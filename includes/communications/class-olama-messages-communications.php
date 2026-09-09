<?php
/** Release A integration boundary; legacy orchestrator remains small. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Olama_Messages_Communications {
    public function init() {
        add_action( 'init', array( $this, 'install' ), 15 );
        add_action( 'olama_users_register_modules', array( $this, 'register_module' ) );
        add_action( 'rest_api_init', array( new Olama_Messages_Communications_Rest_Controller(), 'register_routes' ) );
        add_action( 'rest_api_init', array( new Olama_Messages_Chat_Rest_Controller(), 'register_routes' ) );
        add_action( 'rest_api_init', array( new Olama_Messages_Suite_Rest_Controller(), 'register_routes' ) );
        add_action( 'olama_msg_communications_tick', array( $this, 'tick' ) );
        add_action( 'admin_menu', array( $this, 'menu' ), 40 );
        add_action( 'admin_post_olama_communications_settings', array( $this, 'save_settings' ) );
        add_shortcode( 'olama_communications', array( $this, 'app' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
        add_filter( 'olama_student_gateway_messages_data', array( $this, 'gateway_data' ), 10, 2 );
        add_action( 'olama_student_gateway_render_messages', array( $this, 'gateway_render' ) );
    }

    public function install() {
        $version_mismatch = Olama_Messages_Communications_DB::VERSION !== get_option( 'olama_msg_communications_db_version' );
        $schema_errors = $version_mismatch ? array() : Olama_Messages_Communications_DB::health();
        if ( $version_mismatch || $schema_errors ) {
            // A bounded repair also recovers from an interrupted migration or a missed
            // schema-version bump. Persistent failures are retried at most every 5 minutes.
            $retry_key = 'olama_msg_schema_repair_attempt';
            if ( $version_mismatch || ! get_transient( $retry_key ) ) {
                set_transient( $retry_key, 1, 5 * MINUTE_IN_SECONDS );
                Olama_Messages_Communications_DB::install();
                if ( ! Olama_Messages_Communications_DB::health( true ) ) { delete_transient( $retry_key ); }
            }
        }
        if ( ! wp_next_scheduled( 'olama_msg_communications_tick' ) ) { wp_schedule_event( time() + 60, 'olama_msg_every_minute', 'olama_msg_communications_tick' ); }
    }

    public function tick() {
        ( new Olama_Messages_Job_Service() )->run( array( 'Olama_Messages_Suite_Operations', 'handle_job' ) );
        Olama_Messages_Chat_Service::reconcile();
        Olama_Messages_Suite_Operations::maintenance();
        if ( Olama_Messages_Communications_DB::VERSION !== get_option( 'olama_msg_communications_db_version' ) ) { return; }
        global $wpdb;
        $c = Olama_Messages_Communications_DB::table( 'campaigns' );
        $j = Olama_Messages_Communications_DB::table( 'jobs' );
        $m = Olama_Messages_Communications_DB::table( 'internal_campaigns' );
        // Failed preparation is visible and cannot accidentally publish.
        Olama_Messages_Communications_DB::query( "UPDATE {$c} c INNER JOIN {$m} m ON m.campaign_id=c.id SET c.status='preparation_failed' WHERE c.channel='internal' AND c.status='preparing' AND EXISTS (SELECT 1 FROM {$j} j WHERE j.object_id=c.id AND j.job_type='prepare' AND j.status='failed' AND j.job_key=CONCAT('prepare:',m.snapshot_id,':',m.cursor_offset))" );
    }

    public function register_module() {
        if ( ! function_exists( 'olama_users_register_module' ) ) { return; }
        $items = array();
        foreach ( array( 'use' => 'استخدام الاتصالات', 'manage_campaigns' => 'إدارة الإعلانات الرسمية', 'configure' => 'إعدادات الاتصالات', 'chat' => 'المراسلات الخاصة', 'contact_teachers' => 'مراسلة المعلمين إدارياً', 'service_inbox' => 'عضوية صناديق الخدمة', 'manage_inboxes' => 'إدارة صناديق الخدمة', 'moderate' => 'مراجعة البلاغات والقيود', 'audit' => 'تدقيق المحتوى الخاص', 'attachments' => 'المرفقات الخاصة', 'actions' => 'الإجراءات المطلوبة', 'manage_actions' => 'إدارة الإجراءات', 'events' => 'التقويم والفعاليات', 'manage_events' => 'إدارة الفعاليات', 'view_dashboard' => 'مؤشرات الاتصالات' ) as $key => $label ) {
            $items[] = array( 'id' => 'communications.' . $key, 'type' => 'action', 'label' => $label, 'capability' => 'olama_messages_' . $key );
        }
        olama_users_register_module( array( 'id' => 'olama-communications', 'plugin' => 'olama-messages', 'label' => 'OLAMA Communications', 'capability' => 'olama_messages_use', 'items' => $items ) );
    }

    public function menu() {
        add_menu_page( 'OLAMA Communications', 'OLAMA Communications', 'olama_messages_use', 'olama-communications', array( $this, 'admin_page' ), 'dashicons-megaphone', 31 );
        add_submenu_page( 'olama-communications', 'إعدادات الاتصالات', 'إعدادات الاتصالات', 'olama_messages_configure', 'olama-communications-settings', array( $this, 'settings_page' ) );
    }

    public function admin_page() { echo '<div class="wrap">' . $this->app() . '</div>'; }

    public function app() {
        if ( ! is_user_logged_in() ) { return '<p dir="rtl">يرجى تسجيل الدخول إلى حساب OLAMA.</p>'; }
        if ( ! current_user_can( 'olama_messages_use' ) ) { return '<p dir="rtl">لا يملك هذا الحساب صلاحية استخدام OLAMA Communications.</p>'; }
        $settings = Olama_Messages_Communication_Policy::settings();
        if ( empty( $settings['enabled'] ) ) {
            $message = '<section class="notice notice-warning inline" dir="rtl"><h2>الاتصالات غير مفعلة</h2><p>يلزم تفعيل «الاتصالات الداخلية» من إعدادات OLAMA Communications في هذا الموقع.</p>';
            if ( is_admin() && current_user_can( 'olama_messages_configure' ) ) {
                $message .= '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=olama-communications-settings' ) ) . '">فتح إعدادات الاتصالات</a></p>';
            }
            return $message . '</section>';
        }
        if ( ! empty( $settings['pilot_users'] ) && ! in_array( get_current_user_id(), array_map( 'intval', $settings['pilot_users'] ), true ) ) {
            return '<p dir="rtl">الاتصالات مفعلة لمجموعة التجربة، وهذا الحساب غير مضاف إليها.</p>';
        }
        $actors = ( new Olama_Messages_Actor_Resolver() )->available( get_current_user_id(), true );
        if ( ! $actors ) {
            $message = '<section class="notice notice-warning inline" dir="rtl"><h2>يلزم ربط هوية OLAMA</h2><p>الحساب مخول للاتصالات، لكن OLAMA Users لم يعد هوية أسرة أو موظف نشطة وموثقة له.</p>';
            if ( is_admin() && current_user_can( 'olama_users_accounts_view' ) ) {
                $message .= '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=olama-users' ) ) . '">فتح حسابات OLAMA Users</a></p>';
            }
            return $message . '</section>';
        }
        $this->enqueue( true );
        return '<section class="olama-communications" dir="rtl" data-olama-communications><p role="status">جارٍ تحميل إعلانات المدرسة…</p></section>';
    }

    public function enqueue( $force = false ) {
        if ( ! is_user_logged_in() || ! Olama_Messages_Communication_Policy::enabled() || ! current_user_can( 'olama_messages_use' ) ) { return; }
        global $post;
        $is_olama = is_admin() ? false !== strpos( (string) ( $_GET['page'] ?? '' ), 'olama' ) : ( $post && preg_match( '/\[olama_/', $post->post_content ) );
        if ( true !== $force && ! apply_filters( 'olama_messages_is_portal_page', $is_olama ) ) { return; }
        $actors = ( new Olama_Messages_Actor_Resolver() )->available( get_current_user_id(), true );
        if ( ! $actors ) { return; }
        wp_enqueue_style( 'olama-communications', OLAMA_MSG_URL . 'assets/communications-app.css', array(), OLAMA_MSG_VERSION );
        wp_enqueue_script( 'olama-communications', OLAMA_MSG_URL . 'assets/communications-app.js', array(), OLAMA_MSG_VERSION, true );
        wp_enqueue_script( 'olama-communications-chat', OLAMA_MSG_URL . 'assets/communications-chat.js', array( 'olama-communications' ), OLAMA_MSG_VERSION, true );
        wp_enqueue_script( 'olama-communications-suite', OLAMA_MSG_URL . 'assets/communications-suite.js', array( 'olama-communications-chat' ), OLAMA_MSG_VERSION, true );
        $settings = Olama_Messages_Communication_Policy::settings();
        wp_localize_script( 'olama-communications', 'OlamaCommunications', array(
            'root' => esc_url_raw( rest_url( 'olama-messages/v1/communications/' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ),
            'actors' => $actors, 'userId' => get_current_user_id(),
            'sessionScope' => substr( hash_hmac( 'sha256', wp_get_session_token(), wp_salt( 'auth' ) ), 0, 24 ),
            'timezone' => wp_timezone_string(),
            'notifications' => ! empty( $settings['notifications'] ), 'pollSeconds' => max( 10, min( 120, (int) $settings['poll_seconds'] ) ),
            'suite' => ! empty( $settings['events_enabled'] ) || ! empty( $settings['actions_enabled'] ) || ! empty( $settings['attachments_enabled'] ),
            'chat' => ! empty( $settings['chat_enabled'] ) && current_user_can( 'olama_messages_chat' ), 'messageMaxChars' => (int) $settings['message_max_chars'], 'editMinutes' => (int) $settings['edit_minutes'],
        ) );
    }

    public function gateway_data( $data, $context ) {
        try {
            $actor = ( new Olama_Messages_Actor_Resolver() )->resolve( 'family:' . ( $context['family_uid'] ?? '' ) );
            Olama_Messages_Communication_Policy::require_use( $actor );
            return array( 'counts' => ( new Olama_Messages_Notification_Service() )->counts( $actor ), 'available' => true );
        } catch ( Throwable $error ) { return $data; }
    }

    public function gateway_render() { echo $this->app(); }

    public static function health() {
        global $wpdb;
        $j = Olama_Messages_Communications_DB::table( 'jobs' );
        $n = Olama_Messages_Communications_DB::table( 'notifications' );
        $a = Olama_Messages_Communications_DB::table( 'audit_log' );
        $errors = Olama_Messages_Communications_DB::health( true );
        return array(
            'schema_errors' => $errors, 'identity_mode' => 'single_verified',
            'dependencies' => array( 'core' => function_exists( 'olama_core' ), 'users' => function_exists( 'olama_users_get_identity' ) ),
            'identity_note' => 'Multiple-identity resolution is not currently available from OLAMA Users.',
            'heartbeat_utc' => get_option( 'olama_msg_communications_heartbeat', null ),
            'last_identity_failure_utc' => get_option( 'olama_msg_communications_identity_error_at', null ),
            'jobs' => $errors ? array() : $wpdb->get_results( "SELECT job_type,status,COUNT(*) AS total,MIN(available_at_utc) AS oldest_due_at_utc FROM {$j} GROUP BY job_type,status", ARRAY_A ),
            'failed_jobs' => $errors ? array() : $wpdb->get_results( "SELECT id,job_type,object_id,attempt_count,last_error FROM {$j} WHERE status='failed' ORDER BY id DESC LIMIT 30", ARRAY_A ),
            'notification_delivery_pending' => $errors ? null : (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$n} WHERE delivered_at_utc IS NULL" ),
            'chat_enabled' => ! empty( Olama_Messages_Communication_Policy::settings()['chat_enabled'] ),
            'attachments' => ( new Olama_Messages_Attachment_Service() )->health(),
            'suite_maintenance_utc' => get_option( 'olama_msg_suite_maintenance_utc', null ),
            'storage_error' => get_option( 'olama_msg_storage_error', null ),
            'chat_reconciled_at_utc' => get_option( 'olama_msg_chat_reconciled_at', null ),
            'open_reports' => $errors ? null : (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Olama_Messages_Communications_DB::table( 'reports' ) . " WHERE status='open'" ),
            // General health exposes metadata only, never private moderation/access reasons.
            'audit' => $errors ? array() : $wpdb->get_results( "SELECT id,business_actor_key,authenticated_wp_user_id,action,object_id,created_at_utc FROM {$a} ORDER BY id DESC LIMIT 30", ARRAY_A ),
        );
    }

    public static function retry_job( $id, array $actor ) {
        global $wpdb;
        Olama_Messages_Communication_Policy::require_manage( $actor );
        return Olama_Messages_Communications_DB::transaction( function () use ( $wpdb, $id, $actor ) {
            $table = Olama_Messages_Communications_DB::table( 'jobs' );
            $changed = Olama_Messages_Communications_DB::query( $wpdb->prepare( "UPDATE {$table} SET status='pending',attempt_count=0,lock_token=NULL,available_at_utc=%s WHERE id=%d AND status='failed'", gmdate( 'Y-m-d H:i:s' ), $id ) );
            if ( ! $changed ) { throw new RuntimeException( 'المهمة ليست في حالة فشل.' ); }
            Olama_Messages_Communications_DB::audit( 'job_retried', $id, $actor );
            return array( 'ok' => true );
        } );
    }

    public function settings_page() {
        if ( ! current_user_can( 'olama_messages_configure' ) ) { return; }
        $settings = Olama_Messages_Communication_Policy::settings();
        echo '<div class="wrap" dir="rtl"><h1>إعدادات OLAMA Communications</h1><p>الإعلانات والمراسلات المقيدة. امنح الصلاحيات من OLAMA Users وأكمل فحوص بيئة المدرسة قبل التفعيل.</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'olama_communications_settings' );
        echo '<input type="hidden" name="action" value="olama_communications_settings">';
        foreach ( array( 'enabled' => 'تفعيل الاتصالات الداخلية', 'notifications' => 'تفعيل التنبيهات داخل صفحات OLAMA', 'chat_enabled' => 'المحادثات وصناديق الخدمة والرقابة', 'attachments_enabled' => 'المرفقات الخاصة', 'actions_enabled' => 'الإجراءات ومهل الخدمة والتصعيد', 'events_enabled' => 'الفعاليات والتقويم والتذكيرات', 'legacy_office_enabled' => 'ملفات Office القديمة (فحص نظيف إلزامي)', 'retention_archive_enabled' => 'السماح بالأرشفة اليدوية بعد معاينة سياسة الاحتفاظ' ) as $key => $label ) {
            echo '<p><label><input type="checkbox" name="' . esc_attr( $key ) . '" value="1" ' . checked( ! empty( $settings[$key] ), true, false ) . '> ' . esc_html( $label ) . '</label></p>';
        }
        echo '<p><label>حسابات التجربة (معرفات WordPress مفصولة بفواصل؛ فارغ لجميع المخولين)<br><input name="pilot_users" class="large-text" value="' . esc_attr( implode( ',', $settings['pilot_users'] ) ) . '"></label></p>';
        echo '<p><label>فاصل الاستطلاع بالثواني (10–120)<input type="number" name="poll_seconds" min="10" max="120" value="' . esc_attr( $settings['poll_seconds'] ) . '"></label></p>';
        foreach ( array( 'message_max_chars' => 'الحد الأقصى لأحرف الرسالة (1–5000)', 'edit_minutes' => 'مهلة التعديل بالدقائق (0–60)', 'student_context_max_age' => 'عمر مزامنة سياق الطالب بالثواني', 'employee_mapping_max_age' => 'عمر مزامنة الموظفين بالثواني', 'academic_mapping_max_age' => 'عمر مزامنة ربط المواد والشعب بالثواني' ) as $key => $label ) {
            echo '<p><label>' . esc_html( $label ) . '<input type="number" name="' . esc_attr( $key ) . '" value="' . esc_attr( $settings[$key] ) . '"></label></p>';
        }
        echo '<p><label>مسار التخزين الخاص خارج جذر الويب<input class="large-text" name="private_storage_path" value="' . esc_attr( $settings['private_storage_path'] ) . '"></label></p>';
        echo '<p><label><input type="checkbox" name="storage_reviewed" value="1">أؤكد مراجعة إعدادات nginx/Apache وأن المسار أعلاه غير منشور عبر alias أو رابط عام</label></p>';
        echo '<p><label>سياسة فحص الملفات<select name="malware_policy">'; foreach ( array( 'if_available', 'required', 'disabled' ) as $policy ) { echo '<option ' . selected( $settings['malware_policy'], $policy, false ) . '>' . esc_html( $policy ) . '</option>'; } echo '</select></label></p>';
        foreach ( array( 'office_start' => 'بداية دوام الرد بالدقائق بعد منتصف الليل', 'office_end' => 'نهاية دوام الرد بالدقائق بعد منتصف الليل' ) as $key => $label ) { echo '<p><label>' . esc_html( $label ) . '<input type="number" min="0" max="1439" name="' . esc_attr( $key ) . '" value="' . esc_attr( $settings[$key] ) . '"></label></p>'; }
        echo '<p><label>أيام دوام الرد: الأحد 0 إلى السبت 6، مفصولة بفواصل<input name="office_days" value="' . esc_attr( implode( ',', $settings['office_days'] ) ) . '"></label></p>';
        echo '<p><label>عمر الفعاليات المغلقة قبل الأرشفة بالأيام<input name="retention_archive_days" type="number" min="30" value="' . esc_attr( $settings['retention_archive_days'] ) . '"></label></p>';
        submit_button( 'حفظ الإعدادات' );
        echo '</form><h2>صحة النظام</h2><pre dir="ltr" style="white-space:pre-wrap">' . esc_html( wp_json_encode( self::health(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre></div>';
    }

    public function save_settings() {
        if ( ! current_user_can( 'olama_messages_configure' ) ) { wp_die( 'غير مخول', '', array( 'response' => 403 ) ); }
        check_admin_referer( 'olama_communications_settings' );
        $settings = Olama_Messages_Communication_Policy::settings();
        $settings['enabled'] = ! empty( $_POST['enabled'] );
        $settings['notifications'] = ! empty( $_POST['notifications'] );
        $settings['chat_enabled'] = ! empty( $_POST['chat_enabled'] );
        foreach ( array( 'attachments_enabled', 'actions_enabled', 'events_enabled', 'legacy_office_enabled', 'retention_archive_enabled' ) as $key ) { $settings[$key] = ! empty( $_POST[$key] ); }
        $settings['retention_archive_days'] = max( 30, min( 36500, absint( $_POST['retention_archive_days'] ?? 730 ) ) );
        $path = rtrim( wp_normalize_path( trim( (string) wp_unslash( $_POST['private_storage_path'] ?? '' ) ) ), '/' );
        $effective_path = defined( 'OLAMA_MSG_PRIVATE_STORAGE_PATH' ) ? rtrim( wp_normalize_path( OLAMA_MSG_PRIVATE_STORAGE_PATH ), '/' ) : ( $path ?: wp_normalize_path( dirname( rtrim( ABSPATH, '/\\' ) ) . '/olama-private-communications' ) );
        if ( $path !== $settings['private_storage_path'] ) { $settings['private_storage_reviewed_path'] = ''; }
        $settings['private_storage_path'] = $path;
        if ( ! empty( $_POST['storage_reviewed'] ) ) { $settings['private_storage_reviewed_path'] = $effective_path; }
        $policy = (string) ( $_POST['malware_policy'] ?? 'if_available' );
        if ( ! in_array( $policy, array( 'if_available', 'required', 'disabled' ), true ) ) { wp_die( 'سياسة فحص غير صالحة.' ); }
        $settings['malware_policy'] = $policy;
        $settings['office_start'] = max( 0, min( 1439, (int) ( $_POST['office_start'] ?? 480 ) ) );
        $settings['office_end'] = max( $settings['office_start'] + 1, min( 1440, (int) ( $_POST['office_end'] ?? 900 ) ) );
        $settings['office_days'] = array_values( array_unique( array_filter( array_map( 'intval', explode( ',', (string) ( $_POST['office_days'] ?? '0,1,2,3,4' ) ) ), static function ( $day ) { return $day >= 0 && $day <= 6; } ) ) );
        $settings['message_max_chars'] = max( 1, min( 5000, absint( $_POST['message_max_chars'] ?? 5000 ) ) );
        $settings['edit_minutes'] = min( 60, absint( $_POST['edit_minutes'] ?? 15 ) );
        foreach ( array( 'student_context_max_age', 'employee_mapping_max_age', 'academic_mapping_max_age' ) as $key ) { $settings[$key] = max( 60, min( 30 * DAY_IN_SECONDS, absint( $_POST[$key] ?? DAY_IN_SECONDS ) ) ); }
        $pilot_input = trim( (string) wp_unslash( $_POST['pilot_users'] ?? '' ) );
        if ( '' !== $pilot_input && ! preg_match( '/^[1-9][0-9]*(\s*,\s*[1-9][0-9]*)*$/', $pilot_input ) ) { wp_die( 'قائمة حسابات التجربة غير صالحة؛ لم تتغير الإعدادات.' ); }
        $settings['pilot_users'] = array_values( array_filter( array_map( 'absint', explode( ',', $pilot_input ) ) ) );
        $settings['poll_seconds'] = max( 10, min( 120, absint( $_POST['poll_seconds'] ?? 20 ) ) );
        if ( Olama_Messages_Communications_DB::health() ) {
            // Saving configuration is a natural recovery point for an additive migration.
            Olama_Messages_Communications_DB::install();
            $schema_errors = Olama_Messages_Communications_DB::health( true );
            if ( $schema_errors ) {
                wp_die( 'تعذر إصلاح مخطط قاعدة بيانات الاتصالات: ' . esc_html( implode( '، ', $schema_errors ) ) );
            }
        }
        $actors = ( new Olama_Messages_Actor_Resolver() )->available( get_current_user_id() );
        Olama_Messages_Communications_DB::transaction( function () use ( $settings, $actors ) {
            update_option( 'olama_msg_communications', $settings, false );
            // Unmapped technical administrators are identified by WP ID, not fabricated employee identity.
            Olama_Messages_Communications_DB::audit( 'settings_changed', 0, $actors ? $actors[0] : array( 'actor_key' => 'system:configuration' ), array( 'settings' => $settings ) );
        } );
        wp_safe_redirect( admin_url( 'admin.php?page=olama-communications-settings' ) );
        exit;
    }
}
