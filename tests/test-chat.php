<?php
require __DIR__ . '/communications-bootstrap.php';

class Chat_Test_Core extends Communications_Test_Core {
    public $children = array(); public $student_records = array();
    public function current() { return (object) array( 'study_year' => '2026-2027', 'academic_year_id' => 6, 'semester_id' => 11 ); }
    public function get_by_family( $id, $year ) { return $this->enrolled ? ( $this->children[$id] ?? array() ) : array(); }
    public function table( $name ) { global $wpdb; return in_array( $name, array( 'academic_grade_subjects', 'academic_grade_sections' ), true ) ? $wpdb->prefix . 'fixture_' . $name : parent::table( $name ); }
    public function students() { return new class( $this ) {
        private $core;
        public function __construct( $core ) { $this->core = $core; }
        public function get_by_uid( $id ) { return $this->core->student_records[$id] ?? null; }
    }; }
}
$core = new Chat_Test_Core(); $GLOBALS['communications_test_core'] = $core;
$wpdb->query( 'ALTER TABLE ' . $core->table( 'student_years' ) . ' ADD student_uid varchar(191), ADD class_id varchar(50), ADD section_id varchar(50)' );
$now = current_time( 'mysql' );
$core->families['F-A'] = array( 'family_uid' => 'F-A', 'oracle_family_id' => '0008', 'sponsor_full_name' => 'أسرة تجريبية', 'is_active' => 1 );
$core->families['F-B'] = array( 'family_uid' => 'F-B', 'oracle_family_id' => '0009', 'sponsor_full_name' => 'أسرة أخرى', 'is_active' => 1 );
foreach ( array( 'F-A', 'F-B' ) as $family ) {
    $core->children[$family] = array( array( 'family_uid' => $family, 'student_uid' => 'S-' . $family, 'student_status' => 'active', 'class_id' => '04', 'class_name' => 'الرابع', 'section_id' => '02', 'study_year' => '2026-2027', 'last_synced_at' => $now ) );
    $core->student_records['S-' . $family] = array( 'student_uid' => 'S-' . $family, 'family_uid' => $family, 'student_name' => 'طالب ' . $family, 'last_synced_at' => $now );
    $wpdb->insert( $core->table( 'student_years' ), array( 'family_uid' => $family, 'student_uid' => 'S-' . $family, 'study_year' => '2026-2027', 'student_status' => 'active', 'class_id' => '04', 'section_id' => '02' ) );
}
foreach ( array( 'T-001', 'T-002', 'ADMIN-003', 'OTHER-004', 'MOD-005' ) as $employee ) { $core->employees[$employee] = array( 'employee_id' => $employee, 'full_name' => 'موظف ' . $employee, 'employee_status' => 'مستمر', 'last_synced_at' => $now ); }
add_role( 'olama_teacher', 'Teacher', array( 'read' => true ) );
$family_user = comm_user( 'family', '0008' ); $other_family_user = comm_user( 'family', '0009' );
$teacher_user = comm_user( 'employee', 'T-001' ); $teacher2_user = comm_user( 'employee', 'T-002' );
$admin_user = comm_user( 'employee', 'ADMIN-003' ); $other_user = comm_user( 'employee', 'OTHER-004' ); $mod_user = comm_user( 'employee', 'MOD-005' );
$wp_admin_user = wp_insert_user( array( 'user_login' => 'communications_wp_admin', 'user_pass' => wp_generate_password( 30 ), 'role' => 'administrator', 'display_name' => 'مدير النظام' ) );
foreach ( array( $teacher_user, $teacher2_user ) as $uid ) { get_user_by( 'id', $uid )->set_role( 'olama_teacher' ); }
foreach ( array( $family_user, $other_family_user, $teacher_user, $teacher2_user, $admin_user, $other_user, $mod_user ) as $uid ) {
    foreach ( array( 'use', 'chat', 'service_inbox' ) as $cap ) { get_user_by( 'id', $uid )->add_cap( 'olama_messages_' . $cap ); }
}
get_user_by( 'id', $admin_user )->add_cap( 'olama_messages_contact_teachers' );
get_user_by( 'id', $admin_user )->add_cap( 'olama_messages_manage_inboxes' );
get_user_by( 'id', $mod_user )->add_cap( 'olama_messages_moderate' );
get_user_by( 'id', $mod_user )->add_cap( 'olama_messages_audit' );
function chat_as( $uid ) { wp_set_current_user( $uid ); return ( new Olama_Messages_Actor_Resolver() )->resolve( '', true ); }
function chat_table( $suffix ) { return Olama_Messages_Communications_DB::table( $suffix ); }
function chat_send_data( $body = 'رسالة تجريبية' ) { return array( 'body' => $body, 'client_message_id' => wp_generate_uuid4() ); }
$wpdb->query( "CREATE TABLE {$wpdb->prefix}olama_sections (id int PRIMARY KEY,academic_year_id int,grade_id int,core_grade_id varchar(50),core_section_id varchar(50),core_study_year varchar(20),section_name varchar(50)) ENGINE=InnoDB" );
$wpdb->query( "CREATE TABLE {$wpdb->prefix}olama_subjects (id int PRIMARY KEY,grade_id int,subject_name varchar(100),is_active int,core_study_year varchar(20),core_grade_id varchar(50),core_subject_id varchar(50)) ENGINE=InnoDB" );
$wpdb->query( "CREATE TABLE {$wpdb->prefix}fixture_academic_grade_subjects (id int PRIMARY KEY,study_year varchar(20),grade_id varchar(50),subject_id varchar(50),is_active int,last_synced_at datetime) ENGINE=InnoDB" );
$wpdb->query( "CREATE TABLE {$wpdb->prefix}fixture_academic_grade_sections (id int PRIMARY KEY,study_year varchar(20),grade_id varchar(50),section_id varchar(50),last_synced_at datetime) ENGINE=InnoDB" );
$wpdb->insert( $core->table( 'academic_grade_subjects' ), array( 'id' => 1, 'study_year' => '2026-2027', 'grade_id' => '04', 'subject_id' => 'SCI', 'is_active' => 1, 'last_synced_at' => $now ) );
$wpdb->insert( $core->table( 'academic_grade_sections' ), array( 'id' => 1, 'study_year' => '2026-2027', 'grade_id' => '04', 'section_id' => '02', 'last_synced_at' => $now ) );
$wpdb->query( "CREATE TABLE {$wpdb->prefix}olama_teacher_assignments (id int PRIMARY KEY,academic_year_id int,teacher_id bigint,teacher_employee_id varchar(50),grade_id int,section_id int,subject_id int) ENGINE=InnoDB" );
$wpdb->insert( $wpdb->prefix . 'olama_sections', array( 'id' => 12, 'academic_year_id' => 6, 'grade_id' => 4, 'core_grade_id' => '04', 'core_section_id' => '02', 'core_study_year' => '2026/2027', 'section_name' => 'ب' ) );
$wpdb->insert( $wpdb->prefix . 'olama_subjects', array( 'id' => 31, 'grade_id' => 4, 'subject_name' => 'العلوم', 'is_active' => 1, 'core_study_year' => '2026-2027', 'core_grade_id' => '04', 'core_subject_id' => 'SCI' ) );
$wpdb->insert( $wpdb->prefix . 'olama_teacher_assignments', array( 'id' => 81, 'academic_year_id' => 6, 'teacher_id' => $teacher_user, 'teacher_employee_id' => 'T-001', 'grade_id' => 4, 'section_id' => 12, 'subject_id' => 31 ) );
$chat = new Olama_Messages_Chat_Service(); $provider = new Olama_Messages_Relationship_Provider(); $inboxes = new Olama_Messages_Service_Inbox_Service(); $mod = new Olama_Messages_Moderation_Service();
$actor = chat_as( $family_user );
comm_assert( ! Olama_Messages_Communication_Policy::settings()['chat_enabled'], 'chat ships disabled' );
comm_throws( function () use ( $chat, $actor ) { $chat->listing( $actor ); }, 'disabled chat denies operations' );
update_option( 'olama_msg_communications', array( 'enabled' => true, 'chat_enabled' => true ) );
Olama_Messages_Communications_DB::install(); Olama_Messages_Communications_DB::install();
comm_assert( ! Olama_Messages_Communications_DB::health( true ), 'Release B dbDelta is repeatable and healthy' );
$children = $provider->contacts( $actor );
comm_assert( 1 === count( $children['children'] ) && ! $children['contacts'], 'family directory is child-first with no global staff list' );
comm_assert( array( 'teachers' ) === array_column( $children['groups'], 'key' ), 'family recipient directory exposes only the assigned-teacher group' );
$contacts = $provider->contacts( $actor, 'S-F-A' );
comm_assert( 1 === count( $contacts['contacts'] ) && 'employee:T-001' === $contacts['contacts'][0]['actor_key'], 'read-only adapter maps student and subject to canonical assigned employee' );
comm_assert( 1 === count( $provider->contacts( $actor, 'S-F-A', 0, '', 'teachers' )['contacts'] ), 'family can browse the selected teacher group without entering a search term' );
comm_assert( ! $provider->contacts( $actor, 'S-F-A', 0, '', 'families' )['contacts'], 'family cannot select a directory group outside its relationship scope' );
comm_assert( 1 === count( $provider->contacts( $actor, 'S-F-A', 0, 'العلوم' )['contacts'] ), 'family recipient search matches an assigned teacher by subject' );
comm_assert( ! $provider->contacts( $actor, 'S-F-A', 0, 'ا' )['contacts'], 'recipient search requires at least two characters' );
comm_assert( ! $provider->contacts( $actor, 'S-F-B' )['contacts'], 'another family child cannot reveal contacts' );
$context = $contacts['contacts'][0]['context'];
comm_assert( 'valid' === $provider->relationship( 'family:F-A', 'employee:T-001', $context )['relationship_status'], 'current academic relationship valid' );
$wpdb->update( $core->table( 'academic_grade_subjects' ), array( 'is_active' => 0 ), array( 'id' => 1 ) );
comm_assert( 'invalid' === $provider->relationship( 'family:F-A', 'employee:T-001', $context )['relationship_status'], 'Core subject removal overrides stale active School mirror' );
$wpdb->update( $core->table( 'academic_grade_subjects' ), array( 'is_active' => 1 ), array( 'id' => 1 ) );
$wpdb->update( $core->table( 'academic_grade_sections' ), array( 'last_synced_at' => '2020-01-01 00:00:00' ), array( 'id' => 1 ) );
comm_assert( 'stale' === $provider->relationship( 'family:F-A', 'employee:T-001', $context )['relationship_status'], 'academic mapping freshness is independently checked' );
$wpdb->update( $core->table( 'academic_grade_sections' ), array( 'last_synced_at' => $now ), array( 'id' => 1 ) );
$core->children['F-A'][0]['last_synced_at'] = null;
comm_assert( 'unknown' === $provider->relationship( 'family:F-A', 'employee:T-001', $context )['relationship_status'], 'missing sync timestamp fails closed as unknown' );
$core->children['F-A'][0]['last_synced_at'] = $now;
$wpdb->update( $wpdb->prefix . 'olama_teacher_assignments', array( 'teacher_employee_id' => 'missing' ), array( 'id' => 81 ) );
comm_assert( 'invalid' === $provider->relationship( 'family:F-A', 'employee:T-001', $context )['relationship_status'], 'mismatched canonical assignment mapping denied' );
$wpdb->update( $wpdb->prefix . 'olama_teacher_assignments', array( 'teacher_employee_id' => 'T-001' ), array( 'id' => 81 ) );
comm_assert( 'invalid' === $provider->relationship( 'family:F-A', 'employee:T-002', $context )['relationship_status'], 'unassigned teacher denied' );
comm_assert( 'mapping_missing' === $provider->relationship( 'family:F-A', 'family:F-B', $context )['relationship_status'], 'family-to-family relationship denied' );
comm_assert( 'valid' === $provider->relationship( 'employee:T-001', 'employee:T-002' )['relationship_status'], 'verified teacher-to-teacher relationship permitted' );
comm_assert( 'valid' === $provider->relationship( 'employee:ADMIN-003', 'employee:T-001' )['relationship_status'], 'administrative employee capability permits teacher contact' );
comm_assert( 'invalid' === $provider->relationship( 'employee:OTHER-004', 'employee:T-001' )['relationship_status'], 'ordinary employee cannot inherit administrative contact privilege' );
$wp_admin = chat_as( $wp_admin_user );
comm_assert( 'administrator:' . $wp_admin_user === $wp_admin['actor_key'] && Olama_Messages_Communication_Policy::can( 'olama_messages_chat' ), 'WordPress administrator receives an audited actor and every Communications capability without an OLAMA identity' );
$admin_contacts = $provider->contacts( $wp_admin );
comm_assert( in_array( 'family:F-B', array_column( $admin_contacts['contacts'], 'actor_key' ), true ) && in_array( 'employee:T-001', array_column( $admin_contacts['contacts'], 'actor_key' ), true ), 'administrator directory includes eligible families and employees regardless of role or academic relationship' );
comm_assert( array( 'all', 'administrators', 'teachers', 'employees', 'families' ) === array_column( $admin_contacts['groups'], 'key' ), 'administrator recipient directory is organized into role groups' );
$admin_teachers = $provider->contacts( $wp_admin, '', 0, '', 'teachers' )['contacts'];
comm_assert( $admin_teachers && array( 'teachers' ) === array_values( array_unique( array_column( $admin_teachers, 'group' ) ) ), 'administrator can browse a role group and receives only teachers' );
comm_assert( array( 'employee:T-001' ) === array_column( $provider->contacts( $wp_admin, '', 0, 'T-001' )['contacts'], 'actor_key' ), 'administrator AJAX search returns only the matching authorized recipient' );
$admin_thread = $chat->create( $wp_admin, array( 'target' => 'employee:OTHER-004' ) )['id'];
$admin_message = $chat->send( $wp_admin, $admin_thread, chat_send_data( 'رسالة إدارية مباشرة' ) );
comm_assert( ! empty( $admin_message['id'] ), 'administrator can start and send an unrestricted direct conversation to an eligible user' );
$actor = chat_as( $family_user );
comm_throws( function () use ( $chat, $actor ) { $chat->create( $actor, array( 'kind' => 'group' ) ); }, 'arbitrary group creation denied' );
$thread_id = $chat->create( $actor, array( 'target' => 'employee:T-001', 'context' => $context ) )['id'];
comm_assert( $thread_id === $chat->create( $actor, array( 'target' => 'employee:T-001', 'context' => $context ) )['id'], 'direct context uniqueness reuses only same participants and assignment' );
$data = chat_send_data( '<script>alert("literal")</script> مرحباً' ); $sent = $chat->send( $actor, $thread_id, $data ); $message_id = $sent['id'];
comm_assert( $chat->send( $actor, $thread_id, $data )['duplicate'], 'client UUID retry commits one message' );
comm_throws( function () use ( $chat, $actor, $thread_id ) { $chat->send( $actor, $thread_id, chat_send_data( str_repeat( 'أ', 5001 ) ) ); }, 'message length is limited by Unicode characters' );
comm_throws( function () use ( $chat, $actor, $thread_id, $data ) { $data['body'] = 'different'; $chat->send( $actor, $thread_id, $data ); }, 'UUID reuse with changed payload conflicts' );
comm_throws( function () use ( $chat, $actor, $thread_id ) { $data = chat_send_data(); $data['attachments'] = array( 1 ); $chat->send( $actor, $thread_id, $data ); }, 'private attachments cannot bypass Release C gate' );
$family_view = $chat->conversation( $actor, $thread_id );
comm_assert( '<script>alert("literal")</script> مرحباً' === $family_view['messages'][0]['body'], 'message body remains plain text for safe DOM rendering' );
comm_assert( 0 === (int) $family_view['receipts'][0]['delivered_cursor'] && 0 === (int) $family_view['receipts'][0]['read_cursor'], 'committing a message does not invent delivery or read' );
$other_actor = chat_as( $other_family_user );
comm_throws( function () use ( $chat, $other_actor, $thread_id ) { $chat->conversation( $other_actor, $thread_id ); }, 'unrelated family cannot read private thread' );
comm_assert( ! $chat->listing( $other_actor ) && ! $chat->feed( $other_actor )['changes'], 'thread list and change feed are actor-scoped' );
$teacher = chat_as( $teacher_user );
$assigned_families = $provider->teacher_contacts( $teacher );
comm_assert( 2 === count( $assigned_families['contacts'] ), 'teacher directory includes only current assigned student families' );
comm_assert( 2 === count( $provider->teacher_contacts( $teacher, '', 0, '', 'families' )['contacts'] ), 'teacher can browse the assigned-family group without entering a search term' );
comm_assert( array( 'family:F-A' ) === array_column( $provider->teacher_contacts( $teacher, '', 0, 'طالب F-A' )['contacts'], 'actor_key' ), 'teacher AJAX search filters current assigned families' );
comm_assert( $thread_id === $chat->create( $teacher, array( 'target' => 'family:F-A', 'context' => $context ) )['id'], 'teacher can initiate same authorized family thread bidirectionally' );
comm_assert( 1 === $chat->feed( $teacher )['unread'], 'authorized recipient has unread count' );
$chat->receipt( $teacher, $thread_id, $message_id, 'delivered' );
$actor = chat_as( $family_user ); $receipts = $chat->conversation( $actor, $thread_id )['receipts'][0];
comm_assert( $message_id === (int) $receipts['delivered_cursor'] && 0 === (int) $receipts['read_cursor'], 'client delivery acknowledgement remains independent of read' );
$teacher = chat_as( $teacher_user ); $chat->receipt( $teacher, $thread_id, $message_id, 'read' );
$chat->preferences( $teacher, $thread_id, array( 'manual_unread' => true ) );
$state = $chat->conversation( $teacher, $thread_id )['personal'];
comm_assert( (int) $state['read_cursor'] === $message_id && 1 === (int) $state['manual_unread'], 'manual unread marker never reverses read receipt' );
$feed_cursor = $chat->feed( $teacher )['cursor'];
$actor = chat_as( $family_user ); $chat->edit( $actor, $message_id, array( 'body' => 'تم تعديل الرسالة' ) );
comm_assert( $chat->send( $actor, $thread_id, $data )['duplicate'], 'original request remains idempotent after message was edited' );
comm_assert( 1 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . chat_table( 'message_revisions' ) ), 'editing retains original revision' );
$teacher = chat_as( $teacher_user );
comm_assert( in_array( 'edit', array_column( $chat->feed( $teacher, $feed_cursor )['changes'], 'kind' ), true ), 'change sequence surfaces edits without new message ID' );
comm_throws( function () use ( $chat, $teacher, $message_id ) { $chat->edit( $teacher, $message_id, array( 'body' => 'no' ) ); }, 'cannot edit another actor message' );
$reply = chat_send_data( 'رد المعلم' ); $reply['reply_to'] = $message_id; $reply_id = $chat->send( $teacher, $thread_id, $reply )['id'];
$actor = chat_as( $family_user );
$wpdb->update( chat_table( 'messages' ), array( 'sent_at_utc' => '2020-01-01 00:00:00' ), array( 'id' => $message_id ) );
comm_throws( function () use ( $chat, $actor, $message_id ) { $chat->edit( $actor, $message_id, array( 'body' => 'متأخر' ) ); }, 'edit window expiry enforced by server clock' );
$wpdb->update( chat_table( 'messages' ), array( 'sent_at_utc' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $message_id ) );
$chat->receipt( $actor, $thread_id, $reply_id, 'read' ); $chat->receipt( $actor, $thread_id, $message_id, 'read' );
comm_assert( $reply_id === (int) $chat->conversation( $actor, $thread_id )['personal']['read_cursor'], 'read cursor is monotonic for out-of-order acknowledgements' );
comm_throws( function () use ( $chat, $actor, $thread_id ) { $chat->receipt( $actor, $thread_id, 999999, 'read' ); }, 'out-of-thread receipt rejected' );
$core->children['F-A'][0]['last_synced_at'] = '2020-01-01 00:00:00';
comm_assert( 'stale' === $provider->relationship( 'family:F-A', 'employee:T-001', $context )['relationship_status'], 'staleness is source-specific and explicit' );
comm_throws( function () use ( $chat, $actor, $thread_id ) { $chat->send( $actor, $thread_id, chat_send_data() ); }, 'stale relationship denies each new send' );
comm_assert( 2 === count( $chat->conversation( $actor, $thread_id )['messages'] ), 'stale relationship preserves authorized history' );
$core->children['F-A'][0]['last_synced_at'] = $now;
$wpdb->update( $wpdb->prefix . 'olama_teacher_assignments', array( 'teacher_employee_id' => 'T-002', 'teacher_id' => $teacher2_user ), array( 'id' => 81 ) );
comm_assert( ! $chat->conversation( $actor, $thread_id )['send_state']['allowed'], 'teacher reassignment makes old conversation read-only' );
$new_id = $chat->create( $actor, array( 'target' => 'employee:T-002', 'context' => $context ) )['id'];
comm_assert( $new_id !== $thread_id, 'successor teacher gets a distinct empty thread' );
$teacher2 = chat_as( $teacher2_user );
comm_throws( function () use ( $chat, $teacher2, $thread_id ) { $chat->conversation( $teacher2, $thread_id ); }, 'successor does not inherit predecessor private history' );
comm_throws( function () use ( $chat, $teacher2, $new_id, $message_id ) { $payload = chat_send_data(); $payload['reply_to'] = $message_id; $chat->send( $teacher2, $new_id, $payload ); }, 'quote cannot reach into another private thread' );
$wpdb->update( $wpdb->prefix . 'olama_teacher_assignments', array( 'teacher_employee_id' => 'T-001', 'teacher_id' => $teacher_user ), array( 'id' => 81 ) );
$core->enrolled = false; $actor = chat_as( $family_user );
comm_assert( 2 === count( $chat->conversation( $actor, $thread_id )['messages'] ), 'active family identity retains history after enrollment withdrawal' );
comm_throws( function () use ( $chat, $actor, $thread_id ) { $chat->send( $actor, $thread_id, chat_send_data() ); }, 'withdrawn family cannot send using historical context' ); $core->enrolled = true;

