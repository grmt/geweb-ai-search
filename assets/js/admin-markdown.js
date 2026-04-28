(function() {
	const {
		decodeHtmlEntities = (text) => String(text || ''),
		escapeHtml = (text) => String(text || ''),
		getLastTruthyValue = () => '',
		getRegexMatch = () => null,
		recordRecoverableAdminError = () => {},
		safeDecodeURIComponent = (value) => String(value || ''),
	} = globalThis.GewebAISearchAdminUtils || {};

	function getPreviewUrlLeaf(url) {
		const normalizedUrl = String(url || '').trim();
		if (!normalizedUrl) {
			return '';
		}

		try {
			const parsedUrl = new URL(normalizedUrl, globalThis.location?.origin || undefined);
			const pathname = String(parsedUrl.pathname || '');
			const leaf = getLastTruthyValue(pathname.split('/'));
			return safeDecodeURIComponent(leaf || normalizedUrl);
		} catch (error) {
			recordRecoverableAdminError(error, 'getPreviewUrlLeaf');
			const leaf = getLastTruthyValue(normalizedUrl.split('/')) || normalizedUrl;
			return safeDecodeURIComponent(leaf);
		}
	}

	function getMarkdownFrontmatterEnd(lines, startIndex) {
		for (let index = startIndex + 1; index < lines.length; index += 1) {
			if (/^-{3,}$/.test(String(lines[index] || '').trim())) {
				return index;
			}
		}

		return -1;
	}

	function buildMarkdownFrontmatterHtml(lines, startIndex, endIndex) {
		const frontmatterLines = lines.slice(startIndex + 1, endIndex);
		return '<pre style="background:#f5f5f5;padding:8px;border-left:3px solid #ccc;margin:0 0 1em 0;overflow-x:auto;">' + escapeHtml(frontmatterLines.join('\n')) + '</pre>';
	}

	function parseMarkdownPreviewLine(lines, lineIndex) {
		const line = String(lines[lineIndex] || '');
		const trimmed = line.trim();
		if (!trimmed) {
			return { type: 'blank' };
		}

		if (/^-{3,}$/.test(trimmed) && lineIndex === 0) {
			const frontmatterEnd = getMarkdownFrontmatterEnd(lines, lineIndex);
			if (frontmatterEnd > lineIndex) {
				return {
					type: 'frontmatter',
					html: buildMarkdownFrontmatterHtml(lines, lineIndex, frontmatterEnd),
					nextIndex: frontmatterEnd + 1
				};
			}
		}

		if (isMarkdownTableStart(lines, lineIndex)) {
			const table = buildMarkdownTableHtml(lines, lineIndex);
			if (table) {
				return {
					type: 'table',
					html: table.html,
					nextIndex: table.nextIndex + 1
				};
			}
		}

		const headingMatch = getRegexMatch(trimmed, /^(#{1,6})\s+(.+)$/);
		if (headingMatch) {
			const level = Math.min(6, headingMatch[1].length);
			return {
				type: 'html',
				html: '<h' + level + '>' + applyInlineMarkdown(headingMatch[2]) + '</h' + level + '>'
			};
		}

		if (/^-{3,}$/.test(trimmed)) {
			return { type: 'html', html: '<hr>' };
		}

		const listMatch = getRegexMatch(trimmed, /^[-*]\s+(.+)$/);
		if (listMatch) {
			return { type: 'list-item', html: '<li>' + applyInlineMarkdown(listMatch[1]) + '</li>' };
		}

		return { type: 'html', html: '<p>' + applyInlineMarkdown(trimmed) + '</p>' };
	}

	function getAdminMenuTargetTab(href) {
		const normalizedHref = String(href || '');
		if (!normalizedHref) {
			return 'general';
		}

		if (typeof URL.canParse === 'function' && URL.canParse(normalizedHref, globalThis.location?.origin || globalThis.location?.href || undefined)) {
			const url = new URL(normalizedHref, globalThis.location?.origin || globalThis.location?.href || undefined);
			return String(url.searchParams.get('geweb_tab') || 'general').trim();
		}

		if (normalizedHref.includes('geweb_tab=documents')) {
			return 'documents';
		}

		if (normalizedHref.includes('geweb_tab=stores')) {
			return 'stores';
		}

		if (normalizedHref.includes('geweb_tab=conversations')) {
			return 'conversations';
		}

		if (normalizedHref.includes('geweb_tab=prompts')) {
			return 'prompts';
		}

		return 'general';
	}

	function normalizeMarkdownPreviewBlocks(markdown) {
		return String(markdown || '')
			.replaceAll(/(!\[[^\]]*]\([^)]+\))/g, '\n$1\n')
			.replaceAll(/<figcaption\b[^>]*>/gi, ': ')
			.replaceAll(/<\/figcaption>/gi, '\n')
			.replaceAll(/\n{3,}/g, '\n\n');
	}

	function sanitizePreviewHtml(html) {
		const container = globalThis.document.createElement('div');
		container.innerHTML = String(html || '');

		Array.from(container.querySelectorAll('*')).forEach((element) => {
			const tagName = String(element.tagName || '').toLowerCase();
			if (!['a', 'p', 'ul', 'ol', 'li', 'strong', 'em', 'code', 'pre', 'blockquote', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'table', 'thead', 'tbody', 'tr', 'th', 'td'].includes(tagName)) {
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
			.map((cell) => String(cell || '').trim());
	}

	function isMarkdownTableSeparator(line) {
		const cells = parseMarkdownTableCells(line);
		if (!cells.length) {
			return false;
		}

		return cells.every((cell) => /^:?-{3,}:?$/.test(cell));
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
				const label = getPreviewUrlLeaf(url) || url;
				return '<a href="' + escapeHtml(url) + '" title="' + escapeHtml(url) + '">' + escapeHtml(label) + '</a>';
			})
			.replaceAll(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
			.replaceAll(/__([^_]+)__/g, '<strong>$1</strong>')
			.replaceAll(/(^|[\s(])\*([^*]+)\*(?=[\s).,!?;:]|$)/g, '$1<em>$2</em>')
			.replaceAll(/(^|[\s(])_([^_]+)_(?=[\s).,!?;:]|$)/g, '$1<em>$2</em>')
			.replaceAll(/`([^`]+)`/g, '<code>$1</code>')
			.replaceAll(/\[([^\]]+)\]\((#[^)]+)\)/g, (_, label, href) => '<a href="' + escapeHtml(href) + '" title="' + escapeHtml(href) + '">' + label + '</a>')
			.replaceAll(/\[([^\]]+)\]\((https?:\/\/[^)\s]+#[^)]+)\)/g, (_, label, url) => '<a href="' + escapeHtml(url) + '" title="' + escapeHtml(url) + '">' + label + '</a>')
			.replaceAll(/\[([^\]]+)\]\((https?:\/\/[^)]+)\)/g, (_, label, url) => '<a href="' + escapeHtml(url) + '" title="' + escapeHtml(url) + '">' + label + '</a>')
			.replaceAll(/\\([\\`*_{}[\]()#+.!-])/g, '$1');
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

		const headHtml = '<thead><tr>' + headerCells.map((cell) => '<th>' + applyInlineMarkdown(cell) + '</th>').join('') + '</tr></thead>';
		const bodyHtml = rows.length
			? '<tbody>' + rows.map((cells) => '<tr>' + cells.map((cell) => '<td>' + applyInlineMarkdown(cell) + '</td>').join('') + '</tr>').join('') + '</tbody>'
			: '';

		return {
			html: '<table>' + headHtml + bodyHtml + '</table>',
			nextIndex: index - 1
		};
	}

	function renderMarkdownPreview(markdown) {
		if (typeof globalThis.GewebAisearchMarkdown?.render === 'function') {
			return globalThis.GewebAisearchMarkdown.render(markdown);
		}

		const normalizedMarkdown = normalizeMarkdownPreviewBlocks(
			decodeHtmlEntities(String(markdown || '')).replaceAll(/\r\n?/g, '\n')
		);
		const escaped = escapeHtml(normalizedMarkdown);
		const lines = escaped.split('\n');
		const parts = [];
		let listItems = [];

		function flushList() {
			if (!listItems.length) {
				return;
			}

			parts.push('<ul>' + listItems.join('') + '</ul>');
			listItems = [];
		}

		let lineIndex = 0;
		while (lineIndex < lines.length) {
			const parsedLine = parseMarkdownPreviewLine(lines, lineIndex);
			if (parsedLine.type === 'blank') {
				flushList();
				lineIndex += 1;
				continue;
			}

			if (parsedLine.type === 'frontmatter' || parsedLine.type === 'table') {
				flushList();
				parts.push(parsedLine.html);
				lineIndex = parsedLine.nextIndex;
				continue;
			}

			if (parsedLine.type === 'list-item') {
				listItems.push(parsedLine.html);
				lineIndex += 1;
				continue;
			}

			flushList();
			parts.push(parsedLine.html);
			lineIndex += 1;
		}

		flushList();
		return sanitizePreviewHtml(parts.join(''));
	}

	function normalizePromptText(text) {
		return String(text || '')
			.replaceAll(/\r\n?/g, '\n')
			.split('\n')
			.map((line) => line.replaceAll(/[ \t]+$/g, ''))
			.join('\n');
	}

	globalThis.GewebAISearchAdminMarkdown = {
		getAdminMenuTargetTab,
		normalizePromptText,
		renderMarkdownPreview,
	};
})();
