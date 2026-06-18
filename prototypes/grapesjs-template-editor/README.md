# GrapesJS invoice-template editor — prototype

A self-contained spike showing a **GUI, drag-and-drop way to design the invoice
layout** instead of hand-editing PHP templates. It is a *prototype*: nothing here
is wired into the plugin runtime. It lives under `prototypes/` and ships nothing
to production.

## What it demonstrates

- A **block palette** of invoice tokens (logo, English shop block, Arabic shop
  block, TRN/phone/email line, document title, customer address, invoice meta,
  line items, totals) you drag onto a canvas.
- A **starter layout** that mirrors the current Standard UAE Tax Invoice, so you
  edit something familiar rather than a blank page.
- **Export template** → the HTML + CSS you designed, with `{{tokens}}` intact.
  This is what would be saved and later rendered through Dompdf.
- **Preview with sample data** → merges sample WooCommerce-style values into the
  tokens and renders the result, proving the design → data → output round-trip.

## Run it

Just open `index.html` in a browser (it loads GrapesJS from a pinned, SRI-checked
CDN build — needs internet on first load). No build step, no server.

## How this would integrate with the plugin

The key insight: GrapesJS produces **plain HTML/CSS**, which is exactly what the
existing renderer already consumes — so the engine does not change.

1. **Author** — the editor saves the exported HTML/CSS as a stored template
   (e.g. a `woi_pdf_visual_template` option, per document type).
2. **Tokens** — replace `{{invoice_number}}`, `{{shop_name_ar}}`, `{{line_items}}`,
   etc. server-side from the `OrderDocument`. A thin token map is all that is
   needed; the document already exposes every value as a method
   (`get_number()`, `secondary_shop_name()`, `woi_pdf_templates_get_table_body()`…).
3. **Render** — feed the merged HTML to `PDFMaker`, unchanged. Arabic is still
   shaped by `ArabicShaper::shape_html()` before Dompdf (see
   `includes/Bilingual/ArabicShaper.php`), so RTL keeps working regardless of
   how the layout was arranged in the GUI.

```
GrapesJS (design)  ->  stored HTML template with {{tokens}}
                          |
OrderDocument data  -->  token merge (new, ~1 small class)
                          |
                       PDFMaker -> ArabicShaper -> Dompdf -> PDF
```

### What a GUI does NOT remove

A visual builder converts "move this box / put X here" requests into clicks, but
it does **not** replace the bespoke i18n work:

- Dompdf has no Arabic shaper or bidi — `ArabicShaper` still runs on the merged
  HTML.
- Bilingual mirroring (`shop_block_secondary`, the English|logo|Arabic header)
  stays custom; the builder just lets you place those blocks.

## Suggested next steps if we pursue this

1. Define the canonical **token list** + a `TemplateTokens` mapper class
   (token → `OrderDocument` value).
2. Add a **token-merge** step (string replace, with `{{line_items}}` / `{{totals}}`
   expanding to the existing table/total renderers).
3. Store/load the template in settings; add a "Visual template" toggle that makes
   `DocumentRenderer` use the stored HTML instead of the PHP template file.
4. Harden the block set (validation, required tokens, page-break controls).
