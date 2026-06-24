# Document Naming Series & PDF Filename Format (Block Invoice Template) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Surface the existing per-type document numbering series (prefix/suffix/padding/yearly-reset/next-number) and a per-type PDF filename override into the Block Invoice Template editor, and add a `{document_number_sequence}` filename token — all sharing storage with the classic settings tabs.

**Architecture:** The filename builder gains a per-type → global → default resolution chain plus one new token; classic per-type settings gain a filename-override field; a new REST endpoint (`/document-naming`) fronts the existing per-type option (`woi_pdf_documents_settings_{type}`) and sequential-number store; a Block-editor sidebar panel reads/writes through that endpoint. No new storage, no sync logic — both surfaces touch the same option + store.

**Tech Stack:** PHP 7.4+ (WordPress/WooCommerce plugin), PHPUnit + Brain Monkey (unit), `@wordpress/scripts` (webpack) + Jest for the React block editor (`src/block-editor/`).

## Global Constraints

- **Worktree:** All work happens in `C:\Users\asifm\source\repos\woi-document-naming-series` (branch `feat/document-naming-series`, based on `origin/master` @ v1.5.80). NEVER edit the main checkout. The pre-commit guard blocks commits to master.
- **PHPUnit invocation:** ALWAYS `php vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php <path>` — it dies silently without the flag. Establish the baseline (some helper-function failures pre-exist) before judging a change.
- **Jest:** `npm run test:unit`.
- **Per-type settings option name:** `woi_pdf_documents_settings_{type}` (confirmed via `Settings::get_document_settings()`, `includes/Settings.php:680-689`). `number_format` is a sub-array (`prefix`/`suffix`/`padding`); `reset_number_yearly` and the new `filename_template` are top-level keys.
- **Numbered types** (have a series): `invoice`, `proforma`, `credit-note`, `receipt`. **`packing-slip`** gets a filename override only (no series). `summary`/bulk: excluded.
- **Filename token set:** `{document_type}`, `{order_number}`, `{document_number}` (already the *formatted* series number), `{document_number_sequence}` (new — raw counter), `{date}`.
- **Default filename template** (shipped): `{document_type}_{order_number}_{date}` (underscores), date format `Y-m-d`. Do not change it.
- **REST:** namespace `woi-pdf/v1`; permission `current_user_can( 'manage_woocommerce' )`; nonce via `X-WP-Nonce` header (matches `src/block-editor/store.js`). New routes register inside `Rest::register_visual_template_route()` — the ALWAYS-ON method (hooked unconditionally in the constructor) that already holds `/visual-columns` and `/editor-config`. Do NOT use `rest_api_init()` — that method is gated by the debug `enable_rest_api` flag, and the Block editor reaches the always-on routes unconditionally, so a gated `/document-naming` would 404 for most users.
- **Version bump:** Touches PHP + JS, so bump BOTH strings in `woocommerce-orders-invoice-pdf.php` (header `Version` ~line 6 and `public string $version` ~line 24) and run `npm run build` — done **LAST, at landing**, on rebased source (see CLAUDE.md "Landing a feature"). Do NOT bump per-task.
- **i18n text domain:** `woocommerce-orders-invoice-pdf`.

---

### Task 1: Filename builder — per-type override + `{document_number_sequence}` token

**Files:**
- Modify: `woi-pdf-functions.php` — `woi_pdf_get_filename_settings()` (`:295-312`) and `woi_pdf_build_filename()` (`:325-388`)
- Test: `tests/Unit/FilenameBuilderTest.php` (extend)

**Interfaces:**
- Produces: `woi_pdf_get_filename_settings( string $type = '' ): array{template:string, date_format:string}` — when `$type` is non-empty and `get_option("woi_pdf_documents_settings_{$type}")['filename_template']` is a non-empty trimmed string, that template wins over the global one; same for `filename_date_format`.
- Produces: `woi_pdf_build_filename( array $args )` now also reads `$args['document_number_sequence']` (string; default `''`) and substitutes `{document_number_sequence}`; resolves the template via `woi_pdf_get_filename_settings( (string) $args['type'] )`. Empty `{document_number_sequence}` is collapsed exactly like the existing empty-token handling. Bulk (`order_count > 1`) forces `{document_number_sequence}` empty, mirroring `{document_number}`.

- [ ] **Step 1: Write the failing tests**

Append these methods to the `FilenameBuilderTest` class in `tests/Unit/FilenameBuilderTest.php` (before the final closing `}`):

```php
	public function test_document_number_sequence_token_uses_raw_counter(): void {
		Functions\when( 'get_option' )->justReturn( array(
			'filename_template' => '{document_type}-{document_number_sequence}-{date}',
		) );
		$this->assertSame(
			'invoice-123-2026-06-20.pdf',
			woi_pdf_build_filename( $this->args( array( 'document_number_sequence' => '123' ) ) )
		);
	}

	public function test_document_number_sequence_empty_collapses_separators(): void {
		Functions\when( 'get_option' )->justReturn( array(
			'filename_template' => '{document_type}-{document_number_sequence}-{date}',
		) );
		$this->assertSame(
			'invoice-2026-06-20.pdf',
			woi_pdf_build_filename( $this->args( array( 'document_number_sequence' => '' ) ) )
		);
	}

	public function test_formatted_and_sequence_tokens_coexist(): void {
		Functions\when( 'get_option' )->justReturn( array(
			'filename_template' => '{document_number}-{document_number_sequence}',
		) );
		$this->assertSame(
			'INV-2026-000123-123.pdf',
			woi_pdf_build_filename( $this->args( array(
				'document_number'          => 'INV-2026-000123',
				'document_number_sequence' => '123',
			) ) )
		);
	}

	public function test_bulk_forces_sequence_token_empty(): void {
		Functions\when( 'get_option' )->justReturn( array(
			'filename_template' => '{document_type}-{document_number_sequence}-{date}',
		) );
		// document_type="invoices", sequence forced empty for bulk; the `--`
		// left by the empty token collapses to one `-`. No {order_number} in
		// this template, so "N-orders" must NOT appear in the sequence slot.
		$this->assertSame(
			'invoices-2026-06-20.pdf',
			woi_pdf_build_filename( $this->args( array(
				'document_type'            => 'invoices',
				'order_ids'                => array( 55, 56, 57 ),
				'document_number_sequence' => '7', // ignored for bulk
			) ) )
		);
	}

	public function test_per_type_template_overrides_global(): void {
		// Global template is one thing; the per-type option overrides it.
		// get_option is called for BOTH 'woi_pdf_settings_general' and
		// 'woi_pdf_documents_settings_invoice' — return per key.
		Functions\when( 'get_option' )->alias( function ( $name, $default = array() ) {
			if ( 'woi_pdf_settings_general' === $name ) {
				return array( 'filename_template' => '{document_type}-{order_number}-{date}' );
			}
			if ( 'woi_pdf_documents_settings_invoice' === $name ) {
				return array( 'filename_template' => 'INV_{order_number}' );
			}
			return $default;
		} );
		$this->assertSame(
			'INV_1042.pdf',
			woi_pdf_build_filename( $this->args() )
		);
	}

	public function test_per_type_blank_falls_back_to_global(): void {
		Functions\when( 'get_option' )->alias( function ( $name, $default = array() ) {
			if ( 'woi_pdf_settings_general' === $name ) {
				return array( 'filename_template' => '{document_type}-{order_number}' );
			}
			if ( 'woi_pdf_documents_settings_invoice' === $name ) {
				return array( 'filename_template' => '   ' ); // whitespace only
			}
			return $default;
		} );
		$this->assertSame(
			'invoice-1042.pdf',
			woi_pdf_build_filename( $this->args() )
		);
	}

	public function test_per_type_date_format_override(): void {
		Functions\when( 'get_option' )->alias( function ( $name, $default = array() ) {
			if ( 'woi_pdf_documents_settings_invoice' === $name ) {
				return array( 'filename_date_format' => 'Ymd' );
			}
			return $default;
		} );
		$this->assertSame(
			'invoice_1042_20260620.pdf',
			woi_pdf_build_filename( $this->args() )
		);
	}

	public function test_settings_resolver_per_type_override(): void {
		Functions\when( 'get_option' )->alias( function ( $name, $default = array() ) {
			if ( 'woi_pdf_documents_settings_receipt' === $name ) {
				return array( 'filename_template' => 'RCPT-{order_number}', 'filename_date_format' => 'd/m/Y' );
			}
			return $default;
		} );
		$settings = woi_pdf_get_filename_settings( 'receipt' );
		$this->assertSame( 'RCPT-{order_number}', $settings['template'] );
		$this->assertSame( 'd/m/Y', $settings['date_format'] );
	}
```

