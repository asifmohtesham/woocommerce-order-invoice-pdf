/* invoice-mark.jsx — STATIC Milano Leather brand mark + wordmark for the invoice.
   Uses the official vector paths from window.MILANO_PATHS (logo-data.js). No animation. */

function MilanoMark({ width = 64, hide = '#9E0A0E', mono = '#140858', paper = '#FFFFFF' }) {
  const P = window.MILANO_PATHS;
  const h = width / (480 / 400); // viewBox ratio 480:400
  return (
    <svg viewBox={P.viewBox} width={width} height={h} style={{ display: 'block' }} aria-label="Milano Leather">
      <g transform={P.transform}>
        <path d={P.hide} fill={hide} fillRule="evenodd" />
        <path d={P.inner} fill={paper} />
        <path d={P.mono} fill={mono} />
      </g>
    </svg>
  );
}

function MilanoWordmark({ color = '#140858', size = 13, sub = '#9E0A0E' }) {
  return (
    <div style={{ lineHeight: 1 }}>
      <div style={{
        fontFamily: "'Hertical Sans', 'Archivo', sans-serif",
        fontWeight: 400, fontSize: size, letterSpacing: '0.02em',
        color, whiteSpace: 'nowrap', display: 'flex', gap: '0.34em',
      }}>
        <span>MILANO</span><span>LEATHER</span>
      </div>
    </div>
  );
}

Object.assign(window, { MilanoMark, MilanoWordmark });
