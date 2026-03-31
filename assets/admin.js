function escapeHtml(text) {
		return String(text)
				.replaceAll('&', '&amp;')
				.replaceAll('<', '&lt;')
				.replaceAll('>', '&gt;')
				.replaceAll(' ', '&nbsp;');
}

function lineNumberHtml(number) {
		return '<span style="display:inline-block; width:3em; margin-right:12px; color:#8c8f94; user-select:none;">' + number + '</span>';
}

function getAdminAjaxUrl() {
		return globalThis.gewebAisearchAdmin?.ajaxUrl || globalThis.ajaxurl || '';
}

function decodeBase64Value(encodedValue) {
		if (typeof encodedValue !== 'string' || encodedValue === '') {
				return '';
		}

		try {
				const binaryValue = globalThis.atob(encodedValue);
				const binaryLength = binaryValue.length;
				const bytes = new Uint8Array(binaryLength);

				for (let index = 0; index < binaryLength; index++) {
						bytes[index] = binaryValue.charCodeAt(index);
				}

				if (typeof globalThis.TextDecoder === 'function') {
						return new globalThis.TextDecoder('utf-8').decode(bytes);
				}

				let decodedValue = '';
				for (let index = 0; index < binaryLength; index++) {
						decodedValue += String.fromCharCode(bytes[index]);
				}

				return decodedValue;
		} catch (_error) {
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

		element.dispatchEvent(new Event(eventName, { bubbles: true }));
}

jQuery(document).ready(function($) {
		const $settingsForm = $('form[action*="admin-post.php"]').has('input[name="action"][value="geweb_save"]').first();
		let initialFormState = $settingsForm.length ? $settingsForm.serialize() : '';
		let suppressBeforeUnloadWarning = false;

		function markFormSaved() {
				if (!$settingsForm.length) return;
				initialFormState = $settingsForm.serialize();
				updateSaveButtonState();
		}

		function hasUnsavedChanges() {
				return $settingsForm.length && initialFormState !== $settingsForm.serialize();
		}

		function updateSaveButtonState() {
				const $saveButton = $('#geweb-save-settings');
				if (!$saveButton.length) return;
				$saveButton.prop('disabled', !hasUnsavedChanges());
		}

		function getPromptTargetElements(scope, model) {
				if (scope === 'model' && model) {
						const escapedModel = model.replaceAll('"', '\\"');
						return {
								$prompt: $('[data-geweb-model-prompt="' + escapedModel + '"]').first(),
								$name: $('[data-geweb-model-prompt-name="' + escapedModel + '"]').first()
						};
				}

				return {
						$prompt: $('#geweb_ai_search_custom_prompt'),
						$name: $('#geweb_ai_search_custom_prompt_name')
				};
		}

		function setPromptValue(value, scope, model) {
				const target = getPromptTargetElements(scope, model);
				const $prompt = target.$prompt;
				const promptElement = $prompt.get(0);
				if (!promptElement) return;

				promptElement.value = value;
				$prompt.val(value);
				dispatchPromptEvent(promptElement, 'input');
				dispatchPromptEvent(promptElement, 'change');
				promptElement.focus();
		}

		function setPromptNameValue(value, scope, model) {
				const target = getPromptTargetElements(scope, model);
				if (!target.$name.length) return;
				target.$name.val(value || '');
		}

		function setPromptModeValue(mode, model) {
				if (!model) return;
				const normalized = mode === 'override' ? 'override' : 'append';
				const escapedModel = model.replaceAll('"', '\\"');
				$('[data-geweb-model-prompt-mode="' + escapedModel + '"][value="' + normalized + '"]').prop('checked', true);
		}
		function buildPromptDiff(currentPrompt, historyPrompt) {
				const currentLines = normalizePromptText(currentPrompt).split('\n');
				const historyLines = normalizePromptText(historyPrompt).split('\n');
				const maxLines = Math.max(currentLines.length, historyLines.length);
				const html = [];

				for (let i = 0; i < maxLines; i++) {
						const currentLine = currentLines[i];
						const historyLine = historyLines[i];
						const lineNumber = i + 1;

						if (currentLine === historyLine) {
								if (historyLine !== undefined) {
										html.push('<div>' + lineNumberHtml(lineNumber) + escapeHtml(historyLine) + '</div>');
								}
								continue;
						}

						if (currentLine !== undefined) {
								html.push('<div style="background:#fbeaea; color:#8a1f11;">' + lineNumberHtml(lineNumber) + escapeHtml('- ' + currentLine) + '</div>');
						}

						if (historyLine !== undefined) {
								html.push('<div style="background:#edf7ed; color:#166534;">' + lineNumberHtml(lineNumber) + escapeHtml('+ ' + historyLine) + '</div>');
						}
				}

				return html.length ? html.join('') : '<div>No differences from the current AI Prompt.</div>';
		}

		function updatePromptHistoryPreview(selectedPrompt, scope, model) {
				const $diff = $('#geweb-ai-prompt-history-diff');
				const target = getPromptTargetElements(scope, model);
				const $currentPrompt = target.$prompt;

				if (!$diff.length || !$currentPrompt.length) return;

				if (!selectedPrompt) {
						$diff.html('Select a saved prompt version to compare it with the current prompt field.');
						return;
				}

				$diff.html(buildPromptDiff($currentPrompt.val(), selectedPrompt));
		}

		function selectPromptHistoryItem($item) {
				if (!$item || !$item.length) return;
				$promptHistoryList.find('.geweb-ai-prompt-history-item').removeClass('selected').css('border-color', '#dcdcde');
				$item.addClass('selected').css('border-color', '#2271b1');
				updatePromptHistoryPreview(
						decodeBase64Value($item.data('prompt')),
						String($item.data('scope') || 'global'),
						String($item.data('model') || '')
				);
		}

		function showCellFeedback($cell, message, isError) {
				const $feedback = $cell.find('.geweb-ai-index-feedback');
				if (!$feedback.length) return;

				$feedback.text(message).css('color', isError ? '#d63638' : '#2271b1').show();
		}

		function replaceCellHtml($button, html) {
				const $cell = $button.closest('.geweb-ai-index-cell');
				if (!$cell.length || !html) return;

				$cell.replaceWith(html);
		}

		function refreshModelSelectorInBackground() {
				const $select = $('#geweb_ai_search_model');
				const $status = $('#geweb-ai-model-refresh-status');
				if (!$select.length) return;

				if ($status.length) {
						$status.text('Refreshing available Gemini models...').css({display: 'block', color: '#646970'});
				}

				$.ajax({
						url: getAdminAjaxUrl(),
						type: 'POST',
						dataType: 'json',
						data: {
								action: 'geweb_refresh_models',
								nonce: gewebAisearchAdmin.adminActionNonce
						}
				}).done(function(response) {
						if (!response || !response.success || !response.data || !$.isArray(response.data.models) || !response.data.models.length) {
								if ($status.length) {
										$status.text('Could not refresh models. Showing cached or bundled list.').css('color', '#996800');
								}
								return;
						}

						const selectedValue = String($select.val() || '');
						const remoteSelected = String(response.data.selected_model || selectedValue || '');
						$select.empty();

						$.each(response.data.models, function(_, model) {
								const value = String(model || '');
								if (!value) return;
								$select.append(new Option(value, value, false, value === remoteSelected));
						});

						if (remoteSelected && $select.find('option[value="' + remoteSelected.replaceAll('"', '\\"') + '"]').length === 0) {
								$select.prepend(new Option(remoteSelected, remoteSelected, true, true));
						} else if (remoteSelected) {
								$select.val(remoteSelected);
						}

						if ($status.length) {
								$status.text('Model list refreshed from Gemini.').css('color', '#46b450');
								globalThis.setTimeout(function() {
										$status.fadeOut(200, function() {
												$status.text('').css('display', 'none');
										});
								}, 2000);
						}
				}).fail(function() {
						if ($status.length) {
								$status.text('Could not refresh models. Showing cached or bundled list.').css('color', '#996800');
						}
				});
		}

		$('#geweb-ai-restore-default-prompt').on('click', function(event) {
				event.preventDefault();
				const defaultPrompt = $('#geweb_ai_search_default_prompt').val() || decodeBase64Value($(this).attr('data-default-prompt'));
				setPromptValue(defaultPrompt, 'global', '');
				$('#geweb_ai_search_custom_prompt_name').val('');
				updateSaveButtonState();
		});

		$('#geweb_ai_search_prompt_model_jump').on('change', function() {
				const model = String($(this).val() || '');
				if (!model) return;

				const $target = $('[data-geweb-model-prompt-details="' + model.replaceAll('"', '\\"') + '"]').first();
				if (!$target.length) return;

				$target.prop('open', true);
				const top = Math.max(0, $target.offset().top - 80);
				$('html, body').animate({ scrollTop: top }, 180);
		});

		$('.nav-tab-wrapper').on('click', '[data-geweb-tab]', function(e) {
				e.preventDefault();
				const tab = $(this).attr('data-geweb-tab');
				$('.nav-tab-wrapper [data-geweb-tab]').removeClass('nav-tab-active');
				$(this).addClass('nav-tab-active');
				$('.geweb-settings-tab-panel').hide();
				$('.geweb-settings-tab-panel[data-geweb-tab-panel="' + tab + '"]').show();
				if (globalThis.history?.replaceState) {
						const url = new URL(globalThis.location.href);
						url.searchParams.set('page', 'geweb-ai-search');
						url.searchParams.set('geweb_tab', tab);
						globalThis.history.replaceState({}, '', url.toString());
				}
		});

		const $promptHistoryList = $('#geweb-ai-prompt-history-list');

		$promptHistoryList.on('click', '.geweb-ai-prompt-history-item', function(e) {
				if ($(e.target).is('input, button, .dashicons')) {
						return;
				}
				selectPromptHistoryItem($(this));
		});

		$promptHistoryList.on('click', '.geweb-ai-use-history-prompt', function(e) {
				e.preventDefault();
				e.stopPropagation();
				const $item = $(this).closest('.geweb-ai-prompt-history-item');
				const value = decodeBase64Value($item.data('prompt'));
				if (!value) return;
				const scope = String($item.data('scope') || 'global');
				const model = String($item.data('model') || '');
				const mode = String($item.data('mode') || 'base');
				const name = $.trim(String($item.find('.geweb-ai-prompt-history-name-label').text() || ''));

				setPromptValue(value, scope, model);
				setPromptNameValue(name, scope, model);
				if (scope === 'model') {
						setPromptModeValue(mode, model);
				}
				updatePromptHistoryPreview(value, scope, model);
		});

		$promptHistoryList.on('click', '.geweb-ai-rename-history-prompt', function(e) {
				e.preventDefault();
				e.stopPropagation();
				const $item = $(this).closest('.geweb-ai-prompt-history-item');
				if (String($item.data('is-default') || '') === '1' || String($item.data('can-rename') || '') !== '1') {
						return;
				}
				const $button = $(this);
				const $label = $item.find('.geweb-ai-prompt-history-name-label');
				const $input = $item.find('.geweb-ai-prompt-history-name-input');
				const editing = $input.is(':visible');

				if (editing) {
						$label.text($input.val() || '').show();
						$input.hide();
						$button.text('Rename');
						return;
				}

				$label.hide();
				$input.show().focus().select();
				$button.text('Done');
		});

		$promptHistoryList.on('keydown', '.geweb-ai-prompt-history-name-input', function(e) {
				if (e.key !== 'Enter') return;
				e.preventDefault();
				$(this).closest('.geweb-ai-prompt-history-item').find('.geweb-ai-rename-history-prompt').trigger('click');
		});

		$promptHistoryList.on('blur', '.geweb-ai-prompt-history-name-input', function() {
				const $item = $(this).closest('.geweb-ai-prompt-history-item');
				const $button = $item.find('.geweb-ai-rename-history-prompt');
				if ($(this).is(':visible')) {
						$button.trigger('click');
				}
		});

		$promptHistoryList.on('click', '.geweb-ai-delete-history-prompt', function(e) {
				e.preventDefault();
				e.stopPropagation();
				const $button = $(this);
				const $item = $button.closest('.geweb-ai-prompt-history-item');
				const entryId = $item.data('entry-id');

				if (!entryId || !confirm('Are you sure you want to delete this prompt version?')) {
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
								entry_id: entryId
						}
				}).done(function(response) {
						if (!response || !response.success) {
								const message = response && response.data && response.data.message ? response.data.message : 'Could not delete prompt version.';
								alert(message);
								$button.prop('disabled', false);
								return;
						}
						$item.remove();
						if ($promptHistoryList.children().length === 0) {
								const $p = $('<p class="description">No previous prompts saved yet.</p>');
								$('#geweb-ai-prompt-history-list').replaceWith($p);
								$('#geweb-ai-clear-history').remove();
								$('#geweb-ai-prompt-history-diff').parent().remove();
						}
				}).fail(function() {
						alert('Could not delete prompt version due to a network error.');
						$button.prop('disabled', false);
				});
		});

		$(document).on('click', '.geweb-edit-conversation-trigger', function(e) {
				e.preventDefault();
				const $cell = $(this).closest('.geweb-conversation-summary-cell');
				$cell.find('.geweb-conversation-summary-label, .geweb-edit-conversation-trigger').hide();
				$cell.find('.geweb-edit-conversation-form').show();
				$cell.find('.geweb-edit-conversation-input').trigger('focus');
		});

		$(document).on('click', '.geweb-cancel-conversation-name', function(e) {
				e.preventDefault();
				const $cell = $(this).closest('.geweb-conversation-summary-cell');
				$cell.find('.geweb-edit-conversation-input').val($cell.data('current-summary') || '');
				$cell.find('.geweb-ai-index-feedback').hide().text('');
				$cell.find('.geweb-edit-conversation-form').hide();
				$cell.find('.geweb-conversation-summary-label, .geweb-edit-conversation-trigger').show();
		});

		$(document).on('click', '.geweb-save-conversation-name', function(e) {
				e.preventDefault();
				const $button = $(this);
				const $cell = $button.closest('.geweb-conversation-summary-cell');
				const $input = $cell.find('.geweb-edit-conversation-input');
				const $feedback = $cell.find('.geweb-ai-index-feedback');
				const conversationId = $cell.data('conversation-id');
				const summary = $.trim(String($input.val() || ''));

				if (!conversationId) {
						return;
				}

				if (!summary) {
						$feedback.text('Conversation name cannot be empty.').css('color', '#d63638').show();
						return;
				}

				$button.prop('disabled', true);
				$feedback.text('Saving conversation name...').css('color', '#646970').show();

				$.ajax({
						url: getAdminAjaxUrl(),
						type: 'POST',
						data: {
								action: 'geweb_rename_conversation',
								nonce: gewebAisearchAdmin.adminActionNonce,
								conversation_id: conversationId,
								summary: summary
						}
				}).done(function(response) {
						if (!response || !response.success || !response.data) {
								const message = response && response.data && response.data.message ? response.data.message : 'Could not rename the conversation.';
								$feedback.text(message).css('color', '#d63638').show();
								return;
						}

						$cell.data('current-summary', response.data.summary);
						$cell.find('.geweb-conversation-summary-label').text(response.data.summary).show();
						$cell.find('.geweb-edit-conversation-form').hide();
						$cell.find('.geweb-edit-conversation-trigger').show();
						$feedback.text(response.data.message || 'Conversation renamed.').css('color', '#46b450').show();
				}).fail(function() {
						$feedback.text('Could not rename the conversation.').css('color', '#d63638').show();
				}).always(function() {
						$button.prop('disabled', false);
				});
		});

		$(document).on('click', '.geweb-delete-conversation-trigger', function(e) {
				e.preventDefault();
				const $button = $(this);
				const $cell = $button.closest('.geweb-conversation-summary-cell');
				const $feedback = $cell.find('.geweb-ai-index-feedback');
				const conversationId = $cell.data('conversation-id');

				if (!conversationId) {
						return;
				}

				if (!globalThis.confirm('Delete this saved conversation?')) {
						return;
				}

				$button.prop('disabled', true);
				$feedback.text('Deleting conversation...').css('color', '#646970').show();

				$.ajax({
						url: getAdminAjaxUrl(),
						type: 'POST',
						data: {
								action: 'geweb_delete_conversation',
								nonce: gewebAisearchAdmin.adminActionNonce,
								conversation_id: conversationId
						}
				}).done(function(response) {
						if (!response || !response.success) {
								const message = response && response.data && response.data.message ? response.data.message : 'Could not delete the conversation.';
								$feedback.text(message).css('color', '#d63638').show();
								return;
						}

						globalThis.location.reload();
				}).fail(function() {
						$feedback.text('Could not delete the conversation.').css('color', '#d63638').show();
				}).always(function() {
						$button.prop('disabled', false);
				});
		});

		$('#geweb-ai-clear-history').on('click', function() {
				const $button = $(this);
				const $diff = $('#geweb-ai-prompt-history-diff');

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
								const message = response && response.data && response.data.message ? response.data.message : 'Could not clear prompt history.';
								if ($diff.length) {
										$diff.text(message);
								} else {
										alert(message);
								}
								$button.prop('disabled', false).text('Clear All History');
								return;
						}

						const $p = $('<p class="description">No previous prompts saved yet.</p>');
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

		$('#geweb_ai_search_custom_prompt, [data-geweb-model-prompt]').on('input', function() {
				const $selectedItem = $promptHistoryList.find('.geweb-ai-prompt-history-item.selected');
				const selectedPrompt = $selectedItem.length ? decodeBase64Value($selectedItem.data('prompt')) : null;
				updatePromptHistoryPreview(
						selectedPrompt,
						$selectedItem.length ? String($selectedItem.data('scope') || 'global') : 'global',
						$selectedItem.length ? String($selectedItem.data('model') || '') : ''
				);
		});
		updatePromptHistoryPreview(null);
		if ($promptHistoryList.length) {
				let $initialItem = $();
				$promptHistoryList.find('.geweb-ai-prompt-history-item').each(function() {
						const $item = $(this);
						const promptValue = normalizePromptText(decodeBase64Value($item.data('prompt')));
						const scope = String($item.data('scope') || 'global');
						const model = String($item.data('model') || '');
						const currentPromptValue = normalizePromptText(getPromptTargetElements(scope, model).$prompt.val());
						if (promptValue !== currentPromptValue) {
								$initialItem = $item;
								return false;
						}
				});
				if (!$initialItem.length) {
						$initialItem = $promptHistoryList.find('.geweb-ai-prompt-history-item').first();
				}
				selectPromptHistoryItem($initialItem);
		}
		refreshModelSelectorInBackground();

		$(window).on('beforeunload', function() {
				if (suppressBeforeUnloadWarning || !hasUnsavedChanges()) return;

				return 'You have unsaved changes. Are you sure you want to leave this page?';
		});

		$(document).on('mousedown pointerdown click', '.geweb-referenced-documents-table-form a, .geweb-gemini-stores-table-form a, .geweb-referenced-documents-table-form .button, .geweb-gemini-stores-table-form .button, .geweb-referenced-documents-table-form .button-link, .geweb-gemini-stores-table-form .button-link, #geweb-refresh-referenced-documents, #geweb-refresh-gemini-stores', function() {
				suppressBeforeUnloadWarning = true;
		});

		$(document).on('focus mousedown pointerdown change', '.geweb-referenced-documents-table-form select, .geweb-gemini-stores-table-form select', function() {
				suppressBeforeUnloadWarning = true;
		});

		$(document).on('submit', '.geweb-referenced-documents-table-form, .geweb-gemini-stores-table-form', function() {
				suppressBeforeUnloadWarning = true;
		});

		$('#geweb_ai_search_preserve_data_on_uninstall').on('change', function() {
				if (!this.checked) return;

				alert('Plugin data can be preserved on uninstall, but the stored API key and encryption key will always be removed.');
		});

		$('#geweb-toggle-api-key-visibility').on('click', function() {
				const $button = $(this);
				const $field = $('#geweb_api_key');
				if (!$field.length) return;

				const showing = $field.attr('type') === 'text';
				$field.attr('type', showing ? 'password' : 'text');
				$button.attr('aria-pressed', showing ? 'false' : 'true');
				$button.attr('aria-label', showing ? 'Show API key' : 'Hide API key');
				$button.attr('title', showing ? 'Show API key' : 'Hide API key');
				$button.find('.dashicons')
						.removeClass('dashicons-visibility dashicons-hidden')
						.addClass(showing ? 'dashicons-visibility' : 'dashicons-hidden');
		});

		function updateApiKeyVisibilityToggle() {
				const $field = $('#geweb_api_key');
				const $button = $('#geweb-toggle-api-key-visibility');
				const $warning = $('#geweb-api-key-replacement-warning');
				if (!$field.length || !$button.length) return;

				const hasValue = String($field.val() || '').length > 0;
				const hasValidSavedKey = String($field.data('current-key-valid') || '') === '1';
				$button.toggle(hasValue);
				if ($warning.length) {
						$warning.toggle(hasValue && hasValidSavedKey);
				}

				if (!hasValue) {
						$field.attr('type', 'password');
						$button.attr('aria-pressed', 'false');
						$button.attr('aria-label', 'Show API key');
						$button.attr('title', 'Show API key');
						$button.find('.dashicons')
								.removeClass('dashicons-hidden')
								.addClass('dashicons-visibility');
				}
		}

		$('#geweb_api_key').on('input change', function() {
				updateApiKeyVisibilityToggle();
		});

		updateApiKeyVisibilityToggle();
		globalThis.setTimeout(updateApiKeyVisibilityToggle, 150);
		globalThis.setTimeout(updateApiKeyVisibilityToggle, 600);

		if ($settingsForm.length) {
				$settingsForm.on('input change', function() {
						updateSaveButtonState();
				});
				$('#geweb_ai_search_frontend_ai_interface').on('change', function() {
						updateSaveButtonState();
				});
				$settingsForm.on('submit', function() {
						markFormSaved();
				});
		}
		updateSaveButtonState();

		let isProcessing = false;
		let totalSuccess = 0;
		let totalErrors = 0;

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
										const data = response.data;
										totalSuccess += data.success;
										totalErrors += data.errors;

										const percentage = Math.round((data.processed / data.total) * 100);
										const statusText = 'Processing: ' + data.processed + '/' + data.total + ' (' + percentage + '%)';

										$('#geweb-generate-status').html('<p>' + statusText + '</p>');

										if (data.has_more) {
												// Continue processing
												processPage(data.next_page);
										} else {
												// Finished
												let finalMessage = 'Completed! ' + totalSuccess + ' documents uploaded';
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

				const $btn = $(this);
				const $status = $('#geweb-generate-status');

				$btn.prop('disabled', true);
				$status.html('<p>Starting...</p>');

				processPage(1);
		});

		$(document).on('click', '.geweb-ai-reupload', function() {
				const $button = $(this);
				const $cell = $button.closest('.geweb-ai-index-cell');
				const postId = $cell.data('post-id');
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
				const $checkbox = $(this);
				const $cell = $checkbox.closest('.geweb-ai-index-cell');
				const postId = $cell.data('post-id');
				const exclude = $checkbox.is(':checked') ? 1 : 0;
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

		let isRefreshingReferencedDocuments = false;
		let isRefreshingGeminiStores = false;

		function refreshReferencedDocuments() {
				const $container = $('#geweb-referenced-documents-container');
				const $button = $('#geweb-refresh-referenced-documents');
				const $status = $('#geweb-referenced-documents-status');
				if (!$container.length || !$button.length || isRefreshingReferencedDocuments) return;

				isRefreshingReferencedDocuments = true;
				$button.prop('disabled', true).text('Refreshing...');
				if ($container.attr('data-needs-refresh') === '1') {
						$status.text('Loading referenced documents...');
						$container.html('<p>Loading referenced documents...</p>');
				} else {
						$status.text('Refreshing referenced documents... showing cached data until the update completes.');
				}

				$.ajax({
						url: getAdminAjaxUrl(),
						type: 'POST',
						dataType: 'json',
						data: {
								action: 'geweb_refresh_referenced_documents',
								nonce: gewebAisearchAdmin.adminActionNonce
						}
				}).done(function(response) {
						if (!response || !response.success || !response.data || !response.data.html) {
								$status.text('Could not refresh referenced documents.');
								isRefreshingReferencedDocuments = false;
								$button.prop('disabled', false).text('Refresh List');
								return;
						}

						$container.html(response.data.html).attr('data-needs-refresh', '0');
						const suffix = typeof response.data.count === 'number'
								? ' (' + response.data.count + ' documents)'
								: '';
						$status.text('Last refreshed: ' + (response.data.refreshed_at || 'just now') + suffix);
						isRefreshingReferencedDocuments = false;
						$button.prop('disabled', false).text('Refresh List');
				}).fail(function() {
						$status.text('Could not refresh referenced documents.');
						$container.html('<p>Could not load referenced documents.</p>');
						isRefreshingReferencedDocuments = false;
						$button.prop('disabled', false).text('Refresh List');
				});
		}

		function applyPendingReferencedDocumentTargets() {
				// This hook used to apply staged UI state for referenced documents.
				// Keep it as a no-op so admin initialization does not fail when the
				// referenced documents and Gemini stores panels are loaded.
		}

		function refreshSelectedGeminiStoreDocuments(storeName, storeLabel) {
				const $panel = $('#geweb-gemini-store-documents-panel');
				const $container = $('#geweb-gemini-store-documents-container');
				const $status = $('#geweb-gemini-store-documents-status');
				const $error = $('#geweb-gemini-store-documents-error');
				const $title = $('#geweb-gemini-store-documents-title');
				const $button = $('#geweb-refresh-gemini-store-documents');
				const $storesRefreshButton = $('#geweb-refresh-gemini-stores');
				if (!$panel.length || !$container.length || !storeName) return;

				$panel.attr('data-store-name', storeName);
				$title.text(storeLabel || storeName);
				$button.prop('disabled', true).text('Refreshing...');
				$storesRefreshButton.prop('disabled', true);
				$status.text('Loading uploaded items...');
				$error.hide().text('');
				$container.html('<p>Loading uploaded items...</p>');

				$.ajax({
						url: getAdminAjaxUrl(),
						type: 'POST',
						dataType: 'json',
						data: {
								action: 'geweb_refresh_gemini_store_documents',
								store_name: storeName,
								store_label: storeLabel || storeName,
								nonce: gewebAisearchAdmin.adminActionNonce
						}
				}).done(function(response) {
						if (!response || !response.success || !response.data || typeof response.data.html !== 'string') {
								const message = response && response.data && response.data.message
										? response.data.message
										: 'Could not refresh uploaded items.';
								$status.text(message);
								$error.text(message).show();
								$container.html('<p>Could not load uploaded items.</p>');
								$button.prop('disabled', false).text('Refresh List');
								$storesRefreshButton.prop('disabled', false).text('Refresh List');
								return;
						}

						$container.html(response.data.html);
						applyGeminiStoreDocumentsBrowserState();
						$title.text(response.data.store_label || storeLabel || storeName);
						$status.text((response.data.message || 'Uploaded items refreshed.') + (typeof response.data.count === 'number' ? ' (' + response.data.count + ' items)' : ''));
						$error.hide().text('');
						$button.prop('disabled', false).text('Refresh List');
						$storesRefreshButton.prop('disabled', false).text('Refresh List');
				}).fail(function(xhr) {
						const message = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
								? xhr.responseJSON.data.message
								: 'Could not refresh uploaded items.';
						$status.text(message);
						$error.text(message).show();
						$container.html('<p>Could not load uploaded items.</p>');
						$button.prop('disabled', false).text('Refresh List');
						$storesRefreshButton.prop('disabled', false).text('Refresh List');
				});
		}

		function applyGeminiStoreDocumentsBrowserState() {
				var $browser = $('.geweb-gemini-store-documents-browser');
				if (!$browser.length) return;

				$browser.each(function() {
						var $currentBrowser = $(this);
						var $filter = $currentBrowser.find('.geweb-gemini-store-documents-filter');
						var $idFilter = $currentBrowser.find('.geweb-gemini-store-documents-id-filter');
						var $slugFilter = $currentBrowser.find('.geweb-gemini-store-documents-slug-filter');
						var $typeFilter = $currentBrowser.find('.geweb-gemini-store-documents-type-filter');
						var $sortHeaders = $currentBrowser.find('.geweb-gemini-store-documents-sort-header');
						var $status = $currentBrowser.find('.geweb-gemini-store-documents-filter-status');
						var $tbody = $currentBrowser.find('tbody');
						var $rows = $tbody.find('tr');
						if (!$rows.length) {
								$status.text('No uploaded items found.');
								return;
						}

						var filterValue = $.trim(String($filter.val() || '')).toLowerCase();
						var idFilterValue = $.trim(String($idFilter.val() || '')).toLowerCase();
						var slugFilterValue = $.trim(String($slugFilter.val() || '')).toLowerCase();
						var typeValue = $.trim(String($typeFilter.val() || '')).toLowerCase();
						var sortValue = String($currentBrowser.attr('data-sort') || 'name-asc');
						var sortParts = sortValue.split('-');
						var sortKey = sortParts[0] || 'name';
						var sortDirection = sortParts[1] === 'desc' ? 'desc' : 'asc';

						var rows = $rows.get();
						rows.sort(function(a, b) {
								var $a = $(a);
								var $b = $(b);
								var direction = sortDirection === 'desc' ? -1 : 1;
								var aValue = String($a.attr('data-' + sortKey) || '');
								var bValue = String($b.attr('data-' + sortKey) || '');
								return aValue.localeCompare(bValue, undefined, { sensitivity: 'base' }) * direction;
						});
						$tbody.append(rows);

						$sortHeaders.each(function() {
								var $header = $(this);
								var headerKey = String($header.data('sort-key') || '');
								var headerLabel = String($header.data('sort-label') || $header.text() || '');
								var suffix = ' ↕';
								if (headerKey === sortKey) {
										suffix = sortDirection === 'desc' ? ' ↓' : ' ↑';
								}
								$header.text(headerLabel + suffix);
						});

						var visibleCount = 0;
						$rows.each(function() {
								var $row = $(this);
								var haystack = [
										String($row.attr('data-name') || ''),
										String($row.attr('data-id') || ''),
										String($row.attr('data-slug') || ''),
										String($row.attr('data-type') || ''),
										String($row.attr('data-url') || '')
								].join(' ');
								var matchesFilter = !filterValue || haystack.indexOf(filterValue) !== -1;
								var matchesId = !idFilterValue || String($row.attr('data-id') || '').split(',').map(function(part) {
										return $.trim(String(part || ''));
								}).indexOf(idFilterValue) !== -1;
								var matchesSlug = !slugFilterValue || String($row.attr('data-slug') || '').indexOf(slugFilterValue) !== -1;
								var matchesType = !typeValue || String($row.attr('data-type') || '') === typeValue;
								var visible = matchesFilter && matchesId && matchesSlug && matchesType;
								$row.toggle(visible);
								if (visible) visibleCount += 1;
						});

						$status.text(visibleCount + ' item' + (visibleCount === 1 ? '' : 's') + ' shown');
				});
		}

		$('#geweb-refresh-referenced-documents').on('click', function() {
				refreshReferencedDocuments();
		});

		$(document).on('click', '.geweb-referenced-document-upload-now', function() {
				var $button = $(this);
				var fileHash = String($button.data('file-hash') || '');
				var $cell = $button.closest('.geweb-ai-index-cell');
				var $status = $('#geweb-referenced-documents-status');
				if (!fileHash) return;

				$button.prop('disabled', true).text('Uploading...');
				showCellFeedback($cell, 'Uploading in the background...', false);
				if ($status.length) {
						$status.text('Uploading document...');
				}

				$.ajax({
						url: getAdminAjaxUrl(),
						type: 'POST',
						dataType: 'json',
						data: {
								action: 'geweb_update_referenced_document',
								document_action: 'upload',
								file_hash: fileHash,
								nonce: gewebAisearchAdmin.adminActionNonce
						}
				}).done(function(response) {
						if (!response || !response.success || !response.data) {
								var message = response && response.data && response.data.message
										? response.data.message
										: 'The document upload could not be completed.';
								showCellFeedback($cell, message, true);
								$button.prop('disabled', false).text('Upload');
								if ($status.length) {
										$status.text(message);
								}
								return;
						}

						var $row = $button.closest('tr');
						if ($row.length) {
								if (response.data.status_html) {
										$row.find('td.column-status').html(response.data.status_html);
								}
								if (response.data.actions_html) {
										$row.find('td.column-actions').html(response.data.actions_html);
								}
						}

						if ($status.length) {
								$status.text(response.data.message || 'Document uploaded.');
						}

						if ($('#geweb-gemini-stores-container').length) {
								refreshGeminiStores();
						}
				}).fail(function(xhr) {
						var message = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
								? xhr.responseJSON.data.message
								: 'The document upload could not be completed.';
						showCellFeedback($cell, message, true);
						$button.prop('disabled', false).text('Upload');
						if ($status.length) {
								$status.text(message);
						}
				});
		});

		$(document).on('change', '.geweb-referenced-document-toggle-exclude', function() {
				var $checkbox = $(this);
				var $cell = $checkbox.closest('.geweb-ai-index-cell');
				var fileHash = String($checkbox.data('file-hash') || '');
				var exclude = $checkbox.is(':checked') ? 1 : 0;
				var $status = $('#geweb-referenced-documents-status');
				if (!fileHash) return;

				$checkbox.prop('disabled', true);
				showCellFeedback($cell, exclude ? 'Excluding in the background...' : 'Including for upload...', false);

				$.ajax({
						url: getAdminAjaxUrl(),
						type: 'POST',
						dataType: 'json',
						data: {
								action: 'geweb_toggle_referenced_document_exclude',
								file_hash: fileHash,
								exclude: exclude,
								nonce: gewebAisearchAdmin.adminActionNonce
						}
				}).done(function(response) {
						if (!response || !response.success || !response.data) {
								var message = response && response.data && response.data.message
										? response.data.message
										: 'Could not update exclusion.';
								showCellFeedback($cell, message, true);
								$checkbox.prop('disabled', false).prop('checked', !exclude);
								if ($status.length) {
										$status.text(message);
								}
								return;
						}

						var $row = $checkbox.closest('tr');
						if ($row.length) {
								if (response.data.status_html) {
										$row.find('td.column-status').html(response.data.status_html);
								}
								if (response.data.actions_html) {
										$row.find('td.column-actions').html(response.data.actions_html);
								}
						}

						if ($status.length) {
								$status.text(response.data.message || (exclude ? 'Excluded from indexing.' : 'Included for indexing.'));
						}

						if ($('#geweb-gemini-stores-container').length) {
								refreshGeminiStores();
						}
				}).fail(function(xhr) {
						var message = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
								? xhr.responseJSON.data.message
								: 'Could not update exclusion.';
						showCellFeedback($cell, message, true);
						$checkbox.prop('disabled', false).prop('checked', !exclude);
						if ($status.length) {
								$status.text(message);
						}
				});
		});

		$(document).on('click', '.geweb-edit-nice-name-trigger', function(e) {
				e.preventDefault();
				var $cell = $(this).closest('.geweb-nice-name-cell');
				if (!$cell.length) return;

				$cell.find('.geweb-edit-nice-name-form').show();
				$(this).hide();
				var $input = $cell.find('.geweb-edit-nice-name-input');
				$input.trigger('focus').trigger('select');
		});

		$(document).on('click', '.geweb-cancel-nice-name', function(e) {
				e.preventDefault();
				var $cell = $(this).closest('.geweb-nice-name-cell');
				if (!$cell.length) return;

				$cell.find('.geweb-edit-nice-name-input').val(String($cell.data('current-nice-name') || ''));
				$cell.find('.geweb-ai-index-feedback').hide().text('');
				$cell.find('.geweb-edit-nice-name-form').hide();
				$cell.find('.geweb-edit-nice-name-trigger').show();
		});

		$(document).on('keydown', '.geweb-edit-nice-name-input', function(e) {
				if (e.key === 'Enter') {
						e.preventDefault();
						$(this).closest('.geweb-nice-name-cell').find('.geweb-save-nice-name').trigger('click');
				}

				if (e.key === 'Escape') {
						e.preventDefault();
						$(this).closest('.geweb-nice-name-cell').find('.geweb-cancel-nice-name').trigger('click');
				}
		});

		$(document).on('click', '.geweb-save-nice-name', function(e) {
				e.preventDefault();
				var $button = $(this);
				var $cell = $button.closest('.geweb-nice-name-cell');
				var fileHash = String($cell.data('file-hash') || '');
				var $input = $cell.find('.geweb-edit-nice-name-input');
				var niceName = $.trim(String($input.val() || ''));
				var $feedback = $cell.find('.geweb-ai-index-feedback');
				var $status = $('#geweb-referenced-documents-status');
				var $niceNameCell = $button.closest('tr').find('td.column-nice_name');

				if (!fileHash) return;
				if (!niceName) {
						$feedback.text('Enter a nice name.').css('color', '#d63638').show();
						$input.trigger('focus');
						return;
				}

				$button.prop('disabled', true).text('Saving...');
				$feedback.text('Saving nice name...').css('color', '#2271b1').show();
				if ($status.length) {
						$status.text('Saving nice name...');
				}

				$.ajax({
						url: getAdminAjaxUrl(),
						type: 'POST',
						dataType: 'json',
						data: {
								action: 'geweb_update_referenced_document_nice_name',
								file_hash: fileHash,
								nice_name: niceName,
								nonce: gewebAisearchAdmin.adminActionNonce
						}
				}).done(function(response) {
						if (!response || !response.success || !response.data) {
								var message = response && response.data && response.data.message
										? response.data.message
										: 'The nice name could not be updated.';
								$feedback.text(message).css('color', '#d63638').show();
								$button.prop('disabled', false).text('Save');
								if ($status.length) {
										$status.text(message);
								}
								return;
						}

						if ($niceNameCell.length && response.data.nice_name_html) {
								$niceNameCell.html(response.data.nice_name_html);
						}

						var suffix = typeof response.data.count === 'number'
								? ' (' + response.data.count + ' documents)'
								: '';
						if ($status.length) {
								$status.text((response.data.message || 'Nice name updated.') + ' Last refreshed: ' + (response.data.refreshed_at || 'just now') + suffix);
						}
				}).fail(function(xhr) {
						var message = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
								? xhr.responseJSON.data.message
								: 'The nice name could not be updated.';
						$feedback.text(message).css('color', '#d63638').show();
						$button.prop('disabled', false).text('Save');
						if ($status.length) {
								$status.text(message);
						}
				});
		});

		if ($('#geweb-referenced-documents-container').attr('data-needs-refresh') === '1') {
				$('#geweb-refresh-referenced-documents').prop('disabled', true).text('Refreshing...');
				refreshReferencedDocuments();
		}

		applyPendingReferencedDocumentTargets();

		function refreshGeminiStores() {
				var $container = $('#geweb-gemini-stores-container');
				var $button = $('#geweb-refresh-gemini-stores');
				var $status = $('#geweb-gemini-stores-status');
				var $error = $('#geweb-gemini-stores-error');
				if (!$container.length || !$button.length || isRefreshingGeminiStores) return;

				isRefreshingGeminiStores = true;
				$button.prop('disabled', true).text('Refreshing...');
				$status.text('Loading Gemini stores...');
				$error.hide().text('');
				$container.html('<p>Loading Gemini stores...</p>');

				$.ajax({
						url: getAdminAjaxUrl(),
						type: 'POST',
						dataType: 'json',
						data: {
								action: 'geweb_refresh_gemini_stores',
								nonce: gewebAisearchAdmin.adminActionNonce
						}
				}).done(function(response) {
						if (!response || !response.success || !response.data || !response.data.html) {
								$status.text('Could not refresh Gemini stores.');
								isRefreshingGeminiStores = false;
								$button.prop('disabled', false).text('Refresh List');
								return;
						}

						$container.html(response.data.html).attr('data-needs-refresh', '0');
						var suffix = '';
						if (typeof response.data.count === 'number') {
								suffix = ' (' + response.data.count + ' ' + (response.data.count === 1 ? 'store' : 'stores') + ')';
						}
						$status.text('Last refreshed: ' + (response.data.refreshed_at || 'just now') + suffix);
						if (response.data.error) {
								$error.text(response.data.error).show();
						} else {
								$error.hide().text('');
						}
						$('#geweb-refresh-gemini-store-documents').prop('disabled', true).text('Refreshing...');
						var selectedStoreName = $('#geweb-gemini-store-documents-panel').attr('data-store-name') || '';
						var $selectedButton = selectedStoreName
								? $('.geweb-select-gemini-store[data-store-name="' + String(selectedStoreName).replace(/"/g, '\\"') + '"]').first()
								: $();
						if (!$selectedButton.length) {
								$selectedButton = $('.geweb-select-gemini-store').first();
						}
						if ($selectedButton.length) {
								refreshSelectedGeminiStoreDocuments(
										String($selectedButton.data('store-name') || ''),
										String($selectedButton.data('store-label') || '')
								);
						} else {
								$('#geweb-refresh-gemini-store-documents').prop('disabled', false).text('Refresh List');
						}
						isRefreshingGeminiStores = false;
						$button.prop('disabled', false).text('Refresh List');
				}).fail(function() {
						$status.text('Could not refresh Gemini stores.');
						$error.hide().text('');
						$container.html('<p>Could not load Gemini stores.</p>');
						$('#geweb-refresh-gemini-store-documents').prop('disabled', false).text('Refresh List');
						isRefreshingGeminiStores = false;
						$button.prop('disabled', false).text('Refresh List');
				});
		}

		$('#geweb-refresh-gemini-stores').on('click', function() {
				refreshGeminiStores();
		});

		$(document).on('click', '.geweb-select-gemini-store', function(e) {
				e.preventDefault();
				var $button = $(this);
				var storeName = String($button.data('store-name') || '');
				var storeLabel = String($button.data('store-label') || '');
				if (!storeName) return;

				$('.geweb-select-gemini-store').css('font-weight', '400');
				$button.css('font-weight', '600');
				refreshSelectedGeminiStoreDocuments(storeName, storeLabel);
		});

		$('#geweb-refresh-gemini-store-documents').on('click', function() {
				var $panel = $('#geweb-gemini-store-documents-panel');
				var storeName = String($panel.attr('data-store-name') || '');
				var storeLabel = $.trim($('#geweb-gemini-store-documents-title').text());
				if (!storeName) return;

				refreshSelectedGeminiStoreDocuments(storeName, storeLabel);
		});

		$(document).on('input change', '.geweb-gemini-store-documents-filter, .geweb-gemini-store-documents-id-filter, .geweb-gemini-store-documents-slug-filter, .geweb-gemini-store-documents-type-filter', function() {
				applyGeminiStoreDocumentsBrowserState();
		});

		$(document).on('click', '.geweb-gemini-store-documents-sort-header', function(e) {
				e.preventDefault();
				var $button = $(this);
				var $browser = $button.closest('.geweb-gemini-store-documents-browser');
				var sortKey = String($button.data('sort-key') || 'name');
				var currentSort = String($browser.attr('data-sort') || 'name-asc');
				var nextDirection = 'asc';

				if (currentSort === sortKey + '-asc') {
						nextDirection = 'desc';
				}

				$browser.attr('data-sort', sortKey + '-' + nextDirection);
				applyGeminiStoreDocumentsBrowserState();
		});

		$(document).on('click', '.geweb-delete-gemini-store', function(e) {
				e.preventDefault();
				e.stopPropagation();

				var $button = $(this);
				var storeName = $button.data('store-name');
				var confirmMessage = $button.data('confirm-message') || 'Delete this Gemini store?';
				var $container = $('#geweb-gemini-stores-container');
				var $status = $('#geweb-gemini-stores-status');
				var $error = $('#geweb-gemini-stores-error');
				var $row = $button.closest('tr');
				if (!storeName || !$container.length || $button.prop('disabled')) return;
				if (!window.confirm(confirmMessage)) return;

				$button.prop('disabled', true).text('Deleting...');
				$row.css('opacity', '0.5');
				$status.text('Deleting Gemini store...');
				$error.hide().text('');

				$.ajax({
						url: getAdminAjaxUrl(),
						type: 'POST',
						dataType: 'json',
						data: {
								action: 'geweb_delete_gemini_store',
								store_name: storeName,
								nonce: gewebAisearchAdmin.adminActionNonce
						}
				}).done(function(response) {
						if (!response || !response.success || !response.data || !response.data.html) {
								var message = response && response.data && response.data.message
										? response.data.message
										: 'Could not delete the Gemini store.';
								$status.text(message);
								$error.text(message).show();
								$button.prop('disabled', false).text('Delete');
								$row.css('opacity', '1');
								return;
						}

						$container.html(response.data.html).attr('data-needs-refresh', '0');
						var suffix = '';
						if (typeof response.data.count === 'number') {
								suffix = ' (' + response.data.count + ' ' + (response.data.count === 1 ? 'store' : 'stores') + ')';
						}
						$status.text((response.data.message || 'Gemini store deleted.') + ' Last refreshed: ' + (response.data.refreshed_at || 'just now') + suffix);
						if (response.data.error) {
								$error.text(response.data.error).show();
						} else {
								$error.hide().text('');
						}

						if (response.data.deleted_store_name && $container.find('.geweb-delete-gemini-store[data-store-name="' + response.data.deleted_store_name.replace(/"/g, '\\"') + '"]').length) {
								$status.text('Delete requested. Refreshing the Gemini stores list again...');
								window.setTimeout(function() {
										refreshGeminiStores();
								}, 1500);
						}
				}).fail(function(xhr) {
						var message = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
								? xhr.responseJSON.data.message
								: 'Could not delete the Gemini store.';
						$status.text(message);
						$error.text(message).show();
						$button.prop('disabled', false).text('Delete');
						$row.css('opacity', '1');
				});
		});

		if ($('#geweb-gemini-stores-container').attr('data-needs-refresh') === '1') {
				$('#geweb-refresh-gemini-stores').prop('disabled', true).text('Refreshing...');
				refreshGeminiStores();
		}

		applyGeminiStoreDocumentsBrowserState();
	});
