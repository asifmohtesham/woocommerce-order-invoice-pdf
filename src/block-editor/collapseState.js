/**
 * Collapse state for the line-item column tiles.
 *
 * State is a Set of *collapsed* column indices. Every transform is pure and
 * returns a new Set, mirroring the matching column-array mutation in
 * ColumnEditor so the index keys stay aligned (move ⇄ swap, remove ⇄ filter,
 * add ⇄ append). Collapse state is ephemeral — it is never persisted.
 */

/** Every index 0..len-1 collapsed (the initial all-collapsed state). */
export function allCollapsed( len ) {
	const set = new Set();
	for ( let i = 0; i < len; i++ ) { set.add( i ); }
	return set;
}

/** Flip the collapsed state of index i. */
export function toggle( set, i ) {
	const next = new Set( set );
	if ( next.has( i ) ) { next.delete( i ); } else { next.add( i ); }
	return next;
}

/** Swap the collapsed membership of indices i and j (mirrors a column swap). */
export function move( set, i, j ) {
	const next = new Set( set );
	const iHad = next.has( i );
	const jHad = next.has( j );
	if ( jHad ) { next.add( i ); } else { next.delete( i ); }
	if ( iHad ) { next.add( j ); } else { next.delete( j ); }
	return next;
}

/** Drop index i and shift every higher index down by one (mirrors filter removal). */
export function remove( set, i ) {
	const next = new Set();
	set.forEach( ( idx ) => {
		if ( idx < i ) { next.add( idx ); }
		else if ( idx > i ) { next.add( idx - 1 ); }
	} );
	return next;
}

/** A newly appended column renders expanded, so the set is unchanged. */
export function add( set ) {
	return new Set( set );
}

/** True when all `len` columns are collapsed (drives the global toggle label). */
export function isAllCollapsed( set, len ) {
	return len > 0 && set.size === len;
}
