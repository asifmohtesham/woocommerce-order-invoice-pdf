import { LH_TEXT_FIELDS, LH_DEFAULT, lhValueStyle, lhFieldClass } from './letterheadModel';

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

	test( 'lhFieldClass maps name fields to woi-co-name, address to woi-co-lines', () => {
		// Matches the PDF markup (TemplateTokens::letterhead_text_cell) so the
		// shared accent/font CSS keys on the same classes in editor and PDF.
		expect( lhFieldClass( 'name_en' ) ).toBe( 'woi-co-name' );
		expect( lhFieldClass( 'name_ar' ) ).toBe( 'woi-co-name' );
		expect( lhFieldClass( 'address_en' ) ).toBe( 'woi-co-lines' );
		expect( lhFieldClass( 'address_ar' ) ).toBe( 'woi-co-lines' );
	} );

	test( 'lhValueStyle emits only set properties', () => {
		expect( lhValueStyle( { bold: false, fontSize: 0, color: '' } ) ).toEqual( {} );
		expect( lhValueStyle( { bold: true, fontSize: 16, color: '#ff0000' } ) ).toEqual( {
			fontWeight: 'bold', fontSize: '16px', color: '#ff0000',
		} );
	} );
} );
