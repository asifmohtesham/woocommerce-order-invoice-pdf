<?php
namespace WOI\PDF\Tests\Unit\Documents;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Documents\DocumentNumber;

class DocumentNumberTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'apply_filters' )->returnArg( 2 );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_number_formats_with_padding(): void {
        $number = new DocumentNumber( 5, [ 'padding' => 4, 'prefix' => '', 'suffix' => '' ] );
        $this->assertSame( '0005', $number->formatted_number );
    }

    public function test_number_formats_with_prefix_and_suffix(): void {
        $number = new DocumentNumber( 7, [ 'padding' => 1, 'prefix' => 'INV-', 'suffix' => '-2025' ] );
        $this->assertSame( 'INV-7-2025', $number->formatted_number );
    }

    public function test_number_is_null_when_empty(): void {
        $number = new DocumentNumber( null, [] );
        $this->assertNull( $number->number );
    }
}
