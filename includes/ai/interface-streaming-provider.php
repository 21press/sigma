<?php
/**
 * A provider that can stream its answer (§8.3).
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Same call as Provider::answer(), with the text handed over as it arrives. The Answer it returns at
 * the end holds the whole text and the usage, exactly as answer() would.
 */
interface Streaming_Provider extends Provider {

	/**
	 * Whether this request can stream (cURL present, or a test transport).
	 */
	public function can_stream(): bool;

	/**
	 * Stream the answer.
	 *
	 * @param Prompt                                     $prompt   System prompt.
	 * @param list<array{role: string, content: string}> $messages Turns.
	 * @param array<string, mixed>                       $options  model, max_tokens.
	 * @param callable(string): void                     $on_text  Gets each text delta.
	 * @param callable(): void                           $on_tick  Called about once a second while waiting (pings).
	 * @return Answer|\WP_Error
	 */
	public function stream( Prompt $prompt, array $messages, array $options, callable $on_text, callable $on_tick );
}
