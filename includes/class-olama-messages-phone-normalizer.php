<?php
/**
 * Olama Messages Phone Normalizer service.
 *
 * Cleanses and normalizes Jordanian mobile numbers to E.164 format.
 *
 * @package Olama_Messages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Olama_Messages_Phone_Normalizer {

	/**
	 * Normalize a Jordanian mobile number to E.164 format.
	 *
	 * Normalization rules:
	 * - Strip spaces, hyphens, parentheses, and leading/trailing whitespace.
	 * - Mobile networks must start with: 077, 078, 079, 96277, 96278, 96279, +96277, +96278, or +96279.
	 * - Cleaned length must be:
	 *   - 10 digits for local format (079XXXXXXX -> +96279XXXXXXX)
	 *   - 12 digits for international without + (96279XXXXXXX -> +96279XXXXXXX)
	 *   - 13 characters for E.164 (+96279XXXXXXX)
	 * - Rejects landlines (e.g. 06XXXXXXX) and non-Jordanian numbers.
	 *
	 * @param  string|null $phone The raw phone number input.
	 * @return array {
	 *     valid:  bool,
	 *     raw:    string,
	 *     e164:   ?string,
	 *     reason: ?string,
	 * }
	 */
	public function normalize_jordan_mobile( ?string $phone ) {
		$raw = (string) $phone;
		$trimmed = trim( $raw );

		if ( $trimmed === '' ) {
			return array(
				'valid'  => false,
				'raw'    => $raw,
				'e164'   => null,
				'reason' => 'empty_phone',
			);
		}

		// Strip all spaces, hyphens, parentheses, and dots.
		// Keep digits and the leading plus sign.
		$clean = preg_replace( '/[^\d+]/', '', $trimmed );

		// Extract only digits to check length and structure.
		$digits = preg_replace( '/\D/', '', $clean );

		// Determine if it is a Jordan number and normalize it.
		// Jordan mobile prefixes: 77 (Orange), 78 (Umniah), 79 (Zain).
		if ( preg_match( '/^(?:\+?962|0)?(7[789]\d{7})$/', $digits, $matches ) ) {
			$mobile_part = $matches[1]; // e.g. 791234567
			$e164 = '+962' . $mobile_part;

			return array(
				'valid'  => true,
				'raw'    => $raw,
				'e164'   => $e164,
				'reason' => null,
			);
		}

		// If it doesn't match Jordan mobile pattern:
		// Check for landlines (e.g. starting with 02, 03, 05, 06, 072, etc. that aren't mobile)
		if ( preg_match( '/^(?:\+?962|0)?([2356]\d{7})$/', $digits ) ) {
			return array(
				'valid'  => false,
				'raw'    => $raw,
				'e164'   => null,
				'reason' => 'landline_rejected',
			);
		}

		// Otherwise general invalid mobile reason
		return array(
			'valid'  => false,
			'raw'    => $raw,
			'e164'   => null,
			'reason' => 'invalid_mobile',
		);
	}
}
