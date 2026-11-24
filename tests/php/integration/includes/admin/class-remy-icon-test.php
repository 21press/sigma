<?php
/**
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Tests\Integration;

use TwentyOnePress\Sigma\Admin\Admin_Page;
use TwentyOnePress\Sigma\Admin\Remy_Icon;
use TwentyOnePress\Sigma\Plugin;
use TwentyOnePress\Sigma\Settings;
use TwentyOnePress\Sigma\Team\Avatars;
use TwentyOnePress\Sigma\Tests\Support\Integration_Test_Case;

/**
 * One Remy icon (§8.8, 0.1.8): the setting, the migration, the wp-admin menu icon and the Settings
 * header icon, all from the shared icon files the widget build imports.
 *
 * @covers \TwentyOnePress\Sigma\Admin\Remy_Icon
 * @covers \TwentyOnePress\Sigma\Admin\Admin_Page
 * @covers \TwentyOnePress\Sigma\Settings
 * @covers \TwentyOnePress\Sigma\Plugin
 */
final class Remy_Icon_Test extends Integration_Test_Case {

	public function set_up(): void {
		parent::set_up();
		Remy_Icon::flush();
		$this->answer_http( static fn( string $url ) => new \WP_Error( 'not_in_tests', $url ) );
	}

	public function tear_down(): void {
		foreach ( (array) glob( trailingslashit( (string) wp_get_upload_dir()['basedir'] ) . 'delta-assistant/avatars/*' ) as $file ) {
			wp_delete_file( (string) $file );
		}
		parent::tear_down();
	}

	private function image(): int {
		$file = trailingslashit( sys_get_temp_dir() ) . 'da-icon-' . wp_generate_password( 8, false ) . '.png';
		$img  = imagecreatetruecolor( 200, 200 );
		imagefill( $img, 0, 0, imagecolorallocate( $img, 30, 120, 90 ) );
		imagepng( $img, $file );
		return (int) self::factory()->attachment->create_upload_object( $file );
	}

	/**
	 * The menu icon registered by Admin_Page::menu() for this request.
	 */
	private function registered_menu_icon(): string {
		global $menu, $submenu, $_wp_real_parent_file, $_registered_pages, $admin_page_hooks;
		$menu                 = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A fresh admin menu per call.
		$submenu              = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$_wp_real_parent_file = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$_registered_pages    = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$admin_page_hooks     = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		Admin_Page::menu();
		foreach ( $menu as $item ) {
			if ( Admin_Page::SLUG === $item[2] ) {
				return (string) $item[6];
			}
		}
		return '';
	}

