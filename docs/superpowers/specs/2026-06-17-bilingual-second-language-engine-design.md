# Bilingual Second-Language Engine — Design

**Date:** 2026-06-17
**Status:** Approved (design), pending implementation plan
**Reference target:** `ABU ELYAS YAMAN 1.5.2025.pdf` (Milano Leather Trading L.L.C — bilingual EN/AR UAE tax invoice)

## Context

The plugin renders WooCommerce PDF documents through four selectable template themes
(Business, Modern, Simple, Simple Premium) plus a live Customiser preview. Templates are
hook-driven PHP. All document labels funnel through a single chokepoint,
`OrderDocument::get_title_for( $slug )`, which returns single-language i18n strings.
Whole-document RTL exists (`.rtl { direction: rtl }`) but there is **no bilingual
(two-languages-in-one-document) capability**.

The reference invoice is genuinely bilingual: English and Arabic appear together in the
same document, in three distinct patterns:

1. **Mirror blocks** — shop address and buyer block rendered twice, English (LTR) left,
   Arabic (RTL) right.
2. **Stacked label pairs** — item-table header cells stack English over Arabic
   (`Description of Goods` / `البيان الصنف`).
3. **Inline label pairs** — single-line labels show both (`Invoice No\الفاتورة رقم`,
   `Total\المجموع`).

This is the first of six decomposed sub-projects that together let the builder reproduce
the reference. The others (tracked separately) are: **B** item-table columns (Part No.,
alt-unit quantity, "per", summed-unit footer totals), **C** VAT summary block, **D**
amount-in-words block, **E** signature/attestation block, **F** buyer-block extra fields
(Place of supply, Contact person, Contact). This spec covers **A — the bilingual
second-language engine** only, plus the scaffold for a shared "Standard UAE Tax Invoice"
preset that the later sub-projects extend.

## Goals

- A **general, language-agnostic** second-language engine: any template can render a
  configured second language alongside the primary, in all three patterns above.
- Ship an **Arabic preset** (EN→AR seed dictionary, bundled Noto Naskh Arabic font, RTL on)
  so it works near-turnkey for Arabic.
- **User-editable translations** through the Customiser — bundled dictionary is seed
  defaults only, never the dead-end source of truth.
- Ship a selectable **"Standard UAE Tax Invoice" preset template** pre-wired to the engine.
- **Zero impact** on non-bilingual documents: when disabled, output is byte-for-byte today's
  output and no engine assets (font `@font-face`, dictionary) load.

## Non-goals

- Per-customer / per-product translated content meta (content values resolve from settings +
  `WC_Countries`, otherwise render as-is). Buyer name and product descriptions are rendered
  as-is (Latin) in both columns, matching the reference.
- WPML/Polylang integration.
- The UAE column set, VAT summary, amount-in-words, and signature blocks — those are
  sub-projects B/C/D/E. This spec only scaffolds the preset and wires its bilingual layer.
- More than two languages simultaneously.

## Decisions (from brainstorming)

| Decision | Choice |
|---|---|
| Language scope | Generic engine, **Arabic preset** shipped |
| Layout patterns | **All three** (mirror blocks + stacked + inline label pairs) |
| Content-value source | Shop AR from admin settings; customer country/state via `WC_Countries` with fallback; buyer name + product text as-is |
| Arabic font | **Noto Naskh Arabic** (Regular + Bold, SIL OFL) |
| Translations | **User-editable** in Customiser, seeded from bundled preset dictionary |
| Preset | Ship selectable **"Standard UAE Tax Invoice"** template on the shared engine |

## Architecture

### Approach (chosen)

Engine service + filter/helper-injected label pairs + thin per-template mirror helpers.
A central `BilingualEngine` owns the dictionary, font/RTL CSS, and content-value resolution.
Label-pair logic is centralized in `OrderDocument` render helpers and the table/totals
builders. Mirror-block markup is produced by new `OrderDocument` helper methods that each
template calls in place of its inline single-language markup, delegating to today's output
when bilingual is off. Works across all four templates **and** the Customiser preview.

Rejected: (2) a dedicated bilingual template *as the mechanism* — gives no general capability;
(3) a fully data-driven block renderer shared by all templates — ground-up refactor, YAGNI.
Note: the "Standard UAE Tax Invoice" preset below is a template *on top of* the general
engine, not a replacement for it.

