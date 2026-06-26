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

    // ── Phase 4 Run 4B: Confirm Campaign Sending Modal ──────────────────
    var $confirmModal  = $('#olama-msg-confirm-send-modal');
    var $confirmTitle  = $('#olama-msg-confirm-campaign-title');
    var $confirmRec    = $('#olama-msg-confirm-recipients');
    var $confirmProceed = $('#olama-msg-confirm-proceed');

    function openConfirmModal() { $confirmModal.show(); }
    function closeConfirmModal() { $confirmModal.hide(); }

    $('#olama-msg-confirm-close').on('click', closeConfirmModal);
    $('#olama-msg-confirm-cancel').on('click', closeConfirmModal);
    $('#olama-msg-confirm-backdrop').on('click', closeConfirmModal);
    $(document).on('keydown', function (e) { if (e.key === 'Escape') { closeConfirmModal(); } });

    $(document).on('click', '.olama-msg-start-btn', function () {
        var $btn        = $(this);
        var title       = $btn.data('title')       || '';
        var recipients  = $btn.data('recipients')  || '0';
        var startUrl    = $btn.data('start-url')   || '#';

        $confirmTitle.text(title);
        $confirmRec.text(recipients);
        $confirmProceed.attr('href', startUrl);

        openConfirmModal();
    });

    // ── Phase 4D: Direct Single SMS UI Interactive Logic ────────────────
    var selectedFamilyData = null;

    // A. Search Families
    function performFamilySearch() {
        var query = $('#olama-msg-direct-search-input').val().trim();
        var studyYear = $('#olama-msg-direct-study-year-val').val() || '2026-2027';
        var $resultsBox = $('#olama-msg-direct-search-results');
        var $placeholder = $('#olama-msg-direct-search-placeholder');
        var $btn = $('#olama-msg-direct-search-btn');

        if (!query) {
            return;
        }

        $btn.prop('disabled', true).text('Searching...');
        $resultsBox.html('<div class="olama-msg-muted" style="padding:20px;text-align:center;">جاري البحث (Searching)...</div>').show();
        $placeholder.hide();

        $.post(olamaMsgAdmin.ajaxUrl, {
            action: 'olama_msg_search_families',
            security: olamaMsgAdmin.nonce,
            search: query,
            study_year: studyYear
        }, function (res) {
            $btn.prop('disabled', false).text('Search');
            if (res.success) {
                var items = res.data.items || [];
                $resultsBox.empty();
                
                if (items.length === 0) {
                    $resultsBox.html('<div class="olama-msg-muted" style="padding:20px;text-align:center;">No families found matching your search.</div>');
                    return;
                }

                items.forEach(function (family) {
                    var fatherPhone = family.father_mobile || '';
                    var motherPhone = family.mother_mobile || '';
                    var students = (family.students && family.students.length > 0) ? family.students.join(', ') : 'No students';
                    
                    var itemHtml = $('<div class="olama-msg-direct-item"></div>')
                        .attr('data-family-json', JSON.stringify(family))
                        .append(
                            $('<div class="olama-msg-direct-item__info"></div>')
                                .append($('<div class="olama-msg-direct-item__id"></div>').text('ID: ' + family.family_id))
                                .append($('<div class="olama-msg-direct-item__name"></div>').text(family.sponsor_name))
                                .append($('<div class="olama-msg-direct-item__details"></div>').text('Students: ' + students))
                        )
                        .append($('<button type="button" class="olama-msg-direct-item__btn">Select</button>'));

                    $resultsBox.append(itemHtml);
                });
            } else {
                $resultsBox.html('<div style="color:var(--omsg-danger);padding:20px;text-align:center;">Error loading search results.</div>');
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('Search');
            $resultsBox.html('<div style="color:var(--omsg-danger);padding:20px;text-align:center;">Connection failed.</div>');
        });
    }

    $('#olama-msg-direct-search-btn').on('click', performFamilySearch);
    $('#olama-msg-direct-search-input').on('keypress', function (e) {
        if (e.which === 13) {
            e.preventDefault();
            performFamilySearch();
        }
    });

    // B. Select Family
    $(document).on('click', '.olama-msg-direct-item', function () {
        var $item = $(this);
        $('.olama-msg-direct-item').removeClass('is-selected');
        $item.addClass('is-selected');

        var family = JSON.parse($item.attr('data-family-json'));
        selectedFamilyData = family;

        // Populate hidden fields
        $('#olama-msg-direct-family-id-val').val(family.family_id);

        // Build family summary HTML
        var balText = (family.balance !== null) ? parseFloat(family.balance).toFixed(3) + ' JOD' : 'غير متوفر (Unavailable)';
        var fatherPhone = family.father_mobile ? family.father_mobile : '—';
        var motherPhone = family.mother_mobile ? family.mother_mobile : '—';
        
        var summaryHtml = 
            '<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;">' +
                '<div>' +
                    '<h3 style="margin:0 0 5px 0;font-size:1.15rem;font-weight:600;color:var(--omsg-primary);">' + escapeHtml(family.sponsor_name) + '</h3>' +
                    '<p style="margin:0 0 4px 0;font-size:0.9em;"><strong>Family ID:</strong> ' + family.family_id + ' | <strong>Sponsor:</strong> ' + escapeHtml(family.sponsor_name) + '</p>' +
                    '<p style="margin:0 0 4px 0;font-size:0.9em;"><strong>Students:</strong> ' + escapeHtml(family.students.join(', ')) + '</p>' +
                    '<p style="margin:0;font-size:0.9em;"><strong>Mobiles:</strong> Father: <code>' + escapeHtml(fatherPhone) + '</code> | Mother: <code>' + escapeHtml(motherPhone) + '</code></p>' +
                '</div>' +
                '<div style="text-align:right;background:#fff;border:1px solid var(--omsg-border);padding:8px 15px;border-radius:6px;min-width:120px;">' +
                    '<span style="font-size:0.75rem;color:var(--omsg-muted);text-transform:uppercase;display:block;">Current Balance</span>' +
                    '<strong style="font-size:1.2rem;color:' + (family.balance < 0 ? 'var(--omsg-success)' : 'var(--omsg-danger)') + ';">' + balText + '</strong>' +
                '</div>' +
            '</div>';

        if (family.financial_available === 0 || family.financial_available === '0') {
            summaryHtml += 
                '<div style="margin-top:10px;padding:6px 10px;background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:4px;font-size:0.82em;">' +
                '⚠️ Financial data is not connected. Showing demographic information only.' +
                '</div>';
        }

        $('#olama-msg-direct-family-summary').html(summaryHtml);

        // Parent selectors configuration (Father vs Mother mobile availability)
        var $fatherRadio = $('#olama-msg-direct-label-father input');
        var $motherRadio = $('#olama-msg-direct-label-mother input');
        var $fatherLabel = $('#olama-msg-direct-label-father');
        var $motherLabel = $('#olama-msg-direct-label-mother');

        // Reset radio states
        $fatherRadio.prop('disabled', false);
        $motherRadio.prop('disabled', false);
        $fatherLabel.css('opacity', 1).find('span').text('Father Only (' + fatherPhone + ')');
        $motherLabel.css('opacity', 1).find('span').text('Mother Only (' + motherPhone + ')');

        var selectRole = 'father';

        if (!family.father_mobile) {
            $fatherRadio.prop('disabled', true);
            $fatherLabel.css('opacity', 0.5).find('span').text('Father Only (No Phone)');
            selectRole = 'mother';
        }
        if (!family.mother_mobile) {
            $motherRadio.prop('disabled', true);
            $motherLabel.css('opacity', 0.5).find('span').text('Mother Only (No Phone)');
        }

        if (family.father_mobile) {
            $fatherRadio.prop('checked', true);
        } else if (family.mother_mobile) {
            $motherRadio.prop('checked', true);
        } else {
            $fatherRadio.prop('checked', false);
            $motherRadio.prop('checked', false);
            alert('Warning: This family has no mobile numbers listed in the database.');
        }

        // Show composer, hide placeholder
        $('#olama-msg-direct-composer-card').show();
        $('#olama-msg-direct-composer-placeholder').hide();

        // Reset template select
        $('#olama-msg-direct-template-select').val('');
        $('#olama-msg-direct-body-textarea').val('');
        updateCharCount();
    });

    // C. Template Selection Change
    $('#olama-msg-direct-template-select').on('change', function () {
        var templateId = $(this).val();
        var studyYear = $('#olama-msg-direct-study-year-val').val() || '2026-2027';
        var $textarea = $('#olama-msg-direct-body-textarea');

        if (!selectedFamilyData || !templateId) {
            $textarea.val('');
            updateCharCount();
            return;
        }

        $textarea.val('جاري التوليد (Rendering)...').prop('disabled', true);

        $.post(olamaMsgAdmin.ajaxUrl, {
            action: 'olama_msg_render_direct_template',
            security: olamaMsgAdmin.nonce,
            template_id: templateId,
            family_id: selectedFamilyData.family_id,
            study_year: studyYear
        }, function (res) {
            $textarea.prop('disabled', false);
            if (res.success) {
                $textarea.val(res.data.rendered);
                updateCharCount();
            } else {
                $textarea.val('خطأ في تحميل القالب (Error rendering template).');
            }
        }).fail(function () {
            $textarea.prop('disabled', false).val('فشل الاتصال بالخادم (Connection failed).');
        });
    });

    // D. Character Counter with JIT Placeholder Simulation
    function updateCharCount() {
        var text = $('#olama-msg-direct-body-textarea').val() || '';
        
        // Simulate a real 110-character tokenized URL if {{PAYMENT_LINK}} is found
        var simulationText = text;
        var paymentWarning = false;
        if (text.indexOf('{{PAYMENT_LINK}}') !== -1) {
            var dummyLink = 'http://olama3.local/olama-payment-report/abcdef01.abcdef01b1f37dfa4d01b0de61cec17062225010d953c52947c2db48';
            simulationText = text.replace(/\{\{PAYMENT_LINK\}\}/g, dummyLink);
            paymentWarning = true;
        }

        var charCount = simulationText.length;
        
        // Unicode/Arabic SMS parts calculation: 70 chars per part
        var smsParts = 0;
        if (charCount > 0) {
            smsParts = Math.ceil(charCount / 70);
        }

        $('#olama-msg-direct-char-count').text(charCount);
        $('#olama-msg-direct-part-count').text(smsParts);

        // Highlight if too long (> 3 parts, 210 chars)
        if (smsParts > 3) {
            $('#olama-msg-direct-counters').addClass('olama-msg-counter-alert');
        } else {
            $('#olama-msg-direct-counters').removeClass('olama-msg-counter-alert');
        }

        if (paymentWarning) {
            $('#olama-msg-direct-payment-warning').show();
        } else {
            $('#olama-msg-direct-payment-warning').hide();
        }
    }

    $('#olama-msg-direct-body-textarea').on('input propertychange', updateCharCount);

    // E. Form Submission Confirmation Modal
    $('#olama-msg-direct-send-form').on('submit', function (e) {
        if (!selectedFamilyData) {
            e.preventDefault();
            alert('Please select a family first.');
            return;
        }

        var role = $('input[name="recipient_role"]:checked').val();
        if (!role) {
            e.preventDefault();
            alert('Please select a recipient.');
            return;
        }

        var phoneRaw = (role === 'father') ? selectedFamilyData.father_mobile : selectedFamilyData.mother_mobile;
        var name = (role === 'father') 
            ? (selectedFamilyData.father_name || selectedFamilyData.sponsor_name)
            : (selectedFamilyData.mother_name || selectedFamilyData.sponsor_name);

        if (!phoneRaw) {
            e.preventDefault();
            alert('The selected parent does not have a phone number.');
            return;
        }

        // Mask the phone number for display safety
        var masked = phoneRaw.toString();
        if (masked.length > 6) {
            masked = masked.substring(0, 5) + '***' + masked.substring(masked.length - 3);
        }

        var confirmMsg = 
            '⚠️ WARNING: Send Direct SMS Now\n\n' +
            'This action will create a micro-campaign and immediately queue exactly one SMS for dispatch by the active Windows agent.\n\n' +
            'Recipient: ' + name + ' (' + role.toUpperCase() + ')\n' +
            'Phone Number: ' + masked + '\n\n' +
            'Do you want to proceed and send this SMS now?';

        if (!confirm(confirmMsg)) {
            e.preventDefault();
        }
    });

}(jQuery));

