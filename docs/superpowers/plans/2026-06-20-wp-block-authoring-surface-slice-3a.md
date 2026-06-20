# WP Block Authoring Surface — Slice 3 Part A (Live HTML Preview + Order Picker) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the block editor a live preview panel: a side iframe that renders the current block design merged with real order data (or sample data), plus an order-search picker — reusing the GrapesJS editor's existing endpoints and shared document CSS.

**Architecture:** The preview is built natively in the block editor's React app (it already owns the `blocks` state). On each (debounced) change, it serializes the blocks, strips the WP block-delimiter comments to get the rendered HTML (our blocks are static, so the comment-stripped serialization IS the save() HTML carrying `{{tokens}}`), client-side substitutes the selected order's token values (fetched from the existing `visual-preview-data` REST route), and writes the result into an `<iframe srcdoc>` wrapped with the shared `visual-document.css`. The order picker reuses the existing `woi_pdf_preview_order_search` AJAX action. No GrapesJS code is touched; the GrapesJS preview keeps working unchanged.

**Tech Stack:** `@wordpress/scripts` 30, `@wordpress/element` (React) + `@wordpress/blocks` (`serialize`) + `@wordpress/i18n`, PHP 7.4, PHPUnit 9.

**Scope note:** This is **Slice 3 Part A** — the **Live HTML** preview + order picker only. The **A4 PDF.js PDF tab** (Part B) and the **full/stack/overlay layout modes** (Part C) are separate, heavier sub-features and get their own plans. Part A is independently shippable: it adds a read-only preview that changes nothing about saving, rendering, or the active-source flow. It builds on Slices 1, 2A, 2B (all on `origin/master`).

## Global Constraints

- **Invoice-only**; render engine stays mPDF; render-path/REST/storage/build-config untouched. GrapesJS (`assets/visual-editor/*`) is NOT modified.
- **Reuse, don't duplicate:** consume the existing endpoints — `GET woi-pdf/v1/visual-preview-data` (returns `{ tokens: {"{{k}}":"v",…}, order_label }`) and the `woi_pdf_preview_order_search` admin-ajax action (POST `action`,`security`(=`woi_pdf_preview` nonce),`document_type`,`search` → `{success, data:{ id:{order_number,billing_company,billing_first_name,billing_last_name,total_raw,line_count,unit_count,date_raw,payment_method} } }`). Reuse the shared `woi_pdf_visual_document_css()` for the iframe CSS. Do NOT add a second copy of the document CSS.
- **Preview is read-only:** it must NOT save, must NOT change the active source, must NOT reserve invoice numbers (the `visual-preview-data` route is already read-only).
- **Token merge is client-side** and mirrors the GrapesJS logic exactly: replace each `{{token}}` key with its value, then strip any leftover `{{…}}`.
- **Version bump is a shared, collision-prone resource.** Before bumping you MUST `git fetch origin` and read the TRUE `origin/master` value, then take the next patch above it. Set BOTH line 6 and line 24 of `woocommerce-orders-invoice-pdf.php`. (See the `version-coordination` memory.)
- **Run PHPUnit as:** `php -d display_errors=1 -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit`. NEVER `vendor/bin/phpunit`.
- **Working tree is the git worktree** at `.claude/worktrees/wp-block-slice-3` on branch `worktree-wp-block-slice-3`, based on `origin/master`. All commands run there. Integrate by **fast-forward push to `origin/master`** — never check out `master` in the shared main checkout.

---

## File Structure

**Create:**
- `src/block-editor/preview.js` — pure preview helpers (serialize→strip, token merge, iframe-doc wrap, order/preview-data fetch). No JSX.
- `src/block-editor/PreviewPanel.js` — the React preview panel (order search + Live HTML iframe).
- `tests/Unit/Visual/VisualSampleDataTest.php` — unit test for the shared sample-data helper.

**Modify:**
- `includes/Visual/functions.php` — add `woi_pdf_visual_sample_data(): array` (shared sample token map).
- `includes/Visual/VisualEditorPage.php` — `sample_data()` returns the shared helper (DRY; no behavior change).
- `includes/Visual/BlockEditorPage.php` — extend the `woiBlocks` localize with the preview keys.
- `src/block-editor/index.js` — render `<PreviewPanel blocks={blocks} />` beside the editor.
- `woocommerce-orders-invoice-pdf.php` — version bump.

