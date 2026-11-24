<?php
/**
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Tests\Unit;

use Brain\Monkey\Functions;
use TwentyOnePress\Sigma\AI\Anthropic_Provider;
use TwentyOnePress\Sigma\AI\Answer;
use TwentyOnePress\Sigma\AI\Holdback;
use TwentyOnePress\Sigma\AI\OpenAI_Provider;
use TwentyOnePress\Sigma\AI\Prompt;
use TwentyOnePress\Sigma\AI\Sse_Parser;
use TwentyOnePress\Sigma\Tests\Support\Source;
use TwentyOnePress\Sigma\Tests\Support\Unit_Test_Case;

/**
 * Streaming (§8.3): the SSE parser, the marker hold-back, and both providers' stream formats.
 *
 * @covers \TwentyOnePress\Sigma\AI\Sse_Parser
 * @covers \TwentyOnePress\Sigma\AI\Holdback
 * @covers \TwentyOnePress\Sigma\AI\Anthropic_Provider::stream
 * @covers \TwentyOnePress\Sigma\AI\OpenAI_Provider::stream
 */
final class Streaming_Test extends Unit_Test_Case {

	private const MARKER = '[[NOT_IN_DOCS]]';

	protected function set_up(): void {
		parent::set_up();
		Source::load(
			'includes/class-settings.php',
			'includes/class-pricing.php',
			'includes/class-usage.php',
			'includes/conversations/class-repository.php',
			'includes/knowledge/class-retriever.php',
			'includes/knowledge/class-corpus.php',
			'includes/ai/interface-provider.php',
			'includes/ai/interface-streaming-provider.php',
			'includes/ai/class-prompt.php',
			'includes/ai/class-answer.php',
			'includes/ai/class-sse-parser.php',
			'includes/ai/class-holdback.php',
			'includes/ai/class-stream-transport.php',
			'includes/ai/class-anthropic-provider.php',
			'includes/ai/class-openai-provider.php',
			'includes/ai/class-link-filter.php',
			'includes/ai/class-answerer.php'
		);
	}

