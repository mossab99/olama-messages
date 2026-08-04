<?php
/**
 * Read-only Olama Stores adapter for the Messages audience system.
 *
 * Finds families with at least one student who has not yet received all
 * allocated books or custom items for the current academic year.
 *
 * "Not received" = student has allocated item IDs not in os_assignments
 * with status IN ('active', 'partially_returned').
 *
 * @package Olama_Messages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Olama_Messages_Store_Provider {

	/**
	 * Check whether the Olama Stores plugin tables are available.
	 *
	 * @return bool
	 */
	public function is_available() {
		global $wpdb;
		return function_exists( 'olama_core' )
			&& $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'os_assignments' ) ) === $wpdb->prefix . 'os_assignments';
	}

	/**
	 * Return the active academic year ID used by Olama Stores.
	 *
	 * @return int
	 */
	private function get_active_year_id() {
		if ( function_exists( 'os_get_active_year_id' ) ) {
			return (int) os_get_active_year_id();
		}
		global $wpdb;
		$id = $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}os_academic_years WHERE is_active = 1 ORDER BY id DESC LIMIT 1" );
		return (int) $id;
	}

	/**
	 * Load book allocations for a given academic year ID.
	 *
	 * OS_API_Books_Withdrawal stores them in WordPress options under the key
	 * `os_year_config_{year_id}` as a nested array.
	 *
	 * @param  int $year_id
	 * @return array  grade_id => [item_id, ...]
	 */
	private function get_allocations( $year_id ) {
		$config_raw = get_option( 'os_year_config_' . $year_id, array() );
		if ( is_array( $config_raw ) && isset( $config_raw['os_book_allocations'] ) ) {
			return (array) $config_raw['os_book_allocations'];
		}
		// Older storage key fallback.
		$raw = get_option( 'os_book_allocations_' . $year_id, null );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Return families that have at least one student with missing books/customs.
	 *
	 * Uses OS_API_Reports::get_withdrawals_report_data() from olama-stores directly
	 * to ensure 100% accuracy and parity with the Stores Reports Center.
	 *
	 * @param  string $study_year  Study year string, e.g. "2025-2026" or "2027/2026".
	 * @param  array  $filters     Filter parameters (store_item_type, school_id, grade_id, etc.).
	 * @return array  Recipient-item arrays compatible with preview_candidates().
	 */
	public function get_families_missing_items( $study_year, array $filters = array() ) {
		if ( ! $this->is_available() ) {
			return array();
		}

		$academic_year_id = 0;
		if ( class_exists( 'OS_School_Integration' ) ) {
			$academic_year_id = OS_School_Integration::study_year_to_id( $study_year );
		}
		if ( ! $academic_year_id ) {
			$academic_year_id = $this->get_active_year_id();
		}

		// Determine report types to include (not_received_custom, not_received_books, or both).
		$item_type = $filters['store_item_type'] ?? 'both';
		$report_types = array();
		if ( 'books' === $item_type ) {
			$report_types[] = 'not_received_books';
		} elseif ( 'custom' === $item_type ) {
			$report_types[] = 'not_received_custom';
		} else {
			$report_types = array( 'not_received_custom', 'not_received_books' );
		}

		$combined_families = array();

		// Primary path: Use OS_API_Reports if available (the exact engine powering Stores Reports UI).
		if ( class_exists( 'OS_API_Reports' ) && method_exists( 'OS_API_Reports', 'get_withdrawals_report_data' ) ) {
			foreach ( $report_types as $rtype ) {
				$params = array(
					'report_type'      => $rtype,
					'academic_year_id' => $academic_year_id,
					'study_year'       => $study_year,
				);
				if ( ! empty( $filters['school_id'] ) ) { $params['school_id'] = $filters['school_id']; }
				if ( ! empty( $filters['grade_id'] ) ) { $params['grade_id'] = $filters['grade_id']; }
				if ( ! empty( $filters['family_id'] ) ) { $params['family_id'] = $filters['family_id']; }

				$report_rows = OS_API_Reports::get_withdrawals_report_data( $params );

				foreach ( (array) $report_rows as $row ) {
					$oracle_id = (string) ( $row['family_id'] ?? $row['family_uid'] ?? '' );
					if ( '' === $oracle_id || isset( $combined_families[ $oracle_id ] ) ) {
						continue;
					}

					$students_summary = array();
					if ( ! empty( $row['students'] ) && is_array( $row['students'] ) ) {
						foreach ( $row['students'] as $st ) {
							$students_summary[] = (string) ( $st['student_name'] ?? $st['name'] ?? '' );
						}
					}

					$combined_families[ $oracle_id ] = array(
						'family_id'           => absint( $oracle_id ),
						'oracle_family_id'    => $oracle_id,
						'core_family_uid'     => (string) ( $row['family_uid'] ?? '' ),
						'sponsor_name'        => (string) ( $row['sponsor_name'] ?? '' ),
						'father_name'         => (string) ( $row['father_name'] ?? '' ),
						'father_mobile'       => (string) ( $row['father_mobile'] ?? '' ),
						'mother_name'         => (string) ( $row['mother_name'] ?? '' ),
						'mother_mobile'       => (string) ( $row['mother_mobile'] ?? '' ),
						'students'            => array_values( array_filter( $students_summary ) ),
						'student_rows'        => $row['students'] ?? array(),
						'balance'             => null,
						'monthly_due'         => null,
						'monthly_due_source'  => 'unavailable',
						'financial_available' => false,
					);
				}
			}

			if ( ! empty( $combined_families ) ) {
				return array_values( $combined_families );
			}
		}

		// Fallback query if OS_API_Reports returns no items or is not present.
		global $wpdb;

		if ( ! function_exists( 'olama_core' ) || ! method_exists( olama_core(), 'read_models' ) ) {
			return array();
		}

		$student_years_table = olama_core()->read_models()->table( 'student_years' );
		$students_table      = olama_core()->read_models()->table( 'students' );
		$families_table      = olama_core()->read_models()->table( 'families' );

		$study_year_clean = sanitize_text_field( (string) $study_year );
		$alternate_year   = strpos( $study_year_clean, '/' ) !== false
			? str_replace( '/', '-', $study_year_clean )
			: str_replace( '-', '/', $study_year_clean );

		$families = $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT f.oracle_family_id, f.family_uid, f.sponsor_full_name,
			        f.father_name, f.father_mobile, f.mother_name, f.mother_mobile
			 FROM `{$families_table}` f
			 INNER JOIN `{$student_years_table}` sy ON sy.family_uid = f.family_uid
			 WHERE sy.study_year IN (%s, %s) AND f.is_active = 1",
			$study_year_clean,
			$alternate_year
		), ARRAY_A );

		// Oracle family IDs that already received items.
		$received_family_ids = (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT stud.oracle_family_id
			 FROM {$wpdb->prefix}os_assignments a
			 INNER JOIN `{$students_table}` stud ON stud.student_uid = a.assignee_id
			 WHERE a.assignee_type = 'student'
			   AND (a.academic_year_id = %d OR a.academic_year_id IS NULL)
			   AND a.status IN ('active', 'partially_returned')",
			$academic_year_id
		) );
		$received_set = array_flip( $received_family_ids );

		$items = array();
		foreach ( (array) $families as $f ) {
			$oracle_id = (string) $f['oracle_family_id'];
			if ( isset( $received_set[ $oracle_id ] ) ) {
				continue;
			}
			$items[] = array(
				'family_id'           => absint( $oracle_id ),
				'oracle_family_id'    => $oracle_id,
				'core_family_uid'     => (string) $f['family_uid'],
				'sponsor_name'        => (string) ( $f['sponsor_full_name'] ?? '' ),
				'father_name'         => (string) ( $f['father_name'] ?? '' ),
				'father_mobile'       => (string) ( $f['father_mobile'] ?? '' ),
				'mother_name'         => (string) ( $f['mother_name'] ?? '' ),
				'mother_mobile'       => (string) ( $f['mother_mobile'] ?? '' ),
				'students'            => array(),
				'student_rows'        => array(),
				'balance'             => null,
				'monthly_due'         => null,
				'monthly_due_source'  => 'unavailable',
				'financial_available' => false,
			);
		}
		return $items;
	}

}
