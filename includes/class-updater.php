<?php
/**
 * Updates from GitHub releases (§19.2), checked by sha256.
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma;

defined( 'ABSPATH' ) || exit;

/**
 * Sigma is not on WordPress.org: its header has `Update URI: https://github.com/21press/sigma`, so
 * WordPress never offers another plugin's update for it, and this class offers the GitHub release.
 *
 * - Source: the latest release of 21press/sigma (drafts and pre-releases ignored), its asset
 *   `sigma-{version}.zip` (never GitHub's source zip, which has no build and the wrong folder name)
 *   and `sigma-{version}.zip.sha256`. The tag's readme.txt gives the requirements and description.
 * - Checked at most every 12 hours. On a rate limit (403/429) or an error, the last good answer is
 *   kept and the next try waits (Retry-After, or 1 hour).
 * - The download is verified: when the zip's sha256 does not match the published one, the update is
 *   aborted before anything is unpacked.
 * - Privacy: only GitHub is contacted, with the User-Agent "Sigma/{version}" (WordPress's default
 *   one includes the site URL); nothing about the site is sent.
 * - Off with SIGMA_DISABLE_UPDATE_CHECK in wp-config.php, or the `sigma_update_check` filter.
 */
final class Updater {

	public const REPO = '21press/sigma';

	public const API = 'https://api.github.com/repos/21press/sigma/releases/latest';

	/** Every Sigma release asset lives under this URL; a package here is always checksum-verified. */
	public const DOWNLOADS = 'https://github.com/21press/sigma/releases/download/';

	/** Site option: the last good release info and when it was fetched. */
	public const CACHE = 'sigma_update_release';

	/** Site transient: don't ask GitHub again before it expires (rate limit or error). */
	public const BACKOFF = 'sigma_update_backoff';

	/** How long a fetched answer stays fresh. */
	public const TTL = 12 * HOUR_IN_SECONDS;

	/** The most the update zip may be (a bigger download is cut off and fails its checksum). */
	public const MAX_ZIP = 20 * MB_IN_BYTES;

	/** Hosts the updater talks to. */
	private const HOSTS = array( 'api.github.com', 'github.com', 'raw.githubusercontent.com', 'objects.githubusercontent.com', 'release-assets.githubusercontent.com' );

	/**
	 * Hooks.
	 */
	public static function hooks(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( self::class, 'inject' ) );
		add_filter( 'plugins_api', array( self::class, 'details' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( self::class, 'pre_download' ), 10, 3 );
		add_filter( 'plugin_row_meta', array( self::class, 'row_meta' ), 10, 2 );
		add_action( 'admin_init', array( self::class, 'check_now' ) );
	}

	/**
	 * Whether updates are checked at all.
	 */
	public static function enabled(): bool {
		if ( Config::get( 'DISABLE_UPDATE_CHECK' ) ) {
			return false;
		}
		/**
		 * Filters whether Sigma checks GitHub for updates.
		 *
		 * @param bool $check Check.
		 */
		return (bool) Hooks::filter( 'update_check', true );
	}

	/**
	 * The plugin's basename (sigma/sigma.php).
	 */
	public static function basename(): string {
		$base = plugin_basename( P21_SIGMA_FILE );
		// Outside the plugins folder (a symlink WordPress can't map, or the test suite): folder/file.
		return 1 === substr_count( $base, '/' ) ? $base : basename( dirname( P21_SIGMA_FILE ) ) . '/' . basename( P21_SIGMA_FILE );
	}