	/** An Anthropic stream as the API sends it. */
	private static function anthropic_stream( array $pieces ): string {
		$out  = "event: message_start\ndata: " . json_encode( array( 'type' => 'message_start', 'message' => array( 'model' => 'claude-haiku-5-5', 'usage' => array( 'input_tokens' => 120, 'cache_read_input_tokens' => 3400, 'cache_creation_input_tokens' => 0, 'output_tokens' => 1 ) ) ) ) . "\n\n"; // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$out .= "event: ping\ndata: {\"type\": \"ping\"}\n\n";
		foreach ( $pieces as $piece ) {
			$out .= "event: content_block_delta\ndata: " . json_encode( array( 'type' => 'content_block_delta', 'index' => 0, 'delta' => array( 'type' => 'text_delta', 'text' => $piece ) ) ) . "\n\n"; // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		}
		$out .= "event: message_delta\ndata: " . json_encode( array( 'type' => 'message_delta', 'delta' => array( 'stop_reason' => 'end_turn' ), 'usage' => array( 'output_tokens' => 42 ) ) ) . "\n\n"; // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		return $out . "event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n";
	}

	public function test_events_survive_any_chunk_split_including_crlf(): void {
		$raw = str_replace( "\n", "\r\n", self::anthropic_stream( array( 'Héllo ', 'wörld' ) ) );
		for ( $size = 1; $size <= 7; $size++ ) {
			$parser = new Sse_Parser();
			$events = array();
			foreach ( str_split( $raw, $size ) as $chunk ) {
				$events = array_merge( $events, $parser->feed( $chunk ) );
			}
			$this->assertSame( array( 'message_start', 'ping', 'content_block_delta', 'content_block_delta', 'message_delta', 'message_stop' ), array_column( $events, 'event' ), "chunks of {$size} bytes" );
			$this->assertSame( 'Héllo ', json_decode( $events[2]['data'], true )['delta']['text'] );
		}
	}

	public function test_comments_are_skipped_and_multiline_data_joins(): void {
		$events = ( new Sse_Parser() )->feed( ": keepalive\n\nevent: x\ndata: a\ndata: b\n\n" );
		$this->assertSame(
			array(
				array(
					'event' => 'x',
					'data'  => "a\nb",
				),
			),
			$events
		);
	}

	/**
	 * Run text through the hold-back in the given pieces.
	 *
	 * @param list<string> $pieces Pieces.
	 */
	private static function hold( array $pieces ): string {
		$hold = new Holdback();
		$out  = '';
		foreach ( $pieces as $piece ) {
			$out .= $hold->push( $piece );
		}
		return $out;
	}

	public function test_the_marker_never_leaks_wherever_the_chunks_split_it(): void {
		$answer = "I don't know: the docs do not cover Salesforce. I can put you in touch with a person.\n" . self::MARKER;
		for ( $at = 1; $at < strlen( $answer ); $at++ ) {
			$out = self::hold( array( substr( $answer, 0, $at ), substr( $answer, $at ) ) );
			$this->assertStringNotContainsString( '[[', $out, "split at {$at}" );
			$this->assertStringStartsWith( "I don't know", $out );
		}
		// Every character its own chunk.
		$this->assertStringNotContainsString( 'NOT_IN', self::hold( str_split( $answer ) ) );
	}

	public function test_the_human_request_marker_never_leaks_wherever_the_chunks_split_it(): void {
		foreach ( array( "I can connect you with the team: they reply by email.\n[[HUMAN_REQUESTED]]", "I don't know that one. I can connect you with the team.\n[[NOT_IN_DOCS]]\n[[HUMAN_REQUESTED]]" ) as $answer ) {
			for ( $at = 1; $at < strlen( $answer ); $at++ ) {
				$out = self::hold( array( substr( $answer, 0, $at ), substr( $answer, $at ) ) );
				$this->assertStringNotContainsString( '[[', $out, "split at {$at}" );
			}
			$this->assertStringNotContainsString( 'HUMAN', self::hold( str_split( $answer ) ), 'every character its own chunk' );
		}
		$this->assertGreaterThanOrEqual( strlen( '[[SOURCES: kb-1234567, kb-1234568, kb-1234569]]' ), Holdback::HOLD, 'the hold covers a long sources marker' );
	}

	public function test_a_marker_at_the_start_or_middle_never_leaks_either(): void {
		$this->assertStringNotContainsString( 'NOT_IN', self::hold( str_split( self::MARKER . "\nI can only help with Delta, so here is a long enough tail to release text." ) ) );
		$this->assertStringNotContainsString( 'NOT_IN', self::hold( array( 'Some text ', '[[NOT_', 'IN_DOCS]]', ' and then a long enough tail to release everything before it.' ) ) );
	}

	public function test_plain_text_streams_at_once_in_order_and_only_an_open_marker_is_held(): void {
		$text = 'Go to Delta → Features and switch on Snippets. Then click [Open](https://example.com/x).';
		$hold = new Holdback();
		$out  = '';
		foreach ( mb_str_split( $text, 5 ) as $piece ) {
			$out .= $hold->push( $piece );
		}
		$this->assertSame( $text, $out, 'no [[ : nothing held, nothing reordered (a single [ only waits for its next character)' );
		$this->assertSame( $text, $hold->text() );

		$hold = new Holdback();
		$this->assertSame( 'Prices start at $49', $hold->push( 'Prices start at $49' ) );
		$this->assertSame( ".\n", $hold->push( ".\n[[SOUR" ), 'the open [[ and what follows wait' );
		$this->assertSame( '', $hold->push( 'CES: kb-' ) );
		$this->assertSame( '', $hold->push( '12, kb-7]]' ), 'the complete marker is removed, never released' );
	}

	public function test_a_split_sources_marker_never_leaks_wherever_the_chunks_split_it(): void {
		$answer = "The Pro licence is \$149 a year for 5 sites.\n[[SOURCES: kb-12, kb-7]]\n[[NOT_IN_DOCS]]";
		for ( $at = 1; $at < strlen( $answer ); $at++ ) {
			$out = self::hold( array( substr( $answer, 0, $at ), substr( $answer, $at ) ) );
			$this->assertStringNotContainsString( '[', $out, "split at {$at}" );
			$this->assertStringNotContainsString( 'SOURCES', $out, "split at {$at}" );
		}
		$this->assertStringNotContainsString( 'kb-', self::hold( str_split( $answer ) ), 'every character its own chunk' );
	}

	public function test_an_open_double_bracket_is_released_after_200_characters(): void {
		$hold  = new Holdback();
		$first = $hold->push( 'Use the array syntax [[1, 2], [3, 4]' );
		$this->assertSame( 'Use the array syntax ', $first, 'held from the [[' );
		$rest = $hold->push( ' and so on. ' . str_repeat( 'More text. ', 20 ) );
		$this->assertStringStartsWith( '[[1, 2]', $rest, '200 characters on, it was not a marker: released in order' );
	}

	/**
	 * A transport that answers with these chunks.
	 *
	 * @param list<string> $chunks Body chunks.
	 * @param int          $status HTTP status.
	 */
	private function fake_transport( array $chunks, int $status = 200 ): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) use ( $chunks, $status ) {
				if ( 'sigma_stream_transport' !== $hook ) {
					return $value;
				}
				return static function ( $url, $headers, $body, $on_chunk, $on_tick ) use ( $chunks, $status ) {
					$on_tick();
					foreach ( $chunks as $chunk ) {
						if ( 200 === $status ) {
							$on_chunk( $chunk );
						}
					}
					return array(
						'status'     => $status,
						'error_body' => 200 === $status ? '' : (string) json_encode( array( 'error' => array( 'type' => 'overloaded_error' ) ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
					);
				};
			}
		);
	}

