# Editor Editing UX (Slice 4) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the preloaded GrapesJS design editable (load-order fix) and add a WP-block-style `/` + toolbar variable/block inserter with live per-token value previews.

**Architecture:** All changes in `assets/visual-editor/app.js` + `assets/visual-editor/editor.css` (and a version bump). Part A reorders boot so custom component types register before the stored design loads. Part B adds a vanilla insert-menu popup (no third-party RTE), opened by a toolbar button or by typing `/` in an edited field, reusing `TOKEN_META`, the Layout blocks, and the slice-3 `currentOrderTokens` cache for live previews. No server/REST changes.

**Tech Stack:** GrapesJS 0.21.13 (vendored), vanilla JS/DOM, PHP (version bump only). PHPUnit unaffected.

## Global Constraints

- All editor behavior is browser JS with NO in-repo JS test harness → `node --check` is the only automated check; everything else is verified LIVE via the harness (debug Chrome :9222 + puppeteer-core in `%TEMP%\woi-cdp`). Deploy is a manual pull.
- **Bump `WOI_PDF_VERSION` (header + `$version` in `woocommerce-orders-invoice-pdf.php`) — REQUIRED** so the asset URL `app.js?ver=…` changes and browsers fetch the new JS/CSS (last slice's gotcha: a JS fix didn't take because the version was unchanged and the browser served the cached asset).
- Part A is a load-order change ONLY — it must NOT alter the stored design's content (`getHtml()` output unchanged); only component typing changes. The Live-HTML init + stored-vs-starter selection must still happen, routed through `setComponents`.
- Token insertion inserts the literal `{{token}}` text inline at the caret (or appends a text node when not mid-edit). Layout-block insertion adds the block as a NEW block after the current component (NOT inline). Every insert pushes the entry to a Recent list in `localStorage` (key `woiInsertRecent`).
- Token block content elsewhere stays `<span data-woi-token="T">{{T}}</span>`. mPDF-safe markup. No new PHP/REST.
- Full PHP suite must stay green: `php -d display_errors=1 -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit` → 182 / 0 / 1 (no PHP logic changes here except the version bump).
- Branch `feat/editor-editing-ux` (spec committed there).

## File structure

- `assets/visual-editor/app.js` — MODIFY: Part A boot reorder; Part B catalog/recent/preview helpers, popup, toolbar button, insertion, `/` trigger.
- `assets/visual-editor/editor.css` — MODIFY: insert-menu popup styling.
- `woocommerce-orders-invoice-pdf.php` — MODIFY: version bump.

Current `app.js` shape (for reference): `TOKEN_META` (lines ~6-24), `grapesjs.init({…, components: woiVisual.stored || woiVisual.starter || ''})` (~26-33), then `editor.DomComponents.addType('woi-cell'|'woi-trow'|'woi-table', …)` + row/col commands, then `TOKEN_META.forEach(...)` token blocks + Layout `BlockManager.add('row-2col'|'spacer'|'divider'|'heading'|'pagebreak', …)`, then `getHtml`/`save`/preview pane/order bar code, ending with `}() );`.

---

### Task 1: Part A — make the preloaded design editable (load-order fix)

**Files:**
- Modify: `assets/visual-editor/app.js`

**Interfaces:**
- Produces: components are loaded via `editor.setComponents(...)` AFTER the custom type registrations, so stored `<td>`→`woi-cell` (editable), `<table>`→`woi-table`.

- [ ] **Step 1: Start the editor empty**

In `assets/visual-editor/app.js`, change the `grapesjs.init` options object to NOT load components in init — replace the `components:` line:

```js
    var editor = grapesjs.init( {
        container: '#woi-visual-editor',
        height: '80vh',
        fromElement: false,
        storageManager: false,
        deviceManager: { devices: [] },
        components: ''
    } );
```

- [ ] **Step 2: Load the design AFTER the component types + blocks are registered**

