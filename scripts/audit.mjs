#!/usr/bin/env node
/**
 * Zero known vulnerabilities, dev dependencies included: npm audit on both lockfiles (root and the widget) and
 * composer audit on the test tools. Any advisory fails (exit 1). `npm run audit`; CI runs it on every push.
 */
import { spawnSync } from 'node:child_process';
import { ROOT } from '../tests/bin/root.mjs';

const runs = [
	[ 'npm', [ 'audit', '--audit-level=low' ], ROOT ],
	[ 'npm', [ 'audit', '--audit-level=low' ], `${ ROOT }/apps/widget` ],
	[ 'composer', [ 'audit', '--locked', '--no-interaction' ], `${ ROOT }/tests` ],
];
let failed = 0;
for ( const [ cmd, args, cwd ] of runs ) {
	const r = spawnSync( cmd, args, { cwd, stdio: 'inherit' } );
	if ( r.error?.code === 'ENOENT' ) {
		console.warn( `skipped: ${ cmd } is not installed` );
		continue;
	}
	if ( r.status !== 0 ) failed++;
}
process.exit( failed ? 1 : 0 );
