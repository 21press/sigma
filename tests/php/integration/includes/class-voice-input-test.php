<?php
/**
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Tests\Integration;

use TwentyOnePress\Sigma\Privacy\Privacy;
use TwentyOnePress\Sigma\Settings;
use TwentyOnePress\Sigma\Tests\Support\Integration_Test_Case;
use TwentyOnePress\Sigma\Widget;

/**
 * Voice input (§19.4): the `voice_input` setting, on by default (owner), in /config, the inline data and the
 * admin settings, and the privacy-policy paragraph while it is on. The dictation itself is the browser's.
 *
 * @covers \TwentyOnePress\Sigma\Settings
 * @covers \TwentyOnePress\Sigma\Rest\Visitor_Controller::config
 * @covers \TwentyOnePress\Sigma\Privacy\Privacy::policy_text
 */
final class Voice_Input_Test extends Integration_Test_Case {

	private function config(): array {
		wp_set_current_user( 0 );
		return $this->rest( 'GET', '/config' )->get_data();
	}

	/** The suggested privacy policy text Sigma registers, as in wp-admin on admin_init. */
	private static function policy(): string {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';
		$store = new \ReflectionProperty( \WP_Privacy_Policy_Content::class, 'policy_content' );
		$store->setAccessible( true ); // phpcs:ignore Generic.PHP.DeprecatedFunctions -- Needed before PHP 8.1 semantics; harmless after.
		$store->setValue( null, array() );
		set_current_screen( 'dashboard' ); // is_admin(): WordPress only takes policy text in wp-admin.
		$GLOBALS['wp_current_filter'][] = 'admin_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WordPress only takes policy text on admin_init.
		try {
			Privacy::policy_text();
			return implode( '', array_column( (array) $store->getValue(), 'policy_text' ) );
		} finally {
			array_pop( $GLOBALS['wp_current_filter'] );
			$store->setValue( null, array() );
			set_current_screen( 'front' );
		}
	}

	public function test_it_is_on_by_default_and_reaches_config_and_the_admin(): void {
		$this->assertTrue( Settings::defaults()['voice_input'] );
		$this->assertTrue( Settings::get( 'voice_input' ) );
		$this->assertTrue( $this->config()['voice_input'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( $this->rest( 'GET', '/admin/settings' )->get_data()['settings']['voice_input'] );
	}

	public function test_switching_it_off_is_saved_as_a_boolean_and_turns_it_off_for_visitors(): void {
		foreach ( array( false, 0, '0', 'false', 'no', 'off' ) as $off ) {
			$this->settings( array( 'voice_input' => true ) );
			$this->assertSame( 200, $this->rest( 'POST', '/admin/settings', array( 'voice_input' => $off ) )->get_status() );
			$this->assertFalse( Settings::get( 'voice_input' ), var_export( $off, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		}
		$this->assertFalse( $this->config()['voice_input'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( 200, $this->rest( 'POST', '/admin/settings', array( 'voice_input' => 'yes' ) )->get_status() );
		$this->assertTrue( Settings::get( 'voice_input' ) );
		$this->assertSame( true, get_option( Settings::OPTION )['voice_input'], 'stored as a real boolean' );
	}

	public function test_the_inline_data_carries_it_before_the_panel_loads(): void {
		if ( ! is_readable( P21_SIGMA_DIR . '/' . Widget::script_path() ) ) {
			$this->markTestSkipped( 'The inline data needs the built widget (npm run build:widget); /config is checked above.' );
		}
		$inline = static function (): string {
			wp_set_current_user( 0 );
			wp_scripts()->registered = array();
			Widget::enqueue();
			return implode( '', (array) wp_scripts()->get_data( Widget::HANDLE, 'before' ) );
		};
		$this->assertStringContainsString( '"voice_input":true', $inline() );
		$this->settings( array( 'voice_input' => false ) );
		$this->assertStringContainsString( '"voice_input":false', $inline() );
	}

	public function test_the_privacy_policy_text_mentions_dictation_only_while_it_is_on(): void {
		$on = self::policy();
		$this->assertStringContainsString( 'You can dictate a question instead of typing it.', $on );
		$this->assertStringContainsString( 'This site never receives the audio', $on );

		$this->settings( array( 'voice_input' => false ) );
		$off = self::policy();
		$this->assertStringNotContainsString( 'dictate', $off );
		$this->assertStringContainsString( 'Our help chat answers questions with AI', $off, 'the rest stays' );
	}
}
