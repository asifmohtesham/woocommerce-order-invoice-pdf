import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, RichText, BlockControls, InspectorControls } from '@wordpress/block-editor';
import { ToolbarGroup, ToolbarButton, PanelBody, ToggleControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * A true multi-row data table, modelled on core/table: the table is a 2D
 * `rows` attribute (each cell is { content, tag }), edited as RichText cells.
 * This complements woi/columns (a single layout row whose cells hold blocks):
 * woi/table is for tabular data (text + {{tokens}}) with header cells + borders.
 *
 * save() bakes the whole <table> into the block's inner HTML (a static block),
 * so do_blocks returns it verbatim for the PDF. Output is mPDF-safe (real
 * table/tr/td/th + inline border/padding) and survives the kses allowlist.
 */
const DEFAULT_ROWS = [
	{ cells: [ { content: '', tag: 'td' }, { content: '', tag: 'td' } ] },
	{ cells: [ { content: '', tag: 'td' }, { content: '', tag: 'td' } ] },
];

function newCell() {
	return { content: '', tag: 'td' };
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
			const headerOn = !! ( rows[ 0 ] && rows[ 0 ].cells.length && rows[ 0 ].cells.every( ( cell ) => 'th' === cell.tag ) );

			const updateCell = ( r, c, content ) => {
				setAttributes( {
					rows: rows.map( ( row, ri ) => ri !== r ? row : {
						cells: row.cells.map( ( cell, ci ) => ci !== c ? cell : { ...cell, content } ),
					} ),
				} );
			};

			// delta 0 = before the selected row, 1 = after. Selection follows the new row.
			const insertRow = ( delta ) => {
				const next = rows.slice();
				next.splice( sel.r + delta, 0, { cells: Array.from( { length: colCount || 1 }, newCell ) } );
				setAttributes( { rows: next } );
				setSel( { r: sel.r + delta, c: sel.c } );
			};
			const deleteRow = () => {
				if ( rows.length <= 1 ) { return; }
				const next = rows.slice();
				next.splice( sel.r, 1 );
				setAttributes( { rows: next } );
				setSel( { r: Math.max( 0, sel.r - 1 ), c: sel.c } );
			};
			const insertColumn = ( delta ) => {
				const at = sel.c + delta;
				setAttributes( {
					rows: rows.map( ( row ) => {
						const cells = row.cells.slice();
						cells.splice( at, 0, newCell() );
						return { cells };
					} ),
				} );
				setSel( { r: sel.r, c: at } );
			};
			const deleteColumn = () => {
				if ( colCount <= 1 ) { return; }
				setAttributes( {
					rows: rows.map( ( row ) => {
						const cells = row.cells.slice();
						cells.splice( sel.c, 1 );
						return { cells };
					} ),
				} );
				setSel( { r: sel.r, c: Math.max( 0, sel.c - 1 ) } );
			};
			// Toggle the first row's cells between <th> and <td>.
			const toggleHeaderRow = () => {
				setAttributes( {
					rows: rows.map( ( row, ri ) => ri !== 0 ? row : {
						cells: row.cells.map( ( cell ) => ( { ...cell, tag: headerOn ? 'td' : 'th' } ) ),
					} ),
				} );
			};

			const cellStyle = {
				border: bordered ? '1px solid #000' : '1px solid #ddd',
				padding: '2px 6px',
				verticalAlign: 'top',
			};

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
					</BlockControls>
					<InspectorControls>
						<PanelBody title={ __( 'Table', 'woocommerce-orders-invoice-pdf' ) }>
							<ToggleControl
								label={ __( 'Header row', 'woocommerce-orders-invoice-pdf' ) }
								checked={ headerOn }
								onChange={ toggleHeaderRow }
							/>
							<ToggleControl
								label={ __( 'Bordered', 'woocommerce-orders-invoice-pdf' ) }
								checked={ bordered }
								onChange={ ( v ) => setAttributes( { bordered: v } ) }
							/>
						</PanelBody>
					</InspectorControls>
					<table { ...useBlockProps( { style: { borderCollapse: 'collapse', width: '100%' } } ) }>
						<tbody>
							{ rows.map( ( row, r ) => (
								<tr key={ r }>
									{ row.cells.map( ( cell, c ) => {
										const Tag = 'th' === cell.tag ? 'th' : 'td';
										return (
											<Tag key={ c } style={ cellStyle }>
												<RichText
													tagName="span"
													value={ cell.content }
													onChange={ ( content ) => updateCell( r, c, content ) }
													onFocus={ () => setSel( { r, c } ) }
													placeholder={ __( 'Cell', 'woocommerce-orders-invoice-pdf' ) }
												/>
											</Tag>
										);
									} ) }
								</tr>
							) ) }
						</tbody>
					</table>
				</>
			);
		},
		save( { attributes } ) {
			const { rows, bordered } = attributes;
			const cellStyle = bordered ? { border: '0.5pt solid #000', padding: '2px 6px', verticalAlign: 'top' } : { padding: '2px 6px', verticalAlign: 'top' };
			return (
				<table { ...useBlockProps.save( { style: { borderCollapse: 'collapse', width: '100%' } } ) }>
					<tbody>
						{ rows.map( ( row, r ) => (
							<tr key={ r }>
								{ row.cells.map( ( cell, c ) => {
									const Tag = 'th' === cell.tag ? 'th' : 'td';
									return (
										<Tag key={ c } style={ cellStyle }>
											<RichText.Content tagName="span" value={ cell.content } />
										</Tag>
									);
								} ) }
							</tr>
						) ) }
					</tbody>
				</table>
			);
		},
	} );
}
