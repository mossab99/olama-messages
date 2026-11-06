<?php
/**
 * Read-only operational summaries for the Messages admin.
 *
 * @package Olama_Messages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Olama_Messages_Operations_Service {
	private $campaigns;
	private $queue;
	private $agents;

	public function __construct() {
		global $wpdb;
		$this->campaigns = $wpdb->prefix . 'olama_msg_campaigns';
		$this->queue     = $wpdb->prefix . 'olama_msg_queue';
		$this->agents    = $wpdb->prefix . 'olama_msg_agents';
	}

	public function queue_counts() {
		global $wpdb;
		$counts = array_fill_keys( array( 'prepared', 'reserved', 'sent', 'failed', 'retry_wait', 'unknown' ), 0 );
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$this->queue} GROUP BY status", ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$counts[ $row['status'] ] = (int) $row['total'];
		}
		return $counts;
	}

	public function campaign_counts() {
		global $wpdb;
		$counts = array_fill_keys( array( 'draft', 'prepared', 'sending', 'paused', 'completed', 'cancelled' ), 0 );
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$this->campaigns} WHERE channel='sms' GROUP BY status", ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$key = 'completed_with_errors' === $row['status'] ? 'completed' : $row['status'];
			$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + (int) $row['total'];
		}
		return $counts;
	}

	public function recent_failures( $limit = 10 ) {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT q.*, c.title AS campaign_title FROM {$this->queue} q LEFT JOIN {$this->campaigns} c ON c.id=q.campaign_id WHERE q.status IN ('failed','retry_wait','unknown') ORDER BY q.updated_at DESC LIMIT %d",
				absint( $limit )
			),
			ARRAY_A
		);
	}

    /** Read-only totals with prepared and authorized work deliberately separated. */
    public function workspace_summary() {
        global $wpdb;
        $start = new DateTimeImmutable( 'today', wp_timezone() );
        $utc_start = $start->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
        $submitted = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->queue} WHERE status='sent' AND sent_at >= %s", $utc_start ) );
        $authorized = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->queue} q JOIN {$this->campaigns} c ON c.id=q.campaign_id WHERE c.status IN ('sending','paused') AND q.status IN ('prepared','reserved','retry_wait') AND c.channel='sms'" );
        $review = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->queue} WHERE status='unknown'" );
        $prepared = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->campaigns} WHERE status='prepared' AND channel='sms'" );
        $campaign = $wpdb->get_row( "SELECT * FROM {$this->campaigns} WHERE status IN ('sending','paused') AND channel='sms' ORDER BY FIELD(status,'sending','paused'),id DESC LIMIT 1", ARRAY_A );
        $counts = array_fill_keys( array( 'prepared','reserved','retry_wait','sent','failed','unknown','cancelled' ), 0 );
        if ( $campaign ) { foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT status,COUNT(*) AS total FROM {$this->queue} WHERE campaign_id=%d GROUP BY status", $campaign['id'] ), ARRAY_A ) as $row ) { $counts[$row['status']] = (int) $row['total']; } }
        return array( 'submitted' => $submitted, 'authorized' => $authorized, 'review' => $review, 'prepared' => $prepared, 'campaign' => $campaign, 'counts' => $counts );
    }

    public function recent_activity( int $limit = 50, string $status = '' ) {
        global $wpdb;
        $allowed = array( 'reserved','sent','failed','unknown','retry_wait' );
        $where = in_array( $status, $allowed, true ) ? $wpdb->prepare( 'q.status=%s', $status ) : "q.status IN ('reserved','sent','failed','unknown','retry_wait')";
        return (array) $wpdb->get_results( $wpdb->prepare( "SELECT q.id,q.campaign_id,q.phone_e164,q.status,q.last_error_message,q.updated_at,c.title AS campaign_title
            FROM {$this->queue} q LEFT JOIN {$this->campaigns} c ON c.id=q.campaign_id WHERE {$where} ORDER BY q.updated_at DESC,q.id DESC LIMIT %d", min( 100, max( 1, $limit ) ) ), ARRAY_A );
    }

	public function readiness() {
		$agent = Olama_Messages_Plugin::instance()->agents()->get_ready_dispatcher_agent();
		$health = Olama_Messages_Plugin::instance()->provider()->get_sync_health( 'collection', '' );
		return array(
			'agent_ready' => ! empty( $agent ),
			'agent'       => $agent,
			'core_ready'  => ! empty( $health['ready'] ),
			'core'        => $health,
		);
	}
}
