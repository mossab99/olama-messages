<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Olama_Messages_Service_Inbox_Service {
    public function get( $id, $lock = false ) {
        global $wpdb;
        $table = Olama_Messages_Communications_DB::table( 'service_inboxes' );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d" . ( $lock ? ' FOR UPDATE' : '' ), $id ), ARRAY_A );
        if ( ! $row ) { throw new RuntimeException( 'صندوق الخدمة غير متاح.' ); }
        return $row;
    }

    public function member( $id, array $actor, $lock = false ) {
        global $wpdb;
        if ( 'administrator' === $actor['actor_type'] ) { return array( 'actor_key' => $actor['actor_key'], 'active' => 1, 'is_manager' => 1 ); }
        if ( 'employee' !== $actor['actor_type'] || ! Olama_Messages_Communication_Policy::can( 'olama_messages_service_inbox' ) ) { return null; }
        $table = Olama_Messages_Communications_DB::table( 'inbox_members' );
        $member = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE inbox_id=%d AND actor_key=%s AND active=1" . ( $lock ? ' FOR UPDATE' : '' ), $id, $actor['actor_key'] ), ARRAY_A );
        if ( $member ) { $member['is_manager'] = $member['is_manager'] && Olama_Messages_Communication_Policy::can( 'olama_messages_manage_inboxes' ); }
        return $member;
    }

    /** Shared by content, lists, change feed and receipts. Aliases t and p are caller-owned. */
    public function access_sql( array $actor ) {
        global $wpdb;
        $requester = $wpdb->prepare( 't.requester_key=%s', $actor['actor_key'] );
        if ( 'administrator' === $actor['actor_type'] ) { return '1=1'; }
        if ( 'employee' !== $actor['actor_type'] || ! Olama_Messages_Communication_Policy::can( 'olama_messages_service_inbox' ) ) { return $requester; }
        $members = Olama_Messages_Communications_DB::table( 'inbox_members' );
        $inboxes = Olama_Messages_Communications_DB::table( 'service_inboxes' );
        $manager = Olama_Messages_Communication_Policy::can( 'olama_messages_manage_inboxes' ) ? 'im.is_manager=1' : '0=1';
        return '(' . $requester . $wpdb->prepare( " OR EXISTS (SELECT 1 FROM {$members} im INNER JOIN {$inboxes} si ON si.id=im.inbox_id WHERE im.inbox_id=t.inbox_id AND im.actor_key=%s AND im.active=1 AND (
            {$manager} OR si.history_policy='all_history' OR t.status IN ('open','in_progress')
            OR (si.history_policy IN ('active_only','active_and_recent') AND t.assignee_key=%s)
            OR (si.history_policy='active_and_recent' AND p.participated_at_utc IS NOT NULL AND t.resolved_at_utc>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL si.recent_days DAY))
        )))", $actor['actor_key'], $actor['actor_key'] );
    }

    public function require_contact( $id, array $actor, $lock = false ) {
        $inbox = $this->get( $id, $lock );
        if ( 'administrator' === $actor['actor_type'] && $inbox['active'] ) { return $inbox; }
        $teacher = 'employee' === $actor['actor_type'] ? ( new Olama_Messages_Relationship_Provider() )->employee( $actor['actor_key'] ) : null;
        $allowed = ( 'family' === $actor['actor_type'] && $inbox['allow_family'] ) || ( $teacher && $teacher['teacher'] && 'valid' === $teacher['source']['status'] && $inbox['allow_teacher'] );
        if ( ! $inbox['active'] || ! $allowed || ! ( new Olama_Messages_Actor_Resolver() )->eligible( $actor['actor_key'] ) ) { throw new RuntimeException( 'لا يمكن بدء طلب لهذا الصندوق.' ); }
        return $inbox;
    }

    /** Current locking reads prevent a pre-lock InnoDB snapshot retaining revoked membership. */
    public function check_access( array $thread, array $actor, $lock = false ) {
        global $wpdb;
        if ( $thread['requester_key'] === $actor['actor_key'] ) { return; }
        $inbox = $this->get( $thread['inbox_id'], $lock );
        $member = $this->member( $thread['inbox_id'], $actor, $lock );
        if ( $member ) {
            if ( $member['is_manager'] || 'all_history' === $inbox['history_policy'] || in_array( $thread['status'], array( 'open', 'in_progress' ), true ) ) { return; }
            if ( in_array( $inbox['history_policy'], array( 'active_only', 'active_and_recent' ), true ) && $thread['assignee_key'] === $actor['actor_key'] ) { return; }
            if ( 'active_and_recent' === $inbox['history_policy'] && $thread['resolved_at_utc'] && strtotime( $thread['resolved_at_utc'] . ' UTC' ) >= time() - (int) $inbox['recent_days'] * DAY_IN_SECONDS ) {
                $p = Olama_Messages_Communications_DB::table( 'thread_participants' );
                if ( $wpdb->get_var( $wpdb->prepare( "SELECT participated_at_utc FROM {$p} WHERE thread_id=%d AND actor_key=%s" . ( $lock ? ' FOR UPDATE' : '' ), $thread['id'], $actor['actor_key'] ) ) ) { return; }
            }
        }
        throw new RuntimeException( 'لم يعد سجل الطلب متاحاً لهذه العضوية.' );
    }

    public function listing( array $actor, $admin = false, $before = 0 ) {
        global $wpdb;
        Olama_Messages_Chat_Policy::require_use( $actor );
        if ( $admin ) { Olama_Messages_Chat_Policy::require_staff( $actor, 'manage_inboxes' ); }
        $table = Olama_Messages_Communications_DB::table( 'service_inboxes' );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE (%d=0 OR id<%d) ORDER BY id DESC LIMIT 50", $before, $before ), ARRAY_A );
        $result = array();
        foreach ( $rows as $row ) {
            if ( $admin ) {
                $m = Olama_Messages_Communications_DB::table( 'inbox_members' );
                $row['members'] = $wpdb->get_results( $wpdb->prepare( "SELECT actor_key,active,is_manager FROM {$m} WHERE inbox_id=%d ORDER BY id", $row['id'] ), ARRAY_A );
                $result[] = $row; continue;
            }
            $contact = false;
            try { $this->require_contact( $row['id'], $actor ); $contact = true; } catch ( Throwable $error ) { /* Not an eligible requester. */ }
            if ( $contact || $this->member( $row['id'], $actor ) ) { $result[] = array( 'id' => $row['id'], 'name' => $row['name'], 'can_contact' => $contact ); }
        }
        return array( 'items' => $result, 'next' => count( $rows ) === 50 ? (int) end( $rows )['id'] : 0 );
    }

    public function save( array $actor, array $data, $id = 0 ) {
        global $wpdb;
        Olama_Messages_Chat_Policy::require_staff( $actor, 'manage_inboxes' );
        $name = Olama_Messages_Chat_Policy::plain_text( $data['name'] ?? '', 190 );
        $policy = $data['history_policy'] ?? 'active_and_recent';
        if ( ! in_array( $policy, array( 'active_only', 'active_and_recent', 'all_history', 'manager_history_only' ), true ) ) { throw new InvalidArgumentException( 'سياسة السجل غير صالحة.' ); }
        $members = (array) ( $data['members'] ?? array() );
        if ( count( $members ) > 100 ) { throw new InvalidArgumentException( 'الحد الأقصى 100 عضو.' ); }
        foreach ( $members as $member ) {
            if ( ! is_array( $member ) || ! ( new Olama_Messages_Relationship_Provider() )->employee( $member['actor_key'] ?? '' ) ) { throw new InvalidArgumentException( 'عضو دون هوية موظف فعالة.' ); }
        }
        return Olama_Messages_Communications_DB::transaction( function () use ( $wpdb, $actor, $data, $id, $name, $policy, $members ) {
            if ( $id ) { $this->get( $id, true ); }
            $fields = array( 'name' => $name, 'active' => empty( $data['active'] ) ? 0 : 1, 'allow_family' => empty( $data['allow_family'] ) ? 0 : 1, 'allow_teacher' => empty( $data['allow_teacher'] ) ? 0 : 1, 'show_employee' => empty( $data['show_employee'] ) ? 0 : 1, 'history_policy' => $policy, 'recent_days' => max( 1, min( 365, absint( $data['recent_days'] ?? 30 ) ) ) );
            $fields['response_sla_minutes'] = max( 0, min( 43200, absint( $data['response_sla_minutes'] ?? 1440 ) ) );
            $fields['resolution_sla_minutes'] = max( 0, min( 129600, absint( $data['resolution_sla_minutes'] ?? 4320 ) ) );
            if ( $id ) {
                if ( false === $wpdb->update( Olama_Messages_Communications_DB::table( 'service_inboxes' ), $fields, array( 'id' => $id ) ) ) { throw new RuntimeException( 'تعذر حفظ الصندوق.' ); }
            } else { $fields['created_at_utc'] = gmdate( 'Y-m-d H:i:s' ); $id = Olama_Messages_Communications_DB::insert( 'service_inboxes', $fields ); }
            $table = Olama_Messages_Communications_DB::table( 'inbox_members' );
            Olama_Messages_Communications_DB::query( $wpdb->prepare( "UPDATE {$table} SET active=0 WHERE inbox_id=%d", $id ) );
            foreach ( $members as $member ) {
                Olama_Messages_Communications_DB::query( $wpdb->prepare( "INSERT INTO {$table} (inbox_id,actor_key,active,is_manager) VALUES (%d,%s,%d,%d) ON DUPLICATE KEY UPDATE active=VALUES(active),is_manager=VALUES(is_manager)", $id, $member['actor_key'], empty( $member['active'] ) ? 0 : 1, empty( $member['is_manager'] ) ? 0 : 1 ) );
            }
            Olama_Messages_Communications_DB::audit( 'inbox_configured', $id, $actor, array( 'settings' => $fields, 'members' => $members ) );
            return array( 'id' => $id );
        } );
    }

    public function action( array $actor, $id, $command, $target = '' ) {
        global $wpdb;
        Olama_Messages_Chat_Policy::require_use( $actor );
        return Olama_Messages_Communications_DB::transaction( function () use ( $wpdb, $actor, $id, $command, $target ) {
            $chat = new Olama_Messages_Chat_Service();
            $thread = $chat->get( $actor, $id, true );
            if ( 'service' !== $thread['kind'] ) { throw new RuntimeException( 'الإجراء لصناديق الخدمة فقط.' ); }
            $inbox = $this->get( $thread['inbox_id'], true );
            $member = $this->member( $thread['inbox_id'], $actor, true );
            Olama_Messages_Chat_Policy::restrictions( $actor, 'service_action', $thread, $thread['requester_key'] );
            if ( ! $member || ! $inbox['active'] ) { throw new RuntimeException( 'يتطلب هذا الإجراء عضوية فعالة.' ); }
            $fields = array();
            if ( 'assign' === $command ) {
                if ( ! $member['is_manager'] && ( $target !== $actor['actor_key'] || ( $thread['assignee_key'] && $thread['assignee_key'] !== $actor['actor_key'] ) ) ) { throw new RuntimeException( 'إعادة التعيين تتطلب مدير الصندوق.' ); }
                $m = Olama_Messages_Communications_DB::table( 'inbox_members' );
                if ( $target && ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$m} WHERE inbox_id=%d AND actor_key=%s AND active=1", $inbox['id'], $target ) ) ) { throw new RuntimeException( 'الموظف ليس عضواً فعالاً.' ); }
                $fields['assignee_key'] = $target;
            } elseif ( in_array( $command, array( 'resolve', 'reopen', 'start' ), true ) ) {
                if ( ! $member['is_manager'] && $thread['assignee_key'] !== $actor['actor_key'] ) { throw new RuntimeException( 'الإجراء للمسؤول عن الطلب أو مدير الصندوق.' ); }
                $fields['status'] = 'resolve' === $command ? 'resolved' : ( 'start' === $command ? 'in_progress' : 'open' );
                if ( 'reopen' === $command && 'resolved' === $thread['status'] ) { $fields['service_cycle'] = (int) $thread['service_cycle'] + 1; $fields['service_cycle_started_at_utc'] = gmdate( 'Y-m-d H:i:s' ); $fields['department_responded_at_utc'] = null; }
                $fields['resolved_at_utc'] = 'resolve' === $command ? gmdate( 'Y-m-d H:i:s' ) : null;
            } else { throw new InvalidArgumentException( 'إجراء غير صالح.' ); }
            if ( false === $wpdb->update( Olama_Messages_Communications_DB::table( 'threads' ), $fields, array( 'id' => $id ) ) ) { throw new RuntimeException( 'تعذر حفظ الإجراء.' ); }
            Olama_Messages_Communications_DB::insert( 'thread_actions', array( 'thread_id' => $id, 'action' => $command, 'actor_key' => $actor['actor_key'], 'authenticated_wp_user_id' => get_current_user_id(), 'target_key' => $target, 'created_at_utc' => gmdate( 'Y-m-d H:i:s' ) ) );
            Olama_Messages_Communications_DB::audit( 'service_' . $command, $id, $actor, array( 'previous_assignee' => $thread['assignee_key'], 'target' => $target ) );
            $chat->change( $id, $command );
            return array( 'ok' => true );
        } );
    }
}
