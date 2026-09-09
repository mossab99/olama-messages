<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Olama_Messages_Chat_Service {
    public function access_sql( array $actor ) {
        return "((t.kind='direct' AND p.id IS NOT NULL) OR (t.kind='service' AND " . ( new Olama_Messages_Service_Inbox_Service() )->access_sql( $actor ) . '))';
    }

    private function join( array $actor ) {
        global $wpdb;
        $t = Olama_Messages_Communications_DB::table( 'threads' ); $p = Olama_Messages_Communications_DB::table( 'thread_participants' );
        return $wpdb->prepare( "{$t} t LEFT JOIN {$p} p ON p.thread_id=t.id AND p.actor_key=%s", $actor['actor_key'] );
    }

    public function get( array $actor, $id, $lock = false ) {
        global $wpdb;
        Olama_Messages_Chat_Policy::require_use( $actor );
        $join = $this->join( $actor ); $access = $this->access_sql( $actor );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT t.* FROM {$join} WHERE t.id=%d AND {$access}" . ( $lock ? ' FOR UPDATE' : '' ), $id ), ARRAY_A );
        if ( ! $row ) { throw new RuntimeException( 'المحادثة غير متاحة لهذه الهوية.' ); }
        if ( 'service' === $row['kind'] ) { ( new Olama_Messages_Service_Inbox_Service() )->check_access( $row, $actor, $lock ); }
        return $row;
    }

    public function participant( $id, array $actor ) {
        global $wpdb;
        $p = Olama_Messages_Communications_DB::table( 'thread_participants' );
        Olama_Messages_Communications_DB::query( $wpdb->prepare( "INSERT INTO {$p} (thread_id,actor_key,display_name) VALUES (%d,%s,%s) ON DUPLICATE KEY UPDATE id=id", $id, $actor['actor_key'], mb_substr( $actor['display_name'], 0, 190 ) ) );
    }

    /** Profile data already available to a participant, used by the compact chat drawer. */
    private function conversation_participant( array $actor, array $thread ) {
        global $wpdb;
        if ( 'direct' !== $thread['kind'] ) {
            return array( 'display_name' => $thread['subject'], 'type_label' => 'طلب خدمة', 'role' => 'قسم المدرسة', 'identifier' => '', 'phones' => array(), 'students' => array(), 'links' => array() );
        }
        $p = Olama_Messages_Communications_DB::table( 'thread_participants' );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT actor_key,display_name FROM {$p} WHERE thread_id=%d AND actor_key<>%s ORDER BY id LIMIT 1", $thread['id'], $actor['actor_key'] ), ARRAY_A );
        if ( ! $row ) { return null; }
        $key = (string) $row['actor_key']; $record = ( new Olama_Messages_Relationship_Provider() )->actor( $key );
        $profile = array(
            'display_name' => $record['name'] ?? $row['display_name'], 'type_label' => 'مستخدم', 'role' => 'مستخدم المدرسة',
            'identifier' => (string) preg_replace( '/^[^:]+:/', '', $key ), 'phones' => array(), 'students' => array(), 'links' => array(),
        );
        $family_id = ''; $study_year = (string) ( $thread['context']['study_year'] ?? '' );
        if ( 0 === strpos( $key, 'family:' ) ) {
            $profile['type_label'] = 'أسرة'; $profile['role'] = 'ولي أمر'; $family_id = substr( $key, 7 );
            if ( function_exists( 'olama_core' ) ) {
                $family = (array) olama_core()->families()->get_by_uid( $family_id );
                foreach ( array( 'primary_mobile', 'father_mobile', 'mother_mobile', 'family_home_phone' ) as $field ) {
                    $phone = trim( (string) ( $family[$field] ?? '' ) ); if ( $phone && ! in_array( $phone, $profile['phones'], true ) ) { $profile['phones'][] = $phone; }
                }
                try {
                    foreach ( ( new Olama_Messages_Relationship_Provider() )->children( $key ) as $student ) {
                        $profile['students'][] = array( 'id' => (string) $student['student_uid'], 'name' => (string) ( $student['student_name'] ?? $student['student_uid'] ), 'context' => trim( (string) ( $student['class_name'] ?? '' ) . ' · ' . (string) ( $student['section_name'] ?? '' ), " ·" ) );
                    }
                } catch ( Throwable $error ) { /* Historical conversations remain readable when Core context is unavailable. */ }
            }
        } elseif ( 0 === strpos( $key, 'employee:' ) ) {
            $employee = function_exists( 'olama_core' ) ? (array) olama_core()->employees()->get_by_employee_id( substr( $key, 9 ) ) : array();
            $is_teacher = ! empty( $record['teacher'] ); $profile['type_label'] = $is_teacher ? 'معلم' : 'موظف';
            $profile['role'] = (string) ( $employee['job_title'] ?? ( $is_teacher ? 'معلم' : 'موظف المدرسة' ) );
            $phone = trim( (string) ( $employee['phones'] ?? '' ) ); if ( $phone ) { $profile['phones'][] = $phone; }
        } elseif ( 0 === strpos( $key, 'administrator:' ) ) { $profile['type_label'] = 'إدارة'; $profile['role'] = 'مدير النظام'; }
        if ( ! empty( $thread['context']['student_uid'] ) ) {
            $student_id = (string) $thread['context']['student_uid'];
            if ( ! array_filter( $profile['students'], static function ( $student ) use ( $student_id ) { return $student['id'] === $student_id; } ) ) {
                $profile['students'][] = array( 'id' => $student_id, 'name' => (string) ( $thread['context']['student_name'] ?? $student_id ), 'context' => trim( (string) ( $thread['context']['class_name'] ?? '' ) . ' · ' . (string) ( $thread['context']['section_name'] ?? '' ), " ·" ) );
            }
            if ( ! $family_id && ! empty( $thread['context']['family_key'] ) ) { $family_id = substr( (string) $thread['context']['family_key'], 7 ); }
        }
        if ( $family_id && current_user_can( 'manage_options' ) ) {
            $base = array( 'family_id' => absint( $family_id ) ); if ( $study_year ) { $base['study_year'] = $study_year; }
            foreach ( array( 'olama-core-family-360' => 'ملف الأسرة', 'olama-core-family-financial-card' => 'السجل المالي', 'olama-core-family-transportation-card' => 'سجل المواصلات' ) as $page => $label ) {
                $profile['links'][] = array( 'label' => $label, 'url' => add_query_arg( array_merge( array( 'page' => $page ), $base ), admin_url( 'admin.php' ) ) );
            }
            foreach ( $profile['students'] as $student ) { $profile['links'][] = array( 'label' => 'بطاقة ' . $student['name'], 'url' => add_query_arg( array_merge( array( 'page' => 'olama-core-student-card', 'student_id' => absint( $student['id'] ) ), $base ), admin_url( 'admin.php' ) ) ); }
        }
        return $profile;
    }

    /** Called inside the mutation transaction. Single clock lock guarantees commit-ordered feed IDs. */
    public function change( $id, $kind ) {
        global $wpdb;
        $clock = Olama_Messages_Communications_DB::table( 'chat_clock' );
        Olama_Messages_Communications_DB::query( "INSERT INTO {$clock} (id,sequence) VALUES (1,1) ON DUPLICATE KEY UPDATE sequence=sequence+1" );
        $sequence = $wpdb->get_var( "SELECT sequence FROM {$clock} WHERE id=1 FOR UPDATE" );
        Olama_Messages_Communications_DB::insert( 'chat_changes', array( 'id' => $sequence, 'thread_id' => $id, 'kind' => $kind, 'created_at_utc' => gmdate( 'Y-m-d H:i:s' ) ) );
    }

    public function create( array $actor, array $data ) {
        global $wpdb;
        Olama_Messages_Chat_Policy::require_use( $actor );
        if ( array_diff( array_keys( $data ), array( 'kind', 'target', 'context', 'inbox_id', 'subject', 'client_thread_id' ) ) ) { throw new InvalidArgumentException( 'حقول إنشاء غير مدعومة.' ); }
        $kind = $data['kind'] ?? 'direct';
        if ( ! in_array( $kind, array( 'direct', 'service' ), true ) ) { throw new InvalidArgumentException( 'المحادثات الجماعية والطلاب غير مفعلة.' ); }
        return Olama_Messages_Communications_DB::transaction( function () use ( $wpdb, $actor, $data, $kind ) {
            $target = (string) ( $data['target'] ?? '' ); $inbox_id = 0; $context = array();
            if ( 'direct' === $kind ) {
                $relation = ( new Olama_Messages_Relationship_Provider() )->relationship( $actor['actor_key'], $target, (array) ( $data['context'] ?? array() ) );
                if ( 'valid' !== $relation['relationship_status'] ) { throw new RuntimeException( 'العلاقة الدراسية غير متاحة: ' . $relation['relationship_status'] ); }
                if ( 'eligible_account' !== ( new Olama_Messages_Actor_Resolver() )->reachability( $target ) ) { throw new RuntimeException( 'حساب المستلم غير متاح للمراسلة.' ); }
                $context = $relation['context'];
                $keys = array( $actor['actor_key'], $target ); sort( $keys, SORT_STRING );
                // Semester is a snapshot; assignments are year-owned. Assignment ID prevents successor inheritance.
                $key_context = $context; unset( $key_context['semester_id'], $key_context['student_name'], $key_context['class_name'], $key_context['section_name'], $key_context['subject_name'] );
                $key = 'direct:' . hash( 'sha256', wp_json_encode( array( $keys, $key_context ) ) );
                $subject = isset( $context['student_name'] ) ? $context['student_name'] . ' · ' . $context['subject_name'] : ( 'administrator' === ( $context['scope'] ?? '' ) ? 'مراسلة إدارية' : 'مراسلة الموظفين' );
            } else {
                $inbox_id = absint( $data['inbox_id'] ?? 0 );
                $inbox = ( new Olama_Messages_Service_Inbox_Service() )->require_contact( $inbox_id, $actor, true );
                $uuid = (string) ( $data['client_thread_id'] ?? '' );
                if ( ! wp_is_uuid( $uuid, 4 ) ) { throw new InvalidArgumentException( 'معرف طلب UUID مطلوب.' ); }
                $key = 'service:' . hash( 'sha256', $actor['actor_key'] . ':' . $inbox_id . ':' . strtolower( $uuid ) );
                $subject = Olama_Messages_Chat_Policy::plain_text( $data['subject'] ?? '', 190 );
                $context = array( 'inbox_name' => $inbox['name'] ); $target = 'service_inbox:' . $inbox_id;
            }
            $t = Olama_Messages_Communications_DB::table( 'threads' );
            $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE thread_key=%s", $key ) );
            if ( $existing ) {
                $existing_thread = $this->get( $actor, $existing );
                if ( 'service' === $kind && $existing_thread['subject'] !== $subject ) { throw new RuntimeException( 'معرف الطلب مستخدم بعنوان آخر.' ); }
                return array( 'id' => (int) $existing_thread['id'] );
            }
            Olama_Messages_Chat_Policy::restrictions( $actor, 'create', array( 'inbox_id' => $inbox_id ), $target );
            Olama_Messages_Chat_Policy::rate( $actor, 'thread' );
            Olama_Messages_Communications_DB::query( $wpdb->prepare( "INSERT INTO {$t} (thread_key,kind,subject,context_json,inbox_id,requester_key,created_at_utc) VALUES (%s,%s,%s,%s,%d,%s,%s) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)", $key, $kind, mb_substr( $subject, 0, 190 ), wp_json_encode( $context ), $inbox_id, 'service' === $kind ? $actor['actor_key'] : '', gmdate( 'Y-m-d H:i:s' ) ) );
            $id = (int) $wpdb->insert_id;
            $this->participant( $id, $actor );
            if ( 'direct' === $kind ) {
                $record = ( new Olama_Messages_Relationship_Provider() )->actor( $target );
                if ( ! $record ) { throw new RuntimeException( 'تعذر تحميل حساب المستلم.' ); }
                $this->participant( $id, array( 'actor_key' => $target, 'display_name' => $record['name'] ?? $target ) );
            }
            Olama_Messages_Communications_DB::audit( 'thread_created', $id, $actor, array( 'kind' => $kind ) );
            $this->change( $id, 'created' );
            return array( 'id' => $id );
        } );
    }

    public function send_state( array $actor, array $thread, $operation = 'send', $lock = false ) {
        global $wpdb;
        try {
            $target = '';
            if ( 'direct' === $thread['kind'] ) {
                $p = Olama_Messages_Communications_DB::table( 'thread_participants' );
                $target = $wpdb->get_var( $wpdb->prepare( "SELECT actor_key FROM {$p} WHERE thread_id=%d AND actor_key<>%s ORDER BY id LIMIT 1", $thread['id'], $actor['actor_key'] ) );
                $relation = ( new Olama_Messages_Relationship_Provider() )->relationship( $actor['actor_key'], $target, (array) json_decode( $thread['context_json'], true ) );
                if ( 'valid' !== $relation['relationship_status'] ) { return array( 'allowed' => false, 'relationship' => $relation, 'reason' => 'العلاقة الحالية لا تسمح بالإرسال. السجل متاح للقراءة.' ); }
                if ( 'eligible_account' !== ( new Olama_Messages_Actor_Resolver() )->reachability( $target ) ) { throw new RuntimeException( 'حساب المستلم غير متاح حالياً.' ); }
            } else {
                $inboxes = new Olama_Messages_Service_Inbox_Service(); $inbox = $inboxes->get( $thread['inbox_id'], $lock );
                if ( ! $inbox['active'] || ! in_array( $thread['status'], array( 'open', 'in_progress' ), true ) ) { throw new RuntimeException( 'الطلب مغلق أو الصندوق غير فعال.' ); }
                if ( $actor['actor_key'] === $thread['requester_key'] ) { $inboxes->require_contact( $thread['inbox_id'], $actor, $lock ); $target = 'service_inbox:' . $thread['inbox_id']; }
                else {
                    $member = $inboxes->member( $thread['inbox_id'], $actor, $lock );
                    if ( ! $member || ( $thread['assignee_key'] && $thread['assignee_key'] !== $actor['actor_key'] && ! $member['is_manager'] ) ) { throw new RuntimeException( 'الرد للمسؤول عن الطلب أو مدير الصندوق.' ); }
                    $target = $thread['requester_key'];
                }
            }
            Olama_Messages_Chat_Policy::restrictions( $actor, $operation, $thread, $target );
            $settings = Olama_Messages_Communication_Policy::settings(); $minute = (int) wp_date( 'G' ) * 60 + (int) wp_date( 'i' );
            $outside = ! in_array( (int) wp_date( 'w' ), array_map( 'intval', (array) $settings['office_days'] ), true ) || $minute < (int) $settings['office_start'] || $minute >= (int) $settings['office_end'];
            return array( 'allowed' => true, 'office_hours_note' => $outside ? 'تُرسل الرسالة الآن؛ قد يتم الرد خلال ساعات الدوام الرسمية.' : '' );
        } catch ( Throwable $error ) { return array( 'allowed' => false, 'reason' => $error->getMessage() ); }
    }

    public function send( array $actor, $id, array $data ) {
        global $wpdb;
        Olama_Messages_Chat_Policy::require_use( $actor );
        if ( array_diff( array_keys( $data ), array( 'body', 'client_message_id', 'reply_to', 'attachment_ids' ) ) ) { throw new InvalidArgumentException( 'المرفقات والتحويل غير مفعلين.' ); }
        $files = Olama_Messages_Suite_Policy::bounded_ids( $data['attachment_ids'] ?? array() );
        if ( $files ) { Olama_Messages_Suite_Policy::feature( $actor, 'attachments' ); }
        $body = $files && '' === trim( (string) ( $data['body'] ?? '' ) ) ? '' : Olama_Messages_Chat_Policy::plain_text( $data['body'] ?? '', (int) Olama_Messages_Communication_Policy::settings()['message_max_chars'] );
        $uuid = strtolower( (string) ( $data['client_message_id'] ?? '' ) );
        if ( ! wp_is_uuid( $uuid, 4 ) ) { throw new InvalidArgumentException( 'معرف رسالة UUID مطلوب.' ); }
        return Olama_Messages_Communications_DB::transaction( function () use ( $wpdb, $actor, $id, $data, $body, $uuid, $files ) {
            $thread = $this->get( $actor, $id, true );
            $m = Olama_Messages_Communications_DB::table( 'messages' );
            $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$m} WHERE sender_key=%s AND client_message_id=%s FOR UPDATE", $actor['actor_key'], $uuid ), ARRAY_A );
            $hash_data = array( (int) $id, $body, absint( $data['reply_to'] ?? 0 ) ); if ( $files ) { $hash_data[] = $files; }
            $request_hash = hash( 'sha256', wp_json_encode( $hash_data ) );
            if ( $existing ) {
                if ( ! hash_equals( $existing['request_hash'], $request_hash ) ) { throw new RuntimeException( 'معرف الرسالة مستخدم لمحتوى آخر.' ); }
                return array( 'id' => (int) $existing['id'], 'duplicate' => true );
            }
            if ( $thread['inbox_id'] ) { ( new Olama_Messages_Service_Inbox_Service() )->get( $thread['inbox_id'], true ); }
            $state = $this->send_state( $actor, $thread, 'send', true );
            if ( ! $state['allowed'] ) { throw new RuntimeException( $state['reason'] ); }
            $reply = absint( $data['reply_to'] ?? 0 );
            if ( $reply && ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$m} WHERE id=%d AND thread_id=%d AND redacted_at_utc IS NULL", $reply, $id ) ) ) { throw new RuntimeException( 'الرسالة المقتبسة غير متاحة في هذه المحادثة.' ); }
            Olama_Messages_Chat_Policy::rate( $actor, 'message' );
            $this->participant( $id, $actor ); $now = gmdate( 'Y-m-d H:i:s' );
            $message_id = Olama_Messages_Communications_DB::insert( 'messages', array( 'thread_id' => $id, 'sender_key' => $actor['actor_key'], 'authenticated_wp_user_id' => get_current_user_id(), 'display_name' => mb_substr( $actor['display_name'], 0, 190 ), 'client_message_id' => $uuid, 'request_hash' => $request_hash, 'body' => $body, 'reply_to' => $reply, 'sent_at_utc' => $now ) );
            $t = Olama_Messages_Communications_DB::table( 'threads' ); $p = Olama_Messages_Communications_DB::table( 'thread_participants' );
            Olama_Messages_Communications_DB::query( $wpdb->prepare( "UPDATE {$t} SET last_message_id=%d WHERE id=%d", $message_id, $id ) );
            Olama_Messages_Communications_DB::query( $wpdb->prepare( "UPDATE {$p} SET unread_count=unread_count+1 WHERE thread_id=%d AND actor_key<>%s", $id, $actor['actor_key'] ) );
            Olama_Messages_Communications_DB::query( $wpdb->prepare( "UPDATE {$p} SET participated_at_utc=%s WHERE thread_id=%d AND actor_key=%s", $now, $id, $actor['actor_key'] ) );
            if ( 'service' === $thread['kind'] && $actor['actor_key'] !== $thread['requester_key'] ) {
                Olama_Messages_Communications_DB::query( $wpdb->prepare( "UPDATE {$t} SET department_responded_at_utc=COALESCE(department_responded_at_utc,%s),assignee_key=IF(assignee_key='',%s,assignee_key) WHERE id=%d", $now, $actor['actor_key'], $id ) );
                if ( ! $thread['assignee_key'] ) {
                    Olama_Messages_Communications_DB::insert( 'thread_actions', array( 'thread_id' => $id, 'action' => 'assign', 'actor_key' => $actor['actor_key'], 'authenticated_wp_user_id' => get_current_user_id(), 'target_key' => $actor['actor_key'], 'created_at_utc' => $now ) );
                    Olama_Messages_Communications_DB::audit( 'service_auto_assigned', $id, $actor );
                }
            }
            if ( $files ) { ( new Olama_Messages_Attachment_Service() )->claim( $actor, $files, 'message', $message_id, 'thread', $id ); }
            Olama_Messages_Communications_DB::audit( 'message_sent', $message_id, $actor, array( 'thread_id' => $id ) );
            $this->change( $id, 'message' );
            return array( 'id' => $message_id, 'duplicate' => false );
        } );
    }

    private function unread_sql( array $actor ) {
        global $wpdb; $m = Olama_Messages_Communications_DB::table( 'messages' );
        return $wpdb->prepare( "(SELECT COUNT(*) FROM {$m} um WHERE um.thread_id=t.id AND um.id>COALESCE(p.read_cursor,0) AND um.sender_key<>%s)", $actor['actor_key'] );
    }

    public function listing( array $actor, $before = 0, $archived = false, $inbox = 0, $cursor = '' ) {
        global $wpdb;
        Olama_Messages_Chat_Policy::require_use( $actor );
        $join = $this->join( $actor ); $access = $this->access_sql( $actor ); $unread = $this->unread_sql( $actor );
        $messages = Olama_Messages_Communications_DB::table( 'messages' );
        $participants = Olama_Messages_Communications_DB::table( 'thread_participants' );
        $correspondent = $wpdb->prepare( "(SELECT cp.display_name FROM {$participants} cp WHERE cp.thread_id=t.id AND cp.actor_key<>%s ORDER BY cp.id LIMIT 1)", $actor['actor_key'] );
        $keyset = '';
        if ( $cursor ) {
            if ( ! preg_match( '/^([01]):([0-9]{1,19}):([0-9]{1,19})$/', $cursor, $parts ) ) { throw new InvalidArgumentException( 'مؤشر القائمة غير صالح.' ); }
            $keyset = $wpdb->prepare( ' AND (COALESCE(p.pinned,0)<%d OR (COALESCE(p.pinned,0)=%d AND (t.last_message_id<%d OR (t.last_message_id=%d AND t.id<%d))))', $parts[1], $parts[1], $parts[2], $parts[2], $parts[3] );
        }
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT t.id,t.kind,t.subject,t.inbox_id,t.status,t.assignee_key,t.last_message_id,COALESCE(p.pinned,0) AS pinned,COALESCE(p.muted,0) AS muted,COALESCE(p.manual_unread,0) AS manual_unread,{$unread} AS unread_count,{$correspondent} AS correspondent_name,CASE WHEN lm.redacted_at_utc IS NOT NULL THEN 'تم حجب الرسالة' ELSE LEFT(lm.body,280) END AS last_message,lm.sender_key AS last_sender_key,lm.sent_at_utc AS last_message_at FROM {$join} LEFT JOIN {$messages} lm ON lm.id=t.last_message_id WHERE {$access} AND (%d=0 OR t.id<%d) AND COALESCE(p.archived,0)=%d AND (%d=0 OR t.inbox_id=%d) {$keyset} ORDER BY COALESCE(p.pinned,0) DESC,t.last_message_id DESC,t.id DESC LIMIT 30", $before, $before, $archived ? 1 : 0, $inbox, $inbox ), ARRAY_A );
        foreach ( $rows as &$row ) {
            $row['page_cursor'] = $row['pinned'] . ':' . $row['last_message_id'] . ':' . $row['id'];
            $row['last_message_own'] = $actor['actor_key'] === (string) $row['last_sender_key'];
            if ( 'direct' !== $row['kind'] ) { $row['correspondent_name'] = ''; }
            unset( $row['last_sender_key'] );
            if ( 'family' === $actor['actor_type'] ) { unset( $row['assignee_key'] ); }
        } unset( $row );
        return $rows;
    }

    public function conversation( array $actor, $id, $before = 0 ) {
        global $wpdb;
        $thread = $this->get( $actor, $id );
        $m = Olama_Messages_Communications_DB::table( 'messages' ); $p = Olama_Messages_Communications_DB::table( 'thread_participants' );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT m.id,m.sender_key,m.display_name,m.body,m.reply_to,m.sent_at_utc,m.edited_at_utc,m.redacted_at_utc,q.body AS quote_body,q.redacted_at_utc AS quote_redacted FROM {$m} m LEFT JOIN {$m} q ON q.id=m.reply_to AND q.thread_id=m.thread_id WHERE m.thread_id=%d AND (%d=0 OR m.id<%d) ORDER BY m.id DESC LIMIT 50", $id, $before, $before ), ARRAY_A );
        $inboxes = new Olama_Messages_Service_Inbox_Service();
        $inbox = $thread['inbox_id'] ? $inboxes->get( $thread['inbox_id'] ) : null;
        foreach ( $rows as &$row ) {
            $row['attachments'] = $row['redacted_at_utc'] ? array() : ( new Olama_Messages_Attachment_Service() )->listing( $actor, 'message', $row['id'] );
            $row['own'] = $row['sender_key'] === $actor['actor_key'];
            if ( $row['redacted_at_utc'] ) { $row['body'] = 'تم حجب الرسالة'; }
            if ( $row['quote_redacted'] ) { $row['quote_body'] = 'تم حجب الرسالة المقتبسة'; }
            if ( $inbox && $row['sender_key'] !== $thread['requester_key'] ) { $row['display_name'] = $inbox['name'] . ( $inbox['show_employee'] ? ' · ' . $row['display_name'] : '' ); }
            unset( $row['sender_key'], $row['quote_redacted'] );
        } unset( $row );
        $receipts = array();
        if ( ! $inbox ) { $receipts = $wpdb->get_results( $wpdb->prepare( "SELECT display_name,delivered_cursor,read_cursor FROM {$p} WHERE thread_id=%d AND actor_key<>%s", $id, $actor['actor_key'] ), ARRAY_A ); }
        $personal = $wpdb->get_row( $wpdb->prepare( "SELECT archived,muted,pinned,manual_unread,read_cursor,delivered_cursor FROM {$p} WHERE thread_id=%d AND actor_key=%s", $id, $actor['actor_key'] ), ARRAY_A );
        $member = $inbox ? $inboxes->member( $inbox['id'], $actor ) : null;
        $actions = $member ? $wpdb->get_results( $wpdb->prepare( 'SELECT action,actor_key,target_key,created_at_utc FROM ' . Olama_Messages_Communications_DB::table( 'thread_actions' ) . ' WHERE thread_id=%d ORDER BY id DESC LIMIT 50', $id ), ARRAY_A ) : array();
        $state = $this->send_state( $actor, $thread );
        $thread['context'] = json_decode( $thread['context_json'], true ); unset( $thread['context_json'], $thread['thread_key'] );
        $participant = $this->conversation_participant( $actor, $thread );
        if ( $inbox && ! $member ) { unset( $thread['assignee_key'], $thread['requester_key'] ); }
        return array( 'thread' => $thread, 'participant' => $participant, 'messages' => array_reverse( $rows ), 'send_state' => $state, 'receipts' => $receipts, 'personal' => $personal, 'member' => $member ? array( 'manager' => (bool) $member['is_manager'] ) : null, 'actions' => $actions, 'next' => count( $rows ) === 50 ? (int) end( $rows )['id'] : 0 );
    }

    public function receipt( array $actor, $id, $cursor, $kind ) {
        global $wpdb;
        if ( ! in_array( $kind, array( 'delivered', 'read' ), true ) ) { throw new InvalidArgumentException( 'نوع الإيصال غير صالح.' ); }
        return Olama_Messages_Communications_DB::transaction( function () use ( $wpdb, $actor, $id, $cursor, $kind ) {
            $thread = $this->get( $actor, $id, true );
            $m = Olama_Messages_Communications_DB::table( 'messages' ); $p = Olama_Messages_Communications_DB::table( 'thread_participants' );
            if ( ! $cursor || ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$m} WHERE id=%d AND thread_id=%d", $cursor, $id ) ) ) { throw new InvalidArgumentException( 'مؤشر الإيصال خارج المحادثة.' ); }
            $this->participant( $id, $actor );
            $column = 'read' === $kind ? 'read_cursor' : 'delivered_cursor';
            $changed = Olama_Messages_Communications_DB::query( $wpdb->prepare( "UPDATE {$p} SET {$column}=GREATEST({$column},%d) WHERE thread_id=%d AND actor_key=%s", $cursor, $id, $actor['actor_key'] ) );
            if ( 'read' === $kind ) {
                Olama_Messages_Communications_DB::query( $wpdb->prepare( "UPDATE {$p} p SET unread_count=(SELECT COUNT(*) FROM {$m} m WHERE m.thread_id=p.thread_id AND m.id>p.read_cursor AND m.sender_key<>p.actor_key),manual_unread=0 WHERE thread_id=%d AND actor_key=%s", $id, $actor['actor_key'] ) );
                if ( 'service' === $thread['kind'] && $actor['actor_key'] !== $thread['requester_key'] && ! $thread['department_viewed_at_utc'] ) {
                    $t = Olama_Messages_Communications_DB::table( 'threads' );
                    Olama_Messages_Communications_DB::query( $wpdb->prepare( "UPDATE {$t} SET department_viewed_at_utc=%s WHERE id=%d", gmdate( 'Y-m-d H:i:s' ), $id ) ); $changed = true;
                }
            }
            if ( $changed ) { $this->change( $id, 'receipt' ); }
            return array( 'ok' => true );
        } );
    }

    public function preferences( array $actor, $id, array $data ) {
        global $wpdb;
        if ( ! $data || array_diff( array_keys( $data ), array( 'archived', 'muted', 'pinned', 'manual_unread' ) ) ) { throw new InvalidArgumentException( 'تفضيلات غير صالحة.' ); }
        return Olama_Messages_Communications_DB::transaction( function () use ( $wpdb, $actor, $id, $data ) {
            $this->get( $actor, $id, true ); $this->participant( $id, $actor );
            $values = array(); foreach ( $data as $key => $value ) { $values[$key] = $value ? 1 : 0; }
            if ( false === $wpdb->update( Olama_Messages_Communications_DB::table( 'thread_participants' ), $values, array( 'thread_id' => $id, 'actor_key' => $actor['actor_key'] ) ) ) { throw new RuntimeException( 'تعذر حفظ التفضيلات.' ); }
            $this->change( $id, 'preferences' ); return array( 'ok' => true );
        } );
    }

    public function edit( array $actor, $message_id, array $data ) {
        global $wpdb;
        $body = Olama_Messages_Chat_Policy::plain_text( $data['body'] ?? '', (int) Olama_Messages_Communication_Policy::settings()['message_max_chars'] );
        return Olama_Messages_Communications_DB::transaction( function () use ( $wpdb, $actor, $message_id, $body ) {
            $m = Olama_Messages_Communications_DB::table( 'messages' );
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$m} WHERE id=%d", $message_id ), ARRAY_A );
            if ( ! $row ) { throw new RuntimeException( 'الرسالة غير متاحة.' ); }
            $thread = $this->get( $actor, $row['thread_id'], true );
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$m} WHERE id=%d FOR UPDATE", $message_id ), ARRAY_A );
            $minutes = (int) Olama_Messages_Communication_Policy::settings()['edit_minutes'];
            if ( $row['sender_key'] !== $actor['actor_key'] || $row['redacted_at_utc'] || time() > strtotime( $row['sent_at_utc'] . ' UTC' ) + $minutes * 60 ) { throw new RuntimeException( 'انتهت مهلة التعديل أو الرسالة ليست لك.' ); }
            $state = $this->send_state( $actor, $thread, 'edit', true ); if ( ! $state['allowed'] ) { throw new RuntimeException( $state['reason'] ); }
            if ( $body === $row['body'] ) { return array( 'ok' => true ); }
            Olama_Messages_Chat_Policy::rate( $actor, 'message' );
            Olama_Messages_Communications_DB::insert( 'message_revisions', array( 'message_id' => $message_id, 'body' => $row['body'], 'actor_key' => $actor['actor_key'], 'authenticated_wp_user_id' => get_current_user_id(), 'created_at_utc' => gmdate( 'Y-m-d H:i:s' ) ) );
            Olama_Messages_Communications_DB::query( $wpdb->prepare( "UPDATE {$m} SET body=%s,edited_at_utc=%s WHERE id=%d", $body, gmdate( 'Y-m-d H:i:s' ), $message_id ) );
            Olama_Messages_Communications_DB::audit( 'message_edited', $message_id, $actor ); $this->change( $thread['id'], 'edit' );
            return array( 'ok' => true );
        } );
    }

    public function feed( array $actor, $after = 0 ) {
        global $wpdb;
        Olama_Messages_Chat_Policy::require_use( $actor );
        $c = Olama_Messages_Communications_DB::table( 'chat_changes' ); $join = $this->join( $actor ); $access = $this->access_sql( $actor );
        $high = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$c}" );
        $m = Olama_Messages_Communications_DB::table( 'messages' );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT c.id,c.thread_id,c.kind,(c.kind='message' AND COALESCE(p.muted,0)=0 AND COALESCE(p.archived,0)=0 AND cm.sender_key<>%s AND cm.id>COALESCE(p.read_cursor,0)) AS notify FROM {$join} INNER JOIN {$c} c ON t.id=c.thread_id LEFT JOIN {$m} cm ON cm.id=t.last_message_id WHERE c.id>%d AND c.id<=%d AND {$access} ORDER BY c.id LIMIT 100", $actor['actor_key'], $after, $high ), ARRAY_A );
        if ( $wpdb->last_error ) { throw new RuntimeException( 'تعذر قراءة تحديثات المراسلة.' ); }
        $unread = $this->unread_sql( $actor );
        $count = (int) $wpdb->get_var( "SELECT COALESCE(SUM({$unread}),0) FROM {$join} WHERE {$access} AND COALESCE(p.archived,0)=0" );
        return array( 'changes' => $rows, 'cursor' => count( $rows ) === 100 ? (int) end( $rows )['id'] : $high, 'unread' => $count );
    }

    public static function reconcile() {
        global $wpdb;
        if ( empty( Olama_Messages_Communication_Policy::settings()['chat_enabled'] ) || Olama_Messages_Communications_DB::health() ) { return; }
        $p = Olama_Messages_Communications_DB::table( 'thread_participants' ); $m = Olama_Messages_Communications_DB::table( 'messages' );
        $cursor = (int) get_option( 'olama_msg_chat_reconcile_cursor', 0 );
        $ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p} WHERE id>%d ORDER BY id LIMIT 100", $cursor ) );
        if ( $ids ) { Olama_Messages_Communications_DB::query( "UPDATE {$p} p SET unread_count=(SELECT COUNT(*) FROM {$m} m WHERE m.thread_id=p.thread_id AND m.id>p.read_cursor AND m.sender_key<>p.actor_key) WHERE p.id IN (" . implode( ',', array_map( 'absint', $ids ) ) . ')' ); }
        update_option( 'olama_msg_chat_reconcile_cursor', count( $ids ) === 100 ? (int) end( $ids ) : 0, false );
        update_option( 'olama_msg_chat_reconciled_at', gmdate( 'Y-m-d H:i:s' ), false );
        $rates = Olama_Messages_Communications_DB::table( 'chat_rate_limits' );
        Olama_Messages_Communications_DB::query( "DELETE FROM {$rates} WHERE expires_at_utc<UTC_TIMESTAMP() LIMIT 500" );
    }
}
