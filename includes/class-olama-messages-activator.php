<?php
/**
 * Activator — creates plugin database tables.
 *
 * @package Olama_Messages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Olama_Messages_Activator {

	/**
	 * Run on plugin activation.
	 */
	public static function activate() {
		self::create_tables();
		update_option( 'olama_msg_db_version', OLAMA_MSG_VERSION );

		// Register the rewrite rule NOW, before flushing, so the flush
		// actually persists the rule into the rewrite cache.
		add_rewrite_rule(
			'^olama-payment-report/([A-Za-z0-9._-]+)/?$',
			'index.php?olama_payment_token=$matches[1]',
			'top'
		);
		flush_rewrite_rules();

		// Insert default templates if template service is available.
		if ( file_exists( OLAMA_MSG_PATH . 'includes/class-olama-messages-template-service.php' ) ) {
			require_once OLAMA_MSG_PATH . 'includes/class-olama-messages-template-service.php';
			$template_svc = new Olama_Messages_Template_Service();
			$template_svc->ensure_default_templates();
		}
	}

	/**
	 * Run on plugin deactivation.
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}

	/**
	 * Create (or upgrade) the plugin's tables using dbDelta.
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$tokens          = $wpdb->prefix . 'olama_msg_tokens';
		$token_views     = $wpdb->prefix . 'olama_msg_token_views';
		$templates       = $wpdb->prefix . 'olama_msg_templates';
		$campaigns       = $wpdb->prefix . 'olama_msg_campaigns';
		$recipients      = $wpdb->prefix . 'olama_msg_campaign_recipients';
		$queue           = $wpdb->prefix . 'olama_msg_queue';

		// ── Tokens table ─────────────────────────────────────────────────────
		dbDelta( "CREATE TABLE {$tokens} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			family_id BIGINT UNSIGNED NOT NULL,
			token_hash VARCHAR(255) NOT NULL,
			token_prefix VARCHAR(20) NOT NULL,
			purpose VARCHAR(50) NOT NULL DEFAULT 'payment_report',
			study_year VARCHAR(20) NULL,
			expires_at DATETIME NULL,
			max_views INT UNSIGNED NULL,
			view_count INT UNSIGNED NOT NULL DEFAULT 0,
			last_viewed_at DATETIME NULL,
			revoked_at DATETIME NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY idx_family_id (family_id),
			KEY idx_token_prefix (token_prefix),
			KEY idx_purpose (purpose),
			KEY idx_expires_at (expires_at)
		) {$charset_collate};" );

		// ── Token views table ─────────────────────────────────────────────────
		dbDelta( "CREATE TABLE {$token_views} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			token_id BIGINT UNSIGNED NOT NULL,
			family_id BIGINT UNSIGNED NOT NULL,
			viewed_at DATETIME NOT NULL,
			ip_hash VARCHAR(128) NULL,
			user_agent_hash VARCHAR(128) NULL,
			PRIMARY KEY  (id),
			KEY idx_token_id (token_id),
			KEY idx_family_id (family_id),
			KEY idx_viewed_at (viewed_at)
		) {$charset_collate};" );

		// ── Templates table ───────────────────────────────────────────────────
		dbDelta( "CREATE TABLE {$templates} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL,
			channel VARCHAR(30) NOT NULL DEFAULT 'sms',
			body LONGTEXT NOT NULL,
			is_default TINYINT(1) NOT NULL DEFAULT 0,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY idx_channel (channel),
			KEY idx_is_active (is_active)
		) {$charset_collate};" );

		// ── Campaigns table ───────────────────────────────────────────────────
		dbDelta( "CREATE TABLE {$campaigns} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(190) NOT NULL,
			channel VARCHAR(30) NOT NULL DEFAULT 'sms',
			status VARCHAR(30) NOT NULL DEFAULT 'draft',
			study_year VARCHAR(20) NULL,
			template_id BIGINT UNSIGNED NULL,
			template_name_snapshot VARCHAR(190) NULL,
			template_body_snapshot LONGTEXT NULL,
			filters_json LONGTEXT NULL,
			recipient_policy VARCHAR(50) NOT NULL DEFAULT 'father_first',
			min_balance DECIMAL(12,3) NULL,
			exclude_credit_balances TINYINT(1) NOT NULL DEFAULT 1,
			exclude_zero_balances TINYINT(1) NOT NULL DEFAULT 1,
			total_candidates INT UNSIGNED NOT NULL DEFAULT 0,
			total_included INT UNSIGNED NOT NULL DEFAULT 0,
			total_excluded INT UNSIGNED NOT NULL DEFAULT 0,
			total_prepared INT UNSIGNED NOT NULL DEFAULT 0,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			prepared_at DATETIME NULL,
			cancelled_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY idx_status (status),
			KEY idx_channel (channel),
			KEY idx_study_year (study_year),
			KEY idx_created_at (created_at)
		) {$charset_collate};" );

		// ── Campaign Recipients table ─────────────────────────────────────────
		dbDelta( "CREATE TABLE {$recipients} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			campaign_id BIGINT UNSIGNED NOT NULL,
			family_id BIGINT UNSIGNED NOT NULL,
			oracle_family_id VARCHAR(50) NULL,
			recipient_type VARCHAR(30) NOT NULL,
			recipient_name VARCHAR(190) NULL,
			phone_raw VARCHAR(50) NULL,
			phone_e164 VARCHAR(30) NULL,
			sponsor_name VARCHAR(190) NULL,
			father_name VARCHAR(190) NULL,
			father_mobile VARCHAR(50) NULL,
			mother_name VARCHAR(190) NULL,
			mother_mobile VARCHAR(50) NULL,
			students_json LONGTEXT NULL,
			student_rows_json LONGTEXT NULL,
			balance DECIMAL(12,3) NULL,
			monthly_due DECIMAL(12,3) NULL,
			monthly_due_source VARCHAR(50) NULL,
			currency VARCHAR(10) NOT NULL DEFAULT 'JOD',
			financial_available TINYINT(1) NOT NULL DEFAULT 0,
			included TINYINT(1) NOT NULL DEFAULT 1,
			excluded_reason VARCHAR(190) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_campaign_id (campaign_id),
			KEY idx_family_id (family_id),
			KEY idx_phone_e164 (phone_e164),
			KEY idx_included (included)
		) {$charset_collate};" );

		// ── Prepared Queue table ──────────────────────────────────────────────
		dbDelta( "CREATE TABLE {$queue} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			campaign_id BIGINT UNSIGNED NOT NULL,
			campaign_recipient_id BIGINT UNSIGNED NOT NULL,
			family_id BIGINT UNSIGNED NOT NULL,
			channel VARCHAR(30) NOT NULL DEFAULT 'sms',
			phone_e164 VARCHAR(30) NULL,
			message_body_preview LONGTEXT NULL,
			message_body_hash VARCHAR(128) NULL,
			message_char_count INT UNSIGNED NULL,
			message_sms_parts INT UNSIGNED NULL,
			requires_payment_link TINYINT(1) NOT NULL DEFAULT 1,
			payment_token_id BIGINT UNSIGNED NULL,
			status VARCHAR(30) NOT NULL DEFAULT 'prepared',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			cancelled_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY idx_campaign_id (campaign_id),
			KEY idx_campaign_recipient_id (campaign_recipient_id),
			KEY idx_family_id (family_id),
			KEY idx_status (status),
			KEY idx_phone_e164 (phone_e164)
		) {$charset_collate};" );
	}

	/**
	 * Return the list of tables this plugin owns.
	 *
	 * @return string[]
	 */
	public static function required_tables() {
		global $wpdb;

		return array(
			$wpdb->prefix . 'olama_msg_tokens',
			$wpdb->prefix . 'olama_msg_token_views',
			$wpdb->prefix . 'olama_msg_templates',
			$wpdb->prefix . 'olama_msg_campaigns',
			$wpdb->prefix . 'olama_msg_campaign_recipients',
			$wpdb->prefix . 'olama_msg_queue',
		);
	}
}
