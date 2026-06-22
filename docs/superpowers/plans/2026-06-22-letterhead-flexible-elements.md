# Letterhead Per-Element Flexibility Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every element of the Block Invoice Template's Letterhead (company name EN/AR, address EN/AR, logo) individually alignable, styleable (bold/size/colour), show/hideable, and rearrangeable (logo position + EN/AR side swap + logo width).

**Architecture:** Config travels via WordPress options the PDF renderer reads directly — NOT serialized into saved block HTML (the contact-strip lesson). Logo position reuses the existing `header` doc-option (widened to `left|center|right`) so the legacy sidebar dropdown and the new control stay in sync. Everything else new (EN/AR swap, logo width, per-element text styling) lives in a new `woi_pdf_letterhead` option. The letterhead block's `save` stays the bare `{{letterhead}}` token.

**Tech Stack:** PHP 7.4 (mPDF render), WordPress block editor (`@wordpress/scripts`), PHPUnit + Brain Monkey, Jest.

## Global Constraints

- **PHP floor 7.4** — `?array` nullable params OK; no union types.
- **Inline styles only** for per-element styling — mPDF ignores the theme stylesheet / `body[data-*]` descendant selectors. Emit align/weight/size/colour inline.
- **Escaping:** values via `esc_html()`/`wp_kses_post()` (as the current builder already does), attribute/style strings via `esc_attr()`.
- **Logo position is the shared `header` doc-option**, widened from `center|left` to `left|center|right` (default `center`). The new `woi_pdf_letterhead` option must NOT duplicate it.
- **AR gating:** an AR element renders only when `woi_pdf_visual_doc_options()['arabic'] === 'on'` AND its own `visible` is true.
- **Defaults reproduce today's letterhead exactly:** EN block left-aligned, AR block right-aligned (RTL), logo centred, all visible, widths 40/20/40.
- **Version bump:** both strings in `woocommerce-orders-invoice-pdf.php` (header line ~6 + `public string $version` line ~24) to the next free patch — read `git show origin/master:woocommerce-orders-invoice-pdf.php | grep Version` first.
- **PHPUnit harness:** always run with `-d auto_prepend_file=tests/bootstrap.php`. The suite has pre-existing baseline failures (e.g. `test_line_item_columns_emit_configured_width`, `test_thumbs_off_drops_thumbnail_column`, plus ~11 errors in other Visual files); the gate is **no NEW failures** beyond that baseline. `woi-pdf-functions.php` is NOT loaded under the harness — stub `woi_pdf_letterhead` / `woi_pdf_visual_doc_options` via Brain Monkey.

---

### Task 1: PHP — option readers/sanitisers + widen `header` whitelist

**Files:**
- Modify: `woi-pdf-functions.php` (add 3 functions after `woi_pdf_contact_items`; widen `header` in `woi_pdf_visual_doc_options`)

**Interfaces:**
- Produces (consumed by Task 2 render + Task 3 REST):
  - `woi_pdf_default_letterhead(): array`
  - `woi_pdf_sanitize_letterhead( $raw ): array`
  - `woi_pdf_letterhead(): array`
  - `woi_pdf_visual_doc_options()` `header` now accepts `left|center|right`.

These live in the functions file (not loaded by the PHPUnit harness), so they have no direct unit test here; they are exercised through `section_letterhead` in Task 2 (stubbed) and live. This task's gate is a PHP lint + the existing suite staying green.

- [ ] **Step 1: Widen the `header` whitelist**

In `woi-pdf-functions.php`, inside `woi_pdf_visual_doc_options()`, change BOTH the default-comment and the allowed list. Find:

```php
			'header'  => 'center',        // center | left
```
Replace with:
```php
			'header'  => 'center',        // left | center | right (logo position)
```
And find:
```php
			'header'  => array( 'center', 'left' ),
```
Replace with:
```php
			'header'  => array( 'left', 'center', 'right' ),
```

- [ ] **Step 2: Add the letterhead option functions**

In `woi-pdf-functions.php`, immediately after the closing `}` of the `if ( ! function_exists( 'woi_pdf_contact_items' ) ) { ... }` block, insert:

