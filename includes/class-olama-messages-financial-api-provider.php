<?php
/**
 * Olama Messages Financial API Provider adapter.
 *
 * Connects read-only financial data through the Oracle Flask API bridge (D:\api).
 *
 * @package Olama_Messages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Olama_Messages_Financial_Api_Provider {

	/** Transient key for health status caching. */
	const HEALTH_TRANSIENT = 'olama_msg_fin_health_check';

	/**
	 * Retrieve API connection configuration.
	 *
	 * Checks dedicated olama-messages settings first.
	 * Falls back to olama-oracle-sync settings if base URL or key is empty.
	 *
	 * @return array {
	 *     enabled: bool,
	 *     base_url: string,
	 *     api_key: string,
	 *     timeout: int,
	 *     configured: bool
	 * }
	 */
	public static function get_config() {
		$enabled  = get_option( 'olama_msg_financial_enabled', 'yes' ) === 'yes';
		$base_url = untrailingslashit( trim( get_option( 'olama_msg_oracle_base_url', '' ) ) );
		$api_key  = get_option( 'olama_msg_oracle_api_key', '' );
		$timeout  = absint( get_option( 'olama_msg_api_timeout', 15 ) );
		if ( ! $timeout ) {
			$timeout = 15;
		}

		// Fallback to olama-oracle-sync configuration if needed.
		if ( empty( $base_url ) || empty( $api_key ) ) {
			$sync = get_option( 'olama_oracle_sync_settings', array() );
			if ( is_array( $sync ) ) {
				if ( empty( $base_url ) && ! empty( $sync['base_url'] ) ) {
					$base_url = untrailingslashit( trim( $sync['base_url'] ) );
				}
				if ( empty( $api_key ) && ! empty( $sync['api_key'] ) ) {
					$api_key = trim( $sync['api_key'] );
				}
			}
		}

		return array(
			'enabled'    => $enabled,
			'base_url'   => $base_url,
			'api_key'    => $api_key,
			'timeout'    => $timeout,
			'configured' => ! empty( $base_url ) && ! empty( $api_key ),
		);
	}

	/**
	 * Is the financial API provider active and reachable?
	 *
	 * Caches health check result in a transient for 3 minutes.
	 *
	 * @param bool $force Bypass transient cache.
	 * @return bool
	 */
	public function is_available( $force = false ) {
		$cfg = self::get_config();
		if ( ! $cfg['enabled'] || ! $cfg['configured'] ) {
			return false;
		}

		if ( ! $force ) {
			$cached = get_transient( self::HEALTH_TRANSIENT );
			if ( $cached === 'reachable' ) {
				return true;
			} elseif ( $cached === 'unreachable' ) {
				return false;
			}
		}

		// Perform fast probe.
		$url      = $cfg['base_url'] . '/api/messaging/recipients?limit=1';
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => min( 5, $cfg['timeout'] ),
				'headers' => array( 'X-API-Key' => $cfg['api_key'] ),
			)
		);

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			set_transient( self::HEALTH_TRANSIENT, 'unreachable', 60 );
			return false;
		}

		set_transient( self::HEALTH_TRANSIENT, 'reachable', 180 );
		return true;
	}

	/**
	 * Execute read-only GET request to Oracle API.
	 *
	 * @param string $endpoint Route path starting with /api/
	 * @param array  $query Query parameters.
	 * @return array|false Parsed JSON array or false on failure.
	 */
	protected function request( $endpoint, $query = array() ) {
		$cfg = self::get_config();
		if ( ! $cfg['enabled'] || ! $cfg['configured'] ) {
			return false;
		}

		$url = $cfg['base_url'] . $endpoint;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( urlencode_deep( $query ), $url );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => $cfg['timeout'],
				'headers' => array( 'X-API-Key' => $cfg['api_key'] ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		return is_array( $data ) ? $data : false;
	}

	/**
	 * Retrieve bulk financial recipients.
	 *
	 * @param string $study_year
	 * @param array  $filters
	 * @return array|false
	 */
	public function get_bulk_recipients( $study_year, $filters = array() ) {
		$query = array(
			'study_year' => $study_year,
			'limit'      => isset( $filters['limit'] ) ? absint( $filters['limit'] ) : 50,
			'offset'     => isset( $filters['offset'] ) ? absint( $filters['offset'] ) : 0,
		);

		if ( isset( $filters['min_balance'] ) && $filters['min_balance'] !== '' ) {
			$query['min_balance'] = floatval( $filters['min_balance'] );
		}
		if ( ! empty( $filters['family_id'] ) ) {
			$query['family_id'] = intval( $filters['family_id'] );
		}
		if ( ! empty( $filters['class_id'] ) ) {
			$query['class_id'] = intval( $filters['class_id'] );
		}
		if ( ! empty( $filters['section_id'] ) ) {
			$query['section_id'] = intval( $filters['section_id'] );
		}
		if ( ! empty( $filters['class_name'] ) ) {
			$query['class_name'] = sanitize_text_field( $filters['class_name'] );
		}
		if ( ! empty( $filters['section_name'] ) ) {
			$query['section_name'] = sanitize_text_field( $filters['section_name'] );
		}

		return $this->request( '/api/messaging/recipients', $query );
	}

	/**
	 * Retrieve single family financial summary.
	 *
	 * @param int    $family_id
	 * @param string $study_year
	 * @return array|false
	 */
	public function get_financial_summary( $family_id, $study_year ) {
		return $this->request(
			'/api/families/' . absint( $family_id ) . '/financial-summary',
			array( 'study_year' => $study_year )
		);
	}

	/**
	 * Retrieve single family full payment report payload.
	 *
	 * @param int    $family_id
	 * @param string $study_year
	 * @return array|false
	 */
	public function get_payment_report( $family_id, $study_year ) {
		return $this->request(
			'/api/families/' . absint( $family_id ) . '/payment-report',
			array( 'study_year' => $study_year )
		);
	}
}
