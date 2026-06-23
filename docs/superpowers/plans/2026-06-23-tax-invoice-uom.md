# Tax Invoice UOM Column Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a configurable, fixed-value "UOM" (Unit of Measure) column to the invoice line-items table, defaulting to `Nos`.

**Architecture:** The line-items table is schema-driven. We register one new column type `uom` in the PHP column schema (`EditorSettings::get_columns_field_options()`) and add a matching `case 'uom'` to the header renderer and the cell renderer (`EditorMain`). The editor UI (`ColumnEditor.js`) and the PDF/HTML assembler (`TemplateTokens.php`) consume the schema generically, so they need no changes.

**Tech Stack:** PHP (WordPress/WooCommerce plugin), PHPUnit + Brain\Monkey for unit tests, mPDF for PDF rendering.

## Global Constraints

- Work happens in the worktree `C:\Users\asifm\source\repos\woi-tax-invoice-uom` (branch `feat/tax-invoice-uom`). Do NOT commit to master.
- PHPUnit MUST run with `-d auto_prepend_file=tests/bootstrap.php` or it dies silently (ABSPATH undefined).
- Some baseline PHPUnit failures pre-exist (helper functions not loaded under the harness). Establish the baseline before judging a change; only the new `uom` tests must pass.
- Default UOM value is `Nos` (UAE convention) — exact string, capital N.
- Header `<th>` default text is `UOM` — exact string, all caps.
- No JavaScript source changes and no new React components (the editor reads the schema generically). The bundle rebuild at landing is only for the `?ver=` cache-bust.
- This touches `includes/` (plugin code), so at LANDING (not before) bump BOTH version strings to the next free patch and run `npm run build`, per CLAUDE.md's landing sequence.

---

### Task 1: Register the `uom` column type in the schema

**Files:**
- Modify: `includes/Editor/EditorSettings.php` (inside `get_columns_field_options()`, `$column_blocks` array — insert after the `'quantity'` block, which ends around line 610)
- Test: `tests/Unit/Editor/UomColumnTest.php` (create)

**Interfaces:**
- Consumes: `\WOI\PDF\Editor\EditorSettings::instance()->get_columns_field_options(): array`
- Produces: a `'uom'` key in that array. Its `options` includes a `unit` text field; after the class's automatic post-processing the block also carries `width` and `label_ar` options.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Editor/UomColumnTest.php`:

```php
<?php
namespace WOI\PDF\Tests\Unit\Editor;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Editor\EditorSettings;
use WOI\PDF\Editor\EditorMain;

class UomColumnTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Mirror the existing test pattern: translations pass through,
		// apply_filters returns the filtered value (its 2nd argument).
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_schema_registers_uom_with_unit_width_and_label_ar(): void {
		$schema = EditorSettings::instance()->get_columns_field_options();

		$this->assertArrayHasKey( 'uom', $schema, 'uom column type must be registered' );
		$this->assertArrayHasKey( 'unit', $schema['uom']['options'], 'uom must expose a unit option' );
		// Added to every block by the post-processing foreach in get_columns_field_options().
		$this->assertArrayHasKey( 'width', $schema['uom']['options'], 'width is auto-added to every block' );
		$this->assertArrayHasKey( 'label_ar', $schema['uom']['options'], 'Arabic header is auto-added to every block' );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php tests/Unit/Editor/UomColumnTest.php`
Expected: FAIL — `assertArrayHasKey('uom', ...)` fails (key absent).

- [ ] **Step 3: Add the `uom` block to the schema**