```php
if ( ! function_exists( 'woi_pdf_default_letterhead' ) ) {
	/**
	 * Default letterhead config — reproduces the historical render: all elements
	 * visible, EN block left, AR block right, logo centred (position lives in the
	 * `header` doc-option), no width override, EN before AR.
	 *
	 * @return array<string,mixed>
	 */
	function woi_pdf_default_letterhead() {
		return array(
			'swapText'  => false,
			'logoWidth' => 0,
			'elements'  => array(
				'name_en'    => array( 'visible' => true, 'align' => 'left',  'bold' => true,  'fontSize' => 0, 'color' => '' ),
				'address_en' => array( 'visible' => true, 'align' => 'left',  'bold' => false, 'fontSize' => 0, 'color' => '' ),
				'name_ar'    => array( 'visible' => true, 'align' => 'right', 'bold' => true,  'fontSize' => 0, 'color' => '' ),
				'address_ar' => array( 'visible' => true, 'align' => 'right', 'bold' => false, 'fontSize' => 0, 'color' => '' ),
				'logo'       => array( 'visible' => true ),
			),
		);
	}
}

if ( ! function_exists( 'woi_pdf_sanitize_letterhead' ) ) {
	/**
	 * Normalise a raw letterhead config (editor POST or stored option) into
	 * trusted shape. Unknown element keys dropped; text elements get whitelisted
	 * align / boolean flags / clamped px size / hex colour; logo keeps `visible`
	 * only. swapText bool; logoWidth int clamped 0–120 (mm). Missing pieces fall
	 * back to the default. Single source of truth for save and read.
	 *
	 * @param mixed $raw
	 * @return array<string,mixed>
	 */
	function woi_pdf_sanitize_letterhead( $raw ) {
		$default = woi_pdf_default_letterhead();
		if ( ! is_array( $raw ) ) {
			return $default;
		}
		$allowed_align = array( 'left', 'center', 'right' );
		$text_keys     = array( 'name_en', 'address_en', 'name_ar', 'address_ar' );

		$raw_els = isset( $raw['elements'] ) && is_array( $raw['elements'] ) ? $raw['elements'] : array();
		$els     = array();
		foreach ( $text_keys as $k ) {
			$d   = $default['elements'][ $k ];
			$src = isset( $raw_els[ $k ] ) && is_array( $raw_els[ $k ] ) ? $raw_els[ $k ] : array();
			$size = isset( $src['fontSize'] ) ? (int) $src['fontSize'] : 0;
			$size = max( 0, min( 48, $size ) );
			$els[ $k ] = array(
				'visible'  => isset( $src['visible'] ) ? (bool) filter_var( $src['visible'], FILTER_VALIDATE_BOOLEAN ) : true,
				'align'    => ( isset( $src['align'] ) && in_array( $src['align'], $allowed_align, true ) ) ? $src['align'] : $d['align'],
				'bold'     => isset( $src['bold'] ) ? (bool) filter_var( $src['bold'], FILTER_VALIDATE_BOOLEAN ) : $d['bold'],
				'fontSize' => $size,
				'color'    => ( isset( $src['color'] ) && preg_match( '/^#[0-9a-fA-F]{3,6}$/', (string) $src['color'] ) ) ? (string) $src['color'] : '',
			);
		}
		$logo_src = isset( $raw_els['logo'] ) && is_array( $raw_els['logo'] ) ? $raw_els['logo'] : array();
		$els['logo'] = array(
			'visible' => isset( $logo_src['visible'] ) ? (bool) filter_var( $logo_src['visible'], FILTER_VALIDATE_BOOLEAN ) : true,
		);

		$width = isset( $raw['logoWidth'] ) ? (int) $raw['logoWidth'] : 0;
		$width = max( 0, min( 120, $width ) );

		return array(
			'swapText'  => isset( $raw['swapText'] ) ? (bool) filter_var( $raw['swapText'], FILTER_VALIDATE_BOOLEAN ) : false,
			'logoWidth' => $width,
			'elements'  => $els,
		);
	}
}

if ( ! function_exists( 'woi_pdf_letterhead' ) ) {
	/**
	 * The configured letterhead layout, read from the `woi_pdf_letterhead` option
	 * and normalised. Logo POSITION is NOT here — it is the shared `header`
	 * doc-option (woi_pdf_visual_doc_options). Defaults to the historical layout.
	 *
	 * @return array<string,mixed>
	 */
	function woi_pdf_letterhead() {
		return woi_pdf_sanitize_letterhead( get_option( 'woi_pdf_letterhead', array() ) );
	}
}
```

- [ ] **Step 3: Lint + run the existing suite (no new failures)**

```bash
php -l woi-pdf-functions.php
php vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php --filter TemplateTokensTest tests/Unit/Visual/TemplateTokensTest.php
```
Expected: lint "No syntax errors"; the only TemplateTokensTest failures are the 2 known baseline ones.

- [ ] **Step 4: Commit**

```bash
git add woi-pdf-functions.php
git commit -m "feat(letterhead): option readers/sanitiser + widen header to left|center|right"
```

---

### Task 2: PHP — `section_letterhead` renders from the options

**Files:**
- Modify: `includes/Visual/TemplateTokens.php` (replace `section_letterhead` at lines 222-233; add two private helpers after it)
- Test: `tests/Unit/Visual/TemplateTokensTest.php` (add tests + extend `setUp`)

**Interfaces:**
- Consumes: `woi_pdf_letterhead()`, `woi_pdf_visual_doc_options()` (Task 1; stubbed in tests).
- Produces: `private function section_letterhead( $document, $engine, ?array $config = null ): string` (signature gains optional `$config`; callers in `map()` and `running_header()` pass two args, so the default applies — no caller change needed).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Visual/TemplateTokensTest.php` `setUp()` (after the `woi_pdf_contact_items` stub), so every `map()` call has stable letterhead inputs:

```php
        Functions\when( 'woi_pdf_letterhead' )->justReturn( array(
            'swapText'  => false,
            'logoWidth' => 0,
            'elements'  => array(
                'name_en'    => array( 'visible' => true, 'align' => 'left',  'bold' => true,  'fontSize' => 0, 'color' => '' ),
                'address_en' => array( 'visible' => true, 'align' => 'left',  'bold' => false, 'fontSize' => 0, 'color' => '' ),
                'name_ar'    => array( 'visible' => true, 'align' => 'right', 'bold' => true,  'fontSize' => 0, 'color' => '' ),
                'address_ar' => array( 'visible' => true, 'align' => 'right', 'bold' => false, 'fontSize' => 0, 'color' => '' ),
                'logo'       => array( 'visible' => true ),
            ),
        ) );
        Functions\when( 'woi_pdf_visual_doc_options' )->justReturn( array( 'header' => 'center', 'arabic' => 'on' ) );
