function getAiSearchConfig() {
    return globalThis.geweb_aisearch ?? {};
}

function t(key, fallback) {
    const translated = getAiSearchConfig().i18n?.[key];
    return typeof translated === 'string' && translated.trim() !== '' ? translated : fallback;
}

jQuery(document).ready(function ($) {
    const GewebAILauncher = {
        init() {
            if (getAiSearchConfig().frontend_ai_interface !== 'fullscreen') {
                return;
            }

            if (getAiSearchConfig().is_frontend_ai_page) {
                return;
            }

            this.injectAIButtons();
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
