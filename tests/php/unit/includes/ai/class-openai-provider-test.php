<?php
/**
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Tests\Unit;

use Brain\Monkey\Functions;
use TwentyOnePress\Sigma\AI\Answer;
use TwentyOnePress\Sigma\AI\OpenAI_Provider;
use TwentyOnePress\Sigma\AI\Prompt;
use TwentyOnePress\Sigma\Tests\Support\Source;
use TwentyOnePress\Sigma\Tests\Support\Unit_Test_Case;

/**
 * OpenAI Chat Completions, checked on 2026-11-24 against OpenAI's API reference: the request body, and
 * each response and stream event type (content, refusal, finish reasons, the usage chunk, [DONE],
 * an error event, error statuses), all with mocked HTTP.
 *
 * @covers \TwentyOnePress\Sigma\AI\OpenAI_Provider
 */
final class OpenAI_Provider_Test extends Unit_Test_Case {

	/** The last request: url, headers, body (decoded). */
	private array $sent = array();

	protected function set_up(): void {
		parent::set_up();
		Source::load(
			'includes/ai/interface-provider.php',
			'includes/ai/interface-streaming-provider.php',
			'includes/ai/class-prompt.php',
			'includes/ai/class-answer.php',
			'includes/ai/class-sse-parser.php',
			'includes/ai/class-stream-transport.php',
			'includes/ai/class-openai-provider.php'
		);
	}

	private static function provider(): OpenAI_Provider {
		return new OpenAI_Provider( 'sk-test' );
	}

	private static function prompt(): Prompt {
		return new Prompt( 'RULES AND CORPUS', 'PAGE AND DATE' );
	}

