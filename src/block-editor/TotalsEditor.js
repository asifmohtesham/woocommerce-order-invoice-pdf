import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { Button, SelectControl, TextControl, Spinner } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { chevronUp, chevronDown, chevronRight, trash } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';
import { STORE } from './previewStore';
import { getEditorConfig, saveEditorConfig } from './store';
import { renderableOptions } from './optionSchema';
import OptionField from './OptionField';
import * as collapse from './collapseState';

export default function TotalsEditor( { onTokens, onSaved, orderId } ) {
	const [ rows, setRows ] = useState( null );
	const [ schema, setSchema ] = useState( {} );
	const [ secondaryDefaults, setSecondaryDefaults ] = useState( {} );
	// Ephemeral UI state: Set of collapsed row indices. Seeded all-collapsed once
	// rows load; never persisted (resets on reload). Mirrors ColumnEditor.
	const [ collapsed, setCollapsed ] = useState( new Set() );
	const debounceRef = useRef( null );
	const { setLoading } = useDispatch( STORE );

	useEffect( () => {
		getEditorConfig()
			.then( ( r ) => {
				const values = Array.isArray( r.totals?.values ) ? r.totals.values : [];
				setRows( values );
				setCollapsed( collapse.allCollapsed( values.length ) );
				setSchema( r.totals?.schema || {} );
				setSecondaryDefaults( r.totals?.secondary_defaults || {} );
			} )
			.catch( () => setRows( [] ) );
	}, [] );

	// Every total-row edit needs a server re-render, so flip the preview into its
	// loading state for feedback (cleared by onTokens/onSaved via the store reducer;
	// the explicit setLoading(false) covers the no-token / error paths). Mirrors ColumnEditor.
	const persist = useCallback( ( next ) => {
		setLoading( true );
		if ( debounceRef.current ) { clearTimeout( debounceRef.current ); }
		debounceRef.current = setTimeout( () => {
			saveEditorConfig( { totals: next }, orderId ).then( ( res ) => {
				if ( res && res.tokens && onTokens ) { onTokens( res.tokens ); }
				else if ( onSaved ) { onSaved(); }
				setLoading( false );
			} ).catch( () => setLoading( false ) );
		}, 250 );
	}, [ onTokens, onSaved, orderId, setLoading ] );

	const update = ( next ) => { setRows( next ); persist( next ); };
	if ( null === rows ) { return <div className="woi-col-editor"><Spinner /></div>; }

	const typeTitle = ( t ) => ( schema[ t ] && schema[ t ].title ) || t;
	const move = ( i, d ) => {
		const j = i + d;
		if ( j < 0 || j >= rows.length ) { return; }
		const n = rows.slice(); const tmp = n[ i ]; n[ i ] = n[ j ]; n[ j ] = tmp;
		setCollapsed( collapse.move( collapsed, i, j ) ); update( n );
	};
	const setKey = ( i, k, v ) => update( rows.map( ( c, idx ) => ( idx === i ? { ...c, [ k ]: v } : c ) ) );
	const remove = ( i ) => { setCollapsed( collapse.remove( collapsed, i ) ); update( rows.filter( ( _, idx ) => idx !== i ) ); };
	// Appended row renders expanded (collapse set unchanged) so the user can edit it.
	const add = ( t ) => { if ( t ) { setCollapsed( collapse.add( collapsed ) ); update( [ ...rows, { type: t } ] ); } };
	const toggleCollapse = ( i ) => setCollapsed( collapse.toggle( collapsed, i ) );
	const allCollapsed = collapse.isAllCollapsed( collapsed, rows.length );
	const toggleAll = () => setCollapsed( allCollapsed ? new Set() : collapse.allCollapsed( rows.length ) );
	const addOptions = [ { label: __( 'Add total row…', 'woocommerce-orders-invoice-pdf' ), value: '' } ]
		.concat( Object.keys( schema ).map( ( t ) => ( { label: typeTitle( t ), value: t } ) ) );
	const hasOption = ( t, k ) => !! ( schema[ t ] && schema[ t ].options && schema[ t ].options[ k ] );

	return (
		<div className="woi-col-editor">
			{ rows.length > 0 && (
				<div className="woi-col-bulk">
					<Button variant="tertiary" onClick={ toggleAll }>
						{ allCollapsed
							? __( 'Expand all', 'woocommerce-orders-invoice-pdf' )
							: __( 'Collapse all', 'woocommerce-orders-invoice-pdf' ) }
					</Button>
				</div>
			) }
			{ rows.map( ( c, i ) => {
				const isCollapsed = collapsed.has( i );
				return (
				<div className="woi-col-row" key={ i }>
					<div className="woi-col-head">
						<Button
							className="woi-col-toggle"
							icon={ isCollapsed ? chevronRight : chevronDown }
							label={ isCollapsed ? __( 'Expand row', 'woocommerce-orders-invoice-pdf' ) : __( 'Collapse row', 'woocommerce-orders-invoice-pdf' ) }
							onClick={ () => toggleCollapse( i ) }
						/>
						<button
							type="button"
							className="woi-col-type"
							aria-expanded={ ! isCollapsed }
							onClick={ () => toggleCollapse( i ) }
						>
							{ typeTitle( c.type ) }
						</button>
						<span className="woi-col-actions">
							<Button icon={ chevronUp } label={ __( 'Move up', 'woocommerce-orders-invoice-pdf' ) } onClick={ () => move( i, -1 ) } disabled={ 0 === i } />
							<Button icon={ chevronDown } label={ __( 'Move down', 'woocommerce-orders-invoice-pdf' ) } onClick={ () => move( i, 1 ) } disabled={ i === rows.length - 1 } />
							<Button icon={ trash } label={ __( 'Remove', 'woocommerce-orders-invoice-pdf' ) } onClick={ () => remove( i ) } isDestructive />
						</span>
					</div>
					{ ! isCollapsed && <>
					{ hasOption( c.type, 'label' ) && (
						<TextControl
							label={ __( 'Label', 'woocommerce-orders-invoice-pdf' ) }
							value={ c.label || '' }
							placeholder={ typeTitle( c.type ) }
							onChange={ ( v ) => setKey( i, 'label', v ) }
							__nextHasNoMarginBottom
						/>
					) }
					{ hasOption( c.type, 'label_ar' ) && (
						<TextControl
							label={ __( 'Arabic label', 'woocommerce-orders-invoice-pdf' ) }
							value={ c.label_ar || '' }
							placeholder={ secondaryDefaults[ c.type ] || __( 'No translation — enter Arabic', 'woocommerce-orders-invoice-pdf' ) }
							help={ secondaryDefaults[ c.type ]
								? __( 'Leave blank to inherit the default shown above.', 'woocommerce-orders-invoice-pdf' )
								: __( 'No default translation for this row — enter the Arabic label.', 'woocommerce-orders-invoice-pdf' ) }
							onChange={ ( v ) => setKey( i, 'label_ar', v ) }
							__nextHasNoMarginBottom
						/>
					) }
					{ renderableOptions( schema[ c.type ], { exclude: [ 'label', 'label_ar' ] } ).map( ( { key, field } ) => (
						<OptionField key={ key } optionKey={ key } field={ field } value={ c[ key ] } onChange={ ( v ) => setKey( i, key, v ) } />
					) ) }
					</> }
				</div>
				);
			} ) }
			<div className="woi-col-add">
				<SelectControl value="" options={ addOptions } onChange={ add } __nextHasNoMarginBottom />
			</div>
		</div>
	);
}
