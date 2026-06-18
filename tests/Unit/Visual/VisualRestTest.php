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
        $this->assertNotNull( $saved, 'update_option was not called — save() did not run' );
        $this->assertStringContainsString( '{{shop_name}}', $saved );
    }

    public function test_visual_route_hooked_even_when_rest_api_disabled(): void {
        Functions\when( 'get_option' )->justReturn( array() ); // debug off: no enable_rest_api

        $hooked = false;
        Functions\when( 'add_action' )->alias( function ( $hook, $cb ) use ( &$hooked ) {
            if ( 'rest_api_init' === $hook && is_array( $cb ) && isset( $cb[1] ) && 'register_visual_template_route' === $cb[1] ) {
                $hooked = true;
            }
        } );

        new Rest();

        $this->assertTrue( $hooked, 'register_visual_template_route was not hooked to rest_api_init' );
    }
}
