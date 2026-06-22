import { reorder, valueStyle, CONTACT_DEFAULT_ITEMS, CONTACT_FIELDS } from './contactStripModel';

describe( 'contactStripModel', () => {
	test( 'default items are trn, tel, email in order, all visible', () => {
		expect( CONTACT_DEFAULT_ITEMS.map( ( i ) => i.field ) ).toEqual( [ 'trn', 'tel', 'email' ] );
		expect( CONTACT_DEFAULT_ITEMS.every( ( i ) => i.visible ) ).toBe( true );
	} );

	test( 'field map exposes label + token for each field', () => {
		expect( CONTACT_FIELDS.trn.token ).toBe( '{{trn}}' );
		expect( CONTACT_FIELDS.tel.token ).toBe( '{{shop_phone}}' );
		expect( CONTACT_FIELDS.email.token ).toBe( '{{shop_email}}' );
	} );

	test( 'reorder moves an item and leaves the original array untouched', () => {
		const items = [ { field: 'a' }, { field: 'b' }, { field: 'c' } ];
		expect( reorder( items, 0, 2 ).map( ( i ) => i.field ) ).toEqual( [ 'b', 'c', 'a' ] );
		expect( items.map( ( i ) => i.field ) ).toEqual( [ 'a', 'b', 'c' ] );
	} );

	test( 'reorder is a no-op on out-of-range or equal indices', () => {
		const items = [ { field: 'a' }, { field: 'b' } ];
		expect( reorder( items, 0, 0 ).map( ( i ) => i.field ) ).toEqual( [ 'a', 'b' ] );
		expect( reorder( items, -1, 1 ).map( ( i ) => i.field ) ).toEqual( [ 'a', 'b' ] );
		expect( reorder( items, 0, 5 ).map( ( i ) => i.field ) ).toEqual( [ 'a', 'b' ] );
	} );

	test( 'valueStyle emits only set properties', () => {
		expect( valueStyle( { bold: false, fontSize: 0, color: '' } ) ).toEqual( {} );
		expect( valueStyle( { bold: true, fontSize: 12, color: '#ff0000' } ) ).toEqual( {
			fontWeight: 'bold',
			fontSize: '12px',
			color: '#ff0000',
		} );
	} );
} );
