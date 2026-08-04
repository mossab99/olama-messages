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
	 * @param  string $study_year  Study year string, e.g. "2025-2026".
	 * @param  array  $filters     Reserved for future filtering.
	 * @return array  Recipient-item arrays compatible with preview_candidates().
	 */
	public function get_families_missing_items( $study_year, array $filters = array() ) {
		if ( ! $this->is_available() ) {
			return array();
		}

		global $wpdb;

		$year_id = $this->get_active_year_id();
		if ( ! $year_id ) {
			return array();
		}

		$allocations = $this->get_allocations( $year_id );

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

		// Load all enrolled students for this year with family contact data.
		$students = $wpdb->get_results( $wpdb->prepare(
			"SELECT sy.student_uid, sy.family_uid, sy.class_id AS grade_id,
			        sy.class_name AS grade_name, sy.section_name, s.student_name,
			        f.oracle_family_id, f.sponsor_full_name, f.father_name, f.father_mobile,
			        f.mother_name, f.mother_mobile
			 FROM `{$student_years_table}` sy
			 INNER JOIN `{$students_table}` s ON s.student_uid = sy.student_uid
			 INNER JOIN `{$families_table}` f ON f.family_uid = sy.family_uid
			 WHERE sy.study_year IN (%s, %s) AND f.is_active = 1",
			$study_year_clean,
			$alternate_year
		), ARRAY_A );

		if ( empty( $students ) ) {
			return array();
		}

		// If no allocations are configured, use the custom-items fallback.
		if ( empty( $allocations ) ) {
			return $this->get_families_missing_custom_items( $student_years_table, $students_table, $families_table, $study_year_clean, $alternate_year, $year_id );
		}

		// Check each student for missing books.
		$missing_families = array(); // keyed by oracle_family_id

		foreach ( $students as $stud ) {
			$grade_id        = (string) ( $stud['grade_id'] ?? '' );
			$allocated_items = isset( $allocations[ $grade_id ] ) ? array_map( 'intval', (array) $allocations[ $grade_id ] ) : array();

			// Honour a saved package override (student-specific subset).
			$package_row = $wpdb->get_row( $wpdb->prepare(
				"SELECT item_ids FROM {$wpdb->prefix}os_student_book_packages
				 WHERE student_uid = %s AND academic_year_id = %d
				 ORDER BY id DESC LIMIT 1",
				$stud['student_uid'],
				$year_id
			), ARRAY_A );
			if ( $package_row && ! empty( $package_row['item_ids'] ) ) {
				$package_items = json_decode( $package_row['item_ids'], true );
				if ( is_array( $package_items ) ) {
					$allocated_items = array_map( 'intval', $package_items );
				}
			}

			if ( empty( $allocated_items ) ) {
				continue;
			}

			// Fetch which of those items the student already received.
			$received_ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT item_id FROM {$wpdb->prefix}os_assignments
				 WHERE assignee_type = 'student' AND assignee_id = %s
				   AND academic_year_id = %d
				   AND status IN ('active', 'partially_returned')",
				$stud['student_uid'],
				$year_id
			) ) );

			$missing_ids = array_diff( $allocated_items, $received_ids );
			if ( empty( $missing_ids ) ) {
				continue;
			}

			// Register the family as having missing items.
			$oracle_id = (string) $stud['oracle_family_id'];
			if ( ! isset( $missing_families[ $oracle_id ] ) ) {
				$missing_families[ $oracle_id ] = array(
					'family_id'           => absint( $oracle_id ),
					'oracle_family_id'    => $oracle_id,
					'core_family_uid'     => (string) $stud['family_uid'],
					'sponsor_name'        => (string) ( $stud['sponsor_full_name'] ?? '' ),
					'father_name'         => (string) ( $stud['father_name'] ?? '' ),
					'father_mobile'       => (string) ( $stud['father_mobile'] ?? '' ),
					'mother_name'         => (string) ( $stud['mother_name'] ?? '' ),
					'mother_mobile'       => (string) ( $stud['mother_mobile'] ?? '' ),
					'students'            => array(),
					'student_rows'        => array(),
					'balance'             => null,
					'monthly_due'         => null,
					'monthly_due_source'  => 'unavailable',
					'financial_available' => false,
				);
			}

			$missing_families[ $oracle_id ]['students'][]     = (string) $stud['student_name'];
			$missing_families[ $oracle_id ]['student_rows'][] = array(
				'student_name' => (string) $stud['student_name'],
				'class_name'   => (string) ( $stud['grade_name'] ?? '' ),
				'section_name' => (string) ( $stud['section_name'] ?? '' ),
				'study_year'   => $study_year_clean,
			);
		}

		return array_values( $missing_families );
	}

	/**
	 * Fallback: when no allocations are configured, return families
	 * that have zero custom-item (os_assignments) records for the year.
	 */
	private function get_families_missing_custom_items( $sy_table, $students_table, $families_table, $study_year, $alternate_year, $year_id ) {
		global $wpdb;

		$families = $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT f.oracle_family_id, f.family_uid, f.sponsor_full_name,
			        f.father_name, f.father_mobile, f.mother_name, f.mother_mobile
			 FROM `{$families_table}` f
			 INNER JOIN `{$sy_table}` sy ON sy.family_uid = f.family_uid
			 WHERE sy.study_year IN (%s, %s) AND f.is_active = 1",
			$study_year,
			$alternate_year
		), ARRAY_A );

		// Oracle family IDs that already received at least one item.
		$received_family_ids = (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT stud.oracle_family_id
			 FROM {$wpdb->prefix}os_assignments a
			 INNER JOIN `{$students_table}` stud ON stud.student_uid = a.assignee_id
			 WHERE a.assignee_type = 'student'
			   AND a.academic_year_id = %d
			   AND a.status IN ('active', 'partially_returned')",
			$year_id
		) );
		$received_set = array_flip( $received_family_ids );

		$items = array();
		foreach ( $families as $f ) {
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