```

Then add these test methods:

```php
    public function test_letterhead_default_three_columns_en_logo_ar(): void {
        $tokens = new class extends TemplateTokens {
            protected function fetch_table_headers( $d ): array { return array(); }
            protected function fetch_table_body( $d ): array { return array(); }
            protected function fetch_totals( $d ): array { return array(); }
        };
        $lh = $tokens->map( $this->stub_document() )['{{letterhead}}'];

        $this->assertStringContainsString( '<table class="woi-letterhead">', $lh );
        // EN name + AR name present; EN left, AR right; logo cell between them.
        $this->assertStringContainsString( 'Acme Co', $lh );
        $this->assertStringContainsString( 'متجر', $lh );
        $this->assertStringContainsString( 'class="woi-lh-mark"', $lh );
        // Order: EN cell, then mark, then AR cell.
        $this->assertLessThan( strpos( $lh, 'woi-lh-mark' ), strpos( $lh, 'woi-lh-en' ) );
        $this->assertLessThan( strpos( $lh, 'woi-lh-ar' ), strpos( $lh, 'woi-lh-mark' ) );
    }

    public function test_letterhead_logo_left_position_and_swap(): void {
        Functions\when( 'woi_pdf_visual_doc_options' )->justReturn( array( 'header' => 'left', 'arabic' => 'on' ) );
        Functions\when( 'woi_pdf_letterhead' )->justReturn( array(
            'swapText' => true, 'logoWidth' => 30,
            'elements' => array(
                'name_en' => array( 'visible' => true ), 'address_en' => array( 'visible' => true ),
                'name_ar' => array( 'visible' => true ), 'address_ar' => array( 'visible' => true ),
                'logo' => array( 'visible' => true ),
            ),
        ) );
        $tokens = new class extends TemplateTokens {
            protected function fetch_table_headers( $d ): array { return array(); }
            protected function fetch_table_body( $d ): array { return array(); }
            protected function fetch_totals( $d ): array { return array(); }
        };
        $lh = $tokens->map( $this->stub_document() )['{{letterhead}}'];
        // logo first (left), then AR (swapped before EN).
        $this->assertLessThan( strpos( $lh, 'woi-lh-ar' ), strpos( $lh, 'woi-lh-mark' ) );
        $this->assertLessThan( strpos( $lh, 'woi-lh-en' ), strpos( $lh, 'woi-lh-ar' ) );
        // Logo width applied inline.
        $this->assertStringContainsString( 'width:30mm', $lh );
    }

    public function test_letterhead_hidden_element_and_style(): void {
        Functions\when( 'woi_pdf_letterhead' )->justReturn( array(
            'swapText' => false, 'logoWidth' => 0,
            'elements' => array(
                'name_en'    => array( 'visible' => true, 'align' => 'center', 'bold' => true, 'fontSize' => 16, 'color' => '#ff0000' ),
                'address_en' => array( 'visible' => false ),
                'name_ar'    => array( 'visible' => true ), 'address_ar' => array( 'visible' => true ),
                'logo' => array( 'visible' => true ),
            ),
        ) );
        $tokens = new class extends TemplateTokens {
            protected function fetch_table_headers( $d ): array { return array(); }
            protected function fetch_table_body( $d ): array { return array(); }
            protected function fetch_totals( $d ): array { return array(); }
        };
        $lh = $tokens->map( $this->stub_document() )['{{letterhead}}'];
        // EN address hidden -> '1 Main St' absent; EN name styled inline.
        $this->assertStringNotContainsString( '1 Main St', $lh );
        $this->assertStringContainsString( 'font-weight:bold;font-size:16px;color:#ff0000', $lh );
        $this->assertStringContainsString( 'text-align:center', $lh );
    }

    public function test_letterhead_arabic_off_drops_ar_column(): void {
        Functions\when( 'woi_pdf_visual_doc_options' )->justReturn( array( 'header' => 'center', 'arabic' => 'off' ) );
        $tokens = new class extends TemplateTokens {
            protected function fetch_table_headers( $d ): array { return array(); }
            protected function fetch_table_body( $d ): array { return array(); }
            protected function fetch_totals( $d ): array { return array(); }
        };
        $lh = $tokens->map( $this->stub_document() )['{{letterhead}}'];
        $this->assertStringNotContainsString( 'woi-lh-ar', $lh );
        $this->assertStringNotContainsString( 'متجر', $lh );
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
php vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php \
  --filter 'test_letterhead_default_three_columns_en_logo_ar|test_letterhead_logo_left_position_and_swap|test_letterhead_hidden_element_and_style|test_letterhead_arabic_off_drops_ar_column' \
  tests/Unit/Visual/TemplateTokensTest.php
```
Expected: FAIL — the current builder ignores config (always EN|logo|AR, always renders AR, no inline styles).

- [ ] **Step 3: Replace `section_letterhead` and add helpers**

In `includes/Visual/TemplateTokens.php`, replace the method at lines 222-233 with:

```php
    /**
     * Letterhead — EN block | logo | AR block. Per-element layout is read from
     * woi_pdf_letterhead() (option) and the logo POSITION from the shared
     * woi_pdf_visual_doc_options()['header']; null $config falls back to those
     * (or the historical default under the test harness). AR is gated by the
     * `arabic` doc-option AND each element's own `visible`.
     *
     * @param object             $document
     * @param object             $engine   BilingualEngine
     * @param array<string,mixed>|null $config
     */
    private function section_letterhead( $document, $engine, ?array $config = null ): string {
        $values = array(
            'name_en'    => esc_html( (string) $document->get_shop_name() ),
            'address_en' => wp_kses_post( (string) $document->get_shop_address() ),
            'name_ar'    => esc_html( $engine->secondary_shop_name() ),
            'address_ar' => nl2br( esc_html( (string) $engine->secondary_shop_address() ) ),
        );
        $logo = $document->has_header_logo() ? $this->capture( array( $document, 'header_logo' ) ) : '';

        if ( null === $config ) {
            $config = function_exists( 'woi_pdf_letterhead' )
                ? woi_pdf_letterhead()
                : array(
                    'swapText' => false, 'logoWidth' => 0,
                    'elements' => array(
                        'name_en' => array( 'visible' => true, 'align' => 'left' ),  'address_en' => array( 'visible' => true, 'align' => 'left' ),
                        'name_ar' => array( 'visible' => true, 'align' => 'right' ), 'address_ar' => array( 'visible' => true, 'align' => 'right' ),
                        'logo' => array( 'visible' => true ),
                    ),
                );
        }
        $opts      = function_exists( 'woi_pdf_visual_doc_options' ) ? woi_pdf_visual_doc_options( 'invoice' ) : array();
        $logo_pos  = ( isset( $opts['header'] ) && in_array( $opts['header'], array( 'left', 'center', 'right' ), true ) ) ? $opts['header'] : 'center';
        $arabic_on = ( ! isset( $opts['arabic'] ) ) || 'on' === $opts['arabic'];

        $els       = isset( $config['elements'] ) && is_array( $config['elements'] ) ? $config['elements'] : array();
        $swap      = ! empty( $config['swapText'] );
        $logo_w    = isset( $config['logoWidth'] ) ? (int) $config['logoWidth'] : 0;
        $logo_show = ! ( isset( $els['logo']['visible'] ) && ! $els['logo']['visible'] ) && '' !== $logo;

        // Build the two text cells (empty string when fully hidden / AR gated off).
        $en_cell = $this->letterhead_text_cell( 'en', array( 'name' => $values['name_en'], 'address' => $values['address_en'] ), $els['name_en'] ?? array(), $els['address_en'] ?? array(), false );
        $ar_cell = $arabic_on
            ? $this->letterhead_text_cell( 'ar', array( 'name' => $values['name_ar'], 'address' => $values['address_ar'] ), $els['name_ar'] ?? array(), $els['address_ar'] ?? array(), true )
            : '';

        // Ordered text cells (swap flips EN/AR reading order), empties removed.
        $texts = $swap ? array( $ar_cell, $en_cell ) : array( $en_cell, $ar_cell );
        $texts = array_values( array_filter( $texts, static function ( $c ) { return '' !== $c; } ) );

        // Logo cell.
        $logo_cell = '';
        if ( $logo_show ) {
            $lw = $logo_w > 0 ? ' style="text-align:center;width:' . (int) $logo_w . 'mm"' : '';
            $logo_cell = '<td class="woi-lh-mark"' . $lw . '>' . $logo . '</td>';
        }

        // Assemble columns: logo position relative to the text cells.
        $cells = $texts;
        if ( '' !== $logo_cell ) {
            if ( 'left' === $logo_pos ) {
                array_unshift( $cells, $logo_cell );
            } elseif ( 'right' === $logo_pos ) {
                $cells[] = $logo_cell;
            } else { // center
                if ( 2 === count( $texts ) ) {
                    $cells = array( $texts[0], $logo_cell, $texts[1] );
                } else {
                    $cells[] = $logo_cell;
                }
            }
        }
        if ( empty( $cells ) ) {
            return '';
        }
        return '<table class="woi-letterhead"><tr>' . implode( '', $cells ) . '</tr></table>';
    }

    /**
     * One letterhead text column (company name over address lines). Returns ''
     * when both name and address are hidden. $rtl adds dir="rtl" and the AR class.
     *
     * @param string               $side 'en'|'ar' (class suffix only)
     * @param array<string,string> $vals { name, address } pre-escaped HTML
     * @param array<string,mixed>  $name_el  element settings for the name
     * @param array<string,mixed>  $addr_el  element settings for the address
     * @param bool                 $rtl
     */
    private function letterhead_text_cell( string $side, array $vals, array $name_el, array $addr_el, bool $rtl ): string {
        $name_on = ! ( isset( $name_el['visible'] ) && ! $name_el['visible'] );
        $addr_on = ! ( isset( $addr_el['visible'] ) && ! $addr_el['visible'] );
        if ( ! $name_on && ! $addr_on ) {
            return '';
        }
        $default_align = $rtl ? 'right' : 'left';
        $name_align    = ( isset( $name_el['align'] ) && in_array( $name_el['align'], array( 'left', 'center', 'right' ), true ) ) ? $name_el['align'] : $default_align;
        $addr_align    = ( isset( $addr_el['align'] ) && in_array( $addr_el['align'], array( 'left', 'center', 'right' ), true ) ) ? $addr_el['align'] : $default_align;

        $inner = '';
        if ( $name_on ) {
            $inner .= '<div class="woi-co-name" style="text-align:' . $name_align . $this->letterhead_el_style( $name_el ) . '">' . $vals['name'] . '</div>';
        }
        if ( $addr_on ) {
            $inner .= '<div class="woi-co-lines" style="text-align:' . $addr_align . $this->letterhead_el_style( $addr_el ) . '">' . $vals['address'] . '</div>';
        }
        $cls = 'woi-lh-' . $side;
        $dir = $rtl ? ' dir="rtl"' : '';
        return '<td class="' . $cls . '"' . $dir . '>' . $inner . '</td>';
    }

    /**
     * Inline weight/size/colour fragment for a letterhead text element, prefixed
     * with ';' so it appends to an existing text-align declaration. Empty when
     * nothing is set. Inline because mPDF ignores the theme stylesheet.
     *
     * @param array<string,mixed> $el
     */
    private function letterhead_el_style( array $el ): string {
        $parts = array();
        if ( ! empty( $el['bold'] ) ) {
            $parts[] = 'font-weight:bold';
        }
        if ( ! empty( $el['fontSize'] ) ) {
            $parts[] = 'font-size:' . (int) $el['fontSize'] . 'px';
        }
        if ( ! empty( $el['color'] ) && preg_match( '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', (string) $el['color'] ) ) {
            $parts[] = 'color:' . $el['color'];
        }
        return $parts ? ';' . implode( ';', $parts ) : '';
    }
```

Note: the `<div class="woi-co-name" style="text-align:...">` carries the inline style directly (the style attribute value is built from whitelisted/clamped pieces, so it is safe; matches the contact-strip convention of trusting validated inline styles).

- [ ] **Step 4: Run the new tests to verify they pass**

```bash
php vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php \
  --filter 'test_letterhead_default_three_columns_en_logo_ar|test_letterhead_logo_left_position_and_swap|test_letterhead_hidden_element_and_style|test_letterhead_arabic_off_drops_ar_column' \
  tests/Unit/Visual/TemplateTokensTest.php
```
Expected: all four PASS.

- [ ] **Step 5: Run the whole class + the existing letterhead tests for regressions**

```bash
php vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php --filter TemplateTokensTest tests/Unit/Visual/TemplateTokensTest.php
```
Expected: only the 2 known baseline failures; the existing `test_letterhead_token_present_when_repeat_off`, `test_running_header_wraps_letterhead`, `test_section_tokens_emit_canonical_sections` still pass (they only assert `<table class="woi-letterhead">` / `Acme Co` presence, which still holds).

- [ ] **Step 6: Commit**

```bash
git add includes/Visual/TemplateTokens.php tests/Unit/Visual/TemplateTokensTest.php
git commit -m "feat(letterhead): render from option config — arrangement, per-element style, AR gating"
```

---

### Task 3: PHP — REST save + editor localisation

**Files:**
- Modify: `includes/Rest.php` (register `/letterhead` route near `/contact-items`; add `handle_letterhead_save` near `handle_contact_items_save`)
- Modify: `includes/Visual/BlockEditorPage.php` (localise `letterhead`)

**Interfaces:**
- Consumes: `woi_pdf_sanitize_letterhead()`, `woi_pdf_letterhead()` (Task 1).
- Produces: REST `POST woi-pdf/v1/letterhead` → `{ items: {...} }` saved to option `woi_pdf_letterhead`; `window.woiBlocks.letterhead` available in the editor.

REST handlers are not unit-tested in this harness (the contact-items handler isn't either); this task is verified by the live round-trip in Task 6. Gate: PHP lint.

- [ ] **Step 1: Register the route**

In `includes/Rest.php`, immediately before the `register_rest_route( 'woi-pdf/v1', '/contact-items', ... )` call, add:

```php
		register_rest_route( 'woi-pdf/v1', '/letterhead', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_letterhead_save' ),
			'permission_callback' => function () { return current_user_can( 'manage_woocommerce' ); },
			'args'                => array(
				'items' => array( 'type' => 'object', 'required' => true ),
			),
		) );
