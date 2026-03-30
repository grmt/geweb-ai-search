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

	        url.searchParams.set('geweb_ai_chat', '1');

	        if (trimmedQuery) {
	            url.searchParams.set('s', trimmedQuery);
	        } else {
	            url.searchParams.delete('s');
	        }

	        if (trimmedConversationId) {
	            url.searchParams.set('geweb_ai_conversation', trimmedConversationId);
	        } else {
	            url.searchParams.delete('geweb_ai_conversation');
	        }

	        return url.toString();
	    },

	    injectAIButtons() {
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

	        document.body.classList.add('geweb-ai-page-open');
	        $('.geweb-ai-search-results-toggle').on('click', function() {
	            const $button = $(this);
	            const $panel = $button.closest('.geweb-ai-search-results-panel');
	            const isExpanded = $button.attr('aria-expanded') === 'true';

	            $panel.toggleClass('is-collapsed', isExpanded);
	            $button.attr('aria-expanded', isExpanded ? 'false' : 'true');
	            $button.text(isExpanded ? t('showResults', 'Show results') : t('hideResults', 'Hide results'));
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
	    $answerBox: $('.answer-box'),
	    $conversationOverview: $('#geweb-ai-conversation-overview'),
	    $sourcesBox: $('#geweb-ai-sources'),
	    $currentConversationSummary: $('#geweb-ai-current-conversation-summary'),
	    $renameConversationBtn: $('#geweb-ai-rename-conversation'),
	    $deleteConversationBtn: $('#geweb-ai-delete-conversation'),
	    $newConversationBtn: $('.geweb-ai-new-conversation'),
	    conversationHistory: [],
	    conversationId: '',
	    requestInFlight: false,
	    compactedConversation: false,
	    conversationArchive: [],

	    init() {
	        if (!this.$textarea.length) return;

	        this.$textarea.on('input', () => this.toggleSubmitButton());
	        this.$submitBtn.on('click', () => this.sendMessage());
	        this.$renameConversationBtn.on('click', () => { void this.renameCurrentConversation(); });
	        this.$deleteConversationBtn.on('click', () => { void this.deleteCurrentConversation(); });
	        this.$newConversationBtn.on('click', () => this.createNewConversation(true));
	        this.$textarea.on('keydown', (e) => {
	            if (e.key === 'Enter' && !e.shiftKey) {
	                e.preventDefault();
	                if (!this.$submitBtn.prop('disabled')) {
	                    this.sendMessage();
	                }
	            }
	        });
	        void this.bootstrap();
	    },

	    async bootstrap() {
	        await this.loadConversationArchive();
	        await this.applyFrontendRequestState();
	        this.renderConversationOverview();
	        this.renderConversationSummary();
	        this.renderSources(this.getLatestSources(), this.getLatestAnswerText());
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

	    async sendMessage(prefilledMessage) {
	        const message = typeof prefilledMessage === 'string'
	            ? prefilledMessage.trim()
	            : this.$textarea.val().trim();

	        if (!message || this.requestInFlight) return;

	        this.conversationHistory.push({ role: 'user', content: message });
	        this.appendMessage(message, 'user');
	        this.renderConversationOverview();
	        this.renderConversationSummary();
	        this.renderSources([], '');
	        this.persistConversation();

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
	                messages: [{
	                    role: 'user',
	                    content: message
	                }]
	            },
	            success: (response) => this.handleResponse(response, $loader),
	            error: () => this.handleError($loader)
	        });
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
		            sources: Array.isArray(response.data.sources) ? response.data.sources : []
		        });
		        if (!compactedBeforeAppend && this.compactedConversation) {
		            this.appendSystemNote(t('earlierTrimmed', 'Earlier messages were trimmed to keep the conversation context compact.'));
		        }
		        this.appendMessage(response.data, 'ai');
		        this.renderSources(response.data.sources || [], response.data.answer || '');
		        this.persistConversation();
		        await this.loadConversationArchive();
		        this.renderConversationOverview();
		        this.renderConversationSummary();
		    } else {
		        this.appendMessage({ answer: t('answerError', 'Error: Unable to get response'), sources: [] }, 'ai');
		    }
	        this.toggleSubmitButton();
		},

		handleError($loader) {
		    $loader.remove();
		    this.requestInFlight = false;
		    this.appendMessage({ answer: t('connectionError', 'Connection error. Please try again.'), sources: [] }, 'ai');
	        this.toggleSubmitButton();
		},

		appendMessage(text, type) {
		    if (type === 'user') {
		        const $msg = $(`<p class="user-message">${this.escapeHtml(text)}</p>`);
		        this.$answerBox.append($msg);
		    } else {
		        const $container = $('<div class="ai-message"></div>');
						$container.html(this.sanitizeAnswer(text.answer));

		        this.$answerBox.append($container);
		    }

		    this.scrollToBottom();
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

	    renderSources(sources, answerText) {
	        if (!this.$sourcesBox.length) {
	            return;
	        }

	        this.$sourcesBox.empty();

	        const normalizedSources = this.normalizeSources(sources, answerText);

	        if (!normalizedSources.length) {
	            this.$sourcesBox.append($('<p class="geweb-ai-empty-panel"></p>').text(t('noSourcesYet', 'No source links yet.')));
	            return;
	        }

	        const $list = $('<ul class="geweb-ai-source-list"></ul>');
	        normalizedSources.forEach((source) => {
	            const title = source.title;
	            const url = source.url;
	            const $item = $('<li></li>');
	            const $link = $('<a target="_blank" rel="noopener noreferrer" class="geweb-ai-source-link"></a>').text(title);
	            const $meta = $('<div class="geweb-ai-source-meta"></div>');

	            if (url) {
	                $link.attr('href', url);
	            } else {
	                $link.attr('href', '#');
	            }

	            $item.append($link);
	            if (source.mentioned) {
	                $meta.append($('<span class="geweb-ai-source-badge"></span>').text(t('mentionedInAnswer', 'Mentioned in answer')));
	            }
	            if ($meta.children().length) {
	                $item.append($meta);
	            }
	            $list.append($item);
	        });

	        this.$sourcesBox.append($list);
	    },

	    normalizeSources(sources, answerText) {
	        if (!Array.isArray(sources) || !sources.length) {
	            return [];
	        }

	        const seen = new Set();
	        const haystack = String(answerText || '').toLowerCase();

	        return sources.reduce((accumulator, source) => {
	            const title = source && source.title ? String(source.title).trim() : '';
	            const url = source && source.url ? String(source.url).trim() : '';
	            const key = (url || title).toLowerCase();

	            if (!key || seen.has(key)) {
	                return accumulator;
	            }

	            seen.add(key);
	            accumulator.push({
	                title: title || url || t('untitledConversation', 'Untitled conversation'),
	                url: url,
	                mentioned: haystack !== '' && (
	                    (title !== '' && haystack.includes(title.toLowerCase())) ||
	                    (url !== '' && haystack.includes(url.toLowerCase()))
	                ),
	            });
	            return accumulator;
	        }, []);
	    },

	    getLatestSources() {
	        for (let index = this.conversationHistory.length - 1; index >= 0; index -= 1) {
	            const item = this.conversationHistory[index];
	            if (item && item.role === 'model' && Array.isArray(item.sources) && item.sources.length) {
	                return item.sources;
	            }
	        }

	        return [];
	    },

	    getLatestAnswerText() {
	        for (let index = this.conversationHistory.length - 1; index >= 0; index -= 1) {
	            const item = this.conversationHistory[index];
	            if (item && item.role === 'model' && item.content) {
	                return String(item.content);
	            }
	        }

	        return '';
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
	        return 'geweb-ai-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
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
					const allowed = ['p', 'br', 'b', 'strong', 'i', 'em', 'ul', 'ol', 'li', 'a', 'h1', 'h2', 'h3'];
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
	        this.renderSources([]);
	    },

	    normalizeStoredConversation(entry) {
	        const messages = Array.isArray(entry.messages) ? entry.messages : [];
	        const normalizedMessages = messages
	            .filter((item) => item && typeof item === 'object')
	            .map((item) => ({
	                role: item.role === 'model' ? 'model' : 'user',
	                content: String(item.content || ''),
	                sources: Array.isArray(item.sources) ? item.sources : []
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
	                    return;
	                }
	                entry = this.normalizeStoredConversation(response.data.conversation);
	                this.conversationArchive = this.conversationArchive.map((item) => item.id === entry.id ? entry : item);
	                if (!this.conversationArchive.some((item) => item.id === entry.id)) {
	                    this.conversationArchive.unshift(entry);
	                }
	            } catch (error) {
	                return;
	            }
	        }

	        this.conversationId = entry.id;
	        this.conversationHistory = entry.messages.map((item) => ({
	            role: item.role,
	            content: item.content,
	            sources: Array.isArray(item.sources) ? item.sources : []
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
	                    sources: item.sources || []
	                }, 'ai');
	                return;
	            }

	            this.appendMessage(item.content, 'user');
	        });

	        this.renderConversationOverview();
	        this.renderConversationSummary();
	        this.renderSources(this.getLatestSources(), this.getLatestAnswerText());
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
	        this.renderSources([], '');
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

	GewebModal.init();
	GewebAIChat.init();
});
