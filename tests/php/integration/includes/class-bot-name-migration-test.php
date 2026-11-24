<?php
/**
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Tests\Integration;

use TwentyOnePress\Sigma\Plugin;
use TwentyOnePress\Sigma\Settings;
use TwentyOnePress\Sigma\Tests\Support\Integration_Test_Case;

/**
 * 0.2.5: the default bot name is Sigma. A site still on the old default "Remy" is renamed once, with a
 * one-time notice; any other name stays.
 *
 * @covers \TwentyOnePress\Sigma\Plugin::migrate_bot_name
 * @covers \TwentyOnePress\Sigma\Admin\Notices::for_screen
 * @covers \TwentyOnePress\Sigma\Settings::bot_name
 */
final class Bot_Name_Migration_Test extends Integration_Test_Case {

	public function tear_down(): void {
		unset( $_GET['page'] );
		parent::tear_down();
	}

	/** Store settings as an older version left them (raw option, no sanitizing). */
	private function stored( array $settings ): void {
		update_option( Settings::OPTION, $settings + (array) get_option( Settings::OPTION, array() ) );
		Settings::flush();
		delete_option( Plugin::NAME_MIGRATED );
		delete_option( Plugin::NAME_NOTICE );
	}

	/** The rename notice's text as the Settings screen would deliver it, or ''. */
	private function notice(): string {
		foreach ( \TwentyOnePress\Sigma\Admin\Notices::for_screen( 'settings' ) as $notice ) {
			if ( 'bot-renamed' === $notice['id'] ) {
				return $notice['text'];
			}
		}
		return '';
	}

	/** What the classic admin_notices hook prints. */
	private function classic(): string {
		ob_start();
		do_action( 'admin_notices' );
		return (string) ob_get_clean();
	}

	public function test_the_default_is_sigma_and_the_texts_follow_it(): void {
		$this->assertSame( 'Sigma', Settings::defaults()['bot_name'] );
		$this->assertSame( 'Sigma', Settings::bot_name() );
		$this->assertSame( '👋 Hi, this is Sigma. How can I help you?', Settings::text( 'teaser_text' ) );
		wp_set_current_user( 0 );
		$config = $this->rest( 'GET', '/config' )->get_data();
		$this->assertSame( 'Sigma', $config['bot_name'] );
		$this->assertStringStartsWith( "Hi, I'm Sigma.", $config['bot_intro'] );
		$this->assertSame( 'Talk to Sigma', $config['bot_card_title'] );
	}

	public function test_the_old_default_remy_becomes_sigma_once_with_a_notice(): void {
		$this->stored( array( 'bot_name' => 'Remy' ) );
		update_option( Plugin::VERSION_OPTION, '0.2.1' );
		Plugin::maybe_version_change();

		$this->assertSame( 'Sigma', get_option( Settings::OPTION )['bot_name'] );
		$this->assertSame( 'Sigma', Settings::bot_name() );
		$this->assertNotFalse( get_option( Plugin::NAME_MIGRATED ) );

		$_GET['page'] = 'sigma';
		$this->assertStringNotContainsString( 'now called Sigma', $this->classic(), 'never a classic notice (WordPress moves those into the header)' );
		$this->assertStringContainsString( 'Your bot is now called Sigma (it was Remy). You can change the name under AI agent → Identity.', $this->notice() );
		$this->assertSame( '', $this->notice(), 'shown once' );

		// Renamed back to Remy afterwards by the owner: left alone, also on later versions.
		$this->rest( 'POST', '/admin/settings', array( 'bot_name' => 'Remy' ) );
		Plugin::maybe_version_change();
		update_option( Plugin::VERSION_OPTION, '0.2.4' );
		Plugin::maybe_version_change();
		$this->assertSame( 'Remy', Settings::bot_name(), 'runs once per site' );
	}

	public function test_a_test_build_that_already_stored_0_2_5_is_migrated_too(): void {
		$this->stored( array( 'bot_name' => 'Remy' ) );
		update_option( Plugin::VERSION_OPTION, P21_SIGMA_VERSION );
		Plugin::maybe_version_change();
		$this->assertSame( 'Sigma', Settings::bot_name() );
	}

	public function test_a_chosen_name_is_kept_without_a_notice(): void {
		foreach ( array( 'Nova', 'remy', 'Remy Bot' ) as $name ) {
			$this->stored( array( 'bot_name' => $name ) );
			update_option( Plugin::VERSION_OPTION, '0.2.1' );
			Plugin::maybe_version_change();
			$this->assertSame( $name, Settings::bot_name(), $name );
			$this->assertFalse( get_option( Plugin::NAME_NOTICE ), $name . ': no notice' );
		}
	}

	public function test_an_unsaved_name_now_reads_sigma_and_says_so_but_a_new_install_has_no_notice(): void {
		$settings = (array) get_option( Settings::OPTION, array() );
		unset( $settings['bot_name'] );
		update_option( Settings::OPTION, $settings );
		Settings::flush();
		delete_option( Plugin::NAME_MIGRATED );
		update_option( Plugin::VERSION_OPTION, '0.2.1' );
		Plugin::maybe_version_change();
		$this->assertSame( 'Sigma', Settings::bot_name(), 'the default changed under it' );
		$this->assertNotFalse( get_option( Plugin::NAME_NOTICE ), 'so the owner is told' );

		delete_option( Plugin::NAME_MIGRATED );
		delete_option( Plugin::NAME_NOTICE );
		delete_option( Plugin::VERSION_OPTION );
		Plugin::maybe_version_change();
		$this->assertFalse( get_option( Plugin::NAME_NOTICE ), 'a new install was never Remy' );
		$this->assertNotFalse( get_option( Plugin::NAME_MIGRATED ) );
	}

	public function test_the_notice_is_for_admins_only(): void {
		update_option( Plugin::NAME_NOTICE, 1 );
		$_GET['page'] = 'sigma-conversations';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( array(), \TwentyOnePress\Sigma\Admin\Notices::for_screen( 'conversations' ) );
		$this->assertNotFalse( get_option( Plugin::NAME_NOTICE ), 'kept for an admin' );
	}
}
