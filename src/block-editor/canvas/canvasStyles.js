const CSS =
	'.woi-canvas-scroll{flex:1;min-height:0;overflow:auto;background:#B6B0A4;padding:34px}' +
	'.woi-a4-frame{position:relative;width:max-content;margin:0 auto;padding-left:8mm;padding-top:6mm}' +
	'.woi-a4-frame-body{position:relative;display:block}' +
	// top ruler
	'.woi-ruler{position:relative;background:#f3f3f3;color:#555;font-size:8px;line-height:1}' +
	'.woi-ruler--top{height:6mm;width:210mm;margin-left:8mm;position:sticky;top:0;z-index:3;' +
		'background-image:repeating-linear-gradient(to right,#bbb 0,#bbb 0.18mm,transparent 0.18mm,transparent 1mm),' +
		'repeating-linear-gradient(to right,#888 0,#888 0.25mm,transparent 0.25mm,transparent 10mm)}' +
	'.woi-ruler--top .woi-ruler-mark{position:absolute;top:0.5mm;transform:translateX(1px)}' +
	// left ruler
	'.woi-ruler--left{position:absolute;left:0;top:0;width:8mm;font-size:8px;' +
		'background-image:repeating-linear-gradient(to bottom,#bbb 0,#bbb 0.18mm,transparent 0.18mm,transparent 1mm),' +
		'repeating-linear-gradient(to bottom,#888 0,#888 0.25mm,transparent 0.25mm,transparent 10mm)}' +
	'.woi-ruler--left .woi-ruler-mark{position:absolute;left:0.5mm;transform:translateY(-1px)}' +
	'.woi-ruler--left .woi-ruler-mark.is-page{color:#c0392b;font-weight:700}' +
	// page
	'.woi-a4-page{position:relative;margin-left:8mm;width:210mm;min-height:297mm;background:#fff;' +
		'box-shadow:0 8px 34px rgba(0,0,0,.24)}' +
	'.woi-a4-page .block-editor-block-canvas,.woi-a4-page iframe{width:210mm;border:0;display:block}' +
	// "Updating preview…" overlay shown while the line-items token is re-rendered
	// server-side (z-index 5 sits above the page guides at z-index 2). The badge is
	// pinned near the top of the (tall, scrollable) A4 page so it stays in view.
	'.woi-canvas-busy{position:absolute;inset:0;z-index:5;display:flex;align-items:flex-start;justify-content:center;background:rgba(255,255,255,.5)}' +
	'.woi-canvas-busy-inner{position:sticky;top:40mm;display:flex;align-items:center;gap:8px;padding:8px 16px;background:#fff;border:1px solid #DEDAD1;border-radius:6px;box-shadow:0 4px 18px rgba(0,0,0,.18);font-size:13px;color:#140858}' +
	// fallback (no BlockCanvas): apply preview CSS scoped, padded like the page
	'.woi-a4-fallback{padding:15mm;box-sizing:border-box;min-height:297mm}' +
	// page-break guides
	'.woi-page-guides{position:absolute;inset:0;pointer-events:none;z-index:2}' +
	'.woi-page-guide{position:absolute;left:0;right:0;border-top:1px dashed #c0392b}' +
	'.woi-page-guide-label{position:absolute;right:2mm;top:1mm;font-size:8px;color:#c0392b;background:#fff;padding:0 2px}' +
	// native InterfaceSkeleton wrapper — give the skeleton a sized, positioned host
	'.woi-block-interface-wrap{position:relative;height:80vh;min-height:600px;border:1px solid #ddd}' +
	'.woi-block-interface{height:100%}' +
	// WordPress does NOT register a standalone 'wp-interface' STYLE handle (the
	// .interface-interface-skeleton flex rules ship inside wp-edit-post/wp-editor
	// styles, which we don't load). On this custom page the skeleton would otherwise
	// have no layout CSS and block-stack header/content/sidebar vertically. Own the
	// minimal native layout here so the sidebar sits as a RIGHT column beside the canvas.
	// NB: `woi-block-interface` and `interface-interface-skeleton` are on the SAME
	// element (InterfaceSkeleton's className is appended to its own class), so this
	// must be a COMPOUND selector (no space) — a descendant selector never matched,
	// leaving the skeleton content-height. That let a tall inspector (the line-item
	// column editor) grow the page instead of scrolling the sidebar internally.
	'.woi-block-interface.interface-interface-skeleton{display:flex;flex-direction:column;height:100%}' +
	// InterfaceSkeleton wraps header + body in an __editor element. It defaults to
	// flex:0 1 auto / display:block, so it grows with content and never constrains
	// the body — which let a tall inspector grow the page. Make it the flex column
	// that fills the skeleton so the body (and the sidebar) get a bounded height
	// and scroll internally.
	'.woi-block-interface .interface-interface-skeleton__editor{flex:1;display:flex;flex-direction:column;min-height:0;overflow:hidden}' +
	'.woi-block-interface .interface-interface-skeleton__header{flex-shrink:0;border-top:1px solid #DEDAD1;border-bottom:1px solid #DEDAD1;background:#fff}' +
	'.woi-block-interface .interface-interface-skeleton__body{flex:1;display:flex;flex-direction:row;min-height:0;overflow:hidden}' +
	'.woi-block-interface .interface-interface-skeleton__content{flex:1 1 auto;min-width:0;display:flex;flex-direction:column;overflow:auto}' +
	'.woi-block-interface .interface-interface-skeleton__sidebar{flex:0 0 288px;width:288px;min-width:288px;overflow:auto;border-left:1px solid #DEDAD1;background:#fff}' +
	// PDF preview panel below the editor (was styled by the retired layout.js)
	'.woi-block-preview{display:flex;flex-direction:column;min-height:440px;border:1px solid #ddd;border-top:0;margin-bottom:16px}' +
	// full-screen: lift the skeleton host out of admin flow to cover the viewport.
	// height MUST be a definite value (100vh), not auto — the skeleton inside is
	// height:100% and a % height can't resolve against an auto-height parent, which
	// collapses the whole editor to zero height (blank screen). 80vh works in normal
	// mode for the same reason; full screen needs its own definite height.
	'.woi-block-interface-wrap.is-fullscreen{position:fixed;inset:0;height:100vh;width:100vw;z-index:100000;border:0;background:#fff;overflow:hidden}' +
	'body.woi-block-fullscreen{overflow:hidden}' +
	// secondary sidebar (list view) panel
	'.woi-block-interface .interface-interface-skeleton__secondary-sidebar{flex:0 0 262px;width:262px;min-width:262px;overflow:auto;border-right:1px solid #DEDAD1;background:#fff}' +
	// InterfaceSkeleton wraps the secondary-sidebar content in a framer-motion div
	// with inline `width:fit-content`; the wp-interface stylesheet (which we don't
	// load) would normally override it. Without that, the list view shrinks to its
	// content (~100px) instead of filling the 281px panel. Force it to full width.
	'.woi-block-interface .interface-interface-skeleton__secondary-sidebar > div{width:100% !important}' +
	'.woi-block-listview{padding:8px}';

export default function injectCanvasStyles() {
	if ( document.getElementById( 'woi-canvas-styles' ) ) { return; }
	const el = document.createElement( 'style' );
	el.id = 'woi-canvas-styles';
	el.textContent = CSS;
	document.head.appendChild( el );
}
