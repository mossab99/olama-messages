<?php
/** Recipient-only notices and independently acknowledged receipts. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Olama_Messages_Notification_Service {
    public function notices( array $actor, $before = 0 ) {
        global $wpdb;
        $d = Olama_Messages_Communications_DB::table( 'internal_deliveries' );
        $c = Olama_Messages_Communications_DB::table( 'campaigns' );
        return $wpdb->get_results( $wpdb->prepare( "SELECT d.id,d.rendered_title,d.purpose,d.sent_at_utc,d.delivered_at_utc,d.read_at_utc,d.acknowledged_at_utc,c.status AS campaign_status FROM {$d} d INNER JOIN {$c} c ON c.id=d.campaign_id AND c.channel='internal' WHERE d.actor_key=%s AND (%d=0 OR d.id<%d) ORDER BY d.id DESC LIMIT 30", $actor['actor_key'], $before, $before ), ARRAY_A );
    }

    public function notice( array $actor, $id ) {
        global $wpdb;
        $d = Olama_Messages_Communications_DB::table( 'internal_deliveries' );
        $c = Olama_Messages_Communications_DB::table( 'campaigns' );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT d.*,c.status AS campaign_status FROM {$d} d INNER JOIN {$c} c ON c.id=d.campaign_id AND c.channel='internal' WHERE d.id=%d AND d.actor_key=%s", $id, $actor['actor_key'] ), ARRAY_A );
        if ( ! $row ) { throw new RuntimeException( 'الإعلان غير متاح لهذا الحساب.' ); }
        $row['attachments'] = ( new Olama_Messages_Attachment_Service() )->listing( $actor, 'campaign', $row['campaign_id'] );
        $row['action_id'] = 0;
        if ( ! empty( Olama_Messages_Communication_Policy::settings()['actions_enabled'] ) && current_user_can( 'olama_messages_actions' ) ) { $a = Olama_Messages_Communications_DB::table( 'action_items' ); $row['action_id'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$a} WHERE source_type='campaign_delivery' AND source_id=%d AND owner_key=%s", $id, $actor['actor_key'] ) ); }
        return $row;
    }

    public function notifications( array $actor, $after = 0, $before = 0, $unseen = false ) {
        global $wpdb;
        $n = Olama_Messages_Communications_DB::table( 'notifications' );
        $d = Olama_Messages_Communications_DB::table( 'internal_deliveries' );
        $order = $after ? 'ASC' : 'DESC';
        return $wpdb->get_results( $wpdb->prepare( "SELECT n.*,d.rendered_title FROM {$n} n INNER JOIN {$d} d ON d.id=n.delivery_id AND d.actor_key=n.actor_key WHERE n.actor_key=%s AND n.id>%d AND (%d=0 OR n.id<%d)" . ( $unseen ? ' AND n.seen_at_utc IS NULL' : '' ) . " ORDER BY n.id {$order} LIMIT 50", $actor['actor_key'], $after, $before, $before ), ARRAY_A );
    }

    public function counts( array $actor ) {
        global $wpdb;
        $n = Olama_Messages_Communications_DB::table( 'notifications' );
        $d = Olama_Messages_Communications_DB::table( 'internal_deliveries' );
        return array(
            'notifications' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$n} WHERE actor_key=%s AND seen_at_utc IS NULL", $actor['actor_key'] ) ),
            'notices' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$d} WHERE actor_key=%s AND read_at_utc IS NULL", $actor['actor_key'] ) ),
        );
    }

    public function receipt( array $actor, array $ids, $kind ) {
        global $wpdb;
        if ( ! in_array( $kind, array( 'delivered', 'read', 'acknowledge', 'seen' ), true ) || ! $ids || count( $ids ) > 100 ) { throw new InvalidArgumentException( 'طلب تأكيد غير صالح.' ); }
        $ids = array_values( array_unique( array_map( 'absint', $ids ) ) );
        return Olama_Messages_Communications_DB::transaction( function () use ( $wpdb, $actor, $ids, $kind ) {
            $n = Olama_Messages_Communications_DB::table( 'notifications' );
            $d = Olama_Messages_Communications_DB::table( 'internal_deliveries' );
            foreach ( $ids as $id ) {
                $row = $this->notice( $actor, $id );
                $now = gmdate( 'Y-m-d H:i:s' );
                if ( 'seen' === $kind ) {
                    Olama_Messages_Communications_DB::query( $wpdb->prepare( "UPDATE {$n} SET seen_at_utc=COALESCE(seen_at_utc,%s) WHERE delivery_id=%d AND actor_key=%s", $now, $id, $actor['actor_key'] ) );
                    continue;
                }
                $column = 'delivered' === $kind ? 'delivered_at_utc' : ( 'read' === $kind ? 'read_at_utc' : 'acknowledged_at_utc' );
                if ( 'acknowledge' === $kind && 'acknowledgement' !== $row['purpose'] ) { throw new InvalidArgumentException( 'هذا الإعلان لا يتطلب إقراراً.' ); }
                $changed = Olama_Messages_Communications_DB::query( $wpdb->prepare( "UPDATE {$d} SET {$column}=%s WHERE id=%d AND actor_key=%s AND {$column} IS NULL", $now, $id, $actor['actor_key'] ) );
                if ( 'delivered' === $kind ) { Olama_Messages_Communications_DB::query( $wpdb->prepare( "UPDATE {$n} SET delivered_at_utc=COALESCE(delivered_at_utc,%s) WHERE delivery_id=%d AND actor_key=%s", $now, $id, $actor['actor_key'] ) ); }
                if ( $changed && 'acknowledge' === $kind ) { Olama_Messages_Communications_DB::audit( 'notice_acknowledged', $id, $actor, array( 'campaign_id' => $row['campaign_id'] ) ); }
            }
            return array( 'ok' => true );
        } );
    }
}
