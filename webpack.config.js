const path = require( 'path' );

/** @type {import('webpack').Configuration} */
module.exports = {
	entry: {
		admin: './assets/src/admin.ts',
		'admin-bar': './assets/src/admin-bar.ts',
		settings: './assets/src/settings.ts',
	},
	output: {
		path: path.resolve( __dirname, 'assets/dist' ),
		filename: '[name].js',
		clean: true,
	},
	resolve: {
		extensions: [ '.ts', '.js' ],
	},
	module: {
		rules: [
			{
				test: /\.ts$/,
				use: 'ts-loader',
				exclude: /node_modules/,
			},
		],
	},
	externals: {
		'@wordpress/dom-ready': [ 'wp', 'domReady' ],
		'@wordpress/api-fetch': [ 'wp', 'apiFetch' ],
	},
};
