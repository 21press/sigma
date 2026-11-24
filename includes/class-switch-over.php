<?php
/**
 * Switching from Delta Assistant to Sigma (§19.1).
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma;

use TwentyOnePress\Sigma\Team\Team;

defined( 'ABSPATH' ) || exit;

/**
 * Sigma uses the same storage as Delta Assistant, so everything carries over. Two things guard it:
 *
 * - Activating Sigma deactivates Delta Assistant (both would answer the same chats) and says so.
 * - Deleting Delta Assistant from wp-admin runs its uninstall.php, which always removes the saved
 *   keys and, with "Delete data on uninstall" on, the tables and knowledge entries Sigma now uses.
 *   Before it runs, the settings are saved aside and "Delete data" is switched off; after the files
 *   are deleted, the settings are put back (keys included) and Sigma's roles and jobs restored.
 *   WP-CLI's `wp plugin uninstall` runs the same uninstall but deletes the files itself, without
 *   `deleted_plugin`, so the restore also runs at the end of the request (a no-op once done).
 */
final class Switch_Over {

	/** Delta Assistant's plugin file. */
	public const OLD_PLUGIN = 'delta-assistant/delta-assistant.php';

	/** Option holding the settings while the old plugin is deleted (autoload off). */
	public const SNAPSHOT = 'sigma_switch_snapshot';

	/**
	 * Options Delta Assistant's uninstall deletes that Sigma still uses, besides the settings.
	 */
	public const KEPT_OPTIONS = array( Security\Turnstile::TESTED_OPTION );

	/** Transient: show the "Delta Assistant was deactivated" notice. */
	public const NOTICE = 'sigma_switch_notice';

	/**
	 * Hooks.
	 */
	public static function hooks(): void {
		add_action( 'pre_uninstall_plugin', array( self::class, 'before_uninstall' ), 10, 1 );
		add_action( 'deleted_plugin', array( self::class, 'after_delete' ), 10, 2 );
		add_action( 'admin_notices', array( self::class, 'notices' ) );
	}

	/**
	 * On Sigma's activation: deactivate Delta Assistant if it is active (single site or network).
	 */
	public static function on_activate(): void {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$network = is_multisite() && is_plugin_active_for_network( self::OLD_PLUGIN );
		if ( ! $network && ! is_plugin_active( self::OLD_PLUGIN ) ) {
			return;
		}
		deactivate_plugins( self::OLD_PLUGIN, true, $network );
		set_transient( self::NOTICE, 1, DAY_IN_SECONDS );
	}

	/**
	 * Before an uninstall.php runs: for Delta Assistant's, save the settings of every site and keep its
	 * uninstall from deleting data.
	 *
	 * @param string $plugin Plugin file being uninstalled.
	 */
	public static function before_uninstall( $plugin ): void {
		if ( self::OLD_PLUGIN !== $plugin ) {
			return;
		}
		self::each_site(
			static function (): void {
				$settings = get_option( Settings::OPTION, null );
				if ( ! is_array( $settings ) ) {
					return;
				}
				$kept = array();
				foreach ( self::KEPT_OPTIONS as $option ) {
					$value = get_option( $option, null );
					if ( null !== $value ) {
						$kept[ $option ] = $value;
					}
				}
				update_option(
					self::SNAPSHOT,
					array(
						'settings' => $settings,
						'options'  => $kept,
					),
					false
				);
				$settings['delete_on_uninstall'] = false;
				update_option( Settings::OPTION, $settings );
			}
		);
		add_action( 'shutdown', array( self::class, 'restore' ), 0 );
	}

	/**
	 * After the plugin's files are deleted: put the saved settings back and restore Sigma's roles and
	 * scheduled jobs, which the old uninstall removes.
	 *
	 * @param string $plugin  Plugin file deleted.
	 * @param bool   $deleted Whether the files were deleted.
	 */
	public static function after_delete( $plugin, $deleted = true ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Restored even if the files stayed: its uninstall already ran.
		if ( self::OLD_PLUGIN === $plugin ) {
			self::restore();
		}
	}

	/**
	 * Put the saved settings back on every site that has a snapshot; nothing to do otherwise. Runs
	 * after the delete in wp-admin and, for WP-CLI, at the end of the request.
	 */
	public static function restore(): void {
		self::each_site(
			static function (): void {
				$snapshot = get_option( self::SNAPSHOT, null );
				if ( ! is_array( $snapshot ) || ! is_array( $snapshot['settings'] ?? null ) ) {
					return;
				}
				update_option( Settings::OPTION, $snapshot['settings'] );
				foreach ( (array) ( $snapshot['options'] ?? array() ) as $option => $value ) {
					if ( in_array( $option, self::KEPT_OPTIONS, true ) ) {
						update_option( (string) $option, $value );
					}
				}
				delete_option( self::SNAPSHOT );
				Settings::flush();
				Plugin::activate();
				Team::sync_caps();
			}
		);
	}

	/**
	 * The admin notice after switching over.
	 */
	public static function notices(): void {
		if ( ! current_user_can( 'activate_plugins' ) || ! get_transient( self::NOTICE ) ) {
			return;
		}
		delete_transient( self::NOTICE );
		printf(
			'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
			esc_html__( 'Sigma replaces Delta Assistant, so Delta Assistant was deactivated. Your settings, conversations and knowledge base carry over. You can now delete Delta Assistant: its keys and data stay with Sigma.', 'sigma' )
		);
	}

	/**
	 * Run a callback on every site (once on a single site).
	 *
	 * @param callable $callback Callback.
	 */
	private static function each_site( callable $callback ): void {
		if ( ! is_multisite() ) {
			$callback();
			return;
		}
		foreach ( get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		) as $site ) {
			switch_to_blog( (int) $site );
			try {
				$callback();
			} finally {
				restore_current_blog();
			}
		}
	}
}
