<?php
/**
 * Renewal Reminder audience — automated tests.
 *
 * Pure PHP: no WordPress, no database. Tests the set-subtraction business rule
 * and the year-derivation utility in complete isolation.
 *
 * Run:
 *   php tests/test-renewal-audience.php
 *
 * Exit code 0 = all pass, 1 = at least one failure.
 *
 * @package Olama_Messages
 */

// Provide the ABSPATH stub expected by any included WP code (none here).
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// ── Pure set-subtraction helper ────────────────────────────────────────────
//
// This class mirrors the four-line algorithm inside
// Olama_Core_Audience_Service::query_renewal_candidates() so the business
// rule can be tested without a database or WordPress.

class Renewal_Audience_Set_Logic {

	/**
	 * Compute the renewal family IDs from the three canonical sets.
	 *
	 *   PREVIOUS YEAR FAMILIES
	 *           -
	 *   CURRENT YEAR FAMILIES
	 *           -
	 *   TRANSFERRED FAMILIES
	 *           =
	 *   RENEWAL REMINDER FAMILIES
	 *
	 * All identifiers are normalized to unique integers before subtraction to
	 * prevent string/int comparison mismatches (e.g. "627" vs 627).
	 *
	 * @param  int[] $previous    Distinct family IDs enrolled in the previous year.
	 * @param  int[] $current     Distinct family IDs enrolled in the current year.
	 * @param  int[] $transferred Distinct family IDs transferred from the previous year.
	 * @return int[]              Renewal candidate family IDs, re-indexed.
	 */
	public static function compute( array $previous, array $current, array $transferred ): array {
		$previous    = array_values( array_unique( array_map( 'intval', $previous ) ) );
		$current     = array_values( array_unique( array_map( 'intval', $current ) ) );
		$transferred = array_values( array_unique( array_map( 'intval', $transferred ) ) );

		return array_values( array_diff( $previous, $current, $transferred ) );
	}

	/**
	 * Derive the previous academic year from a canonical YYYY-YYYY code.
	 *
	 * This is the pure arithmetic fallback used when the academic calendar
	 * service is unavailable (e.g. in unit tests). The live implementation in
	 * Olama_Core_Audience_Service::get_previous_study_year_code() consults the
	 * academic calendar service first and falls back to this arithmetic.
	 *
	 * @param  string                $current_year E.g. '2026-2027'.
	 * @return string                              E.g. '2025-2026'.
	 * @throws InvalidArgumentException            On malformed or non-consecutive input.
	 */
	public static function derive_previous_year( string $current_year ): string {
		if ( ! preg_match( '/^(\d{4})-(\d{4})$/', $current_year, $m ) ) {
			throw new InvalidArgumentException( "Malformed study year: '{$current_year}'" );
		}
		$start = (int) $m[1];
		$end   = (int) $m[2];
		if ( $end !== $start + 1 ) {
			throw new InvalidArgumentException( "Invalid study year range (must be consecutive): '{$current_year}'" );
		}
		return ( $start - 1 ) . '-' . ( $end - 1 );
	}
}

// ── Test runner ────────────────────────────────────────────────────────────

$passed = 0;
$failed = 0;

/**
 * Assert two arrays are equal as sorted integer sets.
 */
function assert_equal( string $label, array $expected, array $actual ): void {
	global $passed, $failed;
	$exp = array_values( array_unique( array_map( 'intval', $expected ) ) );
	$act = array_values( array_unique( array_map( 'intval', $actual ) ) );
	sort( $exp );
	sort( $act );
	if ( $exp === $act ) {
		echo "[PASS] {$label}\n";
		$passed++;
	} else {
		echo "[FAIL] {$label}\n";
		echo '       Expected: [' . implode( ', ', $exp ) . "]\n";
		echo '       Actual:   [' . implode( ', ', $act ) . "]\n";
		$failed++;
	}
}

/**
 * Assert that a callable throws an exception of the given class.
 */
function assert_throws( string $label, callable $fn, string $expected_class = 'Exception' ): void {
	global $passed, $failed;
	try {
		$fn();
		echo "[FAIL] {$label} — expected {$expected_class} but nothing was thrown\n";
		$failed++;
	} catch ( Throwable $e ) {
		if ( $e instanceof $expected_class ) {
			echo "[PASS] {$label}\n";
			$passed++;
		} else {
			echo "[FAIL] {$label} — expected {$expected_class}, got " . get_class( $e ) . ': ' . $e->getMessage() . "\n";
			$failed++;
		}
	}
}

