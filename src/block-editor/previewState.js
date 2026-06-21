// Pure preview state: no @wordpress imports so it is unit-testable. previewStore.js
// wraps this in a registered @wordpress/data store.

export function initialState( sample ) {
	return { tokens: sample || {}, orderLabel: '', orderId: null, loading: false };
}

export function reducer( state, action ) {
	switch ( action.type ) {
		case 'SET_LOADING':
			return { ...state, loading: action.loading };
		case 'SET_ORDER':
			return {
				...state,
				tokens: action.tokens || state.tokens,
				orderLabel: action.orderLabel || '',
				orderId: ( undefined === action.orderId ? null : action.orderId ),
				loading: false,
			};
		case 'PATCH_TOKENS':
			// Merge a partial token map (e.g. just {{line_items}} after a column
			// change) into the current tokens, keeping every other token intact.
			return { ...state, tokens: { ...state.tokens, ...( action.tokens || {} ) }, loading: false };
		default:
			return state;
	}
}

export const actions = {
	setLoading( loading ) {
		return { type: 'SET_LOADING', loading };
	},
	setOrder( { tokens, orderLabel, orderId } ) {
		return { type: 'SET_ORDER', tokens, orderLabel, orderId };
	},
	patchTokens( tokens ) {
		return { type: 'PATCH_TOKENS', tokens };
	},
};

export const selectors = {
	getTokens( state ) { return state.tokens; },
	getOrderLabel( state ) { return state.orderLabel; },
	getOrderId( state ) { return state.orderId; },
	isLoading( state ) { return state.loading; },
};
