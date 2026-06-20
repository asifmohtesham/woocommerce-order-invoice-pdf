<?php
namespace WOI\PDF\Tests\Unit\Visual;

use PHPUnit\Framework\TestCase;
use function WOI\PDF\Visual\woi_pdf_visual_sample_data;

class VisualSampleDataTest extends TestCase {

    public function test_sample_data_has_token_keys_and_values(): void {
        $data = woi_pdf_visual_sample_data();
        $this->assertIsArray( $data );
        // Keyed by {{token}} braces, mirroring TemplateTokens::map output keys.
        $this->assertArrayHasKey( '{{shop_name}}', $data );
        $this->assertArrayHasKey( '{{document_title}}', $data );
        $this->assertArrayHasKey( '{{line_items}}', $data );
        $this->assertArrayHasKey( '{{totals}}', $data );
        // Values are strings; line_items carries table markup.
        $this->assertIsString( $data['{{shop_name}}'] );
        $this->assertStringContainsString( '<table', $data['{{line_items}}'] );
    }
}