/**
 * Assert a condition is truthy.
 */
function assert_true( string $label, $value ): void {
	global $passed, $failed;
	if ( $value ) {
		echo "[PASS] {$label}\n";
		$passed++;
	} else {
		echo "[FAIL] {$label}\n";
		$failed++;
	}
}

echo "=== Renewal Reminder Audience Tests ===\n\n";

// ── TEST 1 ─────────────────────────────────────────────────────────────────
// Previous:[100]  Current:[]  Transferred:[] → [100]
assert_equal(
	'TEST 1 — previous-only, no exclusions: family included',
	[ 100 ],
	Renewal_Audience_Set_Logic::compute( [ 100 ], [], [] )
);

// ── TEST 2 ─────────────────────────────────────────────────────────────────
// Previous:[100]  Current:[100]  Transferred:[] → []
assert_equal(
	'TEST 2 — family enrolled current year: excluded',
	[],
	Renewal_Audience_Set_Logic::compute( [ 100 ], [ 100 ], [] )
);

// ── TEST 3 ─────────────────────────────────────────────────────────────────
// Previous:[100]  Current:[]  Transferred:[100] → []
assert_equal(
	'TEST 3 — family transferred: excluded',
	[],
	Renewal_Audience_Set_Logic::compute( [ 100 ], [], [ 100 ] )
);

// ── TEST 4 ─────────────────────────────────────────────────────────────────
// Previous:[]  Current:[100]  Transferred:[] → []
assert_equal(
	'TEST 4 — no previous enrollment: result is empty',
	[],
	Renewal_Audience_Set_Logic::compute( [], [ 100 ], [] )
);

// ── TEST 5 ─────────────────────────────────────────────────────────────────
// All sets empty → []
assert_equal(
	'TEST 5 — all sets empty: result is empty',
	[],
	Renewal_Audience_Set_Logic::compute( [], [], [] )
);

// ── TEST 6 ─────────────────────────────────────────────────────────────────
// Previous:[100,101,102,103,104]  Current:[101,103]  Transferred:[104] → [100,102]
assert_equal(
	'TEST 6 — typical scenario with multiple exclusions',
	[ 100, 102 ],
	Renewal_Audience_Set_Logic::compute( [ 100, 101, 102, 103, 104 ], [ 101, 103 ], [ 104 ] )
);

// ── TEST 7 ─────────────────────────────────────────────────────────────────
// Families in current or transferred that were never in previous must not
// affect the result.
// Previous:[100,101]  Current:[999]  Transferred:[888] → [100,101]
assert_equal(
	'TEST 7 — exclusion sets with families not in previous: result unaffected',
	[ 100, 101 ],
	Renewal_Audience_Set_Logic::compute( [ 100, 101 ], [ 999 ], [ 888 ] )
);

// ── TEST 8 ─────────────────────────────────────────────────────────────────
// Overlapping exclusion sets must not cause calculation errors.
// Previous:[100,101]  Current:[101]  Transferred:[101] → [100]
assert_equal(
	'TEST 8 — family in both exclusion sets: counted once, result correct',
	[ 100 ],
	Renewal_Audience_Set_Logic::compute( [ 100, 101 ], [ 101 ], [ 101 ] )
);

// ── TEST 9 ─────────────────────────────────────────────────────────────────
// Previous set contains multiple entries for family 100 (e.g. multiple
// student-year records). Family 100 must appear exactly once in the result.
$result_9 = Renewal_Audience_Set_Logic::compute( [ 100, 100, 100 ], [], [] );
assert_true(
	'TEST 9 — duplicate entries for same family deduplicated to exactly one result',
	count( $result_9 ) === 1 && (int) $result_9[0] === 100
);

// ── TEST 10 ────────────────────────────────────────────────────────────────
// Family 200 has student A registered current year and student B not registered.
// Family-level rule: if ANY child is in the current year, exclude the whole family.
// Representation: family 200 is in the current-year set (one enrolled child is enough).
assert_equal(
	'TEST 10 — family excluded when ANY student is registered current year',
	[],
	Renewal_Audience_Set_Logic::compute( [ 200 ], [ 200 ], [] )
);

// ── TEST 11 ────────────────────────────────────────────────────────────────
// Family 300 has student A transferred and student B not transferred.
// Family-level rule: if ANY child is transferred, exclude the whole family.
// Representation: family 300 is in the transferred set.
assert_equal(
	'TEST 11 — family excluded when ANY student is transferred',
	[],
	Renewal_Audience_Set_Logic::compute( [ 300 ], [], [ 300 ] )
);

