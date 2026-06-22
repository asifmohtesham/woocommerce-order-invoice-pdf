// When a search term is a bare order number (optionally #-prefixed), return its
// digits so Enter can fetch that order directly; otherwise null (run a search).
export function parseOrderNumber( term ) {
	const t = String( term || '' ).trim().replace( /^#/, '' );
	return /^[0-9]+$/.test( t ) ? t : null;
}

// Compute the highlighted option index for the order-results dropdown in
// response to an arrow/Home/End keypress. `active` is the current index
// (-1 = nothing highlighted) and `count` is the number of options. ArrowDown /
// ArrowUp wrap around the ends so the list is fully traversable with the
// keyboard; any other key leaves the selection unchanged.
export function nextActiveIndex( key, active, count ) {
	if ( count <= 0 ) { return -1; }
	switch ( key ) {
		case 'ArrowDown':
			return active >= count - 1 ? 0 : active + 1;
		case 'ArrowUp':
			return active <= 0 ? count - 1 : active - 1;
		case 'Home':
			return 0;
		case 'End':
			return count - 1;
		default:
			return active;
	}
}
