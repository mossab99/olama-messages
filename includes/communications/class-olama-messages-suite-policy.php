<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Olama_Messages_Suite_Policy {
    public static function actor( array $actor ) {
        Olama_Messages_Communication_Policy::require_use( $actor );
        return ( new Olama_Messages_Actor_Resolver() )->resolve( $actor['actor_key'], true );
    }
    public static function feature( array $actor, $feature ) {
        self::actor( $actor );
        if ( empty( Olama_Messages_Communication_Policy::settings()[$feature . '_enabled'] ) || ! current_user_can( 'olama_messages_' . $feature ) ) { throw new RuntimeException( 'هذه الخدمة غير مفعلة لهذا الحساب.' ); }
    }
    public static function staff( array $actor, $cap, $feature = '' ) {
        if ( $feature ) { self::feature( $actor, $feature ); } else { self::actor( $actor ); }
        if ( 'employee' !== $actor['actor_type'] || ! current_user_can( 'olama_messages_' . $cap ) ) { throw new RuntimeException( 'يتطلب الإجراء هوية موظف وصلاحية مخولة.' ); }
    }
    public static function update( $table, array $data, array $where ) {
        global $wpdb;
        if ( false === $wpdb->update( Olama_Messages_Communications_DB::table( $table ), $data, $where ) ) { throw new RuntimeException( 'تعذر حفظ سجل الاتصالات.' ); }
    }
    public static function utc( $value, $nullable = false ) {
        if ( $nullable && ! $value ) { return null; }
        if ( ! is_string( $value ) ) { throw new InvalidArgumentException( 'الموعد غير صالح.' ); }
        $date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
        if ( ! $date || $date->format( 'Y-m-d H:i:s' ) !== $value ) { throw new InvalidArgumentException( 'أدخل موعد UTC صالحاً.' ); }
        return $value;
    }
    public static function local_to_utc( $value, $date_only = false ) {
        $format = $date_only ? 'Y-m-d' : 'Y-m-d\TH:i';
        $date = DateTimeImmutable::createFromFormat( '!' . $format, (string) $value, wp_timezone() );
        if ( ! $date || $date->format( $format ) !== $value ) { throw new InvalidArgumentException( 'الموعد المحلي غير صالح.' ); }
        return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
    }
    public static function user_for_actor( $key, $cap = '' ) {
        if ( 'eligible_account' !== ( new Olama_Messages_Actor_Resolver() )->reachability( $key ) ) { return 0; }
        list( $type, $external ) = explode( ':', $key, 2 );
        if ( 'family' === $type ) { $family = olama_core()->families()->get_by_uid( $external ); $external = $family['oracle_family_id']; }
        $identity = Olama_Users_DB::get_identity( $type, $external );
        $id = (int) ( $identity['wp_user_id'] ?? 0 );
        return $id && ( ! $cap || user_can( $id, 'olama_messages_' . $cap ) ) ? $id : 0;
    }
    public static function bounded_ids( $input, $max = 5 ) {
        if ( ! is_array( $input ) || count( $input ) > $max ) { throw new InvalidArgumentException( 'عدد الملفات يتجاوز الحد المسموح.' ); }
        $ids = array();
        foreach ( $input as $id ) { if ( ! is_scalar( $id ) || ! preg_match( '/^[1-9][0-9]*$/', (string) $id ) ) { throw new InvalidArgumentException( 'معرف ملف غير صالح.' ); } $ids[] = (int) $id; }
        if ( count( array_unique( $ids ) ) !== count( $ids ) ) { throw new InvalidArgumentException( 'معرف ملف مكرر.' ); }
        sort( $ids, SORT_NUMERIC ); return $ids;
    }
}
