/**
 * Pure presentational-style helpers for the shared Appearance system. NO
 * @wordpress/* imports — kept separate from appearance.js (which imports
 * @wordpress/components for the panel) so jest can unit-test the style mapping.
 *
 * Inline-style based ON PURPOSE: mPDF does not load the theme stylesheet, so
 * WordPress's palette/preset CLASSES would render unstyled; inline styles always
 * render and are kses-safe (safecss_filter_attr allows these properties). Every
 * attribute defaults empty/0, so a block with no appearance set serialises
 * exactly as before (no block-validation break).
 */
export const APPEARANCE_ATTRS = {
	align: { type: 'string', default: '' },
	weight: { type: 'string', default: '' },
	fontSize: { type: 'number', default: 0 },
	color: { type: 'string', default: '' },
	bg: { type: 'string', default: '' },
	padding: { type: 'number', default: 0 },
	margin: { type: 'number', default: 0 },
	width: { type: 'string', default: '' },
};

// Build the inline style object from the appearance attributes (set props only).
export function appearanceStyle( a ) {
	const s = {};
	if ( a.align ) { s.textAlign = a.align; }
	if ( a.weight ) { s.fontWeight = a.weight; }
	if ( a.fontSize ) { s.fontSize = a.fontSize + 'px'; }
	if ( a.color ) { s.color = a.color; }
	if ( a.bg ) { s.backgroundColor = a.bg; }
	if ( a.padding ) { s.padding = a.padding + 'px'; }
	if ( a.margin ) { s.margin = a.margin + 'px'; }
	if ( a.width ) { s.width = a.width; }
	return s;
}

// Spread onto an element's props: adds { style } only when something is set, so
// an unstyled block produces the identical markup it did before this feature.
export function appearanceProps( attributes ) {
	const style = appearanceStyle( attributes );
	return Object.keys( style ).length ? { style } : {};
}
