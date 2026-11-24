<?php
/**
 * Remy's icon in wp-admin (§8.8): the menu icon and the Settings header icon.
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Admin;

use TwentyOnePress\Sigma\Settings;
use TwentyOnePress\Sigma\Team\Avatars;

defined( 'ABSPATH' ) || exit;

/**
 * One icon is Remy's identity (`launcher_icon`). The path data comes from the same files the widget
 * build imports (assets/icons/*.svg, and assets/remy-avatar.svg for `remy`), so the menu, the admin
 * header and the widget cannot drift apart.
 *
 * The menu icon is a one-colour disc with the icon cut out, drawn with fills only: wp-admin's
 * svg-painter tints menu icons by rewriting `fill` (not `stroke`), so line icons are cut out through a
 * mask whose own shapes carry no `fill` attribute. `image` uses the local avatar copy, shown round.
 */
final class Remy_Icon {

	/** The built-in icons. */
	public const BUILTINS = array( 'assistant', 'remy', 'chat', 'sparkles', 'help' );

	/** Text colours on the brand disc (the widget's own). */
	private const ON_BRAND = array( '#15171b', '#ffffff' );

	/**
	 * Shapes read from the asset files, by icon.
	 *
	 * @var array<string, array<string, mixed>|null>
	 */
	private static array $shapes = array();

	/**
	 * The icon shown now: the setting, with `image` falling back to `assistant` while there is no copy.
	 */
	public static function current(): string {
		$icon = (string) Settings::get( 'launcher_icon' );
		if ( 'image' === $icon ) {
			return '' !== Avatars::bot_url() ? 'image' : 'assistant';
		}
		return in_array( $icon, self::BUILTINS, true ) ? $icon : 'assistant';
	}

