<?php
/**
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Tests\Unit;

use Brain\Monkey\Functions;
use TwentyOnePress\Sigma\Mail\Mailer;
use TwentyOnePress\Sigma\Tests\Support\Source;
use TwentyOnePress\Sigma\Tests\Support\Unit_Test_Case;

/**
 * @covers \TwentyOnePress\Sigma\Mail\Mailer
 */
final class Mailer_Test extends Unit_Test_Case {

	protected function set_up(): void {
		parent::set_up();
		Source::load( 'includes/class-settings.php', 'includes/mail/class-mailer.php' );
		Functions\when( 'is_email' )->alias( static fn( $e ) => false !== filter_var( $e, FILTER_VALIDATE_EMAIL ) ? $e : false );
	}

	public function test_support_mail_replies_to_the_visitor(): void {
		$this->assertSame(
			array( 'Content-Type: text/plain; charset=UTF-8', 'Reply-To: Ann Lee <ann@example.test>' ),
			Mailer::support_headers( 'Ann Lee', 'ann@example.test' )
		);
	}

	public function test_line_breaks_in_the_name_cannot_add_headers(): void {
		$headers = Mailer::support_headers( "Ann\r\nBcc: evil@example.net", 'ann@example.test' );
		$this->assertCount( 2, $headers, 'no extra header line' );
		foreach ( $headers as $header ) {
			$this->assertStringNotContainsString( "\n", $header );
			$this->assertStringNotContainsString( "\r", $header );
		}
	}

	public function test_quotes_and_brackets_cannot_break_the_address(): void {
		$this->assertSame( 'Ann evil@x.net', Mailer::clean_name( 'Ann" <evil@x.net>' ) );
	}

	public function test_an_invalid_email_gets_no_reply_to(): void {
		$this->assertSame( array( 'Content-Type: text/plain; charset=UTF-8' ), Mailer::support_headers( 'Ann', "ann@example.test\nBcc: x@y.z" ) );
	}
}
