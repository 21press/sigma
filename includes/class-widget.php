<?php
/**
 * Prints the visitor widget (apps/widget, built to build/widget/).
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma;

defined( 'ABSPATH' ) || exit;

/**
 * One deferred script on the front end; the bubble loads its panel chunk itself on first click. No
 * REST call until the visitor opens it. Nothing per-user in the page, which is cached, except while
 * "Show to admins only" is on: then only admins get the widget (pages are not cached for them) and
 * it carries a REST nonce so the API can tell an admin is asking.
 */
final class Widget {

	public const HANDLE = 'sigma-widget';

	/**
	 * Path of the built bubble script, relative to the plugin folder.
	 */
	public static function script_path(): string {
		return 'build/widget/widget.js';
	}

	/**
	 * Whether this front-end request gets the widget.
	 */
	public static function should_load(): bool {
		if ( is_admin() || Plugin::killed() ) {
			return false;
		}
		if ( Settings::get( 'admins_only' ) && ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		/**
		 * Filters whether the widget loads on this page.
		 *
		 * @param bool $load Load it.
		 */
		return (bool) \TwentyOnePress\Sigma\Hooks::filter( 'show_widget', true );
	}

	/**
	 * Enqueue on wp_enqueue_scripts.
	 */
	public static function enqueue(): void {
		if ( ! self::should_load() || ! is_readable( P21_SIGMA_DIR . '/' . self::script_path() ) ) {
			return;
		}
		wp_enqueue_script(
			self::HANDLE,
			plugins_url( self::script_path(), P21_SIGMA_FILE ),
			array(),
			(string) filemtime( P21_SIGMA_DIR . '/' . self::script_path() ),
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
		// The look, so the launcher has its colour and side before /config loads. Settings only:
		// the same for every visitor, so cached pages stay correct.
		$data = array(
			'root'           => esc_url_raw( rest_url( 'sigma/v1/' ) ),
			// §8.5: the teaser and pulse run before the chat opens, so they travel here, not in /config.
			'bot_name'       => Settings::bot_name(),
			'bot_avatar_url' => Team\Avatars::bot_url(), // The teaser shows it before any REST call.
			'teaser'         => array(
				'enabled' => (bool) Settings::get( 'teaser_enabled' ),
				'text'    => Settings::text( 'teaser_text' ),
			),
			'pulse'          => (bool) Settings::get( 'launcher_pulse' ),
			'voice_input'    => (bool) Settings::get( 'voice_input' ), // §19.4: the panel shows the mic only when the browser can, too.
			'brand'          => array(
				'name'          => Settings::brand_name(),
				'font'          => (string) Settings::get( 'font' ),
				'color_light'   => (string) Settings::get( 'color_light' ),
				'color_dark'    => (string) Settings::get( 'color_dark' ),
				// Panel palette and launcher icon (#41); empty colours = Delta's own.
				'bg_light'      => (string) Settings::get( 'color_bg_light' ),
				'bg_dark'       => (string) Settings::get( 'color_bg_dark' ),
				'text_light'    => (string) Settings::get( 'color_text_light' ),
				'text_dark'     => (string) Settings::get( 'color_text_dark' ),
				'launcher_icon' => (string) Settings::get( 'launcher_icon' ),
				'scheme'        => (string) Settings::get( 'scheme' ),
				'position'      => (string) Settings::get( 'position' ),
				'offset_x'      => (int) Settings::get( 'offset_x' ),
				'offset_y'      => (int) Settings::get( 'offset_y' ),
			),
		);
		if ( Settings::get( 'admins_only' ) ) {
			$data['nonce'] = wp_create_nonce( 'wp_rest' );
		}
		// §19.3: the chat box's strings in the site's language (only those that differ from English).
		$i18n = Widget_Strings::translated();
		if ( $i18n ) {
			$data['i18n'] = $i18n;
		}
		wp_add_inline_script( self::HANDLE, 'window.SigmaWidget=' . wp_json_encode( $data ) . ';', 'before' );
	}
}
