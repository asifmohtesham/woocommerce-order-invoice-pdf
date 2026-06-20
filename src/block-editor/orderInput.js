// When a search term is a bare order number (optionally #-prefixed), return its
// digits so Enter can fetch that order directly; otherwise null (run a search).
export function parseOrderNumber( term ) {
	const t = String( term || '' ).trim().replace( /^#/, '' );
	return /^[0-9]+$/.test( t ) ? t : null;
}