---

## Task 1: Shared sample-data helper + BlockEditorPage preview localize

**Files:**
- Modify: `includes/Visual/functions.php`, `includes/Visual/VisualEditorPage.php`, `includes/Visual/BlockEditorPage.php`
- Test: `tests/Unit/Visual/VisualSampleDataTest.php` (create)

**Interfaces:**
- Produces: `WOI\PDF\Visual\woi_pdf_visual_sample_data(): array` — the `{{token}} => sample value` map; consumed by both editor pages' localize.
- The block editor's `window.woiBlocks` gains: `ajaxUrl`, `previewNonce`, `previewCss`, `sampleData`, `previewDataUrl`, `orderSearchAction` (Task 2/3 consume these).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Visual/VisualSampleDataTest.php`:

```php
<?php
namespace WOI\PDF\Tests\Unit\Visual;

use PHPUnit\Framework\TestCase;
use function WOI\PDF\Visual\woi_pdf_visual_sample_data;

class VisualSampleDataTest extends TestCase {

    public function test_sample_data_has_token_keys_and_values(): void {
        $data = woi_pdf_visual_sample_data();
        $this->assertIsArray( $data );
        // Keyed by {{token}} braces, mirroring TemplateTokens::map output keys.
        $this->assertArrayHasKey( '{{shop_name}}', $data );
        $this->assertArrayHasKey( '{{document_title}}', $data );
        $this->assertArrayHasKey( '{{line_items}}', $data );
        $this->assertArrayHasKey( '{{totals}}', $data );
        // Values are strings; line_items carries table markup.
        $this->assertIsString( $data['{{shop_name}}'] );
        $this->assertStringContainsString( '<table', $data['{{line_items}}'] );
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php -d display_errors=1 -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit --filter VisualSampleDataTest`
Expected: FAIL — undefined function `WOI\PDF\Visual\woi_pdf_visual_sample_data`.

- [ ] **Step 3: Add the shared helper**

In `includes/Visual/functions.php`, add (after `visual_template_active()`):

```php
/**
 * Static sample token map for the in-editor preview (browser-only, approximate).
 * Keyed by {{token}} braces to match TemplateTokens::map output, so the same
 * client-side token-merge works against sample data and real order data alike.
 *
 * @return array<string,string>
 */
function woi_pdf_visual_sample_data(): array {
    return array(
        '{{shop_name}}'         => 'Acme Trading LLC',
        '{{shop_address}}'      => 'Office 12, Dubai, UAE',
        '{{shop_name_ar}}'      => 'أكمي للتجارة',
        '{{shop_address_ar}}'   => 'مكتب ١٢، دبي',
        '{{trn}}'               => '100123456700003',
        '{{shop_phone}}'        => '+971 4 000 0000',
        '{{shop_email}}'        => 'billing@acme.example',
        '{{logo}}'              => '',
        '{{document_title}}'    => 'Tax Invoice',
        '{{document_title_ar}}' => 'فاتورة ضريبية',
        '{{billing_address}}'   => 'John Buyer<br>Abu Dhabi, UAE',
        '{{invoice_number}}'    => 'INV-001',
        '{{invoice_date}}'      => '18 June 2026',
        '{{order_number}}'      => '4242',
        '{{payment_method}}'    => 'Credit Card',
        '{{line_items}}'        => '<table class="order-details"><thead><tr><th>Item</th><th>Qty</th><th>Price</th></tr></thead><tbody><tr><td>Widget</td><td>2</td><td>AED 50</td></tr></tbody></table>',
        '{{totals}}'            => '<table class="totals-table"><tr><th>Total</th><td>AED 100</td></tr></table>',
    );
}
```

- [ ] **Step 4: Point VisualEditorPage at the shared helper (DRY)**

In `includes/Visual/VisualEditorPage.php`, replace the body of the private `sample_data()` method so it returns the shared helper (keeps the existing call site working, removes the duplicate array):

```php
    /** Static sample values for the in-editor preview (browser-only, approximate). */
    private function sample_data(): array {
        return woi_pdf_visual_sample_data();
    }
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php -d display_errors=1 -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit --filter VisualSampleDataTest`
Expected: PASS (1 test).

