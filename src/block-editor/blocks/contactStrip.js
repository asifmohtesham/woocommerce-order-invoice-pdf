import { useState } from '@wordpress/element';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, ToggleControl, SelectControl, RangeControl, ColorPalette, Button } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { STORE } from '../previewStore';
import { tokenValue } from '../tokenMerge';
import { appearanceProps, APPEARANCE_ATTRS } from '../appearance';
import { CONTACT_FIELDS, CONTACT_DEFAULT_ITEMS, reorder, valueStyle } from './contactStripModel';

export { CONTACT_DEFAULT_ITEMS };

const COLORS = [
	{ name: __( 'Ink', 'woocommerce-orders-invoice-pdf' ), color: '#1C1A17' },
	{ name: __( 'Accent', 'woocommerce-orders-invoice-pdf' ), color: '#140858' },
	{ name: __( 'Grey', 'woocommerce-orders-invoice-pdf' ), color: '#8A8378' },
];

// Always work with a non-empty item list (guards a cleared attribute).
function effectiveItems( attributes ) {
	return attributes.items && attributes.items.length ? attributes.items : CONTACT_DEFAULT_ITEMS;
}

export function ContactStripEdit( { attributes, setAttributes } ) {
	const items = effectiveItems( attributes );
	const tokens = useSelect( ( select ) => select( STORE ).getTokens(), [] );
	const [ selected, setSelected ] = useState( 0 );
	const [ dragFrom, setDragFrom ] = useState( null );
	const blockProps = useBlockProps( { className: 'woi-contact-edit' } );

	const update = ( idx, patch ) => {
		setAttributes( { items: items.map( ( it, i ) => ( i === idx ? { ...it, ...patch } : it ) ) } );
	};
	const onDrop = ( to ) => {
		if ( null === dragFrom ) { return; }
		setAttributes( { items: reorder( items, dragFrom, to ) } );
		setSelected( to );
		setDragFrom( null );
	};

	const sel = items[ selected ] || items[ 0 ];

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Contact element', 'woocommerce-orders-invoice-pdf' ) } initialOpen={ true }>
					<SelectControl
						label={ __( 'Editing', 'woocommerce-orders-invoice-pdf' ) }
						value={ String( selected ) }
						options={ items.map( ( it, i ) => ( { label: CONTACT_FIELDS[ it.field ].label, value: String( i ) } ) ) }
						onChange={ ( v ) => setSelected( parseInt( v, 10 ) ) }
					/>
					<ToggleControl
						label={ __( 'Visible', 'woocommerce-orders-invoice-pdf' ) }
						checked={ false !== sel.visible }
						onChange={ ( v ) => update( selected, { visible: v } ) }
					/>
					<SelectControl
						label={ __( 'Align', 'woocommerce-orders-invoice-pdf' ) }
						value={ sel.align || 'left' }
						options={ [
							{ label: __( 'Left', 'woocommerce-orders-invoice-pdf' ), value: 'left' },
							{ label: __( 'Center', 'woocommerce-orders-invoice-pdf' ), value: 'center' },
							{ label: __( 'Right', 'woocommerce-orders-invoice-pdf' ), value: 'right' },
						] }
						onChange={ ( v ) => update( selected, { align: v } ) }
					/>
					<ToggleControl
						label={ __( 'Bold', 'woocommerce-orders-invoice-pdf' ) }
						checked={ !! sel.bold }
						onChange={ ( v ) => update( selected, { bold: v } ) }
					/>
					<RangeControl
						label={ __( 'Font size (px) — 0 = default', 'woocommerce-orders-invoice-pdf' ) }
						value={ sel.fontSize || 0 }
						onChange={ ( v ) => update( selected, { fontSize: v || 0 } ) }
						min={ 0 }
						max={ 24 }
					/>
					<p style={ { margin: '12px 0 4px' } }>{ __( 'Text colour', 'woocommerce-orders-invoice-pdf' ) }</p>
					<ColorPalette value={ sel.color || '' } colors={ COLORS } onChange={ ( c ) => update( selected, { color: c || '' } ) } />
					<Button variant="secondary" onClick={ () => setAttributes( { items: CONTACT_DEFAULT_ITEMS } ) } style={ { marginTop: 12 } }>
						{ __( 'Reset to default', 'woocommerce-orders-invoice-pdf' ) }
					</Button>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<div
					className="woi-contact-strip-row"
					style={ { display: 'flex', borderTop: '1.5px solid #140858', borderBottom: '0.5pt solid #D9D4C9', padding: '4px 0' } }
				>
					{ items.map( ( it, i ) => {
						const field = CONTACT_FIELDS[ it.field ];
						const value = tokenValue( field.token, tokens );
						const hidden = false === it.visible;
						return (
							<div
								key={ i }
								draggable
								onDragStart={ () => setDragFrom( i ) }
								onDragOver={ ( e ) => e.preventDefault() }
								onDrop={ () => onDrop( i ) }
								onClick={ () => setSelected( i ) }
								className={ 'woi-contact-chip' + ( i === selected ? ' is-selected' : '' ) }
								style={ {
									flex: 1,
									textAlign: it.align || 'left',
									opacity: hidden ? 0.35 : 1,
									cursor: 'grab',
									outline: i === selected ? '1px solid #007cba' : 'none',
									padding: '2px 4px',
								} }
							>
								<span className="woi-contact-k">{ field.label }</span>{ ' ' }
								<span className="woi-contact-v" style={ valueStyle( it ) }>{ value || '—' }</span>
								{ hidden ? <em style={ { marginLeft: 4, fontSize: 10 } }>{ __( '(hidden)', 'woocommerce-orders-invoice-pdf' ) }</em> : null }
							</div>
						);
					} ) }
				</div>
			</div>
		</>
	);
}

export function contactStripSave( { attributes } ) {
	const items = effectiveItems( attributes );
	const props = useBlockProps.save( {
		...appearanceProps( attributes ),
		'data-woi-section': 'contact',
		'data-woi-contact-config': JSON.stringify( items ),
	} );
	return <div { ...props }>{ '{{contact_strip}}' }</div>;
}

// Old save was a bare token-only div. Migrate stored templates so they don't
// trip block validation ("unexpected or invalid content") on load.
export const CONTACT_DEPRECATED = [
	{
		attributes: { ...APPEARANCE_ATTRS },
		save( { attributes } ) {
			return <div { ...useBlockProps.save( appearanceProps( attributes ) ) }>{ '{{contact_strip}}' }</div>;
		},
		migrate( attributes ) {
			return { ...attributes, items: CONTACT_DEFAULT_ITEMS };
		},
	},
];
