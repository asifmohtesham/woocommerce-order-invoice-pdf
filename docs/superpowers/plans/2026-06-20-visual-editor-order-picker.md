# Searchable Order Picker Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the "Preview order" text-box + Find + native `<select>` on the Visual Invoice editor with a searchable combobox that opens to the 5 most recent orders and shows each order's name, amount, qty (lines/units), date, and payment mode.

**Architecture:** The existing admin-ajax action `woi_pdf_preview_order_search` (in `Settings.php`) is extended — additively — to (a) return the 5 most recent orders when the search term is empty and (b) include new per-order fields (`payment_method`, `line_count`, `unit_count`, `total_raw`, `date_raw`). Per-order row building is extracted into a unit-testable `build_order_row()` seam. The front end (`VisualEditorPage.php` markup + `app.js` + `editor.css`) renders a custom combobox panel and reuses the existing `woiFetchOrderTokens()` preview pipeline on selection.

**Tech Stack:** PHP (WordPress/WooCommerce), vanilla ES5-style JS (no build step), CSS. Tests: PHPUnit + Brain Monkey.

## Global Constraints

- PHP namespace for the AJAX handler: `WOI\PDF` (class `WOI\PDF\Settings`).
- Existing response keys `order_number`, `billing_first_name`, `billing_last_name`, `billing_company`, `date_created`, `total` MUST keep their current label-prefixed HTML format — the legacy `assets/js/admin.js` settings-page search renders them verbatim.
- No new REST endpoint; reuse admin-ajax `woi_pdf_preview_order_search` (nonce `woi_pdf_preview`, sent as `security`; params `document_type`, `search`).
- `app.js` is plain browser JS (no imports/build); match the file's existing `var`/function style.
- PHPUnit MUST be run with the ABSPATH bootstrap: `php -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit` (it dies silently otherwise).
- Recent-orders count is `(int) apply_filters( 'woi_pdf_preview_order_recent_limit', 5 )` — default **5**.
- After any JS/CSS edit, bump the plugin header `Version:` (line 6 of `woocommerce-orders-invoice-pdf.php`) so `WOI_PDF_VERSION` (the asset-enqueue version) invalidates browser cache. Current: `1.4.31` → target `1.4.32`.

---

### Task 1: Server — recent-on-empty + new row fields (`build_order_row` seam)

**Files:**
- Modify: `includes/Settings.php` — `preview_order_search()` (~lines 429–519); add `build_order_row()`.
- Test: `tests/Unit/Settings/PreviewOrderRowTest.php` (create).

**Interfaces:**
- Consumes: WC functions `wc_get_orders`, `wc_get_order`, `wc_price`; plugin fn `woi_pdf_sanitize_html_content` (loaded via bootstrap); WP `apply_filters`, `esc_attr__`, `is_email`, `sanitize_text_field`, `wp_unslash`, `wp_send_json_success/error`.
- Produces: `protected function build_order_row( $order ): array` returning keys
  `order_number, billing_first_name, billing_last_name, billing_company, date_created, total` (legacy, unchanged format) plus `total_raw, date_raw, payment_method, line_count (int), unit_count (int)`. The AJAX response is a map `order_id => build_order_row(order)`. Empty `search` ⇒ 5 most recent orders.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Settings/PreviewOrderRowTest.php`:

```php
<?php
namespace WOI\PDF\Tests\Unit\Settings;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Settings;

/**
 * Guards the preview order-search row builder used by the Visual editor combobox.
 * - line/unit counts and payment label are computed correctly
 * - new raw (label-free) amount/date fields are present
 * - legacy label-prefixed fields (consumed by admin.js) are preserved
 */
class PreviewOrderRowTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( '__' )->returnArg();
		// woi_pdf_sanitize_html_content() internals: keep filter passthrough.
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wc_price' )->alias( function ( $v ) { return 'AED ' . number_format( (float) $v, 2 ); } );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function settings(): Settings {
		return ( new \ReflectionClass( Settings::class ) )->newInstanceWithoutConstructor();
	}

	private function build_row( $order ): array {
		$m = new \ReflectionMethod( Settings::class, 'build_order_row' );
		$m->setAccessible( true );
		return $m->invoke( $this->settings(), $order );
	}

	private function stub_order() {
		return new class {
			public function get_id() { return 237; }
			public function get_order_number() { return '237'; }
			public function get_billing_first_name() { return 'John'; }
			public function get_billing_last_name() { return 'Buyer'; }
			public function get_billing_company() { return 'Nesto Hypermarket LLC'; }
			public function get_payment_method_title() { return 'Bank transfer'; }
			public function get_total() { return 1250; }
			public function get_date_created() {
				return new class { public function format( $f ) { return '2026/06/17'; } };
			}
			public function get_items() {
				return array(
					new class { public function get_quantity() { return 2; } },
					new class { public function get_quantity() { return 12; } },
				);
			}
		};
	}

	public function test_counts_payment_and_raw_fields(): void {
		$row = $this->build_row( $this->stub_order() );

		$this->assertSame( 2, $row['line_count'], 'two distinct line items' );
		$this->assertSame( 14, $row['unit_count'], 'summed quantities 2 + 12' );
		$this->assertStringContainsString( 'Bank transfer', $row['payment_method'] );
		$this->assertSame( '2026/06/17', $row['date_raw'] );
		$this->assertStringContainsString( '1,250', $row['total_raw'] );
		$this->assertStringNotContainsString( '<strong>', $row['total_raw'], 'raw total has no label prefix' );
	}

	public function test_preserves_legacy_labelled_fields(): void {
		$row = $this->build_row( $this->stub_order() );

		$this->assertSame( '237', $row['order_number'] );
		$this->assertStringContainsString( 'Total', $row['total'] );
		$this->assertStringContainsString( 'Date', $row['date_created'] );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit --filter PreviewOrderRowTest`
Expected: FAIL — `ReflectionMethod` cannot find `build_order_row` (method does not exist yet).

- [ ] **Step 3: Add the `build_order_row()` seam**

In `includes/Settings.php`, add this method directly **after** the closing brace of `preview_order_search()` (after the current line 519 `}`):

```php
	/**
	 * Build one order's data row for the preview order search / combobox.
	 *
	 * The legacy keys (order_number, billing_*, date_created, total) keep their
	 * existing label-prefixed HTML because assets/js/admin.js renders them
	 * verbatim. The combobox in the Visual editor uses the raw + count fields.
	 *
	 * @param object $order WC_Order (or compatible).
	 *
	 * @return array
	 */
	protected function build_order_row( $order ): array {
		$has_date = is_callable( array( $order, 'get_date_created' ) ) && $order->get_date_created();

		$row = array(
			'order_number'       => is_callable( array( $order, 'get_order_number' ) ) ? $order->get_order_number() : '',
			'billing_first_name' => is_callable( array( $order, 'get_billing_first_name' ) ) ? woi_pdf_sanitize_html_content( $order->get_billing_first_name(), 'first_name' ) : '',
			'billing_last_name'  => is_callable( array( $order, 'get_billing_last_name' ) ) ? woi_pdf_sanitize_html_content( $order->get_billing_last_name(), 'last_name' ) : '',
			'billing_company'    => is_callable( array( $order, 'get_billing_company' ) ) ? woi_pdf_sanitize_html_content( $order->get_billing_company(), 'company' ) : '',
			'date_created'       => $has_date ? '<strong>' . esc_attr__( 'Date', 'woocommerce-orders-invoice-pdf' ) . ':</strong> ' . $order->get_date_created()->format( 'Y/m/d' ) : '',
			'total'              => is_callable( array( $order, 'get_total' ) ) ? '<strong>' . esc_attr__( 'Total', 'woocommerce-orders-invoice-pdf' ) . ':</strong> ' . wc_price( $order->get_total() ) : '',
		);

		// Combobox-only fields: raw amount/date (no label), payment label, qty breakdown.
		$row['total_raw']      = is_callable( array( $order, 'get_total' ) ) ? wc_price( $order->get_total() ) : '';
		$row['date_raw']       = $has_date ? $order->get_date_created()->format( 'Y/m/d' ) : '';
		$row['payment_method'] = is_callable( array( $order, 'get_payment_method_title' ) ) ? woi_pdf_sanitize_html_content( $order->get_payment_method_title(), 'payment_method' ) : '';

		$line_count = 0;
		$unit_count = 0;
		if ( is_callable( array( $order, 'get_items' ) ) ) {
			$items      = $order->get_items();
			$line_count = ( is_array( $items ) || $items instanceof \Countable ) ? count( $items ) : 0;
			foreach ( (array) $items as $item ) {
				$unit_count += is_callable( array( $item, 'get_quantity' ) ) ? (int) $item->get_quantity() : 0;
			}
		}
		$row['line_count'] = $line_count;
		$row['unit_count'] = $unit_count;

		return $row;
	}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit --filter PreviewOrderRowTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Rewire `preview_order_search()` to use the seam + recent-on-empty**

