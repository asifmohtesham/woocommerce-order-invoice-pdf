import { registerBlockType, registerBlockVariation, createBlock } from '@wordpress/blocks';
import { useBlockProps, useInnerBlocksProps, InnerBlocks, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, RangeControl, SelectControl, ColorPalette, ToggleControl, Button } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

// Max cells in a Columns (table row) block. Mirrors core/columns' upper bound.
const MAX_COLUMNS = 12;

// Curated cell-background swatches (custom colour also available). mPDF honours
// background-color on a <td>.
const CELL_COLORS = [
	{ name: __( 'Light grey', 'woocommerce-orders-invoice-pdf' ), color: '#f3f4f5' },
	{ name: __( 'Grey', 'woocommerce-orders-invoice-pdf' ), color: '#e0e0e0' },
	{ name: __( 'Black', 'woocommerce-orders-invoice-pdf' ), color: '#000000' },
	{ name: __( 'White', 'woocommerce-orders-invoice-pdf' ), color: '#ffffff' },
];

/**
 * Composition blocks. woi/columns renders a single-row table (.woi-row, already
 * styled in visual-document.css); its cells are woi/column blocks, each a <td>
 * drop-zone that accepts any invoice block. edit() uses a friendly flex/div
 * layout for editing; save() emits the real mPDF-safe table — Gutenberg only
 * validates save() markup, so the divergence is intentional and allowed.
 */
