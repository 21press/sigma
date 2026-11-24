<?php
/**
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Tests\Integration;

use TwentyOnePress\Sigma\Conversations\Repository;
use TwentyOnePress\Sigma\Knowledge\Corpus;
use TwentyOnePress\Sigma\Mail\Smtp_Check;
use TwentyOnePress\Sigma\Plugin;
use TwentyOnePress\Sigma\Privacy\Privacy;
use TwentyOnePress\Sigma\Security\Turnstile;
use TwentyOnePress\Sigma\Settings;
use TwentyOnePress\Sigma\Tests\Support\Integration_Test_Case;

/**
 * §10b: Turnstile, SMTP targets and password reuse, the corpus as a visitor, secrets at rest,
 * uninstall, the exporter, the 0.1.7 migration.
 *
 * @covers \TwentyOnePress\Sigma\Security\Turnstile
 * @covers \TwentyOnePress\Sigma\Settings
 * @covers \TwentyOnePress\Sigma\Mail\Smtp_Check
 * @covers \TwentyOnePress\Sigma\Knowledge\Corpus
 * @covers \TwentyOnePress\Sigma\Privacy\Privacy
 * @covers \TwentyOnePress\Sigma\Plugin
 */
final class Hardening_Admin_Test extends Integration_Test_Case {

	/** Cloudflare's public always-pass test site key. */
	private const TEST_SITE_KEY = '1x00000000000000000000AA';

	/** Cloudflare's public always-pass test secret. */
	private const TEST_SECRET = '1x0000000000000000000000000000000AA';

	public function set_up(): void {
		parent::set_up();
		$_SERVER['REMOTE_ADDR'] = '198.51.100.40';
		$this->settings( array( 'ip_source' => 'remote_addr' ) );
	}

	/**
	 * Siteverify answers with this body; every call is recorded.
	 *
	 * @param array|\WP_Error $answer Siteverify JSON, or an error.
	 */
	private function siteverify( $answer ): void {
		$this->answer_http(
			static function ( string $url ) use ( $answer ) {
				if ( Turnstile::VERIFY_URL !== $url ) {
					return new \WP_Error( 'unexpected', $url );
				}
				return is_wp_error( $answer ) ? $answer : self::json( 200, $answer );
			}
		);
	}

	private function turnstile_on(): void {
		$this->settings(
			array(
				'turnstile_site_key' => self::TEST_SITE_KEY,
				'turnstile_secret'   => self::TEST_SECRET,
				'turnstile_enabled'  => true,
			)
		);
	}

	private function handoff( string $uuid, string $token, array $extra = array() ): \WP_REST_Response {
		return $this->rest(
			'POST',
			"/conversations/{$uuid}/handoff",
			$extra + array(
				'name'  => 'Ann',
				'email' => 'ann@example.test',
				'text'  => 'Please help.',
			),
			array( 'X-DA-Token' => $token )
		);
	}

	public function test_turnstile_is_off_by_default_and_config_says_so(): void {
		$data = $this->rest( 'GET', '/config' )->get_data();
		$this->assertSame( '', $data['turnstile_site_key'] );
		$this->assertSame( 'auto', $data['turnstile_theme'] );
		$this->assertSame( wp_parse_url( home_url(), PHP_URL_HOST ), $data['site_host'] );
		[ $uuid, $token ] = $this->conversation( false );
		$this->assertSame( 200, $this->handoff( $uuid, $token )->get_status(), 'no token needed while off' );
		$this->assertSame( array(), $this->http, 'no siteverify call while off' );
	}