Note: the existing `args()` helper has no `document_number_sequence` key; `woi_pdf_build_filename` defaults it to `''` via `array_merge`, so existing tests stay green.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php tests/Unit/FilenameBuilderTest.php`
Expected: the new tests FAIL (e.g. `{document_number_sequence}` left literal in output; per-type override not applied). Existing tests still PASS.

- [ ] **Step 3: Update `woi_pdf_get_filename_settings()` for per-type resolution**

Replace the whole function body (`woi-pdf-functions.php:295-312`) with:

```php
/**
 * Resolve the configurable filename settings, applying defaults.
 *
 * Resolution order (first non-empty wins): per-type override (stored on the
 * document's own settings option) -> global (woi_pdf_settings_general) ->
 * hard default.
 *
 * @param string $type Optional machine document type (e.g. 'invoice') whose
 *                     per-type override should take precedence.
 * @return array{template:string, date_format:string}
 */
function woi_pdf_get_filename_settings( string $type = '' ): array {
	$global = get_option( 'woi_pdf_settings_general', array() );

	$per_type = array();
	if ( '' !== $type ) {
		$per_type = (array) get_option( "woi_pdf_documents_settings_{$type}", array() );
	}

	$pick = static function ( $key ) use ( $per_type, $global ) {
		$value = isset( $per_type[ $key ] ) ? trim( (string) $per_type[ $key ] ) : '';
		if ( '' !== $value ) {
			return $value;
		}
		return isset( $global[ $key ] ) ? trim( (string) $global[ $key ] ) : '';
	};

	$template = $pick( 'filename_template' );
	if ( '' === $template ) {
		$template = '{document_type}_{order_number}_{date}';
	}

	$date_format = $pick( 'filename_date_format' );
	if ( '' === $date_format ) {
		$date_format = 'Y-m-d';
	}

	return array(
		'template'    => $template,
		'date_format' => $date_format,
	);
}
```

- [ ] **Step 4: Wire the type + new token into `woi_pdf_build_filename()`**

In `woi-pdf-functions.php`, add `'document_number_sequence' => ''` to the `array_merge` defaults block (`:326-336`), so it reads:

```php
	$args = array_merge( array(
		'type'                     => '',
		'document_type'            => '',
		'order_ids'                => array(),
		'order_number'             => '',
		'order_id'                 => 0,
		'document_number'          => '',
		'document_number_sequence' => '',
		'output_format'            => 'pdf',
		'context'                  => 'download',
		'filter_args'              => array(),
	), $args );
```

Change the settings lookup (`:338`) from:

```php
	$settings    = woi_pdf_get_filename_settings();
```

to:

```php
	$settings    = woi_pdf_get_filename_settings( (string) $args['type'] );
```

Add the new token to the `$replacements` array (`:362-367`) — insert after the `{document_number}` line:

```php
		'{document_number}'          => $is_bulk ? '' : (string) $args['document_number'],
		'{document_number_sequence}' => $is_bulk ? '' : (string) $args['document_number_sequence'],
```

(Leave the separator-collapse, extension, filter, and sanitize steps unchanged — they already handle the new empty token.)

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php tests/Unit/FilenameBuilderTest.php`
Expected: all tests PASS (new + existing).

- [ ] **Step 6: Commit**

```bash
cd "C:/Users/asifm/source/repos/woi-document-naming-series"
git add woi-pdf-functions.php tests/Unit/FilenameBuilderTest.php
git commit -m "feat(filename): per-type template override + {document_number_sequence} token

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 2: Document callers pass the raw sequence number

**Files:**
- Modify: `includes/Documents/Invoice.php` (`get_filename`, ~`:179-189`)
- Modify: `includes/Documents/PackingSlip.php` (`get_filename`, ~`:97-107`)
- Modify: `includes/Documents/CreditNote.php` (`get_filename`, ~`:147-157`)
- Modify: `includes/Documents/Proforma.php` (`get_filename`)
- Modify: `includes/Documents/Receipt.php` (`get_filename`)
- Modify: `includes/Documents/OrderDocument.php` (generic `get_filename`, ~`:1934`)
- Test: `tests/Unit/FilenameSequenceCallerTest.php` (create)

**Interfaces:**
- Consumes: `DocumentNumber::get_plain(): ?int` (`includes/Documents/DocumentNumber.php:121-123`) — the raw counter, or `null` when no number is assigned.
- Produces: every `get_filename()` passes `'document_number_sequence' => <raw counter or ''>` to `woi_pdf_build_filename()`.

- [ ] **Step 1: Write the failing test (sequence-resolution helper)**

The per-document edits are mechanical (identical line added in 6 files), so the meaningful, testable unit is the "DocumentNumber → sequence string" rule. Extract it as a tiny pure helper and test that. Create `tests/Unit/FilenameSequenceCallerTest.php`:

```php
<?php
namespace WOI\PDF\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * woi_pdf_document_number_sequence() converts a DocumentNumber-like object into
 * the raw-counter string used for the {document_number_sequence} filename token:
 * the plain int as a string, or '' when there is no number.
 */