Find the end of the block/command/type registration section — specifically, immediately AFTER the last Layout `editor.BlockManager.add( 'pagebreak', { … } )` call (and before the `getHtml`/helpers). Insert:

```js
    // Load the stored design (or starter) AFTER the custom component types are
    // registered, so loaded <td>/<table> resolve to the editable woi-cell/woi-table
    // types (registering types AFTER init left loaded cells as the built-in,
    // non-editable 'cell' type — the cause of "preloaded fields not editable").
    editor.setComponents( woiVisual.stored || woiVisual.starter || '' );
```

- [ ] **Step 3: Verify**

Run: `node --check assets/visual-editor/app.js` → clean.
Full PHP suite (unchanged): `php -d display_errors=1 -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit` → 182 / 0 / 1.
(Live, controller: probe loaded `<td>`s → `type:'woi-cell'`, `editable:true`; double-click the header / `{{shop_name_ar}}` cell edits text; stored design unchanged via getHtml round-trip.)

- [ ] **Step 4: Commit**

```bash
git add assets/visual-editor/app.js
git commit -m "fix: load stored design after registering component types (editable cells)"
```

---

### Task 2: Catalog, token preview, and Recent helpers

**Files:**
- Modify: `assets/visual-editor/app.js`

**Interfaces:**
- Consumes: `TOKEN_META`, `woiVisual.sampleData`, the slice-3 `currentOrderTokens` (cached order token map; may be `null`).
- Produces:
  - `woiBuildCatalog(): Array<{label,kind,value?,token?,blockId?,description?,category}>` — tokens (kind `'token'`) + layout blocks (kind `'block'`).
  - `woiTokenPreview(token): string` — live value/hint for a token from `currentOrderTokens` → `sampleData` → `''`.
  - `woiGetRecent(): Array<string>` / `woiPushRecent(entry): void` — recent ids in localStorage (`woiInsertRecent`).

- [ ] **Step 1: Add the helpers**

In `app.js`, after the `setComponents(...)` line from Task 1 (or anywhere after `TOKEN_META` and the Layout block registrations, before the toolbar buttons), add:

```js
    // --- Insert-menu catalog + previews + recent (#4) ---
    var WOI_LAYOUT_ITEMS = [
        { label: 'Table (2 columns)', blockId: 'row-2col', description: 'Editable 2-column table' },
        { label: 'Heading',           blockId: 'heading',  description: 'Section heading' },
        { label: 'Divider',           blockId: 'divider',  description: 'Horizontal rule' },
        { label: 'Spacer',            blockId: 'spacer',   description: 'Vertical space' },
        { label: 'Page break',        blockId: 'pagebreak', description: 'Force a new page' }
    ];
    var WOI_BLOCK_TOKENS = { logo: 1, line_items: 1, totals: 1, billing_address: 1 };

    // Build the unified catalog: tokens (inline) + layout blocks.
    function woiBuildCatalog() {
        var items = TOKEN_META.map( function ( m ) {
            return { id: 'token-' + m[0], label: m[1], kind: 'token', token: m[0], value: '{{' + m[0] + '}}', category: m[2] };
        } );
        WOI_LAYOUT_ITEMS.forEach( function ( l ) {
            items.push( { id: 'block-' + l.blockId, label: l.label, kind: 'block', blockId: l.blockId, description: l.description, category: 'Layout' } );
        } );
        return items;
    }

    // Live value/hint for a token, from the selected order (or sample data).
    function woiTokenPreview( token ) {
        var map = currentOrderTokens || woiVisual.sampleData || {};
        var raw = map[ '{{' + token + '}}' ];
        if ( raw == null || raw === '' ) { return ''; }
        if ( WOI_BLOCK_TOKENS[ token ] ) {
            if ( token === 'logo' ) { return '[image]'; }
            var rows = ( String( raw ).match( /<tr/gi ) || [] ).length;
            return rows ? '[table · ' + rows + ' rows]' : '[table]';
        }
        var text = String( raw ).replace( /<[^>]*>/g, ' ' ).replace( /\s+/g, ' ' ).trim();
        return text.length > 40 ? text.slice( 0, 40 ) + '…' : text;
    }

    function woiGetRecent() {
        try { return JSON.parse( window.localStorage.getItem( 'woiInsertRecent' ) || '[]' ); }
        catch ( e ) { return []; }
    }
    function woiPushRecent( entry ) {
        try {
            var list = woiGetRecent().filter( function ( id ) { return id !== entry.id; } );
            list.unshift( entry.id );
            window.localStorage.setItem( 'woiInsertRecent', JSON.stringify( list.slice( 0, 6 ) ) );
        } catch ( e ) {}
    }
```

