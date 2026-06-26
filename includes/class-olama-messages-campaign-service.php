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
			'template_id'             => null,
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

		return $row;
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
			'limit'      => 50,
			'offset'     => 0,
			'orderby'    => 'created_at',
			'order'      => 'DESC',
		);

		$args = array_merge( $defaults, $args );

		$where  = array();
		$values = array();

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = sanitize_text_field( $args['status'] );
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
			$row['min_balance']             = null !== $row['min_balance'] ? (float) $row['min_balance'] : null;
			$row['exclude_credit_balances'] = (int) $row['exclude_credit_balances'];
			$row['exclude_zero_balances']   = (int) $row['exclude_zero_balances'];
			$row['total_candidates']        = (int) $row['total_candidates'];
			$row['total_included']          = (int) $row['total_included'];
			$row['total_excluded']          = (int) $row['total_excluded'];
			$row['total_prepared']          = (int) $row['total_prepared'];
			$row['filters']                 = $row['filters_json'] ? json_decode( $row['filters_json'], true ) : array();
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
				'template_id'             => null,
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
		if ( empty( $study_year ) ) {
			$years      = Olama_Messages_Plugin::instance()->provider()->get_available_study_years();
			$study_year = ! empty( $years ) ? $years[0] : '2025/2026';
		}

		// Determine the active template body.
		$template_body = '';
		if ( ! empty( $campaign['template_id'] ) ) {
			$template = Olama_Messages_Plugin::instance()->templates()->get_template( $campaign['template_id'] );
			if ( $template ) {
				$template_body = $template['body'];
			}
		}
		if ( empty( $template_body ) && ! empty( $campaign['template_body_snapshot'] ) ) {
			$template_body = $campaign['template_body_snapshot'];
		}
		if ( empty( $template_body ) ) {
			// Fallback.
			$template_body = Olama_Messages_Plugin::instance()->renderer()->get_active_template( true );
		}

		// Extract filters (class_name, section_name, family_id).
		$filters = isset( $campaign['filters'] ) && is_array( $campaign['filters'] ) ? $campaign['filters'] : array();

		// Fetch candidates in chunks.
		$all_items  = array();
		$chunk_size = 200;
		$offset     = 0;
		$provider   = Olama_Messages_Plugin::instance()->provider();

		while ( true ) {
			$query_filters = array(
				'study_year' => $study_year,
				'limit'      => $chunk_size,
				'offset'     => $offset,
			);

			if ( ! empty( $filters['family_id'] ) ) {
				$query_filters['family_id'] = $filters['family_id'];
			}
			if ( ! empty( $filters['class_name'] ) ) {
				$query_filters['class_name'] = $filters['class_name'];
			}
			if ( ! empty( $filters['section_name'] ) ) {
				$query_filters['section_name'] = $filters['section_name'];
			}
			if ( ! empty( $filters['class_id'] ) ) {
				$query_filters['class_id'] = $filters['class_id'];
			}
			if ( ! empty( $filters['section_id'] ) ) {
				$query_filters['section_id'] = $filters['section_id'];
			}

			$res = $provider->get_recipients_preview( $query_filters );
			if ( empty( $res['items'] ) ) {
				break;
			}

			$all_items = array_merge( $all_items, $res['items'] );

			if ( count( $res['items'] ) < $chunk_size || count( $all_items ) >= $res['total'] ) {
				break;
			}

			$offset += $chunk_size;
		}

		$evaluated_targets = array();
		$seen_phones       = array(); // Track duplicates.

		foreach ( $all_items as $item ) {
			$family_id           = (int) $item['family_id'];
			$oracle_family_id    = $item['oracle_family_id'] ?? (string) $family_id;
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
			} elseif ( $policy === 'both_parents' ) {
				$has_father = ! empty( trim( (string) $father_mobile ) );
				$has_mother = ! empty( trim( (string) $mother_mobile ) );

				if ( ! $has_father && ! $has_mother ) {
					$targets[] = array(
						'type'  => 'father',
						'name'  => $father_name ? $father_name : $sponsor_name,
						'phone' => '',
					);
				} else {
					if ( $has_father ) {
						$targets[] = array(
							'type'  => 'father',
							'name'  => $father_name ? $father_name : $sponsor_name,
							'phone' => $father_mobile,
						);
					}
					if ( $has_mother ) {
						$targets[] = array(
							'type'  => 'mother',
							'name'  => $mother_name ? $mother_name : $sponsor_name,
							'phone' => $mother_mobile,
						);
					}
				}
			}

			// Evaluate each target against financial rules and phone policy.
			foreach ( $targets as $target ) {
				$included        = true;
				$excluded_reason = null;

				// 1. Check financial rules (Rule 7: financial reminders require financial data).
				$req_finance = ( isset( $campaign['min_balance'] ) && $campaign['min_balance'] !== '' )
					|| ! empty( $campaign['exclude_credit_balances'] )
					|| ! empty( $campaign['exclude_zero_balances'] );

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

				if ( $included ) {
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
				}

				$evaluated_targets[] = array(
					'family_id'            => $family_id,
					'oracle_family_id'     => $oracle_family_id,
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

		foreach ( $evaluated_targets as $t ) {
			if ( $t['included'] ) {
				$total_included++;
			} else {
				$total_excluded++;
			}
		}

		// Paginate the in-memory array for the response if limits are requested.
		$paginated_targets = $evaluated_targets;
		if ( isset( $args['limit'] ) ) {
			$limit             = absint( $args['limit'] );
			$offset            = isset( $args['offset'] ) ? absint( $args['offset'] ) : 0;
			$paginated_targets = array_slice( $evaluated_targets, $offset, $limit );
		}

		return array(
			'items'            => $paginated_targets,
			'total_candidates' => $total_candidates,
			'total_included'   => $total_included,
			'total_excluded'   => $total_excluded,
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

		// 2. Fetch template.
		if ( empty( $campaign['template_id'] ) ) {
			throw new Exception( 'No template selected for this campaign.' );
		}

		$template = Olama_Messages_Plugin::instance()->templates()->get_template( $campaign['template_id'] );
		if ( ! $template ) {
			throw new Exception( 'Selected template not found.' );
		}

		$template_name = $template['name'];
		$template_body = $template['body'];

		// 3. Evaluate all candidates in memory.
		$results = $this->preview_candidates( $campaign );

		// 4. Write to DB using transaction wrapper.
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

				// Insert recipient snapshot.
				$recipient_data = array(
					'campaign_id'         => $campaign_id,
					'family_id'           => $t['family_id'],
					'oracle_family_id'    => $t['oracle_family_id'],
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
						'requires_payment_link' => 1,
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
				'prepared_at'            => current_time( 'mysql' ),
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
				'prepared_at'            => null,
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
	 * Cancel a prepared campaign, preserving snapshots for audit.
	 *
	 * Transitions campaign and queue statuses to 'cancelled'.
	 *
	 * @param  int $campaign_id Campaign ID.
	 * @return bool             True on success, false on failure.
	 * @throws Exception        If campaign status is not prepared.
	 */
	public function cancel_campaign( int $campaign_id ) {
		global $wpdb;

		$campaign = $this->get_campaign( $campaign_id );
		if ( ! $campaign ) {
			return false;
		}

		if ( $campaign['status'] !== 'prepared' ) {
			throw new Exception( 'Only prepared campaigns can be cancelled.' );
		}

		$wpdb->query( 'START TRANSACTION' );

		try {
			$cancelled_time = current_time( 'mysql' );

			// Update queue records status to cancelled.
			$wpdb->update(
				$this->table_queue,
				array(
					'status'       => 'cancelled',
					'cancelled_at' => $cancelled_time,
					'updated_at'   => $cancelled_time,
				),
				array(
					'campaign_id' => $campaign_id,
					'status'      => 'prepared',
				)
			);

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
}
