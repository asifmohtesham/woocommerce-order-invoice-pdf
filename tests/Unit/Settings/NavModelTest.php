<?php
namespace WOI\PDF\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use WOI\PDF\Settings\NavModel;

class NavModelTest extends TestCase {

	private function tabs(): array {
		return array(
			'home'      => array( 'title' => 'Home', 'preview_states' => 1 ),
			'general'   => array( 'title' => 'General', 'preview_states' => 3 ),
			'documents' => array( 'title' => 'Documents', 'preview_states' => 3 ),
			'debug'     => array( 'title' => 'Advanced', 'preview_states' => 1 ),
		);
	}

	private function documents(): array {
		return array(
			array( 'type' => 'invoice', 'title' => 'Invoice', 'enabled' => true ),
			array( 'type' => 'packing-slip', 'title' => 'Packing Slip', 'enabled' => false ),
		);
	}

	public function test_documents_tab_expands_to_heading_plus_items(): void {
		$items = NavModel::build( $this->tabs(), $this->documents(), 'home', '' );
		$kinds = array_column( $items, 'kind' );
		$this->assertSame( array( 'tab', 'tab', 'heading', 'document', 'document', 'tab' ), $kinds );
	}

	public function test_active_document_requires_tab_and_section_match(): void {
		$items   = NavModel::build( $this->tabs(), $this->documents(), 'documents', 'invoice' );
		$by_id   = array_combine( array_column( $items, 'id' ), $items );
		$invoice = $by_id['invoice'];
		$packing = $by_id['packing-slip'];
		$this->assertSame( 'invoice', $invoice['id'] );
		$this->assertTrue( $invoice['active'] );
		$this->assertFalse( $packing['active'] );
	}

	public function test_active_plain_tab(): void {
		$items = NavModel::build( $this->tabs(), $this->documents(), 'debug', '' );
		$debug = end( $items );
		$this->assertTrue( $debug['active'] );
	}

	public function test_document_enabled_flag_passes_through(): void {
		$items = NavModel::build( $this->tabs(), $this->documents(), 'home', '' );
		$by_id = array_combine( array_column( $items, 'id' ), $items );
		$this->assertTrue( $by_id['invoice']['enabled'] );
		$this->assertFalse( $by_id['packing-slip']['enabled'] );
	}

	public function test_string_tab_title_supported(): void {
		$tabs  = array( 'general' => 'General' );
		$items = NavModel::build( $tabs, array(), 'general', '' );
		$this->assertSame( 'General', $items[0]['label'] );
	}

	public function test_documents_key_absent_yields_only_tab_items(): void {
		$items = NavModel::build( array( 'general' => 'General' ), $this->documents(), 'general', '' );
		$this->assertCount( 1, $items );
		$this->assertSame( 'tab', $items[0]['kind'] );
	}
}
