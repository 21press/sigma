<?php
/**
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Tests\Integration;

use TwentyOnePress\Sigma\Knowledge\Corpus;
use TwentyOnePress\Sigma\Usage;
use TwentyOnePress\Sigma\Tests\Support\Integration_Test_Case;
use TwentyOnePress\Sigma\Widget;

/**
 * The visitor API (§8, §8.1), the kill switch, admins-only mode and the spend cap.
 *
 * @covers \TwentyOnePress\Sigma\Rest\Visitor_Controller
 * @covers \TwentyOnePress\Sigma\AI\Answerer
 */
final class Visitor_Controller_Test extends Integration_Test_Case {

	public function set_up(): void {
		parent::set_up();
		$this->doc( 'Snippets', '<p>Snippets runs small pieces of code. Switch on Snippets under Features.</p>' );
		$this->settings( array( 'sources' => array( 'post' ) ) );
		Corpus::build();
		wp_set_current_user( 0 );
	}

	public function test_config_has_the_v1_1_shape(): void {
		$data = $this->rest( 'GET', '/config' )->get_data();
		foreach ( array( 'enabled', 'ai_enabled', 'human_enabled', 'confirmation_email', 'greeting', 'intro', 'human_reply_text', 'privacy_text_ai', 'privacy_text_human', 'privacy_url', 'retention_days', 'brand', 'agents_online' ) as $key ) {
			$this->assertArrayHasKey( $key, $data, "/config has {$key}" );
		}
		$this->assertSame( array( 'name', 'logo_url', 'color_light', 'color_dark', 'bg_light', 'bg_dark', 'text_light', 'text_dark', 'launcher_icon', 'scheme', 'position', 'offset_x', 'offset_y', 'font' ), array_keys( $data['brand'] ) );
		$this->assertSame( 0, $data['agents_online'], 'always 0 in R1' );
		$this->assertStringContainsString( '90 days', $data['privacy_text_ai'] );
	}

