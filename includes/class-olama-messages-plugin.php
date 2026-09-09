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
	private $short_links;

	/** @var Olama_Messages_Template_Renderer */
	private $renderer;

	/** @var Olama_Messages_Financial_Api_Provider */
	private $financial;

	/** @var Olama_Messages_Transportation_Service */
	private $transportation;

	/** @var Olama_Messages_Store_Provider */
	private $store_provider;

	/** @var Olama_Messages_Phone_Book_Exporter */
	private $phone_book_exporter;

	/** @var Olama_Messages_Phone_Normalizer */
	private $normalizer;

	/** @var Olama_Messages_Template_Service */
	private $templates;

	/** @var Olama_Messages_Campaign_Service */
	private $campaigns;
	private $operations;

	/** @var Olama_Messages_Agent_Service */
	private $agents;

	/** @var Olama_Messages_Dispatcher_Service */
	private $dispatcher;

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

		// Oracle credentials belong only to Olama Oracle Sync. Remove legacy
		// Messages options after migrating to the Core service layer.
		foreach ( array( 'olama_msg_oracle_base_url', 'olama_msg_oracle_api_key', 'olama_msg_api_timeout', 'olama_msg_financial_enabled' ) as $legacy_option ) {
			delete_option( $legacy_option );
		}

		// Maybe upgrade DB tables.
		if ( get_option( 'olama_msg_db_version' ) !== OLAMA_MSG_LEGACY_DB_VERSION ) {
			Olama_Messages_Activator::activate();
		}

		( new Olama_Messages_Communications() )->init();

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

		// Recover reservations even when the desktop dispatcher has stopped polling.
		add_filter( 'cron_schedules', array( $this, 'register_cron_schedules' ) );
		add_action( 'olama_msg_dispatcher_maintenance', array( $this, 'run_dispatcher_maintenance' ) );
		if ( ! wp_next_scheduled( 'olama_msg_dispatcher_maintenance' ) ) {
			wp_schedule_event( time() + 60, 'olama_msg_every_minute', 'olama_msg_dispatcher_maintenance' );
		}
	}

	public function register_cron_schedules( $schedules ) {
		$schedules['olama_msg_every_minute'] = array(
			'interval' => 60,
			'display'  => __( 'Every minute (Olama Messages)', 'olama-messages' ),
		);
		return $schedules;
	}

	public function run_dispatcher_maintenance() {
		$this->dispatcher()->cleanup_stale_reservations( 180 );
		$this->dispatcher()->reconcile_sending_campaigns();
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
		add_rewrite_rule( '^p/([A-Za-z0-9]{8})/?$', 'index.php?olama_short_code=$matches[1]', 'top' );
	}

	/**
	 * Add the plugin's custom query var to WordPress.
	 */
	public function add_query_vars( $vars ) {
		$vars[] = 'olama_payment_token';
		$vars[] = 'olama_short_code';
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

	/** @return Olama_Messages_Short_Link_Service */
	public function short_links() {
		if ( ! $this->short_links ) {
			$this->short_links = new Olama_Messages_Short_Link_Service();
		}
		return $this->short_links;
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
	 * @return Olama_Messages_Transportation_Service
	 */
	public function transportation() {
		if ( ! $this->transportation ) {
			$this->transportation = new Olama_Messages_Transportation_Service();
		}
		return $this->transportation;
	}

	/**
	 * @return Olama_Messages_Store_Provider
	 */
	public function store_provider() {
		if ( ! $this->store_provider ) {
			$this->store_provider = new Olama_Messages_Store_Provider();
		}
		return $this->store_provider;
	}

	/**
	 * @return Olama_Messages_Phone_Book_Exporter
	 */
	public function phone_book_exporter() {
		if ( ! $this->phone_book_exporter ) {
			$this->phone_book_exporter = new Olama_Messages_Phone_Book_Exporter(
				$this->provider(),
				$this->transportation()
			);
		}
		return $this->phone_book_exporter;
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

	/** @return Olama_Messages_Operations_Service */
	public function operations() {
		if ( ! $this->operations ) {
			$this->operations = new Olama_Messages_Operations_Service();
		}
		return $this->operations;
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

	/**
	 * @return Olama_Messages_Dispatcher_Service
	 */
	public function dispatcher() {
		if ( ! $this->dispatcher ) {
			$this->dispatcher = new Olama_Messages_Dispatcher_Service();
		}
		return $this->dispatcher;
	}
}
