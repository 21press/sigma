<?php
/**
 * Per-conversation visitor secret (§8, §10).
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Security;

defined( 'ABSPATH' ) || exit;

/**
 * 32 random bytes, given to the widget once, stored only as a sha256 hash. No WP nonce: pages are
 * cached by Cloudflare and a nonce in cached HTML goes stale.
 */
final class Visitor_Token {

	/** Header the widget sends the secret in. */
	public const HEADER = 'X-DA-Token';

	/**
	 * A new secret (64 hex characters).
	 */
	public static function generate(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * The token of a conversation uuid (lazy conversations, §10b): HMAC-SHA256 of the uuid with the
	 * site's auth salt. POST /conversations stores nothing; the first ask or handoff checks this and
	 * then creates the row.
	 *
	 * @param string $uuid Conversation uuid.
	 */
	public static function for_uuid( string $uuid ): string {
		return hash_hmac( 'sha256', strtolower( $uuid ), wp_salt( 'auth' ) );
	}

	/**
	 * Whether a token is the one for a uuid, in constant time.
	 *
	 * @param string $token Token from the request.
	 * @param string $uuid  Conversation uuid.
	 */
	public static function matches_uuid( string $token, string $uuid ): bool {
		return '' !== $token && 1 === preg_match( '/^[0-9a-f]{64}$/', $token ) && hash_equals( self::for_uuid( $uuid ), $token );
	}

	/**
	 * Hash of a secret for storage.
	 *
	 * @param string $secret Secret.
	 */
	public static function hash( string $secret ): string {
		return hash( 'sha256', $secret );
	}

	/**
	 * Whether a secret matches a stored hash, in constant time.
	 *
	 * @param string $secret Secret from the request.
	 * @param string $stored Stored hash.
	 */
	public static function verify( string $secret, string $stored ): bool {
		if ( '' === $secret || '' === $stored || ! preg_match( '/^[0-9a-f]{64}$/', $secret ) ) {
			return false;
		}
		return hash_equals( $stored, self::hash( $secret ) );
	}
}
