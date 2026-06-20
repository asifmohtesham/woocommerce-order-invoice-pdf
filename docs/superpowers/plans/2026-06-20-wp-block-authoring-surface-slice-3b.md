# WP Block Authoring Surface — Slice 3 Part B (A4 PDF.js Tab) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a **PDF** tab to the block editor's preview panel that renders the real mPDF output as crisp A4 canvas pages using the already-vendored PDF.js — alongside the Live HTML tab from Part A.

**Architecture:** A "Render PDF" action saves the current block markup (so the rendered HTML is stored), POSTs the existing `woi_pdf_preview` admin-ajax action (same one the GrapesJS PDF tab uses), receives a base64 PDF, decodes it, and renders every page onto `<canvas>` "sheets" with PDF.js — reusing the vendored `assets/js/pdf_js/` and the same monotonic gen-guard pattern as the GrapesJS editor. No backend changes: `woi_pdf_preview` renders the document via `OrderDocument::get_html()` → `get_active()`, so the PDF reflects the **active source** — exactly like the GrapesJS PDF tab (which only shows the visual design when the "Visual template (invoice)" toggle is on). A hint guides the user to set source = Block editor.

**Tech Stack:** `@wordpress/scripts` 30, `@wordpress/element` (React) + `@wordpress/blocks` (`serialize`), vendored PDF.js (`globalThis.pdfjsLib`), PHP 7.4.

**Scope note:** This is **Slice 3 Part B** — the **PDF tab** only. Layout modes (full/stack/overlay) are **Part C** (separate plan). Part B builds on Part A (Live HTML preview, already on `origin/master`). It does NOT change the render path, REST routes, or `woi_pdf_preview`; it adds a second preview tab.

## Global Constraints

- **Invoice-only**; render engine stays mPDF; render-path/REST/`ajax_preview` handler/build-config untouched. GrapesJS (`assets/visual-editor/*`) is NOT modified.
- **Reuse the existing preview pipeline:** POST `woi_pdf_preview` (admin-ajax; `security` = `woi_pdf_preview` nonce = `previewNonce`; params `document_type`, optional `order_id`; response `{success, data:{ preview_data (base64), output_format:'pdf', error? }}`). Reuse the vendored PDF.js at `assets/js/pdf_js/pdf.min.js` + worker `pdf.worker.min.js` (`globalThis.pdfjsLib`).
- **The PDF tab is a save-to-preview** (it saves the block markup before rendering, mirroring the GrapesJS PDF tab). This is an intentional mutation; the **Live HTML** tab stays read-only.
- **PDF reflects the active source.** Because `woi_pdf_preview` renders `get_active()`, the PDF shows the blocks design only when the active source is `blocks` AND the visual toggle is on (same requirement as the GrapesJS PDF tab). Show a hint in the PDF tab when source ≠ `blocks`.
- **Gen-guard concurrency:** a newer render supersedes an older one; destroy the PDF.js task on success/supersede/reject (port the GrapesJS guard exactly).
- **Version bump is a shared, collision-prone resource.** Before bumping, `git fetch origin`, read the TRUE `origin/master` value, take the next patch above it; set BOTH line 6 and line 24 of `woocommerce-orders-invoice-pdf.php`. (See `version-coordination` memory.)
- **Run PHPUnit as:** `php -d display_errors=1 -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit`. NEVER `vendor/bin/phpunit`.
- **Working tree is the git worktree** at `.claude/worktrees/wp-block-slice-3b` on branch `worktree-wp-block-slice-3b`, based on `origin/master`. Integrate by **fast-forward push to `origin/master`** — never check out `master` in the shared main checkout.

---

## File Structure

**Create:**
- `src/block-editor/pdfPreview.js` — PDF render orchestration (save → fetch `woi_pdf_preview` → decode → PDF.js A4 render, with the gen-guard). No JSX.

