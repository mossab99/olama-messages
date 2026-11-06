<?php
/** Real WordPress render fixtures and read-only workspace regression checks. */
require __DIR__ . '/test-agent-dashboard.php';
$plugin = Olama_Messages_Plugin::instance();
class Workspace_View_Provider {
    public function get_current_study_year() { return '2026-2027'; }
    public function get_available_study_years() { return array('2026-2027','2025-2026'); }
    public function get_phone_book_study_years() { return $this->get_available_study_years(); }
    public function get_sync_health(...$args) { return array('ready'=>true); }
    public function get_phone_book(...$args) { return array('total'=>0,'items'=>array(),'summary'=>array(),'data_source'=>'olama_core'); }
    public function get_section_report_options(...$args) { return array(); }
    public function is_core_available() { return true; }
}
$property = new ReflectionProperty($plugin, 'provider'); $property->setValue($plugin, new Workspace_View_Provider());
$template_id=$plugin->templates()->create_template(array('name'=>'Parent meeting invitation','body'=>'أولياء الأمور الكرام، ندعوكم إلى اجتماع المدرسة. {school_name}','channel'=>'sms','is_active'=>1));
$wpdb->insert($c, array('title'=>'Prepared school update','status'=>'prepared','channel'=>'sms','created_at'=>$utc)); $prepared_id=$wpdb->insert_id;
$wpdb->insert($q, array('campaign_id'=>$prepared_id,'family_id'=>1,'status'=>'prepared','created_at'=>$utc));
$wpdb->insert($q, array('campaign_id'=>$cid,'family_id'=>1,'status'=>'unknown','phone_e164'=>'+962790000123','last_error_message'=>'Check phone <script>alert(1)</script>','created_at'=>$utc,'updated_at'=>$utc));
$summary=$plugin->operations()->workspace_summary();
comm_assert(8 === $summary['authorized'] && 1 === $summary['prepared'], 'prepared campaign is separate from authorized queue');
comm_assert(1 === $summary['review'] && 12 === $summary['submitted'], 'workspace totals reflect actual outcomes');
comm_assert(1 === count($plugin->operations()->recent_activity(50,'unknown')), 'outcome filter is applied on server');
comm_assert('olama-messages-delivery' === Olama_Messages_Workspace::parent('olama-messages-agents'), 'agent workspace keeps delivery navigation active');
$modern=new Olama_Messages_Modern_Admin($plugin); $legacy=new Olama_Messages_Admin($plugin);
$out=getenv('OLAMA_WORKSPACE_FIXTURE');
$before=$wpdb->get_var("SELECT COUNT(*) FROM $a");
$views=array('overview'=>array($modern,'overview','olama-messages'), 'campaigns'=>array($modern,'campaigns','olama-messages-campaigns'), 'wizard'=>array($modern,'wizard','olama-messages-new-campaign'), 'delivery'=>array($modern,'delivery','olama-messages-delivery'), 'audience'=>array($modern,'phone_book','olama-messages-phone-book'), 'library'=>array($legacy,'page_templates','olama-messages-templates'), 'settings'=>array($legacy,'page_settings','olama-messages-settings'), 'agents'=>array($legacy,'page_agents','olama-messages-agents'), 'progress'=>array($legacy,'page_campaign_progress','olama-messages-campaign-progress'), 'quick-send'=>array($legacy,'page_direct_message','olama-messages-direct'), 'reports'=>array($modern,'reports','olama-messages-reports'));
$views['authorize']=array($modern,'wizard','olama-messages-new-campaign');
$views['agent-detail']=array($legacy,'page_agents','olama-messages-agents');
$views['template-edit']=array($legacy,'page_templates','olama-messages-templates');
$views['report-links']=array($legacy,'page_tokens','olama-messages-tokens');
foreach($views as $name=>$view) {
    $_GET=array('page'=>$view[2]); $_POST=array(); if('progress'===$name){$_GET['campaign_id']=$cid;}
    if('wizard'===$name){$_GET['template_id']=$template_id;$_GET['step']=3;}
    if('authorize'===$name){$_GET['campaign_id']=$prepared_id;$_GET['step']=5;}
    if('agent-detail'===$name){$_GET['action']='view';$_GET['agent_id']=$id;}
    if('template-edit'===$name){$_GET['action']='edit';$_GET['template_id']=$template_id;}
    ob_start(); $callback=Olama_Messages_Workspace::callback($view[0],$view[1],$plugin); $callback(); $html=ob_get_clean();
    comm_assert(1===substr_count($html,'class="omw-shell"') && strpos($html,'id="omw-command"')!==false, $name.' uses the shared shell');
    comm_assert(strpos($html,'<script>alert(1)</script>')===false, $name.' escapes untrusted content');
    if('library'===$name){comm_assert(strpos($html,'template_id='.$template_id)!==false && strpos($html,'omw-template-card')!==false,'library provides campaign reuse and readable message cards');}
    if('authorize'===$name){comm_assert(strpos($html,'olama_msg_start_campaign')!==false && strpos($html,'name="confirmation"')!==false && strpos($html,'name="_wpnonce"')!==false,'authorization retains explicit confirmation and nonce');}
    if('wizard'===$name){comm_assert(strpos($html,'أولياء الأمور الكرام')!==false,'active library message prefills a draft without preparing or sending');}
    if(!$out){continue;} if(!is_dir($out)){mkdir($out,0777,true);}
    $head='<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Workspace preview</title>';
    foreach(array(ABSPATH.'wp-includes/css/dashicons.css',ABSPATH.'wp-includes/css/buttons.css',ABSPATH.'wp-admin/css/common.css',ABSPATH.'wp-admin/css/forms.css',dirname(__DIR__).'/assets/admin.css',dirname(__DIR__).'/assets/workspace.css') as $css){$head.='<link rel="stylesheet" href="file:///'.str_replace('\\','/',$css).'">';}
    $head.='<style>#wpadminbar{height:32px;background:#1d2327;color:white;position:fixed;top:0;left:0;right:0;z-index:99999;padding:5px 15px;box-sizing:border-box}body{margin:32px 0 0}</style></head><body class="wp-admin admin-bar olama-workspace"><div id="wpadminbar">WordPress · School administration</div><div id="wpcontent"><div id="wpbody-content">';
    $tail='</div></div><script src="file:///'.str_replace('\\','/',ABSPATH).'wp-includes/js/jquery/jquery.min.js"></script><script>var olamaMsgAdmin={ajaxUrl:"http://127.0.0.1:13389/admin-ajax.php",nonce:"fixture"};</script>';
    foreach(array('sms-segmentation.js','admin.js','admin-campaign-wizard.js','workspace.js') as $js){if(file_exists(dirname(__DIR__).'/assets/'.$js)){$tail.='<script src="file:///'.str_replace('\\','/',dirname(__DIR__)).'/assets/'.$js.'"></script>';}}
    file_put_contents($out.'/'.$name.'.html',$head.$html.$tail.'</body></html>');
}
comm_assert($before===$wpdb->get_var("SELECT COUNT(*) FROM $a"), 'rendering every workspace page creates no dispatch attempts');
echo "Unified workspace render checks complete.\n";

