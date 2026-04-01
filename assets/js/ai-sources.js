(function($) {
    let nonceRequest = null;
    const MANAGED_TITLE_URL_REGEX_SUFFIX = String.raw`[^\n]*?(https?:\/\/[^\s<>"']+)`;
    const TRAILING_SLASH_REGEX = /\/?$/;

    function getAiSearchConfig() {
        return globalThis.geweb_aisearch ?? {};
    }

    function getI18nValue(key) {
        return getAiSearchConfig().i18n?.[key];
    }

    function normalizeObject(value) {
        return value && typeof value === 'object' ? value : {};
    }

    function safeParseUrl(url, base) {
        try {
            return new URL(url, base);
        } catch (error) {
            console.debug('URL parsing failed.', error);
            return null;
        }
    }

    function t(key, fallback) {
        const translated = getI18nValue(key);
        if (translated) {
            return translated;
        }

        return fallback;
    }

    function fetchSearchNonce() {
        return $.post(getAiSearchConfig().ajax_url, {
            action: 'geweb_get_nonce'
        }).then(function(response) {
            if (response?.data?.nonce && response?.success) {
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

    globalThis.GewebAISearchSourceMethods = {
        buildResponseDetails(meta) {
            const normalizedMeta = normalizeObject(meta);
            if (!Object.keys(normalizedMeta).length) {
                return null;
            }

            const $details = $('<div class="geweb-ai-response-details"></div>');
            $details.append($('<div class="geweb-ai-response-details-title"></div>').text(t('responseDetails', 'Response details')));

            const summaryEntries = this.buildCompactResponseMetaEntries(normalizedMeta);
            if (summaryEntries.length) {
                const $list = $('<dl class="geweb-ai-response-details-list"></dl>');
                summaryEntries.forEach((entry) => {
                    $list.append($('<dt></dt>').text(entry.label));
                    $list.append($('<dd></dd>').text(entry.value));
                });
                $details.append($list);
            }

            const grounding = this.getGroundingMetadata(normalizedMeta);
            const groundingChunks = this.getGroundingChunks(grounding);
            if (groundingChunks.length) {
                const $section = $('<div class="geweb-ai-grounding-section"></div>');
                $section.append($('<div class="geweb-ai-grounding-section-title"></div>').text('Grounding chunks'));
                const $chunkList = $('<ol class="geweb-ai-grounding-chunk-list"></ol>');
                groundingChunks.forEach((chunk) => {
                    const $item = $('<li></li>');
                    $item.append($('<div class="geweb-ai-grounding-chunk-label"></div>').text(
                        this.getPreferredSourceLabel(chunk.title, chunk.url || '')
                    ));
                    if (chunk.text) {
                        $item.append($('<div class="geweb-ai-grounding-chunk-text"></div>').text(chunk.text));
                    }
                    $chunkList.append($item);
                });
                $section.append($chunkList);
                $details.append($section);
            }

            if (!$details.children().length) {
                const entries = this.flattenResponseMeta(normalizedMeta);
                if (!entries.length) {
                    return null;
                }
                const $list = $('<dl class="geweb-ai-response-details-list"></dl>');
                entries.forEach((entry) => {
                    $list.append($('<dt></dt>').text(entry.label));
                    $list.append($('<dd></dd>').text(entry.value));
                });
                $details.append($list);
            }

            return $details;
        },

        buildCompactResponseMetaEntries(meta) {
            const entries = [];
            const usage = normalizeObject(meta.usage);
            const candidate = normalizeObject(meta.candidate);
            const prompt = normalizeObject(meta.prompt);
            const grounding = this.getGroundingMetadata(meta);

            const pushEntry = (label, value) => {
                const text = String(value || '').trim();
                if (!text) {
                    return;
                }
                entries.push({ label, value: text });
            };

	            pushEntry('Provider', meta.provider);
	            pushEntry('Model', meta.model_version || meta.model);
	            pushEntry('Prompt name', prompt.name || prompt.scope);
	            pushEntry('Prompt', prompt.text || prompt.preview);
	            pushEntry('Prompt scope', prompt.scope);
	            pushEntry('Prompt mode', prompt.mode);
            pushEntry('Response ID', meta.response_id);
            pushEntry('Finish', candidate.finish_reason || candidate.finish_message);
            if (usage.total_tokens) {
                pushEntry('Tokens', `${usage.total_tokens}`);
            }
            if (meta.estimated_cost_usd !== undefined) {
                pushEntry('Estimated cost', `$${Number(meta.estimated_cost_usd).toFixed(6)}`);
            }

            const chunks = this.getGroundingChunks(grounding);
            if (chunks.length) {
                pushEntry('Grounding chunks', `${chunks.length}`);
            }

            const supports = Array.isArray(grounding?.groundingSupports) ? grounding.groundingSupports : [];
            if (supports.length) {
                pushEntry('Grounding supports', `${supports.length}`);
            }

            return entries;
        },

        getGroundingMetadata(meta) {
            const candidate = normalizeObject(meta?.candidate);
            return candidate.grounding_metadata && typeof candidate.grounding_metadata === 'object'
                ? candidate.grounding_metadata
                : {};
        },

        getGroundingChunks(grounding) {
            const chunks = Array.isArray(grounding?.groundingChunks) ? grounding.groundingChunks : [];
            return chunks.map((chunk) => {
                const context = normalizeObject(chunk?.retrievedContext);
                return {
                    title: String(context.title || ''),
                    text: this.formatMetaValue(String(context.text || '')),
                    url: String(context.uri || context.url || ''),
                };
            });
        },

        decorateAnswerWithGroundingFootnotes(answerHtml, meta, sourceFootnoteMap) {
            const html = String(answerHtml || '');
            if (!html) {
                return '';
            }

            const grounding = this.getGroundingMetadata(meta);
            const supports = Array.isArray(grounding?.groundingSupports) ? grounding.groundingSupports : [];
            if (!supports.length) {
                return html;
            }

            const container = document.createElement('div');
            container.innerHTML = html;
            const blocks = Array.from(container.querySelectorAll('p, li')).filter((node) => String(node.textContent || '').trim() !== '');
            if (!blocks.length) {
                return html;
            }

            const blockFootnotes = new Map();
            supports.forEach((support) => {
                const indices = Array.isArray(support?.groundingChunkIndices)
                    ? support.groundingChunkIndices.filter((value) => Number.isInteger(value) && value >= 0)
                    : [];
                const segmentText = String(support?.segment?.text || '').replaceAll(/\s+/g, ' ').trim();
                if (!indices.length || !segmentText) {
                    return;
                }

                const matchedBlock = this.findBestGroundingBlockMatch(blocks, segmentText);
                if (!matchedBlock) {
                    return;
                }

                const existing = blockFootnotes.get(matchedBlock) || [];
                indices.forEach((index) => {
                    const localFootnote = index + 1;
                    const footnote = Number(sourceFootnoteMap?.[localFootnote]) || localFootnote;
                    if (!existing.includes(footnote)) {
                        existing.push(footnote);
                    }
                });
                blockFootnotes.set(matchedBlock, existing.sort((a, b) => a - b));
            });

            blockFootnotes.forEach((footnotes, block) => {
                if (!footnotes.length) {
                    return;
                }
                const marker = document.createElement('span');
                marker.className = 'geweb-ai-footnote-group';
                marker.innerHTML = footnotes.map((number) => `<sup class="geweb-ai-footnote-ref" data-footnote="${number}" title="Show source reference ${number}">[${number}]</sup>`).join('');
                block.appendChild(marker);
            });

            return container.innerHTML;
        },

        findBestGroundingBlockMatch(blocks, segmentText) {
            const normalizedSegment = String(segmentText || '').replaceAll(/\s+/g, ' ').trim().toLowerCase();
            if (!normalizedSegment) {
                return null;
            }

            let bestBlock = null;
            let bestScore = 0;
            blocks.forEach((block) => {
                const blockText = String(block.textContent || '').replaceAll(/\s+/g, ' ').trim().toLowerCase();
                if (!blockText) {
                    return;
                }

                if (blockText.includes(normalizedSegment) || normalizedSegment.includes(blockText)) {
                    const score = Math.min(blockText.length, normalizedSegment.length);
                    if (score > bestScore) {
                        bestScore = score;
                        bestBlock = block;
                    }
                    return;
                }

                const probe = normalizedSegment.slice(0, Math.min(80, normalizedSegment.length));
                if (probe && blockText.includes(probe)) {
                    const score = probe.length;
                    if (score > bestScore) {
                        bestScore = score;
                        bestBlock = block;
                    }
                }
            });

            return bestBlock;
        },

        flattenResponseMeta(meta) {
            const entries = [];
            const maxEntries = 40;
            const visit = (value, path) => {
                if (entries.length >= maxEntries) {
                    return;
                }

                if (value === null || value === undefined) {
                    return;
                }

                if (Array.isArray(value)) {
                    if (!value.length) {
                        return;
                    }

                    const simpleValues = value.every((item) => item === null || ['string', 'number', 'boolean'].includes(typeof item));
                    if (simpleValues) {
                        entries.push({
                            label: this.formatMetaLabel(path),
                            value: value.map(String).join(', ')
                        });
                        return;
                    }

                    value.forEach((item, index) => visit(item, `${path} ${index + 1}`));
                    return;
                }

                if (typeof value === 'object') {
                    Object.keys(value).forEach((key) => {
                        visit(value[key], path ? `${path} ${key}` : key);
                    });
                    return;
                }

                entries.push({
                    label: this.formatMetaLabel(path),
                    value: this.formatMetaValue(value)
                });
            };

            visit(meta, '');
            if (entries.length >= maxEntries) {
                entries.push({
                    label: 'More',
                    value: 'Additional response metadata was omitted from this view.'
                });
            }
            return entries;
        },

        formatMetaLabel(path) {
            return String(path || 'value')
                .replaceAll('_', ' ')
                .replaceAll(/\s+/g, ' ')
                .trim()
                .replaceAll(/\b\w/g, (char) => char.toUpperCase());
        },

        formatMetaValue(value) {
            const text = String(value);
            return text.length > 240 ? `${text.slice(0, 237)}...` : text;
        },

        getSourceRegistryKey(source) {
            if (!source || typeof source !== 'object') {
                return '';
            }

            const normalizedUrl = this.normalizeManagedSourceUrl(source.url || '');
            if (normalizedUrl) {
                return `url:${normalizedUrl.toLowerCase()}`;
            }

            const title = String(source.title || '').trim().toLowerCase();
            if (title) {
                return `title:${title}`;
            }

            const snippet = String(source.snippet || '').replaceAll(/\s+/g, ' ').trim().toLowerCase();
            return snippet ? `snippet:${snippet}` : '';
        },

        buildConversationSourceRegistry() {
            const registry = [];
            const registryIndex = new Map();
            const history = Array.isArray(this.conversationHistory) ? this.conversationHistory : [];

            history.forEach((item, historyIndex) => {
                if (item?.role !== 'model') {
                    return;
                }

                const normalizedSources = this.normalizeSources(item.sources || [], item.content || '', item.meta || {});
                normalizedSources.forEach((source) => {
                    const key = this.getSourceRegistryKey(source);
                    if (!key) {
                        return;
                    }

                    if (!registryIndex.has(key)) {
                        registryIndex.set(key, registry.length);
                        registry.push({
                            ...source,
                            footnote: registry.length + 1,
                            historyIndices: [historyIndex],
                        });
                        return;
                    }

                    const registryEntry = registry[registryIndex.get(key)];
                    if (!registryEntry) {
                        return;
                    }

                    if (!registryEntry.snippet && source.snippet) {
                        registryEntry.snippet = source.snippet;
                    }
                    if (!registryEntry.title && source.title) {
                        registryEntry.title = source.title;
                    }
                    if (!registryEntry.url && source.url) {
                        registryEntry.url = source.url;
                    }
                    if (!registryEntry.historyIndices.includes(historyIndex)) {
                        registryEntry.historyIndices.push(historyIndex);
                    }
                });
            });

            return registry;
        },

        getResponseSourceFootnoteMap(sources, answerText, responseMeta) {
            const normalizedSources = this.normalizeSources(sources, answerText, responseMeta);
            const registry = this.buildConversationSourceRegistry();
            const registryMap = new Map();

            registry.forEach((source) => {
                const key = this.getSourceRegistryKey(source);
                if (key) {
                    registryMap.set(key, Number(source.footnote || 0));
                }
            });

            return normalizedSources.reduce((map, source) => {
                const localFootnote = Number(source.footnote || 0);
                const key = this.getSourceRegistryKey(source);
                const globalFootnote = key ? Number(registryMap.get(key) || 0) : 0;
                if (localFootnote > 0 && globalFootnote > 0) {
                    map[localFootnote] = globalFootnote;
                }
                return map;
            }, {});
        },

        renderSources() {
            if (!this.$sourcesBox.length) {
                return;
            }

            this.$sourcesBox.empty();

            const normalizedSources = this.buildConversationSourceRegistry();

            if (!normalizedSources.length) {
                this.$sourcesBox.append($('<p class="geweb-ai-empty-panel"></p>').text(t('noSourcesYet', 'No source links yet.')));
                return;
            }

            const $list = $('<ol class="geweb-ai-source-list"></ol>');
            normalizedSources.forEach((source) => {
                const title = this.getPreferredSourceLabel(source.title, source.url);
                const url = source.url;
                const $item = $('<li></li>');
                const $itemHeader = $('<div class="geweb-ai-source-item-header"></div>');
                const $toggle = $('<button type="button" class="geweb-ai-source-link geweb-ai-source-toggle"></button>');
                const $label = $('<span class="geweb-ai-source-link-label"></span>').text(title);
                const footnote = Number(source.footnote || 0);
                const snippet = String(source.snippet || '').trim();
                const sourceLabel = title || url || `Source ${footnote || ''}`.trim();

                $toggle.attr('aria-label', `Show context for ${sourceLabel}`);
                $toggle.append($label);

                if (url) {
                    $item.attr('data-source-url', url);
                    const $openLink = $('<a class="geweb-ai-source-open" rel="noopener noreferrer"></a>');
                    $openLink.attr('href', url);
                    $openLink.attr('aria-label', `Open source ${sourceLabel}`);
                    $openLink.attr('title', `Open source ${sourceLabel}`);
                    $openLink.text('Open');
                    $itemHeader.append($openLink);
                }

                if (footnote > 0) {
                    $item.attr('data-source-footnote', `${footnote}`);
                }

                $toggle.on('click', (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    this.activateSourceReferenceItem($item);
                });

                $itemHeader.prepend($toggle);
                $item.append($itemHeader);
                if (snippet) {
                    $item.append($('<div class="geweb-ai-source-snippet"></div>').text(snippet));
                }
                $list.append($item);
            });

            this.$sourcesBox.append($list);
            this.hydrateResolvedSourceReferences($list, normalizedSources);
        },

        async hydrateResolvedSourceReferences($list, sources) {
            if (!$list.length || !Array.isArray(sources) || !sources.length) {
                return;
            }

            const uncachedUrls = [];
            sources.forEach((source) => {
                const url = String(source?.url || '').trim();
                if (url && !this.sourceReferenceCache[url]) {
                    uncachedUrls.push(url);
                }
            });

            if (uncachedUrls.length) {
                try {
                    await ensureSearchNonce();
                    const response = await $.ajax({
                        url: geweb_aisearch.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'geweb_resolve_source_references',
                            nonce: geweb_aisearch.search_nonce,
                            urls: uncachedUrls
                        }
                    });

                    const references = response?.success ? response?.data?.references ?? {} : {};
                    Object.keys(references).forEach((url) => {
                        const reference = references[url];
                        if (reference && typeof reference === 'object') {
                            this.sourceReferenceCache[url] = reference;
                        }
                    });
                } catch (error) {
                    console.debug('Source reference resolution failed.', error);
                }
            }

            $list.find('li[data-source-url]').each((_, item) => {
                const $item = $(item);
                const originalUrl = String($item.attr('data-source-url') || '').trim();
                const reference = originalUrl ? this.sourceReferenceCache[originalUrl] : null;
                if (!reference || typeof reference !== 'object') {
                    return;
                }

                const nextUrl = String(reference.url || originalUrl).trim();
                const nextLabel = String(reference.label || reference.title || '').trim();
                const $label = $item.find('.geweb-ai-source-link-label').first();
                if (!$label.length) {
                    return;
                }

                if (nextLabel) {
                    $label.text(nextLabel);
                }
                if (nextUrl) {
                    const $openLink = $item.find('.geweb-ai-source-open').first();
                    if ($openLink.length) {
                        $openLink.attr('href', nextUrl);
                    }
                }
            });
        },

        normalizeSources(sources, answerText, responseMeta) {
            const seen = new Set();
            const haystack = String(answerText || '').toLowerCase();
            const groundingSources = this.extractSourcesFromGroundingChunks(responseMeta, sources, answerText, seen);
            if (groundingSources.length) {
                return groundingSources;
            }

            const normalized = Array.isArray(sources) && sources.length
                ? sources.reduce((accumulator, source) => {
                    const title = source?.title ? String(source.title).trim() : '';
                    const url = source?.url ? String(source.url).trim() : '';
                    const key = (url || title).toLowerCase();

                    if (!key || seen.has(key) || !this.isManagedSourceUrl(url)) {
                        return accumulator;
                    }

                    seen.add(key);
                    accumulator.push({
                        title: this.getPreferredSourceLabel(title, url),
                        url: url,
                        mentioned: haystack !== '' && (
                            (title !== '' && haystack.includes(title.toLowerCase())) ||
                            (url !== '' && haystack.includes(url.toLowerCase()))
                        ),
                    });
                    return accumulator;
                }, [])
                : [];

            if (normalized.length) {
                return normalized;
            }

            const metaSources = this.extractSourcesFromMeta(responseMeta, seen);
            if (metaSources.length) {
                return metaSources;
            }

            return this.extractSourcesFromAnswerText(answerText, seen);
        },

        extractSourcesFromGroundingChunks(responseMeta, sources, answerText, seen) {
            const chunks = this.getGroundingChunks(this.getGroundingMetadata(responseMeta));
            if (!chunks.length) {
                return [];
            }

            const explicitSources = Array.isArray(sources) ? sources : [];
            return chunks.reduce((accumulator, chunk, index) => {
                const inferredUrl = this.findManagedUrlForGroundingChunk(chunk, explicitSources, answerText);
                const normalizedUrl = this.normalizeManagedSourceUrl(inferredUrl);
                const dedupeKey = normalizedUrl || `chunk:${index}:${String(chunk.title || '').trim()}`;
                if (!dedupeKey || seen.has(dedupeKey.toLowerCase())) {
                    return accumulator;
                }

                seen.add(dedupeKey.toLowerCase());
                accumulator.push({
                    title: chunk.title || `Source ${index + 1}`,
                    url: normalizedUrl,
                    snippet: chunk.text || '',
                    footnote: index + 1,
                });
                return accumulator;
            }, []);
        },

        findManagedUrlForGroundingChunk(chunk, sources, answerText) {
            const title = String(chunk?.title || '').trim();
            const directUrl = this.normalizeManagedSourceUrl(chunk?.url || '');
            if (directUrl) {
                return directUrl;
            }

            const sourceMatchUrl = this.findMatchingManagedUrlByTitle(title, sources);
            if (sourceMatchUrl) {
                return sourceMatchUrl;
            }

            const managedDocumentUrl = this.buildManagedDocumentUrlFromTitle(title);
            if (managedDocumentUrl) {
                return managedDocumentUrl;
            }

            return this.findManagedUrlMentionedInAnswer(title, answerText);
        },

        findMatchingManagedUrlByTitle(title, sources) {
            for (const source of sources) {
                const sourceUrl = this.normalizeManagedSourceUrl(source?.url || '');
                const sourceTitle = String(source?.title || '').trim();
                if (!sourceUrl) {
                    continue;
                }
                if (title && sourceTitle && title.toLowerCase() === sourceTitle.toLowerCase()) {
                    return sourceUrl;
                }
            }

            return '';
        },

        buildManagedDocumentUrlFromTitle(title) {
            if (!/^\d+\.md$/i.test(title)) {
                return '';
            }

            return `${String(getAiSearchConfig().site_url || globalThis.location?.origin || '').replace(TRAILING_SLASH_REGEX, '')}/?p=${title.replace(/\.md$/i, '')}`;
        },

        findManagedUrlMentionedInAnswer(title, answerText) {
            const text = String(answerText || '');
            if (!title || !text) {
                return '';
            }

            const escapedTitle = title.replaceAll(/[.*+?^${}()|[\]\\]/g, String.raw`\$&`);
            const titleUrlPattern = new RegExp(`${escapedTitle}${MANAGED_TITLE_URL_REGEX_SUFFIX}`, 'i');
            const match = titleUrlPattern.exec(text);
            return match?.[1] || '';
        },

        highlightSourceReference(footnote) {
            if (!this.$sourcesBox.length) {
                return;
            }

            const $items = this.$sourcesBox.find('[data-source-footnote]');
            const $target = $items.filter(`[data-source-footnote="${footnote}"]`).first();
            if (!$target.length) {
                return;
            }

            this.activateSourceReferenceItem($target);
            const element = $target.get(0);
            if (element && typeof element.scrollIntoView === 'function') {
                element.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            }
        },

        activateSourceReferenceItem($target) {
            if (!$target?.length || !this.$sourcesBox.length) {
                return;
            }

            const $items = this.$sourcesBox.find('li');
            $items.removeClass('is-active');
            $target.addClass('is-active');
        },

        extractSourcesFromMeta(responseMeta, seen) {
            const meta = normalizeObject(responseMeta);
            const extracted = [];

            const visit = (value) => {
                if (!value) {
                    return;
                }

                if (Array.isArray(value)) {
                    value.forEach((item) => visit(item));
                    return;
                }

                if (typeof value !== 'object') {
                    return;
                }

                const urlCandidates = [
                    value.url,
                    value.uri,
                    value.sourceUrl,
                    value.source_url,
                ].filter((item) => typeof item === 'string' && item.trim() !== '');

                urlCandidates.forEach((candidate) => {
                    const url = String(candidate).trim();
                    if (!this.isManagedSourceUrl(url)) {
                        return;
                    }

                    const key = url.toLowerCase();
                    if (seen.has(key)) {
                        return;
                    }

                    seen.add(key);
                    extracted.push({
                        title: this.getPreferredSourceLabel(this.extractSourceTitleFromMeta(value, url), url),
                        url: url,
                        mentioned: true
                    });
                });

                Object.keys(value).forEach((key) => {
                    visit(value[key]);
                });
            };

            visit(meta);
            return extracted;
        },

        extractSourceTitleFromMeta(value, fallbackUrl) {
            if (!value || typeof value !== 'object') {
                return fallbackUrl;
            }

            const candidates = [
                value.title,
                value.name,
                value.displayName,
                value.display_name,
                value.domain,
            ];

            for (const rawCandidate of candidates) {
                const candidate = typeof rawCandidate === 'string' ? rawCandidate.trim() : '';
                if (candidate) {
                    return candidate;
                }
            }

            return fallbackUrl;
        },

        extractSourcesFromAnswerText(answerText, seen) {
            const html = String(answerText || '').trim();
            if (!html) {
                return [];
            }

            const parser = document.createElement('div');
            parser.innerHTML = html;

            const extracted = [];
            parser.querySelectorAll('a[href]').forEach((anchor) => {
                const href = this.normalizeManagedSourceUrl(anchor.getAttribute('href') || '');
                const text = String(anchor.textContent || '').replaceAll(/\s+/g, ' ').trim();
                if (!href) {
                    return;
                }

                const key = href.toLowerCase();
                if (seen.has(key)) {
                    return;
                }

                seen.add(key);
                extracted.push({
                    title: this.getPreferredSourceLabel(text || href, href),
                    url: href,
                    mentioned: true
                });
            });

            if (extracted.length) {
                return extracted;
            }

            const parserText = String(parser.textContent || '').replaceAll(/\s+/g, ' ').trim();
            const rawMatches = parserText.match(/(?:https?:\/\/[^\s)]+|\/[A-Za-z0-9._~:/?#[\]@!$&'()*+,;=%-]+)/g) || [];
            rawMatches.forEach((match) => {
                const url = this.normalizeManagedSourceUrl(match);
                if (!url) {
                    return;
                }

                const key = url.toLowerCase();
                if (seen.has(key)) {
                    return;
                }

                seen.add(key);
                extracted.push({
                    title: this.getPreferredSourceLabel(url, url),
                    url: url,
                    mentioned: true
                });
            });

            return extracted;
        },

        getPreferredSourceLabel(title, url) {
            const normalizedTitle = String(title || '').trim();
            const normalizedUrl = this.normalizeManagedSourceUrl(url);
            const urlLabel = this.formatManagedSourcePath(normalizedUrl);

            if (!normalizedTitle) {
                return urlLabel || normalizedUrl || t('untitledConversation', 'Untitled conversation');
            }

            if (
                /\.md$/i.test(normalizedTitle) ||
                normalizedTitle === normalizedUrl ||
                /^https?:\/\//i.test(normalizedTitle)
            ) {
                return urlLabel || normalizedTitle;
            }

            return normalizedTitle;
        },

        formatManagedSourcePath(url) {
            const normalizedUrl = this.normalizeManagedSourceUrl(url);
            if (!normalizedUrl) {
                return '';
            }

            const parsed = safeParseUrl(normalizedUrl);
            if (!parsed) {
                return '';
            }

            const pathname = parsed.pathname.replace(/^\/+/, '');
            if (pathname) {
                return pathname.replace(TRAILING_SLASH_REGEX, '/');
            }

            const pageId = parsed.searchParams.get('page_id');
            if (pageId) {
                return `page ${pageId}`;
            }

            const postId = parsed.searchParams.get('p');
            if (postId) {
                return `post ${postId}`;
            }

            return parsed.search.replace(/^\?/, '');
        },

        normalizeManagedSourceUrl(url) {
            const candidate = String(url || '').trim();
            if (!candidate) {
                return '';
            }

            const siteUrl = safeParseUrl(String(getAiSearchConfig().site_url || globalThis.location?.origin || ''));
            if (!siteUrl) {
                return '';
            }

            const normalized = safeParseUrl(candidate, siteUrl);
            return normalized?.origin === siteUrl.origin ? normalized.toString() : '';
        },

        isManagedSourceUrl(url) {
            return this.normalizeManagedSourceUrl(url) !== '';
        },

        getLatestSources() {
            for (let index = this.conversationHistory.length - 1; index >= 0; index -= 1) {
                const item = this.conversationHistory[index];
                if (item?.role === 'model' && Array.isArray(item.sources) && item.sources.length) {
                    return item.sources;
                }
            }

            return [];
        },

        getLatestAnswerText() {
            for (let index = this.conversationHistory.length - 1; index >= 0; index -= 1) {
                const item = this.conversationHistory[index];
                if (item?.role === 'model' && item.content) {
                    return String(item.content);
                }
            }

            return '';
        },

        getLatestResponseMeta() {
            for (let index = this.conversationHistory.length - 1; index >= 0; index -= 1) {
                const item = this.conversationHistory[index];
                if (item?.role === 'model' && item.meta && typeof item.meta === 'object') {
                    return item.meta;
                }
            }

            return {};
        }
    };
})(jQuery);