```

- [ ] **Step 2: Add the handler**

In `includes/Rest.php`, immediately after the `handle_contact_items_save` method's closing `}`, add:

```php
		/**
		 * Save the letterhead per-element + arrangement config (swapText, logoWidth,
		 * per-element style/visibility) to its own option. Logo POSITION is saved
		 * separately via /visual-doc-options (the shared `header` key). Normalised
		 * through the same sanitiser used on read.
		 *
		 * @param object $request
		 * @return array|\WP_Error
		 */
		public function handle_letterhead_save( $request ) {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return new \WP_Error( 'forbidden', 'Insufficient permissions', array( 'status' => 403 ) );
			}
			$clean = woi_pdf_sanitize_letterhead( (array) $request->get_param( 'items' ) );
			update_option( 'woi_pdf_letterhead', $clean, false );
			return array( 'items' => $clean );
		}
```

- [ ] **Step 3: Localise the config**

In `includes/Visual/BlockEditorPage.php`, find:
```php
            'contactItems'      => function_exists( 'woi_pdf_contact_items' ) ? woi_pdf_contact_items() : array(),
```
Add immediately after it:
```php
            'letterhead'        => function_exists( 'woi_pdf_letterhead' ) ? woi_pdf_letterhead() : array(),
```

- [ ] **Step 4: Lint**

```bash
php -l includes/Rest.php && php -l includes/Visual/BlockEditorPage.php
```
Expected: "No syntax errors detected" for both.

- [ ] **Step 5: Commit**

```bash
git add includes/Rest.php includes/Visual/BlockEditorPage.php
git commit -m "feat(letterhead): REST /letterhead save + localise config for the editor"
```

---

### Task 4: JS — pure model + Jest

**Files:**
- Create: `src/block-editor/blocks/letterheadModel.js`
- Test: `src/block-editor/blocks/letterheadModel.test.js`

**Interfaces (no `@wordpress/*` imports — Jest imports it directly):**
- Produces: `LH_TEXT_FIELDS` (ordered `[{key,label,token}]` for the 4 text elements), `LH_DEFAULT` (default config matching `woi_pdf_default_letterhead`), `lhValueStyle(el)` → React style object.

- [ ] **Step 1: Write the failing test**

Create `src/block-editor/blocks/letterheadModel.test.js`:

```js
import { LH_TEXT_FIELDS, LH_DEFAULT, lhValueStyle } from './letterheadModel';

describe( 'letterheadModel', () => {
	test( 'text fields are name/address EN then AR, with value tokens', () => {
		expect( LH_TEXT_FIELDS.map( ( f ) => f.key ) ).toEqual( [ 'name_en', 'address_en', 'name_ar', 'address_ar' ] );
		expect( LH_TEXT_FIELDS.find( ( f ) => f.key === 'name_en' ).token ).toBe( '{{shop_name}}' );
		expect( LH_TEXT_FIELDS.find( ( f ) => f.key === 'address_ar' ).token ).toBe( '{{shop_address_ar}}' );
	} );

	test( 'default config: all visible, EN left / AR right, no swap/width', () => {
		expect( LH_DEFAULT.swapText ).toBe( false );
		expect( LH_DEFAULT.logoWidth ).toBe( 0 );
		expect( LH_DEFAULT.elements.name_en.align ).toBe( 'left' );
		expect( LH_DEFAULT.elements.name_ar.align ).toBe( 'right' );
		expect( LH_DEFAULT.elements.logo.visible ).toBe( true );
	} );

	test( 'lhValueStyle emits only set properties', () => {
		expect( lhValueStyle( { bold: false, fontSize: 0, color: '' } ) ).toEqual( {} );
		expect( lhValueStyle( { bold: true, fontSize: 16, color: '#ff0000' } ) ).toEqual( {
			fontWeight: 'bold', fontSize: '16px', color: '#ff0000',
		} );
	} );
} );
```

- [ ] **Step 2: Run to verify it fails**

```bash
npm run test:unit -- letterheadModel
```
Expected: FAIL — module not found.

- [ ] **Step 3: Create the model**

Create `src/block-editor/blocks/letterheadModel.js`:

```js
/**
 * Pure model for the Letterhead block. NO @wordpress/* imports — kept separate
 * so Jest can unit-test it directly (mirrors contactStripModel.js).
 */

