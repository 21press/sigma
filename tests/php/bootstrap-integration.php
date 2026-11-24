<?php
/**
 * Bootstrap for the integration layer: WordPress's own test library with Sigma active.
 *
 * Runs in the wp-env sandbox (`npm run test:int`) or on this machine against a local database
 * (`npm run test:int:local`, tests/bin/int-local.mjs). The library installs a fresh site on every
 * run. Outbound HTTP is refused unless a test answers it (tests/env/mu-plugins/…-http.php), so no
 * test ever reaches an AI provider.
 *
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

require_once __DIR__ . '/../vendor/autoload.php';

$delta_assistant_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( false === $delta_assistant_tests_dir || ! is_readable( $delta_assistant_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress's test library was not found. Run npm run test:int (wp-env) or npm run test:int:local.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}
if ( getenv( 'DA_TESTS_CONFIG' ) ) {
	define( 'WP_TESTS_CONFIG_FILE_PATH', getenv( 'DA_TESTS_CONFIG' ) );
}
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );

require_once $delta_assistant_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		// The no-HTTP guard: wp-env maps it as a mu-plugin; the local runner has no mu-plugins folder.
		if ( ! has_filter( 'pre_http_request' ) ) {
			require_once dirname( __DIR__ ) . '/env/mu-plugins/delta-assistant-tests-http.php';
		}
		require_once dirname( __DIR__, 2 ) . '/sigma.php';
	}
);

require_once $delta_assistant_tests_dir . '/includes/bootstrap.php';

foreach ( glob( __DIR__ . '/support/class-integration-*.php' ) as $delta_assistant_support ) {
	require_once $delta_assistant_support;
}

// What activating the plugin runs: tables, capabilities, the purge job.
\TwentyOnePress\Sigma\Plugin::activate();
rest_get_server();
