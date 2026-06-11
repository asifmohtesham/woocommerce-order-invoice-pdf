<?php
namespace WOI\PDF\Tests\Unit\Compatibility;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Compatibility\OrderUtil;

class OrderUtilTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_instance_returns_singleton(): void {
        $this->assertSame( OrderUtil::instance(), OrderUtil::instance() );
    }

    public function test_custom_orders_table_usage_is_false_without_woocommerce(): void {
        // \Automattic\WooCommerce\Utilities\OrderUtil is not loaded in unit tests
        $this->assertFalse( OrderUtil::instance()->custom_orders_table_usage_is_enabled() );
    }

    public function test_is_wc_admin_page_is_false_without_woocommerce_admin(): void {
        // \Automattic\WooCommerce\Admin\PageController is not loaded in unit tests
        $this->assertFalse( OrderUtil::instance()->is_wc_admin_page() );
    }

    public function test_get_order_type_falls_back_to_post_type(): void {
        Functions\when( 'get_post_type' )->justReturn( 'shop_order' );

        $this->assertSame( 'shop_order', OrderUtil::instance()->get_order_type( 123 ) );
    }
}