class FilenameSequenceCallerTest extends TestCase {

	public function test_returns_plain_counter_as_string(): void {
		$num = new class {
			public function get_plain(): ?int { return 123; }
		};
		$this->assertSame( '123', woi_pdf_document_number_sequence( $num ) );
	}

	public function test_returns_empty_for_null_object(): void {
		$this->assertSame( '', woi_pdf_document_number_sequence( null ) );
	}

	public function test_returns_empty_when_plain_is_null(): void {
		$num = new class {
			public function get_plain(): ?int { return null; }
		};
		$this->assertSame( '', woi_pdf_document_number_sequence( $num ) );
	}

	public function test_returns_empty_when_object_lacks_get_plain(): void {
		$this->assertSame( '', woi_pdf_document_number_sequence( new \stdClass() ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php tests/Unit/FilenameSequenceCallerTest.php`
Expected: FAIL — `Call to undefined function WOI\PDF\Tests\Unit\woi_pdf_document_number_sequence()`.

- [ ] **Step 3: Add the helper**

In `woi-pdf-functions.php`, immediately AFTER the `woi_pdf_build_filename()` function (after its closing `}` at `:388`), add:

```php
/**
 * Resolve the raw sequence counter for the {document_number_sequence} filename
 * token from a DocumentNumber (or any object exposing get_plain()).
 *
 * @param mixed $document_number A DocumentNumber instance, or null.
 * @return string The plain counter as a string, or '' when unavailable.
 */
function woi_pdf_document_number_sequence( $document_number ): string {
	if ( is_object( $document_number ) && is_callable( array( $document_number, 'get_plain' ) ) ) {
		$plain = $document_number->get_plain();
		if ( null !== $plain ) {
			return (string) $plain;
		}
	}
	return '';
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php tests/Unit/FilenameSequenceCallerTest.php`
Expected: PASS.

- [ ] **Step 5: Add the arg in each `get_filename()`**

In EACH of the six files listed under **Files**, find the line:

```php
			'document_number' => (string) $this->get_number(),
```

and insert immediately AFTER it:

```php
			'document_number_sequence' => woi_pdf_document_number_sequence( $this->get_number() ),
```

NOTE: the generic `OrderDocument::get_filename()` (`~:1950`) is the exception — it deliberately hardcodes `'document_number' => ''` (it also serves refunds, where calling `get_number()` is inappropriate). To preserve the "sequence mirrors document_number" invariant, add `'document_number_sequence' => '',` there (NOT the helper call). The five subclasses use the real helper call; only the base class blanks it. Match the surrounding indentation in each file.

- [ ] **Step 6: Verify nothing broke (PHP lints + full unit suite baseline)**

Run: `php -l includes/Documents/Invoice.php && php -l includes/Documents/PackingSlip.php && php -l includes/Documents/CreditNote.php && php -l includes/Documents/Proforma.php && php -l includes/Documents/Receipt.php && php -l includes/Documents/OrderDocument.php`
Expected: `No syntax errors detected` for each.

Run: `php vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php tests/Unit/`
Expected: no NEW failures vs the pre-existing baseline.

- [ ] **Step 7: Commit**

```bash
cd "C:/Users/asifm/source/repos/woi-document-naming-series"
git add woi-pdf-functions.php includes/Documents/ tests/Unit/FilenameSequenceCallerTest.php
git commit -m "feat(filename): pass raw sequence counter from every document get_filename()

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 3: Classic-tab per-type "Filename override" field

**Files:**
- Modify: `includes/Documents/Invoice.php` (`get_pdf_settings_fields` array, after the `document_title` field ~`:280-293`)
- Modify: `includes/Documents/PackingSlip.php`, `Proforma.php`, `CreditNote.php`, `Receipt.php` (their settings-field arrays)
- Test: manual admin smoke (no unit test — this is a declarative settings-field array consumed by the existing `text_element` callback)

**Interfaces:**
- Produces: a `filename_template` setting on each numbered type's PDF settings option (`woi_pdf_documents_settings_{type}`), rendered by the existing `text_element` callback. Consumed by Task 1's `woi_pdf_get_filename_settings( $type )`.

- [ ] **Step 1: Add the field to Invoice**

In `includes/Documents/Invoice.php`, inside `get_pdf_settings_fields()`, add this array element immediately AFTER the `document_title` setting block (the one ending at `:293`). `$option_name` is in scope (`= "woi_pdf_documents_settings_{$this->get_type()}"`):

```php
			array(
				'type'     => 'setting',
				'id'       => 'filename_template',
				'title'    => __( 'PDF filename override', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'text_element',
				'section'  => $this->type,
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'filename_template',
					'size'        => 'regular',
					'description' => sprintf(
						/* translators: %s: comma-separated list of placeholder tokens */
						__( 'Filename for this document\'s PDF. Leave blank to use the global template (Settings &rarr; General). Available tokens: %s. The extension is added automatically.', 'woocommerce-orders-invoice-pdf' ),
						'<code>{document_type}</code>, <code>{order_number}</code>, <code>{document_number}</code>, <code>{document_number_sequence}</code>, <code>{date}</code>'
					),
				),
			),
```

- [ ] **Step 2: Add the SAME field to the other four classes**

Add the identical array element to the settings-field array in `PackingSlip.php`, `Proforma.php`, `CreditNote.php`, and `Receipt.php`. In each, place it next to that class's existing `document_title` field (or, if absent, anywhere within that class's section before the closing of the fields array). Confirm `$option_name` is the in-scope variable in each method; if a class builds fields without `$option_name`, use `"woi_pdf_documents_settings_{$this->get_type()}"` literally.

CRITICAL — `'section'` must equal that class's section ROW `'id'`, which is NOT always `$this->type`. Verified section ids: Invoice → `invoice` (= `$this->type`, OK), Proforma → `proforma` (OK), Receipt → `receipt` (OK), but **PackingSlip → `packing_slip`** and **CreditNote → `credit_note`** (UNDERSCORES — they differ from the hyphenated `$this->type`). For PackingSlip and CreditNote, set `'section' => 'packing_slip'` / `'section' => 'credit_note'` (the literal each class uses for its other fields), NOT `$this->type`, or the field renders in no section.

- [ ] **Step 3: Verify PHP lints**

Run: `php -l includes/Documents/Invoice.php && php -l includes/Documents/PackingSlip.php && php -l includes/Documents/Proforma.php && php -l includes/Documents/CreditNote.php && php -l includes/Documents/Receipt.php`
Expected: `No syntax errors detected` for each.

- [ ] **Step 4: Commit**

```bash
cd "C:/Users/asifm/source/repos/woi-document-naming-series"
git add includes/Documents/
git commit -m "feat(settings): per-type PDF filename override field in classic tabs

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 4: REST endpoint `/document-naming` (GET + POST)

**Files:**
- Modify: `includes/Rest.php` — register routes in `rest_api_init()` (after the `editor-config` block, ~`:148`); add handler methods (near `handle_visual_doc_options`, ~`:1140`)
- Test: `tests/Unit/DocumentNamingRestTest.php` (create) — tests the pure helpers, not the WP route plumbing

**Interfaces:**
- Produces (PHP helper, static on `Rest`): `Rest::numbering_types(): array` → `['invoice','proforma','credit-note','receipt']`; `Rest::naming_types(): array` → numbering types + `['packing-slip']` (all switcher-eligible types).
- Produces (PHP helper, static on `Rest`): `Rest::read_naming_settings( string $type ): array` → `{ type, has_series, prefix, suffix, padding, reset_number_yearly, filename_template }` read from `woi_pdf_documents_settings_{type}` (next_number is added by the route handler, which needs a document instance).
- Produces (PHP helper, static on `Rest`): `Rest::merge_naming_settings( array $existing, array $incoming, bool $has_series ): array` → the option array to persist (merges `number_format` sub-array, `reset_number_yearly` flag, `filename_template`; never clobbers unrelated keys; ignores numbering fields when `!$has_series`).
- Produces (REST): `GET woi-pdf/v1/document-naming?type={type}` and `POST woi-pdf/v1/document-naming` (body includes `type` + fields), both returning the read shape (POST also returns the persisted `next_number`).

- [ ] **Step 1: Write the failing tests for the pure helpers**

Create `tests/Unit/DocumentNamingRestTest.php`:

```php
<?php
namespace WOI\PDF\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Rest;

class DocumentNamingRestTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_text_field' )->alias( function ( $v ) { return is_string( $v ) ? trim( $v ) : $v; } );
		Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_numbering_types_are_the_four_numbered_docs(): void {
		$this->assertSame(
			array( 'invoice', 'proforma', 'credit-note', 'receipt' ),
			Rest::numbering_types()
		);
	}

	public function test_naming_types_add_packing_slip(): void {
		$this->assertContains( 'packing-slip', Rest::naming_types() );
		$this->assertContains( 'invoice', Rest::naming_types() );
	}

	public function test_read_naming_settings_shape_for_numbered_type(): void {
		Functions\when( 'get_option' )->justReturn( array(
			'number_format'       => array( 'prefix' => 'INV-', 'suffix' => '', 'padding' => '6' ),
			'reset_number_yearly' => '1',
			'filename_template'   => 'INV_{order_number}',
		) );
		$s = Rest::read_naming_settings( 'invoice' );
		$this->assertTrue( $s['has_series'] );
		$this->assertSame( 'INV-', $s['prefix'] );
		$this->assertSame( '6', (string) $s['padding'] );
		$this->assertTrue( $s['reset_number_yearly'] );
		$this->assertSame( 'INV_{order_number}', $s['filename_template'] );
	}

	public function test_read_naming_settings_packing_slip_has_no_series(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$s = Rest::read_naming_settings( 'packing-slip' );
		$this->assertFalse( $s['has_series'] );
		$this->assertSame( '', $s['filename_template'] );
	}

	public function test_merge_preserves_unrelated_keys_and_sets_number_format(): void {
		$existing = array( 'enabled' => '1', 'document_title' => 'Tax Invoice' );
		$incoming = array(
			'prefix'              => 'INV-',
			'suffix'              => '/X',
			'padding'             => '5',
			'reset_number_yearly' => true,
			'filename_template'   => 'INV_{order_number}',
		);
		$merged = Rest::merge_naming_settings( $existing, $incoming, true );
		$this->assertSame( '1', $merged['enabled'] );                 // untouched
		$this->assertSame( 'Tax Invoice', $merged['document_title'] ); // untouched
		$this->assertSame( 'INV-', $merged['number_format']['prefix'] );
		$this->assertSame( '/X', $merged['number_format']['suffix'] );
		$this->assertSame( '5', $merged['number_format']['padding'] );
		$this->assertSame( '1', $merged['reset_number_yearly'] );
		$this->assertSame( 'INV_{order_number}', $merged['filename_template'] );
	}

	public function test_merge_unchecked_reset_removes_key(): void {
		$existing = array( 'reset_number_yearly' => '1' );
		$merged   = Rest::merge_naming_settings( $existing, array( 'reset_number_yearly' => false ), true );
		$this->assertArrayNotHasKey( 'reset_number_yearly', $merged );
	}

	public function test_merge_packing_slip_ignores_number_fields(): void {
		$merged = Rest::merge_naming_settings(
			array(),
			array( 'prefix' => 'X', 'padding' => '4', 'reset_number_yearly' => true, 'filename_template' => 'PS_{order_number}' ),
			false // no series
		);
		$this->assertArrayNotHasKey( 'number_format', $merged );
		$this->assertArrayNotHasKey( 'reset_number_yearly', $merged );
		$this->assertSame( 'PS_{order_number}', $merged['filename_template'] );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php tests/Unit/DocumentNamingRestTest.php`
Expected: FAIL — `Error: Call to undefined method WOI\PDF\Rest::numbering_types()` (and friends).

- [ ] **Step 3: Add the pure helpers to `Rest`**

In `includes/Rest.php`, inside `class Rest`, add these static methods (place them just before `handle_visual_doc_options`):

```php
		/**
		 * Document types that maintain a numbering series.
		 *
		 * @return string[]
		 */
		public static function numbering_types(): array {
			return array( 'invoice', 'proforma', 'credit-note', 'receipt' );
		}

		/**
		 * Document types configurable in the naming/filename panel (numbered
		 * types + packing-slip, which has a filename override but no series).
		 *
		 * @return string[]
		 */
		public static function naming_types(): array {
			return array_merge( self::numbering_types(), array( 'packing-slip' ) );
		}

		/**
		 * Read the naming + filename settings for a type from its PDF settings
		 * option. Does NOT include next_number (that needs a document instance).
		 *
		 * @param string $type
		 * @return array
		 */
		public static function read_naming_settings( string $type ): array {
			$option     = (array) get_option( "woi_pdf_documents_settings_{$type}", array() );
			$has_series = in_array( $type, self::numbering_types(), true );
			$format     = isset( $option['number_format'] ) && is_array( $option['number_format'] ) ? $option['number_format'] : array();

			return array(
				'type'                => $type,
				'has_series'          => $has_series,
				'prefix'              => isset( $format['prefix'] ) ? (string) $format['prefix'] : '',
				'suffix'              => isset( $format['suffix'] ) ? (string) $format['suffix'] : '',
				'padding'             => isset( $format['padding'] ) ? (string) $format['padding'] : '',
				'reset_number_yearly' => ! empty( $option['reset_number_yearly'] ),
				'filename_template'   => isset( $option['filename_template'] ) ? (string) $option['filename_template'] : '',
			);
		}

		/**
		 * Merge incoming naming fields into an existing settings option without
		 * clobbering unrelated keys. Numbering fields are ignored when the type
		 * has no series.
		 *
		 * @param array $existing  Current option array.
		 * @param array $incoming  Sanitized incoming fields.
		 * @param bool  $has_series
		 * @return array
		 */
		public static function merge_naming_settings( array $existing, array $incoming, bool $has_series ): array {
			$merged = $existing;

			// Filename override applies to every naming type.
			$merged['filename_template'] = isset( $incoming['filename_template'] ) ? (string) $incoming['filename_template'] : '';

			if ( $has_series ) {
				$merged['number_format'] = array(
					'prefix'  => isset( $incoming['prefix'] ) ? (string) $incoming['prefix'] : '',
					'suffix'  => isset( $incoming['suffix'] ) ? (string) $incoming['suffix'] : '',
					'padding' => isset( $incoming['padding'] ) ? (string) $incoming['padding'] : '',
				);
				// Stored as a checkbox: present '1' when on, absent when off.
				if ( ! empty( $incoming['reset_number_yearly'] ) ) {
					$merged['reset_number_yearly'] = '1';
				} else {
					unset( $merged['reset_number_yearly'] );
				}
			}

			return $merged;
		}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php tests/Unit/DocumentNamingRestTest.php`
Expected: PASS.

- [ ] **Step 5: Register the routes**

In `includes/Rest.php`, inside `register_visual_template_route()` (the ALWAYS-ON method, NOT `rest_api_init()`), immediately after the `editor-config` `register_rest_route(...)` block closes (~`:148`) and before the method's closing `}` (~`:149`), add:

```php
			register_rest_route( 'woi-pdf/v1', '/document-naming', array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'handle_get_document_naming' ),
					'permission_callback' => function () { return current_user_can( 'manage_woocommerce' ); },
					'args'                => array(
						'type' => array( 'type' => 'string', 'required' => true ),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle_save_document_naming' ),
					'permission_callback' => function () { return current_user_can( 'manage_woocommerce' ); },
					'args'                => array(
						'type' => array( 'type' => 'string', 'required' => true ),
					),
				),
			) );
```

- [ ] **Step 6: Add the route handlers**

In `includes/Rest.php`, add these methods next to the static helpers from Step 3:

```php
		/**
		 * GET: read naming + filename settings (incl. live next_number) for a type.
		 *
		 * @param object $request
		 * @return array|\WP_Error
		 */
		public function handle_get_document_naming( $request ) {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return new \WP_Error( 'forbidden', 'Insufficient permissions', array( 'status' => 403 ) );
			}
			$type = sanitize_text_field( (string) $request->get_param( 'type' ) );
			if ( ! in_array( $type, self::naming_types(), true ) ) {
				return new \WP_Error( 'invalid_type', 'Unknown document type', array( 'status' => 400 ) );
			}

