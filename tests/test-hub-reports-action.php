<?php
define( 'ABSPATH', __DIR__ . '/' );

function __( $text, $domain = '' ) { return $text; }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); }

require_once dirname( __DIR__ ) . '/includes/class-olama-messages-plugin.php';

$plugin = Olama_Messages_Plugin::instance();
$cards = array(
	array(
		'id'       => 'another-module',
		'submenus' => array(),
	),
	array(
		'id'       => 'olama-messages',
		'submenus' => array(
			array( 'id' => 'messages.dashboard' ),
		),
	),
);

$cards = $plugin->add_hub_reports_action( $cards );
$cards = $plugin->add_hub_reports_action( $cards );
$reports = array_values(
	array_filter(
		$cards[1]['submenus'],
		static function ( $submenu ) {
			return 'messages.reports' === ( $submenu['id'] ?? '' );
		}
	)
);

if ( 1 !== count( $reports ) ) {
	fwrite( STDERR, "Reports should be added to the Messages Hub card exactly once.\n" );
	exit( 1 );
}
if ( 'https://example.test/wp-admin/admin.php?page=olama-messages-reports' !== $reports[0]['url'] ) {
	fwrite( STDERR, "Reports should link to the registered Messages report page.\n" );
	exit( 1 );
}
if ( 'olama_access_messages' !== $reports[0]['capability'] ) {
	fwrite( STDERR, "Reports should use the Messages access capability.\n" );
	exit( 1 );
}

echo "Olama Hub Reports action: PASS\n";
