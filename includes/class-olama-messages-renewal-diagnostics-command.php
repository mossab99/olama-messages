<?php
/**
 * WP-CLI diagnostics for the renewal reminder audience.
 *
 * @package Olama_Messages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Olama_Messages_Renewal_Diagnostics_Command {
	public static function run( $args, $assoc_args ) {
		if ( ! function_exists( 'olama_core' ) ) {
			WP_CLI::error( 'Olama Core is not active.' );
		}

		$core = olama_core();
		if ( ! is_object( $core ) || ! method_exists( $core, 'audiences' ) || ! method_exists( $core, 'student_years' ) || ! method_exists( $core, 'academic' ) || ! method_exists( $core, 'academic_context' ) ) {
			WP_CLI::error( 'Olama Core services are unavailable.' );
		}

		$current_year = '';
		if ( ! empty( $assoc_args['study_year'] ) ) {
			$current_year = sanitize_text_field( (string) $assoc_args['study_year'] );
		} elseif ( ! empty( $args[0] ) ) {
			$current_year = sanitize_text_field( (string) $args[0] );
		} else {
			$context = $core->academic_context();
			if ( method_exists( $context, 'current_year' ) ) {
				$year = $context->current_year();
				$current_year = $year ? (string) ( ! empty( $year->code ) ? $year->code : $year->year_name ) : '';
			}
		}

		if ( '' === $current_year ) {
			WP_CLI::error( 'Provide a study year, for example: --study_year=2026-2027' );
		}

		$audiences = $core->audiences();
		if ( ! method_exists( $audiences, 'query_renewal_candidates' ) || ! method_exists( $audiences, 'get_previous_study_year_code' ) ) {
			WP_CLI::error( 'Olama Core does not expose the Renewal Reminder audience contract.' );
		}

		$previous_year = $audiences->get_previous_study_year_code( $current_year );
		if ( '' === $previous_year ) {
			WP_CLI::error( 'Unable to resolve the previous academic year for the supplied study year.' );
		}

		$previous_rows = $core->student_years()->for_study_year( $previous_year );
		$current_rows = $core->student_years()->for_study_year( $current_year );
		$transferred_rows = $core->academic()->transferred_students( $previous_year );
		$renewal_result = $audiences->query_renewal_candidates( $current_year, array( 'limit' => 1, 'offset' => 0 ) );

		$previous_families = array();
		foreach ( (array) $previous_rows as $row ) {
			if ( ! empty( $row['family_uid'] ) ) {
				$previous_families[ (string) $row['family_uid'] ] = true;
			}
		}

		$current_families = array();
		foreach ( (array) $current_rows as $row ) {
			if ( ! empty( $row['family_uid'] ) ) {
				$current_families[ (string) $row['family_uid'] ] = true;
			}
		}

		$transferred_families = array();
		foreach ( (array) $transferred_rows as $row ) {
			if ( ! empty( $row['family_id'] ) ) {
				$transferred_families[ (string) $row['family_id'] ] = true;
			}
		}

		$payload = array(
			'current_year' => $current_year,
			'previous_year' => $previous_year,
			'previous_year_families' => count( $previous_families ),
			'current_year_families' => count( $current_families ),
			'transferred_families' => count( $transferred_families ),
			'renewal_candidate_families' => (int) ( $renewal_result['total'] ?? 0 ),
		);

		WP_CLI::line( wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}
}
