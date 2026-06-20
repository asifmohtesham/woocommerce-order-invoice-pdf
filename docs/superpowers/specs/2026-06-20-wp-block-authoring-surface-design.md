# WP Block (Gutenberg) Authoring Surface for Invoice Templates — Design

**Date:** 2026-06-20
**Status:** Approved (design); ready for implementation plan
**Related:** `2026-06-18-grapesjs-visual-template-design.md`, `2026-06-19-editor-layout-modes-design.md`, `2026-06-19-pdf-preview-a4-pdfjs-design.md`

## Summary

Add a second visual authoring surface for the invoice PDF template: a real
`@wordpress/block-editor` canvas ("Block Editor"), coexisting with the existing
GrapesJS editor. Both editors are *producers* of the same kind of artifact —
plain HTML containing `{{tokens}}` — which the existing render pipeline already
consumes. An **active-source flag** decides which producer's HTML feeds the PDF.

The GrapesJS editor, the `TemplateTokens` system, the `_visual` wrapper, and the
mPDF engine are **not changed**. The only render-path edit makes the resolver
pick the active source's HTML instead of hard-reading the GrapesJS option.

## Goals

- A native WordPress block-editing experience for designing the invoice template.
- Coexist with GrapesJS; the user picks which editor's design feeds the PDF.
- Reuse the existing token system, preview endpoints, mPDF pipeline, and bilingual
  engineering unchanged.
- Full shell parity with the GrapesJS editor: Live HTML preview, A4 PDF.js PDF
  tab, order-search picker, layout modes (full/stack/overlay).

## Non-goals

- Replacing or retiring GrapesJS (explicitly a coexistence design).
- Changing the render engine (stays mPDF — see `mpdf-engine-swap` memory).
- Lossy HTML↔blocks conversion between the two editors. Each editor owns its own
  source of truth; switching between them does **not** import the other's design.
- Document types other than `invoice` in this feature (matches GrapesJS scope).

## Architecture

The render pipeline is already authoring-surface-agnostic:

```
stored HTML + {{tokens}}  ──►  TemplateTokens::merge  ──►  _visual wrapper  ──►  mPDF
```

GrapesJS is one producer of that stored HTML. This feature adds a second:

```
                      ┌─ GrapesJS editor ──► woi_pdf_visual_template_invoice (HTML)
 active source ──────►│
 (grapesjs|blocks)    └─ Block editor ─────► woi_pdf_visual_blocks_invoice    (block markup)
                                              └─ render ─► woi_pdf_visual_blocks_html_invoice (HTML)
                                  │
   OrderDocument::get_html() ─► resolve active source ─► TemplateTokens::merge ─► mPDF
```

### Render-path integration point

In `includes/Documents/OrderDocument.php` (~line 1812), the invoice branch
currently does:

```php
$store  = new \WOI\PDF\Visual\VisualTemplateStore();
$stored = $store->get( $this->get_type() );
$toggle = (bool) $this->get_setting( 'enable_visual_template_invoice' );
```

This becomes a call that resolves the **active source's** rendered HTML:

```php
$stored = $store->get_active( $this->get_type() ); // resolves active-source flag
```

`visual_template_active( $doc_type, $toggle, $stored )` is unchanged — it still
gates on the toggle plus a non-empty stored HTML. The `enable_visual_template_invoice`
toggle continues to gate the *whole* visual path (both editors); the
active-source flag only chooses *which* HTML is used when the path is on.

## Storage model

