<?php
/** Arabic collection report, hosted in the existing Messages Reports workspace. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Olama_Messages_Collection_Report_Page {
    public static function init() {
        add_action( 'admin_init', array( __CLASS__, 'handle' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
    }

    public static function assets() {
        if ( self::input( 'page' ) === 'olama-messages-reports' ) {
            wp_enqueue_style( 'olama-msg-collection', OLAMA_MSG_URL . 'assets/collection-report.css', array(), filemtime( OLAMA_MSG_PATH . 'assets/collection-report.css' ) );
        }
    }

    private static function input( $key, $default = '', $source = null ) {
        $source = null === $source ? $_GET : $source;
        return isset( $source[$key] ) && is_scalar( $source[$key] ) ? sanitize_text_field( wp_unslash( (string) $source[$key] ) ) : $default;
    }

    private static function url( array $args = array() ) {
        return add_query_arg( array_merge( array( 'page' => 'olama-messages-reports', 'report' => 'collection' ), $args ), admin_url( 'admin.php' ) );
    }

    public static function tabs( $active ) {
        echo '<nav class="nav-tab-wrapper omcr-tabs" aria-label="التقارير"><a class="nav-tab ' . ( $active === 'sections' ? 'nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=olama-messages-reports' ) ) . '">تقرير طلاب الشعب</a>';
        if ( Olama_Messages_Collection_Report::can_view() ) {
            echo '<a class="nav-tab ' . ( $active === 'collection' ? 'nav-tab-active' : '' ) . '" href="' . esc_url( self::url() ) . '">التحصيل ومراجعة إيقاف المواصلات</a>';
        }
        echo '</nav>';
    }

    private static function policy_input( array $source ) {
        $policy = Olama_Messages_Collection_Report::policy();
        $limits = array( 'installments' => array( 0.1, 12 ), 'days' => array( 1, 365 ), 'tolerance' => array( 0, 100 ), 'stale_days' => array( 0, 30 ) );
        foreach ( $limits as $key => $range ) {
            $value = self::input( 'policy_' . $key, (string) $policy[$key], $source );
            if ( ! is_numeric( $value ) || (float) $value < $range[0] || (float) $value > $range[1] ) { throw new InvalidArgumentException( 'إعدادات الشروط خارج الحدود المسموحة.' ); }
            $policy[$key] = in_array( $key, array( 'days', 'stale_days' ), true ) ? (int) $value : round( (float) $value, 3 );
        }
        return $policy;
    }

    public static function handle() {
        $operation = self::input( 'collection_operation', '', $_POST );
        $export = self::input( 'collection_export' );
        if ( ! $operation && ! $export ) { return; }
        if ( ! Olama_Messages_Collection_Report::can_view() ) { wp_die( 'لا تملك صلاحية عرض البيانات المالية.', '', array( 'response' => 403 ) ); }
        if ( $operation ) {
            check_admin_referer( 'olama_msg_collection_write' );
            try {
                if ( $operation === 'policy' ) {
                    if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'تعديل السياسة متاح لمدير النظام فقط.', '', array( 'response' => 403 ) ); }
                    $policy = self::policy_input( $_POST );
                    $policy['updated_at'] = current_time( 'mysql' ); $policy['updated_by'] = get_current_user_id();
                    $policy['version'] = hash( 'sha256', wp_json_encode( $policy ) );
                    update_option( Olama_Messages_Collection_Report::OPTION, $policy, false );
                } elseif ( $operation === 'review' ) {
                    $year = self::input( 'study_year', '', $_POST ); $family = self::input( 'family_id', '', $_POST );
                    $status = self::input( 'review_status', '', $_POST ); $until = self::input( 'review_until', '', $_POST );
                    $note = self::input( 'review_note', '', $_POST ); $override = self::input( 'typical_override', '0', $_POST );
                    if ( ! in_array( $year, self::years(), true ) || ! preg_match( '/^\d+$/', $family ) || ! in_array( $status, array( 'clear', 'exception', 'arrangement', 'unverified' ), true )
                        || ! Olama_Messages_Collection_Report::valid_date( $until ) || $until < current_time( 'Y-m-d' ) || ! $note || ! is_numeric( $override ) || (float) $override < 0 || (float) $override > 1000000 ) { throw new InvalidArgumentException( 'تحقق من العائلة وحالة المراجعة والملاحظة وتاريخ الصلاحية وقيمة القسط.' ); }
                    global $wpdb;
                    if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$wpdb->prefix}olama_core_family_financial_years` WHERE oracle_family_id = %s AND study_year = %s", $family, $year ) ) ) { throw new InvalidArgumentException( 'العائلة غير موجودة في البيانات المالية لهذه السنة.' ); }
                    $key = Olama_Messages_Collection_Report::review_key( $year, $family );
                    $old = (array) get_option( $key, array() ); $audit = $old['audit'] ?? array(); unset( $old['audit'] );
                    if ( $old ) { $audit[] = $old; }
                    $review = array( 'status' => $status, 'until' => $until, 'note' => mb_substr( $note, 0, 2000 ), 'typical_override' => round( (float) $override, 3 ), 'updated_by' => get_current_user_id(), 'updated_at' => current_time( 'mysql' ), 'policy' => Olama_Messages_Collection_Report::policy(), 'calculation_version' => Olama_Messages_Collection_Report::VERSION, 'audit' => array_slice( $audit, -20 ) );
                    update_option( $key, $review, false );
                } else { throw new InvalidArgumentException( 'إجراء غير صالح.' ); }
                wp_safe_redirect( self::url( array( 'study_year' => self::input( 'study_year', '', $_POST ), 'saved' => 1 ) ) ); exit;
            } catch ( Exception $e ) { wp_die( esc_html( $e->getMessage() ) ); }
        }
        if ( ! in_array( $export, array( 'families', 'transport' ), true ) ) { wp_die( 'نوع تصدير غير صالح.' ); }
        check_admin_referer( 'olama_msg_collection_export' );
        try { $data = self::data(); } catch ( Exception $e ) { wp_die( esc_html( $e->getMessage() ) ); }
        nocache_headers(); header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="collection-' . $export . '-' . current_time( 'Y-m-d' ) . '.csv"' );
        $stream = fopen( 'php://output', 'w' ); fwrite( $stream, "\xEF\xBB\xBF" );
        $write = static function ( $cells ) use ( $stream ) { fputcsv( $stream, array_map( array( 'Olama_Messages_Collection_Report', 'csv_cell' ), $cells ), ',', '"', '' ); };
        $labels = Olama_Messages_Collection_Report::labels();
        if ( $export === 'families' ) {
            $write( array( 'تاريخ التقرير', 'السنة', 'رقم العائلة', 'اسم العائلة', 'صافي المستحق', 'المغطى', 'المتأخر', 'نسبة التغطية', 'أقساط ذات رصيد', 'غير مسددة', 'جزئية', 'القسط المعتاد', 'مكافئ الأقساط', 'أقدم تأخر بالأيام', 'التوصية', 'الأهلية المالية', 'آخر مزامنة', 'ملاحظات البيانات' ) );
            foreach ( $data['rows'] as $row ) { $write( array( $data['today'], $data['year'], $row['oracle_family_id'], $row['sponsor_full_name'] ?: $row['father_name'], self::amount( $row['due'] ), self::amount( $row['covered'] ), self::amount( $row['overdue'] ), $row['coverage'] === null ? '' : round( $row['coverage'], 2 ), $row['outstanding'], $row['unpaid'], $row['partial'], self::amount( $row['typical'] ), $row['equivalent'] === null ? '' : round( $row['equivalent'], 2 ), $row['oldest'], $labels[$row['action']], $row['financial_match'] ? 'متحققة' : 'غير متحققة', $row['source_synced_at'], implode( '؛ ', $row['warnings'] ) ) ); }
        } else {
            // No debt figures in the operational review export. One row per student.
            $write( array( 'تاريخ التقرير', 'السنة', 'رقم العائلة', 'الطالب', 'الصف', 'الشعبة', 'باص الذهاب', 'باص العودة', 'الجولة', 'التوصية', 'صلاحية التحقق الإداري' ) );
            foreach ( $data['rows'] as $row ) {
                if ( $row['action'] !== 'candidate' ) { continue; }
                foreach ( $row['transport'] as $student ) { $write( array( $data['today'], $data['year'], $row['oracle_family_id'], $student['student_name'], $student['class_name'], $student['section_name'], $student['departure_bus_name'] ?: $student['departure_bus'], $student['arrival_bus_name'] ?: $student['arrival_bus'], $student['trans_route_name'], $labels['candidate'], $row['review']['until'] ) ); }
            }
        }
        fclose( $stream ); exit;
    }

    private static function years() {
        global $wpdb;
        if ( ! function_exists( 'olama_core' ) ) { return array(); }
        return array_map( 'strval', $wpdb->get_col( "SELECT DISTINCT study_year FROM `{$wpdb->prefix}olama_core_family_financial_years` ORDER BY study_year DESC" ) );
    }

    private static function data() {
        $years = self::years(); $year = self::input( 'study_year', $years[0] ?? '' );
        if ( ! $years || ! in_array( $year, $years, true ) ) { throw new RuntimeException( 'لا توجد بيانات مالية متزامنة للسنة المختارة.' ); }
        $today = current_time( 'Y-m-d' ); $policy = self::policy_input( $_GET );
        $rows = Olama_Messages_Collection_Report::load( $year, $today, $policy );
        $action = self::input( 'collection_action' ); $family = self::input( 'family_id' ); $transport = self::input( 'transport' ); $bus = self::input( 'bus' );
        $rows = array_values( array_filter( $rows, static function ( $row ) use ( $action, $family, $transport, $bus ) {
            if ( $action === 'financial' ? ! $row['financial_match'] : ( $action && $row['action'] !== $action ) ) { return false; }
            if ( $family && (string) $row['oracle_family_id'] !== $family ) { return false; }
            if ( $transport === 'yes' && ! $row['transport'] ) { return false; }
            if ( $bus ) {
                foreach ( $row['transport'] as $student ) { foreach ( array( 'departure_bus', 'departure_bus_name', 'arrival_bus', 'arrival_bus_name' ) as $field ) { if ( stripos( (string) $student[$field], $bus ) !== false ) { return true; } } }
                return false;
            }
            return true;
        } ) );
        $sort = self::input( 'sort', 'overdue' );
        if ( ! in_array( $sort, array( 'overdue', 'oldest', 'equivalent', 'coverage' ), true ) ) { $sort = 'overdue'; }
        usort( $rows, static function ( $a, $b ) use ( $sort ) { $cmp = $sort === 'coverage' ? ( $a[$sort] ?? 101 ) <=> ( $b[$sort] ?? 101 ) : ( $b[$sort] ?? -1 ) <=> ( $a[$sort] ?? -1 ); return $cmp ?: strcmp( (string) $a['oracle_family_id'], (string) $b['oracle_family_id'] ); } );
        return compact( 'rows', 'years', 'year', 'today', 'policy' );
    }

    private static function amount( $fils ) { return number_format( $fils / 1000, 3, '.', '' ); }

    public static function render() {
        if ( ! Olama_Messages_Collection_Report::can_view() ) { wp_die( 'لا تملك صلاحية عرض البيانات المالية.', '', array( 'response' => 403 ) ); }
        echo '<div class="wrap olama-msg-app omcr" dir="rtl"><div class="omsg-page-head"><div><h1>التحصيل ومراجعة إيقاف المواصلات</h1><p>تقييم عائلي للأقساط الشاملة للدراسة والمواصلات.</p></div></div>';
        self::tabs( 'collection' );
        try { $data = self::data(); } catch ( Exception $e ) { echo '<div class="notice notice-error inline"><p>' . esc_html( $e->getMessage() ) . '</p></div></div>'; return; }
        extract( $data, EXTR_SKIP );
        $labels = Olama_Messages_Collection_Report::labels();
        $base = array( 'study_year' => $year );
        foreach ( array( 'collection_action', 'family_id', 'transport', 'bus', 'sort', 'policy_installments', 'policy_days', 'policy_tolerance', 'policy_stale_days' ) as $key ) { if ( self::input( $key ) !== '' ) { $base[$key] = self::input( $key ); } }
        $due = array_sum( array_column( $rows, 'due' ) ); $covered = array_sum( array_column( $rows, 'covered' ) ); $debt = array_sum( array_column( $rows, 'overdue' ) );
        $matches = count( array_filter( $rows, static function ( $r ) { return $r['financial_match']; } ) );
        $candidates = array_filter( $rows, static function ( $r ) { return $r['action'] === 'candidate'; } );
        $students = array_sum( array_map( static function ( $r ) { return count( $r['transport'] ); }, $candidates ) );
        ?>
        <?php if ( self::input( 'saved' ) ) : ?><div class="notice notice-success inline"><p>تم حفظ المراجعة / السياسة.</p></div><?php endif; ?>
        <section class="omsg-panel omcr-intro"><strong>تاريخ التقرير: <?php echo esc_html( $today ); ?> — العملة: دينار أردني</strong><p>الأهلية المالية: متأخر يعادل <?php echo esc_html( $policy['installments'] ); ?> قسطًا صافيًا أو أكثر، وأقدم رصيد قسط يتجاوز سماحية <?php echo esc_html( $policy['tolerance'] ); ?> دينار متأخر منذ <?php echo (int) $policy['days']; ?> يومًا على الأقل.</p><p>تُستخدم آخر أرصدة موزعة في Oracle؛ الخصومات تخفض المستحق، والدفعات والمدور الدائن تغطيه. الحساب لتاريخ اليوم فقط. التقرير ينتج قائمة للمراجعة ولا يوقف خدمة أو يرسل رسائل.</p></section>
        <form method="get" class="omsg-panel omcr-filters">
            <input type="hidden" name="page" value="olama-messages-reports"><input type="hidden" name="report" value="collection">
            <label>السنة الدراسية<select name="study_year"><?php foreach ( $years as $y ) : ?><option <?php selected( $year, $y ); ?>><?php echo esc_html( $y ); ?></option><?php endforeach; ?></select></label>
            <label>التوصية<select name="collection_action"><option value="">جميع العائلات</option><option value="financial" <?php selected( self::input( 'collection_action' ), 'financial' ); ?>>الشروط المالية متحققة</option><?php foreach ( $labels as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( self::input( 'collection_action' ), $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
            <label>رقم العائلة<input name="family_id" inputmode="numeric" value="<?php echo esc_attr( self::input( 'family_id' ) ); ?>"></label>
            <label>المواصلات<select name="transport"><option value="">الجميع</option><option value="yes" <?php selected( self::input( 'transport' ), 'yes' ); ?>>مشتركون فعّالون</option></select></label>
            <label>الباص<input name="bus" value="<?php echo esc_attr( self::input( 'bus' ) ); ?>" placeholder="الاسم أو الرقم"></label>
            <label>الترتيب<select name="sort"><?php foreach ( array( 'overdue' => 'المتأخر: الأكبر أولًا', 'oldest' => 'التأخر: الأقدم أولًا', 'equivalent' => 'مكافئ الأقساط: الأكبر أولًا', 'coverage' => 'التغطية: الأقل أولًا' ) as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( self::input( 'sort', 'overdue' ), $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
            <details class="omcr-policy"><summary>الشروط المالية وحداثة البيانات — معاينة قبل الحفظ</summary><div class="omcr-filters">
                <?php foreach ( array( 'installments' => 'حد مكافئ الأقساط (0.1–12)', 'days' => 'حد التأخر بالأيام (1–365)', 'tolerance' => 'سماحية الرصيد الصغير بالدينار (0–100)', 'stale_days' => 'أقصى عمر للمزامنة بالأيام (0–30)' ) as $key => $label ) : ?><label><?php echo esc_html( $label ); ?><input type="number" step="<?php echo in_array( $key, array( 'days', 'stale_days' ), true ) ? '1' : '0.001'; ?>" name="policy_<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $policy[$key] ); ?>" required></label><?php endforeach; ?>
            </div><p>تعديل القيم ثم عرض التقرير يعاين أثرها فقط. الحفظ كسياسة افتراضية متاح لمدير النظام.</p></details>
            <button class="button button-primary">عرض التقرير / معاينة الشروط</button><a class="button" href="<?php echo esc_url( self::url() ); ?>">إعادة الضبط</a>
        </form>
        <?php if ( current_user_can( 'manage_options' ) ) : ?><form method="post" class="omcr-save-policy"><?php wp_nonce_field( 'olama_msg_collection_write' ); ?><input type="hidden" name="collection_operation" value="policy"><input type="hidden" name="study_year" value="<?php echo esc_attr( $year ); ?>"><?php foreach ( array_keys( Olama_Messages_Collection_Report::defaults() ) as $key ) : ?><input type="hidden" name="policy_<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $policy[$key] ); ?>"><?php endforeach; ?><button class="button">حفظ الشروط المعروضة كسياسة افتراضية</button></form><?php endif; ?>
        <div class="omcr-cards"><?php foreach ( array( 'صافي المستحق' => self::amount( $due ), 'المغطى' => self::amount( $covered ), 'المتأخر' => self::amount( $debt ), 'التغطية الإجمالية' => $due ? number_format( $covered / $due * 100, 2 ) . '%' : 'لا مستحقات', 'عائلات تحقق الشروط المالية' => $matches, 'عائلات مرشحة بعد التحقق' => count( $candidates ), 'طلاب بقائمة المراجعة' => $students ) as $label => $value ) : ?><div class="omsg-panel"><span><?php echo esc_html( $label ); ?></span><strong><?php echo esc_html( $value ); ?></strong></div><?php endforeach; ?></div>
        <p class="description">الإجماليات تخص نتائج الفلاتر وتشمل الصفوف الموسومة بملاحظات؛ لا تعد قائمة تنفيذ. البيانات القديمة أو غير المتسقة تمنع تأكيد الترشيح. يلزم تحقق إداري مسجل من الاستثناءات واتفاقات السداد.</p>
        <div class="omcr-toolbar"><strong><?php echo count( $rows ); ?> عائلة</strong><a class="button" href="<?php echo esc_url( wp_nonce_url( self::url( array_merge( $base, array( 'collection_export' => 'families' ) ) ), 'olama_msg_collection_export' ) ); ?>">تصدير التقرير المالي CSV</a><a class="button" href="<?php echo esc_url( wp_nonce_url( self::url( array_merge( $base, array( 'collection_export' => 'transport' ) ) ), 'olama_msg_collection_export' ) ); ?>">تصدير الطلاب المرشحين للمراجعة CSV</a></div>
        <div class="omsg-panel omsg-table-scroll"><table class="widefat striped omcr-table"><thead><tr><?php foreach ( array( 'العائلة', 'صافي المستحق', 'المغطى', 'المتأخر', 'التغطية', 'أقساط ذات رصيد', 'مكافئ الأقساط', 'أقدم تأخر معتبر', 'الشروط المالية', 'طلاب النقل', 'التوصية / التفاصيل' ) as $label ) { echo '<th>' . esc_html( $label ) . '</th>'; } ?></tr></thead><tbody>
        <?php $page = max( 1, min( max( 1, (int) ceil( count( $rows ) / 50 ) ), absint( self::input( 'paged', '1' ) ) ) ); ?>
        <?php if ( ! $rows ) : ?><tr><td colspan="11">لا توجد عائلات تطابق الفلاتر.</td></tr><?php endif; ?>
        <?php foreach ( array_slice( $rows, ( $page - 1 ) * 50, 50 ) as $row ) : ?>
            <tr><td><strong><?php echo esc_html( $row['oracle_family_id'] ); ?></strong><br><?php echo esc_html( $row['sponsor_full_name'] ?: $row['father_name'] ); ?></td>
            <?php foreach ( array( 'due', 'covered', 'overdue' ) as $key ) : ?><td class="omcr-number"><?php echo esc_html( self::amount( $row[$key] ) ); ?></td><?php endforeach; ?>
            <td><?php echo esc_html( $row['coverage'] === null ? 'لا مستحقات' : number_format( $row['coverage'], 2 ) . '%' ); ?></td><td><?php echo (int) $row['outstanding']; ?><small>كامل: <?php echo (int) $row['unpaid']; ?> / جزئي: <?php echo (int) $row['partial']; ?></small></td><td><?php echo esc_html( $row['equivalent'] === null ? 'غير محدد' : number_format( $row['equivalent'], 2 ) ); ?></td><td><?php echo (int) $row['oldest']; ?> يومًا</td><td><?php echo $row['financial_match'] ? 'متحققة' : 'غير متحققة'; ?></td><td><?php echo count( $row['transport'] ); ?></td><td><span class="omcr-badge omcr-<?php echo esc_attr( $row['action'] ); ?>"><?php echo esc_html( $labels[$row['action']] ); ?></span><?php self::details( $row, $year, $today ); ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
        <div class="omcr-toolbar"><?php if ( $page > 1 ) : ?><a class="button" href="<?php echo esc_url( self::url( array_merge( $base, array( 'paged' => $page - 1 ) ) ) ); ?>">السابق</a><?php endif; ?><span>صفحة <?php echo $page; ?> من <?php echo max( 1, (int) ceil( count( $rows ) / 50 ) ); ?></span><?php if ( $page * 50 < count( $rows ) ) : ?><a class="button" href="<?php echo esc_url( self::url( array_merge( $base, array( 'paged' => $page + 1 ) ) ) ); ?>">التالي</a><?php endif; ?></div>
        </div><?php
    }

    private static function details( array $row, $year, $today ) {
        $review = $row['review'];
        ?><details class="omcr-details"><summary>الحساب والتحقق الإداري</summary><div>
        <p>القسط المعتاد الصافي: <strong><?php echo esc_html( self::amount( $row['typical'] ) ); ?></strong> دينار. <?php echo ! empty( $review['typical_override'] ) ? 'قيمة محددة يدويًا.' : 'وسيط قيم الأقساط الصافية الموجبة؛ يمكن تصحيحه أدناه عند وجود أقساط استثنائية.'; ?></p>
        <p>آخر مزامنة مستخدمة: <b dir="ltr"><?php echo esc_html( $row['source_synced_at'] ?: 'غير معروف' ); ?></b>. الرصيد السنوي المتبقي: <?php echo esc_html( self::amount( $row['annual_balance'] ) ); ?> دينار؛ يشمل المستقبلي.</p>
        <?php foreach ( $row['warnings'] as $warning ) : ?><p class="omcr-warning"><?php echo esc_html( $warning ); ?></p><?php endforeach; ?>
        <table class="widefat"><thead><tr><th>الاستحقاق</th><th>قبل الخصم</th><th>الخصومات</th><th>الصافي</th><th>المغطى</th><th>الرصيد</th><th>التأخر</th></tr></thead><tbody><?php foreach ( $row['schedule'] as $item ) : ?><tr><td><?php echo esc_html( $item['date'] ); ?></td><?php foreach ( array( 'gross', 'discount', 'net', 'covered', 'balance' ) as $key ) : ?><td><?php echo esc_html( self::amount( $item[$key] ) ); ?></td><?php endforeach; ?><td><?php echo $item['is_due'] ? (int) $item['age'] . ' يومًا' : 'مستقبلي'; ?></td></tr><?php endforeach; ?></tbody></table>
        <p>طلاب النقل الفعّالون: <?php echo esc_html( implode( '، ', array_column( $row['transport'], 'student_name' ) ) ?: 'لا يوجد اشتراك فعّال مؤكد' ); ?></p>
        <?php if ( $review ) : ?><p>آخر تحقق: <?php echo esc_html( $review['updated_at'] ?? '' ); ?> — صالح حتى <?php echo esc_html( $review['until'] ?? '' ); ?><br><?php echo esc_html( $review['note'] ?? '' ); ?></p><?php endif; ?>
        <form method="post" class="omcr-review"><?php wp_nonce_field( 'olama_msg_collection_write' ); ?><input type="hidden" name="collection_operation" value="review"><input type="hidden" name="study_year" value="<?php echo esc_attr( $year ); ?>"><input type="hidden" name="family_id" value="<?php echo esc_attr( $row['oracle_family_id'] ); ?>">
        <label>نتيجة التحقق<select name="review_status"><?php foreach ( array( 'unverified' => 'لم يكتمل التحقق', 'clear' => 'تم التحقق: لا استثناء ولا اتفاق سداد ملتزم به', 'exception' => 'استثناء معتمد', 'arrangement' => 'اتفاق سداد معتمد وملتزم به' ) as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $review['status'] ?? 'unverified', $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
        <label>صالح حتى<input type="date" name="review_until" min="<?php echo esc_attr( $today ); ?>" value="<?php echo esc_attr( ( $review['until'] ?? '' ) >= $today ? $review['until'] : $today ); ?>" required></label>
        <label>القسط المعتاد يدويًا — صفر للحساب التلقائي<input type="number" name="typical_override" min="0" max="1000000" step="0.001" value="<?php echo esc_attr( $review['typical_override'] ?? 0 ); ?>"></label>
        <label>سبب التحقق / مرجع الاعتماد<textarea name="review_note" maxlength="2000" required><?php echo esc_textarea( $review['note'] ?? '' ); ?></textarea></label><button class="button">حفظ التحقق</button></form>
        </div></details><?php
    }
}
