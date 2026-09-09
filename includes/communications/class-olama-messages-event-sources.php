<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

interface Olama_Messages_Event_Source_Interface {
    /** Null means authoritatively removed. Throw when the source is unavailable. */
    public function read( $source_id );
}
interface Olama_Messages_Completion_Provider_Interface {
    /** Return complete, incomplete, or unknown. Never perform business mutations. */
    public function completion( array $event, array $target );
}
class Olama_Messages_Event_Sources {
    public static function read( $type, $id ) {
        $sources = apply_filters( 'olama_messages_event_sources', array( 'school' => new Olama_Messages_School_Event_Source() ) );
        if ( ! isset( $sources[$type] ) || ! $sources[$type] instanceof Olama_Messages_Event_Source_Interface ) { throw new RuntimeException( 'مصدر الفعالية غير متاح.' ); }
        $result = $sources[$type]->read( $id );
        if ( null !== $result && ( ! is_array( $result ) || empty( $result['source_version'] ) || empty( $result['title'] ) || empty( $result['starts_at_utc'] ) || empty( $result['ends_at_utc'] ) ) ) { throw new RuntimeException( 'عقد مصدر الفعالية غير صالح.' ); }
        return $result;
    }
    public static function completion( array $event, array $target ) {
        if ( ! $event['completion_provider'] ) { return 'incomplete'; }
        if ( 'action' === $event['completion_provider'] ) {
            global $wpdb; $a = Olama_Messages_Communications_DB::table( 'action_items' );
            $state = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$a} WHERE source_type='event_target' AND source_id=%d ORDER BY id DESC LIMIT 1", $target['id'] ) );
            if ( $wpdb->last_error || ! $state ) { return 'unknown'; }
            return 'resolved' === $state ? 'complete' : 'incomplete';
        }
        $providers = apply_filters( 'olama_messages_completion_providers', array() );
        $p = $providers[$event['completion_provider']] ?? null;
        if ( ! $p instanceof Olama_Messages_Completion_Provider_Interface ) { return 'unknown'; }
        $state = $p->completion( $event, $target );
        return in_array( $state, array( 'complete', 'incomplete' ), true ) ? $state : 'unknown';
    }
}
class Olama_Messages_School_Event_Source implements Olama_Messages_Event_Source_Interface {
    public function read( $source_id ) {
        global $wpdb;
        if ( ! ctype_digit( (string) $source_id ) || ! is_callable( array( 'Olama_School_Academic', 'get_event' ) ) ) { throw new RuntimeException( 'واجهة فعاليات OLAMA School غير متاحة.' ); }
        $wpdb->last_error = '';
        $row = Olama_School_Academic::get_event( (int) $source_id );
        if ( $wpdb->last_error ) { throw new RuntimeException( 'تعذر قراءة فعالية المدرسة.' ); }
        if ( ! $row ) { return null; }
        $row = (array) $row;
        $start = Olama_Messages_Suite_Policy::local_to_utc( $row['start_date'], true );
        $end = DateTimeImmutable::createFromFormat( '!Y-m-d', $row['end_date'], wp_timezone() );
        if ( ! $end || $end->format( 'Y-m-d' ) !== $row['end_date'] ) { throw new RuntimeException( 'موعد المصدر غير صالح.' ); }
        return array( 'source_version' => hash( 'sha256', wp_json_encode( $row ) ), 'title' => mb_substr( $row['event_description'], 0, 190 ),
            'starts_at_utc' => $start, 'ends_at_utc' => $end->modify( '+1 day' )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ), 'all_day' => 1, 'timezone' => wp_timezone_string(), 'source_status' => 'active' );
    }
}
