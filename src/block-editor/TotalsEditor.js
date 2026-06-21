import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { Button, SelectControl, TextControl, Spinner } from '@wordpress/components';
import { chevronUp, chevronDown, trash } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';
import { getEditorConfig, saveEditorConfig } from './store';
import { renderableOptions } from './optionSchema';
import OptionField from './OptionField';

export default function TotalsEditor( { onTokens, onSaved, orderId } ) {
	const [ rows, setRows ] = useState( null );
	const [ schema, setSchema ] = useState( {} );
	const debounceRef = useRef( null );

	useEffect( () => {
		getEditorConfig()
			.then( ( r ) => { setRows( Array.isArray( r.totals?.values ) ? r.totals.values : [] ); setSchema( r.totals?.schema || {} ); } )
			.catch( () => setRows( [] ) );
	}, [] );

	const persist = useCallback( ( next ) => {
		if ( debounceRef.current ) { clearTimeout( debounceRef.current ); }
		debounceRef.current = setTimeout( () => {
			saveEditorConfig( { totals: next }, orderId ).then( ( res ) => {
				if ( res && res.tokens && onTokens ) { onTokens( res.tokens ); }
				else if ( onSaved ) { onSaved(); }
			} ).catch( () => {} );
		}, 250 );
	}, [ onTokens, onSaved, orderId ] );

	const update = ( next ) => { setRows( next ); persist( next ); };
	if ( null === rows ) { return <div className="woi-col-editor"><Spinner /></div>; }

	const typeTitle = ( t ) => ( schema[ t ] && schema[ t ].title ) || t;
	const move = ( i, d ) => {
		const j = i + d;
		if ( j < 0 || j >= rows.length ) { return; }
		const n = rows.slice(); const tmp = n[ i ]; n[ i ] = n[ j ]; n[ j ] = tmp; update( n );
	};
	const setKey = ( i, k, v ) => update( rows.map( ( c, idx ) => ( idx === i ? { ...c, [ k ]: v } : c ) ) );
	const remove = ( i ) => update( rows.filter( ( _, idx ) => idx !== i ) );
	const add = ( t ) => { if ( t ) { update( [ ...rows, { type: t } ] ); } };
	const addOptions = [ { label: __( 'Add total row…', 'woocommerce-orders-invoice-pdf' ), value: '' } ]
		.concat( Object.keys( schema ).map( ( t ) => ( { label: typeTitle( t ), value: t } ) ) );
	const hasOption = ( t, k ) => !! ( schema[ t ] && schema[ t ].options && schema[ t ].options[ k ] );

	return (
		<div className="woi-col-editor">
			{ rows.map( ( c, i ) => (
				<div className="woi-col-row" key={ i }>
					<div className="woi-col-head">
						<span className="woi-col-type">{ typeTitle( c.type ) }</span>
						<span className="woi-col-actions">
							<Button icon={ chevronUp } label={ __( 'Move up', 'woocommerce-orders-invoice-pdf' ) } onClick={ () => move( i, -1 ) } disabled={ 0 === i } />
							<Button icon={ chevronDown } label={ __( 'Move down', 'woocommerce-orders-invoice-pdf' ) } onClick={ () => move( i, 1 ) } disabled={ i === rows.length - 1 } />
							<Button icon={ trash } label={ __( 'Remove', 'woocommerce-orders-invoice-pdf' ) } onClick={ () => remove( i ) } isDestructive />
						</span>
					</div>
					{ hasOption( c.type, 'label' ) && (
						<TextControl
							label={ __( 'Label', 'woocommerce-orders-invoice-pdf' ) }
							value={ c.label || '' }
							placeholder={ typeTitle( c.type ) }
							onChange={ ( v ) => setKey( i, 'label', v ) }
							__nextHasNoMarginBottom
						/>
					) }
					{ renderableOptions( schema[ c.type ], { exclude: [ 'label' ] } ).map( ( { key, field } ) => (
						<OptionField key={ key } optionKey={ key } field={ field } value={ c[ key ] } onChange={ ( v ) => setKey( i, key, v ) } />
					) ) }
				</div>
			) ) }
			<div className="woi-col-add">
				<SelectControl value="" options={ addOptions } onChange={ add } __nextHasNoMarginBottom />
			</div>
		</div>
	);
}
