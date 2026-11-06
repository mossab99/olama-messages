(function ($) {
    'use strict';
    var $shell = $('.omw-shell');
    if (!$shell.length) { return; }
    var dialog = document.getElementById('omw-command'), trigger = null, navTrigger = null;
    function closeNav() {
        var wasOpen = $('#omw-sidebar').hasClass('is-open');
        $('#omw-sidebar').removeClass('is-open').removeAttr('role aria-modal aria-label');
        $('.omw-app,.omw-bottom-nav').prop('inert', false);
        $('[data-workspace-menu]').attr('aria-expanded', 'false');
        $('[data-workspace-close]').removeClass('is-open').prop('hidden', true);
        if (wasOpen && navTrigger) { navTrigger.focus(); }
    }
    function search() {
        if (!dialog) { return; }
        if ($('#omw-sidebar').hasClass('is-open')) { closeNav(); }
        trigger = document.activeElement;
        $('[data-workspace-query]').val(''); $('[data-workspace-result]').prop('hidden', false); $('[data-workspace-empty]').prop('hidden', true);
        if (!dialog.open) { dialog.showModal(); }
        $('[data-workspace-query]').trigger('focus');
    }
    $(document).on('click', '[data-workspace-menu]', function () {
        var open = !$('#omw-sidebar').hasClass('is-open');
        if (!open) { closeNav(); return; }
        navTrigger = this;
        $('#omw-sidebar').toggleClass('is-open', open); $(this).attr('aria-expanded', String(open)); $('[data-workspace-close]').toggleClass('is-open', open).prop('hidden', !open);
        $('.omw-app,.omw-bottom-nav').prop('inert', true);
        $('#omw-sidebar').attr({'role':'dialog','aria-modal':'true','aria-label':document.documentElement.dir === 'rtl' ? 'التنقل' : 'Navigation'}).find('a').first().trigger('focus');
    }).on('click', '[data-workspace-close]', closeNav).on('click', '[data-workspace-search]', search);
    $(document).on('keydown', function (event) {
        if (event.key === 'Tab' && $('#omw-sidebar').hasClass('is-open')) {
            var links = $('#omw-sidebar').find('a:visible,button:visible').get(), first = links[0], last = links[links.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        }
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') { event.preventDefault(); search(); }
        if (event.key === 'Escape') {
            closeNav();
            if (dialog && dialog.open) { event.preventDefault(); dialog.close(); }
        }
        if (dialog && dialog.open && event.key === 'Enter' && $(event.target).is('[data-workspace-query]')) {
            event.preventDefault(); var first = $('[data-workspace-result]:not([hidden])').get(0); if (first) { first.click(); }
        }
    });
    window.matchMedia('(min-width:783px)').addEventListener('change', function (event) { if (event.matches) { closeNav(); } });
    $('[data-workspace-query]').on('input', function () {
        var value = this.value.toLowerCase().trim(), shown = 0;
        $('[data-workspace-result]').each(function () { var match = $(this).text().toLowerCase().indexOf(value) !== -1; $(this).prop('hidden', !match); if (match) { shown++; } });
        $('[data-workspace-empty]').prop('hidden', shown > 0);
    });
    if (dialog) { dialog.addEventListener('close', function () { if (trigger && trigger.isConnected) { trigger.focus(); } }); }
    // Recipient details are read-only and fetched only when opened. Text nodes
    // preserve untrusted error/name content without interpreting markup.
    var drawer = document.getElementById('omw-recipient-drawer'), drawerTrigger = null, detailRequest = null;
    var arabic = document.documentElement.dir === 'rtl';
    function text(en, ar) { return arabic ? ar : en; }
    function statusText(value) {
        var labels = {sent:text('Submitted to KDE','مقدمة إلى KDE'),reserved:text('In progress','قيد المعالجة'),prepared:text('Prepared','مجهزة'),failed:text('Failed submission','فشل التقديم'),unknown:text('Review needed','تحتاج مراجعة'),retry_wait:text('Retry scheduled','إعادة محاولة مجدولة'),cancelled:text('Cancelled','ملغاة'),submitted:text('Submitted to KDE','مقدمة إلى KDE')};
        return labels[value] || value;
    }
    $(document).on('click', '[data-recipient-detail]', function () {
        if (!drawer) { return; }
        drawerTrigger = this;
        var $body = $('[data-recipient-content]').empty().text(text('Loading details…','جارٍ تحميل التفاصيل…'));
        if (!drawer.open) { drawer.showModal(); }
        if (detailRequest) { detailRequest.abort(); }
        detailRequest = $.post(olamaMsgAdmin.ajaxUrl, {action:'olama_msg_recipient_detail',nonce:olamaMsgAdmin.nonce,queue_id:$(this).attr('data-recipient-detail'),campaign_id:$('#olama-msg-campaign-progress').data('campaign-id')})
            .done(function (response) {
                if (!response.success) { $body.text(response.data && response.data.message || text('Could not load details.','تعذر تحميل التفاصيل.')); return; }
                var row = response.data.recipient, $list = $('<dl>');
                $body.empty().append($('<p class="omw-delivery-note">').text(text('Delivery confirmation unavailable. Times are UTC.','تأكيد التسليم غير متاح. الأوقات بتوقيت UTC.')));
                [[text('Recipient','المستلم'),row.recipient_name || '—'],[text('Phone','الهاتف'),row.phone_e164],[text('Submission status','حالة التقديم'),statusText(row.status)],[text('Attempts','المحاولات'),row.attempt_count],[text('Created','الإنشاء'),row.created_at],[text('Submitted to KDE','التقديم إلى KDE'),row.sent_at || '—'],[text('Last updated','آخر تحديث'),row.updated_at || '—'],[text('Last error','آخر خطأ'),row.last_error_message || '—']].forEach(function (pair) {
                    $list.append($('<div>').append($('<dt>').text(pair[0]),$('<dd dir="auto">').text(pair[1])));
                });
                $body.append($list,$('<h3>').text(text('Latest recorded attempts (up to 20)','آخر المحاولات المسجلة (حتى ٢٠)')));
                if (!response.data.attempts.length) { $body.append($('<p>').text(text('No attempt history recorded.','لا يوجد سجل محاولات متاح.'))); }
                response.data.attempts.forEach(function (attempt) {
                    $body.append($('<p>').append($('<strong>').text(statusText(attempt.status)),$('<br>'),$('<small dir="ltr">').text(attempt.created_at+' → '+attempt.updated_at),$('<br>'),$('<small dir="ltr">').text(attempt.attempt_id)));
                });
            }).fail(function (_xhr, state) { if (state !== 'abort') { $body.text(text('Could not load details. Close and try again.','تعذر تحميل التفاصيل. أغلق النافذة وحاول مجدداً.')); } });
    });
    if (drawer) { drawer.addEventListener('close', function () { if (detailRequest) { detailRequest.abort(); } if (drawerTrigger && drawerTrigger.isConnected) { drawerTrigger.focus(); } }); }
    // Progressive GET filtering keeps the PHP routes as the source of truth.
    // Without JavaScript these forms still submit normally.
    var filterTimer = null, filterRequest = null;
    function labelRecords() {
        $shell.find('table.omsg-table, #olama-msg-campaign-progress table').each(function () {
            var headings = $(this).find('thead th').map(function () { return $(this).text().trim(); }).get();
            $(this).find('tbody tr').each(function () { $(this).children('td').each(function (index) { if (this.colSpan === 1) { $(this).attr('data-label', headings[index] || ''); } }); });
        });
    }
    function filterResults(url, focusName, pushHistory) {
        clearTimeout(filterTimer);
        if (filterRequest) { filterRequest.abort(); }
        var request = new AbortController(); filterRequest = request;
        var main = document.getElementById('omw-main');
        main.setAttribute('aria-busy', 'true');
        var notice = document.createElement('p'); notice.className = 'omw-filter-state'; notice.setAttribute('role','status');
        notice.textContent = text('Updating results…','جارٍ تحديث النتائج…');
        main.querySelectorAll('.omw-filter-state').forEach(function (el) { el.remove(); }); main.prepend(notice);
        var active = document.activeElement, selection = active && active.selectionStart;
        fetch(url, {credentials:'same-origin',signal:request.signal}).then(function (response) {
            if (!response.ok) { throw new Error('filter_failed'); }
            return response.text();
        }).then(function (html) {
            if (request.signal.aborted) { return; }
            var replacement = new DOMParser().parseFromString(html,'text/html').getElementById('omw-main');
            if (!replacement) { throw new Error('filter_failed'); }
            main.innerHTML = replacement.innerHTML;
            if (pushHistory) { history.pushState({},'',url); } else { history.replaceState({},'',url); }
            labelRecords();
            drawer = document.getElementById('omw-recipient-drawer');
            if (drawer) { drawer.addEventListener('close', function () { if (detailRequest) { detailRequest.abort(); } if (drawerTrigger && drawerTrigger.isConnected) { drawerTrigger.focus(); } }); }
            if (focusName) {
                var field = Array.from(main.querySelectorAll('[name]')).find(function (el) { return el.name === focusName; });
                if (field) { field.focus({preventScroll:true}); if (field.type === 'search' && selection !== null) { field.setSelectionRange(selection,selection); } }
            } else { main.focus({preventScroll:true}); }
        }).catch(function (error) {
            if (error.name !== 'AbortError') { notice.textContent = text('Results could not be refreshed. Submit again or reload this page.','تعذر تحديث النتائج. أعد المحاولة أو أعد تحميل الصفحة.'); notice.setAttribute('role','alert'); }
        }).finally(function () { if (filterRequest === request) { main.removeAttribute('aria-busy'); filterRequest = null; } });
    }
    function filterUrl(form) {
        var url = new URL(window.location.href); url.search = new URLSearchParams(new FormData(form)).toString();
        return url.toString();
    }
    $(document).on('submit','form[data-workspace-filter]',function (event) {
        event.preventDefault(); filterResults(filterUrl(this),document.activeElement.name);
    }).on('input','form[data-workspace-filter] input[type="search"]',function () {
        var field = this; clearTimeout(filterTimer);
        if (filterRequest) { filterRequest.abort(); }
        filterTimer = setTimeout(function () { filterResults(filterUrl(field.form),field.name); },450);
    }).on('change','form[data-workspace-filter] select',function () { filterResults(filterUrl(this.form),this.name); })
    .on('click','.omw-pagination a,.olama-msg-pagination a',function (event) {
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) { return; }
        event.preventDefault(); filterResults(this.href,null,true);
    });
    window.addEventListener('popstate', function () { if (document.querySelector('form[data-workspace-filter]')) { filterResults(window.location.href,null,false); } });
    // Mobile records keep column meaning when rendered as stacked cards.
    labelRecords();
    // Tables scroll within the workspace, never across the whole admin page.
    $shell.find('table.widefat, table.olama-msg-table').each(function () {
        if (!$(this).closest('.omsg-table-scroll,.olama-msg-table-wrap,.omw-table-scroll').length) { $(this).wrap('<div class="omw-table-scroll"></div>'); }
    });
    // Draft preview uses text nodes only. It does not render per-family values or
    // call preparation, authorization, recipient or sending endpoints.
    var $message = $shell.find('[name="message_body_draft"]'), $preview = $shell.find('[data-workspace-message-preview]');
    function preview() {
        var value = String($message.val() || '');
        $preview.text(value || text('Your draft message will appear here.','ستظهر مسودة رسالتك هنا.'));
        if (window.OlamaSms) { var info = window.OlamaSms.info(value); $('[data-workspace-message-stats]').text(info.char_count + text(' characters · ',' حرفاً · ') + info.sms_parts + text(' SMS parts · ',' جزء SMS · ') + info.encoding.toUpperCase()); }
        $('[data-workspace-draft-title]').text(String($shell.find('[name="title"]').val() || text('Untitled campaign','حملة دون عنوان')));
    }
    $message.on('input change', preview); $shell.find('[name="title"],[name="template_id"]').on('input change', function () { setTimeout(preview, 0); });
    if ($preview.length) { preview(); }
})(jQuery);
