import { isHtmlToken, tokenValue } from './tokenMerge';

describe( 'isHtmlToken', () => {
	it( 'is true for HTML-valued tokens', () => {
		expect( isHtmlToken( '{{line_items}}' ) ).toBe( true );
		expect( isHtmlToken( '{{totals}}' ) ).toBe( true );
		expect( isHtmlToken( '{{logo}}' ) ).toBe( true );
		expect( isHtmlToken( '{{billing_address}}' ) ).toBe( true );
		// Shop addresses carry <br/> line breaks server-side → HTML.
		expect( isHtmlToken( '{{shop_address}}' ) ).toBe( true );
		expect( isHtmlToken( '{{shop_address_ar}}' ) ).toBe( true );
	} );
	it( 'is false for plain-text tokens', () => {
		expect( isHtmlToken( '{{shop_name}}' ) ).toBe( false );
		expect( isHtmlToken( '{{shop_name_ar}}' ) ).toBe( false );
		expect( isHtmlToken( '{{trn}}' ) ).toBe( false );
	} );
} );

describe( 'tokenValue', () => {
	it( 'returns the mapped value', () => {
		expect( tokenValue( '{{shop_name}}', { '{{shop_name}}': 'Acme' } ) ).toBe( 'Acme' );
	} );
	it( 'returns empty string when missing or map is null', () => {
		expect( tokenValue( '{{shop_name}}', {} ) ).toBe( '' );
		expect( tokenValue( '{{shop_name}}', null ) ).toBe( '' );
	} );
	it( 'coerces non-string values to string', () => {
		expect( tokenValue( '{{order_number}}', { '{{order_number}}': 4242 } ) ).toBe( '4242' );
	} );
} );
