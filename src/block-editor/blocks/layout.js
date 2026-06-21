import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, RichText, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, SelectControl, RangeControl, ColorPalette } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { APPEARANCE_ATTRS, appearanceStyle, appearanceProps, AppearancePanel, AppearanceToolbar } from '../appearance';

/**
 * Static layout blocks. Each save() emits the same class-keyed markup the
 * GrapesJS editor and templates/_visual/visual-document.css already use, so the
 * PDF renders identically regardless of which editor authored the design.
 * edit() adds a light in-canvas hint for the otherwise-invisible blocks.
 */
export function registerLayoutBlocks() {
	// Spacer — vertical gap. CSS default .woi-spacer { height: 12mm }; an optional
	// height attribute overrides it inline (mm). Unset → bare class (CSS default).
	registerBlockType( 'woi/spacer', {
		apiVersion: 2,
		title: __( 'Spacer', 'woocommerce-orders-invoice-pdf' ),
		category: 'woi-invoice',
		icon: 'minus',
		attributes: { height: { type: 'number', default: 0 } },
		supports: { html: false, reusable: false },
		edit( { attributes, setAttributes } ) {
			const { height } = attributes;
			return (
				<>
					<InspectorControls>
						<PanelBody title={ __( 'Spacer', 'woocommerce-orders-invoice-pdf' ) }>
							<RangeControl
								label={ __( 'Height (mm) — 0 = default', 'woocommerce-orders-invoice-pdf' ) }
								value={ height || 0 }
								onChange={ ( v ) => setAttributes( { height: v || 0 } ) }
								min={ 0 }
								max={ 60 }
							/>
						</PanelBody>
					</InspectorControls>
					<div { ...useBlockProps( { style: { minHeight: height ? height + 'mm' : '24px', background: 'repeating-linear-gradient(45deg,#f3f4f5,#f3f4f5 6px,#fff 6px,#fff 12px)', border: '1px dashed #c3c4c7' } } ) }>
						<span style={ { fontSize: '11px', color: '#666' } }>{ __( 'Spacer', 'woocommerce-orders-invoice-pdf' ) }</span>
					</div>
				</>
			);
		},
		save( { attributes } ) {
			const props = attributes.height
				? { className: 'woi-spacer', style: { height: attributes.height + 'mm' } }
				: { className: 'woi-spacer' };
			return <div { ...useBlockProps.save( props ) } />;
		},
	} );

	// Divider — horizontal rule. Optional thickness (px) + colour; unset → bare <hr>.
	registerBlockType( 'woi/divider', {
		apiVersion: 2,
		title: __( 'Divider', 'woocommerce-orders-invoice-pdf' ),
		category: 'woi-invoice',
		icon: 'minus',
		attributes: {
			thickness: { type: 'number', default: 0 },
			color: { type: 'string', default: '' },
		},
		supports: { html: false, reusable: false },
		edit( { attributes, setAttributes } ) {
			const { thickness, color } = attributes;
			const previewStyle = ( thickness || color )
				? { border: 0, borderTop: ( thickness || 1 ) + 'px solid ' + ( color || '#000' ) }
				: undefined;
			return (
				<>
					<InspectorControls>
						<PanelBody title={ __( 'Divider', 'woocommerce-orders-invoice-pdf' ) }>
							<RangeControl
								label={ __( 'Thickness (px) — 0 = default', 'woocommerce-orders-invoice-pdf' ) }
								value={ thickness || 0 }
								onChange={ ( v ) => setAttributes( { thickness: v || 0 } ) }
								min={ 0 }
								max={ 10 }
							/>
							<p style={ { margin: '12px 0 4px' } }>{ __( 'Colour', 'woocommerce-orders-invoice-pdf' ) }</p>
							<ColorPalette value={ color } onChange={ ( c ) => setAttributes( { color: c || '' } ) } />
						</PanelBody>
					</InspectorControls>
					<div { ...useBlockProps() }><hr style={ previewStyle } /></div>
				</>
			);
		},
		save( { attributes } ) {
			const { thickness, color } = attributes;
			if ( thickness || color ) {
				return <hr { ...useBlockProps.save( { style: { border: 0, borderTop: ( thickness || 1 ) + 'px solid ' + ( color || '#000' ) } } ) } />;
			}
			return <hr { ...useBlockProps.save() } />;
		},
	} );

	// Heading — editable section heading, level h1–h6. The content selector lists
	// every heading tag so existing h2 headings keep parsing after the level
	// became configurable (no deprecation needed).
	registerBlockType( 'woi/heading', {
		apiVersion: 2,
		title: __( 'Heading', 'woocommerce-orders-invoice-pdf' ),
		category: 'woi-invoice',
		icon: 'heading',
		attributes: {
			content: { type: 'string', source: 'html', selector: 'h1,h2,h3,h4,h5,h6', default: '' },
			level: { type: 'number', default: 2 },
			...APPEARANCE_ATTRS,
		},
		supports: { reusable: false },
		edit( { attributes, setAttributes } ) {
			const tagName = 'h' + ( attributes.level || 2 );
			return (
				<>
					<AppearanceToolbar attributes={ attributes } setAttributes={ setAttributes } />
					<InspectorControls>
						<PanelBody title={ __( 'Heading', 'woocommerce-orders-invoice-pdf' ) }>
							<SelectControl
								label={ __( 'Level', 'woocommerce-orders-invoice-pdf' ) }
								value={ String( attributes.level || 2 ) }
								options={ [ 1, 2, 3, 4, 5, 6 ].map( ( n ) => ( { label: 'H' + n, value: String( n ) } ) ) }
								onChange={ ( v ) => setAttributes( { level: parseInt( v, 10 ) || 2 } ) }
							/>
						</PanelBody>
						<AppearancePanel attributes={ attributes } setAttributes={ setAttributes } />
					</InspectorControls>
					<RichText
						{ ...useBlockProps( { style: appearanceStyle( attributes ) } ) }
						tagName={ tagName }
						value={ attributes.content }
						onChange={ ( content ) => setAttributes( { content } ) }
						placeholder={ __( 'Section heading…', 'woocommerce-orders-invoice-pdf' ) }
					/>
				</>
			);
		},
		save( { attributes } ) {
			const tagName = 'h' + ( attributes.level || 2 );
			return <RichText.Content { ...useBlockProps.save( appearanceProps( attributes ) ) } tagName={ tagName } value={ attributes.content } />;
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
