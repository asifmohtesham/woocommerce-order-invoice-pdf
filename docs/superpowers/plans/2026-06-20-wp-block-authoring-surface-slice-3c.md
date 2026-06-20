# WP Block Authoring Surface — Slice 3 Part C (Layout Modes) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the block editor three selectable layout modes — **Full screen**, **Split below** (stack), and **Overlay** — for the editor + preview arrangement, matching the GrapesJS editor's layout switcher. Completes preview parity.

**Architecture:** Frontend-only. A small injected `<style>` carries `.woi-block-shell[data-layout=full|stack|overlay]` rules (mirroring the GrapesJS `editor.css`); the React shell sets `data-layout` from a `layout` state, toggles `body.woi-block-fullscreen` for full mode, and persists the choice to `localStorage`. The structural inline styles currently on the shell / main / preview containers move into the injected CSS so the `[data-layout]` overrides win without `!important`. Overlay mode floats the preview as a fixed panel with a show/hide toggle. No PHP, no backend, no new endpoints.

**Tech Stack:** `@wordpress/scripts` 30, `@wordpress/element` (React: `useState`, `useEffect`), `@wordpress/i18n`.

**Scope note:** This is **Slice 3 Part C** — layout modes only. With it, Slice 3 (preview parity: Live HTML + A4 PDF.js + order picker + layout modes) is complete. Builds on 3A/3B (preview panel, already on `origin/master`).

## Global Constraints

- **Invoice-only**; render path / REST / build config untouched. GrapesJS (`assets/visual-editor/*`) is NOT modified.
- **Mirror the GrapesJS modes** (`assets/visual-editor/editor.css`): `full` = `position:fixed;inset:0;z-index:100000` over WP chrome with `body` `overflow:hidden`; `stack` = editor on top, preview below (column); `overlay` = preview as a fixed floating panel (`top:var(--wp-admin--admin-bar--height,32px);right:0;bottom:0;width:40%;max-width:640px;z-index:99980`). Default `full`; persist to `localStorage['woiBlockEditorLayout']`.
- **No `!important` fights:** remove the structural inline styles from `.woi-block-shell`, `.woi-block-main`, `.woi-block-preview` and put their base rules in the injected CSS; keep only cosmetic inline styles elsewhere. (The preview container must NOT carry an inline `display`, so the CSS `[hidden]`/mode rules govern it — see the Slice 3B `hidden`-vs-inline-`display` lesson.)
- **Version bump is a shared, collision-prone resource.** Before bumping, `git fetch origin`, read the TRUE `origin/master` value, take the next patch above it; set BOTH line 6 and line 24. (See `version-coordination` memory.)
- **Run PHPUnit as:** `php -d display_errors=1 -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit`. NEVER `vendor/bin/phpunit`.
- **Working tree is the git worktree** at `.claude/worktrees/wp-block-slice-3c` on branch `worktree-wp-block-slice-3c`, based on `origin/master`. Integrate by **fast-forward push to `origin/master`** — never check out `master` in the shared main checkout.

---

## File Structure

**Create:**
- `src/block-editor/layout.js` — `LAYOUT_CSS` string, `injectLayoutStyles()`, `LAYOUTS` list. No JSX.

**Modify:**
- `src/block-editor/index.js` — inject the styles; add `layout` + `overlayOpen` state, the segmented switcher, `data-layout` on the shell, the `body.woi-block-fullscreen` effect, localStorage persistence, the overlay show/hide toggle; remove the structural inline styles from the shell/main containers; pass `hidden` to the preview.
- `src/block-editor/PreviewPanel.js` — accept a `hidden` prop on the root container; remove the container's structural inline style (now in CSS).
- `woocommerce-orders-invoice-pdf.php` — version bump.

No new automated tests: JS has no harness; the PHP suite must stay green (no PHP changed). Verified by build + live acceptance.

---

## Task 1: Layout module + editor wiring + preview container

**Files:**
- Create: `src/block-editor/layout.js`
- Modify: `src/block-editor/index.js`, `src/block-editor/PreviewPanel.js`

**Interfaces:**
- Produces: `injectLayoutStyles()`, `LAYOUTS` (consumed by `index.js`); the shell gains `data-layout`; PreviewPanel gains a `hidden` prop.

- [ ] **Step 1: Create the layout module (no JSX)**

Create `src/block-editor/layout.js`:

