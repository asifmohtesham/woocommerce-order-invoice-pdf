# Contact strip per-element flexibility (Slice 1)

**Date:** 2026-06-22
**Status:** Approved design — ready for implementation plan
**Scope:** Contact strip section of the Block Invoice Template. Letterhead is a
deliberate follow-up slice using the same pattern.

## Problem

The Block Invoice Template's **Contact strip** (the `TRN … Tel … Email` row) and
**Letterhead** are token-based section blocks whose internal HTML is hardcoded
server-side in `includes/Visual/TemplateTokens.php` and styled by fixed rules in
`templates/_visual/visual-document.css`. Today the only controls are
whole-section (accent colour, header left/centre). There is no way to align,
style, show/hide, or reorder an individual element.

Two user-reported issues:

1. **Centering bug.** The `Tel` value is not truly centered. The contact strip
   is a 3-cell table with *auto* column widths, so cells size to their content;
   the middle cell drifts off the page center because `TRN` and `Email` differ
   in length.
2. **No per-element flexibility.** The user wants to align and style each nested
   element individually, and reorder them via WYSIWYG drag-and-drop.

This slice solves both for the **Contact strip only**, establishing the pattern
to reuse for the Letterhead next.

## Chosen approach

**Config-driven section (Approach A).** The Contact strip stays a single block
but gains a structured, ordered `items` attribute. The editor renders the strip
as draggable chips with per-element controls; the per-element configuration is
serialized into the saved wrapper markup and read back by a config-aware PHP
renderer.

Rejected alternative — **native InnerBlocks with custom child blocks** (Approach
B): would require the PDF render pipeline (today a token `strtr`) to execute
block render-callbacks and emit mPDF-safe HTML per child. Too much risk in the
render path for the first slice; revisit only if per-element editing needs to
generalise beyond known fields.

### Why this fits the codebase

- The individual values already exist as leaf tokens: `{{trn}}`,
  `{{shop_phone}}`, `{{shop_email}}` (map in `TemplateTokens::map()`), and the
  editor's preview store already resolves them — so chips can show live values
  with no new data plumbing.
- The values are **dynamic** (order/shop data at render time). Keeping rendering
  server-side preserves a single source of truth and avoids WP block-binding
  complexity.

## Components

### 1. Block attribute schema — `woi/contact-strip`

Add an ordered `items` array attribute. Each item:

```js
{
  field:    'trn' | 'tel' | 'email', // which leaf token this element shows
  visible:  boolean,                 // show / hide
  align:    'left' | 'center' | 'right' | '', // '' = positional default
  bold:     boolean,
  fontSize: number | '',             // pt; '' = inherit
  color:    string | '',             // hex; '' = inherit
}
```

