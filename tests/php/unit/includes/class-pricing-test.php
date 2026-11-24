<?php
/**
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Tests\Unit;

use TwentyOnePress\Sigma\Pricing;
use TwentyOnePress\Sigma\Tests\Support\Source;
use TwentyOnePress\Sigma\Tests\Support\Unit_Test_Case;

/**
 * @covers \TwentyOnePress\Sigma\Pricing
 */
final class Pricing_Test extends Unit_Test_Case {

	protected function set_up(): void {
		parent::set_up();
		Source::load( 'includes/class-pricing.php' );
	}

	public function test_a_warm_haiku_question(): void {
		$cost = Pricing::cost(
			'claude-haiku-5-5',
			array(
				'input'      => 22000,
				'cache_read' => 3400,
				'output'     => 400,
			)
		);
		$this->assertEqualsWithDelta( 0.002434, $cost, 0.000001, '22k in at $0.10, 3.4k cache read at $0.01, 400 out at $0.50 per million' );
	}

	public function test_haiku_bills_five_times_past_100k_prompt_tokens(): void {
		$under = Pricing::cost( 'claude-haiku-5-5', array( 'cache_read' => 99000 ) );
		$over  = Pricing::cost( 'claude-haiku-5-5', array( 'cache_read' => 101000 ) );
		$this->assertEqualsWithDelta( $under / 99000 * 101000 * 5, $over, 0.0000001 );
	}

	public function test_every_model_in_settings_has_a_price(): void {
		\TwentyOnePress\Sigma\Tests\Support\Source::load( 'includes/class-settings.php' );
		foreach ( \TwentyOnePress\Sigma\Settings::models() as $provider => $list ) {
			foreach ( $list as $m ) {
				$this->assertArrayHasKey( $m['id'], Pricing::table(), "{$provider} {$m['id']} is priced" );
			}
		}
	}

	public function test_an_unknown_model_is_priced_as_the_dearest_known_one(): void {
		$this->assertSame( max( array_column( Pricing::table(), 'output' ) ), Pricing::for_model( 'claude-new-model' )['output'], 'a new model can never slip under the cap' );
		$this->assertSame( 40.0, Pricing::for_model( 'claude-new-model' )['output'], 'GPT-6.2 Astra, the dearest listed model' );
	}
}
