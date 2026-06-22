import { useState, useRef, useCallback, useEffect } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { Button, Spinner } from '@wordpress/components';
import { search as searchIcon } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';
import { safeHTML } from '@wordpress/dom';
import { STORE } from './previewStore';
import { parseOrderNumber, nextActiveIndex } from './orderInput';
import { fetchOrders, fetchOrderTokens, orderRowTitle, orderMetaLine } from './preview';

export default function OrderPicker() {
	const [ term, setTerm ] = useState( '' );
	const [ results, setResults ] = useState( null );
	const [ open, setOpen ] = useState( false );
	const [ searching, setSearching ] = useState( false );
	// Index of the keyboard-highlighted result (-1 = none). Drives arrow-key
	// navigation and aria-activedescendant on the input.
	const [ active, setActive ] = useState( -1 );
	// After an order is loaded the search box collapses to a compact chip + a
	// search icon; clicking either expands the input again to pick a new order.
	const [ expanded, setExpanded ] = useState( false );
	const debounceRef = useRef( null );
	const boxRef = useRef( null );
	const inputRef = useRef( null );
	const listRef = useRef( null );

	const { setOrder, setLoading } = useDispatch( STORE );
	const { orderLabel, loading } = useSelect( ( select ) => ( {
		orderLabel: select( STORE ).getOrderLabel(),
		loading: select( STORE ).isLoading(),
	} ), [] );

	const collapsed = !! orderLabel && ! expanded;
	const ids = results ? Object.keys( results ) : [];

	// Focus the input when the search expands so the user can type immediately.
	useEffect( () => {
		if ( expanded && inputRef.current ) { inputRef.current.focus(); }
	}, [ expanded ] );

	// A fresh result set invalidates any prior highlight.
	useEffect( () => { setActive( -1 ); }, [ results ] );

	// Keep the highlighted option scrolled into view as the user arrows through.
	useEffect( () => {
		if ( active < 0 || ! listRef.current ) { return; }
		const el = listRef.current.querySelector( '[data-active="true"]' );
		if ( el ) { el.scrollIntoView( { block: 'nearest' } ); }
	}, [ active ] );

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
				setExpanded( false ); // collapse back to the chip once an order is picked
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
		// Escape closes the dropdown without changing the term.
		if ( 'Escape' === e.key ) {
			if ( open ) { e.preventDefault(); setOpen( false ); setActive( -1 ); }
			return;
		}
		// Arrow / Home / End move the highlight through the results. The first
		// arrow press also opens the list (loading recents if nothing is shown).
		if ( [ 'ArrowDown', 'ArrowUp', 'Home', 'End' ].includes( e.key ) ) {
			if ( ! open ) { setOpen( true ); }
			if ( null === results ) { runSearch( '' ); return; }
			if ( ids.length ) {
				e.preventDefault();
				setActive( ( cur ) => nextActiveIndex( e.key, cur, ids.length ) );
			}
			return;
		}
		if ( 'Enter' !== e.key ) { return; }
		e.preventDefault();
		if ( debounceRef.current ) { clearTimeout( debounceRef.current ); }
		// A highlighted result takes precedence over re-parsing the raw term.
		if ( open && active >= 0 && active < ids.length ) {
			const id = ids[ active ];
			loadOrder( id, orderRowTitle( results[ id ] ) );
			return;
		}
		const num = parseOrderNumber( term );
		if ( num ) {
			loadOrder( num, '#' + num );
		} else {
			runSearch( term );
		}
	}, [ open, results, ids, active, term, loadOrder, runSearch ] );

	// Close the dropdown on outside click; collapse back to the chip if an order
	// is already loaded (so the toolbar isn't left with a stray open input).
	useEffect( () => {
		function onDocClick( e ) {
			if ( boxRef.current && ! boxRef.current.contains( e.target ) ) {
				setOpen( false );
				if ( orderLabel ) { setExpanded( false ); }
			}
		}
		document.addEventListener( 'click', onDocClick );
		return () => document.removeEventListener( 'click', onDocClick );
	}, [ orderLabel ] );

	// ---- Collapsed: order chip + search icon (post-load) ----
	if ( collapsed ) {
		let no = '';
		let name = orderLabel;
		const dash = orderLabel.indexOf( ' — ' );
		if ( -1 !== dash ) { no = orderLabel.slice( 0, dash ); name = orderLabel.slice( dash + 3 ); }
		return (
			<div className="woi-order-picker woi-op-collapsed" ref={ boxRef }>
				<button
					type="button"
					className="woi-op-chip"
					onClick={ () => setExpanded( true ) }
					title={ orderLabel }
				>
					{ no ? <span className="woi-op-chipno">{ no }</span> : null }
					<span className="woi-op-chipname">{ name }</span>
				</button>
				{ loading ? <Spinner /> : (
					<Button
						icon={ searchIcon }
						label={ __( 'Change order', 'woocommerce-orders-invoice-pdf' ) }
						onClick={ () => setExpanded( true ) }
					/>
				) }
			</div>
		);
	}

	// ---- Expanded: search input + results dropdown ----
	return (
		<div className="woi-order-picker" ref={ boxRef } style={ { position: 'relative', minWidth: '280px' } }>
			<div style={ { display: 'flex', alignItems: 'center', gap: '6px' } }>
				<input
					ref={ inputRef }
					type="text"
					value={ term }
					onFocus={ onFocus }
					onChange={ onChange }
					onKeyDown={ onKeyDown }
					placeholder={ __( 'Order #, name or email (blank = recent)', 'woocommerce-orders-invoice-pdf' ) }
					style={ { flex: '1' } }
					role="combobox"
					aria-expanded={ open && !! results }
					aria-controls="woi-op-listbox"
					aria-autocomplete="list"
					aria-activedescendant={ ( open && active >= 0 && active < ids.length ) ? `woi-op-opt-${ ids[ active ] }` : undefined }
				/>
				{ ( searching || loading ) ? <Spinner /> : null }
			</div>
			{ open && results ? (
				<ul ref={ listRef } id="woi-op-listbox" role="listbox" className="woi-order-results" style={ { position: 'absolute', zIndex: 100001, left: 0, right: 0, top: '100%', margin: 0, padding: '4px 0', listStyle: 'none', background: '#fff', border: '1px solid #ccc', boxShadow: '0 2px 8px rgba(0,0,0,.15)', maxHeight: '320px', overflow: 'auto' } }>
					{ 0 === ids.length ? (
						<li style={ { padding: '8px 12px', color: '#777' } }>{ __( 'No orders found', 'woocommerce-orders-invoice-pdf' ) }</li>
					) : ids.map( ( id, idx ) => {
						const d = results[ id ];
						const isActive = idx === active;
						return (
							<li
								key={ id }
								id={ `woi-op-opt-${ id }` }
								role="option"
								aria-selected={ isActive }
								data-active={ isActive ? 'true' : undefined }
							>
								<button
									type="button"
									className="button-link"
									tabIndex={ -1 }
									onClick={ () => loadOrder( id, orderRowTitle( d ) ) }
									onMouseEnter={ () => setActive( idx ) }
									style={ { display: 'block', width: '100%', textAlign: 'left', padding: '6px 12px', background: isActive ? '#f0f0f1' : 'transparent' } }
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