Default value (reproduces today's layout exactly):

```js
[
  { field: 'trn',   visible: true, align: 'left',   bold: false, fontSize: '', color: '' },
  { field: 'tel',   visible: true, align: 'center', bold: false, fontSize: '', color: '' },
  { field: 'email', visible: true, align: 'right',  bold: false, fontSize: '', color: '' },
]
```

Existing `APPEARANCE_ATTRS` (whole-section wrapper align/spacing) are retained
for backward compatibility. Labels (`TRN` / `Tel` / `Email`) remain fixed per
field — editing label text is out of scope for this slice.

### 2. Editor UI — `ContactStripEdit`

`edit()` in `src/block-editor/blocks/token.js` branches on
`name === 'woi/contact-strip'` to a dedicated `ContactStripEdit` component; the
generic token `edit` stays for all other section/leaf tokens.

`ContactStripEdit`:

- Renders the strip as a horizontal row of **chips**, one per item in `items`
  order. Each chip shows `LABEL value`, where `value` is the live token value
  from the preview store (`tokenValue('{{trn}}', tokens)`, etc.).
- **Drag-to-reorder** via native HTML5 drag events (no new dependency); dropping
  rewrites the `items` order.
- Hidden items are shown dimmed with an "off" indicator **in the editor only**.
- Selecting a chip sets a local selected index; `InspectorControls` (sidebar)
  shows that item's controls: **Visible** toggle, **Alignment** (L/C/R),
  **Bold**, **Font size**, **Color**, and a **Reset to default** action.
- Chips render with the same inline styles the PDF will use (align, bold, size,
  colour) so the editor is true WYSIWYG. The container mimics the strip's
  top/bottom border using the existing visual classes.

### 3. `save()`

Emits the wrapper with the config serialized as a data attribute plus the token:

```html
<div class="…" data-woi-section="contact" data-woi-contact-config='<json>'>{{contact_strip}}</div>
```

The config is deterministically derived from `items` and HTML-attribute-escaped.
Encoding it in the rendered HTML (not only the block comment) means it survives
regardless of whether the render path processes block delimiters.

### 4. PHP render — config-aware merge

`TemplateTokens::merge()` gains a targeted `preg_replace_callback` matching the
contact wrapper + token, e.g.:

```
/<div\b[^>]*data-woi-section="contact"[^>]*>\s*\{\{contact_strip\}\}\s*<\/div>/
```

The callback extracts `data-woi-contact-config`, JSON-decodes it, and calls
`section_contact_strip( $document, $config )`. Remaining tokens are handled by
the existing `strtr` pass. If no wrapper matches (legacy saves, GrapesJS starter
with a bare `{{contact_strip}}`), the default `strtr` path renders the current
layout unchanged.

`section_contact_strip( $document, ?array $config = null )`:

- `null`/empty/malformed config → current default layout (unchanged output).
- With config: iterate items in order, skip `visible === false`, skip unknown
  fields. For each visible item build a `<td>` with:
  - `width: (100 / visibleCount)%` (the centering fix — deterministic equal
    columns).
  - `text-align` from `align` (fallback to positional default if `''`).
  - value `<span>` inline `font-weight` / `font-size` / `color` from the item.
- Inline styles are used throughout because mPDF ignores `body[data-*]`
  descendant selectors (per render notes).

### 5. Centering fix

Independent of any config: cells get explicit equal widths
`width = 100 / visibleCount %`. Default 3 items → 33.33% each, so the `Tel` cell
is the true center third and its centered text sits at the page center.

### 6. CSS — `templates/_visual/visual-document.css`

- Keep `.woi-contact` border and `.woi-contact-k` / `.woi-contact-v` typography.
- Per-cell `text-align` and `width`, and per-value `font-weight`/`font-size`/
  `color`, come from PHP-emitted **inline styles**; the positional
  `.woi-contact-mid` / `.woi-contact-end` rules become redundant for the
  config path (retained as the default-path fallback).

### 7. Build & cache-bust

- Rebuild the webpack bundle (`assets/js/block-editor/index.js`); mind the
  `clean: false` gotcha.
- Bump `WOI_PDF_VERSION` in **both** strings (header + `public string $version`);
  currently `1.5.64`.

## Data flow

`edit()` ⇄ `items` attribute → `save()` serializes to wrapper `data-woi-contact-config`
+ `{{contact_strip}}` token → post_content saved → PDF render: `merge()`
callback decodes config → `section_contact_strip( $document, $config )` builds
the table (order / visibility / align / style / equal widths) → mPDF renders.

## Edge cases & error handling

- Malformed / empty config → default layout. Unknown field → skip item.
- Zero visible items → omit the strip entirely (no stray border row).
- Single visible item → `width: 100%`, its own align.
- Legacy templates without `data-woi-section` → exact current output. No data
  migration required (absence of config == today's behaviour).

## Testing

- **PHPUnit** (`tests/Unit/Visual/TemplateTokensTest.php`): cases for reorder,
  hide, per-item align/style, equal-width centering, zero-visible omission, and
  default-`null` unchanged output. Mind the harness gotchas: `-d
  auto_prepend_file=tests/bootstrap.php` (ABSPATH) and the unloaded
  `woi-pdf-functions.php` helpers.
- **Local mPDF harness** (`tools/render-visual-sample.php` + `tools/rasterize.py`):
  visually confirm the centering fix and a reordered/hidden config without a
  deploy. Add a way to feed a sample config to the harness.
- **Editor JS**: WYSIWYG behaviour verified manually; live acceptance on the
  Chrome debug harness (`b2b.milanoleather.ae`) deferred.

## Out of scope (this slice)

- Letterhead per-element flexibility (follow-up slice, same pattern).
- Editing label text per field.
- Adding Arabic/RTL to the contact strip.
- Native InnerBlocks (Approach B).

## Follow-up

Once the Contact strip pattern is proven, apply it to the Letterhead: elements
`shop_name`, `shop_name_ar`, `shop_address`, `shop_address_ar`, `logo`, with the
same `items` config model and a `data-woi-section="letterhead"` wrapper.
