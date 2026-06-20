# WP Block Authoring Surface — Slice 2 (Token + Static Layout Blocks) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expand the block editor palette from the Slice-1 starter (4 blocks) to the full set of token blocks (all 17 invoice tokens) plus the simple static layout blocks (Spacer, Divider, Heading, Page Break), so a user can compose a complete invoice from blocks — matching the GrapesJS catalog.

**Architecture:** Pure extension of the Slice-1 mechanism. Token blocks are static blocks whose `save()` emits the literal `{{token}}`; layout blocks are static blocks whose `save()` emits the same class-keyed markup the GrapesJS editor and the shared `templates/_visual/visual-document.css` already use. Every new block is registered server-side (so `do_blocks()` renders it) and client-side (so it appears in the inserter). No render-path, REST, storage, or build-config changes.

**Tech Stack:** `@wordpress/scripts` 30 (wp-scripts/webpack), `@wordpress/blocks` + `@wordpress/block-editor` + `@wordpress/i18n`, PHP 7.4, PHPUnit 9.

**Scope note:** This plan is **Slice 2 Part A** — the token blocks and the *static* layout blocks (no nested content). The InnerBlocks **composition** blocks from the spec's Slice 2 (Columns/Column drop-zones, Header Row EN|logo|AR) are a separate, higher-risk subsystem (nested-block serialization + recursive `do_blocks`) and get their own plan (Slice 2 Part B). Part A is independently shippable: it expands the palette to the full atomic catalog and changes nothing about how Slice 1 already renders.

## Global Constraints

- **Invoice-only**; render engine stays mPDF; render-path/REST/storage/build-config untouched.
- **mPDF-safe markup:** layout blocks must emit the SAME classes the shared CSS targets — `<div class="woi-spacer">`, `<div class="woi-pagebreak">`, `<hr>`, `<h2>` — never flexbox/grid. (`templates/_visual/visual-document.css` already styles `.woi-spacer{height:12mm}` and `.woi-pagebreak{page-break-after:always;height:0}`.)
- **Token `save()` emits the literal `{{token}}` as plain-text content** so kses leaves it untouched; the value is merged later by `TemplateTokens` at PDF time.
- **Every new block registered in BOTH places:** server `includes/Visual/Blocks.php` `NAMES` (for `do_blocks`) and the client JS factory (for the inserter). The two lists must stay in lockstep.
- **Block names** follow Slice-1 convention `woi/<token-with-dashes>` (e.g. `woi/shop-address`, `woi/page-break`).
- **Version bump is a shared, collision-prone resource.** `Version:` (line 6 of `woocommerce-orders-invoice-pdf.php`) doubles as `WOI_PDF_VERSION` and another instance may be bumping it concurrently. Before bumping you MUST `git fetch origin` and read the TRUE current value from `origin/master`, then take the next patch above it — never assume from the local checkout. (See the `version-coordination` memory.)
- **Run PHPUnit as:** `php -d display_errors=1 -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit`. NEVER `vendor/bin/phpunit`.
- **Working tree is a git worktree** at `.claude/worktrees/wp-block-slice-2` on branch `worktree-wp-block-slice-2`, based on `origin/master` (includes Slice 1). All commands run there.

---

## File Structure

**Modify:**
- `src/block-editor/blocks/token.js` — extend the `TOKENS` array from 3 to all 17 entries.
- `includes/Visual/Blocks.php` — extend `NAMES` with the 14 new token blocks + 4 layout blocks.
- `src/block-editor/index.js` — import and call `registerLayoutBlocks()`.
- `woocommerce-orders-invoice-pdf.php` — version bump (header + property).

**Create:**
- `src/block-editor/blocks/layout.js` — `registerLayoutBlocks()` registering `woi/spacer`, `woi/divider`, `woi/heading`, `woi/page-break`.

No test files: JS has no harness in this repo; the existing PHP tests already cover the storage/REST/resolver/page surfaces and must stay green. New blocks are verified by a clean `npm run build` + live acceptance.

---

## Task 1: All remaining token blocks

**Files:**
- Modify: `src/block-editor/blocks/token.js`
- Modify: `includes/Visual/Blocks.php`

**Interfaces:**
- Consumes: the existing `registerTokenBlocks()` factory (unchanged) and the canonical token list from `includes/Visual/TemplateTokens.php::map()` (17 tokens).
- Produces: 14 additional registered token blocks; their names added to server `NAMES`.

