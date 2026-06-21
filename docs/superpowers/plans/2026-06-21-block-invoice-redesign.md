# Block Invoice Template Redesign — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Re-skin the Gutenberg Block Editor admin screen and redesign the server-rendered bilingual UAE A4 tax-invoice PDF to match the high-fidelity handoff in `docs/design_handoff_block_invoice_redesign/`, using the existing stack (no React-from-CDN, no flex/grid in mPDF).

**Architecture:** Two surfaces. (1) **Editor chrome** = `@wordpress/block-editor` + `@wordpress/components` in `src/block-editor/*`, built by `wp-scripts` to `assets/js/block-editor/index.js`; restyled via the runtime-injected `canvasStyles.js` plus a new screen-scoped stylesheet. (2) **PDF document** = block markup → `{{tokens}}` (`includes/Visual/TemplateTokens.php`) → server HTML → mPDF, styled by `templates/_visual/visual-document.css` (the PRIMARY redesign target; shared by the live HTML preview via `woi_pdf_visual_document_css()`). Author "tweaks" map onto a `--accent` CSS var + `data-*` attributes on the document root and onto block attributes / template options.

**Tech Stack:** WordPress Gutenberg (`@wordpress/scripts ^30`), PHP 7.4+/WooCommerce, mPDF (Strauss-vendored `\WOI\PDF\Vendor\Mpdf\Mpdf`), `mpdf/qrcode` (already vendored), PHPUnit (Patchwork-free seams preferred).

## Global Constraints

- **Conservative, customs-/accountant-safe bilingual EN + Arabic (RTL) A4 portrait invoice.** Preserve the 5% VAT line and supplier+recipient TRN placement.
- **mPDF CSS subset only** in `visual-document.css`: NO flex/grid. Use tables, block/inline, absolute `mm` widths, and real `<br>` for stacking. Keep the thumbnail fix (`td.thumbnail` 15mm col / `img` 13mm `!important`) and the `<br>` bilingual label pairs (`.woi-lbl-primary` / `.woi-lbl-secondary`).
- **Keep all existing chrome wiring:** `saveBlocks`, `setActiveSource`, `historyReducer`, `PreviewPanel`/`pdfPreview`, `OrderPicker`, `BlockInspector`, `InterfaceSkeleton`, the `wp-block-editor` style enqueue (toolbar must stay horizontal). Do NOT replace Gutenberg.
- **Fonts:** keep `dejavusans` base + `xbriyaz`/`lateef` Arabic (mPDF-safe). Register ONE monospace face for tabular figures/SKU. Do NOT introduce Noto Sans Arabic (crashed PHP 8.4).
- **Design tokens — Chrome:** bg `#ECEAE4`, surface `#FFFFFF`, line `#DEDAD1`, ink `#2A2722`, muted `#8A8378`, navy `#140858`, mat `#B6B0A4`, radius 6–7px, primary shadow `0 8px 34px rgba(0,0,0,.24)`, controls 32px.
- **Design tokens — Document:** ink `#1C1A17`, accent/navy `#140858` (alt red `#9E0A0E`, mono `#3A3A3A`), muted `#8A8378`, hairline `#D9D4C9`, strong rule `#B6AFA1`, header fill `#F6F3EC`, grand-total/section rules 1.5–2px in accent. Title 25px, company name 13–14px, body 10.5–11px, captions 8.5–9px, grand total 14px/800.
- **Cache-bust:** every JS/CSS change requires bumping `public string $version` in `woocommerce-orders-invoice-pdf.php` (drives `WOI_PDF_VERSION`). Current: `1.5.37`.
- **Source-of-truth visual spec** = the prototype CSS in `docs/design_handoff_block_invoice_redesign/Block Invoice Editor.html` (`.ed-*` chrome, `.inv-*`/`.inv__*` document) and the `*.jsx` structure. Recreate the *look*; do not paste prototype CSS verbatim — translate to mPDF-safe equivalents for the PDF and to the Gutenberg DOM for the chrome.
- **New settings require a flag:** Due Date and signature image are not currently in the block path. Before adding either setting/asset, STOP and confirm with the user (see Tasks 9 & 11). Bank (WooCommerce BACS), Ship To (order shipping), recipient TRN (`woi_pdf_get_recipient_trn()`), and QR (`mpdf/qrcode`) reuse existing data.
- **Verification gate (every task that touches built assets or PHP):** `npm run build` succeeds; `php vendor/bin/phpunit --no-coverage` stays green (run with `-d auto_prepend_file=tests/bootstrap.php` per the ABSPATH gotcha if invoked directly). Render a PDF on a real order for document-affecting tasks.

