<?php
/**
 * Users → Profile → "Sigma", for team members only (§8.4).
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Team;

defined( 'ABSPATH' ) || exit;

/**
 * "Display name in chat" (defaults to the first name; never the login or email) and an optional
 * "Chat avatar" from the Media Library. Saving refreshes the member's avatar copy.
 */
final class Profile {

	private const NONCE = 'delta_assistant_profile';

	/**
	 * Hooks.
	 */
	public static function hooks(): void {
		add_action( 'show_user_profile', array( self::class, 'render' ) );
		add_action( 'edit_user_profile', array( self::class, 'render' ) );
		add_action( 'personal_options_update', array( self::class, 'save' ) );
		add_action( 'edit_user_profile_update', array( self::class, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/**
	 * The Media Library picker on profile screens of team members.
	 *
	 * @param string $hook Screen hook suffix.
	 */
	public static function enqueue( $hook ): void {
		if ( ! in_array( $hook, array( 'profile.php', 'user-edit.php' ), true ) ) {
			return;
		}
		$user_id = 'profile.php' === $hook ? get_current_user_id() : ( isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Which profile is shown; no action.
		if ( ! Team::has( $user_id ) ) {
			return;
		}
		wp_enqueue_media();
		wp_add_inline_script(
			'media-editor',
			'jQuery(function($){var f;$(document).on("click","#da-chat-avatar-pick",function(e){e.preventDefault();if(!f){f=wp.media({title:' . wp_json_encode( __( 'Chat avatar', 'sigma' ) ) . ',library:{type:"image"},multiple:false});f.on("select",function(){var a=f.state().get("selection").first().toJSON();$("#da-chat-avatar").val(a.id);$("#da-chat-avatar-preview").attr("src",(a.sizes&&a.sizes.thumbnail?a.sizes.thumbnail:a).url).show();});}f.open();});$(document).on("click","#da-chat-avatar-clear",function(e){e.preventDefault();$("#da-chat-avatar").val("");$("#da-chat-avatar-preview").hide();});});'
		);
	}

	/**
	 * The profile section.
	 *
	 * @param \WP_User $user User.
	 */
	public static function render( $user ): void {
		if ( ! $user instanceof \WP_User || ! Team::has( (int) $user->ID ) ) {
			return;
		}
		$name       = (string) get_user_meta( $user->ID, Team::META_NAME, true );
		$attachment = (int) get_user_meta( $user->ID, Team::META_AVATAR, true );
		$preview    = $attachment ? (string) wp_get_attachment_image_url( $attachment, 'thumbnail' ) : '';
		wp_nonce_field( self::NONCE, '_da_profile_nonce' );
		?>
		<h2><?php esc_html_e( 'Sigma', 'sigma' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="da-chat-name"><?php esc_html_e( 'Display name in chat', 'sigma' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="da-chat-name" name="delta_assistant_chat_name" maxlength="40" value="<?php echo esc_attr( $name ); ?>" placeholder="<?php echo esc_attr( Team::chat_name( (int) $user->ID ) ); ?>" />
					<p class="description"><?php esc_html_e( 'What visitors see. Empty: the first name. Never your login or email.', 'sigma' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Chat avatar', 'sigma' ); ?></th>
				<td>
					<input type="hidden" id="da-chat-avatar" name="delta_assistant_chat_avatar" value="<?php echo esc_attr( $attachment ? (string) $attachment : '' ); ?>" />
					<img id="da-chat-avatar-preview" src="<?php echo esc_url( $preview ); ?>" alt="" width="48" height="48" style="border-radius:50%;vertical-align:middle;<?php echo $preview ? '' : 'display:none;'; ?>" />
					<button type="button" class="button" id="da-chat-avatar-pick"><?php esc_html_e( 'Choose image', 'sigma' ); ?></button>
					<button type="button" class="button-link" id="da-chat-avatar-clear"><?php esc_html_e( 'Remove', 'sigma' ); ?></button>
					<p class="description"><?php esc_html_e( 'Optional. Without one, your profile picture is used if you have one, else your initial. The widget shows a 96 px copy kept on this site.', 'sigma' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save the section.
	 *
	 * @param int $user_id User id.
	 */
	public static function save( $user_id ): void {
		$user_id = (int) $user_id;
		if ( ! current_user_can( 'edit_user', $user_id ) || ! Team::has( $user_id ) ) {
			return;
		}
		if ( ! isset( $_POST['_da_profile_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['_da_profile_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		$name = isset( $_POST['delta_assistant_chat_name'] ) ? mb_substr( trim( sanitize_text_field( wp_unslash( (string) $_POST['delta_assistant_chat_name'] ) ) ), 0, 40 ) : '';
		$user = get_user_by( 'id', $user_id );
		if ( $user && ( $name === $user->user_login || str_contains( $name, '@' ) ) ) {
			$name = ''; // Never the login or an email.
		}
		update_user_meta( $user_id, Team::META_NAME, $name );

		$attachment = isset( $_POST['delta_assistant_chat_avatar'] ) ? absint( $_POST['delta_assistant_chat_avatar'] ) : 0;
		if ( $attachment && ( ! wp_attachment_is_image( $attachment ) || ! current_user_can( 'read_post', $attachment ) ) ) {
			$attachment = 0;
		}
		update_user_meta( $user_id, Team::META_AVATAR, $attachment ? $attachment : '' );
		Avatars::refresh( $user_id );
	}
}
