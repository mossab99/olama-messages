<?php
/**
 * Read-only financial adapter for the canonical Olama Core knowledge store.
 *
 * @package Olama_Messages
 */

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Messages_Financial_Api_Provider {
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
        return function_exists('olama_core') && is_object(olama_core()->financial());
    }

    public function get_bulk_recipients($study_year, $filters = array()) {
        if (!$this->is_available()) {
            return false;
        }
        return olama_core()->financial()->query_recipients($study_year, is_array($filters) ? $filters : array());
    }

    public function get_financial_summary($family_id, $study_year) {
        if (!$this->is_available()) {
            return false;
        }
        $summary = olama_core()->financial()->get_summary($family_id, $study_year);
        return $summary ?: false;
    }

    public function get_payment_report($family_id, $study_year) {
        if (!$this->is_available()) {
            return false;
        }
        return olama_core()->financial()->get_payment_report($family_id, $study_year);
    }
}