- [ ] **Step 2: Verify + commit**

Run: `node --check assets/visual-editor/app.js` → clean.

```bash
git add assets/visual-editor/app.js
git commit -m "feat: insert-menu catalog, token live-preview, and recent helpers"
```

---

### Task 3: Insert-menu popup (UI, filter, keyboard, live previews) + CSS

**Files:**
- Modify: `assets/visual-editor/app.js`
- Modify: `assets/visual-editor/editor.css`

**Interfaces:**
- Consumes: `woiBuildCatalog()`, `woiTokenPreview()`, `woiGetRecent()` (Task 2).
- Produces:
  - `woiOpenInsertMenu(x, y): void` — show the popup at page coords `(x,y)` (or centered if omitted), reset filter, focus the search box.
  - `woiCloseInsertMenu(): void`.
  - On select (Enter/click), calls `woiInsertSelect(entry)` if defined (Task 4), then closes. Keyboard: Up/Down highlight, Enter select, Esc close.

- [ ] **Step 1: Add the popup engine**

In `app.js`, after the Task 2 helpers, add:

```js
    // --- Insert-menu popup (#4) ---
    var woiMenuEl = null, woiMenuItems = [], woiMenuActive = -1;

    function woiEnsureMenu() {
        if ( woiMenuEl ) { return woiMenuEl; }
        woiMenuEl = document.createElement( 'div' );
        woiMenuEl.id = 'woi-insert-menu';
        woiMenuEl.hidden = true;
        woiMenuEl.innerHTML = '<input type="text" id="woi-insert-search" placeholder="Search variables…" autocomplete="off"><div id="woi-insert-list"></div>';
        document.body.appendChild( woiMenuEl );

        var search = woiMenuEl.querySelector( '#woi-insert-search' );
        search.addEventListener( 'input', function () { woiRenderMenu( search.value ); } );
        search.addEventListener( 'keydown', function ( e ) {
            if ( e.key === 'ArrowDown' ) { e.preventDefault(); woiMoveMenu( 1 ); }
            else if ( e.key === 'ArrowUp' ) { e.preventDefault(); woiMoveMenu( -1 ); }
            else if ( e.key === 'Enter' ) { e.preventDefault(); woiChooseMenu( woiMenuActive ); }
            else if ( e.key === 'Escape' ) { e.preventDefault(); woiCloseInsertMenu(); }
        } );
        document.addEventListener( 'mousedown', function ( e ) {
            if ( woiMenuEl && ! woiMenuEl.hidden && ! woiMenuEl.contains( e.target ) ) { woiCloseInsertMenu(); }
        } );
        return woiMenuEl;
    }

    function woiRenderMenu( filter ) {
        var list = woiMenuEl.querySelector( '#woi-insert-list' );
        var f = ( filter || '' ).toLowerCase();
        var catalog = woiBuildCatalog();
        var byId = {};
        catalog.forEach( function ( it ) { byId[ it.id ] = it; } );

        // Recent group first (only when no active filter).
        var groups = [];
        if ( ! f ) {
            var recent = woiGetRecent().map( function ( id ) { return byId[ id ]; } ).filter( Boolean );
            if ( recent.length ) { groups.push( { name: 'Recent', items: recent } ); }
        }
        var order = [ 'Shop', 'Document', 'Customer', 'Items & Totals', 'Layout' ];
        order.forEach( function ( cat ) {
            var items = catalog.filter( function ( it ) {
                return it.category === cat && ( ! f || it.label.toLowerCase().indexOf( f ) !== -1 || ( it.token || '' ).indexOf( f ) !== -1 );
            } );
            if ( items.length ) { groups.push( { name: cat, items: items } ); }
        } );

        woiMenuItems = [];
        var html = '';
        groups.forEach( function ( g ) {
            html += '<div class="woi-insert-group">' + g.name + '</div>';
            g.items.forEach( function ( it ) {
                var idx = woiMenuItems.length;
                woiMenuItems.push( it );
                var right = it.kind === 'token'
                    ? '<span class="woi-insert-val">' + woiEsc( woiTokenPreview( it.token ) ) + '</span>'
                    : '<span class="woi-insert-desc">' + woiEsc( it.description ) + '</span>';
                html += '<div class="woi-insert-item" data-idx="' + idx + '">' +
                    '<span class="woi-insert-label">' + woiEsc( it.label ) + '</span>' + right + '</div>';
            } );
        } );
        list.innerHTML = html || '<div class="woi-insert-empty">No matches</div>';
        woiMenuActive = woiMenuItems.length ? 0 : -1;
        woiHighlightMenu();
        Array.prototype.forEach.call( list.querySelectorAll( '.woi-insert-item' ), function ( el ) {
            el.addEventListener( 'mousedown', function ( e ) { e.preventDefault(); woiChooseMenu( parseInt( el.getAttribute( 'data-idx' ), 10 ) ); } );
        } );
    }

    function woiEsc( s ) { return String( s == null ? '' : s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ); }

    function woiHighlightMenu() {
        var els = woiMenuEl.querySelectorAll( '.woi-insert-item' );
        Array.prototype.forEach.call( els, function ( el ) {
            el.classList.toggle( 'is-active', parseInt( el.getAttribute( 'data-idx' ), 10 ) === woiMenuActive );
        } );
        var active = woiMenuEl.querySelector( '.woi-insert-item.is-active' );
        if ( active && active.scrollIntoView ) { active.scrollIntoView( { block: 'nearest' } ); }
    }
    function woiMoveMenu( dir ) {
        if ( ! woiMenuItems.length ) { return; }
        woiMenuActive = ( woiMenuActive + dir + woiMenuItems.length ) % woiMenuItems.length;
        woiHighlightMenu();
    }
    function woiChooseMenu( idx ) {
        var entry = woiMenuItems[ idx ];
        if ( entry && typeof woiInsertSelect === 'function' ) { woiInsertSelect( entry ); }
        woiCloseInsertMenu();
    }

    function woiOpenInsertMenu( x, y ) {
        woiEnsureMenu();
        woiMenuEl.hidden = false;
        if ( typeof x === 'number' && typeof y === 'number' ) {
            woiMenuEl.style.left = Math.max( 8, Math.min( x, window.innerWidth - 320 ) ) + 'px';
            woiMenuEl.style.top = ( y + 6 ) + 'px';
        } else {
            woiMenuEl.style.left = ( window.innerWidth / 2 - 150 ) + 'px';
            woiMenuEl.style.top = '120px';
        }
        var search = woiMenuEl.querySelector( '#woi-insert-search' );
        search.value = '';
        woiRenderMenu( '' );
        search.focus();
    }
    function woiCloseInsertMenu() { if ( woiMenuEl ) { woiMenuEl.hidden = true; } }
```

