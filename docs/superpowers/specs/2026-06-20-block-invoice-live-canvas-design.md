# Block Invoice Template — Live A4 WYSIWYG Canvas

**Date:** 2026-06-20
**Status:** Approved (brainstorming)
**Surface:** `src/block-editor/` (the WP Block authoring surface; coexists with GrapesJS — see `wp-block-editor-feature` memory)

## Problem

The Block Invoice Template editor currently shows **static placeholder text** in the
canvas (e.g. `woi/shop-name` renders "Acme Trading LLC", `woi/line-items` renders
"[ line items table ]"). Real merged data only appears in a **separate side preview
panel** (Live HTML + PDF tabs). The canvas is a plain white box (`min-height: 60vh`),
not sized as an A4 page, and the order picker is a bare "Find" text box rather than
the rich combobox used by the GrapesJS editor.

The user wants the **canvas itself** to be the live, A4-accurate WYSIWYG document.

## Goals

1. Every invoice token/dynamic block renders the **selected order's real merged
   value inline** in the canvas (not placeholder labels).
2. **No separate Live HTML view** — the canvas replaces it. Keep the PDF tab for
   now (becomes a plain "Export to PDF" action later, once canvas == PDF; not built
   in this work).
3. The canvas is **ISO A4 WYSIWYG**: 210mm-wide page, 15mm margins, styled with the
   same document CSS the PDF uses.
4. The order picker is the **same combobox as GrapesJS** — focus shows recents,
   debounced filter, rows with order metadata, loading feedback — driving the canvas.
5. **Typing an order # → Enter** fetches that order directly.
6. The A4 container is **page-wise scrollable with mm rulers on the x and y axes**
   (rulers + dashed page-break guide lines every 297mm; continuous scroll, not true
   reflow pagination).

## Non-goals

- True reflow pagination (content broken into discrete A4 sheets). Out of scope —
  mPDF does that; the canvas shows page-break guide lines instead, matching GrapesJS.
- Changing block `save()` output, stored markup, the render path, or any REST/AJAX
  backend. This is a **front-end restructuring**; all data needed already exists.
- Building the eventual "Export to PDF" replacement for the PDF tab. The PDF.js tab
  stays for now.
- Touching the GrapesJS editor (`VisualEditorPage`).

## Key facts that make this a front-end-only change

- The order token map (both `woi_pdf_visual_sample_data()` and the live
  `visual-preview-data` REST endpoint) already includes `{{line_items}}` and
  `{{totals}}` as **rendered HTML table strings**, and `{{logo}}` / `{{billing_address}}`
  as HTML. So a block `edit()` can render real content directly.
- `woi_pdf_preview_order_search` (admin-ajax) already returns rich per-order
  metadata (`total_raw`, `date_raw`, `payment_method`, `line_count`, `unit_count`)
  plus name/company fields — the same data the GrapesJS combobox consumes
  (see `order-picker-combobox` memory).
- `woiBlocks` localize already exposes `previewCss` (shared `woi_pdf_visual_document_css()`),
  `sampleData`, `previewDataUrl`, `ajaxUrl`, `previewNonce`, `orderSearchAction`,
  `docType`, `pdfWorkerUrl`.

## Architecture

### Decision: iframe A4 canvas (Option A)

Render the `BlockList` inside `@wordpress/block-editor`'s `BlockCanvas` (stable since
WP 6.3), an iframe into whose `<head>` we inject the shared document CSS + an A4 page
shim. CSS is fully isolated from wp-admin, so the canvas can match the PDF exactly.
The block toolbar/inserter live **outside** the iframe and keep working via the
existing `SlotFillProvider` + `<Popover.Slot />` wiring.

Rejected alternative (Option B): scoped-CSS in-DOM canvas — simpler scroll/rulers but
wp-admin CSS bleeds in and PDF parity stays approximate (two prior "legacy CSS pitfall"
incidents in memory). Retained only as a runtime fallback when `BlockCanvas` is
unavailable.

### Components

**1. Live token store — `src/block-editor/previewStore.js`**
A `@wordpress/data` store registered as `woi/preview`, state
`{ tokens, orderLabel, orderId, loading }`, seeded from `window.woiBlocks.sampleData`.

- Selectors: `getTokens()`, `getOrderLabel()`, `getOrderId()`, `isLoading()`.
- Actions: `setLoading(bool)`, `setOrder({ tokens, orderLabel, orderId })`.
- Chosen over React context because block `edit()` components render deep inside
  `BlockList` (and, with the iframe, across a portal boundary) — a global data store
  reaches them cleanly; context is fragile across the portal.

**2. Token blocks render live — `src/block-editor/blocks/token.js`**
Each `woi/*` token block's `edit()` reads `getTokens()` via `useSelect` and renders
the merged value for its own `token`:
- Text tokens (shop name, address, TRN, invoice number, dates, payment, etc.) →
  rendered as text.
- HTML tokens (`{{logo}}`, `{{billing_address}}`, `{{line_items}}`, `{{totals}}`) →
  `dangerouslySetInnerHTML` (server-trusted HTML from the token map).
- Empty/missing value → a subtle dashed-outline placeholder showing the friendly
  label, so an empty block stays visible/selectable.
- `save()` is **unchanged** — still emits the literal `{{token}}`. No block-validation
  or stored-markup change.
- A shared helper decides text-vs-HTML per token (small map; HTML token set is the
  four above). Unit-testable as a pure function.

`woi/text` (free RichText) stays literally editable — no merge while editing.

