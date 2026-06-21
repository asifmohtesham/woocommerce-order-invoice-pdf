import { setTextAlign, getTextAlign, renderableOptions } from '../optionSchema';

describe( 'setTextAlign', () => {
	it( 'adds text-align to an empty style', () => {
		expect( setTextAlign( '', 'center' ) ).toBe( 'text-align: center;' );
	} );
	it( 'replaces an existing text-align, preserving other declarations', () => {
		expect( setTextAlign( 'color:#000; text-align:left; font-size:12px', 'right' ) )
			.toBe( 'color:#000; font-size:12px; text-align: right;' );
	} );
	it( 'removes text-align when align is empty', () => {
		expect( setTextAlign( 'color:#000; text-align:left;', '' ) ).toBe( 'color:#000;' );
	} );
} );

describe( 'getTextAlign', () => {
	it( 'reads the declaration', () => {
		expect( getTextAlign( 'text-align: center; color:#000' ) ).toBe( 'center' );
	} );
	it( 'returns empty when absent', () => {
		expect( getTextAlign( 'color:#000' ) ).toBe( '' );
	} );
} );

describe( 'renderableOptions', () => {
	const schema = { options: {
		label: { type: 'text' },
		width: { type: 'number' },
		price_type: { type: 'select', options: { single: 'S' } },
		note: { type: 'documentation' },
	} };
	it( 'keeps input widgets and drops documentation', () => {
		const keys = renderableOptions( schema, { exclude: [ 'label', 'width' ] } ).map( ( o ) => o.key );
		expect( keys ).toEqual( [ 'price_type' ] );
	} );
} );
