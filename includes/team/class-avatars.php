<?php
/**
 * Team avatars without email hashes (§8.4).
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Team;

defined( 'ABSPATH' ) || exit;

use TwentyOnePress\Sigma\Settings;

/**
 * A Gravatar URL carries a hash of the user's email, so /config never shows WordPress avatar URLs.
 * The plugin keeps its own 96×96 copy instead (WebP, PNG when WebP is not available or fails) in
 * uploads/delta-assistant/avatars/ under a random file name:
 *  - source: the Chat avatar from the Media Library, else get_avatar_url() fetched server-side with
 *    `default=404`, so a user without a picture comes back as a 404 and gets initials instead of the
 *    "mystery person";
 *  - refreshed on team save, on a profile update, on Settings → Team → Refresh avatars and daily; a
 *    new file only when the image changed, and the old file is deleted;
 *  - when there is no picture or the fetch fails: no file, empty URL, and the widget draws initials.
 *
 * Runs in REST requests and WP-Cron, where wp-admin/includes is not loaded: only wp-includes
 * functions here (0.1.2 called wp_tempnam() and died with a fatal error outside wp-admin).
 *
 * Each refresh records its outcome in user meta (Avatar_Status) for Settings → Team: the source, the
 * time and the result with its reason. Never the email, the hash or the Gravatar URL.
 */
final class Avatars {

	/** Stored copy (user meta): {file (name only), hash}. */
	public const META = 'delta_assistant_avatar_copy';

	/** Last refresh (user meta): see Avatar_Status. */
	public const STATUS_META = 'delta_assistant_avatar_status';

	/** Daily refresh (WP-Cron). */
	public const CRON = 'delta_assistant_refresh_avatars';

	/** One-off refresh (WP-Cron): after an upgrade or activation, or when /config finds a member never refreshed. */
	public const CRON_SOON = 'delta_assistant_refresh_avatars_soon';

	/** Transient: a one-off refresh was scheduled in the last 10 minutes (no other from /config). */
	public const HEAL_LOCK = 'delta_assistant_avatar_heal';

	/** Option: a team refresh is running (its start time). Atomic with add_option(). */
	public const RUN_LOCK = 'delta_assistant_avatar_lock';

	/** A run lock older than this is from a run that died: take it over. */
	private const RUN_LOCK_STALE = 120;

	/** Files in the avatars folder that no member uses are deleted after this long. */
	private const ORPHAN_AGE = 600;

	public const SIZE = 96;

	/** The bot (Remy, §8.6) in the same pipeline: id 0, stored in options instead of user meta. */
	public const BOT = 0;

	/** The bot's copy (option): {file, hash}. */
	public const BOT_COPY = 'delta_assistant_bot_avatar_copy';

	/** The bot's last refresh (option): Avatar_Status. */
	public const BOT_STATUS = 'delta_assistant_bot_avatar_status';

	/** The default bot avatar shipped with the plugin. */
	public const BOT_DEFAULT = 'assets/remy-avatar.svg';

	/**
	 * Hooks.
	 */
	public static function hooks(): void {
		add_action( self::CRON, array( self::class, 'refresh_team' ) );
		add_action( self::CRON_SOON, array( self::class, 'refresh_team' ) );
		add_action( 'init', array( self::class, 'schedule' ) );
	}

	/**
	 * Ask WP-Cron to refresh the team's avatars now, in its own request: a one-off event due now and a
	 * non-blocking loopback (spawn_cron() only starts events that are already due). This request never
	 * fetches an avatar. Used after a version change or activation (0.1.2 left every copy missing) and
	 * by heal_missing().
	 *
	 * @return bool Whether a refresh was scheduled (false when one is already waiting).
	 */
	public static function refresh_soon(): bool {
		if ( wp_next_scheduled( self::CRON_SOON ) ) {
			return false;
		}
		$scheduled = wp_schedule_single_event( time(), self::CRON_SOON );
		if ( true === $scheduled ) {
			set_transient( self::HEAL_LOCK, 1, 10 * MINUTE_IN_SECONDS ); // /config won't add another while this runs.
		}
		if ( true === $scheduled && function_exists( 'spawn_cron' ) && defined( 'WP_CRON_LOCK_TIMEOUT' ) && ! wp_doing_cron() ) {
			spawn_cron();
		}
		return true === $scheduled;
	}

