import { __, sprintf } from '@wordpress/i18n';
import { majorMarks, pageBoundaries } from './rulers';

// React ruler views. Kept separate from the pure math in ./rulers (which must
// stay @wordpress-free so its jest test can run); a distinct lowercase filename
// also avoids a case-collision with ./rulers on case-insensitive filesystems.

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
