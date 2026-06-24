import {
	NAMING_TYPES,
	hasSeries,
	buildNamingPayload,
	FILENAME_TOKENS,
} from './namingModel';

describe( 'namingModel', () => {
	test( 'NAMING_TYPES lists five types with correct series flags', () => {
		const byValue = Object.fromEntries( NAMING_TYPES.map( ( t ) => [ t.value, t ] ) );
		expect( Object.keys( byValue ).sort() ).toEqual(
			[ 'credit-note', 'invoice', 'packing-slip', 'proforma', 'receipt' ]
		);
		expect( byValue.invoice.hasSeries ).toBe( true );
		expect( byValue[ 'packing-slip' ].hasSeries ).toBe( false );
	} );

	test( 'hasSeries reflects the type', () => {
		expect( hasSeries( 'invoice' ) ).toBe( true );
		expect( hasSeries( 'packing-slip' ) ).toBe( false );
		expect( hasSeries( 'nonsense' ) ).toBe( false );
	} );

	test( 'buildNamingPayload includes numbering fields for a numbered type', () => {
		const state = {
			prefix: 'INV-', suffix: '', padding: '6',
			reset_number_yearly: true, next_number: 42,
			filename_template: 'INV_{order_number}',
		};
		expect( buildNamingPayload( 'invoice', state ) ).toEqual( {
			type: 'invoice',
			prefix: 'INV-', suffix: '', padding: '6',
			reset_number_yearly: true, next_number: 42,
			filename_template: 'INV_{order_number}',
		} );
	} );

	test( 'buildNamingPayload omits numbering fields for packing-slip', () => {
		const state = {
			prefix: 'X', padding: '4', reset_number_yearly: true,
			next_number: 9, filename_template: 'PS_{order_number}',
		};
		expect( buildNamingPayload( 'packing-slip', state ) ).toEqual( {
			type: 'packing-slip',
			filename_template: 'PS_{order_number}',
		} );
	} );

	test( 'buildNamingPayload preserves padding "0"', () => {
		const out = buildNamingPayload( 'invoice', { padding: '0', filename_template: '' } );
		expect( out.padding ).toBe( '0' );
	} );

	test( 'FILENAME_TOKENS includes the new sequence token', () => {
		expect( FILENAME_TOKENS ).toContain( '{document_number_sequence}' );
		expect( FILENAME_TOKENS ).toContain( '{document_number}' );
	} );
} );

import { prefixTokens, filenameTokenChips } from './namingModel';

describe( 'prefixTokens', () => {
	it( 'includes the order placeholders and slug-based doc placeholders for invoice', () => {
		const toks = prefixTokens( 'invoice' ).map( ( t ) => t.token );
		expect( toks ).toEqual( expect.arrayContaining( [
			'[order_year]', '[order_month]', '[order_day]', '[order_number]',
			'[invoice_year]', '[invoice_month]', '[invoice_day]',
		] ) );
	} );

	it( 'uses underscore slug for hyphenated types (credit-note)', () => {
		const toks = prefixTokens( 'credit-note' ).map( ( t ) => t.token );
		expect( toks ).toContain( '[credit_note_year]' );
		expect( toks ).not.toContain( '[credit-note_year]' );
	} );
} );

describe( 'filenameTokenChips', () => {
	it( 'wraps every FILENAME_TOKENS entry as a {token,label} chip', () => {
		const chips = filenameTokenChips();
		expect( chips.map( ( c ) => c.token ) ).toContain( '{document_number_sequence}' );
		chips.forEach( ( c ) => {
			expect( typeof c.token ).toBe( 'string' );
			expect( typeof c.label ).toBe( 'string' );
		} );
	} );
} );
