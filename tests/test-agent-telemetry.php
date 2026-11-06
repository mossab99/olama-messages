<?php
require __DIR__ . '/communications-bootstrap.php';
$agents = Olama_Messages_Plugin::instance()->agents();
$created = $agents->create_agent( array( 'agent_name' => 'Synthetic telemetry agent' ) );
$uuid = $created['uuid'];
$poll = gmdate( 'Y-m-d H:i:s' );
$full = array( 'platform' => 'Windows', 'app_version' => '2.0.2', 'kde_cli_found' => true,
    'kde_device_id' => 'synthetic-device', 'kde_device_name' => 'Synthetic Galaxy', 'kde_device_reachable' => true,
    'dispatch_protocol_version' => 2, 'dispatcher_enabled' => true, 'dispatcher_state' => 'Idle', 'dispatcher_last_poll_at' => $poll );
comm_assert( $agents->update_heartbeat( $uuid, $full ), 'full worker heartbeat accepted' );
comm_assert( null !== $agents->get_ready_dispatcher_agent(), 'full live heartbeat makes worker ready' );
$partial = array( 'kde_cli_found' => true, 'kde_device_id' => 'synthetic-device', 'kde_device_name' => '', 'kde_device_reachable' => true );
$agents->update_heartbeat( $uuid, $partial ); $row = $agents->get_agent_by_uuid( $uuid );
comm_assert( 'Synthetic Galaxy' === $row['kde_device_name'], 'old connection test cannot erase connected device name' );
comm_assert( true === $row['heartbeat']['dispatcher_enabled'] && 'Idle' === $row['heartbeat']['dispatcher_state'], 'partial heartbeat preserves actual dispatcher telemetry' );
comm_assert( $poll === $row['heartbeat']['dispatcher_last_poll_at'], 'partial heartbeat does not fabricate a newer worker poll' );
comm_assert( null !== $agents->get_ready_dispatcher_agent(), 'connection probe no longer hides a ready worker' );
$full['dispatcher_last_poll_at'] = gmdate( 'Y-m-d H:i:s', time()-600 );
$agents->update_heartbeat( $uuid, $full ); $agents->update_heartbeat( $uuid, $partial );
comm_assert( null === $agents->get_ready_dispatcher_agent(), 'partial probe cannot revive a stalled worker' );
$agents->update_heartbeat( $uuid, array_merge( $full, array( 'dispatcher_enabled' => false ) ) );
comm_assert( false === $agents->get_agent_by_uuid( $uuid )['heartbeat']['dispatcher_enabled'], 'explicit disabled state is not masked by merging' );
$agents->update_heartbeat( $uuid, $full );
$agents->update_heartbeat( $uuid, array( 'kde_device_id' => 'different-phone', 'kde_cli_found' => true, 'kde_device_reachable' => true ) );
$row = $agents->get_agent_by_uuid( $uuid );
comm_assert( '' === $row['kde_device_name'] && ! isset( $row['heartbeat']['dispatcher_enabled'] ), 'changed target does not inherit old phone name or dispatcher state' );
$agents->update_heartbeat( $uuid, $full );
$before = $agents->get_agent_by_uuid( $uuid )['last_heartbeat_json'];
comm_assert( ! $agents->update_heartbeat( $uuid, array( 'huge' => str_repeat( 'x', 17000 ) ) ), 'oversized telemetry rejected' );
comm_assert( $before === $agents->get_agent_by_uuid( $uuid )['last_heartbeat_json'], 'invalid telemetry cannot replace healthy saved snapshot' );
$request = new WP_REST_Request( 'POST' ); $request->set_param( '_authorized_agent', $agents->get_agent_by_uuid( $uuid ) );
$request->set_header( 'content-type', 'application/json' ); $request->set_body( json_encode( array( 'kde_device_reachable' => false ) ) );
$response = (new Olama_Messages_Agent_Rest_Controller())->handle_heartbeat( $request );
comm_assert( 'offline' === $response->get_data()['agent_status'], 'heartbeat response reflects actual device readiness rather than always online' );
comm_assert( 0 === $agents->get_agent_by_uuid( $uuid )['kde_device_reachable'], 'explicit device disconnect is preserved' );
echo "Telemetry regression checks complete. No SMS sent.\n";
