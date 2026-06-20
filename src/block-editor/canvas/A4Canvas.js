import { BlockCanvas, BlockList, WritingFlow, ObserveTyping } from '@wordpress/block-editor';

// Injected into the BlockCanvas iframe head, after the shared document CSS.
// Sizes the body as an A4 page and neutralises WP block chrome so the canvas
// reads as the printed document.
export const A4_SHIM_CSS =
	'html,body{margin:0;padding:0;background:transparent}' +
	'body{width:210mm;min-height:297mm;margin:0;padding:15mm;box-sizing:border-box;background:#fff}' +
	'.block-editor-block-list__layout.is-root-container{padding:0}' +
	'.block-editor-block-list__block{margin-top:0;margin-bottom:0}' +
	'.block-editor-block-list__block::before,.block-editor-block-list__block::after{display:none !important}' +
	'.is-selected.block-editor-block-list__block::before{display:block !important}' +
	'.woi-pagebreak{border-top:1px dashed #999;margin:0;height:0;page-break-after:auto}' +
	'.woi-token-empty{outline:1px dashed #c8c8c8;outline-offset:2px;color:#9aa;min-height:1em}';

export function hasBlockCanvas() {
	return 'function' === typeof BlockCanvas;
}

// The A4 page itself. Prefers the isolated BlockCanvas iframe; falls back to an
// in-DOM scoped render when BlockCanvas is unavailable in the installed WP.
export default function A4Canvas( { previewCss } ) {
	if ( hasBlockCanvas() ) {
		const styles = [ { css: previewCss || '' }, { css: A4_SHIM_CSS } ];
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
