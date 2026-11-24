<?php
/**
 * Sigma's notices inside its own screens (0.2.5).
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Admin;

use TwentyOnePress\Sigma\Config;
use TwentyOnePress\Sigma\Conversations\Repository;
use TwentyOnePress\Sigma\Hooks;
use TwentyOnePress\Sigma\Mail\Transport;
use TwentyOnePress\Sigma\Plugin;
use TwentyOnePress\Sigma\Rest\Visitor_Controller;
use TwentyOnePress\Sigma\Security\Turnstile;
use TwentyOnePress\Sigma\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress moves classic admin notices into the page header, where they break the app's layout. On
 * Sigma's screens the notices travel in the boot data instead (SigmaAdmin.notices) and the app shows
 * them in the content column. Elsewhere in wp-admin the classic notices stay.
 *
 * Every notice is dismissible. The one-time notice (bot-renamed) is used up when it is delivered; a
 * notice about a condition is hidden for that user until the condition changes (its fingerprint).
 */
final class Notices {

	/** User meta: notice id => fingerprint dismissed. */
	public const DISMISSED_META = 'sigma_dismissed_notices';

	/** The notices this class knows. */
	public const IDS = array( 'bot-renamed', 'build-stale', 'legacy-constants', 'mail-failing', 'handoffs-failed', 'turnstile-outage' );

	/**
	 * Hooks.
	 */
	public static function hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	/**
	 * Whether this request is one of Sigma's three screens (the classic notices skip them).
	 */
	public static function is_sigma_screen(): bool {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Which screen this is; no action.
		return in_array( $page, array( Admin_Page::SLUG, Admin_Page::KNOWLEDGE, Admin_Page::CONVERSATIONS ), true );
	}

