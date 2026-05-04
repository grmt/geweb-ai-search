(function($) {
	const {
		t = (_key, fallback) => fallback,
	} = globalThis.GewebAISearchShared || {};
	const {
		normalizeObject = (value) => (value && typeof value === 'object' ? value : {}),
		pushLabeledEntry = () => {},
	} = globalThis.GewebAISearchSourceUtils || {};

	const THOUGHT_TITLE_REGEX_SOURCE = String.raw`(?:[A-Z][\p{Ll}-]+)(?:\s+[A-Z][\p{Ll}-]+){1,5}`;
	const THOUGHT_INLINE_HEADING_REGEX = new RegExp(
		String.raw`^(${THOUGHT_TITLE_REGEX_SOURCE})\s+([\s\S]+)$`,
		'u'
	);
	const THOUGHT_MARKDOWN_SECTION_REGEX = /^\*\*([^\n*][^\n]*?)\*\*\n\n([\s\S]+)$/u;

	function extractThoughtHeading(text) {
		const normalized = String(text || '').trim();
		let match = THOUGHT_MARKDOWN_SECTION_REGEX.exec(normalized);
		if (match) {
			const title = String(match[1] || '').trim();
			const body = String(match[2] || '').trim();
			if (title && body) {
				return { title, body };
			}
		}
		match = THOUGHT_INLINE_HEADING_REGEX.exec(normalized);
		if (match) {
			const title = String(match[1] || '').trim();
			const body = String(match[2] || '').trim();
			if (title && body) {
				return { title, body };
			}
		}
		return null;
	}

	globalThis.GewebAISearchSourceMethods = globalThis.GewebAISearchSourceMethods || {};

	Object.assign(globalThis.GewebAISearchSourceMethods, {
		buildResponseDetails(meta) {
			const normalizedMeta = normalizeObject(meta);
			if (!Object.keys(normalizedMeta).length) {
				return null;
			}

			const $details = $('<div class="geweb-ai-response-details"></div>');

			const $header = $(`
				<div class="geweb-ai-response-details-header">
					<div class="geweb-ai-response-details-title">${t('responseDetails', 'Response details')}</div>
					<button type="button" class="geweb-ai-response-details-close" aria-label="Close details" title="Close details"><span aria-hidden="true">&times;</span></button>
				</div>
			`);

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

			const $requestSection = this.buildRequestContextDetails(normalizedMeta);
			if ($requestSection) {
				$details.append(this.buildResponseDetailsSection(
					t('requestMetaTitle', 'Request context'),
					$requestSection,
					false
				));
				hasContentSection = true;
			}

			const $thoughtHistorySection = this.buildThoughtHistoryDetails(normalizedMeta);
			if ($thoughtHistorySection) {
				$details.append(this.buildResponseDetailsSection(
					t('thoughtHistoryTitle', 'Gedachtegang'),
					$thoughtHistorySection,
					false
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
					false
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

		buildRequestContextDetails(meta) {
			const requestMeta = normalizeObject(meta?.request);
			if (!Object.keys(requestMeta).length) {
				return null;
			}

			const entries = [];
			pushLabeledEntry(entries, 'Compacted', requestMeta.compacted ? 'yes' : 'no');
			pushLabeledEntry(entries, 'Model', requestMeta.model);
			pushLabeledEntry(entries, 'Temporary prompt', requestMeta.temporary_prompt_active ? 'yes' : 'no');
			if (Number(requestMeta.context_message_count || 0) > 0) {
				pushLabeledEntry(entries, 'Context messages sent', `${Number(requestMeta.context_message_count)}`);
			}
			if (Number(requestMeta.excluded_source_count || 0) > 0) {
				pushLabeledEntry(entries, 'Excluded sources', `${Number(requestMeta.excluded_source_count)}`);
			}
			if (Number(requestMeta.thought_history_updates || 0) > 0) {
				pushLabeledEntry(entries, 'Denkupdates', `${Number(requestMeta.thought_history_updates)}`);
			}
			pushLabeledEntry(entries, 'Context summary', requestMeta.context_summary);

			const $wrapper = $('<div class="geweb-ai-request-details"></div>');
			if (entries.length) {
				$wrapper.append(this.buildResponseDetailsList(entries));
			}

			const requestAttempts = Array.isArray(meta?.request_attempts)
				? meta.request_attempts.filter((item) => item && typeof item === 'object')
				: [];
			if (requestAttempts.length) {
				const $attemptList = $('<ol class="geweb-ai-response-details-inline-list"></ol>');
				const toTimestampMs = (value) => {
					const numeric = Number(value || 0);
					return numeric > 0 && numeric < 1000000000000 ? numeric * 1000 : numeric;
				};
				requestAttempts.forEach((attempt) => {
					const startedAt = this.formatThoughtHistoryTimestamp(toTimestampMs(attempt.started_at));
					const finishedAt = this.formatThoughtHistoryTimestamp(toTimestampMs(attempt.finished_at));
					const model = String(attempt.model || requestMeta.model || meta.model_version || meta.model || '').trim();
					const httpCode = Number(attempt.http_code || 0);
					const status = String(attempt.status || '').replaceAll('_', ' ').trim();
					const elapsedMs = Number(attempt.elapsed_ms || 0);
					const retryTriplet = String(attempt.retry_triplet || '').trim();
					const bits = [];

					if (startedAt) {
						bits.push(`started ${startedAt}`);
					}
					if (finishedAt) {
						bits.push(`finished ${finishedAt}`);
					}
					if (elapsedMs > 0) {
						bits.push(`${elapsedMs} ms`);
					}
					if (httpCode > 0) {
						bits.push(`HTTP ${httpCode}`);
					}
					if (status) {
						bits.push(status);
					}
					if (model) {
						bits.push(`model ${model}`);
					}

					const label = retryTriplet
						? `Attempt ${retryTriplet}`
						: `Attempt ${Number(attempt.attempt || 0) || $attemptList.children().length + 1}`;
					const $line = $('<li></li>');
					$line.append($('<strong></strong>').text(`${label}: `));
					$line.append(document.createTextNode(bits.join(' · ')));
					$attemptList.append($line);
				});

				$wrapper.append(this.buildResponseDetailsSection(
					'Request attempts',
					$('<div></div>').append($attemptList),
					true
				));
			}

			const excludedSources = Array.isArray(requestMeta.excluded_sources)
				? requestMeta.excluded_sources.map((item) => String(item || '').trim()).filter(Boolean)
				: [];
			if (excludedSources.length) {
				const $excludedList = $('<ul class="geweb-ai-response-details-inline-list"></ul>');
				excludedSources.forEach((item) => {
					$excludedList.append($('<li></li>').text(item));
				});
				$wrapper.append(this.buildResponseDetailsSection(
					'Excluded source labels',
					$('<div></div>').append($excludedList),
					false
				));
			}

			const messagePreview = Array.isArray(requestMeta.messages_preview)
				? requestMeta.messages_preview.filter((item) => item && typeof item === 'object')
				: [];
			if (messagePreview.length) {
				const $previewList = $('<ol class="geweb-ai-response-details-inline-list"></ol>');
				messagePreview.forEach((item) => {
					const role = String(item.role || 'user').trim() || 'user';
					const content = String(item.content || '').trim();
					if (!content) {
						return;
					}

					const $line = $('<li></li>');
					$line.append($('<strong></strong>').text(`${role}: `));
					$line.append(document.createTextNode(content));
					$previewList.append($line);
				});

				if ($previewList.children().length) {
					$wrapper.append(this.buildResponseDetailsSection(
						'Messages sent (preview)',
						$('<div></div>').append($previewList),
						false
					));
				}
			}

			return $wrapper;
		},

		/**
		 * Builds the thought history timeline DOM element from AI metadata.
		 *
		 * @param {Object} meta - The response metadata object
		 * @param {Array<{
		 *   thoughts?: string[],
		 *   elapsed_ms?: number|string,
		 *   changed_at_ms?: number|string,
		 *   label?: string
		 * }>} [meta.thought_history] - Timeline of model thoughts
		 * @returns {jQuery|null} The constructed list element or null if empty
		 */
		buildThoughtHistoryDetails(meta) {
			const history = Array.isArray(meta?.thought_history)
				? meta.thought_history.filter((entry) => entry && typeof entry === 'object')
				: [];
			if (!history.length) {
				return null;
			}

			// Pass 1: for each unique thought key (heading), record timing from the first
			// snapshot where that thought was the last (actively growing) one.
			// Store raw ms values so we can compute deltas between thoughts in pass 2.
			const timingRawByKey = new Map();
			history.forEach((entry) => {
				const thoughts = (Array.isArray(entry.thoughts) ? entry.thoughts : [])
					.map((item) => String(item || '').trim()).filter(Boolean);
				if (!thoughts.length) {
					return;
				}
				const lastThought = thoughts.at(-1);
				const section = extractThoughtHeading(lastThought);
				const key = section ? section.title : lastThought;
				if (timingRawByKey.has(key)) {
					return;
				}
				timingRawByKey.set(key, {
					elapsedMs: Number(entry.elapsed_ms || 0),
					changedAtMs: Number(entry.changed_at_ms || 0),
				});
			});

			// Pass 2: render the final snapshot's thoughts, each with its timing + delta.
			const lastEntry = history.at(-1);
			const finalThoughts = (Array.isArray(lastEntry.thoughts) ? lastEntry.thoughts : [])
				.map((item) => String(item || '').trim()).filter(Boolean);
			if (!finalThoughts.length) {
				return null;
			}

			const $list = $('<ul class="geweb-ai-thought-history-list"></ul>');
			let previousChangedAtMs = 0;
			finalThoughts.forEach((thought) => {
				const section = extractThoughtHeading(thought);
				const key = section ? section.title : thought;
				const raw = timingRawByKey.get(key) || {};
				const elapsedMs = Number(raw.elapsedMs || 0);
				const changedAtMs = Number(raw.changedAtMs || 0);

				const timingBits = [];
				if (elapsedMs > 0) {
					timingBits.push(`T+${this.formatElapsedMilliseconds(elapsedMs)}`);
				}
				if (changedAtMs > 0 && previousChangedAtMs > 0) {
					const deltaMs = changedAtMs - previousChangedAtMs;
					if (deltaMs > 0) {
						timingBits.push(`+${this.formatElapsedMilliseconds(deltaMs)}`);
					}
				}
				if (changedAtMs > 0) {
					timingBits.push(this.formatThoughtHistoryTimestamp(changedAtMs));
				}
				if (changedAtMs > 0) {
					previousChangedAtMs = changedAtMs;
				}

				const timingText = timingBits.length ? `(${timingBits.join(' | ')})` : '';
				const $item = $('<li class="geweb-ai-thought-history-item"></li>');
				const $body = $('<div class="geweb-ai-thought-history-item-body"></div>');
				if (section) {
					const $line = $('<div class="geweb-ai-thought-history-intro-line"></div>');
					$line.append($('<strong></strong>').text(section.title));
					if (timingText) {
						$line.append(document.createTextNode(` ${timingText}`));
					}
					$line.append(document.createTextNode(` ${section.body}`));
					$body.append($line);
				} else {
					if (timingText) {
						$body.append($('<span class="geweb-ai-thought-history-timing"></span>').text(`${timingText} `));
					}
					$body.append($(this.renderFormattedChunkHtml(thought)));
				}
				$item.append($body);
				$list.append($item);
			});

			return $list.children().length ? $list : null;
		},

		formatElapsedMilliseconds(value) {
			const elapsedMs = Number(value || 0);
			if (!Number.isFinite(elapsedMs) || elapsedMs <= 0) {
				return '';
			}

			if (elapsedMs >= 1000) {
				return `${(elapsedMs / 1000).toFixed(1)}s`;
			}

			return `${Math.round(elapsedMs)}ms`;
		},

		formatThoughtHistoryTimestamp(value) {
			const timestampMs = Number(value || 0);
			if (!Number.isFinite(timestampMs) || timestampMs <= 0) {
				return '';
			}

			try {
				return new Intl.DateTimeFormat(undefined, {
					hour: '2-digit',
					minute: '2-digit',
					second: '2-digit',
					hour12: false,
				}).format(new Date(timestampMs));
			} catch (error) {
				// eslint-disable-next-line no-console
				console.debug('Formatting thought-history timestamp failed.', error);
				return '';
			}
		},

		buildResponseDetailsSection(title, $content, isOpen) {
			const $section = $('<details class="geweb-ai-response-details-section"></details>');
			if (isOpen) {
				$section.attr('open', 'open');
			}

			$section.append(`
				<summary class="geweb-ai-response-details-section-summary">
					<span class="geweb-ai-response-details-section-title">${title}</span>
				</summary>
			`);
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

			pushLabeledEntry(entries, 'Provider', meta.provider);
			pushLabeledEntry(entries, 'Model', meta.model_version || meta.model);
			pushLabeledEntry(entries, 'Prompt name', prompt.name || prompt.scope);
			pushLabeledEntry(entries, 'Prompt', prompt.text || prompt.preview);
			pushLabeledEntry(entries, 'Prompt scope', prompt.scope);
			pushLabeledEntry(entries, 'Prompt mode', prompt.mode);
			pushLabeledEntry(entries, 'Response ID', meta.response_id);
			pushLabeledEntry(entries, 'Finish', candidate.finish_reason || candidate.finish_message);
			if (usage.total_tokens) {
				pushLabeledEntry(entries, 'Tokens', `${usage.total_tokens}`);
			}
			if (meta.estimated_cost_usd !== undefined) {
				pushLabeledEntry(entries, 'Estimated cost', `$${Number(meta.estimated_cost_usd).toFixed(6)}`);
			}
			if (Array.isArray(meta.request_attempts) && meta.request_attempts.length) {
				pushLabeledEntry(entries, 'Request attempts', `${meta.request_attempts.length}`);
			}

			const chunks = this.getGroundingChunks(grounding);
			if (chunks.length) {
				pushLabeledEntry(entries, 'Grounding chunks', `${chunks.length}`);
			}

			const supports = Array.isArray(grounding?.groundingSupports) ? grounding.groundingSupports : [];
			if (supports.length) {
				pushLabeledEntry(entries, 'Grounding supports', `${supports.length}`);
			}

			return entries;
		},
	});
})(jQuery);
