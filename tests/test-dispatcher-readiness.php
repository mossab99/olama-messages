<?php
/** Standalone regression: php tests/test-dispatcher-readiness.php */
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
function sanitize_text_field( $value ) { return trim( $value ); }
require dirname( __DIR__ ) . '/includes/class-olama-messages-agent-service.php';

$wpdb = new class {
	public $prefix = 'wp_';
	public $rows = array();
	public function prepare( $sql, ...$args ) { return $sql; }
	public function get_results( $sql, $format ) { return $this->rows; }
};
$service = new Olama_Messages_Agent_Service();
function check_dispatcher( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	echo "PASS: {$message}\n";
}
function dispatcher_fixture( $id, $age, $state = 'Reserved', $enabled = true, $error = '' ) {
	return array(
		'id' => $id, 'kde_cli_found' => 1, 'kde_device_reachable' => 1,
		'last_heartbeat_json' => json_encode( array(
			'dispatch_protocol_version' => 2, 'dispatcher_enabled' => $enabled, 'dispatcher_state' => $state,
			'dispatcher_last_poll_at' => gmdate( 'Y-m-d H:i:s', time() - $age ),
			'dispatcher_last_error' => $error,
		) ),
	);
}
$wpdb->rows = array( dispatcher_fixture( 1, 600 ) );
check_dispatcher( null === $service->get_ready_dispatcher_agent(), 'A stalled worker cannot authorize sending' );
check_dispatcher( 1 === $service->get_online_dispatcher_agent()['id'], 'A stalled worker remains available for last-poll diagnostics' );
$wpdb->rows[] = dispatcher_fixture( 2, 10, 'Idle' );
check_dispatcher( 2 === $service->get_ready_dispatcher_agent()['id'], 'A healthy worker is preferred over a stalled worker' );
$wpdb->rows = array( dispatcher_fixture( 3, 10, 'Error', true, 'KDE timed out' ) );
check_dispatcher( null === $service->get_ready_dispatcher_agent(), 'An errored worker cannot authorize sending' );
check_dispatcher( 'KDE timed out' === $service->get_online_dispatcher_agent()['heartbeat']['dispatcher_last_error'], 'Worker error remains available for diagnostics' );
$wpdb->rows = array( dispatcher_fixture( 4, 10, 'Disabled', false ) );
check_dispatcher( null === $service->get_online_dispatcher_agent(), 'Disabled dispatchers are excluded' );
$wpdb->rows = array();
check_dispatcher( null === $service->get_online_dispatcher_agent(), 'No online agents returns null' );
