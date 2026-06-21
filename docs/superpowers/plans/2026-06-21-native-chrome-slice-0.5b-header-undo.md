# Native Chrome — Sub-slice 0.5b: Header Affordances + Undo/Redo Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a native-looking header to the InterfaceSkeleton editor — block inserter, undo/redo (backed by a real history stack), list-view toggle, and full-screen — and clean up the dead `hidden` prop on `PreviewPanel`.

**Architecture:** The controlled `BlockEditorProvider` keeps no undo history, so a pure `historyReducer` over `{past, present, future}` provides it: `onChange` (persistent) pushes history, `onInput` (transient) replaces present. `Editor` switches from `useState(blocks)` to `useReducer(historyReducer, …)`. Header tools are `@wordpress/components` `Button`s with `@wordpress/icons`; list view uses `ListView` in the skeleton's `secondarySidebar`; full-screen toggles a class on the existing `.woi-block-interface-wrap`.

**Tech Stack:** `@wordpress/element` (`useReducer`), `@wordpress/block-editor` (`ListView`, `Inserter`), `@wordpress/components` (`Button`), `@wordpress/icons`, `@wordpress/interface` (`InterfaceSkeleton.secondarySidebar`), jest via `@wordpress/scripts`, debug-Chrome live harness.

## Global Constraints

