# Searchable order picker for the Visual Invoice editor

**Date:** 2026-06-20
**Status:** Approved (design)

## Problem

On the Visual Invoice Template editor screen the "Preview order" control is a
text input + **Find** button + a hidden native `<select>`. The select only
appears after a search, and each option shows only `#number — First Last`. The
user wants a searchable drop-down that lists orders with enough context to pick
the right one at a glance: customer/company name, order amount, quantity, order
date, and payment mode.

## Goals

- Replace the text-box-then-Find flow with a combobox: type to filter, pick from
  a panel.
- Show the **5 most recent** orders the moment the field is focused, so a recent
  order can be chosen with zero typing.
- Each row shows: name (company, falling back to first+last), total amount,
  quantity as **distinct line items / total units**, order date, and payment mode
  (human-readable title).
- Reuse the existing preview pipeline (`woiFetchOrderTokens` → Live HTML + PDF).

## Non-goals

- No new REST endpoint. The existing admin-ajax action
  `woi_pdf_preview_order_search` is extended.
- No change to how the preview renders tokens, HTML, or PDF.
- No change to the legacy settings-page order search behaviour (`admin.js`).
- No change to search matching logic (still order #, email, or name/company).

## Decisions (from brainstorming)

| Question | Decision |
|----------|----------|
| Dropdown style | Custom rich panel (multi-line rows), not native `<select>` or Select2 |
| Population / filter | 5 most recent on focus + live filter (debounced ~300ms); no Find button |
| Quantity meaning | **Both** — `N items / M units` (distinct lines / summed quantities) |
| Payment mode | Human-readable `get_payment_method_title()` |

## Design

### 1. Markup — `includes/Visual/VisualEditorPage.php`

The order bar (currently lines ~114–122) becomes a combobox:

- Keep the `Preview order:` label and the `#woi-order-current` read-out span.
- Keep `#woi-order-search` text input; its placeholder becomes a filter hint
  (e.g. "Search by order #, name, company or email").
- **Remove** the `#woi-order-search-btn` (Find) button and the native
  `#woi-order-results` `<select>`.
- Add a panel container positioned relative to the input:
  - `<div class="woi-order-combo">` wrapping the input and
  - `<ul id="woi-order-panel" class="woi-order-panel" role="listbox" hidden>`.
- Keep the existing `.woi-order-spinner` for loading feedback.

Each panel row is rendered client-side as:

```
<li role="option" data-order-id="237">
  <span class="woi-op-title">#237 — Nesto Hypermarket LLC</span>
  <span class="woi-op-meta">AED 1,250 · 2 items / 14 units</span>
  <span class="woi-op-meta">2026/06/17 · Bank transfer</span>
</li>
```

Row title prefers `billing_company`; falls back to `first + last`; falls back to
`(no name)`. Amount/date already arrive as HTML from the server (`wc_price`,
formatted date) and are inserted as-is into their own spans.

`woiVisual` localisation is unchanged except it no longer needs anything new —
`orderSearchAction`, `previewNonce`, `docType`, `ajaxUrl` all already exist.

### 2. Behaviour — `assets/visual-editor/app.js`

Replace the current `orderSearch()` / `bindOrderBar()` block with combobox logic:

- **`woiFetchOrders(term)`** — POSTs to `woiVisual.ajaxUrl` with
  `action=woiVisual.orderSearchAction`, `security=previewNonce`,
  `document_type=docType`, `search=term` (term may be empty). Returns the parsed
  `res.data` map (order-id → fields) or `{}`.
- **`woiRenderOrderPanel(data)`** — clears and rebuilds `#woi-order-panel` rows
  from the data map, shows/hides the panel, resets the active-row index.
- **Focus/open:** when the input gains focus and the panel is empty (or term is
  empty), fetch the 5 most recent (empty `term`) and render.
- **Input:** debounce ~300ms; fetch with the trimmed term and render. Empty term
  re-fetches recents.
- **Pick** (click a row, or Enter on the active row): read `data-order-id`, set
  `woiSelectedOrderId`, update `#woi-order-current` ("Selected: …"), close the
  panel, then `setOrderBarBusy(true)` →
  `woiFetchOrderTokens(id).then(refresh Live HTML + maybe PDF)` →
  `setOrderBarBusy(false)`. This mirrors the current select-change handler.
- **Keyboard:** ArrowDown/ArrowUp move an `is-active` highlight; Enter selects the
  active row; Escape closes the panel.
- **Dismiss:** a `document` click outside `.woi-order-combo` closes the panel.

The empty-term "last order" preview that `orderSearch()` did on blank input is no
longer needed as a separate path — focusing shows recents, and the page's initial
token fetch (unchanged) still defaults to the last order until the user picks one.

### 3. Server — `includes/Settings.php::preview_order_search()`

Two additive changes:

1. **Recent-orders mode when `search` is empty.** Today the method requires both
   `search` and `document_type` to be non-empty, otherwise it returns an error.
   Change so that when `document_type` is present but `search` is empty, it loads
   the most recent orders:

   ```php
   $recent_limit = (int) apply_filters( 'woi_pdf_preview_order_recent_limit', 5 );
   $results = wc_get_orders( array(
       'type'    => 'shop_order',
       'limit'   => $recent_limit,
       'orderby' => 'date',
       'order'   => 'DESC',
       'return'  => 'ids',
   ) );
   ```

   Legacy callers (`admin.js`) always send a non-empty `search`, so their
   behaviour is unchanged.

2. **New fields per order** in the `$data[$order_id]` array, alongside the
   existing keys:

   - `payment_method` — `get_payment_method_title()` (human label), sanitised.
   - `line_count` — `count( $order->get_items() )` (distinct product lines).
   - `unit_count` — sum of `$item->get_quantity()` over `get_items()`.
   - `total_raw` — `wc_price( get_total() )` with **no** `<strong>Total:</strong>`
     label prefix.
   - `date_raw` — `get_date_created()->format('Y/m/d')` with **no**
     `<strong>Date:</strong>` label prefix.

   Raw variants are needed because the existing `total` / `date_created` keys
   carry label-prefixed HTML that the legacy `admin.js` settings-page search
   renders verbatim — those must not change. The combobox uses the raw fields.
   All guarded with `is_callable(...)` like the existing fields. Existing keys are
   untouched, so the legacy settings-page search keeps working.

   The per-order row construction is extracted into a protected
   `build_order_row( $order ): array` seam so it can be unit-tested in isolation
   (mirrors `Rest::token_map` / `Rest::get_document`).

### 4. Styling

Add panel CSS to `assets/visual-editor/editor.css` (the sheet that already
styles `.woi-order-bar` and `#woi-editor-toolbar`). Panel: absolutely positioned under the input, white
background, border, subtle shadow, `max-height` with `overflow:auto`, `z-index`
above the GrapesJS canvas. Rows: padding, hover + `.is-active` highlight,
`.woi-op-title` bold, `.woi-op-meta` smaller/muted.

### 5. Cache-busting

Bump the plugin header `Version:` on line 6 of
`woocommerce-orders-invoice-pdf.php` (currently `1.4.31` → `1.4.32`).
`WOI_PDF_VERSION` is derived from that header (`$this->version`) and is the
asset-enqueue version, so bumping the header invalidates the cached JS/CSS — per
the project's asset-version convention.

## Data flow

```
focus / type
   └─> woiFetchOrders(term)  ──ajax──> preview_order_search()
                                          (recent 5 if term empty;
                                           else search by #/email/name)
           <── res.data {id: {order_number, billing_*, date_created,
                               total, payment_method, line_count, unit_count}}
   └─> woiRenderOrderPanel(data)  -> #woi-order-panel rows
pick row
   └─> woiFetchOrderTokens(id) -> Live HTML + PDF refresh  (unchanged)
```

## Error handling

- Ajax failure or `res.success === false`: render an empty panel with a single
  non-selectable "No orders found" row; never `alert()` on every keystroke.
- Empty result set for a typed term: same "No orders found" row.
- Orders with no name and no company: title shows "(no name)".

## Testing

- **PHP unit** (`tests/Unit`): extend / add coverage for
  `preview_order_search()` — empty `search` returns recent orders; the response
  includes `payment_method`, `line_count`, `unit_count`. Follow the existing
  Brain Monkey patterns and the PHPUnit ABSPATH bootstrap convention
  (`-d auto_prepend_file=tests/bootstrap.php`).
- **JS:** no in-repo JS harness — verified live against the editor screen
  (focus shows 5 recents; typing filters; picking refreshes the preview;
  keyboard + click-outside behave).

## Files touched

- `includes/Visual/VisualEditorPage.php` — combobox markup.
- `assets/visual-editor/app.js` — combobox behaviour (replace order-bar block).
- `includes/Settings.php` — recent-on-empty + 3 new response fields.
- `assets/visual-editor/editor.css` — panel CSS.
- `woocommerce-orders-invoice-pdf.php` — header `Version:` bump (line 6).
- `tests/Unit/...` — server coverage.
