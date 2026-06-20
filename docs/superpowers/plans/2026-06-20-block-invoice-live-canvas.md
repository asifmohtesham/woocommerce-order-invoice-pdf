# Block Invoice Template — Live A4 WYSIWYG Canvas Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Block Invoice Template canvas a live, A4-accurate WYSIWYG document — every token block renders the selected order's real data, the canvas is an iframe-isolated A4 page with mm rulers and page-break guides, and a GrapesJS-style order combobox (with order-# → Enter) drives it.

**Architecture:** Front-end-only restructuring of `src/block-editor/`. A pure preview-state module feeds a `@wordpress/data` store (`woi/preview`); token block `edit()`s read it and render merged values. The `BlockList` moves into `@wordpress/block-editor`'s `BlockCanvas` iframe with the shared document CSS + an A4 shim; mm rulers and page guides wrap it. A React order combobox dispatches fetched token maps into the store. The side panel drops Live HTML and keeps only the PDF tab. No backend, `save()`, stored-markup, or render-path change.

**Tech Stack:** `@wordpress/scripts` 30 (webpack multi-entry + jest `test-unit-js`), `@wordpress/{block-editor,blocks,data,element,components,i18n}`, PDF.js (already enqueued), plain ES modules.

## Global Constraints

- **No backend change:** do not touch REST endpoints, AJAX actions, `save()` output, stored markup, or the mPDF render path. All data (token map incl. `{{line_items}}`/`{{totals}}`/`{{logo}}`/`{{billing_address}}` as HTML; rich order-search metadata) already exists.
- **Block `save()` is frozen** — only `edit()` changes. No block-validation/stored-markup risk.
- **Unit-tested helpers must be plain JS with zero `@wordpress/*` imports** — those are externalized at build (`wp.data`, etc.) and may not resolve under jest. `@wordpress`-dependent code is verified by build + live acceptance.
- **Webpack `output.clean:false` must stay** in `webpack.config.js` — removing it wipes sibling `assets/js/*` (admin.js, pdf_js worker). Do not touch it.
- **Build:** `npm run build` (root multi-entry: home + block-editor → `assets/js/block-editor/`).
- **Asset cache-bust on the final task:** bump BOTH the `Version:` header (line ~6 of the main plugin file) and `public string $version` (drives `WOI_PDF_VERSION`) in lockstep. CHECK current version first and coordinate per the `version-coordination` memory (one git worktree per concurrent instance; fast-forward push, don't checkout master in a shared checkout).
- **i18n:** all user-facing strings via `__( '…', 'woocommerce-orders-invoice-pdf' )`.
- **GrapesJS editor (`VisualEditorPage`) is untouched.**

---

### Task 1: Test harness + token merge helper (pure)

**Files:**
- Modify: `package.json` (add `test:unit` script)
- Create: `src/block-editor/tokenMerge.js`
- Test: `src/block-editor/tokenMerge.test.js`

**Interfaces:**
- Produces:
  - `isHtmlToken( token: string ): boolean` — true for the four HTML-valued tokens.
  - `tokenValue( token: string, tokens: object|null ): string` — the merged string for one token (`''` when missing/null).
  - `HTML_TOKENS: Set<string>`

- [ ] **Step 1: Add the test script**

In `package.json`, add to `"scripts"`:

```json
		"build": "wp-scripts build",
		"start": "wp-scripts start",
		"test:unit": "wp-scripts test-unit-js"
```

- [ ] **Step 2: Write the failing test**

Create `src/block-editor/tokenMerge.test.js`:

```js
import { isHtmlToken, tokenValue } from './tokenMerge';

describe( 'isHtmlToken', () => {
	it( 'is true for HTML-valued tokens', () => {
		expect( isHtmlToken( '{{line_items}}' ) ).toBe( true );
		expect( isHtmlToken( '{{totals}}' ) ).toBe( true );
		expect( isHtmlToken( '{{logo}}' ) ).toBe( true );
		expect( isHtmlToken( '{{billing_address}}' ) ).toBe( true );
	} );
	it( 'is false for plain-text tokens', () => {
		expect( isHtmlToken( '{{shop_name}}' ) ).toBe( false );
		expect( isHtmlToken( '{{trn}}' ) ).toBe( false );
	} );
} );

describe( 'tokenValue', () => {
	it( 'returns the mapped value', () => {
		expect( tokenValue( '{{shop_name}}', { '{{shop_name}}': 'Acme' } ) ).toBe( 'Acme' );
	} );
	it( 'returns empty string when missing or map is null', () => {
		expect( tokenValue( '{{shop_name}}', {} ) ).toBe( '' );
		expect( tokenValue( '{{shop_name}}', null ) ).toBe( '' );
	} );
	it( 'coerces non-string values to string', () => {
		expect( tokenValue( '{{order_number}}', { '{{order_number}}': 4242 } ) ).toBe( '4242' );
	} );
} );
```

- [ ] **Step 3: Run test to verify it fails**

Run: `npm run test:unit -- src/block-editor/tokenMerge.test.js`
Expected: FAIL — `Cannot find module './tokenMerge'`.

- [ ] **Step 4: Write the implementation**

Create `src/block-editor/tokenMerge.js`:

```js
// Tokens whose merged value is HTML (rendered via dangerouslySetInnerHTML),
// not plain text. Everything else is treated as text.
export const HTML_TOKENS = new Set( [
	'{{logo}}',
	'{{billing_address}}',
	'{{line_items}}',
	'{{totals}}',
] );

export function isHtmlToken( token ) {
	return HTML_TOKENS.has( token );
}

// The merged string for one token; '' when absent or the map is null/undefined.
export function tokenValue( token, tokens ) {
	const map = tokens || {};
	if ( ! Object.prototype.hasOwnProperty.call( map, token ) ) {
		return '';
	}
	const raw = map[ token ];
	return ( null === raw || undefined === raw ) ? '' : String( raw );
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `npm run test:unit -- src/block-editor/tokenMerge.test.js`
Expected: PASS (all 3 describe blocks green).

- [ ] **Step 6: Commit**

```bash
git add package.json src/block-editor/tokenMerge.js src/block-editor/tokenMerge.test.js
git commit -m "feat(block-editor): token merge helper + jest unit-test harness"
```

---

### Task 2: Preview state (pure reducer/actions/selectors)

**Files:**
- Create: `src/block-editor/previewState.js`
- Test: `src/block-editor/previewState.test.js`

**Interfaces:**
- Produces:
  - `initialState( sample: object ): { tokens, orderLabel, orderId, loading }`
  - `reducer( state, action )`
  - `actions.setLoading( loading: boolean )` → `{ type:'SET_LOADING', loading }`
  - `actions.setOrder( { tokens, orderLabel, orderId } )` → `{ type:'SET_ORDER', ... }`
  - `selectors.{ getTokens, getOrderLabel, getOrderId, isLoading }`
- These are consumed by `previewStore.js` (Task 3) to build the registered store. Kept `@wordpress`-free so they can be unit-tested.

- [ ] **Step 1: Write the failing test**

Create `src/block-editor/previewState.test.js`:

```js
import { initialState, reducer, actions, selectors } from './previewState';

describe( 'preview state', () => {
	it( 'seeds tokens from the sample map', () => {
		const s = initialState( { '{{shop_name}}': 'Acme' } );
		expect( selectors.getTokens( s ) ).toEqual( { '{{shop_name}}': 'Acme' } );
		expect( selectors.isLoading( s ) ).toBe( false );
		expect( selectors.getOrderId( s ) ).toBe( null );
	} );

	it( 'setLoading toggles the loading flag', () => {
		const s = reducer( initialState( {} ), actions.setLoading( true ) );
		expect( selectors.isLoading( s ) ).toBe( true );
	} );

	it( 'setOrder replaces tokens/label/id and clears loading', () => {
		let s = reducer( initialState( {} ), actions.setLoading( true ) );
		s = reducer( s, actions.setOrder( { tokens: { a: 1 }, orderLabel: '#5 — Acme', orderId: 5 } ) );
		expect( selectors.getTokens( s ) ).toEqual( { a: 1 } );
		expect( selectors.getOrderLabel( s ) ).toBe( '#5 — Acme' );
		expect( selectors.getOrderId( s ) ).toBe( 5 );
		expect( selectors.isLoading( s ) ).toBe( false );
	} );

	it( 'setOrder keeps prior tokens when none supplied', () => {
		let s = initialState( { keep: 1 } );
		s = reducer( s, actions.setOrder( { orderLabel: 'x', orderId: 9 } ) );
		expect( selectors.getTokens( s ) ).toEqual( { keep: 1 } );
	} );
} );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run test:unit -- src/block-editor/previewState.test.js`
Expected: FAIL — `Cannot find module './previewState'`.

- [ ] **Step 3: Write the implementation**

Create `src/block-editor/previewState.js`:

```js
// Pure preview state: no @wordpress imports so it is unit-testable. previewStore.js
// wraps this in a registered @wordpress/data store.

export function initialState( sample ) {
	return { tokens: sample || {}, orderLabel: '', orderId: null, loading: false };
}

export function reducer( state, action ) {
	switch ( action.type ) {
		case 'SET_LOADING':
			return { ...state, loading: action.loading };
		case 'SET_ORDER':
			return {
				...state,
				tokens: action.tokens || state.tokens,
				orderLabel: action.orderLabel || '',
				orderId: ( undefined === action.orderId ? null : action.orderId ),
				loading: false,
			};
		default:
			return state;
	}
}

export const actions = {
	setLoading( loading ) {
		return { type: 'SET_LOADING', loading };
	},
	setOrder( { tokens, orderLabel, orderId } ) {
		return { type: 'SET_ORDER', tokens, orderLabel, orderId };
	},
};

export const selectors = {
	getTokens( state ) { return state.tokens; },
	getOrderLabel( state ) { return state.orderLabel; },
	getOrderId( state ) { return state.orderId; },
	isLoading( state ) { return state.loading; },
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npm run test:unit -- src/block-editor/previewState.test.js`
Expected: PASS (4 tests green).

- [ ] **Step 5: Commit**

```bash
git add src/block-editor/previewState.js src/block-editor/previewState.test.js
git commit -m "feat(block-editor): pure preview state (reducer/actions/selectors)"
```

---

### Task 3: Register the `woi/preview` store + make token blocks render live

**Files:**
- Create: `src/block-editor/previewStore.js`
- Modify: `src/block-editor/blocks/token.js` (the `edit()` of every token block)

**Interfaces:**
- Consumes: `initialState/reducer/actions/selectors` (Task 2); `isHtmlToken/tokenValue` (Task 1).
- Produces:
  - `STORE = 'woi/preview'` (export from `previewStore.js`)
  - Side effect on import: `register()`s the store, seeded from `window.woiBlocks.sampleData`.

- [ ] **Step 1: Create the store registration**

Create `src/block-editor/previewStore.js`:

```js
import { createReduxStore, register } from '@wordpress/data';
import { initialState, reducer, actions, selectors } from './previewState';

export const STORE = 'woi/preview';

const seed = initialState( ( window.woiBlocks && window.woiBlocks.sampleData ) || {} );

const store = createReduxStore( STORE, {
	reducer( state = seed, action ) {
		return reducer( state, action );
	},
	actions,
	selectors,
} );

register( store );

export default store;
```

- [ ] **Step 2: Wire token block `edit()` to the store**

In `src/block-editor/blocks/token.js`, update the imports at the top:

```js
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { STORE } from '../previewStore';
import { isHtmlToken, tokenValue } from '../tokenMerge';
```

Replace the `edit()` method inside `registerTokenBlocks()` (the `save()` stays exactly as-is):

```js
			edit() {
				const Tag = tag;
				const tokens = useSelect( ( select ) => select( STORE ).getTokens(), [] );
				const value = tokenValue( token, tokens );
				const blockProps = useBlockProps( { className: value ? undefined : 'woi-token-empty' } );
				if ( ! value ) {
					// No order picked / token empty: show the friendly label so the
					// block stays visible and selectable.
					return <Tag { ...blockProps }>{ preview }</Tag>;
				}
				if ( isHtmlToken( token ) ) {
					// Server-trusted HTML from the token map (logo, billing address,
					// line-items table, totals table).
					return <Tag { ...blockProps } dangerouslySetInnerHTML={ { __html: value } } />;
				}
				return <Tag { ...blockProps }>{ value }</Tag>;
			},
```

- [ ] **Step 3: Build**

Run: `npm run build`
Expected: builds cleanly; `assets/js/block-editor/index.js` regenerated. (No test for this task — verified live in Task 9.)

- [ ] **Step 4: Commit**

```bash
git add src/block-editor/previewStore.js src/block-editor/blocks/token.js assets/js/block-editor/
git commit -m "feat(block-editor): live token store + token blocks render merged order data"
```

---

### Task 4: Ruler math (pure)

**Files:**
- Create: `src/block-editor/canvas/rulers.js`
- Test: `src/block-editor/canvas/rulers.test.js`

**Interfaces:**
- Produces:
  - `majorMarks( lengthMm: number, every = 10 ): number[]` — `[0, every, …]` up to and including `lengthMm` when divisible, else up to the last mark `<= lengthMm`.
  - `pageBoundaries( contentMm: number, pageMm = 297 ): number[]` — interior page-break offsets (`[297, 594, …]`), excluding 0 and the final edge.
- Consumed by `Rulers.js` and `Canvas.js` (Task 5).

- [ ] **Step 1: Write the failing test**

Create `src/block-editor/canvas/rulers.test.js`:

```js
import { majorMarks, pageBoundaries } from './rulers';

describe( 'majorMarks', () => {
	it( 'marks every 10mm including the end when divisible', () => {
		expect( majorMarks( 210 ) ).toEqual( [ 0, 10, 20, 30, 40, 50, 60, 70, 80, 90, 100, 110, 120, 130, 140, 150, 160, 170, 180, 190, 200, 210 ] );
	} );
	it( 'stops at the last mark not exceeding length', () => {
		expect( majorMarks( 25 ) ).toEqual( [ 0, 10, 20 ] );
	} );
	it( 'honours a custom interval', () => {
		expect( majorMarks( 60, 30 ) ).toEqual( [ 0, 30, 60 ] );
	} );
} );

describe( 'pageBoundaries', () => {
	it( 'returns interior A4 page offsets', () => {
		expect( pageBoundaries( 700 ) ).toEqual( [ 297, 594 ] );
	} );
	it( 'is empty for a single page', () => {
		expect( pageBoundaries( 200 ) ).toEqual( [] );
		expect( pageBoundaries( 297 ) ).toEqual( [] );
	} );
} );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run test:unit -- src/block-editor/canvas/rulers.test.js`
Expected: FAIL — `Cannot find module './rulers'`.

- [ ] **Step 3: Write the implementation**

Create `src/block-editor/canvas/rulers.js`:

```js
// Major tick positions (mm) from 0 to lengthMm at the given interval.
export function majorMarks( lengthMm, every = 10 ) {
	const out = [];
	for ( let mm = 0; mm <= lengthMm + 1e-6; mm += every ) {
		out.push( Math.round( mm ) );
	}
	return out;
}

// Interior page-break offsets (mm) for a content height, every pageMm.
// Excludes 0 and anything at/after the content end.
export function pageBoundaries( contentMm, pageMm = 297 ) {
	const out = [];
	for ( let mm = pageMm; mm < contentMm; mm += pageMm ) {
		out.push( mm );
	}
	return out;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npm run test:unit -- src/block-editor/canvas/rulers.test.js`
Expected: PASS (5 tests green).

- [ ] **Step 5: Commit**

```bash
git add src/block-editor/canvas/rulers.js src/block-editor/canvas/rulers.test.js
git commit -m "feat(block-editor): pure ruler tick + page-boundary math"
```

---

### Task 5: A4 iframe canvas, rulers, and page guides

**Files:**
- Create: `src/block-editor/canvas/A4Canvas.js`
- Create: `src/block-editor/canvas/Rulers.js`
- Create: `src/block-editor/canvas/Canvas.js`
- Create: `src/block-editor/canvas/canvasStyles.js`

**Interfaces:**
- Consumes: `majorMarks`, `pageBoundaries` (Task 4).
- Produces:
  - `Canvas( { previewCss: string } )` — default export of `Canvas.js`; renders the gray scroll area with mm rulers, page guides, and the A4 page. Used by `index.js` (Task 8) inside `<BlockTools>`.
  - `injectCanvasStyles()` — default export of `canvasStyles.js`; idempotently injects the canvas/ruler `<style>` once.
  - `hasBlockCanvas(): boolean` — from `A4Canvas.js`.

- [ ] **Step 1: Write the A4 iframe canvas**

Create `src/block-editor/canvas/A4Canvas.js`:

```js
import { BlockCanvas, BlockList, WritingFlow, ObserveTyping } from '@wordpress/block-editor';

// Injected into the BlockCanvas iframe head, after the shared document CSS.
// Sizes the body as an A4 page and neutralises WP block chrome so the canvas
// reads as the printed document.
export const A4_SHIM_CSS =
	'html,body{margin:0;padding:0;background:transparent}' +
	'body{width:210mm;min-height:297mm;margin:0;padding:15mm;box-sizing:border-box;background:#fff}' +
	'.block-editor-block-list__layout.is-root-container{padding:0}' +
	'.block-editor-block-list__block{margin-top:0;margin-bottom:0}' +
	'.block-editor-block-list__block::before,.block-editor-block-list__block::after{display:none !important}' +
	'.is-selected.block-editor-block-list__block::before{display:block !important}' +
	'.woi-pagebreak{border-top:1px dashed #999;margin:0;height:0;page-break-after:auto}' +
	'.woi-token-empty{outline:1px dashed #c8c8c8;outline-offset:2px;color:#9aa;min-height:1em}';

export function hasBlockCanvas() {
	return 'function' === typeof BlockCanvas;
}

// The A4 page itself. Prefers the isolated BlockCanvas iframe; falls back to an
// in-DOM scoped render when BlockCanvas is unavailable in the installed WP.
export default function A4Canvas( { previewCss } ) {
	if ( hasBlockCanvas() ) {
		const styles = [ { css: previewCss || '' }, { css: A4_SHIM_CSS } ];
		return <BlockCanvas height="100%" styles={ styles } />;
	}
	return (
		<div className="woi-a4-fallback">
			<WritingFlow>
				<ObserveTyping>
					<BlockList />
				</ObserveTyping>
			</WritingFlow>
		</div>
	);
}
```

- [ ] **Step 2: Write the rulers + page guides**

Create `src/block-editor/canvas/Rulers.js`:

```js
import { __, sprintf } from '@wordpress/i18n';
import { majorMarks, pageBoundaries } from './rulers';

const A4_W = 210;
const A4_H = 297;

// Horizontal ruler across the top of the page (0..210mm).
export function TopRuler() {
	return (
		<div className="woi-ruler woi-ruler--top" aria-hidden="true">
			{ majorMarks( A4_W ).map( ( mm ) => (
				<span key={ mm } className="woi-ruler-mark" style={ { left: mm + 'mm' } }>{ mm }</span>
			) ) }
		</div>
	);
}

// Vertical ruler down the left edge (0..contentMm), with bold page-boundary marks.
export function LeftRuler( { contentMm } ) {
	const boundaries = new Set( pageBoundaries( contentMm ) );
	return (
		<div className="woi-ruler woi-ruler--left" aria-hidden="true" style={ { height: contentMm + 'mm' } }>
			{ majorMarks( contentMm ).map( ( mm ) => (
				<span
					key={ mm }
					className={ 'woi-ruler-mark' + ( boundaries.has( mm ) ? ' is-page' : '' ) }
					style={ { top: mm + 'mm' } }
				>{ mm }</span>
			) ) }
		</div>
	);
}

// Dashed page-break guide lines overlaid on the page at each 297mm boundary.
export function PageGuides( { contentMm } ) {
	return (
		<div className="woi-page-guides" aria-hidden="true">
			{ pageBoundaries( contentMm ).map( ( mm, i ) => (
				<div key={ mm } className="woi-page-guide" style={ { top: mm + 'mm' } }>
					<span className="woi-page-guide-label">
						{ sprintf( /* translators: %d: page number */ __( 'Page %d', 'woocommerce-orders-invoice-pdf' ), i + 2 ) }
					</span>
				</div>
			) ) }
		</div>
	);
}

export { A4_W, A4_H };
```

- [ ] **Step 3: Write the composing canvas (scroll + measurement + assembly)**

Create `src/block-editor/canvas/Canvas.js`:

```js
import { useRef, useState, useEffect } from '@wordpress/element';
import A4Canvas from './A4Canvas';
import { TopRuler, LeftRuler, PageGuides, A4_H } from './Rulers';

const PX_PER_MM = 96 / 25.4;

// Gray scrollable stage holding the mm rulers and the A4 page. Measures the page
// height (px → mm) so the left ruler and page guides span the real content.
export default function Canvas( { previewCss } ) {
	const pageRef = useRef( null );
	const [ contentMm, setContentMm ] = useState( A4_H );

	useEffect( () => {
		const el = pageRef.current;
		if ( ! el || 'undefined' === typeof ResizeObserver ) { return undefined; }
		const ro = new ResizeObserver( () => {
			const mm = Math.max( A4_H, el.offsetHeight / PX_PER_MM );
			setContentMm( Math.ceil( mm ) );
		} );
		ro.observe( el );
		return () => ro.disconnect();
	}, [] );

	return (
		<div className="woi-canvas-scroll">
			<div className="woi-a4-frame">
				<TopRuler />
				<div className="woi-a4-frame-body">
					<LeftRuler contentMm={ contentMm } />
					<div className="woi-a4-page" ref={ pageRef }>
						<PageGuides contentMm={ contentMm } />
						<A4Canvas previewCss={ previewCss } />
					</div>
				</div>
			</div>
		</div>
	);
}
```

- [ ] **Step 4: Write the canvas/ruler styles**

Create `src/block-editor/canvas/canvasStyles.js`:

```js
const CSS =
	'.woi-canvas-scroll{flex:1;min-height:0;overflow:auto;background:#525659;padding:24px}' +
	'.woi-a4-frame{position:relative;width:max-content;margin:0 auto;padding-left:8mm;padding-top:6mm}' +
	'.woi-a4-frame-body{position:relative;display:block}' +
	// top ruler
	'.woi-ruler{position:relative;background:#f3f3f3;color:#555;font-size:8px;line-height:1}' +
	'.woi-ruler--top{height:6mm;width:210mm;margin-left:8mm;position:sticky;top:0;z-index:3;' +
		'background-image:repeating-linear-gradient(to right,#bbb 0,#bbb 0.18mm,transparent 0.18mm,transparent 1mm),' +
		'repeating-linear-gradient(to right,#888 0,#888 0.25mm,transparent 0.25mm,transparent 10mm)}' +
	'.woi-ruler--top .woi-ruler-mark{position:absolute;top:0.5mm;transform:translateX(1px)}' +
	// left ruler
	'.woi-ruler--left{position:absolute;left:0;top:0;width:8mm;font-size:8px;' +
		'background-image:repeating-linear-gradient(to bottom,#bbb 0,#bbb 0.18mm,transparent 0.18mm,transparent 1mm),' +
		'repeating-linear-gradient(to bottom,#888 0,#888 0.25mm,transparent 0.25mm,transparent 10mm)}' +
	'.woi-ruler--left .woi-ruler-mark{position:absolute;left:0.5mm;transform:translateY(-1px)}' +
	'.woi-ruler--left .woi-ruler-mark.is-page{color:#c0392b;font-weight:700}' +
	// page
	'.woi-a4-page{position:relative;margin-left:8mm;width:210mm;min-height:297mm;background:#fff;' +
		'box-shadow:0 1px 6px rgba(0,0,0,.4)}' +
	'.woi-a4-page .block-editor-block-canvas,.woi-a4-page iframe{width:210mm;border:0;display:block}' +
	// fallback (no BlockCanvas): apply preview CSS scoped, padded like the page
	'.woi-a4-fallback{padding:15mm;box-sizing:border-box;min-height:297mm}' +
	// page-break guides
	'.woi-page-guides{position:absolute;inset:0;pointer-events:none;z-index:2}' +
	'.woi-page-guide{position:absolute;left:0;right:0;border-top:1px dashed #c0392b}' +
	'.woi-page-guide-label{position:absolute;right:2mm;top:1mm;font-size:8px;color:#c0392b;background:#fff;padding:0 2px}';

export default function injectCanvasStyles() {
	if ( document.getElementById( 'woi-canvas-styles' ) ) { return; }
	const el = document.createElement( 'style' );
	el.id = 'woi-canvas-styles';
	el.textContent = CSS;
	document.head.appendChild( el );
}
```

- [ ] **Step 5: Build**

Run: `npm run build`
Expected: builds cleanly (these modules are not yet imported by `index.js`; this confirms they compile). Wired in Task 8.

- [ ] **Step 6: Commit**

```bash
git add src/block-editor/canvas/A4Canvas.js src/block-editor/canvas/Rulers.js src/block-editor/canvas/Canvas.js src/block-editor/canvas/canvasStyles.js assets/js/block-editor/
git commit -m "feat(block-editor): A4 iframe canvas with mm rulers and page guides"
```

---

### Task 6: Order-number Enter detection (pure)

**Files:**
- Create: `src/block-editor/orderInput.js`
- Test: `src/block-editor/orderInput.test.js`

**Interfaces:**
- Produces: `parseOrderNumber( term: string ): string|null` — the bare digits when the term is an order number (optionally `#`-prefixed, whitespace-trimmed), else `null`.
- Consumed by `OrderPicker.js` (Task 7).

- [ ] **Step 1: Write the failing test**

Create `src/block-editor/orderInput.test.js`:

```js
import { parseOrderNumber } from './orderInput';

describe( 'parseOrderNumber', () => {
	it( 'returns the digits for a numeric term', () => {
		expect( parseOrderNumber( '4242' ) ).toBe( '4242' );
	} );
	it( 'strips a leading # and whitespace', () => {
		expect( parseOrderNumber( ' #4242 ' ) ).toBe( '4242' );
	} );
	it( 'returns null for non-numeric or empty terms', () => {
		expect( parseOrderNumber( 'john' ) ).toBe( null );
		expect( parseOrderNumber( '' ) ).toBe( null );
		expect( parseOrderNumber( '12a' ) ).toBe( null );
	} );
} );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run test:unit -- src/block-editor/orderInput.test.js`
Expected: FAIL — `Cannot find module './orderInput'`.

- [ ] **Step 3: Write the implementation**

Create `src/block-editor/orderInput.js`:

```js
// When a search term is a bare order number (optionally #-prefixed), return its
// digits so Enter can fetch that order directly; otherwise null (run a search).
export function parseOrderNumber( term ) {
	const t = String( term || '' ).trim().replace( /^#/, '' );
	return /^[0-9]+$/.test( t ) ? t : null;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npm run test:unit -- src/block-editor/orderInput.test.js`
Expected: PASS (3 tests green).

- [ ] **Step 5: Commit**

```bash
git add src/block-editor/orderInput.js src/block-editor/orderInput.test.js
git commit -m "feat(block-editor): order-number Enter detection helper"
```

---

### Task 7: Order combobox (GrapesJS-style)

**Files:**
- Create: `src/block-editor/OrderPicker.js`
- Modify: `src/block-editor/preview.js` (export a metadata-line helper; keep fetchers)

**Interfaces:**
- Consumes: `STORE` (Task 3); `parseOrderNumber` (Task 6); `fetchOrders`, `fetchOrderTokens`, `orderRowTitle` (existing `preview.js`).
- Produces: `OrderPicker()` — default export; a toolbar combobox that dispatches `woi/preview` updates. `orderMetaLine( row ): string` added to `preview.js`.

- [ ] **Step 1: Add the metadata-line helper to `preview.js`**

In `src/block-editor/preview.js`, append (keep `orderRowTitle`, `fetchOrders`, `fetchOrderTokens` as-is):

```js
// Secondary line for an order row: "AED 100 · 3 items / 5 units · 18 Jun · Credit Card".
// total_raw is wc_price HTML and must be rendered separately (innerHTML); this
// helper returns only the plain-text remainder.
export function orderMetaLine( d ) {
	const parts = [];
	const items = parseInt( d.line_count, 10 ) || 0;
	const units = parseInt( d.unit_count, 10 ) || 0;
	parts.push( items + ( 1 === items ? ' item' : ' items' ) + ' / ' + units + ( 1 === units ? ' unit' : ' units' ) );
	if ( d.date_raw ) { parts.push( d.date_raw ); }
	if ( d.payment_method ) { parts.push( d.payment_method ); }
	return parts.join( ' · ' );
}
```

- [ ] **Step 2: Write the combobox**

Create `src/block-editor/OrderPicker.js`:

```js
import { useState, useRef, useCallback, useEffect } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { STORE } from './previewStore';
import { parseOrderNumber } from './orderInput';
import { fetchOrders, fetchOrderTokens, orderRowTitle, orderMetaLine } from './preview';

export default function OrderPicker() {
	const [ term, setTerm ] = useState( '' );
	const [ results, setResults ] = useState( null );
	const [ open, setOpen ] = useState( false );
	const [ searching, setSearching ] = useState( false );
	const debounceRef = useRef( null );
	const boxRef = useRef( null );

	const { setOrder, setLoading } = useDispatch( STORE );
	const { orderLabel, loading } = useSelect( ( select ) => ( {
		orderLabel: select( STORE ).getOrderLabel(),
		loading: select( STORE ).isLoading(),
	} ), [] );

	const runSearch = useCallback( ( value ) => {
		setSearching( true );
		fetchOrders( value ).then( ( data ) => {
			setResults( data );
			setSearching( false );
			setOpen( true );
		} );
	}, [] );

	const loadOrder = useCallback( ( id, label ) => {
		setOpen( false );
		setResults( null );
		setTerm( label || '' );
		setLoading( true );
		fetchOrderTokens( id ).then( ( res ) => {
			if ( res && res.tokens ) {
				setOrder( { tokens: res.tokens, orderLabel: res.order_label || label || '', orderId: id } );
			} else {
				setLoading( false );
			}
		} );
	}, [ setOrder, setLoading ] );

	const onFocus = useCallback( () => {
		setOpen( true );
		if ( null === results ) { runSearch( '' ); } // focus → recents
	}, [ results, runSearch ] );

	const onChange = useCallback( ( e ) => {
		const value = e.target.value;
		setTerm( value );
		if ( debounceRef.current ) { clearTimeout( debounceRef.current ); }
		debounceRef.current = setTimeout( () => runSearch( value ), 300 );
	}, [ runSearch ] );

	const onKeyDown = useCallback( ( e ) => {
		if ( 'Enter' !== e.key ) { return; }
		e.preventDefault();
		if ( debounceRef.current ) { clearTimeout( debounceRef.current ); }
		const num = parseOrderNumber( term );
		if ( num ) {
			loadOrder( num, '#' + num );
		} else {
			runSearch( term );
		}
	}, [ term, loadOrder, runSearch ] );

	// Close the dropdown on outside click.
	useEffect( () => {
		function onDocClick( e ) {
			if ( boxRef.current && ! boxRef.current.contains( e.target ) ) { setOpen( false ); }
		}
		document.addEventListener( 'click', onDocClick );
		return () => document.removeEventListener( 'click', onDocClick );
	}, [] );

	const ids = results ? Object.keys( results ) : [];

	return (
		<div className="woi-order-picker" ref={ boxRef } style={ { position: 'relative', minWidth: '280px' } }>
			<div style={ { display: 'flex', alignItems: 'center', gap: '6px' } }>
				<input
					type="text"
					value={ term }
					onFocus={ onFocus }
					onChange={ onChange }
					onKeyDown={ onKeyDown }
					placeholder={ __( 'Order #, name or email (blank = recent)', 'woocommerce-orders-invoice-pdf' ) }
					style={ { flex: '1' } }
				/>
				{ ( searching || loading ) ? <Spinner /> : null }
			</div>
			{ orderLabel ? (
				<div style={ { fontSize: '11px', color: '#555', marginTop: '2px' } }>
					{ __( 'Order:', 'woocommerce-orders-invoice-pdf' ) } { orderLabel }
				</div>
			) : null }
			{ open && results ? (
				<ul className="woi-order-results" style={ { position: 'absolute', zIndex: 100001, left: 0, right: 0, top: '100%', margin: 0, padding: '4px 0', listStyle: 'none', background: '#fff', border: '1px solid #ccc', boxShadow: '0 2px 8px rgba(0,0,0,.15)', maxHeight: '320px', overflow: 'auto' } }>
					{ 0 === ids.length ? (
						<li style={ { padding: '8px 12px', color: '#777' } }>{ __( 'No orders found', 'woocommerce-orders-invoice-pdf' ) }</li>
					) : ids.map( ( id ) => {
						const d = results[ id ];
						return (
							<li key={ id }>
								<button
									type="button"
									className="button-link"
									onClick={ () => loadOrder( id, orderRowTitle( d ) ) }
									style={ { display: 'block', width: '100%', textAlign: 'left', padding: '6px 12px' } }
								>
									<span style={ { fontWeight: 600 } }>{ orderRowTitle( d ) }</span>
									<span style={ { display: 'block', fontSize: '11px', color: '#666' } }>
										<span dangerouslySetInnerHTML={ { __html: d.total_raw || '' } } />
										{ d.total_raw ? ' · ' : '' }
										{ orderMetaLine( d ) }
									</span>
								</button>
							</li>
						);
					} ) }
				</ul>
			) : null }
		</div>
	);
}
```

- [ ] **Step 3: Build**

Run: `npm run build`
Expected: builds cleanly. (Mounted in Task 8; verified live in Task 9.)

- [ ] **Step 4: Commit**

```bash
git add src/block-editor/OrderPicker.js src/block-editor/preview.js assets/js/block-editor/
git commit -m "feat(block-editor): GrapesJS-style order combobox with order-# Enter"
```

---

### Task 8: Assemble the editor — mount picker + canvas, strip Live HTML

**Files:**
- Modify: `src/block-editor/index.js`
- Modify: `src/block-editor/PreviewPanel.js` (PDF-only)
- Modify: `src/block-editor/preview.js` (remove now-dead Live-HTML helpers)

**Interfaces:**
- Consumes: `Canvas` + `injectCanvasStyles` (Task 5), `OrderPicker` (Task 7), `previewStore` (Task 3).
- Produces: the wired editor. `PreviewPanel` now reads `orderId` from `STORE` and shows only the PDF tab.

- [ ] **Step 1: Wire `index.js`**

In `src/block-editor/index.js`, update imports (add the store registration, picker, canvas; drop nothing else):

```js
import { createRoot, useState, useEffect } from '@wordpress/element';
import {
	BlockList,
	BlockTools,
	BlockEditorProvider,
	Inserter,
} from '@wordpress/block-editor';
import { parse, serialize, registerBlockCollection } from '@wordpress/blocks';
import { Button, Popover, SlotFillProvider } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { registerTokenBlocks } from './blocks/token';
import { registerTextBlock } from './blocks/text';
import { registerLayoutBlocks } from './blocks/layout';
import { registerColumnsBlocks, registerHeaderRowVariation } from './blocks/columns';
import { saveBlocks, setActiveSource } from './store';
import './previewStore';
import OrderPicker from './OrderPicker';
import Canvas from './canvas/Canvas';
import injectCanvasStyles from './canvas/canvasStyles';
import PreviewPanel from './PreviewPanel';
import { injectLayoutStyles, LAYOUTS } from './layout';
```

After the existing `injectLayoutStyles();` call, add:

```js
injectCanvasStyles();
```

Replace the canvas block (the `<SlotFillProvider> … </SlotFillProvider>` region) so the `BlockList`/`WritingFlow`/`ObserveTyping` is replaced by `<Canvas>` (which owns the iframe + rulers), and add `<OrderPicker />` to the toolbar row right after the source `<select>`:

```jsx
					<select value={ source } onChange={ ( e ) => onSource( e.target.value ) }>
						<option value="grapesjs">{ __( 'GrapesJS', 'woocommerce-orders-invoice-pdf' ) }</option>
						<option value="blocks">{ __( 'Block editor', 'woocommerce-orders-invoice-pdf' ) }</option>
					</select>
					<OrderPicker />
					<span aria-live="polite">{ status }</span>
```

```jsx
				<SlotFillProvider>
					<BlockEditorProvider value={ blocks } onInput={ setBlocks } onChange={ setBlocks }>
						<div className="woi-block-canvas">
							<BlockTools>
								<div style={ { padding: '8px' } }><Inserter rootClientId={ undefined } isAppender /></div>
								<Canvas previewCss={ ( window.woiBlocks && window.woiBlocks.previewCss ) || '' } />
							</BlockTools>
						</div>
						<Popover.Slot />
					</BlockEditorProvider>
				</SlotFillProvider>
```

Note: remove the now-unused `WritingFlow` and `ObserveTyping` imports from `index.js` (they live inside `A4Canvas` now). Keep `BlockList` imported only if still referenced — it is not, so remove it too. Final block-editor imports from `@wordpress/block-editor` in `index.js`: `BlockTools, BlockEditorProvider, Inserter`.

- [ ] **Step 2: Make `PreviewPanel.js` PDF-only**

Replace the entire contents of `src/block-editor/PreviewPanel.js`:

```js
import { useRef, useEffect, useCallback } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { STORE } from './previewStore';
import { renderPdfPreview } from './pdfPreview';

// PDF-only preview panel. The A4 block canvas is now the live HTML view, so the
// former Live HTML tab and its own order search are gone; the order is chosen by
// the toolbar OrderPicker and read from the woi/preview store.
export default function PreviewPanel( { blocks, source, hidden } ) {
	const stageRef = useRef( null );
	const orderId = useSelect( ( select ) => select( STORE ).getOrderId(), [] );

	const renderPdf = useCallback( () => {
		renderPdfPreview( { stageEl: stageRef.current, blocks, orderId, onStatus: () => {} } );
	}, [ blocks, orderId ] );

	useEffect( () => { /* no auto-render; user clicks Render PDF */ }, [] );

	return (
		<div className="woi-block-preview" hidden={ hidden }>
			<div className="woi-block-preview-bar" style={ { display: 'flex', gap: '8px', alignItems: 'center', padding: '8px', flexWrap: 'wrap' } }>
				<strong>{ __( 'PDF preview', 'woocommerce-orders-invoice-pdf' ) }</strong>
				<button type="button" className="button button-primary" onClick={ renderPdf }>{ __( 'Render PDF', 'woocommerce-orders-invoice-pdf' ) }</button>
				{ 'blocks' !== source ? (
					<span style={ { color: '#b32d2e' } }>{ __( 'PDF reflects the active source. Set "PDF source" to "Block editor" above to preview the block design.', 'woocommerce-orders-invoice-pdf' ) }</span>
				) : null }
			</div>
			<div className="woi-a4-scroll" style={ { flex: '1', overflow: 'auto', background: '#525659', padding: '16px', display: 'flex', flexDirection: 'column', alignItems: 'center', gap: '16px' } }>
				<div className="woi-a4-stage" ref={ stageRef } style={ { width: 'min(100%, 820px)', display: 'flex', flexDirection: 'column', alignItems: 'stretch', gap: '16px' } } />
			</div>
		</div>
	);
}
```

- [ ] **Step 3: Remove the now-dead Live-HTML helpers from `preview.js`**

In `src/block-editor/preview.js`, delete `SHIM_CSS`, `FALLBACK_CSS`, `renderedHtmlFromBlocks`, `mergeTokens`, and `wrapForPreview` (no longer imported anywhere — the canvas renders live now). Keep `fetchOrderTokens`, `fetchOrders`, `orderRowTitle`, and `orderMetaLine`.

Verify nothing else imports the deleted symbols:

Run: `grep -rn "renderedHtmlFromBlocks\|mergeTokens\|wrapForPreview" src/block-editor`
Expected: no matches.

- [ ] **Step 4: Build**

Run: `npm run build`
Expected: builds cleanly, no unresolved imports.

- [ ] **Step 5: Run the full unit suite**

Run: `npm run test:unit`
Expected: PASS — tokenMerge, previewState, rulers, orderInput suites all green.

- [ ] **Step 6: Commit**

```bash
git add src/block-editor/index.js src/block-editor/PreviewPanel.js src/block-editor/preview.js assets/js/block-editor/
git commit -m "feat(block-editor): live A4 canvas + toolbar order picker; drop Live HTML tab"
```

---

### Task 9: Version bump, full build, and live acceptance

**Files:**
- Modify: the main plugin file (`Version:` header + `public string $version`)

**Interfaces:**
- Consumes: everything above.
- Produces: a cache-busted, deployable build verified on the live site.

- [ ] **Step 1: Check the current version (do not assume)**

Run: `grep -nE "Version:|public string \$version" woocommerce-orders-invoice-pdf.php`
Expected: shows the current released version. Confirm against the `version-coordination` memory before choosing the next number.

- [ ] **Step 2: Bump both version strings in lockstep**

Set BOTH the `Version:` header and `public string $version` to the next version (e.g. if current is `1.5.17`, use `1.5.18`). They MUST match — `WOI_PDF_VERSION` is the JS/CSS cache-bust key.

- [ ] **Step 3: Full build**

Run: `npm run build`
Expected: clean build; `assets/js/block-editor/index.js` + `index.asset.php` regenerated with the new version.

- [ ] **Step 4: Commit**

```bash
git add woocommerce-orders-invoice-pdf.php assets/js/block-editor/
git commit -m "chore: bump version for live A4 block canvas (vX.Y.Z)"
```

- [ ] **Step 5: Live acceptance (manual, on b2b.milanoleather.ae)**

Deploy (manual git pull per the `live-testing-harness` memory), then drive wp-admin via debug Chrome (port 9222). Verify, in the Block Editor page (WooCommerce → Block Editor):

1. Canvas shows an A4 (210mm) white page on a gray stage with **mm rulers** on top and left and a corner gutter.
2. With "PDF source" = Block editor and the invoice visual toggle ON, the token blocks render the **selected order's real data**: shop name/address/TRN as text, logo image, billing address, **line-items table**, **totals table** — all inside the A4 page, styled like the PDF.
3. The **order combobox** in the toolbar: focus shows recent orders; typing filters (debounced) with metadata rows (`name · amount · N items / M units · date · payment`) and a spinner; selecting updates the canvas live.
4. Type an **order number + Enter** → that order loads into the canvas directly.
5. Add enough blocks to exceed one page → a **dashed page-break guide** + "Page 2" label appears at 297mm and the left ruler shows a bold red page mark; the stage scrolls.
6. The **PDF tab** still renders the order's PDF (click Render PDF).
7. There is **no Live HTML tab**.
8. Flip "PDF source" back to GrapesJS — GrapesJS editor unaffected.

Record results in the `wp-block-editor-feature` memory (live acceptance status) and note any follow-ups.

---

## Self-Review notes (for the executor)

- **`woiBlocks.previewCss` may be empty** if `woi_pdf_visual_document_css()` returns nothing — the A4 shim still sizes the page; live acceptance step 2 will surface any missing document CSS.
- **Order #-as-id caveat:** Enter-by-number passes the number as `order_id` to `visual-preview-data`. On this shop order number == order ID, so it resolves; if a future shop customizes order numbers, this may need a number→id lookup (out of scope, note only).
- **BlockCanvas fallback:** if the installed WP lacks `BlockCanvas`, `A4Canvas` renders the in-DOM fallback; rulers/guides still work but CSS isolation is weaker. Live acceptance is on the real WP version, which has `BlockCanvas` (WP ≥ 6.3).
