// GET the selected order's token map (read-only; falls back to null on error).
export function fetchOrderTokens( orderId ) {
	const w = window.woiBlocks || {};
	let url = w.previewDataUrl + '?doc_type=' + encodeURIComponent( w.docType );
	if ( orderId ) {
		url += '&order_id=' + encodeURIComponent( orderId );
	}
	return fetch( url, {
		headers: { 'X-WP-Nonce': w.nonce },
		credentials: 'same-origin',
		cache: 'no-store',
	} )
		.then( ( r ) => ( r.ok ? r.json() : null ) )
		.catch( () => null );
}

// POST the order-search admin-ajax action; returns the { id: data } map or {}.
export function fetchOrders( term ) {
	const w = window.woiBlocks || {};
	const body =
		'action=' +
		encodeURIComponent( w.orderSearchAction ) +
		'&security=' +
		encodeURIComponent( w.previewNonce ) +
		'&document_type=' +
		encodeURIComponent( w.docType ) +
		'&search=' +
		encodeURIComponent( term || '' );
	return fetch( w.ajaxUrl, {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		credentials: 'same-origin',
		body,
	} )
		.then( ( r ) => r.json() )
		.then( ( res ) => ( res && res.success && res.data ? res.data : {} ) )
		.catch( () => ( {} ) );
}

export function orderRowTitle( d ) {
	let name = ( d.billing_company || '' ).trim();
	if ( ! name ) {
		name = (
			( d.billing_first_name || '' ) +
			' ' +
			( d.billing_last_name || '' )
		).trim();
	}
	if ( ! name ) {
		name = '(no name)';
	}
	return '#' + ( d.order_number || '' ) + ' — ' + name;
}

// Secondary line for an order row: "AED 100 · 3 items / 5 units · 18 Jun · Credit Card".
// total_raw is wc_price HTML and must be rendered separately (innerHTML); this
// helper returns only the plain-text remainder.
export function orderMetaLine( d ) {
	const parts = [];
	const items = parseInt( d.line_count, 10 ) || 0;
	const units = parseInt( d.unit_count, 10 ) || 0;
	parts.push(
		items +
			( 1 === items ? ' item' : ' items' ) +
			' / ' +
			units +
			( 1 === units ? ' unit' : ' units' )
	);
	if ( d.date_raw ) {
		parts.push( d.date_raw );
	}
	if ( d.payment_method ) {
		parts.push( d.payment_method );
	}
	return parts.join( ' · ' );
}
