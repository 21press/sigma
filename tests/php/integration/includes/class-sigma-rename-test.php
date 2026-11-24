<?php
/**
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Tests\Integration;

use TwentyOnePress\Sigma\Hooks;
use TwentyOnePress\Sigma\Plugin;
use TwentyOnePress\Sigma\Settings;
use TwentyOnePress\Sigma\Switch_Over;
use TwentyOnePress\Sigma\Tests\Support\Integration_Test_Case;
use TwentyOnePress\Sigma\Updater;

/**
 * Sigma 0.2.5 (§19): the renamed hooks, the switch-over from Delta Assistant, and updates from GitHub.
 *
 * @covers \TwentyOnePress\Sigma\Hooks
 * @covers \TwentyOnePress\Sigma\Switch_Over
 * @covers \TwentyOnePress\Sigma\Updater
 */
final class Sigma_Rename_Test extends Integration_Test_Case {

	public function tear_down(): void {
		delete_site_option( Updater::CACHE );
		delete_site_transient( Updater::BACKOFF );
		parent::tear_down();
	}

	// ----- Hooks (§19.1) -------------------------------------------------------------------------

	public function test_new_filter_names_work_and_old_ones_still_do_with_a_deprecation_notice(): void {
		add_filter( 'sigma_product_name', static fn(): string => 'New name' );
		$this->assertSame( 'New name', Hooks::filter( 'product_name', 'Default' ) );
		remove_all_filters( 'sigma_product_name' );

		$this->setExpectedDeprecated( 'delta_assistant_product_name' );
		add_filter( 'delta_assistant_product_name', static fn(): string => 'Old name' );
		$this->assertSame( 'Old name', Hooks::filter( 'product_name', 'Default' ), 'the old name still applies in 0.2.x' );
		add_filter( 'sigma_product_name', static fn( string $v ): string => $v . ' + new' );
		$this->assertSame( 'Old name + new', Hooks::filter( 'product_name', 'Default' ), 'the new name runs last' );
	}

	public function test_the_rest_namespace_and_admin_slugs_are_sigma(): void {
		$routes = array_keys( rest_get_server()->get_routes() );
		$this->assertContains( '/sigma/v1/config', $routes );
		$this->assertEmpty( preg_grep( '#^/delta-assistant/#', $routes ), 'no old namespace' );
		$this->assertSame( 'sigma', \TwentyOnePress\Sigma\Admin\Admin_Page::SLUG );
		$this->assertStringEndsWith( '/sigma.php', Updater::basename(), 'folder/sigma.php (sigma/sigma.php when installed)' );
		$headers = get_file_data(
			P21_SIGMA_FILE,
			array(
				'name'   => 'Plugin Name',
				'uri'    => 'Update URI',
				'domain' => 'Text Domain',
			)
		);
		$this->assertSame(
			array(
				'name'   => 'Sigma',
				'uri'    => 'https://github.com/21press/sigma',
				'domain' => 'sigma',
			),
			$headers
		);
	}

	// ----- Switch-over (§19.1) -------------------------------------------------------------------

	public function test_activating_sigma_deactivates_delta_assistant_with_a_notice(): void {
		update_option( 'active_plugins', array( Switch_Over::OLD_PLUGIN, 'akismet/akismet.php' ) );
		Switch_Over::on_activate();
		$this->assertSame( array( 'akismet/akismet.php' ), array_values( (array) get_option( 'active_plugins' ) ) );
		$this->assertNotFalse( get_transient( Switch_Over::NOTICE ) );
		ob_start();
		Switch_Over::notices();
		$this->assertStringContainsString( 'Delta Assistant was deactivated', (string) ob_get_clean() );
		$this->assertFalse( get_transient( Switch_Over::NOTICE ), 'shown once' );

		Switch_Over::on_activate();
		$this->assertFalse( get_transient( Switch_Over::NOTICE ), 'nothing to do when it is not active' );
	}

