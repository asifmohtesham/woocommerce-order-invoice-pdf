<?php
namespace WOI\PDF\Tests\Unit\Documents;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use WOI\PDF\Documents\DocumentInterface;
use WOI\PDF\Documents\NumberedDocumentInterface;
use WOI\PDF\Documents\EmailAttachableInterface;
use WOI\PDF\Documents\BulkDocumentInterface;

class DocumentInterfaceContractTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_document_interface_declares_required_methods(): void {
        $methods = get_class_methods( DocumentInterface::class );
        $this->assertContains( 'get_type', $methods );
        $this->assertContains( 'get_title', $methods );
        $this->assertContains( 'is_enabled', $methods );
        $this->assertContains( 'exists', $methods );
        $this->assertContains( 'init', $methods );
        $this->assertContains( 'get_html', $methods );
        $this->assertContains( 'get_settings_fields', $methods );
        $this->assertContains( 'get_settings_option_name', $methods );
    }

    public function test_numbered_document_interface_extends_document_interface(): void {
        $parents = class_implements( NumberedDocumentInterface::class );
        $this->assertArrayHasKey( DocumentInterface::class, $parents );
    }

    public function test_numbered_document_interface_declares_required_methods(): void {
        $methods = get_class_methods( NumberedDocumentInterface::class );
        $this->assertContains( 'get_number', $methods );
        $this->assertContains( 'set_number', $methods );
        $this->assertContains( 'get_date', $methods );
        $this->assertContains( 'has_number', $methods );
    }

    public function test_email_attachable_interface_declares_required_methods(): void {
        $methods = get_class_methods( EmailAttachableInterface::class );
        $this->assertContains( 'get_attach_to_email_ids', $methods );
    }

    public function test_bulk_document_interface_extends_document_interface(): void {
        $parents = class_implements( BulkDocumentInterface::class );
        $this->assertArrayHasKey( DocumentInterface::class, $parents );
    }

    public function test_bulk_document_interface_declares_required_methods(): void {
        $methods = get_class_methods( BulkDocumentInterface::class );
        $this->assertContains( 'set_order_ids', $methods );
    }
}
