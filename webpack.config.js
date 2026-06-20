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
	},
};
