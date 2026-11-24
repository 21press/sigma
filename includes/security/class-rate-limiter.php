<?php
/**
 * Rate limits per conversation, per IP and site-wide (§10, §10b).
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Security;

use TwentyOnePress\Sigma\Conversations\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Fixed windows in the counters table, one atomic statement per hit (§10b), so parallel requests can
 * never both pass a limit. A hit counts before the work it guards; the caller refunds it only when
 * nothing reached the AI provider. IPs are never stored: only a hash with a salt that changes every
 * day, as part of a counter name that expires within 24 hours.
 */
final class Rate_Limiter {

	/** A /48 bucket allows this many times its /64 limit (§10b). */
	public const NET48_FACTOR = 5;

	/**
	 * The counter store: fn( string $bucket, int $window ): array{n: int, expires: int}. Tests swap it.
	 *
	 * @var callable|null
	 */
	public static $store = null;

	/**
	 * The refund: fn( string $bucket ): void. Tests swap it.
	 *
	 * @var callable|null
	 */
	public static $refunder = null;

	/**
	 * The clock: fn(): int. Tests swap it.
	 *
	 * @var callable|null
	 */
	public static $clock = null;

	/**
	 * Default limits: [ max hits, window in seconds ]. Filter `sigma_rate_limits`.
	 *
	 * @return array<string, array{0: int, 1: int}>
	 */
	public static function limits(): array {
		$limits = array(
			'conversation_ip'        => array( 1, 10 ),
			'conversation_ip_day'    => array( 30, DAY_IN_SECONDS ),
			'ask_hour'               => array( 20, HOUR_IN_SECONDS ), // Per conversation.
			'ask_day'                => array( 60, DAY_IN_SECONDS ), // Per conversation.
			'ask_ip_hour'            => array( 30, HOUR_IN_SECONDS ),
			'ask_ip_day'             => array( 100, DAY_IN_SECONDS ),
			'ask_global_hour'        => array( 600, HOUR_IN_SECONDS ), // A circuit breaker, not the main bound.
			'handoff_ip'             => array( 3, HOUR_IN_SECONDS ),
			'handoff_ip_unreachable' => array( 1, HOUR_IN_SECONDS ), // While Cloudflare Turnstile is unreachable.
			'handoff_global_hour'    => array( 30, HOUR_IN_SECONDS ),
			'preview_admin_hour'     => array( 20, HOUR_IN_SECONDS ), // Settings → "Try with real AI", per admin (§7.7).
		);
		/**
		 * Filters the rate limits.
		 *
		 * @param array $limits [ max, window seconds ] by name.
		 */
		return (array) \TwentyOnePress\Sigma\Hooks::filter( 'rate_limits', $limits );
	}

	/**
	 * Count a hit. Returns 0 when allowed, else the seconds until the window resets. A refused hit is
	 * taken back at once, so a blocked visitor does not push the counter further.
	 *
	 * @param string $name Limit name (a key of limits()).
	 * @param string $key  Who: an IP key from ip_keys(), a conversation key, or 'global'.
	 */
	public static function hit( string $name, string $key ): int {
		$limits = self::limits();
		if ( ! isset( $limits[ $name ] ) ) {
			return 0;
		}
		[ $max, $window ] = $limits[ $name ];
		if ( str_starts_with( $key, '48:' ) ) {
			$max *= self::NET48_FACTOR;
		}
		$bucket = self::bucket( $name, $key );
		$state  = call_user_func( self::$store ?? array( Repository::class, 'counter_add' ), $bucket, (int) $window );
		if ( (int) $state['n'] > (int) $max ) {
			self::refund( $name, $key );
			return max( 1, (int) $state['expires'] - (int) ( null === self::$clock ? time() : call_user_func( self::$clock ) ) );
		}
		return 0;
	}

	/**
	 * Count several hits in order; stop at the first refused one and take back the ones before it.
	 *
	 * @param list<array{0: string, 1: string}> $hits [ limit name, key ] pairs.
	 * @return array{wait: int, name: string} wait 0 when every hit passed.
	 */
	public static function hit_all( array $hits ): array {
		$taken = array();
		foreach ( $hits as [ $name, $key ] ) {
			$wait = self::hit( $name, $key );
			if ( $wait > 0 ) {
				self::refund_all( $taken );
				return array(
					'wait' => $wait,
					'name' => $name,
				);
			}
			$taken[] = array( $name, $key );
		}
		return array(
			'wait' => 0,
			'name' => '',
		);
	}

	/**
	 * Take one hit back (nothing reached the provider).
	 *
	 * @param string $name Limit name.
	 * @param string $key  Key.
	 */
	public static function refund( string $name, string $key ): void {
		call_user_func( self::$refunder ?? array( Repository::class, 'counter_refund' ), self::bucket( $name, $key ) );
	}

