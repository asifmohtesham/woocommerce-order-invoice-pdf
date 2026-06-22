import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { Button, SelectControl, TextControl, TextareaControl, Spinner } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { chevronUp, chevronDown, trash } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';
import { STORE } from './previewStore';
import { getEditorConfig, saveEditorConfig } from './store';
import { setTextAlign, getTextAlign, renderableOptions } from './optionSchema';
import OptionField from './OptionField';

const ALIGN_OPTS = [
	{ label: __( 'Default', 'woocommerce-orders-invoice-pdf' ), value: '' },
	{ label: __( 'Left', 'woocommerce-orders-invoice-pdf' ), value: 'left' },
	{ label: __( 'Center', 'woocommerce-orders-invoice-pdf' ), value: 'center' },
	{ label: __( 'Right', 'woocommerce-orders-invoice-pdf' ), value: 'right' },
];
const STYLE_TARGET_OPTS = [
	{ label: __( 'Apply style to entire column', 'woocommerce-orders-invoice-pdf' ), value: 'both' },
	{ label: __( 'Apply style to column header', 'woocommerce-orders-invoice-pdf' ), value: 'header' },
	{ label: __( 'Apply style to column cells', 'woocommerce-orders-invoice-pdf' ), value: 'cells' },
];

export default function ColumnEditor( { onTokens, onSaved, onLiveEdit, orderId } ) {
	const [ columns, setColumns ] = useState( null );
	const [ schema, setSchema ] = useState( {} );
	const debounceRef = useRef( null );
	const { setLoading } = useDispatch( STORE );

	useEffect( () => {
		getEditorConfig()
			.then( ( r ) => {
				setColumns( Array.isArray( r.columns?.values ) ? r.columns.values : [] );
				setSchema( r.columns?.schema || {} );
			} )
			.catch( () => setColumns( [] ) );
	}, [] );

	// showBusy flags edits the canvas can't reflect instantly (toggling SKU/weight/
	// GTIN/meta/plugin columns, add/remove/reorder) — they need the server to re-render
	// the line-items token, so flip the preview into its loading state for feedback.
	// Instant edits (title/width/align) patch the canvas client-side, so they skip it.
	// The save's onTokens/onSaved already clears loading via the store reducer; the
	// explicit setLoading(false) here covers the no-token (e.g. no order) path + errors.
	const persist = useCallback( ( next, showBusy ) => {
		if ( showBusy ) { setLoading( true ); }
		if ( debounceRef.current ) { clearTimeout( debounceRef.current ); }
		debounceRef.current = setTimeout( () => {
			saveEditorConfig( { columns: next }, orderId ).then( ( res ) => {
				if ( res && res.tokens && onTokens ) { onTokens( res.tokens ); }
				else if ( onSaved ) { onSaved(); }
				if ( showBusy ) { setLoading( false ); }
			} ).catch( () => { if ( showBusy ) { setLoading( false ); } } );
		}, 250 );
	}, [ onTokens, onSaved, orderId, setLoading ] );

	const update = ( next ) => { setColumns( next ); persist( next, true ); };
	const editField = ( next, instant ) => {
		setColumns( next );
		if ( instant && onLiveEdit ) { onLiveEdit( next ); }
		persist( next, ! instant );
	};

	if ( null === columns ) {
		return <div className="woi-col-editor"><Spinner /></div>;
	}

	const typeTitle = ( t ) => ( schema[ t ] && schema[ t ].title ) || t;
	const move = ( i, d ) => {
		const j = i + d;
		if ( j < 0 || j >= columns.length ) { return; }
		const n = columns.slice();
		const tmp = n[ i ]; n[ i ] = n[ j ]; n[ j ] = tmp;
		update( n );
	};
	const setKey = ( i, k, v, instant ) => {
		const next = columns.map( ( c, idx ) => ( idx === i ? { ...c, [ k ]: v } : c ) );
		editField( next, !! instant );
	};
	const setAlign = ( i, align ) => {
		const next = columns.map( ( c, idx ) =>
			( idx === i ? { ...c, style: setTextAlign( c.style || '', align ), style_target: c.style_target || 'both' } : c ) );
		editField( next, true );
	};
	const remove = ( i ) => update( columns.filter( ( _, idx ) => idx !== i ) );
	const add = ( t ) => { if ( t ) { update( [ ...columns, { type: t } ] ); } };

	const hasOption = ( type, key ) => !! ( schema[ type ] && schema[ type ].options && schema[ type ].options[ key ] );
	const addOptions = [ { label: __( 'Add column…', 'woocommerce-orders-invoice-pdf' ), value: '' } ]
		.concat( Object.keys( schema ).filter( ( t ) => 'position' !== t ).map( ( t ) => ( { label: typeTitle( t ), value: t } ) ) );

	return (
		<div className="woi-col-editor">
			{ columns.map( ( c, i ) => {
				const opts = renderableOptions( schema[ c.type ], { exclude: [ 'label', 'label_ar', 'width', 'style', 'style_target' ] } );
				return (
					<div className="woi-col-row" key={ i }>
						<div className="woi-col-head">
							<span className="woi-col-type">{ typeTitle( c.type ) }</span>
							<span className="woi-col-actions">
								<Button icon={ chevronUp } label={ __( 'Move up', 'woocommerce-orders-invoice-pdf' ) } onClick={ () => move( i, -1 ) } disabled={ 0 === i } />
								<Button icon={ chevronDown } label={ __( 'Move down', 'woocommerce-orders-invoice-pdf' ) } onClick={ () => move( i, 1 ) } disabled={ i === columns.length - 1 } />
								<Button icon={ trash } label={ __( 'Remove', 'woocommerce-orders-invoice-pdf' ) } onClick={ () => remove( i ) } isDestructive />
							</span>
						</div>
						{ hasOption( c.type, 'label' ) && (
							<TextControl
								label={ __( 'Title', 'woocommerce-orders-invoice-pdf' ) }
								value={ c.label || '' }
								placeholder={ typeTitle( c.type ) }
								onChange={ ( v ) => setKey( i, 'label', v, true ) }
								__nextHasNoMarginBottom
							/>
						) }
						{ hasOption( c.type, 'label_ar' ) && (
							<TextControl
								label={ __( 'Arabic header', 'woocommerce-orders-invoice-pdf' ) }
								value={ c.label_ar || '' }
								placeholder={ __( 'Use default translation', 'woocommerce-orders-invoice-pdf' ) }
								onChange={ ( v ) => setKey( i, 'label_ar', v, false ) }
								__nextHasNoMarginBottom
							/>
						) }
						<div className="woi-col-grid">
							{ hasOption( c.type, 'width' ) && (
								<TextControl
									label={ __( 'Width %', 'woocommerce-orders-invoice-pdf' ) }
									type="number"
									value={ c.width || '' }
									min={ 0 } max={ 100 }
									onChange={ ( v ) => setKey( i, 'width', v, true ) }
									__nextHasNoMarginBottom
								/>
							) }
							{ hasOption( c.type, 'style' ) && (
								<SelectControl
									label={ __( 'Align', 'woocommerce-orders-invoice-pdf' ) }
									value={ getTextAlign( c.style || '' ) }
									options={ ALIGN_OPTS }
									onChange={ ( v ) => setAlign( i, v ) }
									__nextHasNoMarginBottom
								/>
							) }
						</div>
						{ opts.map( ( { key, field } ) => (
							<OptionField
								key={ key }
								optionKey={ key }
								field={ field }
								value={ c[ key ] }
								onChange={ ( v ) => setKey( i, key, v, false ) }
							/>
						) ) }
						{ hasOption( c.type, 'style' ) && (
							<TextareaControl
								label={ __( 'Style (inline CSS)', 'woocommerce-orders-invoice-pdf' ) }
								value={ c.style || '' }
								rows={ 2 }
								help={ __( 'e.g. color:#000; font-size:12px;', 'woocommerce-orders-invoice-pdf' ) }
								onChange={ ( v ) => setKey( i, 'style', v, false ) }
								__nextHasNoMarginBottom
							/>
						) }
						{ hasOption( c.type, 'style_target' ) && (
							<SelectControl
								label={ __( 'Style target', 'woocommerce-orders-invoice-pdf' ) }
								value={ c.style_target || 'both' }
								options={ STYLE_TARGET_OPTS }
								onChange={ ( v ) => setKey( i, 'style_target', v, false ) }
								__nextHasNoMarginBottom
							/>
						) }
					</div>
				);
			} ) }
			<div className="woi-col-add">
				<SelectControl value="" options={ addOptions } onChange={ add } __nextHasNoMarginBottom />
			</div>
		</div>
	);
}
