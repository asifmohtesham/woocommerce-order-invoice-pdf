# Letterhead mode — design spec

Date: 2026-06-16

## Context

The PDF documents render a two-cell header row: the shop logo on the left and a
"Shop details" text block (shop name, address, phone, email) on the right
(e.g. `templates/Simple/invoice.php:5-34`). Some merchants already have a
pre-designed letterhead — a full-width banner with their branding, address, and
contact details baked in — and want to drop that into the PDF instead of the
plugin's separate logo + typed shop details.

This feature adds a **letterhead mode**: a global toggle that replaces the entire
header row (logo cell *and* shop-details cell) with a single full-width letterhead
image, across every document type and all four template designs.

Decisions locked during brainstorming:
- The letterhead replaces the **entire header row**, rendered as one full-width banner.
- The letterhead uses a **new, dedicated upload field** (separate from Shop header/logo), with its own optional max-height.
- The toggle is **global** — it applies to every document type (invoice, credit note, packing slip, proforma, receipt, delivery note) across all four designs (Simple, Modern, Business, Simple Premium), mirroring how Shop header/logo already works.

## Approach

The templates already branch on `has_header_logo()`, so letterhead mode follows
the same in-template conditional pattern. A hook-based swap was rejected: the
header is rendered inline in each template with no clean seam to intercept.
Fully centralizing the header into one shared method was also rejected because the
four designs have *different* normal-header markup (Simple vs. Modern/Business's
`header-stretcher` wrapper), so only the letterhead branch can be shared.

Chosen approach: add letterhead methods on the document object, then wrap each
template's existing header table in an `if letterhead / else normal-header` branch.
The letterhead branch is identical everywhere; the per-design normal header is
untouched.

## Changes

### 1. Settings

**`includes/Settings/SettingsGeneral.php`** — insert a new section immediately after
the `header_logo` field (before the `general_shop_details` section):

- A `letterhead` section header.
- `letterhead_mode` — `checkbox` callback. Label: "Letterhead mode". Description:
  "Replace the shop logo and shop details with a single full-width letterhead image."
- `letterhead_logo` — `media_upload` callback with `height_id => letterhead_logo_height`.
  Description: "Upload your letterhead. Replaces the header in the PDF." Gated with
  `'show_if' => array( 'field' => 'letterhead_mode', 'value' => 1 )` so the upload
  field only appears when the toggle is on (same convention as
  `SettingsGeneral.php:383`).

**`includes/Settings.php`** — add the three keys to
`get_common_document_settings()` (near `header_logo`, line ~601) so they reach
`$this->settings` on documents:
```php
'letterhead_mode'        => $this->general_settings['letterhead_mode'] ?? '',
'letterhead_logo'        => $this->general_settings['letterhead_logo'] ?? '',
'letterhead_logo_height' => $this->general_settings['letterhead_logo_height'] ?? '',
```

### 2. Document methods — `includes/Documents/OrderDocument.php`

Refactor: extract the image-rendering body of `header_logo()` (lines ~1312-1355 —
path/URL resolution, `woi_pdf_use_path`, base64 embedding, readability checks,
`<img>` build) into a private helper:

```php
private function render_settings_image( int $attachment_id, string $alt, string $filter ): void
```

`header_logo()` keeps applying the `woi_pdf_header_logo_img_element` filter; the
helper takes the filter name so each caller applies its own. `header_logo()` and
the new `letterhead()` both call the helper. Behavior of `header_logo()` must be
unchanged.

Add the following public methods, mirroring the `header_logo` family:

- `is_letterhead_mode(): bool` → `! empty( $this->settings['letterhead_mode'] )`
- `get_letterhead_id(): int` → reads `letterhead_logo` via `get_settings_text`,
  `woi_pdf_letterhead_id` filter, returns `absint` (mirror `get_header_logo_id()`).
- `has_letterhead(): bool` → `$this->is_letterhead_mode() && $this->get_letterhead_id() > 0`.
- `get_letterhead_height()` → mirror `get_header_logo_height()` using
  `letterhead_logo_height` and a `woi_pdf_letterhead_height` filter.
- `letterhead(): void` → resolve `get_letterhead_id()`, call
  `render_settings_image( $id, $this->get_shop_name(), 'woi_pdf_letterhead_img_element' )`.

### 3. Templates (24 files)

`templates/{Simple,Modern,Business,Simple Premium}/{invoice,credit-note,packing-slip,proforma,receipt,delivery-note}.php`

In each file, wrap the existing `<table class="head container">…</table>` block:

```php
<?php if ( $this->has_letterhead() ) : ?>
    <table class="head container letterhead">
        <tr><td class="header letterhead"><?php $this->letterhead(); ?></td></tr>
    </table>
<?php else : ?>
    … existing header table (unchanged) …
<?php endif; ?>
```

Update the document-type-label guard in each template from
`if ( $this->has_header_logo() )` to
`if ( $this->has_header_logo() || $this->has_letterhead() )`, so the document title
(e.g. "INVOICE") still renders below the banner when the header row is replaced.

Representative files to verify the two markup variants:
- `templates/Simple/invoice.php` (no `header-stretcher`)
- `templates/Business/invoice.php` (has `header-stretcher`)

### 4. Styling

**Each `templates/{design}/style.css`** — add:
```css
td.header.letterhead img { width: 100%; height: auto; max-height: none; }
```

**`includes/Main.php`** — extend `set_header_logo_height()` (line ~1223) so that,
when the document has a letterhead height set, it also emits:
```css
td.header.letterhead img { max-height: <value>; }
```
(Keep the existing `td.header img` output for the normal logo.)

## Edge cases

- **Toggle on, no image uploaded** → `has_letterhead()` is false → normal header
  renders (graceful fallback). No blank banner.
- **Letterhead off** → no behavior change anywhere; `header_logo()` output is
  byte-identical to today.
- **Live preview** — the preview serializes settings and filters `option_*`
  (`includes/Settings.php:305`), so it reflects unsaved letterhead settings
  automatically. The media-upload field retaining its value before save is already
  covered by the `$args['current']` fix.
- **Summary document** removes the header-logo-height style action
  (`includes/Documents/Summary.php:44`); it does not use letterhead and needs no change.

## Testing

**Unit (Brain Monkey, `tests/Unit/`)** — new test for the document predicates:
- `is_letterhead_mode()` true only when `letterhead_mode` set.
- `has_letterhead()` true only when mode on **and** `letterhead_logo` id present;
  false when mode on but no image, and false when image set but mode off.

**Manual / preview** — enable letterhead mode, upload a banner image, and confirm
in the live admin preview that the header row is replaced by the full-width
letterhead and the document title appears below it. Spot-check two designs
(Simple, Business) and two document types (invoice, packing slip). Toggle off and
confirm the normal logo + shop details return.

## Out of scope

- Per-document-type letterhead overrides (global only).
- Reusing the existing Shop header/logo image as the letterhead.
- Footer/letterhead-footer imagery.
