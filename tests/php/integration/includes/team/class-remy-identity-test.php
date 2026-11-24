<?php
/**
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Tests\Integration;

use TwentyOnePress\Sigma\Conversations\Repository;
use TwentyOnePress\Sigma\Knowledge\Corpus;
use TwentyOnePress\Sigma\Team\Avatars;
use TwentyOnePress\Sigma\Tests\Support\Integration_Test_Case;
use TwentyOnePress\Sigma\Widget;

/**
 * Remy's identity and the home texts (plan §8.6, 0.1.4): settings, /config, inline data, the Remy
 * avatar through the local avatar pipeline, and the intro that never reaches the model.
 *
 * @covers \TwentyOnePress\Sigma\Settings
 * @covers \TwentyOnePress\Sigma\Team\Avatars
 * @covers \TwentyOnePress\Sigma\Rest\Visitor_Controller
 */
final class Remy_Identity_Test extends Integration_Test_Case {

	public function set_up(): void {
		parent::set_up();
		update_option( 'blogname', 'Delta' );
		$this->answer_http( static fn( string $url ) => new \WP_Error( 'not_in_tests', $url ) );
	}

	/**
	 * An image in the Media Library.
	 *
	 * @param int $w Width.
	 * @param int $h Height.
	 */
	private function image( int $w = 200, int $h = 200 ): int {
		$file = trailingslashit( sys_get_temp_dir() ) . 'da-remy-' . wp_generate_password( 8, false ) . '.png';
		$img  = imagecreatetruecolor( $w, $h );
		imagefill( $img, 0, 0, imagecolorallocate( $img, 30, 120, 90 ) );
		imagepng( $img, $file );
		return (int) self::factory()->attachment->create_upload_object( $file );
	}

	private function config(): array {
		wp_set_current_user( 0 );
		return $this->rest( 'GET', '/config' )->get_data();
	}

	public function test_config_has_the_new_texts_with_their_defaults_and_placeholders_filled(): void {
		$config = $this->config();
		$this->assertSame( 'Talk to a human', $config['human_card_title'] );
		$this->assertSame( 'We reply by email', $config['human_reply_text'] );
		$this->assertSame( 'Talk to Sigma', $config['bot_card_title'] );
		$this->assertSame( 'Handles instant support', $config['bot_card_text'] );
		$this->assertSame( "Hi, I'm Sigma. Ask me anything about Delta and I'll answer from our docs.", $config['bot_intro'] );
		$this->assertSame( "Sigma's answers come from our docs and collective knowledge of Delta. Chats are stored for 90 days for better user experience.", $config['privacy_text_ai'] );
		$this->assertSame( 'Continue with Sigma', $config['continue_card_title'] );
		$this->assertSame( 'Continue with the team', $config['continue_human_title'] );

		$this->settings(
			array(
				'bot_name'       => 'Nova',
				'retention_days' => 30,
				'bot_card_text'  => 'Answers in seconds, {bot_name} here',
			)
		);
		$config = $this->config();
		$this->assertSame( 'Talk to Nova', $config['bot_card_title'] );
		$this->assertSame( 'Answers in seconds, Nova here', $config['bot_card_text'] );
		$this->assertStringStartsWith( "Hi, I'm Nova.", $config['bot_intro'] );
		$this->assertStringContainsString( 'stored for 30 days', $config['privacy_text_ai'] );
		$this->assertSame( 'Continue with Nova', $config['continue_card_title'] );
		foreach ( array( 'human_card_title', 'bot_card_title', 'bot_card_text', 'bot_intro', 'privacy_text_ai', 'continue_card_title', 'continue_human_title' ) as $key ) {
			$this->assertDoesNotMatchRegularExpression( '/\{[a-z_]+\}/', $config[ $key ], "{$key}: no placeholder left" );
		}
	}

	public function test_texts_are_capped_and_plain(): void {
		foreach ( array(
			'human_card_title'     => 40,
			'bot_card_title'       => 40,
			'bot_card_text'        => 80,
			'bot_intro'            => 300,
			'continue_card_title'  => 40,
			'continue_human_title' => 40,
		) as $key => $max ) {
			$this->assertSame( 200, $this->rest( 'POST', '/admin/settings', array( $key => str_repeat( 'a', $max ) ) )->get_status(), "{$key}: {$max} is fine" );
			$this->assertSame( 400, $this->rest( 'POST', '/admin/settings', array( $key => str_repeat( 'a', $max + 1 ) ) )->get_status(), "{$key}: over {$max} is refused" );
		}
		$this->rest( 'POST', '/admin/settings', array( 'bot_intro' => 'Hi <b>there</b><script>x()</script>' ) );
		$this->assertSame( 'Hi there', $this->config()['bot_intro'], 'plain text only' );
	}

	public function test_without_an_image_the_bot_avatar_url_is_empty_in_config_and_inline_data(): void {
		$this->assertSame( '', $this->config()['bot_avatar_url'], 'v1.12.1: the widget draws the default Remy inline' );
		$this->assertFileExists( P21_SIGMA_DIR . '/assets/remy-avatar.svg', 'the default ships with the plugin (admin preview, widget build)' );

		if ( ! is_readable( P21_SIGMA_DIR . '/' . Widget::script_path() ) ) {
			$this->markTestSkipped( 'The inline data needs the built widget (npm run build:widget); /config is checked above.' );
		}
		wp_set_current_user( 0 );
		wp_scripts()->registered = array();
		Widget::enqueue();
		$inline = implode( '', (array) wp_scripts()->get_data( Widget::HANDLE, 'before' ) );
		$this->assertStringContainsString( '"bot_avatar_url":""', $inline, 'the teaser has it before any REST call' );
	}

