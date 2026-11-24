<?php
/**
 * Base class for unit tests.
 *
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Tests\Support;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Sets up Brain Monkey and stubs the pure WordPress helpers the plugin's logic calls.
 *
 * The stubs below behave like WordPress for the inputs a unit test uses. When a test
 * depends on the exact WordPress behaviour of one of them (sanitize_text_field() on
 * invalid UTF-8, say), it belongs in the integration layer. Rule of thumb: a class that
 * needs more than about three other WordPress functions is tested in integration.
 *
 * A test replaces a stub or adds one with Functions\when( 'name' )->justReturn( … ).
 */
abstract class Unit_Test_Case extends TestCase {

	/**
	 * Start Brain Monkey and register the default stubs.
	 */
	protected function set_up(): void {
		parent::set_up();
		Monkey\setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		// §19.1: filters go through Hooks::filter(); its deprecated legacy name passes the value through.
		Functions\when( 'apply_filters_deprecated' )->alias( static fn( string $hook, array $args ) => $args[0] );
		Functions\stubs(
			array(
				'absint'                  => static fn( $v ): int => abs( (int) $v ),
				'wp_json_encode'          => static fn( $v, int $flags = 0 ) => json_encode( $v, $flags ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- This is the stand-in for wp_json_encode().
				'sanitize_key'            => static fn( $key ): string => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ),
				'wp_strip_all_tags'       => array( self::class, 'strip_all_tags' ),
				'sanitize_text_field'     => static fn( $str ): string => self::sanitize_text( (string) $str, false ),
				'sanitize_textarea_field' => static fn( $str ): string => self::sanitize_text( (string) $str, true ),
				'is_wp_error'             => static fn( $thing ): bool => $thing instanceof \WP_Error,
				'apply_filters'           => static fn( string $hook, $value ) => $value,
				'do_action'               => null,
				'wp_salt'                 => static fn( string $scheme = 'auth' ): string => 'unit-test-salt-' . $scheme,
			)
		);
	}

	/**
	 * Stop Brain Monkey (also verifies expectations set with Functions\expect()).
	 */
	protected function tear_down(): void {
		Monkey\tearDown();
		parent::tear_down();
	}

	/**
	 * Wp_strip_all_tags() as WordPress has it.
	 *
	 * @param mixed $text          Text.
	 * @param bool  $remove_breaks Also remove line breaks.
	 */
	public static function strip_all_tags( $text, bool $remove_breaks = false ): string {
		$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
		$text = strip_tags( (string) $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- This is the stand-in for wp_strip_all_tags().
		if ( $remove_breaks ) {
			$text = preg_replace( '/[\r\n\t ]+/', ' ', $text );
		}
		return trim( (string) $text );
	}

	/**
	 * The core of _sanitize_text_fields() for valid UTF-8 input.
	 *
	 * @param string $str           Text.
	 * @param bool   $keep_newlines Textarea mode.
	 */
	private static function sanitize_text( string $str, bool $keep_newlines ): string {
		if ( str_contains( $str, '<' ) ) {
			$str = preg_replace_callback( '%<[^>]*?((?=<)|>|$)%', static fn() => '', $str );
			$str = self::strip_all_tags( $str );
			$str = str_replace( "<\n", "&lt;\n", $str );
		}
		if ( ! $keep_newlines ) {
			$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
		}
		$str = trim( (string) $str );
		$str = preg_replace( '/%[a-f0-9]{2}/i', '', $str );
		return trim( (string) $str );
	}
}