In `includes/Editor/EditorSettings.php`, inside `$column_blocks` in `get_columns_field_options()`, insert this entry immediately after the closing `),` of the `'quantity'` block (the `'quantity'` block spans roughly lines 586–610):

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
		'separator' => array(
			'type' => '',
		),
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

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php tests/Unit/Editor/UomColumnTest.php`
Expected: PASS (1 test, all assertions green).

- [ ] **Step 5: Commit**

```bash
git add includes/Editor/EditorSettings.php tests/Unit/Editor/UomColumnTest.php
git commit -m "feat(uom): register uom column type in line-item schema"
```

---

### Task 2: Render the `uom` column header

**Files:**
- Modify: `includes/Editor/EditorMain.php` (`get_order_details_header()` — add a `case` in the title `switch`, after `case 'quantity':` ~line 769)
- Test: `tests/Unit/Editor/UomColumnTest.php` (add method)

**Interfaces:**
- Consumes: `(new \WOI\PDF\Editor\EditorMain())->get_order_details_header( array $column_setting, $document ): array` — returns a header array with `title` and `class` keys.
- Produces: for `type === 'uom'` and no `label`, `title === 'UOM'` and `class` contains `uom`.

- [ ] **Step 1: Write the failing test**

Add this method to `tests/Unit/Editor/UomColumnTest.php`:

```php
	public function test_header_default_title_and_class(): void {
		$editor = new EditorMain();
		$header = $editor->get_order_details_header( array( 'type' => 'uom' ), null );

		$this->assertSame( 'UOM', $header['title'], 'default uom header title' );
		$this->assertStringContainsString( 'uom', $header['class'], 'uom header carries the uom css class' );
	}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php tests/Unit/Editor/UomColumnTest.php --filter test_header_default_title_and_class`
Expected: FAIL — without a `case 'uom'`, the `default` branch sets `title` to the translated type string (`'uom'`, lowercase), so `assertSame('UOM', ...)` fails.

- [ ] **Step 3: Add the header case**

In `includes/Editor/EditorMain.php`, in `get_order_details_header()`, add this case to the title `switch ( $type )` immediately after the `case 'quantity':` block (the one that sets `'Quantity'`, ~line 767–769):

```php
				case 'uom':
					$header['title'] = __( 'UOM', 'woi_pdf_templates' );
					break;
```

(The CSS class defaults to the type name `uom` via the existing `if ( ! isset( $header['class'] ) )` block — no extra code needed. A non-empty `label` option still overrides the title via the existing top-of-method check.)

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php tests/Unit/Editor/UomColumnTest.php --filter test_header_default_title_and_class`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/Editor/EditorMain.php tests/Unit/Editor/UomColumnTest.php
git commit -m "feat(uom): render UOM column header"
```

---

### Task 3: Render the `uom` cell value (default `Nos`, configurable)

**Files:**
- Modify: `includes/Editor/EditorMain.php` (`get_order_details_data()` — add a `case` in the data `switch`, after `case 'quantity':` ~line 971)
- Test: `tests/Unit/Editor/UomColumnTest.php` (add methods)

**Interfaces:**
- Consumes: `(new \WOI\PDF\Editor\EditorMain())->get_order_details_data( array $column_setting, array $item, $document ): array` — returns a column array with a `data` key.
- Produces: for `type === 'uom'`, `data === $unit` when the `unit` option is non-empty, else `data === 'Nos'`.

- [ ] **Step 1: Write the failing tests**

Add these two methods to `tests/Unit/Editor/UomColumnTest.php`:

```php
	public function test_cell_defaults_to_nos(): void {
		$editor = new EditorMain();
		$column = $editor->get_order_details_data( array( 'type' => 'uom' ), array(), null );

		$this->assertSame( 'Nos', $column['data'], 'uom cell defaults to Nos when unit is unset' );
	}

	public function test_cell_uses_configured_unit(): void {
		$editor = new EditorMain();
		$column = $editor->get_order_details_data(
			array( 'type' => 'uom', 'unit' => 'PCS' ),
			array(),
			null
		);

		$this->assertSame( 'PCS', $column['data'], 'uom cell echoes the configured unit verbatim' );
	}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php tests/Unit/Editor/UomColumnTest.php --filter test_cell`
Expected: FAIL — without a `case 'uom'`, the data `switch` never sets `$column['data']`, so `$column['data']` is undefined and the assertions fail.

- [ ] **Step 3: Add the data case**

In `includes/Editor/EditorMain.php`, in `get_order_details_data()`, add this case to the data `switch ( $type )` immediately after the `case 'quantity':` block (the one that sets `$column['data'] = $item['quantity'];`, ~line 966–971):

```php
				case 'uom':
					$column['data'] = ! empty( $unit ) ? $unit : 'Nos';
					break;