// The four text elements, in EN-then-AR reading order, with their value tokens.
export const LH_TEXT_FIELDS = [
	{ key: 'name_en',    label: 'Company name', token: '{{shop_name}}' },
	{ key: 'address_en', label: 'Address',      token: '{{shop_address}}' },
	{ key: 'name_ar',    label: 'Company name (AR)', token: '{{shop_name_ar}}' },
	{ key: 'address_ar', label: 'Address (AR)',      token: '{{shop_address_ar}}' },
];

// Default config — mirrors woi_pdf_default_letterhead() on the PHP side.
export const LH_DEFAULT = {
	swapText: false,
	logoWidth: 0,
	elements: {
		name_en:    { visible: true, align: 'left',  bold: true,  fontSize: 0, color: '' },
		address_en: { visible: true, align: 'left',  bold: false, fontSize: 0, color: '' },
		name_ar:    { visible: true, align: 'right', bold: true,  fontSize: 0, color: '' },
		address_ar: { visible: true, align: 'right', bold: false, fontSize: 0, color: '' },
		logo:       { visible: true },
	},
};

// React inline-style object for a text element (set properties only).
export function lhValueStyle( el ) {
	const s = {};
	if ( el.bold ) { s.fontWeight = 'bold'; }
	if ( el.fontSize ) { s.fontSize = el.fontSize + 'px'; }
	if ( el.color ) { s.color = el.color; }
	return s;
}
```

- [ ] **Step 4: Run to verify it passes**

```bash
npm run test:unit -- letterheadModel
```
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/block-editor/blocks/letterheadModel.js src/block-editor/blocks/letterheadModel.test.js
git commit -m "feat(letterhead): pure model (fields, default config, value style)"
```

