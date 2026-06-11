<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// $settings_tabs, $default_tab, $nonce, $current_tab, $current_section, $nav_items are set by Settings::settings_page()

// Map tab key to WP Settings API page/option_group.
// The Documents tab fires woi_pdf_settings_output_documents and handles its own option page internally.
// All other tabs map to 'woi_pdf_settings_{tab}' (or an override in $tab_option_page_map).
$tab_option_page_map = array(
	'editor' => 'woi_pdf_editor_settings',
);

if ( isset( $tab_option_page_map[ $current_tab ] ) ) {
	$option_page = $tab_option_page_map[ $current_tab ];
} else {
	$option_page = 'woi_pdf_settings_' . $current_tab;
}

$preview_states      = isset( $settings_tabs[ $current_tab ]['preview_states'] ) ? $settings_tabs[ $current_tab ]['preview_states'] : 1;
$preview_states_lock = ( 3 === (int) $preview_states ) ? false : true;
$preview_document_type = ( 'documents' === $current_tab && ! empty( $current_section ) ) ? $current_section : 'invoice';

$nav_icons = array(
	'home'    => 'dashicons-admin-home',
	'general' => 'dashicons-admin-settings',
	'editor'  => 'dashicons-admin-customizer',
	'debug'   => 'dashicons-admin-tools',
);

// Breadcrumb: active nav item label (documents get the group label prefix).
$breadcrumb = array();
foreach ( $nav_items as $item ) {
	if ( ! empty( $item['active'] ) ) {
		if ( 'document' === $item['kind'] ) {
			$breadcrumb[] = __( 'Documents', 'woocommerce-orders-invoice-pdf' );
		}
		$breadcrumb[] = $item['label'];
		break;
	}
}
?>
<div class="wrap woi-pdf-settings-page woi-pdf-shell">
	<h1 class="screen-reader-text"><?php esc_html_e( 'PDF Invoices & Packing Slips', 'woocommerce-orders-invoice-pdf' ); ?></h1>

	<header class="woi-shell-header">
		<div class="woi-shell-title">
			<strong><?php esc_html_e( 'PDF Invoices', 'woocommerce-orders-invoice-pdf' ); ?></strong>
			<?php foreach ( $breadcrumb as $crumb ) : ?>
				<span class="woi-shell-crumb">&rsaquo; <?php echo esc_html( $crumb ); ?></span>
			<?php endforeach; ?>
			<span class="woi-shell-dirty" hidden><?php esc_html_e( 'Unsaved changes', 'woocommerce-orders-invoice-pdf' ); ?></span>
		</div>
		<div class="woi-shell-actions">
			<?php if ( in_array( $current_tab, apply_filters( 'woi_pdf_searchable_tabs', array( 'general', 'documents', 'debug' ) ), true ) ) : ?>
				<div class="settings-search">
					<input type="text" name="settings-search" id="wpo-settings-search" placeholder="<?php esc_attr_e( 'Search settings', 'woocommerce-orders-invoice-pdf' ); ?>">
				</div>
			<?php endif; ?>
			<?php if ( 'home' !== $current_tab ) : ?>
				<button type="button" class="button button-primary woi-shell-save" hidden><?php esc_html_e( 'Save', 'woocommerce-orders-invoice-pdf' ); ?></button>
				<button type="button" class="button woi-shell-preview-toggle" hidden><?php esc_html_e( 'Preview', 'woocommerce-orders-invoice-pdf' ); ?></button>
			<?php endif; ?>
		</div>
	</header>

	<?php do_action( 'woi_pdf_before_settings_page', $current_tab, $nonce ); ?>

	<div class="woi-shell-body">
		<nav class="woi-shell-nav" aria-label="<?php esc_attr_e( 'PDF Invoices settings', 'woocommerce-orders-invoice-pdf' ); ?>">
			<ul>
				<?php foreach ( $nav_items as $item ) : ?>
					<?php if ( 'heading' === $item['kind'] ) : ?>
						<li class="woi-nav-heading"><?php echo esc_html( $item['label'] ); ?></li>
					<?php else :
						$url = add_query_arg(
							array_filter( array(
								'page'    => 'woi_pdf_options_page',
								'tab'     => $item['tab'],
								'section' => $item['section'],
							) ),
							admin_url( 'admin.php' )
						);
						$classes = array( 'woi-nav-item', 'woi-nav-' . $item['kind'] );
						if ( $item['active'] ) {
							$classes[] = 'active';
						}
						if ( 'document' === $item['kind'] && empty( $item['enabled'] ) ) {
							$classes[] = 'woi-nav-disabled-doc';
						}
					?>
						<li class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
							<a href="<?php echo esc_url( $url ); ?>" title="<?php echo esc_attr( $item['label'] ); ?>">
								<?php if ( 'tab' === $item['kind'] ) : ?>
									<span class="dashicons <?php echo esc_attr( $nav_icons[ $item['id'] ] ?? 'dashicons-media-document' ); ?>"></span>
								<?php else : ?>
									<span class="woi-nav-dot" aria-hidden="true"></span>
								<?php endif; ?>
								<span class="woi-nav-label"><?php echo esc_html( $item['label'] ); ?></span>
							</a>
						</li>
					<?php endif; ?>
				<?php endforeach; ?>
			</ul>
		</nav>

		<main class="woi-shell-content">
		<?php if ( 'home' === $current_tab ) : ?>

			<?php do_action( 'woi_pdf_settings_output_home', $current_section, $nonce ); ?>

		<?php else : ?>

			<div id="woi-pdf-preview-wrapper"
				class="<?php echo esc_attr( $current_tab ); ?>"
				data-preview-states="<?php echo esc_attr( $preview_states ); ?>"
				data-preview-state="closed"
				data-from-preview-state=""
				data-preview-states-lock="<?php echo esc_attr( $preview_states_lock ); ?>">

				<div class="sidebar">
					<form method="post" action="options.php" id="woi-pdf-settings" class="<?php echo esc_attr( $current_tab ); ?>">
						<input type="hidden" name="tab" value="<?php echo esc_attr( $current_tab ); ?>">
						<?php
						do_action( 'woi_pdf_before_settings', $current_tab, $nonce );
						if ( has_action( "woi_pdf_settings_output_{$current_tab}" ) ) {
							do_action( "woi_pdf_settings_output_{$current_tab}", $current_section, $nonce );
						} else {
							settings_fields( $option_page );
							do_settings_sections( $option_page );
							submit_button();
						}
						?>
					</form>
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

		<?php endif; ?>
		</main>
	</div>

	<?php do_action( 'woi_pdf_after_settings_page', $current_tab, $nonce ); ?>
</div>
