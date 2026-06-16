<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>

<?php do_action( 'woi_pdf_before_document', $this->get_type(), $this->order ); ?>

<?php if ( $this->has_letterhead() ) : ?>
<table class="head container letterhead">
	<tr><td class="header letterhead"><?php $this->letterhead(); ?></td></tr>
</table>
<?php else : ?>
<table class="head container">
	<tr>
		<td class="header">
		<?php
			if ( $this->has_header_logo() ) {
				do_action( 'woi_pdf_before_shop_logo', $this->get_type(), $this->order );
				$this->header_logo();
				do_action( 'woi_pdf_after_shop_logo', $this->get_type(), $this->order );
			} else {
				$this->title();
			}
		?>
		</td>
		<td class="shop-info">
			<?php do_action( 'woi_pdf_before_shop_name', $this->get_type(), $this->order ); ?>
			<div class="shop-name"><h3><?php $this->shop_name(); ?></h3></div>
			<?php do_action( 'woi_pdf_after_shop_name', $this->get_type(), $this->order ); ?>
			<?php do_action( 'woi_pdf_before_shop_address', $this->get_type(), $this->order ); ?>
			<div class="shop-address"><?php $this->shop_address(); ?></div>
			<?php do_action( 'woi_pdf_after_shop_address', $this->get_type(), $this->order ); ?>
		</td>
	</tr>
</table>
<?php endif; ?>

<?php do_action( 'woi_pdf_before_document_label', $this->get_type(), $this->order ); ?>

<?php if ( $this->has_header_logo() || $this->has_letterhead() ) : ?>
	<h1 class="document-type-label"><?php $this->title(); ?></h1>
<?php endif; ?>

<?php do_action( 'woi_pdf_after_document_label', $this->get_type(), $this->order ); ?>

<table class="order-data-addresses">
	<tr>
		<td class="address billing-address">
			<?php do_action( 'woi_pdf_before_billing_address', $this->get_type(), $this->order ); ?>
			<p><?php $this->billing_address(); ?></p>
			<?php do_action( 'woi_pdf_after_billing_address', $this->get_type(), $this->order ); ?>
			<?php if ( isset( $this->settings['display_email'] ) ) : ?>
				<div class="billing-email"><?php $this->billing_email(); ?></div>
			<?php endif; ?>
			<?php if ( isset( $this->settings['display_phone'] ) ) : ?>
				<div class="billing-phone"><?php $this->billing_phone(); ?></div>
			<?php endif; ?>
		</td>
		<td class="address shipping-address">
			<?php if ( $this->show_shipping_address() ) : ?>
				<h3><?php $this->shipping_address_title(); ?></h3>
				<?php do_action( 'woi_pdf_before_shipping_address', $this->get_type(), $this->order ); ?>
				<p><?php $this->shipping_address(); ?></p>
				<?php do_action( 'woi_pdf_after_shipping_address', $this->get_type(), $this->order ); ?>
				<?php if ( isset( $this->settings['display_phone'] ) ) : ?>
					<div class="shipping-phone"><?php $this->shipping_phone(); ?></div>
				<?php endif; ?>
			<?php endif; ?>
		</td>
		<td class="order-data">
			<table>
				<?php do_action( 'woi_pdf_before_order_data', $this->get_type(), $this->order ); ?>
				<?php if ( isset( $this->settings['display_number'] ) ) : ?>
					<tr class="receipt-number">
						<th><?php $this->number_title(); ?></th>
						<td><?php $this->number( $this->get_type() ); ?></td>
					</tr>
				<?php endif; ?>
				<?php if ( isset( $this->settings['display_date'] ) ) : ?>
					<tr class="receipt-date">
						<th><?php $this->date_title(); ?></th>
						<td><?php $this->date( $this->get_type() ); ?></td>
					</tr>
				<?php endif; ?>
				<?php if ( isset( $this->settings['display_invoice_number'] ) && ! empty( $this->get_number( 'invoice' ) ) ) : ?>
					<tr class="invoice-number">
						<th><?php $this->invoice_number_title(); ?></th>
						<td><?php $this->number( 'invoice' ); ?></td>
					</tr>
				<?php endif; ?>
				<?php if ( is_callable( array( $this, 'payment_date' ) ) && ! empty( $this->get_payment_date() ) ) : ?>
					<tr class="payment-date">
						<th><?php $this->payment_date_title(); ?></th>
						<td><?php $this->payment_date(); ?></td>
					</tr>
				<?php endif; ?>
				<?php if ( is_callable( array( $this, 'payment_method' ) ) && $this->get_payment_method() ) : ?>
					<tr class="payment-method">
						<th><?php $this->payment_method_title(); ?></th>
						<td><?php $this->payment_method(); ?></td>
					</tr>
				<?php endif; ?>
				<?php do_action( 'woi_pdf_after_order_data', $this->get_type(), $this->order ); ?>
			</table>			
		</td>
	</tr>
