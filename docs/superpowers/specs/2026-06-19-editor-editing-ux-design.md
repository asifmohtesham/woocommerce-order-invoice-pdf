# Editor editing UX — slice 4 (design)

**Date:** 2026-06-19
**Status:** Approved for planning
**Scope:** Make the preloaded GrapesJS design editable, and add a WP-block-style `/` + toolbar variable/block inserter with live value previews. No server-render, token-merge, storage, or REST changes.

## Goal

Today the saved/preloaded invoice design is **not editable** in the editor (table cells load as non-editable), and inserting a token means hand-typing `{{token}}`. This slice (a) fixes the editability so authors can click into any preloaded field and edit it, and (b) adds a Notion/WordPress-style insert menu — triggered by typing `/` in a field or a toolbar button — that inserts tokens (inline) and layout blocks (as new blocks), each token showing its live value for the selected order.

Builds on slices 1–3 (v1.4.13): `assets/visual-editor/app.js` (GrapesJS app, `TOKEN_META`, layout blocks, `woi-cell`/`woi-table` component types + row/col commands, the preview pane with `currentOrderTokens`), `assets/visual-editor/editor.css`, `includes/Visual/VisualEditorPage.php`.

## The two parts + decisions (locked during brainstorming)

| Part | Item | Decision |
|---|---|---|
| A | #1–3 preloaded fields not editable | **Load-order fix**: register custom component types BEFORE loading the stored design (`init` empty → register types → `setComponents`) so loaded `<td>`s become editable `woi-cell` and tables become `woi-table` |
| B | #4 `/variable` UX | Insert menu via BOTH **typing `/` in an edited field** AND a **toolbar "Insert variable" button**; inserts **tokens** (inline) and **layout blocks** (as a new block after the current one); a **Recent** section (localStorage) |
| B | live preview | Each token row shows its **resolved value for the selected order** (from the cached `currentOrderTokens`); block tokens show a type hint; layout blocks show their description |

## Root cause (Part A) — confirmed via live probe

`grapesjs.init({ components: woiVisual.stored })` parses the saved HTML during init, BEFORE the `editor.DomComponents.addType('woi-cell'|'woi-trow'|'woi-table', …)` calls run (those are after `init` in `app.js`). So loaded `<td>`s resolve to GrapesJS's built-in `cell` type with `editable: false` (probe confirmed all 6 stored cells are `type:"cell", editable:false`, including the `{{shop_name_ar}}`/`{{shop_address_ar}}` cell; stored tables are built-in `table`). Newly-dropped Table blocks work because they're created AFTER registration. Non-table fields (`<h1>`, spans) already load as `type:"text"` editable.

## Part A — fix preloaded editability

In `assets/visual-editor/app.js`, change the boot order:

1. `grapesjs.init({ …, components: '' })` — start with no components (remove the `components: woiVisual.stored || woiVisual.starter` from init options).
2. Keep registering the component types + row/col commands (the slice-3 `woi-cell`/`woi-trow`/`woi-table` block) — they already run after `init`; that's fine as long as components load AFTER them.
3. After the type registrations (and the block/palette setup), load the design once:
   `editor.setComponents( woiVisual.stored || woiVisual.starter || '' );`

Result: loaded `<td>` → `woi-cell` (`editable:true`, `droppable:true`); loaded `<table>` → `woi-table` (with the add/del row/col toolbar). Double-clicking any preloaded cell edits its text. `woi-cell` must keep `editable:true` even when the cell contains only a token (e.g. `{{logo}}`) so it stays clickable. The stored design content is unchanged — only its component typing changes, so `getHtml()`/save/Live-HTML/PDF are unaffected.

Edge: the Live-HTML init (slice 3 `woiFetchOrderTokens(null).then(woiRefreshLiveHtml)`) and the stored-vs-starter selection must still happen; just route the components through `setComponents` instead of the init option.

## Part B — slash + toolbar variable/block inserter

### Catalog

A single ordered catalog assembled in `app.js`:
- **Tokens** — from `TOKEN_META` (17 entries): `{ label, kind:'token', value:'{{'+token+'}}', token, category }`.
- **Layout blocks** — table, divider, spacer, page break, heading: `{ label, kind:'block', blockId, description, category:'Layout' }` (reuse the existing BlockManager block content for each `blockId`).
- **Recent** — up to 6 most-recently-inserted entries, persisted in `localStorage` (key `woiInsertRecent`), rendered as a top group.