	public function test_bot_name_teaser_and_pulse_reach_the_page_config_and_prompt(): void {
		$this->assertSame( 'Sigma', $this->rest( 'GET', '/config' )->get_data()['bot_name'], 'default' );
		$this->settings(
			array(
				'bot_name'       => 'Ada',
				'launcher_pulse' => false,
			)
		);
		$this->assertSame( 'Ada', $this->rest( 'GET', '/config' )->get_data()['bot_name'] );

		// The inline data carries them, so nothing calls REST before the chat opens (§8.5).
		$build = P21_SIGMA_DIR . '/build/widget/widget.js';
		$made  = false;
		if ( ! is_readable( $build ) ) {
			wp_mkdir_p( dirname( $build ) );
			file_put_contents( $build, '/* test */' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$made = true;
		}
		\TwentyOnePress\Sigma\Widget::enqueue();
		$before = (array) wp_scripts()->get_data( \TwentyOnePress\Sigma\Widget::HANDLE, 'before' );
		if ( $made ) {
			wp_delete_file( $build );
		}
		$inline = (string) end( $before );
		$data   = json_decode( (string) preg_replace( '/^window\.SigmaWidget=|;$/', '', $inline ), true );
		$this->assertSame( 'Ada', $data['bot_name'] );
		$this->assertSame(
			array(
				'enabled' => true,
				'text'    => '👋 Hi, this is Ada. How can I help you?',
			),
			$data['teaser']
		);
		$this->assertFalse( $data['pulse'] );

		$this->assertStringContainsString( "Your name is Ada. You are the AI assistant for", \TwentyOnePress\Sigma\AI\Answerer::rules() );
		$this->assertStringContainsString( 'say you are an AI assistant', \TwentyOnePress\Sigma\AI\Answerer::rules() );
	}

	public function test_a_visitor_asks_and_gets_a_doc_link(): void {
		[ $uuid, $token ] = $this->conversation();
		$response         = $this->rest( 'POST', "/conversations/{$uuid}/ask", array( 'text' => 'How do I switch on snippets?' ), array( 'X-DA-Token' => $token ) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['answered'] );
		$this->assertTrue( $data['can_handoff'] );
		$this->assertSame( 'ai', $data['message']['sender'] );
		$this->assertSame( 'Snippets', $data['message']['links'][0]['title'] );
	}

	public function test_not_in_the_docs_is_answered_false_without_the_marker(): void {
		[ $uuid, $token ] = $this->conversation();
		$data             = $this->rest( 'POST', "/conversations/{$uuid}/ask", array( 'text' => 'capital of peru' ), array( 'X-DA-Token' => $token ) )->get_data();
		$this->assertFalse( $data['answered'] );
		$this->assertStringNotContainsString( '[[NOT_IN_DOCS]]', $data['message']['body'], 'the marker never reaches the visitor' );
	}

	public function test_handoff_requested_is_true_only_when_the_visitor_asks_for_a_human(): void {
		[ $uuid, $token ] = $this->conversation();
		$ask              = fn( string $text ) => $this->rest( 'POST', "/conversations/{$uuid}/ask", array( 'text' => $text ), array( 'X-DA-Token' => $token ) )->get_data();

		$doc = $ask( 'How do I switch on snippets?' );
		$this->assertFalse( $doc['handoff_requested'], 'an answered doc question: no offer' );

		$human = $ask( 'Can I talk to a human please?' );
		$this->assertTrue( $human['handoff_requested'] );
		$this->assertStringNotContainsString( '[[', $human['message']['body'], 'the marker never reaches the visitor' );

		$offtopic = $ask( 'capital of peru' );
		$this->assertFalse( $offtopic['answered'] );
		$this->assertFalse( $offtopic['handoff_requested'], 'not in the docs is answered:false, not a request' );

		$stored = ( new \TwentyOnePress\Sigma\Conversations\Repository() )->messages( (int) ( new \TwentyOnePress\Sigma\Conversations\Repository() )->find_by_uuid( $uuid )['id'] );
		$meta = is_array( $stored[3]['meta'] ) ? $stored[3]['meta'] : (array) json_decode( (string) $stored[3]['meta'], true );
		$this->assertTrue( (bool) ( $meta['handoff_requested'] ?? false ), 'kept in the message meta for the transcript' );
	}

	public function test_the_keyword_fallback_sets_handoff_requested_when_the_model_forgets_the_marker(): void {
		$this->settings(
			array(
				'provider'      => 'anthropic',
				'anthropic_key' => 'sk-ant-test-key-0000',
			)
		);
		$this->answer_http(
			fn( string $url ) => str_contains( $url, 'count_tokens' )
				? self::json( 200, array( 'input_tokens' => 1200 ) )
				: self::json(
					200,
					array(
						'model'       => 'claude-haiku-5-5',
						'stop_reason' => 'end_turn',
						'content'     => array(
							array(
								'type' => 'text',
								'text' => 'Sure, the team can help. Use the button below.',
							),
						),
						'usage'       => array(
							'input_tokens'  => 100,
							'output_tokens' => 20,
						),
					)
				)
		);
		\TwentyOnePress\Sigma\Knowledge\Corpus::build();
		[ $uuid, $token ] = $this->conversation();
		$data             = $this->rest( 'POST', "/conversations/{$uuid}/ask", array( 'text' => '¿Puedo hablar con un humano?' ), array( 'X-DA-Token' => $token ) )->get_data();
		$this->assertTrue( $data['handoff_requested'], 'no marker, but the visitor asked' );
		$sent = (string) end( $this->http )['args']['body'];
		$this->assertStringContainsString( '[[HUMAN_REQUESTED]]', $sent, 'the rule is in the prompt' );
	}

	public function test_a_conversation_opens_only_with_its_own_token(): void {
		[ $uuid ]   = $this->conversation();
		[ , $other ] = $this->conversation();
		$this->assertSame( 403, $this->rest( 'POST', "/conversations/{$uuid}/ask", array( 'text' => 'hi' ), array( 'X-DA-Token' => $other ) )->get_status(), 'another conversation\'s token' );
		$this->assertSame( 403, $this->rest( 'POST', "/conversations/{$uuid}/ask", array( 'text' => 'hi' ) )->get_status(), 'no token' );
		$this->assertSame( 403, $this->rest( 'POST', '/conversations/' . wp_generate_uuid4() . '/ask', array( 'text' => 'hi' ), array( 'X-DA-Token' => $other ) )->get_status(), 'unknown conversation: 403, never says whether it exists (§10b)' );
	}

	public function test_empty_and_too_long_text_are_refused(): void {
		[ $uuid, $token ] = $this->conversation();
		$this->assertSame( 'invalid_text', $this->rest( 'POST', "/conversations/{$uuid}/ask", array( 'text' => '   ' ), array( 'X-DA-Token' => $token ) )->get_data()['code'] );
		$this->assertSame( 'text_too_long', $this->rest( 'POST', "/conversations/{$uuid}/ask", array( 'text' => str_repeat( 'a', 2001 ) ), array( 'X-DA-Token' => $token ) )->get_data()['code'] );
	}

	public function test_a_second_conversation_within_10_seconds_waits(): void {
		$this->conversation();
		$response = $this->rest( 'POST', '/conversations', array() );
		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'rate_limited', $response->get_data()['code'] );
		$this->assertGreaterThan( 0, $response->get_data()['data']['retry_after'] );
		$this->assertContains( $response->get_data()['data']['scope'], array( 'hour', 'day' ) );
	}

