(function($) {
	function getAdminAjaxUrl() {
		return globalThis.gewebAisearchAdmin?.ajaxUrl || globalThis.ajaxurl || '';
	}

	function escapeSelectorAttributeValue(value) {
		const normalizedValue = String(value || '');
		if (typeof globalThis.CSS?.escape === 'function') {
			return globalThis.CSS.escape(normalizedValue);
		}

		return normalizedValue
			.replaceAll('\\', String.raw`\\`)
			.replaceAll('"', String.raw`\"`);
	}

	function decodeBase64Value(encodedValue) {
		if (typeof encodedValue !== 'string' || encodedValue === '') {
			return '';
		}

		if (!/^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$/.test(encodedValue)) {
			return '';
		}

		const binaryValue = globalThis.atob(encodedValue);
		const binaryLength = binaryValue.length;
		const bytes = new Uint8Array(binaryLength);

		for (let index = 0; index < binaryLength; index += 1) {
			bytes[index] = binaryValue.codePointAt(index) || 0;
		}

		if (typeof globalThis.TextDecoder === 'function') {
			const utf8Value = new globalThis.TextDecoder('utf-8').decode(bytes);
			if (!utf8Value.includes('\uFFFD')) {
				return utf8Value;
			}

			return new globalThis.TextDecoder('windows-1252').decode(bytes);
		}

		let decodedValue = '';
		for (let index = 0; index < binaryLength; index += 1) {
			decodedValue += String.fromCodePoint(bytes[index]);
		}

		return decodedValue;
	}

	function escapeHtml(text) {
		return String(text || '')
			.replaceAll('&', '&amp;')
			.replaceAll('<', '&lt;')
			.replaceAll('>', '&gt;')
			.replaceAll('"', '&quot;')
			.replaceAll("'", '&#039;');
	}

	function decodeHtmlEntities(text) {
		const textarea = globalThis.document.createElement('textarea');
		textarea.innerHTML = String(text || '');
		return textarea.value;
	}

	function getLastTruthyValue(values) {
		return Array.isArray(values) ? values.findLast(Boolean) || '' : '';
	}

	function recordRecoverableAdminError(error, context) {
		globalThis.gewebAisearchAdminLastRecoverableError = {
			context: String(context || 'unknown'),
			message: String(error?.message || error || '')
		};
	}

	function safeDecodeURIComponent(value) {
		try {
			return decodeURIComponent(String(value || ''));
		} catch (error) {
			recordRecoverableAdminError(error, 'decodeURIComponent');
			return String(value || '');
		}
	}

	function readLocalStorageString(key) {
		try {
			return String(globalThis.localStorage?.getItem(key) || '');
		} catch (error) {
			recordRecoverableAdminError(error, 'localStorage.getItem');
			return '';
		}
	}

	function writeLocalStorageString(key, value) {
		try {
			globalThis.localStorage?.setItem(key, String(value || ''));
			return true;
		} catch (error) {
			recordRecoverableAdminError(error, 'localStorage.setItem');
			return false;
		}
	}

	function parseLocalSortSpecs($table) {
		try {
			const rawValue = String($table.attr('data-local-sort-specs') || '[]');
			const parsedValue = JSON.parse(rawValue);
			return Array.isArray(parsedValue)
				? parsedValue.filter((spec) => {
					return spec && typeof spec.column === 'string' && typeof spec.direction === 'string';
				})
				: [];
		} catch (error) {
			recordRecoverableAdminError(error, 'parseLocalSortSpecs');
			return [];
		}
	}

	function cycleSortDirection(currentDirection) {
		if (currentDirection === 'asc') {
			return 'desc';
		}

		if (currentDirection === 'desc') {
			return '';
		}

		return 'asc';
	}

	function getSortIndicator(isActive, direction) {
		if (!isActive) {
			return '';
		}

		return direction === 'desc' ? '↓' : '↑';
	}

	function getAriaSortValue(direction) {
		if (direction === 'desc') {
			return 'descending';
		}

		if (direction === 'asc') {
			return 'ascending';
		}

		return 'none';
	}

	function ensureOriginalRowIndex($row, index) {
		if ($row.attr('data-original-index') === undefined) {
			$row.attr('data-original-index', String(index));
		}
	}

	function getRegexMatch(text, pattern) {
		const match = pattern.exec(String(text || ''));
		pattern.lastIndex = 0;
		return match;
	}

	function buildCountSuffix(count, singular, plural) {
		if (typeof count !== 'number') {
			return '';
		}

		const label = count === 1 ? singular : plural;
		return ` (${count} ${label})`;
	}

	function submitFormWithFallback(form) {
		if (!form) {
			return;
		}

		if (typeof form.requestSubmit === 'function') {
			form.requestSubmit();
			return;
		}

		form.submit();
	}

	function buildModelOption(value, selectedValue, statusEntry) {
		const normalizedValue = String(value || '');
		const isFailedModel = String(statusEntry?.status || '') === 'failed';
		const option = new Option(normalizedValue, normalizedValue, false, normalizedValue === selectedValue);
		option.dataset.modelStatus = isFailedModel ? 'failed' : 'ok';
		if (isFailedModel) {
			option.style.color = '#b32d2e';
		}

		return option;
	}

	function populateModelSelectOptions($select, responseData, remoteSelected) {
		const sourceModels = responseData?.dropdown_models || responseData?.models || [];
		$.each(sourceModels, (_, model) => {
			const value = String(model || '');
			if (!value) {
				return;
			}

			$select.append(buildModelOption(value, remoteSelected, responseData?.model_statuses?.[value]));
		});

		if (!remoteSelected) {
			return;
		}

		const hasSelectedOption = $select.find(`option[value="${escapeSelectorAttributeValue(remoteSelected)}"]`).length > 0;
		if (hasSelectedOption) {
			$select.val(remoteSelected);
			return;
		}

		const statusEntry = responseData?.model_statuses?.[remoteSelected];
		if (statusEntry?.permanent_unavailable) {
			return;
		}

		$select.prepend(buildModelOption(remoteSelected, remoteSelected, statusEntry));
	}

	globalThis.GewebAISearchAdminUtils = {
		getAdminAjaxUrl,
		escapeSelectorAttributeValue,
		decodeBase64Value,
		escapeHtml,
		decodeHtmlEntities,
		getLastTruthyValue,
		recordRecoverableAdminError,
		safeDecodeURIComponent,
		readLocalStorageString,
		writeLocalStorageString,
		parseLocalSortSpecs,
		cycleSortDirection,
		getSortIndicator,
		getAriaSortValue,
		ensureOriginalRowIndex,
		getRegexMatch,
		buildCountSuffix,
		submitFormWithFallback,
		buildModelOption,
		populateModelSelectOptions,
	};
})(jQuery);
