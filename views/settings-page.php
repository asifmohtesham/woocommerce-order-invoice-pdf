<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// $settings_tabs, $default_tab, $nonce are set by Settings::settings_page()
$current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : $default_tab;
if ( ! array_key_exists( $current_tab, $settings_tabs ) ) {
	$current_tab = $default_tab;
}

// Map tab key to WP Settings API page/option_group.
// Document tabs have key 'woi_pdf_{type}' → page 'woi_pdf_settings_{type}'.
// Static tabs (general, debug, edi…) → page 'woi_pdf_settings_{tab}'.
$is_upgrade = ( 'upgrade' === $current_tab );

// Static tab → option-page mapping overrides (where the class uses a non-standard name).
$tab_option_page_map = array(
	'editor' => 'woi_pdf_editor_settings',
);

if ( ! $is_upgrade ) {
	if ( isset( $tab_option_page_map[ $current_tab ] ) ) {
		$option_page = $tab_option_page_map[ $current_tab ];
	} elseif ( 0 === strpos( $current_tab, 'woi_pdf_' ) ) {
		// Document tabs: 'woi_pdf_{type}' → 'woi_pdf_documents_settings_{type}'.
		$option_page = 'woi_pdf_documents_settings_' . substr( $current_tab, strlen( 'woi_pdf_' ) );
	} else {
		// Static tabs: 'general', 'debug', 'edi', … → 'woi_pdf_settings_{tab}'.
		$option_page = 'woi_pdf_settings_' . $current_tab;
	}
}
?>
<div class="wrap wpo-wcpdf-settings-page">
	<h1><?php esc_html_e( 'PDF Invoices & Packing Slips', 'woocommerce-orders-invoice-pdf' ); ?></h1>

	<nav class="nav-tab-wrapper woo-nav-tab-wrapper">
		<?php foreach ( $settings_tabs as $tab_key => $tab ) :
			$title = is_array( $tab ) ? $tab['title'] : $tab;
		?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=woi_pdf_options_page&tab=' . rawurlencode( $tab_key ) ) ); ?>"
		   class="nav-tab <?php echo $current_tab === $tab_key ? 'nav-tab-active' : ''; ?>">
			<?php echo esc_html( $title ); ?>
		</a>
		<?php endforeach; ?>
	</nav>

	<?php do_action( 'woi_pdf_settings_output_' . $current_tab, $current_tab, $nonce ); ?>

	<?php if ( $is_upgrade ) : ?>
		<div class="wpo-wcpdf-upgrade-notice" style="margin-top:1em;">
			<p><?php esc_html_e( 'Upgrade to the Professional extension for additional features.', 'woocommerce-orders-invoice-pdf' ); ?></p>
		</div>
	<?php else : ?>
		<form method="post" action="options.php" id="woi-pdf-settings">
			<input type="hidden" name="tab" value="<?php echo esc_attr( $current_tab ); ?>">
			<?php do_action( 'woi_pdf_before_settings', $current_tab, $nonce ); ?>
			<?php settings_fields( $option_page ); ?>
			<?php do_settings_sections( $option_page ); ?>
			<?php submit_button(); ?>
		</form>
	<?php endif; ?>
</div>
