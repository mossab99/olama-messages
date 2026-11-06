<?php
/** Read-only operational view of a sending agent. Never changes dispatch state. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Olama_Messages_Agent_Dashboard {
    public static function utc_timestamp( $value ) {
        if ( ! $value ) { return 0; }
        try { return ( new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) ) )->getTimestamp(); }
        catch ( Exception $error ) { return 0; }
    }
    public static function time_label( $value, $utc = true ) {
        if ( ! $value ) { return '—'; }
        try {
            $date = new DateTimeImmutable( $value, $utc ? new DateTimeZone( 'UTC' ) : wp_timezone() );
            return wp_date( 'j M Y, H:i:s', $date->getTimestamp(), wp_timezone() );
        } catch ( Exception $error ) { return '—'; }
    }
    public static function age_label( $timestamp ) {
        if ( ! $timestamp ) { return __( 'Not reported yet', 'olama-messages' ); }
        return sprintf( __( '%s ago', 'olama-messages' ), human_time_diff( min( time(), $timestamp ), time() ) );
    }
    public static function health( array $agent, array $work, $now = null ) {
        $now = $now ?? time();
        $heartbeat = (array) ( $agent['heartbeat'] ?? array() );
        $seen = self::utc_timestamp( $agent['last_seen_at'] ?? '' );
        $poll = self::utc_timestamp( $heartbeat['dispatcher_last_poll_at'] ?? '' );
        $progress = max( $poll, (int) ( $work['progress_at'] ?? 0 ) );
        if ( 'revoked' === $agent['status'] || ! empty( $agent['revoked_at'] ) ) { return array( 'key' => 'revoked', 'tone' => 'muted', 'label' => __( 'Access revoked', 'olama-messages' ), 'description' => __( 'This agent cannot authenticate. Register an agent to restore access.', 'olama-messages' ) ); }
        if ( ! $seen || $now - $seen > 300 ) { return array( 'key' => 'offline', 'tone' => 'muted', 'label' => __( 'Agent offline', 'olama-messages' ), 'description' => __( 'No recent heartbeat. Open the Windows agent on the sending computer.', 'olama-messages' ) ); }
        if ( empty( $agent['kde_cli_found'] ) ) { return array( 'key' => 'cli_missing', 'tone' => 'danger', 'label' => __( 'KDE Connect missing', 'olama-messages' ), 'description' => __( 'Check the KDE Connect CLI path in the Windows agent settings.', 'olama-messages' ) ); }
        if ( empty( $agent['kde_device_reachable'] ) ) { return array( 'key' => 'phone_offline', 'tone' => 'warning', 'label' => __( 'Phone disconnected', 'olama-messages' ), 'description' => __( 'Reconnect the selected phone in KDE Connect. Queued messages are preserved.', 'olama-messages' ) ); }
        if ( ! isset( $heartbeat['dispatcher_enabled'] ) ) { return array( 'key' => 'not_reported', 'tone' => 'warning', 'label' => __( 'Waiting for worker status', 'olama-messages' ), 'description' => __( 'The agent is connected but has not reported its sending worker state.', 'olama-messages' ) ); }
        if ( ! $heartbeat['dispatcher_enabled'] ) { return array( 'key' => 'disabled', 'tone' => 'muted', 'label' => __( 'Sending disabled', 'olama-messages' ), 'description' => __( 'Enable the dispatcher from the Windows tray menu to accept messages.', 'olama-messages' ) ); }
        if ( 2 !== (int) ( $heartbeat['dispatch_protocol_version'] ?? 0 ) ) { return array( 'key' => 'upgrade', 'tone' => 'warning', 'label' => __( 'Agent update needed', 'olama-messages' ), 'description' => __( 'Update the Windows agent to use the current sending protocol.', 'olama-messages' ) ); }
        if ( ! $progress || $now - $progress > 90 ) { return array( 'key' => 'stalled', 'tone' => 'warning', 'label' => __( 'Worker not progressing', 'olama-messages' ), 'description' => __( 'The phone is connected, but the worker has not progressed recently. Check the Windows agent.', 'olama-messages' ) ); }
        if ( ! empty( $work['active'] ) ) { return array( 'key' => 'sending', 'tone' => 'success', 'label' => __( 'Sending', 'olama-messages' ), 'description' => __( 'A message is being submitted or its result is being recorded.', 'olama-messages' ) ); }
        if ( 'Error' === ( $heartbeat['dispatcher_state'] ?? '' ) && $now - (int) ( $work['progress_at'] ?? 0 ) > 30 ) {
            return array( 'key' => 'attention', 'tone' => 'warning', 'label' => __( 'Worker needs attention', 'olama-messages' ), 'description' => (string) ( $heartbeat['dispatcher_last_error'] ?? '' ) ?: __( 'Open the Windows agent to inspect its last error.', 'olama-messages' ) );
        }
        if ( 'paused' === ( $work['campaign']['status'] ?? '' ) ) { return array( 'key' => 'paused', 'tone' => 'muted', 'label' => __( 'Campaign paused', 'olama-messages' ), 'description' => __( 'The agent is available. This campaign will continue when resumed.', 'olama-messages' ) ); }
        if ( 'sending' === ( $work['campaign']['status'] ?? '' ) && (int) ( $work['pending'] ?? 0 ) > 0 ) { return array( 'key' => 'waiting', 'tone' => 'success', 'label' => __( 'Sending campaign', 'olama-messages' ), 'description' => __( 'Waiting for the next submission window. Messages are processed one at a time.', 'olama-messages' ) ); }
        return array( 'key' => 'ready', 'tone' => 'success', 'label' => __( 'Ready to send', 'olama-messages' ), 'description' => __( 'Agent and phone are connected. No active message is waiting for this worker.', 'olama-messages' ) );
    }
    public function listing() {
        global $wpdb;
        $agents = Olama_Messages_Plugin::instance()->agents()->list_agents();
        if ( ! $agents ) { return array(); }
        $ids = implode( ',', array_map( 'intval', array_column( $agents, 'id' ) ) );
        $q = $wpdb->prefix . 'olama_msg_queue'; $c = $wpdb->prefix . 'olama_msg_campaigns';
        $progress = array();
        foreach ( (array) $wpdb->get_results( "SELECT reserved_by_agent_id,MAX(reserved_at) AS reserved_at,MAX(sent_at) AS sent_at,
            SUM(status='reserved') AS active,COALESCE(MAX(CASE WHEN status='reserved' THEN id END),MAX(id)) AS latest_id
            FROM {$q} WHERE reserved_by_agent_id IN ({$ids}) GROUP BY reserved_by_agent_id", ARRAY_A ) as $row ) { $progress[(int) $row['reserved_by_agent_id']] = $row; }
        $last_ids = array_filter( array_map( 'intval', array_column( $progress, 'latest_id' ) ) );
        $campaigns = array();
        if ( $last_ids ) { foreach ( (array) $wpdb->get_results( "SELECT q.id,c.id AS campaign_id,c.status FROM {$q} q LEFT JOIN {$c} c ON c.id=q.campaign_id WHERE q.id IN (" . implode( ',', $last_ids ) . ')', ARRAY_A ) as $row ) { $campaigns[(int) $row['id']] = $row; } }
        $items = array();
        foreach ( $agents as $agent ) {
            $p = $progress[$agent['id']] ?? array(); $campaign = $campaigns[$p['latest_id'] ?? 0] ?? null;
            $work = array( 'active' => ! empty( $p['active'] ), 'progress_at' => max( self::utc_timestamp( $p['reserved_at'] ?? '' ), self::utc_timestamp( $p['sent_at'] ?? '' ) ),
                'campaign' => $campaign, 'pending' => 'sending' === ( $campaign['status'] ?? '' ) ? 1 : 0 );
            $safe = array_intersect_key( $agent, array_flip( array( 'id','agent_name','machine_name','kde_device_name','kde_device_reachable','last_seen_at' ) ) );
            $items[] = array( 'agent' => $safe, 'health' => self::health( $agent, $work ) );
        }
        return $items;
    }
    public function snapshot( int $agent_id, bool $include_events = true ) {
        global $wpdb;
        $agent = Olama_Messages_Plugin::instance()->agents()->get_agent( $agent_id );
        if ( ! $agent ) { return null; }
        $q = $wpdb->prefix . 'olama_msg_queue'; $c = $wpdb->prefix . 'olama_msg_campaigns';
        $r = $wpdb->prefix . 'olama_msg_campaign_recipients'; $a = $wpdb->prefix . 'olama_msg_dispatch_attempts';
        $latest = $wpdb->get_row( $wpdb->prepare( "SELECT q.*, c.title AS campaign_title, c.status AS campaign_status FROM {$q} q
            LEFT JOIN {$c} c ON c.id=q.campaign_id WHERE q.reserved_by_agent_id=%d ORDER BY q.reserved_at DESC, q.id DESC LIMIT 1", $agent_id ), ARRAY_A );
        $active = $wpdb->get_row( $wpdb->prepare( "SELECT id, campaign_id FROM {$q} WHERE reserved_by_agent_id=%d AND status='reserved' ORDER BY reserved_at DESC LIMIT 1", $agent_id ), ARRAY_A );
        if ( $active && ( ! $latest || (int) $active['campaign_id'] !== (int) $latest['campaign_id'] ) ) {
            $latest = $wpdb->get_row( $wpdb->prepare( "SELECT q.*,c.title AS campaign_title,c.status AS campaign_status FROM {$q} q LEFT JOIN {$c} c ON c.id=q.campaign_id WHERE q.id=%d", $active['id'] ), ARRAY_A );
        }
        $counts = array_fill_keys( array( 'prepared', 'reserved', 'retry_wait', 'sent', 'failed', 'unknown', 'cancelled' ), 0 );
        $campaign = null;
        if ( $latest ) {
            foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT status,COUNT(*) AS total FROM {$q} WHERE campaign_id=%d GROUP BY status", $latest['campaign_id'] ), ARRAY_A ) as $row ) { $counts[$row['status']] = (int) $row['total']; }
            $campaign = array( 'id' => (int) $latest['campaign_id'], 'title' => $latest['campaign_title'], 'status' => $latest['campaign_status'] );
        }
        $last_sent = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(sent_at) FROM {$q} WHERE reserved_by_agent_id=%d AND status='sent'", $agent_id ) );
        $review = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$q} WHERE reserved_by_agent_id=%d AND status='unknown'", $agent_id ) );
        $times = $wpdb->get_col( $wpdb->prepare( "SELECT sent_at FROM {$q} WHERE reserved_by_agent_id=%d AND status='sent' AND sent_at IS NOT NULL ORDER BY sent_at DESC LIMIT 20", $agent_id ) );
        $gaps = array();
        for ( $i = 1; $i < count( $times ); $i++ ) { $gap = self::utc_timestamp( $times[$i-1] ) - self::utc_timestamp( $times[$i] ); if ( $gap > 0 ) { $gaps[] = $gap; } }
        sort( $gaps ); $median = $gaps ? $gaps[(int) floor( count( $gaps ) / 2 )] : null;
        $work = array( 'active' => $active, 'campaign' => $campaign, 'pending' => $counts['prepared'] + $counts['retry_wait'],
            'progress_at' => max( self::utc_timestamp( $latest['reserved_at'] ?? '' ), self::utc_timestamp( $last_sent ) ) );
        $attempts = $wpdb->get_results( $wpdb->prepare( "SELECT a.attempt_id,a.queue_id,a.status,a.created_at,a.updated_at,
            q.phone_e164,q.last_error_message,q.dispatch_attempt_id,r.recipient_name,c.title AS campaign_title,q.campaign_id
            FROM {$a} a LEFT JOIN {$q} q ON q.id=a.queue_id LEFT JOIN {$r} r ON r.id=q.campaign_recipient_id
            LEFT JOIN {$c} c ON c.id=q.campaign_id WHERE a.agent_id=%d ORDER BY a.created_at DESC,a.attempt_id DESC LIMIT 50", $agent_id ), ARRAY_A );
        $events = $include_events ? Olama_Messages_Plugin::instance()->agents()->get_events( $agent['agent_uuid'], 20 ) : array();
        // Return only presentation fields. Credential hashes and raw heartbeat JSON
        // must never be serialized into the dashboard response.
        $safe = array_intersect_key( $agent, array_flip( array( 'id','agent_name','agent_uuid','status','revoked_at','platform','app_version','machine_name','windows_user','kde_cli_path','kde_cli_found','kde_device_id','kde_device_name','kde_device_reachable','last_seen_at','created_at' ) ) );
        return array( 'agent' => $safe, 'health' => self::health( $agent, $work ), 'campaign' => $campaign, 'counts' => $counts,
            'active' => $active, 'pending' => $work['pending'], 'review' => $review, 'last_sent' => $last_sent,
            'last_poll' => $agent['heartbeat']['dispatcher_last_poll_at'] ?? '', 'worker_state' => $agent['heartbeat']['dispatcher_state'] ?? '',
            'last_error' => $agent['heartbeat']['dispatcher_last_error'] ?? '', 'median' => $median, 'sample_count' => count( $gaps ),
            'target' => Olama_Messages_Dispatcher_Service::config()['min_seconds_between_sms'], 'attempts' => (array) $attempts, 'events' => (array) $events );
    }
}
