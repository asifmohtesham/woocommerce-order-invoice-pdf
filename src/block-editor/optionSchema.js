// Pure helpers shared by the schema-driven Customiser panels.

/**
 * Parse "a:b; c:d" into [ { prop, value, raw }, ... ] (lowercased prop, trimmed).
 * raw preserves the original "prop:value" text for round-tripping.
 */
function parseDecls( style ) {
	return String( style || '' )
		.split( ';' )
		.map( ( d ) => d.trim() )
		.filter( Boolean )
		.map( ( d ) => {
			const i = d.indexOf( ':' );
			if ( i === -1 ) {
				return { prop: d.trim().toLowerCase(), value: '', raw: d.trim() };
			}
			return {
				prop: d.slice( 0, i ).trim().toLowerCase(),
				value: d.slice( i + 1 ).trim(),
				raw: d.trim(),
			};
		} );
}

/** Join declarations back, preserving original text except for newly added ones. */
function joinDecls( decls ) {
	return decls.map( ( d ) => ( d.raw ? `${ d.raw };` : `${ d.prop }: ${ d.value };` ) ).join( ' ' ).trim();
}

/** Set/replace/remove the text-align declaration without touching others. */
export function setTextAlign( style, align ) {
	const decls = parseDecls( style ).filter( ( d ) => d.prop !== 'text-align' );
	if ( align ) {
		// New declaration — no raw, formatted with space.
		decls.push( { prop: 'text-align', value: align, raw: null } );
	}
	return joinDecls( decls );
}

/** Read the current text-align value (or ''). */
export function getTextAlign( style ) {
	const found = parseDecls( style ).find( ( d ) => d.prop === 'text-align' );
	return found ? found.value : '';
}

/** Ordered list of {key, field} for renderable option widgets. */
export function renderableOptions( typeSchema, { exclude = [] } = {} ) {
	const options = ( typeSchema && typeSchema.options ) || {};
	const renderable = [ 'checkbox', 'select', 'text', 'number', 'textarea' ];
	return Object.keys( options )
		.filter( ( key ) => ! exclude.includes( key ) && renderable.includes( options[ key ] && options[ key ].type ) )
		.map( ( key ) => ( { key, field: options[ key ] } ) );
}
