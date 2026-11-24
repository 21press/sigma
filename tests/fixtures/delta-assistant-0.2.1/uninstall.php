<?php
/**
 * Uninstall: capabilities, jobs and secrets always go; tables and the other options only when
 * "Delete data on uninstall" is on (§15, §10b). On multisite, every site of the network.
 *
 * @package TwentyOnePress\Delta_Assistant
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-schema.php';

if ( ! function_exists( 'delta_assistant_uninstall_site' ) ) :
	/**
	 * Clean up one site. (Guarded: the test suite loads this file more than once in one process.)
	 */
	function delta_assistant_uninstall_site(): void {
		foreach ( array( 'delta_assistant_purge', 'delta_assistant_purge_more', 'delta_assistant_rebuild_corpus', 'delta_assistant_refresh_avatars', 'delta_assistant_refresh_avatars_soon' ) as $delta_assistant_hook ) {
			wp_clear_scheduled_hook( $delta_assistant_hook );
		}
		delete_transient( 'delta_assistant_avatar_heal' );
		delete_option( 'delta_assistant_avatar_lock' );
		delete_option( 'delta_assistant_turnstile_tested' );

		foreach ( wp_roles()->role_objects as $delta_assistant_role ) {
			$delta_assistant_role->remove_cap( 'delta_assistant_view' );
			$delta_assistant_role->remove_cap( 'delta_assistant_reply' );
		}
		foreach ( get_users(
			array(
				'capability__in' => array( 'delta_assistant_view', 'delta_assistant_reply' ),
				'fields'         => 'all',
			)
		) as $delta_assistant_user ) {
			$delta_assistant_user->remove_cap( 'delta_assistant_view' );
			$delta_assistant_user->remove_cap( 'delta_assistant_reply' );
		}

		$delta_assistant_settings = get_option( 'delta_assistant_settings', array() );
		if ( ! is_array( $delta_assistant_settings ) ) {
			$delta_assistant_settings = array();
		}

		// Secrets go even when the data stays (§10b): a kept install must not hold keys of a removed plugin.
		$delta_assistant_secrets = array( 'anthropic_key_enc', 'anthropic_key_last4', 'openai_key_enc', 'openai_key_last4', 'smtp_password_enc', 'turnstile_secret_enc', 'turnstile_last4' );
		if ( array_intersect_key( $delta_assistant_settings, array_flip( $delta_assistant_secrets ) ) ) {
			update_option( 'delta_assistant_settings', array_diff_key( $delta_assistant_settings, array_flip( $delta_assistant_secrets ) ) );
		}

		if ( empty( $delta_assistant_settings['delete_on_uninstall'] ) ) {
			return;
		}
		\TwentyOnePress\Delta_Assistant\Schema::drop();
		// Knowledge base entries (§7.6), with their revisions and meta. Only here: kept data keeps them.
		foreach ( get_posts(
			array(
				'post_type'        => 'da_knowledge',
				'post_status'      => 'any',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		) as $delta_assistant_entry ) {
			wp_delete_post( (int) $delta_assistant_entry, true );
		}
		foreach ( array( 'delta_assistant_settings', 'delta_assistant_corpus', 'delta_assistant_corpus_meta', 'delta_assistant_cf_ranges', 'delta_assistant_cf_seen', 'delta_assistant_mail_status', 'delta_assistant_version', 'delta_assistant_bot_avatar_copy', 'delta_assistant_bot_avatar_status' ) as $delta_assistant_option ) {
			delete_option( $delta_assistant_option );
		}
		foreach ( array( 'delta_assistant_chat_name', 'delta_assistant_chat_avatar', 'delta_assistant_avatar_copy', 'delta_assistant_avatar_status' ) as $delta_assistant_meta ) {
			delete_metadata( 'user', 0, $delta_assistant_meta, '', true );
		}
		$delta_assistant_uploads = wp_get_upload_dir();
		$delta_assistant_avatars = trailingslashit( (string) $delta_assistant_uploads['basedir'] ) . 'delta-assistant/avatars';
		foreach ( (array) glob( $delta_assistant_avatars . '/*' ) as $delta_assistant_file ) {
			wp_delete_file( (string) $delta_assistant_file );
		}
		@rmdir( $delta_assistant_avatars ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Empty folder; fine if it stays.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup of the plugin's monthly usage options.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'delta_assistant_usage_' ) . '%' ) );
	}
endif;

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $delta_assistant_site ) {
		switch_to_blog( (int) $delta_assistant_site );
		delta_assistant_uninstall_site();
		restore_current_blog();
	}
} else {
	delta_assistant_uninstall_site();
}
