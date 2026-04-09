# AI Search UI Spec

This file is the lightweight design spec for the frontend AI Search workspace.

It is intended to sit between:
- visual design decisions
- frontend markup/CSS
- browser verification with `npm run wp:test`

It is not a replacement for Figma, but it should be detailed enough to keep UI work consistent inside VS Code.

## Goals

- Keep the workspace calm and readable.
- Avoid surprise layout shifts.
- Make side panes predictable across desktop, half-screen, and mobile.
- Keep source interaction lightweight: inspect, temporarily exclude, compare.
- Preserve a clear difference between:
  - group-managed configuration
  - temporary chat-level overrides

## Core Screens

The UI should always be reviewed in these four layouts:

1. Desktop
   Width: full laptop/desktop
   Layout: left pane, main pane, right pane all visible

2. Half-screen desktop
   Width: roughly half a laptop screen
   Layout: all panes still readable if possible; collapse only when truly needed

3. Mobile portrait
   Device target: Pixel 9a-like
   Layout: side panes collapse to icon-width when space is insufficient

4. Mobile landscape
   Device target: Pixel 9a-like rotated
   Layout: same rules as portrait, but allow more horizontal recovery where possible

## Layout Regions

### 1. Workspace Header

Class anchors:
- `.geweb-ai-page-toolbar`
- `.geweb-ai-page-toolbar-title`
- `.geweb-ai-page-toolbar-actions`
- `.geweb-ai-page-toolbar-button`

Purpose:
- top-level workspace controls
- not a heavy card
- should read as a light strip above the workspace

Rules:
- light gray background is allowed
- avoid boxed/capsule framing for the whole header
- `AI Workspace` label is subtle, not dominant
- `Align` and `Settings` belong on the right
- on small mobile widths, actions may become icon-only

### 2. Search Results Panel

Class anchors:
- `.geweb-ai-search-results-panel`
- `.geweb-ai-search-results-header`
- `.geweb-ai-search-results-content`

Purpose:
- show normal WordPress search results inside the workspace

Rules:
- should visually connect to the workspace, not compete with it
- header may have a subtle gray tint
- panel spacing should stay compact
- avoid redundant separator lines above it

### 3. Left Pane: Chats

Class anchors:
- `.geweb-ai-sidebar`
- `.geweb-ai-overview-header`
- `.geweb-ai-panel-heading`
- `.geweb-ai-conversation-overview`
- `.geweb-ai-current-conversation`

Purpose:
- current chat
- chat archive
- new/manage actions

Rules:
- header stays on one row whenever space allows
- hide/collapse control is always right-aligned in open state
- collapsed state keeps a visible stub column
- collapsed state can show icon-only chat affordances
- no full disappearance of the pane

### 4. Main Pane: Conversation

Class anchors:
- `.geweb-ai-main-panel`
- `.answer-box`
- `.question-box`

Purpose:
- display conversation
- show footnotes
- allow asking the next question

Rules:
- content scrolling must not unexpectedly move the whole workspace
- footnote hover may preview related source state
- footnote hover must not cause large layout jumps
- click may activate/open a source more strongly than hover

### 5. Right Pane: Source References

Class anchors:
- `.geweb-ai-sources-panel`
- `.geweb-ai-sources`
- `.geweb-ai-source-list`
- `.geweb-ai-source-item-header`
- `.geweb-ai-source-link`
- `.geweb-ai-source-details`

Purpose:
- show sources used by the current answer or restored chat
- allow temporary per-chat source inclusion/exclusion

Rules:
- `Source References` may wrap only when space is genuinely tight
- settings/manage action stays near the title
- hide/collapse control is right-aligned
- collapsed state keeps a visible stub column
- if there are no items, collapsed state should not show stray empty text

## Source Row Spec

Class anchors:
- `.geweb-ai-source-list li`
- `.geweb-ai-source-link`
- `.geweb-ai-source-link-hint`
- `.geweb-ai-source-filter-toggle`
- `.geweb-ai-source-filter-checkbox`
- `.geweb-ai-source-details`

Each source row has:
- list number
- source title button
- optional source hint pill
- temporary include/exclude checkbox
- expandable context details

