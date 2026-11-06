<?php
/** Isolated WordPress/MariaDB checks. No real campaigns, network or SMS. */
require __DIR__ . '/test-unified-workspace.php';
$ops = $plugin->operations();
$wpdb->update($c,array('study_year'=>'2026-2027','title'=>'اجتماع أولياء الأمور','target_type'=>'academic'),array('id'=>$cid));
$wpdb->update($c,array('study_year'=>'2026-2027','title'=>'تحديث المدرسة'),array('id'=>$prepared_id));
$wpdb->update($wpdb->prefix.'olama_msg_campaign_recipients',array('recipient_name'=>'أحمد محمد'),array('id'=>$rid));
$stats = $ops->command_center('2026-2027');
comm_assert(2 === $stats['campaigns'] && 12 === $stats['submitted'] && 12 === $stats['today_attempts'],'school-year campaign and daily submission/attempt totals use real records');
comm_assert(0 === $ops->command_center('1999-2000')['campaigns'],'academic-year summary excludes other years');
comm_assert(2 === count($stats['trend']) && (int)array_sum(array_column($stats['trend'],'submitted')) === 12,'chart counts actual queue submissions for the selected year');
$filtered = $ops->campaign_recipients($cid,array('status'=>'sent','search'=>'أحمد','limit'=>5,'page'=>2));
comm_assert(12 === $filtered['total'] && 3 === $filtered['pages'] && 5 === count($filtered['items']),'recipient search and pagination run on the server');
$third = $ops->campaign_recipients($cid,array('status'=>'sent','search'=>'أحمد','limit'=>5,'page'=>3));
comm_assert(2 === count($third['items']) && !array_intersect(array_column($filtered['items'],'id'),array_column($third['items'],'id')),'recipient pages do not repeat records');
comm_assert(0 === $ops->campaign_recipients($prepared_id,array('status'=>'sent'))['total'],'recipient queries remain campaign-scoped');
comm_assert(0 === $ops->campaign_recipients($cid,array('search'=>"%' OR 1=1 --"))['total'],'recipient search escapes SQL wildcard and injection characters');
$sort = $ops->campaign_recipients($cid,array('orderby'=>'id','order'=>'DESC','limit'=>2));
comm_assert($sort['items'][0]['id'] > $sort['items'][1]['id'],'recipient sorting uses validated server columns');
comm_assert(1 === count($plugin->templates()->list_templates(array('search'=>'Parent meeting'))),'message library searches stored template names');
comm_assert(0 === count($plugin->templates()->list_templates(array('search'=>"%' OR 1=1 --"))),'template search safely escapes wildcard and injection input');
$csv_row=Olama_Messages_Operations_Service::delivery_csv_row(array('id'=>1,'recipient_name'=>'=SUM(1,2)','phone_e164'=>'+962790000123','status'=>'sent','attempt_count'=>1,'last_error_message'=>"\t@malicious",'sent_at'=>$utc,'updated_at'=>$utc));
comm_assert("'=SUM(1,2)" === $csv_row[1] && "'\t@malicious" === $csv_row[5] && strpos($csv_row[2],'0000123')===false,'CSV cells neutralize formulas and mask phone numbers');
comm_assert('submitted_to_KDE' === $csv_row[3],'CSV explicitly distinguishes KDE submission from delivery');
$wpdb->insert($wpdb->prefix.'olama_msg_campaign_recipients',array('campaign_id'=>$cid,'family_id'=>1,'recipient_type'=>'father','recipient_name'=>'مستبعد <script>unsafe</script>','included'=>0,'excluded_reason'=>'invalid_phone','created_at'=>$utc));
$wpdb->update($c,array('total_excluded'=>1),array('id'=>$cid));
$_GET=array('page'=>'olama-messages-recipients','campaign_id'=>$cid); ob_start(); $legacy->page_recipients(); $exclusion_html=ob_get_clean();
comm_assert(strpos($exclusion_html,'invalid_phone')!==false && strpos($exclusion_html,'<script>unsafe</script>')===false,'campaign exclusion link displays escaped frozen reasons instead of the general phone book');
// Exercise the new detail endpoint's actual security gates and campaign boundary.
class Command_Center_Ajax_Done extends Error {}
$command_die_handler = function() { return function($message='') { echo is_scalar($message) ? $message : ''; throw new Command_Center_Ajax_Done(); }; };
add_filter('wp_die_handler',$command_die_handler); add_filter('wp_die_ajax_handler',$command_die_handler);
function command_center_ajax($controller, $method = 'ajax_recipient_detail') {
    ob_start();
    try { $controller->$method(); }
    catch (Command_Center_Ajax_Done $error) { /* WordPress AJAX response captured. */ }
    return ob_get_clean();
}
$sent_id=(int)$wpdb->get_var("SELECT id FROM $q WHERE campaign_id=$cid AND status='sent' LIMIT 1");
$_POST=array('queue_id'=>$sent_id,'campaign_id'=>$cid); $_REQUEST['nonce']=wp_create_nonce('olama_msg_ajax');
wp_set_current_user(0);
$_REQUEST['nonce']=wp_create_nonce('olama_msg_ajax');
comm_assert(false === json_decode(command_center_ajax($legacy),true)['success'],'recipient details deny unauthorized accounts');
wp_set_current_user($viewer); $_REQUEST['nonce']='invalid';
comm_assert('-1' === trim(command_center_ajax($legacy)),'recipient details reject invalid nonce');
$_REQUEST['nonce']=wp_create_nonce('olama_msg_ajax'); $_POST['campaign_id']=$prepared_id;
comm_assert(false === json_decode(command_center_ajax($legacy),true)['success'],'recipient ID cannot be opened under another campaign');
$_POST['campaign_id']=$cid; $detail_json=command_center_ajax($legacy); $detail=json_decode($detail_json,true);
comm_assert(true === $detail['success'] && 1 === count($detail['data']['attempts']),'recipient drawer returns only recorded attempts');
comm_assert(false === strpos($detail_json,'+962790000123') && false === strpos($detail_json,'agent_key'),'recipient details mask phones and omit credentials');
$_POST=array('campaign_id'=>$cid,'recipient_status'=>'unknown');
$progress=json_decode(command_center_ajax($legacy,'ajax_campaign_progress'),true);
comm_assert(true === $progress['success'] && count($progress['data']['rows'])===1 && $progress['data']['counts']['unknown']===1,'live progress refresh applies explorer filters while keeping campaign totals');
$_REQUEST['security']=wp_create_nonce('olama_msg_ajax');
$_POST=array('title'=>'Safe wizard draft','study_year'=>'2026-2027','target_type'=>'academic','recipient_policy'=>'father_first','ui_step'=>1,'filter_exclude_zero'=>1,'message_body_draft'=>'Draft text');
$saved=json_decode(command_center_ajax($legacy,'ajax_save_campaign_draft'),true);
comm_assert(true === $saved['success'],'combined audience stage saves through the existing nonce-protected endpoint');
$draft=$plugin->campaigns()->get_campaign($saved['data']['campaign_id']);
comm_assert('draft' === $draft['status'] && empty($draft['started_at']) && $draft['filters']['exclude_credit_balances']===0 && $draft['filters']['exclude_zero_balances']===1,'merged first stage preserves financial exclusion flags and never authorizes sending');
$plugin->campaigns()->delete_campaign($draft['id']);
$_POST['campaign_id']=$prepared_id;
comm_assert(false === json_decode(command_center_ajax($legacy,'ajax_save_campaign_draft'),true)['success'],'wizard autosave cannot modify an already-prepared campaign');
$out=getenv('OLAMA_WORKSPACE_FIXTURE');
if ($out) { file_put_contents($out.'/recipient-detail.json',$detail_json); }
remove_filter('wp_die_handler',$command_die_handler); remove_filter('wp_die_ajax_handler',$command_die_handler);
// Verify campaign page boundaries and filters with more than one page.
for($i=0;$i<29;$i++) { $wpdb->insert($c,array('title'=>'تحديث أكاديمي '.($i+1),'study_year'=>'2026-2027','target_type'=>'academic','channel'=>'sms','status'=>'draft','created_at'=>$utc)); }
$_GET=array('page'=>'olama-messages-campaigns','audience'=>'academic','sort'=>'title','order'=>'ASC');
ob_start(); $modern->campaigns(); $page1=ob_get_clean();
comm_assert(substr_count($page1,'class="omsg-actions"')===25 && strpos($page1,'paged=2')!==false,'campaign listing has bounded server pagination');
$_GET['paged']=2; ob_start(); $modern->campaigns(); $page2=ob_get_clean();
comm_assert(substr_count($page2,'class="omsg-actions"')===5,'campaign second page has only remaining filtered rows');
// Real renderer fixtures in Arabic RTL, using synthetic school records only.
if ($out) {
    $GLOBALS['wp_locale']->text_direction='rtl';
    update_option('olama_msg_school_name','مدرسة الاختبار · بيانات اصطناعية');
    foreach(array('overview'=>array($modern,'overview','olama-messages'),'campaigns'=>array($modern,'campaigns','olama-messages-campaigns'),'campaigns-filtered'=>array($modern,'campaigns','olama-messages-campaigns'),'wizard'=>array($modern,'wizard','olama-messages-new-campaign'),'progress'=>array($legacy,'page_campaign_progress','olama-messages-campaign-progress'),'audience'=>array($modern,'phone_book','olama-messages-phone-book'),'library'=>array($legacy,'page_templates','olama-messages-templates'),'settings'=>array($legacy,'page_settings','olama-messages-settings'),'exclusions'=>array($legacy,'page_recipients','olama-messages-recipients')) as $name=>$view) {
        $_GET=array('page'=>$view[2]); $_POST=array();
        if(in_array($name,array('progress','exclusions'),true)) { $_GET['campaign_id']=$cid; }
        if($name==='wizard') { $_GET['audience']='academic'; }
        if($name==='campaigns-filtered') { $_GET['s']='تحديث'; }
        ob_start(); Olama_Messages_Workspace::callback($view[0],$view[1],$plugin)(); $html=ob_get_clean();
        $head='<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Command Center — isolated fixture</title>';
        foreach(array(ABSPATH.'wp-includes/css/dashicons.css',ABSPATH.'wp-includes/css/buttons.css',ABSPATH.'wp-admin/css/common.css',ABSPATH.'wp-admin/css/forms.css',dirname(__DIR__).'/assets/admin.css',dirname(__DIR__).'/assets/workspace.css') as $css) { $head.='<link rel="stylesheet" href="file:///'.str_replace('\\','/',$css).'">'; }
        $head.='<style>body{margin:0}.fixture-banner{background:#111a35;color:white;padding:5px 15px;font:12px Arial;text-align:center}</style></head><body class="wp-admin olama-workspace"><div class="fixture-banner">ISOLATED TEST DATA — NO SMS TRANSPORT</div><div id="wpcontent"><div id="wpbody-content">';
        $tail='</div></div><script src="file:///'.str_replace('\\','/',ABSPATH).'wp-includes/js/jquery/jquery.min.js"></script><script>var olamaMsgAdmin='.wp_json_encode(array('ajaxUrl'=>'http://127.0.0.1:13389/admin-ajax.php','nonce'=>'fixture','i18n'=>Olama_Messages_Workspace::arabic_labels())).';</script>';
        foreach(array('sms-segmentation.js','admin.js','admin-campaign-wizard.js','workspace.js') as $js) { $tail.='<script src="file:///'.str_replace('\\','/',dirname(__DIR__)).'/assets/'.$js.'"></script>'; }
        file_put_contents($out.'/rtl-'.$name.'.html',$head.$html.$tail.'</body></html>');
    }
}
comm_assert($before===$wpdb->get_var("SELECT COUNT(*) FROM $a"),'command center reads, filters and drawers create no dispatch attempts');
echo "Command Center checks complete.\n";
