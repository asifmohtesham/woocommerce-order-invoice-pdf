import { useRef, useState, useEffect } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import A4Canvas from './A4Canvas';
import { TopRuler, LeftRuler, PageGuides, A4_H } from './rulerView';
import { STORE } from '../previewStore';

const PX_PER_MM = 96 / 25.4;
const A4_PAGE_PX = Math.round( A4_H * PX_PER_MM );

// Gray scrollable stage holding the mm rulers and the A4 page.
//
// An <iframe> never auto-sizes to its content, so BlockCanvas's iframe falls back
// to the 150px HTML default and scrolls internally (the inner scrollbar bug). We
// drive the iframe element's height from its own document height instead, so it
// shows the whole page and the outer .woi-canvas-scroll owns the only scrollbar.
// (The iframe document also gets html,body{overflow:hidden} via A4_SHIM_CSS so a
// transient scrollbar can't trigger the horizontal/vertical scrollbar deadlock.)
export default function Canvas( { previewCss, optionsCss } ) {
	const pageRef = useRef( null );
	const [ contentMm, setContentMm ] = useState( A4_H );
	// Preview is mid-refresh — switching order, or a column/option change the canvas
	// can't reflect client-side (SKU/weight/GTIN/meta/plugin) that needs a server
	// re-render. Show an overlay so the change registers as "working", not "ignored".
	const loading = useSelect( ( select ) => select( STORE ).isLoading(), [] );

	useEffect( () => {
		const host = pageRef.current;
		if ( ! host ) { return undefined; }
		let ro = null;
		let raf = 0;
		let observed = null;

		function apply() {
			const iframe = host.querySelector( 'iframe' );
			const body = iframe && iframe.contentDocument && iframe.contentDocument.body;
			if ( ! iframe || ! body ) { return false; }
			const px = Math.max( A4_PAGE_PX, body.scrollHeight );
			iframe.style.height = px + 'px';
			setContentMm( Math.ceil( px / PX_PER_MM ) );
			// (Re)observe the live body so the height tracks blocks being added/removed.
			if ( observed !== body && 'undefined' !== typeof window.ResizeObserver ) {
				if ( ro ) { ro.disconnect(); }
				ro = new window.ResizeObserver( () => apply() );
				ro.observe( body );
				observed = body;
			}
			return true;
		}

		// Poll until BlockCanvas has mounted the iframe + its body; then the
		// ResizeObserver keeps the height in sync.
		function tick() {
			if ( ! apply() && 'undefined' !== typeof window.requestAnimationFrame ) {
				raf = window.requestAnimationFrame( tick );
			}
		}
		tick();

		return () => {
			if ( raf && 'undefined' !== typeof window.cancelAnimationFrame ) { window.cancelAnimationFrame( raf ); }
			if ( ro ) { ro.disconnect(); }
		};
	}, [] );

	return (
		<div className="woi-canvas-scroll">
			<div className="woi-a4-frame">
				<TopRuler />
				<div className="woi-a4-frame-body">
					<LeftRuler contentMm={ contentMm } />
					<div className="woi-a4-page" ref={ pageRef }>
						<PageGuides contentMm={ contentMm } />
						<A4Canvas previewCss={ previewCss } optionsCss={ optionsCss } />
						{ loading ? (
							<div className="woi-canvas-busy" aria-live="polite" role="status">
								<div className="woi-canvas-busy-inner">
									<Spinner />
									<span>{ __( 'Updating preview…', 'woocommerce-orders-invoice-pdf' ) }</span>
								</div>
							</div>
						) : null }
					</div>
				</div>
			</div>
		</div>
	);
}