	/**
	 * /config: when a member has no copy and no recorded refresh (it never ran for them), schedule a
	 * one-off refresh, at most once per 10 minutes. A member with a status (no Gravatar, a failure) is
	 * left to the daily refresh and Settings → Team.
	 *
	 * @param int[] $ids Team member ids.
	 * @return bool Whether a refresh was scheduled.
	 */
	public static function heal_missing( array $ids ): bool {
		if ( get_transient( self::HEAL_LOCK ) ) {
			return false;
		}
		if ( 'media' === self::source_kind( self::BOT ) ) {
			$ids[] = self::BOT; // A chosen bot image that was never copied.
		}
		foreach ( $ids as $id ) {
			if ( '' === self::url( (int) $id ) && null === self::status( (int) $id ) ) {
				set_transient( self::HEAL_LOCK, 1, 10 * MINUTE_IN_SECONDS );
				return self::refresh_soon();
			}
		}
		return false;
	}

	/**
	 * Keep the daily refresh scheduled.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/**
	 * The public URL of a member's copy, or '' (initials). Built from the current uploads URL on every
	 * read, so it follows the site's scheme and domain (an http upload URL on an https request becomes
	 * https; a copy made on staging works after the site moves).
	 *
	 * @param int $user_id User id.
	 */
	public static function url( int $user_id ): string {
		$name = self::file_name( $user_id );
		if ( '' === $name ) {
			return '';
		}
		$url = trailingslashit( self::dir()['url'] ) . $name;
		return is_ssl() ? set_url_scheme( $url, 'https' ) : $url;
	}

	/**
	 * The file name of a member's copy when the file exists, else ''.
	 *
	 * @param int $user_id User id.
	 */
	private static function file_name( int $user_id ): string {
		$copy = self::get_copy( $user_id );
		if ( ! is_array( $copy ) || empty( $copy['file'] ) ) {
			return '';
		}
		$name = basename( (string) $copy['file'] ); // 0.1.2 stored the full path.
		if ( ! self::valid_name( $name ) ) {
			return '';
		}
		return is_readable( trailingslashit( self::dir()['path'] ) . $name ) ? $name : '';
	}

	/**
	 * Whether a file name is one of ours: 20 random characters and an image extension.
	 *
	 * @param string $name File name.
	 */
	private static function valid_name( string $name ): bool {
		return 1 === preg_match( '/^[a-z0-9]{20}\.(webp|png|avif|jpe?g)$/', $name );
	}

	/**
	 * Refresh every member, and drop the copies of users who left the team. One member's failure
	 * never stops the others or the settings save that called this. One run at a time (two at once
	 * could each write a file and leave one behind); files no member uses are swept.
	 *
	 * @return bool False when another run holds the lock and this one was skipped.
	 */
	public static function refresh_team(): bool {
		Team::sync_caps(); // Members who lost publish_posts lose the reply capability too.
		if ( ! self::lock() ) {
			return false;
		}
		try {
			$team = Team::ids();
			foreach ( array_merge( $team, array( self::BOT ) ) as $id ) {
				try {
					self::refresh( $id );
				} catch ( \Throwable $e ) {
					self::record( $id, Avatar_Status::status( self::source_kind( $id ), 'failed', 'exception', ( new \ReflectionClass( $e ) )->getShortName() ) );
				}
			}
			$used = array( self::file_name( self::BOT ) );
			foreach ( get_users(
				array(
					'meta_key' => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Few users; cron and saves only.
					'fields'   => 'ID',
				)
			) as $id ) {
				if ( ! in_array( (int) $id, $team, true ) ) {
					self::remove( (int) $id );
					delete_user_meta( (int) $id, self::STATUS_META );
				} else {
					$used[] = self::file_name( (int) $id );
				}
			}
			self::sweep( $used );
		} finally {
			delete_option( self::RUN_LOCK );
		}
		return true;
	}

	/**
	 * Take the run lock: false when a live run holds it.
	 */
	private static function lock(): bool {
		if ( add_option( self::RUN_LOCK, time(), '', false ) ) {
			return true;
		}
		wp_cache_delete( self::RUN_LOCK, 'options' ); // Read the stored value, not this request's cache.
		$since = (int) get_option( self::RUN_LOCK, 0 );
		if ( $since > 0 && time() - $since < self::RUN_LOCK_STALE ) {
			return false;
		}
		update_option( self::RUN_LOCK, time(), false ); // A run that died: take over.
		return true;
	}

