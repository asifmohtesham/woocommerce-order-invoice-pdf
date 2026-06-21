import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, RangeControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { safeHTML } from '@wordpress/dom';
import { STORE } from '../previewStore';
import { isHtmlToken, tokenValue } from '../tokenMerge';
import { APPEARANCE_ATTRS, appearanceStyle, appearanceProps, AppearancePanel, AppearanceToolbar } from '../appearance';

/**
 * Token blocks. Each is static: save() emits a fixed wrapper holding the literal
 * {{token}}; the real value is merged server-side at PDF time. The logo entry is
 * flagged `image: true` and gains a width control (mm) that sizes the
 * server-rendered logo via the .woi-img-sized CSS contract.
 */
const TOKENS = [
	{ name: 'woi/logo',              title: __( 'Logo image', 'woocommerce-orders-invoice-pdf' ),        token: '{{logo}}',              tag: 'div', preview: '[ logo image ]', image: true },
	{ name: 'woi/shop-name',         title: __( 'Shop name', 'woocommerce-orders-invoice-pdf' ),         token: '{{shop_name}}',         tag: 'p',   preview: 'Acme Trading LLC' },
	{ name: 'woi/shop-address',      title: __( 'Shop address', 'woocommerce-orders-invoice-pdf' ),      token: '{{shop_address}}',      tag: 'p',   preview: 'Office 12, Dubai, UAE' },
	{ name: 'woi/shop-name-ar',      title: __( 'Shop name (AR)', 'woocommerce-orders-invoice-pdf' ),    token: '{{shop_name_ar}}',      tag: 'p',   preview: 'أكمي للتجارة' },
	{ name: 'woi/shop-address-ar',   title: __( 'Shop address (AR)', 'woocommerce-orders-invoice-pdf' ), token: '{{shop_address_ar}}',   tag: 'p',   preview: 'مكتب ١٢، دبي' },
	{ name: 'woi/trn',               title: __( 'TRN', 'woocommerce-orders-invoice-pdf' ),                token: '{{trn}}',               tag: 'p',   preview: '100123456700003' },
	{ name: 'woi/shop-phone',        title: __( 'Shop phone', 'woocommerce-orders-invoice-pdf' ),        token: '{{shop_phone}}',        tag: 'p',   preview: '+971 4 000 0000' },
	{ name: 'woi/shop-email',        title: __( 'Shop email', 'woocommerce-orders-invoice-pdf' ),        token: '{{shop_email}}',        tag: 'p',   preview: 'billing@acme.example' },
	{ name: 'woi/document-title',    title: __( 'Document title', 'woocommerce-orders-invoice-pdf' ),    token: '{{document_title}}',    tag: 'p',   preview: 'Tax Invoice' },
	{ name: 'woi/document-title-ar', title: __( 'Document title (AR)', 'woocommerce-orders-invoice-pdf' ),token: '{{document_title_ar}}', tag: 'p',   preview: 'فاتورة ضريبية' },
	{ name: 'woi/invoice-number',    title: __( 'Invoice number', 'woocommerce-orders-invoice-pdf' ),    token: '{{invoice_number}}',    tag: 'p',   preview: 'INV-001' },
	{ name: 'woi/invoice-date',      title: __( 'Invoice date', 'woocommerce-orders-invoice-pdf' ),      token: '{{invoice_date}}',      tag: 'p',   preview: '18 June 2026' },
	{ name: 'woi/order-number',      title: __( 'Order number', 'woocommerce-orders-invoice-pdf' ),      token: '{{order_number}}',      tag: 'p',   preview: '4242' },
	{ name: 'woi/payment-method',    title: __( 'Payment method', 'woocommerce-orders-invoice-pdf' ),    token: '{{payment_method}}',    tag: 'p',   preview: 'Credit Card' },
	{ name: 'woi/billing-address',   title: __( 'Billing address', 'woocommerce-orders-invoice-pdf' ),   token: '{{billing_address}}',   tag: 'div', preview: 'John Buyer, Abu Dhabi, UAE' },
	{ name: 'woi/line-items',        title: __( 'Line items table', 'woocommerce-orders-invoice-pdf' ),  token: '{{line_items}}',        tag: 'div', preview: '[ line items table ]' },
	{ name: 'woi/totals',            title: __( 'Totals table', 'woocommerce-orders-invoice-pdf' ),      token: '{{totals}}',            tag: 'div', preview: '[ totals table ]' },

	// Redesign leaf tokens.
	{ name: 'woi/shipping-address',  title: __( 'Shipping address', 'woocommerce-orders-invoice-pdf' ),  token: '{{shipping_address}}',  tag: 'div', preview: 'Ship to address' },
	{ name: 'woi/recipient-trn',     title: __( 'Customer TRN', 'woocommerce-orders-invoice-pdf' ),       token: '{{recipient_trn}}',     tag: 'div', preview: 'Customer TRN' },
	{ name: 'woi/bank-details',      title: __( 'Bank details', 'woocommerce-orders-invoice-pdf' ),       token: '{{bank_details}}',      tag: 'div', preview: '[ bank details ]' },
	{ name: 'woi/qr-code',           title: __( 'QR code', 'woocommerce-orders-invoice-pdf' ),            token: '{{qr_code}}',           tag: 'div', preview: '[ QR ]' },
	{ name: 'woi/amount-words',      title: __( 'Amount in words', 'woocommerce-orders-invoice-pdf' ),    token: '{{amount_words}}',      tag: 'p',   preview: 'Amount in words' },
	{ name: 'woi/shop-website',      title: __( 'Shop website', 'woocommerce-orders-invoice-pdf' ),       token: '{{shop_website}}',      tag: 'p',   preview: 'www.example.com' },

	// Whole-section blocks — each emits a canonical visual-document.css section.
	// The default template (Reset) is built from these so the block path renders
	// the full redesign. Granular tokens above remain for custom composition.
	{ name: 'woi/letterhead',        title: __( 'Letterhead (section)', 'woocommerce-orders-invoice-pdf' ),    token: '{{letterhead}}',     tag: 'div', preview: '[ Letterhead ]' },
	{ name: 'woi/contact-strip',     title: __( 'Contact strip (section)', 'woocommerce-orders-invoice-pdf' ), token: '{{contact_strip}}',  tag: 'div', preview: '[ Contact strip ]' },
	{ name: 'woi/title-meta',        title: __( 'Title & meta (section)', 'woocommerce-orders-invoice-pdf' ),  token: '{{title_meta}}',     tag: 'div', preview: '[ Title & meta ]' },
	{ name: 'woi/parties',           title: __( 'Bill / Ship to (section)', 'woocommerce-orders-invoice-pdf' ),token: '{{parties}}',        tag: 'div', preview: '[ Bill / Ship to ]' },
	{ name: 'woi/lower',             title: __( 'Bank & totals (section)', 'woocommerce-orders-invoice-pdf' ), token: '{{lower}}',          tag: 'div', preview: '[ Bank & totals ]' },
	{ name: 'woi/signature',         title: __( 'Signature / QR (section)', 'woocommerce-orders-invoice-pdf' ),token: '{{signature}}',      tag: 'div', preview: '[ Signature / QR ]' },
	// Footer is rendered as an mPDF running page-footer by the document wrapper
	// (accurate Page X of Y), so it is intentionally NOT a content block.
];

