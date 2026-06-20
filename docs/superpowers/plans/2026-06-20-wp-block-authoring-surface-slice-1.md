# WP Block Authoring Surface — Slice 1 (Pipeline Spine) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up the end-to-end spine so a design authored in a new WordPress block editor produces a correct invoice PDF through the existing token/mPDF pipeline, selectable via an active-source flag — without changing GrapesJS.

**Architecture:** A new `@wordpress/block-editor` admin page serializes block markup and POSTs it to a new REST route, which renders it to HTML+`{{tokens}}` (`do_blocks` → kses) and stores it. A resolver on `VisualTemplateStore` picks the active source's rendered HTML at render time; the one read in `OrderDocument::get_html()` switches from `get()` to `get_active()`. Default active source is `grapesjs`, so existing installs are unaffected until a user opts into blocks.

**Tech Stack:** PHP 7.4 (WordPress/WooCommerce plugin), `@wordpress/scripts` 30 (wp-scripts/webpack), `@wordpress/block-editor` + `@wordpress/blocks` + `@wordpress/data`, PHPUnit 9 with Brain Monkey, mPDF (vendored via Strauss).

**Scope note:** This plan covers **Slice 1 only** (the spine). The full custom block set (Slice 2), preview parity (Slice 3), and polish (Slice 4) follow as their own plans after Slice 1 ships — mirroring how the GrapesJS feature was built slice-by-slice. Slice 1 ships behind the existing `enable_visual_template_invoice` toggle with the default source `grapesjs`, so it is independently releasable.

## Global Constraints

- **Invoice-only.** Every visual path is gated to `doc_type === 'invoice'` (matches GrapesJS).
- **Render engine stays mPDF.** Do not touch the maker/wrapper/token system.
- **Default active source is `'grapesjs'`** — existing behaviour must be byte-identical until a user explicitly switches.
- **Options are unautoloaded:** always `update_option( $name, $value, false )`.
- **Sanitize on store** with the existing `VisualTemplateStore::allowed_html()` kses allowlist; `{{tokens}}` must survive kses untouched.
- **Bump the version on every JS/CSS change.** `public string $version` in `woocommerce-orders-invoice-pdf.php` (line 24) drives `WOI_PDF_VERSION` and the asset cache-bust query string; the header `Version:` comment (line 6) must match it. They are currently out of sync (header `1.4.32`, property `1.4.31`) — set BOTH to the same new value when bumping.
- **Run PHPUnit as:** `php -d display_errors=1 -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit`. NEVER `vendor/bin/phpunit` (its Windows shim mishandles `-d auto_prepend` and reports false errors).
- **PHP namespace** for new server code is `WOI\PDF\Visual`. **JS block namespace** is `woi/`.

---

## File Structure

**Create:**
- `src/block-editor/index.js` — mounts the block editor, registers Slice-1 blocks, wires Save + active-source switch.
- `src/block-editor/blocks/text.js` — editable rich-text block (`woi/text`).
- `src/block-editor/blocks/token.js` — factory that registers the Slice-1 token blocks (`woi/shop-name`, `woi/line-items`, `woi/totals`).
- `src/block-editor/store.js` — small REST client (save markup, set active source).
- `includes/Visual/BlockEditorPage.php` — admin page + settings tab + asset enqueue (parallels `VisualEditorPage`).
- `includes/Visual/Blocks.php` — server-side `register_block_type` for the Slice-1 blocks so `do_blocks()` renders them canonically.
- `tests/Unit/Visual/VisualActiveSourceTest.php` — resolver + active-source option tests.
- `tests/Unit/Visual/VisualBlocksRestTest.php` — block-save REST handler tests.
- `tests/Unit/Visual/BlockEditorPageNoticesTest.php` — page screen-gating test (parallels `VisualEditorNoticesTest`).

**Modify:**
- `includes/Visual/VisualTemplateStore.php` — add blocks options, active-source option, `get_active()`.
- `includes/Documents/OrderDocument.php:1813` — `get()` → `get_active()`.
- `includes/Rest.php` — extend `register_visual_template_route()` with two routes; add two handlers + a `render_blocks()` seam.
- `includes/Main.php:122` — instantiate `BlockEditorPage`.
- `package.json` — build both `src/home` and `src/block-editor` entries.
- `woocommerce-orders-invoice-pdf.php` — version bump (header + property).

---

## Task 1: Storage — blocks options, active source, and resolver

**Files:**
- Modify: `includes/Visual/VisualTemplateStore.php`
- Test: `tests/Unit/Visual/VisualActiveSourceTest.php` (create)

