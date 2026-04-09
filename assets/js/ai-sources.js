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
        if (typeof translated === 'string' && translated.trim() !== '') {
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
        getSourceDestination(url, matchPhrase) {
            const normalizedUrl = this.normalizeManagedSourceUrl(url);
            if (!normalizedUrl) {
                return null;
            }

            const phrase = String(matchPhrase || '').trim();
            const parsed = safeParseUrl(normalizedUrl);
            if (!parsed) {
                return null;
            }

            const pathname = parsed.pathname.toLowerCase();
            const documentExtensionMatch = /\.([a-z0-9]{2,8})$/i.exec(pathname);
            const documentExtensions = new Set(['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'rtf']);
            const isDocument = !!(documentExtensionMatch && documentExtensions.has(String(documentExtensionMatch[1] || '').toLowerCase()));

            if (isDocument) {
                return {
                    url: phrase ? `${normalizedUrl}#search=${encodeURIComponent(phrase)}` : normalizedUrl,
                    target: '_blank',
                };
            }

            if (parsed.hash) {
                parsed.searchParams.delete('geweb_ai_match');
                return {
                    url: parsed.toString(),
                    target: '_self',
                };
            }

            if (phrase) {
                parsed.searchParams.set('geweb_ai_match', phrase);
            } else {
                parsed.searchParams.delete('geweb_ai_match');
            }

            return {
                url: parsed.toString(),
                target: '_self',
            };
        },

        openSourceDestination(url, matchPhrase) {
            const destination = this.getSourceDestination(url, matchPhrase);
            if (!destination) {
                return;
            }

            globalThis.open(destination.url, destination.target, destination.target === '_blank' ? 'noopener,noreferrer' : undefined);
        },

        buildResponseDetails(meta) {
            const normalizedMeta = normalizeObject(meta);
            if (!Object.keys(normalizedMeta).length) {
                return null;
            }

            const $details = $('<div class="geweb-ai-response-details"></div>');
            const $header = $('<div class="geweb-ai-response-details-header"></div>');
            $header.append($('<div class="geweb-ai-response-details-title"></div>').text(t('responseDetails', 'Response details')));
            $header.append(
                $('<button type="button" class="geweb-ai-response-details-close" aria-label="Close details" title="Close details"></button>')
                    .append($('<span aria-hidden="true">×</span>'))
            );
            $details.append($header);
            let hasContentSection = false;

            const summaryEntries = this.buildCompactResponseMetaEntries(normalizedMeta);
            if (summaryEntries.length) {
                $details.append(this.buildResponseDetailsSection(
                    t('responseMetaTitle', 'Response metadata'),
                    this.buildResponseDetailsList(summaryEntries),
                    true
                ));
                hasContentSection = true;
            }

            const grounding = this.getGroundingMetadata(normalizedMeta);
            const groundingChunks = this.getGroundingChunks(grounding);
            if (groundingChunks.length) {
                const $section = $('<div class="geweb-ai-grounding-section"></div>');
                const $chunkList = $('<ol class="geweb-ai-grounding-chunk-list"></ol>');
                groundingChunks.forEach((chunk) => {
                    const $item = $('<li></li>');
                    const matchPhrase = this.extractContextMatchPhrase(chunk.rawText || chunk.text || '');
                    const $label = $('<div class="geweb-ai-grounding-chunk-label"></div>').text(
                        this.getPreferredSourceLabel(chunk.title, chunk.url || '')
                    );
                    $item.append($label);
                    if (chunk.text) {
                        const $text = $('<div class="geweb-ai-grounding-chunk-text"></div>').html(
                            this.highlightMatchPhraseInHtml(
                                this.renderFormattedChunkHtml(chunk.rawText || chunk.text),
                                matchPhrase
                            )
                        );
                        if (chunk.url) {
                            $item.addClass('geweb-ai-grounding-chunk-item--link');
                            $item.attr('tabindex', '0');
                            $item.attr('role', 'link');
                            $item.attr('title', matchPhrase ? `Open at first match: ${matchPhrase}` : 'Open source');
                            $item.on('click', () => this.openSourceDestination(chunk.url, matchPhrase));
                            $item.on('keydown', (event) => {
                                if (event.key === 'Enter' || event.key === ' ') {
                                    event.preventDefault();
                                    this.openSourceDestination(chunk.url, matchPhrase);
                                }
                            });
                        }
                        $item.append($text);
                    }
                    $chunkList.append($item);
                });
                $section.append($chunkList);
                $details.append(this.buildResponseDetailsSection(
                    `${t('groundingChunksTitle', 'Grounding chunks')} (${groundingChunks.length})`,
                    $section,
                    true
                ));
                hasContentSection = true;
            }

            if (!hasContentSection) {
                const entries = this.flattenResponseMeta(normalizedMeta);
                if (!entries.length) {
                    return null;
                }
                $details.append(this.buildResponseDetailsSection(
                    t('responseMetaTitle', 'Response metadata'),
                    this.buildResponseDetailsList(entries),
                    true
                ));
            }

            return $details;
        },

        buildResponseDetailsSection(title, $content, isOpen) {
            const $section = $('<details class="geweb-ai-response-details-section"></details>');
            if (isOpen) {
                $section.attr('open', 'open');
            }

            const $summary = $('<summary class="geweb-ai-response-details-section-summary"></summary>');
            $summary.append($('<span class="geweb-ai-response-details-section-title"></span>').text(title));
            $section.append($summary);
            $section.append($('<div class="geweb-ai-response-details-section-body"></div>').append($content));
            return $section;
        },

        buildResponseDetailsList(entries) {
            const $list = $('<dl class="geweb-ai-response-details-list"></dl>');
            entries.forEach((entry) => {
                const explanation = this.getResponseDetailsExplanation(entry.label);
                const $row = $('<div class="geweb-ai-response-details-row"></div>');
                const $term = $('<dt></dt>').text(entry.label);
                const $value = $('<dd></dd>').text(entry.value);
                if (explanation) {
                    $term.attr('title', explanation);
                    $value.attr('title', explanation);
                }
                $row.append($term, $value);
                $list.append($row);
            });
            return $list;
        },

        getResponseDetailsExplanation(label) {
            const explanations = {
                'Provider': 'The AI service that produced this answer, for example Google Gemini. This helps explain which backend handled the request and where provider-specific response metadata comes from.',
                'Model': 'The exact model variant that generated the answer. Different models can behave differently in speed, style, context handling, and citation quality, so this tells you which one was actually used.',
                'Prompt name': 'The human-readable name of the prompt configuration that was active for this answer. If you used a one-off override in the chat settings, this may describe that temporary override instead of a saved default prompt.',
                'Prompt': 'The effective instruction text that was sent along with your question. This is the prompt the model actually received after prompt selection and any temporary override were applied.',
                'Prompt scope': 'Where the effective prompt came from. For example, it may be a global default prompt, a model-specific prompt, or a temporary prompt override for just this one question.',
                'Prompt mode': 'How the selected prompt was applied. For example, a base/default mode means the normal prompt variant was used, while an override mode means a temporary or replacement instruction took precedence.',
                'Response ID': 'The provider-specific identifier for this generated response. This can be useful for debugging, tracing provider logs, or comparing repeated runs of the same question.',
                'Finish': 'Why the model stopped generating text. A normal value such as STOP usually means the answer finished naturally, while other values can hint at truncation, limits, or provider-side interruption.',
                'Tokens': 'The total token usage reported for this answer. This is a rough measure of how much prompt and response text the model processed, and it often correlates with cost and context size.',
                'Estimated cost': 'A best-effort estimate of the cost of this response based on the reported model usage. Treat it as approximate rather than exact billing.',
                'Grounding chunks': 'How many retrieved source chunks were attached to the answer generation step. More chunks usually means the model had more candidate evidence available from your indexed content.',
                'Grounding supports': 'How many provider support references linked parts of the answer back to retrieved grounding chunks. This can help indicate how much of the answer was explicitly tied to retrieved evidence.'
            };

            return explanations[String(label || '').trim()] || '';
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
                const rawText = String(context.text || '');
                return {
                    title: String(context.title || ''),
                    text: this.formatMetaValue(rawText),
                    rawText: rawText,
                    url: String(context.uri || context.url || ''),
                };
            });
        },

        escapeHtml(text) {
            return String(text || '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#39;');
        },

        decodeHtmlEntities(text) {
            const parser = document.createElement('textarea');
            parser.innerHTML = String(text || '');
            return parser.value;
        },

        stripHtmlAndMarkdown(text) {
            const htmlContainer = document.createElement('div');
            htmlContainer.innerHTML = String(text || '');
            const withoutHtml = String(htmlContainer.textContent || htmlContainer.innerText || '');
            return withoutHtml
                .replaceAll(/!\[[^\]]*]\(([^)]+)\)/g, ' ')
                .replaceAll(/\[([^\]]+)]\(([^)]+)\)/g, '$1')
                .replaceAll(/[*_~`>#-]+/g, ' ')
                .replaceAll(/\s+/g, ' ')
                .trim();
        },

        extractContextMatchPhrase(text) {
            const normalized = this.stripHtmlAndMarkdown(this.decodeHtmlEntities(text));
            if (!normalized) {
                return '';
            }

            return normalized.split(/\s+/).slice(0, 6).join(' ');
        },

        isDocumentLikeTitle(title) {
            return /^.+\.(pdf|doc|docx|xls|xlsx|ppt|pptx|txt|csv|rtf)$/i.test(String(title || '').trim());
        },

        extractDocumentLabelFromTitle(title) {
            const normalizedTitle = String(title || '').trim();
            if (!normalizedTitle) {
                return '';
            }

            const prefixedDocumentMatch = /^(\d+)-(.+\.(pdf|doc|docx|xls|xlsx|ppt|pptx|txt|csv|rtf))$/i.exec(normalizedTitle);
            if (prefixedDocumentMatch?.[2]) {
                return String(prefixedDocumentMatch[2]).trim();
            }

            return this.isDocumentLikeTitle(normalizedTitle) ? normalizedTitle : '';
        },

        getDocumentExtension(value) {
            const normalizedValue = String(value || '').trim();
            if (!normalizedValue) {
                return '';
            }

            const match = /\.([a-z0-9]{2,8})(?:[?#].*)?$/i.exec(normalizedValue);
            return match?.[1] ? String(match[1]).toLowerCase() : '';
        },

        isDocumentUrl(url) {
            const normalizedUrl = String(url || '').trim();
            if (!normalizedUrl) {
                return false;
            }

            try {
                const parsed = new URL(normalizedUrl, String(getAiSearchConfig().site_url || globalThis.location?.origin || ''));
                return ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'rtf'].includes(this.getDocumentExtension(parsed.pathname || ''));
            } catch (error) {
                console.debug('Document URL detection failed.', error);
                return ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'rtf'].includes(this.getDocumentExtension(normalizedUrl));
            }
        },

        getDocumentType(source) {
            const title = String(source?.title || '').trim();
            const url = String(source?.url || '').trim();
            const documentLabel = String(source?.documentLabel || this.extractDocumentLabelFromTitle(title)).trim();
            const extension = this.getDocumentExtension(documentLabel) || this.getDocumentExtension(url);

            if (['doc', 'docx', 'rtf'].includes(extension)) {
                return 'word';
            }

            if (['xls', 'xlsx', 'csv'].includes(extension)) {
                return 'sheet';
            }

            if (['ppt', 'pptx'].includes(extension)) {
                return 'slides';
            }

            if (['txt'].includes(extension)) {
                return 'text';
            }

            if (extension === 'pdf') {
                return 'pdf';
            }

            return documentLabel || this.isDocumentUrl(url) ? 'document' : '';
        },

        getSourceKind(source) {
            return this.getDocumentType(source) ? 'document' : 'page';
        },

        getSourceIcon(source) {
            const documentType = this.getDocumentType(source);
            if (documentType === 'pdf') {
                return '📕';
            }

            if (documentType === 'word') {
                return '📝';
            }

            if (documentType === 'sheet') {
                return '📊';
            }

            if (documentType === 'slides') {
                return '📈';
            }

            if (documentType === 'text') {
                return '📄';
            }

            return documentType ? '📁' : '🌐';
        },

        normalizeDocumentContextText(text) {
            const normalized = String(text || '').replaceAll(/\r\n?/g, '\n').trim();
            if (!normalized) {
                return '';
            }

            const protectedBreak = '\uE000';
            const startsNewBlock = (line) => {
                const trimmed = String(line || '').trim();
                if (!trimmed) {
                    return true;
                }

                return (
                    /^(#{1,6})\s+/.test(trimmed) ||
                    /^[-*]\s+/.test(trimmed) ||
                    /^\d+\.\s+/.test(trimmed) ||
                    /^---+$/.test(trimmed) ||
                    /^\|.+\|$/.test(trimmed) ||
                    /^\*\*[^*]+\*\*:?$/.test(trimmed) ||
                    /^[A-Z][A-Z\s/&-]{3,}:?$/.test(trimmed)
                );
            };
            const endsLikeWrappedLine = (line) => {
                const trimmed = String(line || '').trim();
                if (!trimmed) {
                    return false;
                }

                return !/[.!?;:]$/.test(trimmed);
            };
            const startsLikeContinuation = (line) => {
                const trimmed = String(line || '').trim();
                if (!trimmed) {
                    return false;
                }

                return /^[a-zà-ÿ(€\d"'“‘]/i.test(trimmed);
            };

            const lines = normalized.split('\n');
            const rebuilt = [];

            lines.forEach((line, index) => {
                const trimmedLine = String(line || '').trim();
                if (!trimmedLine) {
                    rebuilt.push(protectedBreak);
                    return;
                }

                if (!rebuilt.length) {
                    rebuilt.push(trimmedLine);
                    return;
                }

                const previous = rebuilt.at(-1);
                const nextOriginal = lines[index + 1] || '';
                const shouldJoin =
                    previous !== protectedBreak &&
                    !startsNewBlock(trimmedLine) &&
                    endsLikeWrappedLine(previous) &&
                    startsLikeContinuation(trimmedLine) &&
                    !startsNewBlock(nextOriginal);

                if (shouldJoin) {
                    rebuilt[rebuilt.length - 1] = `${String(previous).trim()} ${trimmedLine}`;
                    return;
                }

                rebuilt.push(trimmedLine);
            });

            return rebuilt
                .join('\n')
                .replaceAll(new RegExp(String.raw`${protectedBreak}\n*`, 'g'), '\n')
                .replaceAll(/\n{2,}/g, '\n')
                .replaceAll(/[ \t]{2,}/g, ' ')
                .replaceAll(/\s+\n/g, '\n')
                .trim();
        },

        renderFormattedChunkHtml(text) {
            const sourceText = String(text || '').trim();
            if (!sourceText) {
                return '';
            }

            if (/<[a-z!/][^>]*>/i.test(sourceText)) {
                return this.sanitizeChunkHtml(sourceText);
            }

            return this.renderMarkdownChunkHtml(sourceText);
        },

        normalizeChunkDisplayText(text) {
            return String(text || '')
                .replaceAll(/\\([*_`[\]])/g, '$1')
                .replaceAll(/\\EUR\b/gi, 'EUR')
                .replaceAll(/(?:^|[\s(])EUR(?=\s*\d)/g, (match) => match.replace('EUR', '€'))
                .replaceAll(/(\d)\n(?=\d)/g, '$1 ')
                .replaceAll(/([^\s])\n(?=[^\s#>*-])/g, '$1 ')
                .replaceAll(/\n(?=[a-zà-ÿ])/gi, ' ')
                .replaceAll(/\s+\|/g, ' |')
                .replaceAll(/\|\s+/g, '| ')
                .replaceAll(/\s{2,}/g, ' ');
        },

        highlightMatchPhraseInHtml(html, matchPhrase) {
            const phrase = String(matchPhrase || '').trim();
            if (!html || !phrase) {
                return html;
            }

            const container = document.createElement('div');
            container.innerHTML = String(html || '');
            const normalizedPhrase = phrase.toLowerCase();
            const walker = document.createTreeWalker(container, NodeFilter.SHOW_TEXT);
            let matchedNode = null;

            /* eslint-disable no-cond-assign */
            while ((matchedNode = walker.nextNode())) {
                const rawText = String(matchedNode.textContent || '');
                const matchIndex = rawText.toLowerCase().indexOf(normalizedPhrase);
                if (matchIndex < 0) {
                    continue;
                }

                const range = document.createRange();
                range.setStart(matchedNode, matchIndex);
                range.setEnd(matchedNode, matchIndex + phrase.length);
                const highlight = document.createElement('mark');
                highlight.className = 'geweb-ai-inline-match';
                range.surroundContents(highlight);
                break;
            }
            /* eslint-enable no-cond-assign */

            return container.innerHTML;
        },

        sanitizeChunkHtml(html) {
            const allowed = new Set(['p', 'br', 'b', 'strong', 'i', 'em', 'ul', 'ol', 'li', 'a', 'code', 'pre']);
            const container = document.createElement('div');
            container.innerHTML = String(html || '');
            container.querySelectorAll('*').forEach((element) => {
                const tagName = element.tagName.toLowerCase();
                if (!allowed.has(tagName)) {
                    element.replaceWith(document.createTextNode(String(element.textContent || '')));
                    return;
                }

                Array.from(element.attributes).forEach((attribute) => {
                    if (tagName === 'a' && attribute.name === 'href') {
                        if (!/^https?:\/\//i.test(attribute.value)) {
                            element.removeAttribute('href');
                        }
                        return;
                    }

                    if (!['href', 'target', 'rel'].includes(attribute.name)) {
                        element.removeAttribute(attribute.name);
                    }
                });

                if (tagName === 'a') {
                    element.setAttribute('target', '_blank');
                    element.setAttribute('rel', 'noopener noreferrer');
                }
            });

            return container.innerHTML;
        },

        renderMarkdownChunkHtml(text) {
            const escaped = this.escapeHtml(
                this.normalizeChunkMarkdownText(
                    this.normalizeChunkDisplayText(this.decodeHtmlEntities(text))
                )
            );
            const lines = escaped.split(/\r?\n/);
            const parts = [];
            let listItems = [];

            const flushList = () => {
                if (!listItems.length) {
                    return;
                }

                parts.push(`<ul>${listItems.join('')}</ul>`);
                listItems = [];
            };

            lines.forEach((line) => {
                const trimmed = line.trim();
                if (!trimmed) {
                    flushList();
                    return;
                }

                const headingMatch = trimmed.match(/^(#{1,6})\s+(.+)$/);
                if (headingMatch) {
                    flushList();
                    const level = Math.min(6, headingMatch[1].length);
                    parts.push(`<h${level}>${this.applyInlineMarkdown(headingMatch[2])}</h${level}>`);
                    return;
                }

                if (/^-{3,}$/.test(trimmed)) {
                    flushList();
                    parts.push('<hr>');
                    return;
                }

                const listMatch = trimmed.match(/^[-*]\s+(.+)$/);
                if (listMatch) {
                    listItems.push(`<li>${this.applyInlineMarkdown(listMatch[1])}</li>`);
                    return;
                }

                flushList();
                parts.push(`<p>${this.applyInlineMarkdown(trimmed)}</p>`);
            });

            flushList();
            return parts.join('');
        },

        applyInlineMarkdown(text) {
            return String(text || '')
                .replaceAll(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
                .replaceAll(/__([^_]+)__/g, '<strong>$1</strong>')
                .replaceAll(/(^|[\s(])\*([^*]+)\*(?=[\s).,!?;:]|$)/g, '$1<em>$2</em>')
                .replaceAll(/(^|[\s(])_([^_]+)_(?=[\s).,!?;:]|$)/g, '$1<em>$2</em>')
                .replaceAll(/`([^`]+)`/g, '<code>$1</code>')
                .replaceAll(/(?:^|[\s(])EUR(?=\s*\d)/g, (match) => match.replace('EUR', '€'))
                .replaceAll(/\[([^\]]+)\]\((https?:\/\/[^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>')
                .replaceAll(/\[([^\]]+)\]\((#[^)]+)\)/g, '<a href="$2">$1</a>');
        },

        normalizeChunkMarkdownText(text) {
            return String(text || '')
                .replaceAll(/\r\n?/g, '\n')
                .replaceAll(/\s+---\s+/g, '\n---\n')
                .replaceAll(/\s+(#{1,6}\s+)/g, '\n$1')
                .replaceAll(/(\[[^\]]+]\([^)]+\))(?=[A-Z0-9#])/g, '$1\n')
                .replaceAll(/(\d+\.\s+[^\n]+)(?=\d+\.\s+)/g, '$1\n')
                .replaceAll(/([a-z0-9])(\[[^\]]+]\([^)]+\))/gi, '$1 $2')
                .replaceAll(/\n{3,}/g, '\n\n')
                .trim();
        },

        decorateAnswerWithGroundingFootnotes(answerHtml, meta, sourceFootnoteMap, sources) {
            const html = String(answerHtml || '');
            if (!html) {
                return '';
            }

            const grounding = this.getGroundingMetadata(meta);
            const supports = Array.isArray(grounding?.groundingSupports) ? grounding.groundingSupports : [];
            if (!supports.length) {
                return this.appendFallbackFootnoteGroup(html, this.getFallbackFootnoteNumbers(sourceFootnoteMap, sources));
            }

            const container = document.createElement('div');
            container.innerHTML = html;
            const blocks = Array.from(container.querySelectorAll('p, li')).filter((node) => String(node.textContent || '').trim() !== '');
            if (!blocks.length) {
                const allFootnotes = supports.reduce((accumulator, support) => {
                    const indices = Array.isArray(support?.groundingChunkIndices)
                        ? support.groundingChunkIndices.filter((value) => Number.isInteger(value) && value >= 0)
                        : [];
                    indices.forEach((index) => {
                        const localFootnote = index + 1;
                        const footnote = Number(sourceFootnoteMap?.[localFootnote]) || localFootnote;
                        if (!accumulator.includes(footnote)) {
                            accumulator.push(footnote);
                        }
                    });
                    return accumulator;
                }, []).sort((a, b) => a - b);

                this.appendFallbackFootnoteMarker(container, allFootnotes);
                return container.innerHTML;
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
                const insertionOffset = this.findExactFootnoteOffsetInBlock(matchedBlock, segmentText);
                indices.forEach((index) => {
                    const localFootnote = index + 1;
                    const footnote = Number(sourceFootnoteMap?.[localFootnote]) || localFootnote;
                    if (!existing.some((entry) => entry.footnote === footnote && entry.offset === insertionOffset)) {
                        existing.push({
                            footnote,
                            offset: insertionOffset,
                        });
                    }
                });
                blockFootnotes.set(matchedBlock, existing.sort((left, right) => left.offset === right.offset ? left.footnote - right.footnote : left.offset - right.offset));
            });

            blockFootnotes.forEach((placements, block) => {
                if (!placements.length) {
                    return;
                }
                this.insertFootnotesIntoBlock(block, placements);
            });

            if (!blockFootnotes.size) {
                const allFootnotes = supports.reduce((accumulator, support) => {
                    const indices = Array.isArray(support?.groundingChunkIndices)
                        ? support.groundingChunkIndices.filter((value) => Number.isInteger(value) && value >= 0)
                        : [];
                    indices.forEach((index) => {
                        const localFootnote = index + 1;
                        const footnote = Number(sourceFootnoteMap?.[localFootnote]) || localFootnote;
                        if (!accumulator.includes(footnote)) {
                            accumulator.push(footnote);
                        }
                    });
                    return accumulator;
                }, []).sort((a, b) => a - b);

                this.appendFallbackFootnoteMarker(container, allFootnotes);
            }

            return container.innerHTML;
        },

        findExactFootnoteOffsetInBlock(block, segmentText) {
            const rawText = String(block?.textContent || '');
            if (!rawText.trim()) {
                return 0;
            }

            const normalizedSegment = String(segmentText || '').replaceAll(/\s+/g, ' ').trim().toLowerCase();
            if (!normalizedSegment) {
                return rawText.length;
            }

            const lowerRawText = rawText.toLowerCase();
            let startIndex = lowerRawText.indexOf(normalizedSegment);
            let matchLength = normalizedSegment.length;

            if (startIndex < 0) {
                const fallbackToken = normalizedSegment
                    .split(/\s+/)
                    .find((token) => token.length >= 4 && lowerRawText.includes(token));
                if (!fallbackToken) {
                    return rawText.length;
                }
                startIndex = lowerRawText.indexOf(fallbackToken);
                matchLength = fallbackToken.length;
            }

            return Math.max(0, Math.min(rawText.length, startIndex + matchLength));
        },

        buildFootnoteGroupMarker(footnotes) {
            const marker = document.createElement('span');
            marker.className = 'geweb-ai-footnote-group';
            marker.innerHTML = footnotes.map((number) => `<sup class="geweb-ai-footnote-ref" data-footnote="${number}">[${number}]</sup>`).join('');
            return marker;
        },

        insertFootnotesIntoBlock(block, placements) {
            if (!block || !Array.isArray(placements) || !placements.length) {
                return;
            }

            const groupedPlacements = placements.reduce((map, placement) => {
                const offset = Number(placement?.offset ?? 0);
                const footnote = Number(placement?.footnote ?? 0);
                if (!Number.isInteger(footnote) || footnote <= 0) {
                    return map;
                }

                const normalizedOffset = Math.max(0, Math.min(String(block.textContent || '').length, Number.isFinite(offset) ? offset : 0));
                const existing = map.get(normalizedOffset) || [];
                if (!existing.includes(footnote)) {
                    existing.push(footnote);
                }
                map.set(normalizedOffset, existing.sort((a, b) => a - b));
                return map;
            }, new Map());

            const insertions = Array.from(groupedPlacements.entries()).sort((left, right) => right[0] - left[0]);
            insertions.forEach(([offset, footnotes]) => {
                const marker = this.buildFootnoteGroupMarker(footnotes);
                if (!this.insertNodeAtTextOffset(block, marker, offset)) {
                    block.appendChild(marker);
                }
            });
        },

        insertNodeAtTextOffset(root, node, targetOffset) {
            const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
            let currentNode = null;
            let traversed = 0;

            while ((currentNode = walker.nextNode())) {
                const textLength = String(currentNode.textContent || '').length;
                const nextTraversed = traversed + textLength;
                if (targetOffset > nextTraversed) {
                    traversed = nextTraversed;
                    continue;
                }

                const localOffset = Math.max(0, targetOffset - traversed);
                if (localOffset <= 0) {
                    currentNode.parentNode?.insertBefore(node, currentNode);
                    return true;
                }

                if (localOffset >= textLength) {
                    currentNode.parentNode?.insertBefore(node, currentNode.nextSibling);
                    return true;
                }

                const splitNode = currentNode.splitText(localOffset);
                splitNode.parentNode?.insertBefore(node, splitNode);
                return true;
            }

            return false;
        },

        getFallbackFootnoteNumbers(sourceFootnoteMap, sources) {
            const mappedFootnotes = Object.values(sourceFootnoteMap || {})
                .map((value) => Number(value || 0))
                .filter((value) => Number.isInteger(value) && value > 0);

            if (mappedFootnotes.length) {
                return [...new Set(mappedFootnotes)].sort((a, b) => a - b);
            }

            const sourceFootnotes = (Array.isArray(sources) ? sources : [])
                .map((source, index) => Number(source?.footnote || 0) || (index + 1))
                .filter((value) => Number.isInteger(value) && value > 0);

            return [...new Set(sourceFootnotes)].sort((a, b) => a - b);
        },

        appendFallbackFootnoteMarker(container, footnotes) {
            if (!container || !Array.isArray(footnotes) || !footnotes.length) {
                return;
            }

            const marker = document.createElement('p');
            marker.className = 'geweb-ai-footnote-group geweb-ai-footnote-group--fallback';
            marker.innerHTML = footnotes.map((number) => `<sup class="geweb-ai-footnote-ref" data-footnote="${number}">[${number}]</sup>`).join('');
            container.appendChild(marker);
        },

        appendFallbackFootnoteGroup(html, footnotes) {
            if (!Array.isArray(footnotes) || !footnotes.length) {
                return html;
            }

            const container = document.createElement('div');
            container.innerHTML = html;
            this.appendFallbackFootnoteMarker(container, footnotes);
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
                            contexts: this.buildSourceContexts(source),
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
                    registryEntry.contexts = this.mergeSourceContexts(registryEntry.contexts, this.buildSourceContexts(source));
                });
            });

            return registry;
        },

        buildSourceContexts(source) {
            if (Array.isArray(source?.contexts) && source.contexts.length) {
                return source.contexts
                    .filter((context) => context && typeof context === 'object')
                    .map((context) => {
                        const title = String(context.title || source?.title || '').trim();
                        const documentLabel = String(context.documentLabel || source?.documentLabel || this.extractDocumentLabelFromTitle(title)).trim();
                        const rawText = String(context.text || context.snippet || '').trim();
                        const text = documentLabel ? this.normalizeDocumentContextText(rawText) : rawText;
                        const url = String(context.url || source?.documentUrl || source?.url || '').trim();

                        return {
                            title,
                            text,
                            matchPhrase: this.extractContextMatchPhrase(text),
                            url,
                            documentLabel,
                        };
                    })
                    .filter((context) => context.title || context.text || context.url);
            }

            const snippet = String(source?.snippet || '').trim();
            if (!snippet) {
                return [];
            }

            const documentLabel = String(source?.documentLabel || this.extractDocumentLabelFromTitle(source?.title || '')).trim();
            const text = documentLabel ? this.normalizeDocumentContextText(snippet) : snippet;
            return [{
                title: String(source?.title || '').trim(),
                text,
                matchPhrase: this.extractContextMatchPhrase(text),
                url: String(source?.documentUrl || source?.url || '').trim(),
                documentLabel,
            }];
        },

        mergeSourceContexts(existingContexts, nextContexts) {
            const merged = Array.isArray(existingContexts) ? existingContexts.slice() : [];
            const seen = new Set(merged.map((context) => `${String(context?.title || '')}||${String(context?.text || '')}||${String(context?.url || '')}`));

            (Array.isArray(nextContexts) ? nextContexts : []).forEach((context) => {
                const key = `${String(context?.title || '')}||${String(context?.text || '')}||${String(context?.url || '')}`;
                if (!key.trim() || seen.has(key)) {
                    return;
                }

                seen.add(key);
                merged.push(context);
            });

            return merged;
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
            const requestExcludedSources = typeof this.getExcludedSourcesForRequest === 'function'
                ? this.getExcludedSourcesForRequest()
                : [];
            const excludedCount = requestExcludedSources.length;

            if (!normalizedSources.length) {
                this.$sourcesBox.append($('<p class="geweb-ai-empty-panel"></p>').text(t('noSourcesYet', 'No source links yet.')));
                return;
            }

            if (excludedCount > 0 && typeof this.clearTemporarilyExcludedSources === 'function') {
                const $toolbar = $('<div class="geweb-ai-source-filter-toolbar"></div>');
                const summaryText = excludedCount === 1
                    ? t('oneSourceTemporarilyExcluded', '1 source temporarily excluded for the next question.')
                    : t('multipleSourcesTemporarilyExcluded', `${excludedCount} sources temporarily excluded for the next question.`).replace('%d', String(excludedCount));
                const $summary = $('<div class="geweb-ai-source-filter-summary"></div>').text(summaryText);
                const $resetButton = $('<button type="button" class="button button-small geweb-ai-source-filter-reset"></button>')
                    .text(t('useAllSourcesAgain', 'Use all sources again'))
                    .attr('title', t('useAllSourcesAgain', 'Use all sources again'))
                    .on('click', (event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        this.clearTemporarilyExcludedSources();
                    });

                $toolbar.append($summary, $resetButton);
                this.$sourcesBox.append($toolbar);
            }

            const $list = $('<ol class="geweb-ai-source-list"></ol>');
            normalizedSources.forEach((source) => {
                $list.append(this.buildSourceListItem(source));
            });

            this.$sourcesBox.append($list);
            this.hydrateResolvedSourceReferences($list, normalizedSources);
        },

        buildSourceListItem(source) {
            const title = this.getPreferredSourceLabel(source.title, source.url);
            const url = source.url;
            const footnote = Number(source.footnote || 0);
            const snippet = String(source.snippet || '').trim();
            const contexts = Array.isArray(source.contexts) ? source.contexts : [];
            const previewSnippet = snippet || String(contexts[0]?.text || '').trim();
            const sourceLabel = title || url || `Source ${footnote || ''}`.trim();
            const referenceHint = this.getManagedSourceReferenceHint(url);
            const firstMatchPhrase = contexts[0]?.matchPhrase || '';
            const sourceKind = this.getSourceKind(source);
            const sourceIcon = this.getSourceIcon(source);
            const $item = $('<li></li>');
            const $itemHeader = $('<div class="geweb-ai-source-item-header"></div>');
            const $toggle = $('<button type="button" class="geweb-ai-source-link geweb-ai-source-toggle"></button>');
            const $number = $('<span class="geweb-ai-source-item-number" aria-hidden="true"></span>').text(`${footnote || ''}`);
            const isExcluded = typeof this.isSourceTemporarilyExcluded === 'function'
                ? this.isSourceTemporarilyExcluded(source)
                : false;

            $toggle.attr('aria-label', `Show context for ${sourceLabel}`);
            $toggle.attr('title', sourceLabel);
            $toggle.append($('<span class="geweb-ai-source-link-icon" aria-hidden="true"></span>').text(sourceIcon));
            $toggle.append($('<span class="geweb-ai-source-link-label"></span>').text(title));
            if (referenceHint) {
                $toggle.append($('<span class="geweb-ai-source-link-hint"></span>').text(referenceHint));
            }
            this.applySourceItemAttributes($item, { footnote, title, snippet, previewSnippet, url, contexts });
            $item.attr('data-source-kind', sourceKind);
            this.bindSourceToggleInteractions($toggle, $item, url, firstMatchPhrase, $itemHeader, $number);
            if (isExcluded) {
                $item.addClass('is-excluded');
            }

            $itemHeader.append(this.buildSourceExcludeToggle(source, sourceLabel, isExcluded));
            $itemHeader.append($number);
            $itemHeader.append($toggle);
            $item.append($itemHeader);

            const $details = this.buildSourceDetails(url, contexts, previewSnippet, referenceHint, $item, footnote);
            if ($details.children().length) {
                $item.append($details);
            }

            return $item;
        },

        buildSourceExcludeToggle(source, sourceLabel, isExcluded) {
            const includeTitle = t('includeSourceAgainTitle', 'Allow this source again for the next question');
            const excludeTitle = t('excludeSourceTemporarilyTitle', 'Temporarily exclude this source from the next question');
            const checkboxId = `geweb-ai-source-filter-${String(source?.footnote || sourceLabel || 'source')
                .toLowerCase()
                .replaceAll(/[^a-z0-9]+/g, '-')}`;

            const $wrapper = $('<label class="geweb-ai-source-filter-toggle"></label>');
            const $checkbox = $('<input type="checkbox" class="geweb-ai-source-filter-checkbox" />');
            const nextTitle = isExcluded ? includeTitle : excludeTitle;

            $wrapper.attr('for', checkboxId);
            $wrapper.attr('title', `${nextTitle}: ${sourceLabel}`);
            $wrapper.attr('aria-label', `${nextTitle}: ${sourceLabel}`);
            $wrapper.toggleClass('is-active', isExcluded);

            $checkbox.attr('id', checkboxId);
            $checkbox.prop('checked', !isExcluded);
            $checkbox.attr('title', `${nextTitle}: ${sourceLabel}`);
            $checkbox.on('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                if (typeof this.toggleSourceTemporarilyExcluded === 'function') {
                    this.toggleSourceTemporarilyExcluded(source);
                }
            });
            $checkbox.on('keydown', (event) => {
                event.stopPropagation();
            });

            $wrapper.append($checkbox);
            return $wrapper;
        },

        applySourceItemAttributes($item, sourceInfo) {
            const { footnote, title, snippet, previewSnippet, url, contexts } = sourceInfo;
            if (footnote > 0) {
                $item.attr('data-source-footnote', `${footnote}`);
            }
            if (title) {
                $item.attr('data-source-title', title);
            }
            if (snippet) {
                $item.attr('data-source-snippet', snippet);
            }
            if (!snippet && previewSnippet) {
                $item.attr('data-source-snippet', previewSnippet);
            }
            if (url) {
                $item.attr('data-source-url', url);
            }
            if (contexts.length) {
                $item.attr('data-source-context-count', `${contexts.length}`);
                $item.attr('data-source-match-phrases', JSON.stringify(contexts.map((context) => String(context?.matchPhrase || '')).filter(Boolean)));
            }
        },

	        bindSourceToggleInteractions($toggle, $item, url, matchPhrase, $itemHeader = null, $number = null) {
	            const activateSource = (event) => {
	                event.preventDefault();
	                event.stopPropagation();
	                this.setScrollablePaneActive?.('sources');
	                this.ensureSourcesPanelVisible();
	                this.activateSourceReferenceItem($item);
	            };

	            const handleSourceToggle = (event) => {
	                if ($item.hasClass('is-active') && url) {
	                    event.preventDefault();
                    event.stopPropagation();
                    this.openSourceDestination(url, matchPhrase);
                    return;
                }

	                activateSource(event);
	            };
	            $toggle.on('click', handleSourceToggle);
	            $toggle.on('keydown', (event) => {
	                if (event.key !== 'Enter' && event.key !== ' ') {
	                    return;
	                }

	                handleSourceToggle(event);
	            });
	            if ($number?.length) {
	                $number.attr('role', 'button');
	                $number.attr('tabindex', '0');
	                $number.on('click', handleSourceToggle);
	                $number.on('keydown', (event) => {
	                    if (event.key !== 'Enter' && event.key !== ' ') {
	                        return;
	                    }

	                    handleSourceToggle(event);
	                });
	            }
	            if ($itemHeader?.length) {
	                $itemHeader.on('click', (event) => {
	                    if ($(event.target).closest('.geweb-ai-source-filter-toggle, .geweb-ai-source-toggle, .geweb-ai-source-item-number').length) {
	                        return;
	                    }

	                    handleSourceToggle(event);
	                });
	            }
	        },

        buildSourceDetails(url, contexts, previewSnippet, referenceHint, $sourceItem, footnote = 0) {
            const $details = $('<div class="geweb-ai-source-details"></div>');
            const $detailsHeader = $('<div class="geweb-ai-source-details-header"></div>');
            const $closeButton = $('<button type="button" class="geweb-ai-source-details-close" aria-label="Close source context" title="Close source context"></button>');
            $closeButton.append($('<span aria-hidden="true">×</span>'));
            $closeButton.on('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                this.deactivateSourceReferenceItems();
            });
            $detailsHeader.append($closeButton);
            $details.append($detailsHeader);

            const $contexts = contexts.length > 1
                ? this.buildSourceContextList(url, contexts, $sourceItem, footnote)
                : this.buildSingleSourceSnippet(url, contexts[0], previewSnippet, $sourceItem, footnote);

            if ($contexts) {
                $details.append($contexts);
            };

            return $details;
        },

        buildSourceContextList(url, contexts, $sourceItem, footnote = 0) {
            const $contextList = $('<ol class="geweb-ai-source-context-list"></ol>');
            contexts.forEach((context, contextIndex) => {
                const $contextItem = this.buildSourceContextItem(url, context, contextIndex, $sourceItem, footnote);
                if ($contextItem) {
                    $contextList.append($contextItem);
                }
            });

            return $contextList.children().length ? $contextList : null;
        },

        buildSourceContextItem(url, context, contextIndex, $sourceItem, footnote = 0) {
            const contextText = String(context?.text || '').trim();
            if (!contextText) {
                return null;
            }

            const matchPhrase = String(context?.matchPhrase || '');
            const targetUrl = String(context?.url || url || '').trim();
            const documentLabel = String(context?.documentLabel || '').trim();
            const $contextItem = $('<li class="geweb-ai-source-context-item"></li>');
            const $contextLabel = $('<div class="geweb-ai-source-context-label"></div>').text(t('groundingContextTitle', 'Context'));
            const $footnoteBadge = this.buildSourceContextFootnoteBadge(footnote);
            if ($footnoteBadge) {
                $contextLabel.append($footnoteBadge);
            }
            $contextItem.append($contextLabel);
            if (documentLabel) {
                $contextItem.append($('<div class="geweb-ai-source-context-document"></div>').text(documentLabel));
            }
            if (matchPhrase) {
                $contextItem.attr('data-source-match-phrase', matchPhrase);
            }

            this.bindSourceContentInteraction($contextItem, targetUrl, matchPhrase, $sourceItem);
            $contextItem.append($('<div class="geweb-ai-source-snippet"></div>').html(
                this.highlightMatchPhraseInHtml(this.renderFormattedChunkHtml(contextText), matchPhrase)
            ));
            return $contextItem;
        },

        buildSingleSourceSnippet(url, context, previewSnippet, $sourceItem, footnote = 0) {
            const snippetText = String(context?.text || previewSnippet || '').trim();
            if (!snippetText) {
                return null;
            }

            const matchPhrase = String(context?.matchPhrase || '');
            const targetUrl = String(context?.url || url || '').trim();
            const documentLabel = String(context?.documentLabel || '').trim();
            const $wrapper = $('<div class="geweb-ai-source-single-context"></div>');
            const $contextLabel = $('<div class="geweb-ai-source-context-label"></div>').text(t('groundingContextTitle', 'Context'));
            const $footnoteBadge = this.buildSourceContextFootnoteBadge(footnote);
            if ($footnoteBadge) {
                $contextLabel.append($footnoteBadge);
            }
            $wrapper.append($contextLabel);
            if (documentLabel) {
                $wrapper.append($('<div class="geweb-ai-source-context-document"></div>').text(documentLabel));
            }
            const $snippet = $('<div class="geweb-ai-source-snippet"></div>').html(
                this.highlightMatchPhraseInHtml(this.renderFormattedChunkHtml(snippetText), matchPhrase)
            );
            if (matchPhrase) {
                $snippet.attr('data-source-match-phrase', matchPhrase);
            }

            this.bindSourceContentInteraction($snippet, targetUrl, matchPhrase, $sourceItem);
            $wrapper.append($snippet);
            return $wrapper;
        },

        buildSourceContextFootnoteBadge(footnote) {
            const footnoteNumber = Number(footnote || 0);
            if (!Number.isInteger(footnoteNumber) || footnoteNumber <= 0) {
                return null;
            }

            return $('<span class="geweb-ai-source-context-footnote"></span>').text(`[${footnoteNumber}]`);
        },

	        bindSourceContentInteraction($element, url, matchPhrase, $sourceItem) {
	            if (!url) {
	                return;
	            }

	            const activateOrOpen = (event) => {
	                this.setScrollablePaneActive?.('sources');
	                if (!$sourceItem?.length || !$sourceItem.hasClass('is-active')) {
	                    event.preventDefault();
	                    event.stopPropagation();
	                    if ($sourceItem?.length) {
                        this.activateSourceReferenceItem($sourceItem);
                    }
                    return;
                }

                this.openSourceDestination(url, matchPhrase);
            };

            $element.attr('tabindex', '0');
            $element.attr('role', 'link');
            $element.attr('title', matchPhrase ? `Open at first match: ${matchPhrase}` : 'Open source');
            $element.on('click', activateOrOpen);
            $element.on('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    activateOrOpen(event);
                }
            });
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
                this.hydrateResolvedSourceReferenceItem($(item));
            });
        },

        hydrateResolvedSourceReferenceItem($item) {
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
                $item.attr('data-source-title', nextLabel);
                $item.find('.geweb-ai-source-toggle').first().attr('title', nextLabel);
            }
            if (!nextUrl) {
                return;
            }

            const nextHint = this.getManagedSourceReferenceHint(nextUrl);
            $item.attr('data-source-url', nextUrl);
            this.hydrateResolvedSourceReferenceHint($item, nextHint);
        },

        hydrateResolvedSourceReferenceHint($item, nextHint) {
            const $hint = $item.find('.geweb-ai-source-link-hint').first();
            if (!nextHint) {
                $hint.remove();
                return;
            }

            if ($hint.length) {
                $hint.text(nextHint);
                return;
            }

            $item.find('.geweb-ai-source-link-label').after(
                $('<span class="geweb-ai-source-link-hint"></span>').text(nextHint)
            );
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
                const documentInfo = this.resolveGroundingChunkDocumentInfo(chunk, explicitSources);
                const dedupeKey = normalizedUrl || `chunk:${index}:${String(chunk.title || '').trim()}`;
                if (!dedupeKey || seen.has(dedupeKey.toLowerCase())) {
                    return accumulator;
                }

                seen.add(dedupeKey.toLowerCase());
                const rawContextText = String(chunk.rawText || chunk.text || '');
                const contextText = documentInfo.documentLabel
                    ? this.normalizeDocumentContextText(rawContextText)
                    : rawContextText;
                accumulator.push({
                    title: chunk.title || `Source ${index + 1}`,
                    url: normalizedUrl,
                    documentUrl: documentInfo.documentUrl,
                    documentLabel: documentInfo.documentLabel,
                    snippet: chunk.text || '',
                    footnote: index + 1,
                    contexts: [{
                        title: chunk.title || `Source ${index + 1}`,
                        text: contextText,
                        matchPhrase: this.extractContextMatchPhrase(contextText),
                        url: documentInfo.documentUrl || normalizedUrl,
                        documentLabel: documentInfo.documentLabel,
                    }],
                });
                return accumulator;
            }, []);
        },

        resolveGroundingChunkDocumentInfo(chunk, sources) {
            const title = String(chunk?.title || '').trim();
            const documentLabel = this.extractDocumentLabelFromTitle(title);
            if (!documentLabel) {
                return { documentLabel: '', documentUrl: '' };
            }

            const explicitSources = Array.isArray(sources) ? sources : [];
            for (const source of explicitSources) {
                const sourceUrl = this.normalizeManagedSourceUrl(source?.url || '');
                const sourceTitle = String(source?.title || '').trim();
                if (!sourceUrl) {
                    continue;
                }

                if (sourceTitle && sourceTitle.toLowerCase() === documentLabel.toLowerCase()) {
                    return { documentLabel, documentUrl: sourceUrl };
                }

                if (sourceUrl.toLowerCase().includes(`/${documentLabel.toLowerCase()}`)) {
                    return { documentLabel, documentUrl: sourceUrl };
                }
            }

            return { documentLabel, documentUrl: '' };
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
            const normalizedTitle = String(title || '').trim();
            const pageIdFromMarkdown = /^\d+\.md$/i.test(normalizedTitle)
                ? normalizedTitle.replace(/\.md$/i, '')
                : '';
            const pageIdFromPrefixedDocument = pageIdFromMarkdown === ''
                ? (/^(\d+)-.+\.[a-z0-9]{2,8}$/i.exec(normalizedTitle)?.[1] || '')
                : '';
            const pageId = pageIdFromMarkdown || pageIdFromPrefixedDocument;

            if (!pageId) {
                return '';
            }

            return `${String(getAiSearchConfig().site_url || globalThis.location?.origin || '').replace(TRAILING_SLASH_REGEX, '')}/?p=${pageId}`;
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

	        highlightSourceReference(footnote, options = {}) {
	            if (!this.$sourcesBox.length) {
	                return;
	            }

            const $items = this.$sourcesBox.find('[data-source-footnote]');
            const $target = $items.filter(`[data-source-footnote="${footnote}"]`).first();
            if (!$target.length) {
                return;
            }

	            this.setScrollablePaneActive?.('sources');
	            this.ensureSourcesPanelVisible();
	            this.activateSourceReferenceItem($target);
	            this.highlightBestSourceContext($target, 'active');

	            const element = $target.get(0);
	            if (element && typeof element.scrollIntoView === 'function') {
	                element.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
	            }
        },

        ensureSourcesPanelVisible() {
            const workspace = this.$sourcesBox.closest('.geweb-ai-workspace').get(0);
            if (globalThis.GewebAIModal && typeof globalThis.GewebAIModal.isMobileWorkspaceNavigationActive === 'function' && globalThis.GewebAIModal.isMobileWorkspaceNavigationActive(workspace)) {
                globalThis.GewebAIModal.setMobileWorkspacePane('right', { focusPane: true });
                globalThis.GewebAIModal.syncPanelCollapseButtons();
                return;
            }

            if (!workspace?.classList.contains('is-right-collapsed')) {
                return;
            }

            workspace.classList.remove('is-right-collapsed');
            if (globalThis.GewebAIModal && typeof globalThis.GewebAIModal.syncPanelCollapseButtons === 'function') {
                globalThis.GewebAIModal.syncPanelCollapseButtons();
            }
        },

        activateSourceReferenceItem($target) {
            if (!$target?.length || !this.$sourcesBox.length) {
                return;
            }

            const $items = this.$sourcesBox.find('li');
            $items.removeClass('is-active is-preview');
            this.clearSourceContextHighlights();
            $target.addClass('is-active');
        },

        previewSourceReferenceItem($target) {
            if (!$target?.length || !this.$sourcesBox.length) {
                return;
            }

            const $items = this.$sourcesBox.find('li').not('.is-active');
            $items.removeClass('is-preview');
            this.clearSourceContextHighlights('preview');
            if (!$target.hasClass('is-active')) {
                $target.addClass('is-preview');
            }
        },

        clearSourceReferencePreview($target) {
            if (!$target?.length || !$target.hasClass('is-preview') || $target.hasClass('is-active')) {
                return;
            }

            $target.removeClass('is-preview');
        },

        clearAllSourceReferencePreviews() {
            if (!this.$sourcesBox.length) {
                return;
            }

            this.$sourcesBox.find('li.is-preview').removeClass('is-preview');
            this.clearSourceContextHighlights('preview');
        },

        deactivateSourceReferenceItems() {
            if (!this.$sourcesBox.length) {
                return;
            }

            this.$sourcesBox.find('li').removeClass('is-active is-preview');
            this.clearSourceContextHighlights();
        },

        highlightBestSourceContext($sourceItem, mode = 'active') {
            if (!$sourceItem?.length) {
                return;
            }

            const $contexts = $sourceItem.find('.geweb-ai-source-context-item, .geweb-ai-source-single-context');
            if (!$contexts.length) {
                return;
            }

            const previewOnly = mode === 'preview';
            this.clearSourceContextHighlights(previewOnly ? 'preview' : null);
            const $bestMatch = this.findBestSourceContextElement($sourceItem, $contexts);
            if (!$bestMatch?.length) {
                return;
            }

            $bestMatch.addClass(previewOnly ? 'is-context-preview' : 'is-context-active');
            if (previewOnly) {
                return;
            }

            const element = $bestMatch.get(0);
            if (element && typeof element.scrollIntoView === 'function') {
                element.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            }
        },

        clearSourceContextHighlights(mode = null) {
            if (!this.$sourcesBox.length) {
                return;
            }

            const $contexts = this.$sourcesBox.find('.geweb-ai-source-context-item, .geweb-ai-source-single-context');
            if (mode === 'preview') {
                $contexts.removeClass('is-context-preview');
                return;
            }

            $contexts.removeClass('is-context-preview is-context-active');
        },

        findBestSourceContextElement($sourceItem, $contexts) {
            if (!$contexts?.length) {
                return $();
            }

            if ($contexts.length === 1) {
                return $contexts.first();
            }

            const preferredSnippet = this.normalizeSourceContextText($sourceItem.attr('data-source-snippet') || '');
            const preferredPhrases = this.parseSourceMatchPhrases($sourceItem.attr('data-source-match-phrases'));
            let bestScore = -1;
            let $bestMatch = $contexts.first();

            $contexts.each((index, element) => {
                const $context = $(element);
                const contextText = this.normalizeSourceContextText($context.text());
                const matchPhrase = this.normalizeSourceContextText($context.attr('data-source-match-phrase') || '');
                let score = 0;

                if (preferredSnippet && contextText) {
                    if (contextText.includes(preferredSnippet) || preferredSnippet.includes(contextText)) {
                        score += 8;
                    } else {
                        score += this.computeSourceTextOverlapScore(preferredSnippet, contextText);
                    }
                }

                if (matchPhrase) {
                    score += 3;
                }

                if (matchPhrase && preferredPhrases.includes(matchPhrase)) {
                    score += 6;
                }

                score -= index * 0.01;

                if (score > bestScore) {
                    bestScore = score;
                    $bestMatch = $context;
                }
            });

            return $bestMatch;
        },

        parseSourceMatchPhrases(rawPhrases) {
            if (!rawPhrases) {
                return [];
            }

            try {
                const phrases = JSON.parse(rawPhrases);
                if (!Array.isArray(phrases)) {
                    return [];
                }

                return phrases
                    .map((phrase) => this.normalizeSourceContextText(phrase))
                    .filter(Boolean);
            } catch (error) {
                return [];
            }
        },

        normalizeSourceContextText(value) {
            return String(value || '')
                .replaceAll(/\s+/g, ' ')
                .trim()
                .toLowerCase();
        },

        computeSourceTextOverlapScore(left, right) {
            if (!left || !right) {
                return 0;
            }

            const leftTokens = [...new Set(left.split(' ').filter((token) => token.length > 3))];
            if (!leftTokens.length) {
                return 0;
            }

            return leftTokens.reduce((score, token) => (
                right.includes(token) ? score + 1 : score
            ), 0);
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
                return `page ${postId}`;
            }

            return parsed.search.replace(/^\?/, '');
        },

        getManagedSourceReferenceHint(url) {
            const normalizedUrl = this.normalizeManagedSourceUrl(url);
            if (!normalizedUrl) {
                return '';
            }

            const parsed = safeParseUrl(normalizedUrl);
            if (!parsed) {
                return '';
            }

            const pageId = String(parsed.searchParams.get('page_id') || parsed.searchParams.get('p') || '').trim();
            return /^\d+$/.test(pageId) ? `[page ${pageId}]` : '';
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
