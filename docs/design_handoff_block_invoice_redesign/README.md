# Handoff: Block Invoice Template — Editor + PDF Redesign

## Overview
This package redesigns the **Block Editor** screen of the *WooCommerce Orders Invoice PDF* plugin
(WordPress admin: **WooCommerce → Block Editor**, internal slug `woi-pdf-blocks`) **and** the
**A4 bilingual UAE Tax Invoice** the editor renders to PDF.

Two surfaces are redesigned together:
1. **Editor admin chrome** — the toolbar, left "Blocks" outline, center canvas, and right inspector
   that wrap the document while an admin designs it.
2. **The invoice document itself** — the actual PDF output: a conservative, customs-/accountant-safe
   bilingual (English + Arabic RTL) UAE tax invoice for *Milano Leather Trading LLC*.

The redesign is **conservative/corporate** by request: clean rules, restrained accent color, no
decorative gradients, tabular figures, and full bilingual labels.

## About the Design Files
The files in this bundle are **design references created in HTML/React (Babel-in-browser)** — a
prototype showing the intended look and behavior. **They are not production code to paste in.**
The task is to **recreate these designs inside the plugin's existing environment**:

- The **editor chrome** is built with **WordPress Gutenberg primitives** (`@wordpress/block-editor`,
  `@wordpress/components` `InterfaceSkeleton`, `BlockInspector`, `Inserter`, `ListView`). Re-skin and
  re-layout *that*, do not introduce React-from-CDN or a parallel UI framework.
- The **invoice document** is produced by block markup → tokens → server-rendered HTML, styled by
  **`templates/_visual/visual-document.css`** and rendered to PDF by **mPDF**. Recreate the document
  look by editing that CSS (and the starter block markup / `TemplateTokens.php`), **not** by shipping
  the prototype's CSS verbatim — mPDF supports a limited CSS subset (no flex/grid; use tables,
  block/inline, absolute mm widths, `<br>` for stacking).

## Fidelity
**High-fidelity.** Colors, type scale, spacing, table structure, and bilingual label placement are
final and intentional. Match them closely. Where a prototype technique is browser-only (flexbox,
web fonts, SVG QR), use the mPDF-safe equivalent noted under each section.

---

## Target files in the real codebase

### Editor chrome (React / Gutenberg — browser UI)
| Concern | File |
|---|---|
| Editor shell: header, sidebar tabs, content, listview | `src/block-editor/index.js` |
| Canvas wrapper + injected canvas CSS | `src/block-editor/canvas/Canvas.js`, `src/block-editor/canvas/canvasStyles.js` |
| PDF preview panel | `src/block-editor/PreviewPanel.js`, `src/block-editor/pdfPreview.js` |
| Order picker (toolbar doc context) | `src/block-editor/OrderPicker.js` |
| Block registrations (inserter "Invoice" group) | `src/block-editor/blocks/*.js` |
| Admin page shell + heading + enqueued core styles | `includes/Visual/BlockEditorPage.php` |
| Built bundle / handles | `assets/js/block-editor/index.js`, `assets/css/admin-shell.css` |

The shell currently renders via `InterfaceSkeleton` with a `header` (Inserter / Undo / Redo /
ListView / Save / Render PDF / OrderPicker / Fullscreen / Settings), a `sidebar` (`TabPanel` with
**Document** + **Block** tabs, the latter showing `<BlockInspector />`), `content` (BlockTools →
Inserter appender → `<Canvas />`), and an optional `secondarySidebar` list view. **Keep this
architecture** — restyle it; do not replace Gutenberg.

### Invoice document (server-rendered HTML → mPDF)
| Concern | File |
|---|---|
| PDF document CSS (primary redesign target) | `templates/_visual/visual-document.css` |
| Bilingual token markup (label pairs, `<br>` stacking) | `includes/Visual/TemplateTokens.php` |
| Starter block markup for the invoice | `assets/visual-editor/starter-invoice.html` |
| Document wrapper | `templates/_visual/visual-document-wrapper.php` |
| Bilingual EN/AR strings | `includes/Bilingual/BilingualEngine.php`, `includes/Bilingual/dictionary/*` |
| Classic PHP template parity (non-block path) | `templates/Standard UAE Tax Invoice/*` |

