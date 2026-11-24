<?php
/**
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Tests\Unit;

use Brain\Monkey\Functions;
use TwentyOnePress\Sigma\Security\Rate_Limiter;
use TwentyOnePress\Sigma\Tests\Support\Source;
use TwentyOnePress\Sigma\Tests\Support\Unit_Test_Case;

/**
 * @covers \TwentyOnePress\Sigma\Security\Rate_Limiter
 */
final class Rate_Limiter_Test extends Unit_Test_Case {

	/**
	 * Counters of the test: bucket => [ n, expires ].
	 *
	 * @var array<string, array{0: int, 1: int}>
	 */
	private array $store = array();

	/**
	 * Fake clock.
	 *
	 * @var int
	 */
	private int $now = 1800000000;

	protected function set_up(): void {
		parent::set_up();
		Source::load( 'includes/conversations/class-repository.php', 'includes/security/class-rate-limiter.php' );
		$this->store = array();
		// The same semantics as the atomic SQL counter: a window that ended starts again.
		Rate_Limiter::$store    = function ( string $bucket, int $window ): array {
			[ $n, $exp ] = $this->store[ $bucket ] ?? array( 0, 0 );
			if ( $exp <= $this->now ) {
				$n   = 0;
				$exp = $this->now + $window;
			}
			$this->store[ $bucket ] = array( $n + 1, $exp );
			return array(
				'n'       => $n + 1,
				'expires' => $exp,
			);
		};
		Rate_Limiter::$refunder = function ( string $bucket ): void {
			if ( isset( $this->store[ $bucket ] ) && $this->store[ $bucket ][1] > $this->now ) {
				$this->store[ $bucket ][0] = max( 0, $this->store[ $bucket ][0] - 1 );
			}
		};
		Rate_Limiter::$clock = fn(): int => $this->now;
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
	}

	protected function tear_down(): void {
		Rate_Limiter::$store    = null;
		Rate_Limiter::$refunder = null;
		Rate_Limiter::$clock    = null;
		parent::tear_down();
	}

	/**
	 * A hit at a time on the fake clock (the limiter itself reads time() only for the wait).
	 *
	 * @param string $name Limit.
	 * @param string $key  Key.
	 * @param int    $at   Clock.
	 */
	private function hit_at( string $name, string $key, int $at ): int {
		$this->now = $at;
		return Rate_Limiter::hit( $name, $key );
	}

	public function test_one_new_conversation_per_10_seconds_per_ip(): void {
		$now = 1000000;
		$this->assertSame( 0, $this->hit_at( 'conversation_ip', 'ip-a', $now ), 'the first is allowed' );
		$wait = $this->hit_at( 'conversation_ip', 'ip-a', $now + 3 );
		$this->assertSame( 7, $wait, 'the second within 10 s waits for the rest of the window' );
		$this->assertSame( 0, $this->hit_at( 'conversation_ip', 'ip-b', $now + 3 ), 'another IP is not affected' );
		$this->assertSame( 0, $this->hit_at( 'conversation_ip', 'ip-a', $now + 10 ), 'a new window allows it again' );
	}

