(function() {
	function normalizeObject(value) {
		return value && typeof value === 'object' ? value : {};
	}

	function pushLabeledEntry(entries, label, value) {
		const text = String(value || '').trim();
		if (!text) {
			return;
		}

		entries.push({ label, value: text });
	}

	function appendGroundingPlacementEntries(existing, indices, insertionOffset, sourceFootnoteMap) {
		indices.forEach((index) => {
			const localFootnote = index + 1;
			const footnote = Number(sourceFootnoteMap?.[localFootnote]) || localFootnote;
			if (!existing.some((entry) => entry.footnote === footnote && entry.offset === insertionOffset)) {
				existing.push({
					footnote,
					offset: insertionOffset,
				});
			}
		});
	}

	function safeParseUrl(url, base) {
		try {
			return new URL(url, base);
		} catch {
			return null;
		}
	}

	function normalizeManagedHost(hostname) {
		return String(hostname || '')
			.trim()
			.toLowerCase()
			.replace(/^www\./, '');
	}

	globalThis.GewebAISearchSourceUtils = {
		normalizeObject,
		pushLabeledEntry,
		appendGroundingPlacementEntries,
		safeParseUrl,
		normalizeManagedHost,
	};
})();
