const path = require( 'path' );
const MiniCssExtractPlugin = require( 'mini-css-extract-plugin' );

/** @type {import('webpack').Configuration} */
module.exports = {
	entry: {
		admin: './assets/src/admin.ts',
		'admin-bar': './assets/src/admin-bar.ts',
		modal: './assets/src/modal.ts',
		settings: './assets/src/settings.ts',
		'stagify-admin': './assets/scss/stagify-admin.scss',
	},
	output: {
		path: path.resolve( __dirname, 'assets/dist' ),
		filename: '[name].js',
		clean: true,
	},
	resolve: {
		extensions: [ '.ts', '.js' ],
	},
	plugins: [
		new MiniCssExtractPlugin( {
			filename: '../css/[name].css',
		} ),
	],
	module: {
		rules: [
			{
				test: /\.ts$/,
				use: 'ts-loader',
				exclude: /node_modules/,
			},
			{
				test: /\.scss$/,
				use: [
					MiniCssExtractPlugin.loader,
					'css-loader',
					'sass-loader',
				],
			},
		],
	},
	externals: {
		'@wordpress/dom-ready': [ 'wp', 'domReady' ],
		'@wordpress/api-fetch': [ 'wp', 'apiFetch' ],
	},
};
