function getAiSearchConfig() {
	return globalThis.geweb_aisearch ?? {};
}

function t(key, fallback) {
	const translated = getAiSearchConfig().i18n?.[key];
	return typeof translated === 'string' && translated.trim() !== '' ? translated : fallback;
}

jQuery(document).ready(($) => {
	const GewebAILauncher = {
		init() {
			if (getAiSearchConfig().frontend_ai_interface !== 'fullscreen') {
				return;
			}

			if (getAiSearchConfig().is_frontend_ai_page) {
				return;
			}

			this.injectAIButtons();
			this.injectMobileShortcut();
			this.injectMobileMenuEntries();
			this.bindMobileMenuInjection();
		},

		buildFrontendAiPageUrl(query) {
			const baseUrl = getAiSearchConfig().frontend_ai_page_url || globalThis.location?.href || '';
			const url = new URL(baseUrl, globalThis.location?.origin);
			const trimmedQuery = String(query || '').trim();

			url.searchParams.delete('geweb_ai_chat');
			url.searchParams.delete('geweb_ai_conversation');
			url.searchParams.delete('s');

			if (trimmedQuery) {
				url.searchParams.set('geweb_ai_query', trimmedQuery);
			} else {
				url.searchParams.delete('geweb_ai_query');
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

				const $button = $('<button type="button" class="geweb-ai-page-trigger"><span class="geweb-ai-trigger-label" aria-hidden="true">AI</span></button>');
				$button.attr('aria-label', t('openAiSearch', 'Open AI Search'));
				this.matchTriggerButtonSize($button, $searchButton);
				$button.on('click', () => {
					const query = this.resolveQueryText($input.val());
					globalThis.location.href = this.buildFrontendAiPageUrl(query);
				});

				$form.addClass('geweb-ai-search-form');
				$input.addClass('geweb-ai-search-input');
				if ($searchButton.length) {
					$searchButton.after($button);
				} else {
					$form.append($button);
				}
			});
		},

		injectMobileShortcut() {
			if (!$('body').length || $('.geweb-ai-mobile-shortcut').length) {
				return;
			}

			const $shortcut = $(
				'<button type="button" class="geweb-ai-mobile-shortcut">' +
					'<span class="geweb-ai-mobile-shortcut-label"></span>' +
				'</button>'
			);

			$shortcut.attr('aria-label', t('openAiSearch', 'Open AI Search'));
			$shortcut.find('.geweb-ai-mobile-shortcut-label').text(t('searchWithAi', 'AI search'));
			$shortcut.on('click', () => {
				const query = this.resolveQueryText('');
				globalThis.location.href = this.buildFrontendAiPageUrl(query);
			});

			$('body').append($shortcut);
		},

		isMobileMenuInjectionActive() {
			return !!globalThis.matchMedia && globalThis.matchMedia('(max-width: 782px)').matches;
		},

		getStandardMenuContainers() {
			const selectors = [
				'nav .wp-block-navigation__container',
				'nav ul.menu',
				'nav ul[id^="menu-"]',
				'.main-navigation ul.menu',
				'.main-navigation ul[id^="menu-"]',
				'.wp-block-navigation ul.wp-block-navigation__container',
			];

			const seen = new Set();
			const containers = [];

			selectors.forEach((selector) => {
				document.querySelectorAll(selector).forEach((element) => {
					if (!(element instanceof HTMLUListElement)) {
						return;
					}

					if (element.closest('#wpadminbar')) {
						return;
					}

					if (element.closest('li')) {
						return;
					}

					const key = element;
					if (seen.has(key)) {
						return;
					}

					seen.add(key);
					containers.push(element);
				});
			});

			return containers;
		},

		buildMobileMenuEntry(container) {
			const listItem = document.createElement('li');
			listItem.className = 'menu-item geweb-ai-mobile-menu-item';

			if (container.classList.contains('wp-block-navigation__container')) {
				listItem.classList.add('wp-block-navigation-item');
			}

			const link = document.createElement('a');
			link.className = 'geweb-ai-mobile-menu-link';
			if (container.classList.contains('wp-block-navigation__container')) {
				link.classList.add('wp-block-navigation-item__content');
			}

			link.href = this.buildFrontendAiPageUrl(this.resolveQueryText(''));
			link.textContent = t('searchWithAi', 'AI search');
			link.setAttribute('aria-label', t('openAiSearch', 'Open AI Search'));

			listItem.appendChild(link);
			return listItem;
		},

		injectMobileMenuEntries() {
			if (!this.isMobileMenuInjectionActive()) {
				return;
			}

			this.getStandardMenuContainers().forEach((container) => {
				if (container.querySelector('.geweb-ai-mobile-menu-item, .geweb-ai-mobile-menu-link')) {
					return;
				}

				const firstChild = Array.from(container.children).find((child) => child instanceof HTMLLIElement);
				const entry = this.buildMobileMenuEntry(container);

				if (firstChild) {
					container.insertBefore(entry, firstChild);
					return;
				}

				container.appendChild(entry);
			});
		},

		bindMobileMenuInjection() {
			if (document.body?.dataset?.gewebAiMobileMenuBound === '1') {
				return;
			}

			if (document.body) {
				document.body.dataset.gewebAiMobileMenuBound = '1';
			}

			const inject = () => this.injectMobileMenuEntries();

			if (typeof globalThis.matchMedia === 'function') {
				const mediaQuery = globalThis.matchMedia('(max-width: 782px)');
				if (typeof mediaQuery.addEventListener === 'function') {
					mediaQuery.addEventListener('change', inject);
				} else if (typeof mediaQuery.addListener === 'function') {
					mediaQuery.addListener(inject);
				}
			}

			if (typeof globalThis.MutationObserver === 'function') {
				const observer = new globalThis.MutationObserver(() => inject());
				observer.observe(document.body, { childList: true, subtree: true });
			}
		},

		matchTriggerButtonSize($button, $referenceButton) {
			if (!$button.length || !$referenceButton.length) {
				return;
			}

			const width = $referenceButton.outerWidth();
			const height = $referenceButton.outerHeight();

			if (Number.isFinite(width) && width > 0) {
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
	};

	GewebAILauncher.init();
});
