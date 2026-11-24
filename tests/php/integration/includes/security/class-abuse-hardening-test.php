<?php
/**
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Tests\Integration;

use TwentyOnePress\Sigma\Conversations\Repository;
use TwentyOnePress\Sigma\Schema;
use TwentyOnePress\Sigma\Security\Ask_Slots;
use TwentyOnePress\Sigma\Security\Rate_Limiter;
use TwentyOnePress\Sigma\Security\Visitor_Token;
use TwentyOnePress\Sigma\Tests\Support\Integration_Test_Case;

/**
 * §10b: atomic counters, concurrency slots, per-IP and site limits, lazy conversations.
 *
 * @covers \TwentyOnePress\Sigma\Security\Rate_Limiter
 * @covers \TwentyOnePress\Sigma\Security\Ask_Slots
 * @covers \TwentyOnePress\Sigma\Rest\Visitor_Controller
 */
final class Abuse_Hardening_Test extends Integration_Test_Case {

	/**
	 * Extra database connections opened by a test.
	 *
	 * @var list<\mysqli>
	 */
	private array $links = array();

	public function set_up(): void {
		parent::set_up();
		$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
		$this->doc( 'Snippets', '<p>Snippets runs small pieces of code. Switch on Snippets under Features.</p>' );
		$this->settings(
			array(
				'ip_source' => 'remote_addr',
				'sources'   => array( 'post' ),
			)
		);
		\TwentyOnePress\Sigma\Knowledge\Corpus::build();
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		foreach ( $this->links as $link ) {
			$link->close(); // Releases any named lock it held.
		}
		$this->links = array();
		parent::tear_down();
	}

	/**
	 * A second connection to the test database (another PHP worker, as far as MySQL can tell).
	 */
	private function connection(): \mysqli {
		global $wpdb;
		$parsed                   = $wpdb->parse_db_host( DB_HOST );
		[ $host, $port, $socket ] = is_array( $parsed ) ? $parsed : array( DB_HOST, null, null );
		$link                     = mysqli_init();
		$link->real_connect( (string) $host, DB_USER, DB_PASSWORD, DB_NAME, $port ? (int) $port : null, $socket ? (string) $socket : null );
		$this->links[] = $link;
		return $link;
	}

	private function ask( string $uuid, string $token, string $text = 'How do I switch on snippets?' ): \WP_REST_Response {
		return $this->rest( 'POST', "/conversations/{$uuid}/ask", array( 'text' => $text ), array( 'X-DA-Token' => $token ) );
	}

