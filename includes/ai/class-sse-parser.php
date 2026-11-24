<?php
/**
 * Server-sent events parser for provider streams (§8.3).
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Feed it raw chunks as they arrive (they split anywhere: mid-line, mid-UTF-8, mid-CRLF); it returns
 * the complete events so far. Pure: the unit layer tests it.
 */
final class Sse_Parser {

	/**
	 * Bytes not yet ending an event.
	 *
	 * @var string
	 */
	private string $buffer = '';

	/**
	 * A "\r" held from the end of the last chunk.
	 *
	 * @var string
	 */
	private string $cr = '';

	/**
	 * Add a chunk; get the events it completed.
	 *
	 * @param string $chunk Raw bytes.
	 * @return list<array{event: string, data: string}>
	 */
	public function feed( string $chunk ): array {
		// A trailing "\r" may be half of a "\r\n" split across chunks: keep it until the next chunk.
		$chunk         = $this->cr . $chunk;
		$this->cr      = str_ends_with( $chunk, "\r" ) ? "\r" : '';
		$chunk         = '' !== $this->cr ? substr( $chunk, 0, -1 ) : $chunk;
		$this->buffer .= str_replace( array( "\r\n", "\r" ), "\n", $chunk );
		$events        = array();
		while ( false !== ( $end = strpos( $this->buffer, "\n\n" ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			$block        = substr( $this->buffer, 0, $end );
			$this->buffer = substr( $this->buffer, $end + 2 );
			$event        = self::parse_block( $block );
			if ( null !== $event ) {
				$events[] = $event;
			}
		}
		return $events;
	}

	/**
	 * One event block: `event:` and `data:` lines (several data lines join with "\n"); comments
	 * (`:`) are skipped. Null for a block with no data.
	 *
	 * @param string $block Lines of one event.
	 * @return array{event: string, data: string}|null
	 */
	private static function parse_block( string $block ): ?array {
		$event = 'message';
		$data  = array();
		foreach ( explode( "\n", $block ) as $line ) {
			if ( '' === $line || ':' === $line[0] ) {
				continue;
			}
			$colon = strpos( $line, ':' );
			$field = false === $colon ? $line : substr( $line, 0, $colon );
			$value = false === $colon ? '' : ltrim( substr( $line, $colon + 1 ), ' ' );
			if ( 'event' === $field ) {
				$event = $value;
			} elseif ( 'data' === $field ) {
				$data[] = $value;
			}
		}
		return $data ? array(
			'event' => $event,
			'data'  => implode( "\n", $data ),
		) : null;
	}
}
