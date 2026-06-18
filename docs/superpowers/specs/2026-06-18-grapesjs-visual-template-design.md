# GrapesJS visual invoice-template editor — vertical slice (design)

**Date:** 2026-06-18
**Status:** Approved for planning
**Scope:** First end-to-end slice of the GrapesJS integration — **invoice document type only**.

## Goal

Let users design the invoice layout in a GUI (GrapesJS) instead of hand-editing PHP
templates, and render that design to a real PDF through the existing mPDF engine.
This slice proves the full round-trip — **design → store → token-merge → mPDF → PDF**
— with the bilingual/Arabic engineering intact. Hardening, additional document
types, and block-set expansion are deferred to later slices.

This is a self-contained increment. It ships nothing on by default and leaves every
existing PHP template path untouched.

## Background / corrections to the prototype

A working prototype lives on branch `prototype/grapesjs-template-editor` at
`prototypes/grapesjs-template-editor/` (commit `1d520ab`): a standalone `index.html`
GrapesJS editor with invoice-token blocks + sample-data merge preview, plus a
`README.md` integration sketch.

The prototype README is **out of date on the engine**: it describes Dompdf +
`ArabicShaper::shape_html()`. The plugin no longer uses either — the engine is
**mPDF** (`includes/Makers/MpdfMaker.php`), which shapes Arabic natively;
`ArabicShaper` has been deleted. This spec targets mPDF. Dompdf shaping is not part
of the path.

## Decisions (locked during brainstorming)

| Decision | Choice |
|---|---|
| First deliverable | End-to-end vertical slice, **invoice only** |
| Override model | **Global override per document type** — toggle ON makes the invoice render from the stored visual HTML regardless of which PHP template folder is active |
| Editor surface | **New dedicated admin page**, full-bleed |
| GrapesJS assets | **Vendored** into the plugin (no CDN, no build step — copy the pinned dist) |
| Preview | **Static sample data** in-editor + a **Preview real PDF** action that renders through the existing mPDF preview overlay |
| mPDF wrapping | **Dedicated visual wrapper** (hermetic, carries the font + `@page` setup) |
| Default state | Toggle **OFF** → fully backward compatible |

## Architecture & data flow

```
ADMIN (design time)
  New "Visual Template" admin page
     └─ GrapesJS editor (vendored assets, seeded with a starter invoice layout)
          ├─ in-browser preview merges STATIC sample data into {{tokens}}
          ├─ [Save]  → REST → option  woi_pdf_visual_template_invoice  (HTML + CSS)
          └─ [Preview real PDF] → Save, then open the existing mPDF preview overlay

RENDER (generate time)
  OrderDocument builds invoice HTML
     └─ IF visual toggle ON for invoice:
            stored HTML  →  TemplateTokens::merge( $document )
                              ├─ scalar tokens → esc_html( $document->getter() )
                              └─ block tokens  → raw HTML (line_items, totals, logo)
                         →  visual-document-wrapper (fonts + @page)
                         →  MpdfMaker → PDF
        ELSE: existing render_template( invoice.php ) path, unchanged
```

## Token contract

`TemplateTokens` is the single source of truth mapping `{{token}}` → a value resolved
from an `OrderDocument`. Two classes of token, with different escaping:

| Token | Source | Escaping |
|---|---|---|
| `{{logo}}` | logo `<img>` html | raw (trusted) |
| `{{shop_name}}` | `shop_name()` | `esc_html` |
| `{{shop_address}}` | `shop_address()` | `esc_html` |
| `{{shop_name_ar}}` | `secondary_shop_name()` | `esc_html` |
| `{{shop_address_ar}}` | `secondary_shop_address()` | `esc_html` |
| `{{document_title}}` | title getter | `esc_html` |
| `{{document_title_ar}}` | secondary title getter | `esc_html` |
| `{{trn}}` | settings getter | `esc_html` |
| `{{shop_phone}}` | settings getter | `esc_html` |
| `{{shop_email}}` | settings getter | `esc_html` |
| `{{invoice_number}}` | `get_number()` | `esc_html` |
| `{{invoice_date}}` | date getter | `esc_html` |
| `{{order_number}}` | order-number getter | `esc_html` |
| `{{payment_method}}` | payment-method getter | `esc_html` |
| `{{billing_address}}` | formatted billing address | raw (WC-escaped html with `<br>`) |
| `{{line_items}}` | `woi_pdf_templates_get_table_headers()` + `woi_pdf_templates_get_table_body()` | raw (trusted renderer) |
| `{{totals}}` | `woi_pdf_templates_get_totals()` | raw (trusted renderer) |

**Merge** = an ordered `str_replace` over a resolved `token → string` map. Block tokens
resolve by calling the existing renderers, so line-items / totals / Arabic shaping are
byte-identical to today's output. Any unknown `{{…}}` left in the template is stripped
via regex before render so no stray braces reach the PDF.

