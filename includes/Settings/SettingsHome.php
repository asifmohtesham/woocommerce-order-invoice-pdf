<?php
namespace WOI\PDF\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( '\\WOI\\PDF\\Settings\\SettingsHome' ) ) :

class SettingsHome {

	protected static ?self $_instance = null;

	public static function instance(): self {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	public function __construct() {
		add_filter( 'woi_pdf_settings_tabs', array( $this, 'add_home_tab' ), 1 );
		add_filter( 'woi_pdf_settings_tabs_default', array( $this, 'default_tab' ) );
		add_action( 'woi_pdf_settings_output_home', array( $this, 'output' ), 10, 2 );
		add_action( 'wp_ajax_woi_pdf_enable_document', array( $this, 'ajax_enable_document' ) );
	}

	public function add_home_tab( array $tabs ): array {
		return array(
			'home' => array(
				'title'          => __( 'Home', 'woocommerce-orders-invoice-pdf' ),
				'preview_states' => 1,
			),
		) + $tabs;
	}

	public function default_tab(): string {
		return 'home';
	}

	/**
	 * Pure checklist computation. Inputs are raw option arrays so tests need no WP state.
	 *
	 * @param array $general             woi_pdf_settings_general option
	 * @param array $invoice             woi_pdf_documents_settings_invoice option
	 * @param int   $next_invoice_number next number from the invoice sequence store
	 *
	 * @return array id => array( 'id', 'label', 'done', 'tab', 'section', 'anchor' )
	 */
	public static function compute_checklist( array $general, array $invoice, int $next_invoice_number ): array {
		$number_format = isset( $invoice['number_format'] ) && is_array( $invoice['number_format'] )
			? $invoice['number_format']
			: array();

		$items = array(
			'shop_address'     => array(
				'label'   => __( 'Set your shop name & address', 'woocommerce-orders-invoice-pdf' ),
				'done'    => self::setting_filled( $general['shop_name'] ?? '' ) && self::setting_filled( $general['shop_address_line_1'] ?? '' ),
				'tab'     => 'general',
				'section' => '',
				'anchor'  => 'shop_name',
			),
			'invoice_enabled'  => array(
				'label'   => __( 'Enable the invoice', 'woocommerce-orders-invoice-pdf' ),
				'done'    => ! empty( $invoice['enabled'] ),
				'tab'     => 'documents',
				'section' => 'invoice',
				'anchor'  => 'enabled',
			),
			'numbering'        => array(
				'label'   => __( 'Configure invoice numbering', 'woocommerce-orders-invoice-pdf' ),
				'done'    => self::setting_filled( $number_format['prefix'] ?? '' )
					|| self::setting_filled( $number_format['suffix'] ?? '' )
					|| ! empty( $number_format['padding'] )
					|| $next_invoice_number > 1,
				'tab'     => 'documents',
				'section' => 'invoice',
				'anchor'  => 'number_format',
			),
			'logo'             => array(
				'label'   => __( 'Upload a header logo', 'woocommerce-orders-invoice-pdf' ),
				'done'    => ! empty( $general['header_logo'] ),
				'tab'     => 'general',
				'section' => '',
				'anchor'  => 'header_logo',
			),
			'email_attachment' => array(
				'label'   => __( 'Attach the invoice to order emails', 'woocommerce-orders-invoice-pdf' ),
				'done'    => ! empty( $invoice['attach_to_email_ids'] ),
				'tab'     => 'documents',
				'section' => 'invoice',
				'anchor'  => 'attach_to_email_ids',
			),
		);

		foreach ( $items as $id => &$item ) {
			$item['id'] = $id;
		}

		return $items;
	}

	/**
	 * A setting counts as filled when it is a non-empty string, or an array
	 * containing at least one non-empty string (multilingual values).
	 *
	 * @param mixed $value
	 */
	private static function setting_filled( $value ): bool {
		if ( is_array( $value ) ) {
			foreach ( $value as $entry ) {
				if ( is_string( $entry ) && '' !== trim( $entry ) ) {
					return true;
				}
			}
			return false;
		}

		return is_string( $value ) ? '' !== trim( $value ) : ! empty( $value );
	}

	/**
	 * Gather live checklist state from WP options + number store.
	 */
	public function get_checklist(): array {
		$general = get_option( 'woi_pdf_settings_general', array() );
		$invoice = get_option( 'woi_pdf_documents_settings_invoice', array() );
		$next    = 1;

		$invoice_document = woi_pdf_get_document( 'invoice', null );
		if ( $invoice_document && is_callable( array( $invoice_document, 'get_sequential_number_store' ) ) ) {
			$next = (int) $invoice_document->get_sequential_number_store()->get_next();
		}

		return self::compute_checklist(
			is_array( $general ) ? $general : array(),
			is_array( $invoice ) ? $invoice : array(),
			$next
		);
	}

	/**
	 * Per-document summary for the Home status cards.
	 */
	public function get_documents_summary(): array {
		$summary = array();

		foreach ( WOI_PDF()->documents->get_documents( 'all' ) as $document ) {
			$type     = $document->get_type();
			$settings = get_option( 'woi_pdf_documents_settings_' . $type, array() );
			$settings = is_array( $settings ) ? $settings : array();

			$next_number = null;
			if ( $document->is_enabled() && is_callable( array( $document, 'get_sequential_number_store' ) ) ) {
				$store = $document->get_sequential_number_store();
				if ( $store ) {
					$next_number = (int) $store->get_next();
				}
			}

			$summary[] = array(
				'type'          => $type,
				'title'         => wp_strip_all_tags( $document->get_title() ),
				'enabled'       => $document->is_enabled(),
				'next_number'   => $next_number,
				'email_count'   => is_array( $settings['attach_to_email_ids'] ?? null ) ? count( $settings['attach_to_email_ids'] ) : 0,
				'settings_url'  => add_query_arg(
					array( 'page' => 'woi_pdf_options_page', 'tab' => 'documents', 'section' => $type ),
					admin_url( 'admin.php' )
				),
				'customise_url' => add_query_arg(
					array( 'page' => 'woi_pdf_options_page', 'tab' => 'editor', 'section' => $type ),
					admin_url( 'admin.php' )
				),
			);
		}

		return $summary;
	}

	/**
	 * One-click enable from a Home status card.
	 */
	public function ajax_enable_document(): void {
		check_ajax_referer( 'woi_pdf_admin_nonce', 'security' );

		if ( ! WOI_PDF()->settings->user_can_manage_settings() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to change settings.', 'woocommerce-orders-invoice-pdf' ) ) );
		}

		$type      = isset( $_POST['document_type'] ) ? sanitize_key( wp_unslash( $_POST['document_type'] ) ) : '';
		$documents = wp_list_pluck(
			array_map(
				fn( $document ) => array( 'type' => $document->get_type() ),
				WOI_PDF()->documents->get_documents( 'all' )
			),
			'type'
		);

		if ( empty( $type ) || ! in_array( $type, $documents, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown document type.', 'woocommerce-orders-invoice-pdf' ) ) );
		}

		$option_name         = 'woi_pdf_documents_settings_' . $type;
		$settings            = get_option( $option_name, array() );
		$settings            = is_array( $settings ) ? $settings : array();
		$settings['enabled'] = 1;
		update_option( $option_name, $settings );

		wp_send_json_success( array( 'type' => $type, 'enabled' => true ) );
	}

	/**
	 * Render the Home mount point with no-JS fallback links.
	 * Hooked to woi_pdf_settings_output_home (rendered outside the form by the shell view).
	 *
	 * @param string $section unused
	 * @param string $nonce   settings-page nonce
	 */
	public function output( string $section, string $nonce ): void {
		if ( ! wp_verify_nonce( $nonce, 'wp_woi_pdf_settings_page_nonce' ) ) {
			return;
		}
		?>
		<div id="woi-pdf-home-root">
			<p><?php esc_html_e( 'Loading the PDF Invoices dashboard…', 'woocommerce-orders-invoice-pdf' ); ?></p>
			<ul class="woi-home-fallback">
				<li><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'woi_pdf_options_page', 'tab' => 'general' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'General settings', 'woocommerce-orders-invoice-pdf' ); ?></a></li>
				<li><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'woi_pdf_options_page', 'tab' => 'documents', 'section' => 'invoice' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Invoice settings', 'woocommerce-orders-invoice-pdf' ); ?></a></li>
			</ul>
		</div>
		<?php
	}
}

endif; // class_exists
