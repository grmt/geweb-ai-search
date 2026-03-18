jQuery(document).ready(function($) {
		var $settingsForm = $('form[action*="admin-post.php"]').has('input[name="action"][value="geweb_save"]').first();
		var initialFormState = $settingsForm.length ? $settingsForm.serialize() : '';

		function markFormSaved() {
				if (!$settingsForm.length) return;
				initialFormState = $settingsForm.serialize();
		}

		function hasUnsavedChanges() {
				return $settingsForm.length && initialFormState !== $settingsForm.serialize();
		}

		function escapeHtml(text) {
				return String(text)
						.replace(/&/g, '&amp;')
						.replace(/</g, '&lt;')
						.replace(/>/g, '&gt;')
						.replace(/ /g, '&nbsp;');
		}

		function lineNumberHtml(number) {
				return '<span style="display:inline-block; width:3em; margin-right:12px; color:#8c8f94; user-select:none;">' + number + '</span>';
		}

		function getAdminAjaxUrl() {
				return gewebAisearchAdmin && gewebAisearchAdmin.ajaxUrl ? gewebAisearchAdmin.ajaxUrl : (typeof ajaxurl !== 'undefined' ? ajaxurl : '');
		}

		function decodeBase64Value(encodedValue) {
				if (typeof encodedValue !== 'string' || encodedValue === '') {
						return '';
				}

				try {
						return window.atob(encodedValue);
				} catch (error) {
						return '';
				}
		}

		function normalizePromptText(text) {
				return String(text || '')
						.replace(/\r\n?/g, '\n')
						.split('\n')
						.map(function(line) {
								return line.replace(/[ \t]+$/g, '');
						})
						.join('\n');
		}

		function dispatchPromptEvent(element, eventName) {
				if (!element) return;

				try {
						element.dispatchEvent(new Event(eventName, { bubbles: true }));
						return;
				} catch (error) {
				}

				if (document.createEvent) {
						var event = document.createEvent('Event');
						event.initEvent(eventName, true, true);
						element.dispatchEvent(event);
				}
		}

		function setPromptValue(value) {
				var $prompt = $('#geweb_ai_search_custom_prompt');
				var promptElement = $prompt.get(0);
				if (!promptElement) return;

				promptElement.value = value;
				$prompt.val(value);
				dispatchPromptEvent(promptElement, 'input');
				dispatchPromptEvent(promptElement, 'change');
				promptElement.focus();
		}
		function buildPromptDiff(currentPrompt, historyPrompt) {
				var currentLines = normalizePromptText(currentPrompt).split('\n');
				var historyLines = normalizePromptText(historyPrompt).split('\n');
				var maxLines = Math.max(currentLines.length, historyLines.length);
				var html = [];

				for (var i = 0; i < maxLines; i++) {
						var currentLine = currentLines[i];
						var historyLine = historyLines[i];
						var lineNumber = i + 1;

						if (currentLine === historyLine) {
								if (typeof historyLine !== 'undefined') {
										html.push('<div>' + lineNumberHtml(lineNumber) + escapeHtml(historyLine) + '</div>');
								}
								continue;
						}

						if (typeof currentLine !== 'undefined') {
								html.push('<div style="background:#fbeaea; color:#8a1f11;">' + lineNumberHtml(lineNumber) + escapeHtml('- ' + currentLine) + '</div>');
						}

						if (typeof historyLine !== 'undefined') {
								html.push('<div style="background:#edf7ed; color:#166534;">' + lineNumberHtml(lineNumber) + escapeHtml('+ ' + historyLine) + '</div>');
						}
				}

				return html.length ? html.join('') : '<div>No differences from the current AI Prompt.</div>';
		}

		function updatePromptHistoryPreview(selectedPrompt) {
				var $diff = $('#geweb-ai-prompt-history-diff');
				var $currentPrompt = $('#geweb_ai_search_custom_prompt');

				if (!$diff.length || !$currentPrompt.length) return;

				if (!selectedPrompt) {
						$diff.html('Click on a prompt version to preview the full text and diff.');
						return;
				}

				$diff.html(buildPromptDiff($currentPrompt.val(), selectedPrompt));
		}

		function showCellFeedback($cell, message, isError) {
				var $feedback = $cell.find('.geweb-ai-index-feedback');
				if (!$feedback.length) return;

				$feedback.text(message).css('color', isError ? '#d63638' : '#2271b1').show();
		}

		function replaceCellHtml($button, html) {
				var $cell = $button.closest('.geweb-ai-index-cell');
				if (!$cell.length || !html) return;

				$cell.replaceWith(html);
		}

		$('#geweb-ai-restore-default-prompt').on('click', function(event) {
				event.preventDefault();
				var defaultPrompt = $('#geweb_ai_search_default_prompt').val() || decodeBase64Value($(this).attr('data-default-prompt'));
				setPromptValue(defaultPrompt);
		});

		var $promptHistoryList = $('#geweb-ai-prompt-history-list');

		$promptHistoryList.on('click', '.geweb-ai-prompt-history-item', function(e) {
				if ($(e.target).is('input, button, .dashicons')) {
						return;
				}
				var $item = $(this);
				$promptHistoryList.find('.geweb-ai-prompt-history-item').removeClass('selected').css('border-color', '#dcdcde');
				$item.addClass('selected').css('border-color', '#2271b1');
				var selectedPrompt = decodeBase64Value($item.data('prompt'));
				updatePromptHistoryPreview(selectedPrompt);
		});

		$promptHistoryList.on('click', '.geweb-ai-use-history-prompt', function(e) {
				e.preventDefault();
				e.stopPropagation();
				var $item = $(this).closest('.geweb-ai-prompt-history-item');
				var value = decodeBase64Value($item.data('prompt'));
				if (!value) return;

				setPromptValue(value);
				updatePromptHistoryPreview(value);
		});

		$promptHistoryList.on('click', '.geweb-ai-delete-history-prompt', function(e) {
				e.preventDefault();
				e.stopPropagation();
				var $button = $(this);
				var $item = $button.closest('.geweb-ai-prompt-history-item');
				var timestamp = $item.data('timestamp');

				if (!timestamp || !confirm('Are you sure you want to delete this prompt version?')) {
						return;
				}

				$button.prop('disabled', true);

				$.ajax({
						url: getAdminAjaxUrl(),
						type: 'POST',
						dataType: 'json',
						data: {
								action: 'geweb_delete_prompt_history_item',
								nonce: gewebAisearchAdmin.adminActionNonce,
								timestamp: timestamp
						}
				}).done(function(response) {
						if (!response || !response.success) {
								var message = response && response.data && response.data.message ? response.data.message : 'Could not delete prompt version.';
								alert(message);
								$button.prop('disabled', false);
								return;
						}
						$item.remove();
						if ($promptHistoryList.children().length === 0) {
								var $p = $('<p class="description">No previous prompts saved yet.</p>');
								$('#geweb-ai-prompt-history-list').replaceWith($p);
								$('#geweb-ai-clear-history').remove();
								$('#geweb-ai-prompt-history-diff').parent().remove();
						}
				}).fail(function() {
						alert('Could not delete prompt version due to a network error.');
						$button.prop('disabled', false);
				});
		});

		$('#geweb-ai-clear-history').on('click', function() {
				var $button = $(this);
				var $diff = $('#geweb-ai-prompt-history-diff');

				if (!$button.length) return;

				$button.prop('disabled', true).text('Clearing...');

				$.ajax({
						url: getAdminAjaxUrl(),
						type: 'POST',
						dataType: 'json',
						data: {
								action: 'geweb_clear_prompt_history',
								nonce: gewebAisearchAdmin.adminActionNonce
						}
				}).done(function(response) {
						if (!response || !response.success) {
								var message = response && response.data && response.data.message ? response.data.message : 'Could not clear prompt history.';
								if ($diff.length) {
										$diff.text(message);
								} else {
										alert(message);
								}
								$button.prop('disabled', false).text('Clear All History');
								return;
						}

						var $p = $('<p class="description">No previous prompts saved yet.</p>');
						$('#geweb-ai-prompt-history-list').replaceWith($p);
						$('#geweb-ai-clear-history').remove();
						$('#geweb-ai-prompt-history-diff').parent().remove();
				}).fail(function() {
						if ($diff.length) {
								$diff.text('Could not clear prompt history.');
						} else {
								alert('Could not clear prompt history.');
						}
						$button.prop('disabled', false).text('Clear All History');
				});
		});

		$('#geweb_ai_search_custom_prompt').on('input', function() {
				var $selectedItem = $promptHistoryList.find('.geweb-ai-prompt-history-item.selected');
				var selectedPrompt = $selectedItem.length ? decodeBase64Value($selectedItem.data('prompt')) : null;
				updatePromptHistoryPreview(selectedPrompt);
		});
		updatePromptHistoryPreview(null);

		$(window).on('beforeunload', function() {
				if (!hasUnsavedChanges()) return;

				return 'You have unsaved changes. Are you sure you want to leave this page?';
		});

		$('#geweb_ai_search_preserve_data_on_uninstall').on('change', function() {
				if (!this.checked) return;

				alert('Plugin data can be preserved on uninstall, but the stored API key and encryption key will always be removed.');
		});

		if ($settingsForm.length) {
				$settingsForm.on('submit', function() {
						markFormSaved();
				});
		}

		var isProcessing = false;
		var totalSuccess = 0;
		var totalErrors = 0;

		function processPage(page) {
				$.ajax({
						url: ajaxurl,
						type: 'POST',
						timeout: 180000,
						data: {
								action: 'geweb_generate_library',
								nonce: gewebAisearchAdmin.generateLibraryNonce,
								page: page
						},
						success: function(response) {
								if (response.success) {
										var data = response.data;
										totalSuccess += data.success;
										totalErrors += data.errors;

										var percentage = Math.round((data.processed / data.total) * 100);
										var statusText = 'Processing: ' + data.processed + '/' + data.total + ' (' + percentage + '%)';

										$('#geweb-generate-status').html('<p>' + statusText + '</p>');

										if (data.has_more) {
												// Continue processing
												processPage(data.next_page);
										} else {
												// Finished
												var finalMessage = 'Completed! ' + totalSuccess + ' documents uploaded';
												if (totalErrors > 0) {
														finalMessage += ', ' + totalErrors + ' errors (failed items were automatically excluded)';
												}
												$('#geweb-generate-status').html('<p style="color: green;">' + finalMessage + '</p>');
												$('#geweb-generate-library').prop('disabled', false);
												isProcessing = false;
										}
								} else {
										$('#geweb-generate-status').html('<p style="color: red;">Error: ' + response.data.message + '</p>');
										$('#geweb-generate-library').prop('disabled', false);
										isProcessing = false;
								}
						},
						error: function() {
								$('#geweb-generate-status').html('<p style="color: red;">Network error</p>');
								$('#geweb-generate-library').prop('disabled', false);
								isProcessing = false;
						}
				});
		}

		$('#geweb-generate-library').on('click', function() {
				if (isProcessing) return;

				isProcessing = true;
				totalSuccess = 0;
				totalErrors = 0;

				var $btn = $(this);
				var $status = $('#geweb-generate-status');

				$btn.prop('disabled', true);
				$status.html('<p>Starting...</p>');

				processPage(1);
		});

		$(document).on('click', '.geweb-ai-reupload', function() {
				var $button = $(this);
				var $cell = $button.closest('.geweb-ai-index-cell');
				var postId = $cell.data('post-id');
				if (!postId) return;

				$button.prop('disabled', true).text('Uploading...');
				showCellFeedback($cell, 'Uploading in the background...', false);

				$.ajax({
						url: ajaxurl,
						type: 'POST',
						timeout: 180000,
						data: {
								action: 'geweb_reupload_post',
								nonce: gewebAisearchAdmin.adminActionNonce,
								post_id: postId
						},
						success: function(response) {
								if (response.success) {
										replaceCellHtml($button, response.data.html);
										return;
								}

								replaceCellHtml($button, response.data && response.data.html ? response.data.html : '');
								if ($cell.length) {
										showCellFeedback($cell, response.data && response.data.message ? response.data.message : 'Upload failed.', true);
								}
						},
						error: function() {
								$button.prop('disabled', false).text('Upload');
								showCellFeedback($cell, 'Upload failed or timed out.', true);
						}
				});
		});

		$(document).on('change', '.geweb-ai-toggle-exclude', function() {
				var $checkbox = $(this);
				var $cell = $checkbox.closest('.geweb-ai-index-cell');
				var postId = $cell.data('post-id');
				var exclude = $checkbox.is(':checked') ? 1 : 0;
				if (!postId) return;

				$checkbox.prop('disabled', true);
				showCellFeedback($cell, exclude ? 'Excluding in the background...' : 'Including for upload...', false);

				$.ajax({
						url: ajaxurl,
						type: 'POST',
						timeout: 60000,
						data: {
								action: 'geweb_toggle_exclude',
								nonce: gewebAisearchAdmin.adminActionNonce,
								post_id: postId,
								exclude: exclude
						},
						success: function(response) {
								if (response.success) {
										replaceCellHtml($checkbox, response.data.html);
										return;
								}

								replaceCellHtml($checkbox, response.data && response.data.html ? response.data.html : '');
								if ($cell.length) {
										showCellFeedback($cell, response.data && response.data.message ? response.data.message : 'Could not update exclusion.', true);
								}
						},
						error: function() {
								$checkbox.prop('disabled', false).prop('checked', !exclude);
								showCellFeedback($cell, 'Could not update exclusion.', true);
						}
				});
		});
	});
