# Visual Editor Enhancements (Slice 2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add three cohesive enhancements to the invoice GrapesJS visual editor — block-set hardening, real-order in-editor preview, and adjustable title spacing — without changing the render engine, token-merge core, or storage.

**Architecture:** Mostly `assets/visual-editor/app.js` (GrapesJS app) and `includes/Visual/VisualEditorPage.php`, plus one new read-only REST endpoint (`visual-preview-data`) that reuses `TemplateTokens::map()`, small CSS additions to `templates/_visual/visual-document-wrapper.php`, and a starter-template restructure. Every new editor control emits plain HTML/CSS that mPDF already understands.

**Tech Stack:** PHP 8.1+, WordPress/WooCommerce, GrapesJS 0.21.13 (vendored), mPDF (via existing render path), PHPUnit 9.6 + Brain Monkey.

## Global Constraints

- PHP floor **8.1**. Each PHP file starts with `if ( ! defined( 'ABSPATH' ) ) exit;` after the namespace (class files); `class_exists`/`endif` guard for classes.
- Canonical test command (PHP 8.4; `display_errors` REQUIRED or phpunit prints nothing; do NOT use `vendor/bin/phpunit`):
  `php -d display_errors=1 -d error_reporting=E_ALL -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit`
  Current green baseline before this slice: **172 tests / 0 errors / 1 skipped**.
- Brain Monkey CANNOT stub functions already defined in `woi-pdf-functions.php` (e.g. `woi_pdf_templates_get_*`) — Patchwork throws `DefinedTooEarly`. WP-core functions (`current_user_can`, `wc_get_order`, `apply_filters`, etc.) are NOT defined in the test env, so Brain Monkey CAN stub them.
- The new REST route MUST be registered **unconditionally** (not behind the debug gate) — extend the existing `Rest::register_visual_template_route()` which is already hooked unconditionally in the constructor.
- The preview endpoint is **read-only**: never call `SequentialNumberStore::get_next()` or anything that reserves/increments a number.
- Required-token set (validation): `line_items`, `totals`, `invoice_number`, `invoice_date`, `billing_address`. Save must NEVER be blocked by validation.
- Token block content is unchanged: `<span data-woi-token="TOKEN">{{TOKEN}}</span>`.
- Bump `WOI_PDF_VERSION` (in `woocommerce-orders-invoice-pdf.php`, both the header `Version:` and the `$version` property) when shipping new JS/CSS.
- Branch: `feat/visual-editor-enhancements` (spec already committed there).
- JS has no in-repo test harness; JS tasks use live verification via the harness (debug Chrome on port 9222 + puppeteer-core in `%TEMP%\woi-cdp` + PyMuPDF). Deploy is a manual `git pull` on the shared server; confirm the deployed revision on the **Status** tab before testing.

## File structure

- `includes/Rest.php` — MODIFY: register + handle `GET visual-preview-data`; add protected `token_map()` seam.
- `tests/Unit/Visual/VisualPreviewDataTest.php` — CREATE.
- `includes/Visual/VisualEditorPage.php` — MODIFY: order control bar markup + `woiVisual` keys (`previewDataUrl`, `orderSearchNonce`, `orderSearchAction`).
- `assets/visual-editor/app.js` — MODIFY: token metadata + grouped palette; layout/page-break blocks; keep-together style sector; required-token warning; real-order preview wiring.
- `templates/_visual/visual-document-wrapper.php` — MODIFY: `.woi-pagebreak`, `.woi-spacer`, `.totals-table` page-break guard, `.woi-doc-title` defaults.
- `assets/visual-editor/starter-invoice.html` — MODIFY: two styleable title elements.
- `woocommerce-orders-invoice-pdf.php` — MODIFY: version bump.

---

### Task 1: `visual-preview-data` REST endpoint

**Files:**
- Modify: `includes/Rest.php`
- Test: `tests/Unit/Visual/VisualPreviewDataTest.php`