**Modify:**
- `includes/Visual/BlockEditorPage.php` — enqueue the vendored PDF.js, add it as a dependency of the block bundle, localize `pdfWorkerUrl`.
- `src/block-editor/PreviewPanel.js` — add the Live HTML / PDF tab switcher, the PDF tab (hint + Render button + status + A4 stage), and track the selected order id; accept a `source` prop.
- `src/block-editor/index.js` — pass `source` to `<PreviewPanel>`.
- `woocommerce-orders-invoice-pdf.php` — version bump.

No new automated tests: JS has no harness; PDF.js canvas rendering has no oracle outside a visible browser tab (it even hangs in hidden/background tabs — see the `grapesjs-next-steps` memory). Verified by build + live acceptance.

---

## Task 1: Enqueue PDF.js in the block editor

**Files:**
- Modify: `includes/Visual/BlockEditorPage.php`

**Interfaces:**
- Produces: `globalThis.pdfjsLib` available on the block-editor screen; `window.woiBlocks.pdfWorkerUrl` localized (Task 2 consumes it).

- [ ] **Step 1: Enqueue PDF.js + add the dependency + localize the worker URL**

In `includes/Visual/BlockEditorPage.php`, inside `enqueue()`:

(a) BEFORE the `wp_enqueue_script( 'woi-block-editor', … )` call, enqueue PDF.js:

```php
        wp_enqueue_script( 'woi-pdfjs', WOI_PDF()->plugin_url() . '/assets/js/pdf_js/pdf.min.js', array(), WOI_PDF_VERSION, true );
```

(b) add `'woi-pdfjs'` to the block bundle's dependencies so PDF.js loads first — change the `wp_enqueue_script( 'woi-block-editor', … , $asset['dependencies'], … )` call so the deps are:

```php
            array_merge( $asset['dependencies'], array( 'woi-pdfjs' ) ),
```

(c) add `pdfWorkerUrl` to the `woiBlocks` localize array (after `orderSearchAction`):

```php
            'pdfWorkerUrl'      => esc_url_raw( WOI_PDF()->plugin_url() . '/assets/js/pdf_js/pdf.worker.min.js' ),
```

- [ ] **Step 2: Lint + full suite**

Run: `php -l includes/Visual/BlockEditorPage.php && php -d display_errors=1 -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit`
Expected: no syntax errors; suite PASS, 0 errors (1 intentional skip OK).

- [ ] **Step 3: Commit**

```bash
git add includes/Visual/BlockEditorPage.php
git commit -m "feat(visual): enqueue PDF.js for the block editor preview"
```

---

## Task 2: PDF render helper + PDF tab in PreviewPanel

**Files:**
- Create: `src/block-editor/pdfPreview.js`
- Modify: `src/block-editor/PreviewPanel.js`, `src/block-editor/index.js`

**Interfaces:**
- Consumes: `window.pdfjsLib`, `window.woiBlocks` (`pdfWorkerUrl`, `ajaxUrl`, `previewNonce`, `docType`), `saveBlocks` (from `store.js`), `serialize`.
- Produces: `renderPdfPreview({ stageEl, blocks, orderId, onStatus })` (default-ish export); PreviewPanel gains the tab UI.

- [ ] **Step 1: Create the PDF render helper (no JSX)**

Create `src/block-editor/pdfPreview.js`:

```js
import { serialize } from '@wordpress/blocks';
import { saveBlocks } from './store';

// Monotonic guard: a newer render supersedes older ones mid-flight.
let renderGen = 0;

// Render every page of the decoded PDF into A4 canvases in a detached fragment,
// then swap into the stage only if this render is still the latest (gen check).
function renderPdfPages( stageEl, bytes, gen ) {
	if ( ! window.pdfjsLib ) { return Promise.reject( new Error( 'PDF.js not loaded' ) ); }
	window.pdfjsLib.GlobalWorkerOptions.workerSrc = window.woiBlocks.pdfWorkerUrl;
	const task = window.pdfjsLib.getDocument( { data: bytes } );
	return task.promise.then( ( pdf ) => {
		const frag = document.createDocumentFragment();
		const dpr = window.devicePixelRatio || 1;
		let chain = Promise.resolve();
		for ( let n = 1; n <= pdf.numPages; n++ ) {
			( ( pageNum ) => {
				chain = chain.then( () => {
					if ( gen !== renderGen ) { return undefined; } // superseded mid-render
					return pdf.getPage( pageNum ).then( ( page ) => {
						const canvas = document.createElement( 'canvas' );
						const vp = page.getViewport( { scale: dpr } );
						// Intrinsic px are dpr-scaled for crisp HiDPI; CSS keeps display at true A4.
						canvas.width = Math.floor( vp.width );
						canvas.height = Math.floor( vp.height );
						canvas.style.width = '100%';
						canvas.style.height = 'auto';
						canvas.style.aspectRatio = '210 / 297';
						canvas.style.background = '#fff';
						canvas.style.boxShadow = '0 1px 6px rgba(0,0,0,.45)';
						canvas.style.display = 'block';
						frag.appendChild( canvas );
						return page.render( { canvasContext: canvas.getContext( '2d' ), viewport: vp } ).promise;
					} );
				} );
			} )( n );
		}
		return chain.then( () => {
			if ( gen !== renderGen ) { task.destroy(); return; }
			stageEl.innerHTML = '';
			stageEl.appendChild( frag );
			task.destroy();
		} );
	} ).catch( ( e ) => {
		task.destroy();
		return Promise.reject( e );
	} );
}

// Save the current design, render the real mPDF, paint A4 canvases into stageEl.
// onStatus( text ) reports progress/errors ('' clears). Returns a Promise.
export function renderPdfPreview( { stageEl, blocks, orderId, onStatus } ) {
	if ( ! stageEl ) { return Promise.resolve(); }
	const gen = ++renderGen;
	onStatus( 'Rendering…' );
	return saveBlocks( serialize( blocks || [] ) ).then( () => {
		const w = window.woiBlocks || {};
		let body = 'action=woi_pdf_preview' +
			'&security=' + encodeURIComponent( w.previewNonce ) +
			'&document_type=' + encodeURIComponent( w.docType );
		if ( orderId ) { body += '&order_id=' + encodeURIComponent( orderId ); }
		return fetch( w.ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			credentials: 'same-origin',
			body,
		} );
	} ).then( ( r ) => { if ( ! r.ok ) { throw new Error( 'HTTP ' + r.status ); } return r.json(); } )
		.then( ( res ) => {
			if ( gen !== renderGen ) { return undefined; } // a newer render started during the round-trip
			if ( ! res.success || ! res.data || ! res.data.preview_data || 'pdf' !== res.data.output_format ) {
				throw new Error( ( res.data && res.data.error ) ? res.data.error : 'Preview failed.' );
			}
			const binary = window.atob( res.data.preview_data );
			const bytes = new Uint8Array( binary.length );
			for ( let i = 0; i < binary.length; i++ ) { bytes[ i ] = binary.charCodeAt( i ); }
			return renderPdfPages( stageEl, bytes, gen ).then( () => { if ( gen === renderGen ) { onStatus( '' ); } } );
		} ).catch( ( e ) => { if ( gen === renderGen ) { onStatus( 'Error: ' + ( e && e.message ? e.message : e ) ); } } );
}
```

- [ ] **Step 2: Syntax-check the helper**

Run: `node --check src/block-editor/pdfPreview.js 2>&1 || node --input-type=module --check < src/block-editor/pdfPreview.js && echo "valid ESM"`
Expected: valid (the file is ESM; bare `node --check` may report the CJS/ESM notice — the `--input-type=module` check is the real gate).

- [ ] **Step 3: Replace PreviewPanel.js with the tabbed version**

Overwrite `src/block-editor/PreviewPanel.js` with this complete file (adds the tab switcher + PDF tab + selected-order tracking + `source` prop; keeps the Part-A Live HTML behavior):