	/**
	 * Delete files in the avatars folder that no member uses, once they are 10 minutes old (a run
	 * that died, two runs at once in 0.1.3, temporary files).
	 *
	 * @param string[] $used File names in use.
	 */
	private static function sweep( array $used ): void {
		$dir = self::dir()['path'];
		foreach ( (array) glob( trailingslashit( $dir ) . '*' ) as $file ) {
			$name = basename( (string) $file );
			$ours = self::valid_name( $name ) || 1 === preg_match( '/^incoming-[a-z0-9]{12}\.tmp$/', $name );
			if ( $ours && ! in_array( $name, $used, true ) && is_file( (string) $file ) && time() - (int) filemtime( (string) $file ) > self::ORPHAN_AGE ) {
				wp_delete_file( (string) $file );
			}
		}
	}

	/**
	 * Make or update one member's copy, and record the outcome.
	 *
	 * @param int $user_id User id.
	 * @return string The URL now in use ('' for initials).
	 */
	public static function refresh( int $user_id ): string {
		$source = self::source_kind( $user_id );
		if ( 'none' === $source ) {
			self::remove( $user_id ); // The bot without a chosen image: the shipped default, nothing to copy.
			delete_option( self::BOT_STATUS );
			return '';
		}
		$fetch = self::source_bytes( $user_id );
		if ( isset( $fetch['missing'] ) ) {
			self::remove( $user_id ); // No picture (404): initials.
			self::record( $user_id, Avatar_Status::status( $source, 'no_picture', $fetch['missing'] ) );
			return '';
		}
		if ( isset( $fetch['error'] ) ) {
			// A timeout, 5xx or bad answer: keep the copy we have, try again tomorrow.
			$kept = self::url( $user_id );
			self::record( $user_id, Avatar_Status::status( $source, '' !== $kept ? 'kept' : 'failed', $fetch['error'], $fetch['detail'] ?? '' ) );
			return $kept;
		}
		$bytes = $fetch['bytes'];
		$hash  = hash( 'sha256', $bytes );
		$copy  = self::get_copy( $user_id );
		if ( is_array( $copy ) && ( $copy['hash'] ?? '' ) === $hash && '' !== self::file_name( $user_id ) ) {
			self::record( $user_id, Avatar_Status::status( $source, 'unchanged' ) );
			return self::url( $user_id );
		}
		$made = self::write( $bytes );
		if ( isset( $made['error'] ) ) {
			$kept = self::url( $user_id );
			self::record( $user_id, Avatar_Status::status( $source, '' !== $kept ? 'kept' : 'failed', $made['error'], $made['detail'] ?? '' ) );
			return $kept;
		}
		self::remove( $user_id ); // The old file goes once the new one exists.
		self::put_copy(
			$user_id,
			array(
				'file' => $made['file'],
				'hash' => $hash,
			)
		);
		self::record( $user_id, Avatar_Status::status( $source, 'ok', '', strtoupper( pathinfo( $made['file'], PATHINFO_EXTENSION ) ) ) );
		return self::url( $user_id );
	}

	/**
	 * Delete a member's copy.
	 *
	 * @param int $user_id User id.
	 */
	public static function remove( int $user_id ): void {
		$copy = self::get_copy( $user_id );
		if ( is_array( $copy ) && ! empty( $copy['file'] ) ) {
			$file = trailingslashit( self::dir()['path'] ) . basename( (string) $copy['file'] );
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}
		self::drop_copy( $user_id );
	}

	/**
	 * The last refresh of a member (Avatar_Status shape), or null when never refreshed.
	 *
	 * @param int $user_id User id.
	 * @return array{source: string, result: string, reason: string, detail: string, at: int}|null
	 */
	public static function status( int $user_id ): ?array {
		$status = self::BOT === $user_id ? get_option( self::BOT_STATUS ) : get_user_meta( $user_id, self::STATUS_META, true );
		return is_array( $status ) && isset( $status['result'] ) ? Avatar_Status::status( (string) ( $status['source'] ?? '' ), (string) $status['result'], (string) ( $status['reason'] ?? '' ), (string) ( $status['detail'] ?? '' ), (int) ( $status['at'] ?? 0 ) ) : null;
	}

	/**
	 * Where the picture comes from: 'chat' (a member's Media Library chat avatar), 'gravatar', or for
	 * the bot 'media' (the Remy avatar setting) or 'none' (the shipped default).
	 *
	 * @param int $user_id User id, or BOT.
	 */
	public static function source_kind( int $user_id ): string {
		$attachment = self::attachment( $user_id );
		if ( $attachment > 0 && wp_attachment_is_image( $attachment ) ) {
			return self::BOT === $user_id ? 'media' : 'chat';
		}
		return self::BOT === $user_id ? 'none' : 'gravatar';
	}

