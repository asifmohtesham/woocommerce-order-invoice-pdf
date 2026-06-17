<?php
namespace WOI\PDF\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\TemplateLoader;

class TemplateLoaderTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_locate_returns_plugin_template_when_no_theme_override(): void {
        $plugin_path = dirname( __DIR__, 2 ); // points to plugin root
        Functions\when( 'locate_template' )->justReturn( '' );
        Functions\when( 'apply_filters' )->returnArg( 2 );
        Functions\when( 'trailingslashit' )->alias( fn( $s ) => rtrim( $s, '/\\' ) . '/' );
        Functions\when( 'file_exists' )->justReturn( true );

        $loader = new TemplateLoader( $plugin_path );
        $result = $loader->locate( 'invoice', 'invoice.php', 'Simple' );

        $this->assertStringContainsString( 'Simple', $result );
        $this->assertStringContainsString( 'invoice.php', $result );
    }

    public function test_locate_returns_empty_string_for_unknown_template(): void {
        Functions\when( 'locate_template' )->justReturn( '' );
        Functions\when( 'apply_filters' )->returnArg( 2 );
        Functions\when( 'trailingslashit' )->alias( fn( $s ) => rtrim( $s, '/\\' ) . '/' );
        Functions\when( 'file_exists' )->justReturn( false );

        $loader = new TemplateLoader( '/nonexistent' );
        $result = $loader->locate( 'invoice', 'invoice.php', 'Simple' );

        $this->assertSame( '', $result );
    }

    public function test_standard_uae_template_is_discovered(): void {
        $dir = dirname( __DIR__, 2 ) . '/templates/Standard UAE Tax Invoice';
        $this->assertDirectoryExists( $dir );
        $this->assertFileExists( $dir . '/invoice.php' );
        $this->assertFileExists( $dir . '/template-functions.php' );
        $this->assertFileExists( $dir . '/fonts/NotoNaskhArabic-Regular.ttf' );
    }
}
