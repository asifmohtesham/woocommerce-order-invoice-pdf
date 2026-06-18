<?php
namespace WOI\PDF\Tests\Unit\Visual;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Rest;

class VisualRestTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_save_handler_persists_and_returns_saved(): void {
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'wp_kses' )->returnArg( 1 );
        $saved = null;
        Functions\when( 'update_option' )->alias( function ( $n, $v ) use ( &$saved ) { $saved = $v; return true; } );

        $request = new class {
            public function get_param( $k ) {
                return array( 'doc_type' => 'invoice', 'html' => '<p>{{shop_name}}</p>' )[ $k ] ?? null;
            }
        };

        $rest   = new Rest();
        $result = $rest->handle_visual_template_save( $request );

        $this->assertSame( array( 'saved' => true ), $result );
        $this->assertStringContainsString( '{{shop_name}}', $saved );
    }
}
