<?php
/**
 * Boot order and hooks.
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma;

use TwentyOnePress\Sigma\Admin\Admin_Page;
use TwentyOnePress\Sigma\Knowledge\Corpus;
use TwentyOnePress\Sigma\Mail\Transport;
use TwentyOnePress\Sigma\Team\Avatars;
use TwentyOnePress\Sigma\Team\Profile;
use TwentyOnePress\Sigma\Privacy\Privacy;
use TwentyOnePress\Sigma\Rest\Admin_Controller;
use TwentyOnePress\Sigma\Rest\Visitor_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Wires every part of the plugin to WordPress. Nothing here does work itself.
 */
final class Plugin {

	/** Capability: read transcripts (§9). */
	public const CAP_VIEW = 'delta_assistant_view';

	/** Capability: answer live chats (§9, release 2). */
	public const CAP_REPLY = 'delta_assistant_reply';

	/** The plugin version that last ran on this site. */
	public const VERSION_OPTION = 'delta_assistant_version';

	/** Set once the 0.2.5 bot-name change has run on this site. */
	public const NAME_MIGRATED = 'sigma_bot_name_migrated';

	/** Pending one-time notice: the bot was renamed from Remy to Sigma. */
	public const NAME_NOTICE = 'sigma_bot_name_notice';

	/** Daily retention purge (§11). */
	public const CRON_PURGE = 'delta_assistant_purge';

	/**
	 * Add the hooks. Runs once, when the plugin file loads.
	 */
	public static function boot(): void {
		add_action( 'plugins_loaded', array( Schema::class, 'maybe_upgrade' ) );
		// On init, not plugins_loaded: spawn_cron() needs WP_CRON_LOCK_TIMEOUT, defined after plugins_loaded.
		add_action( 'init', array( self::class, 'maybe_version_change' ), 20 );
		add_action( 'init', array( self::class, 'load_textdomain' ) );
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_action( 'wp_enqueue_scripts', array( Widget::class, 'enqueue' ) );
		add_action( self::CRON_PURGE, array( Privacy::class, 'purge' ) );
		add_action( Privacy::CRON_MORE, array( Privacy::class, 'purge' ) );
		add_action( 'init', array( self::class, 'ensure_purge_scheduled' ), 30 );
		add_action( 'admin_notices', array( \TwentyOnePress\Sigma\Security\Turnstile::class, 'notices' ) );

		Config::hooks();
		Switch_Over::hooks();
		Updater::hooks();
		Visitor_Controller::hooks();
		Corpus::hooks();
		Knowledge\Knowledge_Base::hooks();
		Transport::hooks();
		Avatars::hooks();
		Profile::hooks();
		Privacy::hooks();
		Admin_Page::hooks();
		Admin\Build_Check::hooks();
		Admin\Notices::hooks();
	}

	/**
	 * Load translations.
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain( 'sigma', false, dirname( plugin_basename( P21_SIGMA_FILE ) ) . '/languages' );
	}

	/**
	 * REST routes (§8).
	 */
	public static function register_routes(): void {
		( new Visitor_Controller() )->register_routes();
		( new Admin_Controller() )->register_routes();
		( new Rest\Knowledge_Controller() )->register_routes();
	}

	/**
	 * Whether the kill switch is on: the constant or the Settings toggle (§9). The bubble is not
	 * printed and the visitor API answers 503.
	 */
	public static function killed(): bool {
		if ( Config::get( 'DISABLED' ) ) {
			return true;
		}
		return ! Settings::get( 'enabled' );
	}

	/**
	 * After an update (the stored version is older or missing), run the one-off jobs a new version
	 * needs: today, the team's avatar copies (0.1.2 could not make them). Cheap: one autoloaded option.
	 */
	public static function maybe_version_change(): void {
		$stored = (string) get_option( self::VERSION_OPTION, '' );
		if ( ! get_option( self::NAME_MIGRATED ) ) {
			self::migrate_bot_name( '' === $stored );
		}
		if ( P21_SIGMA_VERSION === $stored ) {
			return;
		}
		update_option( self::VERSION_OPTION, P21_SIGMA_VERSION, true );
		if ( '' === $stored || version_compare( $stored, P21_SIGMA_VERSION, '<' ) ) {
			Avatars::refresh_soon();
		}
		if ( '' !== $stored && version_compare( $stored, '0.1.7', '<' ) ) {
			self::migrate_017();
		}
		if ( '' !== $stored && version_compare( $stored, '0.1.8', '<' ) ) {
			self::migrate_018();
		}
	}

