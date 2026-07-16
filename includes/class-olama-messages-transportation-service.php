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
}
