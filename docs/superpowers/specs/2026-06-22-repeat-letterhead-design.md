# Repeat letterhead on every page — design spec

Date: 2026-06-22

## Context

Multi-page invoices rendered through the **visual document** path
(`includes/Visual/TemplateTokens.php` + `templates/_visual/visual-document-wrapper.php`,
active for invoices when the visual template toggle is on) show the branded
letterhead banner — English shop name | logo | Arabic shop name — only at the top
of **page 1**. The banner is part of the body flow (`section_letterhead()`,
emitted via the `{{letterhead}}` token), so it does not repeat. Continuation
pages (page 2+) open with no letterhead.

By contrast the **footer already repeats** on every page: `running_footer()`
emits an mPDF running element `<htmlpagefooter name="woiFooter">`, and
`templates/_visual/visual-document.css` assigns it with `@page { footer: woiFooter }`.

Reference symptom: `invoice-237.pdf` — page 1 has the full bilingual letterhead;
page 2 has only the running footer.

This feature adds a **repeat-letterhead toggle**: when on, the letterhead renders
as an mPDF running page-header so it appears on every page; when off (the
default), behaviour is unchanged.

Decisions locked during brainstorming:
- Pages 2+ show the **full letterhead, identical** to page 1 (no separate compact
  variant).
- Scope is the **visual invoice only** — the only document type that uses the
  visual renderer (`OrderDocument::get_html()` gates the visual path on
  `'invoice' === get_type()`). Classic templates (Simple/Modern/Business/Standard
  UAE) are out of scope.
- The control is a **global on/off toggle**, default **off**, so existing output
  is byte-identical until a merchant opts in.

## Approach

Mirror the running-footer mechanism. The footer works via two pieces: a running
element defined in the body (`<htmlpagefooter name="woiFooter">`) and an
`@page { footer: woiFooter }` assignment (the reliable all-pages method). The
repeating letterhead is the symmetric counterpart: `<htmlpageheader name="woiHeader">`
plus `@page { header: woiHeader }`.

Rejected alternatives:
- **mPDF `<sethtmlpageheader>` tag in the body** — `running_footer()` already
  documents that this only applies from the current page *forward*, not all pages.
  `@page` assignment is the reliable method.
- **Duplicating the letterhead into the body before each page break** — page
  breaks are not known until mPDF lays out, so this cannot be done reliably.

When the toggle is on, the banner **moves** from the body into the running
header: the body `{{letterhead}}` token resolves to `''` and the running header
emits the banner, so page 1 shows it exactly once (in the page margin) and every
continuation page shows it too.

## Changes

### 1. Doc-option `repeat_letterhead`

**`woi-pdf-functions.php` — `woi_pdf_visual_doc_options()`**: add the key to
`$defaults` and `$allowed`, exactly like `borders`/`stripes`:
```php
'repeat_letterhead' => 'off',   // on | off — repeat letterhead on every page
```
```php
'repeat_letterhead' => array( 'on', 'off' ),
```
The option persists through the existing `Rest::handle_visual_doc_options`
endpoint (it saves sanitised scalars; the read-time whitelist in
`woi_pdf_visual_doc_options()` is what validates), so no REST change is needed.

### 2. Block-editor toggle

**`src/block-editor/index.js`**: add `repeat_letterhead: 'off'` to
`DEFAULT_DOC_OPTIONS`, and add a `ToggleControl` in the Settings panel beside
"Column borders" / "Striped rows":
```jsx
<ToggleControl
    label={ __( 'Repeat letterhead on every page', 'woocommerce-orders-invoice-pdf' ) }
    help={ __( 'Shows the letterhead at the top of every PDF page. Preview shows it once; the effect appears in the generated PDF.', 'woocommerce-orders-invoice-pdf' ) }
    checked={ 'on' === docOptions.repeat_letterhead }
    onChange={ ( v ) => onDocOption( 'repeat_letterhead', v ? 'on' : 'off' ) }
/>
```
Requires a webpack build (`assets/js/block-editor/index.js` is the built artifact).

### 3. Running header + body suppression — `includes/Visual/TemplateTokens.php`

