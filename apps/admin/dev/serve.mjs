// Admin test harness server (never shipped): bundles dev/harness.tsx with esbuild, serves dev/index.html at /
// (and at /wp-admin/admin.php, so "Used:" deep links open the harness) and the built admin app from build/admin.
//   node apps/admin/dev/serve.mjs [port]
import { build } from 'esbuild';
import { createServer } from 'node:http';
import { readFileSync, existsSync } from 'node:fs';
import { dirname, join, resolve, normalize } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname( fileURLToPath( import.meta.url ) );
const pluginRoot = resolve( here, '../../..' );
const port = Number( process.argv[ 2 ] || process.env.PORT || 5320 );

const harness = await build( {
	entryPoints: [ join( here, 'harness.tsx' ) ],
	bundle: true,
	write: false,
	format: 'iife',
	target: 'es2020',
	jsx: 'automatic',
	define: { 'process.env.NODE_ENV': '"development"' },
	logLevel: 'warning',
} );
const harnessJs = harness.outputFiles[ 0 ].text;

createServer( ( req, res ) => {
	const p = new URL( req.url, 'http://x' ).pathname;
	if ( p === '/' || p === '/index.html' || p === '/wp-admin/admin.php' ) {
		res.writeHead( 200, { 'Content-Type': 'text/html; charset=utf-8' } );
		return res.end( readFileSync( join( here, 'index.html' ) ) );
	}
	if ( p === '/dev/harness.js' ) {
		res.writeHead( 200, { 'Content-Type': 'text/javascript; charset=utf-8' } );
		return res.end( harnessJs );
	}
	// The Settings screen's live preview loads the site's widget.js (next to build/admin) in preview mode.
	if ( p.startsWith( '/build/widget/' ) ) {
		const file = normalize( join( pluginRoot, p ) );
		if ( file.startsWith( join( pluginRoot, 'build/widget' ) ) && existsSync( file ) ) {
			res.writeHead( 200, { 'Content-Type': 'text/javascript; charset=utf-8', 'Cache-Control': 'no-store' } );
			return res.end( readFileSync( file ) );
		}
	}
	if ( p.startsWith( '/build/admin/' ) ) {
		const file = normalize( join( pluginRoot, p ) );
		if ( file.startsWith( join( pluginRoot, 'build/admin' ) ) && existsSync( file ) ) {
			res.writeHead( 200, { 'Content-Type': p.endsWith( '.css' ) ? 'text/css' : 'text/javascript; charset=utf-8', 'Cache-Control': 'no-store' } );
			return res.end( readFileSync( file ) );
		}
	}
	res.writeHead( 404 );
	res.end( 'not found' );
} ).listen( port, () => console.log( `admin harness: http://localhost:${ port }/` ) );
