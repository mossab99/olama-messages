(function ($) {
	'use strict';
	var $wizard = $('.omsg-wizard');
	if (!$wizard.length) return;
	var $form = $('#omsg-campaign-wizard-form');
	var step = Number($wizard.data('step')) || 1;
	var campaignStatus = String($wizard.data('status') || 'draft');
	var previewPage = 1;
	var previewPerPage = 25;
	var previewLoading = false;
	var previewItems = {};
	var previewModalTrigger = null;
	var timer;

	function draw() {
		$wizard.attr('data-step', step);
		$('.omsg-step-panel').hide().filter('[data-step="' + step + '"]').show();
		$('.omsg-steps li').each(function (index) {
			$(this).toggleClass('is-current', index + 1 === step).toggleClass('is-done', index + 1 < step);
		});
		$('[name="ui_step"]').val(step);
		$('[data-wizard-back]').prop('disabled', step === 1);
		$('[data-wizard-next]')
			.toggle(step <= 4 && campaignStatus === 'draft')
			.text(step === 4 ? 'Prepare & continue' : 'Save & continue');
	}

	function meter() {
		var value = $('[name="message_body_draft"]').val() || '';
		var result = window.OlamaSms.info(value);
		$('[data-sms-parts]').text(result.sms_parts + (result.sms_parts === 1 ? ' SMS part' : ' SMS parts'));
		$('[data-sms-detail]').text(result.char_count + ' characters · ' + result.encoding.toUpperCase());
	}

	function escapeHtml(value) {
		return $('<div>').text(value == null ? '' : String(value)).html();
	}

	function paginationHtml(page, totalPages) {
		var visible = {};
		var buttons = '';
		var previous = 0;
		visible[1] = true;
		visible[totalPages] = true;
		for (var number = Math.max(1, page - 2); number <= Math.min(totalPages, page + 2); number++) {
			visible[number] = true;
		}
		Object.keys(visible).map(Number).sort(function (a, b) { return a - b; }).forEach(function (number) {
			if (previous && number - previous > 1) {
				buttons += '<span class="omsg-page-ellipsis">&hellip;</span>';
			}
			buttons += '<button type="button" class="button omsg-page-number' + (number === page ? ' button-primary' : '') +
				'" data-preview-page="' + number + '"' + (number === page ? ' aria-current="page"' : '') + '>' + number + '</button>';
			previous = number;
		});
		return '<div class="omsg-preview-pagination">' +
			'<span>Page ' + page + ' of ' + totalPages + '</span>' +
			'<div class="omsg-preview-page-buttons"><button type="button" class="button" data-preview-prev' + (page <= 1 ? ' disabled' : '') + '>&lsaquo; Previous</button>' +
			buttons +
			'<button type="button" class="button" data-preview-next' + (page >= totalPages ? ' disabled' : '') + '>Next &rsaquo;</button></div>' +
			'<label>Rows <select data-preview-per-page><option value="25"' + (previewPerPage === 25 ? ' selected' : '') + '>25</option><option value="50"' + (previewPerPage === 50 ? ' selected' : '') + '>50</option><option value="100"' + (previewPerPage === 100 ? ' selected' : '') + '>100</option></select></label></div>';
	}

	function renderPreview(data) {
		var reasons = data.reason_counts || {};
		var campaign = data.campaign || {};
		var editable = campaignStatus === 'draft';
		var audienceLabels = {
			collection: 'Outstanding balances (Legacy)',
			general: 'Active families (Legacy)',
			transportation: 'Active transportation families (Legacy)',
			finance_outstanding: 'Finance — Outstanding Balances',
			finance_renewal_reminder: 'Finance — Renewal Reminder',
			renewal_reminder: 'Finance — Renewal Reminder',
			academic: 'Academic — School / Grade / Section',
			transport_no_gps: 'Transportation — Families without GPS',
			transport_registered: 'Transportation — Registered Transport Families',
			store_missing: 'Store — Families Pending Books / Uniforms'
		};
		var policyLabels = {
			father_first: 'Father first, mother fallback',
			mother_first: 'Mother first, father fallback',
			both: 'Both parents',
			both_parents: 'Both parents',
			father_only: 'Father only',
			mother_only: 'Mother only'
		};
		var reasonLabels = {
			financial_unavailable: 'Financial data unavailable',
			credit_balance: 'Credit balance',
			zero_balance: 'Zero balance',
			below_min_balance: 'Below minimum balance',
			missing_phone: 'Missing mobile',
			invalid_phone: 'Invalid mobile',
			international_phone: 'International number',
			landline_rejected: 'Landline',
			duplicate_phone: 'Duplicate mobile',
			manually_excluded: 'Removed manually'
		};
		var reasonHtml = Object.keys(reasons).map(function (key) {
			return '<span class="omsg-preview-reason">' + escapeHtml(reasonLabels[key] || key.replace(/_/g, ' ')) + ': <strong>' + Number(reasons[key]) + '</strong></span>';
		}).join('');
		previewItems = {};
		var rows = (data.items || []).map(function (item) {
			var overrideKey = String(item.oracle_family_id) + ':' + String(item.recipient_type);
			previewItems[overrideKey] = item;
			var manuallyExcluded = item.excluded_reason === 'manually_excluded';
			var selectable = editable && (Number(item.included) === 1 || manuallyExcluded);
			var checkbox = '<input type="checkbox" class="omsg-recipient-select" data-override-key="' + escapeHtml(overrideKey) + '"' +
				(Number(item.included) === 1 ? ' checked' : '') + (selectable ? '' : ' disabled') +
				' aria-label="' + escapeHtml((Number(item.included) === 1 ? 'Unselect ' : 'Select ') + (item.recipient_name || 'recipient')) + '">';
			var state = Number(item.included) === 1
				? '<span class="omsg-status omsg-status--completed">Included</span>'
				: '<span class="omsg-status omsg-status--failed">Excluded</span>';
			var reason = item.excluded_reason ? (reasonLabels[item.excluded_reason] || item.excluded_reason.replace(/_/g, ' ')) : '';
			var previewButton = '<button type="button" class="button button-small" data-open-sms-preview="' + escapeHtml(overrideKey) + '">Preview SMS</button>';
			return '<tr><th class="check-column">' + checkbox + '</th><td><strong>' + escapeHtml(item.recipient_name || 'Unnamed parent') + '</strong><small>Family ' + escapeHtml(item.oracle_family_id) + '</small></td>' +
				'<td>' + escapeHtml(item.recipient_type || '') + '</td><td dir="ltr">' + escapeHtml(item.phone_raw || item.phone_e164 || '—') + '</td>' +
				'<td>' + state + (reason ? '<small>' + escapeHtml(reason) + '</small>' : '') + '</td>' +
				'<td>' + Number(item.sms_parts || 0) + '</td><td>' + previewButton + '</td></tr>';
		}).join('');
		var health = data.sync_health || {};
		var healthText = health.ready === false ? '<div class="notice notice-warning inline"><p>' + escapeHtml(health.message || 'Olama Core synchronization needs attention.') + '</p></div>' : '';
		var lockedText = editable ? '' : '<div class="notice notice-info inline"><p>This campaign is prepared and locked. Recipient choices are read-only.</p></div>';
		var selectionTools = editable
			? '<div class="omsg-preview-selection"><strong>' + Number(data.total_included || 0) + ' messages selected for sending</strong><div class="omsg-preview-selection-actions"><button type="button" class="button" data-select-preview-page>Select page</button><button type="button" class="button" data-clear-preview-page>Unselect page</button><button type="button" class="button button-primary" data-select-all-recipients>Select all families</button><button type="button" class="button" data-clear-all-recipients>Unselect all families</button></div></div>'
			: '';
		var page = Number(data.page || 1);
		var totalPages = Math.max(1, Number(data.total_pages || 1));
		return healthText +
			lockedText +
			'<div class="omsg-preview-context"><span><strong>Source</strong> Olama Core tables</span><span><strong>Study year</strong> ' + escapeHtml(campaign.study_year || health.study_year || '—') + '</span><span><strong>Audience</strong> ' + escapeHtml(audienceLabels[campaign.target_type] || campaign.target_type || '—') + '</span><span><strong>Recipient policy</strong> ' + escapeHtml(policyLabels[campaign.recipient_policy] || campaign.recipient_policy || '—') + '</span></div>' +
			'<div class="omsg-preview-totals"><strong class="is-positive">' + Number(data.total_families || 0) + ' active families</strong><strong class="is-positive">' + Number(data.total_included || 0) + ' messages to send</strong><strong class="is-cost">' + Number(data.total_sms_parts || 0) + ' total SMS parts</strong><strong class="is-negative">' + Number(data.total_excluded || 0) + ' excluded</strong><span>' + Number(data.total_candidates || 0) + ' parent message targets</span></div>' +
			(reasonHtml ? '<div class="omsg-preview-reasons">' + reasonHtml + '</div>' : '') +
			selectionTools +
			'<div class="omsg-table-scroll"><table class="widefat striped omsg-preview-table"><thead><tr><td class="check-column"></td><th>Recipient</th><th>Parent</th><th>Mobile</th><th>Eligibility</th><th>SMS parts</th><th>Message</th></tr></thead><tbody>' +
			(rows || '<tr><td colspan="7">No recipients match this audience. Review the study year and audience filters.</td></tr>') +
			'</tbody></table></div>' + paginationHtml(page, totalPages);
	}

	function closeSmsPreview() {
		$('[data-sms-preview-modal]').prop('hidden', true);
		$('body').removeClass('omsg-modal-open');
		if (previewModalTrigger) {
			previewModalTrigger.focus();
			previewModalTrigger = null;
		}
	}

	function openSmsPreview(key, trigger) {
		var item = previewItems[key];
		if (!item) return;
		var $modal = $('[data-sms-preview-modal]');
		previewModalTrigger = trigger;
		$modal.find('[data-sms-preview-recipient]').text((item.recipient_name || 'Unnamed parent') + ' · Family ' + (item.oracle_family_id || '—') + ' · ' + (item.recipient_type || ''));
		$modal.find('[data-sms-preview-phone]').text('Mobile: ' + (item.phone_raw || item.phone_e164 || '—'));
		$modal.find('[data-sms-preview-counts]').text(Number(item.char_count || 0) + ' characters · ' + Number(item.sms_parts || 0) + ' SMS parts');
		$modal.find('[data-sms-preview-body]').text(item.message_body_preview || 'No rendered message is available for this recipient.');
		$modal.prop('hidden', false);
		$('body').addClass('omsg-modal-open');
		$modal.find('[data-close-sms-preview]').last().trigger('focus');
	}

	function save(done) {
		if (campaignStatus !== 'draft') {
			if (done) done();
			return;
		}
		$('.omsg-save-state').text('Saving…');
		var data = $form.serializeArray();
		data.push({name: 'action', value: 'olama_msg_save_campaign_draft'});
		data.push({name: 'security', value: olamaMsgAdmin.nonce});
		$.post(olamaMsgAdmin.ajaxUrl, data).done(function (response) {
			if (!response.success) {
				$('.omsg-save-state').text(response.data.message || 'Draft could not be saved');
				return;
			}
			$('[name="campaign_id"]').val(response.data.campaign_id);
			$wizard.attr('data-campaign-id', response.data.campaign_id);
			history.replaceState({}, '', response.data.url + '&step=' + step);
			$('.omsg-save-state').text('Saved at ' + response.data.saved_at);
			if (done) done(response.data);
		}).fail(function () { $('.omsg-save-state').text('Draft could not be saved'); });
	}

	function prepareCampaign() {
		var id = Number($('[name="campaign_id"]').val());
		if (!id) {
			save(prepareCampaign);
			return;
		}
		var $button = $('[data-wizard-next]');
		$button.prop('disabled', true).text('Preparing…');
		$('.omsg-save-state').text('Preparing recipient messages…');
		$.post(olamaMsgAdmin.ajaxUrl, {
			action: 'olama_msg_prepare_campaign_ajax',
			security: olamaMsgAdmin.nonce,
			campaign_id: id
		}).done(function (response) {
			if (response.success) {
				window.location.href = response.data.url;
				return;
			}
			$('.omsg-save-state').text((response.data || {}).message || 'Campaign could not be prepared');
			$button.prop('disabled', false).text('Prepare & continue');
		}).fail(function () {
			$('.omsg-save-state').text('Campaign could not be prepared');
			$button.prop('disabled', false).text('Prepare & continue');
		});
	}

	// ─── Audience Category Tabs & Dynamic Filters ──────────────────────────────
	$(document).on('click', '.omsg-cat-tab', function () {
		var cat = $(this).data('cat');
		$('.omsg-cat-tab').removeClass('is-active').filter('[data-cat="' + cat + '"]').addClass('is-active');
		$('.omsg-cat-panel').removeClass('is-active').filter('[data-cat-panel="' + cat + '"]').addClass('is-active');

		// Select default target_type for this category if current input radio is not checked
		var $panel = $('.omsg-cat-panel[data-cat-panel="' + cat + '"]');
		var $radios = $panel.find('input[name="target_type"]');
		var savedTarget = $panel.data('selected-target');

		if (savedTarget && $radios.filter('[value="' + savedTarget + '"]').length) {
			$radios.filter('[value="' + savedTarget + '"]').prop('checked', true).trigger('change');
		} else if ($radios.length && !$radios.filter(':checked').length) {
			$radios.first().prop('checked', true).trigger('change');
		} else if ($radios.filter(':checked').length) {
			$radios.filter(':checked').trigger('change');
		}

		// Trigger lazy option loading for the category
		if (cat === 'academic') {
			loadAcademicSchools();
		} else if (cat === 'transportation') {
			loadTransportOptions();
		}
	});

	// Finance and Transportation subtarget radio handler
	$form.on('change', 'input[name="target_type"]', function () {
		var val = $(this).val();
		$(this).closest('.omsg-cat-panel').data('selected-target', val);
		if (val === 'transport_registered') {
			$('.omsg-transport-filters').removeClass('is-hidden');
			loadTransportOptions();
		} else if (val === 'transport_no_gps') {
			$('.omsg-transport-filters').addClass('is-hidden');
		} else if (val === 'finance_outstanding') {
			$('.omsg-finance-outstanding-filters').removeClass('is-hidden');
		} else if (val === 'finance_renewal_reminder' || val === 'renewal_reminder') {
			$('.omsg-finance-outstanding-filters').addClass('is-hidden');
		}
	});

	function getStudyYear() {
		return $('[name="study_year"]').val() || '';
	}

	function loadAcademicSchools() {
		var $school = $('#omsg-filter-school');
		if (!$school.length || $school.data('loaded')) return;
		$.get(olamaMsgAdmin.ajaxUrl, {
			action: 'olama_msg_audience_school_options',
			security: olamaMsgAdmin.nonce,
			study_year: getStudyYear()
		}).done(function (r) {
			if (r.success && r.data.schools) {
				var current = $school.val();
				var html = '<option value="">All Schools / Kindergarten</option>';
				r.data.schools.forEach(function (s) {
					html += '<option value="' + escapeHtml(s.id) + '"' + (String(s.id) === String(current) ? ' selected' : '') + '>' + escapeHtml(s.name) + '</option>';
				});
				$school.html(html).data('loaded', true);
				loadAcademicGrades();
			}
		});
	}

	function loadAcademicGrades() {
		var $grade = $('#omsg-filter-grade');
		var $section = $('#omsg-filter-section');
		if (!$grade.length) return;
		$.get(olamaMsgAdmin.ajaxUrl, {
			action: 'olama_msg_audience_section_options',
			security: olamaMsgAdmin.nonce,
			study_year: getStudyYear(),
			school_id: $('#omsg-filter-school').val() || ''
		}).done(function (r) {
			if (r.success) {
				var curGrade = $grade.val();
				var gradeHtml = '<option value="">All Grades</option>';
				(r.data.grades || []).forEach(function (g) {
					gradeHtml += '<option value="' + escapeHtml(g.id) + '"' + (String(g.id) === String(curGrade) ? ' selected' : '') + '>' + escapeHtml(g.name) + '</option>';
				});
				$grade.html(gradeHtml);

				var curSec = $section.val();
				var secHtml = '<option value="">All Sections</option>';
				(r.data.sections || []).forEach(function (sec) {
					secHtml += '<option value="' + escapeHtml(sec.id) + '"' + (String(sec.id) === String(curSec) ? ' selected' : '') + '>' + escapeHtml(sec.name) + '</option>';
				});
				$section.html(secHtml);
			}
		});
	}

	$form.on('change', '#omsg-filter-school', function () {
		loadAcademicGrades();
	});

	$form.on('change', '#omsg-filter-grade', function () {
		var $section = $('#omsg-filter-section');
		$.get(olamaMsgAdmin.ajaxUrl, {
			action: 'olama_msg_audience_section_options',
			security: olamaMsgAdmin.nonce,
			study_year: getStudyYear(),
			school_id: $('#omsg-filter-school').val() || '',
			class_id: $(this).val() || ''
		}).done(function (r) {
			if (r.success) {
				var curSec = $section.val();
				var secHtml = '<option value="">All Sections</option>';
				(r.data.sections || []).forEach(function (sec) {
					secHtml += '<option value="' + escapeHtml(sec.id) + '"' + (String(sec.id) === String(curSec) ? ' selected' : '') + '>' + escapeHtml(sec.name) + '</option>';
				});
				$section.html(secHtml);
			}
		});
	} );

	function loadTransportOptions() {
		var $area = $('#omsg-filter-area');
		var $dep = $('#omsg-filter-dep-bus');
		var $arr = $('#omsg-filter-arr-bus');
		if (!$area.length || $area.data('loaded')) return;
		$.get(olamaMsgAdmin.ajaxUrl, {
			action: 'olama_audience_transport_options' in olamaMsgAdmin ? olamaMsgAdmin.action : 'olama_msg_audience_transport_options',
			security: olamaMsgAdmin.nonce,
			study_year: getStudyYear()
		}).done(function (r) {
			if (r.success) {
				var curArea = $area.val();
				var areaHtml = '<option value="">All Areas</option>';
				(r.data.areas || []).forEach(function (a) {
					areaHtml += '<option value="' + escapeHtml(a.id) + '"' + (String(a.id) === String(curArea) ? ' selected' : '') + '>' + escapeHtml(a.name) + '</option>';
				});
				$area.html(areaHtml).data('loaded', true);

				var curDep = $dep.val();
				var depHtml = '<option value="">All Departure Buses</option>';
				(r.data.departure_buses || []).forEach(function (b) {
					var bval = b.bus_name || b.bus_id;
					depHtml += '<option value="' + escapeHtml(bval) + '"' + (String(bval) === String(curDep) ? ' selected' : '') + '>' + escapeHtml(b.bus_name || b.bus_id) + '</option>';
				});
				$dep.html(depHtml);

				var curArr = $arr.val();
				var arrHtml = '<option value="">All Arrival Buses</option>';
				(r.data.arrival_buses || []).forEach(function (b) {
					var bval = b.bus_name || b.bus_id;
					arrHtml += '<option value="' + escapeHtml(bval) + '"' + (String(bval) === String(curArr) ? ' selected' : '') + '>' + escapeHtml(b.bus_name || b.bus_id) + '</option>';
				});
				$arr.html(arrHtml);
			}
		});
	}

	// Auto-load options if starting on step 2
	if (step === 2) {
		var currentActiveCat = $('.omsg-cat-tab.is-active').data('cat');
		if (currentActiveCat === 'academic') loadAcademicSchools();
		if (currentActiveCat === 'transportation') loadTransportOptions();
	}

	$form.on('input change', 'input,select,textarea', function () {
		if ($(this).is('.omsg-recipient-select,[data-preview-per-page]') || campaignStatus !== 'draft') {
			return;
		}
		clearTimeout(timer);
		timer = setTimeout(function () { save(); }, 650);
		$('[data-preview-result]').html('<p class="omsg-preview-stale">Campaign settings changed. The audience preview will be recalculated in step 4.</p>');
		meter();
	});

	$('[name="template_id"]').on('change', function () {
		var body = $(this).find(':selected').data('body');
		if (body) $('[name="message_body_draft"]').val(body).trigger('input');
	});
	$('[data-wizard-next]').on('click', function () {
		if (step === 4) {
			prepareCampaign();
			return;
		}
		var valid = true;
		$('.omsg-step-panel[data-step="' + step + '"]').find('[required]').each(function () {
			if (!this.checkValidity()) {
				this.reportValidity();
				valid = false;
				return false;
			}
		});
		if (!valid) return;
		step = Math.min(5, step + 1);
		save(function () {
			draw();
			if (step === 4) {
				$('[data-preview-campaign]').trigger('click');
			}
		});
	});
	function updateStepUrl() {
		var url = new URL(window.location.href);
		url.searchParams.set('step', step);
		history.replaceState({}, '', url.toString());
	}

	function loadPreview() {
		if (previewLoading) return;
		var id = Number($('[name="campaign_id"]').val());
		if (!id) return save(loadPreview);
		previewLoading = true;
		$('[data-preview-result]').text('Loading preview…');
		$.post(olamaMsgAdmin.ajaxUrl, {
			action: 'olama_msg_preview_campaign_ajax',
			nonce: olamaMsgAdmin.nonce,
			campaign_id: id,
			show_excluded: '1',
			preview_page: previewPage,
			preview_per_page: previewPerPage
		}).done(function (r) {
			var d = r.data || {};
			if (r.success) {
				previewPage = Number(d.page || previewPage);
				$('[data-preview-result]').html(renderPreview(d));
			} else {
				$('[data-preview-result]').text(typeof d === 'string' ? d : (d.message || 'Preview failed'));
			}
		}).fail(function () {
			$('[data-preview-result]').text('The recipient preview could not be loaded.');
		}).always(function () {
			previewLoading = false;
		});
	}

	function saveRecipientChanges(changes) {
		var id = Number($('[name="campaign_id"]').val());
		if (!id || campaignStatus !== 'draft' || !Object.keys(changes).length) return;
		$('.omsg-preview-selection').addClass('is-saving').find('strong').text('Saving recipient choices…');
		$('[data-wizard-next]').prop('disabled', true);
		$.post(olamaMsgAdmin.ajaxUrl, {
			action: 'olama_msg_save_recipient_override',
			security: olamaMsgAdmin.nonce,
			campaign_id: id,
			overrides: JSON.stringify(changes)
		}).done(function (response) {
			if (!response.success) {
				$('.omsg-save-state').text((response.data || {}).message || 'Recipient choices could not be saved');
			} else {
				$('.omsg-save-state').text('Recipient choices saved');
			}
		}).fail(function () {
			$('.omsg-save-state').text('Recipient choices could not be saved');
		}).always(function () {
			$('[data-wizard-next]').prop('disabled', false);
			loadPreview();
		});
	}

	function saveAllRecipientChoices(selected) {
		var id = Number($('[name="campaign_id"]').val());
		if (!id || campaignStatus !== 'draft') return;
		$('.omsg-preview-selection').addClass('is-saving').find('strong').text('Updating all pages…');
		$('[data-wizard-next]').prop('disabled', true);
		$.post(olamaMsgAdmin.ajaxUrl, {
			action: 'olama_msg_save_recipient_override',
			security: olamaMsgAdmin.nonce,
			campaign_id: id,
			selection_scope: 'all',
			selected: selected ? '1' : '0'
		}).done(function (response) {
			if (!response.success) {
				$('.omsg-save-state').text((response.data || {}).message || 'Recipient choices could not be saved');
			} else {
				$('.omsg-save-state').text((selected ? 'Selected' : 'Unselected') + ' recipients across all pages');
			}
		}).fail(function () {
			$('.omsg-save-state').text('Recipient choices could not be saved');
		}).always(function () {
			$('[data-wizard-next]').prop('disabled', false);
			loadPreview();
		});
	}

	$('[data-wizard-back]').on('click', function () {
		step = Math.max(1, step - 1);
		if (campaignStatus === 'draft') {
			// draw() FIRST so the target step panel is visible when save() serializes the form.
			// Without this, navigating back from step 4 would serialize with step 3's textarea
			// hidden (jQuery .hide()), causing message_body_draft to save as empty string.
			draw();
			save(function () {
				if (step === 4) loadPreview();
			});
		} else {
			draw();
			updateStepUrl();
			if (step === 4) loadPreview();
		}
	});
	$('[data-preview-campaign]').on('click', function () {
		previewPage = 1;
		loadPreview();
	});
	$form.on('change', '.omsg-recipient-select', function () {
		var changes = {};
		changes[String($(this).data('override-key'))] = {excluded: !this.checked};
		saveRecipientChanges(changes);
	});
	$form.on('click', '[data-select-preview-page],[data-clear-preview-page]', function () {
		var selected = $(this).is('[data-select-preview-page]');
		var changes = {};
		$('.omsg-recipient-select:not(:disabled)').each(function () {
			changes[String($(this).data('override-key'))] = {excluded: !selected};
		});
		saveRecipientChanges(changes);
	});
	$form.on('click', '[data-select-all-recipients]', function () {
		saveAllRecipientChoices(true);
	});
	$form.on('click', '[data-clear-all-recipients]', function () {
		if (window.confirm('Unselect every eligible family across all pages?')) {
			saveAllRecipientChoices(false);
		}
	});
	$form.on('click', '[data-preview-prev]', function () {
		if (previewPage > 1) {
			previewPage--;
			loadPreview();
		}
	});
	$form.on('click', '[data-preview-next]', function () {
		previewPage++;
		loadPreview();
	});
	$form.on('click', '[data-preview-page]', function () {
		previewPage = Number($(this).data('preview-page')) || 1;
		loadPreview();
	});
	$form.on('change', '[data-preview-per-page]', function () {
		previewPerPage = Number($(this).val()) || 25;
		previewPage = 1;
		loadPreview();
	});
	$form.on('click', '[data-open-sms-preview]', function () {
		openSmsPreview(String($(this).data('open-sms-preview')), this);
	});
	$('[data-close-sms-preview]').on('click', closeSmsPreview);
	$(document).on('keydown', function (event) {
		if (event.key === 'Escape' && !$('[data-sms-preview-modal]').prop('hidden')) {
			closeSmsPreview();
		}
	});
	$('.omsg-reset-form').on('submit', function () {
		return window.confirm('Unlock this campaign for editing? The prepared recipient snapshot and unsent queue records will be removed.');
	});
	draw();
	meter();
	if (campaignStatus !== 'draft') {
		$('.omsg-save-state').text('Prepared campaign is locked');
		$form.find('input:not([type="hidden"]),select,textarea').prop('disabled', true);
	}
	if (step === 4) {
		loadPreview();
	}
}(jQuery));
