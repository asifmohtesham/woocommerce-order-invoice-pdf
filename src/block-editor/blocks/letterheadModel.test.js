import { LH_TEXT_FIELDS, LH_DEFAULT, lhValueStyle } from './letterheadModel';

describe( 'letterheadModel', () => {
	test( 'text fields are name/address EN then AR, with value tokens', () => {
		expect( LH_TEXT_FIELDS.map( ( f ) => f.key ) ).toEqual( [ 'name_en', 'address_en', 'name_ar', 'address_ar' ] );
		expect( LH_TEXT_FIELDS.find( ( f ) => f.key === 'name_en' ).token ).toBe( '{{shop_name}}' );
		expect( LH_TEXT_FIELDS.find( ( f ) => f.key === 'address_ar' ).token ).toBe( '{{shop_address_ar}}' );
	} );

	test( 'default config: all visible, EN left / AR right, no swap/width', () => {
		expect( LH_DEFAULT.swapText ).toBe( false );
		expect( LH_DEFAULT.logoWidth ).toBe( 0 );
		expect( LH_DEFAULT.elements.name_en.align ).toBe( 'left' );
		expect( LH_DEFAULT.elements.name_ar.align ).toBe( 'right' );
		expect( LH_DEFAULT.elements.logo.visible ).toBe( true );
	} );

	test( 'lhValueStyle emits only set properties', () => {
		expect( lhValueStyle( { bold: false, fontSize: 0, color: '' } ) ).toEqual( {} );
		expect( lhValueStyle( { bold: true, fontSize: 16, color: '#ff0000' } ) ).toEqual( {
			fontWeight: 'bold', fontSize: '16px', color: '#ff0000',
		} );
	} );
} );