**Interfaces:**
- Consumes: `current_user_can`, `wc_get_order`, `wc_get_orders`, `woi_pdf_get_document`, `apply_filters`/`add_filter`/`remove_filter`, `\WOI\PDF\Visual\TemplateTokens::map()`.
- Produces:
  - Route `GET woi-pdf/v1/visual-preview-data` (params `order_id` int optional, `doc_type` string optional).
  - `Rest::handle_visual_preview_data($request): array|\WP_Error` → `array{ order_id:int, order_label:string, tokens:array<string,string> }`; `WP_Error` 403 (cap) / 404 (no order/document).
  - `protected Rest::token_map($document): array` — wraps `( new TemplateTokens() )->map($document)` so tests can override it.

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace WOI\PDF\Tests\Unit\Visual;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Rest;

class VisualPreviewDataTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Rest constructor reads debug settings + hooks actions.
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'remove_filter' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Rest subclass that overrides the TemplateTokens seam with a fixture map. */
	private function rest_with_map( array $map ): Rest {
		return new class( $map ) extends Rest {
			private array $map;
			public function __construct( array $map ) { $this->map = $map; parent::__construct(); }
			protected function token_map( $document ): array { return $this->map; }
		};
	}

	private function stub_order( int $id ) {
		return new class( $id ) {
			private int $id;
			public function __construct( $id ) { $this->id = $id; }
			public function get_order_number() { return (string) $this->id; }
			public function get_billing_first_name() { return 'John'; }
			public function get_billing_last_name() { return 'Buyer'; }
		};
	}

	private function request( array $params ) {
		return new class( $params ) {
			private array $p;
			public function __construct( $p ) { $this->p = $p; }
			public function get_param( $k ) { return $this->p[ $k ] ?? null; }
		};
	}

	public function test_returns_token_map_for_explicit_order(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wc_get_order' )->justReturn( $this->stub_order( 239 ) );
		Functions\when( 'woi_pdf_get_document' )->justReturn( new \stdClass() );

		$rest   = $this->rest_with_map( array( '{{line_items}}' => '<table></table>' ) );
		$result = $rest->handle_visual_preview_data( $this->request( array( 'order_id' => 239, 'doc_type' => 'invoice' ) ) );

		$this->assertSame( 239, $result['order_id'] );
		$this->assertStringContainsString( '#239', $result['order_label'] );
		$this->assertStringContainsString( 'John Buyer', $result['order_label'] );
		$this->assertSame( '<table></table>', $result['tokens']['{{line_items}}'] );
	}

	public function test_defaults_to_last_order_when_no_id(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wc_get_orders' )->justReturn( array( 512 ) );
		Functions\when( 'wc_get_order' )->justReturn( $this->stub_order( 512 ) );
		Functions\when( 'woi_pdf_get_document' )->justReturn( new \stdClass() );

		$rest   = $this->rest_with_map( array() );
		$result = $rest->handle_visual_preview_data( $this->request( array() ) );

		$this->assertSame( 512, $result['order_id'] );
	}

	public function test_404_when_no_order_found(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wc_get_orders' )->justReturn( array() );
		Functions\when( 'wc_get_order' )->justReturn( false );

		$rest   = $this->rest_with_map( array() );
		$result = $rest->handle_visual_preview_data( $this->request( array() ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_403_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$rest   = $this->rest_with_map( array() );
		$result = $rest->handle_visual_preview_data( $this->request( array( 'order_id' => 1 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d display_errors=1 -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit tests/Unit/Visual/VisualPreviewDataTest.php`
Expected: FAIL — `handle_visual_preview_data` / `token_map` not defined.

- [ ] **Step 3: Add the route registration**

In `includes/Rest.php`, find `register_visual_template_route()` (added in slice 1). Add the second route inside that same method, after the existing `register_rest_route( 'woi-pdf/v1', '/visual-template', … )` call:

```php
register_rest_route( 'woi-pdf/v1', '/visual-preview-data', array(
	'methods'             => 'GET',
	'callback'            => array( $this, 'handle_visual_preview_data' ),
	'permission_callback' => function () {
		return current_user_can( 'manage_woocommerce' );
	},
	'args'                => array(
		'order_id' => array( 'type' => 'integer', 'required' => false ),
		'doc_type' => array( 'type' => 'string', 'required' => false ),
	),
) );
```

- [ ] **Step 4: Add the handler + seam**

Add these methods to the `Rest` class (near `handle_visual_template_save`):

```php
public function handle_visual_preview_data( $request ) {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return new \WP_Error( 'forbidden', 'Insufficient permissions', array( 'status' => 403 ) );
	}

	$doc_type = $request->get_param( 'doc_type' );
	$doc_type = $doc_type ? (string) $doc_type : 'invoice';

	$order_id = (int) $request->get_param( 'order_id' );
	if ( ! $order_id ) {
		$ids      = wc_get_orders( array( 'limit' => 1, 'return' => 'ids', 'type' => 'shop_order' ) );
		$order_id = ! empty( $ids ) ? (int) reset( $ids ) : 0;
	}

	$order = $order_id ? wc_get_order( $order_id ) : false;
	if ( ! $order ) {
		return new \WP_Error( 'no_order', 'No order found to preview.', array( 'status' => 404 ) );
	}

	// Preview mode: reflect live settings, treat doc as enabled (read-only — no number reservation).
	add_filter( 'woi_pdf_document_use_historical_settings', '__return_false', 99 );
	add_filter( 'woi_pdf_document_is_enabled', '__return_true', 99 );
	$document = woi_pdf_get_document( $doc_type, $order );
	remove_filter( 'woi_pdf_document_is_enabled', '__return_true', 99 );

	if ( ! $document ) {
		return new \WP_Error( 'no_document', 'Could not build the document for this order.', array( 'status' => 404 ) );
	}
	if ( is_callable( array( $document, 'initiate_date' ) ) ) {
		$document->initiate_date();
	}

	$label = '#' . $order->get_order_number();
	$name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
	if ( '' !== $name ) {
		$label .= ' — ' . $name;
	}

	return array(
		'order_id'    => $order_id,
		'order_label' => $label,
		'tokens'      => $this->token_map( $document ),
	);
}

/** Seam over TemplateTokens::map so the handler is unit-testable. */
protected function token_map( $document ): array {
	return ( new \WOI\PDF\Visual\TemplateTokens() )->map( $document );
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php -d display_errors=1 -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit tests/Unit/Visual/VisualPreviewDataTest.php`
Expected: PASS (4 tests).

- [ ] **Step 6: Run the full suite**

Run: `php -d display_errors=1 -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit`
Expected: 176 tests / 0 errors / 1 skipped (172 baseline + 4). The slice-1 `VisualRestTest::test_visual_route_hooked_even_when_rest_api_disabled` must still pass (route method name unchanged).

- [ ] **Step 7: Commit**

```bash
git add includes/Rest.php tests/Unit/Visual/VisualPreviewDataTest.php
git commit -m "feat: visual-preview-data REST endpoint (token map for an order)"
```

---

### Task 2: Grouped palette with friendly labels (app.js)

**Files:**
- Modify: `assets/visual-editor/app.js`

**Interfaces:**
- Consumes: `grapesjs`, `woiVisual`.
- Produces: a `TOKEN_META` array driving block registration; replaces the flat `tokens`/`Invoice tokens` registration. Each token block keeps content `<span data-woi-token="T">{{T}}</span>`.

- [ ] **Step 1: Replace the token list + registration**

In `assets/visual-editor/app.js`, replace the `var tokens = [ … ];` array (lines ~5-10) and the registration loop (lines ~20-27) with:

```js
    // token, friendly label, palette category, tooltip hint
    var TOKEN_META = [
        [ 'logo',             'Logo image',            'Shop',           'Shop logo image' ],
        [ 'shop_name',        'Shop name',             'Shop',           'Company name' ],
        [ 'shop_address',     'Shop address',          'Shop',           'Company address' ],
        [ 'shop_name_ar',     'Shop name (AR)',        'Shop',           'Company name, second language' ],
        [ 'shop_address_ar',  'Shop address (AR)',     'Shop',           'Company address, second language' ],
        [ 'trn',              'TRN',                   'Shop',           'Tax registration number' ],
        [ 'shop_phone',       'Shop phone',            'Shop',           'Company phone' ],
        [ 'shop_email',       'Shop email',            'Shop',           'Company email' ],
        [ 'document_title',   'Document title',        'Document',       'e.g. Tax Invoice' ],
        [ 'document_title_ar','Document title (AR)',   'Document',       'Title, second language' ],
        [ 'invoice_number',   'Invoice number',        'Document',       'Document number' ],
        [ 'invoice_date',     'Invoice date',          'Document',       'Document date' ],
        [ 'order_number',     'Order number',          'Document',       'WooCommerce order number' ],
        [ 'payment_method',   'Payment method',        'Document',       'Order payment method' ],
        [ 'billing_address',  'Billing address',       'Customer',       'Customer billing block' ],
        [ 'line_items',       'Line items table',      'Items & Totals', 'Order line-items table' ],
        [ 'totals',           'Totals table',          'Items & Totals', 'Subtotal / VAT / total table' ]
    ];

    var editor = grapesjs.init( {
        container: '#woi-visual-editor',
        height: '80vh',
        fromElement: false,
        storageManager: false,
        components: woiVisual.stored || woiVisual.starter || ''
    } );

    // Register one draggable block per token, grouped by category.
    TOKEN_META.forEach( function ( m ) {
        var token = m[ 0 ];
        editor.BlockManager.add( 'token-' + token, {
            label: m[ 1 ],
            category: m[ 2 ],
            attributes: { title: m[ 3 ] },
            content: '<span data-woi-token="' + token + '">{{' + token + '}}</span>'
        } );
    } );
```

- [ ] **Step 2: Live verification**

Per the harness: open the Visual Template editor, open the block manager, confirm the palette now shows four categories (**Shop**, **Document**, **Customer**, **Items & Totals**) with friendly labels and tooltips, and dragging a block still inserts the `{{token}}` span. (No automated test — JS.)

- [ ] **Step 3: Commit**

```bash
git add assets/visual-editor/app.js
git commit -m "feat: grouped editor palette with friendly token labels"
```

---

### Task 3: Layout + page-break blocks, keep-together, wrapper CSS

**Files:**
- Modify: `assets/visual-editor/app.js`
- Modify: `templates/_visual/visual-document-wrapper.php`

**Interfaces:**
- Consumes: `editor` (from Task 2), `editor.BlockManager`, `editor.StyleManager`.
- Produces: Layout-category blocks (`row-2col`, `spacer`, `divider`, `heading`, `pagebreak`); a "Print" style sector with a keep-together control; wrapper CSS classes `.woi-pagebreak`, `.woi-spacer`, and `.totals-table` page-break guard.

- [ ] **Step 1: Add Layout blocks**

In `app.js`, after the `TOKEN_META.forEach( … )` registration block, add:

```js
    // Layout building blocks (non-token). Tables (not flex/grid) for mPDF safety.
    editor.BlockManager.add( 'row-2col', {
        label: '2-column row', category: 'Layout',
        attributes: { title: 'Two side-by-side columns' },
        content: '<table class="woi-row"><tr><td>Column one</td><td>Column two</td></tr></table>'
    } );
    editor.BlockManager.add( 'spacer', {
        label: 'Spacer', category: 'Layout',
        attributes: { title: 'Vertical empty space' },
        content: '<div class="woi-spacer"></div>'
    } );
    editor.BlockManager.add( 'divider', {
        label: 'Divider', category: 'Layout',
        attributes: { title: 'Horizontal rule' },
        content: '<hr>'
    } );
    editor.BlockManager.add( 'heading', {
        label: 'Heading', category: 'Layout',
        attributes: { title: 'Section heading' },
        content: '<h2>Section heading</h2>'
    } );
    editor.BlockManager.add( 'pagebreak', {
        label: 'Page break', category: 'Layout',
        attributes: { title: 'Force a new page at this point' },
        content: '<div class="woi-pagebreak"></div>'
    } );
```

- [ ] **Step 2: Add the keep-together style control**

In `app.js`, after the Layout blocks, register a Style-Manager sector with a keep-together property:

```js
    // "Print" style sector: keep a block together across page breaks (mPDF).
    editor.StyleManager.addSector( 'print', {
        name: 'Print',
        open: false,
        properties: [ {
            name: 'Keep together',
            property: 'page-break-inside',
            type: 'select',
            defaults: 'auto',
            list: [
                { value: 'auto',  name: 'Allow break' },
                { value: 'avoid', name: 'Keep together' }
            ]
        } ]
    } );
```

- [ ] **Step 3: Add wrapper CSS**

In `templates/_visual/visual-document-wrapper.php`, inside the existing `<style>` block, add:

```css
.woi-pagebreak { page-break-after: always; height: 0; }
.woi-spacer { height: 12mm; }
.woi-row { width: 100%; }
.woi-row td { vertical-align: top; }
.totals-table { page-break-inside: avoid; }
```

(Keep the existing `@page`, body font, RTL, and table-fidelity rules from slice 1.)

- [ ] **Step 4: Live verification**

Per the harness: confirm the **Layout** category lists 2-column row / Spacer / Divider / Heading / Page break; selecting a block shows a **Print → Keep together** control; then design a long invoice with a Page-break block + a "keep together" totals block, enable the toggle, **Preview real PDF**, rasterize with PyMuPDF, and confirm the PDF spans two pages with the totals table intact (not split) and the totals auto-guard holds even without the manual toggle.

- [ ] **Step 5: Commit**

```bash
git add assets/visual-editor/app.js templates/_visual/visual-document-wrapper.php
git commit -m "feat: layout + page-break blocks, keep-together, totals auto-guard"
```

---

### Task 4: Required-token warning on save (app.js)

**Files:**
- Modify: `assets/visual-editor/app.js`

**Interfaces:**
- Consumes: `getHtml()`, `save()`, the Save toolbar button command (from slice 1).
- Produces: `missingRequiredTokens(html): string[]`; the Save command's success message lists missing required tokens without blocking.

- [ ] **Step 1: Add the helper**

In `app.js`, after `getHtml()` (near the other helpers), add:

```js
    var REQUIRED_TOKENS = [ 'line_items', 'totals', 'invoice_number', 'invoice_date', 'billing_address' ];

    /** Return the required tokens NOT present in the given design HTML. */
    function missingRequiredTokens( html ) {
        return REQUIRED_TOKENS.filter( function ( t ) {
            return html.indexOf( '{{' + t + '}}' ) === -1;
        } );
    }
```

- [ ] **Step 2: Fold the warning into the Save command**

Replace the existing Save button `command` (the `woi-save` button added with `editor.Panels.addButton( 'options', { id: 'woi-save', … } )`) body with:

```js
        command: function () {
            var missing = missingRequiredTokens( getHtml() );
            save().then( function () {
                if ( missing.length ) {
                    alert( 'Saved — heads up, these required tokens are missing: ' +
                        missing.map( function ( t ) { return '{{' + t + '}}'; } ).join( ', ' ) );
                } else {
                    alert( 'Saved' );
                }
            } ).catch( function ( e ) {
                alert( 'Save failed: ' + ( e && e.message ? e.message : e ) );
            } );
        }
```

(`missing` is computed from the pre-save HTML; the save itself is never blocked.)

- [ ] **Step 3: Live verification**

Per the harness: delete the `{{totals}}` block, click **Save** → the design persists AND the alert reads `Saved — heads up, these required tokens are missing: {{totals}}`. Re-add it, Save → plain `Saved`.

- [ ] **Step 4: Commit**

```bash
git add assets/visual-editor/app.js
git commit -m "feat: non-blocking required-token warning on save"
```

---

### Task 5: Real-order in-editor preview (VisualEditorPage + app.js)

**Files:**
- Modify: `includes/Visual/VisualEditorPage.php`
- Modify: `assets/visual-editor/app.js`

**Interfaces:**
- Consumes: REST `GET visual-preview-data` (Task 1); existing admin-ajax `woi_pdf_preview_order_search` (nonce `woi_pdf_preview`, params `search`+`document_type`, returns `success` with data keyed by order id → `{ order_number, billing_first_name, billing_last_name, date_created, total }`); `woiVisual` (extended here).
- Produces: an order control bar above `#woi-visual-editor`; `woiVisual.previewDataUrl`, `woiVisual.orderSearchNonce` (= `woi_pdf_preview` nonce, already `previewNonce`), `woiVisual.orderSearchAction`; `app.js` order-search + `previewRealOrder()`.

- [ ] **Step 1: Localise the new data + render the control bar**

In `includes/Visual/VisualEditorPage.php`, in `enqueue()`, add to the `wp_localize_script( 'woi-visual-editor', 'woiVisual', array( … ) )` array:

```php
			'previewDataUrl'    => esc_url_raw( rest_url( 'woi-pdf/v1/visual-preview-data' ) ),
			'orderSearchAction' => 'woi_pdf_preview_order_search',
```

(`previewNonce` is already the `woi_pdf_preview` nonce — reuse it for the order search.)

In `render_page()`, insert the control bar markup immediately before `echo '<div id="woi-visual-editor"></div></div>';`:

```php
		echo '<div class="woi-order-bar" style="margin:8px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
		echo '<label for="woi-order-search"><strong>' . esc_html__( 'Preview order:', 'woocommerce-orders-invoice-pdf' ) . '</strong></label>';
		echo '<input type="text" id="woi-order-search" class="regular-text" placeholder="' . esc_attr__( 'Order #, email or name (blank = last order)', 'woocommerce-orders-invoice-pdf' ) . '" style="max-width:280px">';
		echo '<button type="button" class="button" id="woi-order-search-btn">' . esc_html__( 'Find', 'woocommerce-orders-invoice-pdf' ) . '</button>';
		echo '<select id="woi-order-results" style="display:none;max-width:320px"></select>';
		echo '<button type="button" class="button button-primary" id="woi-preview-real-order">' . esc_html__( 'Preview real order', 'woocommerce-orders-invoice-pdf' ) . '</button>';
		echo '<span id="woi-order-current" style="color:#555"></span>';
		echo '</div>';
```

- [ ] **Step 2: Wire the order bar + preview in app.js**

In `assets/visual-editor/app.js`, after the existing toolbar buttons (before the final `}() );`), add:

```js
    // --- Real-order preview (control bar rendered by VisualEditorPage) ---
    var selectedOrderId = null;

    function setCurrentOrder( id, label ) {
        selectedOrderId = id;
        var el = document.getElementById( 'woi-order-current' );
        if ( el ) { el.textContent = label ? ( 'Selected: ' + label ) : ''; }
    }

    function orderSearch() {
        var input = document.getElementById( 'woi-order-search' );
        var sel   = document.getElementById( 'woi-order-results' );
        if ( ! input || ! sel ) { return; }
        var term = input.value.trim();
        if ( '' === term ) { setCurrentOrder( null, 'last order' ); sel.style.display = 'none'; return; }

        var body = 'action=' + encodeURIComponent( woiVisual.orderSearchAction ) +
            '&security=' + encodeURIComponent( woiVisual.previewNonce ) +
            '&document_type=' + encodeURIComponent( woiVisual.docType ) +
            '&search=' + encodeURIComponent( term );

        fetch( woiVisual.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            credentials: 'same-origin',
            body: body
        } ).then( function ( r ) { return r.json(); } ).then( function ( res ) {
            sel.innerHTML = '';
            if ( ! res.success || ! res.data ) { alert( 'No orders found.' ); sel.style.display = 'none'; return; }
            Object.keys( res.data ).forEach( function ( id ) {
                var d = res.data[ id ];
                var opt = document.createElement( 'option' );
                opt.value = id;
                opt.textContent = '#' + ( d.order_number || id ) + ' — ' + ( d.billing_first_name || '' ) + ' ' + ( d.billing_last_name || '' );
                sel.appendChild( opt );
            } );
            sel.style.display = 'inline-block';
            sel.selectedIndex = 0;
            setCurrentOrder( sel.value, sel.options[ 0 ].textContent );
        } ).catch( function ( e ) { alert( 'Order search failed: ' + ( e && e.message ? e.message : e ) ); } );
    }

    function previewRealOrder() {
        var url = woiVisual.previewDataUrl + '?doc_type=' + encodeURIComponent( woiVisual.docType );
        if ( selectedOrderId ) { url += '&order_id=' + encodeURIComponent( selectedOrderId ); }

        fetch( url, { headers: { 'X-WP-Nonce': woiVisual.nonce }, credentials: 'same-origin' } )
            .then( function ( r ) {
                if ( ! r.ok ) { throw new Error( r.status === 404 ? 'No order found to preview.' : ( 'HTTP ' + r.status ) ); }
                return r.json();
            } ).then( function ( res ) {
                var html = getHtml();
                Object.keys( res.tokens ).forEach( function ( k ) {
                    html = html.split( k ).join( res.tokens[ k ] );
                } );
                html = html.replace( /\{\{\s*[a-z0-9_]+\s*\}\}/gi, '' );
                var blob = new Blob( [ html ], { type: 'text/html; charset=utf-8' } );
                var blobUrl = URL.createObjectURL( blob );
                window.open( blobUrl, '_blank' );
                setTimeout( function () { URL.revokeObjectURL( blobUrl ); }, 10000 );
                setCurrentOrder( res.order_id, res.order_label );
            } ).catch( function ( e ) { alert( 'Real-order preview failed: ' + ( e && e.message ? e.message : e ) ); } );
    }

    ( function bindOrderBar() {
        var searchBtn  = document.getElementById( 'woi-order-search-btn' );
        var previewBtn = document.getElementById( 'woi-preview-real-order' );
        var sel        = document.getElementById( 'woi-order-results' );
        if ( searchBtn ) { searchBtn.addEventListener( 'click', orderSearch ); }
        if ( previewBtn ) { previewBtn.addEventListener( 'click', previewRealOrder ); }
        if ( sel ) { sel.addEventListener( 'change', function () { setCurrentOrder( sel.value, sel.options[ sel.selectedIndex ].textContent ); } ); }
    }() );
```

- [ ] **Step 3: Live verification**

Per the harness: on the editor, leave the search blank and click **Preview real order** → a new tab shows the current design merged with the **last order's** real content (line items, totals, billing). Then type a known order number, **Find**, pick it, **Preview real order** → shows that order. The sample-data and real-PDF previews still work. (Verify the new REST GET returns 200 with a `tokens` map via the harness `evaluate`.)

- [ ] **Step 4: Commit**

```bash
git add includes/Visual/VisualEditorPage.php assets/visual-editor/app.js
git commit -m "feat: real-order in-editor preview (order search + preview-data fetch)"
```

---

### Task 6: Title spacing — separate styleable title elements

**Files:**
- Modify: `assets/visual-editor/starter-invoice.html`
- Modify: `templates/_visual/visual-document-wrapper.php`

**Interfaces:**
- Consumes: the `document_title` / `document_title_ar` token blocks (already separate).
- Produces: a `.woi-doc-title` title container with two styleable child elements; wrapper default CSS for it.

- [ ] **Step 1: Restructure the starter title**

In `assets/visual-editor/starter-invoice.html`, replace the single title line (the `<h1 …>{{document_title}} <span dir="rtl">{{document_title_ar}}</span></h1>` from slice 1) with:

```html
<div class="woi-doc-title">
  <span class="title-en">{{document_title}}</span>
  <span class="title-ar" dir="rtl">{{document_title_ar}}</span>
</div>
```

- [ ] **Step 2: Add default title CSS to the wrapper**

In `templates/_visual/visual-document-wrapper.php`, inside the `<style>` block, add:

```css
.woi-doc-title { text-align: center; margin: 4mm 0; }
.woi-doc-title .title-en,
.woi-doc-title .title-ar { font-size: 16pt; font-weight: bold; }
.woi-doc-title .title-ar { margin-left: 6mm; }
```

(`.title-ar { margin-left }` is the default gap; the author overrides it via the Style Manager.)

- [ ] **Step 3: Live verification**

Per the harness: with NO stored design (or after "load starter"), confirm the editor shows the two title elements; select the Arabic title and change its left margin in the Style Manager; **Preview real PDF** and rasterize — the English and Arabic titles render with the adjusted gap, Arabic shaped correctly. Confirm an already-saved slice-1 design is unaffected (the starter only loads when nothing is stored).

- [ ] **Step 4: Commit**

```bash
git add assets/visual-editor/starter-invoice.html templates/_visual/visual-document-wrapper.php
git commit -m "feat: separate styleable document-title elements for adjustable gap"
```

---

### Task 7: Version bump + full verification

**Files:**
- Modify: `woocommerce-orders-invoice-pdf.php`

- [ ] **Step 1: Bump the version**

In `woocommerce-orders-invoice-pdf.php`, bump BOTH the header `Version:` and the `$version` property from `1.4.10` to `1.4.11` (they must match).

- [ ] **Step 2: Run the full suite + lints**

Run: `php -d display_errors=1 -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit`
Expected: 176 tests / 0 errors / 1 skipped.
Run: `node --check assets/visual-editor/app.js`
Run: `php -l includes/Rest.php` and `php -l includes/Visual/VisualEditorPage.php` and `php -l templates/_visual/visual-document-wrapper.php`
Expected: all clean.

- [ ] **Step 3: Full live acceptance (harness)**

Confirm on the deployed site (check the **Status** tab shows the new revision first): grouped palette; layout/page-break blocks; keep-together; missing-token save warning; real-order preview (last + searched order); adjustable title gap; and a real multi-page PDF with intact totals and correct Arabic.

- [ ] **Step 4: Commit**

```bash
git add woocommerce-orders-invoice-pdf.php
git commit -m "chore: bump version for visual editor enhancements"
```

---

## Self-Review

**Spec coverage:**
- #2 required-token validation (warn, allow) → Task 4. ✓
- #2 page-break block + keep-together + auto-guard totals → Task 3. ✓
- #2 grouped palette + layout blocks + friendly labels → Tasks 2 & 3. ✓
- #3 new REST endpoint reusing `TemplateTokens::map` → Task 1. ✓
- #3 order search (last + ID override) + coexisting preview → Task 5. ✓
- #4 separate styleable title elements → Task 6. ✓
- Read-only endpoint (no number reservation) → Task 1 (initiate_date only; explicit no get_next). ✓
- Unconditional route registration → Task 1 Step 3 (inside `register_visual_template_route`). ✓
- Version bump → Task 7. ✓
- Backward compatibility (starter-only title change) → Task 6 Step 3. ✓

**Placeholder scan:** No TBD/vague steps; every code step carries complete code. JS tasks use live verification (no in-repo JS harness) with concrete harness steps — explicitly stated, not a hidden gap.

**Type consistency:** `handle_visual_preview_data($request): array|WP_Error` and `token_map($document): array` referenced identically in Task 1 and its test. `missingRequiredTokens(html): string[]` defined and used in Task 4. `previewDataUrl`/`orderSearchAction`/`previewNonce`/`ajaxUrl`/`docType`/`nonce` keys consistent between Task 5's `wp_localize_script` and `app.js`. Block content `<span data-woi-token="T">{{T}}</span>` consistent with slice 1. CSS classes (`.woi-pagebreak`, `.woi-spacer`, `.woi-row`, `.woi-doc-title`, `.title-en`, `.title-ar`, `.totals-table`) consistent between app.js/starter and the wrapper. ✓

## Out of scope (later slices)

Other document types; mPDF in-canvas preview; persisting the chosen preview order; undo/redo or template versioning.
