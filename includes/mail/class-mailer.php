<?php
/**
 * Handoff email to support, Reply-To the visitor (§3, §10). No visitor confirmation email (owner, v1.6): the chat confirms.
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Mail;

use TwentyOnePress\Sigma\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Plain-text mail through Transport (wp_mail, plus built-in SMTP when chosen, §10a). Support
 * answers from their mail client; Reply-To takes the answer to the visitor.
 */
final class Mailer {

	/**
	 * Email the conversation to support.
	 *
	 * @param array<string, mixed>       $conversation Conversation row.
	 * @param string                     $name         Visitor name.
	 * @param string                     $email        Visitor email (validated).
	 * @param string                     $text         The visitor's message to support.
	 * @param list<array<string, mixed>> $messages     The conversation so far, oldest first.
	 * @return bool Whether the support email was accepted by wp_mail.
	 */
	public function send_handoff( array $conversation, string $name, string $email, string $text, array $messages ): bool {
		$name  = self::clean_name( $name );
		$brand = self::clean_line( Settings::brand_name() );

		/* translators: 1: brand, 2: visitor name. */
		$subject = self::clean_line( sprintf( __( '[%1$s] Help request from %2$s', 'sigma' ), $brand, $name ) );
		$sent    = Transport::send( Settings::support_email(), $subject, self::support_body( $conversation, $name, $email, $text, $messages ), self::support_headers( $name, $email ) );

		return (bool) $sent;
	}

	/**
	 * Send test email (§10a check 3): to the support address, through exactly the path a handoff
	 * takes (same transport, same headers).
	 */
	public function send_test(): bool {
		$brand = self::clean_line( Settings::brand_name() );
		/* translators: %s: brand. */
		$subject = self::clean_line( sprintf( __( '[%s] Test email from Sigma', 'sigma' ), $brand ) );
		$body    = __( 'This is a test of the email path that help requests use. If you can read it, handoffs will reach this address.', 'sigma' );
		return Transport::send( Settings::support_email(), $subject, $body, self::support_headers( 'Sigma', Settings::support_email() ) );
	}

	/**
	 * Headers of the support email: Reply-To is the visitor. Pure apart from is_email.
	 *
	 * @param string $name  Visitor name.
	 * @param string $email Visitor email.
	 * @return list<string>
	 */
	public static function support_headers( string $name, string $email ): array {
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		$email   = self::clean_line( $email );
		if ( is_email( $email ) ) {
			$name = self::clean_name( $name );
			// No quotes: wp_mail() parses "Name <email>" and PHPMailer quotes the name itself.
			$headers[] = '' !== $name ? sprintf( 'Reply-To: %s <%s>', $name, $email ) : sprintf( 'Reply-To: %s', $email );
		}
		return $headers;
	}

	/**
	 * A visitor name safe for a header: no line breaks, quotes, angle brackets or commas.
	 *
	 * @param string $name Name.
	 */
	public static function clean_name( string $name ): string {
		$name = self::clean_line( $name );
		$name = str_replace( array( '"', '<', '>', ',', ';', '\\' ), '', $name );
		return mb_substr( trim( $name ), 0, 100 );
	}

	/**
	 * One line: line breaks and other control characters removed (header injection, §10).
	 *
	 * @param string $text Text.
	 */
	public static function clean_line( string $text ): string {
		return trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $text ) );
	}

	/**
	 * Body of the support email.
	 *
	 * @param array<string, mixed>       $conversation Conversation row.
	 * @param string                     $name         Name.
	 * @param string                     $email        Email.
	 * @param string                     $text         Message.
	 * @param list<array<string, mixed>> $messages     Transcript.
	 */
	private static function support_body( array $conversation, string $name, string $email, string $text, array $messages ): string {
		$lines   = array();
		$lines[] = sprintf( '%s <%s>', $name, $email );
		$lines[] = '';
		$lines[] = $text;
		$lines[] = '';
		$lines[] = '--';
		/* translators: %s: page URL. */
		$lines[] = sprintf( __( 'Page: %s', 'sigma' ), (string) $conversation['page_url'] );
		/* translators: %s: wp-admin URL of the conversation. */
		$lines[] = sprintf( __( 'Conversation: %s', 'sigma' ), admin_url( 'admin.php?page=sigma-conversations#/' . rawurlencode( (string) $conversation['uuid'] ) ) );
		$lines[] = __( 'Reply to this email to answer the visitor.', 'sigma' );

		$transcript = array();
		foreach ( $messages as $message ) {
			// System notes are ours; the handoff message itself is already at the top.
			if ( 'system' === $message['sender'] || ! empty( $message['meta']['handoff'] ) ) {
				continue;
			}
			$who          = 'visitor' === $message['sender'] ? __( 'Visitor', 'sigma' ) : ( 'ai' === $message['sender'] ? __( 'AI', 'sigma' ) : __( 'Support', 'sigma' ) );
			$transcript[] = sprintf( '[%s UTC] %s:', substr( (string) $message['created_at'], 0, 16 ), $who );
			$transcript[] = (string) $message['body'];
			$transcript[] = '';
		}
		if ( $transcript ) {
			$lines[] = '';
			$lines[] = __( 'Chat before the handoff:', 'sigma' );
			$lines[] = '';
			$lines   = array_merge( $lines, $transcript );
		}
		return implode( "\n", $lines );
	}
}
