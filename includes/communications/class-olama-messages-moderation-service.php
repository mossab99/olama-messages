<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Olama_Messages_Moderation_Service {
    public function report( array $actor, $message_id, $reason ) {
        global $wpdb;
        Olama_Messages_Chat_Policy::require_use( $actor );
        $reason = Olama_Messages_Chat_Policy::plain_text( $reason, 2000 );
        return Olama_Messages_Communications_DB::transaction( function () use ( $wpdb, $actor, $message_id, $reason ) {
            $m = Olama_Messages_Communications_DB::table( 'messages' ); $r = Olama_Messages_Communications_DB::table( 'reports' );
            $thread_id = $wpdb->get_var( $wpdb->prepare( "SELECT thread_id FROM {$m} WHERE id=%d", $message_id ) );
            ( new Olama_Messages_Chat_Service() )->get( $actor, $thread_id, true );
            $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$r} WHERE message_id=%d AND reporter_key=%s FOR UPDATE", $message_id, $actor['actor_key'] ) );
            if ( $existing ) { return array( 'id' => (int) $existing ); }
            Olama_Messages_Chat_Policy::rate( $actor, 'report' );
            $now = gmdate( 'Y-m-d H:i:s' );
            $id = Olama_Messages_Communications_DB::insert( 'reports', array( 'message_id' => $message_id, 'thread_id' => $thread_id, 'reporter_key' => $actor['actor_key'], 'reason' => $reason, 'resolution_note' => '', 'created_at_utc' => $now, 'updated_at_utc' => $now ) );
            Olama_Messages_Communications_DB::audit( 'message_reported', $id, $actor, array( 'message_id' => $message_id ) );
            return array( 'id' => $id );
        } );
    }

    public function queue( array $actor, $before = 0, $status = 'open' ) {
        global $wpdb;
        Olama_Messages_Chat_Policy::require_staff( $actor, 'moderate' );
        if ( ! in_array( $status, array( 'open', 'reviewed', 'actioned', 'dismissed' ), true ) ) { throw new InvalidArgumentException( 'حالة البلاغ غير صالحة.' ); }
        $r = Olama_Messages_Communications_DB::table( 'reports' );
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$r} WHERE status=%s AND (%d=0 OR id<%d) ORDER BY id DESC LIMIT 30", $status, $before, $before ), ARRAY_A );
    }

    private function reported_thread( $report_id ) {
        global $wpdb; $r = Olama_Messages_Communications_DB::table( 'reports' );
        $id = $wpdb->get_var( $wpdb->prepare( "SELECT thread_id FROM {$r} WHERE id=%d", $report_id ) );
        if ( ! $id ) { throw new RuntimeException( 'البلاغ غير متاح.' ); } return (int) $id;
    }

    /** Only dedicated audit can open an arbitrary thread; moderators must supply an existing report. */
    public function context( array $actor, $thread_id, $report_id, $reason, $before = 0 ) {
        global $wpdb;
        $reason = Olama_Messages_Chat_Policy::plain_text( $reason, 1000 );
        if ( $report_id ) { Olama_Messages_Chat_Policy::require_staff( $actor, 'moderate' ); $thread_id = $this->reported_thread( $report_id ); }
        else { Olama_Messages_Chat_Policy::require_staff( $actor, 'audit' ); }
        return Olama_Messages_Communications_DB::transaction( function () use ( $wpdb, $actor, $thread_id, $report_id, $reason, $before ) {
            $t = Olama_Messages_Communications_DB::table( 'threads' ); $m = Olama_Messages_Communications_DB::table( 'messages' ); $v = Olama_Messages_Communications_DB::table( 'message_revisions' );
            $thread = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id=%d", $thread_id ), ARRAY_A );
            if ( ! $thread ) { throw new RuntimeException( 'المحادثة غير متاحة.' ); }
            // Audit must commit with every privileged content read; no content returned on audit failure.
            Olama_Messages_Communications_DB::audit( 'privileged_content_access', $thread_id, $actor, array( 'report_id' => $report_id, 'reason' => $reason ) );
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$m} WHERE thread_id=%d AND (%d=0 OR id<%d) ORDER BY id DESC LIMIT 50", $thread_id, $before, $before ), ARRAY_A );
            // Revisions have a separate bounded, audited endpoint to avoid an unbounded response.
            return array( 'thread' => $thread, 'messages' => $rows, 'next' => count( $rows ) === 50 ? (int) end( $rows )['id'] : 0 );
        } );
    }

    public function revisions( array $actor, $message_id, $report_id, $reason, $before = 0 ) {
        global $wpdb;
        Olama_Messages_Chat_Policy::require_staff( $actor, $report_id ? 'moderate' : 'audit' );
        $reason = Olama_Messages_Chat_Policy::plain_text( $reason, 1000 );
        $m = Olama_Messages_Communications_DB::table( 'messages' );
        $thread_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT thread_id FROM {$m} WHERE id=%d", $message_id ) );
        if ( ! $thread_id || ( $report_id && $thread_id !== $this->reported_thread( $report_id ) ) ) { throw new RuntimeException( 'السجل خارج نطاق البلاغ.' ); }
        return Olama_Messages_Communications_DB::transaction( function () use ( $wpdb, $actor, $message_id, $report_id, $reason, $before ) {
            Olama_Messages_Communications_DB::audit( 'message_revisions_accessed', $message_id, $actor, array( 'report_id' => $report_id, 'reason' => $reason ) );
            $v = Olama_Messages_Communications_DB::table( 'message_revisions' );
            return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$v} WHERE message_id=%d AND (%d=0 OR id<%d) ORDER BY id DESC LIMIT 30", $message_id, $before, $before ), ARRAY_A );
        } );
    }

    public function review( array $actor, $id, array $data ) {
        global $wpdb;
        Olama_Messages_Chat_Policy::require_staff( $actor, 'moderate' );
        $status = $data['status'] ?? '';
        if ( ! in_array( $status, array( 'reviewed', 'actioned', 'dismissed' ), true ) ) { throw new InvalidArgumentException( 'حالة غير صالحة.' ); }
        $note = Olama_Messages_Chat_Policy::plain_text( $data['note'] ?? '', 2000 );
        return Olama_Messages_Communications_DB::transaction( function () use ( $wpdb, $actor, $id, $status, $note ) {
            $this->reported_thread( $id );
            $r = Olama_Messages_Communications_DB::table( 'reports' );
            Olama_Messages_Communications_DB::query( $wpdb->prepare( "UPDATE {$r} SET status=%s,resolution_note=%s,updated_at_utc=%s WHERE id=%d", $status, $note, gmdate( 'Y-m-d H:i:s' ), $id ) );
            Olama_Messages_Communications_DB::audit( 'report_' . $status, $id, $actor ); return array( 'ok' => true );
        } );
    }

    public function redact( array $actor, $message_id, array $data ) {
        global $wpdb;
        Olama_Messages_Chat_Policy::require_staff( $actor, 'moderate' );
        $reason = Olama_Messages_Chat_Policy::plain_text( $data['reason'] ?? '', 1000 );
        $reported_thread = $this->reported_thread( absint( $data['report_id'] ?? 0 ) );
        return Olama_Messages_Communications_DB::transaction( function () use ( $wpdb, $actor, $message_id, $reason, $reported_thread ) {
            $m = Olama_Messages_Communications_DB::table( 'messages' ); $t = Olama_Messages_Communications_DB::table( 'threads' );
            $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE id=%d FOR UPDATE", $reported_thread ) );
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$m} WHERE id=%d AND thread_id=%d FOR UPDATE", $message_id, $reported_thread ), ARRAY_A );
            if ( ! $row ) { throw new RuntimeException( 'الرسالة خارج نطاق البلاغ.' ); }
            if ( ! $row['redacted_at_utc'] ) {
                Olama_Messages_Communications_DB::insert( 'message_revisions', array( 'message_id' => $message_id, 'body' => $row['body'], 'actor_key' => $actor['actor_key'], 'authenticated_wp_user_id' => get_current_user_id(), 'created_at_utc' => gmdate( 'Y-m-d H:i:s' ) ) );
                Olama_Messages_Communications_DB::query( $wpdb->prepare( "UPDATE {$m} SET body='',redacted_at_utc=%s WHERE id=%d", gmdate( 'Y-m-d H:i:s' ), $message_id ) );
                Olama_Messages_Communications_DB::audit( 'message_redacted', $message_id, $actor, array( 'reason' => $reason ) );
                ( new Olama_Messages_Chat_Service() )->change( $reported_thread, 'redacted' );
            }
            return array( 'ok' => true );
        } );
    }

    public function restrictions( array $actor, $before = 0 ) {
        global $wpdb;
        Olama_Messages_Chat_Policy::require_staff( $actor, 'moderate' );
        $r = Olama_Messages_Communications_DB::table( 'restrictions' );
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$r} WHERE (%d=0 OR id<%d) ORDER BY id DESC LIMIT 30", $before, $before ), ARRAY_A );
    }

    public function restrict( array $actor, array $data ) {
        Olama_Messages_Chat_Policy::require_staff( $actor, 'moderate' );
        $type = $data['restriction_type'] ?? ''; $scope = $data['scope_type'] ?? ''; $key = (string) ( $data['actor_key'] ?? '' );
        if ( ! in_array( $type, array( 'send_block', 'attachment_block', 'new_thread_block', 'target_block', 'chat_block' ), true ) || ! in_array( $scope, array( 'global', 'actor', 'service_inbox', 'thread' ), true ) || ( '*' !== $key && ! preg_match( '/^(family|employee):[^\s]{1,170}$/u', $key ) ) ) { throw new InvalidArgumentException( 'تعريف القيد غير صالح.' ); }
        $target = (string) ( $data['target_key'] ?? '' );
        if ( ( 'target_block' === $type && ! $target ) || ( $target && ! preg_match( '/^(family|employee|service_inbox):[^\s]{1,170}$/u', $target ) ) ) { throw new InvalidArgumentException( 'الجهة المستهدفة غير صالحة.' ); }
        $scope_key = 'global' === $scope ? '' : (string) ( $data['scope_key'] ?? '' );
        if ( ( 'actor' === $scope && ! preg_match( '/^(family|employee):[^\s]{1,170}$/u', $scope_key ) ) || ( in_array( $scope, array( 'thread', 'service_inbox' ), true ) && ! preg_match( '/^[1-9][0-9]*$/', $scope_key ) ) ) { throw new InvalidArgumentException( 'نطاق القيد غير صالح.' ); }
        $start = $data['starts_at_utc'] ?? gmdate( 'Y-m-d H:i:s' ); $end = $data['ends_at_utc'] ?? null;
        foreach ( array_filter( array( $start, $end ) ) as $value ) {
            $parsed = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
            if ( ! $parsed || $parsed->format( 'Y-m-d H:i:s' ) !== $value ) { throw new InvalidArgumentException( 'موعد UTC غير صالح.' ); }
        }
        if ( ! $start || ( $end && $end <= $start ) ) { throw new InvalidArgumentException( 'نهاية القيد يجب أن تلي بدايته.' ); }
        $reason = Olama_Messages_Chat_Policy::plain_text( $data['private_reason'] ?? '', 2000 );
        return Olama_Messages_Communications_DB::transaction( function () use ( $actor, $type, $scope, $key, $target, $scope_key, $start, $end, $reason ) {
            $id = Olama_Messages_Communications_DB::insert( 'restrictions', array( 'actor_key' => $key, 'restriction_type' => $type, 'scope_type' => $scope, 'scope_key' => $scope_key, 'target_key' => $target, 'private_reason' => $reason, 'starts_at_utc' => $start, 'ends_at_utc' => $end ?: null ) );
            Olama_Messages_Communications_DB::audit( 'restriction_created', $id, $actor, array( 'subject_actor' => $key, 'type' => $type ) ); return array( 'id' => $id );
        } );
    }

    public function revoke( array $actor, $id ) {
        global $wpdb;
        Olama_Messages_Chat_Policy::require_staff( $actor, 'moderate' );
        return Olama_Messages_Communications_DB::transaction( function () use ( $wpdb, $actor, $id ) {
            $r = Olama_Messages_Communications_DB::table( 'restrictions' );
            $changed = Olama_Messages_Communications_DB::query( $wpdb->prepare( "UPDATE {$r} SET revoked_at_utc=%s WHERE id=%d AND revoked_at_utc IS NULL", gmdate( 'Y-m-d H:i:s' ), $id ) );
            if ( $changed ) { Olama_Messages_Communications_DB::audit( 'restriction_revoked', $id, $actor ); }
            return array( 'ok' => true );
        } );
    }
}
