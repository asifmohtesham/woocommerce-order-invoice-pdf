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
