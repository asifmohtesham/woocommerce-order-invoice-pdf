import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { renderedHtmlFromBlocks, mergeTokens, wrapForPreview, fetchOrderTokens, fetchOrders, orderRowTitle } from './preview';
import { renderPdfPreview } from './pdfPreview';

export default function PreviewPanel( { blocks, source } ) {
	const iframeRef = useRef( null );
	const stageRef = useRef( null );
	const [ tab, setTab ] = useState( 'html' ); // 'html' | 'pdf'
	const [ tokens, setTokens ] = useState( () => ( window.woiBlocks && window.woiBlocks.sampleData ) || null );
	const [ orderLabel, setOrderLabel ] = useState( '' );
	const [ orderId, setOrderId ] = useState( null );
	const [ results, setResults ] = useState( null );
	const [ term, setTerm ] = useState( '' );
	const [ pdfStatus, setPdfStatus ] = useState( '' );

	// Re-render the live HTML iframe (debounced) on block or token changes, only on the HTML tab.
	useEffect( () => {
		if ( 'html' !== tab ) { return undefined; }
		const t = setTimeout( () => {
			const frame = iframeRef.current;
			if ( frame ) {
				frame.srcdoc = wrapForPreview( mergeTokens( renderedHtmlFromBlocks( blocks ), tokens ) );
			}
		}, 400 );
		return () => clearTimeout( t );
	}, [ blocks, tokens, tab ] );

	// Load the last order's tokens on mount.
	useEffect( () => {
		fetchOrderTokens( null ).then( ( res ) => {
			if ( res && res.tokens ) {
				setTokens( res.tokens );
				if ( res.order_label ) { setOrderLabel( res.order_label ); }
			}
		} );
	}, [] );

	const renderPdf = useCallback( () => {
		renderPdfPreview( { stageEl: stageRef.current, blocks, orderId, onStatus: setPdfStatus } );
	}, [ blocks, orderId ] );

	// Render the PDF once when the PDF tab becomes active.
	useEffect( () => {
		if ( 'pdf' === tab ) { renderPdf(); }
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ tab ] );

	const onSearch = useCallback( () => {
		fetchOrders( term ).then( ( data ) => setResults( data ) );
	}, [ term ] );

	const onPick = useCallback( ( id, label ) => {
		setResults( null );
		setTerm( label );
		setOrderId( id );
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
				<div className="woi-block-preview-tabs" role="group" style={ { display: 'flex', gap: '4px' } }>
					<button type="button" className={ 'button' + ( 'html' === tab ? ' button-primary' : '' ) } onClick={ () => setTab( 'html' ) }>{ __( 'Live HTML', 'woocommerce-orders-invoice-pdf' ) }</button>
					<button type="button" className={ 'button' + ( 'pdf' === tab ? ' button-primary' : '' ) } onClick={ () => setTab( 'pdf' ) }>{ __( 'PDF', 'woocommerce-orders-invoice-pdf' ) }</button>
				</div>
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
				hidden={ 'html' !== tab }
				style={ { flex: '1', width: '100%', border: '0', background: '#fff', minHeight: '60vh', display: 'html' === tab ? 'block' : 'none' } }
			/>
			<div className="woi-block-pdf" hidden={ 'pdf' !== tab } style={ { flex: '1', display: 'pdf' === tab ? 'flex' : 'none', flexDirection: 'column', minHeight: '60vh' } }>
				<div style={ { padding: '8px', display: 'flex', gap: '8px', alignItems: 'center', flexWrap: 'wrap' } }>
					<button type="button" className="button button-primary" onClick={ renderPdf }>{ __( 'Render PDF', 'woocommerce-orders-invoice-pdf' ) }</button>
					<span aria-live="polite">{ pdfStatus }</span>
					{ 'blocks' !== source ? (
						<span style={ { color: '#b32d2e' } }>{ __( 'PDF reflects the active source. Set "PDF source" to "Block editor" above to preview the block design.', 'woocommerce-orders-invoice-pdf' ) }</span>
					) : null }
				</div>
				<div className="woi-a4-scroll" style={ { flex: '1', overflow: 'auto', background: '#525659', padding: '16px', display: 'flex', flexDirection: 'column', alignItems: 'center', gap: '16px' } }>
					<div className="woi-a4-stage" ref={ stageRef } style={ { width: 'min(100%, 820px)', display: 'flex', flexDirection: 'column', alignItems: 'stretch', gap: '16px' } } />
				</div>
			</div>
		</div>
	);
}
