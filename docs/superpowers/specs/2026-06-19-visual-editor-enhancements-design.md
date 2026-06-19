# Visual editor enhancements — slice 2 (design)

**Date:** 2026-06-19
**Status:** Approved for planning
**Scope:** Three cohesive enhancements to the existing **invoice** GrapesJS visual editor — block-set hardening, real-order in-editor preview, and title spacing. Other document types remain deferred.

## Goal

Make the visual invoice-template editor (shipped in slice 1, v1.4.9) more robust and pleasant to author with, without changing the render engine, the token-merge core, or the storage model. Every new control just emits plain HTML/CSS that mPDF already understands (page-break properties, margins), so the only render-path touch is an auto-guard on totals.

Builds on slice 1: `WOI\PDF\Visual\TemplateTokens` (merge), `VisualTemplateStore` (store), `VisualEditorPage` (admin page + `woiVisual` localisation), `assets/visual-editor/app.js` (GrapesJS app), `templates/_visual/visual-document-wrapper.php` (mPDF wrapper), and REST `POST woi-pdf/v1/visual-template` (save).

## Decisions (locked during brainstorming)

| Topic | Decision |
|---|---|
| Required-token validation | **Warn on save, allow anyway** — never blocks. Required set: `{{line_items}}`, `{{totals}}`, `{{invoice_number}}`, `{{invoice_date}}`, `{{billing_address}}` |
| Validation UX | Folded into the **post-save toast** (`Saved — heads up, these are missing: …`), not a confirm dialog |
| Page-break controls | **Page-break block** + **keep-together toggle** + **auto-guard totals** (all three) |
| Page-break mechanism | **CSS div** (`page-break-after:always`), not mPDF's `<pagebreak>` tag (GrapesJS strips unknown tags) |
| Palette | **Group into categories** + **add layout blocks** + **friendly labels/hints** |
| Real-order preview — order pick | **Order search box** (ID/email/name) defaulting to **last order**, with order-number acting as ID override |
| Real-order preview — sample vs real | **Coexist** — keep "Preview sample data", add "Preview real order" |
| Real-order data source | **New REST endpoint reusing `TemplateTokens::map()`** so browser preview content matches the real render |
| Title spacing | **Two separate, styleable title elements**; gap set via Style-Manager margin (no token/render change) |

## Architecture & where the work lands

```
#2 Hardening   → app.js (token metadata, grouped palette, layout + page-break blocks,
                  keep-together style sector, save-time required-token check)
                + templates/_visual/visual-document-wrapper.php
                  (.woi-pagebreak, .woi-spacer, .totals-table page-break-inside:avoid)
#3 Real order  → NEW REST GET woi-pdf/v1/visual-preview-data (token→value map for an order,
                  via TemplateTokens::map) + reuse existing woi_pdf_preview_order_search ajax
                + VisualEditorPage order control bar + app.js previewRealOrder()
#4 Title gap   → assets/visual-editor/starter-invoice.html (two styleable title elements)
                + wrapper default .woi-doc-title CSS
```

No change to the render engine, `TemplateTokens::merge`, or `VisualTemplateStore`.

## Part A — Block-set hardening (#2)

### Token metadata + grouped palette

Replace the flat `tokens` array in `app.js` with a metadata table; each entry drives one palette block:

```
{ token, label, category, hint }
```

Categories and membership:
- **Shop** — logo, shop_name, shop_address, shop_name_ar, shop_address_ar, trn, shop_phone, shop_email
- **Document** — document_title, document_title_ar, invoice_number, invoice_date, order_number, payment_method
- **Customer** — billing_address
- **Items & Totals** — line_items, totals
- **Layout** — 2-column row, divider/spacer, heading, page break (non-token blocks)

Block `label` is the friendly name (e.g. "Logo image", "Order line-items table"); `attributes.title` carries the hint. Block `content` for token blocks is unchanged: `<span data-woi-token="t">{{t}}</span>`.

### Layout blocks (Layout category)

- **2-column row** — `<table class="woi-row"><tr><td>…</td><td>…</td></tr></table>` (tables, not flex/grid — mPDF-safe).
- **Divider / spacer** — `<div class="woi-spacer"></div>` (default height via wrapper CSS) and a plain `<hr>`.
- **Heading** — `<h2>Section heading</h2>`.

### Page breaks

- **Page-break block** — `<div class="woi-pagebreak"></div>`; wrapper CSS: `.woi-pagebreak{page-break-after:always;}`. In-canvas it shows as a labelled dashed marker (editor-only CSS).
- **Keep-together toggle** — a custom "Print" Style-Manager sector exposing one property that sets `page-break-inside:avoid` on the selected component.
- **Auto-guard totals** — server-side: add `.totals-table{page-break-inside:avoid;}` to `visual-document-wrapper.php`. Always on; needs no author action.

### Required-token validation (warn, non-blocking)

