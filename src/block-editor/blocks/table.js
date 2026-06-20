import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, RichText, BlockControls, InspectorControls } from '@wordpress/block-editor';
import { ToolbarGroup, ToolbarButton, PanelBody, ToggleControl, SelectControl, ColorPalette } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * A true multi-row data table, modelled on core/table. The table is a 2D `rows`
 * attribute; each row has a `section` ('head'|'body'|'foot') and `cells`, each
 * cell `{ content, tag, align, bg, colspan, rowspan, merged }`. Cells are edited
 * as RichText (text + {{tokens}} + inline formatting).
 *
 * Spans: a cell with colspan/rowspan > 1 covers neighbouring grid positions; the
 * covered cells carry `merged: true` and are not rendered (they stay in the array
 * to keep rows rectangular so column ops stay index-based). save() bakes the full
 * mPDF-safe <table> (thead/tbody/tfoot + inline styles + colspan/rowspan) into the
 * block HTML; do_blocks returns it for the PDF; output survives the kses allowlist.
 */
const CELL_COLORS = [
	{ name: __( 'Light grey', 'woocommerce-orders-invoice-pdf' ), color: '#f3f4f5' },
	{ name: __( 'Grey', 'woocommerce-orders-invoice-pdf' ), color: '#e0e0e0' },
	{ name: __( 'Black', 'woocommerce-orders-invoice-pdf' ), color: '#000000' },
	{ name: __( 'White', 'woocommerce-orders-invoice-pdf' ), color: '#ffffff' },
];

const DEFAULT_ROWS = [
	{ section: 'body', cells: [ { content: '', tag: 'td' }, { content: '', tag: 'td' } ] },
	{ section: 'body', cells: [ { content: '', tag: 'td' }, { content: '', tag: 'td' } ] },
];

function newCell() {
	return { content: '', tag: 'td' };
}

const cs = ( cell ) => cell.colspan || 1;
const rs = ( cell ) => cell.rowspan || 1;

// Inline cell <td>/<th> props (style + colSpan/rowSpan). `editing` shows a faint
// guide border on unbordered cells so the canvas stays usable; the PDF gets none.
function cellProps( cell, bordered, editing ) {
	const style = {};
	if ( bordered ) {
		style.border = '0.5pt solid #000';
	} else if ( editing ) {
		style.border = '1px solid #eee';
	}
	style.padding = '2px 6px';
	style.verticalAlign = 'top';
	if ( cell.align ) { style.textAlign = cell.align; }
	if ( cell.bg ) { style.backgroundColor = cell.bg; }
	const props = { style };
	if ( cs( cell ) > 1 ) { props.colSpan = cs( cell ); }
	if ( rs( cell ) > 1 ) { props.rowSpan = rs( cell ); }
	return props;
}

// Group rows by section, preserving array order within each group.
function bySection( rows ) {
	return {
		head: rows.map( ( row, i ) => ( { row, i } ) ).filter( ( x ) => 'head' === x.row.section ),
		body: rows.map( ( row, i ) => ( { row, i } ) ).filter( ( x ) => 'head' !== x.row.section && 'foot' !== x.row.section ),
		foot: rows.map( ( row, i ) => ( { row, i } ) ).filter( ( x ) => 'foot' === x.row.section ),
	};
}

