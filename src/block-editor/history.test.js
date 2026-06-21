import { initHistory, historyReducer, canUndo, canRedo } from './history';

const A = [ { name: 'a' } ];
const B = [ { name: 'b' } ];
const C = [ { name: 'c' } ];

describe( 'history reducer', () => {
	test( 'initHistory seeds present with empty past/future', () => {
		expect( initHistory( A ) ).toEqual( { past: [], present: A, future: [] } );
	} );

	test( 'CHANGE pushes the previous present onto past and clears future', () => {
		const s1 = initHistory( A );
		const s2 = historyReducer( s1, { type: 'CHANGE', blocks: B } );
		expect( s2 ).toEqual( { past: [ A ], present: B, future: [] } );
	} );

	test( 'CHANGE with the same present is a no-op (no duplicate history entry)', () => {
		const s1 = initHistory( A );
		const s2 = historyReducer( s1, { type: 'CHANGE', blocks: A } );
		expect( s2 ).toBe( s1 );
	} );

	test( 'INPUT replaces present without adding a history entry', () => {
		const s1 = initHistory( A );
		const s2 = historyReducer( s1, { type: 'INPUT', blocks: B } );
		expect( s2 ).toEqual( { past: [], present: B, future: [] } );
	} );

	test( 'UNDO restores the previous present and stashes the current onto future', () => {
		const s = historyReducer( initHistory( A ), { type: 'CHANGE', blocks: B } );
		const u = historyReducer( s, { type: 'UNDO' } );
		expect( u ).toEqual( { past: [], present: A, future: [ B ] } );
	} );

	test( 'REDO re-applies the next future entry', () => {
		let s = historyReducer( initHistory( A ), { type: 'CHANGE', blocks: B } );
		s = historyReducer( s, { type: 'UNDO' } );
		const r = historyReducer( s, { type: 'REDO' } );
		expect( r ).toEqual( { past: [ A ], present: B, future: [] } );
	} );

	test( 'a fresh CHANGE clears the redo future', () => {
		let s = historyReducer( initHistory( A ), { type: 'CHANGE', blocks: B } );
		s = historyReducer( s, { type: 'UNDO' } );      // future = [B]
		s = historyReducer( s, { type: 'CHANGE', blocks: C } );
		expect( s.future ).toEqual( [] );
		expect( s.present ).toBe( C );
	} );

	test( 'UNDO/REDO at the ends are no-ops', () => {
		const s = initHistory( A );
		expect( historyReducer( s, { type: 'UNDO' } ) ).toBe( s );
		expect( historyReducer( s, { type: 'REDO' } ) ).toBe( s );
	} );

	test( 'RESET clears history to a new present', () => {
		const s = historyReducer( initHistory( A ), { type: 'CHANGE', blocks: B } );
		expect( historyReducer( s, { type: 'RESET', blocks: C } ) ).toEqual( { past: [], present: C, future: [] } );
	} );

	test( 'canUndo/canRedo reflect stack contents', () => {
		const s0 = initHistory( A );
		expect( canUndo( s0 ) ).toBe( false );
		expect( canRedo( s0 ) ).toBe( false );
		const s1 = historyReducer( s0, { type: 'CHANGE', blocks: B } );
		expect( canUndo( s1 ) ).toBe( true );
		const s2 = historyReducer( s1, { type: 'UNDO' } );
		expect( canRedo( s2 ) ).toBe( true );
	} );
} );
