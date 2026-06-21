# Block Styling — Slice 2: Structural Blocks (Section Row, Spacer, Divider) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend styling to the structural blocks: the section row (`woi/columns`) gains the shared Appearance system + a row border; the Spacer gains an adjustable height; the Divider gains adjustable thickness + colour.

**Architecture:** `woi/columns` reuses the shared `appearance.js` (panel + toolbar + `appearanceStyle`/`appearanceProps`) plus a row-level `border` boolean, baking inline styles onto its `<table class="woi-row">`. `woi/spacer` and `woi/divider` get their own small attributes and emit inline styles in `save()` only when set. Every attribute is default-empty/0/false, so an unstyled block serialises byte-identical to today (no block-validation break) — the same additive pattern used for the `woi/column` cell controls.

**Tech Stack:** `@wordpress/block-editor`, `@wordpress/components` (`RangeControl`, `ColorPalette`, `ToggleControl`), the shared `appearance.js`, jest via `@wordpress/scripts`, debug-Chrome live harness.

## Global Constraints

- Spec: `docs/superpowers/specs/2026-06-21-block-styling-design.md` (Slice 2).
- **Inline styles only**; all props (`background-color`, `padding`, `text-align`, `border`, `height`, `border-top`) are `safecss_filter_attr`-allowed on already-allowed tags (`table`, `div`, `hr` all carry `style` via `$common` in `VisualTemplateStore::allowed_html`) → **NO kses/allowlist change**.
- **Back-compat invariant:** every new attribute defaults empty/0/false; `save()` emits the SAME markup as today when nothing is set (`woi/columns` → bare `<table class="woi-row">`; `woi/spacer` → bare `<div class="woi-spacer">`; `woi/divider` → bare `<hr>`). No deprecation needed.
- mPDF honours: `background-color`/`padding`/`text-align`/`border` on a table, `height` (mm) on a div, `border-top` on an hr.
- Build with `npm run build`; `output.clean:false` stays; sibling assets intact. **Worktree needs a REAL `node_modules` (`npm install`)** (bundling `@wordpress/interface` breaks through a junction). Controller provisions before dispatch.
- Version bump BOTH lines in `woocommerce-orders-invoice-pdf.php` to **1.5.35** (origin/master is at 1.5.34).
- Run full `npm run test:unit` before each commit (must stay green; no new tests expected — these are block edit/save changes like the prior woi/column slices). Work in a worktree; FF push to master; read true version from origin/master before bumping.

---

### Task 1: Section row (`woi/columns`) appearance + border

**Files:**
- Modify: `src/block-editor/blocks/columns.js` (the `woi/columns` registration — `attributes`, `edit`, `save`; ~lines 151–222)
- Build artifact (committed): `assets/js/block-editor/*`

**Interfaces:**
- Consumes: `APPEARANCE_ATTRS`, `appearanceStyle`, `AppearancePanel`, `AppearanceToolbar` from `'../appearance'`; `ToggleControl` from `@wordpress/components` (already imported in columns.js).

- [ ] **Step 1: Add the appearance import to `columns.js`**

At the top of `src/block-editor/blocks/columns.js`, add the appearance import (next to the existing imports):

```js
import { APPEARANCE_ATTRS, appearanceStyle, AppearancePanel, AppearanceToolbar } from '../appearance';
```

(`ToggleControl` is already imported from `@wordpress/components` in this file — confirm it is in the existing import list; it is used by `woi/column`.)

- [ ] **Step 2: Add attributes to `woi/columns`**

In the `registerBlockType( 'woi/columns', { … } )` object, add an `attributes` key (it currently has none) right after `icon: 'columns',`:

```js
		attributes: {
			...APPEARANCE_ATTRS,
			border: { type: 'boolean', default: false },
		},
```

- [ ] **Step 3: Wire appearance into `woi/columns` `edit()`**

Change `edit( { clientId } )` to `edit( { clientId, attributes, setAttributes } )`. Apply the appearance style to the preview container and add the toolbar + appearance panel + a border toggle. Replace the `blockProps` line and the returned JSX:

`blockProps`:
```js
			const blockProps = useBlockProps( {
				style: {
					display: 'flex',
					gap: '8px',
					alignItems: 'stretch',
					...appearanceStyle( attributes ),
					...( attributes.border ? { border: '1px solid #000' } : {} ),
				},
			} );
```

Return JSX — add `<AppearanceToolbar>` and, inside `<InspectorControls>`, add the border toggle and `<AppearancePanel>` after the existing Columns `PanelBody`:

```jsx
			return (
				<>
					<AppearanceToolbar attributes={ attributes } setAttributes={ setAttributes } />
					<InspectorControls>
						<PanelBody title={ __( 'Columns', 'woocommerce-orders-invoice-pdf' ) }>
							<RangeControl
								label={ __( 'Number of columns', 'woocommerce-orders-invoice-pdf' ) }
								value={ count }
								onChange={ setColumns }
								min={ 1 }
								max={ MAX_COLUMNS }
							/>
							<Button variant="secondary" onClick={ equalizeWidths }>
								{ __( 'Equalize column widths', 'woocommerce-orders-invoice-pdf' ) }
							</Button>
							<ToggleControl
								label={ __( 'Row border', 'woocommerce-orders-invoice-pdf' ) }
								checked={ attributes.border }
								onChange={ ( v ) => setAttributes( { border: v } ) }
							/>
						</PanelBody>
						<AppearancePanel attributes={ attributes } setAttributes={ setAttributes } />
					</InspectorControls>
					<div { ...innerProps } />
				</>
			);
```

- [ ] **Step 4: Bake styles into `woi/columns` `save()`**

Replace the `save()`:

```jsx
		save( { attributes } ) {
			const style = appearanceStyle( attributes );
			if ( attributes.border ) { style.border = '0.5pt solid #000'; }
			const extra = Object.keys( style ).length ? { style } : {};
			return (
				<table { ...useBlockProps.save( { className: 'woi-row', ...extra } ) }>
					<tbody>
						<tr>
							<InnerBlocks.Content />
						</tr>
					</tbody>
				</table>
			);
		},
```

Back-compat: with no appearance set and `border:false`, `style` is `{}`, `extra` is `{}`, and `save()` emits `<table class="woi-row">…` — byte-identical to the current output.

- [ ] **Step 5: Build**

Run: `npm run build`
Expected: success; sibling assets intact.

- [ ] **Step 6: Run unit tests**

Run: `npm run test:unit`
Expected: 35 passing, pristine (no test change).

- [ ] **Step 7: Commit**

```bash
git add src/block-editor/blocks/columns.js assets/js/block-editor
git commit -m "feat(block-editor): woi/columns section-row appearance (bg/padding/align via shared system) + row border"
```

---

### Task 2: Spacer height + Divider thickness/colour

**Files:**
- Modify: `src/block-editor/blocks/layout.js` (the `woi/spacer` and `woi/divider` registrations; ~lines 14–46)
- Version: `woocommerce-orders-invoice-pdf.php` (two lines → 1.5.35)
- Build artifact (committed): `assets/js/block-editor/*`

**Interfaces:**
- Consumes: `RangeControl`, `ColorPalette` from `@wordpress/components` (add to the existing import), `InspectorControls` (already imported).

- [ ] **Step 1: Extend the `@wordpress/components` import in `layout.js`**

Change `import { PanelBody, SelectControl } from '@wordpress/components';` to:

```js
import { PanelBody, SelectControl, RangeControl, ColorPalette } from '@wordpress/components';
```

- [ ] **Step 2: Add adjustable height to `woi/spacer`**

Replace the entire `registerBlockType( 'woi/spacer', { … } )` block with:

```jsx
	// Spacer — vertical gap. CSS default .woi-spacer { height: 12mm }; an optional
	// height attribute overrides it inline (mm). Unset → bare class (CSS default).
	registerBlockType( 'woi/spacer', {
		apiVersion: 2,
		title: __( 'Spacer', 'woocommerce-orders-invoice-pdf' ),
		category: 'woi-invoice',
		icon: 'minus',
		attributes: { height: { type: 'number', default: 0 } },
		supports: { html: false, reusable: false },
		edit( { attributes, setAttributes } ) {
			const { height } = attributes;
			return (
				<>
					<InspectorControls>
						<PanelBody title={ __( 'Spacer', 'woocommerce-orders-invoice-pdf' ) }>
							<RangeControl
								label={ __( 'Height (mm) — 0 = default', 'woocommerce-orders-invoice-pdf' ) }
								value={ height || 0 }
								onChange={ ( v ) => setAttributes( { height: v || 0 } ) }
								min={ 0 }
								max={ 60 }
							/>
						</PanelBody>
					</InspectorControls>
					<div { ...useBlockProps( { style: { minHeight: height ? height + 'mm' : '24px', background: 'repeating-linear-gradient(45deg,#f3f4f5,#f3f4f5 6px,#fff 6px,#fff 12px)', border: '1px dashed #c3c4c7' } } ) }>
						<span style={ { fontSize: '11px', color: '#666' } }>{ __( 'Spacer', 'woocommerce-orders-invoice-pdf' ) }</span>
					</div>
				</>
			);
		},
		save( { attributes } ) {
			const props = attributes.height
				? { className: 'woi-spacer', style: { height: attributes.height + 'mm' } }
				: { className: 'woi-spacer' };
			return <div { ...useBlockProps.save( props ) } />;
		},
	} );
```

Back-compat: `height:0` → `save()` emits `<div class="woi-spacer">` — identical to today (CSS keeps the 12mm default).

