<?php
/**
 * What a provider returns.
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\AI;

defined( 'ABSPATH' ) || exit;

/**
 * The answer text, the model that wrote it, and its token usage.
 */
final class Answer {

	/**
	 * Create an answer.
	 *
	 * @param string             $text  Answer (limited Markdown).
	 * @param string             $model Model id.
	 * @param array<string, int> $usage input, cache_write, cache_read, output.
	 */
	public function __construct(
		public readonly string $text,
		public readonly string $model,
		public readonly array $usage = array()
	) {}
}
