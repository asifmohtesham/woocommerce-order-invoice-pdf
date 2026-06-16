# Horizontal Two-Tier Settings Navigation — Design Spec

**Date:** 2026-06-16
**Status:** Approved (design), pending implementation plan

## Goal

Replace the vertical left-nav sidebar (`.woi-shell-nav`) in the settings shell with a
**horizontal two-tier tab bar**:

- **Row 1 (main tabs):** Home · General · Documents · Customiser · Advanced — icon + label.
- **Row 2 (document sub-tabs):** Invoice · Packing Slip · Proforma Invoice · Credit Note ·
  Receipt · Summary of Invoices — shown **only when the Documents tab is active**.

The sticky dark header (breadcrumb, search, Save, Preview toggle) and the entire
`.woi-shell-content` region (preview wrapper, form, AJAX preview) are unchanged.

## Decisions (locked during brainstorming)

| Question | Decision |
|----------|----------|
| Document group layout | **Two-tier sub-tabs** — Documents collapses to one top tab; sub-row appears only when active |
| Documents top-tab landing | **Always Invoice** (already the default via `Settings.php:206`) |
| Main-tab visual | **Keep dashicon icons + labels** |
| Sub-row overflow | Native horizontal scroll (`overflow-x: auto`); no ghost-arrow JS in v1 |

## Architecture

Server-rendered, CSS-driven. `NavModel` produces the structured nav data, the view prints
two `<nav>` rows, and CSS stacks them horizontally. No new JavaScript.

### 1. Data model — `includes/Settings/NavModel.php`

`build()` changes its return shape from a single flat list to a structured array:

```php
return array(
    'tabs'      => array( /* main-row items */ ),
    'documents' => array( /* document sub-items */ ),
);
```

- **`tabs`** — main row in source order: Home, General, **Documents**, Customiser, Advanced.
  The `documents` entry is now a real clickable `tab` (not a `heading`):
  - `kind` = `tab`, `id` = `documents`, `tab` = `documents`, `section` = `''`
    (URL omits `section`; `Settings.php:206` defaults it to `invoice`).
  - `active` = `( 'documents' === $current_tab )`.
- **`documents`** — the six document sub-items, same per-item shape as today:
  `kind` = `document`, `id`/`section` = type, `label` = title, `enabled` (bool),
  `active` = `( 'documents' === $current_tab && $current_section === type )`.

NavModel stays pure data-in/data-out (no WP state), preserving unit-testability.

### 2. View — `views/settings-page.php`

`Settings.php` passes `$nav_items` (now the structured array). The `.woi-shell-body` flex
(sidebar + content) is replaced by stacked rows:

```html
<nav class="woi-shell-tabs" aria-label="…settings">
  <?php foreach ( $nav_items['tabs'] as $item ) : ?>
    <a class="woi-tab <?php active ?>" href="…">
      <span class="dashicons …"></span><span class="woi-tab-label">…</span>
    </a>
  <?php endforeach; ?>
</nav>

<?php if ( 'documents' === $current_tab ) : ?>
<nav class="woi-shell-subtabs" aria-label="…document types">
  <?php foreach ( $nav_items['documents'] as $doc ) : ?>
    <a class="woi-subtab <?php active / disabled ?>" href="…">
      <span class="woi-nav-dot"></span><span class="woi-subtab-label">…</span>
    </a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>

<main class="woi-shell-content"> … unchanged … </main>
```

- Main-tab icons reuse the existing `$nav_icons` map (`home`, `general`, `editor`, `debug`)
  plus a document icon (`dashicons-media-document`) for the Documents tab.
- URLs are built exactly as today via `add_query_arg` with `page`/`tab`/`section`
  (`array_filter` drops the empty `section` for main tabs).
- Disabled documents keep their greyed treatment (`woi-nav-disabled-doc`) and remain
  clickable so they can be enabled.

### 3. Styling — `assets/css/admin-shell.css`

Retire the vertical-sidebar rules (`.woi-shell-nav`, `.woi-nav-item`, `.woi-nav-heading`,
sidebar `.woi-nav-document`) and replace with:

- `.woi-shell-body` → `display: block`.
- `.woi-shell-tabs` — full-width horizontal flex, sticky under the dark header, bottom
  border like WP `nav-tab-wrapper`; `overflow-x: auto` for narrow screens.
  `.woi-tab` = icon + label; `.woi-tab.active` = 2px bottom accent (`#2271b1`) + 600 weight
  + `#135e96` text.
- `.woi-shell-subtabs` — lighter second strip; `overflow-x: auto`.
  `.woi-subtab` carries the green enabled `.woi-nav-dot`; `.woi-subtab.active` = underline
  accent; `.woi-nav-disabled-doc` = greyed text + hollow dot (reuse existing rule).
- `.woi-shell-content` — drop the left border, full width.
- Reuse existing color tokens: `#2271b1`, `#00a32a`, `#1d2327`, `#f0f6fc`, `#135e96`.
- Rewrite/remove the `@media (max-width: 782px)` block (lines ~211–234) that faked a
  horizontal nav out of the sidebar — redundant now that the nav is horizontal everywhere.
  Keep the header `top: 46px` admin-bar adjustment.

### 4. Cache

Bump `WOI_PDF_VERSION` in `woocommerce-orders-invoice-pdf.php` so LiteSpeed serves the new
CSS (per the standing rule: bump version whenever CSS/JS selectors change).

## Testing

- **`tests/Unit/Settings/NavModelTest.php`** updated for the new return shape:
  - `build()` returns `array( 'tabs' => [...], 'documents' => [...] )`.
  - Documents is a `tab` (kind `tab`, id `documents`), not a `heading`; its `active`
    follows `current_tab === 'documents'`.
  - Document sub-items live under the `documents` key; `enabled` + `active` assertions move
    there.
  - The "documents key absent" case yields `documents => []` and tabs without a Documents
    entry.
- Run PHPUnit with the ABSPATH prepend (`-d auto_prepend_file=tests/bootstrap.php`).
- Manual browser pass: main row renders 5 tabs with icons; selecting Documents reveals the
  6-item sub-row with Invoice active; green dots on enabled docs; disabled docs greyed;
  active accents correct; both rows scroll horizontally when narrow; breadcrumb shows
  `Documents › Invoice`; Customiser/Advanced hide the sub-row; preview/save unchanged.

## Out of scope

- Ghost-arrow scroll buttons for the rows (native scroll only in v1).
- The Customiser editor's internal document `initTabScroll()` strip (separate component,
  untouched).
- Any change to header, search, save, or the preview overlay.