	/**
	 * The add_menu_page() icon: a data URI for a built-in, the avatar copy URL for `image`.
	 */
	public static function menu_icon(): string {
		$icon = self::current();
		if ( 'image' === $icon ) {
			return Avatars::bot_url();
		}
		return 'data:image/svg+xml;base64,' . base64_encode( self::menu_svg( $icon ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- wp-admin wants a base64 SVG data URI.
	}

	/**
	 * The Settings header icon (24 px): a built-in on the brand disc, or the round avatar copy.
	 *
	 * @return array{kind: string, url: string}
	 */
	public static function header_icon(): array {
		$icon = self::current();
		if ( 'image' === $icon ) {
			return array(
				'kind' => 'image',
				'url'  => Avatars::bot_url(),
			);
		}
		return array(
			'kind' => 'builtin',
			'url'  => self::data_uri( self::disc_svg( $icon ) ),
		);
	}

	/**
	 * Every built-in on the brand disc, for the radio choices in Settings.
	 *
	 * @return array<string, string> Data URI by icon.
	 */
	public static function choices(): array {
		$out = array();
		foreach ( self::BUILTINS as $icon ) {
			$out[ $icon ] = self::data_uri( self::disc_svg( $icon ) );
		}
		return $out;
	}

	/**
	 * Admin CSS for the round `image` menu icon (printed only then, on every admin screen: the menu is).
	 */
	public static function menu_css(): void {
		if ( 'image' !== self::current() ) {
			return;
		}
		$ids = '#toplevel_page_' . Admin_Page::SLUG . ' .wp-menu-image img, #toplevel_page_' . Admin_Page::CONVERSATIONS . ' .wp-menu-image img';
		printf( '<style id="sigma-menu-icon">%s{width:20px;height:20px;padding:7px 0 0;border-radius:50%%;object-fit:cover;opacity:1}</style>', esc_html( $ids ) );
	}

	/**
	 * The one-colour menu SVG of a built-in (24×24, black, tinted by wp-admin).
	 *
	 * @param string $icon Built-in icon.
	 */
	public static function menu_svg( string $icon ): string {
		// Inside the mask: white shows the disc, black cuts it. The disc is a wide white stroke and the
		// shapes use stroke or the default (black) fill, so svg-painter's fill rewrite never reaches them.
		$mask = '<circle cx="12" cy="12" r="6" stroke="white" stroke-width="12"/>' . self::mark( $icon, 'black', 'white', true );
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><defs><mask id="da-remy-m">' . $mask . '</mask></defs><rect width="24" height="24" fill="black" mask="url(#da-remy-m)"/></svg>';
	}

	/**
	 * A built-in on the brand disc, in full colour (24×24).
	 *
	 * @param string $icon Built-in icon.
	 */
	public static function disc_svg( string $icon ): string {
		$brand = self::brand();
		$on    = self::on_brand( $brand );
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="12" fill="' . $brand . '"/>' . self::mark( $icon, $on, $brand, false ) . '</svg>';
	}

	/**
	 * The brand colour of the light scheme (the admin screen is light), #rrggbb.
	 */
	public static function brand(): string {
		$color = strtolower( (string) Settings::get( 'color_light' ) );
		return 1 === preg_match( '/^#[0-9a-f]{6}$/', $color ) ? $color : '#1d1d1f';
	}

	/**
	 * The mark colour on a brand colour: whichever of the widget's two text colours contrasts more.
	 *
	 * @param string $brand #rrggbb.
	 */
	public static function on_brand( string $brand ): string {
		return self::ratio( $brand, self::ON_BRAND[0] ) >= self::ratio( $brand, self::ON_BRAND[1] ) ? self::ON_BRAND[0] : self::ON_BRAND[1];
	}

	/**
	 * WCAG contrast ratio of two #rrggbb colours.
	 *
	 * @param string $a Colour.
	 * @param string $b Colour.
	 */
	public static function ratio( string $a, string $b ): float {
		$l = static function ( string $hex ): float {
			$sum = 0.0;
			foreach ( array( 0.2126, 0.7152, 0.0722 ) as $i => $weight ) {
				$v    = hexdec( substr( $hex, 1 + 2 * $i, 2 ) ) / 255;
				$sum += $weight * ( $v <= 0.03928 ? $v / 12.92 : ( ( $v + 0.055 ) / 1.055 ) ** 2.4 );
			}
			return $sum;
		};
		$x = $l( $a );
		$y = $l( $b );
		return ( max( $x, $y ) + 0.05 ) / ( min( $x, $y ) + 0.05 );
	}

	/**
	 * The icon's mark placed inside the 24×24 disc.
	 *
	 * @param string $icon  Built-in icon.
	 * @param string $ink   Colour of the mark.
	 * @param string $back  Colour of details drawn back on the mark (Remy's eyes).
	 * @param bool   $plain No fill attributes at all (the menu mask).
	 */
	private static function mark( string $icon, string $ink, string $back, bool $plain ): string {
		$shape = self::shape( $icon ) ?? self::shape( 'assistant' );
		if ( null === $shape ) {
			return '';
		}
		$fill = $plain ? '' : ' fill="' . $ink . '"';
		if ( 'remy' === $shape['type'] ) {
			// The 96-unit avatar scaled to the disc: the bubble in ink, the eyes drawn back as round lines.
			$eyes = '';
			foreach ( $shape['eyes'] as [ $x, $y, $w, $h ] ) {
				$cx    = $x + $w / 2;
				$eyes .= sprintf( '<path d="M%1$s %2$sV%3$s" stroke="%4$s" stroke-width="%5$s" stroke-linecap="round"/>', self::num( $cx ), self::num( $y + $w / 2 ), self::num( $y + $h - $w / 2 ), $back, self::num( $w ) );
			}
			return '<g transform="scale(.25)"><g transform="' . esc_attr( $shape['transform'] ) . '"><path d="' . esc_attr( $shape['d'] ) . '"' . $fill . '/>' . $eyes . '</g></g>';
		}
		$box = (float) $shape['size'];
		if ( 'fill' === $shape['type'] ) {
			// Fill marks (the plugin mark) are drawn for a disc of their own viewBox: scale to fit.
			return '<g transform="scale(' . self::num( 24 / $box ) . ')"><path d="' . esc_attr( $shape['d'] ) . '"' . ( $plain ? '' : ' fill="' . $ink . '"' ) . ( $plain ? '' : ' fill-rule="evenodd"' ) . '/></g>';
		}
		// Line icons: 15 units wide in the middle of the disc, the stroke 1.7 units after scaling (about
		// 1.4 px in the 20 px menu).
		$scale = 15 / $box;
		return sprintf(
			'<g transform="translate(4.5 4.5) scale(%1$s)"><path d="%2$s" fill-opacity="0" stroke="%3$s" stroke-width="%4$s" stroke-linecap="round" stroke-linejoin="round"/></g>',
			self::num( $scale ),
			esc_attr( $shape['d'] ),
			$ink,
			self::num( 1.7 / $scale )
		);
	}

	/**
	 * The shape of a built-in from its asset file, or null when the file is missing or unreadable.
	 *
	 * @param string $icon Built-in icon.
	 * @return array<string, mixed>|null
	 */
	public static function shape( string $icon ): ?array {
		if ( array_key_exists( $icon, self::$shapes ) ) {
			return self::$shapes[ $icon ];
		}
		$file                  = 'remy' === $icon ? P21_SIGMA_DIR . '/' . Avatars::BOT_DEFAULT : P21_SIGMA_DIR . '/assets/icons/' . $icon . '.svg';
		$svg                   = in_array( $icon, self::BUILTINS, true ) && is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A bundled asset.
		self::$shapes[ $icon ] = '' === $svg ? null : self::parse( $icon, $svg );
		return self::$shapes[ $icon ];
	}

	/**
	 * Forget the cached shapes (tests).
	 */
	public static function flush(): void {
		self::$shapes = array();
	}

	/**
	 * Parse an icon file.
	 *
	 * @param string $icon Icon.
	 * @param string $svg  SVG markup.
	 * @return array<string, mixed>|null
	 */
	private static function parse( string $icon, string $svg ): ?array {
		if ( 'remy' === $icon ) {
			if ( ! preg_match( '/<g[^>]*\stransform="([^"]+)"/', $svg, $t ) || ! preg_match( '/<path[^>]*\sd="([^"]+)"/', $svg, $p ) ) {
				return null;
			}
			preg_match_all( '/<rect[^>]*\sx="([\d.]+)"[^>]*\sy="([\d.]+)"[^>]*\swidth="([\d.]+)"[^>]*\sheight="([\d.]+)"/', $svg, $r, PREG_SET_ORDER );
			return array(
				'type'      => 'remy',
				'transform' => $t[1],
				'd'         => $p[1],
				'eyes'      => array_map( static fn( array $m ): array => array( (float) $m[1], (float) $m[2], (float) $m[3], (float) $m[4] ), $r ),
			);
		}
		if ( ! preg_match( '/viewBox="0 0 ([\d.]+) [\d.]+"/', $svg, $v ) || ! preg_match( '/<path[^>]*\sd="([^"]+)"/', $svg, $p ) ) {
			return null;
		}
		return array(
			'type' => 1 === preg_match( '/<path[^>]*\sstroke="currentColor"/', $svg ) ? 'stroke' : 'fill',
			'size' => (float) $v[1],
			'd'    => $p[1],
		);
	}

	/**
	 * An SVG as a data URI for an <img>.
	 *
	 * @param string $svg SVG markup.
	 */
	private static function data_uri( string $svg ): string {
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- An image data URI.
	}

	/**
	 * A short number for SVG attributes.
	 *
	 * @param float $n Number.
	 */
	private static function num( float $n ): string {
		return rtrim( rtrim( number_format( $n, 4, '.', '' ), '0' ), '.' );
	}
}
