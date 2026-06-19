# Real-time A4 PDF.js Preview Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the visual editor's PDF-tab native-viewer `<iframe>` with a PDF.js canvas render of all pages, framed as ISO A4 sheets, auto-refreshing ~1s (debounced) after edits.

**Architecture:** The server-side mPDF pipeline (`woi_pdf_preview` AJAX) is unchanged — it still returns a base64 PDF. The change is client-side: decode the bytes, render every page with the already-vendored PDF.js (`globalThis.pdfjsLib`) into `<canvas>` "sheets" inside an A4-proportioned scroll stage, with a generation guard so debounced bursts don't interleave stale pages.

**Tech Stack:** PHP (WordPress admin enqueue + markup), vanilla JS (ES5 style, matching `app.js`), CSS, PDF.js (vendored at `assets/js/pdf_js/`).

## Global Constraints

- Plugin version: bump `1.4.18` → `1.4.19` in BOTH the plugin header (`woocommerce-orders-invoice-pdf.php:6`) and the class property (`woocommerce-orders-invoice-pdf.php:24`). `WOI_PDF_VERSION` derives from the class property and cache-busts all enqueued assets — this is REQUIRED so browsers don't serve stale `app.js`/`editor.css`/markup.
- JS style: match existing `app.js` — ES5 (`var`, `function`), 4-space indent, single-quoted strings with inner spacing `( ... )`, no new build step (file is served as-is).
- No new dependencies: PDF.js is already vendored at `assets/js/pdf_js/pdf.min.js` + `pdf.worker.min.js` and exposes `globalThis.pdfjsLib`.
- No automated JS test framework exists in this repo. Per-task verification is `node --check <file>` for JS syntax, `php -l <file>` for PHP, plus manual browser verification at `wp-admin → PDF Invoices → Visual Invoice Template` (live site: b2b.milanoleather.ae via the live-testing harness, or any WooCommerce dev site).
- Preserve these IDs/handles that JS depends on: `#woi-preview-pdf`, `#woi-render-pdf`, `#woi-render-pdf-status`.

## File Structure

- `woocommerce-orders-invoice-pdf.php` — version bump (header + class property).
- `includes/Visual/VisualEditorPage.php` — enqueue PDF.js, localize `pdfWorkerUrl`, replace PDF-tab iframe markup with A4 stage.
- `assets/visual-editor/editor.css` — A4 stage/sheet styles; update layout-mode rules that referenced the removed iframe.
- `assets/visual-editor/app.js` — rewrite `woiRenderPdf()` to multi-page canvas render with generation guard; wire debounced auto-refresh + render-on-tab-activate.

---

### Task 1: Enqueue PDF.js, localize worker URL, bump version

**Files:**
- Modify: `woocommerce-orders-invoice-pdf.php:6` and `:24` (version bump)
- Modify: `includes/Visual/VisualEditorPage.php:73-91` (enqueue + localize)

**Interfaces:**
- Produces: `woiVisual.pdfWorkerUrl` (string, absolute URL to `pdf.worker.min.js`) consumed by Task 4; the `woi-pdfjs` script handle loads `globalThis.pdfjsLib` before `app.js` runs.

- [ ] **Step 1: Bump the plugin version (header)**

In `woocommerce-orders-invoice-pdf.php`, change line 6:

```php
 * Version:              1.4.19
```

- [ ] **Step 2: Bump the class version property**

In `woocommerce-orders-invoice-pdf.php`, change line 24:

```php
	public string $version     = '1.4.19';
```

- [ ] **Step 3: Enqueue PDF.js as a dependency of the editor script**

In `includes/Visual/VisualEditorPage.php`, replace the enqueue block (lines 74-77):

```php
        wp_enqueue_style( 'woi-grapesjs', $base . '/grapesjs/grapes.min.css', array(), WOI_PDF_VERSION );
        wp_enqueue_script( 'woi-grapesjs', $base . '/grapesjs/grapes.min.js', array(), WOI_PDF_VERSION, true );
        wp_enqueue_script( 'woi-pdfjs', WOI_PDF()->plugin_url() . '/assets/js/pdf_js/pdf.min.js', array(), WOI_PDF_VERSION, true );
        wp_enqueue_script( 'woi-visual-editor', $base . '/app.js', array( 'woi-grapesjs', 'woi-pdfjs' ), WOI_PDF_VERSION, true );
        wp_enqueue_style( 'woi-visual-editor-css', $base . '/editor.css', array( 'woi-grapesjs' ), WOI_PDF_VERSION );
```

