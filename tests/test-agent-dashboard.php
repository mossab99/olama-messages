<?php
define( 'DOING_AJAX', true );
require __DIR__ . '/communications-bootstrap.php';
$service = new Olama_Messages_Agent_Dashboard();
$agent_service = Olama_Messages_Plugin::instance()->agents();
$created = $agent_service->create_agent( array( 'agent_name' => 'Office sender' ) );
$agent = $agent_service->get_agent_by_uuid( $created['uuid'] ); $id = $agent['id'];
$now = time(); $utc = gmdate( 'Y-m-d H:i:s', $now );
$agent_service->update_heartbeat( $created['uuid'], array( 'platform' => 'Windows', 'app_version' => '2.0.3', 'machine_name' => 'SCHOOL-OFFICE', 'windows_user' => 'Office',
    'kde_cli_path' => 'C:\\Program Files\\KDE Connect\\bin\\kdeconnect-cli.exe', 'kde_cli_found' => true, 'kde_device_id' => 'synthetic-phone',
    'kde_device_name' => 'Galaxy · School phone', 'kde_device_reachable' => true, 'dispatcher_enabled' => true,
    'dispatcher_state' => 'Idle', 'dispatcher_last_poll_at' => $utc, 'dispatch_protocol_version' => 2 ) );