$report_id = $mod->report( $actor, $reply_id, 'بلاغ تجريبي' )['id'];
comm_assert( $report_id === $mod->report( $actor, $reply_id, 'تكرار' )['id'], 'report retries do not duplicate the queue' );
get_user_by( 'id', $family_user )->add_cap( 'olama_messages_moderate' );
comm_throws( function () use ( $mod, $actor ) { $mod->queue( $actor ); }, 'family actor cannot borrow moderation capability' );
$admin = chat_as( $admin_user );
comm_throws( function () use ( $mod, $admin, $thread_id ) { $mod->context( $admin, $thread_id, 0, 'just admin' ); }, 'ordinary administrator metadata access does not grant private content' );
$moderator = chat_as( $mod_user );
$mod->context( $moderator, 0, $report_id, 'مراجعة البلاغ' );
comm_assert( 1 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . chat_table( 'audit_log' ) . " WHERE action='privileged_content_access'" ), 'privileged context access records an audit event' );
wp_get_current_user()->remove_cap( 'olama_messages_audit' );
comm_throws( function () use ( $mod, $moderator, $new_id ) { $mod->context( $moderator, $new_id, 0, 'دون بلاغ' ); }, 'moderator without audit cannot browse an unreported arbitrary thread' );
wp_get_current_user()->add_cap( 'olama_messages_audit' );
$mod->redact( $moderator, $message_id, array( 'report_id' => $report_id, 'reason' => 'حجب تجريبي' ) );
$mod->review( $moderator, $report_id, array( 'status' => 'actioned', 'note' => 'تمت المعالجة' ) );
comm_assert( 1 === count( $mod->queue( $moderator, 0, 'actioned' ) ), 'moderation queue supports actioned state' );
$restriction = $mod->restrict( $moderator, array( 'actor_key' => 'family:F-A', 'restriction_type' => 'send_block', 'scope_type' => 'thread', 'scope_key' => (string) $thread_id, 'private_reason' => 'سبب خاص' ) )['id'];
$actor = chat_as( $family_user ); $view = $chat->conversation( $actor, $thread_id );
comm_assert( 'تم حجب الرسالة' === $view['messages'][0]['body'] && 'تم حجب الرسالة المقتبسة' === $view['messages'][1]['quote_body'], 'redaction removes live text and quoted previews' );
comm_assert( false === strpos( wp_json_encode( $view ), 'سبب خاص' ), 'restriction reason never leaks to ordinary conversation' );
comm_throws( function () use ( $chat, $actor, $thread_id ) { $chat->send( $actor, $thread_id, chat_send_data() ); }, 'thread-scoped send restriction enforced' );
$moderator = chat_as( $mod_user ); $mod->revoke( $moderator, $restriction );
$expired = $mod->restrict( $moderator, array( 'actor_key' => 'family:F-A', 'restriction_type' => 'chat_block', 'scope_type' => 'global', 'starts_at_utc' => '2020-01-01 00:00:00', 'ends_at_utc' => '2020-01-02 00:00:00', 'private_reason' => 'منتهٍ' ) );
$actor = chat_as( $family_user ); comm_assert( $chat->send( $actor, $thread_id, chat_send_data() )['id'] > 0, 'expired restrictions stop applying without a cron job' );
$moderator = chat_as( $mod_user );
$global_restriction = $mod->restrict( $moderator, array( 'actor_key' => 'family:F-A', 'restriction_type' => 'chat_block', 'scope_type' => 'global', 'private_reason' => 'chat only' ) )['id'];
$actor = chat_as( $family_user );
Olama_Messages_Communication_Policy::require_use( $actor );
comm_assert( is_array( ( new Olama_Messages_Notification_Service() )->notices( $actor ) ), 'chat block leaves official notices accessible' );
comm_assert( count( $chat->conversation( $actor, $thread_id )['messages'] ) > 0, 'chat block preserves authorized message history' );
$moderator = chat_as( $mod_user ); $mod->revoke( $moderator, $global_restriction );

