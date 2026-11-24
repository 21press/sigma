<?php
/**
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Tests\Integration;

use TwentyOnePress\Sigma\Admin\Build_Check;
use TwentyOnePress\Sigma\Tests\Support\Integration_Test_Case;

/**
 * Stale or missing built files: build/version.txt against the plugin version and the app sources.
 *
 * @covers \TwentyOnePress\Sigma\Admin\Build_Check
 */
final class Build_Check_Test extends Integration_Test_Case {

	/** A fake plugin folder. */
	private string $dir = '';

	public function set_up(): void {
		parent::set_up();
		$this->dir = get_temp_dir() . 'sigma-build-check-' . wp_generate_password( 8, false );
		foreach ( array( 'build/admin', 'build/widget', 'apps/admin/src', 'apps/widget/src' ) as $sub ) {
			wp_mkdir_p( $this->dir . '/' . $sub );
		}
		$this->put( 'apps/admin/src/main.tsx', 'x', time() - 600 );
		$this->put( 'apps/widget/src/bubble.ts', 'x', time() - 600 );
		$this->put( 'build/admin/admin.js', 'x', time() - 60 );
		$this->put( 'build/widget/widget.js', 'x', time() - 60 );
		$this->put( 'build/version.txt', "0.2.5 8bcc645\n", time() - 60 );
	}

	public function tear_down(): void {
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $files as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : wp_delete_file( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
		rmdir( $this->dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		unset( $_GET['page'] );
		parent::tear_down();
	}

	/**
	 * Write a file with a modification time.
	 *
	 * @param string $file    Relative path.
	 * @param string $content Content.
	 * @param int    $mtime   Modification time.
	 */
	private function put( string $file, string $content, int $mtime ): void {
		file_put_contents( $this->dir . '/' . $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		touch( $this->dir . '/' . $file, $mtime );
	}

	public function test_a_build_of_this_version_newer_than_its_sources_is_fine(): void {
		$this->assertSame(
			array(
				'problem' => '',
				'built'   => '0.2.5 8bcc645',
			),
			Build_Check::check( $this->dir, '0.2.5' )
		);

		$this->put( 'build/version.txt', "0.2.5\n", time() - 60 );
		$this->assertSame( '', Build_Check::check( $this->dir, '0.2.5' )['problem'], 'no git: the version alone' );
	}

	public function test_another_version_or_no_stamp_is_stale(): void {
		$this->assertSame(
			array(
				'problem' => 'stale',
				'built'   => '0.2.5 8bcc645',
			),
			Build_Check::check( $this->dir, '0.2.6' )
		);
		$this->assertSame( 'stale', Build_Check::check( $this->dir, '0.2.50' )['problem'], 'the version must match exactly' );

		wp_delete_file( $this->dir . '/build/version.txt' );
		$this->assertSame(
			array(
				'problem' => 'stale',
				'built'   => '',
			),
			Build_Check::check( $this->dir, '0.2.5' ),
			'a build from before the stamp'
		);
	}

	public function test_a_source_changed_after_the_build_is_stale_and_a_zip_without_sources_is_not(): void {
		$this->put( 'apps/widget/src/bubble.ts', 'changed', time() );
		$this->assertSame( 'stale', Build_Check::check( $this->dir, '0.2.5' )['problem'] );

		wp_delete_file( $this->dir . '/apps/widget/src/bubble.ts' );
		wp_delete_file( $this->dir . '/apps/admin/src/main.tsx' );
		rmdir( $this->dir . '/apps/widget/src' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( $this->dir . '/apps/admin/src' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		$this->assertSame( '', Build_Check::check( $this->dir, '0.2.5' )['problem'], 'the release zip has no apps/' );
	}

	public function test_a_missing_build_file_is_missing(): void {
		wp_delete_file( $this->dir . '/build/widget/widget.js' );
		$this->assertSame( 'missing', Build_Check::check( $this->dir, '0.2.5' )['problem'] );
		wp_delete_file( $this->dir . '/build/admin/admin.js' );
		$this->assertSame( 'missing', Build_Check::check( $this->dir . '/nowhere', '0.2.5' )['problem'] );
	}

	public function test_a_stale_build_is_reported_inside_the_app_not_as_a_classic_notice(): void {
		$stamp  = P21_SIGMA_DIR . '/' . Build_Check::VERSION_FILE;
		$before = is_readable( $stamp ) ? (string) file_get_contents( $stamp ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$inapp  = static function ( string $screen ): ?array {
			foreach ( \TwentyOnePress\Sigma\Admin\Notices::for_screen( $screen ) as $notice ) {
				if ( 'build-stale' === $notice['id'] ) {
					return $notice;
				}
			}
			return null;
		};
		try {
			wp_mkdir_p( dirname( $stamp ) );
			file_put_contents( $stamp, "0.0.1 deadbee\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			if ( 'stale' !== Build_Check::check()['problem'] ) {
				$this->markTestSkipped( 'This checkout has no built files ("missing"), so the stale case cannot be set up.' );
			}

			$_GET['page'] = 'sigma';
			ob_start();
			Build_Check::notice();
			$this->assertSame( '', (string) ob_get_clean(), 'stale: no classic notice (WordPress would move it into the header)' );

			foreach ( array( 'settings', 'knowledge', 'conversations' ) as $screen ) {
				$notice = $inapp( $screen );
				$this->assertNotNull( $notice, $screen );
				$this->assertSame( 'danger', $notice['tone'] );
				$this->assertTrue( $notice['dismissible'] );
				$this->assertStringContainsString( 'built from 0.0.1 deadbee; the plugin is ' . P21_SIGMA_VERSION, $notice['text'] );
				$this->assertStringContainsString( '`npm run build`', $notice['text'], 'the app renders `…` as code' );
			}

			wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
			$this->assertNull( $inapp( 'settings' ), 'only for admins' );
		} finally {
			if ( null === $before ) {
				wp_delete_file( $stamp );
			} else {
				file_put_contents( $stamp, $before ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
	}
}
