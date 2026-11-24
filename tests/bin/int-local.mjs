#!/usr/bin/env node
/**
 * Run the integration layer without Docker: WordPress's own test library and a WordPress core on
 * this machine, against a local MariaDB/MySQL (for example a local WordPress site's server), in a database of its own
 * that the test library wipes on every run.
 *
 *   npm run test:int:local                   Every integration test.
 *   npm run test:int:local -- --filter Kill  Any PHPUnit option.
 *
 * Needs (once):
 *   - the test library: git clone --depth 1 --filter=blob:none --sparse -b 7.1.5 https://github.com/WordPress/wordpress-develop.git
 *     then git sparse-checkout set tests/phpunit/includes tests/phpunit/data
 *   - a core: wp core download --version=7.1.5 --path=<dir>
 *   - an empty database (default da_assistant_tests) the DB user can write.
 * Where they are: DA_TESTS_HOME (default ~/.cache/delta-assistant-tests, holding wordpress-develop/
 * and wordpress/). DB credentials come from the environment (DA_TESTS_DB_USER, _PASSWORD, _HOST,
 * _NAME) or, with DA_TESTS_COVE_SITE=<site>, from that local site's wp-config.php at run time.
 * Nothing is written to the repository.
 *
 * `npm run test:int` (wp-env, Docker) stays the house way and what CI runs.
 */
import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, writeFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { join } from 'node:path';
import { ROOT } from './root.mjs';

const home = process.env.DA_TESTS_HOME || join( homedir(), '.cache/delta-assistant-tests' );
const testsDir = join( home, 'wordpress-develop/tests/phpunit' );
const core = join( home, 'wordpress' );
for ( const [ path, what ] of [ [ join( testsDir, 'includes/functions.php' ), 'the WordPress test library' ], [ join( core, 'wp-settings.php' ), 'a WordPress core' ] ] ) {
	if ( ! existsSync( path ) ) {
		console.log( `Missing ${ what } at ${ path }. See the header of tests/bin/int-local.mjs.` );
		process.exit( 2 );
	}
}

const db = {
	user: process.env.DA_TESTS_DB_USER,
	password: process.env.DA_TESTS_DB_PASSWORD,
	host: process.env.DA_TESTS_DB_HOST,
	name: process.env.DA_TESTS_DB_NAME || 'da_assistant_tests',
};
if ( ! db.user && process.env.DA_TESTS_COVE_SITE ) {
	const r = spawnSync( 'cove', [ 'wp', process.env.DA_TESTS_COVE_SITE, 'eval', 'echo json_encode( array( DB_USER, DB_PASSWORD, DB_HOST ) );' ], { encoding: 'utf8' } );
	const line = ( r.stdout || '' ).split( '\n' ).find( ( l ) => l.startsWith( '[' ) );
	if ( ! line ) {
		console.log( `Could not read the DB credentials of local site ${ process.env.DA_TESTS_COVE_SITE }.` );
		process.exit( 2 );
	}
	[ db.user, db.password, db.host ] = JSON.parse( line );
}
if ( ! db.user ) {
	console.log( 'Set DA_TESTS_DB_USER / _PASSWORD / _HOST, or DA_TESTS_COVE_SITE=<local site>.' );
	process.exit( 2 );
}

// The config reads the credentials from the environment: no password on disk.
mkdirSync( home, { recursive: true } );
writeFileSync(
	join( home, 'wp-tests-config.php' ),
	`<?php
define( 'ABSPATH', ${ JSON.stringify( core + '/' ) } );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'DB_NAME', getenv( 'DA_TESTS_DB_NAME' ) );
define( 'DB_USER', getenv( 'DA_TESTS_DB_USER' ) );
define( 'DB_PASSWORD', getenv( 'DA_TESTS_DB_PASSWORD' ) );
define( 'DB_HOST', getenv( 'DA_TESTS_DB_HOST' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
$table_prefix = 'wptests_';
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Sigma tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
`
);

const r = spawnSync( join( ROOT, 'tests/vendor/bin/phpunit' ), [ '-c', join( ROOT, 'tests/phpunit.integration.xml' ), ...process.argv.slice( 2 ) ], {
	cwd: ROOT,
	stdio: 'inherit',
	env: {
		...process.env,
		WP_TESTS_DIR: testsDir,
		DA_TESTS_CONFIG: join( home, 'wp-tests-config.php' ),
		DA_TESTS_DB_USER: db.user,
		DA_TESTS_DB_PASSWORD: db.password ?? '',
		DA_TESTS_DB_HOST: db.host || 'localhost',
		DA_TESTS_DB_NAME: db.name,
	},
} );
process.exit( r.status ?? 1 );