**Interfaces:**
- Consumes: existing `VisualTemplateStore::get()`, `option_name()`, `allowed_html()`.
- Produces:
  - `blocks_markup_option_name(string $doc_type): string` → `woi_pdf_visual_blocks_<type>`
  - `blocks_html_option_name(string $doc_type): string` → `woi_pdf_visual_blocks_html_<type>`
  - `active_source_option_name(): string` → `woi_pdf_visual_active_source`
  - `get_blocks_markup(string $doc_type): string`
  - `get_blocks_html(string $doc_type): string`
  - `save_blocks(string $doc_type, string $markup, string $rendered_html): void` (kses's the rendered HTML; stores both, unautoloaded)
  - `get_active_source(): string` (`'grapesjs'` default; only ever returns `'grapesjs'`|`'blocks'`)
  - `set_active_source(string $source): void` (ignores values other than the two valid ones)
  - `get_active(string $doc_type): string` (returns the rendered HTML for the active source)

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Visual/VisualActiveSourceTest.php`:

```php
<?php
namespace WOI\PDF\Tests\Unit\Visual;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Visual\VisualTemplateStore;

class VisualActiveSourceTest extends TestCase {

    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    public function test_option_names_are_namespaced(): void {
        $store = new VisualTemplateStore();
        $this->assertSame( 'woi_pdf_visual_blocks_invoice', $store->blocks_markup_option_name( 'invoice' ) );
        $this->assertSame( 'woi_pdf_visual_blocks_html_invoice', $store->blocks_html_option_name( 'invoice' ) );
        $this->assertSame( 'woi_pdf_visual_active_source', $store->active_source_option_name() );
    }

    public function test_active_source_defaults_to_grapesjs(): void {
        Functions\when( 'get_option' )->justReturn( false );
        $this->assertSame( 'grapesjs', ( new VisualTemplateStore() )->get_active_source() );
    }

    public function test_active_source_rejects_unknown_values(): void {
        $stored = array();
        Functions\when( 'update_option' )->alias( function ( $n, $v ) use ( &$stored ) { $stored[ $n ] = $v; return true; } );
        $store = new VisualTemplateStore();
        $store->set_active_source( 'nonsense' );
        $this->assertArrayNotHasKey( 'woi_pdf_visual_active_source', $stored );
        $store->set_active_source( 'blocks' );
        $this->assertSame( 'blocks', $stored['woi_pdf_visual_active_source'] );
    }

    public function test_save_blocks_stores_markup_raw_and_html_through_kses_unautoloaded(): void {
        $captured = array();
        Functions\when( 'wp_kses' )->returnArg( 1 ); // passthrough proves no pre-mangling
        Functions\when( 'update_option' )->alias(
            function ( $name, $value, $autoload ) use ( &$captured ) { $captured[ $name ] = array( $value, $autoload ); return true; }
        );
        $store = new VisualTemplateStore();
        $store->save_blocks( 'invoice', '<!-- wp:woi/shop-name -->{{shop_name}}<!-- /wp:woi/shop-name -->', '<p>{{shop_name}}</p>' );

        $this->assertArrayHasKey( 'woi_pdf_visual_blocks_invoice', $captured );
        $this->assertArrayHasKey( 'woi_pdf_visual_blocks_html_invoice', $captured );
        $this->assertStringContainsString( '<!-- wp:woi/shop-name -->', $captured['woi_pdf_visual_blocks_invoice'][0] );
        $this->assertStringContainsString( '{{shop_name}}', $captured['woi_pdf_visual_blocks_html_invoice'][0] );
        $this->assertFalse( $captured['woi_pdf_visual_blocks_invoice'][1] );  // unautoloaded
        $this->assertFalse( $captured['woi_pdf_visual_blocks_html_invoice'][1] );
    }

    public function test_get_active_returns_grapesjs_html_by_default(): void {
        Functions\when( 'get_option' )->alias( function ( $name ) {
            if ( 'woi_pdf_visual_active_source' === $name ) { return 'grapesjs'; }
            if ( 'woi_pdf_visual_template_invoice' === $name ) { return '<p>grapes</p>'; }
            return false;
        } );
        $this->assertSame( '<p>grapes</p>', ( new VisualTemplateStore() )->get_active( 'invoice' ) );
    }

    public function test_get_active_returns_blocks_html_when_blocks_active(): void {
        Functions\when( 'get_option' )->alias( function ( $name ) {
            if ( 'woi_pdf_visual_active_source' === $name ) { return 'blocks'; }
            if ( 'woi_pdf_visual_blocks_html_invoice' === $name ) { return '<p>blocks</p>'; }
            return false;
        } );
        $this->assertSame( '<p>blocks</p>', ( new VisualTemplateStore() )->get_active( 'invoice' ) );
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php -d display_errors=1 -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit --filter VisualActiveSourceTest`
Expected: FAIL — `Error: Call to undefined method ...::blocks_markup_option_name()` (methods not defined yet).

- [ ] **Step 3: Implement the methods**

In `includes/Visual/VisualTemplateStore.php`, add these methods inside the class (after `get()`/`save()`, before `allowed_html()`):

```php
    public function blocks_markup_option_name( string $doc_type ): string {
        return 'woi_pdf_visual_blocks_' . preg_replace( '/[^a-z0-9_]/', '', $doc_type );
    }

    public function blocks_html_option_name( string $doc_type ): string {
        return 'woi_pdf_visual_blocks_html_' . preg_replace( '/[^a-z0-9_]/', '', $doc_type );
    }

    public function active_source_option_name(): string {
        return 'woi_pdf_visual_active_source';
    }

    public function get_blocks_markup( string $doc_type ): string {
        $stored = get_option( $this->blocks_markup_option_name( $doc_type ) );
        return is_string( $stored ) ? $stored : '';
    }

    public function get_blocks_html( string $doc_type ): string {
        $stored = get_option( $this->blocks_html_option_name( $doc_type ) );
        return is_string( $stored ) ? $stored : '';
    }

    /**
     * Store both the round-trip block markup (raw) and the rendered HTML
     * (kses-cleaned, tokens preserved). Both unautoloaded.
     */
    public function save_blocks( string $doc_type, string $markup, string $rendered_html ): void {
        update_option( $this->blocks_markup_option_name( $doc_type ), $markup, false );
        $clean = wp_kses( $rendered_html, $this->allowed_html() );
        update_option( $this->blocks_html_option_name( $doc_type ), $clean, false );
    }

    /** 'grapesjs' (default) or 'blocks'. */
    public function get_active_source(): string {
        $source = get_option( $this->active_source_option_name() );
        return ( 'blocks' === $source ) ? 'blocks' : 'grapesjs';
    }

    /** Silently ignores anything other than the two valid sources. */
    public function set_active_source( string $source ): void {
        if ( 'grapesjs' === $source || 'blocks' === $source ) {
            update_option( $this->active_source_option_name(), $source, false );
        }
    }

    /** Rendered HTML for whichever source is active (what the render path consumes). */
    public function get_active( string $doc_type ): string {
        return ( 'blocks' === $this->get_active_source() )
            ? $this->get_blocks_html( $doc_type )
            : $this->get( $doc_type );
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php -d display_errors=1 -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit --filter VisualActiveSourceTest`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add includes/Visual/VisualTemplateStore.php tests/Unit/Visual/VisualActiveSourceTest.php
git commit -m "feat(visual): blocks storage options + active-source resolver"
```

---

## Task 2: Render path resolves the active source

**Files:**
- Modify: `includes/Documents/OrderDocument.php` (~line 1813)
- Test: `tests/Unit/Visual/VisualRenderPathTest.php` (existing — add a case)

**Interfaces:**
- Consumes: `VisualTemplateStore::get_active()` from Task 1.
- Produces: no new symbols; the invoice render branch now reads the active source's HTML.

> Why no `OrderDocument` unit test: `OrderDocument` is a heavy class the suite deliberately does not instantiate (the existing `VisualRenderPathTest` only exercises the pure `visual_template_active()` function). So Task 2 is a one-line, behavior-preserving swap whose safety net is (a) the resolver parity test below, (b) the full suite staying green, and (c) the live source-switch acceptance in Task 7 Step 4.6.

- [ ] **Step 1: Write the resolver parity test**

The risk in the swap is that the **default** source must behave exactly as `get()` did. Add this case to `tests/Unit/Visual/VisualRenderPathTest.php` (it needs the Brain Monkey imports — add `use Brain\Monkey\Functions;` at the top alongside the existing `use Brain\Monkey;`):

```php
    public function test_get_active_default_source_matches_legacy_get(): void {
        // Default (no active-source option set) must return the GrapesJS HTML,
        // identical to the legacy get() the render branch used before the swap.
        Functions\when( 'get_option' )->alias( function ( $name ) {
            if ( 'woi_pdf_visual_active_source' === $name ) { return false; } // unset → default
            if ( 'woi_pdf_visual_template_invoice' === $name ) { return '<p>legacy</p>'; }
            return false;
        } );
        $store = new \WOI\PDF\Visual\VisualTemplateStore();
        $this->assertSame( $store->get( 'invoice' ), $store->get_active( 'invoice' ) );
        $this->assertSame( '<p>legacy</p>', $store->get_active( 'invoice' ) );
    }
```

- [ ] **Step 2: Run test to verify it passes (resolver already exists from Task 1)**

Run: `php -d display_errors=1 -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit --filter VisualRenderPathTest`
Expected: PASS — confirms default-source parity before touching `OrderDocument`. (If Task 1 is not yet merged in this worktree, this FAILs with undefined `get_active` — implement Task 1 first.)

- [ ] **Step 3: Make the one-line change**

In `includes/Documents/OrderDocument.php`, line ~1813, change:

```php
			$stored  = $store->get( $this->get_type() );
```
to:
```php
			$stored  = $store->get_active( $this->get_type() );
```

Also update the adjacent comment (lines ~1809–1810) from "render from the GrapesJS-designed HTML" to "render from the active visual source's HTML (GrapesJS or block editor)".

- [ ] **Step 4: Confirm the swap with a grep**

Run: `grep -n "get_active( \$this->get_type() )" includes/Documents/OrderDocument.php`
Expected: one match at line ~1813 (proves `get()` → `get_active()` landed).

- [ ] **Step 5: Run the full Visual suite to verify no regression**

Run: `php -d display_errors=1 -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit --filter Visual`
Expected: PASS (all Visual tests green; default-source path unchanged).

- [ ] **Step 6: Commit**

```bash
git add includes/Documents/OrderDocument.php tests/Unit/Visual/VisualRenderPathTest.php
git commit -m "feat(visual): render path resolves active template source"
```

---

## Task 3: REST — save blocks (render→kses→store) and set active source

**Files:**
- Modify: `includes/Rest.php` (extend `register_visual_template_route()`; add handlers + a `render_blocks()` seam)
- Test: `tests/Unit/Visual/VisualBlocksRestTest.php` (create)

**Interfaces:**
- Consumes: `VisualTemplateStore::save_blocks()`, `set_active_source()` (Task 1).
- Produces (on the `Rest` class):
  - `handle_visual_blocks_save($request): array|\WP_Error` — params `doc_type` (string), `markup` (string); renders markup via `render_blocks()`, stores both via `save_blocks()`, returns `array('saved'=>true)`.
  - `handle_visual_active_source($request): array|\WP_Error` — param `source` (string); calls `set_active_source()`, returns `array('source'=>$resolved)`.
  - `protected function render_blocks(string $markup): string` — wraps `do_blocks()` (a seam so the handler is unit-testable without WP block registration).
  - Routes: `POST woi-pdf/v1/visual-blocks`, `POST woi-pdf/v1/visual-active-source`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Visual/VisualBlocksRestTest.php`. The handler is tested via a subclass that overrides `render_blocks()` (so `do_blocks` isn't needed) and stubs the store calls through Brain Monkey:

```php
<?php
namespace WOI\PDF\Tests\Unit\Visual;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Rest;

class VisualBlocksRestTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'wp_kses' )->returnArg( 1 );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'get_option' )->justReturn( false );
    }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    private function request( array $params ) {
        return new class( $params ) {
            public array $p;
            public function __construct( array $p ) { $this->p = $p; }
            public function get_param( $k ) { return $this->p[ $k ] ?? null; }
        };
    }

    public function test_blocks_save_renders_then_stores_both_options(): void {
        $captured = array();
        Functions\when( 'update_option' )->alias( function ( $n, $v ) use ( &$captured ) { $captured[ $n ] = $v; return true; } );

        $rest = new class extends Rest {
            public function __construct() {}                       // skip parent hook wiring
            protected function render_blocks( string $markup ): string { return '<p>{{shop_name}}</p>'; }
        };
        $result = $rest->handle_visual_blocks_save( $this->request( array(
            'doc_type' => 'invoice',
            'markup'   => '<!-- wp:woi/shop-name -->{{shop_name}}<!-- /wp:woi/shop-name -->',
        ) ) );

        $this->assertSame( array( 'saved' => true ), $result );
        $this->assertStringContainsString( '<!-- wp:woi/shop-name -->', $captured['woi_pdf_visual_blocks_invoice'] );
        $this->assertSame( '<p>{{shop_name}}</p>', $captured['woi_pdf_visual_blocks_html_invoice'] );
    }

    public function test_blocks_save_forbidden_without_cap(): void {
        Functions\when( 'current_user_can' )->justReturn( false );
        $rest = new class extends Rest { public function __construct() {} };
        $result = $rest->handle_visual_blocks_save( $this->request( array( 'doc_type' => 'invoice', 'markup' => 'x' ) ) );
        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    public function test_set_active_source_persists_valid_value(): void {
        $captured = array();
        Functions\when( 'update_option' )->alias( function ( $n, $v ) use ( &$captured ) { $captured[ $n ] = $v; return true; } );
        $rest = new class extends Rest { public function __construct() {} };
        $result = $rest->handle_visual_active_source( $this->request( array( 'source' => 'blocks' ) ) );
        $this->assertSame( 'blocks', $captured['woi_pdf_visual_active_source'] );
        $this->assertSame( array( 'source' => 'blocks' ), $result );
    }
}
```

> Note: `WP_Error` is already available to the suite (used by existing `Rest` tests). If `tests/bootstrap.php` does not define it, the existing `VisualRestTest` reveals the pattern to reuse — follow it.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php -d display_errors=1 -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit --filter VisualBlocksRestTest`
Expected: FAIL — `Call to undefined method ...::handle_visual_blocks_save()`.

- [ ] **Step 3: Add the routes**

In `includes/Rest.php`, inside `register_visual_template_route()` (after the existing `visual-preview-data` registration, before the closing `}`), add:

```php
		register_rest_route( 'woi-pdf/v1', '/visual-blocks', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_visual_blocks_save' ),
			'permission_callback' => function () { return current_user_can( 'manage_woocommerce' ); },
			'args'                => array(
				'doc_type' => array( 'type' => 'string', 'required' => true ),
				'markup'   => array( 'type' => 'string', 'required' => true ),
			),
		) );

		register_rest_route( 'woi-pdf/v1', '/visual-active-source', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_visual_active_source' ),
			'permission_callback' => function () { return current_user_can( 'manage_woocommerce' ); },
			'args'                => array(
				'source' => array( 'type' => 'string', 'required' => true ),
			),
		) );
```

- [ ] **Step 4: Add the handlers + seam**

In `includes/Rest.php`, after `handle_visual_template_save()` (before the class-closing `}`), add:

```php
		/**
		 * Render block markup to HTML for the visual render path.
		 * Seam so the save handler is unit-testable without the WP block registry.
		 */
		protected function render_blocks( string $markup ): string {
			return function_exists( 'do_blocks' ) ? do_blocks( $markup ) : $markup;
		}

		/**
		 * Save block markup: render → store markup (raw) + rendered HTML (kses'd).
		 *
		 * @param object $request Request object with get_param().
		 * @return array|\WP_Error
		 */
		public function handle_visual_blocks_save( $request ) {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return new \WP_Error( 'forbidden', 'Insufficient permissions', array( 'status' => 403 ) );
			}
			$doc_type = (string) $request->get_param( 'doc_type' );
			$markup   = (string) $request->get_param( 'markup' );
			$html     = $this->render_blocks( $markup );

			( new \WOI\PDF\Visual\VisualTemplateStore() )->save_blocks( $doc_type, $markup, $html );

			return array( 'saved' => true );
		}

		/**
		 * Set which visual source feeds the PDF.
		 *
		 * @param object $request Request object with get_param().
		 * @return array|\WP_Error
		 */
		public function handle_visual_active_source( $request ) {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return new \WP_Error( 'forbidden', 'Insufficient permissions', array( 'status' => 403 ) );
			}
			$store = new \WOI\PDF\Visual\VisualTemplateStore();
			$store->set_active_source( (string) $request->get_param( 'source' ) );
			return array( 'source' => $store->get_active_source() );
		}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php -d display_errors=1 -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit --filter VisualBlocksRestTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Run the full suite (no regressions)**

Run: `php -d display_errors=1 -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit`
Expected: PASS, 0 errors (1 intentional skip is fine).

- [ ] **Step 7: Commit**

```bash
git add includes/Rest.php tests/Unit/Visual/VisualBlocksRestTest.php
git commit -m "feat(visual): REST routes to save blocks and set active source"
```

---

## Task 4: Server-side block registration (`Blocks.php`)

**Files:**
- Create: `includes/Visual/Blocks.php`
- Modify: `includes/Main.php` (instantiate it)
- Test: covered by live `do_blocks` acceptance (Task 7); no unit test (registration is a thin WP wrapper)

**Interfaces:**
- Produces: `WOI\PDF\Visual\Blocks` with a constructor that hooks `init` → `register()`, registering Slice-1 blocks (`woi/text`, `woi/shop-name`, `woi/line-items`, `woi/totals`) as **static** blocks (no `render_callback`; their inner HTML carries the `{{token}}`). Registration makes `do_blocks()` canonical and gives the inserter its server-known types.

> Why static + no render_callback: the Slice-1 blocks emit fixed markup containing a `{{token}}`; the dynamic value is filled later by `TemplateTokens::merge` at PDF time, NOT at block-render time. `do_blocks()` returns each static block's inner HTML, so the stored rendered HTML keeps the `{{token}}` literally.

- [ ] **Step 1: Create the registrar**

Create `includes/Visual/Blocks.php`:

```php
<?php
namespace WOI\PDF\Visual;

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( '\\WOI\\PDF\\Visual\\Blocks' ) ) :

