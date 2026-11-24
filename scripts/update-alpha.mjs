#!/usr/bin/env node
/**
 * Update the admin styles from Alpha (github.com/21press/alpha, private).
 *
 *   git clone git@github.com:21press/alpha.git ../alpha   (main, or add --branch <tag>)
 *   node scripts/update-alpha.mjs ../alpha
 *
 * Copies tokens.css and the licence into apps/admin/alpha/, records the version, and generates
 * apps/admin/src/alpha.css with Alpha's own scope script (wrapper .sigma-admin).
 * Never edit the generated file; the plugin's own styles live in apps/admin/src/styles.css.
 */
import { copyFileSync, writeFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '..' );
const kit = process.argv[ 2 ] ? path.resolve( process.argv[ 2 ] ) : '';
if ( ! kit ) {
	console.error( 'Usage: node scripts/update-alpha.mjs <path to a alpha checkout>' );
	process.exit( 1 );
}
const dir = path.join( root, 'apps/admin/alpha' );
copyFileSync( path.join( kit, 'tokens.css' ), path.join( dir, 'tokens.css' ) );
copyFileSync( path.join( kit, 'LICENSE' ), path.join( dir, 'LICENSE' ) );
const version = execFileSync( 'git', [ '-C', kit, 'describe', '--tags', '--always' ], { encoding: 'utf8' } ).trim();
writeFileSync( path.join( dir, 'VERSION' ), version + '\n' );
execFileSync(
	'node',
	[ path.join( kit, 'scripts/scope.mjs' ), '--in', path.join( dir, 'tokens.css' ), '--wrapper', '.sigma-admin', '--prefix', 'sigma', '--out', path.join( root, 'apps/admin/src/alpha.css' ) ],
	{ stdio: 'inherit' }
);
console.log( `Alpha ${ version }` );
