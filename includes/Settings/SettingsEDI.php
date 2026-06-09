<?php
namespace WOI\PDF\Settings;

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( '\\WOI\\PDF\\Settings\\SettingsEDI' ) ) :

class SettingsEDI {

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
		$page = $option_group = $option_name = 'woi_pdf_settings_edi';

		ob_start();
		?>
		<div class="notice notice-info inline" style="margin:0;">
			<p>
				<?php esc_html_e( 'E-Documents (EDI/UBL) functionality requires the E-Documents extension, which is not included in this build.', 'woocommerce-orders-invoice-pdf' ); ?>
			</p>
		</div>
		<?php
		$html = ob_get_clean();

		$settings_fields = array(
			array(
				'type'     => 'section',
				'id'       => 'edi_info',
				'title'    => __( 'E-Documents', 'woocommerce-orders-invoice-pdf' ),
				'callback' => 'section',
			),
			array(
				'type'     => 'setting',
				'id'       => 'edi_notice',
				'title'    => '',
				'callback' => 'html_section',
				'section'  => 'edi_info',
				'args'     => array(
					'option_name' => $option_name,
					'id'          => 'edi_notice',
					'html'        => $html,
				),
			),
		);

		\WOI_PDF()->settings->add_settings_fields( $settings_fields, $page, $option_group, $option_name );
	}
}

endif;
