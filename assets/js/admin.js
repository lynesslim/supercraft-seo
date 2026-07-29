(function ($) {
	'use strict';

	var pollInterval = null;
	var isPolling = false;
	var currentFixH1PostId = 0;
	var currentFixMetaPostId = 0;
	var isTicking = false;

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
				$('#audit-btn-text').text('Run Audit Only (All Pages)');
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
					$('#audit-btn-text').text('Select Pages to Audit');
				} else if (count === 1) {
					$('#launch-btn-text').text('Run One-Click Technical SEO (1 Page Selected)');
					$('#audit-btn-text').text('Run Audit Only (1 Page Selected)');
				} else {
					$('#launch-btn-text').text('Run One-Click Technical SEO (' + count + ' Pages Selected)');
					$('#audit-btn-text').text('Run Audit Only (' + count + ' Pages Selected)');
				}
			} else {
				$('#launch-btn-text').text('Run One-Click Technical SEO (All Pages)');
				$('#audit-btn-text').text('Run Audit Only (All Pages)');
			}
		}

		// H1 Modal Event Handlers
		$(document).on('click', '.btn-fix-h1', function () {
			currentFixH1PostId = parseInt($(this).attr('data-postid'), 10);
			$('#supercraft-h1-modal').fadeIn();
		});

		$('#btn-h1-modal-cancel').on('click', function () {
			$('#supercraft-h1-modal').fadeOut();
		});

		// Fix Single Page H1
		$('#btn-h1-fix-single').on('click', function () {
			var $btn = $(this);
			$btn.text('Fixing...').prop('disabled', true);

			$.ajax({
				url: supercraftSEO.ajaxUrl,
				type: 'POST',
				data: {
					action: 'supercraft_seo_fix_h1',
					nonce: supercraftSEO.nonce,
					scope: 'single',
					post_id: currentFixH1PostId,
				},
				success: function (res) {
					$btn.text('Fix This Page Only').prop('disabled', false);
					$('#supercraft-h1-modal').fadeOut();
					if (res.success) {
						checkQueueStatus();
					} else {
						alert(res.data && res.data.message ? res.data.message : 'H1 promotion complete.');
						checkQueueStatus();
					}
				},
				error: function (xhr, status, err) {
					$btn.text('Fix This Page Only').prop('disabled', false);
					$('#supercraft-h1-modal').fadeOut();
					var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ? xhr.responseJSON.data.message : (err || status);
					alert('Server response: ' + msg);
					checkQueueStatus();
				}
			});
		});

		// Fix All Pages Site-Wide H1 via Server Async Background Queue
		$('#btn-h1-fix-all').on('click', function () {
			$('#supercraft-h1-modal').fadeOut();
			
			var targetIds = [];
			$('.audit-item-card').each(function () {
				if ($(this).find('.btn-fix-h1').length > 0) {
					var id = parseInt($(this).attr('data-postid'), 10);
					if (id > 0) targetIds.push(id);
				}
			});

			if (targetIds.length === 0) {
				$('.audit-item-card').each(function () {
					var id = parseInt($(this).attr('data-postid'), 10);
					if (id > 0) targetIds.push(id);
				});
			}

			if (targetIds.length === 0) {
				alert('No pages found to fix H1 headings.');
				return;
			}

			$('#supercraft-start-oneclick').prop('disabled', true).addClass('processing');
			$('#supercraft-stop-bg').show();
			$('#supercraft-progress-container').slideDown();
			$('#supercraft-progress-text').text('Initializing server background queue for ' + targetIds.length + ' pages...');

			$.ajax({
				url: supercraftSEO.ajaxUrl,
				type: 'POST',
				data: {
					action: 'supercraft_seo_start_bg_queue',
					nonce: supercraftSEO.nonce,
					mode: 'full',
					post_ids: targetIds,
				},
				success: function (res) {
					if (res.success) {
						startPolling();
					} else {
						alert('Failed to start server background queue for H1 fixes.');
					}
				}
			});
		});

		// Meta AI Modal Event Handlers
		$(document).on('click', '.btn-fix-meta-title, .btn-fix-meta-desc', function () {
			currentFixMetaPostId = parseInt($(this).attr('data-postid'), 10);
			$('#supercraft-meta-modal').fadeIn();
		});

		$('#btn-meta-modal-cancel').on('click', function () {
			$('#supercraft-meta-modal').fadeOut();
		});

		// Fix Single Page Meta AI
		$('#btn-meta-fix-single').on('click', function () {
			var $btn = $(this);
			$btn.text('Fixing via AI...').prop('disabled', true);

			$.ajax({
				url: supercraftSEO.ajaxUrl,
				type: 'POST',
				data: {
					action: 'supercraft_seo_fix_meta',
					nonce: supercraftSEO.nonce,
					scope: 'single',
					post_id: currentFixMetaPostId,
				},
				success: function (res) {
					$btn.text('Fix This Page Only').prop('disabled', false);
					$('#supercraft-meta-modal').fadeOut();
					if (res.success) {
						checkQueueStatus();
					} else {
						alert(res.data && res.data.message ? res.data.message : 'Failed to fix Meta AI.');
					}
				},
				error: function (xhr, status, err) {
					$btn.text('Fix This Page Only').prop('disabled', false);
					$('#supercraft-meta-modal').fadeOut();
					alert('Server error occurred while generating AI meta: ' + (err || status));
				}
			});
		});

		// Fix All Pages Site-Wide Meta AI via Server Async Background Queue
		$('#btn-meta-fix-all').on('click', function () {
			$('#supercraft-meta-modal').fadeOut();

			var targetIds = [];
			$('.audit-item-card').each(function () {
				if ($(this).find('.btn-fix-meta-title, .btn-fix-meta-desc').length > 0) {
					var id = parseInt($(this).attr('data-postid'), 10);
					if (id > 0) targetIds.push(id);
				}
			});

			if (targetIds.length === 0) {
				$('.audit-item-card').each(function () {
					var id = parseInt($(this).attr('data-postid'), 10);
					if (id > 0) targetIds.push(id);
				});
			}

			if (targetIds.length === 0) {
				alert('No pages found to re-generate AI metadata.');
				return;
			}

			$('#supercraft-start-oneclick').prop('disabled', true).addClass('processing');
			$('#supercraft-stop-bg').show();
			$('#supercraft-progress-container').slideDown();
			$('#supercraft-progress-text').text('Initializing server background queue for ' + targetIds.length + ' pages...');

			$.ajax({
				url: supercraftSEO.ajaxUrl,
				type: 'POST',
				data: {
					action: 'supercraft_seo_start_bg_queue',
					nonce: supercraftSEO.nonce,
					mode: 'full',
					post_ids: targetIds,
				},
				success: function (res) {
					if (res.success) {
						startPolling();
					} else {
						alert('Failed to start server background queue for Meta AI.');
					}
				}
			});
		});

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

		// Start Full AI Auto-Fix One-Click Process
		$('#supercraft-start-oneclick').on('click', function () {
			launchQueue('full');
		});

		// Start Audit Only Process (No AI Changes)
		$('#supercraft-start-audit-only').on('click', function () {
			launchQueue('audit_only');
		});

		function launchQueue(mode) {
			var $btnOneClick = $('#supercraft-start-oneclick');
			var $btnAudit    = $('#supercraft-start-audit-only');
			var targetMode   = $('input[name="target_mode"]:checked').val();
			var selectedIds  = [];

			if (targetMode === 'selected') {
				$('.page-item-checkbox:checked').each(function () {
					selectedIds.push($(this).val());
				});

				if (selectedIds.length === 0) {
					alert('Please select at least one page to process.');
					return;
				}
			}

			$btnOneClick.prop('disabled', true);
			$btnAudit.prop('disabled', true);
			$('#supercraft-stop-bg').show();
			$('#supercraft-progress-container').slideDown();
			$('#supercraft-results-card').slideDown();

			var requestData = {
				action: 'supercraft_seo_start_bg_queue',
				nonce: supercraftSEO.nonce,
				mode: mode,
			};

			if (targetMode === 'selected' && selectedIds.length > 0) {
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
						$btnOneClick.prop('disabled', false);
						$btnAudit.prop('disabled', false);
						$('#supercraft-stop-bg').hide();
					}
				},
				error: function () {
					$('#supercraft-progress-text').text('Error starting server background queue.');
					$btnOneClick.prop('disabled', false);
					$btnAudit.prop('disabled', false);
					$('#supercraft-stop-bg').hide();
				}
			});
		}

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
					$('#supercraft-start-oneclick').prop('disabled', false);
					$('#supercraft-start-audit-only').prop('disabled', false);
					$btn.hide().text('🛑 Stop Background Process').prop('disabled', false);
				}
			});
		});

		// Polling & Dual-Engine Ticker Functions
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
							$('#supercraft-start-oneclick').prop('disabled', true);
							$('#supercraft-start-audit-only').prop('disabled', true);
							$('#supercraft-stop-bg').show();
							$('#supercraft-progress-container').slideDown();
							$('#supercraft-results-card').slideDown();

							var total = state.total || 1;
							var count = state.processed_count || 0;
							var percent = Math.round((count / total) * 100);
							var modeLabel = state.mode === 'audit_only' ? 'Running audit' : 'Processing AI SEO';

							$('#supercraft-progress-text').text(modeLabel + ' on server (' + count + '/' + total + ' pages)...');
							$('#supercraft-progress-percent').text(percent + '%');
							$('#supercraft-progress-bar-fill').css('width', percent + '%');

							if (state.results && state.results.length > 0) {
								renderAllResults(state.results);
							}

							// Trigger front-end backup ticker to ensure queue NEVER stalls
							if (!isTicking) {
								isTicking = true;
								$.ajax({
									url: supercraftSEO.ajaxUrl,
									type: 'POST',
									data: { action: 'supercraft_seo_bg_tick' },
									complete: function () {
										isTicking = false;
									}
								});
							}

							if (!isPolling) {
								startPolling();
							}
						} else if (state.status === 'completed') {
							stopPolling();
							var completeLabel = state.mode === 'audit_only' ? '🎉 Technical SEO Audit complete!' : '🎉 All selected pages processed & synced with AIOSEO!';
							$('#supercraft-progress-text').text(completeLabel);
							$('#supercraft-progress-percent').text('100%');
							$('#supercraft-progress-bar-fill').css('width', '100%');
							$('#supercraft-start-oneclick').prop('disabled', false);
							$('#supercraft-start-audit-only').prop('disabled', false);
							$('#supercraft-stop-bg').hide();
							$('#supercraft-progress-container').slideDown();
							$('#supercraft-results-card').slideDown();

							if (state.results && state.results.length > 0) {
								renderAllResults(state.results);
							}
						} else if (state.status === 'stopped') {
							stopPolling();
							$('#supercraft-progress-text').text('🛑 Background process stopped.');
							$('#supercraft-start-oneclick').prop('disabled', false);
							$('#supercraft-start-audit-only').prop('disabled', false);
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

			var html = '<div class="audit-item-card" data-postid="' + data.post_id + '" data-category="' + category + '">';
			
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
				var metaTitle = data.seo_data.meta_title || data.seo_data.title || '';
				var metaDesc  = data.seo_data.meta_description || data.seo_data.description || '';
				var focusKw   = data.seo_data.focus_keyword || data.seo_data.focus_keyphrase || '';

				html += '<div class="seo-meta-preview">';
				html += '<div class="preview-title">⚡ AIOSEO Title: ' + escapeHtml(metaTitle) + '</div>';
				html += '<div class="preview-desc">Meta Description: ' + escapeHtml(metaDesc) + '</div>';
				if (focusKw) {
					html += '<span class="preview-kw">Focus Keyword: ' + escapeHtml(focusKw) + '</span>';
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

				if (issue.code === 'missing_h1') {
					html += '<button class="btn-fix-h1" data-postid="' + data.post_id + '">Fix H1 Tag</button>';
				}

				if (issue.code === 'meta_title_too_short' || issue.code === 'meta_title_too_long' || issue.code === 'missing_meta_title') {
					html += '<button class="btn-fix-meta-title" data-postid="' + data.post_id + '">Fix Title via AI</button>';
				}

				if (issue.code === 'meta_desc_too_short' || issue.code === 'meta_desc_too_long' || issue.code === 'missing_meta_desc') {
					html += '<button class="btn-fix-meta-desc" data-postid="' + data.post_id + '">Fix Description via AI</button>';
				}

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

		// Fix Image ALTs via AJAX with live card refresh
		$(document).on('click', '.btn-fix-alt', function () {
			var $btn = $(this);
			var postId = parseInt($btn.attr('data-postid'), 10);
			var alts = $btn.data('alts');
			$btn.text('Updating ALTs...').prop('disabled', true);

			$.ajax({
				url: supercraftSEO.ajaxUrl,
				type: 'POST',
				data: {
					action: 'supercraft_seo_fix_image_alts',
					nonce: supercraftSEO.nonce,
					post_id: postId,
					alts: alts,
				},
				success: function (res) {
					if (res.success) {
						checkQueueStatus();
					} else {
						$btn.text('Failed').css({ background: '#ef4444' });
					}
				},
				error: function () {
					$btn.text('Failed').css({ background: '#ef4444' });
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
