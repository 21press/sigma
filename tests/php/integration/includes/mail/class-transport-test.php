<?php
/**
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Tests\Integration;

use TwentyOnePress\Sigma\Mail\Mailer;
use TwentyOnePress\Sigma\Mail\Transport;
use TwentyOnePress\Sigma\Tests\Support\Integration_Test_Case;

/**
 * Built-in SMTP (§10a): only the plugin's own emails use it; status and notices.
 *
 * @covers \TwentyOnePress\Sigma\Mail\Transport
 * @covers \TwentyOnePress\Sigma\Mail\Mailer::send_test
 */
final class Transport_Test extends Integration_Test_Case {

	public function set_up(): void {
		parent::set_up();
		$this->settings(
			array(
				'mail_transport'  => 'smtp',
				'smtp_host'       => 'smtp.example.org',
				'smtp_port'       => 587,
				'smtp_encryption' => 'starttls',
				'smtp_auth'       => true,
				'smtp_username'   => 'help@example.org',
				'smtp_password'   => 'secret-smtp-pass',
				'mail_from_email' => 'help@example.org',
				'mail_from_name'  => 'Delta help',
			)
		);
	}

	public function test_another_plugins_wp_mail_is_left_untouched(): void {
		wp_mail( 'someone@example.org', 'Order shipped', 'From WooCommerce, say.' );
		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertNotSame( 'smtp', $mailer->Mailer, 'a non-plugin email does not go through our SMTP' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->assertNotSame( 'smtp.example.org', $mailer->Host ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->assertNotSame( 'help@example.org', $mailer->From ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	public function test_the_plugins_own_email_goes_through_smtp_with_its_from(): void {
		$seen = array();
		add_action(
			'phpmailer_init',
			static function ( $m ) use ( &$seen ): void {
				// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's API.
				$seen = array(
					'mailer'   => $m->Mailer,
					'host'     => $m->Host,
					'port'     => $m->Port,
					'secure'   => $m->SMTPSecure,
					'password' => $m->Password,
					'from'     => $m->From,
					'verify'   => $m->SMTPOptions['ssl']['verify_peer'] ?? null,
				);
				// phpcs:enable
			},
			100
		);
		$this->assertTrue( ( new Mailer() )->send_test() );
		$this->assertSame(
			array(
				'mailer'   => 'smtp',
				'host'     => 'smtp.example.org',
				'port'     => 587,
				'secure'   => 'tls',
				'password' => 'secret-smtp-pass',
				'from'     => 'help@example.org',
				'verify'   => true,
			),
			$seen,
			'at send time: SMTP, STARTTLS, our From, certificates verified'
		);
		$this->assertSame( 'support@example.org', tests_retrieve_phpmailer_instance()->get_sent( 0 )->to[0][0], 'the test goes to the support address' );
		$this->assertNotSame( '', Transport::status()['last_success_at'] );

		// The flag is down again and our SMTP fields are cleared: the next email inherits nothing.
		$this->assertSame( '', $GLOBALS['phpmailer']->Password ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->assertNotSame( 'smtp.example.org', $GLOBALS['phpmailer']->Host ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		reset_phpmailer_instance();
		wp_mail( 'someone@example.org', 'Hi', 'Body' );
		$this->assertNotSame( 'smtp', tests_retrieve_phpmailer_instance()->Mailer ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * A real PHPMailer against a fake server that accepts the connection and never says a word.
	 *
	 * @group slow
	 */
	public function test_a_server_that_accepts_and_stalls_fails_the_handoff_within_45_seconds(): void {
		$server = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
		$this->assertNotFalse( $server, 'a local listening socket' );
		$port = (int) substr( (string) stream_socket_get_name( $server, false ), strrpos( (string) stream_socket_get_name( $server, false ), ':' ) + 1 );
		$this->settings(
			array(
				'smtp_host'       => '127.0.0.1',
				'smtp_port'       => $port,
				'smtp_encryption' => 'none',
				'smtp_auth'       => false,
			)
		);
		// The test library's mock PHPMailer never connects; use the real one for this test.
		$GLOBALS['phpmailer'] = new \PHPMailer\PHPMailer\PHPMailer( true );

		wp_set_current_user( 0 );
		[ $uuid, $token ] = $this->conversation();
		$started          = microtime( true );
		$response         = $this->rest( 'POST', "/conversations/{$uuid}/handoff", array( 'name' => 'Ann', 'email' => 'ann@example.test', 'text' => 'Stalled server' ), array( 'X-DA-Token' => $token ) );
		$took             = microtime( true ) - $started;
		fclose( $server ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$this->assertLessThan( 45, $took, 'the handoff returns before the widget gives up' );
		$this->assertGreaterThanOrEqual( Transport::REPLY_TIMEOUT - 1, $took, 'it waited for the reply timeout, not the 300 s default' );
		$this->assertSame( 'emailed', $response->get_data()['status'], 'the visitor still gets "we got your message"' );
		$this->assertSame( 'failed', ( new \TwentyOnePress\Sigma\Conversations\Repository() )->find_by_uuid( $uuid )['email_status'] );
		$this->assertSame( 300, $GLOBALS['phpmailer']->getSMTPInstance()->Timelimit, 'our reply timeout is not left on the shared mailer' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		fwrite( STDERR, sprintf( "\n[stalled-server handoff returned in %.1f s]\n", $took ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	}

	public function test_php_transport_leaves_our_email_to_the_site(): void {
		$this->settings( array( 'mail_transport' => 'php' ) );
		( new Mailer() )->send_test();
		$this->assertNotSame( 'smtp', tests_retrieve_phpmailer_instance()->Mailer ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	public function test_a_failure_reported_through_wp_mail_failed_is_recorded_redacted(): void {
		add_filter(
			'pre_wp_mail',
			static function () {
				do_action( 'wp_mail_failed', new \WP_Error( 'wp_mail_failed', 'SMTP Error: Could not authenticate. Password secret-smtp-pass refused.' ) );
				return false;
			}
		);
		$this->assertFalse( ( new Mailer() )->send_test() );
		$status = Transport::status();
		$this->assertSame( 1, $status['consecutive_failures'] );
		$this->assertStringContainsString( 'Could not authenticate', $status['last_error'] );
		$this->assertStringNotContainsString( 'secret-smtp-pass', $status['last_error'] );
	}

	public function test_three_failures_in_a_row_raise_a_notice_and_errors_are_redacted(): void {
		add_action(
			'phpmailer_init',
			static function (): void {
				throw new \PHPMailer\PHPMailer\Exception( 'SMTP Error: Could not authenticate with secret-smtp-pass' );
			},
			100
		);
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertFalse( ( new Mailer() )->send_test() );
		}
		$status = Transport::status();
		$this->assertSame( 3, $status['consecutive_failures'] );
		$this->assertStringNotContainsString( 'secret-smtp-pass', $status['last_error'] );
		$this->assertStringContainsString( '[redacted]', $status['last_error'] );
		ob_start();
		Transport::notices();
		$this->assertStringContainsString( 'the last 3 emails failed', (string) ob_get_clean() );
	}

	public function test_the_password_never_comes_back_from_rest(): void {
		$data = $this->rest( 'GET', '/admin/settings' )->get_data();
		$json = (string) wp_json_encode( $data );
		$this->assertStringNotContainsString( 'secret-smtp-pass', $json );
		$this->assertStringNotContainsString( 'smtp_password_enc', $json );
		$this->assertSame(
			array(
				'set'    => true,
				'source' => 'settings',
			),
			$data['settings']['smtp_password']
		);
		$this->assertArrayHasKey( 'this', $data['usage'] );
		$this->assertArrayHasKey( 'last', $data['usage'], 'usage for this and last month' );
	}

	public function test_the_check_route_reports_steps_and_needs_manage_options(): void {
		$this->settings( array( 'smtp_host' => '127.0.0.1', 'smtp_port' => 1, 'smtp_password' => 'secret-smtp-pass' ) ); // §10b: a new host needs the password again.
		$data = $this->rest( 'POST', '/admin/mail/check' )->get_data();
		$this->assertFalse( $data['ok'] );
		$this->assertSame( array( 'dns', 'tcp', 'greeting', 'tls', 'auth' ), array_column( $data['steps'], 'step' ) );
		$this->assertFalse( $data['steps'][1]['ok'], 'nothing listens on port 1' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, $this->rest( 'POST', '/admin/mail/check' )->get_status() );
	}
}