- [ ] **Step 6: Extend the BlockEditorPage localize**

In `includes/Visual/BlockEditorPage.php`, inside `enqueue()`, replace the `wp_localize_script( 'woi-block-editor', 'woiBlocks', array( … ) )` array with this superset (keeps the existing keys, adds the preview keys):

```php
        wp_localize_script( 'woi-block-editor', 'woiBlocks', array(
            'restUrl'           => esc_url_raw( rest_url( 'woi-pdf/v1' ) ),
            'nonce'             => wp_create_nonce( 'wp_rest' ),
            'docType'           => 'invoice',
            'storedMarkup'      => $store->get_blocks_markup( 'invoice' ),
            'activeSource'      => $store->get_active_source(),
            'backUrl'           => esc_url_raw( admin_url( 'admin.php?page=woi_pdf_options_page' ) ),
            // --- Live preview (Slice 3A) ---
            'ajaxUrl'           => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
            'previewNonce'      => wp_create_nonce( 'woi_pdf_preview' ),
            'previewCss'        => woi_pdf_visual_document_css(),
            'sampleData'        => woi_pdf_visual_sample_data(),
            'previewDataUrl'    => esc_url_raw( rest_url( 'woi-pdf/v1/visual-preview-data' ) ),
            'orderSearchAction' => 'woi_pdf_preview_order_search',
        ) );
```

- [ ] **Step 7: Lint + full suite**

Run: `php -l includes/Visual/BlockEditorPage.php && php -l includes/Visual/functions.php && php -l includes/Visual/VisualEditorPage.php && php -d display_errors=1 -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit`
Expected: no syntax errors; suite PASS, 0 errors (1 intentional skip OK).

- [ ] **Step 8: Commit**

```bash
git add includes/Visual/functions.php includes/Visual/VisualEditorPage.php includes/Visual/BlockEditorPage.php tests/Unit/Visual/VisualSampleDataTest.php
git commit -m "feat(visual): share sample-data helper; localize preview data for block editor"
```

---

## Task 2: Preview helpers + React PreviewPanel + editor wiring

**Files:**
- Create: `src/block-editor/preview.js`, `src/block-editor/PreviewPanel.js`
- Modify: `src/block-editor/index.js`

**Interfaces:**
- Consumes: `window.woiBlocks` preview keys (Task 1), `@wordpress/blocks` `serialize`, `@wordpress/element`.
- Produces: `PreviewPanel` (default export) rendered beside the editor; pure helpers in `preview.js`.

- [ ] **Step 1: Create the preview helpers (no JSX)**

Create `src/block-editor/preview.js`:

```js
import { serialize } from '@wordpress/blocks';

// Preview-only shim layered on top of the shared visual-document CSS. The iframe
// is a scrolling document, not paged media: simulate the 15mm page margin and
// centre an A4-width "page"; show page breaks as a dashed divider. (Ported from
// the GrapesJS editor's PREVIEW_SHIM_CSS so both previews look identical.)
const SHIM_CSS =
	'body{width:210mm;max-width:100%;margin:0 auto !important;padding:15mm;box-sizing:border-box;background:#fff}' +
	'.woi-pagebreak{border-top:1px dashed #999;margin:4mm 0;height:auto;page-break-after:auto}';
const FALLBACK_CSS =
	'table{border-collapse:collapse;width:100%}' +
	'.order-details th,.order-details td{border:0.5pt solid #000;padding:0.375em}' +
	'.woi-lbl-primary,.woi-lbl-secondary{display:inline}.woi-lbl-secondary{direction:rtl}';

// Our blocks are static, so serialize() emits the save() HTML wrapped in WP
// block-delimiter comments; stripping the comments yields the rendered HTML
// carrying the {{tokens}} — the block editor's equivalent of getHtml().
export function renderedHtmlFromBlocks( blocks ) {
	return serialize( blocks || [] ).replace( /<!--\s*\/?wp:[\s\S]*?-->/g, '' );
}

export function mergeTokens( html, tokens ) {
	let out = html;
	if ( tokens ) {
		Object.keys( tokens ).forEach( ( k ) => { out = out.split( k ).join( tokens[ k ] ); } );
	}
	return out.replace( /\{\{\s*[a-z0-9_]+\s*\}\}/gi, '' );
}

export function wrapForPreview( bodyHtml ) {
	const docCss = ( window.woiBlocks && window.woiBlocks.previewCss ) ? window.woiBlocks.previewCss : FALLBACK_CSS;
	return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>' + docCss + SHIM_CSS + '</style></head><body>' + ( bodyHtml || '' ) + '</body></html>';
}

// GET the selected order's token map (read-only; falls back to null on error).
export function fetchOrderTokens( orderId ) {
	const w = window.woiBlocks || {};
	let url = w.previewDataUrl + '?doc_type=' + encodeURIComponent( w.docType );
	if ( orderId ) { url += '&order_id=' + encodeURIComponent( orderId ); }
	return fetch( url, { headers: { 'X-WP-Nonce': w.nonce }, credentials: 'same-origin', cache: 'no-store' } )
		.then( ( r ) => ( r.ok ? r.json() : null ) )
		.catch( () => null );
}

// POST the order-search admin-ajax action; returns the { id: data } map or {}.
export function fetchOrders( term ) {
	const w = window.woiBlocks || {};
	const body = 'action=' + encodeURIComponent( w.orderSearchAction ) +
		'&security=' + encodeURIComponent( w.previewNonce ) +
		'&document_type=' + encodeURIComponent( w.docType ) +
		'&search=' + encodeURIComponent( term || '' );
	return fetch( w.ajaxUrl, {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		credentials: 'same-origin',
		body,
	} ).then( ( r ) => r.json() )
		.then( ( res ) => ( res && res.success && res.data ) ? res.data : {} )
		.catch( () => ( {} ) );
}

export function orderRowTitle( d ) {
	let name = ( d.billing_company || '' ).trim();
	if ( ! name ) { name = ( ( d.billing_first_name || '' ) + ' ' + ( d.billing_last_name || '' ) ).trim(); }
	if ( ! name ) { name = '(no name)'; }
	return '#' + ( d.order_number || '' ) + ' — ' + name;
}
```

- [ ] **Step 2: Syntax-check the helpers**

Run: `node --check src/block-editor/preview.js`
Expected: clean (no JSX in this file — a real `node --check` gate).

- [ ] **Step 3: Create the React PreviewPanel**

Create `src/block-editor/PreviewPanel.js`:

```js
import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { renderedHtmlFromBlocks, mergeTokens, wrapForPreview, fetchOrderTokens, fetchOrders, orderRowTitle } from './preview';

export default function PreviewPanel( { blocks } ) {
	const iframeRef = useRef( null );
	const [ tokens, setTokens ] = useState( () => ( window.woiBlocks && window.woiBlocks.sampleData ) || null );
	const [ orderLabel, setOrderLabel ] = useState( '' );
	const [ results, setResults ] = useState( null );
	const [ term, setTerm ] = useState( '' );

	// Re-render the iframe (debounced) on block or token changes.
	useEffect( () => {
		const t = setTimeout( () => {
			const frame = iframeRef.current;
			if ( frame ) {
				frame.srcdoc = wrapForPreview( mergeTokens( renderedHtmlFromBlocks( blocks ), tokens ) );
			}
		}, 400 );
		return () => clearTimeout( t );
	}, [ blocks, tokens ] );

	// Load the last order's tokens on mount.
	useEffect( () => {
		fetchOrderTokens( null ).then( ( res ) => {
			if ( res && res.tokens ) {
				setTokens( res.tokens );
				if ( res.order_label ) { setOrderLabel( res.order_label ); }
			}
		} );
	}, [] );

	const onSearch = useCallback( () => {
		fetchOrders( term ).then( ( data ) => setResults( data ) );
	}, [ term ] );

	const onPick = useCallback( ( id, label ) => {
		setResults( null );
		setTerm( label );
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
				<strong>{ __( 'Live preview', 'woocommerce-orders-invoice-pdf' ) }</strong>
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
				style={ { flex: '1', width: '100%', border: '0', background: '#fff', minHeight: '60vh' } }
			/>
		</div>
	);
}
```

