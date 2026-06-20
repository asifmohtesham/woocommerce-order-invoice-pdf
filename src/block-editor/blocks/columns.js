import { registerBlockType, registerBlockVariation } from '@wordpress/blocks';
import { useBlockProps, useInnerBlocksProps, InnerBlocks } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

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

	// Parent: a one-row table whose cells are woi/column children.
	registerBlockType( 'woi/columns', {
		apiVersion: 2,
		title: __( 'Columns (table row)', 'woocommerce-orders-invoice-pdf' ),
		category: 'woi-invoice',
		icon: 'columns',
		supports: { html: false, reusable: false },
		edit() {
			const blockProps = useBlockProps( { style: { display: 'flex', gap: '8px', alignItems: 'stretch' } } );
			const innerProps = useInnerBlocksProps( blockProps, {
				allowedBlocks: [ 'woi/column' ],
				template: [ [ 'woi/column' ], [ 'woi/column' ] ],
				orientation: 'horizontal',
			} );
			return <div { ...innerProps } />;
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
