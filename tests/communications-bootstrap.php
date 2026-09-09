<?php
/** Real WordPress/MySQL harness. Creates only a random disposable test database. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$host = getenv( 'OLAMA_TEST_DB_HOST' ) ?: '127.0.0.1';
$port = (int) ( getenv( 'OLAMA_TEST_DB_PORT' ) ?: 13387 );
$user = getenv( 'OLAMA_TEST_DB_USER' ) ?: 'root';
$password = getenv( 'OLAMA_TEST_DB_PASSWORD' ) ?: 'communications-test-only';
mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );
$connection = new mysqli( $host, $user, $password, '', $port );
$database = 'olama_comm_test_' . bin2hex( random_bytes( 6 ) );
$connection->query( 'CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4' );
register_shutdown_function( function () use ( $connection, $database ) {
    if ( preg_match( '/^olama_comm_test_[a-f0-9]{12}$/', $database ) ) { $connection->query( 'DROP DATABASE `' . $database . '`' ); }
} );
define( 'ABSPATH', str_replace( '\\', '/', dirname( __DIR__, 4 ) ) . '/' );
define( 'DB_NAME', $database ); define( 'DB_USER', $user ); define( 'DB_PASSWORD', $password );
define( 'DB_HOST', $host . ':' . $port ); define( 'DB_CHARSET', 'utf8mb4' ); define( 'DB_COLLATE', '' );
define( 'WP_INSTALLING', true ); define( 'WP_DEBUG', false ); define( 'DISABLE_WP_CRON', true );
define( 'WP_HOME', 'http://communications-test.invalid' ); define( 'WP_SITEURL', WP_HOME );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/olama-empty-test-content' );
define( 'WP_CONTENT_URL', WP_HOME . '/wp-content' );
$table_prefix = 'test_';
$_SERVER['HTTP_HOST'] = 'communications-test.invalid'; $_SERVER['REQUEST_METHOD'] = 'GET';
require ABSPATH . 'wp-settings.php';
add_filter( 'pre_wp_mail', '__return_true' );
add_filter( 'pre_http_request', function () { return new WP_Error( 'network_disabled', 'Test has no external network.' ); } );
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
$wpdb->suppress_errors( true );
dbDelta( wp_get_db_schema( 'all' ) );
populate_options(); populate_roles();
update_option( 'timezone_string', 'Asia/Amman' );

class Communications_Test_Core {
    public $families = array(); public $employees = array(); public $enrolled = true; public $ready = true;
    public function families() { return $this; } public function employees() { return $this; }
    public function academic_context() { return $this; } public function student_years() { return $this; }
    public function audiences() { return $this; }
    public function read_models() { return $this; }
    public function table( $name ) { global $wpdb; if ( 'student_years' !== $name ) { throw new RuntimeException( 'Unexpected model' ); } return $wpdb->prefix . 'comms_test_enrollments'; }
    public function get_by_uids( $ids ) { return array_values( array_intersect_key( $this->families, array_flip( $ids ) ) ); }
    public function get_by_employee_ids( $ids ) { return array_values( array_intersect_key( $this->employees, array_flip( $ids ) ) ); }
    public function current() { return (object) array( 'study_year' => '2026-2027' ); }
    public function get_by_uid( $id ) { return $this->families[$id] ?? null; }
    public function get_by_oracle_id( $id ) { foreach ( $this->families as $row ) { if ( $row['oracle_family_id'] === $id ) { return $row; } } return null; }
    public function get_by_employee_id( $id ) { return $this->employees[$id] ?? null; }
    public function get_by_family( $id, $year ) { return $this->enrolled && isset( $this->families[$id] ) ? array( array( 'student_status' => 'active' ) ) : array(); }
    public function get_sync_health( $type, $year ) { return array( 'ready' => $this->ready, 'source' => $type ); }
    public function active( $args ) { return array_slice( array_values( $this->employees ), $args['offset'], $args['limit'] ); }
    public function query( $args ) {
        $rows = array();
        foreach ( array_slice( array_values( $this->families ), $args['offset'], $args['limit'] ) as $family ) {
            $rows[] = array( 'core_family_uid' => $family['family_uid'], 'sponsor_name' => $family['sponsor_full_name'], 'students' => array() );
        }
        return array( 'items' => $rows );
    }
}
function olama_core() { return $GLOBALS['communications_test_core']; }
function olama_users_get_identity( $id ) { return $GLOBALS['communications_test_identities'][$id] ?? null; }
class Olama_Users_DB {
    public static function get_identity( $type, $external ) {
        foreach ( $GLOBALS['communications_test_identities'] as $identity ) {
            if ( $identity['identity_type'] === $type && $identity['oracle_identifier'] === $external ) { return $identity; }
        }
        return null;
    }
}
$GLOBALS['communications_test_core'] = new Communications_Test_Core();
$wpdb->query( 'CREATE TABLE ' . $wpdb->prefix . 'comms_test_enrollments (family_uid varchar(191), study_year varchar(30), student_status varchar(20), student_status_name varchar(30)) ENGINE=InnoDB' );
$GLOBALS['communications_test_identities'] = array();
require dirname( __DIR__ ) . '/olama-messages.php';
Olama_Messages_Activator::create_tables();
Olama_Messages_Communications_DB::install();

function comm_assert( $condition, $label ) {
    if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $label ); }
    echo 'PASS: ' . $label . PHP_EOL;
}
function comm_throws( $callback, $label ) {
    try { $callback(); } catch ( Throwable $error ) { comm_assert( true, $label ); return; }
    comm_assert( false, $label );
}
function comm_user( $type, $external ) {
    $id = wp_insert_user( array( 'user_login' => 'comm_' . $type . '_' . $external, 'user_pass' => wp_generate_password( 30 ), 'role' => 'subscriber' ) );
    if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
    $account = get_user_by( 'id', $id );
    foreach ( array( 'olama_messages_use', 'olama_messages_manage_campaigns', 'olama_messages_configure' ) as $cap ) { $account->add_cap( $cap ); }
    $GLOBALS['communications_test_identities'][$id] = array( 'wp_user_id' => $id, 'identity_type' => $type, 'oracle_identifier' => (string) $external, 'account_status' => 'active' );
    return $id;
}