- [ ] **Step 3: Add thickness + colour to `woi/divider`**

Replace the entire `registerBlockType( 'woi/divider', { … } )` block with:

```jsx
	// Divider — horizontal rule. Optional thickness (px) + colour; unset → bare <hr>.
	registerBlockType( 'woi/divider', {
		apiVersion: 2,
		title: __( 'Divider', 'woocommerce-orders-invoice-pdf' ),
		category: 'woi-invoice',
		icon: 'minus',
		attributes: {
			thickness: { type: 'number', default: 0 },
			color: { type: 'string', default: '' },
		},
		supports: { html: false, reusable: false },
		edit( { attributes, setAttributes } ) {
			const { thickness, color } = attributes;
			const previewStyle = ( thickness || color )
				? { border: 0, borderTop: ( thickness || 1 ) + 'px solid ' + ( color || '#000' ) }
				: undefined;
			return (
				<>
					<InspectorControls>
						<PanelBody title={ __( 'Divider', 'woocommerce-orders-invoice-pdf' ) }>
							<RangeControl
								label={ __( 'Thickness (px) — 0 = default', 'woocommerce-orders-invoice-pdf' ) }
								value={ thickness || 0 }
								onChange={ ( v ) => setAttributes( { thickness: v || 0 } ) }
								min={ 0 }
								max={ 10 }
							/>
							<p style={ { margin: '12px 0 4px' } }>{ __( 'Colour', 'woocommerce-orders-invoice-pdf' ) }</p>
							<ColorPalette value={ color } onChange={ ( c ) => setAttributes( { color: c || '' } ) } />
						</PanelBody>
					</InspectorControls>
					<div { ...useBlockProps() }><hr style={ previewStyle } /></div>
				</>
			);
		},
		save( { attributes } ) {
			const { thickness, color } = attributes;
			if ( thickness || color ) {
				return <hr { ...useBlockProps.save( { style: { border: 0, borderTop: ( thickness || 1 ) + 'px solid ' + ( color || '#000' ) } } ) } />;
			}
			return <hr { ...useBlockProps.save() } />;
		},
	} );
```

Back-compat: `thickness:0` + `color:''` → `save()` emits a bare `<hr>` — identical to today.

- [ ] **Step 4: Bump version**

Set BOTH version lines in `woocommerce-orders-invoice-pdf.php` to `1.5.35`.

- [ ] **Step 5: Build**

Run: `npm run build`
Expected: success; sibling assets intact.

- [ ] **Step 6: Run unit tests**

Run: `npm run test:unit`
Expected: 35 passing, pristine.

- [ ] **Step 7: Commit**

```bash
git add src/block-editor/blocks/layout.js assets/js/block-editor woocommerce-orders-invoice-pdf.php
git commit -m "feat(block-editor): woi/spacer adjustable height (mm) + woi/divider thickness/colour"
```

- [ ] **Step 8: Live acceptance (controller/user, post-deploy)**

After deploy, on the Block Invoice Template page:
1. Select the **Columns (table row)** header block → Appearance panel (bg/padding/align/etc.) + a **Row border** toggle + toolbar alignment; setting a background/border/padding shows on the row in the canvas and in the rendered PDF.
2. Select a **Spacer** → **Height (mm)** control changes the gap; Render PDF reflects it.
3. Select a **Divider** → **Thickness (px)** + **Colour** change the rule; Render PDF reflects it.
4. An untouched columns row / spacer / divider still serialises unchanged (no "unexpected content" validation warning on reload).

---

## Self-Review

**Spec coverage (Slice 2):** `woi/columns` row background/border/padding/align (Task 1); `woi/spacer` adjustable height (Task 2 Step 2); `woi/divider` thickness + colour (Task 2 Step 3). Covered. (Image/logo sizing is Slice 3.)

**Placeholder scan:** No TBD/TODO; full replacement code for each block shown; live-acceptance concrete. Clean.

**Type consistency:** `woi/columns` adds `...APPEARANCE_ATTRS` + `border:boolean`; `save()` merges `appearanceStyle(attributes)` + the border into one `style` and only spreads it when non-empty (back-compat). `woi/spacer` `height:number`; `woi/divider` `thickness:number`+`color:string` — each `save()` has an explicit unset branch emitting the original markup. Imports: `appearance.js` helpers in columns.js; `RangeControl`/`ColorPalette` added to layout.js.

**Testing note:** these are block `edit()`/`save()` changes (jest can't render them; the pure `appearanceStyle` they reuse is already tested 35/35). Verification is build + live acceptance + the reviewer's back-compat check (unset → byte-identical `save()`), exactly as the prior `woi/column` attribute slices (v1.5.10–1.5.16) were done. No kses/allowlist change (all tags already allow `style`).