			$data               = self::read_naming_settings( $type );
			$data['next_number'] = $data['has_series'] ? $this->read_next_number( $type ) : null;

			return $data;
		}

		/**
		 * POST: persist naming + filename settings for a type, including the
		 * sequential next_number (written through the document's number store).
		 *
		 * @param object $request
		 * @return array|\WP_Error
		 */
		public function handle_save_document_naming( $request ) {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return new \WP_Error( 'forbidden', 'Insufficient permissions', array( 'status' => 403 ) );
			}
			$type = sanitize_text_field( (string) $request->get_param( 'type' ) );
			if ( ! in_array( $type, self::naming_types(), true ) ) {
				return new \WP_Error( 'invalid_type', 'Unknown document type', array( 'status' => 400 ) );
			}
			$has_series = in_array( $type, self::numbering_types(), true );

			$incoming = array(
				'prefix'              => sanitize_text_field( (string) $request->get_param( 'prefix' ) ),
				'suffix'              => sanitize_text_field( (string) $request->get_param( 'suffix' ) ),
				'padding'             => sanitize_text_field( (string) $request->get_param( 'padding' ) ),
				'reset_number_yearly' => (bool) $request->get_param( 'reset_number_yearly' ),
				'filename_template'   => sanitize_text_field( (string) $request->get_param( 'filename_template' ) ),
			);

			$existing = (array) get_option( "woi_pdf_documents_settings_{$type}", array() );
			$merged   = self::merge_naming_settings( $existing, $incoming, $has_series );
			update_option( "woi_pdf_documents_settings_{$type}", $merged );

			// next_number is sequential-store state, not an option key. Write it
			// through the document's own store (same mechanism as the classic
			// next_number_edit save) AFTER the option update so the store name
			// reflects the just-saved reset_number_yearly flag.
			if ( $has_series ) {
				$next = absint( $request->get_param( 'next_number' ) );
				if ( $next > 0 ) {
					$this->write_next_number( $type, $next );
				}
			}

			$data                = self::read_naming_settings( $type );
			$data['next_number'] = $has_series ? $this->read_next_number( $type ) : null;
			return $data;
		}

		/**
		 * Read the next sequential number for a type from its number store.
		 *
		 * @param string $type
		 * @return int
		 */
		private function read_next_number( string $type ): int {
			$document = $this->get_naming_document( $type );
			if ( ! $document || ! is_callable( array( $document, 'get_sequential_number_store' ) ) ) {
				return 0;
			}
			return (int) $document->get_sequential_number_store()->get_next();
		}

		/**
		 * Write the next sequential number for a type to its number store.
		 *
		 * @param string $type
		 * @param int    $number
		 * @return void
		 */
		private function write_next_number( string $type, int $number ): void {
			$document = $this->get_naming_document( $type );
			if ( $document && is_callable( array( $document, 'get_sequential_number_store' ) ) ) {
				$document->get_sequential_number_store()->set_next( $number );
			}
		}

		/**
		 * Get an orderless document instance for a numbered type (loads its
		 * settings so the store name reflects reset_number_yearly).
		 *
		 * @param string $type
		 * @return object|false
		 */
		private function get_naming_document( string $type ) {
			if ( ! function_exists( 'WOI_PDF' ) || empty( WOI_PDF()->documents ) ) {
				return false;
			}
			// Orderless instance: get_document( $type, $order ) requires the second
			// arg; null yields an orderless document whose settings load from the
			// per-type option (enough for the sequential-number store).
			return WOI_PDF()->documents->get_document( $type, null );
		}
