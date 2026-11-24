<?php
/**
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Tests\Integration;

use TwentyOnePress\Sigma\Conversations\Repository;
use TwentyOnePress\Sigma\Tests\Support\Integration_Test_Case;

/**
 * The email handoff (§3, R1): support gets the chat with Reply-To the visitor; no email to the
 * visitor (v1.6); bots filling the honeypot get nothing sent; a failed email is kept and retried.
 *
 * @covers \TwentyOnePress\Sigma\Rest\Visitor_Controller::handoff
 * @covers \TwentyOnePress\Sigma\Mail\Mailer
 */
final class Handoff_Test extends Integration_Test_Case {

	public function test_support_gets_the_chat_and_the_visitor_gets_no_email(): void {
		wp_set_current_user( 0 );
		[ $uuid, $token ] = $this->conversation();
		$response         = $this->rest( 'POST', "/conversations/{$uuid}/handoff", array( 'name' => "Ann\r\nBcc: x@evil.net", 'email' => 'ann@example.test', 'text' => 'Please help with my licence.', 'website' => '' ), array( 'X-DA-Token' => $token ) );
		$this->assertSame( array( 'status' => 'emailed', 'email_status' => 'sent' ), $response->get_data() );

		$mailer  = tests_retrieve_phpmailer_instance();
		$support = $mailer->get_sent( 0 );
		$this->assertSame( 'support@example.org', $support->to[0][0] );
		$this->assertMatchesRegularExpression( '/^Reply-To: "?Ann Bcc: x@evil\.net"? <ann@example\.test>\r?$/m', $support->header, 'Reply-To is the visitor, on one line, the name not double-quoted' );
		$this->assertStringNotContainsString( "\nBcc:", $support->header, 'no header was injected' );
		$this->assertStringContainsString( 'Please help with my licence.', $support->body );
		$this->assertFalse( $mailer->get_sent( 1 ), 'no confirmation email to the visitor (owner, v1.6)' );

		$row = ( new Repository() )->find_by_uuid( $uuid );
		$this->assertSame( 'emailed', $row['status'] );
		$this->assertSame( 'human', $row['mode'] );
		$this->assertSame( 'ann@example.test', $row['visitor_email'] );
		$this->assertNotEmpty( $row['emailed_at'] );
		$this->assertSame( 'sent', $row['email_status'] );
	}

