jQuery(document).ready(function($) {
	const GEWEB_AI_CONVERSATION_STORAGE_KEY = 'gewebAiConversationArchiveV1';
	let nonceRequest = null;

	function fetchSearchNonce() {
		return $.post(geweb_aisearch.ajax_url, {
			action: 'geweb_get_nonce'
		}).then(function(response) {
			if (response && response.success && response.data && response.data.nonce) {
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

	ensureSearchNonce().catch(function() {
		return null;
	});

	function t(key, fallback) {
		if (geweb_aisearch && geweb_aisearch.i18n && geweb_aisearch.i18n[key]) {
			return geweb_aisearch.i18n[key];
		}

		return fallback;
	}

	function getConversationTrimMessageLimit() {
		const value = Number(geweb_aisearch && geweb_aisearch.conversation_trim_message_limit);
		return Number.isFinite(value) && value >= 2 ? value : 12;
	}

	function getConversationTrimCharLimit() {
		const value = Number(geweb_aisearch && geweb_aisearch.conversation_trim_char_limit);
		return Number.isFinite(value) && value >= 500 ? value : 12000;
	}

	function getLocalConversationArchiveLimit() {
		const value = Number(geweb_aisearch && geweb_aisearch.local_conversation_archive_limit);
		return Number.isFinite(value) && value >= 1 ? value : 12;
	}
	
	const GewebModal = {
	    ai: document.getElementById('geweb-ai-modal'),

	    init() {
	        if (!this.ai) return;
	        this.injectAIButtons();
	        this.bindClose();
	        this.openPageViewIfNeeded();
	    },

	    isPageView() {
	        return this.ai && this.ai.getAttribute('data-geweb-page-view') === '1';
	    },

	    buildFrontendAiPageUrl(query, conversationId) {
	        const baseUrl = geweb_aisearch.frontend_ai_page_url || window.location.href;
	        const url = new URL(baseUrl, window.location.origin);
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

	            const inheritedClasses = (($searchButton.attr('class') || '').trim() || 'basic-button').split(/\s+/).filter(Boolean);

	            if (geweb_aisearch.frontend_ai_interface === 'fullscreen') {
	                const buttonClasses = ['geweb-ai-page-trigger'].concat(inheritedClasses).join(' ');
	                const $button = $(`<button type="button" class="${buttonClasses}"></button>`);
	                $button.attr('aria-label', t('openAiSearch', 'Open AI Search'));
	                this.matchTriggerButtonSize($button, $searchButton);
	                $button.on('click', () => {
	                    const query = $input.val().trim();
	                    window.location.href = this.buildFrontendAiPageUrl(query, GewebAIChat.getPreferredConversationId());
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

	            const buttonClasses = ['geweb-ai-trigger', 'geweb-ai-trigger--icon'].concat(inheritedClasses).join(' ');
	            const askAiLabel = this.escapeAttr(t('askAi', 'Ask AI'));
	            const $button = $(`<button type="button" class="${buttonClasses}" aria-label="${askAiLabel}"></button>`);
	            this.matchTriggerButtonSize($button, $searchButton);
	            $button.on('click', () => {
	                const query = $input.val().trim();
	                this.openAI(query);
	            });

	            $form.addClass('geweb-ai-search-form');
	            $input.addClass('geweb-ai-search-input');
	            $form.append($button);
	        });
	    },

	    openAI(query) {
	        const trimmedQuery = (query || '').trim();
	        $('#geweb-ai-query-display').val(GewebAIChat.shouldAutoSubmitQuery(trimmedQuery) ? trimmedQuery : '');
	        GewebAIChat.toggleSubmitButton();
	        document.body.classList.add('no-scroll');
	        if (typeof this.ai.showModal === 'function') {
	            this.ai.showModal();
	        }
	        GewebAIChat.focusInput();

	        if (GewebAIChat.shouldAutoSubmitQuery(trimmedQuery)) {
	            window.setTimeout(function() {
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

	        if (Number.isFinite(width) && width > 0) {
	            $button.css({
	                width: `${width}px`,
	                minWidth: `${width}px`,
	            });
	        }

	        if (Number.isFinite(height) && height > 0) {
	            $button.css('height', `${height}px`);
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
                    window.location.href = geweb_aisearch.frontend_ai_exit_url || window.location.origin;
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
	
	        document.body.classList.add('geweb-ai-page');
	        document.body.classList.add('geweb-ai-page-open');
	        this.bindFrontendHeaderSearch();
	        this.bindWorkspacePaneResizers();
	        this.bindPanelCollapseButtons();
	        this.syncPanelCollapseButtons();
	    },

	    bindWorkspacePaneResizers() {
	        const workspace = this.ai ? this.ai.querySelector('.geweb-ai-workspace') : null;
	        if (!workspace || window.innerWidth <= 1023) {
	            return;
	        }

	        const minLeft = 180;
	        const maxLeft = 420;
	        const minRight = 220;
	        const maxRight = 420;
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
	                return;
	            }

	            const maxAllowed = Math.min(maxRight, totalWidth - minLeft - minMain - 32);
	            const nextWidth = clamp(rawWidth, minRight, Math.max(minRight, maxAllowed));
	            workspace.style.setProperty('--geweb-ai-right-pane-width', `${Math.round(nextWidth)}px`);
	        };

	        workspace.querySelectorAll('.geweb-ai-pane-resizer').forEach((handle) => {
	            const side = handle.getAttribute('data-resize-target');
	            if (!side) {
	                return;
	            }

	            handle.addEventListener('pointerdown', (event) => {
	                event.preventDefault();
	                const rect = workspace.getBoundingClientRect();
	                handle.classList.add('is-active');
	                document.body.style.cursor = 'ew-resize';

	                const move = (moveEvent) => {
	                    if (side === 'left') {
	                        setPaneWidth('left', moveEvent.clientX - rect.left);
	                        return;
	                    }

	                    setPaneWidth('right', rect.right - moveEvent.clientX);
	                };

	                const stop = () => {
	                    handle.classList.remove('is-active');
	                    document.body.style.cursor = '';
	                    window.removeEventListener('pointermove', move);
	                    window.removeEventListener('pointerup', stop);
	                };

	                window.addEventListener('pointermove', move);
	                window.addEventListener('pointerup', stop);
	            });
	        });
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
	        const leftCollapsed = !!(workspace && workspace.classList.contains('is-left-collapsed'));
	        const rightCollapsed = !!(workspace && workspace.classList.contains('is-right-collapsed'));
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
	            leftCollapsed ? 'Expand conversations panel' : 'Collapse conversations panel'
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
	                window.location.href = this.buildFrontendAiPageUrl(query, GewebAIChat.getPreferredConversationId());
	            });
	        });
	    },

	    escapeAttr(text) {
	        return String(text).replace(/"/g, '&quot;');
	    },

	};

		const GewebAIChat = {
		    $textarea: $('#geweb-ai-query-display'),
		    $submitBtn: $('#geweb-ask-ai-submit'),
		    $modelSelector: $('#geweb-ai-model-selector'),
		    $settingsToggle: $('#geweb-ai-toggle-temp-settings'),
		    $settingsPanel: $('#geweb-ai-temporary-settings-panel'),
		    $resetSettingsBtn: $('#geweb-ai-reset-temp-settings'),
		    $currentModelDisplay: $('#geweb-ai-current-model-display'),
		    $currentPromptDisplay: $('#geweb-ai-current-prompt-display'),
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
		    sourceReferenceCache: {},

		    init() {
		        if (!this.$textarea.length) return;

		        this.$textarea.on('input', () => this.toggleSubmitButton());
		        this.$submitBtn.on('click', () => this.sendMessage());
		        this.$settingsToggle.on('click', () => this.toggleTemporarySettings());
		        this.$resetSettingsBtn.on('click', () => this.resetTemporarySettings(true));
		        this.$modelSelector.on('change', () => this.handleTemporaryModelChange());
		        this.$temporaryPrompt.on('input', () => this.updateTemporarySettingsSummary());
		        this.$copyConversationBtn.on('click', () => { void this.copyCurrentConversation(); });
		        this.$renameConversationBtn.on('click', () => { void this.renameCurrentConversation(); });
		        this.$deleteConversationBtn.on('click', () => { void this.deleteCurrentConversation(); });
		        this.$newConversationBtn.on('click', () => this.createNewConversation(true));
		        $(document).on('click', (event) => this.handleDocumentClick(event));
		        this.$textarea.on('keydown', (e) => {
		            if (e.key === 'Enter' && !e.shiftKey) {
		                e.preventDefault();
	                if (!this.$submitBtn.prop('disabled')) {
	                    this.sendMessage();
	                }
	            }
		        });
		        $(document).on('keydown', (event) => {
		            if (event.key === 'Escape') {
		                this.toggleTemporarySettings(false);
		            }
		        });
		        this.resetTemporarySettings();
		        void this.bootstrap();
		    },

	    async bootstrap() {
	        await this.loadConversationArchive();
	        await this.applyFrontendRequestState();
	        this.renderConversationOverview();
	        this.renderConversationSummary();
	        this.renderSources();
	        this.toggleSubmitButton();
	    },

	    toggleSubmitButton() {
	        const hasText = this.$textarea.val().trim().length > 0;
	        this.$submitBtn.prop('disabled', !hasText || this.requestInFlight);
	    },

	    focusInput() {
	        if (this.$textarea.length) {
	            this.$textarea.trigger('focus');
	        }
	    },

	    shouldAutoSubmitQuery(query) {
	        const trimmedQuery = (query || '').trim();
	        if (trimmedQuery === '' || this.requestInFlight) {
	            return false;
	        }

	        return trimmedQuery.split(/\s+/).length > 1;
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
		            name: String(descriptor.name || t('temporaryPrompt', 'Temporary prompt')).trim(),
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
	        const responseJsonMessage = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
	            ? String(xhr.responseJSON.data.message).trim()
	            : '';
	        if (responseJsonMessage) {
	            return responseJsonMessage;
	        }

	        const rawResponseText = xhr && typeof xhr.responseText === 'string'
	            ? String(xhr.responseText).trim()
	            : '';
	        if (rawResponseText) {
	            const plainText = $('<div></div>').html(rawResponseText).text().replace(/\s+/g, ' ').trim();
	            if (plainText) {
	                return plainText.length > 220 ? `${plainText.slice(0, 217)}...` : plainText;
	            }
	        }

	        return fallbackMessage;
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
		        const promptDescriptor = temporaryPrompt !== '' && temporaryPrompt !== basePrompt
		            ? {
		                name: t('temporaryPrompt', 'Temporary prompt'),
		                instruction: temporaryPrompt,
		            }
		            : baseDescriptor;

		        if (this.$currentModelDisplay.length) {
		            this.$currentModelDisplay.text(selectedModel);
		        }

		        if (this.$currentPromptDisplay.length) {
		            this.$currentPromptDisplay.text(promptDescriptor.name || t('composerPromptLabel', 'Prompt'));
		            this.$currentPromptDisplay.attr('title', promptDescriptor.instruction || '');
		            this.$currentPromptDisplay.attr('aria-label', `${t('composerPromptLabel', 'Prompt')}: ${promptDescriptor.name || ''}`);
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

		    toggleTemporarySettings(forceState) {
		        if (!this.$settingsPanel.length || !this.$settingsToggle.length) {
		            return;
		        }

		        const shouldOpen = typeof forceState === 'boolean'
		            ? forceState
		            : this.$settingsPanel.prop('hidden');

		        this.$settingsPanel.prop('hidden', !shouldOpen);
		        this.$settingsToggle.attr('aria-expanded', shouldOpen ? 'true' : 'false');

		        if (shouldOpen && this.$temporaryPrompt.length) {
		            this.$temporaryPrompt.trigger('focus');
		        }
		    },

		    handleDocumentClick(event) {
		        if (!this.$settingsPanel.length || this.$settingsPanel.prop('hidden')) {
		            return;
		        }

		        const $target = $(event.target);
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
	            $loader.remove();
	            this.requestInFlight = false;
	            this.appendMessage({ answer: t('couldNotStart', 'Could not start the AI search. Please try again.'), sources: [] }, 'ai');
	            this.toggleSubmitButton();
	            return;
	        }

		        $.ajax({
		            url: geweb_aisearch.ajax_url,
		            type: 'POST',
		            data: {
		                action: 'geweb_ai_chat',
		                nonce: geweb_aisearch.search_nonce,
		                conversation_id: this.ensureConversationId(),
		                model: this.getSelectedModel(),
		                temporary_prompt: this.getTemporaryPrompt(),
		                messages: [{
		                    role: 'user',
		                    content: message
		                }]
		            },
		            success: (response) => this.handleResponse(response, $loader),
		            error: (xhr) => this.handleError($loader, xhr)
		        });
		        this.resetTemporarySettings();
		        this.toggleTemporarySettings(false);
		    },

		async handleResponse(response, $loader) {
		    $loader.remove();
		    this.requestInFlight = false;
		    if (response.success && response.data) {
		        const compactedBeforeAppend = this.compactedConversation;
		        this.compactedConversation = !!response.data.context_compacted;
		        this.conversationHistory.push({
		            role: 'model',
		            content: response.data.answer,
		            sources: Array.isArray(response.data.sources) ? response.data.sources : [],
		            meta: response.data && response.data.meta && typeof response.data.meta === 'object' ? response.data.meta : {}
		        });
		        if (!compactedBeforeAppend && this.compactedConversation) {
		            this.appendSystemNote(t('earlierTrimmed', 'Earlier messages were trimmed to keep the conversation context compact.'));
		        }
		        this.appendMessage(response.data, 'ai');
		        this.renderSources();
		        this.persistConversation();
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
		    } else {
		        const $container = $('<div class="ai-message"></div>');
		        const responseMeta = text && text.meta && typeof text.meta === 'object' ? text.meta : {};
		        const sourceFootnoteMap = this.getResponseSourceFootnoteMap(text.sources || [], text.answer || '', responseMeta);
		        const answerWithFootnotes = this.decorateAnswerWithGroundingFootnotes(String(text.answer || ''), responseMeta, sourceFootnoteMap);
		        const sanitizedAnswer = this.sanitizeAnswer(answerWithFootnotes);
		        const $content = $('<div class="geweb-ai-message-content"></div>').html(sanitizedAnswer);
		        const plainText = this.extractPlainTextFromHtml(sanitizedAnswer);
		        const $copyButton = this.buildCopyButton(plainText);
		        const $details = this.buildResponseDetails(responseMeta);
		        const $messageActions = $('<div class="geweb-ai-message-actions"></div>');

		        if ($copyButton) {
		            $messageActions.append($copyButton);
		        }

		        if ($details) {
		            this.bindFootnoteInteractions($content);
		            const $detailsButton = $('<button type="button" class="geweb-ai-message-details-toggle"></button>');
		            $detailsButton.attr('aria-expanded', 'false');
		            $detailsButton.attr('title', t('showDetails', 'Show details'));
		            $detailsButton.text(t('showDetails', 'Show details'));
		            $detailsButton.on('click', () => {
		                this.toggleResponseDetails($container);
		                const expanded = $details.hasClass('is-open');
		                $detailsButton.attr('aria-expanded', expanded ? 'true' : 'false');
		                $detailsButton.attr('title', expanded ? t('hideDetails', 'Hide details') : t('showDetails', 'Show details'));
		                $detailsButton.text(expanded ? t('hideDetails', 'Hide details') : t('showDetails', 'Show details'));
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
		    }

		    this.scrollToBottom();
		},

	    extractPlainTextFromHtml(html) {
	        const container = document.createElement('div');
	        container.innerHTML = String(html || '');
	        return String(container.textContent || container.innerText || '').replace(/\s+/g, ' ').trim();
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
	        $button.on('click', async () => {
	            const copied = await this.copyTextToClipboard(plainText);
	            $button.toggleClass('is-copied', copied);
	            $button.attr('aria-label', copied ? t('copied', 'Copied') : t('copyFailed', 'Could not copy'));
	            $button.attr('title', copied ? t('copied', 'Copied') : t('copyFailed', 'Could not copy'));

	            window.setTimeout(() => {
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
	            const footnote = Number($(event.currentTarget).attr('data-footnote') || 0);
	            if (footnote > 0) {
	                this.highlightSourceReference(footnote);
	            }
	        });

	        $footnotes.on('click', (event) => {
	            event.preventDefault();
	            event.stopPropagation();
	            const footnote = Number($(event.currentTarget).attr('data-footnote') || 0);
	            if (footnote > 0) {
	                this.highlightSourceReference(footnote);
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
	                this.highlightSourceReference(footnote);
	            }
	        });
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
	                    : String(item.content || '').replace(/\s+/g, ' ').trim();

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

	        window.setTimeout(() => {
	            this.$copyConversationBtn.removeClass('is-copied');
	            this.$copyConversationBtn.attr('aria-label', t('copyConversation', 'Copy conversation'));
	            this.$copyConversationBtn.attr('title', t('copyConversation', 'Copy conversation'));
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
	                // Fall through to legacy copy path.
	            }
	        }

	        const textarea = document.createElement('textarea');
	        textarea.value = value;
	        textarea.setAttribute('readonly', 'readonly');
	        textarea.style.position = 'absolute';
	        textarea.style.left = '-9999px';
	        document.body.appendChild(textarea);
	        textarea.select();

	        let copied = false;
	        try {
	            copied = document.execCommand('copy');
	        } catch (error) {
	            copied = false;
	        }

	        document.body.removeChild(textarea);
	        return copied;
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
	    },

	    scrollToBottom() {
	        this.$answerBox[0].scrollTop = this.$answerBox[0].scrollHeight;
	    },

	    persistConversation() {
	        this.upsertCurrentConversationInArchive();
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
	            const conversations = Array.isArray(response && response.data && response.data.conversations)
	                ? response.data.conversations
	                : [];
	            this.conversationArchive = conversations
	                .filter((entry) => entry && typeof entry === 'object')
	                .map((entry) => this.normalizeStoredConversation(entry));
	        } catch (error) {
	            this.conversationArchive = [];
	        }
	    },

	    renderConversationOverview() {
	        if (!this.$conversationOverview.length) {
	            return;
	        }

	        this.$conversationOverview.empty();

	        if (!this.conversationArchive.length) {
	            this.$conversationOverview.append($('<p class="geweb-ai-empty-panel"></p>').text(t('noChatsYet', 'No chats yet.')));
	            return;
	        }

	        this.conversationArchive.forEach((entry) => {
	            const $item = $('<button type="button" class="geweb-ai-overview-item geweb-ai-overview-item--conversation"></button>');
	            if (entry.id === this.conversationId) {
	                $item.addClass('is-current');
	            }

	            const dateLabel = entry.savedAt ? new Date(entry.savedAt).toLocaleString() : '';
	            $item.append($('<div class="geweb-ai-overview-role"></div>').text(dateLabel || t('savedChat', 'Saved chat')));
	            $item.append($('<div class="geweb-ai-overview-preview"></div>').text(entry.summary || t('untitledConversation', 'Untitled conversation')));
	            $item.on('click', () => this.loadConversation(entry.id));
	            this.$conversationOverview.append($item);
	        });
	    },

	    renderConversationSummary() {
	        if (!this.$currentConversationSummary.length) {
	            return;
	        }

	        const currentEntry = this.conversationArchive.find((item) => item.id === this.conversationId);
	        const summary = currentEntry && currentEntry.summary
	            ? currentEntry.summary
	            : this.buildConversationSummaryFromMessages(this.conversationHistory);

	        this.$currentConversationSummary.text(summary || t('untitledConversation', 'Untitled conversation'));
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
					html = html.replace(urlRegex, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>');

					// Allow safe tags only
					const allowed = ['p', 'br', 'b', 'strong', 'i', 'em', 'ul', 'ol', 'li', 'a', 'h1', 'h2', 'h3', 'sup'];
					const div = document.createElement('div');
					div.innerHTML = html;

					div.querySelectorAll('*').forEach(el => {
							if (!allowed.includes(el.tagName.toLowerCase())) {
									el.replaceWith(document.createTextNode(el.textContent));
									return;
							}
							Array.from(el.attributes).forEach(attr => {
									if (el.tagName.toLowerCase() === 'a' && attr.name === 'href') {
											if (!/^https?:\/\//i.test(attr.value)) {
													el.removeAttribute('href');
											}
									} else if (el.tagName.toLowerCase() === 'sup' && ['class', 'data-footnote', 'title'].includes(attr.name)) {
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
	            messages: normalizedMessages
	        };
	    },

	    buildConversationSummaryFromMessages(messages) {
	        for (let index = 0; index < messages.length; index += 1) {
	            const item = messages[index];
	            if (!item || item.role !== 'user') {
	                continue;
	            }

	            const text = String(item.content || '').replace(/\s+/g, ' ').trim();
	            if (!text) {
	                continue;
	            }

	            return text.length > 80 ? `${text.slice(0, 77)}...` : text;
	        }

	        return t('untitledConversation', 'Untitled conversation');
	    },

	    upsertCurrentConversationInArchive() {
	        const id = this.ensureConversationId();
	        const entry = {
	            id: id,
	            savedAt: Date.now(),
	            compacted: this.compactedConversation,
	            summary: this.buildConversationSummaryFromMessages(this.conversationHistory),
	            messages: this.conversationHistory
	        };

	        this.conversationArchive = this.conversationArchive.filter((item) => item.id !== id);
	        this.conversationArchive.unshift(entry);
	        this.conversationArchive = this.conversationArchive.slice(0, getLocalConversationArchiveLimit());
	    },

	    async renameCurrentConversation() {
	        const currentId = this.ensureConversationId();
	        const currentEntry = this.conversationArchive.find((item) => item.id === currentId);
	        const currentSummary = currentEntry && currentEntry.summary
	            ? currentEntry.summary
	            : this.buildConversationSummaryFromMessages(this.conversationHistory);
	        const nextSummary = window.prompt(t('renameConversation', 'Rename conversation'), currentSummary || t('untitledConversation', 'Untitled conversation'));

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
	                return;
	            }
	        }

	        this.conversationArchive = this.conversationArchive.map((item) => item.id === currentId ? {
	            ...item,
	            summary: trimmedSummary
	        } : item);

	        this.persistConversation();
	        this.renderConversationOverview();
	        this.renderConversationSummary();
	    },

	    async deleteCurrentConversation() {
	        const currentId = this.conversationId;
	        if (!currentId) {
	            this.createNewConversation(true);
	            return;
	        }

	        if (!window.confirm(t('removeConversationConfirm', 'Remove this conversation from the current search context?'))) {
	            return;
	        }

	        if (this.conversationArchive.some((item) => item.id === currentId)) {
	            try {
	                await this.requestFrontendConversation('geweb_frontend_delete_conversation', {
	                    conversation_id: currentId
	                });
	            } catch (error) {
	                return;
	            }
	        }

	        await this.loadConversationArchive();
	        if (this.conversationArchive.length) {
	            await this.loadConversation(this.conversationArchive[0].id);
	        } else {
	            this.createNewConversation(true);
	        }
	    },

	    async loadConversation(conversationId) {
	        let entry = this.conversationArchive.find((item) => item.id === conversationId);
	        if (!entry || !entry.messages.length) {
	            try {
	                const response = await this.requestFrontendConversation('geweb_get_frontend_conversation', {
	                    conversation_id: conversationId
	                });
	                if (!(response && response.success && response.data && response.data.conversation)) {
	                    this.conversationArchive = this.conversationArchive.filter((item) => item.id !== conversationId);
	                    this.renderConversationOverview();
	                    return;
	                }
	                entry = this.normalizeStoredConversation(response.data.conversation);
	                this.conversationArchive = this.conversationArchive.map((item) => item.id === entry.id ? entry : item);
	                if (!this.conversationArchive.some((item) => item.id === entry.id)) {
	                    this.conversationArchive.unshift(entry);
	                }
	            } catch (error) {
	                this.conversationArchive = this.conversationArchive.filter((item) => item.id !== conversationId);
	                this.renderConversationOverview();
	                this.renderConversationSummary();
	                return;
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
	            this.appendSystemNote(t('earlierTrimmed', 'Earlier messages were trimmed to keep the conversation context compact.'));
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

	        if (shouldFocus) {
	            this.focusInput();
	        }
	    },

	    async applyFrontendRequestState() {
	        const initialQuery = (geweb_aisearch.frontend_ai_initial_query || '').trim();
	        const requestedConversationId = (geweb_aisearch.frontend_ai_conversation_id || '').trim();

	        if (requestedConversationId && this.conversationArchive.some((item) => item.id === requestedConversationId)) {
	            await this.loadConversation(requestedConversationId);
	        } else if (requestedConversationId) {
	            await this.loadConversation(requestedConversationId);
	        } else if (this.conversationArchive.length) {
	            await this.loadConversation(this.conversationArchive[0].id);
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
	            window.setTimeout(() => this.sendMessage(initialQuery), 0);
	        }
	    }
	};

	if (window.GewebAISearchSourceMethods && typeof window.GewebAISearchSourceMethods === 'object') {
	    Object.assign(GewebAIChat, window.GewebAISearchSourceMethods);
	}

	GewebModal.init();
	GewebAIChat.init();
});
