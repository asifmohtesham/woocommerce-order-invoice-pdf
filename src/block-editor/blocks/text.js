import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, RichText, InspectorControls } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';
import { APPEARANCE_ATTRS, appearanceStyle, appearanceProps, AppearancePanel } from '../appearance';

export function registerTextBlock() {
	registerBlockType( 'woi/text', {
		apiVersion: 2,
		title: __( 'Text', 'woocommerce-orders-invoice-pdf' ),
		category: 'woi-invoice',
		icon: 'editor-paragraph',
		attributes: { content: { type: 'string', source: 'html', selector: 'p', default: '' }, ...APPEARANCE_ATTRS },
		supports: { reusable: false },
		edit( { attributes, setAttributes } ) {
			return (
				<>
					<InspectorControls>
						<AppearancePanel attributes={ attributes } setAttributes={ setAttributes } />
					</InspectorControls>
					<RichText
						{ ...useBlockProps( { style: appearanceStyle( attributes ) } ) }
						tagName="p"
						value={ attributes.content }
						onChange={ ( content ) => setAttributes( { content } ) }
						placeholder={ __( 'Type text or insert a {{token}}…', 'woocommerce-orders-invoice-pdf' ) }
					/>
				</>
			);
		},
		save( { attributes } ) {
			return <RichText.Content { ...useBlockProps.save( appearanceProps( attributes ) ) } tagName="p" value={ attributes.content } />;
		},
	} );
}