### Popup UI (shared by both triggers)

A floating `<div id="woi-insert-menu">` (created once, appended to the admin page, hidden by default), containing a search `<input>` and a results list grouped by `Recent / Shop / Document / Customer / Items & Totals / Layout`. Behaviour: filter-as-you-type (matches label + token name), Up/Down to move highlight, Enter/click to insert, Esc or outside-click to close. Styled in `editor.css`. Each **token** row renders: friendly label (left) + **live value** (right, dimmed, truncated): the value from `currentOrderTokens['{{token}}']` when set, else `woiVisual.sampleData['{{token}}']`, else empty; the HTML block tokens (`logo`, `line_items`, `totals`, `billing_address`) render a hint instead (`[image]`, `[table · N rows]` where N counts `<tr>`, `[table]`). **Block** rows render the description, no value. The preview reads the live cache each time the menu opens, so it reflects the current order.

### Trigger A — toolbar "Insert variable" button

A toolbar button (`id: woi-insert-var`, icon `fa fa-plus-circle`) opens the menu near the toolbar. Insert target resolution: (1) if a field is being text-edited (an active `contenteditable` in the canvas), insert at the caret; (2) else if a component is selected, append into it; (3) else append to the wrapper end.

### Trigger B — type `/` while editing a field

GrapesJS inline text editing puts a `contenteditable` element in the canvas iframe. A listener on the canvas document (`editor.Canvas.getDocument()`) watches `keyup`/`input`: when the active element is `contenteditable` and the user types `/`, record the caret, open the menu positioned at the caret (mapping iframe coords to page coords), and route subsequent typing into the menu's filter. On select, delete the typed `/query` from the contenteditable and insert.

### Insertion semantics

- **Token** → insert the literal `{{token}}` text **inline at the caret** within the editing element (or append where targeted if not mid-edit). After insertion, blur/sync so GrapesJS captures the new HTML.
- **Layout block** → insert the block's component **as a new block after the current block/component** (WP-style), via `editor.addComponents(blockContent, { at: index+1 })` on the parent — not inside inline text.
- Every insert pushes the entry onto the Recent list (localStorage).

### Approach

Vanilla custom popup + a `contenteditable` `keyup`/`input` listener on the canvas document — full control over `/` detection and caret insertion, reusing the existing catalog; no third-party RTE plugin. The caret/`contenteditable` manipulation (insert text at caret, delete the `/query`, map caret coords) is isolated in one small helper module within `app.js`.

## Error handling & edge cases

- **No order loaded:** token previews fall back to `sampleData`, then empty; insertion still works.
- **`/` typed but not in a contenteditable** (e.g., normal canvas): ignored — menu only opens during text edit.
- **Menu open + click elsewhere / Esc:** closes without inserting; any partial `/query` left as typed (we only delete it on a successful select).
- **Block insert with nothing selected:** append the block to the wrapper end.
- **Stored design integrity:** Part A changes only component typing, not content; Part B only inserts where the user chooses. Live tests still save+restore the stored design.
- **localStorage unavailable:** Recent section silently empty; everything else works.
- **iframe coordinate mapping** for caret-positioning the menu: best-effort; if mapping fails, open the menu at a default position near the toolbar.

## Testing

- **JS:** `node --check`. Catalog builder, filter, and recent-list helpers are small pure functions, covered by live verification (no in-repo JS harness).
- **Live verification (harness — see live-testing-harness memory; confirm deployed revision on the Status tab + bump WOI_PDF_VERSION so the new app.js is fetched):**
  - **Part A:** probe loaded `<td>`s → `woi-cell` + `editable:true` (was `cell`/`false`); double-click the header and `{{shop_name_ar}}` cell and edit text; stored table shows the row/col toolbar; stored design unchanged after load.
  - **Part B:** toolbar button opens the menu; typing `/` in an edited field opens it at the caret and filters; token insert puts `{{token}}` inline; block insert adds a new block after the current one; each token row shows the live value for the selected order; Recent populates and persists across reloads; `getHtml()`/save/preview reflect inserted tokens.
  - Regression: Live HTML + PDF tab + save still work.
- **Version:** bump `WOI_PDF_VERSION` (asset cache-bust — required for the new JS/CSS to load).

## Out of scope (later slices)

- Other document types.
- Manual favourites/pinning (Recent is auto only).
- A rich-text formatting toolbar (bold/italic) — only token/block insertion.
- Server-side rendering or REST changes.
