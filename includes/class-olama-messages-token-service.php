<?php
/**
 * Token service — generates, validates, revokes, and logs payment-report tokens.
 *
 * Security design:
 *  - Raw token: 32 bytes of CSPRNG output, hex-encoded (64 chars).
 *  - URL token: "{8-char-prefix}.{raw_token}" — prefix for DB lookup only.
 *  - Stored:    wp_hash_password( $raw_hex )  — one-way, salted, never reversed.
 *  - token_prefix: first 8 chars of the raw hex (non-secret, for lookup only).
 *  - Raw token shown only once (returned from generate_token).
 *
 * @package Olama_Messages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Olama_Messages_Token_Service {

	/** Table names (resolved in constructor). */
	private $tokens_table;
	private $views_table;
	private $tokens_table_columns = null;

	public function __construct() {
		global $wpdb;
		$this->tokens_table = $wpdb->prefix . 'olama_msg_tokens';
		$this->views_table  = $wpdb->prefix . 'olama_msg_token_views';
	}

	private function has_column( $column_name ) {
		global $wpdb;
		if ( null === $this->tokens_table_columns ) {
			$this->tokens_table_columns = array();
			$rows = $wpdb->get_results( 'SHOW COLUMNS FROM `' . esc_sql( $this->tokens_table ) . '`', ARRAY_A );
			if ( is_array( $rows ) ) {
				foreach ( $rows as $row ) {
					if ( isset( $row['Field'] ) ) {
						$this->tokens_table_columns[ $row['Field'] ] = true;
					}
				}
			}
		}
		return ! empty( $this->tokens_table_columns[ $column_name ] );
	}

	// ─── Generate ────────────────────────────────────────────────────────────

	/**
	 * Generate a new secure payment-report token for a family.
	 *
	 * @param  int|string $family_id   oracle_family_id cast to int
	 * @param  string     $study_year
	 * @param  array      $options {
	 *     @type int|null $expiry_days   Days until expiry (null = never).
	 *     @type int|null $max_views     Max allowed views (null = unlimited).
	 *     @type int|null $campaign_id   Campaign ID, if campaign generated.
	 *     @type string   $generated_source campaign|manual
	 * }
	 * @return array { token_id: int, public_url: string, raw_token: string }
	 * @throws RuntimeException on generation failure.
	 */
	public function generate_token( $family_id, $study_year = '', array $options = array() ) {
		global $wpdb;

		$family_id_int = absint( $family_id );
		if ( ! $family_id_int ) {
			throw new RuntimeException( 'Invalid family_id.' );
		}

		// Generate a collision-free token prefix (max 5 attempts).
		// Prefix collisions are extremely rare but we check anyway for correctness.
		$raw_token    = '';
		$token_prefix = '';
		$token_hash   = '';
		$max_attempts = 5;

		for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
			$raw_bytes = function_exists( 'random_bytes' ) ? random_bytes( 32 ) : openssl_random_pseudo_bytes( 32 );
			if ( false === $raw_bytes ) {
				throw new RuntimeException( 'Could not generate secure random bytes.' );
			}

			$raw_token    = bin2hex( $raw_bytes );          // 64-char hex string
			$token_prefix = substr( $raw_token, 0, 8 );    // first 8 chars for lookup

			// Check that this prefix is not already in use.
			$existing_prefix = $wpdb->get_var( $wpdb->prepare(
				'SELECT id FROM `' . esc_sql( $this->tokens_table ) . '` WHERE token_prefix = %s LIMIT 1',
				$token_prefix
			) );

			if ( ! $existing_prefix ) {
				break; // Unique prefix found.
			}

			if ( $attempt === $max_attempts ) {
				throw new RuntimeException( 'Could not generate a unique token prefix after ' . $max_attempts . ' attempts. Please try again.' );
			}
		}

		$token_hash = wp_hash_password( $raw_token ); // one-way salted hash

		// Expiry — use WordPress site timezone (current_time) consistently.
		$expires_at  = null;
		$expiry_days = isset( $options['expiry_days'] ) ? absint( $options['expiry_days'] ) : (int) get_option( 'olama_msg_token_expiry_days', 30 );
		if ( $expiry_days > 0 ) {
			$expires_at = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + $expiry_days * DAY_IN_SECONDS );
		}

		$max_views = isset( $options['max_views'] ) ? ( $options['max_views'] === null ? null : absint( $options['max_views'] ) ) : null;
		$campaign_id = isset( $options['campaign_id'] ) ? absint( $options['campaign_id'] ) : null;
		$generated_source = isset( $options['generated_source'] ) && 'manual' === $options['generated_source'] ? 'manual' : 'campaign';

		$now = current_time( 'mysql' );

		$insert_data = array(
			'family_id'    => $family_id_int,
			'token_hash'   => $token_hash,
			'token_prefix' => $token_prefix,
			'purpose'      => 'payment_report',
			'study_year'   => sanitize_text_field( $study_year ),
			'expires_at'   => $expires_at,
			'max_views'    => $max_views,
			'view_count'   => 0,
			'created_by'   => get_current_user_id() ?: null,
			'created_at'   => $now,
			'updated_at'   => $now,
		);
		$insert_format = array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' );
		if ( $this->has_column( 'campaign_id' ) ) {
			$insert_data['campaign_id'] = $campaign_id ?: null;
			$insert_format[] = '%d';
		}
		if ( $this->has_column( 'generated_source' ) ) {
			$insert_data['generated_source'] = $generated_source;
			$insert_format[] = '%s';
		}

		$inserted = $wpdb->insert( $this->tokens_table, $insert_data, $insert_format );

		if ( ! $inserted ) {
			throw new RuntimeException( 'Failed to save token to database.' );
		}

		$token_id = (int) $wpdb->insert_id;

		// Phase 1 policy: one active token per family+study_year+purpose.
		// Safe flow: Now that the insert has succeeded, revoke any OTHER existing active tokens for this combination.
		$this->revoke_active_tokens_for_family_except( $family_id_int, $token_id, sanitize_text_field( $study_year ) );

		// Build the public URL: /{prefix}.{raw_token}
		$url_token  = $token_prefix . '.' . $raw_token;
		$public_url = home_url( '/olama-payment-report/' . $url_token );

		return array(
			'token_id'   => $token_id,
			'public_url' => $public_url,
			'raw_token'  => $raw_token, // ← Shown only once; never stored.
		);
	}

	// ─── Validate ────────────────────────────────────────────────────────────

	/**
	 * Validate a URL token string and return the token row if valid.
	 *
	 * @param  string $url_token  "{prefix}.{raw_hex}"
	 * @return array|false  Token row on success, false on any validation failure.
	 */
	public function validate_token( $url_token ) {
		global $wpdb;

		$url_token = sanitize_text_field( trim( $url_token ) );

		// Split prefix and raw hex.
		$dot_pos = strpos( $url_token, '.' );
		if ( false === $dot_pos ) {
			return false;
		}
		$prefix  = substr( $url_token, 0, $dot_pos );
		$raw_hex = substr( $url_token, $dot_pos + 1 );

		// Basic format validation.
		if ( ! preg_match( '/^[a-f0-9]{8}$/', $prefix ) || ! preg_match( '/^[a-f0-9]{64}$/', $raw_hex ) ) {
			return false;
		}

		// Look up by prefix.
		$token = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM `' . esc_sql( $this->tokens_table ) . '`
				 WHERE token_prefix = %s AND purpose = %s
				 LIMIT 1',
				$prefix,
				'payment_report'
			),
			ARRAY_A
		);

		if ( ! $token ) {
			return false;
		}

		// Verify hash (timing-safe via wp_check_password).
		if ( ! wp_check_password( $raw_hex, $token['token_hash'] ) ) {
			return false;
		}

		// Check revoked.
		if ( ! empty( $token['revoked_at'] ) ) {
			return false;
		}

		// Check expiry (timezone-safe comparison using local MySQL time).
		if ( ! empty( $token['expires_at'] ) && $token['expires_at'] < current_time( 'mysql' ) ) {
			return false;
		}

		// Check max views.
		if ( ! empty( $token['max_views'] ) && (int) $token['view_count'] >= (int) $token['max_views'] ) {
			return false;
		}

		return $token;
	}

	// ─── Log view ────────────────────────────────────────────────────────────

	/**
	 * Increment view count and log a view record.
	 *
	 * @param int $token_id
	 * @param int $family_id
	 */
	public function log_view( $token_id, $family_id ) {
		global $wpdb;

		$token_id  = absint( $token_id );
		$family_id = absint( $family_id );
		$now       = current_time( 'mysql' );

		// IP and UA are hashed, never stored raw.
		$ip_hash = hash( 'sha256', sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ) . NONCE_SALT );
		$ua_hash = hash( 'sha256', sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' ) . NONCE_SALT );

		// Log the view.
		$wpdb->insert(
			$this->views_table,
			array(
				'token_id'        => $token_id,
				'family_id'       => $family_id,
				'viewed_at'       => $now,
				'ip_hash'         => $ip_hash,
				'user_agent_hash' => $ua_hash,
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);

		// Increment token view count and update last_viewed_at.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE `' . esc_sql( $this->tokens_table ) . '`
				 SET view_count = view_count + 1, last_viewed_at = %s, updated_at = %s
				 WHERE id = %d',
				$now,
				$now,
				$token_id
			)
		);
	}

	// ─── Revoke ──────────────────────────────────────────────────────────────

	/**
	 * Revoke a token by ID.
	 *
	 * @param  int $token_id
	 * @return bool
	 */
	public function revoke_token( $token_id ) {
		global $wpdb;

		$now = current_time( 'mysql' );
		$result = $wpdb->update(
			$this->tokens_table,
			array(
				'revoked_at' => $now,
				'updated_at' => $now,
			),
			array( 'id' => absint( $token_id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		if ( false !== $result && class_exists( 'Olama_Messages_Short_Link_Service' ) ) {
			Olama_Messages_Plugin::instance()->short_links()->revoke_for_token( absint( $token_id ) );
		}

		return false !== $result;
	}

	public function revoke_all_tokens() {
		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE `' . esc_sql( $this->tokens_table ) . '`
				 SET revoked_at = %s, updated_at = %s
				 WHERE purpose = %s AND revoked_at IS NULL',
				$now,
				$now,
				'payment_report'
			)
		);
		return (int) $wpdb->rows_affected;
	}

	public function delete_token( $token_id ) {
		global $wpdb;
		$token_id = absint( $token_id );
		if ( ! $token_id ) {
			return false;
		}
		$wpdb->delete( $this->views_table, array( 'token_id' => $token_id ), array( '%d' ) );
		if ( class_exists( 'Olama_Messages_Short_Link_Service' ) ) {
			$wpdb->delete( $wpdb->prefix . 'olama_msg_short_links', array( 'token_id' => $token_id ), array( '%d' ) );
		}
		return false !== $wpdb->delete( $this->tokens_table, array( 'id' => $token_id ), array( '%d' ) );
	}

	public function delete_all_tokens() {
		global $wpdb;
		$wpdb->query( "DELETE FROM `" . esc_sql( $this->views_table ) . "` WHERE token_id IN (SELECT id FROM `" . esc_sql( $this->tokens_table ) . "`)" );
		$wpdb->query( "DELETE FROM `" . esc_sql( $this->tokens_table ) . "` WHERE purpose = 'payment_report'" );
		return (int) $wpdb->rows_affected;
	}

	/**
	 * Revoke all currently active tokens for a given family + study_year + purpose.
	 *
	 * Phase 1 policy: one active token per family/study_year/purpose.
	 * Called automatically by generate_token() before inserting a new token.
	 *
	 * @param  int    $family_id_int
	 * @param  string $study_year
	 * @param  string $purpose       Defaults to 'payment_report'.
	 * @return int Number of tokens revoked.
	 */
	/**
	 * Revoke all currently active tokens for a given family + study_year + purpose,
	 * EXCEPT a specific newly created token ID.
	 *
	 * Safe flow: Called after successfully inserting a new token.
	 *
	 * @param  int    $family_id_int
	 * @param  int    $except_token_id
	 * @param  string $study_year
	 * @param  string $purpose          Defaults to 'payment_report'.
	 * @return int Number of tokens revoked.
	 */
	public function revoke_active_tokens_for_family_except( $family_id_int, $except_token_id, $study_year = '', $purpose = 'payment_report' ) {
		global $wpdb;
		$now = current_time( 'mysql' );

		// Build the WHERE: family_id + not this token + purpose + not yet revoked.
		$where  = 'family_id = %d AND id != %d AND purpose = %s AND revoked_at IS NULL';
		$values = array( absint( $family_id_int ), absint( $except_token_id ), $purpose );

		// If study_year provided, scope to that year only.
		if ( $study_year !== '' ) {
			$where   .= ' AND study_year = %s';
			$values[] = $study_year;
		}

		$sql = $wpdb->prepare(
			'UPDATE `' . esc_sql( $this->tokens_table ) . '`
			 SET revoked_at = %s, updated_at = %s
			 WHERE ' . $where,
			array_merge( array( $now, $now ), $values )
		);

		$wpdb->query( $sql );
		return (int) $wpdb->rows_affected;
	}


	// ─── Queries ─────────────────────────────────────────────────────────────

	/**
	 * Return a paginated list of tokens.
	 *
	 * @param  array $args { limit, offset, family_id }
	 * @return array[]
	 */
	public function get_tokens_list( array $args = array() ) {
		global $wpdb;

		$limit  = isset( $args['limit'] )  ? max( 1, min( 200, absint( $args['limit'] ) ) ) : 50;
		$offset = isset( $args['offset'] ) ? max( 0, absint( $args['offset'] ) )             : 0;
		$where  = array( 't.purpose = %s' );
		$values = array( 'payment_report' );
		$join    = " LEFT JOIN {$wpdb->prefix}olama_msg_campaigns c ON c.id = t.campaign_id";
		$order   = 't.created_at DESC';

		if ( ! empty( $args['campaign_id'] ) ) {
			$where[]  = 't.campaign_id = %d';
			$values[] = absint( $args['campaign_id'] );
		}
		if ( ! empty( $args['family_id'] ) ) {
			$where[]  = 't.family_id = %d';
			$values[] = absint( $args['family_id'] );
		}
		if ( ! empty( $args['generated_source'] ) ) {
			$where[]  = 't.generated_source = %s';
			$values[] = sanitize_text_field( $args['generated_source'] );
		}
		if ( ! empty( $args['viewed_only'] ) ) {
			$where[] = 't.view_count > 0';
		}
		if ( ! empty( $args['not_viewed_only'] ) ) {
			$where[] = '(t.view_count = 0 OR t.view_count IS NULL)';
		}

		if ( ! empty( $args['campaign_name'] ) ) {
			$where[] = 'c.title LIKE %s';
			$values[] = '%' . $wpdb->esc_like( $args['campaign_name'] ) . '%';
		}

		$sql = "SELECT t.*, c.title AS campaign_title
		        FROM `" . esc_sql( $this->tokens_table ) . "` t
		        {$join}
		        WHERE " . implode( ' AND ', $where ) . "
		        ORDER BY {$order} LIMIT %d OFFSET %d";
		$values[] = $limit;
		$values[] = $offset;
		return $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A ) ?: array();
	}

	/**
	 * @param  int $id
	 * @return array|null
	 */
	public function get_token_by_id( $id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM `' . esc_sql( $this->tokens_table ) . '` WHERE id = %d LIMIT 1',
				absint( $id )
			),
			ARRAY_A
		);
	}

	/**
	 * @return int
	 */
	public function count_tokens( array $args = array() ) {
		global $wpdb;
		$where  = array( "purpose = 'payment_report'" );
		$values = array();
		if ( ! empty( $args['campaign_id'] ) ) {
			$where[]  = 'campaign_id = %d';
			$values[] = absint( $args['campaign_id'] );
		}
		if ( ! empty( $args['generated_source'] ) ) {
			$where[]  = 'generated_source = %s';
			$values[] = sanitize_text_field( $args['generated_source'] );
		}
		if ( ! empty( $args['viewed_only'] ) ) {
			$where[] = 'view_count > 0';
		}
		if ( ! empty( $args['not_viewed_only'] ) ) {
			$where[] = '(view_count = 0 OR view_count IS NULL)';
		}
		$sql = 'SELECT COUNT(*) FROM `' . esc_sql( $this->tokens_table ) . '` WHERE ' . implode( ' AND ', $where );
		if ( $values ) {
			$sql = $wpdb->prepare( $sql, $values );
		}
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * @return int
	 */
	public function count_views() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . esc_sql( $this->views_table ) . '`' );
	}

	/**
	 * @return array|null
	 */
	public function get_last_token_created() {
		global $wpdb;

		return $wpdb->get_row(
			'SELECT * FROM `' . esc_sql( $this->tokens_table ) . '` ORDER BY created_at DESC LIMIT 1',
			ARRAY_A
		);
	}

	/**
	 * Return the public URL for a given token row.
	 *
	 * NOTE: The raw hex is NOT stored. This reconstructs a non-usable preview URL.
	 * A real copy-link requires the raw token shown at generation time.
	 *
	 * @param  array $token  Token row from DB.
	 * @return string        Admin-facing token info URL (shows prefix only).
	 */
	public function get_report_url_preview( array $token ) {
		return home_url( '/olama-payment-report/' . esc_attr( $token['token_prefix'] ) . '.{raw_token}' );
	}

	/**
	 * Determine the human-readable status of a token.
	 *
	 * @param  array $token
	 * @return string  'active' | 'revoked' | 'expired' | 'maxed'
	 */
	public function get_token_status( array $token ) {
		if ( ! empty( $token['revoked_at'] ) ) {
			return 'revoked';
		}
		if ( ! empty( $token['expires_at'] ) && $token['expires_at'] < current_time( 'mysql' ) ) {
			return 'expired';
		}
		if ( ! empty( $token['max_views'] ) && (int) $token['view_count'] >= (int) $token['max_views'] ) {
			return 'maxed';
		}
		return 'active';
	}
}
