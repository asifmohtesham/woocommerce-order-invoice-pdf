import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

/**
 * Slice-1 token blocks. Each is static: save() emits a fixed wrapper holding the
 * literal {{token}}; the real value is merged server-side at PDF time.
 * `example`/preview shows a friendly label so the canvas is readable.
 */
const TOKENS = [
	{ name: 'woi/shop-name', title: __( 'Shop Name', 'woocommerce-orders-invoice-pdf' ), token: '{{shop_name}}', tag: 'p', preview: 'Acme Trading LLC' },
	{ name: 'woi/line-items', title: __( 'Line Items', 'woocommerce-orders-invoice-pdf' ), token: '{{line_items}}', tag: 'div', preview: '[ line items table ]' },
	{ name: 'woi/totals', title: __( 'Totals', 'woocommerce-orders-invoice-pdf' ), token: '{{totals}}', tag: 'div', preview: '[ totals table ]' },
];

export function registerTokenBlocks() {
	TOKENS.forEach( ( { name, title, token, tag, preview } ) => {
		registerBlockType( name, {
			apiVersion: 2,
			title,
			category: 'woi-invoice',
			icon: 'media-document',
			supports: { html: false, reusable: false },
			edit() {
				const Tag = tag;
				return <Tag { ...useBlockProps() }>{ preview }</Tag>;
			},
			save() {
				const Tag = tag;
				// Inner content is the literal token; merged at PDF render time.
				return <Tag { ...useBlockProps.save() }>{ token }</Tag>;
			},
		} );
	} );
}
