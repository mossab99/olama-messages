<?php
/**
 * Plugin Name: Olama Messages
 * Plugin URI:  https://olama.edu.jo/
 * Description: Tokenized payment report links and SMS template preview for the Olama school ecosystem.
 * Version:     2.5.0
 * Author:      Olama
 * Text Domain: olama-messages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Constants ───────────────────────────────────────────────────────────────
define( 'OLAMA_MSG_VERSION',  '2.5.0' );
define( 'OLAMA_MSG_FILE',     __FILE__ );
define( 'OLAMA_MSG_PATH',     plugin_dir_path( __FILE__ ) );
define( 'OLAMA_MSG_URL',      plugin_dir_url( __FILE__ ) );
define( 'OLAMA_MSG_BASENAME', plugin_basename( __FILE__ ) );

// ─── Autoload includes ────────────────────────────────────────────────────────
require_once OLAMA_MSG_PATH . 'includes/class-olama-messages-activator.php';
require_once OLAMA_MSG_PATH . 'includes/class-olama-messages-phone-normalizer.php';
require_once OLAMA_MSG_PATH . 'includes/class-olama-messages-template-service.php';
require_once OLAMA_MSG_PATH . 'includes/class-olama-messages-campaign-service.php';
require_once OLAMA_MSG_PATH . 'includes/class-olama-messages-operations-service.php';
require_once OLAMA_MSG_PATH . 'includes/class-olama-messages-financial-api-provider.php';
require_once OLAMA_MSG_PATH . 'includes/class-olama-messages-core-provider.php';
require_once OLAMA_MSG_PATH . 'includes/class-olama-messages-transportation-service.php';
require_once OLAMA_MSG_PATH . 'includes/class-olama-messages-store-provider.php';

require_once OLAMA_MSG_PATH . 'includes/class-olama-messages-phone-book-exporter.php';
require_once OLAMA_MSG_PATH . 'includes/class-olama-messages-token-service.php';
require_once OLAMA_MSG_PATH . 'includes/class-olama-messages-short-link-service.php';
require_once OLAMA_MSG_PATH . 'includes/class-olama-messages-template-renderer.php';
require_once OLAMA_MSG_PATH . 'includes/class-olama-messages-agent-service.php';
require_once OLAMA_MSG_PATH . 'includes/class-olama-messages-agent-rest-controller.php';
require_once OLAMA_MSG_PATH . 'includes/class-olama-messages-dispatcher-service.php';
require_once OLAMA_MSG_PATH . 'includes/class-olama-messages-plugin.php';
require_once OLAMA_MSG_PATH . 'admin/class-olama-messages-admin.php';
require_once OLAMA_MSG_PATH . 'admin/class-olama-messages-modern-admin.php';
require_once OLAMA_MSG_PATH . 'public/class-olama-messages-public-report.php';

// ─── Activation / Deactivation ────────────────────────────────────────────────
register_activation_hook( __FILE__, array( 'Olama_Messages_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Olama_Messages_Activator', 'deactivate' ) );

// ─── Bootstrap ───────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', array( Olama_Messages_Plugin::instance(), 'init' ) );
