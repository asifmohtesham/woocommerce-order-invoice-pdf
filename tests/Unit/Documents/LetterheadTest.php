<?php
namespace WOI\PDF\Tests\Unit\Documents;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Documents\OrderDocument;

/**
 * Letterhead mode is active only when the toggle is on AND a letterhead
 * attachment id resolves (> 0). is_letterhead_mode() reads $this->settings
 * directly; has_letterhead() delegates to get_letterhead_id(), which we stub
 * here so the predicate is tested without WP.
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

	/**
	 * @param array    $settings       Document settings.
	 * @param int|null $letterhead_id  When provided, get_letterhead_id() is stubbed to return it.
	 */
	private function make_document( array $settings, ?int $letterhead_id = null ): OrderDocument {
		$mocked_methods = ( null === $letterhead_id ) ? array() : array( 'get_letterhead_id' );
		$doc = $this->getMockForAbstractClass( OrderDocument::class, array(), '', false, true, true, $mocked_methods );
		$doc->settings = $settings;
		if ( null !== $letterhead_id ) {
			$doc->method( 'get_letterhead_id' )->willReturn( $letterhead_id );
		}
		return $doc;
	}

	public function test_is_letterhead_mode_reflects_setting(): void {
		$this->assertTrue( $this->make_document( array( 'letterhead_mode' => 1 ) )->is_letterhead_mode() );
		$this->assertFalse( $this->make_document( array() )->is_letterhead_mode() );
		$this->assertFalse( $this->make_document( array( 'letterhead_mode' => 0 ) )->is_letterhead_mode() );
	}

	public function test_has_letterhead_requires_mode_and_image(): void {
		$this->assertTrue(
			$this->make_document( array( 'letterhead_mode' => 1 ), 123 )->has_letterhead()
		);
		$this->assertFalse(
			$this->make_document( array( 'letterhead_mode' => 1 ), 0 )->has_letterhead(),
			'mode on but no image'
		);
		$this->assertFalse(
			$this->make_document( array(), 123 )->has_letterhead(),
			'image set but mode off'
		);
	}
}