### Components

**`includes/Bilingual/BilingualEngine.php`** (new) — single service, instantiated/accessed
through the main plugin container. Responsibilities, each independently testable:

- `is_enabled( OrderDocument $document ): bool` — reads the per-document
  `enable_second_language` Customiser setting. Default false.
- `secondary_language( OrderDocument $document ): string` — language code (default `ar`).
- `is_rtl( OrderDocument $document ): bool` — per-language RTL flag (true for `ar`).
- `secondary_label( string $key, OrderDocument $document ): string` — resolves a label's
  secondary text. **Resolution order: user override (saved setting) → bundled preset
  dictionary → empty.** Wrapped by filters `woi_pdf_second_language_dictionary` (whole map)
  and `woi_pdf_second_language_label` (per key).
- `secondary_shop_name() / secondary_shop_address()` — from settings `shop_name_ar` /
  `shop_address_ar`; empty → caller falls back to primary.
- `localized_location( string $value, string $type, $order )` — country/state name via
  `WC_Countries` in the secondary locale; falls back to the order's stored value.
- `font_css(): string` and `font_family(): string` — `@font-face` for the bundled font and
  the family name to apply to secondary text. **Emitted only when enabled.**

**Dictionary** — `includes/Bilingual/dictionary/ar.php` returns an associative array keyed by
stable keys: label slugs (`document`, `document_number`, `document_date`, `due_date`,
`billing_address`, `shipping_address`, `order_number`, `order_date`), column types (`sku`,
`description`, `quantity`, `price`, `tax_rate`, `weight`), totals types (`subtotal`,
`discount`, `shipping`, `fee`, `vat`, `total`). These are **seed defaults** copied into the
editable settings table on preset load; runtime resolution always consults the saved settings
first.

### Label-pair rendering (patterns 2 & 3)

The escaping constraint: print methods do `echo esc_html( get_title_for(...) )` and templates
do `esc_html( $header_data['title'] )`, so a second-language `<span>` cannot be smuggled
through the returned string. Therefore rendering, not the string, carries the pair:

- `OrderDocument` print helpers (`title()`, `number_title()`, `date_title()`,
  `due_date_title()`, `billing_address_title()`, `shipping_address_title()`,
  `order_number_title()`, `order_date_title()`) route through a new centralized
  `render_label( string $slug ): void`. When enabled it emits
  `<span class="woi-lbl-primary">EN</span><span class="woi-lbl-secondary" dir="rtl">AR</span>`
  (each part individually escaped); when disabled it emits today's `esc_html` single output.
  One change, inherited by all four templates and the Customiser.
- `woi_pdf_templates_get_table_headers()` and `woi_pdf_templates_get_totals()`
  (in `woi-pdf-functions.php`) gain a `secondary` value per row. The four templates'
  header/totals loops render a `woi-lbl-secondary` span when `secondary` is non-empty
  (small, uniform edits).
- **Stacked vs inline** is CSS: `.woi-lbl-secondary` is block (stacked) by default — used in
  table headers; a `.woi-lbl-inline` modifier renders it inline with a separator — used in
  label rows. Defined in each template `style.css` and the Customiser editor CSS.

### Mirror-block rendering (pattern 1)

- New `OrderDocument` helpers `bilingual_shop_block()` and `bilingual_address_block( $type )`
  emit a two-column row (primary LTR left, secondary RTL right) when enabled; otherwise they
  delegate to today's single-language output, so non-bilingual templates are unchanged.
- Each template's header/address regions call these helpers instead of inlining
  `$this->shop_address()` / `$this->billing_address()`. Markup-generating logic lives in the
  helper (DRY); the template only positions it, preserving per-template layout.
- Document title is centered and stacked: primary from the existing configurable
  `document_title` setting; secondary from a new `document_title_secondary` setting (AR preset
  seeds `فاتورة ضريبية`).
- The buyer block's extra rows (Emirate/Country/Place of supply) are **sub-project F**; this
  sub-project mirrors whatever the address block currently renders. Because F's fields flow
  through the same helpers, they become bilingual automatically.

### Content-value resolution

- **Shop AR name/address** — new settings `shop_name_ar`, `shop_address_ar`; empty → secondary
  shop cell falls back to primary text (never blank).
