// JS mirror of PHP woi_pdf_visual_options_css() (woi-pdf-functions.php). The PDF
// gets that override injected by the wrapper; the editor canvas needs the same
// flat-selector overrides so changing a Document option live-refreshes the
// preview. Browsers honour display:none on table cells (mPDF doesn't), so the
// canvas can hide the thumbnail column / Arabic spans via CSS — the PDF path
// removes them at the source instead.

const ACCENTS = { navy: '#140858', red: '#9E0A0E', mono: '#3A3A3A' };

export function optionsCss( opts ) {
	const o = opts || {};
	const c = ACCENTS[ o.accent ] || ACCENTS.navy;
	const css = [];

	// Accent (colour + section rules).
	css.push(
		'.woi-co-name,.woi-title-en,.woi-doc-title .title-en,.woi-party-label,.woi-sub-label,' +
		'tr.grand-total th,tr.grand-total td,tr.grand-total th .woi-lbl-secondary{color:' + c + '}'
	);
	css.push( 'table.woi-contact{border-top-color:' + c + '}' );
	css.push( '.order-details thead th{border-top-color:' + c + ';border-bottom-color:' + c + '}' );
	css.push( 'table.woi-parties td.woi-party{border-top-color:' + c + '}' );
	css.push( 'tr.grand-total th,tr.grand-total td{border-top-color:' + c + ';border-bottom-color:' + c + '}' );

	// Font family.
	if ( 'serif' === o.font ) {
		css.push( '.woi-title-en,.woi-doc-title .title-en,.woi-co-name,.woi-party-name,.woi-sig-cap{font-family:"dejavuserif",serif}' );
	} else if ( 'mono' === o.font ) {
		css.push( 'body{font-family:"dejavusansmono",monospace}' );
	}

	// Table density.
	if ( 'compact' === o.density ) {
		css.push( '.order-details tbody td{padding:1mm 2mm}' );
		css.push( 'td.thumbnail img{width:10mm !important}' );
	}

	// Bilingual off — hide every secondary / Arabic-only element.
	if ( 'off' === o.arabic ) {
		css.push( '.woi-lbl-secondary,.woi-title-ar,.woi-lh-ar,.woi-terms p.ar{display:none}' );
	}

	// Left letterhead variant.
	if ( 'left' === o.header ) {
		css.push( '.woi-letterhead .woi-lh-mark{text-align:left}' );
	}

	// Thumbnails off — hide the column (canvas only; the PDF drops it at source).
	if ( 'off' === o.thumbs ) {
		css.push( '.order-details .thumbnail{display:none}' );
	}

	return css.join( '\n' );
}
