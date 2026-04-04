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
	return String(text || '').replaceAll(/\s+/g, ' ').trim().toLowerCase();
}

function highlightFirstPageMatch() {
	const currentUrl = globalThis.location?.href;
	if (!currentUrl) {
		return;
	}

	let phrase = '';
	try {
		const url = new URL(currentUrl, globalThis.location.origin);
		phrase = String(url.searchParams.get('geweb_ai_match') || '').trim();
	} catch (error) {
		console.debug('Reading geweb_ai_match failed.', error);
		return;
	}

	if (!phrase) {
		return;
	}

	const candidates = [];
	const words = phrase.split(/\s+/).filter(Boolean);
	for (let length = Math.min(5, words.length); length >= 1; length -= 1) {
		candidates.push(words.slice(0, length).join(' '));
	}

	const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, {
		acceptNode(node) {
			const parent = node.parentElement;
			if (!parent || ['SCRIPT', 'STYLE', 'NOSCRIPT', 'TEXTAREA'].includes(parent.tagName)) {
				return NodeFilter.FILTER_REJECT;
			}

			return normalizeInlineMatchText(node.textContent).length ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
		}
	});

	let matchedNode = null;
	let matchedPhrase = '';
	/* eslint-disable no-cond-assign */
	while ((matchedNode = walker.nextNode())) {
		const rawText = String(matchedNode.textContent || '');
		const normalizedText = normalizeInlineMatchText(rawText);
		matchedPhrase = candidates.find((candidate) => normalizedText.includes(normalizeInlineMatchText(candidate))) || '';
		if (!matchedPhrase) {
			continue;
		}

		const matchIndex = rawText.toLowerCase().indexOf(matchedPhrase.toLowerCase());
		if (matchIndex < 0) {
			continue;
		}

		const range = document.createRange();
		range.setStart(matchedNode, matchIndex);
		range.setEnd(matchedNode, matchIndex + matchedPhrase.length);
		const highlight = document.createElement('mark');
		highlight.className = 'geweb-ai-inline-match';
		range.surroundContents(highlight);
		if (typeof highlight.scrollIntoView === 'function') {
			globalThis.setTimeout(() => {
				highlight.scrollIntoView({ behavior: 'smooth', block: 'center' });
			}, 120);
		}
		return;
	}
	/* eslint-enable no-cond-assign */
}

