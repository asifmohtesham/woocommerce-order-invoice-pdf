# Tax Invoice UOM Column — Design

**Date:** 2026-06-23
**Branch:** `feat/tax-invoice-uom`
**Status:** Approved (design) — pending spec review

## Goal

Add a **UOM (Unit of Measure)** column to the invoice line-items table. The unit
is a **fixed, configurable string** (default `Nos`, the common UAE unit) shown identically on every
line. It is added per-template via the existing column editor, so the Tax
Invoice can show it while other documents need not.

## Non-goals

- Per-product or per-line UOM values (every line shows the same configured unit).
  The design leaves a clean one-line upgrade path to per-product, but does not
  implement it.
- Any change to the Block Invoice Template editor's generic `woi/table` block —
  that is not the line-items table.
- New React components or a webpack rebuild.

## Background — how line-item columns work

The line-items table is **schema-driven**. A column "type" is defined once in a
PHP schema and is then rendered generically:

- **Schema:** `includes/Editor/EditorSettings.php` →
  `get_columns_field_options()` (lines 455–1208) builds `$column_blocks`. Every
  block automatically receives a `width` (%) option and a `label_ar` (Arabic
  header) option via the `foreach` at lines 1170–1192.
- **Header render:** `includes/Editor/EditorMain.php` →
  `get_order_details_header()` (line 748). `extract( $column_setting )` exposes
  each saved option as a local variable; a `switch ( $type )` maps the type to a
  title and CSS class. A non-empty `$label` option overrides the title (line
  751).
- **Cell render:** `includes/Editor/EditorMain.php` →
  `get_order_details_data()` (line 878). Same `extract()`; a `switch ( $type )`
  sets `$column['data']`. Static-value precedent: `static_text` (line 1167–1170)
  returns its configured `$text`.
- **Editor UI:** `src/block-editor/ColumnEditor.js` builds its add-column
  dropdown from `Object.keys(schema)` and renders each option generically. **A
  new schema entry appears automatically — no JS change.**
- **PDF/HTML assembly:** `includes/Visual/TemplateTokens.php` →
  `render_line_items()` iterates whatever columns the helpers return and emits
  `<td><span>…</span></td>`. **No change needed** — and it renders through the
  same path as every other cell, so mPDF parity is automatic.

## Design — a new `uom` column type

### Edit 1 — schema (`EditorSettings.php`, in `$column_blocks`)

Register a `'uom'` block, cloned from `'quantity'`, with one extra option `unit`
(text, placeholder `PCS`). Order the options: `label`, `unit`, `separator`,
`style`, `style_target`. (`width` and `label_ar` are appended automatically.)

```php
'uom' => array (
    'title'   => __( 'UOM (unit of measure)', 'woi_pdf_templates' ),
    'options' => array (
        'label' => array(
            'type'        => 'text',
            'description' => __( 'Label', 'woi_pdf_templates' ),
            'placeholder' => __( 'Use default', 'woi_pdf_templates' ),
        ),
        'unit' => array(
            'type'        => 'text',
            'description' => __( 'Unit', 'woi_pdf_templates' ),
            'placeholder' => 'Nos',
        ),
        'separator' => array( 'type' => '' ),
        'style' => array(
            'type'        => 'text',
            'description' => __( 'Style', 'woi_pdf_templates' ),
        ),
        'style_target' => array(
            'type'    => 'select',
            'options' => array(
                'both'   => __( 'Apply style to entire column', 'woi_pdf_templates' ),
                'header' => __( 'Apply style to column header', 'woi_pdf_templates' ),
                'cells'  => __( 'Apply style to column cells', 'woi_pdf_templates' ),
            ),
        ),
    ),
),
```

### Edit 2 — header (`EditorMain.php`, `get_order_details_header()`)

Add a `case 'uom':` to the title `switch` (after `quantity`, ~line 769):

```php
case 'uom':
    $header['title'] = __( 'UOM', 'woi_pdf_templates' );
    break;
```

The class defaults to the type name (`uom`) at lines 842–844. A custom `$label`
overrides the title (line 751); the Arabic header comes from the shared
`label_ar` option via the existing bilingual engine — no extra code.

### Edit 3 — cell (`EditorMain.php`, `get_order_details_data()`)

Add a `case 'uom':` to the data `switch` (after `quantity`, ~line 971):

```php
case 'uom':
    $column['data'] = ! empty( $unit ) ? $unit : 'Nos';
    break;
```

**Do NOT add `uom` to `$item_dependent_columns`** (lines 884–897): the value is
constant and does not depend on a resolvable order item, so it should still
render on rows without `$item['item']`.

## Data flow

```
ColumnEditor.js (reads schema via REST /editor-config)
   └─ user adds "UOM" column, sets Unit (default "Nos"), width, Arabic header
        └─ saved column settings (incl. type='uom', unit='PCS')
             └─ render: get_order_details_header()  → <th>UOM</th>
                        get_order_details_data()    → <td><span>Nos</span></td>
                          via TemplateTokens::render_line_items() → mPDF
```

## Testing

PHPUnit (run with `-d auto_prepend_file=tests/bootstrap.php`):

1. **Header:** `get_order_details_header(['type'=>'uom'], $doc)` returns
   `title === 'UOM'` and `class` contains `uom`.
2. **Header label override:** with `label => 'Unit'`, title is `Unit`.
3. **Cell default:** `get_order_details_data(['type'=>'uom'], $item, $doc)`
   returns `data === 'Nos'` when `unit` is empty/unset.
4. **Cell configured:** with `unit => 'PCS'`, `data === 'PCS'`.
5. **Schema presence:** `get_columns_field_options()` contains a `uom` key whose
   `options` include `unit`, and (post-foreach) `width` + `label_ar`.

Follow existing test patterns in `tests/` for `EditorMain` / column rendering;
establish the baseline first (some helper-function failures are pre-existing —
see project notes on the PHPUnit harness).

No Jest change expected (ColumnEditor consumes the schema generically); if a
column-list snapshot test exists, update it to include `uom`.

## Files touched

| File | Change |
|------|--------|
| `includes/Editor/EditorSettings.php` | add `uom` block to `$column_blocks` |
| `includes/Editor/EditorMain.php` | `case 'uom'` in header + data switches |
| `tests/…` | unit tests for header/data/schema |

## Version / build

PHP-only logic change to `includes/` ⇒ this **does** touch plugin code, so on
landing bump **both** version strings to the next free patch and run
`npm run build` per CLAUDE.md's landing sequence. (No JS source changed, but the
bundle rebuild keeps the `?ver=` cache-bust consistent.)

## Upgrade path (future, out of scope)

To make UOM per-product later: change Edit 3 to read a product meta/attribute
(e.g. `$item['product']->get_meta( $field_name )`) and add a `field_name` option
to the schema — mirroring the existing `product_custom` column. Same column
type, no migration.
