jQuery(document).ready(function($) {
	$.post(geweb_aisearch.ajax_url, {
			action: 'geweb_get_nonce'
	}, function(response) {
			if (response.success) {
					geweb_aisearch.search_nonce = response.data.nonce;
			}
	});
	
	const GewebModal = {
	    ai: document.getElementById('geweb-ai-modal'),

	    init() {
	        if (!this.ai) return;
	        this.injectAIButtons();
	        this.bindClose();
	    },

	    injectAIButtons() {
	        $('form').has('input[name="s"]').each((_, form) => {
	            const $form = $(form);
	            const $input = $form.find('input[name="s"]').first();

	            if (!$input.length || $form.find('.geweb-ai-trigger').length) {
	                return;
	            }

	            const $button = $('<button type="button" class="geweb-ai-trigger">Ask AI</button>');
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
	        $('#geweb-ai-query-display').val(trimmedQuery);
	        GewebAIChat.toggleSubmitButton();
	        document.body.classList.add('no-scroll');
	        this.ai.showModal();
	    },

        bindClose() {
            const unlockScroll = () => {
                if (!this.ai.open) {
                    document.body.classList.remove('no-scroll');
                }
            };

            $('.close', this.ai).on('click', () => {
                this.ai.close();
                unlockScroll();
            });

            $(this.ai).on('click', (e) => {
                if (e.target === this.ai) {
                    this.ai.close();
                    unlockScroll();
                }
            });
        },

	};

	const GewebAIChat = {
	    $textarea: $('#geweb-ai-query-display'),
	    $submitBtn: $('#geweb-ask-ai-submit'),
	    $answerBox: $('.answer-box'),
	    conversationHistory: [],

	    init() {
	        if (!this.$textarea.length) return;

	        this.$textarea.on('input', () => this.toggleSubmitButton());
	        this.$submitBtn.on('click', () => this.sendMessage());
	        this.$textarea.on('keydown', (e) => {
	            if (e.key === 'Enter' && !e.shiftKey) {
	                e.preventDefault();
	                if (!this.$submitBtn.prop('disabled')) {
	                    this.sendMessage();
	                }
	            }
	        });
	    },

	    toggleSubmitButton() {
	        const hasText = this.$textarea.val().trim().length > 0;
	        this.$submitBtn.prop('disabled', !hasText);
	    },

	    sendMessage() {
	        const message = this.$textarea.val().trim();
	        if (!message) return;

	        this.conversationHistory.push({ role: 'user', content: message });
	        this.appendMessage(message, 'user');

	        this.$textarea.val('');
	        this.toggleSubmitButton();
	        this.$submitBtn.prop('disabled', true);

	        const $loader = $('<p class="ai-message loading">Thinking...</p>');
	        this.$answerBox.append($loader);
	        this.scrollToBottom();

	        $.ajax({
	            url: geweb_aisearch.ajax_url,
	            type: 'POST',
	            data: {
	                action: 'geweb_ai_chat',
	                nonce: geweb_aisearch.search_nonce,
	                messages: this.conversationHistory
	            },
	            success: (response) => this.handleResponse(response, $loader),
	            error: () => this.handleError($loader)
	        });
	    },

		handleResponse(response, $loader) {
		    $loader.remove();
		    if (response.success && response.data) {
		        this.conversationHistory.push({
		            role: 'model',
		            content: response.data.answer
		        });
		        this.appendMessage(response.data, 'ai');
		    } else {
		        this.appendMessage({ answer: 'Error: Unable to get response', sources: [] }, 'ai');
		    }
		},

		handleError($loader) {
		    $loader.remove();
		    this.appendMessage({ answer: 'Connection error. Please try again.', sources: [] }, 'ai');
		},

		appendMessage(text, type) {
		    if (type === 'user') {
		        const $msg = $(`<p class="user-message">${this.escapeHtml(text)}</p>`);
		        this.$answerBox.append($msg);
		    } else {
		        const $container = $('<div class="ai-message"></div>');
						$container.html(this.sanitizeAnswer(text.answer));

		        if (text.sources && text.sources.length > 0) {
		            const $sourcesList = $('<ul></ul>');
		            text.sources.forEach(source => {
		                const $link = $(`<a href="${this.escapeHtml(source.url)}" target="_blank" rel="noopener">${this.escapeHtml(source.title)}</a>`);
		                $sourcesList.append($('<li></li>').append($link));
		            });
		            $container.append($sourcesList);
		        }

		        this.$answerBox.append($container);
		    }

		    this.scrollToBottom();
		},

	    scrollToBottom() {
	        this.$answerBox[0].scrollTop = this.$answerBox[0].scrollHeight;
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
	        this.$answerBox.html('');
	    }
	};

	GewebModal.init();
	GewebAIChat.init();
});
