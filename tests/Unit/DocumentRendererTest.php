<?php
namespace WOI\PDF\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\DocumentRenderer;

class DocumentRendererTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_renderer_instantiates(): void {
        $renderer = new DocumentRenderer();
        $this->assertInstanceOf( DocumentRenderer::class, $renderer );
    }

    public function test_get_output_modes_returns_array(): void {
        $renderer = new DocumentRenderer();
        $modes = $renderer->get_output_modes();
        $this->assertIsArray( $modes );
        $this->assertContains( 'download', $modes );
        $this->assertContains( 'inline', $modes );
        $this->assertContains( 'base64', $modes );
    }
}