---

## File Structure

**Editor chrome (built by wp-scripts):**
- `src/block-editor/index.js` — shell: toolbar markup (`woi-block-header`), TabPanel (Document/Block), order-context chip. *Modify.*
- `src/block-editor/canvas/canvasStyles.js` — runtime-injected chrome CSS (the main restyle surface for skeleton layout, panels, canvas mat). *Modify.*
- `src/block-editor/OrderPicker.js` — expose order# + customer name for the toolbar chip (already in preview store via `getOrderLabel`/`getOrderId`). *Read; maybe minor.*
- `src/block-editor/blocks/*.js`, `src/block-editor/appearance.js` — author-option attributes for tweaks (accent/density/letterhead/thumbnails/Arabic/font). *Modify.*
- `includes/Visual/BlockEditorPage.php` — `render_page()` heading/description copy; `enqueue()` add a screen-scoped stylesheet. *Modify.*
- `assets/css/block-editor-shell.css` — NEW screen-scoped chrome stylesheet (cleaner than ballooning canvasStyles.js). *Create.*

**PDF document (server-rendered):**
- `templates/_visual/visual-document.css` — PRIMARY redesign target. *Rewrite/extend.*
- `includes/Visual/TemplateTokens.php` — add tokens: `{{shipping_address}}`, `{{recipient_trn}}`, `{{bank_details}}`, `{{qr_code}}`, `{{contact_strip}}`, footer/signature tokens as needed; keep bilingual `<br>` pairs. *Modify.*
- `assets/visual-editor/starter-invoice.html` — new section order/structure (letterhead, contact strip, title+meta, parties, items, totals, bank+terms, signature/stamp/QR, footer). *Rewrite.*
- `templates/_visual/visual-document-wrapper.php` — usually unchanged (injects CSS). *Read.*
- `includes/Bilingual/dictionary/ar.php`, `BilingualEngine.php` — add any missing AR keys (ship_to, bank labels, due_date, amount-in-words caption, footer). *Modify.*
- `includes/Makers/MpdfMaker.php` — register the monospace font family + `data-accent`/font config hooks if needed. *Modify.*
- `templates/Standard UAE Tax Invoice/style.css` + `invoice.php` — keep classic path in visual parity if it ships. *Optional/parity.*

**Tests:**
- `tests/` (PHPUnit) — token-map additions render expected markup (use the existing seam pattern in `TemplateTokens`); bilingual pair stacking; QR token returns an `<img>`/SVG. History reducer + chrome are not unit-tested (visual).

---

## Phase A — Editor chrome (TODO §1–5)

### Task 1: Page shell copy + screen-scoped stylesheet scaffold (TODO §5)

**Files:**
- Modify: `includes/Visual/BlockEditorPage.php` (`render_page()`, `enqueue()`)
- Create: `assets/css/block-editor-shell.css`
- Modify: `woocommerce-orders-invoice-pdf.php` (version bump)

**Interfaces:**
- Produces: stylesheet handle `woi-block-editor-shell` enqueued only on `woi-pdf-blocks`, depending on `wp-block-editor`; class hooks the later tasks restyle.

