<?php
/**
 * Filter names (§19.1): `sigma_*`, with the old `delta_assistant_*` names still honoured in 0.2.x.
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma;

defined( 'ABSPATH' ) || exit;

/**
 * One call per filter: the legacy name runs first through apply_filters_deprecated() (a notice in
 * debug mode tells the developer the new name), then the new `sigma_*` name gets the last word.
 */
final class Hooks {

	/** The prefix of the filter names before 0.2.5. */
	public const LEGACY = 'delta_assistant_';

	/** The prefix of the filter names. */
	public const PREFIX = 'sigma_';

	/**
	 * Apply a filter by its short name, e.g. 'client_ip' for `sigma_client_ip`.
	 *
	 * @param string $name  Short name.
	 * @param mixed  $value Value to filter.
	 * @param mixed  ...$args More arguments.
	 * @return mixed
	 */
	public static function filter( string $name, $value, ...$args ) {
		$value = apply_filters_deprecated( self::LEGACY . $name, array_merge( array( $value ), $args ), '0.2.5', self::PREFIX . $name ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Prefixed above.
		return apply_filters( self::PREFIX . $name, $value, ...$args ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Prefixed above.
	}
}
