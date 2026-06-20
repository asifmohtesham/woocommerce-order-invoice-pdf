import { __, sprintf } from '@wordpress/i18n';

// Major tick positions (mm) from 0 to lengthMm at the given interval.
export function majorMarks( lengthMm, every = 10 ) {
	const out = [];
	for ( let mm = 0; mm <= lengthMm + 1e-6; mm += every ) {
		out.push( Math.round( mm ) );
	}
	return out;
}

// Interior page-break offsets (mm) for a content height, every pageMm.
// Excludes 0 and anything at/after the content end.
export function pageBoundaries( contentMm, pageMm = 297 ) {
	const out = [];
	for ( let mm = pageMm; mm < contentMm; mm += pageMm ) {
		out.push( mm );
	}
	return out;
}

export const A4_W = 210;
export const A4_H = 297;

// Horizontal ruler across the top of the page (0..210mm).
export function TopRuler() {
	return (
		<div className="woi-ruler woi-ruler--top" aria-hidden="true">
			{ majorMarks( A4_W ).map( ( mm ) => (
				<span key={ mm } className="woi-ruler-mark" style={ { left: mm + 'mm' } }>{ mm }</span>
			) ) }
		</div>
	);
}

// Vertical ruler down the left edge (0..contentMm), with bold page-boundary marks.
export function LeftRuler( { contentMm } ) {
	const boundaries = new Set( pageBoundaries( contentMm ) );
	return (
		<div className="woi-ruler woi-ruler--left" aria-hidden="true" style={ { height: contentMm + 'mm' } }>
			{ majorMarks( contentMm ).map( ( mm ) => (
				<span
					key={ mm }
					className={ 'woi-ruler-mark' + ( boundaries.has( mm ) ? ' is-page' : '' ) }
					style={ { top: mm + 'mm' } }
				>{ mm }</span>
			) ) }
		</div>
	);
}

// Dashed page-break guide lines overlaid on the page at each 297mm boundary.
export function PageGuides( { contentMm } ) {
	return (
		<div className="woi-page-guides" aria-hidden="true">
			{ pageBoundaries( contentMm ).map( ( mm, i ) => (
				<div key={ mm } className="woi-page-guide" style={ { top: mm + 'mm' } }>
					<span className="woi-page-guide-label">
						{ sprintf( /* translators: %d: page number */ __( 'Page %d', 'woocommerce-orders-invoice-pdf' ), i + 2 ) }
					</span>
				</div>
			) ) }
		</div>
	);
}
