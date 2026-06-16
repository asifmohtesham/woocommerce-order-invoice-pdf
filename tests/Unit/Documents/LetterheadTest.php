<?php
namespace WOI\PDF\Tests\Unit\Documents;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Documents\OrderDocument;

/**
 * Letterhead mode is active only when the toggle is on AND an image is set.
 * These predicates read $this->settings only, so no WP stubs are needed.
 */
class LetterheadTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function make_document( array $settings ): OrderDocument {
		$doc = $this->getMockForAbstractClass( OrderDocument::class, array(), '', false );
		$doc->settings = $settings;
		return $doc;
	}

	public function test_is_letterhead_mode_reflects_setting(): void {
		$this->assertTrue( $this->make_document( array( 'letterhead_mode' => 1 ) )->is_letterhead_mode() );
		$this->assertFalse( $this->make_document( array() )->is_letterhead_mode() );
	}

	public function test_has_letterhead_requires_mode_and_image(): void {
		$this->assertTrue(
			$this->make_document( array( 'letterhead_mode' => 1, 'letterhead_logo' => 123 ) )->has_letterhead()
		);
		$this->assertFalse(
			$this->make_document( array( 'letterhead_mode' => 1 ) )->has_letterhead(),
			'mode on but no image'
		);
		$this->assertFalse(
			$this->make_document( array( 'letterhead_logo' => 123 ) )->has_letterhead(),
			'image set but mode off'
		);
	}
}