```

- [ ] **Step 7: Verify lint + helper tests still pass**

Run: `php -l includes/Rest.php`
Expected: `No syntax errors detected`.

Run: `php vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php tests/Unit/DocumentNamingRestTest.php`
Expected: PASS (route handlers aren't unit-tested here — they're thin wrappers over the tested helpers + the existing store API; verified via manual smoke in Task 6).

- [ ] **Step 8: Commit**

```bash
cd "C:/Users/asifm/source/repos/woi-document-naming-series"
git add includes/Rest.php tests/Unit/DocumentNamingRestTest.php
git commit -m "feat(rest): /document-naming endpoint fronting per-type option + number store

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 5: Block-editor naming model (pure JS) + Jest

**Files:**
- Create: `src/block-editor/namingModel.js`
- Create: `src/block-editor/namingModel.test.js`

**Interfaces:**
- Produces: `NAMING_TYPES` — array of `{ value, label, hasSeries }` for the switcher (`invoice`, `proforma`, `credit-note`, `receipt` with `hasSeries:true`; `packing-slip` with `hasSeries:false`).
- Produces: `hasSeries( type ): boolean`.
- Produces: `buildNamingPayload( type, state ): object` — `{ type, filename_template, ... }`; numbering fields (`prefix`,`suffix`,`padding`,`reset_number_yearly`,`next_number`) included ONLY when `hasSeries(type)`.
- Produces: `FILENAME_TOKENS` — array of token strings for the help hint.

