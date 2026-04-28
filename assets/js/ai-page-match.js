(function() {
	const INLINE_MATCH_MAX_WINDOW = 16;
	const INLINE_MATCH_MIN_WINDOW_THRESHOLD = 8;
	const SNIPPET_CONTEXT_BEFORE = 90;
	const SNIPPET_CONTEXT_AFTER = 110;
	const PAGE_MATCH_MAX_OBSERVE_MS = 15000;
	const PAGE_MATCH_MUTATION_DEBOUNCE_MS = 250;
	const PAGE_MATCH_DEBUG_QUERY_PARAM = 'geweb_ai_match_debug';

	function t(key, fallback) {
		const translated = globalThis.GewebAISearchShared?.t;
		return typeof translated === 'function' ? translated(key, fallback) : fallback;
	}

	function normalizeInlineMatchText(text) {
		return buildNormalizedInlineMatchData(text).text;
	}

	function isInlineMatchWordCharacter(character) {
		return /[\p{L}\p{N}]/u.test(character) || ['€', '$', '%'].includes(character);
	}

	function buildNormalizedInlineMatchData(text) {
		const sourceText = String(text || '');
		let normalizedText = '';
		const normalizedToRawIndex = [];
		let previousWasSpace = false;

		for (let index = 0; index < sourceText.length; index += 1) {
			const character = sourceText[index];
			if (isInlineMatchWordCharacter(character)) {
				normalizedText += character.toLowerCase();
				normalizedToRawIndex.push(index);
				previousWasSpace = false;
				continue;
			}

			if (!normalizedText.length || previousWasSpace) {
				continue;
			}

			normalizedText += ' ';
			normalizedToRawIndex.push(index);
			previousWasSpace = true;
		}

		normalizedText = normalizedText.trimEnd();
		while (normalizedToRawIndex.length > normalizedText.length) {
			normalizedToRawIndex.pop();
		}

		return {
			text: normalizedText,
			indexMap: normalizedToRawIndex,
		};
	}

	function buildInlineMatchCandidates(phrase) {
		const words = String(phrase || '').split(/\s+/).filter(Boolean);
		if (!words.length) {
			return [];
		}

		const candidates = [];
		const maxWindowSize = Math.min(words.length, INLINE_MATCH_MAX_WINDOW);
		const minWindowSize = words.length > INLINE_MATCH_MIN_WINDOW_THRESHOLD
			? 4
			: Math.min(words.length, 3);

		for (let windowSize = maxWindowSize; windowSize >= minWindowSize; windowSize -= 1) {
			for (let start = 0; start <= words.length - windowSize; start += 1) {
				const candidate = words.slice(start, start + windowSize).join(' ').trim();
				if (candidate && !candidates.includes(candidate)) {
					candidates.push(candidate);
				}
			}
		}

		if (!candidates.length) {
			const fallback = words.slice(0, Math.min(words.length, 3)).join(' ').trim();
			if (fallback) {
				candidates.push(fallback);
			}
		}

		return candidates;
	}

	function findInlineMatchRangeInText(rawText, phrase) {
		const sourceText = String(rawText || '');
		const targetPhrase = String(phrase || '').trim();
		if (!sourceText || !targetPhrase) {
			return null;
		}

		const normalizedSource = buildNormalizedInlineMatchData(sourceText);
		const normalizedText = normalizedSource.text;
		const normalizedToRawIndex = normalizedSource.indexMap;
		const normalizedPhrase = normalizeInlineMatchText(targetPhrase);
		if (!normalizedPhrase) {
			return null;
		}

		const normalizedStart = normalizedText.indexOf(normalizedPhrase);
		if (normalizedStart < 0) {
			return null;
		}

		const normalizedEnd = normalizedStart + normalizedPhrase.length - 1;
		const rawStart = normalizedToRawIndex[normalizedStart];
		const rawEnd = normalizedToRawIndex[normalizedEnd];
		if (!Number.isInteger(rawStart) || !Number.isInteger(rawEnd) || rawEnd < rawStart) {
			return null;
		}

		return {
			start: rawStart,
			length: (rawEnd - rawStart) + 1,
		};
	}

	function escapeInlineMatchHtml(text) {
		return String(text || '')
			.replaceAll('&', '&amp;')
			.replaceAll('<', '&lt;')
			.replaceAll('>', '&gt;')
			.replaceAll('"', '&quot;')
			.replaceAll("'", '&#039;');
	}

	function buildPageMatchSnippet(text, matchIndex, matchLength) {
		const rawText = String(text || '').replaceAll(/\s+/g, ' ').trim();
		if (!rawText) {
			return '';
		}

		const start = Math.max(0, matchIndex - SNIPPET_CONTEXT_BEFORE);
		const end = Math.min(rawText.length, matchIndex + matchLength + SNIPPET_CONTEXT_AFTER);
		const prefix = start > 0 ? '…' : '';
		const suffix = end < rawText.length ? '…' : '';
		const before = escapeInlineMatchHtml(rawText.slice(start, matchIndex));
		const match = escapeInlineMatchHtml(rawText.slice(matchIndex, matchIndex + matchLength));
		const after = escapeInlineMatchHtml(rawText.slice(matchIndex + matchLength, end));

		return `${prefix}${before}<mark class="geweb-ai-page-match-preview-mark">${match}</mark>${after}${suffix}`;
	}

	function showPageMatchPreview(snippetHtml) {
		if (!snippetHtml) {
			return;
		}

		document.querySelectorAll('.geweb-ai-page-match-preview').forEach((element) => element.remove());
		const preview = document.createElement('aside');
		preview.className = 'geweb-ai-page-match-preview';
		preview.innerHTML = `
			<div class="geweb-ai-page-match-preview-label">${escapeInlineMatchHtml(t('matchPreviewLabel', 'Matching text on this page'))}</div>
			<div class="geweb-ai-page-match-preview-text">${snippetHtml}</div>
		`;
		document.body.appendChild(preview);
	}

	function getCurrentPageMatchSearchParams() {
		const search = globalThis.location?.search || '';
		return search ? new URLSearchParams(search) : null;
	}

	function isPageMatchDebugEnabled() {
		const searchParams = getCurrentPageMatchSearchParams();
		if (!searchParams || String(searchParams.get('geweb_ai_match') || '').trim() === '') {
			return false;
		}

		return ['1', 'true', 'yes', 'on'].includes(
			String(searchParams.get(PAGE_MATCH_DEBUG_QUERY_PARAM) || '').trim().toLowerCase()
		);
	}

	function logPageMatchDebug(stage, details = {}) {
		if (!isPageMatchDebugEnabled() || typeof globalThis.GewebAISearchPageMatchDebug !== 'function') {
			return;
		}

		globalThis.GewebAISearchPageMatchDebug({
			stage,
			...details,
		});
	}

	function selectPageMatchRange(range) {
		if (!range || typeof globalThis.getSelection !== 'function') {
			return;
		}

		const selection = globalThis.getSelection();
		if (!selection) {
			return;
		}

		selection.removeAllRanges();
		selection.addRange(range);
	}

	function isInlineMatchNodeVisible(node) {
		const parent = node?.parentElement;
		if (!parent) {
			return false;
		}

		if (parent.closest('[hidden], [aria-hidden="true"], template')) {
			return false;
		}

		const closedDetails = parent.closest('details:not([open])');
		if (closedDetails) {
			return false;
		}

		let current = parent;
		while (current && current !== document.body) {
			const style = globalThis.getComputedStyle ? globalThis.getComputedStyle(current) : null;
			if (style && (style.display === 'none' || style.visibility === 'hidden' || style.visibility === 'collapse' || Number(style.opacity) === 0)) {
				return false;
			}
			current = current.parentElement;
		}

		return true;
	}

	function isInlineMatchRangeVisible(range) {
		if (!range || typeof range.getClientRects !== 'function') {
			return false;
		}

		const rects = Array.from(range.getClientRects());
		return rects.some((rect) => rect.width > 0 && rect.height > 0);
	}

	function getInlineMatchScrollableAncestors(element) {
		const ancestors = [];
		let current = element?.parentElement || null;

		while (current && current !== document.body) {
			const style = globalThis.getComputedStyle ? globalThis.getComputedStyle(current) : null;
			const overflowY = `${style?.overflowY || ''} ${style?.overflow || ''}`.toLowerCase();
			if (/(auto|scroll|overlay)/.test(overflowY) && current.scrollHeight > current.clientHeight + 4) {
				ancestors.push(current);
			}
			current = current.parentElement;
		}

		return ancestors;
	}

	function centerInlineMatchInAncestor(element, ancestor, behavior = 'smooth') {
		if (!element || !ancestor || typeof ancestor.scrollTo !== 'function') {
			return;
		}

		const ancestorRect = ancestor.getBoundingClientRect();
		const elementRect = element.getBoundingClientRect();
		const nextTop = ancestor.scrollTop + (elementRect.top - ancestorRect.top) - ((ancestor.clientHeight - elementRect.height) / 2);
		if (Math.abs(nextTop - ancestor.scrollTop) < 2) {
			return;
		}

		ancestor.scrollTo({
			top: Math.max(0, nextTop),
			behavior,
		});
	}

	function centerInlineMatchInScrollableAncestors(element, behavior) {
		for (const ancestor of getInlineMatchScrollableAncestors(element)) {
			centerInlineMatchInAncestor(element, ancestor, behavior);
		}
	}

	function scrollDocumentToInlineMatch(element, scrollingElement, behavior) {
		const rect = element.getBoundingClientRect();
		const viewportHeight = globalThis.innerHeight || document.documentElement.clientHeight || 0;
		if (
			scrollingElement &&
			typeof scrollingElement.scrollTo === 'function' &&
			viewportHeight > 0 &&
			(rect.top < 96 || rect.bottom > viewportHeight - 32)
		) {
			const absoluteTop = rect.top + (globalThis.scrollY || scrollingElement.scrollTop || 0);
			const nextTop = absoluteTop - Math.max(96, Math.round((viewportHeight - rect.height) / 2));
			scrollingElement.scrollTo({
				top: Math.max(0, nextTop),
				behavior,
			});
		}

		return {
			rect,
			viewportHeight,
		};
	}

	function runInlineMatchScrollAttempt(element, scrollingElement, delay, index) {
		const behavior = index === 0 ? 'auto' : 'smooth';
		element.style.scrollMarginTop = '112px';
		element.style.scrollMarginBottom = '48px';

		if (typeof element.scrollIntoView === 'function') {
			element.scrollIntoView({ behavior, block: 'center', inline: 'nearest' });
		}

		centerInlineMatchInScrollableAncestors(element, behavior);
		const scrollResult = scrollDocumentToInlineMatch(element, scrollingElement, behavior);
		logPageMatchDebug('scroll-attempt', {
			delay,
			behavior,
			rectTop: scrollResult.rect.top,
			rectBottom: scrollResult.rect.bottom,
			viewportHeight: scrollResult.viewportHeight,
		});
	}

	function scheduleInlineMatchScrollAttempt(element, scrollingElement, delay, index) {
		globalThis.setTimeout(runInlineMatchScrollAttempt, delay, element, scrollingElement, delay, index);
	}

	function scrollInlineMatchIntoView(element) {
		if (!element) {
			logPageMatchDebug('scroll-skip-missing-element');
			return;
		}

		const scrollingElement = document.scrollingElement || document.documentElement || document.body;
		const attempts = [0, 180, 700];
		logPageMatchDebug('scroll-start', {
			attempts,
			text: String(element.textContent || '').trim().slice(0, 160),
		});

		for (let index = 0; index < attempts.length; index += 1) {
			scheduleInlineMatchScrollAttempt(element, scrollingElement, attempts[index], index);
		}
	}

	function highlightFirstPageMatch() {
		const searchParams = getCurrentPageMatchSearchParams();
		if (!searchParams) {
			logPageMatchDebug('skip-missing-url');
			return false;
		}

		const existingHighlight = Array.from(document.querySelectorAll('mark.geweb-ai-inline-match'))
			.find((element) => !element.closest('.geweb-ai-page-match-preview'));
		if (existingHighlight) {
			logPageMatchDebug('reuse-existing-highlight', {
				text: String(existingHighlight.textContent || '').trim().slice(0, 160),
			});
			scrollInlineMatchIntoView(existingHighlight);
			return true;
		}

		const phrase = String(searchParams.get('geweb_ai_match') || '').trim();

		if (!phrase) {
			logPageMatchDebug('skip-missing-phrase');
			return false;
		}

		const candidates = buildInlineMatchCandidates(phrase);
		logPageMatchDebug('start', {
			phrase,
			candidateCount: candidates.length,
			candidates: candidates.slice(0, 8),
		});

		const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, {
			acceptNode(node) {
				const parent = node.parentElement;
				if (!parent || ['SCRIPT', 'STYLE', 'NOSCRIPT', 'TEXTAREA'].includes(parent.tagName)) {
					return NodeFilter.FILTER_REJECT;
				}

				if (!isInlineMatchNodeVisible(node)) {
					return NodeFilter.FILTER_REJECT;
				}

				return normalizeInlineMatchText(node.textContent).length ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
			}
		});

		let matchedNode = null;
		let matchedPhrase = '';
		let inspectedNodeCount = 0;

		while ((matchedNode = walker.nextNode())) {
			inspectedNodeCount += 1;
			const rawText = String(matchedNode.textContent || '');
			const normalizedText = normalizeInlineMatchText(rawText);
			matchedPhrase = candidates.find((candidate) => normalizedText.includes(normalizeInlineMatchText(candidate))) || '';
			if (!matchedPhrase) {
				continue;
			}

			logPageMatchDebug('candidate-found', {
				inspectedNodeCount,
				matchedPhrase,
				rawTextSample: rawText.trim().slice(0, 220),
				parentTag: matchedNode.parentElement?.tagName || '',
			});

			const matchRange = findInlineMatchRangeInText(rawText, matchedPhrase);
			if (!matchRange) {
				logPageMatchDebug('candidate-range-miss', {
					matchedPhrase,
					rawTextSample: rawText.trim().slice(0, 220),
				});
				continue;
			}

			const range = document.createRange();
			range.setStart(matchedNode, matchRange.start);
			range.setEnd(matchedNode, matchRange.start + matchRange.length);
			if (!isInlineMatchRangeVisible(range)) {
				logPageMatchDebug('candidate-invisible-range', {
					matchedPhrase,
					rangeStart: matchRange.start,
					rangeLength: matchRange.length,
					rectCount: Array.from(range.getClientRects()).length,
					parentTag: matchedNode.parentElement?.tagName || '',
				});
				continue;
			}
			selectPageMatchRange(range);
			const highlight = document.createElement('mark');
			highlight.className = 'geweb-ai-inline-match';
			range.surroundContents(highlight);
			logPageMatchDebug('highlight-inserted', {
				matchedPhrase,
				highlightedText: String(highlight.textContent || '').trim(),
				parentTag: highlight.parentElement?.tagName || '',
			});
			showPageMatchPreview(buildPageMatchSnippet(rawText, matchRange.start, matchRange.length));
			scrollInlineMatchIntoView(highlight);
			logPageMatchDebug('success', {
				inspectedNodeCount,
				matchedPhrase,
			});
			return true;
		}

		logPageMatchDebug('no-match-found', {
			phrase,
			inspectedNodeCount,
		});
		return false;
	}

	function stopScheduledPageMatchHighlighting() {
		if (globalThis.__gewebAiPageMatchMutationTimer) {
			globalThis.clearTimeout(globalThis.__gewebAiPageMatchMutationTimer);
			globalThis.__gewebAiPageMatchMutationTimer = null;
		}
		if (globalThis.__gewebAiPageMatchObserver) {
			globalThis.__gewebAiPageMatchObserver.disconnect();
			globalThis.__gewebAiPageMatchObserver = null;
		}
		globalThis.__gewebAiPageMatchScheduled = false;
		logPageMatchDebug('schedule-stop');
	}

	function attemptScheduledPageMatchHighlight() {
		logPageMatchDebug('attempt-start');
		if (highlightFirstPageMatch()) {
			stopScheduledPageMatchHighlighting();
			return true;
		}

		logPageMatchDebug('attempt-no-match-yet');
		return false;
	}

	function runScheduledPageMatchAttempt(delay) {
		if (!globalThis.__gewebAiPageMatchScheduled) {
			return;
		}

		logPageMatchDebug('scheduled-attempt', {
			delay,
		});
		attemptScheduledPageMatchHighlight();
	}

	function scheduleDelayedPageMatchAttempt(delay) {
		globalThis.setTimeout(runScheduledPageMatchAttempt, delay, delay);
	}

	function runDebouncedPageMatchMutationAttempt(observerStartedAt) {
		globalThis.__gewebAiPageMatchMutationTimer = null;
		logPageMatchDebug('observer-attempt', {
			elapsedMs: Date.now() - observerStartedAt,
		});
		attemptScheduledPageMatchHighlight();
	}

	function handlePageMatchMutation(observerStartedAt) {
		if (!globalThis.__gewebAiPageMatchScheduled) {
			return;
		}

		if ((Date.now() - observerStartedAt) > PAGE_MATCH_MAX_OBSERVE_MS) {
			logPageMatchDebug('observer-timeout', {
				elapsedMs: Date.now() - observerStartedAt,
			});
			stopScheduledPageMatchHighlighting();
			return;
		}

		if (globalThis.__gewebAiPageMatchMutationTimer) {
			globalThis.clearTimeout(globalThis.__gewebAiPageMatchMutationTimer);
		}

		globalThis.__gewebAiPageMatchMutationTimer = globalThis.setTimeout(
			runDebouncedPageMatchMutationAttempt,
			PAGE_MATCH_MUTATION_DEBOUNCE_MS,
			observerStartedAt
		);
	}

	function bindPageMatchObserver() {
		if (typeof MutationObserver !== 'function' || !document.body) {
			return;
		}

		const observerStartedAt = Date.now();
		globalThis.__gewebAiPageMatchObserver = new MutationObserver(() => {
			handlePageMatchMutation(observerStartedAt);
		});

		globalThis.__gewebAiPageMatchObserver.observe(document.body, {
			childList: true,
			subtree: true,
			characterData: true,
		});
		logPageMatchDebug('observer-start');
	}

	function runLoadPageMatchAttempt() {
		if (!globalThis.__gewebAiPageMatchScheduled) {
			return;
		}

		logPageMatchDebug('load-attempt');
		attemptScheduledPageMatchHighlight();
	}

	function scheduleLoadPageMatchAttempt() {
		globalThis.setTimeout(runLoadPageMatchAttempt, 250);
	}

	function runPageMatchHardTimeout() {
		if (globalThis.__gewebAiPageMatchScheduled) {
			logPageMatchDebug('schedule-hard-timeout');
			stopScheduledPageMatchHighlighting();
		}
	}

	function scheduleHighlightFirstPageMatch() {
		if (globalThis.__gewebAiPageMatchScheduled) {
			logPageMatchDebug('schedule-skip-already-running');
			return;
		}

		globalThis.__gewebAiPageMatchScheduled = true;
		const attemptDelays = [0, 200, 800, 1800, 3200, 5000, 8000, 12000];
		logPageMatchDebug('schedule-start', {
			attemptDelays,
		});
		for (const delay of attemptDelays) {
			scheduleDelayedPageMatchAttempt(delay);
		}

		bindPageMatchObserver();
		globalThis.addEventListener('load', scheduleLoadPageMatchAttempt, { once: true });
		globalThis.setTimeout(runPageMatchHardTimeout, PAGE_MATCH_MAX_OBSERVE_MS + 1000);
	}

	globalThis.GewebAISearchPageMatch = {
		scheduleHighlightFirstPageMatch,
	};

	const currentPageMatchSearchParams = getCurrentPageMatchSearchParams();
	if (currentPageMatchSearchParams && String(currentPageMatchSearchParams.get('geweb_ai_match') || '').trim() !== '') {
		scheduleHighlightFirstPageMatch();
	}
})();