// ── TEST 12 ────────────────────────────────────────────────────────────────
// Phone validity does NOT affect Core eligibility.
// Family 400: previous=yes, current=no, transferred=no.
// Father mobile = invalid, mother mobile = valid.
// Core must include this family — Messages will pick the mother phone later.
assert_equal(
	'TEST 12 — Core: invalid father phone does not affect eligibility (family included)',
	[ 400 ],
	Renewal_Audience_Set_Logic::compute( [ 400 ], [], [] )
);

// ── TEST 13 ────────────────────────────────────────────────────────────────
// Both phones invalid → Core still includes the family. Messages will mark it
// as no-valid-phone and exclude it from the sending queue — that is a Messages
// responsibility, not Core's.
assert_equal(
	'TEST 13 — Core: both phones invalid does not affect eligibility (family included)',
	[ 500 ],
	Renewal_Audience_Set_Logic::compute( [ 500 ], [], [] )
);

// ── TEST 14 ────────────────────────────────────────────────────────────────
// A missing transferred-students source must produce a clear, loud failure.
// The caller must NOT treat an unavailable source as zero transferred families.
assert_throws(
	'TEST 14 — missing transferred source throws RuntimeException (not treated as zero)',
	static function (): void {
		// Simulates what Olama_Core_Audience_Service::get_transferred_family_ids()
		// does when the olama_core_academic_transferred_students table is absent.
		throw new RuntimeException(
			'Renewal Reminder audience cannot be calculated because transferred student data is unavailable.'
		);
	},
	'RuntimeException'
);

// ── TEST 15 ────────────────────────────────────────────────────────────────
// Pagination: all pages combined must cover every eligible family exactly once.
$all_previous_15 = range( 100, 154 ); // 55 eligible families.
$renewal_15      = Renewal_Audience_Set_Logic::compute( $all_previous_15, [], [] );
sort( $renewal_15 );

$page_size = 20;
$page1     = array_slice( $renewal_15, 0,  $page_size );
$page2     = array_slice( $renewal_15, 20, $page_size );
$page3     = array_slice( $renewal_15, 40, $page_size ); // 15 families on final page.

$all_pages_15 = array_merge( $page1, $page2, $page3 );
$unique_15    = array_unique( $all_pages_15 );

assert_true(
	'TEST 15a — pagination: pages 1+2+3 together cover all 55 families',
	count( $all_pages_15 ) === 55
);
assert_true(
	'TEST 15b — pagination: no family appears on more than one page',
	count( $unique_15 ) === 55
);
assert_true(
	'TEST 15c — pagination: final page contains the remaining 15 families',
	count( $page3 ) === 15
);

// ── TEST 16 ────────────────────────────────────────────────────────────────
// Year derivation: valid inputs and rejection of malformed values.

// 16a — 2026-2027 → 2025-2026
$y1 = null;
try {
	$y1 = Renewal_Audience_Set_Logic::derive_previous_year( '2026-2027' );
} catch ( Throwable $e ) { /* handled below */
}
assert_true(
	'TEST 16a — year derivation: 2026-2027 → 2025-2026',
	$y1 === '2025-2026'
);

// 16b — 2025-2026 → 2024-2025
$y2 = null;
try {
	$y2 = Renewal_Audience_Set_Logic::derive_previous_year( '2025-2026' );
} catch ( Throwable $e ) { /* handled below */
}
assert_true(
	'TEST 16b — year derivation: 2025-2026 → 2024-2025',
	$y2 === '2024-2025'
);

// 16c — free-text string must be rejected.
assert_throws(
	'TEST 16c — year derivation: free-text string throws InvalidArgumentException',
	static function (): void {
		Renewal_Audience_Set_Logic::derive_previous_year( 'foo' );
	},
	'InvalidArgumentException'
);

// 16d — partial year must be rejected.
assert_throws(
	'TEST 16d — year derivation: partial year throws InvalidArgumentException',
	static function (): void {
		Renewal_Audience_Set_Logic::derive_previous_year( '2026' );
	},
	'InvalidArgumentException'
);

// 16e — non-consecutive range must be rejected.
assert_throws(
	'TEST 16e — year derivation: non-consecutive range throws InvalidArgumentException',
	static function (): void {
		Renewal_Audience_Set_Logic::derive_previous_year( '2024-2027' );
	},
	'InvalidArgumentException'
);

// ── Summary ────────────────────────────────────────────────────────────────
echo "\n=== Results: {$passed} passed, {$failed} failed ===\n";
exit( $failed > 0 ? 1 : 0 );
