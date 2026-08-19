<?php
/**
 * Dispatcher Service — manages SMS job reservations, just-in-time tokenized URL substitution,
 * status updates, and campaign completion detection.
 *
 * @package Olama_Messages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Olama_Messages_Dispatcher_Service {

	/** @var string campaigns table name */
	private $table_campaigns;

	/** @var string queue table name */
	private $table_queue;

	/** @var string agents table name */
	private $table_agents;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->table_campaigns = $wpdb->prefix . 'olama_msg_campaigns';
		$this->table_queue     = $wpdb->prefix . 'olama_msg_queue';
		$this->table_agents    = $wpdb->prefix . 'olama_msg_agents';
	}

	/**
	 * Reserve a batch of prepared messages for sending.
	 *
	 * Uses database-level locking (transaction and FOR UPDATE) to prevent concurrency issues.
	 *
	 * @param  array $agent    Active authenticated agent details.
	 * @param  int   $max_jobs Requested maximum batch size.
	 * @return array           List of reserved job records.
	 */
	public function reserve_batch( array $agent, int $max_jobs = 1 ) {
		global $wpdb;

		// Deliberately reserve one SMS per poll. Campaigns may contain many
		// messages, but pacing remains sequential for the connected device.
		$limit = 1;

		$wpdb->query( 'START TRANSACTION' );

		// 1. Fetch campaigns that are in 'sending' status
		$sending_campaign_ids = $wpdb->get_col(
			"SELECT id FROM {$this->table_campaigns} WHERE status = 'sending'"
		);

		if ( empty( $sending_campaign_ids ) ) {
			$wpdb->query( 'COMMIT' );
			return array();
		}

		$campaign_ids_list = implode( ',', array_map( 'intval', $sending_campaign_ids ) );
		$now = current_time( 'mysql', true );

		// 2. Select prepared records or expired reserved/retry_wait records for active campaigns
		// A reservation is expired if status is 'reserved' and reservation_expires_at is in the past
		$query = $wpdb->prepare(
			"SELECT q.*, c.study_year 
			 FROM {$this->table_queue} q
			 JOIN {$this->table_campaigns} c ON q.campaign_id = c.id
			 WHERE q.campaign_id IN ($campaign_ids_list)
			   AND ( q.status = 'prepared' OR q.status = 'retry_wait' OR ( q.status = 'reserved' AND q.reservation_expires_at < %s ) )
			   AND q.attempt_count < q.max_attempts
			 ORDER BY q.id ASC
			 LIMIT %d
			 FOR UPDATE",
			$now,
			$limit
		);

		$rows = $wpdb->get_results( $query, ARRAY_A );

		if ( empty( $rows ) ) {
			$wpdb->query( 'COMMIT' );
			return array();
		}

		$reserved_jobs = array();
		$reservation_ttl = 120; // 2 minutes
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + $reservation_ttl );

		foreach ( $rows as $row ) {
			$queue_id = (int) $row['id'];
			$campaign_id = (int) $row['campaign_id'];
			$family_id = (int) $row['family_id'];
			$phone_e164 = $row['phone_e164'];
			$template_body = $row['message_body_preview'];

			$final_body = $template_body;
			$payment_token_id = null;

			// Just-in-Time Token Replacement
			if ( $row['requires_payment_link'] && strpos( $template_body, '{{PAYMENT_LINK}}' ) !== false ) {
				$token_service = Olama_Messages_Plugin::instance()->tokens();
				$study_year = $row['study_year'];

				try {
					$token_data = $token_service->generate_token( $family_id, $study_year );
					$payment_token_id = $token_data['token_id'];
					$short_data = Olama_Messages_Plugin::instance()->short_links()
						->get_or_create_for_family_token( $payment_token_id, $family_id, $study_year );
					$link = $short_data['short_url'];

					$final_body = str_replace( '{{PAYMENT_LINK}}', $link, $template_body );
				} catch ( Exception $e ) {
					// Update record to failed and log event
					$wpdb->update(
						$this->table_queue,
						array(
							'status'             => 'failed',
							'last_error_message' => 'JIT payment link rendering failed: ' . $e->getMessage(),
							'failed_at'          => $now,
							'updated_at'         => $now,
						),
						array( 'id' => $queue_id )
					);

					$this->log_agent_event(
						$agent['id'],
						$agent['agent_uuid'],
						'jit_render_failed',
						sprintf( 'JIT token rendering failed for family ID %d: %s', $family_id, $e->getMessage() ),
						array( 'queue_id' => $queue_id ),
						'error'
					);
					$this->check_and_finalize_campaign( $campaign_id );

					continue;
				}
			}

			// Update queue record state to 'reserved' without incrementing attempts (attempts are only counted when result is reported)
			$wpdb->update(
				$this->table_queue,
				array(
					'status'                 => 'reserved',
					'reserved_by_agent_id'   => $agent['id'],
					'reserved_by_agent_uuid' => $agent['agent_uuid'],
					'reserved_at'            => $now,
					'reservation_expires_at' => $expires_at,
					'send_started_at'        => $now,
					'payment_token_id'       => $payment_token_id,
					'updated_at'             => $now,
				),
				array( 'id' => $queue_id )
			);

			$reserved_jobs[] = array(
				'queue_id'       => $queue_id,
				'campaign_id'    => $campaign_id,
				'phone_e164'     => $phone_e164,
				'message_body'   => $final_body,
				'message_length' => mb_strlen( $final_body ),
				'expires_at'     => $expires_at,
			);

			// Log reservation audit event
			$this->log_agent_event(
				$agent['id'],
				$agent['agent_uuid'],
				'job_reserved',
				sprintf( 'Job ID %d reserved by agent.', $queue_id ),
				array( 'queue_id' => $queue_id, 'campaign_id' => $campaign_id ),
				'info'
			);
		}

		$wpdb->query( 'COMMIT' );
		return $reserved_jobs;
	}

	/**
	 * Log the output result for a reserved queue item.
	 *
	 * @param  array $agent       Active authenticated agent details.
	 * @param  int   $queue_id    Queue record ID.
	 * @param  string $status     Outcome status: 'sent_by_kde' or 'failed'.
	 * @param  int   $exit_code   KDE process exit code.
	 * @param  string $stdout     Captured stdout.
	 * @param  string $stderr     Captured stderr.
	 * @return array|false        Status response on success, false on verification failure.
	 */
	public function update_send_status( array $agent, int $queue_id, string $status, int $exit_code, string $stdout, string $stderr ) {
		global $wpdb;

		$queue_item = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table_queue} WHERE id = %d", $queue_id ),
			ARRAY_A
		);

		if ( ! $queue_item ) {
			return false;
		}

		// Enforce reporting agent match (or handle late checkouts if not overridden by another agent)
		if ( (int) $queue_item['reserved_by_agent_id'] !== (int) $agent['id'] ) {
			return false;
		}

		// If already sent or failed, do not double-process (idempotency guard)
		if ( in_array( $queue_item['status'], array( 'sent', 'failed' ), true ) ) {
			return $this->get_campaign_status_response( (int) $queue_item['campaign_id'] );
		}

		// Safety guard: if the queue row was cancelled (admin cancelled the campaign while
		// this job was reserved), never overwrite the cancelled status to sent/failed.
		// Log for audit but return an idempotent ok so the agent does not retry.
		if ( $queue_item['status'] === 'cancelled' ) {
			$this->log_agent_event(
				$agent['id'],
				$agent['agent_uuid'],
				'late_result_after_cancelled',
				sprintf(
					'Late result received for Job ID %d (status: %s) but queue record is already cancelled. Ignoring.',
					$queue_id,
					$status
				),
				array(
					'queue_id'    => $queue_id,
					'late_status' => $status,
					'exit_code'   => $exit_code,
				),
				'warning'
			);

			// Return idempotent ok — agent should not retry this job.
			return array(
				'status'          => 'ok',
				'queue_status'    => 'cancelled',
				'campaign_status' => $wpdb->get_var(
					$wpdb->prepare(
						"SELECT status FROM {$this->table_campaigns} WHERE id = %d",
						(int) $queue_item['campaign_id']
					)
				),
			);
		}

		$now = current_time( 'mysql', true );
		$clamped_stdout = substr( $stdout, 0, 4000 );
		$clamped_stderr = substr( $stderr, 0, 4000 );

		if ( $status === 'sent_by_kde' ) {
			$wpdb->update(
				$this->table_queue,
				array(
					'status'             => 'sent',
					'sent_at'            => $now,
					'kde_exit_code'      => $exit_code,
					'last_stdout'        => $clamped_stdout,
					'last_stderr'        => $clamped_stderr,
					'last_error_code'    => null,
					'last_error_message' => null,
					'attempt_count'      => (int) $queue_item['attempt_count'] + 1,
					'updated_at'         => $now,
				),
				array( 'id' => $queue_id )
			);

			$this->log_agent_event(
				$agent['id'],
				$agent['agent_uuid'],
				'job_sent',
				sprintf( 'Job ID %d successfully sent via KDE Connect.', $queue_id ),
				array( 'queue_id' => $queue_id, 'campaign_id' => $queue_item['campaign_id'] ),
				'info'
			);

		} else {
			// Job sending failed
			$new_attempts = (int) $queue_item['attempt_count'] + 1;
			$max_attempts = (int) $queue_item['max_attempts'];

			if ( $new_attempts < $max_attempts ) {
				$new_status = 'retry_wait';
				$event_type = 'job_retry_scheduled';
				$msg = sprintf( 'Job ID %d failed. Scheduled for retry (Attempt %d/%d).', $queue_id, $new_attempts, $max_attempts );
			} else {
				$new_status = 'failed';
				$event_type = 'job_failed';
				$msg = sprintf( 'Job ID %d failed permanently after %d attempts.', $queue_id, $max_attempts );
			}

			$update_data = array(
				'status'             => $new_status,
				'failed_at'          => ( $new_status === 'failed' ) ? $now : null,
				'kde_exit_code'      => $exit_code,
				'last_stdout'        => $clamped_stdout,
				'last_stderr'        => $clamped_stderr,
				'last_error_code'    => (string) $exit_code,
				'last_error_message' => empty( $clamped_stderr ) ? 'Process exited with non-zero code.' : $clamped_stderr,
				'attempt_count'      => $new_attempts,
				'updated_at'         => $now,
			);

			$wpdb->update( $this->table_queue, $update_data, array( 'id' => $queue_id ) );

			$this->log_agent_event(
				$agent['id'],
				$agent['agent_uuid'],
				$event_type,
				$msg,
				array( 'queue_id' => $queue_id, 'exit_code' => $exit_code ),
				'error'
			);
		}

		// Check and update overall campaign completion status
		$this->check_and_finalize_campaign( (int) $queue_item['campaign_id'] );

		return $this->get_campaign_status_response( (int) $queue_item['campaign_id'] );
	}

	/**
	 * Reset reservations that have expired or are locked in processing.
	 *
	 * @param  int $timeout_seconds Expiry threshold.
	 * @return int                  Number of reset records.
	 */
	public function cleanup_stale_reservations( int $timeout_seconds = 600 ) {
		global $wpdb;

		$threshold = gmdate( 'Y-m-d H:i:s', time() - $timeout_seconds );
		$now = current_time( 'mysql', true );

		// Prefer the explicit UTC reservation deadline. The reserved_at fallback
		// covers legacy rows that predate reservation_expires_at.
		$stale_jobs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, campaign_id, reserved_by_agent_id, reserved_by_agent_uuid 
				 FROM {$this->table_queue} 
				 WHERE status = 'reserved'
				   AND (
				     ( reservation_expires_at IS NOT NULL AND reservation_expires_at < %s )
				     OR ( reservation_expires_at IS NULL AND reserved_at < %s )
				   )",
				$now,
				$threshold
			),
			ARRAY_A
		);

		if ( empty( $stale_jobs ) ) {
			return 0;
		}

		$count = 0;
		$campaign_ids = array();
		foreach ( $stale_jobs as $job ) {
			$wpdb->update(
				$this->table_queue,
				array(
					// Retain the previous owner and reserved_at audit values. A late,
					// durable result from that agent remains valid until another agent
					// actually reserves the row and replaces the owner.
					'status'                 => 'prepared',
					'reservation_expires_at' => null,
					'updated_at'             => $now,
				),
				array( 'id' => $job['id'] )
			);

			$this->log_agent_event(
				$job['reserved_by_agent_id'],
				$job['reserved_by_agent_uuid'],
				'reservation_expired',
				sprintf( 'Reservation for Job ID %d expired and was reset to prepared.', $job['id'] ),
				array( 'queue_id' => $job['id'] ),
				'warning'
			);

			$count++;
			$campaign_ids[] = (int) $job['campaign_id'];
		}

		foreach ( array_unique( $campaign_ids ) as $campaign_id ) {
			$this->check_and_finalize_campaign( $campaign_id );
		}

		return $count;
	}

	/**
	 * Reconcile all sending campaigns whose queue may have reached a terminal state
	 * outside the normal result callback (for example, JIT rendering failures).
	 *
	 * @return int Number of campaigns inspected.
	 */
	public function reconcile_sending_campaigns() {
		global $wpdb;
		$campaign_ids = $wpdb->get_col(
			"SELECT id FROM {$this->table_campaigns} WHERE status = 'sending'"
		);
		foreach ( $campaign_ids as $campaign_id ) {
			$this->check_and_finalize_campaign( (int) $campaign_id );
		}
		return count( $campaign_ids );
	}

	/**
	 * Check if campaign sending is complete and transition its state accordingly.
	 */
	private function check_and_finalize_campaign( int $campaign_id ) {
		global $wpdb;

		// Count items in sending loop states ('prepared', 'reserved', 'retry_wait')
		$pending_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table_queue} 
				 WHERE campaign_id = %d AND status IN ('prepared', 'reserved', 'retry_wait')",
				$campaign_id
			)
		);

		// If no items are pending, the campaign sending is complete
		if ( $pending_count === 0 ) {
			// Check if any errors occurred
			$failed_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$this->table_queue} 
					 WHERE campaign_id = %d AND status = 'failed'",
					$campaign_id
				)
			);

			$new_status = ( $failed_count > 0 ) ? 'completed_with_errors' : 'completed';

			$wpdb->update(
				$this->table_campaigns,
				array(
					'status'       => $new_status,
					'completed_at' => current_time( 'mysql' ),
					'updated_at'   => current_time( 'mysql' ),
				),
				array( 'id' => $campaign_id )
			);

			// Log campaign audit event
			$agent_service = Olama_Messages_Plugin::instance()->agents();
			$agent_service->log_event(
				null,
				'',
				'campaign_completed',
				sprintf( 'Campaign ID %d send process completed. Final status: %s.', $campaign_id, $new_status ),
				array( 'campaign_id' => $campaign_id, 'failed_jobs' => $failed_count ),
				( $new_status === 'completed' ) ? 'info' : 'warning'
			);
		}
	}

	/**
	 * Resolve campaign and REST response parameters.
	 */
	private function get_campaign_status_response( int $campaign_id ) {
		global $wpdb;

		$status = $wpdb->get_var(
			$wpdb->prepare( "SELECT status FROM {$this->table_campaigns} WHERE id = %d", $campaign_id )
		);

		return array(
			'status'          => 'ok',
			'queue_status'    => 'processed',
			'campaign_status' => $status,
		);
	}

	/**
	 * Log helper for agent events.
	 */
	private function log_agent_event( $agent_id, string $agent_uuid, string $event_type, string $message, array $context, string $severity ) {
		$agent_service = Olama_Messages_Plugin::instance()->agents();
		$agent_service->log_event( $agent_id, $agent_uuid, $event_type, $message, $context, $severity );
	}
}
