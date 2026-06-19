<?php
namespace WOI\PDF\Tests\Unit\Visual;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Visual\VisualEditorPage;

class VisualEditorNoticesTest extends TestCase {

	protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
	protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

	private function page(): VisualEditorPage {
		// Constructor only registers hooks (add_action/add_filter) — stub them.
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		return new VisualEditorPage();
	}

	public function test_is_visual_editor_screen_true_on_editor(): void {
		Functions\when( 'get_current_screen' )->justReturn( (object) array( 'id' => 'woocommerce_page_woi-pdf-visual' ) );
		$this->assertTrue( $this->page()->is_visual_editor_screen() );
	}

	public function test_is_visual_editor_screen_false_elsewhere(): void {
		Functions\when( 'get_current_screen' )->justReturn( (object) array( 'id' => 'edit-post' ) );
		$this->assertFalse( $this->page()->is_visual_editor_screen() );
	}

	public function test_is_visual_editor_screen_false_when_no_screen(): void {
		Functions\when( 'get_current_screen' )->justReturn( null );
		$this->assertFalse( $this->page()->is_visual_editor_screen() );
	}

	public function test_suppress_removes_notice_actions_on_screen(): void {
		Functions\when( 'get_current_screen' )->justReturn( (object) array( 'id' => 'woocommerce_page_woi-pdf-visual' ) );
		$removed = array();
		Functions\when( 'remove_all_actions' )->alias( function ( $hook ) use ( &$removed ) { $removed[] = $hook; return true; } );

		$this->page()->suppress_admin_notices();

		$this->assertSame( array( 'admin_notices', 'all_admin_notices', 'user_admin_notices' ), $removed );
	}

	public function test_suppress_noop_off_screen(): void {
		Functions\when( 'get_current_screen' )->justReturn( (object) array( 'id' => 'edit-post' ) );
		$called = false;
		Functions\when( 'remove_all_actions' )->alias( function () use ( &$called ) { $called = true; return true; } );

		$this->page()->suppress_admin_notices();

		$this->assertFalse( $called );
	}
}
