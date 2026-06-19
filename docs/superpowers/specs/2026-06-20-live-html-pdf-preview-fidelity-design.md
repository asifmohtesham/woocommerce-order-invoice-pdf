# Live-HTML ↔ PDF preview fidelity — Design

Date: 2026-06-20
Status: Approved (pending spec review)

## Problem

The Visual Invoice Template editor offers two previews of the same document:

- **Live HTML** tab — a fast browser render in an `<iframe>`.
- **PDF** tab — the real mPDF output (rendered via PDF.js).

For order #237 these diverge badly. The Live HTML preview looks "nice" (large
product thumbnails, roomy columns, bilingual column/total labels stacked
English-over-Arabic), while the actual PDF squishes the thumbnails, cramps the
columns, and **jams the bilingual labels onto one line** (e.g.
`المجموع الفرعيSubtotal`). The fast preview therefore *over-promises* and the
user only discovers the real layout after rendering a PDF.

### Root cause

Both previews share the **same markup** — it is built once by
`WOI\PDF\Visual\TemplateTokens` (`{{line_items}}`, `{{totals}}`). The divergence
is entirely CSS, across three drifting layers:

| Layer | Where | Role |
|-------|-------|------|
| `PREVIEW_CSS` | `assets/visual-editor/app.js` (~line 647) | Live HTML preview — hand-maintained, stale subset |
| visual wrapper CSS | `templates/_visual/visual-document-wrapper.php` inline `<style>` | the visual-template **PDF** (mPDF) |
| canonical CSS | `templates/Standard UAE Tax Invoice/style.css` | the canonical (non-visual) PDF — renders correctly |

The visual wrapper CSS is a stripped subset of the canonical stylesheet and is
**missing** the fidelity rules that make the canonical invoice render cleanly:

- column-width allocations (`.thumbnail`, `.quantity`, `.sku`, `.price`,
  `.tax_rate`, `.total`, `.position`, `.vat-split`)
- `td.thumbnail img { width: 13mm; height: auto }`
- `.woi-lbl-primary { display: block }` (it only has the *secondary* block rule)

`PREVIEW_CSS` is an even thinner subset that drifts the opposite way: the browser
honours `display:block` on the secondary `<span>` and renders `<img>` at natural
size, so the preview looks good while the PDF does not.

The bilingual label jam specifically is because `TemplateTokens` emits the
English title as a **bare text node** followed by a block secondary span. mPDF
does not break a bare-text-then-block-span pair onto two lines. The canonical
template stacks correctly because `render_label()` wraps **both** sides in block
spans (`woi-lbl-primary` + `woi-lbl-secondary`).

This preview path has a documented history of drifting from the PDF path
(recent fixes: shop-address `<br>` escaping, web-URL thumbnails, no-store
headers). The duplicate stylesheet is the structural cause.

## Goal

1. **Fix the visual-template PDF** so it renders cleanly (proper columns, 13 mm
   thumbnails, stacked bilingual labels), porting the rules the canonical
   template already has.
2. **Make the Live HTML preview faithfully match the now-correct PDF**, and do so
   in a way that structurally prevents future drift.

Decisions taken during brainstorming:

- End state: **fix PDF + match preview** (not "honest preview of a flawed PDF").
- Sync mechanism: **single shared CSS file** (Approach A) — not REST-delivered
  CSS, not two-copies-plus-test.

## Architecture — single source of truth

A new **static** stylesheet `templates/_visual/visual-document.css` becomes the
sole definition of the visual document's appearance. It contains no PHP-dynamic
parts (unlike the canonical `style.css`, whose `@font-face` blocks interpolate
template paths), so it is a plain `.css` file.

Two consumers read it through one helper:

```
woi_pdf_visual_document_css()   // woi-pdf-functions.php
  -> path: WOI_PDF()->plugin_path().'/templates/_visual/visual-document.css'
  -> returns file_get_contents() when is_readable(), else '' (graceful)
```

- **mPDF path:** `visual-document-wrapper.php` replaces its inline CSS body with
  `<style><?php echo woi_pdf_visual_document_css(); ?></style>` (mPDF requires
  inline CSS; a `<link>` to a plugin URL is not reliably fetchable).
- **Browser path:** `VisualEditorPage::enqueue()` calls the same helper and adds
  the result to the `woiVisual` localized object as `previewCss`. `app.js`
  injects it into the preview iframe.

Because both consumers read the same bytes, the preview can no longer drift from
the PDF.