	/**
	 * The latest release, from the cache when fresh, else from GitHub. Null when there is none.
	 *
	 * @param bool $force Ask GitHub now (still not during a backoff).
	 * @return array{version: string, tag: string, url: string, zip: string, sha256_url: string, published: string, body: string, requires: string, requires_php: string, tested: string, description: string}|null
	 */
	public static function release( bool $force = false ): ?array {
		$cache = get_site_option( self::CACHE, array() );
		$cache = is_array( $cache ) ? $cache : array();
		$fresh = isset( $cache['fetched_at'] ) && (int) $cache['fetched_at'] > time() - self::TTL;
		if ( ( $fresh && ! $force ) || get_site_transient( self::BACKOFF ) ) {
			return $cache['release'] ?? null;
		}
		$fetched = self::fetch();
		if ( is_wp_error( $fetched ) ) {
			$data = (array) $fetched->get_error_data();
			set_site_transient( self::BACKOFF, 1, max( 15 * MINUTE_IN_SECONDS, min( 6 * HOUR_IN_SECONDS, (int) ( $data['retry_after'] ?? HOUR_IN_SECONDS ) ) ) );
			return $cache['release'] ?? null;
		}
		update_site_option(
			self::CACHE,
			array(
				'release'    => $fetched,
				'fetched_at' => time(),
			)
		);
		return $fetched;
	}

