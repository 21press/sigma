#!/usr/bin/env node
/**
 * Install the test dependencies when they are missing or tests/composer.lock changed since the
 * last install, so a fresh clone runs `npm test` with no setup step. The same as the Delta theme's.
 *
 * The lock's hash is kept beside the install (tests/vendor/.composer-lock.sha256).
 */
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { ROOT } from './root.mjs';

const tests = join( ROOT, 'tests' );
const installed = join( tests, 'vendor/composer/installed.json' );
const lock = join( tests, 'composer.lock' );
const stamp = join( tests, 'vendor/.composer-lock.sha256' );

const hash = existsSync( lock ) ? createHash( 'sha256' ).update( readFileSync( lock ) ).digest( 'hex' ) : '';
if ( existsSync( installed ) && existsSync( stamp ) && readFileSync( stamp, 'utf8' ).trim() === hash ) {
	process.exit( 0 );
}

console.log( 'Installing test dependencies (tests/composer.json)…' );
const r = spawnSync( 'composer', [ 'install', '--no-interaction', '--no-progress', '--quiet' ], { cwd: tests, stdio: 'inherit' } );
if ( r.error ) {
	console.log( 'Composer is needed for the PHP tests: https://getcomposer.org/download/' );
	process.exit( 2 );
}
if ( 0 === r.status ) {
	writeFileSync( stamp, `${ hash }\n` );
}
process.exit( r.status ?? 1 );
