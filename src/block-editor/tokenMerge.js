// INVARIANT: every token whose merged value is HTML must be listed here so the
// block edit() routes it through safeHTML before dangerouslySetInnerHTML. A new
// HTML-valued token added to the TOKENS array but omitted here renders as escaped
// text (fails safe), so keep this set in sync when adding HTML tokens.
// Tokens whose merged value is HTML (rendered via dangerouslySetInnerHTML),
// not plain text. Everything else is treated as text.
export const HTML_TOKENS = new Set( [
	'{{logo}}',
	'{{billing_address}}',
	// Shop addresses carry <br/> line breaks server-side (wp_kses_post / nl2br),
	// so they must render as HTML, not escaped text.
	'{{shop_address}}',
	'{{shop_address_ar}}',
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
