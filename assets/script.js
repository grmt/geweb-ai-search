const INLINE_MATCH_MAX_WINDOW = 16;
const INLINE_MATCH_MIN_WINDOW_THRESHOLD = 8;
const SNIPPET_CONTEXT_BEFORE = 90;
const SNIPPET_CONTEXT_AFTER = 110;

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
	const minWindowSize = words.length > INLINE_MATCH_MIN_WINDOW_THRESHOLD ? 4 : 1;

	for (let windowSize = maxWindowSize; windowSize >= minWindowSize; windowSize -= 1) {
		for (let start = 0; start <= words.length - windowSize; start += 1) {
			const candidate = words.slice(start, start + windowSize).join(' ').trim();
			if (candidate && !candidates.includes(candidate)) {
				candidates.push(candidate);
			}
		}
	}

	if (!candidates.length) {
		candidates.push(words.join(' '));
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

	return `${prefix}${before}<mark class="geweb-ai-inline-match">${match}</mark>${after}${suffix}`;
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

function scrollInlineMatchIntoView(element) {
	if (!element) {
		return;
	}

	const scrollingElement = document.scrollingElement || document.documentElement || document.body;
	const attempts = [0, 180, 700];

	attempts.forEach((delay, index) => {
		globalThis.setTimeout(() => {
			const behavior = index === 0 ? 'auto' : 'smooth';
			element.style.scrollMarginTop = '112px';
			element.style.scrollMarginBottom = '48px';

			if (typeof element.scrollIntoView === 'function') {
				element.scrollIntoView({ behavior, block: 'center', inline: 'nearest' });
			}

			getInlineMatchScrollableAncestors(element).forEach((ancestor) => {
				centerInlineMatchInAncestor(element, ancestor, behavior);
			});

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
		}, delay);
	});
}

function highlightFirstPageMatch() {
	const currentUrl = globalThis.location?.href;
	if (!currentUrl) {
		return false;
	}

	if (document.querySelector('mark.geweb-ai-inline-match')) {
		return true;
	}

	let phrase = '';
	try {
		const url = new URL(currentUrl, globalThis.location.origin);
		phrase = String(url.searchParams.get('geweb_ai_match') || '').trim();
	} catch (error) {
		console.debug('Reading geweb_ai_match failed.', error);
		return false;
	}

	if (!phrase) {
		return false;
	}

	const candidates = buildInlineMatchCandidates(phrase);

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

	while ((matchedNode = walker.nextNode())) {
		const rawText = String(matchedNode.textContent || '');
		const normalizedText = normalizeInlineMatchText(rawText);
		matchedPhrase = candidates.find((candidate) => normalizedText.includes(normalizeInlineMatchText(candidate))) || '';
		if (!matchedPhrase) {
			continue;
		}

		const matchRange = findInlineMatchRangeInText(rawText, matchedPhrase);
		if (!matchRange) {
			continue;
		}

		const range = document.createRange();
		range.setStart(matchedNode, matchRange.start);
		range.setEnd(matchedNode, matchRange.start + matchRange.length);
		if (!isInlineMatchRangeVisible(range)) {
			continue;
		}
		selectPageMatchRange(range);
		const highlight = document.createElement('mark');
		highlight.className = 'geweb-ai-inline-match';
		range.surroundContents(highlight);
		showPageMatchPreview(buildPageMatchSnippet(rawText, matchRange.start, matchRange.length));
		scrollInlineMatchIntoView(highlight);
		return true;
	}

	return false;
}

function scheduleHighlightFirstPageMatch() {
	if (globalThis.__gewebAiPageMatchScheduled) {
		return;
	}

	globalThis.__gewebAiPageMatchScheduled = true;
	const attemptDelays = [0, 200, 800, 1800, 3200];
	attemptDelays.forEach((delay) => {
		globalThis.setTimeout(() => {
			if (highlightFirstPageMatch()) {
				globalThis.__gewebAiPageMatchScheduled = false;
			}
		}, delay);
	});

	globalThis.addEventListener('load', () => {
		globalThis.setTimeout(() => {
			highlightFirstPageMatch();
			globalThis.__gewebAiPageMatchScheduled = false;
		}, 250);
	}, { once: true });
}

jQuery(document).ready(($) => {
	let nonceRequest = null;

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

	ensureSearchNonce().catch(() => {
		return null;
	});

	const GewebModal = {
	    ai: document.getElementById('geweb-ai-modal'),

	    init() {
	        if (!this.ai) {
return;
}
	        this.injectAIButtons();
	        this.bindClose();
	        this.openPageViewIfNeeded();
	    },

	    isPageView() {
	        return this.ai?.dataset?.gewebPageView === '1';
	    },

	    buildFrontendAiPageUrl(query, conversationId) {
	        const baseUrl = geweb_aisearch.frontend_ai_page_url || globalThis.location?.href;
	        const url = new URL(baseUrl, globalThis.location?.origin);
	        const trimmedQuery = (query || '').trim();
	        const trimmedConversationId = (conversationId || '').trim();
	        url.searchParams.delete('geweb_ai_chat');
	        url.searchParams.delete('s');

	        if (trimmedQuery) {
	            url.searchParams.set('geweb_ai_query', trimmedQuery);
	        } else {
	            url.searchParams.delete('geweb_ai_query');
	        }

	        if (trimmedConversationId) {
	            url.searchParams.set('geweb_ai_conversation', trimmedConversationId);
	        } else {
	            url.searchParams.delete('geweb_ai_conversation');
	        }

	        return url.toString();
	    },

	    resolveQueryText(rawQuery) {
	        const directQuery = String(rawQuery || '').trim();
	        if (directQuery !== '') {
	            return directQuery;
	        }

	        const currentUrl = globalThis.location?.href;
	        if (!currentUrl) {
	            return '';
	        }

	        try {
	            const url = new URL(currentUrl, globalThis.location?.origin);
	            return String(url.searchParams.get('s') || url.searchParams.get('geweb_ai_query') || '').trim();
	        } catch (error) {
	            console.debug('Resolving query text from URL failed.', error);
	            return '';
	        }
	    },

	    injectAIButtons() {
	        if (this.isPageView()) {
	            return;
	        }

	        $('form').has('input[name="s"]').each((_, form) => {
	            const $form = $(form);
	            if (
	                $form.closest('#wpadminbar').length ||
	                $form.attr('id') === 'adminbarsearch' ||
	                $form.hasClass('ab-item') ||
	                $form.find('#adminbar-search').length
	            ) {
	                return;
	            }

	            const $input = $form.find('input[name="s"]').first();
	            const $searchButton = $form.find('button[type="submit"], input[type="submit"], .search-submit, .wp-block-search__button').first();

	            if (
	                !$input.length ||
	                $form.find('.geweb-ai-trigger').length ||
	                $form.find('.geweb-ai-page-trigger').length
	            ) {
	                return;
	            }

	            if (geweb_aisearch.frontend_ai_interface === 'fullscreen') {
	                const buttonClasses = ['geweb-ai-page-trigger'].join(' ');
	                const $button = $(`<button type="button" class="${buttonClasses}"><span class="geweb-ai-trigger-label" aria-hidden="true">AI</span></button>`);
	                $button.attr('aria-label', t('openAiSearch', 'Open AI Search'));
	                this.matchTriggerButtonSize($button, $searchButton);
	                $button.on('click', () => {
	                    const query = this.resolveQueryText($input.val());
		                    globalThis.location.href = this.buildFrontendAiPageUrl(query, GewebAIChat.getTargetConversationIdForQuery(query));
	                });

	                $form.addClass('geweb-ai-search-form');
	                $input.addClass('geweb-ai-search-input');
	                if ($searchButton.length) {
	                    $searchButton.after($button);
	                } else {
	                    $form.append($button);
	                }
	                return;
	            }

	            const buttonClasses = ['geweb-ai-trigger', 'geweb-ai-trigger--text'].join(' ');
	            const searchWithAiLabel = this.escapeHtml(t('searchWithAi', 'AI search'));
	            const $button = $(`<button type="button" class="${buttonClasses}" aria-label="${this.escapeAttr(searchWithAiLabel)}"><span class="geweb-ai-trigger-label">${searchWithAiLabel}</span></button>`);
	            this.matchTriggerButtonSize($button, $searchButton);
	            $button.on('click', () => {
	                const query = this.resolveQueryText($input.val());
	                this.openAI(query);
	            });

	            $form.addClass('geweb-ai-search-form');
	            $input.addClass('geweb-ai-search-input');
	            $form.append($button);
	        });
	    },

	    openAI(query) {
		        const trimmedQuery = (query || '').trim();
		        GewebAIChat.prepareChatForQuery(trimmedQuery);
	        $('#geweb-ai-query-display').val(GewebAIChat.shouldAutoSubmitQuery(trimmedQuery) ? trimmedQuery : '');
	        GewebAIChat.toggleSubmitButton();
	        document.body.classList.add('no-scroll');
	        if (typeof this.ai.showModal === 'function') {
	            this.ai.showModal();
	        }
	        GewebAIChat.focusInput();

	        if (GewebAIChat.shouldAutoSubmitQuery(trimmedQuery)) {
	            globalThis.setTimeout(() => {
	                GewebAIChat.sendMessage(trimmedQuery);
	            }, 0);
	        }
	    },

	    matchTriggerButtonSize($button, $referenceButton) {
	        if (!$button.length || !$referenceButton.length) {
	            return;
	        }

	        const width = $referenceButton.outerWidth();
	        const height = $referenceButton.outerHeight();

	        if (Number.isFinite(width) && width > 0 && !$button.hasClass('geweb-ai-trigger--text')) {
	            const element = $button.get(0);
	            if (element?.style) {
	                element.style.setProperty('width', `${width}px`, 'important');
	                element.style.setProperty('min-width', `${width}px`, 'important');
	                element.style.setProperty('max-width', `${width}px`, 'important');
	            }
	        }

	        if (Number.isFinite(height) && height > 0) {
	            const element = $button.get(0);
	            if (element?.style) {
	                element.style.setProperty('height', `${height}px`, 'important');
	            }
	        }
	    },

		bindClose() {
			const unlockScroll = () => {
				if (!this.ai.open && !this.isPageView()) {
					document.body.classList.remove('no-scroll');
				}
			};

			$('.close', this.ai).on('click', () => {
				if (this.isPageView()) {
					globalThis.location.href = geweb_aisearch.frontend_ai_exit_url || globalThis.location?.origin;
					return;
				}
				this.ai.close();
				unlockScroll();
			});

			$(this.ai).on('click', (e) => {
				if (!this.isPageView() && e.target === this.ai) {
					this.ai.close();
					unlockScroll();
				}
			});
		},

	    openPageViewIfNeeded() {
	        if (!this.isPageView()) {
	            return;
	        }

	        document.body.classList.add('geweb-ai-page', 'geweb-ai-page-open');
	        this.syncPageViewportOffsetStyles();
	        this.initPageHeightPersistence();
	        this.bindPageToolbarControls();
	        this.ensureSearchPanelMobileDefault();
	        this.ensurePageResizeHandle();
	        this.bindFrontendHeaderSearch();
	        this.bindWorkspacePaneResizers();
	        this.bindMobileWorkspaceNavigation();
	        this.bindMobilePaneFooter();
	        this.bindPanelCollapseButtons();
	        this.bindResponsivePanelCollapse();
	        this.syncPanelCollapseButtons();
	    },

	    getWorkspaceElement() {
	        return this.ai ? this.ai.querySelector('.geweb-ai-workspace') : null;
	    },

	    getWorkspaceAutoCollapseThreshold() {
	        return 834;
	    },

	    getMobilePaneFooterThreshold() {
	        return 767;
	    },

	    isMobileWorkspaceNavigationActive(workspace = this.getWorkspaceElement()) {
	        const isWidthBasedMobile = !!globalThis.matchMedia
	            && globalThis.matchMedia(`(max-width: ${this.getMobilePaneFooterThreshold()}px)`).matches;
	        const isTouchLikeDevice = !!globalThis.matchMedia
	            && globalThis.matchMedia('(hover: none) and (pointer: coarse)').matches;

	        return !!workspace
	            && this.isPageView()
	            && !!globalThis.matchMedia
	            && isWidthBasedMobile
	            && isTouchLikeDevice;
	    },

	    isPseudoFullscreenActive() {
	        return !!document.body?.classList.contains('geweb-ai-page-pseudo-fullscreen');
	    },

	    shouldShowQuestionInfoButton(workspace = this.getWorkspaceElement()) {
	        const hasTouchPoints = Number(globalThis.navigator?.maxTouchPoints || 0) > 0;
	        const isTouchLikeDevice = !!globalThis.matchMedia
	            && (
	                globalThis.matchMedia('(hover: none)').matches
	                || globalThis.matchMedia('(pointer: coarse)').matches
	            );

	        return this.isMobileWorkspaceNavigationActive(workspace) || isTouchLikeDevice || hasTouchPoints;
	    },

	    shouldAutoCollapseDesktopPanels(workspace = this.getWorkspaceElement()) {
	        return !!workspace
	            && this.isPageView()
	            && !this.isMobileWorkspaceNavigationActive(workspace)
	            && workspace.clientWidth <= this.getWorkspaceAutoCollapseThreshold();
	    },

	    getMobileWorkspacePaneIndex(pane) {
	        return {
	            left: 0,
	            main: 1,
	            right: 2,
	        }[pane] ?? 1;
	    },

	    setMobileWorkspacePane(pane, options = {}) {
	        const workspace = this.getWorkspaceElement();
	        const nextPane = ['left', 'main', 'right'].includes(pane) ? pane : 'main';
	        if (!workspace) {
	            return;
	        }

	        const previousPane = ['left', 'main', 'right'].includes(workspace.dataset.mobilePane) ? workspace.dataset.mobilePane : 'main';
	        const isMobile = this.isMobileWorkspaceNavigationActive(workspace);
	        const shouldAnimate = isMobile && !options?.skipAnimation && previousPane !== nextPane;

	        if (shouldAnimate) {
	            const direction = this.getMobileWorkspacePaneIndex(nextPane) > this.getMobileWorkspacePaneIndex(previousPane)
	                ? 'forward'
	                : 'backward';
	            workspace.dataset.mobilePanePrevious = previousPane;
	            workspace.dataset.mobilePaneAnimating = '1';
	            workspace.dataset.mobilePaneDirection = direction;
	            if (workspace._gewebMobilePaneAnimationTimeout) {
	                globalThis.clearTimeout(workspace._gewebMobilePaneAnimationTimeout);
	            }
	        }

	        workspace.dataset.mobilePane = nextPane;
	        this.syncMobilePaneFooter(nextPane);

	        if (shouldAnimate) {
	            workspace._gewebMobilePaneAnimationTimeout = globalThis.setTimeout(() => {
	                delete workspace.dataset.mobilePanePrevious;
	                delete workspace.dataset.mobilePaneAnimating;
	                delete workspace.dataset.mobilePaneDirection;
	            }, 340);
	        }

	        if (options?.focusPane && this.isMobileWorkspaceNavigationActive(workspace)) {
	            const paneSelector = {
	                left: '.geweb-ai-sidebar',
	                main: '.geweb-ai-main-panel',
	                right: '.geweb-ai-sources-panel',
	            }[nextPane];
	            const paneElement = paneSelector ? workspace.querySelector(paneSelector) : null;
	            if (paneElement && typeof paneElement.focus === 'function') {
	                paneElement.focus({ preventScroll: true });
	            }
	        }
	    },

	    moveMobileWorkspacePane(direction) {
	        const workspace = this.getWorkspaceElement();
	        if (!this.isMobileWorkspaceNavigationActive(workspace)) {
	            return;
	        }

	        const currentPane = ['left', 'main', 'right'].includes(workspace.dataset.mobilePane) ? workspace.dataset.mobilePane : 'main';

	        if (currentPane === 'main') {
	            this.setMobileWorkspacePane(direction > 0 ? 'right' : 'left', { focusPane: true });
	            return;
	        }

	        this.setMobileWorkspacePane('main', { focusPane: true });
	    },

	    bindMobileWorkspaceNavigation() {
	        const workspace = this.getWorkspaceElement();
	        if (!workspace || workspace.dataset.gewebMobilePaneBound === '1') {
	            return;
	        }

	        workspace.dataset.gewebMobilePaneBound = '1';
	        this.setMobileWorkspacePane(workspace.dataset.mobilePane || 'main', { skipAnimation: true });

	        const syncMobilePaneState = () => {
	            if (this.isMobileWorkspaceNavigationActive(workspace)) {
	                this.setMobileWorkspacePane(workspace.dataset.mobilePane || 'main', { skipAnimation: true });
	                return;
	            }

	            delete workspace.dataset.mobilePane;
	        };

	        let touchStartX = 0;
	        let touchStartY = 0;

	        workspace.addEventListener('touchstart', (event) => {
	            if (!this.isMobileWorkspaceNavigationActive(workspace) || event.touches.length !== 1) {
	                return;
	            }

	            touchStartX = Number(event.touches[0]?.clientX || 0);
	            touchStartY = Number(event.touches[0]?.clientY || 0);
	        }, { passive: true });

	        workspace.addEventListener('touchend', (event) => {
	            if (!this.isMobileWorkspaceNavigationActive(workspace) || event.changedTouches.length !== 1) {
	                return;
	            }

	            const endX = Number(event.changedTouches[0]?.clientX || 0);
	            const endY = Number(event.changedTouches[0]?.clientY || 0);
	            const deltaX = endX - touchStartX;
	            const deltaY = endY - touchStartY;

	            if (Math.abs(deltaX) < 56 || Math.abs(deltaX) <= Math.abs(deltaY)) {
	                return;
	            }

	            this.moveMobileWorkspacePane(deltaX < 0 ? 1 : -1);
	        }, { passive: true });

	        if (typeof globalThis.matchMedia === 'function') {
	            const mediaQuery = globalThis.matchMedia(`(max-width: ${this.getWorkspaceAutoCollapseThreshold()}px)`);
	            if (typeof mediaQuery.addEventListener === 'function') {
	                mediaQuery.addEventListener('change', syncMobilePaneState);
	            } else if (typeof mediaQuery.addListener === 'function') {
	                mediaQuery.addListener(syncMobilePaneState);
	            }
	        }

	        syncMobilePaneState();
	    },

	    bindMobilePaneFooter() {
	        const $tabs = $(this.ai).find('.geweb-ai-mobile-pane-tab');
	        if (!$tabs.length) {
	            return;
	        }

	        $tabs.each((_, element) => {
	            const $tab = $(element);
	            if ($tab.data('gewebMobilePaneBound')) {
	                return;
	            }

	            $tab.data('gewebMobilePaneBound', true);
	            $tab.on('click', (event) => {
	                event.preventDefault();
	                const targetPane = String($tab.data('mobile-pane-target') || 'main');
	                this.setMobileWorkspacePane(targetPane, { focusPane: true });
	                this.syncPanelCollapseButtons();
	            });
	        });

	        this.syncMobilePaneFooter(this.getWorkspaceElement()?.dataset?.mobilePane || 'main');
	    },

	    syncMobilePaneFooter(activePane = 'main') {
	        const workspace = this.getWorkspaceElement();
	        const isMobile = this.isMobileWorkspaceNavigationActive(workspace);
	        const isSmallScreen = !!globalThis.matchMedia
	            && globalThis.matchMedia(`(max-width: ${this.getMobilePaneFooterThreshold()}px)`).matches;
	        const $footer = $(this.ai).find('.geweb-ai-mobile-pane-footer');
	        const $tabs = $footer.find('.geweb-ai-mobile-pane-tab');
	        const $mobileMenuButton = $(this.ai).find('#geweb-ai-toggle-mobile-menu');

	        $footer.toggleClass('is-visible', isMobile && isSmallScreen);
	        $tabs.each((_, element) => {
	            const $tab = $(element);
	            const isActive = String($tab.data('mobile-pane-target') || '') === activePane;
	            $tab.toggleClass('is-active', isActive);
	            $tab.attr('aria-pressed', isActive ? 'true' : 'false');
	        });
	        if ($mobileMenuButton.length) {
	            const leftActive = activePane === 'left';
	            $mobileMenuButton.toggleClass('is-active', isMobile && leftActive);
	            $mobileMenuButton.attr('aria-expanded', isMobile && leftActive ? 'true' : 'false');
	            $mobileMenuButton.attr('aria-label', leftActive ? 'Show answer panel' : 'Show chats panel');
	            $mobileMenuButton.attr('title', leftActive ? 'Show answer panel' : 'Show chats panel');
	        }
	    },

	    setWorkspacePanelCollapsed(workspace, side, collapsed) {
	        if (!workspace || (side !== 'left' && side !== 'right')) {
	            return;
	        }

	        workspace.classList.toggle(`is-${side}-collapsed`, !!collapsed);
	    },

	    applyResponsivePanelCollapse() {
	        const workspace = this.getWorkspaceElement();
	        if (!workspace) {
	            return;
	        }

	        if (this.isMobileWorkspaceNavigationActive(workspace)) {
	            if (workspace.dataset.autoCollapseMode !== 'mobile') {
	                workspace.dataset.manualLeftCollapsed = workspace.classList.contains('is-left-collapsed') ? '1' : '0';
	                workspace.dataset.manualRightCollapsed = workspace.classList.contains('is-right-collapsed') ? '1' : '0';
	            }
	            workspace.dataset.autoCollapseActive = '1';
	            workspace.dataset.autoCollapseMode = 'mobile';
	            this.setWorkspacePanelCollapsed(workspace, 'left', false);
	            this.setWorkspacePanelCollapsed(workspace, 'right', false);
	            this.setMobileWorkspacePane(workspace.dataset.mobilePane || 'main');
	            this.syncPanelCollapseButtons();
	            return;
	        }

	        if (this.shouldAutoCollapseDesktopPanels(workspace)) {
	            if (workspace.dataset.autoCollapseMode !== 'desktop') {
	                workspace.dataset.manualLeftCollapsed = workspace.classList.contains('is-left-collapsed') ? '1' : '0';
	                workspace.dataset.manualRightCollapsed = workspace.classList.contains('is-right-collapsed') ? '1' : '0';
	                this.setWorkspacePanelCollapsed(workspace, 'left', true);
	                this.setWorkspacePanelCollapsed(workspace, 'right', true);
	            }

	            workspace.dataset.autoCollapseActive = '1';
	            workspace.dataset.autoCollapseMode = 'desktop';
	            delete workspace.dataset.mobilePane;
	            this.syncPanelCollapseButtons();
	            return;
	        }

	        const autoCollapseWasActive = workspace.dataset.autoCollapseActive === '1';

	        if (autoCollapseWasActive) {
	            this.setWorkspacePanelCollapsed(workspace, 'left', workspace.dataset.manualLeftCollapsed === '1');
	            this.setWorkspacePanelCollapsed(workspace, 'right', workspace.dataset.manualRightCollapsed === '1');
	            delete workspace.dataset.autoCollapseActive;
	            delete workspace.dataset.autoCollapseMode;
	            delete workspace.dataset.manualLeftCollapsed;
	            delete workspace.dataset.manualRightCollapsed;
	        }

	        this.syncPanelCollapseButtons();
	    },

	    bindResponsivePanelCollapse() {
	        const workspace = this.getWorkspaceElement();
	        if (!workspace || workspace.dataset.gewebResponsiveCollapseBound === '1') {
	            return;
	        }

	        workspace.dataset.gewebResponsiveCollapseBound = '1';

	        const apply = () => {
	            this.applyResponsivePanelCollapse();
	        };

	        if (typeof globalThis.ResizeObserver === 'function') {
	            const observer = new globalThis.ResizeObserver(() => apply());
	            observer.observe(workspace);
	            workspace._gewebResponsiveCollapseObserver = observer;
	        } else {
	            globalThis.addEventListener('resize', apply);
	        }

	        apply();
	    },

	    getPageViewHeightStorageKey() {
	        const pathname = globalThis.location?.pathname || 'default';
	        return `geweb_ai_page_height:${pathname}`;
	    },

	    getPageViewMinHeight() {
	        return 360;
	    },

	    getPageViewViewportTopOffset() {
	        const adminBar = document.getElementById('wpadminbar');
	        if (!adminBar) {
	            return 0;
	        }

	        const style = globalThis.getComputedStyle ? globalThis.getComputedStyle(adminBar) : null;
	        if (style?.display === 'none' || style?.visibility === 'hidden') {
	            return 0;
	        }

	        const rect = adminBar.getBoundingClientRect();
	        return rect.bottom > 0 ? Math.round(rect.bottom) : 0;
	    },

	    getPageViewViewportHeight() {
	        const layoutViewportHeight = globalThis.innerHeight || this.getPageViewMinHeight();
	        const visualViewportHeight = Number(globalThis.visualViewport?.height || 0);
	        const rawViewportHeight = visualViewportHeight > 0
	            ? Math.min(layoutViewportHeight, Math.round(visualViewportHeight))
	            : layoutViewportHeight;
	        const availableHeight = rawViewportHeight - this.getPageViewViewportTopOffset();
	        return Math.max(this.getPageViewMinHeight(), availableHeight);
	    },

	    isPageViewportKeyboardCompacted() {
	        const layoutViewportHeight = Number(globalThis.innerHeight || 0);
	        const visualViewportHeight = Number(globalThis.visualViewport?.height || 0);
	        if (layoutViewportHeight <= 0 || visualViewportHeight <= 0) {
	            return false;
	        }

	        return (layoutViewportHeight - visualViewportHeight) >= 120;
	    },

	    syncPageViewportOffsetStyles() {
	        const body = document.body;
	        const root = document.documentElement;
	        if (!body || !root || !body.classList.contains('geweb-ai-page')) {
	            return;
	        }

	        const topOffset = this.getPageViewViewportTopOffset();
	        root.style.setProperty('--geweb-ai-page-top-offset', `${topOffset}px`);
	    },

	    getPageViewMaxHeight() {
	        const viewportHeight = this.getPageViewViewportHeight();
	        return Math.max(this.getPageViewMinHeight(), viewportHeight + 720);
	    },

	    clampPageViewHeight(rawHeight) {
	        const height = Number(rawHeight);
	        if (!Number.isFinite(height) || height <= 0) {
	            return null;
	        }

	        return Math.max(this.getPageViewMinHeight(), Math.min(this.getPageViewMaxHeight(), Math.round(height)));
	    },

	    readStoredPageViewHeight() {
	        if (!globalThis.localStorage) {
	            return null;
	        }

	        try {
	            return this.clampPageViewHeight(globalThis.localStorage.getItem(this.getPageViewHeightStorageKey()));
	        } catch (error) {
	            console.debug('Reading stored AI page height failed.', error);
	            return null;
	        }
	    },

	    persistPageViewHeight(rawHeight) {
	        if (!globalThis.localStorage) {
	            return;
	        }

	        const height = this.clampPageViewHeight(rawHeight);
	        if (!height) {
	            return;
	        }

	        try {
	            globalThis.localStorage.setItem(this.getPageViewHeightStorageKey(), String(height));
	        } catch (error) {
	            console.debug('Saving stored AI page height failed.', error);
	        }
	    },

	    clearStoredPageViewHeight() {
	        if (!globalThis.localStorage) {
	            return;
	        }

	        try {
	            globalThis.localStorage.removeItem(this.getPageViewHeightStorageKey());
	        } catch (error) {
	            console.debug('Clearing stored AI page height failed.', error);
	        }
	    },

	    resetPageViewToViewport() {
	        if (!this.isPageView() || !this.ai) {
	            return;
	        }

	        this.clearStoredPageViewHeight();
	        this.ai.style.height = '';
	        this.applyPageViewHeight(this.getPageViewViewportHeight(), { persist: false });
	        globalThis.requestAnimationFrame(() => {
	            this.alignPageViewBottomToViewport();
	        });
	    },

	    schedulePageViewViewportSync(options = {}) {
	        if (!this.isPageView() || !this.ai) {
	            return;
	        }

	        const delays = Array.isArray(options.delays) && options.delays.length
	            ? options.delays
	            : [0, 120, 320];
	        const shouldAlignBottom = options.alignBottom !== false;

	        delays.forEach((delay) => {
	            globalThis.setTimeout(() => {
	                this.syncPageViewportOffsetStyles();
	                this.syncPageViewHeightToViewport();
	                if (shouldAlignBottom) {
	                    this.alignPageViewBottomToViewport();
	                }
	            }, Math.max(0, Number(delay) || 0));
	        });
	    },

	    applyPageViewHeight(rawHeight, options = {}) {
	        const height = this.clampPageViewHeight(rawHeight);
	        if (!height || !this.ai) {
	            return;
	        }

	        this.ai.style.height = `${height}px`;

	        if (options.persist !== false) {
	            this.persistPageViewHeight(height);
	        }
	    },

	    alignPageViewBottomToViewport(options = {}) {
	        if (!this.isPageView() || !this.ai) {
	            return;
	        }

	        const align = (behavior) => {
	            if (!this.ai) {
	                return;
	            }

	            const viewportHeight = globalThis.innerHeight || 0;
	            const topOffset = this.getPageViewViewportTopOffset();
	            if (!viewportHeight) {
	                return;
	            }

	            if (typeof this.ai.scrollIntoView === 'function') {
	                this.ai.scrollIntoView({
	                    block: 'end',
	                    behavior,
	                });
	            }

	            const rect = this.ai.getBoundingClientRect();
	            const correction = rect.bottom - viewportHeight;
	            if (Math.abs(correction) > 1 && typeof globalThis.scrollBy === 'function') {
	                globalThis.scrollBy({
	                    top: correction,
	                    behavior,
	                });
	            }

	            const nextRect = this.ai.getBoundingClientRect();
	            if (nextRect.top < topOffset) {
	                const overflow = topOffset - nextRect.top;
	                const nextHeight = this.clampPageViewHeight(nextRect.height - overflow);
	                if (nextHeight && Math.abs(nextHeight - nextRect.height) > 1) {
	                    this.ai.style.height = `${nextHeight}px`;
	                }
	            }
	        };

	        const behavior = options.behavior || 'auto';
	        align(behavior);
	        globalThis.requestAnimationFrame(() => {
	            align(behavior);
	        });
	    },

	    syncPageViewHeightToViewport() {
	        if (!this.isPageView() || !this.ai) {
	            return;
	        }

	        const viewportHeight = this.getPageViewViewportHeight();
	        if (this.isPageViewportKeyboardCompacted()) {
	            this.ai.style.height = `${viewportHeight}px`;
	            return;
	        }

	        const storedHeight = this.readStoredPageViewHeight();
	        if (storedHeight === null) {
	            this.ai.style.height = `${viewportHeight}px`;
	            return;
	        }

	        const targetHeight = Math.max(storedHeight, viewportHeight);
	        this.applyPageViewHeight(targetHeight, { persist: true });
	    },

	    initPageHeightPersistence() {
	        if (!this.ai || this.ai.dataset.gewebAiPageHeightBound === '1') {
	            return;
	        }

	        this.ai.dataset.gewebAiPageHeightBound = '1';

	        const storedHeight = this.readStoredPageViewHeight();
	        if (storedHeight) {
	            this.applyPageViewHeight(storedHeight, { persist: false });
	        } else {
	            this.applyPageViewHeight(this.getPageViewViewportHeight(), { persist: false });
	            if (this.resolveQueryText('') === '') {
	                globalThis.requestAnimationFrame(() => {
	                    this.alignPageViewBottomToViewport();
	                });
	            }
	        }

	        if (typeof globalThis.ResizeObserver === 'function') {
	            const observer = new globalThis.ResizeObserver((entries) => {
	                const nextHeight = entries[0]?.contentRect?.height;
	                const viewportHeight = this.getPageViewViewportHeight();
	                if (this.isPageViewportKeyboardCompacted()) {
	                    return;
	                }

	                const measuredHeight = Number.isFinite(nextHeight)
	                    ? nextHeight
	                    : this.ai?.getBoundingClientRect?.().height;
	                const shouldPersist = Boolean(this.ai?.style?.height)
	                    || (Number.isFinite(measuredHeight) && measuredHeight < viewportHeight - 4);

	                if (!shouldPersist) {
	                    return;
	                }

	                this.persistPageViewHeight(Math.max(viewportHeight, Number(measuredHeight) || 0));
	            });

	            observer.observe(this.ai);
	        }

	        globalThis.addEventListener('resize', () => {
	            this.syncPageViewportOffsetStyles();
	            this.syncPageViewHeightToViewport();
	        });

	        if (globalThis.visualViewport) {
	            const syncViewport = () => {
	                this.syncPageViewportOffsetStyles();
	                this.syncPageViewHeightToViewport();
	            };
	            globalThis.visualViewport.addEventListener('resize', syncViewport);
	            globalThis.visualViewport.addEventListener('scroll', syncViewport);
	        }

	        globalThis.addEventListener('scroll', () => {
	            this.syncPageViewportOffsetStyles();
	        }, { passive: true });
	    },

	    bindPageToolbarControls() {
	        const mobileMenuButton = document.getElementById('geweb-ai-toggle-mobile-menu');
	        if (mobileMenuButton && mobileMenuButton.dataset.gewebAiBound !== '1') {
	            mobileMenuButton.dataset.gewebAiBound = '1';
	            mobileMenuButton.addEventListener('click', (event) => {
	                event.preventDefault();
	                const workspace = this.getWorkspaceElement();
	                if (!this.isMobileWorkspaceNavigationActive(workspace)) {
	                    return;
	                }

	                const nextPane = workspace?.dataset?.mobilePane === 'left' ? 'main' : 'left';
	                this.setMobileWorkspacePane(nextPane, { focusPane: true });
	                this.syncPanelCollapseButtons();
	            });
	        }

	        const alignButton = document.getElementById('geweb-ai-align-workspace');
	        if (alignButton && alignButton.dataset.gewebAiBound !== '1') {
	            alignButton.dataset.gewebAiBound = '1';
	            alignButton.addEventListener('click', (event) => {
	                event.preventDefault();
	                this.resetPageViewToViewport();
			});
		}

		const searchToggleButton = document.getElementById('geweb-ai-toggle-search-panel');
		if (searchToggleButton && searchToggleButton.dataset.gewebAiBound !== '1') {
			searchToggleButton.dataset.gewebAiBound = '1';
			searchToggleButton.addEventListener('click', (event) => {
				event.preventDefault();
				this.toggleSearchPanelVisibility();
			});
		}

		const fullscreenButton = document.getElementById('geweb-ai-toggle-fullscreen');
		if (fullscreenButton && fullscreenButton.dataset.gewebAiBound !== '1') {
			fullscreenButton.dataset.gewebAiBound = '1';
			fullscreenButton.addEventListener('click', async (event) => {
				event.preventDefault();
				await this.togglePageFullscreen();
			});

			const syncFullscreenState = () => this.syncPageToolbarFullscreenState(fullscreenButton);
			document.addEventListener('fullscreenchange', syncFullscreenState);
			document.addEventListener('webkitfullscreenchange', syncFullscreenState);
			syncFullscreenState();
		}
	},

	    getPageFullscreenElement() {
	        return document.fullscreenElement || document.webkitFullscreenElement || null;
	    },

	    isPageFullscreenActive() {
	        return this.getPageFullscreenElement() === this.ai || this.isPseudoFullscreenActive();
	    },

	    syncPageToolbarFullscreenState(fullscreenButton = document.getElementById('geweb-ai-toggle-fullscreen')) {
	        if (!fullscreenButton) {
	            return;
	        }

	        const isActive = this.isPageFullscreenActive();
	        const nextLabel = isActive ? 'Exit fullscreen' : 'Enter fullscreen';
	        const nextIcon = isActive ? '🗗' : '⛶';
	        fullscreenButton.classList.toggle('is-active', isActive);
	        fullscreenButton.setAttribute('aria-label', nextLabel);
	        fullscreenButton.setAttribute('title', nextLabel);
	        const icon = fullscreenButton.querySelector('.geweb-ai-page-toolbar-button-icon');
	        if (icon) {
	            icon.textContent = nextIcon;
	        }
	    },

	    async togglePageFullscreen() {
	        if (!this.ai) {
	            return;
	        }

	        try {
	            if (this.isPseudoFullscreenActive()) {
	                document.body?.classList.remove('geweb-ai-page-pseudo-fullscreen');
	                this.syncPageViewportOffsetStyles();
	                this.syncPageViewHeightToViewport();
	                return;
	            }

	            if (this.getPageFullscreenElement() === this.ai) {
	                if (document.exitFullscreen) {
	                    await document.exitFullscreen();
	                } else if (document.webkitExitFullscreen) {
	                    document.webkitExitFullscreen();
	                }
	                return;
	            }

	            if (this.ai.requestFullscreen) {
	                await this.ai.requestFullscreen();
	            } else if (this.ai.webkitRequestFullscreen) {
	                this.ai.webkitRequestFullscreen();
	            } else {
	                document.body?.classList.add('geweb-ai-page-pseudo-fullscreen');
	                this.syncPageViewportOffsetStyles();
	                this.syncPageViewHeightToViewport();
	            }
	        } catch (error) {
	            document.body?.classList.toggle('geweb-ai-page-pseudo-fullscreen');
	            this.syncPageViewportOffsetStyles();
	            this.syncPageViewHeightToViewport();
	            console.debug('Toggling page fullscreen failed.', error);
	        } finally {
	            this.syncPageToolbarFullscreenState();
	        }
	    },

	    togglePageAdminBarVisibility() {
	        const body = document.body;
	        if (!body || !body.classList.contains('geweb-ai-page')) {
	            return;
	        }

	        body.classList.toggle('geweb-ai-page-hide-adminbar');
	        this.syncPageViewportOffsetStyles();
	        this.syncPageViewHeightToViewport();
	    },

	toggleSearchPanelVisibility() {
		const $searchPanel = $('.geweb-ai-search-results-panel');
		if (!$searchPanel.length) {
			return;
		}

		const willHide = !$searchPanel.hasClass('is-hidden');
		$searchPanel.toggleClass('is-hidden', willHide);

		if (!willHide) {
			$searchPanel.removeClass('is-collapsed');
		}

		this.syncPanelCollapseButtons();
	},

	ensureSearchPanelMobileDefault() {
		const workspace = this.getWorkspaceElement();
		if (this.isMobileWorkspaceNavigationActive(workspace)) {
			const $searchPanel = $('.geweb-ai-search-results-panel');
			if ($searchPanel.length && !$searchPanel.hasClass('is-hidden')) {
				$searchPanel.addClass('is-hidden');
			}
		}
	},

	ensurePageResizeHandle() {
		if (!this.ai || this.ai.querySelector('.geweb-ai-page-resize-handle')) {
			return;
		}

		const handle = document.createElement('button');
		handle.type = 'button';
		handle.className = 'geweb-ai-page-resize-handle';
		handle.setAttribute('aria-label', 'Resize AI search window');
		handle.setAttribute('title', 'Drag to resize the AI search window');
		handle.innerHTML = '<span class="geweb-ai-page-resize-handle-bar" aria-hidden="true"></span>';
		this.ai.appendChild(handle);
		this.bindPageResizeHandle(handle);
	},

	    bindPageResizeHandle(handle) {
	        if (!handle || handle.dataset.gewebAiResizeBound === '1') {
	            return;
	        }

	        handle.dataset.gewebAiResizeBound = '1';
	        const getPointerPageY = (event) => Number(event?.clientY || 0) + (globalThis.scrollY || 0);

	        const stopResize = () => {
	            document.body.classList.remove('geweb-ai-page-resizing');
	            handle.classList.remove('is-active');
	            globalThis.removeEventListener('pointermove', onPointerMove);
	            globalThis.removeEventListener('pointerup', stopResize);
	            globalThis.removeEventListener('pointercancel', stopResize);
	        };

	        const onPointerMove = (event) => {
	            if (!this.ai) {
	                return;
	            }

	            const edgeThreshold = 32;
	            if (Number(event.clientY) >= (globalThis.innerHeight || 0) - edgeThreshold && typeof globalThis.scrollBy === 'function') {
	                globalThis.scrollBy({ top: 18, behavior: 'auto' });
	            } else if (Number(event.clientY) <= edgeThreshold && typeof globalThis.scrollBy === 'function') {
	                globalThis.scrollBy({ top: -18, behavior: 'auto' });
	            }

	            const deltaY = getPointerPageY(event) - startY;
	            this.applyPageViewHeight(startHeight + deltaY);
	        };

	        let startY = 0;
	        let startHeight = 0;

	        handle.addEventListener('pointerdown', (event) => {
	            if (!this.ai) {
	                return;
	            }

	            event.preventDefault();
	            startY = getPointerPageY(event);
	            startHeight = this.ai.getBoundingClientRect().height;
	            document.body.classList.add('geweb-ai-page-resizing');
	            handle.classList.add('is-active');
	            globalThis.addEventListener('pointermove', onPointerMove);
	            globalThis.addEventListener('pointerup', stopResize);
	            globalThis.addEventListener('pointercancel', stopResize);
	        });

	        handle.addEventListener('dblclick', (event) => {
	            if (!this.ai) {
	                return;
	            }

	            event.preventDefault();
	            this.resetPageViewToViewport();
	        });
	    },

	    bindWorkspacePaneResizers() {
	        const workspace = this.ai ? this.ai.querySelector('.geweb-ai-workspace') : null;
	        if (!workspace || globalThis.innerWidth <= this.getWorkspaceAutoCollapseThreshold()) {
	            return;
	        }

	        const minLeft = 180;
	        const maxLeft = 420;
	        const minRight = 220;
	        const maxRight = 640;
	        const minMain = 420;

	        const clamp = (value, min, max) => Math.max(min, Math.min(max, value));

	        const setPaneWidth = (side, rawWidth) => {
	            const totalWidth = workspace.getBoundingClientRect().width;
	            if (!totalWidth) {
	                return;
	            }

	            if (side === 'left') {
	                const maxAllowed = Math.min(maxLeft, totalWidth - minRight - minMain - 32);
	                const nextWidth = clamp(rawWidth, minLeft, Math.max(minLeft, maxAllowed));
	                workspace.style.setProperty('--geweb-ai-left-pane-width', `${Math.round(nextWidth)}px`);
	                workspace.dataset.gewebAiLeftPaneRatio = String(nextWidth / totalWidth);
	                return;
	            }

	            const maxAllowed = Math.min(maxRight, totalWidth - minLeft - minMain - 32);
	            const nextWidth = clamp(rawWidth, minRight, Math.max(minRight, maxAllowed));
	            workspace.style.setProperty('--geweb-ai-right-pane-width', `${Math.round(nextWidth)}px`);
	            workspace.dataset.gewebAiRightPaneRatio = String(nextWidth / totalWidth);
	        };

	        this.syncWorkspacePaneWidths(workspace, setPaneWidth);
	        this.bindWorkspacePaneResizeSync(workspace, setPaneWidth);

	        workspace.querySelectorAll('.geweb-ai-pane-resizer').forEach((handle) => {
	            this.bindWorkspacePaneResizeHandle(handle, workspace, setPaneWidth);
	        });
	    },

	    bindWorkspacePaneResizeSync(workspace, setPaneWidth) {
	        if (workspace.dataset.gewebAiResizeSyncBound === '1') {
	            return;
	        }

	        workspace.dataset.gewebAiResizeSyncBound = '1';
	        globalThis.addEventListener('resize', () => {
	            this.syncWorkspacePaneWidths(workspace, setPaneWidth);
	        });
	    },

	    syncWorkspacePaneWidths(workspace, setPaneWidth) {
	        const totalWidth = workspace.getBoundingClientRect().width;
	        if (!totalWidth) {
	            return;
	        }

	        const leftRatio = Number(workspace.dataset.gewebAiLeftPaneRatio);
	        const rightRatio = Number(workspace.dataset.gewebAiRightPaneRatio);
	        const leftWidth = Number.parseFloat(getComputedStyle(workspace).getPropertyValue('--geweb-ai-left-pane-width'));
	        const rightWidth = Number.parseFloat(getComputedStyle(workspace).getPropertyValue('--geweb-ai-right-pane-width'));

	        setPaneWidth('left', Number.isFinite(leftRatio) && leftRatio > 0 ? leftRatio * totalWidth : leftWidth);
	        setPaneWidth('right', Number.isFinite(rightRatio) && rightRatio > 0 ? rightRatio * totalWidth : rightWidth);
	    },

	    bindWorkspacePaneResizeHandle(handle, workspace, setPaneWidth) {
	        const side = handle.dataset.resizeTarget;
	        if (!side) {
	            return;
	        }

	        handle.addEventListener('pointerdown', (event) => {
	            this.startWorkspacePaneResize(event, handle, side, workspace, setPaneWidth);
	        });
	    },

	    startWorkspacePaneResize(event, handle, side, workspace, setPaneWidth) {
	        event.preventDefault();
	        const rect = workspace.getBoundingClientRect();
	        handle.classList.add('is-active');
	        document.body.style.cursor = 'ew-resize';

	        const move = (moveEvent) => {
	            const width = side === 'left'
	                ? moveEvent.clientX - rect.left
	                : rect.right - moveEvent.clientX;
	            setPaneWidth(side, width);
	        };

	        const stop = () => {
	            handle.classList.remove('is-active');
	            document.body.style.cursor = '';
	            globalThis.removeEventListener('pointermove', move);
	            globalThis.removeEventListener('pointerup', stop);
	        };

	        globalThis.addEventListener('pointermove', move);
	        globalThis.addEventListener('pointerup', stop);
	    },

	    bindPanelCollapseButtons() {
	        const workspace = this.getWorkspaceElement();
	        const $searchPanel = $('.geweb-ai-search-results-panel');
	        if (!workspace) {
	            return;
	        }

	        $(this.ai).find('.geweb-ai-panel-collapse').on('click', function() {
	            const target = $(this).data('panel-toggle');
	            if (target === 'left') {
	                if (GewebModal.isMobileWorkspaceNavigationActive(workspace)) {
	                    GewebModal.setMobileWorkspacePane(workspace.dataset.mobilePane === 'left' ? 'main' : 'left', { focusPane: true });
	                    GewebModal.syncPanelCollapseButtons();
	                    return;
	                }
	                workspace.classList.toggle('is-left-collapsed');
	            } else if (target === 'right') {
	                if (GewebModal.isMobileWorkspaceNavigationActive(workspace)) {
	                    GewebModal.setMobileWorkspacePane(workspace.dataset.mobilePane === 'right' ? 'main' : 'right', { focusPane: true });
	                    GewebModal.syncPanelCollapseButtons();
	                    return;
	                }
	                workspace.classList.toggle('is-right-collapsed');
			} else if (target === 'search') {
				$searchPanel.removeClass('is-hidden');
				$searchPanel.toggleClass('is-collapsed');
			}

			GewebModal.syncPanelCollapseButtons();
		});
	},

	    syncPanelCollapseButtons() {
	        const workspace = this.ai ? this.ai.querySelector('.geweb-ai-workspace') : null;
	        const leftCollapsed = !!workspace?.classList.contains('is-left-collapsed');
	        const rightCollapsed = !!workspace?.classList.contains('is-right-collapsed');
	        const mobilePane = workspace?.dataset.mobilePane || 'main';
	        const mobileNavigationActive = this.isMobileWorkspaceNavigationActive(workspace);
	        const $searchPanel = $('.geweb-ai-search-results-panel');
	        const searchHidden = $searchPanel.hasClass('is-hidden');
	        const searchCollapsed = $searchPanel.hasClass('is-collapsed');
	        const searchExpanded = !searchHidden && !searchCollapsed;

	        const applyButtonState = ($button, expanded, icon, label) => {
	            $button.attr('aria-expanded', expanded ? 'true' : 'false');
	            $button.attr('aria-label', label);
	            $button.attr('title', label);
	            $button.find('.geweb-ai-panel-collapse-icon').text(icon);
	        };

	        applyButtonState(
	            $(this.ai).find('.geweb-ai-panel-collapse[data-panel-toggle="left"]'),
	            mobileNavigationActive ? mobilePane === 'left' : !leftCollapsed,
	            mobileNavigationActive ? (mobilePane === 'left' ? '▶' : '◀') : (leftCollapsed ? '▶' : '◀'),
	            mobileNavigationActive
	                ? (mobilePane === 'left' ? 'Show answer panel' : 'Show chats panel')
	                : (leftCollapsed ? 'Expand chats panel' : 'Collapse chats panel')
	        );
	        applyButtonState(
	            $(this.ai).find('.geweb-ai-panel-collapse[data-panel-toggle="right"]'),
	            mobileNavigationActive ? mobilePane === 'right' : !rightCollapsed,
	            mobileNavigationActive ? (mobilePane === 'right' ? '◀' : '▶') : (rightCollapsed ? '◀' : '▶'),
	            mobileNavigationActive
	                ? (mobilePane === 'right' ? 'Show answer panel' : 'Show sources panel')
	                : (rightCollapsed ? 'Expand sources panel' : 'Collapse sources panel')
	        );
	        applyButtonState(
	            $(this.ai).find('.geweb-ai-panel-collapse[data-panel-toggle="search"]'),
	            searchExpanded,
	            searchExpanded ? '▴' : '▾',
	            searchExpanded ? 'Hide classic search results' : 'Show classic search results'
	        );
	        const $toolbarSearchButton = $(this.ai).find('#geweb-ai-toggle-search-panel');
	        if ($toolbarSearchButton.length) {
	            const toolbarLabel = searchHidden ? 'Show classic search results' : 'Hide classic search results';
	            $toolbarSearchButton.attr('aria-expanded', searchHidden ? 'false' : 'true');
	            $toolbarSearchButton.attr('aria-label', toolbarLabel);
	            $toolbarSearchButton.attr('title', toolbarLabel);
	            $toolbarSearchButton.toggleClass('is-active', !searchHidden);
	        }
	        this.syncMobilePaneFooter(mobilePane);
	    },

	    bindFrontendHeaderSearch() {
	        $('form').has('input[name="s"]').each((_, form) => {
	            const $form = $(form);
	            const isInlineWorkspaceSearch = $form.hasClass('geweb-ai-inline-search-form');
	            if ($form.closest('#geweb-ai-modal').length && !isInlineWorkspaceSearch) {
	                return;
	            }

	            if ($form.data('gewebAiSearchBound')) {
	                return;
	            }

	            const $input = $form.find('input[name="s"]').first();
	            if (!$input.length) {
	                return;
	            }

	            const currentWorkspaceQuery = this.resolveQueryText('');
	            if (currentWorkspaceQuery !== '' && String($input.val() || '').trim() === '') {
	                $input.val(currentWorkspaceQuery);
	            }

	            const navigateToHeaderSearchQuery = (query) => {
	                globalThis.location.href = this.buildFrontendAiPageUrl(query, GewebAIChat.getTargetConversationIdForQuery(query));
	            };

	            const syncCompactPlaceholderState = () => {
	                $input.toggleClass('geweb-ai-search-input--compact-placeholder', String($input.val() || '').trim() === '');
	            };

	            const clearWorkspaceResultsIfInputIsEmpty = () => {
	                const visibleQuery = String($input.val() || '').trim();
	                const currentQuery = this.resolveQueryText('');
	                if (visibleQuery !== '' || currentQuery === '') {
	                    return;
	                }

	                navigateToHeaderSearchQuery('');
	            };

	            $form.addClass('geweb-ai-workspace-search-form');
	            $input.addClass('geweb-ai-workspace-search-input');
	            $input.attr('placeholder', t('searchResultsIntro', 'Use your normal site search above to update these WordPress results without leaving the AI workspace.'));
	            syncCompactPlaceholderState();
	            $input.on('input', syncCompactPlaceholderState);
	            $input.on('search', clearWorkspaceResultsIfInputIsEmpty);
	            $input.on('change', clearWorkspaceResultsIfInputIsEmpty);

	            $form.data('gewebAiSearchBound', true);
	            $form.on('submit', (event) => {
	                event.preventDefault();
	                const query = String($input.val() || '').trim();
		                navigateToHeaderSearchQuery(query);
	            });

	            if (isInlineWorkspaceSearch) {
	                const $searchPanel = $('.geweb-ai-search-results-panel');
	                if ($searchPanel.length) {
	                    $searchPanel.removeClass('is-hidden is-collapsed');
	                    this.syncPanelCollapseButtons();
	                }
	            }

	            globalThis.setTimeout(() => {
	                syncCompactPlaceholderState();
	            }, 0);
	        });
	    },

	    escapeAttr(text) {
	        return String(text).replaceAll('&', '&amp;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
	    },

	};

		const GewebAIChat = {
		    $textarea: $('#geweb-ai-query-display'),
		    $submitBtn: $('#geweb-ask-ai-submit'),
		    $modelSelector: $('#geweb-ai-model-selector'),
		    $settingsToggle: $('[data-geweb-temp-settings-toggle="1"]'),
		    $settingsPanel: $('#geweb-ai-temporary-settings-panel'),
		    $resetSettingsBtn: $('#geweb-ai-reset-temp-settings'),
		    $resetModelBtn: $('#geweb-ai-reset-temp-model'),
		    $resetPromptBtn: $('#geweb-ai-reset-temp-prompt'),
		    $closeSettingsBtn: $('#geweb-ai-close-temp-settings'),
		    $currentModelDisplay: $('#geweb-ai-current-model-display'),
		    $currentPromptDisplay: $('#geweb-ai-current-prompt-display'),
		    $temporaryPromptSummary: $('#geweb-ai-temporary-prompt-summary'),
		    $temporaryPromptEditor: $('#geweb-ai-temporary-prompt-editor'),
		    $togglePromptEditorBtn: $('#geweb-ai-toggle-prompt-editor'),
		    $temporaryPrompt: $('#geweb-ai-temporary-prompt'),
		    $answerBox: $('.answer-box'),
	    $conversationOverview: $('#geweb-ai-conversation-overview'),
	    $sourcesBox: $('#geweb-ai-sources'),
	    $searchResultsContent: $('.geweb-ai-search-results-content'),
	    $currentConversationSummary: $('#geweb-ai-current-conversation-summary'),
	    $copyConversationBtn: $('#geweb-ai-copy-conversation'),
	    $renameConversationBtn: $('#geweb-ai-rename-conversation'),
	    $deleteConversationBtn: $('#geweb-ai-delete-conversation'),
	    $newConversationBtn: $('.geweb-ai-new-conversation'),
	    conversationHistory: [],
	    conversationId: '',
	    requestInFlight: false,
	    compactedConversation: false,
	    currentContextSummary: '',
	    excludedSourceKeysByConversation: {},
		    conversationArchive: [],
		    conversationOverviewPageSize: 10,
		    conversationOverviewRenderedCount: 0,
		    sourceReferenceCache: {},
		    $messageInfoPopover: null,
		    $messageInfoPopoverAnchor: null,
		    historyNavIndex: -1,
		    draftMessage: '',

		    init() {
		        if (!this.$textarea.length) {
return;
}

		        this.$textarea.on('input', () => {
		            this.toggleSubmitButton();
		            if (this.historyNavIndex !== -1) {
		                this.historyNavIndex = -1;
		                this.syncHistoryButtons();
		            }
		        });
		        this.$textarea.on('focus', () => this.handleQuestionBoxFocus());
		        this.$textarea.on('click', () => this.handleQuestionBoxFocus());
		        this.$submitBtn.on('click', (event) => this.handleSubmitButtonClick(event));
		        this.$settingsToggle.on('click', () => this.toggleTemporarySettings());
		        this.$closeSettingsBtn.on('click', () => this.toggleTemporarySettings(false));
		        this.$resetSettingsBtn.on('click', () => this.resetTemporarySettings(true));
		        this.$resetModelBtn.on('click', () => this.resetTemporaryModel(true));
		        this.$resetPromptBtn.on('click', () => this.resetTemporaryPrompt(true));
		        this.$togglePromptEditorBtn.on('click', () => this.toggleTemporaryPromptEditor());
		        this.$modelSelector.on('change', () => this.handleTemporaryModelChange());
		        this.$temporaryPrompt.on('input', () => this.updateTemporarySettingsSummary());
		        this.$copyConversationBtn.on('click', () => {
 void this.copyCurrentConversation();
});
		        this.$renameConversationBtn.on('click', () => {
 void this.renameCurrentConversation();
});
		        this.$deleteConversationBtn.on('click', () => {
 void this.deleteCurrentConversation();
});
		        this.$newConversationBtn.on('click', () => this.createNewConversation(true));
		        this.$conversationOverview.off('click.gewebPaneActivate').on('click.gewebPaneActivate', (event) => {
		            event.stopPropagation();
		            const isActive = this.$conversationOverview.hasClass('is-scroll-active');
		            const isInteractiveClick = this.isPaneInteractiveClick(event);
		            if (!isActive) {
		                this.setConversationOverviewScrollActive(true);
		                return;
		            }
		            if (isInteractiveClick) {
		                return;
		            }
		            this.setConversationOverviewScrollActive(false);
		        });
		        this.$answerBox.off('click.gewebPaneActivate').on('click.gewebPaneActivate', (event) => {
		            event.stopPropagation();
		            const isActive = this.$answerBox.hasClass('is-scroll-active');
		            const isInteractiveClick = this.isPaneInteractiveClick(event);
		            if (!isActive) {
		                this.setAnswerBoxScrollActive(true);
		                return;
		            }
		            if (isInteractiveClick) {
		                return;
		            }
		            this.setAnswerBoxScrollActive(false);
		        });
		        this.$answerBox.closest('.geweb-ai-main-panel').off('click.gewebPaneActivate').on('click.gewebPaneActivate', (event) => {
		            if (GewebModal.isMobileWorkspaceNavigationActive()) {
		                return;
		            }

		            if (event.target !== event.currentTarget) {
		                return;
		            }

		            if ($(event.target).closest('.question-box').length) {
		                return;
		            }

		            event.stopPropagation();
		            this.toggleAnswerBoxScrollActive();
		        });
		        this.$sourcesBox.off('click.gewebPaneActivate').on('click.gewebPaneActivate', (event) => {
		            event.stopPropagation();
		            if (GewebModal.isMobileWorkspaceNavigationActive()) {
		                return;
		            }
		            const isActive = this.$sourcesBox.hasClass('is-scroll-active');
		            const isInteractiveClick = this.isPaneInteractiveClick(event);
		            if (!isActive) {
		                this.setSourcesScrollActive(true);
		                return;
		            }
		            if (isInteractiveClick) {
		                return;
		            }
		            this.setSourcesScrollActive(false);
		        });
		        this.$searchResultsContent.off('click.gewebPaneActivate').on('click.gewebPaneActivate', (event) => {
		            event.stopPropagation();
		            const isActive = this.$searchResultsContent.hasClass('is-scroll-active');
		            const isInteractiveClick = this.isPaneInteractiveClick(event);
		            if (!isActive) {
		                this.setSearchResultsScrollActive(true);
		                return;
		            }
		            if (isInteractiveClick) {
		                return;
		            }
		            this.setSearchResultsScrollActive(false);
		        });
		        this.bindConversationScrollLock();
		        this.bindSourcesScrollLock();
		        this.bindSearchResultsScrollLock();
		        this.bindAnswerBoxTouchScrollActivation();
		        this.bindAnswerBoxEdgeScrollPropagation();
		        this.$conversationOverview.on('scroll', () => this.handleConversationOverviewScroll());
		        $(document).off('click.gewebPaneActivate').on('click.gewebPaneActivate', (event) => this.handleDocumentClick(event));
		        document.addEventListener('scroll', () => {
		            this.hideFootnotePreview();
		            this.hideMessageInfoPopover();
		        }, true);
		        globalThis.addEventListener('resize', () => this.hideMessageInfoPopover());
		        this.$textarea.on('keydown', (event) => this.handleQuestionBoxKeydown(event));
		        this.$textarea.on('beforeinput', (event) => this.handleQuestionBoxBeforeInput(event));
		        globalThis.addEventListener('pageshow', (event) => this.handlePageShow(event));
		        $(document).on('keydown', (event) => {
		            if (event.key === 'Escape') {
		                this.toggleTemporarySettings(false);
		            }
		        });
		        this.resetTemporarySettings();
		        this.updateTemporarySettingsAvailability(false);
		        this.syncModelSelectorWidth();
		        this.normalizeWorkspaceControlTitles();
		        this.bindHistoryControls();
		        void this.bootstrap();
		    },

		    async bootstrap() {
		        this.hydrateFromStoredChatState();
		        await this.loadConversationArchive();
	        try {
	            await this.applyFrontendRequestState();
	        } catch (error) {
	            console.debug('Applying frontend request state failed.', error);
	        }
	        this.renderConversationOverview();
		        this.renderConversationSummary();
		        this.renderSources();
		        this.syncModelSelectorWidth();
		        this.normalizeWorkspaceControlTitles();
		        this.toggleSubmitButton();
		        this.syncStoredChatState();
		    },

	    normalizeWorkspaceControlTitles(scope = null) {
	        const root = scope
	            ? $(scope)
	            : (this.ai ? $(this.ai) : $());
	        if (!root.length) {
	            return;
	        }

	        const inlineLabelSelectors = [
	            '.geweb-ai-message-action-label',
	            '.geweb-ai-overview-action-label',
	            '.geweb-ai-page-toolbar-button-label',
	            '.geweb-ai-trigger-label',
	        ].join(', ');

	        const controlSelectors = [
	            'button',
	            'a.button',
	            '.geweb-ai-source-details-link',
	        ].join(', ');

	        root.find(controlSelectors).each((_, element) => {
	            const $control = $(element);
	            const ariaLabel = String($control.attr('aria-label') || '').trim();
	            const hasInlineLabel = $control.find(inlineLabelSelectors).length > 0;

	            if (hasInlineLabel) {
	                $control.removeAttr('title');
	                return;
	            }

	            if (!$control.attr('title') && ariaLabel) {
	                $control.attr('title', ariaLabel);
	            }
	        });
	    },

	    isBackForwardNavigation(event) {
	        if (event?.persisted) {
	            return true;
	        }

	        const navigationEntry = globalThis.performance?.getEntriesByType?.('navigation')?.[0];
	        return navigationEntry?.type === 'back_forward';
	    },

	    handlePageShow(event) {
	        if (!geweb_aisearch.is_frontend_ai_page || !this.isBackForwardNavigation(event)) {
	            return;
	        }

	        void this.bootstrap();
	    },

	    toggleSubmitButton() {
	        const hasText = this.$textarea.val().trim().length > 0;
	        this.$submitBtn.prop('disabled', !hasText || this.requestInFlight);
	    },

	    scrollElementIntoView(element) {
	        if (!element || typeof element.scrollIntoView !== 'function') {
	            return;
	        }

	        globalThis.setTimeout(() => {
	            element.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
	        }, 0);
	    },

	    focusInput() {
	        if (this.$textarea.length) {
	            this.$textarea.trigger('focus');
	        }
	    },

	    handleQuestionBoxFocus() {
	        const workspace = GewebModal.getWorkspaceElement();
	        if (GewebModal.isMobileWorkspaceNavigationActive(workspace)) {
	            GewebModal.setMobileWorkspacePane('main', { focusPane: false });
	            GewebModal.syncPanelCollapseButtons();
	        }

	        const textarea = this.$textarea.get(0);
	        if (!textarea) {
	            return;
	        }

	        globalThis.setTimeout(() => {
	            GewebModal.syncPageViewHeightToViewport();
	            this.scrollElementIntoView(textarea);
	        }, 80);

	        globalThis.setTimeout(() => {
	            GewebModal.syncPageViewHeightToViewport();
	            this.scrollElementIntoView(textarea);
	        }, 280);
	    },

	    populateQuestionBox(text, options = {}) {
	        if (!this.$textarea.length) {
	            return;
	        }

	        const nextValue = String(text || '');
	        this.$textarea.val(nextValue);
	        this.toggleSubmitButton();

	        const textarea = this.$textarea.get(0);
	        if (textarea && typeof textarea.setSelectionRange === 'function') {
	            const cursorPosition = nextValue.length;
	            textarea.setSelectionRange(cursorPosition, cursorPosition);
	        }

	        if (options.focus !== false) {
	            this.focusInput();
	        }
	    },

	    getUserQuestions() {
	        return this.conversationHistory
	            .filter((msg) => msg.role === 'user')
	            .map((msg) => msg.content);
	    },

	    navigateHistory(direction) {
	        const questions = this.getUserQuestions();
	        if (!questions.length) {
return;
}

	        if (this.historyNavIndex === -1) {
	            if (direction > 0) {
return;
}
	            this.draftMessage = this.$textarea.val();
	            this.historyNavIndex = questions.length;
	        }

	        this.historyNavIndex += direction;

	        if (this.historyNavIndex < 0) {
	            this.historyNavIndex = 0;
	        } else if (this.historyNavIndex >= questions.length) {
	            this.historyNavIndex = -1;
	        }

	        if (this.historyNavIndex === -1) {
	            this.populateQuestionBox(this.draftMessage, { focus: true });
	        } else {
	            this.populateQuestionBox(questions[this.historyNavIndex], { focus: true });
	        }

	        this.syncHistoryButtons();
	    },

	    syncHistoryButtons() {
	        const questions = this.getUserQuestions();
	        const hasQuestions = questions.length > 0;
	        const canGoUp = hasQuestions && this.historyNavIndex !== 0;
	        const canGoDown = this.historyNavIndex !== -1;
	        const canRetry = hasQuestions && !this.requestInFlight;

	        if (this.$historyUpBtn) {
	            this.$historyUpBtn.prop('disabled', !canGoUp).css({
	                opacity: canGoUp ? '1' : '0.4',
	                cursor: canGoUp ? 'pointer' : 'default'
	            });
	        }
	        if (this.$historyDownBtn) {
	            this.$historyDownBtn.prop('disabled', !canGoDown).css({
	                opacity: canGoDown ? '1' : '0.4',
	                cursor: canGoDown ? 'pointer' : 'default'
	            });
	        }
	        if (this.$retryLastBtn) {
	            this.$retryLastBtn.prop('disabled', !canRetry).css({
	                opacity: canRetry ? '1' : '0.4',
	                cursor: canRetry ? 'pointer' : 'default'
	            });
	        }
	    },

	    async retryLastQuestion() {
	        if (this.requestInFlight) {
return;
}

	        let lastUserIndex = -1;
	        for (let i = this.conversationHistory.length - 1; i >= 0; i--) {
	            if (this.conversationHistory[i].role === 'user') {
	                lastUserIndex = i;
	                break;
	            }
	        }

	        if (lastUserIndex >= 0) {
	            const questionText = this.conversationHistory[lastUserIndex].content;
	            await this.removeConversationTurn(lastUserIndex);
	            this.populateQuestionBox(questionText, { focus: false });
	            this.historyNavIndex = -1;
	            this.syncHistoryButtons();
	            void this.sendMessage();
	        }
	    },

	    bindHistoryControls() {
	        const $form = this.$textarea.closest('form, .question-box, .geweb-ai-question-box');

	        this.$historyUpBtn = $form.find('.geweb-ai-history-up, .history-up, button[title*="Previous"], button[aria-label*="Previous"], button:contains("↑")');
	        this.$historyDownBtn = $form.find('.geweb-ai-history-down, .history-down, button[title*="Next"], button[aria-label*="Next"], button:contains("↓")');

	        if (this.$historyUpBtn.length && this.$historyDownBtn.length) {
	            const $parent = this.$historyUpBtn.parent();
	            $parent.css({
	                display: 'flex',
	                gap: '4px',
	                alignItems: 'center'
	            });

	            this.$retryLastBtn = $parent.find('.geweb-ai-retry-last, .geweb-ai-retry-btn, button[title*="Retry"], button:contains("↻")');
	            if (!this.$retryLastBtn.length) {
	                this.$retryLastBtn = $(`<button type="button" class="geweb-ai-icon-button geweb-ai-retry-last" title="${t('retryLast', 'Retry last question')}" aria-label="Retry last question"><span aria-hidden="true" class="geweb-ai-icon">↻</span></button>`);
	                this.$historyUpBtn.before(this.$retryLastBtn);
	            }
	        } else {
	            const $controls = $('<div class="geweb-ai-history-controls" style="display: flex; gap: 4px; align-items: center; margin-right: 8px;"></div>');
	            this.$retryLastBtn = $(`<button type="button" class="geweb-ai-icon-button geweb-ai-retry-last" title="${t('retryLast', 'Retry last question')}" aria-label="Retry last question"><span aria-hidden="true" class="geweb-ai-icon">↻</span></button>`);
	            this.$historyUpBtn = $(`<button type="button" class="geweb-ai-icon-button geweb-ai-history-up" title="${t('historyPrev', 'Previous question')}" aria-label="Previous question"><span aria-hidden="true" class="geweb-ai-icon">↑</span></button>`);
	            this.$historyDownBtn = $(`<button type="button" class="geweb-ai-icon-button geweb-ai-history-down" title="${t('historyNext', 'Next question')}" aria-label="Next question"><span aria-hidden="true" class="geweb-ai-icon">↓</span></button>`);

	            $controls.append(this.$retryLastBtn, this.$historyUpBtn, this.$historyDownBtn);
	            this.$submitBtn.before($controls);
	            this.$submitBtn.parent().css({ display: 'flex', alignItems: 'center' });
	        }

	        this.$historyUpBtn.off('click').on('click', (e) => {
	            e.preventDefault();
	            this.navigateHistory(-1);
	        });
	        this.$historyDownBtn.off('click').on('click', (e) => {
	            e.preventDefault();
	            this.navigateHistory(1);
	        });
	        this.$retryLastBtn.off('click').on('click', (e) => {
	            e.preventDefault();
	            this.retryLastQuestion();
	        });

	        this.$textarea.off('keydown.history').on('keydown.history', (e) => {
	            if (e.key === 'ArrowUp' && this.$textarea.val().trim() === '') {
	                e.preventDefault();
	                this.navigateHistory(-1);
	            } else if (e.key === 'ArrowDown' && this.historyNavIndex !== -1) {
	                e.preventDefault();
	                this.navigateHistory(1);
	            }
	        });

	        this.syncHistoryButtons();
	    },

	    getScrollablePaneTargets() {
	        return [
	            {
	                key: 'conversation',
	                $element: this.$conversationOverview,
	                activeClass: 'is-scroll-active',
	                onChange: (isActive) => {
	                    this.$conversationOverview.find('.geweb-ai-overview-list').toggleClass('is-scroll-active', !!isActive);
	                },
	            },
	            {
	                key: 'answer',
	                $element: this.$answerBox,
	                activeClass: 'is-scroll-active',
	                onChange: (isActive) => {
	                    this.$answerBox.closest('.geweb-ai-main-panel').toggleClass('is-answer-box-scroll-active', !!isActive);
	                },
	            },
	            {
	                key: 'sources',
	                $element: this.$sourcesBox,
	                activeClass: 'is-scroll-active',
	                onChange: (isActive) => {
	                    this.$sourcesBox.closest('.geweb-ai-sources-panel').toggleClass('is-sources-scroll-active', !!isActive);
	                    this.$sourcesBox.find('.geweb-ai-source-list').toggleClass('is-scroll-active', !!isActive);
	                },
	            },
	            {
	                key: 'search',
	                $element: this.$searchResultsContent,
	                activeClass: 'is-scroll-active',
	                onChange: (isActive) => {
	                    this.$searchResultsContent.closest('.geweb-ai-search-results-panel').toggleClass('is-search-scroll-active', !!isActive);
	                },
	            },
	        ];
	    },

	    setScrollablePaneActive(targetKey = null) {
	        this.getScrollablePaneTargets().forEach((target) => {
	            if (!target?.$element?.length) {
	                return;
	            }

	            const isActive = !!targetKey && target.key === targetKey;
	            target.$element.toggleClass(target.activeClass, isActive);
	            if (typeof target.onChange === 'function') {
	                target.onChange(isActive);
	            }
	        });
	    },

	    toggleScrollablePane(targetKey) {
	        const target = this.getScrollablePaneTargets().find((item) => item.key === targetKey && item.$element?.length);
	        if (!target) {
	            return;
	        }

	        const shouldActivate = !target.$element.hasClass(target.activeClass);
	        this.setScrollablePaneActive(shouldActivate ? targetKey : null);
	    },

	    setConversationOverviewScrollActive(isActive) {
	        this.setScrollablePaneActive(isActive ? 'conversation' : null);
	    },

	    setAnswerBoxScrollActive(isActive) {
	        this.setScrollablePaneActive(isActive ? 'answer' : null);
	    },

	    setSourcesScrollActive(isActive) {
	        this.setScrollablePaneActive(isActive ? 'sources' : null);
	    },

	    setSearchResultsScrollActive(isActive) {
	        this.setScrollablePaneActive(isActive ? 'search' : null);
	    },

	    isPaneInteractiveClick(event) {
	        const target = event?.target;
	        if (!target || !target.closest) {
	            return false;
	        }

	        return !!target.closest(
	            'a, button, input, textarea, select, option, label, summary, details, .geweb-ai-footnote-ref, .user-message, .geweb-ai-user-message-remove'
	        );
	    },

	    toggleConversationOverviewScrollActive() {
	        this.toggleScrollablePane('conversation');
	    },

		    toggleAnswerBoxScrollActive() {
		        this.toggleScrollablePane('answer');
		    },

	    toggleSourcesScrollActive() {
	        this.toggleScrollablePane('sources');
	    },

	    toggleSearchResultsScrollActive() {
	        this.toggleScrollablePane('search');
	    },

		    isMobileAnswerBoxTouchMode() {
		        return GewebModal.isMobileWorkspaceNavigationActive();
		    },

		    bindAnswerBoxTouchScrollActivation() {
		        const answerBox = this.$answerBox.get(0);
		        if (!answerBox || answerBox.dataset.gewebTouchScrollBound === '1') {
		            return;
		        }

		        answerBox.dataset.gewebTouchScrollBound = '1';
		        let pressTimer = null;
		        let startX = 0;
		        let startY = 0;
		        let touchActivated = false;

		        const clearPressTimer = () => {
		            if (pressTimer !== null) {
		                globalThis.clearTimeout(pressTimer);
		                pressTimer = null;
		            }
		        };

		        answerBox.addEventListener('touchstart', (event) => {
		            if (!this.isMobileAnswerBoxTouchMode() || event.touches.length !== 1) {
		                return;
		            }

		            if (!this.$answerBox.hasClass('is-scroll-active')) {
		                this.setAnswerBoxScrollActive(true);
		            }

		            const touch = event.touches[0];
		            startX = Number(touch?.clientX || 0);
		            startY = Number(touch?.clientY || 0);
		            touchActivated = false;
		            clearPressTimer();
		            pressTimer = globalThis.setTimeout(() => {
		                touchActivated = true;
		                this.setAnswerBoxScrollActive(true);
		            }, 240);
		        }, { passive: true });

		        answerBox.addEventListener('touchmove', (event) => {
		            if (!this.isMobileAnswerBoxTouchMode() || event.touches.length !== 1) {
		                return;
		            }

		            const touch = event.touches[0];
		            const deltaX = Math.abs(Number(touch?.clientX || 0) - startX);
		            const deltaY = Math.abs(Number(touch?.clientY || 0) - startY);
		            if (!touchActivated && (deltaX > 8 || deltaY > 8)) {
		                clearPressTimer();
		            }
		        }, { passive: true });

		        const finishTouch = () => {
		            clearPressTimer();
		        };

		        answerBox.addEventListener('touchend', finishTouch, { passive: true });
		        answerBox.addEventListener('touchcancel', finishTouch, { passive: true });
		    },

		    bindAnswerBoxEdgeScrollPropagation() {
		        const answerBox = this.$answerBox.get(0);
		        if (!answerBox || answerBox.dataset.gewebEdgeScrollBound === '1') {
		            return;
		        }

		        answerBox.dataset.gewebEdgeScrollBound = '1';

		        const isAnswerActive = () => this.$answerBox.hasClass('is-scroll-active');
	        const isAtTop = () => answerBox.scrollTop <= 1;
	        const isAtBottom = () => answerBox.scrollTop + answerBox.clientHeight >= answerBox.scrollHeight - 1;

		        this.$answerBox.on('wheel.gewebEdgeScroll', (event) => {
		            if (!isAnswerActive()) {
		                return;
		            }

		            const originalEvent = event.originalEvent;
		            const deltaY = Number(originalEvent?.deltaY || 0);
		            if (!Number.isFinite(deltaY) || deltaY === 0) {
		                return;
		            }

		            if ((deltaY < 0 && isAtTop()) || (deltaY > 0 && isAtBottom())) {
		                event.preventDefault();
		                if (typeof globalThis.scrollBy === 'function') {
		                    globalThis.scrollBy({ top: deltaY, left: 0, behavior: 'auto' });
		                }
		            }
		        });

		        let lastTouchY = 0;
		        answerBox.addEventListener('touchstart', (event) => {
		            if (!isAnswerActive() || event.touches.length !== 1) {
		                return;
		            }

		            lastTouchY = Number(event.touches[0]?.clientY || 0);
		        }, { passive: true });

		        answerBox.addEventListener('touchmove', (event) => {
		            if (!isAnswerActive() || event.touches.length !== 1) {
		                return;
		            }

		            const currentY = Number(event.touches[0]?.clientY || 0);
		            const deltaY = lastTouchY - currentY;
		            lastTouchY = currentY;

		            if ((deltaY < 0 && isAtTop()) || (deltaY > 0 && isAtBottom())) {
		                event.preventDefault();
		                if (typeof globalThis.scrollBy === 'function') {
		                    globalThis.scrollBy({ top: deltaY, left: 0, behavior: 'auto' });
		                }
		            }
		        }, { passive: false });
		    },

		    bindSearchResultsScrollLock() {
		        const resultsElement = this.$searchResultsContent.get(0);
		        if (!resultsElement || resultsElement.dataset.gewebSearchScrollBound === '1') {
		            return;
		        }

		        resultsElement.dataset.gewebSearchScrollBound = '1';

		        const isSearchActive = () => this.$searchResultsContent.hasClass('is-scroll-active');

		        this.$searchResultsContent.on('wheel.gewebSearchScroll', (event) => {
		            if (!isSearchActive()) {
		                return;
		            }

		            const originalEvent = event.originalEvent;
		            const deltaY = Number(originalEvent?.deltaY || 0);
		            if (!Number.isFinite(deltaY) || deltaY === 0) {
		                return;
		            }

		            event.preventDefault();
		            resultsElement.scrollTop += deltaY;
		        });

		        let lastTouchY = 0;
		        resultsElement.addEventListener('touchstart', (event) => {
		            if (!isSearchActive() || event.touches.length !== 1) {
		                return;
		            }

		            lastTouchY = Number(event.touches[0]?.clientY || 0);
		        }, { passive: true });

		        resultsElement.addEventListener('touchmove', (event) => {
		            if (!isSearchActive() || event.touches.length !== 1) {
		                return;
		            }

		            const currentY = Number(event.touches[0]?.clientY || 0);
		            const deltaY = lastTouchY - currentY;
		            lastTouchY = currentY;

		            event.preventDefault();
		            resultsElement.scrollTop += deltaY;
		        }, { passive: false });
		    },

		    bindConversationScrollLock() {
		        this.bindScrollablePaneScrollLock({
		            $element: this.$conversationOverview,
		            activeCheck: () => this.$conversationOverview.hasClass('is-scroll-active'),
		            boundKey: 'gewebConversationScrollBound',
		            namespace: 'gewebConversationScroll',
		        });
		    },

		    bindSourcesScrollLock() {
		        this.bindScrollablePaneScrollLock({
		            $element: this.$sourcesBox,
		            activeCheck: () => this.$sourcesBox.hasClass('is-scroll-active'),
		            boundKey: 'gewebSourcesScrollBound',
		            namespace: 'gewebSourcesScroll',
		        });
		    },

		    bindScrollablePaneScrollLock({ $element, activeCheck, boundKey, namespace }) {
		        const element = $element?.get?.(0);
		        if (!element || !boundKey || !namespace || element.dataset[boundKey] === '1') {
		            return;
		        }

		        element.dataset[boundKey] = '1';

		        $element.on(`wheel.${namespace}`, (event) => {
		            if (typeof activeCheck !== 'function' || !activeCheck()) {
		                return;
		            }

		            const originalEvent = event.originalEvent;
		            const deltaY = Number(originalEvent?.deltaY || 0);
		            if (!Number.isFinite(deltaY) || deltaY === 0) {
		                return;
		            }

		            event.preventDefault();
		            element.scrollTop += deltaY;
		        });

		        let lastTouchY = 0;
		        element.addEventListener('touchstart', (event) => {
		            if (typeof activeCheck !== 'function' || !activeCheck() || event.touches.length !== 1) {
		                return;
		            }

		            lastTouchY = Number(event.touches[0]?.clientY || 0);
		        }, { passive: true });

		        element.addEventListener('touchmove', (event) => {
		            if (typeof activeCheck !== 'function' || !activeCheck() || event.touches.length !== 1) {
		                return;
		            }

		            const currentY = Number(event.touches[0]?.clientY || 0);
		            const deltaY = lastTouchY - currentY;
		            lastTouchY = currentY;

		            event.preventDefault();
		            element.scrollTop += deltaY;
		        }, { passive: false });
		    },

	    submitQuestionBoxFromKeyboard(event) {
	        if (event) {
	            event.preventDefault();
	            event.stopPropagation();
	        }

	        if (this.$submitBtn.prop('disabled')) {
	            return;
	        }

	        if (event?.altKey) {
	            void this.sendMessageToNewConversation();
	            return;
	        }

	        void this.sendMessage();
	    },

	    handleSubmitButtonClick(event) {
	        if (event?.altKey) {
	            void this.sendMessageToNewConversation();
	            return;
	        }

	        void this.sendMessage();
	    },

	    handleQuestionBoxKeydown(event) {
	        const isEnterKey = event.key === 'Enter' || event.keyCode === 13 || event.which === 13;
	        if (event.isComposing || !isEnterKey || event.shiftKey) {
	            return;
	        }

	        this.submitQuestionBoxFromKeyboard(event);
	    },

	    handleQuestionBoxBeforeInput(event) {
	        const originalEvent = event.originalEvent;
	        const inputType = originalEvent?.inputType || event.inputType || '';
	        if (inputType !== 'insertLineBreak') {
	            return;
	        }

	        if (originalEvent?.shiftKey || event.shiftKey) {
	            return;
	        }

	        this.submitQuestionBoxFromKeyboard(event);
	    },

	    async sendMessageToNewConversation(prefilledMessage) {
	        const message = typeof prefilledMessage === 'string'
	            ? prefilledMessage.trim()
	            : String(this.$textarea.val() || '').trim();

	        if (!message || this.requestInFlight) {
	            return;
	        }

	        this.createNewConversation(false);
	        await this.sendMessage(message);
	    },

	    syncFrontendPageConversationState() {
	        if (!geweb_aisearch.is_frontend_ai_page || !globalThis.history?.replaceState) {
	            return;
	        }

	        const currentUrl = globalThis.location?.href;
	        if (!currentUrl) {
	            return;
	        }

	        try {
	            const url = new URL(currentUrl, globalThis.location.origin);
	            const conversationId = String(this.conversationId || '').trim();
	            if (conversationId) {
	                url.searchParams.set('geweb_ai_conversation', conversationId);
	            } else {
	                url.searchParams.delete('geweb_ai_conversation');
	            }

	            geweb_aisearch.frontend_ai_conversation_id = conversationId;
	            globalThis.history.replaceState({}, '', url.toString());
	        } catch (error) {
	            console.debug('Syncing frontend page conversation state failed.', error);
	        }
	    },

	    shouldAutoSubmitQuery(query) {
	        const trimmedQuery = (query || '').trim();
	        if (trimmedQuery === '' || this.requestInFlight) {
	            return false;
	        }

	        return trimmedQuery.split(/\s+/).length > 1;
	    },

	    normalizeChatMatchText(text) {
	        return String(text || '').replaceAll(/\s+/g, ' ').trim().toLowerCase();
	    },

	    getFirstUserMessageFromMessages(messages) {
	        const list = Array.isArray(messages) ? messages : [];
	        for (const item of list) {
	            if (item?.role !== 'user') {
	                continue;
	            }

	            const content = String(item.content || '').trim();
	            if (content) {
	                return content;
	            }
	        }

	        return '';
	    },

	    findMatchingConversationEntry(query) {
	        const normalizedQuery = this.normalizeChatMatchText(query);
	        if (!normalizedQuery) {
	            return null;
	        }

	        return this.conversationArchive.find((entry) => {
	            const normalizedSummary = this.normalizeChatMatchText(entry?.summary || '');
	            if (normalizedSummary && normalizedSummary === normalizedQuery) {
	                return true;
	            }

	            const firstQuestion = String(entry?.firstUserMessage || '').trim() || this.getFirstUserMessageFromMessages(entry?.messages);
	            return firstQuestion !== '' && this.normalizeChatMatchText(firstQuestion) === normalizedQuery;
	        }) || null;
	    },

	    getTargetConversationIdForQuery(query) {
	        const trimmedQuery = String(query || '').trim();
	        if (!this.shouldAutoSubmitQuery(trimmedQuery)) {
	            return this.getPreferredConversationId();
	        }

	        const matchingEntry = this.findMatchingConversationEntry(trimmedQuery);
	        return matchingEntry?.id || '';
	    },

	    applyConversationEntry(entry) {
	        if (!entry || !Array.isArray(entry.messages)) {
	            return;
	        }

	        this.conversationId = entry.id;
	        this.conversationHistory = entry.messages.map((item) => ({
	            role: item.role,
	            content: item.content,
	            sources: Array.isArray(item.sources) ? item.sources : [],
	            meta: item.meta && typeof item.meta === 'object' ? item.meta : {},
	            created_at: this.normalizeEpochSeconds(item.created_at ?? item.createdAt)
	        }));
	        this.compactedConversation = !!entry.compacted;
	        this.currentContextSummary = String(entry.contextSummary || '').trim();
	        this.requestInFlight = false;
	        this.renderConversationMessages();

	        this.renderConversationOverview();
	        this.renderConversationSummary();
	        this.renderSources();
	        this.syncStoredChatState();
	        this.toggleSubmitButton();
	        this.syncFrontendPageConversationState();
	        this.historyNavIndex = -1;
	        this.syncHistoryButtons();
	    },

	    renderConversationMessages() {
	        if (!this.$answerBox.length) {
	            return;
	        }

	        const answerBoxNode = this.$answerBox[0];
	        const parentNode = answerBoxNode.parentNode;
	        const nextSibling = answerBoxNode.nextSibling;

	        this.$answerBox.detach().empty();

	        this.conversationHistory.forEach((item, index) => {
	            if (item.role === 'model') {
	                const requestContext = item.meta && typeof item.meta.request === 'object'
	                    ? item.meta.request
	                    : {};
	                const requestSummary = String(requestContext.context_summary || '').trim();
	                if (requestSummary) {
	                    this.appendContextSummaryNote(requestSummary);
	                } else if (index === 0 && this.compactedConversation && this.currentContextSummary) {
	                    // Backward compatibility for chats saved before per-response request metadata.
	                    this.appendContextSummaryNote(this.currentContextSummary);
	                }

	                this.appendMessage({
	                    answer: item.content,
	                    sources: item.sources || [],
	                    meta: item.meta || {}
	                }, 'ai', { historyIndex: index, messageData: item });
	                return;
	            }

	            const linkedResponseMeta = this.conversationHistory[index + 1]?.role === 'model'
	                ? (this.conversationHistory[index + 1].meta || {})
	                : {};
	            this.appendMessage(item.content, 'user', { historyIndex: index, messageData: item, responseMeta: linkedResponseMeta });
	        });

	        if (parentNode) {
	            parentNode.insertBefore(answerBoxNode, nextSibling);
	        }
	    },

	    async removeConversationTurn(historyIndex) {
	        const index = Number(historyIndex);
	        if (!Number.isInteger(index) || index < 0 || index >= this.conversationHistory.length) {
	            return;
	        }

	        const targetEntry = this.conversationHistory[index];
	        if (!targetEntry || targetEntry.role !== 'user') {
	            return;
	        }

	        const deleteCount = this.conversationHistory[index + 1]?.role === 'model' ? 2 : 1;
	        this.conversationHistory.splice(index, deleteCount);
	        this.requestInFlight = false;
	        this.renderConversationMessages();
	        this.renderConversationOverview();
	        this.renderConversationSummary();
	        this.renderSources();
	        this.toggleSubmitButton();
	        this.syncFrontendPageConversationState();
	        this.syncStoredChatState();
	        await this.persistConversation();
	        await this.loadConversationArchive();
	        this.renderConversationOverview();
	        this.renderConversationSummary();
	    },

	    getNormalizedConversationIdForSourceState() {
	        return String(this.conversationId || '').trim() || this.ensureConversationId();
	    },

	    getCurrentExcludedSourceKeys() {
	        const conversationId = this.getNormalizedConversationIdForSourceState();
	        const storedKeys = this.excludedSourceKeysByConversation?.[conversationId];
	        return Array.isArray(storedKeys)
	            ? [...new Set(storedKeys.map((key) => String(key || '').trim()).filter(Boolean))]
	            : [];
	    },

	    setCurrentExcludedSourceKeys(keys) {
	        const conversationId = this.getNormalizedConversationIdForSourceState();
	        const normalizedKeys = Array.isArray(keys)
	            ? [...new Set(keys.map((key) => String(key || '').trim()).filter(Boolean))]
	            : [];

	        if (!normalizedKeys.length) {
	            delete this.excludedSourceKeysByConversation[conversationId];
	            this.syncStoredChatState();
	            return;
	        }

	        this.excludedSourceKeysByConversation[conversationId] = normalizedKeys;
	        this.syncStoredChatState();
	    },

	    isSourceTemporarilyExcluded(source) {
	        const sourceKey = typeof this.getSourceRegistryKey === 'function'
	            ? String(this.getSourceRegistryKey(source) || '').trim()
	            : '';
	        if (!sourceKey) {
	            return false;
	        }

	        return this.getCurrentExcludedSourceKeys().includes(sourceKey);
	    },

	    toggleSourceTemporarilyExcluded(source) {
	        const sourceKey = typeof this.getSourceRegistryKey === 'function'
	            ? String(this.getSourceRegistryKey(source) || '').trim()
	            : '';
	        if (!sourceKey) {
	            return;
	        }

	        const currentKeys = this.getCurrentExcludedSourceKeys();
	        const nextKeys = currentKeys.includes(sourceKey)
	            ? currentKeys.filter((key) => key !== sourceKey)
	            : [...currentKeys, sourceKey];

	        this.setCurrentExcludedSourceKeys(nextKeys);
	        this.renderSources();
	    },

	    clearTemporarilyExcludedSources() {
	        this.setCurrentExcludedSourceKeys([]);
	        this.renderSources();
	    },

	    getExcludedSourcesForRequest() {
	        const normalizedSources = typeof this.buildConversationSourceRegistry === 'function'
	            ? this.buildConversationSourceRegistry()
	            : [];
	        const excludedKeys = new Set(this.getCurrentExcludedSourceKeys());

	        return normalizedSources.reduce((items, source) => {
	            const key = typeof this.getSourceRegistryKey === 'function'
	                ? String(this.getSourceRegistryKey(source) || '').trim()
	                : '';
	            if (!key || !excludedKeys.has(key)) {
	                return items;
	            }

	            items.push({
	                key,
	                title: String(source?.title || '').trim(),
	                url: String(source?.url || '').trim(),
	            });
	            return items;
	        }, []);
	    },

	    prepareChatForQuery(query) {
	        const trimmedQuery = String(query || '').trim();
	        if (!this.shouldAutoSubmitQuery(trimmedQuery)) {
	            return;
	        }

	        const targetConversationId = this.getTargetConversationIdForQuery(trimmedQuery);
	        if (!targetConversationId) {
	            this.createNewConversation(false);
	            return;
	        }

	        if (targetConversationId === this.conversationId) {
	            return;
	        }

	        const matchingEntry = this.conversationArchive.find((entry) => entry.id === targetConversationId);
	        if (matchingEntry?.messages?.length) {
	            this.applyConversationEntry(matchingEntry);
	        }
	    },

		    getSelectedModel() {
		        if (!this.$modelSelector.length) {
		            return String(geweb_aisearch.selected_model || '').trim();
		        }

		        return String(this.$modelSelector.val() || geweb_aisearch.selected_model || '').trim();
		    },

		    getPromptDescriptors() {
		        return geweb_aisearch.prompt_descriptors && typeof geweb_aisearch.prompt_descriptors === 'object'
		            ? geweb_aisearch.prompt_descriptors
		            : {};
		    },

		    getPromptDescriptor(model) {
		        const descriptors = this.getPromptDescriptors();
		        const resolvedModel = String(model || geweb_aisearch.selected_model || '').trim();
		        const fallbackDescriptor = descriptors[geweb_aisearch.selected_model] || descriptors[''] || {};
		        const descriptor = descriptors[resolvedModel] || fallbackDescriptor || {};

			        return {
			            name: String(descriptor.name || t('temporaryPrompt', 'Temporary chat prompt')).trim(),
			            instruction: String(descriptor.instruction || '').trim(),
			        };
		    },

	    getTemporaryPrompt() {
	        if (!this.$temporaryPrompt.length) {
	            return '';
	        }

		        const promptValue = String(this.$temporaryPrompt.val() || '').trim();
		        const basePrompt = String(this.$temporaryPrompt.attr('data-base-prompt') || '').trim();
		        if (promptValue === '' || promptValue === basePrompt) {
		            return '';
		        }

	        return promptValue;
	    },

	    getAjaxErrorMessage(xhr, fallbackMessage) {
	        if (xhr?.statusText === 'timeout') {
	            return t('requestTimedOut', 'The AI request timed out. Please try again.');
	        }

	        const responseJsonMessage = xhr?.responseJSON?.data?.message
	            ? String(xhr.responseJSON.data.message).trim()
	            : '';
	        if (responseJsonMessage) {
	            return responseJsonMessage;
	        }

	        const rawResponseText = typeof xhr?.responseText === 'string'
	            ? String(xhr.responseText).trim()
	            : '';
	        if (rawResponseText) {
	            const plainText = $('<div></div>').html(rawResponseText).text().replaceAll(/\s+/g, ' ').trim();
	            if (plainText) {
	                return plainText.length > 220 ? `${plainText.slice(0, 217)}...` : plainText;
	            }
	        }

	        return fallbackMessage;
	    },

	    normalizeAiErrorMessage(message, fallbackMessage = '') {
	        const rawMessage = String(message || '').trim();
	        const fallback = String(fallbackMessage || '').trim() || t('answerError', 'Error: Unable to get response');
	        const normalized = rawMessage || fallback;
	        const lowerMessage = normalized.toLowerCase();
	        const isTimeout = lowerMessage.includes('timed out')
	            || lowerMessage.includes('curl error 28')
	            || lowerMessage.includes('operation timed out')
	            || lowerMessage.includes('timeout');

	        if (isTimeout) {
	            return [
	                '<div class="geweb-ai-error-card geweb-ai-error-card--timeout">',
	                `<div class="geweb-ai-error-card-title">${this.escapeHtml(t('requestTimedOutTitle', 'The AI request timed out'))}</div>`,
	                `<div class="geweb-ai-error-card-body">${this.escapeHtml(t('requestTimedOutBody', 'The request to the AI service took too long and no answer was returned.'))}</div>`,
	                '<ul class="geweb-ai-error-card-tips">',
	                `<li>${this.escapeHtml(t('requestTimedOutRetry', 'Please try again.'))}</li>`,
	                `<li>${this.escapeHtml(t('requestTimedOutModelTip', 'If it keeps happening, retry with a different model.'))}</li>`,
	                '</ul>',
	                '</div>',
	            ].join('');
	        }

	        return [
	            '<div class="geweb-ai-error-card geweb-ai-error-card--generic">',
	            `<div class="geweb-ai-error-card-title">${this.escapeHtml(t('requestFailedTitle', 'The AI request did not complete'))}</div>`,
	            `<div class="geweb-ai-error-card-body geweb-ai-error-card-body--preserve">${this.escapeHtml(normalized)}</div>`,
	            '</div>',
	        ].join('');
	    },

	    buildTransportRecoveryMessage(message) {
	        const errorHtml = this.normalizeAiErrorMessage(message, t('connectionError', 'Connection error. Please try again.'));
	        return [
	            errorHtml,
	            '<div class="geweb-ai-error-card geweb-ai-error-card--recovery">',
	            `<div class="geweb-ai-error-card-title">${this.escapeHtml(t('requestRecoveryTitle', 'Still checking for a finished answer'))}</div>`,
	            `<div class="geweb-ai-error-card-body">${this.escapeHtml(t('requestRecoveryBody', 'The connection ended before the browser received the final answer. The server may still finish this request, so the workspace will keep checking for a completed response.'))}</div>`,
	            '</div>',
	        ].join('');
	    },

	    buildAiHistoryEntry({ content = '', sources = [], meta = {}, createdAt = null } = {}) {
	        return {
	            role: 'model',
	            content: String(content || ''),
	            sources: Array.isArray(sources) ? sources : [],
	            meta: meta && typeof meta === 'object' ? meta : {},
	            created_at: this.normalizeEpochSeconds(createdAt || Math.floor(Date.now() / 1000))
	        };
	    },

	    async appendAiHistoryEntry(entry) {
	        if (!entry || typeof entry !== 'object' || String(entry.content || '').trim() === '') {
	            return;
	        }

	        this.conversationHistory.push(entry);
	        this.appendMessage({
	            answer: entry.content,
	            sources: Array.isArray(entry.sources) ? entry.sources : [],
	            meta: entry.meta && typeof entry.meta === 'object' ? entry.meta : {}
	        }, 'ai', { messageData: entry });
	        this.renderSources();
	        await this.persistConversation();
	        await this.loadConversationArchive();
	        this.renderConversationOverview();
	        this.renderConversationSummary();
	    },

	    async pollAiChatJob(jobId, $loader, options = {}) {
	        const normalizedJobId = String(jobId || '').trim();
	        if (!normalizedJobId) {
	            return false;
	        }

	        const maxAttempts = Math.max(1, Number(options.maxAttempts) || 120);
	        const intervalMs = Math.max(500, Number(options.intervalMs) || 2000);

	        for (let attempt = 0; attempt < maxAttempts; attempt += 1) {
	            if (attempt > 0) {
	                await new Promise((resolve) => globalThis.setTimeout(resolve, intervalMs));
	            }

				if ($loader && $loader.length) {
	                $loader.css('opacity', '0.4');
	            }

	            try {
	                const response = await this.requestFrontendConversation('geweb_get_ai_chat_job', {
	                    job_id: normalizedJobId
	                });

					if ($loader && $loader.length) {
	                	$loader.css('opacity', '1');
	            }

	                const payload = response?.data && typeof response.data === 'object' ? response.data : {};
	                const status = String(payload.status || '').trim();
	                this.updateLoaderFromJobProgress($loader, payload.progress);

	                if (status === 'completed' && payload.result && typeof payload.result === 'object') {
	                    await this.handleResponse({
	                        success: true,
	                        data: payload.result
	                    }, $loader);
	                    return true;
	                }

	                if (status === 'error') {
	                    await this.handleResponse({
	                        success: false,
	                        data: {
	                            message: String(payload.message || '').trim()
	                        }
	                    }, $loader);
	                    return false;
	                }
	            } catch (error) {
	                console.debug('Polling AI chat job failed.', error);
	            }
	        }

	        await this.handleResponse({
	            success: false,
	            data: {
	                message: t('requestTimedOut', 'The AI request timed out. Please try again.')
	            }
	        }, $loader);
	        return false;
	    },

	    shouldAttemptResponseRecovery(xhr) {
	        const status = Number(xhr?.status || 0);
	        const statusText = String(xhr?.statusText || '').toLowerCase();
	        return statusText === 'timeout'
	            || status === 502
	            || status === 504
	            || status === 524
	            || status === 598
	            || status === 599;
	    },

	    hasPendingRecoveryErrorAfter(createdAt) {
	        const targetCreatedAt = this.normalizeEpochSeconds(createdAt);
	        return this.conversationHistory.some((item) => {
	            if (item?.role !== 'model') {
	                return false;
	            }

	            const itemCreatedAt = this.normalizeEpochSeconds(item.created_at ?? item.createdAt);
	            const itemMeta = item.meta && typeof item.meta === 'object' ? item.meta : {};
	            return !!itemMeta.pending_recovery && (!targetCreatedAt || (itemCreatedAt && itemCreatedAt >= targetCreatedAt));
	        });
	    },

	    async pollForRecoveredResponse(conversationId, userCreatedAt, options = {}) {
	        const normalizedConversationId = String(conversationId || '').trim();
	        const targetCreatedAt = this.normalizeEpochSeconds(userCreatedAt);
	        if (!normalizedConversationId || !targetCreatedAt) {
	            return false;
	        }

	        const maxAttempts = Math.max(1, Number(options.maxAttempts) || 18);
	        const intervalMs = Math.max(1000, Number(options.intervalMs) || 5000);

	        for (let attempt = 0; attempt < maxAttempts; attempt += 1) {
	            await new Promise((resolve) => globalThis.setTimeout(resolve, attempt === 0 ? 1500 : intervalMs));

	            try {
	                const response = await this.requestFrontendConversation('geweb_get_frontend_conversation', {
	                    conversation_id: normalizedConversationId
	                });
	                if (!(response?.success && response?.data?.conversation)) {
	                    continue;
	                }

	                const entry = this.normalizeStoredConversation(response.data.conversation);
	                this.conversationArchive = this.conversationArchive.filter((item) => item.id !== entry.id);
	                this.conversationArchive.unshift(entry);

	                const recoveredMessage = [...entry.messages].reverse().find((item) => {
	                    if (item?.role !== 'model') {
	                        return false;
	                    }

	                    const itemCreatedAt = this.normalizeEpochSeconds(item.created_at ?? item.createdAt);
	                    const itemMeta = item.meta && typeof item.meta === 'object' ? item.meta : {};
	                    return !!itemCreatedAt && itemCreatedAt >= targetCreatedAt && !itemMeta.error;
	                });

	                if (!recoveredMessage) {
	                    continue;
	                }

	                if (this.conversationId === normalizedConversationId) {
	                    this.conversationHistory = entry.messages.map((item) => ({
	                        role: item.role,
	                        content: item.content,
	                        sources: Array.isArray(item.sources) ? item.sources : [],
	                        meta: item.meta && typeof item.meta === 'object' ? item.meta : {},
	                        created_at: this.normalizeEpochSeconds(item.created_at ?? item.createdAt)
	                    }));
	                    this.compactedConversation = !!entry.compacted;
	                    this.currentContextSummary = String(entry.contextSummary || '').trim();
	                    this.requestInFlight = false;
	                    this.renderConversationMessages();
	                    this.renderConversationOverview();
	                    this.renderConversationSummary();
	                    this.renderSources();
	                    this.toggleSubmitButton();
	                    this.syncFrontendPageConversationState();
	                    this.syncStoredChatState();
	                } else {
	                    this.syncStoredChatState();
	                }

	                return true;
	            } catch (error) {
	                console.debug('Recovered response poll failed.', error);
	            }
	        }

	        return false;
	    },

	    isNonceFailureResponse(xhr) {
	        const responseText = typeof xhr?.responseText === 'string'
	            ? String(xhr.responseText).trim()
	            : '';
	        return Number(xhr?.status || 0) === 403 && responseText === '-1';
	    },

		    setTemporaryPromptBase(descriptor) {
		        if (!this.$temporaryPrompt.length) {
		            return;
		        }

		        this.$temporaryPrompt.attr('data-base-prompt', String(descriptor.instruction || ''));
		        this.$temporaryPrompt.attr('data-base-name', String(descriptor.name || ''));
		    },

		    updateTemporarySettingsSummary() {
		        const selectedModel = this.getSelectedModel();
		        const baseDescriptor = this.getPromptDescriptor(selectedModel);
		        const temporaryPrompt = String(this.$temporaryPrompt.val() || '').trim();
		        const basePrompt = String(this.$temporaryPrompt.attr('data-base-prompt') || '').trim();
		        const basePromptName = String(baseDescriptor.name || '').trim() || t('composerPromptLabel', 'Prompt');
		        const defaultModel = String(geweb_aisearch.selected_model || '').trim();
		        const hasTemporaryModel = selectedModel !== '' && selectedModel !== defaultModel;
		        const hasTemporaryPrompt = temporaryPrompt !== '' && temporaryPrompt !== basePrompt;
		        const promptDescriptor = temporaryPrompt !== '' && temporaryPrompt !== basePrompt
		            ? {
		                name: `${t('temporaryPromptActive', 'Temporary prompt')} · ${t('composerTemporaryOverride', 'Defaults overridden')}`,
		                instruction: temporaryPrompt,
		            }
		            : baseDescriptor;

		        if (this.$currentModelDisplay.length) {
		            this.$currentModelDisplay.text(
		                hasTemporaryModel
		                    ? `${t('temporaryModelActive', 'Temporary model')} · ${selectedModel}`
		                    : selectedModel
		            );
		        }

		        if (this.$currentPromptDisplay.length) {
		            this.$currentPromptDisplay.text(promptDescriptor.name || t('composerPromptLabel', 'Prompt'));
		            this.$currentPromptDisplay.attr('title', promptDescriptor.instruction || '');
		            this.$currentPromptDisplay.attr('aria-label', `${t('composerPromptLabel', 'Prompt')}: ${promptDescriptor.name || ''}`);
		        }
		        if (this.$temporaryPromptSummary.length) {
		            this.$temporaryPromptSummary.text(promptDescriptor.name || basePromptName);
		            this.$temporaryPromptSummary.attr('title', promptDescriptor.instruction || '');
		        }

		        if (this.$resetModelBtn.length) {
		            this.$resetModelBtn.prop('disabled', !hasTemporaryModel);
		        }
		        if (this.$resetPromptBtn.length) {
		            this.$resetPromptBtn.prop('disabled', !hasTemporaryPrompt);
		        }
		    },

		    handleTemporaryModelChange() {
		        const descriptor = this.getPromptDescriptor(this.getSelectedModel());
		        this.setTemporaryPromptBase(descriptor);
		        if (this.$temporaryPrompt.length) {
		            this.$temporaryPrompt.val(descriptor.instruction || '');
		        }
		        this.updateTemporarySettingsSummary();
		    },

		    resetTemporarySettings(shouldFocusPrompt) {
		        const defaultModel = String(geweb_aisearch.selected_model || '').trim();
		        if (this.$modelSelector.length) {
		            this.$modelSelector.val(defaultModel);
		        }
		        this.handleTemporaryModelChange();
		        if (shouldFocusPrompt && this.$temporaryPrompt.length) {
		            this.$temporaryPrompt.trigger('focus');
		        }
		    },

		    resetTemporaryModel(shouldFocusPrompt) {
		        const defaultModel = String(geweb_aisearch.selected_model || '').trim();
		        if (this.$modelSelector.length) {
		            this.$modelSelector.val(defaultModel);
		        }
		        this.handleTemporaryModelChange();
		        if (shouldFocusPrompt && this.$temporaryPrompt.length) {
		            this.$temporaryPrompt.trigger('focus');
		        }
		    },

		    resetTemporaryPrompt(shouldFocusPrompt) {
		        const descriptor = this.getPromptDescriptor(this.getSelectedModel());
		        if (this.$temporaryPrompt.length) {
		            this.$temporaryPrompt.val(descriptor.instruction || '');
		        }
		        this.updateTemporarySettingsSummary();
		        if (shouldFocusPrompt && this.$temporaryPrompt.length) {
		            this.$temporaryPrompt.trigger('focus');
		        }
		    },

		    toggleTemporaryPromptEditor(forceState) {
		        if (!this.$temporaryPromptEditor.length) {
		            return;
		        }

		        const shouldOpen = typeof forceState === 'boolean'
		            ? forceState
		            : this.$temporaryPromptEditor.prop('hidden');

		        this.$temporaryPromptEditor.prop('hidden', !shouldOpen);
		        this.updatePromptEditorToggleButtonState(shouldOpen);

		        if (shouldOpen && this.$temporaryPrompt.length) {
		            this.$temporaryPrompt.trigger('focus');
		        }
		    },

		    updatePromptEditorToggleButtonState(isOpen) {
		        if (!this.$togglePromptEditorBtn.length) {
		            return;
		        }

		        const label = isOpen
		            ? t('composerHidePromptEditor', 'Hide prompt editor')
		            : t('composerEditPrompt', 'Edit prompt');
		        this.$togglePromptEditorBtn.attr('aria-label', label);
		        this.$togglePromptEditorBtn.attr('title', label);
		        this.$togglePromptEditorBtn.attr('aria-expanded', isOpen ? 'true' : 'false');

		        const $icon = this.$togglePromptEditorBtn.find('.geweb-ai-temporary-inline-action-icon--edit');
		        if ($icon.length) {
		            $icon.text(isOpen ? '▴' : '✎');
		        }
		    },

		    updateTemporarySettingsAvailability(isEnabled) {
		        if (this.$modelSelector.length) {
		            this.$modelSelector.prop('disabled', !isEnabled);
		        }
		        if (this.$temporaryPrompt.length) {
		            this.$temporaryPrompt.prop('disabled', !isEnabled);
		        }
		        if (this.$togglePromptEditorBtn.length) {
		            this.$togglePromptEditorBtn.prop('disabled', !isEnabled);
		        }
		        if (this.$resetSettingsBtn.length) {
		            this.$resetSettingsBtn.prop('disabled', !isEnabled);
		        }
		        if (this.$resetModelBtn.length) {
		            this.$resetModelBtn.prop('disabled', !isEnabled || this.$resetModelBtn.prop('disabled'));
		        }
		        if (this.$resetPromptBtn.length) {
		            this.$resetPromptBtn.prop('disabled', !isEnabled || this.$resetPromptBtn.prop('disabled'));
		        }
		    },

		    toggleTemporarySettings(forceState) {
		        if (!this.$settingsPanel.length || !this.$settingsToggle.length) {
		            return;
		        }

		        const shouldOpen = typeof forceState === 'boolean'
		            ? forceState
		            : this.$settingsPanel.prop('hidden');

		        this.$settingsPanel.prop('hidden', !shouldOpen);
		        this.$settingsToggle.attr('aria-expanded', shouldOpen ? 'true' : 'false');
		        this.$settingsToggle.attr('title', shouldOpen ? t('composerClose', 'Close settings') : t('composerSettingsTitle', 'Next question settings'));
		        this.updateTemporarySettingsAvailability(shouldOpen);

		        if (!shouldOpen) {
		            this.toggleTemporaryPromptEditor(false);
		        }
		    },

		    syncModelSelectorWidth() {
		        const select = this.$modelSelector.get(0);
		        if (!select) {
		            return;
		        }

		        if (globalThis.matchMedia && globalThis.matchMedia(`(max-width: ${GewebModal.getWorkspaceAutoCollapseThreshold()}px)`).matches) {
		            select.style.removeProperty('width');
		            select.style.removeProperty('min-width');
		            return;
		        }

		        const optionTexts = Array.from(select.options || []).map((option) => String(option.text || ''));
		        const longest = optionTexts.reduce((best, text) => text.length > best.length ? text : best, '');
		        if (!longest) {
		            return;
		        }

		        const probe = document.createElement('span');
		        probe.textContent = longest;
		        probe.style.position = 'absolute';
		        probe.style.visibility = 'hidden';
		        probe.style.whiteSpace = 'nowrap';
		        probe.style.font = globalThis.getComputedStyle(select).font;
		        document.body.appendChild(probe);
		        const width = Math.ceil(probe.getBoundingClientRect().width) + 56;
		        document.body.removeChild(probe);
		        select.style.width = `${width}px`;
		        select.style.minWidth = `${width}px`;
		    },

		    handleDocumentClick(event) {
		        const $target = $(event.target);
		        const clickedInsideFootnotePreview = $target.closest('.geweb-ai-footnote-preview, .geweb-ai-footnote-ref').length > 0;
		        if (!clickedInsideFootnotePreview) {
		            this.hideFootnotePreview();
		        }
		        const clickedInsideInfoPopover = $target.closest('.geweb-ai-message-info-popover, .geweb-ai-message-info-toggle').length > 0;
		        if (!clickedInsideInfoPopover) {
		            this.hideMessageInfoPopover();
		        }
		        const clickedInsideScrollablePane = $target.closest('#geweb-ai-conversation-overview, .answer-box, #geweb-ai-sources, .geweb-ai-search-results-content').length > 0;
		        if (!clickedInsideScrollablePane) {
		            this.setScrollablePaneActive(null);
		        }

		        if (!this.$settingsPanel.length || this.$settingsPanel.prop('hidden')) {
		            return;
		        }

		        if (
		            $target.closest('#geweb-ai-temporary-settings-panel').length ||
		            $target.closest('[data-geweb-temp-settings-toggle="1"]').length
		        ) {
		            return;
		        }

		        this.toggleTemporarySettings(false);
		    },

	    async sendMessage(prefilledMessage) {
	        const message = typeof prefilledMessage === 'string'
	            ? prefilledMessage.trim()
	            : this.$textarea.val().trim();

	        if (!message || this.requestInFlight) {
return;
}

	        const userEntry = {
	            role: 'user',
	            content: message,
	            created_at: this.normalizeEpochSeconds(Math.floor(Date.now() / 1000))
	        };
	        this.conversationHistory.push(userEntry);
	        this.appendMessage(message, 'user', { historyIndex: this.conversationHistory.length - 1, messageData: userEntry });
	        this.renderConversationSummary();
	        this.renderSources();

	        this.$textarea.val('');
	        this.requestInFlight = true;
	        this.historyNavIndex = -1;
	        this.syncHistoryButtons();
	        this.toggleSubmitButton();
	        GewebModal.schedulePageViewViewportSync();

	        const supportsThoughtProcess = this.modelSupportsThoughtProcess(this.getSelectedModel());
	        const loaderLabel = supportsThoughtProcess
	            ? t('thoughtProcessPending', 'Collecting thought process...')
	            : t('thinking', 'Thinking...');
	        const loaderClassName = supportsThoughtProcess
	            ? 'ai-message loading geweb-ai-thinking-indicator geweb-ai-thinking-indicator--thoughts'
	            : 'ai-message loading geweb-ai-thinking-indicator';
	        const $loader = $(`<div class="${loaderClassName}" aria-live="polite" style="animation:none; transition:opacity 0.3s ease-in-out;"></div>`);
	        this.renderLoaderState($loader, {
	            supportsThoughtProcess: supportsThoughtProcess,
	            label: loaderLabel,
	            thoughts: [],
	        });
	        this.$answerBox.append($loader);
	        this.scrollToBottom();

	        try {
	            await ensureSearchNonce();
		        } catch (error) {
		            console.debug('Could not initialize nonce before sending chat message.', error);
		            $loader.remove();
	            this.requestInFlight = false;
	            await this.appendAiHistoryEntry(this.buildAiHistoryEntry({
	                content: this.normalizeAiErrorMessage(
	                    t('couldNotStart', 'Could not start the AI search. Please try again.'),
	                    t('couldNotStart', 'Could not start the AI search. Please try again.')
	                ),
	                meta: {
	                    error: true,
	                    error_type: 'nonce_init'
	                }
	            }));
	            this.toggleSubmitButton();
		            return;
		        }

	        const requestData = {
	            action: 'geweb_ai_chat',
	            nonce: geweb_aisearch.search_nonce,
	            conversation_id: this.ensureConversationId(),
		            model: this.getSelectedModel(),
		            temporary_prompt: this.getTemporaryPrompt(),
		            excluded_sources: JSON.stringify(this.getExcludedSourcesForRequest()),
		            messages: [{
		                role: 'user',
		                content: message,
		                created_at: userEntry.created_at
		            }]
		        };
		        let nonceRetried = false;
		        const sendAjaxRequest = () => {
		            $.ajax({
		                url: geweb_aisearch.ajax_url,
		                type: 'POST',
		                timeout: 20000,
		                data: requestData,
		                success: (response) => {
		                    if (response?.success && response?.data?.queued) {
		                        const resolvedConversationId = String(response.data.conversation_id || requestData.conversation_id || '').trim();
		                        if (resolvedConversationId) {
		                            this.conversationId = resolvedConversationId;
		                            this.syncFrontendPageConversationState();
		                            this.syncStoredChatState();
		                        }
		                        this.pollAiChatJob(String(response.data.job_id || '').trim(), $loader).catch((error) => console.debug('Polling AI chat job failed.', error));
		                        return;
		                    }

		                    this.handleResponse(response, $loader).catch((error) => console.debug('Handling AI response failed.', error));
		                },
		                error: (xhr) => {
		                    if (!nonceRetried && this.isNonceFailureResponse(xhr)) {
		                        nonceRetried = true;
		                        clearSearchNonce();
		                        ensureSearchNonce()
		                            .then((freshNonce) => {
		                                requestData.nonce = freshNonce;
		                                sendAjaxRequest();
		                            })
		                            .catch((error) => {
		                                console.debug('Could not refresh expired AI search nonce.', error);
		                                this.handleError($loader, xhr, requestData.conversation_id, userEntry.created_at).catch((err) => console.debug('Handling AI error failed.', err));
		                            });
		                        return;
		                    }

		                    this.handleError($loader, xhr, requestData.conversation_id, userEntry.created_at).catch((error) => console.debug('Handling AI error failed.', error));
		                }
		            });
		        };

		        sendAjaxRequest();
		        this.toggleTemporarySettings(false);
		    },

		async handleResponse(response, $loader) {
		    $loader.remove();
		    this.requestInFlight = false;
		    if (response?.success && response?.data) {
		        this.compactedConversation = !!response.data.context_compacted;
		        this.currentContextSummary = String(response.data.context_summary || '').trim();
		        const aiEntry = this.buildAiHistoryEntry({
		            content: response.data.answer,
		            sources: Array.isArray(response.data.sources) ? response.data.sources : [],
		            meta: response.data?.meta && typeof response.data.meta === 'object' ? response.data.meta : {}
		        });
		        const responseRequestContext = response.data?.meta?.request && typeof response.data.meta.request === 'object'
		            ? response.data.meta.request
		            : {};
		        const responseSummary = String(responseRequestContext.context_summary || response.data.context_summary || '').trim();
		        if (responseSummary) {
		            this.appendContextSummaryNote(responseSummary);
		        }
		        await this.appendAiHistoryEntry(aiEntry);
			    } else {
		        const backendMessage = response?.data?.message ? String(response.data.message) : '';
		        await this.appendAiHistoryEntry(this.buildAiHistoryEntry({
		            content: this.normalizeAiErrorMessage(backendMessage, t('answerError', 'Error: Unable to get response')),
		            meta: {
		                error: true,
		                error_type: 'backend',
		                raw_message: backendMessage
		            }
		        }));
			    }
			        this.toggleSubmitButton();
				},

		async handleError($loader, xhr, conversationId = '', userCreatedAt = null) {
		    $loader.remove();
		    this.requestInFlight = false;
		    const ajaxErrorMessage = this.getAjaxErrorMessage(xhr, t('connectionError', 'Connection error. Please try again.'));
		    const shouldRecover = this.shouldAttemptResponseRecovery(xhr) && String(conversationId || '').trim() !== '';
		    await this.appendAiHistoryEntry(this.buildAiHistoryEntry({
		        content: shouldRecover
		            ? this.buildTransportRecoveryMessage(ajaxErrorMessage)
		            : this.normalizeAiErrorMessage(ajaxErrorMessage, t('connectionError', 'Connection error. Please try again.')),
		        meta: {
		            error: true,
		            error_type: shouldRecover ? 'transport_recoverable' : 'transport',
		            pending_recovery: shouldRecover,
		            raw_message: ajaxErrorMessage,
		            http_status: Number(xhr?.status || 0) || null
		        }
		    }));
	        this.syncHistoryButtons();
	        this.toggleSubmitButton();

	        if (shouldRecover) {
	            this.pollForRecoveredResponse(conversationId, userCreatedAt).catch((error) => console.debug('Polling for recovered response failed.', error));
	        }
		},

		appendMessage(text, type, options = {}) {
	        if (type === 'user') {
		        const questionText = String(text || '');
		        const historyIndex = Number.isInteger(options?.historyIndex) ? options.historyIndex : -1;
		        const messageData = options?.messageData && typeof options.messageData === 'object' ? options.messageData : {};
		        const linkedResponseMetaFromHistory = historyIndex >= 0 && this.conversationHistory[historyIndex + 1]?.role === 'model'
		            ? (this.conversationHistory[historyIndex + 1].meta || {})
		            : {};
		        const responseMeta = options?.responseMeta && typeof options.responseMeta === 'object'
		            ? options.responseMeta
		            : linkedResponseMetaFromHistory;
		        const $msg = $(`<div class="user-message" role="button" tabindex="0"></div>`);
		        if (historyIndex >= 0) {
		            $msg.attr('data-history-index', String(historyIndex));
		        }
		        const $text = $('<span class="geweb-ai-user-message-text"></span>').html(this.escapeHtml(questionText));
		        $msg.append($text);
		        if (historyIndex >= 0) {
		            const $removeButton = $('<button type="button" class="geweb-ai-user-message-remove" aria-label="Remove question and answer" title="Remove question and answer"><span aria-hidden="true">−</span></button>');
		            $removeButton.on('click', async (event) => {
		                event.preventDefault();
		                event.stopPropagation();
		                await this.removeConversationTurn(historyIndex);
		            });
		            $msg.append($removeButton);
		        }
		        const userTooltip = this.buildMessageTooltip({
		            type: 'user',
		            content: questionText,
		            messageData: messageData,
		            responseMeta: responseMeta,
		            includeReuseHint: true,
		        });
		        const $userInfoButton = GewebModal.shouldShowQuestionInfoButton()
		            ? this.buildMessageInfoButton(userTooltip, {
		                extraClass: 'geweb-ai-question-info-toggle',
		                showActionLabel: false,
		            })
		            : null;
		        if ($userInfoButton) {
		            $msg.append($userInfoButton);
		        }
		        $msg.attr('title', userTooltip || t('reuseQuestion', 'Reuse this question'));
		        $msg.attr('aria-label', t('reuseQuestion', 'Reuse this question'));
		        $msg.on('click', () => {
		            this.populateQuestionBox(questionText, { focus: true });
		        });
		        $msg.on('keydown', (event) => {
		            if (event.key !== 'Enter' && event.key !== ' ') {
		                return;
		            }

		            event.preventDefault();
		            this.populateQuestionBox(questionText, { focus: true });
		        });
		        this.$answerBox.append($msg);
		        this.scrollElementIntoView($msg.get(0));
		    } else {
		        const $container = $('<div class="ai-message"></div>');
		        const responseMeta = text?.meta && typeof text.meta === 'object' ? text.meta : {};
		        const sourceFootnoteMap = this.getResponseSourceFootnoteMap(text.sources || [], text.answer || '', responseMeta);
		        const answerWithFootnotes = this.decorateAnswerWithGroundingFootnotes(String(text.answer || ''), responseMeta, sourceFootnoteMap, text.sources || []);
		        const sanitizedAnswer = this.sanitizeAnswer(answerWithFootnotes);
		        const $content = $('<div class="geweb-ai-message-content"></div>').html(sanitizedAnswer);
		        const $thoughtProcess = this.buildThoughtProcessBlock(responseMeta);
		        const fallbackFootnotes = this.getFallbackFootnoteNumbers(sourceFootnoteMap, text.sources || []);
		        if (fallbackFootnotes.length) {
		            $content.append($(this.appendFallbackFootnoteGroup('', fallbackFootnotes)));
		        }
		        const plainText = this.extractPlainTextFromHtml(sanitizedAnswer);
		        const $copyButton = this.buildCopyButton(plainText);
		        const $details = this.buildResponseDetails(responseMeta);
		        const $messageActions = $('<div class="geweb-ai-message-actions"></div>');

		        if (responseMeta.error) {
		            const $retryButton = $('<button type="button" class="geweb-ai-retry-answer" aria-live="polite"></button>');
		            $retryButton.attr('aria-label', t('retryRequest', 'Retry request'));
		            $retryButton.append($('<span class="geweb-ai-message-action-icon" aria-hidden="true" style="font-size:15px; line-height:1;">↻</span>'));
		            $retryButton.append($('<span class="geweb-ai-message-action-label"></span>').text(t('retryRequest', 'Retry')));
		            $retryButton.on('click', async (event) => {
		                event.preventDefault();
		                event.stopPropagation();

		                let lastUserIndex = -1;
		                for (let i = this.conversationHistory.length - 1; i >= 0; i--) {
		                    if (this.conversationHistory[i].role === 'user') {
		                        lastUserIndex = i;
		                        break;
		                    }
		                }

		                if (lastUserIndex >= 0) {
		                    const questionText = this.conversationHistory[lastUserIndex].content;
		                    await this.removeConversationTurn(lastUserIndex);
		                    this.populateQuestionBox(questionText, { focus: false });
		                    this.sendMessage().catch((error) => console.debug('Retry sendMessage failed.', error));
		                }
		            });
		            $messageActions.append($retryButton);
		        } else if ($copyButton) {
		            $messageActions.append($copyButton);
		        }

		        if ($content.find('.geweb-ai-footnote-ref').length) {
		            this.bindFootnoteInteractions($content);
		        }

		        if ($details) {
		            $details.find('.geweb-ai-response-details-close').on('click', (event) => {
		                event.preventDefault();
		                event.stopPropagation();
		                this.toggleResponseDetails($container);
		                $detailsButton.attr('aria-expanded', 'false');
		                $detailsButton.attr('aria-label', t('showDetails', 'Show details'));
		                $detailsButton.find('.geweb-ai-message-action-label').text(t('showDetails', 'Show details'));
		            });
		            const $detailsButton = $('<button type="button" class="geweb-ai-icon-button geweb-ai-message-details-toggle"></button>');
		            $detailsButton.attr('aria-expanded', 'false');
		            $detailsButton.attr('aria-label', t('showDetails', 'Show details'));
		            $detailsButton.append($('<span class="geweb-ai-message-action-icon geweb-ai-message-action-icon--details" aria-hidden="true">ⓘ</span>'));
		            $detailsButton.append($('<span class="geweb-ai-message-action-label"></span>').text(t('showDetails', 'Show details')));
		            $detailsButton.on('click', () => {
		                this.toggleResponseDetails($container);
		                const expanded = $details.hasClass('is-open');
		                $detailsButton.attr('aria-expanded', expanded ? 'true' : 'false');
		                $detailsButton.attr('aria-label', expanded ? t('hideDetails', 'Hide details') : t('showDetails', 'Show details'));
		                $detailsButton.find('.geweb-ai-message-action-label').text(expanded ? t('hideDetails', 'Hide details') : t('showDetails', 'Show details'));
		            });
		            $messageActions.append($detailsButton);
		        }

		        if ($messageActions.children().length) {
		            $content.prepend($messageActions);
		        }
		        if ($thoughtProcess) {
		            $container.append($thoughtProcess);
		        }
		        $container.append($content);
		        if ($details) {
		            $container.append($details);
		        }
		        this.normalizeWorkspaceControlTitles($container);

		        this.$answerBox.append($container);
		        this.scrollElementIntoView($container.get(0));
		    }

		    this.scrollToBottom();
		},

	    ensureMessageInfoPopover() {
	        if (this.$messageInfoPopover && this.$messageInfoPopover.length) {
	            return this.$messageInfoPopover;
	        }

	        this.$messageInfoPopover = $('<div class="geweb-ai-message-info-popover" role="dialog" aria-hidden="true" hidden></div>');
	        this.$messageInfoPopoverContent = $('<div class="geweb-ai-message-info-popover-content"></div>');
	        this.$messageInfoPopover.append(this.$messageInfoPopoverContent);
	        $('body').append(this.$messageInfoPopover);
	        return this.$messageInfoPopover;
	    },

	    positionMessageInfoPopover($anchor) {
	        if (!this.$messageInfoPopover || !this.$messageInfoPopover.length || !$anchor || !$anchor.length) {
	            return;
	        }

	        const anchorRect = $anchor.get(0).getBoundingClientRect();
	        const popover = this.$messageInfoPopover.get(0);
	        const viewportWidth = globalThis.innerWidth || document.documentElement.clientWidth || 0;
	        const viewportHeight = globalThis.innerHeight || document.documentElement.clientHeight || 0;
	        const margin = 8;
	        const spacing = 10;

	        let left = anchorRect.right - popover.offsetWidth;
	        left = Math.max(margin, Math.min(left, viewportWidth - popover.offsetWidth - margin));

	        let top = anchorRect.top - popover.offsetHeight - spacing;
	        if (top < margin) {
	            top = anchorRect.bottom + spacing;
	        }
	        top = Math.max(margin, Math.min(top, viewportHeight - popover.offsetHeight - margin));

	        this.$messageInfoPopover.css({ left: `${left}px`, top: `${top}px` });
	    },

	    showMessageInfoPopover($anchor, text) {
	        const content = String(text || '').trim();
	        if (!content || !$anchor || !$anchor.length) {
	            return;
	        }

	        const $popover = this.ensureMessageInfoPopover();
	        this.$messageInfoPopoverContent.text(content);
	        $popover.prop('hidden', false).attr('aria-hidden', 'false').addClass('is-open');
	        this.$messageInfoPopoverAnchor = $anchor;
	        this.positionMessageInfoPopover($anchor);
	    },

	    hideMessageInfoPopover() {
	        if (!this.$messageInfoPopover || !this.$messageInfoPopover.length) {
	            return;
	        }

	        this.$messageInfoPopover.removeClass('is-open').attr('aria-hidden', 'true').prop('hidden', true);
	        this.$messageInfoPopoverAnchor = null;
	    },

	    toggleMessageInfoPopover($anchor, text) {
	        const isOpen = !!(this.$messageInfoPopover && this.$messageInfoPopover.hasClass('is-open'));
	        const sameAnchor = !!(this.$messageInfoPopoverAnchor && $anchor && this.$messageInfoPopoverAnchor.get(0) === $anchor.get(0));
	        if (isOpen && sameAnchor) {
	            this.hideMessageInfoPopover();
	            return;
	        }

	        this.showMessageInfoPopover($anchor, text);
	    },

	    buildMessageInfoButton(tooltipText, options = {}) {
	        const text = String(tooltipText || '').trim();
	        if (!text) {
	            return null;
	        }

	        const extraClass = String(options?.extraClass || '').trim();
	        const showActionLabel = options?.showActionLabel !== false;
	        const buttonClassName = ['geweb-ai-icon-button', 'geweb-ai-message-info-toggle', extraClass].filter(Boolean).join(' ');
	        const $button = $(`<button type="button" class="${buttonClassName}"></button>`);
	        const infoLabel = t('messageInfo', 'Message info');
	        $button.attr('aria-label', infoLabel);
	        $button.append($('<span class="geweb-ai-message-action-icon geweb-ai-message-action-icon--details" aria-hidden="true">ⓘ</span>'));
	        if (showActionLabel) {
	            $button.append($('<span class="geweb-ai-message-action-label"></span>').text(infoLabel));
	        }
	        $button.on('click', (event) => {
	            event.preventDefault();
	            event.stopPropagation();
	            this.toggleMessageInfoPopover($button, text);
	        });
	        return $button;
	    },

	    extractPlainTextFromHtml(html) {
	        const container = document.createElement('div');
	        container.innerHTML = String(html || '');
	        return String(container.textContent || container.innerText || '').replaceAll(/\s+/g, ' ').trim();
	    },

	    normalizeEpochSeconds(value) {
	        if (value === null || value === undefined || value === '') {
	            return null;
	        }

	        const numeric = Number(value);
	        if (!Number.isFinite(numeric) || numeric <= 0) {
	            return null;
	        }

	        return numeric > 1000000000000 ? Math.floor(numeric / 1000) : Math.floor(numeric);
	    },

	    formatTooltipTimestamp(epochSeconds) {
	        const normalized = this.normalizeEpochSeconds(epochSeconds);
	        if (!normalized) {
	            return '';
	        }

	        try {
	            return new Date(normalized * 1000).toISOString().slice(0, 10);
	        } catch (_) {
	            return '';
	        }
	    },

	    buildMessageTooltip({ type = 'user', content = '', messageData = {}, responseMeta = {}, includeReuseHint = false } = {}) {
	        const lines = [];
	        const normalizedType = String(type || '').toLowerCase() === 'ai' ? 'AI answer' : 'User question';
	        lines.push(normalizedType);

	        const createdAt = this.normalizeEpochSeconds(messageData?.created_at ?? messageData?.createdAt ?? responseMeta?.request?.created_at);
	        const createdLabel = this.formatTooltipTimestamp(createdAt);
	        if (createdLabel) {
	            lines.push(`Time: ${createdLabel}`);
	        }

	        const plainContent = this.extractPlainTextFromHtml(String(content || ''));
	        if (plainContent) {
	            lines.push(`Chars: ${plainContent.length}`);
	        }

	        const isAiTooltip = String(type || '').toLowerCase() === 'ai';
	        if (isAiTooltip || (responseMeta && typeof responseMeta === 'object' && Object.keys(responseMeta).length > 0)) {
	            const usage = responseMeta?.usage && typeof responseMeta.usage === 'object' ? responseMeta.usage : {};
	            const model = String(responseMeta?.model_version || responseMeta?.model || '').trim();
	            if (model) {
	                lines.push(isAiTooltip ? `Model: ${model}` : `Answer model: ${model}`);
	            }
	            if (usage.total_tokens) {
	                lines.push(isAiTooltip ? `Tokens: ${usage.total_tokens}` : `Answer tokens: ${usage.total_tokens}`);
	            }
	            if (usage.input_tokens) {
	                lines.push(isAiTooltip ? `Input tokens: ${usage.input_tokens}` : `Answer input tokens: ${usage.input_tokens}`);
	            }
	            if (usage.output_tokens) {
	                lines.push(isAiTooltip ? `Output tokens: ${usage.output_tokens}` : `Answer output tokens: ${usage.output_tokens}`);
	            }
	            if (usage.thought_tokens) {
	                lines.push(isAiTooltip ? `Thought tokens: ${usage.thought_tokens}` : `Answer thought tokens: ${usage.thought_tokens}`);
	            }
	            if (responseMeta?.estimated_cost_usd !== undefined && responseMeta?.estimated_cost_usd !== null) {
	                const cost = Number(responseMeta.estimated_cost_usd);
	                if (Number.isFinite(cost)) {
	                    lines.push(isAiTooltip ? `Estimated cost: $${cost.toFixed(6)}` : `Answer estimated cost: $${cost.toFixed(6)}`);
	                }
	            }
	        }

	        if (includeReuseHint) {
	            lines.push('Click on the question text to reuse this question.');
	        }

	        return lines.filter(Boolean).join('\n');
	    },

	    modelSupportsThoughtProcess(model) {
	        const normalizedModel = String(model || '').trim().toLowerCase();
	        return normalizedModel.indexOf('gemini-3') === 0;
	    },

	    renderLoaderState($loader, options = {}) {
	        if (!$loader || !$loader.length) {
	            return;
	        }

	        const supportsThoughtProcess = options?.supportsThoughtProcess === true;
	        const label = String(options?.label || '').trim() || t('thinking', 'Thinking...');
	        const thoughts = Array.isArray(options?.thoughts)
	            ? options.thoughts.map((item) => String(item || '').trim()).filter(Boolean)
	            : [];

	        $loader.empty();
	        if (!supportsThoughtProcess) {
	            $loader.text(label);
	            return;
	        }

	        const $block = $('<section class="geweb-ai-thought-process geweb-ai-thought-process--loading"></section>');
	        $block.append($('<div class="geweb-ai-thought-process-title"></div>').text(t('thoughtProcess', 'Denkproces')));
	        if (thoughts.length) {
	            $block.append($('<div class="geweb-ai-thought-process-body"></div>').html(this.renderThoughtProcessHtml(thoughts)));
	        }
	        $loader.append($block);
	    },

	    updateLoaderFromJobProgress($loader, progress) {
	        if (!$loader || !$loader.length || !progress || typeof progress !== 'object') {
	            return;
	        }

	        const supportsThoughtProcess = progress.supports_thoughts === true || $loader.hasClass('geweb-ai-thinking-indicator--thoughts');
	        const label = String(progress.label || '').trim()
	            || (supportsThoughtProcess
	                ? t('thoughtProcessPending', 'Denkproces wordt opgebouwd...')
	                : t('thinking', 'Thinking...'));
	        const thoughts = Array.isArray(progress.thoughts) ? progress.thoughts : [];

	        this.renderLoaderState($loader, {
	            supportsThoughtProcess: supportsThoughtProcess,
	            label: label,
	            thoughts: thoughts,
	        });
	    },

	    getThoughtProcessItems(responseMeta) {
	        if (!responseMeta || typeof responseMeta !== 'object' || !Array.isArray(responseMeta.thoughts)) {
	            return [];
	        }

	        return responseMeta.thoughts
	            .map((item) => String(item || '').trim())
	            .filter(Boolean);
	    },

	    renderThoughtProcessHtml(thoughts) {
	        const normalizedThoughts = Array.isArray(thoughts)
	            ? thoughts.map((item) => String(item || '').trim()).filter(Boolean)
	            : [];
	        if (!normalizedThoughts.length) {
	            return '';
	        }

	        const sections = normalizedThoughts.flatMap((item) => this.splitThoughtProcessSections(item));
	        return sections.map((section) => this.renderThoughtProcessSection(section)).join('');
	    },

	    splitThoughtProcessSections(text) {
	        const normalizedText = String(text || '').trim();
	        if (!normalizedText) {
	            return [];
	        }

	        const matches = Array.from(normalizedText.matchAll(/(?:^|\n\n)([A-Z][^\n]{0,100})\n\n([\s\S]*?)(?=\n\n[A-Z][^\n]{0,100}\n\n|$)/gu));
	        if (!matches.length) {
	            return [{ title: '', body: normalizedText }];
	        }

	        return matches.map((match) => ({
	            title: String(match[1] || '').trim(),
	            body: String(match[2] || '').trim(),
	        })).filter((section) => section.title || section.body);
	    },

	    renderThoughtProcessSection(section) {
	        const title = String(section?.title || '').trim();
	        const body = String(section?.body || '').trim();
	        const bodyHtml = this.renderThoughtProcessMarkdown(body || title);
	        if (!title) {
	            return `<div class="geweb-ai-thought-process-entry"><div class="geweb-ai-thought-process-entry-body">${bodyHtml}</div></div>`;
	        }

	        return [
	            '<div class="geweb-ai-thought-process-entry">',
	            `<div class="geweb-ai-thought-process-entry-title">${this.escapeHtml(title)}</div>`,
	            `<div class="geweb-ai-thought-process-entry-body">${bodyHtml}</div>`,
	            '</div>',
	        ].join('');
	    },

	    renderThoughtProcessMarkdown(text) {
	        const markdown = String(text || '').trim();
	        if (!markdown) {
	            return '';
	        }

	        if (globalThis.GewebAisearchMarkdown && typeof globalThis.GewebAisearchMarkdown.render === 'function') {
	            return String(globalThis.GewebAisearchMarkdown.render(markdown) || '').trim();
	        }

	        return '<p>' + this.escapeHtml(markdown).replaceAll(/\n/g, '<br>') + '</p>';
	    },

	    buildThoughtProcessBlock(responseMeta) {
	        const thoughts = this.getThoughtProcessItems(responseMeta);
	        if (!thoughts.length) {
	            return null;
	        }

	        const $block = $('<section class="geweb-ai-thought-process" aria-live="polite"></section>');
	        $block.append($('<div class="geweb-ai-thought-process-title"></div>').text(t('thoughtProcess', 'Thought process')));
	        $block.append($('<div class="geweb-ai-thought-process-body"></div>').html(this.renderThoughtProcessHtml(thoughts)));
	        return $block;
	    },

	    buildCopyButton(text) {
	        const plainText = String(text || '').trim();
	        if (!plainText) {
	            return null;
	        }

	        const $button = $('<button type="button" class="geweb-ai-copy-answer" aria-live="polite"></button>');
	        $button.attr('aria-label', t('copyAnswer', 'Copy answer'));
	        $button.append($('<span class="geweb-ai-copy-answer-icon" aria-hidden="true">⧉</span>'));
	        $button.append($('<span class="geweb-ai-message-action-label"></span>').text(t('copyAnswer', 'Copy answer')));
	        $button.on('click', async () => {
	            const copied = await this.copyTextToClipboard(plainText);
	            $button.toggleClass('is-copied', copied);
	            $button.attr('aria-label', copied ? t('copied', 'Copied') : t('copyFailed', 'Could not copy'));

	            globalThis.setTimeout(() => {
	                $button.removeClass('is-copied');
	                $button.attr('aria-label', t('copyAnswer', 'Copy answer'));
	            }, 1600);
	        });

	        return $button;
	    },

	    bindFootnoteInteractions($content) {
	        const $footnotes = $content.find('.geweb-ai-footnote-ref');
	        if (!$footnotes.length) {
	            return;
	        }

	        $footnotes.each((_, element) => {
	            const $footnote = $(element);
	            const footnote = Number($footnote.attr('data-footnote') || 0);
	            if (footnote <= 0) {
	                return;
	            }

	            $footnote.attr('tabindex', '0');
	            $footnote.attr('role', 'button');
	            $footnote.attr('aria-label', `Show source reference ${footnote}`);
	        });

	        $footnotes.on('mouseenter focus', (event) => {
	            const $footnote = $(event.currentTarget);
	            const footnote = Number($footnote.attr('data-footnote') || 0);
	            if (footnote > 0) {
	                this.highlightSourceReference(footnote, { mode: 'preview' });
	                this.showFootnotePreview($footnote, footnote);
	            }
	        });

	        $footnotes.on('mouseleave blur', () => {
	            this.clearAllSourceReferencePreviews?.();
	            this.hideFootnotePreview();
	        });

	        $footnotes.on('click', (event) => {
	            event.preventDefault();
	            event.stopPropagation();
	            const $footnote = $(event.currentTarget);
	            const footnote = Number($footnote.attr('data-footnote') || 0);
	            if (footnote > 0) {
	                const isSamePreviewOpen = this.$footnotePreview?.hasClass('is-visible')
	                    && Number(this.$footnotePreview.attr('data-footnote') || 0) === footnote;
	                if (this.shouldUseTapPreviewForFootnotes() && isSamePreviewOpen) {
	                    this.highlightSourceReference(footnote, { mode: 'active' });
	                    this.hideFootnotePreview();
	                    return;
	                }

	                this.showFootnotePreview($footnote, footnote);
	                this.highlightSourceReference(footnote, { mode: this.shouldUseTapPreviewForFootnotes() ? 'preview' : 'active' });
	            }
	        });

	        $footnotes.on('keydown', (event) => {
	            if (event.key !== 'Enter' && event.key !== ' ') {
	                return;
	            }

	            event.preventDefault();
	            event.stopPropagation();
	            const $footnote = $(event.currentTarget);
	            const footnote = Number($footnote.attr('data-footnote') || 0);
	            if (footnote > 0) {
	                this.showFootnotePreview($footnote, footnote);
	                this.highlightSourceReference(footnote, { mode: 'active' });
	            }
	        });
	    },

	    shouldUseTapPreviewForFootnotes() {
	        return !!globalThis.matchMedia
	            && globalThis.matchMedia('(hover: none) and (pointer: coarse)').matches;
	    },

	    getSourceReferenceItem(footnote) {
	        if (!this.$sourcesBox.length || footnote <= 0) {
	            return $();
	        }

	        return this.$sourcesBox.find(`[data-source-footnote="${footnote}"]`).first();
	    },

	    showFootnotePreview($footnote, footnote) {
	        const $sourceItem = this.getSourceReferenceItem(footnote);
	        if (!$footnote?.length || !$sourceItem.length) {
	            this.hideFootnotePreview();
	            return;
	        }

	        const title = String($sourceItem.attr('data-source-title') || '').trim();
	        const snippet = String($sourceItem.attr('data-source-snippet') || '').trim();
	        const path = String($sourceItem.attr('data-source-path') || '').trim();
	        const contextCount = Number($sourceItem.attr('data-source-context-count') || 0);
	        if (!title && !snippet && !path) {
	            this.hideFootnotePreview();
	            return;
	        }

	        if (!this.$footnotePreview?.length) {
	            this.$footnotePreview = $('<div class="geweb-ai-footnote-preview" role="dialog" tabindex="0"></div>');
	            this.$footnotePreview.on('click', (event) => {
	                if ($(event.target).closest('.geweb-ai-footnote-preview-close').length) {
	                    return;
	                }

	                event.preventDefault();
	                event.stopPropagation();
	                const activeFootnote = Number(this.$footnotePreview.attr('data-footnote') || 0);
	                if (activeFootnote > 0) {
	                    this.highlightSourceReference(activeFootnote, { mode: 'active' });
	                    this.hideFootnotePreview();
	                }
	            });
	            this.$footnotePreview.on('keydown', (event) => {
	                if (event.key !== 'Enter' && event.key !== ' ') {
	                    return;
	                }

	                event.preventDefault();
	                event.stopPropagation();
	                const activeFootnote = Number(this.$footnotePreview.attr('data-footnote') || 0);
	                if (activeFootnote > 0) {
	                    this.highlightSourceReference(activeFootnote, { mode: 'active' });
	                    this.hideFootnotePreview();
	                }
	            });
	            this.$footnotePreview.on('click', '.geweb-ai-footnote-preview-close', (event) => {
	                event.preventDefault();
	                event.stopPropagation();
	                this.clearAllSourceReferencePreviews?.();
	                this.hideFootnotePreview();
	            });
	            $('body').append(this.$footnotePreview);
	        }

	        this.$footnotePreview.empty();
	        this.$footnotePreview.attr('data-footnote', String(footnote));
	        this.$footnotePreview.attr('aria-label', `Open source reference ${footnote}`);
	        const $header = $('<div class="geweb-ai-footnote-preview-header"></div>');
	        const $body = $('<div class="geweb-ai-footnote-preview-body"></div>');
	        const $closeButton = $('<button type="button" class="geweb-ai-footnote-preview-close" aria-label="Close source preview" title="Close source preview"></button>');
	        $closeButton.append($('<span aria-hidden="true">×</span>'));
	        $header.append($closeButton);
	        this.$footnotePreview.append($header, $body);
	        this.$footnotePreview.toggleClass('is-touch-preview', this.shouldUseTapPreviewForFootnotes());
	        if (title) {
	            $body.append($('<div class="geweb-ai-footnote-preview-title"></div>').text(title));
	        }
	        if (path) {
	            $body.append($('<div class="geweb-ai-footnote-preview-meta"></div>').text(path));
	        }
	        if (contextCount > 1) {
	            $body.append($('<div class="geweb-ai-footnote-preview-meta"></div>').text(`${contextCount} contexts`));
	        }
	        if (snippet) {
	            $body.append($('<div class="geweb-ai-footnote-preview-snippet"></div>').text(snippet));
	        }

	        const rect = $footnote.get(0).getBoundingClientRect();
	        const top = globalThis.scrollY + rect.bottom + 8;
	        const left = globalThis.scrollX + rect.left;
	        this.$footnotePreview.css({
	            top: `${top}px`,
	            left: `${left}px`,
	        }).addClass('is-visible');
	    },

	    hideFootnotePreview() {
	        if (this.$footnotePreview?.length) {
	            this.$footnotePreview.removeClass('is-visible');
	            this.$footnotePreview.removeAttr('data-footnote');
	        }
	    },

	    buildCurrentConversationClipboardText() {
	        if (!Array.isArray(this.conversationHistory) || !this.conversationHistory.length) {
	            return '';
	        }

	        return this.conversationHistory
	            .map((item) => {
	                if (!item || typeof item !== 'object') {
	                    return '';
	                }

	                const role = item.role === 'model' ? 'AI' : 'User';
		                const content = item.role === 'model'
		                    ? this.extractPlainTextFromHtml(String(item.content || ''))
		                    : String(item.content || '').replaceAll(/\s+/g, ' ').trim();

	                return content ? `${role}: ${content}` : '';
	            })
	            .filter(Boolean)
	            .join('\n\n')
	            .trim();
	    },

	    async copyCurrentConversation() {
	        const text = this.buildCurrentConversationClipboardText();
	        if (!text || !this.$copyConversationBtn.length) {
	            return;
	        }

	        const copied = await this.copyTextToClipboard(text);
	        this.$copyConversationBtn.toggleClass('is-copied', copied);
	        this.$copyConversationBtn.attr('aria-label', copied ? t('copied', 'Copied') : t('copyFailed', 'Could not copy'));

	        globalThis.setTimeout(() => {
	            this.$copyConversationBtn.removeClass('is-copied');
	            this.$copyConversationBtn.attr('aria-label', t('copyConversation', 'Copy chat'));
	        }, 1600);
	    },

	    async copyTextToClipboard(text) {
	        const value = String(text || '').trim();
	        if (!value) {
	            return false;
	        }

	        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
	            try {
	                await navigator.clipboard.writeText(value);
	                return true;
	            } catch (error) {
	                console.debug('Clipboard API copy failed, falling back.', error);
	            }
	        }

	        return this.copyTextToClipboardLegacy(value);
	    },

	    copyTextToClipboardLegacy(value) {
	        const textarea = document.createElement('textarea');
	        textarea.value = value;
	        textarea.setAttribute('readonly', 'readonly');
	        textarea.style.position = 'absolute';
	        textarea.style.left = '-9999px';
	        document.body.appendChild(textarea);
	        textarea.select();

	        try {
	            textarea.setSelectionRange(0, textarea.value.length);
	        } catch (error) {
	            console.debug('Preparing clipboard fallback failed.', error);
	        }

	        textarea.remove();
	        return false;
	    },

	    toggleResponseDetails($message) {
	        const $details = $message.find('.geweb-ai-response-details');
	        const $content = $message.find('.geweb-ai-message-content');
	        if (!$details.length) {
	            return;
	        }

	        const shouldShow = !$details.hasClass('is-open');
	        $details.toggleClass('is-open', shouldShow);
	        $content.attr('aria-expanded', shouldShow ? 'true' : 'false');
	        if (shouldShow) {
	            const detailsElement = $details.get(0);
	            if (detailsElement && typeof detailsElement.scrollIntoView === 'function') {
	                globalThis.setTimeout(() => {
	                    detailsElement.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
	                }, 0);
	            }
	        }
	    },

	    scrollToBottom() {
	        this.$answerBox[0].scrollTop = this.$answerBox[0].scrollHeight;
	    },

	    async persistConversation() {
	        const entry = this.upsertCurrentConversationInArchive();
	        if (!entry) {
	            return;
	        }

	        try {
	            const response = await this.requestFrontendConversation('geweb_save_frontend_conversation', {
	                conversation_id: entry.id,
	                summary: entry.summary,
	                compacted: entry.compacted ? '1' : '0',
	                context_summary: entry.contextSummary,
	                messages: entry.messages
	            });
	            const savedConversation = response?.success ? response?.data?.conversation : null;
	            if (savedConversation && typeof savedConversation === 'object') {
	                const normalized = this.normalizeStoredConversation(savedConversation);
	                this.conversationArchive = this.conversationArchive.filter((item) => item.id !== normalized.id);
	                this.conversationArchive.unshift(normalized);
	            }
	        } catch (error) {
	            console.debug('Persist conversation failed.', error);
	        }

	        this.syncFrontendPageConversationState();
	        this.syncStoredChatState();
	    },

	    async requestFrontendConversation(action, data) {
	        await ensureSearchNonce();

	        return $.ajax({
	            url: geweb_aisearch.ajax_url,
	            type: 'POST',
	            data: {
	                action: action,
	                nonce: geweb_aisearch.search_nonce,
	                ...data
	            }
	        });
	    },

	    async loadConversationArchive() {
	        try {
	            const response = await this.requestFrontendConversation('geweb_get_frontend_conversations', {});
	            const conversations = Array.isArray(response?.data?.conversations)
	                ? response.data.conversations
	                : [];
	            this.conversationArchive = conversations
	                .filter((entry) => entry && typeof entry === 'object')
	                .map((entry) => this.normalizeStoredConversation(entry));
	        } catch (error) {
	            console.debug('Load conversation archive failed.', error);
	        }
	    },

	    renderConversationOverview() {
	        if (!this.$conversationOverview.length) {
	            return;
	        }

	        this.$conversationOverview.empty();
	        this.conversationOverviewRenderedCount = 0;

	        if (!this.conversationArchive.length) {
	            this.$conversationOverview.append($('<p class="geweb-ai-empty-panel"></p>').text(t('noChatsYet', 'No chats yet.')));
	            return;
	        }

	        this.$conversationOverview.append($('<div class="geweb-ai-overview-list"></div>'));

	        this.renderMoreConversationOverviewItems();
	        this.normalizeWorkspaceControlTitles(this.$conversationOverview);
	    },

	    renderMoreConversationOverviewItems() {
	        if (!this.$conversationOverview.length) {
	            return;
	        }

	        const $overviewList = this.$conversationOverview.find('.geweb-ai-overview-list').first();
	        if (!$overviewList.length) {
	            return;
	        }

	        const nextEntries = this.conversationArchive.slice(
	            this.conversationOverviewRenderedCount,
	            this.conversationOverviewRenderedCount + this.conversationOverviewPageSize
	        );

	        nextEntries.forEach((entry) => {
	            const $item = $('<button type="button" class="geweb-ai-overview-item geweb-ai-overview-item--conversation"></button>');
	            if (entry.id === this.conversationId) {
	                $item.addClass('is-current');
	            }

	            const dateLabel = entry.savedAt ? new Date(entry.savedAt).toISOString().slice(0, 10) : '';
	            const summaryLabel = entry.summary || t('untitledConversation', 'Untitled chat');
	            const tooltipLabel = dateLabel ? `${summaryLabel} (${dateLabel})` : summaryLabel;
	            $item.attr('title', tooltipLabel);
	            $item.attr('aria-label', tooltipLabel);
	            const $metaRow = $('<div class="geweb-ai-overview-meta-row"></div>');
	            const $metaLeft = $('<div class="geweb-ai-overview-meta-left"></div>');
	            $metaLeft.append($('<span class="geweb-ai-overview-item-icon" aria-hidden="true">💬</span>'));
	            if (dateLabel) {
	                $metaLeft.append($('<span class="geweb-ai-overview-timestamp"></span>').text(dateLabel));
	            }

	            const $removeButton = $('<button type="button" class="geweb-ai-overview-item-remove" aria-label="Remove chat" title="Remove chat"><span aria-hidden="true">−</span></button>');
	            $removeButton.on('click', (event) => {
	                event.preventDefault();
	                event.stopPropagation();
	                void this.deleteConversationById(entry.id);
	            });

	            $metaRow.append($metaLeft, $removeButton);
	            $item.append($metaRow);
	            $item.append($('<div class="geweb-ai-overview-role"></div>').text(t('savedChat', 'Saved chat')));
	            $item.append($('<div class="geweb-ai-overview-preview"></div>').text(summaryLabel));
	            $item.on('click', async () => {
	                const loaded = await this.loadConversation(entry.id);
	                if (loaded && GewebModal.isMobileWorkspaceNavigationActive()) {
	                    GewebModal.setMobileWorkspacePane('main', { focusPane: true });
	                }
	            });
	            $overviewList.append($item);
	        });

	        this.conversationOverviewRenderedCount += nextEntries.length;
	        this.normalizeWorkspaceControlTitles($overviewList);
	    },

	    handleConversationOverviewScroll() {
	        if (!this.$conversationOverview.length) {
	            return;
	        }

	        const element = this.$conversationOverview.get(0);
	        if (!element || this.conversationOverviewRenderedCount >= this.conversationArchive.length) {
	            return;
	        }

	        const remaining = element.scrollHeight - (element.scrollTop + element.clientHeight);
	        if (remaining <= 80) {
	            this.renderMoreConversationOverviewItems();
	        }
	    },

	    renderConversationSummary() {
	        if (!this.$currentConversationSummary.length) {
	            return;
	        }

	        const currentEntry = this.conversationArchive.find((item) => item.id === this.conversationId);
	        const summary = currentEntry?.summary
	            ? currentEntry.summary
	            : this.buildConversationSummaryFromMessages(this.conversationHistory);

	        this.$currentConversationSummary.text(summary || t('untitledConversation', 'Untitled chat'));
	    },

	    ensureConversationId() {
	        if (this.conversationId === '') {
	            this.conversationId = this.generateConversationId();
	        }

	        return this.conversationId;
	    },

	    getPreferredConversationId() {
	        return this.conversationId || geweb_aisearch.frontend_ai_conversation_id || '';
	    },

	    generateConversationId() {
	        return 'geweb-ai-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10).toLowerCase();
	    },

	    appendSystemNote(message) {
	        const $note = $('<p class="geweb-ai-system-message"></p>').text(message);
	        this.$answerBox.append($note);
	    },

	    appendContextSummaryNote(summaryText) {
	        const normalizedSummary = String(summaryText || '').trim();
	        if (!normalizedSummary) {
	            this.appendSystemNote(t('earlierTrimmed', 'Earlier messages were trimmed to keep the chat context compact.'));
	            return;
	        }

	        const $details = $('<details class="geweb-ai-context-summary" role="note"></details>');
	        const $summary = $('<summary class="geweb-ai-context-summary-toggle"></summary>')
	            .text(t('contextSummaryCollapsed', 'Compacted context summary used. Click to expand.'));
	        const $body = $('<div class="geweb-ai-context-summary-body"></div>').text(normalizedSummary);
	        $details.append($summary, $body);
	        this.$answerBox.append($details);
	    },

	    escapeHtml(text) {
	        const div = document.createElement('div');
	        div.textContent = text;
	        return div.innerHTML;
	    },

			sanitizeAnswer(html) {
					// Wrap bare URLs in anchor tags
					const urlRegex = /(?<!href=["'])(?<!src=["'])(https?:\/\/[^\s<>"']+)/g;
					html = html.replaceAll(urlRegex, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>');

					// Allow safe tags only
	        const allowed = new Set(['p', 'br', 'b', 'strong', 'i', 'em', 'ul', 'ol', 'li', 'a', 'h1', 'h2', 'h3', 'span', 'sup', 'div']);
					const div = document.createElement('div');
					div.innerHTML = html;

					div.querySelectorAll('*').forEach(el => {
							const tagName = el.tagName.toLowerCase();
							if (!allowed.has(el.tagName.toLowerCase())) {
									el.replaceWith(document.createTextNode(el.textContent));
									return;
							}
							Array.from(el.attributes).forEach(attr => {
									if (tagName === 'a' && attr.name === 'href') {
											if (!/^https?:\/\//i.test(attr.value)) {
													el.removeAttribute('href');
											}
									} else if (tagName === 'sup' && ['class', 'data-footnote'].includes(attr.name)) {
											return;
									} else if (tagName === 'span' && attr.name === 'class' && String(attr.value || '').includes('geweb-ai-footnote-group')) {
											return;
									} else if (
										['div', 'ul'].includes(tagName)
										&& attr.name === 'class'
										&& String(attr.value || '').split(/\s+/).every((className) => /^geweb-ai-error-card(?:[A-Za-z0-9_-]*)$/.test(className) || className === '')
									) {
											return;
									} else if (!['target', 'rel'].includes(attr.name)) {
											el.removeAttribute(attr.name);
									}
							});
							if (tagName === 'a') {
									el.setAttribute('target', '_blank');
									el.setAttribute('rel', 'noopener noreferrer');
							}
					});

					return div.innerHTML;
			},

	    reset() {
	        this.conversationHistory = [];
	        this.conversationId = '';
	        this.requestInFlight = false;
	        this.compactedConversation = false;
	        this.excludedSourceKeysByConversation = {};
	        this.$answerBox.html('');
	        this.conversationArchive = [];
	        this.renderConversationOverview();
	        this.renderConversationSummary();
	        this.renderSources();
	        this.syncStoredChatState();
	        this.historyNavIndex = -1;
	        this.syncHistoryButtons();
	    },

	    getStoredChatStateKey() {
	        const pageKey = String(globalThis.location?.pathname || 'default').trim() || 'default';
	        const scopeKey = String(geweb_aisearch.current_scope_key || 'default').trim() || 'default';
	        return `geweb_ai_chat_state:${scopeKey}:${pageKey}`;
	    },

	    readStoredChatState() {
	        if (!globalThis.localStorage) {
	            return null;
	        }

	        try {
	            const rawValue = globalThis.localStorage.getItem(this.getStoredChatStateKey());
	            if (!rawValue) {
	                return null;
	            }

	            const parsed = JSON.parse(rawValue);
	            return parsed && typeof parsed === 'object' ? parsed : null;
	        } catch (error) {
	            console.debug('Reading stored chat state failed.', error);
	            return null;
	        }
	    },

	    syncStoredChatState() {
	        if (!globalThis.localStorage) {
	            return;
	        }

	        const state = {
	            conversationId: this.conversationId,
	            compactedConversation: this.compactedConversation,
	            currentContextSummary: this.currentContextSummary,
	            excludedSourceKeysByConversation: this.excludedSourceKeysByConversation,
	            conversationHistory: this.conversationHistory,
	            conversationArchive: this.conversationArchive,
	        };

	        try {
	            globalThis.localStorage.setItem(this.getStoredChatStateKey(), JSON.stringify(state));
	        } catch (error) {
	            const isQuotaError = error instanceof DOMException
	                && (error.name === 'QuotaExceededError' || error.name === 'NS_ERROR_DOM_QUOTA_REACHED');
	            if (isQuotaError) {
	                try {
	                    globalThis.localStorage.setItem(this.getStoredChatStateKey(), JSON.stringify({ ...state, conversationArchive: [] }));
	                } catch (retryError) {
	                    console.debug('Saving stored chat state failed even after trimming archive.', retryError);
	                }
	                return;
	            }
	            console.debug('Saving stored chat state failed.', error);
	        }
	    },

	    hydrateFromStoredChatState() {
	        const storedState = this.readStoredChatState();
	        if (!storedState) {
	            return false;
	        }

	        const storedArchive = Array.isArray(storedState.conversationArchive)
	            ? storedState.conversationArchive.map((entry) => this.normalizeStoredConversation(entry))
	            : [];
	        if (storedArchive.length) {
	            this.conversationArchive = storedArchive;
	        }

	        const storedConversationId = String(storedState.conversationId || '').trim();
	        const storedHistory = Array.isArray(storedState.conversationHistory)
	            ? storedState.conversationHistory
	                .filter((entry) => entry && typeof entry === 'object')
	                .map((entry) => ({
	                    role: ['model', 'assistant', 'ai'].includes(String(entry.role || '').trim()) ? 'model' : 'user',
	                    content: String(entry.content ?? entry.answer ?? entry.text ?? entry.message ?? ''),
	                    sources: Array.isArray(entry.sources) ? entry.sources : [],
	                    meta: entry.meta && typeof entry.meta === 'object' ? entry.meta : {},
	                    created_at: this.normalizeEpochSeconds(entry.created_at ?? entry.createdAt)
	                }))
	                .filter((entry) => entry.content.trim() !== '')
	            : [];

	        if (storedConversationId && storedHistory.length) {
	            this.conversationId = storedConversationId;
	            this.conversationHistory = storedHistory;
	            this.compactedConversation = !!storedState.compactedConversation;
	            this.currentContextSummary = String(storedState.currentContextSummary || '').trim();
	        }

	        if (storedState.excludedSourceKeysByConversation && typeof storedState.excludedSourceKeysByConversation === 'object') {
	            this.excludedSourceKeysByConversation = Object.entries(storedState.excludedSourceKeysByConversation).reduce((map, [conversationId, keys]) => {
	                const normalizedConversationId = String(conversationId || '').trim();
	                if (!normalizedConversationId || !Array.isArray(keys)) {
	                    return map;
	                }

	                const normalizedKeys = [...new Set(keys.map((key) => String(key || '').trim()).filter(Boolean))];
	                if (normalizedKeys.length) {
	                    map[normalizedConversationId] = normalizedKeys;
	                }

	                return map;
	            }, {});
	        }

	        return storedArchive.length > 0 || storedHistory.length > 0;
	    },

	    normalizeStoredConversation(entry) {
	        const messages = Array.isArray(entry.messages) ? entry.messages : [];
	        const normalizedMessages = messages
	            .filter((item) => item && typeof item === 'object')
	            .map((item) => ({
	                role: ['model', 'assistant', 'ai'].includes(String(item.role || '').trim()) ? 'model' : 'user',
	                content: String(item.content ?? item.answer ?? item.text ?? item.message ?? ''),
	                sources: Array.isArray(item.sources) ? item.sources : [],
	                meta: item.meta && typeof item.meta === 'object' ? item.meta : {},
	                created_at: this.normalizeEpochSeconds(item.created_at ?? item.createdAt)
	            }))
	            .filter((item) => item.content.trim() !== '');
	        const summary = typeof entry.summary === 'string' && entry.summary.trim() !== ''
	            ? entry.summary.trim()
	            : this.buildConversationSummaryFromMessages(normalizedMessages);

	        return {
	            id: typeof entry.id === 'string' && entry.id ? entry.id : this.generateConversationId(),
	            savedAt: Number(entry.savedAt || Date.now()),
	            compacted: !!entry.compacted,
	            contextSummary: String(entry.context_summary || entry.contextSummary || '').trim(),
	            summary: summary,
	            firstUserMessage: typeof entry.firstUserMessage === 'string' ? entry.firstUserMessage : this.getFirstUserMessageFromMessages(normalizedMessages),
	            messages: normalizedMessages
	        };
	    },

	    buildConversationSummaryFromMessages(messages) {
	        for (const item of messages) {
	            if (item?.role !== 'user') {
	                continue;
	            }

	            const text = String(item.content || '').replaceAll(/\s+/g, ' ').trim();
	            if (!text) {
	                continue;
	            }

	            return text.length > 80 ? `${text.slice(0, 77)}...` : text;
	        }

	        return t('untitledConversation', 'Untitled chat');
	    },

	    upsertCurrentConversationInArchive() {
	        const id = this.ensureConversationId();
	        const entry = {
	            id: id,
	            savedAt: Date.now(),
	            compacted: this.compactedConversation,
	            contextSummary: String(this.currentContextSummary || '').trim(),
	            summary: this.buildConversationSummaryFromMessages(this.conversationHistory),
	            firstUserMessage: this.getFirstUserMessageFromMessages(this.conversationHistory),
	            messages: this.conversationHistory
	        };

	        this.conversationArchive = this.conversationArchive.filter((item) => item.id !== id);
	        this.conversationArchive.unshift(entry);
	        this.conversationArchive = this.conversationArchive.slice(0, getLocalConversationArchiveLimit());
	        return entry;
	    },

	    async renameCurrentConversation() {
	        const currentId = this.ensureConversationId();
	        const currentEntry = this.conversationArchive.find((item) => item.id === currentId);
	        const currentSummary = currentEntry?.summary
	            ? currentEntry.summary
	            : this.buildConversationSummaryFromMessages(this.conversationHistory);
	        const nextSummary = globalThis.prompt(t('renameConversation', 'Rename chat'), currentSummary || t('untitledConversation', 'Untitled chat'));

	        if (typeof nextSummary !== 'string') {
	            return;
	        }

	        const trimmedSummary = nextSummary.trim();
	        if (!trimmedSummary) {
	            return;
	        }

	        if (currentEntry) {
	            try {
	                await this.requestFrontendConversation('geweb_frontend_rename_conversation', {
	                    conversation_id: currentId,
	                    summary: trimmedSummary
	                });
		            } catch (error) {
		                console.debug('Rename conversation failed.', error);
		                return;
		            }
	        }

	        this.conversationArchive = this.conversationArchive.map((item) => item.id === currentId ? {
	            ...item,
	            summary: trimmedSummary
	        } : item);

	        await this.persistConversation();
	        this.renderConversationOverview();
	        this.renderConversationSummary();
	    },

	    async deleteCurrentConversation() {
	        const currentId = this.conversationId;
	        await this.deleteConversationById(currentId);
	    },

	    async deleteConversationById(conversationId) {
	        const currentId = String(conversationId || '').trim();
	        if (!currentId) {
	            this.createNewConversation(true);
	            return;
	        }

	        if (!globalThis.confirm(t('removeConversationConfirm', 'Remove this chat from the current search context?'))) {
	            return;
	        }

	        if (this.conversationArchive.some((item) => item.id === currentId)) {
	            try {
	                await this.requestFrontendConversation('geweb_frontend_delete_conversation', {
	                    conversation_id: currentId
	                });
		            } catch (error) {
		                console.debug('Delete conversation failed.', error);
		                return;
		            }
	        }

	        await this.loadConversationArchive();
	        delete this.excludedSourceKeysByConversation[currentId];
	        this.syncStoredChatState();
	        if (currentId !== this.conversationId) {
	            this.renderConversationOverview();
	            this.renderConversationSummary();
	            this.renderSources();
	            this.syncStoredChatState();
	            return;
	        }

	        if (this.conversationArchive.length) {
	            await this.loadConversation(this.conversationArchive[0].id);
	        } else {
	            this.createNewConversation(true);
	        }
	    },

	    async loadConversation(conversationId) {
	        try {
	        const cachedEntry = this.conversationArchive.find((item) => item.id === conversationId);
	        let entry = cachedEntry;
	        try {
	            const response = await this.requestFrontendConversation('geweb_get_frontend_conversation', {
	                conversation_id: conversationId
	            });
	            if (!(response?.success && response?.data?.conversation)) {
	                const hasRenderableCachedConversation = Array.isArray(cachedEntry?.messages)
	                    && cachedEntry.messages.length > 1
	                    && cachedEntry.messages.some((item) => item?.role === 'model');
	                if (!hasRenderableCachedConversation) {
	                    this.conversationArchive = this.conversationArchive.filter((item) => item.id !== conversationId);
	                    this.renderConversationOverview();
	                    return false;
	                }
	            } else {
	                entry = this.normalizeStoredConversation(response.data.conversation);
	                this.conversationArchive = this.conversationArchive.map((item) => item.id === entry.id ? entry : item);
	                if (!this.conversationArchive.some((item) => item.id === entry.id)) {
	                    this.conversationArchive.unshift(entry);
	                }
	            }
	        } catch (error) {
	            console.debug('Load conversation failed.', error);
	            const hasRenderableCachedConversation = Array.isArray(cachedEntry?.messages)
	                && cachedEntry.messages.length > 1
	                && cachedEntry.messages.some((item) => item?.role === 'model');
	            if (!hasRenderableCachedConversation) {
	                this.conversationArchive = this.conversationArchive.filter((item) => item.id !== conversationId);
	                this.renderConversationOverview();
	                this.renderConversationSummary();
	                return false;
	            }
	        }

	        if (!entry?.messages?.length) {
	            return false;
	        }

	        this.conversationId = entry.id;
	        this.conversationHistory = entry.messages.map((item) => ({
	            role: item.role,
	            content: item.content,
	            sources: Array.isArray(item.sources) ? item.sources : [],
	            meta: item.meta && typeof item.meta === 'object' ? item.meta : {},
	            created_at: this.normalizeEpochSeconds(item.created_at ?? item.createdAt)
	        }));
	        this.compactedConversation = !!entry.compacted;
	        this.currentContextSummary = String(entry.contextSummary || '').trim();
	        this.requestInFlight = false;
	        this.renderConversationMessages();

	        this.renderConversationOverview();
	        this.renderConversationSummary();
	        this.renderSources();
	        this.toggleSubmitButton();
	        this.syncFrontendPageConversationState();
	        this.syncStoredChatState();
	        this.historyNavIndex = -1;
	        this.syncHistoryButtons();
	        return true;
	        } catch (error) {
	            console.debug('Restoring conversation render failed.', error);
	            this.conversationId = conversationId;
	            this.requestInFlight = false;
	            this.renderConversationOverview();
	            this.renderConversationSummary();
	            this.renderSources();
	            this.toggleSubmitButton();
	            this.syncFrontendPageConversationState();
	            this.syncStoredChatState();
	            this.historyNavIndex = -1;
	            this.syncHistoryButtons();
	            return false;
	        }
	    },

	    createNewConversation(shouldFocus) {
	        this.conversationId = this.generateConversationId();
	        this.conversationHistory = [];
	        this.compactedConversation = false;
	        this.currentContextSummary = '';
	        this.requestInFlight = false;
	        this.$answerBox.empty();
	        this.renderConversationOverview();
	        this.renderConversationSummary();
	        this.renderSources();
	        this.toggleSubmitButton();
	        this.syncFrontendPageConversationState();
	        this.syncStoredChatState();
	        this.historyNavIndex = -1;
	        this.syncHistoryButtons();

	        if (shouldFocus) {
	            this.focusInput();
	        }
	    },

	    async restoreRequestedOrFirstConversation(requestedConversationId) {
	        if (requestedConversationId) {
	            return this.loadConversation(requestedConversationId);
	        }

	        const firstConversationId = this.conversationArchive[0]?.id || '';
	        if (!firstConversationId) {
	            return false;
	        }

	        return this.loadConversation(firstConversationId);
	    },

	    async applyFrontendRequestState() {
	        const initialQuery = (geweb_aisearch.frontend_ai_initial_query || '').trim();
	        const requestedConversationId = (geweb_aisearch.frontend_ai_conversation_id || '').trim();
	        const conversationRestored = await this.restoreRequestedOrFirstConversation(requestedConversationId);
	        if (!conversationRestored && requestedConversationId && this.conversationArchive.length) {
	            await this.restoreRequestedOrFirstConversation('');
	        }

	        if (!requestedConversationId && initialQuery && this.shouldAutoSubmitQuery(initialQuery)) {
	            const targetConversationId = this.getTargetConversationIdForQuery(initialQuery);
	            if (targetConversationId && targetConversationId !== this.conversationId) {
	                await this.loadConversation(targetConversationId);
	            } else if (!targetConversationId) {
	                this.createNewConversation(false);
	            }
	        }

	        if (initialQuery && this.shouldAutoSubmitQuery(initialQuery)) {
	            this.$textarea.val(initialQuery);
	            this.toggleSubmitButton();
	        }

	        if (
	            geweb_aisearch.is_frontend_ai_page &&
	            initialQuery &&
	            this.shouldAutoSubmitQuery(initialQuery) &&
	            !this.requestInFlight &&
	            this.conversationHistory.length === 0
	        ) {
	            globalThis.setTimeout(() => this.sendMessage(initialQuery), 0);
	        }
	    }
	};

	if (globalThis.GewebAISearchSourceMethods && typeof globalThis.GewebAISearchSourceMethods === 'object') {
	    Object.assign(GewebAIChat, globalThis.GewebAISearchSourceMethods);
	}

	globalThis.GewebAIModal = GewebModal;
	GewebModal.init();
	GewebAIChat.init();
	scheduleHighlightFirstPageMatch();
});