Replace the body of `preview_order_search()` (current lines ~429–519, from `check_ajax_referer` through `wp_die();`) with:

```php
	public function preview_order_search() {
		check_ajax_referer( 'woi_pdf_preview', 'security' );

		try {
			// check permissions
			if ( ! $this->user_can_manage_settings() ) {
				throw new \Exception( esc_html__( 'You do not have sufficient permissions to access this page.', 'woocommerce-orders-invoice-pdf' ), 403 );
			}

			if ( empty( $_POST['document_type'] ) ) {
				wp_send_json_error( array( 'error' => esc_html__( 'An error occurred when trying to process your request!', 'woocommerce-orders-invoice-pdf' ) ) );
			}

			$document_type = sanitize_text_field( wp_unslash( $_POST['document_type'] ) );
			$search        = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
			$results       = array();

			// Empty term: the combobox opens with the most recent orders.
			if ( '' === $search ) {
				$recent_limit = (int) apply_filters( 'woi_pdf_preview_order_recent_limit', 5 );
				$results      = wc_get_orders( array(
					'type'    => 'shop_order',
					'limit'   => $recent_limit,
					'orderby' => 'date',
					'order'   => 'DESC',
					'return'  => 'ids',
				) );

			// we have an order ID
			} elseif ( is_numeric( $search ) && wc_get_order( $search ) ) {
				$results = array( $search );

			// no order ID, let's try with customer
			} else {
				$default_args = apply_filters( 'woi_pdf_preview_order_search_args', array(
					'type'    => 'shop_order',
					'limit'   => 10,
					'orderby' => 'date',
					'order'   => 'DESC',
					'return'  => 'ids',
				), $document_type );

				// search by email
				if ( is_email( $search ) ) {
					$args    = array( 'customer' => $search ) + $default_args;
					$results = wc_get_orders( $args );

				// search by names
				} else {
					$names = array( 'billing_first_name', 'billing_last_name', 'billing_company' );
					foreach ( $names as $name ) {
						$args    = array( $name => $search ) + $default_args;
						$results = wc_get_orders( $args );
						if ( count( $results ) > 0 ) {
							break;
						}
					}
				}
			}

			// filter results
			$results = apply_filters( 'woi_pdf_preview_order_search_results', $results, $search, $document_type );

			// if we got here we have results!
			if ( ! empty( $results ) ) {
				$data = array();
				foreach ( $results as $value ) {
					$order = wc_get_order( $value );
					if ( empty( $order ) ) {
						continue;
					}
					$order_id          = is_callable( array( $order, 'get_id' ) ) ? $order->get_id() : 0;
					$data[ $order_id ] = $this->build_order_row( $order );
				}

				$data = apply_filters( 'woi_pdf_preview_order_search_data', $data, $results );

				wp_send_json_success( $data );
			} else {
				wp_send_json_error( array( 'error' => esc_html__( 'No order(s) found!', 'woocommerce-orders-invoice-pdf' ) ) );
			}
		} catch ( \Throwable $th ) {
			wp_send_json_error(
				array(
					'error' => sprintf(
						/* translators: error message */
						esc_html__( 'Error trying to get orders: %s', 'woocommerce-orders-invoice-pdf' ),
						$th->getMessage()
					)
				)
			);
		}

		wp_die();
	}
```

- [ ] **Step 6: Run the full suite to confirm no regressions**

Run: `php -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit`
Expected: PASS — all prior tests plus the 2 new ones; 0 errors.

