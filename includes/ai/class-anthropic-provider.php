<?php
/**
 * Anthropic Messages API over wp_remote_post, with prompt caching (§7.1).
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Block 1 of the system prompt (rules + corpus) carries `cache_control: ephemeral`, so a warm call
 * pays the cache-read price for it. No SDK: one HTTP call does not justify a vendor folder (§5.2).
 *
 * Current Claude models reject non-default `temperature` (400), so none is sent; the rules keep the
 * answers to the docs instead. Thinking is switched off where the model allows it, because a docs
 * answer does not need it and it costs output tokens.
 */
final class Anthropic_Provider implements Streaming_Provider {

	public const ENDPOINT    = 'https://api.anthropic.com/v1/messages';
	public const COUNT       = 'https://api.anthropic.com/v1/messages/count_tokens';
	public const API_VERSION = '2026-10-21';

	/**
	 * Create the provider.
	 *
	 * @param string $api_key Key; never logged or returned.
	 */
	public function __construct( private readonly string $api_key ) {}

	/**
	 * Provider id.
	 */
	public function id(): string {
		return 'anthropic';
	}

	/**
	 * The request body. Public so the tests can check its shape without a network.
	 *
	 * @param Prompt                                     $prompt   System prompt.
	 * @param list<array{role: string, content: string}> $messages Turns.
	 * @param array<string, mixed>                       $options  model, max_tokens.
	 * @return array<string, mixed>
	 */
	public function body( Prompt $prompt, array $messages, array $options ): array {
		$model = (string) ( $options['model'] ?? 'claude-haiku-5-5' );
		$body  = array(
			'model'      => $model,
			'max_tokens' => min( Provider::MAX_OUTPUT_TOKENS, max( 1, (int) ( $options['max_tokens'] ?? 900 ) ) ), // §10b: never above 1,200.
			'system'     => array(
				array(
					'type'          => 'text',
					'text'          => $prompt->cached,
					'cache_control' => array( 'type' => 'ephemeral' ),
				),
				array(
					'type' => 'text',
					'text' => $prompt->dynamic,
				),
			),
			'messages'   => $messages,
		);

		// §10b: the lowest thinking each model accepts, and the 1,200-token cap for every model.
		$thinking = self::thinking_for( $model );
		if ( null === $thinking ) {
			// Thinking can't be turned off on this model (Claude Opus 5.5 400s on disabled): lowest effort instead.
			$body['output_config'] = array( 'effort' => 'low' );
		} else {
			$body['thinking'] = $thinking;
		}

		/**
		 * Filters the Anthropic request body (model-specific options).
		 *
		 * @param array $body Body.
		 */
		return (array) \TwentyOnePress\Sigma\Hooks::filter( 'anthropic_request', $body );
	}

	/**
	 * How to turn thinking off for a model, or null when it can't be (Claude Opus 5.5: thinking is
	 * always on; `disabled` and `budget_tokens` are a 400 at every effort, so effort `low` is the floor).
	 * Haiku 5.5 accepts `disabled` (at its default effort, medium); Sonnet 5.5 rejects `disabled` and
	 * turns thinking off with `between_tools`.
	 *
	 * @param string $model Model id.
	 * @return array<string, string>|null
	 */
	public static function thinking_for( string $model ): ?array {
		if ( str_starts_with( $model, 'claude-haiku-5' ) ) {
			return array( 'type' => 'disabled' );
		}
		if ( str_starts_with( $model, 'claude-sonnet-5-5' ) ) {
			return array( 'type' => 'between_tools' );
		}
		return null;
	}

