<?php
namespace WOI\PDF\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Rest;

class RestTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_rest_instantiates(): void {
        Functions\when( 'register_rest_field' )->justReturn( null );
        Functions\when( 'register_rest_route' )->justReturn( null );
        Functions\when( 'get_option' )->justReturn( array() );
        $rest = new Rest();
        $this->assertInstanceOf( Rest::class, $rest );
    }

    public function test_rest_namespace_is_wc_v3(): void {
        Functions\when( 'get_option' )->justReturn( array() );
        $rest = new Rest();
        $reflection = new \ReflectionProperty( Rest::class, 'namespace' );
        $reflection->setAccessible( true );
        $this->assertSame( 'wc/v3', $reflection->getValue( $rest ) );
    }

    public function test_rest_base_is_orders(): void {
        Functions\when( 'get_option' )->justReturn( array() );
        $rest = new Rest();
        $reflection = new \ReflectionProperty( Rest::class, 'rest_base' );
        $reflection->setAccessible( true );
        $this->assertSame( 'orders', $reflection->getValue( $rest ) );
    }
}
