<?php
/**
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Tests\Integration;

use TwentyOnePress\Sigma\Conversations\Repository;
use TwentyOnePress\Sigma\Knowledge\Corpus;
use TwentyOnePress\Sigma\Tests\Support\Integration_Test_Case;
use TwentyOnePress\Sigma\Usage;

/**
 * Streamed /ask (§8.3), through the real controller, Answerer and Anthropic provider, with the HTTP
 * transport answered by the test and the SSE output captured.
 *
 * @covers \TwentyOnePress\Sigma\Rest\Visitor_Controller::ask
 * @covers \TwentyOnePress\Sigma\Rest\Sse
 * @covers \TwentyOnePress\Sigma\AI\Answerer::stream
 */
final class Ask_Stream_Test extends Integration_Test_Case {

	/**
	 * Captured SSE output.
	 *
	 * @var string
	 */
	private string $out = '';

	public function set_up(): void {
		parent::set_up();
		$this->doc( 'Snippets', '<p>Snippets runs small pieces of code. Switch on Snippets under Features.</p>' );
		$this->settings(
			array(
				'sources'       => array( 'post' ),
				'provider'      => 'anthropic',
				'anthropic_key' => 'sk-ant-test-key-0000',
			)
		);
		$this->out = '';
		add_filter(
			'sigma_sse_sink',
			fn() => function ( string $text ): void {
				$this->out .= $text;
			}
		);
		$this->answer_http( fn() => self::json( 200, array( 'input_tokens' => 900 ) ) ); // count_tokens at corpus build.
		Corpus::build();
		wp_set_current_user( 0 );
	}

	/**
	 * Answer the provider stream with these text pieces (and an error event, optionally).
	 *
	 * @param list<string> $pieces Text pieces.
	 * @param bool         $fail   End with a provider error event instead of message_stop.
	 */
	private function provider_streams( array $pieces, bool $fail = false ): void {
		add_filter(
			'sigma_stream_transport',
			static fn() => static function ( $url, $headers, $body, $on_chunk, $on_tick ) use ( $pieces, $fail ) {
				$e = static fn( string $type, array $data ): string => "event: {$type}\ndata: " . wp_json_encode( array( 'type' => $type ) + $data ) . "\n\n";
				$on_chunk( $e( 'message_start', array( 'message' => array( 'model' => 'claude-haiku-5-5', 'usage' => array( 'input_tokens' => 50, 'cache_read_input_tokens' => 900, 'output_tokens' => 1 ) ) ) ) );
				foreach ( $pieces as $piece ) {
					$on_tick();
					// Split each event in two, as a network would.
					$raw = $e( 'content_block_delta', array( 'delta' => array( 'type' => 'text_delta', 'text' => $piece ) ) );
					$on_chunk( substr( $raw, 0, 17 ) );
					$on_chunk( substr( $raw, 17 ) );
				}
				if ( $fail ) {
					$on_chunk( $e( 'error', array( 'error' => array( 'type' => 'overloaded_error' ) ) ) );
				} else {
					$on_chunk( $e( 'message_delta', array( 'delta' => array( 'stop_reason' => 'end_turn' ), 'usage' => array( 'output_tokens' => 30 ) ) ) );
				}
				return array(
					'status'     => 200,
					'error_body' => '',
				);
			}
		);
	}

	/**
	 * The captured events.
	 *
	 * @return list<array{event: string, data: array}>
	 */
	private function events(): array {
		preg_match_all( "/event: (\\w+)\ndata: (.*)\n\n/", $this->out, $m, PREG_SET_ORDER );
		return array_map(
			static fn( array $x ): array => array(
				'event' => $x[1],
				'data'  => json_decode( $x[2], true ),
			),
			$m
		);
	}

	private function ask( string $uuid, string $token, string $text, string $accept = 'text/event-stream' ): \WP_REST_Response {
		return $this->rest( 'POST', "/conversations/{$uuid}/ask", array( 'text' => $text ), array( 'X-DA-Token' => $token, 'Accept' => $accept ) );
	}

