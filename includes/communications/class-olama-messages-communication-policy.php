<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Olama_Messages_Communication_Policy {
    public static function settings() {
        return array_merge( array( 'enabled' => false, 'notifications' => false, 'pilot_users' => array(), 'app_url' => '', 'poll_seconds' => 20,
            'chat_enabled' => false, 'message_max_chars' => 5000, 'edit_minutes' => 15,
            'attachments_enabled' => false, 'actions_enabled' => false, 'events_enabled' => false,
            'private_storage_path' => '', 'private_storage_reviewed_path' => '', 'malware_policy' => 'if_available',
            'legacy_office_enabled' => false, 'max_attachment_count' => 5, 'max_total_attachment_bytes' => 25 * MB_IN_BYTES,
            'max_image_bytes' => 8 * MB_IN_BYTES, 'max_document_bytes' => 15 * MB_IN_BYTES, 'max_presentation_bytes' => 20 * MB_IN_BYTES,
            'office_start' => 480, 'office_end' => 900, 'office_days' => array( 0, 1, 2, 3, 4 ),
            'retention_archive_enabled' => false, 'retention_archive_days' => 730,
            'student_context_max_age' => DAY_IN_SECONDS, 'employee_mapping_max_age' => DAY_IN_SECONDS, 'academic_mapping_max_age' => DAY_IN_SECONDS
        ), (array) get_option( 'olama_msg_communications', array() ) );
    }

    public static function enabled() {
        $settings = self::settings();
        return ! empty( $settings['enabled'] ) && ( ! $settings['pilot_users'] || in_array( get_current_user_id(), array_map( 'intval', $settings['pilot_users'] ), true ) );
    }

    public static function require_use( array $actor ) {
        if ( ! self::enabled() || ! current_user_can( 'olama_messages_use' ) || 'suspended' === get_user_meta( get_current_user_id(), 'olama_account_status', true ) || (int) $actor['wp_user_id'] !== get_current_user_id() ) {
            throw new RuntimeException( 'خدمة الاتصالات غير متاحة لهذا الحساب.' );
        }
    }

    public static function require_manage( array $actor ) {
        self::require_use( $actor );
        if ( 'employee' !== $actor['actor_type'] || ! current_user_can( 'olama_messages_manage_campaigns' ) ) {
            throw new RuntimeException( 'إدارة الإعلانات تتطلب هوية موظف وصلاحية مخولة.' );
        }
    }
}