```js
// Injected stylesheet for the block-editor layout modes. Mirrors the GrapesJS
// editor.css [data-layout] rules. These also carry the base structural styles
// for the shell/main/preview containers (moved off inline styles so the
// [data-layout] overrides win without !important).
export const LAYOUT_CSS =
	'.woi-block-shell{display:flex;gap:0;align-items:stretch;min-height:70vh}' +
	'.woi-block-main{flex:1.3;min-width:0;padding-right:8px}' +
	'.woi-block-preview{flex:1;min-width:360px;border-left:1px solid #ddd;display:flex;flex-direction:column}' +
	'.woi-block-preview[hidden]{display:none}' +
	'body.woi-block-fullscreen{overflow:hidden}' +
	'.woi-block-shell[data-layout="full"]{position:fixed;inset:0;z-index:100000;background:#fff;margin:0;padding:8px;min-height:0}' +
	'.woi-block-shell[data-layout="stack"]{flex-direction:column}' +
	'.woi-block-shell[data-layout="stack"] .woi-block-main{padding-right:0}' +
	'.woi-block-shell[data-layout="stack"] .woi-block-preview{flex:0 0 auto;min-width:0;border-left:0;border-top:1px solid #ddd;min-height:50vh}' +
	'.woi-block-shell[data-layout="overlay"] .woi-block-preview{position:fixed;top:var(--wp-admin--admin-bar--height,32px);right:0;bottom:0;width:40%;max-width:640px;z-index:99980;border-left:1px solid #c3c4c7;box-shadow:-8px 0 24px rgba(0,0,0,.18)}';

export function injectLayoutStyles() {
	if ( document.getElementById( 'woi-block-layout-css' ) ) { return; }
	const el = document.createElement( 'style' );
	el.id = 'woi-block-layout-css';
	el.textContent = LAYOUT_CSS;
	document.head.appendChild( el );
}

export const LAYOUTS = [
	{ id: 'full', label: 'Full screen' },
	{ id: 'stack', label: 'Split below' },
	{ id: 'overlay', label: 'Overlay' },
];
```

- [ ] **Step 2: Syntax-check the module**

Run: `node --check src/block-editor/layout.js 2>&1 || node --input-type=module --check < src/block-editor/layout.js && echo "valid ESM"`
Expected: valid.

- [ ] **Step 3: Replace `src/block-editor/index.js` with the layout-aware version**

Overwrite `src/block-editor/index.js` with this complete file (adds `useEffect` to the element import, the layout module import + injection, the `layout`/`overlayOpen` state, the switcher, `data-layout`, the body-fullscreen effect, persistence, the overlay toggle; removes the structural inline styles from the shell/main containers; passes `hidden` to PreviewPanel):

