(function ($) {
    'use strict';
    var $shell = $('.omw-shell');
    if (!$shell.length) { return; }
    var dialog = document.getElementById('omw-command'), trigger = null;
    function closeNav() { $('#omw-sidebar').removeClass('is-open'); $('[data-workspace-menu]').attr('aria-expanded', 'false'); $('[data-workspace-close]').removeClass('is-open').prop('hidden', true); }
    function search() {
        if (!dialog) { return; }
        trigger = document.activeElement;
        $('[data-workspace-query]').val(''); $('[data-workspace-result]').prop('hidden', false); $('[data-workspace-empty]').prop('hidden', true);
        if (!dialog.open) { dialog.showModal(); }
        $('[data-workspace-query]').trigger('focus');
    }
    $(document).on('click', '[data-workspace-menu]', function () {
        var open = !$('#omw-sidebar').hasClass('is-open');
        $('#omw-sidebar').toggleClass('is-open', open); $(this).attr('aria-expanded', String(open)); $('[data-workspace-close]').toggleClass('is-open', open).prop('hidden', !open);
    }).on('click', '[data-workspace-close]', closeNav).on('click', '[data-workspace-search]', search);
    $(document).on('keydown', function (event) {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') { event.preventDefault(); search(); }
        if (event.key === 'Escape') {
            closeNav();
            if (dialog && dialog.open) { event.preventDefault(); dialog.close(); }
        }
        if (dialog && dialog.open && event.key === 'Enter' && $(event.target).is('[data-workspace-query]')) {
            event.preventDefault(); var first = $('[data-workspace-result]:not([hidden])').get(0); if (first) { first.click(); }
        }
    });
    $('[data-workspace-query]').on('input', function () {
        var value = this.value.toLowerCase().trim(), shown = 0;
        $('[data-workspace-result]').each(function () { var match = $(this).text().toLowerCase().indexOf(value) !== -1; $(this).prop('hidden', !match); if (match) { shown++; } });
        $('[data-workspace-empty]').prop('hidden', shown > 0);
    });
    if (dialog) { dialog.addEventListener('close', function () { if (trigger && trigger.isConnected) { trigger.focus(); } }); }
    // Tables scroll within the workspace, never across the whole admin page.
    $shell.find('table.widefat, table.olama-msg-table').each(function () {
        if (!$(this).closest('.omsg-table-scroll,.olama-msg-table-wrap,.omw-table-scroll').length) { $(this).wrap('<div class="omw-table-scroll"></div>'); }
    });
    // Draft preview uses text nodes only. It does not render per-family values or
    // call preparation, authorization, recipient or sending endpoints.
    var $message = $shell.find('[name="message_body_draft"]'), $preview = $shell.find('[data-workspace-message-preview]');
    function preview() {
        var value = String($message.val() || '');
        $preview.text(value || 'Your draft message will appear here.');
        if (window.OlamaSms) { var info = window.OlamaSms.info(value); $('[data-workspace-message-stats]').text(info.char_count + ' characters · ' + info.sms_parts + ' SMS parts · ' + info.encoding.toUpperCase()); }
        $('[data-workspace-draft-title]').text(String($shell.find('[name="title"]').val() || 'Untitled campaign'));
    }
    $message.on('input change', preview); $shell.find('[name="title"],[name="template_id"]').on('input change', function () { setTimeout(preview, 0); });
    if ($preview.length) { preview(); }
})(jQuery);
