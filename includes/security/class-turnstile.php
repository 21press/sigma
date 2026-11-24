<?php
/**
 * Optional Cloudflare Turnstile on the visitor routes (§10b).
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Security;

use TwentyOnePress\Sigma\Conversations\Repository;
use TwentyOnePress\Sigma\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Server side of Turnstile: the token from the widget is checked with Cloudflare's siteverify (5 s
 * timeout). Off by default; when on, the handoff form needs a token, and so does a new conversation
 * once the site-wide question breaker is past half.
 *
 * When Cloudflare cannot be reached (a network error, a timeout or a 5xx from siteverify), the check
 * fails open (§10b, 0.1.7): a spam check must not cut off human contact during an outage. The caller
 * then applies a stricter limit, and an admin notice shows after 3 such events in an hour. An invalid
 * or expired token, or a bad secret, always fails.
 */
final class Turnstile {

	/** Result of check() when Cloudflare could not be reached. */
	public const UNREACHABLE = 'unreachable';

	/** Unreachable events in the current hour before the admin notice shows. */
	public const NOTICE_AFTER = 3;

	/** Counter of unreachable events (one-hour window). */
	private const EVENTS = 'ts:unreachable';

	public const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

	/** Option: hash of the key pair that passed "Test keys" (the switch needs it). */
	public const TESTED_OPTION = 'delta_assistant_turnstile_tested';

	/**
	 * Check a visitor's token. True when Turnstile is off or the token passes.
	 *
	 * @param string $token  The widget's token.
	 * @param string $ip     The client IP (sent as remoteip).
	 * @param bool   $needed Whether this route needs a token now.
	 * @return true|string|\WP_Error True, self::UNREACHABLE (accept with a stricter limit), or 403
	 *                               turnstile_required / turnstile_failed.
	 */
	public static function check( string $token, string $ip, bool $needed = true ) {
		if ( ! $needed || ! Settings::turnstile_on() ) {
			return true;
		}
		$token = trim( $token );
		if ( '' === $token ) {
			// No token: the widget may not have loaded the challenge because Cloudflare is down. Only
			// then is it accepted; whether it is down is probed once and remembered for a minute.
			return self::reachable() ? new \WP_Error( 'turnstile_required', __( 'Please confirm you are human.', 'sigma' ), array( 'status' => 403 ) ) : self::outage();
		}
		$result = self::verify( Settings::turnstile_secret(), $token, $ip );
		if ( $result['unreachable'] ) {
			Repository::counter_add( 'ts:down', 60 );
			return self::outage();
		}
		if ( true !== $result['success'] ) {
			return new \WP_Error(
				'turnstile_failed',
				__( 'The human check did not pass. Please try again.', 'sigma' ),
				array(
					'status' => 403,
					'codes'  => $result['codes'],
				)
			);
		}
		return true;
	}