/**
 * Registers the custom invoice blocks server-side so do_blocks() renders
 * them canonically. Slice 1 ships a minimal set; later slices extend it.
 */
class Blocks {

    /** @var string[] Block names registered for the visual editor. */
    private const NAMES = array( 'woi/text', 'woi/shop-name', 'woi/line-items', 'woi/totals' );

    public function __construct() {
        add_action( 'init', array( $this, 'register' ) );
    }

    public function register(): void {
        if ( ! function_exists( 'register_block_type' ) ) {
            return;
        }
        foreach ( self::NAMES as $name ) {
            if ( \WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
                continue;
            }
            // Static blocks: no render_callback; inner HTML (the {{token}}) passes through.
            register_block_type( $name, array(
                'api_version' => 2,
                'category'    => 'woi-invoice',
            ) );
        }
    }
}

endif;
```

- [ ] **Step 2: Instantiate in Main**

In `includes/Main.php`, in the `if ( is_admin() )` block at line ~121 AND for the front end (do_blocks runs at PDF render, which can be a front-end/cron context), register unconditionally. Change:

```php
		// Visual template editor admin page + Status/diagnostics tab
		if ( is_admin() ) {
			new \WOI\PDF\Visual\VisualEditorPage();
			new \WOI\PDF\Status\StatusTab();
		}
