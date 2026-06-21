# TODO — Block Invoice Template redesign (editor chrome + PDF document)

> Implement inside the existing plugin stack. Editor chrome = WordPress Gutenberg
> (`@wordpress/block-editor`, `@wordpress/components`). PDF document = `templates/_visual/
> visual-document.css` + token markup, rendered by mPDF. Do NOT paste the HTML prototype in.
> Reference: `Block Invoice Editor.html` and the per-surface notes in `README.md`.

## 0. Setup & orientation
- [ ] Read `README.md` and open `Block Invoice Editor.html` to see the target look.
- [ ] Locate the two surfaces in code: `src/block-editor/` (chrome) and `templates/_visual/visual-document.css` (PDF).
- [ ] Confirm build pipeline for `src/block-editor/*` → `assets/js/block-editor/index.js` (wp-scripts). Note how to rebuild.

## 1. Editor chrome — toolbar (`src/block-editor/index.js`)
- [ ] Restyle `woi-block-header`: group icon buttons (Add / Undo / Redo / List view) at left.
- [ ] Add center **order-context chip**: order-number pill (navy `#140858` on `#EEEBF5`) + truncated customer name (drive from `OrderPicker` state).
- [ ] Right group: **Render PDF** (secondary), **Save** (primary navy), Fullscreen, Settings — match radius 6px, 32px controls.
- [ ] Keep `wp-block-editor` style enqueue (toolbar must stay horizontal — see `BlockEditorPage::enqueue()`).

## 2. Editor chrome — left "Blocks" panel
- [ ] Style the block list / `ListView` as the outline in the prototype: 262px column, rows = icon + label + sub-caption, hover `#ECEAE4`, active `#EEEBF5` with navy icon.
- [ ] Ensure selecting a row selects the block and reveals it in the canvas.

## 3. Editor chrome — canvas (`canvas/Canvas.js`, `canvas/canvasStyles.js`)
- [ ] Center the A4 page on a `#B6B0A4` mat with `0 8px 34px rgba(0,0,0,.24)` shadow; fit-to-width scaling.
- [ ] Selected-block outline = 1.5px accent ring + faint `rgba(20,8,88,.025)` tint; hover = lighter ring.

## 4. Editor chrome — inspector sidebar (`index.js` TabPanel)
- [ ] Restyle Document | Block tabs (active = navy text + 2px navy underline).
- [ ] **Document** tab: source select (GrapesJS / Block editor), page size (A4·Portrait), localisation (Arabic RTL, AED), "Visual template" toggle, helper note. Keep `setActiveSource` wiring.
- [ ] **Block** tab: keep `<BlockInspector />`; restyle field rows + switches to match (`insp-field`, `insp-switch`).

## 5. Editor chrome — page shell (`includes/Visual/BlockEditorPage.php`)
- [ ] Update `render_page()` heading/description to match copy ("Block Invoice Template" + one-liner).
- [ ] Add a redesign stylesheet handle (or extend `assets/css/admin-shell.css`) scoped to the block screen.

## 6. PDF document — CSS (`templates/_visual/visual-document.css`) — PRIMARY
- [ ] Introduce a single `--accent` (navy `#140858`; options red `#9E0A0E`, mono `#3A3A3A`) and apply to rules, headers, totals, section labels.
- [ ] **Letterhead**: 3-column EN | mark | AR (RTL) using a table layout (mPDF-safe, no flex); center + left variants. Contact strip with 1.5px accent top rule.
- [ ] **Title + meta**: large "TAX INVOICE" in accent + Arabic subtitle; right-aligned meta table, tabular figures.
- [ ] **Bill/Ship to**: two bordered cards, 2px accent top border, bilingual labels.
- [ ] **Line items** (`table.order-details`): header soft `#F6F3EC` fill bounded by 1.5px accent rules; 0.5pt hairlines; SKU/figures right-aligned & tabular; thumbnail column absolute ~15mm (image ~13mm) — keep the existing mm-width fix; bilingual headers stack EN over AR via real `<br>`.
- [ ] **Totals** (`table.totals-table`): Subtotal / VAT (5%) / Shipping / bold **Total (AED)** with accent top+bottom rules (`tr.grand-total`); amount-in-words line.
- [ ] **Bank & terms**: bank detail table + EN/AR terms paragraph.
- [ ] **Signature / stamp / QR**: signature line + caption; dashed stamp ring; QR slot.
- [ ] **Footer**: centered muted line (company · TRN · web · page).
- [ ] Verify everything in mPDF (no flex/grid; use tables, block/inline, mm widths, `<br>` for stacking).

## 7. PDF document — markup & tokens
- [ ] Update `assets/visual-editor/starter-invoice.html` (block starter) to the new section order/structure.
- [ ] Ensure bilingual label pairs render via `TemplateTokens.php` (`.woi-lbl-primary` / `.woi-lbl-secondary` + `<br>`), gated by the Arabic toggle / `BilingualEngine`.
- [ ] Keep classic PHP template (`templates/Standard UAE Tax Invoice/*`) visually in parity if both paths ship.

## 8. Assets & fonts
- [ ] Export the Milano logo (from `logo-data.js`) to a print-safe asset; wire the logo token / `.woi-img-sized`.
- [ ] If adopting the prototype type pairing, register a mono + Arabic face in `assets/fonts/` (`.ttf` + `.ufm`) via `MpdfMaker`/`FontSynchronizer`; otherwise map to existing DejaVu/OpenSans/RobotoSlab.
- [ ] Replace the placeholder QR with a real server-generated QR (verify/FTA payload).

## 9. Optional author options (only if surfaced)
- [ ] Thumbnails on/off → line-items block attribute (adds/removes `td.thumbnail`).
- [ ] Arabic on/off → `BilingualEngine`/document option.
- [ ] Accent color, letterhead center/left, table density, font → block attributes / template options + the `--accent`/font vars above.

## 10. Verify
- [ ] Editor screen matches prototype at common widths; toolbar stays horizontal; selection highlight correct.
- [ ] Render PDF on a real order → A4 bilingual output matches the document reference; thumbnails contained; Arabic shapes correctly RTL; totals rules and tabular figures correct.
- [ ] Run existing tests (`composer install && php vendor/bin/phpunit --no-coverage`) — green.
```