	public function test_the_anthropic_stream_gives_deltas_text_and_usage(): void {
		$this->fake_transport( str_split( self::anthropic_stream( array( 'Go to ', 'Features.' ) ), 9 ) );
		$deltas = array();
		$answer = ( new Anthropic_Provider( 'k' ) )->stream(
			new Prompt( 'RULES', 'CTX' ),
			array(
				array(
					'role'    => 'user',
					'content' => 'Q',
				),
			),
			array( 'model' => 'claude-haiku-5-5' ),
			static function ( string $t ) use ( &$deltas ): void {
				$deltas[] = $t;
			},
			static function (): void {}
		);
		$this->assertInstanceOf( Answer::class, $answer );
		$this->assertSame( array( 'Go to ', 'Features.' ), $deltas );
		$this->assertSame( 'Go to Features.', $answer->text );
		$this->assertSame(
			array(
				'input'       => 120,
				'cache_write' => 0,
				'cache_read'  => 3400,
				'output'      => 42,
			),
			$answer->usage
		);
	}

	public function test_a_provider_error_status_is_ai_unavailable(): void {
		$this->fake_transport( array(), 529 );
		$result = ( new Anthropic_Provider( 'k' ) )->stream( new Prompt( 'R', 'C' ), array(), array(), static function (): void {}, static function (): void {} );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_unavailable', $result->get_error_code() );
	}

	public function test_the_openai_stream_gives_deltas_text_and_usage(): void {
		$chunk = static fn( array $d ): string => 'data: ' . json_encode( $d ) . "\n\n"; // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$raw   = $chunk( array( 'model' => 'gpt-6-luna', 'choices' => array( array( 'delta' => array( 'role' => 'assistant', 'content' => '' ) ) ) ) )
			. $chunk( array( 'choices' => array( array( 'delta' => array( 'content' => 'Go to ' ) ) ) ) )
			. $chunk( array( 'choices' => array( array( 'delta' => array( 'content' => 'Features.' ) ) ) ) )
			. $chunk( array( 'choices' => array(), 'usage' => array( 'prompt_tokens' => 3500, 'completion_tokens' => 40, 'prompt_tokens_details' => array( 'cached_tokens' => 3000 ) ) ) )
			. "data: [DONE]\n\n";
		$this->fake_transport( str_split( $raw, 11 ) );
		$deltas = array();
		$answer = ( new OpenAI_Provider( 'k' ) )->stream(
			new Prompt( 'R', 'C' ),
			array(),
			array( 'model' => 'gpt-6-luna' ),
			static function ( string $t ) use ( &$deltas ): void {
				$deltas[] = $t;
			},
			static function (): void {}
		);
		$this->assertSame( array( 'Go to ', 'Features.' ), $deltas );
		$this->assertSame( 'gpt-6-luna', $answer->model );
		$this->assertSame(
			array(
				'input'       => 500,
				'cache_write' => 0,
				'cache_read'  => 3000,
				'output'      => 40,
			),
			$answer->usage
		);
	}
}
