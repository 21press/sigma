<?php
/**
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Tests\Integration;

use TwentyOnePress\Sigma\Tests\Support\Integration_Test_Case;

/**
 * REST requests and WP-Cron do not load wp-admin/includes. The test library does, so a call to,
 * say, wp_tempnam() passes every other test and dies on a real site (the 0.1.2 avatar bug). This
 * test reads the plugin's code instead: no function defined only in wp-admin/includes is called,
 * unless the file loads it first.
 *
 * @coversNothing
 */
final class Request_Context_Test extends Integration_Test_Case {

	/**
	 * Functions defined in wp-admin/includes/*.php and not in wp-includes.
	 *
	 * @return array<string, string> Lowercase name => file.
	 */
	private static function admin_only_functions(): array {
		$out = array();
		foreach ( (array) glob( ABSPATH . 'wp-admin/includes/*.php' ) as $file ) {
			$tokens = token_get_all( (string) file_get_contents( (string) $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading core source.
			$depth  = 0;
			$class  = false;
			foreach ( $tokens as $i => $token ) {
				if ( is_array( $token ) && in_array( $token[0], array( T_CLASS, T_TRAIT, T_INTERFACE ), true ) ) {
					$class = true;
				}
				if ( '{' === $token || ( is_array( $token ) && in_array( $token[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
					++$depth;
				} elseif ( '}' === $token ) {
					--$depth;
					if ( 0 === $depth ) {
						$class = false;
					}
				}
				if ( ! $class && is_array( $token ) && T_FUNCTION === $token[0] ) {
					$next = $tokens[ $i + 2 ] ?? null;
					if ( is_array( $next ) && T_STRING === $next[0] ) {
						$out[ strtolower( $next[1] ) ] = basename( (string) $file );
					}
				}
			}
		}
		// Some wp-admin functions are also defined in wp-includes (or loaded on every request).
		return array_filter( $out, static fn( string $file, string $name ): bool => ! self::defined_in_wp_includes( $name ), ARRAY_FILTER_USE_BOTH );
	}

	/**
	 * Whether a function is defined in a wp-includes file.
	 *
	 * @param string $name Function.
	 */
	private static function defined_in_wp_includes( string $name ): bool {
		if ( ! function_exists( $name ) ) {
			return false;
		}
		$file = wp_normalize_path( (string) ( new \ReflectionFunction( $name ) )->getFileName() );
		return str_contains( $file, '/' . WPINC . '/' );
	}

	/**
	 * Calls that only run on wp-admin hooks, where wp-admin/includes is loaded. Keep this short:
	 * every entry is a place where REST and cron must never reach.
	 */
	private const ADMIN_HOOK_ONLY = array(
		'includes/admin/class-admin-page.php add_menu_page'               => 'admin_menu',
		'includes/admin/class-admin-page.php add_submenu_page'            => 'admin_menu',
		'includes/privacy/class-privacy.php wp_add_privacy_policy_content' => 'admin_init',
	);

	public function test_no_plugin_code_calls_a_wp_admin_only_function_without_loading_it(): void {
		$admin = self::admin_only_functions();
		$this->assertArrayHasKey( 'wp_tempnam', $admin, 'the scan finds wp-admin functions' );
		$root  = dirname( __DIR__, 4 );
		$found = array();
		$files = array_merge( array( $root . '/sigma.php' ), self::php_files( $root . '/includes' ) );
		foreach ( $files as $file ) {
			$source = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading plugin source.
			$tokens = token_get_all( $source );
			foreach ( $tokens as $i => $token ) {
				if ( ! is_array( $token ) || T_STRING !== $token[0] || ! isset( $admin[ strtolower( $token[1] ) ] ) ) {
					continue;
				}
				$j = $i + 1;
				while ( isset( $tokens[ $j ] ) && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
					++$j;
				}
				$k = $i - 1;
				while ( isset( $tokens[ $k ] ) && is_array( $tokens[ $k ] ) && T_WHITESPACE === $tokens[ $k ][0] ) {
					--$k;
				}
				$before = $tokens[ $k ] ?? null;
				$method = is_array( $before ) && in_array( $before[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NULLSAFE_OBJECT_OPERATOR ), true );
				if ( '(' !== ( $tokens[ $j ] ?? null ) || $method ) {
					continue;
				}
				if ( str_contains( $source, "wp-admin/includes/{$admin[ strtolower( $token[1] ) ]}'" ) ) {
					continue; // The file loads it first.
				}
				$relative = substr( $file, strlen( $root ) + 1 );
				if ( isset( self::ADMIN_HOOK_ONLY[ $relative . ' ' . $token[1] ] ) ) {
					continue;
				}
				$found[] = $relative . ':' . $token[2] . ' ' . $token[1] . '() from wp-admin/includes/' . $admin[ strtolower( $token[1] ) ];
			}
		}
		$this->assertSame( array(), $found );
	}

	/**
	 * PHP files under a folder.
	 *
	 * @param string $dir Folder.
	 * @return list<string>
	 */
	private static function php_files( string $dir ): array {
		$out = array();
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$out[] = $file->getPathname();
			}
		}
		sort( $out );
		return $out;
	}
}
