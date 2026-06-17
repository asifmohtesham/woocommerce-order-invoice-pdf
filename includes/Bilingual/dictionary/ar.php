<?php
// Seed Arabic translations for fixed labels. User overrides (saved settings)
// take precedence at runtime; blanks fall back to these values.
if ( ! defined( 'ABSPATH' ) ) exit;

return array(
	// document labels (keys mirror OrderDocument::get_title_for slugs)
	'document'          => 'فاتورة ضريبية',
	'document_number'   => 'رقم الفاتورة',
	'document_date'     => 'التاريخ',
	'document_due_date' => 'تاريخ الاستحقاق',
	'billing_address'   => 'المشترى',
	'shipping_address'  => 'عنوان الشحن',
	'order_number'      => 'رقم المرجع',
	'order_date'        => 'تاريخ الطلب',
	// item-table column types
	'sku'               => 'رقم القطعة',
	'description'       => 'البيان الصنف',
	'quantity'          => 'الكمية',
	'price'             => 'المبلغ',
	'tax_rate'          => 'معدل الضريبة %',
	'weight'            => 'الوزن',
	// totals types
	'subtotal'          => 'المجموع الفرعي',
	'discount'          => 'الخصم',
	'shipping'          => 'الشحن',
	'fees'              => 'رسوم',
	'vat'               => 'ضريبة القيمة المضافة',
	'total'             => 'المجموع',
);