	public function test_handoff_needs_a_token_that_passes_siteverify(): void {
		$this->turnstile_on();
		$this->settings( array( 'turnstile_appearance' => 'dark' ) );
		$config = $this->rest( 'GET', '/config' )->get_data();
		$this->assertSame( self::TEST_SITE_KEY, $config['turnstile_site_key'] );
		$this->assertSame( 'dark', $config['turnstile_theme'] );

		$this->siteverify(
			array(
				'success'     => false,
				'error-codes' => array( 'invalid-input-response' ),
			)
		);
		[ $uuid, $token ] = $this->conversation( false );
		$missing          = $this->handoff( $uuid, $token );
		$this->assertSame( 403, $missing->get_status(), 'Cloudflare answers, so a missing token is refused' );
		$this->assertSame( 'turnstile_required', $missing->as_error()->get_error_code() );
		$this->assertCount( 1, $this->http, 'one reachability probe' );

		$failed = $this->handoff( $uuid, $token, array( 'turnstile_token' => 'XXXX.DUMMY.TOKEN' ) );
		$this->assertSame( 403, $failed->get_status(), 'an invalid token is never accepted' );
		$this->assertSame( 'turnstile_failed', $failed->as_error()->get_error_code() );
		$last = end( $this->http );
		$this->assertSame( self::TEST_SECRET, $last['args']['body']['secret'] );
		$this->assertSame( 'XXXX.DUMMY.TOKEN', $last['args']['body']['response'] );
		$this->assertSame( '198.51.100.40', $last['args']['body']['remoteip'] );
		$this->assertSame( 5, $last['args']['timeout'] );
		$this->assertSame( 0, Repository::counter_get( 'rl:handoff_global_hour:global' ), 'a refused handoff uses none of the site cap' );

		remove_all_filters( 'pre_http_request' );
		$this->siteverify( array( 'success' => true ) );
		$this->assertSame( 200, $this->handoff( $uuid, $token, array( 'turnstile_token' => 'XXXX.DUMMY.TOKEN' ) )->get_status() );
	}

	public function test_a_network_error_or_bad_answer_is_never_a_pass(): void {
		$this->turnstile_on();
		$this->siteverify( new \WP_Error( 'http_request_failed', 'timed out' ) );
		$this->assertSame( array( 'network-error' ), Turnstile::verify( self::TEST_SECRET, 'tok' )['codes'] );
		$this->assertFalse( Turnstile::verify( self::TEST_SECRET, 'tok' )['success'] );
		$this->assertTrue( Turnstile::verify( self::TEST_SECRET, 'tok' )['unreachable'] );
		$this->assertFalse( Turnstile::test_keys()['ok'], 'Test keys never passes while Cloudflare is unreachable' );
		remove_all_filters( 'pre_http_request' );
		$this->answer_http( static fn() => self::json( 502, array() ) );
		$this->assertTrue( Turnstile::verify( self::TEST_SECRET, 'tok' )['unreachable'], 'a 5xx from Cloudflare counts as unreachable' );
		remove_all_filters( 'pre_http_request' );
		$this->answer_http( static fn() => array( 'response' => array( 'code' => 200 ), 'body' => 'not json', 'headers' => array(), 'cookies' => array() ) );
		$this->assertFalse( Turnstile::verify( self::TEST_SECRET, 'tok' )['success'] );
		$this->assertFalse( Turnstile::verify( self::TEST_SECRET, 'tok' )['unreachable'], 'a malformed 200 is a failure, not an outage' );
		remove_all_filters( 'pre_http_request' );
		$this->answer_http( static fn() => self::json( 200, array( 'success' => 'true' ) ) );
		$this->assertFalse( Turnstile::verify( self::TEST_SECRET, 'tok' )['success'], 'only a JSON true passes' );
	}

	public function test_a_new_conversation_needs_a_token_once_the_site_breaker_is_past_half(): void {
		$this->turnstile_on();
		$this->assertSame( 201, $this->rest( 'POST', '/conversations', array() )->get_status(), 'quiet site: no token' );
		self::clear_counters( 'rl:conversation_ip' );
		Repository::counter_add( 'rl:ask_global_hour:global', HOUR_IN_SECONDS, 301 ); // Past 600 / 2.
		$this->siteverify(
			array(
				'success'     => false,
				'error-codes' => array( 'invalid-input-response' ),
			)
		);
		$busy = $this->rest( 'POST', '/conversations', array() );
		$this->assertSame( 403, $busy->get_status() );
		$this->assertSame( 'turnstile_required', $busy->as_error()->get_error_code() );
		remove_all_filters( 'pre_http_request' );
		$this->siteverify( array( 'success' => true ) );
		$this->assertSame( 201, $this->rest( 'POST', '/conversations', array( 'turnstile_token' => 'tok' ) )->get_status() );
	}

