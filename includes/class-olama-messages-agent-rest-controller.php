<?php
/**
 * Agent REST Controller — exposes endpoints for desktop agent heartbeats and discovery.
 *
 * @package Olama_Messages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Olama_Messages_Agent_Rest_Controller extends WP_REST_Controller {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = 'olama-messages/v1';
		$this->rest_base = 'agent';
	}

	/**
	 * Register agent-related REST routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/heartbeat',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_heartbeat' ),
					'permission_callback' => array( $this, 'check_authentication' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/diagnostics',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_diagnostics' ),
					'permission_callback' => array( $this, 'check_authentication' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/test-result',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_test_result' ),
					'permission_callback' => array( $this, 'check_authentication' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/config',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_get_config' ),
					'permission_callback' => array( $this, 'check_authentication' ),
				),
			)
		);

		// ─── Dispatcher REST Endpoints (Phase 4) ────────────────────────────────

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/jobs/reserve',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_reserve_jobs' ),
					'permission_callback' => array( $this, 'check_authentication' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/jobs/result',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_report_result' ),
					'permission_callback' => array( $this, 'check_authentication' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/jobs/config',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_get_dispatcher_config' ),
					'permission_callback' => array( $this, 'check_authentication' ),
				),
			)
		);
	}

	/**
	 * Permission callback to validate credentials in HTTP headers.
	 *
	 * @param  WP_REST_Request $request REST request.
	 * @return bool|WP_Error             True if authenticated, WP_Error otherwise.
	 */
	public function check_authentication( $request ) {
		$agent_uuid = $request->get_header( 'X-Olama-Agent-UUID' );
		$raw_key    = $request->get_header( 'X-Olama-Agent-Key' );

		if ( empty( $agent_uuid ) || empty( $raw_key ) ) {
			return new WP_Error(
				'olama_agent_unauthorized',
				__( 'Missing agent credentials headers.', 'olama-messages' ),
				array( 'status' => 401 )
			);
		}

		$agent = Olama_Messages_Plugin::instance()->agents()->validate_agent_auth( $agent_uuid, $raw_key );
		if ( ! $agent ) {
			return new WP_Error(
				'olama_agent_forbidden',
				__( 'Invalid agent credentials or revoked agent.', 'olama-messages' ),
				array( 'status' => 403 )
			);
		}

		// Store authorized agent in the request context
		$request->set_param( '_authorized_agent', $agent );

		return true;
	}

	/**
	 * Handle agent heartbeat request.
	 *
	 * @param  WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function handle_heartbeat( $request ) {
		$agent = $request->get_param( '_authorized_agent' );
		$params = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$ok = Olama_Messages_Plugin::instance()->agents()->update_heartbeat( $agent['agent_uuid'], $params );

		if ( ! $ok ) {
			return new WP_REST_Response(
				array(
					'status'  => 'error',
					'message' => 'Failed to update heartbeat.',
				),
				500
			);
		}

		// Return server ISO time (UTC)
		return new WP_REST_Response(
			array(
				'status'       => 'ok',
				'server_time'  => gmdate( 'c' ),
				'agent_status' => 'online',
			),
			200
		);
	}

	/**
	 * Handle agent diagnostics submit.
	 *
	 * @param  WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function handle_diagnostics( $request ) {
		$agent = $request->get_param( '_authorized_agent' );
		$params = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			return new WP_REST_Response(
				array(
					'status'  => 'error',
					'message' => 'Invalid JSON body.',
				),
				400
			);
		}

		$ok = Olama_Messages_Plugin::instance()->agents()->save_diagnostics( $agent['agent_uuid'], $params );

		if ( ! $ok ) {
			return new WP_REST_Response(
				array(
					'status'  => 'error',
					'message' => 'Failed to save diagnostics.',
				),
				500
			);
		}

		return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
	}

	/**
	 * Handle manual test SMS result submission.
	 *
	 * @param  WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function handle_test_result( $request ) {
		$agent = $request->get_param( '_authorized_agent' );
		$params = $request->get_json_params();

		if ( empty( $params ) || ! is_array( $params ) ) {
			return new WP_REST_Response(
				array(
					'status'  => 'error',
					'message' => 'Missing body params.',
				),
				400
			);
		}

		$required = array( 'phone_e164', 'message_body', 'status' );
		foreach ( $required as $f ) {
			if ( empty( $params[ $f ] ) ) {
				return new WP_REST_Response(
					array(
						'status'  => 'error',
						'message' => sprintf( 'Missing parameter: %s', $f ),
					),
					400
				);
			}
		}

		$ok = Olama_Messages_Plugin::instance()->agents()->log_test_message( $agent['id'], $params );

		if ( ! $ok ) {
			return new WP_REST_Response(
				array(
					'status'  => 'error',
					'message' => 'Failed to log test result.',
				),
				500
			);
		}

		return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
	}

	/**
	 * Retrieve agent intervals and configuration.
	 *
	 * @param  WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function handle_get_config( $request ) {
		return new WP_REST_Response(
			array(
				'status' => 'ok',
				'config' => array(
					'heartbeat_interval_seconds'   => 60,
					'diagnostics_interval_seconds' => 300,
					'allow_manual_test_sms'        => true,
					'max_test_sms_length'          => 160,
				),
			),
			200
		);
	}

	// ─── Dispatcher REST Endpoints handlers (Phase 4) ───────────────────────

	/**
	 * Reserve SMS jobs for the agent.
	 *
	 * @param  WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function handle_reserve_jobs( $request ) {
		$agent = $request->get_param( '_authorized_agent' );
		$params = $request->get_json_params() ?: array();

		$max_jobs = 1; // Enforce sequential device dispatch server-side.

		// Check KDE readiness from:
		//  1. Capabilities sent in request body (C# app may send these)
		//  2. Agent DB record (updated by heartbeat — authoritative fallback)
		$req_capabilities = $params['capabilities'] ?? array();
		$kde_cli_found        = ! empty( $req_capabilities['kde_cli_found'] )       || ! empty( $agent['kde_cli_found'] );
		$kde_device_reachable = ! empty( $req_capabilities['kde_device_reachable'] ) || ! empty( $agent['kde_device_reachable'] );
		$kde_ready            = $kde_cli_found && $kde_device_reachable;

		if ( ! $kde_ready ) {
			return new WP_REST_Response(
				array(
					'status'             => 'ok',
					'jobs'               => array(),
					'server_time'        => current_time( 'mysql' ),
					'poll_after_seconds' => 30,
					'reason'             => 'kde_not_ready',
				),
				200
			);
		}

		$dispatcher = Olama_Messages_Plugin::instance()->dispatcher();
		$jobs = $dispatcher->reserve_batch( $agent, $max_jobs );

		return new WP_REST_Response(
			array(
				'status'             => 'ok',
				'jobs'               => $jobs,
				'server_time'        => current_time( 'mysql' ),
				'poll_after_seconds' => 15,
			),
			200
		);
	}

	/**
	 * Report output result for a reserved queue item.
	 *
	 * @param  WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function handle_report_result( $request ) {
		$agent = $request->get_param( '_authorized_agent' );
		$params = $request->get_json_params() ?: array();

		$required = array( 'queue_id', 'status', 'kde_exit_code' );
		foreach ( $required as $f ) {
			if ( ! isset( $params[ $f ] ) ) {
				return new WP_REST_Response(
					array(
						'status'  => 'error',
						'message' => sprintf( 'Missing parameter: %s', $f ),
					),
					400
				);
			}
		}

		$queue_id      = intval( $params['queue_id'] );
		$status        = sanitize_text_field( $params['status'] );
		$kde_exit_code = intval( $params['kde_exit_code'] );
		$stdout        = isset( $params['stdout'] ) ? (string) $params['stdout'] : '';
		$stderr        = isset( $params['stderr'] ) ? (string) $params['stderr'] : '';

		if ( $status !== 'sent_by_kde' && $status !== 'failed' ) {
			return new WP_REST_Response(
				array(
					'status'  => 'error',
					'message' => 'Invalid status value.',
				),
				400
			);
		}

		$dispatcher = Olama_Messages_Plugin::instance()->dispatcher();
		$response = $dispatcher->update_send_status( $agent, $queue_id, $status, $kde_exit_code, $stdout, $stderr );

		if ( false === $response ) {
			return new WP_REST_Response(
				array(
					'status'  => 'error',
					'message' => 'Action forbidden. Reporting agent is not matching lock owner or record is not in reserved state.',
				),
				403
			);
		}

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Return dispatcher configuration limits.
	 *
	 * @param  WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function handle_get_dispatcher_config( $request ) {
		return new WP_REST_Response(
			array(
				'status'                  => 'ok',
				'poll_interval_seconds'   => 15,
				'max_jobs_per_poll'       => 1,
				'min_seconds_between_sms' => 20,
				'max_sms_per_hour'        => 60,
				'reservation_ttl_seconds' => 120,
			),
			200
		);
	}
}
