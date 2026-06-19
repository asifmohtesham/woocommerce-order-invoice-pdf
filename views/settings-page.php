<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// $settings_tabs, $nonce, $current_tab, $current_section, $nav_items are set by Settings::settings_page()

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
	'home'      => 'dashicons-admin-home',
	'general'   => 'dashicons-admin-settings',
	'documents' => 'dashicons-media-document',
	'editor'    => 'dashicons-admin-customizer',
	'visual'    => 'dashicons-layout',
	'status'    => 'dashicons-info-outline',
	'debug'     => 'dashicons-admin-tools',
);

// Breadcrumb: active document (prefixed with the group label) or active main tab.
$breadcrumb = array();
foreach ( $nav_items['documents'] as $doc ) {
	if ( ! empty( $doc['active'] ) ) {
		$breadcrumb[] = __( 'Documents', 'woocommerce-orders-invoice-pdf' );
		$breadcrumb[] = $doc['label'];
		break;
	}
}
if ( empty( $breadcrumb ) ) {
	foreach ( $nav_items['tabs'] as $item ) {
		if ( ! empty( $item['active'] ) ) {
			$breadcrumb[] = $item['label'];
			break;
		}
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
				<?php if ( 3 === (int) $preview_states ) : ?>
				<button type="button" class="button woi-shell-preview-toggle" hidden title="<?php esc_attr_e( 'Show or hide the PDF preview. A red dot means the preview is out of date and will refresh when opened.', 'woocommerce-orders-invoice-pdf' ); ?>"><?php esc_html_e( 'Preview', 'woocommerce-orders-invoice-pdf' ); ?></button>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</header>

	<?php do_action( 'woi_pdf_before_settings_page', $current_tab, $nonce ); ?>

	<div class="woi-shell-body">
		<nav class="woi-shell-tabs" aria-label="<?php esc_attr_e( 'PDF Invoices settings', 'woocommerce-orders-invoice-pdf' ); ?>">
			<?php foreach ( $nav_items['tabs'] as $item ) :
				if ( ! empty( $item['href'] ) ) {
					// Tab links to a dedicated page (e.g. the full-screen Visual editor).
					$url = $item['href'];
				} else {
					$url = add_query_arg(
						array_filter( array(
							'page'    => 'woi_pdf_options_page',
							'tab'     => $item['tab'],
							'section' => $item['section'],
						) ),
						admin_url( 'admin.php' )
					);
				}
				$classes = array( 'woi-tab' );
				if ( $item['active'] ) {
					$classes[] = 'active';
				}
			?>
				<a class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" href="<?php echo esc_url( $url ); ?>" title="<?php echo esc_attr( $item['label'] ); ?>"<?php echo $item['active'] ? ' aria-current="page"' : ''; ?>>
					<span class="dashicons <?php echo esc_attr( $nav_icons[ $item['id'] ] ?? 'dashicons-media-document' ); ?>"></span>
					<span class="woi-tab-label"><?php echo esc_html( $item['label'] ); ?></span>
				</a>
			<?php endforeach; ?>
		</nav>

		<?php if ( 'documents' === $current_tab && ! empty( $nav_items['documents'] ) ) : ?>
		<nav class="woi-shell-subtabs" aria-label="<?php esc_attr_e( 'Document types', 'woocommerce-orders-invoice-pdf' ); ?>">
			<?php foreach ( $nav_items['documents'] as $doc ) :
				$url = add_query_arg(
					array(
						'page'    => 'woi_pdf_options_page',
						'tab'     => $doc['tab'],
						'section' => $doc['section'],
					),
					admin_url( 'admin.php' )
				);
				$classes = array( 'woi-subtab' );
				if ( $doc['active'] ) {
					$classes[] = 'active';
				}
				if ( empty( $doc['enabled'] ) ) {
					$classes[] = 'woi-nav-disabled-doc';
				}
			?>
				<a class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" href="<?php echo esc_url( $url ); ?>" title="<?php echo esc_attr( $doc['label'] ); ?>"<?php echo $doc['active'] ? ' aria-current="page"' : ''; ?>>
					<span class="woi-nav-dot" aria-hidden="true"></span>
					<span class="woi-subtab-label"><?php echo esc_html( $doc['label'] ); ?></span>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php endif; ?>

		<?php /* WP relocates floating admin notices to just after this marker. Placed below the nav
		         rows so the sticky dark header + tab bar stay flush at the top, with notices above the content. */ ?>
		<hr class="wp-header-end">

		<main class="woi-shell-content">
		<?php if ( in_array( $current_tab, apply_filters( 'woi_pdf_fullwidth_tabs', array( 'home' ) ), true ) ) : ?>

			<?php do_action( "woi_pdf_settings_output_{$current_tab}", $current_section, $nonce ); ?>

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
						<div class="preview-data preview-download">
							<button type="button" class="button woi-preview-download" disabled
								title="<?php esc_attr_e( 'Download the preview as a watermarked sample PDF.', 'woocommerce-orders-invoice-pdf' ); ?>">
								<?php esc_html_e( 'Download', 'woocommerce-orders-invoice-pdf' ); ?>
							</button>
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
