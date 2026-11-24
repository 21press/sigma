<?php
/**
 * Post → clean text for the AI (§7.3).
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a post's content the way visitors see it (the_content), then keeps what helps an answer:
 * headings as Markdown (`##` for h1 and h2, `###` for h3, …), list items, code blocks, paragraphs. Everything else is stripped.
 *
 * Strategies: a post type whose text is not in post_content (Delta's recipes and releases, R2)
 * supplies it through the `sigma_extract_text` filter.
 */
final class Extractor {

	/**
	 * Text of a post.
	 *
	 * @param \WP_Post $item Post.
	 */
	public static function text( \WP_Post $item ): string {
		/**
		 * Filters the text of a post before the default extraction. Return a string to replace it.
		 *
		 * @param string|null $text Null to use post_content.
		 * @param \WP_Post    $item Post.
		 */
		$custom = \TwentyOnePress\Sigma\Hooks::filter( 'extract_text', null, $item );
		if ( is_string( $custom ) ) {
			return self::from_html( $custom );
		}

		global $post;
		$previous = $post;
		$post     = $item; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the_content filters read the global post; restored below.
		setup_postdata( $item );
		$html = (string) apply_filters( 'the_content', $item->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
		$post = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring.
		if ( $previous instanceof \WP_Post ) {
			setup_postdata( $previous );
		} else {
			wp_reset_postdata();
		}
		return self::from_html( $html );
	}

	/**
	 * HTML → text with light Markdown. Pure: the unit layer tests it.
	 *
	 * @param string $html HTML.
	 */
	public static function from_html( string $html ): string {
		// Things that are never content.
		$html = (string) preg_replace( '#<(script|style|noscript|svg|template|button|form|iframe)\b[^>]*>.*?</\1>#is', ' ', $html );
		$html = (string) preg_replace( '#<!--.*?-->#s', '', $html );

		// Code blocks: kept verbatim, fenced. Swapped out so the rules below leave them alone.
		$blocks = array();
		$html   = (string) preg_replace_callback(
			'#<pre\b[^>]*>(.*?)</pre>#is',
			static function ( array $m ) use ( &$blocks ): string {
				$code     = html_entity_decode( strip_tags( (string) preg_replace( '#<br\s*/?>#i', "\n", $m[1] ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Plain text of a code block; wp_strip_all_tags() would trim it.
				$blocks[] = "```\n" . trim( $code, "\n" ) . "\n```";
				return "\n\nDAPRE" . ( count( $blocks ) - 1 ) . "X\n\n";
			},
			$html
		);

		$html = (string) preg_replace_callback(
			'#<h([1-6])\b[^>]*>(.*?)</h\1>#is',
			static fn( array $m ): string => "\n\n" . str_repeat( '#', max( 2, (int) $m[1] ) ) . ' ' . trim( (string) preg_replace( '/\s+/', ' ', strip_tags( $m[2] ) ) ) . "\n\n", // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Inline text.
			$html
		);
		$html = (string) preg_replace( '#<li\b[^>]*>#i', "\n- ", $html );
		$html = (string) preg_replace( '#<(br)\s*/?>#i', "\n", $html );
		$html = (string) preg_replace( '#</(p|div|ul|ol|table|blockquote|figure|section|details|summary|dd|dt)>#i', "\n\n", $html );
		$html = (string) preg_replace( '#</tr>#i', "\n", $html );
		$html = (string) preg_replace( '#<(td|th)\b[^>]*>#i', ' | ', $html );
		$html = (string) preg_replace( '#<code\b[^>]*>(.*?)</code>#is', '`$1`', $html );
		$html = (string) preg_replace( '#<(strong|b)\b[^>]*>(.*?)</\1>#is', '**$2**', $html );

		$text = html_entity_decode( strip_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Newlines matter here; wp_strip_all_tags() collapses them only on request, but also trims.
		$text = str_replace( "\xc2\xa0", ' ', $text );

		$lines = array();
		foreach ( explode( "\n", $text ) as $line ) {
			$lines[] = trim( (string) preg_replace( '/[ \t]+/', ' ', $line ) );
		}
		$text = trim( (string) preg_replace( "/\n{3,}/", "\n\n", implode( "\n", $lines ) ) );
		$text = (string) preg_replace( "/^- \n/m", '', $text );

		return (string) preg_replace_callback( '/DAPRE(\d+)X/', static fn( array $m ): string => $blocks[ (int) $m[1] ] ?? '', $text );
	}
}
