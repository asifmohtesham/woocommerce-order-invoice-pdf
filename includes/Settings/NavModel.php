<?php
namespace WOI\PDF\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( '\\WOI\\PDF\\Settings\\NavModel' ) ) :

/**
 * Builds the sidebar nav model for the settings shell.
 * Pure data in, pure data out — no WP state, so it stays unit-testable.
 */
class NavModel {

	/**
	 * @param array  $settings_tabs   Filtered tabs array from Settings::settings_page()
	 * @param array  $documents       List of array( 'type' => string, 'title' => string, 'enabled' => bool )
	 * @param string $current_tab
	 * @param string $current_section Document type when on the documents tab
	 *
	 * @return array List of items: array( 'kind' => 'tab'|'heading'|'document', 'id', 'label', 'tab', 'section', 'enabled', 'active' )
	 */
	public static function build( array $settings_tabs, array $documents, string $current_tab, string $current_section ): array {
		$items = array();

		foreach ( $settings_tabs as $tab_key => $tab ) {
			$label = is_array( $tab ) ? (string) ( $tab['title'] ?? $tab_key ) : (string) $tab;

			if ( 'documents' === $tab_key ) {
				$items[] = array(
					'kind'    => 'heading',
					'id'      => 'documents',
					'label'   => $label,
					'tab'     => '',
					'section' => '',
					'enabled' => null,
					'active'  => false,
				);

				foreach ( $documents as $document ) {
					$items[] = array(
						'kind'    => 'document',
						'id'      => (string) $document['type'],
						'label'   => (string) $document['title'],
						'tab'     => 'documents',
						'section' => (string) $document['type'],
						'enabled' => ! empty( $document['enabled'] ),
						'active'  => ( 'documents' === $current_tab && $current_section === $document['type'] ),
					);
				}

				continue;
			}

			$items[] = array(
				'kind'    => 'tab',
				'id'      => (string) $tab_key,
				'label'   => $label,
				'tab'     => (string) $tab_key,
				'section' => '',
				'enabled' => null,
				'active'  => ( $current_tab === $tab_key ),
			);
		}

		return $items;
	}
}

endif; // class_exists