	public function test_counters_are_exact_under_20_parallel_increments(): void {
		global $wpdb;
		// The test's own transaction holds row locks on the counters table, so the 20 workers use a copy
		// of it (same columns and key), created and dropped outside that transaction.
		$table = Schema::counters() . '_ptest';
		$setup = $this->connection();
		$setup->query( "DROP TABLE IF EXISTS {$table}" );
		$this->assertTrue( $setup->query( "CREATE TABLE {$table} (bucket varchar(100) NOT NULL, n bigint(20) unsigned NOT NULL DEFAULT 0, expires_at datetime NOT NULL, PRIMARY KEY  (bucket), KEY expires_at (expires_at)) ENGINE=InnoDB" ), (string) $setup->error );
		$bucket = 'test:parallel:' . wp_generate_password( 8, false );
		$sql    = $wpdb->prepare(
			"INSERT INTO {$table} (bucket, n, expires_at) VALUES (%s, LAST_INSERT_ID(1), %s) ON DUPLICATE KEY UPDATE n = LAST_INSERT_ID(IF(expires_at <= %s, 1, n + 1)), expires_at = IF(expires_at <= %s, %s, expires_at)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's table.
			$bucket,
			gmdate( 'Y-m-d H:i:s', time() + 3600 ),
			gmdate( 'Y-m-d H:i:s' ),
			gmdate( 'Y-m-d H:i:s' ),
			gmdate( 'Y-m-d H:i:s', time() + 3600 )
		);
		$links  = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$link = $this->connection();
			$link->query( $sql, MYSQLI_ASYNC ); // All 20 in flight at once.
			$links[] = $link;
		}
		$seen = array();
		foreach ( $links as $link ) {
			$this->assertNotFalse( $link->reap_async_query(), (string) $link->error );
			$seen[] = (int) $link->query( 'SELECT LAST_INSERT_ID()' )->fetch_row()[0];
		}
		sort( $seen );
		$this->assertSame( range( 1, 20 ), $seen, implode( ',', $seen ) . ': every request read its own exact value, no two passed with the same count' );
		$check = $this->connection();
		$this->assertSame( '20', (string) $check->query( "SELECT n FROM {$table} WHERE bucket = '" . $check->real_escape_string( $bucket ) . "'" )->fetch_row()[0] );
		$check->query( "DROP TABLE {$table}" );
	}

	public function test_post_conversations_stores_nothing_the_first_ask_does_and_a_forged_token_is_403(): void {
		global $wpdb;
		$before           = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::conversations() ); // phpcs:ignore WordPress.DB
		[ $uuid, $token ] = $this->conversation( false );
		$this->assertSame( $before, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::conversations() ), 'no row for a conversation that never asked' ); // phpcs:ignore WordPress.DB
		$this->assertSame( Visitor_Token::for_uuid( $uuid ), $token, 'the token is the HMAC of the uuid' );

		$forged = hash( 'sha256', 'guess' );
		$this->assertSame( 403, $this->ask( $uuid, $forged )->get_status(), 'a forged token' );
		$this->assertSame( 403, $this->ask( wp_generate_uuid4(), $token )->get_status(), 'the token of another uuid' );
		$this->assertNull( ( new Repository() )->find_by_uuid( $uuid ), 'refused asks make no row' );

		$response = $this->rest(
			'POST',
			"/conversations/{$uuid}/ask",
			array(
				'text'     => 'How do I switch on snippets?',
				'page_url' => home_url( '/docs/snippets/' ),
				'locale'   => 'de_DE',
			),
			array( 'X-DA-Token' => $token )
		);
		$this->assertSame( 200, $response->get_status() );
		$row = ( new Repository() )->find_by_uuid( $uuid );
		$this->assertNotNull( $row, 'the first ask made the row' );
		$this->assertSame( array( home_url( '/docs/snippets/' ), 'de_DE' ), array( $row['page_url'], $row['locale'] ) );
		$this->assertTrue( Visitor_Token::verify( $token, (string) $row['token_hash'] ), 'and stored the token hash as before' );
		$this->assertSame( 200, $this->ask( $uuid, $token )->get_status(), 'the second ask uses the stored row' );
	}

	public function test_the_stored_page_url_is_always_on_this_site(): void {
		$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$cases = array(
			array( 'https://evil.example/login', '', '' ),
			array( 'javascript:alert(1)', '', '' ),
			array( 'https://evil.example/x', home_url( '/from-referer/' ), home_url( '/from-referer/' ) ),
			array( '', 'https://evil.example/ref', '' ),
			array( 'https://www.' . $host . '/docs/', '', 'https://www.' . $host . '/docs/' ),
			array( home_url( '/docs/a/' ), '', home_url( '/docs/a/' ) ),
		);
		foreach ( $cases as $i => [ $body_url, $referer, $stored ] ) {
			[ $uuid, $token ] = $this->conversation( false );
			$headers          = array( 'X-DA-Token' => $token );
			if ( '' !== $referer ) {
				$headers['Referer'] = $referer;
			}
			$response = $this->rest(
				'POST',
				"/conversations/{$uuid}/ask",
				array(
					'text'     => 'How do I switch on snippets?',
					'page_url' => $body_url,
				),
				$headers
			);
			$this->assertSame( 200, $response->get_status(), "case {$i}" );
			$this->assertSame( $stored, ( new Repository() )->find_by_uuid( $uuid )['page_url'], "case {$i}" );
			self::clear_counters();
		}
	}

	public function test_an_empty_conversation_is_purged_after_24_hours(): void {
		global $wpdb;
		[ $uuid ] = $this->conversation(); // A stored row with no message (a pre-0.1.7 POST, or a failed first ask).
		$wpdb->update( Schema::conversations(), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ), array( 'uuid' => $uuid ) ); // phpcs:ignore WordPress.DB
		[ $kept, $kept_token ] = $this->conversation();
		$wpdb->update( Schema::conversations(), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ), array( 'uuid' => $kept ) ); // phpcs:ignore WordPress.DB
		$this->ask( $kept, $kept_token );
		\TwentyOnePress\Sigma\Privacy\Privacy::purge();
		$this->assertNull( ( new Repository() )->find_by_uuid( $uuid ), 'no message after 24 h: gone' );
		$this->assertNotNull( ( new Repository() )->find_by_uuid( $kept ), 'a conversation with messages stays (retention applies)' );
	}

	public function test_one_answer_in_flight_per_conversation_the_rest_429_busy(): void {
		[ $uuid, $token ] = $this->conversation();
		$names            = Ask_Slots::names( $uuid, Rate_Limiter::ip_keys( Rate_Limiter::client_ip() ) );
		$other            = $this->connection();
		// Another worker is answering this conversation right now.
		$this->assertSame( '1', (string) $other->query( "SELECT GET_LOCK('" . $other->real_escape_string( $names['conversation'][0] ) . "', 0)" )->fetch_row()[0] );
		$busy = $this->ask( $uuid, $token );
		$this->assertSame( 429, $busy->get_status() );
		$this->assertSame( 'busy', $busy->get_data()['code'] );
		$this->assertSame( 5, $busy->get_data()['data']['retry_after'] );
		$this->assertSame( 0, Repository::counter_get( 'rl:ask_hour:c:' . $uuid ), 'a busy answer counts nothing' );
		$this->assertSame( array(), ( new Repository() )->messages( (int) ( new Repository() )->find_by_uuid( $uuid )['id'] ), 'and stores nothing' );

		$other->query( "SELECT RELEASE_LOCK('" . $other->real_escape_string( $names['conversation'][0] ) . "')" );
		$this->assertSame( 200, $this->ask( $uuid, $token )->get_status(), 'free again: answered' );
	}

	public function test_one_per_network_and_six_on_the_site(): void {
		[ $uuid, $token ] = $this->conversation();
		$names            = Ask_Slots::names( $uuid, Rate_Limiter::ip_keys( Rate_Limiter::client_ip() ) );
		$other            = $this->connection();
		$lock             = static fn( string $name ) => $other->query( "SELECT GET_LOCK('" . $other->real_escape_string( $name ) . "', 0)" );
		foreach ( $names['ip'] as $name ) {
			$lock( $name ); // This network already has its one answer running.
		}
		$this->assertSame( 'busy', $this->ask( $uuid, $token )->get_data()['code'] ?? '' );
		$other->close();
		$this->links = array_values( array_filter( $this->links, static fn( $l ): bool => $l !== $other ) );

		$site = $this->connection();
		foreach ( $names['site'] as $name ) {
			$site->query( "SELECT GET_LOCK('" . $site->real_escape_string( $name ) . "', 0)" ); // Six answers run site-wide.
		}
		$this->assertCount( 1, $names['ip'] );
		$this->assertCount( 6, $names['site'] );
		$this->assertSame( 'busy', $this->ask( $uuid, $token )->get_data()['code'] ?? '', 'the seventh on the site waits' );
	}

	public function test_without_get_lock_a_counter_slot_stands_in(): void {
		Ask_Slots::$locker = static fn(): ?bool => null; // A database without named locks.
		try {
			$first  = new Ask_Slots();
			$second = new Ask_Slots();
			$this->assertTrue( $first->acquire( 'conv-a', 'ip-a' ) );
			$this->assertFalse( $second->acquire( 'conv-a', 'ip-b' ), 'the same conversation is busy' );
			$this->assertFalse( $second->acquire( 'conv-b', 'ip-a' ), 'one answer per network' );
			$this->assertTrue( $second->acquire( 'conv-b', 'ip-b' ), 'another network is fine' );
			$third = new Ask_Slots();
			$first->release();
			$this->assertTrue( $third->acquire( 'conv-c', 'ip-a' ), 'a released slot is free at once' );
			$second->release();
			$third->release();
		} finally {
			Ask_Slots::$locker = null;
		}
	}

	public function test_ipv6_one_per_64_two_per_48_and_the_site_cap(): void {
		Ask_Slots::$locker = static fn(): ?bool => null; // Counter slots: no second database connection needed.
		$held              = array();
		try {
			$a = Rate_Limiter::ip_keys( '2001:db8:1:a::1' );
			$b = Rate_Limiter::ip_keys( '2001:db8:1:b::1' ); // Same /48, another /64.
			$c = Rate_Limiter::ip_keys( '2001:db8:1:c::1' ); // Same /48, a third /64.
			$this->assertCount( 2, $a, 'IPv6: the /64 and the /48' );
			$this->assertSame( $a[1], $c[1], 'one /48' );

			$held[] = $one = new Ask_Slots();
			$this->assertTrue( $one->acquire( 'v6-1', $a ) );
			$this->assertFalse( ( new Ask_Slots() )->acquire( 'v6-2', $a ), 'one per /64' );
			$held[] = $two = new Ask_Slots();
			$this->assertTrue( $two->acquire( 'v6-3', $b ), 'a second /64 in the /48' );
			$this->assertFalse( ( new Ask_Slots() )->acquire( 'v6-4', $c ), 'but two per /48' );

			// The site cap: four more networks fill the six site slots; the seventh waits.
			foreach ( array( '198.51.100.1', '198.51.100.2', '198.51.100.3', '198.51.100.4' ) as $i => $ip ) {
				$held[] = $s = new Ask_Slots();
				$this->assertTrue( $s->acquire( 'v4-' . $i, Rate_Limiter::ip_keys( $ip ) ), $ip );
			}
			$this->assertFalse( ( new Ask_Slots() )->acquire( 'v4-x', Rate_Limiter::ip_keys( '198.51.100.9' ) ), 'six site-wide' );
			add_filter( 'sigma_max_concurrent_asks', static fn(): int => 7 );
			$held[] = $seventh = new Ask_Slots();
			$this->assertTrue( $seventh->acquire( 'v4-y', Rate_Limiter::ip_keys( '198.51.100.9' ) ), 'the filter still raises it' );
		} finally {
			foreach ( $held as $slots ) {
				$slots->release();
			}
			Ask_Slots::$locker = null;
		}
	}

	public function test_40_asks_across_new_conversations_from_one_ip_get_at_most_30_answers(): void {
		$answered = 0;
		$limited  = 0;
		for ( $i = 0; $i < 40; $i++ ) {
			[ $uuid, $token ] = $this->conversation( false );
			$status           = $this->ask( $uuid, $token )->get_status();
			$answered        += 200 === $status ? 1 : 0;
			$limited         += 429 === $status ? 1 : 0;
		}
		$this->assertSame( 30, $answered, '30 an hour per IP, whatever the number of conversations' );
		$this->assertSame( 10, $limited );
	}

	public function test_a_billed_empty_answer_counts_and_a_call_that_never_reached_the_provider_is_refunded(): void {
		$this->settings(
			array(
				'provider'      => 'anthropic',
				'anthropic_key' => 'sk-ant-test-key-0000',
			)
		);
		$this->answer_http(
			fn( string $url ) => str_contains( $url, 'count_tokens' )
				? self::json( 200, array( 'input_tokens' => 1200 ) )
				: self::json(
					200,
					array(
						'model'       => 'claude-haiku-5-5',
						'stop_reason' => 'end_turn',
						'content'     => array(
							array(
								'type' => 'text',
								'text' => '',
							),
						),
						'usage'       => array(
							'input_tokens'  => 800,
							'output_tokens' => 0,
						),
					)
				)
		);
		\TwentyOnePress\Sigma\Knowledge\Corpus::build();
		[ $uuid, $token ] = $this->conversation();
		$this->assertSame( 503, $this->ask( $uuid, $token )->get_status(), 'an empty answer is an error for the visitor' );
		$this->assertSame( 1, Repository::counter_get( 'rl:ask_hour:c:' . $uuid ), 'but the provider was billed: it stays counted' );
		$this->assertSame( 1, Repository::counter_get( 'rl:ask_global_hour:global' ) );
		$this->assertGreaterThan( 0, \TwentyOnePress\Sigma\Usage::month()['calls'] );

		$this->settings( array( 'anthropic_key' => '' ) ); // No key: nothing can reach the provider.
		[ $uuid2, $token2 ] = $this->conversation();
		$this->assertSame( 503, $this->ask( $uuid2, $token2 )->get_status() );
		$this->assertSame( 0, Repository::counter_get( 'rl:ask_hour:c:' . $uuid2 ), 'refunded' );
		$this->assertSame( 1, Repository::counter_get( 'rl:ask_global_hour:global' ), 'the site counter too' );
	}

	public function test_the_site_wide_handoff_cap_answers_503_handoff_busy(): void {
		add_filter(
			'sigma_rate_limits',
			static function ( array $limits ): array {
				$limits['handoff_global_hour'] = array( 2, HOUR_IN_SECONDS );
				return $limits;
			}
		);
		$statuses = array();
		foreach ( array( '203.0.113.1', '203.0.113.2', '203.0.113.3' ) as $i => $ip ) {
			$_SERVER['REMOTE_ADDR'] = $ip; // Three different visitors.
			[ $uuid, $token ]       = $this->conversation( false );
			$response               = $this->rest(
				'POST',
				"/conversations/{$uuid}/handoff",
				array(
					'name'  => 'Ann',
					'email' => 'ann' . $i . '@example.test',
					'text'  => 'Hello',
				),
				array( 'X-DA-Token' => $token )
			);
			$statuses[]             = $response->get_status() . ':' . ( 200 === $response->get_status() ? 'ok' : $response->get_data()['code'] );
		}
		$this->assertSame( array( '200:ok', '200:ok', '503:handoff_busy' ), $statuses );
		$this->assertSame( 0, Repository::counter_get( 'rl:handoff_ip:' . Rate_Limiter::ip_hash( '203.0.113.3' ) ), 'the refused visitor\'s own hit is refunded' );
	}
}
