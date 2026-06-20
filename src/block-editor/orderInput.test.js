import { parseOrderNumber } from './orderInput';

describe( 'parseOrderNumber', () => {
	it( 'returns the digits for a numeric term', () => {
		expect( parseOrderNumber( '4242' ) ).toBe( '4242' );
	} );
	it( 'strips a leading # and whitespace', () => {
		expect( parseOrderNumber( ' #4242 ' ) ).toBe( '4242' );
	} );
	it( 'returns null for non-numeric or empty terms', () => {
		expect( parseOrderNumber( 'john' ) ).toBe( null );
		expect( parseOrderNumber( '' ) ).toBe( null );
		expect( parseOrderNumber( '12a' ) ).toBe( null );
	} );
} );
