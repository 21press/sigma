<?php
/**
 * How the plugin's own emails leave the site: PHP mail or built-in SMTP (§10a, plan v1.6).
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Mail;

use PHPMailer\PHPMailer\PHPMailer;
use TwentyOnePress\Sigma\Conversations\Repository;
use TwentyOnePress\Sigma\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * SMTP applies only to this plugin's emails: send() raises a flag around its own wp_mail() call and
 * the phpmailer_init hook acts only while it is up. Every other email on the site goes exactly as
 * before (WP Mail SMTP, FluentSMTP and host mailers keep working). Rejected: site-wide SMTP.
 */
final class Transport {

	/** Last send status (autoload no). */
	public const STATUS_OPTION = 'delta_assistant_mail_status';

	/** Seconds to connect (PHPMailer's Timeout). */
	public const CONNECT_TIMEOUT = 10;

	/**
	 * Seconds to wait for each server reply (the SMTP instance's Timelimit; PHPMailer's default is
	 * 300). A server that accepts and then stalls must not hold a handoff request, and a PHP worker,
	 * past the widget's 45 s: with 15 s the send fails in about 15 s (30 s around STARTTLS, which
	 * PHPMailer doubles) and the conversation is marked for Retry.
	 */
	public const REPLY_TIMEOUT = 15;

	/** Failures in a row before the admin notice (§10a). */
	public const NOTICE_AFTER = 3;

	/**
	 * Whether the plugin's own wp_mail() call is running.
	 *
	 * @var bool
	 */
	private static bool $active = false;

	/**
	 * Hooks.
	 */
	public static function hooks(): void {
		add_action( 'phpmailer_init', array( self::class, 'configure' ), 99 );
		add_action( 'wp_mail_failed', array( self::class, 'failed' ) );
		add_action( 'admin_notices', array( self::class, 'notices' ) );
	}

	/**
	 * Send one of the plugin's emails.
	 *
	 * @param string   $to      Recipient.
	 * @param string   $subject Subject (one line).
	 * @param string   $body    Plain text.
	 * @param string[] $headers Headers.
	 */
	public static function send( string $to, string $subject, string $body, array $headers ): bool {
		self::$active = true;
		$error        = null;
		$catch        = static function ( $wp_error ) use ( &$error ): void {
			$error = $wp_error;
		};
		add_action( 'wp_mail_failed', $catch, 1 );
		try {
			$sent = (bool) wp_mail( $to, $subject, $body, $headers );
		} catch ( \Throwable $e ) {
			// A mailer hook that throws (ours or another plugin's) must not break a handoff.
			$sent = false;
			self::record( false, $e->getMessage() );
			$error = true;
		} finally {
			remove_action( 'wp_mail_failed', $catch, 1 );
			self::$active = false;
			self::reset();
		}
		if ( $sent ) {
			self::record( true, '' );
		} elseif ( null === $error ) {
			// Some mailers return false without firing wp_mail_failed.
			self::record( false, __( 'wp_mail() returned false without an error message.', 'sigma' ) );
		}
		return $sent;
	}

