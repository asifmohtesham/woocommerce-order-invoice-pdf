/**
 * Pure undo/redo history over the editor's block array. The controlled
 * BlockEditorProvider keeps no history of its own, so the Editor drives this
 * reducer: persistent edits (onChange) push a history entry; transient edits
 * (onInput, e.g. mid-typing) only replace the present so typing isn't recorded
 * keystroke-by-keystroke. Pure — imports zero @wordpress/* so jest can run it.
 */
export function initHistory( blocks ) {
	return { past: [], present: blocks, future: [] };
}

export function historyReducer( state, action ) {
	switch ( action.type ) {
		case 'RESET':
			return { past: [], present: action.blocks, future: [] };
		case 'CHANGE':
			if ( action.blocks === state.present ) {
				return state;
			}
			return { past: [ ...state.past, state.present ], present: action.blocks, future: [] };
		case 'INPUT':
			if ( action.blocks === state.present ) {
				return state;
			}
			return { past: state.past, present: action.blocks, future: state.future };
		case 'UNDO':
			if ( ! state.past.length ) {
				return state;
			}
			return {
				past: state.past.slice( 0, -1 ),
				present: state.past[ state.past.length - 1 ],
				future: [ state.present, ...state.future ],
			};
		case 'REDO':
			if ( ! state.future.length ) {
				return state;
			}
			return {
				past: [ ...state.past, state.present ],
				present: state.future[ 0 ],
				future: state.future.slice( 1 ),
			};
		default:
			return state;
	}
}

export const canUndo = ( state ) => state.past.length > 0;
export const canRedo = ( state ) => state.future.length > 0;