The canonical 17 tokens (from `TemplateTokens::map`, mirrored by the GrapesJS `TOKEN_META`): logo, shop_name✓, shop_address, shop_name_ar, shop_address_ar, trn, shop_phone, shop_email, document_title, document_title_ar, invoice_number, invoice_date, order_number, payment_method, billing_address, line_items✓, totals✓ (✓ = already shipped in Slice 1).

- [ ] **Step 1: Replace the TOKENS array**

In `src/block-editor/blocks/token.js`, replace the existing 3-entry `TOKENS` array with the full 17-entry list (keep the existing 3 entries verbatim; add the other 14). Use `tag: 'div'` for block/markup tokens (`logo`, `billing_address`, the two already-present `line_items`/`totals`) and `tag: 'p'` for scalar text tokens. Previews mirror the GrapesJS sample data:

```js
const TOKENS = [
	{ name: 'woi/logo',              title: __( 'Logo image', 'woocommerce-orders-invoice-pdf' ),        token: '{{logo}}',              tag: 'div', preview: '[ logo image ]' },
	{ name: 'woi/shop-name',         title: __( 'Shop name', 'woocommerce-orders-invoice-pdf' ),         token: '{{shop_name}}',         tag: 'p',   preview: 'Acme Trading LLC' },
	{ name: 'woi/shop-address',      title: __( 'Shop address', 'woocommerce-orders-invoice-pdf' ),      token: '{{shop_address}}',      tag: 'p',   preview: 'Office 12, Dubai, UAE' },
	{ name: 'woi/shop-name-ar',      title: __( 'Shop name (AR)', 'woocommerce-orders-invoice-pdf' ),    token: '{{shop_name_ar}}',      tag: 'p',   preview: 'أكمي للتجارة' },
	{ name: 'woi/shop-address-ar',   title: __( 'Shop address (AR)', 'woocommerce-orders-invoice-pdf' ), token: '{{shop_address_ar}}',   tag: 'p',   preview: 'مكتب ١٢، دبي' },
	{ name: 'woi/trn',               title: __( 'TRN', 'woocommerce-orders-invoice-pdf' ),                token: '{{trn}}',               tag: 'p',   preview: '100123456700003' },
	{ name: 'woi/shop-phone',        title: __( 'Shop phone', 'woocommerce-orders-invoice-pdf' ),        token: '{{shop_phone}}',        tag: 'p',   preview: '+971 4 000 0000' },
	{ name: 'woi/shop-email',        title: __( 'Shop email', 'woocommerce-orders-invoice-pdf' ),        token: '{{shop_email}}',        tag: 'p',   preview: 'billing@acme.example' },
	{ name: 'woi/document-title',    title: __( 'Document title', 'woocommerce-orders-invoice-pdf' ),    token: '{{document_title}}',    tag: 'p',   preview: 'Tax Invoice' },
	{ name: 'woi/document-title-ar', title: __( 'Document title (AR)', 'woocommerce-orders-invoice-pdf' ),token: '{{document_title_ar}}', tag: 'p',   preview: 'فاتورة ضريبية' },
	{ name: 'woi/invoice-number',    title: __( 'Invoice number', 'woocommerce-orders-invoice-pdf' ),    token: '{{invoice_number}}',    tag: 'p',   preview: 'INV-001' },
	{ name: 'woi/invoice-date',      title: __( 'Invoice date', 'woocommerce-orders-invoice-pdf' ),      token: '{{invoice_date}}',      tag: 'p',   preview: '18 June 2026' },
	{ name: 'woi/order-number',      title: __( 'Order number', 'woocommerce-orders-invoice-pdf' ),      token: '{{order_number}}',      tag: 'p',   preview: '4242' },
	{ name: 'woi/payment-method',    title: __( 'Payment method', 'woocommerce-orders-invoice-pdf' ),    token: '{{payment_method}}',    tag: 'p',   preview: 'Credit Card' },
	{ name: 'woi/billing-address',   title: __( 'Billing address', 'woocommerce-orders-invoice-pdf' ),   token: '{{billing_address}}',   tag: 'div', preview: 'John Buyer, Abu Dhabi, UAE' },
	{ name: 'woi/line-items',        title: __( 'Line items table', 'woocommerce-orders-invoice-pdf' ),  token: '{{line_items}}',        tag: 'div', preview: '[ line items table ]' },
	{ name: 'woi/totals',            title: __( 'Totals table', 'woocommerce-orders-invoice-pdf' ),      token: '{{totals}}',            tag: 'div', preview: '[ totals table ]' },
];
```

Leave `registerTokenBlocks()` (the factory below the array) exactly as-is.

- [ ] **Step 2: Register the 14 new token names server-side**