	public function test_a_chosen_image_becomes_a_local_copy_and_clearing_it_returns_to_the_svg(): void {
		$this->assertSame( 400, $this->rest( 'POST', '/admin/settings', array( 'bot_avatar' => self::factory()->post->create() ) )->get_status(), 'only images' );

		$image = $this->image( 300, 200 );
		$this->assertSame( 200, $this->rest( 'POST', '/admin/settings', array( 'bot_avatar' => $image ) )->get_status() );
		$url = $this->config()['bot_avatar_url'];
		$this->assertMatchesRegularExpression( '#/uploads/delta-assistant/avatars/[a-z0-9]{20}\.(webp|png)$#', $url, 'a copy under a random name, not the upload' );
		$file = Avatars::dir()['path'] . '/' . basename( $url );
		$this->assertSame( array( 96, 96 ), array_slice( (array) getimagesize( $file ), 0, 2 ) );
		$this->assertSame( array( 'media', 'ok' ), array( Avatars::status( Avatars::BOT )['source'], Avatars::status( Avatars::BOT )['result'] ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$admin = $this->rest( 'GET', '/admin/settings' )->get_data()['bot_avatar'];
		$this->assertFalse( $admin['default'] );
		$this->assertSame( 'Copy made from the chosen image (' . strtoupper( pathinfo( $url, PATHINFO_EXTENSION ) ) . ').', $admin['message'] );

		$this->assertSame( 200, $this->rest( 'POST', '/admin/settings', array( 'bot_avatar' => 0 ) )->get_status() );
		$this->assertFileDoesNotExist( $file, 'the copy goes' );
		$this->assertSame( '', $this->config()['bot_avatar_url'], 'back to the inline default' );
		$this->assertNull( Avatars::status( Avatars::BOT ) );
	}

	public function test_an_upload_whose_copy_fails_gives_an_empty_url_and_a_reason(): void {
		$image = $this->image();
		wp_delete_file( (string) get_attached_file( $image ) ); // The upload's file is gone: nothing to copy.
		$this->assertSame( 200, $this->rest( 'POST', '/admin/settings', array( 'bot_avatar' => $image ) )->get_status(), 'the save itself stands' );
		$this->assertSame( '', $this->config()['bot_avatar_url'], 'no copy: empty, the widget draws the default' );
		$status = Avatars::status( Avatars::BOT );
		$this->assertSame( array( 'media', 'failed', 'chat_missing' ), array( $status['source'], $status['result'], $status['reason'] ) );
	}

	public function test_the_bot_copy_survives_the_team_refresh_and_the_orphan_sweep(): void {
		$image = $this->image();
		$this->rest( 'POST', '/admin/settings', array( 'bot_avatar' => $image ) );
		$file = Avatars::dir()['path'] . '/' . basename( $this->config()['bot_avatar_url'] );
		touch( $file, time() - 3600 );
		Avatars::refresh_team();
		$this->assertFileExists( $file, 'in use by the bot: not an orphan' );
		$this->assertSame( 'unchanged', Avatars::status( Avatars::BOT )['result'] );
	}

	public function test_a_chosen_image_never_copied_is_healed_by_config(): void {
		$this->settings( array( 'bot_avatar' => $this->image() ) ); // Settings only: no copy yet.
		wp_clear_scheduled_hook( Avatars::CRON_SOON );
		delete_transient( Avatars::HEAL_LOCK );
		$this->assertSame( '', $this->config()['bot_avatar_url'], 'the inline default meanwhile' );
		$this->assertIsInt( wp_next_scheduled( Avatars::CRON_SOON ) );
		do_action( Avatars::CRON_SOON );
		$this->assertStringContainsString( '/delta-assistant/avatars/', $this->config()['bot_avatar_url'] );
	}

	public function test_the_intro_is_never_sent_to_the_model_nor_stored(): void {
		$intro = 'INTRO-MARKER-7731 hello from the bot';
		self::factory()->post->create(
			array(
				'post_title'   => 'Snippets',
				'post_content' => 'Snippets runs small pieces of code. Switch it on under Features.',
				'post_status'  => 'publish',
			)
		);
		$this->settings(
			array(
				'sources'       => array( 'post' ),
				'provider'      => 'anthropic',
				'anthropic_key' => 'sk-ant-test-key-0000',
				'bot_intro'     => $intro,
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
								'text' => 'Switch it on under Features. See [Snippets](' . get_permalink( get_posts( array( 'post_type' => 'post' ) )[0] ) . ').',
							),
						),
						'usage'       => array(
							'input_tokens'  => 500,
							'output_tokens' => 60,
						),
					)
				)
		);
		Corpus::build();
		$this->assertStringContainsString( 'INTRO-MARKER-7731', $this->config()['bot_intro'] );
		[ $uuid, $token ] = $this->conversation();
		foreach ( array( 'How do I switch on snippets?', 'And off again?' ) as $question ) {
			$response = $this->rest( 'POST', "/conversations/{$uuid}/ask", array( 'text' => $question ), array( 'X-DA-Token' => $token ) );
			$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		}
		$calls = array_filter( $this->http, static fn( array $r ): bool => 'https://api.anthropic.com/v1/messages' === $r['url'] );
		$this->assertGreaterThanOrEqual( 2, count( $calls ), 'one or more provider calls per question' );
		foreach ( $calls as $call ) {
			$this->assertStringNotContainsString( 'INTRO-MARKER-7731', (string) $call['args']['body'], 'not in the system prompt or the history' );
		}
		$repo     = new Repository();
		$messages = $repo->messages( (int) $repo->find_by_uuid( $uuid )['id'] );
		$this->assertCount( 4, $messages, 'two questions and two answers, no intro row' );
		$this->assertStringNotContainsString( 'INTRO-MARKER-7731', (string) wp_json_encode( $messages ) );
	}
}