## Part 1 — Fix the PDF (shared by both previews)

### 1a. Markup — `includes/Visual/TemplateTokens.php`

In `render_line_items()` and `render_totals()`, wrap the primary (English) label
in `<span class="woi-lbl-primary">…</span>`, emitted immediately before the
existing `woi-lbl-secondary` span. This mirrors `BilingualLabelTrait::render_label()`
and is the actual fix for the label jam — mPDF stacks only when both labels are
block-level spans.

- Headers: `<th class="…"><span class="woi-lbl-primary">{title}</span><span class="woi-lbl-secondary" dir="rtl">{secondary}</span></th>`
  (the secondary span stays gated on a non-empty `secondary`).
- Totals: same treatment inside the existing `<th class="description"><span>…</span></th>`
  wrapper.

When there is no secondary value, behaviour is unchanged in effect (a single
block span renders the same as the previous bare text).

### 1b. `templates/_visual/visual-document.css`

Starts as the current wrapper CSS, plus the ported canonical fidelity rules:

- `.woi-lbl-primary { display: block }` (secondary block rule already present)
- Column widths on `.order-details`:
  - `.thumbnail, .quantity, .weight { width: 8% }`
  - `.sku, .price, .regular_price, .vat, .discount, .tax_rate, .total { width: 10% }`
  - `.vat-split { width: 12% }`
  - `.position { width: 5% }`
- `td.thumbnail img { width: 13mm !important; height: auto !important }`
  (the `!important` is required: WooCommerce thumbnail markup carries inline
  `width`/`height` attributes that otherwise win, in the browser especially —
  this is exactly why the canonical template uses `!important` here.)
- `.order-details th { overflow-wrap: normal }`
- Item-meta sizing: `.wc-item-meta { font-size: 7pt; line-height: 115% }` and the
  `dl/dt/dd` equivalents, matching the canonical template.

The existing `@page { margin: 15mm }`, table borders, totals rules, doc-title and
layout-block rules are preserved.

## Part 2 — Preview consumes the shared CSS

`assets/visual-editor/app.js`:

- Delete the hardcoded `PREVIEW_CSS` constant.
- `woiWrapForPreview()` builds the iframe `<style>` from
  `woiVisual.previewCss` **plus a clearly-labelled preview-only shim** appended
  after it (so shim rules win):
  - **Page simulation** (since `@page` does not apply inside an iframe):
    `body { max-width: 210mm; margin: 0 auto; padding: 15mm; box-sizing: border-box; background: #fff }`
    — reproduces the ~180 mm content width mPDF lays out against.
  - **Visible page break** (the shared rule is `height:0` / invisible, correct
    for paged PDF but useless in a continuous scroll view):
    `.woi-pagebreak { border-top: 1px dashed #999; margin: 4mm 0 }`
- If `woiVisual.previewCss` is empty (helper failed), fall back to a minimal
  inline string so the preview still renders something.

Net result: identical column percentages, identical 13 mm thumbnails, and
stacked bilingual labels in both the browser preview and the PDF.

## Testing & versioning

- Extend `tests/Unit/Visual/TemplateTokensTest.php`: assert that a bilingual
  header and a bilingual total each emit a `woi-lbl-primary` span paired with the
  `woi-lbl-secondary` span; assert the primary still renders when no secondary
  exists.
- Add a guard test that `visual-document.css` is readable and that the wrapper
  render (or `woi_pdf_visual_document_css()`) contains a ported marker rule
  (e.g. `width: 13mm !important`), so the inline wiring cannot silently break.
- Run PHPUnit with the ABSPATH prepend
  (`-d auto_prepend_file=tests/bootstrap.php`).
- Bump `WOI_PDF_VERSION` and the plugin header `1.4.26 → 1.4.27` so browsers
  fetch the new `app.js`/CSS rather than a cached copy.

## Known residual (accepted)

The browser falls back from `dejavusans`/`xbriyaz` to system fonts, so Arabic
glyph shapes and exact line metrics differ slightly between the Live HTML
preview and the PDF. Layout-level fidelity (label stacking, column widths,
thumbnail size, wrapping) matches. The PDF tab remains ground truth for
pagination and final glyph rendering.

## Out of scope

- Changing the canonical `Standard UAE Tax Invoice` template or other PDF
  templates.
- Pixel-identical font rendering between browser and mPDF.
- Reworking the column/totals settings model (`EditorSettings`).
