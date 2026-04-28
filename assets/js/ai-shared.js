(function($) {
	let nonceRequest = null;

	function getAiSearchConfig() {
		return globalThis.geweb_aisearch ?? {};
	}

	function t(key, fallback) {
		const translated = getAiSearchConfig().i18n?.[key];
		return typeof translated === 'string' && translated.trim() !== '' ? translated : fallback;
	}

	function getLocalConversationArchiveLimit() {
		const value = Number(getAiSearchConfig().local_conversation_archive_limit);
		return Number.isFinite(value) && value >= 1 ? value : 12;
	}

	function fetchSearchNonce() {
		return $.post(getAiSearchConfig().ajax_url, {
			action: 'geweb_get_nonce'
		}).then((response) => {
			if (response?.success && response?.data?.nonce) {
				geweb_aisearch.search_nonce = response.data.nonce;
				return geweb_aisearch.search_nonce;
			}

			throw new Error('Could not get a fresh AI search nonce.');
		});
	}

	function ensureSearchNonce() {
		if (geweb_aisearch.search_nonce) {
			return Promise.resolve(geweb_aisearch.search_nonce);
		}

		if (!nonceRequest) {
			nonceRequest = Promise.resolve(fetchSearchNonce()).finally(() => {
				nonceRequest = null;
			});
		}

		return nonceRequest;
	}

	function clearSearchNonce() {
		geweb_aisearch.search_nonce = '';
	}

	globalThis.GewebAISearchShared = {
		getAiSearchConfig,
		t,
		getLocalConversationArchiveLimit,
		fetchSearchNonce,
		ensureSearchNonce,
		clearSearchNonce,
	};
})(jQuery);
