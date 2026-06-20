import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, RichText } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

export function registerTextBlock() {
	registerBlockType( 'woi/text', {
		apiVersion: 2,
		title: __( 'Text', 'woocommerce-orders-invoice-pdf' ),
		category: 'woi-invoice',
		icon: 'editor-paragraph',
		attributes: { content: { type: 'string', source: 'html', selector: 'p', default: '' } },
		supports: { reusable: false },
		edit( { attributes, setAttributes } ) {
			return (
				<RichText
					{ ...useBlockProps() }
					tagName="p"
					value={ attributes.content }
					onChange={ ( content ) => setAttributes( { content } ) }
					placeholder={ __( 'Type text or insert a {{token}}…', 'woocommerce-orders-invoice-pdf' ) }
				/>
			);
		},
		save( { attributes } ) {
			return <RichText.Content { ...useBlockProps.save() } tagName="p" value={ attributes.content } />;
		},
	} );
}
