<?php
namespace WOI\PDF\Tests\Unit\Status;

use PHPUnit\Framework\TestCase;
use WOI\PDF\Status\Diagnostics;

class DiagnosticsTest extends TestCase {

	public function test_to_text_renders_grouped_key_values(): void {
		$groups = array(
			'Versions'    => array( 'Plugin' => '1.4.9', 'Revision' => 'cc2c215 (master)' ),
			'Environment' => array( 'PHP' => '8.4.14', 'WordPress' => '6.7' ),
		);

		$text = Diagnostics::to_text( $groups );

		$this->assertStringContainsString( '== Versions ==', $text );
		$this->assertStringContainsString( 'Plugin: 1.4.9', $text );
		$this->assertStringContainsString( 'Revision: cc2c215 (master)', $text );
		$this->assertStringContainsString( '== Environment ==', $text );
		$this->assertStringContainsString( 'PHP: 8.4.14', $text );
		// Group order preserved: Versions before Environment.
		$this->assertLessThan( strpos( $text, '== Environment ==' ), strpos( $text, '== Versions ==' ) );
	}

	public function test_to_text_handles_empty_group(): void {
		$text = Diagnostics::to_text( array( 'Versions' => array() ) );
		$this->assertStringContainsString( '== Versions ==', $text );
	}
}
