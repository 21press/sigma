<?php
/**
 * OpenAI Chat Completions over wp_remote_post (§7.1).
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\AI;

defined( 'ABSPATH' ) || exit;

/**
 * OpenAI caches a repeated prompt prefix by itself; the corpus goes first in a stable order so the
 * prefix repeats. No sampling parameters are sent (newer models reject them).
 *
 * Checked on 2026-11-24 against developers.openai.com/api/reference (chat completions create and
 * streaming events) and the model pages: instructions go in `developer` messages (o1 and newer);
 * `max_completion_tokens` includes reasoning tokens and every listed model reasons at `medium` by
 * default, so each request sets the lowest effort the model accepts, or the cap would be spent on
 * reasoning and the answer cut off.
 */
final class OpenAI_Provider implements Streaming_Provider {

	public const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

	/**
	 * The lowest `reasoning_effort` each offered model accepts (the 6.2 models reject `none`). Other
	 * models (added with the `sigma_models` filter) get none sent; `sigma_openai_request` can add it.
	 */
	public const REASONING = array(
		'gpt-6-luna'    => 'none',
		'gpt-6.1-terra' => 'none',
		'gpt-6.2-sol'   => 'low',
		'gpt-6.2-astra' => 'low',
	);

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
		return 'openai';
	}

	/**
	 * The request body.
	 *
	 * @param Prompt                                     $prompt   System prompt.
	 * @param list<array{role: string, content: string}> $messages Turns.
	 * @param array<string, mixed>                       $options  model, max_tokens.
	 * @return array<string, mixed>
	 */
	public function body( Prompt $prompt, array $messages, array $options ): array {
		$model = (string) ( $options['model'] ?? 'gpt-6-luna' );
		$body  = array(
			'model'                 => $model,
			'max_completion_tokens' => min( Provider::MAX_OUTPUT_TOKENS, max( 1, (int) ( $options['max_tokens'] ?? 900 ) ) ), // §10b: never above 1,200, reasoning included.
			'messages'              => array_merge(
				array(
					array(
						'role'    => 'developer',
						'content' => $prompt->cached,
					),
					array(
						'role'    => 'developer',
						'content' => $prompt->dynamic,
					),
				),
				$messages
			),
		);
		if ( isset( self::REASONING[ $model ] ) ) {
			$body['reasoning_effort'] = self::REASONING[ $model ];
		}
		/**
		 * Filters the OpenAI request body.
		 *
		 * @param array $body Body.
		 */
		return (array) \TwentyOnePress\Sigma\Hooks::filter( 'openai_request', $body );
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
				__( 'No OpenAI API key is set.', 'sigma' ),
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
					'content-type'  => 'application/json',
					'authorization' => 'Bearer ' . $this->api_key,
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
			return self::fail( 'status_' . $code, is_array( $data ) ? (string) ( $data['error']['code'] ?? $data['error']['type'] ?? '' ) : 'bad_json' );
		}
		$choice = (array) ( $data['choices'][0] ?? array() );
		$text   = trim( (string) ( $choice['message']['content'] ?? '' ) );
		$failed = self::finished( (string) ( $choice['finish_reason'] ?? '' ), ! empty( $choice['message']['refusal'] ), $text );
		if ( null !== $failed ) {
			return $failed;
		}
		$usage  = (array) ( $data['usage'] ?? array() );
		$cached = (int) ( $usage['prompt_tokens_details']['cached_tokens'] ?? 0 );
		return new Answer(
			$text,
			(string) ( $data['model'] ?? $body['model'] ),
			array(
				'input'       => max( 0, (int) ( $usage['prompt_tokens'] ?? 0 ) - $cached ),
				'cache_write' => 0,
				'cache_read'  => $cached,
				'output'      => (int) ( $usage['completion_tokens'] ?? 0 ),
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
	 * Stream the answer (Chat Completions with `stream: true`, §8.3).
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
				__( 'No OpenAI API key is set.', 'sigma' ),
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
		$body    = $this->body( $prompt, $messages, $options ) + array(
			'stream'         => true,
			'stream_options' => array( 'include_usage' => true ),
		);
		$parser  = new Sse_Parser();
		$text    = '';
		$model   = (string) $body['model'];
		$usage   = array(
			'input'       => 0,
			'cache_write' => 0,
			'cache_read'  => 0,
			'output'      => 0,
		);
		$refused = false;
		$finish  = '';
		$error   = '';
		$result  = $transport(
			self::ENDPOINT,
			array(
				'content-type'  => 'application/json',
				'accept'        => 'text/event-stream',
				'authorization' => 'Bearer ' . $this->api_key,
			),
			(string) wp_json_encode( $body ),
			static function ( string $chunk ) use ( $parser, $on_text, &$text, &$model, &$usage, &$refused, &$finish, &$error ): void {
				foreach ( $parser->feed( $chunk ) as $event ) {
					if ( '[DONE]' === trim( $event['data'] ) ) {
						continue;
					}
					$data = json_decode( $event['data'], true );
					if ( ! is_array( $data ) ) {
						continue;
					}
					if ( isset( $data['error'] ) ) {
						$error = is_array( $data['error'] ) ? (string) ( $data['error']['code'] ?? $data['error']['type'] ?? 'error' ) : 'error';
						continue;
					}
					$model  = (string) ( $data['model'] ?? $model );
					$finish = (string) ( $data['choices'][0]['finish_reason'] ?? $finish );
					$delta  = (array) ( $data['choices'][0]['delta'] ?? array() );
					if ( ! empty( $delta['refusal'] ) ) {
						$refused = true;
					}
					if ( isset( $delta['content'] ) && '' !== (string) $delta['content'] ) {
						$text .= (string) $delta['content'];
						$on_text( (string) $delta['content'] );
					}
					if ( ! empty( $data['usage'] ) ) {
						$u      = (array) $data['usage'];
						$cached = (int) ( $u['prompt_tokens_details']['cached_tokens'] ?? 0 );
						$usage  = array(
							'input'       => max( 0, (int) ( $u['prompt_tokens'] ?? 0 ) - $cached ),
							'cache_write' => 0,
							'cache_read'  => $cached,
							'output'      => (int) ( $u['completion_tokens'] ?? 0 ),
						);
					}
				}
			},
			$on_tick
		);
		if ( is_wp_error( $result ) ) {
			return self::fail( $result->get_error_code(), (string) ( $result->get_error_data()['errno'] ?? '' ) );
		}
		if ( 200 !== (int) $result['status'] ) {
			$data = json_decode( (string) $result['error_body'], true );
			return self::fail( 'status_' . (int) $result['status'], is_array( $data ) ? (string) ( $data['error']['code'] ?? $data['error']['type'] ?? '' ) : 'bad_json' );
		}
		if ( '' !== $error ) {
			return self::fail( 'stream_error', $error );
		}
		$failed = self::finished( $finish, $refused, trim( $text ) );
		if ( null !== $failed ) {
			return $failed;
		}
		return new Answer( trim( $text ), $model, $usage );
	}

	/**
	 * How the answer ended: a refusal or the content filter is a refusal; hitting the token cap with
	 * nothing written (all of it spent on reasoning) is a failure. A cut-off answer with text is kept.
	 *
	 * @param string $finish_reason stop, length, content_filter, tool_calls or ''.
	 * @param bool   $refused       Whether the model sent a refusal.
	 * @param string $text          The answer.
	 * @return \WP_Error|null Null when the answer can be used.
	 */
	private static function finished( string $finish_reason, bool $refused, string $text ): ?\WP_Error {
		if ( $refused || 'content_filter' === $finish_reason ) {
			return new \WP_Error( 'ai_refused', 'refusal', array( 'status' => 200 ) );
		}
		if ( 'length' === $finish_reason && '' === $text ) {
			return self::fail( 'length', 'no_text' );
		}
		return null;
	}

	/**
	 * A provider failure.
	 *
	 * @param string $reason Short reason.
	 * @param string $detail Provider error code.
	 */
	private static function fail( string $reason, string $detail ): \WP_Error {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( sprintf( 'sigma: openai call failed (%s %s)', $reason, $detail ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Status only, never the key or the chat.
		}
		return new \WP_Error(
			'ai_unavailable',
			__( 'The AI is not available right now.', 'sigma' ),
			array(
				'status' => 503,
				'reason' => $reason,
			)
		);
	}
}
