<?php
/**
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Tests\Integration;

use TwentyOnePress\Sigma\Admin\Admin_Page;
use TwentyOnePress\Sigma\Admin\Notices;
use TwentyOnePress\Sigma\Conversations\Repository;
use TwentyOnePress\Sigma\Mail\Transport;
use TwentyOnePress\Sigma\Plugin;
use TwentyOnePress\Sigma\Tests\Support\Integration_Test_Case;

/**
 * 0.2.5: Sigma's notices travel in the boot data of its screens (SigmaAdmin.notices) and show in the app's
 * content column, never as classic admin notices there (WordPress moves those into the page header).
 *
 * @covers \TwentyOnePress\Sigma\Admin\Notices
 */
final class Notices_Test extends Integration_Test_Case {

	public function tear_down(): void {
		unset( $_GET['page'] );
		remove_all_filters( 'pre_wp_mail' );
		parent::tear_down();
	}

	/** The notices a screen delivers, by id. */
	private static function by_id( string $screen = 'settings' ): array {
		return array_column( Notices::for_screen( $screen ), null, 'id' );
	}

	/** What the classic admin_notices hook prints on a page. */
	private static function classic( string $page ): string {
		$_GET['page'] = $page;
		ob_start();
		do_action( 'admin_notices' );
		return (string) ob_get_clean();
	}

	/** Conditions for the bot-renamed, mail-failing, handoffs-failed and turnstile-outage notices. */
	private function conditions(): void {
		update_option( Plugin::NAME_NOTICE, 1 );
		update_option(
			Transport::STATUS_OPTION,
			array(
				'consecutive_failures' => 3,
				'last_error_at'        => '2026-11-24 10:00:00',
			)
		);
		add_filter( 'pre_wp_mail', '__return_false' );
		[ $uuid, $token ] = $this->conversation();
		$this->rest(
			'POST',
			"/conversations/{$uuid}/handoff",
			array(
				'name'  => 'Ann',
				'email' => 'ann@example.test',
				'text'  => 'Please help',
			),
			array( 'X-DA-Token' => $token )
		);
		remove_all_filters( 'pre_wp_mail' );
		$this->settings(
			array(
				'turnstile_site_key' => '1x00000000000000000000AA',
				'turnstile_secret'   => '1x0000000000000000000000000000000AA',
				'turnstile_enabled'  => true,
			)
		);
		Repository::counter_add( 'ts:unreachable', HOUR_IN_SECONDS, 3 );
	}

	public function test_each_notice_has_the_contract_shape_and_admin_links(): void {
		$this->conditions();
		$notices = self::by_id();
		foreach ( array( 'bot-renamed', 'mail-failing', 'handoffs-failed', 'turnstile-outage' ) as $id ) {
			$this->assertArrayHasKey( $id, $notices, $id );
			$this->assertContains( $notices[ $id ]['tone'], array( 'info', 'success', 'warning', 'danger' ) );
			$this->assertTrue( $notices[ $id ]['dismissible'] );
			$this->assertIsString( $notices[ $id ]['text'] );
			$this->assertArrayNotHasKey( 'fingerprint', $notices[ $id ], 'internal' );
			if ( isset( $notices[ $id ]['action'] ) ) {
				$this->assertStringStartsWith( admin_url(), $notices[ $id ]['action']['url'] );
			}
		}
		$this->assertSame( 1, (int) preg_match( '/^1 handoff email was not sent\.$/', $notices['handoffs-failed']['text'] ) );
		$this->assertSame( admin_url( 'admin.php?page=sigma-conversations' ), $notices['handoffs-failed']['action']['url'] );
		$this->assertSame( admin_url( 'admin.php?page=sigma#human' ), $notices['mail-failing']['action']['url'] );
		$this->assertSame( 'danger', $notices['mail-failing']['tone'] );

		$this->assertArrayNotHasKey( 'bot-renamed', self::by_id(), 'one-time: used up when delivered' );
		$this->assertArrayHasKey( 'handoffs-failed', self::by_id( 'conversations' ), 'every Sigma screen' );
		$this->assertArrayHasKey( 'turnstile-outage', self::by_id( 'knowledge' ) );
	}

