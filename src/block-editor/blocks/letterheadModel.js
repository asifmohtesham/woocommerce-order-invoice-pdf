/**
 * Pure model for the Letterhead block. NO @wordpress/* imports — kept separate
 * so Jest can unit-test it directly (mirrors contactStripModel.js).
 */

// The four text elements, in EN-then-AR reading order, with their value tokens.
export const LH_TEXT_FIELDS = [
	{ key: 'name_en',    label: 'Company name', token: '{{shop_name}}' },
	{ key: 'address_en', label: 'Address',      token: '{{shop_address}}' },
	{ key: 'name_ar',    label: 'Company name (AR)', token: '{{shop_name_ar}}' },
	{ key: 'address_ar', label: 'Address (AR)',      token: '{{shop_address_ar}}' },
];

// Default config — mirrors woi_pdf_default_letterhead() on the PHP side.
export const LH_DEFAULT = {
	swapText: false,
	logoWidth: 0,
	elements: {
		name_en:    { visible: true, align: 'left',  bold: true,  fontSize: 0, color: '' },
		address_en: { visible: true, align: 'left',  bold: false, fontSize: 0, color: '' },
		name_ar:    { visible: true, align: 'right', bold: true,  fontSize: 0, color: '' },
		address_ar: { visible: true, align: 'right', bold: false, fontSize: 0, color: '' },
		logo:       { visible: true },
	},
};

// PDF-parity class for a text element: the company name carries `woi-co-name`,
// address lines carry `woi-co-lines` — the same classes the PDF emits
// (TemplateTokens::letterhead_text_cell). Keying on these lets the shared accent
// (optionsCss) + document CSS colour/size the name identically in editor and PDF;
// without the class an unset colour rendered black in the editor but accent in
// the PDF. Field keys are `name_*` / `address_*` (see LH_TEXT_FIELDS).
export function lhFieldClass( key ) {
	return String( key ).startsWith( 'name' ) ? 'woi-co-name' : 'woi-co-lines';
}

// React inline-style object for a text element (set properties only).
export function lhValueStyle( el ) {
	const s = {};
	if ( el.bold ) { s.fontWeight = 'bold'; }
	if ( el.fontSize ) { s.fontSize = el.fontSize + 'px'; }
	if ( el.color ) { s.color = el.color; }
	return s;
}
