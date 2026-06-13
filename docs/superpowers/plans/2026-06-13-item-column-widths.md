# Percentage-based Item Column Widths Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a dedicated, validated "Width (%)" field to each Item Column in the Customiser, rendered as percentage widths in the PDF, with unset columns auto-distributing the remaining space — across all templates and document types.

**Architecture:** Approach A from the spec. A new `width` option is injected into every column block in the editor, rendered via a new generic `number` field type. At render time the existing `woi_pdf_templates_maybe_apply_column_styles()` helper emits `width: X%` on both the `<th>` and `<td>` of a column (independent of `style_target`), so no template files change. A small pure normalizer clamps/validates the value. VAT-split columns carry the parent width into their split cells.

**Tech Stack:** PHP (WordPress plugin), PHPUnit 9.5 + Brain Monkey for unit tests, Dompdf (strauss-bundled) for PDF rendering.

**Spec:** `docs/superpowers/specs/2026-06-13-item-column-widths-design.md`

---

## Running the tests

This project has an ABSPATH gotcha — plain `vendor/bin/phpunit` exits silently. Always run:

```bash
php -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit --no-coverage
```

To run a single test:

```bash
php -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit --no-coverage --filter test_name
```

---

## File Structure

- **Modify** `woi-pdf-functions.php`
  - Add new pure function `woi_pdf_templates_normalize_column_width()` (validate/clamp a width value).
  - Extend `woi_pdf_templates_maybe_apply_column_styles()` to emit the dedicated width.
  - Add `width` carry into the VAT-split branch of `woi_pdf_templates_get_table_body()`.
- **Modify** `includes/Editor/EditorSettings.php`
  - Inject a `width` option into every column block in `get_columns_field_options()`.
  - Add a `number` case to `display_table_field_options()`.
- **Create** `tests/Unit/ColumnWidthTest.php` — unit tests for the two pure/near-pure functions.
- **Modify** `woocommerce-orders-invoice-pdf.php` — version bump (header + `$version`).

No template files change. No editor.js change (fields serialize by `name` on normal form submit; new fields are produced server-side by `display_table_field`).

---

## Task 1: Pure width normalizer + tests

