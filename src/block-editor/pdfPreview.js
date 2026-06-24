import { serialize } from '@wordpress/blocks';
import { saveBlocks } from './store';

// Monotonic guard: a newer render supersedes older ones mid-flight.
let renderGen = 0;

// Render every page of the decoded PDF into A4 canvases in a detached fragment,
// then swap into the stage only if this render is still the latest (gen check).
function renderPdfPages( stageEl, bytes, gen ) {
	if ( ! window.pdfjsLib ) { return Promise.reject( new Error( 'PDF.js not loaded' ) ); }
	window.pdfjsLib.GlobalWorkerOptions.workerSrc = window.woiBlocks.pdfWorkerUrl;
	const task = window.pdfjsLib.getDocument( { data: bytes } );
	return task.promise.then( ( pdf ) => {
		const frag = document.createDocumentFragment();
		const dpr = window.devicePixelRatio || 1;
		let chain = Promise.resolve();
		for ( let n = 1; n <= pdf.numPages; n++ ) {
			( ( pageNum ) => {
				chain = chain.then( () => {
					if ( gen !== renderGen ) { return undefined; } // superseded mid-render
					return pdf.getPage( pageNum ).then( ( page ) => {
						const canvas = document.createElement( 'canvas' );
						const vp = page.getViewport( { scale: dpr } );
						// Intrinsic px are dpr-scaled for crisp HiDPI; CSS keeps display at true A4.
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
			if ( gen !== renderGen ) { task.destroy(); return; }
			stageEl.innerHTML = '';
			stageEl.appendChild( frag );
			task.destroy();
		} );
	} ).catch( ( e ) => {
		task.destroy();
		return Promise.reject( e );
	} );
}

// POST the current design to the preview endpoint and return the decoded PDF
// bytes (Uint8Array). Saves the blocks first so the server renders the latest.
// `noWatermark` requests a clean file (the Download action; previews stay marked).
function fetchPdfBytes( blocks, orderId, noWatermark ) {
	return saveBlocks( serialize( blocks || [] ) ).then( () => {
		const w = window.woiBlocks || {};
		let body = 'action=woi_pdf_preview' +
			'&security=' + encodeURIComponent( w.previewNonce ) +
			'&document_type=' + encodeURIComponent( w.docType );
		if ( orderId ) { body += '&order_id=' + encodeURIComponent( orderId ); }
		if ( noWatermark ) { body += '&no_watermark=1'; }
		return fetch( w.ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			credentials: 'same-origin',
			body,
		} );
	} ).then( ( r ) => { if ( ! r.ok ) { throw new Error( 'HTTP ' + r.status ); } return r.json(); } )
		.then( ( res ) => {
			if ( ! res.success || ! res.data || ! res.data.preview_data || 'pdf' !== res.data.output_format ) {
				throw new Error( ( res.data && res.data.error ) ? res.data.error : 'PDF generation failed.' );
			}
			const binary = window.atob( res.data.preview_data );
			const bytes = new Uint8Array( binary.length );
			for ( let i = 0; i < binary.length; i++ ) { bytes[ i ] = binary.charCodeAt( i ); }
			// The server resolves the filename from the document's get_filename()
			// (per-type override -> global template -> default). Carry it back so
			// the download honours the configured naming instead of a hardcode.
			return { bytes, filename: ( res.data.filename || '' ) };
		} );
}

// Generate the PDF and trigger a browser download. Returns a Promise that
// resolves once the download has been kicked off (or rejects on failure).
// `filename` (if passed) is only a last-resort fallback; the server-resolved
// name wins.
export function downloadPdf( { blocks, orderId, filename } ) {
	return fetchPdfBytes( blocks, orderId, true ).then( ( { bytes, filename: serverFilename } ) => {
		const blob = new Blob( [ bytes ], { type: 'application/pdf' } );
		const url = URL.createObjectURL( blob );
		const a = document.createElement( 'a' );
		a.href = url;
		a.download = serverFilename || filename || 'invoice.pdf';
		document.body.appendChild( a );
		a.click();
		a.remove();
		setTimeout( () => URL.revokeObjectURL( url ), 1000 );
	} );
}

// Save the current design, render the real mPDF, paint A4 canvases into stageEl.
// onStatus( text ) reports progress/errors ('' clears). Returns a Promise.
export function renderPdfPreview( { stageEl, blocks, orderId, onStatus } ) {
	if ( ! stageEl ) { return Promise.resolve(); }
	const gen = ++renderGen;
	onStatus( 'Rendering…' );
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
				throw new Error( ( res.data && res.data.error ) ? res.data.error : 'Preview failed.' );
			}
			const binary = window.atob( res.data.preview_data );
			const bytes = new Uint8Array( binary.length );
			for ( let i = 0; i < binary.length; i++ ) { bytes[ i ] = binary.charCodeAt( i ); }
			return renderPdfPages( stageEl, bytes, gen ).then( () => { if ( gen === renderGen ) { onStatus( '' ); } } );
		} ).catch( ( e ) => { if ( gen === renderGen ) { onStatus( 'Error: ' + ( e && e.message ? e.message : e ) ); } } );
}
