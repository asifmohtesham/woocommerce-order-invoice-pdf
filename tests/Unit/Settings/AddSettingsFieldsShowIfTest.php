<?php
namespace WOI\PDF\Tests\Unit\Settings;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Settings;
use WOI\PDF\Settings\SettingsCallbacks;

/**
 * Integration test for the seam between field definitions and WP core:
 * add_settings_fields() must hand the show_if marker classes to add_settings_field().
 */
class AddSettingsFieldsShowIfTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_key' )->alias( function ( $key ) {
			return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) );
		} );
		Functions\when( 'add_settings_section' )->justReturn( null );
		Functions\when( 'register_setting' )->justReturn( null );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_show_if_class_reaches_add_settings_field_args(): void {
		$captured = array();

		Functions\when( 'add_settings_field' )->alias(
			function ( $id, $title, $callback, $page, $section, $args ) use ( &$captured ) {
				$captured[ $id ] = $args;
			}
		);

		$settings  = ( new \ReflectionClass( Settings::class ) )->newInstanceWithoutConstructor();
		$callbacks = ( new \ReflectionClass( SettingsCallbacks::class ) )->newInstanceWithoutConstructor();

		$property = new \ReflectionProperty( Settings::class, 'callbacks' );
		$property->setValue( $settings, $callbacks );

		// Mirror of the checkout-field definitions in SettingsGeneral::init_settings()
		$settings_fields = array(
			array(
				'type'     => 'section',
				'id'       => 'general_checkout_field',
				'title'    => 'Checkout field',
				'callback' => 'section',
			),
			array(
				'type'     => 'setting',
				'id'       => 'checkout_field_enable',
				'title'    => 'Enable',
				'callback' => 'checkbox',
				'section'  => 'general_checkout_field',
				'args'     => array( 'option_name' => 'woi_pdf_settings_general', 'id' => 'checkout_field_enable' ),
			),
			array(
				'type'     => 'setting',
				'id'       => 'checkout_field_label',
				'title'    => 'Label',
				'callback' => 'text_element',
				'section'  => 'general_checkout_field',
				'show_if'  => array( 'field' => 'checkout_field_enable', 'value' => 1 ),
				'args'     => array( 'option_name' => 'woi_pdf_settings_general', 'id' => 'checkout_field_label' ),
			),
		);

		$settings->add_settings_fields( $settings_fields, 'woi_pdf_settings_general', 'woi_pdf_settings_general', 'woi_pdf_settings_general' );

		$this->assertArrayHasKey( 'checkout_field_label', $captured );
		$this->assertSame(
			'woi-show-if woi-show-if--checkout_field_enable--1',
			$captured['checkout_field_label']['class'] ?? null,
			'show_if marker classes must reach the add_settings_field args'
		);
		$this->assertArrayNotHasKey( 'class', $captured['checkout_field_enable'], 'controller field must not get marker classes' );
	}
}