</table>

<?php do_action( 'woi_pdf_before_order_details', $this->get_type(), $this->order ); ?>

<table class="order-details">
	<?php $headers = woi_pdf_get_simple_template_default_table_headers( $this ); ?>
	<thead>
		<tr>
			<?php 
				foreach ( $headers as $column_class => $column_title ) {
					printf( '<th class="%s">%s</th>', $column_class, $column_title );
				}
			?>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $this->get_order_items() as $item_id => $item ) : ?>
			<tr class="<?php echo apply_filters( 'woi_pdf_item_row_class', 'item-' . $item_id, esc_attr( $this->get_type() ), $this->order, $item_id ); ?>">
				<td class="product">
					<p class="item-name"><?php echo $item['name']; ?></p>
					<?php do_action( 'woi_pdf_before_item_meta', $this->get_type(), $item, $this->order ); ?>
					<div class="item-meta">
						<?php if ( ! empty( $item['sku'] ) ) : ?>
							<p class="sku"><span class="label"><?php $this->sku_title(); ?></span> <?php echo esc_attr( $item['sku'] ); ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $item['weight'] ) ) : ?>
							<p class="weight"><span class="label"><?php $this->weight_title(); ?></span> <?php echo esc_attr( $item['weight'] ); ?><?php echo esc_attr( get_option( 'woocommerce_weight_unit' ) ); ?></p>
						<?php endif; ?>
						<!-- ul.wc-item-meta -->
						<?php if ( ! empty( $item['meta'] ) ) : ?>
							<?php echo $item['meta']; ?>
						<?php endif; ?>
						<!-- / ul.wc-item-meta -->
					</div>
					<?php do_action( 'woi_pdf_after_item_meta', $this->get_type(), $item, $this->order ); ?>
				</td>
				<td class="quantity"><?php echo $item['quantity']; ?></td>
				<td class="price"><?php echo $item['order_price']; ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<table class="notes-totals">
	<tbody>
		<tr class="no-borders">
			<td class="no-borders notes-cell">
				<?php do_action( 'woi_pdf_before_customer_notes', $this->get_type(), $this->order ); ?>
				<?php if ( $this->get_shipping_notes() ) : ?>
					<div class="customer-notes">
						<h3><?php $this->customer_notes_title(); ?></h3>
						<?php $this->shipping_notes(); ?>
					</div>
				<?php endif; ?>
				<?php do_action( 'woi_pdf_after_customer_notes', $this->get_type(), $this->order ); ?>
			</td>
			<td class="no-borders totals-cell">
				<table class="totals">
					<tfoot>
						<?php foreach ( $this->get_woocommerce_totals() as $key => $total ) : ?>
							<tr class="<?php echo esc_attr( $key ); ?>">
								<th class="description"><?php echo $total['label']; ?></th>
								<td class="price"><span class="totals-price"><?php echo $total['value']; ?></span></td>
							</tr>
						<?php endforeach; ?>
					</tfoot>
				</table>
			</td>
		</tr>
	</tbody>
</table>

<?php do_action( 'woi_pdf_after_order_details', $this->get_type(), $this->order ); ?>

<div class="bottom-spacer"></div>

<?php if ( $this->get_footer() ) : ?>
	<htmlpagefooter name="docFooter"><!-- required for mPDF engine -->
		<div id="footer">
			<!-- hook available: woi_pdf_before_footer -->
			<?php $this->footer(); ?>
			<!-- hook available: woi_pdf_after_footer -->
		</div>
	</htmlpagefooter><!-- required for mPDF engine -->
<?php endif; ?>

<?php do_action( 'woi_pdf_after_document', $this->get_type(), $this->order ); ?>
