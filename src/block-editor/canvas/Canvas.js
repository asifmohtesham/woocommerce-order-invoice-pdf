import { useRef, useState, useEffect } from '@wordpress/element';
import A4Canvas from './A4Canvas';
import { TopRuler, LeftRuler, PageGuides, A4_H } from './rulers';

const PX_PER_MM = 96 / 25.4;

// Gray scrollable stage holding the mm rulers and the A4 page. Measures the page
// height (px → mm) so the left ruler and page guides span the real content.
export default function Canvas( { previewCss } ) {
	const pageRef = useRef( null );
	const [ contentMm, setContentMm ] = useState( A4_H );

	useEffect( () => {
		const el = pageRef.current;
		if ( ! el || 'undefined' === typeof window.ResizeObserver ) { return undefined; }
		const ro = new window.ResizeObserver( () => {
			const mm = Math.max( A4_H, el.offsetHeight / PX_PER_MM );
			setContentMm( Math.ceil( mm ) );
		} );
		ro.observe( el );
		return () => ro.disconnect();
	}, [] );

	return (
		<div className="woi-canvas-scroll">
			<div className="woi-a4-frame">
				<TopRuler />
				<div className="woi-a4-frame-body">
					<LeftRuler contentMm={ contentMm } />
					<div className="woi-a4-page" ref={ pageRef }>
						<PageGuides contentMm={ contentMm } />
						<A4Canvas previewCss={ previewCss } />
					</div>
				</div>
			</div>
		</div>
	);
}
