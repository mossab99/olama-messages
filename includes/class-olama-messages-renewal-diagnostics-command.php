<?php
/**
 * WP-CLI diagnostics for the renewal reminder audience.
 *
 * Reports counts and overlap statistics only.
 * No PII, no raw family identifiers, no names, no phone numbers.
 *
 * Usage:
 *   wp olama-messages renewal-diagnostics --study_year=2026-2027
 *
 * @package Olama_Messages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Olama_Messages_Renewal_Diagnostics_Command {

	public static function run( $args, $assoc_args ) {

		// ── Verify Olama Core is available ─────────────────────────────────
		if ( ! function_exists( 'olama_core' ) ) {
			WP_CLI::error( 'Olama Core is not active.' );
		}

		$core = olama_core();
		if ( ! is_object( $core )
			|| ! method_exists( $core, 'audiences' )
			|| ! method_exists( $core, 'academic_context' ) ) {
			WP_CLI::error( 'Olama Core services are unavailable.' );
		}

		$audiences = $core->audiences();
		if ( ! method_exists( $audiences, 'get_previous_study_year_code' )
			|| ! method_exists( $audiences, 'get_family_ids_for_study_year' )
			|| ! method_exists( $audiences, 'get_transferred_family_ids' ) ) {
			WP_CLI::error(
				'Olama Core does not expose the required renewal audience helpers. ' .
				'Update Olama Core to the latest version.'
			);
		}

		// ── Resolve the study year ─────────────────────────────────────────
		$current_year = '';
		if ( ! empty( $assoc_args['study_year'] ) ) {
			$current_year = sanitize_text_field( (string) $assoc_args['study_year'] );
		} elseif ( ! empty( $args[0] ) ) {
			$current_year = sanitize_text_field( (string) $args[0] );
		} else {
			$context = $core->academic_context();
			if ( method_exists( $context, 'current_year' ) ) {
				$year         = $context->current_year();
				$current_year = $year
					? (string) ( ! empty( $year->code ) ? $year->code : $year->year_name )
					: '';
			}
		}

		if ( '' === $current_year ) {
			WP_CLI::error( 'Provide a study year, for example: --study_year=2026-2027' );
		}

		$previous_year = $audiences->get_previous_study_year_code( $current_year );
		if ( '' === $previous_year ) {
			WP_CLI::error( 'Unable to resolve the previous academic year for: ' . $current_year );
		}

		// ── Collect the three intermediate sets ────────────────────────────
		//
		//   Stage 1 — PREVIOUS year family IDs
		//   Stage 2 — CURRENT year family IDs
		//   Stage 3 — TRANSFERRED family IDs (throws if source is unavailable)
		//
		try {
			$previous_ids    = $audiences->get_family_ids_for_study_year( $previous_year );
			$current_ids     = $audiences->get_family_ids_for_study_year( $current_year );
			$transferred_ids = $audiences->get_transferred_family_ids( $previous_year );
		} catch ( RuntimeException $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		// ── Stage 4: Set subtraction ───────────────────────────────────────
		//
		//   PREVIOUS YEAR FAMILIES
		//           -
		//   CURRENT YEAR FAMILIES
		//           -
		//   TRANSFERRED FAMILIES
		//           =
		//   RENEWAL REMINDER FAMILIES
		//
		$renewal_ids = array_values( array_diff( $previous_ids, $current_ids, $transferred_ids ) );

		// ── Overlap analysis ───────────────────────────────────────────────
		// Families in previous that also appear in current.
		$prev_current_overlap = array_intersect( $previous_ids, $current_ids );

		// Families in previous that also appear in transferred.
		$prev_transferred_overlap = array_intersect( $previous_ids, $transferred_ids );

		// Families actually removed by current (those in previous ∩ current).
		$removed_current = count( $prev_current_overlap );

		// Families actually removed by transferred but NOT already removed by
		// current (i.e., the additional unique contribution of the transfer set).
		$removed_transferred = count(
			array_diff( array_values( $prev_transferred_overlap ), array_values( $prev_current_overlap ) )
		);

		// ── Build diagnostic payload ───────────────────────────────────────
		// Counts only — no PII, no raw identifiers, no names, no phones.
		$payload = array(
			'current_year'                     => $current_year,
			'previous_year'                    => $previous_year,
			'previous_year_families'           => count( $previous_ids ),
			'current_year_families'            => count( $current_ids ),
			'transferred_families'             => count( $transferred_ids ),
			'previous_and_current_overlap'     => count( $prev_current_overlap ),
			'previous_and_transferred_overlap' => count( $prev_transferred_overlap ),
			'removed_current_families'         => $removed_current,
			'removed_transferred_families'     => $removed_transferred,
			'renewal_candidate_families'       => count( $renewal_ids ),
		);

		WP_CLI::line(
			wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT )
		);
	}
}
