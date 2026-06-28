<?php
/**
 * Admin controller — registers menus and renders all 8 admin pages.
 *
 * Pages:
 *  1. Dashboard (updated with campaign/queue/template stats)
 *  2. Campaigns [NEW]
 *  3. New Campaign / Edit Campaign [NEW]
 *  4. Templates [NEW]
 *  5. SMS Dispatch Queue [NEW]
 *  6. Recipients Preview (from Phase 1.5)
 *  7. Payment Report Links (from Phase 1)
 *  8. Settings (from Phase 1.5)
 *
 * @package Olama_Messages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Olama_Messages_Admin {

	/** @var Olama_Messages_Plugin */
	private $plugin;

	public function __construct( Olama_Messages_Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	// ─── Init ────────────────────────────────────────────────────────────────

	public function init() {
		add_action( 'admin_menu',            array( $this, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Token / settings actions (Phase 1 / 1.5)
		add_action( 'admin_post_olama_msg_generate_token',  array( $this, 'handle_generate_token' ) );
		add_action( 'admin_post_olama_msg_generate_manual_token',  array( $this, 'handle_generate_manual_token' ) );
		add_action( 'admin_post_olama_msg_revoke_token',    array( $this, 'handle_revoke_token' ) );
		add_action( 'admin_post_olama_msg_delete_token',    array( $this, 'handle_delete_token' ) );
		add_action( 'admin_post_olama_msg_clear_all_tokens', array( $this, 'handle_clear_all_tokens' ) );
		add_action( 'admin_post_olama_msg_delete_queue_item', array( $this, 'handle_delete_queue_item' ) );
		add_action( 'admin_post_olama_msg_save_settings',   array( $this, 'handle_save_settings' ) );

		// Template actions (Phase 2)
		add_action( 'admin_post_olama_msg_save_template',   array( $this, 'handle_save_template' ) );
		add_action( 'admin_post_olama_msg_delete_template', array( $this, 'handle_delete_template' ) );

		// Campaign actions (Phase 2)
		add_action( 'admin_post_olama_msg_save_campaign',     array( $this, 'handle_save_campaign' ) );
		add_action( 'admin_post_olama_msg_delete_campaign',   array( $this, 'handle_delete_campaign' ) );
		add_action( 'admin_post_olama_msg_prepare_campaign',  array( $this, 'handle_prepare_campaign' ) );
		add_action( 'admin_post_olama_msg_reset_campaign',    array( $this, 'handle_reset_campaign' ) );
		add_action( 'admin_post_olama_msg_cancel_campaign',   array( $this, 'handle_cancel_campaign' ) );

		// Campaign sending lifecycle actions (Phase 4 Run 4B)
		add_action( 'admin_post_olama_msg_start_campaign',    array( $this, 'handle_start_campaign' ) );
		add_action( 'admin_post_olama_msg_pause_campaign',    array( $this, 'handle_pause_campaign' ) );
		add_action( 'admin_post_olama_msg_resume_campaign',   array( $this, 'handle_resume_campaign' ) );

		// Agent actions (Phase 3)
		add_action( 'admin_post_olama_msg_save_agent',        array( $this, 'handle_save_agent' ) );
		add_action( 'admin_post_olama_msg_revoke_agent',      array( $this, 'handle_revoke_agent' ) );
		add_action( 'admin_post_olama_msg_delete_agent',      array( $this, 'handle_delete_agent' ) );

		// Direct Message actions (Phase 4D Stabilization)
		add_action( 'admin_post_olama_msg_send_direct',         array( $this, 'handle_send_direct' ) );
		add_action( 'wp_ajax_olama_msg_search_families',        array( $this, 'ajax_search_families' ) );
		add_action( 'wp_ajax_olama_msg_render_direct_template',  array( $this, 'ajax_render_direct_template' ) );

		// AJAX actions
		add_action( 'wp_ajax_olama_msg_preview_sms',             array( $this, 'ajax_preview_sms' ) );
		add_action( 'wp_ajax_olama_msg_preview_report',          array( $this, 'ajax_preview_report' ) );
		add_action( 'wp_ajax_olama_msg_preview_campaign_ajax',   array( $this, 'ajax_preview_campaign' ) );
		add_action( 'wp_ajax_olama_msg_transport_route_options', array( $this, 'ajax_transport_route_options' ) );
		add_action( 'wp_ajax_olama_msg_campaign_progress',       array( $this, 'ajax_campaign_progress' ) );
	}

	// ─── Menus ───────────────────────────────────────────────────────────────

	public function register_menus() {
		add_menu_page(
			__( 'Olama Messages', 'olama-messages' ),
			__( 'Olama Messages', 'olama-messages' ),
			'manage_options',
			'olama-messages',
			array( $this, 'page_dashboard' ),
			'dashicons-email-alt',
			56
		);

		add_submenu_page(
			'olama-messages',
			__( 'Dashboard', 'olama-messages' ),
			__( 'Dashboard', 'olama-messages' ),
			'manage_options',
			'olama-messages',
			array( $this, 'page_dashboard' )
		);

		add_submenu_page(
			'olama-messages',
			__( 'Campaigns', 'olama-messages' ),
			__( 'Campaigns', 'olama-messages' ),
			'manage_options',
			'olama-messages-campaigns',
			array( $this, 'page_campaigns' )
		);

		add_submenu_page(
			'olama-messages',
			__( 'Templates', 'olama-messages' ),
			__( 'Templates', 'olama-messages' ),
			'manage_options',
			'olama-messages-templates',
			array( $this, 'page_templates' )
		);

		add_submenu_page(
			'olama-messages',
			__( 'SMS Dispatch Queue', 'olama-messages' ),
			__( 'SMS Dispatch Queue', 'olama-messages' ),
			'manage_options',
			'olama-messages-queue',
			array( $this, 'page_queue' )
		);

		add_submenu_page(
			'olama-messages',
			__( 'Recipients Preview', 'olama-messages' ),
			__( 'Recipients Preview', 'olama-messages' ),
			'manage_options',
			'olama-messages-recipients',
			array( $this, 'page_recipients' )
		);

		add_submenu_page(
			'olama-messages',
			__( 'Payment Report Links', 'olama-messages' ),
			__( 'Report Links', 'olama-messages' ),
			'manage_options',
			'olama-messages-tokens',
			array( $this, 'page_tokens' )
		);

		add_submenu_page(
			'olama-messages',
			__( 'Settings', 'olama-messages' ),
			__( 'Settings', 'olama-messages' ),
			'manage_options',
			'olama-messages-settings',
			array( $this, 'page_settings' )
		);

		add_submenu_page(
			'olama-messages',
			__( 'Sending Agents', 'olama-messages' ),
			__( 'Sending Agents', 'olama-messages' ),
			'manage_options',
			'olama-messages-agents',
			array( $this, 'page_agents' )
		);

		add_submenu_page(
			'olama-messages',
			__( 'Direct Message', 'olama-messages' ),
			__( 'Direct Message', 'olama-messages' ),
			'manage_options',
			'olama-messages-direct',
			array( $this, 'page_direct_message' )
		);

		// Hidden page for Add/Edit Campaign
		add_submenu_page(
			null,
			__( 'New Campaign', 'olama-messages' ),
			__( 'New Campaign', 'olama-messages' ),
			'manage_options',
			'olama-messages-new-campaign',
			array( $this, 'page_new_campaign' )
		);

		// Hidden page for Campaign Progress (Phase 4 Run 4B)
		add_submenu_page(
			null,
			__( 'Campaign Progress', 'olama-messages' ),
			__( 'Campaign Progress', 'olama-messages' ),
			'manage_options',
			'olama-messages-campaign-progress',
			array( $this, 'page_campaign_progress' )
		);
	}

	// ─── Assets ──────────────────────────────────────────────────────────────

	public function enqueue_assets( $hook ) {
		if ( strpos( $hook, 'olama-messages' ) === false ) {
			return;
		}
		wp_enqueue_style(
			'olama-messages-admin',
			OLAMA_MSG_URL . 'assets/admin.css',
			array(),
			(string) filemtime( OLAMA_MSG_PATH . 'assets/admin.css' )
		);
		wp_enqueue_script(
			'olama-messages-admin',
			OLAMA_MSG_URL . 'assets/admin.js',
			array( 'jquery' ),
			(string) filemtime( OLAMA_MSG_PATH . 'assets/admin.js' ),
			true
		);
		wp_localize_script( 'olama-messages-admin', 'olamaMsgAdmin', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'olama_msg_ajax' ),
		) );
	}

	// ─── Helpers ─────────────────────────────────────────────────────────────

	/** Print a styled admin notice. */
	private function notice( $message, $type = 'info' ) {
		$type = in_array( $type, array( 'success', 'error', 'warning', 'info' ), true ) ? $type : 'info';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . wp_kses_post( $message ) . '</p></div>';
	}

	/** Get the current page's flash message from transient. */
	private function get_flash() {
		$user_id = get_current_user_id();
		$flash   = get_transient( 'olama_msg_flash_' . $user_id );
		if ( $flash ) {
			delete_transient( 'olama_msg_flash_' . $user_id );
		}
		return $flash;
	}

	/** Set a flash message to display after redirect. */
	private function set_flash( $message, $type = 'success' ) {
		$user_id = get_current_user_id();
		set_transient( 'olama_msg_flash_' . $user_id, array( 'message' => $message, 'type' => $type ), 60 );
	}

	/** Print flash message if any. */
	private function print_flash() {
		$flash = $this->get_flash();
		if ( $flash ) {
			$this->notice( $flash['message'], $flash['type'] );
		}
	}

	/** Badge HTML. */
	private function status_badge( $status ) {
		$labels = array(
			'active'                 => array( __( 'Active', 'olama-messages' ),            'olama-msg-badge--active' ),
			'revoked'                => array( __( 'Revoked', 'olama-messages' ),           'olama-msg-badge--revoked' ),
			'expired'                => array( __( 'Expired', 'olama-messages' ),           'olama-msg-badge--expired' ),
			'maxed'                  => array( __( 'Max Views', 'olama-messages' ),         'olama-msg-badge--maxed' ),
			'draft'                  => array( __( 'Draft', 'olama-messages' ),             'olama-msg-badge--draft' ),
			'prepared'               => array( __( 'Prepared', 'olama-messages' ),          'olama-msg-badge--prepared' ),
			'sending'                => array( __( 'Sending', 'olama-messages' ),           'olama-msg-badge--sending' ),
			'paused'                 => array( __( 'Paused', 'olama-messages' ),            'olama-msg-badge--paused' ),
			'completed'              => array( __( 'Completed', 'olama-messages' ),         'olama-msg-badge--completed' ),
			'completed_with_errors'  => array( __( 'Completed (Errors)', 'olama-messages' ),'olama-msg-badge--completed-errors' ),
			'cancelled'              => array( __( 'Cancelled', 'olama-messages' ),         'olama-msg-badge--revoked' ),
			// Queue-level statuses
			'reserved'               => array( __( 'Reserved', 'olama-messages' ),          'olama-msg-badge--sending' ),
			'sent'                   => array( __( 'Sent', 'olama-messages' ),              'olama-msg-badge--completed' ),
			'failed'                 => array( __( 'Failed', 'olama-messages' ),            'olama-msg-badge--revoked' ),
			'retry_wait'             => array( __( 'Retry Wait', 'olama-messages' ),        'olama-msg-badge--paused' ),
		);
		$item = $labels[ $status ] ?? array( esc_html( $status ), '' );
		return '<span class="olama-msg-badge ' . esc_attr( $item[1] ) . '">' . esc_html( $item[0] ) . '</span>';
	}

	/** Mask a phone number for privacy, keeping prefix and last 3 digits. */
	private function mask_phone( $phone ) {
		$phone = (string) $phone;
		if ( strlen( $phone ) < 7 ) {
			return '***';
		}
		$prefix = substr( $phone, 0, strlen( $phone ) - 6 );
		$suffix = substr( $phone, -3 );
		return $prefix . '***' . $suffix;
	}

	/** Validate per-recipient draft exclusions and custom SMS text. */
	private function sanitize_recipient_overrides( $json ) {
		$decoded = json_decode( (string) $json, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		$clean = array();
		foreach ( array_slice( $decoded, 0, 5000, true ) as $key => $override ) {
			if ( ! preg_match( '/^[A-Za-z0-9_-]+:(father|mother|sponsor)$/', (string) $key ) || ! is_array( $override ) ) {
				continue;
			}
			$clean[ $key ] = array(
				'excluded' => ! empty( $override['excluded'] ),
				'message'  => isset( $override['message'] ) ? mb_substr( sanitize_textarea_field( $override['message'] ), 0, 1600 ) : '',
			);
		}
		return $clean;
	}

	/** Return fresh campaign/queue state for the live progress page. */
	public function ajax_campaign_progress() {
		check_ajax_referer( 'olama_msg_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'olama-messages' ) ), 403 );
		}

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
		$paged       = isset( $_POST['paged'] ) ? max( 1, absint( $_POST['paged'] ) ) : 1;
		$campaign    = $this->plugin->campaigns()->get_campaign( $campaign_id );
		if ( ! $campaign ) {
			wp_send_json_error( array( 'message' => __( 'Campaign not found.', 'olama-messages' ) ), 404 );
		}

		global $wpdb;
		$table_queue      = $wpdb->prefix . 'olama_msg_queue';
		$table_recipients = $wpdb->prefix . 'olama_msg_campaign_recipients';
		$counts           = $wpdb->get_results( $wpdb->prepare(
			"SELECT status, COUNT(*) AS cnt FROM {$table_queue} WHERE campaign_id = %d GROUP BY status",
			$campaign_id
		), ARRAY_A );
		$counter = array_fill_keys( array( 'prepared', 'reserved', 'sent', 'failed', 'retry_wait', 'cancelled' ), 0 );
		$total   = 0;
		foreach ( $counts as $count ) {
			$counter[ $count['status'] ] = (int) $count['cnt'];
			$total += (int) $count['cnt'];
		}

		$per_page = 30;
		$rows     = $wpdb->get_results( $wpdb->prepare(
			"SELECT q.id, q.status, q.attempt_count, q.last_error_message, q.sent_at, q.updated_at
			 FROM {$table_queue} q
			 LEFT JOIN {$table_recipients} r ON r.id = q.campaign_recipient_id
			 WHERE q.campaign_id = %d ORDER BY q.id ASC LIMIT %d OFFSET %d",
			$campaign_id,
			$per_page,
			( $paged - 1 ) * $per_page
		), ARRAY_A );

		$row_data = array();
		foreach ( $rows as $row ) {
			$row_data[] = array(
				'id'         => (int) $row['id'],
				'status_html'=> $this->status_badge( $row['status'] ),
				'attempts'   => (int) $row['attempt_count'],
				'last_error' => $row['last_error_message'] ?: '—',
				'sent_at'    => $row['sent_at'] ?: '—',
				'updated_at' => $row['updated_at'] ?: '—',
			);
		}

		$pending = $counter['prepared'] + $counter['reserved'] + $counter['retry_wait'];
		wp_send_json_success( array(
			'campaign_status'      => $campaign['status'],
			'campaign_status_html' => $this->status_badge( $campaign['status'] ),
			'terminal'             => in_array( $campaign['status'], array( 'completed', 'completed_with_errors', 'cancelled' ), true ),
			'counts'               => array_merge( array( 'total' => $total ), $counter ),
			'percent'              => array(
				'sent'     => $total ? round( ( $counter['sent'] / $total ) * 100 ) : 0,
				'reserved' => $total ? round( ( $counter['reserved'] / $total ) * 100 ) : 0,
				'failed'   => $total ? round( ( $counter['failed'] / $total ) * 100 ) : 0,
			),
			'eta_seconds' => $pending * 20,
			'rows'        => $row_data,
		) );
	}

	// ─── Page: Dashboard ─────────────────────────────────────────────────────

	public function page_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'olama-messages' ) );
		}

		global $wpdb;
		$provider     = $this->plugin->provider();
		$tokens_svc   = $this->plugin->tokens();
		$core_ok      = $provider->is_core_available();
		$fin_ok       = $provider->is_financial_provider_ready();
		$total_tokens = $tokens_svc->count_tokens();
		$total_views  = $tokens_svc->count_views();

		// Phase 2 dashboard metrics
		$total_campaigns = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}olama_msg_campaigns" );
		$draft_campaigns = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}olama_msg_campaigns WHERE status = %s", 'draft' ) );
		$prep_campaigns  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}olama_msg_campaigns WHERE status = %s", 'prepared' ) );
		$total_templates = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}olama_msg_templates" );
		$queue_prepared  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}olama_msg_queue WHERE status = %s", 'prepared' ) );

		// Phase 3 dashboard metrics
		$agent_stats = $this->plugin->agents()->count_agents_by_status();

		$this->print_flash();
		?>
		<div class="wrap olama-msg-wrap">
			<h1 class="olama-msg-page-title">
				<span class="dashicons dashicons-email-alt"></span>
				<?php esc_html_e( 'Olama Messages — Dashboard', 'olama-messages' ); ?>
			</h1>

			<div class="olama-msg-cards">

				<!-- Core Status -->
				<div class="olama-msg-card">
					<div class="olama-msg-card__header"><?php esc_html_e( 'Olama Core & APIs', 'olama-messages' ); ?></div>
					<div class="olama-msg-card__body">
						<div class="olama-msg-status-row">
							<span><?php esc_html_e( 'Core detected', 'olama-messages' ); ?></span>
							<?php echo $core_ok
								? '<span class="olama-msg-pill olama-msg-pill--ok">✓ ' . esc_html__( 'Yes', 'olama-messages' ) . '</span>'
								: '<span class="olama-msg-pill olama-msg-pill--err">✗ ' . esc_html__( 'No', 'olama-messages' ) . '</span>'; ?>
						</div>
						<div class="olama-msg-status-row">
							<span><?php esc_html_e( 'Data provider ready', 'olama-messages' ); ?></span>
							<?php echo $core_ok
								? '<span class="olama-msg-pill olama-msg-pill--ok">✓ ' . esc_html__( 'Yes', 'olama-messages' ) . '</span>'
								: '<span class="olama-msg-pill olama-msg-pill--err">✗ ' . esc_html__( 'No', 'olama-messages' ) . '</span>'; ?>
						</div>
						<div class="olama-msg-status-row">
							<span><?php esc_html_e( 'Financial provider ready', 'olama-messages' ); ?></span>
							<?php echo $fin_ok
								? '<span class="olama-msg-pill olama-msg-pill--ok">✓ ' . esc_html__( 'Connected', 'olama-messages' ) . '</span>'
								: '<span class="olama-msg-pill olama-msg-pill--warn">⚠ ' . esc_html__( 'Not available', 'olama-messages' ) . '</span>'; ?>
						</div>
						<?php if ( ! $core_ok ) : ?>
							<p class="olama-msg-warning-text">
								<?php esc_html_e( 'Olama Core plugin is not active. Activate it to enable recipient data.', 'olama-messages' ); ?>
							</p>
						<?php elseif ( ! $fin_ok ) : ?>
							<p class="olama-msg-warning-text olama-msg-warning-text--financial">
								<?php echo esc_html( $provider::FINANCIAL_WARNING ); ?>
							</p>
						<?php endif; ?>
					</div>
				</div>

				<!-- Campaigns Stats (Phase 2) -->
				<div class="olama-msg-card">
					<div class="olama-msg-card__header"><?php esc_html_e( 'Campaign & Queue Overview', 'olama-messages' ); ?></div>
					<div class="olama-msg-card__body">
						<div class="olama-msg-stat">
							<span class="olama-msg-stat__num"><?php echo esc_html( number_format( $total_campaigns ) ); ?></span>
							<span class="olama-msg-stat__label"><?php esc_html_e( 'Total Campaigns', 'olama-messages' ); ?></span>
						</div>
						<div class="olama-msg-stat">
							<span class="olama-msg-stat__num"><?php echo esc_html( number_format( $queue_prepared ) ); ?></span>
							<span class="olama-msg-stat__label"><?php esc_html_e( 'Prepared Messages in Queue', 'olama-messages' ); ?></span>
						</div>
						<div class="olama-msg-status-row">
							<span><?php esc_html_e( 'Draft Campaigns', 'olama-messages' ); ?></span>
							<span><strong><?php echo esc_html( $draft_campaigns ); ?></strong></span>
						</div>
						<div class="olama-msg-status-row">
							<span><?php esc_html_e( 'Prepared Campaigns', 'olama-messages' ); ?></span>
							<span><strong><?php echo esc_html( $prep_campaigns ); ?></strong></span>
						</div>
						<div class="olama-msg-status-row">
							<span><?php esc_html_e( 'Active Templates', 'olama-messages' ); ?></span>
							<span><strong><?php echo esc_html( $total_templates ); ?></strong></span>
						</div>
					</div>
				</div>

				<!-- Token Stats (Phase 1) -->
				<div class="olama-msg-card">
					<div class="olama-msg-card__header"><?php esc_html_e( 'Parent Access Stats', 'olama-messages' ); ?></div>
					<div class="olama-msg-card__body">
						<div class="olama-msg-stat">
							<span class="olama-msg-stat__num"><?php echo esc_html( number_format( $total_tokens ) ); ?></span>
							<span class="olama-msg-stat__label"><?php esc_html_e( 'Tokens Generated', 'olama-messages' ); ?></span>
						</div>
						<div class="olama-msg-stat">
							<span class="olama-msg-stat__num"><?php echo esc_html( number_format( $total_views ) ); ?></span>
							<span class="olama-msg-stat__label"><?php esc_html_e( 'Total Report Views', 'olama-messages' ); ?></span>
						</div>
					</div>
				</div>

				<!-- Sending Agents Stats (Phase 3) -->
				<div class="olama-msg-card">
					<div class="olama-msg-card__header"><?php esc_html_e( 'Sending Agents', 'olama-messages' ); ?></div>
					<div class="olama-msg-card__body">
						<div class="olama-msg-stat">
							<span class="olama-msg-stat__num"><?php echo esc_html( number_format( $agent_stats['total'] ) ); ?></span>
							<span class="olama-msg-stat__label"><?php esc_html_e( 'Registered Agents', 'olama-messages' ); ?></span>
						</div>
						<div class="olama-msg-stat">
							<span class="olama-msg-stat__num"><?php echo esc_html( number_format( $agent_stats['online'] ) ); ?></span>
							<span class="olama-msg-stat__label"><?php esc_html_e( 'Online Agents', 'olama-messages' ); ?></span>
						</div>
						<div class="olama-msg-status-row">
							<span><?php esc_html_e( 'KDE Ready Agents', 'olama-messages' ); ?></span>
							<span><strong><?php echo esc_html( $agent_stats['kde_ready'] ); ?></strong></span>
						</div>
						<div class="olama-msg-status-row">
							<span><?php esc_html_e( 'Last Agent Seen', 'olama-messages' ); ?></span>
							<span style="font-size: 0.9em;"><strong><?php echo esc_html( $agent_stats['last_seen'] ); ?></strong></span>
						</div>
					</div>
				</div>

			</div><!-- /.olama-msg-cards -->
		</div>
		<?php
	}

	// ─── Page: Campaigns (Phase 2) ───────────────────────────────────────────

	public function page_campaigns() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'olama-messages' ) );
		}

		$campaign_svc = $this->plugin->campaigns();
		$paged        = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$per_page     = 20;
		$offset       = ( $paged - 1 ) * $per_page;

		// Simple filter from query.
		$status_filter = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';

		$campaigns = $campaign_svc->list_campaigns( array(
			'status' => $status_filter,
			'limit'  => $per_page,
			'offset' => $offset,
		) );

		global $wpdb;
		$total_campaigns = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}olama_msg_campaigns" );
		$total_pages     = (int) ceil( $total_campaigns / $per_page );

		$this->print_flash();
		?>
		<div class="wrap olama-msg-wrap">
			<h1 class="olama-msg-page-title">
				<span class="dashicons dashicons-email-alt"></span>
				<?php esc_html_e( 'SMS Campaigns', 'olama-messages' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=olama-messages-new-campaign' ) ); ?>" class="page-title-action">
					<?php esc_html_e( 'New Campaign', 'olama-messages' ); ?>
				</a>
			</h1>

			<!-- Filters -->
			<ul class="subsubsub">
				<li class="all"><a href="<?php echo esc_url( admin_url( 'admin.php?page=olama-messages-campaigns' ) ); ?>" class="<?php echo empty( $status_filter ) ? 'current' : ''; ?>"><?php esc_html_e( 'All', 'olama-messages' ); ?></a> |</li>
				<li class="draft"><a href="<?php echo esc_url( admin_url( 'admin.php?page=olama-messages-campaigns&status=draft' ) ); ?>" class="<?php echo $status_filter === 'draft' ? 'current' : ''; ?>"><?php esc_html_e( 'Drafts', 'olama-messages' ); ?></a> |</li>
				<li class="prepared"><a href="<?php echo esc_url( admin_url( 'admin.php?page=olama-messages-campaigns&status=prepared' ) ); ?>" class="<?php echo $status_filter === 'prepared' ? 'current' : ''; ?>"><?php esc_html_e( 'Prepared', 'olama-messages' ); ?></a> |</li>
				<li class="sending"><a href="<?php echo esc_url( admin_url( 'admin.php?page=olama-messages-campaigns&status=sending' ) ); ?>" class="<?php echo $status_filter === 'sending' ? 'current' : ''; ?>"><?php esc_html_e( 'Sending', 'olama-messages' ); ?></a> |</li>
				<li class="paused"><a href="<?php echo esc_url( admin_url( 'admin.php?page=olama-messages-campaigns&status=paused' ) ); ?>" class="<?php echo $status_filter === 'paused' ? 'current' : ''; ?>"><?php esc_html_e( 'Paused', 'olama-messages' ); ?></a> |</li>
				<li class="completed"><a href="<?php echo esc_url( admin_url( 'admin.php?page=olama-messages-campaigns&status=completed' ) ); ?>" class="<?php echo $status_filter === 'completed' ? 'current' : ''; ?>"><?php esc_html_e( 'Completed', 'olama-messages' ); ?></a> |</li>
				<li class="cancelled"><a href="<?php echo esc_url( admin_url( 'admin.php?page=olama-messages-campaigns&status=cancelled' ) ); ?>" class="<?php echo $status_filter === 'cancelled' ? 'current' : ''; ?>"><?php esc_html_e( 'Cancelled', 'olama-messages' ); ?></a></li>
			</ul>

			<?php if ( ! $campaigns ) : ?>
				<div class="olama-msg-empty" style="clear:both;">
					<?php esc_html_e( 'No campaigns found. Click "New Campaign" to draft your first notification campaign.', 'olama-messages' ); ?>
				</div>
			<?php else : ?>

			<div class="olama-msg-table-wrap" style="clear:both;">
				<table class="olama-msg-table widefat">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Campaign Title', 'olama-messages' ); ?></th>
							<th><?php esc_html_e( 'Study Year', 'olama-messages' ); ?></th>
							<th><?php esc_html_e( 'Recipient Policy', 'olama-messages' ); ?></th>
							<th><?php esc_html_e( 'Status', 'olama-messages' ); ?></th>
							<th><?php esc_html_e( 'Metrics', 'olama-messages' ); ?></th>
							<th><?php esc_html_e( 'Created', 'olama-messages' ); ?></th>
							<th><?php esc_html_e( 'Prepared At', 'olama-messages' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'olama-messages' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $campaigns as $c ) :
							$prepare_url  = wp_nonce_url(
								admin_url( 'admin-post.php?action=olama_msg_prepare_campaign&campaign_id=' . $c['id'] ),
								'olama_msg_prepare_' . $c['id']
							);
							$reset_url    = wp_nonce_url(
								admin_url( 'admin-post.php?action=olama_msg_reset_campaign&campaign_id=' . $c['id'] ),
								'olama_msg_reset_' . $c['id']
							);
							$cancel_url   = wp_nonce_url(
								admin_url( 'admin-post.php?action=olama_msg_cancel_campaign&campaign_id=' . $c['id'] ),
								'olama_msg_cancel_' . $c['id']
							);
							$delete_url   = wp_nonce_url(
								admin_url( 'admin-post.php?action=olama_msg_delete_campaign&campaign_id=' . $c['id'] ),
								'olama_msg_delete_' . $c['id']
							);
							$start_url    = wp_nonce_url(
								admin_url( 'admin-post.php?action=olama_msg_start_campaign&campaign_id=' . $c['id'] ),
								'olama_msg_start_' . $c['id']
							);
							$pause_url    = wp_nonce_url(
								admin_url( 'admin-post.php?action=olama_msg_pause_campaign&campaign_id=' . $c['id'] ),
								'olama_msg_pause_' . $c['id']
							);
							$resume_url   = wp_nonce_url(
								admin_url( 'admin-post.php?action=olama_msg_resume_campaign&campaign_id=' . $c['id'] ),
								'olama_msg_resume_' . $c['id']
							);
							$progress_url = admin_url( 'admin.php?page=olama-messages-campaign-progress&campaign_id=' . $c['id'] );
						?>
						<tr>
							<td>
								<strong>
									<?php if ( $c['status'] === 'draft' ) : ?>
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=olama-messages-new-campaign&action=edit&campaign_id=' . $c['id'] ) ); ?>">
											<?php echo esc_html( $c['title'] ); ?>
										</a>
									<?php elseif ( in_array( $c['status'], array( 'sending', 'paused', 'completed', 'completed_with_errors' ), true ) ) : ?>
										<a href="<?php echo esc_url( $progress_url ); ?>">
											<?php echo esc_html( $c['title'] ); ?>
										</a>
									<?php else : ?>
										<?php echo esc_html( $c['title'] ); ?>
									<?php endif; ?>
								</strong>
								<?php if ( isset( $c['filters']['type'] ) && $c['filters']['type'] === 'direct' ) : ?>
									<span class="olama-msg-pill" style="background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;margin-left:8px;font-weight:600;font-size:10px;text-transform:uppercase;padding:2px 6px;border-radius:4px;vertical-align:middle;display:inline-block;line-height:1.2;">Direct</span>
								<?php endif; ?>
							</td>
							<td><code><?php echo esc_html( $c['study_year'] ); ?></code></td>
							<td>
								<?php
								$policies = array(
									'father_first' => __( 'Father First, Mother Fallback', 'olama-messages' ),
									'mother_first' => __( 'Mother First, Father Fallback', 'olama-messages' ),
									'father_only'  => __( 'Father Only', 'olama-messages' ),
									'mother_only'  => __( 'Mother Only', 'olama-messages' ),
									'both_parents' => __( 'Both Parents (Separate SMS)', 'olama-messages' ),
								);
								echo esc_html( $policies[ $c['recipient_policy'] ] ?? $c['recipient_policy'] );
								?>
							</td>
							<td><?php echo $this->status_badge( $c['status'] ); ?></td>
							<td>
								<?php if ( $c['status'] !== 'draft' ) : ?>
									<span class="olama-msg-pill olama-msg-pill--ok" title="<?php esc_attr_e( 'Targets included & written to queue', 'olama-messages' ); ?>">
										<?php echo esc_html( $c['total_included'] ); ?> <?php esc_html_e( 'Included', 'olama-messages' ); ?>
									</span>
									<span class="olama-msg-pill olama-msg-pill--err" title="<?php esc_attr_e( 'Targets excluded by filters', 'olama-messages' ); ?>">
										<?php echo esc_html( $c['total_excluded'] ); ?> <?php esc_html_e( 'Excluded', 'olama-messages' ); ?>
									</span>
								<?php else : ?>
									<span class="olama-msg-muted"><?php esc_html_e( 'Unprepared', 'olama-messages' ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $c['created_at'] ); ?></td>
							<td><?php echo esc_html( $c['prepared_at'] ?: '—' ); ?></td>
							<td class="olama-msg-actions">
								<?php if ( $c['status'] === 'draft' ) : ?>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=olama-messages-new-campaign&action=edit&campaign_id=' . $c['id'] ) ); ?>" class="button button-small">
										<?php esc_html_e( 'Edit', 'olama-messages' ); ?>
									</a>
									<a href="<?php echo esc_url( $prepare_url ); ?>" class="button button-small button-primary" onclick="return confirm('<?php esc_attr_e( 'Prepare this campaign? This will snapshot targets and lock settings.', 'olama-messages' ); ?>');">
										<?php esc_html_e( 'Prepare Queue', 'olama-messages' ); ?>
									</a>
									<a href="<?php echo esc_url( $delete_url ); ?>" class="button button-small button-link-delete" onclick="return confirm('<?php esc_attr_e( 'Delete this draft campaign permanently?', 'olama-messages' ); ?>');">
										<?php esc_html_e( 'Delete', 'olama-messages' ); ?>
									</a>
								<?php elseif ( $c['status'] === 'prepared' ) : ?>
									<?php if ( intval( $c['total_included'] ) === 1 ) : ?>
										<button type="button" class="button button-small button-primary olama-msg-start-btn"
											data-campaign-id="<?php echo esc_attr( $c['id'] ); ?>"
											data-title="<?php echo esc_attr( $c['title'] ); ?>"
											data-recipients="<?php echo esc_attr( $c['total_included'] ); ?>"
											data-start-url="<?php echo esc_attr( $start_url ); ?>">
											▶ <?php esc_html_e( 'Start Sending', 'olama-messages' ); ?>
										</button>
									<?php else : ?>
										<span class="olama-msg-safety-warning" style="color: #d63638; font-weight: bold; display: inline-block; padding: 4px 8px; background: #fcf1f1; border: 1px solid #f8cbcb; border-radius: 4px; margin-bottom: 5px;">
											<?php esc_html_e( 'Run 4D safety mode allows only one prepared message.', 'olama-messages' ); ?>
										</span>
									<?php endif; ?>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=olama-messages-queue&campaign_id=' . $c['id'] ) ); ?>" class="button button-small">
										<?php esc_html_e( 'View Queue', 'olama-messages' ); ?>
									</a>
									<a href="<?php echo esc_url( $reset_url ); ?>" class="button button-small" onclick="return confirm('<?php esc_attr_e( 'Reset this campaign back to draft? This will clear all prepared queue records.', 'olama-messages' ); ?>');">
										<?php esc_html_e( 'Reset to Draft', 'olama-messages' ); ?>
									</a>
									<a href="<?php echo esc_url( $cancel_url ); ?>" class="button button-small button-link-delete" onclick="return confirm('<?php esc_attr_e( 'Cancel this prepared campaign? This is audit-permanent.', 'olama-messages' ); ?>');">
										<?php esc_html_e( 'Cancel', 'olama-messages' ); ?>
									</a>
								<?php elseif ( $c['status'] === 'sending' ) : ?>
									<a href="<?php echo esc_url( $progress_url ); ?>" class="button button-small button-primary">
										<?php esc_html_e( 'View Progress', 'olama-messages' ); ?>
									</a>
									<a href="<?php echo esc_url( $pause_url ); ?>" class="button button-small" onclick="return confirm('<?php esc_attr_e( 'Pause sending? The agent will stop picking up new jobs.', 'olama-messages' ); ?>');">
										⏸ <?php esc_html_e( 'Pause', 'olama-messages' ); ?>
									</a>
								<?php elseif ( $c['status'] === 'paused' ) : ?>
									<a href="<?php echo esc_url( $progress_url ); ?>" class="button button-small button-primary">
										<?php esc_html_e( 'View Progress', 'olama-messages' ); ?>
									</a>
									<a href="<?php echo esc_url( $resume_url ); ?>" class="button button-small" onclick="return confirm('<?php esc_attr_e( 'Resume sending this campaign?', 'olama-messages' ); ?>');">
										▶ <?php esc_html_e( 'Resume Sending', 'olama-messages' ); ?>
									</a>
									<a href="<?php echo esc_url( $cancel_url ); ?>" class="button button-small button-link-delete" onclick="return confirm('<?php esc_attr_e( 'Cancel this paused campaign? All pending queue records will be cancelled.', 'olama-messages' ); ?>');">
										<?php esc_html_e( 'Cancel Campaign', 'olama-messages' ); ?>
									</a>
								<?php elseif ( in_array( $c['status'], array( 'completed', 'completed_with_errors' ), true ) ) : ?>
									<a href="<?php echo esc_url( $progress_url ); ?>" class="button button-small button-primary">
										<?php esc_html_e( 'View Results', 'olama-messages' ); ?>
									</a>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=olama-messages-queue&campaign_id=' . $c['id'] ) ); ?>" class="button button-small">
										<?php esc_html_e( 'View Queue', 'olama-messages' ); ?>
									</a>
								<?php elseif ( $c['status'] === 'cancelled' ) : ?>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=olama-messages-queue&campaign_id=' . $c['id'] ) ); ?>" class="button button-small">
										<?php esc_html_e( 'View Queue', 'olama-messages' ); ?>
									</a>
								<?php endif; ?>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<?php if ( $total_pages > 1 ) :
				$base_url = admin_url( 'admin.php?page=olama-messages-campaigns&status=' . rawurlencode( $status_filter ) );
			?>
			<div class="olama-msg-pagination">
				<?php for ( $p = 1; $p <= $total_pages; $p++ ) : ?>
					<?php if ( $p === $paged ) : ?>
						<span class="olama-msg-page-current"><?php echo esc_html( $p ); ?></span>
					<?php else : ?>
						<a href="<?php echo esc_url( $base_url . '&paged=' . $p ); ?>"><?php echo esc_html( $p ); ?></a>
					<?php endif; ?>
				<?php endfor; ?>
			</div>
			<?php endif; ?>

			<?php endif; ?>

			<!-- Confirm Campaign Sending Modal -->
			<div id="olama-msg-confirm-send-modal" class="olama-msg-modal" style="display:none;">
				<div class="olama-msg-modal__backdrop" id="olama-msg-confirm-backdrop"></div>
				<div class="olama-msg-modal__box olama-msg-confirm-box">
					<button type="button" class="olama-msg-modal__close" id="olama-msg-confirm-close" aria-label="<?php esc_attr_e( 'Close', 'olama-messages' ); ?>">×</button>
					<h2>🚀 <?php esc_html_e( 'Confirm: Start Sending Campaign', 'olama-messages' ); ?></h2>
					<div class="olama-msg-confirm-body">
						<p><?php esc_html_e( 'You are about to start sending SMS messages for:', 'olama-messages' ); ?></p>
						<div class="olama-msg-confirm-title" id="olama-msg-confirm-campaign-title"></div>
						<div class="olama-msg-confirm-stats">
							<div class="olama-msg-confirm-stat">
								<span class="olama-msg-confirm-stat__num" id="olama-msg-confirm-recipients">—</span>
								<span class="olama-msg-confirm-stat__label"><?php esc_html_e( 'Recipients', 'olama-messages' ); ?></span>
							</div>
						</div>
						<p class="olama-msg-confirm-warning">
							⚠ <?php esc_html_e( 'This action will allow the Windows sending agent to start dispatching SMS messages immediately. Ensure an agent is online and KDE Connect is ready before proceeding.', 'olama-messages' ); ?>
						</p>
					</div>
					<div class="olama-msg-confirm-actions">
						<a href="#" id="olama-msg-confirm-proceed" class="button button-primary olama-msg-confirm-proceed-btn">
							▶ <?php esc_html_e( 'Proceed — Start Sending', 'olama-messages' ); ?>
						</a>
						<button type="button" id="olama-msg-confirm-cancel" class="button">
							<?php esc_html_e( 'Cancel', 'olama-messages' ); ?>
						</button>
					</div>
				</div>
			</div>

		</div>
		<?php
	}

	// ─── Page: Campaign Progress (Phase 4 Run 4B) ────────────────────────────

	public function page_campaign_progress() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'olama-messages' ) );
		}

		$campaign_id = isset( $_GET['campaign_id'] ) ? absint( $_GET['campaign_id'] ) : 0;
		if ( ! $campaign_id ) {
			wp_die( esc_html__( 'Missing campaign ID.', 'olama-messages' ) );
		}

		$campaign_svc = $this->plugin->campaigns();
		$campaign     = $campaign_svc->get_campaign( $campaign_id );
		if ( ! $campaign ) {
			wp_die( esc_html__( 'Campaign not found.', 'olama-messages' ) );
		}

		global $wpdb;
		$table_queue = $wpdb->prefix . 'olama_msg_queue';

		// Aggregated queue counters for this campaign.
		$counts = $wpdb->get_results( $wpdb->prepare(
			"SELECT status, COUNT(*) AS cnt FROM {$table_queue} WHERE campaign_id = %d GROUP BY status",
			$campaign_id
		), ARRAY_A );

		$counter = array(
			'prepared'   => 0,
			'reserved'   => 0,
			'sent'       => 0,
			'failed'     => 0,
			'retry_wait' => 0,
			'cancelled'  => 0,
		);
		$total_queue = 0;
		foreach ( $counts as $row ) {
			$counter[ $row['status'] ] = (int) $row['cnt'];
			$total_queue += (int) $row['cnt'];
		}

		$pending = $counter['prepared'] + $counter['reserved'] + $counter['retry_wait'];
		$done    = $counter['sent'] + $counter['failed'] + $counter['cancelled'];

		// Estimated time remaining (20s per pending SMS).
		$est_seconds  = $pending * 20;
		$est_display  = '';
		if ( $est_seconds > 0 ) {
			$est_display = sprintf(
				/* translators: 1: minutes, 2: seconds */
				__( '~%1$d min %2$d sec', 'olama-messages' ),
				(int) floor( $est_seconds / 60 ),
				$est_seconds % 60
			);
		}

		// Retrieve active agent info for this campaign if sending.
		$active_agent = null;
		if ( in_array( $campaign['status'], array( 'sending', 'paused' ), true ) ) {
			$table_agents = $wpdb->prefix . 'olama_msg_agents';
			$active_agent = $wpdb->get_row( $wpdb->prepare(
				"SELECT agent_name, last_seen_at FROM {$table_agents}
				 WHERE id = (
				   SELECT DISTINCT reserved_by_agent_id FROM {$table_queue}
				   WHERE campaign_id = %d AND reserved_by_agent_id IS NOT NULL
				   ORDER BY updated_at DESC LIMIT 1
				 )",
				$campaign_id
			), ARRAY_A );
		}

		// Paginated queue records.
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$per_page = 30;
		$offset   = ( $paged - 1 ) * $per_page;

		$table_recipients = $wpdb->prefix . 'olama_msg_campaign_recipients';
		$queue_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT q.id, q.status, q.phone_e164, q.attempt_count, q.last_error_message,
			        q.reserved_by_agent_id, q.sent_at, q.updated_at,
			        r.recipient_name
			 FROM {$table_queue} q
			 LEFT JOIN {$table_recipients} r ON r.id = q.campaign_recipient_id
			 WHERE q.campaign_id = %d
			 ORDER BY q.id ASC
			 LIMIT %d OFFSET %d",
			$campaign_id,
			$per_page,
			$offset
		), ARRAY_A );

		$total_pages = (int) ceil( $total_queue / $per_page );

		// Action URLs.
		$pause_url  = wp_nonce_url(
			admin_url( 'admin-post.php?action=olama_msg_pause_campaign&campaign_id=' . $campaign_id ),
			'olama_msg_pause_' . $campaign_id
		);
		$resume_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=olama_msg_resume_campaign&campaign_id=' . $campaign_id ),
			'olama_msg_resume_' . $campaign_id
		);
		$cancel_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=olama_msg_cancel_campaign&campaign_id=' . $campaign_id ),
			'olama_msg_cancel_' . $campaign_id
		);

		$this->print_flash();
		?>
		<div class="wrap olama-msg-wrap" id="olama-msg-campaign-progress"
			data-campaign-id="<?php echo esc_attr( $campaign_id ); ?>"
			data-paged="<?php echo esc_attr( $paged ); ?>">
			<h1 class="olama-msg-page-title">
				<span class="dashicons dashicons-email-alt"></span>
				<?php esc_html_e( 'Campaign Progress', 'olama-messages' ); ?>
				<span id="olama-msg-campaign-status"><?php echo $this->status_badge( $campaign['status'] ); ?></span>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=olama-messages-campaigns' ) ); ?>" class="page-title-action">
					<?php esc_html_e( '← Back to Campaigns', 'olama-messages' ); ?>
				</a>
			</h1>

			<!-- Campaign Title -->
			<h2 style="font-size:1.2rem;color:var(--omsg-text);margin-bottom:1.5rem;">
				<?php echo esc_html( $campaign['title'] ); ?>
				<span style="font-size:.85rem;color:var(--omsg-muted);margin-left:.5rem;">
					<?php echo esc_html( $campaign['study_year'] ); ?>
				</span>
			</h2>

			<!-- Controls -->
			<div class="olama-msg-progress-controls" id="olama-msg-progress-controls">
				<?php if ( $campaign['status'] === 'sending' ) : ?>
					<a href="<?php echo esc_url( $pause_url ); ?>" class="button button-secondary olama-msg-btn-pause"
						onclick="return confirm('<?php esc_attr_e( 'Pause sending? The agent will stop picking up new jobs.', 'olama-messages' ); ?>');">
						⏸ <?php esc_html_e( 'Pause Sending', 'olama-messages' ); ?>
					</a>
				<?php elseif ( $campaign['status'] === 'paused' ) : ?>
					<a href="<?php echo esc_url( $resume_url ); ?>" class="button button-primary"
						onclick="return confirm('<?php esc_attr_e( 'Resume sending this campaign?', 'olama-messages' ); ?>');">
						▶ <?php esc_html_e( 'Resume Sending', 'olama-messages' ); ?>
					</a>
					<a href="<?php echo esc_url( $cancel_url ); ?>" class="button button-link-delete"
						onclick="return confirm('<?php esc_attr_e( 'Cancel campaign permanently? All pending records will be marked cancelled.', 'olama-messages' ); ?>');">
						<?php esc_html_e( 'Cancel Campaign', 'olama-messages' ); ?>
					</a>
				<?php endif; ?>
			</div>

			<!-- Progress Stats -->
			<div class="olama-msg-progress-stats">
				<div class="olama-msg-progress-stat olama-msg-progress-stat--total">
					<span class="olama-msg-progress-stat__num" data-progress-count="total"><?php echo esc_html( number_format( $total_queue ) ); ?></span>
					<span class="olama-msg-progress-stat__label"><?php esc_html_e( 'Total Queue', 'olama-messages' ); ?></span>
				</div>
				<div class="olama-msg-progress-stat olama-msg-progress-stat--prepared">
					<span class="olama-msg-progress-stat__num" data-progress-count="prepared"><?php echo esc_html( number_format( $counter['prepared'] ) ); ?></span>
					<span class="olama-msg-progress-stat__label"><?php esc_html_e( 'Prepared', 'olama-messages' ); ?></span>
				</div>
				<div class="olama-msg-progress-stat olama-msg-progress-stat--reserved">
					<span class="olama-msg-progress-stat__num" data-progress-count="reserved"><?php echo esc_html( number_format( $counter['reserved'] ) ); ?></span>
					<span class="olama-msg-progress-stat__label"><?php esc_html_e( 'In Progress', 'olama-messages' ); ?></span>
				</div>
				<div class="olama-msg-progress-stat olama-msg-progress-stat--sent">
					<span class="olama-msg-progress-stat__num" data-progress-count="sent"><?php echo esc_html( number_format( $counter['sent'] ) ); ?></span>
					<span class="olama-msg-progress-stat__label"><?php esc_html_e( 'Sent', 'olama-messages' ); ?></span>
				</div>
				<div class="olama-msg-progress-stat olama-msg-progress-stat--failed">
					<span class="olama-msg-progress-stat__num" data-progress-count="failed"><?php echo esc_html( number_format( $counter['failed'] ) ); ?></span>
					<span class="olama-msg-progress-stat__label"><?php esc_html_e( 'Failed', 'olama-messages' ); ?></span>
				</div>
				<div class="olama-msg-progress-stat olama-msg-progress-stat--cancelled">
					<span class="olama-msg-progress-stat__num" data-progress-count="cancelled"><?php echo esc_html( number_format( $counter['cancelled'] ) ); ?></span>
					<span class="olama-msg-progress-stat__label"><?php esc_html_e( 'Cancelled', 'olama-messages' ); ?></span>
				</div>
			</div>

			<!-- Progress Bar -->
			<?php if ( $total_queue > 0 ) :
				$pct_sent      = round( ( $counter['sent'] / $total_queue ) * 100 );
				$pct_failed    = round( ( $counter['failed'] / $total_queue ) * 100 );
				$pct_reserved  = round( ( $counter['reserved'] / $total_queue ) * 100 );
			?>
			<div class="olama-msg-progressbar-wrap">
				<div class="olama-msg-progressbar">
					<div class="olama-msg-progressbar__sent" style="width:<?php echo esc_attr( $pct_sent ); ?>%" title="<?php printf( esc_attr__( '%d%% Sent', 'olama-messages' ), $pct_sent ); ?>"></div>
					<div class="olama-msg-progressbar__reserved" style="width:<?php echo esc_attr( $pct_reserved ); ?>%" title="<?php printf( esc_attr__( '%d%% In Progress', 'olama-messages' ), $pct_reserved ); ?>"></div>
					<div class="olama-msg-progressbar__failed" style="width:<?php echo esc_attr( $pct_failed ); ?>%" title="<?php printf( esc_attr__( '%d%% Failed', 'olama-messages' ), $pct_failed ); ?>"></div>
				</div>
				<div class="olama-msg-progressbar-legend">
					<span class="olama-msg-progressbar__legend--sent" id="olama-msg-progress-sent-label"><?php printf( esc_html__( '%d%% Sent', 'olama-messages' ), $pct_sent ); ?></span>
					<?php if ( $est_display ) : ?>
					<span class="olama-msg-progressbar__legend--eta" id="olama-msg-progress-eta"><?php printf( esc_html__( 'Est. remaining: %s', 'olama-messages' ), $est_display ); ?></span>
					<?php endif; ?>
				</div>
			</div>
			<?php endif; ?>

			<!-- Agent Info -->
			<?php if ( $active_agent ) : ?>
			<div class="olama-msg-agent-info">
				<span class="dashicons dashicons-desktop"></span>
				<?php printf(
					esc_html__( 'Active Agent: %s — Last seen: %s', 'olama-messages' ),
					esc_html( $active_agent['agent_name'] ),
					esc_html( $active_agent['last_seen_at'] ?? '—' )
				); ?>
			</div>
			<?php endif; ?>

			<!-- Queue Table -->
			<div class="olama-msg-table-wrap" style="margin-top:1.5rem;">
				<table class="olama-msg-table widefat">
					<thead>
						<tr>
							<th><?php esc_html_e( '#', 'olama-messages' ); ?></th>
							<th><?php esc_html_e( 'Recipient', 'olama-messages' ); ?></th>
							<th><?php esc_html_e( 'Phone', 'olama-messages' ); ?></th>
							<th><?php esc_html_e( 'Status', 'olama-messages' ); ?></th>
							<th><?php esc_html_e( 'Attempts', 'olama-messages' ); ?></th>
							<th><?php esc_html_e( 'Last Error', 'olama-messages' ); ?></th>
							<th><?php esc_html_e( 'Sent At', 'olama-messages' ); ?></th>
							<th><?php esc_html_e( 'Updated', 'olama-messages' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( $queue_rows ) : ?>
							<?php foreach ( $queue_rows as $row ) :
								$masked_phone = $this->mask_phone( $row['phone_e164'] );
							?>
							<tr data-queue-id="<?php echo esc_attr( $row['id'] ); ?>">
								<td><?php echo esc_html( $row['id'] ); ?></td>
								<td><?php echo esc_html( $row['recipient_name'] ?: '—' ); ?></td>
								<td>
									<code title="<?php echo esc_attr( $row['phone_e164'] ); ?>">
										<?php echo esc_html( $masked_phone ); ?>
									</code>
								</td>
								<td data-queue-field="status"><?php echo $this->status_badge( $row['status'] ); ?></td>
								<td data-queue-field="attempts"><?php echo esc_html( $row['attempt_count'] ); ?></td>
								<td data-queue-field="last_error">
									<?php if ( $row['last_error_message'] ) : ?>
										<span class="olama-msg-error-cell" title="<?php echo esc_attr( $row['last_error_message'] ); ?>">
											<?php echo esc_html( mb_strimwidth( $row['last_error_message'], 0, 40, '…' ) ); ?>
										</span>
									<?php else : ?>
										<span class="olama-msg-muted">—</span>
									<?php endif; ?>
								</td>
								<td data-queue-field="sent_at"><?php echo esc_html( $row['sent_at'] ?: '—' ); ?></td>
								<td data-queue-field="updated_at"><?php echo esc_html( $row['updated_at'] ?: '—' ); ?></td>
							</tr>
							<?php endforeach; ?>
						<?php else : ?>
							<tr><td colspan="8" style="text-align:center;padding:2rem;color:var(--omsg-muted);">
								<?php esc_html_e( 'No queue records found for this campaign.', 'olama-messages' ); ?>
							</td></tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<?php if ( $total_pages > 1 ) :
				$base_url = admin_url( 'admin.php?page=olama-messages-campaign-progress&campaign_id=' . $campaign_id );
			?>
			<div class="olama-msg-pagination">
				<?php for ( $p = 1; $p <= $total_pages; $p++ ) : ?>
					<?php if ( $p === $paged ) : ?>
						<span class="olama-msg-page-current"><?php echo esc_html( $p ); ?></span>
					<?php else : ?>
						<a href="<?php echo esc_url( $base_url . '&paged=' . $p ); ?>"><?php echo esc_html( $p ); ?></a>
					<?php endif; ?>
				<?php endfor; ?>
			</div>
			<?php endif; ?>

		</div>
		<?php
	}

	// ─── Page: New Campaign / Edit Campaign (Phase 2) ─────────────────────────

	public function page_new_campaign() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'olama-messages' ) );
		}

		$campaign_id = isset( $_GET['campaign_id'] ) ? absint( $_GET['campaign_id'] ) : 0;
		$is_edit     = isset( $_GET['action'] ) && $_GET['action'] === 'edit' && $campaign_id > 0;

		$campaign = null;
		if ( $is_edit ) {
			$campaign = $this->plugin->campaigns()->get_campaign( $campaign_id );
			if ( ! $campaign || $campaign['status'] !== 'draft' ) {
				wp_die( esc_html__( 'Campaign not found or is locked (not in draft status).', 'olama-messages' ) );
			}
		}

		$provider    = $this->plugin->provider();
		$study_years = $provider->get_available_study_years();
		$templates   = $this->plugin->templates()->list_templates( array( 'is_active' => 1 ) );
		$selected_study_year = $campaign['study_year'] ?? ( $study_years[0] ?? '' );
		$class_names   = $provider->get_available_class_names( $selected_study_year );
		$section_names = $provider->get_available_section_names( $selected_study_year );
		$route_options = $this->plugin->transportation()->get_route_options( $selected_study_year );
		$transport_class_id = (string) ( $campaign['filters']['class_id'] ?? '' );
		$transport_section_id = (string) ( $campaign['filters']['section_id'] ?? '' );
		// Migrate legacy name-based draft filters to Oracle IDs in the UI.
		if ( '' === $transport_class_id && ! empty( $campaign['filters']['class_name'] ) ) {
			foreach ( $route_options['classes'] ?? array() as $option ) {
				if ( 0 === strcasecmp( trim( $option['name'] ), trim( $campaign['filters']['class_name'] ) ) ) {
					$transport_class_id = (string) $option['id'];
					break;
				}
			}
		}
		if ( '' === $transport_section_id && ! empty( $campaign['filters']['section_name'] ) ) {
			foreach ( $route_options['sections'] ?? array() as $option ) {
				if ( ( '' === $transport_class_id || (string) $option['class_id'] === $transport_class_id ) && 0 === strcasecmp( trim( $option['name'] ), trim( $campaign['filters']['section_name'] ) ) ) {
					$transport_section_id = (string) $option['id'];
					break;
				}
			}
		}

		$this->print_flash();
		?>
		<div class="wrap olama-msg-wrap" id="olama-campaign-builder">
			<h1 class="olama-msg-page-title">
				<span class="dashicons dashicons-email-alt"></span>
				<?php echo $is_edit ? esc_html__( 'Edit SMS Campaign Draft', 'olama-messages' ) : esc_html__( 'Draft New SMS Campaign', 'olama-messages' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=olama-messages-campaigns' ) ); ?>" class="page-title-action">
					<?php esc_html_e( 'Back to Campaigns', 'olama-messages' ); ?>
				</a>
			</h1>

			<div id="poststuff">
				<div id="post-body" class="metabox-holder columns-2">

					<!-- Left Form Columns -->
					<div id="post-body-content">
						<div class="postbox">
							<div class="postbox-header">
								<h2 class="hndle"><?php esc_html_e( 'Campaign Configuration', 'olama-messages' ); ?></h2>
							</div>
										<div class="inside">
											<div class="notice notice-info inline" style="margin:0 0 1rem;">
												<p style="margin:0;">
													<strong><?php esc_html_e( 'Collection:', 'olama-messages' ); ?></strong> <?php esc_html_e( 'shows balance filters only.', 'olama-messages' ); ?>
													<strong style="margin-left:12px;"><?php esc_html_e( 'General:', 'olama-messages' ); ?></strong> <?php esc_html_e( 'shows grade/class and section filters.', 'olama-messages' ); ?>
													<strong style="margin-left:12px;"><?php esc_html_e( 'Transportation:', 'olama-messages' ); ?></strong> <?php esc_html_e( 'shows bus and round filters.', 'olama-messages' ); ?>
												</p>
											</div>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="olama-campaign-form">
									<input type="hidden" id="olama-campaign-recipient-overrides" name="recipient_overrides" value="<?php echo esc_attr( wp_json_encode( $campaign['filters']['recipient_overrides'] ?? array() ) ); ?>">
									<input type="hidden" name="action" value="olama_msg_save_campaign">
									<?php if ( $is_edit ) : ?>
										<input type="hidden" name="campaign_id" value="<?php echo esc_attr( $campaign_id ); ?>">
									<?php endif; ?>
									<?php wp_nonce_field( 'olama_msg_save_campaign', 'olama_msg_campaign_nonce' ); ?>

									<table class="form-table" role="presentation" style="margin-top:0;">
										<tbody>
											<tr>
												<th scope="row">
													<label for="campaign-target-type"><?php esc_html_e( 'Campaign Target', 'olama-messages' ); ?></label>
												</th>
												<td>
													<select id="campaign-target-type" name="target_type" required style="max-width:25rem;width:100%;">
														<option value="collection" <?php selected( $campaign['target_type'] ?? 'collection', 'collection' ); ?>><?php esc_html_e( 'Collection', 'olama-messages' ); ?></option>
														<option value="general" <?php selected( $campaign['target_type'] ?? '', 'general' ); ?>><?php esc_html_e( 'General', 'olama-messages' ); ?></option>
														<option value="transportation" <?php selected( $campaign['target_type'] ?? '', 'transportation' ); ?>><?php esc_html_e( 'Transportation', 'olama-messages' ); ?></option>
													</select>
													<p class="description"><?php esc_html_e( 'Choose the audience type so the form shows only the relevant filters.', 'olama-messages' ); ?></p>
												</td>
											</tr>
											<tr>
												<th scope="row">
													<label for="campaign-title"><?php esc_html_e( 'Campaign Title', 'olama-messages' ); ?></label>
												</th>
												<td>
													<input type="text" id="campaign-title" name="title" value="<?php echo esc_attr( $campaign['title'] ?? '' ); ?>" class="large-text" required placeholder="<?php esc_attr_e( 'e.g. مطالبات رسوم النصف الأول 2025/2026', 'olama-messages' ); ?>">
												</td>
											</tr>
											<tr>
												<th scope="row">
													<label for="campaign-study-year"><?php esc_html_e( 'Study Year Scope', 'olama-messages' ); ?></label>
												</th>
												<td>
													<select id="campaign-study-year" name="study_year" required style="max-width:25rem;width:100%;">
														<option value=""><?php esc_html_e( '— Select Study Year —', 'olama-messages' ); ?></option>
														<?php foreach ( $study_years as $yr ) : ?>
															<option value="<?php echo esc_attr( $yr ); ?>" <?php selected( $campaign['study_year'] ?? '', $yr ); ?>>
																<?php echo esc_html( $yr ); ?>
															</option>
														<?php endforeach; ?>
													</select>
												</td>
											</tr>
											<tr>
												<th scope="row">
													<label for="campaign-template"><?php esc_html_e( 'Message Template', 'olama-messages' ); ?></label>
												</th>
												<td>
													<select id="campaign-template" name="template_id" required style="max-width:25rem;width:100%;">
														<option value=""><?php esc_html_e( '— Select Template —', 'olama-messages' ); ?></option>
														<?php foreach ( $templates as $tpl ) : ?>
															<option value="<?php echo esc_attr( $tpl['id'] ); ?>" <?php selected( $campaign['template_id'] ?? 0, $tpl['id'] ); ?>>
																<?php echo esc_html( $tpl['name'] ); ?>
															</option>
														<?php endforeach; ?>
													</select>
													<p class="description">
														<a href="<?php echo esc_url( admin_url( 'admin.php?page=olama-messages-templates' ) ); ?>" target="_blank">
															<?php esc_html_e( 'Manage templates in new tab ↗', 'olama-messages' ); ?>
														</a>
													</p>
												</td>
											</tr>
											<tr>
												<th scope="row">
													<label for="campaign-policy"><?php esc_html_e( 'Parent Recipient Policy', 'olama-messages' ); ?></label>
												</th>
												<td>
													<select id="campaign-policy" name="recipient_policy" required style="max-width:25rem;width:100%;">
														<option value="father_first" <?php selected( $campaign['recipient_policy'] ?? 'father_first', 'father_first' ); ?>><?php esc_html_e( 'Father First, Mother Fallback', 'olama-messages' ); ?></option>
														<option value="mother_first" <?php selected( $campaign['recipient_policy'] ?? '', 'mother_first' ); ?>><?php esc_html_e( 'Mother First, Father Fallback', 'olama-messages' ); ?></option>
														<option value="father_only" <?php selected( $campaign['recipient_policy'] ?? '', 'father_only' ); ?>><?php esc_html_e( 'Father Only', 'olama-messages' ); ?></option>
														<option value="mother_only" <?php selected( $campaign['recipient_policy'] ?? '', 'mother_only' ); ?>><?php esc_html_e( 'Mother Only', 'olama-messages' ); ?></option>
														<option value="both_parents" <?php selected( $campaign['recipient_policy'] ?? '', 'both_parents' ); ?>><?php esc_html_e( 'Both Parents (Two Messages)', 'olama-messages' ); ?></option>
													</select>
												</td>
											</tr>
											<tr class="olama-msg-collection-only">
												<th scope="row">
													<label for="campaign-min-balance"><?php esc_html_e( 'Minimum Outstanding Balance', 'olama-messages' ); ?></label>
												</th>
												<td>
													<input type="number" step="0.001" id="campaign-min-balance" name="min_balance" value="<?php echo esc_attr( null !== ( $campaign['min_balance'] ?? null ) ? $campaign['min_balance'] : '' ); ?>" class="regular-text" placeholder="e.g. 10.000"> JOD
													<p class="description"><?php esc_html_e( 'Only notify families who owe this amount or more. Leave blank for no minimum.', 'olama-messages' ); ?></p>
												</td>
											</tr>
											<tr class="olama-msg-collection-only">
												<th scope="row"><?php esc_html_e( 'Exclusions & Filters', 'olama-messages' ); ?></th>
												<td>
													<fieldset>
														<label for="campaign-exclude-credit">
															<input type="checkbox" id="campaign-exclude-credit" name="exclude_credit_balances" value="1" <?php checked( $campaign['exclude_credit_balances'] ?? 1, 1 ); ?>>
															<strong><?php esc_html_e( 'Exclude credit balances (balances less than 0)', 'olama-messages' ); ?></strong>
														</label>
														<br>
														<label for="campaign-exclude-zero" style="display:inline-block;margin-top:0.5rem;">
															<input type="checkbox" id="campaign-exclude-zero" name="exclude_zero_balances" value="1" <?php checked( $campaign['exclude_zero_balances'] ?? 1, 1 ); ?>>
															<strong><?php esc_html_e( 'Exclude zero balances (fully paid accounts)', 'olama-messages' ); ?></strong>
														</label>
													</fieldset>
												</td>
											</tr>
											<tr class="olama-msg-general-only olama-msg-transport-only">
												<th scope="row"><?php esc_html_e( 'Target Filters', 'olama-messages' ); ?></th>
												<td>
													<div class="olama-msg-target-general">
														<label for="campaign-class-name"><strong><?php esc_html_e( 'Grade / Class', 'olama-messages' ); ?></strong></label><br>
														<select id="campaign-class-name" name="class_name" style="max-width:25rem;width:100%;">
															<option value=""><?php esc_html_e( '— Select Grade —', 'olama-messages' ); ?></option>
															<?php foreach ( $class_names as $class_name ) : ?>
																<option value="<?php echo esc_attr( $class_name ); ?>" <?php selected( $campaign['filters']['class_name'] ?? '', $class_name ); ?>>
																	<?php echo esc_html( $class_name ); ?>
																</option>
															<?php endforeach; ?>
														</select>
														<br><br>
														<label for="campaign-section-name"><strong><?php esc_html_e( 'Section', 'olama-messages' ); ?></strong></label><br>
														<select id="campaign-section-name" name="section_name" style="max-width:25rem;width:100%;">
															<option value=""><?php esc_html_e( '— Select Section —', 'olama-messages' ); ?></option>
															<?php foreach ( $section_names as $section_name ) : ?>
																<option value="<?php echo esc_attr( $section_name ); ?>" <?php selected( $campaign['filters']['section_name'] ?? '', $section_name ); ?>>
																	<?php echo esc_html( $section_name ); ?>
																</option>
															<?php endforeach; ?>
														</select>
											</div>
											<div class="olama-msg-target-transport" style="margin-top:1rem;">
												<label for="campaign-transport-class-id"><strong><?php esc_html_e( 'Grade / Class', 'olama-messages' ); ?></strong></label><br>
												<select id="campaign-transport-class-id" name="class_id" style="max-width:25rem;width:100%;">
													<option value=""><?php esc_html_e( '— Select Grade —', 'olama-messages' ); ?></option>
													<?php foreach ( $route_options['classes'] ?? array() as $option ) : ?>
														<option value="<?php echo esc_attr( $option['id'] ); ?>" <?php selected( $transport_class_id, $option['id'] ); ?>><?php echo esc_html( $option['name'] ); ?></option>
													<?php endforeach; ?>
												</select>
												<br><br>
												<label for="campaign-transport-section-id"><strong><?php esc_html_e( 'Section', 'olama-messages' ); ?></strong></label><br>
												<select id="campaign-transport-section-id" name="section_id" style="max-width:25rem;width:100%;">
													<option value=""><?php esc_html_e( '— Select Section —', 'olama-messages' ); ?></option>
													<?php foreach ( $route_options['sections'] ?? array() as $option ) : ?>
														<option value="<?php echo esc_attr( $option['id'] ); ?>" data-class-id="<?php echo esc_attr( $option['class_id'] ); ?>" <?php selected( $transport_section_id, $option['id'] ); ?>><?php echo esc_html( $option['name'] ); ?></option>
													<?php endforeach; ?>
												</select>
												<br><br>
												<label for="campaign-departure-bus"><strong><?php esc_html_e( 'Departure Bus', 'olama-messages' ); ?></strong></label><br>
														<select id="campaign-departure-bus" name="departure_bus" style="max-width:25rem;width:100%;">
															<option value=""><?php esc_html_e( '— Select Departure Bus —', 'olama-messages' ); ?></option>
															<?php foreach ( $route_options['departure'] as $route ) : ?>
																<option value="<?php echo esc_attr( $route['id'] ); ?>" <?php selected( $campaign['filters']['departure_bus'] ?? '', $route['id'] ); ?>>
																	<?php echo esc_html( trim( $route['name'] . ( ! empty( $route['seq'] ) ? ' (' . $route['seq'] . ')' : '' ) ) ); ?>
																</option>
															<?php endforeach; ?>
														</select>
														<br><br>
														<label for="campaign-arrival-bus"><strong><?php esc_html_e( 'Arrival Bus', 'olama-messages' ); ?></strong></label><br>
														<select id="campaign-arrival-bus" name="arrival_bus" style="max-width:25rem;width:100%;">
															<option value=""><?php esc_html_e( '— Select Arrival Bus —', 'olama-messages' ); ?></option>
															<?php foreach ( $route_options['arrival'] as $route ) : ?>
																<option value="<?php echo esc_attr( $route['id'] ); ?>" <?php selected( $campaign['filters']['arrival_bus'] ?? '', $route['id'] ); ?>>
																	<?php echo esc_html( trim( $route['name'] . ( ! empty( $route['seq'] ) ? ' (' . $route['seq'] . ')' : '' ) ) ); ?>
																</option>
															<?php endforeach; ?>
														</select>
														<input type="hidden" id="campaign-bus-name" name="bus_name" value="<?php echo esc_attr( $campaign['filters']['bus_name'] ?? '' ); ?>">
														<br><br>
												<label for="campaign-bus-round"><strong><?php esc_html_e( 'Round', 'olama-messages' ); ?></strong></label><br>
												<select id="campaign-bus-round" name="round_name" style="max-width:25rem;width:100%;">
													<option value=""><?php esc_html_e( '— Select Round —', 'olama-messages' ); ?></option>
													<?php foreach ( $route_options['rounds'] ?? array() as $route ) : ?>
														<option value="<?php echo esc_attr( $route['id'] ); ?>" <?php selected( $campaign['filters']['round_name'] ?? '', $route['id'] ); ?>><?php echo esc_html( $route['name'] ); ?></option>
													<?php endforeach; ?>
												</select>
													</div>
												</td>
											</tr>
										</tbody>
									</table>
									<script type="application/json" id="olama-campaign-meta"><?php echo wp_json_encode( array(
										'target_type'       => $campaign['target_type'] ?? 'collection',
										'class_name'        => $campaign['filters']['class_name'] ?? '',
										'section_name'      => $campaign['filters']['section_name'] ?? '',
										'class_id'          => $campaign['filters']['class_id'] ?? '',
										'section_id'        => $campaign['filters']['section_id'] ?? '',
										'bus_name'          => $campaign['filters']['bus_name'] ?? '',
										'round_name'        => $campaign['filters']['round_name'] ?? '',
										'departure_bus'     => $campaign['filters']['departure_bus'] ?? '',
										'arrival_bus'       => $campaign['filters']['arrival_bus'] ?? '',
										'class_options'     => $class_names,
										'section_options'   => $section_names,
										'route_options'     => $route_options,
										'study_year_default'=> $selected_study_year,
									) ); ?></script>

									<div style="margin-top:1.5rem;border-top:1px solid #eee;padding-top:1.25rem;">
										<?php submit_button( $is_edit ? __( 'Update Campaign Draft', 'olama-messages' ) : __( 'Save Campaign Draft', 'olama-messages' ), 'primary', 'submit', false ); ?>
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=olama-messages-campaigns' ) ); ?>" class="button" style="margin-left:0.5rem;">
											<?php esc_html_e( 'Cancel', 'olama-messages' ); ?>
										</a>
									</div>
								</form>
							</div>
						</div>
					</div>

					<!-- Right Sidebar Panel (Live Preview) -->
					<div id="postbox-container-1" class="postbox-container">
						<div class="postbox" id="olama-campaign-preview-box" style="border-color:#3858e9;">
							<div class="postbox-header" style="background:#f0f2ff;border-bottom-color:#dee2fe;">
								<h2 class="hndle" style="color:#1e293b;"><?php esc_html_e( 'Live Candidate Preview', 'olama-messages' ); ?></h2>
							</div>
							<div class="inside" id="olama-campaign-preview-results" style="padding:0.75rem 0.5rem;">
								<!-- Counts Grid -->
								<div class="olama-campaign-preview-stats" style="display:grid;grid-template-columns:repeat(3, 1fr);gap:0.5rem;margin-bottom:1rem;">
									<div class="olama-campaign-preview-stat-card" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:0.375rem;padding:0.5rem;text-align:center;">
										<span class="olama-campaign-preview-stat-val" id="olama-campaign-stat-candidates" style="font-size:1.25rem;font-weight:700;display:block;">0</span>
										<span class="olama-campaign-preview-stat-label" style="font-size:0.75rem;color:#64748b;"><?php esc_html_e( 'Candidates', 'olama-messages' ); ?></span>
									</div>
									<div class="olama-campaign-preview-stat-card" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:0.375rem;padding:0.5rem;text-align:center;">
										<span class="olama-campaign-preview-stat-val" id="olama-campaign-stat-included" style="font-size:1.25rem;font-weight:700;color:#16a34a;display:block;">0</span>
										<span class="olama-campaign-preview-stat-label" style="font-size:0.75rem;color:#166534;"><?php esc_html_e( 'Included', 'olama-messages' ); ?></span>
									</div>
									<div class="olama-campaign-preview-stat-card" style="background:#fef2f2;border:1px solid #fecaca;border-radius:0.375rem;padding:0.5rem;text-align:center;">
										<span class="olama-campaign-preview-stat-val" id="olama-campaign-stat-excluded" style="font-size:1.25rem;font-weight:700;color:#dc2626;display:block;">0</span>
										<span class="olama-campaign-preview-stat-label" style="font-size:0.75rem;color:#991b1b;"><?php esc_html_e( 'Excluded', 'olama-messages' ); ?></span>
									</div>
								</div>
								<div id="olama-campaign-preview-guidance" class="notice notice-warning inline" style="display:none;margin:0 0 12px;"><p></p></div>

								<!-- Live List -->
								<div class="olama-campaign-preview-controls">
									<label><input type="checkbox" name="show_excluded" id="olama-campaign-show-excluded" value="1"> <strong><?php esc_html_e( 'Show excluded families', 'olama-messages' ); ?></strong></label>
								</div>
								<div class="olama-campaign-selection-toolbar">
									<button type="button" class="button" id="olama-campaign-select-page"><?php esc_html_e( 'Select eligible on this page', 'olama-messages' ); ?></button>
									<button type="button" class="button" id="olama-campaign-clear-page"><?php esc_html_e( 'Clear this page', 'olama-messages' ); ?></button>
									<strong id="olama-campaign-selection-summary"></strong>
								</div>
								<div class="olama-campaign-preview-table-wrap" style="max-height:30rem;overflow-y:auto;border:1px solid #e2e8f0;border-radius:0.375rem;">
									<table class="widefat striped" id="olama-campaign-preview-table" style="box-shadow:none;border:none;">
										<thead>
										<tr>
											<th class="check-column"><input type="checkbox" id="olama-campaign-toggle-page" aria-label="<?php esc_attr_e( 'Select all eligible recipients on this page', 'olama-messages' ); ?>"></th>
											<th><button type="button" class="olama-campaign-sort" data-sort-field="family_id"><?php esc_html_e( 'Family ID', 'olama-messages' ); ?> <span></span></button></th>
												<th><button type="button" class="olama-campaign-sort" data-sort-field="recipient"><?php esc_html_e( 'Recipient', 'olama-messages' ); ?> <span></span></button></th>
												<th><?php esc_html_e( 'Normalized Phone', 'olama-messages' ); ?></th>
												<th><button type="button" class="olama-campaign-sort" data-sort-field="balance"><?php esc_html_e( 'Balance', 'olama-messages' ); ?> <span></span></button></th>
												<th><?php esc_html_e( 'Status', 'olama-messages' ); ?></th>
												<th><?php esc_html_e( 'Actions', 'olama-messages' ); ?></th>
											</tr>
										</thead>
										<tbody id="olama-campaign-preview-tbody">
											<tr>
											<td colspan="7" style="text-align:center;padding:1.5rem;color:#64748b;">
													<?php esc_html_e( 'Fill the Study Year and select a template to run preview.', 'olama-messages' ); ?>
												</td>
											</tr>
										</tbody>
									</table>
								</div>
								<div class="olama-campaign-preview-pagination">
									<label class="olama-campaign-per-page"><?php esc_html_e( 'Rows per page:', 'olama-messages' ); ?>
										<select id="olama-campaign-preview-per-page">
											<option value="10">10</option>
											<option value="25" selected>25</option>
											<option value="50">50</option>
											<option value="100">100</option>
										</select>
									</label>
									<button type="button" class="button" id="olama-campaign-preview-prev"><?php esc_html_e( 'Previous', 'olama-messages' ); ?></button>
									<div id="olama-campaign-preview-pages" class="olama-campaign-page-numbers" aria-label="<?php esc_attr_e( 'Candidate preview pages', 'olama-messages' ); ?>"></div>
									<span id="olama-campaign-preview-page-label" class="screen-reader-text"></span>
									<button type="button" class="button" id="olama-campaign-preview-next"><?php esc_html_e( 'Next', 'olama-messages' ); ?></button>
								</div>
							</div>
						</div>
					</div>

				</div>
			</div>
		</div>

		<!-- SMS Preview Modal (Reused) -->
		<div id="olama-msg-sms-modal" class="olama-msg-modal" style="display:none;" role="dialog" aria-modal="true">
			<div class="olama-msg-modal__backdrop"></div>
			<div class="olama-msg-modal__box">
				<button class="olama-msg-modal__close" id="olama-msg-sms-modal-close" aria-label="Close">&times;</button>
				<h2><?php esc_html_e( 'SMS Draft Preview', 'olama-messages' ); ?></h2>
				<div class="olama-msg-sms-bubble" id="olama-msg-sms-text" dir="rtl" style="font-family:Tahoma, Arial, sans-serif;font-size:1.05em;"></div>
				<div class="olama-msg-sms-meta">
					<span id="olama-msg-sms-chars"></span>
					<span id="olama-msg-sms-parts"></span>
				</div>
			</div>
		</div>
		<?php
	}

	// ─── Page: Templates (Phase 2) ───────────────────────────────────────────

	public function page_templates() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'olama-messages' ) );
		}

		$template_svc = $this->plugin->templates();

		// Handle edit action query
		$edit_id = isset( $_GET['template_id'] ) ? absint( $_GET['template_id'] ) : 0;
		$is_edit = isset( $_GET['action'] ) && $_GET['action'] === 'edit' && $edit_id > 0;
		$edit_tpl = $is_edit ? $template_svc->get_template( $edit_id ) : null;

		$templates = $template_svc->list_templates();

		$this->print_flash();
		?>
		<div class="wrap olama-msg-wrap">
			<h1 class="olama-msg-page-title">
				<span class="dashicons dashicons-email-alt"></span>
				<?php esc_html_e( 'Reusable SMS Templates', 'olama-messages' ); ?>
			</h1>

			<div id="col-container" class="wp-clearfix">

				<!-- Left Column: Add/Edit Form -->
				<div id="col-left" style="width:35%;">
					<div class="col-wrap">
						<div class="postbox">
							<div class="postbox-header">
								<h2 class="hndle">
									<?php echo $is_edit ? esc_html__( 'Edit Template', 'olama-messages' ) : esc_html__( 'Add New Template', 'olama-messages' ); ?>
								</h2>
							</div>
							<div class="inside">
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="olama_msg_save_template">
									<?php if ( $is_edit ) : ?>
										<input type="hidden" name="template_id" value="<?php echo esc_attr( $edit_id ); ?>">
									<?php endif; ?>
									<?php wp_nonce_field( 'olama_msg_save_template', 'olama_msg_template_nonce' ); ?>

									<div class="form-field form-required" style="margin-bottom:1rem;">
										<label for="template-name"><strong><?php esc_html_e( 'Template Name', 'olama-messages' ); ?></strong></label>
										<input type="text" id="template-name" name="name" value="<?php echo esc_attr( $edit_tpl['name'] ?? '' ); ?>" required style="width:100%;margin-top:0.25rem;">
										<p class="description"><?php esc_html_e( 'Descriptive name for admin selection.', 'olama-messages' ); ?></p>
									</div>

									<div class="form-field form-required" style="margin-bottom:1rem;">
										<label for="olama-template-body"><strong><?php esc_html_e( 'Message Body', 'olama-messages' ); ?></strong></label>
										<textarea id="olama-template-body" name="body" rows="8" required style="width:100%;margin-top:0.25rem;font-family:inherit;" dir="rtl"><?php echo esc_textarea( $edit_tpl['body'] ?? '' ); ?></textarea>
										
										<!-- Live Char Counter -->
										<div style="margin-top:0.25rem;font-size:0.85em;color:#64748b;direction:rtl;">
											<?php esc_html_e( 'Length:', 'olama-messages' ); ?>
											<span id="olama-template-char-count"><strong><?php echo esc_html( strlen( $edit_tpl['body'] ?? '' ) ); ?></strong></span> <?php esc_html_e( 'characters', 'olama-messages' ); ?>
											| <span id="olama-template-sms-parts"><strong><?php echo esc_html( ceil( strlen( $edit_tpl['body'] ?? '' ) / 70 ) ); ?></strong></span> <?php esc_html_e( 'SMS parts', 'olama-messages' ); ?>
										</div>

										<p class="description" style="margin-top:0.5rem;line-height:1.4;">
											<?php esc_html_e( 'Placeholders:', 'olama-messages' ); ?><br>
											<code>{sponsor_name}</code> <code>{family_id}</code> <code>{students}</code>
											<code>{balance}</code> <code>{monthly_due}</code> <code>{monthly_due_source}</code>
											<code>{payment_link}</code> <code>{study_year}</code> <code>{school_name}</code>
										</p>
									</div>

									<div class="form-field" style="margin-bottom:1rem;">
										<label for="template-default">
											<input type="checkbox" id="template-default" name="is_default" value="1" <?php checked( $edit_tpl['is_default'] ?? 0, 1 ); ?>>
											<strong><?php esc_html_e( 'Set as default template', 'olama-messages' ); ?></strong>
										</label>
									</div>

									<div class="form-field" style="margin-bottom:1rem;">
										<label for="template-active">
											<input type="checkbox" id="template-active" name="is_active" value="1" <?php checked( $edit_tpl['is_active'] ?? 1, 1 ); ?>>
											<strong><?php esc_html_e( 'Active (available for campaigns)', 'olama-messages' ); ?></strong>
										</label>
									</div>

									<div style="margin-top:1.5rem;">
										<?php submit_button( $is_edit ? __( 'Save Changes', 'olama-messages' ) : __( 'Add Template', 'olama-messages' ), 'primary', 'submit', false ); ?>
										<?php if ( $is_edit ) : ?>
											<a href="<?php echo esc_url( admin_url( 'admin.php?page=olama-messages-templates' ) ); ?>" class="button" style="margin-left:0.5rem;">
												<?php esc_html_e( 'Cancel Edit', 'olama-messages' ); ?>
											</a>
										<?php endif; ?>
									</div>
								</form>
							</div>
						</div>
					</div>
				</div>

				<!-- Right Column: List Table -->
				<div id="col-right" style="width:63%;">
					<div class="col-wrap">
						<div class="olama-msg-table-wrap">
							<table class="olama-msg-table widefat striped">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Name', 'olama-messages' ); ?></th>
										<th><?php esc_html_e( 'Body Excerpt', 'olama-messages' ); ?></th>
										<th><?php esc_html_e( 'Default', 'olama-messages' ); ?></th>
										<th><?php esc_html_e( 'Status', 'olama-messages' ); ?></th>
										<th><?php esc_html_e( 'Actions', 'olama-messages' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php if ( empty( $templates ) ) : ?>
										<tr><td colspan="5" style="text-align:center;"><?php esc_html_e( 'No templates created yet.', 'olama-messages' ); ?></td></tr>
									<?php else : ?>
										<?php foreach ( $templates as $t ) :
											$del_url = wp_nonce_url(
												admin_url( 'admin-post.php?action=olama_msg_delete_template&template_id=' . $t['id'] ),
												'olama_msg_del_tpl_' . $t['id']
											);
										?>
										<tr>
											<td>
												<strong>
													<a href="<?php echo esc_url( admin_url( 'admin.php?page=olama-messages-templates&action=edit&template_id=' . $t['id'] ) ); ?>">
														<?php echo esc_html( $t['name'] ); ?>
													</a>
												</strong>
											</td>
											<td dir="rtl" style="text-align:right;font-family:Tahoma, Arial, sans-serif;font-size:0.9em;max-width:20rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
												<?php echo esc_html( $t['body'] ); ?>
											</td>
											<td>
												<?php if ( $t['is_default'] ) : ?>
													<span class="olama-msg-badge olama-msg-badge--active"><?php esc_html_e( 'Default', 'olama-messages' ); ?></span>
												<?php else : ?>
													<span class="olama-msg-muted">—</span>
												<?php endif; ?>
											</td>
											<td>
												<?php echo $t['is_active'] 
													? '<span class="olama-msg-pill olama-msg-pill--ok">✓ Active</span>'
													: '<span class="olama-msg-pill olama-msg-pill--err">✗ Inactive</span>'; ?>
											</td>
											<td class="olama-msg-actions">
												<a href="<?php echo esc_url( admin_url( 'admin.php?page=olama-messages-templates&action=edit&template_id=' . $t['id'] ) ); ?>" class="button button-small">
													<?php esc_html_e( 'Edit', 'olama-messages' ); ?>
												</a>
												<?php if ( ! $t['is_default'] ) : ?>
													<a href="<?php echo esc_url( $del_url ); ?>" class="button button-small button-link-delete" onclick="return confirm('<?php esc_attr_e( 'Delete this template permanently?', 'olama-messages' ); ?>');">
														<?php esc_html_e( 'Delete', 'olama-messages' ); ?>
													</a>
												<?php endif; ?>
											</td>
										</tr>
										<?php endforeach; ?>
									<?php endif; ?>
								</tbody>
							</table>
						</div>
					</div>
				</div>

			</div>
		</div>
		<?php
	}

	// ─── Page: SMS Dispatch Queue (Phase 2) ──────────────────────────────────────

	public function page_queue() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'olama-messages' ) );
		}

		global $wpdb;

		// Fetch all prepared/cancelled campaigns for filtering
		$campaigns = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, title FROM {$wpdb->prefix}olama_msg_campaigns WHERE status IN (%s, %s) ORDER BY id DESC", 'prepared', 'cancelled' ),
			ARRAY_A
		);

		// Get filters
		$f_campaign_id = isset( $_GET['campaign_id'] ) ? absint( $_GET['campaign_id'] ) : 0;
		$f_status      = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
		$paged         = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$per_page      = 25;
		$offset        = ( $paged - 1 ) * $per_page;

		// Build WHERE
		$where  = array();
		$values = array();

		if ( $f_campaign_id > 0 ) {
			$where[]  = 'q.campaign_id = %d';
			$values[] = $f_campaign_id;
		}

		if ( ! empty( $f_status ) ) {
			$where[]  = 'q.status = %s';
			$values[] = $f_status;
		}

		$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

		// Query queue items with JOINs to retrieve campaign and recipient details
		$query = $wpdb->prepare(
			"SELECT q.*, c.title as campaign_title, r.recipient_name, r.recipient_type
			 FROM {$wpdb->prefix}olama_msg_queue q
			 LEFT JOIN {$wpdb->prefix}olama_msg_campaigns c ON q.campaign_id = c.id
			 LEFT JOIN {$wpdb->prefix}olama_msg_campaign_recipients r ON q.campaign_recipient_id = r.id
			 {$where_sql}
			 ORDER BY q.id ASC
			 LIMIT %d OFFSET %d",
			array_merge( $values, array( $per_page, $offset ) )
		);

		$queue_items = $wpdb->get_results( $query, ARRAY_A );

		// Count total
		$total = (int) $wpdb->get_var(
			$where_sql 
				? $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}olama_msg_queue q {$where_sql}", $values )
				: "SELECT COUNT(*) FROM {$wpdb->prefix}olama_msg_queue"
		);

		$total_pages = (int) ceil( $total / $per_page );

		$this->print_flash();
		?>
		<div class="wrap olama-msg-wrap">
			<h1 class="olama-msg-page-title">
				<span class="dashicons dashicons-list-view"></span>
				<?php esc_html_e( 'SMS Dispatch Queue', 'olama-messages' ); ?>
			</h1>

			<div class="notice notice-info inline">
				<p>
					<?php esc_html_e( 'Messages are sent only when a campaign is started and an authenticated Windows agent dispatcher is enabled. Safety mode currently keeps sending to one prepared message at a time so the first message can be verified before the queue continues.', 'olama-messages' ); ?>
				</p>
			</div>

			<!-- Filters Form -->
			<form method="get" class="olama-msg-filters" style="margin-bottom:1.5rem;padding:0.75rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:0.375rem;">
				<input type="hidden" name="page" value="olama-messages-queue">

				<div class="olama-msg-filter-group" style="display:inline-block;margin-right:1rem;">
					<label for="queue-campaign"><strong><?php esc_html_e( 'Filter by Campaign', 'olama-messages' ); ?></strong></label>
					<select id="queue-campaign" name="campaign_id" style="margin-left:0.5rem;">
						<option value=""><?php esc_html_e( '— All Campaigns —', 'olama-messages' ); ?></option>
						<?php foreach ( $campaigns as $camp ) : ?>
							<option value="<?php echo esc_attr( $camp['id'] ); ?>" <?php selected( $f_campaign_id, $camp['id'] ); ?>>
								<?php echo esc_html( $camp['title'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="olama-msg-filter-group" style="display:inline-block;margin-right:1rem;">
					<label for="queue-status"><strong><?php esc_html_e( 'Status', 'olama-messages' ); ?></strong></label>
					<select id="queue-status" name="status" style="margin-left:0.5rem;">
						<option value=""><?php esc_html_e( '— All Statuses —', 'olama-messages' ); ?></option>
						<option value="prepared" <?php selected( $f_status, 'prepared' ); ?>><?php esc_html_e( 'Prepared', 'olama-messages' ); ?></option>
						<option value="reserved" <?php selected( $f_status, 'reserved' ); ?>><?php esc_html_e( 'Reserved', 'olama-messages' ); ?></option>
						<option value="retry_wait" <?php selected( $f_status, 'retry_wait' ); ?>><?php esc_html_e( 'Retry Wait', 'olama-messages' ); ?></option>
						<option value="sent" <?php selected( $f_status, 'sent' ); ?>><?php esc_html_e( 'Sent', 'olama-messages' ); ?></option>
						<option value="failed" <?php selected( $f_status, 'failed' ); ?>><?php esc_html_e( 'Failed', 'olama-messages' ); ?></option>
						<option value="cancelled" <?php selected( $f_status, 'cancelled' ); ?>><?php esc_html_e( 'Cancelled', 'olama-messages' ); ?></option>
					</select>
				</div>

				<button type="submit" class="button button-primary"><?php esc_html_e( 'Filter', 'olama-messages' ); ?></button>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=olama-messages-queue' ) ); ?>" class="button"><?php esc_html_e( 'Reset', 'olama-messages' ); ?></a>
			</form>

			<p class="olama-msg-total-count">
				<?php printf( esc_html__( 'Total prepared records in queue: %d', 'olama-messages' ), $total ); ?>
			</p>

			<?php if ( empty( $queue_items ) ) : ?>
				<div class="olama-msg-empty">
					<?php esc_html_e( 'No prepared records found in the queue.', 'olama-messages' ); ?>
				</div>
			<?php else : ?>

			<div class="olama-msg-table-wrap">
				<table class="olama-msg-table widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Campaign', 'olama-messages' ); ?></th>
							<th><?php esc_html_e( 'Family ID', 'olama-messages' ); ?></th>
							<th><?php esc_html_e( 'Recipient Name', 'olama-messages' ); ?></th>
							<th><?php esc_html_e( 'Phone (E.164)', 'olama-messages' ); ?></th>
							<th style="max-width:30rem;"><?php esc_html_e( 'Rendered SMS Preview (Arabic)', 'olama-messages' ); ?></th>
							<th><?php esc_html_e( 'Size', 'olama-messages' ); ?></th>
							<th><?php esc_html_e( 'Status', 'olama-messages' ); ?></th>
							<th><?php esc_html_e( 'Created', 'olama-messages' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'olama-messages' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $queue_items as $item ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $item['campaign_title'] ); ?></strong></td>
							<td><code><?php echo esc_html( $item['family_id'] ); ?></code></td>
							<td>
								<?php echo esc_html( $item['recipient_name'] ); ?>
								<br><span class="description" style="font-size:0.8em;">(<?php echo esc_html( $item['recipient_type'] === 'father' ? __( 'Father', 'olama-messages' ) : ( $item['recipient_type'] === 'mother' ? __( 'Mother', 'olama-messages' ) : __( 'Sponsor', 'olama-messages' ) ) ); ?>)</span>
							</td>
							<td><code><?php echo esc_html( $item['phone_e164'] ); ?></code></td>
							<td dir="rtl" style="text-align:right;font-family:Tahoma, Arial, sans-serif;font-size:0.9em;padding:0.75rem;line-height:1.4;">
								<?php echo esc_html( $item['message_body_preview'] ); ?>
							</td>
							<td>
								<span title="<?php esc_attr_e( 'Character count / SMS parts count', 'olama-messages' ); ?>">
									<?php echo esc_html( $item['message_char_count'] ); ?> / <strong><?php echo esc_html( $item['message_sms_parts'] ); ?></strong>
								</span>
							</td>
							<td><?php echo $this->status_badge( $item['status'] ); ?></td>
							<td><?php echo esc_html( $item['created_at'] ); ?></td>
							<td>
								<?php
									$delete_queue_url = wp_nonce_url(
										admin_url( 'admin-post.php?action=olama_msg_delete_queue_item&queue_id=' . absint( $item['id'] ) ),
										'olama_msg_delete_queue_item_' . $item['id']
									);
								?>
								<a href="<?php echo esc_url( $delete_queue_url ); ?>"
									class="button button-small button-link-delete"
									onclick="return confirm('<?php esc_attr_e( 'Delete this queue item? It will be removed from dispatch.', 'olama-messages' ); ?>');">
									<?php esc_html_e( 'Delete', 'olama-messages' ); ?>
								</a>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<?php if ( $total_pages > 1 ) :
				$base_url = admin_url( 'admin.php?page=olama-messages-queue&campaign_id=' . $f_campaign_id . '&status=' . rawurlencode( $f_status ) );
			?>
			<div class="olama-msg-pagination">
				<?php for ( $p = 1; $p <= $total_pages; $p++ ) : ?>
					<?php if ( $p === $paged ) : ?>
						<span class="olama-msg-page-current"><?php echo esc_html( $p ); ?></span>
					<?php else : ?>
						<a href="<?php echo esc_url( $base_url . '&paged=' . $p ); ?>"><?php echo esc_html( $p ); ?></a>
					<?php endif; ?>
				<?php endfor; ?>
			</div>
			<?php endif; ?>

			<?php endif; ?>
		</div>
		<?php
	}

	// ─── Page: Recipients Preview (Phase 1.5) ────────────────────────────────

	public function page_recipients() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'olama-messages' ) );
		}

		$provider   = $this->plugin->provider();
		$core_ok    = $provider->is_core_available();
		$study_years = $core_ok ? $provider->get_available_study_years() : array();

		// Filters from GET.
		$f_study_year   = isset( $_GET['study_year'] )   ? sanitize_text_field( wp_unslash( $_GET['study_year'] ) )   : '';
		$f_family_id    = isset( $_GET['family_id'] )    ? sanitize_text_field( wp_unslash( $_GET['family_id'] ) )    : '';
		$f_class_name   = isset( $_GET['class_name'] )   ? sanitize_text_field( wp_unslash( $_GET['class_name'] ) )   : '';
		$f_section_name = isset( $_GET['section_name'] ) ? sanitize_text_field( wp_unslash( $_GET['section_name'] ) ) : '';
		$paged          = isset( $_GET['paged'] )         ? max( 1, absint( $_GET['paged'] ) )                         : 1;
		$per_page       = 25;
		$offset         = ( $paged - 1 ) * $per_page;

		$result = array( 'items' => array(), 'total' => 0, 'financial_available' => false, 'financial_warning' => null );
		if ( $core_ok ) {
			$result = $provider->get_recipients_preview( array(
				'study_year'   => $f_study_year,
				'family_id'    => $f_family_id,
				'class_name'   => $f_class_name,
				'section_name' => $f_section_name,
				'min_balance'  => isset( $_GET['min_balance'] ) ? sanitize_text_field( wp_unslash( $_GET['min_balance'] ) ) : '',
				'limit'        => $per_page,
				'offset'       => $offset,
			) );
		}

		$this->print_flash();
		?>
		<div class="wrap olama-msg-wrap">
			<h1 class="olama-msg-page-title">
				<span class="dashicons dashicons-groups"></span>
				<?php esc_html_e( 'Recipients Preview', 'olama-messages' ); ?>
			</h1>

			<?php if ( ! $core_ok ) : ?>
				<div class="notice notice-error"><p>
					<?php esc_html_e( 'Olama Core is not active. Please activate the Olama Core plugin to load recipient data.', 'olama-messages' ); ?>
				</p></div>
			<?php else : ?>

				<?php if ( ! ( $result['financial_available'] ?? false ) ) : ?>
					<div class="notice notice-warning inline"><p>
						<strong><?php esc_html_e( 'Financial Data:', 'olama-messages' ); ?></strong>
						<?php echo esc_html( $result['financial_warning'] ?? Olama_Messages_Core_Provider::FINANCIAL_ADMIN_NOTICE ); ?>
						<br><em><?php esc_html_e( 'Balance and monthly due columns show N/A until a financial provider is connected.', 'olama-messages' ); ?></em>
					</p></div>
				<?php endif; ?>

				<!-- Filters -->
				<form method="get" class="olama-msg-filters" id="olama-msg-recipient-filters">
					<input type="hidden" name="page" value="olama-messages-recipients">

					<div class="olama-msg-filter-group">
						<label for="filter-study-year"><?php esc_html_e( 'Study Year', 'olama-messages' ); ?></label>
						<select id="filter-study-year" name="study_year">
							<option value=""><?php esc_html_e( '— All Years —', 'olama-messages' ); ?></option>
							<?php foreach ( $study_years as $yr ) : ?>
								<option value="<?php echo esc_attr( $yr ); ?>" <?php selected( $f_study_year, $yr ); ?>>
									<?php echo esc_html( $yr ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="olama-msg-filter-group">
						<label for="filter-family-id"><?php esc_html_e( 'Family ID', 'olama-messages' ); ?></label>
						<input type="text" id="filter-family-id" name="family_id" value="<?php echo esc_attr( $f_family_id ); ?>" placeholder="<?php esc_attr_e( 'e.g. 459', 'olama-messages' ); ?>">
					</div>

					<?php $f_min_bal = isset( $_GET['min_balance'] ) ? sanitize_text_field( wp_unslash( $_GET['min_balance'] ) ) : ''; ?>
					<div class="olama-msg-filter-group">
						<label for="filter-min-balance"><?php esc_html_e( 'Min Balance', 'olama-messages' ); ?></label>
						<input type="number" step="0.01" id="filter-min-balance" name="min_balance" value="<?php echo esc_attr( $f_min_bal ); ?>" placeholder="<?php esc_attr_e( 'e.g. 100', 'olama-messages' ); ?>" class="small-text">
					</div>

					<div class="olama-msg-filter-group">
						<label for="filter-class"><?php esc_html_e( 'Class', 'olama-messages' ); ?></label>
						<input type="text" id="filter-class" name="class_name" value="<?php echo esc_attr( $f_class_name ); ?>" placeholder="<?php esc_attr_e( 'Class name…', 'olama-messages' ); ?>">
					</div>

					<div class="olama-msg-filter-group">
						<label for="filter-section"><?php esc_html_e( 'Section', 'olama-messages' ); ?></label>
						<input type="text" id="filter-section" name="section_name" value="<?php echo esc_attr( $f_section_name ); ?>" placeholder="<?php esc_attr_e( 'Section…', 'olama-messages' ); ?>">
					</div>

					<div class="olama-msg-filter-actions">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Filter', 'olama-messages' ); ?></button>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=olama-messages-recipients' ) ); ?>" class="button"><?php esc_html_e( 'Reset', 'olama-messages' ); ?></a>
					</div>
				</form>

				<p class="olama-msg-total-count">
					<?php printf(
						esc_html__( 'Total families: %d', 'olama-messages' ),
						(int) $result['total']
					); ?>
				</p>

				<?php if ( $result['items'] ) : ?>
				<div class="olama-msg-table-wrap">
					<table class="olama-msg-table widefat">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Family ID', 'olama-messages' ); ?></th>
								<th><?php esc_html_e( 'Sponsor / Parent', 'olama-messages' ); ?></th>
								<th><?php esc_html_e( 'Students', 'olama-messages' ); ?></th>
								<th><?php esc_html_e( 'Father Mobile', 'olama-messages' ); ?></th>
								<th><?php esc_html_e( 'Mother Mobile', 'olama-messages' ); ?></th>
								<th><?php esc_html_e( 'Balance', 'olama-messages' ); ?></th>
								<th><?php esc_html_e( 'Monthly Due', 'olama-messages' ); ?></th>
								<th><?php esc_html_e( 'Token', 'olama-messages' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'olama-messages' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $result['items'] as $item ) :
								$family_id_val = esc_attr( $item['family_id'] );
								$gen_url = wp_nonce_url(
									admin_url( 'admin-post.php?action=olama_msg_generate_token&family_id=' . rawurlencode( $item['family_id'] ) . '&study_year=' . rawurlencode( $f_study_year ) ),
									'olama_msg_gen_' . $item['family_id']
								);
							?>
							<tr>
								<td><code><?php echo esc_html( $item['family_id'] ); ?></code></td>
								<td><?php echo esc_html( $item['sponsor_name'] ); ?></td>
								<td>
									<?php if ( $item['students'] ) : ?>
										<ul class="olama-msg-student-list">
											<?php foreach ( $item['students'] as $s ) : ?>
												<li><?php echo esc_html( $s ); ?></li>
											<?php endforeach; ?>
										</ul>
									<?php else : ?>
										<span class="olama-msg-muted">—</span>
									<?php endif; ?>
								</td>
								<td><code><?php echo esc_html( $item['father_mobile'] ?: '—' ); ?></code></td>
								<td><code><?php echo esc_html( $item['mother_mobile'] ?: '—' ); ?></code></td>
								<td>
									<?php if ( ( $item['financial_available'] ?? false ) && $item['balance'] !== null ) : ?>
										<?php echo esc_html( number_format( (float) $item['balance'], 3 ) ); ?> JOD
									<?php else : ?>
										<span class="olama-msg-unavailable" title="<?php esc_attr_e( 'Financial data not available yet', 'olama-messages' ); ?>"><?php esc_html_e( 'N/A', 'olama-messages' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( ( $item['financial_available'] ?? false ) && $item['monthly_due'] !== null ) : ?>
										<?php echo esc_html( number_format( (float) $item['monthly_due'], 3 ) ); ?> JOD
									<?php else : ?>
										<span class="olama-msg-unavailable" title="<?php esc_attr_e( 'Financial data not available yet', 'olama-messages' ); ?>"><?php esc_html_e( 'N/A', 'olama-messages' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php
									// Check if this family has any active token, scoped by study year if selected.
									global $wpdb;
									$tok_table   = $wpdb->prefix . 'olama_msg_tokens';
									$family_int  = absint( $item['family_id'] );
									
									$active_tok_query = 'SELECT COUNT(*) FROM `' . esc_sql( $tok_table ) . '`
										 WHERE family_id = %d AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > %s)';
									$active_tok_args = array( $family_int, current_time( 'mysql' ) );

									if ( $f_study_year !== '' ) {
										$active_tok_query .= ' AND study_year = %s';
										$active_tok_args[] = $f_study_year;
									}

									$active_tok = $family_int ? $wpdb->get_var( $wpdb->prepare(
										$active_tok_query,
										$active_tok_args
									) ) : 0;
									?>
									<?php if ( $active_tok > 0 ) : ?>
										<?php echo $this->status_badge( 'active' ); ?>
									<?php else : ?>
										<span class="olama-msg-muted"><?php esc_html_e( 'None', 'olama-messages' ); ?></span>
									<?php endif; ?>
								</td>
								<td class="olama-msg-actions">
									<a href="<?php echo esc_url( $gen_url ); ?>" class="button button-small">
										<?php esc_html_e( 'Generate Token', 'olama-messages' ); ?>
									</a>
									<button
										type="button"
										class="button button-small olama-msg-preview-sms-btn"
										data-family-id="<?php echo esc_attr( $item['family_id'] ); ?>"
										data-oracle-id="<?php echo esc_attr( $item['oracle_family_id'] ?? $item['family_id'] ); ?>"
										data-sponsor="<?php echo esc_attr( $item['sponsor_name'] ); ?>"
										data-students="<?php echo esc_attr( implode( '، ', $item['students'] ) ); ?>"
										data-financial-available="<?php echo ! empty( $item['financial_available'] ) ? '1' : '0'; ?>"
										data-balance="<?php echo esc_attr( $item['balance'] ?? '' ); ?>"
										data-monthly-due="<?php echo esc_attr( $item['monthly_due'] ?? '' ); ?>"
										data-study-year="<?php echo esc_attr( $f_study_year ); ?>"
									>
										<?php esc_html_e( 'SMS Template Preview', 'olama-messages' ); ?>
									</button>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<?php
				// Pagination.
				$total_pages = (int) ceil( $result['total'] / $per_page );
				if ( $total_pages > 1 ) :
					$base_url = admin_url( 'admin.php?page=olama-messages-recipients&study_year=' . rawurlencode( $f_study_year ) . '&family_id=' . rawurlencode( $f_family_id ) . '&class_name=' . rawurlencode( $f_class_name ) . '&section_name=' . rawurlencode( $f_section_name ) );
				?>
				<div class="olama-msg-pagination">
					<?php for ( $p = 1; $p <= $total_pages; $p++ ) : ?>
						<?php if ( $p === $paged ) : ?>
							<span class="olama-msg-page-current"><?php echo esc_html( $p ); ?></span>
						<?php else : ?>
							<a href="<?php echo esc_url( $base_url . '&paged=' . $p ); ?>"><?php echo esc_html( $p ); ?></a>
						<?php endif; ?>
					<?php endfor; ?>
				</div>
				<?php endif; ?>

				<?php else : ?>
					<div class="olama-msg-empty">
						<?php esc_html_e( 'No recipients found matching your filters. If the list is empty, ensure Olama Core has synced data from Oracle.', 'olama-messages' ); ?>
					</div>
				<?php endif; ?>

			<?php endif; /* $core_ok */ ?>
		</div>

		<!-- SMS Preview Modal -->
		<div id="olama-msg-sms-modal" class="olama-msg-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="olama-msg-sms-modal-title">
			<div class="olama-msg-modal__backdrop"></div>
			<div class="olama-msg-modal__box">
				<button class="olama-msg-modal__close" id="olama-msg-sms-modal-close" aria-label="Close">&times;</button>
				<h2 id="olama-msg-sms-modal-title"><?php esc_html_e( 'SMS Template Preview', 'olama-messages' ); ?></h2>
				<div class="olama-msg-sms-bubble" id="olama-msg-sms-text" dir="rtl"></div>
				<div class="olama-msg-sms-meta">
					<span id="olama-msg-sms-chars"></span>
					<span id="olama-msg-sms-parts"></span>
				</div>
			</div>
		</div>
		<?php
	}

	// ─── Page: Payment Report Links (Tokens) ─────────────────────────────────

	public function page_tokens() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'olama-messages' ) );
		}

		$tokens_svc   = $this->plugin->tokens();
		$campaign_svc = $this->plugin->campaigns();
		$paged        = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$per_page     = isset( $_GET['per_page'] ) ? max( 10, min( 100, absint( $_GET['per_page'] ) ) ) : 25;
		$offset       = ( $paged - 1 ) * $per_page;
		$f_campaign_id = isset( $_GET['campaign_id'] ) ? absint( $_GET['campaign_id'] ) : 0;
		$f_view_state  = isset( $_GET['view_state'] ) ? sanitize_text_field( wp_unslash( $_GET['view_state'] ) ) : '';
		$tokens_args  = array( 'limit' => $per_page, 'offset' => $offset );
		if ( $f_campaign_id ) {
			$tokens_args['campaign_id'] = $f_campaign_id;
		}
		if ( 'viewed' === $f_view_state ) {
			$tokens_args['viewed_only'] = 1;
		} elseif ( 'not_viewed' === $f_view_state ) {
			$tokens_args['not_viewed_only'] = 1;
		}
		$total        = $tokens_svc->count_tokens( $tokens_args );
		$tokens       = $tokens_svc->get_tokens_list( $tokens_args );
		$total_pages = (int) ceil( $total / $per_page );
		$campaigns    = $campaign_svc->list_campaigns( array( 'limit' => 200, 'offset' => 0, 'orderby' => 'created_at', 'order' => 'DESC' ) );

		// Lookup provider for family names.
		$provider = $this->plugin->provider();
		$core_ok  = $provider->is_core_available();

		$this->print_flash();

		$new_token_id  = isset( $_GET['new_token_id'] ) ? absint( $_GET['new_token_id'] ) : 0;
		$new_token_url = '';
		if ( $new_token_id ) {
			$transient_key = 'olama_msg_new_token_' . get_current_user_id() . '_' . $new_token_id;
			$new_token_url = get_transient( $transient_key );
			if ( $new_token_url ) {
				delete_transient( $transient_key );
			}
		}
		?>
		<div class="wrap olama-msg-wrap">
			<h1 class="olama-msg-page-title">
				<span class="dashicons dashicons-admin-links"></span>
				<?php esc_html_e( 'Payment Report Links', 'olama-messages' ); ?>
			</h1>

			<div style="display:flex; flex-wrap:wrap; gap:12px; align-items:center; margin: 0 0 14px 0;">
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
					<input type="hidden" name="page" value="olama-messages-tokens">
					<label for="olama-msg-campaign-filter"><strong><?php esc_html_e( 'Campaign', 'olama-messages' ); ?></strong></label>
					<select id="olama-msg-campaign-filter" name="campaign_id">
						<option value="0"><?php esc_html_e( 'All campaigns', 'olama-messages' ); ?></option>
						<?php foreach ( $campaigns as $camp ) : ?>
							<option value="<?php echo esc_attr( $camp['id'] ); ?>" <?php selected( $f_campaign_id, (int) $camp['id'] ); ?>>
								<?php echo esc_html( $camp['title'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<label for="olama-msg-per-page"><strong><?php esc_html_e( 'Per page', 'olama-messages' ); ?></strong></label>
					<select id="olama-msg-per-page" name="per_page">
						<?php foreach ( array( 10, 25, 50, 100 ) as $n ) : ?>
							<option value="<?php echo esc_attr( $n ); ?>" <?php selected( $per_page, $n ); ?>><?php echo esc_html( $n ); ?></option>
						<?php endforeach; ?>
					</select>
					<label for="olama-msg-view-state"><strong><?php esc_html_e( 'View state', 'olama-messages' ); ?></strong></label>
					<select id="olama-msg-view-state" name="view_state">
						<option value=""><?php esc_html_e( 'All', 'olama-messages' ); ?></option>
						<option value="viewed" <?php selected( $f_view_state, 'viewed' ); ?>><?php esc_html_e( 'Viewed', 'olama-messages' ); ?></option>
						<option value="not_viewed" <?php selected( $f_view_state, 'not_viewed' ); ?>><?php esc_html_e( 'Not viewed', 'olama-messages' ); ?></option>
					</select>
					<button type="submit" class="button"><?php esc_html_e( 'Filter', 'olama-messages' ); ?></button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php esc_attr_e( 'Clear all active payment report links? This will revoke them.', 'olama-messages' ); ?>');">
					<input type="hidden" name="action" value="olama_msg_clear_all_tokens">
					<?php wp_nonce_field( 'olama_msg_clear_all_tokens', 'olama_msg_clear_all_tokens_nonce' ); ?>
					<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Delete All Links', 'olama-messages' ); ?></button>
				</form>
			</div>

			<div class="olama-msg-new-token-box" style="margin-bottom:16px;">
				<h3><?php esc_html_e( 'Generate a manual family link', 'olama-messages' ); ?></h3>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex; flex-wrap:wrap; gap:10px; align-items:end;">
					<input type="hidden" name="action" value="olama_msg_generate_manual_token">
					<?php wp_nonce_field( 'olama_msg_generate_manual_token', 'olama_msg_generate_manual_token_nonce' ); ?>
					<p style="margin:0;">
						<label for="olama-msg-manual-family"><strong><?php esc_html_e( 'Family ID', 'olama-messages' ); ?></strong></label><br>
						<input type="number" id="olama-msg-manual-family" name="family_id" min="1" required>
					</p>
					<p style="margin:0;">
						<label for="olama-msg-manual-study"><strong><?php esc_html_e( 'Study Year', 'olama-messages' ); ?></strong></label><br>
						<input type="text" id="olama-msg-manual-study" name="study_year" placeholder="2026-2027">
					</p>
					<p style="margin:0;">
						<label for="olama-msg-manual-campaign"><strong><?php esc_html_e( 'Campaign', 'olama-messages' ); ?></strong></label><br>
						<select id="olama-msg-manual-campaign" name="campaign_id">
							<option value="0"><?php esc_html_e( 'Manual', 'olama-messages' ); ?></option>
							<?php foreach ( $campaigns as $camp ) : ?>
								<option value="<?php echo esc_attr( $camp['id'] ); ?>"><?php echo esc_html( $camp['title'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Generate Manual Link', 'olama-messages' ); ?></button>
				</form>
			</div>

			<?php if ( $new_token_url ) : ?>
			<div class="olama-msg-new-token-box" id="olama-msg-new-token-box">
				<h3><?php esc_html_e( '⚠ Save this link now — it is shown once only!', 'olama-messages' ); ?></h3>
				<div class="olama-msg-token-url-row">
					<input type="text" id="olama-msg-new-token-url" value="<?php echo esc_url( $new_token_url ); ?>" readonly class="olama-msg-token-url-input">
					<button type="button" class="button button-primary" id="olama-msg-copy-new-url" data-clipboard="olama-msg-new-token-url">
						<?php esc_html_e( 'Copy Link', 'olama-messages' ); ?>
					</button>
				</div>
				<p class="olama-msg-muted"><?php esc_html_e( 'The raw token is not stored in the database. This link is shown once only. If you lose this link, you must regenerate a new one.', 'olama-messages' ); ?></p>
			</div>
			<?php endif; ?>

			<?php if ( ! $tokens ) : ?>
				<div class="olama-msg-empty">
					<?php esc_html_e( 'No tokens generated yet. Use the Recipients Preview page to generate tokens for families.', 'olama-messages' ); ?>
				</div>
			<?php else : ?>

			<div class="olama-msg-table-wrap">
				<table class="olama-msg-table widefat">
					<thead>
						<tr>
							<th><?php esc_html_e( 'ID', 'olama-messages' ); ?></th>
							<th><?php esc_html_e( 'Family ID', 'olama-messages' ); ?></th>
							<th><?php esc_html_e( 'Prefix', 'olama-messages' ); ?></th>
							<th><?php esc_html_e( 'Study Year', 'olama-messages' ); ?></th>
							<th><?php esc_html_e( 'Campaign', 'olama-messages' ); ?></th>
							<th><?php esc_html_e( 'Source', 'olama-messages' ); ?></th>
							<th><?php esc_html_e( 'Created', 'olama-messages' ); ?></th>
							<th><?php esc_html_e( 'Expires', 'olama-messages' ); ?></th>
							<th><?php esc_html_e( 'Views', 'olama-messages' ); ?></th>
							<th><?php esc_html_e( 'Last Viewed', 'olama-messages' ); ?></th>
							<th><?php esc_html_e( 'Status', 'olama-messages' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'olama-messages' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $tokens as $tok ) :
							$status     = $tokens_svc->get_token_status( $tok );
							$revoke_url = wp_nonce_url(
								admin_url( 'admin-post.php?action=olama_msg_revoke_token&token_id=' . absint( $tok['id'] ) ),
								'olama_msg_revoke_' . $tok['id']
							);
							$regen_url  = wp_nonce_url(
								admin_url( 'admin-post.php?action=olama_msg_generate_token&family_id=' . rawurlencode( $tok['family_id'] ) . '&study_year=' . rawurlencode( $tok['study_year'] ?? '' ) ),
								'olama_msg_gen_' . $tok['family_id']
							);
							$delete_url = wp_nonce_url(
								admin_url( 'admin-post.php?action=olama_msg_delete_token&token_id=' . absint( $tok['id'] ) ),
								'olama_msg_delete_token_' . $tok['id']
							);
						?>
						<tr>
							<td><?php echo esc_html( $tok['id'] ); ?></td>
							<td><code><?php echo esc_html( $tok['family_id'] ); ?></code></td>
							<td><code class="olama-msg-prefix"><?php echo esc_html( $tok['token_prefix'] ); ?>…</code></td>
							<td><?php echo esc_html( $tok['study_year'] ?: '—' ); ?></td>
							<td><?php echo esc_html( $tok['campaign_title'] ?? '—' ); ?></td>
							<td><?php echo esc_html( $tok['generated_source'] ?? 'campaign' ); ?></td>
							<td><?php echo esc_html( $tok['created_at'] ); ?></td>
							<td><?php echo esc_html( $tok['expires_at'] ?: '—' ); ?></td>
							<td><?php echo esc_html( $tok['view_count'] ); ?></td>
							<td><?php echo esc_html( $tok['last_viewed_at'] ?: '—' ); ?></td>
							<td><?php echo $this->status_badge( $status ); ?></td>
							<td class="olama-msg-actions">
								<?php if ( 'active' === $status ) : ?>
									<a href="<?php echo esc_url( $revoke_url ); ?>"
										class="button button-small button-link-delete"
										onclick="return confirm('<?php esc_attr_e( 'Revoke this token? It will immediately stop working.', 'olama-messages' ); ?>');">
										<?php esc_html_e( 'Revoke', 'olama-messages' ); ?>
									</a>
								<?php endif; ?>
								<a href="<?php echo esc_url( $regen_url ); ?>" class="button button-small">
									<?php esc_html_e( 'Regenerate', 'olama-messages' ); ?>
								</a>
								<a href="<?php echo esc_url( $delete_url ); ?>"
									class="button button-small button-link-delete"
									onclick="return confirm('<?php esc_attr_e( 'Delete this token permanently? This cannot be undone.', 'olama-messages' ); ?>');">
									<?php esc_html_e( 'Delete', 'olama-messages' ); ?>
								</a>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<?php if ( $total_pages > 1 ) :
				$base_url = admin_url( 'admin.php?page=olama-messages-tokens' );
				if ( $f_campaign_id ) {
					$base_url .= '&campaign_id=' . $f_campaign_id;
				}
				$base_url .= '&per_page=' . $per_page;
			?>
			<div class="olama-msg-pagination">
				<?php for ( $p = 1; $p <= $total_pages; $p++ ) : ?>
					<?php if ( $p === $paged ) : ?>
						<span class="olama-msg-page-current"><?php echo esc_html( $p ); ?></span>
					<?php else : ?>
						<a href="<?php echo esc_url( $base_url . '&paged=' . $p ); ?>"><?php echo esc_html( $p ); ?></a>
					<?php endif; ?>
				<?php endfor; ?>
			</div>
			<?php endif; ?>

			<?php endif; ?>
		</div>
		<?php
	}

	// ─── Page: Settings ──────────────────────────────────────────────────────

	public function page_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'olama-messages' ) );
		}

		$this->print_flash();
		?>
		<div class="wrap olama-msg-wrap">
			<h1 class="olama-msg-page-title">
				<span class="dashicons dashicons-admin-settings"></span>
				<?php esc_html_e( 'Olama Messages — Settings', 'olama-messages' ); ?>
			</h1>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="olama_msg_save_settings">
				<?php wp_nonce_field( 'olama_msg_save_settings', 'olama_msg_settings_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="olama_msg_school_name"><?php esc_html_e( 'School Display Name', 'olama-messages' ); ?></label>
							</th>
							<td>
								<input type="text" id="olama_msg_school_name" name="olama_msg_school_name"
									value="<?php echo esc_attr( get_option( 'olama_msg_school_name', '' ) ); ?>"
									class="regular-text"
									placeholder="<?php esc_attr_e( 'e.g. أكاديمية علماء المستقبل', 'olama-messages' ); ?>">
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="olama_msg_contact_phone"><?php esc_html_e( 'Report Contact Phone', 'olama-messages' ); ?></label>
							</th>
							<td>
								<input type="text" id="olama_msg_contact_phone" name="olama_msg_contact_phone"
									value="<?php echo esc_attr( get_option( 'olama_msg_contact_phone', '' ) ); ?>"
									class="regular-text"
									placeholder="<?php esc_attr_e( 'e.g. 0799999999', 'olama-messages' ); ?>">
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="olama_msg_payment_instructions"><?php esc_html_e( 'Payment Instructions', 'olama-messages' ); ?></label>
							</th>
							<td>
								<textarea id="olama_msg_payment_instructions" name="olama_msg_payment_instructions"
									rows="5" class="large-text"><?php echo esc_textarea( get_option( 'olama_msg_payment_instructions', '' ) ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Shown on the public payment report page.', 'olama-messages' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="olama_msg_token_expiry_days"><?php esc_html_e( 'Default Token Expiry (days)', 'olama-messages' ); ?></label>
							</th>
							<td>
								<input type="number" id="olama_msg_token_expiry_days" name="olama_msg_token_expiry_days"
									value="<?php echo esc_attr( get_option( 'olama_msg_token_expiry_days', 30 ) ); ?>"
									min="0" max="3650" class="small-text">
								<p class="description"><?php esc_html_e( 'Set to 0 for no expiry.', 'olama-messages' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="olama_msg_default_study_year"><?php esc_html_e( 'Default Study Year', 'olama-messages' ); ?></label>
							</th>
							<td>
								<?php
								$years = $this->plugin->provider()->get_available_study_years();
								$saved = get_option( 'olama_msg_default_study_year', '' );
								?>
								<?php if ( $years ) : ?>
									<select id="olama_msg_default_study_year" name="olama_msg_default_study_year">
										<option value=""><?php esc_html_e( '— None —', 'olama-messages' ); ?></option>
										<?php foreach ( $years as $yr ) : ?>
											<option value="<?php echo esc_attr( $yr ); ?>" <?php selected( $saved, $yr ); ?>>
												<?php echo esc_html( $yr ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								<?php else : ?>
									<input type="text" id="olama_msg_default_study_year" name="olama_msg_default_study_year"
										value="<?php echo esc_attr( $saved ); ?>"
										class="regular-text"
										placeholder="<?php esc_attr_e( 'e.g. 2025/2026', 'olama-messages' ); ?>">
								<?php endif; ?>
							</td>
						</tr>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Oracle Financial API Bridge Configuration', 'olama-messages' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Connects read-only balances and due items. If left blank, settings from Olama Oracle Sync will be used automatically.', 'olama-messages' ); ?></p>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="olama_msg_financial_enabled"><?php esc_html_e( 'Enable Financial Adapter', 'olama-messages' ); ?></label>
							</th>
							<td>
								<label>
									<input type="checkbox" id="olama_msg_financial_enabled" name="olama_msg_financial_enabled" value="yes" <?php checked( get_option( 'olama_msg_financial_enabled', 'yes' ), 'yes' ); ?>>
									<?php esc_html_e( 'Load financial balances and payment reports', 'olama-messages' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="olama_msg_oracle_base_url"><?php esc_html_e( 'API Bridge Base URL', 'olama-messages' ); ?></label>
							</th>
							<td>
								<input type="url" id="olama_msg_oracle_base_url" name="olama_msg_oracle_base_url" value="<?php echo esc_attr( get_option( 'olama_msg_oracle_base_url', '' ) ); ?>" class="regular-text" placeholder="http://192.168.0.13:5000">
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="olama_msg_oracle_api_key"><?php esc_html_e( 'API Secret Key', 'olama-messages' ); ?></label>
							</th>
							<td>
								<?php $saved_key = get_option( 'olama_msg_oracle_api_key', '' ); ?>
								<input type="password" id="olama_msg_oracle_api_key" name="olama_msg_oracle_api_key" value="" class="regular-text" placeholder="<?php echo $saved_key ? '••••••••••••••••' : ''; ?>">
								<?php if ( $saved_key ) : ?>
									<p class="description"><?php esc_html_e( 'API key is saved. Leave blank to keep existing key.', 'olama-messages' ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="olama_msg_api_timeout"><?php esc_html_e( 'Request Timeout (sec)', 'olama-messages' ); ?></label>
							</th>
							<td>
								<input type="number" id="olama_msg_api_timeout" name="olama_msg_api_timeout" value="<?php echo esc_attr( get_option( 'olama_msg_api_timeout', 15 ) ); ?>" min="5" max="60" class="small-text">
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( __( 'Save Settings', 'olama-messages' ) ); ?>
			</form>
		</div>
		<?php
	}

	// ─── Handlers: POST actions ───────────────────────────────────────────────

	/** Handle template save/update POST. */
	public function handle_save_template() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'olama-messages' ) );
		}

		check_admin_referer( 'olama_msg_save_template', 'olama_msg_template_nonce' );

		$template_svc = $this->plugin->templates();
		$template_id  = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;

		$data = array(
			'name'       => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'channel'    => 'sms',
			'body'       => sanitize_textarea_field( wp_unslash( $_POST['body'] ?? '' ) ),
			'is_default' => isset( $_POST['is_default'] ) ? 1 : 0,
			'is_active'  => isset( $_POST['is_active'] ) ? 1 : 0,
		);

		// Warn on unknown placeholders.
		$val_res = $template_svc->validate_template_body( $data['body'] );
		$warning_suffix = '';
		if ( ! empty( $val_res['warnings'] ) ) {
			$warning_suffix = ' ' . __( 'Warning: Unknown placeholders were found.', 'olama-messages' );
		}

		if ( $template_id > 0 ) {
			$ok = $template_svc->update_template( $template_id, $data );
			if ( $ok ) {
				$this->set_flash( __( 'Template updated successfully.', 'olama-messages' ) . $warning_suffix, 'success' );
			} else {
				$this->set_flash( __( 'Failed to update template.', 'olama-messages' ), 'error' );
			}
		} else {
			$new_id = $template_svc->create_template( $data );
			if ( $new_id ) {
				$this->set_flash( __( 'Template created successfully.', 'olama-messages' ) . $warning_suffix, 'success' );
			} else {
				$this->set_flash( __( 'Failed to create template.', 'olama-messages' ), 'error' );
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=olama-messages-templates' ) );
		exit;
	}

	/** Handle template deletion POST. */
	public function handle_delete_template() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'olama-messages' ) );
		}

		$template_id = isset( $_GET['template_id'] ) ? absint( $_GET['template_id'] ) : 0;
		if ( ! $template_id ) {
			wp_die( esc_html__( 'Missing template ID.', 'olama-messages' ) );
		}

		check_admin_referer( 'olama_msg_del_tpl_' . $template_id );

		$template_svc = $this->plugin->templates();
		$tpl = $template_svc->get_template( $template_id );

		if ( ! $tpl ) {
			$this->set_flash( __( 'Template not found.', 'olama-messages' ), 'error' );
		} elseif ( $tpl['is_default'] ) {
			$this->set_flash( __( 'Cannot delete the default template.', 'olama-messages' ), 'error' );
		} else {
			global $wpdb;
			$result = $wpdb->delete( $wpdb->prefix . 'olama_msg_templates', array( 'id' => $template_id ) );
			if ( false !== $result ) {
				$this->set_flash( __( 'Template deleted successfully.', 'olama-messages' ), 'success' );
			} else {
				$this->set_flash( __( 'Failed to delete template.', 'olama-messages' ), 'error' );
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=olama-messages-templates' ) );
		exit;
	}

	/** Handle campaign save/update. */
	public function handle_save_campaign() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'olama-messages' ) );
		}

		check_admin_referer( 'olama_msg_save_campaign', 'olama_msg_campaign_nonce' );

		$campaign_svc = $this->plugin->campaigns();
		$campaign_id  = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;

		$data = array(
			'title'                   => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
			'study_year'              => sanitize_text_field( wp_unslash( $_POST['study_year'] ?? '' ) ),
			'template_id'             => isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : null,
			'target_type'             => sanitize_text_field( wp_unslash( $_POST['target_type'] ?? 'collection' ) ),
			'recipient_policy'        => sanitize_text_field( wp_unslash( $_POST['recipient_policy'] ?? 'father_first' ) ),
			'min_balance'             => in_array( sanitize_text_field( wp_unslash( $_POST['target_type'] ?? 'collection' ) ), array( 'collection' ), true ) && isset( $_POST['min_balance'] ) && $_POST['min_balance'] !== '' ? floatval( $_POST['min_balance'] ) : null,
			'exclude_credit_balances' => in_array( sanitize_text_field( wp_unslash( $_POST['target_type'] ?? 'collection' ) ), array( 'collection' ), true ) && isset( $_POST['exclude_credit_balances'] ) ? 1 : 0,
			'exclude_zero_balances'   => in_array( sanitize_text_field( wp_unslash( $_POST['target_type'] ?? 'collection' ) ), array( 'collection' ), true ) && isset( $_POST['exclude_zero_balances'] ) ? 1 : 0,
			'filters_json'            => array(
				'recipient_overrides' => $this->sanitize_recipient_overrides( wp_unslash( $_POST['recipient_overrides'] ?? '{}' ) ),
				'class_name'          => sanitize_text_field( wp_unslash( $_POST['class_name'] ?? '' ) ),
				'section_name'        => sanitize_text_field( wp_unslash( $_POST['section_name'] ?? '' ) ),
				'class_id'            => absint( $_POST['class_id'] ?? 0 ),
				'section_id'          => absint( $_POST['section_id'] ?? 0 ),
				'departure_bus'       => sanitize_text_field( wp_unslash( $_POST['departure_bus'] ?? '' ) ),
				'arrival_bus'         => sanitize_text_field( wp_unslash( $_POST['arrival_bus'] ?? '' ) ),
				'bus_name'            => sanitize_text_field( wp_unslash( $_POST['bus_name'] ?? '' ) ),
				'round_name'          => sanitize_text_field( wp_unslash( $_POST['round_name'] ?? '' ) ),
			),
		);

		try {
			if ( $campaign_id > 0 ) {
				$ok = $campaign_svc->update_campaign( $campaign_id, $data );
				if ( $ok ) {
					$this->set_flash( __( 'Campaign draft updated successfully.', 'olama-messages' ), 'success' );
				} else {
					$this->set_flash( __( 'Failed to update campaign draft.', 'olama-messages' ), 'error' );
				}
			} else {
				$new_id = $campaign_svc->create_campaign( $data );
				if ( $new_id ) {
					$this->set_flash( __( 'Campaign draft created successfully.', 'olama-messages' ), 'success' );
				} else {
					$this->set_flash( __( 'Failed to create campaign draft.', 'olama-messages' ), 'error' );
				}
			}
		} catch ( Exception $e ) {
			$this->set_flash( $e->getMessage(), 'error' );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=olama-messages-campaigns' ) );
		exit;
	}

	/** Handle campaign deletion. */
	public function handle_delete_campaign() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'olama-messages' ) );
		}

		$campaign_id = isset( $_GET['campaign_id'] ) ? absint( $_GET['campaign_id'] ) : 0;
		if ( ! $campaign_id ) {
			wp_die( esc_html__( 'Missing campaign ID.', 'olama-messages' ) );
		}

		check_admin_referer( 'olama_msg_delete_' . $campaign_id );

		try {
			$ok = $this->plugin->campaigns()->delete_campaign( $campaign_id );
			if ( $ok ) {
				$this->set_flash( __( 'Campaign deleted successfully.', 'olama-messages' ), 'success' );
			} else {
				$this->set_flash( __( 'Failed to delete campaign.', 'olama-messages' ), 'error' );
			}
		} catch ( Exception $e ) {
			$this->set_flash( $e->getMessage(), 'error' );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=olama-messages-campaigns' ) );
		exit;
	}

	/** Handle campaign preparation. */
	public function handle_prepare_campaign() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'olama-messages' ) );
		}

		$campaign_id = isset( $_GET['campaign_id'] ) ? absint( $_GET['campaign_id'] ) : 0;
		if ( ! $campaign_id ) {
			wp_die( esc_html__( 'Missing campaign ID.', 'olama-messages' ) );
		}

		check_admin_referer( 'olama_msg_prepare_' . $campaign_id );

		try {
			$res = $this->plugin->campaigns()->prepare_campaign( $campaign_id );
			if ( $res['success'] ) {
				$this->set_flash( sprintf(
					/* translators: 1: total candidates, 2: total prepared in queue */
					__( 'Campaign prepared successfully! Evaluated %1$d families; generated %2$d messages in prepared queue.', 'olama-messages' ),
					$res['total_candidates'],
					$res['total_prepared']
				), 'success' );
			} else {
				$this->set_flash( __( 'Failed to prepare campaign.', 'olama-messages' ), 'error' );
			}
		} catch ( Exception $e ) {
			$this->set_flash( $e->getMessage(), 'error' );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=olama-messages-campaigns' ) );
		exit;
	}

	/** Handle campaign reset to draft. */
	public function handle_reset_campaign() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'olama-messages' ) );
		}

		$campaign_id = isset( $_GET['campaign_id'] ) ? absint( $_GET['campaign_id'] ) : 0;
		if ( ! $campaign_id ) {
			wp_die( esc_html__( 'Missing campaign ID.', 'olama-messages' ) );
		}

		check_admin_referer( 'olama_msg_reset_' . $campaign_id );

		try {
			$ok = $this->plugin->campaigns()->reset_campaign_to_draft( $campaign_id );
			if ( $ok ) {
				$this->set_flash( __( 'Campaign reset to draft. Prepared snapshots and queue records have been deleted.', 'olama-messages' ), 'success' );
			} else {
				$this->set_flash( __( 'Failed to reset campaign.', 'olama-messages' ), 'error' );
			}
		} catch ( Exception $e ) {
			$this->set_flash( $e->getMessage(), 'error' );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=olama-messages-campaigns' ) );
		exit;
	}

	/** Handle campaign cancellation. */
	public function handle_cancel_campaign() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'olama-messages' ) );
		}

		$campaign_id = isset( $_GET['campaign_id'] ) ? absint( $_GET['campaign_id'] ) : 0;
		if ( ! $campaign_id ) {
			wp_die( esc_html__( 'Missing campaign ID.', 'olama-messages' ) );
		}

		check_admin_referer( 'olama_msg_cancel_' . $campaign_id );

		try {
			$ok = $this->plugin->campaigns()->cancel_campaign( $campaign_id );
			if ( $ok ) {
				$this->set_flash( __( 'Campaign and queue records marked as cancelled. Snapshots preserved for audit.', 'olama-messages' ), 'success' );
			} else {
				$this->set_flash( __( 'Failed to cancel campaign.', 'olama-messages' ), 'error' );
			}
		} catch ( Exception $e ) {
			$this->set_flash( $e->getMessage(), 'error' );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=olama-messages-campaigns' ) );
		exit;
	}

	// ─── Phase 4 Run 4B: Campaign Sending Lifecycle Handlers ────────────────

	/** Handle Start Campaign POST. */
	public function handle_start_campaign() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'olama-messages' ) );
		}

		$campaign_id = isset( $_GET['campaign_id'] ) ? absint( $_GET['campaign_id'] ) : 0;
		if ( ! $campaign_id ) {
			wp_die( esc_html__( 'Missing campaign ID.', 'olama-messages' ) );
		}

		check_admin_referer( 'olama_msg_start_' . $campaign_id );

		try {
			$this->plugin->campaigns()->start_campaign_sending( $campaign_id );
			$this->set_flash( __( 'Campaign started. The sending agent will begin dispatching SMS messages shortly.', 'olama-messages' ), 'success' );
		} catch ( Exception $e ) {
			$this->set_flash( $e->getMessage(), 'error' );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=olama-messages-campaign-progress&campaign_id=' . $campaign_id ) );
		exit;
	}

	/** Handle Pause Campaign POST. */
	public function handle_pause_campaign() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'olama-messages' ) );
		}

		$campaign_id = isset( $_GET['campaign_id'] ) ? absint( $_GET['campaign_id'] ) : 0;
		if ( ! $campaign_id ) {
			wp_die( esc_html__( 'Missing campaign ID.', 'olama-messages' ) );
		}

		check_admin_referer( 'olama_msg_pause_' . $campaign_id );

		try {
			$this->plugin->campaigns()->pause_campaign_sending( $campaign_id );
			$this->set_flash( __( 'Campaign paused. Reserved jobs have been returned to the prepared queue.', 'olama-messages' ), 'success' );
		} catch ( Exception $e ) {
			$this->set_flash( $e->getMessage(), 'error' );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=olama-messages-campaign-progress&campaign_id=' . $campaign_id ) );
		exit;
	}

	/** Handle Resume Campaign POST. */
	public function handle_resume_campaign() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'olama-messages' ) );
		}

		$campaign_id = isset( $_GET['campaign_id'] ) ? absint( $_GET['campaign_id'] ) : 0;
		if ( ! $campaign_id ) {
			wp_die( esc_html__( 'Missing campaign ID.', 'olama-messages' ) );
		}

		check_admin_referer( 'olama_msg_resume_' . $campaign_id );

		try {
			$this->plugin->campaigns()->resume_campaign_sending( $campaign_id );
			$this->set_flash( __( 'Campaign resumed. The sending agent will continue dispatching SMS messages.', 'olama-messages' ), 'success' );
		} catch ( Exception $e ) {
			$this->set_flash( $e->getMessage(), 'error' );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=olama-messages-campaign-progress&campaign_id=' . $campaign_id ) );
		exit;
	}

	/** Handle token generation POST. */
	public function handle_generate_token() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'olama-messages' ) );
		}

		$family_id  = isset( $_GET['family_id'] )  ? sanitize_text_field( wp_unslash( $_GET['family_id'] ) )  : '';
		$study_year = isset( $_GET['study_year'] )  ? sanitize_text_field( wp_unslash( $_GET['study_year'] ) ) : '';
		$campaign_id = isset( $_GET['campaign_id'] ) ? absint( $_GET['campaign_id'] ) : 0;
		$generated_source = ( isset( $_GET['source'] ) && 'manual' === sanitize_text_field( wp_unslash( $_GET['source'] ) ) ) ? 'manual' : 'campaign';

		if ( ! $family_id ) {
			wp_die( esc_html__( 'Missing family_id.', 'olama-messages' ) );
		}

		check_admin_referer( 'olama_msg_gen_' . $family_id );

		try {
			$result = $this->plugin->tokens()->generate_token( $family_id, $study_year, array(
				'campaign_id'      => $campaign_id ?: null,
				'generated_source' => $generated_source,
			) );
			$short_data = $this->plugin->short_links()->get_or_create_for_family_token(
				$result['token_id'],
				absint( $family_id ),
				$study_year
			);

			// Store the compact first-party URL in a short-lived transient (10 min).
			$token_id      = $result['token_id'];
			$transient_key = 'olama_msg_new_token_' . get_current_user_id() . '_' . $token_id;
			set_transient( $transient_key, $short_data['short_url'], 10 * MINUTE_IN_SECONDS );

			$redirect = admin_url(
				'admin.php?page=olama-messages-tokens&new_token_id=' . $token_id
			);
			$this->set_flash( sprintf(
				/* translators: %s: family ID */
				__( 'Token generated for family %s. Copy the short link now — it is shown once only. If lost, you must regenerate a new one.', 'olama-messages' ),
				esc_html( $family_id )
			), 'success' );
		} catch ( Exception $e ) {
			$this->set_flash( $e->getMessage(), 'error' );
			$redirect = admin_url( 'admin.php?page=olama-messages-recipients' );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/** Handle manual token generation POST. */
	public function handle_generate_manual_token() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'olama-messages' ) );
		}

		check_admin_referer( 'olama_msg_generate_manual_token', 'olama_msg_generate_manual_token_nonce' );

		$family_id  = isset( $_POST['family_id'] ) ? absint( wp_unslash( $_POST['family_id'] ) ) : 0;
		$study_year = isset( $_POST['study_year'] ) ? sanitize_text_field( wp_unslash( $_POST['study_year'] ) ) : '';
		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( wp_unslash( $_POST['campaign_id'] ) ) : 0;

		if ( ! $family_id ) {
			$this->set_flash( __( 'Missing family_id.', 'olama-messages' ), 'error' );
			wp_safe_redirect( admin_url( 'admin.php?page=olama-messages-tokens' ) );
			exit;
		}

		try {
			$result = $this->plugin->tokens()->generate_token( $family_id, $study_year, array(
				'campaign_id'      => $campaign_id ?: null,
				'generated_source' => 'manual',
			) );
			$short_data = $this->plugin->short_links()->get_or_create_for_family_token(
				$result['token_id'],
				$family_id,
				$study_year
			);
			$transient_key = 'olama_msg_new_token_' . get_current_user_id() . '_' . $result['token_id'];
			set_transient( $transient_key, $short_data['short_url'], 10 * MINUTE_IN_SECONDS );
			$this->set_flash( sprintf( __( 'Manual link generated for family %s.', 'olama-messages' ), esc_html( $family_id ) ), 'success' );
			$redirect = admin_url( 'admin.php?page=olama-messages-tokens&new_token_id=' . $result['token_id'] );
		} catch ( Exception $e ) {
			$this->set_flash( $e->getMessage(), 'error' );
			$redirect = admin_url( 'admin.php?page=olama-messages-tokens' );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/** Handle token revocation POST. */
	public function handle_revoke_token() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'olama-messages' ) );
		}

		$token_id = isset( $_GET['token_id'] ) ? absint( $_GET['token_id'] ) : 0;
		if ( ! $token_id ) {
			wp_die( esc_html__( 'Missing token_id.', 'olama-messages' ) );
		}

		check_admin_referer( 'olama_msg_revoke_' . $token_id );

		$ok = $this->plugin->tokens()->revoke_token( $token_id );
		if ( $ok ) {
			$this->set_flash( __( 'Token revoked successfully.', 'olama-messages' ), 'success' );
		} else {
			$this->set_flash( __( 'Failed to revoke token.', 'olama-messages' ), 'error' );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=olama-messages-tokens' ) );
		exit;
	}

	/** Handle token delete POST. */
	public function handle_delete_token() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'olama-messages' ) );
		}

		$token_id = isset( $_GET['token_id'] ) ? absint( $_GET['token_id'] ) : 0;
		if ( ! $token_id ) {
			wp_die( esc_html__( 'Missing token_id.', 'olama-messages' ) );
		}

		check_admin_referer( 'olama_msg_delete_token_' . $token_id );

		$ok = $this->plugin->tokens()->delete_token( $token_id );
		if ( $ok ) {
			$this->set_flash( __( 'Token deleted successfully.', 'olama-messages' ), 'success' );
		} else {
			$this->set_flash( __( 'Failed to delete token.', 'olama-messages' ), 'error' );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=olama-messages-tokens' ) );
		exit;
	}

	/** Clear all payment links by revoking active tokens and short links. */
	public function handle_clear_all_tokens() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'olama-messages' ) );
		}

		check_admin_referer( 'olama_msg_clear_all_tokens', 'olama_msg_clear_all_tokens_nonce' );

		$tokens_deleted = $this->plugin->tokens()->delete_all_tokens();
		$links_deleted  = $this->plugin->short_links()->delete_all_short_links();
		$this->set_flash( sprintf( __( 'Deleted all payment links. Tokens deleted: %d, short links deleted: %d.', 'olama-messages' ), $tokens_deleted, $links_deleted ), 'success' );
		wp_safe_redirect( admin_url( 'admin.php?page=olama-messages-tokens' ) );
		exit;
	}

	public function handle_delete_queue_item() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'olama-messages' ) );
		}

		$queue_id = isset( $_GET['queue_id'] ) ? absint( $_GET['queue_id'] ) : 0;
		if ( ! $queue_id ) {
			wp_die( esc_html__( 'Missing queue_id.', 'olama-messages' ) );
		}

		check_admin_referer( 'olama_msg_delete_queue_item_' . $queue_id );

		$ok = $this->plugin->campaigns()->delete_queue_item( $queue_id );
		if ( $ok ) {
			$this->set_flash( __( 'Queue item deleted successfully.', 'olama-messages' ), 'success' );
		} else {
			$this->set_flash( __( 'Failed to delete queue item.', 'olama-messages' ), 'error' );
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=olama-messages-queue' ) );
		exit;
	}

	/** Handle settings save. */
	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'olama-messages' ) );
		}

		check_admin_referer( 'olama_msg_save_settings', 'olama_msg_settings_nonce' );

		$fields = array(
			'olama_msg_school_name'          => 'sanitize_text_field',
			'olama_msg_contact_phone'        => 'sanitize_text_field',
			'olama_msg_payment_instructions' => 'sanitize_textarea_field',
			'olama_msg_token_expiry_days'    => 'absint',
			'olama_msg_default_study_year'   => 'sanitize_text_field',
		);

		foreach ( $fields as $key => $sanitizer ) {
			if ( isset( $_POST[ $key ] ) ) {
				$value = call_user_func( $sanitizer, wp_unslash( $_POST[ $key ] ) );
				update_option( $key, $value );
			}
		}

		// Phase 1.5 financial provider settings
		update_option( 'olama_msg_financial_enabled', isset( $_POST['olama_msg_financial_enabled'] ) ? 'yes' : 'no' );
		if ( isset( $_POST['olama_msg_oracle_base_url'] ) ) {
			update_option( 'olama_msg_oracle_base_url', esc_url_raw( trim( wp_unslash( $_POST['olama_msg_oracle_base_url'] ) ) ) );
		}
		if ( isset( $_POST['olama_msg_oracle_api_key'] ) ) {
			$input_key = trim( wp_unslash( $_POST['olama_msg_oracle_api_key'] ) );
			if ( $input_key !== '' ) {
				update_option( 'olama_msg_oracle_api_key', sanitize_text_field( $input_key ) );
			}
		}
		if ( isset( $_POST['olama_msg_api_timeout'] ) ) {
			update_option( 'olama_msg_api_timeout', max( 5, min( 60, absint( $_POST['olama_msg_api_timeout'] ) ) ) );
		}
		delete_transient( Olama_Messages_Financial_Api_Provider::HEALTH_TRANSIENT );

		$this->set_flash( __( 'Settings saved.', 'olama-messages' ), 'success' );
		wp_safe_redirect( admin_url( 'admin.php?page=olama-messages-settings' ) );
		exit;
	}

	// ─── AJAX: SMS Preview ────────────────────────────────────────────────────

	public function ajax_preview_sms() {
		check_ajax_referer( 'olama_msg_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$renderer = $this->plugin->renderer();
		$school   = get_option( 'olama_msg_school_name', 'أكاديمية علماء المستقبل' );

		$financial_available = '1' === sanitize_text_field( wp_unslash( $_POST['financial_available'] ?? '0' ) );
		$balance_input       = isset( $_POST['balance'] ) ? sanitize_text_field( wp_unslash( $_POST['balance'] ) ) : '';
		$monthly_due_input   = isset( $_POST['monthly_due'] ) ? sanitize_text_field( wp_unslash( $_POST['monthly_due'] ) ) : '';

		// Guardrail: if financial is available but balance is not numeric, treat as unavailable for preview
		if ( $financial_available && ( $balance_input === '' || ! is_numeric( $balance_input ) ) ) {
			$financial_available = false;
		}

		$template = $renderer->get_active_template( $financial_available );

		$financial_template_warning = '';
		if ( ! $financial_available && $renderer->template_has_financial_vars( $template ) ) {
			$financial_template_warning = __(
				'Warning: This template contains {balance} or {monthly_due}, but the financial provider is not available yet. These will show as "غير متوفر" (unavailable).',
				'olama-messages'
			);
		}

		$payment_link    = esc_url_raw( wp_unslash( $_POST['payment_link'] ?? '' ) );
		$no_token_notice = false;

		if ( empty( $payment_link ) ) {
			$no_token_notice = true;
			$payment_link    = '[Short payment link generated at send time]';
		}

		$vars = array(
			'sponsor_name' => sanitize_text_field( wp_unslash( $_POST['sponsor_name'] ?? '' ) ),
			'family_id'    => sanitize_text_field( wp_unslash( $_POST['family_id']    ?? '' ) ),
			'students'     => sanitize_text_field( wp_unslash( $_POST['students']     ?? '' ) ),
			'payment_link' => $payment_link,
			'study_year'   => sanitize_text_field( wp_unslash( $_POST['study_year']   ?? '' ) ),
			'school_name'  => $school,
		);

		if ( $financial_available ) {
			$vars['balance']     = $balance_input;
			$vars['monthly_due'] = ( $monthly_due_input !== '' && is_numeric( $monthly_due_input ) ) ? $monthly_due_input : '';
		}

		$text = $renderer->render_sms( $template, $vars );
		$info = $renderer->sms_info( $text );

		wp_send_json_success( array(
			'text'                       => $text,
			'info'                       => $info,
			'financial_available'        => $financial_available,
			'no_token_notice'            => $no_token_notice,
			'no_token_notice_text'       => __( 'The secure short payment link is generated only when the sending agent reserves this SMS.', 'olama-messages' ),
			'financial_template_warning' => $financial_template_warning,
		) );
	}

	/** AJAX: preview report (placeholder). */
	public function ajax_preview_report() {
		check_ajax_referer( 'olama_msg_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}
		$family_id = sanitize_text_field( wp_unslash( $_POST['family_id'] ?? '' ) );
		wp_send_json_success( array(
			'redirect' => admin_url( 'admin.php?page=olama-messages-recipients&family_id=' . rawurlencode( $family_id ) ),
		) );
	}

	/** AJAX: live preview campaign candidates. */
	public function ajax_preview_campaign() {
		check_ajax_referer( 'olama_msg_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$data = array(
			'study_year'              => sanitize_text_field( wp_unslash( $_POST['study_year'] ?? '' ) ),
			'template_id'             => isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : null,
			'target_type'             => sanitize_text_field( wp_unslash( $_POST['target_type'] ?? 'collection' ) ),
			'recipient_policy'        => sanitize_text_field( wp_unslash( $_POST['recipient_policy'] ?? 'father_first' ) ),
			'min_balance'             => ( 'collection' === sanitize_text_field( wp_unslash( $_POST['target_type'] ?? 'collection' ) ) && isset( $_POST['min_balance'] ) && $_POST['min_balance'] !== '' ) ? floatval( $_POST['min_balance'] ) : null,
			'exclude_credit_balances' => ( 'collection' === sanitize_text_field( wp_unslash( $_POST['target_type'] ?? 'collection' ) ) && isset( $_POST['exclude_credit_balances'] ) ) ? 1 : 0,
			'exclude_zero_balances'   => ( 'collection' === sanitize_text_field( wp_unslash( $_POST['target_type'] ?? 'collection' ) ) && isset( $_POST['exclude_zero_balances'] ) ) ? 1 : 0,
			'filters'                 => array(
				'recipient_overrides' => $this->sanitize_recipient_overrides( wp_unslash( $_POST['recipient_overrides'] ?? '{}' ) ),
				'class_name'          => sanitize_text_field( wp_unslash( $_POST['class_name'] ?? '' ) ),
				'section_name'        => sanitize_text_field( wp_unslash( $_POST['section_name'] ?? '' ) ),
				'class_id'            => absint( $_POST['class_id'] ?? 0 ),
				'section_id'          => absint( $_POST['section_id'] ?? 0 ),
				'departure_bus'       => sanitize_text_field( wp_unslash( $_POST['departure_bus'] ?? '' ) ),
				'arrival_bus'         => sanitize_text_field( wp_unslash( $_POST['arrival_bus'] ?? '' ) ),
				'bus_name'            => sanitize_text_field( wp_unslash( $_POST['bus_name'] ?? '' ) ),
				'round_name'          => sanitize_text_field( wp_unslash( $_POST['round_name'] ?? '' ) ),
			),
		);

		try {
			$page     = max( 1, absint( $_POST['preview_page'] ?? 1 ) );
			$requested_per_page = absint( $_POST['preview_per_page'] ?? 25 );
			$per_page = in_array( $requested_per_page, array( 10, 25, 50, 100 ), true ) ? $requested_per_page : 25;
			$preview = $this->plugin->campaigns()->preview_candidates( $data, array(
				'limit'          => $per_page,
				'offset'         => ( $page - 1 ) * $per_page,
				'show_excluded'  => isset( $_POST['show_excluded'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['show_excluded'] ) ),
				'sort_field'     => in_array( $_POST['sort_field'] ?? '', array( 'family_id', 'recipient', 'balance' ), true ) ? $_POST['sort_field'] : 'family_id',
				'sort_order'     => in_array( $_POST['sort_order'] ?? '', array( 'asc', 'desc' ), true ) ? $_POST['sort_order'] : 'asc',
			) );
			$preview['page']        = $page;
			$preview['per_page']    = $per_page;
			$preview['total_pages'] = max( 1, (int) ceil( $preview['total_displayed'] / $per_page ) );

			wp_send_json_success( $preview );
		} catch ( Exception $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	public function ajax_transport_route_options() {
		check_ajax_referer( 'olama_msg_ajax', 'nonce' );

		$study_year = sanitize_text_field( wp_unslash( $_POST['study_year'] ?? '' ) );
		if ( '' === $study_year ) {
			wp_send_json_success(
				array(
					'departure' => array(),
					'arrival'   => array(),
					'rounds'    => array(),
				)
			);
		}

		$options = $this->plugin->transportation()->get_route_options( $study_year );
		wp_send_json_success( $options );
	}

	// ─── Page: Sending Agents (Phase 3) ──────────────────────────────────────

	public function page_agents() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'olama-messages' ) );
		}

		$agent_svc = $this->plugin->agents();
		$action    = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';

		$this->print_flash();

		if ( $action === 'view' ) {
			$agent_id = isset( $_GET['agent_id'] ) ? absint( $_GET['agent_id'] ) : 0;
			$agent    = $agent_svc->get_agent( $agent_id );
			if ( ! $agent ) {
				$this->notice( __( 'Agent not found.', 'olama-messages' ), 'error' );
				return;
			}

			$events = $agent_svc->get_events( $agent['agent_uuid'], 50 );
			$this->render_agent_detail( $agent, $events );
			return;
		}

		// List view: check if a new agent was just created to display credentials
		$user_id = get_current_user_id();
		$new_agent = get_transient( 'olama_msg_new_agent_' . $user_id );
		if ( $new_agent ) {
			delete_transient( 'olama_msg_new_agent_' . $user_id );
			?>
			<div class="notice notice-warning olama-msg-agent-credentials-card" style="border-right-color: #ffb900; padding: 15px; margin: 20px 0;">
				<h2 style="margin-top: 0; color: #d54e21;">⚠️ <?php esc_html_e( 'API Credentials Created — COPY NOW', 'olama-messages' ); ?></h2>
				<p><?php printf( __( 'Credentials for agent <strong>%s</strong> are shown below. Copy them now; the API secret key is hashed and cannot be recovered later.', 'olama-messages' ), esc_html( $new_agent['name'] ) ); ?></p>
				<table class="form-table" style="margin-top: 10px;">
					<tr>
						<th scope="row" style="width: 150px; font-weight: bold; padding: 5px 0;"><?php esc_html_e( 'Agent UUID:', 'olama-messages' ); ?></th>
						<td style="padding: 5px 0;"><code><?php echo esc_html( $new_agent['uuid'] ); ?></code></td>
					</tr>
					<tr>
						<th scope="row" style="width: 150px; font-weight: bold; padding: 5px 0;"><?php esc_html_e( 'API Secret Key:', 'olama-messages' ); ?></th>
						<td style="padding: 5px 0;"><code style="background: #fff; padding: 4px 8px; border: 1px solid #ccc; font-weight: bold; color: #111; font-size: 1.1em; display: inline-block; word-break: break-all;"><?php echo esc_html( $new_agent['raw_key'] ); ?></code></td>
					</tr>
				</table>
				<p style="margin-bottom: 0; font-style: italic; color: #666; margin-top: 10px;">
					<?php esc_html_e( 'Instructions: Paste these values into the Windows Agent local settings screen to authenticate.', 'olama-messages' ); ?>
				</p>
			</div>
			<?php
		}

		$agents = $agent_svc->list_agents();
		$this->render_agents_list( $agents );
	}

	private function render_agents_list( array $agents ) {
		?>
		<div class="wrap olama-msg-wrap">
			<h1 class="olama-msg-page-title">
				<span class="dashicons dashicons-networking"></span>
				<?php esc_html_e( 'Sending Agents', 'olama-messages' ); ?>
			</h1>

			<div class="olama-msg-agents-stack">
				<!-- Add Agent Form (Left Column) -->
				<div class="olama-msg-agents-register">
					<div class="olama-msg-card">
						<div class="olama-msg-card__header"><?php esc_html_e( 'Register New Agent', 'olama-messages' ); ?></div>
						<div class="olama-msg-card__body">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( 'olama_msg_save_agent' ); ?>
								<input type="hidden" name="action" value="olama_msg_save_agent">
								
								<div class="olama-msg-form-group" style="margin-bottom: 15px;">
									<label style="display: block; font-weight: bold; margin-bottom: 5px;" for="agent_name"><?php esc_html_e( 'Agent Name', 'olama-messages' ); ?></label>
									<input type="text" id="agent_name" name="agent_name" class="regular-text" style="width: 100%;" placeholder="<?php esc_attr_e( 'e.g. Office Front Desk PC', 'olama-messages' ); ?>" required>
									<p class="description"><?php esc_html_e( 'A user-friendly label to identify this sending device.', 'olama-messages' ); ?></p>
								</div>
								
								<p class="submit" style="margin: 0; padding: 0;">
									<?php submit_button( __( 'Register Agent', 'olama-messages' ), 'primary', 'submit', false ); ?>
								</p>
							</form>
						</div>
					</div>
				</div>

				<!-- Registered Agents List (Right Column) -->
				<div class="olama-msg-agents-list" style="margin-top:20px;">
					<div class="olama-msg-card">
						<div class="olama-msg-card__header"><?php esc_html_e( 'Registered Desktop Agents', 'olama-messages' ); ?></div>
						<div class="olama-msg-card__body" style="padding: 0;">
							<table class="wp-list-table widefat fixed striped posts">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Agent Name', 'olama-messages' ); ?></th>
										<th><?php esc_html_e( 'UUID Prefix', 'olama-messages' ); ?></th>
										<th><?php esc_html_e( 'Status', 'olama-messages' ); ?></th>
										<th><?php esc_html_e( 'Machine / User', 'olama-messages' ); ?></th>
										<th><?php esc_html_e( 'KDE CLI', 'olama-messages' ); ?></th>
										<th><?php esc_html_e( 'KDE Device', 'olama-messages' ); ?></th>
										<th><?php esc_html_e( 'Dispatcher', 'olama-messages' ); ?></th>
										<th><?php esc_html_e( 'Last Seen', 'olama-messages' ); ?></th>
										<th style="width: 150px; text-align: center;"><?php esc_html_e( 'Actions', 'olama-messages' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php if ( empty( $agents ) ) : ?>
										<tr>
											<td colspan="9" class="olama-msg-muted" style="text-align: center; padding: 15px;">
												<?php esc_html_e( 'No sending agents registered yet.', 'olama-messages' ); ?>
											</td>
										</tr>
									<?php else : ?>
										<?php foreach ( $agents as $a ) :
											$is_online = false;
											if ( $a['status'] === 'online' && ! empty( $a['kde_cli_found'] ) && ! empty( $a['kde_device_reachable'] ) ) {
												$active_threshold = time() - 300; // 5 minutes
												$is_online = ( ! empty( $a['last_seen_at'] ) && strtotime( $a['last_seen_at'] ) >= $active_threshold );
											}
											$computed_status = $is_online ? 'online' : ( $a['status'] === 'online' ? 'offline' : $a['status'] );
											
											$status_label = $computed_status;
											$status_class = '';
											if ( $computed_status === 'online' ) {
												$status_label = __( 'Online', 'olama-messages' );
												$status_class = 'olama-msg-pill--ok';
											} elseif ( $computed_status === 'offline' ) {
												$status_label = __( 'Offline', 'olama-messages' );
												$status_class = 'olama-msg-pill--warn';
											} elseif ( $computed_status === 'inactive' ) {
												$status_label = __( 'Inactive', 'olama-messages' );
												$status_class = 'olama-msg-muted';
											} elseif ( $computed_status === 'revoked' ) {
												$status_label = __( 'Revoked', 'olama-messages' );
												$status_class = 'olama-msg-pill--err';
											}
											
											$view_url = admin_url( 'admin.php?page=olama-messages-agents&action=view&agent_id=' . $a['id'] );
											$revoke_url = wp_nonce_url(
												admin_url( 'admin-post.php?action=olama_msg_revoke_agent&agent_id=' . $a['id'] ),
												'olama_msg_revoke_agent_' . $a['id']
											);
											$delete_url = wp_nonce_url(
												admin_url( 'admin-post.php?action=olama_msg_delete_agent&agent_id=' . $a['id'] ),
												'olama_msg_delete_agent_' . $a['id']
											);
										?>
										<tr>
											<td>
												<strong><a href="<?php echo esc_url( $view_url ); ?>"><?php echo esc_html( $a['agent_name'] ); ?></a></strong>
											</td>
											<td><code><?php echo esc_html( substr( $a['agent_uuid'], 0, 8 ) ); ?>...</code></td>
											<td>
												<span class="olama-msg-pill <?php echo esc_attr( $status_class ); ?>">
													<?php echo esc_html( $status_label ); ?>
												</span>
											</td>
											<td>
												<?php if ( $a['machine_name'] ) : ?>
													<?php echo esc_html( $a['machine_name'] ); ?> / <code><?php echo esc_html( $a['windows_user'] ); ?></code>
												<?php else : ?>
													<span class="olama-msg-muted">—</span>
												<?php endif; ?>
											</td>
											<td>
												<?php if ( $a['status'] === 'inactive' ) : ?>
													<span class="olama-msg-muted">—</span>
												<?php elseif ( $a['kde_cli_found'] ) : ?>
													<span class="olama-msg-pill olama-msg-pill--ok"><?php esc_html_e( 'Found', 'olama-messages' ); ?></span>
												<?php else : ?>
													<span class="olama-msg-pill olama-msg-pill--err"><?php esc_html_e( 'Missing', 'olama-messages' ); ?></span>
												<?php endif; ?>
											</td>
											<td>
												<?php if ( $a['kde_device_name'] ) : ?>
													<?php echo esc_html( $a['kde_device_name'] ); ?>
													<?php echo $a['kde_device_reachable']
														? '<span class="olama-msg-pill olama-msg-pill--ok" style="font-size: 0.8em; margin-right: 4px;">' . esc_html__( 'Connected', 'olama-messages' ) . '</span>'
														: '<span class="olama-msg-pill olama-msg-pill--err" style="font-size: 0.8em; margin-right: 4px;">' . esc_html__( 'Offline', 'olama-messages' ) . '</span>'; ?>
												<?php else : ?>
													<span class="olama-msg-muted">—</span>
												<?php endif; ?>
											</td>
											<td>
												<?php if ( isset( $a['heartbeat']['dispatcher_enabled'] ) ) : ?>
													<?php if ( $a['heartbeat']['dispatcher_enabled'] ) : ?>
														<span class="olama-msg-pill olama-msg-pill--ok" style="font-size: 0.85em;"><?php esc_html_e( 'Enabled', 'olama-messages' ); ?></span>
														<br><code style="font-size: 0.8em; margin-top: 4px; display: inline-block;"><?php echo esc_html( $a['heartbeat']['dispatcher_state'] ?? '' ); ?></code>
													<?php else : ?>
														<span class="olama-msg-pill olama-msg-muted" style="font-size: 0.85em;"><?php esc_html_e( 'Disabled', 'olama-messages' ); ?></span>
													<?php endif; ?>
												<?php else : ?>
													<span class="olama-msg-muted">—</span>
												<?php endif; ?>
											</td>
											<td><?php echo esc_html( $a['last_seen_at'] ? date( 'Y-m-d H:i:s', strtotime( $a['last_seen_at'] ) ) : '—' ); ?></td>
											<td class="olama-msg-actions" style="text-align: center;">
												<a href="<?php echo esc_url( $view_url ); ?>" class="button button-small">
													<?php esc_html_e( 'View', 'olama-messages' ); ?>
												</a>
												<?php if ( $a['status'] !== 'revoked' ) : ?>
													<a href="<?php echo esc_url( $revoke_url ); ?>" class="button button-small button-link-delete" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to revoke this agent? It will immediately stop authenticating.', 'olama-messages' ); ?>');">
														<?php esc_html_e( 'Revoke', 'olama-messages' ); ?>
													</a>
												<?php endif; ?>
												<a href="<?php echo esc_url( $delete_url ); ?>" class="button button-small button-link-delete" onclick="return confirm('<?php esc_attr_e( 'Permanently delete this sending agent? Its credentials will stop working immediately. Delivery and audit history will be preserved. This cannot be undone.', 'olama-messages' ); ?>');">
													<?php esc_html_e( 'Delete', 'olama-messages' ); ?>
												</a>
											</td>
										</tr>
										<?php endforeach; ?>
									<?php endif; ?>
								</tbody>
							</table>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	private function render_agent_detail( array $agent, array $events ) {
		$is_online = false;
		if ( $agent['status'] === 'online' && ! empty( $agent['kde_cli_found'] ) && ! empty( $agent['kde_device_reachable'] ) ) {
			$active_threshold = time() - 300;
			$is_online = ( ! empty( $agent['last_seen_at'] ) && strtotime( $agent['last_seen_at'] ) >= $active_threshold );
		}
		$computed_status = $is_online ? 'online' : ( $agent['status'] === 'online' ? 'offline' : $agent['status'] );
		
		$status_label = $computed_status;
		$status_class = '';
		if ( $computed_status === 'online' ) {
			$status_label = __( 'Online', 'olama-messages' );
			$status_class = 'olama-msg-pill--ok';
		} elseif ( $computed_status === 'offline' ) {
			$status_label = __( 'Offline', 'olama-messages' );
			$status_class = 'olama-msg-pill--warn';
		} elseif ( $computed_status === 'inactive' ) {
			$status_label = __( 'Inactive', 'olama-messages' );
			$status_class = 'olama-msg-muted';
		} elseif ( $computed_status === 'revoked' ) {
			$status_label = __( 'Revoked', 'olama-messages' );
			$status_class = 'olama-msg-pill--err';
		}

		$revoke_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=olama_msg_revoke_agent&agent_id=' . $agent['id'] ),
			'olama_msg_revoke_agent_' . $agent['id']
		);
		$delete_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=olama_msg_delete_agent&agent_id=' . $agent['id'] ),
			'olama_msg_delete_agent_' . $agent['id']
		);
		?>
		<div class="wrap olama-msg-wrap">
			<h1 class="olama-msg-page-title">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=olama-messages-agents' ) ); ?>" class="button button-secondary" style="margin-left: 10px; font-weight: normal; vertical-align: middle;">
					← <?php esc_html_e( 'Back to Agents', 'olama-messages' ); ?>
				</a>
				<?php esc_html_e( 'Agent Details: ', 'olama-messages' ); ?> <?php echo esc_html( $agent['agent_name'] ); ?>
			</h1>

			<div class="olama-msg-split-layout" style="display: flex; gap: 20px; align-items: stretch; flex-wrap: wrap; margin-top: 20px;">
				<!-- Agent Card (Left) -->
				<div class="olama-msg-agent-details-left" style="flex: 1; min-width: 320px; max-width: 450px;">
					<div class="olama-msg-card" style="height: 100%;">
						<div class="olama-msg-card__header"><?php esc_html_e( 'Configuration & Health', 'olama-messages' ); ?></div>
						<div class="olama-msg-card__body">
							<table class="form-table" style="margin: 0;">
								<tr>
									<th scope="row" style="width: 140px; font-weight: bold; padding: 8px 0;"><?php esc_html_e( 'Status', 'olama-messages' ); ?></th>
									<td style="padding: 8px 0;">
										<span class="olama-msg-pill <?php echo esc_attr( $status_class ); ?>">
											<?php echo esc_html( $status_label ); ?>
										</span>
									</td>
								</tr>
								<tr>
									<th scope="row" style="font-weight: bold; padding: 8px 0;"><?php esc_html_e( 'Agent UUID', 'olama-messages' ); ?></th>
									<td style="padding: 8px 0;"><code><?php echo esc_html( $agent['agent_uuid'] ); ?></code></td>
								</tr>
								<tr>
									<th scope="row" style="font-weight: bold; padding: 8px 0;"><?php esc_html_e( 'Platform / Version', 'olama-messages' ); ?></th>
									<td style="padding: 8px 0;">
										<?php if ( $agent['platform'] ) : ?>
											<?php echo esc_html( $agent['platform'] ); ?> / v<?php echo esc_html( $agent['app_version'] ); ?>
										<?php else : ?>
											<span class="olama-msg-muted">—</span>
										<?php endif; ?>
									</td>
								</tr>
								<tr>
									<th scope="row" style="font-weight: bold; padding: 8px 0;"><?php esc_html_e( 'Machine / OS User', 'olama-messages' ); ?></th>
									<td style="padding: 8px 0;">
										<?php if ( $agent['machine_name'] ) : ?>
											<code><?php echo esc_html( $agent['machine_name'] ); ?></code> / <code><?php echo esc_html( $agent['windows_user'] ); ?></code>
										<?php else : ?>
											<span class="olama-msg-muted">—</span>
										<?php endif; ?>
									</td>
								</tr>
								<tr>
									<th scope="row" style="font-weight: bold; padding: 8px 0;"><?php esc_html_e( 'KDE CLI Path', 'olama-messages' ); ?></th>
									<td style="padding: 8px 0;">
										<?php if ( $agent['kde_cli_path'] ) : ?>
											<code style="word-break: break-all; font-size: 0.9em;"><?php echo esc_html( $agent['kde_cli_path'] ); ?></code>
											<?php echo $agent['kde_cli_found']
												? '<br><span class="olama-msg-pill olama-msg-pill--ok" style="font-size: 0.8em; margin-top: 4px; display: inline-block;">' . esc_html__( 'Found', 'olama-messages' ) . '</span>'
												: '<br><span class="olama-msg-pill olama-msg-pill--err" style="font-size: 0.8em; margin-top: 4px; display: inline-block;">' . esc_html__( 'Not Found', 'olama-messages' ) . '</span>'; ?>
										<?php else : ?>
											<span class="olama-msg-muted">—</span>
										<?php endif; ?>
									</td>
								</tr>
								<tr>
									<th scope="row" style="font-weight: bold; padding: 8px 0;"><?php esc_html_e( 'Target Device', 'olama-messages' ); ?></th>
									<td style="padding: 8px 0;">
										<?php if ( $agent['kde_device_name'] ) : ?>
											<strong><?php echo esc_html( $agent['kde_device_name'] ); ?></strong><br>
											<code style="font-size: 0.85em; color: #666;"><?php echo esc_html( $agent['kde_device_id'] ); ?></code><br>
											<?php echo $agent['kde_device_reachable']
												? '<span class="olama-msg-pill olama-msg-pill--ok" style="font-size: 0.8em; margin-top: 4px; display: inline-block;">' . esc_html__( 'Reachable', 'olama-messages' ) . '</span>'
												: '<span class="olama-msg-pill olama-msg-pill--err" style="font-size: 0.8em; margin-top: 4px; display: inline-block;">' . esc_html__( 'Unreachable', 'olama-messages' ) . '</span>'; ?>
										<?php else : ?>
											<span class="olama-msg-muted">—</span>
										<?php endif; ?>
									</td>
								</tr>
								<tr>
									<th scope="row" style="font-weight: bold; padding: 8px 0;"><?php esc_html_e( 'Last Seen', 'olama-messages' ); ?></th>
									<td style="padding: 8px 0;"><?php echo esc_html( $agent['last_seen_at'] ? date( 'Y-m-d H:i:s', strtotime( $agent['last_seen_at'] ) ) : '—' ); ?></td>
								</tr>
								<tr>
									<th scope="row" style="font-weight: bold; padding: 8px 0;"><?php esc_html_e( 'Registered On', 'olama-messages' ); ?></th>
									<td style="padding: 8px 0;"><?php echo esc_html( date( 'Y-m-d H:i:s', strtotime( $agent['created_at'] ) ) ); ?></td>
								</tr>
								<tr style="border-top: 1px solid #eee;">
									<th scope="row" style="font-weight: bold; padding: 8px 0; color: #23282d;"><?php esc_html_e( 'Dispatcher Enabled', 'olama-messages' ); ?></th>
									<td style="padding: 8px 0;">
										<?php if ( isset( $agent['heartbeat']['dispatcher_enabled'] ) ) : ?>
											<?php if ( $agent['heartbeat']['dispatcher_enabled'] ) : ?>
												<span class="olama-msg-pill olama-msg-pill--ok"><?php esc_html_e( 'Yes', 'olama-messages' ); ?></span>
											<?php else : ?>
												<span class="olama-msg-pill olama-msg-muted"><?php esc_html_e( 'No', 'olama-messages' ); ?></span>
											<?php endif; ?>
										<?php else : ?>
											<span class="olama-msg-muted">—</span>
										<?php endif; ?>
									</td>
								</tr>
								<tr>
									<th scope="row" style="font-weight: bold; padding: 8px 0;"><?php esc_html_e( 'Dispatcher State', 'olama-messages' ); ?></th>
									<td style="padding: 8px 0;">
										<?php if ( ! empty( $agent['heartbeat']['dispatcher_state'] ) ) : ?>
											<code><?php echo esc_html( $agent['heartbeat']['dispatcher_state'] ); ?></code>
										<?php else : ?>
											<span class="olama-msg-muted">—</span>
										<?php endif; ?>
									</td>
								</tr>
								<tr>
									<th scope="row" style="font-weight: bold; padding: 8px 0;"><?php esc_html_e( 'Dispatcher Last Poll', 'olama-messages' ); ?></th>
									<td style="padding: 8px 0;">
										<?php if ( ! empty( $agent['heartbeat']['dispatcher_last_poll_at'] ) ) : ?>
											<?php echo esc_html( $agent['heartbeat']['dispatcher_last_poll_at'] ); ?>
										<?php else : ?>
											<span class="olama-msg-muted">—</span>
										<?php endif; ?>
									</td>
								</tr>
								<tr>
									<th scope="row" style="font-weight: bold; padding: 8px 0;"><?php esc_html_e( 'Dispatcher Last Result', 'olama-messages' ); ?></th>
									<td style="padding: 8px 0;">
										<?php if ( ! empty( $agent['heartbeat']['dispatcher_last_result'] ) ) : ?>
											<?php echo esc_html( $agent['heartbeat']['dispatcher_last_result'] ); ?>
										<?php else : ?>
											<span class="olama-msg-muted">—</span>
										<?php endif; ?>
									</td>
								</tr>
								<tr>
									<th scope="row" style="font-weight: bold; padding: 8px 0;"><?php esc_html_e( 'Dispatcher Last Error', 'olama-messages' ); ?></th>
									<td style="padding: 8px 0;">
										<?php if ( ! empty( $agent['heartbeat']['dispatcher_last_error'] ) ) : ?>
											<span style="color: #a00; font-family: monospace; font-size: 0.95em;"><?php echo esc_html( $agent['heartbeat']['dispatcher_last_error'] ); ?></span>
										<?php else : ?>
											<span class="olama-msg-muted">—</span>
										<?php endif; ?>
									</td>
								</tr>
							</table>
							
							<?php if ( $agent['status'] !== 'revoked' ) : ?>
								<div style="margin-top: 20px; border-top: 1px solid #eee; padding-top: 15px;">
									<a href="<?php echo esc_url( $revoke_url ); ?>" class="button button-link-delete" style="color: #a00; font-weight: bold;" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to revoke this agent? It will immediately stop authenticating.', 'olama-messages' ); ?>');">
										<?php esc_html_e( 'Revoke Agent Authorization', 'olama-messages' ); ?>
									</a>
								</div>
							<?php endif; ?>
							<div style="margin-top: 12px;">
								<a href="<?php echo esc_url( $delete_url ); ?>" class="button button-link-delete" style="color:#a00;font-weight:bold;" onclick="return confirm('<?php esc_attr_e( 'Permanently delete this sending agent? Its credentials will stop working immediately. Delivery and audit history will be preserved. This cannot be undone.', 'olama-messages' ); ?>');">
									<?php esc_html_e( 'Delete Agent Permanently', 'olama-messages' ); ?>
								</a>
							</div>
						</div>
					</div>
				</div>

				<!-- Event Logs (Right) -->
				<div class="olama-msg-agent-details-right" style="flex: 2; min-width: 400px; display: flex; flex-direction: column;">
					<div class="olama-msg-card" style="flex: 1; display: flex; flex-direction: column;">
						<div class="olama-msg-card__header"><?php esc_html_e( 'Recent Events Audit Log (Max 50)', 'olama-messages' ); ?></div>
						<div class="olama-msg-card__body" style="padding: 0; overflow-y: auto; max-height: 500px;">
							<table class="wp-list-table widefat fixed striped posts" style="border: 0;">
								<thead>
									<tr>
										<th style="width: 140px;"><?php esc_html_e( 'Timestamp', 'olama-messages' ); ?></th>
										<th style="width: 100px;"><?php esc_html_e( 'Event Type', 'olama-messages' ); ?></th>
										<th style="width: 80px;"><?php esc_html_e( 'Severity', 'olama-messages' ); ?></th>
										<th><?php esc_html_e( 'Message', 'olama-messages' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php if ( empty( $events ) ) : ?>
										<tr>
											<td colspan="4" class="olama-msg-muted" style="text-align: center; padding: 15px;">
												<?php esc_html_e( 'No events recorded for this agent.', 'olama-messages' ); ?>
											</td>
										</tr>
									<?php else : ?>
										<?php foreach ( $events as $e ) :
											$severity_class = '';
											if ( $e['severity'] === 'error' ) {
												$severity_class = 'olama-msg-pill--err';
											} elseif ( $e['severity'] === 'warning' ) {
												$severity_class = 'olama-msg-pill--warn';
											} else {
												$severity_class = 'olama-msg-muted';
											}
										?>
										<tr>
											<td style="font-size: 0.9em;"><?php echo esc_html( date( 'Y-m-d H:i:s', strtotime( $e['created_at'] ) ) ); ?></td>
											<td><code><?php echo esc_html( $e['event_type'] ); ?></code></td>
											<td>
												<span class="olama-msg-pill <?php echo esc_attr( $severity_class ); ?>" style="font-size: 0.8em;">
													<?php echo esc_html( $e['severity'] ); ?>
												</span>
											</td>
											<td style="font-size: 0.95em;">
												<?php echo esc_html( $e['message'] ); ?>
												<?php if ( ! empty( $e['context_json'] ) && $e['context_json'] !== '[]' ) : ?>
													<br>
													<code style="font-size: 0.8em; color: #555; background: #fafafa; display: block; padding: 4px; margin-top: 4px; overflow-x: auto; white-space: pre-wrap;"><?php echo esc_html( $e['context_json'] ); ?></code>
												<?php endif; ?>
											</td>
										</tr>
										<?php endforeach; ?>
									<?php endif; ?>
								</tbody>
							</table>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/** Save/Register Agent POST Action. */
	public function handle_save_agent() {
		check_admin_referer( 'olama_msg_save_agent' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'olama-messages' ) );
		}

		$agent_name = sanitize_text_field( $_POST['agent_name'] ?? '' );
		if ( empty( $agent_name ) ) {
			$agent_name = __( 'New Windows Agent', 'olama-messages' );
		}

		try {
			$reg = $this->plugin->agents()->create_agent( array(
				'agent_name' => $agent_name,
				'platform'   => 'Windows',
			) );

			$user_id = get_current_user_id();
			set_transient( 'olama_msg_new_agent_' . $user_id, array(
				'uuid'    => $reg['uuid'],
				'raw_key' => $reg['raw_key'],
				'name'    => $agent_name
			), 60 );

			$this->set_flash( __( 'Agent registered successfully. Please copy the API credentials below.', 'olama-messages' ), 'success' );
		} catch ( Exception $e ) {
			$this->set_flash( __( 'Failed to register agent: ', 'olama-messages' ) . $e->getMessage(), 'error' );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=olama-messages-agents' ) );
		exit;
	}

	/** Revoke Agent POST Action. */
	public function handle_revoke_agent() {
		$agent_id = isset( $_GET['agent_id'] ) ? absint( $_GET['agent_id'] ) : 0;
		check_admin_referer( 'olama_msg_revoke_agent_' . $agent_id );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'olama-messages' ) );
		}

		$ok = $this->plugin->agents()->revoke_agent( $agent_id );
		if ( $ok ) {
			$this->set_flash( __( 'Agent revoked successfully.', 'olama-messages' ), 'success' );
		} else {
			$this->set_flash( __( 'Failed to revoke agent.', 'olama-messages' ), 'error' );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=olama-messages-agents' ) );
		exit;
	}

	/** Permanently delete an agent registration while retaining historical records. */
	public function handle_delete_agent() {
		$agent_id = isset( $_GET['agent_id'] ) ? absint( $_GET['agent_id'] ) : 0;
		check_admin_referer( 'olama_msg_delete_agent_' . $agent_id );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'olama-messages' ) );
		}

		try {
			$this->plugin->agents()->delete_agent( $agent_id );
			$this->set_flash( __( 'Sending agent permanently deleted. Historical delivery and audit records were preserved.', 'olama-messages' ), 'success' );
		} catch ( Exception $e ) {
			$this->set_flash( $e->getMessage(), 'error' );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=olama-messages-agents' ) );
		exit;
	}

	// ─── Direct Message Feature (Phase 4D Stabilization) ─────────────────────

	/** Render Direct Message Page. */
	public function page_direct_message() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'olama-messages' ) );
		}

		global $wpdb;
		$table_agents     = $wpdb->prefix . 'olama_msg_agents';
		$five_minutes_ago = date( 'Y-m-d H:i:s', time() - 300 );

		// Check for any alive agent (not revoked, seen in last 5 min)
		$alive_agent = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table_agents}
			 WHERE status IN ('active','online')
			   AND revoked_at IS NULL
			   AND last_seen_at >= %s
			 ORDER BY last_seen_at DESC
			 LIMIT 1",
			$five_minutes_ago
		), ARRAY_A );

		// Check for a fully KDE-ready agent (for informational warning)
		$kde_ready_agent = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table_agents}
			 WHERE status IN ('active','online')
			   AND revoked_at IS NULL
			   AND kde_cli_found = 1
			   AND kde_device_reachable = 1
			   AND last_seen_at >= %s
			 LIMIT 1",
			$five_minutes_ago
		), ARRAY_A );

		// Check for a dispatcher-ready agent
		$dispatcher_ready_agent = $this->plugin->agents()->get_ready_dispatcher_agent();

		$templates   = $this->plugin->templates()->list_templates( array( 'is_active' => 1 ) );
		$years       = $this->plugin->provider()->get_available_study_years();
		$active_year = ! empty( $years ) ? $years[0] : '2026-2027';

		?>
		<div class="wrap olama-msg-wrap">
			<h1 class="olama-msg-page-title">
				<span class="dashicons dashicons-email-alt"></span>
				<?php esc_html_e( 'Direct Single SMS (Phase 4D)', 'olama-messages' ); ?>
			</h1>

			<!-- Page Description -->
			<p class="description" style="margin-bottom: 20px;">
				<?php esc_html_e( 'Send exactly one SMS safely to a specific family. Real payment report tokens are generated only at send time by the active Windows agent.', 'olama-messages' ); ?>
			</p>

			<!-- Agent Status Banner -->
			<?php if ( ! $alive_agent ) : ?>
			<div class="notice notice-error" style="margin: 0 0 20px 0;">
				<p><strong><?php esc_html_e( '⛔ No Windows agent is connected.', 'olama-messages' ); ?></strong><br>
				<?php esc_html_e( 'The Windows SMS agent must be running and have sent a heartbeat in the last 5 minutes before you can send. Please start the agent application.', 'olama-messages' ); ?></p>
			</div>
			<?php elseif ( ! $kde_ready_agent ) : ?>
			<div class="notice notice-warning" style="margin: 0 0 20px 0;">
				<p><strong><?php esc_html_e( '⚠️ Agent connected but KDE Connect is not fully ready.', 'olama-messages' ); ?></strong><br>
				<?php printf(
					esc_html__( 'Agent "%s" is online (last seen: %s) but KDE CLI or device reachability is not confirmed. Please configure KDE Connect on the agent machine.', 'olama-messages' ),
					esc_html( $alive_agent['agent_name'] ),
					esc_html( $alive_agent['last_seen_at'] )
				); ?></p>
			</div>
			<?php elseif ( ! $dispatcher_ready_agent ) : ?>
			<div class="notice notice-error" style="margin: 0 0 20px 0;">
				<p><strong><?php esc_html_e( '⛔ Dispatcher Disabled', 'olama-messages' ); ?></strong><br>
				<?php esc_html_e( 'Windows agent is online, but dispatcher is disabled. Right-click the tray icon and choose Enable Dispatcher.', 'olama-messages' ); ?></p>
			</div>
			<?php else : ?>
			<div class="notice notice-success" style="margin: 0 0 20px 0;">
				<p><strong><?php esc_html_e( '✅ Agent ready.', 'olama-messages' ); ?></strong>
				<?php printf(
					esc_html__( 'Agent "%s" is online with KDE Connect ready. Last seen: %s.', 'olama-messages' ),
					esc_html( $dispatcher_ready_agent['agent_name'] ),
					esc_html( $dispatcher_ready_agent['last_seen_at'] )
				); ?></p>
			</div>
			<?php endif; ?>

			<!-- Main Layout Container -->
			<?php if ( $dispatcher_ready_agent ) : ?>
			<div style="display: grid; grid-template-columns: 1fr 1.5fr; gap: 20px; align-items: start;">
				
				<!-- Left Column: Search Family -->
				<div class="olama-msg-card">
					<div class="olama-msg-card__header"><?php esc_html_e( '1. Select Family', 'olama-messages' ); ?></div>
					<div class="olama-msg-card__body">
						<div style="display: flex; gap: 10px; margin-bottom: 15px;">
							<input type="text" id="olama-msg-direct-search-input" class="regular-text" placeholder="<?php esc_attr_e( 'Type Family ID or Sponsor Name...', 'olama-messages' ); ?>" style="flex: 1; height: 36px;" />
							<button type="button" id="olama-msg-direct-search-btn" class="button button-primary" style="height: 36px; line-height: 34px;"><?php esc_html_e( 'Search', 'olama-messages' ); ?></button>
						</div>
						
						<div id="olama-msg-direct-search-results" style="max-height: 400px; overflow-y: auto; border: 1px solid var(--omsg-border); border-radius: 6px; background: #fafafa; display: none;">
							<!-- Search results populated here -->
						</div>
						<div id="olama-msg-direct-search-placeholder" class="olama-msg-muted" style="text-align: center; padding: 30px; border: 1px dashed var(--omsg-border); border-radius: 6px;">
							<?php esc_html_e( 'Search results will appear here.', 'olama-messages' ); ?>
						</div>
					</div>
				</div>
				
				<!-- Right Column: Message Composer -->
				<div id="olama-msg-direct-composer-card" class="olama-msg-card" style="display: none;">
					<div class="olama-msg-card__header"><?php esc_html_e( '2. Compose & Send', 'olama-messages' ); ?></div>
					<div class="olama-msg-card__body">
						
						<!-- Family Details Summary -->
						<div id="olama-msg-direct-family-summary" style="background: var(--omsg-bg); border: 1px solid var(--omsg-border); border-radius: 8px; padding: 15px; margin-bottom: 20px;">
							<!-- Dynamically populated -->
						</div>
						
						<!-- Send Form -->
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="olama-msg-direct-send-form">
							<?php wp_nonce_field( 'olama_msg_send_direct' ); ?>
							<input type="hidden" name="action" value="olama_msg_send_direct" />
							<input type="hidden" name="family_id" id="olama-msg-direct-family-id-val" value="" />
							<input type="hidden" name="study_year" id="olama-msg-direct-study-year-val" value="<?php echo esc_attr( $active_year ); ?>" />
							
							<!-- Recipient Selection (Only Father or Mother) -->
							<p style="margin-top: 0;"><strong><?php esc_html_e( 'Select Recipient:', 'olama-messages' ); ?></strong></p>
							<div style="display: flex; gap: 20px; margin-bottom: 20px;">
								<label id="olama-msg-direct-label-father" style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
									<input type="radio" name="recipient_role" value="father" checked />
									<span><?php esc_html_e( 'Father Only', 'olama-messages' ); ?></span>
								</label>
								<label id="olama-msg-direct-label-mother" style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
									<input type="radio" name="recipient_role" value="mother" />
									<span><?php esc_html_e( 'Mother Only', 'olama-messages' ); ?></span>
								</label>
							</div>
							
							<!-- Template Selection -->
							<div style="margin-bottom: 20px;">
								<label for="olama-msg-direct-template-select" style="display: block; font-weight: 600; margin-bottom: 6px;"><?php esc_html_e( 'Select Template (Optional):', 'olama-messages' ); ?></label>
								<select id="olama-msg-direct-template-select" class="postform" style="width: 100%; max-width: 100%;">
									<option value=""><?php esc_html_e( '── Write custom message from scratch ──', 'olama-messages' ); ?></option>
									<?php foreach ( $templates as $t ) : ?>
										<option value="<?php echo esc_attr( $t['id'] ); ?>"><?php echo esc_html( $t['name'] ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							
							<!-- Message Body -->
							<div style="margin-bottom: 15px;">
								<label for="olama-msg-direct-body-textarea" style="display: block; font-weight: 600; margin-bottom: 6px;"><?php esc_html_e( 'Message Body:', 'olama-messages' ); ?></label>
								<textarea name="message_body" id="olama-msg-direct-body-textarea" rows="8" style="width: 100%; font-family: monospace;" required></textarea>
							</div>
							
							<!-- Counters & Info -->
							<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
								<div id="olama-msg-direct-counters" class="olama-msg-muted" style="font-size: 0.9em;">
									Characters: <span id="olama-msg-direct-char-count">0</span> | SMS Parts: <span id="olama-msg-direct-part-count">0</span>
								</div>
								<div id="olama-msg-direct-payment-warning" class="olama-msg-muted" style="font-size: 0.9em; display: none; color: var(--omsg-success);">
									<span class="dashicons dashicons-admin-links" style="font-size: 16px; width: 16px; height: 16px; vertical-align: middle;"></span>
									<?php esc_html_e( 'Payment link placeholder {{PAYMENT_LINK}} detected.', 'olama-messages' ); ?>
								</div>
							</div>
							
							<!-- Dispatcher Warning Box -->
							<div style="margin-bottom: 15px; padding: 12px; background: #fff8e1; border-left: 4px solid #ffb300; border-radius: 4px;">
								<p style="margin: 0; font-size: 0.9em; color: #b78103; line-height: 1.4;">
									<strong><?php esc_html_e( '⚠️ Dispatcher Notice:', 'olama-messages' ); ?></strong><br>
									<?php esc_html_e( 'The Windows agent dispatcher must be enabled. This message will be sent only after the agent reserves it.', 'olama-messages' ); ?>
								</p>
							</div>

							<!-- Action Button -->
							<button type="submit" id="olama-msg-direct-submit-btn" class="button button-primary button-large" style="width: 100%; height: 40px; line-height: 38px; font-size: 1.05rem; font-weight: 600;">
								<span class="dashicons dashicons-email-alt" style="vertical-align: middle; margin-top: -2px;"></span>
								<?php esc_html_e( 'Queue Direct SMS for Agent', 'olama-messages' ); ?>
							</button>
						</form>
					</div>
				</div>
				
				<!-- Selection Placeholder Card -->
				<div id="olama-msg-direct-composer-placeholder" class="olama-msg-card" style="border: 1px dashed var(--omsg-border); background: transparent; text-align: center; padding: 80px 20px; display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%;">
					<span class="dashicons dashicons-email-alt" style="font-size: 4rem; width: auto; height: auto; color: var(--omsg-muted); margin-bottom: 15px;"></span>
					<h3><?php esc_html_e( 'No Family Selected', 'olama-messages' ); ?></h3>
					<p class="olama-msg-muted"><?php esc_html_e( 'Fuzzy search and select a family from the left panel to compose a message.', 'olama-messages' ); ?></p>
				</div>
				
			</div>
			<?php else : ?>
			<div class="olama-msg-card" style="padding: 40px; text-align: center; border: 1px dashed var(--omsg-border); background: #fafafa;">
				<span class="dashicons dashicons-lock" style="font-size: 3rem; width: auto; height: auto; color: var(--omsg-muted); margin-bottom: 15px;"></span>
				<h3><?php esc_html_e( 'Direct Messaging is Locked', 'olama-messages' ); ?></h3>
				<p class="olama-msg-muted"><?php esc_html_e( 'To send direct messages, a Windows agent with both KDE CLI and KDE device reachable must be online and active (last seen within 5 minutes) with dispatcher enabled.', 'olama-messages' ); ?></p>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Save/Queue Direct Message. */
	public function handle_send_direct() {
		check_admin_referer( 'olama_msg_send_direct' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'olama-messages' ) );
		}

		global $wpdb;

		// 1. Verify an online, dispatcher-ready agent is connected (not revoked, seen in the last 5 minutes).
		$dispatcher_ready_agent = $this->plugin->agents()->get_ready_dispatcher_agent();

		if ( ! $dispatcher_ready_agent ) {
			$this->set_flash( __( 'No online dispatcher-ready SMS agent is currently active. The agent must be online, KDE-ready, and have its dispatcher enabled.', 'olama-messages' ), 'error' );
			wp_safe_redirect( admin_url( 'admin.php?page=olama-messages-direct' ) );
			exit;
		}

		// 2. Extract parameters
		$family_id      = isset( $_POST['family_id'] ) ? absint( $_POST['family_id'] ) : 0;
		$recipient_role = sanitize_text_field( $_POST['recipient_role'] ?? '' );
		$message_body   = sanitize_textarea_field( $_POST['message_body'] ?? '' );
		$study_year     = sanitize_text_field( $_POST['study_year'] ?? '' );

		try {
			$campaign_id = $this->plugin->campaigns()->create_direct_message_campaign(
				$family_id,
				$recipient_role,
				$message_body,
				$study_year
			);

			$is_dispatcher_enabled = ! empty( $dispatcher_ready_agent['heartbeat']['dispatcher_enabled'] );
			if ( $is_dispatcher_enabled ) {
				$msg = __( 'Direct SMS queued successfully. The active Windows agent will dispatch it immediately.', 'olama-messages' );
			} else {
				$msg = __( 'Direct SMS queued successfully. It will be sent when the Windows agent dispatcher reserves the job.', 'olama-messages' );
			}

			$this->set_flash( $msg, 'success' );
			wp_safe_redirect( admin_url( 'admin.php?page=olama-messages-campaign-progress&campaign_id=' . $campaign_id ) );
			exit;
		} catch ( Exception $e ) {
			$this->set_flash( __( 'Failed to queue direct SMS: ', 'olama-messages' ) . $e->getMessage(), 'error' );
			wp_safe_redirect( admin_url( 'admin.php?page=olama-messages-direct' ) );
			exit;
		}
	}

	/** AJAX Action: Search Families for Direct Message. */
	public function ajax_search_families() {
		check_ajax_referer( 'olama_msg_ajax', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'olama-messages' ) ) );
		}

		$search     = sanitize_text_field( $_POST['search'] ?? '' );
		$study_year = sanitize_text_field( $_POST['study_year'] ?? '' );

		if ( empty( $search ) ) {
			wp_send_json_success( array( 'items' => array() ) );
		}

		$filters = array(
			'search'     => $search,
			'study_year' => $study_year,
			'limit'      => 15,
		);

		$res = $this->plugin->provider()->get_recipients_preview( $filters );

		wp_send_json_success( array(
			'items' => $res['items'] ?? array(),
		) );
	}

	/** AJAX Action: Render Template for Direct Message. */
	public function ajax_render_direct_template() {
		check_ajax_referer( 'olama_msg_ajax', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'olama-messages' ) ) );
		}

		$template_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		$family_id   = isset( $_POST['family_id'] ) ? absint( $_POST['family_id'] ) : 0;
		$study_year  = sanitize_text_field( $_POST['study_year'] ?? '' );

		if ( ! $template_id || ! $family_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid parameters.', 'olama-messages' ) ) );
		}

		$template = $this->plugin->templates()->get_template( $template_id );
		if ( ! $template ) {
			wp_send_json_error( array( 'message' => __( 'Template not found.', 'olama-messages' ) ) );
		}

		// Fetch family details using core provider
		$filters = array(
			'family_id'  => $family_id,
			'study_year' => $study_year,
			'limit'      => 1,
		);
		$res = $this->plugin->provider()->get_recipients_preview( $filters );
		if ( empty( $res['items'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Family not found.', 'olama-messages' ) ) );
		}

		$family = $res['items'][0];

		$bal_fmt = is_numeric( $family['balance'] ) ? number_format( $family['balance'], 3 ) : 'غير متوفر';
		$due_fmt = is_numeric( $family['monthly_due'] ) ? number_format( $family['monthly_due'], 3 ) : 'غير متوفر';

		$vars = array(
			'sponsor_name'       => $family['sponsor_name'] ?? '',
			'family_id'          => $family['oracle_family_id'] ?? (string) $family['family_id'],
			'students'           => $family['students'] ?? array(),
			'balance'            => $bal_fmt,
			'monthly_due'        => $due_fmt,
			'monthly_due_source' => $family['monthly_due_source'] ?? 'unavailable',
		);

		$rendered = $this->plugin->renderer()->render_campaign_sms( $template['body'], $vars );

		wp_send_json_success( array(
			'rendered' => $rendered,
		) );
	}
}
