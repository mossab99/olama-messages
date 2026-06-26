<?php
/**
 * Agent Service — manages desktop sending agents, credentials hashing, and logs.
 *
 * @package Olama_Messages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Olama_Messages_Agent_Service {

	/** @var string agents table name */
	private $table_agents;

	/** @var string events table name */
	private $table_events;

	/** @var string test messages table name */
	private $table_test_messages;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->table_agents        = $wpdb->prefix . 'olama_msg_agents';
		$this->table_events        = $wpdb->prefix . 'olama_msg_agent_events';
		$this->table_test_messages = $wpdb->prefix . 'olama_msg_agent_test_messages';
	}

	/**
	 * Create/register a new sending agent.
	 *
	 * @param  array $data Agent configuration.
	 * @return array       Registration output including UUID and raw API key.
	 * @throws Exception   On database insert failure.
	 */
	public function create_agent( array $data ) {
		global $wpdb;

		// Generate random UUID
		$uuid = sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
			mt_rand( 0, 0xffff ),
			mt_rand( 0, 0x0fff ) | 0x4000,
			mt_rand( 0, 0x3fff ) | 0x8000,
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
		);

		// Generate cryptographically secure API key: olama_agent_ + 64 hex chars (32 bytes)
		$raw_key = 'olama_agent_' . bin2hex( random_bytes( 32 ) );
		$hashed_key = wp_hash_password( $raw_key );

		$defaults = array(
			'agent_uuid'     => $uuid,
			'agent_name'     => '',
			'agent_key_hash' => $hashed_key,
			'status'         => 'inactive',
			'created_by'     => get_current_user_id(),
			'created_at'     => current_time( 'mysql' ),
		);

		$insert_data = array_merge( $defaults, $data );
		$result = $wpdb->insert( $this->table_agents, $insert_data );

		if ( false === $result ) {
			throw new Exception( 'Failed to register agent: ' . $wpdb->last_error );
		}

		$agent_id = (int) $wpdb->insert_id;

		// Log registration event
		$this->log_event(
			$agent_id,
			$uuid,
			'registered',
			sprintf( 'Agent "%s" registered successfully.', $insert_data['agent_name'] ),
			array( 'platform' => $insert_data['platform'] ?? '' ),
			'info'
		);

		return array(
			'id'      => $agent_id,
			'uuid'    => $uuid,
			'raw_key' => $raw_key,
		);
	}

	/**
	 * Retrieve a single agent by UUID.
	 *
	 * @param  string $agent_uuid Agent UUID.
	 * @return array|null         Agent data or null if not found.
	 */
	public function get_agent_by_uuid( string $agent_uuid ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table_agents} WHERE agent_uuid = %s", $agent_uuid ),
			ARRAY_A
		);

		return $row ? $this->typecast_agent( $row ) : null;
	}

	/**
	 * Retrieve a single agent by ID.
	 *
	 * @param  int $agent_id Agent ID.
	 * @return array|null     Agent data or null if not found.
	 */
	public function get_agent( int $agent_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table_agents} WHERE id = %d", $agent_id ),
			ARRAY_A
		);

		return $row ? $this->typecast_agent( $row ) : null;
	}

	/**
	 * List registered agents based on filters.
	 *
	 * @param  array $args Query arguments.
	 * @return array       Array of agents.
	 */
	public function list_agents( array $args = array() ) {
		global $wpdb;

		$defaults = array(
			'status'  => '',
			'limit'   => 50,
			'offset'  => 0,
			'orderby' => 'created_at',
			'order'   => 'DESC',
		);

		$args = array_merge( $defaults, $args );

		$where  = array();
		$values = array();

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = sanitize_text_field( $args['status'] );
		}

		$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

		$allowed_cols = array( 'id', 'agent_name', 'status', 'created_at', 'last_seen_at' );
		$orderby      = in_array( $args['orderby'], $allowed_cols, true ) ? $args['orderby'] : 'created_at';
		$order        = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

		$query = $wpdb->prepare(
			"SELECT * FROM {$this->table_agents} {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
			array_merge( $values, array( absint( $args['limit'] ), absint( $args['offset'] ) ) )
		);

		$rows = $wpdb->get_results( $query, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		foreach ( $rows as &$row ) {
			$row = $this->typecast_agent( $row );
		}

		return $rows;
	}

	/**
	 * Validate agent credentials against hashed credentials.
	 *
	 * @param  string $agent_uuid Agent UUID.
	 * @param  string $raw_key    Plaintext API key.
	 * @return array|null         Agent data if valid, null otherwise.
	 */
	public function validate_agent_auth( string $agent_uuid, string $raw_key ) {
		$agent = $this->get_agent_by_uuid( $agent_uuid );
		if ( ! $agent ) {
			return null;
		}

		// Block revoked agents
		if ( $agent['status'] === 'revoked' || ! empty( $agent['revoked_at'] ) ) {
			return null;
		}

		// Perform verification using WordPress hash checking
		if ( wp_check_password( $raw_key, $agent['agent_key_hash'] ) ) {
			return $agent;
		}

		return null;
	}

	/**
	 * Update agent details from heartbeat.
	 *
	 * @param  string $agent_uuid Agent UUID.
	 * @param  array  $payload    Diagnostics payload.
	 * @return bool               True on success.
	 */
	public function update_heartbeat( string $agent_uuid, array $payload ) {
		global $wpdb;

		$agent = $this->get_agent_by_uuid( $agent_uuid );
		if ( ! $agent ) {
			return false;
		}

		// Enforce heartbeat payload limit size (max 16KB for DB safety)
		$json_payload = wp_json_encode( $payload );
		if ( strlen( $json_payload ) > 16384 ) {
			$payload = array( 'error' => 'Heartbeat payload exceeded 16KB limit' );
			$json_payload = wp_json_encode( $payload );
		}

		$now = current_time( 'mysql' );
		$update_data = array(
			'status'               => 'online',
			'platform'             => sanitize_text_field( $payload['platform'] ?? '' ),
			'app_version'          => sanitize_text_field( $payload['app_version'] ?? '' ),
			'machine_name'         => sanitize_text_field( $payload['machine_name'] ?? '' ),
			'windows_user'         => sanitize_text_field( $payload['windows_user'] ?? '' ),
			'kde_cli_path'         => sanitize_text_field( $payload['kde_cli_path'] ?? '' ),
			'kde_cli_found'        => ! empty( $payload['kde_cli_found'] ) ? 1 : 0,
			'kde_device_id'        => sanitize_text_field( $payload['kde_device_id'] ?? '' ),
			'kde_device_name'      => sanitize_text_field( $payload['kde_device_name'] ?? '' ),
			'kde_device_reachable' => ! empty( $payload['kde_device_reachable'] ) ? 1 : 0,
			'last_seen_at'         => $now,
			'last_heartbeat_json'  => $json_payload,
			'updated_at'           => $now,
		);

		$result = $wpdb->update(
			$this->table_agents,
			$update_data,
			array( 'agent_uuid' => $agent_uuid )
		);

		if ( false === $result ) {
			return false;
		}

		// Log heartbeat event with 10-minute throttling to protect dbDelta tables from bloat
		$last_hb_log = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT created_at FROM {$this->table_events} 
				 WHERE agent_uuid = %s AND event_type = 'heartbeat' 
				 ORDER BY created_at DESC LIMIT 1",
				$agent_uuid
			)
		);

		$should_log = true;
		if ( $last_hb_log ) {
			$elapsed = time() - strtotime( $last_hb_log );
			if ( $elapsed < 600 ) { // 10 minutes
				$should_log = false;
			}
		}

		if ( $should_log ) {
			$this->log_event(
				$agent['id'],
				$agent_uuid,
				'heartbeat',
				sprintf( 'Agent heartbeat received from machine "%s".', $update_data['machine_name'] ),
				array(
					'kde_device_name' => $update_data['kde_device_name'],
					'kde_cli_found'   => $update_data['kde_cli_found'],
				),
				'info'
			);
		}

		return true;
	}

	/**
	 * Save detailed diagnostics.
	 *
	 * @param  string $agent_uuid Agent UUID.
	 * @param  array  $payload    Diagnostics JSON data.
	 * @return bool
	 */
	public function save_diagnostics( string $agent_uuid, array $payload ) {
		global $wpdb;

		$agent = $this->get_agent_by_uuid( $agent_uuid );
		if ( ! $agent ) {
			return false;
		}

		// Size limit diagnostics (max 32KB)
		$json_payload = wp_json_encode( $payload );
		if ( strlen( $json_payload ) > 32768 ) {
			$payload = array( 'error' => 'Diagnostics payload exceeded 32KB limit' );
			$json_payload = wp_json_encode( $payload );
		}

		// Update CLI path & device ID if provided
		$update_data = array(
			'kde_cli_path'  => sanitize_text_field( $payload['kde_cli_path'] ?? $agent['kde_cli_path'] ),
			'kde_cli_found' => ! empty( $payload['kde_cli_found'] ) ? 1 : 0,
			'updated_at'    => current_time( 'mysql' ),
		);

		if ( ! empty( $payload['selected_device_id'] ) ) {
			$update_data['kde_device_id'] = sanitize_text_field( $payload['selected_device_id'] );
			// Resolve selected device details
			if ( ! empty( $payload['devices'] ) && is_array( $payload['devices'] ) ) {
				foreach ( $payload['devices'] as $d ) {
					if ( ( $d['id'] ?? '' ) === $payload['selected_device_id'] ) {
						$update_data['kde_device_name']      = sanitize_text_field( $d['name'] ?? '' );
						$update_data['kde_device_reachable'] = ! empty( $d['reachable'] ) ? 1 : 0;
						break;
					}
				}
			}
		}

		$wpdb->update(
			$this->table_agents,
			$update_data,
			array( 'agent_uuid' => $agent_uuid )
		);

		// Log diagnostic event
		$cli_status = $update_data['kde_cli_found'] ? 'kde_detected' : 'kde_missing';
		$this->log_event(
			$agent['id'],
			$agent_uuid,
			$cli_status,
			$update_data['kde_cli_found'] ? 'KDE Connect CLI tool detected.' : 'KDE Connect CLI tool not found.',
			array( 'path' => $update_data['kde_cli_path'] ),
			$update_data['kde_cli_found'] ? 'info' : 'warning'
		);

		if ( ! empty( $update_data['kde_device_id'] ) ) {
			$device_status = $update_data['kde_device_reachable'] ? 'device_detected' : 'device_unreachable';
			$this->log_event(
				$agent['id'],
				$agent_uuid,
				$device_status,
				sprintf( 'KDE device "%s" (%s).', $update_data['kde_device_name'], $update_data['kde_device_reachable'] ? 'reachable' : 'unreachable' ),
				array( 'device_id' => $update_data['kde_device_id'] ),
				$update_data['kde_device_reachable'] ? 'info' : 'warning'
			);
		}

		// Log a main diagnostics submit event
		$this->log_event(
			$agent['id'],
			$agent_uuid,
			'diagnostic',
			'Detailed discovery diagnostics submitted.',
			$payload,
			'info'
		);

		return true;
	}

	/**
	 * Log manual test message metadata.
	 *
	 * @param  int    $agent_id Agent ID.
	 * @param  array  $payload  Test results data.
	 * @return int|bool         Insert ID or false.
	 */
	public function log_test_message( int $agent_id, array $payload ) {
		global $wpdb;

		$agent = $this->get_agent( $agent_id );
		if ( ! $agent ) {
			return false;
		}

		// Clamp texts (max 4000 chars)
		$stdout = substr( (string) ($payload['stdout_text'] ?? ''), 0, 4000 );
		$stderr = substr( (string) ($payload['stderr_text'] ?? ''), 0, 4000 );
		$msg_body = substr( (string) ($payload['message_body'] ?? ''), 0, 160 ); // limit to 160 for test message

		$insert_data = array(
			'agent_id'     => $agent_id,
			'phone_e164'   => sanitize_text_field( $payload['phone_e164'] ?? '' ),
			'message_body' => sanitize_textarea_field( $msg_body ),
			'status'       => sanitize_text_field( $payload['status'] ?? 'failed' ),
			'result_code'  => sanitize_text_field( $payload['result_code'] ?? '' ),
			'stdout_text'  => $stdout,
			'stderr_text'  => $stderr,
			'created_by'   => get_current_user_id(),
			'created_at'   => current_time( 'mysql' ),
		);

		$result = $wpdb->insert( $this->table_test_messages, $insert_data );
		if ( false === $result ) {
			return false;
		}

		// Log event
		$this->log_event(
			$agent_id,
			$agent['agent_uuid'],
			( $insert_data['status'] === 'sent_by_kde' ) ? 'manual_test_sms' : 'manual_test_sms_failed',
			sprintf( 'Manual test SMS sent to phone %s. Status: %s.', $insert_data['phone_e164'], $insert_data['status'] ),
			array(
				'result_code' => $insert_data['result_code'],
				'stdout'      => $stdout,
				'stderr'      => $stderr,
			),
			( $insert_data['status'] === 'sent_by_kde' ) ? 'info' : 'error'
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Revoke agent authorization.
	 *
	 * @param  int $agent_id Agent ID.
	 * @return bool
	 */
	public function revoke_agent( int $agent_id ) {
		global $wpdb;

		$agent = $this->get_agent( $agent_id );
		if ( ! $agent ) {
			return false;
		}

		$now = current_time( 'mysql' );
		$result = $wpdb->update(
			$this->table_agents,
			array(
				'status'     => 'revoked',
				'revoked_at' => $now,
				'updated_at' => $now,
			),
			array( 'id' => $agent_id )
		);

		if ( false !== $result ) {
			$this->log_event(
				$agent_id,
				$agent['agent_uuid'],
				'revoked',
				'Agent authorization revoked by admin.',
				array(),
				'warning'
			);
			return true;
		}

		return false;
	}

	/**
	 * Log agent audit events.
	 *
	 * @param  int|null $agent_id    Agent ID.
	 * @param  string   $agent_uuid  Agent UUID.
	 * @param  string   $event_type  Event type.
	 * @param  string   $message     Log message.
	 * @param  array    $context     Arbitrary context.
	 * @param  string   $severity    info|warning|error.
	 */
	public function log_event( $agent_id, string $agent_uuid, string $event_type, string $message = '', array $context = array(), string $severity = 'info' ) {
		global $wpdb;

		$wpdb->insert(
			$this->table_events,
			array(
				'agent_id'     => $agent_id,
				'agent_uuid'   => $agent_uuid,
				'event_type'   => sanitize_text_field( $event_type ),
				'severity'     => sanitize_text_field( $severity ),
				'message'      => sanitize_textarea_field( $message ),
				'context_json' => wp_json_encode( $context ),
				'created_at'   => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Get event log list for an agent.
	 *
	 * @param  string $agent_uuid
	 * @param  int    $limit
	 * @return array
	 */
	public function get_events( string $agent_uuid, int $limit = 50 ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_events} WHERE agent_uuid = %s ORDER BY created_at DESC LIMIT %d",
				$agent_uuid,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Summarize agent states for dashboard widgets.
	 *
	 * @return array Status counts.
	 */
	public function count_agents_by_status() {
		global $wpdb;

		$results = $wpdb->get_results(
			"SELECT status, COUNT(*) as count FROM {$this->table_agents} GROUP BY status",
			ARRAY_A
		);

		$counts = array(
			'inactive' => 0,
			'online'   => 0,
			'offline'  => 0,
			'revoked'  => 0,
			'total'    => 0,
		);

		foreach ( $results as $row ) {
			$status = $row['status'];
			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ] = (int) $row['count'];
			}
		}

		// Recalculate computed 'offline' state
		// (online agents with no heartbeat in last 5 minutes)
		$active_threshold = date( 'Y-m-d H:i:s', time() - 300 );
		$offline_agents = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table_agents} 
				 WHERE status = 'online' AND (last_seen_at IS NULL OR last_seen_at < %s)",
				$active_threshold
			)
		);

		if ( $offline_agents > 0 ) {
			$counts['online']  = max( 0, $counts['online'] - $offline_agents );
			$counts['offline'] += $offline_agents;
		}

		$counts['total'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_agents}" );
		$counts['kde_ready'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table_agents} 
				 WHERE status = 'online' AND kde_cli_found = 1 AND kde_device_reachable = 1 AND last_seen_at >= %s",
				$active_threshold
			)
		);

		$counts['last_seen'] = $wpdb->get_var( "SELECT MAX(last_seen_at) FROM {$this->table_agents}" ) ?: '—';

		return $counts;
	}

	/**
	 * Helper to typecast SQL row fields.
	 */
	private function typecast_agent( array $row ) {
		$row['id']                   = (int) $row['id'];
		$row['kde_cli_found']        = (int) $row['kde_cli_found'];
		$row['kde_device_reachable'] = (int) $row['kde_device_reachable'];
		if ( $row['last_heartbeat_json'] ) {
			$row['heartbeat'] = json_decode( $row['last_heartbeat_json'], true );
		} else {
			$row['heartbeat'] = array();
		}
		return $row;
	}
}
