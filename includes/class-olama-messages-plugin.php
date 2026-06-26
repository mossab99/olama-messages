<?php
/**
 * Plugin orchestrator — singleton that wires up all sub-components.
 *
 * @package Olama_Messages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Olama_Messages_Plugin {

	/** @var self|null */
	private static $instance = null;

	/** @var Olama_Messages_Core_Provider */
	private $provider;

	/** @var Olama_Messages_Token_Service */
	private $tokens;

	/** @var Olama_Messages_Template_Renderer */
	private $renderer;

	/** @var Olama_Messages_Financial_Api_Provider */
	private $financial;

	/** @var Olama_Messages_Phone_Normalizer */
	private $normalizer;

	/** @var Olama_Messages_Template_Service */
	private $templates;

	/** @var Olama_Messages_Campaign_Service */
	private $campaigns;

	/** @var Olama_Messages_Agent_Service */
	private $agents;

	/** @var bool */
	private $initialized = false;

	// ─── Singleton ───────────────────────────────────────────────────────────

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	// ─── Init ────────────────────────────────────────────────────────────────

	public function init() {
		if ( $this->initialized ) {
			return;
		}
		$this->initialized = true;

		// Maybe upgrade DB tables.
		if ( get_option( 'olama_msg_db_version' ) !== OLAMA_MSG_VERSION ) {
			Olama_Messages_Activator::activate();
		}

		// Admin.
		if ( is_admin() ) {
			$admin = new Olama_Messages_Admin( $this );
			$admin->init();
		}

		// Public.
		$public = new Olama_Messages_Public_Report( $this );
		$public->init();

		// Register rewrite rule on 'init' — $wp_rewrite is NOT available at plugins_loaded.
		add_action( 'init', array( $this, 'register_rewrite' ) );

		// Query vars can be registered at any time via filter.
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );

		// Register REST routes
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	// ─── Rewrite ─────────────────────────────────────────────────────────────

	/**
	 * Register the rewrite rule for the public payment report URL.
	 * Must run on the WordPress 'init' hook — $wp_rewrite is null at plugins_loaded.
	 */
	public function register_rewrite() {
		add_rewrite_rule(
			'^olama-payment-report/([A-Za-z0-9._-]+)/?$',
			'index.php?olama_payment_token=$matches[1]',
			'top'
		);
	}

	/**
	 * Add the plugin's custom query var to WordPress.
	 */
	public function add_query_vars( $vars ) {
		$vars[] = 'olama_payment_token';
		return $vars;
	}

	// ─── Service accessors ───────────────────────────────────────────────────

	/**
	 * @return Olama_Messages_Core_Provider
	 */
	public function provider() {
		if ( ! $this->provider ) {
			$this->provider = new Olama_Messages_Core_Provider();
		}
		return $this->provider;
	}

	/**
	 * @return Olama_Messages_Token_Service
	 */
	public function tokens() {
		if ( ! $this->tokens ) {
			$this->tokens = new Olama_Messages_Token_Service();
		}
		return $this->tokens;
	}

	/**
	 * @return Olama_Messages_Template_Renderer
	 */
	public function renderer() {
		if ( ! $this->renderer ) {
			$this->renderer = new Olama_Messages_Template_Renderer();
		}
		return $this->renderer;
	}

	/**
	 * @return Olama_Messages_Financial_Api_Provider
	 */
	public function financial() {
		if ( ! $this->financial ) {
			$this->financial = new Olama_Messages_Financial_Api_Provider();
		}
		return $this->financial;
	}

	/**
	 * @return Olama_Messages_Phone_Normalizer
	 */
	public function normalizer() {
		if ( ! $this->normalizer ) {
			$this->normalizer = new Olama_Messages_Phone_Normalizer();
		}
		return $this->normalizer;
	}

	/**
	 * @return Olama_Messages_Template_Service
	 */
	public function templates() {
		if ( ! $this->templates ) {
			$this->templates = new Olama_Messages_Template_Service();
		}
		return $this->templates;
	}

	/**
	 * @return Olama_Messages_Campaign_Service
	 */
	public function campaigns() {
		if ( ! $this->campaigns ) {
			$this->campaigns = new Olama_Messages_Campaign_Service();
		}
		return $this->campaigns;
	}

	/**
	 * Register agent REST API routes.
	 */
	public function register_rest_routes() {
		$controller = new Olama_Messages_Agent_Rest_Controller();
		$controller->register_routes();
	}

	/**
	 * @return Olama_Messages_Agent_Service
	 */
	public function agents() {
		if ( ! $this->agents ) {
			$this->agents = new Olama_Messages_Agent_Service();
		}
		return $this->agents;
	}
}
