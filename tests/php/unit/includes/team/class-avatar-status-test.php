<?php
/**
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Tests\Unit;

use TwentyOnePress\Sigma\Team\Avatar_Status;
use TwentyOnePress\Sigma\Tests\Support\Source;
use TwentyOnePress\Sigma\Tests\Support\Unit_Test_Case;

/**
 * The avatar status shown per member in Settings → Team.
 *
 * @covers \TwentyOnePress\Sigma\Team\Avatar_Status
 */
final class Avatar_Status_Test extends Unit_Test_Case {

	protected function set_up(): void {
		parent::set_up();
		Source::load( 'includes/team/class-avatar-status.php' );
	}

	/**
	 * Message for a status.
	 *
	 * @param string $source Source.
	 * @param string $result Result.
	 * @param string $reason Reason.
	 * @param string $detail Detail.
	 */
	private static function says( string $source, string $result, string $reason = '', string $detail = '' ): string {
		return Avatar_Status::message( Avatar_Status::status( $source, $result, $reason, $detail ) );
	}

	public function test_never_refreshed(): void {
		$this->assertSame( 'Not refreshed yet. Save the team or use Refresh avatars.', Avatar_Status::message( null ) );
	}

	public function test_success_names_the_source_and_the_file_type(): void {
		$this->assertSame( 'Copy made from Gravatar (WEBP).', self::says( 'gravatar', 'ok', '', 'WEBP' ) );
		$this->assertSame( 'Copy made from the chat avatar (PNG).', self::says( 'chat', 'ok', '', 'PNG' ) );
		$this->assertSame( 'Up to date: Gravatar has not changed.', self::says( 'gravatar', 'unchanged' ) );
	}

	public function test_no_gravatar_is_named_as_a_404(): void {
		$this->assertSame( 'No Gravatar (404): the widget shows initials.', self::says( 'gravatar', 'no_picture', 'http_404' ) );
		$this->assertSame( 'No picture: the widget shows initials.', self::says( 'gravatar', 'no_picture', 'no_avatar_url' ) );
	}

	/**
	 * @dataProvider failures
	 *
	 * @param string $reason   Reason.
	 * @param string $detail   Detail.
	 * @param string $expected Words.
	 */
	public function test_each_failure_reason_in_words( string $reason, string $detail, string $expected ): void {
		$this->assertSame( "No copy: {$expected}. The widget shows initials.", self::says( 'gravatar', 'failed', $reason, $detail ) );
		$this->assertSame( "Refresh failed: {$expected}. The previous copy is still used.", self::says( 'gravatar', 'kept', $reason, $detail ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function failures(): array {
		return array(
			'http status'  => array( 'http_status', '503', 'Gravatar answered HTTP 503' ),
			'http error'   => array( 'http_error', 'cURL error 28: Operation timed out', 'Gravatar could not be reached (cURL error 28: Operation timed out)' ),
			'not an image' => array( 'not_image', 'text/html', 'Gravatar did not send an image (text/html)' ),
			'empty'        => array( 'empty', '', 'Gravatar sent an empty image' ),
			'too large'    => array( 'too_large', '', 'the image is over 2 MB' ),
			'chat missing' => array( 'chat_missing', '', 'the chat avatar file is missing from the Media Library' ),
			'folder'       => array( 'folder', '', 'the uploads/delta-assistant/avatars folder is not writable' ),
			'temp file'    => array( 'temp', '', 'a temporary file could not be written in the avatars folder' ),
			'editor'       => array( 'editor', 'invalid_image', 'the image editor could not open the picture (invalid_image)' ),
			'save'         => array( 'save', 'image_save_error', 'the resized picture could not be saved as WebP or PNG (image_save_error)' ),
			'exception'    => array( 'exception', 'Error', 'an error stopped the refresh (Error)' ),
			'unknown'      => array( 'something_else', '', 'unknown error' ),
		);
	}

	public function test_status_values_are_normalised_and_short(): void {
		$status = Avatar_Status::status( 'elsewhere', 'weird', 'HTTP-Status!', "<b>a</b>\n" . str_repeat( 'x', 300 ), 1234 );
		$this->assertSame( 'gravatar', $status['source'] );
		$this->assertSame( 'failed', $status['result'] );
		$this->assertSame( 'httpstatus', $status['reason'] );
		$this->assertStringStartsWith( 'a x', $status['detail'] );
		$this->assertSame( 120, mb_strlen( $status['detail'] ) );
		$this->assertSame( 1234, $status['at'] );
		$this->assertGreaterThan( 0, Avatar_Status::status( 'chat', 'ok' )['at'] );
	}
}
