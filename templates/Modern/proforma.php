<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>

<?php do_action( 'woi_pdf_before_document', $this->get_type(), $this->order ); ?>

<?php if ( $this->has_letterhead() ) : ?>
<table class="head container letterhead">
	<tr><td class="header letterhead"><?php $this->letterhead(); ?></td></tr>
</table>
<?php else : ?>
<table class="head container">
	<tr class="underline">
		<td class="header">
			<div class="header-stretcher">
				<?php if ( $this->has_header_logo() ) : ?>
					<?php do_action( 'woi_pdf_before_shop_logo', $this->get_type(), $this->order ); ?>
					<?php $this->header_logo(); ?>
					<?php do_action( 'woi_pdf_after_shop_logo', $this->get_type(), $this->order ); ?>
				<?php endif; ?>
			</div>
		</td>
		<td class="shop-info">
			<?php do_action( 'woi_pdf_before_shop_name', $this->get_type(), $this->order ); ?>
			<?php $this->bilingual_shop_name_slot( true ); ?>
			<?php do_action( 'woi_pdf_after_shop_name', $this->get_type(), $this->order ); ?>
			<?php do_action( 'woi_pdf_before_shop_address', $this->get_type(), $this->order ); ?>
			<?php $this->bilingual_shop_address_slot( true ); ?>
			<?php do_action( 'woi_pdf_after_shop_address', $this->get_type(), $this->order ); ?>
			<?php do_action( 'woi_pdf_before_shop_phone_number', $this->get_type(), $this->order ); ?>
			<?php if ( ! empty( $this->get_shop_phone_number() ) ) : ?>
				<div class="shop-phone-number"><?php $this->shop_phone_number(); ?></div>
			<?php endif; ?>
			<?php do_action( 'woi_pdf_after_shop_phone_number', $this->get_type(), $this->order ); ?>
			<?php if ( ! empty( $this->get_shop_email_address() ) ) : ?>
				<div class="shop-email-address"><?php $this->shop_email_address(); ?></div>
			<?php endif; ?>
			<?php do_action( 'woi_pdf_after_shop_email_address', $this->get_type(), $this->order ); ?>
		</td>
	</tr>
</table>
<?php endif; ?>

<table class="order-data-addresses">
	<tr>
		<td class="address billing-address">
			<h3>&nbsp;<!-- empty spacer to keep adjecent cell content aligned --></h3>
			<?php do_action( 'woi_pdf_before_billing_address', $this->get_type(), $this->order ); ?>
			<?php $this->bilingual_address_block( 'billing' ); ?>
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
				<?php $this->bilingual_address_block( 'shipping' ); ?>
				<?php do_action( 'woi_pdf_after_shipping_address', $this->get_type(), $this->order ); ?>
				<?php if ( isset( $this->settings['display_phone'] ) ) : ?>
					<div class="shipping-phone"><?php $this->shipping_phone(); ?></div>
				<?php endif; ?>
			<?php endif; ?>
		</td>
		<td class="order-data">
			<?php do_action( 'woi_pdf_before_document_label', $this->get_type(), $this->order ); ?>
			<h3 class="document-type-label"><?php $this->title(); ?></h3>
			<?php do_action( 'woi_pdf_after_document_label', $this->get_type(), $this->order ); ?>
			<table>
				<?php do_action( 'woi_pdf_before_order_data', $this->get_type(), $this->order ); ?>
				<?php if ( isset( $this->settings['display_number'] ) ) : ?>
					<tr class="proforma-number">
						<th><?php $this->number_title(); ?></th>
						<td><?php $this->number( $this->get_type() ); ?></td>
					</tr>
				<?php endif; ?>
				<?php if ( isset( $this->settings['display_date'] ) ) : ?>
					<tr class="proforma-date">
						<th><?php $this->date_title(); ?></th>
						<td><?php $this->date( $this->get_type() ); ?></td>
					</tr>
				<?php endif; ?>
				<tr class="order-number">
					<th><?php $this->order_number_title(); ?></th>
					<td><?php $this->order_number(); ?></td>
				</tr>
				<tr class="order-date">
					<th><?php $this->order_date_title(); ?></th>
					<td><?php $this->order_date(); ?></td>
				</tr>
				<?php do_action( 'woi_pdf_after_order_data', $this->get_type(), $this->order ); ?>
			</table>
		</td>
	</tr>
</table>

<?php do_action( 'woi_pdf_before_order_details', $this->get_type(), $this->order ); ?>