- [ ] **Step 1:** In `render_page()`, set `<h1>Block Invoice Template</h1>` and the one-line description: "Design the invoice with content blocks. Set it as the active template source to render the PDF from this design." (match prototype `.ed-head`). Wrap heading block in `.woi-block-head` for styling.
- [ ] **Step 2:** Create `assets/css/block-editor-shell.css` with the chrome design-token `:root` vars (scoped under `.woi-block-interface-wrap`/page wrap) and empty section comments for toolbar/left-panel/inspector/canvas (filled by later tasks).
- [ ] **Step 3:** In `enqueue()`, `wp_enqueue_style('woi-block-editor-shell', plugins_url('assets/css/block-editor-shell.css', WOI_PDF_PLUGIN_FILE), ['wp-block-editor'], WOI_PDF_VERSION)`.
- [ ] **Step 4:** Bump `$version` in `woocommerce-orders-invoice-pdf.php`.
- [ ] **Step 5:** `npm run build` (no JS change yet, sanity) and load the screen; confirm heading copy + stylesheet present (DevTools). Commit.

### Task 2: Toolbar restyle + order-context chip (TODO §1)

**Files:**
- Modify: `src/block-editor/index.js` (header markup), `assets/css/block-editor-shell.css`

**Interfaces:**
- Consumes: `getOrderLabel()`/`getOrderId()` from `previewStore` (already selected in OrderPicker/PreviewPanel).

- [ ] **Step 1:** Group the left icon buttons (Add block / Undo / Redo / List view) inside `<div className="woi-tb-grp woi-tb-grp--left">`; right group (Render PDF secondary, Save primary navy, Fullscreen, Settings cog) inside `woi-tb-grp--right`.
- [ ] **Step 2:** Add a centered order-context chip `<div className="woi-tb-doc">`: a navy pill `<span className="woi-tb-docno">#{orderId}</span>` (navy `#140858` on `#EEEBF5`) + truncated `<span className="woi-tb-docname">{customerName}</span>`, driven from the preview store's order label (parse number/name from `getOrderLabel()`). When no order is selected, hide the chip.
- [ ] **Step 3:** In `block-editor-shell.css`, style `.woi-block-header` (sticky, surface bg, top/bottom 1px `#DEDAD1`), `.woi-tb-grp`, the icon buttons (32px, radius 6px, hover `#ECEAE4`), `.woi-tb-docno`, `.woi-tb-docname` (ellipsis, max-width 360px), and the Save/Render buttons (navy solid / ghost navy border).
- [ ] **Step 4:** Keep the `wp-block-editor` enqueue (verify toolbar stays horizontal). Bump version. `npm run build`.
- [ ] **Step 5:** Load screen, select an order in OrderPicker → chip shows `#237 — Customer`. Toolbar horizontal. Commit.

### Task 3: Left "Blocks" panel as styled outline (TODO §2)

**Files:**
- Modify: `src/block-editor/index.js` (ListView container), `assets/css/block-editor-shell.css`

- [ ] **Step 1:** Confirm the secondary sidebar (`woi-block-listview` → `<ListView/>`) is the block outline; ensure it can be opened by default OR keep the List view toggle. Wrap with `woi-block-listview` (exists).
- [ ] **Step 2:** Style the ListView rows to the prototype outline: 262px column width on the secondary sidebar (`.interface-interface-skeleton__secondary-sidebar` flex-basis 262px), rows = icon + label, hover `#ECEAE4`, selected `#EEEBF5` with navy icon. (Gutenberg ListView shows block names; the sub-caption from the prototype is cosmetic — only add if trivial via block `description`.)
- [ ] **Step 3:** Verify selecting a row selects the block in the canvas (Gutenberg wires this already). Bump version, build, load. Commit.

### Task 4: Canvas mat + selection outline (TODO §3)

**Files:**
- Modify: `src/block-editor/canvas/canvasStyles.js`

