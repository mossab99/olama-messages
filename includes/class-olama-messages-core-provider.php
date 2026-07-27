<?php
/**
 * Read-only adapter from Olama Messages to the public Olama Core services.
 *
 * @package Olama_Messages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Olama_Messages_Core_Provider {
	const FINANCIAL_WARNING = 'No synchronized financial data is available for this family and study year.';
	const FINANCIAL_ADMIN_NOTICE = 'Collection audiences use synchronized financial data stored in Olama Core.';
	const FINANCIAL_ARABIC_NOTICE = 'لا توجد بيانات مالية متزامنة لهذه العائلة والسنة الدراسية في Olama Core.';

	public function is_core_available() {
		return function_exists( 'olama_core' )
			&& method_exists( olama_core(), 'audiences' )
			&& is_object( olama_core()->audiences() )
			&& olama_core()->audiences()->is_ready();
	}

	public function is_financial_provider_ready() {
		return $this->is_core_available() && is_object( olama_core()->financial() );
	}

	public function get_available_study_years() {
		$years = $this->is_core_available() ? olama_core()->audiences()->get_study_years() : array();
		$current = $this->get_current_study_year();
		$years = array_values( array_filter( array_map( 'strval', (array) $years ) ) );
		if ( '' !== $current ) {
			$years = array_values( array_diff( $years, array( $current ) ) );
			array_unshift( $years, $current );
		}
		return $years;
	}

	public function get_current_study_year() {
		if ( ! function_exists( 'olama_core' ) || ! method_exists( olama_core(), 'academic_context' ) ) {
			return '';
		}
		$year = olama_core()->academic_context()->current_year();
		return $year ? (string) ( ! empty( $year->code ) ? $year->code : $year->year_name ) : '';
	}

	public function get_available_class_names( $study_year = '' ) {
		return $this->is_core_available() ? olama_core()->audiences()->get_class_names( $study_year ) : array();
	}

	public function get_available_section_names( $study_year = '' ) {
		return $this->is_core_available() ? olama_core()->audiences()->get_section_names( $study_year ) : array();
	}

	/**
	 * Read the active-family phone book from Olama Core.
	 */
	public function get_phone_book( $study_year, array $args = array() ) {
		if ( ! $this->is_core_available() || ! method_exists( olama_core()->audiences(), 'query_phone_book' ) ) {
			return array(
				'items'       => array(),
				'total'       => 0,
				'limit'       => absint( $args['limit'] ?? 50 ),
				'offset'      => absint( $args['offset'] ?? 0 ),
				'study_year'  => sanitize_text_field( (string) $study_year ),
				'data_source' => 'unavailable',
			);
		}
		return olama_core()->audiences()->query_phone_book( $study_year, $args );
	}

	/**
	 * Return target-specific Olama Core synchronization health.
	 */
	public function get_sync_health( $target_type = 'general', $study_year = '' ) {
		if ( ! $this->is_core_available() ) {
			return array(
				'ready'          => false,
				'target_type'    => sanitize_key( (string) $target_type ),
				'study_year'     => sanitize_text_field( (string) $study_year ),
				'last_synced_at' => null,
				'checked_at'     => current_time( 'mysql' ),
				'sources'        => array(),
				'message'        => 'Olama Core is inactive or its audience tables are unavailable.',
			);
		}

		$audiences = olama_core()->audiences();
		if ( method_exists( $audiences, 'get_sync_health' ) ) {
			return $audiences->get_sync_health( $target_type, $study_year );
		}

		// Compatibility with older Olama Core releases: audience readiness is
		// known, but per-source freshness is not yet exposed.
		return array(
			'ready'          => true,
			'target_type'    => sanitize_key( (string) $target_type ),
			'study_year'     => sanitize_text_field( (string) $study_year ),
			'last_synced_at' => null,
			'checked_at'     => current_time( 'mysql' ),
			'sources'        => array(),
			'message'        => 'Olama Core is ready; detailed synchronization timestamps are unavailable.',
		);
	}

	public function get_recipients_preview( array $filters = array() ) {
		if ( ! $this->is_core_available() ) {
			return array(
				'items' => array(),
				'total' => 0,
				'financial_available' => false,
				'financial_warning' => 'Olama Core is not active or its required data tables are unavailable.',
				'data_source' => 'unavailable',
			);
		}

		// Calls that do not specify a campaign target are ordinary family lookups.
		// Collection campaigns explicitly pass target_type=collection.
		if ( empty( $filters['target_type'] ) ) {
			$filters['target_type'] = 'general';
		}
		$result = olama_core()->audiences()->query( $filters );
		$items = is_array( $result['items'] ?? null ) ? $result['items'] : array();
		$financial_available = false;
		foreach ( $items as $item ) {
			if ( ! empty( $item['financial_available'] ) ) {
				$financial_available = true;
				break;
			}
		}

		$audience = $result['audience'] ?? sanitize_key( $filters['target_type'] );
		$warning = '';
		if ( 'financial' === $audience && ! $items ) {
			$warning = 'No synchronized financial recipients match this campaign.';
		}

		return array(
			'items' => $items,
			'total' => absint( $result['total'] ?? count( $items ) ),
			'limit' => absint( $result['limit'] ?? count( $items ) ),
			'offset' => absint( $result['offset'] ?? 0 ),
			'financial_available' => $financial_available,
			'financial_warning' => $warning,
			'data_source' => 'olama_core',
		);
	}

	public function get_family_payment_report( $family_id, $study_year = '' ) {
		if ( ! $this->is_core_available() ) {
			return null;
		}

		$family_id = sanitize_text_field( (string) $family_id );
		if ( $study_year === '' ) {
			$study_year = $this->get_current_study_year();
		}

		$report = olama_core()->financial()->get_payment_report( $family_id, $study_year );
		if ( is_array( $report ) ) {
			$report['students'] = $this->normalize_students( $report['students'] ?? array(), $study_year );
			$report['student_rows'] = $report['students'];
			$report['monthly_due'] = ! empty( $report['due_items'] ) ? (float) $report['due_items'][0]['due_amount'] : null;
			$report['monthly_due_source'] = $report['monthly_due'] !== null ? 'due_allocation' : 'unavailable';
			$report['data_source'] = 'olama_core';
			return $report;
		}

		$card = olama_core()->knowledge()->get_family_card( $family_id, $study_year );
		if ( ! $card ) {
			return null;
		}
		$family = $card['family'];
		$students = $this->normalize_students( $card['students'] ?? array(), $study_year );
		return array(
			'family_id' => absint( $family_id ),
			'oracle_family_id' => $family_id,
			'sponsor_name' => (string) ( $family['sponsor_full_name'] ?? '' ),
			'father_name' => (string) ( $family['father_name'] ?? '' ),
			'mother_name' => (string) ( $family['mother_name'] ?? '' ),
			'father_mobile' => (string) ( $family['father_mobile'] ?? '' ),
			'mother_mobile' => (string) ( $family['mother_mobile'] ?? '' ),
			'students' => $students,
			'student_rows' => $students,
			'study_year' => $study_year,
			'balance' => null,
			'monthly_due' => null,
			'monthly_due_source' => 'unavailable',
			'due_items' => array(),
			'last_payment' => null,
			'currency' => 'JOD',
			'financial_available' => false,
			'financial_warning' => self::FINANCIAL_WARNING,
			'data_source' => 'olama_core',
			'last_synced_at' => $card['last_synced_at'] ?? null,
		);
	}

	private function normalize_students( $students, $study_year ) {
		$normalized = array();
		foreach ( (array) $students as $student ) {
			if ( is_string( $student ) ) {
				$student = array( 'student_name' => $student );
			}
			if ( ! is_array( $student ) ) {
				continue;
			}
			$name = (string) ( $student['student_name'] ?? $student['name'] ?? '' );
			$normalized[] = array(
				'name' => $name,
				'student_name' => $name,
				'student_id' => $student['student_id'] ?? $student['oracle_student_id'] ?? null,
				'class_name' => (string) ( $student['class_name'] ?? '' ),
				'section_name' => (string) ( $student['section_name'] ?? '' ),
				'study_year' => (string) ( $student['study_year'] ?? $study_year ),
			);
		}
		return $normalized;
	}
}
