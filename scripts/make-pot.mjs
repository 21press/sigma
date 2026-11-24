#!/usr/bin/env node
/**
 * languages/sigma.pot (§19.3 item 3): every string in the PHP, the admin app and the chat box.
 *
 * WP-CLI's `wp i18n make-pot` reads PHP and JavaScript, not TypeScript, and the minified admin.js has no comments.
 * So: build an unminified admin.js at the same relative path (build/admin/admin.js, which is what the JSON
 * translation files are keyed on), run make-pot over it with the PHP, then copy each translators comment from the
 * TypeScript sources onto its entry. The widget's strings are in includes/class-widget-strings.php (generated).
 *
 *   npm run i18n:pot   (needs WP-CLI as `wp`; npm run build runs it when WP-CLI is there)
 */
import { cpSync, mkdtempSync, readFileSync, readdirSync, rmSync, statSync, writeFileSync, mkdirSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { ROOT } from '../tests/bin/root.mjs';

const optional = process.argv.includes( '--if-available' );
if ( spawnSync( 'wp', [ '--version' ], { stdio: 'ignore' } ).status !== 0 ) {
	console[ optional ? 'warn' : 'error' ]( 'make-pot: WP-CLI (wp) is not installed; languages/sigma.pot was not regenerated.' );
	process.exit( optional ? 0 : 1 );
}

const tmp = mkdtempSync( join( tmpdir(), 'sigma-pot-' ) );
try {
	const vite = spawnSync( 'npx', [ 'vite', 'build', '--config', 'apps/admin/vite.config.ts', '--minify', 'false', '--outDir', join( tmp, 'build/admin' ), '--emptyOutDir', '--logLevel', 'warn' ], { cwd: ROOT, stdio: 'inherit' } );
	if ( vite.status !== 0 ) process.exit( 1 );
	cpSync( join( ROOT, 'includes' ), join( tmp, 'includes' ), { recursive: true } );
	cpSync( join( ROOT, 'sigma.php' ), join( tmp, 'sigma.php' ) );
	const out = join( tmp, 'sigma.pot' );
	const pot = spawnSync( 'wp', [ 'i18n', 'make-pot', '.', out, '--slug=sigma', '--domain=sigma', '--skip-audit', '--exclude=build/admin/admin.css', '--headers={"Report-Msgid-Bugs-To":"https://github.com/21press/sigma/issues"}' ], { cwd: tmp, encoding: 'utf8' } );
	if ( pot.status !== 0 ) {
		process.stderr.write( pot.stderr );
		process.exit( 1 );
	}

	// translators comments from the TypeScript: /* translators: … */ [sprintf(] __( '…' or _n( '…'.
	const notes = new Map();
	const files = ( d ) => readdirSync( d ).flatMap( ( f ) => ( statSync( join( d, f ) ).isDirectory() ? files( join( d, f ) ) : /\.tsx?$/.test( f ) ? [ join( d, f ) ] : [] ) );
	const RE = /\/\*\s*translators:\s*([^*]*?)\s*\*\/\s*(?:[\w.]+\s*\(\s*)?(?:createInterpolateElement\(\s*)?(?:sprintf\(\s*)?(?:__|_n|_x)\(\s*'((?:[^'\\\n]|\\.)*)'/g;
	for ( const f of files( join( ROOT, 'apps/admin/src' ) ) ) {
		for ( const m of readFileSync( f, 'utf8' ).matchAll( RE ) ) {
			notes.set( JSON.parse( '"' + m[ 2 ].replace( /\\'/g, "'" ).replace( /"/g, '\\"' ) + '"' ), m[ 1 ].replace( /\s+/g, ' ' ) );
		}
	}
	const esc = ( s ) => s.replace( /\\/g, '\\\\' ).replace( /"/g, '\\"' );
	let text = readFileSync( out, 'utf8' );
	let added = 0;
	for ( const [ msgid, note ] of notes ) {
		const block = new RegExp( `((?:^#[:,|] .*\\n)+)(msgid "${ esc( msgid ).replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) }"\\n)`, 'm' );
		text = text.replace( block, ( all, refs, id ) => {
			if ( /^#\. translators:/m.test( refs ) ) return all;
			added++;
			return `#. translators: ${ note }\n${ refs }${ id }`;
		} );
	}
	mkdirSync( join( ROOT, 'languages' ), { recursive: true } );
	// No creation date: the file changes only when a string does.
	writeFileSync( join( ROOT, 'languages/sigma.pot' ), text.replace( /^"POT-Creation-Date: .*\\n"\n/m, '' ) );
	const count = ( text.match( /^msgid "/gm ) || [] ).length - 1;
	console.log( `make-pot: ${ count } strings, ${ added } translators comments from the TypeScript → languages/sigma.pot` );
} finally {
	rmSync( tmp, { recursive: true, force: true } );
}