- [ ] **Step 7: Commit**

```bash
git add includes/Settings.php tests/Unit/Settings/PreviewOrderRowTest.php
git commit -m "feat: recent-on-empty + qty/payment/raw fields in preview order search"
```

---

### Task 2: Front-end combobox (markup + JS + CSS + version bump)

**Files:**
- Modify: `includes/Visual/VisualEditorPage.php` — order-bar markup (~lines 114–122).
- Modify: `assets/visual-editor/app.js` — replace `setOrderBarBusy` + `orderSearch` + `bindOrderBar` (~lines 489–562).
- Modify: `assets/visual-editor/editor.css` — append combobox panel styles.
- Modify: `woocommerce-orders-invoice-pdf.php` — header `Version:` line 6.

**Interfaces:**
- Consumes (Task 1 server): admin-ajax `woi_pdf_preview_order_search` returning `success` with map `order_id => { order_number, billing_first_name, billing_last_name, billing_company, date_created, total, total_raw, date_raw, payment_method, line_count, unit_count }`; empty `search` ⇒ recent 5.
- Consumes (existing `app.js`): `woiVisual.{ajaxUrl, orderSearchAction, previewNonce, docType}`; `woiSelectedOrderId` (var, line 715); `woiFetchOrderTokens(id)` (line 693); `woiRefreshLiveHtml()` (line 685); `woiMaybeRefreshPdf()` (line 797); `setCurrentOrder(id,label)` (line 482).
- Produces: a working combobox (`#woi-order-search` input + `#woi-order-panel` list) inside `.woi-order-combo`; no native `<select>` / Find button.

- [ ] **Step 1: Replace the order-bar markup**

In `includes/Visual/VisualEditorPage.php`, replace the order-bar block (current lines 114–122):

```php
        // Order bar.
        echo '<div class="woi-order-bar" style="margin:8px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
        echo '<label for="woi-order-search"><strong>' . esc_html__( 'Preview order:', 'woocommerce-orders-invoice-pdf' ) . '</strong></label>';
        echo '<input type="text" id="woi-order-search" class="regular-text" placeholder="' . esc_attr__( 'Order #, email or name (blank = last order)', 'woocommerce-orders-invoice-pdf' ) . '" style="max-width:280px">';
        echo '<button type="button" class="button" id="woi-order-search-btn">' . esc_html__( 'Find', 'woocommerce-orders-invoice-pdf' ) . '</button>';
        echo '<span class="spinner woi-order-spinner" style="float:none;margin:0;visibility:hidden"></span>';
        echo '<select id="woi-order-results" style="display:none;max-width:320px"></select>';
        echo '<span id="woi-order-current" style="color:#555"></span>';
        echo '</div>';
```

with:

```php
        // Order bar (searchable combobox; populated by app.js via woi_pdf_preview_order_search).
        echo '<div class="woi-order-bar" style="margin:8px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
        echo '<label for="woi-order-search"><strong>' . esc_html__( 'Preview order:', 'woocommerce-orders-invoice-pdf' ) . '</strong></label>';
        echo '<span class="woi-order-combo">';
        echo '<input type="text" id="woi-order-search" class="regular-text" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="woi-order-panel" aria-autocomplete="list" placeholder="' . esc_attr__( 'Search by order #, name, company or email', 'woocommerce-orders-invoice-pdf' ) . '">';
        echo '<ul id="woi-order-panel" class="woi-order-panel" role="listbox" hidden></ul>';
        echo '</span>';
        echo '<span class="spinner woi-order-spinner" style="float:none;margin:0;visibility:hidden"></span>';
        echo '<span id="woi-order-current" style="color:#555"></span>';
        echo '</div>';
```

- [ ] **Step 2: Replace the order-bar JS block**

In `assets/visual-editor/app.js`, replace everything from the `setOrderBarBusy` comment+function through the end of the `bindOrderBar` IIFE (current lines 489–562) with:

```js
    // Toggle loading feedback on the order bar (spinner only; no Find button now).
    function setOrderBarBusy( busy ) {
        var spinner = document.querySelector( '.woi-order-spinner' );
        if ( spinner ) {
            spinner.classList.toggle( 'is-active', !! busy );
            spinner.style.visibility = busy ? 'visible' : 'hidden';
        }
    }

    // --- Searchable order combobox ---
    var woiOrderDebounce   = null;
    var woiOrderActiveIdx  = -1;

    function woiOrderPanel() { return document.getElementById( 'woi-order-panel' ); }

    function woiOrderRowTitle( d ) {
        var name = ( d.billing_company || '' ).trim();
        if ( '' === name ) {
            name = ( ( d.billing_first_name || '' ) + ' ' + ( d.billing_last_name || '' ) ).trim();
        }
        if ( '' === name ) { name = '(no name)'; }
        return '#' + ( d.order_number || '' ) + ' — ' + name;
    }

    function woiSetPanelOpen( open ) {
        var panel = woiOrderPanel();
        var input = document.getElementById( 'woi-order-search' );
        if ( ! panel ) { return; }
        if ( open ) { panel.removeAttribute( 'hidden' ); } else { panel.setAttribute( 'hidden', '' ); }
        if ( input ) { input.setAttribute( 'aria-expanded', open ? 'true' : 'false' ); }
        if ( ! open ) { woiOrderActiveIdx = -1; }
    }

    function woiPanelIsOpen() {
        var panel = woiOrderPanel();
        return panel && ! panel.hasAttribute( 'hidden' );
    }

    // POST a search term (empty = recent orders); resolves to the data map or {}.
    function woiFetchOrders( term ) {
        var body = 'action=' + encodeURIComponent( woiVisual.orderSearchAction ) +
            '&security=' + encodeURIComponent( woiVisual.previewNonce ) +
            '&document_type=' + encodeURIComponent( woiVisual.docType ) +
            '&search=' + encodeURIComponent( term || '' );
        return fetch( woiVisual.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            credentials: 'same-origin',
            body: body
        } ).then( function ( r ) { return r.json(); } )
           .then( function ( res ) { return ( res && res.success && res.data ) ? res.data : {}; } )
           .catch( function () { return {}; } );
    }

    function woiSelectOrder( id, label ) {
        setCurrentOrder( id, label );
        woiSetPanelOpen( false );
        var input = document.getElementById( 'woi-order-search' );
        if ( input ) { input.value = label || ''; }
        setOrderBarBusy( true );
        woiFetchOrderTokens( id ).then( function () {
            woiRefreshLiveHtml();
            if ( typeof woiMaybeRefreshPdf === 'function' ) { woiMaybeRefreshPdf(); }
        } ).catch( function () {} ).then( function () { setOrderBarBusy( false ); } );
    }

    // Rebuild #woi-order-panel from a data map and open it.
    function woiRenderOrderPanel( data ) {
        var panel = woiOrderPanel();
        if ( ! panel ) { return; }
        panel.innerHTML = '';
        woiOrderActiveIdx = -1;
        var ids = Object.keys( data || {} );

        if ( ! ids.length ) {
            var empty = document.createElement( 'li' );
            empty.className = 'woi-order-empty';
            empty.textContent = 'No orders found';
            panel.appendChild( empty );
            woiSetPanelOpen( true );
            return;
        }

        ids.forEach( function ( id ) {
            var d  = data[ id ];
            var li = document.createElement( 'li' );
            li.className = 'woi-order-opt';
            li.setAttribute( 'role', 'option' );
            li.setAttribute( 'data-order-id', id );

            var title = document.createElement( 'span' );
            title.className = 'woi-op-title';
            title.textContent = woiOrderRowTitle( d );

            var meta1 = document.createElement( 'span' );
            meta1.className = 'woi-op-meta';
            // total_raw is server HTML (wc_price); counts are integers.
            meta1.innerHTML = ( d.total_raw || '' ) + ' · ' +
                ( d.line_count || 0 ) + ' items / ' + ( d.unit_count || 0 ) + ' units';

            var meta2 = document.createElement( 'span' );
            meta2.className = 'woi-op-meta';
            var dateTxt = d.date_raw || '';
            var payTxt  = d.payment_method || '—';
            meta2.innerHTML = ( dateTxt ? dateTxt + ' · ' : '' ) + payTxt;

            li.appendChild( title );
            li.appendChild( meta1 );
            li.appendChild( meta2 );

            li.addEventListener( 'mousedown', function ( e ) {
                // mousedown (not click) so it fires before the input blur closes the panel.
                e.preventDefault();
                woiSelectOrder( id, woiOrderRowTitle( d ) );
            } );

            panel.appendChild( li );
        } );

        woiSetPanelOpen( true );
    }

    function woiOrderOptions() {
        var panel = woiOrderPanel();
        return panel ? Array.prototype.slice.call( panel.querySelectorAll( '.woi-order-opt' ) ) : [];
    }

    function woiHighlightOption( idx ) {
        var opts = woiOrderOptions();
        if ( ! opts.length ) { return; }
        if ( idx < 0 ) { idx = opts.length - 1; }
        if ( idx >= opts.length ) { idx = 0; }
        woiOrderActiveIdx = idx;
        opts.forEach( function ( o, i ) { o.classList.toggle( 'is-active', i === idx ); } );
        opts[ idx ].scrollIntoView( { block: 'nearest' } );
    }

    function woiLoadOrders( term ) {
        setOrderBarBusy( true );
        return woiFetchOrders( term ).then( function ( data ) {
            woiRenderOrderPanel( data );
        } ).catch( function () {} ).then( function () { setOrderBarBusy( false ); } );
    }

    ( function bindOrderCombo() {
        var input = document.getElementById( 'woi-order-search' );
        if ( ! input ) { return; }

        input.addEventListener( 'focus', function () {
            // Show recents (or the existing list) on open.
            if ( ! woiPanelIsOpen() || ! woiOrderOptions().length ) {
                woiLoadOrders( input.value.trim() );
            } else {
                woiSetPanelOpen( true );
            }
        } );

        input.addEventListener( 'input', function () {
            if ( woiOrderDebounce ) { clearTimeout( woiOrderDebounce ); }
            var term = input.value.trim();
            woiOrderDebounce = setTimeout( function () { woiLoadOrders( term ); }, 300 );
        } );

        input.addEventListener( 'keydown', function ( e ) {
            if ( 'ArrowDown' === e.key ) {
                e.preventDefault();
                if ( ! woiPanelIsOpen() ) { woiLoadOrders( input.value.trim() ); return; }
                woiHighlightOption( woiOrderActiveIdx + 1 );
            } else if ( 'ArrowUp' === e.key ) {
                e.preventDefault();
                woiHighlightOption( woiOrderActiveIdx - 1 );
            } else if ( 'Enter' === e.key ) {
                var opts = woiOrderOptions();
                if ( woiPanelIsOpen() && woiOrderActiveIdx >= 0 && opts[ woiOrderActiveIdx ] ) {
                    e.preventDefault();
                    opts[ woiOrderActiveIdx ].dispatchEvent( new MouseEvent( 'mousedown' ) );
                }
            } else if ( 'Escape' === e.key ) {
                woiSetPanelOpen( false );
            }
        } );

        document.addEventListener( 'click', function ( e ) {
            var combo = e.target.closest ? e.target.closest( '.woi-order-combo' ) : null;
            if ( ! combo ) { woiSetPanelOpen( false ); }
        } );
    }() );
```