Add a `running_header()` method mirroring `running_footer()`:
```php
public function running_header( $document ): string {
    $banner = $this->section_letterhead( $document, BilingualEngine::instance() );
    return '<htmlpageheader name="woiHeader">' . $banner . '</htmlpageheader>';
}
```
In `map()`, suppress the body copy when the option is on so the banner is not
rendered twice on page 1:
```php
$repeat = ( woi_pdf_visual_doc_options( 'invoice' )['repeat_letterhead'] ?? 'off' ) === 'on';
// ...
'{{letterhead}}' => $repeat ? '' : $this->section_letterhead( $document, $engine ),
```
`section_letterhead()` itself is unchanged; the running header calls it directly,
so the banner markup/classes (and existing `.woi-lh-ar` Arabic-off hiding, which
uses flat class selectors that apply inside the page header too) stay consistent.

### 4. Wrapper — `templates/_visual/visual-document-wrapper.php`

Beside the existing `running_footer` emission, echo the running header when the
option is on (it is already reading `$woi_doc_opts`):
```php
if ( isset( $document ) && is_object( $document ) ) {
    $tokens = new \WOI\PDF\Visual\TemplateTokens();
    if ( 'on' === ( $woi_doc_opts['repeat_letterhead'] ?? 'off' ) ) {
        echo $tokens->running_header( $document );
    }
    echo $tokens->running_footer( $document );
}
```

### 5. Page geometry — `woi_pdf_visual_options_css()` (PHP)

When the option is on, reserve top-margin space for the banner on every page and
assign the header. The base CSS is `@page { margin: 15mm; margin-bottom: 18mm;
footer: woiFooter; }`; mPDF merges additional `@page` blocks:
```php
if ( 'on' === ( $opts['repeat_letterhead'] ?? 'off' ) ) {
    $css[] = '@page { header: woiHeader; margin-top: 34mm; }';
}
```
`34mm` is a starting value (logo `max-height` is 18mm plus the name lines and a
gap); tune via the render harness so the body clears the banner without an
excessive gap.

The JS canvas mirror (`src/block-editor/optionsCss.js`) is **not** changed: the
block-editor canvas is a single-flow HTML preview that cannot simulate mPDF
running headers, so the toggle has no canvas effect (covered by the toggle's help
text).

### 6. Version bump

Bump `WOI_PDF_VERSION` (and the plugin header `Version`) per the asset
cache-bust convention. Check the current released version before bumping (shared
cache-bust key).

## Edge cases

- **Toggle off (default)** → no code path changes; output is byte-identical to
  today (body letterhead on page 1 only, no `woiHeader`, base `@page` margins).
- **Toggle on, no logo / no secondary shop** → `section_letterhead()` still
  renders the available name lines; the running header is never blank-only.
- **Arabic off** → the existing flat-selector rule `.woi-lh-ar{display:none}`
  (emitted by `woi_pdf_visual_options_css` when `arabic=off`) applies inside the
  running header too, so the header stays consistent with the body.
- **Block-editor live canvas** → unchanged appearance (letterhead shown once at
  top); suppression lives only in the PHP PDF path, so the canvas is unaffected.
- **GrapesJS templates with a hand-expanded letterhead** (literal
  `<table class="woi-letterhead">` markup instead of the `{{letterhead}}` token)
  → the body copy is not auto-suppressed, so the banner would appear both in the
  running header and the body. Documented limitation; the block-editor
  (production) path uses the token and is fully covered.

## Testing

**Unit (Brain Monkey, `tests/Unit/Visual/`)**:
- `woi_pdf_visual_doc_options()` returns `repeat_letterhead => 'off'` by default,
  accepts `on`, and rejects junk values (whitelist).
- `TemplateTokens::map()` returns `''` for `{{letterhead}}` when the option is on,
  and the full `<table class="woi-letterhead">` markup when off.
- `TemplateTokens::running_header()` wraps the letterhead in
  `<htmlpageheader name="woiHeader">`.
- `woi_pdf_visual_options_css()` emits the `@page { header: woiHeader; ... }` rule
  only when the option is on.

**Manual (render harness, no deploy)**:
- `tools/render-visual-sample.php` with enough line items to force a 2-page
  document, then `tools/rasterize.py` on both pages. Confirm:
  - letterhead appears at the top of **page 1 and page 2**,
  - it is **not duplicated** on page 1 (no banner in both margin and body),
  - the body content is not clipped under the banner (margin-top sufficient),
  - the running footer still renders correctly on both pages.
- Re-run with the toggle off and confirm page 1 is unchanged from current output.

## Out of scope

- Classic templates (Simple/Modern/Business/Standard UAE) and non-invoice document
  types.
- A separate compact/condensed header for continuation pages.
- Auto-suppressing a hand-expanded (non-token) letterhead in GrapesJS templates.
- A per-document-type override (the toggle is global).
