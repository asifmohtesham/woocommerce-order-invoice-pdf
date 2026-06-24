// Pure caret-insertion helper for TokenField. Given the input's current value
// and selection range, returns the value with `token` spliced in and the new
// caret offset (so the caller can restore selection after React re-renders).
export function insertAtCaret( value, start, end, token ) {
	const v = String( value == null ? '' : value );
	const s = Number.isInteger( start ) ? start : v.length;
	const e = Number.isInteger( end ) ? end : s;
	const next = v.slice( 0, s ) + token + v.slice( e );
	return { value: next, caret: s + token.length };
}
