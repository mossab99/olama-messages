<?php
/** One bounded, phone-independent audience contract for internal delivery. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Olama_Messages_Audience_Resolver {
    const BATCH = 100;

    public function validate( array $spec ) {
        $allowed = array( 'type', 'study_year', 'class_id', 'section_id', 'family_id', 'actor_keys' );
        if ( array_diff( array_keys( $spec ), $allowed ) ) { throw new InvalidArgumentException( 'مرشح جمهور غير مدعوم.' ); }
        $type = $spec['type'] ?? 'general';
        if ( ! in_array( $type, array( 'general', 'collection', 'transportation', 'renewal_reminder', 'employees', 'selected' ), true ) ) { throw new InvalidArgumentException( 'نوع الجمهور غير مدعوم.' ); }
        foreach ( array( 'class_id', 'section_id', 'family_id' ) as $filter ) {
            if ( ! empty( $spec[$filter] ) && 'general' !== $type ) { throw new InvalidArgumentException( 'مرشحات الصف والشعبة والأسرة متاحة للجمهور العام فقط.' ); }
        }
        if ( ! function_exists( 'olama_core' ) ) { throw new RuntimeException( 'OLAMA Core غير متاح.' ); }
        $current = olama_core()->academic_context()->current();
        $year = (string) ( $spec['study_year'] ?? $current->study_year ?? '' );
        if ( '' === $year ) { throw new RuntimeException( 'السنة الدراسية الحالية غير متاحة.' ); }
        $spec['type'] = $type;
        $spec['study_year'] = $year;
        if ( 'selected' === $type ) {
            $keys = array_values( array_unique( (array) ( $spec['actor_keys'] ?? array() ) ) );
            if ( ! $keys || count( $keys ) > 1000 ) { throw new InvalidArgumentException( 'اختر من 1 إلى 1000 هوية.' ); }
            foreach ( $keys as $key ) {
                if ( ! is_string( $key ) || ! preg_match( '/^(family|employee):[^\s]{1,170}$/u', $key ) ) { throw new InvalidArgumentException( 'معرف جمهور غير صالح.' ); }
            }
            sort( $keys, SORT_STRING );
            $spec['actor_keys'] = $keys;
        } elseif ( ! empty( $spec['actor_keys'] ) ) { throw new InvalidArgumentException( 'الهويات المحددة تتطلب الجمهور المحدد.' ); }
        return $spec;
    }

    public function page( array $spec, $offset ) {
        global $wpdb;
        $spec = $this->validate( $spec );
        $resolver = new Olama_Messages_Actor_Resolver();
        $type = $spec['type'];
        $items = array();
        $health = array( 'consistency' => 'preparation_window', 'academic_context' => $spec['study_year'] );
        $wpdb->last_error = '';
        if ( 'selected' === $type ) {
            $rows = array_slice( $spec['actor_keys'], $offset, self::BATCH );
            foreach ( $rows as $key ) {
                list( $kind, $id ) = explode( ':', $key, 2 );
                $record = 'family' === $kind ? olama_core()->families()->get_by_uid( $id ) : olama_core()->employees()->get_by_employee_id( $id );
                if ( ! $record ) { throw new RuntimeException( 'تعذر العثور على هوية محددة في OLAMA Core.' ); }
                $items[] = array( 'actor_key' => $key, 'name' => $record['sponsor_full_name'] ?? $record['full_name'] ?? '', 'context' => array( 'study_year' => $spec['study_year'] ) );
            }
        } elseif ( 'employees' === $type ) {
            $rows = olama_core()->employees()->active( array( 'limit' => self::BATCH, 'offset' => $offset ) );
            foreach ( (array) $rows as $row ) {
                $items[] = array( 'actor_key' => 'employee:' . $row['employee_id'], 'name' => $row['full_name'], 'context' => array( 'study_year' => $spec['study_year'] ) );
            }
        } else {
            $provider = olama_core()->audiences();
            $health['source_health'] = $provider->get_sync_health( $type, $spec['study_year'] );
            if ( empty( $health['source_health']['ready'] ) ) { throw new RuntimeException( 'بيانات الجمهور المطلوبة غير جاهزة للمزامنة.' ); }
            $filters = $spec;
            unset( $filters['type'] );
            $filters['target_type'] = $type;
            $filters['offset'] = $offset;
            $filters['limit'] = self::BATCH;
            $result = $provider->query( $filters );
            if ( ! is_array( $result ) || ! isset( $result['items'] ) ) { throw new RuntimeException( 'استجابة الجمهور غير صالحة.' ); }
            $rows = $result['items'];
            foreach ( $rows as $row ) {
                $uid = (string) ( $row['core_family_uid'] ?? $row['family_uid'] ?? '' );
                if ( '' === $uid ) { throw new RuntimeException( 'جمهور دون معرف أسرة ثابت.' ); }
                $items[] = array( 'actor_key' => 'family:' . $uid, 'name' => $row['sponsor_name'] ?? '',
                    'context' => array( 'study_year' => $spec['study_year'], 'students' => $row['student_rows'] ?? $row['students'] ?? array(), 'source_hash' => $row['core_source_hash'] ?? '' ) );
            }
        }
        if ( $wpdb->last_error ) { throw new RuntimeException( 'تعذر قراءة مصدر الجمهور.' ); }
        $resolver->prime( array_column( $items, 'actor_key' ) );
        foreach ( $items as &$item ) {
            $item['reachability'] = $resolver->reachability( $item['actor_key'] );
        }
        unset( $item );
        return array( 'items' => $items, 'next' => $offset + count( (array) $rows ), 'done' => count( (array) $rows ) < self::BATCH, 'sources' => $health );
    }
}