Rules:
- list number is visually quiet
- source title is primary
- source hint is a small pill, not plain inline text
- temporary source control is a checkbox
- checked means: included
- unchecked means: temporarily excluded for the next question
- checkbox has tooltip; no visible explanatory text needed
- excluded rows may look slightly muted, but should remain readable

## Footnote Interaction Spec

Related code:
- `assets/script.js`
- `assets/js/ai-sources.js`

Hover:
- may preview the matching source row
- may preview the best matching context
- must not auto-scroll the whole workspace
- should not auto-expand the right pane if that expansion causes jumpiness

Click:
- may activate the source row
- may expand the right pane
- may scroll the source row into view if needed

Preview tooltip:
- small, contextual, anchored near the footnote
- never causes layout reflow

## Pane Collapse Spec

Related classes:
- `.is-left-collapsed`
- `.is-right-collapsed`
- `.geweb-ai-panel-collapse`
- `.geweb-ai-panel-reopen`

Rules:
- collapsing a pane never removes it completely
- a stub width remains at least the width of a single icon button
- reopen button lives inside the pane stub, not in the center area
- collapsing one pane must not alter the other pane’s header layout
- hide/unhide controls remain visually aligned to the right in open state
- on very small widths, automatic collapse to icon-width is acceptable

## Responsive Rules

### Desktop

- all three primary regions visible
- source titles and pills readable
- toolbar buttons may show icon + label

### Half-screen

- preserve small but visible gap between panes
- do not let headers wrap prematurely
- only collapse panes when layout is truly too narrow

### Mobile

- toolbar actions may become icon-only
- side panes may auto-collapse to icon-width
- avoid long text labels in controls
- preserve tap targets at comfortable size

## Visual Tokens

These are directionally defined here; exact values may evolve in CSS.

### Color

- workspace grays should stay cool and muted
- active blue should be reserved for meaningful focus or source emphasis
- avoid accidental bright blue browser-default button/icon rendering
- destructive/excluded state uses a soft red tint, not aggressive warning red

### Radius

- large containers: soft rounded corners
- small controls: rounded pills or rounded rectangles
- page toolbar itself should be flatter than a pill card

### Spacing

- horizontal spacing should be tighter than vertical whitespace
- pane headers should feel compact
- source rows should have enough breathing room for details, but no dead air

## Current Component Mapping

Use this when changing markup/CSS.

- `Workspace/Header`
  - `.geweb-ai-page-toolbar`

- `Workspace/SearchResults`
  - `.geweb-ai-search-results-panel`

- `Pane/Header`
  - `.geweb-ai-panel-heading`
  - `.geweb-ai-panel-heading-main`
  - `.geweb-ai-panel-heading-actions`
  - `.geweb-ai-panel-collapse`

- `Chat/CurrentCard`
  - `.geweb-ai-current-conversation`

- `Chat/List`
  - `.geweb-ai-conversation-overview`

- `Source/Row`
  - `.geweb-ai-source-list li`

- `Source/PrimaryAction`
  - `.geweb-ai-source-link`

- `Source/HintPill`
  - `.geweb-ai-source-link-hint`

- `Source/IncludeCheckbox`
  - `.geweb-ai-source-filter-toggle`
  - `.geweb-ai-source-filter-checkbox`

- `Source/ContextBlock`
  - `.geweb-ai-source-details`
  - `.geweb-ai-source-context-item`
  - `.geweb-ai-source-single-context`

## Acceptance Checks

Before calling a UI tweak done, verify all of these:

1. Left pane open: hide button right-aligned
2. Right pane open: hide button right-aligned
3. Collapse left: right header unchanged
4. Collapse right: left header unchanged
5. Both collapsed: both stubs visible
6. Footnote hover: no big workspace jump
7. Source row: checkbox checked means included
8. Source row: hint pill still visible
9. Half-screen: panes keep a visible gap
10. Mobile: icon-only controls remain understandable

## Workflow

Recommended flow for future UI work:

1. Update this file first when changing behavior or intent.
2. Implement CSS/markup changes.
3. Run `npm run wp:test`.
4. Review:
   - desktop
   - narrow desktop
   - mobile portrait
   - mobile landscape
5. If needed, refine this spec so it matches the built result.
