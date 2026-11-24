<?php
/**
 * Marker hold-back for streamed answers (§8.3).
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Holds streamed text back while it may be the start of an end marker (§7.6), so no marker ever
 * reaches the browser, not even when it arrives split across chunks: everything from the first `[[`
 * that has no closing `]]` yet is kept until it closes (and the complete marker is removed) or until
 * 200 characters have followed it (then it was not a marker). A lone `[` at the end is kept too, in
 * case the next chunk makes it `[[`. Every complete `[[…]]` marker is removed before release.
 * Pure: the unit layer tests it.
 */
final class Holdback {

	/** Characters an open `[[` may hold the text back (§7.6). */
	public const HOLD = 200;

	/**
	 * All text received.
	 *
	 * @var string
	 */
	private string $text = '';

	/**
	 * Characters of the clean text already released.
	 *
	 * @var int
	 */
	private int $sent = 0;

	/**
	 * Add text; get what may be shown now (possibly '').
	 *
	 * @param string $chunk Text from the provider.
	 */
	public function push( string $chunk ): string {
		$this->text .= $chunk;
		$clean       = $this->clean();
		$safe        = max( $this->sent, self::safe_length( $clean, $this->sent ) );
		$out         = mb_substr( $clean, $this->sent, $safe - $this->sent );
		$this->sent  = $safe;
		return $out;
	}

	/**
	 * Everything received so far (markers included), for the final processing.
	 */
	public function text(): string {
		return $this->text;
	}

	/**
	 * How much of the clean text may be released: up to the first open `[[` that is less than 200
	 * characters from the end, or all but a trailing `[`. Pure.
	 *
	 * @param string $clean Text without complete markers.
	 * @param int    $from  Characters already released.
	 */
	public static function safe_length( string $clean, int $from = 0 ): int {
		$length = mb_strlen( $clean );
		$offset = $from;
		while ( false !== ( $open = mb_strpos( $clean, '[[', $offset ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( false === mb_strpos( $clean, ']]', $open + 2 ) && $length - $open < self::HOLD ) {
				return $open;
			}
			$offset = $open + 1;
		}
		return str_ends_with( $clean, '[' ) ? $length - 1 : $length;
	}

	/**
	 * The received text without complete markers.
	 */
	private function clean(): string {
		return (string) preg_replace( Answerer::MARKER_PATTERN, '', $this->text );
	}
}