$admin = chat_as( $admin_user );
$inbox_data = array( 'name' => 'شؤون الطلبة', 'active' => true, 'allow_family' => true, 'allow_teacher' => true, 'history_policy' => 'active_and_recent', 'recent_days' => 30, 'members' => array( array( 'actor_key' => 'employee:T-001', 'active' => true ), array( 'actor_key' => 'employee:ADMIN-003', 'active' => true, 'is_manager' => true ) ) );
$inbox_id = $inboxes->save( $admin, $inbox_data )['id'];
$actor = chat_as( $family_user );
$service_data = array( 'kind' => 'service', 'inbox_id' => $inbox_id, 'subject' => 'طلب خدمة تجريبي', 'client_thread_id' => wp_generate_uuid4() );
$service_id = $chat->create( $actor, $service_data )['id'];
comm_assert( $service_id === $chat->create( $actor, $service_data )['id'], 'service creation retry is idempotent' );
comm_throws( function () use ( $chat, $actor, $service_data ) { $changed = $service_data; $changed['subject'] = 'changed'; $chat->create( $actor, $changed ); }, 'service UUID cannot be reused with another subject' );
$service_data['client_thread_id'] = wp_generate_uuid4();
comm_assert( $service_id !== $chat->create( $actor, $service_data )['id'], 'same family can open a later distinct service request' );
$service_message = $chat->send( $actor, $service_id, chat_send_data( 'طلب من الأسرة' ) )['id'];
$teacher = chat_as( $teacher_user ); $chat->receipt( $teacher, $service_id, $service_message, 'delivered' );
comm_assert( ! $chat->conversation( $teacher, $service_id )['thread']['department_viewed_at_utc'], 'personal delivery does not mean department viewed request' );
$chat->receipt( $teacher, $service_id, $service_message, 'read' );
comm_assert( (bool) $chat->conversation( $teacher, $service_id )['thread']['department_viewed_at_utc'], 'visible member read records independent department first-view' );
$chat->send( $teacher, $service_id, chat_send_data( 'رد القسم' ) );
comm_assert( 'employee:T-001' === $chat->get( $teacher, $service_id )['assignee_key'], 'first department reply atomically claims an unassigned request' );
$actor = chat_as( $family_user ); $service_view = $chat->conversation( $actor, $service_id );
comm_assert( 'شؤون الطلبة' === $service_view['messages'][1]['display_name'] && ! $service_view['receipts'] && ! isset( $service_view['thread']['assignee_key'] ), 'family sees department response without hidden employee IDs or everyone-read claims' );
$other = chat_as( $other_user );
comm_throws( function () use ( $provider, $other ) { $provider->teacher_contacts( $other ); }, 'ordinary employee cannot enumerate teacher family directory' );
comm_throws( function () use ( $chat, $other, $service_id ) { $chat->conversation( $other, $service_id ); }, 'nonmember employee cannot open shared inbox' );
$teacher = chat_as( $teacher_user ); $inboxes->action( $teacher, $service_id, 'resolve' );
comm_assert( 'resolved' === $chat->get( $teacher, $service_id )['status'], 'assigned employee can resolve and retain configured history' );
$admin = chat_as( $admin_user );
$inbox_data['history_policy'] = 'manager_history_only'; $inboxes->save( $admin, $inbox_data, $inbox_id );
$teacher = chat_as( $teacher_user );
comm_throws( function () use ( $chat, $teacher, $service_id ) { $chat->conversation( $teacher, $service_id ); }, 'manager-only history removes resolved access from ordinary member' );
comm_assert( ! in_array( $service_id, array_column( $chat->listing( $teacher ), 'id' ) ), 'listing enforces same history policy as content' );
comm_assert( ! in_array( $service_id, array_column( $chat->feed( $teacher )['changes'], 'thread_id' ) ), 'change feed hides inaccessible historical requests' );
$admin = chat_as( $admin_user ); $inboxes->action( $admin, $service_id, 'reopen' );
$teacher = chat_as( $teacher_user ); comm_assert( 'open' === $chat->get( $teacher, $service_id )['status'], 'manager reopen restores normal active membership access' );
$admin = chat_as( $admin_user ); $inbox_data['members'] = array( $inbox_data['members'][1] ); $inboxes->save( $admin, $inbox_data, $inbox_id );
$teacher = chat_as( $teacher_user );
comm_throws( function () use ( $chat, $teacher, $service_id ) { $chat->conversation( $teacher, $service_id ); }, 'removed author loses service access immediately' );
comm_throws( function () use ( $chat, $teacher, $service_id, $service_message ) { $chat->receipt( $teacher, $service_id, $service_message, 'read' ); }, 'removed member cannot acknowledge service receipts' );
comm_throws( function () use ( $chat, $teacher, $service_id ) { $chat->send( $teacher, $service_id, chat_send_data() ); }, 'removed member cannot send using an old open browser' );
$actor = chat_as( $family_user ); comm_assert( 2 === count( $chat->conversation( $actor, $service_id )['messages'] ), 'removing employee preserves authored replies for authorized requester' );