	/**
	 * Delete Delta Assistant 0.2.1 the way wp-admin or WP-CLI does, around its real uninstall.php, and
	 * check that everything Sigma uses is back.
	 *
	 * @param bool $wp_cli WP-CLI's `wp plugin uninstall`: no `deleted_plugin`, only the end of the request.
	 */
	private function delete_old_plugin_and_check( bool $wp_cli ): void {
		$this->settings(
			array(
				'anthropic_key'       => 'sk-ant-switch-0000000000000000000000000000000000000000-AbC1',
				'smtp_password'       => 'smtp-pass-kept',
				'delete_on_uninstall' => true,
				'bot_name'            => 'Nova',
			)
		);
		update_option( \TwentyOnePress\Sigma\Security\Turnstile::TESTED_OPTION, array( 'ok' => true ) );
		[ $uuid ] = $this->conversation();
		$entry    = \TwentyOnePress\Sigma\Knowledge\Knowledge_Base::create( 'Kept entry', 'Kept text.', true );
		$before   = get_option( Settings::OPTION );

		do_action( 'pre_uninstall_plugin', Switch_Over::OLD_PLUGIN, array() );
		$this->assertFalse( get_option( Settings::OPTION )['delete_on_uninstall'], 'its uninstall must not drop the shared data' );
		$this->assertSame( 0, has_action( 'shutdown', array( Switch_Over::class, 'restore' ) ), 'restored at the end of the request too' );
		try {
			if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
				define( 'WP_UNINSTALL_PLUGIN', Switch_Over::OLD_PLUGIN );
			}
			require dirname( __DIR__, 3 ) . '/fixtures/delta-assistant-0.2.1/uninstall.php';
			$this->assertArrayNotHasKey( 'anthropic_key_enc', (array) get_option( Settings::OPTION ), 'its uninstall removed the keys' );
			$this->assertFalse( get_option( \TwentyOnePress\Sigma\Security\Turnstile::TESTED_OPTION ), 'and the Turnstile check' );
			if ( ! $wp_cli ) {
				do_action( 'deleted_plugin', Switch_Over::OLD_PLUGIN, true );
			}
			Switch_Over::restore(); // The shutdown action: WP-CLI's only restore, a no-op after wp-admin's.
		} finally {
			remove_action( 'shutdown', array( Switch_Over::class, 'restore' ), 0 );
			get_role( 'administrator' )->add_cap( Plugin::CAP_VIEW );
		}

