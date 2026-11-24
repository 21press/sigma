<?php
/**
 * HTTP POST that hands the response over as it arrives (§8.3).
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\AI;

defined( 'ABSPATH' ) || exit;

/**
 * PHP cURL with CURLOPT_WRITEFUNCTION: wp_remote_post() buffers the whole body, so it cannot stream.
 * Timeouts (§8.3): 10 s to connect, abort after 20 s without a byte, 45 s in all (§10b). TLS is verified
 * against WordPress's own CA bundle, as wp_remote_post() does.
 *
 * Tests and hosts without cURL: the `sigma_stream_transport` filter supplies a callable with
 * the same signature as post().
 */
final class Stream_Transport {

	public const CONNECT_TIMEOUT = 10;
	public const IDLE_TIMEOUT    = 20;
	public const TOTAL_TIMEOUT   = 45;

	/**
	 * The transport in use: a filtered callable, else cURL, else null (no streaming here).
	 *
	 * @return callable|null fn( string $url, array $headers, string $body, callable $on_chunk, callable $on_tick ): array{status: int, error_body: string}|\WP_Error
	 */
	public static function get(): ?callable {
		/**
		 * Filters the streaming transport (tests answer with canned chunks).
		 *
		 * @param callable|null $transport Null for cURL.
		 */
		$custom = \TwentyOnePress\Sigma\Hooks::filter( 'stream_transport', null );
		if ( is_callable( $custom ) ) {
			return $custom;
		}
		return function_exists( 'curl_init' ) ? array( self::class, 'post' ) : null;
	}

	/**
	 * POST and stream the response body to $on_chunk.
	 *
	 * @param string                 $url      URL.
	 * @param array<string, string>  $headers  Headers.
	 * @param string                 $body     Body.
	 * @param callable(string): void $on_chunk Gets body bytes as they arrive (2xx only).
	 * @param callable(): void       $on_tick  Called about once a second.
	 * @return array{status: int, error_body: string}|\WP_Error
	 */
	public static function post( string $url, array $headers, string $body, callable $on_chunk, callable $on_tick ) {
		$status     = 0;
		$error_body = '';
		$lines      = array();
		foreach ( $headers as $name => $value ) {
			$lines[] = $name . ': ' . $value;
		}
		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_setopt_array, WordPress.WP.AlternativeFunctions.curl_curl_exec, WordPress.WP.AlternativeFunctions.curl_curl_getinfo, WordPress.WP.AlternativeFunctions.curl_curl_errno, WordPress.WP.AlternativeFunctions.curl_curl_error, WordPress.WP.AlternativeFunctions.curl_curl_close -- The HTTP API cannot stream (§8.3).
		$ch = curl_init( $url );
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_POST             => true,
				CURLOPT_POSTFIELDS       => $body,
				CURLOPT_HTTPHEADER       => $lines,
				CURLOPT_CONNECTTIMEOUT   => self::CONNECT_TIMEOUT,
				CURLOPT_TIMEOUT          => self::TOTAL_TIMEOUT,
				CURLOPT_LOW_SPEED_LIMIT  => 1,
				CURLOPT_LOW_SPEED_TIME   => self::IDLE_TIMEOUT,
				CURLOPT_SSL_VERIFYPEER   => true,
				CURLOPT_SSL_VERIFYHOST   => 2,
				CURLOPT_CAINFO           => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
				CURLOPT_NOPROGRESS       => false,
				CURLOPT_XFERINFOFUNCTION => static function () use ( $on_tick ): int {
					$on_tick();
					return 0;
				},
				CURLOPT_WRITEFUNCTION    => static function ( $handle, string $data ) use ( &$status, &$error_body, $on_chunk ): int {
					if ( 0 === $status ) {
						$status = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
					}
					if ( $status >= 200 && $status < 300 ) {
						$on_chunk( $data );
					} elseif ( strlen( $error_body ) < 4000 ) {
						$error_body .= $data;
					}
					return strlen( $data );
				},
			)
		);
		$ok    = curl_exec( $ch );
		$errno = curl_errno( $ch );
		$error = curl_error( $ch );
		if ( 0 === $status ) {
			$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		}
		curl_close( $ch );
		// phpcs:enable
		if ( false === $ok || 0 !== $errno ) {
			$reason = in_array( $errno, array( 28 ), true ) ? 'timeout' : 'http_error';
			return new \WP_Error( 'stream_' . $reason, $error, array( 'errno' => $errno ) );
		}
		return array(
			'status'     => $status,
			'error_body' => $error_body,
		);
	}
}