- [ ] **Step 4: Localize the worker URL**

In `includes/Visual/VisualEditorPage.php`, add one entry to the `wp_localize_script` array (after the `'orderSearchAction'` line, ~line 90):

```php
            'orderSearchAction' => 'woi_pdf_preview_order_search',
            'pdfWorkerUrl'      => esc_url_raw( WOI_PDF()->plugin_url() . '/assets/js/pdf_js/pdf.worker.min.js' ),
```

- [ ] **Step 5: Lint PHP**

Run: `php -l includes/Visual/VisualEditorPage.php && php -l woocommerce-orders-invoice-pdf.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 6: Manual verification**

Load `wp-admin → PDF Invoices → Visual Invoice Template`. Open browser DevTools → Network, filter `pdf`. Expected: `pdf.min.js` loads (200) with `?ver=1.4.19`. In Console, type `pdfjsLib` → expected: an object (not `undefined`). `woiVisual.pdfWorkerUrl` → expected: the absolute worker URL.

- [ ] **Step 7: Commit**

```bash
git add woocommerce-orders-invoice-pdf.php includes/Visual/VisualEditorPage.php
git commit -m "feat: enqueue PDF.js in visual editor + bump to 1.4.19"
```

---

### Task 2: Replace PDF-tab iframe markup with the A4 stage

**Files:**
- Modify: `includes/Visual/VisualEditorPage.php:129-132`

**Interfaces:**
- Consumes: nothing from prior tasks.
- Produces: DOM `#woi-pdf-stage` (the element Task 4's JS fills with `.woi-a4-page` canvases), wrapped in `.woi-a4-scroll`. Preserves `#woi-render-pdf`, `#woi-render-pdf-status`, `#woi-preview-pdf`.

- [ ] **Step 1: Replace the iframe with the scroll + stage structure**

In `includes/Visual/VisualEditorPage.php`, replace lines 129-132:

```php
        echo '<div id="woi-preview-pdf" hidden>';
        echo '<p><button type="button" class="button button-primary" id="woi-render-pdf">' . esc_html__( 'Render PDF', 'woocommerce-orders-invoice-pdf' ) . '</button> <span id="woi-render-pdf-status"></span></p>';
        echo '<div class="woi-a4-scroll"><div class="woi-a4-stage" id="woi-pdf-stage"></div></div>';
        echo '</div>'; // #woi-preview-pdf
```

(Removes the `<iframe id="woi-preview-pdf-frame">`; the `<p>` toolbar and the `#woi-preview-pdf` wrapper are kept.)

- [ ] **Step 2: Lint PHP**

Run: `php -l includes/Visual/VisualEditorPage.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Manual verification**

Reload the editor, click the **PDF** tab. Expected: an empty area with the Render PDF button above it (no broken iframe). In DevTools Elements, confirm `#woi-pdf-stage` exists inside `.woi-a4-scroll`. (It will be unstyled until Task 3.)

- [ ] **Step 4: Commit**

```bash
git add includes/Visual/VisualEditorPage.php
git commit -m "feat: A4 stage markup for PDF preview (replaces iframe)"
```

---

### Task 3: A4 sheet CSS + update layout-mode rules

**Files:**
- Modify: `assets/visual-editor/editor.css:29,33,44-45,50-51,59-60` (drop `#woi-preview-pdf-frame` references) and add new rules.

**Interfaces:**
- Consumes: `.woi-a4-scroll`, `.woi-a4-stage`, `.woi-a4-page` from Task 2 markup / Task 4 canvases.
- Produces: nothing consumed by later JS (purely presentational).

- [ ] **Step 1: Replace the iframe sizing rules with stage rules**

In `assets/visual-editor/editor.css`, replace lines 29-33:

```css
#woi-preview-html { width: 100%; border: 0; background: #fff; }
#woi-preview-html { flex: 1 1 auto; min-height: 70vh; }
#woi-preview-pdf { flex: 1 1 auto; display: flex; flex-direction: column; padding: 6px; min-height: 0; }
#woi-preview-pdf[hidden] { display: none; }
.woi-a4-scroll { flex: 1 1 auto; min-height: 65vh; overflow: auto; background: #525659; padding: 16px; display: flex; flex-direction: column; align-items: center; gap: 16px; }
.woi-a4-stage { width: min(100%, 820px); display: flex; flex-direction: column; align-items: stretch; gap: 16px; }
.woi-a4-page { width: 100%; height: auto; aspect-ratio: 210 / 297; background: #fff; box-shadow: 0 1px 6px rgba(0,0,0,.45); display: block; }
```

- [ ] **Step 2: Update the FULL layout override**

In `assets/visual-editor/editor.css`, replace lines 44-45:

```css
#woi-editor-shell[data-layout="full"] #woi-preview-html,
#woi-editor-shell[data-layout="full"] .woi-a4-scroll { min-height: 0; }
```

- [ ] **Step 3: Update the STACK layout override**

In `assets/visual-editor/editor.css`, replace lines 50-51:

```css
#woi-editor-shell[data-layout="stack"] #woi-preview-html,
#woi-editor-shell[data-layout="stack"] .woi-a4-scroll { min-height: 0; }
```

- [ ] **Step 4: Update the OVERLAY layout override**

In `assets/visual-editor/editor.css`, replace lines 59-60:

```css
#woi-editor-shell[data-layout="overlay"] #woi-preview-html,
#woi-editor-shell[data-layout="overlay"] .woi-a4-scroll { min-height: 0; }
```

- [ ] **Step 5: Manual verification**

Reload the editor, click the **PDF** tab. Expected: a gray (`#525659`) scroll area with the Render PDF button above. Search the file for the old selector to confirm none remain:

Run: `grep -n "woi-preview-pdf-frame" assets/visual-editor/editor.css`
Expected: no matches (empty output).

- [ ] **Step 6: Commit**

```bash
git add assets/visual-editor/editor.css
git commit -m "feat: A4 sheet styling for PDF preview stage"
```

---

### Task 4: PDF.js multi-page canvas render with generation guard

**Files:**
- Modify: `assets/visual-editor/app.js:676-723` (the PDF preview tab block)

**Interfaces:**
- Consumes: `woiVisual.pdfWorkerUrl` (Task 1); `#woi-pdf-stage`, `#woi-render-pdf`, `#woi-render-pdf-status` (Task 2); `globalThis.pdfjsLib` (Task 1). Reuses existing `save()`, `woiPaneOpen()` from `app.js`.
- Produces: `woiRenderPdf()` (renders current design as A4 canvases) and `woiMaybeRefreshPdf()` (renders only when pane open + PDF tab active) — both consumed by Task 5. Removes `woiPdfBlobUrl`.

- [ ] **Step 1: Replace the PDF preview block**

In `assets/visual-editor/app.js`, replace lines 676-723 (from the `// --- PDF preview tab (#6)` comment through the `window.addEventListener( 'beforeunload', ... )` line, but NOT the final `}() );` on line 724) with:

```js
    // --- PDF preview tab (#6): save current design, render real mPDF as A4 canvases ---
    var woiSelectedOrderId = null;          // set by the order bar / select
    var woiPdfRenderGen = 0;                // monotonic guard: a newer render supersedes older ones

    function woiPdfTabActive() {
        var pdf = document.getElementById( 'woi-preview-pdf' );
        return pdf && ! pdf.hasAttribute( 'hidden' );
    }

    // Render every page of the decoded PDF into A4 canvases in a detached fragment,
    // then swap into the stage only if this render is still the latest (gen check).
    function woiRenderPdfPages( bytes, gen ) {
        var stage = document.getElementById( 'woi-pdf-stage' );
        if ( ! stage ) { return Promise.resolve(); }
        if ( ! window.pdfjsLib ) { return Promise.reject( new Error( 'PDF.js not loaded' ) ); }
        pdfjsLib.GlobalWorkerOptions.workerSrc = woiVisual.pdfWorkerUrl;
        var task = pdfjsLib.getDocument( { data: bytes } );
        return task.promise.then( function ( pdf ) {
            var frag = document.createDocumentFragment();
            var dpr  = window.devicePixelRatio || 1;
            var chain = Promise.resolve();
            for ( var n = 1; n <= pdf.numPages; n++ ) {
                ( function ( pageNum ) {
                    chain = chain.then( function () {
                        if ( gen !== woiPdfRenderGen ) { return; } // superseded mid-render
                        return pdf.getPage( pageNum ).then( function ( page ) {
                            var canvas = document.createElement( 'canvas' );
                            var vp     = page.getViewport( { scale: dpr } );
                            canvas.className = 'woi-a4-page';
                            canvas.width  = Math.floor( vp.width );
                            canvas.height = Math.floor( vp.height );
                            frag.appendChild( canvas );
                            return page.render( { canvasContext: canvas.getContext( '2d' ), viewport: vp } ).promise;
                        } );
                    } );
                }( n ) );
            }
            return chain.then( function () {
                if ( gen !== woiPdfRenderGen ) { task.destroy(); return; }
                stage.innerHTML = '';
                stage.appendChild( frag );
                task.destroy();
            } );
        } );
    }

    function woiRenderPdf() {
        var status = document.getElementById( 'woi-render-pdf-status' );
        var stage  = document.getElementById( 'woi-pdf-stage' );
        if ( ! stage ) { return; }
        var gen = ++woiPdfRenderGen;
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
            if ( gen !== woiPdfRenderGen ) { return; } // a newer render started during the round-trip
            if ( ! res.success || ! res.data || ! res.data.preview_data || res.data.output_format !== 'pdf' ) {
                throw new Error( ( res.data && res.data.error ) ? res.data.error : 'Preview failed.' );
            }
            var binary = window.atob( res.data.preview_data );
            var bytes  = new Uint8Array( binary.length );
            for ( var i = 0; i < binary.length; i++ ) { bytes[ i ] = binary.charCodeAt( i ); }
            return woiRenderPdfPages( bytes, gen ).then( function () {
                if ( gen === woiPdfRenderGen && status ) { status.textContent = ''; }
            } );
        } ).catch( function ( e ) {
            if ( gen === woiPdfRenderGen && status ) { status.textContent = 'Error: ' + ( e && e.message ? e.message : e ); }
        } );
    }
    // Re-render the PDF only when its tab is active (avoid a save+round-trip on every edit).
    function woiMaybeRefreshPdf() { if ( woiPaneOpen() && woiPdfTabActive() ) { woiRenderPdf(); } }

    ( function bindPdfTab() {
        var btn = document.getElementById( 'woi-render-pdf' );
        if ( btn ) { btn.addEventListener( 'click', woiRenderPdf ); }
    }() );
```

Note: the `beforeunload` blob-revoke listener is intentionally removed (no Blob URL anymore). Leave the file's final `}() );` (IIFE close) intact immediately after this block.

- [ ] **Step 2: Verify JS syntax**

Run: `node --check assets/visual-editor/app.js`
Expected: no output (exit 0). Any parse error means the block boundaries were mismatched — re-check that the final `}() );` was preserved.

- [ ] **Step 3: Confirm no stale Blob references remain**

Run: `grep -n "woiPdfBlobUrl\|woi-preview-pdf-frame" assets/visual-editor/app.js`
Expected: no matches (empty output).

- [ ] **Step 4: Manual verification**

Reload the editor, click the **PDF** tab, click **Render PDF**. Expected: the status shows `Rendering…` then clears; one white A4 sheet per PDF page appears stacked in the gray stage, each rendered crisply (sharp text on HiDPI). Multi-page documents show all pages. Trigger two renders quickly (click twice) — expected: no duplicated/interleaved pages, only the final result.

- [ ] **Step 5: Commit**

```bash
git add assets/visual-editor/app.js
git commit -m "feat: render PDF preview as A4 canvases via PDF.js (all pages, gen-guarded)"
```