Note: `setCurrentOrder` (line 482) already sets both `selectedOrderId` and `woiSelectedOrderId`, so selection keeps the PDF engine in sync without touching `woiSelectedOrderId` directly here.

- [ ] **Step 3: Append the combobox CSS**

Append to `assets/visual-editor/editor.css`:

```css
/* --- Searchable order combobox --- */
.woi-order-combo { position: relative; display: inline-block; }
.woi-order-combo > #woi-order-search { max-width: 320px; }

.woi-order-panel {
	position: absolute;
	top: 100%;
	left: 0;
	z-index: 100000; /* above the GrapesJS canvas */
	margin: 2px 0 0;
	padding: 4px 0;
	min-width: 320px;
	max-height: 320px;
	overflow-y: auto;
	list-style: none;
	background: #fff;
	border: 1px solid #c3c4c7;
	border-radius: 4px;
	box-shadow: 0 6px 16px rgba( 0, 0, 0, 0.15 );
}

.woi-order-opt {
	display: block;
	padding: 6px 12px;
	cursor: pointer;
	border-bottom: 1px solid #f0f0f1;
}
.woi-order-opt:last-child { border-bottom: 0; }
.woi-order-opt:hover,
.woi-order-opt.is-active { background: #f0f6fc; }

.woi-order-opt .woi-op-title { display: block; font-weight: 600; color: #1d2327; }
.woi-order-opt .woi-op-meta  { display: block; font-size: 12px; color: #646970; }

.woi-order-empty { padding: 8px 12px; color: #646970; font-style: italic; }
```

