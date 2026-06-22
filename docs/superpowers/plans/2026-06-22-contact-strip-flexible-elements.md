# Contact Strip Per-Element Flexibility Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let each element of the Block Invoice Template's Contact strip (TRN / Tel / Email) be individually aligned, styled (bold / size / colour), shown-hidden, and reordered via drag-and-drop in the editor — and fix the off-centre "Tel" bug.

**Architecture:** The Contact strip stays a single block. It gains an ordered `items` attribute serialised into the saved wrapper as `data-woi-contact-config`. A config-aware PHP pre-pass in `TemplateTokens::merge()` reads that config and rebuilds the strip table (order / visibility / per-cell align / per-value inline style / equal column widths). Values stay dynamic (existing `{{trn}}` / `{{shop_phone}}` / `{{shop_email}}` tokens). The editor renders the strip as draggable chips with a per-element controls panel.

**Tech Stack:** PHP 7.4 (mPDF render path), WordPress block editor (React via `@wordpress/scripts`), PHPUnit + Brain Monkey, Jest (`wp-scripts test-unit-js`).

## Global Constraints

- **PHP floor 7.4** — `?array` nullable params and typed properties are allowed; do NOT use union types or named args.
- **Inline styles only** for per-element styling — mPDF does not load the theme stylesheet and ignores `body[data-*]` descendant selectors, so align/weight/size/colour must be emitted as inline `style` on the `<td>` / value `<span>`. (Confirmed in `appearanceStyle.js` header comment.)
- **Escaping:** values via `esc_html()`, attribute/style strings via `esc_attr()`.
- **Version bump:** edit BOTH strings in `woocommerce-orders-invoice-pdf.php` — line 6 header `* Version:` and line 24 `public string $version` — from `1.5.64` to `1.5.65`. `WOI_PDF_VERSION` is the shared JS/CSS cache-bust key.
- **PHPUnit harness:** always run with `-d auto_prepend_file=tests/bootstrap.php` (without it phpunit dies silently — ABSPATH guard). The full suite has ~25 known-baseline failures from unrelated unloaded helpers; scope runs with `--filter TemplateTokensTest`.
- **Default attribute invariance:** an unstyled/legacy block must serialise unchanged. The contact block ships a `deprecated` entry so existing stored templates migrate without an "invalid content" warning.

---

### Task 1: PHP — equal-width centring + config-capable strip builder

**Files:**
- Modify: `includes/Visual/TemplateTokens.php` (replace `section_contact_strip()` at lines 259-268; add `contact_value_style()` helper after it)
- Test: `tests/Unit/Visual/TemplateTokensTest.php` (add one test method)

**Interfaces:**
- Consumes: `$document->get_shop_vat_number()`, `get_shop_phone_number()`, `get_shop_email_address()` (string accessors, already used today).
- Produces:
  - `private function section_contact_strip( $document, ?array $config = null ): string` — builds `<table class="woi-contact">` with equal-width cells; `null` config = historical default layout.
  - `private function contact_value_style( array $item ): string` — inline style string from `bold` / `fontSize` / `color`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/Visual/TemplateTokensTest.php` (before the closing `}`):

```php
    /**
     * The contact strip must use equal-width cells so the middle item sits at the
     * true page centre (the old auto-width layout drifted with content length).
     * Default (no config) reproduces the TRN-left / Tel-centre / Email-right order.
     */
    public function test_contact_strip_default_has_equal_widths_and_centred_middle(): void {
        $tokens = new class extends TemplateTokens {
            protected function fetch_table_headers( $d ): array { return array(); }
            protected function fetch_table_body( $d ): array { return array(); }
            protected function fetch_totals( $d ): array { return array(); }
        };
        $strip = $tokens->map( $this->stub_document() )['{{contact_strip}}'];

        // Three equal thirds.
        $this->assertSame( 3, substr_count( $strip, 'width:33.3333%' ) );
        // Positional alignment preserved.
        $this->assertStringContainsString( 'width:33.3333%;text-align:left', $strip );
        $this->assertStringContainsString( 'width:33.3333%;text-align:center', $strip );
        $this->assertStringContainsString( 'width:33.3333%;text-align:right', $strip );
        // Values still present and labelled.
        $this->assertStringContainsString( '<span class="woi-contact-k">Tel</span>', $strip );
        $this->assertStringContainsString( '+971', $strip );
    }
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php \
  --filter test_contact_strip_default_has_equal_widths_and_centred_middle \
  tests/Unit/Visual/TemplateTokensTest.php