- Spec: `docs/superpowers/specs/2026-06-21-block-editor-native-chrome-design.md` (sub-slice 0.5b).
- CHROME-only: no block `save()` change, no kses/allowlist change; GrapesJS, render path, active-source resolver, `woi/preview` store, order AJAX, PDF preview untouched.
- `ListView` and `Inserter` come from `@wordpress/block-editor` (externalized — no new bundling). `@wordpress/icons` is already a dependency (0.5a) and bundles; importing more named icons grows the bundle slightly.
- Build with `npm run build`; `output.clean:false` stays; sibling assets intact. **The worktree needs a REAL `node_modules` (run `npm install`), NOT a junction** — bundling `@wordpress/interface` through a junctioned `node_modules` breaks webpack (symlink-walk). The controller provisions this before dispatch.
- Version bump BOTH lines in `woocommerce-orders-invoice-pdf.php` (header ~line 6 + `public string $version` ~line 24) to **1.5.30** (origin/master is at 1.5.29).
- Pure helpers import ZERO `@wordpress/*` (jest can't resolve externalized packages) — `history.js` must stay pure.
- Run full `npm run test:unit` before each commit.
- Work in a worktree; FF push to master; read true version from origin/master before bumping.

---

### Task 1: Pure history reducer with tests (TDD)

**Files:**
- Create: `src/block-editor/history.js`
- Test: `src/block-editor/history.test.js`

**Interfaces:**
- Produces:
  - `initHistory(blocks) → { past: [], present: blocks, future: [] }`
  - `historyReducer(state, action) → state` — actions `{type:'RESET',blocks}`, `{type:'CHANGE',blocks}`, `{type:'INPUT',blocks}`, `{type:'UNDO'}`, `{type:'REDO'}`
  - `canUndo(state) → boolean`, `canRedo(state) → boolean`
- Consumed by Task 2's `Editor` via `useReducer`.

- [ ] **Step 1: Write the failing tests**

Create `src/block-editor/history.test.js`:

```js
import { initHistory, historyReducer, canUndo, canRedo } from './history';

const A = [ { name: 'a' } ];
const B = [ { name: 'b' } ];
const C = [ { name: 'c' } ];

describe( 'history reducer', () => {
	test( 'initHistory seeds present with empty past/future', () => {
		expect( initHistory( A ) ).toEqual( { past: [], present: A, future: [] } );
	} );

	test( 'CHANGE pushes the previous present onto past and clears future', () => {
		const s1 = initHistory( A );
		const s2 = historyReducer( s1, { type: 'CHANGE', blocks: B } );
		expect( s2 ).toEqual( { past: [ A ], present: B, future: [] } );
	} );

	test( 'CHANGE with the same present is a no-op (no duplicate history entry)', () => {
		const s1 = initHistory( A );
		const s2 = historyReducer( s1, { type: 'CHANGE', blocks: A } );
		expect( s2 ).toBe( s1 );
	} );

	test( 'INPUT replaces present without adding a history entry', () => {
		const s1 = initHistory( A );
		const s2 = historyReducer( s1, { type: 'INPUT', blocks: B } );
		expect( s2 ).toEqual( { past: [], present: B, future: [] } );
	} );

	test( 'UNDO restores the previous present and stashes the current onto future', () => {
		const s = historyReducer( initHistory( A ), { type: 'CHANGE', blocks: B } );
		const u = historyReducer( s, { type: 'UNDO' } );
		expect( u ).toEqual( { past: [], present: A, future: [ B ] } );
	} );

	test( 'REDO re-applies the next future entry', () => {
		let s = historyReducer( initHistory( A ), { type: 'CHANGE', blocks: B } );
		s = historyReducer( s, { type: 'UNDO' } );
		const r = historyReducer( s, { type: 'REDO' } );
		expect( r ).toEqual( { past: [ A ], present: B, future: [] } );
	} );

	test( 'a fresh CHANGE clears the redo future', () => {
		let s = historyReducer( initHistory( A ), { type: 'CHANGE', blocks: B } );
		s = historyReducer( s, { type: 'UNDO' } );      // future = [B]
		s = historyReducer( s, { type: 'CHANGE', blocks: C } );
		expect( s.future ).toEqual( [] );
		expect( s.present ).toBe( C );
	} );

	test( 'UNDO/REDO at the ends are no-ops', () => {
		const s = initHistory( A );
		expect( historyReducer( s, { type: 'UNDO' } ) ).toBe( s );
		expect( historyReducer( s, { type: 'REDO' } ) ).toBe( s );
	} );

	test( 'RESET clears history to a new present', () => {
		const s = historyReducer( initHistory( A ), { type: 'CHANGE', blocks: B } );
		expect( historyReducer( s, { type: 'RESET', blocks: C } ) ).toEqual( { past: [], present: C, future: [] } );
	} );

	test( 'canUndo/canRedo reflect stack contents', () => {
		const s0 = initHistory( A );
		expect( canUndo( s0 ) ).toBe( false );
		expect( canRedo( s0 ) ).toBe( false );
		const s1 = historyReducer( s0, { type: 'CHANGE', blocks: B } );
		expect( canUndo( s1 ) ).toBe( true );
		const s2 = historyReducer( s1, { type: 'UNDO' } );
		expect( canRedo( s2 ) ).toBe( true );
	} );
} );
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run test:unit -- history`
Expected: FAIL — `Cannot find module './history'`.

- [ ] **Step 3: Implement `src/block-editor/history.js`**

```js
/**
 * Pure undo/redo history over the editor's block array. The controlled
 * BlockEditorProvider keeps no history of its own, so the Editor drives this
 * reducer: persistent edits (onChange) push a history entry; transient edits
 * (onInput, e.g. mid-typing) only replace the present so typing isn't recorded
 * keystroke-by-keystroke. Pure — imports zero @wordpress/* so jest can run it.
 */
export function initHistory( blocks ) {
	return { past: [], present: blocks, future: [] };
}

export function historyReducer( state, action ) {
	switch ( action.type ) {
		case 'RESET':
			return { past: [], present: action.blocks, future: [] };
		case 'CHANGE':
			if ( action.blocks === state.present ) {
				return state;
			}
			return { past: [ ...state.past, state.present ], present: action.blocks, future: [] };
		case 'INPUT':
			if ( action.blocks === state.present ) {
				return state;
			}
			return { past: state.past, present: action.blocks, future: state.future };
		case 'UNDO':
			if ( ! state.past.length ) {
				return state;
			}
			return {
				past: state.past.slice( 0, -1 ),
				present: state.past[ state.past.length - 1 ],
				future: [ state.present, ...state.future ],
			};
		case 'REDO':
			if ( ! state.future.length ) {
				return state;
			}
			return {
				past: [ ...state.past, state.present ],
				present: state.future[ 0 ],
				future: state.future.slice( 1 ),
			};
		default:
			return state;
	}
}

export const canUndo = ( state ) => state.past.length > 0;
export const canRedo = ( state ) => state.future.length > 0;
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm run test:unit -- history`
Expected: all `history` tests PASS.

- [ ] **Step 5: Run the full suite**

Run: `npm run test:unit`
Expected: prior 17 + the new history tests all pass, output pristine.

- [ ] **Step 6: Commit**

```bash
git add src/block-editor/history.js src/block-editor/history.test.js
git commit -m "feat(block-editor): pure undo/redo history reducer for the controlled BlockEditorProvider"
```

---

### Task 2: Wire history + native header affordances into the Editor

**Files:**
- Modify (rewrite `Editor` + imports): `src/block-editor/index.js`
- Modify: `src/block-editor/canvas/canvasStyles.js` (append full-screen + list-view rules)
- Modify: `src/block-editor/PreviewPanel.js` (drop the dead `hidden` prop)
- Version: `woocommerce-orders-invoice-pdf.php` (two lines → 1.5.30)
- Build artifact (committed): `assets/js/block-editor/*`

**Interfaces:**
- Consumes: `historyReducer`/`initHistory`/`canUndo`/`canRedo` (Task 1); `ListView`, `Inserter` (`@wordpress/block-editor`); icons from `@wordpress/icons`; `InterfaceSkeleton` `secondarySidebar` prop.

- [ ] **Step 1: Replace `src/block-editor/index.js` entirely with this**

```jsx
import { createRoot, useReducer, useState, useEffect } from '@wordpress/element';
import {
	BlockTools,
	BlockEditorProvider,
	BlockInspector,
	Inserter,
	ListView,
} from '@wordpress/block-editor';
import { parse, serialize, registerBlockCollection } from '@wordpress/blocks';
import { Button, Popover, SlotFillProvider, TabPanel } from '@wordpress/components';
import { InterfaceSkeleton } from '@wordpress/interface';
import {
	cog,
	plus,
	undo as undoIcon,
	redo as redoIcon,
	listView as listViewIcon,
	fullscreen as fullscreenIcon,
} from '@wordpress/icons';
import { __ } from '@wordpress/i18n';
import { historyReducer, initHistory, canUndo, canRedo } from './history';
import { registerTokenBlocks } from './blocks/token';
import { registerTextBlock } from './blocks/text';
import { registerLayoutBlocks } from './blocks/layout';
import {
	registerColumnsBlocks,
	registerHeaderRowVariation,
} from './blocks/columns';
import { registerTableBlock } from './blocks/table';
import { saveBlocks, setActiveSource } from './store';
import './previewStore';
import OrderPicker from './OrderPicker';
import Canvas from './canvas/Canvas';
import injectCanvasStyles from './canvas/canvasStyles';
import PreviewPanel from './PreviewPanel';

// Register our blocks; group them under an "Invoice" heading in the inserter.
registerBlockCollection( 'woi', {
	title: __( 'Invoice', 'woocommerce-orders-invoice-pdf' ),
} );
registerTextBlock();
registerTokenBlocks();
registerLayoutBlocks();
registerColumnsBlocks();
registerHeaderRowVariation();
registerTableBlock();
injectCanvasStyles();

function Editor( { initial, activeSource } ) {
	const [ history, dispatch ] = useReducer( historyReducer, initial, initHistory );
	const blocks = history.present;
	const [ status, setStatus ] = useState( '' );
	const [ source, setSource ] = useState( activeSource );
	const [ isSidebarOpen, setIsSidebarOpen ] = useState( true );
	const [ isListViewOpen, setIsListViewOpen ] = useState( false );
	const [ isFullscreen, setIsFullscreen ] = useState( false );

	// Hide the admin background scroll while the editor is full screen.
	useEffect( () => {
		document.body.classList.toggle( 'woi-block-fullscreen', isFullscreen );
		return () => document.body.classList.remove( 'woi-block-fullscreen' );
	}, [ isFullscreen ] );

	async function onSave() {
		setStatus( __( 'Saving…', 'woocommerce-orders-invoice-pdf' ) );
		try {
			await saveBlocks( serialize( blocks ) );
			setStatus( __( 'Saved.', 'woocommerce-orders-invoice-pdf' ) );
		} catch ( e ) {
			setStatus( __( 'Save failed.', 'woocommerce-orders-invoice-pdf' ) );
		}
	}

	async function onSource( next ) {
		setSource( next );
		try {
			const r = await setActiveSource( next );
			setSource( r.source );
		} catch ( e ) {
			/* keep prior on failure */
		}
	}

	const header = (
		<div
			className="woi-block-header"
			style={ { display: 'flex', gap: '4px', alignItems: 'center', width: '100%' } }
		>
			<Inserter
				rootClientId={ undefined }
				isAppender={ false }
				renderToggle={ ( { onToggle, isOpen } ) => (
					<Button
						icon={ plus }
						label={ __( 'Add block', 'woocommerce-orders-invoice-pdf' ) }
						onClick={ onToggle }
						aria-expanded={ isOpen }
					/>
				) }
			/>
			<Button
				icon={ undoIcon }
				label={ __( 'Undo', 'woocommerce-orders-invoice-pdf' ) }
				onClick={ () => dispatch( { type: 'UNDO' } ) }
				disabled={ ! canUndo( history ) }
			/>
			<Button
				icon={ redoIcon }
				label={ __( 'Redo', 'woocommerce-orders-invoice-pdf' ) }
				onClick={ () => dispatch( { type: 'REDO' } ) }
				disabled={ ! canRedo( history ) }
			/>
			<Button
				icon={ listViewIcon }
				label={ __( 'List view', 'woocommerce-orders-invoice-pdf' ) }
				isPressed={ isListViewOpen }
				onClick={ () => setIsListViewOpen( ( o ) => ! o ) }
			/>
			<Button variant="primary" onClick={ onSave } style={ { marginLeft: '8px' } }>
				{ __( 'Save', 'woocommerce-orders-invoice-pdf' ) }
			</Button>
			<span aria-live="polite">{ status }</span>
			<div style={ { marginLeft: 'auto', display: 'flex', gap: '4px', alignItems: 'center' } }>
				<OrderPicker />
				<Button
					icon={ fullscreenIcon }
					label={ __( 'Toggle full screen', 'woocommerce-orders-invoice-pdf' ) }
					isPressed={ isFullscreen }
					onClick={ () => setIsFullscreen( ( f ) => ! f ) }
				/>
				<Button
					icon={ cog }
					label={ __( 'Settings', 'woocommerce-orders-invoice-pdf' ) }
					isPressed={ isSidebarOpen }
					onClick={ () => setIsSidebarOpen( ( o ) => ! o ) }
				/>
			</div>
		</div>
	);

	const sidebar = (
		<TabPanel
			className="woi-block-sidebar-tabs"
			tabs={ [
				{ name: 'document', title: __( 'Document', 'woocommerce-orders-invoice-pdf' ) },
				{ name: 'block', title: __( 'Block', 'woocommerce-orders-invoice-pdf' ) },
			] }
			initialTabName="block"
		>
			{ ( tab ) =>
				'block' === tab.name ? (
					<BlockInspector />
				) : (
					<div className="woi-block-document-panel" style={ { padding: '16px' } }>
						<label htmlFor="woi-pdf-source" style={ { display: 'block', marginBottom: '4px' } }>
							{ __( 'PDF source:', 'woocommerce-orders-invoice-pdf' ) }
						</label>
						<select
							id="woi-pdf-source"
							value={ source }
							onChange={ ( e ) => onSource( e.target.value ) }
						>
							<option value="grapesjs">{ __( 'GrapesJS', 'woocommerce-orders-invoice-pdf' ) }</option>
							<option value="blocks">{ __( 'Block editor', 'woocommerce-orders-invoice-pdf' ) }</option>
						</select>
						<p style={ { marginTop: '12px', color: '#757575' } }>
							{ __( 'Set the source to "Block editor" to render the PDF from this design.', 'woocommerce-orders-invoice-pdf' ) }
						</p>
					</div>
				)
			}
		</TabPanel>
	);

	const content = (
		<BlockTools>
			<div style={ { padding: '8px' } }>
				<Inserter rootClientId={ undefined } isAppender />
			</div>
			<Canvas
				previewCss={ ( window.woiBlocks && window.woiBlocks.previewCss ) || '' }
			/>
		</BlockTools>
	);

	const secondarySidebar = isListViewOpen ? (
		<div className="woi-block-listview">
			<ListView />
		</div>
	) : undefined;

	return (
		<SlotFillProvider>
			<BlockEditorProvider
				value={ blocks }
				onInput={ ( next ) => dispatch( { type: 'INPUT', blocks: next } ) }
				onChange={ ( next ) => dispatch( { type: 'CHANGE', blocks: next } ) }
			>
				<div className={ 'woi-block-interface-wrap' + ( isFullscreen ? ' is-fullscreen' : '' ) }>
					<InterfaceSkeleton
						className="woi-block-interface"
						header={ header }
						content={ content }
						sidebar={ isSidebarOpen ? sidebar : undefined }
						secondarySidebar={ secondarySidebar }
						labels={ {
							header: __( 'Editor top bar', 'woocommerce-orders-invoice-pdf' ),
							body: __( 'Editor content', 'woocommerce-orders-invoice-pdf' ),
							sidebar: __( 'Editor settings', 'woocommerce-orders-invoice-pdf' ),
							secondarySidebar: __( 'Block list view', 'woocommerce-orders-invoice-pdf' ),
						} }
					/>
				</div>
				<Popover.Slot />
			</BlockEditorProvider>
			<PreviewPanel blocks={ blocks } source={ source } />
		</SlotFillProvider>
	);
}

const mount = document.getElementById( 'woi-block-editor-root' );
if ( mount && window.woiBlocks ) {
	const initial = window.woiBlocks.storedMarkup
		? parse( window.woiBlocks.storedMarkup )
		: [];
	createRoot( mount ).render(
		<Editor
			initial={ initial }
			activeSource={ window.woiBlocks.activeSource || 'grapesjs' }
		/>
	);
}
```

- [ ] **Step 2: Append full-screen + list-view CSS to `canvasStyles.js`**

In `src/block-editor/canvas/canvasStyles.js`, change the `.woi-block-preview{…}` rule's terminating `;` to `+` and append these rules (the last one keeps the `;`):

```js
	// full-screen: lift the skeleton host out of admin flow to cover the viewport
	'.woi-block-interface-wrap.is-fullscreen{position:fixed;inset:0;height:auto;min-height:0;z-index:100000;border:0;background:#fff}' +
	'body.woi-block-fullscreen{overflow:hidden}' +
	// secondary sidebar (list view) panel
	'.woi-block-interface .interface-interface-skeleton__secondary-sidebar{flex:0 0 281px;width:281px;min-width:281px;overflow:auto;border-right:1px solid #e0e0e0;background:#fff}' +
	'.woi-block-listview{padding:8px}';
```

- [ ] **Step 3: Remove the dead `hidden` prop from `PreviewPanel.js`**

In `src/block-editor/PreviewPanel.js`, change the signature and the wrapper div (the `hidden` prop is never passed since 0.5a, so React drops it — remove the dead code):

```jsx
export default function PreviewPanel( { blocks, source } ) {
```

and

```jsx
		<div className="woi-block-preview">
```

(Leave the rest of the component unchanged.)

- [ ] **Step 4: Bump version**

Set BOTH version lines in `woocommerce-orders-invoice-pdf.php` to `1.5.30`.

- [ ] **Step 5: Build**

Run: `npm run build`
Expected: success. If an icon import errors (`export 'fullscreen' … not found`), check the exact export name in `@wordpress/icons` and fix the import. Bundle grows slightly (more icons). Sibling assets intact.

- [ ] **Step 6: Run unit tests**

Run: `npm run test:unit`
Expected: all pass (17 + history), pristine.

- [ ] **Step 7: Commit**

```bash
git add src/block-editor/index.js src/block-editor/canvas/canvasStyles.js src/block-editor/PreviewPanel.js assets/js/block-editor woocommerce-orders-invoice-pdf.php
git commit -m "feat(block-editor): native header (inserter, undo/redo, list view, full screen); wire undo history; drop dead PreviewPanel hidden prop"
```

- [ ] **Step 8: Live acceptance (controller/user, post-deploy)**

After deploy, on the Block Invoice Template page:
1. Header shows: block inserter (+), undo, redo, list-view toggle, Save, then (right) OrderPicker, full-screen, settings gear — all native-styled.
2. The **+** opens the block library; inserting a block adds it to the canvas.
3. Make an edit → **Undo** reverts it and **Redo** re-applies; both buttons disable at the ends of history; typing in a block doesn't create one history entry per keystroke.
4. **List view** toggles a left panel showing the block tree; clicking a row selects that block.
5. **Full screen** expands the editor to cover the viewport; toggling off restores it.
6. Save + Render PDF still work; the right sidebar (Document|Block, Appearance) is unchanged; no console errors.

---

## Self-Review

**Spec coverage (0.5b):** history reducer + tests (Task 1); useReducer wiring + onChange/onInput mapping (Task 2 Step 1); inserter toggle + list view + undo/redo + full screen header (Task 2 Step 1 + CSS Step 2); secondarySidebar list view (Task 2 Step 1 + CSS); PreviewPanel dead-prop cleanup (Task 2 Step 3). Covered.

**Placeholder scan:** No TBD/TODO; full file + exact CSS + test code provided. Clean.

**Type consistency:** `historyReducer`/`initHistory`/`canUndo`/`canRedo` signatures match between Task 1 (definition + tests) and Task 2 (`useReducer(historyReducer, initial, initHistory)`, `dispatch({type:'CHANGE'|'INPUT'|'UNDO'|'REDO', blocks?})`, `canUndo(history)`/`canRedo(history)`). `InterfaceSkeleton` `secondarySidebar` + `labels.secondarySidebar` match its API. Icon imports aliased to avoid identifier clashes (`undo as undoIcon`, etc.).

**Icon-name risk (flagged):** `plus`, `undo`, `redo`, `listView`, `fullscreen`, `cog` are expected `@wordpress/icons` exports; Task 2 Step 5 says to verify at build time and correct any export-name mismatch. Low risk.

**Testing note:** Task 1 is fully TDD'd (pure reducer). Task 2 is layout/slot wiring (jest can't render externalized WP components) → build + live acceptance; the reducer it consumes is already covered by Task 1.
