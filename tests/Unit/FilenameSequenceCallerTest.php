<?php
namespace WOI\PDF\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * woi_pdf_document_number_sequence() converts a DocumentNumber-like object into
 * the raw-counter string used for the {document_number_sequence} filename token:
 * the plain int as a string, or '' when there is no number.
 */
class FilenameSequenceCallerTest extends TestCase {

	public function test_returns_plain_counter_as_string(): void {
		$num = new class {
			public function get_plain(): ?int { return 123; }
		};
		$this->assertSame( '123', woi_pdf_document_number_sequence( $num ) );
	}

	public function test_returns_empty_for_null_object(): void {
		$this->assertSame( '', woi_pdf_document_number_sequence( null ) );
	}

	public function test_returns_empty_when_plain_is_null(): void {
		$num = new class {
			public function get_plain(): ?int { return null; }
		};
		$this->assertSame( '', woi_pdf_document_number_sequence( $num ) );
	}

	public function test_returns_empty_when_object_lacks_get_plain(): void {
		$this->assertSame( '', woi_pdf_document_number_sequence( new \stdClass() ) );
	}
}