- [ ] **Step 1:** Change `.woi-canvas-scroll` stage background from `#525659` to the mat `#B6B0A4`; center the A4 page; keep fit-to-width. Page shadow `0 8px 34px rgba(0,0,0,.24)`.
- [ ] **Step 2:** Restyle selected-block outline to a 1.5px accent (`#140858`) ring + faint `rgba(20,8,88,.025)` tint; hover = lighter ring. (Inside the BlockCanvas iframe `previewCss`/shim — target `.is-selected`/Gutenberg's `.is-selected` block wrapper, or inject into the shim CSS.)
- [ ] **Step 3:** Bump version, build, load; confirm mat color + selection ring. Commit.

### Task 5: Inspector sidebar restyle (TODO §4)

**Files:**
- Modify: `src/block-editor/index.js` (TabPanel + Document panel), `assets/css/block-editor-shell.css`

- [ ] **Step 1:** Restyle `.woi-block-sidebar-tabs` tabs: active = navy text + 2px navy underline (`.components-tab-panel__tabs-item.is-active`).
- [ ] **Step 2:** Document tab content (`woi-block-document-panel`): keep the `#woi-pdf-source` select (GrapesJS / Block editor → `setActiveSource`); add read-styled rows for Page size (A4 · Portrait), Localisation (Arabic RTL, AED), and a "Visual template" note. Style field rows `.insp-field` and any switches `.insp-switch` to match.
- [ ] **Step 3:** Block tab: keep `<BlockInspector/>`; add CSS to align its field rows/switches to the prototype look (no structural change).
- [ ] **Step 4:** Bump version, build, load; confirm tab styling + Document panel. Commit.

---

## Phase B — PDF document (TODO §6–8)

### Task 6: Accent variable + base document type system (TODO §6 part 1)

**Files:**
- Modify: `templates/_visual/visual-document.css`

- [ ] **Step 1:** Add a top-of-file token layer. mPDF supports CSS variables poorly; instead define the accent as a literal `#140858` and centralize via a single, easily-swapped value. Wire data-attribute variants on `body`: `body[data-accent="red"]` overrides rule/heading colors to `#9E0A0E`, `body[data-accent="mono"]` to `#3A3A3A`. (The document wrapper must emit `data-accent`, `data-density`, `data-header`, `data-thumbs`, `data-arabic` on `<body>` — see Task 13.)
- [ ] **Step 2:** Set base body type: keep `dejavusans`; add `.mono`/figure cells to the registered monospace family (Task 12). Body 10.5–11pt, ink `#1C1A17`, muted `#8A8378`.
- [ ] **Step 3:** Render a current invoice PDF to confirm no regression before adding sections. Commit.

### Task 7: Letterhead + contact strip (TODO §6 letterhead)

**Files:**
- Modify: `templates/_visual/visual-document.css`, `assets/visual-editor/starter-invoice.html`, `includes/Visual/TemplateTokens.php`

- [ ] **Step 1:** Letterhead = 3-column table (EN | mark | AR-RTL). Center variant (logo centered) and left variant via `body[data-header="left"]`. Use a `<table class="woi-letterhead">` with fixed `td` widths (mm), `vertical-align:top`. Company name navy 13–14px; address lines 10.5px.
- [ ] **Step 2:** Contact strip = a full-width row below letterhead with `border-top:1.5px solid <accent>; border-bottom:1px solid #D9D4C9`; three items TRN / Tel / Email, key in muted caps + value in mono. Add a `{{contact_strip}}` token OR inline in starter using existing `{{trn}}`, `{{shop_phone}}`, `{{shop_email}}`.
- [ ] **Step 3:** Update `starter-invoice.html` letterhead section accordingly. Build not required (server template); render PDF. Commit.

### Task 8: Title + meta block (TODO §6 title)

**Files:**
- Modify: `templates/_visual/visual-document.css`, `assets/visual-editor/starter-invoice.html`

- [ ] **Step 1:** Replace `.woi-doc-title` centered title with a title-bar: left `<div class="woi-title">` big "TAX INVOICE" 25px accent + Arabic subtitle 15px muted (`{{document_title}}` / `{{document_title_ar}}`); right `<table class="woi-meta">` right-aligned meta (Invoice No., Order No., Issue Date, Due Date) with tabular mono values. Layout via a 2-cell `<table>` (mPDF-safe), not flex.
- [ ] **Step 2:** Meta `th` = muted uppercase caption with bilingual `<br>` pair; `td` = mono, right-aligned. Issue Date = `{{invoice_date}}`; Due Date token handled in Task 9 (flag) — until confirmed, omit the Due Date row.
- [ ] **Step 3:** Update starter; render PDF. Commit.

### Task 9: Due Date (NEW SETTING — FLAG FIRST)

**Files:** TBD pending confirmation.

- [ ] **Step 1:** STOP. Confirm with user: add a "Payment terms (days)" setting → Due Date = invoice date + N days, with a `{{due_date}}` token? Or omit Due Date row entirely? Do not implement until confirmed.
- [ ] **Step 2 (if approved):** Add the setting + `{{due_date}}` token + AR dictionary key `due_date`; add the meta row. Render PDF. Commit.

### Task 10: Bill To / Ship To party cards + recipient TRN (TODO §6 parties)

**Files:**
- Modify: `templates/_visual/visual-document.css`, `assets/visual-editor/starter-invoice.html`, `includes/Visual/TemplateTokens.php`, `includes/Bilingual/dictionary/ar.php`

**Interfaces:**
- Produces: tokens `{{shipping_address}}` (from `$document` order shipping), `{{recipient_trn}}` (from `woi_pdf_get_recipient_trn($order)`).

- [ ] **Step 1 (test):** Add a PHPUnit test asserting `TemplateTokens::map()` includes `{{shipping_address}}` and `{{recipient_trn}}` keys (extend existing token tests with a stub document exposing `get_shipping_address()` and an order). Run → fails.
- [ ] **Step 2:** Add the two tokens to `map()`: `{{shipping_address}}` = `wp_kses_post( $document->get_shipping_address() )`; `{{recipient_trn}}` = `esc_html( woi_pdf_get_recipient_trn( $document->get_order() ) )` (guard for stub). Run test → passes.
- [ ] **Step 3:** Two bordered cards as a 2-cell `<table class="woi-parties">`: each `td.woi-party` has `border:1px solid #D9D4C9; border-top:2px solid <accent>`; label (accent caps, bilingual), name bold, address lines, TRN muted. Bill To shows `{{recipient_trn}}`; Ship To shows `{{shipping_address}}`.
- [ ] **Step 4:** Add AR dictionary keys: `ship_to` (الشحن إلى), `bill_to` (فاتورة إلى) if not present. Update starter. Render PDF + phpunit green. Commit.

### Task 11: Signature / stamp / QR (TODO §6 signature; QR real)

**Files:**
- Modify: `templates/_visual/visual-document.css`, `assets/visual-editor/starter-invoice.html`, `includes/Visual/TemplateTokens.php`

**Interfaces:**
- Produces: `{{qr_code}}` token returning an `<img>` (data-URI) or SVG from `mpdf/qrcode`.

- [ ] **Step 1:** QR — add a `{{qr_code}}` token: use the vendored `mpdf/qrcode` (`\WOI\PDF\Vendor\Mpdf\QrCode\QrCode` + `Output\Svg` or `Png`) with a verify payload (order URL / FTA-style string). Return an inline `<img src="data:image/png;base64,...">` sized ~22mm. Guard with try/catch → '' on failure.
- [ ] **Step 1b (test):** PHPUnit: `{{qr_code}}` value contains `data:image` (or `<svg`). Run → fail → implement → pass.
- [ ] **Step 2:** 3-column `<table class="woi-sign">`: QR slot (left) + caption "Scan to verify" bilingual; dashed stamp ring (center) — `.woi-stamp-ring` 74px square with `border:1.5px dashed #B6AFA1; border-radius:50%` (mPDF supports border-radius on block boxes; verify, fallback to a square dashed box if it renders poorly); signature line (right) — bottom-bordered empty box + "Authorised Signatory" bilingual + "For {shop_name}".
- [ ] **Step 3 (signature image — FLAG):** If the user wants a real signature/stamp image (not just a line), confirm adding a settings image upload + token. Until confirmed, ship the drawn line + dashed ring only.
- [ ] **Step 4:** Update starter; render PDF; phpunit green. Commit.

### Task 12: Monospace font for figures (TODO §8 fonts)

**Files:**
- Modify: `includes/Makers/MpdfMaker.php`, `assets/fonts/` (add a mono `.ttf` if not vendored), `templates/_visual/visual-document.css`

- [ ] **Step 1:** Pick an mPDF-bundled monospace (mPDF ships `dejavusansmono`) — prefer it to avoid embedding new files. Map `.mono`, meta/totals/figure `td`s, SKU, IBAN, TRN values to `font-family:"dejavusansmono"`.
- [ ] **Step 2:** If `dejavusansmono` is available via the vendored mPDF, no font registration is needed (confirm by rendering). Only add a `.ttf`/`.ufm` + `fontdata` via the `woi_pdf_mpdf_config` filter if a specific mono is required (flag if so).
- [ ] **Step 3:** Render PDF; confirm tabular figures use mono. Commit.

### Task 13: Bank & terms + totals + footer + body data-attrs (TODO §6 totals/bank/footer)

**Files:**
- Modify: `templates/_visual/visual-document.css`, `assets/visual-editor/starter-invoice.html`, `includes/Visual/TemplateTokens.php`, `templates/_visual/visual-document-wrapper.php`, `includes/Bilingual/dictionary/ar.php`

**Interfaces:**
- Produces: `{{bank_details}}` token (from WooCommerce BACS accounts).

- [ ] **Step 1:** Lower section = 2-cell `<table class="woi-lower">`: left = `{{bank_details}}` table (Bank, Account Name, IBAN, Account No., SWIFT — mono values) + payment-terms paragraph (EN + AR); right = `table.totals-table` restyle.
- [ ] **Step 1b (test):** `{{bank_details}}` returns the BACS account markup (test via a stub/filtered accounts list). Implement from the existing BACS reader (`woi-pdf-functions.php:2619` pattern). Fail→pass.
- [ ] **Step 2:** Totals restyle: Subtotal / VAT (5%) / Shipping rows hairline `#D9D4C9`; `tr.grand-total` → `Total (AED)` bold 14px accent with top 1.5px + bottom 2px accent rules; add "Amount in words" caption row beneath. Preserve the existing VAT row (`type==='vat'`, secondary AR injected by BilingualEngine).
- [ ] **Step 3:** Footer = centered muted line (company · TRN · web · `Page {PAGENO} of {nbpg}`) via mPDF `<htmlpagefooter>` or a bottom `.woi-footer` block. Bilingual page label.
- [ ] **Step 4:** Update `visual-document-wrapper.php` to emit `<body data-accent="..." data-density="..." data-header="..." data-thumbs="..." data-arabic="...">` from document options (defaults: navy/comfortable/center/on/on).
- [ ] **Step 5:** Add AR keys: bank labels, payment_terms, amount_in_words, footer page. Update starter. Render PDF; phpunit green. Commit.

### Task 14: Line-items table redesign (TODO §6 line items)

**Files:**
- Modify: `templates/_visual/visual-document.css`

- [ ] **Step 1:** `table.order-details thead th` = soft `#F6F3EC` fill bounded top+bottom by 1.5px accent rules; uppercase 9px; bilingual EN/AR stacked via existing `<br>` pairs (already emitted by `TemplateTokens::render_line_items`).
- [ ] **Step 2:** `tbody td` = 0.5pt hairline `#D9D4C9` bottom borders (remove the full `0.5pt solid #000` box grid); last row heavier `1.5px #B6AFA1`. Keep thumbnail 15mm/13mm fix. SKU + figures right-aligned `.num`/mono + tabular.
- [ ] **Step 3:** `body[data-density="compact"]` reduces `td` padding. `body[data-thumbs="off"]` hides `.thumbnail` column.
- [ ] **Step 4:** Render PDF; confirm header fill/rules, hairlines, thumbnails contained, Arabic RTL shapes. Commit.

---

## Phase C — Author options (tweaks as real options) (TODO §9)

### Task 15: Surface tweaks as block attributes / template options

**Files:**
- Modify: `src/block-editor/blocks/*.js`, `src/block-editor/appearance.js`, `includes/Visual/...` (option persistence), `templates/_visual/visual-document-wrapper.php`

- [ ] **Step 1:** Document-level options (accent, density, letterhead center/left, Arabic on/off, font) → persist as template options read by the wrapper to set `<body data-*>`. Surface in the Document tab inspector (Task 5) as real controls wired through the existing options save path (mirror `setActiveSource`).
- [ ] **Step 2:** Thumbnails on/off → line-items block attribute toggling the `.thumbnail` column (or a document option `data-thumbs`). Wire in the Block inspector for the line-items/token block.
- [ ] **Step 3:** Arabic on/off → `BilingualEngine` `enable_second_language` (existing setting) reflected in `data-arabic`; ensure `.woi-lbl-secondary` + RTL spans gate off.
- [ ] **Step 4:** Build; toggle each option; render PDF to confirm each maps correctly. Bump version. phpunit green. Commit.

---

## Phase D — Assets, parity, verification (TODO §8, §10)

### Task 16: Milano logo print-safe asset

**Files:**
- Create: `assets/images/milano-mark.*`; Modify: logo token wiring if needed.

- [ ] **Step 1:** Export the Milano mark (from `logo-data.js` vector) to a print-safe raster/SVG under `assets/images/`. mPDF handles PNG reliably; SVG support is partial — prefer a high-res PNG. Wire via the existing `{{logo}}` / `.woi-img-sized` path (logo is a settings upload today; only add a bundled default if the user wants the Milano mark shipped — FLAG if it changes default branding).
- [ ] **Step 2:** Render PDF; confirm logo crisp at letterhead size. Commit.

### Task 17: Classic template parity (optional, TODO §7)

- [ ] **Step 1:** If the classic `templates/Standard UAE Tax Invoice/*` path still ships, mirror the accent/rules/totals styling in its `style.css` for visual parity. If only the visual/block path is active, note it and skip. Confirm with user which paths ship.

### Task 18: Final verification (TODO §10)

- [ ] **Step 1:** Editor screen matches prototype at common widths; toolbar horizontal; selection highlight correct; order chip populates.
- [ ] **Step 2:** Render PDF on a real order → A4 bilingual output matches the document reference (letterhead, contact strip, title+meta, parties+recipient TRN, items with header fill/hairlines/thumbnails, totals with 5% VAT + grand-total rules, bank+terms, signature/stamp/QR, footer). Arabic shapes RTL; tabular figures mono.
- [ ] **Step 3:** `composer install && php vendor/bin/phpunit --no-coverage` → green.
- [ ] **Step 4:** Final version bump + commit. Use `superpowers:finishing-a-development-branch`.

---

## Self-Review notes
- **Spec coverage:** TODO §1→T2, §2→T3, §3→T4, §4→T5, §5→T1, §6→T6-8,10,11,13,14, §7→T7-13/T17, §8→T12,T16, §9→T15, §10→T18. All covered.
- **New-setting flags:** Due Date (T9), signature image (T11 step 3), bundled logo default (T16) — each STOPs for user confirmation before adding.
- **mPDF risks to validate at render time:** border-radius on the stamp ring (T11), CSS variables (avoided — using `data-*` attribute overrides instead, T6/T13), `dejavusansmono` availability (T12), `<htmlpagefooter>` page numbers (T13).
