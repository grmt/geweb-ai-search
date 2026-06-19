jQuery(document).ready(($) => {
	const THOUGHT_TITLE_REGEX_SOURCE = String.raw`(?:[A-Z][\p{Ll}-]+)(?:\s+[A-Z][\p{Ll}-]+){1,5}`;
	const THOUGHT_SECTION_REGEX_FLAGS = 'gu';
	const THOUGHT_MARKDOWN_SECTION_REGEX = /^\*\*([^\n*][^\n]*?)\*\*\n\n([\s\S]+)$/u;
	const THOUGHT_INLINE_HEADING_REGEX = new RegExp(
		String.raw`^(${THOUGHT_TITLE_REGEX_SOURCE})\s+([\s\S]+)$`,
		'u'
	);

	function createThoughtSectionRegex() {
		return new RegExp(
			String.raw`(?:^|\n\n)(${THOUGHT_TITLE_REGEX_SOURCE})\n\n([\s\S]*?)(?=\n\n${THOUGHT_TITLE_REGEX_SOURCE}\n\n|$)`,
			THOUGHT_SECTION_REGEX_FLAGS
		);
	}

	const {
		clearSearchNonce = () => {},
		ensureSearchNonce = () => Promise.reject(new Error('AI search shared helpers are not available.')),
		getLocalConversationArchiveLimit = () => 12,
		t = (_key, fallback) => fallback,
	} = globalThis.GewebAISearchShared || {};
	const scheduleHighlightFirstPageMatch = globalThis.GewebAISearchPageMatch?.scheduleHighlightFirstPageMatch || (() => {});

	ensureSearchNonce().catch(() => {
		return null;
	});

	const GewebModal = typeof globalThis.createGewebAIModal === 'function'
		? globalThis.createGewebAIModal($, t)
		: {
			ai: document.getElementById('geweb-ai-modal'),
			init() {},
			isPageView() {
				return false;
			},
			getWorkspaceElement() {
				return null;
			},
			getWorkspaceAutoCollapseThreshold() {
				return 834;
			},
			shouldShowQuestionInfoButton() {
				return false;
			},
			isMobileWorkspaceNavigationActive() {
				return false;
			},
			setMobileWorkspacePane() {},
			syncPanelCollapseButtons() {},
			syncPageViewHeightToViewport() {},
			schedulePageViewViewportSync() {},
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
			$settingsInfoBtn: $('#geweb-ai-temporary-settings-info'),
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
			answerBoxWasNearBottom: true,

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
				this.$settingsInfoBtn.on('click', (event) => this.toggleTemporarySettingsInfo(event));
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
				this.$answerBox.off('scroll.gewebAnswerStick').on('scroll.gewebAnswerStick', () => {
					this.answerBoxWasNearBottom = this.isAnswerBoxNearBottom(96);
				});
				if (typeof globalThis.ResizeObserver === 'function' && this.$answerBox.length) {
					const answerBoxElement = this.$answerBox.get(0);
					const observer = new globalThis.ResizeObserver(() => {
						if (!this.answerBoxWasNearBottom) {
							return;
						}

						globalThis.requestAnimationFrame(() => {
							this.scrollToBottom();
						});
					});
					if (answerBoxElement) {
						observer.observe(answerBoxElement);
					}
				}
				globalThis.addEventListener('resize', () => {
					if (!this.answerBoxWasNearBottom) {
						return;
					}

					globalThis.requestAnimationFrame(() => {
						this.scrollToBottom();
					});
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
				// eslint-disable-next-line no-console
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
			const defaultRoot = this.ai ? $(this.ai) : $();
			const root = scope ? $(scope) : defaultRoot;
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

		ensureQuestionBoxVisible({ textarea = null, questionBox = null, mainPanel = null, behavior = 'auto' } = {}) {
			const target = textarea || questionBox;
			if (!target || typeof target.getBoundingClientRect !== 'function') {
				return;
			}

			const panelRect = mainPanel && typeof mainPanel.getBoundingClientRect === 'function'
				? mainPanel.getBoundingClientRect()
				: null;
			const targetRect = target.getBoundingClientRect();
			const padding = 12;

			const isVisibleWithinPanel = panelRect
				? targetRect.top >= (panelRect.top + padding) && targetRect.bottom <= (panelRect.bottom - padding)
				: false;

			if (isVisibleWithinPanel) {
				return;
			}

			if (questionBox && typeof questionBox.scrollIntoView === 'function') {
				questionBox.scrollIntoView({
					behavior,
					block: 'nearest',
				});
			}

			if (textarea && textarea !== questionBox && typeof textarea.scrollIntoView === 'function') {
				textarea.scrollIntoView({
					behavior,
					block: 'nearest',
				});
			}
		},

		focusInput() {
			if (this.$textarea.length) {
				this.$textarea.trigger('focus');
			}
		},

		revealQuestionBox(options = {}) {
			const workspace = GewebModal.getWorkspaceElement();
			if (GewebModal.isMobileWorkspaceNavigationActive(workspace)) {
				GewebModal.setMobileWorkspacePane('main', { focusPane: false });
				GewebModal.syncPanelCollapseButtons();
			}

			const textarea = this.$textarea.get(0);
			const questionBox = this.$textarea.closest('.question-box, .geweb-ai-question-box').get(0);
			const mainPanel = this.$textarea.closest('.geweb-ai-main-panel').get(0);
			if (options.focusInput === true) {
				this.focusInput();
			}

			if (!textarea && !questionBox && !mainPanel) {
				return;
			}

			[80, 280].forEach((delay, index) => {
				globalThis.setTimeout(() => {
					GewebModal.syncPageViewHeightToViewport();
					this.ensureQuestionBoxVisible({
						textarea,
						questionBox,
						mainPanel,
						behavior: index === 0 ? 'auto' : 'smooth',
					});
					if (typeof GewebModal.alignPageViewBottomToViewport === 'function') {
						GewebModal.alignPageViewBottomToViewport({ behavior: index === 0 ? 'auto' : 'smooth' });
					}
				}, delay);
			});
		},

		handleQuestionBoxFocus() {
			this.revealQuestionBox();
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
				this.revealQuestionBox({ focusInput: true });
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

			this.applyHistoryButtonState(this.$historyUpBtn, canGoUp);
			this.applyHistoryButtonState(this.$historyDownBtn, canGoDown);
			this.applyHistoryButtonState(this.$retryLastBtn, canRetry);
		},

		applyHistoryButtonState($button, isEnabled) {
			if (!$button) {
				return;
			}

			$button.prop('disabled', !isEnabled).css({
				opacity: isEnabled ? '1' : '0.4',
				cursor: isEnabled ? 'pointer' : 'default'
			});
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
				const lastUserEntry = this.conversationHistory[lastUserIndex] || {};
				const questionText = lastUserEntry.content;
				const recovered = await this.tryRestoreLatestCompletedResponse(
					this.ensureConversationId(),
					lastUserEntry.created_at ?? lastUserEntry.createdAt
				);
				if (recovered) {
					this.historyNavIndex = -1;
					this.syncHistoryButtons();
					return;
				}
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
			if (!target?.closest) {
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

				const scrollableChildCanHandle = (target, deltaY) => {
					let el = target instanceof Element ? target : null;
					while (el && el !== answerBox) {
						if (el.scrollHeight > el.clientHeight + 1) {
							const oy = getComputedStyle(el).overflowY;
							const isScrollable = oy === 'auto' || oy === 'scroll';
							const canScroll = deltaY > 0
								? el.scrollTop + el.clientHeight < el.scrollHeight - 1
								: el.scrollTop > 0;
							if (isScrollable && canScroll) {
								return true;
							}
						}
						el = el.parentElement;
					}
					return false;
				};

				this.$answerBox.on('wheel.gewebEdgeScroll', (event) => {
					if (!isAnswerActive()) {
						return;
					}

					const originalEvent = event.originalEvent;
					const deltaY = Number(originalEvent?.deltaY || 0);
					if (!Number.isFinite(deltaY) || deltaY === 0) {
						return;
					}

					if (scrollableChildCanHandle(event.target, deltaY)) {
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
				// eslint-disable-next-line no-console
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
				nextSibling.before(answerBoxNode);
			}
		},

		async removeConversationTurn(historyIndex) {
			const index = Number(historyIndex);
			if (!Number.isInteger(index) || index < 0 || index >= this.conversationHistory.length) {
				return;
			}

			const targetEntry = this.conversationHistory[index];
			if (targetEntry?.role !== 'user') {
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

		getAjaxTimeoutSecondsForModel(model) {
			const resolvedModel = String(model || this.getSelectedModel() || '').trim().toLowerCase();
			const flashTimeout = Math.max(15, Number(geweb_aisearch.gemini_timeout_flash_seconds) || 90);
			const proTimeout = Math.max(15, Number(geweb_aisearch.gemini_timeout_pro_seconds) || flashTimeout);
			const bufferSeconds = Math.max(0, Number(geweb_aisearch.frontend_ai_ajax_timeout_buffer_seconds) || 120);
			const baseTimeout = resolvedModel.includes('pro') ? proTimeout : flashTimeout;
			return Math.max(30, baseTimeout + bufferSeconds);
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

		formatElapsedSeconds(elapsedSeconds) {
			const totalSeconds = Math.max(0, Math.round(Number(elapsedSeconds) || 0));
			const minutes = Math.floor(totalSeconds / 60);
			const seconds = totalSeconds % 60;
			const parts = [];
			if (minutes > 0) {
				parts.push(`${minutes}m`);
			}
			parts.push(`${seconds}s`);
			return parts.join(' ');
		},

		normalizeAiErrorMessage(message, fallbackMessage = '', options = {}) {
			const rawMessage = String(message || '').trim();
			const fallback = String(fallbackMessage || '').trim() || t('answerError', 'Error: Unable to get response');
			const normalized = rawMessage || fallback;
			const formatted = this.formatAiErrorDisplayMessage(normalized);
			const elapsedSeconds = Math.max(0, Number(options?.elapsed_seconds) || 0);
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
					elapsedSeconds > 0
						? `<div class="geweb-ai-error-card-body">${this.escapeHtml(`${t('requestTimedOutElapsed', 'Elapsed time')}: ${this.formatElapsedSeconds(elapsedSeconds)}`)}</div>`
						: '',
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
				`<div class="geweb-ai-error-card-body geweb-ai-error-card-body--preserve">${this.sanitizeAnswer(this.escapeHtml(formatted).replaceAll('\n', '<br>'))}</div>`,
				'</div>',
			].join('');
		},

		buildTransportRecoveryMessage(message, elapsedSeconds = 0) {
			const errorHtml = this.normalizeAiErrorMessage(
				message,
				t('connectionError', 'Connection error. Please try again.'),
				{ elapsed_seconds: elapsedSeconds }
			);
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
				meta: entry.meta && typeof entry.meta === 'object' ? entry.meta : {},
				created_at: entry.created_at
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
			const inactivityTimeoutMs = this.getAjaxTimeoutSecondsForModel(this.getSelectedModel()) * 1000;
			let lastActivityAtMs = Date.now();
			let lastObservedUpdatedAt = 0;

			for (let attempt = 0; attempt < maxAttempts; attempt += 1) {
				if ((Date.now() - lastActivityAtMs) >= inactivityTimeoutMs) {
					break;
				}

				if (attempt > 0) {
					await new Promise((resolve) => globalThis.setTimeout(resolve, intervalMs));
				}

				this.setLoaderOpacity($loader, '0.4');

				try {
					const response = await this.requestFrontendConversation('geweb_get_ai_chat_job', {
						job_id: normalizedJobId
					});

					this.setLoaderOpacity($loader, '1');

					const payload = response?.data && typeof response.data === 'object' ? response.data : {};
					const status = String(payload.status || '').trim();
					this.updateLoaderFromJobProgress($loader, payload.progress);
					const payloadUpdatedAt = Number(payload.updated_at || 0) > 0 ? Number(payload.updated_at) * 1000 : 0;
					if (payloadUpdatedAt > 0 && payloadUpdatedAt > lastObservedUpdatedAt) {
						lastObservedUpdatedAt = payloadUpdatedAt;
						lastActivityAtMs = Date.now();
					}

					const handledResult = await this.handlePolledJobStatus(status, payload, $loader);
					if (handledResult !== null) {
						return handledResult;
					}
				} catch (error) {
					// eslint-disable-next-line no-console
					console.debug('Polling AI chat job failed.', error);
					this.setLoaderOpacity($loader, '1');
				}
			}

			await this.handleResponse({
				success: false,
				data: {
					message: t('requestTimedOut', 'The AI request timed out. Please try again.'),
					meta: {
						elapsed_seconds: Math.max(1, Math.round((Date.now() - lastActivityAtMs) / 1000))
					}
				}
			}, $loader);
			return false;
		},

		setLoaderOpacity($loader, opacity) {
			if ($loader?.length) {
				$loader.css('opacity', opacity);
			}
		},

		async handlePolledJobStatus(status, payload, $loader) {
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
						message: String(payload.message || '').trim(),
						meta: payload.meta && typeof payload.meta === 'object' ? payload.meta : {}
					}
				}, $loader);
				return false;
			}

			return null;
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
					const entry = await this.fetchRecoveredConversationEntry(normalizedConversationId, targetCreatedAt);
					if (!entry) {
						continue;
					}

					this.applyRecoveredConversationEntry(entry, normalizedConversationId);
					return true;
				} catch (error) {
					// eslint-disable-next-line no-console
					console.debug('Recovered response poll failed.', error);
				}
			}

			return false;
		},

		async tryRestoreLatestCompletedResponse(conversationId, userCreatedAt) {
			const normalizedConversationId = String(conversationId || '').trim();
			const targetCreatedAt = this.normalizeEpochSeconds(userCreatedAt);
			if (!normalizedConversationId || !targetCreatedAt) {
				return false;
			}

			try {
				const entry = await this.fetchRecoveredConversationEntry(normalizedConversationId, targetCreatedAt);
				if (!entry) {
					return false;
				}

				this.applyRecoveredConversationEntry(entry, normalizedConversationId);
				return true;
			} catch (error) {
				// eslint-disable-next-line no-console
				console.debug('Checking for completed response before retry failed.', error);
				return false;
			}
		},

		async fetchRecoveredConversationEntry(conversationId, targetCreatedAt) {
			const response = await this.requestFrontendConversation('geweb_get_frontend_conversation', {
				conversation_id: conversationId
			});
			if (!response?.success || !response?.data?.conversation) {
				return null;
			}

			const entry = this.normalizeStoredConversation(response.data.conversation);
			this.conversationArchive = this.conversationArchive.filter((item) => item.id !== entry.id);
			this.conversationArchive.unshift(entry);

			return this.conversationEntryHasRecoveredResponse(entry, targetCreatedAt) ? entry : null;
		},

		conversationEntryHasRecoveredResponse(entry, targetCreatedAt) {
			if (!entry || !Array.isArray(entry.messages)) {
				return false;
			}

			return [...entry.messages].reverse().some((item) => {
				if (item?.role !== 'model') {
					return false;
				}

				const itemCreatedAt = this.normalizeEpochSeconds(item.created_at ?? item.createdAt);
				const itemMeta = item.meta && typeof item.meta === 'object' ? item.meta : {};
				return !!itemCreatedAt && itemCreatedAt >= targetCreatedAt && !itemMeta.error;
			});
		},

		applyRecoveredConversationEntry(entry, conversationId) {
			if (this.conversationId === conversationId) {
				this.applyConversationEntry(entry);
				return;
			}

			this.syncStoredChatState();
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

				if (globalThis.matchMedia?.(`(max-width: ${GewebModal.getWorkspaceAutoCollapseThreshold()}px)`)?.matches) {
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
				probe.remove();
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
					// eslint-disable-next-line no-console
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
				this.sendAiAjaxRequest(requestData, $loader, userEntry.created_at, {
					clearSearchNonce,
					ensureSearchNonce,
					nonceRetried: false,
				});
				this.toggleTemporarySettings(false);
			},

		sendAiAjaxRequest(requestData, $loader, userCreatedAt, retryState) {
			retryState.requestStartedAtMs = retryState.requestStartedAtMs || Date.now();
			const timeoutSeconds = this.getAjaxTimeoutSecondsForModel(requestData?.model);
			$.ajax({
				url: geweb_aisearch.ajax_url,
				type: 'POST',
				timeout: timeoutSeconds * 1000,
				data: requestData,
				success: (response) => {
					this.handleSendAjaxSuccess(response, requestData, $loader);
				},
				error: (xhr) => {
					this.handleSendAjaxError(xhr, requestData, $loader, userCreatedAt, retryState);
				}
			});
		},

		handleSendAjaxSuccess(response, requestData, $loader) {
			if (response?.success && response?.data?.queued) {
				const resolvedConversationId = String(response.data.conversation_id || requestData.conversation_id || '').trim();
				if (resolvedConversationId) {
					this.conversationId = resolvedConversationId;
					this.syncFrontendPageConversationState();
					this.syncStoredChatState();
				}
				this.pollAiChatJob(String(response.data.job_id || '').trim(), $loader).catch((error) => console.debug('Polling AI chat job failed.', error)); // eslint-disable-line no-console
				return;
			}

			this.handleResponse(response, $loader).catch((error) => console.debug('Handling AI response failed.', error)); // eslint-disable-line no-console
		},

		handleSendAjaxError(xhr, requestData, $loader, userCreatedAt, retryState) {
			if (!retryState.nonceRetried && this.isNonceFailureResponse(xhr)) {
				retryState.nonceRetried = true;
				retryState.clearSearchNonce();
				retryState.ensureSearchNonce()
					.then((freshNonce) => {
						requestData.nonce = freshNonce;
						this.sendAiAjaxRequest(requestData, $loader, userCreatedAt, retryState);
					})
					.catch((error) => {
						console.debug('Could not refresh expired AI search nonce.', error); // eslint-disable-line no-console
						this.handleError($loader, xhr, requestData.conversation_id, userCreatedAt, retryState.requestStartedAtMs).catch((err) => console.debug('Handling AI error failed.', err)); // eslint-disable-line no-console
					});
				return;
			}

			this.handleError($loader, xhr, requestData.conversation_id, userCreatedAt, retryState.requestStartedAtMs).catch((error) => console.debug('Handling AI error failed.', error)); // eslint-disable-line no-console
		},

		async handleResponse(response, $loader) {
			const loaderThoughts = this.getLoaderThoughts($loader);
			$loader.remove();
			this.requestInFlight = false;
			if (response?.success && response?.data) {
				await this.handleSuccessfulResponseData(response.data);
			} else {
				await this.appendAiHistoryEntry(this.buildBackendErrorHistoryEntry(response, loaderThoughts));
			}
			this.toggleSubmitButton();
		},

		async handleSuccessfulResponseData(data) {
			this.compactedConversation = !!data.context_compacted;
			this.currentContextSummary = String(data.context_summary || '').trim();
			const answerText = String(data?.answer || '');
			if (answerText.trim() === '') {
				await this.appendAiHistoryEntry(this.buildAiHistoryEntry({
					content: this.normalizeAiErrorMessage(
						t('emptyAiAnswer', 'AI returned a completed response without answer text. Please retry.'),
						t('emptyAiAnswer', 'AI returned a completed response without answer text. Please retry.')
					),
					meta: {
						...(data?.meta && typeof data.meta === 'object' ? data.meta : {}),
						error: true,
						error_type: 'empty_success',
						raw_message: 'Completed AI response did not contain answer text.',
					}
				}));
				return;
			}
			const aiEntry = this.buildAiHistoryEntry({
				content: answerText,
				sources: Array.isArray(data.sources) ? data.sources : [],
				meta: data?.meta && typeof data.meta === 'object' ? data.meta : {}
			});
			const responseSummary = this.extractResponseSummary(data);
			if (responseSummary) {
				this.appendContextSummaryNote(responseSummary);
			}
			await this.appendAiHistoryEntry(aiEntry);
		},

		extractResponseSummary(data) {
			const responseRequestContext = data?.meta?.request && typeof data.meta.request === 'object'
				? data.meta.request
				: {};
			return String(responseRequestContext.context_summary || data.context_summary || '').trim();
		},

		buildBackendErrorHistoryEntry(response, loaderThoughts = []) {
			const backendMessage = response?.data?.message ? String(response.data.message) : '';
			const backendMeta = response?.data?.meta && typeof response.data.meta === 'object' ? response.data.meta : {};
			const thoughts = this.mergeThoughtLists(backendMeta.thoughts, loaderThoughts);
			return this.buildAiHistoryEntry({
				content: this.normalizeAiErrorMessage(
					backendMessage,
					t('answerError', 'Error: Unable to get response'),
					{ elapsed_seconds: this.resolveElapsedSeconds(backendMeta) }
				),
				meta: {
					...backendMeta,
					...(thoughts.length ? { thoughts } : {}),
					error: true,
					error_type: 'backend',
					raw_message: backendMessage,
					model: backendMeta.model || backendMeta?.request?.model || this.getSelectedModel(),
					request: {
						...backendMeta.request,
						finished_at: backendMeta?.request?.finished_at || this.normalizeEpochSeconds(Math.floor(Date.now() / 1000)),
					},
				}
			});
		},

		async handleError($loader, xhr, conversationId = '', userCreatedAt = null, requestStartedAtMs = null) {
			const loaderThoughts = this.getLoaderThoughts($loader);
			$loader.remove();
			this.requestInFlight = false;
			const ajaxErrorMessage = this.getAjaxErrorMessage(xhr, t('connectionError', 'Connection error. Please try again.'));
			const elapsedSeconds = this.resolveElapsedSeconds({}, requestStartedAtMs, userCreatedAt);
			const shouldRecover = this.shouldAttemptResponseRecovery(xhr) && String(conversationId || '').trim() !== '';
			await this.appendAiHistoryEntry(this.buildAiHistoryEntry({
				content: shouldRecover
					? this.buildTransportRecoveryMessage(ajaxErrorMessage, elapsedSeconds)
					: this.normalizeAiErrorMessage(
						ajaxErrorMessage,
						t('connectionError', 'Connection error. Please try again.'),
						{ elapsed_seconds: elapsedSeconds }
					),
				meta: {
					error: true,
					error_type: shouldRecover ? 'transport_recoverable' : 'transport',
					pending_recovery: shouldRecover,
					raw_message: ajaxErrorMessage,
					http_status: Number(xhr?.status || 0) || null,
					model: this.getSelectedModel(),
					...(elapsedSeconds > 0 ? { elapsed_seconds: elapsedSeconds } : {}),
					...(loaderThoughts.length ? { thoughts: loaderThoughts } : {}),
					request: {
						created_at: userCreatedAt || this.normalizeEpochSeconds(Math.floor(Date.now() / 1000)),
						finished_at: this.normalizeEpochSeconds(Math.floor(Date.now() / 1000)),
						model: this.getSelectedModel(),
					},
				}
			}));
			this.syncHistoryButtons();
			this.toggleSubmitButton();

			if (shouldRecover) {
				this.pollForRecoveredResponse(conversationId, userCreatedAt).catch((error) => console.debug('Polling for recovered response failed.', error)); // eslint-disable-line no-console
			}
		},

		getLoaderThoughts($loader) {
			const thoughts = $loader?.data('gewebThoughts');
			return Array.isArray(thoughts)
				? thoughts.map((item) => String(item || '').trim()).filter(Boolean)
				: [];
		},

		getElapsedSeconds(requestStartedAtMs, createdAtSeconds = null) {
			const startedAtMs = Number(requestStartedAtMs) || 0;
			if (startedAtMs > 0) {
				return Math.max(1, Math.round((Date.now() - startedAtMs) / 1000));
			}

			const createdAt = this.normalizeEpochSeconds(createdAtSeconds);
			if (createdAt > 0) {
				return Math.max(1, Math.round(Date.now() / 1000 - createdAt));
			}

			return 0;
		},

		resolveElapsedSeconds(meta = {}, requestStartedAtMs = null, createdAtSeconds = null) {
			const normalizedMeta = meta && typeof meta === 'object' ? meta : {};
			const directElapsedSeconds = Math.max(0, Number(normalizedMeta.elapsed_seconds) || 0);
			if (directElapsedSeconds > 0) {
				return directElapsedSeconds;
			}

			const requestMeta = normalizedMeta.request && typeof normalizedMeta.request === 'object'
				? normalizedMeta.request
				: {};
			const requestElapsedSeconds = Math.max(0, Number(requestMeta.elapsed_seconds) || 0);
			if (requestElapsedSeconds > 0) {
				return requestElapsedSeconds;
			}

			const requestCreatedAt = this.normalizeEpochSeconds(requestMeta.created_at || createdAtSeconds);
			const requestFinishedAt = this.normalizeEpochSeconds(requestMeta.finished_at);
			if (requestCreatedAt > 0 && requestFinishedAt >= requestCreatedAt) {
				return Math.max(1, requestFinishedAt - requestCreatedAt);
			}

			return this.getElapsedSeconds(requestStartedAtMs, requestCreatedAt || createdAtSeconds);
		},

		mergeThoughtLists(...thoughtLists) {
			const merged = [];
			thoughtLists.forEach((thoughtList) => {
				if (!Array.isArray(thoughtList)) {
					return;
				}

				thoughtList.forEach((thought) => {
					const text = String(thought || '').trim();
					if (text !== '' && !merged.includes(text)) {
						merged.push(text);
					}
				});
			});
			return merged;
		},

		appendMessage(text, type, options = {}) {
			if (type === 'user') {
				this.appendUserMessage(text, options);
			} else {
				this.appendAiMessage(text, options);
			}

			this.scrollToBottom();
		},

		appendUserMessage(text, options = {}) {
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
			const $meta = $('<div class="geweb-ai-message-meta geweb-ai-message-meta--user"></div>');
			const $metaActions = $('<div class="geweb-ai-message-meta-actions geweb-ai-message-meta-actions--user"></div>');
			if (historyIndex >= 0) {
				$msg.attr('data-history-index', String(historyIndex));
			}
			$msg.append($('<span class="geweb-ai-user-message-text"></span>').html(this.escapeHtml(questionText)));
			if (historyIndex >= 0) {
				const $removeButton = $('<button type="button" class="geweb-ai-user-message-remove" aria-label="Remove question and answer" title="Remove question and answer"><span aria-hidden="true">−</span></button>');
				$removeButton.on('click', async (event) => {
					event.preventDefault();
					event.stopPropagation();
					await this.removeConversationTurn(historyIndex);
				});
				$metaActions.append($removeButton);
			}
			const userTooltip = this.buildMessageTooltip({
				type: 'user',
				content: questionText,
				messageData,
				responseMeta,
				includeReuseHint: true,
			});
				if (GewebModal.shouldShowQuestionInfoButton()) {
					const $userInfoButton = this.buildMessageInfoButton(userTooltip, {
						extraClass: 'geweb-ai-question-info-toggle',
						showActionLabel: false,
					});
					if ($userInfoButton) {
						$metaActions.append($userInfoButton);
					}
				}
			if ($metaActions.children().length) {
				$meta.append($metaActions);
			}
			const $userTimestamp = this.buildMessageTimestampElement(
				messageData?.created_at ?? messageData?.createdAt,
				'geweb-ai-message-timestamp--user'
			);
			if ($userTimestamp) {
				$meta.append($userTimestamp);
			}
			if ($meta.children().length) {
				$msg.append($meta);
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
		},

		appendAiMessage(text, options = {}) {
			const $container = $('<div class="ai-message"></div>');
			const responseMeta = text?.meta && typeof text.meta === 'object' ? text.meta : {};
			const aiMessageData = options?.messageData && typeof options.messageData === 'object' ? options.messageData : {};
			const sourceFootnoteMap = this.getResponseSourceFootnoteMap(text.sources || [], text.answer || '', responseMeta);
			const rawAnswer = String(text.answer || '');
			const answerHtml = responseMeta.error && rawAnswer.includes('"error"') && rawAnswer.includes('HTTP code')
				? this.normalizeAiErrorMessage(this.extractPlainTextFromHtml(rawAnswer), t('answerError', 'Error: Unable to get response'))
				: rawAnswer;
			const answerWithFootnotes = this.decorateAnswerWithGroundingFootnotes(answerHtml, responseMeta, sourceFootnoteMap);
			const sanitizedAnswer = this.sanitizeAnswer(answerWithFootnotes);
			const $content = $('<div class="geweb-ai-message-content"></div>');
			const $body = $('<div class="geweb-ai-message-body"></div>').html(sanitizedAnswer);
			const $meta = $('<div class="geweb-ai-message-meta geweb-ai-message-meta--ai"></div>');
			const $thoughtProcess = this.buildThoughtProcessBlock(responseMeta);
			const fallbackFootnotes = this.getFallbackFootnoteNumbers(sourceFootnoteMap, text.sources || []);
			if (fallbackFootnotes.length) {
				$body.append($(this.appendFallbackFootnoteGroup('', fallbackFootnotes)));
			}
			const plainText = this.extractPlainTextFromHtml(sanitizedAnswer);
			const $details = typeof this.buildResponseDetails === 'function'
				? this.buildResponseDetails(responseMeta)
				: null;
			const $messageActions = $('<div class="geweb-ai-message-actions"></div>');
			const shouldShowThoughtProcess = !!(responseMeta.error && $thoughtProcess);

			this.appendAiMessagePrimaryAction($messageActions, responseMeta, plainText);
			if ($body.find('.geweb-ai-footnote-ref').length) {
				this.bindFootnoteInteractions($body);
			}
			this.appendAiThoughtsToggle($messageActions, $container, $thoughtProcess, shouldShowThoughtProcess);
			this.appendAiDetailsToggle($messageActions, $container, $details);

			if ($messageActions.children().length) {
				$meta.append($messageActions);
			}
			const $aiTimestamp = this.buildMessageTimestampElement(
				aiMessageData?.created_at ?? aiMessageData?.createdAt ?? text?.created_at ?? text?.createdAt ?? responseMeta?.request?.finished_at ?? responseMeta?.request?.created_at,
				'geweb-ai-message-timestamp--ai'
			);
			if ($aiTimestamp) {
				$meta.append($aiTimestamp);
			}
			$content.append($body);
			if ($meta.children().length) {
				$content.append($meta);
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
		},

		appendAiMessagePrimaryAction($messageActions, responseMeta, plainText) {
			if (responseMeta.error) {
				const $retryButton = $('<button type="button" class="geweb-ai-retry-answer" aria-live="polite"></button>');
				$retryButton.attr('aria-label', t('retryRequest', 'Retry request'));
				$retryButton.append($('<span class="geweb-ai-message-action-icon" aria-hidden="true" style="font-size:15px; line-height:1;">↻</span>'));
				$retryButton.append($('<span class="geweb-ai-message-action-label"></span>').text(t('retryRequest', 'Retry')));
				$retryButton.on('click', async (event) => {
					event.preventDefault();
					event.stopPropagation();
					await this.retryLastQuestion();
				});
				$messageActions.append($retryButton);
				return;
			}

			const $copyButton = this.buildCopyButton(plainText);
			if ($copyButton) {
				$messageActions.append($copyButton);
			}
		},

		appendAiThoughtsToggle($messageActions, $container, $thoughtProcess, initiallyExpanded = false) {
			if (!$thoughtProcess) {
				return;
			}

			if (initiallyExpanded) {
				$thoughtProcess.prop('hidden', false).addClass('is-open');
			}

			const $thoughtsButton = $('<button type="button" class="geweb-ai-icon-button geweb-ai-message-thoughts-toggle"></button>');
			$thoughtsButton.attr('aria-expanded', initiallyExpanded ? 'true' : 'false');
			$thoughtsButton.attr('aria-label', initiallyExpanded ? t('hideThoughtProcess', 'Hide thought process') : t('showThoughtProcess', 'Show thought process'));
			$thoughtsButton.append($('<span class="geweb-ai-message-action-icon geweb-ai-message-action-icon--thoughts" aria-hidden="true">✦</span>'));
			$thoughtsButton.append($('<span class="geweb-ai-message-action-label"></span>').text(initiallyExpanded ? t('hideThoughtProcess', 'Hide thought process') : t('showThoughtProcess', 'Show thought process')));
			$thoughtsButton.on('click', () => {
				this.toggleThoughtProcess($container);
				const expanded = $thoughtProcess.hasClass('is-open');
				$thoughtsButton.attr('aria-expanded', expanded ? 'true' : 'false');
				$thoughtsButton.attr('aria-label', expanded ? t('hideThoughtProcess', 'Hide thought process') : t('showThoughtProcess', 'Show thought process'));
				$thoughtsButton.find('.geweb-ai-message-action-label').text(expanded ? t('hideThoughtProcess', 'Hide thought process') : t('showThoughtProcess', 'Show thought process'));
			});
			$messageActions.append($thoughtsButton);
		},

		appendAiDetailsToggle($messageActions, $container, $details) {
			if (!$details) {
				return;
			}

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
			$details.find('.geweb-ai-response-details-close').on('click', (event) => {
				event.preventDefault();
				event.stopPropagation();
				this.toggleResponseDetails($container);
				$detailsButton.attr('aria-expanded', 'false');
				$detailsButton.attr('aria-label', t('showDetails', 'Show details'));
				$detailsButton.find('.geweb-ai-message-action-label').text(t('showDetails', 'Show details'));
			});
			$messageActions.append($detailsButton);
		},

		toggleTemporarySettingsInfo(event) {
			event.preventDefault();
			event.stopPropagation();
			const text = String(this.$settingsInfoBtn.attr('data-tooltip') || '').trim();
			if (!text) {
				return;
			}

			this.toggleMessageInfoPopover(this.$settingsInfoBtn, text);
		},

		ensureMessageInfoPopover() {
			if (this.$messageInfoPopover?.length) {
				return this.$messageInfoPopover;
			}

			this.$messageInfoPopover = $('<div class="geweb-ai-message-info-popover" role="dialog" aria-hidden="true" hidden></div>');
			const $header = $('<div class="geweb-ai-message-info-popover-header"></div>');
			const $close = $('<button type="button" class="geweb-ai-message-info-popover-close" aria-label="Close info" title="Close info"><span aria-hidden="true">×</span></button>');
			$close.on('click', (event) => {
				event.preventDefault();
				event.stopPropagation();
				this.hideMessageInfoPopover();
			});
			$header.append($close);
			this.$messageInfoPopoverContent = $('<div class="geweb-ai-message-info-popover-content"></div>');
			this.$messageInfoPopover.append($header, this.$messageInfoPopoverContent);
			$('body').append(this.$messageInfoPopover);
			return this.$messageInfoPopover;
		},

		positionMessageInfoPopover($anchor) {
			if (!this.$messageInfoPopover?.length || !$anchor?.length) {
				return;
			}

			const anchorRect = $anchor.get(0).getBoundingClientRect();
			const popover = this.$messageInfoPopover.get(0);
			const viewportWidth = globalThis.innerWidth || document.documentElement.clientWidth || 0;
			const viewportHeight = globalThis.innerHeight || document.documentElement.clientHeight || 0;
			const isMobilePopover = globalThis.matchMedia?.('(max-width: 767px) and (hover: none) and (pointer: coarse)')?.matches;
			const margin = 8;
			const spacing = 10;

			this.$messageInfoPopover.toggleClass('is-mobile', !!isMobilePopover);
			if (isMobilePopover) {
				this.$messageInfoPopover.css({
					left: `${margin}px`,
					right: `${margin}px`,
					top: 'auto',
					bottom: `calc(env(safe-area-inset-bottom, 0px) + ${margin}px)`,
				});
				return;
			}

			let left = anchorRect.right - popover.offsetWidth;
			left = Math.max(margin, Math.min(left, viewportWidth - popover.offsetWidth - margin));

			let top = anchorRect.top - popover.offsetHeight - spacing;
			if (top < margin) {
				top = anchorRect.bottom + spacing;
			}
			top = Math.max(margin, Math.min(top, viewportHeight - popover.offsetHeight - margin));

			this.$messageInfoPopover.css({ left: `${left}px`, right: 'auto', top: `${top}px`, bottom: 'auto' });
		},

		showMessageInfoPopover($anchor, text) {
			const content = String(text || '').trim();
			if (!content || !$anchor?.length) {
				return;
			}

			const $popover = this.ensureMessageInfoPopover();
			this.$messageInfoPopoverContent.text(content);
			$popover.prop('hidden', false).attr('aria-hidden', 'false').addClass('is-open');
			this.$messageInfoPopoverAnchor = $anchor;
			this.positionMessageInfoPopover($anchor);
		},

		hideMessageInfoPopover() {
			if (!this.$messageInfoPopover?.length) {
				return;
			}

			this.$messageInfoPopover.removeClass('is-open').attr('aria-hidden', 'true').prop('hidden', true);
			this.$messageInfoPopoverAnchor = null;
		},

		toggleMessageInfoPopover($anchor, text) {
			const isOpen = !!(this.$messageInfoPopover?.hasClass('is-open'));
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
				return new Intl.DateTimeFormat(undefined, {
					year: 'numeric',
					month: 'short',
					day: '2-digit',
					hour: '2-digit',
					minute: '2-digit',
					second: '2-digit',
					hour12: false,
				}).format(new Date(normalized * 1000));
			} catch (_) { // NOSONAR
				return '';
			}
		},

		formatMessageTimestamp(epochSeconds) {
			const parts = this.formatMessageTimestampParts(epochSeconds);
			if (!parts) {
				return '';
			}

			return `${parts.day} ${parts.time}`;
		},

		formatMessageTimestampParts(epochSeconds) {
			const normalized = this.normalizeEpochSeconds(epochSeconds);
			if (!normalized) {
				return null;
			}

			try {
				const date = new Date(normalized * 1000);
				const year = date.getFullYear();
				const month = String(date.getMonth() + 1).padStart(2, '0');
				const day = String(date.getDate()).padStart(2, '0');
				return {
					day: `${year}-${month}-${day}`,
					time: new Intl.DateTimeFormat(undefined, {
						hour: '2-digit',
						minute: '2-digit',
						second: '2-digit',
						hour12: false,
					}).format(date),
				};
			} catch (_) { // NOSONAR
				return null;
			}
		},

		buildMessageTimestampElement(epochSeconds, extraClass = '') {
			const parts = this.formatMessageTimestampParts(epochSeconds);
			if (!parts) {
				return null;
			}

			const className = ['geweb-ai-message-timestamp', extraClass].filter(Boolean).join(' ');
			const $timestamp = $(`<div class="${className}"></div>`);
			$timestamp.append($('<span class="geweb-ai-message-timestamp-day"></span>').text(parts.day));
			$timestamp.append($('<span class="geweb-ai-message-timestamp-time"></span>').text(parts.time));
			return $timestamp;
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

			this.appendTooltipResponseMeta(lines, type, responseMeta);

			if (includeReuseHint) {
				lines.push('Click on the question text to reuse this question.');
			}

			return lines.filter(Boolean).join('\n');
		},

		appendTooltipResponseMeta(lines, type, responseMeta) {
			const isAiTooltip = String(type || '').toLowerCase() === 'ai';
			const hasResponseMeta = responseMeta && typeof responseMeta === 'object' && Object.keys(responseMeta).length > 0;
			if (!isAiTooltip && !hasResponseMeta) {
				return;
			}

			const usage = responseMeta?.usage && typeof responseMeta.usage === 'object' ? responseMeta.usage : {};
			this.appendTooltipModelAndTime(lines, isAiTooltip, responseMeta);
			this.appendTooltipAttempts(lines, isAiTooltip, responseMeta);
			this.appendTooltipUsage(lines, isAiTooltip, usage);
			this.appendTooltipCost(lines, isAiTooltip, responseMeta?.estimated_cost_usd);
		},

		appendTooltipModelAndTime(lines, isAiTooltip, responseMeta) {
			const model = String(responseMeta?.model_version || responseMeta?.model || '').trim();
			if (model) {
				lines.push(isAiTooltip ? `Model: ${model}` : `Answer model: ${model}`);
			}

			const requestFinishedAt = this.formatTooltipTimestamp(responseMeta?.request?.finished_at);
			if (requestFinishedAt) {
				lines.push(isAiTooltip ? `Returned: ${requestFinishedAt}` : `Answer returned: ${requestFinishedAt}`);
			}
		},

		appendTooltipAttempts(lines, isAiTooltip, responseMeta) {
			const attempts = Array.isArray(responseMeta?.request_attempts) ? responseMeta.request_attempts : [];
			if (!attempts.length) {
				return;
			}

			lines.push(`${isAiTooltip ? 'Request' : 'Answer request'} attempts: ${attempts.length}`);
			attempts.slice(-3).forEach((attempt) => {
				const startedAt = this.formatTooltipTimestamp(attempt?.started_at);
				const status = String(attempt?.status || '').replaceAll('_', ' ').trim();
				const httpCode = Number(attempt?.http_code || 0);
				const elapsedMs = Number(attempt?.elapsed_ms || 0);
				const triplet = String(attempt?.retry_triplet || attempt?.attempt || '').trim();
				const httpCodeLabel = httpCode ? `HTTP ${httpCode}` : '';
				const elapsedLabel = elapsedMs ? `${elapsedMs} ms` : '';
				lines.push(`Attempt ${triplet}: ${[startedAt, httpCodeLabel, status, elapsedLabel].filter(Boolean).join(' · ')}`);
			});
		},

		appendTooltipUsage(lines, isAiTooltip, usage) {
			[
				['total_tokens', 'Tokens'],
				['input_tokens', 'Input tokens'],
				['output_tokens', 'Output tokens'],
				['thought_tokens', 'Thought tokens'],
			].forEach(([key, label]) => {
				if (usage[key]) {
					lines.push(isAiTooltip ? `${label}: ${usage[key]}` : `Answer ${label.toLowerCase()}: ${usage[key]}`);
				}
			});
		},

		appendTooltipCost(lines, isAiTooltip, estimatedCostUsd) {
			if (estimatedCostUsd === undefined || estimatedCostUsd === null) {
				return;
			}

			const cost = Number(estimatedCostUsd);
			if (Number.isFinite(cost)) {
				lines.push(isAiTooltip ? `Estimated cost: $${cost.toFixed(6)}` : `Answer estimated cost: $${cost.toFixed(6)}`);
			}
		},

		modelSupportsThoughtProcess(model) {
			const normalizedModel = String(model || '').trim().toLowerCase();
			return normalizedModel.startsWith('gemini-3');
		},

		renderLoaderState($loader, options = {}) {
			if (!$loader?.length) {
				return;
			}

			const supportsThoughtProcess = options?.supportsThoughtProcess === true;
			const label = String(options?.label || '').trim() || t('thinking', 'Thinking...');
			const thoughts = Array.isArray(options?.thoughts)
				? options.thoughts.map((item) => String(item || '').trim()).filter(Boolean)
				: [];
			const isExpanded = $loader.data('gewebThoughtsExpanded') === true;
			const thoughtSections = this.getThoughtProcessSections(thoughts);
			const hasExpandableThoughtDetails = thoughtSections.some((section) => String(section?.body || '').trim() !== '');

			$loader.data('gewebThoughts', thoughts);
			$loader.empty();
			$loader.toggleClass('geweb-ai-thinking-indicator--thoughts', supportsThoughtProcess);
			$loader.append($('<div class="geweb-ai-thinking-indicator-label"></div>').text(label));

			if (supportsThoughtProcess && thoughtSections.length) {
				const $thoughtList = $('<div class="geweb-ai-thinking-preview"></div>');
				thoughtSections.forEach((section) => {
					$thoughtList.append(this.buildThoughtPreviewLine(section, isExpanded));
				});
				$loader.append($thoughtList);

				if (hasExpandableThoughtDetails) {
					const buttonLabel = isExpanded
						? t('showLessThoughts', 'Less')
						: t('showMoreThoughts', 'More');
					const $toggle = $('<button type="button" class="geweb-ai-thinking-preview-toggle"></button>').text(buttonLabel);
					$toggle.on('click', (event) => {
						event.preventDefault();
						event.stopPropagation();
						$loader.data('gewebThoughtsExpanded', !isExpanded);
						this.renderLoaderState($loader, {
							supportsThoughtProcess: supportsThoughtProcess,
							label: label,
							thoughts: thoughts,
						});
					});
					$loader.append($toggle);
				}
			}
		},

		updateLoaderFromJobProgress($loader, progress) {
			if (!$loader?.length || !progress || typeof progress !== 'object') {
				return;
			}

			const shouldStickToBottom = this.isAnswerBoxNearBottom(96);
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

			if (shouldStickToBottom) {
				this.scrollToBottom();
			}
		},

		getThoughtProcessItems(responseMeta) {
			if (!responseMeta || typeof responseMeta !== 'object' || !Array.isArray(responseMeta.thoughts)) {
				return [];
			}

			return responseMeta.thoughts
				.map((item) => String(item || '').trim())
				.filter(Boolean);
		},

		getThoughtProcessSections(thoughts) {
			const normalizedThoughts = Array.isArray(thoughts)
				? thoughts.map((item) => String(item || '').trim()).filter(Boolean)
				: [];
			if (!normalizedThoughts.length) {
				return [];
			}

			return normalizedThoughts.flatMap((item) => this.splitThoughtProcessSections(item));
		},

		buildThoughtPreviewLine(section, isExpanded) {
			const title = String(section?.title || '').trim();
			const body = String(section?.body || '').trim();
			const $line = $('<div class="geweb-ai-thinking-preview-line"></div>');
			if (!title) {
				$line.text(body);
				return $line;
			}

			$line.append($('<span class="geweb-ai-thinking-preview-heading"></span>').text(title));
			if (isExpanded && body) {
				$line.append(document.createTextNode(` ${body}`));
			}
			return $line;
		},

		renderThoughtProcessHtml(thoughts) {
			return this.renderThoughtProcessSectionsHtml(this.getThoughtProcessSections(thoughts), false);
		},

		renderThoughtProcessSectionsHtml(sections, isExpanded) {
			const normalizedSections = Array.isArray(sections)
				? sections.filter((section) => section && (String(section.title || '').trim() || String(section.body || '').trim()))
				: [];
			if (!normalizedSections.length) {
				return '';
			}

			return normalizedSections.map((section, index) => {
				const prevTitle = index > 0 ? String(normalizedSections[index - 1]?.title || '').trim() : '';
				return this.renderThoughtProcessSection(section, isExpanded, prevTitle);
			}).join('');
		},

		splitThoughtProcessSections(text) {
			const normalizedText = String(text || '').trim();
			if (!normalizedText) {
				return [];
			}

			const markdownSection = this.extractMarkdownThoughtSection(normalizedText);
			if (markdownSection) {
				return [markdownSection];
			}

			const matches = [];
			const sectionRegex = createThoughtSectionRegex();
			let sectionMatch = sectionRegex.exec(normalizedText);
			while (sectionMatch) {
				matches.push(sectionMatch);
				sectionMatch = sectionRegex.exec(normalizedText);
			}
			if (matches.length) {
				return matches.map((match) => ({
					title: String(match[1] || '').trim(),
					body: String(match[2] || '').trim(),
				})).filter((section) => section.title || section.body);
			}

			const inlineHeading = this.extractInlineThoughtHeading(normalizedText);
			if (inlineHeading) {
				return [inlineHeading];
			}

			return [{ title: '', body: normalizedText }];
		},

		extractMarkdownThoughtSection(text) {
			const normalizedText = String(text || '').trim();
			if (!normalizedText) {
				return null;
			}

			const match = THOUGHT_MARKDOWN_SECTION_REGEX.exec(normalizedText);
			if (!match) {
				return null;
			}

			const title = String(match[1] || '').trim();
			const body = String(match[2] || '').trim();
			if (!title || !body) {
				return null;
			}

			return { title, body };
		},

		extractInlineThoughtHeading(text) {
			const normalizedText = String(text || '').trim();
			if (!normalizedText) {
				return null;
			}

			const match = THOUGHT_INLINE_HEADING_REGEX.exec(normalizedText);
			if (!match) {
				return null;
			}

			const title = String(match[1] || '').trim();
			const body = String(match[2] || '').trim();
			if (!title || !body) {
				return null;
			}

			return { title, body };
		},

		renderThoughtProcessSection(section, isExpanded = false, prevTitle = '') {
			const title = String(section?.title || '').trim();
			const body = String(section?.body || '').trim();
			if (!title) {
				const bodyHtml = this.renderThoughtProcessMarkdown(body);
				return `<div class="geweb-ai-thought-process-entry"><div class="geweb-ai-thought-process-entry-body">${bodyHtml}</div></div>`;
			}

			const sameAsPrev = prevTitle !== '' && title === prevTitle;

			// Collapsed view: skip repeat entries entirely — nothing useful to show without body.
			if (!isExpanded || body === '') {
				if (sameAsPrev) {
					return '';
				}
				return [
					'<div class="geweb-ai-thought-process-entry geweb-ai-thought-process-entry--inline">',
					`<span class="geweb-ai-thought-process-entry-heading">${this.escapeHtml(title)}</span>`,
					'</div>',
				].join('');
			}

			const entryClass = sameAsPrev
				? 'geweb-ai-thought-process-entry geweb-ai-thought-process-entry--inline geweb-ai-thought-process-entry--repeat'
				: 'geweb-ai-thought-process-entry geweb-ai-thought-process-entry--inline';
			const headingHtml = sameAsPrev
				? '<span class="geweb-ai-thought-process-entry-heading geweb-ai-thought-process-entry-heading--repeat">···</span>'
				: `<span class="geweb-ai-thought-process-entry-heading">${this.escapeHtml(title)}</span>`;

			return [
				`<div class="${entryClass}">`,
				headingHtml,
				` <span class="geweb-ai-thought-process-entry-detail">${this.renderThoughtProcessInlineHtml(body)}</span>`,
				'</div>',
			].join('');
		},

		renderThoughtProcessInlineHtml(text) {
			const normalized = String(text || '').trim();
			if (!normalized) {
				return '';
			}

			return this.escapeHtml(normalized).replaceAll('\n', '<br>');
		},

		renderThoughtProcessMarkdown(text) {
			const markdown = String(text || '').trim();
			if (!markdown) {
				return '';
			}

			if (globalThis.GewebAisearchMarkdown && typeof globalThis.GewebAisearchMarkdown.render === 'function') {
				return String(globalThis.GewebAisearchMarkdown.render(markdown) || '').trim();
			}

			return '<p>' + this.escapeHtml(markdown).replaceAll('\n', '<br>') + '</p>';
		},

		buildThoughtProcessBlock(responseMeta) {
			const thoughts = this.getThoughtProcessItems(responseMeta);
			if (!thoughts.length) {
				return null;
			}

			const sections = this.getThoughtProcessSections(thoughts);
			const hasExpandableThoughtDetails = sections.some((section) => String(section?.body || '').trim() !== '');
			const $block = $('<section class="geweb-ai-thought-process" aria-live="polite" hidden></section>');
			const $body = $('<div class="geweb-ai-thought-process-body"></div>').html(
				this.renderThoughtProcessSectionsHtml(sections, false)
			);
			$block.append($('<div class="geweb-ai-thought-process-title"></div>').text(t('thoughtProcess', 'Thought process')));
			$block.append($body);
			if (hasExpandableThoughtDetails) {
				const $toggle = $('<button type="button" class="geweb-ai-thought-process-toggle"></button>').text(t('showMoreThoughts', 'More'));
				$toggle.on('click', () => {
					const isExpanded = $block.data('gewebThoughtsExpanded') === true;
					const nextExpanded = !isExpanded;
					$block.data('gewebThoughtsExpanded', nextExpanded);
					$body.html(this.renderThoughtProcessSectionsHtml(sections, nextExpanded));
					$toggle.text(nextExpanded ? t('showLessThoughts', 'Less') : t('showMoreThoughts', 'More'));
				});
				$block.append($toggle);
			}
			return $block;
		},

		toggleThoughtProcess($container) {
			const $thoughtProcess = $container?.find('.geweb-ai-thought-process').first();
			if (!$thoughtProcess?.length) {
				return;
			}

			const isOpen = $thoughtProcess.hasClass('is-open');
			$thoughtProcess.toggleClass('is-open', !isOpen);
			$thoughtProcess.prop('hidden', isOpen);
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
					if (this.shouldUseTapPreviewForFootnotes()) {
						return;
					}

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
					// eslint-disable-next-line no-console
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
				// eslint-disable-next-line no-console
				console.debug('Preparing clipboard fallback failed.', error);
			}

			textarea.remove();
			return false;
		},

		closeOpenResponseDetails($exceptMessage = null) {
			const exceptElement = $exceptMessage?.get?.(0) || null;
			this.$answerBox.find('.ai-message--details-open').each((_, element) => {
				if (exceptElement && element === exceptElement) {
					return;
				}

				const $message = $(element);
				$message.removeClass('ai-message--details-open');
				$message.find('.geweb-ai-response-details').removeClass('is-open');
				$message.find('.geweb-ai-message-content').attr('aria-expanded', 'false');

				const $button = $message.find('.geweb-ai-message-details-toggle').first();
				if ($button.length) {
					$button.attr('aria-expanded', 'false');
					$button.attr('aria-label', t('showDetails', 'Show details'));
					$button.find('.geweb-ai-message-action-label').text(t('showDetails', 'Show details'));
				}
			});
		},

		toggleResponseDetails($message) {
			const $details = $message.find('.geweb-ai-response-details');
			const $content = $message.find('.geweb-ai-message-content');
			if (!$details.length) {
				return;
			}

			const shouldShow = !$details.hasClass('is-open');
			if (shouldShow) {
				this.closeOpenResponseDetails($message);
			}

			$details.toggleClass('is-open', shouldShow);
			$message.toggleClass('ai-message--details-open', shouldShow);
			$content.attr('aria-expanded', shouldShow ? 'true' : 'false');
		},

		scrollToBottom() {
			this.$answerBox[0].scrollTop = this.$answerBox[0].scrollHeight;
			this.answerBoxWasNearBottom = true;
		},

		isAnswerBoxNearBottom(threshold = 64) {
			const element = this.$answerBox?.get(0);
			if (!element) {
				return false;
			}

			const remaining = element.scrollHeight - (element.scrollTop + element.clientHeight);
			return remaining <= Math.max(0, Number(threshold) || 0);
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
				// eslint-disable-next-line no-console
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
				// eslint-disable-next-line no-console
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

		formatAiErrorDisplayMessage(message) {
			const decoded = this.decodeHtmlEntities(String(message || ''))
				.replaceAll(/\r\n?/g, '\n')
				.trim();
			if (!decoded) {
				return '';
			}

			const structuredMatch = decoded.match(/^(.*?HTTP code\s+\d+\s*:)\s*(\{[\s\S]*\})$/i);
			if (!structuredMatch) {
				return decoded;
			}

			const summary = String(structuredMatch[1] || '').trim();
			const jsonText = String(structuredMatch[2] || '').trim();

			try {
				const parsed = JSON.parse(jsonText);
				const error = parsed?.error && typeof parsed.error === 'object' ? parsed.error : {};
				const status = String(error.status || '').trim();
				const remoteMessage = String(error.message || '')
					.replaceAll(/https?:\/\/\S+/g, '')
					.replaceAll(/\s+/g, ' ')
					.trim();

				if (/monthly spending cap|spend cap/i.test(remoteMessage)) {
					return `${summary} the Google AI Studio project has exceeded its monthly spending cap. Increase the spend cap or switch to another API key/project, then retry.`;
				}

				if (status === 'RESOURCE_EXHAUSTED') {
					return `${summary} the Gemini API quota or spending limit has been reached for this project.`;
				}

				const statusSuffix = status ? ` (${status})` : '';
				return `${summary} ${remoteMessage || t('answerError', 'Error: Unable to get response')}${statusSuffix}`;
			} catch (_) { // NOSONAR
				return decoded;
			}
		},

			sanitizeAnswer(html) {
					const urlRegex = /(?<!href=["'])(?<!src=["'])(https?:\/\/[^\s<>"']+)/g;
					const normalizedHtml = String(html || '').replaceAll(urlRegex, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>');
			const allowed = new Set(['p', 'br', 'b', 'strong', 'i', 'em', 'ul', 'ol', 'li', 'a', 'h1', 'h2', 'h3', 'span', 'sup', 'div']);
					const div = document.createElement('div');
					div.innerHTML = normalizedHtml;

					div.querySelectorAll('*').forEach((el) => {
							this.sanitizeAnswerElement(el, allowed);
					});

					return div.innerHTML;
			},

		sanitizeAnswerElement(el, allowed) {
			const tagName = el.tagName.toLowerCase();
			if (!allowed.has(tagName)) {
				el.replaceWith(document.createTextNode(el.textContent));
				return;
			}

			Array.from(el.attributes).forEach((attr) => {
				this.sanitizeAnswerAttribute(el, tagName, attr);
			});
			if (tagName === 'a') {
				el.setAttribute('target', '_blank');
				el.setAttribute('rel', 'noopener noreferrer');
			}
		},

		sanitizeAnswerAttribute(el, tagName, attr) {
			if (tagName === 'a' && attr.name === 'href') {
				if (!/^https?:\/\//i.test(attr.value)) {
					el.removeAttribute('href');
				}
				return;
			}

			if (tagName === 'sup' && ['class', 'data-footnote'].includes(attr.name)) {
				return;
			}

			if (tagName === 'span' && attr.name === 'class' && String(attr.value || '').includes('geweb-ai-footnote-group')) {
				return;
			}

			if (this.isAllowedAnswerContainerClass(tagName, attr)) {
				return;
			}

			if (!['target', 'rel'].includes(attr.name)) {
				el.removeAttribute(attr.name);
			}
		},

		isAllowedAnswerContainerClass(tagName, attr) {
			return ['div', 'ul'].includes(tagName)
				&& attr.name === 'class'
				&& String(attr.value || '').split(/\s+/).every((className) => /^geweb-ai-error-card(?:[A-Za-z0-9_-]*)$/.test(className) || className === '');
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
				// eslint-disable-next-line no-console
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
						// eslint-disable-next-line no-console
						console.debug('Saving stored chat state failed even after trimming archive.', retryError);
					}
					return;
				}
				// eslint-disable-next-line no-console
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
						// eslint-disable-next-line no-console
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
						// eslint-disable-next-line no-console
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
				if (!response?.success || !response?.data?.conversation) {
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
				// eslint-disable-next-line no-console
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
				// eslint-disable-next-line no-console
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

	globalThis.GewebAIChat = GewebAIChat;
	globalThis.GewebAIModal = GewebModal;
	GewebModal.init();
	GewebAIChat.init();
	scheduleHighlightFirstPageMatch();
});