- [ ] **Step 2: Add popup CSS**

Append to `assets/visual-editor/editor.css`:

```css
#woi-insert-menu { position: fixed; z-index: 100000; width: 300px; max-height: 360px; overflow: auto; background: #fff; border: 1px solid #c3c4c7; box-shadow: 0 6px 24px rgba(0,0,0,.18); border-radius: 4px; padding: 6px; }
#woi-insert-menu[hidden] { display: none; }
#woi-insert-search { width: 100%; box-sizing: border-box; margin-bottom: 6px; }
.woi-insert-group { font-size: 11px; text-transform: uppercase; color: #777; padding: 6px 6px 2px; }
.woi-insert-item { display: flex; justify-content: space-between; gap: 8px; padding: 5px 6px; border-radius: 3px; cursor: pointer; }
.woi-insert-item.is-active, .woi-insert-item:hover { background: #2271b1; color: #fff; }
.woi-insert-label { white-space: nowrap; }
.woi-insert-val, .woi-insert-desc { color: #777; font-size: 12px; max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.woi-insert-item.is-active .woi-insert-val, .woi-insert-item.is-active .woi-insert-desc { color: #dbeafe; }
.woi-insert-empty { padding: 8px; color: #777; }
```

- [ ] **Step 3: Verify + commit**

