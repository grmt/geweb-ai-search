(function($) {
const {
	buildCountSuffix,
	cycleSortDirection,
	decodeBase64Value,
	ensureOriginalRowIndex,
	escapeHtml,
	escapeSelectorAttributeValue,
	getAdminAjaxUrl,
	getAriaSortValue,
	getRegexMatch,
	getSortIndicator,
	parseLocalSortSpecs,
	populateModelSelectOptions,
	readLocalStorageString,
	submitFormWithFallback,
	writeLocalStorageString,
} = globalThis.GewebAISearchAdminUtils || {};
const {
	getAdminMenuTargetTab = () => 'general',
	normalizePromptText = (text) => String(text || ''),
	renderMarkdownPreview = (markdown) => String(markdown || ''),
} = globalThis.GewebAISearchAdminMarkdown || {};

function compareSortableValues(leftValue, rightValue, sortType, direction) {
		const normalizedDirection = direction === 'desc' ? -1 : 1;
		const left = String(leftValue || '').trim();
		const right = String(rightValue || '').trim();

		if (!left && !right) {
				return 0;
		}

		if (!left) {
				return 1;
		}

		if (!right) {
				return -1;
		}

		if (sortType === 'date' || sortType === 'number') {
				const leftNumber = Number(left);
				const rightNumber = Number(right);
				if (Number.isFinite(leftNumber) && Number.isFinite(rightNumber) && leftNumber !== rightNumber) {
						return (leftNumber - rightNumber) * normalizedDirection;
				}
		}

		return left.localeCompare(right, undefined, { numeric: true, sensitivity: 'base' }) * normalizedDirection;
}

function setModelDiagnosticsSortSpecs($table, specs) {
		$table.attr('data-local-sort-specs', JSON.stringify(Array.isArray(specs) ? specs : []));
}

function getModelDiagnosticsStatusRank(status) {
		const normalizedStatus = String(status || '').trim().toLowerCase();
		if (normalizedStatus === 'failed') {
				return 0;
		}

		if (normalizedStatus === 'ok') {
				return 1;
		}

		return 2;
}

function compareModelDiagnosticsRows($leftRow, $rightRow, spec) {
		const column = String(spec?.column || '').trim();
		const direction = String(spec?.direction || '').trim();
		const sortType = String(spec?.sortType || 'text').trim();
		if (!column || !direction) {
				return 0;
		}

		if (column === 'test') {
				const leftStatusRank = getModelDiagnosticsStatusRank($leftRow.attr('data-sort-test-status'));
				const rightStatusRank = getModelDiagnosticsStatusRank($rightRow.attr('data-sort-test-status'));
				if (leftStatusRank !== rightStatusRank) {
						return (leftStatusRank - rightStatusRank) * (direction === 'desc' ? -1 : 1);
				}
		}

		const leftValue = String($leftRow.attr('data-sort-' + column) || '');
		const rightValue = String($rightRow.attr('data-sort-' + column) || '');
		return compareSortableValues(leftValue, rightValue, sortType, direction);
}

function updateModelDiagnosticsHeaderState($table, specs) {
		const activeSpecs = Array.isArray(specs) ? specs : [];
		$table.find('.geweb-sortable-column').each(function() {
				const $button = $(this);
				const column = String($button.attr('data-sort-column') || '');
				const specIndex = activeSpecs.findIndex((spec) => {
						return String(spec.column || '') === column;
				});
				const isActive = specIndex >= 0;
				const direction = isActive ? String(activeSpecs[specIndex].direction || '') : '';
				const indicator = getSortIndicator(isActive, direction);
				$button.attr('aria-pressed', isActive ? 'true' : 'false');
				$button.css({
						fontWeight: isActive ? '600' : '',
						opacity: isActive ? '0.92' : ''
				});
				$button.find('[aria-hidden="true"]').text(indicator);
				$button.closest('th').attr('aria-sort', getAriaSortValue(direction));
		});
}

function sortModelDiagnosticsTable($table, column, sortType) {
		const $tbody = $table.find('tbody').first();
		if (!$tbody.length) {
				return;
		}

		const currentSpecs = parseLocalSortSpecs($table);
		const nextSpecs = currentSpecs.slice();
		const existingIndex = nextSpecs.findIndex((spec) => {
				return String(spec.column || '') === column;
		});
		const currentDirection = existingIndex >= 0 ? String(nextSpecs[existingIndex].direction || '') : '';
		const nextDirection = cycleSortDirection(currentDirection);

		if (!nextDirection) {
				if (existingIndex >= 0) {
						nextSpecs.splice(existingIndex, 1);
				}
		} else if (existingIndex >= 0) {
				nextSpecs[existingIndex].direction = nextDirection;
				nextSpecs[existingIndex].sortType = sortType;
		} else {
				nextSpecs.push({
						column: column,
						direction: nextDirection,
						sortType: sortType
				});
		}

		const rows = $tbody.find('tr').get();
		rows.forEach((row, index) => {
				const $row = $(row);
				ensureOriginalRowIndex($row, index);
		});

		rows.sort((leftRow, rightRow) => {
				const $leftRow = $(leftRow);
				const $rightRow = $(rightRow);

				for (const spec of nextSpecs) {
						const result = compareModelDiagnosticsRows($leftRow, $rightRow, spec);
						if (result !== 0) {
								return result;
						}
				}

				const leftOriginalIndex = Number($leftRow.attr('data-original-index') || '0');
				const rightOriginalIndex = Number($rightRow.attr('data-original-index') || '0');
				return leftOriginalIndex - rightOriginalIndex;
		});

		rows.forEach((row) => {
				$tbody.append(row);
		});

		setModelDiagnosticsSortSpecs($table, nextSpecs);
		updateModelDiagnosticsHeaderState($table, nextSpecs);
}

function prepareModelDiagnosticsTableHeaders() {
		jQuery('.geweb-model-diagnostics-table').each(function() {
				const $table = jQuery(this);
				$table.find('tbody tr').each(function(index) {
						const $row = jQuery(this);
						ensureOriginalRowIndex($row, index);
				});
				updateModelDiagnosticsHeaderState($table, parseLocalSortSpecs($table));
		});
}

function getReferencedDocumentsCellSortValue($row, column) {
		const normalizedColumn = String(column || '').trim();
		if (!normalizedColumn) {
				return '';
		}

		const $cell = $row.find('td.column-' + normalizedColumn).first();
		if (!$cell.length) {
				return '';
		}

		if (normalizedColumn === 'last_modified' || normalizedColumn === 'last_uploaded') {
				const text = String($cell.text() || '').trim();
				const matched = getRegexMatch(text, /\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/);
				return matched ? matched[0] : text;
		}

		if (normalizedColumn === 'actions') {
				const statusText = String($cell.find('.geweb-ai-index-cell > p').first().text() || '').trim();
				return statusText || String($cell.text() || '').trim();
		}

		if (normalizedColumn === 'nice_name') {
				const inputValue = String($cell.find('.geweb-edit-nice-name-input').val() || '').trim();
				const triggerText = String($cell.find('.geweb-edit-nice-name-trigger').first().text() || '').trim();
				return inputValue || triggerText || String($cell.text() || '').trim();
		}

		return String($cell.text() || '').trim();
}

function setReferencedDocumentsSortSpecs($table, specs) {
		$table.attr('data-local-sort-specs', JSON.stringify(Array.isArray(specs) ? specs : []));
}

function getReferencedDocumentsColumnSortType(column) {
		return column === 'last_modified' || column === 'last_uploaded' ? 'date' : 'text';
}

function updateReferencedDocumentsHeaderState($table, specs) {
		const activeSpecs = Array.isArray(specs) ? specs : [];
		$table.find('thead th, tfoot th').each(function() {
				const $th = $(this);
				const column = String($th.attr('id') || '').trim();
				const specIndex = activeSpecs.findIndex((spec) => {
						return String(spec.column || '') === column;
				});
				const isActive = specIndex >= 0;
				const direction = isActive ? String(activeSpecs[specIndex].direction || '') : '';
				const $link = $th.find('a').first();

				$th.removeClass('sorted asc desc sortable');
				$th.addClass(isActive ? ('sorted ' + direction) : 'sortable');
				$th.attr('aria-sort', getAriaSortValue(direction));

				if ($link.length) {
						$link.attr('aria-sort', getAriaSortValue(direction));
						$link.css({
								fontWeight: isActive ? '600' : '',
								opacity: isActive ? '0.92' : ''
						});
						$link.find('.sorting-indicator').hide();
				}
		});
}

function sortReferencedDocumentsTable($table, column) {
		const $tbody = $table.find('tbody').first();
		if (!$tbody.length) {
				return;
		}

		const currentSpecs = parseLocalSortSpecs($table);
		const nextSpecs = currentSpecs.slice();
		const existingIndex = nextSpecs.findIndex((spec) => {
				return String(spec.column || '') === column;
		});
		const currentDirection = existingIndex >= 0 ? String(nextSpecs[existingIndex].direction || '') : '';
		const nextDirection = cycleSortDirection(currentDirection);
		if (!nextDirection) {
				if (existingIndex >= 0) {
						nextSpecs.splice(existingIndex, 1);
				}
		} else if (existingIndex >= 0) {
				nextSpecs[existingIndex].direction = nextDirection;
		} else {
				nextSpecs.push({
						column: column,
						direction: nextDirection
				});
		}

		const rows = $tbody.find('tr').get();
		rows.forEach((row, index) => {
				const $row = $(row);
				ensureOriginalRowIndex($row, index);
		});

		rows.sort((leftRow, rightRow) => {
				const $leftRow = $(leftRow);
				const $rightRow = $(rightRow);

				for (const spec of nextSpecs) {
						const currentSpec = spec || {};
						const specColumn = String(currentSpec.column || '').trim();
						const specDirection = String(currentSpec.direction || '').trim();
						if (!specColumn || !specDirection) {
								continue;
						}

						const leftValue = getReferencedDocumentsCellSortValue($leftRow, specColumn);
						const rightValue = getReferencedDocumentsCellSortValue($rightRow, specColumn);
						const result = compareSortableValues(
								leftValue,
								rightValue,
								getReferencedDocumentsColumnSortType(specColumn),
								specDirection
						);
						if (result !== 0) {
								return result;
						}
				}

				const leftOriginalIndex = Number($leftRow.attr('data-original-index') || '0');
				const rightOriginalIndex = Number($rightRow.attr('data-original-index') || '0');
				return leftOriginalIndex - rightOriginalIndex;
		});

		rows.forEach((row) => {
				$tbody.append(row);
		});

		setReferencedDocumentsSortSpecs($table, nextSpecs);
		updateReferencedDocumentsHeaderState($table, nextSpecs);
}

function prepareReferencedDocumentsTableHeaders() {
		jQuery('.geweb-referenced-documents-table-form table').each(function() {
				const $table = jQuery(this);
				const $form = $table.closest('.geweb-referenced-documents-table-form');
				const $searchInput = $form.find('input[name="s"]').first();
				prepareReferencedDocumentsSearchBox($form);
				$table.find('th a .sorting-indicator').hide();
				$searchInput.attr('autocomplete', 'on');
					if ($searchInput.length && !$searchInput.val()) {
							const savedSearch = readLocalStorageString('gewebReferencedDocumentsSearch');
							if (savedSearch) {
									$searchInput.val(savedSearch);
							}
					}
					$table.find('tbody tr').each(function(index) {
							const $row = jQuery(this);
							ensureOriginalRowIndex($row, index);
					});
					updateReferencedDocumentsHeaderState($table, parseLocalSortSpecs($table));
				applyReferencedDocumentsFilters($form);
			});
}

function updateReferencedDocumentsSearchBoxState($form, expanded) {
		if (!$form?.length) {
			return;
		}

		const $toggle = $form.find('.geweb-referenced-documents-search-toggle').first();
		const $searchBox = $form.find('.search-box').first();
		const shouldExpand = !!expanded;

		$form.attr('data-search-expanded', shouldExpand ? '1' : '0');
		$searchBox.toggleClass('is-collapsed', !shouldExpand);
		if ($toggle.length) {
			$toggle.attr('aria-expanded', shouldExpand ? 'true' : 'false');
			$toggle.text(shouldExpand ? 'Hide search' : 'Show search');
		}
	}

function prepareReferencedDocumentsSearchBox($form) {
		if (!$form?.length) {
			return;
		}

		const $searchBox = $form.find('.search-box').first();
		const $searchInput = $searchBox.find('input[name="s"]').first();
		if (!$searchBox.length || !$searchInput.length) {
			return;
		}

		$form.addClass('geweb-referenced-documents-search-ready');
		const inputId = String($searchInput.attr('id') || 'search-input');
		let $toggle = $form.find('.geweb-referenced-documents-search-toggle').first();
		if (!$toggle.length) {
			$toggle = jQuery('<button type="button" class="button geweb-referenced-documents-search-toggle">Show search</button>');
			$searchBox.before($toggle);
		}

		$toggle.attr('aria-controls', inputId);
		const hasSearchValue = $.trim(String($searchInput.val() || '')) !== '';
		const isMobileViewport = globalThis.matchMedia ? globalThis.matchMedia('(max-width: 782px)').matches : false;
		updateReferencedDocumentsSearchBoxState($form, !isMobileViewport || hasSearchValue);
	}

function getReferencedDocumentsFilters($form) {
		return {
			status: String($form.find('#geweb_ai_referenced_doc_status').first().val() || '').trim(),
			type: String($form.find('#geweb_ai_referenced_doc_type').first().val() || '').trim(),
			referencedIn: String($form.find('#geweb_ai_referenced_doc_referenced_in').first().val() || '').trim(),
			searchTerm: String($form.find('input[name="s"]').first().val() || '').trim().toLowerCase(),
		};
}

function rowMatchesReferencedDocumentsFilters($row, filters) {
		if (filters.status && String($row.attr('data-referenced-document-status') || '').trim() !== filters.status) {
			return false;
		}

		if (filters.type && String($row.attr('data-referenced-document-type') || '').trim() !== filters.type) {
			return false;
		}

		if (filters.referencedIn && String($row.attr('data-referenced-document-referenced-in') || '').trim() !== filters.referencedIn) {
			return false;
		}

		if (filters.searchTerm) {
			const haystack = String($row.attr('data-referenced-document-search') || $row.text() || '').toLowerCase();
			if (!haystack.includes(filters.searchTerm)) {
				return false;
			}
		}

		return true;
}

function applyReferencedDocumentsFilters($form) {
		if (!$form?.length) {
			return;
		}

		const filters = getReferencedDocumentsFilters($form);
		const $table = $form.find('table').first();
		if (!$table.length) {
			return;
		}

		const $rows = $table.find('tbody tr');
		let visibleCount = 0;
		$rows.each(function() {
			const $row = jQuery(this);
			const showRow = rowMatchesReferencedDocumentsFilters($row, filters);
			$row.toggle(showRow);
			if (showRow) {
				visibleCount += 1;
			}
		});

		let $message = $form.find('.geweb-referenced-documents-no-results');
		if (!$message.length) {
			$message = jQuery('<div class="geweb-referenced-documents-no-results description" style="margin-top:12px;">No documents match the current filter.</div>');
			$table.after($message);
		}
		$message.toggle(visibleCount === 0);
}

function prepareConversationsTableSearch() {
		jQuery('.geweb-conversations-table-form').each(function() {
				const $form = jQuery(this);
				const $searchInput = $form.find('input[name="s"]').first();
				$searchInput.attr('autocomplete', 'on');
				if ($searchInput.length && !$searchInput.val()) {
						const savedSearch = readLocalStorageString('gewebConversationsSearch');
						if (savedSearch) {
								$searchInput.val(savedSearch);
						}
				}
		});
}

function dispatchPromptEvent(element, eventName) {
		if (!element) {
			return;
		}

		element.dispatchEvent(new Event(eventName, { bubbles: true }));
}

function getPromptHistoryItemPrompt($item) {
		return decodeBase64Value($item.attr('data-prompt'));
}

function showCellFeedback($cell, message, isError) {
		const $feedback = $cell.find('.geweb-ai-index-feedback');
		if (!$feedback.length) {
return;
}

		$feedback.text(message).css('color', isError ? '#d63638' : '#2271b1').show();
}

function getPostListRow(postId) {
		const normalizedPostId = Number(postId || 0);
		if (!normalizedPostId) {
return jQuery();
}

		return jQuery('#post-' + normalizedPostId).first();
}

function replaceCellHtml($trigger, html, postId) {
		const $row = getPostListRow(postId);
		const $cell = $row.find('.geweb-ai-index-cell').first();
		if (!$cell.length || !html) {
return;
}

		$cell.replaceWith(html);
}

function replaceMarkdownCacheCellHtml($trigger, html, postId) {
		const $row = getPostListRow(postId);
		if (!$row.length || !html) {
return;
}

		const $cell = $row.find('.column-geweb_ai_markdown_cache').first();
		if (!$cell.length) {
return;
}

		$cell.html(html);
}

function replaceReferencedDocumentRow($row, rowHtml) {
		if (!$row?.length || !rowHtml) {
			return jQuery();
		}

		const $replacement = jQuery(rowHtml);
		$row.replaceWith($replacement);
		return $replacement;
}

function setRefreshButtonState($button, disabled, label) {
		if (!$button?.length) {
				return;
		}

		$button.prop('disabled', !!disabled).text(label);
}

function renderMarkdownCacheModalMessage($modal, message) {
		$modal.find('.geweb-ai-markdown-cache-modal-rendered').html('<p>' + escapeHtml(message) + '</p>');
		$modal.find('.geweb-ai-markdown-cache-modal-body').text(message);
		$modal.find('.geweb-ai-markdown-cache-modal-html').text(message);
}

function updateReferencedDocumentActionRow($row, responseData) {
		if (!$row?.length || !responseData) {
				return;
		}

		if (responseData.status_html) {
				$row.find('td.column-status').html(responseData.status_html);
		}
		if (responseData.actions_html) {
				$row.find('td.column-actions').html(responseData.actions_html);
		}
		if (responseData.markdown_cache_html) {
				$row.find('td.column-markdown_cache').html(responseData.markdown_cache_html);
		}
		if (typeof responseData.pdf_analysis_html === 'string') {
				$row.find('td.column-pdf_analysis').html(responseData.pdf_analysis_html);
		}
}

function maybeReplaceReferencedDocumentRowFromResponse($row, $form, responseData) {
		if (!responseData?.row_html || !$row?.length) {
				return false;
		}

		replaceReferencedDocumentRow($row, responseData.row_html);
		if ($form?.length) {
				applyReferencedDocumentsFilters($form);
		}

		return true;
}

function getGeminiStoreDocumentFilterState($browser) {
		const $filter = $browser.find('.geweb-gemini-store-documents-filter');
		const $idFilter = $browser.find('.geweb-gemini-store-documents-id-filter');
		const $slugFilter = $browser.find('.geweb-gemini-store-documents-slug-filter');
		const $typeFilter = $browser.find('.geweb-gemini-store-documents-type-filter');
		const $formatFilter = $browser.find('.geweb-gemini-store-documents-format-filter');
		const sortValue = String($browser.attr('data-sort') || 'name-asc');
		const [sortKey = 'name', rawDirection = 'asc'] = sortValue.split('-');

		return {
				filterValue: $.trim(String($filter.val() || '')).toLowerCase(),
				idFilterValue: $.trim(String($idFilter.val() || '')).toLowerCase(),
				slugFilterValue: $.trim(String($slugFilter.val() || '')).toLowerCase(),
				typeValue: $.trim(String($typeFilter.val() || '')).toLowerCase(),
				formatValue: $.trim(String($formatFilter.val() || '')).toLowerCase(),
				sortKey,
				sortDirection: rawDirection === 'desc' ? 'desc' : 'asc'
		};
}

function sortGeminiStoreDocumentRows(rows, sortKey, sortDirection) {
		const direction = sortDirection === 'desc' ? -1 : 1;
		rows.sort((leftRow, rightRow) => {
				const $leftRow = $(leftRow);
				const $rightRow = $(rightRow);
				const leftValue = String($leftRow.attr('data-' + sortKey) || '');
				const rightValue = String($rightRow.attr('data-' + sortKey) || '');
				return leftValue.localeCompare(rightValue, undefined, { sensitivity: 'base' }) * direction;
		});
}

function updateGeminiStoreSortHeader($header, sortKey, sortDirection) {
		const headerKey = String($header.data('sort-key') || '');
		const headerLabel = String($header.data('sort-label') || $header.text() || '');
		let suffix = ' ↕';
		if (headerKey === sortKey) {
				suffix = sortDirection === 'desc' ? ' ↓' : ' ↑';
		}

		$header.text(headerLabel + suffix);
}

function rowMatchesGeminiStoreFilters($row, filterState) {
		const haystack = [
				String($row.attr('data-name') || ''),
				String($row.attr('data-id') || ''),
				String($row.attr('data-slug') || ''),
				String($row.attr('data-type') || ''),
				String($row.attr('data-url') || '')
		].join(' ');

		return (!filterState.filterValue || haystack.includes(filterState.filterValue))
				&& (!filterState.idFilterValue || csvValueIncludes($row.attr('data-id'), filterState.idFilterValue))
				&& (!filterState.slugFilterValue || String($row.attr('data-slug') || '').includes(filterState.slugFilterValue))
				&& (!filterState.typeValue || String($row.attr('data-type') || '') === filterState.typeValue)
				&& (!filterState.formatValue || String($row.attr('data-format') || '') === filterState.formatValue);
}

function applyGeminiStoreDocumentBrowserState($browser) {
		const $sortHeaders = $browser.find('.geweb-gemini-store-documents-sort-header');
		const $status = $browser.find('.geweb-gemini-store-documents-filter-status');
		const $tbody = $browser.find('tbody');
		const $rows = $tbody.find('tr');
		if (!$rows.length) {
				$status.text('No uploaded items found.');
				return;
		}

		const filterState = getGeminiStoreDocumentFilterState($browser);
		const rows = $rows.get();
		sortGeminiStoreDocumentRows(rows, filterState.sortKey, filterState.sortDirection);
		$tbody.append(rows);

		$sortHeaders.each(function() {
				updateGeminiStoreSortHeader($(this), filterState.sortKey, filterState.sortDirection);
		});

		let visibleCount = 0;
		$rows.each(function() {
				const $row = $(this);
				const visible = rowMatchesGeminiStoreFilters($row, filterState);
				$row.toggle(visible);
				if (visible) {
						visibleCount += 1;
				}
		});

		$status.text(visibleCount + ' item' + (visibleCount === 1 ? '' : 's') + ' shown');
}

function pollPostIndexStatus($trigger, postId, attempt) {
		const nextAttempt = Number(attempt || 0) + 1;
		globalThis.setTimeout(() => {
				jQuery.ajax({
						url: ajaxurl,
						type: 'POST',
						timeout: 30000,
						data: {
								action: 'geweb_get_post_index_status',
								nonce: gewebAisearchAdmin.adminActionNonce,
								post_id: postId
						}
				}).done((response) => {
						if (!response?.success) {
								return;
						}

						replaceCellHtml($trigger, response?.data?.html || '', postId);
						replaceMarkdownCacheCellHtml($trigger, response?.data?.markdown_cache_html || '', postId);

						if (!response?.data?.done && nextAttempt < 120) {
								pollPostIndexStatus($trigger, postId, nextAttempt);
						}
				}).fail(() => {
						if (nextAttempt < 120) {
								pollPostIndexStatus($trigger, postId, nextAttempt);
						}
				});
		}, 3000);
}

function ensureMarkdownCacheModal() {
		let $modal = jQuery('#geweb-ai-markdown-cache-modal');
		if ($modal.length) {
				return $modal;
		}

		$modal = jQuery(
				'<div id="geweb-ai-markdown-cache-modal" style="display:none;position:fixed;inset:0;z-index:100000;background:rgba(17,24,39,0.72);padding:40px 24px;box-sizing:border-box;">' +
						'<div style="max-width:1100px;height:100%;margin:0 auto;background:#fff;border-radius:10px;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 20px 60px rgba(15,23,42,0.35);">' +
								'<div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #dcdcde;">' +
										'<div style="display:flex;align-items:center;gap:12px;min-width:0;">' +
												'<strong class="geweb-ai-markdown-cache-modal-title">Markdown cache</strong>' +
												'<span style="display:inline-flex;gap:6px;">' +
														'<button type="button" class="button button-small geweb-ai-markdown-cache-mode is-active" data-mode="rendered">Rendered</button>' +
														'<button type="button" class="button button-small geweb-ai-markdown-cache-mode" data-mode="raw">Raw</button>' +
														'<button type="button" class="button button-small geweb-ai-markdown-cache-mode" data-mode="html">HTML</button>' +
														'<button type="button" class="button button-small geweb-ai-markdown-cache-mode geweb-ai-markdown-cache-mode-browser" data-mode="browser" style="display:none;">Browser</button>' +
														'<a href="#" target="_blank" rel="noopener noreferrer" class="button button-small geweb-ai-markdown-cache-open-original" style="display:none;">Open in new tab</a>' +
												'</span>' +
										'</div>' +
										'<button type="button" class="button-link geweb-ai-markdown-cache-modal-close" style="font-size:20px;line-height:1;text-decoration:none;">×</button>' +
								'</div>' +
								'<div class="geweb-ai-markdown-cache-modal-rendered" style="margin:0;padding:20px;overflow:auto;background:#f8fafc;flex:1;line-height:1.6;"></div>' +
								'<pre class="geweb-ai-markdown-cache-modal-body" style="display:none;margin:0;padding:20px;overflow:auto;white-space:pre-wrap;word-break:break-word;font:12px/1.5 Consolas, Monaco, monospace;background:#f8fafc;flex:1;"></pre>' +
								'<pre class="geweb-ai-markdown-cache-modal-html" style="display:none;margin:0;padding:20px;overflow:auto;white-space:pre-wrap;word-break:break-word;font:12px/1.5 Consolas, Monaco, monospace;background:#f8fafc;flex:1;"></pre>' +
								'<div class="geweb-ai-markdown-cache-modal-browser" style="display:none;flex:1;min-height:0;background:#f8fafc;">' +
										'<iframe class="geweb-ai-markdown-cache-modal-browser-frame" src="about:blank" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" style="display:block;width:100%;height:100%;border:0;background:#fff;"></iframe>' +
								'</div>' +
						'</div>' +
				'</div>'
		);
		jQuery('body').append($modal);
		return $modal;
}

function setMarkdownCacheModalMode($modal, mode) {
		const normalizedMode = mode === 'raw' || mode === 'html' || mode === 'browser' ? mode : 'rendered';
		$modal.find('.geweb-ai-markdown-cache-mode')
				.removeClass('is-active')
				.css({ background: '', color: '' });
		$modal.find('.geweb-ai-markdown-cache-mode[data-mode="' + normalizedMode + '"]')
				.addClass('is-active')
				.css({ background: '#2271b1', color: '#fff' });
		$modal.find('.geweb-ai-markdown-cache-modal-rendered').toggle(normalizedMode === 'rendered');
		$modal.find('.geweb-ai-markdown-cache-modal-body').toggle(normalizedMode === 'raw');
		$modal.find('.geweb-ai-markdown-cache-modal-html').toggle(normalizedMode === 'html');
		$modal.find('.geweb-ai-markdown-cache-modal-browser').toggle(normalizedMode === 'browser');
}

function ensureModelTestDetailsModal() {
		let $modal = jQuery('#geweb-ai-model-test-details-modal');
		if ($modal.length) {
				return $modal;
		}

		$modal = jQuery(
				'<div id="geweb-ai-model-test-details-modal" style="display:none;position:fixed;inset:0;z-index:100001;background:rgba(17,24,39,0.72);padding:40px 24px;box-sizing:border-box;">' +
						'<div style="max-width:640px;height:min(520px,calc(100vh - 80px));margin:0 auto;background:#fff;border-radius:16px;box-shadow:0 24px 60px rgba(15,23,42,0.35);display:flex;flex-direction:column;overflow:hidden;">' +
								'<div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #e5e7eb;gap:16px;">' +
										'<div style="min-width:0;">' +
												'<strong class="geweb-ai-model-test-details-title">Latest test</strong>' +
												'<div class="geweb-ai-model-test-details-subtitle" style="margin-top:4px;color:#6b7280;font-size:12px;"></div>' +
										'</div>' +
										'<button type="button" class="button-link geweb-ai-model-test-details-close" style="font-size:20px;line-height:1;text-decoration:none;">×</button>' +
								'</div>' +
								'<div style="padding:18px 20px;overflow:auto;display:flex;flex-direction:column;gap:16px;background:#f8fafc;">' +
										'<div>' +
												'<div style="font-size:12px;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.04em;">Question</div>' +
												'<pre class="geweb-ai-model-test-details-prompt" style="margin:8px 0 0;padding:12px 14px;white-space:pre-wrap;word-break:break-word;font:12px/1.5 Consolas, Monaco, monospace;background:#fff;border:1px solid #e5e7eb;border-radius:10px;"></pre>' +
										'</div>' +
										'<div>' +
												'<div class="geweb-ai-model-test-details-response-label" style="font-size:12px;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.04em;">Answer</div>' +
												'<pre class="geweb-ai-model-test-details-response" style="margin:8px 0 0;padding:12px 14px;white-space:pre-wrap;word-break:break-word;font:12px/1.5 Consolas, Monaco, monospace;background:#fff;border:1px solid #e5e7eb;border-radius:10px;"></pre>' +
										'</div>' +
								'</div>' +
						'</div>' +
				'</div>'
		);

		jQuery('body').append($modal);
		return $modal;
}

function clearStatusAfterFade($status) {
		$status.fadeOut(200, () => {
				$status.text('').css('display', 'none');
		});
}

function csvValueIncludes(csvValue, needle) {
		return String(csvValue || '').split(',').map((part) => {
				return jQuery.trim(String(part || ''));
		}).includes(needle);
}

function applyPendingReferencedDocumentTargets() {
		// This hook used to apply staged UI state for referenced documents.
		// Keep it as a no-op so admin initialization does not fail when the
		// referenced documents and Gemini stores panels are loaded.
}

function syncModelSelectTint($select) {
		if (!$select?.length) {
return;
}

		const $selectedOption = $select.find('option:selected');
		const status = String($selectedOption.attr('data-model-status') || '');
		$select.css('color', status === 'failed' ? '#b32d2e' : '');
}

jQuery(document).ready(($) => {
		const $settingsForm = $('form[action*="admin-post.php"]').has('input[name="action"][value="geweb_save"]').first();
		const $groupRevisionField = $('#geweb_ai_search_group_revision');
		const $adminViewCacheState = $('#geweb-ai-admin-cache-state');
		let initialFormState = $settingsForm.length ? $settingsForm.serialize() : '';
		let suppressBeforeUnloadWarning = false;
		let promptDiffRequestToken = 0;
		const renderedAdminViewRevisions = {
				prompts: $.trim(String($adminViewCacheState.attr('data-prompts-revision') || '')),
				files: $.trim(String($adminViewCacheState.attr('data-files-revision') || '')),
				chats: $.trim(String($adminViewCacheState.attr('data-chats-revision') || ''))
		};

		function getGroupRevision() {
				return $.trim(String($groupRevisionField.val() || gewebAisearchAdmin.groupDataRevision || ''));
		}

		function setGroupRevision(revision) {
				const normalized = $.trim(String(revision || ''));
				if (!normalized) {
return;
}

				gewebAisearchAdmin.groupDataRevision = normalized;
				if ($groupRevisionField.length) {
						$groupRevisionField.val(normalized);
				}
		}

		function syncGroupRevisionFromPayload(payload) {
				if (!payload) {
return;
}
				setGroupRevision(payload.group_revision || payload.current_revision || '');
		}

		function buildGroupRevisionData(extraData) {
				return {
						...extraData,
						group_revision: getGroupRevision()
				};
		}

		function getRenderedAdminViewRevision(tab) {
				return $.trim(String(renderedAdminViewRevisions[tab] || ''));
		}

		function setRenderedAdminViewRevision(tab, revision) {
				const normalized = $.trim(String(revision || ''));
				if (!normalized || !Object.hasOwn(renderedAdminViewRevisions, tab)) {
return;
}

				renderedAdminViewRevisions[tab] = normalized;
				if ($adminViewCacheState.length) {
						$adminViewCacheState.attr('data-' + tab + '-revision', normalized);
				}
		}

		function setAdminViewStale(tab, stale) {
				$('.geweb-admin-view-stale-notice[data-cache-tab="' + String(tab || '') + '"]').toggle(!!stale);
		}

		function syncAdminViewCacheState(payload, refreshedTabs) {
				const cacheState = payload?.cache_state;
				if (!cacheState || typeof cacheState !== 'object') {
return;
}

				const refreshed = Array.isArray(refreshedTabs) ? refreshedTabs : [];
				['prompts', 'files', 'chats'].forEach((tab) => {
						const serverRevision = $.trim(String(cacheState[tab] || ''));
						if (!serverRevision) {
								return;
						}

						if (refreshed.includes(tab)) {
								setRenderedAdminViewRevision(tab, serverRevision);
								setAdminViewStale(tab, false);
								return;
						}

						const renderedRevision = getRenderedAdminViewRevision(tab);
						if (!renderedRevision) {
								setRenderedAdminViewRevision(tab, serverRevision);
								return;
						}

						setAdminViewStale(tab, renderedRevision !== serverRevision);
				});
		}

		function getAjaxErrorMessage(xhr, fallbackMessage) {
				syncGroupRevisionFromPayload(xhr?.responseJSON?.data);
				return xhr?.responseJSON?.data?.message || fallbackMessage;
		}

		function markFormSaved() {
				if (!$settingsForm.length) {
return;
}
				initialFormState = $settingsForm.serialize();
				updateSaveButtonState();
		}

		function hasUnsavedChanges() {
				return $settingsForm.length && initialFormState !== $settingsForm.serialize();
		}

		function updateSaveButtonState() {
				const $saveButton = $('#geweb-save-settings');
				if (!$saveButton.length) {
return;
}
				$saveButton.prop('disabled', !hasUnsavedChanges());
		}

		function ensureAdminLoadingOverlay() {
				let $overlay = $('#geweb-ai-admin-loading-overlay');
				if ($overlay.length) {
						return $overlay;
				}

				$overlay = $(
						'<div id="geweb-ai-admin-loading-overlay" aria-hidden="true" style="display:none;position:fixed;inset:0;z-index:100000;background:rgba(15,23,42,0.18);backdrop-filter:blur(1px);">' +
								'<div style="position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);width:min(360px,calc(100vw - 40px));padding:20px 20px 18px;border-radius:16px;background:#ffffff;box-shadow:0 18px 50px rgba(15,23,42,0.22);border:1px solid rgba(148,163,184,0.28);">' +
										'<div style="display:flex;align-items:center;justify-content:center;margin-bottom:14px;color:#0f172a;font-weight:600;text-align:center;">' +
												'<span class="geweb-ai-admin-loading-title">Loading…</span>' +
										'</div>' +
										'<div class="geweb-ai-admin-loading-subtitle" style="display:none;margin:-6px 0 12px;color:#64748b;font-size:12px;text-align:center;"></div>' +
										'<div style="height:10px;border-radius:999px;background:rgba(226,232,240,0.95);overflow:hidden;">' +
												'<div class="geweb-ai-admin-loading-bar" style="width:38%;height:100%;border-radius:999px;background:linear-gradient(90deg,#2563eb 0%,#60a5fa 100%);animation:geweb-ai-admin-loading-bar 1.35s ease-in-out infinite;"></div>' +
										'</div>' +
										'<div class="geweb-ai-admin-loading-percent" style="display:none;margin-top:10px;color:#475569;font-size:12px;text-align:center;"></div>' +
								'</div>' +
						'</div>'
				);

				if (!document.getElementById('geweb-ai-admin-loading-overlay-style')) {
						$('<style id="geweb-ai-admin-loading-overlay-style">@keyframes geweb-ai-admin-loading-bar{0%{transform:translateX(-85%);width:30%;}50%{transform:translateX(70%);width:52%;}100%{transform:translateX(210%);width:30%;}}</style>').appendTo('head');
				}

				$('body').append($overlay);
				return $overlay;
		}

		function showAdminLoadingOverlay(title, message) {
				const $overlay = ensureAdminLoadingOverlay();
				$overlay.find('.geweb-ai-admin-loading-title').text(title || 'Loading…');
				$overlay.find('.geweb-ai-admin-loading-subtitle').hide().text('');
				$overlay.find('.geweb-ai-admin-loading-percent').hide().text('');
				$overlay.find('.geweb-ai-admin-loading-bar').css({
						width: '38%',
						animation: 'geweb-ai-admin-loading-bar 1.35s ease-in-out infinite'
				});
				$overlay.show().attr('aria-hidden', 'false');
		}

		function updateAdminLoadingOverlayProgress(title, percent, subtitle) {
				const $overlay = ensureAdminLoadingOverlay();
				const normalizedPercent = Number(percent);
				$overlay.find('.geweb-ai-admin-loading-title').text(title || 'Loading…');
				if (subtitle) {
						$overlay.find('.geweb-ai-admin-loading-subtitle').text(String(subtitle)).show();
				} else {
						$overlay.find('.geweb-ai-admin-loading-subtitle').hide().text('');
				}
				if (Number.isFinite(normalizedPercent)) {
						const clampedPercent = Math.max(0, Math.min(100, Math.round(normalizedPercent)));
						$overlay.find('.geweb-ai-admin-loading-bar').css({
								width: clampedPercent + '%',
								animation: 'none'
						});
						$overlay.find('.geweb-ai-admin-loading-percent').text(clampedPercent + '%').show();
				} else {
						$overlay.find('.geweb-ai-admin-loading-bar').css({
								width: '38%',
								animation: 'geweb-ai-admin-loading-bar 1.35s ease-in-out infinite'
						});
						$overlay.find('.geweb-ai-admin-loading-percent').hide().text('');
				}
				$overlay.show().attr('aria-hidden', 'false');
		}

		function hideAdminLoadingOverlay() {
				$('#geweb-ai-admin-loading-overlay').hide().attr('aria-hidden', 'true');
		}

		$(document).on('click', '#adminmenu a[href*="page=geweb-ai-search"]', function() {
				const href = String($(this).attr('href') || '');
				const titleMap = {
						documents: 'Loading Documents…',
						stores: 'Loading Gemini Stores…',
						conversations: 'Loading Chats…',
						prompts: 'Loading Prompts…',
						general: 'Loading Settings…'
				};
				const tab = getAdminMenuTargetTab(href);
				const title = titleMap[tab] || titleMap.general;

				showAdminLoadingOverlay(title);
		});

		function getPromptTargetElements(scope, model) {
				if (scope === 'model' && model) {
						const escapedModel = escapeSelectorAttributeValue(model);
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
				if (!promptElement) {
return;
}

				promptElement.value = value;
				$prompt.val(value);
				dispatchPromptEvent(promptElement, 'input');
				dispatchPromptEvent(promptElement, 'change');
				promptElement.focus();
		}

		function setPromptNameValue(value, scope, model) {
				const target = getPromptTargetElements(scope, model);
				if (!target.$name.length) {
return;
}
				target.$name.val(value || '');
		}

		function setPromptModeValue(mode, model) {
				if (!model) {
return;
}
				const normalized = mode === 'override' ? 'override' : 'append';
				const escapedModel = escapeSelectorAttributeValue(model);
				$('[data-geweb-model-prompt-mode="' + escapedModel + '"][value="' + normalized + '"]').prop('checked', true);
		}

		function updatePromptHistoryPreview(selectedPrompt, scope, model) {
				const $diff = $('#geweb-ai-prompt-history-diff');
				const target = getPromptTargetElements(scope, model);
				const $currentPrompt = target.$prompt;

				if (!$diff.length || !$currentPrompt.length) {
return;
}

				if (!selectedPrompt) {
						$diff.html('Select a saved prompt version to compare it with the current prompt field.');
						return;
				}

				const requestToken = ++promptDiffRequestToken;
				$diff.html('<p>Loading diff...</p>');

				$.ajax({
						url: getAdminAjaxUrl(),
						type: 'POST',
						dataType: 'json',
						data: {
								action: 'geweb_render_prompt_diff',
								nonce: gewebAisearchAdmin.adminActionNonce,
								current_prompt: $currentPrompt.val(),
								selected_prompt: selectedPrompt
						}
				}).done((response) => {
						if (requestToken !== promptDiffRequestToken) {
								return;
						}

						if (!response?.success || typeof response?.data?.html !== 'string') {
								$diff.html('<p>Could not render prompt diff.</p>');
								return;
						}

						$diff.html(response.data.html);
				}).fail(() => {
						if (requestToken !== promptDiffRequestToken) {
								return;
						}

						$diff.html('<p>Could not render prompt diff.</p>');
				});
		}

		function selectPromptHistoryItem($item) {
				if (!$item?.length) {
return;
}
				$promptHistoryList.find('.geweb-ai-prompt-history-item').removeClass('selected').css('border-color', '#dcdcde');
				$item.addClass('selected').css('border-color', '#2271b1');
				updatePromptHistoryPreview(
						getPromptHistoryItemPrompt($item),
						String($item.data('scope') || 'global'),
						String($item.data('model') || '')
				);
		}

			function refreshModelSelectorInBackground() {
				const $select = $('#geweb_ai_search_model');
				const $status = $('#geweb-ai-model-refresh-status');
				if (!$select.length) {
return;
}

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
				}).done((response) => {
						if (!response?.success || !$.isArray(response?.data?.models) || !response.data.models.length) {
								if ($status.length) {
										$status.text('Could not refresh models. Showing cached or bundled list.').css('color', '#996800');
								}
								return;
						}

						const selectedValue = String($select.val() || '');
						const remoteSelected = String(response.data.selected_model || selectedValue || '');
						$select.empty();
						populateModelSelectOptions($select, response.data, remoteSelected);

						syncModelSelectTint($select);

							if ($status.length) {
									const statusMessage = response?.data?.used_cached_models
											? 'Model list loaded from cache.'
											: 'Model list refreshed from Gemini.';
									$status.text(statusMessage).css('color', '#46b450');
									globalThis.setTimeout(clearStatusAfterFade, 2000, $status);
							}
					}).fail(() => {
						if ($status.length) {
								$status.text('Could not refresh models. Showing cached or bundled list.').css('color', '#996800');
						}
				});
		}

		function updateModelOptionStatuses($select, modelStatuses) {
				if (!$select?.length) {
return;
}

				$select.find('option').each(function() {
						const $option = $(this);
						const value = String($option.val() || '');
						const statusEntry = modelStatuses?.[value];
						const isFailedModel = statusEntry && String(statusEntry.status || '') === 'failed';
						$option.attr('data-model-status', isFailedModel ? 'failed' : 'ok');
						$option.css('color', isFailedModel ? '#b32d2e' : '');
				});

				syncModelSelectTint($select);
		}

		function refreshModelDiagnosticsPanel(html) {
				const markup = String(html || '');
				if (!markup) {
return;
}

				const $existing = $('#geweb-model-diagnostics');
				if ($existing.length) {
						$existing.replaceWith(markup);
						prepareModelDiagnosticsTableHeaders();
				}
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
				if (!model) {
return;
}

				const $target = $('[data-geweb-model-prompt-details="' + escapeSelectorAttributeValue(model) + '"]').first();
				if (!$target.length) {
return;
}

				$target.prop('open', true);
				const top = Math.max(0, $target.offset().top - 80);
				$('html, body').animate({ scrollTop: top }, 180);
		});

		$('#geweb-refresh-models-button').on('click', function(event) {
				event.preventDefault();
				const $button = $(this);
				const $status = $('#geweb-ai-model-refresh-status');
				const $select = $('#geweb_ai_search_model');
				if (!$button.length || !$select.length || $button.prop('disabled')) {
return;
}

				$button.prop('disabled', true).text('Refreshing...');
				if ($status.length) {
						$status.text('Refreshing available Gemini models...').css({ display: 'block', color: '#646970' });
				}

				$.ajax({
						url: getAdminAjaxUrl(),
						type: 'POST',
						dataType: 'json',
						data: {
								action: 'geweb_refresh_models',
								nonce: gewebAisearchAdmin.adminActionNonce,
								force_refresh: 1
						}
				}).done((response) => {
						if (!response?.success || !$.isArray(response?.data?.models) || !response.data.models.length) {
								if ($status.length) {
										$status.text('Could not refresh models. Showing cached or bundled list.').css('color', '#996800');
								}
								return;
						}

						const selectedValue = String($select.val() || '');
						const remoteSelected = String(response.data.selected_model || selectedValue || '');
						$select.empty();
						populateModelSelectOptions($select, response.data, remoteSelected);

						syncModelSelectTint($select);
						if ($status.length) {
								$status.text('Model list refreshed from Gemini.').css('color', '#46b450');
						}
				}).fail(() => {
						if ($status.length) {
								$status.text('Could not refresh models. Showing cached or bundled list.').css('color', '#996800');
						}
				}).always(() => {
						$button.prop('disabled', false).text('Refresh models');
				});
		});

		$('#geweb-test-selected-model').on('click', function(event) {
				event.preventDefault();
				const $button = $(this);
				const $select = $('#geweb_ai_search_model');
				const $status = $('#geweb-ai-model-refresh-status');
				const model = String($select.val() || '');
				if (!$button.length || !$select.length || !model || $button.prop('disabled')) {
return;
}

				$button.prop('disabled', true).text('Testing...');
				if ($status.length) {
						$status.text('Testing selected model...').css({ display: 'block', color: '#646970' });
				}

				$.ajax({
						url: getAdminAjaxUrl(),
						type: 'POST',
						dataType: 'json',
						data: {
								action: 'geweb_test_model',
								nonce: gewebAisearchAdmin.adminActionNonce,
								model: model
						}
				}).done((response) => {
						updateModelOptionStatuses($select, response?.data?.model_statuses || {});
						refreshModelDiagnosticsPanel(response?.data?.model_diagnostics_html || '');
						if ($status.length) {
								$status.text('').hide();
						}
				}).fail((xhr) => {
						updateModelOptionStatuses($select, xhr?.responseJSON?.data?.model_statuses || {});
						refreshModelDiagnosticsPanel(xhr?.responseJSON?.data?.model_diagnostics_html || '');
						if ($status.length) {
								$status.text(getAjaxErrorMessage(xhr, 'Model test failed.')).css('color', '#d63638');
						}
				}).always(() => {
						$button.prop('disabled', false).text('Test selected model');
				});
		});

		$('.nav-tab-wrapper').on('click', '[data-geweb-tab]', function(e) {
				e.preventDefault();
				const tab = String($(this).attr('data-geweb-tab') || '');
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
				const value = getPromptHistoryItemPrompt($item);
				if (!value) {
return;
}
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
				if (e.key !== 'Enter') {
return;
}
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
						data: buildGroupRevisionData({
								action: 'geweb_delete_prompt_history_item',
								nonce: gewebAisearchAdmin.adminActionNonce,
								entry_id: entryId
						})
				}).done((response) => {
						syncGroupRevisionFromPayload(response?.data);
						if (!response?.success) {
								const message = response?.data?.message || 'Could not delete prompt version.';
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
				}).fail((xhr) => {
						alert(getAjaxErrorMessage(xhr, 'Could not delete prompt version due to a network error.'));
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
				}).done((response) => {
						if (!response?.success || !response?.data) {
								const message = response?.data?.message || 'Could not rename the conversation.';
								$feedback.text(message).css('color', '#d63638').show();
								return;
						}

						$cell.data('current-summary', response.data.summary);
						$cell.find('.geweb-conversation-summary-label').text(response.data.summary).show();
						$cell.find('.geweb-edit-conversation-form').hide();
						$cell.find('.geweb-edit-conversation-trigger').show();
						$feedback.text(response.data.message || 'Conversation renamed.').css('color', '#46b450').show();
				}).fail(() => {
						$feedback.text('Could not rename the conversation.').css('color', '#d63638').show();
				}).always(() => {
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
				}).done((response) => {
						if (!response?.success) {
								const message = response?.data?.message || 'Could not delete the conversation.';
								$feedback.text(message).css('color', '#d63638').show();
								return;
						}

						globalThis.location.reload();
				}).fail(() => {
						$feedback.text('Could not delete the conversation.').css('color', '#d63638').show();
				}).always(() => {
						$button.prop('disabled', false);
				});
		});

		$('#geweb-ai-clear-history').on('click', function() {
				const $button = $(this);
				const $diff = $('#geweb-ai-prompt-history-diff');

				if (!$button.length) {
return;
}

				$button.prop('disabled', true).text('Clearing...');

				$.ajax({
						url: getAdminAjaxUrl(),
						type: 'POST',
						dataType: 'json',
						data: buildGroupRevisionData({
								action: 'geweb_clear_prompt_history',
								nonce: gewebAisearchAdmin.adminActionNonce
						})
				}).done((response) => {
						syncGroupRevisionFromPayload(response?.data);
						if (!response?.success) {
								const message = response?.data?.message || 'Could not clear prompt history.';
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
				}).fail((xhr) => {
						if ($diff.length) {
								$diff.text(getAjaxErrorMessage(xhr, 'Could not clear prompt history.'));
						} else {
								alert(getAjaxErrorMessage(xhr, 'Could not clear prompt history.'));
						}
						$button.prop('disabled', false).text('Clear All History');
				});
		});

		$('#geweb_ai_search_custom_prompt, [data-geweb-model-prompt]').on('input', () => {
				const $selectedItem = $promptHistoryList.find('.geweb-ai-prompt-history-item.selected');
				const selectedPrompt = $selectedItem.length ? getPromptHistoryItemPrompt($selectedItem) : null;
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
						const promptValue = normalizePromptText(getPromptHistoryItemPrompt($item));
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
		const activeTab = String($('.nav-tab-wrapper [data-geweb-tab].nav-tab-active').attr('data-geweb-tab') || '');
		if (['general', 'prompts'].includes(activeTab)) {
				refreshModelSelectorInBackground();
		}
		syncModelSelectTint($('#geweb_ai_search_model'));
		$('#geweb_ai_search_model').on('change', function() {
				syncModelSelectTint($(this));
		});

		$(globalThis).on('beforeunload', () => {
				if (suppressBeforeUnloadWarning || !hasUnsavedChanges()) {
return;
}

				return 'You have unsaved changes. Are you sure you want to leave this page?';
		});

		$(document).on('mousedown pointerdown click', '.geweb-referenced-documents-table-form a, .geweb-gemini-stores-table-form a, .geweb-referenced-documents-table-form .button, .geweb-gemini-stores-table-form .button, .geweb-referenced-documents-table-form .button-link, .geweb-gemini-stores-table-form .button-link, #geweb-refresh-referenced-documents, #geweb-refresh-gemini-stores', () => {
				suppressBeforeUnloadWarning = true;
		});

		$(document).on('focus mousedown pointerdown change', '.geweb-referenced-documents-table-form select, .geweb-gemini-stores-table-form select', () => {
				suppressBeforeUnloadWarning = true;
		});

$(document).on('submit', '.geweb-gemini-stores-table-form', () => {
			suppressBeforeUnloadWarning = true;
			showAdminLoadingOverlay('Loading Gemini Stores…', 'Refreshing the selected Gemini store view.');
		});

		$(document).on('submit', '.geweb-referenced-documents-table-form', function(event) {
			event.preventDefault();
			applyReferencedDocumentsFilters(jQuery(this));
		});

		$(document).on('click', '.geweb-referenced-documents-table-form .tablenav-pages a, .geweb-referenced-documents-table-form .first-page, .geweb-referenced-documents-table-form .prev-page, .geweb-referenced-documents-table-form .next-page, .geweb-referenced-documents-table-form .last-page', (event) => {
			event.preventDefault();
		});

		$(document).on('change', '.geweb-referenced-documents-table-form #geweb_ai_referenced_doc_status, .geweb-referenced-documents-table-form #geweb_ai_referenced_doc_type, .geweb-referenced-documents-table-form #geweb_ai_referenced_doc_referenced_in', function() {
			applyReferencedDocumentsFilters(jQuery(this).closest('.geweb-referenced-documents-table-form'));
		});

		$(document).on('click', '.geweb-referenced-documents-search-toggle', function() {
			const $form = jQuery(this).closest('.geweb-referenced-documents-table-form');
			if (!$form.length) {
				return;
			}

			const isExpanded = String($form.attr('data-search-expanded') || '0') === '1';
			updateReferencedDocumentsSearchBoxState($form, !isExpanded);
			if (!isExpanded) {
				$form.find('input[name="s"]').first().trigger('focus');
			}
		});

		$(document).on('input', '.geweb-referenced-documents-table-form input[name="s"]', function() {
			const $form = jQuery(this).closest('.geweb-referenced-documents-table-form');
			if (!$form.length) {
				return;
			}

			updateReferencedDocumentsSearchBoxState($form, true);

			globalThis.clearTimeout(referencedDocumentsFilterTimer);
			referencedDocumentsFilterTimer = globalThis.setTimeout(() => {
				writeLocalStorageString('gewebReferencedDocumentsSearch', this.value);
				applyReferencedDocumentsFilters($form);
			}, 350);
		});

		$(document).on('keydown', '.geweb-referenced-documents-table-form input[name="s"]', function(event) {
			if (event.key !== 'Enter') {
				return;
			}

			event.preventDefault();
			const $form = jQuery(this).closest('.geweb-referenced-documents-table-form');
			if (!$form.length) {
				return;
			}

			globalThis.clearTimeout(referencedDocumentsFilterTimer);
			applyReferencedDocumentsFilters($form);
		});

		$(document).on('submit', '.geweb-conversations-table-form', () => {
				suppressBeforeUnloadWarning = true;
				showAdminLoadingOverlay('Loading Chats…');
		});

		$(document).on('input', '.geweb-conversations-table-form input[name="s"]', function() {
				const form = this.form;
				if (!form) {
						return;
				}

				globalThis.clearTimeout(conversationsFilterTimer);
				conversationsFilterTimer = globalThis.setTimeout(() => {
						writeLocalStorageString('gewebConversationsSearch', this.value);

						suppressBeforeUnloadWarning = true;
						showAdminLoadingOverlay('Loading Chats…');
						submitFormWithFallback(form);
				}, 350);
		});

		$(document).on('keydown', '.geweb-conversations-table-form input[name="s"]', function(event) {
				if (event.key !== 'Enter') {
						return;
				}

				const form = this.form;
				if (!form) {
						return;
				}

				event.preventDefault();
				globalThis.clearTimeout(conversationsFilterTimer);
				writeLocalStorageString('gewebConversationsSearch', this.value);
				suppressBeforeUnloadWarning = true;
				showAdminLoadingOverlay('Loading Chats…');
				submitFormWithFallback(form);
		});

		$('#geweb_ai_search_preserve_data_on_uninstall').on('change', function() {
				if (!this.checked) {
return;
}

				alert('Plugin data can be preserved on uninstall, but the stored API key and encryption key will always be removed.');
		});

		$('#geweb-toggle-api-key-visibility').on('click', function() {
				const $button = $(this);
				const $field = $('#geweb_api_key');
				if (!$field.length) {
return;
}

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
				if (!$field.length || !$button.length) {
return;
}

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

		$('#geweb_api_key').on('input change', () => {
				updateApiKeyVisibilityToggle();
		});

		updateApiKeyVisibilityToggle();
		globalThis.setTimeout(updateApiKeyVisibilityToggle, 150);
		globalThis.setTimeout(updateApiKeyVisibilityToggle, 600);

		if ($settingsForm.length) {
				$settingsForm.on('input change', () => {
						updateSaveButtonState();
				});
				$('#geweb_ai_search_frontend_ai_interface').on('change', () => {
						updateSaveButtonState();
				});
				$settingsForm.on('submit', () => {
						setGroupRevision(getGroupRevision());
						markFormSaved();
				});
		}
		updateSaveButtonState();

		let isProcessing = false;
		let totalSuccess = 0;
		let totalErrors = 0;
		let isBuildingMarkdownCache = false;
		let totalCacheSuccess = 0;
		let totalCacheErrors = 0;

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
				if (isProcessing) {
return;
}

				isProcessing = true;
				totalSuccess = 0;
				totalErrors = 0;

				const $btn = $(this);
				const $status = $('#geweb-generate-status');

				$btn.prop('disabled', true);
				$status.html('<p>Starting...</p>');

				processPage(1);
		});

		function processMarkdownCachePage(page) {
				$.ajax({
						url: ajaxurl,
						type: 'POST',
						timeout: 180000,
						data: {
								action: 'geweb_build_markdown_cache',
								nonce: gewebAisearchAdmin.generateLibraryNonce,
								page: page
						},
						success: function(response) {
								if (response.success) {
										const data = response.data;
										totalCacheSuccess += Number(data.success || 0);
										totalCacheErrors += Number(data.errors || 0);

										const percentage = data.total > 0 ? Math.round((data.processed / data.total) * 100) : 100;
										const statusText = 'Processing: ' + data.processed + '/' + data.total + ' (' + percentage + '%)';

										$('#geweb-build-markdown-cache-status').html('<p>' + statusText + '</p>');

										if (data.has_more) {
												processMarkdownCachePage(data.next_page);
										} else {
												let finalMessage = 'Completed! ' + totalCacheSuccess + ' MD cache item(s) built';
												if (totalCacheErrors > 0) {
														finalMessage += ', ' + totalCacheErrors + ' error(s)';
												}
												$('#geweb-build-markdown-cache-status').html('<p style="color: green;">' + finalMessage + '</p>');
												$('#geweb-build-markdown-cache').prop('disabled', false);
												isBuildingMarkdownCache = false;
										}
								} else {
										$('#geweb-build-markdown-cache-status').html('<p style="color: red;">Error: ' + response.data.message + '</p>');
										$('#geweb-build-markdown-cache').prop('disabled', false);
										isBuildingMarkdownCache = false;
								}
						},
						error: function() {
								$('#geweb-build-markdown-cache-status').html('<p style="color: red;">Network error</p>');
								$('#geweb-build-markdown-cache').prop('disabled', false);
								isBuildingMarkdownCache = false;
						}
				});
		}

		$('#geweb-build-markdown-cache').on('click', function() {
				if (isBuildingMarkdownCache) {
return;
}

				isBuildingMarkdownCache = true;
				totalCacheSuccess = 0;
				totalCacheErrors = 0;

				const $btn = $(this);
				const $status = $('#geweb-build-markdown-cache-status');

				$btn.prop('disabled', true);
				$status.html('<p>Starting...</p>');

				processMarkdownCachePage(1);
		});

		$(document).on('click', '.geweb-ai-markdown-cache-view', function(event) {
				event.preventDefault();
				const $link = $(this);
				const postId = Number($link.data('post-id') || 0);
				const cacheKind = String($link.data('cache-kind') || 'post');
				const fileHash = String($link.data('file-hash') || '').trim();
				if (cacheKind === 'post' && !postId) {
return;
}
				if (cacheKind === 'document' && !fileHash) {
return;
}

				const $modal = ensureMarkdownCacheModal();
				$modal.find('.geweb-ai-markdown-cache-modal-title').text('Markdown cache');
				$modal.find('.geweb-ai-markdown-cache-open-original').hide().attr('href', '#');
				$modal.find('.geweb-ai-markdown-cache-mode-browser').hide();
				$modal.find('.geweb-ai-markdown-cache-modal-rendered').html('<p>Loading...</p>');
				$modal.find('.geweb-ai-markdown-cache-modal-body').text('Loading...');
				$modal.find('.geweb-ai-markdown-cache-modal-html').text('Loading...');
				$modal.find('.geweb-ai-markdown-cache-modal-browser-frame').attr('src', 'about:blank');
				setMarkdownCacheModalMode($modal, 'rendered');
				$modal.show();

				$.ajax({
						url: getAdminAjaxUrl(),
						type: 'POST',
						dataType: 'json',
						data: {
								action: cacheKind === 'document' ? 'geweb_get_referenced_document_markdown_cache' : 'geweb_get_markdown_cache',
								nonce: gewebAisearchAdmin.adminActionNonce,
								post_id: postId,
								file_hash: fileHash
						}
				}).done((response) => {
						if (!response?.success) {
									const message = response?.data?.message || 'Could not load Markdown cache.';
									renderMarkdownCacheModalMessage($modal, message);
									return;
							}

						const title = String(response?.data?.title || '').trim();
						const filename = String(response?.data?.filename || '').trim();
						const originalUrl = String(response?.data?.url || '').trim();
						const markdown = String(response?.data?.markdown || '');
						const renderedHtml = String(response?.data?.rendered_html || '');
						let modalTitle = 'Markdown cache';
						if (filename && title) {
								modalTitle = 'Markdown cache: ' + filename + ' (' + title + ')';
						} else if (filename) {
								modalTitle = 'Markdown cache: ' + filename;
						} else if (title) {
								modalTitle = 'Markdown cache: ' + title;
						}
						$modal.find('.geweb-ai-markdown-cache-modal-title').text(modalTitle);
						if (/^https?:\/\//i.test(originalUrl)) {
								$modal.find('.geweb-ai-markdown-cache-open-original').attr('href', originalUrl).show();
								$modal.find('.geweb-ai-markdown-cache-mode-browser').show();
								$modal.find('.geweb-ai-markdown-cache-modal-browser-frame').attr('src', originalUrl);
						}
						$modal.find('.geweb-ai-markdown-cache-modal-rendered').html(renderMarkdownPreview(markdown));
						$modal.find('.geweb-ai-markdown-cache-modal-body').text(markdown);
						$modal.find('.geweb-ai-markdown-cache-modal-html').text(renderedHtml);
				}).fail((xhr) => {
							const message = xhr?.responseJSON?.data?.message || 'Could not load Markdown cache.';
							renderMarkdownCacheModalMessage($modal, message);
					});
		});

		$(document).on('click', '.geweb-ai-markdown-cache-mode', function(event) {
				event.preventDefault();
				const $button = $(this);
				const $modal = $button.closest('#geweb-ai-markdown-cache-modal');
				if (!$modal.length) {
return;
}

				setMarkdownCacheModalMode($modal, String($button.data('mode') || 'rendered'));
		});

		$(document).on('click', '#geweb-ai-markdown-cache-modal .geweb-ai-markdown-cache-modal-rendered a[href^="#"]', function(event) {
				const $link = $(this);
				const href = String($link.attr('href') || '');
				if (!href || href === '#') {
						return;
				}

				const $modal = $link.closest('#geweb-ai-markdown-cache-modal');
				const $container = $modal.find('.geweb-ai-markdown-cache-modal-rendered').first();
				const $target = $container.find(href).first();
				if (!$container.length || !$target.length) {
						return;
				}

				event.preventDefault();
				const scrollTop = $container.scrollTop() + $target.position().top - 16;
				$container.animate({ scrollTop: Math.max(0, scrollTop) }, 180);
		});

		$(document).on('click', '.geweb-ai-markdown-cache-modal-close', (event) => {
				event.preventDefault();
				$('#geweb-ai-markdown-cache-modal').hide();
		});

		$(document).on('click', '#geweb-ai-markdown-cache-modal', function(event) {
				if (event.target === this) {
						$(this).hide();
				}
		});

		$(document).on('click', '.geweb-model-test-details-trigger', function(event) {
				event.preventDefault();
				const $button = $(this);
				const $modal = ensureModelTestDetailsModal();
				const model = String($button.data('model') || '').trim();
				const status = String($button.data('status') || '').trim();
				const timestamp = String($button.data('timestamp') || '').trim();
				const prompt = String($button.data('prompt') || '').trim();
				const response = String($button.data('response') || '').trim();
				const message = String($button.data('message') || '').trim();
				const subtitleParts = [];

				if (model) {
subtitleParts.push(model);
}
				if (status) {
subtitleParts.push(status.toUpperCase());
}
				if (timestamp) {
subtitleParts.push(timestamp);
}

				$modal.find('.geweb-ai-model-test-details-title').text(model ? 'Latest test: ' + model : 'Latest test');
				$modal.find('.geweb-ai-model-test-details-subtitle').text(subtitleParts.join(' · '));
				$modal.find('.geweb-ai-model-test-details-prompt').text(prompt ? '"' + prompt + '"' : 'No stored test question.');

				if (status === 'failed' || status === 'timeout') {
						$modal.find('.geweb-ai-model-test-details-response-label').text('Error Message');
						$modal.find('.geweb-ai-model-test-details-response').text(message || 'No stored error message.');
				} else {
						$modal.find('.geweb-ai-model-test-details-response-label').text('Answer');
						$modal.find('.geweb-ai-model-test-details-response').text(response ? '"' + response + '"' : 'No stored test answer.');
				}

				$modal.show();
		});

		$(document).on('click', '.geweb-ai-model-test-details-close', (event) => {
				event.preventDefault();
				$('#geweb-ai-model-test-details-modal').hide();
		});

		$(document).on('click', '#geweb-ai-model-test-details-modal', function(event) {
				if (event.target === this) {
						$(this).hide();
				}
		});

		$(document).on('click', '.geweb-ai-reupload', function() {
				const $button = $(this);
				const $cell = $button.closest('.geweb-ai-index-cell');
				const postId = $cell.data('post-id');
				if (!postId) {
return;
}

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
										replaceCellHtml($button, response.data.html, postId);
										replaceMarkdownCacheCellHtml($button, response?.data?.markdown_cache_html || '', postId);
										pollPostIndexStatus($button, postId, 0);
										return;
								}

									replaceCellHtml($button, response?.data?.html || '', postId);
									replaceMarkdownCacheCellHtml($button, response?.data?.markdown_cache_html || '', postId);
									if ($cell.length) {
											showCellFeedback($cell, response?.data?.message || 'Upload failed.', true);
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
				if (!postId) {
return;
}

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
										replaceCellHtml($checkbox, response.data.html, postId);
										replaceMarkdownCacheCellHtml($checkbox, response?.data?.markdown_cache_html || '', postId);
										return;
								}

									replaceCellHtml($checkbox, response?.data?.html || '', postId);
									replaceMarkdownCacheCellHtml($checkbox, response?.data?.markdown_cache_html || '', postId);
									if ($cell.length) {
											showCellFeedback($cell, response?.data?.message || 'Could not update exclusion.', true);
									}
						},
						error: function() {
								$checkbox.prop('disabled', false).prop('checked', !exclude);
								showCellFeedback($cell, 'Could not update exclusion.', true);
						}
				});
		});

		$(document).on('change', '.geweb-ai-attachment-image-mode', function() {
				const $select = $(this);
				const $cell = $select.closest('.geweb-ai-index-cell');
				const postId = Number($cell.data('post-id') || 0);
				const mode = String($select.val() || 'none');
				const messages = {
						none: 'Disabling image processing...',
						ocr: 'Enabling OCR...',
						describe: 'Enabling image description...'
				};
				if (!postId) {
return;
}

				$select.prop('disabled', true);
				showCellFeedback($cell, messages[mode] || 'Updating image processing...', false);

				$.ajax({
						url: ajaxurl,
						type: 'POST',
						timeout: 30000,
						data: {
								action: 'geweb_set_attachment_image_processing_mode',
								nonce: gewebAisearchAdmin.adminActionNonce,
								post_id: postId,
								mode: mode
						},
						success: function(response) {
								if (response.success) {
										replaceCellHtml($select, response?.data?.html || '', postId);
										replaceMarkdownCacheCellHtml($select, response?.data?.markdown_cache_html || '', postId);
										return;
								}

								replaceCellHtml($select, response?.data?.html || '', postId);
								replaceMarkdownCacheCellHtml($select, response?.data?.markdown_cache_html || '', postId);
								if ($cell.length) {
										showCellFeedback($cell, response?.data?.message || 'Could not update image processing mode.', true);
								}
						},
						error: function() {
								$select.prop('disabled', false);
								showCellFeedback($cell, 'Could not update image processing mode.', true);
						}
				});
		});

		let isRefreshingReferencedDocuments = false;
		let isRefreshingGeminiStores = false;
		let isRefreshingGeminiStoreDocuments = false;
		let isRefreshingConversations = false;
		let adminPreloadProgressJobId = '';
		let adminPreloadPollTimer = 0;
		let referencedDocumentsFilterTimer = 0;
		let conversationsFilterTimer = 0;

		function refreshReferencedDocuments(preloadJobId, options) {
				const normalizedOptions = options || {};
				const $container = $('#geweb-referenced-documents-container');
				const $button = $('#geweb-refresh-referenced-documents');
				const $status = $('#geweb-referenced-documents-status');
				if (!$container.length || !$button.length || isRefreshingReferencedDocuments) {
return;
}

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
						nonce: gewebAisearchAdmin.adminActionNonce,
						preload_job: preloadJobId || '',
						force_refresh: normalizedOptions.forceRefresh ? 1 : 0
				}
		}).done((response) => {
						syncGroupRevisionFromPayload(response?.data);
						if (!response?.success || !response?.data?.html) {
								$status.text('Could not refresh referenced documents.');
								if (!normalizedOptions.keepOverlay) {
										hideAdminLoadingOverlay();
								}
								isRefreshingReferencedDocuments = false;
								$button.prop('disabled', false).text('Refresh List');
								return;
						}

						$container.html(response.data.html).attr('data-needs-refresh', '0');
						prepareReferencedDocumentsTableHeaders();
							const suffix = buildCountSuffix(response.data.count, 'document', 'documents');
							$status.text('Last refreshed: ' + (response.data.refreshed_at || 'just now') + suffix);
						if (!normalizedOptions.keepOverlay) {
								hideAdminLoadingOverlay();
						}
						isRefreshingReferencedDocuments = false;
						$button.prop('disabled', false).text('Refresh List');
				}).fail(() => {
						$status.text('Could not refresh referenced documents.');
						$container.html('<p>Could not load referenced documents.</p>');
						if (!normalizedOptions.keepOverlay) {
								hideAdminLoadingOverlay();
						}
						isRefreshingReferencedDocuments = false;
						$button.prop('disabled', false).text('Refresh List');
				});
		}

			function refreshSelectedGeminiStoreDocuments(storeName, storeLabel, preloadJobId) {
					const $panel = $('#geweb-gemini-store-documents-panel');
				const $container = $('#geweb-gemini-store-documents-container');
				const $status = $('#geweb-gemini-store-documents-status');
				const $error = $('#geweb-gemini-store-documents-error');
				const $title = $('#geweb-gemini-store-documents-title');
				const $button = $('#geweb-refresh-gemini-store-documents');
				const $storesRefreshButton = $('#geweb-refresh-gemini-stores');
				if (!$panel.length || !$container.length || !storeName || isRefreshingGeminiStoreDocuments) {
return;
}

				isRefreshingGeminiStoreDocuments = true;
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
								nonce: gewebAisearchAdmin.adminActionNonce,
								preload_job: preloadJobId || ''
						}
				}).done((response) => {
						syncGroupRevisionFromPayload(response?.data);
						if (!response?.success || typeof response?.data?.html !== 'string') {
								const message = response?.data?.message || 'Could not refresh uploaded items.';
								$status.text(message);
								$error.text(message).show();
								$container.html('<p>Could not load uploaded items.</p>');
									setRefreshButtonState($button, false, 'Refresh List');
									setRefreshButtonState($storesRefreshButton, false, 'Refresh List');
									isRefreshingGeminiStoreDocuments = false;
									return;
							}

						$container.html(response.data.html);
						applyGeminiStoreDocumentsBrowserState();
						$title.text(response.data.store_label || storeLabel || storeName);
							$status.text((response.data.message || 'Uploaded items refreshed.') + buildCountSuffix(response.data.count, 'item', 'items'));
						$error.hide().text('');
							setRefreshButtonState($button, false, 'Refresh List');
							setRefreshButtonState($storesRefreshButton, false, 'Refresh List');
							isRefreshingGeminiStoreDocuments = false;
					}).fail((xhr) => {
							const message = xhr?.responseJSON?.data?.message || 'Could not refresh uploaded items.';
							$status.text(message);
							$error.text(message).show();
							$container.html('<p>Could not load uploaded items.</p>');
							setRefreshButtonState($button, false, 'Refresh List');
							setRefreshButtonState($storesRefreshButton, false, 'Refresh List');
							isRefreshingGeminiStoreDocuments = false;
					});
			}

		function refreshConversations(preloadJobId, options) {
				const normalizedOptions = options || {};
				const $container = $('#geweb-conversations-container');
				const $button = $('#geweb-refresh-conversations');
				const $status = $('#geweb-conversations-status');
				if (!$container.length || !$button.length || isRefreshingConversations) {
return;
}

				isRefreshingConversations = true;
				$button.prop('disabled', true).text('Refreshing...');
				$status.text('Loading chats...');
				$container.html('<p>Loading chats...</p>');

				$.ajax({
						url: getAdminAjaxUrl(),
						type: 'POST',
						dataType: 'json',
						data: {
								action: 'geweb_refresh_conversations',
								nonce: gewebAisearchAdmin.adminActionNonce,
								preload_job: preloadJobId || ''
						}
				}).done((response) => {
						if (!response?.success || typeof response?.data?.html !== 'string') {
								$status.text('Could not refresh chats.');
								if (!normalizedOptions.keepOverlay) {
										hideAdminLoadingOverlay();
								}
								isRefreshingConversations = false;
								$button.prop('disabled', false).text('Refresh List');
								return;
						}

						$container.html(response.data.html);
						prepareConversationsTableSearch();
							const suffix = buildCountSuffix(response.data.count, 'chat', 'chats');
						$status.text('Last refreshed: ' + (response.data.refreshed_at || 'just now') + suffix);
						if (!normalizedOptions.keepOverlay) {
								hideAdminLoadingOverlay();
						}
						isRefreshingConversations = false;
						$button.prop('disabled', false).text('Refresh List');
				}).fail(() => {
						$status.text('Could not refresh chats.');
						$container.html('<p>Could not load chats.</p>');
						if (!normalizedOptions.keepOverlay) {
								hideAdminLoadingOverlay();
						}
						isRefreshingConversations = false;
						$button.prop('disabled', false).text('Refresh List');
				});
		}

			function applyGeminiStoreDocumentsBrowserState() {
					const $browser = $('.geweb-gemini-store-documents-browser');
					if (!$browser.length) {
return;
}

					$browser.each(function() {
							applyGeminiStoreDocumentBrowserState($(this));
					});
			}

			function stopAdminPreloadProgressPolling() {
					if (adminPreloadPollTimer) {
							globalThis.clearInterval(adminPreloadPollTimer);
							adminPreloadPollTimer = 0;
					}
			}

			function completeAdminPanelPreload() {
					stopAdminPreloadProgressPolling();
					updateAdminLoadingOverlayProgress('Loading Settings…', 100, 'Ready');
					globalThis.setTimeout(hideAdminLoadingOverlay, 250);
			}

			function finalizeAdminPanelPreload($state) {
					globalThis.setTimeout(() => {
							$state.attr('data-needs-preload', '0');
					}, 0);

					const finishCheck = globalThis.setInterval(() => {
							const preloadStillRunning = isRefreshingReferencedDocuments
									|| isRefreshingGeminiStores
									|| isRefreshingGeminiStoreDocuments
									|| isRefreshingConversations;
							if (preloadStillRunning) {
									return;
							}

							globalThis.clearInterval(finishCheck);
							globalThis.setTimeout(completeAdminPanelPreload, 200);
					}, 200);
			}

			function pollAdminPreloadProgress(jobId) {
					if (!jobId) {
							return;
					}

					function handlePreloadProgressResponse(response) {
							if (!response?.success || !response?.data) {
									return;
							}

							const progress = response.data;
							const title = String(progress.current_label || (progress.finished ? 'Loading Settings…' : 'Loading…'));
							const subtitle = typeof progress.total_steps === 'number'
									? String((progress.completed_steps || 0) + (progress.failed_steps || 0)) + ' / ' + String(progress.total_steps) + ' steps'
									: '';
							updateAdminLoadingOverlayProgress(title, Number(progress.percent || 0), subtitle);
							if (progress.finished) {
									stopAdminPreloadProgressPolling();
							}
					}

					stopAdminPreloadProgressPolling();
					adminPreloadPollTimer = globalThis.setInterval(() => {
							$.ajax({
									url: getAdminAjaxUrl(),
								type: 'POST',
								dataType: 'json',
								data: {
											action: 'geweb_get_admin_preload_progress',
											nonce: gewebAisearchAdmin.adminActionNonce,
											job_id: jobId
									}
							}).done(handlePreloadProgressResponse);
					}, 350);
			}

		function startAdminPanelPreload() {
				const $state = $('#geweb-ai-admin-preload-state');
				if (!$state.length || String($state.attr('data-needs-preload') || '0') !== '1') {
						return;
				}

				showAdminLoadingOverlay('Loading Settings…');

				$.ajax({
						url: getAdminAjaxUrl(),
						type: 'POST',
						dataType: 'json',
						data: {
								action: 'geweb_start_admin_preload',
								nonce: gewebAisearchAdmin.adminActionNonce
						}
				}).done((response) => {
						if (!response?.success || !response?.data?.job_id) {
								hideAdminLoadingOverlay();
								return;
						}

						adminPreloadProgressJobId = String(response.data.job_id || '');
						pollAdminPreloadProgress(adminPreloadProgressJobId);

							refreshReferencedDocuments(adminPreloadProgressJobId, { keepOverlay: true });
							refreshGeminiStores(adminPreloadProgressJobId, { keepOverlay: true, preload: true });
							refreshConversations(adminPreloadProgressJobId, { keepOverlay: true });
							finalizeAdminPanelPreload($state);
					}).fail(() => {
							hideAdminLoadingOverlay();
					});
		}

		$('#geweb-refresh-referenced-documents').on('click', () => {
				showAdminLoadingOverlay('Loading Documents…');
				refreshReferencedDocuments('', { forceRefresh: true });
		});

		$('#geweb-refresh-conversations').on('click', () => {
				showAdminLoadingOverlay('Loading Chats…');
				refreshConversations('', { keepOverlay: false });
		});

		$(document).on('click', '.geweb-referenced-document-upload-now', function() {
				const $button = $(this);
				const fileHash = String($button.data('file-hash') || '');
				const $cell = $button.closest('.geweb-ai-index-cell');
				const $row = $button.closest('tr');
				const $form = $button.closest('.geweb-referenced-documents-table-form');
				const $status = $('#geweb-referenced-documents-status');
				if (!fileHash) {
return;
}

				$button.prop('disabled', true).text('Uploading...');
				showCellFeedback($cell, 'Uploading in the background...', false);
				if ($status.length) {
						$status.text('Uploading document...');
				}

				$.ajax({
						url: getAdminAjaxUrl(),
						type: 'POST',
						dataType: 'json',
						data: buildGroupRevisionData({
								action: 'geweb_update_referenced_document',
								document_action: 'upload',
								file_hash: fileHash,
								nonce: gewebAisearchAdmin.adminActionNonce
						})
					}).done((response) => {
							syncGroupRevisionFromPayload(response?.data);
							syncAdminViewCacheState(response?.data, ['files']);
							if (!response?.success || !response?.data) {
									const message = response?.data?.message || 'The document upload could not be completed.';
									maybeReplaceReferencedDocumentRowFromResponse($row, $form, response?.data);
									showCellFeedback($cell, message, true);
									$button.prop('disabled', false).text('Upload');
									if ($status.length) {
										$status.text(message);
								}
								return;
						}

							const $row = $button.closest('tr');
							updateReferencedDocumentActionRow($row, response.data);
							// Do not refresh the whole Gemini stores list here; local row update is enough.
					}).fail((xhr) => {
							const message = getAjaxErrorMessage(xhr, 'The document upload could not be completed.');
							const responseData = xhr?.responseJSON?.data;
							syncGroupRevisionFromPayload(responseData);
							syncAdminViewCacheState(responseData, ['files']);
							maybeReplaceReferencedDocumentRowFromResponse($row, $form, responseData);
							showCellFeedback($cell, message, true);
							$button.prop('disabled', false).text('Upload');
							if ($status.length) {
								$status.text(message);
						}
				});
		});

		$(document).on('change', '.geweb-referenced-document-toggle-exclude', function() {
				const $checkbox = $(this);
				const $cell = $checkbox.closest('.geweb-ai-index-cell');
				const $row = $checkbox.closest('tr');
				const $form = $checkbox.closest('.geweb-referenced-documents-table-form');
				const fileHash = String($checkbox.data('file-hash') || '');
				const exclude = $checkbox.is(':checked') ? 1 : 0;
				const $status = $('#geweb-referenced-documents-status');
				if (!fileHash) {
return;
}

				$checkbox.prop('disabled', true);
				showCellFeedback($cell, exclude ? 'Excluding in the background...' : 'Including for upload...', false);

				$.ajax({
						url: getAdminAjaxUrl(),
						type: 'POST',
						dataType: 'json',
						data: buildGroupRevisionData({
								action: 'geweb_toggle_referenced_document_exclude',
								file_hash: fileHash,
								exclude: exclude,
								nonce: gewebAisearchAdmin.adminActionNonce
						})
					}).done((response) => {
							syncGroupRevisionFromPayload(response?.data);
							syncAdminViewCacheState(response?.data, ['files']);
							if (!response?.success || !response?.data) {
									const message = response?.data?.message || 'Could not update exclusion.';
									maybeReplaceReferencedDocumentRowFromResponse($row, $form, response?.data);
									showCellFeedback($cell, message, true);
									$checkbox.prop('disabled', false).prop('checked', !exclude);
									if ($status.length) {
										$status.text(message);
								}
								return;
						}

							const $row = $checkbox.closest('tr');
							updateReferencedDocumentActionRow($row, response.data);

						if ($status.length) {
								$status.text(response.data.message || (exclude ? 'Excluded from indexing.' : 'Included for indexing.'));
						}

						if ($('#geweb-gemini-stores-container').length) {
								refreshGeminiStores();
						}
				}).fail((xhr) => {
						const message = getAjaxErrorMessage(xhr, 'Could not update exclusion.');
						const responseData = xhr?.responseJSON?.data;
						syncGroupRevisionFromPayload(responseData);
						syncAdminViewCacheState(responseData, ['files']);
						if (responseData?.row_html && $row.length) {
								replaceReferencedDocumentRow($row, responseData.row_html);
								if ($form.length) {
										applyReferencedDocumentsFilters($form);
								}
						}
						showCellFeedback($cell, message, true);
						$checkbox.prop('disabled', false).prop('checked', !exclude);
						if ($status.length) {
								$status.text(message);
						}
				});
		});

		$(document).on('click', '.geweb-referenced-document-remove-now', function() {
				const $button = $(this);
				const $cell = $button.closest('.geweb-ai-index-cell');
				const $row = $button.closest('tr');
				const $status = $('#geweb-referenced-documents-status');
				const fileHash = String($button.data('file-hash') || '');
				const $exclude = $row.find('.geweb-referenced-document-toggle-exclude').first();
				if (!fileHash) {
return;
}

				$button.prop('disabled', true).text('Removing...');
				if ($exclude.length) {
						$exclude.prop('disabled', true);
				}
				showCellFeedback($cell, 'Removing from Gemini store...', false);
				if ($status.length) {
						$status.text('Removing document from Gemini store...');
				}

				$.ajax({
						url: getAdminAjaxUrl(),
						type: 'POST',
						dataType: 'json',
						data: buildGroupRevisionData({
								action: 'geweb_toggle_referenced_document_exclude',
								file_hash: fileHash,
								exclude: 1,
								nonce: gewebAisearchAdmin.adminActionNonce
						})
				}).done((response) => {
						syncGroupRevisionFromPayload(response?.data);
						syncAdminViewCacheState(response?.data, ['files']);
						if (!response?.success || !response?.data) {
								const message = response?.data?.message || 'Could not remove this document from the Gemini store.';
								showCellFeedback($cell, message, true);
								$button.prop('disabled', false).text('Remove from store');
								if ($exclude.length) {
										$exclude.prop('disabled', false).prop('checked', false);
								}
								if ($status.length) {
										$status.text(message);
								}
								return;
						}

						if ($row.length) {
								if (response.data.status_html) {
										$row.find('td.column-actions .geweb-ai-index-cell > p').first().html(response.data.status_html);
								}
								if (response.data.actions_html) {
										$row.find('td.column-actions').html(response.data.actions_html);
								}
								if (response.data.markdown_cache_html) {
										$row.find('td.column-markdown_cache').html(response.data.markdown_cache_html);
								}
						}

						if ($status.length) {
								$status.text('Removed from Gemini store.');
						}

						if ($('#geweb-gemini-stores-container').length) {
								refreshGeminiStores();
						}
				}).fail((xhr) => {
						const message = getAjaxErrorMessage(xhr, 'Could not remove this document from the Gemini store.');
						showCellFeedback($cell, message, true);
						$button.prop('disabled', false).text('Remove from store');
						if ($exclude.length) {
								$exclude.prop('disabled', false).prop('checked', false);
						}
						if ($status.length) {
								$status.text(message);
						}
				});
		});

		$(document).on('change', '.geweb-ai-referenced-document-image-mode', function() {
				const $select = $(this);
				const $cell = $select.closest('.geweb-ai-index-cell, td');
				const fileHash = String($select.data('file-hash') || '');
				const mode = String($select.val() || 'none');
				const subject = String($select.data('processing-subject') || 'image');
				const $status = $('#geweb-referenced-documents-status');
				const messages = {
						none: subject === 'pdf' ? 'Disabling PDF processing...' : 'Disabling image processing...',
						ocr: subject === 'pdf' ? 'Enabling OCR for this PDF...' : 'Enabling OCR for this image...',
						describe: subject === 'pdf' ? 'Enabling description for this PDF...' : 'Enabling image description for this image...'
				};
				if (!fileHash) {
return;
}

				$select.prop('disabled', true);
				showCellFeedback($cell, messages[mode] || 'Updating image processing...', false);
				if ($status.length) {
						$status.text(messages[mode] || 'Updating image processing...');
				}

				$.ajax({
						url: getAdminAjaxUrl(),
						type: 'POST',
						dataType: 'json',
						data: buildGroupRevisionData({
								action: 'geweb_set_referenced_document_image_processing_mode',
								file_hash: fileHash,
								mode: mode,
								nonce: gewebAisearchAdmin.adminActionNonce
						})
				}).done((response) => {
						syncGroupRevisionFromPayload(response?.data);
						syncAdminViewCacheState(response?.data, ['files']);
						if (!response?.success || !response?.data) {
								const message = response?.data?.message || 'Could not update image processing mode.';
								showCellFeedback($cell, message, true);
								$select.prop('disabled', false);
								if ($status.length) {
										$status.text(message);
								}
								return;
						}

						const $row = $select.closest('tr');
						if ($row.length) {
								if (response.data.actions_html) {
										$row.find('td.column-actions').html(response.data.actions_html);
								}
								if (typeof response.data.pdf_analysis_html === 'string') {
										$row.find('td.column-pdf_analysis').html(response.data.pdf_analysis_html);
								}
								if (typeof response.data.markdown_cache_html === 'string') {
										$row.find('td.column-markdown_cache').html(response.data.markdown_cache_html);
								}
						}

						if ($status.length) {
								$status.text(response.data.message || (subject === 'pdf' ? 'PDF processing updated.' : 'Image processing updated.'));
						}
				}).fail((xhr) => {
						const message = getAjaxErrorMessage(xhr, subject === 'pdf' ? 'Could not update PDF processing mode.' : 'Could not update image processing mode.');
						showCellFeedback($cell, message, true);
						$select.prop('disabled', false);
						if ($status.length) {
								$status.text(message);
						}
				});
		});

		$(document).on('click', '.geweb-edit-nice-name-trigger', function(e) {
				e.preventDefault();
				const $cell = $(this).closest('.geweb-nice-name-cell');
				if (!$cell.length) {
return;
}

				$cell.find('.geweb-edit-nice-name-form').show();
				$(this).hide();
				const $input = $cell.find('.geweb-edit-nice-name-input');
				$input.trigger('focus').trigger('select');
		});

		$(document).on('click', '.geweb-cancel-nice-name', function(e) {
				e.preventDefault();
				const $cell = $(this).closest('.geweb-nice-name-cell');
				if (!$cell.length) {
return;
}

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
				const $button = $(this);
				const $cell = $button.closest('.geweb-nice-name-cell');
				const fileHash = String($cell.data('file-hash') || '');
				const $input = $cell.find('.geweb-edit-nice-name-input');
				const niceName = $.trim(String($input.val() || ''));
				const $feedback = $cell.find('.geweb-ai-index-feedback');
				const $status = $('#geweb-referenced-documents-status');
				const $niceNameCell = $button.closest('tr').find('td.column-nice_name');

				if (!fileHash) {
return;
}
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
						data: buildGroupRevisionData({
								action: 'geweb_update_referenced_document_nice_name',
								file_hash: fileHash,
								nice_name: niceName,
								nonce: gewebAisearchAdmin.adminActionNonce
						})
				}).done((response) => {
						syncGroupRevisionFromPayload(response?.data);
						if (!response?.success || !response?.data) {
								const message = response?.data?.message || 'The nice name could not be updated.';
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

						const suffix = typeof response.data.count === 'number'
								? ' (' + response.data.count + ' documents)'
								: '';
						if ($status.length) {
								$status.text((response.data.message || 'Nice name updated.') + ' Last refreshed: ' + (response.data.refreshed_at || 'just now') + suffix);
						}
				}).fail((xhr) => {
						const message = getAjaxErrorMessage(xhr, 'The nice name could not be updated.');
						$feedback.text(message).css('color', '#d63638').show();
						$button.prop('disabled', false).text('Save');
						if ($status.length) {
								$status.text(message);
						}
				});
		});

		applyPendingReferencedDocumentTargets();

		function refreshGeminiStores(preloadJobId, options) {
				const normalizedOptions = options || {};
				const $container = $('#geweb-gemini-stores-container');
				const $button = $('#geweb-refresh-gemini-stores');
				const $status = $('#geweb-gemini-stores-status');
				const $error = $('#geweb-gemini-stores-error');
				if (!$container.length || !$button.length || isRefreshingGeminiStores) {
return;
}

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
						nonce: gewebAisearchAdmin.adminActionNonce,
						preload_job: preloadJobId || '',
						force_refresh: normalizedOptions.forceRefresh ? 1 : 0
				}
		}).done((response) => {
						syncGroupRevisionFromPayload(response?.data);
						if (!response?.success || !response?.data?.html) {
								$status.text('Could not refresh Gemini stores.');
								if (!normalizedOptions.keepOverlay) {
										hideAdminLoadingOverlay();
								}
								isRefreshingGeminiStores = false;
								$button.prop('disabled', false).text('Refresh List');
								return;
						}

						$container.html(response.data.html).attr('data-needs-refresh', '0');
						let suffix = '';
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
						const selectedStoreName = $('#geweb-gemini-store-documents-panel').attr('data-store-name') || '';
						let $selectedButton = selectedStoreName
								? $('.geweb-select-gemini-store[data-store-name="' + escapeSelectorAttributeValue(selectedStoreName) + '"]').first()
								: $();
						if (!$selectedButton.length) {
								$selectedButton = $('.geweb-select-gemini-store').first();
						}
						if ($selectedButton.length) {
									refreshSelectedGeminiStoreDocuments(
											String($selectedButton.data('store-name') || ''),
											String($selectedButton.data('store-label') || ''),
											preloadJobId || ''
									);
							} else {
									setRefreshButtonState($('#geweb-refresh-gemini-store-documents'), false, 'Refresh List');
							}
						if (!normalizedOptions.keepOverlay) {
								hideAdminLoadingOverlay();
						}
						isRefreshingGeminiStores = false;
						$button.prop('disabled', false).text('Refresh List');
				}).fail(() => {
						$status.text('Could not refresh Gemini stores.');
						$error.hide().text('');
						$container.html('<p>Could not load Gemini stores.</p>');
						$('#geweb-refresh-gemini-store-documents').prop('disabled', false).text('Refresh List');
						if (!normalizedOptions.keepOverlay) {
								hideAdminLoadingOverlay();
						}
						isRefreshingGeminiStores = false;
						$button.prop('disabled', false).text('Refresh List');
				});
		}

		$('#geweb-refresh-gemini-stores').on('click', () => {
				showAdminLoadingOverlay('Loading Gemini Stores…');
				refreshGeminiStores('', { forceRefresh: true });
		});

		$(document).on('click', '.geweb-select-gemini-store', function(e) {
				e.preventDefault();
				const $button = $(this);
				const storeName = String($button.data('store-name') || '');
				const storeLabel = String($button.data('store-label') || '');
				if (!storeName) {
return;
}

				$('.geweb-select-gemini-store').css('font-weight', '400');
				$button.css('font-weight', '600');
				refreshSelectedGeminiStoreDocuments(storeName, storeLabel);
		});

		$('#geweb-refresh-gemini-store-documents').on('click', () => {
				const $panel = $('#geweb-gemini-store-documents-panel');
				const storeName = String($panel.attr('data-store-name') || '');
				const storeLabel = $.trim($('#geweb-gemini-store-documents-title').text());
				if (!storeName) {
return;
}

				refreshSelectedGeminiStoreDocuments(storeName, storeLabel);
		});

		$(document).on('input change', '.geweb-gemini-store-documents-filter, .geweb-gemini-store-documents-id-filter, .geweb-gemini-store-documents-slug-filter, .geweb-gemini-store-documents-type-filter, .geweb-gemini-store-documents-format-filter', () => {
				applyGeminiStoreDocumentsBrowserState();
		});

		$(document).on('click', '.geweb-gemini-store-documents-sort-header', function(e) {
				e.preventDefault();
				const $button = $(this);
				const $browser = $button.closest('.geweb-gemini-store-documents-browser');
				const sortKey = String($button.data('sort-key') || 'name');
				const currentSort = String($browser.attr('data-sort') || 'name-asc');
				let nextDirection = 'asc';

				if (currentSort === sortKey + '-asc') {
						nextDirection = 'desc';
				}

				$browser.attr('data-sort', sortKey + '-' + nextDirection);
				applyGeminiStoreDocumentsBrowserState();
		});

		$(document).on('click', '.geweb-delete-gemini-store', function(e) {
				e.preventDefault();
				e.stopPropagation();

				const $button = $(this);
				const storeName = $button.data('store-name');
				const confirmMessage = $button.data('confirm-message') || 'Delete this Gemini store?';
				const $container = $('#geweb-gemini-stores-container');
				const $status = $('#geweb-gemini-stores-status');
				const $error = $('#geweb-gemini-stores-error');
				const $row = $button.closest('tr');
				if (!storeName || !$container.length || $button.prop('disabled')) {
return;
}
				if (!globalThis.confirm(confirmMessage)) {
return;
}

				$button.prop('disabled', true).text('Deleting...');
				$row.css('opacity', '0.5');
				$status.text('Deleting Gemini store...');
				$error.hide().text('');

				$.ajax({
						url: getAdminAjaxUrl(),
						type: 'POST',
						dataType: 'json',
						data: buildGroupRevisionData({
								action: 'geweb_delete_gemini_store',
								store_name: storeName,
								nonce: gewebAisearchAdmin.adminActionNonce
						})
				}).done((response) => {
						syncGroupRevisionFromPayload(response?.data);
						if (!response?.success || !response?.data?.html) {
								const message = response?.data?.message || 'Could not delete the Gemini store.';
								$status.text(message);
								$error.text(message).show();
								$button.prop('disabled', false).text('Delete');
								$row.css('opacity', '1');
								return;
						}

						$container.html(response.data.html).attr('data-needs-refresh', '0');
						let suffix = '';
						if (typeof response.data.count === 'number') {
								suffix = ' (' + response.data.count + ' ' + (response.data.count === 1 ? 'store' : 'stores') + ')';
						}
						$status.text((response.data.message || 'Gemini store deleted.') + ' Last refreshed: ' + (response.data.refreshed_at || 'just now') + suffix);
						if (response.data.error) {
								$error.text(response.data.error).show();
						} else {
								$error.hide().text('');
						}

							if (response.data.deleted_store_name && $container.find('.geweb-delete-gemini-store[data-store-name="' + escapeSelectorAttributeValue(response.data.deleted_store_name) + '"]').length) {
									$status.text('Delete requested. Refreshing the Gemini stores list again...');
									globalThis.setTimeout(refreshGeminiStores, 1500);
							}
				}).fail((xhr) => {
						const message = getAjaxErrorMessage(xhr, 'Could not delete the Gemini store.');
						$status.text(message);
						$error.text(message).show();
						$button.prop('disabled', false).text('Delete');
				$row.css('opacity', '1');
			});
		});

		$(document).on('click', '.geweb-model-diagnostics-table .geweb-sortable-column', function(event) {
				event.preventDefault();
				const $button = $(this);
				const $table = $button.closest('.geweb-model-diagnostics-table');
				if (!$table.length) {
						return;
				}

				const column = String($button.attr('data-sort-column') || '');
				const sortType = String($button.attr('data-sort-type') || 'text');
				if (!column) {
						return;
				}

				sortModelDiagnosticsTable($table, column, sortType);
		});

		$(document).on('click', '.geweb-referenced-documents-table-form th.sortable a, .geweb-referenced-documents-table-form th.sorted a', function(event) {
				event.preventDefault();
				const $link = $(this);
				const $th = $link.closest('th');
				const $table = $link.closest('table');
				const column = String($th.attr('id') || '').trim();
				if (!column || !$table.length) {
						return;
				}

				sortReferencedDocumentsTable($table, column);
		});

		applyGeminiStoreDocumentsBrowserState();
		prepareModelDiagnosticsTableHeaders();
		prepareReferencedDocumentsTableHeaders();
		prepareConversationsTableSearch();
		startAdminPanelPreload();
	});

})(jQuery);
