/**
 * Olama Messages — Admin JavaScript
 *
 * Handles:
 * - SMS Preview modal (trigger, AJAX fetch, display)
 * - Copy link button (clipboard)
 * - Auto-dismiss new token box
 * - Template character and SMS parts counter [NEW]
 * - Campaign live preview AJAX updater [NEW]
 */
/* global olamaMsgAdmin */

(function ($) {
    'use strict';

    // ── SMS Preview modal ──────────────────────────────────────────────────
    var $modal   = $('#olama-msg-sms-modal');
    var $text    = $('#olama-msg-sms-text');
    var $chars   = $('#olama-msg-sms-chars');
    var $parts   = $('#olama-msg-sms-parts');
    var $close   = $('#olama-msg-sms-modal-close');

    function openModal() { $modal.show(); }
    function closeModal() { $modal.hide(); }

    $close.on('click', closeModal);
    $modal.find('.olama-msg-modal__backdrop').on('click', closeModal);
    $(document).on('keydown', function (e) { if (e.key === 'Escape') { closeModal(); } });

    $(document).on('click', '.olama-msg-preview-sms-btn', function () {
        var $btn = $(this);
        var familyId           = $btn.data('family-id')           || '';
        var sponsor            = $btn.data('sponsor')             || '';
        var students           = $btn.data('students')            || '';
        var studyYear          = $btn.data('study-year')          || '';
        var financialAvailable = $btn.attr('data-financial-available') || '0';
        var balance            = $btn.attr('data-balance')          !== undefined ? $btn.attr('data-balance') : '';
        var monthlyDue         = $btn.attr('data-monthly-due')      !== undefined ? $btn.attr('data-monthly-due') : '';

        $modal.find('.olama-msg-modal-alert').remove();
        $text.text('جاري التحميل…');
        $chars.text('');
        $parts.text('');
        openModal();

        var payload = {
            action:               'olama_msg_preview_sms',
            nonce:                olamaMsgAdmin.nonce,
            family_id:            familyId,
            sponsor_name:         sponsor,
            students:             students,
            study_year:           studyYear,
            financial_available:  financialAvailable
        };

        if (financialAvailable === '1') {
            payload.balance = balance;
            payload.monthly_due = monthlyDue;
        }

        $.post(olamaMsgAdmin.ajaxUrl, payload, function (res) {
            if (res.success) {
                var d = res.data;
                $modal.find('.olama-msg-modal-alert').remove();
                $text.text(d.text);
                $chars.text(d.info.char_count + ' حرف');
                $parts.text('≈ ' + d.info.sms_parts + ' رسالة SMS');

                // Show notice when raw token link cannot be recovered
                if (d.no_token_notice) {
                    $text.before(
                        '<div class="olama-msg-modal-alert" style="background:#fef3c7;color:#92400e;padding:.75rem;border-radius:.375rem;margin-bottom:.75rem;font-size:.85em;direction:rtl;line-height:1.4">' +
                        d.no_token_notice_text +
                        '</div>'
                    );
                }

                // Warn if custom template has financial placeholders while financial data is unavailable.
                if (d.financial_template_warning) {
                    $text.append(
                        $('<p style="color:#dc2626;font-size:.8em;margin-top:.5rem;direction:ltr;text-align:left"></p>')
                            .text(d.financial_template_warning)
                    );
                }
            } else {
                $text.text('خطأ في تحميل المعاينة.');
            }
        }).fail(function () {
            $text.text('خطأ في الاتصال بالخادم.');
        });

    });

    // ── Copy link button ──────────────────────────────────────────────────
    $(document).on('click', '[data-clipboard]', function () {
        var targetId = $(this).data('clipboard');
        var $input   = $('#' + targetId);
        if (!$input.length) { return; }

        var text = $input.val();
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function () {
                showCopied();
            });
        } else {
            $input[0].select();
            document.execCommand('copy');
            showCopied();
        }

        function showCopied() {
            var $btn = $('[data-clipboard="' + targetId + '"]');
            var orig = $btn.text();
            $btn.text('✓ تم النسخ!');
            setTimeout(function () { $btn.text(orig); }, 2000);
        }
    });

    // ── Auto-dismiss new token box after 5 minutes ─────────────────────
    var $newBox = $('#olama-msg-new-token-box');
    if ($newBox.length) {
        setTimeout(function () {
            $newBox.fadeOut(600);
        }, 5 * 60 * 1000);
    }

    // ── Template character and SMS parts counter [NEW] ─────────────────
    $(document).on('input', '#olama-template-body', function () {
        var text   = $(this).val();
        var length = text.length;
        var parts  = length === 0 ? 0 : Math.ceil(length / 70);

        $('#olama-template-char-count').find('strong').text(length);
        $('#olama-template-sms-parts').find('strong').text(parts);
    });

    // ── Campaign Live Preview AJAX [NEW] ──────────────────────────────
    var previewTimeout;
    $(document).on('input change', '#olama-campaign-form input, #olama-campaign-form select', function () {
        clearTimeout(previewTimeout);
        previewTimeout = setTimeout(updateCampaignPreview, 400);
    });

    // Run preview immediately if form is loaded on edit page
    $(document).ready(function () {
        if ($('#olama-campaign-form').length) {
            updateCampaignPreview();
        }
    });

    function updateCampaignPreview() {
        var $form = $('#olama-campaign-form');
        if (!$form.length) { return; }
        
        var $container = $('#olama-campaign-preview-results');
        if (!$container.length) { return; }

        var studyYear  = $('#campaign-study-year').val();
        var templateId = $('#campaign-template').val();

        // Avoid running empty requests
        if (!studyYear || !templateId) {
            $('#olama-campaign-preview-tbody').html(
                '<tr><td colspan="6" style="text-align:center;padding:1.5rem;color:#64748b;">' +
                'يرجى تحديد العام الدراسي واختيار نموذج الرسالة لتشغيل المعاينة المباشرة.' +
                '</td></tr>'
            );
            $('#olama-campaign-stat-candidates').text('0');
            $('#olama-campaign-stat-included').text('0');
            $('#olama-campaign-stat-excluded').text('0');
            return;
        }

        $container.css('opacity', 0.5);

        var payload = $form.serializeArray();
        payload.push({ name: 'action', value: 'olama_msg_preview_campaign_ajax' });
        payload.push({ name: 'nonce', value: olamaMsgAdmin.nonce });

        $.post(olamaMsgAdmin.ajaxUrl, payload, function (res) {
            $container.css('opacity', 1);
            if (res.success) {
                var d = res.data;
                
                // Update stats
                $('#olama-campaign-stat-candidates').text(d.total_candidates);
                $('#olama-campaign-stat-included').text(d.total_included);
                $('#olama-campaign-stat-excluded').text(d.total_excluded);

                // Update table
                var $tbody = $('#olama-campaign-preview-tbody');
                $tbody.empty();

                if (d.items && d.items.length > 0) {
                    $.each(d.items, function (idx, item) {
                        var badgeClass = item.included === 1 ? 'olama-msg-badge--active' : 'olama-msg-badge--revoked';
                        var badgeText  = item.included === 1 ? 'مشمول' : 'مستثنى (' + translateReason(item.excluded_reason) + ')';
                        var statusHtml = '<span class="olama-msg-badge ' + badgeClass + '">' + badgeText + '</span>';

                        var previewBtn = item.included === 1
                            ? '<button type="button" class="button button-small olama-msg-preview-sms-btn" ' +
                              'data-sponsor="' + escapeHtml(item.recipient_name) + '" ' +
                              'data-family-id="' + escapeHtml(item.oracle_family_id) + '" ' +
                              'data-students="' + escapeHtml(JSON.parse(item.students_json).join('، ')) + '" ' +
                              'data-financial-available="' + item.financial_available + '" ' +
                              'data-balance="' + (item.balance !== null ? item.balance : '') + '" ' +
                              'data-monthly-due="' + (item.monthly_due !== null ? item.monthly_due : '') + '" ' +
                              'data-study-year="' + escapeHtml(studyYear) + '"' +
                              '>معاينة SMS</button>'
                            : '—';

                        var row = '<tr>' +
                            '<td><code>' + escapeHtml(item.oracle_family_id) + '</code></td>' +
                            '<td>' + escapeHtml(item.recipient_name) + ' <span class="description" style="font-size:0.85em;">(' + translateType(item.recipient_type) + ')</span></td>' +
                            '<td><code>' + escapeHtml(item.phone_e164 || item.phone_raw || '—') + '</code></td>' +
                            '<td>' + (item.balance !== null ? parseFloat(item.balance).toFixed(3) + ' JOD' : 'غير متوفر') + '</td>' +
                            '<td>' + statusHtml + '</td>' +
                            '<td>' + previewBtn + '</td>' +
                            '</tr>';

                        $tbody.append(row);
                    });
                } else {
                    $tbody.append('<tr><td colspan="6" style="text-align:center;padding:1.5rem;">لا توجد نتائج مطابقة للفلاتر.</td></tr>');
                }
            } else {
                $('#olama-campaign-preview-tbody').html(
                    '<tr><td colspan="6" style="text-align:center;padding:1.5rem;color:#dc2626;">' +
                    'خطأ في تحميل المعاينة: ' + escapeHtml(res.data) +
                    '</td></tr>'
                );
            }
        }).fail(function () {
            $container.css('opacity', 1);
            $('#olama-campaign-preview-tbody').html(
                '<tr><td colspan="6" style="text-align:center;padding:1.5rem;color:#dc2626;">' +
                'فشل الاتصال بالخادم لتحميل المعاينة المباشرة.' +
                '</td></tr>'
            );
        });
    }

    function translateReason(reason) {
        var reasons = {
            'financial_unavailable': 'البيانات المالية غير متوفرة',
            'credit_balance': 'رصيد دائن',
            'zero_balance': 'رصيد صفر',
            'below_min_balance': 'أقل من الحد الأدنى',
            'missing_phone': 'الهاتف مفقود',
            'landline_rejected': 'استبعاد أرضي',
            'invalid_mobile': 'هاتف خاطئ',
            'invalid_phone': 'هاتف خاطئ',
            'duplicate_phone': 'رقم مكرر'
        };
        return reasons[reason] || reason || 'غير معروف';
    }

    function translateType(type) {
        var types = {
            'father': 'الأب',
            'mother': 'الأم',
            'sponsor': 'المسؤول'
        };
        return types[type] || type || 'غير معروف';
    }

    function escapeHtml(str) {
        if (!str) { return ''; }
        return str.toString()
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

}(jQuery));
