# Visual Editor UX Improvements (Slice 3) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Five UX improvements to the invoice GrapesJS editor — suppress page notices, hide the device switcher, enable native table-cell editing, and replace the three new-tab previews with one in-place dual-mode (live HTML + embedded real PDF) preview pane.

**Architecture:** Almost entirely editor JS (`app.js`), admin PHP (`VisualEditorPage.php`), a new editor CSS file, and reuse of the existing slice-2 `visual-preview-data` REST endpoint + `woi_pdf_preview` / `woi_pdf_preview_order_search` ajax. No render-engine, token-merge, or storage changes; no new endpoints.

**Tech Stack:** PHP 8.1+, WordPress/WooCommerce, GrapesJS 0.21.13 (vendored), PHPUnit 9.6 + Brain Monkey.

## Global Constraints

- PHP floor **8.1**. PHP files start with `if ( ! defined( 'ABSPATH' ) ) exit;` after the namespace; classes use `class_exists`/`endif` guards.
- Canonical test command (PHP 8.4; `display_errors` REQUIRED; do NOT use `vendor/bin/phpunit`):
  `php -d display_errors=1 -d error_reporting=E_ALL -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit`
  Baseline before this slice: **177 tests / 0 errors / 1 skipped**.
- Brain Monkey CANNOT stub functions defined in `woi-pdf-functions.php`; WP-core functions (`get_current_screen`, `remove_all_actions`, etc.) are NOT defined in tests → Brain Monkey CAN stub them.
- No new REST endpoints. Live HTML uses `GET woi-pdf/v1/visual-preview-data` (slice 2); PDF tab uses admin-ajax `woi_pdf_preview`. `woiVisual` already provides `restUrl, ajaxUrl, previewUrl, nonce, previewNonce, docType, stored, starter, sampleData, previewDataUrl, orderSearchAction`.
- Notice suppression is gated STRICTLY to the editor screen (`get_current_screen()->id` contains `woi-pdf-visual`); never global.
- Device switcher HIDDEN (`deviceManager: { devices: [] }` + CSS), not repositioned.
- The three slice-1/2 new-tab preview buttons (`woi-preview-pdf`, `woi-preview-sample`, and the order-bar "Preview real order" button) are REMOVED and consolidated into the pane.
- Token block content unchanged: `<span data-woi-token="T">{{T}}</span>`. mPDF-safe markup (tables, not flex/grid) for any document content.
- Bump `WOI_PDF_VERSION` (header + `$version`) for new JS/CSS.
- Branch `feat/visual-editor-ux` (spec committed there).
- JS has no in-repo harness → JS tasks use `node --check` + live verification via the harness (debug Chrome :9222 + puppeteer-core in `%TEMP%\woi-cdp` + PyMuPDF). Deploy is manual pull; confirm the deployed revision on the Status tab before live testing. Live tests that save a design MUST capture and restore the user's stored design.

**#2 table note (deviation surfaced):** the spec preferred a vendored plugin; this plan implements the **native component config** (the spec's documented fallback) because no GrapesJS table plugin is verified-compatible with the pinned 0.21.13 without runtime research, and native config carries no dependency/SRI risk. It delivers all four capabilities (edit text, drop blocks in, add/remove rows & columns, per-cell styling).

## File structure

