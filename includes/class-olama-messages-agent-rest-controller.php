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
}