---

## Screens / Views

### 1. Editor admin screen (`woi-pdf-blocks`)
**Purpose:** an admin designs/reviews the invoice template, picks an order for preview, and renders a PDF.

**Layout (full-height `InterfaceSkeleton`):**
- **Global admin strip** (top, dark `#1A1714` / WP `#1d2327`): plugin brand at left, user at right. (Existing WP admin bar — leave as is; the prototype's strip is just a stand-in.)
- **Page heading**: `H1` "Block Invoice Template" + one-line description. Lives in `BlockEditorPage::render_page()`.
- **Toolbar** (`woi-block-header`, sticky): left group = Add block / Undo / Redo / List view icon buttons; center = order context chip (`#237` pill + customer name, truncated); right group = **Render PDF** (secondary), **Save** (primary), Fullscreen, Settings(cog) toggles.
- **Body grid** `262px | 1fr | 288px`:
  - **Left "Blocks" panel** — outline of document blocks (Letterhead, Title & meta, Bill/Ship to, Line items, Totals, Bank & terms, Signature/stamp/QR, Footer). Each row: block icon + label + sub-caption. Selecting highlights the block in the canvas. *(In the real app this is the Gutenberg `ListView` / block list; style rows to match.)*
  - **Center canvas** — gray mat `#B6B0A4`, the A4 page centered with `0 8px 34px rgba(0,0,0,.24)` shadow, scaled to fit width.
  - **Right inspector** — `TabPanel` Document | Block. Document tab: template source select (GrapesJS / Block editor), page size, localisation, "Visual template" toggle. Block tab: `<BlockInspector />` controls for the selected block.
- **Render PDF modal** — dark bar + scrollable A4 paper with a faint `SAMPLE` watermark (mirrors `PreviewWatermark.php`).

**Key colors (chrome):** surface `#FFFFFF`, app bg `#ECEAE4`, lines `#DEDAD1`, ink `#2A2722`, muted `#8A8378`, brand navy `#140858`, canvas mat `#B6B0A4`. Primary button = navy.

### 2. The Invoice document (A4 portrait, 794×1123 @96dpi ref)
Top-to-bottom blocks:

1. **Letterhead** — three columns: EN company block · centered Milano mark + wordmark · AR company block (RTL). Header layout has a **center** and **left** variant. Below it, a contact strip (TRN / Tel / Email) bounded by a 1.5px accent rule on top.
2. **Title + meta** — large "TAX INVOICE" (accent) with Arabic "فاتورة ضريبية" beneath; right-aligned meta table (Invoice No., Order No., Issue Date, Due Date) with tabular-figure values.
3. **Bill To / Ship To** — two bordered cards, 2px accent top border, bilingual labels, customer TRN.
4. **Line items table** — columns: Sr · *Thumbnail* · Barcode/SKU · Description (name + meta) · Qty · Rate · Tax % · Amount. Header row has soft `#F6F3EC` fill bounded top+bottom by 1.5px accent rules; body rows 1px hairline separators; last row a heavier rule. Bilingual column headers stack EN over AR.
5. **Totals** — right block: Subtotal, VAT (5%), Shipping, then a bold **Total (AED)** row bounded by accent rules; "Amount in words" line beneath.
6. **Bank & payment terms** — left block: bank table (Bank, Account Name, IBAN, Account No., SWIFT) + terms paragraph (EN + AR).
7. **Signature / stamp / QR** — QR placeholder (left), dashed "Company Stamp" ring (center), signature line + "Authorised Signatory" (right).
8. **Footer** — centered, muted: company · TRN · web · page number (bilingual).

---

## Interactions & Behavior
- **Block select** (left panel or click on canvas) → highlight block + switch inspector to **Block** tab. *(Gutenberg already wires selection; restyle the selected outline to a 1.5px accent ring + faint tint.)*
- **Render PDF** → existing `PreviewPanel.reveal()` + `render()` via `pdfPreview.js` (pdf.js). Keep; restyle the modal/panel chrome.
- **Save** → existing `saveBlocks(serialize(blocks))`. Keep.
- **Source select** (Document tab) → existing `setActiveSource('grapesjs' | 'blocks')`. Keep.
- **Fullscreen / Settings / List view** → existing toggles. Keep behavior.
- **Toolbar must stay horizontal** — `BlockEditorPage::enqueue()` already enqueues `wp-block-editor` for the flex toolbar layout; do not remove.

## State Management
No new state. Reuse the existing reducer/history (`history.js`), `store.js` (`saveBlocks`,
`setActiveSource`), and `previewStore.js`. The redesign is **presentational** for the chrome and a
**CSS/markup** change for the document. The prototype's "Tweaks" (below) are author preferences;
map any you choose to keep onto block attributes / template options rather than ad-hoc globals.

## Tweaks → real mapping (optional author options)
The prototype exposes live toggles. If you surface any, wire them properly:
| Tweak | Real home |
|---|---|
| Product thumbnails on/off | line-items block attribute → adds/removes `td.thumbnail` column |
| Arabic (bilingual) on/off | `BilingualEngine` / document option; gate the `.woi-lbl-secondary` + RTL spans |
| Accent color (navy / red / mono) | a single CSS var in `visual-document.css` (e.g. `--accent`) + chrome var |
| Letterhead center / left | letterhead block layout variation |
| Table density compact / comfortable | row padding modifier class on `.order-details` |
| Font (Grotesque / Serif / Mono) | mPDF-registered font family swap (see Fonts) |

## Design Tokens
**Chrome:** bg `#ECEAE4` · surface `#FFFFFF` · line `#DEDAD1` · ink `#2A2722` · muted `#8A8378` ·
navy `#140858` · mat `#B6B0A4` · radius 6–7px · primary shadow `0 8px 34px rgba(0,0,0,.24)`.
**Document:** ink `#1C1A17` · accent/navy `#140858` (alt red `#9E0A0E`, mono `#3A3A3A`) · muted
`#8A8378` · hairline `#D9D4C9` · strong rule `#B6AFA1` · header fill `#F6F3EC` · grand-total/section
rules 1.5–2px in accent.
**Type (prototype):** Archivo (body/head), Source Serif 4 (serif option), IBM Plex Mono (figures/SKU),
Noto Sans Arabic (RTL). Title 25px, company name 13–14px, body 10.5–11px, captions 8.5–9px,
grand total 14px/800. **In mPDF** these map to the plugin's registered families — DejaVu Sans is the
base; the secondary (Arabic) stack is registered by `MpdfMaker`. Use embedded `.ttf`/`.ufm` pairs in
`assets/fonts/` (OpenSans, RobotoSlab, Segoe present); add a mono + Arabic face there if you adopt the
exact prototype pairing.

## Assets
- **Milano Leather logo** — official vector paths in `logo-data.js` (drawn by `invoice-mark.jsx`). For the PDF, export to a print-safe asset under `assets/images/` (or keep the existing logo token).
- **Product thumbnails** — neutral SVG placeholders in the prototype; the real table pulls WooCommerce product images (`td.thumbnail img`, capped to ~13mm).
- **QR code** — prototype draws a deterministic *placeholder* matrix. Replace with a real QR (e.g. the FTA e-invoice/verify payload) generated server-side.

## Files in this bundle (design references)
- `Block Invoice Editor.html` — the full prototype (editor chrome + invoice + tweaks). Open this first.
- `editor-app.jsx` — editor chrome (toolbar, blocks panel, inspector, PDF modal, tweaks wiring).
- `invoice-doc.jsx` — the invoice document structure + QR/thumbnail placeholders + bilingual helper.
- `invoice-mark.jsx` — static Milano mark/wordmark from the official vector.
- `logo-data.js` — official Milano vector paths.
- `tweaks-panel.jsx` — the in-prototype tweak controls (author-preference demo only).
- `TODO.md` — the implementation checklist for Claude Code.
- `screenshots/` — rendered reference images (if included).

> Implement in the plugin's existing stack: **Gutenberg/`@wordpress/*`** for the editor chrome and
> **`visual-document.css` + token markup + mPDF** for the PDF. Do not ship the HTML prototype as-is.
