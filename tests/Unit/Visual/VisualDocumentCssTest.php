<?php
namespace WOI\PDF\Tests\Unit\Visual;

use PHPUnit\Framework\TestCase;

class VisualDocumentCssTest extends TestCase {

    private function repo_root(): string {
        return dirname( __DIR__, 3 ); // tests/Unit/Visual -> repo root
    }

    public function test_css_file_exists_and_has_fidelity_rules(): void {
        $css = (string) file_get_contents( $this->repo_root() . '/templates/_visual/visual-document.css' );

        $this->assertStringContainsString( 'width: 13mm !important', $css );
        $this->assertStringContainsString( '.woi-lbl-primary { display: block; }', $css );
        $this->assertStringContainsString( '.order-details .vat-split { width: 12%; }', $css );
    }
}
