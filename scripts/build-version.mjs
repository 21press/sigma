#!/usr/bin/env node
/**
 * Writes build/version.txt: "{plugin version} {git short sha}" (the sha only in a git checkout). The
 * plugin compares it with P21_SIGMA_VERSION and its app sources and warns on its screens when the
 * built files are stale or missing (includes/admin/class-build-check.php). Run last by npm run build.
 */
import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const header = readFileSync( join( root, 'sigma.php' ), 'utf8' ).match( /^ \* Version:\s*(\S+)/m );
if ( ! header ) {
	console.error( 'build-version: no Version in the sigma.php header.' );
	process.exit( 1 );
}
let sha = '';
try {
	sha = execFileSync( 'git', [ 'rev-parse', '--short', 'HEAD' ], { cwd: root, encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] } ).trim();
} catch {
	// Not a git checkout: the version alone.
}
const line = sha ? `${ header[ 1 ] } ${ sha }` : header[ 1 ];
mkdirSync( join( root, 'build' ), { recursive: true } );
writeFileSync( join( root, 'build/version.txt' ), line + '\n' );
console.log( `build-version: ${ line } → build/version.txt` );
