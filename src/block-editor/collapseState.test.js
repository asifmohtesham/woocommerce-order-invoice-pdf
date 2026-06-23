import { allCollapsed, toggle, move, remove, add, isAllCollapsed } from './collapseState';

const sorted = ( set ) => [ ...set ].sort( ( a, b ) => a - b );

describe( 'collapseState', () => {
	describe( 'allCollapsed', () => {
		it( 'returns every index 0..len-1', () => {
			expect( sorted( allCollapsed( 3 ) ) ).toEqual( [ 0, 1, 2 ] );
		} );
		it( 'returns an empty set for len 0', () => {
			expect( allCollapsed( 0 ).size ).toBe( 0 );
		} );
	} );

	describe( 'toggle', () => {
		it( 'collapses an expanded index', () => {
			expect( sorted( toggle( new Set(), 1 ) ) ).toEqual( [ 1 ] );
		} );
		it( 'expands a collapsed index', () => {
			expect( sorted( toggle( new Set( [ 0, 1 ] ), 1 ) ) ).toEqual( [ 0 ] );
		} );
		it( 'does not mutate the input set', () => {
			const src = new Set( [ 0 ] );
			toggle( src, 1 );
			expect( sorted( src ) ).toEqual( [ 0 ] );
		} );
	} );

	describe( 'move', () => {
		it( 'swaps membership of two indices when only one is collapsed', () => {
			// index 0 collapsed, 1 expanded; swapping should flip them
			expect( sorted( move( new Set( [ 0 ] ), 0, 1 ) ) ).toEqual( [ 1 ] );
		} );
		it( 'is a no-op when both indices share the same state', () => {
			expect( sorted( move( new Set( [ 0, 1 ] ), 0, 1 ) ) ).toEqual( [ 0, 1 ] );
			expect( sorted( move( new Set(), 0, 1 ) ) ).toEqual( [] );
		} );
		it( 'leaves other indices untouched', () => {
			expect( sorted( move( new Set( [ 2 ] ), 0, 1 ) ) ).toEqual( [ 2 ] );
		} );
	} );

	describe( 'remove', () => {
		it( 'drops the removed index', () => {
			expect( sorted( remove( new Set( [ 0, 1 ] ), 1 ) ) ).toEqual( [ 0 ] );
		} );
		it( 'shifts higher indices down by one', () => {
			// columns [0,1,2,3] collapsed {2,3}; remove index 1 -> {1,2}
			expect( sorted( remove( new Set( [ 2, 3 ] ), 1 ) ) ).toEqual( [ 1, 2 ] );
		} );
		it( 'keeps lower indices unchanged', () => {
			expect( sorted( remove( new Set( [ 0 ] ), 2 ) ) ).toEqual( [ 0 ] );
		} );
	} );

	describe( 'add', () => {
		it( 'leaves the set unchanged so the new (appended) index renders expanded', () => {
			expect( sorted( add( new Set( [ 0, 1 ] ) ) ) ).toEqual( [ 0, 1 ] );
		} );
	} );

	describe( 'isAllCollapsed', () => {
		it( 'is true when every index is collapsed', () => {
			expect( isAllCollapsed( new Set( [ 0, 1 ] ), 2 ) ).toBe( true );
		} );
		it( 'is false when some are expanded', () => {
			expect( isAllCollapsed( new Set( [ 0 ] ), 2 ) ).toBe( false );
		} );
		it( 'is false when there are no columns', () => {
			expect( isAllCollapsed( new Set(), 0 ) ).toBe( false );
		} );
	} );
} );
