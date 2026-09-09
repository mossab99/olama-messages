<?php
/** Durable bounded jobs. Effects and completion share a fenced transaction. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Olama_Messages_Job_Service {
    public function enqueue( $key, $type, $id, array $payload = array(), $when = null ) {
        global $wpdb;
        $table = Olama_Messages_Communications_DB::table( 'jobs' );
        Olama_Messages_Communications_DB::query( $wpdb->prepare(
            "INSERT INTO {$table} (job_key,job_type,object_id,payload_json,status,available_at_utc,created_at_utc) VALUES (%s,%s,%d,%s,'pending',%s,%s) ON DUPLICATE KEY UPDATE job_key=VALUES(job_key)",
            $key, $type, $id, wp_json_encode( $payload ), $when ?: gmdate( 'Y-m-d H:i:s' ), gmdate( 'Y-m-d H:i:s' )
        ) );
    }

    public function reserve() {
        global $wpdb;
        $table = Olama_Messages_Communications_DB::table( 'jobs' );
        $lease = max( 60, (int) apply_filters( 'olama_messages_job_lease_seconds', 120 ) );
        $expired = gmdate( 'Y-m-d H:i:s', time() - $lease );
        // Exhausted abandoned jobs must surface as failures rather than stay running forever.
        Olama_Messages_Communications_DB::query( $wpdb->prepare( "UPDATE {$table} SET status='failed',lock_token=NULL,last_error='Worker lease expired after maximum attempts' WHERE status='running' AND locked_at_utc < %s AND attempt_count >= max_attempts", $expired ) );
        $token = wp_generate_uuid4();
        $changed = Olama_Messages_Communications_DB::query( $wpdb->prepare(
            "UPDATE {$table} SET status='running',lock_token=%s,locked_at_utc=%s,attempt_count=attempt_count+1 WHERE attempt_count < max_attempts AND ((status IN ('pending','retry_wait') AND available_at_utc <= %s) OR (status='running' AND locked_at_utc < %s)) ORDER BY id LIMIT 1",
            $token, gmdate( 'Y-m-d H:i:s' ), gmdate( 'Y-m-d H:i:s' ), $expired
        ) );
        return $changed ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE lock_token=%s", $token ), ARRAY_A ) : null;
    }

    public function execute( array $job, $handler ) {
        global $wpdb;
        $table = Olama_Messages_Communications_DB::table( 'jobs' );
        try {
            return Olama_Messages_Communications_DB::transaction( function () use ( $wpdb, $table, $job, $handler ) {
                $current = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d AND lock_token=%s AND status='running' FOR UPDATE", $job['id'], $job['lock_token'] ), ARRAY_A );
                if ( ! $current ) { return false; }
                // Row lock fences side effects: no second worker can reclaim until commit.
                call_user_func( $handler, $current );
                Olama_Messages_Communications_DB::query( $wpdb->prepare( "UPDATE {$table} SET status='completed',completed_at_utc=%s,lock_token=NULL WHERE id=%d AND lock_token=%s", gmdate( 'Y-m-d H:i:s' ), $job['id'], $job['lock_token'] ) );
                return true;
            } );
        } catch ( Throwable $error ) {
            $status = (int) $job['attempt_count'] >= (int) $job['max_attempts'] ? 'failed' : 'retry_wait';
            Olama_Messages_Communications_DB::query( $wpdb->prepare( "UPDATE {$table} SET status=%s,last_error=%s,available_at_utc=%s,lock_token=NULL WHERE id=%d AND lock_token=%s", $status, mb_substr( $error->getMessage(), 0, 1000 ), gmdate( 'Y-m-d H:i:s', time() + min( 3600, 30 * pow( 2, (int) $job['attempt_count'] ) ) ), $job['id'], $job['lock_token'] ) );
            return false;
        }
    }

    public function run( $handler ) {
        if ( empty( Olama_Messages_Communication_Policy::settings()['enabled'] ) || Olama_Messages_Communications_DB::health() ) { return; }
        update_option( 'olama_msg_communications_heartbeat', gmdate( 'Y-m-d H:i:s' ), false );
        $start = microtime( true );
        for ( $i = 0; $i < 20 && microtime( true ) - $start < 20; $i++ ) {
            $job = $this->reserve();
            if ( ! $job ) { break; }
            $this->execute( $job, $handler );
        }
    }
}