	public function test_when_cloudflare_is_unreachable_a_handoff_is_accepted_at_1_per_hour(): void {
		$this->turnstile_on();
		$this->siteverify( new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 5000 milliseconds' ) );
		[ $uuid, $token ] = $this->conversation( false );
		$this->assertSame( 200, $this->handoff( $uuid, $token, array( 'turnstile_token' => 'tok' ) )->get_status(), 'a timeout does not cut off human contact' );
		$visitor = array_values( array_filter( ( new Repository() )->messages( (int) ( new Repository() )->find_by_uuid( $uuid )['id'] ), static fn( array $m ): bool => 'visitor' === $m['sender'] ) );
		$this->assertSame( 'unreachable', $visitor[0]['meta']['turnstile'] ?? null, 'recorded on the conversation' );

		[ $uuid2, $token2 ] = $this->conversation( false );
		$again              = $this->handoff( $uuid2, $token2, array( 'turnstile_token' => 'tok', 'email' => 'second@example.test' ) );
		$this->assertSame( 429, $again->get_status(), 'the stricter limit: 1 per hour per IP during an outage' );
		$this->assertNull( ( new Repository() )->find_by_uuid( $uuid2 ), 'the refused handoff stored nothing' );

		// Tokenless (the challenge could not load): accepted only while the probe also fails.
		$_SERVER['REMOTE_ADDR'] = '198.51.100.41';
		[ $uuid3, $token3 ]     = $this->conversation( false );
		$this->assertSame( 200, $this->handoff( $uuid3, $token3, array( 'email' => 'third@example.test' ) )->get_status() );

		ob_start();
		Turnstile::notices();
		$notice = (string) ob_get_clean();
		$this->assertStringContainsString( 'could not be reached 3 times this hour', $notice, 'the notice after 3 events' );
	}

	public function test_a_tokenless_request_probes_cloudflare_at_most_once_a_minute(): void {
		$this->turnstile_on();
		$this->siteverify(
			array(
				'success'     => false,
				'error-codes' => array( 'invalid-input-response' ),
			)
		);
		for ( $i = 1; $i <= 3; $i++ ) {
			[ $uuid, $token ] = $this->conversation( false );
			$this->assertSame( 'turnstile_required', $this->handoff( $uuid, $token )->as_error()->get_error_code() );
		}
		$this->assertCount( 1, $this->http, 'one probe, remembered for 60 s' );
		ob_start();
		Turnstile::notices();
		$this->assertSame( '', (string) ob_get_clean(), 'no outage, no notice' );
	}

	public function test_test_keys_gates_the_switch_and_never_passes_a_wrong_secret(): void {
		$this->settings(
			array(
				'turnstile_site_key' => self::TEST_SITE_KEY,
				'turnstile_secret'   => 'wrong-secret-0000',
			)
		);
		$blocked = $this->rest( 'POST', '/admin/settings', array( 'turnstile_enabled' => true ) );
		$this->assertSame( 400, $blocked->get_status(), 'untested keys' );
		$this->assertSame( 'turnstile_enabled', $blocked->as_error()->get_error_data()['field'] );

		$this->siteverify(
			array(
				'success'     => false,
				'error-codes' => array( 'invalid-input-secret' ),
			)
		);
		$bad = $this->rest( 'POST', '/admin/turnstile/test' )->get_data();
		$this->assertFalse( $bad['ok'] );
		$this->assertSame( array( 'invalid-input-secret' ), $bad['codes'] );
		$this->assertSame( 'sigma-key-test', $this->http[0]['args']['body']['response'], 'a dummy token' );
		$this->assertSame( 400, $this->rest( 'POST', '/admin/settings', array( 'turnstile_enabled' => true ) )->get_status() );

		remove_all_filters( 'pre_http_request' );
		$this->siteverify(
			array(
				'success'     => false,
				'error-codes' => array( 'invalid-input-response' ),
			)
		);
		$this->assertTrue( $this->rest( 'POST', '/admin/turnstile/test' )->get_data()['ok'], 'a good secret: only the dummy token is rejected' );
		$this->assertSame( 400, $this->rest( 'POST', '/admin/settings', array( 'turnstile_enabled' => true, 'turnstile_secret' => 'another-secret-1111' ) )->get_status(), 'new keys need a new test' );
		$this->assertSame( 200, $this->rest( 'POST', '/admin/settings', array( 'turnstile_enabled' => true ) )->get_status() );
		$this->assertTrue( Settings::turnstile_on() );

		$admin = $this->rest( 'GET', '/admin/settings' )->get_data()['settings'];
		$json  = (string) wp_json_encode( $this->rest( 'GET', '/admin/settings' )->get_data() );
		$this->assertStringNotContainsString( 'wrong-secret-0000', $json, 'the secret never comes back' );
		$this->assertArrayNotHasKey( 'turnstile_secret_enc', $admin );
		$this->assertSame(
			array(
				'set'    => true,
				'source' => 'settings',
				'last4'  => '0000',
			),
			$admin['turnstile_secret']
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, $this->rest( 'POST', '/admin/turnstile/test' )->get_status(), 'manage_options only' );
	}

	public function test_site_keys_are_0x_or_cloudflare_test_keys_and_the_field_is_named(): void {
		foreach ( array( '0x4AAAAAAABkMYinukE8nzYS', self::TEST_SITE_KEY, '2x00000000000000000000AB', '3x00000000000000000000FF' ) as $good ) {
			$this->assertTrue( Settings::valid_turnstile_site_key( $good ), $good );
		}
		foreach ( array( '4x00000000000000000000AA', '0x123', '0x' . str_repeat( 'a', 39 ), '0x4AAAAAAA BkMYinukE8nz', '<script>' ) as $bad ) {
			$this->assertFalse( Settings::valid_turnstile_site_key( $bad ), $bad );
		}
		$error = Settings::update( array( 'turnstile_site_key' => 'nope' ) );
		$this->assertWPError( $error );
		$this->assertSame( 'turnstile_site_key', $error->get_error_data()['field'] );
	}

	public function test_a_new_smtp_destination_never_reuses_the_stored_password(): void {
		$base = array(
			'mail_transport'  => 'smtp',
			'smtp_host'       => 'smtp.example.org',
			'smtp_port'       => 587,
			'smtp_encryption' => 'starttls',
			'smtp_auth'       => true,
			'smtp_username'   => 'help@example.org',
			'smtp_password'   => 'secret-smtp-pass',
			'mail_from_email' => 'help@example.org',
		);
		$this->settings( $base );
		$this->assertSame( 'secret-smtp-pass', Settings::smtp_password() );
		$this->settings( array( 'mail_from_name' => 'Help' ) );
		$this->assertSame( 'secret-smtp-pass', Settings::smtp_password(), 'an unrelated change keeps it' );

		foreach ( array( 'smtp_host' => 'smtp.attacker.example', 'smtp_port' => 2525, 'smtp_username' => 'other@example.org', 'smtp_encryption' => 'ssl' ) as $field => $value ) {
			$error = Settings::update( array( $field => $value ) );
			$this->assertWPError( $error, $field );
			$this->assertSame( 'The SMTP server or account changed: enter the password again.', $error->get_error_message(), $field );
			$this->assertSame( 'secret-smtp-pass', Settings::smtp_password(), $field . ': a refused save keeps the old settings' );
		}
		$this->settings(
			array(
				'smtp_host'     => 'smtp.other.example',
				'smtp_password' => 'new-pass',
			)
		);
		$this->assertSame( 'new-pass', Settings::smtp_password() );
	}

	public function test_smtp_auth_without_encryption_is_refused_off_this_machine(): void {
		$error = Settings::update(
			array(
				'mail_transport'  => 'smtp',
				'smtp_host'       => 'smtp.example.org',
				'smtp_port'       => 25,
				'smtp_encryption' => 'none',
				'smtp_auth'       => true,
				'smtp_username'   => 'help@example.org',
				'smtp_password'   => 'pw-123456',
				'mail_from_email' => 'help@example.org',
			)
		);
		$this->assertWPError( $error );
		$this->assertSame( 'smtp_encryption', $error->get_error_data()['field'] );
		$this->settings(
			array(
				'mail_transport'  => 'smtp',
				'smtp_host'       => '127.0.0.1',
				'smtp_port'       => 1025,
				'smtp_encryption' => 'none',
				'smtp_auth'       => true,
				'smtp_username'   => 'help@example.org',
				'smtp_password'   => 'pw-123456',
				'mail_from_email' => 'help@example.org',
			)
		);
		$this->assertNotNull( Smtp_Check::refused_target( 'smtp.example.org', array( '93.184.216.34' ), 587, 'none', true ) );
		$this->assertNull( Smtp_Check::refused_target( 'localhost', array( '127.0.0.1' ), 1025, 'none', true ) );
	}

	public function test_smtp_test_targets_are_public_mail_ports_unless_the_site_is_local(): void {
		$this->assertSame( 'local', wp_get_environment_type() );
		$this->assertNull( Smtp_Check::refused_target( 'smtp.example.org', array( '93.184.216.34' ), 587, 'starttls', true ) );
		$this->assertNull( Smtp_Check::refused_target( 'mailpit.test', array( '127.0.0.1' ), 1025, 'none', false ), 'a local site may test its mail catcher' );
		$this->assertStringContainsString( 'Port 8080', (string) Smtp_Check::refused_target( 'smtp.example.org', array( '93.184.216.34' ), 8080, 'starttls', true ) );

		add_filter( 'sigma_smtp_allow_private', '__return_false' ); // As on a production site.
		foreach ( array( '127.0.0.1', '10.0.0.5', '192.168.1.10', '169.254.169.254', '0.0.0.0', '::1', 'fd00::1' ) as $ip ) {
			$this->assertNotNull( Smtp_Check::refused_target( 'smtp.example.org', array( $ip ), 587, 'starttls', true ), $ip );
		}
		$this->assertNotNull( Smtp_Check::refused_target( 'smtp.example.org', array( '93.184.216.34', '10.0.0.5' ), 587, 'starttls', true ), 'one private address is enough' );
		$this->assertNull( Smtp_Check::refused_target( 'smtp.example.org', array( '93.184.216.34' ), 465, 'ssl', true ) );
	}

	public function test_the_corpus_is_built_as_a_logged_out_visitor(): void {
		$this->doc( 'Members guide', '<p>Everyone sees this part.</p>' );
		add_filter(
			'the_content',
			static fn( string $html ): string => $html . ( is_user_logged_in() ? '<p>MEMBERS-ONLY-PRICE-LIST</p>' : '' )
		);
		$admin = get_current_user_id();
		$this->settings( array( 'sources' => array( 'post' ) ) );
		$corpus = (string) wp_json_encode( Corpus::build() );
		$this->assertStringContainsString( 'Everyone sees this part.', $corpus );
		$this->assertStringNotContainsString( 'MEMBERS-ONLY-PRICE-LIST', $corpus, 'built as user 0' );
		$this->assertSame( $admin, get_current_user_id(), 'the admin is back afterwards' );
	}

	public function test_saving_settings_schedules_the_rebuild_instead_of_running_it(): void {
		wp_clear_scheduled_hook( Corpus::CRON_REBUILD );
		$response = $this->rest( 'POST', '/admin/settings', array( 'sources' => array( 'post', 'page' ) ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertNotFalse( wp_next_scheduled( Corpus::CRON_REBUILD ) );
		$rebuild = $this->rest( 'POST', '/admin/corpus/rebuild' );
		$this->assertTrue( $rebuild->get_data()['rebuilding'] );
	}

	public function test_only_viewable_post_types_can_be_sources(): void {
		register_post_type( 'da_internal_note', array( 'public' => false ) );
		self::factory()->post->create(
			array(
				'post_type'    => 'da_internal_note',
				'post_status'  => 'publish',
				'post_title'   => 'Internal note',
				'post_content' => 'INTERNAL-ONLY',
			)
		);
		$this->doc( 'Public doc', '<p>Public.</p>' );
		$this->settings( array( 'sources' => array( 'post', 'da_internal_note' ) ) );
		$this->assertSame( array( 'post' ), array_values( (array) Settings::get( 'sources' ) ) );
		$this->assertStringNotContainsString( 'INTERNAL-ONLY', (string) wp_json_encode( Corpus::build() ) );
		$types = array_column( (array) $this->rest( 'GET', '/admin/settings' )->get_data()['post_types'], 'name' );
		$this->assertNotContains( 'da_internal_note', $types );
		unregister_post_type( 'da_internal_note' );
	}

	public function test_a_key_that_cannot_be_decrypted_is_unreadable_not_set(): void {
		$stored                      = get_option( Settings::OPTION );
		$stored['anthropic_key_enc'] = base64_encode( random_bytes( 24 ) . 'not-a-box-under-these-keys' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$stored['anthropic_key_last4'] = 'AbCd';
		update_option( Settings::OPTION, $stored );
		Settings::flush();
		$status = Settings::key_status( 'anthropic' );
		$this->assertFalse( $status['set'] );
		$this->assertSame( 'unreadable', $status['source'] );
	}

	public function test_secrets_are_encrypted_with_the_wp_salt_key_and_migrate_017_moves_ip_source(): void {
		$this->settings( array( 'smtp_password' => 'salted-pass', 'mail_transport' => 'php' ) );
		$enc = (string) Settings::get( 'smtp_password_enc' );
		$this->assertStringNotContainsString( 'salted-pass', $enc );
		$this->assertSame( 'salted-pass', Settings::decrypt( $enc ) );

		$stored              = get_option( Settings::OPTION );
		$stored['ip_source'] = 'remote_addr';
		update_option( Settings::OPTION, $stored );
		Settings::flush();
		update_option( Plugin::VERSION_OPTION, '0.1.5' ); // Any version before 0.1.7.
		Plugin::maybe_version_change();
		$this->assertSame( 'auto', Settings::get( 'ip_source' ) );
		$this->assertSame( 'salted-pass', Settings::smtp_password(), 'rekeyed and still readable' );
		$this->assertSame( P21_SIGMA_VERSION, get_option( Plugin::VERSION_OPTION ) );

		$this->settings( array( 'ip_source' => 'remote_addr' ) );
		Plugin::maybe_version_change();
		$this->assertSame( 'remote_addr', Settings::get( 'ip_source' ), 'a choice made after the update stays' );
	}

	public function test_the_exporter_returns_every_message_past_500(): void {
		$repo             = new Repository();
		[ $uuid, $token ] = $this->conversation();
		$this->assertSame( 200, $this->handoff( $uuid, $token, array( 'email' => 'big@example.test' ) )->get_status() );
		$conversation = $repo->find_by_uuid( $uuid );
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . \TwentyOnePress\Sigma\Schema::messages() . ' WHERE conversation_id = %d', (int) $conversation['id'] ) ); // phpcs:ignore WordPress.DB
		for ( $i = 1; $i <= 1203; $i++ ) {
			$repo->add_message( (int) $conversation['id'], 0 === $i % 2 ? 'bot' : 'visitor', 'message-' . $i . '-end' );
		}
		$export     = Privacy::export( 'big@example.test' );
		$transcript = (string) end( $export['data'][0]['data'] )['value'];
		$this->assertSame( 1203, substr_count( $transcript, "\n" ) + 1 );
		$this->assertStringContainsString( 'message-1-end', $transcript );
		$this->assertStringContainsString( 'message-1203-end', $transcript );
		$this->assertTrue( $export['done'] );
	}

	public function test_uninstall_removes_secrets_even_when_the_data_stays(): void {
		$this->settings(
			array(
				'delete_on_uninstall' => false,
				'anthropic_key'       => 'sk-ant-test-0000000000000000000000000000000000000000-XyZ1',
				'smtp_password'       => 'smtp-secret',
				'turnstile_site_key'  => self::TEST_SITE_KEY,
				'turnstile_secret'    => self::TEST_SECRET,
				'bot_name'            => 'Remy',
			)
		);
		$this->assertTrue( Settings::key_status( 'anthropic' )['set'] );
		[ $uuid ] = $this->conversation();
		try {
			if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
				define( 'WP_UNINSTALL_PLUGIN', 'sigma/sigma.php' );
			}
			require dirname( __DIR__, 5 ) . '/uninstall.php';
			$stored = (array) get_option( Settings::OPTION );
			foreach ( array( 'anthropic_key_enc', 'anthropic_key_last4', 'openai_key_enc', 'smtp_password_enc', 'turnstile_secret_enc', 'turnstile_last4' ) as $secret ) {
				$this->assertArrayNotHasKey( $secret, $stored, $secret );
			}
			$this->assertSame( 'Remy', $stored['bot_name'], 'other settings stay with keep-data' );
			$this->assertNotNull( ( new Repository() )->find_by_uuid( $uuid ), 'chats stay with keep-data' );
			$this->assertFalse( wp_next_scheduled( 'delta_assistant_purge' ) );
		} finally {
			get_role( 'administrator' )->add_cap( Plugin::CAP_VIEW ); // Roles live in memory past the rollback.
		}
	}
}
