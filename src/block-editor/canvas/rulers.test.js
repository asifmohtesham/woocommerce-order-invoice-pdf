import { majorMarks, pageBoundaries } from './rulers';

describe( 'majorMarks', () => {
	it( 'marks every 10mm including the end when divisible', () => {
		expect( majorMarks( 210 ) ).toEqual( [ 0, 10, 20, 30, 40, 50, 60, 70, 80, 90, 100, 110, 120, 130, 140, 150, 160, 170, 180, 190, 200, 210 ] );
	} );
	it( 'stops at the last mark not exceeding length', () => {
		expect( majorMarks( 25 ) ).toEqual( [ 0, 10, 20 ] );
	} );
	it( 'honours a custom interval', () => {
		expect( majorMarks( 60, 30 ) ).toEqual( [ 0, 30, 60 ] );
	} );
} );

describe( 'pageBoundaries', () => {
	it( 'returns interior A4 page offsets', () => {
		expect( pageBoundaries( 700 ) ).toEqual( [ 297, 594 ] );
	} );
	it( 'is empty for a single page', () => {
		expect( pageBoundaries( 200 ) ).toEqual( [] );
		expect( pageBoundaries( 297 ) ).toEqual( [] );
	} );
} );