In `includes/Visual/Blocks.php`, replace the `NAMES` constant so it lists every token block (keep `woi/text` for the editable text block; the layout names are added in Task 2):

```php
	/** @var string[] Block names registered for the visual editor. */
	private const NAMES = array(
		'woi/text',
		'woi/logo', 'woi/shop-name', 'woi/shop-address', 'woi/shop-name-ar', 'woi/shop-address-ar',
		'woi/trn', 'woi/shop-phone', 'woi/shop-email',
		'woi/document-title', 'woi/document-title-ar', 'woi/invoice-number', 'woi/invoice-date',
		'woi/order-number', 'woi/payment-method', 'woi/billing-address',
		'woi/line-items', 'woi/totals',
	);
```

- [ ] **Step 3: Lint PHP + run the full suite (no regression)**

Run: `php -l includes/Visual/Blocks.php && php -d display_errors=1 -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit`
Expected: no syntax errors; suite PASS, 0 errors (1 intentional skip OK). (No new tests — these are data additions; the build in Task 3 validates the JS.)

- [ ] **Step 4: Syntax-check the JS module shape**

Run: `node --check src/block-editor/blocks/token.js 2>&1 || echo "JSX — validated by the build in Task 3"`
Expected: either clean or the JSX note (the file contains JSX, so a clean build in Task 3 is the real gate).

- [ ] **Step 5: Commit**

```bash
git add src/block-editor/blocks/token.js includes/Visual/Blocks.php
git commit -m "feat(visual): add the full set of invoice token blocks"
```

---

## Task 2: Static layout blocks (Spacer, Divider, Heading, Page Break)

**Files:**
- Create: `src/block-editor/blocks/layout.js`
- Modify: `src/block-editor/index.js`
- Modify: `includes/Visual/Blocks.php`

**Interfaces:**
- Consumes: `@wordpress/blocks`, `@wordpress/block-editor` (`useBlockProps`, `RichText`), `@wordpress/i18n`.
- Produces: `registerLayoutBlocks()` registering `woi/spacer`, `woi/divider`, `woi/heading`, `woi/page-break`; the four names added to server `NAMES`; `index.js` calls the new registrar.

Each block's `save()` emits markup matching the shared `visual-document.css`. The `edit()` adds a light in-canvas representation so the otherwise-invisible blocks (spacer, page break) are visible while editing.

- [ ] **Step 1: Create the layout-block module**

Create `src/block-editor/blocks/layout.js`:

```js
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, RichText } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

/**
 * Static layout blocks. Each save() emits the same class-keyed markup the
 * GrapesJS editor and templates/_visual/visual-document.css already use, so the
 * PDF renders identically regardless of which editor authored the design.
 * edit() adds a light in-canvas hint for the otherwise-invisible blocks.
 */
export function registerLayoutBlocks() {
	// Spacer — vertical gap. CSS: .woi-spacer { height: 12mm }.
	registerBlockType( 'woi/spacer', {
		apiVersion: 2,
		title: __( 'Spacer', 'woocommerce-orders-invoice-pdf' ),
		category: 'woi-invoice',
		icon: 'minus',
		supports: { html: false, reusable: false },
		edit() {
			return (
				<div { ...useBlockProps( { style: { minHeight: '24px', background: 'repeating-linear-gradient(45deg,#f3f4f5,#f3f4f5 6px,#fff 6px,#fff 12px)', border: '1px dashed #c3c4c7' } } ) }>
					<span style={ { fontSize: '11px', color: '#666' } }>{ __( 'Spacer', 'woocommerce-orders-invoice-pdf' ) }</span>
				</div>
			);
		},
		save() {
			return <div { ...useBlockProps.save( { className: 'woi-spacer' } ) } />;
		},
	} );

	// Divider — horizontal rule.
	registerBlockType( 'woi/divider', {
		apiVersion: 2,
		title: __( 'Divider', 'woocommerce-orders-invoice-pdf' ),
		category: 'woi-invoice',
		icon: 'minus',
		supports: { html: false, reusable: false },
		edit() {
			return <div { ...useBlockProps() }><hr /></div>;
		},
		save() {
			return <hr { ...useBlockProps.save() } />;
		},
	} );

	// Heading — editable section heading (<h2>).
	registerBlockType( 'woi/heading', {
		apiVersion: 2,
		title: __( 'Heading', 'woocommerce-orders-invoice-pdf' ),
		category: 'woi-invoice',
		icon: 'heading',
		attributes: { content: { type: 'string', source: 'html', selector: 'h2', default: '' } },
		supports: { reusable: false },
		edit( { attributes, setAttributes } ) {
			return (
				<RichText
					{ ...useBlockProps() }
					tagName="h2"
					value={ attributes.content }
					onChange={ ( content ) => setAttributes( { content } ) }
					placeholder={ __( 'Section heading…', 'woocommerce-orders-invoice-pdf' ) }
				/>
			);
		},
		save( { attributes } ) {
			return <RichText.Content { ...useBlockProps.save() } tagName="h2" value={ attributes.content } />;
		},
	} );

	// Page break — forces a new PDF page. CSS: .woi-pagebreak { page-break-after: always; height: 0 }.
	registerBlockType( 'woi/page-break', {
		apiVersion: 2,
		title: __( 'Page break', 'woocommerce-orders-invoice-pdf' ),
		category: 'woi-invoice',
		icon: 'page',
		supports: { html: false, reusable: false },
		edit() {
			return (
				<div { ...useBlockProps( { style: { borderTop: '2px dashed #999', textAlign: 'center', margin: '8px 0' } } ) }>
					<span style={ { fontSize: '11px', color: '#666', background: '#fff', padding: '0 6px' } }>{ __( 'Page break', 'woocommerce-orders-invoice-pdf' ) }</span>
				</div>
			);
		},
		save() {
			return <div { ...useBlockProps.save( { className: 'woi-pagebreak' } ) } />;
		},
	} );
}
```

