# Block Styling — Slice 3: Logo / Image Sizing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the user size the logo block (`woi/logo`) — a width control (mm) that scales the server-rendered logo image, overriding its default 3cm height cap.

**Architecture:** The `{{logo}}` token renders into a wrapper `<div>` whose content is a server-injected `<img style="max-height:3cm">` (the `constrain_header_logo_height` filter). The token factory (`token.js`) gains an `image` flag on the logo entry; when its new `imgWidth` attribute is set, `save()` emits the wrapper with `class="woi-img-sized"` + inline `width:Nmm`, and a new rule in `templates/_visual/visual-document.css` (`.woi-img-sized img{width:100%;height:auto;max-height:none!important}`) makes the server `<img>` fill the sized wrapper (the `!important` overrides the inline 3cm cap; mPDF supports `!important`). Unset → current behaviour, byte-identical. Only the logo token is affected; all other tokens are untouched.

**Tech Stack:** `@wordpress/block-editor`, `@wordpress/components` (`PanelBody`, `RangeControl`), the shared `appearance.js`, mPDF + `visual-document.css`, jest, debug-Chrome live harness (incl. PyMuPDF PDF rasterise).

## Global Constraints

- Spec: `docs/superpowers/specs/2026-06-21-block-styling-design.md` (Slice 3).
- **Inline styles + one scoped CSS rule**; `width` on a div and the `.woi-img-sized img` rule are kses-safe (`div` carries `style`+`class` via `$common`; the CSS rule lives in `visual-document.css`, not in saved block HTML) → **NO kses/allowlist change**.
- **Back-compat invariant:** `imgWidth` defaults `0`; the logo `save()` emits the SAME `<div>{{logo}}</div>` (via `appearanceProps`) when `imgWidth:0`; all non-logo tokens are unchanged. No deprecation.
- `visual-document.css` is shared by the PDF (mPDF) AND the block canvas preview (via `woi_pdf_visual_document_css()` → `previewCss`), so the one rule covers both contexts.
- mPDF: honours `width` on the wrapper div + `width:100%`/`max-height:none!important` on the descendant img. The `!important` is REQUIRED to beat the inline `max-height:3cm` the server puts on the logo img.
- Scope: WIDTH only (height follows aspect ratio — the right model for a logo; explicit height is intentionally omitted, YAGNI).
- Build with `npm run build`; `output.clean:false` stays; sibling assets intact. **Worktree needs a REAL `node_modules` (`npm install`)**. Controller provisions before dispatch.
- Version bump BOTH lines in `woocommerce-orders-invoice-pdf.php` to **1.5.36** (origin/master is at 1.5.35).
- Run full `npm run test:unit` before commit (35 passing; no new test — block edit/save + CSS, like the prior structural slices). Work in a worktree; FF push to master; read true version from origin/master before bumping.

---

### Task 1: Logo width control + image-fill CSS

**Files:**
- Modify (full replacement of the file): `src/block-editor/blocks/token.js`
- Modify: `templates/_visual/visual-document.css` (append one rule)
- Version: `woocommerce-orders-invoice-pdf.php` (two lines → 1.5.36)
- Build artifact (committed): `assets/js/block-editor/*`

**Interfaces:**
- Consumes: `PanelBody`, `RangeControl` from `@wordpress/components`; the shared `appearance.js` helpers (already imported).
- Produces: `woi/logo` gains an `imgWidth` (mm) attribute + an "Image" inspector panel; `.woi-img-sized` markup contract honoured by the CSS rule.

- [ ] **Step 1: Replace `src/block-editor/blocks/token.js` entirely with this**