	public function test_start_deltas_done_with_the_answer_stored_and_usage_recorded(): void {
		$link = get_permalink( get_posts( array( 'post_type' => 'post' ) )[0] );
		$this->provider_streams( array( 'Go to **Features** and switch on Snippets. ', 'See [Snippets](' . $link . ') and ', '[evil](https://evil.example.net/).' ) );
		[ $uuid, $token ] = $this->conversation();
		$this->ask( $uuid, $token, 'How do I switch on snippets?' );

		$events = $this->events();
		$this->assertSame( 'start', $events[0]['event'] );
		$this->assertSame( $uuid, $events[0]['data']['conversation'] );
		$this->assertSame( 'done', end( $events )['event'] );
		$deltas = array_filter( $events, static fn( array $e ): bool => 'delta' === $e['event'] );
		$this->assertGreaterThan( 1, count( $deltas ), 'text arrives in pieces' );
		$streamed = implode( '', array_map( static fn( array $e ): string => $e['data']['text'], $deltas ) );
		$this->assertStringStartsWith( 'Go to **Features**', $streamed );

		$done = end( $events )['data'];
		$this->assertSame( array( 'message', 'answered', 'handoff_requested', 'can_handoff' ), array_keys( $done ), 'the same shape as the JSON answer' );
		$this->assertTrue( $done['answered'] );
		$this->assertStringNotContainsString( 'evil.example.net', $done['message']['body'], 'links validated at done' );
		$this->assertSame( array( $link ), array_column( $done['message']['links'], 'url' ) );

		$stored = ( new Repository() )->messages( (int) ( new Repository() )->find_by_uuid( $uuid )['id'] );
		$this->assertSame( $done['message']['body'], end( $stored )['body'] );
		$this->assertSame( 30, end( $stored )['meta']['usage']['output'] );
		$this->assertSame( 1, Usage::month()['calls'] );
	}

	public function test_the_marker_never_reaches_the_raw_stream(): void {
		$this->provider_streams( array( "I don't know: the docs do not cover Salesforce. I can put you in touch with a person.\n[[NOT_", 'IN_DOCS]]' ) );
		[ $uuid, $token ] = $this->conversation();
		$this->ask( $uuid, $token, 'Salesforce?' );
		$this->assertStringNotContainsString( 'NOT_IN', $this->out );
		$this->assertStringNotContainsString( '[[', $this->out );
		$events = $this->events();
		$this->assertFalse( end( $events )['data']['answered'] );
	}

	public function test_the_human_marker_never_reaches_the_stream_and_done_says_handoff_requested(): void {
		$this->provider_streams( array( 'I can connect you with the team: they reply by email.', "\n[[HUMAN_", 'REQUESTED]]' ) );
		[ $uuid, $token ] = $this->conversation();
		$this->ask( $uuid, $token, 'please let me chat to someone' );
		$this->assertStringNotContainsString( 'HUMAN_', $this->out );
		$this->assertStringNotContainsString( '[[', $this->out );
		$events = $this->events();
		$done   = end( $events );
		$this->assertSame( 'done', $done['event'] );
		$this->assertTrue( $done['data']['handoff_requested'] );
	}

	public function test_a_visitor_who_leaves_mid_stream_still_gets_the_answer_stored(): void {
		// After the first delta nothing reaches the browser, like a closed tab; the server goes on.
		remove_all_filters( 'sigma_sse_sink' );
		$writes = 0;
		$sink   = static function () use ( &$writes ): void {
			++$writes;
		};
		add_filter( 'sigma_sse_sink', static fn() => $sink );
		$this->provider_streams( array( 'Switch on Snippets under Features. ', 'It is off by default.' ) );
		[ $uuid, $token ] = $this->conversation();
		$this->ask( $uuid, $token, 'snippets?' );
		$stored = ( new Repository() )->messages( (int) ( new Repository() )->find_by_uuid( $uuid )['id'] );
		$this->assertSame( 'ai', end( $stored )['sender'], 'the answer is stored' );
		$this->assertGreaterThan( 3, $writes );
	}

	public function test_a_mid_stream_provider_error_is_an_error_event_and_forgets_the_question(): void {
		$this->provider_streams( array( 'Partial answer that goes on for a while ' ), true );
		[ $uuid, $token ] = $this->conversation();
		$this->ask( $uuid, $token, 'snippets?' );
		$events = $this->events();
		$last   = end( $events );
		$this->assertSame( 'error', $last['event'] );
		$this->assertSame( 'ai_unavailable', $last['data']['code'] );
		$this->assertTrue( $last['data']['data']['retryable'] );
		$this->assertSame( array(), ( new Repository() )->messages( (int) ( new Repository() )->find_by_uuid( $uuid )['id'] ), 'a retry does not repeat the question' );
	}