<table class="order-details">
	<thead>
		<tr>
			<?php foreach ( woi_pdf_templates_get_table_headers( $this ) as $column_key => $header_data ) : ?>
				<th class="<?php echo esc_attr( $header_data['class'] ); ?>"<?php echo woi_pdf_templates_maybe_apply_column_styles( $header_data, 'header' ); ?>><?php
					echo esc_html( $header_data['title'] );
					if ( ! empty( $header_data['secondary'] ) ) {
						echo '<span class="woi-lbl-secondary" dir="rtl">' . esc_html( $header_data['secondary'] ) . '</span>';
					}
				?></th>
			<?php endforeach; ?>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( woi_pdf_templates_get_table_body( $this ) as $item_id => $item_columns ) : ?>
			<?php do_action( 'woi_pdf_templates_before_order_details_row', $this, $item_id, $item_columns ); ?>
			<?php $row_class = apply_filters( 'woi_pdf_item_row_class', "item-{$item_id}", $this->get_type(), $this->order, $item_id ); ?>
			<tr class="<?php echo esc_attr( $row_class ) ?>">
				<?php foreach ( $item_columns as $column_key => $column_data ) : ?>
					<td class="<?php echo esc_attr( $column_data['class'] ); ?>"<?php echo woi_pdf_templates_maybe_apply_column_styles( $column_data, 'cells' ); ?>><span><?php echo esc_html( $column_data['data'] ); ?></span></td>
				<?php endforeach; ?>
			</tr>
			<?php do_action( 'woi_pdf_templates_after_order_details_row', $this, $item_id, $item_columns ); ?>
		<?php endforeach; ?>
	</tbody>
</table>

<div class="bottom-spacer"></div>

<?php do_action( 'woi_pdf_after_order_details', $this->get_type(), $this->order ); ?>

<?php do_action( 'woi_pdf_before_customer_notes', $this->get_type(), $this->order ); ?>
<?php if ( $this->get_shipping_notes() ) : ?>
	<div class="notes customer-notes">
		<h3><?php $this->customer_notes_title(); ?></h3>
		<?php $this->shipping_notes(); ?>
	</div>
<?php endif; ?>
<?php do_action( 'woi_pdf_after_customer_notes', $this->get_type(), $this->order ); ?>

<div class="cut-off"></div>

<htmlpagefooter name="docFooter"><!-- required for mPDF engine -->
	<div class="foot">
		<table class="footer container">
			<tr>
				<td>&nbsp;</td>
				<td>&nbsp;</td>
				<td>
					<table class="totals">
						<tfoot>
							<?php foreach ( woi_pdf_templates_get_totals( $this ) as $total_key => $total_data ) : ?>
								<tr class="<?php echo esc_attr( $total_data['class'] ); ?>">
									<th class="description"><span><?php
										echo esc_html( $total_data['label'] );
										if ( ! empty( $total_data['secondary'] ) ) {
											echo '<span class="woi-lbl-secondary" dir="rtl">' . esc_html( $total_data['secondary'] ) . '</span>';
										}
									?></span></th>
									<td class="price"><span class="totals-price"><?php echo esc_html( $total_data['value'] ); ?></span></td>
								</tr>
							<?php endforeach; ?>
						</tfoot>
					</table>
				</td>
			</tr>
			<tr>
				<td colspan="3" class="bluebox">
					<?php do_action( 'woi_pdf_before_footer_bar', $this->get_type(), $this->order ); ?>
					<?php if ( ! empty( $this->get_shipping_method() ) ) : ?>			
						<div class="shipping-method">
							<span class="shipping-method-label"><?php $this->shipping_method_title(); ?>:</span>
							<span class="shipping-method-name"><?php $this->shipping_method(); ?></span>
						</div>
					<?php endif; ?>	
					<?php if ( ! empty( $this->get_payment_method() ) ) : ?>
						<div class="payment-method">
							<span class="payment-method-label"><?php $this->payment_method_title(); ?>:</span>
							<span class="payment-method-name"><?php $this->payment_method(); ?></span>
						</div>
					<?php endif; ?>	
					<?php do_action( 'woi_pdf_after_footer_bar', $this->get_type(), $this->order ); ?>
				</td>
			</tr>
			<?php do_action( 'woi_pdf_before_extra_fields', $this->get_type(), $this->order ); ?>
			<tr>
				<td class="footer-column-1">
					<div class="wrapper"><?php $this->extra_1(); ?></div>
				</td>
				<td class="footer-column-2">
					<div class="wrapper"><?php $this->extra_2(); ?></div>
				</td>
				<td class="footer-column-3">
					<div class="wrapper"><?php $this->extra_3(); ?></div>
				</td>
			</tr>
			<?php do_action( 'woi_pdf_after_extra_fields', $this->get_type(), $this->order ); ?>
			<tr>
				<td colspan="3" class="footer-wide-row">
					<!-- hook available: woi_pdf_before_footer -->
					<?php $this->footer(); ?>
					<!-- hook available: woi_pdf_after_footer -->
				</td>
			</tr>
		</table>
	</div>
</htmlpagefooter><!-- required for mPDF engine -->

<?php do_action( 'woi_pdf_after_document', $this->get_type(), $this->order ); ?>