```js
import { createRoot, useState, useEffect } from '@wordpress/element';
import {
	BlockList,
	BlockTools,
	BlockEditorProvider,
	WritingFlow,
	ObserveTyping,
	Inserter,
} from '@wordpress/block-editor';
import { parse, serialize, registerBlockCollection } from '@wordpress/blocks';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { registerTokenBlocks } from './blocks/token';
import { registerTextBlock } from './blocks/text';
import { registerLayoutBlocks } from './blocks/layout';
import { registerColumnsBlocks, registerHeaderRowVariation } from './blocks/columns';
import { saveBlocks, setActiveSource } from './store';
import PreviewPanel from './PreviewPanel';
import { injectLayoutStyles, LAYOUTS } from './layout';

// Register our blocks; group them under an "Invoice" heading in the inserter.
registerBlockCollection( 'woi', { title: __( 'Invoice', 'woocommerce-orders-invoice-pdf' ) } );
registerTextBlock();
registerTokenBlocks();
registerLayoutBlocks();
registerColumnsBlocks();
registerHeaderRowVariation();
injectLayoutStyles();

function readLayout() {
	try { return window.localStorage.getItem( 'woiBlockEditorLayout' ) || 'full'; } catch ( e ) { return 'full'; }
}

function Editor( { initial, activeSource } ) {
	const [ blocks, setBlocks ] = useState( initial );
	const [ status, setStatus ] = useState( '' );
	const [ source, setSource ] = useState( activeSource );
	const [ layout, setLayout ] = useState( readLayout );
	const [ overlayOpen, setOverlayOpen ] = useState( false );

	// Toggle the body fullscreen class (hides WP chrome scroll) for full mode.
	useEffect( () => {
		document.body.classList.toggle( 'woi-block-fullscreen', 'full' === layout );
		return () => document.body.classList.remove( 'woi-block-fullscreen' );
	}, [ layout ] );

	function applyLayout( mode ) {
		setLayout( mode );
		try { window.localStorage.setItem( 'woiBlockEditorLayout', mode ); } catch ( e ) {}
	}

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
		} catch ( e ) { /* keep prior on failure */ }
	}

	const previewHidden = 'overlay' === layout && ! overlayOpen;

	return (
		<div className="woi-block-shell" data-layout={ layout }>
			<div className="woi-block-main">
				<div className="woi-block-toolbar" style={ { display: 'flex', gap: '8px', alignItems: 'center', marginBottom: '8px', flexWrap: 'wrap' } }>
					<Button variant="primary" onClick={ onSave }>{ __( 'Save', 'woocommerce-orders-invoice-pdf' ) }</Button>
					<label>{ __( 'PDF source:', 'woocommerce-orders-invoice-pdf' ) }</label>
					<select value={ source } onChange={ ( e ) => onSource( e.target.value ) }>
						<option value="grapesjs">{ __( 'GrapesJS', 'woocommerce-orders-invoice-pdf' ) }</option>
						<option value="blocks">{ __( 'Block editor', 'woocommerce-orders-invoice-pdf' ) }</option>
					</select>
					<span aria-live="polite">{ status }</span>
					<span className="woi-block-layout-switch" role="group" aria-label={ __( 'Editor layout', 'woocommerce-orders-invoice-pdf' ) } style={ { marginLeft: 'auto', display: 'inline-flex', gap: '4px' } }>
						{ LAYOUTS.map( ( l ) => (
							<button
								key={ l.id }
								type="button"
								className={ 'button' + ( layout === l.id ? ' button-primary' : '' ) }
								onClick={ () => applyLayout( l.id ) }
							>{ l.label }</button>
						) ) }
						{ 'overlay' === layout ? (
							<button type="button" className="button" onClick={ () => setOverlayOpen( ( o ) => ! o ) }>
								{ overlayOpen ? __( 'Hide preview', 'woocommerce-orders-invoice-pdf' ) : __( 'Show preview', 'woocommerce-orders-invoice-pdf' ) }
							</button>
						) : null }
					</span>
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
			<PreviewPanel blocks={ blocks } source={ source } hidden={ previewHidden } />
		</div>
	);
}

const mount = document.getElementById( 'woi-block-editor-root' );
if ( mount && window.woiBlocks ) {
	const initial = window.woiBlocks.storedMarkup ? parse( window.woiBlocks.storedMarkup ) : [];
	createRoot( mount ).render( <Editor initial={ initial } activeSource={ window.woiBlocks.activeSource || 'grapesjs' } /> );
}
```

- [ ] **Step 4: Update PreviewPanel to accept `hidden` and drop the container inline style**

In `src/block-editor/PreviewPanel.js`:

(a) change the function signature to accept `hidden`:

```js
export default function PreviewPanel( { blocks, source, hidden } ) {
```

(b) change the root container line (currently `<div className="woi-block-preview" style={ { flex: '1', minWidth: '360px', borderLeft: '1px solid #ddd', display: 'flex', flexDirection: 'column' } }>`) to use the class + the `hidden` prop, with NO inline structural style (the base styles now live in the injected CSS):

```js
		<div className="woi-block-preview" hidden={ hidden }>
```

Leave the rest of PreviewPanel unchanged.

- [ ] **Step 5: Syntax-check what can be checked**

Run: `node --check src/block-editor/layout.js 2>&1 || node --input-type=module --check < src/block-editor/layout.js && echo "layout.js valid"`
Expected: valid. (index.js / PreviewPanel.js are JSX — validated by the build in Task 2.)

- [ ] **Step 6: Commit**

```bash
git add src/block-editor/layout.js src/block-editor/index.js src/block-editor/PreviewPanel.js
git commit -m "feat(visual): full/stack/overlay layout modes in block editor"
```

---

## Task 2: Build, verify, version bump

**Files:**
- Modify (built output): `assets/js/block-editor/index.js`, `assets/js/block-editor/index.asset.php`
- Modify: `woocommerce-orders-invoice-pdf.php` (version)

- [ ] **Step 1: Build**

Run: `npm run build`
Expected: `webpack … compiled successfully`; `assets/js/block-editor/index.js` + `index.asset.php` emitted; `assets/js/home/index.js` still present.
> If it fails on a JSX compile error, a Task-1 file is broken — report BLOCKED with the exact error + file:line. If `node_modules` is missing, `npm install` first.

- [ ] **Step 2: Sibling-asset safety check**

Run: `git status --short assets/js`
Expected: only `block-editor/*` (and maybe `home/index.*`) modified; NO deletions (` D `) of `admin.js`, `pdf_js/*`, `order-script.js`, etc.

