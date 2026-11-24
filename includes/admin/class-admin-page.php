<?php
/**
 * The wp-admin menu and the React app (apps/admin, built to build/admin/).
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Admin;

use TwentyOnePress\Sigma\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Three screens: Settings and Knowledge (manage_options), and Conversations (delta_assistant_view). Assets load
 * only on these screens. React comes from WordPress (wp-element), never bundled.
 */
final class Admin_Page {

	public const SLUG          = 'sigma';
	public const CONVERSATIONS = 'sigma-conversations';
	public const KNOWLEDGE     = 'sigma-knowledge';

	/**
	 * Screen hook suffixes, set when the menu is added.
	 *
	 * @var array<string, string>
	 */
	private static array $screens = array();

	/**
	 * Hooks.
	 */
	public static function hooks(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'admin_head', array( Remy_Icon::class, 'menu_css' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( P21_SIGMA_FILE ), array( self::class, 'action_links' ) );
	}

	/**
	 * The menu.
	 */
	public static function menu(): void {
		$cap = current_user_can( 'manage_options' ) ? 'manage_options' : Plugin::CAP_VIEW;
		add_menu_page(
			__( 'Sigma', 'sigma' ),
			__( 'Sigma', 'sigma' ),
			$cap,
			current_user_can( 'manage_options' ) ? self::SLUG : self::CONVERSATIONS,
			array( self::class, 'render' ),
			Remy_Icon::menu_icon(), // §8.8: Remy's icon, the same one the chat box shows.
			81
		);
		if ( current_user_can( 'manage_options' ) ) {
			self::$screens['settings'] = (string) add_submenu_page( self::SLUG, __( 'Sigma settings', 'sigma' ), __( 'Settings', 'sigma' ), 'manage_options', self::SLUG, array( self::class, 'render' ) );
			$parent                    = self::SLUG;
			// §7.6: between Settings and Conversations, as in the design mockup.
			self::$screens['knowledge'] = (string) add_submenu_page( self::SLUG, __( 'Knowledge', 'sigma' ), __( 'Knowledge', 'sigma' ), 'manage_options', self::KNOWLEDGE, array( self::class, 'render' ) );
		} else {
			$parent = self::CONVERSATIONS;
		}
		self::$screens['conversations'] = (string) add_submenu_page( $parent, __( 'Conversations', 'sigma' ), __( 'Conversations', 'sigma' ), Plugin::CAP_VIEW, self::CONVERSATIONS, array( self::class, 'render' ) );
	}

	/**
	 * The app root.
	 */
	public static function render(): void {
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : self::SLUG; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Which screen to show; no action.
		$screen = self::CONVERSATIONS === $page ? 'conversations' : ( self::KNOWLEDGE === $page ? 'knowledge' : 'settings' );
		if ( in_array( $screen, array( 'settings', 'knowledge' ), true ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'sigma' ) );
		}
		// 0.2.5: the screen's frame as the app draws it (no "Loading…" text, no layout jump). Its data is in the
		// page already (preload, see enqueue()), so the app replaces this as soon as its script has run.
		printf(
			'<div class="wrap">%4$s<div id="sigma-admin" data-screen="%1$s">%2$s</div><noscript>%3$s</noscript></div>',
			esc_attr( $screen ),
			is_readable( P21_SIGMA_DIR . '/build/admin/admin.js' ) ? self::skeleton( $screen ) : self::failed_notice(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
			esc_html__( 'This screen needs JavaScript.', 'sigma' ),
			self::build_notice() // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
		);
		self::frame_script();
	}