- [ ] **Step 4: Bump the asset/cache version**

In `woocommerce-orders-invoice-pdf.php`, line 6, change:

```php
 * Version:              1.4.31
```

to:

```php
 * Version:              1.4.32
```

- [ ] **Step 5: PHP smoke check (no syntax errors)**

Run: `php -l includes/Visual/VisualEditorPage.php && php -l woocommerce-orders-invoice-pdf.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 6: Live verification on the editor screen**

There is no in-repo JS harness; verify against the running site (the debug-Chrome harness / wp-admin Visual Invoice Template screen). Confirm:
1. Focusing the "Preview order" input opens a panel listing the **5 most recent** orders, each showing `#num — name`, `<amount> · N items / M units`, and `date · payment mode`.
2. Typing a name / order # / email re-filters the list (after a ~300ms pause).
3. ArrowDown/ArrowUp highlight rows; Enter selects the highlighted row; Escape closes; clicking outside closes.
4. Selecting a row updates "Selected: …", and the Live HTML (and PDF tab if open) refresh to that order.
5. No leftover **Find** button or native `<select>` remain.

- [ ] **Step 7: Commit**

```bash
git add includes/Visual/VisualEditorPage.php assets/visual-editor/app.js assets/visual-editor/editor.css woocommerce-orders-invoice-pdf.php
git commit -m "feat: searchable order picker combobox in Visual Invoice editor"
```

---

## Self-Review

**Spec coverage:**
- Custom rich panel (multi-line rows) → Task 2 Steps 1–3. ✓
- 5 most recent on focus + live debounced filter → Task 1 Step 5 (server recent-on-empty), Task 2 Step 2 (focus/input handlers). ✓
- Row fields name/amount/qty(lines+units)/date/payment → Task 1 (server `build_order_row`), Task 2 Step 2 (`woiRenderOrderPanel`). ✓
- Reuse `woiFetchOrderTokens` pipeline on pick → Task 2 Step 2 (`woiSelectOrder`). ✓
- Legacy `admin.js` fields unchanged → Task 1 (legacy keys preserved; raw variants added) + test `test_preserves_legacy_labelled_fields`. ✓
- No new REST endpoint → reuses `woi_pdf_preview_order_search`. ✓
- Panel CSS in `editor.css` → Task 2 Step 3. ✓
- Version bump 1.4.31 → 1.4.32 → Task 2 Step 4. ✓

**Placeholder scan:** No TBD/TODO/"handle edge cases"; every code step carries complete code. JS verification is live (no JS harness) — stated explicitly, not hidden.

**Type consistency:** Server keys produced in Task 1 (`total_raw`, `date_raw`, `payment_method`, `line_count`, `unit_count`, plus legacy keys) are exactly the keys consumed in Task 2 `woiRenderOrderPanel`/`woiOrderRowTitle`. JS helpers (`woiOrderPanel`, `woiSetPanelOpen`, `woiPanelIsOpen`, `woiFetchOrders`, `woiRenderOrderPanel`, `woiSelectOrder`, `woiOrderOptions`, `woiHighlightOption`, `woiLoadOrders`) are all defined and referenced consistently within Task 2 Step 2. `setCurrentOrder`, `woiFetchOrderTokens`, `woiRefreshLiveHtml`, `woiMaybeRefreshPdf`, `woiVisual.*` match existing `app.js` signatures.

## Out of scope
- Client-side caching of the recent list, infinite scroll / pagination of results.
- Changing search matching logic (still #/email/name/company).
- Restyling the legacy settings-page order search.
