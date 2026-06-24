# Naming Token Builder + Live Preview Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** In the Block Invoice Template "Numbering & filename" panel, let users insert tokens via click/drag chips (instead of typing) and see a server-resolved live preview of the resolved number and PDF filename for the loaded order.

**Architecture:** A new pure helper module (`tokenInsert.js`) + token definitions in `namingModel.js` keep insertion logic testable. A thin `TokenField.js` component wraps a text input with a chip row (click-to-insert + HTML5 draggable). A new read-only REST route `POST woi-pdf/v1/naming-preview` resolves both previews using the real PHP formatters (`woi_pdf_format_document_number` + the document's `get_filename()`), injecting the *unsaved* prefix/suffix/padding/template via a one-shot `option_woi_pdf_documents_settings_{type}` filter so the preview never diverges from production. `NamingPanel.js` wires the fields to `TokenField` and calls the preview endpoint debounced ~250 ms.

**Tech Stack:** React via `@wordpress/scripts` (webpack), `@wordpress/components`/`element`/`data`/`i18n`; PHP WP REST API (`WP_REST_Server`); Jest + PHPUnit (Brain Monkey).

## Global Constraints

- This is **Phase 2** of `docs/superpowers/specs/2026-06-24-naming-token-builder-and-preview-design.md`. Phase 1 (download filename) already landed as v1.5.89.
- Two token systems, never mixed: **prefix/suffix** use `[...]` placeholders resolved by `woi_pdf_format_document_number`; **filename** uses `{...}` tokens resolved by `woi_pdf_build_filename`.
- Prefix slug-based tokens use `slug = type` with hyphens → underscores (`invoice`, `credit_note`, `proforma`, `receipt`) — matches `OrderDocument::$slug`.
- Numbered types: `invoice`, `proforma`, `credit-note`, `receipt` (have a series). `packing-slip`: filename override only, no series. Source of truth: `Rest::numbering_types()` / `Rest::naming_types()` (already exist).
- The preview endpoint is **strictly read-only**: no `update_option`, no number-store increment. Permission `current_user_can('manage_woocommerce')`; same `X-WP-Nonce` transport as `/document-naming`.
- The preview must use the real PHP formatters (no JS reimplementation).
- Concurrency: do all work in a git worktree off `origin/master` (`tools/new-feature.ps1 <name> -Junction`); version bump (BOTH strings) + `npm run build` LAST, at landing. Read the TRUE version from `origin/master` before bumping. Editing `assets/css/block-editor-shell.css` is an asset change → requires a version bump but NO build (it is not webpack-built).
- mPDF/PDF rules do not apply here (this is editor UI + a JSON endpoint).

---

### Task 1: Pure token helpers (`prefixTokens`, `filenameTokenChips`, `insertAtCaret`)

**Files:**
- Create: `src/block-editor/tokenInsert.js`
- Modify: `src/block-editor/namingModel.js` (add `prefixTokens`, `filenameTokenChips`)
- Test: `src/block-editor/tokenInsert.test.js` (new), `src/block-editor/namingModel.test.js` (extend)

**Interfaces:**
- Produces: `insertAtCaret(value: string, start: number|null, end: number|null, token: string) => { value: string, caret: number }`
- Produces: `prefixTokens(type: string) => Array<{ token: string, label: string }>`
- Produces: `filenameTokenChips() => Array<{ token: string, label: string }>`
- Consumes: existing `FILENAME_TOKENS` (array of `{...}` strings) from `namingModel.js`.

- [ ] **Step 1: Write the failing test for `insertAtCaret`**

Create `src/block-editor/tokenInsert.test.js`:

```js
import { insertAtCaret } from './tokenInsert';

describe( 'insertAtCaret', () => {
	it( 'inserts at the caret position', () => {
		expect( insertAtCaret( 'ab', 1, 1, '[x]' ) ).toEqual( { value: 'a[x]b', caret: 4 } );
	} );

	it( 'replaces the current selection', () => {
		expect( insertAtCaret( 'abcd', 1, 3, '[x]' ) ).toEqual( { value: 'a[x]d', caret: 4 } );
	} );

	it( 'appends when caret is unknown (null)', () => {
		expect( insertAtCaret( 'ab', null, null, '[x]' ) ).toEqual( { value: 'ab[x]', caret: 5 } );
	} );

	it( 'treats null/undefined value as empty string', () => {
		expect( insertAtCaret( undefined, null, null, '{d}' ) ).toEqual( { value: '{d}', caret: 3 } );
	} );
} );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npx wp-scripts test-unit-js src/block-editor/tokenInsert.test.js`
Expected: FAIL — `Cannot find module './tokenInsert'`.

- [ ] **Step 3: Implement `tokenInsert.js`**

Create `src/block-editor/tokenInsert.js`:

```js
// Pure caret-insertion helper for TokenField. Given the input's current value
// and selection range, returns the value with `token` spliced in and the new
// caret offset (so the caller can restore selection after React re-renders).
export function insertAtCaret( value, start, end, token ) {
	const v = String( value == null ? '' : value );
	const s = Number.isInteger( start ) ? start : v.length;
	const e = Number.isInteger( end ) ? end : s;
	const next = v.slice( 0, s ) + token + v.slice( e );
	return { value: next, caret: s + token.length };
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npx wp-scripts test-unit-js src/block-editor/tokenInsert.test.js`
Expected: PASS (4/4).

- [ ] **Step 5: Write the failing test for `prefixTokens` / `filenameTokenChips`**

Append to `src/block-editor/namingModel.test.js`:

```js
import { prefixTokens, filenameTokenChips } from './namingModel';

describe( 'prefixTokens', () => {
	it( 'includes the order placeholders and slug-based doc placeholders for invoice', () => {
		const toks = prefixTokens( 'invoice' ).map( ( t ) => t.token );
		expect( toks ).toEqual( expect.arrayContaining( [
			'[order_year]', '[order_month]', '[order_day]', '[order_number]',
			'[invoice_year]', '[invoice_month]', '[invoice_day]',
		] ) );
	} );

	it( 'uses underscore slug for hyphenated types (credit-note)', () => {
		const toks = prefixTokens( 'credit-note' ).map( ( t ) => t.token );
		expect( toks ).toContain( '[credit_note_year]' );
		expect( toks ).not.toContain( '[credit-note_year]' );
	} );
} );

describe( 'filenameTokenChips', () => {
	it( 'wraps every FILENAME_TOKENS entry as a {token,label} chip', () => {
		const chips = filenameTokenChips();
		expect( chips.map( ( c ) => c.token ) ).toContain( '{document_number_sequence}' );
		chips.forEach( ( c ) => {
			expect( typeof c.token ).toBe( 'string' );
			expect( typeof c.label ).toBe( 'string' );
		} );
	} );
} );
```

- [ ] **Step 6: Run it to verify it fails**

Run: `npx wp-scripts test-unit-js src/block-editor/namingModel.test.js`
Expected: FAIL — `prefixTokens`/`filenameTokenChips` are not exported.

- [ ] **Step 7: Implement the helpers in `namingModel.js`**

Add to `src/block-editor/namingModel.js` (keep existing `FILENAME_TOKENS` and other exports unchanged):

```js
// Prefix/suffix placeholders resolved by woi_pdf_format_document_number. The
// slug-based set uses the doc type with hyphens -> underscores, matching
// OrderDocument::$slug (e.g. credit-note -> credit_note).
export function prefixTokens( type ) {
	const slug = String( type || '' ).replace( /-/g, '_' );
	return [
		{ token: '[order_year]', label: __( 'Order year', 'woocommerce-orders-invoice-pdf' ) },
		{ token: '[order_month]', label: __( 'Order month', 'woocommerce-orders-invoice-pdf' ) },
		{ token: '[order_day]', label: __( 'Order day', 'woocommerce-orders-invoice-pdf' ) },
		{ token: '[order_number]', label: __( 'Order #', 'woocommerce-orders-invoice-pdf' ) },
		{ token: `[${ slug }_year]`, label: __( 'Doc year', 'woocommerce-orders-invoice-pdf' ) },
		{ token: `[${ slug }_month]`, label: __( 'Doc month', 'woocommerce-orders-invoice-pdf' ) },
		{ token: `[${ slug }_day]`, label: __( 'Doc day', 'woocommerce-orders-invoice-pdf' ) },
	];
}

// Filename {...} tokens as {token,label} chips for TokenField (the raw strings
// remain available as FILENAME_TOKENS for the help text).
export function filenameTokenChips() {
	const labels = {
		'{document_type}': __( 'Type', 'woocommerce-orders-invoice-pdf' ),
		'{order_number}': __( 'Order #', 'woocommerce-orders-invoice-pdf' ),
		'{document_number}': __( 'Number', 'woocommerce-orders-invoice-pdf' ),
		'{document_number_sequence}': __( 'Sequence', 'woocommerce-orders-invoice-pdf' ),
		'{date}': __( 'Date', 'woocommerce-orders-invoice-pdf' ),
	};
	return FILENAME_TOKENS.map( ( token ) => ( { token, label: labels[ token ] || token } ) );
}
```

(`__` is already imported at the top of `namingModel.js`.)

- [ ] **Step 8: Run both test files to verify they pass**

Run: `npx wp-scripts test-unit-js src/block-editor/tokenInsert.test.js src/block-editor/namingModel.test.js`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add src/block-editor/tokenInsert.js src/block-editor/tokenInsert.test.js src/block-editor/namingModel.js src/block-editor/namingModel.test.js
git commit -m "feat(block-editor): pure token helpers (prefix/filename chips + caret insert)"
```

---

### Task 2: `TokenField` component (chips: click-to-insert + draggable)

**Files:**
- Create: `src/block-editor/TokenField.js`
- Modify: `assets/css/block-editor-shell.css` (append chip styles)

**Interfaces:**
- Produces: default export `TokenField({ label, value, onChange, tokens, help })` where `tokens` is `Array<{token,label}>` (from `prefixTokens()` / `filenameTokenChips()`), `value: string`, `onChange: (string) => void`.
- Consumes: `insertAtCaret` from `./tokenInsert`.

> No render-based unit test: the project has no React test renderer and tests pure helpers only (the insertion logic is already covered in Task 1). This component is a thin wrapper; verify it live in Task 4's acceptance.

- [ ] **Step 1: Implement `TokenField.js`**

Create `src/block-editor/TokenField.js`:

```js
import { useRef, useCallback } from '@wordpress/element';
import { insertAtCaret } from './tokenInsert';

// A text input plus a row of token chips. Each chip inserts its token at the
// input caret on click, and is draggable (drop inserts at the caret too). The
// field stays free-text — chips insert, they don't lock it into segments.
export default function TokenField( { label, value, onChange, tokens, help } ) {
	const inputRef = useRef( null );

	const insert = useCallback( ( token ) => {
		const el = inputRef.current;
		const start = el ? el.selectionStart : null;
		const end = el ? el.selectionEnd : null;
		const r = insertAtCaret( value, start, end, token );
		onChange( r.value );
		// Restore focus + caret after React applies the new value.
		requestAnimationFrame( () => {
			if ( inputRef.current ) {
				inputRef.current.focus();
				inputRef.current.setSelectionRange( r.caret, r.caret );
			}
		} );
	}, [ value, onChange ] );

	return (
		<div className="woi-token-field components-base-control">
			{ label ? (
				<label className="components-base-control__label">{ label }</label>
			) : null }
			<input
				ref={ inputRef }
				type="text"
				className="components-text-control__input"
				value={ value || '' }
				onChange={ ( e ) => onChange( e.target.value ) }
				onDragOver={ ( e ) => e.preventDefault() }
				onDrop={ ( e ) => {
					e.preventDefault();
					const token = e.dataTransfer.getData( 'text/plain' );
					if ( token ) { insert( token ); }
				} }
			/>
			<div className="woi-token-chips">
				{ tokens.map( ( t ) => (
					<button
						type="button"
						key={ t.token }
						className="woi-token-chip"
						draggable={ true }
						onDragStart={ ( e ) => e.dataTransfer.setData( 'text/plain', t.token ) }
						onClick={ () => insert( t.token ) }
						title={ t.token }
					>
						{ t.label }
					</button>
				) ) }
			</div>
			{ help ? <p className="components-base-control__help">{ help }</p> : null }
		</div>
	);
}
```

- [ ] **Step 2: Append chip styles to `assets/css/block-editor-shell.css`**

```css
/* Token builder chips (Numbering & filename panel) */
.woi-block-wrap .woi-token-field { margin-bottom: 12px; }
.woi-block-wrap .woi-token-chips {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin-top: 4px;
}
.woi-block-wrap .woi-token-chip {
	font-size: 11px;
	line-height: 1.6;
	padding: 0 6px;
	border: 1px solid #c3c4c7;
	border-radius: 2px;
	background: #f6f7f7;
	color: #1d2327;
	cursor: grab;
}
.woi-block-wrap .woi-token-chip:hover { background: #e9eaeb; }
.woi-block-wrap .woi-token-chip:active { cursor: grabbing; }
```

- [ ] **Step 3: Verify the bundle compiles with the new component**

Run: `npx wp-scripts build`
Expected: `webpack ... compiled successfully` (no import errors). (Do not commit the bundle yet — the build is regenerated at landing.)

- [ ] **Step 4: Commit (source + CSS only)**

```bash
git add src/block-editor/TokenField.js assets/css/block-editor-shell.css
git commit -m "feat(block-editor): TokenField component (click + drag token chips)"
```

---

### Task 3: REST `POST /naming-preview` (server-resolved number + filename)

**Files:**
- Modify: `includes/Rest.php` (register route in `register_visual_template_route()`; add `handle_naming_preview` + a private `naming_preview_order` helper)
- Test: `tests/Unit/DocumentNamingPreviewRestTest.php` (new)

**Interfaces:**
- Produces (REST): `POST woi-pdf/v1/naming-preview` body `{ type, order_id, prefix, suffix, padding, next_number, filename_template }` → `{ number_preview: string, filename_preview: string, order_id: int, has_order: bool }`.
- Consumes: existing `Rest::numbering_types()` / `Rest::naming_types()`; globals `woi_pdf_get_document()`, `woi_pdf_format_document_number()`; the document's `get_filename( 'download', array(...) )`.

**Context:** The route lives in the ALWAYS-ON `register_visual_template_route()` (same place as `/document-naming` and `/editor-config`) — NOT the debug-gated `rest_api_init()` — so the panel never 404s. Find the existing `register_rest_route( 'woi-pdf/v1', '/document-naming', ... )` block (around line 150) and add the new route immediately after it.

The handler injects the *unsaved* prefix/suffix/padding/template into the per-type option for the duration of the request via an `option_woi_pdf_documents_settings_{type}` filter, then reads everything back through the real formatters — so the preview matches production exactly. `woi_pdf_get_filename_settings()` reads that option via `get_option` (woi-pdf-functions.php:306), so the filter takes effect.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/DocumentNamingPreviewRestTest.php`. This mirrors the Brain Monkey style of `tests/Unit/DocumentNamingRestTest.php` (read that file first for the exact harness: how it instantiates `Rest`, stubs `current_user_can`, builds a fake `WP_REST_Request`, and stubs WC/document globals). Assert:

```php
// Pseudocode of the assertions — adapt to the harness in DocumentNamingRestTest.php:
//
// 1) Unknown type -> WP_Error with status 400.
//    $req = fake_request( array( 'type' => 'not-a-type', 'order_id' => 1 ) );
//    $res = $rest->handle_naming_preview( $req );
//    assert $res is WP_Error and data['status'] === 400.
//
// 2) Series type with a stubbed order + document resolves both previews.
//    Stub woi_pdf_get_document() to return a fake document whose
//    get_filename('download', ...) returns 'invoice_2026-04-000004_2026-04-22.pdf',
//    and stub woi_pdf_format_document_number(...) to return '2026-04-000004'.
//    Stub wc_get_order(237) to a fake order (get_id()=237, get_order_number()='237').
//    $req = fake_request( array(
//        'type' => 'invoice', 'order_id' => 237, 'prefix' => '[invoice_year]-',
//        'suffix' => '', 'padding' => 6, 'next_number' => 4,
//        'filename_template' => '{document_type}_{document_number}_{date}',
//    ) );
//    $res = $rest->handle_naming_preview( $req );
//    assert $res['number_preview'] === '2026-04-000004';
//    assert $res['filename_preview'] === 'invoice_2026-04-000004_2026-04-22.pdf';
//    assert $res['has_order'] === true && $res['order_id'] === 237.
//
// 3) No order found anywhere -> has_order false, empty previews.
//    Stub wc_get_order() falsy and the recent-order query empty.
//    assert $res['has_order'] === false
//        && $res['number_preview'] === '' && $res['filename_preview'] === ''.
```

Write these as three real PHPUnit methods using the same `Brain\Monkey` `Functions\when(...)->justReturn(...)` / `alias(...)` stubs the sibling test uses.

- [ ] **Step 2: Run it to verify it fails**

Run: `php vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php tests/Unit/DocumentNamingPreviewRestTest.php`
Expected: FAIL — `Call to undefined method ...::handle_naming_preview()` (or a fatal until the method exists). Establish the baseline first per CLAUDE.md (some unrelated suites have known failures).

- [ ] **Step 3: Register the route**

In `includes/Rest.php`, immediately after the `'/document-naming'` `register_rest_route(...)` block, add:

```php
register_rest_route( 'woi-pdf/v1', '/naming-preview', array(
	array(
		'methods'             => 'POST',
		'callback'            => array( $this, 'handle_naming_preview' ),
		'permission_callback' => function () { return current_user_can( 'manage_woocommerce' ); },
		'args'                => array(
			'type' => array( 'type' => 'string', 'required' => true ),
		),
	),
) );
```

- [ ] **Step 4: Implement `handle_naming_preview` + `naming_preview_order`**

Add these methods to the `Rest` class (near `handle_save_document_naming`):

```php
/**
 * POST: resolve a live preview of the formatted number and the PDF filename
 * for a type, using the INCOMING (unsaved) prefix/suffix/padding/template so
 * the panel reflects edits before they are saved. Read-only: no option write,
 * no number-store increment.
 *
 * @param object $request
 * @return array|\WP_Error
 */
public function handle_naming_preview( $request ) {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return new \WP_Error( 'forbidden', 'Insufficient permissions', array( 'status' => 403 ) );
	}
	$type = sanitize_text_field( (string) $request->get_param( 'type' ) );
	if ( ! in_array( $type, self::naming_types(), true ) ) {
		return new \WP_Error( 'invalid_type', 'Unknown document type', array( 'status' => 400 ) );
	}

	$has_series = in_array( $type, self::numbering_types(), true );
	$prefix      = (string) $request->get_param( 'prefix' );
	$suffix      = (string) $request->get_param( 'suffix' );
	$padding     = (int) $request->get_param( 'padding' );
	$next_number = (int) $request->get_param( 'next_number' );
	$template    = trim( (string) $request->get_param( 'filename_template' ) );

	$order = $this->naming_preview_order( $type, (int) $request->get_param( 'order_id' ) );
	if ( ! $order ) {
		return array(
			'number_preview'   => '',
			'filename_preview' => '',
			'order_id'         => 0,
			'has_order'        => false,
		);
	}

	// Inject the unsaved values into the per-type option for THIS request only,
	// so both the number formatter and get_filename() reflect the edits without
	// persisting anything. woi_pdf_get_filename_settings() and the document's
	// settings both read this option via get_option, so the filter applies.
	$inject = function ( $value ) use ( $template, $prefix, $suffix, $padding, $has_series ) {
		$value = (array) $value;
		$value['filename_template'] = $template; // '' -> global template fallback
		if ( $has_series ) {
			$value['number_format'] = array(
				'prefix'  => $prefix,
				'suffix'  => $suffix,
				'padding' => (string) $padding,
			);
		}
		return $value;
	};
	add_filter( "option_woi_pdf_documents_settings_{$type}", $inject );

	$document = function_exists( 'woi_pdf_get_document' ) ? woi_pdf_get_document( $type, $order ) : false;

	$number_preview = '';
	if ( $document && $has_series && function_exists( 'woi_pdf_format_document_number' ) ) {
		$number_preview = (string) woi_pdf_format_document_number(
			$next_number, $prefix, $suffix, $padding, $document, $order
		);
	}

	$filename_preview = '';
	if ( $document && is_callable( array( $document, 'get_filename' ) ) ) {
		$filename_preview = (string) $document->get_filename( 'download', array(
			'order_ids' => array( $order->get_id() ),
			'output'    => 'pdf',
		) );
	}

	remove_filter( "option_woi_pdf_documents_settings_{$type}", $inject );

	return array(
		'number_preview'   => $number_preview,
		'filename_preview' => $filename_preview,
		'order_id'         => (int) $order->get_id(),
		'has_order'        => true,
	);
}

/**
 * Resolve the order to preview against: the requested id, else the most recent
 * shop order. Returns false when no order exists.
 *
 * @param string $type
 * @param int    $order_id
 * @return \WC_Abstract_Order|false
 */
private function naming_preview_order( string $type, int $order_id ) {
	if ( $order_id > 0 ) {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			return $order;
		}
	}
	$recent = wc_get_orders( array( 'limit' => 1, 'return' => 'ids', 'type' => 'shop_order' ) );
	if ( ! empty( $recent ) ) {
		$order = wc_get_order( reset( $recent ) );
		if ( $order ) {
			return $order;
		}
	}
	return false;
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php tests/Unit/DocumentNamingPreviewRestTest.php`
Expected: PASS (3/3), output pristine.

- [ ] **Step 6: Commit**

```bash
git add includes/Rest.php tests/Unit/DocumentNamingPreviewRestTest.php
git commit -m "feat(rest): naming-preview endpoint (server-resolved number + filename)"
```

---

### Task 4: Wire `NamingPanel` to TokenField + live preview

**Files:**
- Modify: `src/block-editor/store.js` (add `getNamingPreview`)
- Modify: `src/block-editor/NamingPanel.js` (TokenField for prefix/suffix/filename + debounced preview lines + `orderId` prop)
- Modify: `src/block-editor/index.js` (pass `orderId` to `<NamingPanel />`)

**Interfaces:**
- Produces (store): `getNamingPreview(payload: object) => Promise<{ number_preview, filename_preview, order_id, has_order }>`.
- Consumes: `TokenField` (Task 2), `prefixTokens`/`filenameTokenChips` (Task 1), `hasSeries`/`buildNamingPayload`/`FILENAME_TOKENS` (existing).
- `NamingPanel` gains a prop: `orderId: number|0`.

> No render unit test (no React renderer in this project); the pure pieces are tested in Tasks 1/3. Acceptance is the live check in Step 6.

- [ ] **Step 1: Add `getNamingPreview` to `store.js`**

Append to `src/block-editor/store.js`:

```js
export function getNamingPreview( payload ) {
	return post( 'naming-preview', payload );
}
```

- [ ] **Step 2: Pass `orderId` into NamingPanel from `index.js`**

In `src/block-editor/index.js`, change the render `<NamingPanel />` (in the Document sidebar, under the "Numbering & filename" section) to:

```js
<NamingPanel orderId={ orderId } />
```

(`orderId` is already in scope — it's read via `useSelect( STORE )` at the top of `Editor`.)

- [ ] **Step 3: Rewrite `NamingPanel.js` to use TokenField + preview**

Replace the prefix/suffix/filename `TextControl`s with `TokenField`, and add a debounced preview. Full file:

```js
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { SelectControl, TextControl, ToggleControl, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import {
	NAMING_TYPES, hasSeries, buildNamingPayload, FILENAME_TOKENS,
	prefixTokens, filenameTokenChips,
} from './namingModel';
import { getDocumentNaming, saveDocumentNaming, getNamingPreview } from './store';
import TokenField from './TokenField';

export default function NamingPanel( { orderId = 0 } ) {
	const [ type, setType ] = useState( 'invoice' );
	const [ values, setValues ] = useState( null ); // null => loading
	const [ preview, setPreview ] = useState( null );
	const debounceRef = useRef( null );
	const previewRef = useRef( null );

	// Load the selected type's settings whenever the type changes.
	useEffect( () => {
		let active = true;
		setValues( null );
		getDocumentNaming( type )
			.then( ( r ) => { if ( active ) { setValues( r ); } } )
			.catch( () => { if ( active ) { setValues( {} ); } } );
		return () => {
			active = false;
			if ( debounceRef.current ) { clearTimeout( debounceRef.current ); }
			if ( previewRef.current ) { clearTimeout( previewRef.current ); }
		};
	}, [ type ] );

	const persist = useCallback( ( next ) => {
		if ( debounceRef.current ) { clearTimeout( debounceRef.current ); }
		debounceRef.current = setTimeout( () => {
			saveDocumentNaming( buildNamingPayload( type, next ) )
				.then( ( r ) => setValues( r ) )
				.catch( () => {} );
		}, 500 );
	}, [ type ] );

	// Debounced server-resolved preview of the number + filename for the loaded
	// order. Uses the unsaved field values so it reflects edits live.
	const refreshPreview = useCallback( ( next ) => {
		if ( previewRef.current ) { clearTimeout( previewRef.current ); }
		previewRef.current = setTimeout( () => {
			getNamingPreview( {
				type,
				order_id: orderId || 0,
				prefix: next.prefix || '',
				suffix: next.suffix || '',
				padding: next.padding ?? '',
				next_number: next.next_number,
				filename_template: next.filename_template || '',
			} ).then( ( r ) => setPreview( r ) ).catch( () => {} );
		}, 250 );
	}, [ type, orderId ] );

	const onField = useCallback( ( key, value ) => {
		setValues( ( prev ) => {
			const next = { ...( prev || {} ), [ key ]: value };
			persist( next );
			refreshPreview( next );
			return next;
		} );
	}, [ persist, refreshPreview ] );

	// Refresh the preview when values first load or the order changes.
	useEffect( () => {
		if ( values ) { refreshPreview( values ); }
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ values === null, orderId, type ] );

	const series = hasSeries( type );

	return (
		<div className="woi-naming-panel">
			<SelectControl
				label={ __( 'Document type', 'woocommerce-orders-invoice-pdf' ) }
				value={ type }
				options={ NAMING_TYPES.map( ( t ) => ( { value: t.value, label: t.label } ) ) }
				onChange={ ( v ) => setType( v ) }
				__nextHasNoMarginBottom
			/>

			{ null === values ? (
				<Spinner />
			) : (
				<>
					{ series && (
						<>
							<TokenField
								label={ __( 'Number prefix', 'woocommerce-orders-invoice-pdf' ) }
								value={ values.prefix || '' }
								onChange={ ( v ) => onField( 'prefix', v ) }
								tokens={ prefixTokens( type ) }
							/>
							<TokenField
								label={ __( 'Number suffix', 'woocommerce-orders-invoice-pdf' ) }
								value={ values.suffix || '' }
								onChange={ ( v ) => onField( 'suffix', v ) }
								tokens={ prefixTokens( type ) }
							/>
							<TextControl
								type="number"
								label={ __( 'Padding (digits)', 'woocommerce-orders-invoice-pdf' ) }
								value={ values.padding || '' }
								onChange={ ( v ) => onField( 'padding', v ) }
								__nextHasNoMarginBottom
							/>
							<TextControl
								type="number"
								label={ __( 'Next number', 'woocommerce-orders-invoice-pdf' ) }
								help={ __( 'Setting this lower than the current highest number can create duplicates.', 'woocommerce-orders-invoice-pdf' ) }
								value={ undefined === values.next_number || null === values.next_number ? '' : values.next_number }
								onChange={ ( v ) => onField( 'next_number', v ? parseInt( v, 10 ) : '' ) }
								__nextHasNoMarginBottom
							/>
							<ToggleControl
								label={ __( 'Reset number yearly', 'woocommerce-orders-invoice-pdf' ) }
								checked={ !! values.reset_number_yearly }
								onChange={ ( v ) => onField( 'reset_number_yearly', v ) }
								__nextHasNoMarginBottom
							/>
						</>
					) }

					<TokenField
						label={ __( 'PDF filename override', 'woocommerce-orders-invoice-pdf' ) }
						help={ __( 'Leave blank to use the global template. Tokens: ', 'woocommerce-orders-invoice-pdf' ) + FILENAME_TOKENS.join( ' ' ) }
						value={ values.filename_template || '' }
						onChange={ ( v ) => onField( 'filename_template', v ) }
						tokens={ filenameTokenChips() }
					/>

					{ preview && preview.has_order ? (
						<div className="woi-naming-preview">
							{ series ? (
								<p><strong>{ __( 'Number', 'woocommerce-orders-invoice-pdf' ) }:</strong> { preview.number_preview }</p>
							) : null }
							<p><strong>{ __( 'Filename', 'woocommerce-orders-invoice-pdf' ) }:</strong> { preview.filename_preview }</p>
						</div>
					) : (
						<p className="woi-naming-preview woi-naming-preview--empty">
							{ __( 'Select an order to preview the number and filename.', 'woocommerce-orders-invoice-pdf' ) }
						</p>
					) }
				</>
			) }
		</div>
	);
}
```

- [ ] **Step 4: Add preview-line styles to `assets/css/block-editor-shell.css`**

```css
.woi-block-wrap .woi-naming-preview {
	margin-top: 8px;
	padding: 6px 8px;
	background: #f6f7f7;
	border: 1px solid #e0e0e0;
	border-radius: 2px;
	font-size: 12px;
	word-break: break-all;
}
.woi-block-wrap .woi-naming-preview p { margin: 0 0 2px; }
.woi-block-wrap .woi-naming-preview--empty { color: #757575; background: none; border: none; }
```

- [ ] **Step 5: Build and verify the bundle compiles**

Run: `npx wp-scripts build`
Expected: `compiled successfully`. Also run the full Jest suite to confirm no regression:
Run: `npx wp-scripts test-unit-js`
Expected: all suites pass.

- [ ] **Step 6: Live acceptance (after landing/deploy)**

In the Block editor "Numbering & filename" panel: clicking a prefix chip inserts e.g. `[invoice_year]` at the caret; dragging a chip into the field inserts it; the preview lines update ~250 ms after editing and show the resolved Number (series types) and Filename for order #237; switching to Packing slip hides the numbering fields/preview-number and shows only the filename field + filename preview.

- [ ] **Step 7: Commit**

```bash
git add src/block-editor/store.js src/block-editor/NamingPanel.js src/block-editor/index.js assets/css/block-editor-shell.css
git commit -m "feat(block-editor): wire TokenField + debounced live preview into NamingPanel"
```

---

### Task 5: Land (version bump + build + push)

**Files:**
- Modify: `woocommerce-orders-invoice-pdf.php` (BOTH version strings)
- Rebuilt: `assets/js/block-editor/index.js` (+ `index.asset.php`)

- [ ] **Step 1: Sync to latest origin/master**

```bash
git fetch origin && git rebase origin/master
git show origin/master:woocommerce-orders-invoice-pdf.php | grep -i "Version\|public string \$version" | head -2
```
Note the TRUE current version; the new version is the next free patch above it.

- [ ] **Step 2: Bump BOTH version strings**

In `woocommerce-orders-invoice-pdf.php`: line ~6 `* Version:` header AND line ~24 `public string $version`. Set both to `<next-patch>`.

- [ ] **Step 3: Rebuild the bundle on the rebased source**

Run: `npm run build`
Then confirm the bundle changed: `git status --short -- assets/js/block-editor/index.js` shows it modified.

- [ ] **Step 4: Commit version + build (stage EXPLICITLY — never `git add -A`; worktrees show ~800 Strauss noise files)**

```bash
git add woocommerce-orders-invoice-pdf.php assets/js/block-editor/index.js assets/js/block-editor/index.asset.php
git commit -m "chore: v<next-patch> + build"
```

- [ ] **Step 5: Fast-forward push**

```bash
git fetch origin
git merge-base --is-ancestor origin/master HEAD && git push origin HEAD:master
```
If rejected (someone landed in between): `git rebase origin/master`, re-bump to the next free patch, `npm run build`, re-commit the chore, push again.

- [ ] **Step 6: Sync main checkout + tear down the worktree (junction-safe)**

In the main checkout: `git pull --ff-only origin master` (or `git rebase origin/master` if it carries local doc commits).
Then remove the worktree junction FIRST (`cmd //c rmdir node_modules` inside the worktree), then `git worktree remove --force ../woi-<name>`, `git worktree prune`, `git branch -D feat/<name>`. Verify the MAIN checkout's `node_modules` is intact.

- [ ] **Step 7: Update the version-coordination memory** headline to the new version + feature summary.

---

## Self-Review

**Spec coverage:**
- Component A (`TokenField` chips + draggable) → Task 2 (+ pure logic Task 1). ✅
- Component B (two distinct token sets) → Task 1 (`prefixTokens`/`filenameTokenChips`). ✅
- Component C (server-resolved live preview, read-only endpoint, recent-order fallback) → Task 3. ✅
- Component D (NamingPanel wiring, orderId from store, debounced 250 ms, preview lines, "select an order" hint, save path unchanged) → Task 4. ✅
- Testing (Jest pure helpers + PHPUnit endpoint) → Tasks 1 & 3. ✅
- Scope guard (no token reordering, field stays free-text, no new tokens, endpoint read-only) → honoured across tasks. ✅

**Placeholder scan:** The PHPUnit test in Task 3 Step 1 is described as assertions to adapt to the sibling harness rather than verbatim code — this is deliberate (the Brain Monkey stub setup must match `DocumentNamingRestTest.php`, which the implementer must read first). All other code steps contain complete code.

**Type consistency:** `insertAtCaret(value,start,end,token) => {value,caret}` (Task 1) used identically in `TokenField` (Task 2). `tokens` prop is `Array<{token,label}>` from `prefixTokens`/`filenameTokenChips` (Task 1) and consumed by `TokenField` (Task 2) and `NamingPanel` (Task 4). `getNamingPreview(payload)` (Task 4 store) ↔ endpoint shape `{number_preview,filename_preview,order_id,has_order}` (Task 3). Consistent.
