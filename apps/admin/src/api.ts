import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const NS = '/sigma/v1';

/** A REST error as WordPress returns it. */
export interface ApiError {
	code: string;
	message: string;
	data?: { status?: number; field?: string };
}

/** How long a GET may take before the screen says so and offers "Try again". */
export const GET_TIMEOUT = 15000;

/**
 * GET from the plugin's REST API. A screen's first requests are answered from the page itself
 * (rest_preload_api_request in PHP and apiFetch's preloading middleware), so they need no round trip; any
 * other GET rejects after GET_TIMEOUT with a reason the screen shows next to "Try again".
 */
export function get< T >( path: string ): Promise< T > {
	return new Promise< T >( ( resolve, reject ) => {
		const timer = window.setTimeout(
			() => reject( { code: 'timeout', message: __( 'The server did not answer within 15 seconds.', 'sigma' ) } as ApiError ),
			GET_TIMEOUT
		);
		apiFetch< T >( { path: NS + path } ).then(
			( value ) => {
				window.clearTimeout( timer );
				resolve( value );
			},
			( e ) => {
				window.clearTimeout( timer );
				reject( e );
			}
		);
	} );
}

export function post< T >( path: string, data?: unknown ): Promise< T > {
	return apiFetch< T >( { path: NS + path, method: 'POST', data } );
}

export function put< T >( path: string, data?: unknown ): Promise< T > {
	return apiFetch< T >( { path: NS + path, method: 'PUT', data } );
}

export function del< T >( path: string ): Promise< T > {
	return apiFetch< T >( { path: NS + path, method: 'DELETE' } );
}

/** The field a 400 names (data.field), or ''. */
export function errorField( e: unknown ): string {
	const err = e as ApiError;
	return err && err.data && typeof err.data.field === 'string' ? err.data.field : '';
}

export function errorText( e: unknown ): string {
	const err = e as ApiError;
	return err && err.message ? err.message : __( 'Something went wrong.', 'sigma' );
}

/**
 * A wp-admin page URL. `SigmaAdmin.adminUrl` is `admin_url( 'admin.php' )`, so the query goes straight
 * after it; a base that ends in `/` (older builds) gets `admin.php` added.
 */
export function adminPage( page: string, extra: Record< string, string | number > = {} ): string {
	const base = window.SigmaAdmin.adminUrl;
	const php = /admin\.php$/.test( base ) ? base : `${ base.replace( /\/?$/, '/' ) }admin.php`;
	const q = new URLSearchParams( { page, ...Object.fromEntries( Object.entries( extra ).map( ( [ k, v ] ) => [ k, String( v ) ] ) ) } );
	return `${ php }?${ q.toString() }`;
}