A pure helper `missingRequiredTokens(html)` returns the subset of `REQUIRED = ['line_items','totals','invoice_number','invoice_date','billing_address']` not present in the design HTML. On Save, the design is **always persisted**; if the helper returns a non-empty list, the success notice reads `Saved — heads up, these are missing: {{…}}, {{…}}`. No modal, no cancel.

## Part B — Real-order in-editor preview (#3)

### New REST endpoint

`GET woi-pdf/v1/visual-preview-data`
- **Permission:** `current_user_can('manage_woocommerce')` (+ cookie nonce, like the save route).
- **Params:** `order_id` (int, optional → resolves to the most recent order), `doc_type` (string, default `invoice`).
- **Handler `handle_visual_preview_data($request)`:** resolve the order (given id, else latest), build the document for it, call `( new TemplateTokens() )->map( $document )`, and return:
  ```json
  { "order_id": 239, "order_label": "#239 — John Buyer", "tokens": { "{{line_items}}": "<table…>", "{{shop_name}}": "Acme…", … } }
  ```
- Returns `WP_Error` 404 if no order can be resolved, 403 on capability failure.

Registered **unconditionally** in `Rest.php` via the same ungated `register_visual_template_route()` pattern (extend it to register both visual routes), so it is reachable in production.

### Order selection UI

`VisualEditorPage::render_page()` adds a thin control bar above `#woi-visual-editor`:
- An **order search input** (ID/email/name) that calls the existing `woi_pdf_preview_order_search` admin-ajax action and shows a results dropdown.
- A read-out of the **currently selected order** (defaults to "last order").
- A **"Preview real order"** button.

`woiVisual` gains: `previewDataUrl` (REST URL for the new endpoint), `orderSearchNonce` + `orderSearchAction` (for the existing search ajax). The control-bar markup is printed server-side; `app.js` wires its behaviour.

### app.js wiring

- `previewRealOrder()`: read selected `order_id` (or none → last) → `GET previewDataUrl?order_id=…&doc_type=invoice` → on success, merge `response.tokens` into `getHtml()` using the existing token-merge path (split/join + strip leftovers) → open the result as a Blob preview tab (same mechanism as `mergeSample`).
- Coexists with the existing **Preview sample data** (static, no order) and **Preview real PDF** (true mPDF) buttons.

## Part C — Title spacing (#4)

Purely the starter markup + default CSS. In `starter-invoice.html`, render the title as two distinct styleable elements in a container:

```html
<div class="woi-doc-title">
  <span class="title-en">{{document_title}}</span>
  <span class="title-ar" dir="rtl">{{document_title_ar}}</span>
</div>
```

instead of both inside one `<h1>` separated by a literal space. The wrapper carries minimal defaults (`.woi-doc-title` centered with a sensible default gap; `.title-en`/`.title-ar` as headings). The author then sets the gap via the Style Manager (margin) or stacks the two. `document_title` and `document_title_ar` remain separate token blocks (already the case), so either can be placed/styled independently.

## Error handling & edge cases

- **No orders exist** (real-order preview) → endpoint returns 404; `app.js` alerts "No order found to preview."
- **Order resolves but a block renderer throws** → already guarded by slice-1 `TemplateTokens` try/catch (block tokens degrade to empty); the map still returns.
- **Missing required tokens** → never blocks save (by design); surfaced in the toast.
- **Page-break / keep-together CSS** mPDF doesn't honor in some odd nesting → out of scope to police; the "Preview real PDF" loop is how the author catches it.
- **Capability + nonce** enforced on the new REST route; order-search reuses the existing nonced ajax.
- **Backward compatibility:** an already-saved slice-1 template keeps working; the new palette/blocks/CSS are additive. The title-structure change affects only the *starter* (offered when no design is stored), not stored designs.

## Testing

- **PHP unit (PHPUnit + Brain Monkey, run `php -d display_errors=1 -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit`):**
  - `visual-preview-data` handler: returns the token map for a stubbed document; defaults to last order when `order_id` omitted; 404 when no order; cap enforced. Mirrors `VisualRestTest`.
- **JS logic:** `missingRequiredTokens()` and the real-order merge are factored as small pure functions; no JS test harness exists in-repo, so they are covered by live verification.
- **Live verification (harness — see live-testing-harness memory):** grouped palette + layout/page-break blocks render; a missing-token save shows the warning toast; a page-break block + keep-together produce a multi-page PDF with totals intact; "Preview real order" shows a real order's content; the title gap is adjustable and renders correctly (rasterize the real PDF to check Arabic + spacing).

## Out of scope (later slices)

- Other document types (credit-note, packing-slip, proforma, receipt).
- Real-order preview using mPDF in-canvas (browser preview stays approximate; "Preview real PDF" remains the fidelity check).
- Persisting the chosen preview order across sessions.
- Undo/redo or template versioning.
