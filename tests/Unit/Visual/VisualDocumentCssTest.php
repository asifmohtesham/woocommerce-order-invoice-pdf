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
        // Labels are inline; a <br> in the markup does the stacking (mPDF ignores
        // display:block on inline spans inside <th>). See TemplateTokens.
        $this->assertStringContainsString( '.woi-lbl-primary { display: inline; }', $css );
        $this->assertStringContainsString( '.order-details .vat-split { width: 12%; }', $css );
        // Thumbnail column uses an absolute width (mPDF ignores % here and lets the
        // image overflow), and images are display:block so galleries stack contained.
        $this->assertStringContainsString( 'td.thumbnail { width: 15mm; }', $css );
        $this->assertStringContainsString( 'td.thumbnail img { display: block;', $css );
    }
}
