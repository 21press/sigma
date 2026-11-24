<?php
/**
 * AI provider contract (§5.2).
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\AI;

defined( 'ABSPATH' ) || exit;

/**
 * One call: a system prompt (cached part + per-request part) and the recent turns in, an answer out.
 * Providers have no tools and see no private data.
 */
interface Provider {

	/** No call asks for more output than this, on any model (§10b). */
	public const MAX_OUTPUT_TOKENS = 1200;


	/**
	 * Provider id (anthropic, openai, mock).
	 */
	public function id(): string;

	/**
	 * Answer the last visitor message.
	 *
	 * @param Prompt                                                 $prompt   System prompt.
	 * @param list<array{role: 'user'|'assistant', content: string}> $messages Turns, oldest first; the last is the visitor's.
	 * @param array<string, mixed>                                   $options  model, max_tokens.
	 * @return Answer|\WP_Error
	 */
	public function answer( Prompt $prompt, array $messages, array $options );
}