```js
import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { renderedHtmlFromBlocks, mergeTokens, wrapForPreview, fetchOrderTokens, fetchOrders, orderRowTitle } from './preview';
import { renderPdfPreview } from './pdfPreview';

export default function PreviewPanel( { blocks, source } ) {
	const iframeRef = useRef( null );
	const stageRef = useRef( null );
	const [ tab, setTab ] = useState( 'html' ); // 'html' | 'pdf'
	const [ tokens, setTokens ] = useState( () => ( window.woiBlocks && window.woiBlocks.sampleData ) || null );
	const [ orderLabel, setOrderLabel ] = useState( '' );
	const [ orderId, setOrderId ] = useState( null );
	const [ results, setResults ] = useState( null );
	const [ term, setTerm ] = useState( '' );
	const [ pdfStatus, setPdfStatus ] = useState( '' );

	// Re-render the live HTML iframe (debounced) on block or token changes, only on the HTML tab.
	useEffect( () => {
		if ( 'html' !== tab ) { return undefined; }
		const t = setTimeout( () => {
			const frame = iframeRef.current;
			if ( frame ) {
				frame.srcdoc = wrapForPreview( mergeTokens( renderedHtmlFromBlocks( blocks ), tokens ) );
			}
		}, 400 );
		return () => clearTimeout( t );
	}, [ blocks, tokens, tab ] );

	// Load the last order's tokens on mount.
	useEffect( () => {
		fetchOrderTokens( null ).then( ( res ) => {
			if ( res && res.tokens ) {
				setTokens( res.tokens );
				if ( res.order_label ) { setOrderLabel( res.order_label ); }
			}
		} );
	}, [] );

	const renderPdf = useCallback( () => {
		renderPdfPreview( { stageEl: stageRef.current, blocks, orderId, onStatus: setPdfStatus } );
	}, [ blocks, orderId ] );

	// Render the PDF once when the PDF tab becomes active.
	useEffect( () => {
		if ( 'pdf' === tab ) { renderPdf(); }
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ tab ] );

	const onSearch = useCallback( () => {
		fetchOrders( term ).then( ( data ) => setResults( data ) );
	}, [ term ] );

	const onPick = useCallback( ( id, label ) => {
		setResults( null );
		setTerm( label );
		setOrderId( id );
		fetchOrderTokens( id ).then( ( res ) => {
			if ( res && res.tokens ) {
				setTokens( res.tokens );
				setOrderLabel( res.order_label || label );
			}
		} );
	}, [] );

	return (
		<div className="woi-block-preview" style={ { flex: '1', minWidth: '360px', borderLeft: '1px solid #ddd', display: 'flex', flexDirection: 'column' } }>
			<div className="woi-block-preview-bar" style={ { display: 'flex', gap: '8px', alignItems: 'center', padding: '8px', flexWrap: 'wrap' } }>
				<div className="woi-block-preview-tabs" role="group" style={ { display: 'flex', gap: '4px' } }>
					<button type="button" className={ 'button' + ( 'html' === tab ? ' button-primary' : '' ) } onClick={ () => setTab( 'html' ) }>{ __( 'Live HTML', 'woocommerce-orders-invoice-pdf' ) }</button>
					<button type="button" className={ 'button' + ( 'pdf' === tab ? ' button-primary' : '' ) } onClick={ () => setTab( 'pdf' ) }>{ __( 'PDF', 'woocommerce-orders-invoice-pdf' ) }</button>
				</div>
				<input
					type="text"
					value={ term }
					onChange={ ( e ) => setTerm( e.target.value ) }
					onKeyDown={ ( e ) => { if ( 'Enter' === e.key ) { onSearch(); } } }
					placeholder={ __( 'Order #, name or email (blank = last order)', 'woocommerce-orders-invoice-pdf' ) }
					style={ { flex: '1', minWidth: '160px' } }
				/>
				<button type="button" className="button" onClick={ onSearch }>{ __( 'Find', 'woocommerce-orders-invoice-pdf' ) }</button>
				{ orderLabel ? <span style={ { color: '#555' } }>{ __( 'Order:', 'woocommerce-orders-invoice-pdf' ) } { orderLabel }</span> : null }
			</div>
			{ results ? (
				<ul className="woi-block-order-results" style={ { listStyle: 'none', margin: 0, padding: '4px 8px', maxHeight: '160px', overflow: 'auto', borderBottom: '1px solid #eee' } }>
					{ 0 === Object.keys( results ).length
						? <li style={ { color: '#777' } }>{ __( 'No orders found', 'woocommerce-orders-invoice-pdf' ) }</li>
						: Object.keys( results ).map( ( id ) => (
							<li key={ id }>
								<button type="button" className="button-link" onClick={ () => onPick( id, orderRowTitle( results[ id ] ) ) }>
									{ orderRowTitle( results[ id ] ) }
								</button>
							</li>
						) ) }
				</ul>
			) : null }
			<iframe
				ref={ iframeRef }
				title={ __( 'Live preview', 'woocommerce-orders-invoice-pdf' ) }
				hidden={ 'html' !== tab }
				style={ { flex: '1', width: '100%', border: '0', background: '#fff', minHeight: '60vh' } }
			/>
			<div className="woi-block-pdf" hidden={ 'pdf' !== tab } style={ { flex: '1', display: 'flex', flexDirection: 'column', minHeight: '60vh' } }>
				<div style={ { padding: '8px', display: 'flex', gap: '8px', alignItems: 'center', flexWrap: 'wrap' } }>
					<button type="button" className="button button-primary" onClick={ renderPdf }>{ __( 'Render PDF', 'woocommerce-orders-invoice-pdf' ) }</button>
					<span aria-live="polite">{ pdfStatus }</span>
					{ 'blocks' !== source ? (
						<span style={ { color: '#b32d2e' } }>{ __( 'PDF reflects the active source. Set “PDF source” to “Block editor” above to preview the block design.', 'woocommerce-orders-invoice-pdf' ) }</span>
					) : null }
				</div>
				<div className="woi-a4-scroll" style={ { flex: '1', overflow: 'auto', background: '#525659', padding: '16px', display: 'flex', flexDirection: 'column', alignItems: 'center', gap: '16px' } }>
					<div className="woi-a4-stage" ref={ stageRef } style={ { width: 'min(100%, 820px)', display: 'flex', flexDirection: 'column', alignItems: 'stretch', gap: '16px' } } />
				</div>
			</div>
		</div>
	);
}
```

