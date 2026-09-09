<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Olama_Messages_Chat_Policy {
    public static function require_use( array $actor ) {
        Olama_Messages_Communication_Policy::require_use( $actor );
        if ( empty( Olama_Messages_Communication_Policy::settings()['chat_enabled'] ) || ! Olama_Messages_Communication_Policy::can( 'olama_messages_chat' ) ) { throw new RuntimeException( 'المراسلات الخاصة غير مفعلة لهذا الحساب.' ); }
        $verified = ( new Olama_Messages_Actor_Resolver() )->resolve( $actor['actor_key'], true );
        if ( $verified['actor_key'] !== $actor['actor_key'] ) { throw new RuntimeException( 'هوية غير مخولة.' ); }
    }

    public static function require_staff( array $actor, $cap ) {
        self::require_use( $actor );
        if ( ! Olama_Messages_Communication_Policy::staff_actor( $actor ) || ! Olama_Messages_Communication_Policy::can( 'olama_messages_' . $cap ) ) { throw new RuntimeException( 'يتطلب هذا الإجراء هوية موظف أو مدير وصلاحية مخولة.' ); }
    }

    public static function restrictions( array $actor, $operation, array $thread = array(), $target = '' ) {
        global $wpdb;
        $table = Olama_Messages_Communications_DB::table( 'restrictions' );
        $now = gmdate( 'Y-m-d H:i:s' );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE (actor_key=%s OR actor_key='*') AND revoked_at_utc IS NULL AND starts_at_utc<=%s AND (ends_at_utc IS NULL OR ends_at_utc>%s)", $actor['actor_key'], $now, $now ), ARRAY_A );
        if ( $wpdb->last_error ) { throw new RuntimeException( 'تعذر التحقق من قيود المراسلة.' ); }
        foreach ( $rows as $row ) {
            $scoped = 'global' === $row['scope_type'] || ( 'actor' === $row['scope_type'] && $row['scope_key'] === $actor['actor_key'] ) || ( 'thread' === $row['scope_type'] && $row['scope_key'] === (string) ( $thread['id'] ?? '' ) ) || ( 'service_inbox' === $row['scope_type'] && $row['scope_key'] === (string) ( $thread['inbox_id'] ?? '' ) );
            if ( ! $scoped || ( $row['target_key'] && $row['target_key'] !== $target ) ) { continue; }
            if ( ( 'attachment_block' === $row['restriction_type'] && 'attachment' === $operation ) || 'chat_block' === $row['restriction_type'] || 'target_block' === $row['restriction_type'] || ( 'send_block' === $row['restriction_type'] && in_array( $operation, array( 'send', 'edit' ), true ) ) || ( 'new_thread_block' === $row['restriction_type'] && 'create' === $operation ) ) {
                throw new RuntimeException( 'هذا الإجراء مقيد حالياً. يمكنك الاطلاع على سجل المحادثات والإعلانات.' );
            }
        }
    }

    /** Atomic fixed-window counter, shared by every WP session of the canonical actor. */
    public static function rate( array $actor, $kind ) {
        global $wpdb;
        $window = 'thread' === $kind ? 600 : 60;
        $limit = max( 1, (int) apply_filters( 'olama_messages_' . $kind . '_rate_limit', 'thread' === $kind ? 10 : 20 ) );
        $bucket = hash( 'sha256', $actor['actor_key'] . ':' . $kind . ':' . floor( time() / $window ) );
        $table = Olama_Messages_Communications_DB::table( 'chat_rate_limits' );
        Olama_Messages_Communications_DB::query( $wpdb->prepare( "INSERT INTO {$table} (bucket_key,hits,expires_at_utc) VALUES (%s,1,%s) ON DUPLICATE KEY UPDATE hits=hits+1", $bucket, gmdate( 'Y-m-d H:i:s', ( floor( time() / $window ) + 1 ) * $window ) ) );
        $hits = (int) $wpdb->get_var( $wpdb->prepare( "SELECT hits FROM {$table} WHERE bucket_key=%s FOR UPDATE", $bucket ) );
        if ( $hits > $limit ) { throw new RuntimeException( 'تم بلوغ حد المراسلة المؤقت. يرجى المحاولة لاحقاً.' ); }
    }

    public static function plain_text( $text, $max = 5000 ) {
        if ( ! is_string( $text ) || ! preg_match( '//u', $text ) ) { throw new InvalidArgumentException( 'النص غير صالح.' ); }
        $text = trim( str_replace( array( "\r\n", "\r" ), "\n", $text ) );
        if ( '' === $text || preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $text ) || mb_strlen( $text, 'UTF-8' ) > $max ) { throw new InvalidArgumentException( 'النص مطلوب ويجب ألا يتجاوز ' . $max . ' حرفاً.' ); }
        return $text;
    }
}
