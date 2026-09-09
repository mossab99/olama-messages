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
    function pageHeader(title, description, eyebrow) {
        const header = el('header', undefined, 'olama-comm-page-head');
        const copy = el('div');
        if (eyebrow) copy.append(el('small', eyebrow, 'olama-comm-eyebrow'));
        copy.append(el('h3', title));
        if (description) copy.append(el('p', description));
        header.append(copy);
        const actions = el('div', undefined, 'olama-comm-page-actions');
        header.append(actions);
        return {header, actions};
    }
    function activateNav(root, id) {
        root.querySelectorAll('[data-comm-nav-item]').forEach(item => {
            const active = item.dataset.commNavItem === id;
            item.classList.toggle('is-active', active);
            if (active) item.setAttribute('aria-current', 'page'); else item.removeAttribute('aria-current');
        });
    }
    function addNav(root, nav, label, action, options = {}) {
        const group = options.group === 'admin' ? 'admin' : 'workspace';
        const target = nav.querySelector('[data-comm-nav-' + group + ']') || nav;
        const item = button(label, async () => {
            activateNav(root, options.id || label);
            await action();
        }, 'olama-comm-nav-item');
        item.dataset.commNavItem = options.id || label;
        if (options.chat) item.dataset.chatNav = '';
        target.append(item);
        if (group === 'admin') target.closest('.olama-comm-nav-group')?.removeAttribute('hidden');
        return item;
    }
    function openNav(root, id) {
        const item = root.querySelector('[data-comm-nav-item="' + id + '"]');
        if (item) item.click();
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
    const globalButton = button('اتصالات المدرسة', () => openPanel(), 'olama-comm-launcher'); document.body.append(globalButton);
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
        const total = Number(counts.notices || 0) + Number(counts.notifications || 0) + Number(counts.chat || 0);
        globalButton.textContent = total ? 'اتصالات المدرسة · ' + total + ' جديد' : 'اتصالات المدرسة';
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
    function newView(root) {
        window.OlamaSuiteClient?.closePreviews(); root._chatRefresh = null; root._chatRead = null;
        if (root._chatResize) window.removeEventListener('resize', root._chatResize); root._chatResize = null;
        root.style.removeProperty('--olama-chat-height');
        root.classList.remove('olama-comm-chat-focus', 'is-chat-nav-open');
        if (!document.querySelector('.olama-comm-chat-focus')) document.body.classList.remove('olama-chat-is-open');
        root.querySelectorAll('.olama-chat-popover,.olama-chat-drawer,.olama-chat-backdrop').forEach(node => node.remove());
        root._view = (root._view || 0) + 1; return root._view;
    }
    async function showHome(root) {
        activateNav(root, 'home');
        const view = newView(root), content = body(root);
        const heading = pageHeader('مساحة الاتصالات', 'ابدأ من العمل الذي يحتاج إلى انتباهك، أو انتقل مباشرة إلى أحد الأقسام.', 'نظرة عامة');
        content.replaceChildren(heading.header);
        const loading = el('p', 'جارٍ تحميل الملخص…', 'olama-comm-empty'); content.append(loading);
        const summary = await api('counts'); if (root._view !== view) return;
        counts = {...counts, ...summary}; updateBadges(); loading.remove();
        const metrics = el('div', undefined, 'olama-comm-home-metrics');
        const metric = (label, value, detail, target) => {
            const item = button('', () => openNav(root, target), 'olama-comm-home-metric');
            item.append(el('small', label), el('strong', String(value || 0)), el('span', detail)); metrics.append(item);
        };
        metric('إعلانات غير مقروءة', summary.notices, 'الإعلانات الرسمية', 'notices');
        if (config.chat) metric('محادثات غير مقروءة', counts.chat, 'المراسلات المباشرة', 'conversations');
        metric('إشعارات جديدة', summary.notifications, 'التنبيهات والمتابعة', 'notifications');
        content.append(metrics);
        const shortcuts = el('section', undefined, 'olama-comm-home-section');
        shortcuts.append(el('h4', 'الوصول السريع'), el('p', 'الوظائف الأكثر استخداماً مجمعة حسب نوع العمل.'));
        const grid = el('div', undefined, 'olama-comm-shortcuts');
        [
            ['المحادثات', 'رسائل الأسر والمعلمين والموظفين', 'conversations'],
            ['طلبات الخدمة', 'طلبات الأقسام وحالة الاستجابة', 'requests'],
            ['التقويم', 'الفعاليات والتذكيرات والاستجابات', 'calendar'],
            ['الإجراءات', 'المهام المطلوبة ومواعيدها', 'actions'],
            ['مركز النشر', 'إنشاء الإعلانات ومتابعة وصولها', 'campaigns'],
            ['التقارير', 'مؤشرات التشغيل والمهل', 'dashboard']
        ].forEach(([title, detail, target]) => {
            if (!root.querySelector('[data-comm-nav-item="' + target + '"]')) return;
            const item = button('', () => openNav(root, target), 'olama-comm-shortcut');
            item.append(el('strong', title), el('span', detail)); grid.append(item);
        });
        shortcuts.append(grid); content.append(shortcuts);
    }
    async function mount(root) {
        roots.add(root); root._suite = null; root.replaceChildren();
        const head = el('header', undefined, 'olama-comm-head');
        const brand = el('div', undefined, 'olama-comm-brand'); brand.append(el('span', 'O', 'olama-comm-logo'));
        const brandCopy = el('div'); brandCopy.append(el('h2', 'اتصالات المدرسة'), el('small', 'OLAMA Communications')); brand.append(brandCopy); head.append(brand);
        const context = el('label', undefined, 'olama-comm-context'); context.append(el('span', 'استخدام النظام بصفة'));
        const select = el('select'); select.setAttribute('aria-label', 'استخدام OLAMA كـ');
        config.actors.forEach(item => { const type = item.actor_type === 'family' ? 'حساب الأسرة — ' : item.actor_type === 'administrator' ? 'مدير النظام — ' : 'موظف — '; const option = el('option', type + item.display_name); option.value = item.actor_key; option.selected = item.actor_key === actor.actor_key; select.append(option); });
        select.addEventListener('change', () => switchActor(select.value)); context.append(select); head.append(context); root.append(head);
        const shell = el('div', undefined, 'olama-comm-shell');
        const nav = el('nav', undefined, 'olama-comm-nav'); nav.setAttribute('aria-label', 'أقسام الاتصالات');
        const workspaceGroup = el('section', undefined, 'olama-comm-nav-group'); workspaceGroup.append(el('small', 'مساحة العمل'));
        const workspace = el('div'); workspace.dataset.commNavWorkspace = ''; workspaceGroup.append(workspace); nav.append(workspaceGroup);
        const adminGroup = el('section', undefined, 'olama-comm-nav-group'); adminGroup.hidden = true; adminGroup.append(el('small', 'الإعدادات والإدارة'));
        const admin = el('div'); admin.dataset.commNavAdmin = ''; adminGroup.append(admin); nav.append(adminGroup);
        const main = el('main', undefined, 'olama-comm-main'); const content = el('div'); content.dataset.commBody = ''; main.append(content); shell.append(nav, main); root.append(shell);
        addNav(root, nav, 'الرئيسية', () => showHome(root), {id: 'home'});
        addNav(root, nav, 'الإعلانات', () => showNotices(root), {id: 'notices'});
        addNav(root, nav, 'الإشعارات', () => showNotifications(root), {id: 'notifications'});
        try {
            const me = await api('me');
            root._me = me;
            if (me.can_manage) {
                addNav(root, nav, 'مركز النشر', () => showCampaigns(root), {group: 'admin', id: 'campaigns'});
                addNav(root, nav, 'صحة النظام', () => showHealth(root), {group: 'admin', id: 'health'});
            }
            document.dispatchEvent(new CustomEvent('olama-chat-mount', {detail: {root, nav, me}}));
            await showHome(root);
        } catch (error) { if (error.name !== 'AbortError') content.replaceChildren(el('p', error.message)); }
    }
    async function showNotices(root, before = 0) {
        activateNav(root, 'notices'); const view = newView(root), rows = await api('notices?before=' + before); if (root._view !== view) return;
        const content = body(root), heading = pageHeader('الإعلانات الرسمية', 'التعاميم والأخبار التي نشرتها إدارة المدرسة.', 'مساحة العمل'); content.replaceChildren(heading.header);
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
        activateNav(root, 'notices'); const view = newView(root), row = await api('notices/' + Number(id)); if (root._view !== view) return;
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
        activateNav(root, 'notifications'); const view = newView(root), rows = await api('notifications?before=' + before + '&unseen=' + (unseen ? 1 : 0)); if (root._view !== view) return;
        const content = body(root), heading = pageHeader('مركز الإشعارات', 'التنبيهات الجديدة وما يحتاج إلى متابعة.', 'مساحة العمل'); heading.actions.append(button(unseen ? 'عرض الكل' : 'غير المشاهدة', () => showNotifications(root, 0, !unseen))); content.replaceChildren(heading.header);
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
        activateNav(root, 'campaigns'); newView(root); const content = body(root), heading = pageHeader(existing ? 'تعديل المسودة' : 'إعلان رسمي جديد', 'اكتب المحتوى وحدد الجمهور، ثم احفظه للمراجعة قبل النشر.', 'مركز النشر'); heading.actions.append(button('عودة لمركز النشر', () => showCampaigns(root))); content.replaceChildren(heading.header);
        const spec = existing ? JSON.parse(existing.audience_json) : {};
        const form = el('form', undefined, 'olama-comm-form');
        const title = field(form, 'title', 'عنوان الإعلان', existing ? existing.title : ''); title.maxLength = 190; title.required = true;
        const message = field(form, 'body', 'نص الإعلان — يمكن استخدام {recipient_name}', existing ? existing.message_body_draft : '', 'textarea'); message.maxLength = 5000; message.required = true;
        const purposes = {information: 'للعلم', acknowledgement: 'يتطلب إقراراً بالاطلاع'}; if (root._suite?.actions) purposes.action_required = 'يتطلب إجراء';
        choice(form, 'purpose', 'الغرض', purposes, existing ? existing.purpose : 'information');
        const audienceType = choice(form, 'type', 'الجمهور', {general: 'الأسر النشطة / صف أو شعبة', employees: 'جميع الموظفين النشطين', selected: 'مستلمون محددون', collection: 'جمهور المستحقات المالية', transportation: 'جمهور المواصلات', renewal_reminder: 'جمهور تذكير التجديد'}, spec.type || 'general');
        const studyYear = field(form, 'study_year', 'السنة الدراسية (فارغ للسنة الحالية)', spec.study_year || '');
        const classId = field(form, 'class_id', 'الصف (اختياري)', spec.class_id || '');
        const sectionId = field(form, 'section_id', 'الشعبة (اختياري)', spec.section_id || '');
        const familyId = field(form, 'family_id', 'رقم الأسرة (اختياري)', spec.family_id || '');
        const actorKeys = field(form, 'actor_keys', 'هويات المستلمين المحددين — هوية في كل سطر', (spec.actor_keys || []).join('\n'), 'textarea');
        const audienceFields = [classId, sectionId, familyId];
        const syncAudienceFields = () => {
            const general = audienceType.value === 'general', selected = audienceType.value === 'selected';
            audienceFields.forEach(input => { input.disabled = !general; input.parentElement.hidden = !general; });
            actorKeys.disabled = !selected; actorKeys.parentElement.hidden = !selected;
            studyYear.parentElement.hidden = selected; studyYear.disabled = selected;
        };
        audienceType.addEventListener('change', syncAudienceFields); syncAudienceFields();
        if (root._suite?.actions) { field(form, 'action_due_at_utc', 'مهلة الإجراء UTC', existing?.workflow_json ? (JSON.parse(existing.workflow_json).due_at_utc || '').replace(' ', 'T').slice(0,16) : '', 'datetime-local'); }
        const files = root._suite?.attachments ? window.OlamaSuiteClient.picker(form, 'campaign', existing?.id || 0, existing?.attachments || []) : null;
        const submit = el('button', 'حفظ المسودة', 'olama-comm-primary'); submit.type = 'submit'; form.append(submit);
        form.addEventListener('submit', async event => {
            event.preventDefault(); submit.disabled = true;
            try {
                const values = new FormData(form), audience = {type: values.get('type')};
                ['study_year', 'class_id', 'section_id', 'family_id'].forEach(key => { const value = values.get(key); if (value && value.trim()) audience[key] = value.trim(); });
                if (audience.type === 'selected') audience.actor_keys = values.get('actor_keys').split(/[\s,]+/).filter(Boolean);
                const result = await api('campaigns' + (existing ? '/' + existing.id : ''), {title: values.get('title'), body: values.get('body'), purpose: values.get('purpose'), audience, ...(files ? {attachment_ids: files.ids()} : {}), action_due_at_utc: values.get('action_due_at_utc') ? values.get('action_due_at_utc').replace('T', ' ') + ':00' : null}); await showCampaign(root, result.id);
            } catch (error) { toast(error.message); } finally { submit.disabled = false; }
        }); content.append(form);
    }
    async function showCampaigns(root, before = 0) {
        activateNav(root, 'campaigns'); const view = newView(root), rows = await api('campaigns?before=' + before); if (root._view !== view) return;
        const content = body(root), heading = pageHeader('مركز النشر', 'أنشئ الإعلان، حضّر جمهوره، ثم تابع النشر والوصول.', 'الإدارة'); heading.actions.append(button('إعلان جديد', () => editCampaign(root), 'olama-comm-primary')); content.replaceChildren(heading.header);
        rows.forEach(row => { const card = el('article', undefined, 'olama-comm-card'); card.append(el('h3', row.title), el('p', statusNames[row.status] || row.status), button('عرض ومتابعة', () => showCampaign(root, row.id))); content.append(card); });
        if (rows.length === 30) content.append(button('الأقدم', () => showCampaigns(root, rows[rows.length - 1].id)));
    }
    async function showCampaign(root, id) {
        activateNav(root, 'campaigns'); const view = newView(root), result = await api('campaigns/' + id); if (root._view !== view) return;
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
        activateNav(root, 'health'); const view = newView(root), health = await api('health'); if (root._view !== view) return;
        const content = body(root), heading = pageHeader('صحة النظام', 'حالة المعالجة والتكاملات وأخطاء التسليم.', 'الإدارة'); content.replaceChildren(heading.header, el('p', 'آخر تشغيل للمعالج: ' + date(health.heartbeat_utc)), el('p', 'الهوية: حساب واحد موثّق؛ اختيار الهويات المتعددة ينتظر API معتمداً من OLAMA Users.'));
        health.failed_jobs.forEach(job => { const card = el('article', undefined, 'olama-comm-card'); card.append(el('p', '#' + job.id + ' · ' + job.job_type + ' · ' + job.last_error), button('إعادة المحاولة', async () => { await api('jobs/' + job.id + '/retry', {}); await showHealth(root); })); content.append(card); });
        content.append(el('pre', JSON.stringify(health, null, 2), 'olama-comm-diagnostics'), button('تحديث', () => showHealth(root)));
    }
    window.OlamaChatHost = {api, el, button, date, field, choice, body, newView, pageHeader, addNav, activateNav, toast, config, roots, actor: () => actor, scope: contextKey, openPanel, panelRoot: () => dialog && dialog.querySelector('.olama-communications')};
    document.querySelectorAll('[data-olama-communications]').forEach(root => mount(root));
    document.addEventListener('click', event => { const link = event.target.closest('a'); if (link && /action=logout/.test(link.href)) { document.dispatchEvent(new Event('olama-context-cleared')); releaseCoordinator(); safeStore.remove(sessionStorage, actorStorage); } });
    window.addEventListener('pagehide', releaseCoordinator);
    window.addEventListener('pageshow', event => { if (event.persisted) { generation++; roots.forEach(root => mount(root)); startCoordinator(); } });
    startCoordinator();
}());