	/**
	 * 0.2.5: the default bot name is Sigma, not Remy. Once per site, keyed on its own flag rather than the
	 * version (test builds already stored 0.2.5): a site whose bot still had the old default gets the new
	 * one and a notice; a name the owner chose stays. A new install has nothing to change.
	 *
	 * @param bool $fresh No stored version: a new install.
	 */
	public static function migrate_bot_name( bool $fresh ): void {
		add_option( self::NAME_MIGRATED, 1, '', true );
		$stored = get_option( Settings::OPTION, null );
		if ( $fresh || ! is_array( $stored ) ) {
			return;
		}
		$name = trim( (string) ( $stored['bot_name'] ?? '' ) );
		if ( '' !== $name && 'Remy' !== $name ) {
			return;
		}
		if ( 'Remy' === $name ) {
			$stored['bot_name'] = 'Sigma';
			update_option( Settings::OPTION, $stored, true );
			Settings::flush();
		}
		update_option( self::NAME_NOTICE, 1, false ); // Shown once, inside a Sigma screen (Admin\Notices).
	}

	/**
	 * 0.1.7 (§10b): the visitor IP source becomes `auto` (Cloudflare's header only from its own
	 * addresses; safe everywhere), and stored secrets are encrypted again under the wp_salt key.
	 */
	public static function migrate_017(): void {
		$stored = get_option( Settings::OPTION, array() );
		if ( is_array( $stored ) && 'remote_addr' === ( $stored['ip_source'] ?? 'remote_addr' ) ) {
			$stored['ip_source'] = 'auto';
			update_option( Settings::OPTION, $stored, true );
			Settings::flush();
		}
		Settings::rekey();
	}

	/**
	 * 0.1.8 (§8.8): one Remy icon. A site that uploaded a bot avatar keeps seeing it, now everywhere,
	 * the launcher included: `launcher_icon` becomes `image`. Otherwise the stored icon stays.
	 */
	public static function migrate_018(): void {
		$stored = get_option( Settings::OPTION, array() );
		if ( is_array( $stored ) && (int) ( $stored['bot_avatar'] ?? 0 ) > 0 && 'image' !== ( $stored['launcher_icon'] ?? '' ) ) {
			$stored['launcher_icon'] = 'image';
			update_option( Settings::OPTION, $stored, true );
			Settings::flush();
		}
	}

	/**
	 * The daily purge is rescheduled if it went missing (§10b self-heal).
	 */
	public static function ensure_purge_scheduled(): void {
		if ( ! wp_next_scheduled( self::CRON_PURGE ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_PURGE );
		}
	}

	/**
	 * Activation: tables, capabilities, the purge job.
	 */
	public static function activate(): void {
		Switch_Over::on_activate(); // Delta Assistant off (§19.1); it shares this plugin's storage.
		Schema::install();
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( self::CAP_VIEW );
		}
		if ( ! wp_next_scheduled( self::CRON_PURGE ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_PURGE );
		}
		update_option( self::VERSION_OPTION, P21_SIGMA_VERSION, true );
		Avatars::refresh_soon(); // Copies for a team kept from before (deactivate → activate, or an upgrade by upload).
	}

	/**
	 * Deactivation: stop the jobs. Data and capabilities stay (uninstall removes them).
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_PURGE );
		wp_clear_scheduled_hook( Privacy::CRON_MORE );
		wp_clear_scheduled_hook( Corpus::CRON_REBUILD );
		wp_clear_scheduled_hook( Avatars::CRON );
		wp_clear_scheduled_hook( Avatars::CRON_SOON );
	}
}
