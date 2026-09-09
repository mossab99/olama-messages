<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Olama_Messages_Action_Service {
    public function get( array $actor, $id, $lock = false ) {
        global $wpdb;
        Olama_Messages_Suite_Policy::feature( $actor, 'actions' );
        $t = Olama_Messages_Communications_DB::table( 'action_items' );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id=%d" . ( $lock ? ' FOR UPDATE' : '' ), $id ), ARRAY_A );
        if ( ! $row ) { throw new RuntimeException( 'الإجراء غير موجود.' ); }
        if ( $row['thread_id'] ) { ( new Olama_Messages_Chat_Service() )->get( $actor, $row['thread_id'] ); }
        if ( 'event_target' === $row['source_type'] ) {
            if ( Olama_Messages_Communication_Policy::staff_actor( $actor ) && Olama_Messages_Communication_Policy::can( 'olama_messages_manage_events' ) ) {
                $targets = Olama_Messages_Communications_DB::table( 'event_targets' ); $event_id = $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$targets} WHERE id=%d", $row['source_id'] ) );
                ( new Olama_Messages_Event_Service() )->visible( $actor, $event_id );
            } else { ( new Olama_Messages_Event_Service() )->target( $actor, $row['source_id'] ); }
        }
        $allowed = $row['owner_key'] === $actor['actor_key'] || $row['created_by_key'] === $actor['actor_key'];
        if ( $row['owner_inbox_id'] ) { $allowed = (bool) ( new Olama_Messages_Service_Inbox_Service() )->member( $row['owner_inbox_id'], $actor ); }
        if ( ! $allowed && ! ( Olama_Messages_Communication_Policy::staff_actor( $actor ) && Olama_Messages_Communication_Policy::can( 'olama_messages_manage_actions' ) && ! $row['thread_id'] && 'event_target' !== $row['source_type'] ) ) { throw new RuntimeException( 'لا تملك صلاحية هذا الإجراء.' ); }
        return $row;
    }

    /** Internal integration contract: caller owns the transaction and source authorization. */
    public function create_internal( array $data, array $actor, $key ) {
        global $wpdb;
        if ( empty( Olama_Messages_Communication_Policy::settings()['actions_enabled'] ) ) { throw new RuntimeException( 'سير الإجراءات غير مفعل.' ); }
        $t = Olama_Messages_Communications_DB::table( 'action_items' ); $hash = hash( 'sha256', $key );
        $request_hash = hash( 'sha256', wp_json_encode( array( $data['source_type'], (int) $data['source_id'], (int) ( $data['thread_id'] ?? 0 ), (string) ( $data['owner_key'] ?? '' ), (int) ( $data['owner_inbox_id'] ?? 0 ), (string) ( $data['title'] ?? '' ), $data['priority'] ?? 'normal', $data['due_at_utc'] ?? null ) ) );
        $existing = $wpdb->get_row( $wpdb->prepare( "SELECT id,request_hash FROM {$t} WHERE action_key=%s FOR UPDATE", $hash ), ARRAY_A );
        if ( $existing ) { if ( 0 === strpos( $key, 'manual:' ) && ! hash_equals( $existing['request_hash'], $request_hash ) ) { throw new RuntimeException( 'معرف الإجراء مستخدم لمحتوى آخر.' ); } return (int) $existing['id']; }
        $title = Olama_Messages_Chat_Policy::plain_text( $data['title'] ?? '', 190 );
        $priority = $data['priority'] ?? 'normal';
        if ( ! in_array( $priority, array( 'normal', 'important', 'urgent' ), true ) ) { throw new InvalidArgumentException( 'أولوية غير صالحة.' ); }
        $owner = (string) ( $data['owner_key'] ?? '' ); $inbox = absint( $data['owner_inbox_id'] ?? 0 );
        if ( ( '' === $owner ) === ( 0 === $inbox ) ) { throw new InvalidArgumentException( 'حدد مسؤولاً واحداً أو صندوق خدمة.' ); }
        $now = gmdate( 'Y-m-d H:i:s' );
        $id = Olama_Messages_Communications_DB::insert( 'action_items', array(
            'action_key' => $hash, 'request_hash' => $request_hash, 'source_type' => $data['source_type'], 'source_id' => $data['source_id'], 'thread_id' => $data['thread_id'] ?? 0,
            'owner_key' => $owner, 'owner_inbox_id' => $inbox, 'title' => $title, 'priority' => $priority,
            'due_at_utc' => Olama_Messages_Suite_Policy::utc( $data['due_at_utc'] ?? null, true ),
            'created_by_key' => $actor['actor_key'], 'created_by_wp_user_id' => $actor['wp_user_id'] ?? get_current_user_id(),
            'resolution_note' => '', 'created_at_utc' => $now, 'updated_at_utc' => $now,
        ) );
        $this->history( $id, 1, $actor, array( 'created' => true, 'owner_key' => $owner, 'owner_inbox_id' => $inbox ) );
        if ( $owner ) { Olama_Messages_Activity_Service::emit( $owner, 'action', $id, 1, 'action_assigned' ); }
        return $id;
    }

    public function create( array $actor, array $data ) {
        Olama_Messages_Suite_Policy::feature( $actor, 'actions' );
        return Olama_Messages_Communications_DB::transaction( function () use ( $actor, $data ) {
            $type = $data['source_type'] ?? 'system'; $id = absint( $data['source_id'] ?? 0 ); $thread_id = 0;
            if ( in_array( $type, array( 'thread', 'message' ), true ) ) {
                if ( 'message' === $type ) { global $wpdb; $m = Olama_Messages_Communications_DB::table( 'messages' ); $thread_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT thread_id FROM {$m} WHERE id=%d AND redacted_at_utc IS NULL", $id ) ); }
                else { $thread_id = $id; }
                $thread = ( new Olama_Messages_Chat_Service() )->get( $actor, $thread_id, true );
                $state = ( new Olama_Messages_Chat_Service() )->send_state( $actor, $thread ); if ( ! $state['allowed'] ) { throw new RuntimeException( $state['reason'] ); }
            } elseif ( 'system' === $type ) { Olama_Messages_Suite_Policy::staff( $actor, 'manage_actions' ); }
            else { throw new InvalidArgumentException( 'مصدر الإجراء غير مدعوم.' ); }
            $owner = (string) ( $data['owner_key'] ?? $actor['actor_key'] ); $inbox = absint( $data['owner_inbox_id'] ?? 0 );
            if ( $inbox ) {
                if ( ! $thread_id || (int) $thread['inbox_id'] !== $inbox || ! ( new Olama_Messages_Service_Inbox_Service() )->member( $inbox, $actor ) ) { throw new RuntimeException( 'صندوق الإجراء غير مخول.' ); }
                $owner = '';
            } elseif ( $owner !== $actor['actor_key'] ) {
                Olama_Messages_Suite_Policy::staff( $actor, 'manage_actions' );
                if ( ! Olama_Messages_Suite_Policy::user_for_actor( $owner, 'actions' ) ) { throw new RuntimeException( 'المسؤول غير متاح.' ); }
                if ( $thread_id ) { global $wpdb; $p = Olama_Messages_Communications_DB::table( 'thread_participants' ); if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p} WHERE thread_id=%d AND actor_key=%s", $thread_id, $owner ) ) ) { throw new RuntimeException( 'المسؤول ليس طرفاً في المحادثة.' ); } }
            }
            $uuid = (string) ( $data['client_id'] ?? '' );
            if ( ! wp_is_uuid( $uuid ) ) { throw new InvalidArgumentException( 'معرف المحاولة مطلوب.' ); }
            $data = array_merge( $data, array( 'source_type' => $type, 'source_id' => $id, 'thread_id' => $thread_id, 'owner_key' => $owner, 'owner_inbox_id' => $inbox ) );
            return array( 'id' => $this->create_internal( $data, $actor, 'manual:' . $actor['actor_key'] . ':' . $uuid ) );
        } );
    }

    private function history( $id, $version, array $actor, array $changes ) {
        Olama_Messages_Communications_DB::insert( 'action_history', array( 'action_id' => $id, 'version' => $version, 'actor_key' => $actor['actor_key'], 'authenticated_wp_user_id' => get_current_user_id(), 'change_json' => wp_json_encode( $changes ), 'created_at_utc' => gmdate( 'Y-m-d H:i:s' ) ) );
        Olama_Messages_Communications_DB::audit( 'action_changed', $id, $actor, array( 'version' => $version, 'changes' => $changes ) );
    }

    /** Event worker already holds the source event lock and its enclosing transaction. */
    public function cancel_event_target( $target_id ) {
        global $wpdb; $table = Olama_Messages_Communications_DB::table( 'action_items' );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_type='event_target' AND source_id=%d FOR UPDATE", $target_id ), ARRAY_A );
        if ( ! $row || ! in_array( $row['status'], array( 'open', 'in_progress' ), true ) ) { return; }
        $changes = array( 'status' => 'cancelled', 'version' => (int) $row['version'] + 1, 'resolution_note' => 'أغلقت الفعالية بالأرشفة أو الإلغاء أو الإزالة من مصدرها.', 'resolved_at_utc' => gmdate( 'Y-m-d H:i:s' ), 'updated_at_utc' => gmdate( 'Y-m-d H:i:s' ) );
        Olama_Messages_Suite_Policy::update( 'action_items', $changes, array( 'id' => $row['id'] ) );
        $this->history( $row['id'], $changes['version'], array( 'actor_key' => 'system:school' ), $changes );
        Olama_Messages_Activity_Service::emit( $row['owner_key'], 'action', $row['id'], $changes['version'], 'action_cancelled' );
    }

    public function change( array $actor, $id, array $data ) {
        // Source access is checked again after acquiring the action lock.
        $source = $this->get( $actor, $id );
        return Olama_Messages_Communications_DB::transaction( function () use ( $actor, $id, $data, $source ) {
            if ( $source['thread_id'] ) { ( new Olama_Messages_Chat_Service() )->get( $actor, $source['thread_id'], true ); }
            if ( 'event_target' === $source['source_type'] ) { global $wpdb; $targets = Olama_Messages_Communications_DB::table( 'event_targets' ); $event_id = $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$targets} WHERE id=%d", $source['source_id'] ) ); $event = ( new Olama_Messages_Event_Service() )->get( $event_id, true ); if ( in_array( $event['status'], array( 'cancelled', 'source_removed', 'archived' ), true ) ) { throw new RuntimeException( 'الفعالية لم تعد تقبل تغييرات الإجراءات.' ); }
                if ( 'family' === $actor['actor_type'] ) { $target = ( new Olama_Messages_Event_Service() )->target( $actor, $source['source_id'] ); if ( ! ( new Olama_Messages_Event_Service() )->eligible_target( $event, $target ) ) { throw new RuntimeException( 'سياق الطالب لم يعد مخولاً لهذا الإجراء.' ); } } }
            $row = $this->get( $actor, $id, true );
            if ( (int) ( $data['version'] ?? 0 ) !== (int) $row['version'] ) { throw new RuntimeException( 'تغير الإجراء؛ حدّث الصفحة أولاً.' ); }
            $next = $data['status'] ?? $row['status'];
            $transitions = array( 'open' => array( 'in_progress', 'resolved', 'cancelled' ), 'in_progress' => array( 'open', 'resolved', 'cancelled' ), 'resolved' => array( 'open' ), 'cancelled' => array( 'open' ) );
            if ( $next !== $row['status'] && ! in_array( $next, $transitions[$row['status']], true ) ) { throw new InvalidArgumentException( 'انتقال حالة غير صالح.' ); }
            $note = trim( (string) ( $data['resolution_note'] ?? '' ) );
            if ( mb_strlen( $note ) > 2000 || ( in_array( $next, array( 'resolved', 'cancelled' ), true ) && '' === $note ) ) { throw new InvalidArgumentException( 'أدخل ملاحظة إتمام حتى 2000 حرف.' ); }
            $changes = array( 'status' => $next, 'version' => (int) $row['version'] + 1, 'updated_at_utc' => gmdate( 'Y-m-d H:i:s' ), 'resolution_note' => $note,
                'resolved_at_utc' => in_array( $next, array( 'resolved', 'cancelled' ), true ) ? gmdate( 'Y-m-d H:i:s' ) : null );
            if ( 'in_progress' === $next && ! $row['started_at_utc'] ) { $changes['started_at_utc'] = gmdate( 'Y-m-d H:i:s' ); }
            if ( isset( $data['owner_key'] ) && $data['owner_key'] !== $row['owner_key'] ) {
                Olama_Messages_Suite_Policy::staff( $actor, 'manage_actions' );
                if ( $row['thread_id'] || 'system' !== $row['source_type'] || ! Olama_Messages_Suite_Policy::user_for_actor( $data['owner_key'], 'actions' ) ) { throw new RuntimeException( 'أعد إسناد طلبات الخدمة من صندوق الخدمة؛ مالك استجابة الجمهور ثابت.' ); }
                $changes['owner_key'] = $data['owner_key'];
            }
            if ( array_key_exists( 'due_at_utc', $data ) ) { Olama_Messages_Suite_Policy::staff( $actor, 'manage_actions' ); $changes['due_at_utc'] = Olama_Messages_Suite_Policy::utc( $data['due_at_utc'], true ); }
            Olama_Messages_Suite_Policy::update( 'action_items', $changes, array( 'id' => $id ) );
            $this->history( $id, $changes['version'], $actor, $changes );
            foreach ( array_unique( array_filter( array( $changes['owner_key'] ?? $row['owner_key'], $row['created_by_key'] ) ) ) as $key ) { Olama_Messages_Activity_Service::emit( $key, 'action', $id, $changes['version'], 'action_changed' ); }
            return $this->public_row( $actor, $this->get( $actor, $id ) );
        } );
    }

    public function listing( array $actor, $before = 0, $thread_id = 0 ) {
        global $wpdb;
        Olama_Messages_Suite_Policy::feature( $actor, 'actions' );
        if ( $thread_id ) { ( new Olama_Messages_Chat_Service() )->get( $actor, $thread_id ); }
        $a = Olama_Messages_Communications_DB::table( 'action_items' ); $m = Olama_Messages_Communications_DB::table( 'inbox_members' );
        $sql = $wpdb->prepare( "SELECT a.id FROM {$a} a WHERE (a.owner_key=%s OR a.created_by_key=%s OR EXISTS(SELECT 1 FROM {$m} m WHERE m.inbox_id=a.owner_inbox_id AND m.actor_key=%s AND m.active=1))", $actor['actor_key'], $actor['actor_key'], $actor['actor_key'] );
        if ( $before ) { $sql .= $wpdb->prepare( ' AND a.id<%d', $before ); }
        if ( $thread_id ) { $sql .= $wpdb->prepare( ' AND a.thread_id=%d', $thread_id ); }
        $ids = $wpdb->get_col( $sql . ' ORDER BY a.id DESC LIMIT 100' ); $items = array(); $cursor = 0;
        foreach ( $ids as $id ) { $cursor = (int) $id; try { $items[] = $this->public_row( $actor, $this->get( $actor, $id ) ); } catch ( RuntimeException $e ) {} if ( count( $items ) >= 30 ) { break; } }
        return array( 'items' => $items, 'next_before' => count( $ids ) === 100 || count( $items ) === 30 ? $cursor : 0 );
    }

    private function public_row( array $actor, array $row ) {
        unset( $row['request_hash'], $row['action_key'], $row['created_by_wp_user_id'] );
        if ( 'family' === $actor['actor_type'] ) {
            unset( $row['created_by_key'] );
            if ( 0 === strpos( $row['owner_key'], 'employee:' ) ) { $row['owner_key'] = ''; }
            if ( isset( $row['history'] ) ) { foreach ( $row['history'] as &$entry ) {
                unset( $entry['actor_key'] ); $change = (array) json_decode( $entry['change_json'], true );
                if ( isset( $change['owner_key'] ) && 0 === strpos( $change['owner_key'], 'employee:' ) ) { unset( $change['owner_key'] ); }
                $entry['change_json'] = wp_json_encode( $change );
            } unset( $entry ); }
        }
        return $row;
    }

    public function detail( array $actor, $id ) {
        global $wpdb; $row = $this->get( $actor, $id ); $h = Olama_Messages_Communications_DB::table( 'action_history' );
        $row['history'] = $wpdb->get_results( $wpdb->prepare( "SELECT version,actor_key,change_json,created_at_utc FROM {$h} WHERE action_id=%d ORDER BY id DESC LIMIT 50", $id ), ARRAY_A ); return $this->public_row( $actor, $row );
    }
}
