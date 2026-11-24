<?php
/**
 * Bootstrap for the unit layer: Sigma's pure classes, no WordPress.
 *
 * WordPress functions a class calls are stubbed per test with Brain Monkey (see Unit_Test_Case).
 * Source files are loaded by each test with Source::load(): the plugin has no autoloader.
 *
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

require_once __DIR__ . '/../vendor/autoload.php';

foreach ( array( 'class-wp-error.php', 'class-source.php', 'class-unit-test-case.php' ) as $delta_assistant_support ) {
	require_once __DIR__ . '/support/' . $delta_assistant_support;
}

// Every plugin file starts with `defined( 'ABSPATH' ) || exit;`.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/delta-assistant-unit-wordpress/' );
}
// Key derivation for stored API keys (Settings::encrypt()).
if ( ! defined( 'AUTH_KEY' ) ) {
	define( 'AUTH_KEY', 'unit-test-auth-key' );
	define( 'AUTH_SALT', 'unit-test-auth-salt' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
}

// Loaded for every unit test: filter names (§19.1) and wp-config constants go through these.
require_once dirname( __DIR__, 2 ) . '/includes/class-hooks.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-config.php';
