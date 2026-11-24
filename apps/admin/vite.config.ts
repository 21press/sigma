import { defineConfig } from 'vite';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * The wp-admin screens (Settings, Conversations), built as one script. React is never bundled:
 * it comes from WordPress (wp-element, react-jsx-runtime), so there is one copy on the page.
 */
const root = path.dirname( fileURLToPath( import.meta.url ) );
const pluginRoot = path.resolve( root, '../..' );

export default defineConfig( {
	root,
	publicDir: false,
	esbuild: {
		jsx: 'automatic',
	},
	build: {
		outDir: path.resolve( pluginRoot, 'build/admin' ),
		emptyOutDir: true,
		sourcemap: false,
		cssCodeSplit: false,
		lib: {
			entry: path.resolve( root, 'src/main.tsx' ),
			name: 'sigmaAdmin',
			formats: [ 'iife' ],
			fileName: () => 'admin.js',
			cssFileName: 'admin',
		},
		rollupOptions: {
			external: [ 'react', 'react/jsx-runtime', 'react-dom/client', '@wordpress/api-fetch', '@wordpress/i18n', '@wordpress/element' ],
			output: {
				globals: {
					react: 'wp.element',
					'react/jsx-runtime': 'ReactJSXRuntime',
					'react-dom/client': 'wp.element',
					'@wordpress/api-fetch': 'wp.apiFetch',
					'@wordpress/i18n': 'wp.i18n',
					'@wordpress/element': 'wp.element',
				},
			},
		},
	},
} );
