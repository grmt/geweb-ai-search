(function() {
	const INLINE_BREAK_TOKEN = 'GEWEBINLINEBREAKTOKEN';

	function escapeHtml(text) {
		return String(text || '')
			.replaceAll('&', '&amp;')
			.replaceAll('<', '&lt;')
			.replaceAll('>', '&gt;')
			.replaceAll('"', '&quot;')
			.replaceAll("'", '&#039;');
	}

	function decodeHtmlEntities(text) {
		const textarea = globalThis.document.createElement('textarea');
		textarea.innerHTML = String(text || '');
		return textarea.value;
	}

	function getUrlLeaf(url) {
		const normalizedUrl = String(url || '').trim();
		if (!normalizedUrl) {
			return '';
		}

		const fallbackLeaf = normalizedUrl.split('/').findLast(Boolean) || normalizedUrl;
		try {
			const parsedUrl = new URL(normalizedUrl, globalThis.location?.origin || undefined);
			const pathname = String(parsedUrl.pathname || '');
			const leaf = pathname.split('/').findLast(Boolean) || '';
			try {
				return decodeURIComponent(leaf || normalizedUrl);
			} catch (error) {
				if (error instanceof URIError) {
					return leaf || normalizedUrl;
				}
				throw error;
			}
		} catch (error) {
			if (error instanceof TypeError) {
				try {
					return decodeURIComponent(fallbackLeaf);
				} catch (decodeError) {
					if (decodeError instanceof URIError) {
						return fallbackLeaf;
					}
					throw decodeError;
				}
			}
			throw error;
		}
	}

	function normalizeBlocks(markdown) {
		const normalized = String(markdown || '')
			.replaceAll(/<br\s*\/?>/gi, INLINE_BREAK_TOKEN)
			.replaceAll(/<\/?(?:div|p|section|article|aside|main|header|footer)\b[^>]*>/gi, '\n')
			.replaceAll(/(!\[[^\]]*]\([^)]+\))/g, '\n$1\n')
			.replaceAll(/(!\[[^\]]*]\([^)]+\))(?=\|)/g, '$1\n')
			.replaceAll(/<figcaption\b[^>]*>/gi, ': ')
			.replaceAll(/<\/figcaption>/gi, '\n')
			.replaceAll(/\n{3,}/g, '\n\n');

		return normalizeFlattenedTableLeadIn(normalized);
	}

	function normalizeFlattenedTableLeadIn(markdown) {
		const lines = String(markdown || '').split('\n');
		const normalizedLines = [];
		let index = 0;

		while (index < lines.length) {
			const line = String(lines[index] || '');
			const nextLine = String(lines[index + 1] || '');
			const lookAheadLine = String(lines[index + 2] || '');
			const hasTabHeader = line.includes('\t') && nextLine.includes('\t');
			const followedByPipeRow = /^\s*\|/.test(lookAheadLine) || /^\s*\|/.test(String(lines[index + 3] || ''));

			if (hasTabHeader && followedByPipeRow) {
				const headerCells = line.split('\t').map((cell) => String(cell || '').trim());
				const firstRowCells = nextLine.split('\t').map((cell) => String(cell || '').trim());
				if (headerCells.length >= 2 && headerCells.length === firstRowCells.length) {
					normalizedLines.push(
						'| ' + headerCells.join(' | ') + ' |',
						'| ' + headerCells.map(() => '---').join(' | ') + ' |',
						'| ' + firstRowCells.join(' | ') + ' |'
					);
					index += 2;
					continue;
				}
			}

			normalizedLines.push(line);
			index += 1;
		}

		return normalizedLines.join('\n');
	}

	function sanitizeHtml(html) {
		const container = globalThis.document.createElement('div');
		container.innerHTML = String(html || '');

		Array.from(container.querySelectorAll('*')).forEach((element) => {
			const tagName = String(element.tagName || '').toLowerCase();
			if (!['a', 'p', 'ul', 'ol', 'li', 'strong', 'em', 'code', 'pre', 'blockquote', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'sup'].includes(tagName)) {
				element.replaceWith(...Array.from(element.childNodes));
				return;
			}

			Array.from(element.attributes).forEach((attribute) => {
				if (tagName === 'a' && attribute.name === 'href') {
					if (!/^https?:\/\//i.test(attribute.value) && !/^#/i.test(attribute.value)) {
						element.removeAttribute('href');
					}
					return;
				}

				if (tagName === 'a' && attribute.name === 'id') {
					if (!/^[A-Za-z][A-Za-z0-9\-_:.]*$/.test(attribute.value)) {
						element.removeAttribute('id');
					}
					return;
				}

				if (!['href', 'target', 'rel', 'title', 'id'].includes(attribute.name)) {
					element.removeAttribute(attribute.name);
				}
			});

			if (tagName === 'a') {
				const href = String(element.getAttribute('href') || '');
				if (/^https?:\/\//i.test(href)) {
					element.setAttribute('target', '_blank');
					element.setAttribute('rel', 'noopener noreferrer');
				} else {
					element.removeAttribute('target');
					element.removeAttribute('rel');
				}

				if (!element.getAttribute('href') && !element.getAttribute('id')) {
					element.replaceWith(...Array.from(element.childNodes));
				}
			}
		});

		return container.innerHTML;
	}

	function parseMarkdownTableCells(line) {
		return String(line || '')
			.trim()
			.replace(/^\|/, '')
			.replace(/\|$/, '')
			.split('|')
			.map((cell) => {
				return String(cell || '').trim();
			});
	}

	function isMarkdownTableSeparator(line) {
		const cells = parseMarkdownTableCells(line);
		if (!cells.length) {
			return false;
		}

		return cells.every((cell) => {
			return /^:?-{3,}:?$/.test(cell);
		});
	}

	function isMarkdownTableStart(lines, index) {
		const headerLine = String(lines?.[index] ?? '').trim();
		const separatorLine = String(lines?.[index + 1] ?? '').trim();
		if (!headerLine || !separatorLine || !headerLine.includes('|')) {
			return false;
		}

		return isMarkdownTableSeparator(separatorLine);
	}

	function applyInlineMarkdown(text) {
		return String(text || '')
			.replaceAll(/!\[[^\]]*]\((https?:\/\/[^)]+)\)/g, (_, url) => {
				const label = getUrlLeaf(url) || url;
				return '<a href="' + escapeHtml(url) + '" title="' + escapeHtml(url) + '">' + escapeHtml(label) + '</a>';
			})
			.replaceAll(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
			.replaceAll(/__([^_]+)__/g, '<strong>$1</strong>')
			.replaceAll(/(^|[\s(])\*([^*]+)\*(?=[\s).,!?;:]|$)/g, '$1<em>$2</em>')
			.replaceAll(/(^|[\s(])_([^_]+)_(?=[\s).,!?;:]|$)/g, '$1<em>$2</em>')
			.replaceAll(/`([^`]+)`/g, '<code>$1</code>')
			.replaceAll(/\[([^\]]+)\]\((#[^)]+)\)/g, (_, label, href) => {
				return '<a href="' + escapeHtml(href) + '" title="' + escapeHtml(href) + '">' + label + '</a>';
			})
			.replaceAll(/\[([^\]]+)\]\((mailto:[^)]+)\)/gi, (_, label, url) => {
				return '<a href="' + escapeHtml(url) + '" title="' + escapeHtml(url) + '">' + label + '</a>';
			})
			.replaceAll(/\[([^\]]+)\]\((https?:\/\/[^)\s]+#[^)]+)\)/g, (_, label, url) => {
				return '<a href="' + escapeHtml(url) + '" title="' + escapeHtml(url) + '">' + label + '</a>';
			})
			.replaceAll(/\[([^\]]+)\]\((https?:\/\/[^)]+)\)/g, (_, label, url) => {
				return '<a href="' + escapeHtml(url) + '" title="' + escapeHtml(url) + '">' + label + '</a>';
			})
			.replaceAll(/(^|[\s(>])(https?:\/\/[^\s<]+)/g, (_, prefix, url) => {
				const trailingPunctuationMatch = /[),.;!?]+$/.exec(url);
				const trailingPunctuation = trailingPunctuationMatch ? trailingPunctuationMatch[0] : '';
				const normalizedUrl = trailingPunctuation ? url.slice(0, -trailingPunctuation.length) : url;

				return prefix + '<a href="' + escapeHtml(normalizedUrl) + '" title="' + escapeHtml(normalizedUrl) + '">' + escapeHtml(normalizedUrl) + '</a>' + escapeHtml(trailingPunctuation);
			})
			.replaceAll(INLINE_BREAK_TOKEN, '<br>')
			.replaceAll(/\\([[\]\\`*_{}()#+.!-])/g, '$1');
	}

	function buildMarkdownTableHtml(lines, startIndex) {
		const headerCells = parseMarkdownTableCells(lines[startIndex]);
		const separatorCells = parseMarkdownTableCells(lines[startIndex + 1]);
		if (!headerCells.length || headerCells.length !== separatorCells.length) {
			return null;
		}

		const rows = [];
		let index = startIndex + 2;
		while (index < (lines?.length ?? 0)) {
			const trimmed = String(lines?.[index] ?? '').trim();
			if (!trimmed.includes('|')) {
				break;
			}

			const cells = parseMarkdownTableCells(trimmed);
			if (!cells.length) {
				break;
			}

			while (cells.length < headerCells.length) {
				cells.push('');
			}

			rows.push(cells.slice(0, headerCells.length));
			index += 1;
		}

		const headHtml = '<thead><tr>' + headerCells.map((cell) => {
			return '<th>' + applyInlineMarkdown(cell) + '</th>';
		}).join('') + '</tr></thead>';
		const bodyHtml = rows.length
			? '<tbody>' + rows.map((cells) => {
				return '<tr>' + cells.map((cell) => {
					return '<td>' + applyInlineMarkdown(cell) + '</td>';
				}).join('') + '</tr>';
			}).join('') + '</tbody>'
			: '';

		return {
			html: '<table>' + headHtml + bodyHtml + '</table>',
			nextIndex: index - 1,
		};
	}

	function render(markdown) {
		const normalizedMarkdown = normalizeBlocks(
			decodeHtmlEntities(String(markdown || '')).replaceAll(/\r\n?/g, '\n')
		);
		const escaped = escapeHtml(normalizedMarkdown);
		const lines = escaped.split('\n');
		const parts = [];
		let listItems = [];
		let paragraphLines = [];

		function flushList() {
			if (!listItems.length) {
				return;
			}

			parts.push('<ul>' + listItems.join('') + '</ul>');
			listItems = [];
		}

		function flushParagraph() {
			if (!paragraphLines.length) {
				return;
			}

			parts.push('<p>' + paragraphLines.map((line) => {
				return applyInlineMarkdown(line);
			}).join('<br>') + '</p>');
			paragraphLines = [];
		}

		let lineIndex = 0;
		while (lineIndex < lines.length) {
			const line = lines[lineIndex];
			const trimmed = line.trim();

			if (!trimmed) {
				flushList();
				flushParagraph();
				lineIndex += 1;
				continue;
			}

			if (isMarkdownTableStart(lines, lineIndex)) {
				flushList();
				flushParagraph();
				const table = buildMarkdownTableHtml(lines, lineIndex);
				if (table) {
					parts.push(table.html);
					lineIndex = table.nextIndex + 1;
					continue;
				}
			}

			const headingMatch = /^(#{1,6})\s+(.+)$/.exec(trimmed);
			if (headingMatch) {
				flushList();
				flushParagraph();
				const level = Math.min(6, headingMatch[1].length);
				parts.push('<h' + level + '>' + applyInlineMarkdown(headingMatch[2]) + '</h' + level + '>');
				lineIndex += 1;
				continue;
			}

			if (/^-{3,}$/.test(trimmed)) {
				flushList();
				flushParagraph();
				parts.push('<hr>');
				lineIndex += 1;
				continue;
			}

			const listMatch = /^[-*]\s+(.+)$/.exec(trimmed);
			if (listMatch) {
				flushParagraph();
				listItems.push('<li>' + applyInlineMarkdown(listMatch[1]) + '</li>');
				lineIndex += 1;
				continue;
			}

			flushList();
			paragraphLines.push(trimmed);
			lineIndex += 1;
		}

		flushList();
		flushParagraph();
		return sanitizeHtml(parts.join(''));
	}

	globalThis.GewebAisearchMarkdown = {
		render,
		escapeHtml,
		decodeHtmlEntities,
		applyInlineMarkdown,
		normalizeBlocks,
		sanitizeHtml,
		getUrlLeaf,
	};
})();