```
Expected: FAIL — the current markup has no `width:` style (auto widths).

- [ ] **Step 3: Replace `section_contact_strip()` and add the style helper**

In `includes/Visual/TemplateTokens.php`, replace the whole method at lines 259-268 with:

```php
    /**
     * Contact strip (TRN / Tel / Email). Equal-width cells put the middle item at
     * the true page centre — the previous auto-width 3-cell layout drifted with
     * content length. $config is the ordered per-element list from the block's
     * data-woi-contact-config; null = the historical default layout.
     *
     * @param object             $document Order document (or stub).
     * @param array<int,mixed>|null $config  [{field,visible,align,bold,fontSize,color}, ...]
     */
    private function section_contact_strip( $document, ?array $config = null ): string {
        $values = array(
            'trn'   => esc_html( (string) $document->get_shop_vat_number() ),
            'tel'   => esc_html( (string) $document->get_shop_phone_number() ),
            'email' => esc_html( (string) $document->get_shop_email_address() ),
        );
        $labels = array( 'trn' => 'TRN', 'tel' => 'Tel', 'email' => 'Email' );

        if ( null === $config ) {
            $config = array(
                array( 'field' => 'trn',   'visible' => true, 'align' => 'left' ),
                array( 'field' => 'tel',   'visible' => true, 'align' => 'center' ),
                array( 'field' => 'email', 'visible' => true, 'align' => 'right' ),
            );
        }

        // Keep visible items with a known field, in their configured order.
        $items = array();
        foreach ( $config as $item ) {
            if ( ! is_array( $item ) ) { continue; }
            $field = isset( $item['field'] ) ? (string) $item['field'] : '';
            if ( ! isset( $values[ $field ] ) ) { continue; }
            if ( array_key_exists( 'visible', $item ) && ! $item['visible'] ) { continue; }
            $items[] = $item;
        }
        if ( empty( $items ) ) { return ''; }

        $count = count( $items );
        // Equal thirds: 33.3333% (trailing zeros trimmed). 2 visible -> 50%.
        $width = rtrim( rtrim( sprintf( '%.4f', 100 / $count ), '0' ), '.' ) . '%';

        $cells = '';
        foreach ( $items as $i => $item ) {
            $field = (string) $item['field'];
            $align = ( isset( $item['align'] ) && in_array( $item['align'], array( 'left', 'center', 'right' ), true ) )
                ? $item['align']
                : ( 0 === $i ? 'left' : ( $count - 1 === $i ? 'right' : 'center' ) );
            $td_style  = 'width:' . $width . ';text-align:' . $align;
            $val_style = $this->contact_value_style( $item );
            $val_attr  = '' !== $val_style ? ' style="' . esc_attr( $val_style ) . '"' : '';
            $cells .= '<td style="' . esc_attr( $td_style ) . '">'
                . '<span class="woi-contact-k">' . $labels[ $field ] . '</span> '
                . '<span class="woi-contact-v"' . $val_attr . '>' . $values[ $field ] . '</span>'
                . '</td>';
        }
        return '<table class="woi-contact"><tr>' . $cells . '</tr></table>';
    }

    /**
     * Inline value style for one contact item. Inline (not class) because mPDF
     * ignores the theme stylesheet's descendant selectors. fontSize is px to
     * match the rest of the block Appearance system; colour is hex-validated.
     */
    private function contact_value_style( array $item ): string {
        $parts = array();
        if ( ! empty( $item['bold'] ) ) {
            $parts[] = 'font-weight:bold';
        }
        if ( ! empty( $item['fontSize'] ) ) {
            $parts[] = 'font-size:' . (int) $item['fontSize'] . 'px';
        }
        if ( ! empty( $item['color'] ) && preg_match( '/^#[0-9a-fA-F]{3,6}$/', (string) $item['color'] ) ) {
            $parts[] = 'color:' . $item['color'];
        }
        return implode( ';', $parts );
    }
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
php vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php \
  --filter test_contact_strip_default_has_equal_widths_and_centred_middle \
  tests/Unit/Visual/TemplateTokensTest.php
```
Expected: PASS.

- [ ] **Step 5: Run the existing contact/section assertions to confirm no regression**

```bash
php vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php \
  --filter test_section_tokens_emit_canonical_sections \
  tests/Unit/Visual/TemplateTokensTest.php
```
Expected: PASS (it only asserts `<table class="woi-contact">` is present, which still holds).

- [ ] **Step 6: Commit**

```bash
git add includes/Visual/TemplateTokens.php tests/Unit/Visual/TemplateTokensTest.php
git commit -m "fix(contact): equal-width cells centre the Tel item; config-capable builder

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 2: PHP — config-aware merge pre-pass

**Files:**
- Modify: `includes/Visual/TemplateTokens.php` (rewrite `merge()` at lines 368-371; add `merge_contact_strip()` after it)
- Test: `tests/Unit/Visual/TemplateTokensTest.php` (add three test methods)

**Interfaces:**
- Consumes: `section_contact_strip( $document, ?array $config )` from Task 1.
- Produces:
  - `public function merge( string $html, $document ): string` — now runs the contact pre-pass before the generic `strtr`.
  - `private function merge_contact_strip( string $html, $document ): string` — replaces a `data-woi-section="contact"` wrapper's `{{contact_strip}}` with the configured strip.