	public function test_the_honeypot_answers_normally_and_sends_nothing(): void {
		[ $uuid, $token ] = $this->conversation();
		$response         = $this->rest( 'POST', "/conversations/{$uuid}/handoff", array( 'name' => 'Bot', 'email' => 'bot@example.test', 'text' => 'Buy now', 'website' => 'https://spam.example' ), array( 'X-DA-Token' => $token ) );
		$this->assertSame( array( 'status' => 'emailed' ), $response->get_data() );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent( 0 ) );
		$this->assertSame( 'open', ( new Repository() )->find_by_uuid( $uuid )['status'] );
	}

	public function test_fields_are_checked_and_named(): void {
		[ $uuid, $token ] = $this->conversation();
		foreach ( array(
			array( array( 'name' => '', 'email' => 'a@example.test', 'text' => 'x' ), 'name' ),
			array( array( 'name' => 'A', 'email' => 'nope', 'text' => 'x' ), 'email' ),
			array( array( 'name' => 'A', 'email' => 'a@example.test', 'text' => ' ' ), 'text' ),
		) as [ $body, $field ] ) {
			$data = $this->rest( 'POST', "/conversations/{$uuid}/handoff", $body, array( 'X-DA-Token' => $token ) )->get_data();
			$this->assertSame( $field, $data['data']['field'] );
		}
	}

	public function test_a_failed_email_is_kept_marked_and_can_be_retried(): void {
		add_filter( 'pre_wp_mail', '__return_false' );
		[ $uuid, $token ] = $this->conversation();
		$response         = $this->rest( 'POST', "/conversations/{$uuid}/handoff", array( 'name' => 'Ann', 'email' => 'ann@example.test', 'text' => 'Please help' ), array( 'X-DA-Token' => $token ) );
		$this->assertSame( array( 'status' => 'emailed', 'email_status' => 'failed' ), $response->get_data(), 'the visitor still sees "we got your message"' );
		$row = ( new Repository() )->find_by_uuid( $uuid );
		$this->assertSame( 'failed', $row['email_status'] );
		$this->assertSame( 1, ( new Repository() )->count_email_failed() );

		ob_start();
		\TwentyOnePress\Sigma\Mail\Transport::notices();
		$this->assertStringContainsString( 'handoff email was not sent', (string) ob_get_clean(), 'an admin notice' );

		remove_filter( 'pre_wp_mail', '__return_false' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$retry = $this->rest( 'POST', "/admin/conversations/{$uuid}/retry-email" )->get_data();
		$this->assertTrue( $retry['sent'] );
		$this->assertSame( 'sent', ( new Repository() )->find_by_uuid( $uuid )['email_status'] );
		$this->assertStringContainsString( 'Please help', tests_retrieve_phpmailer_instance()->get_sent( 0 )->body, 'the retry carries the handoff message' );
	}

	public function test_a_closed_conversation_cannot_hand_off(): void {
		[ $uuid, $token ] = $this->conversation();
		$repo             = new Repository();
		$repo->update( (int) $repo->find_by_uuid( $uuid )['id'], array( 'status' => 'closed' ) );
		$this->assertSame( 'closed', $this->rest( 'POST', "/conversations/{$uuid}/handoff", array( 'name' => 'A', 'email' => 'a@example.test', 'text' => 'x' ), array( 'X-DA-Token' => $token ) )->get_data()['code'] );
	}

	/**
	 * Handoff body with an idempotency key.
	 *
	 * @param string $key Key.
	 */
	private static function body( string $key ): array {
		return array(
			'name'            => 'Ann',
			'email'           => 'ann@example.test',
			'text'            => 'Please help',
			'idempotency_key' => $key,
		);
	}

	/**
	 * How many emails wp_mail() tried to send.
	 */
	private static function sends(): int {
		return (int) did_action( 'phpmailer_init' );
	}

	public function test_the_same_key_twice_sends_one_email_and_returns_the_stored_result(): void {
		[ $uuid, $token ] = $this->conversation();
		$key              = wp_generate_uuid4();
		$before           = self::sends();
		$first            = $this->rest( 'POST', "/conversations/{$uuid}/handoff", self::body( $key ), array( 'X-DA-Token' => $token ) );
		$second           = $this->rest( 'POST', "/conversations/{$uuid}/handoff", self::body( $key ), array( 'X-DA-Token' => $token ) );
		$this->assertSame( array( 'status' => 'emailed', 'email_status' => 'sent' ), $first->get_data() );
		$this->assertSame( 200, $second->get_status() );
		$this->assertSame( array( 'status' => 'emailed', 'email_status' => 'sent' ), $second->get_data(), 'the stored result' );
		$this->assertSame( 1, self::sends() - $before, 'one email' );
		$this->assertCount( 1, array_filter( ( new Repository() )->messages( (int) ( new Repository() )->find_by_uuid( $uuid )['id'] ), static fn( array $m ): bool => ! empty( $m['meta']['handoff'] ) ), 'the message is stored once' );
	}

	public function test_a_new_key_after_a_handoff_is_409_with_the_stored_result(): void {
		[ $uuid, $token ] = $this->conversation();
		$this->rest( 'POST', "/conversations/{$uuid}/handoff", self::body( wp_generate_uuid4() ), array( 'X-DA-Token' => $token ) );
		$before   = self::sends();
		$response = $this->rest( 'POST', "/conversations/{$uuid}/handoff", self::body( wp_generate_uuid4() ), array( 'X-DA-Token' => $token ) );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'already_handed_off', $response->get_data()['code'] );
		$this->assertSame( array( 'status' => 'emailed', 'email_status' => 'sent' ), $response->get_data()['data']['result'] );
		$this->assertSame( 0, self::sends() - $before, 'nothing sent' );
	}

	public function test_a_request_without_a_key_still_sends_once(): void {
		[ $uuid, $token ] = $this->conversation();
		$body             = self::body( '' );
		$this->assertSame( 200, $this->rest( 'POST', "/conversations/{$uuid}/handoff", $body, array( 'X-DA-Token' => $token ) )->get_status() );
		$this->assertSame( 409, $this->rest( 'POST', "/conversations/{$uuid}/handoff", $body, array( 'X-DA-Token' => $token ) )->get_status(), 'an older widget cannot send twice either' );
		$this->assertSame( 'idempotency_key', $this->rest( 'POST', "/conversations/{$uuid}/handoff", self::body( 'not-a-uuid' ), array( 'X-DA-Token' => $token ) )->get_data()['data']['field'] );
	}

	public function test_a_parallel_request_with_the_same_key_while_the_first_is_sending_sends_nothing(): void {
		[ $uuid, $token ] = $this->conversation();
		$key              = wp_generate_uuid4();
		$overlap          = null;
		$test             = $this;
		// While the first request is inside wp_mail() (claimed, not finished), the second arrives.
		add_action(
			'phpmailer_init',
			static function () use ( &$overlap, $test, $uuid, $token, $key ): void {
				if ( null === $overlap ) {
					$overlap = false;
					$overlap = $test->rest_public( 'POST', "/conversations/{$uuid}/handoff", self::body( $key ), array( 'X-DA-Token' => $token ) );
				}
			}
		);
		$before = self::sends();
		$first  = $this->rest( 'POST', "/conversations/{$uuid}/handoff", self::body( $key ), array( 'X-DA-Token' => $token ) );
		$this->assertInstanceOf( \WP_REST_Response::class, $overlap );
		$this->assertSame( array( 'status' => 'emailed', 'email_status' => 'sending' ), $overlap->get_data(), 'the overlapping request sees the claim and sends nothing' );
		$this->assertSame( array( 'status' => 'emailed', 'email_status' => 'sent' ), $first->get_data() );
		$this->assertSame( 1, self::sends() - $before, 'one email for two requests' );
	}

	public function test_a_send_that_died_mid_way_turns_failed_after_two_minutes(): void {
		global $wpdb;
		[ $uuid, $token ] = $this->conversation();
		$key              = wp_generate_uuid4();
		$this->rest( 'POST', "/conversations/{$uuid}/handoff", self::body( $key ), array( 'X-DA-Token' => $token ) );
		// As a PHP fatal inside wp_mail() would leave it.
		$wpdb->update( \TwentyOnePress\Sigma\Schema::conversations(), array( 'email_status' => 'sending', 'handoff_at' => gmdate( 'Y-m-d H:i:s', time() - 3 * MINUTE_IN_SECONDS ) ), array( 'uuid' => $uuid ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( array( 'status' => 'emailed', 'email_status' => 'failed' ), $this->rest( 'POST', "/conversations/{$uuid}/handoff", self::body( $key ), array( 'X-DA-Token' => $token ) )->get_data() );
		$this->assertSame( 1, ( new Repository() )->count_email_failed(), 'visible to Retry send and the notice' );
	}

	public function test_a_fresh_sending_is_left_alone(): void {
		global $wpdb;
		[ $uuid ] = $this->conversation();
		$wpdb->update( \TwentyOnePress\Sigma\Schema::conversations(), array( 'email_status' => 'sending', 'handoff_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'uuid' => $uuid ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( 0, ( new Repository() )->fail_stale_sending() );
	}

	public function test_a_handoff_from_before_the_key_existed_cannot_hand_off_again(): void {
		global $wpdb;
		[ $uuid, $token ] = $this->conversation();
		$wpdb->update( \TwentyOnePress\Sigma\Schema::conversations(), array( 'status' => 'emailed', 'email_status' => 'sent', 'handoff_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'uuid' => $uuid ) ); // phpcs:ignore WordPress.DB
		$response = $this->rest( 'POST', "/conversations/{$uuid}/handoff", self::body( wp_generate_uuid4() ), array( 'X-DA-Token' => $token ) );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'sent', $response->get_data()['data']['result']['email_status'] );
	}

	public function test_only_one_claim_wins(): void {
		[ $uuid ] = $this->conversation();
		$repo     = new Repository();
		$id       = (int) $repo->find_by_uuid( $uuid )['id'];
		$this->assertTrue( $repo->claim_handoff( $id, wp_generate_uuid4(), array( 'email_status' => 'sending' ) ) );
		$this->assertFalse( $repo->claim_handoff( $id, wp_generate_uuid4(), array( 'email_status' => 'sending' ) ), 'UPDATE … WHERE handoff_key IS NULL changes no row the second time' );
	}

	/**
	 * @group slow
	 */
	public function test_a_stalled_smtp_server_then_a_retry_with_the_same_key_tries_one_email_in_total(): void {
		$server = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
		$name   = (string) stream_socket_get_name( $server, false );
		$this->settings(
			array(
				'mail_transport'  => 'smtp',
				'smtp_host'       => '127.0.0.1',
				'smtp_port'       => (int) substr( $name, strrpos( $name, ':' ) + 1 ),
				'smtp_encryption' => 'none',
				'smtp_auth'       => false,
				'mail_from_email' => 'help@example.org',
			)
		);
		$GLOBALS['phpmailer'] = new \PHPMailer\PHPMailer\PHPMailer( true ); // The real mailer: the mock never connects.

		[ $uuid, $token ] = $this->conversation();
		$key              = wp_generate_uuid4();
		$before           = self::sends();
		$first            = $this->rest( 'POST', "/conversations/{$uuid}/handoff", self::body( $key ), array( 'X-DA-Token' => $token ) );
		$retry            = $this->rest( 'POST', "/conversations/{$uuid}/handoff", self::body( $key ), array( 'X-DA-Token' => $token ) );
		fclose( $server ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$this->assertSame( array( 'status' => 'emailed', 'email_status' => 'failed' ), $first->get_data() );
		$this->assertSame( array( 'status' => 'emailed', 'email_status' => 'failed' ), $retry->get_data(), 'the retry gets the stored result' );
		$this->assertSame( 1, self::sends() - $before, 'one send attempt in total; the failed one waits for Retry send in wp-admin' );
	}

	public function test_the_human_path_can_be_switched_off(): void {
		$this->settings( array( 'human_enabled' => false ) );
		[ $uuid, $token ] = $this->conversation();
		$this->assertSame( 'human_unavailable', $this->rest( 'POST', "/conversations/{$uuid}/handoff", array( 'name' => 'A', 'email' => 'a@example.test', 'text' => 'x' ), array( 'X-DA-Token' => $token ) )->get_data()['code'] );
	}
}