	public function test_the_hourly_question_limit_answers_429_with_scope_hour(): void {
		add_filter(
			'sigma_rate_limits',
			static function ( array $limits ): array {
				$limits['ask_hour'] = array( 1, HOUR_IN_SECONDS );
				return $limits;
			}
		);
		[ $uuid, $token ] = $this->conversation();
		$this->rest( 'POST', "/conversations/{$uuid}/ask", array( 'text' => 'snippets' ), array( 'X-DA-Token' => $token ) );
		$response = $this->rest( 'POST', "/conversations/{$uuid}/ask", array( 'text' => 'snippets again' ), array( 'X-DA-Token' => $token ) );
		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'hour', $response->get_data()['data']['scope'] );
		$this->assertLessThanOrEqual( HOUR_IN_SECONDS, $response->get_data()['data']['retry_after'] );
	}

	public function test_the_kill_switch_closes_the_api_and_hides_the_bubble(): void {
		[ $uuid, $token ] = $this->conversation();
		$this->settings( array( 'enabled' => false ) );
		$this->assertFalse( $this->rest( 'GET', '/config' )->get_data()['enabled'] );
		$this->assertSame( 'disabled', $this->rest( 'POST', "/conversations/{$uuid}/ask", array( 'text' => 'hi' ), array( 'X-DA-Token' => $token ) )->get_data()['code'] );
		$this->assertSame( 503, $this->rest( 'POST', '/conversations', array() )->get_status() );
		$this->assertFalse( Widget::should_load() );
	}

	public function test_admins_only_closes_the_api_to_visitors_but_not_to_admins(): void {
		$this->settings( array( 'admins_only' => true ) );
		$response = $this->rest( 'POST', '/conversations', array() );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'not_available', $response->get_data()['code'] );
		$this->assertFalse( $this->rest( 'GET', '/config' )->get_data()['enabled'] );
		$this->assertFalse( Widget::should_load(), 'visitors get no bubble at all' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( Widget::should_load() );
		$this->assertSame( 201, $this->rest( 'POST', '/conversations', array() )->get_status() );
	}

	public function test_there_is_no_spend_cap_and_no_visitor_confirmation_email(): void {
		$this->settings(
			array(
				'provider'      => 'anthropic',
				'anthropic_key' => 'sk-ant-test-key-0000',
			)
		);
		update_option( Usage::option(), array( 'cost' => 999.0 ) );
		$config = $this->rest( 'GET', '/config' )->get_data();
		$this->assertTrue( $config['ai_enabled'], 'no amount of usage turns the AI off (owner, v1.6)' );
		$this->assertFalse( $config['confirmation_email'], 'the chat confirms; no visitor email' );
	}

	public function test_the_anthropic_call_caches_the_docs_sends_no_personal_data_and_drops_foreign_links(): void {
		$this->settings(
			array(
				'provider'      => 'anthropic',
				'anthropic_key' => 'sk-ant-test-key-0000',
			)
		);
		$this->answer_http(
			fn( string $url ) => str_contains( $url, 'count_tokens' )
				? self::json( 200, array( 'input_tokens' => 1200 ) )
				: self::json(
					200,
					array(
						'model'       => 'claude-haiku-5-5',
						'stop_reason' => 'end_turn',
						'content'     => array(
							array(
								'type' => 'text',
								'text' => 'Switch it on under Features. See [Snippets](' . get_permalink( get_posts( array( 'post_type' => 'post' ) )[0] ) . ') and [this](https://evil.example.net/x).',
							),
						),
						'usage'       => array(
							'input_tokens'                => 500,
							'cache_read_input_tokens'     => 1200,
							'cache_creation_input_tokens' => 0,
							'output_tokens'               => 60,
						),
					)
				)
		);
		Corpus::build();
		[ $uuid, $token ] = $this->conversation();
		$this->rest( 'POST', "/conversations/{$uuid}/handoff", array( 'name' => 'Ann', 'email' => 'ann@example.test', 'text' => 'Earlier question' ), array( 'X-DA-Token' => $token ) );
		$data = $this->rest( 'POST', "/conversations/{$uuid}/ask", array( 'text' => 'How do I switch on snippets?' ), array( 'X-DA-Token' => $token ) )->get_data();

		$call = end( $this->http );
		$this->assertSame( 'https://api.anthropic.com/v1/messages', $call['url'] );
		$body = json_decode( $call['args']['body'], true );
		$this->assertSame( array( 'type' => 'ephemeral' ), $body['system'][0]['cache_control'] );
		$this->assertStringContainsString( 'Snippets runs small pieces of code', $body['system'][0]['text'], 'the docs are in the cached block (whole mode)' );
		$sent = (string) wp_json_encode( $body );
		$this->assertStringNotContainsString( 'ann@example.test', $sent, 'no email goes to the provider' );
		$this->assertStringNotContainsString( '"Ann"', $sent, 'no name goes to the provider' );
		$this->assertStringNotContainsString( 'sk-ant-test-key-0000', $sent );

		$this->assertStringNotContainsString( 'evil.example.net', $data['message']['body'] );
		$this->assertCount( 1, $data['message']['links'] );
		$this->assertGreaterThan( 0, Usage::month()['cost'], 'the call is counted in this month\'s usage' );
	}

	public function test_a_failed_answer_forgets_the_question_so_a_retry_does_not_repeat_it(): void {
		$this->settings(
			array(
				'provider'      => 'anthropic',
				'anthropic_key' => 'sk-ant-test-key-0000',
			)
		);
		$this->answer_http( fn() => self::json( 529, array( 'error' => array( 'type' => 'overloaded_error' ) ) ) );
		Corpus::build();
		[ $uuid, $token ] = $this->conversation();
		$this->rest( 'POST', "/conversations/{$uuid}/ask", array( 'text' => 'snippets' ), array( 'X-DA-Token' => $token ) );
		$repo = new \TwentyOnePress\Sigma\Conversations\Repository();
		$this->assertSame( array(), $repo->messages( (int) $repo->find_by_uuid( $uuid )['id'] ) );
	}

	public function test_a_provider_failure_is_a_503_ai_unavailable(): void {
		$this->settings(
			array(
				'provider'      => 'anthropic',
				'anthropic_key' => 'sk-ant-test-key-0000',
			)
		);
		$this->answer_http( fn() => self::json( 529, array( 'error' => array( 'type' => 'overloaded_error' ) ) ) );
		Corpus::build();
		[ $uuid, $token ] = $this->conversation();
		$response         = $this->rest( 'POST', "/conversations/{$uuid}/ask", array( 'text' => 'snippets' ), array( 'X-DA-Token' => $token ) );
		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'ai_unavailable', $response->get_data()['code'] );
	}
}
