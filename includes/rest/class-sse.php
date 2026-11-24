<?php
/**
 * Server-sent events output for streamed answers and the streaming self-test (§8.3).
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Takes the response over from the REST server: no output buffers, no compression, headers that stop
 * nginx (X-Accel-Buffering), Cloudflare and browsers from buffering or transforming it, a flush after
 * every event, and exit at the end.
 *
 * Tests capture the output with the `sigma_sse_sink` filter (a callable that gets each
 * written string); then no headers are sent and nothing exits.
 */
final class Sse {

	/** Seconds between `: ping` comments while nothing else is written (§8.3). */
	public const PING_EVERY = 10;

	/**
	 * Test sink, or null for real output.
	 *
	 * @var callable|null
	 */
	private $sink;

	/**
	 * Last write time.
	 *
	 * @var float
	 */
	private float $last = 0.0;

	/**
	 * Start the stream: headers, buffers off, a padding comment that pushes past small proxy buffers.
	 */
	public function open(): void {
		/**
		 * Filters where SSE output goes (tests).
		 *
		 * @param callable|null $sink Null for the response.
		 */
		$sink       = \TwentyOnePress\Sigma\Hooks::filter( 'sse_sink', null );
		$this->sink = is_callable( $sink ) ? $sink : null;
		if ( null === $this->sink ) {
			ignore_user_abort( true ); // The visitor leaving mid-stream must not stop the answer being stored.
			if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( 120 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- The provider may take up to 60 s.
			}
			while ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
			ini_set( 'zlib.output_compression', '0' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Compression buffers the stream.
			if ( function_exists( 'apache_setenv' ) ) {
				apache_setenv( 'no-gzip', '1' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_apache_setenv -- mod_deflate would buffer the stream.
			}
			if ( ! headers_sent() ) {
				status_header( 200 );
				header( 'Content-Type: text/event-stream; charset=UTF-8' );
				header( 'Cache-Control: no-cache, no-store, no-transform, private' );
				header( 'X-Accel-Buffering: no' );
				header( 'Content-Encoding: identity' );
				header( 'X-Content-Type-Options: nosniff' );
			}
		}
		// 2 KB of comment: some proxies and FastCGI layers hold the first bytes until a block fills.
		$this->write( ':' . str_repeat( ' ', 2048 ) . "\n\n" );
	}

	/**
	 * One event.
	 *
	 * @param string               $event Event name.
	 * @param array<string, mixed> $data  JSON data.
	 */
	public function event( string $event, array $data ): void {
		$this->write( 'event: ' . $event . "\ndata: " . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n\n" );
	}

	/**
	 * `: ping` when nothing was written for PING_EVERY seconds.
	 */
	public function maybe_ping(): void {
		if ( microtime( true ) - $this->last >= self::PING_EVERY ) {
			$this->write( ": ping\n\n" );
		}
	}

	/**
	 * End the response (exit, except in tests).
	 */
	public function close(): void {
		if ( null === $this->sink ) {
			exit;
		}
	}

	/**
	 * Whether output goes to a test sink.
	 */
	public function captured(): bool {
		return null !== $this->sink;
	}

	/**
	 * Write and flush.
	 *
	 * @param string $text Bytes.
	 */
	private function write( string $text ): void {
		$this->last = microtime( true );
		if ( null !== $this->sink ) {
			( $this->sink )( $text );
			return;
		}
		echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SSE framing around JSON from wp_json_encode().
		flush();
	}
}