**3. A4 iframe canvas — `src/block-editor/canvas/A4Canvas.js`**
Wraps `BlockCanvas` with `styles = [{ css: previewCss }, { css: A4_SHIM_CSS }]`.
`A4_SHIM_CSS` (new constant, mirrors the GrapesJS/preview shim):
- `body { width: 210mm; padding: 15mm; margin: 0; box-sizing: border-box; background:#fff }`
- strips WP block chrome inside the iframe (block outlines, `:root` appender
  margins, default `.block-editor-block-list__layout` padding) so the canvas reads
  as the document.
- `.woi-pagebreak` → dashed divider (as today).

Iframe auto-sizes to content height (BlockCanvas resize behavior); the **outer**
container scrolls, so rulers share the scroll coordinate space.

If `BlockCanvas` is not exported by the installed `@wordpress/block-editor`, fall
back to the current in-DOM `BlockList` rendering wrapped in a scoped `.woi-a4-page`
(Option B), behind a capability check.

**4. mm rulers + page guides + scroll — `src/block-editor/canvas/Rulers.js` + CSS**
Layout inside the gray scroll container (`.woi-a4-scroll`):
- **Top ruler**: sticky, 0–210mm, tick marks (1mm minor, 10mm major + label),
  printable area (15–195mm) subtly shaded.
- **Left ruler**: continuous mm down the full content height, **bold tick + page
  number at each 297mm boundary**.
- **A4 page**: the iframe at true `210mm` width.
- **Page-break guides**: dashed horizontal lines overlaid at every 297mm of content.
- Real CSS `mm` units throughout keep rulers and content in lockstep.
- Tick generation is a pure function (count, interval) → unit-testable.

**5. Order combobox — `src/block-editor/OrderPicker.js`**
A React component replicating the GrapesJS combobox UX against the **same**
`woi_pdf_preview_order_search` action:
- Focus (empty term) → 5 most recent orders.
- Typing → debounced (~300ms) filtered search.
- Each row: `name · amount · N items / M units · date · payment` (name =
  company → first/last → "(no name)"; reuse the row-title/metadata shaping from the
  existing `preview.js` `orderRowTitle` + the raw metadata keys).
- Loading feedback (spinner/"Searching…") and an "Order: <label>" indicator.
- **Enter handling**: if the current term is purely numeric, Enter fetches that
  order directly by number (skip the results list); otherwise Enter runs the search.
- On select/fetch: dispatch `woi/preview.setLoading(true)`, call the existing
  `fetchOrderTokens(id)` (`visual-preview-data` REST), then `setOrder({...})`.
- Lives in the **main editor toolbar** (top), since it now drives the canvas, not
  just the side panel.

**6. Preview panel → PDF-only — `src/block-editor/PreviewPanel.js`**
- Remove the Live HTML tab, its iframe, and the bar's own order search box (the
  toolbar combobox + live canvas replace them).
- Keep the A4 PDF.js tab (`pdfPreview.js`) unchanged; it reads the selected
  `orderId` from the `woi/preview` store instead of local panel state.
- `preview.js` helpers used only by the removed Live HTML path
  (`renderedHtmlFromBlocks`, `mergeTokens`, `wrapForPreview` for the panel) are
  retargeted: the per-block merge helper moves to a shared module reused by the
  token blocks; `fetchOrderTokens` / `fetchOrders` / `orderRowTitle` are reused by
  the new `OrderPicker`.

### Data flow

```
OrderPicker (toolbar)
  → fetchOrders(term)            [woi_pdf_preview_order_search AJAX]  (search)
  → fetchOrderTokens(id)         [visual-preview-data REST]           (select / Enter#)
  → dispatch woi/preview.setOrder({ tokens, orderLabel, orderId })
        │
        ├─→ token block edit()s  read getTokens() → render merged value in A4 canvas
        └─→ PDF tab              reads getOrderId() → renderPdfPreview(orderId)
```

Stored markup, `save()` output, and the mPDF render path are untouched.

## Layout modes

The existing full / stack / overlay layout modes (`layout.js`) remain. With the side
panel now PDF-only, "stack"/"overlay" govern where the PDF tab sits relative to the
A4 canvas. Default stays `full`.

## Testing

- **Unit (pure functions):** per-token text-vs-HTML merge helper; order-# Enter
  detection (numeric term → direct fetch); ruler tick generation (count/interval →
  marks); `orderRowTitle`/metadata shaping (already covered server-side).
- **Manual live-acceptance** on b2b.milanoleather.ae via the debug-Chrome harness
  (`live-testing-harness` memory): pick an order from the combobox → canvas shows
  real shop/billing/line-items/totals at A4 width with mm rulers and page guides;
  type an order # + Enter → same; PDF tab still renders; flip source blocks↔grapesjs.
- **Build:** `npm run build` (root multi-entry webpack; keep `output.clean:false`).
- **Version bump:** `Version:` header + `public string $version` (WOI_PDF_VERSION)
  in lockstep; coordinate per `version-coordination` memory before bumping.

## Risks / watch-points

- `BlockCanvas` API surface across WP versions → capability check + Option-B fallback.
- Rulers must track the iframe's auto-sized height; if BlockCanvas doesn't expose
  content height cleanly, measure via a `ResizeObserver` on the iframe element.
- `dangerouslySetInnerHTML` for table/logo tokens uses server-trusted token-map HTML
  only (same trust boundary the existing previews already rely on).
- Empty `{{line_items}}`/`{{totals}}` (no order picked yet) → sample data covers it;
  ensure the dashed placeholder doesn't break table layout.

## Follow-ups (not in this work)

- Replace the PDF.js tab with a plain "Export to PDF" button once canvas fidelity
  matches mPDF output exactly.