---

### Task 5: Debounced auto-refresh + render on PDF-tab activation

**Files:**
- Modify: `assets/visual-editor/app.js:673` (add update binding) and `:581-587` (tab-click handler)

**Interfaces:**
- Consumes: `woiMaybeRefreshPdf()` (Task 4), `woiDebounce()` (existing, `app.js:635`).
- Produces: nothing for later tasks (final task).

- [ ] **Step 1: Auto-refresh the PDF on edits (debounced ~1s)**

In `assets/visual-editor/app.js`, find line 673:

```js
    editor.on( 'update', woiDebounce( woiRefreshLiveHtml, 400 ) );
```

Add immediately after it:

```js
    editor.on( 'update', woiDebounce( woiMaybeRefreshPdf, 1000 ) );
```

(`woiMaybeRefreshPdf` is a hoisted function declaration in the same IIFE, so referencing it here is safe. It self-gates on pane-open + PDF-tab-active, so edits while the HTML tab or a closed pane is showing cost nothing.)

- [ ] **Step 2: Render when the user switches to the PDF tab**

In `assets/visual-editor/app.js`, in the tab-click handler (lines 581-587), replace:

```js
        b.addEventListener( 'click', function () {
            var tab = b.getAttribute( 'data-woi-tab' );
            woiSetTab( tab );
            if ( 'html' === tab && typeof woiRefreshLiveHtml === 'function' ) { woiRefreshLiveHtml(); }
        } );
```

with:

```js
        b.addEventListener( 'click', function () {
            var tab = b.getAttribute( 'data-woi-tab' );
            woiSetTab( tab );
            if ( 'html' === tab && typeof woiRefreshLiveHtml === 'function' ) { woiRefreshLiveHtml(); }
            if ( 'pdf' === tab && typeof woiMaybeRefreshPdf === 'function' ) { woiMaybeRefreshPdf(); }
        } );
```

- [ ] **Step 3: Verify JS syntax**

Run: `node --check assets/visual-editor/app.js`
Expected: no output (exit 0).

- [ ] **Step 4: Manual verification**

Reload the editor. (a) Click the **PDF** tab → expected: it auto-renders without clicking Render PDF. (b) With the PDF tab active, edit a cell in the canvas → expected: ~1s after you stop, the sheet re-renders with the change. (c) Switch to the **Live HTML** tab and edit rapidly → expected: no PDF round-trips fire (Network shows no `woi_pdf_preview` calls while HTML tab is active). (d) Type continuously for several seconds on the PDF tab → expected: a single render fires after typing settles, not one per keystroke.

- [ ] **Step 5: Commit**

```bash
git add assets/visual-editor/app.js
git commit -m "feat: debounced real-time PDF preview + render on tab activation"
```

---

## Self-Review

**Spec coverage:**
- §1 Renderer & assets (enqueue pdf.min.js, localize pdfWorkerUrl) → Task 1. ✓
- §2 Markup (remove iframe, add scroll+stage) → Task 2. ✓
- §3 CSS (.woi-a4-scroll/.woi-a4-stage/.woi-a4-page + layout-mode updates) → Task 3. ✓
- §4 JS (rewrite woiRenderPdf, all pages, gen guard, drop blob plumbing, debounced refresh) → Tasks 4 (render) + 5 (debounce/activation). ✓
- §4 "render into detached fragment, swap when latest" → Task 4 Step 1 (`frag` + gen check before `stage.appendChild`). ✓
- §5 Cache-bust (version bump) → Task 1 Steps 1-2 + Global Constraints. ✓
- Edge cases (concurrency guard, error → status, HiDPI dpr, multi-page) → Task 4. ✓

**Placeholder scan:** No TBD/TODO; every code step shows complete code. ✓

**Type/name consistency:** `woiRenderPdf`, `woiMaybeRefreshPdf`, `woiRenderPdfPages`, `woiPdfRenderGen`, `woiVisual.pdfWorkerUrl`, `#woi-pdf-stage`, `.woi-a4-page`, `.woi-a4-scroll`, `.woi-a4-stage` used consistently across Tasks 1-5. ✓
