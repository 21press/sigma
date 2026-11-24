<?php
/**
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Tests\Unit;

use TwentyOnePress\Sigma\Security\Cloudflare;
use TwentyOnePress\Sigma\Tests\Support\Source;
use TwentyOnePress\Sigma\Tests\Support\Unit_Test_Case;

/**
 * Which IP the rate limits see behind Cloudflare (§10, plan v1.4).
 *
 * @covers \TwentyOnePress\Sigma\Security\Cloudflare
 */
final class Cloudflare_Test extends Unit_Test_Case {

	protected function set_up(): void {
		parent::set_up();
		Source::load( 'includes/security/class-cloudflare.php' );
	}

	private static function ip( string $remote, string $header, string $source = 'cloudflare' ): string {
		return Cloudflare::client_ip( $remote, $header, $source, Cloudflare::BUNDLED );
	}

	public function test_a_spoofed_header_from_outside_cloudflare_is_ignored(): void {
		$this->assertSame( '203.0.113.7', self::ip( '203.0.113.7', '198.51.100.1' ), 'a visitor reaching the origin directly cannot choose their IP' );
		$this->assertSame( '2001:db8::7', self::ip( '2001:db8::7', '198.51.100.1' ) );
	}

	public function test_a_cloudflare_edge_ipv4_with_the_header_gives_the_visitor(): void {
		$this->assertSame( '198.51.100.1', self::ip( '172.70.1.5', '198.51.100.1' ), '172.64.0.0/13' );
		$this->assertSame( '198.51.100.1', self::ip( '104.23.255.255', '198.51.100.1' ), 'last address of 104.16.0.0/13' );
		$this->assertSame( '104.24.0.0', self::ip( '104.24.0.0', '' ), 'no header: the connection' );
	}

	public function test_ipv6_edges_and_ipv6_visitors_work(): void {
		$this->assertSame( '2001:db8:1::42', self::ip( '2606:4700:10::ac43:1', '2001:db8:1::42' ), 'IPv6 edge, IPv6 visitor' );
		$this->assertSame( '198.51.100.9', self::ip( '2a06:98c7:ffff::1', '198.51.100.9' ), 'inside the /29' );
		$this->assertSame( '2a06:98c8::1', self::ip( '2a06:98c8::1', '198.51.100.9' ), 'just outside the /29' );
	}

	public function test_the_default_source_never_reads_the_header(): void {
		$this->assertSame( '172.70.1.5', self::ip( '172.70.1.5', '198.51.100.1', 'remote_addr' ) );
	}

	public function test_a_header_that_is_not_an_ip_is_ignored(): void {
		$this->assertSame( '172.70.1.5', self::ip( '172.70.1.5', '198.51.100.1, 10.0.0.1' ) );
		$this->assertSame( '172.70.1.5', self::ip( '172.70.1.5', '<script>' ) );
	}

	public function test_range_edges(): void {
		$this->assertTrue( Cloudflare::in_ranges( '173.245.48.0', array( '173.245.48.0/20' ) ) );
		$this->assertTrue( Cloudflare::in_ranges( '173.245.63.255', array( '173.245.48.0/20' ) ) );
		$this->assertFalse( Cloudflare::in_ranges( '173.245.64.0', array( '173.245.48.0/20' ) ) );
		$this->assertFalse( Cloudflare::in_ranges( '::ffff:173.245.48.1', array( '173.245.48.0/20' ) ), 'v6 and v4 never match each other' );
		$this->assertFalse( Cloudflare::in_ranges( 'not-an-ip', Cloudflare::BUNDLED ) );
	}

	public function test_a_downloaded_list_is_kept_only_when_every_line_is_a_range(): void {
		$this->assertSame( array( '173.245.48.0/20', '2400:cb00::/32' ), Cloudflare::parse( "173.245.48.0/20\n2400:cb00::/32\n" ) );
		$this->assertNull( Cloudflare::parse( "<html>Error</html>\n" ) );
		$this->assertNull( Cloudflare::parse( '' ) );
	}
}
