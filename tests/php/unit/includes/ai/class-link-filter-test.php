<?php
/**
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Tests\Unit;

use Brain\Monkey\Functions;
use TwentyOnePress\Sigma\AI\Link_Filter;
use TwentyOnePress\Sigma\Tests\Support\Source;
use TwentyOnePress\Sigma\Tests\Support\Unit_Test_Case;

/**
 * @covers \TwentyOnePress\Sigma\AI\Link_Filter
 */
final class Link_Filter_Test extends Unit_Test_Case {

	private const DOCS = array(
		'https://example.com/docs/snippets/'      => 'Snippets',
		'https://example.com/docs/import-export/' => 'Import & Export',
	);

	protected function set_up(): void {
		parent::set_up();
		Source::load( 'includes/ai/class-link-filter.php' );
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url, int $component = -1 ) => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	}

	private function filter( string $text ): array {
		return Link_Filter::filter( $text, self::DOCS, array( 'example.com' ), 'https://example.com' );
	}

	public function test_a_doc_link_stays_and_is_listed_with_the_doc_title(): void {
		$out = $this->filter( 'See [the snippets page](https://example.com/docs/snippets/).' );
		$this->assertSame( 'See [the snippets page](https://example.com/docs/snippets/).', $out['text'] );
		$this->assertSame(
			array(
				array(
					'url'   => 'https://example.com/docs/snippets/',
					'title' => 'Snippets',
				),
			),
			$out['links'],
			'links[] carries the corpus title, not the link text'
		);
	}

	public function test_a_link_to_another_site_loses_its_url_and_keeps_its_label(): void {
		$out = $this->filter( 'Try [this guide](https://evil.example.net/phish) now.' );
		$this->assertSame( 'Try this guide now.', $out['text'] );
		$this->assertSame( array(), $out['links'] );
	}

	public function test_a_bare_url_to_another_site_is_removed(): void {
		$out = $this->filter( 'Go to https://evil.example.net/x, then come back.' );
		$this->assertStringNotContainsString( 'evil', $out['text'] );
		$this->assertSame( array(), $out['links'] );
	}

	public function test_only_http_and_https_survive(): void {
		$out = $this->filter( '[click](javascript:alert(1)) and [data](data:text/html,hi)' );
		$this->assertStringNotContainsString( 'javascript:', $out['text'] );
		$this->assertStringNotContainsString( 'data:', $out['text'] );
	}

	public function test_root_relative_links_resolve_to_the_site_and_match_docs(): void {
		$out = $this->filter( 'Read [Import](/docs/import-export) first.' );
		$this->assertSame( 'Read [Import](https://example.com/docs/import-export/) first.', $out['text'] );
		$this->assertSame( 'Import & Export', $out['links'][0]['title'], 'a missing trailing slash still matches the doc' );
	}

	public function test_a_page_on_the_site_that_is_not_a_doc_stays_with_its_label(): void {
		$out = $this->filter( 'See [pricing](https://example.com/pricing/).' );
		$this->assertStringContainsString( '(https://example.com/pricing/)', $out['text'] );
		$this->assertSame( 'pricing', $out['links'][0]['title'] );
	}

	public function test_a_lookalike_host_is_not_the_site(): void {
		$out = $this->filter( '[x](https://example.com.evil.net/docs/snippets/)' );
		$this->assertSame( 'x', $out['text'] );
	}

	public function test_each_link_is_listed_once(): void {
		$out = $this->filter( '[a](https://example.com/docs/snippets/) and [b](https://example.com/docs/snippets/#x) and https://example.com/docs/snippets/' );
		$this->assertCount( 1, $out['links'] );
	}

	/**
	 * @dataProvider hostile_urls
	 *
	 * @param string $url A URL built so PHP and the browser disagree on it (§10b).
	 */
	public function test_hostile_urls_are_never_kept_as_links( string $url ): void {
		foreach ( array( "[Snippets]({$url})", "See {$url} now." ) as $text ) {
			$out = $this->filter( $text );
			$this->assertSame( array(), $out['links'], $text );
			$this->assertStringNotContainsString( 'evil', $out['text'], $text );
			$this->assertStringNotContainsString( 'http', $out['text'], "{$text}: the URL is removed whole" );
		}
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function hostile_urls(): array {
		return array(
			'backslash userinfo (the audit case)' => array( 'https://evil.com\@example.com/docs/snippets/' ),
			'backslash host'                      => array( 'https://example.com\evil.com/docs/' ),
			'userinfo'                            => array( 'https://evil@example.com/docs/snippets/' ),
			'user and password'                   => array( 'https://a:b@example.com/docs/snippets/' ),
			'userinfo to another host'            => array( 'https://example.com@evil.com/x' ),
			'double quote'                        => array( 'https://example.com/docs/snippets/"onmouseover=evil()' ),
			'single quote'                        => array( "https://example.com/docs/snippets/'evil" ),
			'control character'                   => array( "https://example.com/docs/\x01evil" ),
			'NUL in the host'                     => array( "https://example.com\x00.evil.com/" ),
			'encoded backslash host'              => array( 'https://evil.com%5C@example.com/' ),
			'fullwidth at sign'                   => array( 'https://evil.com＠example.com/docs/snippets/' ),
			'fullwidth solidus and at sign'       => array( 'https://evil.com／@example.com/docs/' ),
			'fullwidth reverse solidus'           => array( 'https://evil.com＼@example.com/' ),
			'ideographic space before userinfo'   => array( "https://evil.com\u{3000}@example.com/" ),
			'no-break space in the host'          => array( "https://example.com\u{00A0}.evil.com/" ),
			'zero-width space in the host'        => array( "https://exa\u{200B}mple.com.evil.com/" ),
			'right-to-left override in the path'  => array( "https://example.com/docs/\u{202E}moc.evil" ),
			'unicode host with userinfo'          => array( 'https://évil.com@example.com/' ),
		);
	}

	public function test_a_protocol_relative_markdown_link_keeps_only_its_label(): void {
		$out = $this->filter( 'See [Snippets](//evil.com/x).' );
		$this->assertSame( 'See Snippets.', $out['text'] );
		$this->assertSame( array(), $out['links'] );
	}

	public function test_a_kept_url_is_rebuilt_from_its_parts(): void {
		$this->assertSame( 'https://example.com/docs/snippets/?a=1#b', Link_Filter::rebuild( 'HTTPS://Example.COM/docs/snippets/?a=1#b' ) );
		$this->assertSame( 'https://example.com:8443/x', Link_Filter::rebuild( 'https://example.com:8443/x' ) );
		$this->assertNull( Link_Filter::rebuild( 'https://exa mple.com/' ) );
		$this->assertNull( Link_Filter::rebuild( 'javascript:alert(1)' ) );
		$this->assertSame( 'https://xn--exmple-cua.com/', Link_Filter::rebuild( 'https://exämple.com/' ), 'an IDN host in punycode' );
		$this->assertSame( 'https://example.com/docs/%E6%97%A5%E6%9C%AC/?q=%C3%A9#%C3%BC', Link_Filter::rebuild( 'https://example.com/docs/日本/?q=é#ü' ), 'Unicode percent-encoded' );
	}

	public function test_a_doc_with_a_unicode_path_stays_clickable_as_its_own_url(): void {
		$docs = array( 'https://example.com/docs/%e6%97%a5%e6%9c%ac/' => '日本語のドキュメント' );
		foreach ( array( '[日本](https://example.com/docs/日本/)', '[日本](https://example.com/docs/%E6%97%A5%E6%9C%AC)', 'https://example.com/docs/日本/' ) as $text ) {
			$out = Link_Filter::filter( $text, $docs, array(), 'https://example.com' );
			$this->assertStringContainsString( 'https://example.com/docs/%e6%97%a5%e6%9c%ac/', $out['text'], $text );
			$this->assertSame(
				array(
					array(
						'url'   => 'https://example.com/docs/%e6%97%a5%e6%9c%ac/',
						'title' => '日本語のドキュメント',
					),
				),
				$out['links'],
				$text . ': the text and links carry the same string, so the widget can match them'
			);
		}
	}

	public function test_an_idn_host_is_compared_in_punycode(): void {
		foreach ( array( 'bücher.example', 'xn--bcher-kva.example' ) as $allowed ) {
			$out = Link_Filter::filter( 'Write to us: https://bücher.example/kontakt and https://xn--bcher-kva.example/hilfe', array(), array( $allowed ), 'https://bücher.example' );
			$this->assertSame( 'Write to us: https://xn--bcher-kva.example/kontakt and https://xn--bcher-kva.example/hilfe', $out['text'], $allowed );
		}
		$out = Link_Filter::filter( 'https://bücher.evil/x', array(), array( 'bücher.example' ), 'https://bücher.example' );
		$this->assertSame( '', $out['text'] );
	}

	public function test_the_bare_url_pattern_covers_what_the_widget_autolinks(): void {
		// The widget autolinks https?://[^\s<>()]+ ending in a non-punctuation character; the server must
		// see (and judge) at least that much, quotes and brackets included.
		$out = $this->filter( 'Go to https://evil.com"/x then.' );
		$this->assertStringNotContainsString( 'evil', $out['text'] );
		$out = $this->filter( 'Go to https://example.com/docs/snippets/ then.' );
		$this->assertStringContainsString( 'https://example.com/docs/snippets/', $out['text'] );
	}
}