```jsx
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, RangeControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { safeHTML } from '@wordpress/dom';
import { STORE } from '../previewStore';
import { isHtmlToken, tokenValue } from '../tokenMerge';
import { APPEARANCE_ATTRS, appearanceStyle, appearanceProps, AppearancePanel, AppearanceToolbar } from '../appearance';

/**
 * Token blocks. Each is static: save() emits a fixed wrapper holding the literal
 * {{token}}; the real value is merged server-side at PDF time. The logo entry is
 * flagged `image: true` and gains a width control (mm) that sizes the
 * server-rendered logo via the .woi-img-sized CSS contract.
 */
const TOKENS = [
	{ name: 'woi/logo',              title: __( 'Logo image', 'woocommerce-orders-invoice-pdf' ),        token: '{{logo}}',              tag: 'div', preview: '[ logo image ]', image: true },
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

export function registerTokenBlocks() {
	TOKENS.forEach( ( { name, title, token, tag, preview, image } ) => {
		registerBlockType( name, {
			apiVersion: 2,
			title,
			category: 'woi-invoice',
			icon: 'media-document',
			attributes: {
				...APPEARANCE_ATTRS,
				...( image ? { imgWidth: { type: 'number', default: 0 } } : {} ),
			},
			supports: { html: false, reusable: false },
			edit( { attributes, setAttributes } ) {
				const Tag = tag;
				const tokens = useSelect( ( select ) => select( STORE ).getTokens(), [] );
				const value = tokenValue( token, tokens );
				const sized = image && attributes.imgWidth;
				const style = { ...appearanceStyle( attributes ), ...( sized ? { width: attributes.imgWidth + 'mm' } : {} ) };
				const className = [ value ? '' : 'woi-token-empty', sized ? 'woi-img-sized' : '' ].filter( Boolean ).join( ' ' ) || undefined;
				const blockProps = useBlockProps( { className, style } );
				const panel = (
					<InspectorControls>
						{ image ? (
							<PanelBody title={ __( 'Image', 'woocommerce-orders-invoice-pdf' ) }>
								<RangeControl
									label={ __( 'Logo width (mm) — 0 = default', 'woocommerce-orders-invoice-pdf' ) }
									value={ attributes.imgWidth || 0 }
									onChange={ ( v ) => setAttributes( { imgWidth: v || 0 } ) }
									min={ 0 }
									max={ 120 }
								/>
							</PanelBody>
						) : null }
						<AppearancePanel attributes={ attributes } setAttributes={ setAttributes } />
					</InspectorControls>
				);
				let inner;
				if ( ! value ) {
					// No order picked / token empty: show the friendly label so the
					// block stays visible and selectable.
					inner = <Tag { ...blockProps }>{ preview }</Tag>;
				} else if ( isHtmlToken( token ) ) {
					// HTML token (logo, billing address, line-items / totals tables).
					// safeHTML strips scripts / event-handler attributes / javascript:
					// URLs before injecting into the live admin DOM.
					inner = <Tag { ...blockProps } dangerouslySetInnerHTML={ { __html: safeHTML( value ) } } />;
				} else {
					inner = <Tag { ...blockProps }>{ value }</Tag>;
				}
				return (
					<>
						<AppearanceToolbar attributes={ attributes } setAttributes={ setAttributes } />
						{ panel }
						{ inner }
					</>
				);
			},
			save( { attributes } ) {
				const Tag = tag;
				// Sized logo: wrapper carries the width + the .woi-img-sized class that
				// the visual-document.css rule uses to fill the server <img>.
				if ( image && attributes.imgWidth ) {
					const style = { ...appearanceStyle( attributes ), width: attributes.imgWidth + 'mm' };
					return <Tag { ...useBlockProps.save( { className: 'woi-img-sized', style } ) }>{ token }</Tag>;
				}
				// Inner content is the literal token; merged at PDF render time.
				return <Tag { ...useBlockProps.save( appearanceProps( attributes ) ) }>{ token }</Tag>;
			},
		} );
	} );
}
```

- [ ] **Step 2: Append the image-fill rule to `visual-document.css`**

In `templates/_visual/visual-document.css`, add this rule (near the other `img` rules, e.g. after the `td.thumbnail img` rule):

```css
/* Block-editor sized logo: fill the wrapper's width and drop the default 3cm
   inline max-height cap so the chosen width controls the size. */
.woi-img-sized img { width: 100% !important; height: auto !important; max-height: none !important; }
```

- [ ] **Step 3: Bump version**

Set BOTH version lines in `woocommerce-orders-invoice-pdf.php` to `1.5.36`.

- [ ] **Step 4: Build**

Run: `npm run build`
Expected: success; sibling assets intact.

- [ ] **Step 5: Run unit tests**

Run: `npm run test:unit`
Expected: 35 passing, pristine (no test change).

- [ ] **Step 6: Commit**

```bash
git add src/block-editor/blocks/token.js templates/_visual/visual-document.css assets/js/block-editor woocommerce-orders-invoice-pdf.php
git commit -m "feat(block-editor): woi/logo width control (mm) sizing the server logo image via .woi-img-sized"
```

- [ ] **Step 7: Live acceptance (controller/user, post-deploy)**

After deploy, on the Block Invoice Template page:
1. Select the **Logo image** block → an **Image** panel with a **Logo width (mm)** control appears.
2. Set a width (e.g. 50mm) → the logo in the canvas resizes; set 0 → reverts to default (~3cm height).
3. With PDF source = Block editor + visual template on, render the PDF and **rasterise it (PyMuPDF)** — confirm the logo is rendered at the chosen width (and is NOT clipped to the old 3cm cap when sized larger).
4. An unsized logo and all other token blocks still serialise unchanged (no validation warning on reload).

---

## Self-Review

**Spec coverage (Slice 3):** `woi/logo` (image token) gains a width dimension control (Task 1 Step 1); the `.woi-img-sized img` CSS makes the server image fill the sized wrapper, overriding the 3cm inline cap (Step 2). Width-only by design (height follows aspect — documented). Covered.

**Placeholder scan:** No TBD/TODO; full token.js shown; exact CSS rule; live-acceptance includes a PDF rasterise check. Clean.

**Type consistency:** `image` flag destructured from the TOKENS entry; `imgWidth:{type:'number',default:0}` added only for image tokens; `save()` image branch mirrors the edit() `sized` logic (`appearanceStyle` + `width:Nmm` + `woi-img-sized`); unset path = the original `appearanceProps` branch (byte-identical). `PanelBody`/`RangeControl` imported from `@wordpress/components`.

**Risk (flagged for live):** the `!important` overrides the inline `max-height:3cm` on the server logo img. mPDF supports `!important`, but this is the one thing that needs the live PDF-rasterise check (Step 7.3) — if mPDF ignores it and the logo stays capped at 3cm when sized larger, escalate (e.g. a more specific selector or a server-side conditional on the max-height filter). The browser canvas preview honours `!important` reliably.

**Testing note:** block `edit()`/`save()` + a CSS rule — jest can't render them; the pure `appearanceStyle` reused here is already tested (35/35). Verification is build + live acceptance (incl. PDF rasterise) + the reviewer's back-compat check (unset logo + all other tokens → byte-identical `save()`), as the prior structural slices were done. No kses change.