	public function test_on_sigma_screens_nothing_is_printed_by_admin_notices_but_elsewhere_the_classic_notices_stay(): void {
		$this->conditions();
		foreach ( array( Admin_Page::SLUG, Admin_Page::KNOWLEDGE, Admin_Page::CONVERSATIONS ) as $page ) {
			$html = self::classic( $page );
			foreach ( array( 'now called Sigma', 'emails failed to send', 'handoff email', 'Turnstile could not be reached' ) as $text ) {
				$this->assertStringNotContainsString( $text, $html, "{$page}: {$text}" );
			}
		}
		$html = self::classic( '' );
		$this->assertStringContainsString( 'emails failed to send', $html, 'the Dashboard keeps the classic notice' );
		$this->assertStringContainsString( 'handoff email was not sent', $html );
		$this->assertStringContainsString( 'Turnstile could not be reached', $html );
		$this->assertStringNotContainsString( 'now called Sigma', $html, 'the rename is only told inside Sigma' );
	}

	public function test_dismissing_hides_a_condition_until_it_changes_and_the_one_time_notice_for_good(): void {
		$this->conditions();
		$this->assertSame( array( 'dismissed' => true ), $this->rest( 'POST', '/admin/notices/handoffs-failed/dismiss' )->get_data() );
		$this->assertArrayNotHasKey( 'handoffs-failed', self::by_id() );

		add_filter( 'pre_wp_mail', '__return_false' );
		[ $uuid, $token ] = $this->conversation();
		$this->rest(
			'POST',
			"/conversations/{$uuid}/handoff",
			array(
				'name'  => 'Bo',
				'email' => 'bo@example.test',
				'text'  => 'Again',
			),
			array( 'X-DA-Token' => $token )
		);
		$this->assertSame( '2 handoff emails were not sent.', self::by_id()['handoffs-failed']['text'] ?? '', 'back when the count changes' );

		update_option( Plugin::NAME_NOTICE, 1 );
		$this->assertSame( 200, $this->rest( 'POST', '/admin/notices/bot-renamed/dismiss' )->get_status() );
		$this->assertFalse( get_option( Plugin::NAME_NOTICE ) );

		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $other );
		$this->assertArrayHasKey( 'mail-failing', self::by_id(), 'dismissals are per user' );
	}

	public function test_the_dismiss_endpoint_checks_the_id_and_the_capability(): void {
		$this->assertSame( 404, $this->rest( 'POST', '/admin/notices/nope/dismiss' )->get_status() );
		$this->assertSame( 200, $this->rest( 'POST', '/admin/notices/build-stale/dismiss' )->get_status(), 'known id, nothing to hide: still fine' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, $this->rest( 'POST', '/admin/notices/mail-failing/dismiss' )->get_status() );
		$this->assertSame( array(), Notices::for_screen( 'conversations' ), 'notices are for admins' );
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->rest( 'POST', '/admin/notices/mail-failing/dismiss' )->get_status() );
	}

	public function test_the_boot_data_carries_the_notices(): void {
		if ( ! is_readable( P21_SIGMA_DIR . '/build/admin/admin.js' ) ) {
			$this->markTestSkipped( 'The admin app is not built here; enqueue() needs build/admin/admin.js.' );
		}
		update_option( Plugin::NAME_NOTICE, 1 );
		Admin_Page::menu();
		$GLOBALS['wp_scripts'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A fresh registry, as on a page load.
		try {
			wp_scripts();
			Admin_Page::enqueue( 'toplevel_page_' . Admin_Page::SLUG );
			$before = implode( "\n", (array) wp_scripts()->get_data( 'sigma-admin', 'before' ) );
		} finally {
			$GLOBALS['wp_scripts'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
		$this->assertMatchesRegularExpression( '/"notices":\[\{"id":"bot-renamed","tone":"info","text":"Your bot is now called Sigma/', $before );
	}
}