	/**
	 * The notices for a Sigma screen, for the current user, without those they dismissed. Delivering the
	 * one-time notice uses it up.
	 *
	 * @param string $screen settings|knowledge|conversations.
	 * @return list<array{id: string, tone: string, text: string, dismissible: bool, action?: array{label: string, url: string}}>
	 */
	public static function for_screen( string $screen ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return array();
		}
		$dismissed = self::dismissed();
		$out       = array();
		foreach ( self::collect() as $notice ) {
			if ( isset( $dismissed[ $notice['id'] ] ) && $dismissed[ $notice['id'] ] === $notice['fingerprint'] ) {
				continue;
			}
			if ( 'bot-renamed' === $notice['id'] ) {
				delete_option( Plugin::NAME_NOTICE );
			}
			unset( $notice['fingerprint'] );
			$out[] = $notice;
		}
		/**
		 * Filters the notices shown inside a Sigma screen.
		 *
		 * @param array  $out    Notices: id, tone (info|success|warning|danger), text (`code` in backticks),
		 *                       dismissible, optional action {label, url}.
		 * @param string $screen settings|knowledge|conversations.
		 */
		return array_values( (array) Hooks::filter( 'admin_notices', $out, $screen ) );
	}

	/**
	 * Every notice whose condition holds now, with its fingerprint.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function collect(): array {
		$out = array();
		if ( get_option( Plugin::NAME_NOTICE ) ) {
			$out[] = self::notice( 'bot-renamed', 'info', __( 'Your bot is now called Sigma (it was Remy). You can change the name under AI agent → Identity.', 'sigma' ), 'once' );
		}

		$build = Build_Check::check();
		if ( 'stale' === $build['problem'] ) {
			$out[] = self::notice(
				'build-stale',
				'danger',
				'' === $build['built']
					/* translators: %s: the plugin's version. */
					? sprintf( __( 'The plugin\'s built files are out of date (they have no version stamp; the plugin is %s), so Sigma\'s screens and chat box may not work. If you installed Sigma from git, run `npm run build` in the plugin folder; otherwise reinstall the plugin from its release zip.', 'sigma' ), P21_SIGMA_VERSION )
					/* translators: 1: the version (and commit) the files were built from, 2: the plugin's version. */
					: sprintf( __( 'The plugin\'s built files are out of date (built from %1$s; the plugin is %2$s), so Sigma\'s screens and chat box may not work. If you installed Sigma from git, run `npm run build` in the plugin folder; otherwise reinstall the plugin from its release zip.', 'sigma' ), $build['built'], P21_SIGMA_VERSION ),
				$build['built']
			);
		}

		$legacy = Config::legacy_defined();
		if ( $legacy ) {
			$renames = implode( ', ', array_map( static fn( string $c ): string => '`' . $c . '` → `' . str_replace( 'DELTA_ASSISTANT_', 'SIGMA_', $c ) . '`', $legacy ) );
			/* translators: %s: list of constants, "OLD → NEW". */
			$out[] = self::notice( 'legacy-constants', 'warning', sprintf( __( 'Please rename these constants in wp-config.php: %s. The old names still work for now.', 'sigma' ), $renames ), implode( ',', $legacy ) );
		}

		$status = Transport::status();
		if ( (int) $status['consecutive_failures'] >= Transport::NOTICE_AFTER ) {
			$out[] = self::notice(
				'mail-failing',
				'danger',
				/* translators: %d: failures in a row. */
				sprintf( __( 'The last %d emails failed to send.', 'sigma' ), (int) $status['consecutive_failures'] ),
				(string) $status['last_error_at'],
				array(
					'label' => __( 'Check the mail settings', 'sigma' ),
					'url'   => admin_url( 'admin.php?page=' . Admin_Page::SLUG . '#human' ),
				)
			);
		}

		$failed = ( new Repository() )->count_email_failed();
		if ( $failed > 0 ) {
			$out[] = self::notice(
				'handoffs-failed',
				'warning',
				/* translators: %d: conversations. */
				sprintf( _n( '%d handoff email was not sent.', '%d handoff emails were not sent.', $failed, 'sigma' ), $failed ),
				(string) $failed,
				array(
					'label' => __( 'Open Conversations to retry', 'sigma' ),
					'url'   => admin_url( 'admin.php?page=' . Admin_Page::CONVERSATIONS ),
				)
			);
		}

		$outages = Settings::turnstile_on() ? Turnstile::unreachable_count() : 0;
		if ( $outages >= Turnstile::NOTICE_AFTER ) {
			$out[] = self::notice(
				'turnstile-outage',
				'warning',
				sprintf(
					/* translators: %d: events this hour. */
					_n(
						'Cloudflare Turnstile could not be reached %d time this hour. Messages to your team were still accepted, with a limit of 1 per hour per visitor.',
						'Cloudflare Turnstile could not be reached %d times this hour. Messages to your team were still accepted, with a limit of 1 per hour per visitor.',
						$outages,
						'sigma'
					),
					$outages
				),
				gmdate( 'YmdH' ),
				array(
					'label' => __( 'Spam protection settings', 'sigma' ),
					'url'   => admin_url( 'admin.php?page=' . Admin_Page::SLUG . '#human' ),
				)
			);
		}
		return $out;
	}

	/**
	 * POST /admin/notices/{id}/dismiss.
	 */
	public static function routes(): void {
		register_rest_route(
			Visitor_Controller::NAMESPACE,
			'/admin/notices/(?P<id>[a-z0-9-]{1,40})/dismiss',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'dismiss' ),
				'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
			)
		);
	}

	/**
	 * Dismiss a notice for the current user: the one-time notice for good, a condition notice until its
	 * condition changes.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function dismiss( \WP_REST_Request $request ) {
		$id = (string) $request['id'];
		if ( ! in_array( $id, self::IDS, true ) ) {
			return new \WP_Error( 'rest_not_found', __( 'No such notice.', 'sigma' ), array( 'status' => 404 ) );
		}
		if ( 'bot-renamed' === $id ) {
			delete_option( Plugin::NAME_NOTICE );
		} else {
			foreach ( self::collect() as $notice ) {
				if ( $id === $notice['id'] ) {
					$dismissed        = self::dismissed();
					$dismissed[ $id ] = $notice['fingerprint'];
					update_user_meta( get_current_user_id(), self::DISMISSED_META, $dismissed );
				}
			}
		}
		return new \WP_REST_Response( array( 'dismissed' => true ), 200 );
	}

	/**
	 * What the current user dismissed: id => fingerprint.
	 *
	 * @return array<string, string>
	 */
	private static function dismissed(): array {
		$stored = get_user_meta( get_current_user_id(), self::DISMISSED_META, true );
		return is_array( $stored ) ? array_map( 'strval', $stored ) : array();
	}

	/**
	 * One notice.
	 *
	 * @param string                                 $id          Id.
	 * @param string                                 $tone        info|success|warning|danger.
	 * @param string                                 $text        Plain text; `code` in backticks.
	 * @param string                                 $fingerprint What the condition is now (a dismissal holds until it changes).
	 * @param array{label: string, url: string}|null $action Optional link, an admin URL.
	 * @return array<string, mixed>
	 */
	private static function notice( string $id, string $tone, string $text, string $fingerprint, ?array $action = null ): array {
		$notice = array(
			'id'          => $id,
			'tone'        => $tone,
			'text'        => $text,
			'dismissible' => true,
			'fingerprint' => $fingerprint,
		);
		if ( $action ) {
			$notice['action'] = array(
				'label' => $action['label'],
				'url'   => esc_url_raw( $action['url'] ),
			);
		}
		return $notice;
	}
}