	/**
	 * Take several hits back.
	 *
	 * @param list<array{0: string, 1: string}> $hits [ limit name, key ] pairs.
	 */
	public static function refund_all( array $hits ): void {
		foreach ( $hits as [ $name, $key ] ) {
			self::refund( $name, $key );
		}
	}

	/**
	 * Hits of one limit for every bucket of an IP: [ name, key ] pairs (the /64 and its /48 for IPv6).
	 *
	 * @param string $name Limit name.
	 * @param string $ip   Client IP.
	 * @return list<array{0: string, 1: string}>
	 */
	public static function ip_hits( string $name, string $ip ): array {
		return array_map( static fn( string $key ): array => array( $name, $key ), self::ip_keys( $ip ) );
	}

	/**
	 * Counter keys of an IP (§10b): IPv4 as is (IPv4-mapped IPv6 unwrapped first); IPv6 by its /64 and
	 * by its /48 ("48:" prefix, 5× the limit), so a visitor cannot rotate addresses past the limits.
	 *
	 * @param string $ip  IP address.
	 * @param string $day Date (Y-m-d, GMT); tests pass it.
	 * @return list<string>
	 */
	public static function ip_keys( string $ip, ?string $day = null ): array {
		$ip  = self::normalize_ip( $ip );
		$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Not an IP: hashed as given.
		if ( false === $bin || 16 !== strlen( $bin ) ) {
			return array( self::hash( $ip, $day ) );
		}
		return array(
			self::hash( (string) inet_ntop( substr( $bin, 0, 8 ) . str_repeat( "\0", 8 ) ) . '/64', $day ),
			'48:' . self::hash( (string) inet_ntop( substr( $bin, 0, 6 ) . str_repeat( "\0", 10 ) ) . '/48', $day ),
		);
	}

	/**
	 * The visitor's IP bucket hashed with today's salt (the /64 for IPv6). The salt is derived from the
	 * site's secret keys and the date, so it is never stored and yesterday's hashes cannot be matched.
	 *
	 * @param string $ip  IP address.
	 * @param string $day Date (Y-m-d, GMT); tests pass it.
	 */
	public static function ip_hash( string $ip, ?string $day = null ): string {
		return self::ip_keys( $ip, $day )[0];
	}

	/**
	 * IPv4-mapped IPv6 (::ffff:a.b.c.d) as plain IPv4 (§10b: otherwise every mapped visitor shares one
	 * /64 bucket).
	 *
	 * @param string $ip IP address.
	 */
	public static function normalize_ip( string $ip ): string {
		$ip  = trim( $ip );
		$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Not an IP: left as given.
		if ( false !== $bin && 16 === strlen( $bin ) && str_repeat( "\0", 10 ) . "\xff\xff" === substr( $bin, 0, 12 ) ) {
			return (string) inet_ntop( substr( $bin, 12 ) );
		}
		return $ip;
	}

	/**
	 * The client IP (§10, §10b). With ip_source `auto` (the default): CF-Connecting-IP when the
	 * request comes from a Cloudflare edge address, else REMOTE_ADDR. `cloudflare` does the same;
	 * `remote_addr` never reads the header. The `sigma_client_ip` filter has the last word.
	 */
	public static function client_ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		$header = isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) : '';
		$source = (string) \TwentyOnePress\Sigma\Settings::get( 'ip_source' );
		if ( 'remote_addr' === $source ) {
			Cloudflare::note_edge( $remote ); // For the Settings warning: every visitor would share a few addresses.
		}
		$ip = Cloudflare::client_ip( $remote, $header, $source, 'remote_addr' === $source ? array() : Cloudflare::ranges() );
		/**
		 * Filters the client IP used for rate limits.
		 *
		 * @param string $ip The client IP.
		 */
		return self::normalize_ip( (string) \TwentyOnePress\Sigma\Hooks::filter( 'client_ip', $ip ) );
	}

	/**
	 * Counter name of a limit and key (at most 100 characters).
	 *
	 * @param string $name Limit name.
	 * @param string $key  Key.
	 */
	private static function bucket( string $name, string $key ): string {
		return substr( 'rl:' . $name . ':' . ( strlen( $key ) > 70 ? substr( hash( 'sha256', $key ), 0, 64 ) : $key ), 0, 100 );
	}

	/**
	 * An IP bucket hashed with today's salt.
	 *
	 * @param string      $value IP or network.
	 * @param string|null $day   Date.
	 */
	private static function hash( string $value, ?string $day ): string {
		$salt = hash_hmac( 'sha256', $day ?? gmdate( 'Y-m-d' ), wp_salt( 'nonce' ) );
		return hash_hmac( 'sha256', $value, $salt );
	}
}