- [ ] **Step 4: Wire PreviewPanel into the editor**

In `src/block-editor/index.js`:

(a) add the import after the other imports:

```js
import PreviewPanel from './PreviewPanel';
```

(b) change the Editor component's returned root so the editor and the preview sit side by side. Replace the opening `<div className="woi-block-shell">` and its closing `</div>` so the existing toolbar + `<BlockEditorProvider>…</BlockEditorProvider>` are wrapped in a left column and `<PreviewPanel>` is the right column:

```jsx
		return (
			<div className="woi-block-shell" style={ { display: 'flex', gap: '0', alignItems: 'stretch', minHeight: '70vh' } }>
				<div className="woi-block-main" style={ { flex: '1.3', minWidth: '0', paddingRight: '8px' } }>
					<div className="woi-block-toolbar" style={ { display: 'flex', gap: '8px', alignItems: 'center', marginBottom: '8px' } }>
						<Button variant="primary" onClick={ onSave }>{ __( 'Save', 'woocommerce-orders-invoice-pdf' ) }</Button>
						<label>{ __( 'PDF source:', 'woocommerce-orders-invoice-pdf' ) }</label>
						<select value={ source } onChange={ ( e ) => onSource( e.target.value ) }>
							<option value="grapesjs">{ __( 'GrapesJS', 'woocommerce-orders-invoice-pdf' ) }</option>
							<option value="blocks">{ __( 'Block editor', 'woocommerce-orders-invoice-pdf' ) }</option>
						</select>
						<span aria-live="polite">{ status }</span>
					</div>
					<BlockEditorProvider value={ blocks } onInput={ setBlocks } onChange={ setBlocks }>
						<div className="woi-block-canvas" style={ { border: '1px solid #ddd', background: '#fff', minHeight: '60vh' } }>
							<BlockTools>
								<div style={ { padding: '8px' } }><Inserter rootClientId={ undefined } isAppender /></div>
								<WritingFlow>
									<ObserveTyping>
										<BlockList />
									</ObserveTyping>
								</WritingFlow>
							</BlockTools>
						</div>
					</BlockEditorProvider>
				</div>
				<PreviewPanel blocks={ blocks } />
			</div>
		);
```

> IMPORTANT: preserve the EXACT existing JSX nesting of `BlockTools`/`WritingFlow`/`ObserveTyping`/`BlockList` from the current file — only wrap the shell in the flex row and add `<PreviewPanel>`. Do not reorder those components. (The block above shows the intended final structure; if the current nesting differs, keep the current nesting and only add the flex wrapper + PreviewPanel.)

- [ ] **Step 5: Commit (build happens in Task 3)**

```bash
git add src/block-editor/preview.js src/block-editor/PreviewPanel.js src/block-editor/index.js
git commit -m "feat(visual): live HTML preview panel + order picker in block editor"
```

---

## Task 3: Build, verify, version bump

**Files:**
- Modify (built output): `assets/js/block-editor/index.js`, `assets/js/block-editor/index.asset.php`
- Modify: `woocommerce-orders-invoice-pdf.php` (version)

**Interfaces:**
- Consumes: Task-2 source + existing `webpack.config.js` (`clean:false` — DO NOT change).
- Produces: rebuilt bundle including the preview panel; coordination-safe version bump.

- [ ] **Step 1: Build**

Run: `npm run build`
Expected: `webpack … compiled successfully`; `assets/js/block-editor/index.js` + `index.asset.php` emitted; `assets/js/home/index.js` still present.
> If it fails on a JSX compile error, a Task-2 file is broken — report BLOCKED with the exact error + file:line. If `node_modules` is missing, run `npm install` first.

- [ ] **Step 2: Sibling-asset safety check**

Run: `git status --short assets/js`
Expected: only `block-editor/*` (and maybe `home/index.*`) modified; NO deletions (` D `) of `admin.js`, `pdf_js/*`, `order-script.js`, etc.

- [ ] **Step 3: Confirm the preview compiled in**

Run: `grep -c "previewDataUrl\|orderSearchAction\|woi-block-preview" assets/js/block-editor/index.js`
Expected: non-zero.

- [ ] **Step 4: Read the TRUE current version from origin/master (coordination)**

