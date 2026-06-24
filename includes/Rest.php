<?php

namespace WOI\PDF;

use WOI\PDF\Documents\Order_Document_Methods;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\WOI\\PDF\\Rest' ) ) :

	class Rest {

		protected string $namespace = 'wc/v3';
		protected string $rest_base = 'orders';

		public function __construct() {
			add_action( 'rest_api_init', array( $this, 'register_visual_template_route' ) );

			$debug_settings = get_option( 'woi_pdf_settings_debug', array() );

			if ( ! isset( $debug_settings['enable_rest_api'] ) ) {
				return;
			}

			if ( ! $this->is_rest_api_supported() ) {
				unset( $debug_settings['enable_rest_api'] );
				update_option( 'woi_pdf_settings_debug', $debug_settings );
				return;
			}

			add_action( 'rest_api_init', array( $this, 'rest_api_init' ) );
		}

		/**
		 * Checks whether the REST API is available in this WordPress installation.
		 *
		 * @return bool
		 */
		private function is_rest_api_supported(): bool {
			return function_exists( 'register_rest_route' ) && version_compare( get_bloginfo( 'version' ), '5.4', '>=' );
		}

		/**
		 * Registers the visual-template REST route unconditionally (not gated by debug settings).
		 *
		 * @return void
		 */
		public function register_visual_template_route(): void {
			register_rest_route( 'woi-pdf/v1', '/visual-template', array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_visual_template_save' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_woocommerce' );
				},
				'args'                => array(
					'doc_type' => array( 'type' => 'string', 'required' => true ),
					'html'     => array( 'type' => 'string', 'required' => true ),
				),
			) );

		register_rest_route( 'woi-pdf/v1', '/visual-preview-data', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_visual_preview_data' ),
			'permission_callback' => function () {
				return current_user_can( 'manage_woocommerce' );
			},
			'args'                => array(
				'order_id' => array( 'type' => 'integer', 'required' => false ),
				'doc_type' => array( 'type' => 'string', 'required' => false ),
			),
		) );

		register_rest_route( 'woi-pdf/v1', '/visual-blocks', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_visual_blocks_save' ),
			'permission_callback' => function () { return current_user_can( 'manage_woocommerce' ); },
			'args'                => array(
				'doc_type' => array( 'type' => 'string', 'required' => true ),
				'markup'   => array( 'type' => 'string', 'required' => true ),
			),
		) );

		register_rest_route( 'woi-pdf/v1', '/visual-active-source', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_visual_active_source' ),
			'permission_callback' => function () { return current_user_can( 'manage_woocommerce' ); },
			'args'                => array(
				'source' => array( 'type' => 'string', 'required' => true ),
			),
		) );

		register_rest_route( 'woi-pdf/v1', '/visual-doc-options', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_visual_doc_options' ),
			'permission_callback' => function () { return current_user_can( 'manage_woocommerce' ); },
			'args'                => array(
				'options' => array( 'type' => 'object', 'required' => true ),
			),
		) );

		register_rest_route( 'woi-pdf/v1', '/letterhead', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_letterhead_save' ),
			'permission_callback' => function () { return current_user_can( 'manage_woocommerce' ); },
			'args'                => array(
				'items' => array( 'type' => 'object', 'required' => true ),
			),
		) );

		register_rest_route( 'woi-pdf/v1', '/contact-items', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_contact_items_save' ),
			'permission_callback' => function () { return current_user_can( 'manage_woocommerce' ); },
			'args'                => array(
				'items' => array( 'type' => 'array', 'required' => true ),
			),
		) );

		register_rest_route( 'woi-pdf/v1', '/visual-columns', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_get_columns' ),
				'permission_callback' => function () { return current_user_can( 'manage_woocommerce' ); },
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_save_columns' ),
				'permission_callback' => function () { return current_user_can( 'manage_woocommerce' ); },
				'args'                => array(
					'columns' => array( 'type' => 'array', 'required' => true ),
				),
			),
		) );

		register_rest_route( 'woi-pdf/v1', '/editor-config', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_get_editor_config' ),
				'permission_callback' => function () { return current_user_can( 'manage_woocommerce' ); },
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_save_editor_config' ),
				'permission_callback' => function () { return current_user_can( 'manage_woocommerce' ); },
			),
		) );

		register_rest_route( 'woi-pdf/v1', '/document-naming', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_get_document_naming' ),
				'permission_callback' => function () { return current_user_can( 'manage_woocommerce' ); },
				'args'                => array(
					'type' => array( 'type' => 'string', 'required' => true ),
				),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_save_document_naming' ),
				'permission_callback' => function () { return current_user_can( 'manage_woocommerce' ); },
				'args'                => array(
					'type' => array( 'type' => 'string', 'required' => true ),
				),
			),
		) );

		register_rest_route( 'woi-pdf/v1', '/naming-preview', array(
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_naming_preview' ),
				'permission_callback' => function () { return current_user_can( 'manage_woocommerce' ); },
				'args'                => array(
					'type' => array( 'type' => 'string', 'required' => true ),
				),
			),
		) );
	}

	/**
	 * Read the line-items column config (order, titles, widths, alignment) plus
	 * the available column types so the block editor can present a column editor.
	 *
	 * @param object $request
	 * @return array|\WP_Error
	 */
	public function handle_get_columns( $request ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new \WP_Error( 'forbidden', 'Insufficient permissions', array( 'status' => 403 ) );
		}
		return array(
			'columns' => $this->read_invoice_columns(),
			'types'   => $this->available_column_types(),
		);
	}

	/**
	 * Save the line-items column config. Sanitises each column and preserves the
	 * type-specific options the classic editor manages (price_type, tax, …).
	 *
	 * @param object $request
	 * @return array|\WP_Error
	 */
	public function handle_save_columns( $request ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new \WP_Error( 'forbidden', 'Insufficient permissions', array( 'status' => 403 ) );
		}
		// Read the RAW JSON body: a declared 'array' arg schema (without an items
		// schema) makes WP REST strip the per-column object keys it doesn't know,
		// dropping price_type / field_name / etc. get_json_params keeps them.
		$json     = $request->get_json_params();
		$incoming = ( is_array( $json ) && isset( $json['columns'] ) )
			? (array) $json['columns']
			: (array) $request->get_param( 'columns' );
		$clean    = array();
		$i        = 1;
		// UI-only / explicitly-handled keys; everything ELSE on the column (e.g.
		// price_type, tax, discount, show_meta, attribute_name) is preserved as-is
		// so editing a column never strips its data wiring.
		$handled = array( 'align', 'field', 'field_name', 'width', 'style', 'label', 'type' );
		foreach ( $incoming as $col ) {
			$col = (array) $col;
			if ( empty( $col['type'] ) ) { continue; }
			$c = array( 'type' => sanitize_key( $col['type'] ) );

			// Preserve all other (type-specific) scalar options untouched.
			foreach ( $col as $k => $v ) {
				if ( in_array( $k, $handled, true ) || ! is_scalar( $v ) ) { continue; }
				$c[ sanitize_key( $k ) ] = sanitize_text_field( (string) $v );
			}

			if ( isset( $col['label'] ) ) { $c['label'] = sanitize_text_field( (string) $col['label'] ); }
			if ( isset( $col['width'] ) && function_exists( 'woi_pdf_templates_normalize_column_width' ) ) {
				$w = woi_pdf_templates_normalize_column_width( $col['width'] );
				if ( '' !== $w ) { $c['width'] = $w; }
			}
			// Alignment is stored in the freeform `style` as text-align.
			if ( ! empty( $col['align'] ) && in_array( $col['align'], array( 'left', 'center', 'right' ), true ) ) {
				$c['style']        = 'text-align: ' . $col['align'] . ';';
				$c['style_target'] = 'both';
			} elseif ( isset( $col['style'] ) ) {
				$c['style'] = sanitize_text_field( (string) $col['style'] );
			}
			// Field key — the data source (e.g. a product meta key / property such as
			// global_unique_id). Stored as field_name, which the product_custom
			// renderer reads via get_product_custom_field().
			$field = isset( $col['field'] ) ? sanitize_text_field( (string) $col['field'] ) : '';
			if ( '' !== $field ) { $c['field_name'] = $field; }

			$clean[ $i++ ] = $c;
		}

		$option = get_option( 'woi_pdf_editor_settings', array() );
		if ( ! is_array( $option ) ) { $option = array(); }
		$option['fields_invoice_columns'] = $clean;
		update_option( 'woi_pdf_editor_settings', $option );

		$response = array( 'saved' => true, 'columns' => $this->read_invoice_columns() );

		// Return freshly-rendered tokens for the open order so the editor canvas
		// live-updates in a single round-trip (no separate token fetch).
		$order_id = ( is_array( $json ) && ! empty( $json['order_id'] ) ) ? absint( $json['order_id'] ) : 0;
		if ( $order_id ) {
			$response['tokens'] = $this->render_line_items_token( 'invoice', $order_id );
		}
		return $response;
	}

	/** GET /editor-config — full schema + saved values for every Customiser section. */
	public function handle_get_editor_config( $request ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new \WP_Error( 'forbidden', 'Insufficient permissions', array( 'status' => 403 ) );
		}
		$es     = \WOI\PDF\Editor\EditorSettings::instance();
		$sort   = $es->get_sorting_options();
		$option = get_option( 'woi_pdf_editor_settings', array() );
		$secondary_defaults = $this->editor_secondary_defaults();
		$config = array(
			'columns' => array( 'schema' => $this->editor_schema_columns(), 'values' => $this->read_invoice_columns(), 'secondary_defaults' => $secondary_defaults ),
			'totals'  => array( 'schema' => $this->editor_schema_totals(),  'values' => $this->read_invoice_totals(), 'secondary_defaults' => $secondary_defaults ),
			'custom'  => array(
				'positions' => $this->editor_custom_positions(),
				'types'     => $this->editor_custom_types(),
				'values'    => array_values( (array) ( $option['fields_invoice_custom'] ?? array() ) ),
			),
			'sort'  => array( 'options' => $sort['options'], 'value' => (string) ( $option['sort_items']['invoice'] ?? 'default' ) ),
			'custom_styles' => (string) ( $option['custom_styles'] ?? '' ),
		);
		if ( class_exists( '\\WC_Product_Bundle' ) || function_exists( 'wc_pb_get_bundled_order_items' ) ) {
			$config['bundle'] = array(
				'options' => $es->get_product_bundle_options(),
				'value'   => (string) ( $option['product_bundle_display']['invoice'] ?? 'all' ),
			);
		}
		return $config;
	}

	/** POST /editor-config — sanitize + save only the sections present in the body. */
	public function handle_save_editor_config( $request ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new \WP_Error( 'forbidden', 'Insufficient permissions', array( 'status' => 403 ) );
		}
		$json = $request->get_json_params();
		if ( ! is_array( $json ) ) {
			$json = array();
		}
		$option = get_option( 'woi_pdf_editor_settings', array() );
		if ( ! is_array( $option ) ) {
			$option = array();
		}

		if ( array_key_exists( 'columns', $json ) ) {
			$option['fields_invoice_columns'] = \WOI\PDF\Editor\EditorConfigSanitizer::sanitize_blocks(
				(array) $json['columns'], $this->editor_schema_columns()
			);
		}
		if ( array_key_exists( 'totals', $json ) ) {
			$option['fields_invoice_totals'] = \WOI\PDF\Editor\EditorConfigSanitizer::sanitize_blocks(
				(array) $json['totals'], $this->editor_schema_totals()
			);
		}
		if ( array_key_exists( 'custom', $json ) ) {
			$option['fields_invoice_custom'] = $this->sanitize_custom_blocks( (array) $json['custom'] );
		}
		if ( array_key_exists( 'sort', $json ) ) {
			$sort = sanitize_key( (string) $json['sort'] );
			$option['sort_items']            = (array) ( $option['sort_items'] ?? array() );
			$option['sort_items']['invoice'] = $sort ?: 'default';
		}
		if ( array_key_exists( 'bundle', $json ) ) {
			$bundle = sanitize_key( (string) $json['bundle'] );
			$option['product_bundle_display']            = (array) ( $option['product_bundle_display'] ?? array() );
			$option['product_bundle_display']['invoice'] = $bundle ?: 'all';
		}
		if ( array_key_exists( 'custom_styles', $json ) ) {
			// custom_styles is a full stylesheet (selectors + braces), stored like
			// the classic Customiser's textarea — NOT a single inline declaration.
			// Use sanitize_textarea_field (preserves CSS, strips tag injection); do
			// NOT route it through woi_pdf_templates_sanitize_column_style(), which is
			// a per-declaration whitelist that would gut a real stylesheet.
			$css = (string) $json['custom_styles'];
			$option['custom_styles'] = function_exists( 'sanitize_textarea_field' )
				? sanitize_textarea_field( $css )
				: $css;
		}

		$option['settings_saved'] = '1';
		$this->persist_editor_option( $option ); // triggers position-renumber hook

		$response  = array(
			'saved'         => true,
			'columns'       => $this->read_invoice_columns(),
			'totals'        => $this->read_invoice_totals(),
			'custom_styles' => (string) ( $option['custom_styles'] ?? '' ),
		);
		$order_id = absint( $request->get_param( 'order_id' ) );
		if ( $order_id ) {
			$response['tokens'] = array_merge(
				$this->render_line_items_token( 'invoice', $order_id ),
				$this->render_totals_token( 'invoice', $order_id )
			);
		}
		return $response;
	}

	/** Sanitize custom blocks, preserving the advanced `requirements` subtree. */
	protected function sanitize_custom_blocks( array $incoming ): array {
		$types     = array_map( 'strval', array_keys( $this->editor_custom_types() ) );
		$positions = array_map( 'strval', array_keys( $this->editor_custom_positions() ) );
		$clean = array();
		$i     = 1;
		foreach ( $incoming as $row ) {
			$row = (array) $row;
			$c   = array();
			if ( isset( $row['type'] ) && in_array( (string) $row['type'], $types, true ) ) {
				$c['type'] = (string) $row['type'];
			}
			if ( isset( $row['position'] ) && in_array( (string) $row['position'], $positions, true ) ) {
				$c['position'] = (string) $row['position'];
			}
			if ( isset( $row['label'] ) )    { $c['label']    = sanitize_text_field( (string) $row['label'] ); }
			if ( isset( $row['meta_key'] ) ) { $c['meta_key'] = sanitize_text_field( (string) $row['meta_key'] ); }
			if ( isset( $row['text'] ) )     { $c['text']     = sanitize_textarea_field( (string) $row['text'] ); }
			// Preserve the classic editor's advanced "requirements" subtree untouched.
			if ( isset( $row['requirements'] ) && is_array( $row['requirements'] ) ) {
				$c['requirements'] = map_deep( $row['requirements'], 'sanitize_text_field' );
			}
			if ( ! empty( $c ) ) {
				$clean[ $i++ ] = $c;
			}
		}
		return $clean;
	}

	protected function editor_schema_columns(): array {
		return \WOI\PDF\Editor\EditorSettings::instance()->get_columns_field_options();
	}

	protected function editor_schema_totals(): array {
		return \WOI\PDF\Editor\EditorSettings::instance()->get_totals_field_options();
	}

	protected function editor_custom_positions(): array {
		return \WOI\PDF\Editor\EditorSettings::instance()->get_custom_block_positions();
	}

	protected function editor_custom_types(): array {
		return \WOI\PDF\Editor\EditorSettings::instance()->get_custom_block_types();
	}

	/** Invoice totals config as a plain 0-indexed list (full rows preserved). */
	protected function read_invoice_totals(): array {
		$totals = array();
		if ( class_exists( '\\WOI\\PDF\\Editor\\EditorSettings' ) ) {
			$totals = \WOI\PDF\Editor\EditorSettings::instance()->get_settings( 'invoice', 'totals' );
		}
		$out = array();
		foreach ( (array) $totals as $row ) {
			$row = (array) $row;
			if ( ! empty( $row['type'] ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * Render ONLY the {{totals}} token for an order (live canvas partial). Mirrors
	 * render_line_items_token() exactly: same filter bracketing, same get_document()
	 * seam, same initiate_date() guard. Uses TemplateTokens::map() because render_totals
	 * is private (no public totals() parallel to line_items()).
	 *
	 * @param string $doc_type
	 * @param int    $order_id
	 * @return array Partial token map ('{{totals}}' => html), or array().
	 */
	protected function render_totals_token( string $doc_type, int $order_id ): array {
		if ( ! $order_id || ! function_exists( 'wc_get_order' ) ) { return array(); }
		$order = wc_get_order( $order_id );
		if ( empty( $order ) ) { return array(); }

		add_filter( 'woi_pdf_document_use_historical_settings', '__return_false', 99 );
		add_filter( 'woi_pdf_document_is_enabled', '__return_true', 99 );
		$document = $this->get_document( $doc_type, $order );
		remove_filter( 'woi_pdf_document_is_enabled', '__return_true', 99 );
		remove_filter( 'woi_pdf_document_use_historical_settings', '__return_false', 99 );
		if ( ! $document ) { return array(); }
		if ( is_callable( array( $document, 'initiate_date' ) ) ) { $document->initiate_date(); }

		add_filter( 'woi_pdf_use_path', '__return_false', 99 );
		$tokens = $this->token_map( $document );
		remove_filter( 'woi_pdf_use_path', '__return_false', 99 );
		return isset( $tokens['{{totals}}'] ) ? array( '{{totals}}' => $tokens['{{totals}}'] ) : array();
	}

	/** Persist the option (separate seam so tests can capture without WP). */
	protected function persist_editor_option( array $option ): void {
		update_option( 'woi_pdf_editor_settings', $option );
	}

	/** Current invoice column config as a plain 0-indexed list. */
	protected function read_invoice_columns(): array {
		$columns = array();
		if ( class_exists( '\\WOI\\PDF\\Editor\\EditorSettings' ) ) {
			$columns = \WOI\PDF\Editor\EditorSettings::instance()->get_settings( 'invoice', 'columns' );
		}
		$out = array();
		foreach ( (array) $columns as $col ) {
			$col = (array) $col;
			if ( empty( $col['type'] ) ) { continue; }
			$align = '';
			if ( ! empty( $col['style'] ) && preg_match( '/text-align\s*:\s*(left|center|right)/i', $col['style'], $m ) ) {
				$align = strtolower( $m[1] );
			}
			// Return the FULL column so type-specific options (price_type, tax,
			// field_name, …) survive the editor round-trip; add normalized UI fields.
			$col['align'] = $align;
			$col['field'] = isset( $col['field_name'] ) ? (string) $col['field_name'] : '';
			$out[]        = $col;
		}
		return $out;
	}

	/**
	 * Secondary-language (e.g. Arabic) label defaults as a `key => translation`
	 * map, for the editor's "Arabic header/label" placeholders: dictionary seeds
	 * overlaid with the saved global overrides. Keyed the same as
	 * BilingualEngine::secondary_key() (column type, or field_name for custom
	 * columns), so the editor can show what a blank field will inherit. No document
	 * is available on this GET route, so resolution is document-agnostic.
	 */
	protected function editor_secondary_defaults(): array {
		if ( ! class_exists( '\\WOI\\PDF\\Bilingual\\BilingualEngine' ) ) {
			return array();
		}
		$engine    = \WOI\PDF\Bilingual\BilingualEngine::instance();
		$out       = (array) $engine->dictionary( 'ar' );
		$settings  = (array) get_option( 'woi_pdf_documents_settings_invoice', array() );
		$overrides = ( isset( $settings['second_language_labels'] ) && is_array( $settings['second_language_labels'] ) )
			? $settings['second_language_labels'] : array();
		foreach ( $overrides as $k => $v ) {
			$v = trim( (string) $v );
			if ( '' !== $v ) {
				$out[ (string) $k ] = $v;
			}
		}
		return $out;
	}

	/** Map of available column type => default title. */
	protected function available_column_types(): array {
		$types = array();
		if ( class_exists( '\\WOI\\PDF\\Editor\\EditorSettings' ) && method_exists( '\\WOI\\PDF\\Editor\\EditorSettings', 'get_columns_field_options' ) ) {
			foreach ( (array) \WOI\PDF\Editor\EditorSettings::instance()->get_columns_field_options() as $type => $cfg ) {
				$types[ (string) $type ] = isset( $cfg['title'] ) ? (string) $cfg['title'] : (string) $type;
			}
		}
		return $types;
	}

				/**
		 * Render ONLY the {{line_items}} token for an order in preview mode. A
		 * column change affects just the line-items table, so the column-save
		 * endpoint returns this partial token map (merged client-side) instead of
		 * re-rendering the whole document — keeps the canvas update snappy.
		 *
		 * @param string $doc_type
		 * @param int    $order_id
		 * @return array Partial token map ('{{line_items}}' => html), or array().
		 */
		protected function render_line_items_token( string $doc_type, int $order_id ): array {
			if ( ! $order_id || ! function_exists( 'wc_get_order' ) ) { return array(); }
			$order = wc_get_order( $order_id );
			if ( empty( $order ) ) { return array(); }

			add_filter( 'woi_pdf_document_use_historical_settings', '__return_false', 99 );
			add_filter( 'woi_pdf_document_is_enabled', '__return_true', 99 );
			$document = $this->get_document( $doc_type, $order );
			remove_filter( 'woi_pdf_document_is_enabled', '__return_true', 99 );
			remove_filter( 'woi_pdf_document_use_historical_settings', '__return_false', 99 );
			if ( ! $document ) { return array(); }
			if ( is_callable( array( $document, 'initiate_date' ) ) ) { $document->initiate_date(); }

			add_filter( 'woi_pdf_use_path', '__return_false', 99 );
			$html = ( new \WOI\PDF\Visual\TemplateTokens() )->line_items( $document );
			remove_filter( 'woi_pdf_use_path', '__return_false', 99 );
			return array( '{{line_items}}' => $html );
		}

/**
		 * Registers REST API routes for handling order documents.
		 *
		 *  This function initializes the following REST API routes:
		 *  - Adds a custom 'documents' field to 'shop_order'.
		 *  - Creates or regenerates a document for a specific order.
		 *  - Deletes a document for a specific order.
		 *
		 * @return void
		 */
		public function rest_api_init(): void {
			// Add documents field to order.
			register_rest_field( 'shop_order', 'documents', array(
				'get_callback'    => array( $this, 'order_get_callback' ),
				'update_callback' => null,
				'schema'          => null,
			) );

			// Create/regenerate document.
			register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<order_id>\d+)/documents', array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_document_request' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => rest_get_endpoint_args_for_schema( $this->get_item_schema() ),
				),
				'schema' => array( $this, 'get_item_schema' ),
			) );

			// Download documents.
			register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<order_id>\d+)/documents', array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'download_document' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			) );

			// Delete document.
			register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<order_id>\d+)/documents', array(
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_document' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			) );

		}

		/**
		 * Get callback
		 *
		 * @param array $object
		 * @param string $field_name
		 * @param \WP_REST_Request $request
		 * @param string $object_type
		 *
		 * @return array
		 */
		public function order_get_callback( array $object, string $field_name, \WP_REST_Request $request, string $object_type ): array {
			if ( 'GET' !== $request->get_method() ) {
				return array();
			}

			$order = wc_get_order( absint( $object['id'] ) );

			if ( empty( $order ) ) {
				return array();
			}

			$document_type_keys = array_keys( WOI_PDF()->documents->get_documents( 'all' ) );
			$documents          = array();

			foreach ( $document_type_keys as $type ) {

				if ( 'credit-note' === $type ) {
					$order_ids = array_map( function ( $refund ) {
						return $refund->get_id();
					}, $order->get_refunds() );
				} else {
					$order_ids = array( $order->get_id() );
				}

				foreach ( $order_ids as $order_id ) {
					$document = woi_pdf_get_document( $type, $order_id );

					if ( $document && $document->exists() ) {
						if ( 'credit-note' === $type ) {
							$documents[ $type ][] = $this->output_document_data( $document );
						} else {
							$documents[ $type ] = $this->output_document_data( $document );
						}
					}
				}
			}

			return $documents;
		}

		/**
		 * Handle creation or regeneration of a document.
		 *
		 * @param \WP_REST_Request $request
		 *
		 * @return \WP_REST_Response
		 */
		public function handle_document_request( \WP_REST_Request $request ): \WP_REST_Response {
			$validation_error = $this->validate_request( $request );

			if ( $validation_error ) {
				return $validation_error;
			}

			$order = wc_get_order( absint( $request['order_id'] ) );

			if ( empty( $order ) ) {
				return new \WP_REST_Response( array( 'error' => 'Order not found.' ), 404 );
			}

			$document        = woi_pdf_get_document( sanitize_key( $request->get_param( 'type' ) ), $order );
			$is_regeneration = wc_string_to_bool( $request->get_param( 'regenerate' ) );

			if ( ! $document ) {
				$error_message = sprintf( 'Document %s failed.', $is_regeneration ? 'regeneration' : 'creation' );
				woi_pdf_log_error( $error_message, 'critical' );
				return new \WP_REST_Response( array( 'error' => $error_message ), 422 );
			}

			$document_data = $this->validate_document_data( $request );

			if ( $is_regeneration ) {
				return $this->handle_regeneration( $document, $document_data, $order );
			} else {
				return $this->handle_creation( $document, $document_data, $order );
			}
		}

		/**
		 * Downloads a document for the given order.
		 *
		 * @param \WP_REST_Request $request
		 *
		 * @return void|\WP_REST_Response
		 */
		public function download_document( \WP_REST_Request $request ) {
			$validation_error = $this->validate_request( $request );

			if ( $validation_error ) {
				return $validation_error;
			}

			$order = wc_get_order( absint( $request['order_id'] ) );

			if ( empty( $order ) ) {
				return new \WP_REST_Response( array( 'error' => 'Order not found.' ), 404 );
			}

			$document_type = sanitize_key( $request->get_param( 'type' ) );
			$init          = wc_string_to_bool( $request->get_param( 'generate' ) );

			$document = woi_pdf_get_document( $document_type, $order, $init );

			if ( ! $document || ! $document->exists() ) {
				return new \WP_REST_Response( array( 'error' => 'Document does not exist.' ), 500 );
			}

			$document->output_pdf();
			exit;
		}

		/**
		 * Deletes a document for the given order.
		 *
		 * @param \WP_REST_Request $request
		 *
		 * @return \WP_REST_Response
		 */
		public function delete_document( \WP_REST_Request $request ): \WP_REST_Response {
			$validation_error = $this->validate_request( $request );

			if ( $validation_error ) {
				return $validation_error;
			}

			$order = wc_get_order( absint( $request['order_id'] ) );

			if ( empty( $order ) ) {
				return new \WP_REST_Response( array( 'error' => 'Order not found.' ), 404 );
			}

			$document = woi_pdf_get_document(
				sanitize_key( $request->get_param( 'type' ) ),
				$order
			);

			if ( $document && $document->exists() ) {
				$document->delete();

				return new \WP_REST_Response( array( 'success' => 'Document deleted.' ), 200 );
			}

			return new \WP_REST_Response( array( 'error' => 'Document not found.' ), 404 );
		}

		/**
		 * Checks if the current user has the necessary permissions to access the API endpoint.
		 *
		 * @param \WP_REST_Request $request
		 *
		 * @return bool
		 */
		public function permissions_check( \WP_REST_Request $request ): bool {
			$order_id = absint( $request['order_id'] );
			$default  = $order_id
				? current_user_can( 'edit_shop_order', $order_id )
				: current_user_can( 'edit_shop_orders' );
			return apply_filters( 'woi_pdf_api_permission_check', $default, $request );
		}

		/**
		 * Gets the JSON schema for the document item.
		 *
		 * @return array
		 */
		public function get_item_schema(): array {
			return array(
				'$schema'    => 'http://json-schema.org/draft-04/schema#',
				'title'      => 'document',
				'type'       => 'object',
				'properties' => array(
					'number' => array(
						'description' => __( 'The number of the document.', 'woocommerce-orders-invoice-pdf' ),
						'type'        => 'string',
						'required'    => false,
					),
					'date'   => array(
						'description' => __( 'The issue date of the document.', 'woocommerce-orders-invoice-pdf' ),
						'type'        => 'string',
						'required'    => false,
					),
					'note'   => array(
						'description' => __( 'Additional notes for the document.', 'woocommerce-orders-invoice-pdf' ),
						'type'        => 'string',
						'required'    => false,
					),
				),
			);
		}

		/**
		 * Validates the incoming WP REST request.
		 *
		 * @param \WP_REST_Request $request
		 *
		 * @return \WP_REST_Response|null
		 */
		private function validate_request( \WP_REST_Request $request ): ?\WP_REST_Response {
			$order_id = absint( $request['order_id'] );

			if ( 0 === $order_id ) {
				return new \WP_REST_Response( array( 'error' => 'Order ID is invalid.' ), 400 );
			}

			$document_type = sanitize_key( $request->get_param( 'type' ) );

			if ( empty( $document_type ) ) {
				return new \WP_REST_Response( array( 'error' => 'Document type is required.' ), 400 );
			}

			if ( ! in_array( $document_type, array_keys( WOI_PDF()->documents->get_documents( 'all' ) ) ) ) {
				return new \WP_REST_Response( array( 'error' => 'Document type is invalid.' ), 404 );
			}

			return null;
		}

		/**
		 * Handles the regeneration of an existing document.
		 *
		 * @param Order_Document_Methods $document
		 * @param array $document_data
		 * @param \WC_Abstract_Order $order
		 *
		 * @return \WP_REST_Response
		 */
		private function handle_regeneration( Order_Document_Methods $document, array $document_data, \WC_Abstract_Order $order ): \WP_REST_Response {
			if ( ! $document->exists() ) {
				return new \WP_REST_Response( array( 'error' => 'Document not found to regenerate.' ), 404 );
			}

			$document_settings = $document->get_settings( true );

			// Check if the document is eligible to regenerate.
			if ( ! $document->use_historical_settings() && ! isset( $document_settings['archive_pdf'] ) ) {
				return new \WP_REST_Response( array( 'error' => 'Document not eligible for regeneration.' ), 400 );
			}

			if ( empty( $document_data['number'] ) ) {
				$document_data['number'] = $document->get_number()->get_plain();
			}

			if ( empty( $document_data['date'] ) ) {
				$document_data['date'] = $document->get_date();
			}

			$document->regenerate( $order, $document_data );
			WOI_PDF()->main->log_document_creation_trigger_to_order_meta( $document, 'rest_document_data', true );

			return new \WP_REST_Response( $this->output_document_data( $document ), 201 );
		}

		/**
		 * Handles the creation of a new document.
		 *
		 * @param Order_Document_Methods $document
		 * @param array $document_data
		 * @param \WC_Abstract_Order $order
		 *
		 * @return \WP_REST_Response
		 */
		private function handle_creation( Order_Document_Methods $document, array $document_data, \WC_Abstract_Order $order ): \WP_REST_Response {
			// Do not generate a document if it already exists; regeneration should not be processed here.
			if ( $document->exists() ) {
				return new \WP_REST_Response( $this->output_document_data( $document ), 200 );
			}

			if ( empty( $document_data['date'] ) ) {
				$document_data['date'] = time();
			}

			$document->set_data( $document_data, $order );

			// Initiate number if not set.
			if ( $document->get_date() && empty( $document->get_number() ) ) {
				$document->initiate_number();
			}

			$document->save();

			WOI_PDF()->main->log_document_creation_to_order_notes( $document, 'rest_document_data' );
			WOI_PDF()->main->log_document_creation_trigger_to_order_meta( $document, 'rest_document_data' );
			WOI_PDF()->main->mark_document_printed( $document, 'rest_document_data' );

			if ( ! $document->exists() ) {
				return new \WP_REST_Response( array( 'error' => 'Document creation failed.' ), 500 );
			}

			return new \WP_REST_Response( $this->output_document_data( $document ), 201 );
		}

		/**
		 * Outputs the document data in an array format.
		 *
		 * @param Order_Document_Methods $document
		 *
		 * @return array
		 */
		private function output_document_data( Order_Document_Methods $document ): array {
			if ( ! $document->exists() ) {
				return array();
			}

			return array(
				'number'         => $document->exists() && ! empty( $document->get_number() )
					? (int) $document->get_number()->get_plain()
					: '',
				'date'           => $document->exists() && ! empty( $document->get_date() )
					? $document->get_date()->date_i18n( 'Y-m-d\TH:i:s' )
					: '',
				'date_timestamp' => $document->exists() && ! empty( $document->get_date() )
					? $document->get_date()->getTimestamp()
					: '',
			);
		}

		/**
		 * Validates document data from the incoming WP REST request.
		 *
		 * @param \WP_REST_Request $request
		 *
		 * @return array
		 */
		private function validate_document_data( \WP_REST_Request $request ): array {
			$document_data = array(
				'number' => absint( $request->get_param( 'number' ) ),
				'date'   => sanitize_text_field( $request->get_param( 'date' ) ),
				'note'   => sanitize_textarea_field( $request->get_param( 'note' ) ),
			);

			// Validate number.
			if ( ! empty( $document_data['number'] ) ) {
				$document_data['number'] = sanitize_text_field( $document_data['number'] );
			}

			// Validate date.
			if ( ! empty( $document_data['date'] ) ) {
				$document_data['date'] = \DateTime::createFromFormat( \DateTime::ATOM, $document_data['date'] );
				$document_data['date'] = $document_data['date'] ? $document_data['date']->getTimestamp() : time();
			}

			if ( ! empty( $document_data['note'] ) ) {
				// Validate note.
				$allowed_html = array(
					'a'      => array(
						'href'  => array(),
						'title' => array(),
						'id'    => array(),
						'class' => array(),
						'style' => array(),
					),
					'br'     => array(),
					'em'     => array(),
					'strong' => array(),
					'div'    => array(
						'id'    => array(),
						'class' => array(),
						'style' => array(),
					),
					'span'   => array(
						'id'    => array(),
						'class' => array(),
						'style' => array(),
					),
					'p'      => array(
						'id'    => array(),
						'class' => array(),
						'style' => array(),
					),
					'b'      => array(),
				);

				$document_data['notes'] = wp_kses( $document_data['note'], $allowed_html );
			}

			// Return data which are not empty.
			return array_filter( $document_data );
		}

		/**
		 * Returns a token→value map for an order so the visual editor can show a live preview.
		 *
		 * @param object $request Request object with get_param().
		 *
		 * @return array|\WP_Error
		 */
		public function handle_visual_preview_data( $request ) {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return new \WP_Error( 'forbidden', 'Insufficient permissions', array( 'status' => 403 ) );
			}

			// Live preview must always reflect the latest design + settings, so the
			// response must never be cached (browser, proxy, or LiteSpeed/page cache).
			$this->disable_response_cache();

			$doc_type = $request->get_param( 'doc_type' );
			$doc_type = $doc_type ? (string) $doc_type : 'invoice';

			$order_id = (int) $request->get_param( 'order_id' );
			if ( ! $order_id ) {
				$ids      = wc_get_orders( array( 'limit' => 1, 'return' => 'ids', 'type' => 'shop_order' ) );
				$order_id = ! empty( $ids ) ? (int) reset( $ids ) : 0;
			}

			$order = $order_id ? wc_get_order( $order_id ) : false;
			if ( ! $order ) {
				return new \WP_Error( 'no_order', 'No order found to preview.', array( 'status' => 404 ) );
			}

			// Preview mode: reflect live settings, treat doc as enabled (read-only — no number reservation).
			add_filter( 'woi_pdf_document_use_historical_settings', '__return_false', 99 );
			add_filter( 'woi_pdf_document_is_enabled', '__return_true', 99 );
			$document = $this->get_document( $doc_type, $order );
			remove_filter( 'woi_pdf_document_is_enabled', '__return_true', 99 );
			remove_filter( 'woi_pdf_document_use_historical_settings', '__return_false', 99 );

			if ( ! $document ) {
				return new \WP_Error( 'no_document', 'Could not build the document for this order.', array( 'status' => 404 ) );
			}
			if ( is_callable( array( $document, 'initiate_date' ) ) ) {
				$document->initiate_date();
			}

			$label = '#' . $order->get_order_number();
			$name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
			if ( '' !== $name ) {
				$label .= ' — ' . $name;
			}

			// Browser preview is HTML, not mPDF: thumbnails and the logo must use
			// web URLs, not server filesystem paths. Mirror the HTML-output path
			// (see Main::handle_document_request) which forces this filter false.
			add_filter( 'woi_pdf_use_path', '__return_false', 99 );
			$tokens = $this->token_map( $document );
			remove_filter( 'woi_pdf_use_path', '__return_false', 99 );

			return array(
				'order_id'    => $order_id,
				'order_label' => $label,
				'tokens'      => $tokens,
			);
		}

		/**
		 * Seam over woi_pdf_get_document so the handler is unit-testable.
		 *
		 * @param string $doc_type
		 * @param mixed  $order
		 *
		 * @return mixed
		 */
		protected function get_document( string $doc_type, $order ) {
			return woi_pdf_get_document( $doc_type, $order );
		}

		/** Seam over TemplateTokens::map so the handler is unit-testable. */
		protected function token_map( $document ): array {
			return ( new \WOI\PDF\Visual\TemplateTokens() )->map( $document );
		}

		/**
		 * Send no-store cache headers for the preview response.
		 *
		 * nocache_headers() covers browsers/proxies; the LiteSpeed action opts
		 * this response out of the page cache (a no-op when LiteSpeed is absent).
		 * A seam so the handler stays unit-testable without emitting headers.
		 */
		protected function disable_response_cache(): void {
			nocache_headers();
			do_action( 'litespeed_control_set_nocache', 'woi-pdf visual preview data' );
		}

		/**
		 * Handles saving a visual invoice template via the REST API.
		 *
		 * @param object $request Request object with get_param().
		 *
		 * @return array|\WP_Error
		 */
		public function handle_visual_template_save( $request ) {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return new \WP_Error( 'forbidden', 'Insufficient permissions', array( 'status' => 403 ) );
			}
			$doc_type = (string) $request->get_param( 'doc_type' );
			$html     = (string) $request->get_param( 'html' );

			( new \WOI\PDF\Visual\VisualTemplateStore() )->save( $doc_type, $html );

			return array( 'saved' => true );
		}

		/**
		 * Render block markup to HTML for the visual render path.
		 * Seam so the save handler is unit-testable without the WP block registry.
		 */
		protected function render_blocks( string $markup ): string {
			return function_exists( 'do_blocks' ) ? do_blocks( $markup ) : $markup;
		}

		/**
		 * Save block markup: render → store markup (raw) + rendered HTML (kses'd).
		 *
		 * @param object $request Request object with get_param().
		 * @return array|\WP_Error
		 */
		public function handle_visual_blocks_save( $request ) {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return new \WP_Error( 'forbidden', 'Insufficient permissions', array( 'status' => 403 ) );
			}
			$doc_type = (string) $request->get_param( 'doc_type' );
			$markup   = (string) $request->get_param( 'markup' );
			$html     = $this->render_blocks( $markup );

			( new \WOI\PDF\Visual\VisualTemplateStore() )->save_blocks( $doc_type, $markup, $html );

			return array( 'saved' => true );
		}

		/**
		 * Set which visual source feeds the PDF.
		 *
		 * @param object $request Request object with get_param().
		 * @return array|\WP_Error
		 */
		public function handle_visual_active_source( $request ) {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return new \WP_Error( 'forbidden', 'Insufficient permissions', array( 'status' => 403 ) );
			}
			$store = new \WOI\PDF\Visual\VisualTemplateStore();
			$store->set_active_source( (string) $request->get_param( 'source' ) );
			return array( 'source' => $store->get_active_source() );
		}

		/**
		 * Document types that maintain a numbering series.
		 *
		 * @return string[]
		 */
		public static function numbering_types(): array {
			return array( 'invoice', 'proforma', 'credit-note', 'receipt' );
		}

		/**
		 * Document types configurable in the naming/filename panel (numbered
		 * types + packing-slip, which has a filename override but no series).
		 *
		 * @return string[]
		 */
		public static function naming_types(): array {
			return array_merge( self::numbering_types(), array( 'packing-slip' ) );
		}

		/**
		 * Read the naming + filename settings for a type from its PDF settings
		 * option. Does NOT include next_number (that needs a document instance).
		 *
		 * @param string $type
		 * @return array
		 */
		public static function read_naming_settings( string $type ): array {
			$option     = (array) get_option( "woi_pdf_documents_settings_{$type}", array() );
			$has_series = in_array( $type, self::numbering_types(), true );
			$format     = isset( $option['number_format'] ) && is_array( $option['number_format'] ) ? $option['number_format'] : array();

			return array(
				'type'                => $type,
				'has_series'          => $has_series,
				'prefix'              => isset( $format['prefix'] ) ? (string) $format['prefix'] : '',
				'suffix'              => isset( $format['suffix'] ) ? (string) $format['suffix'] : '',
				'padding'             => isset( $format['padding'] ) ? (string) $format['padding'] : '',
				'reset_number_yearly' => ! empty( $option['reset_number_yearly'] ),
				'filename_template'   => isset( $option['filename_template'] ) ? (string) $option['filename_template'] : '',
			);
		}

		/**
		 * Merge incoming naming fields into an existing settings option without
		 * clobbering unrelated keys. Numbering fields are ignored when the type
		 * has no series.
		 *
		 * @param array $existing  Current option array.
		 * @param array $incoming  Sanitized incoming fields.
		 * @param bool  $has_series
		 * @return array
		 */
		public static function merge_naming_settings( array $existing, array $incoming, bool $has_series ): array {
			$merged = $existing;

			// Filename override applies to every naming type.
			$merged['filename_template'] = isset( $incoming['filename_template'] ) ? (string) $incoming['filename_template'] : '';

			if ( $has_series ) {
				$merged['number_format'] = array(
					'prefix'  => isset( $incoming['prefix'] ) ? (string) $incoming['prefix'] : '',
					'suffix'  => isset( $incoming['suffix'] ) ? (string) $incoming['suffix'] : '',
					'padding' => isset( $incoming['padding'] ) ? (string) $incoming['padding'] : '',
				);
				// Stored as a checkbox: present '1' when on, absent when off.
				if ( ! empty( $incoming['reset_number_yearly'] ) ) {
					$merged['reset_number_yearly'] = '1';
				} else {
					unset( $merged['reset_number_yearly'] );
				}
			}

			return $merged;
		}

		/**
		 * GET: read naming + filename settings (incl. live next_number) for a type.
		 *
		 * @param object $request
		 * @return array|\WP_Error
		 */
		public function handle_get_document_naming( $request ) {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return new \WP_Error( 'forbidden', 'Insufficient permissions', array( 'status' => 403 ) );
			}
			$type = sanitize_text_field( (string) $request->get_param( 'type' ) );
			if ( ! in_array( $type, self::naming_types(), true ) ) {
				return new \WP_Error( 'invalid_type', 'Unknown document type', array( 'status' => 400 ) );
			}

			$data               = self::read_naming_settings( $type );
			$data['next_number'] = $data['has_series'] ? $this->read_next_number( $type ) : null;

			return $data;
		}

		/**
		 * POST: persist naming + filename settings for a type, including the
		 * sequential next_number (written through the document's number store).
		 *
		 * @param object $request
		 * @return array|\WP_Error
		 */
		public function handle_save_document_naming( $request ) {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return new \WP_Error( 'forbidden', 'Insufficient permissions', array( 'status' => 403 ) );
			}
			$type = sanitize_text_field( (string) $request->get_param( 'type' ) );
			if ( ! in_array( $type, self::naming_types(), true ) ) {
				return new \WP_Error( 'invalid_type', 'Unknown document type', array( 'status' => 400 ) );
			}
			$has_series = in_array( $type, self::numbering_types(), true );

			$incoming = array(
				'prefix'              => sanitize_text_field( (string) $request->get_param( 'prefix' ) ),
				'suffix'              => sanitize_text_field( (string) $request->get_param( 'suffix' ) ),
				'padding'             => sanitize_text_field( (string) $request->get_param( 'padding' ) ),
				'reset_number_yearly' => (bool) $request->get_param( 'reset_number_yearly' ),
				'filename_template'   => sanitize_text_field( (string) $request->get_param( 'filename_template' ) ),
			);

			$existing = (array) get_option( "woi_pdf_documents_settings_{$type}", array() );
			$merged   = self::merge_naming_settings( $existing, $incoming, $has_series );
			update_option( "woi_pdf_documents_settings_{$type}", $merged );

			// next_number is sequential-store state, not an option key. Write it
			// through the document's own store (same mechanism as the classic
			// next_number_edit save) AFTER the option update so the store name
			// reflects the just-saved reset_number_yearly flag.
			if ( $has_series ) {
				$next = absint( $request->get_param( 'next_number' ) );
				if ( $next > 0 ) {
					$this->write_next_number( $type, $next );
				}
			}

			$data                = self::read_naming_settings( $type );
			$data['next_number'] = $has_series ? $this->read_next_number( $type ) : null;
			return $data;
		}

		/**
		 * POST: resolve a live preview of the formatted number and the PDF filename
		 * for a type, using the INCOMING (unsaved) prefix/suffix/padding/template so
		 * the panel reflects edits before they are saved. Read-only: no option write,
		 * no number-store increment.
		 *
		 * @param object $request
		 * @return array|\WP_Error
		 */
		public function handle_naming_preview( $request ) {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return new \WP_Error( 'forbidden', 'Insufficient permissions', array( 'status' => 403 ) );
			}
			$type = sanitize_text_field( (string) $request->get_param( 'type' ) );
			if ( ! in_array( $type, self::naming_types(), true ) ) {
				return new \WP_Error( 'invalid_type', 'Unknown document type', array( 'status' => 400 ) );
			}

			$has_series  = in_array( $type, self::numbering_types(), true );
			$prefix      = (string) $request->get_param( 'prefix' );
			$suffix      = (string) $request->get_param( 'suffix' );
			$padding     = (int) $request->get_param( 'padding' );
			$next_number = (int) $request->get_param( 'next_number' );
			$template    = trim( (string) $request->get_param( 'filename_template' ) );

			$order = $this->naming_preview_order( $type, (int) $request->get_param( 'order_id' ) );
			if ( ! $order ) {
				return array(
					'number_preview'   => '',
					'filename_preview' => '',
					'order_id'         => 0,
					'has_order'        => false,
				);
			}

			// Inject the unsaved values into the per-type option for THIS request only,
			// so both the number formatter and get_filename() reflect the edits without
			// persisting anything. woi_pdf_get_filename_settings() and the document's
			// settings both read this option via get_option, so the filter applies.
			$inject = function ( $value ) use ( $template, $prefix, $suffix, $padding, $has_series ) {
				$value = (array) $value;
				$value['filename_template'] = $template; // '' -> global template fallback
				if ( $has_series ) {
					$value['number_format'] = array(
						'prefix'  => $prefix,
						'suffix'  => $suffix,
						'padding' => (string) $padding,
					);
				}
				return $value;
			};
			add_filter( "option_woi_pdf_documents_settings_{$type}", $inject );

			$document = $this->get_document( $type, $order );

			$number_preview = '';
			if ( $document && $has_series ) {
				$number_preview = (string) $this->format_document_number(
					$next_number, $prefix, $suffix, $padding, $document, $order
				);
			}

			$filename_preview = '';
			if ( $document && is_callable( array( $document, 'get_filename' ) ) ) {
				$filename_preview = (string) $document->get_filename( 'download', array(
					'order_ids' => array( $order->get_id() ),
					'output'    => 'pdf',
				) );
			}

			remove_filter( "option_woi_pdf_documents_settings_{$type}", $inject );

			return array(
				'number_preview'   => $number_preview,
				'filename_preview' => $filename_preview,
				'order_id'         => (int) $order->get_id(),
				'has_order'        => true,
			);
		}

		/**
		 * Resolve the order to preview against: the requested id, else the most recent
		 * shop order. Returns false when no order exists.
		 *
		 * @param string $type
		 * @param int    $order_id
		 * @return \WC_Abstract_Order|false
		 */
		private function naming_preview_order( string $type, int $order_id ) {
			if ( $order_id > 0 ) {
				$order = wc_get_order( $order_id );
				if ( $order ) {
					return $order;
				}
			}
			$recent = wc_get_orders( array( 'limit' => 1, 'return' => 'ids', 'type' => 'shop_order' ) );
			if ( ! empty( $recent ) ) {
				$order = wc_get_order( reset( $recent ) );
				if ( $order ) {
					return $order;
				}
			}
			return false;
		}

		/**
		 * Seam over woi_pdf_format_document_number so the handler is unit-testable.
		 * woi_pdf_format_document_number() is defined in woi-pdf-functions.php before
		 * Patchwork loads, so it cannot be stubbed via Brain\Monkey\Functions\when().
		 *
		 * @param int    $plain
		 * @param string $prefix
		 * @param string $suffix
		 * @param int    $padding
		 * @param object $document
		 * @param object $order
		 * @return string
		 */
		protected function format_document_number( int $plain, string $prefix, string $suffix, int $padding, $document, $order ): string {
			return function_exists( 'woi_pdf_format_document_number' )
				? (string) woi_pdf_format_document_number( $plain, $prefix, $suffix, $padding, $document, $order )
				: '';
		}

		/**
		 * Read the next sequential number for a type from its number store.
		 *
		 * @param string $type
		 * @return int
		 */
		private function read_next_number( string $type ): int {
			$document = $this->get_naming_document( $type );
			if ( ! $document || ! is_callable( array( $document, 'get_sequential_number_store' ) ) ) {
				return 0;
			}
			return (int) $document->get_sequential_number_store()->get_next();
		}

		/**
		 * Write the next sequential number for a type to its number store.
		 *
		 * @param string $type
		 * @param int    $number
		 * @return void
		 */
		private function write_next_number( string $type, int $number ): void {
			$document = $this->get_naming_document( $type );
			if ( $document && is_callable( array( $document, 'get_sequential_number_store' ) ) ) {
				$document->get_sequential_number_store()->set_next( $number );
			}
		}

		/**
		 * Get an orderless document instance for a numbered type (loads its
		 * settings so the store name reflects reset_number_yearly).
		 *
		 * @param string $type
		 * @return object|false
		 */
		private function get_naming_document( string $type ) {
			if ( ! function_exists( 'WOI_PDF' ) || empty( WOI_PDF()->documents ) ) {
				return false;
			}
			// Orderless instance: get_document( $type, $order ) requires the second
			// arg; null yields an orderless document whose settings load from the
			// per-type option (enough for the sequential-number store).
			return WOI_PDF()->documents->get_document( $type, null );
		}

		/**
		 * Save the visual document appearance options (accent / letterhead /
		 * density / bilingual / thumbnails / font). Values are whitelisted on read
		 * by woi_pdf_visual_doc_options(), so saving sanitised scalars is enough.
		 *
		 * @param object $request Request object with get_param().
		 * @return array|\WP_Error
		 */
		public function handle_visual_doc_options( $request ) {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return new \WP_Error( 'forbidden', 'Insufficient permissions', array( 'status' => 403 ) );
			}
			$incoming = (array) $request->get_param( 'options' );
			$clean    = array();
			// Persist exactly the known option keys — derived from the SAME source of
			// truth that woi_pdf_visual_doc_options() validates on read. Deriving the
			// allowlist (rather than hardcoding it) prevents a newly-added option such
			// as repeat_letterhead from being silently dropped on save because this
			// list drifted out of sync with the read whitelist.
			foreach ( array_keys( woi_pdf_visual_doc_options() ) as $key ) {
				if ( isset( $incoming[ $key ] ) ) {
					$clean[ $key ] = sanitize_text_field( (string) $incoming[ $key ] );
				}
			}
			$existing = (array) get_option( 'woi_pdf_visual_doc_options', array() );
			update_option( 'woi_pdf_visual_doc_options', array_merge( $existing, $clean ) );
			return array( 'options' => woi_pdf_visual_doc_options( 'invoice' ) );
		}

		/**
		 * Save the contact-strip per-element layout (order / visibility / align /
		 * style) to its own option. The config travels via this option — NOT an
		 * HTML data-attribute — so it survives kses and never trips block
		 * validation. Normalised through the same sanitiser used on read.
		 *
		 * @param object $request Request object with get_param().
		 * @return array|\WP_Error
		 */
		public function handle_contact_items_save( $request ) {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return new \WP_Error( 'forbidden', 'Insufficient permissions', array( 'status' => 403 ) );
			}
			$clean = woi_pdf_sanitize_contact_items( (array) $request->get_param( 'items' ) );
			update_option( 'woi_pdf_contact_items', $clean, false );
			return array( 'items' => $clean );
		}

		/**
		 * Save the letterhead per-element + arrangement config (swapText, logoWidth,
		 * per-element style/visibility) to its own option. Logo POSITION is saved
		 * separately via /visual-doc-options (the shared `header` key). Normalised
		 * through the same sanitiser used on read.
		 *
		 * @param object $request
		 * @return array|\WP_Error
		 */
		public function handle_letterhead_save( $request ) {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return new \WP_Error( 'forbidden', 'Insufficient permissions', array( 'status' => 403 ) );
			}
			$clean = woi_pdf_sanitize_letterhead( (array) $request->get_param( 'items' ) );
			update_option( 'woi_pdf_letterhead', $clean, false );
			return array( 'items' => $clean );
		}

	}

endif;