<?php
namespace WOI\PDF\Tests\Unit\Settings;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Settings\SettingsCallbacks;

/**
 * Guards the media-upload re-render seam: when the field HTML is regenerated via
 * AJAX right after the user picks an image (before saving), the callback must
 * honour the freshly selected attachment passed as $args['current'] instead of
 * falling back to the (still-empty) saved option. Otherwise the selection is
 * wiped on re-render and never reaches the live preview.
 */
class MediaUploadCurrentTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'get_option' )->justReturn( array() ); // nothing saved yet
		Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		Functions\when( 'wp_get_attachment_image_src' )->justReturn( array( 'http://example.test/logo.png', 100, 50 ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_current_arg_overrides_unsaved_option(): void {
		$callbacks = ( new \ReflectionClass( SettingsCallbacks::class ) )->newInstanceWithoutConstructor();

		ob_start();
		$callbacks->media_upload( array(
			'option_name' => 'woi_pdf_settings_general',
			'id'          => 'header_logo',
			'current'     => 123,
		) );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'value="123"', $html, 'Re-rendered hidden input should carry the freshly selected attachment id.' );
	}
}