Run: `git fetch origin && git show origin/master:woocommerce-orders-invoice-pdf.php | grep -m1 "Version:"`
Note the value. The new version is the next patch above it — do NOT assume; another instance may have advanced it.

- [ ] **Step 5: Bump the version in both places**

In `woocommerce-orders-invoice-pdf.php`, set line 6 (`* Version:`) and line 24 (`public string $version`) to the next patch above the Step-4 value (both identical).

- [ ] **Step 6: Commit**

```bash
git add assets/js/block-editor woocommerce-orders-invoice-pdf.php
git commit -m "build(visual): rebuild bundle with live preview; bump version"
```

> Update the `version-coordination` memory's "current released version" line after this branch is pushed.

---

## Task 4: Live acceptance (manual — user, requires deploy)

**Files:** none (verification only)

- [ ] **Step 1: Deploy** the branch to the live site (manual git pull) so the rebuilt bundle is served.

- [ ] **Step 2: Preview renders.** In wp-admin → PDF Invoices → **Block Editor**, confirm a preview panel appears beside the canvas and shows the current design rendered (last order's data on load). Edit a block (e.g. type in a Heading) and confirm the preview updates within ~0.5s.

- [ ] **Step 3: Token resolution.** Confirm tokens resolve to real values (no raw `{{shop_name}}` etc.), the line-items/totals tables render, and Arabic text displays correctly (shaped) in the preview.

- [ ] **Step 4: Order picker.** Type an order number/name in the preview search, click **Find**, pick a result; confirm the preview re-renders with that order's data and the "Order:" label updates. Clear the box + Find (blank) returns recent orders.

- [ ] **Step 5: No side effects.** Confirm previewing does NOT change the saved template or the active source (open the PDF source selector — unchanged; reload without saving — design intact). The GrapesJS editor's own preview still works (open the Visual Template tab).

Expected: a live preview that tracks edits and order selection, read-only, with Arabic intact — matching the GrapesJS Live HTML tab.

> Watch-point (no automated oracle): block-delimiter stripping must yield clean HTML for nested Columns blocks too — confirm a Columns/Header-row design previews as a table row (not raw comments / not empty).

---

## Self-Review

**Spec coverage (Slice 3 Part A scope):**
- Live HTML preview reusing `visual-preview-data` → Tasks 1–2 (localize + `fetchOrderTokens` + client merge + iframe). ✓
- Order-search picker reusing `woi_pdf_preview_order_search` → Task 2 (`fetchOrders` + PreviewPanel UI). ✓
- Shared document CSS reused (no second copy) → Task 1 localizes `woi_pdf_visual_document_css()`; preview.js falls back only if absent. ✓
- Read-only (no save / no source change / no number reservation) → uses only the read-only preview-data + order-search endpoints. ✓
- Sample-data DRY (shared helper, VisualEditorPage refactored) → Task 1. ✓
- Build + coordination-safe version bump → Task 3. ✓
- A4 PDF.js tab + layout modes → **deferred to Slice 3 Parts B/C** (separate plans). ✓ (intentional scope boundary, stated up front)
- Live acceptance → Task 4 (user). ✓

**Placeholder scan:** None. All code complete; the version literal is intentionally resolved at execution time from `origin/master` (Task 3 Step 4).

**Type/name consistency:** `preview.js` exports (`renderedHtmlFromBlocks`, `mergeTokens`, `wrapForPreview`, `fetchOrderTokens`, `fetchOrders`, `orderRowTitle`) are imported with identical names in `PreviewPanel.js`. `PreviewPanel` (default export) is imported and rendered in `index.js` with prop `blocks`. The `window.woiBlocks` keys localized in Task 1 (`ajaxUrl`, `previewNonce`, `previewCss`, `sampleData`, `previewDataUrl`, `orderSearchAction`, plus existing `nonce`/`docType`) exactly match the keys read in `preview.js`. The shared `woi_pdf_visual_sample_data()` (namespace `WOI\PDF\Visual`) is consumed by both `BlockEditorPage` and `VisualEditorPage`. Token-merge and order-data field names (`order_number`, `billing_company`, `billing_first_name`, `billing_last_name`) mirror the GrapesJS `app.js` source exactly. ✓
