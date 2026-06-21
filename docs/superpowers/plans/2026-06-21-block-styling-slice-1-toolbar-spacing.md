# Block Styling — Slice 1: Toolbar Alignment + Spacing/Width Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Surface text alignment in the block toolbar (discoverable on selection) and add padding / margin / width controls to the shared Appearance system, applying automatically to the text-bearing blocks (Text, Heading, all Tokens).

**Architecture:** The shared `src/block-editor/appearance.js` stays the single source of presentational attributes, inline-style output, and the Inspector panel. The pure style helpers (`APPEARANCE_ATTRS`, `appearanceStyle`, `appearanceProps`) are extracted into a new pure module `src/block-editor/appearanceStyle.js` (zero `@wordpress/*` imports) so they become jest-testable; `appearance.js` re-exports them and adds the new `AppearanceToolbar` (`BlockControls` + `AlignmentControl`). Every attribute stays default-empty so unstyled blocks serialise byte-identical (no block-validation break).

**Tech Stack:** `@wordpress/block-editor` (`BlockControls`, `AlignmentControl` — both verified stable on the live WP), `@wordpress/components` (`RangeControl`), jest via `@wordpress/scripts`, debug-Chrome live harness.

## Global Constraints

- Spec: `docs/superpowers/specs/2026-06-21-block-styling-design.md` (Slice 1).
- **Inline styles only** (never palette classes) — mPDF doesn't load the theme stylesheet. All new CSS props (`padding`, `margin`, `width`) are standard `safecss_filter_attr`-allowed on already-allowed tags → NO kses/allowlist change.
- **Every appearance attribute defaults empty/0**; `appearanceStyle` adds a property only when set; `appearanceProps` returns `{style}` only when non-empty → a block with no styling serialises identically (no deprecation needed).
- Pure helpers (`appearanceStyle.js`) import ZERO `@wordpress/*` (jest can't resolve externalized packages).
- `AlignmentControl` value is `'left'|'center'|'right'|undefined`; our `align` attr stores `''|'left'|'center'|'right'`. Map `undefined`↔`''`.
- Build with `npm run build`; `output.clean:false` stays; sibling assets intact. **Worktree needs a REAL `node_modules` (`npm install`), not a junction** (bundling `@wordpress/interface` breaks through a junction). Controller provisions before dispatch.
- Version bump BOTH lines in `woocommerce-orders-invoice-pdf.php` to **1.5.33** (origin/master is at 1.5.32).
- Run full `npm run test:unit` before each commit. Work in a worktree; FF push to master; read true version from origin/master before bumping.

---

### Task 1: Extract pure style helpers + add padding/margin/width (TDD)

**Files:**
- Create: `src/block-editor/appearanceStyle.js`
- Create: `src/block-editor/appearanceStyle.test.js`
- Modify: `src/block-editor/appearance.js` (import + re-export the pure helpers instead of defining them)

**Interfaces:**
- Produces (from `appearanceStyle.js`):
  - `APPEARANCE_ATTRS` — now includes `padding:{type:'number',default:0}`, `margin:{type:'number',default:0}`, `width:{type:'string',default:''}` in addition to the existing `align/weight/fontSize/color/bg`.
  - `appearanceStyle(a) → styleObject` — maps set attrs to CSS, including `padding`/`margin` (`+'px'`) and `width` (verbatim string).
  - `appearanceProps(attributes) → {} | {style}`.
- `appearance.js` re-exports all three so existing block imports (`from '../appearance'`) keep working unchanged.

- [ ] **Step 1: Write the failing test**

Create `src/block-editor/appearanceStyle.test.js`:

```js
import { APPEARANCE_ATTRS, appearanceStyle, appearanceProps } from './appearanceStyle';

describe( 'appearanceStyle', () => {
	test( 'empty attributes produce an empty style object', () => {
		expect( appearanceStyle( {} ) ).toEqual( {} );
	} );

	test( 'maps the original presentational attributes', () => {
		expect(
			appearanceStyle( { align: 'center', weight: 'bold', fontSize: 14, color: '#111', bg: '#eee' } )
		).toEqual( {
			textAlign: 'center',
			fontWeight: 'bold',
			fontSize: '14px',
			color: '#111',
			backgroundColor: '#eee',
		} );
	} );

	test( 'adds padding and margin in px only when non-zero', () => {
		expect( appearanceStyle( { padding: 8, margin: 12 } ) ).toEqual( { padding: '8px', margin: '12px' } );
		expect( appearanceStyle( { padding: 0, margin: 0 } ) ).toEqual( {} );
	} );

	test( 'adds width verbatim only when set', () => {
		expect( appearanceStyle( { width: '50%' } ) ).toEqual( { width: '50%' } );
		expect( appearanceStyle( { width: '' } ) ).toEqual( {} );
	} );

	test( 'fontSize 0 is treated as unset', () => {
		expect( appearanceStyle( { fontSize: 0 } ) ).toEqual( {} );
	} );
} );

describe( 'appearanceProps', () => {
	test( 'returns {} when nothing is set (byte-identical serialisation)', () => {
		expect( appearanceProps( {} ) ).toEqual( {} );
	} );

	test( 'returns { style } when something is set', () => {
		expect( appearanceProps( { padding: 4 } ) ).toEqual( { style: { padding: '4px' } } );
	} );
} );

describe( 'APPEARANCE_ATTRS', () => {
	test( 'declares the new spacing/width attributes with empty defaults', () => {
		expect( APPEARANCE_ATTRS.padding ).toEqual( { type: 'number', default: 0 } );
		expect( APPEARANCE_ATTRS.margin ).toEqual( { type: 'number', default: 0 } );
		expect( APPEARANCE_ATTRS.width ).toEqual( { type: 'string', default: '' } );
	} );
} );
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npm run test:unit -- appearanceStyle`
Expected: FAIL — `Cannot find module './appearanceStyle'`.

- [ ] **Step 3: Create `src/block-editor/appearanceStyle.js`**

```js
/**
 * Pure presentational-style helpers for the shared Appearance system. NO
 * @wordpress/* imports — kept separate from appearance.js (which imports
 * @wordpress/components for the panel) so jest can unit-test the style mapping.
 *
 * Inline-style based ON PURPOSE: mPDF does not load the theme stylesheet, so
 * WordPress's palette/preset CLASSES would render unstyled; inline styles always
 * render and are kses-safe (safecss_filter_attr allows these properties). Every
 * attribute defaults empty/0, so a block with no appearance set serialises
 * exactly as before (no block-validation break).
 */
export const APPEARANCE_ATTRS = {
	align: { type: 'string', default: '' },
	weight: { type: 'string', default: '' },
	fontSize: { type: 'number', default: 0 },
	color: { type: 'string', default: '' },
	bg: { type: 'string', default: '' },
	padding: { type: 'number', default: 0 },
	margin: { type: 'number', default: 0 },
	width: { type: 'string', default: '' },
};

// Build the inline style object from the appearance attributes (set props only).
export function appearanceStyle( a ) {
	const s = {};
	if ( a.align ) { s.textAlign = a.align; }
	if ( a.weight ) { s.fontWeight = a.weight; }
	if ( a.fontSize ) { s.fontSize = a.fontSize + 'px'; }
	if ( a.color ) { s.color = a.color; }
	if ( a.bg ) { s.backgroundColor = a.bg; }
	if ( a.padding ) { s.padding = a.padding + 'px'; }
	if ( a.margin ) { s.margin = a.margin + 'px'; }
	if ( a.width ) { s.width = a.width; }
	return s;
}

// Spread onto an element's props: adds { style } only when something is set, so
// an unstyled block produces the identical markup it did before this feature.
export function appearanceProps( attributes ) {
	const style = appearanceStyle( attributes );
	return Object.keys( style ).length ? { style } : {};
}
```

- [ ] **Step 4: Update `src/block-editor/appearance.js` to re-export the pure helpers**

Replace the top of `appearance.js` — remove the local `APPEARANCE_ATTRS`, `appearanceStyle`, and `appearanceProps` definitions (lines ~23–47) and instead re-export them from the new module. The file's imports become:

```js
import { PanelBody, SelectControl, RangeControl, ColorPalette } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { APPEARANCE_ATTRS, appearanceStyle, appearanceProps } from './appearanceStyle';

export { APPEARANCE_ATTRS, appearanceStyle, appearanceProps };
```

Keep the `PALETTE` const and the `AppearancePanel` definition exactly as they are for now (Task 2 extends the panel). The block files import `APPEARANCE_ATTRS`/`appearanceStyle`/`appearanceProps`/`AppearancePanel` from `'../appearance'` and must keep working unchanged — the re-export preserves that.

- [ ] **Step 5: Run the test to verify it passes**

Run: `npm run test:unit -- appearanceStyle`
Expected: all `appearanceStyle`/`appearanceProps`/`APPEARANCE_ATTRS` tests PASS.

- [ ] **Step 6: Run the full suite**

Run: `npm run test:unit`
Expected: prior 27 + the new appearanceStyle tests, all passing, pristine.

- [ ] **Step 7: Commit**

```bash
git add src/block-editor/appearanceStyle.js src/block-editor/appearanceStyle.test.js src/block-editor/appearance.js
git commit -m "refactor(block-editor): extract pure appearanceStyle helpers (jest-tested) + add padding/margin/width attrs"
```

---

### Task 2: Toolbar alignment + Spacing/Width panel sections + block wiring

**Files:**
- Modify: `src/block-editor/appearance.js` (add `AppearanceToolbar`; add Spacing + Width controls to `AppearancePanel`)
- Modify: `src/block-editor/blocks/text.js` (render `AppearanceToolbar`)
- Modify: `src/block-editor/blocks/token.js` (render `AppearanceToolbar`)
- Modify: `src/block-editor/blocks/layout.js` (render `AppearanceToolbar` in `woi/heading`)
- Version: `woocommerce-orders-invoice-pdf.php` (two lines → 1.5.33)
- Build artifact (committed): `assets/js/block-editor/*`

**Interfaces:**
- Consumes: `appearanceStyle`/`appearanceProps`/`APPEARANCE_ATTRS` (Task 1); `BlockControls`, `AlignmentControl` (`@wordpress/block-editor`, stable on live WP).
- Produces: `AppearanceToolbar({ attributes, setAttributes })` exported from `appearance.js`.

- [ ] **Step 1: Add `AppearanceToolbar` + Spacing/Width to `appearance.js`**

In `src/block-editor/appearance.js`, add `BlockControls`/`AlignmentControl` to the imports and export a new `AppearanceToolbar`; extend `AppearancePanel` with a Spacing section (padding + margin) and a Width control.

Update the import lines:

```js
import { PanelBody, SelectControl, RangeControl, ColorPalette } from '@wordpress/components';
import { BlockControls, AlignmentControl } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';
import { APPEARANCE_ATTRS, appearanceStyle, appearanceProps } from './appearanceStyle';

export { APPEARANCE_ATTRS, appearanceStyle, appearanceProps };
```

Add this exported component (e.g. just before `AppearancePanel`):

```jsx
// Block-toolbar text-alignment control, shown on selection for discoverability.
// Writes the SAME `align` attribute the Appearance panel uses, so they stay in
// sync. AlignmentControl uses undefined for "none"; our attr uses ''.
export function AppearanceToolbar( { attributes, setAttributes } ) {
	return (
		<BlockControls group="block">
			<AlignmentControl
				value={ attributes.align || undefined }
				onChange={ ( v ) => setAttributes( { align: v || '' } ) }
			/>
		</BlockControls>
	);
}
```

Inside `AppearancePanel`, add `padding`, `margin`, `width` to the destructure and append these controls before the panel closes (after the Background colour `ColorPalette`):

```jsx
				<RangeControl
					label={ __( 'Padding (px) — 0 = none', 'woocommerce-orders-invoice-pdf' ) }
					value={ padding || 0 }
					onChange={ ( v ) => setAttributes( { padding: v || 0 } ) }
					min={ 0 }
					max={ 48 }
				/>
				<RangeControl
					label={ __( 'Margin (px) — 0 = none', 'woocommerce-orders-invoice-pdf' ) }
					value={ margin || 0 }
					onChange={ ( v ) => setAttributes( { margin: v || 0 } ) }
					min={ 0 }
					max={ 48 }
				/>
				<RangeControl
					label={ __( 'Width (%) — 0 = auto', 'woocommerce-orders-invoice-pdf' ) }
					value={ width ? ( parseInt( width, 10 ) || 0 ) : 0 }
					onChange={ ( v ) => setAttributes( { width: v ? v + '%' : '' } ) }
					min={ 0 }
					max={ 100 }
				/>
```

and update the destructure line at the top of `AppearancePanel`:

```jsx
	const { align, weight, fontSize, color, bg, padding, margin, width } = attributes;
```

(Width is percent-only in Slice 1 — the common "don't span full width" case; mm can be added later if needed.)

- [ ] **Step 2: Render `AppearanceToolbar` in `woi/text`**

In `src/block-editor/blocks/text.js`, add `AppearanceToolbar` to the appearance import and render it in `edit()` alongside the existing panel:

```jsx
import { APPEARANCE_ATTRS, appearanceStyle, appearanceProps, AppearancePanel, AppearanceToolbar } from '../appearance';
```

and in the returned fragment of `edit()`, add the toolbar (e.g. right after the opening `<>`):

```jsx
				<AppearanceToolbar attributes={ attributes } setAttributes={ setAttributes } />
```

(BlockControls renders into the block toolbar slot regardless of position in the fragment; placement is for readability.)

- [ ] **Step 3: Render `AppearanceToolbar` in the token blocks**

In `src/block-editor/blocks/token.js`, add `AppearanceToolbar` to the appearance import and render it in `edit()`. The current `edit()` builds `const panel = <InspectorControls>…</InspectorControls>;` and returns `<>{ panel }{ inner }</>`. Change the import and the return:

```js
import { APPEARANCE_ATTRS, appearanceStyle, appearanceProps, AppearancePanel } from '../appearance';
```
→
```js
import { APPEARANCE_ATTRS, appearanceStyle, appearanceProps, AppearancePanel, AppearanceToolbar } from '../appearance';
```

and

```jsx
				return <>{ panel }{ inner }</>;
```
→
```jsx
				return (
					<>
						<AppearanceToolbar attributes={ attributes } setAttributes={ setAttributes } />
						{ panel }
						{ inner }
					</>
				);
```

- [ ] **Step 4: Render `AppearanceToolbar` in `woi/heading`**

In `src/block-editor/blocks/layout.js`, add `AppearanceToolbar` to the appearance import (the line `import { APPEARANCE_ATTRS, appearanceStyle, appearanceProps, AppearancePanel } from '../appearance';`) and render it in the `woi/heading` `edit()` return. The heading `edit()` returns a fragment containing `<InspectorControls>…</InspectorControls>` then the `<RichText … />`. Add, just inside the opening `<>`:

```jsx
				<AppearanceToolbar attributes={ attributes } setAttributes={ setAttributes } />
```

(Spacer / divider / page-break blocks have no appearance attrs and are NOT touched in this slice.)

- [ ] **Step 5: Bump version**

Set BOTH version lines in `woocommerce-orders-invoice-pdf.php` to `1.5.33`.

- [ ] **Step 6: Build**

Run: `npm run build`
Expected: success; no "export 'AlignmentControl'/'BlockControls' not found" (both verified stable on the live WP). Sibling assets intact.

- [ ] **Step 7: Run unit tests**

Run: `npm run test:unit`
Expected: all pass (27 + appearanceStyle), pristine.

- [ ] **Step 8: Commit**

```bash
git add src/block-editor/appearance.js src/block-editor/blocks/text.js src/block-editor/blocks/token.js src/block-editor/blocks/layout.js assets/js/block-editor woocommerce-orders-invoice-pdf.php
git commit -m "feat(block-editor): toolbar text-alignment control + padding/margin/width in the Appearance panel (Text, Heading, Tokens)"
```

- [ ] **Step 9: Live acceptance (controller/user, post-deploy)**

After deploy, on the Block Invoice Template page:
1. Select a **Text** block → a text-alignment control appears in the **block toolbar** (left/center/right); clicking it aligns the text and stays in sync with the panel's Text align.
2. The **Appearance** panel now shows **Padding**, **Margin**, and **Width (%)** controls below the colours.
3. Setting padding/margin/width changes the block in the canvas and in the rendered PDF (Render PDF).
4. A block with no appearance set still serialises unchanged (no "block contains unexpected content" validation warning on reload).
5. Heading and token blocks show the same toolbar alignment + new panel controls.

---

## Self-Review

**Spec coverage (Slice 1):** padding/margin/width attrs + appearanceStyle mapping (Task 1); toolbar `AlignmentControl` via `AppearanceToolbar` (Task 2 Step 1); Spacing + Width panel sections (Task 2 Step 1); wired into Text/Heading/Tokens (Task 2 Steps 2–4). Covered. (Section row / spacer / divider styling is Slice 2; image sizing is Slice 3 — out of scope here.)

**Placeholder scan:** No TBD/TODO; full test + code shown; live-acceptance concrete. Clean.

**Type consistency:** `APPEARANCE_ATTRS`/`appearanceStyle`/`appearanceProps` signatures unchanged across the extraction (Task 1) and consumed identically by blocks; `AppearanceToolbar({attributes,setAttributes})` defined in Task 2 Step 1 and called with the same props in Steps 2–4. `AlignmentControl` value/onChange map `undefined`↔`''` consistently.

**Testing note:** Task 1's pure helpers are fully jest-tested (the style mapping is the only logic in this slice). Task 2 is panel/toolbar JSX + block wiring → build + live acceptance (jest can't render externalized WP components). `AlignmentControl`/`BlockControls` confirmed present on the live WP via the headless-Chrome probe, so no runtime-undefined risk (the ListView lesson).
