<?php
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

function sanitize_text_field( $value ) { return trim( (string) $value ); }
function esc_sql( $value ) { return (string) $value; }

class Olama_Messages_Test_Report_Wpdb {
	public $queries = array();

	public function prepare( $sql, ...$values ) {
		return array( 'sql' => $sql, 'values' => $values );
	}

	public function get_results( $query, $format ) {
		$this->queries[] = $query;
		if ( false !== strpos( $query['sql'], 'SELECT DISTINCT' ) ) {
			return array(
				array( 'class_id' => '10', 'class_name' => 'Grade 10', 'section_id' => 'A', 'section_name' => 'Section A' ),
			);
		}

		return array(
			array( 'student_uid' => 'student-1', 'student_name' => 'Student One', 'oracle_family_id' => '44', 'mother_mobile' => '0790000000' ),
		);
	}
}

class Olama_Messages_Test_Report_Core {
	public function audiences() { return $this; }
	public function is_ready() { return true; }
	public function read_models() { return $this; }
	public function table( $model ) { return 'wp_olama_core_' . $model; }
}

function olama_core() { return $GLOBALS['olama_messages_test_report_core']; }

$GLOBALS['wpdb'] = new Olama_Messages_Test_Report_Wpdb();
$GLOBALS['olama_messages_test_report_core'] = new Olama_Messages_Test_Report_Core();
require_once dirname( __DIR__ ) . '/includes/class-olama-messages-core-provider.php';

$provider = new Olama_Messages_Core_Provider();
$options = $provider->get_section_report_options( '2026-2027' );
$rows = $provider->get_section_users_report( '2026-2027', '10', 'A' );
$failures = array();

if ( 'Grade 10' !== ( $options[0]['class_name'] ?? '' ) || 'Section A' !== ( $options[0]['section_name'] ?? '' ) ) {
	$failures[] = 'The provider should return exact grade/section pairs.';
}
if ( 'Student One' !== ( $rows[0]['student_name'] ?? '' ) || '44' !== ( $rows[0]['oracle_family_id'] ?? '' ) || '0790000000' !== ( $rows[0]['mother_mobile'] ?? '' ) ) {
	$failures[] = 'The report should return student, family ID, and mother mobile fields.';
}
$report_query = $GLOBALS['wpdb']->queries[1] ?? array( 'sql' => '', 'values' => array() );
if ( false === strpos( $report_query['sql'], 'f.is_active = 1' ) || false === strpos( $report_query['sql'], 'BINARY sy.class_id = BINARY %s' ) ) {
	$failures[] = 'The report query should require an active family and exact grade/section IDs.';
}
if ( array( '2026-2027', '10', 'A' ) !== $report_query['values'] ) {
	$failures[] = 'The report query should prepare all user-controlled filters.';
}

if ( $failures ) {
	fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
	exit( 1 );
}

echo "Section users report: PASS\n";
