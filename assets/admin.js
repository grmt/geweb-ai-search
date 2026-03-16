jQuery(document).ready(function($) {
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

		$('#geweb-ai-restore-default-prompt').on('click', function() {
				var $prompt = $('#geweb_ai_search_custom_prompt');
				if (!$prompt.length) return;

				$prompt.val($prompt.data('default-prompt'));
		});

		$('#geweb-ai-restore-history-prompt').on('click', function() {
				var $prompt = $('#geweb_ai_search_custom_prompt');
				var value = $('#geweb-ai-prompt-history-select').val();
				if (!$prompt.length || !value) return;

				$prompt.val(value);
		});

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
