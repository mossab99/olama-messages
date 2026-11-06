<?php
require __DIR__ . '/communications-bootstrap.php';
$svc = Olama_Messages_Plugin::instance()->dispatcher();
$camps = $wpdb->prefix . 'olama_msg_campaigns'; $queue = $wpdb->prefix . 'olama_msg_queue';
$attempts = $wpdb->prefix . 'olama_msg_dispatch_attempts'; $devices = $wpdb->prefix . 'olama_msg_dispatch_devices';
$agent = array( 'id' => 99, 'agent_uuid' => 'fixture-agent', 'kde_device_id' => 'synthetic-phone' );
function sms_fixture( $status = 'sending' ) {
    global $wpdb, $camps, $queue;
    $wpdb->insert( $camps, array( 'title' => 'Synthetic SMS', 'status' => $status, 'channel' => 'sms', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
    $id = (int) $wpdb->insert_id;
    $wpdb->insert( $queue, array( 'campaign_id' => $id, 'campaign_recipient_id' => 1, 'family_id' => 1,
        'phone_e164' => '+962790000000', 'message_body_preview' => 'Fictional test only', 'requires_payment_link' => 0,
        'status' => 'prepared', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
    return array( $id, (int) $wpdb->insert_id );
}
function sms_row( $id ) { global $wpdb, $queue; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $queue WHERE id=%d", $id ), ARRAY_A ); }
function sms_free_rate() { global $wpdb, $devices; $wpdb->query( "UPDATE $devices SET last_reserved_at='2020-01-01 00:00:00'" ); }
list( $campaign, $qid ) = sms_fixture();
Olama_Messages_Activator::create_tables();
comm_assert( 'dispatch_attempt_id' === $wpdb->get_var( "SHOW COLUMNS FROM $queue LIKE 'dispatch_attempt_id'" ), 'migration is additive and repeatable' );
$job = $svc->reserve_batch( $agent )[0];
comm_assert( ! empty( $job['attempt_id'] ) && 1 === (int) sms_row( $qid )['attempt_count'], 'reservation creates a durable counted attempt' );
comm_assert( array() === $svc->reserve_batch( $agent ), 'one active reservation per device' );
$other = array_merge( $agent, array( 'id' => 100 ) );
comm_assert( array() === $svc->reserve_batch( $other ), 'same phone across agent registrations shares lock' );
Olama_Messages_Plugin::instance()->campaigns()->pause_campaign_sending( $campaign );
comm_assert( 'reserved' === sms_row( $qid )['status'] && 99 === (int) sms_row( $qid )['reserved_by_agent_id'], 'pause preserves in-flight owner and reservation' );
$result = $svc->update_send_status( $agent, $qid, 'sent_by_kde', 0, '', '', $job['attempt_id'] );
comm_assert( 'sent' === sms_row( $qid )['status'] && 'paused' === $result['campaign_status'], 'late success accepted without overwriting pause' );
$svc->update_send_status( $agent, $qid, 'sent_by_kde', 0, '', '', $job['attempt_id'] );
comm_assert( 1 === (int) sms_row( $qid )['attempt_count'], 'repeated success counts once' );
list( $campaign2, $qid2 ) = sms_fixture();
comm_assert( array() === $svc->reserve_batch( $agent ), 'minimum spacing enforced on server' );
comm_assert( $svc->retry_after_seconds > 0 && $svc->retry_after_seconds <= 10, 'rate-limited poll provides remaining delay rather than a second full cycle' );
$wpdb->query( "UPDATE $devices SET last_reserved_at='" . gmdate( 'Y-m-d H:i:s', time() - 11 ) . "'" );
$job2 = $svc->reserve_batch( $agent )[0];
comm_assert( $job2['queue_id'] === $qid2, 'eleven-second-old reservation no longer imposes a one-minute wait' );
for ( $i=0; $i<3; $i++ ) { $svc->update_send_status( $agent, $qid2, 'failed', -2, '', 'Phone unavailable; not sent', $job2['attempt_id'] ); }
comm_assert( 'retry_wait' === sms_row( $qid2 )['status'] && 1 === (int) sms_row( $qid2 )['attempt_count'], 'repeated failed report does not consume retries' );
sms_free_rate(); $job3 = $svc->reserve_batch( $agent )[0];
$svc->update_send_status( $agent, $qid2, 'failed', -2, '', 'Old repeated result', $job2['attempt_id'] );
comm_assert( 'reserved' === sms_row( $qid2 )['status'] && $job3['attempt_id'] === sms_row( $qid2 )['dispatch_attempt_id'], 'old attempt cannot alter a newer reservation' );
$svc->update_send_status( $agent, $qid2, 'failed', -3, '', 'CLI timed out', $job3['attempt_id'] );
comm_assert( 'unknown' === sms_row( $qid2 )['status'], 'timeout held for review instead of automatic resend' );
sms_free_rate(); comm_assert( array() === $svc->reserve_batch( $agent ), 'unknown SMS is not automatically reserved' );
comm_assert( 'sending' === $wpdb->get_var( "SELECT status FROM $camps WHERE id=$campaign2" ), 'unknown prevents false campaign completion' );
$svc->resolve_unknown( $qid2, 'retry' );
$job4 = $svc->reserve_batch( $agent )[0];
$svc->update_send_status( $agent, $qid2, 'sent_by_kde', 0, '', 'Late older outcome', $job3['attempt_id'] );
comm_assert( 'reserved' === sms_row( $qid2 )['status'], 'reviewed outcome fenced from later callbacks' );
$wpdb->query( "UPDATE $queue SET reservation_expires_at='2020-01-01 00:00:00' WHERE id=$qid2" );
$svc->cleanup_stale_reservations();
comm_assert( 'unknown' === sms_row( $qid2 )['status'], 'expired lease becomes unknown' );
$svc->update_send_status( $agent, $qid2, 'sent_by_kde', 0, '', '', $job4['attempt_id'] );
comm_assert( 'sent' === sms_row( $qid2 )['status'], 'late exact-attempt success can resolve an expired lease' );
comm_assert( 'completed' === $wpdb->get_var( "SELECT status FROM $camps WHERE id=$campaign2" ), 'campaign completes only after outcomes resolved' );
list( $campaign3, $qid3 ) = sms_fixture(); sms_free_rate();
$job5 = $svc->reserve_batch( $agent )[0];
$wpdb->query( "CREATE TRIGGER sms_fail_write BEFORE UPDATE ON $queue FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic write failure'" );
comm_throws( function () use ( $svc, $agent, $qid3, $job5 ) { $svc->update_send_status( $agent, $qid3, 'sent_by_kde', 0, '', '', $job5['attempt_id'] ); }, 'failed database write is not acknowledged' );
$wpdb->query( 'DROP TRIGGER sms_fail_write' );
comm_assert( 'reserved' === sms_row( $qid3 )['status'], 'failed result transaction rolls back' );
$svc->update_send_status( $agent, $qid3, 'sent_by_kde', 0, '', '', $job5['attempt_id'] );
comm_assert( 'sent' === sms_row( $qid3 )['status'], 'same durable result can recover after database repair' );
list( $campaign4, $qid4 ) = sms_fixture(); sms_free_rate();
$job6 = $svc->reserve_batch( $agent )[0];
Olama_Messages_Plugin::instance()->campaigns()->cancel_campaign( $campaign4 );
$svc->update_send_status( $agent, $qid4, 'sent_by_kde', 0, '', '', $job6['attempt_id'] );
comm_assert( 'cancelled' === sms_row( $qid4 )['status'], 'late result does not revive cancelled queue' );
list( $campaign5, $qid5 ) = sms_fixture(); sms_free_rate();
for ( $i=0; $i<360; $i++ ) { $wpdb->insert( $attempts, array( 'attempt_id' => wp_generate_uuid4(), 'queue_id' => $qid5, 'agent_id' => 99,
    'device_key' => hash( 'sha256', $agent['kde_device_id'] ), 'status' => 'sent', 'created_at' => gmdate( 'Y-m-d H:i:s', time()-120 ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) ); }
comm_assert( array() === $svc->reserve_batch( $agent ), 'rolling hourly limit enforced even after minimum spacing' );
comm_assert( $svc->estimate_remaining_seconds( $campaign5, 10 ) === 100, 'ETA uses ten-second spacing for a fresh campaign' );
foreach ( array( 180, 120 ) as $age ) {
    $wpdb->insert( $queue, array( 'campaign_id' => $campaign5, 'campaign_recipient_id' => 1, 'family_id' => 1,
        'status' => 'sent', 'created_at' => gmdate( 'Y-m-d H:i:s' ),
        'reserved_at' => gmdate( 'Y-m-d H:i:s', time() - $age ), 'sent_at' => gmdate( 'Y-m-d H:i:s', time() - $age + 2 ) ) );
}
comm_assert( 100 === $svc->estimate_remaining_seconds( $campaign5, 10 ), 'ETA includes processing inside current ten-second cadence' );
$rest = new Olama_Messages_Agent_REST_Controller();
$request = new WP_REST_Request( 'POST' ); $request->set_param( '_authorized_agent', $agent ); $request->set_body( '{}' ); $request->set_header( 'content-type', 'application/json' );
comm_assert( 426 === $rest->handle_reserve_jobs( $request )->get_status(), 'old agent cannot reserve under new protocol' );
echo "SMS recovery integration checks complete. No SMS sent.\n";
