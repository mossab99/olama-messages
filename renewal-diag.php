<?php
/**
 * Temporary diagnostic script — run once then delete.
 * Access: https://yourlocalsite/wp-content/plugins/olama-messages/renewal-diag.php
 *   OR via WP-CLI: wp eval-file renewal-diag.php
 */

// Bootstrap WordPress.
$wp_root = dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';
if ( ! defined( 'ABSPATH' ) && file_exists( $wp_root . 'wp-load.php' ) ) {
    require_once $wp_root . 'wp-load.php';
} elseif ( ! defined( 'ABSPATH' ) ) {
    die( 'Cannot find WordPress root.' );
}

global $wpdb;

header( 'Content-Type: text/plain; charset=utf-8' );

echo "=== Renewal Reminder Diagnostics ===\n\n";

// 1. Check table existence.
$prefix = $wpdb->prefix;
$tables = array(
    'olama_core_families',
    'olama_core_students',
    'olama_core_student_years',
    'olama_core_academic_transferred_students',
);
echo "-- Table existence --\n";
foreach ( $tables as $t ) {
    $full = $prefix . $t;
    $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) === $full;
    echo $full . ': ' . ( $exists ? 'EXISTS' : 'MISSING' ) . "\n";
}

echo "\n-- Study years in olama_core_student_years --\n";
$years = $wpdb->get_col( "SELECT DISTINCT study_year FROM `{$prefix}olama_core_student_years` ORDER BY study_year DESC LIMIT 10" );
foreach ( $years as $y ) {
    $count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT oracle_family_id) FROM `{$prefix}olama_core_student_years` WHERE study_year = %s", $y ) );
    echo "  {$y}: {$count} distinct families\n";
}

echo "\n-- Study years in olama_core_academic_transferred_students --\n";
$exists_transfer = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $prefix . 'olama_core_academic_transferred_students' ) ) === $prefix . 'olama_core_academic_transferred_students';
if ( $exists_transfer ) {
    $tyears = $wpdb->get_col( "SELECT DISTINCT study_year FROM `{$prefix}olama_core_academic_transferred_students` ORDER BY study_year DESC LIMIT 10" );
    if ( $tyears ) {
        foreach ( $tyears as $y ) {
            $count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT family_id) FROM `{$prefix}olama_core_academic_transferred_students` WHERE study_year = %s", $y ) );
            echo "  {$y}: {$count} distinct families\n";
        }
    } else {
        echo "  (table exists but is empty)\n";
    }
} else {
    echo "  TABLE MISSING\n";
}

echo "\n-- canonical_study_year resolution for '2026-2027' --\n";
if ( function_exists( 'olama_core' ) ) {
    $core = olama_core();
    if ( method_exists( $core, 'academic_calendar' ) ) {
        $calendar = $core->academic_calendar();
        $year = $calendar->resolve_external_year( 'oracle', '2026-2027' );
        if ( $year ) {
            $code = $calendar->canonical_year_code( (int) $year->id );
            echo "  resolved: {$code}\n";
        } else {
            echo "  UNRESOLVED (no oracle mapping for '2026-2027')\n";
            // Show all oracle mappings.
            $mappings = $wpdb->get_results( "SELECT source_year_code, source_system FROM `{$prefix}olama_core_academic_year_source_mappings` LIMIT 20", ARRAY_A );
            echo "  -- All source mappings --\n";
            foreach ( $mappings as $m ) {
                echo "    {$m['source_system']} => {$m['source_year_code']}\n";
            }
        }
    }
    if ( method_exists( $core, 'audiences' ) ) {
        $audiences = $core->audiences();
        if ( method_exists( $audiences, 'get_sync_health' ) ) {
            $health = $audiences->get_sync_health( 'finance_renewal_reminder', '2026-2027' );
            echo "\n-- Sync health for finance_renewal_reminder / 2026-2027 --\n";
            echo '  ready:         ' . ( $health['ready'] ? 'YES' : 'NO' ) . "\n";
            echo '  current_year:  ' . ( $health['current_year'] ?? 'n/a' ) . "\n";
            echo '  previous_year: ' . ( $health['previous_year'] ?? 'n/a' ) . "\n";
            echo '  sources:' . "\n";
            foreach ( $health['sources'] ?? array() as $k => $s ) {
                echo "    {$k}: rows={$s['row_count']} required={$s['required']} ready=" . ( $s['ready'] ? 'Y' : 'N' ) . "\n";
            }
        }
    }
} else {
    echo "  olama_core() not available\n";
}
