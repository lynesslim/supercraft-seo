(function ($) {
	'use strict';

	var pollInterval = null;
	var isPolling = false;

	$(document).ready(function () {
		// Run Pre-Flight Health Check on Load
		runPreflightScan();

		// Check Server Queue Status on Load
		checkQueueStatus();

		$('#supercraft-run-preflight-btn').on('click', function (e) {
			e.preventDefault();
			runPreflightScan();
		});

		// Target Mode Radio Toggle
		$('input[name="target_mode"]').on('change', function () {
			var mode = $(this).val();
			if (mode === 'selected') {
				$('#page-picker-container').slideDown();
				updateLaunchButtonText();
			} else {
				$('#page-picker-container').slideUp();
				$('#launch-btn-text').text('Run One-Click Technical SEO (All Pages)');
			}
		});

		// Page Item Checkbox Change
		$(document).on('change', '.page-item-checkbox', function () {
			updateLaunchButtonText();
		});

		// Select All Pages Button
		$('#btn-select-all-pages').on('click', function () {
			$('.page-item-checkbox:visible').prop('checked', true);
			updateLaunchButtonText();
		});

		// Deselect All Pages Button
		$('#btn-deselect-all-pages').on('click', function () {
			$('.page-item-checkbox').prop('checked', false);
			updateLaunchButtonText();
		});

		// Search Filter Input
		$('#page-search-input').on('keyup input', function () {
			var query = $(this).val().toLowerCase().trim();
			$('.page-checkbox-item').each(function () {
				var title = $(this).data('title');
				if (!query || title.indexOf(query) !== -1) {
					$(this).show();
				} else {
					$(this).hide();
				}
			});
		});

		function updateLaunchButtonText() {
			var mode = $('input[name="target_mode"]:checked').val();
			if (mode === 'selected') {
				var count = $('.page-item-checkbox:checked').length;
				if (count === 0) {
					$('#launch-btn-text').text('Select Pages to Run Engine');
				} else if (count === 1) {
					$('#launch-btn-text').text('Run One-Click Technical SEO (1 Page Selected)');
				} else {
					$('#launch-btn-text').text('Run One-Click Technical SEO (' + count + ' Pages Selected)');
				}
			} else {
				$('#launch-btn-text').text('Run One-Click Technical SEO (All Pages)');
			}
		}

		// Run Pre-Flight Diagnostic Function
		function runPreflightScan() {
			var $container = $('#supercraft-preflight-results');
			$container.html('<div class="preflight-loading">⏳ Diagnostic scanning site-wide settings...</div>');

			$.ajax({
				url: supercraftSEO.ajaxUrl,
				type: 'POST',
				data: {
					action: 'supercraft_seo_run_preflight',
					nonce: supercraftSEO.nonce,
				},
				success: function (res) {
					if (res.success && res.data.checks) {
						renderPreflightResults(res.data);
					} else {
						$container.html('<div class="preflight-item warning">⚠️ Failed to load pre-flight diagnostics.</div>');
					}
				},
				error: function () {
					$container.html('<div class="preflight-item warning">⚠️ Pre-flight request error.</div>');
				}
			});
		}

		// Render Pre-Flight Results List
		function renderPreflightResults(data) {
			var $container = $('#supercraft-preflight-results');
			var html = '';

			data.checks.forEach(function (check) {
				var itemClass = check.type === 'critical' ? 'critical' : (check.type === 'warning' ? 'warning' : 'passed');
				var icon = check.type === 'critical' ? '🔴' : (check.type === 'warning' ? '🟡' : '🟢');

				html += '<div class="preflight-item ' + itemClass + '">';
				html += '<div class="preflight-text">';
				html += '<strong>' + icon + ' ' + escapeHtml(check.title) + ':</strong> ' + escapeHtml(check.message);
				html += '</div>';

				if (check.code === 'raw_brand_name' && check.suggested_name) {
					html += '<div class="preflight-action-area">';
					html += '<input type="text" class="input-clean-brand" value="' + escapeHtml(check.suggested_name) + '" placeholder="Clean Brand Name" />';
					html += '<button class="button button-primary button-small btn-fix-site-title">Update Brand Name</button>';
					html += '</div>';
				}

				if (check.code === 'search_indexing_blocked') {
					html += '<div class="preflight-action-area">';
					html += '<button class="button button-primary button-small btn-fix-indexing">Enable Indexing Now</button>';
					html += '</div>';
				}

				html += '</div>';
			});

			$container.html(html);
		}

		// 1-Click Fix Brand Name Handler
		$(document).on('click', '.btn-fix-site-title', function (e) {
			e.preventDefault();
			var $btn = $(this);
			var $input = $btn.siblings('.input-clean-brand');
			var newTitle = $input.val();

			if (!newTitle) return;
			$btn.text('Updating...').prop('disabled', true);

			$.ajax({
				url: supercraftSEO.ajaxUrl,
				type: 'POST',
				data: {
					action: 'supercraft_seo_update_site_title',
					nonce: supercraftSEO.nonce,
					new_title: newTitle,
				},
				success: function (res) {
					if (res.success) {
						$btn.text('✅ Saved!').css({ background: '#10b981' });
						runPreflightScan();
					} else {
						$btn.text('Failed').css({ background: '#ef4444' });
					}
				}
			});
		});

		// 1-Click Fix Search Indexing Handler
		$(document).on('click', '.btn-fix-indexing', function (e) {
			e.preventDefault();
			var $btn = $(this);
			$btn.text('Enabling...').prop('disabled', true);

			$.ajax({
				url: supercraftSEO.ajaxUrl,
				type: 'POST',
				data: {
					action: 'supercraft_seo_update_blog_public',
					nonce: supercraftSEO.nonce,
				},
				success: function (res) {
					if (res.success) {
						$btn.text('✅ Indexing Enabled!').css({ background: '#10b981' });
						runPreflightScan();
					} else {
						$btn.text('Failed').css({ background: '#ef4444' });
					}
				}
			});
		});

		// Start Server Background Queue Trigger
		$('#supercraft-start-oneclick').on('click', function () {
			var $btn = $(this);
			var mode = $('input[name="target_mode"]:checked').val();
			var selectedIds = [];

			if (mode === 'selected') {
				$('.page-item-checkbox:checked').each(function () {
					selectedIds.push($(this).val());
				});

				if (selectedIds.length === 0) {
					alert('Please select at least one page to process.');
					return;
				}
			}

			$btn.prop('disabled', true).addClass('processing');
			$('#supercraft-stop-bg').show();
			$('#supercraft-progress-container').slideDown();
			$('#supercraft-results-card').slideDown();
			$('#supercraft-audit-results').html('');

			var requestData = {
				action: 'supercraft_seo_start_bg_queue',
				nonce: supercraftSEO.nonce,
			};

			if (mode === 'selected' && selectedIds.length > 0) {
				requestData.post_ids = selectedIds;
			}

			$.ajax({
				url: supercraftSEO.ajaxUrl,
				type: 'POST',
				data: requestData,
				success: function (res) {
					if (res.success) {
						startPolling();
					} else {
						$('#supercraft-progress-text').text(res.data.message || 'Failed to start queue.');
						$btn.prop('disabled', false).removeClass('processing');
						$('#supercraft-stop-bg').hide();
					}
				},
				error: function () {
					$('#supercraft-progress-text').text('Error starting server background queue.');
					$btn.prop('disabled', false).removeClass('processing');
					$('#supercraft-stop-bg').hide();
				}
			});
		});

		// Stop Server Background Queue Trigger
		$('#supercraft-stop-bg').on('click', function () {
			var $btn = $(this);
			$btn.text('Stopping...').prop('disabled', true);

			$.ajax({
				url: supercraftSEO.ajaxUrl,
				type: 'POST',
				data: {
					action: 'supercraft_seo_stop_bg_queue',
					nonce: supercraftSEO.nonce,
				},
				success: function (res) {
					stopPolling();
					$('#supercraft-progress-text').text('🛑 Background process stopped by user.');
					$('#supercraft-start-oneclick').prop('disabled', false).removeClass('processing');
					$btn.hide().text('🛑 Stop Background Process').prop('disabled', false);
				}
			});
		});

		// Polling Functions
		function startPolling() {
			if (isPolling) return;
			isPolling = true;
			checkQueueStatus();
			pollInterval = setInterval(checkQueueStatus, 3000);
		}

		function stopPolling() {
			if (pollInterval) {
				clearInterval(pollInterval);
				pollInterval = null;
			}
			isPolling = false;
		}

		function checkQueueStatus() {
			$.ajax({
				url: supercraftSEO.ajaxUrl,
				type: 'POST',
				data: {
					action: 'supercraft_seo_get_queue_status',
					nonce: supercraftSEO.nonce,
				},
				success: function (res) {
					if (res.success && res.data) {
						var state = res.data;

						if (state.status === 'running') {
							$('#supercraft-start-oneclick').prop('disabled', true).addClass('processing');
							$('#supercraft-stop-bg').show();
							$('#supercraft-progress-container').slideDown();
							$('#supercraft-results-card').slideDown();

							var total = state.total || 1;
							var count = state.processed_count || 0;
							var percent = Math.round((count / total) * 100);

							$('#supercraft-progress-text').text('Processing on server (' + count + '/' + total + ' pages)...');
							$('#supercraft-progress-percent').text(percent + '%');
							$('#supercraft-progress-bar-fill').css('width', percent + '%');

							if (state.results && state.results.length > 0) {
								renderAllResults(state.results);
							}

							if (!isPolling) {
								startPolling();
							}
						} else if (state.status === 'completed') {
							stopPolling();
							$('#supercraft-progress-text').text('🎉 All selected pages processed & synced with AIOSEO!');
							$('#supercraft-progress-percent').text('100%');
							$('#supercraft-progress-bar-fill').css('width', '100%');
							$('#supercraft-start-oneclick').prop('disabled', false).removeClass('processing');
							$('#supercraft-stop-bg').hide();
							$('#supercraft-progress-container').slideDown();
							$('#supercraft-results-card').slideDown();

							if (state.results && state.results.length > 0) {
								renderAllResults(state.results);
							}
						} else if (state.status === 'stopped') {
							stopPolling();
							$('#supercraft-progress-text').text('🛑 Background process stopped.');
							$('#supercraft-start-oneclick').prop('disabled', false).removeClass('processing');
							$('#supercraft-stop-bg').hide();
							if (state.results && state.results.length > 0) {
								$('#supercraft-results-card').slideDown();
								renderAllResults(state.results);
							}
						}
					}
				}
			});
		}

		// Render All Results
		function renderAllResults(results) {
			$('#supercraft-audit-results').html('');
			results.forEach(function (data) {
				renderAuditItem(data);
			});
			updateFilterCounts();
		}

		// Render Single Audit Card UI
		function renderAuditItem(data) {
			var audit = data.audit;
			var score = audit.score;
			var scoreClass = score >= 80 ? 'high' : (score >= 50 ? 'medium' : 'low');

			var category = 'fixed';
			if (audit.issues.some(i => i.type === 'critical')) {
				category = 'critical';
			} else if (audit.issues.some(i => i.type === 'warning')) {
				category = 'warning';
			}

			var html = '<div class="audit-item-card" data-category="' + category + '">';
			
			html += '<div class="audit-item-header">';
			html += '<div class="audit-item-title-area">';
			html += '<h4><a href="' + data.permalink + '" target="_blank" style="color:#0f172a;text-decoration:none;">' + escapeHtml(data.title) + '</a></h4>';
			if (data.is_elementor) {
				html += '<span style="font-size:10px;background:#e0e7ff;color:#3730a3;padding:2px 6px;border-radius:4px;font-weight:600;">Elementor</span>';
			}
			html += '</div>';
			html += '<div class="score-badge ' + scoreClass + '">SEO Score: ' + score + '/100</div>';
			html += '</div>';

			if (data.seo_generated && data.seo_data) {
				html += '<div class="seo-meta-preview">';
				html += '<div class="preview-title">⚡ AIOSEO Title: ' + escapeHtml(data.seo_data.meta_title) + '</div>';
				html += '<div class="preview-desc">Meta Description: ' + escapeHtml(data.seo_data.meta_description) + '</div>';
				if (data.seo_data.focus_keyword) {
					html += '<span class="preview-kw">Focus Keyword: ' + escapeHtml(data.seo_data.focus_keyword) + '</span>';
				}
				html += '</div>';
			} else if (data.openai_error) {
				html += '<div class="seo-meta-preview" style="border-left-color:#ef4444;color:#991b1b;background:#fef2f2;">';
				html += '⚠️ AI Meta Generation Skipped: ' + escapeHtml(data.openai_error);
				html += '</div>';
			}

			html += '<ul class="issues-list">';
			
			audit.issues.forEach(function (issue) {
				var itemClass = issue.type === 'critical' ? 'issue-critical' : 'issue-warning';
				var icon = issue.type === 'critical' ? '🔴' : '🟡';
				
				html += '<li class="' + itemClass + '">';
				html += '<span>' + icon + ' <strong>' + escapeHtml(issue.title) + ':</strong> ' + escapeHtml(issue.message) + '</span>';

				if (issue.code === 'missing_image_alts' && data.seo_data && data.seo_data.suggested_image_alts) {
					html += '<button class="btn-fix-alt" data-postid="' + data.post_id + '" data-alts=\'' + JSON.stringify(data.seo_data.suggested_image_alts) + '\'>Fix ALTs via AI</button>';
				}

				html += '</li>';
			});

			if (audit.passed.length > 0) {
				html += '<li class="issue-passed">🟢 <strong>Passed Checks:</strong> ' + audit.passed.join(' | ') + '</li>';
			}

			html += '</ul>';
			html += '</div>';

			$('#supercraft-audit-results').append(html);
		}

		// Save Settings AJAX
		$('#supercraft-seo-settings-form').on('submit', function (e) {
			e.preventDefault();
			var $form = $(this);
			var $btn = $form.find('.supercraft-btn-save');
			var $msg = $form.find('.supercraft-save-msg');

			$btn.prop('disabled', true);
			$msg.text('Saving...').css({ color: '#64748b' }).show();

			$.ajax({
				url: supercraftSEO.ajaxUrl,
				type: 'POST',
				data: {
					action: 'supercraft_seo_save_settings',
					nonce: supercraftSEO.nonce,
					openai_model: $('#openai_model').val(),
					brand_voice: $('#brand_voice').val(),
				},
				success: function (res) {
					$btn.prop('disabled', false);
					if (res.success) {
						$msg.text(res.data.message).css({ color: '#10b981' });
						setTimeout(function () { $msg.fadeOut(); }, 3000);
					} else {
						$msg.text(res.data.message || 'Error saving settings').css({ color: '#ef4444' });
					}
				},
				error: function () {
					$btn.prop('disabled', false);
					$msg.text('Network error').css({ color: '#ef4444' });
				}
			});
		});

		// Fix Image ALTs via AJAX
		$(document).on('click', '.btn-fix-alt', function () {
			var $btn = $(this);
			var alts = $btn.data('alts');
			$btn.text('Updating ALTs...').prop('disabled', true);

			$.ajax({
				url: supercraftSEO.ajaxUrl,
				type: 'POST',
				data: {
					action: 'supercraft_seo_fix_image_alts',
					nonce: supercraftSEO.nonce,
					alts: alts,
				},
				success: function (res) {
					if (res.success) {
						$btn.text('✅ ALTs Applied!').css({ background: '#10b981' });
					} else {
						$btn.text('Failed').css({ background: '#ef4444' });
					}
				}
			});
		});

		// Filter Pills Logic
		$('.results-filter-pills .pill-btn').on('click', function () {
			var $btn = $(this);
			var filter = $btn.data('filter');

			$('.results-filter-pills .pill-btn').removeClass('active');
			$btn.addClass('active');

			if (filter === 'all') {
				$('.audit-item-card').show();
			} else {
				$('.audit-item-card').hide();
				$('.audit-item-card[data-category="' + filter + '"]').show();
			}
		});

		function updateFilterCounts() {
			var total = $('.audit-item-card').length;
			var fixed = $('.audit-item-card[data-category="fixed"]').length;
			var warning = $('.audit-item-card[data-category="warning"]').length;
			var critical = $('.audit-item-card[data-category="critical"]').length;

			$('#count-all').text(total);
			$('#count-fixed').text(fixed);
			$('#count-warning').text(warning);
			$('#count-critical').text(critical);
		}

		function escapeHtml(text) {
			if (!text) return '';
			return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
		}
	});
})(jQuery);
