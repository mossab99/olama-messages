<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Olama_Messages_Activity_Service {
    /** Metadata only. The caller's transaction supplies idempotent business effects. */
    public static function emit( $actor_key, $type, $id, $version, $kind, $critical = false ) {
        global $wpdb; $t = Olama_Messages_Communications_DB::table( 'activity_notifications' );
        $key = hash( 'sha256', wp_json_encode( array( $actor_key, $type, $id, $version, $kind ) ) );
        Olama_Messages_Communications_DB::query( $wpdb->prepare( "INSERT INTO {$t} (activity_key,actor_key,object_type,object_id,object_version,kind,critical,created_at_utc) VALUES (%s,%s,%s,%d,%d,%s,%d,%s) ON DUPLICATE KEY UPDATE id=id", $key, $actor_key, $type, $id, $version, $kind, $critical ? 1 : 0, gmdate( 'Y-m-d H:i:s' ) ) );
    }
    private function authorize( array $actor, array $row ) {
        if ( $row['actor_key'] !== $actor['actor_key'] ) { throw new RuntimeException( 'تنبيه غير مخول.' ); }
        if ( 'event_target' === $row['object_type'] ) { $t = ( new Olama_Messages_Event_Service() )->target( $actor, $row['object_id'] ); $e = ( new Olama_Messages_Event_Service() )->visible( $actor, $t['event_id'] ); return array( 'title' => $e['title'], 'event_id' => $e['id'] ); }
        if ( 'action' === $row['object_type'] ) { $a = ( new Olama_Messages_Action_Service() )->get( $actor, $row['object_id'] ); return array( 'title' => $a['title'] ); }
        if ( 'thread' === $row['object_type'] ) { $t = ( new Olama_Messages_Chat_Service() )->get( $actor, $row['object_id'] ); return array( 'title' => $t['subject'] ); }
        throw new RuntimeException( 'نوع تنبيه غير معروف.' );
    }
    public function listing( array $actor, $before = 0 ) {
        global $wpdb; Olama_Messages_Suite_Policy::actor( $actor ); $t = Olama_Messages_Communications_DB::table( 'activity_notifications' );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE actor_key=%s AND (%d=0 OR id<%d) ORDER BY id DESC LIMIT 100", $actor['actor_key'], $before, $before ), ARRAY_A ); $items = array(); $cursor = 0;
        foreach ( $rows as $row ) { $cursor = (int) $row['id']; try { $items[] = $row + $this->authorize( $actor, $row ); } catch ( RuntimeException $e ) {} if ( count( $items ) === 30 ) { break; } }
        return array( 'items' => $items, 'next_before' => count( $rows ) === 100 || count( $items ) === 30 ? $cursor : 0 );
    }
    public function receipt( array $actor, array $data ) {
        global $wpdb; Olama_Messages_Suite_Policy::actor( $actor );
        $ids = Olama_Messages_Suite_Policy::bounded_ids( $data['ids'] ?? array(), 100 ); $kind = $data['kind'] ?? '';
        if ( ! in_array( $kind, array( 'delivered', 'seen' ), true ) ) { throw new InvalidArgumentException( 'إيصال غير صالح.' ); }
        return Olama_Messages_Communications_DB::transaction( function () use ( $wpdb, $actor, $ids, $kind ) {
            $t = Olama_Messages_Communications_DB::table( 'activity_notifications' );
            foreach ( $ids as $id ) { $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id=%d AND actor_key=%s", $id, $actor['actor_key'] ), ARRAY_A ); if ( ! $row ) { throw new RuntimeException( 'التنبيه غير متاح.' ); } $this->authorize( $actor, $row );
                $column = 'seen' === $kind ? 'seen_at_utc' : 'delivered_at_utc'; Olama_Messages_Communications_DB::query( $wpdb->prepare( "UPDATE {$t} SET {$column}=COALESCE({$column},%s) WHERE id=%d AND actor_key=%s", gmdate( 'Y-m-d H:i:s' ), $id, $actor['actor_key'] ) );
            } return array( 'ok' => true );
        } );
    }
    public function preferences( array $actor, $data = null ) {
        global $wpdb; Olama_Messages_Suite_Policy::actor( $actor ); $t = Olama_Messages_Communications_DB::table( 'communication_preferences' );
        if ( null !== $data ) {
            $start = (int) ( $data['quiet_start'] ?? 1320 ); $end = (int) ( $data['quiet_end'] ?? 420 );
            if ( $start < 0 || $start > 1439 || $end < 0 || $end > 1439 ) { throw new InvalidArgumentException( 'وقت هدوء غير صالح.' ); }
            Olama_Messages_Communications_DB::query( $wpdb->prepare( "INSERT INTO {$t} (actor_key,quiet_enabled,quiet_start,quiet_end,previews,sound) VALUES (%s,%d,%d,%d,%d,%d) ON DUPLICATE KEY UPDATE quiet_enabled=VALUES(quiet_enabled),quiet_start=VALUES(quiet_start),quiet_end=VALUES(quiet_end),previews=VALUES(previews),sound=VALUES(sound)", $actor['actor_key'], empty( $data['quiet_enabled'] ) ? 0 : 1, $start, $end, empty( $data['previews'] ) ? 0 : 1, empty( $data['sound'] ) ? 0 : 1 ) );
        }
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT quiet_enabled,quiet_start,quiet_end,previews,sound FROM {$t} WHERE actor_key=%s", $actor['actor_key'] ), ARRAY_A ) ?: array( 'quiet_enabled' => 0, 'quiet_start' => 1320, 'quiet_end' => 420, 'previews' => 0, 'sound' => 0 );
        $minute = (int) wp_date( 'G' ) * 60 + (int) wp_date( 'i' );
        $row['quiet_now'] = $row['quiet_enabled'] && ( $row['quiet_start'] > $row['quiet_end'] ? $minute >= $row['quiet_start'] || $minute < $row['quiet_end'] : $minute >= $row['quiet_start'] && $minute < $row['quiet_end'] ); return $row;
    }
    public function feed( array $actor ) {
        global $wpdb; Olama_Messages_Suite_Policy::actor( $actor ); $critical = array();
        if ( ! empty( Olama_Messages_Communication_Policy::settings()['events_enabled'] ) && current_user_can( 'olama_messages_events' ) ) {
            $t = Olama_Messages_Communications_DB::table( 'event_targets' ); $e = Olama_Messages_Communications_DB::table( 'events' );
            $critical = $wpdb->get_results( $wpdb->prepare( "SELECT t.id AS target_id,e.id AS event_id,e.title,e.content_version FROM {$t} t INNER JOIN {$e} e ON e.id=t.event_id WHERE t.actor_key=%s AND t.released_version>0 AND e.status='published' AND e.priority='critical' AND e.requires_ack=1 AND t.acknowledged_ack_version<e.ack_required_version ORDER BY e.id DESC LIMIT 100", $actor['actor_key'] ), ARRAY_A );
        }
        return array( 'critical' => $critical, 'preferences' => $this->preferences( $actor ), 'activity' => $this->listing( $actor ) );
    }
}
