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

	// Column borders — vertical gridlines on the line-items table.
	if ( 'on' === o.borders ) {
		css.push( '.order-details thead th,.order-details tbody td{border-left:0.5pt solid #D9D4C9;border-right:0.5pt solid #D9D4C9}' );
	}

	// Striped rows — zebra background on alternating line items.
	if ( 'on' === o.stripes ) {
		css.push( '.order-details tbody tr:nth-child(even) td{background-color:#F6F3EC}' );
	}

	// Row colour — custom text colour for all line-item body cells. Setting it on
	// the td cascades into the inner name/meta spans; the two muted-by-default
	// columns (td.position / td.tax_rate) need a higher-specificity override. Only
	// a #rgb / #rrggbb hex is honoured so the value can never inject markup.
	if ( o.row_color && /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test( o.row_color ) ) {
		css.push( '.order-details tbody td,.order-details tbody td.position,.order-details tbody td.tax_rate{color:' + o.row_color + '}' );
	}

	return css.join( '\n' );
}