$agent = $agent_service->get_agent( $id );
$basework = array( 'progress_at' => $now, 'pending' => 0, 'active' => null, 'campaign' => null );
comm_assert( 'ready' === Olama_Messages_Agent_Dashboard::health( $agent, $basework, $now )['key'], 'healthy worker with no work is ready' );
$work = array_merge( $basework, array( 'active' => array( 'id' => 1 ) ) );
comm_assert( 'sending' === Olama_Messages_Agent_Dashboard::health( $agent, $work, $now )['key'], 'live queue reservation takes priority over idle heartbeat' );
$stale = $agent; $stale['last_seen_at'] = gmdate( 'Y-m-d H:i:s', $now-600 );
comm_assert( 'offline' === Olama_Messages_Agent_Dashboard::health( $stale, $work, $now )['key'], 'old heartbeat does not show a connected agent' );
$stale = $agent; $stale['heartbeat']['dispatcher_last_poll_at'] = gmdate( 'Y-m-d H:i:s', $now-600 );
comm_assert( 'stalled' === Olama_Messages_Agent_Dashboard::health( $stale, array_merge( $basework, array( 'progress_at' => 0 ) ), $now )['key'], 'old poll and no recent queue progress is stalled' );
$stale['revoked_at'] = $utc;
comm_assert( 'revoked' === Olama_Messages_Agent_Dashboard::health( $stale, $work, $now )['key'], 'revocation always wins over activity' );
$offline = $agent; $offline['kde_device_reachable'] = 0;
comm_assert( 'phone_offline' === Olama_Messages_Agent_Dashboard::health( $offline, $work, $now )['key'], 'phone disconnect has an actionable state' );
$disabled = $agent; $disabled['heartbeat']['dispatcher_enabled'] = false;
comm_assert( 'disabled' === Olama_Messages_Agent_Dashboard::health( $disabled, $basework, $now )['key'], 'disabled worker is not called ready' );
comm_assert( 'paused' === Olama_Messages_Agent_Dashboard::health( $agent, array_merge( $basework, array( 'campaign' => array( 'status' => 'paused' ) ) ), $now )['key'], 'paused campaign has a distinct operational state' );
comm_assert( strpos( Olama_Messages_Agent_Dashboard::time_label( '2026-11-06 17:30:00' ), '20:30:00' ) !== false, 'UTC heartbeat is displayed in Amman time' );
comm_assert( strpos( Olama_Messages_Agent_Dashboard::time_label( '2026-11-06 20:30:00', false ), '20:30:00' ) !== false, 'legacy local audit times are not shifted twice' );
$c = $wpdb->prefix . 'olama_msg_campaigns'; $q = $wpdb->prefix . 'olama_msg_queue'; $a = $wpdb->prefix . 'olama_msg_dispatch_attempts'; $r = $wpdb->prefix . 'olama_msg_campaign_recipients';
$wpdb->insert( $c, array( 'title' => 'November parent meeting', 'status' => 'sending', 'channel' => 'sms', 'created_at' => $utc ) ); $cid = $wpdb->insert_id;
$wpdb->insert( $r, array( 'campaign_id' => $cid, 'family_id' => 1, 'recipient_name' => 'Family <script>alert(1)</script>', 'recipient_type' => 'father', 'created_at' => $utc ) ); $rid = $wpdb->insert_id;
for ( $i=0; $i<12; $i++ ) {
    $attempt = wp_generate_uuid4(); $sent = gmdate( 'Y-m-d H:i:s', $now - (11-$i)*10 - 4 );
    $wpdb->insert( $q, array( 'campaign_id' => $cid, 'campaign_recipient_id' => $rid, 'family_id' => 1, 'phone_e164' => '+962790000123',
        'status' => 'sent', 'reserved_by_agent_id' => $id, 'reserved_at' => $sent, 'sent_at' => $sent, 'dispatch_attempt_id' => $attempt, 'created_at' => $sent ) );
    $wpdb->insert( $a, array( 'attempt_id' => $attempt, 'queue_id' => $wpdb->insert_id, 'agent_id' => $id, 'device_key' => hash( 'sha256', 'synthetic-phone' ), 'status' => 'sent', 'created_at' => $sent, 'updated_at' => $sent ) );
}
for ( $i=0; $i<8; $i++ ) { $wpdb->insert( $q, array( 'campaign_id' => $cid, 'campaign_recipient_id' => $rid, 'family_id' => 1, 'status' => 'prepared', 'created_at' => $utc ) ); }
$before = $wpdb->get_var( "SELECT COUNT(*) FROM $a" ); $snapshot = $service->snapshot( $id );
comm_assert( 10 === $snapshot['median'] && 8 === $snapshot['pending'], 'dashboard computes observed cadence and campaign remaining count' );
comm_assert( 12 === count( $snapshot['attempts'] ), 'one activity row per attempt instead of duplicate audit events' );
comm_assert( ! isset( $snapshot['agent']['agent_key_hash'] ) && strpos( json_encode( $snapshot ), $created['raw_key'] ) === false, 'dashboard never exposes agent credentials' );
comm_assert( $before === $wpdb->get_var( "SELECT COUNT(*) FROM $a" ), 'reading dashboard creates no send attempts' );
$html = Olama_Messages_Agent_Page::part( 'activity', $snapshot );
comm_assert( strpos( $html, '<script>alert(1)</script>' ) === false && strpos( $html, '&lt;script&gt;' ) !== false, 'recipient text is escaped in activity output' );
comm_assert( strpos( $html, '+962790000123' ) === false, 'activity masks phone numbers by default' );
comm_assert( 1 === count( $service->listing() ), 'fleet listing returns agent summaries' );
// Exercise the actual AJAX controller, including its capability and nonce gates.
$ajax_admin = new Olama_Messages_Admin( Olama_Messages_Plugin::instance() );
$die_handler = function () { return function ( $message = '' ) { echo is_scalar( $message ) ? $message : ''; throw new RuntimeException( 'dashboard_fixture_done' ); }; };
add_filter( 'wp_die_handler', $die_handler ); add_filter( 'wp_die_ajax_handler', $die_handler );
function dashboard_ajax_capture( $admin ) {
    ob_start();
    try { $admin->ajax_agent_workspace(); }
    catch ( RuntimeException $error ) { if ( 'dashboard_fixture_done' !== $error->getMessage() ) { ob_end_clean(); throw $error; } }
    return ob_get_clean();
}
$_POST['agent_id'] = $id; $_REQUEST['nonce'] = wp_create_nonce( 'olama_msg_ajax' );
wp_set_current_user( 0 );
$denied = json_decode( dashboard_ajax_capture( $ajax_admin ), true );
comm_assert( false === $denied['success'], 'workspace refresh rejects accounts without access' );
$viewer = wp_insert_user( array( 'user_login' => 'agent_dashboard_viewer', 'user_pass' => wp_generate_password( 30 ), 'role' => 'subscriber' ) );
get_user_by( 'id', $viewer )->add_cap( 'olama_access_messages' ); wp_set_current_user( $viewer );
$_REQUEST['nonce'] = 'invalid';
comm_assert( '-1' === trim( dashboard_ajax_capture( $ajax_admin ) ), 'workspace refresh rejects invalid nonce' );
$_REQUEST['nonce'] = wp_create_nonce( 'olama_msg_ajax' );
$allowed = json_decode( dashboard_ajax_capture( $ajax_admin ), true );
comm_assert( true === $allowed['success'] && isset( $allowed['data']['parts']['hero'], $allowed['data']['parts']['activity'] ), 'authorized refresh returns rendered operational sections' );
remove_filter( 'wp_die_handler', $die_handler ); remove_filter( 'wp_die_ajax_handler', $die_handler );
// Render fixtures with the real view, CSS and JS for browser QA. No school data.
if ( getenv( 'OLAMA_AGENT_UI_FIXTURE' ) ) {
    $out = getenv( 'OLAMA_AGENT_UI_FIXTURE' ); if ( ! is_dir( $out ) ) { mkdir( $out, 0777, true ); }
    $snapshot['attempts'][0]['recipient_name'] = 'Family of Omar';
    foreach ( $snapshot['attempts'] as &$row ) { if ( strpos( $row['recipient_name'], '<script>' ) !== false ) { $row['recipient_name'] = 'Family of Lina'; } } unset( $row );
    $snapshot['events'] = array();
    $parts = array(); foreach ( array( 'hero','metrics','campaign','activity' ) as $part ) { $parts[$part] = Olama_Messages_Agent_Page::part( $part, $snapshot ); }
    file_put_contents( $out . '/fleet.json', wp_json_encode( array( 'success' => true, 'data' => array( 'parts' => array( 'fleet' => Olama_Messages_Agent_Page::part( 'fleet', $service->listing() ) ) ) ) ) );
    file_put_contents( $out . '/refresh.json', wp_json_encode( array( 'success' => true, 'data' => array( 'parts' => $parts ) ) ) );
    $root = dirname( __DIR__ );
    $head = '<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Agent workspace fixture</title>';
    foreach ( array( ABSPATH . 'wp-includes/css/dashicons.css', ABSPATH . 'wp-includes/css/buttons.css', ABSPATH . 'wp-admin/css/forms.css', $root . '/assets/admin.css' ) as $css ) { $head .= '<link rel="stylesheet" href="file:///' . str_replace( '\\', '/', $css ) . '">'; }
    $head .= '<style>body{margin:0;background:#f0f2f5;font-family:Arial,sans-serif}.fixture-top{height:32px;background:#1d2327;color:#fff;padding:8px 20px;box-sizing:border-box;font-size:12px}.fixture-nav{position:fixed;top:32px;bottom:0;width:160px;background:#1d2327;color:#c3c9cd;padding:24px 16px;box-sizing:border-box;font-size:12px;line-height:3}.fixture-nav strong{display:block;color:#fff;background:#303b45;margin:15px -16px;padding:0 16px}.fixture-content{margin-left:180px;padding:4px 10px 15px 0} @media(max-width:760px){.fixture-nav{display:none}.fixture-content{margin-left:12px}}</style></head><body><div class="fixture-top">WordPress · School administration</div><nav class="fixture-nav">Dashboard<br>School management<br>Students<br>Families<strong>Olama Messages</strong>Campaigns<br>Sending agents<br>Settings</nav><main class="fixture-content">';
    ob_start(); Olama_Messages_Agent_Page::detail( $snapshot ); $detail = ob_get_clean();
    ob_start(); Olama_Messages_Agent_Page::listing( $service->listing() ); $listing = ob_get_clean();
    $tail = '</main><script src="file:///' . str_replace( '\\', '/', ABSPATH ) . 'wp-includes/js/jquery/jquery.min.js"></script><script>var olamaMsgAdmin={ajaxUrl:"http://127.0.0.1:13389/admin-ajax.php",nonce:"fixture"};</script><script src="file:///' . str_replace( '\\', '/', $root ) . '/assets/admin.js"></script></body></html>';
    file_put_contents( $out . '/detail.html', $head . $detail . $tail ); file_put_contents( $out . '/list.html', $head . $listing . $tail );
}
echo "Agent workspace checks complete. No dispatch behavior changed.\n";
