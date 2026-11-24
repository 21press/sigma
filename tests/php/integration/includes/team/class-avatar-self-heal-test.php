<?php
/**
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Tests\Integration;

use TwentyOnePress\Sigma\Plugin;
use TwentyOnePress\Sigma\Team\Avatars;
use TwentyOnePress\Sigma\Tests\Support\Integration_Test_Case;

/**
 * 0.1.4: avatar copies heal themselves. 0.1.2 never made them (a fatal error in REST and cron), and
 * 0.1.3 only made them on a team save, a profile save, Refresh avatars or the daily cron, so a site
 * upgraded from 0.1.2 kept initials. Now a version change, activation, and a /config that finds a
 * member never refreshed schedule a one-off refresh in WP-Cron. No request ever fetches inline.
 *
 * @covers \TwentyOnePress\Sigma\Team\Avatars
 * @covers \TwentyOnePress\Sigma\Plugin
 */
final class Avatar_Self_Heal_Test extends Integration_Test_Case {

	public function set_up(): void {
		parent::set_up();
		wp_clear_scheduled_hook( Avatars::CRON_SOON );
		delete_transient( Avatars::HEAL_LOCK );
		delete_transient( 'doing_cron' );
		$this->answer_http(
			static function ( string $url ) {
				if ( ! str_contains( $url, 'gravatar.com' ) ) {
					return new \WP_Error( 'not_in_tests', $url ); // wp-cron.php loopbacks land here.
				}
				$img = imagecreatetruecolor( 192, 192 );
				imagefill( $img, 0, 0, imagecolorallocate( $img, 200, 60, 60 ) );
				ob_start();
				imagepng( $img );
				return array(
					'headers'  => array( 'content-type' => 'image/png' ),
					'body'     => (string) ob_get_clean(),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
				);
			}
		);
	}

	/**
	 * A team as 0.1.2 left it: members saved, no copy, no status.
	 *
	 * @return list<int>
	 */
	private function broken_team(): array {
		$ids = array();
		foreach ( array( 'Alice', 'Bob' ) as $name ) {
			$ids[] = self::factory()->user->create(
				array(
					'role'       => 'editor',
					'first_name' => $name,
					'user_login' => strtolower( $name ) . wp_rand( 1000, 9999 ),
				)
			);
		}
		$this->settings( array( 'team' => $ids ) ); // Settings only: no refresh runs.
		foreach ( $ids as $id ) {
			$this->assertSame( '', Avatars::url( $id ) );
			$this->assertNull( Avatars::status( $id ) );
		}
		return $ids;
	}

	/**
	 * Requests to Gravatar so far.
	 */
	private function gravatar_requests(): int {
		return count( array_filter( $this->http, static fn( array $r ): bool => str_contains( $r['url'], 'gravatar.com' ) ) );
	}

	public function test_an_upgrade_from_0_1_2_schedules_a_refresh_that_makes_the_copies(): void {
		$ids = $this->broken_team();
		update_option( Plugin::VERSION_OPTION, '0.1.2' );

		Plugin::maybe_version_change();

		$this->assertSame( P21_SIGMA_VERSION, get_option( Plugin::VERSION_OPTION ) );
		$at = wp_next_scheduled( Avatars::CRON_SOON );
		$this->assertIsInt( $at, 'a one-off refresh is scheduled' );
		$this->assertLessThanOrEqual( time(), $at, 'due now, so spawn_cron() can start it' );
		$spawn = array_values( array_filter( $this->http, static fn( array $r ): bool => str_contains( $r['url'], 'wp-cron.php' ) ) );
		$this->assertCount( 1, $spawn, 'WP-Cron is asked to run now' );
		$this->assertFalse( $spawn[0]['args']['blocking'], 'without waiting for it' );
		$this->assertSame( 0, $this->gravatar_requests(), 'no fetch in this request' );

		do_action( Avatars::CRON_SOON ); // What the cron request runs.

		foreach ( $ids as $id ) {
			$this->assertMatchesRegularExpression( '#/delta-assistant/avatars/[a-z0-9]{20}\.(webp|png)$#', Avatars::url( $id ) );
			$this->assertSame( 'ok', Avatars::status( $id )['result'] );
		}
	}

	public function test_a_missing_version_counts_as_an_upgrade_and_the_same_version_does_nothing(): void {
		$this->broken_team();
		delete_option( Plugin::VERSION_OPTION ); // 0.1.3 and older never stored it.
		Plugin::maybe_version_change();
		$this->assertIsInt( wp_next_scheduled( Avatars::CRON_SOON ) );

		wp_clear_scheduled_hook( Avatars::CRON_SOON );
		Plugin::maybe_version_change();
		$this->assertFalse( wp_next_scheduled( Avatars::CRON_SOON ), 'same version: nothing to do' );

		update_option( Plugin::VERSION_OPTION, '9.9.9' ); // A downgrade.
		Plugin::maybe_version_change();
		$this->assertFalse( wp_next_scheduled( Avatars::CRON_SOON ) );
		$this->assertSame( P21_SIGMA_VERSION, get_option( Plugin::VERSION_OPTION ) );
	}

	public function test_activation_schedules_the_refresh(): void {
		$this->broken_team();
		Plugin::activate();
		$this->assertIsInt( wp_next_scheduled( Avatars::CRON_SOON ) );
		$this->assertSame( P21_SIGMA_VERSION, get_option( Plugin::VERSION_OPTION ) );
	}