		Settings::flush();
		$this->assertSame( $before, get_option( Settings::OPTION ), 'every setting back, keys and "Delete data" included' );
		$this->assertSame( 'sk-ant-switch-0000000000000000000000000000000000000000-AbC1', Settings::api_key( 'anthropic' ) );
		$this->assertSame( 'smtp-pass-kept', Settings::smtp_password() );
		$this->assertSame( array( 'ok' => true ), get_option( \TwentyOnePress\Sigma\Security\Turnstile::TESTED_OPTION ), 'the Turnstile check kept' );
		$this->assertNotNull( ( new \TwentyOnePress\Sigma\Conversations\Repository() )->find_by_uuid( $uuid ), 'conversations kept' );
		$this->assertNotNull( get_post( $entry ), 'knowledge kept' );
		$this->assertFalse( get_option( Switch_Over::SNAPSHOT ), 'the snapshot is cleared' );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Plugin::CAP_VIEW ) );
		$this->assertNotFalse( wp_next_scheduled( Plugin::CRON_PURGE ), 'the daily purge is scheduled again' );
	}

	public function test_deleting_delta_assistant_in_wp_admin_keeps_the_keys_and_the_data_with_its_real_uninstall(): void {
		$this->delete_old_plugin_and_check( false );
	}

	public function test_wp_cli_plugin_uninstall_of_delta_assistant_keeps_the_keys_and_the_data_too(): void {
		$this->delete_old_plugin_and_check( true );
	}

	public function test_other_plugins_uninstalls_are_left_alone(): void {
		$this->settings( array( 'bot_name' => 'Nova' ) );
		do_action( 'pre_uninstall_plugin', 'akismet/akismet.php', array() );
		$this->assertFalse( get_option( Switch_Over::SNAPSHOT ) );
		do_action( 'deleted_plugin', 'akismet/akismet.php', true );
		$this->assertSame( 'Nova', Settings::get( 'bot_name' ) );
	}

	// ----- Updater (§19.2) -----------------------------------------------------------------------

	/**
	 * A GitHub release JSON.
	 *
	 * @param string $version Version.
	 * @param array  $extra   Fields.
	 */
	private static function release_json( string $version, array $extra = array() ): array {
		return $extra + array(
			'tag_name'     => 'v' . $version,
			'draft'        => false,
			'prerelease'   => false,
			'html_url'     => 'https://github.com/21press/sigma/releases/tag/v' . $version,
			'published_at' => '2026-12-01T10:00:00Z',
			'body'         => "## Changes\n- **New:** things <script>alert(1)</script>\n- [Docs](https://github.com/21press/sigma)",
			'assets'       => array(
				array(
					'name'                 => 'sigma-' . $version . '.zip',
					'browser_download_url' => 'https://github.com/21press/sigma/releases/download/v' . $version . '/sigma-' . $version . '.zip',
				),
				array(
					'name'                 => 'sigma-' . $version . '.zip.sha256',
					'browser_download_url' => 'https://github.com/21press/sigma/releases/download/v' . $version . '/sigma-' . $version . '.zip.sha256',
				),
				array(
					'name'                 => 'Source code (zip)',
					'browser_download_url' => 'https://api.github.com/repos/21press/sigma/zipball/v' . $version,
				),
			),
		);
	}

	/**
	 * Answer GitHub: the release, its readme.txt, the zip and the checksum.
	 *
	 * @param array|int $release Release JSON, or an HTTP status.
	 * @param string    $readme  readme.txt.
	 * @param string    $zip     Zip bytes.
	 * @param string    $sha     The published checksum.
	 */
	private function github( $release, string $readme = "Requires at least: 6.9\nRequires PHP: 8.2\nTested up to: 7.1\n\n== Description ==\nA chat box.\n", string $zip = 'ZIPBYTES', string $sha = '' ): void {
		$sha = '' !== $sha ? $sha : hash( 'sha256', $zip );
		$this->answer_http(
			static function ( string $url, array $args ) use ( $release, $readme, $zip, $sha ) {
				if ( Updater::API === $url ) {
					return is_int( $release ) ? array(
						'headers'  => array( 'retry-after' => '1800' ),
						'body'     => '{"message":"API rate limit exceeded"}',
						'response' => array( 'code' => $release ),
						'cookies'  => array(),
					) : self::json( 200, $release );
				}
				if ( str_ends_with( $url, '/readme.txt' ) ) {
					return array(
						'headers'  => array(),
						'body'     => $readme,
						'response' => array( 'code' => 200 ),
						'cookies'  => array(),
					);
				}
				if ( str_ends_with( $url, '.zip.sha256' ) ) {
					return array(
						'headers'  => array(),
						'body'     => $sha . "  sigma.zip\n",
						'response' => array( 'code' => 200 ),
						'cookies'  => array(),
					);
				}
				if ( str_ends_with( $url, '.zip' ) ) {
					if ( ! empty( $args['filename'] ) ) {
						file_put_contents( $args['filename'], $zip ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
					}
					return array(
						'headers'  => array(),
						'body'     => '',
						'response' => array( 'code' => 200 ),
						'cookies'  => array(),
					);
				}
				return new \WP_Error( 'unexpected', $url );
			}
		);
	}

	private function check(): object {
		return Updater::inject(
			(object) array(
				'response'  => array(),
				'no_update' => array(),
				'checked'   => array(),
			)
		);
	}

	public function test_a_newer_release_is_offered_with_its_asset_zip_never_the_source_zip(): void {
		$this->github( self::release_json( '9.0.0' ) );
		$update = $this->check()->response[ Updater::basename() ] ?? null;
		$this->assertNotNull( $update );
		$this->assertSame( '9.0.0', $update->new_version );
		$this->assertSame( 'https://github.com/21press/sigma/releases/download/v9.0.0/sigma-9.0.0.zip', $update->package );
		$this->assertSame( array( '6.9', '8.2', '7.1' ), array( $update->requires, $update->requires_php, $update->tested ) );
		foreach ( $this->http as $request ) {
			$this->assertSame( 'Sigma/' . P21_SIGMA_VERSION, $request['args']['user-agent'], 'no site URL in the User-Agent' );
			$this->assertTrue( Updater::is_github_url( $request['url'] ), 'only GitHub is contacted' );
		}
	}

	public function test_the_same_or_an_older_version_is_not_offered_but_keeps_the_auto_update_toggle(): void {
		foreach ( array( P21_SIGMA_VERSION, '0.0.1' ) as $version ) {
			delete_site_option( Updater::CACHE );
			remove_all_filters( 'pre_http_request' );
			$this->github( self::release_json( $version ) );
			$result = $this->check();
			$this->assertArrayNotHasKey( Updater::basename(), $result->response, $version );
			$this->assertSame( P21_SIGMA_VERSION, $result->no_update[ Updater::basename() ]->new_version, $version );
		}
	}

	public function test_pre_releases_drafts_and_releases_without_a_checksum_are_ignored(): void {
		foreach ( array( array( 'prerelease' => true ), array( 'draft' => true ), array( 'assets' => array_slice( self::release_json( '9.0.0' )['assets'], 0, 1 ) ) ) as $i => $extra ) {
			delete_site_option( Updater::CACHE );
			remove_all_filters( 'pre_http_request' );
			$this->github( self::release_json( '9.0.0', $extra ) );
			$this->assertSame( array(), $this->check()->response, "case {$i}" );
		}
	}

	public function test_a_release_whose_requirements_are_not_met_is_not_offered(): void {
		$this->github( self::release_json( '9.0.0' ), "Requires at least: 6.9\nRequires PHP: 99.0\n" );
		$this->assertSame( array(), $this->check()->response );
	}

	public function test_the_release_is_cached_for_12_hours(): void {
		$this->github( self::release_json( '9.0.0' ) );
		$this->check();
		$calls = count( $this->http );
		$this->check();
		$this->assertCount( $calls, $this->http, 'no second request within 12 hours' );
	}

	public function test_a_rate_limit_keeps_the_last_good_answer_and_backs_off(): void {
		$this->github( self::release_json( '9.0.0' ) );
		$this->check();
		update_site_option(
			Updater::CACHE,
			array(
				'release'    => get_site_option( Updater::CACHE )['release'],
				'fetched_at' => time() - 13 * HOUR_IN_SECONDS,
			)
		);
		remove_all_filters( 'pre_http_request' );
		$this->http = array();
		$this->github( 403 );
		$this->assertSame( '9.0.0', $this->check()->response[ Updater::basename() ]->new_version, 'the last good result is kept' );
		$this->assertNotFalse( get_site_transient( Updater::BACKOFF ) );
		$calls = count( $this->http );
		$this->check();
		$this->assertCount( $calls, $this->http, 'no request during the backoff' );
	}

	public function test_a_checksum_mismatch_aborts_the_update_and_a_match_hands_over_the_file(): void {
		$this->github( self::release_json( '9.0.0' ), "Requires PHP: 8.2\n", 'GOODZIP', str_repeat( 'a', 64 ) );
		$package = Updater::release( true )['zip'];
		$result  = Updater::pre_download( false, $package );
		$this->assertWPError( $result );
		$this->assertSame( 'sigma_checksum_mismatch', $result->get_error_code() );

		delete_site_option( Updater::CACHE );
		remove_all_filters( 'pre_http_request' );
		$this->github( self::release_json( '9.0.0' ), "Requires PHP: 8.2\n", 'GOODZIP' );
		$limits = array();
		add_filter(
			'http_request_args',
			static function ( array $args, string $url ) use ( &$limits ): array {
				$limits[ basename( $url ) ] = $args['limit_response_size'] ?? null;
				return $args;
			},
			10,
			2
		);
		$file = Updater::pre_download( false, Updater::release( true )['zip'] );
		$this->assertSame( Updater::MAX_ZIP, $limits['sigma-9.0.0.zip'], 'the zip has its own size cap, above the 1 MB API one' );
		$this->assertSame( 1024 * KB_IN_BYTES, $limits['sigma-9.0.0.zip.sha256'] );
		$this->assertIsString( $file );
		$this->assertSame( 'GOODZIP', file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		wp_delete_file( $file );

		$this->assertFalse( Updater::pre_download( false, 'https://downloads.wordpress.org/plugin/akismet.zip' ), 'other packages untouched' );
	}

	/**
	 * Answer GitHub for a package that is not the cached release: the given checksum file (or a 404) and the zip.
	 *
	 * @param string|null $sha  The .sha256 body, or null for a 404.
	 * @param string      $zip  Zip bytes.
	 */
	private function assets( ?string $sha, string $zip ): void {
		remove_all_filters( 'pre_http_request' );
		$this->answer_http(
			static function ( string $url, array $args ) use ( $sha, $zip ) {
				if ( str_ends_with( $url, '.zip.sha256' ) ) {
					return null === $sha ? self::json( 404, array( 'message' => 'Not Found' ) ) : array(
						'headers'  => array(),
						'body'     => $sha . "  sigma.zip\n",
						'response' => array( 'code' => 200 ),
						'cookies'  => array(),
					);
				}
				if ( str_ends_with( $url, '.zip' ) ) {
					if ( ! empty( $args['filename'] ) ) {
						file_put_contents( $args['filename'], $zip ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
					}
					return array(
						'headers'  => array(),
						'body'     => '',
						'response' => array( 'code' => 200 ),
						'cookies'  => array(),
					);
				}
				return self::json( 404, array( 'message' => 'Not Found' ) ); // The API: no release cached or fetchable.
			}
		);
	}

	public function test_every_package_under_sigmas_releases_is_verified_even_when_the_cache_disagrees(): void {
		$newer = Updater::DOWNLOADS . 'v9.1.0/sigma-9.1.0.zip';

		// The update transient still offers 9.1.0, but the release cache says 9.0.0 (they expire apart).
		$this->github( self::release_json( '9.0.0' ) );
		Updater::release( true );
		$this->assets( hash( 'sha256', 'NEWZIP' ), 'NEWZIP' );
		$file = Updater::pre_download( false, $newer );
		$this->assertIsString( $file, 'verified against the .sha256 next to the zip' );
		$this->assertSame( 'NEWZIP', file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		wp_delete_file( $file );

		$this->assets( str_repeat( 'b', 64 ), 'NEWZIP' );
		$this->assertSame( 'sigma_checksum_mismatch', Updater::pre_download( false, $newer )->get_error_code(), 'a mismatch aborts' );

		// No cached release at all (a release published before its .sha256), and no checksum file: refused.
		delete_site_option( Updater::CACHE );
		$this->assets( null, 'NEWZIP' );
		$result = Updater::pre_download( false, $newer );
		$this->assertWPError( $result );
		$this->assertSame( 'sigma_checksum_missing', $result->get_error_code(), 'never falls through to an unverified install' );

		$this->assertFalse( Updater::pre_download( false, 'https://github.com/someone/else/releases/download/v1/x.zip' ), 'other packages untouched' );
		$this->assertFalse( Updater::pre_download( false, 'https://github.com/21press/sigma/archive/refs/tags/v9.1.0.zip' ), 'not a release asset: not ours to verify' );
		$this->assertSame( 'kept', Updater::pre_download( 'kept', $newer ), 'another filter already answered' );
	}

	public function test_only_a_parsed_sigma_release_asset_url_counts_as_own(): void {
		$this->assertTrue( Updater::is_own_package( 'https://github.com/21press/sigma/releases/download/v0.2.5/sigma-0.2.5.zip' ) );
		$this->assertTrue( Updater::is_own_package( 'https://GitHub.com/21press/sigma/releases/download/v1.0.0-rc.1/sigma-1.0.0-rc.1.zip' ), 'host case' );
		foreach ( array(
			'https://github.com/21press/sigma/releases/download/../../evil/x/releases/download/v1/a.zip',
			'https://github.com/21press/sigma/releases/download/%2e%2e/%2E%2E/evil/sigma-1.zip',
			'https://github.com/21press/sigma/releases/download/v1%2fsigma-1.zip',
			'https://github.com/21press/sigma/releases/download/v1\\sigma-1.zip',
			'https://evil@github.com/21press/sigma/releases/download/v1/sigma-1.zip',
			'https://github.com:8443/21press/sigma/releases/download/v1/sigma-1.zip',
			'https://github.com.evil.example/21press/sigma/releases/download/v1/sigma-1.zip',
			'http://github.com/21press/sigma/releases/download/v1/sigma-1.zip',
			'https://github.com/21press/sigma/releases/download/v1/sigma-1.zip?x=1',
			'https://github.com/21press/sigma/releases/download/v1/sigma-1.zip#a',
			'https://github.com/21press/sigma/releases/download/v1/other-1.zip',
			'https://github.com/21press/sigma/releases/download/v1/x/sigma-1.zip',
			'https://github.com/21press/sigma-pro/releases/download/v1/sigma-1.zip',
			'https://github.com/21press/sigma/archive/refs/tags/v1.zip',
			'',
		) as $url ) {
			$this->assertFalse( Updater::is_own_package( $url ), $url );
		}
		$this->assertFalse( Updater::is_own_package( null ) );
	}

	public function test_details_answer_only_plugin_information_for_slug_sigma(): void {
		$this->github( self::release_json( '9.0.0' ) );
		$this->assertSame(
			'orig',
			Updater::details(
				'orig',
				'query_plugins',
				(object) array(
					'search' => 'sigma',
					'slug'   => 'sigma',
				)
			),
			'the search list is never touched'
		);
		$this->assertSame( 'orig', Updater::details( 'orig', 'plugin_information', (object) array( 'slug' => 'sigma-pro' ) ) );
		$this->assertSame( '9.0.0', Updater::details( 'orig', 'plugin_information', (object) array( 'slug' => 'sigma' ) )->version );
	}

	public function test_view_details_shows_the_readme_and_a_sanitized_changelog(): void {
		$this->github( self::release_json( '9.0.0' ) );
		$info = Updater::details( false, 'plugin_information', (object) array( 'slug' => 'sigma' ) );
		$this->assertSame( '9.0.0', $info->version );
		$this->assertStringContainsString( 'A chat box.', $info->sections['description'] );
		$this->assertStringContainsString( '<strong>New:</strong>', $info->sections['changelog'] );
		$this->assertStringContainsString( '<a href="https://github.com/21press/sigma">Docs</a>', $info->sections['changelog'] );
		$this->assertStringNotContainsString( '<script', $info->sections['changelog'] );
		$this->assertFalse( Updater::details( false, 'plugin_information', (object) array( 'slug' => 'akismet' ) ) );
	}

	public function test_the_update_check_can_be_switched_off(): void {
		add_filter( 'sigma_update_check', '__return_false' );
		$this->github( self::release_json( '9.0.0' ) );
		$this->assertSame( array(), $this->check()->response );
		$this->assertSame( array(), $this->http, 'GitHub is not contacted' );
	}
}