	/**
	 * Exact input tokens of a prompt, from the free count_tokens endpoint (§7.4).
	 *
	 * @param Prompt $prompt System prompt.
	 * @param string $model  Model id (token counts depend on the tokenizer).
	 * @return int|\WP_Error
	 */
	public function count_tokens( Prompt $prompt, string $model ) {
		if ( '' === $this->api_key ) {
			return new \WP_Error( 'no_key', 'No Anthropic API key is set.' );
		}
		$body     = $this->body(
			$prompt,
			array(
				array(
					'role'    => 'user',
					'content' => '?',
				),
			),
			array( 'model' => $model )
		);
		$response = wp_remote_post(
			self::COUNT,
			array(
				'timeout' => 30,
				'headers' => array(
					'content-type'      => 'application/json',
					'x-api-key'         => $this->api_key,
					'anthropic-version' => self::API_VERSION,
				),
				'body'    => (string) wp_json_encode( array_intersect_key( $body, array_flip( array( 'model', 'system', 'messages', 'thinking' ) ) ) ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! isset( $data['input_tokens'] ) ) {
			return new \WP_Error( 'count_failed', 'Token count failed (HTTP ' . (int) wp_remote_retrieve_response_code( $response ) . ').' );
		}
		return (int) $data['input_tokens'];
	}

	/**
	 * Call the API.
	 *
	 * @param Prompt                                     $prompt   System prompt.
	 * @param list<array{role: string, content: string}> $messages Turns.
	 * @param array<string, mixed>                       $options  model, max_tokens.
	 * @return Answer|\WP_Error
	 */
	public function answer( Prompt $prompt, array $messages, array $options ) {
		if ( '' === $this->api_key ) {
			return new \WP_Error(
				'ai_unavailable',
				__( 'No Anthropic API key is set.', 'sigma' ),
				array(
					'status' => 503,
					'reason' => 'no_key',
				)
			);
		}
		$body     = $this->body( $prompt, $messages, $options );
		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 45,
				'headers' => array(
					'content-type'      => 'application/json',
					'x-api-key'         => $this->api_key,
					'anthropic-version' => self::API_VERSION,
				),
				'body'    => (string) wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return self::fail( 'http_error', $response->get_error_code() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $data ) ) {
			return self::fail( 'status_' . $code, is_array( $data ) ? (string) ( $data['error']['type'] ?? '' ) : 'bad_json' );
		}
		if ( 'refusal' === ( $data['stop_reason'] ?? '' ) ) {
			return new \WP_Error( 'ai_refused', 'refusal', array( 'status' => 200 ) );
		}

		$text = '';
		foreach ( (array) ( $data['content'] ?? array() ) as $block ) {
			if ( 'text' === ( $block['type'] ?? '' ) ) {
				$text .= (string) $block['text'];
			}
		}
		$usage = (array) ( $data['usage'] ?? array() );
		return new Answer(
			trim( $text ),
			(string) ( $data['model'] ?? $body['model'] ),
			array(
				'input'       => (int) ( $usage['input_tokens'] ?? 0 ),
				'cache_write' => (int) ( $usage['cache_creation_input_tokens'] ?? 0 ),
				'cache_read'  => (int) ( $usage['cache_read_input_tokens'] ?? 0 ),
				'output'      => (int) ( $usage['output_tokens'] ?? 0 ),
			)
		);
	}

	/**
	 * Whether this request can stream.
	 */
	public function can_stream(): bool {
		return null !== Stream_Transport::get();
	}

	/**
	 * Stream the answer (Messages API with `stream: true`, §8.3).
	 *
	 * @param Prompt                                     $prompt   System prompt.
	 * @param list<array{role: string, content: string}> $messages Turns.
	 * @param array<string, mixed>                       $options  model, max_tokens.
	 * @param callable(string): void                     $on_text  Text deltas.
	 * @param callable(): void                           $on_tick  About once a second.
	 * @return Answer|\WP_Error
	 */
	public function stream( Prompt $prompt, array $messages, array $options, callable $on_text, callable $on_tick ) {
		if ( '' === $this->api_key ) {
			return new \WP_Error(
				'ai_unavailable',
				__( 'No Anthropic API key is set.', 'sigma' ),
				array(
					'status' => 503,
					'reason' => 'no_key',
				)
			);
		}
		$transport = Stream_Transport::get();
		if ( null === $transport ) {
			return $this->answer( $prompt, $messages, $options );
		}
		$body   = $this->body( $prompt, $messages, $options ) + array( 'stream' => true );
		$parser = new Sse_Parser();
		$text   = '';
		$model  = (string) $body['model'];
		$usage  = array(
			'input'       => 0,
			'cache_write' => 0,
			'cache_read'  => 0,
			'output'      => 0,
		);
		$failed = '';
		$stop   = '';
		$result = $transport(
			self::ENDPOINT,
			array(
				'content-type'      => 'application/json',
				'accept'            => 'text/event-stream',
				'x-api-key'         => $this->api_key,
				'anthropic-version' => self::API_VERSION,
			),
			(string) wp_json_encode( $body ),
			static function ( string $chunk ) use ( $parser, $on_text, &$text, &$model, &$usage, &$failed, &$stop ): void {
				foreach ( $parser->feed( $chunk ) as $event ) {
					$data = json_decode( $event['data'], true );
					if ( ! is_array( $data ) ) {
						continue;
					}
					switch ( $data['type'] ?? $event['event'] ) {
						case 'message_start':
							$model = (string) ( $data['message']['model'] ?? $model );
							$u     = (array) ( $data['message']['usage'] ?? array() );
							$usage = array(
								'input'       => (int) ( $u['input_tokens'] ?? 0 ),
								'cache_write' => (int) ( $u['cache_creation_input_tokens'] ?? 0 ),
								'cache_read'  => (int) ( $u['cache_read_input_tokens'] ?? 0 ),
								'output'      => (int) ( $u['output_tokens'] ?? 0 ),
							);
							break;
						case 'content_block_delta':
							if ( 'text_delta' === ( $data['delta']['type'] ?? '' ) ) {
								$piece = (string) $data['delta']['text'];
								$text .= $piece;
								$on_text( $piece );
							}
							break;
						case 'message_delta':
							$usage['output'] = (int) ( $data['usage']['output_tokens'] ?? $usage['output'] );
							$stop            = (string) ( $data['delta']['stop_reason'] ?? $stop );
							break;
						case 'error':
							$failed = (string) ( $data['error']['type'] ?? 'stream_error' );
							break;
					}
				}
			},
			$on_tick
		);
		// §10b: once message_start arrived the call is billed, so a failure carries the usage seen so far.
		$billed = $usage['input'] + $usage['cache_write'] + $usage['cache_read'] + $usage['output'] > 0 ? $usage : null;
		if ( is_wp_error( $result ) ) {
			return self::fail( $result->get_error_code(), (string) ( $result->get_error_data()['errno'] ?? '' ), $billed, $model );
		}
		if ( 200 !== (int) $result['status'] ) {
			$data = json_decode( (string) $result['error_body'], true );
			return self::fail( 'status_' . (int) $result['status'], is_array( $data ) ? (string) ( $data['error']['type'] ?? '' ) : 'bad_json' );
		}
		if ( '' !== $failed ) {
			return self::fail( 'stream_error', $failed, $billed, $model );
		}
		if ( 'refusal' === $stop ) {
			return new \WP_Error( 'ai_refused', 'refusal', array( 'status' => 200 ) );
		}
		return new Answer( trim( $text ), $model, $usage );
	}

	/**
	 * A provider failure: logged without the key or the prompt, returned as ai_unavailable.
	 *
	 * @param string                  $reason Short reason.
	 * @param string                  $detail Provider error type.
	 * @param array<string, int>|null $usage  Tokens billed before the failure (§10b), or null.
	 * @param string                  $model  Model that billed them.
	 */
	private static function fail( string $reason, string $detail, ?array $usage = null, string $model = '' ): \WP_Error {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( sprintf( 'sigma: anthropic call failed (%s %s)', $reason, $detail ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Status only, never the key or the chat.
		}
		return new \WP_Error(
			'ai_unavailable',
			__( 'The AI is not available right now.', 'sigma' ),
			array_filter(
				array(
					'status'      => 503,
					'reason'      => $reason,
					'usage'       => $usage,
					'usage_model' => '' !== $model ? $model : null,
				),
				static fn( $v ): bool => null !== $v
			)
		);
	}
}