The wrapper the editor emits (Task 4) looks like (attribute value is HTML-entity-encoded JSON):
```html
<div class="wp-block-woi-contact-strip" data-woi-section="contact"
     data-woi-contact-config="[{&quot;field&quot;:&quot;trn&quot;,&quot;visible&quot;:true,...}]">{{contact_strip}}</div>
```

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/Visual/TemplateTokensTest.php`:

```php
    /** Build the editor-style wrapper for a contact config (mimics React attr encoding). */
    private function contact_wrapper( array $config ): string {
        $attr = htmlspecialchars( json_encode( $config ), ENT_QUOTES );
        return '<div data-woi-section="contact" data-woi-contact-config="' . $attr . '">{{contact_strip}}</div>';
    }

    public function test_contact_config_reorders_hides_and_styles(): void {
        $tokens = new class extends TemplateTokens {
            protected function fetch_table_headers( $d ): array { return array(); }
            protected function fetch_table_body( $d ): array { return array(); }
            protected function fetch_totals( $d ): array { return array(); }
        };
        $config = array(
            array( 'field' => 'email', 'visible' => true,  'align' => 'left' ),
            array( 'field' => 'tel',   'visible' => false ),
            array( 'field' => 'trn',   'visible' => true,  'align' => 'right', 'bold' => true, 'fontSize' => 12, 'color' => '#ff0000' ),
        );
        $out = $tokens->merge( $this->contact_wrapper( $config ), $this->stub_document() );

        // Tel hidden -> two visible -> 50% cells.
        $this->assertSame( 2, substr_count( $out, 'width:50%' ) );
        $this->assertStringNotContainsString( '>Tel<', $out );
        // Order: email cell appears before trn cell.
        $this->assertLessThan( strpos( $out, '100' ), strpos( $out, 'a@b.co' ) );
        // TRN styled inline.
        $this->assertStringContainsString( 'width:50%;text-align:right', $out );
        $this->assertStringContainsString( 'font-weight:bold;font-size:12px;color:#ff0000', $out );
        // No stray braces.
        $this->assertStringNotContainsString( '{{', $out );
    }

    public function test_contact_all_hidden_omits_strip(): void {
        $tokens = new class extends TemplateTokens {
            protected function fetch_table_headers( $d ): array { return array(); }
            protected function fetch_table_body( $d ): array { return array(); }
            protected function fetch_totals( $d ): array { return array(); }
        };
        $config = array(
            array( 'field' => 'trn',   'visible' => false ),
            array( 'field' => 'tel',   'visible' => false ),
            array( 'field' => 'email', 'visible' => false ),
        );
        $out = $tokens->merge( $this->contact_wrapper( $config ), $this->stub_document() );
        $this->assertStringNotContainsString( '<table class="woi-contact"', $out );
        $this->assertStringNotContainsString( '{{', $out );
    }

    public function test_contact_bare_token_without_wrapper_uses_default(): void {
        $tokens = new class extends TemplateTokens {
            protected function fetch_table_headers( $d ): array { return array(); }
            protected function fetch_table_body( $d ): array { return array(); }
            protected function fetch_totals( $d ): array { return array(); }
        };
        // Legacy / GrapesJS starter: bare token, no wrapper -> default layout.
        $out = $tokens->merge( '<p>{{contact_strip}}</p>', $this->stub_document() );
        $this->assertStringContainsString( '<table class="woi-contact">', $out );
        $this->assertStringContainsString( 'width:33.3333%', $out );
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
php vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php \
  --filter 'test_contact_config_reorders_hides_and_styles|test_contact_all_hidden_omits_strip|test_contact_bare_token_without_wrapper_uses_default' \
  tests/Unit/Visual/TemplateTokensTest.php
```
Expected: the first two FAIL (the wrapper's token is replaced by the default strtr, ignoring config); the third PASSES already.

- [ ] **Step 3: Rewrite `merge()` and add `merge_contact_strip()`**

In `includes/Visual/TemplateTokens.php`, replace the `merge()` method (lines 368-371) with:

```php
    /**
     * Replace all known tokens, then strip any leftover {{...}} so stray braces
     * never reach the PDF. The contact-strip pre-pass runs first so a block
     * wrapper's per-element config is honoured before the generic strtr.
     */
    public function merge( string $html, $document ): string {
        $html = $this->merge_contact_strip( $html, $document );
        $html = strtr( $html, $this->map( $document ) );
        return (string) preg_replace( '/\{\{[^}]*\}\}/', '', $html );
    }

    /**
     * Replace a block-editor contact-strip wrapper (carrying a per-element
     * data-woi-contact-config) with the configured strip BEFORE the generic
     * strtr. A bare {{contact_strip}} with no wrapper falls through to the
     * default map entry (historical layout). The wrapper div is preserved so any
     * whole-section appearance style on it survives.
     */
    private function merge_contact_strip( string $html, $document ): string {
        if ( false === strpos( $html, 'data-woi-section="contact"' ) ) {
            return $html;
        }
        $pattern = '#<div\b[^>]*\bdata-woi-section="contact"[^>]*>\s*\{\{contact_strip\}\}\s*</div>#';
        return (string) preg_replace_callback(
            $pattern,
            function ( $m ) use ( $document ) {
                $config = null;
                if ( preg_match( '/data-woi-contact-config="([^"]*)"/', $m[0], $cm ) ) {
                    $decoded = json_decode( html_entity_decode( $cm[1], ENT_QUOTES ), true );
                    if ( is_array( $decoded ) ) {
                        $config = $decoded;
                    }
                }
                return str_replace( '{{contact_strip}}', $this->section_contact_strip( $document, $config ), $m[0] );
            },
            $html
        );
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
php vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php \
  --filter 'test_contact_config_reorders_hides_and_styles|test_contact_all_hidden_omits_strip|test_contact_bare_token_without_wrapper_uses_default' \
  tests/Unit/Visual/TemplateTokensTest.php
```
Expected: all three PASS.

- [ ] **Step 5: Run the whole TemplateTokensTest class for regressions**

```bash
php vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php \
  --filter TemplateTokensTest tests/Unit/Visual/TemplateTokensTest.php
```
Expected: all `TemplateTokensTest` tests PASS.

- [ ] **Step 6: Commit**

```bash
git add includes/Visual/TemplateTokens.php tests/Unit/Visual/TemplateTokensTest.php
git commit -m "feat(contact): config-aware merge renders per-element strip from wrapper

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 3: JS — pure contact-strip model + Jest tests

**Files:**
- Create: `src/block-editor/blocks/contactStripModel.js`
- Test: `src/block-editor/blocks/contactStripModel.test.js`

**Interfaces (no `@wordpress/*` imports — kept pure so Jest can import it directly, mirroring `appearanceStyle.js`):**
- Produces:
  - `CONTACT_FIELDS` — `{ trn:{label,token}, tel:{label,token}, email:{label,token} }`
  - `CONTACT_DEFAULT_ITEMS` — ordered default item array.
  - `reorder(items, from, to)` → new array with the item moved (no-op on bad indices).
  - `valueStyle(item)` → React style object from `bold` / `fontSize` / `color`.

- [ ] **Step 1: Write the failing test**

Create `src/block-editor/blocks/contactStripModel.test.js`:

```js
import { reorder, valueStyle, CONTACT_DEFAULT_ITEMS, CONTACT_FIELDS } from './contactStripModel';

describe( 'contactStripModel', () => {
	test( 'default items are trn, tel, email in order, all visible', () => {
		expect( CONTACT_DEFAULT_ITEMS.map( ( i ) => i.field ) ).toEqual( [ 'trn', 'tel', 'email' ] );
		expect( CONTACT_DEFAULT_ITEMS.every( ( i ) => i.visible ) ).toBe( true );
	} );

	test( 'field map exposes label + token for each field', () => {
		expect( CONTACT_FIELDS.trn.token ).toBe( '{{trn}}' );
		expect( CONTACT_FIELDS.tel.token ).toBe( '{{shop_phone}}' );
		expect( CONTACT_FIELDS.email.token ).toBe( '{{shop_email}}' );
	} );

	test( 'reorder moves an item and leaves the original array untouched', () => {
		const items = [ { field: 'a' }, { field: 'b' }, { field: 'c' } ];
		expect( reorder( items, 0, 2 ).map( ( i ) => i.field ) ).toEqual( [ 'b', 'c', 'a' ] );
		expect( items.map( ( i ) => i.field ) ).toEqual( [ 'a', 'b', 'c' ] );
	} );

	test( 'reorder is a no-op on out-of-range or equal indices', () => {
		const items = [ { field: 'a' }, { field: 'b' } ];
		expect( reorder( items, 0, 0 ).map( ( i ) => i.field ) ).toEqual( [ 'a', 'b' ] );
		expect( reorder( items, -1, 1 ).map( ( i ) => i.field ) ).toEqual( [ 'a', 'b' ] );
		expect( reorder( items, 0, 5 ).map( ( i ) => i.field ) ).toEqual( [ 'a', 'b' ] );
	} );

	test( 'valueStyle emits only set properties', () => {
		expect( valueStyle( { bold: false, fontSize: 0, color: '' } ) ).toEqual( {} );
		expect( valueStyle( { bold: true, fontSize: 12, color: '#ff0000' } ) ).toEqual( {
			fontWeight: 'bold',
			fontSize: '12px',
			color: '#ff0000',
		} );
	} );
} );
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
npm run test:unit -- contactStripModel
```
Expected: FAIL — `contactStripModel` module not found.

- [ ] **Step 3: Create the model module**

Create `src/block-editor/blocks/contactStripModel.js`:

```js
/**
 * Pure model for the Contact strip block. NO @wordpress/* imports — kept
 * separate from contactStrip.js (which needs @wordpress/components) so Jest can
 * unit-test the data helpers directly, mirroring appearanceStyle.js.
 */

// field -> { editor label, dynamic value token }.
export const CONTACT_FIELDS = {
	trn:   { label: 'TRN',   token: '{{trn}}' },
	tel:   { label: 'Tel',   token: '{{shop_phone}}' },
	email: { label: 'Email', token: '{{shop_email}}' },
};

// Default layout — reproduces the historical TRN-left / Tel-centre / Email-right.
export const CONTACT_DEFAULT_ITEMS = [
	{ field: 'trn',   visible: true, align: 'left',   bold: false, fontSize: 0, color: '' },
	{ field: 'tel',   visible: true, align: 'center', bold: false, fontSize: 0, color: '' },
	{ field: 'email', visible: true, align: 'right',  bold: false, fontSize: 0, color: '' },
];

// Move items[from] to index `to`, returning a NEW array (no mutation).
export function reorder( items, from, to ) {
	const next = items.slice();
	if ( from < 0 || from >= next.length || to < 0 || to >= next.length || from === to ) {
		return next;
	}
	const [ moved ] = next.splice( from, 1 );
	next.splice( to, 0, moved );
	return next;
}

// React inline-style object for a value span (set properties only).
export function valueStyle( item ) {
	const s = {};
	if ( item.bold ) { s.fontWeight = 'bold'; }
	if ( item.fontSize ) { s.fontSize = item.fontSize + 'px'; }
	if ( item.color ) { s.color = item.color; }
	return s;
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
npm run test:unit -- contactStripModel
```
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add src/block-editor/blocks/contactStripModel.js src/block-editor/blocks/contactStripModel.test.js
git commit -m "feat(contact): pure model (fields, default items, reorder, valueStyle)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 4: JS — editor component, save, deprecation, wiring, build + version bump

**Files:**
- Create: `src/block-editor/blocks/contactStrip.js`
- Modify: `src/block-editor/blocks/token.js` (import + branch the `woi/contact-strip` registration)
- Modify: `woocommerce-orders-invoice-pdf.php` (version bump, lines 6 and 24)
- Build artifact (regenerated): `assets/js/block-editor/index.js` (+ `index.asset.php`)

**Interfaces:**
- Consumes: `CONTACT_FIELDS`, `CONTACT_DEFAULT_ITEMS`, `reorder`, `valueStyle` (Task 3); `appearanceProps`, `APPEARANCE_ATTRS` (`../appearance`); `tokenValue` (`../tokenMerge`); `STORE` (`../previewStore`).
- Produces (consumed by `token.js`):
  - `ContactStripEdit( props )` — block `edit`.
  - `contactStripSave( props )` — block `save`; emits `<div data-woi-section="contact" data-woi-contact-config='<json>'>{{contact_strip}}</div>`.
  - `CONTACT_DEPRECATED` — one-entry deprecation array migrating the old token-only save.

- [ ] **Step 1: Create the editor module**

Create `src/block-editor/blocks/contactStrip.js`:

```js
import { useState } from '@wordpress/element';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, ToggleControl, SelectControl, RangeControl, ColorPalette, Button } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { STORE } from '../previewStore';
import { tokenValue } from '../tokenMerge';
import { appearanceProps, APPEARANCE_ATTRS } from '../appearance';
import { CONTACT_FIELDS, CONTACT_DEFAULT_ITEMS, reorder, valueStyle } from './contactStripModel';

export { CONTACT_DEFAULT_ITEMS };

const COLORS = [
	{ name: __( 'Ink', 'woocommerce-orders-invoice-pdf' ), color: '#1C1A17' },
	{ name: __( 'Accent', 'woocommerce-orders-invoice-pdf' ), color: '#140858' },
	{ name: __( 'Grey', 'woocommerce-orders-invoice-pdf' ), color: '#8A8378' },
];

// Always work with a non-empty item list (guards a cleared attribute).
function effectiveItems( attributes ) {
	return attributes.items && attributes.items.length ? attributes.items : CONTACT_DEFAULT_ITEMS;
}

export function ContactStripEdit( { attributes, setAttributes } ) {
	const items = effectiveItems( attributes );
	const tokens = useSelect( ( select ) => select( STORE ).getTokens(), [] );
	const [ selected, setSelected ] = useState( 0 );
	const [ dragFrom, setDragFrom ] = useState( null );
	const blockProps = useBlockProps( { className: 'woi-contact-edit' } );

	const update = ( idx, patch ) => {
		setAttributes( { items: items.map( ( it, i ) => ( i === idx ? { ...it, ...patch } : it ) ) } );
	};
	const onDrop = ( to ) => {
		if ( null === dragFrom ) { return; }
		setAttributes( { items: reorder( items, dragFrom, to ) } );
		setSelected( to );
		setDragFrom( null );
	};

	const sel = items[ selected ] || items[ 0 ];

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Contact element', 'woocommerce-orders-invoice-pdf' ) } initialOpen={ true }>
					<SelectControl
						label={ __( 'Editing', 'woocommerce-orders-invoice-pdf' ) }
						value={ String( selected ) }
						options={ items.map( ( it, i ) => ( { label: CONTACT_FIELDS[ it.field ].label, value: String( i ) } ) ) }
						onChange={ ( v ) => setSelected( parseInt( v, 10 ) ) }
					/>
					<ToggleControl
						label={ __( 'Visible', 'woocommerce-orders-invoice-pdf' ) }
						checked={ false !== sel.visible }
						onChange={ ( v ) => update( selected, { visible: v } ) }
					/>
					<SelectControl
						label={ __( 'Align', 'woocommerce-orders-invoice-pdf' ) }
						value={ sel.align || 'left' }
						options={ [
							{ label: __( 'Left', 'woocommerce-orders-invoice-pdf' ), value: 'left' },
							{ label: __( 'Center', 'woocommerce-orders-invoice-pdf' ), value: 'center' },
							{ label: __( 'Right', 'woocommerce-orders-invoice-pdf' ), value: 'right' },
						] }
						onChange={ ( v ) => update( selected, { align: v } ) }
					/>
					<ToggleControl
						label={ __( 'Bold', 'woocommerce-orders-invoice-pdf' ) }
						checked={ !! sel.bold }
						onChange={ ( v ) => update( selected, { bold: v } ) }
					/>
					<RangeControl
						label={ __( 'Font size (px) — 0 = default', 'woocommerce-orders-invoice-pdf' ) }
						value={ sel.fontSize || 0 }
						onChange={ ( v ) => update( selected, { fontSize: v || 0 } ) }
						min={ 0 }
						max={ 24 }
					/>
					<p style={ { margin: '12px 0 4px' } }>{ __( 'Text colour', 'woocommerce-orders-invoice-pdf' ) }</p>
					<ColorPalette value={ sel.color || '' } colors={ COLORS } onChange={ ( c ) => update( selected, { color: c || '' } ) } />
					<Button variant="secondary" onClick={ () => setAttributes( { items: CONTACT_DEFAULT_ITEMS } ) } style={ { marginTop: 12 } }>
						{ __( 'Reset to default', 'woocommerce-orders-invoice-pdf' ) }
					</Button>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<div
					className="woi-contact-strip-row"
					style={ { display: 'flex', borderTop: '1.5px solid #140858', borderBottom: '0.5pt solid #D9D4C9', padding: '4px 0' } }
				>
					{ items.map( ( it, i ) => {
						const field = CONTACT_FIELDS[ it.field ];
						const value = tokenValue( field.token, tokens );
						const hidden = false === it.visible;
						return (
							<div
								key={ i }
								draggable
								onDragStart={ () => setDragFrom( i ) }
								onDragOver={ ( e ) => e.preventDefault() }
								onDrop={ () => onDrop( i ) }
								onClick={ () => setSelected( i ) }
								className={ 'woi-contact-chip' + ( i === selected ? ' is-selected' : '' ) }
								style={ {
									flex: 1,
									textAlign: it.align || 'left',
									opacity: hidden ? 0.35 : 1,
									cursor: 'grab',
									outline: i === selected ? '1px solid #007cba' : 'none',
									padding: '2px 4px',
								} }
							>
								<span className="woi-contact-k">{ field.label }</span>{ ' ' }
								<span className="woi-contact-v" style={ valueStyle( it ) }>{ value || '—' }</span>
								{ hidden ? <em style={ { marginLeft: 4, fontSize: 10 } }>{ __( '(hidden)', 'woocommerce-orders-invoice-pdf' ) }</em> : null }
							</div>
						);
					} ) }
				</div>
			</div>
		</>
	);
}

export function contactStripSave( { attributes } ) {
	const items = effectiveItems( attributes );
	const props = useBlockProps.save( {
		...appearanceProps( attributes ),
		'data-woi-section': 'contact',
		'data-woi-contact-config': JSON.stringify( items ),
	} );
	return <div { ...props }>{ '{{contact_strip}}' }</div>;
}

// Old save was a bare token-only div. Migrate stored templates so they don't
// trip block validation ("unexpected or invalid content") on load.
export const CONTACT_DEPRECATED = [
	{
		attributes: { ...APPEARANCE_ATTRS },
		save( { attributes } ) {
			return <div { ...useBlockProps.save( appearanceProps( attributes ) ) }>{ '{{contact_strip}}' }</div>;
		},
		migrate( attributes ) {
			return { ...attributes, items: CONTACT_DEFAULT_ITEMS };
		},
	},
];
```

- [ ] **Step 2: Wire `token.js` to use the contact component**

In `src/block-editor/blocks/token.js`, add the import after line 9:

```js
import { ContactStripEdit, contactStripSave, CONTACT_DEFAULT_ITEMS, CONTACT_DEPRECATED } from './contactStrip';
```

Mark the contact-strip TOKENS entry (line 48) with `contact: true`:

```js
	{ name: 'woi/contact-strip',     title: __( 'Contact strip (section)', 'woocommerce-orders-invoice-pdf' ), token: '{{contact_strip}}',  tag: 'div', preview: '[ Contact strip ]', contact: true },
```

Replace the whole `registerTokenBlocks` function (lines 57-127) with the version below — it pulls the existing generic `edit`/`save` into named closures and branches on `contact`:

```js
export function registerTokenBlocks() {
	TOKENS.forEach( ( { name, title, token, tag, preview, image, contact } ) => {
		const Tag = tag;

		const genericEdit = function ( { attributes, setAttributes } ) {
			const tokens = useSelect( ( select ) => select( STORE ).getTokens(), [] );
			const value = tokenValue( token, tokens );
			const sized = image && attributes.imgWidth;
			const style = { ...appearanceStyle( attributes ), ...( sized ? { width: attributes.imgWidth + 'mm' } : {} ) };
			const className = [ value ? '' : 'woi-token-empty', sized ? 'woi-img-sized' : '' ].filter( Boolean ).join( ' ' ) || undefined;
			const blockProps = useBlockProps( { className, style } );
			const panel = (
				<InspectorControls>
					{ image ? (
						<PanelBody title={ __( 'Image', 'woocommerce-orders-invoice-pdf' ) }>
							<RangeControl
								label={ __( 'Logo width (mm) — 0 = default', 'woocommerce-orders-invoice-pdf' ) }
								value={ attributes.imgWidth || 0 }
								onChange={ ( v ) => setAttributes( { imgWidth: v || 0 } ) }
								min={ 0 }
								max={ 120 }
							/>
						</PanelBody>
					) : null }
					<AppearancePanel attributes={ attributes } setAttributes={ setAttributes } />
				</InspectorControls>
			);
			let inner;
			if ( ! value ) {
				inner = <Tag { ...blockProps }>{ preview }</Tag>;
			} else if ( isHtmlToken( token ) ) {
				inner = <Tag { ...blockProps } dangerouslySetInnerHTML={ { __html: safeHTML( value ) } } />;
			} else {
				inner = <Tag { ...blockProps }>{ value }</Tag>;
			}
			return (
				<>
					<AppearanceToolbar attributes={ attributes } setAttributes={ setAttributes } />
					{ panel }
					{ inner }
				</>
			);
		};

		const genericSave = function ( { attributes } ) {
			if ( image && attributes.imgWidth ) {
				const style = { ...appearanceStyle( attributes ), width: attributes.imgWidth + 'mm' };
				return <Tag { ...useBlockProps.save( { className: 'woi-img-sized', style } ) }>{ token }</Tag>;
			}
			return <Tag { ...useBlockProps.save( appearanceProps( attributes ) ) }>{ token }</Tag>;
		};

		registerBlockType( name, {
			apiVersion: 2,
			title,
			category: 'woi-invoice',
			icon: 'media-document',
			attributes: {
				...APPEARANCE_ATTRS,
				...( image ? { imgWidth: { type: 'number', default: 0 } } : {} ),
				...( contact ? { items: { type: 'array', default: CONTACT_DEFAULT_ITEMS } } : {} ),
			},
			supports: { html: false, reusable: false },
			...( contact ? { deprecated: CONTACT_DEPRECATED } : {} ),
			edit: contact ? ContactStripEdit : genericEdit,
			save: contact ? contactStripSave : genericSave,
		} );
	} );
}
```

- [ ] **Step 3: Bump the version (both strings)**

In `woocommerce-orders-invoice-pdf.php`:
- Line 6: ` * Version:              1.5.64` → ` * Version:              1.5.65`
- Line 24: `	public string $version     = '1.5.64';` → `	public string $version     = '1.5.65';`

- [ ] **Step 4: Build the editor bundle**

```bash
npm run build
```
Expected: completes without errors; `assets/js/block-editor/index.js` is regenerated.

- [ ] **Step 5: Confirm the build emitted only expected asset changes**

```bash
git status --short assets/js/block-editor/
```
Expected: `assets/js/block-editor/index.js` (and `index.asset.php`) modified. If OTHER bundles were wiped/regenerated unexpectedly, the webpack `clean` option is the culprit — check `webpack.config.js` for `output.clean` and set it to `false`, then rebuild.

- [ ] **Step 6: Re-run the JS unit suite (no regressions)**

```bash
npm run test:unit
```
Expected: all JS tests PASS (including the Task 3 `contactStripModel` tests).

- [ ] **Step 7: Commit**

```bash
git add src/block-editor/blocks/contactStrip.js src/block-editor/blocks/token.js \
  woocommerce-orders-invoice-pdf.php assets/js/block-editor/
git commit -m "feat(contact): draggable per-element editor + config save (v1.5.65)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 5: Verification — render the real PDF and confirm centring + config

**Files:**
- (No source changes — verification only. May add a scratch sample under `tools/` if needed; do not commit scratch files.)

**Interfaces:**
- Consumes: the full feature from Tasks 1-4 and the local mPDF render harness (`tools/render-visual-sample.php` + `tools/rasterize.py`).

- [ ] **Step 1: Full PHP regression for the contact path**

```bash
php vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php \
  --filter TemplateTokensTest tests/Unit/Visual/TemplateTokensTest.php
```
Expected: all `TemplateTokensTest` tests PASS.

- [ ] **Step 2: Render the visual sample to a real mPDF PDF**

```bash
php tools/render-visual-sample.php
```
Expected: writes the sample invoice PDF (path printed by the script). This exercises the default (centred) contact strip through real mPDF.

- [ ] **Step 3: Rasterize and eyeball the contact strip**

```bash
python tools/rasterize.py
```
Then open the produced PNG and confirm: the `Tel +971…` value sits at the **horizontal centre** of the strip (equidistant from the TRN and Email cells), not drifting left/right. The three cells are equal thirds.

- [ ] **Step 4: Confirm config path renders (reorder + hide)**

Temporarily wrap a configured strip in the sample to prove the merge path end-to-end. Edit `tools/render-visual-sample.php` (do NOT commit this change) to feed a wrapper instead of a bare token, e.g.:

```php
$config = '[{"field":"email","visible":true,"align":"left"},{"field":"tel","visible":false},{"field":"trn","visible":true,"align":"right","bold":true}]';
$sample = str_replace(
    '{{contact_strip}}',
    '<div data-woi-section="contact" data-woi-contact-config="' . htmlspecialchars( $config, ENT_QUOTES ) . '">{{contact_strip}}</div>',
    $sample
);
```

Re-run Steps 2-3 and confirm: Email appears first (left), Tel is gone, TRN is right-aligned and bold, and the two cells are equal halves. Then `git checkout tools/render-visual-sample.php` to discard the scratch edit.

- [ ] **Step 5: Final clean-tree check**

```bash
git status --short
```
Expected: clean (all feature changes already committed in Tasks 1-4; no leftover scratch edits).

---

## Self-Review

**Spec coverage:**
- Per-element **alignment** → `align` attr + per-`<td>` inline `text-align` (Tasks 1, 4). ✓
- Per-element **text style** (bold/size/colour) → `contact_value_style()` + `valueStyle()` (Tasks 1, 3, 4). ✓
- **Show/hide** → `visible` flag dropped server-side; dimmed in editor (Tasks 1, 4). ✓
- **Drag-and-drop reorder** → `reorder()` + HTML5 drag handlers (Tasks 3, 4). ✓
- **Centring fix** → equal-width cells `100/visibleCount %` (Task 1). ✓
- **Dynamic values preserved** → existing `{{trn}}`/`{{shop_phone}}`/`{{shop_email}}` tokens (Tasks 1, 3). ✓
- **Config-aware render, legacy fallback** → `merge_contact_strip()` pre-pass; bare token → default (Task 2). ✓
- **Edge cases** (malformed config, unknown field, zero-visible, single item) → builder guards + tests (Tasks 1, 2). ✓
- **Backward compat** → `deprecated` migration; absence of wrapper = current output (Tasks 2, 4). ✓
- **Build + cache-bust** → `npm run build` + version bump both strings (Task 4). ✓
- **Testing** (PHPUnit, Jest, local mPDF harness) → Tasks 1-3 unit, Task 5 render. ✓

**Type consistency:** `section_contact_strip($document, ?array $config)`, `contact_value_style(array $item)`, `merge_contact_strip(string, $document)`, `reorder(items, from, to)`, `valueStyle(item)`, `CONTACT_DEFAULT_ITEMS`, `CONTACT_FIELDS`, `ContactStripEdit`, `contactStripSave`, `CONTACT_DEPRECATED` — names match across all tasks.

**Placeholder scan:** none — every code step contains complete code.

## Out of Scope (per spec)

Letterhead per-element flexibility (follow-up slice, same pattern), per-field label editing, Arabic/RTL in the contact strip, native InnerBlocks (Approach B).
