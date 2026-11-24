#!/usr/bin/env node
/**
 * Run the integration layer in the wp-env sandbox (tests/env/wp-env.json), as Delta Plugin's
 * tests/bin/int.mjs does.
 *
 *   npm run test:int                                     Every integration test.
 *   npm run test:int -- --group snippets                 Any PHPUnit option.
 *   npm run test:int -- tests/php/integration/x-test.php [more files]
 *
 * Starts the sandbox when it is not running (the first start downloads images: a few minutes).
 * WP_ENV_CORE picks another WordPress (e.g. https://wordpress.org/wordpress-6.9.zip).
 */
import { spawn, spawnSync } from 'node:child_process';
import { mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { ROOT } from './root.mjs';
import { MOUNT, cliContainer, execArgs } from './sandbox.mjs';

const CONFIG = '--config=tests/env/wp-env.json';
const wpEnv = ( args, opts = {} ) =>
	spawnSync( join( ROOT, 'node_modules/.bin/wp-env' ), [ ...args, CONFIG ], { cwd: ROOT, encoding: 'utf8', ...opts } );

let CONTAINER = process.env.DELTA_ASSISTANT_TESTS_CONTAINER || cliContainer();
if ( ! CONTAINER ) {
	const status = wpEnv( [ 'status' ] );
	if ( status.error ) {
		console.log( 'wp-env is not installed: run `npm install` in the plugin folder.' );
		process.exit( 2 );
	}
	if ( ! /status:\s*running/.test( status.stdout ?? '' ) ) {
		console.log( 'Starting the test sandbox (wp-env)…' );
		const start = wpEnv( [ 'start' ], { stdio: 'inherit' } );
		if ( start.status !== 0 ) {
			console.log( 'The sandbox did not start. Is Docker running?' );
			process.exit( start.status ?? 1 );
		}
	}
	CONTAINER = cliContainer();
}

// One run at a time: WordPress's test library reinstalls the database on every run.
const lock = join( ROOT, 'tests/.results/int.lock' );
mkdirSync( join( ROOT, 'tests/.results' ), { recursive: true } );
for ( let waited = 0; ; waited++ ) {
	try {
		mkdirSync( lock );
		break;
	} catch {
		if ( waited === 0 ) {
			console.log( 'Another integration run is using the sandbox; waiting…' );
		}
		if ( waited > 3600 ) {
			console.log( `Gave up after 30 minutes. If no run is going on, remove ${ lock }.` );
			process.exit( 2 );
		}
		Atomics.wait( new Int32Array( new SharedArrayBuffer( 4 ) ), 0, 0, 500 );
	}
}
const release = () => rmSync( lock, { recursive: true, force: true } );
process.on( 'exit', release );
process.on( 'SIGINT', () => process.exit( 130 ) );
process.on( 'SIGTERM', () => process.exit( 143 ) );

const args = process.argv.slice( 2 );
const files = args.filter( ( a ) => /-test\.php$/.test( a ) );
const options = args.filter( ( a ) => ! files.includes( a ) );

let config = 'tests/phpunit.integration.xml';
if ( files.length ) {
	// A throwaway config holding only the chosen files; same settings as phpunit.integration.xml.
	const list = files.map( ( f ) => `\t\t\t<file>../${ f.replace( /^(\.\/)?tests\//, '' ) }</file>` ).join( '\n' );
	// The known mismatches stay out here too, unless a group is asked for (as in the main config).
	const groups = options.includes( '--group' ) || options.some( ( o ) => o.startsWith( '--group=' ) )
		? ''
		: '\n\t<groups>\n\t\t<exclude>\n\t\t\t<group>known-mismatch</group>\n\t\t</exclude>\n\t</groups>';
	writeFileSync(
		join( ROOT, 'tests/.results/related.xml' ),
		`<?xml version="1.0"?>
<phpunit bootstrap="../php/bootstrap-integration.php" cacheResult="false" colors="true" executionOrder="random"
	beStrictAboutOutputDuringTests="true" failOnRisky="true" failOnWarning="true" convertDeprecationsToExceptions="true">
	<testsuites>
		<testsuite name="related">
${ list }
		</testsuite>
	</testsuites>${ groups }
</phpunit>
`
	);
	config = 'tests/.results/related.xml';
}

mkdirSync( join( ROOT, 'tests/.results/junit' ), { recursive: true } );
const logs = [];
if ( ! options.some( ( o ) => o.startsWith( '--log-junit' ) ) ) {
	logs.push( '--log-junit', `${ MOUNT }/tests/.results/junit/integration.xml` );
}
if ( ! options.some( ( o ) => o.startsWith( '--cache-result-file' ) || o === '--do-not-cache-result' ) ) {
	logs.push( `--cache-result-file=${ MOUNT }/tests/.results/junit/integration.cache` );
}
const phpunit = [ `${ MOUNT }/tests/vendor/bin/phpunit`, '-c', `${ MOUNT }/${ config }`, ...logs, ...options ];
const child = CONTAINER
	? spawn( 'docker', execArgs( CONTAINER, phpunit ), { cwd: ROOT, stdio: [ 'inherit', 'pipe', 'pipe' ] } )
	: spawn( join( ROOT, 'node_modules/.bin/wp-env' ), [ 'run', 'cli', ...phpunit, CONFIG ], { cwd: ROOT, stdio: [ 'inherit', 'pipe', 'pipe' ] } );
// Shown as it runs, and kept: a failure in a random order can only be replayed with its seed.
let output = '';
for ( const [ from, to ] of [ [ child.stdout, process.stdout ], [ child.stderr, process.stderr ] ] ) {
	from.on( 'data', ( chunk ) => {
		output += chunk;
		to.write( chunk );
	} );
}
const code = await new Promise( ( resolve ) => child.on( 'close', ( c ) => resolve( c ?? 1 ) ) );
if ( 0 !== code ) {
	const seed = output.match( /Random Seed:\s+(\d+)/ )?.[ 1 ];
	const dir = join( ROOT, 'tests/.results/failed' );
	mkdirSync( dir, { recursive: true } );
	const file = join( dir, `integration-${ new Date().toISOString().replace( /[:.]/g, '-' ) }.log` );
	writeFileSync( file, output.replace( /\x1b\[[0-9;]*m/g, '' ) );
	console.log( `\nThis run's output is kept in ${ file.replace( ROOT + '/', '' ) }.` );
	if ( seed ) {
		console.log( `Replay the same order: npm run test:int -- ${ args.join( ' ' ) } --random-order-seed=${ seed }`.replace( /\s+/g, ' ' ) );
	}
}
process.exit( code );
