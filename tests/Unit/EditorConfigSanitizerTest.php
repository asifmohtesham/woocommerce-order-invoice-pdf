<?php
namespace WOI\PDF\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Editor\EditorConfigSanitizer;

class EditorConfigSanitizerTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'sanitize_key' )->alias( static fn( $v ) => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ) );
        Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( (string) $v ) );
        Functions\when( 'sanitize_textarea_field' )->alias( static fn( $v ) => trim( (string) $v ) );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function schema(): array {
        return array(
            'price' => array( 'options' => array(
                'label'      => array( 'type' => 'text' ),
                'width'      => array( 'type' => 'number', 'min' => 0, 'max' => 100 ),
                'price_type' => array( 'type' => 'select', 'options' => array( 'single' => 'Single', 'total' => 'Total' ) ),
                'tax'        => array( 'type' => 'select', 'options' => array( 'incl' => 'Incl', 'excl' => 'Excl' ) ),
                'only_discounted' => array( 'type' => 'checkbox', 'description' => 'Only discounted' ),
                'style'      => array( 'type' => 'text' ),
            ) ),
            'sku' => array( 'options' => array( 'label' => array( 'type' => 'text' ) ) ),
        );
    }

    public function test_checkbox_truthy_becomes_one_and_falsey_is_omitted(): void {
        $on  = EditorConfigSanitizer::sanitize_blocks( array( array( 'type' => 'price', 'only_discounted' => true ) ), $this->schema() );
        $off = EditorConfigSanitizer::sanitize_blocks( array( array( 'type' => 'price', 'only_discounted' => '0' ) ), $this->schema() );
        $this->assertSame( '1', $on[1]['only_discounted'] );
        $this->assertArrayNotHasKey( 'only_discounted', $off[1] );
    }

    public function test_select_validates_against_allowed_values(): void {
        $out = EditorConfigSanitizer::sanitize_blocks(
            array( array( 'type' => 'price', 'price_type' => 'total', 'tax' => 'bogus' ) ),
            $this->schema()
        );
        $this->assertSame( 'total', $out[1]['price_type'] );
        $this->assertArrayNotHasKey( 'tax', $out[1] ); // invalid select dropped
    }

    public function test_number_is_clamped_to_min_max(): void {
        Functions\when( 'woi_pdf_templates_normalize_column_width' )->alias( static fn( $v ) => (string) ( 0 + $v ) );
        $out = EditorConfigSanitizer::sanitize_blocks( array( array( 'type' => 'price', 'width' => '250' ) ), $this->schema() );
        $this->assertSame( '100', $out[1]['width'] );
    }

    public function test_rows_are_renumbered_from_one_and_unknown_types_dropped(): void {
        $out = EditorConfigSanitizer::sanitize_blocks(
            array( array( 'type' => 'bogus' ), array( 'type' => 'sku' ) ),
            $this->schema()
        );
        $this->assertSame( array( 1 ), array_keys( $out ) );
        $this->assertSame( 'sku', $out[1]['type'] );
    }

    public function test_unknown_scalar_keys_are_preserved(): void {
        $out = EditorConfigSanitizer::sanitize_blocks(
            array( array( 'type' => 'sku', 'legacy_wiring' => 'keepme' ) ),
            $this->schema()
        );
        $this->assertSame( 'keepme', $out[1]['legacy_wiring'] );
    }
}
