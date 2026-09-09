<?php
require __DIR__ . '/communications-bootstrap.php';
class Suite_Test_Core extends Communications_Test_Core {
    public $children = array(); public $student_records = array();
    public function get_by_family( $id, $year ) { return $this->children[$id] ?? array(); }
    public function students() { return new class( $this ) { private $core; public function __construct( $core ) { $this->core = $core; } public function get_by_uid( $id ) { return $this->core->student_records[$id] ?? null; } }; }
}
class Suite_Test_Scanner implements Olama_Messages_Malware_Scanner_Interface { public $state = 'clean'; public function scan( $path ) { return $this->state; } }
class Suite_Test_Source implements Olama_Messages_Event_Source_Interface { public $row; public $fail = false; public function read( $id ) { if ( $this->fail ) { throw new RuntimeException( 'Source offline' ); } return $this->row; } }
function suite_as( $id ) { wp_set_current_user( $id ); return ( new Olama_Messages_Actor_Resolver() )->resolve( '', true ); }
function suite_table( $name ) { return Olama_Messages_Communications_DB::table( $name ); }
function suite_drain() { global $wpdb; $jobs = new Olama_Messages_Job_Service(); for ( $i = 0; $i < 50; $i++ ) { $job = $jobs->reserve(); if ( ! $job ) { return; } if ( ! $jobs->execute( $job, array( 'Olama_Messages_Suite_Operations', 'handle_job' ) ) ) { throw new RuntimeException( 'Job failed: ' . $wpdb->get_var( 'SELECT last_error FROM ' . suite_table( 'jobs' ) . ' WHERE id=' . (int) $job['id'] ) ); } } throw new RuntimeException( 'Unbounded job chain' ); }
$private = sys_get_temp_dir() . '/olama-suite-' . bin2hex( random_bytes( 6 ) ); mkdir( $private );
$upload = tempnam( sys_get_temp_dir(), 'olama_suite_upload_' ); file_put_contents( $upload, 'Private fixture text, never a real school file.' );
register_shutdown_function( function () use ( $private, $upload ) { foreach ( glob( $private . '/*' ) as $file ) { if ( is_file( $file ) && ! is_link( $file ) ) { unlink( $file ); } } rmdir( $private ); unlink( $upload ); } );
$core = new Suite_Test_Core(); $GLOBALS['communications_test_core'] = $core;
foreach ( array( 'FA' => '1001', 'FB' => '1002' ) as $family => $oracle ) {
    $core->families[$family] = array( 'family_uid' => $family, 'oracle_family_id' => $oracle, 'sponsor_full_name' => 'أسرة ' . $family, 'is_active' => 1 );
    $wpdb->insert( $core->table( 'student_years' ), array( 'family_uid' => $family, 'study_year' => '2026-2027', 'student_status' => 'active' ) );
    foreach ( array( '1', '2' ) as $n ) { $uid = $family . '-S' . $n; $core->children[$family][] = array( 'student_uid' => $uid, 'student_name' => 'طالب ' . $uid, 'family_uid' => $family, 'class_id' => '4', 'section_id' => $n, 'student_status' => 'active' ); $core->student_records[$uid] = array( 'student_uid' => $uid, 'family_uid' => $family ); }
}
foreach ( array( 'ADMIN', 'OTHER' ) as $key ) { $core->employees[$key] = array( 'employee_id' => $key, 'full_name' => $key, 'employee_status' => 'مستمر', 'last_synced_at' => current_time( 'mysql' ) ); }
$admin_id = comm_user( 'employee', 'ADMIN' ); $other_id = comm_user( 'employee', 'OTHER' ); $fa_id = comm_user( 'family', '1001' ); $fb_id = comm_user( 'family', '1002' );
foreach ( array( $admin_id, $other_id, $fa_id, $fb_id ) as $uid ) { foreach ( array( 'events', 'actions', 'attachments', 'chat', 'service_inbox' ) as $cap ) { get_user_by( 'id', $uid )->add_cap( 'olama_messages_' . $cap ); } }
foreach ( array( 'manage_events', 'manage_actions', 'manage_inboxes', 'view_dashboard', 'audit' ) as $cap ) { get_user_by( 'id', $admin_id )->add_cap( 'olama_messages_' . $cap ); }
$settings = array( 'enabled' => true, 'events_enabled' => true, 'actions_enabled' => true, 'attachments_enabled' => true, 'chat_enabled' => true, 'private_storage_path' => $private, 'private_storage_reviewed_path' => wp_normalize_path( $private ), 'malware_policy' => 'required' ); update_option( 'olama_msg_communications', $settings );
$scanner = new Suite_Test_Scanner(); add_filter( 'olama_messages_malware_scanner', function () use ( $scanner ) { return $scanner; } );
$source = new Suite_Test_Source(); add_filter( 'olama_messages_event_sources', function ( $sources ) use ( $source ) { $sources['fixture'] = $source; return $sources; } );
$admin = suite_as( $admin_id ); $files = new Olama_Messages_Attachment_Service(); $events = new Olama_Messages_Event_Service(); $actions = new Olama_Messages_Action_Service(); $campaigns = new Olama_Messages_Internal_Campaign_Service(); $activity = new Olama_Messages_Activity_Service();
Olama_Messages_Communications_DB::install(); comm_assert( ! Olama_Messages_Communications_DB::health(), 'additive suite migration is healthy and repeatable' );
$wpdb->query( 'ALTER TABLE ' . suite_table( 'action_items' ) . ' DROP COLUMN request_hash' );
update_option( 'olama_msg_communications_db_version', '3', false ); delete_transient( 'olama_msg_schema_health' );
comm_assert( in_array( 'action_items: missing request_hash', Olama_Messages_Communications_DB::health( true ), true ), 'fixture reproduces installed-site request_hash migration gap' );
( new Olama_Messages_Communications() )->install();
comm_assert( '4' === get_option( 'olama_msg_communications_db_version' ) && ! Olama_Messages_Communications_DB::health( true ), 'normal init repairs version-3 action table and advances schema version' );
comm_assert( $files->health()['storage_ready'], 'reviewed external directory accepted for private storage' );
update_option( 'olama_msg_communications', array_merge( $settings, array( 'private_storage_reviewed_path' => '' ) ) ); comm_throws( function () { new Olama_Messages_Local_Private_Storage(); }, 'unreviewed web alias exposure fails closed' );
update_option( 'olama_msg_communications', array_merge( $settings, array( 'private_storage_path' => ABSPATH . 'private', 'private_storage_reviewed_path' => ABSPATH . 'private' ) ) ); comm_throws( function () { new Olama_Messages_Local_Private_Storage(); }, 'storage beneath public root rejected' ); update_option( 'olama_msg_communications', $settings );
comm_throws( function () use ( $files, $upload ) { $files->validate( $upload, 'payload.php' ); }, 'executable file rejected' );
comm_throws( function () use ( $files, $upload ) { $files->validate( $upload, 'fake.png' ); }, 'extension spoof rejected by content inspection' );
comm_throws( function () use ( $files, $upload ) { $files->validate( $upload, 'image.heic' ); }, 'unsupported HEIC reports a capability error' );
$scanner->state = 'infected'; comm_throws( function () use ( $files, $upload ) { $files->validate( $upload, 'infected.txt' ); }, 'infected upload rejected' );
$scanner->state = 'unavailable'; comm_throws( function () use ( $files, $upload ) { $files->validate( $upload, 'unscanned.txt' ); }, 'required scanner outage blocks upload' ); $scanner->state = 'clean';
$staged = $files->stage_file( $admin, $upload, 'instructions.txt', 'event', 0 );
comm_assert( ! isset( $staged['storage_key'] ) && ! isset( $staged['path'] ), 'upload JSON never exposes storage paths or keys' );
$fb = suite_as( $fb_id ); comm_throws( function () use ( $files, $fb, $staged ) { $files->open( $fb, $staged['id'] ); }, 'another actor cannot download staged file' ); $admin = suite_as( $admin_id );
$event_data = array( 'title' => 'يوم الأسرة 😀', 'description' => 'اقرأ التعليمات', 'starts_at_utc' => gmdate( 'Y-m-d H:i:s', time() + 3 * DAY_IN_SECONDS ), 'ends_at_utc' => gmdate( 'Y-m-d H:i:s', time() + 3 * DAY_IN_SECONDS + HOUR_IN_SECONDS ), 'priority' => 'critical', 'rsvp_enabled' => true, 'action_required' => true, 'response_scope' => 'student', 'audience' => array( 'type' => 'selected', 'actor_keys' => array( 'family:FA' ) ), 'reminders' => array( 1440 ), 'attachment_ids' => array( (int) $staged['id'] ) );
$saved = $events->save( $admin, $event_data ); $id = $saved['id'];
comm_assert( 'event' === $files->row( $staged['id'] )['owner_type'], 'event save atomically claims staged attachment' );
comm_throws( function () use ( $events, $admin, $id ) { $events->command( $admin, $id, 'publish' ); }, 'event publication before complete snapshot rejected' );
$events->command( $admin, $id, 'prepare' ); suite_drain();
comm_assert( 'prepared' === $events->get( $id )['status'], 'event audience worker finalizes preparation' );
comm_assert( 2 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . suite_table( 'event_targets' ) . ' WHERE event_id=' . $id ), 'family audience expands to immutable separate child targets' );
$events->command( $admin, $id, 'publish' ); suite_drain();
comm_assert( 2 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . suite_table( 'action_items' ) ), 'event creates one action per child target' );
$fa = suite_as( $fa_id ); $detail = $events->detail( $fa, $id ); $target = $detail['targets'][0]; $second = $detail['targets'][1];
comm_assert( count( $detail['targets'] ) === 2 && count( $detail['attachments'] ) === 1, 'recipient sees own child targets and authorized attachment' );
$opened = $files->open( $fa, $staged['id'] ); comm_assert( stream_get_contents( $opened['stream'] ) === file_get_contents( $upload ), 'private recipient download returns original bytes' ); fclose( $opened['stream'] );
comm_assert( count( $activity->feed( $fa )['critical'] ) === 2, 'critical banner persists separately for each child' );
$events->respond( $fa, $target['id'], array( 'kind' => 'read', 'content_version' => 1 ) );
comm_assert( count( $activity->feed( $fa )['critical'] ) === 2, 'reading does not clear critical acknowledgment or finish action' );
$events->respond( $fa, $target['id'], array( 'kind' => 'ack', 'content_version' => 1, 'required_version' => 1 ) );
comm_assert( count( $activity->feed( $fa )['critical'] ) === 1, 'one child acknowledgment never acknowledges sibling' );
$events->respond( $fa, $target['id'], array( 'kind' => 'rsvp', 'content_version' => 1, 'required_version' => 1, 'response' => 'yes' ) );
comm_assert( 'pending' === $events->target( $fa, $second['id'] )['rsvp_status'], 'RSVP is child-specific' );
comm_throws( function () use ( $events, $fa, $target ) { $events->respond( $fa, $target['id'], array( 'kind' => 'ack', 'content_version' => 99, 'required_version' => 1 ) ); }, 'invented future displayed version rejected' );
$fb = suite_as( $fb_id ); comm_throws( function () use ( $events, $fb, $id ) { $events->detail( $fb, $id ); }, 'non-target actor cannot read event' ); comm_throws( function () use ( $files, $fb, $staged ) { $files->open( $fb, $staged['id'] ); }, 'non-target actor cannot download event attachment' );
$admin = suite_as( $admin_id ); $minor = $events->save( $admin, array( 'title' => 'تصحيح عنوان', 'content_version' => 1 ), $id ); suite_drain();
comm_assert( 1 === (int) $events->get( $id )['ack_required_version'], 'minor title edit preserves required-response version' );
$fa = suite_as( $fa_id ); $events->respond( $fa, $second['id'], array( 'kind' => 'ack', 'content_version' => 1, 'required_version' => 1 ) ); comm_assert( ! $activity->feed( $fa )['critical'], 'valid previously displayed minor revision can still be acknowledged' );
$admin = suite_as( $admin_id ); $events->save( $admin, array( 'location' => 'قاعة جديدة', 'content_version' => $minor['content_version'] ), $id ); suite_drain();
$fa = suite_as( $fa_id ); comm_throws( function () use ( $events, $fa, $target ) { $events->respond( $fa, $target['id'], array( 'kind' => 'ack', 'content_version' => 1, 'required_version' => 1 ) ); }, 'major location edit rejects stale form acknowledgment' );
comm_throws( function () use ( $events, $fa, $target ) { $events->respond( $fa, $target['id'], array( 'kind' => 'ack', 'content_version' => 1, 'required_version' => 3 ) ); }, 'forged pairing of old content and new required version rejected' );
comm_assert( count( $activity->feed( $fa )['critical'] ) === 2, 'major edit reinstates acknowledgment for both children' );
comm_assert( 2 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . suite_table( 'action_items' ) ), 'event refanout never duplicates actions' );
$items = $actions->listing( $fa )['items']; $action = $items[0]; $actions->change( $fa, $action['id'], array( 'version' => 1, 'status' => 'in_progress' ) );
comm_throws( function () use ( $actions, $fa, $action ) { $actions->change( $fa, $action['id'], array( 'version' => 1, 'status' => 'resolved', 'resolution_note' => 'done' ) ); }, 'optimistic action version rejects concurrent stale changes' );
comm_throws( function () use ( $actions, $fa, $action ) { $actions->change( $fa, $action['id'], array( 'version' => 2, 'status' => 'resolved' ) ); }, 'action completion requires resolution note' );
$resolved = $actions->change( $fa, $action['id'], array( 'version' => 2, 'status' => 'resolved', 'resolution_note' => 'تم التنفيذ' ) );
comm_assert( 'resolved' === $resolved['status'] && count( $actions->detail( $fa, $action['id'] )['history'] ) === 3, 'action timestamps and immutable history survive completion' );
$preferences = $activity->preferences( $fa, array( 'quiet_enabled' => true, 'quiet_start' => 0, 'quiet_end' => 1439 ) ); comm_assert( count( $activity->feed( $fa )['critical'] ) === 2, 'quiet hours cannot suppress critical banners' );
$ics = $events->ics( $fa, $id ); comm_assert( strpos( $ics, 'BEGIN:VCALENDAR' ) !== false && strpos( $ics, 'SEQUENCE:3' ) !== false, 'authorized ICS carries stable UID and current sequence' );
foreach ( explode( "\r\n", $ics ) as $line ) { if ( strlen( $line ) > 75 ) { throw new RuntimeException( 'ICS line exceeds 75 octets' ); } } comm_assert( true, 'ICS folding respects UTF-8 octets' );
$admin = suite_as( $admin_id ); $events->command( $admin, $id, 'add_audience', array( 'audience' => array( 'type' => 'selected', 'actor_keys' => array( 'family:FA', 'family:FB' ) ) ) ); suite_drain();
comm_assert( 4 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . suite_table( 'event_targets' ) . ' WHERE event_id=' . $id ), 'explicit added audience releases only new targets without duplicates' );
comm_throws( function () use ( $events, $admin, $id ) { $events->command( $admin, $id, 'delete' ); }, 'published events cannot be deleted' );
$source->row = array( 'source_version' => 'v1', 'title' => 'School authoritative title', 'starts_at_utc' => $event_data['starts_at_utc'], 'ends_at_utc' => $event_data['ends_at_utc'], 'timezone' => 'Asia/Amman', 'all_day' => 0 );
$source_data = $event_data; unset( $source_data['attachment_ids'] ); $source_data['source_type'] = 'fixture'; $source_data['source_id'] = '7';
$source_event = $events->save( $admin, $source_data )['id']; comm_assert( 'School authoritative title' === $events->get( $source_event )['title'], 'source-owned title overrides browser edit' );
$events->command( $admin, $source_event, 'prepare' ); suite_drain(); $events->command( $admin, $source_event, 'publish' ); suite_drain();
$source->fail = true; comm_throws( function () use ( $events, $source_event ) { $events->sync_source( $source_event ); }, 'unavailable source is a recoverable error' ); comm_assert( 'published' === $events->get( $source_event )['status'], 'source outage never fabricates source removal' );
$source->fail = false; $source->row['source_version'] = 'v2'; $source->row['starts_at_utc'] = gmdate( 'Y-m-d H:i:s', time() + 2 * DAY_IN_SECONDS ); $events->sync_source( $source_event ); suite_drain(); comm_assert( 2 === (int) $events->get( $source_event )['ack_required_version'], 'source reschedule invalidates previous required response version' );
$source->row = null; $events->sync_source( $source_event ); suite_drain(); comm_assert( 'source_removed' === $events->get( $source_event )['status'], 'authoritative removal preserves projection and cancels future reminders' );
$stale_job = array( 'object_id' => $source_event, 'job_type' => 'event_remind', 'payload_json' => wp_json_encode( array( 'version' => 1, 'offset' => 1440, 'after' => 0 ) ) );
$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . suite_table( 'activity_notifications' ) ); Olama_Messages_Communications_DB::transaction( function () use ( $events, $stale_job ) { $events->handle_job( $stale_job ); } ); comm_assert( $count === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . suite_table( 'activity_notifications' ) ), 'stale reminder job has zero side effects after source removal' );
$campaign_id = $campaigns->save( array( 'title' => 'إجراء إعلان', 'body' => 'الرجاء التنفيذ', 'purpose' => 'action_required', 'audience' => array( 'type' => 'selected', 'actor_keys' => array( 'family:FA' ) ) ), $admin ); $campaigns->command( $campaign_id, 'prepare', $admin ); suite_drain(); $campaigns->command( $campaign_id, 'publish', $admin ); suite_drain(); $campaigns->command( $campaign_id, 'retry_delivery', $admin ); suite_drain();
comm_assert( 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . suite_table( 'action_items' ) . " WHERE source_type='campaign_delivery'" ), 'action-required campaign fanout retries produce one action' );
$temporary = $files->stage_file( $admin, $upload, 'abandoned.txt', 'campaign', 0 ); $wpdb->update( suite_table( 'attachments' ), array( 'expires_at_utc' => gmdate( 'Y-m-d H:i:s', time() - 10 ) ), array( 'id' => $temporary['id'] ) ); $files->expire_staged(); comm_assert( 'expired' === $files->row( $temporary['id'] )['status'], 'orphaned staged uploads expire with private-byte cleanup' ); comm_assert( 'linked' === $files->row( $staged['id'] )['status'], 'cleanup preserves linked published evidence' );
$manual = $actions->create( $admin, array( 'title' => 'Admin action', 'client_id' => wp_generate_uuid4(), 'due_at_utc' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) ); Olama_Messages_Suite_Operations::maintenance();
comm_assert( 1 === (int) $actions->get( $admin, $manual['id'] )['overdue_notified_version'], 'overdue workflow notification records idempotent version' );
$dash = ( new Olama_Messages_Suite_Operations() )->dashboard( $admin ); comm_assert( $dash['overdue_actions'] > 0 && ! isset( $dash['messages'] ), 'dashboard exposes operational counts without private bodies' );
$fa = suite_as( $fa_id ); comm_throws( function () use ( $fa ) { ( new Olama_Messages_Suite_Operations() )->dashboard( $fa ); }, 'family cannot access employee metadata dashboard' );
$rest = new Olama_Messages_Suite_Rest_Controller(); $request = new WP_REST_Request( 'GET', '/olama-messages/v1/communications/suite/me' ); comm_assert( is_wp_error( $rest->permission( $request ) ), 'suite REST requires session nonce' ); $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) ); $request->set_header( 'X-Olama-Actor', $fa['actor_key'] ); comm_assert( true === $rest->permission( $request ), 'suite REST accepts verified selected identity' );
$admin = suite_as( $admin_id ); $retention = ( new Olama_Messages_Suite_Operations() )->retention( $admin, array() ); comm_assert( $retention['dry_run'] && ! $retention['deletion_enabled'], 'retention defaults to a dry run and never deletes content' );
comm_assert( 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . suite_table( 'sms_queue' ) ), 'suite does not enqueue external SMS' );
$inbox = Olama_Messages_Communications_DB::insert( 'service_inboxes', array( 'name' => 'Fixture service', 'created_at_utc' => gmdate( 'Y-m-d H:i:s' ), 'response_sla_minutes' => 1, 'resolution_sla_minutes' => 2 ) );
Olama_Messages_Communications_DB::insert( 'inbox_members', array( 'inbox_id' => $inbox, 'actor_key' => 'employee:ADMIN', 'is_manager' => 1 ) );
Olama_Messages_Communications_DB::insert( 'inbox_members', array( 'inbox_id' => $inbox, 'actor_key' => 'employee:OTHER' ) );
$fa = suite_as( $fa_id ); $chat = new Olama_Messages_Chat_Service(); $thread = $chat->create( $fa, array( 'kind' => 'service', 'inbox_id' => $inbox, 'subject' => 'Service fixture', 'client_thread_id' => wp_generate_uuid4() ) )['id'];
$staged_message = $files->stage_file( $fa, $upload, 'service.txt', 'thread', $thread );
$body = array( 'body' => '', 'client_message_id' => wp_generate_uuid4(), 'attachment_ids' => array( (int) $staged_message['id'] ) );
$sent = $chat->send( $fa, $thread, $body ); $again = $chat->send( $fa, $thread, $body );
comm_assert( $sent['id'] === $again['id'] && $again['duplicate'], 'attachment-only message is atomically linked and idempotent' );
comm_assert( 1 === count( $chat->conversation( $fa, $thread )['messages'][0]['attachments'] ), 'chat response includes only authorized attachment metadata' );
$staged_retry = $files->stage_file( $fa, $upload, 'replacement.txt', 'thread', $thread );
comm_throws( function () use ( $chat, $fa, $thread, $body, $staged_retry ) { $body['attachment_ids'] = array( (int) $staged_retry['id'] ); $chat->send( $fa, $thread, $body ); }, 'same message UUID with different attachment set conflicts' );
$admin = suite_as( $admin_id );
comm_throws( function () use ( $files, $admin, $staged_retry, $thread ) { Olama_Messages_Communications_DB::transaction( function () use ( $files, $admin, $staged_retry, $thread ) { $files->claim( $admin, array( (int) $staged_retry['id'] ), 'message', 9876, 'thread', $thread ); } ); }, 'same-thread employee cannot steal another uploader staged file' );
$wpdb->update( suite_table( 'threads' ), array( 'created_at_utc' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ), array( 'id' => $thread ) );
Olama_Messages_Suite_Operations::maintenance();
$service_row = $chat->get( $admin, $thread ); comm_assert( 1 === (int) $service_row['response_escalated_cycle'] && 1 === (int) $service_row['resolution_escalated_cycle'], 'response and resolution SLA independently escalate overdue requests' );
$escalated = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . suite_table( 'activity_notifications' ) . " WHERE object_type='thread'" ); Olama_Messages_Suite_Operations::maintenance(); comm_assert( $escalated === (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . suite_table( 'activity_notifications' ) . " WHERE object_type='thread'" ), 'repeated SLA maintenance does not duplicate escalation' );
$inboxes = new Olama_Messages_Service_Inbox_Service(); $inboxes->action( $admin, $thread, 'start' ); comm_assert( 'in_progress' === $chat->get( $admin, $thread )['status'], 'service request moves to in-progress' );
$chat->send( $admin, $thread, array( 'body' => 'searchable service response', 'client_message_id' => wp_generate_uuid4() ) );
$fa = suite_as( $fa_id ); $found = ( new Olama_Messages_Suite_Operations() )->search( $fa, array( 'query' => 'searchable' ) ); comm_assert( count( $found['items'] ) === 1, 'bounded search finds authorized in-progress service transcript' );
$fb = suite_as( $fb_id ); comm_assert( ! ( new Olama_Messages_Suite_Operations() )->search( $fb, array( 'query' => 'searchable' ) )['items'], 'search cannot expose another family transcript' );
comm_throws( function () use ( $fb ) { ( new Olama_Messages_Suite_Operations() )->search( $fb, array( 'query' => 'searchable', 'from' => '2000-01-01 00:00:00' ) ); }, 'unbounded date search rejected' );
$other = suite_as( $other_id ); $opened = $files->open( $other, $staged_message['id'] ); fclose( $opened['stream'] );
$wpdb->update( suite_table( 'inbox_members' ), array( 'active' => 0 ), array( 'inbox_id' => $inbox, 'actor_key' => $other['actor_key'] ) );
comm_throws( function () use ( $files, $other, $staged_message ) { $files->open( $other, $staged_message['id'] ); }, 'removed inbox member loses attachment access immediately' );
comm_assert( ! ( new Olama_Messages_Suite_Operations() )->search( $other, array( 'query' => 'searchable' ) )['items'], 'removed inbox member disappears from search scope' );
$fa = suite_as( $fa_id ); $blocked = Olama_Messages_Communications_DB::insert( 'restrictions', array( 'actor_key' => $fa['actor_key'], 'restriction_type' => 'attachment_block', 'scope_type' => 'thread', 'scope_key' => (string) $thread, 'private_reason' => 'fixture', 'starts_at_utc' => gmdate( 'Y-m-d H:i:s' ) ) );
comm_throws( function () use ( $files, $fa, $upload, $thread ) { $files->stage_file( $fa, $upload, 'blocked.txt', 'thread', $thread ); }, 'thread attachment restriction enforced at upload' );
comm_throws( function () use ( $chat, $fa, $thread, $staged_retry ) { $chat->send( $fa, $thread, array( 'body' => 'blocked claim', 'client_message_id' => wp_generate_uuid4(), 'attachment_ids' => array( (int) $staged_retry['id'] ) ) ); }, 'restriction applied after upload is rechecked at atomic send' );
comm_assert( 'staged' === $files->row( $staged_retry['id'] )['owner_type'], 'rejected send rolls back ownership claim' );
$wpdb->update( suite_table( 'restrictions' ), array( 'revoked_at_utc' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $blocked ) );
$admin = suite_as( $admin_id ); $inboxes->action( $admin, $thread, 'resolve' ); $inboxes->action( $admin, $thread, 'reopen' ); comm_assert( 2 === (int) $chat->get( $admin, $thread )['service_cycle'], 'reopening starts a new SLA cycle without deleting history' );
$events->save( $admin, array( 'content_version' => 3, 'completion_provider' => 'missing_provider' ), $id ); suite_drain();
$unknown = array( 'object_id' => $id, 'job_type' => 'event_remind', 'payload_json' => wp_json_encode( array( 'version' => 4, 'offset' => 1440, 'after' => 0 ) ) );
comm_throws( function () use ( $events, $unknown ) { Olama_Messages_Communications_DB::transaction( function () use ( $events, $unknown ) { $events->handle_job( $unknown ); } ); }, 'unknown completion provider prevents reminder rather than guessing incomplete' );
$events->command( $admin, $id, 'cancel' ); suite_drain(); $fa = suite_as( $fa_id ); comm_assert( ! $activity->feed( $fa )['critical'], 'cancelled and source-removed events clear critical obligations' );
comm_assert( strpos( $events->ics( $fa, $id ), 'STATUS:CANCELLED' ) !== false, 'cancelled ICS communicates cancellation' );
$admin = suite_as( $admin_id );
$image_path = tempnam( sys_get_temp_dir(), 'olama_image_' ); $image = imagecreatetruecolor( 1024, 512 ); imagepng( $image, $image_path ); imagedestroy( $image );
try {
    $image_file = $files->stage_file( $admin, $image_path, 'photo.png', 'campaign', 0 );
    comm_assert( in_array( 'thumb', $image_file['variants'], true ) && in_array( 'preview', $image_file['variants'], true ), 'real GD generates thumbnail and display preview inside private storage' );
    $opened = $files->open( $admin, $image_file['id'], 'thumb' ); $bytes = stream_get_contents( $opened['stream'] ); fclose( $opened['stream'] ); comm_assert( getimagesizefromstring( $bytes )[0] <= 320, 'authorized private thumbnail has bounded dimensions' );
    $fb = suite_as( $fb_id ); comm_throws( function () use ( $files, $fb, $image_file ) { $files->open( $fb, $image_file['id'], 'thumb' ); }, 'thumbnail uses same actor authorization as original' );
} finally { unlink( $image_path ); }
$admin = suite_as( $admin_id );
$date_event = $events->save( $admin, array( 'title' => 'يوم كامل', 'starts_at_local' => '2026-10-01T00:00', 'ends_at_local' => '2026-10-02T00:00', 'timezone' => 'Asia/Amman', 'all_day' => 1, 'audience' => array( 'type' => 'selected', 'actor_keys' => array( 'family:FA' ) ) ) )['id'];
comm_assert( '2026-09-30 21:00:00' === $events->get( $date_event )['starts_at_utc'], 'local event input converts using event timezone rather than browser timezone' );
$all_day_ics = $events->ics( $admin, $date_event ); comm_assert( strpos( $all_day_ics, 'DTSTART;VALUE=DATE:20261001' ) !== false && strpos( $all_day_ics, 'DTEND;VALUE=DATE:20261002' ) !== false, 'all-day ICS preserves exclusive end date' );
$writing = $files->stage_file( $admin, $upload, 'interrupted.txt', 'campaign', 0 ); $wpdb->update( suite_table( 'attachments' ), array( 'status' => 'writing', 'expires_at_utc' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $writing['id'] ) ); $files->expire_staged(); comm_assert( 'expired' === $files->row( $writing['id'] )['status'], 'process-interrupted write is tracked and cleaned' );
$request = array( 'title' => 'Retry action', 'client_id' => wp_generate_uuid4() ); $first = $actions->create( $admin, $request ); comm_assert( $first['id'] === $actions->create( $admin, $request )['id'], 'manual action retry creates one item' );
comm_throws( function () use ( $actions, $admin, $request ) { $request['title'] = 'different payload'; $actions->create( $admin, $request ); }, 'manual action UUID cannot silently reuse another payload' );
$off_hours = array_merge( $settings, array( 'office_days' => array() ) ); update_option( 'olama_msg_communications', $off_hours ); $state = $chat->send_state( $admin, $chat->get( $admin, $thread ) ); comm_assert( $state['allowed'] && $state['office_hours_note'], 'outside office hours informs sender without blocking sending' ); update_option( 'olama_msg_communications', $settings );
comm_assert( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . suite_table( 'action_items' ) . " a INNER JOIN " . suite_table( 'event_targets' ) . " t ON t.id=a.source_id WHERE a.source_type='event_target' AND t.event_id=" . $id . " AND a.status IN ('open','in_progress')" ), 'event cancellation closes outstanding linked actions without losing completed evidence' );
comm_throws( function () use ( $events, $admin, $date_event ) { $events->save( $admin, array( 'content_version' => 1, 'starts_at_local' => '2026-10-01T13:00' ), $date_event ); }, 'all-day event rejects non-midnight boundary' );
$events->command( $admin, $date_event, 'prepare' ); suite_drain(); $old_snapshot = $events->get( $date_event )['snapshot_id']; $events->command( $admin, $date_event, 'reset' );
comm_assert( 'draft' === $events->get( $date_event )['status'] && $old_snapshot !== $events->get( $date_event )['snapshot_id'], 'unpublished preparation reset creates a distinct snapshot' );
$events->command( $admin, $date_event, 'prepare' ); suite_drain(); comm_assert( 'prepared' === $events->get( $date_event )['status'], 'reset event can prepare again without old target uniqueness conflicts' );
$core->children['FA'] = array();
for ( $n = 0; $n < 150; $n++ ) { $uid = 'batch-student-' . $n; $core->children['FA'][] = array( 'student_uid' => $uid, 'student_name' => 'Fixture ' . $n, 'family_uid' => 'FA', 'student_status' => 'active' ); $core->student_records[$uid] = array( 'student_uid' => $uid, 'family_uid' => 'FA' ); }
$batch_event = $events->save( $admin, array( 'title' => 'Batch fixture', 'starts_at_utc' => $event_data['starts_at_utc'], 'ends_at_utc' => $event_data['ends_at_utc'], 'response_scope' => 'student', 'audience' => array( 'type' => 'selected', 'actor_keys' => array( 'family:FA' ) ) ) )['id'];
$events->command( $admin, $batch_event, 'prepare' ); suite_drain(); $events->command( $admin, $batch_event, 'publish' ); suite_drain();
comm_assert( 150 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . suite_table( 'event_targets' ) . ' WHERE event_id=' . $batch_event . ' AND released_version=1' ), 'event fanout continuation releases every target across 100-target pages' );
comm_assert( 150 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . suite_table( 'activity_notifications' ) . " n INNER JOIN " . suite_table( 'event_targets' ) . " t ON t.id=n.object_id WHERE n.object_type='event_target' AND t.event_id=" . $batch_event ), 'paged event fanout creates exactly one publication notification per target' );
$fa = suite_as( $fa_id ); $first_page = $events->detail( $fa, $batch_event ); $last_page = $events->detail( $fa, $batch_event, $first_page['targets_next_after'] );
comm_assert( count( $first_page['targets'] ) === 100 && count( $last_page['targets'] ) === 50, 'large per-actor event targets are completely accessible through pagination' );
comm_assert( ! array_intersect( array_column( $first_page['targets'], 'id' ), array_column( $last_page['targets'], 'id' ) ), 'event target cursor never duplicates a child response' );
echo "Suite integration tests passed. Disposable database and private files removed on exit.\n";
