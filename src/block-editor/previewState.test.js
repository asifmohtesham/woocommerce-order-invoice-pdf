import { initialState, reducer, actions, selectors } from './previewState';

describe( 'preview state', () => {
	it( 'seeds tokens from the sample map', () => {
		const s = initialState( { '{{shop_name}}': 'Acme' } );
		expect( selectors.getTokens( s ) ).toEqual( { '{{shop_name}}': 'Acme' } );
		expect( selectors.isLoading( s ) ).toBe( false );
		expect( selectors.getOrderId( s ) ).toBe( null );
	} );

	it( 'setLoading toggles the loading flag', () => {
		const s = reducer( initialState( {} ), actions.setLoading( true ) );
		expect( selectors.isLoading( s ) ).toBe( true );
	} );

	it( 'setOrder replaces tokens/label/id and clears loading', () => {
		let s = reducer( initialState( {} ), actions.setLoading( true ) );
		s = reducer( s, actions.setOrder( { tokens: { a: 1 }, orderLabel: '#5 — Acme', orderId: 5 } ) );
		expect( selectors.getTokens( s ) ).toEqual( { a: 1 } );
		expect( selectors.getOrderLabel( s ) ).toBe( '#5 — Acme' );
		expect( selectors.getOrderId( s ) ).toBe( 5 );
		expect( selectors.isLoading( s ) ).toBe( false );
	} );

	it( 'setOrder keeps prior tokens when none supplied', () => {
		let s = initialState( { keep: 1 } );
		s = reducer( s, actions.setOrder( { orderLabel: 'x', orderId: 9 } ) );
		expect( selectors.getTokens( s ) ).toEqual( { keep: 1 } );
	} );
} );
