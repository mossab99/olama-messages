<?php
/**
 * Focused renderers for the redesigned Messages operations experience.
 *
 * @package Olama_Messages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Olama_Messages_Modern_Admin {
	private $plugin;

	public function __construct( Olama_Messages_Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	private function header( $title, $description = '', $action = '' ) {
		echo '<div class="wrap olama-msg-app"><div class="omsg-page-head"><div><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $description ) . '</p></div>' . $action . '</div>';
	}

	private function status( $status ) {
		return '<span class="omsg-status omsg-status--' . esc_attr( sanitize_html_class( $status ) ) . '">' . esc_html( ucwords( str_replace( '_', ' ', $status ) ) ) . '</span>';
	}

	private function print_flash() {
		$key   = 'olama_msg_flash_' . get_current_user_id();
		$flash = get_transient( $key );
		if ( ! is_array( $flash ) || empty( $flash['message'] ) ) {
			return;
		}

		delete_transient( $key );
		$type = in_array( $flash['type'] ?? '', array( 'success', 'error', 'warning', 'info' ), true )
			? $flash['type']
			: 'info';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $flash['message'] ) . '</p></div>';
	}

	private function post_button( $action, $campaign_id, $label, $class = 'button' ) {
		$url = admin_url( 'admin-post.php' );
		$nonce_action = str_replace( 'olama_msg_', 'olama_msg_', $action ) . '_' . $campaign_id;
		return '<form class="omsg-inline-form" method="post" action="' . esc_url( $url ) . '"><input type="hidden" name="action" value="' . esc_attr( $action ) . '"><input type="hidden" name="campaign_id" value="' . absint( $campaign_id ) . '">' . wp_nonce_field( $nonce_action, '_wpnonce', true, false ) . '<button class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	public function overview() {
		$ops = $this->plugin->operations();
		$campaigns = $ops->campaign_counts();
		$queue = $ops->queue_counts();
		$ready = $ops->readiness();
		$recent = $this->plugin->campaigns()->list_campaigns( array( 'limit' => 6 ) );
		$action = '<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=olama-messages-new-campaign' ) ) . '">Create campaign</a>';
		$this->header( 'Messages Overview', 'Readiness, active work, and delivery health in one place.', $action );
		?>
		<div class="omsg-readiness <?php echo $ready['agent_ready'] && $ready['core_ready'] ? 'is-ready' : 'needs-attention'; ?>">
			<strong><?php echo $ready['agent_ready'] && $ready['core_ready'] ? 'Ready to send' : 'Action required before sending'; ?></strong>
			<span>Olama Core <?php echo $ready['core_ready'] ? 'ready' : 'needs synchronization'; ?> · Sending agent <?php echo $ready['agent_ready'] ? 'online' : 'offline'; ?></span>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=olama-messages-delivery' ) ); ?>">View delivery operations</a>
		</div>
		<div class="omsg-stat-grid">
			<div class="omsg-stat"><span>Draft campaigns</span><strong><?php echo absint( $campaigns['draft'] ); ?></strong></div>
			<div class="omsg-stat"><span>Awaiting authorization</span><strong><?php echo absint( $campaigns['prepared'] ); ?></strong></div>
			<div class="omsg-stat"><span>Sending / paused</span><strong><?php echo absint( $campaigns['sending'] + $campaigns['paused'] ); ?></strong></div>
			<div class="omsg-stat"><span>Failed / retrying</span><strong><?php echo absint( $queue['failed'] + $queue['retry_wait'] ); ?></strong></div>
		</div>
		<section class="omsg-panel"><div class="omsg-panel-head"><h2>Recent campaigns</h2><a href="<?php echo esc_url( admin_url( 'admin.php?page=olama-messages-campaigns' ) ); ?>">View all</a></div>
			<?php $this->campaign_table( $recent, false ); ?>
		</section>
		</div>
		<?php
	}

	public function campaigns() {
		$status = sanitize_key( $_GET['status'] ?? '' );
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$rows = $this->plugin->campaigns()->list_campaigns( array( 'status' => $status, 'search' => $search, 'limit' => 100 ) );
		$action = '<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=olama-messages-new-campaign' ) ) . '">Create campaign</a>';
		$this->header( 'Campaign Center', 'Find drafts, prepared campaigns, and delivery history.', $action );
		?>
		<form class="omsg-filterbar" method="get"><input type="hidden" name="page" value="olama-messages-campaigns">
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search campaign title">
			<select name="status"><option value="">All statuses</option><?php foreach ( array( 'draft','prepared','sending','paused','completed','cancelled' ) as $item ) : ?><option value="<?php echo esc_attr( $item ); ?>" <?php selected( $status, $item ); ?>><?php echo esc_html( ucwords( $item ) ); ?></option><?php endforeach; ?></select>
			<button class="button">Filter</button>
		</form>
		<section class="omsg-panel"><?php $this->campaign_table( $rows, true ); ?></section></div>
		<?php
	}

	private function campaign_table( $rows, $actions = true ) {
		?>
		<div class="omsg-table-scroll"><table class="widefat striped omsg-table"><thead><tr><th>Campaign</th><th>Audience</th><th>Status</th><th>Messages</th><th>Created</th><?php if ( $actions ) : ?><th>Next action</th><?php endif; ?></tr></thead><tbody>
		<?php if ( empty( $rows ) ) : ?><tr><td colspan="6">No campaigns match these filters.</td></tr><?php endif; ?>
		<?php foreach ( $rows as $row ) : ?>
			<tr><td><strong><?php echo esc_html( $row['title'] ?: 'Untitled campaign' ); ?></strong><small>#<?php echo absint( $row['id'] ); ?> · <?php echo esc_html( $row['study_year'] ); ?></small></td>
			<td><?php echo esc_html( ucwords( str_replace( '_', ' ', $row['target_type'] ) ) ); ?></td>
			<td><?php echo $this->status( $row['status'] ); ?></td>
			<td><?php echo absint( $row['total_included'] ); ?> included<br><small><?php echo absint( $row['total_excluded'] ); ?> excluded</small></td>
			<td><?php echo esc_html( $row['created_at'] ); ?></td>
			<?php if ( $actions ) : ?><td class="omsg-actions">
				<?php if ( 'draft' === $row['status'] ) : ?><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=olama-messages-new-campaign&campaign_id=' . $row['id'] ) ); ?>">Continue</a><?php endif; ?>
				<?php if ( 'prepared' === $row['status'] ) : ?><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=olama-messages-new-campaign&campaign_id=' . $row['id'] . '&step=5' ) ); ?>">Authorize</a><?php endif; ?>
				<?php if ( in_array( $row['status'], array( 'sending','paused','completed','completed_with_errors' ), true ) ) : ?><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=olama-messages-campaign-progress&campaign_id=' . $row['id'] ) ); ?>">View delivery</a><?php endif; ?>
			</td><?php endif; ?></tr>
		<?php endforeach; ?></tbody></table></div>
		<?php
	}

	public function wizard() {
		$id = absint( $_GET['campaign_id'] ?? 0 );
		$campaign = $id ? $this->plugin->campaigns()->get_campaign( $id ) : null;
		$step = max( 1, min( 5, absint( $_GET['step'] ?? ( $campaign['filters']['ui_step'] ?? 1 ) ) ) );
		$years = $this->plugin->provider()->get_available_study_years();
		$templates = $this->plugin->templates()->list_templates( array( 'channel' => 'sms', 'is_active' => 1 ) );
		$body = $campaign ? $this->plugin->campaigns()->get_effective_message_body( $campaign ) : '';
		$this->header( $campaign ? 'Edit Campaign' : 'Create Campaign', 'A guided five-step flow. Draft changes save automatically.' );
		?>
		<div class="omsg-wizard" data-campaign-id="<?php echo absint( $id ); ?>" data-step="<?php echo absint( $step ); ?>" data-status="<?php echo esc_attr( $campaign['status'] ?? 'draft' ); ?>">
			<ol class="omsg-steps"><?php foreach ( array( 'Purpose','Audience','Message','Review & Prepare','Authorize' ) as $i => $label ) : ?><li class="<?php echo $step === $i + 1 ? 'is-current' : ( $step > $i + 1 ? 'is-done' : '' ); ?>"><span><?php echo $i + 1; ?></span><?php echo esc_html( $label ); ?></li><?php endforeach; ?></ol>
			<div class="omsg-save-state" aria-live="polite">Draft changes save automatically</div>
			<form id="omsg-campaign-wizard-form">
				<input type="hidden" name="campaign_id" value="<?php echo absint( $id ); ?>"><input type="hidden" name="ui_step" value="<?php echo absint( $step ); ?>">
				<section class="omsg-step-panel" data-step="1"><h2>What is this campaign for?</h2><label>Campaign title<input type="text" required name="title" value="<?php echo esc_attr( $campaign['title'] ?? '' ); ?>" placeholder="Example: July payment reminder"></label><label>Study year<select name="study_year"><?php foreach ( $years as $year ) : ?><option <?php selected( $campaign['study_year'] ?? '', $year ); ?>><?php echo esc_html( $year ); ?></option><?php endforeach; ?></select></label></section>
				<section class="omsg-step-panel" data-step="2">
					<h2>Choose the audience category</h2>
					<p class="description">Select an audience category and configure specific filters for targeted messaging.</p>

					<?php
					$curr_target = $campaign['target_type'] ?? 'finance_outstanding';
					// Map legacy target_types to new category tabs
					if ( 'collection' === $curr_target ) { $curr_target = 'finance_outstanding'; }
					if ( 'renewal_reminder' === $curr_target ) { $curr_target = 'finance_renewal_reminder'; }
					if ( 'general' === $curr_target ) { $curr_target = 'academic'; }
					if ( 'transportation' === $curr_target ) { $curr_target = 'transport_registered'; }

					$active_cat = 'finance';
					if ( in_array( $curr_target, array( 'academic' ), true ) ) {
						$active_cat = 'academic';
					} elseif ( in_array( $curr_target, array( 'transport_no_gps', 'transport_registered' ), true ) ) {
						$active_cat = 'transportation';
					} elseif ( in_array( $curr_target, array( 'store_missing' ), true ) ) {
						$active_cat = 'store';
					}

					$saved_filters = is_array( $campaign['filters'] ?? null ) ? $campaign['filters'] : array();
					?>

					<div class="omsg-audience-cats" role="tablist">
						<button type="button" class="omsg-cat-tab <?php echo 'finance' === $active_cat ? 'is-active' : ''; ?>" data-cat="finance">
							<span class="dashicons dashicons-money-alt"></span>
							<strong>Finance</strong>
							<small>Outstanding balances</small>
						</button>
						<button type="button" class="omsg-cat-tab <?php echo 'academic' === $active_cat ? 'is-active' : ''; ?>" data-cat="academic">
							<span class="dashicons dashicons-welcome-learn-more"></span>
							<strong>Academic</strong>
							<small>School / Grade / Section</small>
						</button>
						<button type="button" class="omsg-cat-tab <?php echo 'transportation' === $active_cat ? 'is-active' : ''; ?>" data-cat="transportation">
							<span class="dashicons dashicons-bus"></span>
							<strong>Transportation</strong>
							<small>GPS status &amp; bus routes</small>
						</button>
						<button type="button" class="omsg-cat-tab <?php echo 'store' === $active_cat ? 'is-active' : ''; ?>" data-cat="store">
							<span class="dashicons dashicons-store"></span>
							<strong>Store</strong>
							<small>Pending books &amp; uniforms</small>
						</button>
					</div>

					<!-- Category Panel: Finance -->
					<div class="omsg-cat-panel <?php echo 'finance' === $active_cat ? 'is-active' : ''; ?>" data-cat-panel="finance">
						<div class="omsg-subtarget-options">
							<label class="omsg-choice">
								<input type="radio" name="target_type" value="finance_outstanding" <?php checked( $curr_target, 'finance_outstanding' ); ?>>
								<div>
									<strong>Outstanding Balances</strong>
									<small>Target families with unpaid balances or specific financial statuses.</small>
								</div>
							</label>
							<label class="omsg-choice">
								<input type="radio" name="target_type" value="finance_renewal_reminder" <?php checked( $curr_target, 'finance_renewal_reminder' ); ?>>
								<div>
									<strong>Renewal Reminder</strong>
									<small>Target families enrolled in the previous academic year who have not registered for the current academic year and were not transferred.</small>
								</div>
							</label>
						</div>

						<div class="omsg-filter-box omsg-finance-outstanding-filters <?php echo 'finance_renewal_reminder' === $curr_target ? 'is-hidden' : ''; ?>">
							<h3>Finance Audience Filters</h3>
							<p class="description">Target families with unpaid balances or specific financial statuses.</p>
							<div class="omsg-filter-row">
								<label>Minimum Outstanding Balance (JOD)
									<input type="number" step="0.001" name="filter_min_balance" value="<?php echo esc_attr( $saved_filters['min_balance'] ?? '' ); ?>" placeholder="0.000">
								</label>
								<label class="omsg-checkbox-label">
									<input type="checkbox" name="filter_exclude_credit" value="1" <?php checked( ! empty( $saved_filters['exclude_credit_balances'] ?? 1 ) ); ?>>
									Exclude families with credit balances
								</label>
								<label class="omsg-checkbox-label">
									<input type="checkbox" name="filter_exclude_zero" value="1" <?php checked( ! empty( $saved_filters['exclude_zero_balances'] ?? 1 ) ); ?>>
									Exclude families with zero balance
								</label>
							</div>
						</div>
					</div>

					<!-- Category Panel: Academic -->
					<div class="omsg-cat-panel <?php echo 'academic' === $active_cat ? 'is-active' : ''; ?>" data-cat-panel="academic">
						<input type="radio" name="target_type" value="academic" class="omsg-target-radio" <?php checked( $curr_target, 'academic' ); ?>>
						<div class="omsg-filter-box">
							<h3>Academic Filtering (School / Grade / Section)</h3>
							<p class="description">Select a school, grade, or section to filter student families.</p>
							<div class="omsg-filter-row">
								<label>School (مدرسة / روضة)
									<select name="filter_school_id" id="omsg-filter-school">
										<option value="">All Schools / Kindergarten</option>
										<?php if ( ! empty( $saved_filters['school_id'] ) ) : ?>
											<option value="<?php echo esc_attr( $saved_filters['school_id'] ); ?>" selected><?php echo esc_html( $saved_filters['school_name'] ?? $saved_filters['school_id'] ); ?></option>
										<?php endif; ?>
									</select>
								</label>
								<label>Grade (الصف)
									<select name="filter_class_id" id="omsg-filter-grade">
										<option value="">All Grades</option>
										<?php if ( ! empty( $saved_filters['class_id'] ) ) : ?>
											<option value="<?php echo esc_attr( $saved_filters['class_id'] ); ?>" selected><?php echo esc_html( $saved_filters['class_name'] ?? $saved_filters['class_id'] ); ?></option>
										<?php endif; ?>
									</select>
								</label>
								<label>Section (الشعبة)
									<select name="filter_section_id" id="omsg-filter-section">
										<option value="">All Sections</option>
										<?php if ( ! empty( $saved_filters['section_id'] ) ) : ?>
											<option value="<?php echo esc_attr( $saved_filters['section_id'] ); ?>" selected><?php echo esc_html( $saved_filters['section_name'] ?? $saved_filters['section_id'] ); ?></option>
										<?php endif; ?>
									</select>
								</label>
							</div>
						</div>
					</div>

					<!-- Category Panel: Transportation -->
					<div class="omsg-cat-panel <?php echo 'transportation' === $active_cat ? 'is-active' : ''; ?>" data-cat-panel="transportation">
						<div class="omsg-subtarget-options">
							<label class="omsg-choice">
								<input type="radio" name="target_type" value="transport_no_gps" <?php checked( $curr_target, 'transport_no_gps' ); ?>>
								<div>
									<strong>Families without GPS locations</strong>
									<small>Target registered bus families who have not set coordinates in the transportation map.</small>
								</div>
							</label>
							<label class="omsg-choice">
								<input type="radio" name="target_type" value="transport_registered" <?php checked( $curr_target, 'transport_registered' ); ?>>
								<div>
									<strong>Registered transportation families</strong>
									<small>Filter bus subscribers by geographic area, bus number, or trip direction.</small>
								</div>
							</label>
						</div>

						<div class="omsg-filter-box omsg-transport-filters <?php echo 'transport_registered' === $curr_target ? '' : 'is-hidden'; ?>">
							<h3>Transportation Filters</h3>
							<div class="omsg-filter-row">
								<label>Major Area (المنطقة)
									<select name="filter_major_area_id" id="omsg-filter-area">
										<option value="">All Areas</option>
										<?php if ( ! empty( $saved_filters['major_area_id'] ) ) : ?>
											<option value="<?php echo esc_attr( $saved_filters['major_area_id'] ); ?>" selected><?php echo esc_html( $saved_filters['major_area_name'] ?? $saved_filters['major_area_id'] ); ?></option>
										<?php endif; ?>
									</select>
								</label>
								<label>Departure Bus (حافلة الصباح / الذهاب)
									<select name="filter_departure_bus" id="omsg-filter-dep-bus">
										<option value="">All Departure Buses</option>
										<?php if ( ! empty( $saved_filters['departure_bus'] ) ) : ?>
											<option value="<?php echo esc_attr( $saved_filters['departure_bus'] ); ?>" selected><?php echo esc_html( $saved_filters['departure_bus'] ); ?></option>
										<?php endif; ?>
									</select>
								</label>
								<label>Arrival Bus (حافلة المساء / العودة)
									<select name="filter_arrival_bus" id="omsg-filter-arr-bus">
										<option value="">All Arrival Buses</option>
										<?php if ( ! empty( $saved_filters['arrival_bus'] ) ) : ?>
											<option value="<?php echo esc_attr( $saved_filters['arrival_bus'] ); ?>" selected><?php echo esc_html( $saved_filters['arrival_bus'] ); ?></option>
										<?php endif; ?>
									</select>
								</label>
							</div>
						</div>
					</div>

					<!-- Category Panel: Store -->
					<div class="omsg-cat-panel <?php echo 'store' === $active_cat ? 'is-active' : ''; ?>" data-cat-panel="store">
						<input type="radio" name="target_type" value="store_missing" class="omsg-target-radio" <?php checked( $curr_target, 'store_missing' ); ?>>
						<div class="omsg-filter-box">
							<h3>Store &amp; Warehouse Audience</h3>
							<p class="description">Target families who have NOT yet received their allocated textbooks or school uniform/custom packages for this academic year.</p>
							<div class="omsg-filter-row">
								<label>Missing Item Filter
									<select name="filter_store_item_type" id="omsg-filter-store-item-type">
										<option value="both" <?php selected( $saved_filters['store_item_type'] ?? 'both', 'both' ); ?>>Families missing Textbooks OR Uniforms (All Unreceived)</option>
										<option value="books" <?php selected( $saved_filters['store_item_type'] ?? '', 'books' ); ?>>Families missing Textbooks only (الكتب المدرسية فقط)</option>
										<option value="custom" <?php selected( $saved_filters['store_item_type'] ?? '', 'custom' ); ?>>Families missing Uniforms / Custom Items only (الزي والزي الموحد فقط)</option>
									</select>
								</label>
							</div>
							<div class="omsg-info-banner">
								<span class="dashicons dashicons-info"></span>
								<span>Uses the authoritative Olama Stores report engine for 100% accuracy matching the Reports Center.</span>
							</div>
						</div>
					</div>

					<!-- Common Setting: Recipient Policy -->
					<div class="omsg-policy-box">
						<label><strong>Recipient Policy</strong>
							<select name="recipient_policy">
								<option value="father_first">Father first, mother fallback</option>
								<option value="mother_first" <?php selected( $campaign['recipient_policy'] ?? '', 'mother_first' ); ?>>Mother first, father fallback</option>
								<option value="both_parents" <?php echo in_array( $campaign['recipient_policy'] ?? '', array( 'both', 'both_parents' ), true ) ? 'selected' : ''; ?>>Both parents (separate SMS)</option>
							</select>
						</label>
					</div>
				</section>
				<section class="omsg-step-panel" data-step="3"><h2>Write the message</h2><label>Start from a message library item<select name="template_id"><option value="">Custom message</option><?php foreach ( $templates as $template ) : ?><option value="<?php echo absint( $template['id'] ); ?>" data-body="<?php echo esc_attr( $template['body'] ); ?>" <?php selected( $campaign['template_id'] ?? 0, $template['id'] ); ?>><?php echo esc_html( $template['name'] ); ?></option><?php endforeach; ?></select></label><label>Message<textarea required name="message_body_draft" rows="9"><?php echo esc_textarea( $body ); ?></textarea></label><div class="omsg-message-meter"><strong data-sms-parts>0 SMS parts</strong><span data-sms-detail>0 characters</span></div><p class="description">Available fields: {sponsor_name}, {family_id}, {students}, {balance}, {monthly_due}, {payment_link}, {study_year}, {school_name}</p></section>
				<section class="omsg-step-panel" data-step="4"><h2>Review and prepare</h2><div class="omsg-review-summary"><p>Preparation freezes the audience and rendered messages. It does not send anything.</p><button type="button" class="button" data-preview-campaign>Refresh audience preview</button><div data-preview-result aria-live="polite"></div></div></section>
				<section class="omsg-step-panel" data-step="5"><h2>Authorize sending</h2>
					<?php if ( $campaign && 'prepared' === $campaign['status'] ) : ?><p><strong><?php echo absint( $campaign['total_prepared'] ); ?></strong> prepared messages are locked and awaiting authorization.</p><p>Type <code>SEND <?php echo absint( $campaign['total_prepared'] ); ?></code> to start.</p><?php else : ?><p>Prepare the campaign in step 4 before authorization.</p><?php endif; ?>
				</section>
				<div class="omsg-wizard-actions"><button type="button" class="button" data-wizard-back>Back</button><button type="button" class="button button-primary" data-wizard-next>Save & continue</button></div>
			</form>
			<?php if ( $campaign && 'prepared' === $campaign['status'] ) : ?><form class="omsg-reset-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="olama_msg_reset_campaign"><input type="hidden" name="campaign_id" value="<?php echo absint( $id ); ?>"><input type="hidden" name="redirect_step" value="4"><?php wp_nonce_field( 'olama_msg_reset_' . $id ); ?><button class="button">Unlock &amp; edit recipients</button><small>This removes the prepared snapshot. It does not send anything.</small></form><?php endif; ?>
			<?php if ( $campaign && 'prepared' === $campaign['status'] ) : ?><form class="omsg-authorize-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="olama_msg_start_campaign"><input type="hidden" name="campaign_id" value="<?php echo absint( $id ); ?>"><?php wp_nonce_field( 'olama_msg_start_' . $id ); ?><input name="confirmation" autocomplete="off" placeholder="SEND <?php echo absint( $campaign['total_prepared'] ); ?>"><button class="button button-primary">Authorize & start</button></form><?php endif; ?>
		</div>
		<div class="omsg-sms-preview-modal" data-sms-preview-modal role="dialog" aria-modal="true" aria-labelledby="omsg-sms-preview-title" hidden>
			<button type="button" class="omsg-sms-preview-backdrop" data-close-sms-preview aria-label="Close message preview"></button>
			<div class="omsg-sms-preview-dialog">
				<div class="omsg-sms-preview-head"><div><h2 id="omsg-sms-preview-title">SMS preview</h2><p data-sms-preview-recipient></p></div><button type="button" class="button-link omsg-sms-preview-close" data-close-sms-preview aria-label="Close message preview">&times;</button></div>
				<div class="omsg-sms-preview-meta"><span data-sms-preview-phone></span><span data-sms-preview-counts></span></div>
				<div class="omsg-sms-phone-frame"><div class="omsg-sms-bubble" data-sms-preview-body dir="auto"></div></div>
				<p class="description">This is the rendered message with family-specific variables applied. Payment links remain placeholders until sending.</p>
				<div class="omsg-sms-preview-footer"><button type="button" class="button button-primary" data-close-sms-preview>Close</button></div>
			</div>
		</div>
		</div>
		<?php
	}

	public function delivery() {
		$counts = $this->plugin->operations()->queue_counts();
		$ready = $this->plugin->operations()->readiness();
		$failures = $this->plugin->operations()->recent_failures( 20 );
		$this->header( 'Delivery Operations', 'Queue health, sending-agent readiness, and failures.' );
		?>
		<div class="omsg-stat-grid"><div class="omsg-stat"><span>Prepared</span><strong><?php echo absint( $counts['prepared'] ); ?></strong></div><div class="omsg-stat"><span>In progress</span><strong><?php echo absint( $counts['reserved'] ); ?></strong></div><div class="omsg-stat"><span>Sent</span><strong><?php echo absint( $counts['sent'] ); ?></strong></div><div class="omsg-stat"><span>Needs attention</span><strong><?php echo absint( $counts['failed'] + $counts['retry_wait'] ); ?></strong></div></div>
		<section class="omsg-panel"><div class="omsg-panel-head"><h2>Sending agent</h2><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=olama-messages-agents' ) ); ?>">Manage agents</a></div><p><?php echo $ready['agent_ready'] ? '<span class="omsg-status omsg-status--completed">Online and ready</span>' : '<span class="omsg-status omsg-status--failed">No ready dispatcher</span>'; ?></p></section>
		<section class="omsg-panel"><div class="omsg-panel-head"><h2>Recent failures and retries</h2></div><table class="widefat striped"><thead><tr><th>Campaign</th><th>Phone</th><th>Status</th><th>Last error</th><th>Updated</th></tr></thead><tbody><?php if ( ! $failures ) : ?><tr><td colspan="5">No delivery failures.</td></tr><?php endif; ?><?php foreach ( $failures as $row ) : ?><tr><td><?php echo esc_html( $row['campaign_title'] ); ?></td><td dir="ltr"><?php echo esc_html( $row['phone_e164'] ); ?></td><td><?php echo $this->status( $row['status'] ); ?></td><td><?php echo esc_html( $row['last_error_message'] ?? '' ); ?></td><td><?php echo esc_html( $row['updated_at'] ); ?></td></tr><?php endforeach; ?></tbody></table></section>
		</div>
		<?php
	}

	public function phone_book() {
		$years = $this->plugin->provider()->get_available_study_years();
		$selected_year = sanitize_text_field( wp_unslash( $_GET['study_year'] ?? ( $years[0] ?? '' ) ) );
		if ( ! in_array( $selected_year, $years, true ) && $years ) {
			$selected_year = (string) $years[0];
		}
		$merge_year_default = '';
		$selected_year_index = array_search( $selected_year, $years, true );
		if ( false !== $selected_year_index && isset( $years[ $selected_year_index + 1 ] ) ) {
			$merge_year_default = (string) $years[ $selected_year_index + 1 ];
		} else {
			foreach ( $years as $year ) {
				if ( (string) $year !== $selected_year ) {
					$merge_year_default = (string) $year;
					break;
				}
			}
		}
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$paged = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per_page = 50;
		$result = $this->plugin->provider()->get_phone_book(
			$selected_year,
			array(
				'search' => $search,
				'limit'  => $per_page,
				'offset' => ( $paged - 1 ) * $per_page,
			)
		);
		$total = absint( $result['total'] ?? 0 );
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$summary = is_array( $result['summary'] ?? null ) ? $result['summary'] : array();
		$last_synced_at = sanitize_text_field( (string) ( $summary['last_synced_at'] ?? '' ) );
		$can_sync = current_user_can( 'olama_access_messages' ) && current_user_can( 'olama_access_oracle_sync' );
		$bridge_available = function_exists( 'olama_core' ) && method_exists( olama_core(), 'sync' ) && olama_core()->sync()->available( 'family_contacts' );
		$action = '';
		if ( $can_sync ) {
			$action  = '<form class="omsg-inline-form omsg-phonebook-sync" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			$action .= '<input type="hidden" name="action" value="olama_msg_sync_phone_book">';
			$action .= '<input type="hidden" name="study_year" value="' . esc_attr( $selected_year ) . '">';
			$action .= wp_nonce_field( 'olama_msg_sync_phone_book', '_wpnonce', true, false );
			$action .= '<button class="button button-primary"' . ( $bridge_available ? '' : ' disabled' ) . '>' . esc_html__( 'Sync latest from Olama Bridge', 'olama-messages' ) . '</button>';
			$action .= '</form>';
		}
		$this->header( 'Phone Book', 'Active families and parent contact details synchronized from Olama Core.', $action );
		$this->print_flash();
		?>
		<?php if ( 'olama_core' !== ( $result['data_source'] ?? '' ) ) : ?>
			<div class="notice notice-error inline"><p><?php esc_html_e( 'Olama Core is unavailable. Activate or update Olama Core before using the Phone Book.', 'olama-messages' ); ?></p></div>
		<?php elseif ( $can_sync && ! $bridge_available ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'Olama Bridge is unavailable. Activate or update Olama Oracle Sync to retrieve the latest family details.', 'olama-messages' ); ?></p></div>
		<?php endif; ?>
		<div class="omsg-source-note">
			<strong><?php esc_html_e( 'Source: Olama Core tables', 'olama-messages' ); ?></strong>
			<span>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: active family count, 2: study year */
						__( '%1$s active families for %2$s', 'olama-messages' ),
						number_format_i18n( $total ),
						$selected_year ?: '—'
					)
				);
				?>
				<?php if ( $last_synced_at ) : ?>
					<small>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: date and time of the latest Olama Core family synchronization */
								__( 'Last Bridge sync: %s', 'olama-messages' ),
								$last_synced_at
							)
						);
						?>
					</small>
				<?php endif; ?>
			</span>
		</div>
		<section class="omsg-panel omsg-phonebook-export">
			<div class="omsg-panel-head">
				<div>
					<h2><?php esc_html_e( 'Google Contacts export', 'olama-messages' ); ?></h2>
					<p><?php esc_html_e( 'Download one contact per family, including active students, classes, sections, and family transportation.', 'olama-messages' ); ?></p>
				</div>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="olama_msg_export_phone_book">
				<?php wp_nonce_field( 'olama_msg_export_phone_book' ); ?>
				<div class="omsg-phonebook-export-fields">
					<label>
						<?php esc_html_e( 'Phone book year', 'olama-messages' ); ?>
						<select name="study_year" required>
							<?php foreach ( $years as $year ) : ?>
								<option value="<?php echo esc_attr( $year ); ?>" <?php selected( $selected_year, $year ); ?>><?php echo esc_html( $year ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label class="omsg-phonebook-merge-toggle">
						<input type="checkbox" name="merge_years" value="1" <?php disabled( '' === $merge_year_default ); ?>>
						<span><?php esc_html_e( 'Merge families from another year', 'olama-messages' ); ?></span>
					</label>
					<label>
						<?php esc_html_e( 'Additional year', 'olama-messages' ); ?>
						<select name="merge_year" <?php disabled( '' === $merge_year_default ); ?>>
							<?php foreach ( $years as $year ) : ?>
								<?php if ( (string) $year !== $selected_year ) : ?>
									<option value="<?php echo esc_attr( $year ); ?>" <?php selected( $merge_year_default, $year ); ?>><?php echo esc_html( $year ); ?></option>
								<?php endif; ?>
							<?php endforeach; ?>
						</select>
					</label>
					<button class="button button-primary" type="submit" <?php disabled( empty( $years ) ); ?>><?php esc_html_e( 'Download Google CSV', 'olama-messages' ); ?></button>
				</div>
				<p class="description">
					<?php esc_html_e( 'When merging, a family that exists in both years is exported once. The selected phone book year takes priority.', 'olama-messages' ); ?>
				</p>
			</form>
		</section>
		<form class="omsg-filterbar omsg-phonebook-filter" method="get">
			<input type="hidden" name="page" value="olama-messages-phone-book">
			<label>Study year
				<select name="study_year"><?php foreach ( $years as $year ) : ?><option value="<?php echo esc_attr( $year ); ?>" <?php selected( $selected_year, $year ); ?>><?php echo esc_html( $year ); ?></option><?php endforeach; ?></select>
			</label>
			<label>Search families
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Family ID, parent, phone, or address">
			</label>
			<button class="button button-primary">View families</button>
			<?php if ( $search ) : ?><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=olama-messages-phone-book&study_year=' . rawurlencode( $selected_year ) ) ); ?>">Clear</a><?php endif; ?>
		</form>
		<section class="omsg-panel omsg-phonebook-panel">
			<div class="omsg-table-scroll"><table class="widefat striped omsg-table omsg-phonebook-table">
				<thead><tr><th>Family</th><th>Father</th><th>Father phone</th><th>Mother</th><th>Mother phone</th><th>Address</th></tr></thead>
				<tbody>
				<?php if ( empty( $result['items'] ) ) : ?><tr><td colspan="6">No active families match this year and search.</td></tr><?php endif; ?>
				<?php foreach ( (array) ( $result['items'] ?? array() ) as $family ) : ?>
					<tr>
						<td><strong>#<?php echo esc_html( $family['family_id'] ); ?></strong><small><?php echo esc_html( $family['sponsor_name'] ); ?></small></td>
						<td><?php echo esc_html( $family['father_name'] ?: '—' ); ?></td>
						<td><?php $father_tel = preg_replace( '/[^0-9+]/', '', $family['father_mobile'] ); ?><?php if ( $father_tel ) : ?><a href="tel:<?php echo esc_attr( $father_tel ); ?>" dir="ltr"><?php echo esc_html( $family['father_mobile'] ); ?></a><?php else : ?>—<?php endif; ?></td>
						<td><?php echo esc_html( $family['mother_name'] ?: '—' ); ?></td>
						<td><?php $mother_tel = preg_replace( '/[^0-9+]/', '', $family['mother_mobile'] ); ?><?php if ( $mother_tel ) : ?><a href="tel:<?php echo esc_attr( $mother_tel ); ?>" dir="ltr"><?php echo esc_html( $family['mother_mobile'] ); ?></a><?php else : ?>—<?php endif; ?></td>
						<td class="omsg-address"><?php echo esc_html( $family['address'] ?: '—' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages"><?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'current'   => $paged,
							'total'     => $total_pages,
							'prev_text' => '‹',
							'next_text' => '›',
						)
					)
				);
				?></div></div>
			<?php endif; ?>
		</section></div>
		<?php
	}
}
