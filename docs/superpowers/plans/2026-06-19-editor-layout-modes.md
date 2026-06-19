# Visual Editor Layout Modes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the visual invoice editor three selectable layout modes (full-viewport default, vertical stack, right overlay) via a persisted switcher.

**Architecture:** Wrap the order-bar + editor-row in a `#woi-editor-shell` carrying a `data-layout` attribute. A new `#woi-editor-toolbar` holds a back link, title, and a segmented layout switcher. All three layouts are pure CSS keyed off `#woi-editor-shell[data-layout="…"]`; one JS function `woiApplyLayout(mode)` sets the attribute, toggles a `body.woi-fullscreen` class, persists to `localStorage`, docks the preview pane appropriately, and calls `editor.refresh()`. The existing preview pane, its GrapesJS toggle button, and Live/PDF tabs are reused unchanged.

**Tech Stack:** PHP (WordPress admin render), vanilla ES5-style JS, CSS. GrapesJS 0.21.13 (vendored). No bundler — assets served directly.

## Global Constraints

- No new dependencies; vanilla JS/CSS only, matching the existing `app.js`/`editor.css` style.
- Bump `WOI_PDF_VERSION` (plugin header `Version:` and `$version` property) once for this feature so browsers do not serve stale `app.js`/`editor.css`.
- Default layout is `full`. Valid modes: `full`, `stack`, `overlay`. Invalid/missing persisted value falls back to `full`.
- Persistence is browser `localStorage` only, key `woiEditorLayout`. No server-side storage.
- Reuse the existing `#woi-preview-pane` markup, the GrapesJS `woi-preview-toggle` button, and `.woi-preview-tab` tabs — do not duplicate or rebuild them.
- In `full`, preview is docked-open by default; in `stack`, docked-open by default; in `overlay`, closed until toggled.
- Do not add `overflow` clipping to `#woi-preview-pane` (none exists today). A past bug clipped the preview's document-switcher dropdown when the pane scrolled; keep the pane non-clipping and let the inner iframes scroll.
- No automated browser tests exist and synthetic input events are unreliable for this GrapesJS UI; per-task verification is PHP/JS lint plus a manual checklist. Final confirmation is a live check after deploy.

---

### Task 1: Shell markup, toolbar, and layout switcher (PHP) + version bump

**Files:**
- Modify: `includes/Visual/VisualEditorPage.php` (method `render_page`, currently lines 94-120)
- Modify: `woocommerce-orders-invoice-pdf.php` (line 6 header `Version:`, line 24 `$version`)

**Interfaces:**
- Produces (consumed by Tasks 2 & 3):
  - `#woi-editor-shell` element with attribute `data-layout="full"`, wrapping the order-bar and `.woi-editor-row`.
  - `#woi-editor-toolbar` as the shell's first child.
  - Layout switch buttons: `button.woi-layout-btn[data-woi-layout="full|stack|overlay"]`.
  - Unchanged ids/classes still present: `#woi-visual-editor`, `#woi-preview-pane` (with `hidden`), `.woi-preview-tab[data-woi-tab]`, `#woi-order-search`, etc.

- [ ] **Step 1: Replace the `render_page` body with the shell + toolbar markup**

In `includes/Visual/VisualEditorPage.php`, replace the entire current `render_page()` method body (lines 94-120) with:

```php
    public function render_page(): void {
        echo '<div class="wrap"><h1>' . esc_html__( 'Visual Invoice Template', 'woocommerce-orders-invoice-pdf' ) . '</h1>';
        echo '<p>' . esc_html__( 'Design with table/block layout for best mPDF fidelity. Use the PDF tab in the preview pane to verify Arabic rendering and pagination. Note: PDF preview reflects the saved design and only renders the visual template when "Visual template (invoice)" is enabled in Invoice Settings.', 'woocommerce-orders-invoice-pdf' ) . '</p>';

        echo '<div id="woi-editor-shell" data-layout="full">';

        // Toolbar: back link, title, layout switcher.
        echo '<div id="woi-editor-toolbar">';
        echo '<a class="woi-tb-back" href="' . esc_url( admin_url( 'admin.php?page=woi_pdf_options_page' ) ) . '">&larr; ' . esc_html__( 'PDF Invoices', 'woocommerce-orders-invoice-pdf' ) . '</a>';
        echo '<span class="woi-tb-title">' . esc_html__( 'Visual Invoice Template', 'woocommerce-orders-invoice-pdf' ) . '</span>';
        echo '<div class="woi-layout-switch" role="group" aria-label="' . esc_attr__( 'Editor layout', 'woocommerce-orders-invoice-pdf' ) . '">';
        echo '<button type="button" class="button woi-layout-btn" data-woi-layout="full">' . esc_html__( 'Full screen', 'woocommerce-orders-invoice-pdf' ) . '</button>';
        echo '<button type="button" class="button woi-layout-btn" data-woi-layout="stack">' . esc_html__( 'Split below', 'woocommerce-orders-invoice-pdf' ) . '</button>';
        echo '<button type="button" class="button woi-layout-btn" data-woi-layout="overlay">' . esc_html__( 'Overlay', 'woocommerce-orders-invoice-pdf' ) . '</button>';
        echo '</div>'; // .woi-layout-switch
        echo '</div>'; // #woi-editor-toolbar

        // Order bar.
        echo '<div class="woi-order-bar" style="margin:8px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
        echo '<label for="woi-order-search"><strong>' . esc_html__( 'Preview order:', 'woocommerce-orders-invoice-pdf' ) . '</strong></label>';
        echo '<input type="text" id="woi-order-search" class="regular-text" placeholder="' . esc_attr__( 'Order #, email or name (blank = last order)', 'woocommerce-orders-invoice-pdf' ) . '" style="max-width:280px">';
        echo '<button type="button" class="button" id="woi-order-search-btn">' . esc_html__( 'Find', 'woocommerce-orders-invoice-pdf' ) . '</button>';
        echo '<select id="woi-order-results" style="display:none;max-width:320px"></select>';
        echo '<span id="woi-order-current" style="color:#555"></span>';
        echo '</div>';

        // Editor + preview row.
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

        echo '</div>'; // #woi-editor-shell
        echo '</div>'; // .wrap
    }
```

Note vs. the old markup: the standalone "← Back to PDF Invoices" `<p>` is removed (the toolbar back link replaces it); the `<h1>` and intro `<p>` stay outside the shell (visible in `stack`/`overlay`, covered by the fixed shell in `full`). The order-bar and `.woi-editor-row` are unchanged except for now living inside `#woi-editor-shell`.

- [ ] **Step 2: Bump the version constant**

In `woocommerce-orders-invoice-pdf.php` line 6, change `* Version:              1.4.17` to `* Version:              1.4.18`.
In `woocommerce-orders-invoice-pdf.php` line 24, change `public string $version     = '1.4.17';` to `public string $version     = '1.4.18';`.

- [ ] **Step 3: Lint the PHP**

Run: `php -l includes/Visual/VisualEditorPage.php && php -l woocommerce-orders-invoice-pdf.php`
Expected: `No syntax errors detected` for both files.

- [ ] **Step 4: Manual structure check**

Confirm by reading the edited `render_page` that the output nesting is exactly:
`.wrap > (h1, p, #woi-editor-shell[data-layout="full"]) ` and
`#woi-editor-shell > (#woi-editor-toolbar, .woi-order-bar, .woi-editor-row)` and
`#woi-editor-toolbar > (a.woi-tb-back, span.woi-tb-title, .woi-layout-switch > 3×button.woi-layout-btn)`.
Confirm the three switch buttons carry `data-woi-layout` values `full`, `stack`, `overlay` in that order.

- [ ] **Step 5: Commit**

```bash
git add includes/Visual/VisualEditorPage.php woocommerce-orders-invoice-pdf.php
git commit -m "feat: editor shell + layout switcher markup (v1.4.18)"
```

---

### Task 2: Layout CSS for the three modes

**Files:**
- Modify: `assets/visual-editor/editor.css` (replace the "Preview pane layout (#4)" block at lines 7-18; bump `#woi-insert-menu` z-index at line 21)

**Interfaces:**
- Consumes (from Task 1): `#woi-editor-shell[data-layout]`, `#woi-editor-toolbar`, `.woi-layout-switch`, `.woi-layout-btn`, `.woi-editor-row`, `#woi-visual-editor`, `#woi-preview-pane`.
- Consumes (from Task 3): `body.woi-fullscreen` toggled by JS; `.woi-layout-btn.is-active` set by JS.
- Produces: the visual layouts. No symbols consumed by later tasks.

- [ ] **Step 1: Replace the preview-pane layout block with shell + per-mode rules**

In `assets/visual-editor/editor.css`, replace lines 7-18 (the block beginning `/* --- Preview pane layout (#4) --- */` through the `#woi-preview-pdf-frame { … }` rule) with:

```css
/* === Editor shell + layout modes (full / stack / overlay) === */
#woi-editor-shell { display: flex; flex-direction: column; gap: 8px; }

#woi-editor-toolbar {
    flex: 0 0 auto; display: flex; align-items: center; gap: 10px;
    padding: 6px 10px; background: #fff; border: 1px solid #c3c4c7; border-radius: 3px;
}
.woi-tb-title { font-weight: 600; }
.woi-tb-back { text-decoration: none; }
.woi-layout-switch { margin-left: auto; display: inline-flex; }
.woi-layout-switch .woi-layout-btn { border-radius: 0; margin: 0 0 0 -1px; }
.woi-layout-switch .woi-layout-btn:first-child { border-radius: 3px 0 0 3px; margin-left: 0; }
.woi-layout-switch .woi-layout-btn:last-child { border-radius: 0 3px 3px 0; }
.woi-layout-btn.is-active { background: #2271b1; color: #fff; border-color: #2271b1; }

/* Base editor row: side-by-side (default; also used by overlay since the pane is taken out of flow). */
.woi-editor-row { display: flex; align-items: stretch; gap: 8px; flex: 1 1 auto; min-height: 0; }
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

/* --- FULL: fixed viewport takeover (covers WP admin bar + menu) --- */
body.woi-fullscreen { overflow: hidden; }
#woi-editor-shell[data-layout="full"] {
    position: fixed; inset: 0; z-index: 100000; margin: 0; padding: 8px;
    background: #f0f0f1; overflow: hidden;
}
#woi-editor-shell[data-layout="full"] .woi-editor-row { flex: 1 1 auto; min-height: 0; }
#woi-editor-shell[data-layout="full"] #woi-visual-editor { height: 100%; }
#woi-editor-shell[data-layout="full"] .gjs-editor { height: 100% !important; }
#woi-editor-shell[data-layout="full"] #woi-preview-pane { flex: 0 0 40%; }
#woi-editor-shell[data-layout="full"] #woi-preview-html,
#woi-editor-shell[data-layout="full"] #woi-preview-pdf-frame { min-height: 0; }

/* --- STACK: preview docked below the editor --- */
#woi-editor-shell[data-layout="stack"] .woi-editor-row { flex-direction: column; }
#woi-editor-shell[data-layout="stack"] #woi-preview-pane { flex: 0 0 auto; height: 40vh; }
#woi-editor-shell[data-layout="stack"] #woi-preview-html,
#woi-editor-shell[data-layout="stack"] #woi-preview-pdf-frame { min-height: 0; }
#woi-editor-shell[data-layout="stack"] .gjs-editor { height: 60vh !important; }

/* --- OVERLAY: preview floats over the right edge, on demand --- */
#woi-editor-shell[data-layout="overlay"] #woi-preview-pane {
    position: fixed; top: var(--wp-admin--admin-bar--height, 32px); right: 0; bottom: 0;
    width: 40%; max-width: 640px; z-index: 99980; box-shadow: -8px 0 24px rgba(0,0,0,.18);
}
#woi-editor-shell[data-layout="overlay"] #woi-preview-html,
#woi-editor-shell[data-layout="overlay"] #woi-preview-pdf-frame { min-height: 0; }
```

- [ ] **Step 2: Bump the insert-menu z-index above the full-screen shell**

In `assets/visual-editor/editor.css` (the `#woi-insert-menu { … }` rule, was line 21), change `z-index: 100000;` to `z-index: 100001;` so the insert popup stays above the full-screen shell (z-index 100000).

- [ ] **Step 3: Manual layout verification (deploy-independent reasoning + live spot check)**

Because there is no CSS test harness, verify by reasoning against the rules and (if the live debug-Chrome harness is available) by temporarily setting the attribute in DevTools on the deployed page:
- `data-layout="full"`: `#woi-editor-shell` is `position:fixed; inset:0; z-index:100000`; `.gjs-editor` fills height; `#woi-preview-pane` is the right 40% column.
- `data-layout="stack"`: `.woi-editor-row` is `flex-direction:column`; preview is a 40vh strip below the editor.
- `data-layout="overlay"`: `#woi-preview-pane` is `position:fixed` on the right edge; when it has the `hidden` attribute it is `display:none` (existing rule).
Confirm the replacement block still contains `#woi-preview-pane[hidden] { display: none; }`, and that no `overflow` was added to `#woi-preview-pane`.

- [ ] **Step 4: Commit**

```bash
git add assets/visual-editor/editor.css
git commit -m "feat: CSS for full/stack/overlay editor layout modes"
```

---

### Task 3: Layout behavior (apply, persist, switch, init) in app.js