- [ ] **Step 4: Pass `source` from index.js**

In `src/block-editor/index.js`, change the PreviewPanel render to pass the current source:

```jsx
				<PreviewPanel blocks={ blocks } source={ source } />
```

(`source` already exists in the `Editor` component state from Slice 1.)

- [ ] **Step 5: Commit (build in Task 3)**

```bash
git add src/block-editor/pdfPreview.js src/block-editor/PreviewPanel.js src/block-editor/index.js
git commit -m "feat(visual): A4 PDF.js preview tab in block editor"
```

---

## Task 3: Build, verify, version bump

**Files:**
- Modify (built output): `assets/js/block-editor/index.js`, `assets/js/block-editor/index.asset.php`
- Modify: `woocommerce-orders-invoice-pdf.php` (version)

- [ ] **Step 1: Build**

Run: `npm run build`
Expected: `webpack … compiled successfully`; `assets/js/block-editor/index.js` + `index.asset.php` emitted; `assets/js/home/index.js` still present.
> If it fails on a JSX compile error, a Task-2 file is broken — report BLOCKED with the exact error + file:line. If `node_modules` is missing, `npm install` first.

- [ ] **Step 2: Sibling-asset safety check**

Run: `git status --short assets/js`
Expected: only `block-editor/*` (and maybe `home/index.*`) modified; NO deletions (` D `) of `admin.js`, `pdf_js/*`, `order-script.js`, etc.

- [ ] **Step 3: Confirm the PDF tab compiled in**

Run: `grep -c "woi_pdf_preview\|woi-a4-stage\|pdfWorkerUrl" assets/js/block-editor/index.js`
Expected: non-zero.

- [ ] **Step 4: Read the TRUE current version from origin/master (coordination)**

Run: `git fetch origin && git show origin/master:woocommerce-orders-invoice-pdf.php | grep -m1 "Version:"`
Note the value. The new version is the next patch above it — do NOT assume.