`node --check assets/visual-editor/app.js` → clean.

```bash
git add assets/visual-editor/app.js assets/visual-editor/editor.css
git commit -m "feat: insert-menu popup (filter, keyboard nav, live token previews)"
```

---

### Task 4: Insertion logic + toolbar "Insert variable" button

**Files:**
- Modify: `assets/visual-editor/app.js`

**Interfaces:**
- Consumes: `editor`, `editor.Canvas.getDocument()`, `editor.BlockManager`, `woiPushRecent()` (Task 2), the popup's `woiChooseMenu`→`woiInsertSelect` contract (Task 3).
- Produces:
  - `woiInsertTextAtCaret(doc, text): boolean` — insert text at the current selection caret in `doc`.
  - `woiInsertSelect(entry): void` — token → inline `{{token}}` at caret (or append text node), block → new block after the selected component; pushes Recent.
  - Toolbar button `woi-insert-var` (icon `fa fa-plus-circle`) → `woiOpenInsertMenu()`.

- [ ] **Step 1: Add insertion logic**

In `app.js`, after the Task 3 popup code, add:

```js
    // --- Insertion (#4) ---
    function woiInsertTextAtCaret( doc, text ) {
        var sel = doc.getSelection ? doc.getSelection() : null;
        if ( ! sel || ! sel.rangeCount ) { return false; }
        var range = sel.getRangeAt( 0 );
        range.deleteContents();
        var node = doc.createTextNode( text );
        range.insertNode( node );
        range.setStartAfter( node ); range.setEndAfter( node );
        sel.removeAllRanges(); sel.addRange( range );
        return true;
    }

    function woiInsertSelect( entry ) {
        var doc = editor.Canvas.getDocument();
        var active = doc ? doc.activeElement : null;
        if ( entry.kind === 'token' ) {
            if ( active && active.isContentEditable && woiInsertTextAtCaret( doc, entry.value ) ) {
                // RTE syncs the component's HTML on blur; force a sync trigger.
                active.dispatchEvent( new Event( 'input', { bubbles: true } ) );
            } else {
                var target = editor.getSelected() || editor.getWrapper();
                target.append( { type: 'text', content: entry.value } );
            }
        } else { // layout block
            var block = editor.BlockManager.get( entry.blockId );
            var content = block ? block.get( 'content' ) : '';
            var selc = editor.getSelected();
            if ( selc && selc.parent && selc.parent() ) {
                var parent = selc.parent();
                var idx = parent.components().indexOf( selc );
                parent.append( content, { at: idx + 1 } );
            } else {
                editor.getWrapper().append( content );
            }
        }
        woiPushRecent( entry );
    }
```

- [ ] **Step 2: Add the toolbar button**

In `app.js`, add a toolbar button (place it with the other `editor.Panels.addButton( 'options', … )` calls — e.g. right after the `woi-preview-toggle` button from slice 3):

```js
    editor.Panels.addButton( 'options', {
        id: 'woi-insert-var',
        className: 'fa fa-plus-circle',
        attributes: { title: 'Insert variable or block' },
        command: function () { woiOpenInsertMenu(); }
    } );
```

- [ ] **Step 3: Verify + commit**

