<?php
/**
 * The system prompt in two blocks (§7.1).
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Block 1 (cached): rules + the whole corpus; byte-identical across requests until the docs or the
 * settings change. Block 2 (not cached): site name, current page, today's date.
 */
final class Prompt {

	/**
	 * Create a prompt.
	 *
	 * @param string $cached  Rules and corpus.
	 * @param string $dynamic Per-request context.
	 */
	public function __construct(
		public readonly string $cached,
		public readonly string $dynamic
	) {}
}
