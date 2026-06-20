import { useState, useRef, useCallback, useEffect } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { safeHTML } from '@wordpress/dom';
import { STORE } from './previewStore';
import { parseOrderNumber } from './orderInput';
import { fetchOrders, fetchOrderTokens, orderRowTitle, orderMetaLine } from './preview';

export default function OrderPicker() {
	const [ term, setTerm ] = useState( '' );
	const [ results, setResults ] = useState( null );
	const [ open, setOpen ] = useState( false );
	const [ searching, setSearching ] = useState( false );
	const debounceRef = useRef( null );
	const boxRef = useRef( null );

	const { setOrder, setLoading } = useDispatch( STORE );
	const { orderLabel, loading } = useSelect( ( select ) => ( {
		orderLabel: select( STORE ).getOrderLabel(),
		loading: select( STORE ).isLoading(),
	} ), [] );

	const runSearch = useCallback( ( value ) => {
		setSearching( true );
		fetchOrders( value ).then( ( data ) => {
			setResults( data );
			setSearching( false );
			setOpen( true );
		} ).catch( () => setSearching( false ) );
	}, [] );

	const loadOrder = useCallback( ( id, label ) => {
		setOpen( false );
		setResults( null );
		setTerm( label || '' );
		setLoading( true );
		fetchOrderTokens( id ).then( ( res ) => {
			if ( res && res.tokens ) {
				setOrder( { tokens: res.tokens, orderLabel: res.order_label || label || '', orderId: id } );
			} else {
				setLoading( false );
			}
		} ).catch( () => setLoading( false ) );
	}, [ setOrder, setLoading ] );

	const onFocus = useCallback( () => {
		setOpen( true );
		if ( null === results ) { runSearch( '' ); } // focus → recents
	}, [ results, runSearch ] );

	const onChange = useCallback( ( e ) => {
		const value = e.target.value;
		setTerm( value );
		if ( debounceRef.current ) { clearTimeout( debounceRef.current ); }
		debounceRef.current = setTimeout( () => runSearch( value ), 300 );
	}, [ runSearch ] );

	const onKeyDown = useCallback( ( e ) => {
		if ( 'Enter' !== e.key ) { return; }
		e.preventDefault();
		if ( debounceRef.current ) { clearTimeout( debounceRef.current ); }
		const num = parseOrderNumber( term );
		if ( num ) {
			loadOrder( num, '#' + num );
		} else {
			runSearch( term );
		}
	}, [ term, loadOrder, runSearch ] );

	// Close the dropdown on outside click.
	useEffect( () => {
		function onDocClick( e ) {
			if ( boxRef.current && ! boxRef.current.contains( e.target ) ) { setOpen( false ); }
		}
		document.addEventListener( 'click', onDocClick );
		return () => document.removeEventListener( 'click', onDocClick );
	}, [] );

	const ids = results ? Object.keys( results ) : [];

	return (
		<div className="woi-order-picker" ref={ boxRef } style={ { position: 'relative', minWidth: '280px' } }>
			<div style={ { display: 'flex', alignItems: 'center', gap: '6px' } }>
				<input
					type="text"
					value={ term }
					onFocus={ onFocus }
					onChange={ onChange }
					onKeyDown={ onKeyDown }
					placeholder={ __( 'Order #, name or email (blank = recent)', 'woocommerce-orders-invoice-pdf' ) }
					style={ { flex: '1' } }
				/>
				{ ( searching || loading ) ? <Spinner /> : null }
			</div>
			{ orderLabel ? (
				<div style={ { fontSize: '11px', color: '#555', marginTop: '2px' } }>
					{ __( 'Order:', 'woocommerce-orders-invoice-pdf' ) } { orderLabel }
				</div>
			) : null }
			{ open && results ? (
				<ul className="woi-order-results" style={ { position: 'absolute', zIndex: 100001, left: 0, right: 0, top: '100%', margin: 0, padding: '4px 0', listStyle: 'none', background: '#fff', border: '1px solid #ccc', boxShadow: '0 2px 8px rgba(0,0,0,.15)', maxHeight: '320px', overflow: 'auto' } }>
					{ 0 === ids.length ? (
						<li style={ { padding: '8px 12px', color: '#777' } }>{ __( 'No orders found', 'woocommerce-orders-invoice-pdf' ) }</li>
					) : ids.map( ( id ) => {
						const d = results[ id ];
						return (
							<li key={ id }>
								<button
									type="button"
									className="button-link"
									onClick={ () => loadOrder( id, orderRowTitle( d ) ) }
									style={ { display: 'block', width: '100%', textAlign: 'left', padding: '6px 12px' } }
								>
									<span style={ { fontWeight: 600 } }>{ orderRowTitle( d ) }</span>
									<span style={ { display: 'block', fontSize: '11px', color: '#666' } }>
										<span dangerouslySetInnerHTML={ { __html: safeHTML( d.total_raw || '' ) } } />
										{ d.total_raw ? ' · ' : '' }
										{ orderMetaLine( d ) }
									</span>
								</button>
							</li>
						);
					} ) }
				</ul>
			) : null }
		</div>
	);
}
