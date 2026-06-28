<?php
/** First-party, one-way-hashed short payment links. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Olama_Messages_Short_Link_Service {
	private $table_short_links;
	private $table_tokens;

	public function __construct() {
		global $wpdb;
		$this->table_short_links = $wpdb->prefix . 'olama_msg_short_links';
		$this->table_tokens      = $wpdb->prefix . 'olama_msg_tokens';
	}

	/** Create a new 8-character base62 code and return it once. */
	public function create_for_token( $token_id, $family_id, $study_year = '', $expires_at = null, $generated_source = 'campaign' ) {
		global $wpdb;
		$token_id = absint( $token_id );
		$family_id = absint( $family_id );
		$token = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$this->table_tokens} WHERE id = %d AND family_id = %d LIMIT 1",
			$token_id,
			$family_id
		), ARRAY_A );
		if ( ! $token || ! empty( $token['revoked_at'] ) || ( ! empty( $token['expires_at'] ) && $token['expires_at'] < current_time( 'mysql' ) ) ) {
			throw new RuntimeException( 'Cannot create a short link for an invalid or expired payment token.' );
		}

		$alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
		$now = current_time( 'mysql' );
		for ( $attempt = 0; $attempt < 10; $attempt++ ) {
			$raw_code = '';
			for ( $i = 0; $i < 8; $i++ ) {
				$raw_code .= $alphabet[ random_int( 0, 61 ) ];
			}
			$prefix = substr( $raw_code, 0, 6 );
			$hash   = hash_hmac( 'sha256', $raw_code, AUTH_SALT );
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$this->table_short_links} WHERE short_code_hash = %s LIMIT 1",
				$hash
			) );
			if ( $exists ) continue;

			$inserted = $wpdb->insert( $this->table_short_links, array(
				'token_id'         => $token_id,
				'family_id'        => $family_id,
				'study_year'       => sanitize_text_field( $study_year ),
				'short_code_hash'  => $hash,
				'short_code_prefix'=> $prefix,
				'purpose'          => 'payment_report',
				'generated_source' => ( 'manual' === $generated_source ) ? 'manual' : 'campaign',
				'expires_at'       => $expires_at ? sanitize_text_field( $expires_at ) : $token['expires_at'],
				'use_count'        => 0,
				'created_at'       => $now,
				'updated_at'       => $now,
			) );
			if ( $inserted ) {
				return array(
					'id'         => (int) $wpdb->insert_id,
					'raw_code'   => $raw_code,
					'short_url'  => home_url( '/p/' . $raw_code ),
					'token_id'   => $token_id,
					'family_id'  => $family_id,
				);
			}
		}
		throw new RuntimeException( 'Failed to create a unique short payment link.' );
	}

	/** Resolve and account for a short code, returning the active original token. */
	public function resolve_short_code( $raw_short_code ) {
		global $wpdb;
		$raw_short_code = trim( (string) $raw_short_code );
		if ( ! preg_match( '/^[A-Za-z0-9]{8}$/', $raw_short_code ) ) return false;
		$prefix = substr( $raw_short_code, 0, 6 );
		$expected_hash = hash_hmac( 'sha256', $raw_short_code, AUTH_SALT );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.*, t.token_hash, t.token_prefix, t.max_views, t.view_count,
			        t.expires_at AS token_expires_at, t.revoked_at AS token_revoked_at
			 FROM {$this->table_short_links} s
			 JOIN {$this->table_tokens} t ON t.id = s.token_id
			 WHERE s.short_code_prefix = %s AND s.purpose = 'payment_report'",
			$prefix
		), ARRAY_A );
		$now = current_time( 'mysql' );
		foreach ( $rows as $row ) {
			if ( ! hash_equals( $row['short_code_hash'], $expected_hash ) ) continue;
			if ( ! empty( $row['revoked_at'] ) || ! empty( $row['token_revoked_at'] ) ) return false;
			if ( ( ! empty( $row['expires_at'] ) && $row['expires_at'] < $now ) || ( ! empty( $row['token_expires_at'] ) && $row['token_expires_at'] < $now ) ) return false;
			if ( ! empty( $row['max_views'] ) && (int) $row['view_count'] >= (int) $row['max_views'] ) return false;
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$this->table_short_links} SET use_count = use_count + 1, last_used_at = %s, updated_at = %s WHERE id = %d",
				$now, $now, (int) $row['id']
			) );
			$row['id']         = (int) $row['token_id'];
			$row['expires_at'] = $row['token_expires_at'];
			$row['revoked_at'] = $row['token_revoked_at'];
			return $row;
		}
		return false;
	}

	public function revoke_for_token( $token_id ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		return false !== $wpdb->update(
			$this->table_short_links,
			array( 'revoked_at' => $now, 'updated_at' => $now ),
			array( 'token_id' => absint( $token_id ), 'revoked_at' => null )
		);
	}

	/** Raw codes are never stored, so each delivery receives a fresh short code. */
	public function get_or_create_for_family_token( $token_id, $family_id, $study_year = '' ) {
		return $this->create_for_token( $token_id, $family_id, $study_year, null, 'campaign' );
	}

	public function revoke_all_short_links() {
		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE `' . esc_sql( $this->table_short_links ) . '`
				 SET revoked_at = %s, updated_at = %s
				 WHERE purpose = %s AND revoked_at IS NULL',
				$now,
				$now,
				'payment_report'
			)
		);
		return (int) $wpdb->rows_affected;
	}

	public function delete_all_short_links() {
		global $wpdb;
		$wpdb->query( "DELETE FROM `" . esc_sql( $this->table_short_links ) . "` WHERE purpose = 'payment_report'" );
		return (int) $wpdb->rows_affected;
	}
}
