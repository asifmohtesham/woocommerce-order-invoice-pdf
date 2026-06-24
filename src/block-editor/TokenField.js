import { useRef, useCallback } from '@wordpress/element';
import { insertAtCaret } from './tokenInsert';

// A text input plus a row of token chips. Each chip inserts its token at the
// input caret on click, and is draggable (drop inserts at the caret too). The
// field stays free-text — chips insert, they don't lock it into segments.
export default function TokenField( { label, value, onChange, tokens, help } ) {
	const inputRef = useRef( null );

	const insert = useCallback( ( token ) => {
		const el = inputRef.current;
		const start = el ? el.selectionStart : null;
		const end = el ? el.selectionEnd : null;
		const r = insertAtCaret( value, start, end, token );
		onChange( r.value );
		// Restore focus + caret after React applies the new value.
		requestAnimationFrame( () => {
			if ( inputRef.current ) {
				inputRef.current.focus();
				inputRef.current.setSelectionRange( r.caret, r.caret );
			}
		} );
	}, [ value, onChange ] );

	return (
		<div className="woi-token-field components-base-control">
			{ label ? (
				<label className="components-base-control__label">{ label }</label>
			) : null }
			<input
				ref={ inputRef }
				type="text"
				className="components-text-control__input"
				value={ value || '' }
				onChange={ ( e ) => onChange( e.target.value ) }
				onDragOver={ ( e ) => e.preventDefault() }
				onDrop={ ( e ) => {
					e.preventDefault();
					const token = e.dataTransfer.getData( 'text/plain' );
					if ( token ) { insert( token ); }
				} }
			/>
			<div className="woi-token-chips">
				{ tokens.map( ( t ) => (
					<button
						type="button"
						key={ t.token }
						className="woi-token-chip"
						draggable={ true }
						onDragStart={ ( e ) => e.dataTransfer.setData( 'text/plain', t.token ) }
						onClick={ () => insert( t.token ) }
						title={ t.token }
					>
						{ t.label }
					</button>
				) ) }
			</div>
			{ help ? <p className="components-base-control__help">{ help }</p> : null }
		</div>
	);
}