- [ ] **Step 5: Bump the version in both places**

In `woocommerce-orders-invoice-pdf.php`, set line 6 (`* Version:`) and line 24 (`public string $version`) to the next patch above the Step-4 value (both identical).

- [ ] **Step 6: Commit**

```bash
git add assets/js/block-editor woocommerce-orders-invoice-pdf.php
git commit -m "build(visual): rebuild bundle with PDF preview tab; bump version"
```

> Update the `version-coordination` memory's "current released version" line after this branch is pushed.

---

## Task 4: Live acceptance (manual — user, requires deploy + a VISIBLE browser tab)

**Files:** none (verification only)

> CRITICAL (from the `grapesjs-next-steps` memory): PDF.js `page.render()` HANGS while the tab is hidden/background. Test in a foregrounded, visible browser tab.

- [ ] **Step 1: Deploy** (manual git pull) so the rebuilt bundle + PDF.js are served.

- [ ] **Step 2: Prerequisites.** In Invoice Settings, ensure **Visual template (invoice)** is ON. In the Block editor toolbar, set **PDF source → Block editor**.

- [ ] **Step 3: Render.** In PDF Invoices → **Block Editor**, click the **PDF** tab. Confirm it renders the real mPDF invoice as crisp **A4 canvas** page(s) on a grey backdrop (ratio ≈ 0.7071), status clears (~8s), and the design matches your blocks (line items, totals, Arabic shaped). Click **Render PDF** again → exactly one set of canvases (gen-guard holds, no duplicate/error).

- [ ] **Step 4: Order + edits.** Pick a different order (search → Find → select) and confirm the PDF re-renders for that order. Edit a block, switch to PDF, re-render — change reflected.

- [ ] **Step 5: Source hint.** Set **PDF source → GrapesJS**; confirm the red hint appears in the PDF tab (and the PDF then reflects the GrapesJS/active design). Set back to **Block editor**.

- [ ] **Step 6: No regressions.** The Live HTML tab still works; the GrapesJS editor's own PDF tab still works.

Expected: an A4 PDF.js preview of the real mPDF output, gen-guarded, order-aware, with the source hint — matching the GrapesJS PDF tab.

---

## Self-Review

**Spec coverage (Slice 3 Part B scope):**
- A4 PDF.js PDF tab reusing `woi_pdf_preview` + vendored PDF.js → Tasks 1–2. ✓
- Gen-guard concurrency + task.destroy on all paths → `pdfPreview.js` (ported verbatim from GrapesJS). ✓
- Save-to-preview (PDF tab saves block markup; Live HTML stays read-only) → `renderPdfPreview` calls `saveBlocks`; the Live HTML effect is unchanged. ✓
- PDF reflects active source + hint when source ≠ blocks → PreviewPanel `source` prop + hint. ✓
- PDF.js enqueued + worker localized → Task 1. ✓
- Build + coordination-safe version bump → Task 3. ✓
- Layout modes → **deferred to Slice 3 Part C** (separate plan). ✓ (intentional scope boundary)
- Live acceptance (visible-tab caveat) → Task 4 (user). ✓

**Placeholder scan:** None. All code complete; the version literal resolves at execution time from `origin/master` (Task 3 Step 4).

**Type/name consistency:** `renderPdfPreview` is defined in `pdfPreview.js` and imported by `PreviewPanel.js` with the same name and arg shape (`{ stageEl, blocks, orderId, onStatus }`). `PreviewPanel` gains a `source` prop, passed from `index.js` where `source` already exists in `Editor` state. `window.woiBlocks` keys used (`pdfWorkerUrl`, `ajaxUrl`, `previewNonce`, `docType`) are localized in Task 1 + Part A. The admin-ajax action `woi_pdf_preview`, the `security`/`document_type`/`order_id` params, and the `{success,data:{preview_data,output_format}}` response shape mirror the GrapesJS `app.js` and the `Settings::ajax_preview` handler exactly. The `.woi-a4-page/.woi-a4-scroll/.woi-a4-stage` styles match `assets/visual-editor/editor.css`. ✓
