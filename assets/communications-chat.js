/* Release B: plain text, actor-scoped requests, bounded polling and ephemeral drafts. */
(function () {
    'use strict';
    const h = window.OlamaChatHost;
    if (!h) return;
    const {api, el, button, date, field, choice, body, newView, pageHeader, addNav, activateNav, toast, config} = h;
    const labels = {in_progress: 'قيد التنفيذ', open: 'مفتوح', resolved: 'تم الحل', reviewed: 'تمت المراجعة', actioned: 'تم اتخاذ إجراء', dismissed: 'مغلق دون إجراء'};
    const draftPrefix = 'olama-chat-draft:' + config.userId + ':';
    let cursor = 0, initialized = false;
    const draftKey = id => draftPrefix + config.sessionScope + ':' + h.actor().actor_key + ':' + id;
    function cleanDrafts(all = false) {
        try {
            Object.keys(sessionStorage).filter(key => key.startsWith(draftPrefix)).forEach(key => {
                let value; try { value = JSON.parse(sessionStorage.getItem(key)); } catch (_) { /* invalid draft */ }
                if (all || !value || value.expires < Date.now() || !key.startsWith(draftPrefix + config.sessionScope + ':')) sessionStorage.removeItem(key);
            });
        } catch (_) { /* Storage is optional. */ }
    }
    function draft(id, value) {
        cleanDrafts();
        try {
            if (value !== undefined) { if (value) sessionStorage.setItem(draftKey(id), JSON.stringify({text: value, expires: Date.now() + 15 * 60000})); else sessionStorage.removeItem(draftKey(id)); }
            const saved = JSON.parse(sessionStorage.getItem(draftKey(id)) || 'null'); return saved ? saved.text : '';
        } catch (_) { return ''; }
    }
    cleanDrafts();
    document.addEventListener('olama-context-cleared', () => { cursor = 0; initialized = false; cleanDrafts(true); h.roots.forEach(root => { root._chatRefresh = null; root._chatRead = null; }); });
    window.addEventListener('pagehide', () => cleanDrafts(true));
    function visible(root) { return !document.hidden && root.isConnected && (!root.closest('dialog') || root.closest('dialog').open); }
    async function refresh() {
        cleanDrafts();
        for (const root of h.roots) {
            if (visible(root) && root._chatRefresh && !root._chatBusy) {
                root._chatBusy = true;
                try { await root._chatRefresh(); }
                catch (error) { if (error.name !== 'AbortError') { window.OlamaSuiteClient?.closePreviews(); body(root)?.replaceChildren(el('p', error.message)); root._chatRefresh = null; root._chatRead = null; } }
                finally { root._chatBusy = false; }
            }
        }
    }
    window.OlamaChatClient = {open: conversation, threads, requests: serviceInboxes, async poll() {
        const data = await api('chat/feed?after=' + cursor); cursor = data.cursor;
        const key = h.scope() + ':chat-toast'; let shown = 0;
        try { shown = Number(localStorage.getItem(key) || 0); } catch (_) { /* Optional coordination. */ }
        if (initialized && config.notifications && !document.hidden) {
            const notified = new Set();
            data.changes.filter(change => Number(change.notify) && Number(change.id) > shown).slice(-3).forEach(change => {
                if (notified.has(change.thread_id)) return; notified.add(change.thread_id);
                (window.OlamaSuiteClient ? window.OlamaSuiteClient.notify : toast)('لديك رسالة جديدة في المحادثات.', async () => { await h.openPanel(); await conversation(h.panelRoot(), change.thread_id); }, 'فتح المحادثة');
            });
        }
        try { localStorage.setItem(key, String(Math.max(shown, cursor))); } catch (_) { /* Optional coordination. */ }
        initialized = true;
        await refresh(); return data;
    }};
    document.addEventListener('olama-chat-poll', refresh);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });

    function attach({root, nav, me}) {
        if (!me.chat || nav.querySelector('[data-chat-nav]')) return;
        addNav(root, nav, 'المحادثات', () => threads(root), {id: 'conversations', chat: true});
        addNav(root, nav, 'طلبات الخدمة', () => serviceInboxes(root), {id: 'requests'});
        if (me.can_manage_inboxes) addNav(root, nav, 'صناديق الخدمة', () => manageInboxes(root), {group: 'admin', id: 'inboxes'});
        if (me.can_moderate) {
            addNav(root, nav, 'البلاغات', () => reports(root), {group: 'admin', id: 'reports'});
            addNav(root, nav, 'القيود', () => restrictions(root), {group: 'admin', id: 'restrictions'});
        }
        if (me.can_audit) addNav(root, nav, 'تدقيق المحادثات', () => auditForm(root), {group: 'admin', id: 'audit'});
    }
    document.addEventListener('olama-chat-mount', event => attach(event.detail));
    h.roots.forEach(root => { if (root._me) attach({root, nav: root.querySelector('nav'), me: root._me}); });

    async function threads(root, before = '', archived = false, inboxId = 0) {
        activateNav(root, inboxId ? 'requests' : 'conversations');
        const view = newView(root);
        const content = body(root), heading = pageHeader(inboxId ? 'طلبات الصندوق' : 'المحادثات', inboxId ? 'تابع الطلبات المفتوحة وسجل المعالجة.' : 'المراسلات المباشرة مع الأسر والمعلمين والموظفين.', 'مساحة العمل');
        if (!inboxId) heading.actions.append(button('محادثة جديدة', () => contacts(root), 'olama-comm-primary'));
        heading.actions.append(button(archived ? 'الوارد' : 'الأرشيف', () => threads(root, 0, !archived, inboxId)));
        content.replaceChildren(heading.header);
        const list = el('div'); content.append(list);
        const load = async () => {
            const rows = await api('chat/threads?cursor=' + encodeURIComponent(before || '') + '&archived=' + Number(archived) + '&inbox_id=' + inboxId);
            if (root._view !== view) return;
            list.replaceChildren();
            if (!rows.length) list.append(el('p', 'لا توجد محادثات هنا.', 'olama-comm-empty'));
            rows.forEach(row => {
                const card = el('article', undefined, 'olama-comm-card');
                card.append(el('h3', (Number(row.pinned) ? '📌 ' : '') + row.subject), el('p', labels[row.status] + ' · ' + row.unread_count + ' غير مقروء' + (Number(row.manual_unread) ? ' · معلّم للمتابعة' : '')));
                if (row.kind === 'service' && row.assignee_key !== undefined) card.append(el('small', row.assignee_key ? 'المسؤول: ' + row.assignee_key : 'دون مسؤول — بانتظار التعيين'));
                card.append(button('فتح المحادثة', () => conversation(root, row.id))); list.append(card);
            });
            if (rows.length === 30) list.append(button('محادثات أقدم', () => threads(root, rows[rows.length - 1].page_cursor, archived, inboxId)));
            if (before) list.append(button('الأحدث', () => threads(root, 0, archived, inboxId)));
        };
        root._chatRefresh = load; await load();
    }

    function recipientSearch(parent, options) {
        const groups = (options.groups || []).filter(group => group?.key && group?.label);
        const search = el('section', undefined, 'olama-comm-recipient-search');
        let activeGroup = options.initialGroup || (groups.length === 1 ? groups[0].key : '');
        let timer = 0, request = 0;
        const selectedGroup = () => groups.find(group => group.key === activeGroup);
        if (groups.length) {
            const directory = el('div', undefined, 'olama-comm-directory');
            directory.append(el('h4', 'دليل المستلمين'), el('p', 'اختر مجموعة، ثم تصفح الأعضاء أو ابحث بالاسم.'));
            const groupList = el('div', undefined, 'olama-comm-directory-groups'); groupList.setAttribute('role', 'group'); groupList.setAttribute('aria-label', 'مجموعات المستلمين');
            groups.forEach(group => {
                const item = button('', () => selectGroup(group.key), 'olama-comm-directory-group'); item.type = 'button'; item.dataset.group = group.key; item.setAttribute('aria-pressed', 'false');
                const copy = el('span'); copy.append(el('strong', group.label), el('small', group.description || 'عرض أعضاء المجموعة'));
                item.append(el('b', group.symbol || group.label.slice(0, 1), 'olama-comm-directory-symbol'), copy); groupList.append(item);
            });
            directory.append(groupList); search.append(directory);
        }
        const form = el('form');
        const label = el('label', options.label || 'ابحث باسم المستلم');
        const input = el('input'); input.type = 'search'; input.placeholder = options.placeholder || 'اكتب حرفين على الأقل أو اترك الحقل فارغاً للتصفح'; input.autocomplete = 'off'; input.minLength = 2; input.setAttribute('aria-label', options.label || 'ابحث باسم المستلم');
        const submit = el('button', 'بحث', 'olama-comm-primary'); submit.type = 'submit'; label.append(input); form.append(label, submit); form.hidden = groups.length > 0 && !activeGroup; search.append(form);
        const initialStatus = activeGroup ? 'جارٍ تحميل أعضاء المجموعة…' : 'اختر مجموعة من الدليل للبدء.';
        const status = el('p', initialStatus, 'olama-comm-search-status'); status.setAttribute('aria-live', 'polite');
        const results = el('div', undefined, 'olama-comm-contact-list'); search.append(status, results); parent.append(search);
        const detail = contact => [contact.context?.contact_type, contact.context?.student_name, contact.context?.subject_name, contact.context?.class_name, contact.context?.section_name].filter(Boolean).join(' · ') || options.fallback || 'مراسلة مباشرة';
        const run = async (cursor = null, append = false) => {
            const query = input.value.trim();
            const group = selectedGroup();
            if (groups.length && !group) { request++; submit.disabled = false; results.replaceChildren(); status.textContent = 'اختر مجموعة من الدليل للبدء.'; return; }
            if (query.length === 1) { request++; submit.disabled = false; results.replaceChildren(); status.textContent = 'اكتب حرفين على الأقل للبحث، أو امسح النص لتصفح المجموعة.'; return; }
            const token = ++request; submit.disabled = true; status.textContent = query ? 'جارٍ البحث…' : 'جارٍ تحميل أعضاء ' + (group?.label || 'الدليل') + '…'; if (!append) results.replaceChildren();
            try {
                const data = await api(options.path(query, cursor, activeGroup, group));
                if (token !== request || !search.isConnected) return;
                if (!append) results.replaceChildren(); else results.querySelector('[data-more-results]')?.remove();
                data.contacts.forEach(contact => {
                    const item = button('', () => options.open(contact), 'olama-comm-contact');
                    const copy = el('span'); copy.append(el('strong', contact.name), el('small', detail(contact)));
                    item.append(copy, el('b', options.action || 'اختيار')); results.append(item);
                });
                const total = results.querySelectorAll('.olama-comm-contact').length;
                status.textContent = total ? (query ? 'تم العثور على ' + total + ' نتيجة متاحة.' : 'يظهر ' + total + ' من أعضاء ' + (group?.label || 'الدليل') + '.') : (query ? (options.empty || 'لا توجد نتائج مطابقة ضمن الجهات المتاحة لك.') : 'لا يوجد أعضاء متاحون في هذه المجموعة.');
                if (data.next) { const more = button('عرض المزيد من النتائج', () => run(data.next, true)); more.dataset.moreResults = ''; results.append(more); }
            } catch (error) { if (token === request && error.name !== 'AbortError') { status.textContent = 'تعذر إكمال البحث.'; toast(error.message); } }
            finally { if (token === request) submit.disabled = false; }
        };
        function selectGroup(key, focus = true) {
            if (!groups.some(group => group.key === key)) return;
            clearTimeout(timer); request++; activeGroup = key; input.value = ''; form.hidden = false; results.replaceChildren();
            search.querySelectorAll('.olama-comm-directory-group').forEach(item => { const selected = item.dataset.group === key; item.classList.toggle('is-selected', selected); item.setAttribute('aria-pressed', String(selected)); });
            run(); if (focus) input.focus();
        }
        form.addEventListener('submit', event => { event.preventDefault(); clearTimeout(timer); run(); });
        input.addEventListener('input', () => { clearTimeout(timer); if (input.value.trim().length < 2) { run(); return; } timer = setTimeout(() => run(), 320); });
        if (activeGroup) selectGroup(activeGroup, groups.length === 1); else search.querySelector('.olama-comm-directory-group')?.focus();
    }

    async function contacts(root, studentUid = '') {
        activateNav(root, 'conversations');
        const view = newView(root);
        const data = await api('chat/contacts?student_uid=' + encodeURIComponent(studentUid) + '&query=');
        if (root._view !== view) return;
        const content = body(root), heading = pageHeader('بدء محادثة', 'اختر الجهة المتاحة لك وفق علاقتك الحالية في المدرسة.', 'المحادثات'); heading.actions.append(button('عودة للمحادثات', () => threads(root))); content.replaceChildren(heading.header);
        if (h.actor().actor_type === 'family') {
            const picker = el('section', undefined, 'olama-comm-child-picker'); picker.append(el('h4', 'اختر الطالب أولاً'), el('p', 'يعرض البحث المعلمين المعينين لهذا الطالب فقط.'));
            const choices = el('div');
            data.children.forEach(child => { const item = button(child.name + ' · ' + child.class_name + ' ' + child.section_name, () => contacts(root, child.student_uid)); if (String(child.student_uid) === String(studentUid)) item.classList.add('is-selected'); choices.append(item); });
            picker.append(choices); content.append(picker);
            if (!studentUid) return;
        }
        recipientSearch(content, {
            groups: data.groups,
            label: h.actor().actor_type === 'family' ? 'ابحث باسم المعلم أو المادة' : 'ابحث باسم المستلم أو نوعه',
            placeholder: h.actor().actor_type === 'family' ? 'اسم المعلم أو المادة، أو اتركه فارغاً للتصفح' : 'اسم المستلم، أو اتركه فارغاً للتصفح',
            path: (query, after, group, groupData) => groupData?.directory === 'assigned_families'
                ? 'chat/contacts?directory=assigned_families&group=' + encodeURIComponent(group) + '&query=' + encodeURIComponent(query) + '&after_student=' + encodeURIComponent(after?.student_uid || '') + '&after_assignment=' + Number(after?.assignment_id || 0)
                : 'chat/contacts?student_uid=' + encodeURIComponent(studentUid) + '&group=' + encodeURIComponent(group) + '&query=' + encodeURIComponent(query) + '&after=' + Number(after || 0),
            open: async contact => { const result = await api('chat/threads', {kind: 'direct', target: contact.actor_key, context: contact.context}); await conversation(root, result.id); },
            action: 'اختيار المستلم'
        });
    }
    async function serviceInboxes(root, before = 0) {
        activateNav(root, 'requests');
        const view = newView(root), inboxes = await api('chat/inboxes?before=' + before); if (root._view !== view) return;
        const content = body(root), heading = pageHeader('طلبات الخدمة', 'تواصل مع أقسام المدرسة وتابع حالة طلباتك.', 'مساحة العمل'); content.replaceChildren(heading.header);
        if (!inboxes.items.length) content.append(el('p', 'لا توجد صناديق خدمة متاحة لهذا الحساب.', 'olama-comm-empty'));
        const grid = el('div', undefined, 'olama-comm-service-grid'); content.append(grid);
        inboxes.items.forEach(inbox => {
            const card = el('article', undefined, 'olama-comm-card'); card.append(el('h4', inbox.name));
            const actions = el('div', undefined, 'olama-comm-card-actions');
            if (inbox.can_contact) actions.append(button('طلب جديد', () => requestForm(root, inbox), 'olama-comm-primary'));
            actions.append(button('عرض الطلبات', () => threads(root, 0, false, inbox.id))); card.append(actions); grid.append(card);
        });
        if (inboxes.next) content.append(button('صناديق أخرى', () => serviceInboxes(root, inboxes.next)));
    }
    function requestForm(root, inbox) {
        activateNav(root, 'requests'); newView(root); const content = body(root), heading = pageHeader('طلب إلى ' + inbox.name, 'اكتب عنواناً واضحاً، ثم أضف التفاصيل في المحادثة.', 'طلبات الخدمة'); heading.actions.append(button('عودة للصناديق', () => serviceInboxes(root))); content.replaceChildren(heading.header);
        const form = el('form', undefined, 'olama-comm-form'); const subject = field(form, 'subject', 'عنوان الطلب'); subject.maxLength = 190; subject.required = true;
        const uuid = crypto.randomUUID();
        submit(form, 'إنشاء الطلب', async () => { const row = await api('chat/threads', {kind: 'service', inbox_id: inbox.id, subject: subject.value, client_thread_id: uuid}); await conversation(root, row.id); }); content.append(form);
    }
    async function teacherFamilies(root) {
        activateNav(root, 'conversations'); const view = newView(root), content = body(root), heading = pageHeader('البحث في أسر طلابي', 'تظهر فقط الأسر المرتبطة بتعييناتك الدراسية الحالية.', 'المحادثات'); heading.actions.append(button('عودة لجهات الاتصال', () => contacts(root))); content.replaceChildren(heading.header);
        recipientSearch(content, {
            groups: [{key: 'families', label: 'الأسر', description: 'أسر الطلاب المرتبطة بتعييناتك', symbol: 'أ'}], initialGroup: 'families',
            label: 'ابحث باسم الطالب أو الأسرة أو المادة', placeholder: 'مثال: أحمد أو العلوم', fallback: 'أسرة طالب', action: 'اختيار الأسرة',
            path: (query, cursor, group) => 'chat/contacts?directory=assigned_families&group=' + encodeURIComponent(group) + '&query=' + encodeURIComponent(query) + '&after_student=' + encodeURIComponent(cursor?.student_uid || '') + '&after_assignment=' + Number(cursor?.assignment_id || 0),
            empty: 'لا توجد أسرة مطابقة ضمن تعييناتك الحالية.',
            open: async contact => { const result = await api('chat/threads', {kind: 'direct', target: contact.actor_key, context: contact.context}); await conversation(root, result.id); }
        });
    }
    function submit(form, label, action) {
        const control = el('button', label, 'olama-comm-primary'); control.type = 'submit'; form.append(control);
        form.addEventListener('submit', async event => { event.preventDefault(); control.disabled = true; try { await action(); } catch (error) { if (error.name !== 'AbortError') toast(error.message); } finally { control.disabled = form.dataset.blocked === '1'; } }); return control;
    }
    function reasonForm(parent, title, action) {
        const form = el('form', undefined, 'olama-comm-form'); form.append(el('h4', title)); const reason = field(form, 'reason', 'السبب', '', 'textarea'); reason.required = true; reason.maxLength = 1000;
        submit(form, 'تأكيد', async () => { await action(reason.value); form.remove(); }); form.append(button('إلغاء', () => form.remove())); parent.append(form); reason.focus();
    }

    async function conversation(root, id, before = 0) {
        activateNav(root, 'conversations'); const view = newView(root), content = body(root); content.replaceChildren(button('عودة للمحادثات', () => threads(root)));
        const header = el('div'), messages = el('div', undefined, 'olama-chat-messages'), controls = el('div'), status = el('p');
        messages.setAttribute('aria-label', 'رسائل المحادثة'); content.append(header, controls, messages, status);
        const form = el('form', undefined, 'olama-comm-form olama-chat-compose'); const mode = el('p'); form.append(mode);
        const input = field(form, 'message', 'رسالتك', draft(id), 'textarea'); input.maxLength = config.messageMaxChars || 5000; input.required = !root._suite?.attachments;
        const files = root._suite?.attachments ? window.OlamaSuiteClient.picker(form, 'thread', id) : null;
        let replyTo = 0, editId = 0, retry = null, sending = false, lastDelivered = 0, lastRead = 0;
        input.addEventListener('input', () => { draft(id, input.value); retry = null; });
        form.append(button('إلغاء الاقتباس / التعديل', () => { replyTo = 0; editId = 0; retry = null; mode.textContent = ''; }));
        const send = submit(form, 'إرسال', async () => {
            sending = true;
            try {
                if (editId) await api('chat/messages/' + editId + '/edit', {body: input.value});
                else {
                    const attachmentIds = files ? files.ids() : [];
                    if (retry && JSON.stringify(retry.attachment_ids || []) !== JSON.stringify(attachmentIds)) retry = null;
                    if (!retry) retry = {body: input.value, reply_to: replyTo, client_message_id: crypto.randomUUID(), ...(attachmentIds.length ? {attachment_ids: attachmentIds} : {})};
                    await api('chat/threads/' + id + '/messages', retry);
                }
                files?.clear(); retry = null; input.value = ''; replyTo = 0; editId = 0; mode.textContent = ''; draft(id, ''); await load();
            } finally { sending = false; }
        });
        content.append(form);
        const load = async () => {
            const data = await api('chat/threads/' + id + '?before=' + before);
            if (root._view !== view) return;
            const thread = data.thread; header.replaceChildren(el('h3', thread.subject));
            if (thread.context.student_name) header.append(el('p', thread.context.student_name + ' · ' + thread.context.subject_name + ' · ' + thread.context.study_year));
            if (root._suite?.actions) header.append(button('إجراءات المحادثة', () => window.OlamaSuiteClient.actions(root, 0, id)));
            if (thread.kind === 'service') header.append(el('p', thread.status === 'resolved' ? 'تم حل الطلب' : thread.department_responded_at_utc ? 'تم الرد من القسم' : thread.department_viewed_at_utc ? 'استلم القسم الطلب' : 'أُرسل الطلب'));
            status.textContent = data.send_state.allowed ? 'تُحفظ المسودة مؤقتاً لمدة 15 دقيقة وتُمسح عند تغيير الهوية أو مغادرة الصفحة.' + (data.send_state.office_hours_note ? ' · ' + data.send_state.office_hours_note : '') : data.send_state.reason;
            input.disabled = !data.send_state.allowed; send.disabled = !data.send_state.allowed || sending;
            form.dataset.blocked = data.send_state.allowed ? '0' : '1';
            controls.replaceChildren();
            ['pinned', 'muted', 'archived', 'manual_unread'].forEach((key, index) => {
                const active = Number(data.personal?.[key] || 0); const names = [['تثبيت', 'إلغاء التثبيت'], ['كتم', 'إلغاء الكتم'], ['أرشفة', 'إعادة للوارد'], ['تمييز للمتابعة كغير مقروء', 'إلغاء علامة المتابعة']];
                controls.append(button(names[index][active ? 1 : 0], async () => { await api('chat/threads/' + id + '/preferences', {[key]: !active}); if (key === 'manual_unread' || key === 'archived') await threads(root); else await load(); }));
            });
            if (data.member) {
                controls.append(el('p', thread.assignee_key ? 'المسؤول: ' + thread.assignee_key : 'لم يُعيّن مسؤول بعد'));
                if (!thread.assignee_key || thread.assignee_key === h.actor().actor_key) controls.append(button('استلام الطلب', async () => { await api('chat/threads/' + id + '/assign', {target: h.actor().actor_key}); await load(); }));
                if (data.member.manager) {
                    const assignForm = el('form', undefined, 'olama-comm-form'); const target = field(assignForm, 'target', 'هوية الموظف العضو (فارغ لإلغاء التعيين)', thread.assignee_key || '');
                    submit(assignForm, 'تعيين المسؤول', async () => { await api('chat/threads/' + id + '/assign', {target: target.value.trim()}); await load(); }); controls.append(assignForm);
                }
                if (thread.status === 'open') controls.append(button('بدء العمل', async () => { await api('chat/threads/' + id + '/start', {}); await load(); }));
                controls.append(button(thread.status === 'resolved' ? 'إعادة فتح' : 'حل الطلب', async () => { await api('chat/threads/' + id + '/' + (thread.status === 'resolved' ? 'reopen' : 'resolve'), {}); await threads(root); }));
                const history = el('details'); history.append(el('summary', 'سجل التعيين والإجراءات'));
                data.actions.forEach(item => history.append(el('p', date(item.created_at_utc) + ' · ' + item.action + ' · ' + item.actor_key + ' → ' + (item.target_key || '—')))); controls.append(history);
            }
            messages.replaceChildren();
            if (data.next) messages.append(button('رسائل أقدم', () => conversation(root, id, data.next)));
            if (before) messages.append(button('أحدث الرسائل', () => conversation(root, id)));
            data.messages.forEach(message => {
                const bubble = el('article', undefined, 'olama-chat-bubble' + (message.own ? ' olama-chat-own' : '')); bubble.append(el('strong', message.display_name));
                if (message.reply_to) bubble.append(el('blockquote', message.quote_body || 'اقتباس غير متاح'));
                window.OlamaSuiteClient?.attachments(bubble, message.attachments);
                bubble.append(el('p', message.body, 'olama-comm-message'), el('small', date(message.sent_at_utc) + (message.edited_at_utc ? ' · معدلة' : '')));
                if (message.own && thread.kind === 'direct') {
                    const read = data.receipts.length && data.receipts.every(r => Number(r.read_cursor) >= Number(message.id));
                    const delivered = data.receipts.length && data.receipts.every(r => Number(r.delivered_cursor) >= Number(message.id));
                    bubble.append(el('small', read ? ' · مقروءة ✓✓' : delivered ? ' · تم التسليم ✓✓' : ' · أُرسلت ✓'));
                }
                if (!message.redacted_at_utc && data.send_state.allowed) {
                    bubble.append(button('اقتباس', () => { replyTo = Number(message.id); editId = 0; retry = null; mode.textContent = 'رد على: ' + message.body.slice(0, 100); input.focus(); }));
                    if (message.own && Date.now() - new Date(message.sent_at_utc.replace(' ', 'T') + 'Z').getTime() < (config.editMinutes ?? 15) * 60000) bubble.append(button('تعديل', () => { editId = Number(message.id); replyTo = 0; retry = null; input.value = message.body; mode.textContent = 'تعديل الرسالة'; input.focus(); }));
                }
                bubble.append(button('إبلاغ', () => reasonForm(content, 'الإبلاغ عن رسالة', async reason => { await api('chat/messages/' + message.id + '/report', {reason}); toast('تم تسجيل البلاغ.'); }))); messages.append(bubble);
            });
            const max = data.messages.reduce((value, message) => Math.max(value, Number(message.id)), 0);
            if (max > lastDelivered) { await api('chat/threads/' + id + '/receipt', {kind: 'delivered', cursor: max}); lastDelivered = max; }
            if (visible(root) && root._view === view && max > lastRead) { await api('chat/threads/' + id + '/receipt', {kind: 'read', cursor: max}); lastRead = max; }
        };
        root._chatRefresh = load; await load();
    }

    async function manageInboxes(root, before = 0) {
        activateNav(root, 'inboxes'); const view = newView(root), data = await api('chat/inboxes?admin=1&before=' + before); if (root._view !== view) return;
        const content = body(root); content.replaceChildren(el('h3', 'صناديق الخدمة'), button('إنشاء صندوق', () => inboxForm(root)));
        data.items.forEach(row => { const card = el('article', undefined, 'olama-comm-card'); card.append(el('h3', row.name), el('p', row.history_policy + ' · ' + row.recent_days + ' يوم'), button('الإعدادات والأعضاء', () => inboxForm(root, row))); content.append(card); });
        if (data.next) content.append(button('المزيد', () => manageInboxes(root, data.next)));
    }
    function inboxForm(root, row = {}) {
        newView(root); const content = body(root); content.replaceChildren(el('h3', row.id ? 'إعداد صندوق الخدمة' : 'صندوق جديد'));
        const form = el('form', undefined, 'olama-comm-form'); field(form, 'name', 'اسم القسم', row.name || '').required = true;
        ['active', 'allow_family', 'allow_teacher', 'show_employee'].forEach((key, index) => choice(form, key, ['الصندوق فعال', 'استقبال الأسر', 'استقبال المعلمين', 'عرض اسم الموظف مع القسم'][index], {'1': 'نعم', '0': 'لا'}, String(row[key] ?? (key === 'show_employee' ? 0 : 1))));
        choice(form, 'history_policy', 'سياسة الوصول إلى السجل', {active_only: 'المفتوح والمعين للعضو', active_and_recent: 'المفتوح والمعين والمشاركة الحديثة', all_history: 'كل السجل للأعضاء', manager_history_only: 'المفتوح فقط؛ السجل للمدير'}, row.history_policy || 'active_and_recent');
        field(form, 'response_sla_minutes', 'مهلة الرد بالدقائق (0 لتعطيل التصعيد)', row.response_sla_minutes ?? 1440, 'number');
        field(form, 'resolution_sla_minutes', 'مهلة الحل بالدقائق (0 لتعطيل التصعيد)', row.resolution_sla_minutes ?? 4320, 'number');
        field(form, 'recent_days', 'نافذة السجل الحديث بالأيام', row.recent_days || 30, 'number');
        field(form, 'members', 'الأعضاء: هوية موظف في كل سطر. أضف manager بعد هوية مدير الصندوق.', (row.members || []).filter(member => Number(member.active)).map(member => member.actor_key + (Number(member.is_manager) ? ' manager' : '')).join('\n'), 'textarea');
        form.append(el('p', 'الحفظ يستبدل قائمة الأعضاء النشطين. العضو المحذوف يفقد الوصول فوراً؛ تبقى ردوده محفوظة.'));
        submit(form, 'حفظ الصندوق', async () => {
            const values = Object.fromEntries(new FormData(form)); ['active', 'allow_family', 'allow_teacher', 'show_employee'].forEach(key => values[key] = values[key] === '1');
            values.members = values.members.split('\n').filter(line => line.trim()).map(line => { const parts = line.trim().split(/\s+/); return {actor_key: parts[0], active: true, is_manager: parts[1] === 'manager'}; });
            await api('chat/inboxes' + (row.id ? '/' + row.id : ''), values); await manageInboxes(root);
        }); content.append(form);
    }

    async function reports(root, before = 0, status = 'open') {
        activateNav(root, 'reports'); const view = newView(root), rows = await api('chat/reports?before=' + before + '&status=' + status); if (root._view !== view) return;
        const content = body(root); content.replaceChildren(el('h3', 'بلاغات المراسلات'));
        Object.entries(labels).filter(([key]) => key !== 'resolved').forEach(([key, label]) => content.append(button(label, () => reports(root, 0, key))));
        rows.forEach(row => {
            const card = el('article', undefined, 'olama-comm-card'); card.append(el('h4', 'بلاغ #' + row.id), el('p', row.reason), el('small', row.reporter_key + ' · ' + date(row.created_at_utc)));
            card.append(button('مراجعة سياق المحادثة', () => reasonForm(card, 'سبب فتح المحتوى الخاص — يسجل في التدقيق', reason => privileged(root, {report_id: row.id, reason}))));
            ['reviewed', 'actioned', 'dismissed'].forEach(next => card.append(button(labels[next], () => reasonForm(card, 'ملاحظة معالجة البلاغ', async note => { await api('chat/reports/' + row.id, {status: next, note}); await reports(root, before, status); })))); content.append(card);
        });
        if (rows.length === 30) content.append(button('بلاغات أقدم', () => reports(root, rows[rows.length - 1].id, status)));
    }
    function auditForm(root) {
        newView(root); const content = body(root); content.replaceChildren(el('h3', 'تدقيق المحتوى الخاص'));
        const form = el('form', undefined, 'olama-comm-form'); const id = field(form, 'id', 'رقم المحادثة', '', 'number'); id.required = true;
        const reason = field(form, 'reason', 'سبب التدقيق', '', 'textarea'); reason.required = true;
        submit(form, 'فتح وتسجيل الوصول', () => privileged(root, {thread_id: Number(id.value), reason: reason.value})); content.append(form);
    }
    async function privileged(root, request) {
        const view = newView(root), data = await api('chat/context', request); if (root._view !== view) return;
        const content = body(root); content.replaceChildren(el('h3', data.thread.subject), el('p', 'وصول خاص مسجل في سجل التدقيق.'));
        data.messages.forEach(message => {
            const card = el('article', undefined, 'olama-comm-card'); card.append(el('strong', message.sender_key + ' · WP ' + message.authenticated_wp_user_id), el('p', message.redacted_at_utc ? 'رسالة محجوبة' : message.body, 'olama-comm-message'));
            card.append(button('سجل التعديلات', async () => {
                const revisions = await api('chat/messages/' + message.id + '/revisions', {report_id: request.report_id || 0, reason: request.reason});
                revisions.forEach(revision => card.append(el('p', date(revision.created_at_utc) + '\n' + revision.body, 'olama-comm-message')));
            }));
            if (request.report_id && !message.redacted_at_utc) card.append(button('حجب الرسالة مع الاحتفاظ بالسجل', () => reasonForm(card, 'سبب الحجب', async reason => { await api('chat/messages/' + message.id + '/redact', {report_id: request.report_id, reason}); await privileged(root, request); })));
            content.append(card);
        });
        if (data.next) content.append(button('سياق أقدم', () => privileged(root, {...request, before: data.next})));
    }
    async function restrictions(root, before = 0) {
        activateNav(root, 'restrictions'); const view = newView(root), rows = await api('chat/restrictions?before=' + before); if (root._view !== view) return;
        const content = body(root); content.replaceChildren(el('h3', 'قيود المراسلات'), button('إضافة قيد', () => restrictionForm(root)));
        rows.forEach(row => {
            const card = el('article', undefined, 'olama-comm-card'); card.append(el('h4', row.actor_key + ' · ' + row.restriction_type), el('p', row.scope_type + ' ' + row.scope_key + ' · ' + row.target_key), el('p', row.private_reason), el('p', date(row.starts_at_utc) + ' — ' + (row.ends_at_utc ? date(row.ends_at_utc) : 'دون نهاية محددة')));
            if (!row.revoked_at_utc) card.append(button('رفع القيد', async () => { await api('chat/restrictions/' + row.id + '/revoke', {}); await restrictions(root, before); })); else card.append(el('p', 'تم رفع القيد')); content.append(card);
        });
        if (rows.length === 30) content.append(button('قيود أقدم', () => restrictions(root, rows[rows.length - 1].id)));
    }
    function restrictionForm(root) {
        newView(root); const content = body(root); content.replaceChildren(el('h3', 'قيد جديد'));
        const form = el('form', undefined, 'olama-comm-form'); field(form, 'actor_key', 'الهوية الخاضعة للقيد، أو * لجميع الهويات').required = true;
        choice(form, 'restriction_type', 'نوع القيد', {attachment_block: 'منع المرفقات', send_block: 'منع الإرسال والتعديل', new_thread_block: 'منع المحادثات الجديدة', target_block: 'منع المراسلة مع جهة', chat_block: 'منع إجراءات المراسلة'}, 'send_block');
        choice(form, 'scope_type', 'النطاق', {global: 'جميع المحادثات', actor: 'هوية محددة', service_inbox: 'صندوق خدمة', thread: 'محادثة'}, 'global');
        field(form, 'scope_key', 'معرف النطاق (فارغ للنطاق العام)'); field(form, 'target_key', 'هوية الجهة المستهدفة (مطلوبة لمنع جهة)');
        field(form, 'ends_at_utc', 'نهاية القيد الاختيارية بالتوقيت العالمي UTC', '', 'datetime-local'); field(form, 'private_reason', 'سبب داخلي للرقابة فقط', '', 'textarea').required = true;
        submit(form, 'حفظ القيد', async () => { const data = Object.fromEntries(new FormData(form)); data.ends_at_utc = data.ends_at_utc ? data.ends_at_utc.replace('T', ' ') + ':00' : null; await api('chat/restrictions', data); await restrictions(root); }); content.append(form);
    }
}());
