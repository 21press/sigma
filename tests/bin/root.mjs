/**
 * The plugin's folder (the one holding delta-plugin.php), for the scripts in tests/bin.
 */
import { fileURLToPath } from 'node:url';

export const ROOT = fileURLToPath( new URL( '../..', import.meta.url ) ).replace( /\/$/, '' );
