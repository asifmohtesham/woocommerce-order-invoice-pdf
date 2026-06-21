import { PanelBody, SelectControl, RangeControl, ColorPalette } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Shared block-level presentational controls (text align, font weight, font
 * size, text colour, background colour) for the text-bearing blocks (Text,
 * Heading, all Token blocks).
 *
 * Inline-style based ON PURPOSE: WordPress's native color/typography block
 * supports emit PALETTE classes (e.g. has-vivid-red-color) that resolve only
 * against the theme stylesheet — which the mPDF PDF does NOT load. Inline styles
 * always render, are kses-safe (safecss_filter_attr allows these properties),
 * and mPDF honours them. Every attribute defaults empty, so a block with no
 * appearance set serialises exactly as before (no block-validation break).
 */
const PALETTE = [
	{ name: __( 'Black', 'woocommerce-orders-invoice-pdf' ), color: '#000000' },
	{ name: __( 'White', 'woocommerce-orders-invoice-pdf' ), color: '#ffffff' },
	{ name: __( 'Grey', 'woocommerce-orders-invoice-pdf' ), color: '#666666' },
	{ name: __( 'Light grey', 'woocommerce-orders-invoice-pdf' ), color: '#f3f4f5' },
];

export const APPEARANCE_ATTRS = {
	align: { type: 'string', default: '' },
	weight: { type: 'string', default: '' },
	fontSize: { type: 'number', default: 0 },
	color: { type: 'string', default: '' },
	bg: { type: 'string', default: '' },
};

// Build the inline style object from the appearance attributes (set props only).
export function appearanceStyle( a ) {
	const s = {};
	if ( a.align ) { s.textAlign = a.align; }
	if ( a.weight ) { s.fontWeight = a.weight; }
	if ( a.fontSize ) { s.fontSize = a.fontSize + 'px'; }
	if ( a.color ) { s.color = a.color; }
	if ( a.bg ) { s.backgroundColor = a.bg; }
	return s;
}

// Spread onto an element's props: adds { style } only when something is set, so
// an unstyled block produces the identical markup it did before this feature.
export function appearanceProps( attributes ) {
	const style = appearanceStyle( attributes );
	return Object.keys( style ).length ? { style } : {};
}

// The Inspector "Appearance" panel, shared by every text-bearing block.
export function AppearancePanel( { attributes, setAttributes } ) {
	const { align, weight, fontSize, color, bg } = attributes;
	return (
		<PanelBody title={ __( 'Appearance', 'woocommerce-orders-invoice-pdf' ) } initialOpen={ true }>
			<SelectControl
				label={ __( 'Text align', 'woocommerce-orders-invoice-pdf' ) }
				value={ align || '' }
				options={ [
					{ label: __( 'Default', 'woocommerce-orders-invoice-pdf' ), value: '' },
					{ label: __( 'Left', 'woocommerce-orders-invoice-pdf' ), value: 'left' },
					{ label: __( 'Center', 'woocommerce-orders-invoice-pdf' ), value: 'center' },
					{ label: __( 'Right', 'woocommerce-orders-invoice-pdf' ), value: 'right' },
				] }
				onChange={ ( v ) => setAttributes( { align: v } ) }
			/>
			<SelectControl
				label={ __( 'Font weight', 'woocommerce-orders-invoice-pdf' ) }
				value={ weight || '' }
				options={ [
					{ label: __( 'Default', 'woocommerce-orders-invoice-pdf' ), value: '' },
					{ label: __( 'Normal', 'woocommerce-orders-invoice-pdf' ), value: 'normal' },
					{ label: __( 'Bold', 'woocommerce-orders-invoice-pdf' ), value: 'bold' },
				] }
				onChange={ ( v ) => setAttributes( { weight: v } ) }
			/>
			<RangeControl
				label={ __( 'Font size (px) — 0 = default', 'woocommerce-orders-invoice-pdf' ) }
				value={ fontSize || 0 }
				onChange={ ( v ) => setAttributes( { fontSize: v || 0 } ) }
				min={ 0 }
				max={ 48 }
			/>
			<p style={ { margin: '12px 0 4px' } }>{ __( 'Text colour', 'woocommerce-orders-invoice-pdf' ) }</p>
			<ColorPalette value={ color || '' } colors={ PALETTE } onChange={ ( c ) => setAttributes( { color: c || '' } ) } />
			<p style={ { margin: '12px 0 4px' } }>{ __( 'Background colour', 'woocommerce-orders-invoice-pdf' ) }</p>
			<ColorPalette value={ bg || '' } colors={ PALETTE } onChange={ ( c ) => setAttributes( { bg: c || '' } ) } />
		</PanelBody>
	);
}
