(function($) {
	const MANAGED_TITLE_URL_REGEX_SUFFIX = String.raw`[^\n]*?(https?:\/\/[^\s<>"']+)`;
	const TRAILING_SLASH_REGEX = /\/?$/;
	const {
		ensureSearchNonce = () => Promise.reject(new Error('AI search shared helpers are not available.')),
		getAiSearchConfig = () => (globalThis.geweb_aisearch ?? {}),
		t: translate = (_key, fallback) => fallback,
	} = globalThis.GewebAISearchShared || {};
	const {
		appendGroundingPlacementEntries = () => {},
		normalizeManagedHost = (hostname) => String(hostname || '').trim().toLowerCase().replace(/^www\./, ''),
		normalizeObject = (value) => (value && typeof value === 'object' ? value : {}),
		safeParseUrl = () => null,
	} = globalThis.GewebAISearchSourceUtils || {};

	function getI18nValue(key) {
		return getAiSearchConfig().i18n?.[key];
	}

	function t(key, fallback) {
		const translated = getI18nValue(key) || translate(key, fallback);
		if (typeof translated === 'string' && translated.trim() !== '') {
			return translated;
		}

		return fallback;
	}

	globalThis.GewebAISearchSourceMethods = globalThis.GewebAISearchSourceMethods || {};
	Object.assign(globalThis.GewebAISearchSourceMethods, {
		sourceReferenceCache: {},

		getSourceDestination(url, matchPhrase) {
			let normalizedUrl = this.normalizeManagedSourceUrl(url);
			if (!normalizedUrl) {
				return null;
			}

			const resolvedReference = this.sourceReferenceCache?.[normalizedUrl];
			if (resolvedReference?.url) {
				normalizedUrl = resolvedReference.url;
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

		escapeAttr(text) {
			return this.escapeHtml(text);
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

		normalizeContextCandidateLine(text) {
			return this.stripHtmlAndMarkdown(String(text || ''))
				.replaceAll(/\s+/g, ' ')
				.trim();
		},

		isMetadataContextLine(text) {
			const normalized = String(text || '').trim();
			if (!normalized) {
				return true;
			}

			return /^---+$/.test(normalized)
				|| /^(url|title|page_id)\s*:/i.test(normalized)
				|| /^[\w.-]+\.(md|json|txt)$/i.test(normalized)
				|| /^#\s+/.test(normalized)
				|| /^pagina\s+\d+\b/i.test(normalized)
				|| /^\((?:https?:\/\/|www\.)/i.test(normalized)
				|| /^(https?:\/\/|www\.)/i.test(normalized);
		},

		extractMeaningfulContextLine(text) {
			const decoded = this.decodeHtmlEntities(String(text || '')).replaceAll(/\r\n?/g, '\n');
			if (!decoded.trim()) {
				return '';
			}

			const lines = decoded
				.replace(/^---\s*\n[\s\S]*?\n---\s*\n?/u, '')
				.split('\n')
				.map((line) => this.normalizeContextCandidateLine(line))
				.filter(Boolean)
				.filter((line) => !this.isMetadataContextLine(line));
			if (!lines.length) {
				return this.normalizeContextCandidateLine(decoded);
			}

			const preferredLine = lines.find((line) => {
				const wordCount = line.split(/\s+/).filter(Boolean).length;
				return wordCount >= 5 && /[,.;:]/.test(line);
			});
			if (preferredLine) {
				return preferredLine;
			}

			const firstSubstantialLine = lines.find((line) => line.split(/\s+/).filter(Boolean).length >= 5);
			return firstSubstantialLine || lines[0] || '';
		},

		extractContextMatchPhrase(text) {
			const normalized = this.extractMeaningfulContextLine(text);
			if (!normalized) {
				return '';
			}

			const words = normalized.split(/\s+/).filter(Boolean);
			if (!words.length) {
				return '';
			}

			return words.slice(0, Math.min(6, words.length)).join(' ');
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
				// eslint-disable-next-line no-console
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

			const denseReferenceHtml = this.renderDenseReferenceChunkHtml(sourceText);
			if (denseReferenceHtml) {
				return denseReferenceHtml;
			}

			return this.renderMarkdownChunkHtml(sourceText);
		},

		renderDenseReferenceChunkHtml(text) {
			const sourceText = String(text || '').trim();
			const matches = this.extractDenseReferenceMatches(sourceText);
			if (matches.length < 2) {
				return '';
			}

			let nextPrefix = this.normalizeDenseReferenceText(sourceText.slice(0, Number(matches[0]?.index || 0)));
			const rows = matches.map((match, index) => {
				const label = String(match[1] || '').trim();
				const url = String(match[2] || '').trim();
				const currentIndex = Number(match.index || 0);
				const currentEnd = currentIndex + Number(match.matchLength || String(match[0] || '').length);
				const nextIndex = index + 1 < matches.length ? Number(matches[index + 1].index || sourceText.length) : sourceText.length;
				const split = this.splitDenseReferenceSegment(sourceText.slice(currentEnd, nextIndex), index + 1 < matches.length);
				const row = {
					prefix: this.escapeHtml(this.normalizeChunkDisplayText(nextPrefix)),
					label: this.escapeHtml(label),
					url: this.escapeAttr(url),
					suffix: this.escapeHtml(this.normalizeChunkDisplayText(split.currentText)),
				};
				nextPrefix = this.normalizeDenseReferenceText(split.nextPrefix);
				return row;
			}).filter(Boolean);

			const nonEmptyPrefixCount = rows.filter((row) => row.prefix).length;
			const nonEmptySuffixCount = rows.filter((row) => row.suffix).length;
			if (rows.length >= 2 && (nonEmptyPrefixCount >= 2 || nonEmptySuffixCount >= 2)) {
				const body = rows.map((row) => `
					<tr>
						<td class="geweb-ai-source-reference-cell geweb-ai-source-reference-cell--prefix">${row.prefix || ''}</td>
						<td class="geweb-ai-source-reference-cell geweb-ai-source-reference-cell--link"><a href="${row.url}" target="_blank" rel="noopener noreferrer">${row.label}</a></td>
						<td class="geweb-ai-source-reference-cell geweb-ai-source-reference-cell--suffix">${row.suffix || ''}</td>
					</tr>
				`).join('');

				return `<div class="geweb-ai-source-reference-table-wrap"><table class="geweb-ai-source-reference-table"><tbody>${body}</tbody></table></div>`;
			}

			const listItems = rows.map((row) => {
				const metaParts = [row.prefix, row.suffix].filter(Boolean);
				const metaHtml = metaParts.length
					? `<div class="geweb-ai-source-reference-meta">${metaParts.join(' · ')}</div>`
					: '';

				return `<li class="geweb-ai-source-reference-item"><a href="${row.url}" target="_blank" rel="noopener noreferrer">${row.label}</a>${metaHtml}</li>`;
			});

			return listItems.length ? `<ul class="geweb-ai-source-reference-list">${listItems.join('')}</ul>` : '';
		},

		extractDenseReferenceMatches(text) {
			const sourceText = String(text || '');
			const markdownMatches = Array.from(sourceText.matchAll(/\[([^\]]+)\]\((https?:\/\/[^)]+)\)/g)).map((match) => ({
				0: String(match[0] || ''),
				1: String(match[1] || '').trim(),
				2: String(match[2] || '').trim(),
				index: Number(match.index || 0),
				matchLength: String(match[0] || '').length,
			}));
			const occupiedRanges = markdownMatches.map((match) => [
				Number(match.index || 0),
				Number(match.index || 0) + Number(match.matchLength || 0),
			]);
			const rawMatches = Array.from(sourceText.matchAll(/https?:\/\/[^\s<>"')\]]+/g)).reduce((items, match) => {
				const url = String(match[0] || '').trim();
				const index = Number(match.index || 0);
				const overlapsMarkdown = occupiedRanges.some(([start, stop]) => index >= start && index < stop);
				if (!url || overlapsMarkdown) {
					return items;
				}

				items.push({
					0: url,
					1: this.getDenseReferenceLabelFromUrl(url),
					2: url,
					index,
					matchLength: url.length,
				});
				return items;
			}, []);

			return [...markdownMatches, ...rawMatches]
				.sort((left, right) => Number(left.index || 0) - Number(right.index || 0));
		},

		getDenseReferenceLabelFromUrl(url) {
			const normalizedUrl = String(url || '').trim();
			if (!normalizedUrl) {
				return '';
			}

			try {
				const parsed = new URL(normalizedUrl);
				const lastPathSegment = String(parsed.pathname || '')
					.split('/')
					.findLast(Boolean) || normalizedUrl;
				return decodeURIComponent(lastPathSegment);
			} catch {
				return normalizedUrl.split('/').findLast(Boolean) || normalizedUrl;
			}
		},

		normalizeDenseReferenceText(text) {
			return String(text || '')
				.replaceAll(/\r\n?/g, ' ')
				.replaceAll(/([a-z])(\d{2,4}[-/]\d{2}[-/]\d{2,4})/gi, '$1 $2')
				.replaceAll(/(\d)([A-ZÀ-Ÿ])/g, '$1 $2')
				.replaceAll(/([a-zà-ÿ])([A-ZÀ-Ÿ])/g, '$1 $2')
				.replaceAll(/\s{2,}/g, ' ')
				.replaceAll(/^[·,:;|/\-–—\s]+|[·,:;|/\-–—\s]+$/g, '')
				.trim();
		},

		splitDenseReferenceSegment(segment, hasFollowingRow) {
			const rawSegment = String(segment || '').replaceAll(/\r\n?/g, ' ').trim();
			if (!rawSegment || !hasFollowingRow) {
				return {
					currentText: rawSegment,
					nextPrefix: '',
				};
			}

			const boundaryPatterns = [
				/\b\d{4}-\d{2}-\d{2}\b/g,
				/\b\d{2}-\d{2}-\d{4}\b/g,
				/\b\d{8}\b/g,
				/\b\d{4}\b(?=[-/.]\d{2}[-/.]\d{2}\b)/g,
			];
			let boundaryIndex = -1;

			boundaryPatterns.forEach((pattern) => {
				const matches = [...rawSegment.matchAll(pattern)];
				const lastMatch = matches.at(-1);
				if (lastMatch && typeof lastMatch.index === 'number' && lastMatch.index > boundaryIndex) {
					boundaryIndex = lastMatch.index;
				}
			});

			if (boundaryIndex <= 0) {
				return {
					currentText: rawSegment,
					nextPrefix: '',
				};
			}

			return {
				currentText: rawSegment.slice(0, boundaryIndex).trim(),
				nextPrefix: rawSegment.slice(boundaryIndex).trim(),
			};
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
			const normalizedMarkdown = this.normalizeChunkMarkdownText(
				this.normalizeChunkDisplayText(this.decodeHtmlEntities(text))
			);
			if (typeof globalThis.GewebAisearchMarkdown?.render === 'function') {
				return globalThis.GewebAisearchMarkdown.render(normalizedMarkdown);
			}

			const escaped = this.escapeHtml(normalizedMarkdown);
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

			let lineIndex = 0;
			while (lineIndex < lines.length) {
				const line = lines[lineIndex];
				const trimmed = line.trim();
				if (!trimmed) {
					flushList();
					lineIndex += 1;
					continue;
				}

				if (this.isMarkdownTableStart(lines, lineIndex)) {
					flushList();
					const table = this.buildMarkdownTableHtml(lines, lineIndex);
					if (table) {
						parts.push(table.html);
						lineIndex = table.nextIndex;
						lineIndex += 1;
						continue;
					}
				}

				const headingMatch = /^(#{1,6})\s+(.+)$/.exec(trimmed);
				if (headingMatch) {
					flushList();
					const level = Math.min(6, headingMatch[1].length);
					parts.push(`<h${level}>${this.applyInlineMarkdown(headingMatch[2])}</h${level}>`);
					lineIndex += 1;
					continue;
				}

				if (/^-{3,}$/.test(trimmed)) {
					flushList();
					parts.push('<hr>');
					lineIndex += 1;
					continue;
				}

				const listMatch = /^[-*]\s+(.+)$/.exec(trimmed);
				if (listMatch) {
					listItems.push(`<li>${this.applyInlineMarkdown(listMatch[1])}</li>`);
					lineIndex += 1;
					continue;
				}

				flushList();
				parts.push(`<p>${this.applyInlineMarkdown(trimmed)}</p>`);
				lineIndex += 1;
			}

			flushList();
			return parts.join('');
		},

		isMarkdownTableStart(lines, index) {
			const headerLine = String(lines?.[index] ?? '').trim();
			const separatorLine = String(lines?.[index + 1] ?? '').trim();
			if (!headerLine || !separatorLine || !headerLine.includes('|')) {
				return false;
			}

			return this.isMarkdownTableSeparator(separatorLine);
		},

		isMarkdownTableSeparator(line) {
			const cells = this.parseMarkdownTableCells(line);
			if (!cells.length) {
				return false;
			}

			return cells.every((cell) => /^:?-{3,}:?$/.test(cell));
		},

		parseMarkdownTableCells(line) {
			return String(line || '')
				.trim()
				.replace(/^\|/, '')
				.replace(/\|$/, '')
				.split('|')
				.map((cell) => String(cell || '').trim());
		},

		buildMarkdownTableHtml(lines, startIndex) {
			const headerCells = this.parseMarkdownTableCells(lines[startIndex]);
			const separatorCells = this.parseMarkdownTableCells(lines[startIndex + 1]);
			if (!headerCells.length || headerCells.length !== separatorCells.length) {
				return null;
			}

			const rows = [];
			let index = startIndex + 2;
			while (index < (lines?.length ?? 0)) {
				const trimmed = lines?.[index]?.trim();
				if (!trimmed?.includes('|')) {
					break;
				}

				const cells = this.parseMarkdownTableCells(trimmed);
				if (!cells.length) {
					break;
				}

				while (cells.length < headerCells.length) {
					cells.push('');
				}
				rows.push(cells.slice(0, headerCells.length));
				index += 1;
			}

			const headCellsHtml = headerCells.map((cell) => `<th>${this.applyInlineMarkdown(cell)}</th>`).join('');
			const headHtml = `<thead><tr>${headCellsHtml}</tr></thead>`;
			const bodyHtml = rows.length
				? `<tbody>${rows.map((cells) => {
					const rowCellsHtml = cells.map((cell) => `<td>${this.applyInlineMarkdown(cell)}</td>`).join('');
					return `<tr>${rowCellsHtml}</tr>`;
				}).join('')}</tbody>`
				: '';

			return {
				html: `<table>${headHtml}${bodyHtml}</table>`,
				nextIndex: index - 1,
			};
		},

		applyInlineMarkdown(text) {
			return String(text || '')
				.replaceAll(/!\[[^\]]*]\((https?:\/\/[^)]+)\)/g, (_, url) => {
					const label = this.escapeHtml(this.getDenseReferenceLabelFromUrl(url) || url);
					const safeUrl = this.escapeAttr(url);
					return `<a href="${safeUrl}" target="_blank" rel="noopener noreferrer" title="${safeUrl}">${label}</a>`;
				})
				.replaceAll(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
				.replaceAll(/__([^_]+)__/g, '<strong>$1</strong>')
				.replaceAll(/(^|[\s(])\*([^*]+)\*(?=[\s).,!?;:]|$)/g, '$1<em>$2</em>')
				.replaceAll(/(^|[\s(])_([^_]+)_(?=[\s).,!?;:]|$)/g, '$1<em>$2</em>')
				.replaceAll(/`([^`]+)`/g, '<code>$1</code>')
				.replaceAll(/(?:^|[\s(])EUR(?=\s*\d)/g, (match) => match.replace('EUR', '€'))
				.replaceAll(/\[([^\]]+)\]\((https?:\/\/[^)]+)\)/g, (_, label, url) => `<a href="${url}" target="_blank" rel="noopener noreferrer" title="${url}">${label}</a>`)
				.replaceAll(/\[([^\]]+)\]\((#[^)]+)\)/g, (_, label, href) => `<a href="${href}" title="${href}">${label}</a>`);
		},

		normalizeChunkMarkdownText(text) {
			return String(text || '')
				.replaceAll(/\r\n?/g, '\n')
				.replaceAll(/\s+---\s+/g, '\n---\n')
				.replaceAll(/\s+(#{1,6}\s+)/g, '\n$1')
				.replaceAll(/(!\[[^\]]*]\([^)]+\))/g, '\n$1\n')
				.replaceAll(/<figcaption\b[^>]*>/gi, ': ')
				.replaceAll(/<\/figcaption>/gi, '\n')
				.replaceAll(/(\[[^\]]+]\([^)]+\))(?=[A-Z0-9#])/g, '$1\n')
				.replaceAll(/(\d+\.\s+[^\n]+)(?=\d+\.\s+)/g, '$1\n')
				.replaceAll(/([a-z0-9])(\[[^\]]+]\([^)]+\))/gi, '$1 $2')
				.replaceAll(/\n{3,}/g, '\n\n')
				.trim();
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
			const blocks = Array.from(container.querySelectorAll('p, li, blockquote, h1, h2, h3, h4, h5, h6, td, th'))
				.filter((node) => String(node.textContent || '').trim() !== '');
			const targetBlocks = blocks.length ? blocks : [container];

			const blockFootnotes = new Map();
			supports.forEach((support) => {
				const indices = Array.isArray(support?.groundingChunkIndices)
					? support.groundingChunkIndices.filter((value) => Number.isInteger(value) && value >= 0)
					: [];
				const segmentText = String(support?.segment?.text || '').replaceAll(/\s+/g, ' ').trim();
				if (!indices.length || !segmentText) {
					return;
				}

				const matchedBlock = this.findBestGroundingBlockMatch(targetBlocks, segmentText);
				if (!matchedBlock) {
					return;
				}

				const existing = blockFootnotes.get(matchedBlock) || [];
				const insertionOffset = this.findExactFootnoteOffsetInBlock(matchedBlock, segmentText);
				appendGroundingPlacementEntries(existing, indices, insertionOffset, sourceFootnoteMap);
				blockFootnotes.set(matchedBlock, existing.sort((left, right) => left.offset === right.offset ? left.footnote - right.footnote : left.offset - right.offset));
			});

			blockFootnotes.forEach((placements, block) => {
				if (!placements.length) {
					return;
				}
				this.insertFootnotesIntoBlock(block, placements);
			});

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

			let normalizedSources = this.buildConversationSourceRegistry();
			if (!normalizedSources.length) {
				normalizedSources = this.normalizeSources(
					this.getLatestSources(),
					this.getLatestAnswerText(),
					this.getLatestResponseMeta()
				).map((source, index) => ({
					...source,
					footnote: index + 1,
					historyIndices: [],
					contexts: this.buildSourceContexts(source),
				}));
			}
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

			const $table = $('<table class="geweb-ai-source-list"><tbody></tbody></table>');
			const $list = $table.find('tbody');
			normalizedSources.forEach((source) => {
				const rows = this.buildSourceListItem(source);
				if (Array.isArray(rows)) {
					rows.forEach((row) => {
						$list.append(row);
					});
					return;
				}

				$list.append(rows);
			});

			this.$sourcesBox.append($table);
			this.hydrateResolvedSourceReferences($list, normalizedSources);
			this.hydrateReconstructedSourceContexts($list, normalizedSources);
		},

		buildSourceListItem(source) {
			const resolvedReference = this.getResolvedManagedSourceReference(source.url);
			const title = this.getPreferredSourceLabel(source.title, source.url);
			const url = String(resolvedReference?.url || source.url || '').trim();
			const footnote = Number(source.footnote || 0);
			const snippet = String(source.snippet || '').trim();
			const contexts = Array.isArray(source.contexts) ? source.contexts : [];
			const previewSnippet = snippet || String(contexts[0]?.text || '').trim();
			const sourceLabel = title || url || `Source ${footnote || ''}`.trim();
			const referenceHint = this.getManagedSourceReferenceHint(url);
			const firstMatchPhrase = contexts[0]?.matchPhrase || '';
			const sourceKind = this.getSourceKind(source);
			const sourceIcon = this.getSourceIcon(source);
			const pathLabel = this.getSourcePathLabel(url, source.url);
			const tooltipEntries = this.getSourceMetaEntries(url, source.url);
			const $item = $('<tr class="geweb-ai-source-item"></tr>');
			$item.data('sourceInfo', source);
			const $itemHeader = $item;
			const $toggle = $('<button type="button" class="geweb-ai-source-link geweb-ai-source-toggle"></button>');
			const $number = $('<span class="geweb-ai-source-item-number" aria-hidden="true"></span>').text(`${footnote || ''}`);
			const isExcluded = typeof this.isSourceTemporarilyExcluded === 'function'
				? this.isSourceTemporarilyExcluded(source)
				: false;

			$toggle.attr('aria-label', `Show context for ${sourceLabel}`);
			$toggle.attr('title', `Show context for ${sourceLabel}`);
			const $pathLabel = $('<span class="geweb-ai-source-path"></span>');
			const $pathLinkLabel = $('<span class="geweb-ai-source-link-label geweb-ai-source-path-link"></span>')
				.text(pathLabel || title);
			$pathLabel.append($pathLinkLabel);
			$toggle.append($pathLabel);
			const $icon = this.buildSourceMetaTooltip(sourceIcon, tooltipEntries, sourceLabel);
			this.applySourceItemAttributes($item, { footnote, title, snippet, previewSnippet, url, contexts });
			$item.attr('data-source-kind', sourceKind);
			this.bindSourceToggleInteractions($toggle, $item, url, firstMatchPhrase, $itemHeader, $number, $pathLinkLabel);
			if (isExcluded) {
				$item.addClass('is-excluded');
			}

			$item.append($('<td class="geweb-ai-source-item-cell geweb-ai-source-item-cell--checkbox"></td>').append(this.buildSourceExcludeToggle(source, sourceLabel, isExcluded)));
			$item.append($('<td class="geweb-ai-source-item-cell geweb-ai-source-item-cell--number"></td>').append($number));
			$item.append($('<td class="geweb-ai-source-item-cell geweb-ai-source-item-cell--icon"></td>').append($icon));
			$item.append($('<td class="geweb-ai-source-item-cell geweb-ai-source-item-cell--path"></td>').append($toggle));

			const $detailsRow = $('<tr class="geweb-ai-source-detail-row" aria-hidden="true"></tr>');
			const $detailsCell = $('<td class="geweb-ai-source-detail-cell" colspan="4"></td>');
			const $details = this.buildSourceDetails(url, contexts, previewSnippet, referenceHint, $item, footnote);
			if ($details.children().length) {
				$detailsCell.append($details);
			}
			$detailsRow.append($detailsCell);

			return [$item, $detailsRow];
		},

		getSourcePageIdLabel(url, originalUrl = '') {
			const candidates = [
				String(originalUrl || '').trim(),
				String(url || '').trim(),
				this.normalizeManagedSourceUrl(originalUrl),
				this.normalizeManagedSourceUrl(url),
			].filter(Boolean);

			for (const candidate of candidates) {
				const reference = this.sourceReferenceCache?.[candidate];
				const cachedPostId = Number(reference?.post_id || 0);
				if (cachedPostId > 0) {
					return String(cachedPostId);
				}
			}

			const pageId = this.extractManagedSourcePostId(String(url || originalUrl || '').trim());
			if (pageId > 0) {
				return String(pageId);
			}

			return '';
		},

		getSourcePathLabel(url, originalUrl = '') {
			const candidates = [
				String(originalUrl || '').trim(),
				String(url || '').trim(),
				this.normalizeManagedSourceUrl(originalUrl),
				this.normalizeManagedSourceUrl(url),
			].filter(Boolean);

			for (const candidate of candidates) {
				const reference = this.sourceReferenceCache?.[candidate];
				const cachedLabel = String(reference?.label || '').trim();
				if (cachedLabel) {
					return cachedLabel;
				}
			}

			return this.formatManagedSourcePath(String(url || originalUrl || '').trim());
		},

		getSourceSizeLabel(url, originalUrl = '') {
			const candidates = [
				String(originalUrl || '').trim(),
				String(url || '').trim(),
				this.normalizeManagedSourceUrl(originalUrl),
				this.normalizeManagedSourceUrl(url),
			].filter(Boolean);

			for (const candidate of candidates) {
				const reference = this.sourceReferenceCache?.[candidate];
				const cachedSizeLabel = String(reference?.size_label || '').trim();
				if (cachedSizeLabel) {
					return cachedSizeLabel;
				}
			}

			return '';
		},

		getSourceMetaEntries(url, originalUrl = '') {
			const entries = [];
			const sizeLabel = this.getSourceSizeLabel(url, originalUrl);
			const pageIdLabel = this.getSourcePageIdLabel(url, originalUrl);

			if (sizeLabel) {
				entries.push({
					label: t('sourceMetaSize', 'Size'),
					value: sizeLabel,
				});
			}

			if (pageIdLabel) {
				entries.push({
					label: t('sourceMetaPageId', 'Page ID'),
					value: pageIdLabel,
				});
			}

			return entries;
		},

		buildSourceMetaTooltip(sourceIcon, entries, sourceLabel) {
			const tooltipText = entries.length
				? entries.map((entry) => `${entry.label}: ${entry.value}`).join(' • ')
				: t('sourceMetaUnavailable', 'No extra source details available');
			const buttonLabel = entries.length
				? `${t('sourceMetaDetails', 'Source details')}: ${tooltipText}.`
				: `${t('sourceMetaDetails', 'Source details')}: ${tooltipText}`;
			const $wrapper = $('<div class="geweb-ai-source-meta"></div>');
			const $button = $('<button type="button" class="geweb-ai-source-meta-toggle"></button>');
			const $icon = $('<span class="geweb-ai-source-link-icon" aria-hidden="true"></span>').text(sourceIcon);
			const $tooltip = $('<div class="geweb-ai-source-meta-tooltip" role="tooltip" aria-hidden="true"></div>');
			const $close = $('<button type="button" class="geweb-ai-source-meta-tooltip-close" aria-label="Close source details" title="Close source details"><span aria-hidden="true">×</span></button>');

			$button.attr('aria-label', `${buttonLabel} ${sourceLabel}`.trim());
			$button.attr('title', '');
			$button.append($icon);
			$tooltip.append($close);

			if (entries.length) {
				entries.forEach((entry) => {
					const $row = $('<div class="geweb-ai-source-meta-tooltip-row"></div>');
					$row.append(
						$('<span class="geweb-ai-source-meta-tooltip-label"></span>').text(`${entry.label}:`)
					);
					$row.append(
						$('<span class="geweb-ai-source-meta-tooltip-value"></span>').text(entry.value)
					);
					$tooltip.append($row);
				});
			} else {
				$tooltip.append(
					$('<div class="geweb-ai-source-meta-tooltip-row"></div>').text(tooltipText)
				);
			}

			this.bindSourceMetaTooltipInteractions($wrapper, $button, $tooltip);
			$wrapper.append($button, $tooltip);
			return $wrapper;
		},

		closeAllSourceMetaTooltips() {
			$('.geweb-ai-source-meta.is-open').each((_, element) => {
				const $wrapper = $(element);
				$wrapper.removeClass('is-open');
				$wrapper.find('.geweb-ai-source-meta-toggle').attr('aria-expanded', 'false');
				$wrapper.find('.geweb-ai-source-meta-tooltip').attr('aria-hidden', 'true');
			});
		},

		bindSourceMetaTooltipInteractions($wrapper, $button, $tooltip) {
			if (!$wrapper?.length || !$button?.length || !$tooltip?.length) {
				return;
			}

			const hasFinePointer = () => (
				!!globalThis.matchMedia &&
				globalThis.matchMedia('(hover: hover) and (pointer: fine)').matches
			);

			const closeTooltip = () => {
				$wrapper.removeClass('is-open');
				$button.attr('aria-expanded', 'false');
				$tooltip.attr('aria-hidden', 'true');
			};

			const openTooltip = () => {
				this.closeAllSourceMetaTooltips();
				$wrapper.addClass('is-open');
				$button.attr('aria-expanded', 'true');
				$tooltip.attr('aria-hidden', 'false');
			};

			$button.attr('aria-expanded', 'false');

			$button.on('click', (event) => {
				event.preventDefault();
				event.stopPropagation();
				if ($wrapper.hasClass('is-open')) {
					closeTooltip();
					return;
				}

				openTooltip();
			});
			$tooltip.on('click', '.geweb-ai-source-meta-tooltip-close', (event) => {
				event.preventDefault();
				event.stopPropagation();
				closeTooltip();
			});

			$button.on('focus', () => {
				if (!hasFinePointer()) {
					return;
				}

				openTooltip();
			});

			$wrapper.on('mouseleave', () => {
				if (!hasFinePointer()) {
					return;
				}

				closeTooltip();
			});

			$wrapper.on('focusout', (event) => {
				const nextFocused = event.relatedTarget;
				if (nextFocused && $wrapper.get(0)?.contains(nextFocused)) {
					return;
				}

				closeTooltip();
			});

			if (!this._boundSourceMetaTooltipDocumentClick) {
				$(document).on('click.geweb-ai-source-meta-tooltip', (event) => {
					if ($(event.target).closest('.geweb-ai-source-meta').length) {
						return;
					}

					this.closeAllSourceMetaTooltips();
				});
				this._boundSourceMetaTooltipDocumentClick = true;
			}
		},

		refreshSourceMetaTooltip($item, url, originalUrl = '') {
			if (!$item?.length) {
				return;
			}

			const entries = this.getSourceMetaEntries(url, originalUrl);
			const $button = $item.find('.geweb-ai-source-meta-toggle').first();
			const $tooltip = $item.find('.geweb-ai-source-meta-tooltip').first();
			const sourceLabel = String($item.attr('data-source-title') || '').trim();
			const tooltipText = entries.length
				? entries.map((entry) => `${entry.label}: ${entry.value}`).join(' • ')
				: t('sourceMetaUnavailable', 'No extra source details available');

			if ($button.length) {
				$button.attr('aria-label', `${t('sourceMetaDetails', 'Source details')}: ${tooltipText}. ${sourceLabel}`.trim());
			}

			if (!$tooltip.length) {
				return;
			}

			$tooltip.empty();
			$tooltip.append(
				$('<button type="button" class="geweb-ai-source-meta-tooltip-close" aria-label="Close source details" title="Close source details"><span aria-hidden="true">×</span></button>')
			);
			if (entries.length) {
				entries.forEach((entry) => {
					const $row = $('<div class="geweb-ai-source-meta-tooltip-row"></div>');
					$row.append($('<span class="geweb-ai-source-meta-tooltip-label"></span>').text(`${entry.label}:`));
					$row.append($('<span class="geweb-ai-source-meta-tooltip-value"></span>').text(entry.value));
					$tooltip.append($row);
				});
				return;
			}

			$tooltip.append(
				$('<div class="geweb-ai-source-meta-tooltip-row"></div>').text(tooltipText)
			);
		},

		buildSourceExcludeToggle(source, sourceLabel, isExcluded) {
			const includeTitle = t('includeSourceAgainTitle', 'Allow this source again for the next question');
			const excludeTitle = t('excludeSourceTemporarilyTitle', 'Temporarily exclude this source from the next question');
			const checkboxId = `geweb-ai-source-filter-${String(source?.footnote || sourceLabel || 'source')
				.toLowerCase()
				.replaceAll(/[^a-z0-9]+/g, '-')}`;

			const $wrapper = $('<label class="geweb-ai-icon-button geweb-ai-source-filter-toggle"></label>');
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
			$item.data('sourceInfo', sourceInfo);
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

		bindSourceToggleInteractions($toggle, $item, url, matchPhrase, $itemHeader = null, $number = null, $linkLabel = null) {
			const activateSource = (event) => {
				event.preventDefault();
				event.stopPropagation();
				this.setScrollablePaneActive?.('sources');
				this.ensureSourcesPanelVisible();
				this.activateSourceReferenceItem($item);
			};

			const handleSourceToggle = (event) => {
				activateSource(event);
			};
			$toggle.on('click', handleSourceToggle);
			$toggle.on('keydown', (event) => {
				if (event.key !== 'Enter' && event.key !== ' ') {
					return;
				}

				handleSourceToggle(event);
			});
			if ($linkLabel?.length) {
				const openSource = (event) => {
					event.preventDefault();
					event.stopPropagation();
					this.openSourceDestination(url, matchPhrase);
				};
				$linkLabel.attr('role', 'link');
				$linkLabel.attr('tabindex', '0');
				$linkLabel.attr('title', matchPhrase ? `Open source at first match: ${matchPhrase}` : 'Open source');
				$linkLabel.on('click', openSource);
				$linkLabel.on('keydown', (event) => {
					if (event.key !== 'Enter' && event.key !== ' ') {
						return;
					}

					openSource(event);
				});
			}
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
					if ($(event.target).closest('.geweb-ai-source-filter-toggle, .geweb-ai-source-link-label, .geweb-ai-source-item-number, .geweb-ai-source-meta-toggle, .geweb-ai-source-meta-tooltip').length) {
						return;
					}

					handleSourceToggle(event);
				});
			}
		},

		buildSourceDetails(url, contexts, previewSnippet, referenceHint, $sourceItem, footnote = 0) {
			const $details = $('<div class="geweb-ai-source-details"></div>');
			const $detailsHeader = $('<div class="geweb-ai-source-details-header"></div>');
			const sourceInfo = $sourceItem?.data('sourceInfo') || {};
			const sourceKind = this.getSourceKind(sourceInfo);
			const listingUrl = this.getManagedSourceAdminListingUrl(sourceInfo);
			const listingLabel = sourceKind === 'document'
				? t('openDocumentsListing', 'Open documents listing')
				: t('openPagesListing', 'Open pages listing');
			const $headerActions = $('<div class="geweb-ai-source-details-actions"></div>');
			const $closeButton = $('<button type="button" class="geweb-ai-source-details-close" aria-label="Close source context" title="Close source context"></button>');
			$closeButton.append($('<span aria-hidden="true">×</span>'));
			$closeButton.on('click', (event) => {
				event.preventDefault();
				event.stopPropagation();
				this.deactivateSourceReferenceItems();
			});
			$closeButton.attr('aria-label', t('closeSourceContext', 'Close source context'));
			$closeButton.attr('title', t('closeSourceContext', 'Close source context'));
			if (listingUrl) {
				const $listingLink = $('<a class="geweb-ai-source-details-link" target="_blank" rel="noopener noreferrer"></a>');
				$listingLink.attr('href', listingUrl);
				$listingLink.attr('aria-label', listingLabel);
				$listingLink.attr('title', listingLabel);
				$listingLink.append($('<span aria-hidden="true">≣</span>'));
				$headerActions.append($listingLink);
			}
			$headerActions.append($closeButton);
			$detailsHeader.append($headerActions);
			$details.append($detailsHeader);

			const $contexts = contexts.length > 1
				? this.buildSourceContextList(url, contexts, $sourceItem, footnote)
				: this.buildSingleSourceSnippet(url, contexts[0], previewSnippet, $sourceItem, footnote);

			if ($contexts) {
				$details.append($contexts);
			}

			return $details;
		},

		getManagedSourceAdminListingUrl(source) {
			const sourceInfo = source && typeof source === 'object' ? source : {};
			const sourceKind = this.getSourceKind(sourceInfo);
			if (sourceKind === 'document') {
				return String(getAiSearchConfig().frontend_ai_manage_documents_url || '').trim();
			}

			const postId = this.extractManagedSourcePostId(sourceInfo.url || '');
			const editPostBaseUrl = String(getAiSearchConfig().frontend_ai_edit_post_url || '').trim();
			if (postId > 0 && editPostBaseUrl) {
				const parsed = safeParseUrl(editPostBaseUrl, globalThis.location?.origin || undefined);
				if (parsed) {
					parsed.searchParams.set('post', String(postId));
					parsed.searchParams.set('action', 'edit');
					return parsed.toString();
				}
			}

			return String(getAiSearchConfig().frontend_ai_manage_pages_url || '').trim();
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
			$contextItem.attr('data-source-context-index', String(contextIndex));

			const $snippet = $('<div class="geweb-ai-source-snippet"></div>').html(
				this.highlightMatchPhraseInHtml(this.renderFormattedChunkHtml(contextText), matchPhrase)
			);
			this.bindSourceMatchInteraction($snippet, targetUrl, matchPhrase, $sourceItem);
			$contextItem.append($snippet);
			this.bindExplicitSourceContextActivation($contextItem, $sourceItem);
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
			$wrapper.attr('data-source-context-index', '0');

			this.bindSourceMatchInteraction($snippet, targetUrl, matchPhrase, $sourceItem);
			$wrapper.append($snippet);
			this.bindExplicitSourceContextActivation($wrapper, $sourceItem);
			return $wrapper;
		},

		bindExplicitSourceContextActivation($context, $sourceItem) {
			if (!$context?.length || !$sourceItem?.length) {
				return;
			}

			const activateContext = (event) => {
				if ($(event.target).closest('.geweb-ai-inline-match--interactive, a, button').length) {
					return;
				}

				event.preventDefault();
				event.stopPropagation();
				this.setScrollablePaneActive?.('sources');
				this.activateSourceReferenceItem($sourceItem);
				this.activateSpecificSourceContext($sourceItem, $context);
			};

			$context.on('click', activateContext);
			$context.on('keydown', (event) => {
				if (event.key !== 'Enter' && event.key !== ' ') {
					return;
				}

				activateContext(event);
			});
		},

		buildSourceContextFootnoteBadge(footnote) {
			const footnoteNumber = Number(footnote || 0);
			if (!Number.isInteger(footnoteNumber) || footnoteNumber <= 0) {
				return null;
			}

			return $('<span class="geweb-ai-source-context-footnote"></span>').text(`[${footnoteNumber}]`);
		},

		bindSourceMatchInteraction($element, url, matchPhrase, $sourceItem) {
			if (!$element?.length || !url || !String(matchPhrase || '').trim()) {
				return;
			}

			$element.find('mark.geweb-ai-inline-match').each((_, node) => {
				const $match = $(node);
				$match.attr('tabindex', '0');
				$match.attr('role', 'link');
				$match.attr('title', `Open source at first match: ${matchPhrase}`);
				$match.addClass('geweb-ai-inline-match--interactive');
				$match.on('click', (event) => {
					event.preventDefault();
					event.stopPropagation();
					this.setScrollablePaneActive?.('sources');
					if ($sourceItem?.length && !$sourceItem.hasClass('is-active')) {
						this.activateSourceReferenceItem($sourceItem);
					}
					this.openSourceDestination(url, matchPhrase);
				});
				$match.on('keydown', (event) => {
					if (event.key !== 'Enter' && event.key !== ' ') {
						return;
					}

					event.preventDefault();
					event.stopPropagation();
					this.setScrollablePaneActive?.('sources');
					if ($sourceItem?.length && !$sourceItem.hasClass('is-active')) {
						this.activateSourceReferenceItem($sourceItem);
					}
					this.openSourceDestination(url, matchPhrase);
				});
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
					// eslint-disable-next-line no-console
					console.debug('Source reference resolution failed.', error);
				}
			}

			$list.find('tr.geweb-ai-source-item[data-source-url]').each((_, item) => {
				this.hydrateResolvedSourceReferenceItem($(item));
				this.updateSourceItemTitle($(item));
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

			$item.attr('data-source-url-original', originalUrl);
			$item.attr('data-source-url', nextUrl);
			this.refreshSourceMetaTooltip($item, nextUrl, originalUrl);
			$item.find('.geweb-ai-source-link-label').first().text(this.getSourcePathLabel(nextUrl, originalUrl));
		},

		async hydrateReconstructedSourceContexts($list, sources) {
			if (!$list.length || !Array.isArray(sources) || !sources.length) {
				return;
			}

			const items = [];
			sources.forEach((source, sourceIndex) => {
				const sourceUrl = String(source?.url || '').trim();
				if (!sourceUrl) {
					return;
				}

				const contexts = Array.isArray(source?.contexts) ? source.contexts : [];
				contexts.forEach((context, contextIndex) => {
					const text = String(context?.text || '').trim();
					const contextUrl = String(context?.url || sourceUrl).trim();
					if (!text || !contextUrl) {
						return;
					}

					items.push({
						key: `${sourceIndex}:${contextIndex}`,
						url: contextUrl,
						sourceUrl,
						text,
					});
				});
			});

			if (!items.length) {
				return;
			}

			try {
				await ensureSearchNonce();
				const response = await $.ajax({
					url: geweb_aisearch.ajax_url,
					type: 'POST',
					data: {
						action: 'geweb_reconstruct_source_contexts',
						nonce: geweb_aisearch.search_nonce,
						items,
					}
				});

				const reconstructed = response?.success ? response?.data?.contexts ?? {} : {};
				if (!reconstructed || typeof reconstructed !== 'object') {
					return;
				}

				$list.children('tr.geweb-ai-source-item').each((sourceIndex, element) => {
					const $item = $(element);
					const sourceInfo = $item.data('sourceInfo');
					if (!sourceInfo || !Array.isArray(sourceInfo.contexts)) {
						return;
					}

					let updated = false;
					const nextContexts = sourceInfo.contexts.map((context, contextIndex) => {
						const key = `${sourceIndex}:${contextIndex}`;
						const reconstructedData = reconstructed[key];
						if (!reconstructedData) {
							return context;
						}

						let reconstructedText = '';
						let resolvedUrl = '';

						if (typeof reconstructedData === 'object' && reconstructedData !== null) {
							reconstructedText = String(reconstructedData.markdown || '').trim();
							resolvedUrl = String(reconstructedData.url || '').trim();
						} else {
							reconstructedText = String(reconstructedData || '').trim();
						}

						if (!reconstructedText) {
							return context;
						}

						updated = true;
						const nextContext = {
							...context,
							text: reconstructedText,
						};

						if (resolvedUrl) {
							nextContext.url = resolvedUrl;
						}

						return nextContext;
					});

					if (!updated) {
						return;
					}

					const nextSourceInfo = {
						...sourceInfo,
						contexts: nextContexts,
					};
					$item.data('sourceInfo', nextSourceInfo);
					this.applySourceItemAttributes($item, nextSourceInfo);
					const previewSnippet = String(nextSourceInfo.snippet || nextContexts[0]?.text || '').trim();
					const referenceHint = this.getManagedSourceReferenceHint(String(nextSourceInfo.url || '').trim());
					const $details = this.buildSourceDetails(
						String(nextSourceInfo.url || '').trim(),
						nextContexts,
						previewSnippet,
						referenceHint,
						$item,
						Number(nextSourceInfo.footnote || 0)
					);
					this.getSourceDetailsContainer($item).replaceWith($details);
				});
			} catch (error) {
				// eslint-disable-next-line no-console
				console.debug('Source context reconstruction failed.', error);
			}
		},

		updateSourceItemTitle($item) {
			if (!$item?.length) {
				return;
			}

			const sourceUrl = String($item.attr('data-source-url') || '').trim();
			const cacheKeyUrl = String($item.attr('data-source-url-original') || sourceUrl).trim();
			const sourceKind = String($item.attr('data-source-kind') || '').trim();
			const $toggle = $item.find('.geweb-ai-source-toggle').first();
			if (!$toggle.length || !sourceUrl) {
				return;
			}

			const normalizedUrl = this.normalizeManagedSourceUrl(cacheKeyUrl);
			const resolvedRef = normalizedUrl ? this.sourceReferenceCache?.[normalizedUrl] : null;
			if (!resolvedRef?.url) {
				return;
			}

			const pageId = this.extractManagedSourcePostId(resolvedRef.url);
			if (pageId <= 0) {
				return;
			}

			let newTitle = '';
			if (sourceKind === 'document') {
				const parsed = safeParseUrl(resolvedRef.url);
				if (parsed) {
					const pathname = parsed.pathname;
					const documentName = pathname.split('/').findLast(Boolean) || '';
					if (documentName) {
						newTitle = `${documentName} (page ${pageId})`;
					}
				}
			} else {
				const currentLabel = String($toggle.attr('title') || '').trim();
				if (currentLabel) {
					newTitle = `${currentLabel} (page ${pageId})`;
				}
			}

			if (newTitle) {
				$toggle.attr('title', newTitle);
			}
		},

		normalizeSources(sources, answerText, responseMeta) {
			const seen = new Set();
			const haystack = String(answerText || '').toLowerCase();
			const explicitSources = Array.isArray(sources) ? sources : [];
			const groundingSources = this.extractSourcesFromGroundingChunks(responseMeta, sources, answerText, seen);
			if (groundingSources.length) {
				return groundingSources;
			}

			const normalized = explicitSources.length
				? explicitSources.reduce((accumulator, source) => {
					const title = source?.title ? String(source.title).trim() : '';
					const explicitUrl = source?.url ? String(source.url).trim() : '';
					const url = this.resolveManagedSourceUrl(explicitUrl, title, answerText);
					const key = (url || title || explicitUrl).toLowerCase();

					if (!key || seen.has(key) || !url) {
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

		resolveManagedSourceUrl(url, title, answerText) {
			const directUrl = this.normalizeManagedSourceUrl(url);
			if (directUrl) {
				return directUrl;
			}

			const inferredDocumentUrl = this.buildManagedDocumentUrlFromTitle(title);
			if (inferredDocumentUrl) {
				return inferredDocumentUrl;
			}

			return this.findManagedUrlMentionedInAnswer(String(title || '').trim(), answerText);
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

				if (sourceTitle?.toLowerCase() === documentLabel.toLowerCase()) {
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

			const mode = String(options?.mode || 'active').trim().toLowerCase();
			if (mode === 'preview') {
				this.previewSourceReferenceItem($target);
				this.highlightBestSourceContext($target, 'preview');
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

			const $items = this.$sourcesBox.find('tr.geweb-ai-source-item');
			$items.removeClass('is-active is-preview');
			$items.each((_, element) => {
				this.getSourceDetailRow($(element)).attr('aria-hidden', 'true');
			});
			this.clearSourceContextHighlights();
			$target.addClass('is-active');
			this.getSourceDetailRow($target).attr('aria-hidden', 'false');
		},

		activateSpecificSourceContext($sourceItem, $context) {
			if (!$sourceItem?.length || !$context?.length) {
				return;
			}

			this.clearSourceContextHighlights();
			$context.removeClass('is-context-preview').addClass('is-context-active');

			const element = $context.get(0);
			if (element && typeof element.scrollIntoView === 'function') {
				element.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
			}
		},

		previewSourceReferenceItem($target) {
			if (!$target?.length || !this.$sourcesBox.length) {
				return;
			}

			const $items = this.$sourcesBox.find('tr.geweb-ai-source-item').not('.is-active');
			$items.removeClass('is-preview');
			$items.each((_, element) => {
				this.getSourceDetailRow($(element)).attr('aria-hidden', 'true');
			});
			this.clearSourceContextHighlights('preview');
			if (!$target.hasClass('is-active')) {
				$target.addClass('is-preview');
				this.getSourceDetailRow($target).attr('aria-hidden', 'false');
			}
		},

		clearSourceReferencePreview($target) {
			if (!$target?.length || !$target.hasClass('is-preview') || $target.hasClass('is-active')) {
				return;
			}

			$target.removeClass('is-preview');
			this.getSourceDetailRow($target).attr('aria-hidden', 'true');
		},

		clearAllSourceReferencePreviews() {
			if (!this.$sourcesBox.length) {
				return;
			}

			this.$sourcesBox.find('tr.geweb-ai-source-item.is-preview').removeClass('is-preview');
			this.$sourcesBox.find('tr.geweb-ai-source-detail-row').attr('aria-hidden', 'true');
			this.clearSourceContextHighlights('preview');
		},

		deactivateSourceReferenceItems() {
			if (!this.$sourcesBox.length) {
				return;
			}

			this.$sourcesBox.find('tr.geweb-ai-source-item').removeClass('is-active is-preview');
			this.$sourcesBox.find('tr.geweb-ai-source-detail-row').attr('aria-hidden', 'true');
			this.clearSourceContextHighlights();
		},

		highlightBestSourceContext($sourceItem, mode = 'active') {
			if (!$sourceItem?.length) {
				return;
			}

			const $contexts = this.getSourceContextElements($sourceItem);
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

		getSourceDetailRow($sourceItem) {
			if (!$sourceItem?.length) {
				return $();
			}

			return $sourceItem.next('.geweb-ai-source-detail-row');
		},

		getSourceDetailsContainer($sourceItem) {
			return this.getSourceDetailRow($sourceItem).find('.geweb-ai-source-details').first();
		},

		getSourceContextElements($sourceItem) {
			return this.getSourceDetailRow($sourceItem).find('.geweb-ai-source-context-item, .geweb-ai-source-single-context');
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
			} catch {
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

		getResolvedManagedSourceReference(url) {
			const rawUrl = String(url || '').trim();
			const normalizedUrl = this.normalizeManagedSourceUrl(rawUrl);
			const candidates = [rawUrl, normalizedUrl].filter(Boolean);

			for (const candidate of candidates) {
				const reference = this.sourceReferenceCache?.[candidate];
				if (reference && typeof reference === 'object') {
					return reference;
				}
			}

			return null;
		},

		getPreferredSourceLabel(title, url) {
			const normalizedTitle = String(title || '').trim();
			const resolvedReference = this.getResolvedManagedSourceReference(url);
			const resolvedUrl = String(resolvedReference?.url || '').trim();
			const resolvedLabel = String(resolvedReference?.label || resolvedReference?.title || '').trim();
			const normalizedUrl = resolvedUrl || this.normalizeManagedSourceUrl(url);
			const urlLabel = this.formatManagedSourcePath(normalizedUrl);
			const postId = this.extractManagedSourcePostId(normalizedUrl);

			if (resolvedLabel) {
				return resolvedLabel;
			}

			if (!normalizedTitle) {
				return urlLabel || normalizedUrl || t('untitledConversation', 'Untitled conversation');
			}

			if (
				/\.md$/i.test(normalizedTitle) ||
				normalizedTitle === normalizedUrl ||
				/^https?:\/\//i.test(normalizedTitle)
			) {
				if (postId > 0) {
					return `page ${postId}`;
				}
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

			const pathname = parsed.pathname.replace(/^\/+/, '').replace(/\/$/, '');
			if (pathname) {
				const markdownPageIdMatch = /^(\d+)\.md$/i.exec(pathname);
				if (markdownPageIdMatch?.[1]) {
					return `page ${markdownPageIdMatch[1]}`;
				}

				const prefixedMarkdownPageIdMatch = /^(\d+)-.+\.[a-z0-9]{2,8}$/i.exec(pathname);
				if (prefixedMarkdownPageIdMatch?.[1]) {
					return `page ${prefixedMarkdownPageIdMatch[1]}`;
				}

				return pathname.replace(TRAILING_SLASH_REGEX, '/');
			}

			const postId = parsed.searchParams.get('p');
			if (postId) {
				return `page ${postId}`;
			}

			const pageId = parsed.searchParams.get('page_id');
			if (pageId) {
				return `page ${pageId}`;
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

		extractManagedSourcePostId(url) {
			const normalizedUrl = this.normalizeManagedSourceUrl(url);
			if (!normalizedUrl) {
				return 0;
			}

			const parsed = safeParseUrl(normalizedUrl);
			if (!parsed) {
				return 0;
			}

			const directId = String(parsed.searchParams.get('page_id') || parsed.searchParams.get('p') || '').trim();
			if (/^\d+$/.test(directId)) {
				return Number(directId);
			}

			const pathname = parsed.pathname.replace(/^\/+/, '').replace(/\/$/, '');
			const markdownPageIdMatch = /^(\d+)\.md$/i.exec(pathname);
			if (markdownPageIdMatch?.[1]) {
				return Number(markdownPageIdMatch[1]);
			}

			const prefixedMarkdownPageIdMatch = /^(\d+)-.+\.[a-z0-9]{2,8}$/i.exec(pathname);
			if (prefixedMarkdownPageIdMatch?.[1]) {
				return Number(prefixedMarkdownPageIdMatch[1]);
			}

			return 0;
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
			if (!normalized) {
				return '';
			}

			const siteHost = normalizeManagedHost(siteUrl.hostname);
			const candidateHost = normalizeManagedHost(normalized.hostname);
			if (!siteHost || !candidateHost || siteHost !== candidateHost) {
				return '';
			}

			return normalized.toString();
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
	});
})(jQuery);