---

### Task 5: JS — editor component, wiring, build, version bump

**Files:**
- Create: `src/block-editor/blocks/letterhead.js`
- Modify: `src/block-editor/store.js` (add `saveLetterhead` + `saveDocOptions` is already present)
- Modify: `src/block-editor/blocks/token.js` (flag `woi/letterhead` with `letterhead: true`; branch edit/save)
- Modify: `woocommerce-orders-invoice-pdf.php` (version bump, both strings)
- Build artifact: `assets/js/block-editor/index.js` (+ `index.asset.php`)

**Interfaces:**
- Consumes: `LH_TEXT_FIELDS`, `LH_DEFAULT`, `lhValueStyle` (Task 4); `appearanceProps` (`../appearance`); `tokenValue` (`../tokenMerge`); `STORE` (`../previewStore`); `saveLetterhead`, `saveDocOptions` (`../store`).
- Produces (consumed by `token.js`): `LetterheadEdit( props )`, `letterheadSave( props )`.

- [ ] **Step 1: Add the store helper**

In `src/block-editor/store.js`, after the `saveContactItems` function, add:

```js
export function saveLetterhead( items ) {
	return post( 'letterhead', { items } );
}
```

- [ ] **Step 2: Create the editor component**

Create `src/block-editor/blocks/letterhead.js`:

```js
import { useState, useRef } from '@wordpress/element';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, ToggleControl, SelectControl, RangeControl, ColorPalette, Button } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { STORE } from '../previewStore';
import { tokenValue } from '../tokenMerge';
import { appearanceProps } from '../appearance';
import { saveLetterhead, saveDocOptions } from '../store';
import { LH_TEXT_FIELDS, LH_DEFAULT, lhValueStyle } from './letterheadModel';

const COLORS = [
	{ name: __( 'Ink', 'woocommerce-orders-invoice-pdf' ), color: '#1C1A17' },
	{ name: __( 'Accent', 'woocommerce-orders-invoice-pdf' ), color: '#140858' },
	{ name: __( 'Grey', 'woocommerce-orders-invoice-pdf' ), color: '#8A8378' },
];

function seedConfig() {
	const s = window.woiBlocks && window.woiBlocks.letterhead;
	return s && s.elements ? s : LH_DEFAULT;
}
function seedLogoPos() {
	const h = window.woiBlocks && window.woiBlocks.docOptions && window.woiBlocks.docOptions.header;
	return [ 'left', 'center', 'right' ].includes( h ) ? h : 'center';
}

export function LetterheadEdit() {
	const tokens = useSelect( ( select ) => select( STORE ).getTokens(), [] );
	const [ cfg, setCfg ] = useState( seedConfig );
	const [ logoPos, setLogoPos ] = useState( seedLogoPos );
	const [ selected, setSelected ] = useState( 'name_en' );
	const timer = useRef( null );
	const blockProps = useBlockProps( { className: 'woi-lh-edit' } );

	const persist = ( next ) => {
		setCfg( next );
		if ( timer.current ) { clearTimeout( timer.current ); }
		timer.current = setTimeout( () => { saveLetterhead( next ).catch( () => {} ); }, 600 );
	};
	const updateEl = ( key, patch ) => persist( { ...cfg, elements: { ...cfg.elements, [ key ]: { ...cfg.elements[ key ], ...patch } } } );
	const updateCfg = ( patch ) => persist( { ...cfg, ...patch } );
	const changeLogoPos = ( v ) => { setLogoPos( v ); saveDocOptions( { header: v } ).catch( () => {} ); };

	const isText = LH_TEXT_FIELDS.some( ( f ) => f.key === selected );
	const sel = cfg.elements[ selected ] || {};

	// Render the EN and AR columns as stacked name+address; logo cell per position.
	const colFor = ( side ) => {
		const fields = LH_TEXT_FIELDS.filter( ( f ) => f.key.endsWith( side ) );
		return (
			<div key={ side } className="woi-lh-col" style={ { flex: 1, direction: side === 'ar' ? 'rtl' : 'ltr' } }>
				{ fields.map( ( f ) => {
					const el = cfg.elements[ f.key ];
					if ( el.visible === false ) {
						return <div key={ f.key } onClick={ () => setSelected( f.key ) } style={ { opacity: 0.35, cursor: 'pointer', fontSize: 11 } }>{ f.label } { __( '(hidden)', 'woocommerce-orders-invoice-pdf' ) }</div>;
					}
					return (
						<div key={ f.key }
							onClick={ () => setSelected( f.key ) }
							style={ { textAlign: el.align || ( side === 'ar' ? 'right' : 'left' ), outline: selected === f.key ? '1px solid #007cba' : 'none', cursor: 'pointer', padding: '1px 3px', ...lhValueStyle( el ) } }>
							{ tokenValue( f.token, tokens ) || f.label }
						</div>
					);
				} ) }
			</div>
		);
	};
	const logoCell = cfg.elements.logo.visible === false ? null : (
		<div key="logo" className="woi-lh-logo" onClick={ () => setSelected( 'logo' ) }
			style={ { flex: '0 0 20%', textAlign: 'center', outline: selected === 'logo' ? '1px solid #007cba' : 'none', cursor: 'pointer' } }>
			<span style={ { fontSize: 11, color: '#8A8378' } }>{ __( '[ logo ]', 'woocommerce-orders-invoice-pdf' ) }</span>
		</div>
	);
	const textCols = cfg.swapText ? [ colFor( 'ar' ), colFor( 'en' ) ] : [ colFor( 'en' ), colFor( 'ar' ) ];
	let row = [ ...textCols ];
	if ( logoCell ) {
		if ( logoPos === 'left' ) { row = [ logoCell, ...textCols ]; }
		else if ( logoPos === 'right' ) { row = [ ...textCols, logoCell ]; }
		else { row = [ textCols[ 0 ], logoCell, textCols[ 1 ] ]; }
	}

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Layout', 'woocommerce-orders-invoice-pdf' ) } initialOpen={ true }>
					<SelectControl
						label={ __( 'Logo position', 'woocommerce-orders-invoice-pdf' ) }
						value={ logoPos }
						options={ [
							{ label: __( 'Left', 'woocommerce-orders-invoice-pdf' ), value: 'left' },
							{ label: __( 'Centre', 'woocommerce-orders-invoice-pdf' ), value: 'center' },
							{ label: __( 'Right', 'woocommerce-orders-invoice-pdf' ), value: 'right' },
						] }
						onChange={ changeLogoPos }
					/>
					<ToggleControl
						label={ __( 'Swap EN / AR sides', 'woocommerce-orders-invoice-pdf' ) }
						checked={ !! cfg.swapText }
						onChange={ ( v ) => updateCfg( { swapText: v } ) }
					/>
					<RangeControl
						label={ __( 'Logo width (mm) — 0 = default', 'woocommerce-orders-invoice-pdf' ) }
						value={ cfg.logoWidth || 0 }
						onChange={ ( v ) => updateCfg( { logoWidth: v || 0 } ) }
						min={ 0 }
						max={ 120 }
					/>
					<Button variant="secondary" onClick={ () => { persist( LH_DEFAULT ); setSelected( 'name_en' ); } } style={ { marginTop: 12 } }>
						{ __( 'Reset to default', 'woocommerce-orders-invoice-pdf' ) }
					</Button>
				</PanelBody>
				<PanelBody title={ __( 'Element', 'woocommerce-orders-invoice-pdf' ) } initialOpen={ true }>
					<SelectControl
						label={ __( 'Editing', 'woocommerce-orders-invoice-pdf' ) }
						value={ selected }
						options={ [ ...LH_TEXT_FIELDS.map( ( f ) => ( { label: f.label, value: f.key } ) ), { label: __( 'Logo', 'woocommerce-orders-invoice-pdf' ), value: 'logo' } ] }
						onChange={ setSelected }
					/>
					<ToggleControl
						label={ __( 'Visible', 'woocommerce-orders-invoice-pdf' ) }
						checked={ false !== sel.visible }
						onChange={ ( v ) => updateEl( selected, { visible: v } ) }
					/>
					{ isText && (
						<>
							<SelectControl
								label={ __( 'Align', 'woocommerce-orders-invoice-pdf' ) }
								value={ sel.align || 'left' }
								options={ [
									{ label: __( 'Left', 'woocommerce-orders-invoice-pdf' ), value: 'left' },
									{ label: __( 'Center', 'woocommerce-orders-invoice-pdf' ), value: 'center' },
									{ label: __( 'Right', 'woocommerce-orders-invoice-pdf' ), value: 'right' },
								] }
								onChange={ ( v ) => updateEl( selected, { align: v } ) }
							/>
							<ToggleControl
								label={ __( 'Bold', 'woocommerce-orders-invoice-pdf' ) }
								checked={ !! sel.bold }
								onChange={ ( v ) => updateEl( selected, { bold: v } ) }
							/>
							<RangeControl
								label={ __( 'Font size (px) — 0 = default', 'woocommerce-orders-invoice-pdf' ) }
								value={ sel.fontSize || 0 }
								onChange={ ( v ) => updateEl( selected, { fontSize: v || 0 } ) }
								min={ 0 }
								max={ 32 }
							/>
							<p style={ { margin: '12px 0 4px' } }>{ __( 'Text colour', 'woocommerce-orders-invoice-pdf' ) }</p>
							<ColorPalette value={ sel.color || '' } colors={ COLORS } onChange={ ( c ) => updateEl( selected, { color: c || '' } ) } />
						</>
					) }
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<div className="woi-lh-edit-row" style={ { display: 'flex', alignItems: 'flex-start', gap: '8px' } }>
					{ row }
				</div>
			</div>
		</>
	);
}

// Bare token save — valid + kses-safe. Layout lives in the woi_pdf_letterhead
// option + the shared header doc-option, not in the block.
export function letterheadSave( { attributes } ) {
	return <div { ...useBlockProps.save( appearanceProps( attributes ) ) }>{ '{{letterhead}}' }</div>;
}
```

- [ ] **Step 3: Wire `token.js`**

In `src/block-editor/blocks/token.js`, add the import after the contactStrip import line:

```js
import { LetterheadEdit, letterheadSave } from './letterhead';
```

Mark the letterhead TOKENS entry — find the line for `woi/letterhead` and add `letterhead: true` to its object (alongside `token: '{{letterhead}}'`).

In the `TOKENS.forEach( ( { name, title, token, tag, preview, image, contact } ) => {` destructure, add `letterhead`:
```js
	TOKENS.forEach( ( { name, title, token, tag, preview, image, contact, letterhead } ) => {
```

