<?php
/**
 * Focused renderers for the redesigned Messages operations experience.
 *
 * @package Olama_Messages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Olama_Messages_Modern_Admin {
	private $plugin;

	public function __construct( Olama_Messages_Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	private function header( $title, $description = '', $action = '' ) {
		echo '<div class="wrap olama-msg-app"><div class="omsg-page-head"><div><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $description ) . '</p></div>' . $action . '</div>';
	}

	private function status( $status ) {
        return '<span class="omsg-status omsg-status--' . esc_attr(sanitize_html_class($status)) . '">' . esc_html($this->status_label($status)) . '</span>';
	}

	private function print_flash() {
		$key   = 'olama_msg_flash_' . get_current_user_id();
		$flash = get_transient( $key );
		if ( ! is_array( $flash ) || empty( $flash['message'] ) ) {
			return;
		}

		delete_transient( $key );
		$type = in_array( $flash['type'] ?? '', array( 'success', 'error', 'warning', 'info' ), true )
			? $flash['type']
			: 'info';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $flash['message'] ) . '</p></div>';
	}

	private function post_button( $action, $campaign_id, $label, $class = 'button', $confirmation = '' ) {
		$url = admin_url( 'admin-post.php' );
		$nonce_actions = array(
			'olama_msg_delete_campaign'  => 'olama_msg_delete_',
			'olama_msg_archive_campaign' => 'olama_msg_archive_',
		);
		if ( ! isset( $nonce_actions[ $action ] ) ) {
			return '';
		}
		$nonce_action = $nonce_actions[ $action ] . $campaign_id;
		$confirm_attr = '' !== $confirmation ? ' onsubmit="return window.confirm(\'' . esc_js( $confirmation ) . '\');"' : '';
		return '<form class="omsg-inline-form" method="post" action="' . esc_url( $url ) . '"' . $confirm_attr . '><input type="hidden" name="action" value="' . esc_attr( $action ) . '"><input type="hidden" name="campaign_id" value="' . absint( $campaign_id ) . '">' . wp_nonce_field( $nonce_action, '_wpnonce', true, false ) . '<button class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

    private function agent_card() {
        $agent = $this->plugin->agents()->get_ready_dispatcher_agent();
        if ( ! $agent ) { foreach ( $this->plugin->agents()->list_agents( array( 'orderby' => 'last_seen_at', 'order' => 'DESC', 'limit' => 5 ) ) as $candidate ) { if ( 'revoked' !== $candidate['status'] ) { $agent = $candidate; break; } } }
        $href = Olama_Messages_Workspace::url( 'olama-messages-agents', $agent ? array( 'action' => 'view', 'agent_id' => $agent['id'] ) : array() );
        ?><section class="omw-panel omw-device"><div class="omw-device-heading"><span class="omw-device-icon dashicons dashicons-desktop"></span><div><h2><?php echo esc_html( $agent['agent_name'] ?? __( 'Sending agent', 'olama-messages' ) ); ?></h2><p><?php echo esc_html( $agent['machine_name'] ?? __( 'Connect a Windows sending computer', 'olama-messages' ) ); ?></p></div></div><dl><div><dt><?php esc_html_e( 'Phone', 'olama-messages' ); ?></dt><dd><?php echo esc_html( $agent['kde_device_name'] ?? '—' ); ?></dd></div><div><dt><?php esc_html_e( 'Last reported connection', 'olama-messages' ); ?></dt><dd><?php echo esc_html( ! empty( $agent['kde_device_reachable'] ) ? __( 'Reachable', 'olama-messages' ) : ( $agent ? __( 'Not connected', 'olama-messages' ) : Olama_Messages_Workspace::text('Not reported','غير مبلغ عنه') ) ); ?></dd></div><div><dt><?php esc_html_e( 'Target cadence', 'olama-messages' ); ?></dt><dd><?php echo (int) Olama_Messages_Dispatcher_Service::config()['min_seconds_between_sms']; ?> <?php esc_html_e( 'seconds', 'olama-messages' ); ?></dd></div><div><dt><?php esc_html_e( 'Last heartbeat', 'olama-messages' ); ?></dt><dd><?php echo esc_html( Olama_Messages_Agent_Dashboard::age_label( Olama_Messages_Agent_Dashboard::utc_timestamp( $agent['last_seen_at'] ?? '' ) ) ); ?></dd></div></dl><p class="omw-caption"><?php echo esc_html(Olama_Messages_Workspace::text('Readiness is assessed from the latest heartbeat and worker telemetry.','تقيّم الجاهزية وفق آخر اتصال وبيانات تشغيل الوكيل.')); ?></p><a class="omw-link" href="<?php echo esc_url( $href ); ?>"><?php esc_html_e( 'Open agent workspace', 'olama-messages' ); ?> ↗</a></section><?php
    }
    public function overview() {
        $ops = $this->plugin->operations(); $data = $ops->workspace_summary(); $ready = $ops->readiness();
        $year = (string) $this->plugin->provider()->get_current_study_year();
        $available_years = $this->plugin->provider()->get_available_study_years();
        $requested_year = sanitize_text_field(wp_unslash($_GET['study_year'] ?? ''));
        if(in_array($requested_year,$available_years,true)) { $year=$requested_year; }
        $dashboard = $ops->command_center( $year );
        $recent = $this->plugin->campaigns()->list_campaigns( array( 'limit' => 7, 'study_year' => $year ) );
        $campaign = $data['campaign'] ?: ( $recent[0] ?? null );
        $t = array( 'Olama_Messages_Workspace', 'text' );
        $this->header( $t('Communicate with everyone, clearly.','التواصل مع الجميع، بكل وضوح.'), $t('A clear view of school communication, with the next action always in reach.','ملخص عملي يساعدك على متابعة الرسائل واتخاذ القرار في ثوانٍ.'), '<a class="button button-primary" href="' . esc_url( Olama_Messages_Workspace::url( 'olama-messages-new-campaign' ) ) . '">+ ' . esc_html($t('Create campaign','إنشاء حملة جديدة')) . '</a>' );
        $this->print_flash();
        $healthy = $ready['agent_ready'] && $ready['core_ready'];
        $detail_url = Olama_Messages_Workspace::url( $campaign ? 'olama-messages-campaign-progress' : 'olama-messages-new-campaign', $campaign ? array('campaign_id'=>$campaign['id']) : array() );
        $sent = 0; $total = 0;
        if ( $campaign ) { global $wpdb; $totals = $wpdb->get_row( $wpdb->prepare("SELECT COUNT(*) AS total,SUM(status='sent') AS submitted FROM {$wpdb->prefix}olama_msg_queue WHERE campaign_id=%d", $campaign['id']), ARRAY_A ); $sent = (int)$totals['submitted']; $total = (int)$totals['total']; }
        $pct = $total ? round($sent / $total * 100) : 0;
        ?>
        <form class="omw-year-filter" method="get"><input type="hidden" name="page" value="olama-messages"><label for="omw-year"><?php echo esc_html($t('Academic year','العام الدراسي')); ?></label><select id="omw-year" name="study_year"><?php foreach($available_years as $item) : ?><option <?php selected($year,$item); ?>><?php echo esc_html($item); ?></option><?php endforeach; ?></select><button class="button"><?php echo esc_html($t('Apply','تطبيق')); ?></button></form>
        <section class="omw-hero" aria-labelledby="omw-hero-title">
            <div class="omw-hero-copy"><a class="omw-health-chip" href="<?php echo esc_url(Olama_Messages_Workspace::url('olama-messages-agents')); ?>"><i class="omw-dot <?php echo $healthy ? '' : 'is-warning'; ?>"></i><?php echo esc_html($healthy ? $t('Systems ready','أنظمة الإرسال جاهزة') : $t('Readiness needs review','جاهزية الإرسال تحتاج مراجعة')); ?></a>
            <h2 id="omw-hero-title"><?php echo esc_html($campaign ? $t('Your latest campaign.','رسالتك الأخيرة.') : $t('Your next school update.','رسالتك القادمة.')); ?><br><span><?php echo esc_html($campaign ? $campaign['title'] : $t('Start with a clear message.','تبدأ برسالة واضحة.')); ?></span></h2>
            <p><?php echo esc_html($campaign ? sprintf($t('%s messages submitted to KDE. Recipient delivery confirmation is unavailable.','تم تقديم %s رسالة إلى KDE. تأكيد وصول الرسالة إلى المستلم غير متاح.'),number_format_i18n($sent)) : $t('Choose your audience, write your message, then review before authorizing.','اختر الجمهور، واكتب رسالتك، ثم راجعها قبل اعتماد الإرسال.')); ?></p>
            <a class="button" href="<?php echo esc_url($detail_url); ?>"><?php echo esc_html($campaign ? $t('View campaign results','استعراض نتائج الحملة') : $t('Create campaign','إنشاء حملة جديدة')); ?> ↗</a></div>
            <div class="omw-hero-snapshot"><div class="omw-panel-top"><span class="omw-eyebrow"><?php echo esc_html($t('LATEST CAMPAIGN','آخر حملة')); ?></span><bdi>#<?php echo $campaign ? (int)$campaign['id'] : '—'; ?></bdi></div><h3><?php echo esc_html($campaign ? $campaign['title'] : $t('No campaigns yet','لا توجد حملات بعد')); ?></h3>
            <ol class="omw-pipeline"><li><span class="dashicons dashicons-clipboard"></span><?php echo esc_html($t('Preparation','التجهيز')); ?><small><?php echo esc_html($campaign ? $this->pipeline_label($campaign) : '—'); ?></small></li><li><span class="dashicons dashicons-cloud"></span>KDE<small><bdi><?php echo number_format_i18n($sent); ?> / <?php echo number_format_i18n($total); ?></bdi></small></li><li class="is-unavailable"><span class="dashicons dashicons-smartphone"></span><?php echo esc_html($t('Recipient delivery','هاتف المستلم')); ?><small><?php echo esc_html($t('Unconfirmed','غير مؤكد')); ?></small></li></ol>
            <div class="omw-progress" role="progressbar" aria-label="<?php echo esc_attr($t('KDE submissions','التقديم إلى KDE')); ?>" aria-valuenow="<?php echo (int)$pct; ?>" aria-valuemin="0" aria-valuemax="100"><span style="width:<?php echo (int)$pct; ?>%"></span></div><p><?php echo (int)$pct; ?>% <?php echo esc_html($t('submitted to KDE','مقدمة إلى KDE')); ?></p></div>
        </section>
        <div class="omsg-stat-grid omw-metrics">
        <?php foreach (array(
            array($t('Submitted to KDE today','المقدمة إلى KDE اليوم'),$dashboard['submitted'],$t('Unique messages · school timezone','رسائل فريدة · توقيت المدرسة'),'email-alt','olama-messages-delivery','sent'),
            array($t('Dispatch attempts today','محاولات الإرسال اليوم'),$dashboard['today_attempts'],$t('Recorded dispatch attempts, including retries','محاولات مسجلة، تشمل إعادة المحاولة'),'update','olama-messages-delivery',''),
            array($t('Messages requiring attention','رسائل تحتاج تدخلاً'),$data['review']+$dashboard['failed'],$t('Failed or uncertain outcomes · all years','نتائج فاشلة أو غير مؤكدة · جميع الأعوام'),'warning','olama-messages-delivery',''),
            array($t('Campaigns this academic year','الحملات خلال العام'),$dashboard['campaigns'],$year ?: $t('All academic years','جميع الأعوام'),'portfolio','olama-messages-campaigns','')
        ) as $metric) : ?><a class="omsg-stat" href="<?php echo esc_url(Olama_Messages_Workspace::url($metric[4],array('status'=>$metric[5],'study_year'=>$metric[4]==='olama-messages-campaigns' ? $year : ''))); ?>"><span class="omw-metric-icon dashicons dashicons-<?php echo esc_attr($metric[3]); ?>"></span><span><?php echo esc_html($metric[0]); ?></span><strong><?php echo number_format_i18n($metric[1]); ?></strong><small><?php echo esc_html($metric[2]); ?></small></a><?php endforeach; ?>
        </div>
        <div class="omw-columns"><div class="omw-stack">
        <section class="omw-panel"><div class="omw-panel-top"><div><span class="omw-eyebrow">PERFORMANCE</span><h2><?php echo esc_html($t('Campaign activity','نشاط الحملات')); ?></h2></div><span class="omw-caption"><?php echo esc_html($t('Latest 7 prepared campaigns','آخر ٧ حملات مجهزة')); ?></span></div><p class="omw-caption"><?php echo esc_html($t('Current KDE submissions for each campaign.','عدد الرسائل المقدمة إلى KDE لكل حملة.')); ?></p>
        <div class="omw-chart" aria-label="<?php echo esc_attr($t('KDE submissions by campaign','التقديم إلى KDE حسب الحملة')); ?>">
        <?php $max = max(array_merge(array(1),array_map('intval',array_column($dashboard['trend'],'submitted')))); foreach($dashboard['trend'] as $point) : ?><a class="omw-chart-column" href="<?php echo esc_url(Olama_Messages_Workspace::url('olama-messages-campaign-progress',array('campaign_id'=>$point['id']))); ?>" title="<?php echo esc_attr($point['title'].' · '.number_format_i18n($point['submitted'])); ?>"><strong><?php echo number_format_i18n($point['submitted']); ?></strong><span class="omw-chart-track"><i style="height:<?php echo round((int)$point['submitted']/$max*100); ?>%"></i></span><small><?php echo esc_html(wp_date('d/m',strtotime($point['created_at'].' UTC'))); ?></small><span class="screen-reader-text"><?php echo esc_html($point['title']); ?></span></a><?php endforeach; ?>
        <?php if(!$dashboard['trend']) : ?><p class="omw-caption"><?php echo esc_html($t('Activity will appear when you create a campaign.','سيظهر النشاط عند إنشاء حملة.')); ?></p><?php endif; ?></div><a class="omw-link" href="<?php echo esc_url(Olama_Messages_Workspace::url('olama-messages-campaigns')); ?>"><?php echo esc_html($t('All campaigns','كل الحملات')); ?> ↗</a></section>
        <section class="omsg-panel omw-recent-campaigns"><div class="omsg-panel-head"><h2><?php echo esc_html($t('Recent campaigns','أحدث الحملات')); ?></h2><a href="<?php echo esc_url(Olama_Messages_Workspace::url('olama-messages-campaigns')); ?>"><?php echo esc_html($t('View all','عرض الكل')); ?> ↗</a></div><div class="omw-recent-list"><?php foreach(array_slice($recent,0,4) as $item) : $href=Olama_Messages_Workspace::url($item['status']==='draft' || $item['status']==='prepared' ? 'olama-messages-new-campaign' : 'olama-messages-campaign-progress',array('campaign_id'=>$item['id'],'step'=>$item['status']==='prepared' ? 5 : 1)); ?><a href="<?php echo esc_url($href); ?>"><span class="omw-metric-icon dashicons dashicons-<?php echo $item['status']==='draft' ? 'edit' : 'calendar-alt'; ?>"></span><span class="omw-recent-title"><strong><?php echo esc_html($item['title']); ?></strong><small><?php echo esc_html($this->audience_label($item['target_type'])); ?> · <bdi>#<?php echo (int)$item['id']; ?></bdi> · <?php echo esc_html(wp_date('d/m/Y',strtotime($item['created_at'].' UTC'))); ?></small></span><span class="omw-recent-state"><?php echo $this->status($item['status']); ?><small><?php echo number_format_i18n($item['total_included']); ?> <?php echo esc_html($t('included','مضمن')); ?> · <?php echo number_format_i18n($item['total_excluded']); ?> <?php echo esc_html($t('excluded','مستبعد')); ?></small></span><span aria-hidden="true">↗</span></a><?php endforeach; ?><?php if(!$recent) : ?><p class="omw-caption"><?php echo esc_html($t('No campaigns yet. Create your first campaign to get started.','لا توجد حملات بعد. أنشئ حملتك الأولى للبدء.')); ?></p><?php endif; ?></div></section></div>
        <aside class="omw-stack"><section class="omw-panel omw-attention"><span class="omw-attention-icon dashicons dashicons-<?php echo $data['review']+$dashboard['failed'] ? 'warning' : 'yes-alt'; ?>"></span><h2><?php echo esc_html($t('What needs your attention?','ما الذي يحتاج اهتمامك؟')); ?></h2>
        <ul class="omw-attention-list"><li><a href="<?php echo esc_url(Olama_Messages_Workspace::url('olama-messages-delivery',array('status'=>'unknown'))); ?>"><?php echo esc_html($t('Uncertain outcomes','نتائج تحتاج مراجعة')); ?><strong><?php echo number_format_i18n($data['review']); ?></strong></a></li><li><a href="<?php echo esc_url(Olama_Messages_Workspace::url('olama-messages-delivery',array('status'=>'failed'))); ?>"><?php echo esc_html($t('Failed submissions','محاولات فاشلة')); ?><strong><?php echo number_format_i18n($dashboard['failed']); ?></strong></a></li><li><a href="<?php echo esc_url(Olama_Messages_Workspace::url('olama-messages-campaigns',array('status'=>'prepared'))); ?>"><?php echo esc_html($t('Awaiting authorization','بانتظار الاعتماد')); ?><strong><?php echo number_format_i18n($data['prepared']); ?></strong></a></li></ul>
        <?php if(!$healthy) : ?><p class="omw-caption"><?php echo esc_html($t('Check agent and school-data readiness before sending.','راجع جاهزية الوكيل وبيانات المدرسة قبل الإرسال.')); ?></p><?php endif; ?>
        <?php if($campaign && (int)$campaign['total_excluded']) : ?><a class="omw-exclusion-note" href="<?php echo esc_url(Olama_Messages_Workspace::url('olama-messages-recipients',array('campaign_id'=>$campaign['id'],'show_excluded'=>1))); ?>"><?php echo number_format_i18n($campaign['total_excluded']); ?> <?php echo esc_html($t('excluded recipients · review reasons','مستلماً مستبعداً · مراجعة الأسباب')); ?> ↗</a><?php endif; ?></section><?php $this->agent_card(); ?></aside></div></div><?php
    }

    private function pipeline_label( array $campaign ) {
        return !empty($campaign['prepared_at']) || !empty($campaign['total_prepared']) ? Olama_Messages_Workspace::text('Prepared','مجهزة') : $this->status_label($campaign['status']);
    }

    public function campaigns() {
        $status = sanitize_key($_GET['status'] ?? '');
        $search = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $audience = sanitize_key($_GET['audience'] ?? '');
        $study_year = sanitize_text_field(wp_unslash($_GET['study_year'] ?? ''));
        $sort = sanitize_key($_GET['sort'] ?? 'created_at');
        $order = 'ASC' === ($_GET['order'] ?? '') ? 'ASC' : 'DESC';
        $page = max(1,absint($_GET['paged'] ?? 1)); $limit = 25;
        $rows = $this->plugin->campaigns()->list_campaigns(array('status'=>$status,'search'=>$search,'study_year'=>$study_year,'target_type'=>$audience,'orderby'=>$sort,'order'=>$order,'offset'=>($page-1)*$limit,'limit'=>$limit+1));
        $has_next = count($rows)>$limit; $rows = array_slice($rows,0,$limit);
        $t = array('Olama_Messages_Workspace','text');
        $this->header($t('Campaigns','الحملات'),$t('Continue a draft, review recipients, or follow KDE submissions.','تابع المسودات، وراجع المستلمين، وراقب التقديم إلى KDE.'),'<a class="button button-primary" href="'.esc_url(Olama_Messages_Workspace::url('olama-messages-new-campaign')).'">+ '.esc_html($t('Create campaign','إنشاء حملة جديدة')).'</a>');
        $this->print_flash(); ?>
        <form class="omsg-filterbar" data-workspace-filter method="get"><input type="hidden" name="page" value="olama-messages-campaigns">
        <label><span class="screen-reader-text"><?php echo esc_html($t('Search campaigns','البحث في الحملات')); ?></span><input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php echo esc_attr($t('Search campaign title','ابحث عن عنوان حملة')); ?>"></label>
        <label><span class="screen-reader-text"><?php echo esc_html($t('Status','الحالة')); ?></span><select name="status"><option value=""><?php echo esc_html($t('Active & recent','النشطة والحديثة')); ?></option><?php foreach(array('draft','prepared','sending','paused','completed','cancelled','archived') as $item) : ?><option value="<?php echo esc_attr($item); ?>" <?php selected($status,$item); ?>><?php echo esc_html($this->status_label($item)); ?></option><?php endforeach; ?></select></label>
        <label><span class="screen-reader-text"><?php echo esc_html($t('Audience','الجمهور')); ?></span><select name="audience"><option value=""><?php echo esc_html($t('All audiences','كل شرائح الجمهور')); ?></option><?php foreach(array('academic','collection','renewal_reminder','finance_outstanding','finance_agreements_collection','finance_renewal_reminder','transport_registered','transport_no_gps','store_missing','direct','general','transportation') as $type) : ?><option value="<?php echo esc_attr($type); ?>" <?php selected($audience,$type); ?>><?php echo esc_html($this->audience_label($type)); ?></option><?php endforeach; ?></select></label>
        <label><span class="screen-reader-text"><?php echo esc_html($t('Sort by','الترتيب حسب')); ?></span><select name="sort"><?php foreach(array('created_at'=>$t('Creation date','تاريخ الإنشاء'),'title'=>$t('Title','العنوان'),'status'=>$t('Status','الحالة')) as $key=>$label) : ?><option value="<?php echo esc_attr($key); ?>" <?php selected($sort,$key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label><label><span class="screen-reader-text"><?php echo esc_html($t('Sort direction','اتجاه الترتيب')); ?></span><select name="order"><option value="DESC" <?php selected($order,'DESC'); ?>><?php echo esc_html($t('Descending','تنازلي')); ?></option><option value="ASC" <?php selected($order,'ASC'); ?>><?php echo esc_html($t('Ascending','تصاعدي')); ?></option></select></label>
        <label><span class="screen-reader-text"><?php echo esc_html($t('Academic year','العام الدراسي')); ?></span><select name="study_year"><option value=""><?php echo esc_html($t('All academic years','كل الأعوام الدراسية')); ?></option><?php foreach($this->plugin->provider()->get_available_study_years() as $year) : ?><option <?php selected($study_year,$year); ?>><?php echo esc_html($year); ?></option><?php endforeach; ?></select></label>
        <button class="button"><?php echo esc_html($t('Apply filters','تطبيق الفلاتر')); ?></button></form>
        <section class="omsg-panel"><?php $this->campaign_table($rows,true); ?>
        <nav class="omw-pagination" aria-label="<?php echo esc_attr($t('Campaign pages','صفحات الحملات')); ?>"><span><?php echo esc_html(sprintf($t('Page %d · up to 25 campaigns per page','الصفحة %d · حتى ٢٥ حملة لكل صفحة'),$page)); ?></span><div><?php $query = array('status'=>$status,'s'=>$search,'study_year'=>$study_year,'audience'=>$audience,'sort'=>$sort,'order'=>$order); if($page>1) : ?><a class="button" href="<?php echo esc_url(Olama_Messages_Workspace::url('olama-messages-campaigns',array_merge($query,array('paged'=>$page-1)))); ?>"><?php echo esc_html($t('Previous','السابق')); ?></a><?php endif; if($has_next) : ?><a class="button" href="<?php echo esc_url(Olama_Messages_Workspace::url('olama-messages-campaigns',array_merge($query,array('paged'=>$page+1)))); ?>"><?php echo esc_html($t('Next','التالي')); ?></a><?php endif; ?></div></nav></section></div><?php
    }

    private function audience_label($type) {
        $labels = array('academic'=>'أكاديمي','general'=>'عام','collection'=>'مطالبات مالية','renewal_reminder'=>'تذكير بالتجديد','finance_outstanding'=>'الأرصدة المستحقة','finance_agreements_collection'=>'تحصيل الاتفاقيات','finance_renewal_reminder'=>'تذكير بالتجديد المالي','transport_registered'=>'المسجلون في النقل','transport_no_gps'=>'النقل دون GPS','store_missing'=>'مستلزمات لم تستلم','direct'=>'رسالة مباشرة','transportation'=>'النقل');
        return Olama_Messages_Workspace::text(ucwords(str_replace('_',' ',$type)),$labels[$type] ?? $type);
    }

    private function status_label($status) {
        $en = array('prepared'=>'Ready to authorize','sent'=>'Submitted via KDE','unknown'=>'Review needed','retry_wait'=>'Retry scheduled','reserved'=>'In progress','completed'=>'KDE submission complete','completed_with_errors'=>'Finished with errors');
        $ar = array('draft'=>'مسودة','prepared'=>'جاهزة للاعتماد','sending'=>'قيد الإرسال','paused'=>'متوقفة مؤقتاً','completed'=>'اكتمل التقديم إلى KDE','completed_with_errors'=>'انتهت مع أخطاء','cancelled'=>'ملغاة','archived'=>'مؤرشفة','sent'=>'مقدمة إلى KDE','unknown'=>'تحتاج مراجعة','retry_wait'=>'إعادة محاولة مجدولة','reserved'=>'قيد المعالجة','failed'=>'فشل التقديم');
        return Olama_Messages_Workspace::text($en[$status] ?? ucwords(str_replace('_',' ',$status)),$ar[$status] ?? $status);
    }

    /** Frozen exclusions are separate from queue failures and never re-resolved. */
    public function campaign_exclusions( int $campaign_id ) {
        $campaign = $this->plugin->campaigns()->get_campaign($campaign_id);
        if (!$campaign) { wp_die(esc_html__('Campaign not found.','olama-messages')); }
        global $wpdb;
        $table=$wpdb->prefix.'olama_msg_campaign_recipients';
        $page=max(1,absint($_GET['paged'] ?? 1));
        $search=sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $where=$wpdb->prepare('campaign_id=%d AND included=0',$campaign_id);
        if ($search !== '') { $term='%'.$wpdb->esc_like($search).'%'; $where.=$wpdb->prepare(' AND (recipient_name LIKE %s OR excluded_reason LIKE %s)',$term,$term); }
        $total=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where}");
        $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT id,recipient_name,recipient_type,phone_e164,excluded_reason FROM {$table} WHERE {$where} ORDER BY id ASC LIMIT 25 OFFSET %d",($page-1)*25),ARRAY_A);
        $t=array('Olama_Messages_Workspace','text');
        $this->header($t('Excluded recipients','المستلمون المستبعدون'),$campaign['title'],'<a class="button" href="'.esc_url(Olama_Messages_Workspace::url('olama-messages-campaign-progress',array('campaign_id'=>$campaign_id))).'">'.esc_html($t('Back to campaign','العودة إلى الحملة')).'</a>');
        ?>
        <p class="omw-delivery-note"><?php echo esc_html($t('Saved audience exclusions are shown here. These recipients were not queued and are separate from failed submissions. Draft eligibility is calculated during review.','تعرض هنا الاستبعادات المحفوظة عند التجهيز. لم يدرج هؤلاء المستلمون في قائمة الإرسال، وهم منفصلون عن محاولات التقديم الفاشلة. تحتسب أهلية المسودة عند المراجعة.')); ?></p>
        <form class="omsg-filterbar" data-workspace-filter method="get"><input type="hidden" name="page" value="olama-messages-recipients"><input type="hidden" name="campaign_id" value="<?php echo (int)$campaign_id; ?>"><label><span class="screen-reader-text"><?php echo esc_html($t('Search exclusions','البحث في الاستبعادات')); ?></span><input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php echo esc_attr($t('Name or exclusion reason','الاسم أو سبب الاستبعاد')); ?>"></label><button class="button"><?php echo esc_html($t('Search','بحث')); ?></button></form>
        <section class="omw-panel"><div class="omsg-table-scroll"><table class="widefat omsg-table"><thead><tr><th><?php echo esc_html($t('Recipient','المستلم')); ?></th><th><?php echo esc_html($t('Phone','الهاتف')); ?></th><th><?php echo esc_html($t('Exclusion reason','سبب الاستبعاد')); ?></th></tr></thead><tbody><?php foreach($rows as $row) : ?><tr><td><?php echo esc_html($row['recipient_name'] ?: '—'); ?><small><bdi>#<?php echo (int)$row['id']; ?></bdi> · <?php echo esc_html($row['recipient_type']); ?></small></td><td><bdi><?php echo esc_html($row['phone_e164'] ? substr($row['phone_e164'],0,5).'•••'.substr($row['phone_e164'],-3) : '—'); ?></bdi></td><td><?php echo esc_html($row['excluded_reason'] ?: $t('Reason unavailable','السبب غير متاح')); ?></td></tr><?php endforeach; if(!$rows) : ?><tr><td colspan="3"><?php echo esc_html($t('No saved exclusions match these filters.','لا توجد استبعادات محفوظة تطابق هذه الفلاتر.')); ?></td></tr><?php endif; ?></tbody></table></div>
        <nav class="omw-pagination" aria-label="<?php echo esc_attr($t('Exclusion pages','صفحات الاستبعادات')); ?>"><span><?php echo number_format_i18n($total); ?> <?php echo esc_html($t('matching exclusions','استبعاداً مطابقاً')); ?></span><div><?php foreach(array($page-1=>$t('Previous','السابق'),$page+1=>$t('Next','التالي')) as $number=>$label) { if($number<1 || $number>max(1,ceil($total/25))) { continue; } ?><a class="button" href="<?php echo esc_url(Olama_Messages_Workspace::url('olama-messages-recipients',array('campaign_id'=>$campaign_id,'s'=>$search,'paged'=>$number))); ?>"><?php echo esc_html($label); ?></a><?php } ?></div></nav></section></div><?php
    }

	private function campaign_table( $rows, $actions = true ) {
		?>
		<div class="omsg-table-scroll"><table class="widefat striped omsg-table"><thead><tr><th><?php echo esc_html(Olama_Messages_Workspace::text('Campaign','الحملة')); ?></th><th><?php echo esc_html(Olama_Messages_Workspace::text('Audience','الجمهور')); ?></th><th><?php echo esc_html(Olama_Messages_Workspace::text('Status','الحالة')); ?></th><th><?php echo esc_html(Olama_Messages_Workspace::text('Messages','الرسائل')); ?></th><th><?php echo esc_html(Olama_Messages_Workspace::text('Created','الإنشاء')); ?></th><?php if ( $actions ) : ?><th><?php echo esc_html(Olama_Messages_Workspace::text('Next action','الإجراء التالي')); ?></th><?php endif; ?></tr></thead><tbody>
		<?php if ( empty( $rows ) ) : ?><tr><td colspan="6"><?php echo esc_html(Olama_Messages_Workspace::text('No campaigns match these filters.','لا توجد حملات تطابق هذه الفلاتر.')); ?></td></tr><?php endif; ?>
		<?php foreach ( $rows as $row ) : ?>
			<tr><td><strong><?php echo esc_html( $row['title'] ?: 'Untitled campaign' ); ?></strong><small>#<?php echo absint( $row['id'] ); ?> · <?php echo esc_html( $row['study_year'] ); ?></small></td>
			<td><?php echo esc_html( $this->audience_label($row['target_type']) ); ?></td>
			<td><?php echo $this->status( $row['status'] ); ?></td>
			<td><?php echo absint( $row['total_included'] ); ?> included<br><small><?php echo absint( $row['total_excluded'] ); ?> excluded</small></td>
			<td><?php echo esc_html( $row['created_at'] ); ?></td>
            <?php if ( $actions ) : ?><td class="omsg-actions">
                <?php if ( 'draft' === $row['status'] ) : ?><a class="button button-primary" href="<?php echo esc_url( Olama_Messages_Workspace::url( 'olama-messages-new-campaign', array( 'campaign_id' => $row['id'] ) ) ); ?>"><?php esc_html_e( 'Continue draft', 'olama-messages' ); ?></a><?php elseif ( 'prepared' === $row['status'] ) : ?><a class="button button-primary" href="<?php echo esc_url( Olama_Messages_Workspace::url( 'olama-messages-new-campaign', array( 'campaign_id' => $row['id'], 'step' => 5 ) ) ); ?>"><?php esc_html_e( 'Review & authorize', 'olama-messages' ); ?></a><?php else : ?><a class="button" href="<?php echo esc_url( Olama_Messages_Workspace::url( 'olama-messages-campaign-progress', array( 'campaign_id' => $row['id'] ) ) ); ?>"><?php esc_html_e( 'View delivery', 'olama-messages' ); ?></a><?php endif; ?>
                <details class="omw-row-menu"><summary><?php esc_html_e( 'More', 'olama-messages' ); ?></summary>
                <?php if(!empty($row['total_excluded'])) : ?><a class="button button-small" href="<?php echo esc_url(Olama_Messages_Workspace::url('olama-messages-recipients',array('campaign_id'=>$row['id']))); ?>"><?php echo esc_html(Olama_Messages_Workspace::text('Inspect exclusions','مراجعة الاستبعادات')); ?></a><?php endif; ?>
                <?php if ( in_array( $row['status'], array( 'draft','prepared','sending','paused','cancelled' ), true ) ) { echo $this->post_button( 'olama_msg_delete_campaign', $row['id'], __( 'Delete campaign', 'olama-messages' ), 'button button-small button-link-delete', 'Permanently delete this unfinished campaign and its queued messages?' ); } ?>
                <?php if ( in_array( $row['status'], array( 'completed','completed_with_errors' ), true ) ) { echo $this->post_button( 'olama_msg_archive_campaign', $row['id'], __( 'Archive', 'olama-messages' ), 'button button-small', 'Archive this finished campaign? Its delivery history will be retained.' ); } ?>
                <?php if ( 'archived' === $row['status'] ) : ?><small><?php esc_html_e( 'Delivery history retained.', 'olama-messages' ); ?></small><?php endif; ?>
                </details>
			</td><?php endif; ?></tr>
		<?php endforeach; ?></tbody></table></div>
		<?php
	}

	public function wizard() {
		$id = absint( $_GET['campaign_id'] ?? 0 );
		$campaign = $id ? $this->plugin->campaigns()->get_campaign( $id ) : null;
		$step = max( 1, min( 5, absint( $_GET['step'] ?? ( $campaign['filters']['ui_step'] ?? 1 ) ) ) );
		$years = $this->plugin->provider()->get_available_study_years();
		$templates = $this->plugin->templates()->list_templates( array( 'channel' => 'sms', 'is_active' => 1 ) );
		$prefill = ! $campaign && ! empty( $_GET['template_id'] ) ? $this->plugin->templates()->get_template( absint( $_GET['template_id'] ) ) : null;
        if ( $prefill && ( empty( $prefill['is_active'] ) || 'sms' !== ( $prefill['channel'] ?? 'sms' ) ) ) { $prefill = null; }
        $body = $campaign ? $this->plugin->campaigns()->get_effective_message_body( $campaign ) : ( $prefill['body'] ?? '' );
		$this->header( Olama_Messages_Workspace::label($campaign ? 'Edit Campaign' : 'Create Campaign'), Olama_Messages_Workspace::text('Three stages: audience, message, review and authorization. Drafts save automatically.','ثلاث مراحل: الجمهور، الرسالة، ثم المراجعة والاعتماد. تحفظ المسودات تلقائياً.') );
		?>
		<div class="omsg-wizard" data-campaign-id="<?php echo absint( $id ); ?>" data-step="<?php echo absint( $step ); ?>" data-status="<?php echo esc_attr( $campaign['status'] ?? 'draft' ); ?>">
			<ol class="omsg-steps"><?php foreach(array(Olama_Messages_Workspace::text('Audience','الجمهور'),Olama_Messages_Workspace::text('Message','الرسالة'),Olama_Messages_Workspace::text('Review & authorization','المراجعة والاعتماد')) as $i=>$label) : $stage = $step<=2 ? 1 : ($step===3 ? 2 : 3); ?><li class="<?php echo $stage===$i+1 ? 'is-current' : ($stage>$i+1 ? 'is-done' : ''); ?>" <?php echo $stage===$i+1 ? 'aria-current="step"' : ''; ?>><span><?php echo $i+1; ?></span><?php echo esc_html($label); ?></li><?php endforeach; ?></ol>
			<div class="omsg-save-state" aria-live="polite"><?php echo esc_html(Olama_Messages_Workspace::label('Draft changes save automatically')); ?></div>
			<div class="omw-wizard-layout"><div class="omw-wizard-editor"><form id="omsg-campaign-wizard-form">
				<input type="hidden" name="campaign_id" value="<?php echo absint( $id ); ?>"><input type="hidden" name="ui_step" value="<?php echo absint( $step ); ?>">
				<section class="omsg-step-panel" data-step="1" aria-labelledby="omsg-campaign-basics-heading">
                    <div class="omsg-form-heading"><span class="omsg-form-icon dashicons dashicons-edit-page" aria-hidden="true"></span><div><h2 id="omsg-campaign-basics-heading"><?php echo esc_html(Olama_Messages_Workspace::text('Campaign details','معلومات الحملة')); ?></h2><p><?php echo esc_html(Olama_Messages_Workspace::text('Give your campaign a clear name and choose its academic year.','اختر عنوانًا واضحًا للحملة وحدد العام الدراسي.')); ?></p></div></div>
                    <div class="omsg-form-fields">
                        <label for="omsg-campaign-title"><span class="omsg-field-label"><?php echo esc_html(Olama_Messages_Workspace::text('Campaign title','عنوان الحملة')); ?><small><?php echo esc_html(Olama_Messages_Workspace::text('Required','مطلوب')); ?></small></span><input id="omsg-campaign-title" type="text" required name="title" aria-describedby="omsg-title-help" value="<?php echo esc_attr( $campaign['title'] ?? '' ); ?>" placeholder="<?php echo esc_attr(Olama_Messages_Workspace::text('e.g. November parent meeting','مثال: اجتماع أولياء الأمور — نوفمبر')); ?>"><span class="omsg-field-help" id="omsg-title-help"><?php echo esc_html(Olama_Messages_Workspace::text('An internal name to help you find this campaign later.','عنوان داخلي يساعدك على العثور على الحملة لاحقًا.')); ?></span></label>
                        <label for="omsg-campaign-year"><span class="omsg-field-label"><?php echo esc_html(Olama_Messages_Workspace::text('Study year','العام الدراسي')); ?></span><select id="omsg-campaign-year" name="study_year" aria-describedby="omsg-year-help"><?php foreach ( $years as $year ) : ?><option <?php selected( $campaign['study_year'] ?? '', $year ); ?>><?php echo esc_html( $year ); ?></option><?php endforeach; ?></select><span class="omsg-field-help" id="omsg-year-help"><?php echo esc_html(Olama_Messages_Workspace::text('Audience records are scoped to this year.','تُحدد بيانات الجمهور وفق هذا العام.')); ?></span></label>
                    </div>
                </section>
				<section class="omsg-step-panel" data-step="2">
					<h2><?php echo esc_html(Olama_Messages_Workspace::text('Choose the audience category','اختر شريحة الجمهور')); ?></h2>
					<p class="description"><?php echo esc_html(Olama_Messages_Workspace::label('Select an audience category and configure specific filters for targeted messaging.')); ?></p>

					<?php
					$requested_audience = sanitize_key($_GET['audience'] ?? '');
                    $curr_target = $campaign['target_type'] ?? (in_array($requested_audience,array('academic','finance_outstanding','transport_registered','store_missing'),true) ? $requested_audience : 'finance_outstanding');
					// Map legacy target_types to new category tabs
					if ( 'collection' === $curr_target ) { $curr_target = 'finance_outstanding'; }
					if ( 'renewal_reminder' === $curr_target ) { $curr_target = 'finance_renewal_reminder'; }
					if ( 'general' === $curr_target ) { $curr_target = 'academic'; }
					if ( 'transportation' === $curr_target ) { $curr_target = 'transport_registered'; }

					$active_cat = 'finance';
					if ( in_array( $curr_target, array( 'academic' ), true ) ) {
						$active_cat = 'academic';
					} elseif ( in_array( $curr_target, array( 'transport_no_gps', 'transport_registered' ), true ) ) {
						$active_cat = 'transportation';
					} elseif ( in_array( $curr_target, array( 'store_missing' ), true ) ) {
						$active_cat = 'store';
					}

					$saved_filters = is_array( $campaign['filters'] ?? null ) ? $campaign['filters'] : array();
					?>

					<div class="omsg-audience-cats" role="tablist">
						<button type="button" class="omsg-cat-tab <?php echo 'finance' === $active_cat ? 'is-active' : ''; ?>" data-cat="finance">
							<span class="dashicons dashicons-money-alt"></span>
							<strong><?php echo esc_html(Olama_Messages_Workspace::label('Finance')); ?></strong>
									<small><?php echo esc_html(Olama_Messages_Workspace::label('Outstanding balances')); ?></small>
						</button>
						<button type="button" class="omsg-cat-tab <?php echo 'academic' === $active_cat ? 'is-active' : ''; ?>" data-cat="academic">
							<span class="dashicons dashicons-welcome-learn-more"></span>
							<strong><?php echo esc_html(Olama_Messages_Workspace::label('Academic')); ?></strong>
							<small><?php echo esc_html(Olama_Messages_Workspace::label('School / Grade / Section')); ?></small>
						</button>
						<button type="button" class="omsg-cat-tab <?php echo 'transportation' === $active_cat ? 'is-active' : ''; ?>" data-cat="transportation">
							<span class="dashicons dashicons-car"></span>
							<strong><?php echo esc_html(Olama_Messages_Workspace::label('Transportation')); ?></strong>
							<small><?php echo esc_html(Olama_Messages_Workspace::label('GPS status & bus routes')); ?></small>
						</button>
						<button type="button" class="omsg-cat-tab <?php echo 'store' === $active_cat ? 'is-active' : ''; ?>" data-cat="store">
							<span class="dashicons dashicons-store"></span>
							<strong><?php echo esc_html(Olama_Messages_Workspace::label('Store')); ?></strong>
							<small><?php echo esc_html(Olama_Messages_Workspace::label('Pending books & uniforms')); ?></small>
						</button>
					</div>

					<!-- Category Panel: Finance -->
					<div class="omsg-cat-panel <?php echo 'finance' === $active_cat ? 'is-active' : ''; ?>" data-cat-panel="finance">
						<div class="omsg-subtarget-options">
							<label class="omsg-choice">
								<input type="radio" name="target_type" value="finance_outstanding" <?php checked( $curr_target, 'finance_outstanding' ); ?>>
								<div>
									<strong><?php echo esc_html(Olama_Messages_Workspace::label('Outstanding Balances')); ?></strong>
									<small><?php echo esc_html(Olama_Messages_Workspace::label('Target families with unpaid balances or specific financial statuses.')); ?></small>
								</div>
							</label>
							<label class="omsg-choice">
								<input type="radio" name="target_type" value="finance_agreements_collection" <?php checked( $curr_target, 'finance_agreements_collection' ); ?>>
								<div>
									<strong><?php echo esc_html(Olama_Messages_Workspace::label('Olama Agreements Collection')); ?></strong>
									<small><?php echo esc_html(Olama_Messages_Workspace::label('Families with unpaid agreement installments in the selected month.')); ?></small>
								</div>
							</label>
							<label class="omsg-choice">
								<input type="radio" name="target_type" value="finance_renewal_reminder" <?php checked( $curr_target, 'finance_renewal_reminder' ); ?>>
								<div>
									<strong><?php echo esc_html(Olama_Messages_Workspace::label('Renewal Reminder')); ?></strong>
									<small><?php echo esc_html(Olama_Messages_Workspace::label('Target families enrolled in the previous academic year who have not registered for the current academic year and were not transferred.')); ?></small>
								</div>
							</label>
						</div>

						<div class="omsg-filter-box omsg-finance-outstanding-filters <?php echo 'finance_renewal_reminder' === $curr_target ? 'is-hidden' : ''; ?>">
							<h3><?php echo esc_html(Olama_Messages_Workspace::label('Finance Audience Filters')); ?></h3>
							<p class="description"><?php echo esc_html(Olama_Messages_Workspace::label('Target families with unpaid balances or specific financial statuses.')); ?></p>
						<label><span data-finance-month-label><?php echo 'finance_agreements_collection' === $curr_target ? 'Payment due in month' : 'Payment due through month'; ?></span>
							<select name="filter_due_month" data-due-month selected-data="<?php echo esc_attr( $saved_filters['due_month'] ?? '' ); ?>"></select>
							<small data-finance-month-help><?php echo 'finance_agreements_collection' === $curr_target ? 'Includes unpaid agreement installments scheduled in the selected month.' : 'Includes all unpaid installments through the selected month, including August.'; ?></small>
							</label>
							<div class="omsg-filter-row">
								<label><span data-finance-min-label><?php echo 'finance_agreements_collection' === $curr_target ? 'Minimum Unpaid Agreement Due (JOD)' : 'Minimum Outstanding Balance (JOD)'; ?></span>
									<input type="number" step="0.001" name="filter_min_balance" value="<?php echo esc_attr( $saved_filters['min_balance'] ?? '' ); ?>" placeholder="0.000">
								</label>
								<label class="omsg-checkbox-label">
									<input type="checkbox" name="filter_exclude_credit" value="1" <?php checked( ! empty( $saved_filters['exclude_credit_balances'] ?? 1 ) ); ?>>
									Exclude families with credit balances
								</label>
								<label class="omsg-checkbox-label">
									<input type="checkbox" name="filter_exclude_zero" value="1" <?php checked( ! empty( $saved_filters['exclude_zero_balances'] ?? 1 ) ); ?>>
									Exclude families with zero balance
								</label>
							</div>
						</div>
					</div>

					<!-- Category Panel: Academic -->
					<div class="omsg-cat-panel <?php echo 'academic' === $active_cat ? 'is-active' : ''; ?>" data-cat-panel="academic">
						<input type="radio" name="target_type" value="academic" class="omsg-target-radio" <?php checked( $curr_target, 'academic' ); ?>>
						<div class="omsg-filter-box">
							<h3><?php echo esc_html(Olama_Messages_Workspace::label('Academic Filtering (School / Grade / Section)')); ?></h3>
							<p class="description"><?php echo esc_html(Olama_Messages_Workspace::label('Select a school, grade, or section to filter student families.')); ?></p>
							<div class="omsg-filter-row">
								<label><?php echo esc_html(Olama_Messages_Workspace::label('School (مدرسة / روضة)')); ?>
									<select name="filter_school_id" id="omsg-filter-school">
										<option value=""><?php echo esc_html(Olama_Messages_Workspace::label('All Schools / Kindergarten')); ?></option>
										<?php if ( ! empty( $saved_filters['school_id'] ) ) : ?>
											<option value="<?php echo esc_attr( $saved_filters['school_id'] ); ?>" selected><?php echo esc_html( $saved_filters['school_name'] ?? $saved_filters['school_id'] ); ?></option>
										<?php endif; ?>
									</select>
								</label>
								<label><?php echo esc_html(Olama_Messages_Workspace::label('Grade (الصف)')); ?>
									<select name="filter_class_id" id="omsg-filter-grade">
										<option value=""><?php echo esc_html(Olama_Messages_Workspace::label('All Grades')); ?></option>
										<?php if ( ! empty( $saved_filters['class_id'] ) ) : ?>
											<option value="<?php echo esc_attr( $saved_filters['class_id'] ); ?>" selected><?php echo esc_html( $saved_filters['class_name'] ?? $saved_filters['class_id'] ); ?></option>
										<?php endif; ?>
									</select>
								</label>
								<label><?php echo esc_html(Olama_Messages_Workspace::label('Section (الشعبة)')); ?>
									<select name="filter_section_id" id="omsg-filter-section">
										<option value=""><?php echo esc_html(Olama_Messages_Workspace::label('All Sections')); ?></option>
										<?php if ( ! empty( $saved_filters['section_id'] ) ) : ?>
											<option value="<?php echo esc_attr( $saved_filters['section_id'] ); ?>" selected><?php echo esc_html( $saved_filters['section_name'] ?? $saved_filters['section_id'] ); ?></option>
										<?php endif; ?>
									</select>
								</label>
							</div>
						</div>
					</div>

					<!-- Category Panel: Transportation -->
					<div class="omsg-cat-panel <?php echo 'transportation' === $active_cat ? 'is-active' : ''; ?>" data-cat-panel="transportation">
						<div class="omsg-subtarget-options">
							<label class="omsg-choice">
								<input type="radio" name="target_type" value="transport_no_gps" <?php checked( $curr_target, 'transport_no_gps' ); ?>>
								<div>
									<strong><?php echo esc_html(Olama_Messages_Workspace::label('Families without GPS locations')); ?></strong>
									<small><?php echo esc_html(Olama_Messages_Workspace::label('Target registered bus families who have not set coordinates in the transportation map.')); ?></small>
								</div>
							</label>
							<label class="omsg-choice">
								<input type="radio" name="target_type" value="transport_registered" <?php checked( $curr_target, 'transport_registered' ); ?>>
								<div>
									<strong><?php echo esc_html(Olama_Messages_Workspace::label('Registered transportation families')); ?></strong>
									<small><?php echo esc_html(Olama_Messages_Workspace::label('Filter bus subscribers by geographic area, bus number, or trip direction.')); ?></small>
								</div>
							</label>
						</div>

						<div class="omsg-filter-box omsg-transport-filters <?php echo 'transport_registered' === $curr_target ? '' : 'is-hidden'; ?>">
							<h3><?php echo esc_html(Olama_Messages_Workspace::label('Transportation Filters')); ?></h3>
							<div class="omsg-filter-row">
								<label>Major Area (المنطقة)
									<select name="filter_major_area_id" id="omsg-filter-area">
										<option value=""><?php echo esc_html(Olama_Messages_Workspace::label('All Areas')); ?></option>
										<?php if ( ! empty( $saved_filters['major_area_id'] ) ) : ?>
											<option value="<?php echo esc_attr( $saved_filters['major_area_id'] ); ?>" selected><?php echo esc_html( $saved_filters['major_area_name'] ?? $saved_filters['major_area_id'] ); ?></option>
										<?php endif; ?>
									</select>
								</label>
								<label>Departure Bus (حافلة الصباح / الذهاب)
									<select name="filter_departure_bus" id="omsg-filter-dep-bus">
										<option value=""><?php echo esc_html(Olama_Messages_Workspace::label('All Departure Buses')); ?></option>
										<?php if ( ! empty( $saved_filters['departure_bus'] ) ) : ?>
											<option value="<?php echo esc_attr( $saved_filters['departure_bus'] ); ?>" selected><?php echo esc_html( $saved_filters['departure_bus'] ); ?></option>
										<?php endif; ?>
									</select>
								</label>
								<label>Arrival Bus (حافلة المساء / العودة)
									<select name="filter_arrival_bus" id="omsg-filter-arr-bus">
										<option value=""><?php echo esc_html(Olama_Messages_Workspace::label('All Arrival Buses')); ?></option>
										<?php if ( ! empty( $saved_filters['arrival_bus'] ) ) : ?>
											<option value="<?php echo esc_attr( $saved_filters['arrival_bus'] ); ?>" selected><?php echo esc_html( $saved_filters['arrival_bus'] ); ?></option>
										<?php endif; ?>
									</select>
								</label>
							</div>
						</div>
					</div>

					<!-- Category Panel: Store -->
					<div class="omsg-cat-panel <?php echo 'store' === $active_cat ? 'is-active' : ''; ?>" data-cat-panel="store">
						<input type="radio" name="target_type" value="store_missing" class="omsg-target-radio" <?php checked( $curr_target, 'store_missing' ); ?>>
						<div class="omsg-filter-box">
							<h3><?php echo esc_html(Olama_Messages_Workspace::label('Store & Warehouse Audience')); ?></h3>
							<p class="description"><?php echo esc_html(Olama_Messages_Workspace::label('Target families who have NOT yet received their allocated textbooks or school uniform/custom packages for this academic year.')); ?></p>
							<div class="omsg-filter-row">
								<label><?php echo esc_html(Olama_Messages_Workspace::label('Missing Item Filter')); ?>
									<select name="filter_store_item_type" id="omsg-filter-store-item-type">
										<option value="both" <?php selected( $saved_filters['store_item_type'] ?? 'both', 'both' ); ?>><?php echo esc_html(Olama_Messages_Workspace::label('Families missing Textbooks OR Uniforms (All Unreceived)')); ?></option>
										<option value="books" <?php selected( $saved_filters['store_item_type'] ?? '', 'books' ); ?>>Families missing Textbooks only (الكتب المدرسية فقط)</option>
										<option value="custom" <?php selected( $saved_filters['store_item_type'] ?? '', 'custom' ); ?>>Families missing Uniforms / Custom Items only (الزي والزي الموحد فقط)</option>
									</select>
								</label>
							</div>
							<div class="omsg-info-banner">
								<span class="dashicons dashicons-info"></span>
								<span><?php echo esc_html(Olama_Messages_Workspace::label('Uses the authoritative Olama Stores report engine for 100% accuracy matching the Reports Center.')); ?></span>
							</div>
						</div>
					</div>

					<!-- Common Setting: Recipient Policy -->
					<div class="omsg-policy-box">
						<label><strong><?php echo esc_html(Olama_Messages_Workspace::label('Recipient Policy')); ?></strong>
							<select name="recipient_policy">
								<option value="father_first"><?php echo esc_html(Olama_Messages_Workspace::label('Father first, mother fallback')); ?></option>
								<option value="mother_first" <?php selected( $campaign['recipient_policy'] ?? '', 'mother_first' ); ?>><?php echo esc_html(Olama_Messages_Workspace::label('Mother first, father fallback')); ?></option>
								<option value="both_parents" <?php echo in_array( $campaign['recipient_policy'] ?? '', array( 'both', 'both_parents' ), true ) ? 'selected' : ''; ?>><?php echo esc_html(Olama_Messages_Workspace::label('Both parents (separate SMS)')); ?></option>
							</select>
						</label>
					</div>
				</section>
				<section class="omsg-step-panel" data-step="3"><h2><?php echo esc_html(Olama_Messages_Workspace::text('Write the message','اكتب الرسالة')); ?></h2><label><?php echo esc_html(Olama_Messages_Workspace::label('Start from a message library item')); ?><select name="template_id"><option value=""><?php echo esc_html(Olama_Messages_Workspace::label('Custom message')); ?></option><?php foreach ( $templates as $template ) : ?><option value="<?php echo absint( $template['id'] ); ?>" data-body="<?php echo esc_attr( $template['body'] ); ?>" <?php selected( $campaign['template_id'] ?? ( $prefill['id'] ?? 0 ), $template['id'] ); ?>><?php echo esc_html( $template['name'] ); ?></option><?php endforeach; ?></select></label><label><?php echo esc_html(Olama_Messages_Workspace::text('Message','الرسالة')); ?><textarea required name="message_body_draft" rows="9"><?php echo esc_textarea( $body ); ?></textarea></label><div class="omsg-message-meter"><strong data-sms-parts>0 SMS parts</strong><span data-sms-detail>0 characters</span></div><p class="description">Available fields: {sponsor_name}, {family_id}, {students}, {balance}, {monthly_due}, {payment_link}, {study_year}, {school_name}</p><p class="description">For Outstanding Balances, {balance} and {monthly_due} show unpaid installments through the selected month. For Agreements Collection, both show unpaid agreement installments in the selected month.</p></section>
				<section class="omsg-step-panel" data-step="4"><h2><?php echo esc_html(Olama_Messages_Workspace::text('Review and prepare','المراجعة والتجهيز')); ?></h2><div class="omsg-review-summary"><p><?php echo esc_html(Olama_Messages_Workspace::label('Preparation freezes the audience and rendered messages. It does not send anything.')); ?></p><button type="button" class="button" data-preview-campaign><?php echo esc_html(Olama_Messages_Workspace::label('Refresh audience preview')); ?></button><div data-preview-result aria-live="polite"></div></div></section>
				<section class="omsg-step-panel" data-step="5"><h2><?php echo esc_html(Olama_Messages_Workspace::text('Authorize sending','اعتماد الإرسال')); ?></h2>
					<?php if ( $campaign && 'prepared' === $campaign['status'] ) : ?><p><strong><?php echo absint( $campaign['total_prepared'] ); ?></strong> prepared messages are locked and awaiting authorization.</p><p>Type <code>SEND <?php echo absint( $campaign['total_prepared'] ); ?></code> to start.</p><?php else : ?><p><?php echo esc_html(Olama_Messages_Workspace::label('Review and prepare the campaign before authorization.')); ?></p><?php endif; ?>
				</section>
				<div class="omsg-wizard-actions"><button type="button" class="button" data-wizard-back><?php echo esc_html(Olama_Messages_Workspace::text('Back','السابق')); ?></button><button type="button" class="button button-primary" data-wizard-next><?php echo esc_html(Olama_Messages_Workspace::text('Save & continue','حفظ ومتابعة')); ?></button></div>
			</form>
			<?php if ( $campaign && 'prepared' === $campaign['status'] ) : ?><form class="omsg-reset-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="olama_msg_reset_campaign"><input type="hidden" name="campaign_id" value="<?php echo absint( $id ); ?>"><input type="hidden" name="redirect_step" value="4"><?php wp_nonce_field( 'olama_msg_reset_' . $id ); ?><button class="button"><?php echo esc_html(Olama_Messages_Workspace::label('Unlock & edit recipients')); ?></button><small><?php echo esc_html(Olama_Messages_Workspace::label('This removes the prepared snapshot. It does not send anything.')); ?></small></form><?php endif; ?>
			<?php if ( $campaign && 'prepared' === $campaign['status'] ) : ?><form class="omsg-authorize-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="olama_msg_start_campaign"><input type="hidden" name="campaign_id" value="<?php echo absint( $id ); ?>"><?php wp_nonce_field( 'olama_msg_start_' . $id ); ?><input name="confirmation" autocomplete="off" placeholder="SEND <?php echo absint( $campaign['total_prepared'] ); ?>"><button class="button button-primary"><?php echo esc_html(Olama_Messages_Workspace::label('Authorize & start')); ?></button></form><?php endif; ?>
            </div><aside class="omw-wizard-summary"><div><h3><?php esc_html_e( 'Campaign at a glance', 'olama-messages' ); ?></h3><dl><div><dt><?php esc_html_e( 'Title', 'olama-messages' ); ?></dt><dd data-workspace-draft-title><?php echo esc_html( $campaign['title'] ?? __( 'Untitled campaign', 'olama-messages' ) ); ?></dd></div><div><dt><?php esc_html_e( 'Prepared recipients', 'olama-messages' ); ?></dt><dd><?php echo ! empty( $campaign['total_prepared'] ) ? number_format_i18n( $campaign['total_prepared'] ) : esc_html__( 'Preview during review', 'olama-messages' ); ?></dd></div><div><dt><?php esc_html_e( 'Target interval', 'olama-messages' ); ?></dt><dd><?php echo (int) Olama_Messages_Dispatcher_Service::config()['min_seconds_between_sms']; ?> <?php esc_html_e( 'seconds', 'olama-messages' ); ?></dd></div></dl></div><p><?php esc_html_e( 'Preparation freezes the audience and message. Only explicit authorization starts sending.', 'olama-messages' ); ?></p><div class="omw-draft-phone"><span><?php esc_html_e( 'Draft message preview', 'olama-messages' ); ?></span><div class="omw-draft-preview" data-workspace-message-preview dir="auto"><?php echo esc_html( $body ); ?></div><p class="description" data-workspace-message-stats></p><p class="description"><?php esc_html_e( 'Fields are replaced during preparation. Final SMS lengths can vary by recipient.', 'olama-messages' ); ?></p></div></aside></div>
		</div>
		<div class="omsg-sms-preview-modal" data-sms-preview-modal role="dialog" aria-modal="true" aria-labelledby="omsg-sms-preview-title" hidden>
			<button type="button" class="omsg-sms-preview-backdrop" data-close-sms-preview aria-label="Close message preview"></button>
			<div class="omsg-sms-preview-dialog">
				<div class="omsg-sms-preview-head"><div><h2 id="omsg-sms-preview-title"><?php echo esc_html(Olama_Messages_Workspace::label('SMS preview')); ?></h2><p data-sms-preview-recipient></p></div><button type="button" class="button-link omsg-sms-preview-close" data-close-sms-preview aria-label="Close message preview">&times;</button></div>
				<div class="omsg-sms-preview-meta"><span data-sms-preview-phone></span><span data-sms-preview-counts></span></div>
				<div class="omsg-sms-phone-frame"><div class="omsg-sms-bubble" data-sms-preview-body dir="auto"></div></div>
				<p class="description">This is the rendered message with family-specific variables applied. Payment links remain placeholders until sending.</p>
				<div class="omsg-sms-preview-footer"><button type="button" class="button button-primary" data-close-sms-preview><?php echo esc_html(Olama_Messages_Workspace::label('Close')); ?></button></div>
			</div>
		</div>
		</div>
		<?php
	}

    public function delivery() {
        $summary = $this->plugin->operations()->workspace_summary(); $counts = $this->plugin->operations()->queue_counts();
        $filter = sanitize_key( $_GET['status'] ?? '' ); $activity = $this->plugin->operations()->recent_activity( 50, $filter );
        $this->header( __( 'Know what happened.', 'olama-messages' ), __( 'Submissions, uncertain outcomes and worker health in one delivery workspace.', 'olama-messages' ), '<a class="button" href="' . esc_url( Olama_Messages_Workspace::url( 'olama-messages-agents' ) ) . '">' . esc_html__( 'Sending agents', 'olama-messages' ) . ' ↗</a>' ); ?>
        <div class="omsg-stat-grid omw-metrics"><?php foreach ( array( array( __( 'Authorized queue', 'olama-messages' ), $summary['authorized'], __( 'Sending and paused campaigns', 'olama-messages' ) ), array( __( 'In progress', 'olama-messages' ), $counts['reserved'], __( 'Current reservations', 'olama-messages' ) ), array( __( 'Submitted today', 'olama-messages' ), $summary['submitted'], __( 'KDE command completed', 'olama-messages' ) ), array( __( 'Need review', 'olama-messages' ), $summary['review'], __( 'Uncertain outcomes · no automatic resend', 'olama-messages' ) ) ) as $metric ) : ?><div class="omsg-stat"><span><?php echo esc_html( $metric[0] ); ?></span><strong><?php echo number_format_i18n( $metric[1] ); ?></strong><small><?php echo esc_html( $metric[2] ); ?></small></div><?php endforeach; ?></div>
        <div class="omw-columns"><div class="omw-stack"><section class="omsg-panel" style="margin:0"><div class="omsg-panel-head"><div><h2><?php esc_html_e( 'Message outcomes', 'olama-messages' ); ?></h2><p class="description"><?php esc_html_e( 'Latest outcome per message. Times are shown in the school timezone.', 'olama-messages' ); ?></p></div></div>
        <form class="omsg-filterbar" data-workspace-filter method="get"><input type="hidden" name="page" value="olama-messages-delivery"><label class="screen-reader-text" for="omw-outcome-filter"><?php esc_html_e( 'Filter outcomes', 'olama-messages' ); ?></label><select name="status" id="omw-outcome-filter"><?php foreach ( array( '' => __( 'All recent outcomes', 'olama-messages' ), 'unknown' => __( 'Need review', 'olama-messages' ), 'retry_wait' => __( 'Retry scheduled', 'olama-messages' ), 'failed' => __( 'Failed', 'olama-messages' ), 'sent' => __( 'Submitted via KDE', 'olama-messages' ), 'reserved' => __( 'In progress', 'olama-messages' ) ) as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filter, $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select><button class="button"><?php esc_html_e( 'Filter', 'olama-messages' ); ?></button></form>
        <div class="omsg-table-scroll"><table class="widefat"><thead><tr><th><?php esc_html_e( 'Message / campaign', 'olama-messages' ); ?></th><th><?php esc_html_e( 'Outcome', 'olama-messages' ); ?></th><th><?php esc_html_e( 'Recorded', 'olama-messages' ); ?></th><th><?php esc_html_e( 'Next action', 'olama-messages' ); ?></th></tr></thead><tbody>
        <?php if ( ! $activity ) : ?><tr><td colspan="4"><div class="omw-empty"><h2><?php esc_html_e( 'No matching outcomes.', 'olama-messages' ); ?></h2><p><?php esc_html_e( 'Try another filter. Message outcomes appear as your agent processes a campaign.', 'olama-messages' ); ?></p></div></td></tr><?php endif; ?>
        <?php foreach ( $activity as $row ) : $phone = $row['phone_e164'] ? substr( $row['phone_e164'], 0, 5 ) . '•••' . substr( $row['phone_e164'], -3 ) : '—'; ?><tr><td><strong><?php echo esc_html( $row['campaign_title'] ?: __( 'Historical campaign', 'olama-messages' ) ); ?></strong><small>#<?php echo (int) $row['id']; ?> · <bdi><?php echo esc_html( $phone ); ?></bdi></small></td><td><?php echo $this->status( $row['status'] ); ?><?php if ( $row['last_error_message'] ) : ?><details class="omw-row-menu"><summary><?php esc_html_e( 'Reason', 'olama-messages' ); ?></summary><p><?php echo esc_html( $row['last_error_message'] ); ?></p></details><?php endif; ?></td><td><small><?php echo esc_html( Olama_Messages_Agent_Dashboard::time_label( $row['updated_at'] ) ); ?></small></td><td><a class="button button-small" href="<?php echo esc_url( Olama_Messages_Workspace::url( 'olama-messages-campaign-progress', array( 'campaign_id' => $row['campaign_id'] ) ) ); ?>"><?php echo esc_html( 'unknown' === $row['status'] ? __( 'Review outcome', 'olama-messages' ) : __( 'Open campaign', 'olama-messages' ) ); ?> →</a></td></tr><?php endforeach; ?></tbody></table></div><p class="description"><?php esc_html_e( 'Latest 50 outcomes. Complete delivery history is available in each campaign.', 'olama-messages' ); ?></p></section></div>
        <aside class="omw-stack"><?php $this->agent_card(); ?><section class="omw-panel omw-attention"><h2><?php esc_html_e( 'Understand the outcome.', 'olama-messages' ); ?></h2><p><strong><?php esc_html_e( 'Submitted via KDE', 'olama-messages' ); ?></strong><br><?php esc_html_e( 'The command completed. Recipient delivery is not confirmed.', 'olama-messages' ); ?></p><p><strong><?php esc_html_e( 'Review needed', 'olama-messages' ); ?></strong><br><?php esc_html_e( 'The outcome is uncertain. Check the phone history before authorizing a retry.', 'olama-messages' ); ?></p></section></aside></div></div><?php
    }

	public function phone_book() {
		$years = $this->plugin->provider()->get_phone_book_study_years();
		$selected_year = sanitize_text_field( wp_unslash( $_GET['study_year'] ?? ( $years[0] ?? '' ) ) );
		if ( ! in_array( $selected_year, $years, true ) && $years ) {
			$selected_year = (string) $years[0];
		}
		$active_school_year = $this->plugin->provider()->get_current_study_year();
		if ( ! in_array( $active_school_year, $years, true ) ) {
			$active_school_year = $selected_year;
		}
		$merge_year_default = '';
		$selected_year_index = array_search( $selected_year, $years, true );
		if ( false !== $selected_year_index && isset( $years[ $selected_year_index + 1 ] ) ) {
			$merge_year_default = (string) $years[ $selected_year_index + 1 ];
		} else {
			foreach ( $years as $year ) {
				if ( (string) $year !== $selected_year ) {
					$merge_year_default = (string) $year;
					break;
				}
			}
		}
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$paged = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per_page = 50;
		$result = $this->plugin->provider()->get_phone_book(
			$selected_year,
			array(
				'search' => $search,
				'limit'  => $per_page,
				'offset' => ( $paged - 1 ) * $per_page,
			)
		);
		$total = absint( $result['total'] ?? 0 );
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$summary = is_array( $result['summary'] ?? null ) ? $result['summary'] : array();
		$last_synced_at = sanitize_text_field( (string) ( $summary['last_synced_at'] ?? '' ) );
		$can_sync = current_user_can( 'olama_access_messages' ) && current_user_can( 'olama_access_oracle_sync' );
		$bridge_available = function_exists( 'olama_core' ) && method_exists( olama_core(), 'sync' ) && olama_core()->sync()->available( 'family_contacts' );
		$action = '';
		if ( $can_sync ) {
			$action  = '<form class="omsg-inline-form omsg-phonebook-sync" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			$action .= '<input type="hidden" name="action" value="olama_msg_sync_phone_book">';
			$action .= '<input type="hidden" name="study_year" value="' . esc_attr( $selected_year ) . '">';
			$action .= wp_nonce_field( 'olama_msg_sync_phone_book', '_wpnonce', true, false );
			$action .= '<button class="button button-primary"' . ( $bridge_available ? '' : ' disabled' ) . '>' . esc_html__( 'Sync latest from Olama Bridge', 'olama-messages' ) . '</button>';
			$action .= '</form>';
		}
		$this->header( Olama_Messages_Workspace::label('Audience'), Olama_Messages_Workspace::text('Active families and parent contact details synchronized from Olama Core.','الأسر النشطة وبيانات التواصل مع أولياء الأمور من Olama Core.'), $action );
		$this->print_flash();
		?>
        <div class="omw-audience-sources"><?php foreach(array('academic'=>array('Academic families','أولياء الأمور','groups'),'finance_outstanding'=>array('Financial audiences','الشرائح المالية','money-alt'),'transport_registered'=>array('Transportation','النقل المدرسي','location'),'store_missing'=>array('School supplies','المستلزمات المدرسية','archive')) as $type=>$source) : ?><article class="omw-panel"><span class="omw-metric-icon dashicons dashicons-<?php echo esc_attr($source[2]); ?>"></span><h2><?php echo esc_html(Olama_Messages_Workspace::text($source[0],$source[1])); ?></h2><p class="omw-caption"><?php echo esc_html($type==='academic' && ($result['data_source'] ?? '')==='olama_core' ? sprintf(Olama_Messages_Workspace::text('%s active families in the selected year','%s أسرة نشطة في العام المحدد'),number_format_i18n($total)) : Olama_Messages_Workspace::text('Eligibility and recipient counts are calculated during campaign review.','تحتسب الأهلية وأعداد المستلمين عند مراجعة الحملة.')); ?></p><a class="omw-link" href="<?php echo esc_url(Olama_Messages_Workspace::url('olama-messages-new-campaign',array('audience'=>$type))); ?>"><?php echo esc_html(Olama_Messages_Workspace::text('Create campaign','إنشاء حملة')); ?> ↗</a></article><?php endforeach; ?></div>
		<?php if ( 'olama_core' !== ( $result['data_source'] ?? '' ) ) : ?>
			<div class="notice notice-error inline"><p><?php esc_html_e( 'Olama Core is unavailable. Activate or update Olama Core before using the Phone Book.', 'olama-messages' ); ?></p></div>
		<?php elseif ( $can_sync && ! $bridge_available ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'Olama Bridge is unavailable. Activate or update Olama Oracle Sync to retrieve the latest family details.', 'olama-messages' ); ?></p></div>
		<?php endif; ?>
		<div class="omsg-source-note">
			<strong><?php esc_html_e( 'Source: Olama Core tables', 'olama-messages' ); ?></strong>
			<span>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: active family count, 2: study year */
						__( '%1$s active families for %2$s', 'olama-messages' ),
						number_format_i18n( $total ),
						$selected_year ?: '—'
					)
				);
				?>
				<?php if ( $last_synced_at ) : ?>
					<small>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: date and time of the latest Olama Core family synchronization */
								__( 'Last Bridge sync: %s', 'olama-messages' ),
								$last_synced_at
							)
						);
						?>
					</small>
				<?php endif; ?>
			</span>
		</div>
		<section class="omsg-panel omsg-phonebook-export">
			<div class="omsg-panel-head">
				<div>
					<h2><?php esc_html_e( 'Google Contacts export', 'olama-messages' ); ?></h2>
					<p><?php esc_html_e( 'Download one contact per family, including active students, classes, sections, and family transportation.', 'olama-messages' ); ?></p>
				</div>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="olama_msg_export_phone_book">
				<?php wp_nonce_field( 'olama_msg_export_phone_book' ); ?>
				<div class="omsg-phonebook-export-fields">
					<label>
						<?php esc_html_e( 'Phone book year', 'olama-messages' ); ?>
						<select name="study_year" required>
							<?php foreach ( $years as $year ) : ?>
								<option value="<?php echo esc_attr( $year ); ?>" <?php selected( $selected_year, $year ); ?>><?php echo esc_html( $year ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label class="omsg-phonebook-merge-toggle">
						<input type="checkbox" name="merge_years" value="1" <?php disabled( '' === $merge_year_default ); ?>>
						<span><?php esc_html_e( 'Merge families from another year', 'olama-messages' ); ?></span>
					</label>
					<label>
						<?php esc_html_e( 'Additional year', 'olama-messages' ); ?>
						<select name="merge_year" <?php disabled( '' === $merge_year_default ); ?>>
							<?php foreach ( $years as $year ) : ?>
								<?php if ( (string) $year !== $selected_year ) : ?>
									<option value="<?php echo esc_attr( $year ); ?>" <?php selected( $merge_year_default, $year ); ?>><?php echo esc_html( $year ); ?></option>
								<?php endif; ?>
							<?php endforeach; ?>
						</select>
					</label>
					<button class="button button-primary" type="submit" <?php disabled( empty( $years ) ); ?>><?php esc_html_e( 'Download Google CSV', 'olama-messages' ); ?></button>
				</div>
				<p class="description">
					<?php esc_html_e( 'When merging, a family that exists in both years is exported once. The selected phone book year takes priority.', 'olama-messages' ); ?>
				</p>
			</form>
		</section>
		<section class="omsg-panel omsg-phonebook-export">
			<div class="omsg-panel-head">
				<div>
					<h2><?php esc_html_e( 'Active School phone book', 'olama-messages' ); ?></h2>
					<p><?php esc_html_e( 'Current study year only: export Google Contacts using the school naming format, or a CSV grouped by grade and section for WhatsApp groups.', 'olama-messages' ); ?></p>
				</div>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="olama_msg_export_active_school">
				<?php wp_nonce_field( 'olama_msg_export_active_school' ); ?>
				<div class="omsg-phonebook-export-fields">
					<label>
						<?php esc_html_e( 'Current study year', 'olama-messages' ); ?>
						<select name="study_year" required>
							<?php foreach ( $years as $year ) : ?>
								<option value="<?php echo esc_attr( $year ); ?>" <?php selected( $active_school_year, $year ); ?>><?php echo esc_html( $year ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<button class="button button-primary" type="submit" name="format" value="google" <?php disabled( empty( $years ) ); ?>><?php esc_html_e( 'Download Active School Google CSV', 'olama-messages' ); ?></button>
					<button class="button" type="submit" name="format" value="school_grade_section" <?php disabled( empty( $years ) ); ?>><?php esc_html_e( 'Download School - Grade - Section Phone Book', 'olama-messages' ); ?></button>
				</div>
			</form>
		</section>
		<form class="omsg-filterbar omsg-phonebook-filter" method="get">
			<input type="hidden" name="page" value="olama-messages-phone-book">
			<label>Study year
				<select name="study_year"><?php foreach ( $years as $year ) : ?><option value="<?php echo esc_attr( $year ); ?>" <?php selected( $selected_year, $year ); ?>><?php echo esc_html( $year ); ?></option><?php endforeach; ?></select>
			</label>
			<label>Search families
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Family ID, parent, phone, or address">
			</label>
			<button class="button button-primary">View families</button>
			<?php if ( $search ) : ?><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=olama-messages-phone-book&study_year=' . rawurlencode( $selected_year ) ) ); ?>">Clear</a><?php endif; ?>
		</form>
		<section class="omsg-panel omsg-phonebook-panel">
			<div class="omsg-table-scroll"><table class="widefat striped omsg-table omsg-phonebook-table">
				<thead><tr><th>Family</th><th>Father</th><th>Father phone</th><th>Mother</th><th>Mother phone</th><th>Address</th></tr></thead>
				<tbody>
				<?php if ( empty( $result['items'] ) ) : ?><tr><td colspan="6">No active families match this year and search.</td></tr><?php endif; ?>
				<?php foreach ( (array) ( $result['items'] ?? array() ) as $family ) : ?>
					<tr>
						<td><strong>#<?php echo esc_html( $family['family_id'] ); ?></strong><small><?php echo esc_html( $family['sponsor_name'] ); ?></small></td>
						<td><?php echo esc_html( $family['father_name'] ?: '—' ); ?></td>
						<td><?php $father_tel = preg_replace( '/[^0-9+]/', '', $family['father_mobile'] ); ?><?php if ( $father_tel ) : ?><a href="tel:<?php echo esc_attr( $father_tel ); ?>" dir="ltr"><?php echo esc_html( $family['father_mobile'] ); ?></a><?php else : ?>—<?php endif; ?></td>
						<td><?php echo esc_html( $family['mother_name'] ?: '—' ); ?></td>
						<td><?php $mother_tel = preg_replace( '/[^0-9+]/', '', $family['mother_mobile'] ); ?><?php if ( $mother_tel ) : ?><a href="tel:<?php echo esc_attr( $mother_tel ); ?>" dir="ltr"><?php echo esc_html( $family['mother_mobile'] ); ?></a><?php else : ?>—<?php endif; ?></td>
						<td class="omsg-address"><?php echo esc_html( $family['address'] ?: '—' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages"><?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'current'   => $paged,
							'total'     => $total_pages,
							'prev_text' => '‹',
							'next_text' => '›',
						)
					)
				);
				?></div></div>
			<?php endif; ?>
		</section></div>
		<?php
	}

	public function reports() {
		if ( isset( $_GET['report'] ) && $_GET['report'] === 'collection' ) {
			Olama_Messages_Collection_Report_Page::render();
			return;
		}
		$years = $this->plugin->provider()->get_phone_book_study_years();
		$selected_year = sanitize_text_field( wp_unslash( $_GET['study_year'] ?? ( $years[0] ?? '' ) ) );
		if ( ! in_array( $selected_year, $years, true ) && $years ) {
			$selected_year = (string) $years[0];
		}

		$options = $this->plugin->provider()->get_section_report_options( $selected_year );
		$option_map = array();
		foreach ( $options as $option ) {
			$key = substr( hash( 'sha256', $option['class_id'] . "\0" . $option['section_id'] ), 0, 20 );
			$option_map[ $key ] = $option;
		}

		$selection = sanitize_key( wp_unslash( $_GET['grade_section'] ?? '' ) );
		$selected = $option_map[ $selection ] ?? null;
		$rows = $selected ? $this->plugin->provider()->get_section_users_report( $selected_year, $selected['class_id'], $selected['section_id'] ) : array();

		$this->header( 'Reports', 'Operational reports built from synchronized Olama Core records.' );
		Olama_Messages_Collection_Report_Page::tabs( 'sections' );
		?>
		<?php if ( ! $years ) : ?>
			<div class="notice notice-error inline"><p><?php esc_html_e( 'Olama Core is unavailable or has no synchronized student-year records.', 'olama-messages' ); ?></p></div>
		<?php endif; ?>
		<section class="omsg-panel omsg-report-card">
			<div class="omsg-panel-head">
				<div>
					<h2><?php esc_html_e( 'Sections Users Names', 'olama-messages' ); ?></h2>
					<p><?php esc_html_e( 'Select a grade and section to list its students and mother contact numbers.', 'olama-messages' ); ?></p>
				</div>
			</div>
			<form class="omsg-filterbar omsg-report-filter" method="get">
				<input type="hidden" name="page" value="olama-messages-reports">
				<label><?php esc_html_e( 'Study year', 'olama-messages' ); ?>
					<select name="study_year" data-report-study-year required>
						<?php foreach ( $years as $year ) : ?><option value="<?php echo esc_attr( $year ); ?>" <?php selected( $selected_year, $year ); ?>><?php echo esc_html( $year ); ?></option><?php endforeach; ?>
					</select>
				</label>
				<label><?php esc_html_e( 'Grade - section', 'olama-messages' ); ?>
					<select name="grade_section">
						<option value=""><?php esc_html_e( 'Select a grade - section', 'olama-messages' ); ?></option>
						<?php foreach ( $option_map as $key => $option ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $selection, $key ); ?>><?php echo esc_html( ( $option['class_name'] ?: $option['class_id'] ) . ' - ' . ( $option['section_name'] ?: $option['section_id'] ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<button class="button button-primary" type="submit" <?php disabled( empty( $option_map ) ); ?>><?php esc_html_e( 'Display report', 'olama-messages' ); ?></button>
			</form>

			<?php if ( $selection && ! $selected ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'The selected grade and section are not available for this study year.', 'olama-messages' ); ?></p></div>
			<?php elseif ( $selected ) : ?>
				<div class="omsg-report-summary">
					<strong><?php echo esc_html( ( $selected['class_name'] ?: $selected['class_id'] ) . ' - ' . ( $selected['section_name'] ?: $selected['section_id'] ) ); ?></strong>
					<span><?php echo esc_html( sprintf( _n( '%s student', '%s students', count( $rows ), 'olama-messages' ), number_format_i18n( count( $rows ) ) ) ); ?></span>
				</div>
				<div class="omsg-table-scroll"><table class="widefat striped omsg-table omsg-report-table">
					<thead><tr><th><?php esc_html_e( 'Sequence', 'olama-messages' ); ?></th><th><?php esc_html_e( 'Student name', 'olama-messages' ); ?></th><th><?php esc_html_e( 'Family ID', 'olama-messages' ); ?></th><th><?php esc_html_e( 'Mother mobile #', 'olama-messages' ); ?></th></tr></thead>
					<tbody>
					<?php if ( ! $rows ) : ?><tr><td colspan="4"><?php esc_html_e( 'No active students were found in this section.', 'olama-messages' ); ?></td></tr><?php endif; ?>
					<?php foreach ( $rows as $index => $row ) : ?>
						<tr>
							<td><?php echo esc_html( number_format_i18n( $index + 1 ) ); ?></td>
							<td dir="auto"><strong><?php echo esc_html( $row['student_name'] ?: '—' ); ?></strong></td>
							<td dir="ltr"><?php echo esc_html( $row['oracle_family_id'] ?: '—' ); ?></td>
							<td><?php $mobile = preg_replace( '/[^0-9+]/', '', (string) ( $row['mother_mobile'] ?? '' ) ); ?><?php if ( $mobile ) : ?><a href="tel:<?php echo esc_attr( $mobile ); ?>" dir="ltr"><?php echo esc_html( $row['mother_mobile'] ); ?></a><?php else : ?>—<?php endif; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table></div>
			<?php endif; ?>
		</section></div>
		<?php
	}
}
