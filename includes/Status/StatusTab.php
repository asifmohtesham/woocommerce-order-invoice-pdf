<?php
namespace WOI\PDF\Status;

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( '\\WOI\\PDF\\Status\\StatusTab' ) ) :

/**
 * Registers the "Status" tab in the PDF Invoices settings nav and renders the
 * version + diagnostics screen (full-width, read-only) with a copy button.
 */
class StatusTab {

	public function __construct() {
		add_filter( 'woi_pdf_settings_tabs', array( $this, 'register_tab' ) );
		add_filter( 'woi_pdf_fullwidth_tabs', array( $this, 'mark_fullwidth' ) );
		add_action( 'woi_pdf_settings_output_status', array( $this, 'render' ) );
	}

	/**
	 * @param array $tabs
	 * @return array
	 */
	public function register_tab( $tabs ) {
		if ( is_array( $tabs ) ) {
			$tabs['status'] = array(
				'title' => __( 'Status', 'woocommerce-orders-invoice-pdf' ),
			);
		}
		return $tabs;
	}

	/**
	 * @param array $tabs
	 * @return array
	 */
	public function mark_fullwidth( $tabs ) {
		$tabs[]  = 'status';
		return $tabs;
	}

	public function render(): void {
		$diag   = new Diagnostics( WOI_PDF()->plugin_path(), new GitRevision() );
		$groups = $diag->collect();
		$report = Diagnostics::to_text( $groups );

		echo '<div class="woi-status-screen" style="max-width:760px">';
		echo '<h2>' . esc_html__( 'Status & Diagnostics', 'woocommerce-orders-invoice-pdf' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Versioning and environment details for this install. Include these when reporting an issue.', 'woocommerce-orders-invoice-pdf' ) . '</p>';

		foreach ( $groups as $group => $items ) {
			echo '<h3>' . esc_html( $group ) . '</h3>';
			echo '<table class="widefat striped" style="margin-bottom:1em"><tbody>';
			foreach ( $items as $label => $value ) {
				echo '<tr><td style="width:230px"><strong>' . esc_html( $label ) . '</strong></td><td><code>' . esc_html( $value ) . '</code></td></tr>';
			}
			echo '</tbody></table>';
		}

		echo '<p>';
		echo '<button type="button" class="button button-secondary" id="woi-copy-diagnostics">' . esc_html__( 'Copy diagnostics', 'woocommerce-orders-invoice-pdf' ) . '</button> ';
		echo '<span id="woi-copy-diagnostics-done" style="color:#1a7d3c;display:none">' . esc_html__( 'Copied!', 'woocommerce-orders-invoice-pdf' ) . '</span>';
		echo '</p>';
		echo '<textarea id="woi-diagnostics-text" readonly style="position:absolute;left:-9999px;top:-9999px" aria-hidden="true">' . esc_textarea( $report ) . '</textarea>';

		?>
		<script>
		( function () {
			var btn = document.getElementById( 'woi-copy-diagnostics' );
			if ( ! btn ) { return; }
			btn.addEventListener( 'click', function () {
				var ta   = document.getElementById( 'woi-diagnostics-text' );
				var done = document.getElementById( 'woi-copy-diagnostics-done' );
				var text = ta ? ta.value : '';
				var show = function () { if ( done ) { done.style.display = 'inline'; setTimeout( function () { done.style.display = 'none'; }, 2000 ); } };
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( text ).then( show, function () { ta.removeAttribute( 'aria-hidden' ); ta.style.position = 'static'; ta.style.left = 'auto'; ta.select(); document.execCommand( 'copy' ); show(); } );
				} else if ( ta ) {
					ta.removeAttribute( 'aria-hidden' ); ta.style.position = 'static'; ta.style.left = 'auto'; ta.select(); document.execCommand( 'copy' ); show();
				}
			} );
		}() );
		</script>
		<?php
		echo '</div>';
	}
}

endif;
