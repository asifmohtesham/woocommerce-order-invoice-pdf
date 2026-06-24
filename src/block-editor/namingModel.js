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

// Prefix/suffix placeholders resolved by woi_pdf_format_document_number. The
// slug-based set uses the doc type with hyphens -> underscores, matching
// OrderDocument::$slug (e.g. credit-note -> credit_note).
export function prefixTokens( type ) {
	const slug = String( type || '' ).replace( /-/g, '_' );
	return [
		{ token: '[order_year]', label: __( 'Order year', 'woocommerce-orders-invoice-pdf' ) },
		{ token: '[order_month]', label: __( 'Order month', 'woocommerce-orders-invoice-pdf' ) },
		{ token: '[order_day]', label: __( 'Order day', 'woocommerce-orders-invoice-pdf' ) },
		{ token: '[order_number]', label: __( 'Order #', 'woocommerce-orders-invoice-pdf' ) },
		{ token: `[${ slug }_year]`, label: __( 'Doc year', 'woocommerce-orders-invoice-pdf' ) },
		{ token: `[${ slug }_month]`, label: __( 'Doc month', 'woocommerce-orders-invoice-pdf' ) },
		{ token: `[${ slug }_day]`, label: __( 'Doc day', 'woocommerce-orders-invoice-pdf' ) },
	];
}

// Filename {...} tokens as {token,label} chips for TokenField (the raw strings
// remain available as FILENAME_TOKENS for the help text).
export function filenameTokenChips() {
	const labels = {
		'{document_type}': __( 'Type', 'woocommerce-orders-invoice-pdf' ),
		'{order_number}': __( 'Order #', 'woocommerce-orders-invoice-pdf' ),
		'{document_number}': __( 'Number', 'woocommerce-orders-invoice-pdf' ),
		'{document_number_sequence}': __( 'Sequence', 'woocommerce-orders-invoice-pdf' ),
		'{date}': __( 'Date', 'woocommerce-orders-invoice-pdf' ),
	};
	return FILENAME_TOKENS.map( ( token ) => ( { token, label: labels[ token ] || token } ) );
}
