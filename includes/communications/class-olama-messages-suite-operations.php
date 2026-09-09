<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Olama_Messages_Suite_Operations {
    public static function handle_job( array $job ) {
        if ( 0 === strpos( $job['job_type'], 'event_' ) ) { ( new Olama_Messages_Event_Service() )->handle_job( $job ); }
        else { ( new Olama_Messages_Internal_Campaign_Service() )->handle_job( $job ); }
    }
    public static function maintenance() {
        global $wpdb; $settings = Olama_Messages_Communication_Policy::settings();
        if ( empty( $settings['enabled'] ) || Olama_Messages_Communications_DB::health() ) { return; }
        if ( ! empty( $settings['attachments_enabled'] ) ) { try { ( new Olama_Messages_Attachment_Service() )->expire_staged(); delete_option( 'olama_msg_storage_error' ); } catch ( Throwable $e ) { update_option( 'olama_msg_storage_error', $e->getMessage(), false ); } }
        if ( ! empty( $settings['events_enabled'] ) ) {
            $t = Olama_Messages_Communications_DB::table( 'events' );
            $ids = $wpdb->get_col( "SELECT id FROM {$t} WHERE source_type<>'communications' AND status NOT IN ('archived','source_removed') AND (source_checked_at_utc IS NULL OR source_checked_at_utc<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 5 MINUTE)) ORDER BY COALESCE(source_checked_at_utc,'1970-01-01'),id LIMIT 10" );
            foreach ( $ids as $id ) { try { ( new Olama_Messages_Event_Service() )->sync_source( $id ); } catch ( Throwable $e ) { Olama_Messages_Suite_Policy::update( 'events', array( 'source_error' => mb_substr( $e->getMessage(), 0, 190 ), 'source_checked_at_utc' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $id ) ); } }
        }
        if ( ! empty( $settings['actions_enabled'] ) ) {
            $a = Olama_Messages_Communications_DB::table( 'action_items' );
            $ids = $wpdb->get_col( "SELECT id FROM {$a} WHERE status IN ('open','in_progress') AND due_at_utc<UTC_TIMESTAMP() AND overdue_notified_version<version ORDER BY due_at_utc,id LIMIT 100" );
            foreach ( $ids as $id ) { Olama_Messages_Communications_DB::transaction( function () use ( $wpdb, $a, $id ) {
                $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$a} WHERE id=%d FOR UPDATE", $id ), ARRAY_A );
                if ( ! in_array( $row['status'], array( 'open', 'in_progress' ), true ) || ! $row['due_at_utc'] || $row['due_at_utc'] >= gmdate( 'Y-m-d H:i:s' ) || $row['overdue_notified_version'] >= $row['version'] ) { return; }
                foreach ( array_unique( array_filter( array( $row['owner_key'], $row['created_by_key'] ) ) ) as $key ) { Olama_Messages_Activity_Service::emit( $key, 'action', $id, $row['version'], 'action_overdue' ); }
                Olama_Messages_Suite_Policy::update( 'action_items', array( 'overdue_notified_version' => $row['version'] ), array( 'id' => $id ) );
            } ); }
            self::service_sla();
        }
        update_option( 'olama_msg_suite_maintenance_utc', gmdate( 'Y-m-d H:i:s' ), false );
    }
    private static function service_sla() {
        global $wpdb; $t = Olama_Messages_Communications_DB::table( 'threads' ); $i = Olama_Messages_Communications_DB::table( 'service_inboxes' ); $m = Olama_Messages_Communications_DB::table( 'inbox_members' );
        $rows = $wpdb->get_col( "SELECT t.id FROM {$t} t INNER JOIN {$i} i ON i.id=t.inbox_id AND i.active=1 WHERE t.kind='service' AND t.status IN ('open','in_progress') AND ((t.department_responded_at_utc IS NULL AND i.response_sla_minutes>0 AND t.response_escalated_cycle<t.service_cycle AND COALESCE(t.service_cycle_started_at_utc,t.created_at_utc)<DATE_SUB(UTC_TIMESTAMP(),INTERVAL i.response_sla_minutes MINUTE)) OR (i.resolution_sla_minutes>0 AND t.resolution_escalated_cycle<t.service_cycle AND COALESCE(t.service_cycle_started_at_utc,t.created_at_utc)<DATE_SUB(UTC_TIMESTAMP(),INTERVAL i.resolution_sla_minutes MINUTE))) ORDER BY t.id LIMIT 100" );
        foreach ( $rows as $id ) { Olama_Messages_Communications_DB::transaction( function () use ( $wpdb, $t, $i, $m, $id ) {
            $thread = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id=%d FOR UPDATE", $id ), ARRAY_A );
            if ( ! in_array( $thread['status'], array( 'open', 'in_progress' ), true ) ) { return; }
            $inbox = ( new Olama_Messages_Service_Inbox_Service() )->get( $thread['inbox_id'], true ); if ( ! $inbox['active'] ) { return; }
            $keys = $wpdb->get_col( $wpdb->prepare( "SELECT actor_key FROM {$m} WHERE inbox_id=%d AND active=1 AND is_manager=1", $inbox['id'] ) );
            foreach ( array( 'response', 'resolution' ) as $phase ) {
                if ( ! $inbox[$phase . '_sla_minutes'] || $thread[$phase . '_escalated_cycle'] >= $thread['service_cycle'] || ( 'response' === $phase && $thread['department_responded_at_utc'] ) || strtotime( ( $thread['service_cycle_started_at_utc'] ?: $thread['created_at_utc'] ) . ' UTC' ) + (int) $inbox[$phase . '_sla_minutes'] * 60 >= time() ) { continue; }
                foreach ( $keys as $key ) { if ( Olama_Messages_Suite_Policy::user_for_actor( $key, 'manage_inboxes' ) ) { Olama_Messages_Activity_Service::emit( $key, 'thread', $id, $thread['service_cycle'], 'service_' . $phase . '_overdue' ); } }
                Olama_Messages_Suite_Policy::update( 'threads', array( $phase . '_escalated_cycle' => $thread['service_cycle'] ), array( 'id' => $id ) );
                Olama_Messages_Communications_DB::audit( 'service_escalated', $id, array( 'actor_key' => 'system:school' ), array( 'phase' => $phase, 'cycle' => $thread['service_cycle'] ) );
            }
        } ); }
    }
    public function search( array $actor, array $data ) {
        global $wpdb; Olama_Messages_Chat_Policy::require_use( $actor );
        $from = Olama_Messages_Suite_Policy::utc( $data['from'] ?? gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ); $to = Olama_Messages_Suite_Policy::utc( $data['to'] ?? gmdate( 'Y-m-d H:i:s' ) );
        if ( $to <= $from || strtotime( $to ) - strtotime( $from ) > 90 * DAY_IN_SECONDS ) { throw new InvalidArgumentException( 'البحث يتطلب فترة حتى 90 يوماً.' ); }
        $query = trim( (string) ( $data['query'] ?? '' ) ); if ( mb_strlen( $query ) < 2 || mb_strlen( $query ) > 100 ) { throw new InvalidArgumentException( 'عبارة البحث من 2 إلى 100 حرف.' ); }
        $thread = absint( $data['thread_id'] ?? 0 ); $reason = trim( (string) ( $data['audit_reason'] ?? '' ) );
        $access = ( new Olama_Messages_Chat_Service() )->access_sql( $actor );
        if ( $reason ) { Olama_Messages_Suite_Policy::staff( $actor, 'audit' ); if ( ! $thread ) { throw new RuntimeException( 'التدقيق يتطلب محادثة محددة وسبباً.' ); } Olama_Messages_Communications_DB::audit( 'search_privileged', $thread, $actor, array( 'reason' => Olama_Messages_Chat_Policy::plain_text( $reason, 1000 ), 'from' => $from, 'to' => $to ) ); $access = '1=1'; }
        $m = Olama_Messages_Communications_DB::table( 'messages' ); $t = Olama_Messages_Communications_DB::table( 'threads' ); $p = Olama_Messages_Communications_DB::table( 'thread_participants' ); $a = Olama_Messages_Communications_DB::table( 'attachments' );
        $where = ''; if ( $thread ) { $where .= $wpdb->prepare( ' AND t.id=%d', $thread ); }
        if ( ! empty( $data['sender'] ) ) { $where .= $wpdb->prepare( ' AND m.sender_key=%s', $data['sender'] ); }
        if ( ! empty( $data['teacher'] ) ) { $where .= $wpdb->prepare( " AND EXISTS(SELECT 1 FROM {$p} teacher WHERE teacher.thread_id=t.id AND teacher.actor_key=%s)", $data['teacher'] ); }
        if ( ! empty( $data['student_uid'] ) ) { $where .= $wpdb->prepare( " AND JSON_UNQUOTE(JSON_EXTRACT(t.context_json,'$.student_uid'))=%s", $data['student_uid'] ); }
        if ( ! empty( $data['has_attachment'] ) ) { $where .= " AND EXISTS(SELECT 1 FROM {$a} a WHERE a.owner_type='message' AND a.owner_id=m.id AND a.active=1 AND a.status='linked')"; }
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT m.id,m.thread_id,t.subject,m.body,m.sent_at_utc FROM {$m} m INNER JOIN {$t} t ON t.id=m.thread_id LEFT JOIN {$p} p ON p.thread_id=t.id AND p.actor_key=%s WHERE ({$access}) AND m.redacted_at_utc IS NULL AND m.sent_at_utc BETWEEN %s AND %s AND m.body LIKE %s AND (%d=0 OR m.id<%d) {$where} ORDER BY m.id DESC LIMIT 30", $actor['actor_key'], $from, $to, '%' . $wpdb->esc_like( $query ) . '%', absint( $data['before'] ?? 0 ), absint( $data['before'] ?? 0 ) ), ARRAY_A );
        return array( 'items' => $rows, 'next_before' => count( $rows ) === 30 ? (int) end( $rows )['id'] : 0 );
    }
    public function dashboard( array $actor ) {
        global $wpdb; Olama_Messages_Suite_Policy::staff( $actor, 'view_dashboard' );
        $result = array( 'generated_at_utc' => gmdate( 'Y-m-d H:i:s' ) );
        foreach ( array( 'events', 'action_items', 'threads' ) as $name ) { $t = Olama_Messages_Communications_DB::table( $name ); $result[$name] = $wpdb->get_results( "SELECT status,COUNT(*) AS total FROM {$t} GROUP BY status", ARRAY_A ); }
        $t = Olama_Messages_Communications_DB::table( 'threads' ); $result['service'] = $wpdb->get_row( "SELECT COUNT(*) AS requests,SUM(status IN ('open','in_progress')) AS active,SUM(status='resolved') AS resolved,SUM(response_escalated_cycle=service_cycle) AS response_breaches,SUM(resolution_escalated_cycle=service_cycle) AS resolution_breaches,AVG(TIMESTAMPDIFF(MINUTE,COALESCE(service_cycle_started_at_utc,created_at_utc),department_responded_at_utc)) AS average_response_minutes,AVG(TIMESTAMPDIFF(MINUTE,COALESCE(service_cycle_started_at_utc,created_at_utc),resolved_at_utc)) AS average_resolution_minutes FROM {$t} WHERE kind='service'", ARRAY_A );
        $a = Olama_Messages_Communications_DB::table( 'action_items' ); $result['overdue_actions'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$a} WHERE status IN ('open','in_progress') AND due_at_utc<UTC_TIMESTAMP()" );
        $j = Olama_Messages_Communications_DB::table( 'jobs' ); $result['jobs'] = $wpdb->get_results( "SELECT job_type,status,COUNT(*) AS total FROM {$j} GROUP BY job_type,status", ARRAY_A ); return $result;
    }
    public function retention( array $actor, array $data ) {
        global $wpdb; Olama_Messages_Suite_Policy::staff( $actor, 'configure' ); $settings = Olama_Messages_Communication_Policy::settings();
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 30, (int) $settings['retention_archive_days'] ) * DAY_IN_SECONDS );
        $t = Olama_Messages_Communications_DB::table( 'events' ); $ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE status IN ('completed','cancelled','source_removed') AND published_at_utc IS NOT NULL AND updated_at_utc<%s ORDER BY id LIMIT 100", $cutoff ) );
        $execute = ! empty( $data['execute'] ); if ( $execute && empty( $settings['retention_archive_enabled'] ) ) { throw new RuntimeException( 'الأرشفة التلقائية غير مفعلة؛ المعاينة فقط متاحة.' ); }
        if ( $execute ) { Olama_Messages_Communications_DB::transaction( function () use ( $wpdb, $actor, $t, $ids, $cutoff ) {
            foreach ( $ids as $id ) { $changed = Olama_Messages_Communications_DB::query( $wpdb->prepare( "UPDATE {$t} SET status='archived' WHERE id=%d AND status IN ('completed','cancelled','source_removed') AND updated_at_utc<%s", $id, $cutoff ) ); if ( $changed ) { Olama_Messages_Communications_DB::audit( 'retention_event_archived', $id, $actor ); } }
        } ); }
        return array( 'dry_run' => ! $execute, 'candidate_event_ids' => array_map( 'intval', $ids ), 'cutoff_utc' => $cutoff, 'limit' => 100, 'deletion_enabled' => false );
    }
}
