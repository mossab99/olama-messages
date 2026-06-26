<?php
/**
 * Olama Core data-provider adapter.
 *
 * Reads families, students, and study-year data from olama-core tables.
 *
 * FINANCIAL DATA RULE FOR PHASE 1
 * ─────────────────────────────────
 * Olama Core currently does not expose balance, monthly due, due items,
 * or payment history. Therefore:
 *
 *  - Do NOT return fake financial values.
 *  - Do NOT show 0 balance — 0 means "no debt", null means "unknown".
 *  - Use null for all unknown financial values.
 *  - Add financial_available = false to every recipient and report payload.
 *  - Minimum-balance filtering is disabled; it requires financial data.
 *  - Public report must show an Arabic warning when financial data is unavailable.
 *  - SMS preview must not include real balance/monthly_due values while
 *    financial_available = false.
 *
 * Implementation order:
 *  1. Try olama_core() service methods (families, students, student_years).
 *  2. Fall back to direct $wpdb only for combined/aggregated queries that the
 *     Core services do not yet expose (e.g. multi-table JOIN for preview list).
 *
 * @package Olama_Messages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Olama_Messages_Core_Provider {

	/** Warning message shown when financial data is unavailable. */
	const FINANCIAL_WARNING = 'Olama Core financial provider is not available yet. Recipient preview is showing demographic/contact data only.';

	/** Admin-facing notice (English). */
	const FINANCIAL_ADMIN_NOTICE = 'Olama Core financial provider is not available yet. Recipient preview is showing demographic/contact data only.';

	/** Public-facing Arabic notice. */
	const FINANCIAL_ARABIC_NOTICE = 'البيانات المالية غير متوفرة حالياً من Olama Core.';

	// ─── Availability ────────────────────────────────────────────────────────

	/**
	 * Is olama-core loaded and ALL its required tables present?
	 *
	 * Checks families, students, and student_years — all three must exist.
	 * Reporting "ready" when only one table is present would be misleading.
	 *
	 * @return bool
	 */
	public function is_core_available() {
		if ( ! function_exists( 'olama_core' ) ) {
			return false;
		}

		global $wpdb;
		$required = array(
			$wpdb->prefix . 'olama_core_families',
			$wpdb->prefix . 'olama_core_students',
			$wpdb->prefix . 'olama_core_student_years',
		);

		foreach ( $required as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Is the financial provider ready?
	 * Phase 1.5: Checks Oracle Flask API bridge reachability.
	 *
	 * @return bool
	 */
	public function is_financial_provider_ready() {
		if ( class_exists( 'Olama_Messages_Plugin' ) && Olama_Messages_Plugin::instance() ) {
			return Olama_Messages_Plugin::instance()->financial()->is_available();
		}
		return false;
	}

	// ─── Study years ─────────────────────────────────────────────────────────

	/**
	 * Return all distinct study years from olama_core_student_years.
	 *
	 * Core services don't expose this query directly, so we use $wpdb.
	 *
	 * @return string[]   e.g. ['2025/2026', '2024/2025']
	 */
	public function get_available_study_years() {
		if ( ! $this->is_core_available() ) {
			return array();
		}

		global $wpdb;
		$table = $wpdb->prefix . 'olama_core_student_years';
		$rows  = $wpdb->get_col(
			'SELECT DISTINCT study_year FROM `' . esc_sql( $table ) . '` ORDER BY study_year DESC'
		);

		return is_array( $rows ) ? $rows : array();
	}

	// ─── Recipients preview ──────────────────────────────────────────────────

	/**
	 * Return a list of family recipient items, optionally filtered.
	 *
	 * Supported filters (financial filters DISABLED in Phase 1):
	 *   - study_year   (string)
	 *   - family_id    (int|string)  — oracle_family_id
	 *   - class_name   (string)
	 *   - section_name (string)
	 *   - limit        (int, default 50)
	 *   - offset       (int, default 0)
	 *
	 * NOTE: min_balance filter is intentionally ignored — financial data
	 * is not available. Applying it would incorrectly filter all families.
	 *
	 * @param  array $filters
	 * @return array {
	 *     items:               array,
	 *     total:               int,
	 *     financial_available: bool,
	 *     financial_warning:   string,
	 * }
	 */
	public function get_recipients_preview( array $filters = array() ) {
		if ( ! $this->is_core_available() ) {
			return array(
				'items'               => array(),
				'total'               => 0,
				'financial_available' => false,
				'financial_warning'   => 'Olama Core is not active.',
			);
		}

		// Phase 1.5: Use bulk API endpoint when financial provider is available.
		if ( $this->is_financial_provider_ready() ) {
			$study_year = $filters['study_year'] ?? '';
			if ( empty( $study_year ) ) {
				$years      = $this->get_available_study_years();
				$study_year = ! empty( $years ) ? $years[0] : '2025/2026';
			}

			$api_data = Olama_Messages_Plugin::instance()->financial()->get_bulk_recipients( $study_year, $filters );
			if ( is_array( $api_data ) && isset( $api_data['recipients'] ) ) {
				$normalized_items = array();
				foreach ( $api_data['recipients'] as $item ) {
					$normalized_students = array();
					$student_rows        = array();
					if ( ! empty( $item['students'] ) && is_array( $item['students'] ) ) {
						foreach ( $item['students'] as $student ) {
							if ( is_array( $student ) || is_object( $student ) ) {
								$student_arr = (array) $student;
								$name        = $student_arr['student_name'] ?? $student_arr['name'] ?? '';
								if ( $name ) {
									$normalized_students[] = $name;
								}
								$student_rows[] = array(
									'student_id'   => $student_arr['student_id'] ?? null,
									'student_name' => $name,
									'class_name'   => $student_arr['class_name'] ?? '',
									'section_name' => $student_arr['section_name'] ?? '',
								);
							} elseif ( is_string( $student ) ) {
								$normalized_students[] = $student;
								$student_rows[]        = array(
									'student_id'   => null,
									'student_name' => $student,
									'class_name'   => '',
									'section_name' => '',
								);
							}
						}
					}
					$item['students']     = $normalized_students;
					$item['student_rows'] = $student_rows;
					$normalized_items[]   = $item;
				}

				return array(
					'items'               => $normalized_items,
					'total'               => intval( $api_data['count'] ?? count( $normalized_items ) ),
					'limit'               => intval( $api_data['limit'] ?? 50 ),
					'offset'              => intval( $api_data['offset'] ?? 0 ),
					'financial_available' => true,
					'financial_warning'   => '',
				);
			}
		}

		global $wpdb;

		$families_table = $wpdb->prefix . 'olama_core_families';
		$years_table    = $wpdb->prefix . 'olama_core_student_years';
		$students_table = $wpdb->prefix . 'olama_core_students';

		$limit  = isset( $filters['limit'] )  ? max( 1, min( 200, absint( $filters['limit'] ) ) ) : 50;
		$offset = isset( $filters['offset'] ) ? max( 0, absint( $filters['offset'] ) )            : 0;

		// Build WHERE conditions — no min_balance filter allowed in Phase 1.
		$where_clauses = array();
		$where_values  = array();

		if ( ! empty( $filters['study_year'] ) ) {
			$where_clauses[] = 'sy.study_year = %s';
			$where_values[]  = sanitize_text_field( $filters['study_year'] );
		}

		if ( ! empty( $filters['family_id'] ) ) {
			$where_clauses[] = 'f.oracle_family_id = %s';
			$where_values[]  = sanitize_text_field( (string) $filters['family_id'] );
		}

		if ( ! empty( $filters['class_name'] ) ) {
			$where_clauses[] = 'sy.class_name LIKE %s';
			$where_values[]  = '%' . $wpdb->esc_like( sanitize_text_field( $filters['class_name'] ) ) . '%';
		}

		if ( ! empty( $filters['section_name'] ) ) {
			$where_clauses[] = 'sy.section_name LIKE %s';
			$where_values[]  = '%' . $wpdb->esc_like( sanitize_text_field( $filters['section_name'] ) ) . '%';
		}

		$where_sql = $where_clauses ? 'WHERE ' . implode( ' AND ', $where_clauses ) : '';

		// Multi-table JOIN query — olama_core() services don't expose this yet.
		// Students are joined via student_years (not directly via family) so that
		// class/section/year filters correctly scope which students are shown.
		$select_sql = "
			SELECT
				f.oracle_family_id  AS oracle_family_id,
				f.sponsor_full_name AS sponsor_name,
				f.father_name,
				f.father_mobile,
				f.mother_name,
				f.mother_mobile,
				GROUP_CONCAT(DISTINCT st.student_name  ORDER BY st.student_name  SEPARATOR '||') AS students_raw,
				GROUP_CONCAT(DISTINCT sy.class_name    ORDER BY sy.class_name    SEPARATOR '||') AS class_names_raw,
				GROUP_CONCAT(DISTINCT sy.section_name  ORDER BY sy.section_name  SEPARATOR '||') AS section_names_raw
			FROM `" . esc_sql( $families_table ) . "` f
			LEFT JOIN `" . esc_sql( $years_table ) . "` sy ON sy.family_uid = f.family_uid
			LEFT JOIN `" . esc_sql( $students_table ) . "` st ON st.student_uid = sy.student_uid
		";

		if ( $where_clauses ) {
			$select_query = $wpdb->prepare(
				$select_sql . $where_sql . ' GROUP BY f.id ORDER BY f.oracle_family_id ASC LIMIT %d OFFSET %d',
				array_merge( $where_values, array( $limit, $offset ) )
			);
			$count_query  = $wpdb->prepare(
				"SELECT COUNT(DISTINCT f.id) FROM `" . esc_sql( $families_table ) . "` f
				 LEFT JOIN `" . esc_sql( $years_table ) . "` sy ON sy.family_uid = f.family_uid
				 " . $where_sql,
				$where_values
			);
		} else {
			$select_query = $wpdb->prepare(
				$select_sql . ' GROUP BY f.id ORDER BY f.oracle_family_id ASC LIMIT %d OFFSET %d',
				$limit,
				$offset
			);
			$count_query  = "SELECT COUNT(*) FROM `" . esc_sql( $families_table ) . "`";
		}

		$rows  = $wpdb->get_results( $select_query, ARRAY_A );
		$total = (int) $wpdb->get_var( $count_query );

		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$items = array();
		foreach ( $rows as $row ) {
			$oracle_id     = $row['oracle_family_id'] ?? '';
			$students      = $row['students_raw']      ? array_filter( explode( '||', $row['students_raw'] ) )      : array();
			$class_names   = $row['class_names_raw']   ? array_filter( explode( '||', $row['class_names_raw'] ) )   : array();
			$section_names = $row['section_names_raw'] ? array_filter( explode( '||', $row['section_names_raw'] ) ) : array();

			$student_rows = array();
			foreach ( $students as $s ) {
				$student_rows[] = array(
					'student_id'   => null,
					'student_name' => $s,
					'class_name'   => ! empty( $class_names ) ? implode( ', ', array_unique( $class_names ) ) : '',
					'section_name' => ! empty( $section_names ) ? implode( ', ', array_unique( $section_names ) ) : '',
				);
			}

			$items[] = array(
				// Both family_id (int) and oracle_family_id (string) for traceability.
				'family_id'           => (int) $oracle_id,
				'oracle_family_id'    => $oracle_id,
				'sponsor_name'        => $row['sponsor_name']  ?? '',
				'father_name'         => $row['father_name']   ?? '',
				'father_mobile'       => $row['father_mobile'] ?? '',
				'mother_name'         => $row['mother_name']   ?? '',
				'mother_mobile'       => $row['mother_mobile'] ?? '',
				'students'            => array_values( $students ),
				'student_rows'        => $student_rows,
				'class_names'         => array_values( array_unique( $class_names ) ),
				'section_names'       => array_values( array_unique( $section_names ) ),
				// Financial fields: null = unknown, NOT 0.
				'balance'             => null,
				'monthly_due'         => null,
				'currency'            => 'JOD',
				'financial_available' => false,
				'financial_warning'   => self::FINANCIAL_WARNING,
			);
		}

		return array(
			'items'               => $items,
			'total'               => $total,
			'financial_available' => false,
			'financial_warning'   => self::FINANCIAL_ADMIN_NOTICE,
		);
	}

	// ─── Family payment report ────────────────────────────────────────────────

	/**
	 * Return the payment report data for a single family.
	 *
	 * Uses olama_core() service for single-family and student lookups.
	 * Falls back to $wpdb for combined year+student JOIN.
	 *
	 * @param  int|string $family_id    oracle_family_id
	 * @param  string     $study_year
	 * @return array|null  null when family not found; array with financial_available=false otherwise.
	 */
	public function get_family_payment_report( $family_id, $study_year = '' ) {
		if ( ! $this->is_core_available() ) {
			return null;
		}

		$family_id_str = sanitize_text_field( (string) $family_id );

		// Phase 1.5: Use single family payment report API when financial provider is active.
		if ( $this->is_financial_provider_ready() ) {
			if ( empty( $study_year ) ) {
				$years      = $this->get_available_study_years();
				$study_year = ! empty( $years ) ? $years[0] : '2025/2026';
			}

			$api_report = Olama_Messages_Plugin::instance()->financial()->get_payment_report( $family_id_str, $study_year );
			if ( is_array( $api_report ) && isset( $api_report['financial'] ) ) {
				$normalized_students_objects = array();
				if ( ! empty( $api_report['students'] ) && is_array( $api_report['students'] ) ) {
					foreach ( $api_report['students'] as $student ) {
						if ( is_array( $student ) || is_object( $student ) ) {
							$student_arr = (array) $student;
							$name        = $student_arr['student_name'] ?? $student_arr['name'] ?? '';
							$normalized_students_objects[] = array(
								'name'         => $name,
								'student_name' => $name,
								'class_name'   => $student_arr['class_name'] ?? '',
								'section_name' => $student_arr['section_name'] ?? '',
								'study_year'   => $student_arr['study_year'] ?? $study_year,
							);
						} elseif ( is_string( $student ) ) {
							$normalized_students_objects[] = array(
								'name'         => $student,
								'student_name' => $student,
								'class_name'   => '',
								'section_name' => '',
								'study_year'   => $study_year,
							);
						}
					}
				}
				$api_report['students']     = $normalized_students_objects;
				$api_report['student_rows'] = $normalized_students_objects;
				return $api_report;
			}
		}

		// ── Step 1: Use olama_core()->families() service for single-family lookup ──
		$family = olama_core()->families()->get_by_oracle_id( $family_id_str );

		if ( ! $family ) {
			return null;
		}

		// ── Step 2: Use $wpdb for combined year+student JOIN (not in Core API) ──
		global $wpdb;
		$years_table    = $wpdb->prefix . 'olama_core_student_years';
		$students_table = $wpdb->prefix . 'olama_core_students';

		if ( $study_year ) {
			$year_rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT sy.*, st.student_name FROM `' . esc_sql( $years_table ) . '` sy
					 LEFT JOIN `' . esc_sql( $students_table ) . '` st ON st.student_uid = sy.student_uid
					 WHERE sy.family_uid = %s AND sy.study_year = %s
					 ORDER BY st.student_name ASC',
					$family['family_uid'],
					sanitize_text_field( $study_year )
				),
				ARRAY_A
			);
		} else {
			$year_rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT sy.*, st.student_name FROM `' . esc_sql( $years_table ) . '` sy
					 LEFT JOIN `' . esc_sql( $students_table ) . '` st ON st.student_uid = sy.student_uid
					 WHERE sy.family_uid = %s
					 ORDER BY sy.study_year DESC, st.student_name ASC',
					$family['family_uid']
				),
				ARRAY_A
			);
		}

		if ( ! is_array( $year_rows ) ) {
			$year_rows = array();
		}

		$students = array();
		foreach ( $year_rows as $yr ) {
			if ( ! empty( $yr['student_name'] ) ) {
				$students[] = array(
					'name'         => $yr['student_name'],
					'student_name' => $yr['student_name'],
					'class_name'   => $yr['class_name']   ?? '',
					'section_name' => $yr['section_name'] ?? '',
					'study_year'   => $yr['study_year']   ?? '',
				);
			}
		}

		// Resolve display study year from data if not provided.
		if ( ! $study_year && ! empty( $year_rows ) ) {
			$study_year = $year_rows[0]['study_year'] ?? '';
		}

		return array(
			// Both representations for traceability.
			'family_id'           => (int) $family_id_str,
			'oracle_family_id'    => $family_id_str,
			'sponsor_name'        => $family['sponsor_full_name'] ?? '',
			'father_name'         => $family['father_name']       ?? '',
			'mother_name'         => $family['mother_name']       ?? '',
			'father_mobile'       => $family['father_mobile']     ?? '',
			'mother_mobile'       => $family['mother_mobile']     ?? '',
			'students'            => $students,
			'study_year'          => $study_year,
			// Financial fields: null = UNKNOWN, not 0.
			// 0 would mean "no debt" — that is dangerous and incorrect.
			'balance'             => null,
			'monthly_due'         => null,
			'due_items'           => array(),
			'last_payment'        => null,
			'currency'            => 'JOD',
			'financial_available' => false,
			'financial_warning'   => self::FINANCIAL_WARNING,
		);
	}
}
