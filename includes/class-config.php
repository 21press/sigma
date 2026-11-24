<?php
/**
 * Constants from wp-config.php (§19.1): `SIGMA_*`, with the old `DELTA_ASSISTANT_*` names as a fallback.
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma;

defined( 'ABSPATH' ) || exit;

/**
 * Reads optional constants such as SIGMA_ANTHROPIC_KEY. When only the old name is defined, it is
 * used and an admin notice asks to rename it.
 */
final class Config {

	/**
	 * Old names found while reading, for the notice.
	 *
	 * @var array<string, true>
	 */
	private static array $legacy_used = array();

	/**
	 * Hooks.
	 */
	public static function hooks(): void {
		add_action( 'admin_notices', array( self::class, 'notices' ) );
	}

	/**
	 * Whether a constant is set (new or old name).
	 *
	 * @param string $name Name without prefix, e.g. 'ANTHROPIC_KEY'.
	 */
	public static function has( string $name ): bool {
		return defined( 'SIGMA_' . $name ) || defined( 'DELTA_ASSISTANT_' . $name );
	}

	/**
	 * A constant's value (new name first), or null when neither is defined.
	 *
	 * @param string $name Name without prefix.
	 * @return mixed
	 */
	public static function get( string $name ) {
		if ( defined( 'SIGMA_' . $name ) ) {
			return constant( 'SIGMA_' . $name );
		}
		if ( defined( 'DELTA_ASSISTANT_' . $name ) ) {
			self::$legacy_used[ 'DELTA_ASSISTANT_' . $name ] = true;
			return constant( 'DELTA_ASSISTANT_' . $name );
		}
		return null;
	}

	/**
	 * A constant as a string ('' when unset).
	 *
	 * @param string $name Name without prefix.
	 */
	public static function string( string $name ): string {
		$value = self::get( $name );
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * The full constant name in use (for messages), e.g. 'SIGMA_ANTHROPIC_KEY'.
	 *
	 * @param string $name Name without prefix.
	 */
	public static function name( string $name ): string {
		return ! defined( 'SIGMA_' . $name ) && defined( 'DELTA_ASSISTANT_' . $name ) ? 'DELTA_ASSISTANT_' . $name : 'SIGMA_' . $name;
	}

	/**
	 * Every old-name constant defined in wp-config.php.
	 *
	 * @return list<string>
	 */
	public static function legacy_defined(): array {
		$out = array();
		foreach ( array( 'ANTHROPIC_KEY', 'OPENAI_KEY', 'SMTP_PASSWORD', 'SMTP_HOST', 'TURNSTILE_SECRET', 'DISABLED' ) as $name ) {
			if ( defined( 'DELTA_ASSISTANT_' . $name ) && ! defined( 'SIGMA_' . $name ) ) {
				$out[] = 'DELTA_ASSISTANT_' . $name;
			}
		}
		return $out;
	}

	/**
	 * Admin notice: rename the old constants.
	 */
	public static function notices(): void {
		if ( \TwentyOnePress\Sigma\Admin\Notices::is_sigma_screen() ) {
			return; // Shown inside the app there (0.2.5).
		}
		$old = self::legacy_defined();
		if ( ! $old || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$renames = implode(
			', ',
			array_map( static fn( string $c ): string => $c . ' → ' . str_replace( 'DELTA_ASSISTANT_', 'SIGMA_', $c ), $old )
		);
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: list of constants, "OLD → NEW". */
					__( 'Sigma: please rename these constants in wp-config.php: %s. The old names still work for now.', 'sigma' ),
					$renames
				)
			)
		);
	}
}
