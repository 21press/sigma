<?php
/**
 * Estimated cost of one AI call, for the spend cap (§10, §13).
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma;

defined( 'ABSPATH' ) || exit;

/**
 * Prices in USD per million tokens, read from the providers' pricing pages on 2026-11-20 (§13.1).
 * An estimate for the cap, not a bill. A model not in the table is priced as the most expensive
 * known model, so a new model can never slip under the cap. Sites override with the
 * `sigma_model_prices` filter.
 */
final class Pricing {

	/**
	 * Known models: input, cache write (5 min), cache read, output.
	 *
	 * @return array<string, array{input: float, cache_write: float, cache_read: float, output: float}>
	 */
	public static function table(): array {
		$table = array(
			'claude-haiku-5-5'  => array(
				'input'       => 0.10,
				'cache_write' => 0.125,
				'cache_read'  => 0.01,
				'output'      => 0.50,
			),
			'claude-sonnet-5-5' => array(
				'input'       => 2.00,
				'cache_write' => 2.50,
				'cache_read'  => 0.10,
				'output'      => 10.00,
			),
			'claude-opus-5-5'   => array(
				'input'       => 4.00,
				'cache_write' => 5.00,
				'cache_read'  => 0.20,
				'output'      => 20.00,
			),
			'gpt-6-luna'        => array(
				'input'       => 0.10,
				'cache_write' => 0.10,
				'cache_read'  => 0.01,
				'output'      => 0.50,
			),
			// OpenAI bills cached input at the cached rate and writes at the input rate (standard tier, short context).
			'gpt-6.1-terra'     => array(
				'input'       => 1.00,
				'cache_write' => 1.00,
				'cache_read'  => 0.05,
				'output'      => 5.00,
			),
			'gpt-6.2-sol'       => array(
				'input'       => 2.00,
				'cache_write' => 2.00,
				'cache_read'  => 0.10,
				'output'      => 10.00,
			),
			'gpt-6.2-astra'     => array(
				'input'       => 8.00,
				'cache_write' => 8.00,
				'cache_read'  => 0.40,
				'output'      => 40.00,
			),
			'mock'              => array(
				'input'       => 0.0,
				'cache_write' => 0.0,
				'cache_read'  => 0.0,
				'output'      => 0.0,
			),
		);
		/**
		 * Filters the price table (USD per million tokens).
		 *
		 * @param array $table Prices by model id.
		 */
		return (array) \TwentyOnePress\Sigma\Hooks::filter( 'model_prices', $table );
	}

	/**
	 * Prices of a model; an unknown model gets the highest price of each kind.
	 *
	 * @param string $model Model id.
	 * @return array{input: float, cache_write: float, cache_read: float, output: float}
	 */
	public static function for_model( string $model ): array {
		$table = self::table();
		if ( isset( $table[ $model ] ) ) {
			return $table[ $model ];
		}
		$max = array(
			'input'       => 0.0,
			'cache_write' => 0.0,
			'cache_read'  => 0.0,
			'output'      => 0.0,
		);
		foreach ( $table as $prices ) {
			foreach ( $max as $kind => $value ) {
				$max[ $kind ] = max( $value, (float) ( $prices[ $kind ] ?? 0 ) );
			}
		}
		return $max;
	}

	/**
	 * Cost of one call. Haiku-class models bill the whole request at 5× once the prompt passes
	 * 100,000 tokens (§7.4); the context budget keeps prompts under it, and this counts it if not.
	 *
	 * @param string             $model Model id.
	 * @param array<string, int> $usage input, cache_write, cache_read, output token counts.
	 */
	public static function cost( string $model, array $usage ): float {
		$p      = self::for_model( $model );
		$prompt = (int) ( $usage['input'] ?? 0 ) + (int) ( $usage['cache_write'] ?? 0 ) + (int) ( $usage['cache_read'] ?? 0 );
		$cost   = ( (int) ( $usage['input'] ?? 0 ) * $p['input']
			+ (int) ( $usage['cache_write'] ?? 0 ) * $p['cache_write']
			+ (int) ( $usage['cache_read'] ?? 0 ) * $p['cache_read']
			+ (int) ( $usage['output'] ?? 0 ) * $p['output'] ) / 1000000;
		if ( str_starts_with( $model, 'claude-haiku-5' ) && $prompt > 100000 ) {
			$cost *= 5;
		}
		return $cost;
	}
}