jQuery(document).ready(function($) {
	let nonceRequest = null;

	function fetchSearchNonce() {
		return $.post(getAiSearchConfig().ajax_url, {
			action: 'geweb_get_nonce'
		}).then(function(response) {
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
			nonceRequest = Promise.resolve(fetchSearchNonce()).finally(function() {
				nonceRequest = null;
			});
		}

		return nonceRequest;
	}

	function clearSearchNonce() {
		geweb_aisearch.search_nonce = '';
	}

	ensureSearchNonce().catch(function() {
		return null;
	});
	
	const GewebModal = {
	    ai: document.getElementById('geweb-ai-modal'),

	    init() {
	        if (!this.ai) return;
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
	            globalThis.setTimeout(function() {
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
	        this.initPageHeightPersistence();
	        this.bindPageToolbarControls();
	        this.ensurePageResizeHandle();
	        this.bindFrontendHeaderSearch();
	        this.bindWorkspacePaneResizers();
	        this.bindPanelCollapseButtons();
	        this.syncPanelCollapseButtons();
	    },

	    getPageViewHeightStorageKey() {
	        const pathname = globalThis.location?.pathname || 'default';
	        return `geweb_ai_page_height:${pathname}`;
	    },

	    getPageViewMinHeight() {
	        return 360;
	    },

	    getPageViewViewportHeight() {
	        return Math.max(this.getPageViewMinHeight(), globalThis.innerHeight || this.getPageViewMinHeight());
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

	        const currentHeight = this.ai.getBoundingClientRect().height;
	        const viewportHeight = this.getPageViewViewportHeight();
	        const hasStoredHeight = this.readStoredPageViewHeight() !== null;
	        const hasExplicitHeight = Boolean(this.ai.style.height);

	        if (!hasStoredHeight && !hasExplicitHeight) {
	            return;
	        }

	        if (currentHeight >= viewportHeight - 2 && !hasStoredHeight) {
	            this.ai.style.height = `${viewportHeight}px`;
	            return;
	        }

	        this.applyPageViewHeight(currentHeight, { persist: hasStoredHeight || hasExplicitHeight });
	    },

	    initPageHeightPersistence() {
	        if (!this.ai || this.ai.dataset.gewebAiPageHeightBound === '1') {
	            return;
	        }

	        this.ai.dataset.gewebAiPageHeightBound = '1';

	        const storedHeight = this.readStoredPageViewHeight();
	        if (storedHeight) {
	            this.applyPageViewHeight(storedHeight, { persist: false });
	        }

	        if (typeof globalThis.ResizeObserver === 'function') {
	            const observer = new globalThis.ResizeObserver((entries) => {
	                const nextHeight = entries[0]?.contentRect?.height;
	                const viewportHeight = this.getPageViewViewportHeight();
	                const shouldPersist = Boolean(this.ai?.style?.height) || (Number.isFinite(nextHeight) && nextHeight < viewportHeight - 4);

	                if (!shouldPersist) {
	                    return;
	                }

	                this.persistPageViewHeight(nextHeight);
	            });

	            observer.observe(this.ai);
	        }

	        globalThis.addEventListener('resize', () => {
	            this.syncPageViewHeightToViewport();
	        });
	    },

	    bindPageToolbarControls() {
	        const alignButton = document.getElementById('geweb-ai-align-workspace');
	        if (!alignButton || alignButton.dataset.gewebAiBound === '1') {
	            return;
	        }

	        alignButton.dataset.gewebAiBound = '1';
	        alignButton.addEventListener('click', (event) => {
	            event.preventDefault();
	            this.resetPageViewToViewport();
	        });
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

	            const deltaY = Number(event.clientY) - startY;
	            this.applyPageViewHeight(startHeight + deltaY);
	        };

	        let startY = 0;
	        let startHeight = 0;

	        handle.addEventListener('pointerdown', (event) => {
	            if (!this.ai) {
	                return;
	            }

	            event.preventDefault();
	            startY = Number(event.clientY);
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
	        if (!workspace || globalThis.innerWidth <= 1023) {
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
	        const workspace = this.ai ? this.ai.querySelector('.geweb-ai-workspace') : null;
	        const $searchPanel = $('.geweb-ai-search-results-panel');
	        if (!workspace) {
	            return;
	        }

	        $(this.ai).find('.geweb-ai-panel-collapse').on('click', function() {
	            const target = $(this).data('panel-toggle');
	            if (target === 'left') {
	                workspace.classList.toggle('is-left-collapsed');
	            } else if (target === 'right') {
	                workspace.classList.toggle('is-right-collapsed');
	            } else if (target === 'search') {
	                $searchPanel.toggleClass('is-collapsed');
	            }

	            GewebModal.syncPanelCollapseButtons();
	        });
	    },

	    syncPanelCollapseButtons() {
	        const workspace = this.ai ? this.ai.querySelector('.geweb-ai-workspace') : null;
	        const leftCollapsed = !!workspace?.classList.contains('is-left-collapsed');
	        const rightCollapsed = !!workspace?.classList.contains('is-right-collapsed');
	        const $searchPanel = $('.geweb-ai-search-results-panel');
	        const searchCollapsed = $searchPanel.hasClass('is-collapsed');

	        const applyButtonState = ($button, expanded, icon, label) => {
	            $button.attr('aria-expanded', expanded ? 'true' : 'false');
	            $button.attr('aria-label', label);
	            $button.attr('title', label);
	            $button.find('.geweb-ai-panel-collapse-icon').text(icon);
	        };

		        applyButtonState(
		            $(this.ai).find('.geweb-ai-panel-collapse[data-panel-toggle="left"]'),
		            !leftCollapsed,
		            leftCollapsed ? '▶' : '◀',
		            leftCollapsed ? 'Expand chats panel' : 'Collapse chats panel'
		        );
	        applyButtonState(
	            $(this.ai).find('.geweb-ai-panel-collapse[data-panel-toggle="right"]'),
	            !rightCollapsed,
	            rightCollapsed ? '◀' : '▶',
	            rightCollapsed ? 'Expand sources panel' : 'Collapse sources panel'
	        );
	        applyButtonState(
	            $(this.ai).find('.geweb-ai-panel-collapse[data-panel-toggle="search"]'),
	            !searchCollapsed,
	            searchCollapsed ? '▾' : '▴',
	            searchCollapsed ? 'Expand classic search results' : 'Collapse classic search results'
	        );
	    },

	    bindFrontendHeaderSearch() {
	        $('form').has('input[name="s"]').each((_, form) => {
	            const $form = $(form);
	            if ($form.closest('#geweb-ai-modal').length) {
	                return;
	            }

	            if ($form.data('gewebAiSearchBound')) {
	                return;
	            }

	            const $input = $form.find('input[name="s"]').first();
	            if (!$input.length) {
	                return;
	            }

	            $form.data('gewebAiSearchBound', true);
	            $form.on('submit', (event) => {
	                event.preventDefault();
	                const query = String($input.val() || '').trim();
		                globalThis.location.href = this.buildFrontendAiPageUrl(query, GewebAIChat.getTargetConversationIdForQuery(query));
	            });
	        });
	    },

	    escapeAttr(text) {
	        return String(text).replaceAll('"', '&quot;');
	    },

	};

		const GewebAIChat = {
		    $textarea: $('#geweb-ai-query-display'),
		    $submitBtn: $('#geweb-ask-ai-submit'),
		    $modelSelector: $('#geweb-ai-model-selector'),
		    $settingsToggle: $('#geweb-ai-toggle-temp-settings'),
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
	    $currentConversationSummary: $('#geweb-ai-current-conversation-summary'),
	    $copyConversationBtn: $('#geweb-ai-copy-conversation'),
	    $renameConversationBtn: $('#geweb-ai-rename-conversation'),
	    $deleteConversationBtn: $('#geweb-ai-delete-conversation'),
		    $newConversationBtn: $('.geweb-ai-new-conversation'),
	    conversationHistory: [],
	    conversationId: '',
	    requestInFlight: false,
	    compactedConversation: false,
		    conversationArchive: [],
		    conversationOverviewPageSize: 10,
		    conversationOverviewRenderedCount: 0,
		    sourceReferenceCache: {},

		    init() {
		        if (!this.$textarea.length) return;

		        this.$textarea.on('input', () => this.toggleSubmitButton());
		        this.$submitBtn.on('click', () => this.sendMessage());
		        this.$settingsToggle.on('click', () => this.toggleTemporarySettings());
		        this.$closeSettingsBtn.on('click', () => this.toggleTemporarySettings(false));
		        this.$resetSettingsBtn.on('click', () => this.resetTemporarySettings(true));
		        this.$resetModelBtn.on('click', () => this.resetTemporaryModel(true));
		        this.$resetPromptBtn.on('click', () => this.resetTemporaryPrompt(true));
		        this.$togglePromptEditorBtn.on('click', () => this.toggleTemporaryPromptEditor());
		        this.$modelSelector.on('change', () => this.handleTemporaryModelChange());
		        this.$temporaryPrompt.on('input', () => this.updateTemporarySettingsSummary());
		        this.$copyConversationBtn.on('click', () => { void this.copyCurrentConversation(); });
		        this.$renameConversationBtn.on('click', () => { void this.renameCurrentConversation(); });
		        this.$deleteConversationBtn.on('click', () => { void this.deleteCurrentConversation(); });
		        this.$newConversationBtn.on('click', () => this.createNewConversation(true));
		        this.$conversationOverview.on('click', (event) => {
		            event.stopPropagation();
		            this.toggleConversationOverviewScrollActive();
		        });
		        this.$conversationOverview.on('mouseleave', () => this.setConversationOverviewScrollActive(false));
		        this.$answerBox.on('click', (event) => {
		            event.stopPropagation();
		            this.toggleAnswerBoxScrollActive();
		        });
		        this.$answerBox.on('mouseleave', () => this.setAnswerBoxScrollActive(false));
		        this.$conversationOverview.on('scroll', () => this.handleConversationOverviewScroll());
		        $(document).on('click', (event) => this.handleDocumentClick(event));
		        document.addEventListener('scroll', () => this.hideFootnotePreview(), true);
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
	        this.toggleSubmitButton();
	        this.syncStoredChatState();
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

	    setConversationOverviewScrollActive(isActive) {
	        if (!this.$conversationOverview.length) {
	            return;
	        }

	        this.$conversationOverview.toggleClass('is-scroll-active', !!isActive);
	    },

	    setAnswerBoxScrollActive(isActive) {
	        if (!this.$answerBox.length) {
	            return;
	        }

	        this.$answerBox.toggleClass('is-scroll-active', !!isActive);
	    },

	    toggleConversationOverviewScrollActive() {
	        if (!this.$conversationOverview.length) {
	            return;
	        }

	        this.setConversationOverviewScrollActive(!this.$conversationOverview.hasClass('is-scroll-active'));
	    },

	    toggleAnswerBoxScrollActive() {
	        if (!this.$answerBox.length) {
	            return;
	        }

	        this.setAnswerBoxScrollActive(!this.$answerBox.hasClass('is-scroll-active'));
	    },

	    submitQuestionBoxFromKeyboard(event) {
	        if (event) {
	            event.preventDefault();
	            event.stopPropagation();
	        }

	        if (this.$submitBtn.prop('disabled')) {
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
	            meta: item.meta && typeof item.meta === 'object' ? item.meta : {}
	        }));
	        this.compactedConversation = !!entry.compacted;
	        this.requestInFlight = false;
	        this.$answerBox.empty();

	        if (this.compactedConversation) {
	            this.appendSystemNote(t('earlierTrimmed', 'Earlier messages were trimmed to keep the chat context compact.'));
	        }

	        this.conversationHistory.forEach((item) => {
	            if (item.role === 'model') {
	                this.appendMessage({
	                    answer: item.content,
	                    sources: item.sources || [],
	                    meta: item.meta || {}
	                }, 'ai');
	                return;
	            }

	            this.appendMessage(item.content, 'user');
	        });

	        this.renderConversationOverview();
	        this.renderConversationSummary();
	        this.renderSources();
	        this.toggleSubmitButton();
	        this.syncFrontendPageConversationState();
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

		        if (this.$togglePromptEditorBtn.length) {
		            this.$togglePromptEditorBtn.text(
		                shouldOpen
		                    ? t('composerHidePromptEditor', 'Hide prompt editor')
		                    : t('composerEditPrompt', 'Edit prompt')
		            );
		        }

		        if (shouldOpen && this.$temporaryPrompt.length) {
		            this.$temporaryPrompt.trigger('focus');
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

		    handleDocumentClick(event) {
		        this.hideFootnotePreview();
		        const $target = $(event.target);

		        if (this.$conversationOverview.length) {
		            const clickedInsideOverview = $target.closest('#geweb-ai-conversation-overview').length > 0;
		            this.setConversationOverviewScrollActive(clickedInsideOverview);
		        }

		        if (this.$answerBox.length) {
		            const clickedInsideAnswerBox = $target.closest('.answer-box').length > 0;
		            this.setAnswerBoxScrollActive(clickedInsideAnswerBox);
		        }

		        if (!this.$settingsPanel.length || this.$settingsPanel.prop('hidden')) {
		            return;
		        }

		        if (
		            $target.closest('#geweb-ai-temporary-settings-panel').length ||
		            $target.closest('#geweb-ai-toggle-temp-settings').length
		        ) {
		            return;
		        }

		        this.toggleTemporarySettings(false);
		    },

	    async sendMessage(prefilledMessage) {
	        const message = typeof prefilledMessage === 'string'
	            ? prefilledMessage.trim()
	            : this.$textarea.val().trim();

	        if (!message || this.requestInFlight) return;

	        this.conversationHistory.push({ role: 'user', content: message });
	        this.appendMessage(message, 'user');
	        this.renderConversationSummary();
	        this.renderSources();

	        this.$textarea.val('');
	        this.requestInFlight = true;
	        this.toggleSubmitButton();

	        const $loader = $(`<p class="ai-message loading">${this.escapeHtml(t('thinking', 'Thinking...'))}</p>`);
	        this.$answerBox.append($loader);
	        this.scrollToBottom();

	        try {
	            await ensureSearchNonce();
		        } catch (error) {
		            console.debug('Could not initialize nonce before sending chat message.', error);
		            $loader.remove();
	            this.requestInFlight = false;
	            this.appendMessage({ answer: t('couldNotStart', 'Could not start the AI search. Please try again.'), sources: [] }, 'ai');
	            this.toggleSubmitButton();
		            return;
		        }

		        const requestData = {
		            action: 'geweb_ai_chat',
		            nonce: geweb_aisearch.search_nonce,
		            conversation_id: this.ensureConversationId(),
		            model: this.getSelectedModel(),
		            temporary_prompt: this.getTemporaryPrompt(),
		            messages: [{
		                role: 'user',
		                content: message
		            }]
		        };
		        let nonceRetried = false;
		        const sendAjaxRequest = () => {
		            $.ajax({
		                url: geweb_aisearch.ajax_url,
		                type: 'POST',
		                timeout: 120000,
		                data: requestData,
		                success: (response) => this.handleResponse(response, $loader),
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
		                                this.handleError($loader, xhr);
		                            });
		                        return;
		                    }

		                    this.handleError($loader, xhr);
		                }
		            });
		        };

		        sendAjaxRequest();
		        this.resetTemporarySettings();
		        this.toggleTemporarySettings(false);
		    },

		async handleResponse(response, $loader) {
		    $loader.remove();
		    this.requestInFlight = false;
		    if (response?.success && response?.data) {
		        const compactedBeforeAppend = this.compactedConversation;
		        this.compactedConversation = !!response.data.context_compacted;
		        this.conversationHistory.push({
		            role: 'model',
		            content: response.data.answer,
		            sources: Array.isArray(response.data.sources) ? response.data.sources : [],
		            meta: response.data?.meta && typeof response.data.meta === 'object' ? response.data.meta : {}
		        });
		        if (!compactedBeforeAppend && this.compactedConversation) {
		            this.appendSystemNote(t('earlierTrimmed', 'Earlier messages were trimmed to keep the chat context compact.'));
		        }
		        this.appendMessage(response.data, 'ai');
		        this.renderSources();
		        await this.persistConversation();
		        await this.loadConversationArchive();
		        this.renderConversationOverview();
		        this.renderConversationSummary();
			    } else {
			        this.appendMessage({ answer: t('answerError', 'Error: Unable to get response'), sources: [] }, 'ai');
			    }
			        this.toggleSubmitButton();
				},

		handleError($loader, xhr) {
		    $loader.remove();
		    this.requestInFlight = false;
		    this.appendMessage({ answer: this.getAjaxErrorMessage(xhr, t('connectionError', 'Connection error. Please try again.')), sources: [] }, 'ai');
	        this.toggleSubmitButton();
		},

		appendMessage(text, type) {
	        if (type === 'user') {
		        const $msg = $(`<p class="user-message">${this.escapeHtml(text)}</p>`);
		        this.$answerBox.append($msg);
		        this.scrollElementIntoView($msg.get(0));
		    } else {
		        const $container = $('<div class="ai-message"></div>');
		        const responseMeta = text?.meta && typeof text.meta === 'object' ? text.meta : {};
		        const sourceFootnoteMap = this.getResponseSourceFootnoteMap(text.sources || [], text.answer || '', responseMeta);
		        const answerWithFootnotes = this.decorateAnswerWithGroundingFootnotes(String(text.answer || ''), responseMeta, sourceFootnoteMap, text.sources || []);
		        const sanitizedAnswer = this.sanitizeAnswer(answerWithFootnotes);
		        const $content = $('<div class="geweb-ai-message-content"></div>').html(sanitizedAnswer);
		        if (!$content.find('.geweb-ai-footnote-ref').length) {
		            const fallbackFootnotes = this.getFallbackFootnoteNumbers(sourceFootnoteMap, text.sources || []);
		            if (fallbackFootnotes.length) {
		                $content.append($(this.appendFallbackFootnoteGroup('', fallbackFootnotes)));
		            }
		        }
		        const plainText = this.extractPlainTextFromHtml(sanitizedAnswer);
		        const $copyButton = this.buildCopyButton(plainText);
		        const $details = this.buildResponseDetails(responseMeta);
		        const $messageActions = $('<div class="geweb-ai-message-actions"></div>');

		        if ($copyButton) {
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
		                $detailsButton.attr('title', t('showDetails', 'Show details'));
		                $detailsButton.find('.geweb-ai-message-action-label').text(t('showDetails', 'Show details'));
		            });
		            const $detailsButton = $('<button type="button" class="geweb-ai-message-details-toggle"></button>');
		            $detailsButton.attr('aria-expanded', 'false');
		            $detailsButton.attr('title', t('showDetails', 'Show details'));
		            $detailsButton.append($('<span class="geweb-ai-message-action-icon geweb-ai-message-action-icon--details" aria-hidden="true">ⓘ</span>'));
		            $detailsButton.append($('<span class="geweb-ai-message-action-label"></span>').text(t('showDetails', 'Show details')));
		            $detailsButton.on('click', () => {
		                this.toggleResponseDetails($container);
		                const expanded = $details.hasClass('is-open');
		                $detailsButton.attr('aria-expanded', expanded ? 'true' : 'false');
		                $detailsButton.attr('title', expanded ? t('hideDetails', 'Hide details') : t('showDetails', 'Show details'));
		                $detailsButton.find('.geweb-ai-message-action-label').text(expanded ? t('hideDetails', 'Hide details') : t('showDetails', 'Show details'));
		            });
		            $messageActions.append($detailsButton);
		        }

		        if ($messageActions.children().length) {
		            $container.append($messageActions);
		        }
		        $container.append($content);
		        if ($details) {
		            $container.append($details);
		        }

		        this.$answerBox.append($container);
		        this.scrollElementIntoView($container.get(0));
		    }

		    this.scrollToBottom();
		},

	    extractPlainTextFromHtml(html) {
	        const container = document.createElement('div');
	        container.innerHTML = String(html || '');
	        return String(container.textContent || container.innerText || '').replaceAll(/\s+/g, ' ').trim();
	    },

	    buildCopyButton(text) {
	        const plainText = String(text || '').trim();
	        if (!plainText) {
	            return null;
	        }

	        const $button = $('<button type="button" class="geweb-ai-copy-answer" aria-live="polite"></button>');
	        $button.attr('aria-label', t('copyAnswer', 'Copy answer'));
	        $button.attr('title', t('copyAnswer', 'Copy answer'));
	        $button.append($('<span class="geweb-ai-copy-answer-icon" aria-hidden="true">⧉</span>'));
	        $button.append($('<span class="geweb-ai-message-action-label"></span>').text(t('copyAnswer', 'Copy answer')));
	        $button.on('click', async () => {
	            const copied = await this.copyTextToClipboard(plainText);
	            $button.toggleClass('is-copied', copied);
	            $button.attr('aria-label', copied ? t('copied', 'Copied') : t('copyFailed', 'Could not copy'));
	            $button.attr('title', copied ? t('copied', 'Copied') : t('copyFailed', 'Could not copy'));

	            globalThis.setTimeout(() => {
	                $button.removeClass('is-copied');
	                $button.attr('aria-label', t('copyAnswer', 'Copy answer'));
	                $button.attr('title', t('copyAnswer', 'Copy answer'));
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
	            const footnote = Number($(event.currentTarget).attr('data-footnote') || 0);
	            if (footnote > 0) {
	                this.highlightSourceReference(footnote, { mode: 'active' });
	                this.hideFootnotePreview();
	            }
	        });

	        $footnotes.on('keydown', (event) => {
	            if (event.key !== 'Enter' && event.key !== ' ') {
	                return;
	            }

	            event.preventDefault();
	            event.stopPropagation();
	            const footnote = Number($(event.currentTarget).attr('data-footnote') || 0);
	            if (footnote > 0) {
	                this.highlightSourceReference(footnote, { mode: 'active' });
	                this.hideFootnotePreview();
	            }
	        });
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
	            this.$footnotePreview = $('<div class="geweb-ai-footnote-preview" role="tooltip"></div>');
	            $('body').append(this.$footnotePreview);
	        }

	        this.$footnotePreview.empty();
	        if (title) {
	            this.$footnotePreview.append($('<div class="geweb-ai-footnote-preview-title"></div>').text(title));
	        }
	        if (path) {
	            this.$footnotePreview.append($('<div class="geweb-ai-footnote-preview-meta"></div>').text(path));
	        }
	        if (contextCount > 1) {
	            this.$footnotePreview.append($('<div class="geweb-ai-footnote-preview-meta"></div>').text(`${contextCount} contexts`));
	        }
	        if (snippet) {
	            this.$footnotePreview.append($('<div class="geweb-ai-footnote-preview-snippet"></div>').text(snippet));
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
	        this.$copyConversationBtn.attr('title', copied ? t('copied', 'Copied') : t('copyFailed', 'Could not copy'));

	        globalThis.setTimeout(() => {
	            this.$copyConversationBtn.removeClass('is-copied');
	            this.$copyConversationBtn.attr('aria-label', t('copyConversation', 'Copy chat'));
	            this.$copyConversationBtn.attr('title', t('copyConversation', 'Copy chat'));
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
	            const existingArchiveById = new Map(this.conversationArchive.map((entry) => [entry.id, entry]));
	            const response = await this.requestFrontendConversation('geweb_get_frontend_conversations', {});
	            const conversations = Array.isArray(response?.data?.conversations)
	                ? response.data.conversations
	                : [];
	            this.conversationArchive = conversations
	                .filter((entry) => entry && typeof entry === 'object')
	                .map((entry) => {
	                    const normalizedEntry = this.normalizeStoredConversation(entry);
	                    const existingEntry = existingArchiveById.get(normalizedEntry.id);
	                    if (existingEntry?.messages?.length) {
	                        normalizedEntry.messages = existingEntry.messages;
	                    }
	                    return normalizedEntry;
	                });
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

	        this.renderMoreConversationOverviewItems();
	    },

	    renderMoreConversationOverviewItems() {
	        if (!this.$conversationOverview.length) {
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

	            const dateLabel = entry.savedAt ? new Date(entry.savedAt).toLocaleString() : '';
	            const summaryLabel = entry.summary || t('untitledConversation', 'Untitled chat');
	            const tooltipLabel = dateLabel ? `${summaryLabel} (${dateLabel})` : summaryLabel;
	            $item.attr('title', tooltipLabel);
	            $item.attr('aria-label', tooltipLabel);
	            $item.append($('<span class="geweb-ai-overview-item-icon" aria-hidden="true">💬</span>'));

	            const $removeButton = $('<button type="button" class="geweb-ai-overview-item-remove" aria-label="Remove chat" title="Remove chat"><span aria-hidden="true">−</span></button>');
	            $removeButton.on('click', (event) => {
	                event.preventDefault();
	                event.stopPropagation();
	                void this.deleteConversationById(entry.id);
	            });

	            $item.append($removeButton);
	            $item.append($('<div class="geweb-ai-overview-role"></div>').text(dateLabel || t('savedChat', 'Saved chat')));
	            $item.append($('<div class="geweb-ai-overview-preview"></div>').text(summaryLabel));
	            $item.on('click', () => this.loadConversation(entry.id));
	            this.$conversationOverview.append($item);
	        });

	        this.conversationOverviewRenderedCount += nextEntries.length;
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
	        const allowed = new Set(['p', 'br', 'b', 'strong', 'i', 'em', 'ul', 'ol', 'li', 'a', 'h1', 'h2', 'h3', 'span', 'sup']);
					const div = document.createElement('div');
					div.innerHTML = html;

					div.querySelectorAll('*').forEach(el => {
							if (!allowed.has(el.tagName.toLowerCase())) {
									el.replaceWith(document.createTextNode(el.textContent));
									return;
							}
							Array.from(el.attributes).forEach(attr => {
									if (el.tagName.toLowerCase() === 'a' && attr.name === 'href') {
											if (!/^https?:\/\//i.test(attr.value)) {
													el.removeAttribute('href');
											}
									} else if (el.tagName.toLowerCase() === 'sup' && ['class', 'data-footnote'].includes(attr.name)) {
											return;
									} else if (el.tagName.toLowerCase() === 'span' && attr.name === 'class' && String(attr.value || '').includes('geweb-ai-footnote-group')) {
											return;
									} else if (!['target', 'rel'].includes(attr.name)) {
											el.removeAttribute(attr.name);
									}
							});
							if (el.tagName.toLowerCase() === 'a') {
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
	        this.$answerBox.html('');
	        this.conversationArchive = [];
	        this.renderConversationOverview();
	        this.renderConversationSummary();
	        this.renderSources();
	        this.syncStoredChatState();
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

	        try {
	            globalThis.localStorage.setItem(this.getStoredChatStateKey(), JSON.stringify({
	                conversationId: this.conversationId,
	                compactedConversation: this.compactedConversation,
	                conversationHistory: this.conversationHistory,
	                conversationArchive: this.conversationArchive,
	            }));
	        } catch (error) {
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
	                    role: entry.role === 'model' ? 'model' : 'user',
	                    content: String(entry.content || ''),
	                    sources: Array.isArray(entry.sources) ? entry.sources : [],
	                    meta: entry.meta && typeof entry.meta === 'object' ? entry.meta : {}
	                }))
	                .filter((entry) => entry.content.trim() !== '')
	            : [];

	        if (storedConversationId && storedHistory.length) {
	            this.conversationId = storedConversationId;
	            this.conversationHistory = storedHistory;
	            this.compactedConversation = !!storedState.compactedConversation;
	        }

	        return storedArchive.length > 0 || storedHistory.length > 0;
	    },

	    normalizeStoredConversation(entry) {
	        const messages = Array.isArray(entry.messages) ? entry.messages : [];
	        const normalizedMessages = messages
	            .filter((item) => item && typeof item === 'object')
	            .map((item) => ({
	                role: item.role === 'model' ? 'model' : 'user',
	                content: String(item.content || ''),
	                sources: Array.isArray(item.sources) ? item.sources : [],
	                meta: item.meta && typeof item.meta === 'object' ? item.meta : {}
	            }))
	            .filter((item) => item.content.trim() !== '');
	        const summary = typeof entry.summary === 'string' && entry.summary.trim() !== ''
	            ? entry.summary.trim()
	            : this.buildConversationSummaryFromMessages(normalizedMessages);

	        return {
	            id: typeof entry.id === 'string' && entry.id ? entry.id : this.generateConversationId(),
	            savedAt: Number(entry.savedAt || Date.now()),
	            compacted: !!entry.compacted,
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
	        let entry = this.conversationArchive.find((item) => item.id === conversationId);
	        if (!entry?.messages?.length) {
	            try {
	                const response = await this.requestFrontendConversation('geweb_get_frontend_conversation', {
	                    conversation_id: conversationId
	                });
		                if (!(response?.success && response?.data?.conversation)) {
		                    this.conversationArchive = this.conversationArchive.filter((item) => item.id !== conversationId);
		                    this.renderConversationOverview();
		                    return false;
	                }
	                entry = this.normalizeStoredConversation(response.data.conversation);
	                this.conversationArchive = this.conversationArchive.map((item) => item.id === entry.id ? entry : item);
	                if (!this.conversationArchive.some((item) => item.id === entry.id)) {
	                    this.conversationArchive.unshift(entry);
	                }
		            } catch (error) {
		                console.debug('Load conversation failed.', error);
		                this.conversationArchive = this.conversationArchive.filter((item) => item.id !== conversationId);
	                this.renderConversationOverview();
	                this.renderConversationSummary();
	                return false;
	            }
	        }

	        this.conversationId = entry.id;
	        this.conversationHistory = entry.messages.map((item) => ({
	            role: item.role,
	            content: item.content,
	            sources: Array.isArray(item.sources) ? item.sources : [],
	            meta: item.meta && typeof item.meta === 'object' ? item.meta : {}
	        }));
	        this.compactedConversation = !!entry.compacted;
	        this.requestInFlight = false;
	        this.$answerBox.empty();

	        if (this.compactedConversation) {
	            this.appendSystemNote(t('earlierTrimmed', 'Earlier messages were trimmed to keep the chat context compact.'));
	        }

	        this.conversationHistory.forEach((item) => {
	            if (item.role === 'model') {
	                this.appendMessage({
	                    answer: item.content,
	                    sources: item.sources || [],
	                    meta: item.meta || {}
	                }, 'ai');
	                return;
	            }

	            this.appendMessage(item.content, 'user');
	        });

	        this.renderConversationOverview();
	        this.renderConversationSummary();
	        this.renderSources();
	        this.toggleSubmitButton();
	        this.syncFrontendPageConversationState();
	        this.syncStoredChatState();
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
	            return false;
	        }
	    },

	    createNewConversation(shouldFocus) {
	        this.conversationId = this.generateConversationId();
	        this.conversationHistory = [];
	        this.compactedConversation = false;
	        this.requestInFlight = false;
	        this.$answerBox.empty();
	        this.renderConversationOverview();
	        this.renderConversationSummary();
	        this.renderSources();
	        this.toggleSubmitButton();
	        this.syncFrontendPageConversationState();
	        this.syncStoredChatState();

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
	highlightFirstPageMatch();
});
