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
		add_action( 'woi_pdf_settings_output_documents', array( $this, 'output' ), 10, 2 );
	}

	public function output( string $section, string $nonce ): void {
		if ( ! wp_verify_nonce( $nonce, 'wp_woi_pdf_settings_page_nonce' ) ) {
			return;
		}

		$section   = ! empty( $section ) ? sanitize_key( $section ) : 'invoice';
		$documents = \WOI_PDF()->documents->get_documents( 'all' );

		$active = null;
		foreach ( $documents as $doc ) {
			if ( $doc->get_type() === $section ) {
				$active = $doc;
				break;
			}
		}
		if ( empty( $active ) ) {
			$active = reset( $documents );
			if ( ! $active ) {
				return;
			}
			$section = $active->get_type();
		}

		$option_name  = 'woi_pdf_documents_settings_' . $section;
		$active_title = wp_strip_all_tags( $active->get_title() );
		if ( '' === trim( $active_title ) ) {
			$active_title = '[' . __( 'untitled', 'woocommerce-orders-invoice-pdf' ) . ']';
		}
		?>
		<div class="wcpdf_document_settings_sections">
			<span><?php esc_html_e( 'Choose document', 'woocommerce-orders-invoice-pdf' ); ?></span>
			<h2><?php echo esc_html( $active_title ); ?><span class="arrow-down">&#9660;</span></h2>
			<ul>
				<?php foreach ( $documents as $doc ) :
					if ( $doc->get_type() === $section ) {
						continue;
					}
					$title = wp_strip_all_tags( $doc->get_title() );
					if ( '' === trim( $title ) ) {
						$title = '[' . __( 'untitled', 'woocommerce-orders-invoice-pdf' ) . ']';
					}
				?>
				<li>
					<a href="<?php echo esc_url( add_query_arg(
						array( 'tab' => 'documents', 'section' => $doc->get_type() ),
						admin_url( 'admin.php?page=woi_pdf_options_page' )
					) ); ?>">
						<?php echo esc_html( $title ); ?>
					</a>
				</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
		settings_fields( $option_name );
		do_settings_sections( $option_name );
		submit_button();
	}
}

endif;
