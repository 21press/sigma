<?php
/**
 * Stale or missing built files (0.2.5).
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The admin screens and the chat box run from build/, which git does not track. `npm run build` writes
 * build/version.txt ("{version} {git short sha}"). A release zip always matches; a git checkout whose
 * build is missing, from another version, or older than its sources gets a notice on Sigma's screens,
 * instead of a screen that never loads.
 */
final class Build_Check {

	/** Written by scripts/build-version.mjs. */
	public const VERSION_FILE = 'build/version.txt';

	/** The built files the plugin needs. */
	private const REQUIRED = array( 'build/admin/admin.js', 'build/widget/widget.js' );

	/** The app sources, present only in a git checkout (export-ignored from the zip). */
	private const SOURCES = array( 'apps/admin/src', 'apps/widget/src' );

	/**
	 * Hooks.
	 */
	public static function hooks(): void {
		add_action( 'admin_notices', array( self::class, 'notice' ) );
	}

	/**
	 * What is wrong with the build, if anything.
	 *
	 * @param string $dir     Plugin folder.
	 * @param string $version The plugin's version.
	 * @return array{problem: string, built: string} problem: '' (fine), 'missing' or 'stale'; built: the
	 *                                               version.txt line, '' when there is none.
	 */
	public static function check( string $dir = P21_SIGMA_DIR, string $version = P21_SIGMA_VERSION ): array {
		foreach ( self::REQUIRED as $file ) {
			if ( ! is_readable( $dir . '/' . $file ) ) {
				return array(
					'problem' => 'missing',
					'built'   => '',
				);
			}
		}
		$stamp = $dir . '/' . self::VERSION_FILE;
		$built = is_readable( $stamp ) ? trim( (string) file_get_contents( $stamp ) ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file.
		$built = (string) strtok( $built, "\r\n" );
		if ( '' === $built || strtok( $built, ' ' ) !== $version ) {
			return array(
				'problem' => 'stale',
				'built'   => $built,
			);
		}
		$stamped = (int) filemtime( $stamp );
		foreach ( self::SOURCES as $source ) {
			if ( self::newest( $dir . '/' . $source ) > $stamped ) {
				return array(
					'problem' => 'stale',
					'built'   => $built,
				);
			}
		}
		return array(
			'problem' => '',
			'built'   => $built,
		);
	}

	/**
	 * The classic admin notice on Sigma's screens, only when the app's files are missing (nothing else can
	 * show it then). A stale build is reported inside the app (Admin\Notices).
	 */
	public static function notice(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Which screen this is; no action.
		if ( ! in_array( $page, array( Admin_Page::SLUG, Admin_Page::KNOWLEDGE, Admin_Page::CONVERSATIONS ), true ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$check = self::check();
		if ( 'missing' !== $check['problem'] ) {
			return; // Stale: the app runs and shows it in its content column (Notices). Missing: no app to show it.
		}
		$detail = 'missing' === $check['problem']
			? __( 'The build folder is missing or incomplete.', 'sigma' )
			: ( '' === $check['built']
				/* translators: %s: the plugin's version. */
				? sprintf( __( 'They have no version stamp; the plugin is %s.', 'sigma' ), P21_SIGMA_VERSION )
				/* translators: 1: the version (and commit) the files were built from, 2: the plugin's version. */
				: sprintf( __( 'They were built from %1$s; the plugin is %2$s.', 'sigma' ), $check['built'], P21_SIGMA_VERSION ) );
		printf(
			'<div class="notice notice-error sigma-build-notice"><p><strong>%1$s</strong> %2$s</p><p>%3$s</p></div>',
			esc_html__( 'The plugin\'s built files are out of date or missing, so Sigma\'s screens and chat box may not work.', 'sigma' ),
			esc_html( $detail ),
			wp_kses( __( 'If you installed Sigma from git, run <code>npm run build</code> in the plugin folder. Otherwise, reinstall the plugin from its release zip.', 'sigma' ), array( 'code' => array() ) )
		);
	}

	/**
	 * The newest modification time under a folder (0 when it does not exist).
	 *
	 * @param string $path Folder.
	 */
	private static function newest( string $path ): int {
		if ( ! is_dir( $path ) ) {
			return 0;
		}
		$newest = 0;
		$files  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			$newest = max( $newest, (int) $file->getMTime() );
		}
		return $newest;
	}
}
