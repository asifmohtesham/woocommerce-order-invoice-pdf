# Customiser Document Picker: Tabs → Dropdown — Design Spec

**Date:** 2026-06-16
**Status:** Approved (design), pending implementation plan

## Goal

Replace the horizontal document **tab strip** in the Customiser editor (`#documents`) with a
**dropdown `<select>`**. The strip currently shows Invoice · Packing Slip · Proforma Invoice ·
Credit Note · Receipt · Summary of Invoices as jQuery UI tabs wrapped in a scrollable
`.tab-scroll-wrapper` with ‹ › arrows. That whole strip (and its scroll machinery) is removed
in favour of a single labeled dropdown.

## Decisions (locked during brainstorming)

| Question | Decision |
|----------|----------|
| Control type | Native `<select>`, **conditionally** select2-enhanced |
| select2 threshold | Use select2 (`wc-enhanced-select`) only when document count **> 8**; otherwise native. Threshold filterable via `woi_pdf_document_select_select2_threshold` |
| Panel engine | **Keep jQuery UI `.tabs()`** — the `<select>` is a remote control that calls `tabs('option','active', selectedIndex)`. The `<ul class="document-tabs">` stays in the DOM (hidden) because `.tabs()` binds to it |
| Scroll arrows | Removed (`.tab-scroll-wrapper`, ‹ ›, `initTabScroll()` all retired) |

## Architecture

Server-rendered control + thin JS proxy. PHP renders a `<select>` (and keeps the hidden
`<ul>`); JS forwards `change` events to the existing tabs widget and syncs the preview
document type; CSS hides the `<ul>` and styles the select. The jQuery UI tabs widget continues
to own panel show/hide and ARIA — none of that logic is rewritten.

**Why keep the `<ul>`:** jQuery UI Tabs is driven by the first `<ul>`/`<ol>` descendant of
`#documents` — it reads each `<li><a href="#panelId">` to build its tab→panel map and owns
panel visibility thereafter. Deleting the `<ul>` would break `.tabs()` and force a hand-rolled
panel toggle. A `<select>` sibling is not a list, so `document-tabs` remains the first `<ul>`
and the widget is unaffected. Option order === `<ul>` order === panel order (all the same
`$args['documents']` loop), so `selectedIndex` maps 1:1 to the tab active index.

## Components

### 1. Markup — `includes/Editor/EditorSettings.php` (~lines 1388–1401)

Replace the `.tab-scroll-wrapper` block with:

- A `.document-select-wrapper` containing a `<label>Document</label>` and a
  `<select class="document-select">`.
- One `<option value="#{id}_{document}" data-document_type="{document}">{title}</option>` per
  document, rendered from the same `$args['documents']` loop, in panel order.
- select2 branch: compute
  `$threshold = (int) apply_filters( 'woi_pdf_document_select_select2_threshold', 8 )`. If
  `count( $args['documents'] ) > $threshold`, append `wc-enhanced-select` to the select's class
  so the existing `wc-enhanced-select-init` trigger (editor.js:206) enhances it; otherwise the
  select stays native.
- The `<ul class="document-tabs">` (with its `<li><a href="#…" data-document_type="…">`) is
  **kept unchanged** immediately after the wrapper — hidden via CSS. It must remain the first
  `<ul>` inside `#documents`.
- Per-document panels below are unchanged.

The only branch is one class toggle on the `<select>`; everything downstream is identical for
native and select2.

### 2. Behaviour — `assets/js/editor.js`

- **Add** a delegated change handler (after the `$('#documents').tabs().show()` init at
  line 205):
  ```js
  $( '#documents' ).on( 'change', '.document-select', function() {
      $( '#documents' ).tabs( 'option', 'active', this.selectedIndex );
      var document_type = $( this ).find( 'option:selected' ).data( 'document_type' );
      $( '#woi-pdf-preview-wrapper :input[name="document_type"]' ).val( document_type ).trigger( 'change' );
  } );
  ```
  Delegated on `#documents` so it works after select2 wraps the control; select2 fires `change`
  on the underlying `<select>` and `selectedIndex` stays valid.
- **Remove** the dead scroll machinery: the `initTabScroll()` call (line 207), the
  `initTabScroll()` function definition (lines 278–312), and the `debounce` helper (lines 2–8)
  — `debounce` is used only by `initTabScroll`.
- **Remove** the old `ul.document-tabs > li > a` click handler (lines 346–349): the `<ul>` is
  hidden so it never fires; the select handler supersedes it.
- **Replace** the `$(document).ready` active-tab→preview sync (lines 352–360) to read the
  select's selected option instead of `li.ui-state-active`:
  ```js
  $( document ).ready( function() {
      var $select = $( '#documents .document-select' );
      if ( $select.length ) {
          var document_type = $select.find( 'option:selected' ).data( 'document_type' );
          if ( document_type && document_type !== 'invoice' ) {
              $( '#woi-pdf-preview-wrapper :input[name="document_type"]' ).val( document_type ).trigger( 'change' );
          }
      }
  } );
  ```
  (Default selected option is Invoice at index 0, so this is normally a no-op.)

### 3. Styling — `assets/css/editor.css`

- **Remove** the obsolete rules: `.tab-scroll-wrapper`, `.tab-scroll-track` (+ `::before` /
  `::after` fades + `.at-start` / `.at-end`), `.tab-scroll-btn` (+ `:hover` / `.hidden`), and the
  `.document-tabs` flex/scroll + tab-look rules (lines 27–120), plus the mobile overrides for
  `.tab-scroll-wrapper` and `.document-tabs li` (lines 447–458).
- **Add** `#woi-pdf-settings .document-tabs { display: none; }` to hide the engine `<ul>`.
- **Add** `.document-select-wrapper` (labeled row where the strip sat),
  `.document-select-label`, and `.document-select` (sensible `min-width`/`max-width` covering
  the select2 case). Reuse the existing token palette (`#555`, label weight/size from the old
  tab look).

### 4. Cache — `woocommerce-orders-invoice-pdf.php`

Bump `WOI_PDF_VERSION` 1.3.0 → 1.3.1 (lines 6 and 24) so LiteSpeed serves the changed editor
CSS/JS (standing rule: bump version whenever editor CSS/JS selectors change).

## Testing

- No unit-test harness exists for the editor markup/JS/CSS; the `NavModel` suite is unrelated.
  Run the full PHPUnit suite (`php -d auto_prepend_file=tests/bootstrap.php
  vendor/phpunit/phpunit/phpunit`, output to a file — see [[phpunit-abspath-gotcha]]) to confirm
  no regressions (61 tests).
- `php -l` the changed PHP file.
- Manual browser pass on the Customiser tab (hard refresh / clear LiteSpeed cache first):
  1. The tab strip and ‹ › arrows are gone; a labeled **Document** dropdown sits in their place.
  2. The Invoice panel shows by default.
  3. Selecting another document (e.g. Packing Slip) switches the editor panel below and the PDF
     preview document type to match.
  4. Switching back to Invoice restores the Invoice panel + preview.
  5. Keyboard: the select is focusable and operable via keyboard.
  6. No console errors; no leftover scrollbar/arrow artifacts.
  7. (If feasible) raise the threshold filter below the document count to confirm the select2
     branch renders a searchable dropdown and items 1–4 still work.

## Out of scope

- The shell-level horizontal nav (separate component, shipped v1.3.0) — untouched.
- The earlier scrollable tab strip work (specs/plans dated 2026-06-10) is **superseded** by this
  change; those docs remain as history.
- Any change to the per-document panels, fields, columns editor, or preview overlay.
