const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		'home/index': path.resolve( __dirname, 'src/home/index.js' ),
		'block-editor/index': path.resolve( __dirname, 'src/block-editor/index.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'assets/js' ),
		// wp-scripts defaults output.clean to true, which would wipe every
		// sibling file in assets/js (admin.js, pdf_js/*, order-script.js, …)
		// on each build. Our entries always overwrite their own bundles, so
		// disable cleaning to preserve the hand-authored assets that live here.
		clean: false,
	},
};
