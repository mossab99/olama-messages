<?php
/**
 * Olama Messages Template Service.
 *
 * Handles reusable templates, default templates, and placeholder validation.
 *
 * @package Olama_Messages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Olama_Messages_Template_Service {

	/** Default template body constant. */
	const DEFAULT_SMS_BODY = "ولي الأمر المحترم {sponsor_name}،\nنذكركم بوجود رصيد مستحق بقيمة {balance} د.أ.\nيمكنكم مراجعة تفاصيل المطالبة من الرابط التالي:\n{payment_link}\nأكاديمية علماء المستقبل";

	/** Default template name constant. */
	const DEFAULT_SMS_NAME = 'تذكير مستحقات الرصيد الافتراضي';

	/** Allowed placeholders list. */
	const ALLOWED_PLACEHOLDERS = array(
		'{sponsor_name}',
		'{family_id}',
		'{students}',
		'{balance}',
		'{monthly_due}',
		'{monthly_due_source}',
		'{payment_link}',
		'{study_year}',
		'{school_name}',
		'{contact_phone}',
	);

	/**
	 * Insert the default Arabic SMS debt reminder template if no active SMS templates exist.
	 *
	 * @return void
	 */
	public function ensure_default_templates() {
		global $wpdb;
		$table = $wpdb->prefix . 'olama_msg_templates';

		// Check if the table exists first (defensive check).
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}

		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM `{$table}` WHERE channel = %s AND is_active = 1",
			'sms'
		) );

		if ( $count === 0 ) {
			$wpdb->insert(
				$table,
				array(
					'name'       => self::DEFAULT_SMS_NAME,
					'channel'    => 'sms',
					'body'       => self::DEFAULT_SMS_BODY,
					'is_default' => 1,
					'is_active'  => 1,
					'created_at' => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%d', '%d', '%s' )
			);
		}
	}

	/**
	 * Create a new template.
	 *
	 * @param  array $data Template data.
	 * @return int The created template ID, or 0 on failure.
	 */
	public function create_template( array $data ) {
		global $wpdb;
		$table = $wpdb->prefix . 'olama_msg_templates';

		$name       = sanitize_text_field( $data['name'] ?? '' );
		$channel    = sanitize_text_field( $data['channel'] ?? 'sms' );
		$body       = sanitize_textarea_field( $data['body'] ?? '' );
		$is_default = ! empty( $data['is_default'] ) ? 1 : 0;
		$is_active  = isset( $data['is_active'] ) ? ( ! empty( $data['is_active'] ) ? 1 : 0 ) : 1;

		if ( empty( $name ) || empty( $body ) ) {
			return 0;
		}

		// If this is set to default, unset other defaults in the same channel.
		if ( $is_default ) {
			$wpdb->update(
				$table,
				array( 'is_default' => 0 ),
				array( 'channel' => $channel ),
				array( '%d' ),
				array( '%s' )
			);
		}

		$inserted = $wpdb->insert(
			$table,
			array(
				'name'       => $name,
				'channel'    => $channel,
				'body'       => $body,
				'is_default' => $is_default,
				'is_active'  => $is_active,
				'created_by' => get_current_user_id(),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%d', '%d', '%d', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update an existing template.
	 *
	 * @param  int   $template_id Template ID.
	 * @param  array $data        Template data to update.
	 * @return bool True on success, false on failure.
	 */
	public function update_template( int $template_id, array $data ) {
		global $wpdb;
		$table = $wpdb->prefix . 'olama_msg_templates';

		$update_fields = array();
		$formats       = array();

		if ( isset( $data['name'] ) ) {
			$update_fields['name'] = sanitize_text_field( $data['name'] );
			$formats[]             = '%s';
		}
		if ( isset( $data['body'] ) ) {
			$update_fields['body'] = sanitize_textarea_field( $data['body'] );
			$formats[]             = '%s';
		}
		if ( isset( $data['is_default'] ) ) {
			$is_default = ! empty( $data['is_default'] ) ? 1 : 0;
			$update_fields['is_default'] = $is_default;
			$formats[]                   = '%d';

			if ( $is_default ) {
				// Get channel first.
				$channel = $data['channel'] ?? $wpdb->get_var( $wpdb->prepare(
					"SELECT channel FROM `{$table}` WHERE id = %d",
					$template_id
				) );
				if ( $channel ) {
					$wpdb->update(
						$table,
						array( 'is_default' => 0 ),
						array( 'channel' => $channel ),
						array( '%d' ),
						array( '%s' )
					);
				}
			}
		}
		if ( isset( $data['is_active'] ) ) {
			$update_fields['is_active'] = ! empty( $data['is_active'] ) ? 1 : 0;
			$formats[]                  = '%d';
		}

		if ( empty( $update_fields ) ) {
			return false;
		}

		$update_fields['updated_at'] = current_time( 'mysql' );
		$formats[]                   = '%s';

		$updated = $wpdb->update(
			$table,
			$update_fields,
			array( 'id' => $template_id ),
			$formats,
			array( '%d' )
		);

		return $updated !== false;
	}

	/**
	 * Load a single template by ID.
	 *
	 * @param  int $template_id
	 * @return array|null
	 */
	public function get_template( int $template_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'olama_msg_templates';

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM `{$table}` WHERE id = %d",
			$template_id
		), ARRAY_A );

		return $row ?: null;
	}

	/**
	 * List templates with optional filters.
	 *
	 * @param  array $args Filters (channel, is_active, limit, offset).
	 * @return array
	 */
	public function list_templates( array $args = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . 'olama_msg_templates';

		$where_clauses = array();
		$where_values  = array();

		if ( isset( $args['channel'] ) ) {
			$where_clauses[] = 'channel = %s';
			$where_values[]  = sanitize_text_field( $args['channel'] );
		}
		if ( isset( $args['is_active'] ) ) {
			$where_clauses[] = 'is_active = %d';
			$where_values[]  = ! empty( $args['is_active'] ) ? 1 : 0;
		}

		$where_sql = $where_clauses ? 'WHERE ' . implode( ' AND ', $where_clauses ) : '';

		$limit  = isset( $args['limit'] ) ? absint( $args['limit'] ) : 100;
		$offset = isset( $args['offset'] ) ? absint( $args['offset'] ) : 0;

		$query = "SELECT * FROM `{$table}` {$where_sql} ORDER BY is_default DESC, id DESC LIMIT %d OFFSET %d";
		$query = $wpdb->prepare( $query, array_merge( $where_values, array( $limit, $offset ) ) );

		$rows = $wpdb->get_results( $query, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Validate placeholders in a template body.
	 *
	 * Identifies placeholders matching {variable} and flags any that are not in the allowed list.
	 *
	 * @param  string $body The template body text.
	 * @return array {
	 *     valid:    bool (always true),
	 *     warnings: string[],
	 * }
	 */
	public function validate_template_body( string $body ) {
		preg_match_all( '/\{[A-Za-z0-9_]+\}/', $body, $matches );
		$placeholders = isset( $matches[0] ) ? array_unique( $matches[0] ) : array();

		$warnings = array();
		foreach ( $placeholders as $ph ) {
			if ( ! in_array( $ph, self::ALLOWED_PLACEHOLDERS, true ) ) {
				$warnings[] = sprintf(
					/* translators: %s: placeholder name */
					__( 'Unknown placeholder %s will not be replaced.', 'olama-messages' ),
					'<code>' . esc_html( $ph ) . '</code>'
				);
			}
		}

		return array(
			'valid'    => true,
			'warnings' => $warnings,
		);
	}
}
