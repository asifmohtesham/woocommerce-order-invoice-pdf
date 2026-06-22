import { parseOrderNumber, nextActiveIndex } from './orderInput';

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

describe( 'nextActiveIndex', () => {
	it( 'ArrowDown from nothing highlights the first option', () => {
		expect( nextActiveIndex( 'ArrowDown', -1, 3 ) ).toBe( 0 );
	} );
	it( 'ArrowDown advances to the next option', () => {
		expect( nextActiveIndex( 'ArrowDown', 0, 3 ) ).toBe( 1 );
	} );
	it( 'ArrowDown wraps from the last option to the first', () => {
		expect( nextActiveIndex( 'ArrowDown', 2, 3 ) ).toBe( 0 );
	} );
	it( 'ArrowUp from nothing highlights the last option', () => {
		expect( nextActiveIndex( 'ArrowUp', -1, 3 ) ).toBe( 2 );
	} );
	it( 'ArrowUp moves to the previous option', () => {
		expect( nextActiveIndex( 'ArrowUp', 2, 3 ) ).toBe( 1 );
	} );
	it( 'ArrowUp wraps from the first option to the last', () => {
		expect( nextActiveIndex( 'ArrowUp', 0, 3 ) ).toBe( 2 );
	} );
	it( 'Home and End jump to the ends', () => {
		expect( nextActiveIndex( 'Home', 2, 3 ) ).toBe( 0 );
		expect( nextActiveIndex( 'End', 0, 3 ) ).toBe( 2 );
	} );
	it( 'returns -1 when there are no options', () => {
		expect( nextActiveIndex( 'ArrowDown', -1, 0 ) ).toBe( -1 );
	} );
	it( 'leaves the index unchanged for unrelated keys', () => {
		expect( nextActiveIndex( 'a', 1, 3 ) ).toBe( 1 );
	} );
} );
