<?php
/**
 * The plugin's tables (§6, §10b), created and upgraded with dbDelta.
 *
 * @package TwentyOnePress\Delta_Assistant
 */

declare( strict_types=1 );

namespace TwentyOnePress\Delta_Assistant;

defined( 'ABSPATH' ) || exit;

/**
 * Tables already shaped for release 2. Migrations are additive only: a column is never dropped or
 * renamed, so an older release runs on a newer schema (rollback, §15).
 */
final class Schema {

	/** Bump when the CREATE TABLE statements change. */
	public const VERSION = 4;

	/** Option holding the installed schema version. */
	public const OPTION = 'delta_assistant_db_version';

	/**
	 * Conversations table name.
	 */
	public static function conversations(): string {
		global $wpdb;
		return $wpdb->prefix . 'da_conversations';
	}

	/**
	 * Messages table name.
	 */
	public static function messages(): string {
		global $wpdb;
		return $wpdb->prefix . 'da_messages';
	}

	/**
	 * Counters table (§10b): atomic rate-limit, concurrency-fallback and usage counters.
	 */
	public static function counters(): string {
		global $wpdb;
		return $wpdb->prefix . 'da_counters';
	}

	/**
	 * Install or upgrade when the stored version is older. Cheap: one autoloaded option read.
	 */
	public static function maybe_upgrade(): void {
		if ( (int) get_option( self::OPTION, 0 ) < self::VERSION ) {
			self::install();
		}
	}

	/**
	 * Create or update the tables.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset       = $wpdb->get_charset_collate();
		$conversations = self::conversations();
		$messages      = self::messages();
		$counters      = self::counters();

		// dbDelta wants two spaces after PRIMARY KEY and one field per line.
		dbDelta(
			"CREATE TABLE {$conversations} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uuid char(36) NOT NULL,
  token_hash char(64) NOT NULL,
  mode varchar(10) NOT NULL DEFAULT 'ai',
  status varchar(12) NOT NULL DEFAULT 'open',
  visitor_name varchar(100) NULL,
  visitor_email varchar(190) NULL,
  assigned_user_id bigint(20) unsigned NULL,
  page_url varchar(500) NOT NULL DEFAULT '',
  locale varchar(20) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  handoff_at datetime NULL,
  deadline_at datetime NULL,
  first_agent_reply_at datetime NULL,
  emailed_at datetime NULL,
  closed_at datetime NULL,
  email_status varchar(10) NULL,
  handoff_key char(36) NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uuid (uuid),
  KEY status_deadline (status,deadline_at),
  KEY updated_at (updated_at),
  KEY visitor_email (visitor_email),
  KEY email_status (email_status)
) {$charset};
CREATE TABLE {$messages} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  conversation_id bigint(20) unsigned NOT NULL,
  sender varchar(10) NOT NULL,
  user_id bigint(20) unsigned NULL,
  body text NOT NULL,
  meta longtext NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY conversation_id (conversation_id,id)
) {$charset};
CREATE TABLE {$counters} (
  bucket varchar(100) NOT NULL,
  n bigint(20) unsigned NOT NULL DEFAULT 0,
  expires_at datetime NOT NULL,
  PRIMARY KEY  (bucket),
  KEY expires_at (expires_at)
) {$charset};"
		);

		update_option( self::OPTION, self::VERSION, true );
	}

	/**
	 * Drop the tables (uninstall with "Delete data on uninstall" on).
	 */
	public static function drop(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table names are the plugin's own.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::messages() . ', ' . self::conversations() . ', ' . self::counters() );
		delete_option( self::OPTION );
	}
}
