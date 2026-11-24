#!/usr/bin/env node
/**
 * The running cli container of the plugin's wp-env sandbox, for commands run straight through
 * `docker exec` (as the Delta theme's tests/bin/sandbox.mjs does: faster than `wp-env run`, and
 * several at once do not race on wp-env's state file).
 *
 *   node tests/bin/sandbox.mjs     Prints the container id ('' when not running).
 */
import { spawnSync } from 'node:child_process';
import { ROOT } from './root.mjs';

/** Where the plugin is in the container. */
export const MOUNT = '/var/www/html/wp-content/plugins/sigma';

/**
 * The id of the sandbox's cli container: the one whose compose project is tests/env/wp-env.json's
 * and whose plugin folder is this checkout.
 *
 * @return {string} Container id, or '' when the sandbox is not running.
 */
export function cliContainer() {
	const ids = spawnSync( 'docker', [ 'ps', '-q', '--filter', 'label=com.docker.compose.service=cli' ], { encoding: 'utf8' } );
	if ( ids.status !== 0 ) {
		return '';
	}
	// The compose folder ends in -wp-env-<hash>; the theme's plain sandbox has the same name, so the
	// plugin folder mounted in it tells them apart.
	const project = /-wp-env-[0-9a-f]+$/;
	for ( const id of ids.stdout.split( '\n' ).filter( Boolean ) ) {
		const info = spawnSync( 'docker', [ 'inspect', id, '--format', '{{json .}}' ], { encoding: 'utf8' } );
		if ( info.status !== 0 ) {
			continue;
		}
		const c = JSON.parse( info.stdout );
		const dir = String( c.Config?.Labels?.[ 'com.docker.compose.project.working_dir' ] ?? '' );
		const plugin = ( c.Mounts ?? [] ).find( ( m ) => m.Destination === MOUNT );
		if ( project.test( dir ) && plugin && plugin.Source === ROOT ) {
			return id;
		}
	}
	return '';
}

/**
 * docker exec arguments for a command in the container, from the site root as wp-env runs it.
 *
 * @param {string}   id      Container id.
 * @param {string[]} command Command and its arguments.
 * @return {string[]} Arguments after `docker`.
 */
export function execArgs( id, command ) {
	return [ 'exec', '-w', '/var/www/html', id, ...command ];
}

if ( import.meta.url === `file://${ process.argv[ 1 ] }` ) {
	console.log( cliContainer() );
}