	/**
	 * The stale-build notice, printed by PHP in the page frame (0.2.5, ARCH): it exists for the case where the app is
	 * old or missing, so it must not depend on the app. It sits just before the app's root (an old app replaces
	 * everything inside its root); the frame script moves it into the content column, under the header, whenever any
	 * app version draws its page body. With JS off it stays here, visible. The app skips its own copy. Escaped.
	 */
	public static function build_notice(): string {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}
		foreach ( Notices::collect() as $notice ) {
			if ( 'build-stale' !== $notice['id'] ) {
				continue;
			}
			$text = (string) preg_replace( '/`([^`]+)`/', '<code>$1</code>', esc_html( (string) $notice['text'] ) );
			// .notice-error/.inline: wp-admin's own styling if Sigma's CSS is missing, and common.js leaves it in place.
			return '<div id="sigma-build-notice" class="sigma-admin sigma-build-frame"><div class="notice notice--danger notice-error inline" role="alert"><p>' . $text . '</p></div></div>';
		}
		return '';
	}

	/** How long the app may take to start before the frame says it couldn't load, in milliseconds. */
	public const APP_START_TIMEOUT = 10000;

	/**
	 * The frame the app draws, with Alpha skeleton rows where the content goes: the Sigma header, the screen's
	 * title and, on Settings, the tabs and the side panel. Escaped.
	 *
	 * @param string $screen settings|knowledge|conversations.
	 */
	public static function skeleton( string $screen ): string {
		$rows   = static function ( int $n, string $label ): string {
			$widths = array( '', ' skeleton__line--half', '', ' skeleton__line--third', '' );
			$out    = '';
			for ( $i = 0; $i < $n; $i++ ) {
				$out .= '<span class="skeleton__line' . $widths[ $i % 5 ] . '"></span>';
			}
			return '' !== $label
				? '<div class="skeleton" role="status" aria-label="' . esc_attr( $label ) . '">' . $out . '</div>'
				: '<div class="skeleton">' . $out . '</div>';
		};
		$icon   = Remy_Icon::header_icon();
		$header = '<header class="plugin__topbar"><h1 class="plugin__brand"><span class="cluster">'
			. ( '' !== (string) $icon['url'] ? '<img class="brand-mark brand-mark--remy" src="' . esc_url( (string) $icon['url'], array( 'http', 'https', 'data' ) ) . '" alt="" width="24" height="24" />' : '' )
			. esc_html__( 'Sigma', 'sigma' ) . '</span></h1>'
			// The light/dark switcher as the app draws it (moon in light, sun in dark, by data-theme), inert until the app
			// replaces the frame: no header jump, and the right icon on the first paint.
			. '<div class="plugin__topbar-actions"><span class="plugin__theme-toggle" aria-hidden="true">'
			. '<span class="theme-toggle__icon theme-toggle__icon--moon"><svg class="icon icon--sm" viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3h.393a7.5 7.5 0 0 0 7.92 12.446A9 9 0 1 1 12 2.992z"/></svg></span>'
			. '<span class="theme-toggle__icon theme-toggle__icon--sun"><svg class="icon icon--sm" viewBox="0 0 24 24" aria-hidden="true"><g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"><path d="M8 12a4 4 0 1 0 8 0a4 4 0 1 0-8 0"/><path d="M3 12h1m8-9v1m8 8h1m-9 8v1M5.6 5.6l.7.7m12.1-.7l-.7.7m0 11.4l.7.7m-12.1-.7l-.7.7"/></g></svg></span>'
			. '</span></div></header>';
		$title = static fn( string $text, string $lead ): string => '<div class="card__header"><header class="page-header"><h2 class="page-header__title">' . esc_html( $text ) . '</h2><p class="page-header__lead">' . esc_html( $lead ) . '</p></header></div>';

		if ( 'settings' === $screen ) {
			$tabs = '';
			foreach ( array( __( 'Chat box', 'sigma' ), __( 'AI agent', 'sigma' ), __( 'Human support', 'sigma' ) ) as $tab ) {
				$tabs .= '<span class="tabs__tab">' . esc_html( $tab ) . '</span>';
			}
			$body = '<div class="plugin__body"><div class="columns"><div class="columns__main"><section class="card card--flush">'
				. $title( __( 'Settings', 'sigma' ), __( 'The chat box on your site: an AI agent that answers from your content, and your team for the rest.', 'sigma' ) )
				. '<nav class="tabs tabs--card tabs--nav" aria-hidden="true">' . $tabs . '</nav>'
				. '<div class="card__body">' . $rows( 8, __( 'Loading settings', 'sigma' ) ) . '</div></section></div>'
				. '<aside class="columns__aside" aria-hidden="true"><section class="card"><div class="card__body">' . $rows( 4, '' ) . '</div></section></aside></div></div>';
		} elseif ( 'knowledge' === $screen ) {
			$body = '<div class="plugin__body plugin__body--full"><section class="card card--flush">'
				. $title( __( 'Knowledge', 'sigma' ), __( 'Notes the agent reads before it answers: prices, policies, how-tos. Write them in Markdown, or import .md files.', 'sigma' ) )
				. '<div class="knowledge__notice">' . $rows( 6, __( 'Loading entries', 'sigma' ) ) . '</div></section></div>';
		} else {
			$body = '<div class="plugin__body plugin__body--full"><section class="card card--flush">'
				. $title( __( 'Conversations', 'sigma' ), __( 'Read-only transcripts.', 'sigma' ) )
				. '<div class="card__body">' . $rows( 6, __( 'Loading conversations', 'sigma' ) ) . '</div></section></div>';
		}
		return '<div class="sigma-admin">' . $header . $body . '</div>';
	}

	/**
	 * The message when the app can't run (its script missing, or not started in time). Escaped.
	 */
	public static function failed_notice(): string {
		return '<div class="notice notice-error inline"><p>' . sprintf(
			/* translators: %s: the command, "npm run build". */
			esc_html__( 'This screen couldn\'t load. If you installed Sigma from git, run %s in the plugin folder; otherwise reinstall the plugin.', 'sigma' ),
			'<code>npm run build</code>'
		) . '</p></div>';
	}

	/**
	 * A few lines after the frame: the dark mode saved by the app (so the skeleton matches it), and the
	 * fallback when the app hasn't started within APP_START_TIMEOUT (main.tsx sets data-mounted).
	 */
	private static function frame_script(): void {
		$script = sprintf(
			'(function(){var r=document.getElementById("sigma-admin");if(!r)return;var b=document.getElementById("sigma-build-notice");var t="light";try{if(localStorage.getItem("sigma-admin-theme")==="dark")t="dark";}catch(e){}var s=r.querySelector(".sigma-admin");if(s)s.setAttribute("data-theme",t);if(b)b.setAttribute("data-theme",t);if(b&&b.firstElementChild){var n=b.firstElementChild,p=function(){var y=r.querySelector(".plugin__body");if(y){if(n.parentNode!==y)y.insertBefore(n,y.firstChild);}else if(n.parentNode!==b){b.appendChild(n);}};new MutationObserver(p).observe(r,{childList:true,subtree:true});p();}setTimeout(function(){if(!r.dataset.mounted){r.innerHTML=%1$s;}},%2$d);})();',
			wp_json_encode( self::failed_notice() ),
			self::APP_START_TIMEOUT
		);
		wp_print_inline_script_tag( $script, array( 'id' => 'sigma-admin-frame' ) );
	}

	/**
	 * The REST paths a screen requests as soon as it starts, exactly as the app asks for them (query keys in
	 * any order: apiFetch normalises both sides).
	 *
	 * @param string $screen settings|knowledge|conversations.
	 * @return list<string>
	 */
	public static function preload_paths( string $screen ): array {
		switch ( $screen ) {
			case 'settings':
				return array( '/sigma/v1/admin/settings' );
			case 'knowledge':
				$paths = array( '/sigma/v1/admin/knowledge?page=1&per_page=20&search=&status=all' );
				$edit  = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Which entry the drawer opens; no action.
				if ( $edit ) {
					$paths[] = '/sigma/v1/admin/knowledge/' . $edit;
				}
				return $paths;
			case 'conversations':
				return array( '/sigma/v1/admin/conversations?page=1&per_page=20&search=&status=' );
		}
		return array();
	}

	/**
	 * Assets on the plugin's screens only.
	 *
	 * @param string $hook Screen hook suffix.
	 */
	public static function enqueue( $hook ): void {
		if ( ! in_array( $hook, self::$screens, true ) ) {
			return;
		}
		$js = P21_SIGMA_DIR . '/build/admin/admin.js';
		if ( ! is_readable( $js ) ) {
			return;
		}
		if ( ( self::$screens['settings'] ?? '' ) === $hook && current_user_can( 'upload_files' ) ) {
			wp_enqueue_media(); // Settings → Remy → Avatar picks from the Media Library.
		}
		wp_enqueue_script(
			'sigma-admin',
			plugins_url( 'build/admin/admin.js', P21_SIGMA_FILE ),
			array( 'wp-element', 'react-jsx-runtime', 'wp-api-fetch', 'wp-i18n' ),
			(string) filemtime( $js ),
			true
		);
		// §19.3: the admin app's strings from languages/sigma-{locale}-{md5 of build/admin/admin.js}.json, or
		// from WordPress's own language packs folder.
		wp_set_script_translations( 'sigma-admin', 'sigma', P21_SIGMA_DIR . '/languages' );
		// 0.2.5: the screen's first requests are answered from the page (WordPress's own preloading, as the block
		// editor does), so the app draws real content with no extra round trip, which boots WordPress again.
		$preload = self::preload_paths( (string) array_search( $hook, self::$screens, true ) );
		if ( $preload ) {
			wp_add_inline_script(
				'wp-api-fetch',
				sprintf( 'wp.apiFetch.use( wp.apiFetch.createPreloadingMiddleware( %s ) );', wp_json_encode( array_reduce( $preload, 'rest_preload_api_request', array() ) ) ),
				'after'
			);
		}
		wp_add_inline_script(
			'sigma-admin',
			'window.SigmaAdmin=' . wp_json_encode(
				array(
					'canManage'  => current_user_can( 'manage_options' ),
					'adminUrl'   => admin_url( 'admin.php' ),
					'homeUrl'    => home_url( '/' ),
					'restRoot'   => esc_url_raw( rest_url( 'sigma/v1/' ) ),
					'nonce'      => wp_create_nonce( 'wp_rest' ),
					'remyIcon'   => Remy_Icon::header_icon(),
					'botName'    => \TwentyOnePress\Sigma\Settings::bot_name(), // The AI's label in transcripts (no hard-coded name, 0.2.1).
					'widgetI18n' => (object) \TwentyOnePress\Sigma\Widget_Strings::translated(), // The live preview's chat box strings (§19.3).
					'notices'    => Notices::for_screen( (string) array_search( $hook, self::$screens, true ) ), // Shown in the content column, not by admin_notices (0.2.5).
				)
			) . ';',
			'before'
		);
		$css = P21_SIGMA_DIR . '/build/admin/admin.css';
		if ( is_readable( $css ) ) {
			wp_enqueue_style( 'sigma-admin', plugins_url( 'build/admin/admin.css', P21_SIGMA_FILE ), array(), (string) filemtime( $css ) );
		}
	}

	/**
	 * "Settings" link on the Plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return list<string>
	 */
	public static function action_links( array $links ): array {
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ), esc_html__( 'Settings', 'sigma' ) ) );
		return $links;
	}
}
