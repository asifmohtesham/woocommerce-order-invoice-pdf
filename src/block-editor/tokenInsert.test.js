import { insertAtCaret } from './tokenInsert';

describe( 'insertAtCaret', () => {
	it( 'inserts at the caret position', () => {
		expect( insertAtCaret( 'ab', 1, 1, '[x]' ) ).toEqual( { value: 'a[x]b', caret: 4 } );
	} );

	it( 'replaces the current selection', () => {
		expect( insertAtCaret( 'abcd', 1, 3, '[x]' ) ).toEqual( { value: 'a[x]d', caret: 4 } );
	} );

	it( 'appends when caret is unknown (null)', () => {
		expect( insertAtCaret( 'ab', null, null, '[x]' ) ).toEqual( { value: 'ab[x]', caret: 5 } );
	} );

	it( 'treats null/undefined value as empty string', () => {
		expect( insertAtCaret( undefined, null, null, '{d}' ) ).toEqual( { value: '{d}', caret: 3 } );
	} );
} );
