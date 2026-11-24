<?php
/**
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Tests\Unit;

use TwentyOnePress\Sigma\Config;
use TwentyOnePress\Sigma\Tests\Support\Unit_Test_Case;

/**
 * wp-config constants (§19.1): SIGMA_* first, the old DELTA_ASSISTANT_* name as a fallback.
 *
 * @covers \TwentyOnePress\Sigma\Config
 */
final class Config_Test extends Unit_Test_Case {

	public function test_the_new_name_wins_and_the_old_one_is_a_fallback(): void {
		if ( ! defined( 'DELTA_ASSISTANT_UNIT_ONLY_A' ) ) {
			define( 'DELTA_ASSISTANT_UNIT_ONLY_A', 'old' );
			define( 'SIGMA_UNIT_ONLY_A', 'new' );
			define( 'DELTA_ASSISTANT_UNIT_ONLY_B', 'old only' );
		}
		$this->assertSame( 'new', Config::string( 'UNIT_ONLY_A' ) );
		$this->assertSame( 'SIGMA_UNIT_ONLY_A', Config::name( 'UNIT_ONLY_A' ) );
		$this->assertSame( 'old only', Config::string( 'UNIT_ONLY_B' ) );
		$this->assertSame( 'DELTA_ASSISTANT_UNIT_ONLY_B', Config::name( 'UNIT_ONLY_B' ) );
		$this->assertTrue( Config::has( 'UNIT_ONLY_B' ) );
		$this->assertFalse( Config::has( 'UNIT_ONLY_NONE' ) );
		$this->assertNull( Config::get( 'UNIT_ONLY_NONE' ) );
		$this->assertSame( '', Config::string( 'UNIT_ONLY_NONE' ) );
	}
}
