<?php
/**
 * Transportation cache / Oracle endpoint adapter.
 *
 * Caches per-family transportation rows so campaign filtering can use real
 * departure/arrival bus identifiers instead of guessing from grade/section.
 *
 * @package Olama_Messages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Olama_Messages_Transportation_Service {

	/** Health transient key. */
	const HEALTH_TRANSIENT = 'olama_msg_transport_health_check';

	/** @var string */
	private $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'olama_core_student_transportation';
	}

	public static function get_config() {
		$enabled  = get_option( 'olama_msg_financial_enabled', 'yes' ) === 'yes';
		$base_url = untrailingslashit( trim( get_option( 'olama_msg_oracle_base_url', '' ) ) );
		$api_key  = get_option( 'olama_msg_oracle_api_key', '' );
		$timeout  = absint( get_option( 'olama_msg_api_timeout', 15 ) );
		if ( ! $timeout ) {
			$timeout = 15;
		}

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

	public function is_available( $force = false ) {
		$cfg = self::get_config();
		if ( ! $cfg['enabled'] || ! $cfg['configured'] ) {
			return false;
		}

		if ( ! $force ) {
			$cached = get_transient( self::HEALTH_TRANSIENT );
			if ( 'reachable' === $cached ) {
				return true;
			}
			if ( 'unreachable' === $cached ) {
				return false;
			}
		}

		$response = wp_remote_get(
			$cfg['base_url'] . '/api/messaging/recipients?limit=1',
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

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : false;
	}

	/** Retrieve Oracle-filtered transportation family recipients. */
	public function get_bulk_recipients( $study_year, array $filters = array() ) {
		$query = array(
			'study_year' => sanitize_text_field( $study_year ),
			'limit'      => min( 200, max( 1, absint( $filters['limit'] ?? 50 ) ) ),
			'offset'     => max( 0, absint( $filters['offset'] ?? 0 ) ),
			'route_mode' => sanitize_text_field( $filters['route_mode'] ?? 'either' ),
			'active_only'=> 1,
		);
		foreach ( array( 'class_id', 'section_id', 'departure_bus', 'arrival_bus', 'family_id' ) as $field ) {
			if ( isset( $filters[ $field ] ) && '' !== (string) $filters[ $field ] ) {
				$query[ $field ] = absint( $filters[ $field ] );
			}
		}
		if ( isset( $filters['trans_route'] ) && '' !== (string) $filters['trans_route'] ) {
			$query['trans_route'] = absint( $filters['trans_route'] );
		} elseif ( isset( $filters['round_name'] ) && '' !== (string) $filters['round_name'] ) {
			$query['trans_route'] = absint( $filters['round_name'] );
		}

		return $this->request( '/api/messaging/transportation/recipients', $query );
	}

	protected function normalize_rows( $payload, $study_year, $family_id ) {
		if ( ! is_array( $payload ) ) {
			return array();
		}

		if ( isset( $payload['students'] ) && is_array( $payload['students'] ) ) {
			return $payload['students'];
		}
		if ( isset( $payload['items'] ) && is_array( $payload['items'] ) ) {
			return $payload['items'];
		}
		if ( isset( $payload['data'] ) && is_array( $payload['data'] ) ) {
			return $this->normalize_rows( $payload['data'], $study_year, $family_id );
		}
		if ( isset( $payload['transportation'] ) && is_array( $payload['transportation'] ) ) {
			return $payload['transportation'];
		}
		if ( isset( $payload['student_id'] ) || isset( $payload['departure_bus'] ) || isset( $payload['arrival_bus'] ) ) {
			return array( $payload );
		}
		return array();
	}

	public function sync_family_transportation( $family_id, $study_year, $force = false ) {
		global $wpdb;

		$family_id  = absint( $family_id );
		$study_year = sanitize_text_field( $study_year );
		if ( ! $family_id || ! $study_year ) {
			return array();
		}

		if ( ! $force ) {
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$this->table} WHERE family_id = %d AND study_year = %s",
					$family_id,
					$study_year
				)
			);
			if ( (int) $existing > 0 ) {
				return $this->get_family_rows( $family_id, $study_year );
			}
		}

		$payload = $this->request(
			'/api/families/' . $family_id . '/transportation',
			array( 'study_year' => $study_year )
		);
		if ( ! is_array( $payload ) ) {
			return array();
		}

		$rows = $this->normalize_rows( $payload, $study_year, $family_id );
		$now  = current_time( 'mysql' );

		$wpdb->delete(
			$this->table,
			array(
				'family_id'  => $family_id,
				'study_year' => $study_year,
			)
		);

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			// The endpoint can include historical/inactive assignments. Campaigns
			// must target the student's current transportation assignment only.
			if ( isset( $row['is_active'] ) && 0 === (int) $row['is_active'] ) {
				continue;
			}

			$student_id = absint( $row['student_id'] ?? 0 );
			if ( ! $student_id ) {
				continue;
			}

			$wpdb->insert(
				$this->table,
				array(
					'study_year'          => $study_year,
					'family_id'           => $family_id,
					'student_id'          => $student_id,
					'class_id'            => isset( $row['class_id'] ) ? sanitize_text_field( (string) $row['class_id'] ) : null,
					'class_name'          => isset( $row['class_name'] ) ? sanitize_text_field( (string) $row['class_name'] ) : null,
					'section_id'          => isset( $row['section_id'] ) ? sanitize_text_field( (string) $row['section_id'] ) : null,
					'section_name'        => isset( $row['section_name'] ) ? sanitize_text_field( (string) $row['section_name'] ) : null,
					'departure_bus'       => isset( $row['departure_bus'] ) ? sanitize_text_field( (string) $row['departure_bus'] ) : null,
					'departure_bus_name'  => isset( $row['departure_bus_name'] ) ? sanitize_text_field( (string) $row['departure_bus_name'] ) : null,
					'departure_bus_seq'    => isset( $row['departure_bus_seq'] ) ? sanitize_text_field( (string) $row['departure_bus_seq'] ) : null,
					'arrival_bus'         => isset( $row['arrival_bus'] ) ? sanitize_text_field( (string) $row['arrival_bus'] ) : null,
					'arrival_bus_name'    => isset( $row['arrival_bus_name'] ) ? sanitize_text_field( (string) $row['arrival_bus_name'] ) : null,
					'arrival_bus_seq'     => isset( $row['arrival_bus_seq'] ) ? sanitize_text_field( (string) $row['arrival_bus_seq'] ) : null,
					'trans_route'         => isset( $row['trans_route'] ) ? sanitize_text_field( (string) $row['trans_route'] ) : null,
					'trans_route_name'    => isset( $row['trans_route_name'] ) ? sanitize_text_field( (string) $row['trans_route_name'] ) : null,
					'synced_at'           => $now,
				),
				array(
					'%s',
					'%d',
					'%d',
					'%s',
					'%s',
					'%s',
					'%s',
					'%s',
					'%s',
					'%s',
					'%s',
					'%s',
					'%s',
					'%s',
					'%s',
					'%s',
				)
			);
		}

		return $this->get_family_rows( $family_id, $study_year );
	}

	public function get_family_rows( $family_id, $study_year ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE family_id = %d AND study_year = %s ORDER BY student_id ASC",
				absint( $family_id ),
				sanitize_text_field( $study_year )
			),
			ARRAY_A
		);
	}

	public function get_route_options( $study_year ) {
		global $wpdb;

		$study_year = sanitize_text_field( $study_year );
		if ( '' === $study_year ) {
			return array(
				'classes'   => array(),
				'sections'  => array(),
				'departure' => array(),
				'arrival'   => array(),
				'rounds'    => array(),
			);
		}

		$api_options = $this->request(
			'/api/messaging/transportation/options',
			array( 'study_year' => $study_year, 'active_only' => 1 )
		);
		if ( is_array( $api_options ) && 'ok' === ( $api_options['status'] ?? '' ) ) {
			$map_bus = static function ( $row ) {
				return array(
					'id'   => (string) ( $row['bus_id'] ?? '' ),
					'name' => (string) ( $row['bus_name'] ?? $row['bus_id'] ?? '' ),
					'seq'  => $row['bus_seq'] ?? '',
				);
			};
			return array(
				'classes'   => array_values( array_map( static function ( $row ) {
					return array( 'id' => (string) ( $row['class_id'] ?? '' ), 'name' => (string) ( $row['class_name'] ?? '' ) );
				}, $api_options['classes'] ?? array() ) ),
				'sections'  => array_values( array_map( static function ( $row ) {
					return array( 'id' => (string) ( $row['section_id'] ?? '' ), 'class_id' => (string) ( $row['class_id'] ?? '' ), 'name' => (string) ( $row['section_name'] ?? '' ) );
				}, $api_options['sections'] ?? array() ) ),
				'departure' => array_values( array_map( $map_bus, $api_options['departure_buses'] ?? array() ) ),
				'arrival'   => array_values( array_map( $map_bus, $api_options['arrival_buses'] ?? array() ) ),
				'rounds'    => array_values( array_map( static function ( $row ) {
					return array( 'id' => (string) ( $row['trans_route'] ?? '' ), 'name' => (string) ( $row['label'] ?? $row['trans_route'] ?? '' ), 'seq' => '' );
				}, $api_options['routes'] ?? array() ) ),
			);
		}

		// Do not silently fall back to stale local cache options. An empty result
		// makes API availability problems visible instead of targeting the wrong audience.
		return array(
			'classes'   => array(),
			'sections'  => array(),
			'departure' => array(),
			'arrival'   => array(),
			'rounds'    => array(),
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COUNT(*) AS total_rows
				 FROM {$this->table}
				 WHERE study_year = %s",
				$study_year
			),
			ARRAY_A
		);
		$has_cached_rows = ! empty( $rows[0]['total_rows'] );
		if ( ! $has_cached_rows ) {
			$this->warm_sync_study_year( $study_year );
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT departure_bus AS route_id, departure_bus_name AS route_name, MIN(departure_bus_seq) AS route_seq
				 FROM {$this->table}
				 WHERE study_year = %s AND departure_bus IS NOT NULL AND departure_bus <> '' AND departure_bus NOT IN ('0', '999')
				 GROUP BY departure_bus, departure_bus_name
				 ORDER BY route_seq ASC, route_name ASC",
				$study_year
			),
			ARRAY_A
		);

		$departure = array();
		foreach ( (array) $rows as $row ) {
			$departure[] = array(
				'id'   => $row['route_id'],
				'name' => $row['route_name'] ?: $row['route_id'],
				'seq'  => $row['route_seq'],
			);
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT arrival_bus AS route_id, arrival_bus_name AS route_name, MIN(arrival_bus_seq) AS route_seq
				 FROM {$this->table}
				 WHERE study_year = %s AND arrival_bus IS NOT NULL AND arrival_bus <> '' AND arrival_bus NOT IN ('0', '999')
				 GROUP BY arrival_bus, arrival_bus_name
				 ORDER BY route_seq ASC, route_name ASC",
				$study_year
			),
			ARRAY_A
		);

		$arrival = array();
		foreach ( (array) $rows as $row ) {
			$arrival[] = array(
				'id'   => $row['route_id'],
				'name' => $row['route_name'] ?: $row['route_id'],
				'seq'  => $row['route_seq'],
			);
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT trans_route AS route_id, trans_route_name AS route_name
				 FROM {$this->table}
				 WHERE study_year = %s AND trans_route IS NOT NULL AND trans_route <> ''
				 ORDER BY trans_route_name ASC",
				$study_year
			),
			ARRAY_A
		);
		$rounds = array();
		foreach ( (array) $rows as $row ) {
			$rounds[] = array(
				'id'   => $row['route_id'],
				'name' => $row['route_name'] ?: $row['route_id'],
				'seq'  => '',
			);
		}

		return array(
			'departure' => $departure,
			'arrival'   => $arrival,
			'rounds'    => $rounds,
		);
	}

	public function warm_sync_study_year( $study_year ) {
		global $wpdb;

		$study_year = sanitize_text_field( $study_year );
		if ( '' === $study_year ) {
			return array();
		}

		$families_table = $wpdb->prefix . 'olama_core_families';
		$years_table    = $wpdb->prefix . 'olama_core_student_years';

		$families = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT f.oracle_family_id AS family_id
				 FROM {$families_table} f
				 INNER JOIN {$years_table} sy ON sy.family_uid = f.family_uid
				 WHERE sy.study_year = %s AND f.oracle_family_id IS NOT NULL AND f.oracle_family_id <> ''
				 ORDER BY f.oracle_family_id ASC",
				$study_year
			),
			ARRAY_A
		);

		if ( empty( $families ) ) {
			return array();
		}

		$max_sync = (int) apply_filters( 'olama_messages_transportation_warm_sync_limit', 250 );
		$synced   = array();
		foreach ( array_slice( $families, 0, $max_sync ) as $family ) {
			$family_id = absint( $family['family_id'] ?? 0 );
			if ( ! $family_id ) {
				continue;
			}
			$synced[ $family_id ] = $this->sync_family_transportation( $family_id, $study_year );
		}

		return $synced;
	}

	public function filter_cached_families( array $families, $study_year, array $filters = array() ) {
		$study_year = sanitize_text_field( $study_year );
		if ( empty( $families ) ) {
			return array();
		}

		$route_mode     = sanitize_text_field( $filters['transport_route_mode'] ?? $filters['route_mode'] ?? 'either' );
		$class_name     = trim( sanitize_text_field( $filters['class_name'] ?? '' ) );
		$section_name   = trim( sanitize_text_field( $filters['section_name'] ?? '' ) );
		$departure_bus  = trim( sanitize_text_field( $filters['departure_bus'] ?? $filters['bus_name'] ?? '' ) );
		$arrival_bus    = trim( sanitize_text_field( $filters['arrival_bus'] ?? '' ) );
		$round_name     = trim( sanitize_text_field( $filters['round_name'] ?? '' ) );
		$families_by_id = array();
		$family_ids     = array();

		foreach ( $families as $family ) {
			$family_id = absint( $family['family_id'] ?? $family['oracle_family_id'] ?? 0 );
			if ( ! $family_id ) {
				continue;
			}
			$family['family_id']        = $family_id;
			$family['oracle_family_id']  = $family['oracle_family_id'] ?? (string) $family_id;
			$families_by_id[ $family_id ] = $family;
			$family_ids[]               = $family_id;
		}

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $family_ids ), '%d' ) );
		$sql          = $wpdb->prepare(
			"SELECT * FROM {$this->table} WHERE study_year = %s AND family_id IN ({$placeholders}) ORDER BY family_id ASC, student_id ASC",
			array_merge( array( $study_year ), $family_ids )
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		$rows_by_family = array();
		foreach ( (array) $rows as $row ) {
			$rows_by_family[ (int) $row['family_id'] ][] = $row;
		}

		$out = array();
		foreach ( $families_by_id as $family_id => $family ) {
			$rows = $rows_by_family[ $family_id ] ?? array();
			if ( empty( $rows ) ) {
				$rows = $this->sync_family_transportation( $family_id, $study_year );
			}

			$matched_rows = array();
			foreach ( $rows as $row ) {
				$class_match     = '' === $class_name || ( isset( $row['class_name'] ) && 0 === strcasecmp( trim( (string) $row['class_name'] ), $class_name ) );
				$section_match   = '' === $section_name || ( isset( $row['section_name'] ) && 0 === strcasecmp( trim( (string) $row['section_name'] ), $section_name ) );
				$departure_match = '' === $departure_bus || $this->row_matches_route( $row, $departure_bus, 'departure' );
				$arrival_match   = '' === $arrival_bus || $this->row_matches_route( $row, $arrival_bus, 'arrival' );
				$round_match     = '' === $round_name || $this->row_matches_round( $row, $round_name );

				if ( 'departure' === $route_mode ) {
					$include = $departure_match;
				} elseif ( 'arrival' === $route_mode ) {
					$include = $arrival_match;
				} elseif ( 'both' === $route_mode ) {
					$include = $departure_match && $arrival_match;
				} else {
					$include = ( '' === $departure_bus && '' === $arrival_bus ) || $departure_match || $arrival_match;
				}

				if ( $class_match && $section_match && $include && $round_match ) {
					$matched_rows[] = $row;
				}
			}

			if ( '' !== $class_name || '' !== $section_name || '' !== $departure_bus || '' !== $arrival_bus || '' !== $round_name || 'both' === $route_mode || 'departure' === $route_mode || 'arrival' === $route_mode ) {
				if ( empty( $matched_rows ) ) {
					continue;
				}
			}

			$family['transportation_rows'] = $rows;
			$family['transportation_match_rows'] = $matched_rows;
			$family['transportation_departure_buses'] = array_values( array_unique( array_filter( wp_list_pluck( $rows, 'departure_bus' ) ) ) );
			$family['transportation_arrival_buses'] = array_values( array_unique( array_filter( wp_list_pluck( $rows, 'arrival_bus' ) ) ) );
			$family['transportation_departure_bus_names'] = array_values( array_unique( array_filter( wp_list_pluck( $rows, 'departure_bus_name' ) ) ) );
			$family['transportation_arrival_bus_names'] = array_values( array_unique( array_filter( wp_list_pluck( $rows, 'arrival_bus_name' ) ) ) );
			$out[] = $family;
		}

		return $out;
	}

	protected function row_matches_route( array $row, $needle, $direction ) {
		$needle = trim( sanitize_text_field( (string) $needle ) );
		if ( '' === $needle ) {
			return true;
		}

		$fields = 'departure' === $direction
			? array( 'departure_bus', 'departure_bus_name', 'departure_bus_seq' )
			: array( 'arrival_bus', 'arrival_bus_name', 'arrival_bus_seq' );

		foreach ( $fields as $field ) {
			if ( ! empty( $row[ $field ] ) && false !== stripos( (string) $row[ $field ], $needle ) ) {
				return true;
			}
		}

		return false;
	}

	protected function row_matches_round( array $row, $needle ) {
		$needle = trim( sanitize_text_field( (string) $needle ) );
		if ( '' === $needle ) {
			return true;
		}

		foreach ( array( 'trans_route', 'trans_route_name' ) as $field ) {
			if ( isset( $row[ $field ] ) && '' !== (string) $row[ $field ] && false !== stripos( (string) $row[ $field ], $needle ) ) {
				return true;
			}
		}

		return false;
	}
}