> Note the `woi/spacer` and `woi/page-break` `edit()` pass the inline canvas style INTO `useBlockProps( { style: … } )` (so the block wrapper itself carries it), while `save()` passes only `className` to `useBlockProps.save()` — the canvas hint never reaches the PDF markup.

- [ ] **Step 2: Wire the registrar in index.js**

In `src/block-editor/index.js`, add the import alongside the existing block imports and call it next to `registerTokenBlocks()`:

```js
import { registerLayoutBlocks } from './blocks/layout';
```

and, right after the existing `registerTokenBlocks();` call:

```js
registerLayoutBlocks();
```

- [ ] **Step 3: Register the four layout names server-side**

In `includes/Visual/Blocks.php`, extend the `NAMES` constant (added in Task 1) with the layout names — append them to the array:

```php
		'woi/line-items', 'woi/totals',
		'woi/spacer', 'woi/divider', 'woi/heading', 'woi/page-break',
	);
```

- [ ] **Step 4: Lint + full suite**

Run: `php -l includes/Visual/Blocks.php && php -d display_errors=1 -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit`
Expected: no syntax errors; suite PASS, 0 errors.

- [ ] **Step 5: Syntax-check the new module**

Run: `node --check src/block-editor/blocks/layout.js 2>&1 || echo "JSX — validated by the build in Task 3"`
Expected: clean or the JSX note.

- [ ] **Step 6: Commit**

```bash
git add src/block-editor/blocks/layout.js src/block-editor/index.js includes/Visual/Blocks.php
git commit -m "feat(visual): add static layout blocks (spacer, divider, heading, page break)"
```

---

## Task 3: Build, verify emitted assets, version bump

**Files:**
- Modify (built output): `assets/js/block-editor/index.js`, `assets/js/block-editor/index.asset.php`
- Modify: `woocommerce-orders-invoice-pdf.php` (version)

**Interfaces:**
- Consumes: the source from Tasks 1–2 and the existing `webpack.config.js` (with `clean:false` — DO NOT change it; without it the build wipes sibling `assets/js/*`).
- Produces: a rebuilt `assets/js/block-editor/index.js` bundling all 21 blocks, and a coordinated version bump.

- [ ] **Step 1: Build**

Run: `npm run build`
Expected: `webpack … compiled successfully`; `assets/js/block-editor/index.js` + `index.asset.php` emitted; `assets/js/home/index.js` still present; NO sibling assets deleted (`git status --short assets/js` shows only modifications to block-editor/home, never deletions of admin.js / pdf_js / order-script.js).

