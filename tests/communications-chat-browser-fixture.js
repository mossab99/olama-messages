/* Fictional UI responses only. Authorization is tested against WordPress/MariaDB in test-chat.php. */
'use strict';
const personal = new Map(); const messages = new Map(); let seq = 1;
const now = () => new Date().toISOString().slice(0, 19).replace('T', ' ');
module.exports = (url, actor, data, method, receipts) => {
    const staff = actor.actor_type === 'employee'; const key = actor.actor_key;
    if (!messages.has(key)) messages.set(key, [{id: 10, sender_key: staff ? 'employee:fixture-colleague' : 'employee:fixture-teacher', display_name: staff ? 'زميل تجريبي' : 'معلم العلوم', body: staff ? 'محادثة الموظفين التجريبية فقط' : 'مرحباً بالأسرة، هذه متابعة مادة العلوم. <script>alert("نص فقط")</script>', sent_at_utc: now(), reply_to: 0, own: false}]);
    if (!personal.has(key)) personal.set(key, {archived: 0, muted: 0, pinned: 0, manual_unread: 0, read_cursor: 0, delivered_cursor: 0});
    const rows = messages.get(key), prefs = personal.get(key);
    const path = url.pathname.replace('/api/chat/', '');
    const title = staff ? 'متابعة بين الموظفين' : 'أحمد · العلوم · 2026-2027';
    if (path === 'feed') return {changes: [{id: seq, thread_id: 1, kind: 'message'}], cursor: seq, unread: prefs.read_cursor ? 0 : 1};
    if (path === 'contacts') {
        const query = (url.searchParams.get('query') || '').trim();
        const group = url.searchParams.get('group') || '';
        const directory = url.searchParams.get('directory');
        const children = staff ? [] : [{student_uid: 'fixture-child', name: 'أحمد', class_name: 'الرابع', section_name: 'ب'}];
        const groups = staff
            ? [
                {key: 'administrators', label: 'الإدارة', description: 'مديرو النظام وإدارة المدرسة', symbol: 'إ'},
                {key: 'teachers', label: 'المعلمون', description: 'أعضاء الهيئة التدريسية', symbol: 'م'},
                {key: 'employees', label: 'الموظفون', description: 'الموظفون المخولون بالتواصل', symbol: 'و'},
                {key: 'families', label: 'الأسر', description: 'أسر الطلاب المرتبطة بالتعيينات', symbol: 'أ', directory: 'assigned_families'}
            ]
            : [{key: 'teachers', label: 'المعلمون', description: 'المعلمون المعينون للطالب', symbol: 'م'}];
        let contacts = directory === 'assigned_families'
            ? [{actor_key: 'family:test-family', name: 'أحمد · أسرة أحمد وسارة', group: 'families', context: {student_uid: 'fixture-child', assignment_id: 1, student_name: 'أحمد', class_name: 'الرابع', section_name: 'ب', subject_name: 'العلوم', contact_type: 'أسرة طالب', contact_group: 'families'}}]
            : staff
                ? [
                    {actor_key: 'administrator:9', name: 'مدير المدرسة', group: 'administrators', context: {scope: 'administrator', contact_type: 'مدير نظام', contact_group: 'administrators'}},
                    {actor_key: 'employee:fixture-teacher', name: 'أحمد محمد', group: 'teachers', context: {scope: 'staff', contact_type: 'معلم', contact_group: 'teachers'}},
                    {actor_key: 'employee:fixture-colleague', name: 'سارة محمود', group: 'employees', context: {scope: 'staff', contact_type: 'موظف', contact_group: 'employees'}}
                ]
                : url.searchParams.get('student_uid') ? [{actor_key: 'employee:fixture-teacher', name: 'معلم العلوم', group: 'teachers', context: {student_uid: 'fixture-child', assignment_id: 1, student_name: 'أحمد', subject_name: 'العلوم', contact_type: 'معلم', contact_group: 'teachers'}}] : [];
        if (!directory) contacts = group ? contacts.filter(contact => contact.group === group || group === 'all') : [];
        if (query.length === 1) contacts = [];
        else if (query.length >= 2) contacts = contacts.filter(contact => [contact.name, ...Object.values(contact.context)].some(value => String(value).includes(query)));
        return {children, contacts, next: 0, groups};
    }
    if (path === 'inboxes') return {items: [{id: 1, name: 'شؤون الطلبة', can_contact: true, active: 1, allow_family: 1, allow_teacher: 1, history_policy: 'active_and_recent', recent_days: 30, members: [{actor_key: 'employee:E-42', active: 1, is_manager: 1}]}], next: 0};
    if (path === 'threads' && method === 'POST') return {id: data.kind === 'service' ? 2 : 1};
    if (path === 'threads') return Number(url.searchParams.get('archived') || 0) === Number(prefs.archived) ? [
        {id: 1, kind: 'direct', subject: title, correspondent_name: staff ? 'سارة محمود' : 'معلم العلوم', last_message: staff ? 'يرجى مراجعة جدول الاجتماع قبل نهاية اليوم.' : 'مرحباً بالأسرة، هذه متابعة مادة العلوم.', last_message_at: now(), last_message_own: false, status: 'open', unread_count: prefs.read_cursor ? 0 : 2, page_cursor: '0:10:1', ...prefs},
        {id: 2, kind: 'direct', subject: staff ? 'مراسلة إدارية' : 'أحمد · اللغة العربية', correspondent_name: staff ? 'مدير المدرسة' : 'معلم اللغة العربية', last_message: 'تم اعتماد الطلب وإرساله إلى القسم المختص.', last_message_at: '2026-09-09 08:30:00', last_message_own: true, status: 'open', unread_count: 0, pinned: 0, muted: 0, manual_unread: 0, page_cursor: '0:9:2'},
        {id: 3, kind: 'direct', subject: staff ? 'مراسلة الموظفين' : 'سارة · الرياضيات', correspondent_name: staff ? 'أحمد محمد' : 'معلم الرياضيات', last_message: 'موعد المتابعة غداً في الحصة الأولى.', last_message_at: '2026-09-08 11:10:00', last_message_own: false, status: 'open', unread_count: 0, pinned: 0, muted: 0, manual_unread: 1, page_cursor: '0:8:3'}
    ] : [];
    if (/^threads\/\d+$/.test(path)) return {thread: {id: 1, subject: title, kind: 'direct', status: 'open', context: staff ? {scope: 'staff'} : {student_name: 'أحمد', subject_name: 'العلوم', study_year: '2026-2027'}}, messages: rows.map(row => ({...row, own: row.sender_key === key, quote_body: rows.find(quote => quote.id === Number(row.reply_to))?.body || null})), personal: prefs, receipts: [{display_name: 'المستلم', read_cursor: 0, delivered_cursor: 0}], send_state: {allowed: true}, member: null, actions: [], next: 0};
    if (/\/receipt$/.test(path)) { receipts.push({...data, actor: key, chat: true}); prefs[data.kind + '_cursor'] = Math.max(prefs[data.kind + '_cursor'] || 0, data.cursor); return {ok: true}; }
    if (/\/preferences$/.test(path)) { Object.assign(prefs, data); return {ok: true}; }
    if (/^threads\/\d+\/messages$/.test(path)) {
        const duplicate = rows.find(row => row.client_message_id === data.client_message_id); if (duplicate) return {id: duplicate.id, duplicate: true};
        const row = {id: Math.max(...rows.map(item => item.id)) + 1, sender_key: key, display_name: actor.display_name, ...data, sent_at_utc: now()}; rows.push(row); seq++; return {id: row.id};
    }
    if (/^messages\/\d+\/edit$/.test(path)) { const row = rows.find(item => item.id === Number(path.split('/')[1])); row.body = data.body; row.edited_at_utc = now(); seq++; return {ok: true}; }
    if (path === 'reports') return [{id: 1, reporter_key: 'family:fixture', reason: 'بلاغ تجريبي لمراجعة رسالة', created_at_utc: now()}];
    if (path === 'restrictions') return [];
    return {ok: true, id: 1};
};