## Components & file layout

Follows existing conventions (`includes/` namespaced classes under `WOI\PDF`,
`assets/` for JS/CSS, REST through `includes/Rest.php`).

### New files

- `includes/Visual/TemplateTokens.php` — the mapper/merge engine.
  `merge( string $html, OrderDocument $doc ): string`. Unit-testable with a stubbed
  document. Holds the canonical token list + escaping policy above.
- `includes/Visual/VisualTemplateStore.php` — persistence.
  `get( string $doc_type ): string` / `save( string $doc_type, string $html ): void`
  over option `woi_pdf_visual_template_invoice` (stored unautoloaded). `save()`
  sanitises via `wp_kses` with a table/SVG-safe allowlist that **preserves `{{tokens}}`**.
- `includes/Visual/VisualEditorPage.php` — registers the dedicated admin submenu page,
  enqueues vendored GrapesJS + the editor JS, prints the mount node, the starter
  layout, and the sample-data payload.
- `assets/visual-editor/app.js` — boots GrapesJS, registers the token blocks, wires
  Save and Preview-real-PDF, runs the in-browser sample-data merge.
- `assets/visual-editor/grapesjs/grapes.min.js` + `grapes.min.css` — **vendored** dist
  (pinned version; copied, no build step).
- `assets/visual-editor/starter-invoice.html` — seed layout mirroring the current
  invoice so the editor opens on something familiar, not a blank page.
- `templates/_visual/visual-document-wrapper.php` — dedicated mPDF wrapper: injects the
  font stack (XB Riyaz / Lateef per the mPDF swap) + `@page` margins, then drops in the
  merged body + the GrapesJS `<style>`. Hermetic — independent of the active PHP folder.

### Modified files

- `includes/Documents/OrderDocument.php` — in the invoice build path, branch: toggle ON
  → build from `TemplateTokens::merge( VisualTemplateStore::get('invoice'), $this )`
  wrapped by the visual wrapper; else the existing path, unchanged.
- `includes/Rest.php` — one authed route (`manage_woocommerce` capability + nonce):
  `POST …/visual-template` → `VisualTemplateStore::save`.
- Settings — a `Visual template (invoice)` toggle in the existing invoice/documents
  settings group.
- `WOI_PDF_VERSION` bump — so the new JS/CSS is not served stale by browsers.

The `includes/Visual/` namespace keeps the feature isolated; each class is
single-purpose (merge / persistence / admin page).

## Error handling & edge cases

- **Toggle ON, no saved template** → fall back to the existing `invoice.php` path and
  log a notice. Never emit a blank PDF.
- **Save sanitisation** → `wp_kses` allowlist covering the tags GrapesJS emits
  (`table/thead/tbody/tr/td/th`, `div/span/p/img/h1-6/strong/em/br`, `style` element +
  inline `style` attrs) while **preserving the `{{token}}` brace syntax** (a unit test
  guards the round-trip). Strips `script` and event-handler attributes.
- **Unknown / leftover tokens** → stripped via regex before render; no literal `{{foo}}`
  leaks into a customer PDF.
- **Block-token failure** → each block resolver call is wrapped so one bad token
  degrades to empty rather than fataling the whole invoice.
- **mPDF CSS limits** → GrapesJS can emit CSS mPDF ignores (flex/grid). Not policed in
  this slice; the **Preview real PDF** loop is how the user catches it. The editor page
  carries help text: "design with table/block layout for mPDF."
- **Capability + nonce** enforced on the REST save.
- **Logo / Arabic** unchanged — same getters and renderers as today, so no regression
  in the bilingual path.

## Testing

- **Unit (PHPUnit — run with `-d auto_prepend_file=tests/bootstrap.php`):**
  - `TemplateTokens` merges every scalar token with correct escaping; block tokens call
    the right renderers (stubbed); leftover tokens stripped; `{{tokens}}` survive the
    kses round-trip.
  - `VisualTemplateStore` get/save round-trip + empty-option fallback signal.
- **Integration:** toggle ON with a seeded template renders a non-empty PDF through
  `MpdfMaker`; toggle OFF renders the legacy path unchanged.
- **Manual / visual:** design in GrapesJS → Save → Preview real PDF → rasterise with
  PyMuPDF and eyeball Arabic shaping + line items + totals.

## Out of scope (later slices)

- Document types other than invoice.
- Per-template-folder or "virtual template folder" override models.
- Real-order data fetch into the in-editor preview.
- Block-set hardening: required-token validation, page-break controls, richer palette.
- The deferred title-spacing tweak (English↔Arabic heading gap) — intended to be solved
  via GrapesJS once authoring lands, not as a one-off CSS fix.
- Retiring `FontSynchronizer` / unused Noto `.ttf` files / the vendored Dompdf dep.
