<?php
/** Real WP/MySQL render check in the existing disposable-database harness. */
require __DIR__ . '/communications-bootstrap.php';
require dirname( __DIR__, 2 ) . '/olama-core/includes/class-olama-core-migrator.php';
Olama_Core_Migrator::create_tables();
$account = comm_user( 'employee', 'collection-test' );
wp_set_current_user( $account );
$user = wp_get_current_user();
$user->add_cap( 'olama_access_messages' );
$user->add_cap( 'olama_manage_financial_accounts' );
$today = current_time( 'Y-m-d' ); $now = current_time( 'mysql' ); $year = '2026-2027';
$prefix = $wpdb->prefix . 'olama_core_';
foreach ( array( '459', '985' ) as $family ) {
    $uid = 'ORA-FAM-' . $family;
    $wpdb->insert( $prefix . 'families', array( 'family_uid' => $uid, 'oracle_family_id' => $family, 'sponsor_full_name' => 'عائلة ' . $family . ' <script>alert(1)</script>', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now ) );
    $wpdb->insert( $prefix . 'family_financial_years', array( 'family_uid' => $uid, 'oracle_family_id' => $family, 'study_year' => $year, 'balance' => $family === '459' ? 2121 : 960, 'last_synced_at' => $now, 'created_at' => $now, 'updated_at' => $now ) );
    foreach ( array( 1, 2 ) as $i ) {
        $student = $uid . '-' . $i;
        $wpdb->insert( $prefix . 'students', array( 'student_uid' => $student, 'family_uid' => $uid, 'oracle_family_id' => $family, 'oracle_student_id' => (string) $i, 'student_name' => 'طالب ' . $i, 'created_at' => $now, 'updated_at' => $now ) );
        $wpdb->insert( $prefix . 'student_years', array( 'student_uid' => $student, 'family_uid' => $uid, 'study_year' => $year, 'created_at' => $now, 'updated_at' => $now ) );
        $wpdb->insert( $prefix . 'student_transportation', array( 'student_uid' => $student, 'family_uid' => $uid, 'oracle_family_id' => $family, 'oracle_student_id' => (string) $i, 'study_year' => $year, 'is_active' => 1, 'class_name' => 'السادس', 'section_name' => 'أ', 'departure_bus_name' => 'باص 5', 'last_synced_at' => $now, 'created_at' => $now, 'updated_at' => $now ) );
    }
    $gross = $family === '459' ? array( 628.710, 330.165, 358.875 ) : array( 365.100, 173.650, 188.750 );
    $discount = $family === '459' ? array( 190.189, 99.877, 108.562 ) : array( 88.331, 42.012, 45.665 );
    $paid = $family === '459' ? array( 300, 0, 0 ) : array( 276.769, 131.638, 41.593 );
    foreach ( array( -68, -38, -7, 23, 54, 85, 113, 144, 174 ) as $i => $days ) {
        $j = min( 2, $i ); $d = $discount[$j]; $p = $i < 3 ? $paid[$i] : 0;
        if ( $family === '985' && $i === 8 ) { $d = 45.667; }
        $wpdb->insert( $prefix . 'family_financial_dues', array( 'family_uid' => $uid, 'oracle_family_id' => $family, 'study_year' => $year, 'due_date' => ( new DateTimeImmutable( $today ) )->modify( $days . ' days' )->format( 'Y-m-d' ), 'due_amount' => $gross[$j], 'paid_amount' => $d, 'receipt_paid' => $p, 'balance' => round( $gross[$j] - $d - $p, 3 ), 'last_synced_at' => $now, 'created_at' => $now ) );
    }
}
comm_assert( ! $wpdb->last_error, 'Core schema accepts representative family, installment and transport data' );
update_option( Olama_Messages_Collection_Report::review_key( $year, '459' ), array( 'status' => 'clear', 'until' => $today, 'note' => 'تم التحقق من عدم وجود استثناء أو اتفاق سداد.', 'updated_at' => $now ) );
$rows = Olama_Messages_Collection_Report::load( $year, $today, Olama_Messages_Collection_Report::defaults() );
comm_assert( count( $rows ) === 2, 'Real SQL returns one row per family with two siblings' );
$indexed = array_column( $rows, null, 'oracle_family_id' );
comm_assert( $indexed['459']['action'] === 'candidate' && $indexed['985']['action'] === 'reminder', 'Real SQL assessment matches both ERP examples' );
$_GET = array( 'page' => 'olama-messages-reports', 'report' => 'collection', 'study_year' => $year ); $_POST = array();
ob_start(); Olama_Messages_Collection_Report_Page::render(); $html = ob_get_clean();
comm_assert( strpos( $html, '619.122' ) !== false && strpos( $html, '101.492' ) !== false && strpos( $html, '81.60%' ) !== false, 'Arabic report renders verified balances and coverage' );
comm_assert( strpos( $html, '<script>alert(1)</script>' ) === false && strpos( $html, '&lt;script&gt;' ) !== false, 'Family names and administrative notes are escaped' );
comm_assert( substr_count( $html, 'name="collection_operation" value="review"' ) === 2 && strpos( $html, 'name="_wpnonce"' ) !== false, 'Each review form is protected by a WordPress nonce' );
comm_assert( strpos( $html, 'name="collection_operation" value="policy"' ) === false, 'Financial staff can preview but cannot save global policy' );
$document = new DOMDocument();
@$document->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
$xpath = new DOMXPath( $document );
comm_assert( $xpath->query( '//dialog[contains(@class,"omcr-details")]' )->length === 2, 'Each family has a separate accessible details dialog' );
comm_assert( $xpath->query( '//table[contains(@class,"omcr-table")]//dialog' )->length === 0, 'Details forms are outside the scrolling table so opening them cannot expand or clip its columns' );
comm_assert( $xpath->query( '//dialog//form[contains(@class,"omcr-review")]' )->length === 2, 'Both administrative forms stay inside their family dialog' );
comm_assert( $xpath->query( '//button[@data-collection-dialog and @aria-controls and @aria-haspopup="dialog"]' )->length === 2, 'Details triggers explicitly target the corresponding family dialog' );
$_GET['family_id'] = '459'; ob_start(); Olama_Messages_Collection_Report_Page::render(); $filtered = ob_get_clean();
comm_assert( strpos( $filtered, '619.122' ) !== false && strpos( $filtered, '101.492' ) === false, 'Family filter scopes displayed debt and totals' );
$_GET['family_id'] = ''; $_GET['collection_action'] = 'candidate'; ob_start(); Olama_Messages_Collection_Report_Page::render(); $filtered = ob_get_clean();
comm_assert( strpos( $filtered, '619.122' ) !== false && strpos( $filtered, '101.492' ) === false, 'Recommendation filter scopes reviewed transport candidates' );
add_filter( 'wp_die_handler', static function () { return static function ( $message ) { throw new RuntimeException( is_scalar( $message ) ? (string) $message : 'Request denied' ); }; } );
$_GET = array( 'collection_export' => 'families', '_wpnonce' => 'invalid' ); $_REQUEST = $_GET;
comm_throws( static function () { Olama_Messages_Collection_Report_Page::handle(); }, 'CSV export rejects an invalid nonce' );
$_GET = array(); $_POST = array( 'collection_operation' => 'policy', '_wpnonce' => wp_create_nonce( 'olama_msg_collection_write' ) ); $_REQUEST = $_POST;
comm_throws( static function () { Olama_Messages_Collection_Report_Page::handle(); }, 'Financial staff cannot save global thresholds even with a valid nonce' );
$_POST = array(); $_GET = array( 'collection_export' => 'families' ); $_REQUEST = $_GET;
$user->remove_cap( 'olama_manage_financial_accounts' );
comm_throws( static function () { Olama_Messages_Collection_Report_Page::handle(); }, 'CSV export rejects Messages-only users before querying financial data' );
comm_throws( static function () { Olama_Messages_Collection_Report_Page::render(); }, 'Direct report rendering rejects Messages-only users' );
$user->add_cap( 'olama_manage_financial_accounts' ); $_GET = array(); $_POST = array(); $_REQUEST = array();
$out = getenv( 'OLAMA_COLLECTION_FIXTURE' );
if ( $out ) {
    $head = '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>معاينة تقرير التحصيل</title>';
    foreach ( array( ABSPATH . 'wp-admin/css/common.css', ABSPATH . 'wp-includes/css/buttons.css', ABSPATH . 'wp-admin/css/forms.css', dirname( __DIR__ ) . '/assets/admin.css', dirname( __DIR__ ) . '/assets/collection-report.css' ) as $css ) { $head .= '<style>' . file_get_contents( $css ) . '</style>'; }
    $head .= '<style>body{background:#f3f6fb;font-family:Tahoma,Arial,sans-serif;margin:0;padding:20px}.omsg-panel{background:white;border:1px solid #e2e8f0;border-radius:12px}.omsg-table-scroll{overflow-x:auto}.wrap{margin:0}h1{font-size:26px}table{border-collapse:collapse}th,td{text-align:right}.nav-tab-wrapper{display:flex;gap:15px}.nav-tab{padding:14px;background:white;text-decoration:none;border-radius:8px}.nav-tab-active{border-bottom:3px solid #2563eb}</style></head><body>';
    file_put_contents( $out, $head . $html . '</body></html>' );
}
echo "Collection report WordPress/MySQL integration PASS\n";
