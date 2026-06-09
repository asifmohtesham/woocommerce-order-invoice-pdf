<?php
namespace WOI\PDF\Settings;

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( '\\WOI\\PDF\\Settings\\SettingsDocuments' ) ) :

class SettingsDocuments {

	protected static ?self $_instance = null;

	public static function instance(): self {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	public function __construct() {
		add_action( 'admin_init', array( $this, 'init_settings' ) );
	}

	public function init_settings(): void {
		$page = $option_group = $option_name = 'woi_pdf_settings_documents';

		ob_start();
		?>
		<p><?php esc_html_e( 'Select a document type below to configure its settings.', 'woocommerce-orders-invoice-pdf' ); ?></p>
		<ul style="list-style:disc;margin-left:1.5em;">
		<?php
		$documents = \WOI_PDF()->documents->get_documents( 'all' );
		foreach ( $documents as $document ) :
			$url = admin_url( 'admin.php?page=woi_pdf_options_page&tab=woi_pdf_' . rawurlencode( $document->get_type() ) );
			?>
			<li>
				<a href="<?php echo esc_url( $url ); ?>">
					<?php echo esc_html( $document->get_title() ); ?>
				</a>
			</li>
		<?php endforeach; ?>
		</ul>
		<?php
		$html = ob_get_clean();

		$settings_fields = array(
			array(
				'type'     => 'section',
				'id'       => 'documents_overview',
				'title'    => __( 'Document types', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'section',
			),
			array(
				'type'     => 'setting',
				'id'       => 'documents_links',
				'title'    => '',
				'callback' => 'html_section',
				'section'  => 'documents_overview',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'documents_links',
					'html'        => $html,
				),
			),
		);

		\WOI_PDF()->settings->add_settings_fields( $settings_fields, $page, $option_group, $option_name );
	}
}

endif;