// All history policies use the same content/list/feed access expression.
$admin = chat_as( $admin_user );
$inbox_data['members'][] = array( 'actor_key' => 'employee:T-001', 'active' => true );
$inbox_data['history_policy'] = 'active_and_recent'; $inboxes->save( $admin, $inbox_data, $inbox_id );
$inboxes->action( $admin, $service_id, 'assign', '' ); $inboxes->action( $admin, $service_id, 'resolve' );
$teacher = chat_as( $teacher_user );
comm_assert( 'resolved' === $chat->get( $teacher, $service_id )['status'], 'recent participating member retains unassigned resolved history' );
$wpdb->update( chat_table( 'threads' ), array( 'resolved_at_utc' => '2020-01-01 00:00:00' ), array( 'id' => $service_id ) );
comm_throws( function () use ( $chat, $teacher, $service_id ) { $chat->get( $teacher, $service_id ); }, 'recent participation history expires at configured window' );
$admin = chat_as( $admin_user ); $inbox_data['history_policy'] = 'all_history'; $inboxes->save( $admin, $inbox_data, $inbox_id );
$teacher = chat_as( $teacher_user ); comm_assert( 'resolved' === $chat->get( $teacher, $service_id )['status'], 'all-history policy permits old resolved requests to active members' );
$admin = chat_as( $admin_user ); $inbox_data['history_policy'] = 'active_only'; $inboxes->save( $admin, $inbox_data, $inbox_id );
$teacher = chat_as( $teacher_user );
comm_throws( function () use ( $chat, $teacher, $service_id ) { $chat->get( $teacher, $service_id ); }, 'active-only policy denies unassigned resolved requests' );

