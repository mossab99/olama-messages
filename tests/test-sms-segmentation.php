<?php
define( 'ABSPATH', __DIR__ . '/' );
function get_option( $key, $default = false ) { return $default; }
require_once dirname( __DIR__ ) . '/includes/class-olama-messages-template-renderer.php';

$renderer = new Olama_Messages_Template_Renderer();
$cases = array(
	array( '', 'gsm7', 0, 0 ),
	array( str_repeat( 'A', 160 ), 'gsm7', 160, 1 ),
	array( str_repeat( 'A', 161 ), 'gsm7', 161, 2 ),
	array( str_repeat( '^', 80 ), 'gsm7', 160, 1 ),
	array( str_repeat( '^', 81 ), 'gsm7', 162, 2 ),
	array( str_repeat( 'ش', 70 ), 'unicode', 70, 1 ),
	array( str_repeat( 'ش', 71 ), 'unicode', 71, 2 ),
	array( 'hello 😀', 'unicode', 7, 1 ),
);

$failures = 0;
foreach ( $cases as $index => $case ) {
	$result = $renderer->sms_info( $case[0] );
	if ( $result['encoding'] !== $case[1] || $result['encoded_units'] !== $case[2] || $result['sms_parts'] !== $case[3] ) {
		fwrite( STDERR, "Case {$index} failed: " . json_encode( $result ) . PHP_EOL );
		$failures++;
	}
}
if ( $failures ) {
	exit( 1 );
}
echo "PHP SMS segmentation: PASS\n";
