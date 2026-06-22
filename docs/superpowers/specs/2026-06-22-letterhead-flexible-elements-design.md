# Letterhead per-element flexibility (Slice 2)

**Date:** 2026-06-22
**Status:** Approved design — ready for implementation plan
**Scope:** Letterhead section of the Block Invoice Template. Follow-up to the
contact-strip slice ([2026-06-22-contact-strip-flexible-elements-design.md]),
reusing its option-transport pattern.

## Problem

The Block Invoice Template's **Letterhead** renders from a fixed server-side
builder (`TemplateTokens::section_letterhead`): a 3-column table — EN block
(company name + address, left) · logo (centre) · AR block (company name +
address, RTL, right), at widths 40/20/40, with a single `header: center|left`
variant. There is no way to align, style, show/hide, or rearrange its elements.

The user wants per-element control over the letterhead, matching what the
contact strip now offers — extended for the letterhead's richer structure
(bilingual two-column text + a logo image).

## Chosen approach

**Option-transport (same as the contact strip).** Config lives in WordPress
options the PDF renderer reads directly — never serialized into the saved block
HTML. This avoids the data-attribute pitfalls fixed in the contact-strip slice
(block-validation breakage, escaping fragility, `wp_kses` stripping unknown
`data-*`). The letterhead block's `save` stays the bare `{{letterhead}}` token
(valid + kses-safe); the editor persists config to the options via REST; the
repeat-letterhead running-header inherits the same config because it shares the
builder.

### Storage — two homes, by responsibility

1. **Logo position = the existing `header` doc-option**, extended from
   `center|left` to `left|center|right`. This is the user's explicit
   requirement: the legacy "LETTERHEAD" sidebar dropdown and the new arrangement
   control edit the *same* key, so they stay in sync by construction. Stored in
   `woi_pdf_visual_doc_options['header']` (the existing scalar doc-options blob).

2. **New `woi_pdf_letterhead` option** (array) holds everything with no legacy
   twin:
   - `swapText` (bool) — swap which side EN vs AR sits on (default EN-first).
   - `logoWidth` (int, mm; 0 = default).
   - `elements`: per-element settings for the four text elements
     (`name_en`, `address_en`, `name_ar`, `address_ar`) and the logo:
     - text: `{ visible, align, bold, fontSize, color }`
     - logo: `{ visible }` (position via `header`, size via `logoWidth`).

## Components

### 1. PHP option readers/sanitizers — `woi-pdf-functions.php`

- `woi_pdf_default_letterhead()` — default config reproducing today's render:
  all elements visible; `name_en`/`address_en` align `left`;
  `name_ar`/`address_ar` align `right`; `swapText` false; `logoWidth` 0.
- `woi_pdf_sanitize_letterhead( $raw )` — normalize: only the five known element
  keys; per text element whitelist `align ∈ {left,center,right}`, boolean
  `visible`/`bold`, `fontSize` int clamped 0–48, `color` hex-or-empty;
  `swapText` bool; `logoWidth` int clamped 0–120. Empty/malformed → default.
  Single source of truth for save and read.
- `woi_pdf_letterhead()` — `woi_pdf_sanitize_letterhead( get_option(
  'woi_pdf_letterhead', array() ) )`.
- Extend `woi_pdf_visual_doc_options()`: `header` allowed values become
  `array( 'left', 'center', 'right' )` (default stays `center`).

### 2. PHP render — `TemplateTokens::section_letterhead()`

Reads `woi_pdf_letterhead()` (guarded by `function_exists`, with the historical
hardcoded fallback so the unit harness — which does not load the functions file
— still renders). Reads logo position from `woi_pdf_visual_doc_options()['header']`.

Builds the 3-column table:
- Column order determined by `header` (logo left/centre/right) and `swapText`
  (EN/AR sides). The logo occupies one slot; the two text blocks fill the rest.
- Each visible text element renders with inline `text-align` + value-span inline
  `font-weight`/`font-size`/`color` (mPDF-safe inline styles, the contact-strip
  convention). Hidden elements are omitted; an empty text column's slot is
  dropped and the remaining column widths rebalance.
