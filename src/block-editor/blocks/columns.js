import { registerBlockType, registerBlockVariation, createBlock } from '@wordpress/blocks';
import { useBlockProps, useInnerBlocksProps, InnerBlocks, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, RangeControl } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

// Max cells in a Columns (table row) block. Mirrors core/columns' upper bound.
const MAX_COLUMNS = 12;

/**
 * Composition blocks. woi/columns renders a single-row table (.woi-row, already
 * styled in visual-document.css); its cells are woi/column blocks, each a <td>
 * drop-zone that accepts any invoice block. edit() uses a friendly flex/div
 * layout for editing; save() emits the real mPDF-safe table — Gutenberg only
 * validates save() markup, so the divergence is intentional and allowed.
 */
export function registerColumnsBlocks() {
	// Child: one table cell holding arbitrary blocks.
	registerBlockType( 'woi/column', {
		apiVersion: 2,
		title: __( 'Column', 'woocommerce-orders-invoice-pdf' ),
		category: 'woi-invoice',
		parent: [ 'woi/columns' ],
		icon: 'columns',
		supports: { html: false, reusable: false, inserter: false },
		edit() {
			const blockProps = useBlockProps( { style: { flex: '1', minWidth: '60px', border: '1px dashed #c3c4c7', padding: '8px', verticalAlign: 'top' } } );
			const innerProps = useInnerBlocksProps( blockProps, { templateLock: false } );
			return <div { ...innerProps } />;
		},
		save() {
			const innerProps = useInnerBlocksProps.save( useBlockProps.save() );
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
			const { replaceInnerBlocks } = useDispatch( 'core/block-editor' );

			const setColumns = ( next ) => {
				const target = Math.max( 1, Math.min( MAX_COLUMNS, next || 1 ) );
				const current = getBlocks( clientId );
				if ( target === current.length ) { return; }
				const blocks = target > current.length
					? current.concat( Array.from( { length: target - current.length }, () => createBlock( 'woi/column' ) ) )
					: current.slice( 0, target );
				replaceInnerBlocks( clientId, blocks, false );
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
