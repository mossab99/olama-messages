<?php
define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/includes/class-olama-messages-phone-normalizer.php';

$normalizer = new Olama_Messages_Phone_Normalizer();
$cases = array(
	array( '', false, null, 'empty_phone' ),
	array( '0791234567', true, '+962791234567', null ),
	array( '+962 79 123 4567', true, '+962791234567', null ),
	array( '962791234567', true, '+962791234567', null ),
	array( '+966501234567', false, null, 'international_phone' ),
	array( '00971501234567', false, null, 'international_phone' ),
	array( '966501234567', false, null, 'international_phone' ),
	array( '061234567', false, null, 'landline_rejected' ),
	array( '12345', false, null, 'invalid_mobile' ),
);

$failures = 0;
foreach ( $cases as $index => $case ) {
	$result = $normalizer->normalize_jordan_mobile( $case[0] );
	if ( $result['valid'] !== $case[1] || $result['e164'] !== $case[2] || $result['reason'] !== $case[3] ) {
		fwrite( STDERR, "Case {$index} failed: " . json_encode( $result ) . PHP_EOL );
		$failures++;
	}
}

if ( $failures ) {
	exit( 1 );
}

echo "PHP phone normalization: PASS\n";
