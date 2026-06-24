/**
 * @jest-environment jsdom
 */

// pdfPreview imports serialize (heavy WP package) and saveBlocks (reads
// window.woiBlocks); stub both so the test exercises only the download path.
jest.mock( '@wordpress/blocks', () => ( { serialize: () => '' } ), { virtual: true } );
jest.mock( './store', () => ( { saveBlocks: () => Promise.resolve() } ) );

import { downloadPdf } from './pdfPreview';

function mockPreviewResponse( data ) {
	global.fetch = jest.fn( () =>
		Promise.resolve( { ok: true, json: () => Promise.resolve( { success: true, data } ) } )
	);
}

describe( 'downloadPdf filename', () => {
	let anchor;

	beforeEach( () => {
		window.woiBlocks = { ajaxUrl: '/ajax', previewNonce: 'n', docType: 'invoice' };
		global.URL.createObjectURL = jest.fn( () => 'blob:x' );
		global.URL.revokeObjectURL = jest.fn();
		anchor = null;
		const realCreate = document.createElement.bind( document );
		jest.spyOn( document, 'createElement' ).mockImplementation( ( tag ) => {
			const el = realCreate( tag );
			if ( 'a' === tag ) { anchor = el; el.click = jest.fn(); }
			return el;
		} );
	} );

	afterEach( () => {
		jest.restoreAllMocks();
		delete global.fetch;
	} );

	it( 'names the download from the server-provided filename', async () => {
		mockPreviewResponse( {
			preview_data: window.btoa( 'PDF' ),
			output_format: 'pdf',
			filename: 'invoice_2026-04-000004_2026-04-22.pdf',
		} );
		await downloadPdf( { blocks: [], orderId: 237 } );
		expect( anchor.download ).toBe( 'invoice_2026-04-000004_2026-04-22.pdf' );
	} );

	it( 'falls back to a default when the server omits the filename', async () => {
		mockPreviewResponse( { preview_data: window.btoa( 'PDF' ), output_format: 'pdf' } );
		await downloadPdf( { blocks: [], orderId: 237 } );
		expect( anchor.download ).toBe( 'invoice.pdf' );
	} );
} );
