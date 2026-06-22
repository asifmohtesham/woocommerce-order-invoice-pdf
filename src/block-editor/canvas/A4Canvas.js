import { BlockCanvas, BlockList, WritingFlow, ObserveTyping } from '@wordpress/block-editor';

// Injected into the BlockCanvas iframe head, after the shared document CSS.
// Sizes the body as an A4 page and neutralises WP block chrome so the canvas
// reads as the printed document.
export const A4_SHIM_CSS =
	// overflow:hidden — the iframe is sized to its content by Canvas.js, so it must
	// never grow its own scrollbar (a transient one starts a horizontal/vertical
	// scrollbar deadlock that leaves a stuck inner scroll window).
	'html,body{margin:0;padding:0;background:transparent;overflow:hidden}' +
	'body{width:210mm;min-height:297mm;margin:0;padding:15mm;box-sizing:border-box;background:#fff}' +
	'.block-editor-block-list__layout.is-root-container{padding:0}' +
	'.block-editor-block-list__block{margin-top:0;margin-bottom:0}' +
	'.block-editor-block-list__block::before,.block-editor-block-list__block::after{display:none !important}' +
	// Selection / hover affordances, restyled to the redesign: a faint accent ring
	// on hover and a 1.5px accent ring + barely-there tint when selected. The block
	// wrapper is positioned, so an inset box-shadow reads as a ring without taking
	// layout space (mPDF/print never sees this — it lives only in the editor iframe).
	'.block-editor-block-list__block{border-radius:3px}' +
	'.block-editor-block-list__block:hover{box-shadow:0 0 0 1.5px rgba(20,8,88,.16)}' +
	'.is-selected.block-editor-block-list__block,.is-selected.block-editor-block-list__block:hover{box-shadow:0 0 0 1.5px #140858;background:rgba(20,8,88,.025)}' +
	'.is-selected.block-editor-block-list__block::before{display:none !important}' +
	'.woi-pagebreak{border-top:1px dashed #999;margin:0;height:0;page-break-after:auto}' +
	'.woi-token-empty{outline:1px dashed #c8c8c8;outline-offset:2px;color:#9aa;min-height:1em}' +
	// The invalid-block warning ("This block contains unexpected or invalid
	// content" + Attempt Block Recovery) renders INSIDE this iframe, which does
	// not load wp-components / wp-block-editor CSS — so without these rules the
	// warning box and its primary button fall back to bare browser defaults.
	// Restore WP's appearance: bordered box + blue primary button + icon kebab.
	'.block-editor-warning{display:flex;flex-wrap:wrap;align-items:center;gap:12px;border:1px solid #ccc;border-radius:2px;background:#fff;padding:12px 16px;margin:0;color:#1e1e1e}' +
	'.block-editor-warning__contents{display:flex;flex-wrap:wrap;align-items:center;gap:12px;width:100%}' +
	'.block-editor-warning__message{margin:0;font-size:13px;line-height:1.4;flex:1 1 auto}' +
	'.block-editor-warning__actions{display:flex;gap:8px;align-items:center;margin:0}' +
	'.block-editor-warning .components-button.is-primary{background:#3858e9;color:#fff;border:1px solid #3858e9;border-radius:2px;padding:6px 12px;font-size:13px;line-height:1.4;cursor:pointer;box-shadow:none;text-decoration:none}' +
	'.block-editor-warning .components-button.is-primary:hover{background:#2145e6;color:#fff}' +
	'.block-editor-warning .components-button.has-icon{padding:6px;min-width:0;background:transparent;border:0;color:#1e1e1e;cursor:pointer;border-radius:2px}' +
	'.block-editor-warning .components-button.has-icon:hover{background:rgba(0,0,0,.06)}';

export function hasBlockCanvas() {
	return 'function' === typeof BlockCanvas;
}

// The A4 page itself. Prefers the isolated BlockCanvas iframe; falls back to an
// in-DOM scoped render when BlockCanvas is unavailable in the installed WP.
export default function A4Canvas( { previewCss, optionsCss } ) {
	if ( hasBlockCanvas() ) {
		// optionsCss (accent/density/font/arabic/thumbs/header) goes LAST so it
		// overrides the base previewCss; it updates when a Document option changes,
		// which re-renders BlockCanvas and live-refreshes the iframe styles.
		const styles = [ { css: previewCss || '' }, { css: A4_SHIM_CSS }, { css: optionsCss || '' } ];
		return <BlockCanvas height="100%" styles={ styles } />;
	}
	return (
		<div className="woi-a4-fallback">
			<WritingFlow>
				<ObserveTyping>
					<BlockList />
				</ObserveTyping>
			</WritingFlow>
		</div>
	);
}
