<?php
/**
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Tests\Unit;

use TwentyOnePress\Sigma\Security\Visitor_Token;
use TwentyOnePress\Sigma\Tests\Support\Source;
use TwentyOnePress\Sigma\Tests\Support\Unit_Test_Case;

/**
 * @covers \TwentyOnePress\Sigma\Security\Visitor_Token
 */
final class Visitor_Token_Test extends Unit_Test_Case {

	protected function set_up(): void {
		parent::set_up();
		Source::load( 'includes/security/class-visitor-token.php' );
	}

	public function test_a_secret_is_32_random_bytes_in_hex(): void {
		$a = Visitor_Token::generate();
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $a, 'a secret is 64 hex characters' );
		$this->assertNotSame( $a, Visitor_Token::generate(), 'two secrets differ' );
	}

	public function test_only_the_hash_is_stored_and_it_verifies(): void {
		$secret = Visitor_Token::generate();
		$hash   = Visitor_Token::hash( $secret );
		$this->assertSame( hash( 'sha256', $secret ), $hash );
		$this->assertNotSame( $secret, $hash, 'the stored value is not the secret' );
		$this->assertTrue( Visitor_Token::verify( $secret, $hash ), 'the right secret opens its conversation' );
	}

	public function test_a_wrong_or_malformed_secret_is_refused(): void {
		$hash = Visitor_Token::hash( Visitor_Token::generate() );
		$this->assertFalse( Visitor_Token::verify( Visitor_Token::generate(), $hash ), 'another conversation\'s secret' );
		$this->assertFalse( Visitor_Token::verify( '', $hash ), 'no secret' );
		$this->assertFalse( Visitor_Token::verify( $hash, $hash ), 'the hash itself is not a secret that matches' );
		$this->assertFalse( Visitor_Token::verify( 'not hex', $hash ), 'a malformed secret' );
		$this->assertFalse( Visitor_Token::verify( Visitor_Token::generate(), '' ), 'a conversation without a hash' );
	}
}