	/**
	 * Call siteverify. Never a pass on a network error or a malformed answer.
	 *
	 * @param string $secret Secret key.
	 * @param string $token  Response token.
	 * @param string $ip     Client IP, or ''.
	 * @return array{success: bool, codes: list<string>, unreachable: bool} unreachable: a network
	 *         error, a timeout or a 5xx (never a token or secret problem).
	 */
	public static function verify( string $secret, string $token, string $ip = '' ): array {
		if ( '' === $secret ) {
			return array(
				'success'     => false,
				'codes'       => array( 'missing-input-secret' ),
				'unreachable' => false,
			);
		}
		$body = array(
			'secret'   => $secret,
			'response' => mb_substr( $token, 0, 2048 ),
		);
		if ( '' !== $ip ) {
			$body['remoteip'] = $ip;
		}
		$response = wp_safe_remote_post(
			self::VERIFY_URL,
			array(
				'timeout'             => 5,
				'body'                => $body,
				'limit_response_size' => 16 * KB_IN_BYTES,
			)
		);
		if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) >= 500 ) {
			return array(
				'success'     => false,
				'codes'       => array( 'network-error' ),
				'unreachable' => true,
			);
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return array(
				'success'     => false,
				'codes'       => array( 'bad-response' ),
				'unreachable' => false,
			);
		}
		$codes = array_values( array_filter( array_map( 'strval', (array) ( $data['error-codes'] ?? array() ) ), static fn( string $c ): bool => 1 === preg_match( '/^[a-z0-9-]{1,60}$/', $c ) ) );
		return array(
			'success'     => true === ( $data['success'] ?? null ),
			'codes'       => $codes,
			'unreachable' => false,
		);
	}

	/**
	 * Whether siteverify answers (any answer, even a refusal of the probe token). Remembered for 60 s
	 * either way, so tokenless requests cannot make the site call Cloudflare more than once a minute.
	 */
	private static function reachable(): bool {
		if ( Repository::counter_get( 'ts:down' ) > 0 ) {
			return false;
		}
		if ( Repository::counter_get( 'ts:up' ) > 0 ) {
			return true;
		}
		$probe = self::verify( Settings::turnstile_secret(), 'sigma-reachability' );
		Repository::counter_add( $probe['unreachable'] ? 'ts:down' : 'ts:up', 60 );
		return ! $probe['unreachable'];
	}

	/**
	 * Count an unreachable event and return the outage result.
	 */
	private static function outage(): string {
		Repository::counter_add( self::EVENTS, HOUR_IN_SECONDS );
		return self::UNREACHABLE;
	}

	/**
	 * Unreachable events in the current hour.
	 */
	public static function unreachable_count(): int {
		return Repository::counter_get( self::EVENTS );
	}

	/**
	 * Admin notice: Cloudflare could not be reached 3 or more times this hour.
	 */
	public static function notices(): void {
		if ( \TwentyOnePress\Sigma\Admin\Notices::is_sigma_screen() ) {
			return; // Shown inside the app there (0.2.5).
		}
		if ( ! current_user_can( 'manage_options' ) || ! Settings::turnstile_on() ) {
			return;
		}
		$count = self::unreachable_count();
		if ( $count < self::NOTICE_AFTER ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %d: events this hour. */
					_n(
						'Sigma: Cloudflare Turnstile could not be reached %d time this hour. Messages to your team were still accepted, with a limit of 1 per hour per visitor.',
						'Sigma: Cloudflare Turnstile could not be reached %d times this hour. Messages to your team were still accepted, with a limit of 1 per hour per visitor.',
						$count,
						'sigma'
					),
					$count
				)
			),
			esc_url( admin_url( 'admin.php?page=sigma#human' ) ),
			esc_html__( 'Spam protection settings', 'sigma' )
		);
	}

	/**
	 * "Test keys" in Settings: siteverify with the saved secret and a dummy token. A good secret comes
	 * back with only invalid-input-response (the dummy token); a bad one with invalid-input-secret.
	 *
	 * @return array{ok: bool, codes: list<string>}
	 */
	public static function test_keys(): array {
		$secret = Settings::turnstile_secret();
		$site   = (string) Settings::get( 'turnstile_site_key' );
		if ( '' === $secret || '' === $site ) {
			return array(
				'ok'    => false,
				'codes' => array( '' === $site ? 'missing-site-key' : 'missing-input-secret' ),
			);
		}
		$result = self::verify( $secret, 'sigma-key-test' );
		$codes  = $result['codes'];
		// A secret is accepted when Cloudflare only rejects the dummy token (or, with a test secret, passes it).
		$ok = $result['success'] || ( $codes && ! array_diff( $codes, array( 'invalid-input-response', 'timeout-or-duplicate' ) ) );
		if ( $ok ) {
			update_option( self::TESTED_OPTION, self::pair_hash( $site, $secret ), false );
		} else {
			delete_option( self::TESTED_OPTION );
		}
		return array(
			'ok'    => $ok,
			'codes' => $codes,
		);
	}

	/**
	 * Whether a key pair passed "Test keys".
	 *
	 * @param string $site   Site key.
	 * @param string $secret Secret key.
	 */
	public static function tested( string $site, string $secret ): bool {
		$stored = (string) get_option( self::TESTED_OPTION, '' );
		return '' !== $stored && '' !== $site && '' !== $secret && hash_equals( $stored, self::pair_hash( $site, $secret ) );
	}

	/**
	 * A keyed hash of the pair (never the secret itself).
	 *
	 * @param string $site   Site key.
	 * @param string $secret Secret key.
	 */
	private static function pair_hash( string $site, string $secret ): string {
		return hash_hmac( 'sha256', $site . '|' . $secret, wp_salt( 'auth' ) );
	}
}
