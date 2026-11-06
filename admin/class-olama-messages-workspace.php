<?php
/** Shared, capability-scoped admin shell. Existing page callbacks retain their actions. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Olama_Messages_Workspace {
    public static function text( $english, $arabic ) { return is_rtl() ? $arabic : translate( $english, 'olama-messages' ); }
    public static function arabic_labels() {
        $arabic = array( 'Overview'=>'نظرة عامة', 'Campaigns'=>'الحملات', 'Audience'=>'شرائح الجمهور', 'Message library'=>'قوالب الرسائل', 'Delivery'=>'مركز التسليم', 'Settings'=>'إعدادات الإرسال', 'Create campaign'=>'إنشاء حملة جديدة', 'Campaign delivery'=>'متابعة الحملة', 'Quick Send'=>'إرسال سريع', 'Reports'=>'التقارير', 'Parent report links'=>'روابط تقارير أولياء الأمور', 'Sending agents'=>'وكلاء الإرسال', 'Contacts'=>'جهات الاتصال', 'Delivery activity'=>'نشاط الإرسال' );
        $arabic += array(
            'Draft'=>'مسودة','Prepared'=>'مجهزة','Sending'=>'قيد الإرسال','Paused'=>'متوقفة مؤقتاً','KDE submission complete'=>'اكتمل التقديم إلى KDE','Completed (Errors)'=>'انتهت مع أخطاء','Cancelled'=>'ملغاة','Archived'=>'مؤرشفة','Reserved'=>'قيد المعالجة','Sent'=>'مقدمة إلى KDE','Submitted via KDE'=>'مقدمة إلى KDE','Failed'=>'فشل التقديم','Unknown'=>'تحتاج مراجعة','Outcome unknown — review needed'=>'نتيجة غير مؤكدة — تحتاج مراجعة','Retry Wait'=>'إعادة محاولة مجدولة','Retry wait'=>'إعادة محاولة مجدولة',
            'Sending agent ready'=>'وكيل الإرسال جاهز','Check sending agent'=>'راجع وكيل الإرسال','Open connection details'=>'عرض تفاصيل الاتصال','WordPress dashboard'=>'لوحة تحكم WordPress','Find a page…'=>'ابحث عن صفحة…','Find a workspace page'=>'البحث في صفحات مساحة العمل','Page name'=>'اسم الصفحة','Close search'=>'إغلاق البحث','Open navigation'=>'فتح التنقل','Close navigation'=>'إغلاق التنقل','No matching pages.'=>'لا توجد صفحات مطابقة.','Search destinations · Escape to close'=>'ابحث في الصفحات · Escape للإغلاق',
            'Phone'=>'الهاتف','Last reported connection'=>'آخر اتصال أبلغ عنه الوكيل','Reachable'=>'متاح','Not connected'=>'غير متصل','Target cadence'=>'الفاصل المستهدف','Last heartbeat'=>'آخر اتصال مسجل','seconds'=>'ثوانٍ','Open agent workspace'=>'عرض صحة النظام','Sending agent'=>'وكيل الإرسال',
            'Continue draft'=>'متابعة المسودة','Review & authorize'=>'المراجعة والاعتماد','View delivery'=>'عرض نتائج الحملة','More'=>'المزيد','Delete campaign'=>'حذف الحملة','Archive'=>'أرشفة','Campaign Progress'=>'متابعة تقديم الرسائل','Total Queue'=>'إجمالي القائمة','In Progress'=>'قيد المعالجة','Attempts'=>'المحاولات','Last Error'=>'آخر خطأ','Submitted At'=>'وقت التقديم','Updated'=>'آخر تحديث','Recipient'=>'المستلم','Status'=>'الحالة','No queue records found for this campaign.'=>'لا توجد سجلات تطابق هذه الفلاتر.','Pause Sending'=>'إيقاف الإرسال مؤقتاً','Resume Sending'=>'استئناف الإرسال','Cancel Campaign'=>'إلغاء الحملة',
            'Message library'=>'قوالب الرسائل','Edit template'=>'تعديل القالب','+ Create a reusable message'=>'+ إنشاء قالب رسالة','Available for campaigns'=>'متاح للحملات','Inactive'=>'غير نشط','Default'=>'افتراضي','Edit message'=>'تعديل الرسالة','Use message'=>'استخدام القالب','More options'=>'خيارات إضافية','Delete template'=>'حذف القالب','Name'=>'الاسم','Body'=>'نص الرسالة','Active'=>'نشط','Save Template'=>'حفظ القالب','Create Template'=>'إنشاء القالب',
            'Campaign at a glance'=>'ملخص الحملة','Title'=>'العنوان','Prepared recipients'=>'المستلمون المجهزون','Preview during review'=>'المعاينة عند المراجعة','Target interval'=>'الفاصل المستهدف','Draft message preview'=>'معاينة مسودة الرسالة','Fields are replaced during preparation. Final SMS lengths can vary by recipient.'=>'تستبدل الحقول أثناء التجهيز. قد يختلف طول الرسالة النهائي حسب المستلم.','Preparation freezes the audience and message. Only explicit authorization starts sending.'=>'يثبت التجهيز الجمهور والرسالة. يبدأ الإرسال بعد الاعتماد الصريح فقط.'
        );
        $arabic += array(
            'Create Campaign' => 'إنشاء حملة جديدة',
            'Edit Campaign' => 'تعديل الحملة',
            'Untitled campaign' => 'حملة دون عنوان',
            'Draft changes save automatically' => 'تحفظ المسودة تلقائياً',
            'Select an audience category and configure specific filters for targeted messaging.' => 'اختر شريحة الجمهور ثم حدد الفلاتر المناسبة للحملة.',
            'Finance' => 'مالي',
            'Outstanding balances' => 'الأرصدة المستحقة',
            'Academic' => 'أكاديمي',
            'School / Grade / Section' => 'المدرسة / الصف / الشعبة',
            'Transportation' => 'النقل المدرسي',
            'GPS status & bus routes' => 'الموقع ومسارات الحافلات',
            'Store' => 'المستلزمات',
            'Pending books & uniforms' => 'الكتب والزي غير المستلمين',
            'Outstanding Balances' => 'الأرصدة المستحقة',
            'Target families with unpaid balances or specific financial statuses.' => 'الأسر التي لديها أرصدة غير مسددة وفق الفلاتر المالية.',
            'Olama Agreements Collection' => 'تحصيل الاتفاقيات',
            'Families with unpaid agreement installments in the selected month.' => 'الأسر التي لديها أقساط اتفاقيات غير مسددة في الشهر المحدد.',
            'Renewal Reminder' => 'تذكير بالتجديد',
            'Target families enrolled in the previous academic year who have not registered for the current academic year and were not transferred.' => 'أسر العام السابق التي لم تجدد التسجيل للعام الحالي ولم تنقل.',
            'Finance Audience Filters' => 'فلاتر الشريحة المالية',
            'Academic Filtering (School / Grade / Section)' => 'التصفية الأكاديمية (المدرسة / الصف / الشعبة)',
            'Select a school, grade, or section to filter student families.' => 'اختر المدرسة أو الصف أو الشعبة لتحديد أسر الطلبة.',
            'All Schools / Kindergarten' => 'كل المدارس والروضات',
            'All Grades' => 'كل الصفوف',
            'All Sections' => 'كل الشعب',
            'Families without GPS locations' => 'أسر دون موقع على الخريطة',
            'Target registered bus families who have not set coordinates in the transportation map.' => 'الأسر المسجلة في النقل التي لم تحدد إحداثياتها على الخريطة.',
            'Registered transportation families' => 'الأسر المسجلة في النقل',
            'Filter bus subscribers by geographic area, bus number, or trip direction.' => 'تصفية مشتركي النقل حسب المنطقة أو الحافلة أو اتجاه الرحلة.',
            'Transportation Filters' => 'فلاتر النقل',
            'All Areas' => 'كل المناطق',
            'All Departure Buses' => 'كل حافلات المغادرة',
            'All Arrival Buses' => 'كل حافلات العودة',
            'Store & Warehouse Audience' => 'شريحة المستلزمات والمستودع',
            'Target families who have NOT yet received their allocated textbooks or school uniform/custom packages for this academic year.' => 'الأسر التي لم تستلم الكتب أو الزي والمستلزمات المخصصة لها في العام الدراسي.',
            'Families missing Textbooks OR Uniforms (All Unreceived)' => 'كل الأسر التي لم تستلم الكتب أو الزي',
            'Uses the authoritative Olama Stores report engine for 100% accuracy matching the Reports Center.' => 'تعتمد الأهلية على تقارير Olama Stores المعتمدة.',
            'Recipient Policy' => 'سياسة المستلمين',
            'Father first, mother fallback' => 'الأب أولاً ثم الأم عند عدم توفر الرقم',
            'Mother first, father fallback' => 'الأم أولاً ثم الأب عند عدم توفر الرقم',
            'Both parents (separate SMS)' => 'كلا الوالدين (رسالة مستقلة لكل منهما)',
            'Start from a message library item' => 'استخدم قالباً من مكتبة الرسائل',
            'Custom message' => 'رسالة مخصصة',
            'Preparation freezes the audience and rendered messages. It does not send anything.' => 'يثبت التجهيز الجمهور ونص الرسالة النهائي. لا ترسل أي رسالة في هذه الخطوة.',
            'Refresh audience preview' => 'تحديث معاينة الجمهور',
            'Review and prepare the campaign before authorization.' => 'راجع الحملة وجهزها قبل اعتماد الإرسال.',
            'Unlock & edit recipients' => 'إلغاء التثبيت وتعديل المستلمين',
            'This removes the prepared snapshot. It does not send anything.' => 'يزيل هذا قائمة المستلمين المجهزة، دون إرسال رسائل.',
            'Authorize & start' => 'اعتماد الإرسال والبدء',
            'SMS preview' => 'معاينة الرسالة',
            'Close' => 'إغلاق',
            'School (مدرسة / روضة)' => 'المدرسة / الروضة',
            'Grade (الصف)' => 'الصف',
            'Section (الشعبة)' => 'الشعبة',
            'Minimum Outstanding Balance (JOD)' => 'أدنى رصيد مستحق (د.أ)',
            'Minimum Unpaid Agreement Due (JOD)' => 'أدنى قسط اتفاقية مستحق (د.أ)',
            'Payment due through month' => 'المستحقات حتى الشهر',
            'Payment due in month' => 'المستحقات خلال الشهر',
            'Select month' => 'اختر الشهر',
            'Departure Bus (الذهاب)' => 'حافلة الذهاب',
            'Arrival Bus (الإياب)' => 'حافلة العودة',
            'Geographic Area' => 'المنطقة الجغرافية',
            'Missing Item Filter' => 'المستلزمات غير المستلمة',
            'Save & continue' => 'حفظ ومتابعة',
            'Continue' => 'متابعة',
            'Continue to authorize' => 'متابعة إلى الاعتماد',
            'Prepare & continue' => 'تجهيز ومتابعة',
            'Saving…' => 'جارٍ الحفظ…',
            'Preparing…' => 'جارٍ التجهيز…',
            'Preparing recipient messages…' => 'جارٍ تجهيز الرسائل…',
            'Prepared campaign is locked' => 'الحملة المجهزة مثبتة ولا يمكن تعديلها',
            'Select a payment due month.' => 'اختر شهر استحقاق الدفعات.',
            'Draft could not be saved' => 'تعذر حفظ المسودة',
            'Campaign could not be prepared' => 'تعذر تجهيز الحملة',
            'Loading preview…' => 'جارٍ تحميل المعاينة…',
            'Preview failed' => 'تعذرت المعاينة',
            'The recipient preview could not be loaded.' => 'تعذر تحميل معاينة المستلمين.',
            'Your draft message will appear here.' => 'ستظهر مسودة رسالتك هنا.',
            'Example: July payment reminder' => 'مثال: اجتماع أولياء الأمور — نوفمبر',
            'Campaign' => 'الحملة',
            'Audience' => 'الجمهور',
            'Messages' => 'الرسائل',
            'Created' => 'تاريخ الإنشاء',
            'Next action' => 'الإجراء التالي',
            'Included' => 'مضمن',
            'Excluded' => 'مستبعد',
            'Recipient' => 'المستلم',
            'Parent' => 'ولي الأمر',
            'Mobile' => 'الهاتف',
            'Eligibility' => 'الأهلية',
            'SMS parts' => 'أجزاء SMS',
            'Message' => 'الرسالة',
            'Preview SMS' => 'معاينة الرسالة'
        );
        $arabic += array(
            'Source' => 'المصدر',
            'Study year' => 'العام الدراسي',
            'Audience' => 'الجمهور',
            'Recipient policy' => 'سياسة المستلمين',
            'Due through' => 'المستحقات حتى',
            'Olama Core tables' => 'بيانات Olama Core',
            'active families' => 'أسرة نشطة',
            'students' => 'طالباً',
            'messages to send' => 'رسالة للإرسال',
            'total SMS parts' => 'جزء SMS إجمالاً',
            'excluded' => 'مستبعد',
            'parent message targets' => 'هدف رسالة للوالدين',
            'messages selected for sending' => 'رسالة محددة للإرسال',
            'Select page' => 'تحديد الصفحة',
            'Unselect page' => 'إلغاء تحديد الصفحة',
            'Select all families' => 'تحديد كل الأسر',
            'Unselect all families' => 'إلغاء تحديد كل الأسر',
            'Rows' => 'الصفوف',
            'Recipient' => 'المستلم',
            'Parent' => 'ولي الأمر',
            'Mobile' => 'الهاتف',
            'Eligibility' => 'الأهلية',
            'SMS parts' => 'أجزاء SMS',
            'Message' => 'الرسالة',
            'Preview SMS' => 'معاينة الرسالة',
            'Included' => 'مضمن',
            'Excluded' => 'مستبعد',
            'Missing mobile' => 'رقم مفقود',
            'Invalid mobile' => 'رقم غير صالح',
            'International number' => 'رقم دولي',
            'Landline' => 'هاتف أرضي',
            'Duplicate mobile' => 'رقم مكرر',
            'Removed manually' => 'استبعاد يدوي',
            'Settings' => 'الإعدادات',
            'Save Settings' => 'حفظ الإعدادات',
            'School Name' => 'اسم المدرسة',
            'Contact Phone' => 'هاتف التواصل',
            'Channel' => 'القناة',
            'Template Name' => 'اسم القالب',
            'Template Body' => 'نص القالب'
        );
        return $arabic;
    }
    public static function label( $label ) {
        $arabic = self::arabic_labels();
        return self::text( $label, $arabic[$label] ?? $label );
    }
    public static function translate_label($translation, $text, $domain) { return $domain === 'olama-messages' && is_rtl() ? self::label($text) : $translation; }
    public static function routes() {
        return array(
            'olama-messages' => array( 'Overview','dashboard' ),
            'olama-messages-campaigns' => array( 'Campaigns','megaphone' ),
            'olama-messages-delivery' => array( 'Delivery','chart-line' ),
            'olama-messages-phone-book' => array( 'Audience','groups' ),
            'olama-messages-templates' => array( 'Message library','media-text' ),
            'olama-messages-settings' => array( 'Settings','admin-generic' ),
        );
    }
    public static function parent( $page ) {
        $parents = array( 'olama-messages-new-campaign' => 'olama-messages-campaigns', 'olama-messages-campaign-progress' => 'olama-messages-delivery',
            'olama-messages-direct' => 'olama-messages-campaigns', 'olama-messages-recipients' => 'olama-messages-phone-book',
            'olama-messages-reports' => 'olama-messages-phone-book', 'olama-messages-tokens' => 'olama-messages-templates',
            'olama-messages-agents' => 'olama-messages-delivery', 'olama-messages-queue' => 'olama-messages-delivery' );
        return $parents[$page] ?? $page;
    }
    public static function url( $page, array $args = array() ) { return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) ); }
    public static function callback( $owner, $method, $plugin ) {
        return static function () use ( $owner, $method, $plugin ) {
            if ( ! current_user_can( 'olama_access_messages' ) ) { wp_die( esc_html__( 'Unauthorized', 'olama-messages' ) ); }
            add_filter('gettext', array(__CLASS__, 'translate_label'), 10, 3);
            self::begin( $plugin );
            try { $owner->$method(); } finally { self::end(); remove_filter('gettext', array(__CLASS__, 'translate_label'), 10); }
        };
    }
    public static function begin( $plugin ) {
        $page = sanitize_key( $_GET['page'] ?? 'olama-messages' ); $parent = self::parent( $page ); $routes = self::routes();
        $school = get_option( 'olama_msg_school_name', '' ) ?: get_bloginfo( 'name' );
        $year = ''; try { $year = $plugin->provider()->get_current_study_year(); } catch ( Throwable $error ) { /* Navigation remains usable when Core is unavailable. */ }
        $agent = $plugin->agents()->get_ready_dispatcher_agent();
        $labels = array( 'olama-messages-new-campaign' => 'Create campaign', 'olama-messages-campaign-progress' => 'Campaign delivery', 'olama-messages-direct' => 'Quick Send', 'olama-messages-reports' => 'Reports', 'olama-messages-tokens' => 'Parent report links', 'olama-messages-agents' => 'Sending agents' );
        $label = $labels[$page] ?? ( $routes[$parent][0] ?? 'Messages' );
        ?>
        <div class="omw-shell" data-workspace-page="<?php echo esc_attr( $page ); ?>">
            <a class="screen-reader-shortcut" href="#omw-main"><?php echo esc_html( self::text('Skip to workspace','انتقل إلى مساحة العمل') ); ?></a>
            <button type="button" class="omw-shade" data-workspace-close aria-label="<?php esc_attr_e( 'Close navigation', 'olama-messages' ); ?>" hidden></button>
            <aside class="omw-sidebar" id="omw-sidebar"><a class="omw-brand" href="<?php echo esc_url( self::url( 'olama-messages' ) ); ?>"><span class="omw-brand-mark"><span class="dashicons dashicons-email-alt"></span></span><span><strong>olama</strong><small><?php esc_html_e( 'MESSAGES', 'olama-messages' ); ?></small></span></a>
                <div class="omw-school"><span class="dashicons dashicons-welcome-learn-more"></span><div><strong title="<?php echo esc_attr( $school ); ?>"><?php echo esc_html( $school ); ?></strong><small><?php echo esc_html( $year ?: __( 'School workspace', 'olama-messages' ) ); ?></small></div></div>
                <nav aria-label="<?php esc_attr_e( 'Olama Messages navigation', 'olama-messages' ); ?>"><?php $i=0; foreach ( $routes as $slug => $route ) : ?>
                    <?php if ( in_array( $i, array(0,3,5), true ) ) : ?><p class="omw-nav-group"><?php echo esc_html( array( 0 => __( 'WORKSPACE', 'olama-messages' ), 3 => __( 'RESOURCES', 'olama-messages' ), 5 => __( 'SYSTEM', 'olama-messages' ) )[$i] ); ?></p><?php endif; ?>
                    <a class="omw-nav-link <?php echo $parent === $slug ? 'is-current' : ''; ?>" href="<?php echo esc_url( self::url( $slug ) ); ?>" <?php echo $parent === $slug ? 'aria-current="page"' : ''; ?>><span class="dashicons dashicons-<?php echo esc_attr( $route[1] ); ?>"></span><?php echo esc_html( self::label( $route[0] ) ); ?></a>
                <?php $i++; endforeach; ?></nav>
                <div class="omw-sidebar-bottom"><a class="omw-agent-connection" href="<?php echo esc_url( self::url( 'olama-messages-agents' ) ); ?>"><span class="omw-dot <?php echo $agent ? '' : 'is-warning'; ?>"></span><div><strong><?php echo esc_html( $agent ? __( 'Sending agent ready', 'olama-messages' ) : __( 'Check sending agent', 'olama-messages' ) ); ?></strong><small><?php echo esc_html( $agent ? $agent['agent_name'] : __( 'Open connection details', 'olama-messages' ) ); ?></small></div></a><a class="omw-wp-return" href="<?php echo esc_url( admin_url() ); ?>"><span class="dashicons dashicons-wordpress"></span><?php esc_html_e( 'WordPress dashboard', 'olama-messages' ); ?> ↗</a></div>
            </aside>
            <div class="omw-app"><header class="omw-topbar"><div class="omw-breadcrumb"><button type="button" class="omw-mobile-toggle" data-workspace-menu aria-controls="omw-sidebar" aria-expanded="false" aria-label="<?php esc_attr_e( 'Open navigation', 'olama-messages' ); ?>"><span class="dashicons dashicons-menu"></span></button><a href="<?php echo esc_url( admin_url() ); ?>">WordPress</a><span>/</span><strong><?php echo esc_html( self::label( $label ) ); ?></strong></div><button type="button" class="omw-search-button" data-workspace-search><span class="dashicons dashicons-search"></span><span><?php esc_html_e( 'Find a page…', 'olama-messages' ); ?></span><kbd>Ctrl K</kbd></button></header>
                <main class="omw-content" id="omw-main" tabindex="-1">
                    <?php self::context_links( $page, $parent ); ?>
        <?php
    }
    public static function context_links( $page, $parent ) {
        $groups = array(
            'olama-messages-campaigns' => array( 'olama-messages-campaigns' => 'Campaigns', 'olama-messages-new-campaign' => 'Create campaign', 'olama-messages-direct' => 'Quick Send' ),
            'olama-messages-phone-book' => array( 'olama-messages-phone-book' => 'Contacts', 'olama-messages-reports' => 'Reports' ),
            'olama-messages-templates' => array( 'olama-messages-templates' => 'Message library', 'olama-messages-tokens' => 'Parent report links' ),
            'olama-messages-delivery' => array( 'olama-messages-delivery' => 'Delivery activity', 'olama-messages-agents' => 'Sending agents' ),
        );
        if ( ! isset( $groups[$parent] ) ) { return; }
        echo '<nav class="omw-context-nav" aria-label="' . esc_attr__( 'Related pages', 'olama-messages' ) . '">';
        foreach ( $groups[$parent] as $slug => $label ) { echo '<a class="' . ( $page === $slug ? 'is-current' : '' ) . '" href="' . esc_url( self::url( $slug ) ) . '">' . esc_html( self::label( $label ) ) . '</a>'; }
        echo '</nav>';
    }
    public static function end() {
        ?><footer class="omw-footer"><span><?php echo esc_html( self::text('Olama Messages · Crafted for school communication.','Olama Messages · تواصل مدرسي بكل وضوح.') ); ?></span><span><bdi><?php echo esc_html( wp_timezone_string() ); ?></bdi> · v<?php echo esc_html( OLAMA_MSG_VERSION ); ?></span></footer></main></div>
        <nav class="omw-bottom-nav" aria-label="<?php echo esc_attr(self::text('Primary navigation','التنقل الرئيسي')); ?>"><?php $active = self::parent(sanitize_key($_GET['page'] ?? 'olama-messages')); foreach(array('olama-messages','olama-messages-campaigns','olama-messages-new-campaign','olama-messages-delivery','olama-messages-phone-book') as $slug) : $route = self::routes()[$slug] ?? array('Create campaign','plus-alt2'); ?><a href="<?php echo esc_url(self::url($slug)); ?>" class="<?php echo $slug === 'olama-messages-new-campaign' ? 'omw-bottom-create' : ''; ?>" <?php echo $active === $slug ? 'aria-current="page"' : ''; ?>><span class="dashicons dashicons-<?php echo esc_attr($route[1]); ?>"></span><small><?php echo esc_html(self::label($route[0])); ?></small></a><?php endforeach; ?></nav>
        <dialog class="omw-command" id="omw-command" aria-label="<?php esc_attr_e( 'Find a workspace page', 'olama-messages' ); ?>"><form method="dialog"><input type="search" data-workspace-query placeholder="<?php esc_attr_e( 'Find a page…', 'olama-messages' ); ?>" aria-label="<?php esc_attr_e( 'Page name', 'olama-messages' ); ?>"><button class="omw-command-close" aria-label="<?php esc_attr_e( 'Close search', 'olama-messages' ); ?>">×</button></form><div class="omw-command-results"><?php foreach ( array_merge( self::routes(), array( 'olama-messages-new-campaign' => array('Create campaign','plus-alt2'), 'olama-messages-direct' => array('Quick Send','email'), 'olama-messages-agents' => array('Sending agents','desktop'), 'olama-messages-reports' => array('Reports','chart-bar'), 'olama-messages-tokens' => array('Parent report links','admin-links') ) ) as $slug => $route ) : ?><a href="<?php echo esc_url( self::url( $slug ) ); ?>" data-workspace-result><span class="dashicons dashicons-<?php echo esc_attr( $route[1] ); ?>"></span><?php echo esc_html( self::label( $route[0] ) ); ?></a><?php endforeach; ?><p data-workspace-empty hidden><?php esc_html_e( 'No matching pages.', 'olama-messages' ); ?></p></div><small><?php esc_html_e( 'Search destinations · Escape to close', 'olama-messages' ); ?></small></dialog></div><?php
    }
}