- `includes/Visual/VisualEditorPage.php` — MODIFY: notice suppression (#1) + helper; enqueue `editor.css`; render preview-pane markup (#4/#5).
- `tests/Unit/Visual/VisualEditorNoticesTest.php` — CREATE: notice-suppression unit tests.
- `assets/visual-editor/editor.css` — CREATE: device-hide (#3), pane layout (#4/#5), in-canvas table affordances.
- `assets/visual-editor/app.js` — MODIFY: hide devices (#3); native table types + row/col commands (#2); preview pane toggle/tabs/live-HTML/PDF (#4/#5); remove old new-tab buttons.
- `woocommerce-orders-invoice-pdf.php` — MODIFY: version bump.

---

### Task 1: Suppress admin notices on the editor page (#1)

**Files:**
- Modify: `includes/Visual/VisualEditorPage.php`
- Test: `tests/Unit/Visual/VisualEditorNoticesTest.php`

**Interfaces:**
- Consumes: `get_current_screen()`, `remove_all_actions()`.
- Produces:
  - `VisualEditorPage::is_visual_editor_screen(): bool` — true when `get_current_screen()->id` contains `woi-pdf-visual`.
  - `VisualEditorPage::suppress_admin_notices(): void` — when on-screen, removes `admin_notices` / `all_admin_notices` / `user_admin_notices`.
  - Constructor hooks `admin_head` → `suppress_admin_notices`.

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace WOI\PDF\Tests\Unit\Visual;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Visual\VisualEditorPage;

class VisualEditorNoticesTest extends TestCase {

	protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
	protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

	private function page(): VisualEditorPage {
		// Constructor only registers hooks (add_action/add_filter) — stub them.
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		return new VisualEditorPage();
	}

	public function test_is_visual_editor_screen_true_on_editor(): void {
		Functions\when( 'get_current_screen' )->justReturn( (object) array( 'id' => 'woocommerce_page_woi-pdf-visual' ) );
		$this->assertTrue( $this->page()->is_visual_editor_screen() );
	}

	public function test_is_visual_editor_screen_false_elsewhere(): void {
		Functions\when( 'get_current_screen' )->justReturn( (object) array( 'id' => 'edit-post' ) );
		$this->assertFalse( $this->page()->is_visual_editor_screen() );
	}

	public function test_is_visual_editor_screen_false_when_no_screen(): void {
		Functions\when( 'get_current_screen' )->justReturn( null );
		$this->assertFalse( $this->page()->is_visual_editor_screen() );
	}

	public function test_suppress_removes_notice_actions_on_screen(): void {
		Functions\when( 'get_current_screen' )->justReturn( (object) array( 'id' => 'woocommerce_page_woi-pdf-visual' ) );
		$removed = array();
		Functions\when( 'remove_all_actions' )->alias( function ( $hook ) use ( &$removed ) { $removed[] = $hook; return true; } );

		$this->page()->suppress_admin_notices();

		$this->assertSame( array( 'admin_notices', 'all_admin_notices', 'user_admin_notices' ), $removed );
	}

	public function test_suppress_noop_off_screen(): void {
		Functions\when( 'get_current_screen' )->justReturn( (object) array( 'id' => 'edit-post' ) );
		$called = false;
		Functions\when( 'remove_all_actions' )->alias( function () use ( &$called ) { $called = true; return true; } );

		$this->page()->suppress_admin_notices();

		$this->assertFalse( $called );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d display_errors=1 -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit tests/Unit/Visual/VisualEditorNoticesTest.php`
Expected: FAIL — `is_visual_editor_screen` / `suppress_admin_notices` not defined.

- [ ] **Step 3: Implement the methods + hook**

In `includes/Visual/VisualEditorPage.php` constructor, add (alongside the existing `add_action`/`add_filter` calls):

```php
        add_action( 'admin_head', array( $this, 'suppress_admin_notices' ), 1 );
```

Add these methods to the class:

```php
	/** True only on the dedicated Visual Template editor screen. */
	public function is_visual_editor_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && isset( $screen->id ) && false !== strpos( (string) $screen->id, self::PAGE_SLUG );
	}

	/** Remove third-party admin notices on the editor screen (they clutter the full-screen editor). */
	public function suppress_admin_notices(): void {
		if ( ! $this->is_visual_editor_screen() ) {
			return;
		}
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'user_admin_notices' );
	}
```

(`self::PAGE_SLUG` is `'woi-pdf-visual'`, defined in slice 1.)

- [ ] **Step 4: Run test to verify it passes**

Run: `php -d display_errors=1 -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit tests/Unit/Visual/VisualEditorNoticesTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Run full suite + commit**

Run: `php -d display_errors=1 -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit`
Expected: 182 tests / 0 errors / 1 skipped (177 + 5).

```bash
git add includes/Visual/VisualEditorPage.php tests/Unit/Visual/VisualEditorNoticesTest.php
git commit -m "feat: suppress third-party admin notices on the visual editor screen"
```

---

### Task 2: Hide the device switcher + editor CSS file (#3)

**Files:**
- Create: `assets/visual-editor/editor.css`
- Modify: `assets/visual-editor/app.js` (grapesjs.init devices)
- Modify: `includes/Visual/VisualEditorPage.php` (enqueue editor.css)

**Interfaces:**
- Produces: enqueued `editor.css`; `grapesjs.init` with `deviceManager: { devices: [] }`.

- [ ] **Step 1: Create the editor CSS**

Create `assets/visual-editor/editor.css`:

```css
/* Hide the GrapesJS device switcher — an invoice is a fixed A4 print template. */
.gjs-pn-devices-c { display: none !important; }
```

- [ ] **Step 2: Enqueue it**

In `includes/Visual/VisualEditorPage.php` `enqueue()`, after the `wp_enqueue_script( 'woi-visual-editor', … )` line, add:

```php
        wp_enqueue_style( 'woi-visual-editor-css', $base . '/editor.css', array( 'woi-grapesjs' ), WOI_PDF_VERSION );
```

- [ ] **Step 3: Disable devices in init**

In `assets/visual-editor/app.js`, in the `grapesjs.init( { … } )` options object, add a `deviceManager` key:

```js
    var editor = grapesjs.init( {
        container: '#woi-visual-editor',
        height: '80vh',
        fromElement: false,
        storageManager: false,
        deviceManager: { devices: [] },
        components: woiVisual.stored || woiVisual.starter || ''
    } );
```

- [ ] **Step 4: Verify**

Run: `node --check assets/visual-editor/app.js` → clean.
Run: `php -l includes/Visual/VisualEditorPage.php` → clean.
Run full suite (unchanged): `php -d display_errors=1 -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit` → 182 / 0 / 1.
(Live: device switcher hidden, no toolbar overflow — controller's deferred step.)

- [ ] **Step 5: Commit**

```bash
git add assets/visual-editor/editor.css assets/visual-editor/app.js includes/Visual/VisualEditorPage.php
git commit -m "feat: hide device switcher; add editor.css"
```

---

### Task 3: Native editable tables (#2)

**Files:**
- Modify: `assets/visual-editor/app.js`
- Modify: `assets/visual-editor/editor.css`

**Interfaces:**
- Consumes: `editor` (GrapesJS instance), `editor.DomComponents`, `editor.Commands`.
- Produces: `td` cells that are text-editable + droppable + selectable; table toolbar commands `woi-add-row`, `woi-del-row`, `woi-add-col`, `woi-del-col`; the Layout "table" block creates an editable table.

- [ ] **Step 1: Register editable table component types**

In `assets/visual-editor/app.js`, AFTER `grapesjs.init(...)` and BEFORE the block registrations, add:

```js
    // --- Native editable tables (#2): make td editable + droppable, add row/col commands ---
    editor.DomComponents.addType( 'woi-cell', {
        isComponent: function ( el ) { return el.tagName === 'TD' || el.tagName === 'TH'; },
        model: { defaults: {
            tagName: 'td',
            draggable: 'tr',
            droppable: true,
            editable: true,
            highlightable: true,
            selectable: true
        } }
    } );
    editor.DomComponents.addType( 'woi-trow', {
        isComponent: function ( el ) { return el.tagName === 'TR'; },
        model: { defaults: { tagName: 'tr', draggable: false, droppable: 'td, th' } }
    } );
    editor.DomComponents.addType( 'woi-table', {
        isComponent: function ( el ) { return el.tagName === 'TABLE'; },
        model: { defaults: {
            tagName: 'table',
            droppable: false,
            toolbar: [
                { attributes: { class: 'fa fa-plus', title: 'Add row' },    command: 'woi-add-row' },
                { attributes: { class: 'fa fa-minus', title: 'Delete row' }, command: 'woi-del-row' },
                { attributes: { class: 'fa fa-plus-square-o', title: 'Add column' },  command: 'woi-add-col' },
                { attributes: { class: 'fa fa-minus-square-o', title: 'Delete column' }, command: 'woi-del-col' },
                { attributes: { class: 'fa fa-arrows', title: 'Move' }, command: 'tlb-move' },
                { attributes: { class: 'fa fa-trash-o', title: 'Delete' }, command: 'tlb-delete' }
            ]
        } }
    } );
```

- [ ] **Step 2: Add row/column commands**

Immediately after Step 1's block, add:

```js
    // Walk up to the nearest table component from any selection.
    function woiClosestTable( cmp ) {
        while ( cmp ) {
            if ( cmp.get && cmp.get( 'tagName' ) === 'table' ) { return cmp; }
            cmp = cmp.parent && cmp.parent();
        }
        return null;
    }
    function woiTableRows( table ) {
        return table.find( 'tr' );
    }
    editor.Commands.add( 'woi-add-row', { run: function ( ed ) {
        var table = woiClosestTable( ed.getSelected() );
        if ( ! table ) { return; }
        var rows = woiTableRows( table );
        if ( ! rows.length ) { return; }
        var cols = rows[ rows.length - 1 ].components().length || 1;
        var tds = '';
        for ( var i = 0; i < cols; i++ ) { tds += '<td>Cell</td>'; }
        rows[ rows.length - 1 ].parent().append( '<tr>' + tds + '</tr>' );
    } } );
    editor.Commands.add( 'woi-del-row', { run: function ( ed ) {
        var table = woiClosestTable( ed.getSelected() );
        if ( ! table ) { return; }
        var rows = woiTableRows( table );
        if ( rows.length > 1 ) { rows[ rows.length - 1 ].remove(); }
    } } );
    editor.Commands.add( 'woi-add-col', { run: function ( ed ) {
        var table = woiClosestTable( ed.getSelected() );
        if ( ! table ) { return; }
        woiTableRows( table ).forEach( function ( row ) { row.append( '<td>Cell</td>' ); } );
    } } );
    editor.Commands.add( 'woi-del-col', { run: function ( ed ) {
        var table = woiClosestTable( ed.getSelected() );
        if ( ! table ) { return; }
        woiTableRows( table ).forEach( function ( row ) {
            var cells = row.components();
            if ( cells.length > 1 ) { cells.at( cells.length - 1 ).remove(); }
        } );
    } } );
```

- [ ] **Step 3: Point the Layout "table" block at an editable table**

In `app.js`, replace the existing Layout `row-2col` block registration (from slice 2) with:

```js
    editor.BlockManager.add( 'row-2col', {
        label: 'Table (2 columns)', category: 'Layout',
        attributes: { title: 'Editable table — edit cells, add/remove rows & columns' },
        content: '<table class="woi-row"><tr><td>Cell</td><td>Cell</td></tr></table>'
    } );
```

- [ ] **Step 4: Add in-canvas table affordances CSS**

Append to `assets/visual-editor/editor.css`:

```css
/* Make empty table cells visible/clickable in the editor canvas. */
.gjs-frame .woi-row td, .gjs-frame .woi-row th { min-width: 24px; min-height: 1.4em; border: 1px dashed #c8c8c8; }
```

- [ ] **Step 5: Verify**

Run: `node --check assets/visual-editor/app.js` → clean.
Full suite unchanged: 182 / 0 / 1.
(Live: drop a Table block; double-click a cell to type; drop a `{{shop_name}}` token into a cell; select the table → toolbar add/remove row & column work; select a cell and change its background in the Style Manager.)

- [ ] **Step 6: Commit**

```bash
git add assets/visual-editor/app.js assets/visual-editor/editor.css
git commit -m "feat: native editable tables (editable/droppable cells, row/column controls)"
```

---

### Task 4: Preview pane shell — markup, layout, toggle, tabs (#4/#5)

**Files:**
- Modify: `includes/Visual/VisualEditorPage.php` (render_page markup)
- Modify: `assets/visual-editor/editor.css` (pane layout)
- Modify: `assets/visual-editor/app.js` (toggle button + tab switching)

**Interfaces:**
- Produces: a flex row wrapping the editor + `#woi-preview-pane` (hidden by default) with two tab buttons (`data-woi-tab="html"|"pdf"`), `<iframe id="woi-preview-html">`, a `<div id="woi-preview-pdf">` host with `<iframe id="woi-preview-pdf-frame">` + a `#woi-render-pdf` button; a toolbar toggle button (id `woi-preview-toggle`); JS functions `woiSetPaneOpen(bool)`, `woiSetTab('html'|'pdf')`.

- [ ] **Step 1: Render the pane markup**

In `includes/Visual/VisualEditorPage.php` `render_page()`, REPLACE the line `echo '<div id="woi-visual-editor"></div></div>';` with:

```php
		echo '<div class="woi-editor-row">';
		echo '<div id="woi-visual-editor"></div>';
		echo '<div id="woi-preview-pane" hidden>';
		echo '<div class="woi-preview-tabs">';
		echo '<button type="button" class="button woi-preview-tab is-active" data-woi-tab="html">' . esc_html__( 'Live HTML', 'woocommerce-orders-invoice-pdf' ) . '</button>';
		echo '<button type="button" class="button woi-preview-tab" data-woi-tab="pdf">' . esc_html__( 'PDF', 'woocommerce-orders-invoice-pdf' ) . '</button>';
		echo '</div>';
		echo '<iframe id="woi-preview-html" title="' . esc_attr__( 'Live preview', 'woocommerce-orders-invoice-pdf' ) . '"></iframe>';
		echo '<div id="woi-preview-pdf" hidden>';
		echo '<p><button type="button" class="button button-primary" id="woi-render-pdf">' . esc_html__( 'Render PDF', 'woocommerce-orders-invoice-pdf' ) . '</button> <span id="woi-render-pdf-status"></span></p>';
		echo '<iframe id="woi-preview-pdf-frame" title="' . esc_attr__( 'PDF preview', 'woocommerce-orders-invoice-pdf' ) . '"></iframe>';
		echo '</div>'; // #woi-preview-pdf
		echo '</div>'; // #woi-preview-pane
		echo '</div>'; // .woi-editor-row
		echo '</div>'; // .wrap
```

- [ ] **Step 2: Pane layout CSS**

Append to `assets/visual-editor/editor.css`:

```css
.woi-editor-row { display: flex; align-items: stretch; gap: 8px; }
#woi-visual-editor { flex: 1 1 auto; min-width: 0; }
#woi-preview-pane { flex: 0 0 42%; display: flex; flex-direction: column; border: 1px solid #c3c4c7; background: #fff; min-width: 0; }
#woi-preview-pane[hidden] { display: none; }
.woi-preview-tabs { display: flex; gap: 4px; padding: 6px; border-bottom: 1px solid #e2e4e7; }
.woi-preview-tab.is-active { background: #2271b1; color: #fff; border-color: #2271b1; }
#woi-preview-html, #woi-preview-pdf-frame { width: 100%; border: 0; background: #fff; }
#woi-preview-html { flex: 1 1 auto; min-height: 70vh; }
#woi-preview-pdf { flex: 1 1 auto; display: flex; flex-direction: column; padding: 6px; }
#woi-preview-pdf[hidden] { display: none; }
#woi-preview-pdf-frame { flex: 1 1 auto; min-height: 65vh; }
```

- [ ] **Step 3: Toggle button + tab switching (app.js)**

In `app.js`, after the editor/blocks/commands setup and BEFORE the final `}() );`, add:

```js
    // --- Preview pane (#4/#5): toggle + tab switching ---
    function woiSetPaneOpen( open ) {
        var pane = document.getElementById( 'woi-preview-pane' );
        if ( ! pane ) { return; }
        if ( open ) { pane.removeAttribute( 'hidden' ); } else { pane.setAttribute( 'hidden', '' ); }
    }
    function woiPaneOpen() {
        var pane = document.getElementById( 'woi-preview-pane' );
        return pane && ! pane.hasAttribute( 'hidden' );
    }
    function woiSetTab( tab ) {
        var html = document.getElementById( 'woi-preview-html' );
        var pdf  = document.getElementById( 'woi-preview-pdf' );
        Array.prototype.forEach.call( document.querySelectorAll( '.woi-preview-tab' ), function ( b ) {
            b.classList.toggle( 'is-active', b.getAttribute( 'data-woi-tab' ) === tab );
        } );
        if ( 'pdf' === tab ) { if ( html ) html.style.display = 'none'; if ( pdf ) pdf.removeAttribute( 'hidden' ); }
        else { if ( html ) html.style.display = ''; if ( pdf ) pdf.setAttribute( 'hidden', '' ); }
    }

    editor.Panels.addButton( 'options', {
        id: 'woi-preview-toggle',
        className: 'fa fa-columns',
        attributes: { title: 'Toggle preview pane' },
        command: function () {
            var open = ! woiPaneOpen();
            woiSetPaneOpen( open );
            if ( open && typeof woiRefreshLiveHtml === 'function' ) { woiRefreshLiveHtml(); }
        }
    } );

    Array.prototype.forEach.call( document.querySelectorAll( '.woi-preview-tab' ), function ( b ) {
        b.addEventListener( 'click', function () {
            var tab = b.getAttribute( 'data-woi-tab' );
            woiSetTab( tab );
            if ( 'html' === tab && typeof woiRefreshLiveHtml === 'function' ) { woiRefreshLiveHtml(); }
        } );
    } );
```

(`woiRefreshLiveHtml` is defined in Task 5; the `typeof` guard keeps Task 4 self-contained.)

- [ ] **Step 4: Verify**

`node --check assets/visual-editor/app.js` → clean. `php -l includes/Visual/VisualEditorPage.php` → clean. Full suite 182 / 0 / 1.
(Live: a "columns" toolbar button toggles a right-side pane; the Live HTML / PDF tabs switch which sub-view shows.)

- [ ] **Step 5: Commit**

```bash
git add includes/Visual/VisualEditorPage.php assets/visual-editor/editor.css assets/visual-editor/app.js
git commit -m "feat: preview pane shell (toggle, tabs, layout)"
```

---

### Task 5: Live HTML preview tab (#4/#5)

**Files:**
- Modify: `assets/visual-editor/app.js`

**Interfaces:**
- Consumes: `getHtml()`, `woiVisual.previewDataUrl/nonce/docType/sampleData`, the order bar (`#woi-order-search` etc. from slice 2), `editor.on`.
- Produces: `currentOrderTokens` (cached map), `woiFetchOrderTokens(orderId)`, `woiRefreshLiveHtml()`, `woiWrapForPreview(html)`, debounced `editor.on('update', …)`; order-select wiring that refreshes the pane.

- [ ] **Step 1: Add the live-HTML engine**

In `app.js`, after the Task 4 pane code, add:

```js
    // --- Live HTML preview engine (#5) ---
    var currentOrderTokens = null; // cached token map for the selected order
    var PREVIEW_CSS =
        'body{font-family:dejavusans,sans-serif;font-size:11pt;color:#222;padding:8mm}' +
        'table{border-collapse:collapse;width:100%}' +
        '.order-details th,.order-details td{border:0.5pt solid #000;padding:2px 4px}' +
        '.totals-table td.price{text-align:right}.totals-table th.description{text-align:inherit}' +
        '.woi-lbl-secondary{display:block;direction:rtl}' +
        '.woi-doc-title{text-align:center;margin:4mm 0}.woi-doc-title .title-en,.woi-doc-title .title-ar{font-size:16pt;font-weight:bold}.woi-doc-title .title-ar{margin-left:6mm}' +
        '.woi-pagebreak{border-top:1px dashed #999;margin:4mm 0}.woi-row td{vertical-align:top}' +
        '[dir="rtl"],.woi-bilingual-secondary{direction:rtl}';

    function woiDebounce( fn, ms ) {
        var t; return function () { clearTimeout( t ); t = setTimeout( fn, ms ); };
    }
    function woiMergeTokens( html, tokens ) {
        var out = html;
        if ( tokens ) {
            Object.keys( tokens ).forEach( function ( k ) { out = out.split( k ).join( tokens[ k ] ); } );
        }
        return out.replace( /\{\{\s*[a-z0-9_]+\s*\}\}/gi, '' );
    }
    function woiWrapForPreview( bodyHtml ) {
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>' + PREVIEW_CSS + '</style></head><body>' + bodyHtml + '</body></html>';
    }
    function woiRefreshLiveHtml() {
        var frame = document.getElementById( 'woi-preview-html' );
        if ( ! frame || ! woiPaneOpen() ) { return; }
        var tokens = currentOrderTokens || woiVisual.sampleData;
        frame.srcdoc = woiWrapForPreview( woiMergeTokens( getHtml(), tokens ) );
    }

    // Fetch + cache an order's token map; falls back silently to sample data.
    function woiFetchOrderTokens( orderId ) {
        var url = woiVisual.previewDataUrl + '?doc_type=' + encodeURIComponent( woiVisual.docType );
        if ( orderId ) { url += '&order_id=' + encodeURIComponent( orderId ); }
        return fetch( url, { headers: { 'X-WP-Nonce': woiVisual.nonce }, credentials: 'same-origin' } )
            .then( function ( r ) { return r.ok ? r.json() : null; } )
            .then( function ( res ) {
                if ( res && res.tokens ) {
                    currentOrderTokens = res.tokens;
                    var cur = document.getElementById( 'woi-order-current' );
                    if ( cur && res.order_label ) { cur.textContent = 'Order: ' + res.order_label; }
                }
                return res;
            } )
            .catch( function () { return null; } );
    }

    // Re-render live preview on edits (debounced) and once on init for the last order.
    editor.on( 'update', woiDebounce( woiRefreshLiveHtml, 400 ) );
    woiFetchOrderTokens( null ).then( function () { woiRefreshLiveHtml(); } );
```

- [ ] **Step 2: Refresh on order select (replace the slice-2 order-bar wiring)**

In `app.js`, the slice-2 `bindOrderBar` IIFE wired `#woi-order-search-btn` to `orderSearch` and `#woi-preview-real-order` to `previewRealOrder`, and the results `<select>` change to `setCurrentOrder`. REPLACE the results-select `change` handler and the `orderSearch` success path so that picking an order calls `woiFetchOrderTokens(id)` then `woiRefreshLiveHtml()`. Concretely, change the `<select>` change handler in `bindOrderBar` to:

```js
        if ( sel ) { sel.addEventListener( 'change', function () {
            woiFetchOrderTokens( sel.value ).then( function () { woiRefreshLiveHtml(); woiMaybeRefreshPdf(); } );
        } ); }
```

and in `orderSearch`'s success branch, after `setCurrentOrder( sel.value, … )`, add:

```js
            woiFetchOrderTokens( sel.value ).then( function () { woiRefreshLiveHtml(); woiMaybeRefreshPdf(); } );
```

(`woiMaybeRefreshPdf` is defined in Task 6; guard its call with `if ( typeof woiMaybeRefreshPdf === 'function' )`.)

- [ ] **Step 3: Verify**

`node --check assets/visual-editor/app.js` → clean. Full suite 182 / 0 / 1.
(Live: open the pane → Live HTML shows the last order's data merged into the design; edit a block → preview updates within ~0.4s; Find an order + select it → preview re-renders with that order; with no order, sample data is used.)

- [ ] **Step 4: Commit**

```bash
git add assets/visual-editor/app.js
git commit -m "feat: live HTML preview tab with order-token substitution"
```

---

### Task 6: PDF tab + remove old new-tab buttons (#4/#5)

**Files:**
- Modify: `assets/visual-editor/app.js`

**Interfaces:**
- Consumes: `save()`, `woiVisual.ajaxUrl/previewNonce/docType`, `currentOrderTokens`/selected order id, the `#woi-render-pdf` button + `#woi-preview-pdf-frame`.
- Produces: `woiRenderPdf()`, `woiMaybeRefreshPdf()`; removal of the three slice-1/2 new-tab buttons + their helper functions.

- [ ] **Step 1: Add the PDF render engine**

In `app.js`, after the Task 5 code, add:

```js
    // --- PDF preview tab (#5): save current design, render real mPDF, embed in-place ---
    var woiSelectedOrderId = null;          // set by the order bar / select
    var woiPdfBlobUrl = null;

    function woiPdfTabActive() {
        var pdf = document.getElementById( 'woi-preview-pdf' );
        return pdf && ! pdf.hasAttribute( 'hidden' );
    }
    function woiRenderPdf() {
        var status = document.getElementById( 'woi-render-pdf-status' );
        var frame  = document.getElementById( 'woi-preview-pdf-frame' );
        if ( ! frame ) { return; }
        if ( status ) { status.textContent = 'Rendering…'; }
        save().then( function () {
            var body = 'action=woi_pdf_preview' +
                '&security=' + encodeURIComponent( woiVisual.previewNonce ) +
                '&document_type=' + encodeURIComponent( woiVisual.docType );
            if ( woiSelectedOrderId ) { body += '&order_id=' + encodeURIComponent( woiSelectedOrderId ); }
            return fetch( woiVisual.ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                credentials: 'same-origin',
                body: body
            } );
        } ).then( function ( r ) { if ( ! r.ok ) { throw new Error( 'HTTP ' + r.status ); } return r.json(); } )
        .then( function ( res ) {
            if ( ! res.success || ! res.data || ! res.data.preview_data || res.data.output_format !== 'pdf' ) {
                throw new Error( ( res.data && res.data.error ) ? res.data.error : 'Preview failed.' );
            }
            var binary = window.atob( res.data.preview_data );
            var bytes  = new Uint8Array( binary.length );
            for ( var i = 0; i < binary.length; i++ ) { bytes[ i ] = binary.charCodeAt( i ); }
            if ( woiPdfBlobUrl ) { URL.revokeObjectURL( woiPdfBlobUrl ); }
            woiPdfBlobUrl = URL.createObjectURL( new Blob( [ bytes ], { type: 'application/pdf' } ) );
            frame.src = woiPdfBlobUrl;
            if ( status ) { status.textContent = ''; }
        } ).catch( function ( e ) {
            if ( status ) { status.textContent = 'Error: ' + ( e && e.message ? e.message : e ); }
        } );
    }
    // Re-render the PDF only when its tab is active (avoid a save+round-trip on every edit).
    function woiMaybeRefreshPdf() { if ( woiPaneOpen() && woiPdfTabActive() ) { woiRenderPdf(); } }

    ( function bindPdfTab() {
        var btn = document.getElementById( 'woi-render-pdf' );
        if ( btn ) { btn.addEventListener( 'click', woiRenderPdf ); }
    }() );
    window.addEventListener( 'beforeunload', function () { if ( woiPdfBlobUrl ) { URL.revokeObjectURL( woiPdfBlobUrl ); } } );
```

- [ ] **Step 2: Track the selected order id for the PDF**

In `app.js`, the slice-2 `setCurrentOrder( id, label )` function sets `selectedOrderId`. Add a line inside it so the PDF engine sees the same id:

```js
    function setCurrentOrder( id, label ) {
        selectedOrderId = id;
        woiSelectedOrderId = id;            // <-- add this line
        var el = document.getElementById( 'woi-order-current' );
        if ( el ) { el.textContent = label ? ( 'Selected: ' + label ) : ''; }
    }
```

- [ ] **Step 3: Remove the three new-tab preview buttons + helpers**

In `app.js`, DELETE these slice-1/2 additions (now consolidated into the pane):
- the `editor.Panels.addButton( 'options', { id: 'woi-preview-pdf', … } )` block and its long comment;
- the `editor.Panels.addButton( 'options', { id: 'woi-preview-sample', … } )` block and its comment;
- the `previewRealOrder` function (the new-tab Blob version) and the `#woi-preview-real-order` button's binding in `bindOrderBar` (remove the `previewBtn` lookup + its `addEventListener`);
- the now-unused `mergeSample` function IF nothing else references it (the live pane uses `woiMergeTokens`); keep `getHtml` and `save`.

Also, in `includes/Visual/VisualEditorPage.php` `render_page()`, REMOVE the order-bar `#woi-preview-real-order` button line (the pane toggle + auto-refresh replace it); keep the search input, Find button, results select, and `#woi-order-current` readout.

- [ ] **Step 4: Verify**

`node --check assets/visual-editor/app.js` → clean. `php -l includes/Visual/VisualEditorPage.php` → clean. Full suite 182 / 0 / 1.
Grep to confirm removals: `grep -n "woi-preview-pdf'\|woi-preview-sample'\|previewRealOrder\|woi-preview-real-order" assets/visual-editor/app.js includes/Visual/VisualEditorPage.php` → only the new pane ids remain (no `woi-preview-pdf`/`woi-preview-sample` panel buttons; no `previewRealOrder`).
(Live: PDF tab → Render PDF embeds the real mPDF PDF in the pane; selecting an order with the PDF tab active re-renders; no new browser tabs open from the editor anymore.)

- [ ] **Step 5: Commit**

```bash
git add assets/visual-editor/app.js includes/Visual/VisualEditorPage.php
git commit -m "feat: in-place PDF preview tab; remove new-tab preview buttons"
```

---

### Task 7: Version bump + verification

**Files:**
- Modify: `woocommerce-orders-invoice-pdf.php`

- [ ] **Step 1: Bump the version**

Bump BOTH the header `Version:` and the `$version` property from `1.4.11` to `1.4.12` (they must match).

- [ ] **Step 2: Run all checks**

Run: `php -d display_errors=1 -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit` → 182 / 0 / 1.
Run: `node --check assets/visual-editor/app.js` → clean.
Run: `php -l includes/Visual/VisualEditorPage.php` → clean.

- [ ] **Step 3: Full live acceptance (controller, after merge+pull)**

Confirm on the deployed site (Status tab shows the new revision first): notices gone; device switcher hidden; table editing (text, drop token, add/remove row & column, cell styling); preview pane toggles; Live HTML updates on edit + order select; PDF tab embeds the real mPDF PDF in-place; existing save/render unaffected; stored design saved+restored around tests.

- [ ] **Step 4: Commit**

```bash
git add woocommerce-orders-invoice-pdf.php
git commit -m "chore: bump version for visual editor UX improvements"
```

---

## Self-Review

**Spec coverage:**
- #1 suppress notices (screen-gated) → Task 1. ✓
- #3 hide device switcher → Task 2. ✓
- #2 editable tables (text/drop/rows-cols/cell-style) → Task 3 (native config; deviation from plugin-first surfaced at top + handoff). ✓
- #4/#5 toggleable right-side pane, Live HTML (auto-update on edit + order select), PDF tab (embed real mPDF in-place), auto-refresh on order select, consolidation of the three new-tab buttons → Tasks 4, 5, 6. ✓
- No new endpoints (reuse visual-preview-data + woi_pdf_preview) → Tasks 5, 6. ✓
- Version bump → Task 7. ✓

**Placeholder scan:** No TBD/vague steps; every code step carries complete code. JS tasks use `node --check` + live verification (explicitly stated; no in-repo JS harness). The #2 native-config deviation is stated openly, not hidden.

**Type consistency:** `is_visual_editor_screen()`/`suppress_admin_notices()` consistent (Task 1 + test). JS names consistent across tasks: `woiRefreshLiveHtml`, `woiFetchOrderTokens`, `woiMergeTokens`, `woiWrapForPreview`, `currentOrderTokens`, `woiSetPaneOpen`/`woiPaneOpen`/`woiSetTab`, `woiRenderPdf`/`woiMaybeRefreshPdf`/`woiPdfTabActive`, `woiSelectedOrderId`, and the slice-2 `setCurrentOrder`/`selectedOrderId`/`bindOrderBar` referenced for modification. DOM ids consistent between the PHP markup (Task 4) and the JS (`#woi-preview-pane`, `#woi-preview-html`, `#woi-preview-pdf`, `#woi-preview-pdf-frame`, `#woi-render-pdf`, `#woi-render-pdf-status`, `.woi-preview-tab[data-woi-tab]`). CSS classes consistent between editor.css and the markup. ✓

## Out of scope (later slices)

Other document types; persisting selected order / pane state; pdf.js rendering; undo/redo or template versioning.