**Files:**
- Modify: `woi-pdf-functions.php` (add new function near `woi_pdf_templates_sanitize_column_style`, around line 2553)
- Test: `tests/Unit/ColumnWidthTest.php` (create)

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/ColumnWidthTest.php`:

```php
<?php
namespace WOI\PDF\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class ColumnWidthTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** @dataProvider normalize_provider */
    public function test_normalize_column_width( $input, string $expected ): void {
        $this->assertSame( $expected, woi_pdf_templates_normalize_column_width( $input ) );
    }

    public function normalize_provider(): array {
        return array(
            'integer string'      => array( '20', '20' ),
            'decimal string'      => array( '20.5', '20.5' ),
            'trailing zeros'      => array( '50.00', '50' ),
            'half trailing zero'  => array( '12.50', '12.5' ),
            'float type'          => array( 12.5, '12.5' ),
            'max boundary'        => array( '100', '100' ),
            'zero is unset'       => array( '0', '' ),
            'over 100 is unset'   => array( '150', '' ),
            'negative is unset'   => array( '-10', '' ),
            'non-numeric is unset'=> array( 'abc', '' ),
            'empty is unset'      => array( '', '' ),
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:
```bash
php -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit --no-coverage --filter test_normalize_column_width
```
Expected: FAIL with `Error: Call to undefined function ...woi_pdf_templates_normalize_column_width()`.

- [ ] **Step 3: Write minimal implementation**

In `woi-pdf-functions.php`, immediately before the `if ( ! function_exists( 'woi_pdf_templates_sanitize_column_style' ) ) {` block (currently line 2553), add:

```php
if ( ! function_exists( 'woi_pdf_templates_normalize_column_width' ) ) {
	/**
	 * Validate and normalize a column width percentage.
	 *
	 * Accepts a numeric value in the range (0, 100]. Returns the trimmed
	 * numeric string (no unit) or '' when unset/invalid/out of range.
	 *
	 * @param mixed $value Raw width value from settings.
	 * @return string Normalized number as string, or '' if invalid.
	 */
	function woi_pdf_templates_normalize_column_width( $value ): string {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return '';
		}
		$num = (float) $value;
		if ( $num <= 0 || $num > 100 ) {
			return '';
		}
		// Trim trailing zeros: 50.00 -> 50, 12.50 -> 12.5.
		return rtrim( rtrim( sprintf( '%.2f', $num ), '0' ), '.' );
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run:
```bash
php -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit --no-coverage --filter test_normalize_column_width
```
Expected: PASS (11 assertions / data rows).

- [ ] **Step 5: Commit**

```bash
git add woi-pdf-functions.php tests/Unit/ColumnWidthTest.php
git commit -m "feat: add column width normalizer with validation"
```

---

## Task 2: Emit dedicated width in the column-style helper

**Files:**
- Modify: `woi-pdf-functions.php` — `woi_pdf_templates_maybe_apply_column_styles()` (currently lines 2618-2631)
- Test: `tests/Unit/ColumnWidthTest.php` (add methods)

- [ ] **Step 1: Write the failing tests**

Append these methods to `tests/Unit/ColumnWidthTest.php` (inside the class):

```php
    private function stub_wp(): void {
        // esc_attr passes through; sanitize helper is a pure function in the same file.
        Functions\when( 'esc_attr' )->returnArg();
    }

    public function test_width_only_applies_to_header_and_cells(): void {
        $this->stub_wp();
        $column = array( 'width' => '20' );
        $this->assertSame( ' style="width: 20%;"', woi_pdf_templates_maybe_apply_column_styles( $column, 'header' ) );
        $this->assertSame( ' style="width: 20%;"', woi_pdf_templates_maybe_apply_column_styles( $column, 'cells' ) );
    }

    public function test_width_ignores_style_target(): void {
        $this->stub_wp();
        // Freeform style targets header only; width must still reach cells.
        $column = array(
            'style'        => 'color:#000000;',
            'style_target' => 'header',
            'width'        => '30',
        );
        $cells = woi_pdf_templates_maybe_apply_column_styles( $column, 'cells' );
        $this->assertStringContainsString( 'width: 30%;', $cells );
        $this->assertStringNotContainsString( 'color', $cells ); // freeform color gated out for cells
    }

    public function test_dedicated_width_wins_over_freeform_width(): void {
        $this->stub_wp();
        $column = array(
            'style'        => 'width:99%; color:#000000;',
            'style_target' => 'both',
            'width'        => '30',
        );
        $result = woi_pdf_templates_maybe_apply_column_styles( $column, 'cells' );
        $this->assertStringContainsString( 'width: 30%;', $result );
        $this->assertStringNotContainsString( '99%', $result );
        $this->assertStringContainsString( 'color: #000000;', $result );
    }

    public function test_invalid_width_with_no_style_returns_empty(): void {
        $this->stub_wp();
        $this->assertSame( '', woi_pdf_templates_maybe_apply_column_styles( array( 'width' => '150' ), 'cells' ) );
    }

    public function test_no_width_no_style_returns_empty(): void {
        $this->stub_wp();
        $this->assertSame( '', woi_pdf_templates_maybe_apply_column_styles( array(), 'cells' ) );
    }

    public function test_freeform_style_unchanged_when_no_width(): void {
        $this->stub_wp();
        $column = array( 'style' => 'color:#000000;', 'style_target' => 'both' );
        $this->assertSame( ' style="color: #000000;"', woi_pdf_templates_maybe_apply_column_styles( $column, 'cells' ) );
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run:
```bash
php -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit --no-coverage --filter ColumnWidthTest
```
Expected: the new `test_width_*` / `test_dedicated_*` tests FAIL (current helper returns `''` when `style` empty and ignores `width`).

- [ ] **Step 3: Replace the implementation**

In `woi-pdf-functions.php`, replace the entire body of `woi_pdf_templates_maybe_apply_column_styles()` (the function inside the `if ( ! function_exists(...) )` guard at ~line 2618) with:

```php
	function woi_pdf_templates_maybe_apply_column_styles( array $column_data, string $target ): string {
		$style = '';

		// Freeform style respects the style_target setting (header / cells / both).
		if ( ! empty( $column_data['style'] ) ) {
			$apply_style = ! isset( $column_data['style_target'] )
				|| 'both'  === $column_data['style_target']
				|| $target === $column_data['style_target'];
			if ( $apply_style ) {
				$style = woi_pdf_templates_sanitize_column_style( $column_data['style'] );
			}
		}

		// Dedicated width applies to BOTH header and cells, and wins over any
		// width coming from the freeform style.
		$width = woi_pdf_templates_normalize_column_width(
			isset( $column_data['width'] ) ? $column_data['width'] : ''
		);
		if ( '' !== $width ) {
			$style = preg_replace( '/\bwidth\s*:[^;]*;?/i', '', $style );
			$style = trim( $style );
			if ( '' !== $style && ';' !== substr( $style, -1 ) ) {
				$style .= ';';
			}
			if ( '' !== $style ) {
				$style .= ' ';
			}
			$style .= 'width: ' . $width . '%;';
		}

		$style = trim( $style );
		if ( '' === $style ) {
			return '';
		}

		return ' style="' . esc_attr( $style ) . '"';
	}
```

- [ ] **Step 4: Run tests to verify they pass**

Run:
```bash
php -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit --no-coverage --filter ColumnWidthTest
```
Expected: PASS (all ColumnWidthTest methods).

- [ ] **Step 5: Run the full suite (no regressions)**

Run:
```bash
php -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit --no-coverage
```
Expected: All tests PASS (existing suite + new ColumnWidthTest).

- [ ] **Step 6: Commit**

```bash
git add woi-pdf-functions.php tests/Unit/ColumnWidthTest.php
git commit -m "feat: render dedicated column width in style helper"
```

---

## Task 3: Carry width into VAT-split body cells

**Files:**
- Modify: `woi-pdf-functions.php` — `woi_pdf_templates_get_table_body()` VAT-split branch (the `$new_column` array, currently lines 2502-2509)

**Why:** The header builder (`woi_pdf_templates_get_table_headers`) carries the full `$column_setting` (including `width`) into each split header via `$column_setting + $new_column + ...`. But the body builder constructs a fresh `$new_column` array for split cells that omits `width`, so split cells would lose alignment with their header. This adds it back. (This path requires a live order + tax data + singletons to exercise; it is verified manually in Task 5, step 3, rather than with a brittle unit test.)

- [ ] **Step 1: Add width to the split cell array**

In `woi-pdf-functions.php`, in the `$new_column = array(` block inside the VAT-split loop of `woi_pdf_templates_get_table_body()` (currently lines 2502-2509), add a `width` entry:

```php
								$new_column = array(
									'type'          => $column_setting['type'],
									'split'         => $split,
									'dash_for_zero' => isset( $column_setting['dash_for_zero'] ),
									'label'         => $column_setting['label'],
									'price_type'    => $column_setting['price_type'],
									'discount'      => $column_setting['discount'],
									'width'         => isset( $column_setting['width'] ) ? $column_setting['width'] : '',
								);
```

- [ ] **Step 2: Run the full suite (no regressions)**

Run:
```bash
php -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit --no-coverage
```
Expected: All tests PASS.

- [ ] **Step 3: Commit**

```bash
git add woi-pdf-functions.php
git commit -m "feat: carry column width into VAT-split cells"
```

---

## Task 4: Editor UI — inject the Width field + number renderer

**Files:**
- Modify: `includes/Editor/EditorSettings.php` — `get_columns_field_options()` (inject width; the `return apply_filters(...)` is currently at line 1133) and `display_table_field_options()` (add `number` case; switch starts at line 1602)

- [ ] **Step 1: Inject the width option into every column block**

In `includes/Editor/EditorSettings.php`, in `get_columns_field_options()`, replace the final return line (currently line 1133):

```php
		return apply_filters( 'woi_pdf_templates_customizer_column_blocks', $column_blocks );
```

with:

```php
		// Add a dedicated Width (%) field to every built-in column block.
		foreach ( $column_blocks as $block_key => &$block ) {
			if ( ! isset( $block['options'] ) || ! is_array( $block['options'] ) ) {
				continue;
			}
			$block['options'] = array(
				'width' => array(
					'type'        => 'number',
					'description' => __( 'Width (%)', 'woi_pdf_templates' ),
					'placeholder' => __( 'Auto', 'woi_pdf_templates' ),
					'min'         => 0,
					'max'         => 100,
					'step'        => 0.5,
				),
			) + $block['options'];
		}
		unset( $block );

		return apply_filters( 'woi_pdf_templates_customizer_column_blocks', $column_blocks );
```

- [ ] **Step 2: Add the `number` case to the field renderer**

In `includes/Editor/EditorSettings.php`, in `display_table_field_options()`, add a new `case` immediately after the `case 'text':` block (which ends with its `break;` near line 1631), before `case 'textarea':`:

```php
			case 'number':
				printf( '<span class="option-description">%s: </span>', $field_option['description'] );
				$placeholder = isset( $field_option['placeholder'] ) ? $field_option['placeholder'] : '';
				$min         = isset( $field_option['min'] ) ? $field_option['min'] : '';
				$max         = isset( $field_option['max'] ) ? $field_option['max'] : '';
				$step        = isset( $field_option['step'] ) ? $field_option['step'] : '';
				printf(
					'<input type="number" data-key="%s" name="%s" value="%s" placeholder="%s" min="%s" max="%s" step="%s">',
					esc_attr( $option_key ),
					esc_attr( $name ),
					esc_attr( $current ),
					esc_attr( $placeholder ),
					esc_attr( $min ),
					esc_attr( $max ),
					esc_attr( $step )
				);
				break;
```

- [ ] **Step 3: Lint the changed PHP file**

Run:
```bash
php -l includes/Editor/EditorSettings.php
```
Expected: `No syntax errors detected in includes/Editor/EditorSettings.php`.

- [ ] **Step 4: Run the full suite (no regressions)**

Run:
```bash
php -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit --no-coverage
```
Expected: All tests PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/Editor/EditorSettings.php
git commit -m "feat: add Width (%) field to Item Columns editor"
```

---

## Task 5: Version bump + manual verification

**Files:**
- Modify: `woocommerce-orders-invoice-pdf.php` (line 6 header, line 24 `$version`)

- [ ] **Step 1: Bump the version**

In `woocommerce-orders-invoice-pdf.php` line 6, change:
```php
 * Version:              1.1.8
```
to:
```php
 * Version:              1.2.0
```

And line 24, change:
```php
	public string $version     = '1.1.8';
```
to:
```php
	public string $version     = '1.2.0';
```

- [ ] **Step 2: Commit**

```bash
git add woocommerce-orders-invoice-pdf.php
git commit -m "chore: bump version to 1.2.0 for column widths"
```

- [ ] **Step 3: Manual verification (record results)**

On the live/dev site:
1. Customiser → Item Columns: confirm each column now shows a **Width (%)** numeric input with an "Auto" placeholder.
2. Set widths on a subset of columns (e.g. SKU 15, Product blank, Quantity 10, Price 20). Save.
3. Open the Customiser preview (or download the invoice PDF): confirm the columns with widths honor them and the blank columns share the remaining space.
4. Add a column via "Add a column" and confirm the Width field is present on the newly-added column.
5. For a column that has a freeform `width:` in its Style box AND a dedicated Width value: confirm the dedicated value wins in the rendered PDF.
6. VAT-split: on a document with a VAT column set to "Split" and an order with 2+ tax rates, set a width on the VAT column and confirm each split column renders at that width and stays aligned header-to-cells.

Record the outcome of each check in the PR / completion notes.

---

## Self-Review notes

- **Spec coverage:** data model (Task 4 step 1), editor UI/number field (Task 4), PDF rendering incl. style_target independence + conflict rule (Task 2), VAT-split carry (Task 3), render-time validation (Task 1 + Task 2), preview (same render path — verified Task 5 step 3), testing (Tasks 1-2 unit + Task 5 manual for integration-only paths). Out-of-scope items (colgroup, 100% normalization, totals widths) are not implemented, as intended.
- **Type consistency:** `woi_pdf_templates_normalize_column_width()` returns `string`; consumed by `woi_pdf_templates_maybe_apply_column_styles()` which checks `'' !== $width`. The `width` option key (`'width'`) matches across editor injection, the style helper, and the VAT-split carry.
- **No placeholders:** every code step shows complete code; commands include expected output.