```

(Do NOT add `uom` to the `$item_dependent_columns` array — the value is constant and must render even on rows without a resolvable order item.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `php vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php tests/Unit/Editor/UomColumnTest.php`
Expected: PASS (4 tests total in the file).

- [ ] **Step 5: Commit**

```bash
git add includes/Editor/EditorMain.php tests/Unit/Editor/UomColumnTest.php
git commit -m "feat(uom): render UOM cell value (default Nos, configurable)"
```

---

### Task 4: Manual PDF verification (no deploy)

**Files:**
- No source changes. Uses the local render harness.

This confirms the column renders through real mPDF and reads as a normal cell (mPDF parity), beyond the unit tests.

- [ ] **Step 1: Add a `uom` column to the sample template config**

The local sample renders the active template. Add a UOM column to the invoice column settings used by the sample. Inspect `tools/render-visual-sample.php` to see which template/columns it loads, then add a `uom` column (e.g. `array( 'type' => 'uom', 'unit' => 'Nos' )`) to that column list — either in the saved settings the sample reads, or inline in the sample script if it builds columns directly.

- [ ] **Step 2: Render the sample PDF**

Run: `php tools/render-visual-sample.php`
Expected: writes a sample PDF (note the output path it prints) with no PHP fatal.

- [ ] **Step 3: Rasterize and inspect**

Run: `python tools/rasterize.py <pdf-from-step-2> uom-check`
Expected: PNG(s) written. Open them and confirm the line-items table shows a `UOM` header and `Nos` in each product row, aligned like the other columns.

- [ ] **Step 4: Revert any throwaway sample edits**

If you edited `tools/render-visual-sample.php` or sample settings only to preview, revert those so they are not committed:

```bash
git checkout -- tools/render-visual-sample.php
```

(Skip if you made no source edits in Step 1.)

- [ ] **Step 5: Run the full unit suite to confirm no regressions**

Run: `php vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php tests/Unit`
Expected: the 4 new `UomColumnTest` tests pass; the overall pass/fail counts match the pre-existing baseline (no NEW failures introduced).

---

### Task 5: Landing prep (version bump + build)

**Files:**
- Modify: `woocommerce-orders-invoice-pdf.php` (line ~6 `* Version:` and line ~24 `public string $version`)
- Modify: `assets/js/block-editor/index.js` (regenerated by build)

Do this LAST, after syncing to latest master, per CLAUDE.md's landing sequence.

- [ ] **Step 1: Sync to latest master**

```bash
git fetch origin && git rebase origin/master
```

Expected: clean rebase (resolve conflicts if any, then `git rebase --continue`).

- [ ] **Step 2: Read the TRUE current version**

```bash
git show origin/master:woocommerce-orders-invoice-pdf.php | grep -nE "Version:|public string \$version"
```

Note the version; the next free patch is that patch number + 1.

- [ ] **Step 3: Bump BOTH version strings**

Edit `woocommerce-orders-invoice-pdf.php`:
- Line ~6: `* Version:           X.Y.Z` → next free patch
- Line ~24: `public string $version = 'X.Y.Z';` → same value

Both strings MUST match.

- [ ] **Step 4: Rebuild the bundle**

Run: `npm run build`
Expected: `assets/js/block-editor/index.js` regenerated, no build errors.

- [ ] **Step 5: Commit the bump + build**

```bash
git add -A
git commit -m "chore: vX.Y.Z + build"
```

- [ ] **Step 6: Push (fast-forward)**

```bash
git push origin HEAD:master
```

If rejected (someone landed in between): `git fetch && git rebase origin/master`, re-bump to the next free patch, `npm run build`, recommit, push again.

Then in the main/deploy checkout: `git pull --ff-only origin master`.