export function registerTokenBlocks() {
	TOKENS.forEach( ( { name, title, token, tag, preview, image } ) => {
		registerBlockType( name, {
			apiVersion: 2,
			title,
			category: 'woi-invoice',
			icon: 'media-document',
			attributes: {
				...APPEARANCE_ATTRS,
				...( image ? { imgWidth: { type: 'number', default: 0 } } : {} ),
			},
			supports: { html: false, reusable: false },
			edit( { attributes, setAttributes } ) {
				const Tag = tag;
				const tokens = useSelect( ( select ) => select( STORE ).getTokens(), [] );
				const value = tokenValue( token, tokens );
				const sized = image && attributes.imgWidth;
				const style = { ...appearanceStyle( attributes ), ...( sized ? { width: attributes.imgWidth + 'mm' } : {} ) };
				const className = [ value ? '' : 'woi-token-empty', sized ? 'woi-img-sized' : '' ].filter( Boolean ).join( ' ' ) || undefined;
				const blockProps = useBlockProps( { className, style } );
				const panel = (
					<InspectorControls>
						{ image ? (
							<PanelBody title={ __( 'Image', 'woocommerce-orders-invoice-pdf' ) }>
								<RangeControl
									label={ __( 'Logo width (mm) — 0 = default', 'woocommerce-orders-invoice-pdf' ) }
									value={ attributes.imgWidth || 0 }
									onChange={ ( v ) => setAttributes( { imgWidth: v || 0 } ) }
									min={ 0 }
									max={ 120 }
								/>
							</PanelBody>
						) : null }
						<AppearancePanel attributes={ attributes } setAttributes={ setAttributes } />
					</InspectorControls>
				);
				let inner;
				if ( ! value ) {
					// No order picked / token empty: show the friendly label so the
					// block stays visible and selectable.
					inner = <Tag { ...blockProps }>{ preview }</Tag>;
				} else if ( isHtmlToken( token ) ) {
					// HTML token (logo, billing address, line-items / totals tables).
					// safeHTML strips scripts / event-handler attributes / javascript:
					// URLs before injecting into the live admin DOM.
					inner = <Tag { ...blockProps } dangerouslySetInnerHTML={ { __html: safeHTML( value ) } } />;
				} else {
					inner = <Tag { ...blockProps }>{ value }</Tag>;
				}
				return (
					<>
						<AppearanceToolbar attributes={ attributes } setAttributes={ setAttributes } />
						{ panel }
						{ inner }
					</>
				);
			},
			save( { attributes } ) {
				const Tag = tag;
				// Sized logo: wrapper carries the width + the .woi-img-sized class that
				// the visual-document.css rule uses to fill the server <img>.
				if ( image && attributes.imgWidth ) {
					const style = { ...appearanceStyle( attributes ), width: attributes.imgWidth + 'mm' };
					return <Tag { ...useBlockProps.save( { className: 'woi-img-sized', style } ) }>{ token }</Tag>;
				}
				// Inner content is the literal token; merged at PDF render time.
				return <Tag { ...useBlockProps.save( appearanceProps( attributes ) ) }>{ token }</Tag>;
			},
		} );
	} );
}