	public function test_a_visitor_request_never_builds_the_corpus(): void {
		delete_option( Corpus::OPTION );
		delete_option( Corpus::META_OPTION );
		wp_clear_scheduled_hook( Corpus::CRON_REBUILD );
		$this->http = array();
		[ $uuid, $token ] = $this->conversation();
		foreach ( array( 'text/event-stream', 'application/json' ) as $accept ) {
			$response = $this->ask( $uuid, $token, 'How do I switch on snippets?', $accept );
			$this->assertSame( 503, $response->get_status(), "{$accept}: 503 while building" );
			$this->assertSame( 'corpus_building', $response->get_data()['data']['reason'] );
		}
		$this->assertSame( '', $this->out, 'no stream opened' );
		$this->assertFalse( get_option( Corpus::OPTION ), 'nothing was built inline' );
		$urls = array_column( $this->http, 'url' );
		$this->assertSame( array(), array_values( array_filter( $urls, static fn( string $u ): bool => str_contains( $u, 'anthropic.com' ) ) ), 'no count_tokens call on a visitor request' );
		$this->assertNotEmpty( array_filter( $urls, static fn( string $u ): bool => str_contains( $u, 'wp-cron.php' ) ), 'WP-Cron is spawned (non-blocking) so a quiet site builds without waiting for traffic' );
		$this->assertNotFalse( wp_next_scheduled( Corpus::CRON_REBUILD ), 'a build is scheduled instead' );
	}

	public function test_stale_sources_answer_from_the_last_good_corpus_and_schedule_a_rebuild(): void {
		$hash = get_option( Corpus::OPTION )['hash'];
		register_post_type( 'da_extra', array( 'public' => true ) );
		update_option( 'delta_assistant_settings', array_merge( (array) get_option( 'delta_assistant_settings' ), array( 'sources' => array( 'post', 'da_extra' ) ) ) );
		\TwentyOnePress\Sigma\Settings::flush();
		wp_clear_scheduled_hook( Corpus::CRON_REBUILD );
		$this->provider_streams( array( 'Switch on Snippets under Features. See the docs page for more.' ) );
		[ $uuid, $token ] = $this->conversation();
		$this->ask( $uuid, $token, 'How do I switch on snippets?' );
		$events = $this->events();
		$this->assertSame( 'done', end( $events )['event'], 'answered from the last good corpus' );
		$this->assertSame( $hash, get_option( Corpus::OPTION )['hash'], 'not rebuilt inline' );
		$this->assertNotFalse( wp_next_scheduled( Corpus::CRON_REBUILD ) );
		unregister_post_type( 'da_extra' );
	}

	public function test_errors_before_the_stream_are_normal_json(): void {
		[ $uuid, $token ] = $this->conversation();
		$response         = $this->ask( $uuid, $token, '' );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( '', $this->out, 'no stream was opened' );

		$this->settings( array( 'sources' => array() ) );
		$response = $this->ask( $uuid, $token, 'snippets?' );
		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'ai_unavailable', $response->get_data()['code'] );
		$this->assertSame( '', $this->out );
	}

	public function test_without_accept_event_stream_the_answer_is_json_as_before(): void {
		$this->provider_streams( array( 'unused' ) );
		$this->answer_http(
			fn() => self::json(
				200,
				array(
					'model'       => 'claude-haiku-5-5',
					'stop_reason' => 'end_turn',
					'content'     => array(
						array(
							'type' => 'text',
							'text' => 'Switch it on under Features.',
						),
					),
					'usage'       => array(
						'input_tokens'  => 10,
						'output_tokens' => 5,
					),
				)
			)
		);
		[ $uuid, $token ] = $this->conversation();
		$response         = $this->ask( $uuid, $token, 'snippets?', 'application/json' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Switch it on under Features.', $response->get_data()['message']['body'] );
		$this->assertSame( '', $this->out );
	}

	public function test_the_streaming_self_test_sends_ten_parts_and_needs_manage_options(): void {
		add_filter( 'sigma_stream_test_pause', '__return_zero' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, $this->rest( 'POST', '/admin/stream-test' )->get_status() );
		$this->assertSame( '', $this->out );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->rest( 'POST', '/admin/stream-test' );
		$events = $this->events();
		$this->assertSame( 'start', $events[0]['event'] );
		$this->assertCount( 10, array_filter( $events, static fn( array $e ): bool => 'delta' === $e['event'] ) );
		$this->assertSame( 'done', end( $events )['event'] );
		$this->assertSame( 0, Usage::month()['calls'], 'no AI call, no cost' );
	}

	public function test_the_test_provider_streams_in_pieces(): void {
		add_filter( 'sigma_mock_stream_pause', '__return_zero' );
		$this->settings( array( 'provider' => 'mock' ) );
		[ $uuid, $token ] = $this->conversation();
		$this->ask( $uuid, $token, 'How do I switch on snippets?' );
		$events = $this->events();
		$this->assertGreaterThan( 3, count( array_filter( $events, static fn( array $e ): bool => 'delta' === $e['event'] ) ) );
		$this->assertSame( 'done', end( $events )['event'] );
	}
}
