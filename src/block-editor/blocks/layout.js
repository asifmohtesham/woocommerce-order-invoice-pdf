import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, RichText } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

/**
 * Static layout blocks. Each save() emits the same class-keyed markup the
 * GrapesJS editor and templates/_visual/visual-document.css already use, so the
 * PDF renders identically regardless of which editor authored the design.
 * edit() adds a light in-canvas hint for the otherwise-invisible blocks.
 */
export function registerLayoutBlocks() {
	// Spacer — vertical gap. CSS: .woi-spacer { height: 12mm }.
	registerBlockType( 'woi/spacer', {
		apiVersion: 2,
		title: __( 'Spacer', 'woocommerce-orders-invoice-pdf' ),
		category: 'woi-invoice',
		icon: 'minus',
		supports: { html: false, reusable: false },
		edit() {
			return (
				<div { ...useBlockProps( { style: { minHeight: '24px', background: 'repeating-linear-gradient(45deg,#f3f4f5,#f3f4f5 6px,#fff 6px,#fff 12px)', border: '1px dashed #c3c4c7' } } ) }>
					<span style={ { fontSize: '11px', color: '#666' } }>{ __( 'Spacer', 'woocommerce-orders-invoice-pdf' ) }</span>
				</div>
			);
		},
		save() {
			return <div { ...useBlockProps.save( { className: 'woi-spacer' } ) } />;
		},
	} );

	// Divider — horizontal rule.
	registerBlockType( 'woi/divider', {
		apiVersion: 2,
		title: __( 'Divider', 'woocommerce-orders-invoice-pdf' ),
		category: 'woi-invoice',
		icon: 'minus',
		supports: { html: false, reusable: false },
		edit() {
			return <div { ...useBlockProps() }><hr /></div>;
		},
		save() {
			return <hr { ...useBlockProps.save() } />;
		},
	} );

	// Heading — editable section heading (<h2>).
	registerBlockType( 'woi/heading', {
		apiVersion: 2,
		title: __( 'Heading', 'woocommerce-orders-invoice-pdf' ),
		category: 'woi-invoice',
		icon: 'heading',
		attributes: { content: { type: 'string', source: 'html', selector: 'h2', default: '' } },
		supports: { reusable: false },
		edit( { attributes, setAttributes } ) {
			return (
				<RichText
					{ ...useBlockProps() }
					tagName="h2"
					value={ attributes.content }
					onChange={ ( content ) => setAttributes( { content } ) }
					placeholder={ __( 'Section heading…', 'woocommerce-orders-invoice-pdf' ) }
				/>
			);
		},
		save( { attributes } ) {
			return <RichText.Content { ...useBlockProps.save() } tagName="h2" value={ attributes.content } />;
		},
	} );

	// Page break — forces a new PDF page. CSS: .woi-pagebreak { page-break-after: always; height: 0 }.
	registerBlockType( 'woi/page-break', {
		apiVersion: 2,
		title: __( 'Page break', 'woocommerce-orders-invoice-pdf' ),
		category: 'woi-invoice',
		icon: 'page',
		supports: { html: false, reusable: false },
		edit() {
			return (
				<div { ...useBlockProps( { style: { borderTop: '2px dashed #999', textAlign: 'center', margin: '8px 0' } } ) }>
					<span style={ { fontSize: '11px', color: '#666', background: '#fff', padding: '0 6px' } }>{ __( 'Page break', 'woocommerce-orders-invoice-pdf' ) }</span>
				</div>
			);
		},
		save() {
			return <div { ...useBlockProps.save( { className: 'woi-pagebreak' } ) } />;
		},
	} );
}
