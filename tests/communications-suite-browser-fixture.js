'use strict';
const events=[{id:1,title:'اليوم المفتوح للأسرة',description:'يرجى تأكيد الاطلاع والحضور لكل طالب.\nنص <script>غير منفذ</script>',category:'school',priority:'critical',starts_at_utc:'2026-09-15 07:00:00',ends_at_utc:'2026-09-15 09:00:00',timezone:'Asia/Amman',all_day:0,location:'قاعة المدرسة',status:'published',source_type:'communications',content_version:1,ack_required_version:1,rsvp_required_version:1,requires_ack:1,rsvp_enabled:1,action_required:1,published_at_utc:'2026-09-08 07:00:00',reminder_policy_json:'[1440]',response_scope:'student',attachments:[]}];
const targets=[{id:1,event_id:1,student_uid:'S1',context_json:'{"student_name":"أحمد"}',acknowledged_ack_version:0,rsvp_required_version:0,rsvp_status:'pending'},{id:2,event_id:1,student_uid:'S2',context_json:'{"student_name":"سارة"}',acknowledged_ack_version:0,rsvp_required_version:0,rsvp_status:'pending'}];
const actions=[{id:1,title:'تعبئة استمارة اليوم المفتوح',source_type:'system',status:'open',priority:'important',due_at_utc:'2026-09-14 08:00:00',owner_key:'employee:E-42',version:1,history:[]}];
const prefs={};
module.exports=function(url,actor,data,method){
 const path=url.pathname.replace('/api/suite/',''),staff=actor.actor_type==='employee';
 const preferences=prefs[actor.actor_key]||{quiet_enabled:0,quiet_start:1320,quiet_end:420,previews:0,sound:0,quiet_now:false};
 const activity={items:[{id:1,object_type:'event_target',object_id:1,event_id:1,title:events[0].title,created_at_utc:'2026-09-08 08:00:00'}],next_before:0};
 if(path==='me')return{events:true,actions:true,attachments:true,manage_events:staff,manage_actions:staff,view_dashboard:staff,configure:staff};
 if(path==='feed')return{critical:targets.filter(t=>!t.acknowledged_ack_version).map(t=>({target_id:t.id,event_id:1,title:events[0].title,content_version:1})),preferences,activity};
 if(path==='preferences'){if(method==='POST')prefs[actor.actor_key]={...data,quiet_now:false};return prefs[actor.actor_key]||preferences;}
 if(path==='activity')return method==='POST'?{ok:true}:activity;
 if(path==='events') {if(method==='POST'){const row={...data,id:events.length+1,status:'draft',source_type:data.source_type||'communications',reminder_policy_json:JSON.stringify(data.reminders||[]),content_version:1,attachments:[],audiences:[],targets:[]};events.push(row);return{id:row.id};}return{items:events,next_after:0};}
 const match=path.match(/^events\/(\d+)(?:\/(\w+))?$/);
 if(match){const row=events.find(e=>e.id===Number(match[1]));if(match[2]){row.status={prepare:'prepared',publish:'published',cancel:'cancelled',complete:'completed',archive:'archived'}[match[2]]||row.status;return{ok:true};}if(method==='POST'){Object.assign(row,data);row.content_version++;return{id:row.id};}return{...row,targets:row.id===1?targets:[],statistics:staff?{targets:2,reachable:2,sent:2,delivered:1,read_count:1,acknowledged:targets.filter(t=>t.acknowledged_ack_version).length,rsvp_yes:0}:undefined,audiences:[]};}
 if(path.startsWith('event-targets/')){const target=targets.find(t=>t.id===Number(path.split('/')[1]));if(data.kind==='ack')target.acknowledged_ack_version=data.required_version;if(data.kind==='rsvp'){target.rsvp_status=data.response;target.rsvp_required_version=data.required_version;}return{ok:true};}
 if(path==='actions'){if(method==='POST'){const row={...data,id:actions.length+1,status:'open',version:1,history:[]};actions.push(row);return{id:row.id};}return{items:actions,next_before:0};}
 if(path.startsWith('actions/')){const row=actions.find(a=>a.id===Number(path.split('/')[1]));if(method==='POST'){Object.assign(row,data);row.version++;row.history.push({actor_key:actor.actor_key,change_json:JSON.stringify(data),created_at_utc:'2026-09-08 10:00:00'});}return row;}
 if(path==='dashboard')return{events:[{status:'published',total:1}],action_items:[{status:'open',total:1}],threads:[{status:'open',total:3}],service:{requests:3,active:2,resolved:1,average_response_minutes:45},overdue_actions:0,jobs:[{job_type:'event_release',status:'completed',total:1}]};
 if(path==='retention')return{dry_run:true,candidate_event_ids:[],cutoff_utc:'2024-09-08 00:00:00'};
 if(path==='search')return{items:[],next_before:0};
 return{ok:true};
};
