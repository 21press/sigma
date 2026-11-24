<?php
/**
 * Link allowlist for AI answers (§7.2).
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps only links to the corpus docs and to the allowed hosts (the site's own). Any other link is
 * removed: a Markdown link keeps its label, a bare URL goes. Only http(s). Pure: unit tested.
 *
 * §10b: a URL with a backslash, userinfo (`user@` in the authority), whitespace, control characters
 * or quotes is refused, because PHP and browsers can disagree on its host (`https://evil.com\@site/`
 * parses as site in PHP but opens evil.com). A kept URL is rebuilt from its parsed parts and only the
 * rebuilt string is output. The bare-URL pattern matches at least what the widget would autolink.
 *
 * Non-ASCII (0.1.7): an internationalised host is compared in punycode, and a Unicode path, query or
 * fragment is percent-encoded (uppercase hex), so docs with such URLs stay clickable. A matched doc is
 * output as its own corpus URL, so the answer text and `links` carry the identical string.
 */
final class Link_Filter {

	/**
	 * Filter an answer.
	 *
	 * @param string                $text  Answer text.
	 * @param array<string, string> $docs  Title by corpus URL.
	 * @param string[]              $hosts Allowed hosts (lowercase).
	 * @param string                $base  Site origin for root-relative links (https://example.com).
	 * @return array{text: string, links: list<array{url: string, title: string}>}
	 */
	public static function filter( string $text, array $docs, array $hosts, string $base ): array {
		$index = array();
		foreach ( $docs as $url => $title ) {
			$index[ self::key( (string) $url ) ] = array( (string) $url, (string) $title );
		}
		$hosts = array_map( static fn( $h ): string => self::ascii_host( strtolower( (string) $h ) ) ?? '', $hosts );
		$links = array();

		$check = static function ( string $raw, string $label ) use ( $index, $hosts, $base, &$links ): ?string {
			$url = trim( $raw, " \t<>" );
			if ( str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ) {
				$url = rtrim( $base, '/' ) . $url;
			}
			$url = self::rebuild( $url );
			if ( null === $url ) {
				return null;
			}
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			$key  = self::key( $url );
			if ( isset( $index[ $key ] ) ) {
				$doc           = self::rebuild( $index[ $key ][0] ) ?? $url;
				$links[ $key ] = array(
					'url'   => $doc,
					'title' => $index[ $key ][1],
				);
				return $doc;
			}
			if ( in_array( $host, $hosts, true ) ) {
				$links[ $key ] ??= array(
					'url'   => $url,
					'title' => '' !== $label ? $label : $url,
				);
				return $url;
			}
			return null;
		};

		// Markdown links first; their URLs are then hidden from the bare-URL pass.
		$kept = array();
		$text = (string) preg_replace_callback(
			'/\[([^\]\n]{0,300})\]\(\s*([^)\s]+)(?:\s+"[^"]*")?\s*\)/',
			static function ( array $m ) use ( $check, &$kept ): string {
				$url = $check( $m[2], trim( $m[1] ) );
				if ( null === $url ) {
					return $m[1];
				}
				$kept[] = '[' . $m[1] . '](' . $url . ')';
				return 'DALINK' . ( count( $kept ) - 1 ) . 'X';
			},
			$text
		);

		$text = (string) preg_replace_callback(
			// A superset of the widget's autolink pattern: quotes, brackets and backslashes are part of the
			// match, so a URL built to fool the browser is removed whole instead of being cut and kept.
			'#(?:https?://|www\.)[^\s<>()]+#i',
			static function ( array $m ) use ( $check ): string {
				$raw   = rtrim( $m[0], '.,;:!?' );
				$trail = substr( $m[0], strlen( $raw ) );
				$url   = $check( str_starts_with( strtolower( $raw ), 'www.' ) ? 'https://' . $raw : $raw, '' );
				return null === $url ? $trail : $url . $trail;
			},
			$text
		);

		$text = (string) preg_replace_callback( '/DALINK(\d+)X/', static fn( array $m ): string => $kept[ (int) $m[1] ] ?? '', $text );
		$text = trim( (string) preg_replace( "/[ \t]+\n/", "\n", $text ) );

		return array(
			'text'  => $text,
			'links' => array_values( $links ),
		);
	}