- [ ] **Step 1: Write the failing test**

Create `src/block-editor/namingModel.test.js`:

```js
import {
	NAMING_TYPES,
	hasSeries,
	buildNamingPayload,
	FILENAME_TOKENS,
} from './namingModel';

describe( 'namingModel', () => {
	test( 'NAMING_TYPES lists five types with correct series flags', () => {
		const byValue = Object.fromEntries( NAMING_TYPES.map( ( t ) => [ t.value, t ] ) );
		expect( Object.keys( byValue ).sort() ).toEqual(
			[ 'credit-note', 'invoice', 'packing-slip', 'proforma', 'receipt' ]
		);
		expect( byValue.invoice.hasSeries ).toBe( true );
		expect( byValue[ 'packing-slip' ].hasSeries ).toBe( false );
	} );

	test( 'hasSeries reflects the type', () => {
		expect( hasSeries( 'invoice' ) ).toBe( true );
		expect( hasSeries( 'packing-slip' ) ).toBe( false );
		expect( hasSeries( 'nonsense' ) ).toBe( false );
	} );

	test( 'buildNamingPayload includes numbering fields for a numbered type', () => {
		const state = {
			prefix: 'INV-', suffix: '', padding: '6',
			reset_number_yearly: true, next_number: 42,
			filename_template: 'INV_{order_number}',
		};
		expect( buildNamingPayload( 'invoice', state ) ).toEqual( {
			type: 'invoice',
			prefix: 'INV-', suffix: '', padding: '6',
			reset_number_yearly: true, next_number: 42,
			filename_template: 'INV_{order_number}',
		} );
	} );

	test( 'buildNamingPayload omits numbering fields for packing-slip', () => {
		const state = {
			prefix: 'X', padding: '4', reset_number_yearly: true,
			next_number: 9, filename_template: 'PS_{order_number}',
		};
		expect( buildNamingPayload( 'packing-slip', state ) ).toEqual( {
			type: 'packing-slip',
			filename_template: 'PS_{order_number}',
		} );
	} );

	test( 'FILENAME_TOKENS includes the new sequence token', () => {
		expect( FILENAME_TOKENS ).toContain( '{document_number_sequence}' );
		expect( FILENAME_TOKENS ).toContain( '{document_number}' );
	} );
} );
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npm run test:unit -- namingModel`
Expected: FAIL — cannot find module `./namingModel`.

- [ ] **Step 3: Implement `namingModel.js`**

Create `src/block-editor/namingModel.js`:

```js
import { __ } from '@wordpress/i18n';

export const NAMING_TYPES = [
	{ value: 'invoice', label: __( 'Invoice', 'woocommerce-orders-invoice-pdf' ), hasSeries: true },
	{ value: 'proforma', label: __( 'Proforma', 'woocommerce-orders-invoice-pdf' ), hasSeries: true },
	{ value: 'credit-note', label: __( 'Credit note', 'woocommerce-orders-invoice-pdf' ), hasSeries: true },
	{ value: 'receipt', label: __( 'Receipt', 'woocommerce-orders-invoice-pdf' ), hasSeries: true },
	{ value: 'packing-slip', label: __( 'Packing slip', 'woocommerce-orders-invoice-pdf' ), hasSeries: false },
];

export const FILENAME_TOKENS = [
	'{document_type}',
	'{order_number}',
	'{document_number}',
	'{document_number_sequence}',
	'{date}',
];

export function hasSeries( type ) {
	const found = NAMING_TYPES.find( ( t ) => t.value === type );
	return !! ( found && found.hasSeries );
}

export function buildNamingPayload( type, state ) {
	const payload = {
		type,
		filename_template: state.filename_template || '',
	};
	if ( hasSeries( type ) ) {
		payload.prefix = state.prefix || '';
		payload.suffix = state.suffix || '';
		// Nullish coalescing: '0' is a valid "no padding" value and must survive.
		payload.padding = state.padding ?? '';
		payload.reset_number_yearly = !! state.reset_number_yearly;
		payload.next_number = state.next_number;
	}
	return payload;
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `npm run test:unit -- namingModel`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd "C:/Users/asifm/source/repos/woi-document-naming-series"
git add src/block-editor/namingModel.js src/block-editor/namingModel.test.js
git commit -m "feat(block-editor): naming model (types, series flag, payload builder)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 6: Block-editor NamingPanel + store wiring

**Files:**
- Modify: `src/block-editor/store.js` (add `getDocumentNaming`, `saveDocumentNaming`)
- Create: `src/block-editor/NamingPanel.js`
- Modify: `src/block-editor/index.js` (import + render `NamingPanel` in the Document sidebar)

**Interfaces:**
- Consumes: `getDocumentNaming( type )` → GET `document-naming?type=…`; `saveDocumentNaming( payload )` → POST `document-naming`. Both return the read shape `{ type, has_series, prefix, suffix, padding, reset_number_yearly, filename_template, next_number }`.
- Consumes: `NAMING_TYPES`, `hasSeries`, `buildNamingPayload`, `FILENAME_TOKENS` from `./namingModel` (Task 5).
- Produces: `<NamingPanel />` — a self-contained sidebar section (no required props).

- [ ] **Step 1: Add store actions**

In `src/block-editor/store.js`, append:

```js
export function getDocumentNaming( type ) {
	return get( `document-naming?type=${ encodeURIComponent( type ) }` );
}

