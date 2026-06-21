// Optimistic client-side patch of the server-rendered {{line_items}} table for
// in-place column edits (title / width / align). The server save is slow on the
// live host (~2s per write), so we apply these visual edits to the current HTML
// instantly and let the background save re-confirm. Index-based mapping (editor
// column N ↔ table column N) — only valid when the column COUNT and ORDER are
// unchanged, so callers use this for value edits, not add/remove/reorder.
// Field-key edits change the DATA source and can't be derived client-side, so
// they're left to the server render.
export function patchLineItems( html, columns ) {
	if ( ! html || 'undefined' === typeof window.DOMParser ) { return html; }
	try {
		const doc = new window.DOMParser().parseFromString( html, 'text/html' );
		const table = doc.querySelector( 'table.order-details' );
		if ( ! table ) { return html; }
		const ths = table.querySelectorAll( 'thead th' );
		// Structure changed (add/remove/reorder) — let the server render it.
		if ( ths.length !== columns.length ) { return html; }
		const rows = table.querySelectorAll( 'tbody tr' );

		columns.forEach( ( c, i ) => {
			const th = ths[ i ];
			if ( ! th ) { return; }
			// Title: only set a non-empty label (empty → default, server-computed).
			if ( c.label ) {
				const prim = th.querySelector( '.woi-lbl-primary' );
				if ( prim ) { prim.textContent = c.label; }
			}
			// Width / alignment as an inline style on the header + each body cell.
			const parts = [];
			if ( c.width ) { parts.push( 'width:' + c.width + '%' ); }
			if ( c.align ) { parts.push( 'text-align:' + c.align ); }
			const style = parts.join( ';' );
			th.setAttribute( 'style', style );
			rows.forEach( ( tr ) => {
				const td = tr.children[ i ];
				if ( td ) { td.setAttribute( 'style', style ); }
			} );
		} );

		return table.outerHTML;
	} catch ( e ) {
		return html;
	}
}
