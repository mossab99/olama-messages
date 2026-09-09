<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Olama_Messages_Event_Service {
    public function get( $id, $lock = false ) {
        global $wpdb; $t = Olama_Messages_Communications_DB::table( 'events' );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id=%d" . ( $lock ? ' FOR UPDATE' : '' ), $id ), ARRAY_A );
        if ( ! $row || $wpdb->last_error ) { throw new RuntimeException( 'الفعالية غير متاحة.' ); } return $row;
    }
    public function visible( array $actor, $id ) {
        global $wpdb;
        Olama_Messages_Suite_Policy::feature( $actor, 'events' ); $row = $this->get( $id );
        if ( Olama_Messages_Communication_Policy::staff_actor( $actor ) && Olama_Messages_Communication_Policy::can( 'olama_messages_manage_events' ) ) { return $row; }
        $t = Olama_Messages_Communications_DB::table( 'event_targets' );
        if ( ! $row['published_at_utc'] || ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE event_id=%d AND actor_key=%s AND released_version>0 LIMIT 1", $id, $actor['actor_key'] ) ) ) { throw new RuntimeException( 'الفعالية غير متاحة لهذا الحساب.' ); }
        return $row;
    }
    public function target( array $actor, $id ) {
        global $wpdb; Olama_Messages_Suite_Policy::feature( $actor, 'events' );
        $t = Olama_Messages_Communications_DB::table( 'event_targets' );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id=%d AND actor_key=%s AND released_version>0", $id, $actor['actor_key'] ), ARRAY_A );
        if ( ! $row ) { throw new RuntimeException( 'استجابة الجمهور غير مخولة.' ); } return $row;
    }
    private function revision( array $row, array $actor, array $policy ) {
        Olama_Messages_Communications_DB::insert( 'event_revisions', array( 'event_id' => $row['id'], 'content_version' => $row['content_version'], 'ack_required_version' => $row['ack_required_version'], 'rsvp_required_version' => $row['rsvp_required_version'],
            'snapshot_json' => wp_json_encode( $row ), 'change_policy_json' => wp_json_encode( $policy ), 'actor_key' => $actor['actor_key'], 'authenticated_wp_user_id' => get_current_user_id(), 'created_at_utc' => gmdate( 'Y-m-d H:i:s' ) ) );
    }
    private function audience( $id, array $spec, array $actor, $start = false ) {
        $uuid = wp_generate_uuid4();
        Olama_Messages_Communications_DB::insert( 'event_audiences', array( 'event_id' => $id, 'snapshot_id' => $uuid, 'audience_json' => wp_json_encode( $spec ), 'status' => $start ? 'preparing' : 'draft', 'created_by_key' => $actor['actor_key'], 'started_at_utc' => $start ? gmdate( 'Y-m-d H:i:s' ) : null ) );
        if ( $start ) { $this->enqueue( 'event_prepare', $id, array( 'snapshot' => $uuid, 'offset' => 0 ) ); } return $uuid;
    }
    private function enqueue( $type, $id, array $payload, $when = null ) {
        ( new Olama_Messages_Job_Service() )->enqueue( $type . ':' . $id . ':' . hash( 'sha256', wp_json_encode( $payload ) ), $type, $id, $payload, $when );
    }
    private function schedule( array $row ) {
        if ( 'published' !== $row['status'] ) { return; }
        foreach ( (array) json_decode( $row['reminder_policy_json'], true ) as $offset ) {
            $when = gmdate( 'Y-m-d H:i:s', strtotime( $row['starts_at_utc'] . ' UTC' ) - (int) $offset * 60 );
            if ( $when <= gmdate( 'Y-m-d H:i:s' ) ) { continue; }
            global $wpdb; $t = Olama_Messages_Communications_DB::table( 'event_reminders' );
            Olama_Messages_Communications_DB::query( $wpdb->prepare( "INSERT INTO {$t} (event_id,content_version,offset_minutes,run_at_utc,status) VALUES (%d,%d,%d,%s,'pending') ON DUPLICATE KEY UPDATE id=id", $row['id'], $row['content_version'], $offset, $when ) );
            $this->enqueue( 'event_remind', $row['id'], array( 'version' => (int) $row['content_version'], 'offset' => (int) $offset, 'after' => 0 ), $when );
        }
    }
    public function save( array $actor, array $data, $id = 0 ) {
        Olama_Messages_Suite_Policy::staff( $actor, 'manage_events', 'events' );
        return Olama_Messages_Communications_DB::transaction( function () use ( $actor, $data, $id ) {
            $old = $id ? $this->get( $id, true ) : null;
            if ( $old && ! in_array( $old['status'], array( 'draft', 'published' ), true ) ) { throw new RuntimeException( 'حالة الفعالية لا تسمح بالتعديل.' ); }
            if ( $old && (int) ( $data['content_version'] ?? 0 ) !== (int) $old['content_version'] ) { throw new RuntimeException( 'نسخة الفعالية تغيرت؛ حدّث الصفحة.' ); }
            $source = $old ? $old['source_type'] : sanitize_key( $data['source_type'] ?? 'communications' );
            $source_id = $old ? $old['source_id'] : ( 'communications' === $source ? wp_generate_uuid4() : (string) ( $data['source_id'] ?? '' ) );
            if ( strlen( $source ) > 40 || ! $source_id || strlen( $source_id ) > 100 ) { throw new InvalidArgumentException( 'معرف المصدر غير صالح.' ); }
            $base = $old ?: array( 'description' => '', 'category' => 'general', 'priority' => 'normal', 'timezone' => wp_timezone_string(), 'all_day' => 0, 'location' => '', 'deep_link' => '', 'response_scope' => 'actor', 'requires_ack' => 0, 'rsvp_enabled' => 0, 'action_required' => 0, 'action_due_at_utc' => null, 'completion_provider' => '', 'reminder_policy_json' => '[]', 'source_version' => '' );
            $row = array();
            foreach ( array( 'title', 'description', 'category', 'priority', 'timezone', 'all_day', 'location', 'deep_link', 'response_scope', 'requires_ack', 'rsvp_enabled', 'action_required', 'action_due_at_utc', 'completion_provider', 'starts_at_utc', 'ends_at_utc' ) as $key ) { $row[$key] = $data[$key] ?? $base[$key] ?? ''; }
            if ( 'communications' !== $source ) {
                $projection = Olama_Messages_Event_Sources::read( $source, $source_id );
                if ( ! $projection || ( $projection['source_status'] ?? 'active' ) !== 'active' ) { throw new RuntimeException( 'المصدر ألغى الفعالية أو أزالها.' ); }
                foreach ( array( 'title', 'starts_at_utc', 'ends_at_utc', 'all_day', 'timezone' ) as $key ) { if ( isset( $projection[$key] ) ) { $row[$key] = $projection[$key]; } }
                $row['source_version'] = $projection['source_version']; $row['source_checked_at_utc'] = gmdate( 'Y-m-d H:i:s' ); $row['source_error'] = null;
            }
            $row['title'] = Olama_Messages_Chat_Policy::plain_text( $row['title'], 190 );
            if ( mb_strlen( $row['description'] ) > 10000 || mb_strlen( $row['location'] ) > 190 ) { throw new InvalidArgumentException( 'نص الفعالية أطول من الحد المسموح.' ); }
            if ( ! in_array( $row['priority'], array( 'normal', 'important', 'urgent', 'critical' ), true ) || ! in_array( $row['response_scope'], array( 'actor', 'student' ), true ) ) { throw new InvalidArgumentException( 'أولوية أو سياق غير صالح.' ); }
            if ( ! preg_match( '/^[a-z0-9_-]{1,40}$/', $row['category'] ) || ! preg_match( '/^[a-z0-9_-]{0,80}$/', $row['completion_provider'] ) ) { throw new InvalidArgumentException( 'فئة أو مزود إتمام غير صالح.' ); }
            try { new DateTimeZone( $row['timezone'] ); } catch ( Exception $e ) { throw new InvalidArgumentException( 'منطقة زمنية غير صالحة.' ); }
            foreach ( array( 'requires_ack', 'rsvp_enabled', 'all_day', 'action_required' ) as $key ) { $row[$key] = empty( $row[$key] ) ? 0 : 1; }
            if ( 'critical' === $row['priority'] ) { $row['requires_ack'] = 1; }
            if ( $row['action_required'] && empty( Olama_Messages_Communication_Policy::settings()['actions_enabled'] ) ) { throw new RuntimeException( 'فعّل سير الإجراءات أولاً.' ); }
            if ( 'communications' === $source ) { foreach ( array( 'starts_at_local' => 'starts_at_utc', 'ends_at_local' => 'ends_at_utc' ) as $local => $utc ) { if ( isset( $data[$local] ) ) {
                $date = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', (string) $data[$local], new DateTimeZone( $row['timezone'] ) );
                if ( ! $date || $date->format( 'Y-m-d\TH:i' ) !== $data[$local] ) { throw new InvalidArgumentException( 'موعد محلي غير صالح في المنطقة الزمنية المحددة.' ); }
                $row[$utc] = $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
            } } }
            foreach ( array( 'starts_at_utc', 'ends_at_utc' ) as $key ) { $row[$key] = Olama_Messages_Suite_Policy::utc( $row[$key] ); }
            if ( $row['all_day'] ) { foreach ( array( 'starts_at_utc', 'ends_at_utc' ) as $key ) {
                if ( '00:00:00' !== ( new DateTimeImmutable( $row[$key], new DateTimeZone( 'UTC' ) ) )->setTimezone( new DateTimeZone( $row['timezone'] ) )->format( 'H:i:s' ) ) { throw new InvalidArgumentException( 'اليوم الكامل يبدأ وينتهي عند منتصف الليل المحلي.' ); }
            } }
            if ( $row['ends_at_utc'] <= $row['starts_at_utc'] ) { throw new InvalidArgumentException( 'نهاية الفعالية يجب أن تلي بدايتها؛ اليوم الكامل نهايته حصرية.' ); }
            $row['action_due_at_utc'] = Olama_Messages_Suite_Policy::utc( $row['action_due_at_utc'], true );
            $row['deep_link'] = esc_url_raw( $row['deep_link'], array( 'https' ) );
            if ( $row['deep_link'] && wp_parse_url( $row['deep_link'], PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) { throw new InvalidArgumentException( 'رابط الفعالية يجب أن يكون داخل بوابة المدرسة.' ); }
            $offsets = $data['reminders'] ?? json_decode( $base['reminder_policy_json'], true );
            if ( ! is_array( $offsets ) || count( $offsets ) > 5 ) { throw new InvalidArgumentException( 'الحد الأقصى خمسة تذكيرات.' ); }
            foreach ( $offsets as $offset ) { if ( ! is_numeric( $offset ) || (int) $offset != $offset || $offset < 0 || $offset > 43200 ) { throw new InvalidArgumentException( 'التذكير بالدقائق خلال 30 يوماً.' ); } }
            $row['reminder_policy_json'] = wp_json_encode( array_values( array_unique( array_map( 'intval', $offsets ) ) ) );
            $major = ! $old || ! empty( $data['critical_change'] );
            if ( $old && array_key_exists( 'attachment_ids', $data ) ) {
                global $wpdb; $attachments = Olama_Messages_Communications_DB::table( 'attachments' );
                $before_files = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$attachments} WHERE owner_type='event' AND owner_id=%d AND active=1 ORDER BY id", $id ) ) );
                if ( $before_files !== Olama_Messages_Suite_Policy::bounded_ids( $data['attachment_ids'] ) ) { $major = true; }
            }
            if ( $old ) { foreach ( array( 'starts_at_utc', 'ends_at_utc', 'location', 'description', 'all_day', 'timezone', 'requires_ack', 'rsvp_enabled', 'action_required', 'action_due_at_utc' ) as $key ) { if ( (string) $row[$key] !== (string) $old[$key] ) { $major = true; } }
                if ( $row['response_scope'] !== $old['response_scope'] && 'draft' !== $old['status'] ) { throw new RuntimeException( 'سياق الجمهور ثابت بعد التحضير.' ); }
            }
            $row['content_version'] = $old ? (int) $old['content_version'] + 1 : 1;
            $row['ack_required_version'] = $row['requires_ack'] ? ( $major ? $row['content_version'] : (int) $old['ack_required_version'] ) : 0;
            $row['rsvp_required_version'] = $row['rsvp_enabled'] ? ( $major ? $row['content_version'] : (int) $old['rsvp_required_version'] ) : 0;
            $row['updated_at_utc'] = gmdate( 'Y-m-d H:i:s' );
            if ( $old ) { Olama_Messages_Suite_Policy::update( 'events', $row, array( 'id' => $id ) ); }
            else {
                $row += array( 'source_type' => $source, 'source_id' => $source_id, 'source_version' => '', 'snapshot_id' => '', 'created_by_key' => $actor['actor_key'], 'created_by_wp_user_id' => get_current_user_id(), 'created_at_utc' => gmdate( 'Y-m-d H:i:s' ) );
                $id = Olama_Messages_Communications_DB::insert( 'events', $row );
            }
            if ( ! $old || 'draft' === $old['status'] ) {
                $spec = ( new Olama_Messages_Audience_Resolver() )->validate( (array) ( $data['audience'] ?? array() ) );
                $snapshot = $this->audience( $id, $spec, $actor ); Olama_Messages_Suite_Policy::update( 'events', array( 'snapshot_id' => $snapshot ), array( 'id' => $id ) );
            }
            if ( array_key_exists( 'attachment_ids', $data ) ) { ( new Olama_Messages_Attachment_Service() )->replace( $actor, $data['attachment_ids'], 'event', $id ); }
            $saved = $this->get( $id ); $this->revision( $saved, $actor, array( 'major' => $major ) );
            if ( 'published' === $saved['status'] ) { $this->enqueue( 'event_release', $id, array( 'version' => (int) $saved['content_version'], 'after' => 0 ) ); $this->schedule( $saved ); }
            Olama_Messages_Communications_DB::audit( 'event_saved', $id, $actor, array( 'major' => $major, 'version' => $saved['content_version'] ) );
            return array( 'id' => $id, 'content_version' => (int) $saved['content_version'] );
        } );
    }
    public function command( array $actor, $id, $command, array $data = array() ) {
        global $wpdb; Olama_Messages_Suite_Policy::staff( $actor, 'manage_events', 'events' );
        return Olama_Messages_Communications_DB::transaction( function () use ( $wpdb, $actor, $id, $command, $data ) {
            $row = $this->get( $id, true ); $a = Olama_Messages_Communications_DB::table( 'event_audiences' );
            if ( 'prepare' === $command ) {
                if ( 'draft' !== $row['status'] ) { throw new RuntimeException( 'يمكن تحضير المسودة فقط.' ); }
                Olama_Messages_Suite_Policy::update( 'events', array( 'status' => 'preparing' ), array( 'id' => $id ) );
                Olama_Messages_Suite_Policy::update( 'event_audiences', array( 'status' => 'preparing', 'started_at_utc' => gmdate( 'Y-m-d H:i:s' ) ), array( 'snapshot_id' => $row['snapshot_id'] ) );
                $this->enqueue( 'event_prepare', $id, array( 'snapshot' => $row['snapshot_id'], 'offset' => 0 ) );
            } elseif ( 'publish' === $command ) {
                $ready = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$a} WHERE snapshot_id=%s", $row['snapshot_id'] ) );
                if ( 'prepared' !== $row['status'] || 'complete' !== $ready ) { throw new RuntimeException( 'يجب اكتمال لقطة الجمهور قبل النشر.' ); }
                Olama_Messages_Suite_Policy::update( 'events', array( 'status' => 'published', 'published_at_utc' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $id ) );
                $row = $this->get( $id ); $this->enqueue( 'event_release', $id, array( 'version' => (int) $row['content_version'], 'after' => 0 ) ); $this->schedule( $row );
            } elseif ( 'add_audience' === $command ) {
                if ( 'published' !== $row['status'] ) { throw new RuntimeException( 'الإضافة متاحة للفعالية المنشورة.' ); }
                $this->audience( $id, ( new Olama_Messages_Audience_Resolver() )->validate( (array) ( $data['audience'] ?? array() ) ), $actor, true );
            } elseif ( in_array( $command, array( 'cancel', 'complete', 'archive' ), true ) ) {
                if ( ! $row['published_at_utc'] || 'archived' === $row['status'] ) { throw new RuntimeException( 'انتقال حالة غير متاح.' ); }
                $status = array( 'cancel' => 'cancelled', 'complete' => 'completed', 'archive' => 'archived' )[$command];
                Olama_Messages_Suite_Policy::update( 'events', array( 'status' => $status, 'content_version' => (int) $row['content_version'] + 1, 'updated_at_utc' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $id ) );
                $row = $this->get( $id ); $this->revision( $row, $actor, array( 'lifecycle' => $status ) ); $this->enqueue( 'event_release', $id, array( 'version' => (int) $row['content_version'], 'after' => 0 ) );
            } elseif ( 'reset' === $command ) {
                if ( $row['published_at_utc'] ) { throw new RuntimeException( 'لا يمكن إعادة فعالية منشورة إلى مسودة.' ); }
                $spec = json_decode( $wpdb->get_var( $wpdb->prepare( "SELECT audience_json FROM {$a} WHERE snapshot_id=%s", $row['snapshot_id'] ) ), true );
                Olama_Messages_Communications_DB::query( $wpdb->prepare( "UPDATE {$a} SET status='cancelled' WHERE event_id=%d", $id ) );
                Olama_Messages_Communications_DB::query( $wpdb->prepare( 'DELETE FROM ' . Olama_Messages_Communications_DB::table( 'event_targets' ) . ' WHERE event_id=%d', $id ) );
                $snapshot = $this->audience( $id, (array) $spec, $actor );
                Olama_Messages_Suite_Policy::update( 'events', array( 'status' => 'draft', 'snapshot_id' => $snapshot ), array( 'id' => $id ) );
            } elseif ( 'delete' === $command ) {
                if ( 'draft' !== $row['status'] || $row['published_at_utc'] ) { throw new RuntimeException( 'الحذف للمسودة غير المنشورة فقط.' ); }
                foreach ( array( 'event_revisions', 'event_audiences' ) as $table ) { Olama_Messages_Communications_DB::query( $wpdb->prepare( 'DELETE FROM ' . Olama_Messages_Communications_DB::table( $table ) . ' WHERE event_id=%d', $id ) ); }
                Olama_Messages_Communications_DB::query( $wpdb->prepare( 'DELETE FROM ' . Olama_Messages_Communications_DB::table( 'events' ) . ' WHERE id=%d', $id ) );
            } else { throw new InvalidArgumentException( 'عملية فعالية غير مدعومة.' ); }
            Olama_Messages_Communications_DB::audit( 'event_' . $command, $id, $actor ); return array( 'ok' => true );
        } );
    }
    public function respond( array $actor, $target_id, array $data ) {
        global $wpdb; $target = $this->target( $actor, $target_id );
        return Olama_Messages_Communications_DB::transaction( function () use ( $wpdb, $actor, $target_id, $target, $data ) {
            $event = $this->get( $target['event_id'], true ); $t = Olama_Messages_Communications_DB::table( 'event_targets' );
            $target = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id=%d AND actor_key=%s FOR UPDATE", $target_id, $actor['actor_key'] ), ARRAY_A );
            $v = absint( $data['content_version'] ?? 0 ); $r = Olama_Messages_Communications_DB::table( 'event_revisions' );
            $revision = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$r} WHERE event_id=%d AND content_version=%d", $event['id'], $v ), ARRAY_A );
            if ( ! $revision || $v > (int) $event['content_version'] || ! $target['released_version'] ) { throw new RuntimeException( 'نسخة العرض غير معروفة.' ); }
            $kind = $data['kind'] ?? ''; $now = gmdate( 'Y-m-d H:i:s' ); $changes = array();
            if ( in_array( $kind, array( 'ack', 'rsvp' ), true ) ) {
                if ( 'published' !== $event['status'] || ! $this->eligible_target( $event, $target ) ) { throw new RuntimeException( 'الاستجابة لم تعد متاحة.' ); }
                $field = 'ack' === $kind ? 'ack_required_version' : 'rsvp_required_version'; $required = absint( $data['required_version'] ?? 0 );
                if ( ! $required || $required !== (int) $revision[$field] || $required !== (int) $event[$field] ) { throw new RuntimeException( 'تغيرت تعليمات الفعالية؛ اقرأ النسخة الحالية قبل الاستجابة.' ); }
                if ( 'ack' === $kind ) { $changes = array( 'acknowledged_ack_version' => $required, 'acknowledged_content_version' => $v, 'acknowledged_at_utc' => $now ); }
                else {
                    $response = $data['response'] ?? ''; if ( ! in_array( $response, array( 'yes', 'no', 'maybe' ), true ) ) { throw new InvalidArgumentException( 'استجابة حضور غير صالحة.' ); }
                    $changes = array( 'rsvp_required_version' => $required, 'rsvp_content_version' => $v, 'rsvp_status' => $response, 'rsvp_at_utc' => $now );
                }
            } elseif ( in_array( $kind, array( 'delivered', 'read' ), true ) ) {
                $changes['delivered_content_version'] = max( $v, (int) $target['delivered_content_version'] ); $changes['delivered_at_utc'] = $target['delivered_at_utc'] ?: $now;
                if ( 'read' === $kind ) { $changes['read_content_version'] = max( $v, (int) $target['read_content_version'] ); $changes['read_at_utc'] = $now; }
            } else { throw new InvalidArgumentException( 'نوع الاستجابة غير صالح.' ); }
            Olama_Messages_Suite_Policy::update( 'event_targets', $changes, array( 'id' => $target_id ) );
            Olama_Messages_Communications_DB::audit( 'event_' . $kind, $event['id'], $actor, array( 'target_id' => $target_id, 'displayed_version' => $v, 'changes' => $changes ) ); return array( 'ok' => true );
        } );
    }
    public function eligible_target( array $event, array $target ) {
        if ( ! Olama_Messages_Suite_Policy::user_for_actor( $target['actor_key'], 'events' ) ) { return false; }
        if ( $event['action_required'] && ! Olama_Messages_Suite_Policy::user_for_actor( $target['actor_key'], 'actions' ) ) { return false; }
        if ( ! $target['student_uid'] ) { return true; }
        if ( 0 !== strpos( $target['actor_key'], 'family:' ) ) { return false; }
        $student = olama_core()->students()->get_by_uid( $target['student_uid'] );
        if ( ! $student || (string) $student['family_uid'] !== substr( $target['actor_key'], 7 ) ) { return false; }
        $year = olama_core()->academic_context()->current()->study_year;
        foreach ( olama_core()->student_years()->get_by_family( substr( $target['actor_key'], 7 ), $year ) as $child ) { if ( (string) ( $child['student_uid'] ?? '' ) === $target['student_uid'] && 'active' === ( $child['student_status'] ?? '' ) ) { return true; } } return false;
    }
    public function detail( array $actor, $id, $target_after = 0 ) {
        global $wpdb; $row = $this->visible( $actor, $id ); $t = Olama_Messages_Communications_DB::table( 'event_targets' );
        $row['targets'] = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE event_id=%d AND actor_key=%s AND released_version>0 AND id>%d ORDER BY id LIMIT 100", $id, $actor['actor_key'], absint( $target_after ) ), ARRAY_A );
        $row['targets_next_after'] = count( $row['targets'] ) === 100 ? (int) end( $row['targets'] )['id'] : 0;
        $row['starts_at_local'] = ( new DateTimeImmutable( $row['starts_at_utc'], new DateTimeZone( 'UTC' ) ) )->setTimezone( new DateTimeZone( $row['timezone'] ) )->format( 'Y-m-d\TH:i' );
        $row['ends_at_local'] = ( new DateTimeImmutable( $row['ends_at_utc'], new DateTimeZone( 'UTC' ) ) )->setTimezone( new DateTimeZone( $row['timezone'] ) )->format( 'Y-m-d\TH:i' );
        $row['attachments'] = ( new Olama_Messages_Attachment_Service() )->listing( $actor, 'event', $id );
        if ( Olama_Messages_Communication_Policy::staff_actor( $actor ) && Olama_Messages_Communication_Policy::can( 'olama_messages_manage_events' ) ) {
            $row['statistics'] = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS targets,SUM(reachability='eligible_account') AS reachable,SUM(sent_at_utc IS NOT NULL) AS sent,SUM(delivered_content_version>0) AS delivered,SUM(read_content_version>0) AS read_count,SUM(acknowledged_ack_version=%d AND %d>0) AS acknowledged,SUM(rsvp_required_version=%d AND rsvp_status='yes' AND %d>0) AS rsvp_yes FROM {$t} WHERE event_id=%d", $row['ack_required_version'], $row['ack_required_version'], $row['rsvp_required_version'], $row['rsvp_required_version'], $id ), ARRAY_A );
            $row['reachability_statistics'] = $wpdb->get_results( $wpdb->prepare( "SELECT reachability,COUNT(*) AS total FROM {$t} WHERE event_id=%d GROUP BY reachability", $id ), ARRAY_A );
            $action_table = Olama_Messages_Communications_DB::table( 'action_items' );
            $row['action_statistics'] = $wpdb->get_results( $wpdb->prepare( "SELECT a.status,COUNT(*) AS total FROM {$action_table} a INNER JOIN {$t} t ON t.id=a.source_id WHERE a.source_type='event_target' AND t.event_id=%d GROUP BY a.status", $id ), ARRAY_A );
            $row['rsvp_statistics'] = $wpdb->get_results( $wpdb->prepare( "SELECT IF(rsvp_required_version=%d AND %d>0,rsvp_status,'pending') AS response,COUNT(*) AS total FROM {$t} WHERE event_id=%d GROUP BY response", $row['rsvp_required_version'], $row['rsvp_required_version'], $id ), ARRAY_A );
            $a = Olama_Messages_Communications_DB::table( 'event_audiences' ); $row['audiences'] = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$a} WHERE event_id=%d ORDER BY id DESC LIMIT 20", $id ), ARRAY_A );
        } else { unset( $row['source_error'], $row['created_by_wp_user_id'], $row['created_by_key'] ); }
        return $row;
    }
    public function listing( array $actor, array $data ) {
        global $wpdb; Olama_Messages_Suite_Policy::feature( $actor, 'events' );
        $e = Olama_Messages_Communications_DB::table( 'events' ); $t = Olama_Messages_Communications_DB::table( 'event_targets' );
        $start = Olama_Messages_Suite_Policy::utc( $data['from'] ?? gmdate( 'Y-m-d 00:00:00', time() - 30 * DAY_IN_SECONDS ) );
        $end = Olama_Messages_Suite_Policy::utc( $data['to'] ?? gmdate( 'Y-m-d 23:59:59', time() + 60 * DAY_IN_SECONDS ) );
        if ( $end <= $start || strtotime( $end ) - strtotime( $start ) > 366 * DAY_IN_SECONDS ) { throw new InvalidArgumentException( 'اختر فترة تقويم حتى سنة.' ); }
        $admin = ! empty( $data['admin'] ); if ( $admin ) { Olama_Messages_Suite_Policy::staff( $actor, 'manage_events' ); }
        $where = $admin ? '1=1' : $wpdb->prepare( "e.published_at_utc IS NOT NULL AND EXISTS(SELECT 1 FROM {$t} t WHERE t.event_id=e.id AND t.actor_key=%s AND t.released_version>0)", $actor['actor_key'] );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT e.id,e.title,e.category,e.priority,e.status,e.starts_at_utc,e.ends_at_utc,e.all_day,e.timezone,e.content_version,e.requires_ack,e.rsvp_enabled,e.source_type FROM {$e} e WHERE {$where} AND e.starts_at_utc<=%s AND e.ends_at_utc>=%s AND e.id>%d ORDER BY e.id LIMIT 100", $end, $start, absint( $data['after'] ?? 0 ) ), ARRAY_A );
        return array( 'items' => $rows, 'next_after' => count( $rows ) === 100 ? (int) end( $rows )['id'] : 0 );
    }
    public function handle_job( array $job ) {
        global $wpdb;
        if ( empty( Olama_Messages_Communication_Policy::settings()['events_enabled'] ) ) { throw new RuntimeException( 'الفعاليات غير مفعلة.' ); }
        $event = $this->get( $job['object_id'], true ); $p = json_decode( $job['payload_json'], true );
        $a = Olama_Messages_Communications_DB::table( 'event_audiences' ); $t = Olama_Messages_Communications_DB::table( 'event_targets' ); $now = gmdate( 'Y-m-d H:i:s' );
        if ( 'event_prepare' === $job['job_type'] ) {
            $snapshot = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$a} WHERE event_id=%d AND snapshot_id=%s FOR UPDATE", $event['id'], $p['snapshot'] ), ARRAY_A );
            if ( ! $snapshot || 'preparing' !== $snapshot['status'] || (int) $snapshot['cursor_offset'] !== (int) $p['offset'] || ! in_array( $event['status'], array( 'preparing', 'published' ), true ) ) { return; }
            $spec = json_decode( $snapshot['audience_json'], true ); $page = ( new Olama_Messages_Audience_Resolver() )->page( $spec, $p['offset'] );
            foreach ( $page['items'] as $item ) {
                $contexts = array( array( 'student_uid' => '', 'context' => $item['context'] ) );
                if ( 'student' === $event['response_scope'] ) {
                    $contexts = array();
                    if ( 0 === strpos( $item['actor_key'], 'family:' ) ) {
                        foreach ( olama_core()->student_years()->get_by_family( substr( $item['actor_key'], 7 ), $spec['study_year'] ) as $child ) {
                            if ( 'active' !== ( $child['student_status'] ?? '' ) || empty( $child['student_uid'] ) ) { continue; }
                            if ( ! empty( $spec['class_id'] ) && (string) $child['class_id'] !== (string) $spec['class_id'] ) { continue; }
                            if ( ! empty( $spec['section_id'] ) && (string) $child['section_id'] !== (string) $spec['section_id'] ) { continue; }
                            $contexts[] = array( 'student_uid' => (string) $child['student_uid'], 'context' => array( 'study_year' => $spec['study_year'], 'student_uid' => (string) $child['student_uid'], 'student_name' => $child['student_name'] ?? '', 'class_id' => $child['class_id'] ?? '', 'section_id' => $child['section_id'] ?? '' ) );
                        }
                    }
                }
                foreach ( $contexts as $context ) {
                    Olama_Messages_Communications_DB::query( $wpdb->prepare( "INSERT INTO {$t} (event_id,snapshot_id,actor_key,context_key,student_uid,display_name_snapshot,context_json,reachability,checked_at_utc) VALUES (%d,%s,%s,%s,%s,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE id=id", $event['id'], $p['snapshot'], $item['actor_key'], hash( 'sha256', $context['student_uid'] ), $context['student_uid'], mb_substr( $item['name'], 0, 190 ), wp_json_encode( $context['context'] ), $item['reachability'], $now ) );
                }
            }
            Olama_Messages_Suite_Policy::update( 'event_audiences', array( 'cursor_offset' => $page['next'], 'status' => $page['done'] ? 'complete' : 'preparing', 'completed_at_utc' => $page['done'] ? $now : null, 'source_versions_json' => wp_json_encode( $page['sources'] ) ), array( 'id' => $snapshot['id'] ) );
            if ( ! $page['done'] ) { $this->enqueue( 'event_prepare', $event['id'], array( 'snapshot' => $p['snapshot'], 'offset' => $page['next'] ) ); }
            elseif ( 'preparing' === $event['status'] && $event['snapshot_id'] === $p['snapshot'] ) { Olama_Messages_Suite_Policy::update( 'events', array( 'status' => 'prepared' ), array( 'id' => $event['id'] ) ); }
            elseif ( 'published' === $event['status'] ) { $this->enqueue( 'event_release', $event['id'], array( 'version' => (int) $event['content_version'], 'snapshot' => $p['snapshot'], 'after' => 0 ) ); }
            return;
        }
        if ( (int) $event['content_version'] !== (int) $p['version'] || ! $event['published_at_utc'] ) { return; }
        $remind = 'event_remind' === $job['job_type'];
        if ( $remind && ( 'published' !== $event['status'] || $event['starts_at_utc'] <= $now ) ) { return; }
        $where = ! empty( $p['snapshot'] ) ? $wpdb->prepare( ' AND t.snapshot_id=%s', $p['snapshot'] ) : '';
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT t.* FROM {$t} t INNER JOIN {$a} a ON a.snapshot_id=t.snapshot_id AND a.status='complete' WHERE t.event_id=%d AND t.id>%d {$where} ORDER BY t.id LIMIT 100", $event['id'], $p['after'] ), ARRAY_A );
        foreach ( $rows as $target ) {
            if ( ! $remind && in_array( $event['status'], array( 'cancelled', 'source_removed', 'archived' ), true ) ) { ( new Olama_Messages_Action_Service() )->cancel_event_target( $target['id'] ); }
            $eligible = $this->eligible_target( $event, $target );
            Olama_Messages_Suite_Policy::update( 'event_targets', array( 'reachability' => $eligible ? 'eligible_account' : ( 'eligible_account' === ( new Olama_Messages_Actor_Resolver() )->reachability( $target['actor_key'] ) ? 'ineligible_context' : ( new Olama_Messages_Actor_Resolver() )->reachability( $target['actor_key'] ) ), 'checked_at_utc' => $now ), array( 'id' => $target['id'] ) );
            if ( ! $eligible ) { continue; }
            if ( $remind ) {
                if ( ! $target['released_version'] ) { continue; }
                $completion = Olama_Messages_Event_Sources::completion( $event, $target );
                if ( 'unknown' === $completion ) { throw new RuntimeException( 'تعذر التحقق من مزود الإتمام؛ لم يرسل التذكير.' ); }
                if ( 'complete' === $completion || ( ! $event['completion_provider'] && $event['requires_ack'] && (int) $target['acknowledged_ack_version'] === (int) $event['ack_required_version'] && ( ! $event['rsvp_enabled'] || (int) $target['rsvp_required_version'] === (int) $event['rsvp_required_version'] ) ) ) { continue; }
            } else {
                if ( 'published' !== $event['status'] && ! $target['released_version'] ) { continue; }
                Olama_Messages_Suite_Policy::update( 'event_targets', array( 'released_version' => $event['content_version'], 'sent_at_utc' => $target['sent_at_utc'] ?: $now ), array( 'id' => $target['id'] ) );
                if ( $event['action_required'] && 'published' === $event['status'] ) {
                    ( new Olama_Messages_Action_Service() )->create_internal( array( 'source_type' => 'event_target', 'source_id' => $target['id'], 'owner_key' => $target['actor_key'], 'title' => $event['title'], 'priority' => 'critical' === $event['priority'] ? 'urgent' : $event['priority'], 'due_at_utc' => $event['action_due_at_utc'] ), array( 'actor_key' => $event['created_by_key'], 'wp_user_id' => $event['created_by_wp_user_id'] ), 'event_target:' . $target['id'] );
                }
            }
            Olama_Messages_Activity_Service::emit( $target['actor_key'], 'event_target', $target['id'], $event['content_version'], $remind ? 'event_reminder_' . $p['offset'] : 'event_' . $event['status'], 'critical' === $event['priority'] );
        }
        if ( count( $rows ) === 100 ) { $p['after'] = (int) end( $rows )['id']; $this->enqueue( $job['job_type'], $event['id'], $p ); }
        elseif ( $remind ) { Olama_Messages_Suite_Policy::update( 'event_reminders', array( 'status' => 'completed' ), array( 'event_id' => $event['id'], 'content_version' => $event['content_version'], 'offset_minutes' => $p['offset'] ) ); }
    }
    public function sync_source( $id ) {
        return Olama_Messages_Communications_DB::transaction( function () use ( $id ) {
            $row = $this->get( $id, true ); if ( 'communications' === $row['source_type'] || in_array( $row['status'], array( 'archived', 'source_removed' ), true ) ) { return; }
            $projection = Olama_Messages_Event_Sources::read( $row['source_type'], $row['source_id'] );
            $changes = array( 'source_checked_at_utc' => gmdate( 'Y-m-d H:i:s' ), 'source_error' => null );
            if ( null === $projection || ( $projection['source_status'] ?? 'active' ) !== 'active' ) { $changes['status'] = null === $projection ? 'source_removed' : 'cancelled'; }
            elseif ( $projection['source_version'] !== $row['source_version'] ) { foreach ( array( 'source_version', 'title', 'starts_at_utc', 'ends_at_utc', 'all_day', 'timezone' ) as $key ) { if ( isset( $projection[$key] ) ) { $changes[$key] = $projection[$key]; } } }
            if ( count( $changes ) > 2 ) {
                $changes['content_version'] = (int) $row['content_version'] + 1; $major = isset( $changes['status'] );
                foreach ( array( 'starts_at_utc', 'ends_at_utc', 'all_day', 'timezone' ) as $key ) { if ( isset( $changes[$key] ) && (string) $changes[$key] !== (string) $row[$key] ) { $major = true; } }
                if ( $major ) { $changes['ack_required_version'] = $row['requires_ack'] ? $changes['content_version'] : 0; $changes['rsvp_required_version'] = $row['rsvp_enabled'] ? $changes['content_version'] : 0; }
                $changes['updated_at_utc'] = gmdate( 'Y-m-d H:i:s' );
            }
            Olama_Messages_Suite_Policy::update( 'events', $changes, array( 'id' => $id ) );
            if ( isset( $changes['content_version'] ) ) {
                $current = $this->get( $id ); $actor = array( 'actor_key' => 'system:school', 'wp_user_id' => 0 );
                $this->revision( $current, $actor, array( 'source_sync' => true, 'major' => $major ) );
                Olama_Messages_Communications_DB::audit( 'event_source_synced', $id, $actor, array( 'version' => $current['content_version'], 'status' => $current['status'] ) );
                if ( $current['published_at_utc'] ) { $this->enqueue( 'event_release', $id, array( 'version' => (int) $current['content_version'], 'after' => 0 ) ); $this->schedule( $current ); }
            }
        } );
    }
    public function ics( array $actor, $id ) {
        $e = $this->visible( $actor, $id );
        $escape = static function ( $value ) { return str_replace( array( '\\', "\r", "\n", ';', ',' ), array( '\\\\', '', '\\n', '\\;', '\\,' ), (string) $value ); };
        $lines = array( 'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//OLAMA//Communications//AR', 'CALSCALE:GREGORIAN', 'BEGIN:VEVENT', 'UID:olama-event-' . $id . '@' . wp_parse_url( home_url(), PHP_URL_HOST ), 'SEQUENCE:' . $e['content_version'], 'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ), 'SUMMARY:' . $escape( $e['title'] ), 'DESCRIPTION:' . $escape( $e['description'] ), 'LOCATION:' . $escape( $e['location'] ) );
        foreach ( array( 'DTSTART' => 'starts_at_utc', 'DTEND' => 'ends_at_utc' ) as $key => $field ) {
            $date = new DateTimeImmutable( $e[$field], new DateTimeZone( 'UTC' ) );
            $lines[] = $e['all_day'] ? $key . ';VALUE=DATE:' . $date->setTimezone( new DateTimeZone( $e['timezone'] ) )->format( 'Ymd' ) : $key . ':' . $date->format( 'Ymd\THis\Z' );
        }
        $lines[] = 'STATUS:' . ( in_array( $e['status'], array( 'cancelled', 'source_removed' ), true ) ? 'CANCELLED' : 'CONFIRMED' ); $lines[] = 'END:VEVENT'; $lines[] = 'END:VCALENDAR';
        $folded = array(); foreach ( $lines as $line ) { $prefix = ''; while ( strlen( $line ) > 75 - strlen( $prefix ) ) { $part = mb_strcut( $line, 0, 75 - strlen( $prefix ), 'UTF-8' ); $folded[] = $prefix . $part; $line = substr( $line, strlen( $part ) ); $prefix = ' '; } $folded[] = $prefix . $line; }
        return implode( "\r\n", $folded ) . "\r\n";
    }
}
