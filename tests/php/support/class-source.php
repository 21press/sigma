<?php
/**
 * Loads plugin source files by their path in the repository.
 *
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Tests\Support;

/**
 * The plugin has no autoloader: sigma.php requires every file. A unit test loads the file it
 * tests, and the files that file calls, by their path from the plugin root.
 */
final class Source {

	/**
	 * Plugin root (the folder holding sigma.php).
	 */
	public static function root(): string {
		return dirname( __DIR__, 3 );
	}

	/**
	 * Require plugin files once, in the order given.
	 *
	 * @param string ...$paths Paths from the plugin root, e.g. `includes/class-settings.php`.
	 * @throws \RuntimeException When a path does not exist.
	 */
	public static function load( string ...$paths ): void {
		foreach ( $paths as $path ) {
			$file = self::root() . '/' . ltrim( $path, '/' );
			if ( ! is_readable( $file ) ) {
				throw new \RuntimeException( "Source::load(): {$path} does not exist. Paths are relative to the plugin root." );
			}
			require_once $file;
		}
	}
}