	public function test_config_with_a_member_never_refreshed_schedules_once_per_10_minutes_and_never_fetches(): void {
		$ids = $this->broken_team();
		wp_set_current_user( 0 );

		$config = $this->rest( 'GET', '/config' )->get_data();
		$this->assertSame( array( '', '' ), array_column( $config['team'], 'avatar_url' ), 'this response still has initials' );
		$this->assertIsInt( wp_next_scheduled( Avatars::CRON_SOON ), 'a refresh is scheduled' );
		$this->assertSame( 0, $this->gravatar_requests(), 'no inline fetch on /config' );

		// The event is gone (say it ran and the copies are still missing): within 10 minutes, no new one.
		wp_clear_scheduled_hook( Avatars::CRON_SOON );
		$this->rest( 'GET', '/config' );
		$this->rest( 'GET', '/config' );
		$this->assertFalse( wp_next_scheduled( Avatars::CRON_SOON ), 'at most once per 10 minutes' );

		delete_transient( Avatars::HEAL_LOCK ); // 10 minutes later.
		$this->rest( 'GET', '/config' );
		$this->assertIsInt( wp_next_scheduled( Avatars::CRON_SOON ) );
		$this->assertSame( 0, $this->gravatar_requests() );

		do_action( Avatars::CRON_SOON );
		wp_clear_scheduled_hook( Avatars::CRON_SOON );
		delete_transient( Avatars::HEAL_LOCK );
		$config = $this->rest( 'GET', '/config' )->get_data();
		$this->assertNotContains( '', array_column( $config['team'], 'avatar_url' ), 'the next /config has the copies' );
		$this->assertFalse( wp_next_scheduled( Avatars::CRON_SOON ), 'nothing missing: nothing scheduled' );
		$this->assertCount( 2, $ids );
	}

	public function test_after_an_upgrade_config_does_not_schedule_a_second_refresh_while_the_first_runs(): void {
		$this->broken_team();
		update_option( Plugin::VERSION_OPTION, '0.1.2' );
		Plugin::maybe_version_change();
		wp_clear_scheduled_hook( Avatars::CRON_SOON ); // WP-Cron took the event and is running it now.
		wp_set_current_user( 0 );
		$this->rest( 'GET', '/config' );
		$this->assertFalse( wp_next_scheduled( Avatars::CRON_SOON ), 'members still have no status mid-run: no second refresh' );
	}

	public function test_one_run_at_a_time_and_a_dead_runs_lock_is_taken_over(): void {
		$ids = $this->broken_team();
		add_option( Avatars::RUN_LOCK, time() - 30 ); // Another run started 30 s ago.
		$this->assertFalse( Avatars::refresh_team(), 'skipped while another run holds the lock' );
		$response = $this->rest( 'POST', '/admin/team/refresh-avatars' );
		$this->assertSame( 409, $response->get_status(), 'Refresh avatars says so' );
		$this->assertSame( 'refresh_running', $response->get_data()['code'] );
		$this->assertNull( Avatars::status( $ids[0] ) );
		$this->assertSame( 0, $this->gravatar_requests() );

		update_option( Avatars::RUN_LOCK, time() - 300 ); // That run died 5 minutes ago.
		$this->assertTrue( Avatars::refresh_team() );
		$this->assertSame( 'ok', Avatars::status( $ids[0] )['result'] );
		$this->assertFalse( get_option( Avatars::RUN_LOCK ), 'the lock is released' );
	}

	public function test_files_no_member_uses_are_swept_once_10_minutes_old(): void {
		$ids = $this->broken_team();
		$dir = Avatars::dir()['path'];
		wp_mkdir_p( $dir );
		$old   = $dir . '/' . strtolower( wp_generate_password( 20, false ) ) . '.webp';
		$fresh = $dir . '/' . strtolower( wp_generate_password( 20, false ) ) . '.webp';
		$temp  = $dir . '/incoming-' . strtolower( wp_generate_password( 12, false ) ) . '.tmp';
		$other = $dir . '/index.php';
		foreach ( array( $old, $fresh, $temp ) as $file ) {
			file_put_contents( $file, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		touch( $old, time() - 3600 );
		touch( $temp, time() - 3600 );
		Avatars::refresh_team();
		$this->assertFileDoesNotExist( $old, 'an old orphan goes' );
		$this->assertFileDoesNotExist( $temp, 'an old temporary file goes' );
		$this->assertFileExists( $fresh, 'a new one may belong to a run in progress' );
		$this->assertFileExists( $other );
		foreach ( $ids as $id ) {
			$this->assertFileExists( $dir . '/' . basename( Avatars::url( $id ) ), 'copies in use stay' );
		}
		wp_delete_file( $fresh );
	}

	public function test_a_member_with_a_recorded_outcome_is_left_to_the_daily_refresh(): void {
		$ids = $this->broken_team();
		foreach ( $ids as $id ) {
			update_user_meta( $id, Avatars::STATUS_META, array( 'source' => 'gravatar', 'result' => 'no_picture', 'reason' => 'http_404', 'detail' => '', 'at' => time() ) );
		}
		wp_set_current_user( 0 );
		$this->rest( 'GET', '/config' );
		$this->assertFalse( wp_next_scheduled( Avatars::CRON_SOON ), 'no Gravatar is an answer, not a missing refresh' );
	}
}
