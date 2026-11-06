<?php
/** Read-only assessment of the latest Oracle installment allocation. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Olama_Messages_Collection_Report {
    const VERSION = '1';
    const OPTION = 'olama_msg_collection_policy';

    public static function can_view() {
        return current_user_can( 'olama_access_messages' ) && ( current_user_can( 'manage_options' ) || current_user_can( 'olama_manage_financial_accounts' ) );
    }

    public static function defaults() {
        return array( 'installments' => 2, 'days' => 30, 'tolerance' => 1, 'stale_days' => 2 );
    }

    public static function policy() {
        return array_merge( self::defaults(), (array) get_option( self::OPTION, array() ) );
    }

    public static function money( $value ) { return (int) round( (float) $value * 1000 ); }

    public static function valid_date( $value ) {
        $date = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $value );
        return $date && $date->format( 'Y-m-d' ) === $value;
    }

    /** Pure calculation, in integer fils. Credits are already allocated by Oracle. */
    public static function assess( array $dues, $today, array $policy, $override = 0 ) {
        if ( ! self::valid_date( $today ) ) { throw new InvalidArgumentException( 'Invalid report date.' ); }
        $result = array( 'due' => 0, 'covered' => 0, 'overdue' => 0, 'annual_balance' => 0, 'outstanding' => 0, 'unpaid' => 0, 'partial' => 0, 'oldest' => 0, 'weighted_age' => 0, 'typical' => 0, 'equivalent' => null, 'coverage' => null, 'financial_match' => false, 'warnings' => array(), 'schedule' => array() );
        $amounts = array(); $weighted = 0; $tolerance = self::money( $policy['tolerance'] );
        if ( ! $dues ) { $result['warnings'][] = 'جدول الأقساط غير متوفر'; }
        foreach ( $dues as $due ) {
            $date = (string) ( $due['due_date'] ?? '' );
            foreach ( array( 'due_amount', 'paid_amount', 'receipt_paid', 'balance' ) as $key ) {
                if ( ! isset( $due[$key] ) || ! is_numeric( $due[$key] ) ) { $result['warnings'][] = 'قيم مالية ناقصة'; }
            }
            $gross = self::money( $due['due_amount'] ?? 0 );
            $discount = self::money( $due['paid_amount'] ?? 0 );
            $payments = self::money( $due['receipt_paid'] ?? 0 );
            $balance = self::money( $due['balance'] ?? 0 );
            $net = $gross - $discount;
            $result['annual_balance'] += $balance;
            if ( $gross < 0 || $discount < 0 || $payments < 0 || $net < 0 || $balance < 0 || abs( $net - $payments - $balance ) > 2 ) {
                $result['warnings'][] = 'تعارض في أرصدة الأقساط أو تسويات تحتاج مراجعة';
            }
            if ( ! self::valid_date( $date ) ) { $result['warnings'][] = 'تاريخ استحقاق غير صالح'; continue; }
            if ( $net > 0 ) { $amounts[] = $net; }
            $is_due = $date <= $today;
            $remaining = max( 0, min( max( 0, $net ), $balance ) );
            $covered = max( 0, $net ) - $remaining;
            $age = $is_due ? (int) ( new DateTimeImmutable( $date ) )->diff( new DateTimeImmutable( $today ) )->days : 0;
            $result['schedule'][] = array( 'date' => $date, 'gross' => $gross, 'discount' => $discount, 'net' => $net, 'covered' => $covered, 'balance' => $balance, 'age' => $age, 'is_due' => $is_due );
            if ( ! $is_due ) { continue; }
            $result['due'] += max( 0, $net );
            $result['covered'] += $covered;
            $result['overdue'] += $remaining;
            $weighted += $remaining * $age;
            if ( $remaining > 0 ) {
                $result['outstanding']++;
                if ( $covered === 0 ) { $result['unpaid']++; } else { $result['partial']++; }
                // Tiny residuals remain visible but cannot age the actionable debt.
                if ( $remaining > $tolerance ) { $result['oldest'] = max( $result['oldest'], $age ); }
            }
        }
        sort( $amounts, SORT_NUMERIC );
        $count = count( $amounts );
        $result['typical'] = $override > 0 ? self::money( $override ) : ( $count ? (int) round( ( $amounts[(int) floor( ( $count - 1 ) / 2 )] + $amounts[(int) floor( $count / 2 )] ) / 2 ) : 0 );
        if ( $result['typical'] > 0 ) { $result['equivalent'] = $result['overdue'] / $result['typical']; }
        else { $result['warnings'][] = 'تعذر تحديد القسط المعتاد'; }
        if ( $result['due'] > 0 ) { $result['coverage'] = min( 100, $result['covered'] / $result['due'] * 100 ); }
        $result['weighted_age'] = $result['overdue'] > 0 ? $weighted / $result['overdue'] : 0;
        $result['financial_match'] = $result['overdue'] > $tolerance && $result['typical'] > 0
            && $result['overdue'] * 1000 >= $result['typical'] * (int) round( (float) $policy['installments'] * 1000 )
            && $result['oldest'] >= (int) $policy['days'];
        $result['warnings'] = array_values( array_unique( $result['warnings'] ) );
        return $result;
    }

    public static function review_key( $year, $family ) { return 'olama_msg_collection_review_' . md5( $year . ':' . $family ); }

    public static function recommendation( array $assessment, array $review, $today, $active_count, $unknown_transport = false ) {
        if ( $assessment['warnings'] ) { return 'incomplete'; }
        if ( ! $assessment['financial_match'] ) { return $assessment['overdue'] > 0 ? 'reminder' : 'current'; }
        if ( ! $active_count ) { return $unknown_transport ? 'pending' : 'collection'; }
        if ( empty( $review['until'] ) || $review['until'] < $today ) { return 'pending'; }
        return in_array( $review['status'] ?? '', array( 'exception', 'arrangement' ), true ) ? 'exempt' : ( ( $review['status'] ?? '' ) === 'clear' ? 'candidate' : 'pending' );
    }

    public static function labels() {
        return array( 'candidate' => 'مرشح لمراجعة إيقاف المواصلات', 'pending' => 'الشروط المالية متحققة — يلزم تحقق إداري', 'incomplete' => 'بيانات تحتاج مراجعة', 'exempt' => 'مستثنى / اتفاق سداد ملتزم به', 'collection' => 'متابعة تحصيل — لا اشتراك نقل فعّال مؤكد', 'reminder' => 'تذكير بالسداد', 'current' => 'المستحق مغطى' );
    }

    /** Four bulk reads keep siblings and transport rows from multiplying debt. */
    public static function load( $year, $today, array $policy ) {
        global $wpdb;
        if ( ! function_exists( 'olama_core' ) ) { throw new RuntimeException( 'إضافة Olama Core غير متاحة.' ); }
        $prefix = $wpdb->prefix . 'olama_core_';
        $families = $wpdb->get_results( $wpdb->prepare( "SELECT fy.*, f.sponsor_full_name, f.father_name FROM `{$prefix}family_financial_years` fy INNER JOIN `{$prefix}families` f ON f.family_uid = fy.family_uid WHERE fy.study_year = %s AND f.is_active = 1 AND EXISTS (SELECT 1 FROM `{$prefix}student_years` sy WHERE sy.family_uid = fy.family_uid AND sy.study_year = fy.study_year AND sy.withdraw_date IS NULL)", $year ), ARRAY_A );
        if ( $wpdb->last_error ) { throw new RuntimeException( 'تعذر قراءة البيانات المالية.' ); }
        $dues = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$prefix}family_financial_dues` WHERE study_year = %s ORDER BY due_date, id", $year ), ARRAY_A );
        if ( $wpdb->last_error ) { throw new RuntimeException( 'تعذر قراءة الأقساط.' ); }
        $transport = $wpdb->get_results( $wpdb->prepare( "SELECT tr.*, st.student_name FROM `{$prefix}student_transportation` tr LEFT JOIN `{$prefix}students` st ON st.student_uid = tr.student_uid WHERE tr.study_year = %s", $year ), ARRAY_A );
        if ( $wpdb->last_error ) { throw new RuntimeException( 'تعذر قراءة اشتراكات النقل.' ); }
        $future = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT family_uid FROM `{$prefix}financial_transactions` WHERE study_year = %s AND (transaction_date > %s OR transaction_date IS NULL)", $year, $today ) );
        if ( $wpdb->last_error ) { throw new RuntimeException( 'تعذر التحقق من تواريخ الحركات.' ); }
        $by_family = array(); $by_transport = array();
        foreach ( $dues as $due ) { $by_family[$due['family_uid']][] = $due; }
        foreach ( $transport as $row ) { $by_transport[$row['family_uid']][] = $row; }
        $rows = array();
        foreach ( $families as $family ) {
            $uid = $family['family_uid']; $family_dues = $by_family[$uid] ?? array();
            $review = (array) get_option( self::review_key( $year, $family['oracle_family_id'] ), array() );
            $row = array_merge( $family, self::assess( $family_dues, $today, $policy, $review['typical_override'] ?? 0 ) );
            if ( strtoupper( $family['currency'] ?? 'JOD' ) !== 'JOD' ) { $row['warnings'][] = 'عملة المصدر ليست الدينار الأردني؛ يلزم مراجعة'; }
            $row['review'] = $review; $row['transport'] = array(); $unknown = false; $seen = array();
            $sync_times = array( $family['last_synced_at'] );
            foreach ( $family_dues as $due ) { $sync_times[] = $due['last_synced_at']; }
            foreach ( $by_transport[$uid] ?? array() as $tr ) {
                $sync_times[] = $tr['last_synced_at'];
                if ( ( ! empty( $tr['from_date'] ) && $tr['from_date'] > $today ) || ( ! empty( $tr['to_date'] ) && $tr['to_date'] < $today ) ) { continue; }
                if ( ! isset( $tr['is_active'] ) ) { $unknown = true; continue; }
                if ( (int) $tr['is_active'] !== 1 ) { continue; }
                $student = $tr['student_uid'] ?: (string) $tr['student_id'];
                if ( ! isset( $seen[$student] ) ) { $row['transport'][] = $tr; $seen[$student] = true; }
            }
            sort( $sync_times, SORT_STRING ); $row['source_synced_at'] = $sync_times[0] ?? '';
            $sync_date = substr( $row['source_synced_at'], 0, 10 );
            if ( ! self::valid_date( $sync_date ) || $sync_date > $today || ( new DateTimeImmutable( $sync_date ) )->diff( new DateTimeImmutable( $today ) )->days > (int) $policy['stale_days'] ) { $row['warnings'][] = 'المزامنة قديمة أو تاريخها غير صالح'; }
            if ( abs( self::money( $family['balance'] ) - $row['annual_balance'] ) > 20 ) { $row['warnings'][] = 'رصيد الملخص لا يطابق مجموع أرصدة الأقساط'; }
            if ( in_array( $uid, $future, true ) ) { $row['warnings'][] = 'حركات مستقبلية أو دون تاريخ؛ لا يمكن تأكيد رصيد اليوم'; }
            $row['action'] = self::recommendation( $row, $review, $today, count( $row['transport'] ), $unknown );
            $rows[] = $row;
        }
        return $rows;
    }

    public static function csv_cell( $value ) {
        $value = (string) $value;
        return preg_match( '/^[\s\x{FEFF}]*[=+@\-]/u', $value ) || preg_match( '/^[\t\r\n]/', $value ) ? "'" . $value : $value;
    }
}