```
to:
```php
		// Custom invoice blocks must be registered in every context (PDF render
		// can run on the front end / cron), so register outside the is_admin gate.
		new \WOI\PDF\Visual\Blocks();

		// Visual template editor admin pages + Status/diagnostics tab
		if ( is_admin() ) {
			new \WOI\PDF\Visual\VisualEditorPage();
			new \WOI\PDF\Status\StatusTab();
		}
```

- [ ] **Step 3: Lint**

Run: `php -l includes/Visual/Blocks.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Full suite still green**

Run: `php -d display_errors=1 -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit`
Expected: PASS, 0 errors.

- [ ] **Step 5: Commit**

```bash
git add includes/Visual/Blocks.php includes/Main.php
git commit -m "feat(visual): server-side registration of slice-1 invoice blocks"
```

---

## Task 5: Build pipeline + `BlockEditorPage` (admin page, tab, enqueue)

**Files:**
- Modify: `package.json` (build both entries)
- Create: `includes/Visual/BlockEditorPage.php`
- Modify: `includes/Main.php` (instantiate)
- Modify: `woocommerce-orders-invoice-pdf.php` (version bump)
- Test: `tests/Unit/Visual/BlockEditorPageNoticesTest.php` (create)

**Interfaces:**
- Consumes: `VisualTemplateStore` (Task 1), `woi_pdf_settings_tabs` filter, the built bundle `assets/js/block-editor/index.js` + generated `index.asset.php`.
- Produces: `WOI\PDF\Visual\BlockEditorPage` paralleling `VisualEditorPage` — page slug `woi-pdf-blocks`, settings tab `blocks`, `enqueue()`, `render_page()`, `is_block_editor_screen()`, `suppress_admin_notices()`.

