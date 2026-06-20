import { createReduxStore, register } from '@wordpress/data';
import { initialState, reducer, actions, selectors } from './previewState';

export const STORE = 'woi/preview';

const seed = initialState( ( window.woiBlocks && window.woiBlocks.sampleData ) || {} );

const store = createReduxStore( STORE, {
	reducer( state = seed, action ) {
		return reducer( state, action );
	},
	actions,
	selectors,
} );

register( store );

export default store;