`node --check assets/visual-editor/app.js` → clean. Full PHP suite 182 / 0 / 1 (unchanged).
(Live: the "+" toolbar button opens the menu; selecting a token with a text field focused inserts `{{token}}` inline; selecting "Divider" adds a divider after the selected block; Recent updates.)

```bash
git add assets/visual-editor/app.js
git commit -m "feat: insertion logic + 'Insert variable' toolbar button"
```

---

### Task 5: `/` trigger — open the menu while editing a field

**Files:**
- Modify: `assets/visual-editor/app.js`

**Interfaces:**
- Consumes: `editor.Canvas.getDocument()`, `editor.Canvas.getFrameEl()`, `woiOpenInsertMenu()`, `woiInsertSelect()` (so the slash path can delete the typed `/query` before inserting).
- Produces: a `keyup` listener on the canvas document that opens the menu at the caret when `/` is typed in a `contenteditable`; the menu's selection deletes the typed `/query` then inserts.

- [ ] **Step 1: Track the slash origin + wire the canvas listener**

In `app.js`, after the Task 4 insertion code, add:

```js
    // --- "/" trigger (#4): open the insert menu while editing a field ---
    var woiSlash = null; // { node, offset } of the "/" character

    function woiCaretPageXY() {
        var doc = editor.Canvas.getDocument();
        var frame = editor.Canvas.getFrameEl();
        var sel = doc.getSelection && doc.getSelection();
        if ( ! sel || ! sel.rangeCount || ! frame ) { return null; }
        var rect = sel.getRangeAt( 0 ).getBoundingClientRect();
        var fr = frame.getBoundingClientRect();
        return { x: fr.left + rect.left, y: fr.top + rect.bottom };
    }

    function woiBindSlash() {
        var doc = editor.Canvas.getDocument();
        if ( ! doc ) { return; }
        doc.addEventListener( 'keyup', function ( e ) {
            var el = doc.activeElement;
            if ( ! el || ! el.isContentEditable ) { return; }
            if ( e.key === '/' ) {
                var sel = doc.getSelection();
                if ( sel && sel.rangeCount ) {
                    var r = sel.getRangeAt( 0 );
                    // The "/" sits just before the caret.
                    woiSlash = { node: r.startContainer, offset: Math.max( 0, r.startOffset - 1 ) };
                    var xy = woiCaretPageXY();
                    if ( xy ) { woiOpenInsertMenu( xy.x, xy.y ); } else { woiOpenInsertMenu(); }
                }
            }
        } );
    }
    // The canvas iframe may not be ready immediately; bind on load and on each component load.
    editor.on( 'load', woiBindSlash );
    editor.on( 'canvas:frame:load', woiBindSlash );
```

- [ ] **Step 2: Make selection delete the typed `/query` on the slash path**

In `app.js`, wrap the existing `woiInsertSelect` (Task 4) so that, when the menu was opened via `/`, the `/query` text is removed before inserting. Replace the `woiInsertSelect` token branch's caret insert with a slash-aware version — change the START of `woiInsertSelect` to first clear the slash query:

```js
    function woiClearSlashQuery() {
        if ( ! woiSlash ) { return; }
        var doc = editor.Canvas.getDocument();
        var sel = doc.getSelection && doc.getSelection();
        try {
            if ( sel && sel.rangeCount && woiSlash.node ) {
                var range = doc.createRange();
                range.setStart( woiSlash.node, Math.min( woiSlash.offset, ( woiSlash.node.length || 0 ) ) );
                range.setEnd( sel.getRangeAt( 0 ).endContainer, sel.getRangeAt( 0 ).endOffset );
                range.deleteContents();
                sel.removeAllRanges(); sel.addRange( range );
            }
        } catch ( e ) {}
        woiSlash = null;
    }
```

Then, in `woiInsertSelect`, call `woiClearSlashQuery()` as the FIRST line of the function (before computing `active`/inserting). This removes the `/` and any filter text the user typed into the field, leaving a clean caret for the inline insert. (When the menu was opened from the toolbar button, `woiSlash` is null and this is a no-op.)

