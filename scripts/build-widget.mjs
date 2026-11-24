#!/usr/bin/env node
/**
 * Build the visitor widget (apps/widget) into build/widget/ with its own package.json.
 * Skips with a notice while apps/widget is not in the repository yet.
 */
import { spawnSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { join } from 'node:path';

const root = fileURLToPath( new URL( '..', import.meta.url ) );
const widget = join( root, 'apps/widget' );
if ( ! existsSync( join( widget, 'package.json' ) ) ) {
	console.log( 'apps/widget is not here yet: the widget is not built.' );
	process.exit( 0 );
}
for ( const args of [ [ 'ci', '--no-audit', '--no-fund' ], [ 'run', 'build' ] ] ) {
	const r = spawnSync( 'npm', [ '--prefix', widget, ...args ], { stdio: 'inherit' } );
	if ( r.status !== 0 ) {
		process.exit( r.status ?? 1 );
	}
}
if ( ! existsSync( join( root, 'build/widget/widget.js' ) ) ) {
	console.error( 'The widget build did not write build/widget/widget.js.' );
	process.exit( 1 );
}
