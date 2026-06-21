import { APPEARANCE_ATTRS, appearanceStyle, appearanceProps } from './appearanceStyle';

describe( 'appearanceStyle', () => {
	test( 'empty attributes produce an empty style object', () => {
		expect( appearanceStyle( {} ) ).toEqual( {} );
	} );

	test( 'maps the original presentational attributes', () => {
		expect(
			appearanceStyle( { align: 'center', weight: 'bold', fontSize: 14, color: '#111', bg: '#eee' } )
		).toEqual( {
			textAlign: 'center',
			fontWeight: 'bold',
			fontSize: '14px',
			color: '#111',
			backgroundColor: '#eee',
		} );
	} );

	test( 'adds padding and margin in px only when non-zero', () => {
		expect( appearanceStyle( { padding: 8, margin: 12 } ) ).toEqual( { padding: '8px', margin: '12px' } );
		expect( appearanceStyle( { padding: 0, margin: 0 } ) ).toEqual( {} );
	} );

	test( 'adds width verbatim only when set', () => {
		expect( appearanceStyle( { width: '50%' } ) ).toEqual( { width: '50%' } );
		expect( appearanceStyle( { width: '' } ) ).toEqual( {} );
	} );

	test( 'fontSize 0 is treated as unset', () => {
		expect( appearanceStyle( { fontSize: 0 } ) ).toEqual( {} );
	} );
} );

describe( 'appearanceProps', () => {
	test( 'returns {} when nothing is set (byte-identical serialisation)', () => {
		expect( appearanceProps( {} ) ).toEqual( {} );
	} );

	test( 'returns { style } when something is set', () => {
		expect( appearanceProps( { padding: 4 } ) ).toEqual( { style: { padding: '4px' } } );
	} );
} );

describe( 'APPEARANCE_ATTRS', () => {
	test( 'declares the new spacing/width attributes with empty defaults', () => {
		expect( APPEARANCE_ATTRS.padding ).toEqual( { type: 'number', default: 0 } );
		expect( APPEARANCE_ATTRS.margin ).toEqual( { type: 'number', default: 0 } );
		expect( APPEARANCE_ATTRS.width ).toEqual( { type: 'string', default: '' } );
	} );
} );
