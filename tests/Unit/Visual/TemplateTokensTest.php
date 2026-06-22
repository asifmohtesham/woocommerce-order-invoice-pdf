<?php
namespace WOI\PDF\Tests\Unit\Visual;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Visual\TemplateTokens;

class TemplateTokensTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        // esc_html / esc_attr / wp_kses_post passthrough so assertions are readable.
        Functions\when( 'esc_html' )->returnArg( 1 );
        Functions\when( 'esc_attr' )->returnArg( 1 );
        Functions\when( 'wp_kses_post' )->returnArg( 1 );
        Functions\when( 'apply_filters' )->returnArg( 2 );
        // BilingualEngine reads options + dictionary file.
        Functions\when( 'get_option' )->justReturn( array(
            'shop_name_ar'    => 'متجر',
            'shop_address_ar' => 'دبي',
        ) );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function stub_document() {
        return new class {
            public function get_type() { return 'invoice'; }
            public function get_shop_name() { return 'Acme Co'; }
            public function get_shop_address() { return '1 Main St'; }
            public function get_shop_vat_number() { return '100' ; }
            public function get_shop_phone_number() { return '+971' ; }
            public function get_shop_email_address() { return 'a@b.co'; }
            public function get_title() { return 'Tax Invoice'; }
            public function get_order_number() { return '4242'; }
            public function get_payment_method() { return 'Card'; }
            public function get_billing_address() { return 'John<br>Dubai'; }
            public function has_header_logo() { return true; }
            public function header_logo() { echo '<img src="x.png">'; }
            public function number( $t ) { echo 'INV-7'; }
            public function date( $t ) { echo '2026-06-18'; }
            public function get_setting( $k ) { return ''; }
        };
    }

    public function test_scalar_tokens_resolve_and_escape(): void {
        // Use an anonymous subclass that overrides the protected fetch_* seams
        // so we never touch the real woi_pdf_templates_* globals (avoids Patchwork DefinedTooEarly).
        $tokens = new class extends TemplateTokens {
            protected function fetch_table_headers( $d ): array { return array(); }
            protected function fetch_table_body( $d ): array { return array(); }
            protected function fetch_totals( $d ): array { return array(); }
        };
        $map = $tokens->map( $this->stub_document() );

        $this->assertSame( 'Acme Co', $map['{{shop_name}}'] );
        $this->assertSame( '1 Main St', $map['{{shop_address}}'] );
        $this->assertSame( 'Tax Invoice', $map['{{document_title}}'] );
        $this->assertSame( 'INV-7', $map['{{invoice_number}}'] );
        $this->assertSame( '2026-06-18', $map['{{invoice_date}}'] );
        $this->assertSame( '4242', $map['{{order_number}}'] );
        $this->assertSame( '<img src="x.png">', $map['{{logo}}'] );
        $this->assertSame( 'متجر', $map['{{shop_name_ar}}'] );
    }

    public function test_merge_replaces_known_and_strips_unknown_tokens(): void {
        // Anonymous subclass with empty fetch_* seams — no Patchwork stubs needed.
        $tokens = new class extends TemplateTokens {
            protected function fetch_table_headers( $d ): array { return array(); }
            protected function fetch_table_body( $d ): array { return array(); }
            protected function fetch_totals( $d ): array { return array(); }
        };
        $html = '<h1>{{document_title}}</h1><p>{{shop_name}}</p><i>{{bogus}}</i>';
        $out  = $tokens->merge( $html, $this->stub_document() );

        $this->assertStringContainsString( '<h1>Tax Invoice</h1>', $out );
        $this->assertStringContainsString( '<p>Acme Co</p>', $out );
        $this->assertStringNotContainsString( '{{', $out );
        $this->assertStringContainsString( '<i></i>', $out );
    }

    public function test_block_tokens_render_tables(): void {
        // Anonymous subclass returning fixture data via the protected seams.
        $tokens = new class extends TemplateTokens {
            protected function fetch_table_headers( $d ): array {
                return array( array( 'class' => 'sku', 'title' => 'SKU' ) );
            }
            protected function fetch_table_body( $d ): array {
                return array( array( array( 'class' => 'sku', 'data' => 'A-1' ) ) );
            }
            protected function fetch_totals( $d ): array {
                return array( array( 'class' => 'total', 'label' => 'Total', 'value' => 'AED 10' ) );
            }
        };
        $map = $tokens->map( $this->stub_document() );

        $this->assertStringContainsString( '<table class="order-details">', $map['{{line_items}}'] );
        $this->assertStringContainsString( 'A-1', $map['{{line_items}}'] );
        $this->assertStringContainsString( '<table class="totals-table">', $map['{{totals}}'] );
        $this->assertStringContainsString( 'AED 10', $map['{{totals}}'] );
    }

    /**
     * A configured column width must be emitted as an inline style on the
     * line-items th AND td (the same convention the classic templates use).
     * Without this the editor's width edit is dropped on the server render and
     * visibly "collapses back" to the static CSS width.
     */
    public function test_line_item_columns_emit_configured_width(): void {
        $tokens = new class extends TemplateTokens {
            protected function fetch_table_headers( $d ): array {
                return array( array( 'class' => 'sku', 'title' => 'SKU', 'width' => '20' ) );
            }
            protected function fetch_table_body( $d ): array {
                return array( array( array( 'class' => 'sku', 'data' => 'A-1', 'width' => '20' ) ) );
            }
            protected function fetch_totals( $d ): array { return array(); }
        };
        $items = $tokens->map( $this->stub_document() )['{{line_items}}'];

        $this->assertStringContainsString( '<th class="sku" style="width: 20%;"', $items );
        $this->assertStringContainsString( '<td class="sku" style="width: 20%;"', $items );
    }

    /**
     * Block tokens carry trusted HTML (shop address <br/>, product markup,
     * wc_price() spans) and must render that markup — not show it as escaped
     * text — while plain-text scalars stay escaped. Stub esc_html to actually
     * escape and wp_kses_post to pass through so a regression to esc_html on a
     * block token would fail here.
     */
    public function test_block_tokens_preserve_html_scalars_stay_escaped(): void {
        Functions\when( 'esc_html' )->alias( fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES ) );
        Functions\when( 'wp_kses_post' )->returnArg( 1 );
        // Secondary (Arabic) shop address is raw multi-line admin text.
        Functions\when( 'get_option' )->justReturn( array(
            'shop_name_ar'    => 'متجر',
            'shop_address_ar' => "دبي\n<X>",
        ) );

        $doc = new class {
            public function get_type() { return 'invoice'; }
            public function get_shop_name() { return 'Acme & Co'; }
            public function get_shop_address() { return 'Al Buteen<br />Dubai'; }
            public function get_shop_vat_number() { return '100'; }
            public function get_shop_phone_number() { return '+971'; }
            public function get_shop_email_address() { return 'a@b.co'; }
            public function get_title() { return 'Tax Invoice'; }
            public function get_order_number() { return '4242'; }
            public function get_payment_method() { return 'Card'; }
            public function get_billing_address() { return 'John<br>Dubai'; }
            public function has_header_logo() { return false; }
            public function header_logo() {}
            public function number( $t ) { echo 'INV-7'; }
            public function date( $t ) { echo '2026-06-18'; }
            public function get_setting( $k ) { return ''; }
        };

        $tokens = new class extends TemplateTokens {
            protected function fetch_table_headers( $d ): array { return array(); }
            protected function fetch_table_body( $d ): array {
                return array( array( array( 'class' => 'description', 'data' => '<span class="item-name">Belt</span>' ) ) );
            }
            protected function fetch_totals( $d ): array {
                return array( array( 'class' => 'total', 'label' => 'Total', 'value' => '<bdi>AED 10</bdi>' ) );
            }
        };
        $map = $tokens->map( $doc );

        // Block tokens keep their markup intact.
        $this->assertStringContainsString( 'Al Buteen<br />Dubai', $map['{{shop_address}}'] );
        $this->assertStringContainsString( '<span class="item-name">Belt</span>', $map['{{line_items}}'] );
        $this->assertStringContainsString( '<bdi>AED 10</bdi>', $map['{{totals}}'] );

        // Secondary address: newlines become <br/>, content stays escaped.
        $this->assertStringContainsString( '<br />', $map['{{shop_address_ar}}'] );
        $this->assertStringContainsString( '&lt;X&gt;', $map['{{shop_address_ar}}'] );
        $this->assertStringNotContainsString( '<X>', $map['{{shop_address_ar}}'] );

        // Plain-text scalar is still escaped.
        $this->assertSame( 'Acme &amp; Co', $map['{{shop_name}}'] );
    }

    /**
     * Bilingual column headers must place a <br> between the primary and
     * secondary label so mPDF stacks them (English over Arabic). mPDF ignores
     * display:block on inline <span>s inside a <th>, so a real line break is
     * required — display:block alone leaves them jammed on one line.
     */
    public function test_line_item_headers_break_between_primary_and_secondary(): void {
        $tokens = new class extends TemplateTokens {
            protected function fetch_table_headers( $d ): array {
                return array(
                    array( 'class' => 'total', 'title' => 'Total', 'secondary' => 'المبلغ' ),
                    array( 'class' => 'quantity', 'title' => 'Qty' ), // no secondary
                );
            }
            protected function fetch_table_body( $d ): array { return array(); }
            protected function fetch_totals( $d ): array { return array(); }
        };
        $map = $tokens->map( $this->stub_document() );

        // Primary span, then a <br>, then the secondary span — in that order. The
        // secondary carries an inline grey colour because mPDF only colours a span
        // via inheritance/inline, never via the .order-details th descendant rule.
        $this->assertStringContainsString(
            '<span class="woi-lbl-primary">Total</span><br><span class="woi-lbl-secondary" style="color:#8A8378" dir="rtl">المبلغ</span>',
            $map['{{line_items}}']
        );
        // A header with no secondary must NOT emit a stray <br>.
        $this->assertStringContainsString( '<span class="woi-lbl-primary">Qty</span></th>', $map['{{line_items}}'] );
        $this->assertStringNotContainsString( '<span class="woi-lbl-primary">Qty</span><br>', $map['{{line_items}}'] );
    }

    /**
     * Totals labels get the same <br> break between primary and secondary so
     * mPDF stacks them. When no secondary exists, the primary renders alone
     * with no stray <br>.
     */
    public function test_totals_break_between_primary_and_secondary_and_render_without_secondary(): void {
        $tokens = new class extends TemplateTokens {
            protected function fetch_table_headers( $d ): array { return array(); }
            protected function fetch_table_body( $d ): array { return array(); }
            protected function fetch_totals( $d ): array {
                return array(
                    array( 'class' => 'total grand-total', 'label' => 'Total', 'value' => 'AED 10', 'secondary' => 'المجموع' ),
                    array( 'class' => 'subtotal', 'label' => 'Subtotal', 'value' => 'AED 8' ),
                    array( 'class' => 'vat', 'label' => 'VAT', 'value' => 'AED 2', 'secondary' => 'ضريبة' ),
                );
            }
        };
        $map = $tokens->map( $this->stub_document() );

        // Grand-total: primary, <br>, then secondary with NO inline colour — its <th>
        // carries the accent colour, which the secondary should inherit.
        $this->assertStringContainsString(
            '<span class="woi-lbl-primary">Total</span><br><span class="woi-lbl-secondary" dir="rtl">المجموع</span>',
            $map['{{totals}}']
        );
        // A non-grand-total row's secondary gets the inline grey (mPDF won't apply the
        // descendant colour rule, so it is set inline to match the browser preview).
        $this->assertStringContainsString(
            '<span class="woi-lbl-primary">VAT</span><br><span class="woi-lbl-secondary" style="color:#8A8378" dir="rtl">ضريبة</span>',
            $map['{{totals}}']
        );
        // No-secondary row renders its primary label and no stray <br>.
        $this->assertStringContainsString( '<span class="woi-lbl-primary">Subtotal</span></span>', $map['{{totals}}'] );
        $this->assertStringNotContainsString( '<span class="woi-lbl-primary">Subtotal</span><br>', $map['{{totals}}'] );
    }

    /**
     * mPDF ignores the `td.thumbnail img` CSS width rule and sizes the image from
     * its width/height attributes (90px), making PDF rows far taller than the
     * browser preview. The renderer must strip those attributes and set the size
     * inline (the only image-width lever mPDF honours).
     */
    public function test_thumbnail_image_is_inline_sized_and_attributes_stripped(): void {
        $tokens = new class extends TemplateTokens {
            protected function fetch_table_headers( $d ): array { return array(); }
            protected function fetch_table_body( $d ): array {
                return array(
                    array(
                        array( 'class' => 'thumbnail', 'data' => '<img width="90" height="90" src="data:image/png;base64,AAAA" alt="x">' ),
                        array( 'class' => 'description', 'data' => 'Widget' ),
                    ),
                );
            }
            protected function fetch_totals( $d ): array { return array(); }
        };
        $map = $tokens->map( $this->stub_document() );

        // Inline width applied, px attributes removed (so mPDF honours 13mm).
        $this->assertStringContainsString( 'style="width:13mm;height:auto"', $map['{{line_items}}'] );
        $this->assertStringNotContainsString( 'width="90"', $map['{{line_items}}'] );
        $this->assertStringNotContainsString( 'height="90"', $map['{{line_items}}'] );
    }

    /**
     * The redesigned document adds Ship To / recipient TRN / bank / QR / website
     * tokens. With a minimal stub (no order, no BACS) they must be present and
     * degrade to safe values rather than fatal — the QR falls back to a styled
     * placeholder so a missing mpdf/qrcode package never breaks the render.
     */
    public function test_new_section_tokens_present_and_degrade_safely(): void {
        $tokens = new class extends TemplateTokens {
            protected function fetch_table_headers( $d ): array { return array(); }
            protected function fetch_table_body( $d ): array { return array(); }
            protected function fetch_totals( $d ): array { return array(); }
        };
        $map = $tokens->map( $this->stub_document() );

        $this->assertArrayHasKey( '{{shipping_address}}', $map );
        $this->assertArrayHasKey( '{{recipient_trn}}', $map );
        $this->assertArrayHasKey( '{{bank_details}}', $map );
        $this->assertArrayHasKey( '{{shop_website}}', $map );
        $this->assertArrayHasKey( '{{amount_words}}', $map );
        $this->assertArrayHasKey( '{{qr_code}}', $map );

        // No order / no TRN / no BACS → empty, not an error.
        $this->assertSame( '', $map['{{recipient_trn}}'] );
        $this->assertSame( '', $map['{{bank_details}}'] );
        // mpdf/qrcode is a (Strauss-prefixed) dependency, so the QR token emits a
        // browser- AND mPDF-renderable SVG data-URI <img> with the default payload
        // (NOT mPDF's proprietary <barcode>, which is invisible in the editor canvas).
        // The placeholder is the safety net only when the package is somehow absent.
        $this->assertStringContainsString( '<img', $map['{{qr_code}}'] );
        $this->assertStringContainsString( 'data:image/svg+xml;base64,', $map['{{qr_code}}'] );
        $this->assertStringNotContainsString( '<barcode', $map['{{qr_code}}'] );
    }

    /**
     * The QR must render in BOTH the editor canvas (browser) and the PDF. mPDF's
     * <barcode type="QR"> renders only in mPDF — a browser shows nothing — so the
     * canvas QR cell was blank for real orders. The QR is now a server-generated
     * SVG embedded as a data-URI <img>, which both surfaces render identically.
     */
    public function test_qr_renders_as_svg_data_uri_image_for_canvas_pdf_parity(): void {
        $tokens = new class extends TemplateTokens {
            protected function fetch_table_headers( $d ): array { return array(); }
            protected function fetch_table_body( $d ): array { return array(); }
            protected function fetch_totals( $d ): array { return array(); }
        };
        $qr = $tokens->map( $this->stub_document() )['{{qr_code}}'];

        // A real <img> with an SVG data-URI — renders in browser and mPDF alike.
        $this->assertStringContainsString( '<img', $qr );
        $this->assertStringContainsString( 'src="data:image/svg+xml;base64,', $qr );
        // Sized inline (mPDF sizes from the SVG's intrinsic px otherwise → ~28mm).
        $this->assertStringContainsString( 'width:20mm;height:20mm', $qr );
        // The decoded payload is a valid SVG (the QR matrix).
        $this->assertMatchesRegularExpression( '/data:image\/svg\+xml;base64,([A-Za-z0-9+\/=]+)/', $qr );
        preg_match( '/base64,([A-Za-z0-9+\/=]+)/', $qr, $m );
        $this->assertStringContainsString( '<svg', (string) base64_decode( $m[1] ) );
        // Never the mPDF-only proprietary tag.
        $this->assertStringNotContainsString( '<barcode', $qr );
    }

    /** An empty QR payload must fall back to the placeholder, never an empty <barcode>. */
    public function test_qr_falls_back_to_placeholder_when_payload_empty(): void {
        // Force an empty payload via the filter; capture the document number too.
        Functions\when( 'apply_filters' )->alias( function ( $hook, $value ) {
            return 'woi_pdf_qr_payload' === $hook ? '' : $value;
        } );
        $tokens = new class extends TemplateTokens {
            protected function fetch_table_headers( $d ): array { return array(); }
            protected function fetch_table_body( $d ): array { return array(); }
            protected function fetch_totals( $d ): array { return array(); }
        };
        $map = $tokens->map( $this->stub_document() );
        $this->assertStringContainsString( 'woi-qr-placeholder', $map['{{qr_code}}'] );
        $this->assertStringNotContainsString( '<barcode', $map['{{qr_code}}'] );
    }

    /**
     * Bank details render from the first WooCommerce BACS account, with IBAN /
     * account number / SWIFT in the mono class for tabular alignment.
     */
    public function test_bank_details_render_from_bacs_account(): void {
        Functions\when( 'get_option' )->alias( function ( $key ) {
            if ( 'woocommerce_bacs_accounts' === $key ) {
                return array(
                    array(
                        'bank_name'      => 'Emirates NBD',
                        'account_name'   => 'Milano Leather Trading LLC',
                        'iban'           => 'AE07 0331 2345 6789',
                        'account_number' => '1023456789001',
                        'bic'            => 'EBILAEAD',
                    ),
                );
            }
            return array( 'shop_name_ar' => 'متجر', 'shop_address_ar' => 'دبي' );
        } );

        $tokens = new class extends TemplateTokens {
            protected function fetch_table_headers( $d ): array { return array(); }
            protected function fetch_table_body( $d ): array { return array(); }
            protected function fetch_totals( $d ): array { return array(); }
        };
        $map = $tokens->map( $this->stub_document() );

        $this->assertStringContainsString( '<table class="woi-bank">', $map['{{bank_details}}'] );
        $this->assertStringContainsString( 'Emirates NBD', $map['{{bank_details}}'] );
        $this->assertStringContainsString( '<td class="mono">AE07 0331 2345 6789</td>', $map['{{bank_details}}'] );
        $this->assertStringContainsString( 'EBILAEAD', $map['{{bank_details}}'] );
    }

    /**
     * Section tokens emit whole canonical visual-document.css sections with their
     * leaf values already resolved (no nested {{tokens}} left for the single-pass
     * merge). These power the block editor's section blocks / default template.
     */
    public function test_section_tokens_emit_canonical_sections(): void {
        $tokens = new class extends TemplateTokens {
            protected function fetch_table_headers( $d ): array { return array(); }
            protected function fetch_table_body( $d ): array { return array(); }
            protected function fetch_totals( $d ): array { return array(); }
        };
        $map = $tokens->map( $this->stub_document() );

        $this->assertStringContainsString( '<table class="woi-letterhead">', $map['{{letterhead}}'] );
        $this->assertStringContainsString( 'Acme Co', $map['{{letterhead}}'] );
        $this->assertStringNotContainsString( '{{', $map['{{letterhead}}'] );
        $this->assertStringContainsString( '<table class="woi-contact">', $map['{{contact_strip}}'] );
        $this->assertStringContainsString( '<table class="woi-titlebar">', $map['{{title_meta}}'] );
        $this->assertStringContainsString( 'woi-lbl-secondary', $map['{{title_meta}}'] );
        $this->assertStringContainsString( '<table class="woi-parties">', $map['{{parties}}'] );
        $this->assertStringContainsString( '<table class="woi-lower">', $map['{{lower}}'] );
        $this->assertStringContainsString( '<table class="woi-sign">', $map['{{signature}}'] );
        $this->assertStringContainsString( 'class="woi-footer"', $map['{{footer}}'] );
    }

    /**
     * The company-stamp placeholder must render as an SVG data-URI <img> (a true
     * dashed circle in BOTH the editor canvas and the PDF). mPDF ignores width/
     * height, line-height and border-radius:50% on the old .woi-stamp-ring <div>,
     * collapsing it to a tiny box — so the stamp is now an SVG, like the QR.
     */
    public function test_company_stamp_renders_as_svg_circle_image(): void {
        $tokens = new class extends TemplateTokens {
            protected function fetch_table_headers( $d ): array { return array(); }
            protected function fetch_table_body( $d ): array { return array(); }
            protected function fetch_totals( $d ): array { return array(); }
        };
        $sig = $tokens->map( $this->stub_document() )['{{signature}}'];

        $this->assertStringContainsString( 'class="woi-stamp-img"', $sig );
        $this->assertStringContainsString( 'src="data:image/svg+xml;base64,', $sig );
        // The decoded image is an SVG with a <circle>.
        preg_match( '/woi-stamp-img" style="[^"]*" src="data:image\/svg\+xml;base64,([A-Za-z0-9+\/=]+)/', $sig, $m );
        $decoded = (string) base64_decode( $m[1] ?? '' );
        $this->assertStringContainsString( '<svg', $decoded );
        $this->assertStringContainsString( '<circle', $decoded );
        // Never the old div that mPDF collapses to a box.
        $this->assertStringNotContainsString( 'woi-stamp-ring', $sig );
    }

    /**
     * Thumbnails author option OFF must drop the thumbnail column from both the
     * header and every body row (mPDF can't display:none a table column, so the
     * column is removed at the source).
     */
    public function test_thumbs_off_drops_thumbnail_column(): void {
        Functions\when( 'get_option' )->alias( function ( $key ) {
            if ( 'woi_pdf_visual_doc_options' === $key ) {
                return array( 'thumbs' => 'off' );
            }
            return array( 'shop_name_ar' => 'متجر', 'shop_address_ar' => 'دبي' );
        } );

        $tokens = new class extends TemplateTokens {
            protected function fetch_table_headers( $d ): array {
                return array(
                    array( 'class' => 'position', 'title' => 'Sr.' ),
                    array( 'class' => 'thumbnail', 'title' => '' ),
                    array( 'class' => 'sku', 'title' => 'SKU' ),
                );
            }
            protected function fetch_table_body( $d ): array {
                return array( array(
                    array( 'class' => 'position', 'data' => '1' ),
                    array( 'class' => 'thumbnail', 'data' => '<img src="x.png">' ),
                    array( 'class' => 'sku', 'data' => 'A-1' ),
                ) );
            }
            protected function fetch_totals( $d ): array { return array(); }
        };
        $map = $tokens->map( $this->stub_document() );

        $this->assertStringNotContainsString( 'class="thumbnail"', $map['{{line_items}}'] );
        $this->assertStringNotContainsString( 'x.png', $map['{{line_items}}'] );
        $this->assertStringContainsString( 'A-1', $map['{{line_items}}'] );
    }

    public function test_letterhead_token_present_when_repeat_off(): void {
        $tokens = new class extends TemplateTokens {
            protected function fetch_table_headers( $d ): array { return array(); }
            protected function fetch_table_body( $d ): array { return array(); }
            protected function fetch_totals( $d ): array { return array(); }
            protected function repeat_letterhead_enabled(): bool { return false; }
        };
        $map = $tokens->map( $this->stub_document() );
        $this->assertStringContainsString( '<table class="woi-letterhead">', $map['{{letterhead}}'] );
    }

    public function test_letterhead_token_empty_when_repeat_on(): void {
        $tokens = new class extends TemplateTokens {
            protected function fetch_table_headers( $d ): array { return array(); }
            protected function fetch_table_body( $d ): array { return array(); }
            protected function fetch_totals( $d ): array { return array(); }
            protected function repeat_letterhead_enabled(): bool { return true; }
        };
        $map = $tokens->map( $this->stub_document() );
        $this->assertSame( '', $map['{{letterhead}}'] );
    }

    public function test_running_header_wraps_letterhead(): void {
        $tokens = new class extends TemplateTokens {
            protected function fetch_table_headers( $d ): array { return array(); }
            protected function fetch_table_body( $d ): array { return array(); }
            protected function fetch_totals( $d ): array { return array(); }
        };
        $html = $tokens->running_header( $this->stub_document() );
        $this->assertStringStartsWith( '<htmlpageheader name="woiHeader">', $html );
        $this->assertStringContainsString( '<table class="woi-letterhead">', $html );
        $this->assertStringEndsWith( '</htmlpageheader>', $html );
    }

    /**
     * Fix C: a throwing block renderer must degrade to '' and must not prevent
     * other tokens from resolving.
     */
    public function test_throwing_block_renderer_degrades_to_empty_string(): void {
        // Subclass that overrides fetch_table_body to throw.
        $tokens = new class extends TemplateTokens {
            protected function fetch_table_headers( $d ): array { return array(); }
            protected function fetch_table_body( $d ): array {
                throw new \RuntimeException( 'Simulated renderer failure' );
            }
            protected function fetch_totals( $d ): array { return array(); }
        };
        $map = $tokens->map( $this->stub_document() );

        // The throwing block must yield empty string, not propagate the exception.
        $this->assertSame( '', $map['{{line_items}}'], '{{line_items}} must be empty on throw' );

        // Scalar tokens must still resolve despite the block failure.
        $this->assertSame( 'Acme Co', $map['{{shop_name}}'], 'Scalar tokens must still resolve after a block throw' );
        // {{totals}} used a non-throwing fetch_totals path and should produce an empty table.
        $this->assertStringContainsString( 'totals-table', $map['{{totals}}'], '{{totals}} must still render' );
    }

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
}
