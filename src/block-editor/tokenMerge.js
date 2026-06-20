// Tokens whose merged value is HTML (rendered via dangerouslySetInnerHTML),
// not plain text. Everything else is treated as text.
export const HTML_TOKENS = new Set( [
	'{{logo}}',
	'{{billing_address}}',
	'{{line_items}}',
	'{{totals}}',
] );

export function isHtmlToken( token ) {
	return HTML_TOKENS.has( token );
}

// The merged string for one token; '' when absent or the map is null/undefined.
export function tokenValue( token, tokens ) {
	const map = tokens || {};
	if ( ! Object.prototype.hasOwnProperty.call( map, token ) ) {
		return '';
	}
	const raw = map[ token ];
	return ( null === raw || undefined === raw ) ? '' : String( raw );
}
