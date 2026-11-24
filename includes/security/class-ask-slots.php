<?php
/**
 * Concurrency slots for AI answers (§10b): protect the site's PHP workers.
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Security;

use TwentyOnePress\Sigma\Conversations\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * One answer in flight per conversation, one per network (an IPv4 address or an IPv6 /64), two per IPv6 /48
 * (the same bucket pair the rate limiter uses) and 6 site-wide (filter `sigma_max_concurrent_asks`). So it takes
 * six separate networks to make the AI busy for everyone, with headroom left for small hosts' PHP workers. MySQL named locks (GET_LOCK with no wait) are released by the
 * database when the connection ends, so a crashed worker can never keep a slot. Where GET_LOCK is not
 * available (it returns NULL), an atomic counter slot with a 90 s expiry stands in.
 */
final class Ask_Slots {

	/** Answers in flight per network: an IPv4 address or an IPv6 /64. */
	public const PER_IP = 1;

	/** Answers in flight per IPv6 /48. */
	public const PER_48 = 2;

	/** Default answers in flight site-wide. */
	public const SITE = 6;

	/** Fallback slot lifetime: longer than the longest answer (45 s stream timeout). */
	public const FALLBACK_TTL = 90;

	/**
	 * Slots this request holds: [ kind, name ] with kind lock|counter.
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private array $held = array();

	/**
	 * Lock function: fn( string $name ): ?bool. Tests swap it.
	 *
	 * @var callable|null
	 */
	public static $locker = null;

	/**
	 * Take the slots for one answer.
	 *
	 * @param string               $conversation Conversation key (its uuid).
	 * @param string|array<string> $ip_keys      The client's IP keys (Rate_Limiter::ip_keys: the address or /64, then
	 *                                           the /48 for IPv6), or one key.
	 * @return bool False when any of them is busy (nothing is held then).
	 */
	public function acquire( string $conversation, $ip_keys ): bool {
		foreach ( self::names( $conversation, $ip_keys ) as $group ) {
			if ( $group && ! $this->take_one( $group ) ) { // No /48 group for IPv4.
				$this->release();
				return false;
			}
		}
		return true;
	}

	/**
	 * The lock names of one answer, in the order they are taken: the conversation's one slot, the
	 * network's slot, the /48's slots (IPv6), the site's slots (at most 64 characters each).
	 *
	 * @param string               $conversation Conversation key (its uuid).
	 * @param string|array<string> $ip_keys      The client's IP keys (Rate_Limiter::ip_keys), or one key.
	 * @return array{conversation: list<string>, ip: list<string>, ip48: list<string>, site: list<string>}
	 */
	public static function names( string $conversation, $ip_keys ): array {
		$scope = self::scope();
		$keys  = array_values( array_map( 'strval', (array) $ip_keys ) );
		$names = array(
			'conversation' => array( $scope . 'c_' . substr( hash( 'sha256', $conversation ), 0, 24 ) ),
			'ip'           => array(),
			'ip48'         => array(),
			'site'         => array(),
		);
		$net   = substr( hash( 'sha256', (string) ( $keys[0] ?? '' ) ), 0, 24 );
		for ( $i = 1; $i <= self::PER_IP; $i++ ) {
			$names['ip'][] = $scope . 'ip_' . $net . '_' . $i;
		}
		foreach ( array_slice( $keys, 1 ) as $key ) {
			if ( str_starts_with( $key, '48:' ) ) {
				$wide = substr( hash( 'sha256', $key ), 0, 24 );
				for ( $i = 1; $i <= self::PER_48; $i++ ) {
					$names['ip48'][] = $scope . 'w_' . $wide . '_' . $i;
				}
			}
		}
		$site_max = self::site_max();
		for ( $i = 1; $i <= $site_max; $i++ ) {
			$names['site'][] = $scope . 'g_' . $i;
		}
		return $names;
	}

	/**
	 * Give every slot back.
	 */
	public function release(): void {
		foreach ( array_reverse( $this->held ) as [ $kind, $name ] ) {
			if ( 'lock' === $kind ) {
				Repository::release_lock( $name );
			} else {
				Repository::counter_delete( 'slot:' . $name );
			}
		}
		$this->held = array();
	}

	/**
	 * Site-wide maximum, at least 1.
	 */
	public static function site_max(): int {
		/**
		 * Filters how many AI answers may run at once on the whole site (§10b).
		 *
		 * @param int $max Answers in flight.
		 */
		return max( 1, (int) \TwentyOnePress\Sigma\Hooks::filter( 'max_concurrent_asks', self::SITE ) );
	}

	/**
	 * Take the first free slot of a list.
	 *
	 * @param string[] $names Slot names.
	 */
	private function take_one( array $names ): bool {
		foreach ( $names as $name ) {
			$got = call_user_func( self::$locker ?? array( Repository::class, 'try_lock' ), $name );
			if ( true === $got ) {
				$this->held[] = array( 'lock', $name );
				return true;
			}
			if ( null === $got ) {
				// No named locks here: an atomic counter slot (90 s, deleted on release).
				$state = Repository::counter_add( 'slot:' . $name, self::FALLBACK_TTL );
				if ( 1 === (int) $state['n'] ) {
					$this->held[] = array( 'counter', $name );
					return true;
				}
				Repository::counter_refund( 'slot:' . $name );
			}
		}
		return false;
	}

	/**
	 * Lock names are server-wide in MySQL: scope them to this site's database and table prefix.
	 */
	private static function scope(): string {
		global $wpdb;
		$db = defined( 'DB_NAME' ) ? (string) DB_NAME : '';
		return 'da_' . substr( md5( $db . '|' . $wpdb->prefix ), 0, 8 ) . '_';
	}
}