	/**
	 * A safe http(s) URL rebuilt from its parts, or null. Refuses backslashes, userinfo, whitespace,
	 * control characters, quotes and anything outside the URL characters of RFC 3986. Pure.
	 *
	 * @param string $url URL.
	 */
	public static function rebuild( string $url ): ?string {
		$url = self::to_ascii( $url );
		if ( null === $url || '' === $url || 1 === preg_match( '/[^A-Za-z0-9\-._~:\/?#\[\]@!$&()*+,;=%]/', $url ) ) {
			return null; // Backslash, quotes, whitespace, control characters, or what could not be normalised.
		}
		if ( ! preg_match( '#^(https?)://([^/?\#]*)(.*)$#i', $url, $m ) ) {
			return null;
		}
		$authority = $m[2];
		if ( '' === $authority || str_contains( $authority, '@' ) ) {
			return null; // Userinfo: PHP and the browser may take different hosts.
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || ! preg_match( '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $host ) ) {
			return null;
		}
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		if ( strtolower( $authority ) !== $host . ( $port ? ':' . $port : '' ) ) {
			return null; // The parsed host is not literally the authority: refuse rather than guess.
		}
		$path = (string) ( $parts['path'] ?? '' );
		if ( '' !== $path && '/' !== $path[0] ) {
			return null;
		}
		return $scheme . '://' . $host . ( $port ? ':' . $port : '' ) . $path
			. ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' )
			. ( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
	}

	/**
	 * Comparison key of a URL: lowercase scheme-less host + path with one trailing slash, no query or
	 * fragment.
	 *
	 * @param string $url URL.
	 */
	public static function key( string $url ): string {
		$parts = wp_parse_url( self::to_ascii( $url ) ?? $url );
		$host  = strtolower( (string) ( $parts['host'] ?? '' ) );
		$path  = '/' . trim( (string) ( $parts['path'] ?? '' ), '/' );
		$path  = (string) preg_replace_callback( '/%[0-9a-f]{2}/i', static fn( array $m ): string => strtoupper( $m[0] ), $path );
		return $host . ( '/' === $path ? '/' : $path . '/' );
	}

	/**
	 * A URL with a non-ASCII host in punycode and other non-ASCII characters percent-encoded, or null.
	 * Refused first, on the raw string: backslash, quotes, angle brackets, whitespace (any Unicode
	 * space), control and invisible format characters (bidi overrides, zero-width). Pure.
	 *
	 * @param string $url URL.
	 */
	private static function to_ascii( string $url ): ?string {
		if ( 1 !== preg_match( '//u', $url ) || 1 === preg_match( '/[\\\\"\'`<>\x00-\x20\x7F]|[\p{Z}\p{Cc}\p{Cf}]/u', $url ) ) {
			return null; // Not UTF-8, or a character browsers and PHP may read differently.
		}
		if ( 1 !== preg_match( '/[^\x00-\x7F]/', $url ) ) {
			return $url;
		}
		if ( ! preg_match( '#^([a-z][a-z0-9+.-]*://)([^/?\#]*)(.*)$#isu', $url, $m ) ) {
			return null;
		}
		$host = self::ascii_host( $m[2] );
		if ( null === $host ) {
			return null;
		}
		$rest = (string) preg_replace_callback( '/[^\x00-\x7F]+/', static fn( array $c ): string => rawurlencode( $c[0] ), $m[3] );
		return $m[1] . $host . $rest;
	}

	/**
	 * An authority (host, optional port) with an internationalised host in punycode; ASCII as given.
	 * Null when it cannot be converted (no intl extension, or an invalid name).
	 *
	 * @param string $authority Host, maybe with :port.
	 */
	private static function ascii_host( string $authority ): ?string {
		if ( 1 !== preg_match( '/[^\x00-\x7F]/', $authority ) ) {
			return $authority;
		}
		if ( str_contains( $authority, '@' ) || ! function_exists( 'idn_to_ascii' ) ) {
			return null;
		}
		$port = '';
		if ( preg_match( '/^(.*?)(:\d{1,5})$/', $authority, $p ) ) {
			[ , $authority, $port ] = $p;
		}
		$ascii = idn_to_ascii( mb_strtolower( $authority ), IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_BIDI | IDNA_USE_STD3_RULES, INTL_IDNA_VARIANT_UTS46 );
		return is_string( $ascii ) && '' !== $ascii ? strtolower( $ascii ) . $port : null;
	}
}
