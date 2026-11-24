<?php
/**
 * The outcome of a team avatar refresh, and its message for Settings → Team.
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Team;

defined( 'ABSPATH' ) || exit;

/**
 * A status is {source, result, reason, detail, at}:
 *  - source: 'chat' (a member's Media Library chat avatar), 'gravatar', or 'media' (the bot avatar);
 *  - result: 'ok' (new copy), 'unchanged', 'no_picture' (initials), 'kept' (failed, the previous copy
 *    stays) or 'failed' (no copy, initials);
 *  - reason: a code (see message()); detail: an HTTP code, an error code, a file type or an exception
 *    class. Never an email, hash or URL.
 */
final class Avatar_Status {

	public const RESULTS = array( 'ok', 'unchanged', 'no_picture', 'kept', 'failed' );

	/**
	 * Build a status.
	 *
	 * @param string $source Source: 'chat' or 'gravatar'.
	 * @param string $result One of RESULTS.
	 * @param string $reason Reason code.
	 * @param string $detail Short detail.
	 * @param int    $at     Unix time; now when 0.
	 * @return array{source: string, result: string, reason: string, detail: string, at: int}
	 */
	public static function status( string $source, string $result, string $reason = '', string $detail = '', int $at = 0 ): array {
		return array(
			'source' => in_array( $source, array( 'chat', 'gravatar', 'media' ), true ) ? $source : 'gravatar',
			'result' => in_array( $result, self::RESULTS, true ) ? $result : 'failed',
			'reason' => (string) preg_replace( '/[^a-z0-9_]/', '', strtolower( $reason ) ),
			'detail' => mb_substr( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $detail ) ) ?? '' ), 0, 120 ),
			'at'     => $at > 0 ? $at : time(),
		);
	}

	/**
	 * One plain sentence for the site owner.
	 *
	 * @param array{source: string, result: string, reason: string, detail: string, at: int}|null $status Status, or null when never refreshed.
	 */
	public static function message( ?array $status ): string {
		if ( null === $status ) {
			return __( 'Not refreshed yet. Save the team or use Refresh avatars.', 'sigma' );
		}
		$from = array(
			'chat'     => __( 'the chat avatar', 'sigma' ),
			'media'    => __( 'the chosen image', 'sigma' ),
			'gravatar' => __( 'Gravatar', 'sigma' ),
		)[ $status['source'] ] ?? __( 'Gravatar', 'sigma' );
		switch ( $status['result'] ) {
			case 'ok':
				/* translators: 1: the picture source ("Gravatar" or "the chat avatar"), 2: a file type such as WEBP. */
				return sprintf( __( 'Copy made from %1$s (%2$s).', 'sigma' ), $from, '' !== $status['detail'] ? $status['detail'] : 'image' );
			case 'unchanged':
				/* translators: %s: the picture source. */
				return sprintf( __( 'Up to date: %s has not changed.', 'sigma' ), $from );
			case 'no_picture':
				return 'http_404' === $status['reason']
					? __( 'No Gravatar (404): the widget shows initials.', 'sigma' )
					: __( 'No picture: the widget shows initials.', 'sigma' );
			case 'kept':
				/* translators: %s: why the refresh failed. */
				return sprintf( __( 'Refresh failed: %s. The previous copy is still used.', 'sigma' ), self::reason( $status ) );
			default:
				/* translators: %s: why the refresh failed. */
				return sprintf( __( 'No copy: %s. The widget shows initials.', 'sigma' ), self::reason( $status ) );
		}
	}

	/**
	 * The reason in words.
	 *
	 * @param array{source: string, result: string, reason: string, detail: string, at: int} $status Status.
	 */
	public static function reason( array $status ): string {
		$detail = $status['detail'];
		switch ( $status['reason'] ) {
			case 'http_status':
				/* translators: %s: an HTTP status code. */
				return sprintf( __( 'Gravatar answered HTTP %s', 'sigma' ), $detail );
			case 'http_error':
				/* translators: %s: the connection error. */
				return sprintf( __( 'Gravatar could not be reached (%s)', 'sigma' ), $detail );
			case 'not_image':
				/* translators: %s: a content type. */
				return sprintf( __( 'Gravatar did not send an image (%s)', 'sigma' ), $detail );
			case 'empty':
				return __( 'Gravatar sent an empty image', 'sigma' );
			case 'too_large':
				return __( 'the image is over 2 MB', 'sigma' );
			case 'chat_missing':
				return __( 'the chat avatar file is missing from the Media Library', 'sigma' );
			case 'chat_unreadable':
				return __( 'the chat avatar file could not be read', 'sigma' );
			case 'folder':
				return __( 'the uploads/delta-assistant/avatars folder is not writable', 'sigma' );
			case 'temp':
				return __( 'a temporary file could not be written in the avatars folder', 'sigma' );
			case 'editor':
				/* translators: %s: an image editor error code. */
				return sprintf( __( 'the image editor could not open the picture (%s)', 'sigma' ), $detail );
			case 'save':
				/* translators: %s: an image editor error code. */
				return sprintf( __( 'the resized picture could not be saved as WebP or PNG (%s)', 'sigma' ), $detail );
			case 'exception':
				/* translators: %s: a PHP error class. */
				return sprintf( __( 'an error stopped the refresh (%s)', 'sigma' ), $detail );
			default:
				return __( 'unknown error', 'sigma' );
		}
	}
}