Three new options (GrapesJS's `woi_pdf_visual_template_invoice` untouched):

| Option | Contents | Purpose |
| --- | --- | --- |
| `woi_pdf_visual_blocks_invoice` | block markup (`<!-- wp:woi/… -->`) | Block editor round-trip source of truth |
| `woi_pdf_visual_blocks_html_invoice` | rendered, kses-cleaned HTML + `{{tokens}}` | what the render path consumes for the blocks source |
| `woi_pdf_visual_active_source` | `'grapesjs'` (default) \| `'blocks'` | which producer feeds the PDF |

All stored unautoloaded (`update_option( …, false )`), matching the GrapesJS option.

### Resolver

A `VisualTemplateStore::get_active( string $doc_type ): string` method (or a small
`VisualSourceResolver` collaborator) reads `woi_pdf_visual_active_source` and
returns the corresponding rendered HTML:

- `grapesjs` → `get( $doc_type )` (existing GrapesJS option).
- `blocks` → the `woi_pdf_visual_blocks_html_invoice` option.

Default `grapesjs` preserves today's behaviour exactly for existing installs.

## Block set — custom invoice blocks (`woi/` namespace)

A purpose-built set mirroring the GrapesJS token catalog. Every block's `save()`
emits **table / inline-style HTML** (no flexbox/grid) so the editor canvas and
the mPDF output stay in agreement.

**Token blocks** (static; `save()` emits the literal `{{token}}` wrapped in
appropriate markup): Shop Name, Shop Name (AR), Logo, TRN, Phone, Email,
Document Title, Document Title (AR), Billing Address, Invoice #, Date, Order #,
Payment Method, **Line Items**, **Totals**.

**Layout blocks**: Row/Columns (renders as a `<table>`), Header Row
(EN | logo | AR, matching `uae-bilingual-header-layout`), Spacer, Divider,
Heading, **Page Break**, and an editable rich-text/plain-text block.

Each block registers an inserter icon under an "Invoice" block category, so the
native inserter and the `/`-slash menu provide the same discoverability as the
GrapesJS insert menu.

### mPDF-safety constraints (per the `preview-pdf-shared-css` lessons)

- Bilingual label pairs need a real `<br>` (mPDF ignores `display:block` on inline
  spans inside `<th>`). Blocks that stack EN/AR labels must emit `<br>`.
- `.totals-table` keeps its page-break auto-guard from the wrapper.
- Block `save()` output must pass the existing `VisualTemplateStore::allowed_html()`
  kses allowlist unchanged after rendering.

## Block markup → HTML rendering — server-side `do_blocks()`

On Save, the editor POSTs **block markup** to a new REST route
(`POST woi-pdf/v1/visual-blocks`). The server:

1. Runs the markup through `do_blocks()` — static blocks emit their `save()` HTML;
   the `<!-- wp:… -->` comment wrappers are stripped.
2. Sanitizes the result with `wp_kses( $html, VisualTemplateStore::allowed_html() )`.
3. Stores the **markup** in `woi_pdf_visual_blocks_invoice` and the **rendered HTML**
   in `woi_pdf_visual_blocks_html_invoice`.

This keeps one trusted server-side sanitization seam and is the WP-canonical path.

*Rejected alternative:* client-side `wp.blocks.serialize` + strip — duplicates
render logic in JS and bypasses the server kses seam.

The block PHP definitions must be registered server-side (`register_block_type`)
so `do_blocks()` can render them outside the editor request. Static blocks need
no `render_callback`; their `save()` HTML lives inline in the markup.

## Editor shell & preview parity

A new `BlockEditorPage` parallels `VisualEditorPage`:

- Registers a hidden WooCommerce submenu (CSS-hidden link, same pattern as
  `VisualEditorPage::hide_standalone_menu_item_css`) plus a **"Block Editor" tab**
  in the PDF Invoices settings nav (`woi_pdf_settings_tabs` filter).
- Suppresses third-party admin notices on its screen (reuse the screen-gated
  pattern).
- Enqueues the built block-editor bundle using its generated `index.asset.php`
  dependency array, plus the WordPress block-editor stylesheets.
- Localizes the same `woiVisual`-shaped payload (REST URLs, nonces, sample data,
  preview CSS, order-search action, PDF worker URL).

**Preview reuse (full parity).** The preview endpoints are surface-agnostic:

- `GET woi-pdf/v1/visual-preview-data` (token values for a chosen order).
- `woi_pdf_preview` AJAX (real mPDF Blob, security nonce `woi_pdf_preview`).

The Block editor page reuses the same preview-pane markup, the A4 PDF.js render
code path, the Live-HTML tab, the order-search picker, and the layout-mode CSS.
The wiring difference: it subscribes to block-editor changes via
`@wordpress/data` `subscribe()` (debounced) instead of GrapesJS `editor.on('update')`.

To get preview HTML it serializes the current blocks and renders them — for the
**PDF tab** this means save-then-`woi_pdf_preview` (same as GrapesJS); for the
**Live HTML** tab it can serialize client-side (or call the render REST route)
then token-substitute via `visual-preview-data`, matching the GrapesJS behaviour.

**Shared preview CSS/markup is factored once**, not duplicated — consistent with
the `woi_pdf_visual_document_css()` lesson (do not reintroduce a second copy).

## Active-source UI

A switcher ("Template source: GrapesJS | Block editor") appears in:

- Both editor toolbars (so you can flip the active source where you design).
- The Advanced settings tab, beside `enable_visual_template_invoice`.

It writes `woi_pdf_visual_active_source`. Switching only changes which stored HTML
the PDF uses; both designs are preserved independently. When the visual path is
on but the active source has no stored HTML, the render path falls back to the
legacy PHP template (existing `visual_template_active` behaviour — empty HTML
fails the gate).

## Build & asset pipeline

- New entry `src/block-editor/index.js` plus block definition modules under
  `src/block-editor/blocks/`.
- Update `package.json` build to compile both `src/home` and `src/block-editor`
  (multiple `wp-scripts` entry points or a directory build), emitting to
  `assets/js/block-editor/` with a generated `index.asset.php`.
- Bump `WOI_PDF_VERSION` on every JS/CSS change (the `asset-version-cache-bust`
  lesson) so browsers don't serve stale bundles.

## Slice breakdown (one spec, internally sliced)

1. **Pipeline spine.** New storage options + resolver + `OrderDocument` render-branch
   edit + active-source setting + a minimal `BlockEditorPage` with a bare block
   canvas (a few token + text blocks) saving via the render REST route. Prove a
   blocks-source design produces a correct PDF end-to-end.
2. **Full custom block set.** All token + layout blocks with mPDF-safe `save()`
   output and server-side registration.
3. **Preview parity.** Live HTML tab + A4 PDF.js tab + order-search picker +
   layout modes, reusing the existing endpoints and shared CSS.
4. **Polish.** Slash/inserter parity, required-token Save warnings, notices
   suppression, and live browser/PDF acceptance.

## Testing

PHP unit tests (following `tests/Unit/Visual/*` and the PHPUnit ABSPATH bootstrap
— run with `php -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit`,
never `vendor/bin/phpunit` on Windows):

- Resolver: active flag → correct rendered HTML; default `grapesjs` preserves
  current behaviour.
- Render REST route: block markup → `do_blocks` → kses → both options stored; the
  rendered HTML passes the allowlist and preserves `{{tokens}}`.
- `visual_template_active` parity across both sources.
- Render-branch: blocks source active → merged HTML reaches the wrapper.

JS has no harness in this repo → gates are `node --check`, `php -l`, selector
greps, and live browser/PDF acceptance (see `live-testing-harness`,
`rendering-pdfs-for-verification` memories).

## Risks & mitigations

- **`do_blocks` needs server-registered blocks.** Register every custom block
  server-side; static blocks need no render_callback. Add a unit test that renders
  representative markup through `do_blocks`.
- **Core block-editor CSS bloat / canvas styling.** Use a constrained block set
  and our own canvas styles so the editor preview tracks mPDF output.
- **Two editors drifting in shared shell code.** Factor shared preview
  markup/CSS/JS once; do not copy-paste the GrapesJS pane.
- **Scope (full parity is large).** Mitigated by the 4-slice breakdown; slice 1
  is independently shippable behind the existing toggle (default source stays
  `grapesjs`, so nothing changes for current users until they opt in).
