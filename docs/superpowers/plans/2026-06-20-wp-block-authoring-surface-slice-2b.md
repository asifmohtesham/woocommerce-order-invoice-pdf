# WP Block Authoring Surface — Slice 2 Part B (Composition Blocks) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add block *composition* to the invoice block editor: a Columns block (renders as a `<table class="woi-row">`) whose cells are Column blocks (each a `<td>` drop-zone that accepts the token/layout blocks from Part A), plus a "Header row" inserter variation that pre-builds the bilingual EN | logo | AR header. This completes the spec's Slice-2 block set.

**Architecture:** Two nested static blocks following the canonical `core/columns`+`core/column` pattern. `woi/columns` (parent) holds `woi/column` children via InnerBlocks; `woi/column` holds arbitrary invoice blocks via its own InnerBlocks. Both are registered server-side so `do_blocks()` renders them recursively into clean nested `<table>/<tr>/<td>` HTML carrying the `{{tokens}}`. The editor (`edit()`) uses a friendly div/flex layout for usability; `save()` emits the real mPDF-safe table — Gutenberg validates only `save()` markup, so the edit/save divergence is allowed and intentional. The Header row is a client-only `registerBlockVariation` of `woi/columns` (no new server block).

**Tech Stack:** `@wordpress/scripts` 30, `@wordpress/blocks` (`registerBlockType`, `registerBlockVariation`) + `@wordpress/block-editor` (`useBlockProps`, `useInnerBlocksProps`, `InnerBlocks`) + `@wordpress/i18n`, PHP 7.4, PHPUnit 9.

**Scope note:** This is **Slice 2 Part B** — composition only. It builds on Part A (already on `origin/master` at v1.5.3: 17 token blocks + `woi/text` + spacer/divider/heading/page-break). After this, the block palette matches the GrapesJS catalog and Slice 3 (preview parity) is the next feature.

## Global Constraints