- [ ] **Step 3: Verify**

`node --check assets/visual-editor/app.js` → clean.
(Live: double-click a field, type `/`, the menu opens at the caret; type `shop` to filter; Enter inserts `{{shop_name}}` inline and the `/shop` text is gone; the toolbar button path still inserts without deleting anything.)

- [ ] **Step 4: Commit**

```bash
git add assets/visual-editor/app.js
git commit -m "feat: '/' trigger opens the insert menu at the caret while editing"
```

---

### Task 6: Version bump + verification

**Files:**
- Modify: `woocommerce-orders-invoice-pdf.php`

- [ ] **Step 1: Bump the version (cache-bust)**

Bump BOTH the header `Version:` and the `$version` property from `1.4.13` to `1.4.14` (they must match). REQUIRED so the browser fetches the new `app.js`/`editor.css`.

- [ ] **Step 2: Run all checks**

Run: `node --check assets/visual-editor/app.js` → clean.
Run: `php -d display_errors=1 -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit` → 182 / 0 / 1.

- [ ] **Step 3: Full live acceptance (controller, after merge+pull)**

Confirm on the deployed site (Status tab shows the new revision first): preloaded cells editable (probe `woi-cell`/`editable:true`; double-click + edit); toolbar "+" opens the menu; `/` in a field opens it at the caret + filters; token inserts inline; layout block inserts a new block after; each token row shows the live value for the selected order; Recent persists; save/Live-HTML/PDF still work; stored design saved+restored around tests.

- [ ] **Step 4: Commit**

```bash
git add woocommerce-orders-invoice-pdf.php
git commit -m "chore: bump version for editor editing UX (cache-bust)"
```

---

## Self-Review

**Spec coverage:**
- Part A editability load-order fix (init empty → register types → setComponents) → Task 1. ✓
- Part B catalog (tokens + layout blocks) + Recent (localStorage) → Task 2. ✓
- Part B popup (filter, keyboard, grouped, live previews) + CSS → Task 3. ✓
- Part B live per-token value preview (currentOrderTokens → sampleData; `[image]`/`[table · N rows]` hints) → Task 2 `woiTokenPreview` + Task 3 render. ✓
- Part B toolbar "Insert variable" button → Task 4. ✓
- Part B token inline / block-after insertion + recent push → Task 4. ✓
- Part B `/` trigger (contenteditable listener, caret menu position, delete `/query`) → Task 5. ✓
- Version bump / cache-bust → Task 6 (+ Global Constraint). ✓
- No server/REST/storage change → confirmed (JS + CSS + version only). ✓

**Placeholder scan:** Every code step has complete code. JS tasks use `node --check` + live verification (stated; no in-repo JS harness) — not a hidden gap.

**Type consistency:** `woiBuildCatalog`/`woiTokenPreview`/`woiGetRecent`/`woiPushRecent` (Task 2) used by Task 3/4. `woiOpenInsertMenu`/`woiCloseInsertMenu`/`woiChooseMenu` (Task 3) call `woiInsertSelect` (Task 4, guarded by `typeof`). `woiInsertSelect`/`woiInsertTextAtCaret` (Task 4) used by Task 5; `woiClearSlashQuery`/`woiSlash` (Task 5) called inside `woiInsertSelect`. Catalog entry shape `{id,label,kind,token?,value?,blockId?,description?,category}` consistent across Tasks 2-5. DOM ids/classes (`#woi-insert-menu`, `#woi-insert-search`, `#woi-insert-list`, `.woi-insert-item[data-idx]`, `.woi-insert-group`, `.woi-insert-val`, `.woi-insert-desc`) consistent between the JS (Task 3) and CSS (Task 3). Block ids (`row-2col`,`heading`,`divider`,`spacer`,`pagebreak`) match the slice-3 BlockManager registrations. ✓

## Out of scope (later slices)

Other document types; manual favourites/pinning; rich-text formatting toolbar; server/REST changes.