- [ ] **Step 1: Update the build to compile both entries**

In `package.json`, replace the `scripts` block:

```json
	"scripts": {
		"build": "wp-scripts build src/home/index.js src/block-editor/index.js --output-path=assets/js",
		"start": "wp-scripts start src/home/index.js src/block-editor/index.js --output-path=assets/js"
	},
```

> wp-scripts emits each entry under `assets/js/<entry-dir-name>/` only when given a directory; to keep the existing `assets/js/home/` layout AND add `assets/js/block-editor/`, use webpack multi-entry via a config. Create `webpack.config.js` at repo root:

```js
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		'home/index': path.resolve( __dirname, 'src/home/index.js' ),
		'block-editor/index': path.resolve( __dirname, 'src/block-editor/index.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'assets/js' ),
	},
};
```

And simplify `package.json` scripts to:

```json
	"scripts": {
		"build": "wp-scripts build",
		"start": "wp-scripts start"
	},
```

> This makes wp-scripts pick up `webpack.config.js`, emit `assets/js/home/index.js` (+ `index.asset.php`) and `assets/js/block-editor/index.js` (+ `index.asset.php`), preserving the existing home bundle path.

- [ ] **Step 2: Create the admin page**

Create `includes/Visual/BlockEditorPage.php` (parallels `VisualEditorPage`; trimmed to Slice-1 shell — the full preview pane arrives in Slice 3):

```php
<?php
namespace WOI\PDF\Visual;

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( '\\WOI\\PDF\\Visual\\BlockEditorPage' ) ) :

class BlockEditorPage {

    private const PAGE_SLUG = 'woi-pdf-blocks';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_page' ), 20 );
        add_action( 'admin_head', array( $this, 'hide_standalone_menu_item_css' ) );
        add_action( 'admin_head', array( $this, 'suppress_admin_notices' ), 1 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
        add_filter( 'woi_pdf_settings_tabs', array( $this, 'add_settings_tab' ) );
    }

    public function register_page(): void {
        add_submenu_page(
            'woocommerce',
            __( 'Block Editor', 'woocommerce-orders-invoice-pdf' ),
            __( 'Block Editor', 'woocommerce-orders-invoice-pdf' ),
            'manage_woocommerce',
            self::PAGE_SLUG,
            array( $this, 'render_page' )
        );
    }

    public function hide_standalone_menu_item_css(): void {
        echo '<style id="woi-hide-blocks-menu">'
            . '#adminmenu .wp-submenu a[href$="page=' . esc_attr( self::PAGE_SLUG ) . '"]{display:none}'
            . '#adminmenu .wp-submenu li:has(> a[href$="page=' . esc_attr( self::PAGE_SLUG ) . '"]){display:none}'
            . '</style>';
    }

    public function add_settings_tab( $tabs ) {
        if ( ! is_array( $tabs ) ) { return $tabs; }
        $tabs['blocks'] = array(
            'title' => __( 'Block Editor', 'woocommerce-orders-invoice-pdf' ),
            'href'  => admin_url( 'admin.php?page=' . self::PAGE_SLUG ),
        );
        return $tabs;
    }

    public function enqueue( string $hook ): void {
        if ( false === strpos( $hook, self::PAGE_SLUG ) ) { return; }

        $asset_path = WOI_PDF()->plugin_path() . '/assets/js/block-editor/index.asset.php';
        $asset = is_readable( $asset_path ) ? require $asset_path : array( 'dependencies' => array(), 'version' => WOI_PDF_VERSION );

        wp_enqueue_script(
            'woi-block-editor',
            WOI_PDF()->plugin_url() . '/assets/js/block-editor/index.js',
            $asset['dependencies'],
            $asset['version'],
            true
        );
        // Core block-editor styles for the canvas + components.
        wp_enqueue_style( 'wp-edit-blocks' );
        wp_enqueue_style( 'wp-components' );
        wp_enqueue_style( 'wp-format-library' );

        $store = new VisualTemplateStore();
        wp_localize_script( 'woi-block-editor', 'woiBlocks', array(
            'restUrl'        => esc_url_raw( rest_url( 'woi-pdf/v1' ) ),
            'nonce'          => wp_create_nonce( 'wp_rest' ),
            'docType'        => 'invoice',
            'storedMarkup'   => $store->get_blocks_markup( 'invoice' ),
            'activeSource'   => $store->get_active_source(),
            'backUrl'        => esc_url_raw( admin_url( 'admin.php?page=woi_pdf_options_page' ) ),
        ) );
    }

    public function render_page(): void {
        echo '<div class="wrap"><h1>' . esc_html__( 'Block Invoice Template', 'woocommerce-orders-invoice-pdf' ) . '</h1>';
        echo '<p>' . esc_html__( 'Design the invoice with WordPress blocks. Set this as the active template source to render the PDF from this design. Requires "Visual template (invoice)" enabled in Invoice Settings.', 'woocommerce-orders-invoice-pdf' ) . '</p>';
        echo '<div id="woi-block-editor-root"></div>';
        echo '</div>';
    }

    public function is_block_editor_screen(): bool {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        return $screen && isset( $screen->id ) && false !== strpos( (string) $screen->id, self::PAGE_SLUG );
    }

    public function suppress_admin_notices(): void {
        if ( ! $this->is_block_editor_screen() ) { return; }
        remove_all_actions( 'admin_notices' );
        remove_all_actions( 'all_admin_notices' );
        remove_all_actions( 'user_admin_notices' );
    }
}

endif;
```