	public function test_three_handoffs_an_hour(): void {
		$now = 2000000;
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertSame( 0, $this->hit_at( 'handoff_ip', 'ip', $now + $i ), 'handoff ' . ( $i + 1 ) . ' is allowed' );
		}
		$this->assertGreaterThan( 3500, $this->hit_at( 'handoff_ip', 'ip', $now + 5 ), 'the fourth waits about an hour' );
	}

	public function test_an_unknown_limit_never_blocks(): void {
		$this->assertSame( 0, $this->hit_at( 'nope', 'x', 1 ) );
	}

	public function test_ipv6_visitors_are_counted_by_their_64(): void {
		$this->assertSame( Rate_Limiter::ip_hash( '2001:db8:1:2::1', '2026-11-20' ), Rate_Limiter::ip_hash( '2001:db8:1:2:ffff::9', '2026-11-20' ), 'same /64, same visitor' );
		$this->assertNotSame( Rate_Limiter::ip_hash( '2001:db8:1:2::1', '2026-11-20' ), Rate_Limiter::ip_hash( '2001:db8:1:3::1', '2026-11-20' ), 'another /64' );
	}

	public function test_ip_hashes_change_every_day_and_never_contain_the_ip(): void {
		$today    = Rate_Limiter::ip_hash( '203.0.113.9', '2026-11-20' );
		$tomorrow = Rate_Limiter::ip_hash( '203.0.113.9', '2026-11-21' );
		$this->assertSame( $today, Rate_Limiter::ip_hash( '203.0.113.9', '2026-11-20' ), 'same IP, same day: same hash' );
		$this->assertNotSame( $today, $tomorrow, 'the salt rotates daily' );
		$this->assertStringNotContainsString( '203.0.113.9', $today );
	}

	public function test_a_refused_hit_is_taken_back_and_hit_all_refunds_the_ones_before(): void {
		$this->now = 1800000000;
		$this->assertSame( 0, Rate_Limiter::hit( 'conversation_ip', 'ip-a' ) );
		$this->assertGreaterThan( 0, Rate_Limiter::hit( 'conversation_ip', 'ip-a' ) );
		$this->assertSame( 1, $this->store['rl:conversation_ip:ip-a'][0], 'the refused hit did not count' );

		$result = Rate_Limiter::hit_all( array( array( 'ask_hour', 'c:x' ), array( 'conversation_ip', 'ip-a' ) ) );
		$this->assertSame( 'conversation_ip', $result['name'] );
		$this->assertSame( 0, $this->store['rl:ask_hour:c:x'][0], 'the ask hit before the refused one was refunded' );
	}

	public function test_30_questions_an_hour_per_ip_across_conversations(): void {
		$this->now = 1800000000;
		$passed    = 0;
		for ( $i = 0; $i < 40; $i++ ) {
			// A new conversation each time: only the per-IP bucket can stop it.
			$hit = Rate_Limiter::hit_all( array_merge( array( array( 'ask_hour', 'c:' . $i ) ), Rate_Limiter::ip_hits( 'ask_ip_hour', '198.51.100.7' ) ) );
			$passed += 0 === $hit['wait'] ? 1 : 0;
		}
		$this->assertSame( 30, $passed );
	}

	public function test_ipv6_is_counted_by_its_64_and_its_48_at_five_times_the_limit(): void {
		$keys = Rate_Limiter::ip_keys( '2001:db8:aa:1::1', '2026-11-20' );
		$this->assertCount( 2, $keys );
		$this->assertStringStartsWith( '48:', $keys[1] );
		$this->assertSame( $keys[1], Rate_Limiter::ip_keys( '2001:db8:aa:ffff::9', '2026-11-20' )[1], 'another /64 in the same /48 shares the /48 bucket' );
		$this->assertNotSame( $keys[0], Rate_Limiter::ip_keys( '2001:db8:aa:ffff::9', '2026-11-20' )[0] );

		// Rotating /64s inside one /48: each /64 allows 30, but the /48 stops the total at 150.
		$this->now = 1800000000;
		$passed    = 0;
		for ( $net = 0; $net < 10; $net++ ) {
			for ( $i = 0; $i < 30; $i++ ) {
				$hit     = Rate_Limiter::hit_all( Rate_Limiter::ip_hits( 'ask_ip_hour', sprintf( '2001:db8:aa:%x::1', $net ) ) );
				$passed += 0 === $hit['wait'] ? 1 : 0;
			}
		}
		$this->assertSame( 150, $passed, '5 × 30 per /48' );
	}

	public function test_ipv4_mapped_ipv6_is_unwrapped_to_ipv4(): void {
		$this->assertSame( '203.0.113.9', Rate_Limiter::normalize_ip( '::ffff:203.0.113.9' ) );
		$this->assertSame( Rate_Limiter::ip_keys( '203.0.113.9', '2026-11-20' ), Rate_Limiter::ip_keys( '::ffff:203.0.113.9', '2026-11-20' ) );
		$this->assertNotSame( Rate_Limiter::ip_keys( '::ffff:203.0.113.9', '2026-11-20' ), Rate_Limiter::ip_keys( '::ffff:198.51.100.1', '2026-11-20' ), 'two mapped visitors no longer share one bucket' );
		$this->assertCount( 1, Rate_Limiter::ip_keys( '::ffff:203.0.113.9', '2026-11-20' ) );
		$this->assertSame( '2001:db8::1', Rate_Limiter::normalize_ip( '2001:db8::1' ) );
	}
}
