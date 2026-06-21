import { __ } from '@wordpress/i18n';

// Injected stylesheet for the block-editor layout modes. Mirrors the GrapesJS
// editor.css [data-layout] rules. These also carry the base structural styles
// for the shell/main/preview containers (moved off inline styles so the
// [data-layout] overrides win without !important).
export const LAYOUT_CSS =
	'.woi-block-shell{display:flex;gap:0;align-items:stretch;min-height:70vh}' +
	'.woi-block-main{flex:1.3;min-width:0;padding-right:8px}' +
	'.woi-block-preview{flex:1;min-width:360px;border-left:1px solid #ddd;display:flex;flex-direction:column}' +
	'.woi-block-preview[hidden]{display:none}' +
	'body.woi-block-fullscreen{overflow:hidden}' +
	'.woi-block-shell[data-layout="full"]{position:fixed;inset:0;z-index:100000;background:#fff;margin:0;padding:8px;min-height:0;overflow:hidden}' +
	// In full mode the shell is fixed at the viewport height, so let the editor
	// and preview columns scroll internally instead of overflowing off-screen.
	'.woi-block-shell[data-layout="full"] .woi-block-main,.woi-block-shell[data-layout="full"] .woi-block-preview{overflow:auto;min-height:0}' +
	'.woi-block-shell[data-layout="stack"]{flex-direction:column}' +
	'.woi-block-shell[data-layout="stack"] .woi-block-main{padding-right:0}' +
	'.woi-block-shell[data-layout="stack"] .woi-block-preview{flex:0 0 auto;min-width:0;border-left:0;border-top:1px solid #ddd;min-height:50vh}' +
	'.woi-block-shell[data-layout="overlay"] .woi-block-preview{position:fixed;top:var(--wp-admin--admin-bar--height,32px);right:0;bottom:0;width:40%;max-width:640px;z-index:99980;border-left:1px solid #c3c4c7;box-shadow:-8px 0 24px rgba(0,0,0,.18)}' +
	'.woi-block-workspace{display:flex;gap:8px;align-items:stretch;min-height:0;min-width:0}' +
	'.woi-block-canvas{flex:1;min-width:0;display:flex;flex-direction:column}' +
	'.woi-block-sidebar{flex:0 0 280px;overflow:auto;min-height:0;background:#fff;border-left:1px solid #ddd}' +
	'.woi-block-shell[data-layout="stack"] .woi-block-sidebar{flex-basis:260px}';

export function injectLayoutStyles() {
	if ( document.getElementById( 'woi-block-layout-css' ) ) { return; }
	const el = document.createElement( 'style' );
	el.id = 'woi-block-layout-css';
	el.textContent = LAYOUT_CSS;
	document.head.appendChild( el );
}

export const LAYOUTS = [
	{ id: 'full', label: __( 'Full screen', 'woocommerce-orders-invoice-pdf' ) },
	{ id: 'stack', label: __( 'Split below', 'woocommerce-orders-invoice-pdf' ) },
	{ id: 'overlay', label: __( 'Overlay', 'woocommerce-orders-invoice-pdf' ) },
];
