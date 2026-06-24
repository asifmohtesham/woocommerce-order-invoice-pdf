import { __ } from '@wordpress/i18n';

export const NAMING_TYPES = [
	{ value: 'invoice', label: __( 'Invoice', 'woocommerce-orders-invoice-pdf' ), hasSeries: true },
	{ value: 'proforma', label: __( 'Proforma', 'woocommerce-orders-invoice-pdf' ), hasSeries: true },
	{ value: 'credit-note', label: __( 'Credit note', 'woocommerce-orders-invoice-pdf' ), hasSeries: true },
	{ value: 'receipt', label: __( 'Receipt', 'woocommerce-orders-invoice-pdf' ), hasSeries: true },
	{ value: 'packing-slip', label: __( 'Packing slip', 'woocommerce-orders-invoice-pdf' ), hasSeries: false },
];

export const FILENAME_TOKENS = [
	'{document_type}',
	'{order_number}',
	'{document_number}',
	'{document_number_sequence}',
	'{date}',
];

export function hasSeries( type ) {
	const found = NAMING_TYPES.find( ( t ) => t.value === type );
	return !! ( found && found.hasSeries );
}

export function buildNamingPayload( type, state ) {
	const payload = {
		type,
		filename_template: state.filename_template || '',
	};
	if ( hasSeries( type ) ) {
		payload.prefix = state.prefix || '';
		payload.suffix = state.suffix || '';
		payload.padding = state.padding ?? '';
		payload.reset_number_yearly = !! state.reset_number_yearly;
		payload.next_number = state.next_number;
	}
	return payload;
}
