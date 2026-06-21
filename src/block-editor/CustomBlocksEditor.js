import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { Button, SelectControl, TextControl, TextareaControl, Spinner } from '@wordpress/components';
import { trash } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';
import { getEditorConfig, saveEditorConfig } from './store';

export default function CustomBlocksEditor( { onSaved } ) {
	const [ rows, setRows ] = useState( null );
	const [ positions, setPositions ] = useState( {} );
	const [ types, setTypes ] = useState( {} );
	const debounceRef = useRef( null );

	useEffect( () => {
		getEditorConfig()
			.then( ( r ) => { setRows( Array.isArray( r.custom?.values ) ? r.custom.values : [] ); setPositions( r.custom?.positions || {} ); setTypes( r.custom?.types || {} ); } )
			.catch( () => setRows( [] ) );
	}, [] );

	const persist = useCallback( ( next ) => {
		if ( debounceRef.current ) { clearTimeout( debounceRef.current ); }
		debounceRef.current = setTimeout( () => {
			saveEditorConfig( { custom: next } ).then( () => { if ( onSaved ) { onSaved(); } } ).catch( () => {} );
		}, 300 );
	}, [ onSaved ] );

	const update = ( next ) => { setRows( next ); persist( next ); };
	if ( null === rows ) { return <div className="woi-col-editor"><Spinner /></div>; }

	const opts = ( map, head ) => [ { label: head, value: '' } ].concat( Object.keys( map ).map( ( k ) => ( { label: map[ k ], value: k } ) ) );
	const setKey = ( i, k, v ) => update( rows.map( ( c, idx ) => ( idx === i ? { ...c, [ k ]: v } : c ) ) );
	const remove = ( i ) => update( rows.filter( ( _, idx ) => idx !== i ) );
	const add = () => update( [ ...rows, { type: 'text', position: '', label: '', meta_key: '', text: '' } ] );

	return (
		<div className="woi-col-editor">
			{ rows.map( ( c, i ) => (
				<div className="woi-col-row" key={ i }>
					<div className="woi-col-head">
						<span className="woi-col-type">{ __( 'Custom block', 'woocommerce-orders-invoice-pdf' ) }</span>
						<span className="woi-col-actions">
							<Button icon={ trash } label={ __( 'Remove', 'woocommerce-orders-invoice-pdf' ) } onClick={ () => remove( i ) } isDestructive />
						</span>
					</div>
					<SelectControl label={ __( 'Type', 'woocommerce-orders-invoice-pdf' ) } value={ c.type || 'text' }
						options={ opts( types, __( 'Type…', 'woocommerce-orders-invoice-pdf' ) ) } onChange={ ( v ) => setKey( i, 'type', v ) } __nextHasNoMarginBottom />
					<SelectControl label={ __( 'Position', 'woocommerce-orders-invoice-pdf' ) } value={ c.position || '' }
						options={ opts( positions, __( 'Position…', 'woocommerce-orders-invoice-pdf' ) ) } onChange={ ( v ) => setKey( i, 'position', v ) } __nextHasNoMarginBottom />
					<TextControl label={ __( 'Label / header', 'woocommerce-orders-invoice-pdf' ) } value={ c.label || '' } onChange={ ( v ) => setKey( i, 'label', v ) } __nextHasNoMarginBottom />
					{ ( 'custom_field' === c.type || 'user_meta' === c.type ) && (
						<TextControl label={ __( 'Field name / meta key', 'woocommerce-orders-invoice-pdf' ) } value={ c.meta_key || '' } onChange={ ( v ) => setKey( i, 'meta_key', v ) } __nextHasNoMarginBottom />
					) }
					{ 'text' === c.type && (
						<TextareaControl label={ __( 'Text', 'woocommerce-orders-invoice-pdf' ) } value={ c.text || '' } rows={ 4 } onChange={ ( v ) => setKey( i, 'text', v ) } __nextHasNoMarginBottom />
					) }
				</div>
			) ) }
			<div className="woi-col-add">
				<Button variant="secondary" onClick={ add }>{ __( 'Add custom block', 'woocommerce-orders-invoice-pdf' ) }</Button>
			</div>
		</div>
	);
}
