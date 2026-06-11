# Settings UI Overhaul — Design

**Date:** 2026-06-11
**Status:** Approved
**Scope:** Settings experience only (Home, General, Documents, Advanced). The Customiser keeps its current UI and is migrated into the new shell in a later project; this design must not block that migration.

## Goals

Fix four UX pains, weighted equally for the store team (power users) and a future distributed audience (first-run admins):

1. **No overview/landing** — nothing shows at a glance which documents are enabled or misconfigured.
2. **Too much scrolling/hunting** — long flat field lists make finding a setting slow.
3. **Scattered workflows** — common jobs span tab + dropdown round-trips.
4. **Dated look & feel** — legacy WP settings styling.

Inspiration: WooCommerce → Home (status cards, setup checklist, quick actions) and ACF 6 (sticky save header, toggle switches, collapsible groups, conditional fields).

## Architecture: left-nav app shell

One WP admin page as today (`WooCommerce → PDF Invoices`, slug `woi_pdf_options_page`). `views/settings-page.php` is rebuilt as an app shell with three zones:

### Sticky header (dark, ACF-6 style)

- Plugin name + breadcrumb: `PDF Invoices › Documents › Invoice`.
- Global settings search (moved from sidebar; reuses/extends existing search JS; auto-expands matching accordion groups).
- "Unsaved changes" badge driven by dirty tracking.
- Save button, always visible, submits the active form via `form.requestSubmit()`.

### Persistent left nav

Items, in order:

1. `Home` (new default tab)
2. `General`
3. `DOCUMENTS` group label, then **every registered document** (`Documents::get_documents('all')`):
   - enabled → green dot
   - disabled → greyed, hollow dot; clicking opens its settings screen where the Enable toggle is the first field — there is no separate "add document" flow
4. `Customiser` — renders the existing editor UI unchanged inside the content area
5. `Advanced` (debug)

### Content area + preview pane

Settings form and the existing live PDF preview side by side (see Preview section).

### Routing

URL scheme is unchanged: `?page=woi_pdf_options_page&tab={tab}&section={doctype}`. `tab=home` is added and becomes the default. The nav is a re-skin of existing routing — the `woi_pdf_settings_output_{$current_tab}` dispatch in the view and all deep links keep working. The document-picker dropdown in `SettingsDocuments::output()` is removed; the nav replaces it.

## Home dashboard

A React app (`wp.element` + `wp.components` — runtime ships with WP core) mounted at `#woi-pdf-home-root`. Initial state injected server-side via `wp_localize_script`; no REST round-trip on load. Built with `@wordpress/scripts` from `src/home/` to `assets/js/home.js`.

Three blocks:

1. **Setup checklist** — items computed in PHP:
   - shop name & address set
   - invoice enabled
   - invoice numbering configured (number format set or next-number store initialised)
   - header logo uploaded
   - invoice attached to at least one email
   Each item deep-links to the exact screen/accordion group that fixes it. Progress bar; dismissible; auto-hides at 5/5.
2. **Document status cards** — one card per document: enabled/off pill; next formatted number (numbered documents); email-attachment summary; `Settings ›` and `Customise ›` links. Disabled documents show a one-click **Enable** button (AJAX endpoint flips the option and refreshes the card state).
3. **Quick actions row** — Preview last invoice; Set next number (deep-links to Numbering group, auto-expanded); Sync shop address (existing `woi_pdf_sync_address` AJAX); Open Customiser.

**Explicitly excluded:** activity/health feed panel (considered, rejected — not worth the maintenance cost).

## Settings forms: ACF patterns over the PHP Settings API

No rewrite of field rendering. WP Settings API, option groups, and `SettingsCallbacks` stay. Four layers on top:

