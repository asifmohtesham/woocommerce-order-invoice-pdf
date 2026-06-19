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

	public function test_build_returns_tabs_and_documents_keys(): void {
		$nav = NavModel::build( $this->tabs(), $this->documents(), 'home', '' );
		$this->assertArrayHasKey( 'tabs', $nav );
		$this->assertArrayHasKey( 'documents', $nav );
	}

	public function test_main_tabs_in_source_order_all_kind_tab(): void {
		$nav = NavModel::build( $this->tabs(), $this->documents(), 'home', '' );
		$this->assertSame( array( 'home', 'general', 'documents', 'debug' ), array_column( $nav['tabs'], 'id' ) );
		$this->assertSame( array( 'tab' ), array_values( array_unique( array_column( $nav['tabs'], 'kind' ) ) ) );
	}

	public function test_documents_is_a_clickable_tab(): void {
		$nav      = NavModel::build( $this->tabs(), $this->documents(), 'documents', 'invoice' );
		$by_id    = array_combine( array_column( $nav['tabs'], 'id' ), $nav['tabs'] );
		$docs_tab = $by_id['documents'];
		$this->assertSame( 'tab', $docs_tab['kind'] );
		$this->assertSame( 'documents', $docs_tab['tab'] );
		$this->assertSame( '', $docs_tab['section'] );
		$this->assertTrue( $docs_tab['active'] );
	}

	public function test_documents_tab_inactive_on_other_tabs(): void {
		$nav   = NavModel::build( $this->tabs(), $this->documents(), 'home', '' );
		$by_id = array_combine( array_column( $nav['tabs'], 'id' ), $nav['tabs'] );
		$this->assertFalse( $by_id['documents']['active'] );
	}

	public function test_document_subitems_carry_enabled_and_active(): void {
		$nav   = NavModel::build( $this->tabs(), $this->documents(), 'documents', 'invoice' );
		$by_id = array_combine( array_column( $nav['documents'], 'id' ), $nav['documents'] );
		$this->assertTrue( $by_id['invoice']['enabled'] );
		$this->assertTrue( $by_id['invoice']['active'] );
		$this->assertFalse( $by_id['packing-slip']['enabled'] );
		$this->assertFalse( $by_id['packing-slip']['active'] );
	}

	public function test_documents_tab_itself_has_null_enabled(): void {
		$nav   = NavModel::build( $this->tabs(), $this->documents(), 'home', '' );
		$by_id = array_combine( array_column( $nav['tabs'], 'id' ), $nav['tabs'] );
		$this->assertNull( $by_id['documents']['enabled'] );
	}

	public function test_tab_items_expose_the_full_eight_key_shape(): void {
		$nav = NavModel::build( $this->tabs(), $this->documents(), 'documents', 'invoice' );
		$this->assertSame(
			array( 'kind', 'id', 'label', 'tab', 'section', 'enabled', 'active', 'href' ),
			array_keys( $nav['tabs'][0] )
		);
	}

	public function test_document_items_expose_the_seven_key_shape(): void {
		$nav = NavModel::build( $this->tabs(), $this->documents(), 'documents', 'invoice' );
		$this->assertSame(
			array( 'kind', 'id', 'label', 'tab', 'section', 'enabled', 'active' ),
			array_keys( $nav['documents'][0] )
		);
	}

	public function test_tab_href_passes_through_when_set_else_empty(): void {
		$tabs = array(
			'general' => array( 'title' => 'General', 'preview_states' => 3 ),
			'visual'  => array( 'title' => 'Visual Template', 'href' => 'https://example.test/editor' ),
		);
		$nav   = NavModel::build( $tabs, array(), 'general', '' );
		$by_id = array_combine( array_column( $nav['tabs'], 'id' ), $nav['tabs'] );
		$this->assertSame( 'https://example.test/editor', $by_id['visual']['href'] );
		$this->assertSame( '', $by_id['general']['href'] );
	}

	public function test_plain_tab_active(): void {
		$nav   = NavModel::build( $this->tabs(), $this->documents(), 'debug', '' );
		$by_id = array_combine( array_column( $nav['tabs'], 'id' ), $nav['tabs'] );
		$this->assertTrue( $by_id['debug']['active'] );
	}

	public function test_string_tab_title_supported(): void {
		$nav = NavModel::build( array( 'general' => 'General' ), array(), 'general', '' );
		$this->assertSame( 'General', $nav['tabs'][0]['label'] );
	}

	public function test_documents_key_absent_yields_empty_documents(): void {
		$nav = NavModel::build( array( 'general' => 'General' ), $this->documents(), 'general', '' );
		$this->assertCount( 1, $nav['tabs'] );
		$this->assertSame( array(), $nav['documents'] );
	}
}
