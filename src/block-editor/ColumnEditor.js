import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { Button, SelectControl, TextControl, Spinner } from '@wordpress/components';
import { chevronUp, chevronDown, trash } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';
import { getColumns, saveColumns } from './store';

// Line-items column editor. Reads/writes the existing invoice `columns` setting
// (order, title, width %, alignment) via REST; the server line-items renderer
// reflects it. onSaved() lets the editor re-fetch the order tokens so the canvas
// live-updates.
export default function ColumnEditor( { onSaved } ) {
	const [ columns, setColumns ] = useState( null );
	const [ types, setTypes ] = useState( {} );
	const debounceRef = useRef( null );

	useEffect( () => {
		getColumns()
			.then( ( r ) => { setColumns( Array.isArray( r.columns ) ? r.columns : [] ); setTypes( r.types || {} ); } )
			.catch( () => setColumns( [] ) );
	}, [] );

	const persist = useCallback( ( next ) => {
		if ( debounceRef.current ) { clearTimeout( debounceRef.current ); }
		debounceRef.current = setTimeout( () => {
			saveColumns( next ).then( () => { if ( onSaved ) { onSaved(); } } ).catch( () => {} );
		}, 600 );
	}, [ onSaved ] );

	const update = ( next ) => { setColumns( next ); persist( next ); };

	if ( null === columns ) {
		return <div className="woi-col-editor"><Spinner /></div>;
	}

	const typeTitle = ( t ) => types[ t ] || t;
	const move = ( i, d ) => {
		const j = i + d;
		if ( j < 0 || j >= columns.length ) { return; }
		const n = columns.slice();
		const tmp = n[ i ]; n[ i ] = n[ j ]; n[ j ] = tmp;
		update( n );
	};
	const setField = ( i, k, v ) => update( columns.map( ( c, idx ) => ( idx === i ? { ...c, [ k ]: v } : c ) ) );
	const remove = ( i ) => update( columns.filter( ( _, idx ) => idx !== i ) );
	const add = ( t ) => { if ( t ) { update( [ ...columns, { type: t, label: '', width: '', align: '' } ] ); } };

	const addOptions = [ { label: __( 'Add column…', 'woocommerce-orders-invoice-pdf' ), value: '' } ]
		.concat( Object.keys( types ).map( ( t ) => ( { label: typeTitle( t ), value: t } ) ) );

	return (
		<div className="woi-col-editor">
			{ columns.map( ( c, i ) => (
				<div className="woi-col-row" key={ i }>
					<div className="woi-col-head">
						<span className="woi-col-type">{ typeTitle( c.type ) }</span>
						<span className="woi-col-actions">
							<Button icon={ chevronUp } label={ __( 'Move up', 'woocommerce-orders-invoice-pdf' ) } onClick={ () => move( i, -1 ) } disabled={ 0 === i } />
							<Button icon={ chevronDown } label={ __( 'Move down', 'woocommerce-orders-invoice-pdf' ) } onClick={ () => move( i, 1 ) } disabled={ i === columns.length - 1 } />
							<Button icon={ trash } label={ __( 'Remove', 'woocommerce-orders-invoice-pdf' ) } onClick={ () => remove( i ) } isDestructive />
						</span>
					</div>
					<TextControl
						label={ __( 'Title', 'woocommerce-orders-invoice-pdf' ) }
						value={ c.label || '' }
						placeholder={ typeTitle( c.type ) }
						onChange={ ( v ) => setField( i, 'label', v ) }
						__nextHasNoMarginBottom
					/>
					<div className="woi-col-grid">
						<TextControl
							label={ __( 'Width %', 'woocommerce-orders-invoice-pdf' ) }
							type="number"
							value={ c.width || '' }
							min={ 0 }
							max={ 100 }
							onChange={ ( v ) => setField( i, 'width', v ) }
							__nextHasNoMarginBottom
						/>
						<SelectControl
							label={ __( 'Align', 'woocommerce-orders-invoice-pdf' ) }
							value={ c.align || '' }
							options={ [
								{ label: __( 'Default', 'woocommerce-orders-invoice-pdf' ), value: '' },
								{ label: __( 'Left', 'woocommerce-orders-invoice-pdf' ), value: 'left' },
								{ label: __( 'Center', 'woocommerce-orders-invoice-pdf' ), value: 'center' },
								{ label: __( 'Right', 'woocommerce-orders-invoice-pdf' ), value: 'right' },
							] }
							onChange={ ( v ) => setField( i, 'align', v ) }
							__nextHasNoMarginBottom
						/>
					</div>
				</div>
			) ) }
			<div className="woi-col-add">
				<SelectControl value="" options={ addOptions } onChange={ add } __nextHasNoMarginBottom />
			</div>
		</div>
	);
}
