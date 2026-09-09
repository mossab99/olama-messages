<?php
require __DIR__ . '/communications-bootstrap.php';
try {
    $db = 'Olama_Messages_Communications_DB';
    $core = olama_core();
    $core->employees['E-42'] = array( 'employee_id' => 'E-42', 'full_name' => 'موظف الاختبار', 'employee_status' => 'مستمر' );
    $core->families['stable-family-a'] = array( 'family_uid' => 'stable-family-a', 'oracle_family_id' => '1008', 'sponsor_full_name' => 'أسرة الاختبار' );
    $wpdb->insert( $core->table( 'student_years' ), array( 'family_uid' => 'stable-family-a', 'study_year' => '2026-2027', 'student_status' => 'active' ) );
    $employee_user = comm_user( 'employee', 'E-42' );
    $family_user = comm_user( 'family', '1008' );
    update_option( 'olama_msg_communications', array( 'enabled' => true, 'notifications' => true ) );
    wp_set_current_user( $employee_user );
    $resolver = new Olama_Messages_Actor_Resolver();
    $employee = $resolver->resolve();
    comm_assert( 'employee:E-42' === $employee['actor_key'], 'opaque employee identity is not WP ID' );
    $svc = new Olama_Messages_Internal_Campaign_Service();
    $jobs = new Olama_Messages_Job_Service();
    $notifications = new Olama_Messages_Notification_Service();
    $drain = function () use ( $jobs, $svc ) {
        for ( $i = 0; $i < 30; $i++ ) {
            $job = $jobs->reserve(); if ( ! $job ) { return; }
            if ( ! $jobs->execute( $job, array( $svc, 'handle_job' ) ) ) { throw new RuntimeException( 'Worker failed: ' . wp_json_encode( Olama_Messages_Communications::health()['jobs'] ) ); }
        }
        throw new RuntimeException( 'Job loop did not terminate.' );
    };
    $data = array( 'title' => 'إعلان عربي 😀', 'body' => 'مرحباً {recipient_name}\nنص <script>alert(1)</script>', 'purpose' => 'acknowledgement', 'audience' => array( 'type' => 'general' ) );
    $legacy = new Olama_Messages_Campaign_Service();
    $sms_id = $legacy->create_campaign( array( 'title' => 'Legacy preserved' ) );
    $legacy_before = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $db::table( 'campaigns' ) . ' WHERE id=%d', $sms_id ), ARRAY_A );
    $id = $svc->save( $data, $employee );
    $db::install(); $db::install();
    comm_assert( ! $db::health(), 'real dbDelta migration is repeatable and InnoDB healthy' );
    comm_assert( $legacy_before === $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $db::table( 'campaigns' ) . ' WHERE id=%d', $sms_id ), ARRAY_A ), 'migration preserves every legacy campaign field' );
    comm_assert( null === $legacy->get_campaign( $id ), 'legacy lifecycle cannot resolve an internal campaign' );
    comm_throws( function () use ( $legacy ) { $legacy->create_campaign( array( 'channel' => 'internal' ) ); }, 'legacy creation rejects internal channel' );
    comm_throws( function () use ( $legacy, $sms_id ) { $legacy->update_campaign( $sms_id, array( 'channel' => 'internal' ) ); }, 'legacy channel cannot be switched' );
    comm_throws( function () use ( $legacy ) { $legacy->preview_candidates( array( 'channel' => 'internal' ) ); }, 'array-based legacy preview rejects internal channel before phone logic' );
    comm_throws( function () use ( $svc, $employee, $data ) { $data['purpose'] = 'action_required'; $svc->save( $data, $employee ); }, 'Release A rejects action-required campaigns' );
    comm_throws( function () use ( $svc, $id, $employee ) { $svc->command( $id, 'publish', $employee ); }, 'draft cannot publish without snapshot' );
    $svc->command( $id, 'prepare', $employee );
    comm_throws( function () use ( $svc, $id, $employee ) { $svc->command( $id, 'publish', $employee ); }, 'incomplete snapshot cannot publish' );
    $drain();
    comm_assert( 'prepared' === $svc->get( $id )['status'], 'audience preparation finalizes before delivery' );
    comm_assert( 1 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $db::table( 'campaign_targets' ) ), 'phone-independent family snapshot created' );
    $svc->command( $id, 'publish', $employee ); $drain();
    comm_throws( function () use ( $svc, $id, $employee ) { $svc->command( $id, 'delete', $employee ); }, 'published campaign cannot be deleted' );
    $svc->command( $id, 'retry_delivery', $employee ); $drain();
    comm_assert( 1 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $db::table( 'internal_deliveries' ) ), 'repeated fanout creates exactly one delivery' );
    comm_assert( 1 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $db::table( 'notifications' ) ), 'repeated fanout creates exactly one notification' );
    comm_assert( 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $db::table( 'queue' ) ) && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $db::table( 'campaign_recipients' ) ), 'complete internal lifecycle creates zero SMS queue/recipient rows' );

    wp_set_current_user( $family_user );
    $family = $resolver->resolve();
    comm_assert( 'family:stable-family-a' === $family['actor_key'], 'family actor uses stable Core UID' );
    comm_throws( function () use ( $resolver ) { $resolver->resolve( 'employee:E-42' ); }, 'browser actor spoof rejected' );
    comm_throws( function () use ( $svc, $data, $family ) { $svc->save( $data, $family ); }, 'family cannot use staff account capability for publishing' );
    $delivery = $notifications->notices( $family )[0];
    $did = (int) $delivery['id'];
    comm_assert( ! $delivery['delivered_at_utc'] && ! $delivery['read_at_utc'] && ! $delivery['acknowledged_at_utc'], 'database existence is only Sent' );
    $notifications->receipt( $family, array( $did ), 'seen' );
    comm_assert( ! $notifications->notice( $family, $did )['read_at_utc'], 'notification seen never implies message read' );
    $notifications->receipt( $family, array( $did ), 'delivered' );
    comm_assert( $notifications->notice( $family, $did )['delivered_at_utc'] && ! $notifications->notice( $family, $did )['read_at_utc'], 'delivery acknowledgement does not imply read' );
    $notifications->receipt( $family, array( $did ), 'read' );
    comm_assert( ! $notifications->notice( $family, $did )['acknowledged_at_utc'], 'read never implies explicit acknowledgement' );
    $notifications->receipt( $family, array( $did ), 'acknowledge' ); $notifications->receipt( $family, array( $did ), 'acknowledge' );
    $audit = $wpdb->get_results( "SELECT * FROM " . $db::table( 'audit_log' ) . " WHERE action='notice_acknowledged'", ARRAY_A );
    comm_assert( 1 === count( $audit ) && $audit[0]['business_actor_key'] === $family['actor_key'] && (int) $audit[0]['authenticated_wp_user_id'] === $family_user, 'ack audit records family and authenticated account once' );
    comm_throws( function () use ( $notifications, $employee, $did ) { $notifications->notice( $employee, $did ); }, 'unrelated actor cannot fetch notice content' );
    $core->enrolled = false;
    comm_throws( function () use ( $resolver ) { $resolver->resolve(); }, 'withdrawn family loses current access' );
    comm_assert( 1 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $db::table( 'campaign_targets' ) ), 'lost eligibility preserves original snapshot' );
    $core->enrolled = true;
    update_user_meta( $family_user, 'olama_account_status', 'suspended' );
    comm_assert( 'inactive' === $resolver->reachability( $family['actor_key'] ), 'account suspension is reported independently' );
    comm_throws( function () use ( $resolver ) { $resolver->resolve(); }, 'suspended account cannot resolve actor' );
    delete_user_meta( $family_user, 'olama_account_status' );

    wp_set_current_user( $employee_user );
    $svc->command( $id, 'cancel', $employee );
    comm_assert( 1 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $db::table( 'internal_deliveries' ) ), 'cancellation preserves delivered evidence' );
    $svc->command( $id, 'archive', $employee );
    $draft = $svc->save( $data, $employee ); $svc->command( $draft, 'delete', $employee );
    comm_throws( function () use ( $svc, $draft ) { $svc->get( $draft ); }, 'never-published draft can be deleted' );

    // A live stale worker must lose both job mutations and all handler side effects.
    $jobs->enqueue( 'fencing-test', 'test', 0 ); $worker_a = $jobs->reserve();
    $j = $db::table( 'jobs' );
    $wpdb->query( $wpdb->prepare( "UPDATE {$j} SET locked_at_utc='2000-01-01' WHERE id=%d", $worker_a['id'] ) );
    $worker_b = $jobs->reserve(); $effects = 0;
    comm_assert( $worker_a['id'] === $worker_b['id'] && $worker_a['lock_token'] !== $worker_b['lock_token'], 'expired lease reclaimed with a different token' );
    $jobs->execute( $worker_a, function () use ( &$effects ) { $effects++; } );
    comm_assert( 0 === $effects, 'resumed stale worker cannot execute side effects' );
    $jobs->execute( $worker_b, function () use ( &$effects ) { $effects++; } );
    comm_assert( 1 === $effects, 'current owner executes once' );

    $jobs->enqueue( 'rollback-test', 'test', 0 ); $crash = $jobs->reserve();
    $before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $db::table( 'audit_log' ) );
    $jobs->execute( $crash, function () use ( $db ) { $db::audit( 'should_rollback', 0 ); throw new RuntimeException( 'simulated crash' ); } );
    comm_assert( $before === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $db::table( 'audit_log' ) ), 'worker failure rolls back committed-domain candidates' );
    comm_assert( 'retry_wait' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$j} WHERE id=%d", $crash['id'] ) ), 'worker failure schedules backoff' );

    // Multiple pages, pause after page one, and cancellation before pending fanout.
    for ( $i = 0; $i < 205; $i++ ) {
        $core->families['bulk-' . $i] = array( 'family_uid' => 'bulk-' . $i, 'oracle_family_id' => 'bulk-' . $i, 'sponsor_full_name' => 'أسرة ' . $i );
        $wpdb->insert( $core->table( 'student_years' ), array( 'family_uid' => 'bulk-' . $i, 'study_year' => '2026-2027', 'student_status' => 'active' ) );
    }
    $bulk = $svc->save( $data, $employee ); $svc->command( $bulk, 'prepare', $employee );
    $page_job = $jobs->reserve(); $jobs->execute( $page_job, array( $svc, 'handle_job' ) );
    comm_assert( 'preparing' === $svc->get( $bulk )['status'], 'large audience remains preparing after first bounded page' );
    $drain();
    $stats = $svc->statistics( $bulk );
    comm_assert( 206 === array_sum( array_column( $stats['reachability'], 'total' ) ), 'batched snapshot includes all targets exactly once' );
    $svc->command( $bulk, 'publish', $employee ); $pending = $jobs->reserve();
    $svc->command( $bulk, 'cancel', $employee ); $jobs->execute( $pending, array( $svc, 'handle_job' ) );
    comm_assert( 0 === (int) $svc->statistics( $bulk )['receipts']['sent'], 'cancelled campaign prevents previously reserved fanout side effects' );
    comm_assert( count( $legacy->list_campaigns() ) === 1, 'SMS listing excludes internal campaigns' );
    comm_assert( 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $db::table( 'queue' ) ), 'SMS queue remains empty after all internal operations' );
    $selected_data = $data;
    $selected_data['audience'] = array( 'type' => 'selected', 'actor_keys' => array( $family['actor_key'] ) );
    $scheduled = $svc->save( $selected_data, $employee ); $svc->command( $scheduled, 'prepare', $employee ); $drain();
    $svc->command( $scheduled, 'publish', $employee, array( 'scheduled_at_utc' => gmdate( 'Y-m-d\TH:i', time() + 3600 ) ) );
    comm_assert( null === $jobs->reserve(), 'scheduled publication cannot reserve fanout before due time' );
    comm_assert( 0 === (int) $svc->statistics( $scheduled )['receipts']['sent'], 'scheduled notice remains invisible before delivery' );
    $wpdb->query( $wpdb->prepare( "UPDATE {$j} SET available_at_utc='2000-01-01' WHERE object_id=%d AND job_type='fanout'", $scheduled ) );
    $drain();
    comm_assert( 1 === (int) $svc->statistics( $scheduled )['receipts']['sent'], 'due scheduled fanout delivers its frozen target' );
    comm_assert( 'no_account' === $resolver->reachability( 'family:bulk-0' ), 'eligible family without account is not reported as unread' );
    $core->ready = false;
    comm_throws( function () { ( new Olama_Messages_Audience_Resolver() )->page( array( 'type' => 'general' ), 0 ); }, 'unready audience source fails safely rather than publishing empty targets' );
    $core->ready = true;
    $delivery_table = $db::table( 'internal_deliveries' );
    comm_assert( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$delivery_table} WHERE actor_key=%s", strtoupper( $family['actor_key'] ) ) ), 'opaque actor keys retain case-sensitive database isolation' );
    // Exercise actual registered REST routes, nonce checks, and actor permission callbacks.
    $controller = new Olama_Messages_Communications_Rest_Controller();
    $controller->register_routes();
    wp_set_current_user( $family_user );
    $request = new WP_REST_Request( 'GET', '/olama-messages/v1/communications/me' );
    comm_assert( is_wp_error( $controller->permission( $request ) ), 'REST requests without nonce are denied' );
    $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
    $request->set_header( 'X-Olama-Actor', $family['actor_key'] );
    comm_assert( true === $controller->permission( $request ), 'valid family nonce and actor pass REST permission' );
    $response = $controller->dispatch( $request );
    comm_assert( $response->get_data()['actor']['actor_key'] === $family['actor_key'] && false === $response->get_data()['can_manage'], 'REST me never promotes family using employee capabilities' );
    $request = new WP_REST_Request( 'GET', '/olama-messages/v1/communications/campaigns' );
    $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) ); $request->set_header( 'X-Olama-Actor', $family['actor_key'] );
    comm_assert( is_wp_error( $controller->permission( $request ) ), 'staff campaign REST routes reject family actor' );
    update_option( 'olama_msg_communications', array( 'enabled' => true, 'pilot_users' => array( $employee_user ) ) );
    comm_assert( 'outside_pilot' === $resolver->reachability( $family['actor_key'] ), 'pilot rollout scopes fanout as well as frontend access' );
    comm_assert( is_wp_error( $controller->permission( $request ) ), 'pilot exclusion denies REST access' );
    update_option( 'olama_msg_communications', array( 'enabled' => false ) );
    wp_set_current_user( $employee_user );
    comm_assert( false !== strpos( ( new Olama_Messages_Communications() )->app(), 'الاتصالات غير مفعلة' ), 'disabled-site UI identifies the global feature flag' );
    update_option( 'olama_msg_communications', array( 'enabled' => true, 'pilot_users' => array( $family_user ) ) );
    comm_assert( false !== strpos( ( new Olama_Messages_Communications() )->app(), 'غير مضاف إليها' ), 'pilot-excluded UI identifies the account rollout gate' );
    update_option( 'olama_msg_communications', array( 'enabled' => true ) );
    $unmapped_user = wp_insert_user( array( 'user_login' => 'comm_unmapped', 'user_pass' => wp_generate_password( 30 ), 'role' => 'subscriber' ) );
    get_user_by( 'id', $unmapped_user )->add_cap( 'olama_messages_use' );
    wp_set_current_user( $unmapped_user );
    $unmapped_app = ( new Olama_Messages_Communications() )->app();
    comm_assert( false !== strpos( $unmapped_app, 'يلزم ربط هوية OLAMA' ) && false === strpos( $unmapped_app, 'data-olama-communications' ), 'unmapped account gets an actionable identity message instead of an endless loader' );
    update_option( 'olama_msg_communications', array( 'enabled' => false ) );
    wp_set_current_user( $employee_user );
    comm_throws( function () use ( $employee ) { Olama_Messages_Communication_Policy::require_use( $employee ); }, 'disabled feature blocks business operations' );
    $table = $db::table( 'internal_deliveries' ); $wpdb->query( "ALTER TABLE {$table} DROP INDEX target" );
    comm_assert( ! empty( $db::health( true ) ), 'missing uniqueness safeguard is detected by schema health' );
    $db::install(); comm_assert( ! $db::health( true ), 'migration repairs missing unique delivery index' );
    echo "Communications integration suite passed. Disposable database is removed on exit.\n";
} catch ( Throwable $error ) {
    fwrite( STDERR, $error->getMessage() . "\n" . $error->getTraceAsString() . "\n" ); exit( 1 );
}
