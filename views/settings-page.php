<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// $settings_tabs, $default_tab, $nonce are set by Settings::settings_page()
$current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : $default_tab;
if ( ! array_key_exists( $current_tab, $settings_tabs ) ) {
	$current_tab = $default_tab;
}

// Map tab key to WP Settings API page/option_group.
// The Documents tab fires woi_pdf_settings_output_documents and handles its own option page internally.
// All other tabs map to 'woi_pdf_settings_{tab}' (or an override in $tab_option_page_map).
$is_upgrade = ( 'upgrade' === $current_tab );

// Static tab → option-page mapping overrides (where the class uses a non-standard name).
$tab_option_page_map = array(
	'editor' => 'woi_pdf_editor_settings',
);

if ( ! $is_upgrade ) {
	if ( isset( $tab_option_page_map[ $current_tab ] ) ) {
		$option_page = $tab_option_page_map[ $current_tab ];
	} else {
		$option_page = 'woi_pdf_settings_' . $current_tab;
	}
}
?>
<?php
$preview_states      = isset( $settings_tabs[ $current_tab ]['preview_states'] ) ? $settings_tabs[ $current_tab ]['preview_states'] : 1;
$preview_states_lock = ( 3 === (int) $preview_states ) ? false : true;
if ( 'documents' === $current_tab && ! empty( $_GET['section'] ) ) {
	$preview_document_type = sanitize_key( wp_unslash( $_GET['section'] ) );
} else {
	$preview_document_type = 'invoice';
}
?>
<div class="wrap woi-pdf-settings-page">
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

	<?php do_action( 'woi_pdf_before_settings_page', $current_tab, $nonce ); ?>

	<div id="woi-pdf-preview-wrapper"
		class="<?php echo esc_attr( $current_tab ); ?>"
		data-preview-states="<?php echo esc_attr( $preview_states ); ?>"
		data-preview-state="closed"
		data-from-preview-state=""
		data-preview-states-lock="<?php echo esc_attr( $preview_states_lock ); ?>">

		<div class="sidebar">
			<?php if ( $is_upgrade ) : ?>
				<div class="woi-pdf-upgrade-notice" style="margin-top:1em;">
					<p><?php esc_html_e( 'Upgrade to the Professional extension for additional features.', 'woocommerce-orders-invoice-pdf' ); ?></p>
				</div>
			<?php else : ?>
				<form method="post" action="options.php" id="woi-pdf-settings" class="<?php echo esc_attr( $current_tab ); ?>">
					<input type="hidden" name="tab" value="<?php echo esc_attr( $current_tab ); ?>">
					<?php
					do_action( 'woi_pdf_before_settings', $current_tab, $nonce );
					$current_section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
					if ( has_action( "woi_pdf_settings_output_{$current_tab}" ) ) {
						do_action( "woi_pdf_settings_output_{$current_tab}", $current_section, $nonce );
					} else {
						settings_fields( $option_page );
						do_settings_sections( $option_page );
						submit_button();
					}
					?>
				</form>
			<?php endif; ?>
		</div>

		<div class="gutter">
			<div class="slider slide-left"><span class="gutter-arrow arrow-left"></span></div>
			<div class="slider slide-right"><span class="gutter-arrow arrow-right"></span></div>
		</div>

		<div class="preview-document">
			<div class="preview-data-wrapper">
				<div class="save-settings"><?php submit_button(); ?></div>
				<div class="preview-data preview-order-data">
					<div class="preview-order-search-wrapper">
						<input type="text" name="preview-order-search" id="preview-order-search"
							placeholder="<?php esc_attr_e( 'ID, email or name', 'woocommerce-orders-invoice-pdf' ); ?>"
							data-nonce="<?php echo esc_attr( wp_create_nonce( 'woi_pdf_preview' ) ); ?>">
					</div>
					<p class="last-order"><?php esc_html_e( 'Currently showing last order', 'woocommerce-orders-invoice-pdf' ); ?><span class="arrow-down">&#9660;</span></p>
					<p class="order-search"><span class="order-search-label"><?php esc_html_e( 'Search for an order', 'woocommerce-orders-invoice-pdf' ); ?></span><span class="arrow-down">&#9660;</span></p>
					<ul>
						<li class="last-order"><?php esc_html_e( 'Show last order', 'woocommerce-orders-invoice-pdf' ); ?></li>
						<li class="order-search"><?php esc_html_e( 'Search for an order', 'woocommerce-orders-invoice-pdf' ); ?></li>
					</ul>
					<div id="preview-order-search-results"></div>
				</div>
				<?php $picker_documents = WOI_PDF()->documents->get_documents( 'enabled', 'any' ); ?>
				<div class="preview-data preview-document-type">
					<p class="current"><span class="current-label"><?php esc_html_e( 'Invoice', 'woocommerce-orders-invoice-pdf' ); ?></span><span class="arrow-down">&#9660;</span></p>
					<ul class="preview-data-option-list" data-input-name="document_type">
						<?php foreach ( $picker_documents as $doc ) : ?>
							<li data-value="<?php echo esc_attr( $doc->get_type() ); ?>"><?php echo esc_html( $doc->get_title() ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			</div>
			<input type="hidden" name="document_type" data-default="<?php echo esc_attr( $preview_document_type ); ?>" value="<?php echo esc_attr( $preview_document_type ); ?>">
			<input type="hidden" name="output_format" value="pdf">
			<input type="hidden" name="order_id" value="">
			<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'woi_pdf_preview' ) ); ?>">
			<div class="preview"></div>
		</div>

	</div>

	<?php do_action( 'woi_pdf_after_settings_page', $current_tab, $nonce ); ?>
</div>