	/**
	 * The Media Library image chosen for a member (Chat avatar) or the bot (bot_avatar), or 0.
	 *
	 * @param int $user_id User id, or BOT.
	 */
	private static function attachment( int $user_id ): int {
		return self::BOT === $user_id ? (int) Settings::get( 'bot_avatar' ) : (int) get_user_meta( $user_id, Team::META_AVATAR, true );
	}

	/**
	 * A stored copy record ({file, hash}) or ''.
	 *
	 * @param int $user_id User id, or BOT.
	 * @return mixed
	 */
	private static function get_copy( int $user_id ) {
		return self::BOT === $user_id ? get_option( self::BOT_COPY, '' ) : get_user_meta( $user_id, self::META, true );
	}

	/**
	 * Store a copy record.
	 *
	 * @param int                  $user_id User id, or BOT.
	 * @param array<string, mixed> $copy    {file, hash}.
	 */
	private static function put_copy( int $user_id, array $copy ): void {
		if ( self::BOT === $user_id ) {
			update_option( self::BOT_COPY, $copy, false );
		} else {
			update_user_meta( $user_id, self::META, $copy );
		}
	}

	/**
	 * Forget a copy record.
	 *
	 * @param int $user_id User id, or BOT.
	 */
	private static function drop_copy( int $user_id ): void {
		if ( self::BOT === $user_id ) {
			delete_option( self::BOT_COPY );
		} else {
			delete_user_meta( $user_id, self::META );
		}
	}

	/**
	 * The bot avatar for the widget: the copy of the uploaded image, else '' (plan v1.12.1). With ''
	 * the widget draws the default Remy inline from assets/remy-avatar.svg, so it takes the brand
	 * colours; an <img> would not.
	 */
	public static function bot_url(): string {
		return self::url( self::BOT );
	}

	/**
	 * The shipped default bot avatar (an SVG), for the admin preview only.
	 */
	public static function bot_default_url(): string {
		$url = plugins_url( self::BOT_DEFAULT, P21_SIGMA_FILE );
		return is_ssl() ? set_url_scheme( $url, 'https' ) : $url;
	}

	/**
	 * Save a refresh outcome.
	 *
	 * @param int                  $user_id User id.
	 * @param array<string, mixed> $status  Avatar_Status::status().
	 */
	private static function record( int $user_id, array $status ): void {
		if ( self::BOT === $user_id ) {
			update_option( self::BOT_STATUS, $status, false );
		} else {
			update_user_meta( $user_id, self::STATUS_META, $status );
		}
	}