- [ ] **Step 3: Confirm the layout modes compiled in**

Run: `grep -c "woiBlockEditorLayout\|woi-block-fullscreen\|data-layout" assets/js/block-editor/index.js`
Expected: non-zero.

- [ ] **Step 4: Read the TRUE current version from origin/master (coordination)**

Run: `git fetch origin && git show origin/master:woocommerce-orders-invoice-pdf.php | grep -m1 "Version:"`
Note the value. The new version is the next patch above it — do NOT assume.

- [ ] **Step 5: Bump the version in both places**

In `woocommerce-orders-invoice-pdf.php`, set line 6 (`* Version:`) and line 24 (`public string $version`) to the next patch above the Step-4 value (both identical).

- [ ] **Step 6: Commit**

```bash
git add assets/js/block-editor woocommerce-orders-invoice-pdf.php
git commit -m "build(visual): rebuild bundle with layout modes; bump version"
```

> Update the `version-coordination` memory's "current released version" line after this branch is pushed.

---

## Task 3: Live acceptance (manual — user, requires deploy)

**Files:** none (verification only)

- [ ] **Step 1: Deploy** (manual git pull) so the rebuilt bundle is served.

- [ ] **Step 2: Default + switcher.** In PDF Invoices → **Block Editor**, confirm a layout switcher (Full screen / Split below / Overlay) appears in the toolbar, the active mode is highlighted, and the default is **Full screen** (editor fills the screen over WP chrome).

- [ ] **Step 3: Full.** In Full screen, confirm the shell covers WP admin chrome (fixed, full viewport), the page body doesn't scroll behind it, editing + the preview panel both work, and the inserter/block toolbar popovers appear above the canvas.

- [ ] **Step 4: Stack.** Click **Split below**; confirm the editor is on top and the preview below (single column), both usable.

- [ ] **Step 5: Overlay.** Click **Overlay**; confirm the editor spans full width and the preview is hidden until **Show preview** is clicked, at which point it floats as a fixed panel on the right (with shadow); **Hide preview** dismisses it.

- [ ] **Step 6: Persistence + no regressions.** Switch to Split below, reload the page; confirm it reopens in Split below (localStorage). Confirm the Live HTML + PDF tabs still work in every mode, and the GrapesJS editor's own layout switcher is unaffected.

Expected: three working layout modes with a highlighted switcher, persisted across reloads, matching the GrapesJS editor.

> Watch-point (no automated oracle): in Full screen, confirm the `@wordpress` inserter/popover portals (rendered to document.body) still appear above the fixed shell (z-index). If a popover hides behind the canvas, a follow-up may need a popover-slot wrapper — note it but it's not expected to block.

---

## Self-Review

**Spec coverage (Slice 3 Part C scope):**
- Full / Split-below (stack) / Overlay modes mirroring GrapesJS → Task 1 (`LAYOUT_CSS` ported from `editor.css`; `data-layout` on the shell). ✓
- Segmented switcher with active highlight → Task 1 (`LAYOUTS.map` + `button-primary`). ✓
- `body` fullscreen toggle for full mode → Task 1 (`useEffect` toggling `woi-block-fullscreen`). ✓
- Persistence (default `full`) → Task 1 (`localStorage['woiBlockEditorLayout']`). ✓
- Overlay floating panel + show/hide toggle → Task 1 (overlay CSS + `overlayOpen` + toggle button). ✓
- No `!important` fights (structural inline styles moved to CSS; preview has no inline `display`) → Task 1. ✓
- Build + coordination-safe version bump → Task 2. ✓
- Live acceptance → Task 3 (user). ✓
- This completes Slice 3 (preview parity). ✓

**Placeholder scan:** None. All code complete; the version literal resolves at execution time from `origin/master` (Task 2 Step 4).

**Type/name consistency:** `injectLayoutStyles` and `LAYOUTS` are defined in `layout.js` and imported in `index.js`. `useEffect` is added to the `@wordpress/element` import. The shell `data-layout` values (`full`/`stack`/`overlay`) match the `LAYOUT_CSS` selectors and the `LAYOUTS` ids. `PreviewPanel` gains a `hidden` prop (used on its root `.woi-block-preview` div), passed from `index.js` as `previewHidden`. The CSS class names (`woi-block-shell`, `woi-block-main`, `woi-block-preview`) match the existing JSX class names. `localStorage` key `woiBlockEditorLayout` is read (`readLayout`) and written (`applyLayout`) consistently. The mode CSS mirrors `assets/visual-editor/editor.css` (full/stack/overlay). ✓
