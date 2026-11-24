<?php
/**
 * The visitor's IP behind Cloudflare (§10, plan v1.4).
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Security;

defined( 'ABSPATH' ) || exit;

/**
 * CF-Connecting-IP is trusted only when the request itself comes from a Cloudflare edge address, so
 * a visitor who reaches the origin directly cannot pick their own IP. The ranges ship with the
 * plugin and can be refreshed from cloudflare.com (Settings, "Refresh ranges").
 */
final class Cloudflare {

	/** Refreshed ranges (autoload no). */
	public const OPTION = 'delta_assistant_cf_ranges';

	public const URL_V4 = 'https://www.cloudflare.com/ips-v4';
	public const URL_V6 = 'https://www.cloudflare.com/ips-v6';

	/** Ranges bundled with the plugin, read from the URLs above on 2026-11-20. */
	public const BUNDLED = array(
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	);

	/**
	 * The client IP for a request. Pure: the unit layer tests it.
	 *
	 * @param string   $remote_addr REMOTE_ADDR.
	 * @param string   $header      CF-Connecting-IP, or ''.
	 * @param string   $source      auto|cloudflare|remote_addr (the ip_source setting; auto and
	 *                              cloudflare both trust the header only from Cloudflare's ranges).
	 * @param string[] $ranges      Cloudflare CIDR ranges.
	 */
	public static function client_ip( string $remote_addr, string $header, string $source, array $ranges ): string {
		if ( 'remote_addr' === $source ) {
			return $remote_addr;
		}
		$header = trim( $header );
		if ( '' === $header || false === filter_var( $header, FILTER_VALIDATE_IP ) ) {
			return $remote_addr;
		}
		return self::in_ranges( $remote_addr, $ranges ) ? $header : $remote_addr;
	}

	/**
	 * Whether an IP (v4 or v6) is inside one of the CIDR ranges.
	 *
	 * @param string   $ip     IP.
	 * @param string[] $ranges CIDR ranges.
	 */
	public static function in_ranges( string $ip, array $ranges ): bool {
		$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- An invalid address is simply not in a range.
		if ( false === $bin ) {
			return false;
		}
		foreach ( $ranges as $cidr ) {
			$parts = explode( '/', (string) $cidr, 2 );
			if ( 2 !== count( $parts ) ) {
				continue;
			}
			$net = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- See above.
			$len = (int) $parts[1];
			if ( false === $net || strlen( $net ) !== strlen( $bin ) || $len < 0 || $len > strlen( $bin ) * 8 ) {
				continue;
			}
			$bytes = intdiv( $len, 8 );
			$bits  = $len % 8;
			if ( substr( $bin, 0, $bytes ) !== substr( $net, 0, $bytes ) ) {
				continue;
			}
			if ( 0 === $bits ) {
				return true;
			}
			$mask = ( 0xff << ( 8 - $bits ) ) & 0xff;
			if ( ( ord( $bin[ $bytes ] ) & $mask ) === ( ord( $net[ $bytes ] ) & $mask ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The ranges in use: the refreshed list when there is one, else the bundled list.
	 *
	 * @return string[]
	 */
	public static function ranges(): array {
		$stored = get_option( self::OPTION );
		if ( is_array( $stored ) && ! empty( $stored['ranges'] ) ) {
			return (array) $stored['ranges'];
		}
		return self::BUNDLED;
	}

	/** Option: when a request last came from a Cloudflare edge while ip_source is remote_addr. */
	public const SEEN_OPTION = 'delta_assistant_cf_seen';

	/**
	 * Note that a request came from Cloudflare while ip_source is `remote_addr` (§10b warning). At
	 * most one write a day.
	 *
	 * @param string $remote_addr REMOTE_ADDR.
	 */
	public static function note_edge( string $remote_addr ): void {
		if ( '' === $remote_addr || ! self::in_ranges( $remote_addr, self::ranges() ) ) {
			return;
		}
		if ( time() - (int) get_option( self::SEEN_OPTION, 0 ) > DAY_IN_SECONDS ) {
			update_option( self::SEEN_OPTION, time(), false );
		}
	}

	/**
	 * Whether requests seem to arrive through Cloudflare: one did in the last 30 days, or the current
	 * (admin) request does.
	 */
	public static function behind(): bool {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		return time() - (int) get_option( self::SEEN_OPTION, 0 ) < 30 * DAY_IN_SECONDS || ( '' !== $remote && self::in_ranges( $remote, self::ranges() ) );
	}

	/**
	 * Where the ranges come from, for Settings, and whether requests arrive through Cloudflare.
	 *
	 * @return array{source: string, count: int, fetched_at: string, age_days: int, stale: bool, behind: bool}
	 */
	public static function status(): array {
		$stored             = get_option( self::OPTION );
		$status             = is_array( $stored ) && ! empty( $stored['ranges'] )
			? array(
				'source'     => 'refreshed',
				'count'      => count( $stored['ranges'] ),
				'fetched_at' => (string) $stored['fetched_at'],
			)
			: array(
				'source'     => 'bundled',
				'count'      => count( self::BUNDLED ),
				'fetched_at' => '2026-11-20',
			);
		$age                = (int) floor( ( time() - (int) strtotime( substr( $status['fetched_at'], 0, 10 ) . ' UTC' ) ) / DAY_IN_SECONDS );
		$status['age_days'] = max( 0, $age );
		$status['stale']    = $age > 90; // Settings suggests a refresh past 90 days.
		$status['behind']   = self::behind();
		return $status;
	}

	/**
	 * Fetch the current ranges from cloudflare.com and keep them. Nothing is kept unless both lists
	 * download and every line is a valid range.
	 *
	 * @return array<string, mixed>|\WP_Error status() on success.
	 */
	public static function refresh() {
		$ranges = array();
		foreach ( array( self::URL_V4, self::URL_V6 ) as $url ) {
			$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return new \WP_Error( 'refresh_failed', __( 'Could not download Cloudflare\'s IP ranges. The current list stays in use.', 'sigma' ), array( 'status' => 502 ) );
			}
			$lines = self::parse( (string) wp_remote_retrieve_body( $response ) );
			if ( null === $lines ) {
				return new \WP_Error( 'refresh_invalid', __( 'Cloudflare\'s IP list did not look right. The current list stays in use.', 'sigma' ), array( 'status' => 502 ) );
			}
			$ranges = array_merge( $ranges, $lines );
		}
		update_option(
			self::OPTION,
			array(
				'ranges'     => $ranges,
				'fetched_at' => gmdate( 'Y-m-d H:i' ) . ' UTC',
			),
			false
		);
		return self::status();
	}

	/**
	 * One downloaded list: CIDR lines only, at least one. Null when any line is not a range.
	 *
	 * @param string $body Response body.
	 * @return string[]|null
	 */
	public static function parse( string $body ): ?array {
		$out = array();
		foreach ( preg_split( '/\R/', trim( $body ) ) as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			$parts = explode( '/', $line, 2 );
			if ( 2 !== count( $parts ) || false === filter_var( $parts[0], FILTER_VALIDATE_IP ) || ! ctype_digit( $parts[1] ) ) {
				return null;
			}
			$out[] = $line;
		}
		return $out ? $out : null;
	}
}