$actor = chat_as( $family_user );
$chat->preferences( $actor, $new_id, array( 'pinned' => true ) );
comm_assert( $new_id === (int) $chat->listing( $actor )[0]['id'], 'pinned conversation sorts ahead of recent messages' );
$first = $chat->listing( $actor )[0];
$following = $chat->listing( $actor, 0, false, 0, $first['page_cursor'] );
comm_assert( ! in_array( $new_id, array_map( 'intval', array_column( $following, 'id' ) ), true ) && count( $following ) > 0, 'composite keyset continues after pinned cursor without duplicates' );
comm_throws( function () use ( $chat, $actor ) { $chat->listing( $actor, 0, false, 0, 'malformed' ); }, 'malformed keyset cursor rejected' );
$chat->preferences( $actor, $thread_id, array( 'muted' => true ) );
$mute_cursor = $chat->feed( $actor )['cursor'];
$teacher = chat_as( $teacher_user ); $chat->send( $teacher, $thread_id, chat_send_data( 'muted notification test' ) );
$actor = chat_as( $family_user );
$muted_feed = $chat->feed( $actor, $mute_cursor );
comm_assert( 0 === array_sum( array_column( $muted_feed['changes'], 'notify' ) ) && $muted_feed['unread'] > 0, 'mute suppresses alert hints while preserving unread count' );

