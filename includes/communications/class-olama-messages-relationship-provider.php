<?php
/** Read-only Core/Users/School adapter; never calls School's synchronizing teacher APIs. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Olama_Messages_Relationship_Provider {
    private function result( $status, array $sources = array(), array $context = array() ) {
        return array( 'relationship_status' => $status, 'sources' => $sources, 'context' => $context );
    }

    public function source( $domain, $timestamp = null, $live = false ) {
        $status = $live ? 'valid' : 'unknown';
        $settings = Olama_Messages_Communication_Policy::settings();
        $age = max( 60, (int) apply_filters( 'olama_messages_' . $domain . '_max_age', $settings[$domain . '_max_age'] ?? DAY_IN_SECONDS ) );
        if ( ! $live && $timestamp ) {
            // Core sync writes WordPress local time; Communications stores UTC separately.
            $time = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $timestamp, wp_timezone() );
            if ( $time && $time->format( 'Y-m-d H:i:s' ) === $timestamp && $time->getTimestamp() <= time() + 300 ) { $status = time() - $time->getTimestamp() > $age ? 'stale' : 'valid'; }
        }
        return array( 'status' => $status, 'source' => 'teacher_assignment' === $domain ? 'school' : 'core', 'checked_at_utc' => gmdate( 'Y-m-d H:i:s' ), 'source_updated_at' => $timestamp, 'semantics' => $live ? 'live_relational' : 'synchronized', 'max_age_seconds' => $live ? null : $age );
    }

    /** Canonical employee mapping, active account, and current role. No role-only identities. */
    public function employee( $key ) {
        if ( 0 !== strpos( (string) $key, 'employee:' ) || ! function_exists( 'olama_core' ) || ! class_exists( 'Olama_Users_DB' ) ) { return null; }
        $id = substr( $key, 9 );
        $profile = olama_core()->employees()->get_by_employee_id( $id );
        $identity = Olama_Users_DB::get_identity( 'employee', $id );
        if ( ! $profile || ! $identity || 'active' !== $identity['account_status'] || 'مستمر' !== ( $profile['employee_status'] ?? '' ) ) { return null; }
        $user = get_userdata( (int) $identity['wp_user_id'] );
        $verified = $user ? olama_users_get_identity( $user->ID ) : null;
        if ( ! $verified || 'employee' !== $verified['identity_type'] || 'active' !== $verified['account_status'] || (int) $verified['wp_user_id'] !== $user->ID || $id !== (string) $verified['oracle_identifier'] || $id !== (string) $profile['employee_id'] || 'suspended' === get_user_meta( $user->ID, 'olama_account_status', true ) ) { return null; }
        return array( 'actor_key' => $key, 'name' => $profile['full_name'], 'wp_user_id' => $user->ID, 'teacher' => in_array( 'olama_teacher', $user->roles, true ), 'source' => $this->source( 'employee_mapping', $profile['last_synced_at'] ?? null ) );
    }

    /** Resolve a display-safe actor record for direct-message participants. */
    public function actor( $key ) {
        if ( 0 === strpos( (string) $key, 'administrator:' ) ) {
            $user_id = absint( substr( (string) $key, 14 ) );
            $user = $user_id ? get_userdata( $user_id ) : false;
            return $user && Olama_Messages_Communication_Policy::administrator( $user_id )
                ? array( 'actor_key' => $key, 'name' => $user->display_name, 'wp_user_id' => $user_id ) : null;
        }
        if ( 0 === strpos( (string) $key, 'employee:' ) ) { return $this->employee( $key ); }
        if ( 0 === strpos( (string) $key, 'family:' ) && function_exists( 'olama_core' ) ) {
            $family = olama_core()->families()->get_by_uid( substr( (string) $key, 7 ) );
            return $family ? array( 'actor_key' => $key, 'name' => $family['sponsor_full_name'] ?? $family['father_name'] ?? 'الأسرة' ) : null;
        }
        return null;
    }

    public function academic() {
        if ( ! function_exists( 'olama_core' ) ) { throw new RuntimeException( 'OLAMA Core غير متاح.' ); }
        $context = (array) olama_core()->academic_context()->current();
        if ( empty( $context['study_year'] ) || empty( $context['academic_year_id'] ) || empty( $context['semester_id'] ) ) { throw new RuntimeException( 'السياق الدراسي الحالي غير متاح.' ); }
        return $context;
    }

    public function children( $family_key ) {
        if ( 0 !== strpos( $family_key, 'family:' ) ) { return array(); }
        $academic = $this->academic();
        $rows = (array) olama_core()->student_years()->get_by_family( substr( $family_key, 7 ), $academic['study_year'] );
        $result = array();
        foreach ( $rows as $row ) {
            if ( empty( $row['student_uid'] ) || (string) ( $row['family_uid'] ?? '' ) !== substr( $family_key, 7 ) ) { continue; }
            if ( ! in_array( strtolower( (string) ( $row['student_status'] ?? '' ) ), array( '1', 'active', 'enabled', 'current' ), true ) && ! in_array( $row['student_status_name'] ?? '', array( 'فعال', 'نشط', 'مستمر', 'active' ), true ) ) { continue; }
            $student = olama_core()->students()->get_by_uid( $row['student_uid'] );
            if ( ! $student || (string) ( $student['family_uid'] ?? '' ) !== substr( $family_key, 7 ) ) { continue; }
            $row['student_name'] = $student['student_name'] ?? $row['student_uid'];
            $row['student_source'] = $this->source( 'student_context', $student['last_synced_at'] ?? null );
            $result[] = $row;
        }
        return $result;
    }

    private function assignments( array $student, array $academic, $assignment_id = 0 ) {
        global $wpdb;
        $year = str_replace( '/', '-', $academic['study_year'] );
        $subjects = olama_core()->read_models()->table( 'academic_grade_subjects' );
        $sections = olama_core()->read_models()->table( 'academic_grade_sections' );
        $where = $assignment_id ? $wpdb->prepare( ' AND a.id=%d', $assignment_id ) : '';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.*,s.section_name,u.subject_name,cs.id AS core_subject_mapping_id,cs.is_active AS core_subject_active,cs.last_synced_at AS subject_synced_at,cg.id AS core_section_mapping_id,cg.last_synced_at AS section_synced_at FROM {$wpdb->prefix}olama_teacher_assignments a
             INNER JOIN {$wpdb->prefix}olama_sections s ON s.id=a.section_id AND s.grade_id=a.grade_id AND s.academic_year_id=a.academic_year_id
             INNER JOIN {$wpdb->prefix}olama_subjects u ON u.id=a.subject_id AND u.grade_id=a.grade_id AND u.is_active=1
             LEFT JOIN {$subjects} cs ON REPLACE(cs.study_year,'/','-')=REPLACE(u.core_study_year,'/','-') AND REPLACE(cs.study_year,'/','-')=REPLACE(s.core_study_year,'/','-') AND BINARY cs.grade_id=BINARY u.core_grade_id AND BINARY cs.grade_id=BINARY s.core_grade_id AND BINARY cs.subject_id=BINARY u.core_subject_id
             LEFT JOIN {$sections} cg ON REPLACE(cg.study_year,'/','-')=REPLACE(s.core_study_year,'/','-') AND BINARY cg.grade_id=BINARY s.core_grade_id AND BINARY cg.section_id=BINARY s.core_section_id
             WHERE a.academic_year_id=%d AND REPLACE(s.core_study_year,'/','-')=%s AND BINARY s.core_grade_id=BINARY %s AND BINARY s.core_section_id=BINARY %s {$where} ORDER BY a.id LIMIT 100",
            $academic['academic_year_id'], $year, (string) ( $student['class_id'] ?? '' ), (string) ( $student['section_id'] ?? '' ) ), ARRAY_A );
        if ( $wpdb->last_error ) { throw new RuntimeException( 'تعذر قراءة تعيينات المعلمين.' ); }
        return $rows;
    }

    public function relationship( $left, $right, array $requested = array() ) {
        try {
            if ( $left === $right ) { return $this->result( 'invalid' ); }
            if ( 0 === strpos( $left, 'administrator:' ) || 0 === strpos( $right, 'administrator:' ) ) {
                $administrator = 0 === strpos( $left, 'administrator:' ) ? $left : $right;
                $other = $administrator === $left ? $right : $left;
                $admin_record = $this->actor( $administrator );
                $other_record = $this->actor( $other );
                if ( ! $admin_record || ! $other_record || ! ( new Olama_Messages_Actor_Resolver() )->eligible( $other ) ) { return $this->result( 'mapping_missing' ); }
                return $this->result( 'valid', array(), array( 'scope' => 'administrator' ) );
            }
            $family = 0 === strpos( $left, 'family:' ) ? $left : ( 0 === strpos( $right, 'family:' ) ? $right : '' );
            if ( $family ) {
                $teacher_key = $family === $left ? $right : $left;
                $teacher = $this->employee( $teacher_key );
                if ( ! $teacher || ! $teacher['teacher'] ) { return $this->result( 'mapping_missing' ); }
                $academic = $this->academic();
                if ( isset( $requested['study_year'] ) && $requested['study_year'] !== $academic['study_year'] ) { return $this->result( 'invalid' ); }
                foreach ( $this->children( $family ) as $student ) {
                    if ( (string) $student['student_uid'] !== (string) ( $requested['student_uid'] ?? '' ) ) { continue; }
                    $sources = array( 'student' => $student['student_source'], 'student_context' => $this->source( 'student_context', $student['last_synced_at'] ?? null ), 'academic_context' => $this->source( 'academic_context', null, true ), 'employee_mapping' => $teacher['source'], 'teacher_assignment' => $this->source( 'teacher_assignment', null, true ) );
                    foreach ( $sources as $source ) { if ( 'valid' !== $source['status'] ) { return $this->result( $source['status'], $sources ); } }
                    if ( empty( $requested['assignment_id'] ) ) { return $this->result( 'mapping_missing', $sources ); }
                    foreach ( $this->assignments( $student, $academic, absint( $requested['assignment_id'] ) ) as $assignment ) {
                        if ( 'employee:' . $assignment['teacher_employee_id'] !== $teacher_key || (int) $assignment['teacher_id'] !== $teacher['wp_user_id'] ) { continue; }
                        if ( ! $assignment['core_subject_mapping_id'] || ! $assignment['core_section_mapping_id'] ) { return $this->result( 'mapping_missing', $sources ); }
                        if ( ! $assignment['core_subject_active'] ) { return $this->result( 'invalid', $sources ); }
                        $sources['subject_mapping'] = $this->source( 'academic_mapping', $assignment['subject_synced_at'] );
                        $sources['section_mapping'] = $this->source( 'academic_mapping', $assignment['section_synced_at'] );
                        foreach ( $sources as $source ) { if ( 'valid' !== $source['status'] ) { return $this->result( $source['status'], $sources ); } }
                        return $this->result( 'valid', $sources, array(
                            'family_key' => $family, 'teacher_key' => $teacher_key, 'student_uid' => (string) $student['student_uid'],
                            'student_name' => $student['student_name'] ?? $student['student_uid'], 'study_year' => $academic['study_year'],
                            'academic_year_id' => (int) $academic['academic_year_id'], 'semester_id' => (int) $academic['semester_id'],
                            'assignment_id' => (int) $assignment['id'], 'section_id' => (int) $assignment['section_id'], 'section_name' => $assignment['section_name'],
                            'class_id' => (string) $student['class_id'], 'class_name' => $student['class_name'] ?? '',
                            'subject_id' => (int) $assignment['subject_id'], 'subject_name' => $assignment['subject_name']
                        ) );
                    }
                    return $this->result( 'invalid', $sources );
                }
                return $this->result( 'invalid' );
            }
            $a = $this->employee( $left ); $b = $this->employee( $right );
            if ( ! $a || ! $b ) { return $this->result( 'mapping_missing' ); }
            $sources = array( 'employee_mapping_left' => $a['source'], 'employee_mapping_right' => $b['source'] );
            foreach ( $sources as $source ) { if ( 'valid' !== $source['status'] ) { return $this->result( $source['status'], $sources ); } }
            $allowed = ( $a['teacher'] && $b['teacher'] ) || ( $a['teacher'] && user_can( $b['wp_user_id'], 'olama_messages_contact_teachers' ) ) || ( $b['teacher'] && user_can( $a['wp_user_id'], 'olama_messages_contact_teachers' ) );
            return $this->result( $allowed ? 'valid' : 'invalid', $sources, array( 'scope' => 'staff' ) );
        } catch ( Throwable $error ) { return $this->result( 'unknown', array( 'provider' => array( 'status' => 'unknown', 'source' => 'core_school', 'checked_at_utc' => gmdate( 'Y-m-d H:i:s' ) ) ) ); }
    }

    public function contacts( array $actor, $student_uid = '', $after = 0 ) {
        $items = array(); $children = array(); $cursor = 0;
        if ( 'administrator' === $actor['actor_type'] ) {
            global $wpdb;
            $resolver = new Olama_Messages_Actor_Resolver();
            $ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID>%d ORDER BY ID LIMIT 100", $after ) );
            foreach ( $ids as $id ) {
                $cursor = (int) $id;
                foreach ( $resolver->available( (int) $id ) as $candidate ) {
                    if ( $candidate['actor_key'] === $actor['actor_key'] || 'eligible_account' !== $resolver->reachability( $candidate['actor_key'] ) ) { continue; }
                    $items[] = array( 'actor_key' => $candidate['actor_key'], 'name' => $candidate['display_name'], 'context' => array( 'scope' => 'administrator' ) );
                    if ( 30 === count( $items ) ) { break 2; }
                }
            }
            if ( count( $ids ) < 100 && count( $items ) < 30 ) { $cursor = 0; }
        } elseif ( 'family' === $actor['actor_type'] ) {
            $academic = $this->academic();
            foreach ( $this->children( $actor['actor_key'] ) as $student ) {
                $children[] = array( 'student_uid' => $student['student_uid'], 'name' => $student['student_name'] ?? $student['student_uid'], 'class_name' => $student['class_name'] ?? '', 'section_name' => $student['section_name'] ?? '' );
                if ( (string) $student['student_uid'] !== (string) $student_uid ) { continue; }
                foreach ( $this->assignments( $student, $academic ) as $assignment ) {
                    $key = 'employee:' . $assignment['teacher_employee_id'];
                    $context = array( 'student_uid' => $student_uid, 'assignment_id' => (int) $assignment['id'] );
                    $relation = $this->relationship( $actor['actor_key'], $key, $context );
                    $employee = $this->employee( $key );
                    if ( 'valid' === $relation['relationship_status'] && $employee ) { $items[] = array( 'actor_key' => $key, 'name' => $employee['name'], 'context' => $relation['context'] ); }
                }
            }
        } elseif ( $this->employee( $actor['actor_key'] ) ) {
            global $wpdb;
            // Keyset scan, bounded; filtered contacts never expose a family directory.
            $ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID>%d ORDER BY ID LIMIT 100", $after ) );
            foreach ( $ids as $id ) {
                $cursor = (int) $id;
                $identity = olama_users_get_identity( $id );
                if ( ! $identity || 'employee' !== $identity['identity_type'] ) { continue; }
                $key = 'employee:' . $identity['oracle_identifier'];
                if ( 'valid' !== $this->relationship( $actor['actor_key'], $key )['relationship_status'] ) { continue; }
                $employee = $this->employee( $key );
                $items[] = array( 'actor_key' => $key, 'name' => $employee['name'], 'context' => array( 'scope' => 'staff' ) );
                if ( count( $items ) === 30 ) { break; }
            }
            if ( count( $ids ) < 100 && count( $items ) < 30 ) { $cursor = 0; }
        }
        return array( 'children' => $children, 'contacts' => $items, 'next' => $cursor );
    }

    public function teacher_contacts( array $actor, $after_student = '', $after_assignment = 0 ) {
        global $wpdb;
        $teacher = 'employee' === $actor['actor_type'] ? $this->employee( $actor['actor_key'] ) : null;
        if ( ! $teacher || ! $teacher['teacher'] || 'valid' !== $teacher['source']['status'] ) { throw new RuntimeException( 'دليل الأسر متاح للمعلم المعين فقط.' ); }
        $academic = $this->academic(); $sy = olama_core()->read_models()->table( 'student_years' );
        // Only the teacher's current mapped sections enter this bounded keyset scan.
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT sy.student_uid,sy.family_uid,a.id AS assignment_id
            FROM {$sy} sy INNER JOIN {$wpdb->prefix}olama_sections s
              ON REPLACE(s.core_study_year,'/','-')=REPLACE(sy.study_year,'/','-') AND BINARY s.core_grade_id=BINARY sy.class_id AND BINARY s.core_section_id=BINARY sy.section_id
            INNER JOIN {$wpdb->prefix}olama_teacher_assignments a ON a.section_id=s.id AND a.grade_id=s.grade_id AND a.academic_year_id=s.academic_year_id
            WHERE sy.study_year=%s AND a.academic_year_id=%d AND a.teacher_id=%d AND BINARY a.teacher_employee_id=BINARY %s
              AND (BINARY sy.student_uid>BINARY %s OR (BINARY sy.student_uid=BINARY %s AND a.id>%d))
            ORDER BY BINARY sy.student_uid,a.id LIMIT 30", $academic['study_year'], $academic['academic_year_id'], $teacher['wp_user_id'], $actor['actor_id'], $after_student, $after_student, $after_assignment ), ARRAY_A );
        if ( $wpdb->last_error ) { throw new RuntimeException( 'تعذر قراءة أسر الطلاب المعينين.' ); }
        $items = array();
        foreach ( $rows as $row ) {
            $key = 'family:' . $row['family_uid'];
            $relation = $this->relationship( $actor['actor_key'], $key, array( 'student_uid' => $row['student_uid'], 'assignment_id' => (int) $row['assignment_id'] ) );
            if ( 'valid' !== $relation['relationship_status'] ) { continue; }
            $family = olama_core()->families()->get_by_uid( $row['family_uid'] );
            if ( ! $family || empty( $family['is_active'] ) ) { continue; }
            $items[] = array( 'actor_key' => $key, 'name' => $relation['context']['student_name'] . ' · ' . ( $family['sponsor_full_name'] ?? 'الأسرة' ), 'context' => $relation['context'] );
        }
        $last = $rows ? end( $rows ) : null;
        return array( 'contacts' => $items, 'next' => count( $rows ) === 30 ? array( 'student_uid' => $last['student_uid'], 'assignment_id' => (int) $last['assignment_id'] ) : null );
    }
}
