/**
 * Pure model for the Contact strip block. NO @wordpress/* imports — kept
 * separate from contactStrip.js (which needs @wordpress/components) so Jest can
 * unit-test the data helpers directly, mirroring appearanceStyle.js.
 */

// field -> { editor label, dynamic value token }.
export const CONTACT_FIELDS = {
	trn:   { label: 'TRN',   token: '{{trn}}' },
	tel:   { label: 'Tel',   token: '{{shop_phone}}' },
	email: { label: 'Email', token: '{{shop_email}}' },
};

// Default layout — reproduces the historical TRN-left / Tel-centre / Email-right.
export const CONTACT_DEFAULT_ITEMS = [
	{ field: 'trn',   visible: true, align: 'left',   bold: false, fontSize: 0, color: '' },
	{ field: 'tel',   visible: true, align: 'center', bold: false, fontSize: 0, color: '' },
	{ field: 'email', visible: true, align: 'right',  bold: false, fontSize: 0, color: '' },
];

// Move items[from] to index `to`, returning a NEW array (no mutation).
export function reorder( items, from, to ) {
	const next = items.slice();
	if ( from < 0 || from >= next.length || to < 0 || to >= next.length || from === to ) {
		return next;
	}
	const [ moved ] = next.splice( from, 1 );
	next.splice( to, 0, moved );
	return next;
}

// React inline-style object for a value span (set properties only).
export function valueStyle( item ) {
	const s = {};
	if ( item.bold ) { s.fontWeight = 'bold'; }
	if ( item.fontSize ) { s.fontSize = item.fontSize + 'px'; }
	if ( item.color ) { s.color = item.color; }
	return s;
}