	/** One chunk object as the API streams it. */
	private static function chunk( array $delta, ?string $finish = null, array $extra = array() ): string {
		return 'data: ' . json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			$extra + array(
				'id'      => 'chatcmpl-1',
				'object'  => 'chat.completion.chunk',
				'created' => 1790000000,
				'model'   => 'gpt-6-luna-2026-10-01',
				'choices' => array(
					array(
						'index'         => 0,
						'delta'         => $delta,
						'logprobs'      => null,
						'finish_reason' => $finish,
					),
				),
				'usage'   => null,
			)
		) . "\n\n";
	}

	/** The final usage chunk (`stream_options.include_usage`): empty choices. */
	private static function usage_chunk(): string {
		return 'data: ' . json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			array(
				'object'  => 'chat.completion.chunk',
				'model'   => 'gpt-6-luna-2026-10-01',
				'choices' => array(),
				'usage'   => array(
					'prompt_tokens'             => 3500,
					'completion_tokens'         => 60,
					'total_tokens'              => 3560,
					'prompt_tokens_details'     => array( 'cached_tokens' => 3072 ),
					'completion_tokens_details' => array( 'reasoning_tokens' => 20 ),
				),
			)
		) . "\n\n";
	}

	/**
	 * A stream transport answering with this body, in small pieces.
	 *
	 * @param string $raw    SSE body.
	 * @param int    $status HTTP status.
	 * @param string $error  Error body for a non-200.
	 */
	private function stream_answers( string $raw, int $status = 200, string $error = '' ): void {
		$sent = &$this->sent;
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) use ( $raw, $status, $error, &$sent ) {
				if ( 'sigma_stream_transport' !== $hook ) {
					return $value;
				}
				return static function ( $url, $headers, $body, $on_chunk, $on_tick ) use ( $raw, $status, $error, &$sent ) {
					$sent = array(
						'url'     => $url,
						'headers' => $headers,
						'body'    => json_decode( $body, true ),
					);
					$on_tick();
					if ( 200 === $status ) {
						foreach ( str_split( $raw, 7 ) as $piece ) {
							$on_chunk( $piece );
						}
					}
					return array(
						'status'     => $status,
						'error_body' => $error,
					);
				};
			}
		);
	}

	/**
	 * Stream and collect the deltas.
	 *
	 * @param array $options Options.
	 * @return array{0: Answer|\WP_Error, 1: list<string>}
	 */
	private function stream( array $options = array( 'model' => 'gpt-6-luna' ) ): array {
		$deltas = array();
		$result = self::provider()->stream(
			self::prompt(),
			array(
				array(
					'role'    => 'user',
					'content' => 'How do I switch on snippets?',
				),
			),
			$options,
			static function ( string $t ) use ( &$deltas ): void {
				$deltas[] = $t;
			},
			static function (): void {}
		);
		return array( $result, $deltas );
	}

	/**
	 * A non-streaming answer from wp_remote_post.
	 *
	 * @param int   $status HTTP status.
	 * @param array $json   Body.
	 */
	private function post_answers( int $status, array $json ): void {
		$sent = &$this->sent;
		Functions\when( 'wp_remote_post' )->alias(
			static function ( string $url, array $args ) use ( $status, $json, &$sent ) {
				$sent = array(
					'url'     => $url,
					'headers' => $args['headers'],
					'body'    => json_decode( $args['body'], true ),
				);
				return array(
					'response' => array( 'code' => $status ),
					'body'     => json_encode( $json ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
				);
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $r ) => $r['response']['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $r ) => $r['body'] );
	}

	private static function completion( string $content, string $finish = 'stop', ?string $refusal = null ): array {
		return array(
			'id'      => 'chatcmpl-1',
			'object'  => 'chat.completion',
			'model'   => 'gpt-6.2-sol-2026-11-01',
			'choices' => array(
				array(
					'index'         => 0,
					'message'       => array(
						'role'    => 'assistant',
						'content' => $content,
						'refusal' => $refusal,
					),
					'finish_reason' => $finish,
				),
			),
			'usage'   => array(
				'prompt_tokens'             => 2000,
				'completion_tokens'         => 300,
				'prompt_tokens_details'     => array( 'cached_tokens' => 1024 ),
				'completion_tokens_details' => array( 'reasoning_tokens' => 250 ),
			),
		);
	}

	// ----- The request ---------------------------------------------------------------------------

	public function test_the_body_uses_developer_messages_the_completion_cap_and_no_sampling_parameters(): void {
		$body = self::provider()->body(
			self::prompt(),
			array(
				array(
					'role'    => 'user',
					'content' => 'Hi',
				),
			),
			array(
				'model'      => 'gpt-6-luna',
				'max_tokens' => 5000,
			)
		);
		$this->assertSame( 'gpt-6-luna', $body['model'] );
		$this->assertSame( 1200, $body['max_completion_tokens'], 'capped at 1,200 (§10b)' );
		$this->assertArrayNotHasKey( 'max_tokens', $body, 'deprecated in favour of max_completion_tokens' );
		$this->assertSame( array( 'developer', 'developer', 'user' ), array_column( $body['messages'], 'role' ) );
		$this->assertSame( 'RULES AND CORPUS', $body['messages'][0]['content'], 'the cached prefix first' );
		foreach ( array( 'temperature', 'top_p', 'n', 'stop', 'user' ) as $param ) {
			$this->assertArrayNotHasKey( $param, $body, $param );
		}
	}

	public function test_each_offered_model_gets_the_lowest_reasoning_effort_it_accepts(): void {
		$effort = static fn( string $model ) => self::provider()->body( self::prompt(), array(), array( 'model' => $model ) )['reasoning_effort'] ?? null;
		$this->assertSame( 'none', $effort( 'gpt-6-luna' ) );
		$this->assertSame( 'none', $effort( 'gpt-6.1-terra' ) );
		$this->assertSame( 'low', $effort( 'gpt-6.2-sol' ), 'the 6.2 models reject none' );
		$this->assertSame( 'low', $effort( 'gpt-6.2-astra' ) );
		$this->assertNull( $effort( 'gpt-4.1-custom' ), 'a model added by filter: nothing sent' );
	}

	public function test_the_stream_request_asks_for_usage_and_sends_the_key_only_as_a_bearer_header(): void {
		$this->stream_answers( self::chunk( array( 'content' => 'Hi.' ), 'stop' ) . "data: [DONE]\n\n" );
		$this->stream();
		$this->assertSame( OpenAI_Provider::ENDPOINT, $this->sent['url'] );
		$this->assertSame( 'Bearer sk-test', $this->sent['headers']['authorization'] );
		$this->assertSame( 'text/event-stream', $this->sent['headers']['accept'] );
		$this->assertTrue( $this->sent['body']['stream'] );
		$this->assertSame( array( 'include_usage' => true ), $this->sent['body']['stream_options'] );
		$this->assertStringNotContainsString( 'sk-test', (string) json_encode( $this->sent['body'] ), 'the key is not in the body' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	// ----- Stream events -------------------------------------------------------------------------

	public function test_content_deltas_stream_in_order_and_the_usage_chunk_counts_cached_and_reasoning_tokens(): void {
		$this->stream_answers(
			self::chunk(
				array(
					'role'    => 'assistant',
					'content' => '',
				)
			)
			. ": keep-alive comment\n\n"
			. self::chunk( array( 'content' => 'Go to ' ) )
			. self::chunk( array( 'content' => 'Features.' ) )
			. self::chunk( array(), 'stop' )
			. self::usage_chunk()
			. "data: [DONE]\n\n"
		);
		[ $answer, $deltas ] = $this->stream();
		$this->assertInstanceOf( Answer::class, $answer );
		$this->assertSame( array( 'Go to ', 'Features.' ), $deltas );
		$this->assertSame( 'Go to Features.', $answer->text );
		$this->assertSame( 'gpt-6-luna-2026-10-01', $answer->model, 'the model the API reports' );
		$this->assertSame(
			array(
				'input'       => 428,
				'cache_write' => 0,
				'cache_read'  => 3072,
				'output'      => 60,
			),
			$answer->usage,
			'completion_tokens already include the reasoning tokens: billed as output'
		);
	}

	public function test_a_stream_without_the_usage_chunk_still_answers_with_zero_usage(): void {
		$this->stream_answers( self::chunk( array( 'content' => 'Short.' ), 'stop' ) . "data: [DONE]\n\n" );
		[ $answer ] = $this->stream();
		$this->assertSame( 'Short.', $answer->text );
		$this->assertSame( 0, $answer->usage['output'], 'the docs warn the final usage chunk may not arrive' );
	}

	public function test_a_refusal_delta_is_a_refusal(): void {
		$this->stream_answers( self::chunk( array( 'refusal' => 'I can’t help with that.' ) ) . self::chunk( array(), 'stop' ) . "data: [DONE]\n\n" );
		[ $result, $deltas ] = $this->stream();
		$this->assertSame( 'ai_refused', $result->get_error_code() );
		$this->assertSame( array(), $deltas, 'the refusal text is not streamed' );
	}

	public function test_the_content_filter_finish_reason_is_a_refusal(): void {
		$this->stream_answers( self::chunk( array( 'content' => 'Partial' ) ) . self::chunk( array(), 'content_filter' ) . "data: [DONE]\n\n" );
		[ $result ] = $this->stream();
		$this->assertSame( 'ai_refused', $result->get_error_code() );
	}

	public function test_the_token_cap_reached_with_no_text_is_unavailable_but_a_cut_off_answer_is_kept(): void {
		$this->stream_answers( self::chunk( array( 'content' => '' ) ) . self::chunk( array(), 'length' ) . self::usage_chunk() . "data: [DONE]\n\n" );
		[ $result ] = $this->stream();
		$this->assertSame( 'ai_unavailable', $result->get_error_code() );
		$this->assertSame( 'length', $result->get_error_data()['reason'], 'all of it spent on reasoning' );

		$this->stream_answers( self::chunk( array( 'content' => 'Go to Features and' ) ) . self::chunk( array(), 'length' ) . "data: [DONE]\n\n" );
		[ $answer ] = $this->stream();
		$this->assertSame( 'Go to Features and', $answer->text );
	}

	public function test_an_error_event_mid_stream_is_unavailable(): void {
		$this->stream_answers(
			self::chunk( array( 'content' => 'Go to ' ) )
			. 'data: ' . json_encode(
				array(
					'error' => array(
						'message' => 'The server had an error.',
						'type'    => 'server_error',
						'code'    => null,
					),
				)
			) . "\n\n" // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		);
		[ $result ] = $this->stream();
		$this->assertSame( 'ai_unavailable', $result->get_error_code() );
		$this->assertSame( 'stream_error', $result->get_error_data()['reason'] );
	}

	public function test_error_statuses_are_unavailable_with_the_status_as_reason(): void {
		$bodies = array(
			401 => array(
				'message' => 'Incorrect API key provided.',
				'type'    => 'invalid_request_error',
				'code'    => 'invalid_api_key',
				'param'   => null,
			),
			404 => array(
				'message' => 'The model does not exist.',
				'type'    => 'invalid_request_error',
				'code'    => 'model_not_found',
				'param'   => null,
			),
			429 => array(
				'message' => 'You exceeded your current quota.',
				'type'    => 'insufficient_quota',
				'code'    => 'insufficient_quota',
				'param'   => null,
			),
			503 => array(
				'message' => 'Model temporarily overloaded.',
				'type'    => 'service_unavailable_error',
				'code'    => 'server_is_overloaded',
				'param'   => null,
			),
		);
		foreach ( $bodies as $status => $error ) {
			$this->stream_answers( '', $status, (string) json_encode( array( 'error' => $error ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			[ $result ] = $this->stream();
			$this->assertSame( 'ai_unavailable', $result->get_error_code(), (string) $status );
			$this->assertSame( 'status_' . $status, $result->get_error_data()['reason'] );
			$this->assertSame( 503, $result->get_error_data()['status'] );
		}
	}

	public function test_no_key_is_unavailable_without_a_request(): void {
		$result = ( new OpenAI_Provider( '' ) )->stream( self::prompt(), array(), array(), static function (): void {}, static function (): void {} );
		$this->assertSame( 'no_key', $result->get_error_data()['reason'] );
	}

	// ----- Without streaming ---------------------------------------------------------------------

	public function test_a_completion_answers_with_its_text_model_and_usage(): void {
		$this->post_answers( 200, self::completion( '  Go to Features.  ' ) );
		$answer = self::provider()->answer( self::prompt(), array(), array( 'model' => 'gpt-6.2-sol' ) );
		$this->assertSame( 'Go to Features.', $answer->text );
		$this->assertSame( 'gpt-6.2-sol-2026-11-01', $answer->model );
		$this->assertSame(
			array(
				'input'       => 976,
				'cache_write' => 0,
				'cache_read'  => 1024,
				'output'      => 300,
			),
			$answer->usage
		);
		$this->assertSame( 'low', $this->sent['body']['reasoning_effort'] );
		$this->assertArrayNotHasKey( 'stream', $this->sent['body'] );
	}

	public function test_a_completion_refusal_content_filter_or_empty_length_is_not_an_answer(): void {
		$this->post_answers( 200, self::completion( '', 'stop', 'I can’t help with that.' ) );
		$this->assertSame( 'ai_refused', self::provider()->answer( self::prompt(), array(), array() )->get_error_code() );

		$this->post_answers( 200, self::completion( '', 'content_filter' ) );
		$this->assertSame( 'ai_refused', self::provider()->answer( self::prompt(), array(), array() )->get_error_code() );

		$this->post_answers( 200, self::completion( '', 'length' ) );
		$this->assertSame( 'length', self::provider()->answer( self::prompt(), array(), array() )->get_error_data()['reason'] );
	}

	public function test_a_completion_error_status_is_unavailable(): void {
		$this->post_answers(
			429,
			array(
				'error' => array(
					'message' => 'Rate limit reached for requests.',
					'type'    => 'requests',
					'code'    => 'rate_limit_exceeded',
				),
			)
		);
		$result = self::provider()->answer( self::prompt(), array(), array() );
		$this->assertSame( 'ai_unavailable', $result->get_error_code() );
		$this->assertSame( 'status_429', $result->get_error_data()['reason'] );
	}
}
