<?php
/** Internal campaign state machine. No call to SMS services or queue tables. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Olama_Messages_Internal_Campaign_Service {
    private $jobs;
    public function __construct() { $this->jobs = new Olama_Messages_Job_Service(); }

    public function get( $id, $lock = false, $missing_ok = false ) {
        global $wpdb;
        $c = Olama_Messages_Communications_DB::table( 'campaigns' );
        $m = Olama_Messages_Communications_DB::table( 'internal_campaigns' );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT c.*,m.* FROM {$c} c INNER JOIN {$m} m ON m.campaign_id=c.id WHERE c.id=%d AND c.channel='internal'" . ( $lock ? ' FOR UPDATE' : '' ), $id ), ARRAY_A );
        if ( $wpdb->last_error ) { throw new RuntimeException( 'تعذر قراءة الإعلان من قاعدة البيانات.' ); }
        if ( ! $row && $missing_ok ) { return null; }
        if ( ! $row ) { throw new RuntimeException( 'الإعلان غير موجود.' ); }
        return $row;
    }

    private function update( $name, array $data, array $where ) {
        global $wpdb;
        if ( false === $wpdb->update( Olama_Messages_Communications_DB::table( $name ), $data, $where ) ) { throw new RuntimeException( 'تعذر تحديث الإعلان.' ); }
    }

    public function save( array $data, array $actor, $id = 0 ) {
        Olama_Messages_Communication_Policy::require_manage( $actor );
        $title = trim( (string) ( $data['title'] ?? '' ) );
        $body = trim( (string) ( $data['body'] ?? '' ) );
        $purpose = $data['purpose'] ?? 'information';
        if ( ! in_array( $purpose, array( 'information', 'acknowledgement', 'action_required' ), true ) ) { throw new InvalidArgumentException( 'المراسلات المطلوبة للإجراء غير متاحة في هذا الإصدار.' ); }
        if ( '' === $title || mb_strlen( $title ) > 190 || '' === $body || mb_strlen( $body ) > 5000 ) { throw new InvalidArgumentException( 'أدخل عنواناً حتى 190 حرفاً ونصاً حتى 5000 حرف.' ); }
        // A deliberately small merge contract avoids legacy public payment-link generation.
        preg_match_all( '/\{([^{}]+)\}/u', $body, $matches );
        if ( array_diff( $matches[1], array( 'recipient_name' ) ) ) { throw new InvalidArgumentException( 'الحقل المتاح هو {recipient_name} فقط.' ); }
        if ( 'action_required' === $purpose ) { Olama_Messages_Suite_Policy::feature( $actor, 'actions' ); }
        $workflow = array( 'due_at_utc' => Olama_Messages_Suite_Policy::utc( $data['action_due_at_utc'] ?? null, true ), 'priority' => $data['action_priority'] ?? 'normal' );
        if ( ! in_array( $workflow['priority'], array( 'normal', 'important', 'urgent' ), true ) ) { throw new InvalidArgumentException( 'أولوية غير صالحة.' ); }
        $spec = ( new Olama_Messages_Audience_Resolver() )->validate( (array) ( $data['audience'] ?? array() ) );
        return Olama_Messages_Communications_DB::transaction( function () use ( $id, $title, $body, $purpose, $spec, $actor, $workflow, $data ) {
            if ( $id ) {
                $existing = $this->get( $id, true );
                if ( 'draft' !== $existing['status'] || $existing['published_at_utc'] ) { throw new RuntimeException( 'يمكن تعديل المسودة فقط.' ); }
                $this->update( 'campaigns', array( 'title' => $title, 'message_body_draft' => $body, 'study_year' => $spec['study_year'], 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
                $this->update( 'internal_campaigns', array( 'purpose' => $purpose, 'audience_json' => wp_json_encode( $spec ) ), array( 'campaign_id' => $id ) );
            } else {
                $id = Olama_Messages_Communications_DB::insert( 'campaigns', array( 'title' => $title, 'channel' => 'internal', 'status' => 'draft', 'target_type' => 'internal', 'study_year' => $spec['study_year'], 'message_body_draft' => $body, 'created_by' => get_current_user_id(), 'created_at' => current_time( 'mysql' ) ) );
                Olama_Messages_Communications_DB::insert( 'internal_campaigns', array( 'campaign_id' => $id, 'purpose' => $purpose, 'audience_json' => wp_json_encode( $spec ), 'snapshot_id' => wp_generate_uuid4(), 'created_by_actor_key' => $actor['actor_key'] ) );
            }
            Olama_Messages_Suite_Policy::update( 'internal_campaigns', array( 'workflow_json' => wp_json_encode( $workflow ) ), array( 'campaign_id' => $id ) );
            if ( array_key_exists( 'attachment_ids', $data ) ) { ( new Olama_Messages_Attachment_Service() )->replace( $actor, $data['attachment_ids'], 'campaign', $id ); }
            Olama_Messages_Communications_DB::audit( 'campaign_saved', $id, $actor );
            return $id;
        } );
    }

    public function command( $id, $command, array $actor, array $data = array() ) {
        global $wpdb;
        Olama_Messages_Communication_Policy::require_manage( $actor );
        return Olama_Messages_Communications_DB::transaction( function () use ( $wpdb, $id, $command, $actor, $data ) {
            $row = $this->get( $id, true );
            $now = gmdate( 'Y-m-d H:i:s' );
            if ( 'prepare' === $command ) {
                if ( 'draft' !== $row['status'] ) { throw new RuntimeException( 'التحضير متاح للمسودة فقط.' ); }
                $this->update( 'campaigns', array( 'status' => 'preparing' ), array( 'id' => $id ) );
                $this->update( 'internal_campaigns', array( 'resolution_started_at_utc' => $now ), array( 'campaign_id' => $id ) );
                $this->jobs->enqueue( 'prepare:' . $row['snapshot_id'] . ':0', 'prepare', $id, array( 'snapshot' => $row['snapshot_id'], 'offset' => 0 ) );
            } elseif ( 'publish' === $command ) {
                if ( 'prepared' !== $row['status'] || ! $row['snapshot_complete'] ) { throw new RuntimeException( 'يجب اكتمال لقطة الجمهور قبل النشر.' ); }
                $when = $now;
                if ( ! empty( $data['scheduled_at_utc'] ) ) {
                    $date = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $data['scheduled_at_utc'], new DateTimeZone( 'UTC' ) );
                    if ( ! $date || $date->format( 'Y-m-d\TH:i' ) !== $data['scheduled_at_utc'] || $date->getTimestamp() < time() ) { throw new InvalidArgumentException( 'موعد الجدولة UTC غير صالح أو مضى.' ); }
                    $when = $date->format( 'Y-m-d H:i:s' );
                }
                $this->update( 'campaigns', array( 'status' => 'published', 'template_body_snapshot' => $row['message_body_draft'] ), array( 'id' => $id ) );
                $this->update( 'internal_campaigns', array( 'published_at_utc' => $now, 'scheduled_at_utc' => $when, 'published_by_actor_key' => $actor['actor_key'] ), array( 'campaign_id' => $id ) );
                $this->jobs->enqueue( 'fanout:' . $row['snapshot_id'] . ':0', 'fanout', $id, array( 'snapshot' => $row['snapshot_id'], 'after' => 0, 'run' => $row['snapshot_id'] ), $when );
            } elseif ( in_array( $command, array( 'cancel', 'archive' ), true ) ) {
                if ( 'archive' === $command && ! $row['published_at_utc'] ) { throw new RuntimeException( 'الأرشفة للإعلانات المنشورة فقط.' ); }
                $this->update( 'campaigns', array( 'status' => 'cancel' === $command ? 'cancelled' : 'archived', 'cancelled_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
                // Do not lock job rows here (workers lock job then campaign). Future jobs recheck state.
            } elseif ( 'reset' === $command ) {
                if ( $row['published_at_utc'] || 'preparing' === $row['status'] ) { throw new RuntimeException( 'لا يمكن إعادة إعلان منشور أو قيد التحضير.' ); }
                $this->update( 'campaigns', array( 'status' => 'draft' ), array( 'id' => $id ) );
                $this->update( 'internal_campaigns', array( 'snapshot_id' => wp_generate_uuid4(), 'cursor_offset' => 0, 'snapshot_complete' => 0, 'resolution_started_at_utc' => null, 'resolution_completed_at_utc' => null ), array( 'campaign_id' => $id ) );
            } elseif ( 'retry_delivery' === $command ) {
                if ( 'published' !== $row['status'] ) { throw new RuntimeException( 'إعادة التحقق للإعلانات المنشورة فقط.' ); }
                $run = wp_generate_uuid4();
                $this->jobs->enqueue( 'fanout:' . $run . ':0', 'fanout', $id, array( 'snapshot' => $row['snapshot_id'], 'after' => 0, 'run' => $run ), $row['scheduled_at_utc'] );
            } elseif ( 'delete' === $command ) {
                if ( 'draft' !== $row['status'] || $row['published_at_utc'] ) { throw new RuntimeException( 'الحذف الدائم للمسودة غير المنشورة فقط.' ); }
                foreach ( array( 'campaign_targets', 'internal_campaigns' ) as $table ) {
                    $this->delete_rows( $table, 'campaign_id', $id );
                }
                $this->delete_rows( 'campaigns', 'id', $id );
            } else { throw new InvalidArgumentException( 'عملية غير مدعومة.' ); }
            Olama_Messages_Communications_DB::audit( 'campaign_' . $command, $id, $actor );
            return array( 'ok' => true );
        } );
    }

    private function delete_rows( $name, $column, $id ) {
        global $wpdb;
        $table = Olama_Messages_Communications_DB::table( $name );
        Olama_Messages_Communications_DB::query( $wpdb->prepare( "DELETE FROM {$table} WHERE {$column}=%d", $id ) );
    }

    /** Called only inside the job service's fenced transaction. */
    public function handle_job( array $job ) {
        global $wpdb;
        $id = (int) $job['object_id'];
        $row = $this->get( $id, true, true );
        if ( ! $row ) { return; } // A never-published draft may have been deleted after reset.
        $payload = json_decode( $job['payload_json'], true );
        if ( $row['snapshot_id'] !== ( $payload['snapshot'] ?? '' ) ) { return; }
        $targets = Olama_Messages_Communications_DB::table( 'campaign_targets' );
        $now = gmdate( 'Y-m-d H:i:s' );
        if ( 'prepare' === $job['job_type'] ) {
            if ( ! in_array( $row['status'], array( 'preparing', 'preparation_failed' ), true ) || (int) $row['cursor_offset'] !== (int) $payload['offset'] ) { return; }
            $page = ( new Olama_Messages_Audience_Resolver() )->page( json_decode( $row['audience_json'], true ), (int) $payload['offset'] );
            foreach ( $page['items'] as $item ) {
                Olama_Messages_Communications_DB::query( $wpdb->prepare( "INSERT INTO {$targets} (campaign_id,snapshot_id,actor_key,display_name_snapshot,context_json,reachability,checked_at_utc,created_at_utc) VALUES (%d,%s,%s,%s,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE id=id", $id, $row['snapshot_id'], $item['actor_key'], mb_substr( $item['name'], 0, 190 ), wp_json_encode( $item['context'] ), $item['reachability'], $now, $now ) );
            }
            $this->update( 'internal_campaigns', array( 'cursor_offset' => $page['next'], 'snapshot_complete' => $page['done'] ? 1 : 0, 'resolution_completed_at_utc' => $page['done'] ? $now : null, 'source_versions_json' => wp_json_encode( $page['sources'] ) ), array( 'campaign_id' => $id ) );
            $this->update( 'campaigns', array( 'status' => $page['done'] ? 'prepared' : 'preparing' ), array( 'id' => $id ) );
            if ( ! $page['done'] ) { $this->jobs->enqueue( 'prepare:' . $row['snapshot_id'] . ':' . $page['next'], 'prepare', $id, array( 'snapshot' => $row['snapshot_id'], 'offset' => $page['next'] ) ); }
        } elseif ( 'fanout' === $job['job_type'] ) {
            if ( 'published' !== $row['status'] || ! $row['snapshot_complete'] ) { return; }
            $batch = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$targets} WHERE campaign_id=%d AND snapshot_id=%s AND id>%d ORDER BY id LIMIT 100", $id, $row['snapshot_id'], $payload['after'] ), ARRAY_A );
            $resolver = new Olama_Messages_Actor_Resolver();
            $resolver->prime( array_column( $batch, 'actor_key' ) );
            foreach ( $batch as $target ) {
                $reach = $resolver->reachability( $target['actor_key'] );
                if ( 'eligible_account' === $reach && 'action_required' === $row['purpose'] && ! Olama_Messages_Suite_Policy::user_for_actor( $target['actor_key'], 'actions' ) ) { $reach = 'no_access'; }
                $this->update( 'campaign_targets', array( 'reachability' => $reach, 'checked_at_utc' => $now ), array( 'id' => $target['id'] ) );
                if ( 'eligible_account' !== $reach ) { continue; }
                $deliveries = Olama_Messages_Communications_DB::table( 'internal_deliveries' );
                $notifications = Olama_Messages_Communications_DB::table( 'notifications' );
                if ( 'action_required' === $row['purpose'] && empty( Olama_Messages_Communication_Policy::settings()['actions_enabled'] ) ) { throw new RuntimeException( 'سير الإجراءات غير مفعل.' ); }
                $body = str_replace( '{recipient_name}', $target['display_name_snapshot'], $row['template_body_snapshot'] );
                Olama_Messages_Communications_DB::query( $wpdb->prepare( "INSERT INTO {$deliveries} (campaign_id,campaign_target_id,actor_key,rendered_title,rendered_body,purpose,sent_at_utc) VALUES (%d,%d,%s,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE id=id", $id, $target['id'], $target['actor_key'], $row['title'], $body, $row['purpose'], $now ) );
                $delivery_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$deliveries} WHERE campaign_target_id=%d", $target['id'] ) );
                if ( 'action_required' === $row['purpose'] ) { $workflow = (array) json_decode( $row['workflow_json'], true ); ( new Olama_Messages_Action_Service() )->create_internal( array_merge( $workflow, array( 'source_type' => 'campaign_delivery', 'source_id' => $delivery_id, 'title' => $row['title'], 'owner_key' => $target['actor_key'] ) ), array( 'actor_key' => $row['created_by_actor_key'], 'wp_user_id' => $row['created_by'] ), 'campaign_delivery:' . $delivery_id ); }
                Olama_Messages_Communications_DB::query( $wpdb->prepare( "INSERT INTO {$notifications} (actor_key,delivery_id,created_at_utc) VALUES (%s,%d,%s) ON DUPLICATE KEY UPDATE id=id", $target['actor_key'], $delivery_id, $now ) );
            }
            if ( 100 === count( $batch ) ) {
                $last = end( $batch );
                $this->jobs->enqueue( 'fanout:' . $payload['run'] . ':' . $last['id'], 'fanout', $id, array( 'snapshot' => $row['snapshot_id'], 'after' => (int) $last['id'], 'run' => $payload['run'] ) );
            }
        } else { throw new RuntimeException( 'Unknown internal job type.' ); }
    }

    public function listing( $before = 0 ) {
        global $wpdb;
        $c = Olama_Messages_Communications_DB::table( 'campaigns' );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$c} WHERE channel='internal' AND (%d=0 OR id<%d) ORDER BY id DESC LIMIT 30", $before, $before ), ARRAY_A );
        return array_map( function ( $row ) { return $this->get( $row['id'] ); }, $rows );
    }

    public function statistics( $id ) {
        global $wpdb;
        $row = $this->get( $id );
        $t = Olama_Messages_Communications_DB::table( 'campaign_targets' );
        $d = Olama_Messages_Communications_DB::table( 'internal_deliveries' );
        return array(
            'actions' => $wpdb->get_results( $wpdb->prepare( "SELECT a.status,COUNT(*) AS total FROM " . Olama_Messages_Communications_DB::table( 'action_items' ) . " a INNER JOIN {$d} d ON d.id=a.source_id WHERE a.source_type='campaign_delivery' AND d.campaign_id=%d GROUP BY a.status", $id ), ARRAY_A ),
            'reachability' => $wpdb->get_results( $wpdb->prepare( "SELECT reachability,COUNT(*) AS total,MIN(checked_at_utc) AS checked_at_utc FROM {$t} WHERE campaign_id=%d AND snapshot_id=%s GROUP BY reachability", $id, $row['snapshot_id'] ), ARRAY_A ),
            'receipts' => $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS sent,COUNT(delivered_at_utc) AS delivered,COUNT(read_at_utc) AS `read`,COUNT(acknowledged_at_utc) AS acknowledged FROM {$d} WHERE campaign_id=%d", $id ), ARRAY_A ),
        );
    }
}
