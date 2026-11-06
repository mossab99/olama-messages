<?php
/** Durable, sequential SMS dispatch with attempt identities and conservative recovery. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Olama_Messages_Dispatcher_Service {
    private $campaigns;
    private $queue;
    private $attempts;
    private $devices;
    private $transactional_tables_checked = false;
    public function __construct() {
        global $wpdb;
        $this->campaigns = $wpdb->prefix . 'olama_msg_campaigns';
        $this->queue = $wpdb->prefix . 'olama_msg_queue';
        $this->attempts = $wpdb->prefix . 'olama_msg_dispatch_attempts';
        $this->devices = $wpdb->prefix . 'olama_msg_dispatch_devices';
    }
    public static function config() {
        return array( 'dispatch_protocol_version' => 2, 'poll_interval_seconds' => 15,
            'max_jobs_per_poll' => 1, 'min_seconds_between_sms' => 20,
            'max_sms_per_hour' => 60, 'reservation_ttl_seconds' => 180 );
    }
    private function must( $result ) {
        if ( false === $result ) { throw new RuntimeException( 'SMS database operation failed. The result was not acknowledged.' ); }
        return $result;
    }
    private function begin() {
        global $wpdb;
        if ( ! $this->transactional_tables_checked ) {
            $count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA=DATABASE() AND ENGINE='InnoDB' AND TABLE_NAME IN (%s,%s,%s,%s)",
                $this->campaigns, $this->queue, $this->attempts, $this->devices ) );
            if ( 4 !== $count || $wpdb->last_error ) { throw new RuntimeException( 'Safe SMS dispatch requires all four dispatch tables to use InnoDB. Complete the database migration before sending.' ); }
            $this->transactional_tables_checked = true;
        }
        $this->must( $wpdb->query( 'START TRANSACTION' ) );
    }
    private function commit() { global $wpdb; $this->must( $wpdb->query( 'COMMIT' ) ); }
    private function rollback() { global $wpdb; $wpdb->query( 'ROLLBACK' ); }
    private function response( $campaign_id, $queue_status = 'processed' ) {
        global $wpdb;
        $status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$this->campaigns} WHERE id=%d", $campaign_id ) );
        if ( $wpdb->last_error ) { throw new RuntimeException( 'Unable to read campaign status.' ); }
        return array( 'status' => 'ok', 'queue_status' => $queue_status, 'campaign_status' => $status );
    }

    public function reserve_batch( array $agent, int $max_jobs = 1 ) {
        global $wpdb;
        // The device, not the agent registration, owns the sequential lock and limits.
        if ( empty( $agent['kde_device_id'] ) ) { return array(); }
        $device = hash( 'sha256', $agent['kde_device_id'] );
        $now = gmdate( 'Y-m-d H:i:s' );
        $cfg = self::config();
        $this->must( $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$this->devices} (device_key) VALUES (%s)", $device ) ) );
        $this->begin();
        try {
            $lock = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->devices} WHERE device_key=%s FOR UPDATE", $device ), ARRAY_A );
            if ( ! $lock || $wpdb->last_error ) { throw new RuntimeException( 'Unable to lock SMS device.' ); }
            $spacing = max( $cfg['min_seconds_between_sms'], (int) ceil( 3600 / $cfg['max_sms_per_hour'] ) );
            $recent = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->attempts} WHERE device_key=%s AND created_at > %s", $device, gmdate( 'Y-m-d H:i:s', time() - 3600 ) ) );
            if ( $wpdb->last_error ) { throw new RuntimeException( 'Unable to read SMS rate history.' ); }
            $active = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->attempts} WHERE device_key=%s AND status='reserved' AND created_at >= %s", $device, gmdate( 'Y-m-d H:i:s', time() - $cfg['reservation_ttl_seconds'] ) ) );
            if ( $wpdb->last_error ) { throw new RuntimeException( 'Unable to read active SMS attempts.' ); }
            if ( $active || $recent >= $cfg['max_sms_per_hour'] || ( $lock['last_reserved_at'] && strtotime( $lock['last_reserved_at'] . ' UTC' ) > time() - $spacing ) ) {
                $this->commit(); return array();
            }
            // Lock the campaign before its queue row. Pause/cancel use this same order.
            $campaign = $wpdb->get_row( "SELECT * FROM {$this->campaigns} WHERE status='sending' AND channel='sms'
                AND EXISTS (SELECT 1 FROM {$this->queue} q WHERE q.campaign_id={$this->campaigns}.id
                    AND q.status IN ('prepared','retry_wait') AND q.attempt_count < q.max_attempts)
                ORDER BY id LIMIT 1 FOR UPDATE", ARRAY_A );
            if ( $wpdb->last_error ) { throw new RuntimeException( 'Unable to select campaign.' ); }
            if ( ! $campaign ) { $this->commit(); return array(); }
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->queue} WHERE campaign_id=%d AND channel='sms'
                AND status IN ('prepared','retry_wait') AND attempt_count < max_attempts ORDER BY id LIMIT 1 FOR UPDATE", $campaign['id'] ), ARRAY_A );
            if ( $wpdb->last_error ) { throw new RuntimeException( 'Unable to select SMS.' ); }
            if ( ! $row ) { $this->commit(); return array(); }
            $body = $row['message_body_preview'];
            $token_id = null;
            if ( $row['requires_payment_link'] && strpos( $body, '{{PAYMENT_LINK}}' ) !== false ) {
                try {
                    $plugin = Olama_Messages_Plugin::instance();
                    $token = $plugin->tokens()->generate_token( (int) $row['family_id'], $campaign['study_year'] );
                    $link = $plugin->short_links()->get_or_create_for_family_token( $token['token_id'], $row['family_id'], $campaign['study_year'] );
                    $body = str_replace( '{{PAYMENT_LINK}}', $link['short_url'], $body );
                    $token_id = $token['token_id'];
                } catch ( Throwable $error ) {
                    $this->must( $wpdb->update( $this->queue, array( 'status' => 'failed', 'failed_at' => $now,
                        'last_error_message' => 'Payment link rendering failed: ' . $error->getMessage(), 'updated_at' => $now ), array( 'id' => $row['id'] ) ) );
                    $this->commit();
                    $this->check_and_finalize_campaign( (int) $row['campaign_id'] );
                    return array();
                }
            }
            $attempt = wp_generate_uuid4();
            $expires = gmdate( 'Y-m-d H:i:s', time() + $cfg['reservation_ttl_seconds'] );
            $this->must( $wpdb->insert( $this->attempts, array( 'attempt_id' => $attempt, 'queue_id' => $row['id'],
                'agent_id' => $agent['id'], 'device_key' => $device, 'status' => 'reserved', 'created_at' => $now, 'updated_at' => $now ) ) );
            $changed = $this->must( $wpdb->update( $this->queue, array( 'status' => 'reserved', 'dispatch_attempt_id' => $attempt,
                'reserved_by_agent_id' => $agent['id'], 'reserved_by_agent_uuid' => $agent['agent_uuid'], 'reserved_at' => $now,
                'reservation_expires_at' => $expires, 'send_started_at' => $now, 'payment_token_id' => $token_id,
                'attempt_count' => (int) $row['attempt_count'] + 1, 'updated_at' => $now ), array( 'id' => $row['id'], 'status' => $row['status'] ) ) );
            if ( 1 !== $changed ) { throw new RuntimeException( 'SMS reservation changed concurrently.' ); }
            $this->must( $wpdb->update( $this->devices, array( 'last_reserved_at' => $now ), array( 'device_key' => $device ) ) );
            $this->commit();
            Olama_Messages_Plugin::instance()->agents()->log_event( $agent['id'], $agent['agent_uuid'], 'job_reserved', 'SMS attempt reserved.', array( 'queue_id' => $row['id'], 'attempt_id' => $attempt ) );
            return array( array( 'queue_id' => (int) $row['id'], 'campaign_id' => (int) $row['campaign_id'],
                'attempt_id' => $attempt, 'phone_e164' => $row['phone_e164'], 'message_body' => $body,
                'message_length' => mb_strlen( $body ), 'expires_at' => $expires . 'Z' ) );
        } catch ( Throwable $error ) { $this->rollback(); throw $error; }
    }

    public function update_send_status( array $agent, int $queue_id, string $status, int $exit_code, string $stdout, string $stderr, string $attempt_id = '' ) {
        global $wpdb;
        if ( ! in_array( $status, array( 'sent_by_kde', 'failed', 'unknown' ), true ) ) { return false; }
        $this->begin();
        try {
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->queue} WHERE id=%d FOR UPDATE", $queue_id ), ARRAY_A );
            if ( $wpdb->last_error ) { throw new RuntimeException( 'Unable to lock SMS result.' ); }
            if ( ! $row ) { $this->commit(); return array( 'status' => 'ok', 'queue_status' => 'removed' ); }
            $attempt = null;
            if ( '' !== $attempt_id ) {
                $attempt = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->attempts} WHERE attempt_id=%s FOR UPDATE", $attempt_id ), ARRAY_A );
                if ( $wpdb->last_error ) { throw new RuntimeException( 'Unable to read SMS attempt.' ); }
                if ( ! $attempt || (int) $attempt['agent_id'] !== (int) $agent['id'] || (int) $attempt['queue_id'] !== $queue_id ) {
                    $this->rollback(); return false;
                }
                // Superseded/reviewed results are acknowledged, never applied to a newer send.
                if ( $row['dispatch_attempt_id'] !== $attempt_id || ! in_array( $attempt['status'], array( 'reserved', 'unknown' ), true ) ||
                    ( 'unknown' === $attempt['status'] && 'unknown' === $status ) ) {
                    $this->commit(); return $this->response( $row['campaign_id'], 'already_recorded' );
                }
            } elseif ( ! empty( $row['dispatch_attempt_id'] ) || (int) $row['reserved_by_agent_id'] !== (int) $agent['id'] ) {
                $this->rollback(); return false;
            }
            if ( ! $attempt && 'unknown' === $row['status'] && 'sent_by_kde' !== $status ) {
                $this->commit(); return $this->response( $row['campaign_id'], 'already_recorded' );
            }
            if ( in_array( $row['status'], array( 'sent', 'failed', 'cancelled', 'retry_wait' ), true ) ) {
                if ( $attempt && 'cancelled' === $row['status'] ) {
                    $this->must( $wpdb->update( $this->attempts, array( 'status' => 'reviewed', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'attempt_id' => $attempt_id ) ) );
                }
                $this->commit(); return $this->response( $row['campaign_id'], 'already_recorded' );
            }
            // A timeout/nonzero exit after invoking KDE is not proof that nothing was sent.
            if ( 'failed' === $status && ! in_array( $exit_code, array( -1, -2, -4 ), true ) ) { $status = 'unknown'; }
            $attempt_count = (int) $row['attempt_count'] + ( $attempt ? 0 : 1 );
            $new_status = 'sent_by_kde' === $status ? 'sent' : ( 'unknown' === $status ? 'unknown' : ( $attempt_count < (int) $row['max_attempts'] ? 'retry_wait' : 'failed' ) );
            $now = gmdate( 'Y-m-d H:i:s' );
            $changed = $this->must( $wpdb->update( $this->queue, array( 'status' => $new_status, 'attempt_count' => $attempt_count,
                'sent_at' => 'sent' === $new_status ? $now : null, 'failed_at' => 'failed' === $new_status ? $now : null,
                'reservation_expires_at' => null, 'kde_exit_code' => $exit_code, 'last_stdout' => substr( $stdout, 0, 4000 ),
                'last_stderr' => substr( $stderr, 0, 4000 ), 'last_error_code' => 'sent' === $new_status ? null : (string) $exit_code,
                'last_error_message' => 'sent' === $new_status ? null : ( substr( $stderr, 0, 4000 ) ?: 'Outcome requires review.' ),
                'updated_at' => $now ), array( 'id' => $queue_id, 'status' => $row['status'] ) ) );
            if ( 1 !== $changed ) { throw new RuntimeException( 'SMS result changed concurrently.' ); }
            if ( $attempt ) { $this->must( $wpdb->update( $this->attempts, array( 'status' => $new_status, 'updated_at' => $now ), array( 'attempt_id' => $attempt_id ) ) ); }
            $this->commit();
            Olama_Messages_Plugin::instance()->agents()->log_event( $agent['id'], $agent['agent_uuid'], 'job_' . $new_status, 'SMS attempt outcome recorded.', array( 'queue_id' => $queue_id, 'attempt_id' => $attempt_id, 'status' => $new_status ) );
            $this->check_and_finalize_campaign( (int) $row['campaign_id'] );
            return $this->response( $row['campaign_id'], $new_status );
        } catch ( Throwable $error ) { $this->rollback(); throw $error; }
    }

    public function cleanup_stale_reservations( int $timeout_seconds = 180 ) {
        global $wpdb;
        $now = gmdate( 'Y-m-d H:i:s' );
        $this->begin();
        try {
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->queue} WHERE status='reserved'
                AND ((reservation_expires_at IS NOT NULL AND reservation_expires_at < %s)
                OR (reservation_expires_at IS NULL AND reserved_at < %s)) FOR UPDATE", $now, gmdate( 'Y-m-d H:i:s', time() - $timeout_seconds ) ), ARRAY_A );
            if ( $wpdb->last_error ) { throw new RuntimeException( 'Unable to inspect expired SMS reservations.' ); }
            foreach ( $rows as $row ) {
                // Never automatically resend a reservation whose physical outcome is unknown.
                $this->must( $wpdb->update( $this->queue, array( 'status' => 'unknown', 'reservation_expires_at' => null,
                    'last_error_message' => 'Agent reservation expired. Check the phone history before retrying.', 'updated_at' => $now ), array( 'id' => $row['id'], 'status' => 'reserved' ) ) );
                if ( ! empty( $row['dispatch_attempt_id'] ) ) {
                    $this->must( $wpdb->update( $this->attempts, array( 'status' => 'unknown', 'updated_at' => $now ), array( 'attempt_id' => $row['dispatch_attempt_id'], 'status' => 'reserved' ) ) );
                }
            }
            $this->must( $wpdb->query( $wpdb->prepare( "UPDATE {$this->attempts} a JOIN {$this->queue} q ON q.dispatch_attempt_id=a.attempt_id
                SET a.status='reviewed', a.updated_at=%s WHERE a.status='reserved' AND q.status='cancelled'
                AND q.reservation_expires_at < %s", $now, $now ) ) );
            $this->commit(); return count( $rows );
        } catch ( Throwable $error ) { $this->rollback(); throw $error; }
    }

    public function resolve_unknown( int $queue_id, string $resolution ) {
        global $wpdb;
        if ( ! in_array( $resolution, array( 'submitted', 'retry', 'failed' ), true ) ) { throw new InvalidArgumentException( 'Invalid SMS resolution.' ); }
        $this->begin();
        try {
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->queue} WHERE id=%d FOR UPDATE", $queue_id ), ARRAY_A );
            if ( $wpdb->last_error || ! $row || 'unknown' !== $row['status'] ) { throw new RuntimeException( 'SMS is no longer awaiting review. Refresh the page.' ); }
            if ( 'retry' === $resolution && (int) $row['attempt_count'] >= (int) $row['max_attempts'] ) { throw new RuntimeException( 'Attempt limit reached. Mark this message as failed.' ); }
            $now = gmdate( 'Y-m-d H:i:s' );
            $status = 'submitted' === $resolution ? 'sent' : ( 'retry' === $resolution ? 'retry_wait' : 'failed' );
            $this->must( $wpdb->update( $this->queue, array( 'status' => $status, 'sent_at' => 'sent' === $status ? $now : null,
                'failed_at' => 'failed' === $status ? $now : null, 'updated_at' => $now,
                'last_error_message' => 'Reviewed by user ' . get_current_user_id() . ': ' . $resolution ), array( 'id' => $queue_id, 'status' => 'unknown' ) ) );
            if ( ! empty( $row['dispatch_attempt_id'] ) ) {
                $this->must( $wpdb->update( $this->attempts, array( 'status' => 'reviewed', 'updated_at' => $now ), array( 'attempt_id' => $row['dispatch_attempt_id'] ) ) );
            }
            $this->commit();
            Olama_Messages_Plugin::instance()->agents()->log_event( null, '', 'sms_reviewed', 'SMS outcome reviewed.', array( 'queue_id' => $queue_id, 'resolution' => $resolution, 'user_id' => get_current_user_id() ) );
            $this->check_and_finalize_campaign( (int) $row['campaign_id'] );
            return (int) $row['campaign_id'];
        } catch ( Throwable $error ) { $this->rollback(); throw $error; }
    }
    public function reconcile_sending_campaigns() {
        global $wpdb;
        $ids = $wpdb->get_col( "SELECT id FROM {$this->campaigns} WHERE status='sending' AND channel='sms'" );
        foreach ( $ids as $id ) { $this->check_and_finalize_campaign( (int) $id ); }
        return count( $ids );
    }
    private function check_and_finalize_campaign( int $campaign_id ) {
        global $wpdb;
        // One conditional update avoids overwriting a concurrent pause/cancel.
        $this->must( $wpdb->query( $wpdb->prepare( "UPDATE {$this->campaigns} c SET
            status=IF(EXISTS(SELECT 1 FROM {$this->queue} f WHERE f.campaign_id=c.id AND f.status='failed'),'completed_with_errors','completed'),
            completed_at=%s, updated_at=%s WHERE c.id=%d AND c.status='sending' AND c.channel='sms'
            AND NOT EXISTS(SELECT 1 FROM {$this->queue} p WHERE p.campaign_id=c.id AND p.status IN ('prepared','reserved','retry_wait','unknown'))",
            gmdate( 'Y-m-d H:i:s' ), gmdate( 'Y-m-d H:i:s' ), $campaign_id ) ) );
    }
    public function estimate_remaining_seconds( int $campaign_id, int $pending ) {
        global $wpdb;
        if ( $pending < 1 ) { return 0; }
        $cfg = self::config();
        $baseline = max( $cfg['min_seconds_between_sms'], (int) ceil( 3600 / $cfg['max_sms_per_hour'] ) );
        $times = $wpdb->get_col( $wpdb->prepare( "SELECT reserved_at FROM {$this->queue} WHERE campaign_id=%d AND reserved_at IS NOT NULL ORDER BY reserved_at DESC LIMIT 20", $campaign_id ) );
        $observed = count( $times ) > 1 ? ( strtotime( $times[0] . ' UTC' ) - strtotime( end( $times ) . ' UTC' ) ) / ( count( $times ) - 1 ) : 0;
        return (int) ceil( $pending * max( $baseline, $observed ) );
    }
}
