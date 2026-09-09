<?php
/** Additive Release A schema and checked database operations. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Olama_Messages_Communications_DB {
    const VERSION = '4';

    public static function table( $name ) {
        global $wpdb;
        return $wpdb->prefix . 'olama_msg_' . $name;
    }

    public static function definitions() {
        return array_merge( Olama_Messages_Chat_Schema::definitions(), Olama_Messages_Suite_Schema::definitions(), array(
            'internal_campaigns' => "campaign_id bigint unsigned NOT NULL,
                workflow_json longtext NULL,
                purpose varchar(30) NOT NULL,
                audience_json longtext NOT NULL,
                snapshot_id char(36) NOT NULL,
                cursor_offset bigint unsigned NOT NULL DEFAULT 0,
                snapshot_complete tinyint NOT NULL DEFAULT 0,
                resolution_started_at_utc datetime NULL,
                resolution_completed_at_utc datetime NULL,
                source_versions_json longtext NULL,
                published_at_utc datetime NULL,
                scheduled_at_utc datetime NULL,
                created_by_actor_key varchar(191) COLLATE utf8mb4_bin NOT NULL,
                published_by_actor_key varchar(191) NULL,
                PRIMARY KEY  (campaign_id)",
            'campaign_targets' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
                campaign_id bigint unsigned NOT NULL,
                snapshot_id char(36) NOT NULL,
                actor_key varchar(191) COLLATE utf8mb4_bin NOT NULL,
                display_name_snapshot varchar(190) NOT NULL,
                context_json longtext NOT NULL,
                reachability varchar(30) NOT NULL DEFAULT 'unchecked',
                checked_at_utc datetime NULL,
                created_at_utc datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY target (campaign_id,snapshot_id,actor_key),
                KEY actor (actor_key,id)",
            'internal_deliveries' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
                campaign_id bigint unsigned NOT NULL,
                campaign_target_id bigint unsigned NOT NULL,
                actor_key varchar(191) COLLATE utf8mb4_bin NOT NULL,
                rendered_title varchar(190) NOT NULL,
                rendered_body longtext NOT NULL,
                purpose varchar(30) NOT NULL,
                sent_at_utc datetime NOT NULL,
                delivered_at_utc datetime NULL,
                read_at_utc datetime NULL,
                acknowledged_at_utc datetime NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY target (campaign_target_id),
                KEY actor (actor_key,id),
                KEY unread (actor_key,read_at_utc),
                KEY campaign (campaign_id)",
            'notifications' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
                actor_key varchar(191) COLLATE utf8mb4_bin NOT NULL,
                delivery_id bigint unsigned NOT NULL,
                created_at_utc datetime NOT NULL,
                delivered_at_utc datetime NULL,
                seen_at_utc datetime NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY delivery (delivery_id),
                KEY actor (actor_key,id),
                KEY unseen (actor_key,seen_at_utc)",
            'jobs' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
                job_key varchar(191) NOT NULL,
                job_type varchar(30) NOT NULL,
                object_id bigint unsigned NOT NULL,
                payload_json longtext NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'pending',
                available_at_utc datetime NOT NULL,
                lock_token char(36) NULL,
                locked_at_utc datetime NULL,
                attempt_count int unsigned NOT NULL DEFAULT 0,
                max_attempts int unsigned NOT NULL DEFAULT 5,
                last_error text NULL,
                created_at_utc datetime NOT NULL,
                completed_at_utc datetime NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY job_key (job_key),
                KEY due (status,available_at_utc,id),
                KEY lease (status,locked_at_utc),
                KEY object (object_id)",
            'audit_log' => "id bigint unsigned NOT NULL AUTO_INCREMENT,
                business_actor_key varchar(191) COLLATE utf8mb4_bin NOT NULL,
                authenticated_wp_user_id bigint unsigned NOT NULL DEFAULT 0,
                action varchar(80) NOT NULL,
                object_id bigint unsigned NOT NULL DEFAULT 0,
                metadata_json longtext NOT NULL,
                created_at_utc datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY object (object_id,id),
                KEY action (action,id)"
        ) );
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        foreach ( self::definitions() as $name => $fields ) {
            $table = self::table( $name );
            dbDelta( "CREATE TABLE {$table} (\n{$fields}\n) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" );
        }
        delete_transient( 'olama_msg_schema_health' );
        if ( ! self::health( true ) ) {
            update_option( 'olama_msg_communications_db_version', self::VERSION, false );
        }
    }

    /** Structural checks also gate mutations; a warning alone is insufficient. */
    public static function health( $force = false ) {
        global $wpdb;
        $cached = get_transient( 'olama_msg_schema_health' );
        if ( ! $force && is_array( $cached ) ) { return $cached['errors']; }
        $errors = array();
        foreach ( array_merge( array( 'campaigns' => '' ), self::definitions() ) as $name => $fields ) {
            $table = self::table( $name );
            $status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ), ARRAY_A );
            if ( ! $status || 'InnoDB' !== $status['Engine'] ) { $errors[] = $name . ': InnoDB table required'; continue; }
            if ( $fields ) {
                $columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
                preg_match_all( '/(?:^|\n)\s*([a-z_]+)\s+(?:bigint|varchar|char|longtext|text|datetime|tinyint|int)\b/i', $fields, $matches );
                foreach ( $matches[1] as $column ) {
                    if ( ! in_array( $column, $columns, true ) ) { $errors[] = $name . ': missing ' . $column; }
                }
                $indexes = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );
                $unique = array();
                foreach ( $indexes as $index ) { if ( ! $index['Non_unique'] ) { $unique[] = $index['Key_name']; } }
                preg_match_all( '/UNIQUE KEY ([a-z_]+)/', $fields, $keys );
                foreach ( $keys[1] as $key ) { if ( ! in_array( $key, $unique, true ) ) { $errors[] = $name . ': missing unique index ' . $key; } }
            }
        }
        set_transient( 'olama_msg_schema_health', array( 'errors' => $errors ), 60 );
        return $errors;
    }

    public static function query( $sql ) {
        global $wpdb;
        $result = $wpdb->query( $sql );
        if ( false === $result ) { throw new RuntimeException( 'Communications database operation failed.' ); }
        return $result;
    }

    public static function insert( $name, array $data ) {
        global $wpdb;
        if ( false === $wpdb->insert( self::table( $name ), $data ) ) { throw new RuntimeException( 'Communications record could not be saved.' ); }
        return (int) $wpdb->insert_id;
    }

    public static function transaction( $callback ) {
        self::query( 'START TRANSACTION' );
        try {
            $result = call_user_func( $callback );
            self::query( 'COMMIT' );
            return $result;
        } catch ( Throwable $error ) {
            self::query( 'ROLLBACK' );
            throw $error;
        }
    }

    public static function audit( $action, $id, array $actor = array(), array $metadata = array() ) {
        self::insert( 'audit_log', array(
            'business_actor_key' => $actor['actor_key'] ?? 'system:school',
            'authenticated_wp_user_id' => get_current_user_id(),
            'action' => $action, 'object_id' => $id,
            'metadata_json' => wp_json_encode( $metadata ), 'created_at_utc' => gmdate( 'Y-m-d H:i:s' ),
        ) );
    }
}