1. **Accordion groups** — the existing settings-categories system (`update_general_settings_categories`, `update_debug_settings_categories`, `apply_document_settings_categories` in `includes/Settings.php`) is the accordion source. Each category renders as a collapsible group with a field-count badge. One group open at a time; last-open group remembered per screen in `localStorage`. Search auto-expands matching groups.
2. **Toggle switches** — checkbox fields get a `woi-toggle` wrapper class; pure CSS renders the switch over the real `<input type="checkbox">`. Save flow untouched.
3. **Conditional fields** — field definitions gain an optional key:
   ```php
   'show_if' => array( 'field' => 'display_due_date', 'value' => 1 )
   ```
   Render callbacks emit `data-show-if` attributes; a small JS module shows/hides rows live as parent values change. Hidden fields still submit — saved values are never silently dropped.
4. **Sticky save + dirty tracking** — any `input`/`change` event lights the header badge and arms a `beforeunload` guard; the header Save button submits the active form.

## Preview pane

The existing split-view machinery is kept and restyled: `#woi-pdf-preview-wrapper`, gutter slider, `preview_states`, `determinePreviewStates()` in `admin.js`, AJAX preview reflecting live form values. Preview is shown on General and document screens; hidden on Home and Advanced. The Customiser keeps its existing split-view preview behavior unchanged.

## Responsiveness

- Below ~1280px: left nav collapses to icons with flyout labels.
- Below ~1100px: preview becomes a header-toggled overlay instead of a third column.

## Technical changes

| Area | Change |
|---|---|
| `views/settings-page.php` | Rebuilt as the shell (header, nav, content, preview). Nav model built in `Settings::settings_page()` from the `woi_pdf_settings_tabs` filter + `Documents::get_documents('all')` and passed to the view. |
| `includes/Settings/SettingsHome.php` (new) | Registers `tab=home`, computes checklist state, enqueues/mounts the React app, handles Enable-document AJAX. |
| `src/home/` (new) | React source. `package.json` gains `@wordpress/scripts` as a dev dependency; runtime deps (`wp-element`, `wp-components`) ship with WP core. |
| `assets/css/admin-shell.css` (new) | Shell layout, dark header, nav, toggles, accordions, responsive breakpoints. |
| `assets/js/admin-shell.js` (new) | Accordions, conditional fields, dirty tracking, sticky save, nav collapse. Existing `admin.js` preview logic untouched. |
| `includes/Settings/SettingsDocuments.php` | `output()` drops the dropdown picker block; settings rendering kept. |
| `woocommerce-orders-invoice-pdf.php` | `WOI_PDF_VERSION` bump — LiteSpeed cache busting whenever CSS/JS selectors change. |

## Failure handling

- **Graceful degradation:** every settings screen remains a plain PHP form. If shell JS fails, accordion groups render expanded and a native submit button at the bottom of the form (kept in DOM, visually hidden only when shell JS boots) still saves.
- **Home fallback:** the `#woi-pdf-home-root` mount div contains server-rendered links to General and document screens, replaced when React mounts — the page is never a dead end if the bundle fails to load.
- **Hidden conditional fields still submit**, so toggling a parent off and saving does not erase child values.

## Testing

- Unit tests (existing PHPUnit setup; note the `auto_prepend_file=tests/bootstrap.php` requirement): checklist computation, nav-model builder.
- `tests/Unit/ServiceWiringTest.php` must stay green.
- Manual Playwright pass: Home renders with correct card states; document screen accordion/toggle/conditional behavior; save flow from sticky header; responsive breakpoints at ~1280px and ~1100px.

## Decisions log

| Decision | Choice | Alternatives rejected |
|---|---|---|
| Layout architecture | Left-nav shell | Incremental Home tab (doesn't fix scattered workflows); hub-and-spoke (power-user tax on cross-document hops) |
| Home tech | `wp.element` + `wp.components` | Alpine.js (hand-built WC-Admin look); vanilla PHP/JS (least app-like) |
| Overall stack | Hybrid: PHP forms + React Home only | Full React rebuild (REST settings layer, rewriting all field rendering) |
| Customiser | Deferred to follow-up; shell designed so it slots in as a nav item | In-scope now (too risky — JS-heavy editor) |
| Activity/health panel | Excluded | — |