	/**
	 * Ask GitHub for the latest release and read its readme.txt.
	 *
	 * @return array<string, string>|null|\WP_Error Null when there is no usable release.
	 */
	public static function fetch() {
		$response = wp_safe_remote_get( self::API, self::args( array( 'Accept' => 'application/vnd.github+json' ) ) );
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'sigma_update_http', $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $code ) {
			return null; // No release yet.
		}
		if ( 200 !== $code ) {
			$retry = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			$reset = (int) wp_remote_retrieve_header( $response, 'x-ratelimit-reset' );
			return new \WP_Error(
				'sigma_update_http',
				sprintf( 'GitHub answered %d', $code ),
				array( 'retry_after' => $retry > 0 ? $retry : ( $reset > time() ? $reset - time() : HOUR_IN_SECONDS ) )
			);
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'sigma_update_http', 'Not JSON' );
		}
		$release = self::parse( $data );
		if ( null === $release ) {
			return null;
		}
		$readme = wp_safe_remote_get( 'https://raw.githubusercontent.com/' . self::REPO . '/' . rawurlencode( $release['tag'] ) . '/readme.txt', self::args() );
		if ( ! is_wp_error( $readme ) && 200 === (int) wp_remote_retrieve_response_code( $readme ) ) {
			$release = array_merge( $release, self::parse_readme( (string) wp_remote_retrieve_body( $readme ) ) );
		}
		return $release;
	}

	/**
	 * A usable release from GitHub's JSON, or null (draft, pre-release, bad tag, missing assets). Pure.
	 *
	 * @param array<string, mixed> $data Release JSON.
	 * @return array<string, string>|null
	 */
	public static function parse( array $data ): ?array {
		if ( ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
			return null;
		}
		$tag     = (string) ( $data['tag_name'] ?? '' );
		$version = ltrim( $tag, 'vV' );
		if ( ! preg_match( '/^\d+\.\d+(\.\d+)?$/', $version ) ) {
			return null;
		}
		$zip    = '';
		$sha256 = '';
		foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
			$name = is_array( $asset ) ? (string) ( $asset['name'] ?? '' ) : '';
			$url  = is_array( $asset ) ? (string) ( $asset['browser_download_url'] ?? '' ) : '';
			if ( ! self::is_github_url( $url ) ) {
				continue;
			}
			if ( 'sigma-' . $version . '.zip' === $name ) {
				$zip = $url;
			} elseif ( 'sigma-' . $version . '.zip.sha256' === $name ) {
				$sha256 = $url;
			}
		}
		if ( '' === $zip || '' === $sha256 ) {
			return null; // Without the checksum there is no update (§19.2: checksum only).
		}
		$url = (string) ( $data['html_url'] ?? '' );
		return array(
			'version'      => $version,
			'tag'          => $tag,
			'url'          => self::is_github_url( $url ) ? $url : 'https://github.com/' . self::REPO . '/releases',
			'zip'          => $zip,
			'sha256_url'   => $sha256,
			'published'    => (string) ( $data['published_at'] ?? '' ),
			'body'         => mb_substr( (string) ( $data['body'] ?? '' ), 0, 65536 ),
			'requires'     => '',
			'requires_php' => '',
			'tested'       => '',
			'description'  => '',
		);
	}

	/**
	 * Requirements and the description from a readme.txt. Pure.
	 *
	 * @param string $readme readme.txt.
	 * @return array{requires: string, requires_php: string, tested: string, description: string}
	 */
	public static function parse_readme( string $readme ): array {
		$field       = static function ( string $name ) use ( $readme ): string {
			return preg_match( '/^' . preg_quote( $name, '/' ) . ':\s*([0-9.]+)\s*$/mi', $readme, $m ) ? $m[1] : '';
		};
		$description = preg_match( '/==\s*Description\s*==\s*\n(.*?)(?=\n==\s|\z)/s', $readme, $m ) ? trim( $m[1] ) : '';
		return array(
			'requires'     => $field( 'Requires at least' ),
			'requires_php' => $field( 'Requires PHP' ),
			'tested'       => $field( 'Tested up to' ),
			'description'  => mb_substr( $description, 0, 20000 ),
		);
	}

	/**
	 * Whether this site meets a release's requirements.
	 *
	 * @param array<string, string> $release Release.
	 */
	public static function compatible( array $release ): bool {
		global $wp_version;
		if ( '' !== $release['requires'] && version_compare( (string) $wp_version, $release['requires'], '<' ) ) {
			return false;
		}
		return '' === $release['requires_php'] || version_compare( PHP_VERSION, $release['requires_php'], '>=' );
	}

	/**
	 * `pre_set_site_transient_update_plugins`: offer the release when it is newer and compatible.
	 * Listed under no_update otherwise, so the auto-update toggle shows.
	 *
	 * @param mixed $transient The update_plugins transient.
	 * @return mixed
	 */
	public static function inject( $transient ) {
		if ( ! is_object( $transient ) || ! self::enabled() ) {
			return $transient;
		}
		$release = self::release();
		if ( null === $release ) {
			return $transient;
		}
		$file  = self::basename();
		$entry = (object) array(
			'id'            => 'github.com/' . self::REPO,
			'slug'          => 'sigma',
			'plugin'        => $file,
			'new_version'   => $release['version'],
			'url'           => $release['url'],
			'package'       => $release['zip'],
			'requires'      => $release['requires'],
			'requires_php'  => $release['requires_php'],
			'tested'        => $release['tested'],
			'icons'         => array(),
			'banners'       => array(),
			'compatibility' => new \stdClass(),
		);
		if ( version_compare( $release['version'], P21_SIGMA_VERSION, '>' ) && self::compatible( $release ) ) {
			$transient->response          = is_array( $transient->response ?? null ) ? $transient->response : array();
			$transient->response[ $file ] = $entry;
			unset( $transient->no_update[ $file ] );
		} else {
			$entry->new_version            = P21_SIGMA_VERSION;
			$entry->package                = '';
			$transient->no_update          = is_array( $transient->no_update ?? null ) ? $transient->no_update : array();
			$transient->no_update[ $file ] = $entry;
			unset( $transient->response[ $file ] );
		}
		return $transient;
	}

	/**
	 * `plugins_api`: the "View details" modal for Sigma.
	 *
	 * @param mixed  $result Result so far.
	 * @param string $action API action.
	 * @param object $args   Arguments (slug).
	 * @return mixed
	 */
	public static function details( $result, $action, $args ) {
		// Only the "View details" of this installed plugin (sigma.php, Update URI on GitHub). The search list
		// (query_plugins) is never touched. A details request for slug `sigma` from a WordPress.org search result looks
		// the same as ours; the slug is free on WordPress.org, so there is no other plugin to stand in for.
		if ( 'plugin_information' !== $action || ! is_object( $args ) || 'sigma' !== ( $args->slug ?? '' ) || 'sigma.php' !== basename( self::basename() ) ) {
			return $result;
		}
		$release = self::release();
		if ( null === $release ) {
			return $result;
		}
		return (object) array(
			'name'          => 'Sigma',
			'slug'          => 'sigma',
			'version'       => $release['version'],
			'author'        => '<a href="https://21press.com">21press</a>',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => $release['requires'],
			'requires_php'  => $release['requires_php'],
			'tested'        => $release['tested'],
			'last_updated'  => $release['published'],
			'download_link' => $release['zip'],
			'sections'      => array(
				'description' => wp_kses_post( self::markdown( $release['description'] ) ),
				'changelog'   => wp_kses_post( self::markdown( $release['body'] ) ),
			),
		);
	}

	/**
	 * Whether a package URL is one of Sigma's own release assets: parsed, never prefix-matched on the raw string.
	 * https, host github.com, no user info, port, query or fragment, and exactly
	 * /21press/sigma/releases/download/<tag>/sigma-<version>.zip. Any `..`, backslash or percent-encoded dot, slash or
	 * backslash is refused before parsing, so no path can climb out of the releases folder.
	 *
	 * @param mixed $package Package URL.
	 */
	public static function is_own_package( $package ): bool {
		if ( ! is_string( $package ) || '' === $package || str_contains( $package, '..' ) || str_contains( $package, '\\' ) || 1 === preg_match( '/%(2e|2f|5c)/i', $package ) ) {
			return false;
		}
		$parts = wp_parse_url( $package );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || 'github.com' !== strtolower( (string) ( $parts['host'] ?? '' ) ) ) {
			return false;
		}
		foreach ( array( 'user', 'pass', 'port', 'query', 'fragment' ) as $part ) {
			if ( isset( $parts[ $part ] ) ) {
				return false;
			}
		}
		return 1 === preg_match( '#^/' . preg_quote( self::REPO, '#' ) . '/releases/download/[A-Za-z0-9._-]+/sigma-[0-9][A-Za-z0-9._-]*\.zip$#', (string) ( $parts['path'] ?? '' ) );
	}

	/**
	 * `upgrader_pre_download`: download Sigma's zip and its .sha256, and abort on any mismatch. Every package under
	 * Sigma's releases URL is verified, whatever the cached release says (the update transient and the release
	 * cache can expire apart, or a release can be published before its .sha256): never installed unverified.
	 *
	 * @param mixed  $reply   False to let WordPress download.
	 * @param string $package Package URL.
	 * @param mixed  $upgrader Upgrader.
	 * @return mixed A local file path, a WP_Error, or $reply for other packages.
	 */
	public static function pre_download( $reply, $package, $upgrader = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The filter's signature.
		if ( false !== $reply || ! self::is_own_package( $package ) ) {
			return $reply;
		}
		$release    = self::release();
		$sha256_url = ( null !== $release && $package === $release['zip'] ) ? $release['sha256_url'] : $package . '.sha256';
		$version    = (string) preg_replace( '/^sigma-|\.zip$/', '', basename( (string) wp_parse_url( $package, PHP_URL_PATH ) ) );
		$sums       = wp_safe_remote_get( $sha256_url, self::args() );
		if ( is_wp_error( $sums ) || 200 !== (int) wp_remote_retrieve_response_code( $sums ) ) {
			return new \WP_Error( 'sigma_checksum_missing', __( 'Sigma could not download the checksum of the update, so it was not installed. Try again later.', 'sigma' ) );
		}
		$want = strtolower( (string) preg_replace( '/\s.*$/s', '', trim( (string) wp_remote_retrieve_body( $sums ) ) ) );
		if ( ! preg_match( '/^[0-9a-f]{64}$/', $want ) ) {
			return new \WP_Error( 'sigma_checksum_missing', __( 'The checksum file of the Sigma update is not valid, so it was not installed.', 'sigma' ) );
		}
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php'; // Loaded by the upgrader already; cron updates too.
		}
		$file = wp_tempnam( 'sigma-' . sanitize_file_name( $version ) . '.zip' );
		$zip  = wp_safe_remote_get(
			$package,
			self::args(
				array(),
				array(
					'timeout'             => 300,
					'stream'              => true,
					'filename'            => $file,
					'limit_response_size' => self::MAX_ZIP,
				)
			)
		);
		if ( is_wp_error( $zip ) || 200 !== (int) wp_remote_retrieve_response_code( $zip ) ) {
			wp_delete_file( $file );
			return new \WP_Error( 'sigma_download_failed', __( 'The Sigma update could not be downloaded. Try again later.', 'sigma' ) );
		}
		$got = (string) hash_file( 'sha256', $file );
		if ( ! hash_equals( $want, $got ) ) {
			wp_delete_file( $file );
			return new \WP_Error( 'sigma_checksum_mismatch', __( 'The Sigma update does not match its published checksum, so it was not installed. Nothing on your site was changed.', 'sigma' ) );
		}
		return $file;
	}

	/**
	 * "Check for updates" under Sigma on the Plugins screen.
	 *
	 * @param string[] $meta Row links.
	 * @param string   $file Plugin file.
	 * @return string[]
	 */
	public static function row_meta( $meta, $file ): array {
		$meta = (array) $meta;
		if ( self::basename() === $file && self::enabled() && current_user_can( 'update_plugins' ) ) {
			$meta[] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( wp_nonce_url( self_admin_url( 'plugins.php?sigma_check_updates=1' ), 'sigma_check_updates' ) ),
				esc_html__( 'Check for updates', 'sigma' )
			);
		}
		return $meta;
	}

	/**
	 * Handle "Check for updates": forget the cache and the backoff, and ask again.
	 */
	public static function check_now(): void {
		if ( empty( $_GET['sigma_check_updates'] ) || ! current_user_can( 'update_plugins' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified next.
			return;
		}
		check_admin_referer( 'sigma_check_updates' );
		delete_site_option( self::CACHE );
		delete_site_transient( self::BACKOFF );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		wp_safe_redirect( self_admin_url( 'plugins.php' ) );
		exit;
	}

	/**
	 * Request arguments: Sigma's own User-Agent (never the site URL), a short timeout.
	 *
	 * @param array<string, string> $headers Extra headers.
	 * @param array<string, mixed>  $extra   Extra arguments.
	 * @return array<string, mixed>
	 */
	public static function args( array $headers = array(), array $extra = array() ): array {
		return array_merge(
			array(
				'timeout'             => 10,
				'redirection'         => 5,
				'user-agent'          => 'Sigma/' . P21_SIGMA_VERSION,
				'headers'             => $headers,
				'limit_response_size' => 1024 * KB_IN_BYTES,
			),
			$extra
		);
	}

	/**
	 * Whether a URL is an https GitHub URL. Pure.
	 *
	 * @param string $url URL.
	 */
	public static function is_github_url( string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return 'https' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) && in_array( $host, self::HOSTS, true );
	}

	/**
	 * A little Markdown (headings, lists, bold, code, links, paragraphs) as HTML; the caller runs
	 * wp_kses_post() on it. Text is escaped first, so the source can't inject markup. Pure.
	 *
	 * @param string $text Markdown.
	 */
	public static function markdown( string $text ): string {
		$html   = '';
		$list   = false;
		$inline = static function ( string $line ): string {
			$line = esc_html( $line );
			$line = (string) preg_replace( '/`([^`]+)`/', '<code>$1</code>', $line );
			$line = (string) preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $line );
			return (string) preg_replace_callback(
				'/\[([^\]]+)\]\((https:\/\/[^)\s]+)\)/',
				static fn( array $m ): string => '<a href="' . esc_url( html_entity_decode( $m[2] ) ) . '">' . $m[1] . '</a>',
				$line
			);
		};
		foreach ( preg_split( '/\r?\n/', $text ) as $line ) {
			$line = rtrim( (string) $line );
			if ( preg_match( '/^\s*[-*]\s+(.*)$/', $line, $m ) ) {
				$html .= ( $list ? '' : '<ul>' ) . '<li>' . $inline( $m[1] ) . '</li>';
				$list  = true;
				continue;
			}
			if ( $list ) {
				$html .= '</ul>';
				$list  = false;
			}
			if ( preg_match( '/^(#{1,6})\s+(.*)$/', $line, $m ) ) {
				$html .= '<h4>' . $inline( $m[2] ) . '</h4>';
			} elseif ( '' !== trim( $line ) ) {
				$html .= '<p>' . $inline( $line ) . '</p>';
			}
		}
		return $html . ( $list ? '</ul>' : '' );
	}
}