- Logo: rendered when `logo.visible`; sized inline from `logoWidth` (0 → keep
  the existing `.woi-lh-mark img { max-height:18mm }` contract).
- AR elements render only when the `arabic` doc-option is on **and** the
  element's own `visible` is true.

### 3. REST — `Rest.php`

`POST woi-pdf/v1/letterhead` → `handle_letterhead_save`: cap
`manage_woocommerce`, `woi_pdf_sanitize_letterhead( (array) items )`,
`update_option( 'woi_pdf_letterhead', $clean, false )`, return the clean config.
(Logo position continues to save through the existing `/visual-doc-options`
route — no change there beyond the widened `header` whitelist.)

### 4. Editor — `src/block-editor/blocks/letterhead.js` + model

- `letterheadModel.js` (pure, no `@wordpress/*`): `LH_FIELDS` (label + value
  token per element), `LH_DEFAULT` (default config), `lhValueStyle(item)`,
  helpers. Jest-tested (mirrors `contactStripModel.js`).
- `LetterheadEdit`: seeds from `window.woiBlocks.letterhead` (+ `header` from
  `docOptions`), renders the three columns with the five elements (live values
  from the preview store). Selecting an element shows its controls in the
  inspector (text: visible/align/bold/size/colour; logo: visible). A "Layout"
  panel holds logo position (writes `header` via `/visual-doc-options`),
  swap-sides toggle and logo-width slider (write `woi_pdf_letterhead` via
  `/letterhead`). All persistence debounced, like the contact strip.
- `letterheadSave`: bare `{{letterhead}}` token via `useBlockProps.save(
  appearanceProps(attributes) )`. The letterhead block's `items`/data attributes
  are not used.
- Wire in `token.js`: the `woi/letterhead` entry gets a `letterhead: true`
  flag selecting `LetterheadEdit` + `letterheadSave` (generic save otherwise);
  no block attribute beyond `APPEARANCE_ATTRS`.

### 5. Localize — `BlockEditorPage.php`

Add `'letterhead' => woi_pdf_letterhead()` to the `woiBlocks` payload (alongside
`contactItems`). `docOptions.header` already carries the logo position.

## Data flow

`LetterheadEdit` ⇄ local state (seeded from `woiBlocks.letterhead` +
`docOptions.header`) → debounced REST (`/letterhead` for element/swap/width,
`/visual-doc-options` for `header`) → options → `section_letterhead()` reads both
options → builds the table → mPDF / canvas. Logo position is one shared key, so
the legacy dropdown and the arrangement control never diverge.

## Edge cases & error handling

- Malformed/empty `woi_pdf_letterhead` → default layout; unknown element keys
  skipped.
- `header` out of range → default `center` (existing whitelist behaviour).
- All text elements in a column hidden → that column slot dropped, widths
  rebalance; logo-only letterhead still renders.
- `arabic` off → both AR elements suppressed regardless of their `visible`.
- Legacy templates / no options set → exact current letterhead. No migration.

## Testing

- **PHPUnit** (`TemplateTokensTest`): `section_letterhead` reads the option
  (stub `woi_pdf_letterhead` + `woi_pdf_visual_doc_options` via Brain Monkey),
  arrangement permutations (logo left/centre/right, swapText), per-element
  show-hide + inline style, bilingual gating, default-unchanged. Establish the
  baseline (the suite has known pre-existing failures) and assert no new ones.
- **Jest** (`letterheadModel.test.js`): fields/defaults/value-style helpers.
- **Local mPDF harness** + **live CDP harness** after deploy: confirm the block
  stays valid (bare token), edits persist to the options, and the PDF + running
  header reflect arrangement/visibility/style.

## Out of scope

- Free cross-column drag of individual elements (the bilingual two-column
  structure is preserved; arrangement is column-level).
- Per-column width sliders (logo width only; text columns rebalance).
- Editing the company name/address text itself (those remain shop-settings data).
- Applying this to other sections (title/meta, parties, etc.).

## Follow-up

With contact strip + letterhead both on the option-transport pattern, a shared
`woi_pdf_section_items` helper could later de-duplicate the sanitize/read/REST
boilerplate if a third section needs it (YAGNI until then).
