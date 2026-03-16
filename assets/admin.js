jQuery(document).ready(function($) {
		$('#geweb-ai-restore-default-prompt').on('click', function() {
				var $prompt = $('#geweb_ai_search_custom_prompt');
				if (!$prompt.length) return;

				$prompt.val($prompt.data('default-prompt'));
		});

		var isProcessing = false;
		var totalSuccess = 0;
		var totalErrors = 0;

		function processPage(page) {
				$.ajax({
						url: ajaxurl,
						type: 'POST',
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
														finalMessage += ', ' + totalErrors + ' errors';
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
	});