// Meaningful bounds and repair checks.
$limit_filter = function () { return 1; }; add_filter( 'olama_messages_message_rate_limit', $limit_filter );
$wpdb->query( 'DELETE FROM ' . chat_table( 'chat_rate_limits' ) );
$chat->send( $actor, $thread_id, chat_send_data() );
comm_throws( function () use ( $chat, $actor, $thread_id ) { $chat->send( $actor, $thread_id, chat_send_data() ); }, 'canonical actor rate limit enforces repeated sends' );
remove_filter( 'olama_messages_message_rate_limit', $limit_filter );
$wpdb->query( 'UPDATE ' . chat_table( 'thread_participants' ) . ' SET unread_count=999' ); Olama_Messages_Chat_Service::reconcile();
comm_assert( 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . chat_table( 'thread_participants' ) . ' WHERE unread_count=999' ), 'bounded reconciliation repairs cached unread counters' );
update_user_meta( $family_user, 'olama_account_status', 'suspended' );
comm_throws( function () use ( $chat, $actor, $thread_id ) { $chat->conversation( $actor, $thread_id ); }, 'account suspension overrides historical access' ); delete_user_meta( $family_user, 'olama_account_status' );
$controller = new Olama_Messages_Chat_Rest_Controller();
$request = new WP_REST_Request( 'GET', '/olama-messages/v1/communications/chat/threads' );
comm_assert( is_wp_error( $controller->permission( $request ) ), 'chat REST requires nonce' );
$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) ); $request->set_header( 'X-Olama-Actor', 'employee:T-001' );
comm_assert( is_wp_error( $controller->permission( $request ) ), 'chat REST rejects spoofed selected actor' );
$request->set_header( 'X-Olama-Actor', 'family:F-A' );
comm_assert( true === $controller->permission( $request ), 'chat REST accepts verified actor and nonce' );
$response = $controller->dispatch( $request );
comm_assert( $response instanceof WP_REST_Response && 'private, no-store' === $response->get_headers()['Cache-Control'], 'chat REST responses prohibit shared caching' );
comm_assert( 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . chat_table( 'queue' ) ), 'Release B sends zero SMS queue entries' );
echo "Release B integration suite passed. Disposable database removed on exit.\n";