export function saveDocumentNaming( payload ) {
	return post( 'document-naming', payload );
}
```

- [ ] **Step 2: Implement `NamingPanel.js`**

Create `src/block-editor/NamingPanel.js`:

```js
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { SelectControl, TextControl, ToggleControl, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { NAMING_TYPES, hasSeries, buildNamingPayload, FILENAME_TOKENS } from './namingModel';
import { getDocumentNaming, saveDocumentNaming } from './store';

export default function NamingPanel() {
	const [ type, setType ] = useState( 'invoice' );
	const [ values, setValues ] = useState( null ); // null => loading
	const debounceRef = useRef( null );

	// Load the selected type's settings whenever the type changes. The cleanup
	// both ignores a stale in-flight GET (active flag) AND cancels any pending
	// debounced save, so a rapid type switch never POSTs the old type's payload.
	useEffect( () => {
		let active = true;
		setValues( null );
		getDocumentNaming( type )
			.then( ( r ) => { if ( active ) { setValues( r ); } } )
			.catch( () => { if ( active ) { setValues( {} ); } } );
		return () => {
			active = false;
			if ( debounceRef.current ) { clearTimeout( debounceRef.current ); }
		};
	}, [ type ] );

	const persist = useCallback( ( next ) => {
		if ( debounceRef.current ) { clearTimeout( debounceRef.current ); }
		debounceRef.current = setTimeout( () => {
			saveDocumentNaming( buildNamingPayload( type, next ) )
				.then( ( r ) => setValues( r ) )
				.catch( () => {} );
		}, 500 );
	}, [ type ] );

	const onField = useCallback( ( key, value ) => {
		setValues( ( prev ) => {
			const next = { ...( prev || {} ), [ key ]: value };
			persist( next );
			return next;
		} );
	}, [ persist ] );

	const series = hasSeries( type );

	return (
		<div className="woi-naming-panel">
			<SelectControl
				label={ __( 'Document type', 'woocommerce-orders-invoice-pdf' ) }
				value={ type }
				options={ NAMING_TYPES.map( ( t ) => ( { value: t.value, label: t.label } ) ) }
				onChange={ ( v ) => setType( v ) }
				__nextHasNoMarginBottom
			/>

			{ null === values ? (
				<Spinner />
			) : (
				<>
					{ series && (
						<>
							<TextControl
								label={ __( 'Number prefix', 'woocommerce-orders-invoice-pdf' ) }
								value={ values.prefix || '' }
								onChange={ ( v ) => onField( 'prefix', v ) }
								__nextHasNoMarginBottom
							/>
							<TextControl
								label={ __( 'Number suffix', 'woocommerce-orders-invoice-pdf' ) }
								value={ values.suffix || '' }
								onChange={ ( v ) => onField( 'suffix', v ) }
								__nextHasNoMarginBottom
							/>
							<TextControl
								type="number"
								label={ __( 'Padding (digits)', 'woocommerce-orders-invoice-pdf' ) }
								value={ values.padding || '' }
								onChange={ ( v ) => onField( 'padding', v ) }
								__nextHasNoMarginBottom
							/>
							<TextControl
								type="number"
								label={ __( 'Next number', 'woocommerce-orders-invoice-pdf' ) }
								help={ __( 'Setting this lower than the current highest number can create duplicates.', 'woocommerce-orders-invoice-pdf' ) }
								value={ undefined === values.next_number || null === values.next_number ? '' : values.next_number }
								onChange={ ( v ) => onField( 'next_number', v ? parseInt( v, 10 ) : '' ) }
								__nextHasNoMarginBottom
							/>
							<ToggleControl
								label={ __( 'Reset number yearly', 'woocommerce-orders-invoice-pdf' ) }
								checked={ !! values.reset_number_yearly }
								onChange={ ( v ) => onField( 'reset_number_yearly', v ) }
								__nextHasNoMarginBottom
							/>
						</>
					) }

					<TextControl
						label={ __( 'PDF filename override', 'woocommerce-orders-invoice-pdf' ) }
						help={ __( 'Leave blank to use the global template. Tokens: ', 'woocommerce-orders-invoice-pdf' ) + FILENAME_TOKENS.join( ' ' ) }
						value={ values.filename_template || '' }
						onChange={ ( v ) => onField( 'filename_template', v ) }
						__nextHasNoMarginBottom
					/>
				</>
			) }
		</div>
	);
}
```

- [ ] **Step 3: Render the panel in the Document sidebar**

In `src/block-editor/index.js`:

(a) Add to the imports near the other panel imports (after `import CustomCssPanel from './CustomCssPanel';`, `:63`):

```js
import NamingPanel from './NamingPanel';
```

(b) In the Document-tab JSX, add a new section immediately AFTER the `Custom CSS` section block (the `<CustomCssPanel ... />` and its surrounding `insp-sec`, ~`:508-509`):

```jsx
							<div className="insp-sec">{ __( 'Numbering & filename', 'woocommerce-orders-invoice-pdf' ) }</div>
							<NamingPanel />
							<p className="insp-note">
								{ __( 'Sets the numbering series and PDF filename per document type. Shared with the classic settings tabs.', 'woocommerce-orders-invoice-pdf' ) }
							</p>
```

- [ ] **Step 4: Build the bundle and confirm it compiles**

Run: `npm run build`
Expected: webpack completes with no errors; `assets/js/block-editor/index.js` is regenerated. (This build is for compile-verification; the canonical version-stamped rebuild happens at landing — Task 8.)

- [ ] **Step 5: Run the full Jest suite**

Run: `npm run test:unit`
Expected: PASS (no regressions; `namingModel` tests green).

- [ ] **Step 6: Manual smoke (live admin)**

In WP admin → WooCommerce → Block Invoice Template, open the Document settings tab. Verify:
- "Numbering & filename" section renders with a Document-type selector.
- Switching to each numbered type loads its prefix/suffix/padding/next-number/reset and filename override.
- Switching to "Packing slip" hides the numbering fields and shows only the filename override.
- Editing a field and reloading the page persists the value; the SAME value appears in the classic settings tab for that type (and vice-versa).

> If the REST endpoint 404s, confirm the routes are registered in `register_visual_template_route()` (the always-on method that holds `/editor-config` and `/visual-columns`) — NOT in the gated `rest_api_init()`. The Block editor reaches the always-on routes unconditionally.

- [ ] **Step 7: Commit**

```bash
cd "C:/Users/asifm/source/repos/woi-document-naming-series"
git add src/block-editor/store.js src/block-editor/NamingPanel.js src/block-editor/index.js
git commit -m "feat(block-editor): numbering & filename panel wired to /document-naming

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 7: Changelog entry

**Files:**
- Modify: `readme.txt` (changelog section) and/or `CHANGELOG`/`changelog.txt` — match whichever the repo uses (search for the latest version heading `1.5.80`).

**Interfaces:** none (docs only).

- [ ] **Step 1: Locate the changelog**

Run: `git -C "C:/Users/asifm/source/repos/woi-document-naming-series" grep -l "1.5.80" -- '*.txt' 'CHANGELOG*' 'readme*'`
Expected: the file holding the changelog. (If multiple, prefer `readme.txt`'s `== Changelog ==`.)

- [ ] **Step 2: Add an entry under the NEXT version heading**

Add a new version block above the `1.5.80` entry. Use the version chosen at landing (Task 8) — leave a clear placeholder line to fill at landing:

```
= <NEXT_VERSION> =
* Feature: Per-document-type PDF filename override (Block editor + classic tabs).
* Feature: Document numbering series (prefix/suffix/padding/next-number/yearly reset) is now editable in the Block Invoice Template editor for invoice, proforma, credit note and receipt; packing slip gains a filename override.
* Feature: New {document_number_sequence} filename token (raw sequence counter; {document_number} remains the formatted series number).
```

- [ ] **Step 3: Commit**

```bash
cd "C:/Users/asifm/source/repos/woi-document-naming-series"
git add readme.txt
git commit -m "docs: changelog for naming series + per-type filename override

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 8: Land the feature (sync → version bump → build → push)

**Files:**
- Modify: `woocommerce-orders-invoice-pdf.php` (both version strings)
- Modify: `assets/js/block-editor/index.js` (rebuilt bundle)
- Modify: changelog placeholder → real version

**Interfaces:** none.

This task follows CLAUDE.md "Landing a feature" exactly. Do version bump + build LAST, after rebasing.

- [ ] **Step 1: Confirm the working tree is clean and all prior tasks committed**

Run: `git -C "C:/Users/asifm/source/repos/woi-document-naming-series" status --porcelain`
Expected: empty.

- [ ] **Step 2: Sync to latest master (linear history)**

```bash
cd "C:/Users/asifm/source/repos/woi-document-naming-series"
git fetch origin && git rebase origin/master
```
Expected: rebase succeeds (resolve conflicts if any landed in between).

- [ ] **Step 3: Read the TRUE current version**

Run: `git show origin/master:woocommerce-orders-invoice-pdf.php | grep -nE "Version:|public string \$version"`
Expected: two lines with the current version (e.g. `1.5.80`). Choose the next free patch (e.g. `1.5.81`).

- [ ] **Step 4: Bump BOTH version strings**

In `woocommerce-orders-invoice-pdf.php`:
- header `* Version:` (~line 6) → next patch
- `public string $version = '…';` (~line 24) → same next patch

Replace the `<NEXT_VERSION>` placeholder in the changelog (Task 7) with the chosen version.

- [ ] **Step 5: Rebuild the bundle on rebased source**

Run: `npm run build`
Expected: webpack succeeds; `assets/js/block-editor/index.js` updated.

- [ ] **Step 6: Final full test pass**

Run: `php vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php tests/Unit/ && npm run test:unit`
Expected: no NEW failures vs the established baseline; all new tests green.

- [ ] **Step 7: Commit the version bump + build**

```bash
cd "C:/Users/asifm/source/repos/woi-document-naming-series"
git add woocommerce-orders-invoice-pdf.php assets/js/block-editor/ readme.txt
git commit -m "chore: vX.Y.Z + build"
```
(Replace `X.Y.Z` with the chosen version. Stage explicitly — do NOT `git add -A`: fresh worktrees show ~800 spurious Strauss-prefixed files.)

- [ ] **Step 8: Fast-forward push**

```bash
git push origin HEAD:master
```
If rejected (someone landed in between): `git fetch && git rebase origin/master`, re-bump to the next free patch, `npm run build`, re-commit, push again. Never `--force`.

- [ ] **Step 9: Pull in the main checkout**

```bash
git -C "C:/Users/asifm/source/repos/woocommerce-orders-invoice-pdf" pull --ff-only origin master
```

---

## Self-Review

**Spec coverage:**
- Per-type filename override (global default + per-type) → Task 1 (builder chain) + Task 3 (classic field) + Task 4/6 (Block-editor field). ✓
- `{document_number_sequence}` token → Task 1 (substitution) + Task 2 (callers pass raw counter). ✓
- Surface numbering series in Block editor (all numbered types via switcher) → Task 4 (REST) + Task 5/6 (model + panel). ✓
- Shared storage (same per-type option + sequential store, no sync) → Task 4 reads/writes `woi_pdf_documents_settings_{type}` and the document's own store. ✓
- Packing slip = filename only, no series → `naming_types()` vs `numbering_types()`; `hasSeries` hides numbering fields (Tasks 4–6). ✓
- Next-number editing via the store → Task 4 `read_next_number`/`write_next_number`. ✓
- Type matrix (invoice/proforma/credit-note/receipt numbered; packing-slip filename-only; summary excluded) → enforced by the two type lists. ✓
- Backward compatibility (no override = today's behavior) → builder falls through to global/default; existing FilenameBuilderTest stays green. ✓
- Versioning (both strings + rebuild, at landing) → Task 8. ✓
- Testing (PHPUnit builder + REST helpers; Jest model) → Tasks 1, 4, 5. ✓

**Placeholder scan:** No TBD/TODO/"handle edge cases" — all steps carry concrete code/commands. The only intentional placeholder is `<NEXT_VERSION>` / `X.Y.Z`, which MUST be resolved at landing (Task 8) since the version is a shared, collision-prone value chosen last. ✓

**Type consistency:**
- `woi_pdf_get_filename_settings( string $type = '' )` — defined Task 1, called by `woi_pdf_build_filename` Task 1. ✓
- `woi_pdf_document_number_sequence( $document_number ): string` — defined Task 2, used by all `get_filename()` Task 2. ✓
- `Rest::numbering_types()`, `naming_types()`, `read_naming_settings()`, `merge_naming_settings()` — defined + tested Task 4, consumed by route handlers Task 4. ✓
- JS `NAMING_TYPES`/`hasSeries`/`buildNamingPayload`/`FILENAME_TOKENS` — defined Task 5, consumed by `NamingPanel` Task 6. ✓
- `getDocumentNaming`/`saveDocumentNaming` — defined Task 6 store, consumed by `NamingPanel` Task 6. ✓
- REST read shape keys (`has_series`, `next_number`, `filename_template`, …) consistent between PHP (`read_naming_settings` + handlers) and JS (`NamingPanel` field bindings). ✓

**Known nuance flagged for implementer:** the REST routes register inside `register_visual_template_route()` — the ALWAYS-ON method (hooked unconditionally in the constructor) that already holds `/visual-columns` and `/editor-config`. `rest_api_init()` is a separate method gated by the debug `enable_rest_api` flag; do NOT register the new routes there, or the Block editor's NamingPanel would 404 for users without that flag.
