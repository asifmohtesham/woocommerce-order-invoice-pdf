import { useState, useRef } from '@wordpress/element';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, ToggleControl, SelectControl, RangeControl, ColorPalette, Button } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { safeHTML } from '@wordpress/dom';
import { STORE } from '../previewStore';
import { tokenValue, isHtmlToken } from '../tokenMerge';
import { appearanceProps } from '../appearance';
import { saveLetterhead, saveDocOptions } from '../store';
import { LH_TEXT_FIELDS, LH_DEFAULT, lhValueStyle, lhFieldClass } from './letterheadModel';

const COLORS = [
	{ name: __( 'Ink', 'woocommerce-orders-invoice-pdf' ), color: '#1C1A17' },
	{ name: __( 'Accent', 'woocommerce-orders-invoice-pdf' ), color: '#140858' },
	{ name: __( 'Grey', 'woocommerce-orders-invoice-pdf' ), color: '#8A8378' },
];

function seedConfig() {
	const s = window.woiBlocks && window.woiBlocks.letterhead;
	return s && s.elements ? s : LH_DEFAULT;
}
function seedLogoPos() {
	const h = window.woiBlocks && window.woiBlocks.docOptions && window.woiBlocks.docOptions.header;
	return [ 'left', 'center', 'right' ].includes( h ) ? h : 'center';
}