- **Invoice-only**; render engine stays mPDF; render-path/REST/storage/build-config untouched.
- **mPDF-safe `save()` markup:** `woi/columns` → `<table class="woi-row"><tbody><tr>…</tr></tbody></table>`; `woi/column` → `<td>…</td>`. These classes/elements are already styled in `templates/_visual/visual-document.css` (`.woi-row{width:100%}`, `.woi-row td{vertical-align:top}`) and permitted by `VisualTemplateStore::allowed_html()` (table/tbody/tr/td) — DO NOT add CSS or kses changes.
- **No flexbox/grid in `save()`** — the flex layout lives ONLY in `edit()` (not serialized).
- **Both new blocks registered in BOTH places:** server `includes/Visual/Blocks.php` `NAMES` and the client JS — names must match exactly. The Header-row *variation* is client-only (it emits `woi/columns`+`woi/column`+token markup, all already registered).
- **Block names:** `woi/columns`, `woi/column` (follow Part A's `woi/<name>` convention).
- **`woi/column` is `inserter:false` + `parent:['woi/columns']`** so it only appears inside a Columns block.
- **Version bump is a shared, collision-prone resource.** Before bumping you MUST `git fetch origin` and read the TRUE `origin/master` value, then take the next patch above it — never assume from the local checkout (another instance may have advanced it). Set BOTH line 6 (`* Version:`) and line 24 (`public string $version`). (See the `version-coordination` memory.)
- **Run PHPUnit as:** `php -d display_errors=1 -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit`. NEVER `vendor/bin/phpunit`.
- **Working tree is the git worktree** at `.claude/worktrees/wp-block-slice-2b` on branch `worktree-wp-block-slice-2b`, based on `origin/master` (includes Part A). All commands run there. Integrate by **fast-forward push to `origin/master`** — never check out `master` in the shared main checkout.

---

## File Structure

**Create:**
- `src/block-editor/blocks/columns.js` — `registerColumnsBlocks()` (registers `woi/columns` + `woi/column`) and `registerHeaderRowVariation()` (the EN|logo|AR inserter variation).

**Modify:**
- `src/block-editor/index.js` — import and call `registerColumnsBlocks()` and `registerHeaderRowVariation()`.
- `includes/Visual/Blocks.php` — add `woi/columns`, `woi/column` to `NAMES`.
- `woocommerce-orders-invoice-pdf.php` — version bump (header + property).

No test files: JS has no harness; new blocks are verified by a clean `npm run build` + live acceptance. The PHP suite must stay green (registration is additive).

---

## Task 1: Composition blocks (`woi/columns` + `woi/column`) and the Header-row variation

**Files:**
- Create: `src/block-editor/blocks/columns.js`
- Modify: `src/block-editor/index.js`
- Modify: `includes/Visual/Blocks.php`

**Interfaces:**
- Consumes: `@wordpress/blocks`, `@wordpress/block-editor`, `@wordpress/i18n`; the Part-A token blocks (`woi/shop-name`, `woi/logo`, `woi/shop-name-ar`) referenced by the Header-row template.
- Produces: `registerColumnsBlocks()` and `registerHeaderRowVariation()` (both exported); two new server-registered block names.

- [ ] **Step 1: Create the composition-blocks module**

Create `src/block-editor/blocks/columns.js`:

```js
import { registerBlockType, registerBlockVariation } from '@wordpress/blocks';
import { useBlockProps, useInnerBlocksProps, InnerBlocks } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

/**
 * Composition blocks. woi/columns renders a single-row table (.woi-row, already
 * styled in visual-document.css); its cells are woi/column blocks, each a <td>
 * drop-zone that accepts any invoice block. edit() uses a friendly flex/div
 * layout for editing; save() emits the real mPDF-safe table — Gutenberg only
 * validates save() markup, so the divergence is intentional and allowed.
 */
export function registerColumnsBlocks() {
	// Child: one table cell holding arbitrary blocks.
	registerBlockType( 'woi/column', {
		apiVersion: 2,
		title: __( 'Column', 'woocommerce-orders-invoice-pdf' ),
		category: 'woi-invoice',
		parent: [ 'woi/columns' ],
		icon: 'columns',
		supports: { html: false, reusable: false, inserter: false },
		edit() {
			const blockProps = useBlockProps( { style: { flex: '1', minWidth: '60px', border: '1px dashed #c3c4c7', padding: '8px', verticalAlign: 'top' } } );
			const innerProps = useInnerBlocksProps( blockProps, { templateLock: false } );
			return <div { ...innerProps } />;
		},
		save() {
			const innerProps = useInnerBlocksProps.save( useBlockProps.save() );
			return <td { ...innerProps } />;
		},
	} );

	// Parent: a one-row table whose cells are woi/column children.
	registerBlockType( 'woi/columns', {
		apiVersion: 2,
		title: __( 'Columns (table row)', 'woocommerce-orders-invoice-pdf' ),
		category: 'woi-invoice',
		icon: 'columns',
		supports: { html: false, reusable: false },
		edit() {
			const blockProps = useBlockProps( { style: { display: 'flex', gap: '8px', alignItems: 'stretch' } } );
			const innerProps = useInnerBlocksProps( blockProps, {
				allowedBlocks: [ 'woi/column' ],
				template: [ [ 'woi/column' ], [ 'woi/column' ] ],
				orientation: 'horizontal',
			} );
			return <div { ...innerProps } />;
		},
		save() {
			return (
				<table { ...useBlockProps.save( { className: 'woi-row' } ) }>
					<tbody>
						<tr>
							<InnerBlocks.Content />
						</tr>
					</tbody>
				</table>
			);
		},
	} );
}

/**
 * "Header row" inserter variation of woi/columns: a 3-column bilingual header
 * pre-filled English shop name | logo | Arabic shop name (matches the UAE
 * bilingual header layout). Client-only — it expands to already-registered blocks.
 */
export function registerHeaderRowVariation() {
	registerBlockVariation( 'woi/columns', {
		name: 'woi-header-row',
		title: __( 'Header row (EN | logo | AR)', 'woocommerce-orders-invoice-pdf' ),
		icon: 'align-center',
		description: __( 'Three-column bilingual header: English | logo | Arabic.', 'woocommerce-orders-invoice-pdf' ),
		scope: [ 'inserter' ],
		innerBlocks: [
			[ 'woi/column', {}, [ [ 'woi/shop-name' ] ] ],
			[ 'woi/column', {}, [ [ 'woi/logo' ] ] ],
			[ 'woi/column', {}, [ [ 'woi/shop-name-ar' ] ] ],
		],
	} );
}
```

> Why `useInnerBlocksProps.save( useBlockProps.save() )` for the column but a bare `<InnerBlocks.Content />` for the parent: the column's inner blocks ARE the direct content of its `<td>` wrapper, so the combined props go on `<td>`. The parent's inner blocks are NOT direct children of its block wrapper (`<table>`) — they live inside `<tbody><tr>` — so the parent spreads only `useBlockProps.save()` on `<table>` and places `<InnerBlocks.Content />` at the real insertion point inside the `<tr>`.

- [ ] **Step 2: Wire the registrars in index.js**

In `src/block-editor/index.js`, add the import next to the other block imports:

```js
import { registerColumnsBlocks, registerHeaderRowVariation } from './blocks/columns';
```

and, right after the existing `registerLayoutBlocks();` call, add:

```js
registerColumnsBlocks();
registerHeaderRowVariation();
```

> Order matters: `registerHeaderRowVariation()` must run AFTER `registerColumnsBlocks()` (the variation targets an already-registered `woi/columns`).

- [ ] **Step 3: Register the two new names server-side**

In `includes/Visual/Blocks.php`, append the two composition block names to the `NAMES` constant (after the layout names from Part A):

```php
		'woi/spacer', 'woi/divider', 'woi/heading', 'woi/page-break',
		'woi/columns', 'woi/column',
	);
```

- [ ] **Step 4: Lint PHP + run the full suite (no regression)**

Run: `php -l includes/Visual/Blocks.php && php -d display_errors=1 -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit`
Expected: no syntax errors; suite PASS, 0 errors (1 intentional skip OK). (No new PHP tests — additive registration; the JSX/InnerBlocks behavior is validated by the build in Task 2 and live acceptance in Task 3.)

- [ ] **Step 5: Syntax-check the new module shape**

Run: `node --check src/block-editor/blocks/columns.js 2>&1 || echo "JSX — validated by the build in Task 2"`
Expected: clean or the JSX note.

- [ ] **Step 6: Commit**

```bash
git add src/block-editor/blocks/columns.js src/block-editor/index.js includes/Visual/Blocks.php
git commit -m "feat(visual): add columns/column composition blocks + header-row variation"
```

---

## Task 2: Build, verify, version bump

**Files:**
- Modify (built output): `assets/js/block-editor/index.js`, `assets/js/block-editor/index.asset.php`
- Modify: `woocommerce-orders-invoice-pdf.php` (version)

**Interfaces:**
- Consumes: Task-1 source + existing `webpack.config.js` (with `clean:false` — DO NOT change it).
- Produces: rebuilt bundle including the composition blocks; a coordination-safe version bump.

- [ ] **Step 1: Build**

Run: `npm run build`
Expected: `webpack … compiled successfully`; `assets/js/block-editor/index.js` + `index.asset.php` emitted; `assets/js/home/index.js` still present.
> If the build fails on a JSX/InnerBlocks compile error, a Task-1 file is broken — report BLOCKED with the exact error + file:line; do not rewrite the block source here.
> If `node_modules` is missing, run `npm install` first (worktrees don't share it).

- [ ] **Step 2: Sibling-asset safety check (Slice-1 lesson)**

Run: `git status --short assets/js`
Expected: only modifications to `block-editor/*` (and possibly `home/index.*`); NO deletions (` D `) of `admin.js`, `pdf_js/*`, `order-script.js`, etc. If any sibling was deleted, STOP and report (clean:false should prevent this).

- [ ] **Step 3: Confirm the composition blocks compiled in**

Run: `grep -c "woi/columns\|woi/column\|woi-header-row" assets/js/block-editor/index.js`
Expected: non-zero (the minified bundle contains the new names — proves they were compiled, not tree-shaken).

- [ ] **Step 4: Read the TRUE current version from origin/master (coordination)**

Run: `git fetch origin && git show origin/master:woocommerce-orders-invoice-pdf.php | grep -m1 "Version:"`
Note the value. The new version is the next patch above whatever this prints — do NOT assume `1.5.4`; another instance may have advanced it.

- [ ] **Step 5: Bump the version in both places**

In `woocommerce-orders-invoice-pdf.php`, set line 6 (`* Version:`) and line 24 (`public string $version`) to the next patch above the Step-4 value (both lines identical).

- [ ] **Step 6: Commit**

```bash
git add assets/js/block-editor woocommerce-orders-invoice-pdf.php
git commit -m "build(visual): rebuild bundle with composition blocks; bump version"
```

> Update the `version-coordination` memory's "current released version" line after this branch is pushed.

---

## Task 3: Live acceptance (manual — user, requires deploy)

**Files:** none (verification only)

NOT implementable in the dev environment — needs a live WordPress+WooCommerce site and a deploy (manual `git pull` of the built assets). Hand to the user.

- [ ] **Step 1: Deploy** the branch to the live site (manual git pull) so the rebuilt bundle is served (the version bump cache-busts it).

- [ ] **Step 2: Columns nesting.** In wp-admin → PDF Invoices → **Block Editor**, insert a **Columns (table row)** block; confirm it starts with two **Column** cells, and that you can drop token blocks (e.g. Shop name in the left cell, Logo in the right) and add/remove columns.

- [ ] **Step 3: Header row variation.** From the inserter, add **Header row (EN | logo | AR)**; confirm it creates a 3-cell row pre-filled with Shop name | Logo | Shop name (AR).

- [ ] **Step 4: Render.** Build an invoice using a Columns row (and/or Header row) plus Line items + Totals, **Save**, set **PDF source → Block editor**, ensure **Visual template (invoice)** is ON, generate a real-order invoice, and rasterize with PyMuPDF (see `rendering-pdfs-for-verification`). Confirm: the columns render as a side-by-side table row (not stacked), tokens inside cells resolve (no raw `{{…}}`), the header row shows English | logo | Arabic across one row, and Arabic is intact.

- [ ] **Step 5: Switch-back.** Flip **PDF source → GrapesJS**, regenerate, confirm the GrapesJS design still renders (GrapesJS untouched).

Expected: nested composition works in the editor; a block-authored invoice with table-row layout renders correctly through mPDF; the bilingual header lays out in one row.

> Known watch-points to eyeball (InnerBlocks + do_blocks have no automated oracle here): (a) the saved markup is a clean `<table class="woi-row"><tbody><tr><td>…</td>…</tr></tbody></table>` with no stray `<div>` wrappers around the cells; (b) `do_blocks` renders nested column cells (not empty); (c) an empty column still emits a `<td></td>` so the row structure holds.

---

## Self-Review

**Spec coverage (Slice 2 Part B scope):**
- Row/Columns block rendering as a `<table>` → Task 1 (`woi/columns` save → `<table class="woi-row">`). ✓
- Editable cells that accept blocks → Task 1 (`woi/column` InnerBlocks drop-zone → `<td>`). ✓
- Header Row (EN | logo | AR) → Task 1 (`registerHeaderRowVariation`, shop-name | logo | shop-name-ar). ✓
- Server + client registration in lockstep → Task 1 (`Blocks.php::NAMES` += columns/column; JS registers same). ✓
- mPDF-safe markup reusing existing shared CSS/kses → constraint honored (`.woi-row` already styled; table/tr/td already in the kses allowlist; no CSS/kses edits). ✓
- Build + coordination-safe version bump → Task 2. ✓
- Live acceptance → Task 3 (user). ✓

**Placeholder scan:** None. All code is complete; the version literal is intentionally resolved at execution time from `origin/master` (Task 2 Step 4) because it is a concurrently-mutated shared resource.

**Type/name consistency:** Block names `woi/columns` and `woi/column` are identical between the JS (`registerBlockType` calls + the variation's `innerBlocks` template + the parent's `allowedBlocks`/`template`) and the server `NAMES` array. `registerColumnsBlocks` and `registerHeaderRowVariation` are both defined in `columns.js` and imported/called in `index.js`, in that order. The Header-row template references Part-A blocks that exist on `origin/master` (`woi/shop-name`, `woi/logo`, `woi/shop-name-ar`). Save markup classes (`woi-row`) match `templates/_visual/visual-document.css`. ✓
