const { restUrl, nonce, docType } = window.woiBlocks || {};

async function post( path, body ) {
	const res = await fetch( `${ restUrl }/${ path }`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
		body: JSON.stringify( body ),
	} );
	if ( ! res.ok ) {
		throw new Error( `Request failed: ${ res.status }` );
	}
	return res.json();
}

async function get( path ) {
	const res = await fetch( `${ restUrl }/${ path }`, {
		headers: { 'X-WP-Nonce': nonce },
		credentials: 'same-origin',
	} );
	if ( ! res.ok ) {
		throw new Error( `Request failed: ${ res.status }` );
	}
	return res.json();
}

export function saveBlocks( markup ) {
	return post( 'visual-blocks', { doc_type: docType, markup } );
}

export function setActiveSource( source ) {
	return post( 'visual-active-source', { source } );
}

export function saveDocOptions( options ) {
	return post( 'visual-doc-options', { options } );
}

export function saveContactItems( items ) {
	return post( 'contact-items', { items } );
}

export function saveLetterhead( items ) {
	return post( 'letterhead', { items } );
}

export function getColumns() {
	return get( 'visual-columns' );
}

export function saveColumns( columns, orderId ) {
	return post( 'visual-columns', orderId ? { columns, order_id: orderId } : { columns } );
}

export function getEditorConfig() {
	return get( 'editor-config' );
}

export function saveEditorConfig( payload, orderId ) {
	return post( 'editor-config', orderId ? { ...payload, order_id: orderId } : payload );
}

export function getDocumentNaming( type ) {
	return get( `document-naming?type=${ encodeURIComponent( type ) }` );
}

export function saveDocumentNaming( payload ) {
	return post( 'document-naming', payload );
}

export function getNamingPreview( payload ) {
	return post( 'naming-preview', payload );
}