	/**
	 * The source image: the Chat avatar file, else the WordPress avatar fetched with default=404.
	 * ['bytes' => …] on success; ['missing' => reason] when there is no picture (a 404, or no avatar
	 * URL); ['error' => reason, 'detail' => …] when the fetch failed for another reason (timeout, 5xx),
	 * so a good copy is not thrown away.
	 *
	 * @param int $user_id User id.
	 * @return array{bytes: string}|array{missing: string}|array{error: string, detail?: string}
	 */
	private static function source_bytes( int $user_id ): array {
		if ( in_array( self::source_kind( $user_id ), array( 'chat', 'media' ), true ) ) {
			$file = (string) get_attached_file( self::attachment( $user_id ) );
			if ( '' !== $file && is_readable( $file ) ) {
				$bytes = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local upload.
				return false === $bytes || '' === $bytes ? array( 'error' => 'chat_unreadable' ) : array( 'bytes' => $bytes );
			}
			return array( 'error' => 'chat_missing' );
		}
		$url = (string) get_avatar_url(
			$user_id,
			array(
				'size'          => self::SIZE * 2,
				'default'       => '404',
				'force_default' => false,
			)
		);
		if ( '' === $url ) {
			return array( 'missing' => 'no_avatar_url' );
		}
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 10,
				'limit_response_size' => 2 * MB_IN_BYTES, // §10b: never read more than an avatar can be.
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'error'  => 'http_error',
				'detail' => mb_substr( $response->get_error_message(), 0, 120 ),
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $code ) {
			return array( 'missing' => 'http_404' ); // No picture: Gravatar's answer to default=404 instead of the mystery person.
		}
		if ( 200 !== $code ) {
			return array(
				'error'  => 'http_status',
				'detail' => (string) $code,
			);
		}
		$type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		if ( ! str_starts_with( $type, 'image/' ) ) {
			return array(
				'error'  => 'not_image',
				'detail' => mb_substr( (string) strtok( $type, ';' ), 0, 60 ),
			);
		}
		$bytes = (string) wp_remote_retrieve_body( $response );
		if ( '' === $bytes ) {
			return array( 'error' => 'empty' );
		}
		return strlen( $bytes ) > 2 * MB_IN_BYTES ? array( 'error' => 'too_large' ) : array( 'bytes' => $bytes );
	}

	/**
	 * Resize to 96×96 (cropped) and save under a random name: WebP, else PNG. The incoming bytes go
	 * to a temporary file in the avatars folder itself (no wp_tempnam(), which lives in wp-admin).
	 *
	 * @param string $bytes Image bytes.
	 * @return array{file: string}|array{error: string, detail?: string}
	 */
	private static function write( string $bytes ): array {
		$dir = self::dir()['path'];
		if ( ! wp_mkdir_p( $dir ) || ! wp_is_writable( $dir ) ) {
			return array( 'error' => 'folder' );
		}
		self::guard_folder( $dir );
		$tmp = trailingslashit( $dir ) . 'incoming-' . strtolower( wp_generate_password( 12, false ) ) . '.tmp';
		if ( false === file_put_contents( $tmp, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A temp file for the image editor.
			return array( 'error' => 'temp' );
		}
		try {
			$editor = wp_get_image_editor( $tmp );
			if ( is_wp_error( $editor ) ) {
				return array(
					'error'  => 'editor',
					'detail' => $editor->get_error_code(),
				);
			}
			$size = $editor->get_size();
			$side = min( (int) ( $size['width'] ?? 0 ), (int) ( $size['height'] ?? 0 ) );
			if ( $side >= self::SIZE ) {
				$resized = $editor->resize( self::SIZE, self::SIZE, true );
			} elseif ( $side > 0 ) {
				// Smaller than 96 px: WordPress does not upscale, so keep it as a centred square.
				$resized = (int) $size['width'] === (int) $size['height'] ? true : $editor->crop( intdiv( (int) $size['width'] - $side, 2 ), intdiv( (int) $size['height'] - $side, 2 ), $side, $side );
			} else {
				$resized = new \WP_Error( 'no_dimensions' );
			}
			if ( is_wp_error( $resized ) ) {
				return array(
					'error'  => 'editor',
					'detail' => $resized->get_error_code(),
				);
			}
			$name  = strtolower( wp_generate_password( 20, false ) );
			$types = wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ? array( 'webp', 'png' ) : array( 'png' );
			$error = '';
			foreach ( $types as $ext ) {
				$path = trailingslashit( $dir ) . $name . '.' . $ext;
				$save = $editor->save( $path, 'image/' . $ext );
				// An output-format filter (image_editor_output_format) may change the type and the
				// extension: accept any image type the site chose, as long as it is our file.
				$saved = is_wp_error( $save ) || empty( $save['path'] ) ? '' : (string) $save['path'];
				if ( '' !== $saved && dirname( wp_normalize_path( $saved ) ) === wp_normalize_path( untrailingslashit( $dir ) ) && self::valid_name( basename( $saved ) ) && str_starts_with( basename( $saved ), $name . '.' ) && is_readable( $saved ) ) {
					return array( 'file' => basename( $saved ) );
				}
				if ( '' !== $saved && file_exists( $saved ) ) {
					wp_delete_file( $saved );
				}
				$error = is_wp_error( $save ) ? $save->get_error_code() : 'unexpected_path';
			}
			return array(
				'error'  => 'save',
				'detail' => $error,
			);
		} finally {
			wp_delete_file( $tmp );
		}
	}

	/**
	 * An empty index.php so the folder is not listed.
	 *
	 * @param string $dir Folder.
	 */
	private static function guard_folder( string $dir ): void {
		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- One-off folder guard.
		}
	}

	/**
	 * The avatars folder (uploads/delta-assistant/avatars): path and URL.
	 *
	 * @return array{path: string, url: string}
	 */
	public static function dir(): array {
		$uploads = wp_get_upload_dir();
		return array(
			'path' => trailingslashit( (string) $uploads['basedir'] ) . 'delta-assistant/avatars',
			'url'  => trailingslashit( (string) $uploads['baseurl'] ) . 'delta-assistant/avatars',
		);
	}
}