- **Customer country/state** — `localized_location()` via `WC_Countries`; falls back to the
  order's stored value when no localized name exists.
- **Buyer name, product descriptions** — rendered as-is (same Latin text) in both columns.

### Fonts — Noto Naskh Arabic into Dompdf

- Bundle `NotoNaskhArabic-Regular.ttf` + `NotoNaskhArabic-Bold.ttf` under each template's
  `fonts/` directory (consistent with existing per-template `Roboto`/`Segoe` bundling) so the
  `FontSynchronizer` copies them into Dompdf's tmp `fonts/` dir.
- `@font-face` for `'Noto Naskh Arabic'` injected via the engine's style hook; secondary
  spans/cells set `font-family` to it. Latin text keeps the template's existing font.
- `isFontSubsettingEnabled` is already true → only used glyphs embed, keeping PDF size down.
- Guardrail: `@font-face` + dictionary load **only when bilingual is enabled**.

### Settings / Customiser UI

A new **"Second language"** section in the Customiser, using the existing ACF-style `show_if`
conditional-field machinery so dependent fields appear only when enabled:

- `enable_second_language` — per-document toggle, **off by default**.
- `second_language` — dropdown, default `Arabic`. Selecting a preset seeds the editable table
  + RTL flag + font.
- `second_language_rtl` — flag (auto-set by preset, overridable).
- **Editable label table** — each known label shows primary text (read-only) and an editable
  secondary field, pre-filled from the preset dictionary. Blank → falls back to dictionary.
- `document_title_secondary`, `shop_name_ar`, `shop_address_ar` — content fields.

### "Standard UAE Tax Invoice" preset template

- New selectable template `templates/Standard UAE Tax Invoice/` (php + `style.css` + `fonts/`),
  registered alongside the existing four.
- Its `template-functions.php` defaults turn the engine **on**: second language = Arabic, RTL,
  `document_title` = `Tax Invoice`, `document_title_secondary` = `فاتورة ضريبية`, AR dictionary
  seeded, Noto Naskh bundled.
- `style.css` carries the boxed/bordered tabular look of the reference.
- Uses the **shared** `BilingualEngine` + `OrderDocument` helpers — no duplicated rendering.
- **Scope:** this sub-project scaffolds the template and wires its bilingual layer + styling.
  The UAE column set (B), VAT summary (C), amount-in-words (D), and signatures (E) are filled
  in by their own sub-projects. The spec must not present the preset as complete until then.

## Fallbacks & error handling

- Secondary text **always** falls back to primary, never renders blank.
- When disabled: no `@font-face`, no dictionary load, output identical to today.
- Missing/locale-less country or state → original stored value.
- Empty shop AR settings → primary shop text in the secondary cell.

## Testing

Unit (`tests/Unit/Bilingual/`):
- Resolver order: user override → dictionary → empty, and per-key filter.
- Content resolvers: shop AR (set + empty fallback), `WC_Countries` localized country/state
  (hit + miss fallback).
- `is_enabled` / `is_rtl` / `secondary_language` defaults.
- Render smoke test: bilingual spans/cells present when enabled, absent when disabled.

Manual:
- Customiser preview shows all three patterns for the preset template.
- Generated PDF renders Arabic correctly (font embedded), non-bilingual templates unchanged.
- Run `tests/Unit/ServiceWiringTest.php` after wiring the engine into the main container.

## Key integration facts (for the plan)

- Label chokepoint: `OrderDocument::get_title_for()` / print helpers (`includes/Documents/OrderDocument.php`).
- Table/totals builders: `woi_pdf_templates_get_table_headers()`, `woi_pdf_templates_get_totals()` in `woi-pdf-functions.php`.
- Per-template default mechanism: `woi_pdf_template_editor_defaults` / `woi_pdf_*_template_defaults` (see `templates/Business/template-functions.php`).
- Font copy path: `Main::copy_fonts()` + `FontSynchronizer`; Dompdf options in `woi_pdf_dompdf_options`.
- Bump `WOI_PDF_VERSION` when CSS/JS selectors change (LiteSpeed cache on live site).
- Customiser preview must render identically to the PDF — verify the editor preview path uses the same template + helpers during planning.
