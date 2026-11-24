<?php
/**
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Tests\Integration;

use TwentyOnePress\Sigma\Admin\Admin_Page;
use TwentyOnePress\Sigma\Tests\Support\Integration_Test_Case;

/**
 * Loading (0.2.5, owner feedback): each screen's first requests are preloaded into the page (WordPress's
 * rest_preload_api_request + apiFetch's preloading middleware), and the frame is an Alpha skeleton with a
 * fallback when the app doesn't start, never a bare "Loading…".
 *
 * @covers \TwentyOnePress\Sigma\Admin\Admin_Page
 */
final class Admin_Loading_Test extends Integration_Test_Case {

	public function set_up(): void {
		parent::set_up();
		if ( ! is_readable( P21_SIGMA_DIR . '/build/admin/admin.js' ) ) {
			$this->markTestSkipped( 'Needs the built admin app (npm run build:admin).' );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Admin_Page::menu();
		// A fresh script registry, as on a page load: WordPress registers its packages (wp-api-fetch) again. Other
		// tests empty the registry.
		$GLOBALS['wp_scripts'] = null;
		wp_scripts();
	}

	public function tear_down(): void {
		$GLOBALS['wp_scripts'] = null;
		parent::tear_down();
	}

	/**
	 * The inline script after wp-api-fetch on a screen, decoded to path => response.
	 *
	 * @param string $screen settings|knowledge|conversations.
	 * @return array<string, mixed>
	 */
	private function preloaded( string $screen ): array {
		$hooks                   = array(
			'settings'      => 'toplevel_page_' . Admin_Page::SLUG,
			'knowledge'     => get_plugin_page_hookname( Admin_Page::KNOWLEDGE, Admin_Page::SLUG ),
			'conversations' => get_plugin_page_hookname( Admin_Page::CONVERSATIONS, Admin_Page::SLUG ),
		);
		$scripts = wp_scripts();
		$this->assertTrue( $scripts->query( 'wp-api-fetch', 'registered' ) !== false, 'WordPress registers wp-api-fetch' );
		$scripts->registered['wp-api-fetch']->extra['after'] = array(); // only this screen's addition
		Admin_Page::enqueue( $hooks[ $screen ] );
		$after = implode( "\n", (array) $scripts->get_data( 'wp-api-fetch', 'after' ) );
		$this->assertMatchesRegularExpression( '/wp\.apiFetch\.use\( wp\.apiFetch\.createPreloadingMiddleware\( (\{.*\}) \) \);/s', $after );
		preg_match( '/createPreloadingMiddleware\( (\{.*\}) \) \);/s', $after, $m );
		return (array) json_decode( $m[1], true );
	}

	public function test_settings_preloads_admin_settings_with_its_real_answer(): void {
		$data = $this->preloaded( 'settings' );
		$this->assertArrayHasKey( '/sigma/v1/admin/settings', $data );
		$body = $data['/sigma/v1/admin/settings']['body'];
		$this->assertArrayHasKey( 'settings', $body );
		$this->assertArrayHasKey( 'instructions_default', $body );
		$this->assertArrayNotHasKey( 'anthropic_key_enc', $body['settings'], 'never a secret in the page' );
	}

	public function test_knowledge_and_conversations_preload_their_first_list_page(): void {
		$knowledge = $this->preloaded( 'knowledge' );
		$this->assertArrayHasKey( '/sigma/v1/admin/knowledge?page=1&per_page=20&search=&status=all', $knowledge );
		$this->assertArrayHasKey( 'items', $knowledge['/sigma/v1/admin/knowledge?page=1&per_page=20&search=&status=all']['body'] );

		$conversations = $this->preloaded( 'conversations' );
		$this->assertArrayHasKey( '/sigma/v1/admin/conversations?page=1&per_page=20&search=&status=', $conversations );
		$this->assertArrayHasKey( 'items', $conversations['/sigma/v1/admin/conversations?page=1&per_page=20&search=&status=']['body'] );
	}

	public function test_the_paths_match_what_the_app_asks_for(): void {
		$this->assertSame( array( '/sigma/v1/admin/settings' ), Admin_Page::preload_paths( 'settings' ) );
		$this->assertSame( array(), Admin_Page::preload_paths( 'unknown' ) );
	}

	public function test_the_frame_is_a_skeleton_with_a_fallback_never_a_bare_loading_text(): void {
		$_GET['page'] = Admin_Page::SLUG;
		ob_start();
		Admin_Page::render();
		$html = (string) ob_get_clean();
		unset( $_GET['page'] );

		$this->assertStringNotContainsString( 'Loading…', $html );
		$this->assertStringContainsString( 'class="sigma-admin"', $html );
		$this->assertStringContainsString( 'class="plugin__topbar"', $html, 'the header with the icon and name' );
		$this->assertStringContainsString( '<span class="tabs__tab">AI agent</span>', $html, 'the tab bar' );
		$this->assertMatchesRegularExpression( '/<div class="skeleton" role="status" aria-label="Loading settings">(<span class="skeleton__line[^"]*"><\/span>){8}<\/div>/', $html );
		$this->assertStringContainsString( 'id="sigma-admin-frame"', $html, 'the fallback script' );
		$this->assertStringContainsString( ',' . Admin_Page::APP_START_TIMEOUT . ');', $html );
		$this->assertStringContainsString( 'npm run build', $html );
		$this->assertStringContainsString( 'r.dataset.mounted', $html, 'main.tsx marks the app as started' );
		$this->assertStringContainsString( 'theme-toggle__icon--moon', $html, 'the switcher, as the app draws it' );
		$this->assertStringContainsString( 'theme-toggle__icon--sun', $html );
		$this->assertStringContainsString( 'localStorage.getItem("sigma-admin-theme")', $html, 'data-theme set before the first paint picks the icon' );
	}

	public function test_a_stale_build_prints_its_notice_in_the_frame_outside_the_app_root(): void {
		$stamp  = P21_SIGMA_DIR . '/build/version.txt';
		$before = is_readable( $stamp ) ? (string) file_get_contents( $stamp ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture.
		file_put_contents( $stamp, "0.0.1 test\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		try {
			$_GET['page'] = Admin_Page::SLUG;
			ob_start();
			Admin_Page::render();
			$html = (string) ob_get_clean();
			unset( $_GET['page'] );
		} finally {
			null === $before ? unlink( $stamp ) : file_put_contents( $stamp, $before ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture.
		}
		$notice = strpos( $html, 'id="sigma-build-notice"' );
		$root   = strpos( $html, 'id="sigma-admin"' );
		$this->assertNotFalse( $notice, 'printed by PHP, whatever the app' );
		$this->assertLessThan( $root, $notice, 'before the app root, so an old app cannot wipe it' );
		$this->assertStringContainsString( 'notice notice--danger notice-error inline', $html, 'Alpha markup; wp-admin styling if Sigma\'s CSS is missing; common.js leaves it' );
		$this->assertStringContainsString( 'built from 0.0.1 test', $html );
		$this->assertStringContainsString( '<code>npm run build</code>', $html );
		$this->assertStringContainsString( 'getElementById("sigma-build-notice")', $html, 'the frame script moves it into the content column' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( '', Admin_Page::build_notice(), 'admins only' );
	}

	public function test_knowledge_and_conversations_frames(): void {
		$this->assertStringContainsString( 'aria-label="Loading entries"', Admin_Page::skeleton( 'knowledge' ) );
		$this->assertStringContainsString( 'aria-label="Loading conversations"', Admin_Page::skeleton( 'conversations' ) );
		$this->assertStringContainsString( 'This screen couldn&#039;t load.', Admin_Page::failed_notice() );
	}
}
