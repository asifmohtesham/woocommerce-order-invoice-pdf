import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { renderedHtmlFromBlocks, mergeTokens, wrapForPreview, fetchOrderTokens, fetchOrders, orderRowTitle } from './preview';

export default function PreviewPanel( { blocks } ) {
	const iframeRef = useRef( null );
	const [ tokens, setTokens ] = useState( () => ( window.woiBlocks && window.woiBlocks.sampleData ) || null );
	const [ orderLabel, setOrderLabel ] = useState( '' );
	const [ results, setResults ] = useState( null );
	const [ term, setTerm ] = useState( '' );

	// Re-render the iframe (debounced) on block or token changes.
	useEffect( () => {
		const t = setTimeout( () => {
			const frame = iframeRef.current;
			if ( frame ) {
				frame.srcdoc = wrapForPreview( mergeTokens( renderedHtmlFromBlocks( blocks ), tokens ) );
			}
		}, 400 );
		return () => clearTimeout( t );
	}, [ blocks, tokens ] );

	// Load the last order's tokens on mount.
	useEffect( () => {
		fetchOrderTokens( null ).then( ( res ) => {
			if ( res && res.tokens ) {
				setTokens( res.tokens );
				if ( res.order_label ) { setOrderLabel( res.order_label ); }
			}
		} );
	}, [] );

	const onSearch = useCallback( () => {
		fetchOrders( term ).then( ( data ) => setResults( data ) );
	}, [ term ] );

	const onPick = useCallback( ( id, label ) => {
		setResults( null );
		setTerm( label );
		fetchOrderTokens( id ).then( ( res ) => {
			if ( res && res.tokens ) {
				setTokens( res.tokens );
				setOrderLabel( res.order_label || label );
			}
		} );
	}, [] );

	return (
		<div className="woi-block-preview" style={ { flex: '1', minWidth: '360px', borderLeft: '1px solid #ddd', display: 'flex', flexDirection: 'column' } }>
			<div className="woi-block-preview-bar" style={ { display: 'flex', gap: '8px', alignItems: 'center', padding: '8px', flexWrap: 'wrap' } }>
				<strong>{ __( 'Live preview', 'woocommerce-orders-invoice-pdf' ) }</strong>
				<input
					type="text"
					value={ term }
					onChange={ ( e ) => setTerm( e.target.value ) }
					onKeyDown={ ( e ) => { if ( 'Enter' === e.key ) { onSearch(); } } }
					placeholder={ __( 'Order #, name or email (blank = last order)', 'woocommerce-orders-invoice-pdf' ) }
					style={ { flex: '1', minWidth: '160px' } }
				/>
				<button type="button" className="button" onClick={ onSearch }>{ __( 'Find', 'woocommerce-orders-invoice-pdf' ) }</button>
				{ orderLabel ? <span style={ { color: '#555' } }>{ __( 'Order:', 'woocommerce-orders-invoice-pdf' ) } { orderLabel }</span> : null }
			</div>
			{ results ? (
				<ul className="woi-block-order-results" style={ { listStyle: 'none', margin: 0, padding: '4px 8px', maxHeight: '160px', overflow: 'auto', borderBottom: '1px solid #eee' } }>
					{ 0 === Object.keys( results ).length
						? <li style={ { color: '#777' } }>{ __( 'No orders found', 'woocommerce-orders-invoice-pdf' ) }</li>
						: Object.keys( results ).map( ( id ) => (
							<li key={ id }>
								<button type="button" className="button-link" onClick={ () => onPick( id, orderRowTitle( results[ id ] ) ) }>
									{ orderRowTitle( results[ id ] ) }
								</button>
							</li>
						) ) }
				</ul>
			) : null }
			<iframe
				ref={ iframeRef }
				title={ __( 'Live preview', 'woocommerce-orders-invoice-pdf' ) }
				style={ { flex: '1', width: '100%', border: '0', background: '#fff', minHeight: '60vh' } }
			/>
		</div>
	);
}
