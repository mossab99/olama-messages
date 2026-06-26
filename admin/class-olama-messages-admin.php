<?php
/**
 * Admin controller — registers menus and renders all 8 admin pages.
 *
 * Pages:
 *  1. Dashboard (updated with campaign/queue/template stats)
 *  2. Campaigns [NEW]
 *  3. New Campaign / Edit Campaign [NEW]
 *  4. Templates [NEW]
 *  5. Prepared Queue [NEW]
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
		add_action( 'admin_post_olama_msg_revoke_token',    array( $this, 'handle_revoke_token' ) );
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

		// Agent actions (Phase 3)
		add_action( 'admin_post_olama_msg_save_agent',        array( $this, 'handle_save_agent' ) );
		add_action( 'admin_post_olama_msg_revoke_agent',      array( $this, 'handle_revoke_agent' ) );

		// AJAX actions
		add_action( 'wp_ajax_olama_msg_preview_sms',             array( $this, 'ajax_preview_sms' ) );
		add_action( 'wp_ajax_olama_msg_preview_report',          array( $this, 'ajax_preview_report' ) );
		add_action( 'wp_ajax_olama_msg_preview_campaign_ajax',   array( $this, 'ajax_preview_campaign' ) );
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
			__( 'Prepared Queue', 'olama-messages' ),
			__( 'Prepared Queue', 'olama-messages' ),
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

		// Hidden page for Add/Edit Campaign
		add_submenu_page(
			null,
			__( 'New Campaign', 'olama-messages' ),
			__( 'New Campaign', 'olama-messages' ),
			'manage_options',
			'olama-messages-new-campaign',
			array( $this, 'page_new_campaign' )
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
			OLAMA_MSG_VERSION
		);
		wp_enqueue_script(
			'olama-messages-admin',
			OLAMA_MSG_URL . 'assets/admin.js',
			array( 'jquery' ),
			OLAMA_MSG_VERSION,
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
			'active'    => array( __( 'Active', 'olama-messages' ),    'olama-msg-badge--active' ),
			'revoked'   => array( __( 'Revoked', 'olama-messages' ),   'olama-msg-badge--revoked' ),
			'expired'   => array( __( 'Expired', 'olama-messages' ),   'olama-msg-badge--expired' ),
			'maxed'     => array( __( 'Max Views', 'olama-messages' ),  'olama-msg-badge--maxed' ),
			'draft'     => array( __( 'Draft', 'olama-messages' ),      'olama-msg-badge--draft' ),
			'prepared'  => array( __( 'Prepared', 'olama-messages' ),   'olama-msg-badge--active' ),
			'cancelled' => array( __( 'Cancelled', 'olama-messages' ),  'olama-msg-badge--revoked' ),
		);
		$item = $labels[ $status ] ?? array( esc_html( $status ), '' );
		return '<span class="olama-msg-badge ' . esc_attr( $item[1] ) . '">' . esc_html( $item[0] ) . '</span>';
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
							$prepare_url = wp_nonce_url(
								admin_url( 'admin-post.php?action=olama_msg_prepare_campaign&campaign_id=' . $c['id'] ),
								'olama_msg_prepare_' . $c['id']
							);
							$reset_url = wp_nonce_url(
								admin_url( 'admin-post.php?action=olama_msg_reset_campaign&campaign_id=' . $c['id'] ),
								'olama_msg_reset_' . $c['id']
							);
							$cancel_url = wp_nonce_url(
								admin_url( 'admin-post.php?action=olama_msg_cancel_campaign&campaign_id=' . $c['id'] ),
								'olama_msg_cancel_' . $c['id']
							);
							$delete_url = wp_nonce_url(
								admin_url( 'admin-post.php?action=olama_msg_delete_campaign&campaign_id=' . $c['id'] ),
								'olama_msg_delete_' . $c['id']
							);
						?>
						<tr>
							<td>
								<strong>
									<?php if ( $c['status'] === 'draft' ) : ?>
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=olama-messages-new-campaign&action=edit&campaign_id=' . $c['id'] ) ); ?>">
											<?php echo esc_html( $c['title'] ); ?>
										</a>
									<?php else : ?>
										<?php echo esc_html( $c['title'] ); ?>
									<?php endif; ?>
								</strong>
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
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=olama-messages-queue&campaign_id=' . $c['id'] ) ); ?>" class="button button-small button-primary">
										<?php esc_html_e( 'View Queue', 'olama-messages' ); ?>
									</a>
									<a href="<?php echo esc_url( $reset_url ); ?>" class="button button-small" onclick="return confirm('<?php esc_attr_e( 'Reset this campaign back to draft? This will clear all prepared queue records.', 'olama-messages' ); ?>');">
										<?php esc_html_e( 'Reset to Draft', 'olama-messages' ); ?>
									</a>
									<a href="<?php echo esc_url( $cancel_url ); ?>" class="button button-small button-link-delete" onclick="return confirm('<?php esc_attr_e( 'Cancel this prepared campaign? This is audit-permanent.', 'olama-messages' ); ?>');">
										<?php esc_html_e( 'Cancel', 'olama-messages' ); ?>
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

		$this->print_flash();
		?>
		<div class="wrap olama-msg-wrap">
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
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="olama-campaign-form">
									<input type="hidden" name="action" value="olama_msg_save_campaign">
									<?php if ( $is_edit ) : ?>
										<input type="hidden" name="campaign_id" value="<?php echo esc_attr( $campaign_id ); ?>">
									<?php endif; ?>
									<?php wp_nonce_field( 'olama_msg_save_campaign', 'olama_msg_campaign_nonce' ); ?>

									<table class="form-table" role="presentation" style="margin-top:0;">
										<tbody>
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
											<tr>
												<th scope="row">
													<label for="campaign-min-balance"><?php esc_html_e( 'Minimum Outstanding Balance', 'olama-messages' ); ?></label>
												</th>
												<td>
													<input type="number" step="0.001" id="campaign-min-balance" name="min_balance" value="<?php echo esc_attr( null !== ( $campaign['min_balance'] ?? null ) ? $campaign['min_balance'] : '' ); ?>" class="regular-text" placeholder="e.g. 10.000"> JOD
													<p class="description"><?php esc_html_e( 'Only notify families who owe this amount or more. Leave blank for no minimum.', 'olama-messages' ); ?></p>
												</td>
											</tr>
											<tr>
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
										</tbody>
									</table>

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

								<!-- Live List -->
								<div class="olama-campaign-preview-table-wrap" style="max-height:30rem;overflow-y:auto;border:1px solid #e2e8f0;border-radius:0.375rem;">
									<table class="widefat striped" id="olama-campaign-preview-table" style="box-shadow:none;border:none;">
										<thead>
											<tr>
												<th><?php esc_html_e( 'Family ID', 'olama-messages' ); ?></th>
												<th><?php esc_html_e( 'Recipient', 'olama-messages' ); ?></th>
												<th><?php esc_html_e( 'Normalized Phone', 'olama-messages' ); ?></th>
												<th><?php esc_html_e( 'Balance', 'olama-messages' ); ?></th>
												<th><?php esc_html_e( 'Status', 'olama-messages' ); ?></th>
												<th><?php esc_html_e( 'SMS', 'olama-messages' ); ?></th>
											</tr>
										</thead>
										<tbody id="olama-campaign-preview-tbody">
											<tr>
												<td colspan="6" style="text-align:center;padding:1.5rem;color:#64748b;">
													<?php esc_html_e( 'Fill the Study Year and select a template to run preview.', 'olama-messages' ); ?>
												</td>
											</tr>
										</tbody>
									</table>
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

	// ─── Page: Prepared Queue (Phase 2) ──────────────────────────────────────

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
				<?php esc_html_e( 'Prepared Message Queue (Read-Only)', 'olama-messages' ); ?>
			</h1>

			<div class="notice notice-info inline">
				<p>
					<strong><?php esc_html_e( 'Phase 2 Boundary:', 'olama-messages' ); ?></strong>
					<?php esc_html_e( 'This queue is read-only. No SMS or WhatsApp messages will be sent to parents or external networks in this phase. Real tokenized payment report links are generated only at the moment of actual delivery in a later phase.', 'olama-messages' ); ?>
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

		$tokens_svc = $this->plugin->tokens();
		$paged      = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$per_page   = 25;
		$offset     = ( $paged - 1 ) * $per_page;
		$total      = $tokens_svc->count_tokens();
		$tokens     = $tokens_svc->get_tokens_list( array( 'limit' => $per_page, 'offset' => $offset ) );
		$total_pages = (int) ceil( $total / $per_page );

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
						?>
						<tr>
							<td><?php echo esc_html( $tok['id'] ); ?></td>
							<td><code><?php echo esc_html( $tok['family_id'] ); ?></code></td>
							<td><code class="olama-msg-prefix"><?php echo esc_html( $tok['token_prefix'] ); ?>…</code></td>
							<td><?php echo esc_html( $tok['study_year'] ?: '—' ); ?></td>
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
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<?php if ( $total_pages > 1 ) :
				$base_url = admin_url( 'admin.php?page=olama-messages-tokens' );
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
			'recipient_policy'        => sanitize_text_field( wp_unslash( $_POST['recipient_policy'] ?? 'father_first' ) ),
			'min_balance'             => isset( $_POST['min_balance'] ) && $_POST['min_balance'] !== '' ? floatval( $_POST['min_balance'] ) : null,
			'exclude_credit_balances' => isset( $_POST['exclude_credit_balances'] ) ? 1 : 0,
			'exclude_zero_balances'   => isset( $_POST['exclude_zero_balances'] ) ? 1 : 0,
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

	/** Handle token generation POST. */
	public function handle_generate_token() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'olama-messages' ) );
		}

		$family_id  = isset( $_GET['family_id'] )  ? sanitize_text_field( wp_unslash( $_GET['family_id'] ) )  : '';
		$study_year = isset( $_GET['study_year'] )  ? sanitize_text_field( wp_unslash( $_GET['study_year'] ) ) : '';

		if ( ! $family_id ) {
			wp_die( esc_html__( 'Missing family_id.', 'olama-messages' ) );
		}

		check_admin_referer( 'olama_msg_gen_' . $family_id );

		try {
			$result = $this->plugin->tokens()->generate_token( $family_id, $study_year );

			// Store the public URL in a short-lived transient (10 min).
			$token_id      = $result['token_id'];
			$transient_key = 'olama_msg_new_token_' . get_current_user_id() . '_' . $token_id;
			set_transient( $transient_key, $result['public_url'], 10 * MINUTE_IN_SECONDS );

			$redirect = admin_url(
				'admin.php?page=olama-messages-tokens&new_token_id=' . $token_id
			);
			$this->set_flash( sprintf(
				/* translators: %s: family ID */
				__( 'Token generated for family %s. Copy the link now — it is shown once only. If lost, you must regenerate a new one.', 'olama-messages' ),
				esc_html( $family_id )
			), 'success' );
		} catch ( Exception $e ) {
			$this->set_flash( $e->getMessage(), 'error' );
			$redirect = admin_url( 'admin.php?page=olama-messages-recipients' );
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
			$payment_link    = '[رابط المطالبة الحقيقي]';
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
			'no_token_notice_text'       => __( 'لمعاينة الرسالة النهائية مع رابط المطالبة الحقيقي، قم بإنشاء أو إعادة إنشاء الرابط ثم انسخه مباشرة بعد الإنشاء. لا يمكن استرجاع الروابط السابقة لأن الرمز الأصلي لا يتم تخزينه.', 'olama-messages' ),
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
			'recipient_policy'        => sanitize_text_field( wp_unslash( $_POST['recipient_policy'] ?? 'father_first' ) ),
			'min_balance'             => isset( $_POST['min_balance'] ) && $_POST['min_balance'] !== '' ? floatval( $_POST['min_balance'] ) : null,
			'exclude_credit_balances' => isset( $_POST['exclude_credit_balances'] ) ? 1 : 0,
			'exclude_zero_balances'   => isset( $_POST['exclude_zero_balances'] ) ? 1 : 0,
		);

		try {
			$preview = $this->plugin->campaigns()->preview_candidates( $data, array(
				'limit'  => 50,
				'offset' => 0,
			) );

			wp_send_json_success( $preview );
		} catch ( Exception $e ) {
			wp_send_json_error( $e->getMessage() );
		}
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

			<div class="olama-msg-split-layout" style="display: flex; gap: 20px; align-items: flex-start; flex-wrap: wrap;">
				<!-- Add Agent Form (Left Column) -->
				<div class="olama-msg-split-left" style="flex: 1; min-width: 280px; max-width: 380px;">
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
				<div class="olama-msg-split-right" style="flex: 2; min-width: 500px;">
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
										<th><?php esc_html_e( 'Last Seen', 'olama-messages' ); ?></th>
										<th style="width: 150px; text-align: center;"><?php esc_html_e( 'Actions', 'olama-messages' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php if ( empty( $agents ) ) : ?>
										<tr>
											<td colspan="8" class="olama-msg-muted" style="text-align: center; padding: 15px;">
												<?php esc_html_e( 'No sending agents registered yet.', 'olama-messages' ); ?>
											</td>
										</tr>
									<?php else : ?>
										<?php foreach ( $agents as $a ) :
											$is_online = false;
											if ( $a['status'] === 'online' ) {
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
		if ( $agent['status'] === 'online' ) {
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
							</table>
							
							<?php if ( $agent['status'] !== 'revoked' ) : ?>
								<div style="margin-top: 20px; border-top: 1px solid #eee; padding-top: 15px;">
									<a href="<?php echo esc_url( $revoke_url ); ?>" class="button button-link-delete" style="color: #a00; font-weight: bold;" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to revoke this agent? It will immediately stop authenticating.', 'olama-messages' ); ?>');">
										<?php esc_html_e( 'Revoke Agent Authorization', 'olama-messages' ); ?>
									</a>
								</div>
							<?php endif; ?>
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
}
