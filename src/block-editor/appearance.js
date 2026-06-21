import { PanelBody, SelectControl, RangeControl, ColorPalette } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { APPEARANCE_ATTRS, appearanceStyle, appearanceProps } from './appearanceStyle';

export { APPEARANCE_ATTRS, appearanceStyle, appearanceProps };

const PALETTE = [
	{ name: __( 'Black', 'woocommerce-orders-invoice-pdf' ), color: '#000000' },
	{ name: __( 'White', 'woocommerce-orders-invoice-pdf' ), color: '#ffffff' },
	{ name: __( 'Grey', 'woocommerce-orders-invoice-pdf' ), color: '#666666' },
	{ name: __( 'Light grey', 'woocommerce-orders-invoice-pdf' ), color: '#f3f4f5' },
];

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
