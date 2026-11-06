<?php
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
$caps = array(); $options = array();
function current_user_can( $cap ) { return ! empty( $GLOBALS['caps'][$cap] ); }
function get_option( $key, $default = false ) { return $GLOBALS['options'][$key] ?? $default; }
function olama_core() { return new stdClass(); }
require dirname( __DIR__ ) . '/includes/class-olama-messages-collection-report.php';
function check( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function due( $date, $gross, $discount = 0, $paid = 0 ) {
    return array( 'family_uid' => 'ORA-FAM-459', 'due_date' => $date, 'due_amount' => $gross, 'paid_amount' => $discount, 'receipt_paid' => $paid, 'balance' => round( $gross - $discount - $paid, 3 ), 'last_synced_at' => '2026-11-07 00:00:00' );
}
$policy = Olama_Messages_Collection_Report::defaults(); $today = '2026-11-07';
$family459 = array( due( '2026-08-31', 628.710, 190.189, 300 ), due( '2026-09-30', 330.165, 99.877 ) );
foreach ( array( '2026-10-31', '2026-11-30', '2026-12-31', '2027-01-31', '2027-02-28', '2027-03-31', '2027-04-30' ) as $date ) { $family459[] = due( $date, 358.875, 108.562 ); }
$a = Olama_Messages_Collection_Report::assess( $family459, $today, $policy );
check( $a['due'] === 919122 && $a['covered'] === 300000 && $a['overdue'] === 619122 && $a['annual_balance'] === 2121000, '459 must match the ERP sample exactly in fils.' );
check( abs( $a['coverage'] - 32.639845 ) < 0.00001 && $a['typical'] === 250313 && $a['oldest'] === 68 && $a['financial_match'], '459 coverage, median, age and eligibility.' );
check( $a['partial'] === 1 && $a['unpaid'] === 2 && ! $a['warnings'], 'Discounts must never count as payments.' );
$family985 = array( due( '2026-08-31', 365.100, 88.331, 276.769 ), due( '2026-09-30', 173.650, 42.012, 131.638 ), due( '2026-10-31', 188.750, 45.665, 41.593 ) );
foreach ( array( '2026-11-30', '2026-12-31', '2027-01-31', '2027-02-28', '2027-03-31' ) as $date ) { $family985[] = due( $date, 188.750, 45.665 ); }
$family985[] = due( '2027-04-30', 188.750, 45.667 );
$b = Olama_Messages_Collection_Report::assess( $family985, $today, $policy );
check( $b['due'] === 551492 && $b['covered'] === 450000 && $b['overdue'] === 101492 && $b['annual_balance'] === 960000, '985 must match the ERP sample.' );
check( $b['oldest'] === 7 && ! $b['financial_match'] && $b['partial'] === 1 && $b['unpaid'] === 0 && abs( $b['coverage'] - 81.59683 ) < 0.00001, '985 is a recent partial shortfall, not a suspension candidate.' );
$boundary = array( due( '2026-10-08', 100 ), due( '2026-10-31', 100 ) );
$c = Olama_Messages_Collection_Report::assess( $boundary, $today, $policy );
check( $c['financial_match'] && $c['equivalent'] === 2 && $c['oldest'] === 30, 'Exact two-installment and 30-day boundary is inclusive.' );
$boundary[0]['due_date'] = '2026-10-09';
check( ! Olama_Messages_Collection_Report::assess( $boundary, $today, $policy )['financial_match'], '29 days must not qualify.' );
$boundary[0]['due_date'] = '2026-10-08'; $boundary[1]['balance'] = 99.999; $boundary[1]['receipt_paid'] = .001;
check( ! Olama_Messages_Collection_Report::assess( $boundary, $today, $policy )['financial_match'], 'One fils below the two-installment boundary must not qualify.' );
$tiny = Olama_Messages_Collection_Report::assess( array( due( '2026-08-31', 100, 0, 99.5 ), due( '2026-10-31', 100 ) ), $today, $policy );
check( $tiny['oldest'] === 7 && $tiny['overdue'] === 100500 && $tiny['outstanding'] === 2, 'Tolerance must not erase balances or let a tiny old residual age current debt.' );
$advance = Olama_Messages_Collection_Report::assess( array( due( '2026-11-30', 100, 0, 100 ) ), $today, $policy );
check( $advance['due'] === 0 && $advance['coverage'] === null && $advance['overdue'] === 0 && ! $advance['financial_match'], 'Nothing due yet, including advance coverage.' );
$paid = Olama_Messages_Collection_Report::assess( array( due( '2026-10-01', 100, 0, 100 ), due( '2027-01-01', 100 ) ), $today, $policy );
check( $paid['coverage'] === 100 && ! $paid['financial_match'], 'Annual unpaid balance cannot flag a fully covered current schedule.' );
$missing = Olama_Messages_Collection_Report::assess( array(), $today, $policy );
check( $missing['warnings'] && $missing['equivalent'] === null, 'Missing schedule is uncertain.' );
$bad = due( 'invalid', 100 );
check( Olama_Messages_Collection_Report::assess( array( $bad ), $today, $policy )['warnings'], 'Invalid dates must be flagged.' );
$bad = due( '2026-10-01', 100 ); $bad['balance'] = 50;
check( Olama_Messages_Collection_Report::assess( array( $bad ), $today, $policy )['warnings'], 'Inconsistent source allocations must be flagged.' );
check( Olama_Messages_Collection_Report::assess( $family459, $today, $policy, 400 )['typical'] === 400000, 'Authorized override determines installment burden.' );
$clear = array( 'status' => 'clear', 'until' => $today );
check( Olama_Messages_Collection_Report::recommendation( $a, array(), $today, 2 ) === 'pending', 'No exception record does not prove clearance.' );
check( Olama_Messages_Collection_Report::recommendation( $a, $clear, $today, 2 ) === 'candidate', 'Fresh clearance and active transport yield review candidate.' );
check( Olama_Messages_Collection_Report::recommendation( $a, array( 'status' => 'exception', 'until' => $today ), $today, 2 ) === 'exempt', 'Approved exception prevents candidacy.' );
check( Olama_Messages_Collection_Report::recommendation( $a, array( 'status' => 'arrangement', 'until' => $today ), $today, 2 ) === 'exempt', 'Compliant arrangement prevents candidacy.' );
check( Olama_Messages_Collection_Report::recommendation( $a, array( 'status' => 'clear', 'until' => '2026-11-06' ), $today, 2 ) === 'pending', 'Expired clearance must not qualify.' );
check( Olama_Messages_Collection_Report::recommendation( $a, $clear, $today, 0 ) === 'collection', 'No active transport means financial follow-up only.' );
check( Olama_Messages_Collection_Report::recommendation( $a, $clear, $today, 0, true ) === 'pending', 'Unknown subscription cannot qualify.' );
$warn = $a; $warn['warnings'][] = 'stale';
check( Olama_Messages_Collection_Report::recommendation( $warn, $clear, $today, 1 ) === 'incomplete', 'Uncertain data blocks candidacy.' );
foreach ( array( '=1+1', '+cmd', '-1+2', '@SUM(1)', "\t=cmd", '  =1+1', "\xEF\xBB\xBF=1+1" ) as $cell ) { check( substr( Olama_Messages_Collection_Report::csv_cell( $cell ), 0, 1 ) === "'", 'CSV formula injection must be neutralized.' ); }
check( Olama_Messages_Collection_Report::csv_cell( 'عائلة 459' ) === 'عائلة 459', 'Plain Arabic CSV cells remain intact.' );
$caps = array( 'olama_access_messages' => true ); check( ! Olama_Messages_Collection_Report::can_view(), 'Messages access alone must not reveal finances.' );
$caps['olama_manage_financial_accounts'] = true; check( Olama_Messages_Collection_Report::can_view(), 'Financial accounts role can view.' );
$caps = array( 'manage_options' => true ); check( ! Olama_Messages_Collection_Report::can_view(), 'Messages permission is also required.' );
$caps['olama_access_messages'] = true; check( Olama_Messages_Collection_Report::can_view(), 'Authorized admin can view.' );

// Bulk-read integration: siblings, current/expired subscriptions, and stale data.
class Collection_Test_DB {
    public $prefix = 'wp_'; public $last_error = ''; public $stale = false; public $future = false;
    public function prepare( $sql, ...$args ) { return $sql; }
    public function get_results( $sql, $format ) {
        if ( strpos( $sql, 'SELECT fy.*' ) !== false ) { return array( array( 'family_uid' => 'ORA-FAM-459', 'oracle_family_id' => '459', 'balance' => '2121', 'last_synced_at' => $this->stale ? '2026-10-29 00:00:00' : '2026-11-07 00:00:00', 'sponsor_full_name' => 'Family 459', 'father_name' => '' ) ); }
        if ( strpos( $sql, 'financial_dues' ) !== false ) { return $GLOBALS['family459']; }
        $base = array( 'family_uid' => 'ORA-FAM-459', 'student_uid' => 'a', 'student_id' => 1, 'student_name' => 'A', 'is_active' => 1, 'from_date' => '2026-08-01', 'to_date' => null, 'last_synced_at' => '2026-11-07 00:00:00' );
        return array( $base, $base, array_merge( $base, array( 'student_uid' => 'b', 'student_id' => 2 ) ), array_merge( $base, array( 'student_uid' => 'c', 'to_date' => '2026-11-06' ) ), array_merge( $base, array( 'student_uid' => 'd', 'is_active' => null ) ) );
    }
    public function get_col( $sql ) { return $this->future ? array( 'ORA-FAM-459' ) : array(); }
}
$wpdb = new Collection_Test_DB();
$options[Olama_Messages_Collection_Report::review_key( '2026-2027', '459' )] = $clear;
$loaded = Olama_Messages_Collection_Report::load( '2026-2027', $today, $policy );
check( count( $loaded ) === 1 && count( $loaded[0]['transport'] ) === 2 && $loaded[0]['overdue'] === 619122 && $loaded[0]['action'] === 'candidate', 'Siblings must not duplicate family debt; subscriptions must be active and distinct.' );
$wpdb->stale = true;
check( Olama_Messages_Collection_Report::load( '2026-2027', $today, $policy )[0]['action'] === 'incomplete', 'Stale summary blocks transport review export.' );
$wpdb->stale = false; $wpdb->future = true;
check( Olama_Messages_Collection_Report::load( '2026-2027', $today, $policy )[0]['action'] === 'incomplete', 'Future or undated transactions prevent asserting a current balance.' );
echo "Collection report: ERP examples, thresholds, allocations, exceptions, permissions, CSV, transport and freshness PASS\n";
