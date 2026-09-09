<?php
/** Read-only identity adapter. Never infers identity from a role, name or phone. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Olama_Messages_Actor_Resolver {
    private $families = array();
    private $employees = array();
    private $enrollments = array();

    /** Batch Core profiles/enrollment for one bounded worker transaction, never a persistent auth cache. */
    public function prime( array $keys ) {
        global $wpdb;
        if ( ! function_exists( 'olama_core' ) || ! method_exists( olama_core(), 'read_models' ) ) { return; }
        $family_ids = array(); $employee_ids = array();
        foreach ( $keys as $key ) {
            list( $type, $id ) = explode( ':', $key, 2 );
            if ( 'family' === $type ) { $family_ids[] = $id; } else { $employee_ids[] = $id; }
        }
        if ( $employee_ids ) {
            foreach ( $employee_ids as $id ) { $this->employees[$id] = null; }
            foreach ( (array) olama_core()->employees()->get_by_employee_ids( $employee_ids ) as $row ) { $this->employees[$row['employee_id']] = $row; }
        }
        if ( $family_ids ) {
            foreach ( $family_ids as $id ) { $this->families[$id] = null; $this->enrollments[$id] = array(); }
            foreach ( (array) olama_core()->families()->get_by_uids( $family_ids ) as $row ) { $this->families[$row['family_uid']] = $row; }
            $context = olama_core()->academic_context()->current();
            $year = (string) ( $context->study_year ?? '' );
            if ( $year ) {
                $table = olama_core()->read_models()->table( 'student_years' );
                $placeholders = implode( ',', array_fill( 0, count( $family_ids ), '%s' ) );
                $rows = $wpdb->get_results( $wpdb->prepare( "SELECT family_uid,student_status,student_status_name FROM {$table} WHERE study_year=%s AND family_uid IN ({$placeholders})", array_merge( array( $year ), $family_ids ) ), ARRAY_A );
                if ( $wpdb->last_error ) { throw new RuntimeException( 'تعذر التحقق من تسجيل الطلاب.' ); }
                foreach ( $rows as $row ) { $this->enrollments[$row['family_uid']][] = $row; }
            }
        }
    }

    private function family( $id ) {
        return array_key_exists( $id, $this->families ) ? $this->families[$id] : olama_core()->families()->get_by_uid( $id );
    }

    private function employee( $id ) {
        return array_key_exists( $id, $this->employees ) ? $this->employees[$id] : olama_core()->employees()->get_by_employee_id( $id );
    }
    public function available( $user_id, $history = false ) {
        if ( 'suspended' === get_user_meta( $user_id, 'olama_account_status', true ) ) { return array(); }
        if ( ! function_exists( 'olama_users_get_identity' ) || ! function_exists( 'olama_core' ) ) { return array(); }
        $identity = olama_users_get_identity( $user_id );
        if ( ! $identity || 'active' !== $identity['account_status'] || (int) $identity['wp_user_id'] !== (int) $user_id ) { return array(); }
        $type = $identity['identity_type'];
        $external = (string) $identity['oracle_identifier'];
        if ( 'family' === $type ) {
            $record = null;
            foreach ( $this->families as $cached ) { if ( $cached && (string) $cached['oracle_family_id'] === $external ) { $record = $cached; break; } }
            if ( ! $record ) { $record = olama_core()->families()->get_by_oracle_id( $external ); }
            $id = $record['family_uid'] ?? '';
            $name = $record['sponsor_full_name'] ?? $record['father_name'] ?? '';
        } elseif ( 'employee' === $type ) {
            $record = $this->employee( $external );
            $id = $record['employee_id'] ?? '';
            $name = $record['full_name'] ?? '';
        } else { return array(); }
        if ( '' === (string) $id || strlen( $type . ':' . $id ) > 191 ) { return array(); }
        $actor = array( 'actor_type' => $type, 'actor_id' => (string) $id, 'actor_key' => $type . ':' . $id,
            'display_name' => (string) $name, 'wp_user_id' => (int) $user_id, 'external_id' => $external );
        // An active, verified family retains its own history after an enrollment changes.
        // New sends still require current eligibility and an academic relationship.
        if ( $history && 'family' === $type ) { return $record && ( ! isset( $record['is_active'] ) || $record['is_active'] ) ? array( $actor ) : array(); }
        return $this->eligible( $actor['actor_key'] ) ? array( $actor ) : array();
    }

    public function resolve( $requested = '', $history = false ) {
        $actors = $this->available( get_current_user_id(), $history );
        foreach ( $actors as $actor ) {
            if ( ( '' === $requested && 1 === count( $actors ) ) || hash_equals( $actor['actor_key'], (string) $requested ) ) { return $actor; }
        }
        throw new RuntimeException( 'لا تتوفر هوية OLAMA مخولة لهذا الطلب.' );
    }

    public function eligible( $key ) {
        if ( ! function_exists( 'olama_core' ) ) { return false; }
        $parts = explode( ':', (string) $key, 2 );
        if ( 2 !== count( $parts ) ) { return false; }
        if ( 'employee' === $parts[0] ) {
            $employee = $this->employee( $parts[1] );
            return $employee && 'مستمر' === ( $employee['employee_status'] ?? '' );
        }
        if ( 'family' !== $parts[0] ) { return false; }
        $family = $this->family( $parts[1] );
        if ( ! $family || ( isset( $family['is_active'] ) && ! $family['is_active'] ) ) { return false; }
        $context = olama_core()->academic_context()->current();
        $year = $context->study_year ?? '';
        if ( ! $year ) { return false; }
        $students = array_key_exists( $parts[1], $this->enrollments ) ? $this->enrollments[$parts[1]] : olama_core()->student_years()->get_by_family( $parts[1], $year );
        foreach ( (array) $students as $student ) {
            // Explicit active enrollment is required; blank/unknown does not confer private access.
            $status = strtolower( trim( (string) ( $student['student_status'] ?? '' ) ) );
            $label = strtolower( trim( (string) ( $student['student_status_name'] ?? '' ) ) );
            if ( in_array( $status, array( '1', 'active', 'enabled', 'current' ), true ) || in_array( $label, array( 'active', 'enabled', 'current', 'فعال', 'نشط', 'مستمر' ), true ) ) { return true; }
        }
        return false;
    }

    public function reachability( $key ) {
        if ( ! $this->eligible( $key ) ) { return 'ineligible'; }
        if ( ! class_exists( 'Olama_Users_DB' ) ) { return 'identity_unavailable'; }
        list( $type, $id ) = explode( ':', $key, 2 );
        if ( 'family' === $type ) {
            $family = $this->family( $id );
            $id = $family['oracle_family_id'] ?? '';
        }
        $identity = Olama_Users_DB::get_identity( $type, $id );
        if ( ! $identity || ! get_userdata( (int) $identity['wp_user_id'] ) ) { return 'no_account'; }
        if ( 'active' !== $identity['account_status'] || 'suspended' === get_user_meta( $identity['wp_user_id'], 'olama_account_status', true ) ) { return 'inactive'; }
        $pilot = Olama_Messages_Communication_Policy::settings()['pilot_users'];
        if ( $pilot && ! in_array( (int) $identity['wp_user_id'], array_map( 'intval', $pilot ), true ) ) { return 'outside_pilot'; }
        if ( ! user_can( $identity['wp_user_id'], 'olama_messages_use' ) ) { return 'no_access'; }
        $actors = $this->available( $identity['wp_user_id'] );
        return in_array( $key, array_column( $actors, 'actor_key' ), true ) ? 'eligible_account' : 'identity_unavailable';
    }
}
