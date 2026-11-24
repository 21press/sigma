<?php
/**
 * AI usage and estimated cost, per month (§10, plan v1.6: no spend cap).
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma;

defined( 'ABSPATH' ) || exit;

/**
 * Counts tokens and the estimated cost of each AI call in `delta_assistant_usage_{YYYYMM}`, for
 * Settings (this month and last month). It never turns the AI off: the owner chose no cap; the rate
 * limits and the provider account's own limit bound abuse.
 */
final class Usage {

	/**
	 * Option name of a month.
	 *
	 * @param string|null $month YYYYMM; this month when null.
	 */
	public static function option( ?string $month = null ): string {
		return 'delta_assistant_usage_' . ( $month ?? gmdate( 'Ym' ) );
	}

	/**
	 * Usage of a month.
	 *
	 * @param string|null $month YYYYMM.
	 * @return array{calls: int, input: int, cache_write: int, cache_read: int, output: int, cost: float}
	 */
	public static function month( ?string $month = null ): array {
		$month  = $month ?? gmdate( 'Ym' );
		$stored = get_option( self::option( $month ), array() );
		$usage  = array_merge(
			array(
				'calls'       => 0,
				'input'       => 0,
				'cache_write' => 0,
				'cache_read'  => 0,
				'output'      => 0,
				'cost'        => 0.0,
			),
			is_array( $stored ) ? $stored : array()
		);
		// Since 0.1.7 the counters are atomic rows (§10b); the option keeps what earlier versions recorded.
		$prefix = 'usage:' . $month . ':';
		foreach ( Conversations\Repository::counters_like( $prefix ) as $bucket => $n ) {
			$field = substr( $bucket, strlen( $prefix ) );
			if ( 'cost_micro' === $field ) {
				$usage['cost'] = (float) $usage['cost'] + $n / 1000000;
			} elseif ( isset( $usage[ $field ] ) ) {
				$usage[ $field ] = (int) $usage[ $field ] + $n;
			}
		}
		return $usage;
	}

	/**
	 * This month and last month, for Settings.
	 *
	 * @return array{this: array<string, mixed>, last: array<string, mixed>}
	 */
	public static function summary(): array {
		$this_month = gmdate( 'Ym' );
		$last_month = gmdate( 'Ym', strtotime( gmdate( 'Y-m-01' ) . ' -1 month' ) );
		$out        = array();
		foreach ( array(
			'this' => $this_month,
			'last' => $last_month,
		) as $key => $month ) {
			$usage       = self::month( $month );
			$out[ $key ] = array(
				'month' => substr( $month, 0, 4 ) . '-' . substr( $month, 4 ),
				'calls' => (int) $usage['calls'],
				'cost'  => round( (float) $usage['cost'], 4 ),
			);
		}
		return $out;
	}

	/**
	 * Add one call to this month's counters.
	 *
	 * @param array<string, int> $tokens input, cache_write, cache_read, output.
	 * @param float              $cost   Estimated USD.
	 */
	public static function record( array $tokens, float $cost ): void {
		// Atomic increments (§10b): parallel answers never lose each other's counts. Kept 400 days.
		$prefix = 'usage:' . gmdate( 'Ym' ) . ':';
		$keep   = 400 * DAY_IN_SECONDS;
		Conversations\Repository::counter_add( $prefix . 'calls', $keep );
		foreach ( array( 'input', 'cache_write', 'cache_read', 'output' ) as $kind ) {
			$n = (int) ( $tokens[ $kind ] ?? 0 );
			if ( $n > 0 ) {
				Conversations\Repository::counter_add( $prefix . $kind, $keep, $n );
			}
		}
		$micro = (int) round( max( 0.0, $cost ) * 1000000 );
		if ( $micro > 0 ) {
			Conversations\Repository::counter_add( $prefix . 'cost_micro', $keep, $micro );
		}
	}
}