- [ ] **Step 3: Instantiate in Main**

In `includes/Main.php`, inside the `if ( is_admin() )` block, add after the `VisualEditorPage` line:

```php
			new \WOI\PDF\Visual\BlockEditorPage();
```

- [ ] **Step 4: Write the screen-gating test**

Create `tests/Unit/Visual/BlockEditorPageNoticesTest.php`, mirroring `VisualEditorNoticesTest`:

```php
<?php
namespace WOI\PDF\Tests\Unit\Visual;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Visual\BlockEditorPage;

class BlockEditorPageNoticesTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'add_action' )->justReturn( true );
        Functions\when( 'add_filter' )->justReturn( true );
    }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    private function page(): BlockEditorPage { return new BlockEditorPage(); }

    public function test_suppress_noop_off_screen(): void {
        Functions\when( 'get_current_screen' )->justReturn( (object) array( 'id' => 'edit-shop_order' ) );
        // remove_all_actions must NOT be called off-screen.
        Functions\expect( 'remove_all_actions' )->never();
        $this->page()->suppress_admin_notices();
        $this->assertFalse( $this->page()->is_block_editor_screen() );
    }

    public function test_suppress_runs_on_screen(): void {
        Functions\when( 'get_current_screen' )->justReturn( (object) array( 'id' => 'woocommerce_page_woi-pdf-blocks' ) );
        Functions\expect( 'remove_all_actions' )->atLeast()->once();
        $this->page()->suppress_admin_notices();
        $this->assertTrue( $this->page()->is_block_editor_screen() );
    }
}
```

> If `VisualEditorNoticesTest` stubs constructor hooks differently, copy its exact setup so construction doesn't fatal.

- [ ] **Step 5: Run the test to verify it fails, then (after Step 2/3 exist) passes**

Run: `php -d display_errors=1 -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit --filter BlockEditorPageNoticesTest`
Expected: PASS (2 tests) once `BlockEditorPage` exists. (If you wrote the test before the class, it FAILs first with class-not-found.)

- [ ] **Step 6: Bump the version (cache-bust)**

In `woocommerce-orders-invoice-pdf.php`: set line 6 `* Version:` and line 24 `public string $version` both to `1.5.0`.

- [ ] **Step 7: Lint + full suite**

Run: `php -l includes/Visual/BlockEditorPage.php && php -d display_errors=1 -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit`
Expected: no syntax errors; suite PASS, 0 errors.

- [ ] **Step 8: Commit**

```bash
git add package.json webpack.config.js includes/Visual/BlockEditorPage.php includes/Main.php tests/Unit/Visual/BlockEditorPageNoticesTest.php woocommerce-orders-invoice-pdf.php
git commit -m "feat(visual): block editor admin page, settings tab, dual build pipeline"
```

---

## Task 6: Block editor front-end (canvas, Slice-1 blocks, save, active-source switch)

**Files:**
- Create: `src/block-editor/index.js`, `src/block-editor/blocks/text.js`, `src/block-editor/blocks/token.js`, `src/block-editor/store.js`
- Test: build success + `node --check` + live acceptance (no JS harness in repo)

**Interfaces:**
- Consumes: `window.woiBlocks` (localized in Task 5), `@wordpress/*` packages.
- Produces: a mounted block editor that registers `woi/text`, `woi/shop-name`, `woi/line-items`, `woi/totals`, loads `storedMarkup`, Saves via `POST {restUrl}/visual-blocks`, and flips active source via `POST {restUrl}/visual-active-source`.

> The `save()` output of each block MUST match what Task 4 registered and what the kses allowlist permits, and MUST contain the literal `{{token}}`.

- [ ] **Step 1: REST client**

Create `src/block-editor/store.js`:

```js
const { restUrl, nonce, docType } = window.woiBlocks || {};

async function post( path, body ) {
	const res = await fetch( `${ restUrl }/${ path }`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
		body: JSON.stringify( body ),
	} );
	if ( ! res.ok ) {
		throw new Error( `Request failed: ${ res.status }` );
	}
	return res.json();
}

export function saveBlocks( markup ) {
	return post( 'visual-blocks', { doc_type: docType, markup } );
}

export function setActiveSource( source ) {
	return post( 'visual-active-source', { source } );
}
```

- [ ] **Step 2: Token blocks**

Create `src/block-editor/blocks/token.js`:

```js
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

/**
 * Slice-1 token blocks. Each is static: save() emits a fixed wrapper holding the
 * literal {{token}}; the real value is merged server-side at PDF time.
 * `example`/preview shows a friendly label so the canvas is readable.
 */
const TOKENS = [
	{ name: 'woi/shop-name', title: __( 'Shop Name', 'woocommerce-orders-invoice-pdf' ), token: '{{shop_name}}', tag: 'p', preview: 'Acme Trading LLC' },
	{ name: 'woi/line-items', title: __( 'Line Items', 'woocommerce-orders-invoice-pdf' ), token: '{{line_items}}', tag: 'div', preview: '[ line items table ]' },
	{ name: 'woi/totals', title: __( 'Totals', 'woocommerce-orders-invoice-pdf' ), token: '{{totals}}', tag: 'div', preview: '[ totals table ]' },
];

export function registerTokenBlocks() {
	TOKENS.forEach( ( { name, title, token, tag, preview } ) => {
		registerBlockType( name, {
			apiVersion: 2,
			title,
			category: 'woi-invoice',
			icon: 'media-document',
			supports: { html: false, reusable: false },
			edit() {
				const Tag = tag;
				return <Tag { ...useBlockProps() }>{ preview }</Tag>;
			},
			save() {
				const Tag = tag;
				// Inner content is the literal token; merged at PDF render time.
				return <Tag { ...useBlockProps.save() }>{ token }</Tag>;
			},
		} );
	} );
}
```

> `useBlockProps.save()` writes a `class` attr (allowed by kses). The token text is plain text content — kses leaves `{{…}}` untouched (proven by existing store tests).

- [ ] **Step 3: Editable text block**

Create `src/block-editor/blocks/text.js`:

```js
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, RichText } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

export function registerTextBlock() {
	registerBlockType( 'woi/text', {
		apiVersion: 2,
		title: __( 'Text', 'woocommerce-orders-invoice-pdf' ),
		category: 'woi-invoice',
		icon: 'editor-paragraph',
		attributes: { content: { type: 'string', source: 'html', selector: 'p', default: '' } },
		supports: { reusable: false },
		edit( { attributes, setAttributes } ) {
			return (
				<RichText
					{ ...useBlockProps() }
					tagName="p"
					value={ attributes.content }
					onChange={ ( content ) => setAttributes( { content } ) }
					placeholder={ __( 'Type text or insert a {{token}}…', 'woocommerce-orders-invoice-pdf' ) }
				/>
			);
		},
		save( { attributes } ) {
			return <RichText.Content { ...useBlockProps.save() } tagName="p" value={ attributes.content } />;
		},
	} );
}
```

- [ ] **Step 4: Mount the editor**

Create `src/block-editor/index.js`:

```js
import { createRoot, useState } from '@wordpress/element';
import {
	BlockCanvas,
	BlockList,
	BlockTools,
	BlockEditorProvider,
	WritingFlow,
	ObserveTyping,
	Inserter,
} from '@wordpress/block-editor';
import { registerCoreBlocks } from '@wordpress/block-library';
import { parse, serialize, registerBlockCollection } from '@wordpress/blocks';
import { Button, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { registerTokenBlocks } from './blocks/token';
import { registerTextBlock } from './blocks/text';
import { saveBlocks, setActiveSource } from './store';

// Register our blocks (core blocks not used in slice 1, but registering the
// collection groups ours under an "Invoice" heading in the inserter).
registerBlockCollection( 'woi', { title: __( 'Invoice', 'woocommerce-orders-invoice-pdf' ) } );
registerTextBlock();
registerTokenBlocks();

function Editor( { initial, activeSource } ) {
	const [ blocks, setBlocks ] = useState( initial );
	const [ status, setStatus ] = useState( '' );
	const [ source, setSource ] = useState( activeSource );

	async function onSave() {
		setStatus( __( 'Saving…', 'woocommerce-orders-invoice-pdf' ) );
		try {
			await saveBlocks( serialize( blocks ) );
			setStatus( __( 'Saved.', 'woocommerce-orders-invoice-pdf' ) );
		} catch ( e ) {
			setStatus( __( 'Save failed.', 'woocommerce-orders-invoice-pdf' ) );
		}
	}

	async function onSource( next ) {
		setSource( next );
		try {
			const r = await setActiveSource( next );
			setSource( r.source );
		} catch ( e ) { /* keep prior on failure */ }
	}

	return (
		<div className="woi-block-shell">
			<div className="woi-block-toolbar" style={ { display: 'flex', gap: '8px', alignItems: 'center', marginBottom: '8px' } }>
				<Button variant="primary" onClick={ onSave }>{ __( 'Save', 'woocommerce-orders-invoice-pdf' ) }</Button>
				<label>{ __( 'PDF source:', 'woocommerce-orders-invoice-pdf' ) }</label>
				<select value={ source } onChange={ ( e ) => onSource( e.target.value ) }>
					<option value="grapesjs">{ __( 'GrapesJS', 'woocommerce-orders-invoice-pdf' ) }</option>
					<option value="blocks">{ __( 'Block editor', 'woocommerce-orders-invoice-pdf' ) }</option>
				</select>
				<span aria-live="polite">{ status }</span>
			</div>
			<BlockEditorProvider value={ blocks } onInput={ setBlocks } onChange={ setBlocks }>
				<div className="woi-block-canvas" style={ { border: '1px solid #ddd', background: '#fff', minHeight: '60vh' } }>
					<BlockTools>
						<div style={ { padding: '8px' } }><Inserter rootClientId={ undefined } isAppender /></div>
						<WritingFlow>
							<ObserveTyping>
								<BlockList />
							</ObserveTyping>
						</WritingFlow>
					</BlockTools>
				</div>
			</BlockEditorProvider>
		</div>
	);
}

const mount = document.getElementById( 'woi-block-editor-root' );
if ( mount && window.woiBlocks ) {
	const initial = window.woiBlocks.storedMarkup ? parse( window.woiBlocks.storedMarkup ) : [];
	createRoot( mount ).render( <Editor initial={ initial } activeSource={ window.woiBlocks.activeSource || 'grapesjs' } /> );
}
```

