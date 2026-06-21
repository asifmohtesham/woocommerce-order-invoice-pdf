<?php
namespace WOI\PDF\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Rest;

class EditorConfigRestTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        // Stub WP functions before any Rest instantiation.
        Functions\when( 'add_action' )->justReturn( null );
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'get_bloginfo' )->justReturn( '6.5' );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'sanitize_key' )->alias( static fn( $v ) => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ) );
        Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( (string) $v ) );
        Functions\when( 'sanitize_textarea_field' )->alias( static fn( $v ) => trim( (string) $v ) );
        Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** Rest subclass that overrides the EditorSettings-backed seams so the
     *  handler is testable without a WP install / the singleton. */
    private function rest(): Rest {
        return new class extends Rest {
            public array $saved = array();
            protected function editor_schema_columns(): array {
                return array( 'price' => array( 'options' => array(
                    'price_type' => array( 'type' => 'select', 'options' => array( 'single' => 'S', 'total' => 'T' ) ),
                    'tax'        => array( 'type' => 'select', 'options' => array( 'incl' => 'I', 'excl' => 'E' ) ),
                ) ) );
            }
            protected function editor_schema_totals(): array {
                return array( 'total' => array( 'options' => array(
                    'tax' => array( 'type' => 'select', 'options' => array( 'incl' => 'I', 'excl' => 'E' ) ),
                ) ) );
            }
            protected function read_invoice_totals(): array { return array(); }
            protected function read_invoice_columns(): array { return array(); }
            protected function render_line_items_token( string $d, int $o ): array { return array(); }
            protected function render_totals_token( string $d, int $o ): array { return array(); }
            protected function persist_editor_option( array $option ): void { $this->saved = $option; }
        };
    }

    private function request( array $json ) {
        return new class( $json ) {
            public function __construct( private array $json ) {}
            public function get_json_params() { return $this->json; }
            public function get_param( $k ) { return $this->json[ $k ] ?? null; }
        };
    }

    public function test_save_persists_only_provided_sections_with_sanitized_values(): void {
        $rest = $this->rest();
        $req  = $this->request( array(
            'columns' => array( array( 'type' => 'price', 'price_type' => 'total', 'tax' => 'bogus' ) ),
        ) );
        $res = $rest->handle_save_editor_config( $req );
        $this->assertTrue( $res['saved'] );
        $this->assertSame( 'total', $rest->saved['fields_invoice_columns'][1]['price_type'] );
        $this->assertArrayNotHasKey( 'tax', $rest->saved['fields_invoice_columns'][1] ); // invalid dropped
        $this->assertArrayNotHasKey( 'fields_invoice_totals', $rest->saved );            // not provided => untouched
        $this->assertSame( '1', $rest->saved['settings_saved'] );
    }

    public function test_save_totals_and_custom_styles_sections(): void {
        $rest = $this->rest();
        // A REAL stylesheet (selectors + braces) must survive verbatim — it is
        // stored like the classic Customiser textarea via sanitize_textarea_field,
        // NOT gutted by the per-declaration column-style whitelist (final review I1).
        $sheet = ".invoice-table th { color: red; font-size: 12px; }\n.foo > .bar { margin: 0; }";
        $req  = $this->request( array(
            'totals'        => array( array( 'type' => 'total', 'tax' => 'incl' ) ),
            'custom_styles' => $sheet,
        ) );
        $res = $rest->handle_save_editor_config( $req );
        $this->assertSame( 'incl', $rest->saved['fields_invoice_totals'][1]['tax'] );
        $this->assertSame( $sheet, $rest->saved['custom_styles'] );
        // Response echoes the saved custom_styles from the in-memory option (review I1).
        $this->assertSame( $sheet, $res['custom_styles'] );
    }
}