**Files:**
- Modify: `assets/visual-editor/app.js` (add a layout block near the end of the IIFE, after the preview-tab wiring at lines 581-587, before the Live HTML preview engine section at line 589)

**Interfaces:**
- Consumes (from Task 1): `#woi-editor-shell`, `.woi-layout-btn[data-woi-layout]`.
- Consumes (existing in app.js): the `editor` variable (from `grapesjs.init`, line 26); `woiSetPaneOpen(open)` (line 544); `woiRefreshLiveHtml` (called guarded by `typeof`).
- Consumes (from Task 2): `body.woi-fullscreen` class, `.woi-layout-btn.is-active` styling.
- Produces: `woiApplyLayout(mode)` and DOM wiring + init call. No symbols consumed by later tasks.

- [ ] **Step 1: Add the layout-mode block**

In `assets/visual-editor/app.js`, immediately after the preview-tab `forEach` wiring block that ends at line 587 (the block iterating `.woi-preview-tab`), and before the `// --- Live HTML preview engine (#5) ---` comment at line 589, insert:

```javascript
    // --- Editor layout modes (full / stack / overlay) ---
    var WOI_LAYOUTS = { full: 1, stack: 1, overlay: 1 };
    function woiApplyLayout( mode ) {
        if ( ! WOI_LAYOUTS[ mode ] ) { mode = 'full'; }
        var shell = document.getElementById( 'woi-editor-shell' );
        if ( ! shell ) { return; }
        shell.setAttribute( 'data-layout', mode );
        document.body.classList.toggle( 'woi-fullscreen', 'full' === mode );
        Array.prototype.forEach.call( document.querySelectorAll( '.woi-layout-btn' ), function ( b ) {
            b.classList.toggle( 'is-active', b.getAttribute( 'data-woi-layout' ) === mode );
        } );
        // Preview docking default: open for full/stack, closed for overlay until toggled.
        if ( 'overlay' === mode ) {
            woiSetPaneOpen( false );
        } else {
            woiSetPaneOpen( true );
            if ( typeof woiRefreshLiveHtml === 'function' ) { woiRefreshLiveHtml(); }
        }
        try { window.localStorage.setItem( 'woiEditorLayout', mode ); } catch ( e ) {}
        editor.refresh();
    }

    ( function woiInitLayout() {
        var saved = 'full';
        try { saved = window.localStorage.getItem( 'woiEditorLayout' ) || 'full'; } catch ( e ) {}
        woiApplyLayout( saved );
        Array.prototype.forEach.call( document.querySelectorAll( '.woi-layout-btn' ), function ( b ) {
            b.addEventListener( 'click', function () { woiApplyLayout( b.getAttribute( 'data-woi-layout' ) ); } );
        } );
    }() );
```

- [ ] **Step 2: Syntax-check the JS**

Run: `node --check assets/visual-editor/app.js`
Expected: no output (exit 0). If it prints a `SyntaxError`, fix before continuing.

- [ ] **Step 3: Manual behavior verification (reasoning + live if harness available)**

Verify against the code:
- `woiApplyLayout('bogus')` falls back to `full` (guard `if (!WOI_LAYOUTS[mode]) mode='full'`).
- On init with empty `localStorage`, `saved` defaults to `'full'`; `woiApplyLayout('full')` sets `data-layout="full"`, adds `body.woi-fullscreen`, opens the pane.
- Clicking each `.woi-layout-btn` calls `woiApplyLayout` with its `data-woi-layout` and persists it.
- Entering `overlay` closes the pane; entering `full`/`stack` opens it.
- `editor.refresh()` runs on every apply.
If the live debug-Chrome harness is up against the deployed build, additionally confirm: default load is full-viewport with preview docked-open; switching modes changes the layout and the canvas resizes (non-zero); reload preserves the last mode; in full the page behind does not scroll; switching away from full restores scroll.

- [ ] **Step 4: Commit**

```bash
git add assets/visual-editor/app.js
git commit -m "feat: layout-mode apply/persist/switch behavior in editor"
```

---

## Notes for the executor

- This is a front-end (PHP markup + CSS + JS) feature with no automated test suite; the "verification" steps are lint + structured manual checks. The authoritative confirmation is a live check after the branch is merged and pulled onto the server (debug-Chrome harness at port 9222, `defaultViewport: null` to honor the real DPR 1.25). Do not claim the layouts are visually confirmed unless a live check was actually run.
- Keep the version bump (Task 1) as the single bump for the whole feature; do not bump again in Tasks 2/3.
- Do not alter the GrapesJS `height: '80vh'` init option; the CSS overrides `.gjs-editor` height per mode instead.
