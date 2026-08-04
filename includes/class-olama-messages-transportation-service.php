<?php
/**
 * Read-only transportation adapter for Olama Core.
 *
 * Messages owns campaigns and delivery state only. Canonical transportation
 * records are created and updated by Olama Core through Oracle Sync.
 *
 * @package Olama_Messages
 */

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Messages_Transportation_Service {
    public static function get_config() {
        return array(
            'enabled' => true,
            'base_url' => '',
            'api_key' => '',
            'timeout' => 0,
            'configured' => function_exists('olama_core'),
            'data_source' => 'olama_core',
        );
    }

    public function is_available($force = false) {
        return function_exists('olama_core') && is_object(olama_core()->transportation());
    }

    public function get_bulk_recipients($study_year, array $filters = array()) {
        if (!$this->is_available()) {
            return false;
        }
        return olama_core()->transportation()->query_recipients($study_year, $filters);
    }

    public function sync_family_transportation($family_id, $study_year, $force = false) {
        return $this->get_family_rows($family_id, $study_year);
    }

    public function get_family_rows($family_id, $study_year) {
        if (!$this->is_available()) {
            return array();
        }
        return olama_core()->transportation()->get_family($family_id, $study_year);
    }

    public function get_route_options($study_year) {
        $empty = array('classes' => array(), 'sections' => array(), 'departure' => array(), 'arrival' => array(), 'rounds' => array());
        if (!$this->is_available()) {
            return $empty;
        }

        $options = olama_core()->transportation()->get_options($study_year);
        $map_bus = static function ($row) {
            return array(
                'id' => (string) (isset($row['bus_id']) ? $row['bus_id'] : ''),
                'name' => (string) (isset($row['bus_name']) && $row['bus_name'] !== '' ? $row['bus_name'] : (isset($row['bus_id']) ? $row['bus_id'] : '')),
                'seq' => isset($row['bus_seq']) ? $row['bus_seq'] : '',
            );
        };

        return array(
            'classes' => array_values(array_map(static function ($row) {
                return array('id' => (string) ($row['class_id'] ?? ''), 'name' => (string) ($row['class_name'] ?? ''));
            }, $options['classes'] ?? array())),
            'sections' => array_values(array_map(static function ($row) {
                return array('id' => (string) ($row['section_id'] ?? ''), 'class_id' => (string) ($row['class_id'] ?? ''), 'name' => (string) ($row['section_name'] ?? ''));
            }, $options['sections'] ?? array())),
            'departure' => array_values(array_map($map_bus, $options['departure_buses'] ?? array())),
            'arrival' => array_values(array_map($map_bus, $options['arrival_buses'] ?? array())),
            'rounds' => array_values(array_map(static function ($row) {
                return array('id' => (string) ($row['trans_route'] ?? ''), 'name' => (string) ($row['label'] ?? $row['trans_route'] ?? ''), 'seq' => '');
            }, $options['routes'] ?? array())),
        );
    }

    public function warm_sync_study_year($study_year) {
        return array();
    }

    public function filter_cached_families(array $families, $study_year, array $filters = array()) {
        if (!$this->is_available()) {
            return array();
        }
        return olama_core()->transportation()->filter_families($families, $study_year, $filters);
    }

    /**
     * Return families that are registered in transportation (have at least one
     * student-transportation row) but do NOT have GPS coordinates stored in the
     * olama_transport_family_stops table.
     *
     * @param  string $study_year  Canonical study year string, e.g. "2025-2026".
     * @return array  Recipient-item arrays compatible with preview_candidates().
     */
    public function get_families_without_gps( $study_year ) {
        if ( ! $this->is_available() ) {
            return array();
        }
        if ( ! function_exists( 'olama_core' ) ) {
            return array();
        }
        global $wpdb;

        // Verify that the transportation stops table exists in this installation.
        $stops_table = $wpdb->prefix . 'olama_transport_family_stops';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $stops_table ) ) !== $stops_table ) {
            return array();
        }

        // 1. Get all family IDs registered in transportation for this study year.
        $transport_table = $wpdb->prefix . 'olama_core_student_transportation';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $transport_table ) ) !== $transport_table ) {
            return array();
        }

        $study_year_clean = sanitize_text_field( (string) $study_year );
        $alternate_year   = strpos( $study_year_clean, '/' ) !== false
            ? str_replace( '/', '-', $study_year_clean )
            : str_replace( '-', '/', $study_year_clean );

        $transport_family_ids = (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT COALESCE(NULLIF(oracle_family_id, ''), family_id)
             FROM `{$transport_table}`
             WHERE study_year IN (%s, %s) AND (is_active IS NULL OR is_active = 1)",
            $study_year_clean,
            $alternate_year
        ) );

        if ( empty( $transport_family_ids ) ) {
            return array();
        }

        // 2. Get family UIDs that DO have GPS coordinates.
        $families_table = olama_core()->read_models()->table( 'families' );
        $gps_family_uids = (array) $wpdb->get_col(
            "SELECT DISTINCT f.oracle_family_id
             FROM `{$stops_table}` fs
             INNER JOIN `{$families_table}` f
               ON f.family_uid = fs.family_uid OR f.oracle_family_id = fs.oracle_family_id
             WHERE fs.latitude IS NOT NULL AND fs.longitude IS NOT NULL"
        );
        $has_gps_set = array_flip( $gps_family_uids );

        // 3. Filter transport families to those without GPS.
        $without_gps_ids = array_values( array_filter( $transport_family_ids, static function ( $id ) use ( $has_gps_set ) {
            return ! isset( $has_gps_set[ (string) $id ] );
        } ) );

        if ( empty( $without_gps_ids ) ) {
            return array();
        }

        // 4. Fetch family contact details from olama_core.
        $items        = array();
        $chunk_size   = 200;
        $total_chunks = array_chunk( $without_gps_ids, $chunk_size );

        foreach ( $total_chunks as $chunk ) {
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT oracle_family_id, family_uid, sponsor_full_name,
                        father_name, father_mobile, mother_name, mother_mobile
                 FROM `{$families_table}`
                 WHERE oracle_family_id IN ({$placeholders}) AND is_active = 1",
                $chunk
            ), ARRAY_A );
            foreach ( (array) $rows as $f ) {
                $items[] = array(
                    'family_id'           => absint( $f['oracle_family_id'] ),
                    'oracle_family_id'    => (string) $f['oracle_family_id'],
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
        }

        return $items;
    }

    /**
     * Return available major areas from the transportation plugin.
     *
     * @return array  [['id' => string, 'name' => string], ...]
     */
    public function get_transport_area_options() {
        global $wpdb;
        $areas_table = $wpdb->prefix . 'olama_transport_major_areas';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $areas_table ) ) !== $areas_table ) {
            return array();
        }
        $rows = $wpdb->get_results(
            "SELECT id, name FROM `{$areas_table}` WHERE status = 'active' ORDER BY name ASC",
            ARRAY_A
        );
        return array_values( array_map( static function ( $row ) {
            return array( 'id' => (string) $row['id'], 'name' => (string) $row['name'] );
        }, (array) $rows ) );
    }
}