> If `npm run build` reports a missing `node_modules`, run `npm install` first (worktrees don't share it).

- [ ] **Step 2: Confirm the bundle contains the new blocks**

Run: `grep -c "woi/page-break\|{{billing_address}}\|woi-spacer" assets/js/block-editor/index.js`
Expected: a non-zero count (the minified bundle contains the new block names / token / class — proves they were compiled in, not tree-shaken).

- [ ] **Step 3: Read the TRUE current version from origin/master (coordination)**

Run: `git fetch origin && git show origin/master:woocommerce-orders-invoice-pdf.php | grep -m1 "Version:"`
Note the value (e.g. `1.5.2`). The new version is the next patch above whatever this prints — do NOT assume; another instance may have advanced it.

- [ ] **Step 4: Bump the version in both places**

In `woocommerce-orders-invoice-pdf.php`, set line 6 (`* Version:`) and line 24 (`public string $version`) to the next patch above the value Step 3 printed (e.g. if Step 3 shows `1.5.2`, use `1.5.3`; if it shows `1.5.4`, use `1.5.5`). Both lines get the SAME value.

- [ ] **Step 5: Commit**

```bash
git add assets/js/block-editor woocommerce-orders-invoice-pdf.php
git commit -m "build(visual): rebuild block bundle with full block set; bump version"
```

> Update the `version-coordination` memory's "current released version" line after this branch is merged/pushed, so the next instance sees the new number.

---

## Task 4: Live acceptance (manual — user, requires deploy)

**Files:** none (verification only)

This task is NOT implementable in the dev environment — it needs a live WordPress+WooCommerce site and a deploy (manual `git pull` of the built assets). Hand it to the user.

- [ ] **Step 1: Deploy** the branch to the live site (manual git pull) so the rebuilt `assets/js/block-editor/index.js` is served (the version bump cache-busts it).

- [ ] **Step 2: Inserter check.** In wp-admin → PDF Invoices → **Block Editor**, open the inserter / `+` and confirm the **Invoice** category now lists all token blocks (Logo, Shop name, Shop address, Shop name (AR)/(AR address), TRN, Phone, Email, Document title (+AR), Invoice number, Date, Order number, Payment method, Billing address, Line items, Totals) plus Spacer, Divider, Heading, Page break, Text.

- [ ] **Step 3: Compose + save.** Build a representative invoice: Heading, Shop name + TRN, a Spacer, Line items, Totals, a Page break, then Billing address. Click **Save**; confirm "Saved."

- [ ] **Step 4: Render.** Set **PDF source → Block editor**, ensure **Visual template (invoice)** is ON in Invoice Settings, generate a real-order invoice, and rasterize with PyMuPDF (see `rendering-pdfs-for-verification`). Confirm: tokens resolved (no raw `{{…}}`), the line-items + totals tables render, the spacer adds vertical gap, and the page break starts a new page. Arabic intact (mPDF).

- [ ] **Step 5: Switch-back.** Flip **PDF source → GrapesJS**, regenerate, confirm the GrapesJS design returns — proving GrapesJS is still untouched.

Expected: full block palette available; a block-authored invoice renders correctly through mPDF; switching sources works both ways.

---

## Self-Review

**Spec coverage (Slice 2 Part A scope):**
- All 17 token blocks → Task 1 (14 added to the existing 3). ✓
- Static layout blocks: Spacer, Divider, Heading, Page Break → Task 2, emitting the exact `woi-spacer`/`woi-pagebreak`/`<hr>`/`<h2>` markup the shared CSS targets. ✓
- Editable text block → already shipped in Slice 1 (`woi/text`); not re-added. ✓
- Server + client registration in lockstep → Tasks 1–2 update both `Blocks.php::NAMES` and the JS factory/registrar. ✓
- Build + cache-bust version bump (coordination-safe) → Task 3. ✓
- Composition blocks (Columns/Column InnerBlocks, Header Row) → **explicitly deferred to Slice 2 Part B** (separate plan); they are a distinct nested-block subsystem. ✓ (intentional scope boundary, stated up front)
- Live acceptance → Task 4 (user). ✓

**Placeholder scan:** None. Every code step contains the complete content; the only "approximate" element is the version *number*, which is intentionally resolved at execution time from `origin/master` (Task 3 Step 3) because it is a concurrently-mutated shared resource — the procedure is exact even though the literal differs per run.

**Type/name consistency:** Block names are identical between the JS `TOKENS`/`registerLayoutBlocks` and the server `NAMES` array: `woi/{logo,shop-name,shop-address,shop-name-ar,shop-address-ar,trn,shop-phone,shop-email,document-title,document-title-ar,invoice-number,invoice-date,order-number,payment-method,billing-address,line-items,totals}` (tokens) and `woi/{text,spacer,divider,heading,page-break}` (text+layout). `registerLayoutBlocks` is defined in `layout.js` and imported/called in `index.js`. The token factory `registerTokenBlocks` is unchanged and consumes the extended `TOKENS` array. Layout `save()` classes (`woi-spacer`, `woi-pagebreak`) match `templates/_visual/visual-document.css`. ✓