	/**
	 * Point PHPMailer at the SMTP server, for the plugin's own email only.
	 *
	 * @param PHPMailer $mailer PHPMailer.
	 */
	public static function configure( $mailer ): void {
		if ( ! self::$active || 'smtp' !== Settings::get( 'mail_transport' ) || ! $mailer instanceof PHPMailer ) {
			return;
		}
		require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php'; // For getSMTPInstance(); wp_mail() loads it only when it creates the mailer.
		$mailer->isSMTP();
		$mailer->Host                         = (string) Settings::get( 'smtp_host' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's API.
		$mailer->Port                         = (int) Settings::get( 'smtp_port' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$mailer->Timeout                      = self::CONNECT_TIMEOUT; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$mailer->getSMTPInstance()->Timelimit = self::REPLY_TIMEOUT; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$mailer->SMTPAuth                     = (bool) Settings::get( 'smtp_auth' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( $mailer->SMTPAuth ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$mailer->Username = (string) Settings::get( 'smtp_username' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$mailer->Password = Settings::smtp_password(); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}
		switch ( (string) Settings::get( 'smtp_encryption' ) ) {
			case 'ssl':
				$mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				break;
			case 'starttls':
				$mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				break;
			default:
				$mailer->SMTPSecure  = ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$mailer->SMTPAutoTLS = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}
		$mailer->SMTPOptions = array( 'ssl' => self::ssl_options() ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$from                = (string) Settings::get( 'mail_from_email' );
		if ( '' !== $from ) {
			$mailer->setFrom( $from, (string) Settings::get( 'mail_from_name' ), false );
		}
	}

	/**
	 * Clear our SMTP settings from the shared PHPMailer after our send, so a later email on the site
	 * (whose own phpmailer_init may switch to SMTP) never inherits our host or credentials.
	 */
	private static function reset(): void {
		global $phpmailer;
		if ( ! $phpmailer instanceof PHPMailer || 'smtp' !== Settings::get( 'mail_transport' ) ) {
			return;
		}
		require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
		$phpmailer->isMail();
		$phpmailer->Host                         = 'localhost'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's API; its defaults.
		$phpmailer->Port                         = 25; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$phpmailer->SMTPAuth                     = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$phpmailer->Username                     = ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$phpmailer->Password                     = ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$phpmailer->SMTPSecure                   = ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$phpmailer->SMTPAutoTLS                  = true; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$phpmailer->SMTPOptions                  = array(); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$phpmailer->Timeout                      = 300; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$phpmailer->getSMTPInstance()->Timelimit = 300; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Whether wp_mail() is WordPress's own. A plugin that replaces it (a pluggable function) may never
	 * fire phpmailer_init, and then the built-in SMTP is skipped.
	 */
	public static function wp_mail_is_core(): bool {
		try {
			$file = (string) ( new \ReflectionFunction( 'wp_mail' ) )->getFileName();
		} catch ( \ReflectionException $e ) {
			return true;
		}
		return str_ends_with( wp_normalize_path( $file ), '/' . WPINC . '/pluggable.php' );
	}

	/**
	 * TLS options: the certificate is always verified. A site with a private CA passes its CA file
	 * with the `sigma_smtp_ssl_options` filter.
	 *
	 * @return array<string, mixed>
	 */
	public static function ssl_options(): array {
		/**
		 * Filters the stream SSL context options for SMTP.
		 *
		 * @param array $options Options.
		 */
		return (array) apply_filters(
			'delta_assistant_smtp_ssl_options',
			array(
				'verify_peer'       => true,
				'verify_peer_name'  => true,
				'allow_self_signed' => false,
			)
		);
	}

	/**
	 * A failed send of the plugin's own email: keep the error, redacted.
	 *
	 * @param \WP_Error $error Error.
	 */
	public static function failed( $error ): void {
		if ( self::$active && $error instanceof \WP_Error ) {
			self::record( false, $error->get_error_message() );
		}
	}

	/**
	 * Record a send result.
	 *
	 * @param bool   $ok    Sent.
	 * @param string $error Error message.
	 */
	public static function record( bool $ok, string $error ): void {
		$status = self::status();
		if ( $ok ) {
			$status['last_success_at']      = gmdate( 'Y-m-d H:i:s' );
			$status['consecutive_failures'] = 0;
		} else {
			$status['last_error']           = mb_substr( self::redact( $error ), 0, 500 );
			$status['last_error_at']        = gmdate( 'Y-m-d H:i:s' );
			$status['consecutive_failures'] = (int) $status['consecutive_failures'] + 1;
		}
		update_option( self::STATUS_OPTION, $status, false );
	}

	/**
	 * Last send status.
	 *
	 * @return array{last_success_at: string, last_error: string, last_error_at: string, consecutive_failures: int}
	 */
	public static function status(): array {
		$stored = get_option( self::STATUS_OPTION, array() );
		return array_merge(
			array(
				'last_success_at'      => '',
				'last_error'           => '',
				'last_error_at'        => '',
				'consecutive_failures' => 0,
			),
			is_array( $stored ) ? $stored : array()
		);
	}

	/**
	 * Remove the SMTP password (and its base64 forms) from a text.
	 *
	 * @param string $text Text.
	 */
	public static function redact( string $text ): string {
		$password = Settings::smtp_password();
		if ( '' === $password ) {
			return $text;
		}
		$user  = (string) Settings::get( 'smtp_username' );
		$forms = array( $password, base64_encode( $password ), base64_encode( "\0" . $user . "\0" . $password ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- The forms AUTH LOGIN/PLAIN send.
		return str_replace( $forms, '[redacted]', $text );
	}

	/**
	 * Admin notices: 3 failed sends in a row, and handoffs whose email failed.
	 */
	public static function notices(): void {
		if ( \TwentyOnePress\Sigma\Admin\Notices::is_sigma_screen() ) {
			return; // Shown inside the app there (0.2.5).
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$status = self::status();
		if ( (int) $status['consecutive_failures'] >= self::NOTICE_AFTER ) {
			printf(
				'<div class="notice notice-error"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
				/* translators: %d: failures in a row. */
				esc_html( sprintf( __( 'Sigma: the last %d emails failed to send.', 'sigma' ), (int) $status['consecutive_failures'] ) ),
				esc_url( admin_url( 'admin.php?page=sigma#human' ) ),
				esc_html__( 'Check the mail settings', 'sigma' )
			);
		}
		$failed = ( new Repository() )->count_email_failed();
		if ( $failed > 0 ) {
			printf(
				'<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
				/* translators: %d: conversations. */
				esc_html( sprintf( _n( 'Sigma: %d handoff email was not sent.', 'Sigma: %d handoff emails were not sent.', $failed, 'sigma' ), $failed ) ),
				esc_url( admin_url( 'admin.php?page=sigma-conversations' ) ),
				esc_html__( 'Open Conversations to retry', 'sigma' )
			);
		}
	}
}