	/**
	 * The path data of a shared icon file.
	 *
	 * @param string $icon Icon.
	 */
	private static function path_of( string $icon ): string {
		$file = 'remy' === $icon ? 'assets/remy-avatar.svg' : 'assets/icons/' . $icon . '.svg';
		preg_match( '/<path[^>]*\sd="([^"]+)"/', (string) file_get_contents( P21_SIGMA_DIR . '/' . $file ), $m ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return $m[1];
	}

	public function test_each_built_in_menu_icon_is_its_shared_path_in_one_tintable_colour(): void {
		foreach ( Remy_Icon::BUILTINS as $icon ) {
			$this->settings( array( 'launcher_icon' => $icon ) );
			$uri = $this->registered_menu_icon();
			$this->assertStringStartsWith( 'data:image/svg+xml;base64,', $uri, $icon );
			$svg = (string) base64_decode( substr( $uri, strlen( 'data:image/svg+xml;base64,' ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			$this->assertStringContainsString( 'd="' . self::path_of( $icon ) . '"', $svg, "{$icon}: the same path data as the widget" );
			// wp-admin's svg-painter rewrites fill="…", style="…" and fill:…; only. One fill, nothing else to miss.
			$this->assertSame( 1, preg_match_all( '/fill="/', $svg ), "{$icon}: a single tintable fill" );
			$this->assertStringNotContainsString( 'style=', $svg, $icon );
			$this->assertStringNotContainsString( 'currentColor', $svg, $icon );
			$this->assertStringNotContainsString( ';', $svg, "{$icon}: nothing for the fill:…; rewrite to over-match" );
		}
	}

	public function test_image_uses_the_avatar_copy_and_a_missing_copy_falls_back_to_assistant(): void {
		$image = $this->image();
		$this->assertSame(
			200,
			$this->rest(
				'POST',
				'/admin/settings',
				array(
					'launcher_icon' => 'image',
					'bot_avatar'    => $image,
				)
			)->get_status()
		);
		$copy = Avatars::bot_url();
		$this->assertNotSame( '', $copy, 'the copy was made' );
		$this->assertSame( $copy, $this->registered_menu_icon(), 'image: the local copy' );
		$this->assertSame(
			array(
				'kind' => 'image',
				'url'  => $copy,
			),
			Remy_Icon::header_icon()
		);
		ob_start();
		Remy_Icon::menu_css();
		$this->assertStringContainsString( 'border-radius:50%', (string) ob_get_clean(), 'shown round' );

		foreach ( (array) glob( trailingslashit( (string) wp_get_upload_dir()['basedir'] ) . 'delta-assistant/avatars/*' ) as $file ) {
			wp_delete_file( (string) $file );
		}
		$this->assertSame( 'assistant', Remy_Icon::current() );
		$this->settings( array( 'launcher_icon' => 'assistant' ) );
		$assistant = $this->registered_menu_icon();
		$this->settings( array( 'launcher_icon' => 'image' ) );
		$this->assertSame( $assistant, $this->registered_menu_icon(), 'no copy: the assistant icon, never a broken image' );
		$this->assertSame( 'builtin', Remy_Icon::header_icon()['kind'] );
		ob_start();
		Remy_Icon::menu_css();
		$this->assertSame( '', (string) ob_get_clean(), 'no round-image CSS without an image' );
	}

	public function test_changing_the_setting_changes_the_menu_on_the_next_load(): void {
		$this->settings( array( 'launcher_icon' => 'chat' ) );
		$chat = $this->registered_menu_icon();
		$this->assertSame( 200, $this->rest( 'POST', '/admin/settings', array( 'launcher_icon' => 'sparkles' ) )->get_status() );
		$sparkles = $this->registered_menu_icon();
		$this->assertNotSame( $chat, $sparkles );
		$this->assertStringContainsString( self::path_of( 'sparkles' ), (string) base64_decode( substr( $sparkles, 26 ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}

	public function test_image_without_an_upload_is_refused_with_the_field_named(): void {
		$response = $this->rest( 'POST', '/admin/settings', array( 'launcher_icon' => 'image' ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'launcher_icon', $response->as_error()->get_error_data()['field'] );
		$this->assertSame( 'assistant', Settings::get( 'launcher_icon' ), 'nothing saved' );
		$this->assertSame( 400, $this->rest( 'POST', '/admin/settings', array( 'launcher_icon' => 'rocket' ) )->get_status() );
	}

	public function test_settings_lists_every_built_in_on_the_brand_disc(): void {
		$this->settings( array( 'color_light' => '#26292a' ) );
		$icons = $this->rest( 'GET', '/admin/settings' )->get_data()['remy_icons'];
		$this->assertSame( Remy_Icon::BUILTINS, array_keys( $icons ) );
		foreach ( $icons as $icon => $uri ) {
			$svg = (string) base64_decode( substr( $uri, 26 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			$this->assertStringContainsString( 'fill="#26292a"', $svg, "{$icon}: the brand disc" );
			$this->assertStringContainsString( '#ffffff', $svg, "{$icon}: the mark in the on-brand colour" );
			$this->assertStringContainsString( self::path_of( $icon ), $svg, $icon );
		}
		$this->assertGreaterThanOrEqual( 3.0, Remy_Icon::ratio( '#26292a', Remy_Icon::on_brand( '#26292a' ) ) );
		foreach ( array( '#ffffff', '#f5c400', '#0057b8', '#777777' ) as $brand ) {
			$this->assertGreaterThanOrEqual( 3.0, Remy_Icon::ratio( $brand, Remy_Icon::on_brand( $brand ) ), "{$brand}: mark contrast at least 3:1" );
		}
	}

	public function test_the_018_migration_turns_an_uploaded_avatar_into_the_image_icon(): void {
		$stored                  = (array) get_option( Settings::OPTION );
		$stored['bot_avatar']    = $this->image();
		$stored['launcher_icon'] = 'chat';
		update_option( Settings::OPTION, $stored );
		Settings::flush();
		update_option( Plugin::VERSION_OPTION, '0.1.6' ); // Any version before 0.1.8.
		Plugin::maybe_version_change();
		$this->assertSame( 'image', Settings::get( 'launcher_icon' ), 'the uploaded avatar is Remy\'s icon everywhere now' );

		$stored                  = (array) get_option( Settings::OPTION );
		$stored['bot_avatar']    = 0;
		$stored['launcher_icon'] = 'sparkles';
		update_option( Settings::OPTION, $stored );
		Settings::flush();
		Plugin::migrate_018();
		$this->assertSame( 'sparkles', Settings::get( 'launcher_icon' ), 'no avatar: the stored icon stays' );
	}
}