export function LetterheadEdit() {
	const tokens = useSelect( ( select ) => select( STORE ).getTokens(), [] );
	const [ cfg, setCfg ] = useState( seedConfig );
	const [ logoPos, setLogoPos ] = useState( seedLogoPos );
	const [ selected, setSelected ] = useState( 'name_en' );
	const timer = useRef( null );
	const blockProps = useBlockProps( { className: 'woi-lh-edit' } );

	const persist = ( next ) => {
		setCfg( next );
		if ( timer.current ) { clearTimeout( timer.current ); }
		timer.current = setTimeout( () => { saveLetterhead( next ).catch( () => {} ); }, 600 );
	};
	const updateEl = ( key, patch ) => persist( { ...cfg, elements: { ...cfg.elements, [ key ]: { ...cfg.elements[ key ], ...patch } } } );
	const updateCfg = ( patch ) => persist( { ...cfg, ...patch } );
	const changeLogoPos = ( v ) => { setLogoPos( v ); saveDocOptions( { header: v } ).catch( () => {} ); };

	const isText = LH_TEXT_FIELDS.some( ( f ) => f.key === selected );
	const sel = cfg.elements[ selected ] || {};

	// Render the EN and AR columns as stacked name+address; logo cell per position.
	const colFor = ( side ) => {
		const fields = LH_TEXT_FIELDS.filter( ( f ) => f.key.endsWith( side ) );
		return (
			<div key={ side } className="woi-lh-col" style={ { flex: 1, direction: side === 'ar' ? 'rtl' : 'ltr' } }>
				{ fields.map( ( f ) => {
					const el = cfg.elements[ f.key ];
					if ( el.visible === false ) {
						return <div key={ f.key } onClick={ () => setSelected( f.key ) } style={ { opacity: 0.35, cursor: 'pointer', fontSize: 11 } }>{ f.label } { __( '(hidden)', 'woocommerce-orders-invoice-pdf' ) }</div>;
					}
					// shop_address / shop_address_ar carry HTML (<br/> line breaks) and
					// must render as HTML, not literal text — matches the generic token
					// edit. Plain-text fields (names) render as text.
					const val = tokenValue( f.token, tokens );
					const elProps = {
						// woi-co-name / woi-co-lines so the shared accent + document CSS
						// (injected into the canvas iframe) colour/size the name and
						// address exactly as the PDF does — see lhFieldClass().
						className: lhFieldClass( f.key ),
						onClick: () => setSelected( f.key ),
						style: { textAlign: el.align || ( side === 'ar' ? 'right' : 'left' ), outline: selected === f.key ? '1px solid #007cba' : 'none', cursor: 'pointer', padding: '1px 3px', ...lhValueStyle( el ) },
					};
					return ( val && isHtmlToken( f.token ) )
						? <div key={ f.key } { ...elProps } dangerouslySetInnerHTML={ { __html: safeHTML( val ) } } />
						: <div key={ f.key } { ...elProps }>{ val || f.label }</div>;
				} ) }
			</div>
		);
	};
	// The logo token is the real server <img> (HTML); render it, falling back to a
	// placeholder only when no logo is set / no order is selected.
	const logoVal = tokenValue( '{{logo}}', tokens );
	const logoCell = cfg.elements.logo.visible === false ? null : (
		<div key="logo" className="woi-lh-logo" onClick={ () => setSelected( 'logo' ) }
			style={ { flex: '0 0 20%', textAlign: 'center', outline: selected === 'logo' ? '1px solid #007cba' : 'none', cursor: 'pointer' } }>
			{ logoVal
				? <span dangerouslySetInnerHTML={ { __html: safeHTML( logoVal ) } } />
				: <span style={ { fontSize: 11, color: '#8A8378' } }>{ __( '[ logo ]', 'woocommerce-orders-invoice-pdf' ) }</span> }
		</div>
	);
	const textCols = cfg.swapText ? [ colFor( 'ar' ), colFor( 'en' ) ] : [ colFor( 'en' ), colFor( 'ar' ) ];
	let row = [ ...textCols ];
	if ( logoCell ) {
		if ( logoPos === 'left' ) { row = [ logoCell, ...textCols ]; }
		else if ( logoPos === 'right' ) { row = [ ...textCols, logoCell ]; }
		else { row = [ textCols[ 0 ], logoCell, textCols[ 1 ] ]; }
	}

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Layout', 'woocommerce-orders-invoice-pdf' ) } initialOpen={ true }>
					<SelectControl
						label={ __( 'Logo position', 'woocommerce-orders-invoice-pdf' ) }
						value={ logoPos }
						options={ [
							{ label: __( 'Left', 'woocommerce-orders-invoice-pdf' ), value: 'left' },
							{ label: __( 'Centre', 'woocommerce-orders-invoice-pdf' ), value: 'center' },
							{ label: __( 'Right', 'woocommerce-orders-invoice-pdf' ), value: 'right' },
						] }
						onChange={ changeLogoPos }
					/>
					<ToggleControl
						label={ __( 'Swap EN / AR sides', 'woocommerce-orders-invoice-pdf' ) }
						checked={ !! cfg.swapText }
						onChange={ ( v ) => updateCfg( { swapText: v } ) }
					/>
					<RangeControl
						label={ __( 'Logo width (mm) — 0 = default', 'woocommerce-orders-invoice-pdf' ) }
						value={ cfg.logoWidth || 0 }
						onChange={ ( v ) => updateCfg( { logoWidth: v || 0 } ) }
						min={ 0 }
						max={ 120 }
					/>
					<Button variant="secondary" onClick={ () => { persist( LH_DEFAULT ); setSelected( 'name_en' ); } } style={ { marginTop: 12 } }>
						{ __( 'Reset to default', 'woocommerce-orders-invoice-pdf' ) }
					</Button>
				</PanelBody>
				<PanelBody title={ __( 'Element', 'woocommerce-orders-invoice-pdf' ) } initialOpen={ true }>
					<SelectControl
						label={ __( 'Editing', 'woocommerce-orders-invoice-pdf' ) }
						value={ selected }
						options={ [ ...LH_TEXT_FIELDS.map( ( f ) => ( { label: f.label, value: f.key } ) ), { label: __( 'Logo', 'woocommerce-orders-invoice-pdf' ), value: 'logo' } ] }
						onChange={ setSelected }
					/>
					<ToggleControl
						label={ __( 'Visible', 'woocommerce-orders-invoice-pdf' ) }
						checked={ false !== sel.visible }
						onChange={ ( v ) => updateEl( selected, { visible: v } ) }
					/>
					{ isText && (
						<>
							<SelectControl
								label={ __( 'Align', 'woocommerce-orders-invoice-pdf' ) }
								value={ sel.align || 'left' }
								options={ [
									{ label: __( 'Left', 'woocommerce-orders-invoice-pdf' ), value: 'left' },
									{ label: __( 'Center', 'woocommerce-orders-invoice-pdf' ), value: 'center' },
									{ label: __( 'Right', 'woocommerce-orders-invoice-pdf' ), value: 'right' },
								] }
								onChange={ ( v ) => updateEl( selected, { align: v } ) }
							/>
							<ToggleControl
								label={ __( 'Bold', 'woocommerce-orders-invoice-pdf' ) }
								checked={ !! sel.bold }
								onChange={ ( v ) => updateEl( selected, { bold: v } ) }
							/>
							<RangeControl
								label={ __( 'Font size (px) — 0 = default', 'woocommerce-orders-invoice-pdf' ) }
								value={ sel.fontSize || 0 }
								onChange={ ( v ) => updateEl( selected, { fontSize: v || 0 } ) }
								min={ 0 }
								max={ 32 }
							/>
							<p style={ { margin: '12px 0 4px' } }>{ __( 'Text colour', 'woocommerce-orders-invoice-pdf' ) }</p>
							<ColorPalette value={ sel.color || '' } colors={ COLORS } onChange={ ( c ) => updateEl( selected, { color: c || '' } ) } />
						</>
					) }
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<div className="woi-lh-edit-row" style={ { display: 'flex', alignItems: 'flex-start', gap: '8px' } }>
					{ row }
				</div>
			</div>
		</>
	);
}

// Bare token save — valid + kses-safe. Layout lives in the woi_pdf_letterhead
// option + the shared header doc-option, not in the block.
export function letterheadSave( { attributes } ) {
	return <div { ...useBlockProps.save( appearanceProps( attributes ) ) }>{ '{{letterhead}}' }</div>;
}
