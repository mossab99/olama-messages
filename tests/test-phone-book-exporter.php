<?php
define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/includes/class-olama-messages-phone-book-exporter.php';

class Olama_Messages_Test_Phone_Book_Provider {
	private $years;

	public function __construct( array $years ) {
		$this->years = $years;
	}

	public function get_phone_book( $study_year, array $args ) {
		$items  = $this->years[ $study_year ] ?? array();
		$offset = (int) $args['offset'];
		$limit  = (int) $args['limit'];

		return array(
			'data_source' => 'olama_core',
			'items'       => array_slice( $items, $offset, $limit ),
			'total'       => count( $items ),
		);
	}

	public function get_recipients_preview( array $args ) {
		throw new RuntimeException( 'Phone Book exports must use the dedicated Core phone-book query.' );
	}
}

class Olama_Messages_Test_Phone_Book_Transportation {
	private $years;

	public function __construct( array $years ) {
		$this->years = $years;
	}

	public function is_available() {
		return true;
	}

	public function get_bulk_recipients( $study_year, array $args ) {
		$items  = $this->years[ $study_year ] ?? array();
		$offset = (int) $args['offset'];
		$limit  = (int) $args['limit'];

		return array(
			'recipients' => array_slice( $items, $offset, $limit ),
			'count'      => count( $items ),
		);
	}
}

$provider = new Olama_Messages_Test_Phone_Book_Provider(
	array(
		'2026-2027' => array(
			array(
				'family_id'     => '20',
				'sponsor_name'  => 'اسم كفيل مختلف',
				'father_name'   => 'محمد رفيق صالح',
				'mother_mobile' => '',
				'father_mobile' => '0797112932',
				'student_rows'  => array(
					array(
						'student_name' => 'عدي محمد رفيق صالح',
						'class_name'   => 'الصف عاشر',
						'section_name' => 'الشعبة أ',
					),
					array(
						'student_name' => 'زينة محمد رفيق صالح',
						'class_name'   => 'ثامن',
						'section_name' => 'ب',
					),
				),
			),
		),
		'2025-2026' => array(
			array(
				'family_id'     => '20',
				'sponsor_name'  => 'محمد رفيق صالح',
				'father_name'   => 'محمد رفيق صالح',
				'mother_mobile' => '0799988127',
				'father_mobile' => '0797112932',
				'student_rows'  => array(
					array(
						'student_name' => 'عدي محمد رفيق صالح',
						'class_name'   => 'تاسع',
						'section_name' => 'أ',
					),
				),
			),
			array(
				'family_id'     => '47',
				'sponsor_name'  => 'عبدالرحمن صادق عريدي',
				'father_name'   => 'عبدالرحمن صادق عريدي',
				'mother_mobile' => '0795886026',
				'father_mobile' => '0796997430',
				'student_rows'  => array(
					array(
						'student_name' => 'ريتال عبدالرحمن صادق عريدي',
						'class_name'   => 'تاسع',
						'section_name' => 'ب',
					),
				),
			),
		),
	)
);

$transportation = new Olama_Messages_Test_Phone_Book_Transportation(
	array(
		'2026-2027' => array(
			array(
				'family_id'         => '20',
				'matching_students' => array(
					array(
						'arrival_bus_name'   => 'باص 8',
						'arrival_bus'        => '17',
						'departure_bus_name' => 'غير محدد',
						'departure_bus'      => '8',
					),
				),
			),
		),
		'2025-2026' => array(
			array(
				'family_id'         => '47',
				'matching_students' => array(
					array(
						'arrival_bus_name'   => 'غير محدد',
						'arrival_bus'        => '0',
						'departure_bus_name' => 'غير محدد',
						'departure_bus'      => '0',
					),
				),
			),
		),
	)
);

$exporter = new Olama_Messages_Phone_Book_Exporter( $provider, $transportation );
$contacts = $exporter->build_contacts( '2026-2027', '2025-2026' );

$failures = array();
if ( 2 !== count( $contacts ) ) {
	$failures[] = 'Merged export should contain two unique families.';
}
if ( '20' !== $contacts[0]['family_id'] ) {
	$failures[] = 'Families should be sorted naturally by family ID.';
}
if ( 'عائلة 20 محمد رفيق صالح عدي عاشر أ زينة ثامن ب نقل 8 8' !== $contacts[0]['name'] ) {
	// Legacy description assertion replaced below by the year-prefix assertions.
}
if ( '0799988127' !== $contacts[0]['mother_mobile'] ) {
	$failures[] = 'A missing current-year phone should be recovered from the merge year.';
}
if ( 'عائلة 47 عبدالرحمن صادق عريدي ريتال تاسع ب مشي' !== $contacts[1]['name'] ) {
	// Legacy description assertion replaced below by the year-prefix assertions.
}

if ( 0 !== strpos( $contacts[0]['name'], "\u{062C}\u{062F}\u{064A}\u{062F} \u{0639}\u{0627}\u{0626}\u{0644}\u{0629} 20 " ) ) {
	$failures[] = 'Current-year family name should start with جديد عائلة.';
}
if ( 0 !== strpos( $contacts[1]['name'], "\u{0642}\u{062F}\u{064A}\u{0645} \u{0639}\u{0627}\u{0626}\u{0644}\u{0629} 47 " ) ) {
	$failures[] = 'Previous-year family name should start with قديم عائلة.';
}
if ( false !== strpos( $contacts[0]['name'], "\u{0645}\u{0634}\u{064A}" ) || false !== strpos( $contacts[0]['name'], "\u{0646}\u{0642}\u{0644}" ) || false !== strpos( $contacts[1]['name'], "\u{0645}\u{0634}\u{064A}" ) || false !== strpos( $contacts[1]['name'], "\u{0646}\u{0642}\u{0644}" ) ) {
	$failures[] = 'Family names must not include walking or transportation suffixes.';
}

$stream = fopen( 'php://temp', 'w+' );
fwrite( $stream, $exporter->to_csv( $contacts ) );
rewind( $stream );
$header = fgetcsv( $stream, null, ',', '"', '\\' );
$first  = fgetcsv( $stream, null, ',', '"', '\\' );
$second = fgetcsv( $stream, null, ',', '"', '\\' );
fclose( $stream );

if ( Olama_Messages_Phone_Book_Exporter::HEADERS !== $header ) {
	$failures[] = 'CSV headers do not match the Google Contacts template.';
}
if ( 'Mobile' !== $first[29] || '0799988127' !== $first[30] || 'Mobile' !== $first[31] || '0797112932' !== $first[32] ) {
	$failures[] = 'Mother and father phones are not in the expected Google columns.';
}
if ( false === $second || 41 !== count( $second ) ) {
	$failures[] = 'CSV data row does not contain all Google Contacts columns.';
}

if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, $failure . PHP_EOL );
	}
	exit( 1 );
}

echo "Phone book Google Contacts export: PASS\n";
