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
