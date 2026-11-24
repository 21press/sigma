<?php
/**
 * GD with switchable WebP, for the avatar tests.
 *
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Tests\Integration;

/**
 * Reports no WebP support (Team_Test::$no_webp), or reports it and then fails to save WebP
 * (Team_Test::$webp_save_fails), as some ImageMagick builds do.
 */
final class Test_Avatar_Editor extends \WP_Image_Editor_GD {

	/**
	 * Supported types.
	 *
	 * @param string $mime_type Type.
	 */
	public static function supports_mime_type( $mime_type ) {
		if ( 'image/webp' === $mime_type && Team_Test::$no_webp ) {
			return false;
		}
		return parent::supports_mime_type( $mime_type );
	}

	/**
	 * Save.
	 *
	 * @param string|null $destfilename Path.
	 * @param string|null $mime_type    Type.
	 * @return array|\WP_Error
	 */
	public function save( $destfilename = null, $mime_type = null ) {
		if ( 'image/webp' === $mime_type && Team_Test::$webp_save_fails ) {
			return new \WP_Error( 'image_save_error', 'Test: WebP save failed.' );
		}
		return parent::save( $destfilename, $mime_type );
	}
}
