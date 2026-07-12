import { serialize } from '@wordpress/blocks';
import { __, _n, sprintf } from '@wordpress/i18n';
import { saveBlocks } from './store';

// Monotonic guard: a newer render supersedes older ones mid-flight.
let renderGen = 0;

// Render pages at the size they are displayed (stage width) times the device
// pixel ratio, so canvases stay crisp instead of upscaling PDF.js's 72dpi base
// (595px for A4). Floor at dpr, cap at 4 to bound canvas memory on huge zooms.
function pageScale( stageEl, page ) {
	const dpr = window.devicePixelRatio || 1;
	const base = page.getViewport( { scale: 1 } );
	const cssWidth = stageEl.clientWidth || 820;
	return Math.min( Math.max( ( cssWidth / base.width ) * dpr, dpr ), 4 );
}

// Render every page of the decoded PDF into A4 canvases in a detached fragment,
// then swap into the stage only if this render is still the latest (gen check).
function renderPdfPages( stageEl, bytes, gen, onStatus ) {
	if ( ! window.pdfjsLib ) { return Promise.reject( new Error( __( 'PDF.js not loaded', 'woocommerce-orders-invoice-pdf' ) ) ); }
	window.pdfjsLib.GlobalWorkerOptions.workerSrc = window.woiBlocks.pdfWorkerUrl;
	const task = window.pdfjsLib.getDocument( { data: bytes } );
	return task.promise.then( ( pdf ) => {
		const frag = document.createDocumentFragment();
		let chain = Promise.resolve();
		for ( let n = 1; n <= pdf.numPages; n++ ) {
			( ( pageNum ) => {
				chain = chain.then( () => {
					if ( gen !== renderGen ) { return undefined; } // superseded mid-render
					if ( pdf.numPages > 1 ) {
						/* translators: 1: page being rendered, 2: total pages. */
						onStatus( sprintf( __( 'Rendering page %1$d of %2$d…', 'woocommerce-orders-invoice-pdf' ), pageNum, pdf.numPages ) );
					}
					return pdf.getPage( pageNum ).then( ( page ) => {
						const canvas = document.createElement( 'canvas' );
						const vp = page.getViewport( { scale: pageScale( stageEl, page ) } );
						// Intrinsic px match display size × dpr for crisp HiDPI; CSS keeps display at true A4.
						canvas.width = Math.floor( vp.width );
						canvas.height = Math.floor( vp.height );
						canvas.style.width = '100%';
						canvas.style.height = 'auto';
						canvas.style.aspectRatio = '210 / 297';
						canvas.style.background = '#fff';
						canvas.style.boxShadow = '0 1px 6px rgba(0,0,0,.45)';
						canvas.style.display = 'block';
						frag.appendChild( canvas );
						return page.render( { canvasContext: canvas.getContext( '2d' ), viewport: vp } ).promise;
					} );
				} );
			} )( n );
		}
		return chain.then( () => {
			if ( gen !== renderGen ) { task.destroy(); return undefined; }
			stageEl.innerHTML = '';
			stageEl.appendChild( frag );
			task.destroy();
			return pdf.numPages;
		} );
	} ).catch( ( e ) => {
		task.destroy();
		return Promise.reject( e );
	} );
}

// Save the current design, render the real mPDF, paint A4 canvases into stageEl.
// onStatus( text ) reports progress/errors ('' clears). onPdf( bytes ) receives a
// stable copy of the rendered PDF (for download) on success. Returns a Promise.
export function renderPdfPreview( { stageEl, blocks, orderId, onStatus, onPdf } ) {
	if ( ! stageEl ) { return Promise.resolve(); }
	const gen = ++renderGen;
	onStatus( __( 'Rendering…', 'woocommerce-orders-invoice-pdf' ) );
	return saveBlocks( serialize( blocks || [] ) ).then( () => {
		const w = window.woiBlocks || {};
		let body = 'action=woi_pdf_preview' +
			'&security=' + encodeURIComponent( w.previewNonce ) +
			'&document_type=' + encodeURIComponent( w.docType );
		if ( orderId ) { body += '&order_id=' + encodeURIComponent( orderId ); }
		return fetch( w.ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			credentials: 'same-origin',
			body,
		} );
	} ).then( ( r ) => { if ( ! r.ok ) { throw new Error( 'HTTP ' + r.status ); } return r.json(); } )
		.then( ( res ) => {
			if ( gen !== renderGen ) { return undefined; } // a newer render started during the round-trip
			if ( ! res.success || ! res.data || ! res.data.preview_data || 'pdf' !== res.data.output_format ) {
				throw new Error( ( res.data && res.data.error ) ? res.data.error : __( 'Preview failed.', 'woocommerce-orders-invoice-pdf' ) );
			}
			const binary = window.atob( res.data.preview_data );
			const bytes = new Uint8Array( binary.length );
			for ( let i = 0; i < binary.length; i++ ) { bytes[ i ] = binary.charCodeAt( i ); }
			// PDF.js transfers `bytes` to its worker (detaching the buffer), so hand
			// the download callback its own copy before rendering starts.
			const copy = onPdf ? bytes.slice() : null;
			return renderPdfPages( stageEl, bytes, gen, onStatus ).then( ( numPages ) => {
				if ( gen !== renderGen ) { return; }
				if ( onPdf ) { onPdf( copy ); }
				onStatus( numPages
					/* translators: %d: number of pages in the rendered PDF. */
					? sprintf( _n( '%d page', '%d pages', numPages, 'woocommerce-orders-invoice-pdf' ), numPages )
					: '' );
			} );
		} ).catch( ( e ) => {
			if ( gen === renderGen ) {
				/* translators: %s: error message. */
				onStatus( sprintf( __( 'Error: %s', 'woocommerce-orders-invoice-pdf' ), ( e && e.message ) ? e.message : String( e ) ) );
			}
		} );
}
