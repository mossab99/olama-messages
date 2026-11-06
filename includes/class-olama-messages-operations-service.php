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

    /** Read-only dashboard aggregates; dates are UTC in storage, school-local in UI. */
    public function command_center( string $year = '' ) {
        global $wpdb;
        $end = new DateTimeImmutable( 'tomorrow', wp_timezone() );
        $start = $end->modify( '-1 day' );
        $bounds = array( $start->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ), $end->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) );
        $attempts = $wpdb->prefix . 'olama_msg_dispatch_attempts';
        $today_attempts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$attempts} WHERE created_at >= %s AND created_at < %s", $bounds ) );
        $submitted = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->queue} WHERE status='sent' AND sent_at >= %s AND sent_at < %s", $bounds ) );
        $failed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->queue} WHERE status='failed'" );
        $year_where = $year ? $wpdb->prepare( ' AND c.study_year=%s', $year ) : '';
        $campaigns = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->campaigns} c WHERE c.channel='sms' {$year_where}" );
        $trend = (array) $wpdb->get_results( "SELECT c.id,c.title,c.created_at,c.total_excluded,(SELECT COUNT(*) FROM {$this->queue} q WHERE q.campaign_id=c.id AND q.status='sent') AS submitted FROM {$this->campaigns} c WHERE c.channel='sms' AND c.status NOT IN ('archived','draft') {$year_where} ORDER BY c.created_at DESC,c.id DESC LIMIT 7", ARRAY_A );
        return compact( 'today_attempts', 'submitted', 'failed', 'campaigns', 'trend', 'year' );
    }

    /** Shared filtered, bounded recipient query for the explorer and streaming export. */
    public function campaign_recipients( int $campaign_id, array $args = array() ) {
        global $wpdb;
        $args = array_merge( array( 'search' => '', 'status' => '', 'orderby' => 'id', 'order' => 'ASC', 'page' => 1, 'limit' => 12 ), $args );
        $recipients = $wpdb->prefix . 'olama_msg_campaign_recipients';
        $where = $wpdb->prepare( 'q.campaign_id=%d', $campaign_id );
        if ( in_array( $args['status'], array( 'prepared','reserved','sent','failed','unknown','retry_wait','cancelled' ), true ) ) { $where .= $wpdb->prepare( ' AND q.status=%s', $args['status'] ); }
        if ( '' !== $args['search'] ) { $term = '%' . $wpdb->esc_like( $args['search'] ) . '%'; $where .= $wpdb->prepare( ' AND (r.recipient_name LIKE %s OR q.phone_e164 LIKE %s OR CAST(q.id AS CHAR) LIKE %s)', $term, $term, $term ); }
        $sort = in_array( $args['orderby'], array( 'id','status','attempt_count','sent_at','updated_at' ), true ) ? 'q.' . $args['orderby'] : 'q.id';
        $order = 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC';
        $limit = min( 500, max( 1, (int) $args['limit'] ) ); $offset = ( max( 1, (int) $args['page'] ) - 1 ) * $limit;
        $join = "FROM {$this->queue} q LEFT JOIN {$recipients} r ON r.id=q.campaign_recipient_id WHERE {$where}";
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) {$join}" );
        $items = (array) $wpdb->get_results( $wpdb->prepare( "SELECT q.id,q.status,q.phone_e164,q.attempt_count,q.last_error_message,q.reserved_by_agent_id,q.sent_at,q.updated_at,r.recipient_name {$join} ORDER BY {$sort} {$order},q.id {$order} LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A );
        return array( 'items' => $items, 'total' => $total, 'pages' => (int) ceil( $total / $limit ) );
    }

    public static function delivery_csv_row( array $row ) {
        $phone = $row['phone_e164'] ? substr($row['phone_e164'],0,5).'•••'.substr($row['phone_e164'],-3) : '';
        $cells = array($row['id'],$row['recipient_name'],$phone,$row['status']==='sent' ? 'submitted_to_KDE' : $row['status'],$row['attempt_count'],$row['last_error_message'],$row['sent_at'],$row['updated_at']);
        return array_map(static function($cell) {
            $cell=(string)$cell;
            return preg_match('/^[\s\x00-\x1F]*[=+@-]/u',$cell) ? "'".$cell : $cell;
        },$cells);
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
