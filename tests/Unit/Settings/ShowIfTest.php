<?php
namespace WOI\PDF\Tests\Unit\Settings;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Settings;

class ShowIfTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_key' )->alias( function ( $key ) {
			return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) );
		} );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_builds_marker_classes_from_field_and_value(): void {
		$class = Settings::show_if_class( array( 'field' => 'checkout_field_enable', 'value' => 1 ) );
		$this->assertSame( 'woi-show-if woi-show-if--checkout_field_enable--1', $class );
	}

	public function test_returns_empty_string_when_field_missing(): void {
		$this->assertSame( '', Settings::show_if_class( array( 'value' => 1 ) ) );
	}

	public function test_value_defaults_to_1(): void {
		$class = Settings::show_if_class( array( 'field' => 'display_due_date' ) );
		$this->assertSame( 'woi-show-if woi-show-if--display_due_date--1', $class );
	}
}