export function registerTableBlock() {
	registerBlockType( 'woi/table', {
		apiVersion: 2,
		title: __( 'Table', 'woocommerce-orders-invoice-pdf' ),
		category: 'woi-invoice',
		icon: 'editor-table',
		attributes: {
			rows: { type: 'array', default: DEFAULT_ROWS },
			bordered: { type: 'boolean', default: true },
		},
		supports: { html: false, reusable: false },
		edit( { attributes, setAttributes } ) {
			const { rows, bordered } = attributes;
			const [ sel, setSel ] = useState( { r: 0, c: 0 } );
			const colCount = rows.length ? rows[ 0 ].cells.length : 0;
			const selCell = ( rows[ sel.r ] && rows[ sel.r ].cells[ sel.c ] ) || null;
			const headerOn = !! ( rows[ 0 ] && 'head' === rows[ 0 ].section );
			const footerOn = !! ( rows.length && 'foot' === rows[ rows.length - 1 ].section );

			const setRows = ( next ) => setAttributes( { rows: next } );
			const mapCell = ( r, c, fn ) => rows.map( ( row, ri ) => ri !== r ? row : {
				...row,
				cells: row.cells.map( ( cell, ci ) => ci !== c ? cell : fn( cell ) ),
			} );

			const updateCell = ( r, c, content ) => setRows( mapCell( r, c, ( cell ) => ( { ...cell, content } ) ) );
			const setCellAttr = ( patch ) => setRows( mapCell( sel.r, sel.c, ( cell ) => ( { ...cell, ...patch } ) ) );

			// delta 0 = before the selected row, 1 = after. Selection follows the new row.
			const insertRow = ( delta ) => {
				const next = rows.slice();
				next.splice( sel.r + delta, 0, { section: 'body', cells: Array.from( { length: colCount || 1 }, newCell ) } );
				setRows( next );
				setSel( { r: sel.r + delta, c: sel.c } );
			};
			const deleteRow = () => {
				if ( rows.length <= 1 ) { return; }
				const next = rows.slice();
				next.splice( sel.r, 1 );
				setRows( next );
				setSel( { r: Math.max( 0, sel.r - 1 ), c: sel.c } );
			};
			const insertColumn = ( delta ) => {
				const at = sel.c + delta;
				setRows( rows.map( ( row ) => {
					const cells = row.cells.slice();
					cells.splice( at, 0, newCell() );
					return { ...row, cells };
				} ) );
				setSel( { r: sel.r, c: at } );
			};
			const deleteColumn = () => {
				if ( colCount <= 1 ) { return; }
				setRows( rows.map( ( row ) => {
					const cells = row.cells.slice();
					cells.splice( sel.c, 1 );
					return { ...row, cells };
				} ) );
				setSel( { r: sel.r, c: Math.max( 0, sel.c - 1 ) } );
			};

			// Merge the selected cell with its right / lower neighbour. Kept simple
			// and correct: only single (colspan=rowspan=1, non-merged) neighbours
			// merge, and a horizontal span keeps rowspan=1 (vice-versa), so spans
			// stay rectangular.
			const mergeRight = () => {
				if ( ! selCell || rs( selCell ) !== 1 ) { return; }
				const ni = sel.c + cs( selCell );
				const n = rows[ sel.r ].cells[ ni ];
				if ( ! n || n.merged || cs( n ) !== 1 || rs( n ) !== 1 ) { return; }
				setRows( rows.map( ( row, ri ) => ri !== sel.r ? row : {
					...row,
					cells: row.cells.map( ( cell, ci ) => {
						if ( ci === sel.c ) { return { ...cell, colspan: cs( cell ) + 1 }; }
						if ( ci === ni ) { return { ...cell, merged: true }; }
						return cell;
					} ),
				} ) );
			};
			const mergeDown = () => {
				if ( ! selCell || cs( selCell ) !== 1 ) { return; }
				const nr = sel.r + rs( selCell );
				const belowRow = rows[ nr ];
				const n = belowRow && belowRow.cells[ sel.c ];
				if ( ! n || n.merged || cs( n ) !== 1 || rs( n ) !== 1 ) { return; }
				setRows( rows.map( ( row, ri ) => {
					if ( ri === sel.r ) {
						return { ...row, cells: row.cells.map( ( cell, ci ) => ci !== sel.c ? cell : { ...cell, rowspan: rs( cell ) + 1 } ) };
					}
					if ( ri === nr ) {
						return { ...row, cells: row.cells.map( ( cell, ci ) => ci !== sel.c ? cell : { ...cell, merged: true } ) };
					}
					return row;
				} ) );
			};
			const unmerge = () => {
				if ( ! selCell ) { return; }
				const spanC = cs( selCell );
				const spanR = rs( selCell );
				if ( spanC === 1 && spanR === 1 ) { return; }
				setRows( rows.map( ( row, ri ) => {
					// The merged cells covered by a horizontal span are in the same row;
					// a vertical span covers the same column index in the rows below.
					const inHoriz = ri === sel.r && spanC > 1;
					const inVert = spanR > 1 && ri > sel.r && ri < sel.r + spanR;
					if ( ! inHoriz && ! inVert && ri !== sel.r ) { return row; }
					return { ...row, cells: row.cells.map( ( cell, ci ) => {
						if ( ri === sel.r && ci === sel.c ) { return { ...cell, colspan: 1, rowspan: 1 }; }
						if ( inHoriz && ci > sel.c && ci < sel.c + spanC ) { return { ...cell, merged: false }; }
						if ( inVert && ci === sel.c ) { return { ...cell, merged: false }; }
						return cell;
					} ) };
				} ) );
			};

			// Toggle row 0 head / last row foot (and head cells → th).
			const toggleHeader = () => setRows( rows.map( ( row, ri ) => ri !== 0 ? row : {
				...row,
				section: headerOn ? 'body' : 'head',
				cells: row.cells.map( ( cell ) => ( { ...cell, tag: headerOn ? 'td' : 'th' } ) ),
			} ) );
			const toggleFooter = () => {
				const last = rows.length - 1;
				setRows( rows.map( ( row, ri ) => ri !== last ? row : { ...row, section: footerOn ? 'body' : 'foot' } ) );
			};

			const groups = bySection( rows );
			const renderRows = ( list ) => list.map( ( { row, i } ) => (
				<tr key={ i }>
					{ row.cells.map( ( cell, c ) => {
						if ( cell.merged ) { return null; }
						const Tag = 'th' === cell.tag ? 'th' : 'td';
						return (
							<Tag key={ c } { ...cellProps( cell, bordered, true ) }>
								<RichText
									tagName="span"
									value={ cell.content }
									onChange={ ( content ) => updateCell( i, c, content ) }
									onFocus={ () => setSel( { r: i, c } ) }
									placeholder={ __( 'Cell', 'woocommerce-orders-invoice-pdf' ) }
								/>
							</Tag>
						);
					} ) }
				</tr>
			) );

			return (
				<>
					<BlockControls>
						<ToolbarGroup>
							<ToolbarButton icon="table-row-before" title={ __( 'Insert row above', 'woocommerce-orders-invoice-pdf' ) } onClick={ () => insertRow( 0 ) } />
							<ToolbarButton icon="table-row-after" title={ __( 'Insert row below', 'woocommerce-orders-invoice-pdf' ) } onClick={ () => insertRow( 1 ) } />
							<ToolbarButton icon="table-row-delete" title={ __( 'Delete row', 'woocommerce-orders-invoice-pdf' ) } onClick={ deleteRow } />
						</ToolbarGroup>
						<ToolbarGroup>
							<ToolbarButton icon="table-col-before" title={ __( 'Insert column left', 'woocommerce-orders-invoice-pdf' ) } onClick={ () => insertColumn( 0 ) } />
							<ToolbarButton icon="table-col-after" title={ __( 'Insert column right', 'woocommerce-orders-invoice-pdf' ) } onClick={ () => insertColumn( 1 ) } />
							<ToolbarButton icon="table-col-delete" title={ __( 'Delete column', 'woocommerce-orders-invoice-pdf' ) } onClick={ deleteColumn } />
						</ToolbarGroup>
						<ToolbarGroup>
							<ToolbarButton title={ __( 'Merge right', 'woocommerce-orders-invoice-pdf' ) } onClick={ mergeRight }>{ __( 'Merge →', 'woocommerce-orders-invoice-pdf' ) }</ToolbarButton>
							<ToolbarButton title={ __( 'Merge down', 'woocommerce-orders-invoice-pdf' ) } onClick={ mergeDown }>{ __( 'Merge ↓', 'woocommerce-orders-invoice-pdf' ) }</ToolbarButton>
							<ToolbarButton title={ __( 'Unmerge', 'woocommerce-orders-invoice-pdf' ) } onClick={ unmerge }>{ __( 'Unmerge', 'woocommerce-orders-invoice-pdf' ) }</ToolbarButton>
						</ToolbarGroup>
					</BlockControls>
					<InspectorControls>
						<PanelBody title={ __( 'Table', 'woocommerce-orders-invoice-pdf' ) }>
							<ToggleControl label={ __( 'Header row', 'woocommerce-orders-invoice-pdf' ) } checked={ headerOn } onChange={ toggleHeader } />
							<ToggleControl label={ __( 'Footer row', 'woocommerce-orders-invoice-pdf' ) } checked={ footerOn } onChange={ toggleFooter } />
							<ToggleControl label={ __( 'Bordered', 'woocommerce-orders-invoice-pdf' ) } checked={ bordered } onChange={ ( v ) => setAttributes( { bordered: v } ) } />
						</PanelBody>
						{ selCell && ! selCell.merged ? (
							<PanelBody title={ __( 'Selected cell', 'woocommerce-orders-invoice-pdf' ) }>
								<SelectControl
									label={ __( 'Text align', 'woocommerce-orders-invoice-pdf' ) }
									value={ selCell.align || '' }
									options={ [
										{ label: __( 'Default', 'woocommerce-orders-invoice-pdf' ), value: '' },
										{ label: __( 'Left', 'woocommerce-orders-invoice-pdf' ), value: 'left' },
										{ label: __( 'Center', 'woocommerce-orders-invoice-pdf' ), value: 'center' },
										{ label: __( 'Right', 'woocommerce-orders-invoice-pdf' ), value: 'right' },
									] }
									onChange={ ( v ) => setCellAttr( { align: v } ) }
								/>
								<p style={ { margin: '12px 0 4px' } }>{ __( 'Cell background', 'woocommerce-orders-invoice-pdf' ) }</p>
								<ColorPalette value={ selCell.bg || '' } colors={ CELL_COLORS } onChange={ ( color ) => setCellAttr( { bg: color || '' } ) } />
							</PanelBody>
						) : null }
					</InspectorControls>
					<table { ...useBlockProps( { style: { borderCollapse: 'collapse', width: '100%' } } ) }>
						{ groups.head.length ? <thead>{ renderRows( groups.head ) }</thead> : null }
						{ groups.body.length ? <tbody>{ renderRows( groups.body ) }</tbody> : null }
						{ groups.foot.length ? <tfoot>{ renderRows( groups.foot ) }</tfoot> : null }
					</table>
				</>
			);
		},
		save( { attributes } ) {
			const { rows, bordered } = attributes;
			const groups = bySection( rows );
			const renderRows = ( list ) => list.map( ( { row, i } ) => (
				<tr key={ i }>
					{ row.cells.map( ( cell, c ) => {
						if ( cell.merged ) { return null; }
						const Tag = 'th' === cell.tag ? 'th' : 'td';
						return (
							<Tag key={ c } { ...cellProps( cell, bordered, false ) }>
								<RichText.Content tagName="span" value={ cell.content } />
							</Tag>
						);
					} ) }
				</tr>
			) );
			return (
				<table { ...useBlockProps.save( { style: { borderCollapse: 'collapse', width: '100%' } } ) }>
					{ groups.head.length ? <thead>{ renderRows( groups.head ) }</thead> : null }
					{ groups.body.length ? <tbody>{ renderRows( groups.body ) }</tbody> : null }
					{ groups.foot.length ? <tfoot>{ renderRows( groups.foot ) }</tfoot> : null }
				</table>
			);
		},
	} );
}
