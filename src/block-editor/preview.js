import { serialize } from '@wordpress/blocks';

// Preview-only shim layered on top of the shared visual-document CSS. The iframe
// is a scrolling document, not paged media: simulate the 15mm page margin and
// centre an A4-width "page"; show page breaks as a dashed divider. (Ported from
// the GrapesJS editor's PREVIEW_SHIM_CSS so both previews look identical.)
const SHIM_CSS =
	'body{width:210mm;max-width:100%;margin:0 auto !important;padding:15mm;box-sizing:border-box;background:#fff}' +
	'.woi-pagebreak{border-top:1px dashed #999;margin:4mm 0;height:auto;page-break-after:auto}';
const FALLBACK_CSS =
	'table{border-collapse:collapse;width:100%}' +
	'.order-details th,.order-details td{border:0.5pt solid #000;padding:0.375em}' +
	'.woi-lbl-primary,.woi-lbl-secondary{display:inline}.woi-lbl-secondary{direction:rtl}';

// Our blocks are static, so serialize() emits the save() HTML wrapped in WP
// block-delimiter comments; stripping the comments yields the rendered HTML
// carrying the {{tokens}} — the block editor's equivalent of getHtml().
export function renderedHtmlFromBlocks( blocks ) {
	return serialize( blocks || [] ).replace( /<!--\s*\/?wp:[\s\S]*?-->/g, '' );
}

export function mergeTokens( html, tokens ) {
	let out = html;
	if ( tokens ) {
		Object.keys( tokens ).forEach( ( k ) => { out = out.split( k ).join( tokens[ k ] ); } );
	}
	return out.replace( /\{\{\s*[a-z0-9_]+\s*\}\}/gi, '' );
}

export function wrapForPreview( bodyHtml ) {
	const docCss = ( window.woiBlocks && window.woiBlocks.previewCss ) ? window.woiBlocks.previewCss : FALLBACK_CSS;
	return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>' + docCss + SHIM_CSS + '</style></head><body>' + ( bodyHtml || '' ) + '</body></html>';
}

// GET the selected order's token map (read-only; falls back to null on error).
export function fetchOrderTokens( orderId ) {
	const w = window.woiBlocks || {};
	let url = w.previewDataUrl + '?doc_type=' + encodeURIComponent( w.docType );
	if ( orderId ) { url += '&order_id=' + encodeURIComponent( orderId ); }
	return fetch( url, { headers: { 'X-WP-Nonce': w.nonce }, credentials: 'same-origin', cache: 'no-store' } )
		.then( ( r ) => ( r.ok ? r.json() : null ) )
		.catch( () => null );
}

// POST the order-search admin-ajax action; returns the { id: data } map or {}.
export function fetchOrders( term ) {
	const w = window.woiBlocks || {};
	const body = 'action=' + encodeURIComponent( w.orderSearchAction ) +
		'&security=' + encodeURIComponent( w.previewNonce ) +
		'&document_type=' + encodeURIComponent( w.docType ) +
		'&search=' + encodeURIComponent( term || '' );
	return fetch( w.ajaxUrl, {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		credentials: 'same-origin',
		body,
	} ).then( ( r ) => r.json() )
		.then( ( res ) => ( res && res.success && res.data ) ? res.data : {} )
		.catch( () => ( {} ) );
}

export function orderRowTitle( d ) {
	let name = ( d.billing_company || '' ).trim();
	if ( ! name ) { name = ( ( d.billing_first_name || '' ) + ' ' + ( d.billing_last_name || '' ) ).trim(); }
	if ( ! name ) { name = '(no name)'; }
	return '#' + ( d.order_number || '' ) + ' — ' + name;
}

// Secondary line for an order row: "AED 100 · 3 items / 5 units · 18 Jun · Credit Card".
// total_raw is wc_price HTML and must be rendered separately (innerHTML); this
// helper returns only the plain-text remainder.
export function orderMetaLine( d ) {
	const parts = [];
	const items = parseInt( d.line_count, 10 ) || 0;
	const units = parseInt( d.unit_count, 10 ) || 0;
	parts.push( items + ( 1 === items ? ' item' : ' items' ) + ' / ' + units + ( 1 === units ? ' unit' : ' units' ) );
	if ( d.date_raw ) { parts.push( d.date_raw ); }
	if ( d.payment_method ) { parts.push( d.payment_method ); }
	return parts.join( ' · ' );
}
