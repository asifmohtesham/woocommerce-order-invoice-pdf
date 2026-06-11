import { createRoot } from '@wordpress/element';
import App from './app';

const mount = document.getElementById( 'woi-pdf-home-root' );

if ( mount && window.woiPdfHome ) {
	createRoot( mount ).render( <App data={ window.woiPdfHome } /> );
}
