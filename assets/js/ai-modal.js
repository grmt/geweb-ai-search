(function() {
globalThis.createGewebAIModal = function($, t) {
	return {
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
	        } catch {
	            return '';
	        }
	    },

	    openFrontendAiPage(query) {
	        globalThis.location.href = this.buildFrontendAiPageUrl(query, globalThis.GewebAIChat.getTargetConversationIdForQuery(query));
	    },

	    appendAITriggerButton($form, $button, $searchButton) {
	        $form.addClass('geweb-ai-search-form');
	        $form.find('input[name="s"]').first().addClass('geweb-ai-search-input');
	        if ($searchButton.length) {
	            $searchButton.after($button);
	            return;
	        }

	        $form.append($button);
	    },

	    bindFullscreenAiTrigger($button, $input) {
	        $button.on('click', () => {
	            this.openFrontendAiPage(this.resolveQueryText($input.val()));
	        });
	    },

	    bindModalAiTrigger($button, $input) {
	        $button.on('click', () => {
	            this.openAI(this.resolveQueryText($input.val()));
	        });
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
	                this.bindFullscreenAiTrigger($button, $input);
	                this.appendAITriggerButton($form, $button, $searchButton);
	                return;
	            }

	            const buttonClasses = ['geweb-ai-trigger', 'geweb-ai-trigger--text'].join(' ');
	            const searchWithAiLabel = this.escapeHtml(t('searchWithAi', 'AI search'));
	            const $button = $(`<button type="button" class="${buttonClasses}" aria-label="${this.escapeAttr(searchWithAiLabel)}"><span class="geweb-ai-trigger-label">${searchWithAiLabel}</span></button>`);
	            this.matchTriggerButtonSize($button, $searchButton);
	            this.bindModalAiTrigger($button, $input);
	            this.appendAITriggerButton($form, $button, $());
	        });
	    },

	    openAI(query) {
		        const trimmedQuery = (query || '').trim();
		        globalThis.GewebAIChat.prepareChatForQuery(trimmedQuery);
	        $('#geweb-ai-query-display').val(globalThis.GewebAIChat.shouldAutoSubmitQuery(trimmedQuery) ? trimmedQuery : '');
	        globalThis.GewebAIChat.toggleSubmitButton();
	        document.body.classList.add('no-scroll');
	        if (typeof this.ai.showModal === 'function') {
	            this.ai.showModal();
	        }
	        globalThis.GewebAIChat.focusInput();

	        if (globalThis.GewebAIChat.shouldAutoSubmitQuery(trimmedQuery)) {
	            globalThis.setTimeout(() => {
	                globalThis.GewebAIChat.sendMessage(trimmedQuery);
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

	    getValidMobileWorkspacePane(pane) {
	        return ['left', 'main', 'right'].includes(pane) ? pane : 'main';
	    },

	    prepareMobilePaneAnimation(workspace, previousPane, nextPane) {
	        const direction = this.getMobileWorkspacePaneIndex(nextPane) > this.getMobileWorkspacePaneIndex(previousPane)
	            ? 'forward'
	            : 'backward';
	        workspace.dataset.mobilePanePrevious = previousPane;
	        workspace.dataset.mobilePaneAnimating = '1';
	        workspace.dataset.mobilePaneDirection = direction;
	        if (workspace._gewebMobilePaneAnimationTimeout) {
	            globalThis.clearTimeout(workspace._gewebMobilePaneAnimationTimeout);
	        }
	    },

	    finishMobilePaneAnimation(workspace) {
	        workspace._gewebMobilePaneAnimationTimeout = globalThis.setTimeout(() => {
	            delete workspace.dataset.mobilePanePrevious;
	            delete workspace.dataset.mobilePaneAnimating;
	            delete workspace.dataset.mobilePaneDirection;
	        }, 340);
	    },

	    focusMobileWorkspacePane(workspace, pane) {
	        const paneSelector = {
	            left: '.geweb-ai-sidebar',
	            main: '.geweb-ai-main-panel',
	            right: '.geweb-ai-sources-panel',
	        }[pane];
	        const paneElement = paneSelector ? workspace.querySelector(paneSelector) : null;
	        if (paneElement && typeof paneElement.focus === 'function') {
	            paneElement.focus({ preventScroll: true });
	        }
	    },

	    setMobileWorkspacePane(pane, options = {}) {
	        const workspace = this.getWorkspaceElement();
	        const nextPane = this.getValidMobileWorkspacePane(pane);
	        if (!workspace) {
	            return;
	        }

	        const previousPane = this.getValidMobileWorkspacePane(workspace.dataset.mobilePane);
	        const isMobile = this.isMobileWorkspaceNavigationActive(workspace);
	        const shouldAnimate = isMobile && !options?.skipAnimation && previousPane !== nextPane;

	        if (shouldAnimate) {
	            this.prepareMobilePaneAnimation(workspace, previousPane, nextPane);
	        }

	        workspace.dataset.mobilePane = nextPane;
	        this.syncMobilePaneFooter(nextPane);

	        if (shouldAnimate) {
	            this.finishMobilePaneAnimation(workspace);
	        }

	        if (options?.focusPane && this.isMobileWorkspaceNavigationActive(workspace)) {
	            this.focusMobileWorkspacePane(workspace, nextPane);
	        }
	    },

	    moveMobileWorkspacePane(direction) {
	        const workspace = this.getWorkspaceElement();
	        if (!this.isMobileWorkspaceNavigationActive(workspace)) {
	            return;
	        }

	        const currentPane = this.getValidMobileWorkspacePane(workspace.dataset.mobilePane);

	        if (currentPane === 'main') {
	            this.setMobileWorkspacePane(direction > 0 ? 'right' : 'left', { focusPane: true });
	            return;
	        }

	        this.setMobileWorkspacePane('main', { focusPane: true });
	    },

	    syncMobilePaneState(workspace) {
	        if (this.isMobileWorkspaceNavigationActive(workspace)) {
	            this.setMobileWorkspacePane(workspace.dataset.mobilePane || 'main', { skipAnimation: true });
	            return;
	        }

	        delete workspace.dataset.mobilePane;
	    },

	    bindMobileWorkspaceMediaQuery(workspace) {
	        if (typeof globalThis.matchMedia !== 'function') {
	            return;
	        }

	        const mediaQuery = globalThis.matchMedia(`(max-width: ${this.getWorkspaceAutoCollapseThreshold()}px)`);
	        if (typeof mediaQuery.addEventListener === 'function') {
	            mediaQuery.addEventListener('change', () => {
	                this.syncMobilePaneState(workspace);
	            });
	        }
	    },

	    bindMobileWorkspaceNavigation() {
	        const workspace = this.getWorkspaceElement();
	        if (!workspace || workspace.dataset.gewebMobilePaneBound === '1') {
	            return;
	        }

	        workspace.dataset.gewebMobilePaneBound = '1';
	        this.setMobileWorkspacePane(workspace.dataset.mobilePane || 'main', { skipAnimation: true });

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

	        this.bindMobileWorkspaceMediaQuery(workspace);

	        this.syncMobilePaneState(workspace);
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

	            this.bindMobilePaneFooterTab($tab);
	        });

	        this.syncMobilePaneFooter(this.getWorkspaceElement()?.dataset?.mobilePane || 'main');
	    },

	    bindMobilePaneFooterTab($tab) {
	        $tab.data('gewebMobilePaneBound', true);
	        $tab.on('click', (event) => {
	            event.preventDefault();
	            const targetPane = String($tab.data('mobile-pane-target') || 'main');
	            this.setMobileWorkspacePane(targetPane, { focusPane: true });
	            this.syncPanelCollapseButtons();
	        });
	    },

	    syncMobilePaneFooter(activePane = 'main') {
	        const workspace = this.getWorkspaceElement();
	        const isMobile = this.isMobileWorkspaceNavigationActive(workspace);
	        const isSmallScreen = !!globalThis.matchMedia
	            && globalThis.matchMedia(`(max-width: ${this.getMobilePaneFooterThreshold()}px)`).matches;
	        const isKeyboardCompacted = this.isPageViewportKeyboardCompacted();
	        const $footer = $(this.ai).find('.geweb-ai-mobile-pane-footer');
	        const $tabs = $footer.find('.geweb-ai-mobile-pane-tab');
	        const $mobileMenuButton = $(this.ai).find('#geweb-ai-toggle-mobile-menu');
	        const shouldShowFooter = isMobile && isSmallScreen && !isKeyboardCompacted;

	        if (workspace?.style) {
	            workspace.style.setProperty(
	                '--geweb-ai-mobile-pane-footer-space',
	                shouldShowFooter ? 'calc(56px + env(safe-area-inset-bottom, 0px))' : '0px'
	            );
	        }

	        $footer.toggleClass('is-visible', shouldShowFooter);
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

	    storeManualWorkspaceCollapseState(workspace) {
	        workspace.dataset.manualLeftCollapsed = workspace.classList.contains('is-left-collapsed') ? '1' : '0';
	        workspace.dataset.manualRightCollapsed = workspace.classList.contains('is-right-collapsed') ? '1' : '0';
	    },

	    applyMobileResponsivePanelCollapse(workspace) {
	        if (workspace.dataset.autoCollapseMode !== 'mobile') {
	            this.storeManualWorkspaceCollapseState(workspace);
	        }
	        workspace.dataset.autoCollapseActive = '1';
	        workspace.dataset.autoCollapseMode = 'mobile';
	        this.setWorkspacePanelCollapsed(workspace, 'left', false);
	        this.setWorkspacePanelCollapsed(workspace, 'right', false);
	        this.setMobileWorkspacePane(workspace.dataset.mobilePane || 'main');
	        this.syncPanelCollapseButtons();
	    },

	    applyDesktopResponsivePanelCollapse(workspace) {
	        if (workspace.dataset.autoCollapseMode !== 'desktop') {
	            this.storeManualWorkspaceCollapseState(workspace);
	            this.setWorkspacePanelCollapsed(workspace, 'left', true);
	            this.setWorkspacePanelCollapsed(workspace, 'right', true);
	        }

	        workspace.dataset.autoCollapseActive = '1';
	        workspace.dataset.autoCollapseMode = 'desktop';
	        delete workspace.dataset.mobilePane;
	        this.syncPanelCollapseButtons();
	    },

	    restoreManualResponsivePanelCollapse(workspace) {
	        if (workspace.dataset.autoCollapseActive !== '1') {
	            return;
	        }

	        this.setWorkspacePanelCollapsed(workspace, 'left', workspace.dataset.manualLeftCollapsed === '1');
	        this.setWorkspacePanelCollapsed(workspace, 'right', workspace.dataset.manualRightCollapsed === '1');
	        delete workspace.dataset.autoCollapseActive;
	        delete workspace.dataset.autoCollapseMode;
	        delete workspace.dataset.manualLeftCollapsed;
	        delete workspace.dataset.manualRightCollapsed;
	    },

	    applyResponsivePanelCollapse() {
	        const workspace = this.getWorkspaceElement();
	        if (!workspace) {
	            return;
	        }

	        if (this.isMobileWorkspaceNavigationActive(workspace)) {
	            this.applyMobileResponsivePanelCollapse(workspace);
	            return;
	        }

	        if (this.shouldAutoCollapseDesktopPanels(workspace)) {
	            this.applyDesktopResponsivePanelCollapse(workspace);
	            return;
	        }

	        this.restoreManualResponsivePanelCollapse(workspace);
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
	        // iOS: innerHeight is stable; visualViewport.height shrinks when keyboard opens.
	        if ((layoutViewportHeight - visualViewportHeight) >= 120) {
	            return true;
	        }
	        // Android: both innerHeight and visualViewport.height shrink together,
	        // so the difference is ~0. Detect by comparing to the max innerHeight seen
	        // (keyboard can only decrease innerHeight, never increase it).
	        const maxHeight = Number(this._pageViewMaxLayoutHeight || 0);
	        return maxHeight > 0 && (maxHeight - layoutViewportHeight) >= 120;
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
	        } catch {
	            // Ignore unavailable or blocked localStorage reads.
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
	        } catch {
	            // Ignore unavailable or blocked localStorage writes.
	        }
	    },

	    clearStoredPageViewHeight() {
	        if (!globalThis.localStorage) {
	            return;
	        }

	        try {
	            globalThis.localStorage.removeItem(this.getPageViewHeightStorageKey());
	        } catch {
	            // Ignore unavailable or blocked localStorage writes.
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

	    syncPageViewportAfterDelay(delay, shouldAlignBottom) {
	        globalThis.setTimeout(() => {
	            this.syncPageViewportOffsetStyles();
	            this.syncPageViewHeightToViewport();
	            if (shouldAlignBottom) {
	                this.alignPageViewBottomToViewport();
	            }
	        }, Math.max(0, Number(delay) || 0));
	    },

	    schedulePageViewViewportSync(options = {}) {
	        if (!this.isPageView() || !this.ai) {
	            return;
	        }

	        const delays = Array.isArray(options.delays) && options.delays.length
	            ? options.delays
	            : [0, 120, 320];
	        const shouldAlignBottom = options.alignBottom !== false;

	        delays.forEach((delay) => this.syncPageViewportAfterDelay(delay, shouldAlignBottom));
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

	        // Baseline for Android keyboard detection (see isPageViewportKeyboardCompacted).
	        this._pageViewMaxLayoutHeight = Number(globalThis.innerHeight || 0);

	        // On orientation change the layout viewport height changes significantly.
	        // Reset the baseline after the viewport settles so portrait→landscape (or vice
	        // versa) is never mistaken for a keyboard appearing.
	        const applyOrientationChange = () => {
	            const h = Number(globalThis.innerHeight || 0);
	            if (h > 0) {
	                this._pageViewMaxLayoutHeight = h;
	            }
	        };
	        const resetMaxHeightOnOrientationChange = () => {
	            globalThis.setTimeout(applyOrientationChange, 400);
	        };
	        if (globalThis.screen?.orientation) {
	            globalThis.screen.orientation.addEventListener('change', resetMaxHeightOnOrientationChange);
	        } else {
	            globalThis.addEventListener('orientationchange', resetMaxHeightOnOrientationChange);
	        }

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
	            // Update the max only when innerHeight grows (keyboard only shrinks it).
	            const h = Number(globalThis.innerHeight || 0);
	            if (h > (this._pageViewMaxLayoutHeight || 0)) {
	                this._pageViewMaxLayoutHeight = h;
	            }
	            this.syncPageViewportOffsetStyles();
	            this.syncPageViewHeightToViewport();
	            this.syncMobilePaneFooter(this.getWorkspaceElement()?.dataset?.mobilePane || 'main');
	        });

	        if (globalThis.visualViewport) {
	            const syncViewport = () => {
	                this.syncPageViewportOffsetStyles();
	                this.syncPageViewHeightToViewport();
	                this.syncMobilePaneFooter(this.getWorkspaceElement()?.dataset?.mobilePane || 'main');
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

	            const hasTouchPoints = Number(globalThis.navigator?.maxTouchPoints || 0) > 0;
	            const isTouchLikeDevice = !!globalThis.matchMedia && (globalThis.matchMedia('(hover: none)').matches || globalThis.matchMedia('(pointer: coarse)').matches);
	            if (this.isMobileWorkspaceNavigationActive() || hasTouchPoints || isTouchLikeDevice) {
	                document.body?.classList.add('geweb-ai-page-pseudo-fullscreen');
	                this.syncPageViewportOffsetStyles();
	                this.syncPageViewHeightToViewport();
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
	        } catch {
	            document.body?.classList.toggle('geweb-ai-page-pseudo-fullscreen');
	            this.syncPageViewportOffsetStyles();
	            this.syncPageViewHeightToViewport();
	        } finally {
	            this.syncPageToolbarFullscreenState();
	        }
	    },

	    togglePageAdminBarVisibility() {
	        const body = document.body;
	        if (!body?.classList.contains('geweb-ai-page')) {
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
				if ($searchPanel.length && !$searchPanel.hasClass('is-collapsed')) {
					$searchPanel.removeClass('is-hidden').addClass('is-collapsed');
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

	    toggleMobileOrDesktopPanel(workspace, side) {
	        if (this.isMobileWorkspaceNavigationActive(workspace)) {
	            this.setMobileWorkspacePane(workspace.dataset.mobilePane === side ? 'main' : side, { focusPane: true });
	            return;
	        }

	        workspace.classList.toggle(`is-${side}-collapsed`);
	    },

		    handlePanelCollapseClick(event, workspace, $searchPanel) {
		        const target = $(event.currentTarget).data('panel-toggle');
		        if (target === 'left' || target === 'right') {
		            this.toggleMobileOrDesktopPanel(workspace, target);
		        } else if (target === 'search') {
		            $searchPanel.removeClass('is-hidden');
		            $searchPanel.toggleClass('is-collapsed');
		            if (!$searchPanel.hasClass('is-collapsed')) {
		                $searchPanel.find('.geweb-ai-inline-search-form input[name="s"]').first().trigger('focus');
		            }
		        }

	        this.syncPanelCollapseButtons();
	    },

	    bindPanelCollapseButtons() {
	        const workspace = this.getWorkspaceElement();
	        const $searchPanel = $('.geweb-ai-search-results-panel');
	        if (!workspace) {
	            return;
	        }

	        $(this.ai).find('.geweb-ai-panel-collapse').on('click', (event) => {
	            this.handlePanelCollapseClick(event, workspace, $searchPanel);
	        });
	},

	    applyPanelCollapseButtonState($button, expanded, icon, label) {
	        $button.attr('aria-expanded', expanded ? 'true' : 'false');
	        $button.attr('aria-label', label);
	        $button.attr('title', label);
	        $button.find('.geweb-ai-panel-collapse-icon').text(icon);
	    },

	    getSidePanelButtonState(side, collapsed, mobilePane, mobileNavigationActive) {
	        const isLeft = side === 'left';
	        const expanded = mobileNavigationActive ? mobilePane === side : !collapsed;
	        const collapsedIcon = isLeft ? '▶' : '◀';
	        const expandedIcon = isLeft ? '◀' : '▶';
	        const panelName = isLeft ? 'chats' : 'sources';
	        if (mobileNavigationActive) {
	            return {
	                expanded,
	                icon: expanded ? collapsedIcon : expandedIcon,
	                label: expanded ? 'Show answer panel' : `Show ${panelName} panel`,
	            };
	        }

	        return {
	            expanded,
	            icon: collapsed ? collapsedIcon : expandedIcon,
	            label: `${collapsed ? 'Expand' : 'Collapse'} ${panelName} panel`,
	        };
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
	        const leftButtonState = this.getSidePanelButtonState('left', leftCollapsed, mobilePane, mobileNavigationActive);
	        const rightButtonState = this.getSidePanelButtonState('right', rightCollapsed, mobilePane, mobileNavigationActive);

	        this.applyPanelCollapseButtonState(
	            $(this.ai).find('.geweb-ai-panel-collapse[data-panel-toggle="left"]'),
	            leftButtonState.expanded,
	            leftButtonState.icon,
	            leftButtonState.label
	        );
	        this.applyPanelCollapseButtonState(
	            $(this.ai).find('.geweb-ai-panel-collapse[data-panel-toggle="right"]'),
	            rightButtonState.expanded,
	            rightButtonState.icon,
	            rightButtonState.label
	        );
		        this.applyPanelCollapseButtonState(
		            $(this.ai).find('.geweb-ai-panel-collapse[data-panel-toggle="search"]'),
		            searchExpanded,
		            searchExpanded ? '▴' : '🔎',
		            searchExpanded ? 'Hide search results' : 'Show search results'
		        );
		        this.syncMobilePaneFooter(mobilePane);
		    },

	    bindFrontendHeaderSearch() {
	        $('form').has('input[name="s"]').each((_, form) => {
	            this.bindFrontendHeaderSearchForm(form);
	        });
	    },

	    bindFrontendHeaderSearchForm(form) {
	        const $form = $(form);
	        const isInlineWorkspaceSearch = $form.hasClass('geweb-ai-inline-search-form');
	        if (($form.closest('#geweb-ai-modal').length && !isInlineWorkspaceSearch) || $form.data('gewebAiSearchBound')) {
	            return;
	        }

	        const $input = $form.find('input[name="s"]').first();
	        if (!$input.length) {
	            return;
	        }

	        this.applyCurrentWorkspaceQuery($input);
		        this.prepareHeaderSearchInput($form, $input, isInlineWorkspaceSearch);
	        this.bindHeaderSearchEvents($form, $input);
	        this.showInlineWorkspaceSearchPanel(isInlineWorkspaceSearch);
	    },

	    applyCurrentWorkspaceQuery($input) {
	        const currentWorkspaceQuery = this.resolveQueryText('');
	        if (currentWorkspaceQuery !== '' && String($input.val() || '').trim() === '') {
	            $input.val(currentWorkspaceQuery);
	        }
	    },

	    syncHeaderSearchPlaceholder($input) {
	        $input.toggleClass('geweb-ai-search-input--compact-placeholder', String($input.val() || '').trim() === '');
	    },

		    prepareHeaderSearchInput($form, $input, isInlineWorkspaceSearch = false) {
		        $form.addClass('geweb-ai-workspace-search-form');
		        $input.addClass('geweb-ai-workspace-search-input');
		        $input.attr(
		            'placeholder',
		            isInlineWorkspaceSearch
		                ? t('searchResultsLabel', 'Search results')
		                : t('searchResultsIntro', 'Use your normal site search above to update these WordPress results without leaving the AI workspace.')
		        );
		        this.syncHeaderSearchPlaceholder($input);
		    },

	    bindHeaderSearchEvents($form, $input) {
	        const syncPlaceholder = () => this.syncHeaderSearchPlaceholder($input);
	        const clearIfEmpty = () => this.clearWorkspaceResultsIfInputIsEmpty($input);

	        $input.on('input', syncPlaceholder);
	        $input.on('search', clearIfEmpty);
	        $input.on('change', clearIfEmpty);

	        $form.data('gewebAiSearchBound', true);
	        $form.on('submit', (event) => {
	            event.preventDefault();
	            this.openFrontendAiPage(String($input.val() || '').trim());
	        });

	        globalThis.setTimeout(syncPlaceholder, 0);
	    },

	    clearWorkspaceResultsIfInputIsEmpty($input) {
	        const visibleQuery = String($input.val() || '').trim();
	        const currentQuery = this.resolveQueryText('');
	        if (visibleQuery !== '' || currentQuery === '') {
	            return;
	        }

	        this.openFrontendAiPage('');
	    },

	    showInlineWorkspaceSearchPanel(isInlineWorkspaceSearch) {
	        if (!isInlineWorkspaceSearch) {
	            return;
	        }

		        this.syncPanelCollapseButtons();
		    },

	    escapeAttr(text) {
	        return String(text).replaceAll('&', '&amp;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
	    },

	};
};
})();
