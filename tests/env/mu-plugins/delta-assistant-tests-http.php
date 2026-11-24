<?php
/**
 * Plugin Name: Delta Assistant tests: no outbound HTTP
 * Description: Test sandbox only. Refuses every HTTP request a test did not answer, so no test ever reaches an AI provider.
 *
 * A test answers a request with its own `pre_http_request` filter; whatever it returns wins.
 * Never ships: tests/ is not in the release zip.
 *
 * @package TwentyOnePress\Delta_Assistant\Tests
 */

declare( strict_types=1 );

add_filter(
	'pre_http_request',
	static function ( $pre, array $args, string $url ) {
		if ( false !== $pre ) {
			return $pre;
		}
		return new WP_Error( 'delta_assistant_tests_http_blocked', sprintf( 'Outbound HTTP is off in the test sandbox: %s %s', $args['method'] ?? 'GET', $url ) );
	},
	PHP_INT_MAX,
	3
);