> `BlockCanvas` import is left in case an iframe canvas is preferred; Slice 1 uses the simpler `BlockList` in-page surface. Remove unused imports before build to satisfy the wp-scripts ESLint (`node --check` won't catch unused imports, but `wp-scripts build` will warn — trim them).

- [ ] **Step 5: Syntax-check each module**

Run: `node --check src/block-editor/store.js`
Expected: no output (valid). (JSX files won't pass `node --check`; rely on the build in Step 6 for those.)

- [ ] **Step 6: Install deps if needed, then build**

Run: `npm install` (first time, to pull `@wordpress/scripts` 30 deps) then `npm run build`
Expected: build succeeds; `assets/js/block-editor/index.js` and `assets/js/block-editor/index.asset.php` are emitted, and `assets/js/home/index.js` still exists.

- [ ] **Step 7: Verify the emitted asset file shape**

Run: `cat assets/js/block-editor/index.asset.php`
Expected: a PHP array with `dependencies` (including `wp-block-editor`, `wp-blocks`, `wp-element`) and a `version` hash.

- [ ] **Step 8: Bump version again (assets changed)**

In `woocommerce-orders-invoice-pdf.php` set line 6 and line 24 to `1.5.1`.

- [ ] **Step 9: Commit**

```bash
git add src/block-editor woocommerce-orders-invoice-pdf.php assets/js/block-editor
git commit -m "feat(visual): block editor canvas with slice-1 blocks, save + source switch"
```

---

## Task 7: Block category + live end-to-end acceptance

**Files:**
- Modify: `includes/Visual/Blocks.php` (register the `woi-invoice` block category server-side)
- Verification: live WP admin + real-order PDF (manual; no automated oracle)

**Interfaces:**
- Consumes: everything above.
- Produces: a registered `woi-invoice` block category so the inserter groups the blocks; confirmation the blocks→PDF pipeline works on a real order.

- [ ] **Step 1: Register the block category**

In `includes/Visual/Blocks.php`, add to the constructor:

```php
        add_filter( 'block_categories_all', array( $this, 'add_category' ) );
```

and add the method:

```php
    public function add_category( $categories ) {
        if ( ! is_array( $categories ) ) { return $categories; }
        array_unshift( $categories, array(
            'slug'  => 'woi-invoice',
            'title' => __( 'Invoice', 'woocommerce-orders-invoice-pdf' ),
        ) );
        return $categories;
    }
```

- [ ] **Step 2: Lint + full suite**

Run: `php -l includes/Visual/Blocks.php && php -d display_errors=1 -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit`
Expected: no syntax errors; suite PASS, 0 errors.

- [ ] **Step 3: Commit**

```bash
git add includes/Visual/Blocks.php
git commit -m "feat(visual): register Invoice block category"
```

- [ ] **Step 4: Live acceptance (manual — see live-testing-harness memory)**

Deploy (manual `git pull` on the live site), then in `wp-admin`:
1. PDF Invoices → **Block Editor** tab. Confirm the canvas mounts, the inserter shows the **Invoice** category with Text/Shop Name/Line Items/Totals.
2. Build a minimal invoice: a Text block with `{{document_title}}` won't exist yet (Slice 2) — for Slice 1 use **Shop Name**, **Line Items**, **Totals** blocks + a Text block. Click **Save**; confirm "Saved."
3. Set **PDF source → Block editor**.
4. Invoice Settings → ensure **Visual template (invoice)** is ON.
5. Generate a real-order invoice PDF. Rasterize with PyMuPDF (see `rendering-pdfs-for-verification`) and confirm the line-items table + totals render (Arabic intact via mPDF).
6. Flip **PDF source → GrapesJS**, regenerate, and confirm the GrapesJS design returns — proving the switch and that GrapesJS is untouched.

Expected: blocks design drives the PDF when active; switching back restores GrapesJS; no errors in `debug.log`.

> If `do_blocks` returns empty for a block, confirm Task 4 registered it (unregistered static blocks still pass inner HTML, but registration is the canonical fix).

---

## Self-Review

**Spec coverage (Slice 1 scope):**
- Storage options (`woi_pdf_visual_blocks_invoice`, `_html_`, `_active_source`) → Task 1. ✓
- Resolver / render-branch edit at `OrderDocument.php:1812` → Tasks 1 + 2. ✓
- Server-side `do_blocks` render + kses on save → Task 3 (`render_blocks` seam, `save_blocks` kses). ✓
- Server-side block registration → Task 4. ✓
- `BlockEditorPage` (page, hidden submenu, settings tab, notices suppression, enqueue) → Task 5. ✓
- Build pipeline (dual entry) → Task 5. ✓
- Custom invoice blocks (Slice-1 subset) + Save + active-source switch → Task 6. ✓
- Block category / inserter grouping + live acceptance → Task 7. ✓
- Active-source UI in the Advanced settings tab → **deferred to Slice 4** (Slice 1 exposes the switch in the editor toolbar, which is sufficient to drive the pipeline; noted in the spec's active-source section). ✓ (intentional scope boundary)
- Live HTML / A4 PDF.js preview, full block set, slash/inserter parity, required-token warnings → **Slices 2–4** (own plans). ✓

**Placeholder scan:** None. Task 2 was rewritten to a concrete resolver-parity test + grep-verified one-line edit (no `markTestIncomplete`, no "adapt to existing harness" hand-waving). No TODO/TBD anywhere.

**Type consistency:** `get_active()`, `get_active_source()`, `set_active_source()`, `save_blocks()`, `blocks_html_option_name()`, `blocks_markup_option_name()`, `active_source_option_name()` are defined in Task 1 and consumed verbatim in Tasks 2/3/5. REST handler names `handle_visual_blocks_save`, `handle_visual_active_source`, seam `render_blocks` match across Task 3 definition and Task 3 tests. Block names (`woi/text`, `woi/shop-name`, `woi/line-items`, `woi/totals`) match between Task 4 (`NAMES`), Task 6 (`registerTokenBlocks`/`registerTextBlock`), and Task 7. Localized globals: PHP `woiBlocks` (Task 5) === JS `window.woiBlocks` (Task 6). ✓
