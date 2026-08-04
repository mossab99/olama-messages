<?php
/**
 * Campaign Service — manages SMS campaign drafting, filtering, and queue generation.
 *
 * @package Olama_Messages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Olama_Messages_Campaign_Service {

	/** @var string campaigns table name */
	private $table_campaigns;

	/** @var string recipients table name */
	private $table_recipients;

	/** @var string queue table name */
	private $table_queue;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->table_campaigns  = $wpdb->prefix . 'olama_msg_campaigns';
		$this->table_recipients = $wpdb->prefix . 'olama_msg_campaign_recipients';
		$this->table_queue      = $wpdb->prefix . 'olama_msg_queue';
	}

	// ─── CRUD Operations ─────────────────────────────────────────────────────

	/**
	 * Create a new campaign.
	 *
	 * @param  array $data Campaign settings.
	 * @return int         The new campaign ID.
	 * @throws Exception   On database insert failure.
	 */
	public function create_campaign( array $data ) {
		global $wpdb;

		$defaults = array(
			'title'                   => '',
			'channel'                 => 'sms',
			'status'                  => 'draft',
			'study_year'              => '',
			'target_type'             => 'collection',
			'template_id'             => null,
			'message_body_draft'      => null,
			'template_name_snapshot'  => null,
			'template_body_snapshot'  => null,
			'filters_json'            => null,
			'recipient_policy'        => 'father_first',
			'min_balance'             => null,
			'exclude_credit_balances' => 1,
			'exclude_zero_balances'   => 1,
			'created_by'              => get_current_user_id(),
			'created_at'              => current_time( 'mysql' ),
		);

		$insert_data = array_merge( $defaults, $data );

		// Format filters_json if it is an array.
		if ( is_array( $insert_data['filters_json'] ) ) {
			$insert_data['filters_json'] = wp_json_encode( $insert_data['filters_json'] );
		}

		$result = $wpdb->insert( $this->table_campaigns, $insert_data );

		if ( false === $result ) {
			throw new Exception( 'Failed to insert campaign: ' . $wpdb->last_error );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update an existing campaign.
	 * Only allowed when campaign is in 'draft' status.
	 *
	 * @param  int   $campaign_id Campaign ID.
	 * @param  array $data        Data to update.
	 * @return bool               True on success, false on failure or if locked.
	 * @throws Exception         If status checks fail.
	 */
	public function update_campaign( int $campaign_id, array $data ) {
		global $wpdb;

		$campaign = $this->get_campaign( $campaign_id );
		if ( ! $campaign ) {
			return false;
		}

		if ( $campaign['status'] !== 'draft' ) {
			throw new Exception( 'Campaign is locked. Only draft campaigns can be updated.' );
		}

		// Remove keys that should not be updated directly.
		unset( $data['id'], $data['status'], $data['created_at'], $data['created_by'] );

		$data['updated_at'] = current_time( 'mysql' );

		if ( isset( $data['filters_json'] ) && is_array( $data['filters_json'] ) ) {
			$data['filters_json'] = wp_json_encode( $data['filters_json'] );
		}

		$result = $wpdb->update(
			$this->table_campaigns,
			$data,
			array( 'id' => $campaign_id )
		);

		return false !== $result;
	}

	/**
	 * Retrieve a campaign.
	 *
	 * @param  int $campaign_id Campaign ID.
	 * @return array|null       Campaign data or null if not found.
	 */
	public function get_campaign( int $campaign_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table_campaigns} WHERE id = %d", $campaign_id ),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		// Type cast numeric values.
		$row['id']                      = (int) $row['id'];
		$row['template_id']             = $row['template_id'] ? (int) $row['template_id'] : null;
		$row['target_type']             = $row['target_type'] ?? 'collection';
		$row['min_balance']             = null !== $row['min_balance'] ? (float) $row['min_balance'] : null;
		$row['exclude_credit_balances'] = (int) $row['exclude_credit_balances'];
		$row['exclude_zero_balances']   = (int) $row['exclude_zero_balances'];
		$row['total_candidates']        = (int) $row['total_candidates'];
		$row['total_included']          = (int) $row['total_included'];
		$row['total_excluded']          = (int) $row['total_excluded'];
		$row['total_prepared']          = (int) $row['total_prepared'];

		if ( $row['filters_json'] ) {
			$row['filters'] = json_decode( $row['filters_json'], true );
		} else {
			$row['filters'] = array();
		}
		$row['core_sync_health'] = ! empty( $row['core_sync_health_json'] )
			? json_decode( $row['core_sync_health_json'], true )
			: array();

		return $row;
	}

	/**
	 * Return the editable message for drafts and the immutable snapshot for
	 * prepared or historical campaigns.
	 *
	 * @param array $campaign Campaign row.
	 * @return string
	 */
	public function get_effective_message_body( array $campaign ) {
		if ( 'draft' !== ( $campaign['status'] ?? 'draft' ) && ! empty( $campaign['template_body_snapshot'] ) ) {
			return (string) $campaign['template_body_snapshot'];
		}

		if ( ! empty( $campaign['message_body_draft'] ) ) {
			return (string) $campaign['message_body_draft'];
		}

		if ( ! empty( $campaign['template_id'] ) ) {
			$template = Olama_Messages_Plugin::instance()->templates()->get_template( (int) $campaign['template_id'] );
			if ( $template && isset( $template['body'] ) ) {
				return (string) $template['body'];
			}
		}

		return ! empty( $campaign['template_body_snapshot'] ) ? (string) $campaign['template_body_snapshot'] : '';
	}

	/**
	 * List campaigns based on filters.
	 *
	 * @param  array $args Query arguments.
	 * @return array       Array of campaigns.
	 */
	public function list_campaigns( array $args = array() ) {
		global $wpdb;

		$defaults = array(
			'status'     => '',
			'study_year' => '',
			'search'     => '',
			'target_type'=> '',
			'limit'      => 50,
			'offset'     => 0,
			'orderby'    => 'created_at',
			'order'      => 'DESC',
		);

		$args = array_merge( $defaults, $args );

		$where  = array();
		$values = array();

		if ( ! empty( $args['status'] ) ) {
			if ( 'completed' === $args['status'] ) {
				$where[] = "status IN ('completed', 'completed_with_errors')";
			} else {
				$where[]  = 'status = %s';
				$values[] = sanitize_text_field( $args['status'] );
			}
		}

		if ( ! empty( $args['study_year'] ) ) {
			$where[]  = 'study_year = %s';
			$values[] = sanitize_text_field( $args['study_year'] );
		}

		$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

		$allowed_cols = array( 'id', 'title', 'status', 'study_year', 'created_at', 'prepared_at' );
		$orderby      = in_array( $args['orderby'], $allowed_cols, true ) ? $args['orderby'] : 'created_at';
		$order        = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

		$query = $wpdb->prepare(
			"SELECT * FROM {$this->table_campaigns} {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
			array_merge( $values, array( absint( $args['limit'] ), absint( $args['offset'] ) ) )
		);

		$rows = $wpdb->get_results( $query, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		foreach ( $rows as &$row ) {
			$row['id']                      = (int) $row['id'];
			$row['template_id']             = $row['template_id'] ? (int) $row['template_id'] : null;
			$row['target_type']             = $row['target_type'] ?? 'collection';
			$row['min_balance']             = null !== $row['min_balance'] ? (float) $row['min_balance'] : null;
			$row['exclude_credit_balances'] = (int) $row['exclude_credit_balances'];
			$row['exclude_zero_balances']   = (int) $row['exclude_zero_balances'];
			$row['total_candidates']        = (int) $row['total_candidates'];
			$row['total_included']          = (int) $row['total_included'];
			$row['total_excluded']          = (int) $row['total_excluded'];
			$row['total_prepared']          = (int) $row['total_prepared'];
			$row['filters']                 = $row['filters_json'] ? json_decode( $row['filters_json'], true ) : array();
			$row['core_sync_health']        = ! empty( $row['core_sync_health_json'] ) ? json_decode( $row['core_sync_health_json'], true ) : array();
		}

		if ( ! empty( $args['target_type'] ) ) {
			$where[]  = 'target_type = %s';
			$values[] = sanitize_key( $args['target_type'] );
		}

		if ( ! empty( $args['search'] ) ) {
			$where[]  = 'title LIKE %s';
			$values[] = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
		}

		return $rows;
	}

	/**
	 * Delete a campaign.
	 * Only allowed for draft campaigns.
	 *
	 * @param  int $campaign_id Campaign ID.
	 * @return bool             True on success, false on failure or if locked.
	 * @throws Exception       If campaign status is not draft.
	 */
	public function delete_campaign( int $campaign_id ) {
		global $wpdb;

		$campaign = $this->get_campaign( $campaign_id );
		if ( ! $campaign ) {
			return false;
		}

		if ( $campaign['status'] !== 'draft' ) {
			throw new Exception( 'Only draft campaigns can be deleted.' );
		}

		$wpdb->query( 'START TRANSACTION' );
		try {
			$wpdb->delete( $this->table_queue, array( 'campaign_id' => $campaign_id ) );
			$wpdb->delete( $this->table_recipients, array( 'campaign_id' => $campaign_id ) );
			$wpdb->delete( $this->table_campaigns, array( 'id' => $campaign_id ) );

			$wpdb->query( 'COMMIT' );
			return true;
		} catch ( Exception $e ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
	}

	/**
	 * Create a direct SMS campaign for a specific family parent.
	 *
	 * @param  int    $family_id      Family ID.
	 * @param  string $recipient_role 'father' or 'mother'.
	 * @param  string $message_body   SMS message content.
	 * @param  string $study_year     Optional study year.
	 * @return int                    The new campaign ID.
	 * @throws Exception              If parameters, validation, normalization, duplicate check, or database fails.
	 */
	public function create_direct_message_campaign( $family_id, $recipient_role, $message_body, $study_year = '' ) {
		global $wpdb;

		// 1. Parameter Validation
		if ( ! $family_id || ! in_array( $recipient_role, array( 'father', 'mother' ), true ) || empty( $message_body ) ) {
			throw new Exception( __( 'Invalid parameters for direct campaign.', 'olama-messages' ) );
		}

		if ( ! $study_year ) {
			$study_year = Olama_Messages_Plugin::instance()->provider()->get_current_study_year();
		}

		// 2. Fetch family recipient details using Core Provider
		$res = Olama_Messages_Plugin::instance()->provider()->get_recipients_preview( array(
			'family_id'  => $family_id,
			'study_year' => $study_year,
			'limit'      => 1,
		) );
		if ( empty( $res['items'] ) ) {
			throw new Exception( __( 'Family not found.', 'olama-messages' ) );
		}
		$family = $res['items'][0];

		$phone_raw = ( $recipient_role === 'father' ) ? $family['father_mobile'] : $family['mother_mobile'];
		$recipient_name = ( $recipient_role === 'father' ) ? ( $family['father_name'] ?: $family['sponsor_name'] ) : ( $family['mother_name'] ?: $family['sponsor_name'] );

		if ( empty( trim( (string) $phone_raw ) ) ) {
			throw new Exception( sprintf( __( 'The selected parent (%s) does not have a mobile number in the database.', 'olama-messages' ), $recipient_role ) );
		}

		// 3. Normalize mobile number
		$norm = Olama_Messages_Plugin::instance()->normalizer()->normalize_jordan_mobile( $phone_raw );
		if ( ! $norm['valid'] ) {
			throw new Exception( sprintf( __( 'Invalid phone number for %s: %s (%s)', 'olama-messages' ), $recipient_role, $phone_raw, $norm['reason'] ) );
		}
		$phone_e164 = $norm['e164'];

		// 4. Duplicate protection check with normalized message body hash
		$normalized_body = trim( preg_replace( '/\s+/', ' ', $message_body ) );
		$message_body_hash = hash( 'sha256', $normalized_body );
		$five_minutes_ago = date( 'Y-m-d H:i:s', time() - 300 );

		$duplicate_exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) 
			 FROM {$this->table_queue} q
			 JOIN {$this->table_campaigns} c ON q.campaign_id = c.id
			 WHERE q.family_id = %d
			   AND q.phone_e164 = %s
			   AND q.message_body_hash = %s
			   AND ( q.status IN ('prepared', 'reserved', 'retry_wait') OR c.status IN ('sending', 'prepared') )
			   AND q.created_at >= %s",
			$family_id,
			$phone_e164,
			$message_body_hash,
			$five_minutes_ago
		) );

		if ( $duplicate_exists > 0 ) {
			throw new Exception( __( 'A duplicate direct SMS for this parent was already queued or sent within the last 5 minutes. Please wait before resending.', 'olama-messages' ) );
		}

		// 5. Database Transaction
		$wpdb->query( 'START TRANSACTION' );

		try {
			// Direct message metadata
			$metadata = array(
				'type'      => 'direct',
				'family_id' => $family_id,
				'recipient' => $recipient_role,
			);

			$campaign_data = array(
				'title'            => sprintf( 'Direct Message - Family %d (%s)', $family_id, $recipient_role ),
				'channel'          => 'sms',
				'status'           => 'prepared',
				'study_year'       => $study_year,
				'target_type'      => 'direct',
				'message_body_draft' => $message_body,
				'template_name_snapshot' => 'Direct message',
				'template_body_snapshot' => $message_body,
				'filters_json'     => wp_json_encode( $metadata ),
				'recipient_policy' => $recipient_role === 'father' ? 'father_only' : 'mother_only',
				'total_candidates' => 1,
				'total_included'   => 1,
				'total_excluded'   => 0,
				'total_prepared'   => 1,
				'created_by'       => get_current_user_id(),
				'created_at'       => current_time( 'mysql' ),
				'prepared_at'      => current_time( 'mysql' ),
				'prepared_by'      => get_current_user_id(),
			);

			$campaign_inserted = $wpdb->insert( $this->table_campaigns, $campaign_data );
			if ( false === $campaign_inserted ) {
				throw new Exception( $wpdb->last_error ?: __( 'Failed to create campaign record.', 'olama-messages' ) );
			}
			$campaign_id = (int) $wpdb->insert_id;

			// Recipient Snapshot
			$recipient_data = array(
				'campaign_id'         => $campaign_id,
				'family_id'           => $family_id,
				'oracle_family_id'    => $family['oracle_family_id'] ?? (string) $family_id,
				'recipient_type'      => $recipient_role,
				'recipient_name'      => $recipient_name,
				'phone_raw'           => $phone_raw,
				'phone_e164'          => $phone_e164,
				'sponsor_name'        => $family['sponsor_name'],
				'father_name'         => $family['father_name'],
				'father_mobile'       => $family['father_mobile'],
				'mother_name'         => $family['mother_name'],
				'mother_mobile'       => $family['mother_mobile'],
				'students_json'       => wp_json_encode( $family['students'] ),
				'student_rows_json'   => wp_json_encode( $family['student_rows'] ),
				'balance'             => $family['balance'],
				'monthly_due'         => $family['monthly_due'],
				'monthly_due_source'  => $family['monthly_due_source'],
				'currency'            => 'JOD',
				'financial_available' => $family['financial_available'] ? 1 : 0,
				'included'            => 1,
				'created_at'          => current_time( 'mysql' ),
			);

			$recipient_inserted = $wpdb->insert( $this->table_recipients, $recipient_data );
			if ( false === $recipient_inserted ) {
				throw new Exception( $wpdb->last_error ?: __( 'Failed to create campaign recipient snapshot.', 'olama-messages' ) );
			}
			$recipient_db_id = (int) $wpdb->insert_id;

			// SMS metrics calculation
			$sms_info = Olama_Messages_Plugin::instance()->renderer()->sms_info( $message_body );
			$requires_payment_link = ( false !== strpos( $message_body, '{payment_link}' ) ) ? 1 : 0;

			// Queue Item
			$queue_data = array(
				'campaign_id'           => $campaign_id,
				'campaign_recipient_id' => $recipient_db_id,
				'family_id'             => $family_id,
				'channel'               => 'sms',
				'phone_e164'            => $phone_e164,
				'message_body_preview'  => $message_body,
				'message_body_hash'     => $message_body_hash,
				'message_char_count'    => $sms_info['char_count'],
				'message_sms_parts'     => $sms_info['sms_parts'],
				'requires_payment_link' => $requires_payment_link,
				'status'                => 'prepared',
				'created_at'            => current_time( 'mysql' ),
			);

			$queue_inserted = $wpdb->insert( $this->table_queue, $queue_data );
			if ( false === $queue_inserted ) {
				throw new Exception( $wpdb->last_error ?: __( 'Failed to create queue record.', 'olama-messages' ) );
			}

			$wpdb->query( 'COMMIT' );
			return $campaign_id;

		} catch ( Exception $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}
	}

	/**
	 * Explicitly named safe direct-message preparation entry point.
	 */
	public function prepare_direct_message_campaign( $family_id, $recipient_role, $message_body, $study_year = '' ) {
		return $this->create_direct_message_campaign( $family_id, $recipient_role, $message_body, $study_year );
	}


	// ─── Candidate Evaluation & Previews ─────────────────────────────────────

	/**
	 * Previews candidates based on campaign settings, applying all filters and exclusion rules.
	 *
	 * Supports passing a campaign ID or an array of campaign configuration parameters.
	 *
	 * @param  int|array $campaign_id_or_data Campaign ID or array of configuration data.
	 * @param  array     $args                Pagination and other options (limit, offset).
	 * @return array                          Array of preview metrics and evaluated targets.
	 * @throws Exception                      On invalid input or missing template.
	 */
	public function preview_candidates( $campaign_id_or_data, array $args = array() ) {
		$campaign = array();

		if ( is_numeric( $campaign_id_or_data ) ) {
			$campaign = $this->get_campaign( (int) $campaign_id_or_data );
			if ( ! $campaign ) {
				throw new Exception( 'Campaign not found.' );
			}
		} elseif ( is_array( $campaign_id_or_data ) ) {
			$defaults = array(
				'study_year'              => '',
				'target_type'             => 'collection',
				'template_id'             => null,
				'message_body_draft'      => null,
				'template_body_snapshot'  => null,
				'recipient_policy'        => 'father_first',
				'min_balance'             => null,
				'exclude_credit_balances' => 1,
				'exclude_zero_balances'   => 1,
				'filters'                 => array(),
			);
			$campaign = array_merge( $defaults, $campaign_id_or_data );
		} else {
			throw new Exception( 'Invalid campaign parameters provided.' );
		}

		$study_year = $campaign['study_year'];
		$target_type = $campaign['target_type'] ?? 'collection';
		if ( empty( $study_year ) ) {
			$study_year = Olama_Messages_Plugin::instance()->provider()->get_current_study_year();
		}

		$template_body = $this->get_effective_message_body( $campaign );
		if ( '' === trim( $template_body ) ) {
			throw new Exception( 'Add a message before previewing the audience.' );
		}

		// Extract filters (class_name, section_name, family_id, transport fields).
		$filters = isset( $campaign['filters'] ) && is_array( $campaign['filters'] ) ? $campaign['filters'] : array();

		// ─── Route new classified audience types ─────────────────────────────────
		// New types return a flat array of family items directly. Existing legacy
		// types (collection, general, transportation) use the Core provider loop.
		$new_audience_types = array(
			'finance_outstanding',
			'transport_no_gps',
			'transport_registered',
			'store_missing',
			'academic',
		);

		$all_items = array();

		if ( in_array( $target_type, $new_audience_types, true ) ) {
			$all_items = $this->fetch_classified_audience( $target_type, $study_year, $filters );
		} else {
			// Legacy: fetch from Olama Core in chunks.
			$chunk_size = 200;
			$offset     = 0;
			$provider   = Olama_Messages_Plugin::instance()->provider();
			$page_signatures = array();
			$page_count      = 0;

			while ( $page_count < 100 ) {
				$page_count++;
				$query_filters = array(
					'study_year'  => $study_year,
					'target_type' => $target_type,
					'limit'       => $chunk_size,
					'offset'      => $offset,
				);

				if ( ! empty( $filters['family_id'] ) ) {
					$query_filters['family_id'] = $filters['family_id'];
				}
				if ( ! empty( $filters['class_name'] ) && 'transportation' !== $target_type ) {
					$query_filters['class_name'] = $filters['class_name'];
				}
				if ( ! empty( $filters['section_name'] ) && 'transportation' !== $target_type ) {
					$query_filters['section_name'] = $filters['section_name'];
				}
				if ( ! empty( $filters['class_id'] ) ) {
					$query_filters['class_id'] = $filters['class_id'];
				}
				if ( ! empty( $filters['section_id'] ) ) {
					$query_filters['section_id'] = $filters['section_id'];
				}
				if ( ! empty( $filters['class_name'] ) && 'general' === $target_type ) {
					$query_filters['class_name'] = $filters['class_name'];
				}
				if ( ! empty( $filters['section_name'] ) && 'general' === $target_type ) {
					$query_filters['section_name'] = $filters['section_name'];
				}
				if ( ! empty( $filters['bus_name'] ) && 'transportation' === $target_type ) {
					$query_filters['bus_name'] = $filters['bus_name'];
				}
				if ( ! empty( $filters['round_name'] ) && 'transportation' === $target_type ) {
					$query_filters['round_name'] = $filters['round_name'];
				}
				if ( ! empty( $filters['departure_bus'] ) && 'transportation' === $target_type ) {
					$query_filters['departure_bus'] = $filters['departure_bus'];
				}
				if ( ! empty( $filters['arrival_bus'] ) && 'transportation' === $target_type ) {
					$query_filters['arrival_bus'] = $filters['arrival_bus'];
				}

				$res = $provider->get_recipients_preview( $query_filters );
				if ( empty( $res['items'] ) ) {
					break;
				}
				$signature = md5( wp_json_encode( array_map( static function ( $row ) {
					return $row['oracle_family_id'] ?? $row['family_id'] ?? null;
				}, $res['items'] ) ) );
				if ( isset( $page_signatures[ $signature ] ) ) {
					break;
				}
				$page_signatures[ $signature ] = true;

				$all_items = array_merge( $all_items, $res['items'] );

				// Some providers enforce a smaller page size than requested. Advance by
				// the rows actually received so no families are skipped.
				$offset += count( $res['items'] );
			}
		}

		$evaluated_targets = array();
		$seen_phones       = array(); // Track duplicates.

		foreach ( $all_items as $item ) {
			$family_id           = (int) $item['family_id'];
			$oracle_family_id    = $item['oracle_family_id'] ?? (string) $family_id;
			$core_family_uid     = (string) ( $item['core_family_uid'] ?? '' );
			$core_source_hash    = (string) ( $item['core_source_hash'] ?? '' );
			$core_last_synced_at = $item['core_last_synced_at'] ?? null;
			$sponsor_name        = $item['sponsor_name'] ?? '';
			$father_name         = $item['father_name'] ?? '';
			$father_mobile       = $item['father_mobile'] ?? '';
			$mother_name         = $item['mother_name'] ?? '';
			$mother_mobile       = $item['mother_mobile'] ?? '';
			$students            = $item['students'] ?? array();
			$student_rows        = $item['student_rows'] ?? array();
			$balance             = isset( $item['balance'] ) && null !== $item['balance'] ? (float) $item['balance'] : null;
			$monthly_due         = isset( $item['monthly_due'] ) && null !== $item['monthly_due'] ? (float) $item['monthly_due'] : null;
			$monthly_due_source  = $item['monthly_due_source'] ?? 'unavailable';
			$financial_available = ! empty( $item['financial_available'] );

			// Resolve targets based on policy.
			$targets = array();
			$policy  = $campaign['recipient_policy'] ?? 'father_first';

			if ( $policy === 'father_only' ) {
				$targets[] = array(
					'type'  => 'father',
					'name'  => $father_name ? $father_name : $sponsor_name,
					'phone' => $father_mobile,
				);
			} elseif ( $policy === 'mother_only' ) {
				$targets[] = array(
					'type'  => 'mother',
					'name'  => $mother_name ? $mother_name : $sponsor_name,
					'phone' => $mother_mobile,
				);
			} elseif ( $policy === 'father_first' ) {
				$norm = Olama_Messages_Plugin::instance()->normalizer()->normalize_jordan_mobile( $father_mobile );
				if ( $norm['valid'] ) {
					$targets[] = array(
						'type'  => 'father',
						'name'  => $father_name ? $father_name : $sponsor_name,
						'phone' => $father_mobile,
						'norm'  => $norm,
					);
				} else {
					$norm_mother = Olama_Messages_Plugin::instance()->normalizer()->normalize_jordan_mobile( $mother_mobile );
					if ( $norm_mother['valid'] ) {
						$targets[] = array(
							'type'  => 'mother',
							'name'  => $mother_name ? $mother_name : $sponsor_name,
							'phone' => $mother_mobile,
							'norm'  => $norm_mother,
						);
					} else {
						// Default to father to report the normalization error.
						$targets[] = array(
							'type'  => 'father',
							'name'  => $father_name ? $father_name : $sponsor_name,
							'phone' => $father_mobile,
							'norm'  => $norm,
						);
					}
				}
			} elseif ( $policy === 'mother_first' ) {
				$norm = Olama_Messages_Plugin::instance()->normalizer()->normalize_jordan_mobile( $mother_mobile );
				if ( $norm['valid'] ) {
					$targets[] = array(
						'type'  => 'mother',
						'name'  => $mother_name ? $mother_name : $sponsor_name,
						'phone' => $mother_mobile,
						'norm'  => $norm,
					);
				} else {
					$norm_father = Olama_Messages_Plugin::instance()->normalizer()->normalize_jordan_mobile( $father_mobile );
					if ( $norm_father['valid'] ) {
						$targets[] = array(
							'type'  => 'father',
							'name'  => $father_name ? $father_name : $sponsor_name,
							'phone' => $father_mobile,
							'norm'  => $norm_father,
						);
					} else {
						$targets[] = array(
							'type'  => 'mother',
							'name'  => $mother_name ? $mother_name : $sponsor_name,
							'phone' => $mother_mobile,
							'norm'  => $norm,
						);
					}
				}
			} elseif ( in_array( $policy, array( 'both', 'both_parents' ), true ) ) {
				// Keep both requested parent slots in the evaluation. Empty
				// numbers are visible as excluded targets and can never silently
				// disappear from the campaign totals.
				$targets[] = array(
					'type'  => 'father',
					'name'  => $father_name ? $father_name : $sponsor_name,
					'phone' => $father_mobile,
				);
				$targets[] = array(
					'type'  => 'mother',
					'name'  => $mother_name ? $mother_name : $sponsor_name,
					'phone' => $mother_mobile,
				);
			}

			// Evaluate each target against financial rules and phone policy.
			foreach ( $targets as $target ) {
				$included        = true;
				$excluded_reason = null;

				// 1. Check financial rules (Rule 7: financial reminders require financial data).
				$req_finance = 'collection' === $target_type
					&& ( ( isset( $campaign['min_balance'] ) && $campaign['min_balance'] !== '' )
					|| ! empty( $campaign['exclude_credit_balances'] )
					|| ! empty( $campaign['exclude_zero_balances'] ) );

				if ( $req_finance && ! $financial_available ) {
					$included        = false;
					$excluded_reason = 'financial_unavailable';
				} elseif ( $financial_available ) {
					if ( ! empty( $campaign['exclude_credit_balances'] ) && $balance < 0 ) {
						$included        = false;
						$excluded_reason = 'credit_balance';
					} elseif ( ! empty( $campaign['exclude_zero_balances'] ) && $balance == 0 ) {
						$included        = false;
						$excluded_reason = 'zero_balance';
					} elseif ( isset( $campaign['min_balance'] ) && $campaign['min_balance'] !== '' && $balance < (float) $campaign['min_balance'] ) {
						$included        = false;
						$excluded_reason = 'below_min_balance';
					}
				}

				// 2. Phone normalization.
				$phone_raw  = $target['phone'];
				$phone_e164 = null;

				if ( $included ) {
					$norm = isset( $target['norm'] ) ? $target['norm'] : Olama_Messages_Plugin::instance()->normalizer()->normalize_jordan_mobile( $phone_raw );
					if ( ! $norm['valid'] ) {
						$included = false;
						if ( $norm['reason'] === 'empty_phone' ) {
							$excluded_reason = 'missing_phone';
						} elseif ( $norm['reason'] === 'landline_rejected' ) {
							$excluded_reason = 'landline_rejected';
						} elseif ( $norm['reason'] === 'international_phone' ) {
							$excluded_reason = 'international_phone';
						} else {
							$excluded_reason = 'invalid_phone';
						}
					} else {
						$phone_e164 = $norm['e164'];
					}
				}

				// 3. Duplicate phone protection.
				if ( $included && $phone_e164 ) {
					if ( isset( $seen_phones[ $phone_e164 ] ) ) {
						$included        = false;
						$excluded_reason = 'duplicate_phone';
					} else {
						$seen_phones[ $phone_e164 ] = true;
					}
				}

				// Render SMS preview.
				$message_body_preview = '';
				$char_count           = 0;
				$sms_parts            = 0;

				$bal_fmt = is_numeric( $balance ) ? number_format( $balance, 3 ) : 'غير متوفر';
				$due_fmt = is_numeric( $monthly_due ) ? number_format( $monthly_due, 3 ) : 'غير متوفر';

				$vars = array(
					'sponsor_name'       => $target['name'],
					'family_id'          => $oracle_family_id,
					'students'           => $students,
					'balance'            => $bal_fmt,
					'monthly_due'        => $due_fmt,
					'monthly_due_source' => $monthly_due_source,
				);

				$message_body_preview = Olama_Messages_Plugin::instance()->renderer()->render_campaign_sms( $template_body, $vars );
				$sms_info             = Olama_Messages_Plugin::instance()->renderer()->sms_info( $message_body_preview );
				$char_count           = $sms_info['char_count'];
				$sms_parts            = $sms_info['sms_parts'];

				// Apply saved operator choices after normal eligibility/template evaluation.
				$override_key = $oracle_family_id . ':' . $target['type'];
				$overrides    = $filters['recipient_overrides'] ?? array();
				$override     = isset( $overrides[ $override_key ] ) && is_array( $overrides[ $override_key ] )
					? $overrides[ $override_key ]
					: array();
				if ( $included && isset( $override['message'] ) && trim( $override['message'] ) !== '' ) {
					$message_body_preview = $override['message'];
					$sms_info             = Olama_Messages_Plugin::instance()->renderer()->sms_info( $message_body_preview );
					$char_count           = $sms_info['char_count'];
					$sms_parts            = $sms_info['sms_parts'];
				}
				if ( $included && ! empty( $override['excluded'] ) ) {
					$included        = false;
					$excluded_reason = 'manually_excluded';
				}

				$evaluated_targets[] = array(
					'family_id'            => $family_id,
					'oracle_family_id'     => $oracle_family_id,
					'core_family_uid'      => $core_family_uid,
					'core_source_hash'     => $core_source_hash,
					'core_last_synced_at'  => $core_last_synced_at,
					'recipient_type'       => $target['type'],
					'recipient_name'       => $target['name'],
					'phone_raw'            => $phone_raw,
					'phone_e164'           => $phone_e164,
					'sponsor_name'         => $sponsor_name,
					'father_name'          => $father_name,
					'father_mobile'        => $father_mobile,
					'mother_name'          => $mother_name,
					'mother_mobile'        => $mother_mobile,
					'students_json'        => wp_json_encode( $students ),
					'student_rows_json'    => wp_json_encode( $student_rows ),
					'balance'              => $balance,
					'monthly_due'          => $monthly_due,
					'monthly_due_source'   => $monthly_due_source,
					'financial_available'  => $financial_available ? 1 : 0,
					'included'             => $included ? 1 : 0,
					'excluded_reason'      => $excluded_reason,
					'message_body_preview' => $message_body_preview,
					'char_count'           => $char_count,
					'sms_parts'            => $sms_parts,
				);
			}
		}

		$total_candidates = count( $evaluated_targets );
		$total_included   = 0;
		$total_excluded   = 0;
		$total_sms_parts  = 0;
		$reason_counts    = array();
		$family_ids       = array();
		$included_family_ids = array();

		foreach ( $evaluated_targets as $t ) {
			$family_key = (string) ( $t['oracle_family_id'] ?: $t['family_id'] );
			$family_ids[ $family_key ] = true;
			if ( $t['included'] ) {
				$total_included++;
				$total_sms_parts += absint( $t['sms_parts'] ?? 0 );
				$included_family_ids[ $family_key ] = true;
			} else {
				$total_excluded++;
				$reason = $t['excluded_reason'] ?: 'other';
				$reason_counts[ $reason ] = ( $reason_counts[ $reason ] ?? 0 ) + 1;
			}
		}

		// Preview-only display controls are applied before pagination. Campaign
		// preparation omits these args and always receives the complete list.
		$display_targets = $evaluated_targets;
		if ( isset( $args['show_excluded'] ) && ! $args['show_excluded'] ) {
			$display_targets = array_values( array_filter( $display_targets, static function ( $target ) {
				return ! empty( $target['included'] );
			} ) );
		}

		$sort_field = $args['sort_field'] ?? 'family_id';
		$sort_order = $args['sort_order'] ?? 'asc';
		if ( in_array( $sort_field, array( 'family_id', 'recipient', 'balance' ), true ) && in_array( $sort_order, array( 'asc', 'desc' ), true ) ) {
			usort( $display_targets, static function ( $a, $b ) use ( $sort_field, $sort_order ) {
				if ( 'balance' === $sort_field ) {
					$a_value = is_numeric( $a['balance'] ) ? (float) $a['balance'] : null;
					$b_value = is_numeric( $b['balance'] ) ? (float) $b['balance'] : null;
					if ( null === $a_value && null === $b_value ) return 0;
					if ( null === $a_value ) return 1;
					if ( null === $b_value ) return -1;
					$result = $a_value <=> $b_value;
				} elseif ( 'recipient' === $sort_field ) {
					$result = strnatcasecmp( (string) $a['recipient_name'], (string) $b['recipient_name'] );
				} else {
					$result = strnatcasecmp( (string) $a['oracle_family_id'], (string) $b['oracle_family_id'] );
				}
				return 'desc' === $sort_order ? -$result : $result;
			} );
		}

		// Paginate the filtered/sorted in-memory array for the response.
		$paginated_targets = $display_targets;
		if ( isset( $args['limit'] ) ) {
			$limit             = absint( $args['limit'] );
			$offset            = isset( $args['offset'] ) ? absint( $args['offset'] ) : 0;
			$paginated_targets = array_slice( $display_targets, $offset, $limit );
		}

		return array(
			'items'            => $paginated_targets,
			'total_candidates' => $total_candidates,
			'total_families'   => count( $family_ids ),
			'included_families'=> count( $included_family_ids ),
			'total_included'   => $total_included,
			'total_excluded'   => $total_excluded,
			'total_sms_parts'  => $total_sms_parts,
			'total_displayed'  => count( $display_targets ),
			'reason_counts'    => $reason_counts,
		);
	}

	// ─── Campaign Preparation (Idempotence & Queue Generation) ──────────────

	/**
	 * Prepare a campaign by snapshotting templates, evaluating targets, and
	 * writing them to the recipients and queue tables.
	 *
	 * Only draft campaigns can be prepared. This locks the campaign status to 'prepared'.
	 *
	 * @param  int $campaign_id Campaign ID.
	 * @return array            Status metrics of the prepared campaign.
	 * @throws Exception        On validation failures, state lock violations, or DB errors.
	 */
	public function prepare_campaign( int $campaign_id ) {
		global $wpdb;

		// 1. Fetch campaign and verify status.
		$campaign = $this->get_campaign( $campaign_id );
		if ( ! $campaign ) {
			throw new Exception( 'Campaign not found.' );
		}

		if ( $campaign['status'] !== 'draft' ) {
			throw new Exception( 'Campaign is not in draft status. Prepared or cancelled campaigns cannot be prepared again.' );
		}

		// 2. Resolve and validate the editable message. Preparation freezes it.
		$template_body = $this->get_effective_message_body( $campaign );
		if ( '' === trim( $template_body ) ) {
			throw new Exception( 'Add a message before preparing this campaign.' );
		}
		$placeholder_check = Olama_Messages_Plugin::instance()->renderer()->validate_placeholders( $template_body );
		if ( empty( $placeholder_check['valid'] ) ) {
			throw new Exception( 'Unknown message fields: {' . implode( '}, {', $placeholder_check['unknown'] ) . '}' );
		}

		$template_name = 'Custom campaign message';
		if ( ! empty( $campaign['template_id'] ) ) {
			$template = Olama_Messages_Plugin::instance()->templates()->get_template( (int) $campaign['template_id'] );
			if ( $template ) {
				$template_name = $template['name'];
			}
		}

		// 3. Verify target-specific Olama Core data before taking the immutable
		// recipient and message snapshots.
		$sync_health = Olama_Messages_Plugin::instance()->provider()->get_sync_health(
			$campaign['target_type'] ?? 'collection',
			$campaign['study_year'] ?? ''
		);
		if ( empty( $sync_health['ready'] ) ) {
			throw new Exception( 'Olama Core data is not ready for this campaign and study year. Synchronize the required Core sources before preparing.' );
		}

		// 4. Evaluate all candidates in memory.
		$results = $this->preview_candidates( $campaign );

		// 5. Write to DB using transaction wrapper.
		$wpdb->query( 'START TRANSACTION' );

		try {
			// Clear any existing snapshots/queue records for this campaign (idempotency check).
			$wpdb->delete( $this->table_recipients, array( 'campaign_id' => $campaign_id ) );
			$wpdb->delete( $this->table_queue, array( 'campaign_id' => $campaign_id ) );

			// Insert recipients and queue items.
			$total_candidates = 0;
			$total_included   = 0;
			$total_excluded   = 0;

			// Use the full list of evaluated targets (no pagination applied).
			$all_targets = $results['items'];

			foreach ( $all_targets as $t ) {
				$total_candidates++;

				// Final queue invariant: only a non-empty Jordanian mobile may
				// ever become a prepared queue item, even if preview logic or a
				// saved snapshot is changed in the future.
				if ( ! empty( $t['included'] ) ) {
					$queue_phone = Olama_Messages_Plugin::instance()->normalizer()->normalize_jordan_mobile( (string) ( $t['phone_e164'] ?? '' ) );
					if ( empty( $queue_phone['valid'] ) ) {
						$t['included'] = 0;
						if ( 'empty_phone' === ( $queue_phone['reason'] ?? '' ) ) {
							$t['excluded_reason'] = 'missing_phone';
						} elseif ( 'international_phone' === ( $queue_phone['reason'] ?? '' ) ) {
							$t['excluded_reason'] = 'international_phone';
						} elseif ( 'landline_rejected' === ( $queue_phone['reason'] ?? '' ) ) {
							$t['excluded_reason'] = 'landline_rejected';
						} else {
							$t['excluded_reason'] = 'invalid_phone';
						}
						$t['phone_e164'] = null;
					} else {
						$t['phone_e164'] = $queue_phone['e164'];
					}
				}

				// Insert recipient snapshot.
				$recipient_data = array(
					'campaign_id'         => $campaign_id,
					'family_id'           => $t['family_id'],
					'oracle_family_id'    => $t['oracle_family_id'],
					'core_family_uid'     => $t['core_family_uid'],
					'core_source_hash'    => $t['core_source_hash'],
					'core_last_synced_at' => $t['core_last_synced_at'],
					'recipient_type'      => $t['recipient_type'],
					'recipient_name'      => $t['recipient_name'],
					'phone_raw'           => $t['phone_raw'],
					'phone_e164'          => $t['phone_e164'],
					'sponsor_name'        => $t['sponsor_name'],
					'father_name'         => $t['father_name'],
					'father_mobile'       => $t['father_mobile'],
					'mother_name'         => $t['mother_name'],
					'mother_mobile'       => $t['mother_mobile'],
					'students_json'       => $t['students_json'],
					'student_rows_json'   => $t['student_rows_json'],
					'balance'             => $t['balance'],
					'monthly_due'         => $t['monthly_due'],
					'monthly_due_source'  => $t['monthly_due_source'],
					'currency'            => 'JOD',
					'financial_available' => $t['financial_available'],
					'included'            => $t['included'],
					'excluded_reason'     => $t['excluded_reason'],
					'created_at'          => current_time( 'mysql' ),
				);

				$inserted_recipient = $wpdb->insert( $this->table_recipients, $recipient_data );
				if ( false === $inserted_recipient ) {
					throw new Exception( 'Failed to insert recipient snapshot: ' . $wpdb->last_error );
				}

				$recipient_id = (int) $wpdb->insert_id;

				if ( $t['included'] ) {
					$total_included++;

					// Insert prepared queue record.
					$queue_data = array(
						'campaign_id'           => $campaign_id,
						'campaign_recipient_id' => $recipient_id,
						'family_id'             => $t['family_id'],
						'channel'               => 'sms',
						'phone_e164'            => $t['phone_e164'],
						'message_body_preview'  => $t['message_body_preview'],
						'message_body_hash'     => hash( 'sha256', $t['message_body_preview'] ),
						'message_char_count'    => $t['char_count'],
						'message_sms_parts'     => $t['sms_parts'],
						'requires_payment_link' => false !== strpos( $template_body, '{payment_link}' ) ? 1 : 0,
						'payment_token_id'      => null,
						'status'                => 'prepared',
						'created_at'            => current_time( 'mysql' ),
					);

					$inserted_queue = $wpdb->insert( $this->table_queue, $queue_data );
					if ( false === $inserted_queue ) {
						throw new Exception( 'Failed to insert prepared queue record: ' . $wpdb->last_error );
					}
				} else {
					$total_excluded++;
				}
			}

			// Update campaign status, snapshot details, and counts.
			$campaign_update = array(
				'status'                 => 'prepared',
				'template_name_snapshot' => $template_name,
				'template_body_snapshot' => $template_body,
				'total_candidates'       => $total_candidates,
				'total_included'         => $total_included,
				'total_excluded'         => $total_excluded,
				'total_prepared'         => $total_included,
				'core_snapshot_at'       => current_time( 'mysql' ),
				'core_sync_health_json'  => wp_json_encode( $sync_health ),
				'prepared_at'            => current_time( 'mysql' ),
				'prepared_by'            => get_current_user_id(),
				'updated_at'             => current_time( 'mysql' ),
			);

			$updated_campaign = $wpdb->update(
				$this->table_campaigns,
				$campaign_update,
				array( 'id' => $campaign_id )
			);

			if ( false === $updated_campaign ) {
				throw new Exception( 'Failed to update campaign status: ' . $wpdb->last_error );
			}

			$wpdb->query( 'COMMIT' );

			return array(
				'success'          => true,
				'total_candidates' => $total_candidates,
				'total_prepared'   => $total_included,
				'total_excluded'   => $total_excluded,
			);

		} catch ( Exception $e ) {
			$wpdb->query( 'ROLLBACK' );

			// Manual cleanup in case the storage engine did not roll back transactions.
			$wpdb->delete( $this->table_queue, array( 'campaign_id' => $campaign_id ) );
			$wpdb->delete( $this->table_recipients, array( 'campaign_id' => $campaign_id ) );

			throw $e;
		}
	}

	// ─── Sending Lifecycle Operations (Phase 4) ───────────────────────────────

	/**
	 * Start sending a prepared campaign.
	 *
	 * Transitions campaign status from 'prepared' to 'sending'.
	 *
	 * @param  int $campaign_id Campaign ID.
	 * @return bool             True on success.
	 * @throws Exception        If campaign is not in 'prepared' status.
	 */
	public function start_campaign_sending( int $campaign_id ) {
		throw new Exception( 'Direct campaign starts are disabled. Use authorize_campaign_sending() with typed confirmation.' );
	}

	/**
	 * Authorize a prepared campaign after revalidating the immutable queue,
	 * typed confirmation, and live dispatcher readiness.
	 *
	 * @param int    $campaign_id Campaign ID.
	 * @param string $confirmation Typed "SEND {count}" phrase.
	 * @return bool
	 */
	public function authorize_campaign_sending( int $campaign_id, $confirmation ) {
		global $wpdb;

		$campaign = $this->get_campaign( $campaign_id );
		if ( ! $campaign || 'prepared' !== $campaign['status'] ) {
			throw new Exception( 'Only a prepared campaign can be authorized.' );
		}

		$prepared_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table_queue} WHERE campaign_id = %d AND status = 'prepared'",
				$campaign_id
			)
		);
		if ( $prepared_count < 1 || $prepared_count !== (int) $campaign['total_prepared'] ) {
			throw new Exception( 'Prepared queue verification failed. Reset and prepare the campaign again.' );
		}

		if ( 'SEND ' . $prepared_count !== trim( (string) $confirmation ) ) {
			throw new Exception( sprintf( 'Type SEND %d exactly to authorize this campaign.', $prepared_count ) );
		}

		$agent = Olama_Messages_Plugin::instance()->agents()->get_ready_dispatcher_agent();
		if ( empty( $agent ) ) {
			throw new Exception( 'No ready sending agent is online. Preparation is preserved; start after the agent is ready.' );
		}

		$result = $wpdb->update(
			$this->table_campaigns,
			array(
				'status'     => 'sending',
				'started_by' => get_current_user_id(),
				'started_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $campaign_id, 'status' => 'prepared' )
		);
		if ( 1 !== $result ) {
			throw new Exception( 'Campaign authorization failed because its state changed. Refresh and try again.' );
		}

		return true;
	}

	/**
	 * Pause a campaign that is currently sending.
	 *
	 * Transitions campaign status from 'sending' to 'paused'.
	 * Reserved queue records are returned to 'prepared' status so the agent
	 * will not pick them up again until the campaign is resumed.
	 *
	 * @param  int $campaign_id Campaign ID.
	 * @return bool             True on success.
	 * @throws Exception        If campaign is not in 'sending' status.
	 */
	public function pause_campaign_sending( int $campaign_id ) {
		global $wpdb;

		$campaign = $this->get_campaign( $campaign_id );
		if ( ! $campaign ) {
			return false;
		}

		if ( $campaign['status'] !== 'sending' ) {
			throw new Exception( 'Only sending campaigns can be paused.' );
		}

		$wpdb->query( 'START TRANSACTION' );

		try {
			// Return any currently reserved (but not yet sent) records back to prepared.
			$wpdb->update(
				$this->table_queue,
				array(
					'status'              => 'prepared',
					'reserved_by_agent_id' => null,
					'reserved_at'         => null,
					'updated_at'          => current_time( 'mysql' ),
				),
				array(
					'campaign_id' => $campaign_id,
					'status'      => 'reserved',
				)
			);

			$result = $wpdb->update(
				$this->table_campaigns,
				array(
					'status'     => 'paused',
					'updated_at' => current_time( 'mysql' ),
				),
				array( 'id' => $campaign_id )
			);

			if ( false === $result ) {
				throw new Exception( 'Failed to update campaign status to paused: ' . $wpdb->last_error );
			}

			$wpdb->query( 'COMMIT' );
			return true;

		} catch ( Exception $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}
	}

	/**
	 * Resume a paused campaign.
	 *
	 * Transitions campaign status from 'paused' to 'sending'.
	 *
	 * @param  int $campaign_id Campaign ID.
	 * @return bool             True on success.
	 * @throws Exception        If campaign is not in 'paused' status.
	 */
	public function resume_campaign_sending( int $campaign_id ) {
		global $wpdb;

		$campaign = $this->get_campaign( $campaign_id );
		if ( ! $campaign ) {
			return false;
		}

		if ( $campaign['status'] !== 'paused' ) {
			throw new Exception( 'Only paused campaigns can be resumed.' );
		}

		$result = $wpdb->update(
			$this->table_campaigns,
			array(
				'status'     => 'sending',
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $campaign_id )
		);

		if ( false === $result ) {
			throw new Exception( 'Failed to update campaign status to sending: ' . $wpdb->last_error );
		}

		return true;
	}

	// ─── Reset & Cancel Operations ───────────────────────────────────────────

	/**
	 * Reset a prepared campaign back to draft.
	 *
	 * Cleans up all snapshots and queue records associated with the campaign.
	 *
	 * @param  int $campaign_id Campaign ID.
	 * @return bool             True on success, false on failure.
	 * @throws Exception        If campaign status is not prepared or cancelled.
	 */
	public function reset_campaign_to_draft( int $campaign_id ) {
		global $wpdb;

		$campaign = $this->get_campaign( $campaign_id );
		if ( ! $campaign ) {
			return false;
		}

		if ( $campaign['status'] !== 'prepared' ) {
			throw new Exception( 'Only prepared campaigns can be reset to draft.' );
		}

		$wpdb->query( 'START TRANSACTION' );

		try {
			// Delete prepared snapshotted recipients.
			$wpdb->delete( $this->table_recipients, array( 'campaign_id' => $campaign_id ) );

			// Delete queue records.
			$wpdb->delete( $this->table_queue, array( 'campaign_id' => $campaign_id ) );

			// Reset campaign fields.
			$update_data = array(
				'status'                 => 'draft',
				'template_name_snapshot' => null,
				'template_body_snapshot' => null,
				'total_candidates'       => 0,
				'total_included'         => 0,
				'total_excluded'         => 0,
				'total_prepared'         => 0,
				'core_snapshot_at'       => null,
				'core_sync_health_json'  => null,
				'prepared_at'            => null,
				'prepared_by'            => null,
				'started_by'             => null,
				'started_at'             => null,
				'completed_at'           => null,
				'cancelled_at'           => null,
				'updated_at'             => current_time( 'mysql' ),
			);

			$result = $wpdb->update(
				$this->table_campaigns,
				$update_data,
				array( 'id' => $campaign_id )
			);

			if ( false === $result ) {
				throw new Exception( 'Failed to update campaign state during reset: ' . $wpdb->last_error );
			}

			$wpdb->query( 'COMMIT' );
			return true;

		} catch ( Exception $e ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
	}

	/**
	 * Cancel a campaign, preserving snapshots for audit.
	 *
	 * Allowed from 'prepared', 'sending', or 'paused' status.
	 * Transitions campaign and all non-terminal queue records to 'cancelled'.
	 *
	 * @param  int $campaign_id Campaign ID.
	 * @return bool             True on success, false on failure.
	 * @throws Exception        If campaign status is not cancellable.
	 */
	public function cancel_campaign( int $campaign_id ) {
		global $wpdb;

		$campaign = $this->get_campaign( $campaign_id );
		if ( ! $campaign ) {
			return false;
		}

		$cancellable = array( 'prepared', 'sending', 'paused' );
		if ( ! in_array( $campaign['status'], $cancellable, true ) ) {
			throw new Exception( 'Only prepared, sending, or paused campaigns can be cancelled.' );
		}

		$wpdb->query( 'START TRANSACTION' );

		try {
			$cancelled_time    = current_time( 'mysql' );
			// Cancel all non-terminal queue records.
			$non_terminal      = array( 'prepared', 'reserved', 'retry_wait' );
			$placeholders      = implode( ',', array_fill( 0, count( $non_terminal ), '%s' ) );
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$this->table_queue}
				 SET status = 'cancelled', cancelled_at = %s, updated_at = %s
				 WHERE campaign_id = %d AND status IN ({$placeholders})",
				array_merge(
					array( $cancelled_time, $cancelled_time, $campaign_id ),
					$non_terminal
				)
			) );

			// Update campaign status to cancelled.
			$result = $wpdb->update(
				$this->table_campaigns,
				array(
					'status'       => 'cancelled',
					'cancelled_at' => $cancelled_time,
					'updated_at'   => $cancelled_time,
				),
				array( 'id' => $campaign_id )
			);

			if ( false === $result ) {
				throw new Exception( 'Failed to update campaign status during cancellation: ' . $wpdb->last_error );
			}

			$wpdb->query( 'COMMIT' );
			return true;

		} catch ( Exception $e ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
	}

	/**
	 * Delete one queue item if it has not been sent yet.
	 */
	public function delete_queue_item( int $queue_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, status FROM {$this->table_queue} WHERE id = %d", $queue_id ),
			ARRAY_A
		);
		if ( ! $row || in_array( $row['status'], array( 'sent', 'failed', 'cancelled' ), true ) ) {
			return false;
		}
		return false !== $wpdb->delete( $this->table_queue, array( 'id' => $queue_id ), array( '%d' ) );
	}

	// ─── Classified Audience Router ───────────────────────────────────────────

	/**
	 * Dispatch to the correct data source for the new classified audience types.
	 *
	 * @param  string $target_type  One of: finance_outstanding, academic, transport_no_gps,
	 *                              transport_registered, store_missing.
	 * @param  string $study_year
	 * @param  array  $filters      Campaign filters array.
	 * @return array  Flat array of recipient items compatible with the existing
	 *                preview_candidates() evaluation loop.
	 */
	private function fetch_classified_audience( $target_type, $study_year, array $filters ) {
		$plugin = Olama_Messages_Plugin::instance();

		switch ( $target_type ) {

			// ── Finance: Outstanding balances ─────────────────────────────────
			case 'finance_outstanding':
				// Reuse the existing financial / collection flow via the Core provider.
				$provider      = $plugin->provider();
				$all_items     = array();
				$chunk_size    = 200;
				$offset        = 0;
				$page_sigs     = array();
				$page_count    = 0;
				while ( $page_count < 100 ) {
					$page_count++;
					$q = array(
						'study_year'  => $study_year,
						'target_type' => 'collection', // maps to financial
						'limit'       => $chunk_size,
						'offset'      => $offset,
					);
					if ( ! empty( $filters['family_id'] ) ) {
						$q['family_id'] = $filters['family_id'];
					}
					if ( isset( $filters['min_balance'] ) ) {
						$q['min_balance'] = $filters['min_balance'];
					}
					if ( isset( $filters['exclude_credit_balances'] ) ) {
						$q['exclude_credit_balances'] = $filters['exclude_credit_balances'];
					}
					if ( isset( $filters['exclude_zero_balances'] ) ) {
						$q['exclude_zero_balances'] = $filters['exclude_zero_balances'];
					}
					$res = $provider->get_recipients_preview( $q );
					if ( empty( $res['items'] ) ) {
						break;
					}
					$sig = md5( wp_json_encode( wp_list_pluck( $res['items'], 'oracle_family_id' ) ) );
					if ( isset( $page_sigs[ $sig ] ) ) {
						break;
					}
					$page_sigs[ $sig ] = true;
					$all_items         = array_merge( $all_items, $res['items'] );
					$offset           += count( $res['items'] );
				}
				return $all_items;

			// ── Academic: filtered by school / grade / section ────────────────
			case 'academic':
				$provider   = $plugin->provider();
				$all_items  = array();
				$chunk_size = 200;
				$offset     = 0;
				$page_sigs  = array();
				$page_count = 0;
				while ( $page_count < 100 ) {
					$page_count++;
					$q = array(
						'study_year'  => $study_year,
						'target_type' => 'general',
						'limit'       => $chunk_size,
						'offset'      => $offset,
					);
					// Academic filters.
					foreach ( array( 'family_id', 'class_id', 'class_name', 'section_id', 'section_name', 'school_id', 'school_name' ) as $fk ) {
						if ( ! empty( $filters[ $fk ] ) ) {
							$q[ $fk ] = $filters[ $fk ];
						}
					}
					$res = $provider->get_recipients_preview( $q );
					if ( empty( $res['items'] ) ) {
						break;
					}
					$sig = md5( wp_json_encode( wp_list_pluck( $res['items'], 'oracle_family_id' ) ) );
					if ( isset( $page_sigs[ $sig ] ) ) {
						break;
					}
					$page_sigs[ $sig ] = true;
					$all_items         = array_merge( $all_items, $res['items'] );
					$offset           += count( $res['items'] );
				}
				return $all_items;

			// ── Transportation: families without GPS ──────────────────────────
			case 'transport_no_gps':
				if ( ! method_exists( $plugin, 'transportation' ) ) {
					return array();
				}
				return $plugin->transportation()->get_families_without_gps( $study_year );

			// ── Transportation: registered families with bus / area filters ───
			case 'transport_registered':
				$provider   = $plugin->provider();
				$all_items  = array();
				$chunk_size = 200;
				$offset     = 0;
				$page_sigs  = array();
				$page_count = 0;
				while ( $page_count < 100 ) {
					$page_count++;
					$q = array(
						'study_year'  => $study_year,
						'target_type' => 'transportation',
						'limit'       => $chunk_size,
						'offset'      => $offset,
					);
					foreach ( array( 'family_id', 'departure_bus', 'arrival_bus', 'bus_name', 'round_name', 'trans_route' ) as $fk ) {
						if ( ! empty( $filters[ $fk ] ) ) {
							$q[ $fk ] = $filters[ $fk ];
						}
					}
					// Area filter — pass as trans_region_name or major_area_id.
					if ( ! empty( $filters['major_area_id'] ) ) {
						$q['major_area_id'] = $filters['major_area_id'];
					}
					$res = $provider->get_recipients_preview( $q );
					if ( empty( $res['items'] ) ) {
						break;
					}
					$sig = md5( wp_json_encode( wp_list_pluck( $res['items'], 'oracle_family_id' ) ) );
					if ( isset( $page_sigs[ $sig ] ) ) {
						break;
					}
					$page_sigs[ $sig ] = true;
					$all_items         = array_merge( $all_items, $res['items'] );
					$offset           += count( $res['items'] );
				}
				return $all_items;

			// ── Store: families missing books or customs ──────────────────────
			case 'store_missing':
				if ( ! method_exists( $plugin, 'store_provider' ) ) {
					return array();
				}
				return $plugin->store_provider()->get_families_missing_items( $study_year, $filters );

			default:
				return array();
		}
	}
}

