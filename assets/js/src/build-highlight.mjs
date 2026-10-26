import { build } from 'esbuild';

await build( {
	entryPoints: [ 'assets/js/src/source-highlight.js' ],
	bundle: true,
	format: 'iife',
	minify: true,
	outfile: 'assets/js/vendor/carve-source-highlight.js',
	banner: { js: 'var __wpcarvePreviousPrism = window.Prism; window.Prism = { manual: true };' },
	footer: { js: 'if (__wpcarvePreviousPrism === undefined) { delete window.Prism; } else { window.Prism = __wpcarvePreviousPrism; }' },
} );