export function registerColumnsBlocks() {
	// Child: one table cell holding arbitrary blocks. Optional width (%),
	// vertical-align and background colour are set via the sidebar; save() bakes
	// only the set values into the <td> inline style (mPDF honours td width %,
	// vertical-align and background-color; kses allows style on td). When ALL are
	// unset save() emits a plain <td>, identical to the original — so existing
	// saved columns stay valid (purely additive attributes).
	registerBlockType( 'woi/column', {
		apiVersion: 2,
		title: __( 'Column', 'woocommerce-orders-invoice-pdf' ),
		category: 'woi-invoice',
		parent: [ 'woi/columns' ],
		icon: 'columns',
		attributes: {
			width: { type: 'string', default: '' },
			valign: { type: 'string', default: '' },
			bg: { type: 'string', default: '' },
			align: { type: 'string', default: '' },
			border: { type: 'boolean', default: false },
			pad: { type: 'number', default: 0 },
		},
		supports: { html: false, reusable: false, inserter: false },
		edit( { clientId, attributes, setAttributes } ) {
			const { width, valign, bg, align, border, pad } = attributes;
			const pct = width ? ( parseInt( width, 10 ) || 0 ) : 0;
			const rootId = useSelect(
				( select ) => select( 'core/block-editor' ).getBlockRootClientId( clientId ),
				[ clientId ]
			);
			const { duplicateBlocks, moveBlocksUp, moveBlocksDown } = useDispatch( 'core/block-editor' );
			const blockProps = useBlockProps( {
				style: {
					// Preview the chosen width/alignment/background/border; auto when unset.
					flex: width ? '0 0 ' + width : '1',
					minWidth: '60px',
					// Solid black previews the real cell border; dashed is the editor aid.
					border: border ? '1px solid #000' : '1px dashed #c3c4c7',
					padding: pad ? pad + 'px' : '8px',
					verticalAlign: valign || 'top',
					textAlign: align || undefined,
					backgroundColor: bg || undefined,
				},
			} );
			const innerProps = useInnerBlocksProps( blockProps, { templateLock: false } );
			return (
				<>
					<InspectorControls>
						<PanelBody title={ __( 'Column', 'woocommerce-orders-invoice-pdf' ) }>
							<RangeControl
								label={ __( 'Width (%) — 0 = auto', 'woocommerce-orders-invoice-pdf' ) }
								value={ pct }
								onChange={ ( v ) => setAttributes( { width: v ? v + '%' : '' } ) }
								min={ 0 }
								max={ 100 }
							/>
							<SelectControl
								label={ __( 'Vertical align', 'woocommerce-orders-invoice-pdf' ) }
								value={ valign }
								options={ [
									{ label: __( 'Default (top)', 'woocommerce-orders-invoice-pdf' ), value: '' },
									{ label: __( 'Top', 'woocommerce-orders-invoice-pdf' ), value: 'top' },
									{ label: __( 'Middle', 'woocommerce-orders-invoice-pdf' ), value: 'middle' },
									{ label: __( 'Bottom', 'woocommerce-orders-invoice-pdf' ), value: 'bottom' },
								] }
								onChange={ ( v ) => setAttributes( { valign: v } ) }
							/>
							<SelectControl
								label={ __( 'Text align', 'woocommerce-orders-invoice-pdf' ) }
								value={ align }
								options={ [
									{ label: __( 'Default', 'woocommerce-orders-invoice-pdf' ), value: '' },
									{ label: __( 'Left', 'woocommerce-orders-invoice-pdf' ), value: 'left' },
									{ label: __( 'Center', 'woocommerce-orders-invoice-pdf' ), value: 'center' },
									{ label: __( 'Right', 'woocommerce-orders-invoice-pdf' ), value: 'right' },
								] }
								onChange={ ( v ) => setAttributes( { align: v } ) }
							/>
							<ToggleControl
								label={ __( 'Cell border', 'woocommerce-orders-invoice-pdf' ) }
								checked={ border }
								onChange={ ( v ) => setAttributes( { border: v } ) }
							/>
							<p style={ { margin: '12px 0 4px' } }>{ __( 'Cell background', 'woocommerce-orders-invoice-pdf' ) }</p>
							<ColorPalette
								value={ bg }
								colors={ CELL_COLORS }
								onChange={ ( c ) => setAttributes( { bg: c || '' } ) }
							/>
							<RangeControl
								label={ __( 'Cell padding (px) — 0 = default', 'woocommerce-orders-invoice-pdf' ) }
								value={ pad }
								onChange={ ( v ) => setAttributes( { pad: v || 0 } ) }
								min={ 0 }
								max={ 24 }
							/>
							<div style={ { display: 'flex', gap: '4px', marginTop: '8px', flexWrap: 'wrap' } }>
								<Button variant="secondary" onClick={ () => duplicateBlocks( [ clientId ] ) }>{ __( 'Duplicate', 'woocommerce-orders-invoice-pdf' ) }</Button>
								<Button variant="secondary" onClick={ () => moveBlocksUp( [ clientId ], rootId ) }>{ __( 'Move ←', 'woocommerce-orders-invoice-pdf' ) }</Button>
								<Button variant="secondary" onClick={ () => moveBlocksDown( [ clientId ], rootId ) }>{ __( 'Move →', 'woocommerce-orders-invoice-pdf' ) }</Button>
							</div>
						</PanelBody>
					</InspectorControls>
					<div { ...innerProps } />
				</>
			);
		},
		save( { attributes } ) {
			const { width, valign, bg, align, border, pad } = attributes;
			const style = {};
			if ( width ) { style.width = width; }
			if ( valign ) { style.verticalAlign = valign; }
			if ( align ) { style.textAlign = align; }
			if ( bg ) { style.backgroundColor = bg; }
			if ( border ) { style.border = '0.5pt solid #000'; }
			if ( pad ) { style.padding = pad + 'px'; }
			const blockProps = useBlockProps.save( Object.keys( style ).length ? { style } : {} );
			const innerProps = useInnerBlocksProps.save( blockProps );
			return <td { ...innerProps } />;
		},
	} );

	// Parent: a one-row table whose cells are woi/column children. A sidebar
	// control sets the column count (1..12), adding/removing woi/column children
	// to match — the same pattern core/columns uses (woi/column is inserter:false,
	// so this control is the way to add columns).
	registerBlockType( 'woi/columns', {
		apiVersion: 2,
		title: __( 'Columns (table row)', 'woocommerce-orders-invoice-pdf' ),
		category: 'woi-invoice',
		icon: 'columns',
		supports: { html: false, reusable: false },
		edit( { clientId } ) {
			const blockProps = useBlockProps( { style: { display: 'flex', gap: '8px', alignItems: 'stretch' } } );
			const innerProps = useInnerBlocksProps( blockProps, {
				allowedBlocks: [ 'woi/column' ],
				template: [ [ 'woi/column' ], [ 'woi/column' ] ],
				orientation: 'horizontal',
			} );

			const count = useSelect(
				( select ) => select( 'core/block-editor' ).getBlockCount( clientId ),
				[ clientId ]
			);
			const { getBlocks } = useSelect(
				( select ) => ( { getBlocks: select( 'core/block-editor' ).getBlocks } ),
				[]
			);
			const { replaceInnerBlocks, updateBlockAttributes } = useDispatch( 'core/block-editor' );

			const setColumns = ( next ) => {
				const target = Math.max( 1, Math.min( MAX_COLUMNS, next || 1 ) );
				const current = getBlocks( clientId );
				if ( target === current.length ) { return; }
				const blocks = target > current.length
					? current.concat( Array.from( { length: target - current.length }, () => createBlock( 'woi/column' ) ) )
					: current.slice( 0, target );
				replaceInnerBlocks( clientId, blocks, false );
			};

			// Clear every child column's explicit width so they share the row equally.
			const equalizeWidths = () => {
				const ids = getBlocks( clientId ).map( ( b ) => b.clientId );
				if ( ids.length ) { updateBlockAttributes( ids, { width: '' } ); }
			};

			return (
				<>
					<InspectorControls>
						<PanelBody title={ __( 'Columns', 'woocommerce-orders-invoice-pdf' ) }>
							<RangeControl
								label={ __( 'Number of columns', 'woocommerce-orders-invoice-pdf' ) }
								value={ count }
								onChange={ setColumns }
								min={ 1 }
								max={ MAX_COLUMNS }
							/>
							<Button variant="secondary" onClick={ equalizeWidths }>
								{ __( 'Equalize column widths', 'woocommerce-orders-invoice-pdf' ) }
							</Button>
						</PanelBody>
					</InspectorControls>
					<div { ...innerProps } />
				</>
			);
		},
		save() {
			return (
				<table { ...useBlockProps.save( { className: 'woi-row' } ) }>
					<tbody>
						<tr>
							<InnerBlocks.Content />
						</tr>
					</tbody>
				</table>
			);
		},
	} );
}

/**
 * "Header row" inserter variation of woi/columns: a 3-column bilingual header
 * pre-filled English shop name | logo | Arabic shop name (matches the UAE
 * bilingual header layout). Client-only — it expands to already-registered blocks.
 */
export function registerHeaderRowVariation() {
	registerBlockVariation( 'woi/columns', {
		name: 'woi-header-row',
		title: __( 'Header row (EN | logo | AR)', 'woocommerce-orders-invoice-pdf' ),
		icon: 'align-center',
		description: __( 'Three-column bilingual header: English | logo | Arabic.', 'woocommerce-orders-invoice-pdf' ),
		scope: [ 'inserter' ],
		innerBlocks: [
			[ 'woi/column', {}, [ [ 'woi/shop-name' ] ] ],
			[ 'woi/column', {}, [ [ 'woi/logo' ] ] ],
			[ 'woi/column', {}, [ [ 'woi/shop-name-ar' ] ] ],
		],
	} );
}
