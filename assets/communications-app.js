/* OLAMA Release A: plain-text DOM rendering and actor/session-scoped client state. */
(function () {
    'use strict';
    const config = window.OlamaCommunications;
    if (!config || !config.actors.length) return;
    const actorStorage = 'olama-context:' + config.userId + ':' + config.sessionScope;
    const safeStore = {
        get(store, key) { try { return store.getItem(key); } catch (_) { return null; } },
        set(store, key, value) { try { store.setItem(key, value); return true; } catch (_) { return false; } },
        remove(store, key) { try { store.removeItem(key); } catch (_) { /* unavailable */ } }
    };
    let actor = config.actors.find(a => a.actor_key === safeStore.get(sessionStorage, actorStorage)) || config.actors[0];
    let generation = 0, timer, channel, leaseKey, cursor = 0, initialized = false, stopped = false;
    let counts = {notices: 0, notifications: 0};
    const owner = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : String(Math.random());
    const roots = new Set();
    const statusNames = {draft: 'مسودة', preparing: 'جارٍ تحضير الجمهور', preparation_failed: 'فشل التحضير', prepared: 'جاهز للنشر', published: 'منشور', cancelled: 'ملغى', archived: 'مؤرشف'};
    const reachNames = {eligible_account: 'حساب فعال ومخول', inactive: 'حساب موقوف', no_account: 'دون حساب', no_access: 'دون صلاحية', ineligible: 'غير مؤهل حالياً', outside_pilot: 'خارج حسابات التجربة', identity_unavailable: 'تعذر التحقق من الهوية', unchecked: 'لم يُفحص'};

    function el(tag, text, cls) { const node = document.createElement(tag); if (text !== undefined) node.textContent = text; if (cls) node.className = cls; return node; }
    function button(label, action, cls) {
        const node = el('button', label, cls); node.type = 'button';
        node.addEventListener('click', async () => {
            node.disabled = true;
            try { await action(); } catch (error) { if (error.name !== 'AbortError') toast(error.message); }
            finally { node.disabled = false; }
        });
        return node;
    }
    function date(value) {
        if (!value) return '—';
        const instant = new Date(value.replace(' ', 'T') + 'Z');
        try { return instant.toLocaleString('ar-JO', {timeZone: config.timezone || 'Asia/Amman'}); }
        catch (_) { return instant.toLocaleString('ar-JO'); }
    }
    async function api(path, data) {
        const context = actor.actor_key, epoch = generation;
        const response = await fetch(config.root + path, {method: data === undefined ? 'GET' : 'POST', credentials: 'same-origin', cache: 'no-store', headers: {'X-WP-Nonce': config.nonce, 'X-Olama-Actor': context, 'Content-Type': 'application/json'}, body: data === undefined ? undefined : JSON.stringify(data)});
        const result = await response.json();
        if (epoch !== generation || context !== actor.actor_key) throw new DOMException('Identity changed', 'AbortError');
        if (!response.ok) {
            if (response.status === 401 || response.status === 403) document.dispatchEvent(new Event('olama-context-cleared'));
            if (response.status === 401 || response.status === 403) { stopped = true; releaseCoordinator(); toasts.replaceChildren(); counts = {notices: 0, notifications: 0}; updateBadges(); roots.forEach(root => root.replaceChildren(el('p', 'انتهت الجلسة أو تغيرت الصلاحيات. أعد تحميل الصفحة.'))); }
            throw new Error(result.message || 'تعذر إكمال الطلب.');
        }
        return result;
    }
    const toasts = el('aside', undefined, 'olama-comm-toasts'); toasts.dir = 'rtl'; toasts.setAttribute('aria-live', 'polite'); document.body.append(toasts);
    function toast(message, open, openLabel = 'فتح الإعلان') {
        const item = el('div', undefined, 'olama-comm-toast'); item.append(el('span', message));
        if (open) item.append(button(openLabel, open));
        item.append(button('إغلاق', () => item.remove())); toasts.append(item);
        setTimeout(() => item.remove(), 12000);
    }
    const globalButton = button('إعلانات المدرسة', () => openPanel(), 'olama-comm-launcher'); document.body.append(globalButton);
    let dialog, dialogReady;
    async function openPanel(id) {
        if (!dialog) {
            dialog = el('dialog', undefined, 'olama-comm-dialog'); dialog.dir = 'rtl';
            dialog.append(button('إغلاق', () => dialog.close()));
            const root = el('section', undefined, 'olama-communications'); root.dir = 'rtl'; dialog.append(root); document.body.append(dialog); dialogReady = mount(root);
        }
        if (!dialog.open) dialog.showModal();
        await dialogReady;
        if (id) await showNotice(dialog.querySelector('.olama-communications'), id);
    }
    function updateBadges() {
        globalButton.textContent = 'الإعلانات (' + counts.notices + ') · الإشعارات (' + counts.notifications + ')' + (config.chat ? ' · الرسائل (' + (counts.chat || 0) + ')' : '');
    }

    function contextKey() { return 'olama-communications:' + config.userId + ':' + config.sessionScope + ':' + actor.actor_key; }
    function releaseCoordinator() {
        clearTimeout(timer);
        if (channel) channel.close(); channel = null;
        if (leaseKey) {
            try { const lease = JSON.parse(safeStore.get(localStorage, leaseKey)); if (lease && lease.owner === owner) safeStore.remove(localStorage, leaseKey); } catch (_) { /* old lease */ }
        }
    }
    function leader() {
        let lease;
        try { lease = JSON.parse(safeStore.get(localStorage, leaseKey)); } catch (_) { lease = null; }
        if (lease && lease.owner !== owner && lease.until > Date.now()) return false;
        // Coordination is advisory; unique receipt updates make racing leaders harmless.
        safeStore.set(localStorage, leaseKey, JSON.stringify({owner, until: Date.now() + 150000}));
        return true;
    }
    function startCoordinator() {
        releaseCoordinator(); stopped = false; cursor = 0; initialized = false;
        const scope = contextKey(); leaseKey = scope + ':leader';
        if (window.BroadcastChannel) {
            channel = new BroadcastChannel(scope);
            channel.onmessage = event => {
                if (event.data && event.data.actor === actor.actor_key && event.data.counts) { counts = event.data.counts; updateBadges(); document.dispatchEvent(new CustomEvent('olama-chat-poll', {detail: event.data.chatFeed || null})); document.dispatchEvent(new CustomEvent('olama-suite-poll', {detail: event.data.suiteFeed || null})); }
            };
        }
        if (config.notifications || config.chat || config.suite) poll();
    }
    async function poll() {
        const epoch = generation;
        if (stopped) return;
        try {
            if (!channel || leader()) {
                const rows = config.notifications ? await api('notifications?after=' + cursor) : [];
                counts = await api('counts'); updateBadges();
                let chatFeed = null; const suiteFeed = window.OlamaSuiteClient ? await window.OlamaSuiteClient.poll() : null;
                if (config.chat && window.OlamaChatClient) { chatFeed = await window.OlamaChatClient.poll(); counts.chat = chatFeed.unread; updateBadges(); }
                if (channel) channel.postMessage({actor: actor.actor_key, counts, chatFeed, suiteFeed});
                const ids = rows.map(row => Number(row.delivery_id));
                if (ids.length) await api('receipts', {kind: 'delivered', ids});
                const toastKey = contextKey() + ':toast';
                const shown = Number(safeStore.get(localStorage, toastKey) || 0);
                if (initialized && document.visibilityState === 'visible') {
                    rows.filter(row => Number(row.id) > shown).slice(-3).forEach(row => (window.OlamaSuiteClient ? window.OlamaSuiteClient.notify : toast)(row.rendered_title, () => openPanel(row.delivery_id)));
                }
                rows.forEach(row => { cursor = Math.max(cursor, Number(row.id)); });
                safeStore.set(localStorage, toastKey, String(Math.max(shown, cursor))); initialized = true;
            }
        } catch (error) { if (error.name !== 'AbortError' && stopped) toast(error.message); }
        finally { if (!stopped && epoch === generation) timer = setTimeout(poll, (document.hidden ? Math.max(60, config.pollSeconds) : config.pollSeconds) * 1000); }
    }
    function switchActor(key) {
        const next = config.actors.find(item => item.actor_key === key); if (!next) return;
        document.dispatchEvent(new Event('olama-context-cleared'));
        generation++; actor = next; counts = {notices: 0, notifications: 0};
        safeStore.set(sessionStorage, actorStorage, key); toasts.replaceChildren(); updateBadges();
        roots.forEach(root => mount(root)); startCoordinator();
    }
    function body(root) { return root.querySelector('[data-comm-body]'); }
    function newView(root) { window.OlamaSuiteClient?.closePreviews(); root._chatRefresh = null; root._chatRead = null; root._view = (root._view || 0) + 1; return root._view; }
    async function mount(root) {
        roots.add(root); root._suite = null; root.replaceChildren();
        const head = el('header', undefined, 'olama-comm-head'); head.append(el('small', 'OLAMA COMMUNICATIONS'), el('h2', 'اتصالات المدرسة'), el('p', 'الإعلانات والمراسلات الخاصة لحسابك في مكان واحد.'));
        const select = el('select'); select.setAttribute('aria-label', 'استخدام OLAMA كـ');
        config.actors.forEach(item => { const option = el('option', (item.actor_type === 'family' ? 'حساب الأسرة — ' : 'موظف — ') + item.display_name); option.value = item.actor_key; option.selected = item.actor_key === actor.actor_key; select.append(option); });
        select.addEventListener('change', () => switchActor(select.value)); head.append(select); root.append(head);
        const nav = el('nav', undefined, 'olama-comm-nav'); nav.setAttribute('aria-label', 'أقسام الاتصالات');
        nav.append(button('الإعلانات', () => showNotices(root)), button('الإشعارات', () => showNotifications(root)));
        root.append(nav); const content = el('div'); content.dataset.commBody = ''; root.append(content);
        try {
            const me = await api('me');
            root._me = me;
            if (me.can_manage) nav.append(button('إدارة الإعلانات', () => showCampaigns(root)), button('صحة النظام', () => showHealth(root)));
            document.dispatchEvent(new CustomEvent('olama-chat-mount', {detail: {root, nav, me}}));
            await showNotices(root);
        } catch (error) { if (error.name !== 'AbortError') content.replaceChildren(el('p', error.message)); }
    }
    async function showNotices(root, before = 0) {
        const view = newView(root), rows = await api('notices?before=' + before); if (root._view !== view) return;
        const content = body(root); content.replaceChildren(el('h3', 'الإعلانات الرسمية'));
        if (!rows.length) content.append(el('p', 'لا توجد إعلانات متاحة.', 'olama-comm-empty'));
        rows.forEach(row => {
            const card = el('article', undefined, 'olama-comm-card'); card.append(el('small', 'إدارة المدرسة · ' + date(row.sent_at_utc)), el('h3', row.rendered_title));
            card.append(el('p', row.acknowledged_at_utc ? 'تم الإقرار بالاطلاع' : (row.read_at_utc ? 'تمت القراءة' : 'غير مقروء')));
            if (row.campaign_status !== 'published') card.append(el('p', statusNames[row.campaign_status] || row.campaign_status));
            card.append(button('عرض الإعلان', () => showNotice(root, row.id))); content.append(card);
        });
        if (rows.length === 30) content.append(button('إعلانات أقدم', () => showNotices(root, rows[rows.length - 1].id)));
        if (before) content.append(button('الأحدث', () => showNotices(root)));
        if (rows.length) await api('receipts', {kind: 'delivered', ids: rows.map(row => row.id)});
    }
    async function showNotice(root, id) {
        const view = newView(root), row = await api('notices/' + Number(id)); if (root._view !== view) return;
        const content = body(root); if (!content) return;
        content.replaceChildren(button('العودة للإعلانات', () => showNotices(root)), el('small', 'إدارة المدرسة · ' + date(row.sent_at_utc)), el('h3', row.rendered_title), el('p', row.rendered_body, 'olama-comm-message'));
        if (row.campaign_status !== 'published') content.append(el('p', statusNames[row.campaign_status] || row.campaign_status));
        window.OlamaSuiteClient?.attachments(content, row.attachments);
        if (row.action_id) content.append(button('متابعة الإجراء المطلوب', () => window.OlamaSuiteClient.actionDetail(root, row.action_id)));
        if (row.purpose === 'acknowledgement') content.append(row.acknowledged_at_utc ? el('p', 'تم الإقرار بالاطلاع · ' + date(row.acknowledged_at_utc)) : button('تم الاطلاع', async () => { await api('receipts', {kind: 'acknowledge', ids: [row.id]}); await showNotice(root, row.id); }, 'olama-comm-primary'));
        await api('receipts', {kind: 'delivered', ids: [row.id]});
        const read = async () => {
            if (document.hidden || root._view !== view || !root.isConnected || (root.closest('dialog') && !root.closest('dialog').open)) return;
            await api('receipts', {kind: 'read', ids: [row.id]}); counts = await api('counts'); updateBadges();
        };
        if (!document.hidden) await read();
        else document.addEventListener('visibilitychange', () => read().catch(() => {}), {once: true});
    }
    async function showNotifications(root, before = 0, unseen = false) {
        const view = newView(root), rows = await api('notifications?before=' + before + '&unseen=' + (unseen ? 1 : 0)); if (root._view !== view) return;
        const content = body(root); content.replaceChildren(el('h3', 'مركز الإشعارات'), button(unseen ? 'عرض الكل' : 'غير المشاهدة', () => showNotifications(root, 0, !unseen)));
        if (root._suite?.events || root._suite?.actions) content.append(button('تنبيهات الفعاليات والإجراءات والخدمة', () => window.OlamaSuiteClient.activity(root)));
        if (!rows.length) content.append(el('p', 'لا توجد إشعارات.'));
        rows.forEach(row => {
            const card = el('article', undefined, 'olama-comm-card'); card.append(el('h3', row.rendered_title), el('small', date(row.created_at_utc)), button('فتح', () => showNotice(root, row.delivery_id)));
            if (!row.seen_at_utc) card.append(button('تمييز كمشاهد', async () => { await api('receipts', {kind: 'seen', ids: [row.delivery_id]}); await showNotifications(root, before, unseen); }));
            content.append(card);
        });
        if (rows.length === 50) content.append(button('إشعارات أقدم', () => showNotifications(root, rows[rows.length - 1].id, unseen)));
        if (rows.length) await api('receipts', {kind: 'delivered', ids: rows.map(row => row.delivery_id)});
    }
    function field(form, name, label, value = '', type = 'text') {
        const wrapper = el('label', label), input = el(type === 'textarea' ? 'textarea' : 'input'); input.name = name; input.value = value;
        if (type !== 'textarea') input.type = type; wrapper.append(input); form.append(wrapper); return input;
    }
    function choice(form, name, label, values, selected) {
        const wrapper = el('label', label), input = el('select'); input.name = name;
        Object.entries(values).forEach(([value, title]) => { const option = el('option', title); option.value = value; option.selected = value === selected; input.append(option); }); wrapper.append(input); form.append(wrapper); return input;
    }
    function editCampaign(root, existing) {
        newView(root); const content = body(root); content.replaceChildren(el('h3', existing ? 'تعديل المسودة' : 'إعلان رسمي جديد'));
        const spec = existing ? JSON.parse(existing.audience_json) : {};
        const form = el('form', undefined, 'olama-comm-form');
        field(form, 'title', 'عنوان الإعلان', existing ? existing.title : '').maxLength = 190;
        field(form, 'body', 'نص الإعلان — يمكن استخدام {recipient_name}', existing ? existing.message_body_draft : '', 'textarea').maxLength = 5000;
        const purposes = {information: 'للعلم', acknowledgement: 'يتطلب إقراراً بالاطلاع'}; if (root._suite?.actions) purposes.action_required = 'يتطلب إجراء';
        choice(form, 'purpose', 'الغرض', purposes, existing ? existing.purpose : 'information');
        choice(form, 'type', 'الجمهور', {general: 'الأسر النشطة / صف أو شعبة', employees: 'جميع الموظفين النشطين', selected: 'هويات محددة', collection: 'جمهور المستحقات المالية', transportation: 'جمهور المواصلات', renewal_reminder: 'جمهور تذكير التجديد'}, spec.type || 'general');
        field(form, 'study_year', 'السنة الدراسية (فارغ للسنة الحالية)', spec.study_year || '');
        field(form, 'class_id', 'معرف الصف من Core — للجمهور العام فقط', spec.class_id || '');
        field(form, 'section_id', 'معرف الشعبة من Core — للجمهور العام فقط', spec.section_id || '');
        field(form, 'family_id', 'معرف الأسرة الخارجي — للجمهور العام فقط', spec.family_id || '');
        field(form, 'actor_keys', 'هويات محددة: family:UID أو employee:ID، هوية في كل سطر', (spec.actor_keys || []).join('\n'), 'textarea');
        if (root._suite?.actions) { field(form, 'action_due_at_utc', 'مهلة الإجراء UTC', existing?.workflow_json ? (JSON.parse(existing.workflow_json).due_at_utc || '').replace(' ', 'T').slice(0,16) : '', 'datetime-local'); }
        const files = root._suite?.attachments ? window.OlamaSuiteClient.picker(form, 'campaign', existing?.id || 0, existing?.attachments || []) : null;
        const submit = el('button', 'حفظ المسودة', 'olama-comm-primary'); submit.type = 'submit'; form.append(submit);
        form.addEventListener('submit', async event => {
            event.preventDefault(); submit.disabled = true;
            try {
                const values = new FormData(form), audience = {type: values.get('type')};
                ['study_year', 'class_id', 'section_id', 'family_id'].forEach(key => { if (values.get(key).trim()) audience[key] = values.get(key).trim(); });
                if (audience.type === 'selected') audience.actor_keys = values.get('actor_keys').split(/[\s,]+/).filter(Boolean);
                const result = await api('campaigns' + (existing ? '/' + existing.id : ''), {title: values.get('title'), body: values.get('body'), purpose: values.get('purpose'), audience, ...(files ? {attachment_ids: files.ids()} : {}), action_due_at_utc: values.get('action_due_at_utc') ? values.get('action_due_at_utc').replace('T', ' ') + ':00' : null}); await showCampaign(root, result.id);
            } catch (error) { toast(error.message); } finally { submit.disabled = false; }
        }); content.append(form);
    }
    async function showCampaigns(root, before = 0) {
        const view = newView(root), rows = await api('campaigns?before=' + before); if (root._view !== view) return;
        const content = body(root); content.replaceChildren(el('h3', 'الإعلانات الداخلية'), button('إعلان جديد', () => editCampaign(root), 'olama-comm-primary'));
        rows.forEach(row => { const card = el('article', undefined, 'olama-comm-card'); card.append(el('h3', row.title), el('p', statusNames[row.status] || row.status), button('عرض ومتابعة', () => showCampaign(root, row.id))); content.append(card); });
        if (rows.length === 30) content.append(button('الأقدم', () => showCampaigns(root, rows[rows.length - 1].id)));
    }
    async function showCampaign(root, id) {
        const view = newView(root), result = await api('campaigns/' + id); if (root._view !== view) return;
        const row = result.campaign, stats = result.statistics, content = body(root);
        content.replaceChildren(el('h3', row.title), el('p', statusNames[row.status] || row.status), el('p', row.message_body_draft, 'olama-comm-message'));
        content.append(el('p', 'لقطة الجمهور: ' + (row.resolution_started_at_utc ? date(row.resolution_started_at_utc) + ' — ' + date(row.resolution_completed_at_utc) : 'لم يبدأ التحضير')));
        window.OlamaSuiteClient?.attachments(content, row.attachments);
        (stats.actions || []).forEach(item => content.append(el('p', 'الإجراءات — ' + ({open:'مفتوح',in_progress:'قيد التنفيذ',resolved:'منجز',cancelled:'ملغى'}[item.status] || item.status) + ': ' + item.total)));
        const metrics = el('div', undefined, 'olama-comm-metrics');
        stats.reachability.forEach(item => metrics.append(el('p', (reachNames[item.reachability] || item.reachability) + ': ' + item.total + ' · آخر فحص ' + date(item.checked_at_utc))));
        Object.entries({sent: 'أُرسل داخلياً', delivered: 'تأكد التسليم للعميل', read: 'مقروء', acknowledged: 'أُقر بالاطلاع'}).forEach(([key, label]) => metrics.append(el('p', label + ': ' + (stats.receipts[key] || 0)))); content.append(metrics);
        const action = command => async () => { await api('campaigns/' + id + '/' + command, {}); await (command === 'delete' ? showCampaigns(root) : showCampaign(root, id)); };
        if (row.status === 'draft') content.append(button('تعديل', () => editCampaign(root, row)), button('تحضير لقطة الجمهور', action('prepare')), button('حذف المسودة', action('delete')));
        if (row.status === 'prepared') {
            const publish = el('div', undefined, 'olama-comm-card'); publish.append(el('p', 'راجع نص الإعلان والجمهور أعلاه. النشر يحفظ الإعلان وجمهوره ويبدأ التسليم الداخلي.'));
            const when = field(publish, 'schedule', 'موعد اختياري بالتوقيت العالمي UTC', '', 'datetime-local');
            publish.append(button('نشر الإعلان', async () => { await api('campaigns/' + id + '/publish', {scheduled_at_utc: when.value}); await showCampaign(root, id); }, 'olama-comm-primary')); content.append(publish);
        }
        if (row.status === 'published') content.append(button('إعادة فحص الحسابات واستكمال التسليم', action('retry_delivery')));
        if (!['cancelled', 'archived', 'draft'].includes(row.status)) content.append(button('إلغاء الإعلان', action('cancel')));
        if (row.published_at_utc && row.status !== 'archived') content.append(button('أرشفة', action('archive')));
        if (!row.published_at_utc && ['prepared', 'preparation_failed', 'cancelled'].includes(row.status)) content.append(button('إعادة إلى مسودة ولقطة جديدة', action('reset')));
        content.append(button('تحديث الحالة', () => showCampaign(root, id)));
    }
    async function showHealth(root) {
        const view = newView(root), health = await api('health'); if (root._view !== view) return;
        const content = body(root); content.replaceChildren(el('h3', 'صحة النظام'), el('p', 'آخر تشغيل للمعالج: ' + date(health.heartbeat_utc)), el('p', 'الهوية: حساب واحد موثّق؛ اختيار الهويات المتعددة ينتظر API معتمداً من OLAMA Users.'));
        health.failed_jobs.forEach(job => { const card = el('article', undefined, 'olama-comm-card'); card.append(el('p', '#' + job.id + ' · ' + job.job_type + ' · ' + job.last_error), button('إعادة المحاولة', async () => { await api('jobs/' + job.id + '/retry', {}); await showHealth(root); })); content.append(card); });
        content.append(el('pre', JSON.stringify(health, null, 2), 'olama-comm-diagnostics'), button('تحديث', () => showHealth(root)));
    }
    window.OlamaChatHost = {api, el, button, date, field, choice, body, newView, toast, config, roots, actor: () => actor, scope: contextKey, openPanel, panelRoot: () => dialog && dialog.querySelector('.olama-communications')};
    document.querySelectorAll('[data-olama-communications]').forEach(root => mount(root));
    document.addEventListener('click', event => { const link = event.target.closest('a'); if (link && /action=logout/.test(link.href)) { document.dispatchEvent(new Event('olama-context-cleared')); releaseCoordinator(); safeStore.remove(sessionStorage, actorStorage); } });
    window.addEventListener('pagehide', releaseCoordinator);
    window.addEventListener('pageshow', event => { if (event.persisted) { generation++; roots.forEach(root => mount(root)); startCoordinator(); } });
    startCoordinator();
}());
