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
		$counts = array_fill_keys( array( 'prepared', 'reserved', 'sent', 'failed', 'retry_wait' ), 0 );
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$this->queue} GROUP BY status", ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$counts[ $row['status'] ] = (int) $row['total'];
		}
		return $counts;
	}

	public function campaign_counts() {
		global $wpdb;
		$counts = array_fill_keys( array( 'draft', 'prepared', 'sending', 'paused', 'completed', 'cancelled' ), 0 );
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$this->campaigns} GROUP BY status", ARRAY_A );
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
				"SELECT q.*, c.title AS campaign_title FROM {$this->queue} q LEFT JOIN {$this->campaigns} c ON c.id=q.campaign_id WHERE q.status IN ('failed','retry_wait') ORDER BY q.updated_at DESC LIMIT %d",
				absint( $limit )
			),
			ARRAY_A
		);
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