In the `registerBlockType` call, change the edit/save lines from:
```js
			edit: contact ? ContactStripEdit : genericEdit,
			save: contact ? contactStripSave : genericSave,
```
to:
```js
			edit: contact ? ContactStripEdit : ( letterhead ? LetterheadEdit : genericEdit ),
			save: contact ? contactStripSave : ( letterhead ? letterheadSave : genericSave ),
```

- [ ] **Step 4: Bump the version (both strings)**

```bash
git show origin/master:woocommerce-orders-invoice-pdf.php | grep "Version:"
```
Take the next free patch above that value (call it X.Y.Z) and set BOTH lines in `woocommerce-orders-invoice-pdf.php`: header `* Version:` (line ~6) and `public string $version = 'X.Y.Z';` (line ~24).

- [ ] **Step 5: Build + JS suite**

```bash
npm run build
npm run test:unit
git status --short assets/js/block-editor/
```
Expected: build "compiled successfully"; JS suite all green (incl. `letterheadModel`); only `assets/js/block-editor/index.js` + `index.asset.php` (and possibly `home/index.asset.php`) modified. If OTHER bundles were wiped, check `webpack.config.js` `output.clean`.

- [ ] **Step 6: Commit**

```bash
git add src/block-editor/blocks/letterhead.js src/block-editor/blocks/token.js src/block-editor/store.js \
  woocommerce-orders-invoice-pdf.php assets/js/block-editor/ assets/js/home/ 2>/dev/null
git commit -m "feat(letterhead): per-element editor + layout panel; wire block; build (vX.Y.Z)"
```

---

### Task 6: Verification

**Files:** none (verification only).

- [ ] **Step 1: Full PHP regression (no new failures)**

```bash
php vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php tests/Unit/Visual/
```
Expected: same error/failure count as the recorded baseline (run once on `origin/master` first to capture it) — no NEW failures from the letterhead change.

- [ ] **Step 2: Local mPDF render (reads the option via stubbed/default path)**

The stock `tools/render-visual-sample.php` renders `starter-invoice.html` (which has the letterhead markup inline), so it does not exercise `section_letterhead`. To verify the real builder, render it via reflection like the contact-strip verification did:

```bash
php -r '
define("ABSPATH", getcwd()."/");
require "vendor/autoload.php"; require "vendor/strauss/autoload.php"; require "includes/Visual/TemplateTokens.php";
if(!function_exists("esc_html")){function esc_html($s){return htmlspecialchars((string)$s,ENT_QUOTES);}}
if(!function_exists("esc_attr")){function esc_attr($s){return htmlspecialchars((string)$s,ENT_QUOTES);}}
if(!function_exists("wp_kses_post")){function wp_kses_post($s){return $s;}}
$doc=new class{function has_header_logo(){return false;} function header_logo(){} function get_shop_name(){return "Acme Co";} function get_shop_address(){return "1 Main St";}};
$eng=new class{function secondary_shop_name(){return "متجر";} function secondary_shop_address(){return "دبي";}};
$tt=new WOI\PDF\Visual\TemplateTokens();
$m=new ReflectionMethod($tt,"section_letterhead"); $m->setAccessible(true);
$cfg=["swapText"=>true,"logoWidth"=>0,"elements"=>["name_en"=>["visible"=>true,"align"=>"center","bold"=>true],"address_en"=>["visible"=>false],"name_ar"=>["visible"=>true],"address_ar"=>["visible"=>true],"logo"=>["visible"=>true]]];
echo $m->invoke($tt,$doc,$eng,$cfg), "\n";
'
```
Expected: HTML with AR cell before EN cell (swap), no `1 Main St` (EN address hidden), `text-align:center;font-weight:bold` on the EN name. Confirms the builder honours config. (No PDF needed; the inline-style rendering is the same mechanism the contact strip already proved in mPDF.)

- [ ] **Step 3: Clean tree check**

```bash
git status --short
```
Expected: clean (all changes committed; no scratch files).

---

## Self-Review

**Spec coverage:**
- Option-transport, bare-token save → Tasks 2, 3, 5. ✓
- Logo position = shared widened `header` doc-option → Task 1 (whitelist) + Task 5 (editor writes `header`) + Task 2 (render reads `header`). ✓
- `woi_pdf_letterhead` option (swapText/logoWidth/elements) → Tasks 1, 2, 3. ✓
- Per-element controls (text: visible/align/bold/size/colour; logo: visible) → Tasks 2 (render), 5 (editor). ✓
- Column arrangement (logo pos, swap, width) → Tasks 2, 5. ✓
- AR gating (arabic && visible) → Task 2 + `test_letterhead_arabic_off_drops_ar_column`. ✓
- Defaults reproduce today's letterhead → Task 1 default + `test_letterhead_default_three_columns_en_logo_ar`. ✓
- Edge cases (hidden column, empty letterhead, legacy/no option) → Task 2 builder guards + tests. ✓
- Repeat-letterhead inherits config → automatic (running_header calls section_letterhead, no change needed; noted). ✓
- Build + version bump → Task 5. ✓
- Testing (PHPUnit, Jest, render) → Tasks 2, 4, 6. ✓

**Placeholder scan:** none — every code/test step has complete code. Task 5 Step 4 uses `X.Y.Z` deliberately (the exact patch must be read from origin at execution time per the Global Constraints; the command to read it is given).

**Type consistency:** `section_letterhead($document,$engine,?array $config)`, `letterhead_text_cell(string,array,array,array,bool)`, `letterhead_el_style(array)`, `woi_pdf_letterhead()`, `woi_pdf_sanitize_letterhead()`, `woi_pdf_default_letterhead()`, `LH_TEXT_FIELDS`, `LH_DEFAULT`, `lhValueStyle`, `LetterheadEdit`, `letterheadSave`, `saveLetterhead` — names consistent across tasks. Element keys `name_en|address_en|name_ar|address_ar|logo` identical in PHP defaults, sanitiser, builder, JS model, and tests.

## Out of Scope (per spec)

Free cross-column element drag; per-column width sliders (logo width only); editing the name/address text; other sections.
