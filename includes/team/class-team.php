<?php
/**
 * The team that answers "Talk to a human" (§8.4, §9).
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Team;

use TwentyOnePress\Sigma\Plugin;
use TwentyOnePress\Sigma\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * An ordered list of user ids in the `team` setting. Only users who can publish_posts (Author and
 * above) can be on it, and the list is filtered on every read, so someone who loses that capability
 * drops out by itself. Saving grants `delta_assistant_reply` to the team and removes it from everyone
 * else (R2's live chat uses it).
 */
final class Team {

	/** Display name in chat (user meta). */
	public const META_NAME = 'delta_assistant_chat_name';

	/** Chat avatar attachment id (user meta). */
	public const META_AVATAR = 'delta_assistant_chat_avatar';

	/** Most members /config lists (§8.4). */
	public const PUBLIC_MAX = 4;

	/**
	 * Whether a user may be on the team.
	 *
	 * @param int $user_id User id.
	 */
	public static function eligible( int $user_id ): bool {
		return $user_id > 0 && user_can( $user_id, 'publish_posts' );
	}

	/**
	 * Team member ids in picker order, eligible users only.
	 *
	 * @return list<int>
	 */
	public static function ids(): array {
		return array_values( array_filter( array_map( 'intval', (array) Settings::get( 'team' ) ), array( self::class, 'eligible' ) ) );
	}

	/**
	 * Whether a user is on the team.
	 *
	 * @param int $user_id User id.
	 */
	public static function has( int $user_id ): bool {
		return in_array( $user_id, self::ids(), true );
	}

	/**
	 * Keep the reply capability in step with the team: grant to members, remove from everyone else.
	 */
	public static function sync_caps(): void {
		$team = self::ids();
		foreach ( $team as $id ) {
			$user = get_user_by( 'id', $id );
			if ( $user && ! $user->has_cap( Plugin::CAP_REPLY ) ) {
				$user->add_cap( Plugin::CAP_REPLY );
			}
		}
		foreach ( get_users(
			array(
				'capability' => Plugin::CAP_REPLY,
				'fields'     => 'all',
			)
		) as $user ) {
			if ( ! in_array( (int) $user->ID, $team, true ) ) {
				$user->remove_cap( Plugin::CAP_REPLY );
			}
		}
	}

	/**
	 * The name the visitor sees: the profile's display name in chat, else the first name, else the
	 * first word of the display name when it is not the login or an email. Never the login or email.
	 *
	 * @param int $user_id User id.
	 */
	public static function chat_name( int $user_id ): string {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return '';
		}
		$name = trim( (string) get_user_meta( $user_id, self::META_NAME, true ) );
		if ( '' === $name ) {
			$name = trim( (string) $user->first_name );
		}
		if ( '' === $name ) {
			$display = trim( (string) $user->display_name );
			if ( '' !== $display && $display !== $user->user_login && ! str_contains( $display, '@' ) ) {
				$name = (string) strtok( $display, ' ' );
			}
		}
		if ( '' === $name || $name === $user->user_login || str_contains( $name, '@' ) ) {
			// No usable name: a neutral word that reads well in "Team: Raj, Maya and Support".
			$name = __( 'Support', 'sigma' );
		}
		return mb_substr( $name, 0, 40 );
	}

	/**
	 * `team` and `team_total` for /config (§8.4). No WordPress avatar URL ever appears here: avatars
	 * are the plugin's own copies (Avatars), or '' for initials.
	 *
	 * @return array{team: list<array{name: string, avatar_url: string}>, team_total: int}
	 */
	public static function public_config(): array {
		$ids = self::ids();
		// Schedules a one-off refresh (never fetches here) for a member or the bot never copied.
		Avatars::heal_missing( Settings::get( 'team_avatars' ) ? $ids : array() );
		if ( ! Settings::get( 'team_avatars' ) || ! $ids ) {
			return array(
				'team'       => array(),
				'team_total' => 0,
			);
		}
		$team = array();
		foreach ( array_slice( $ids, 0, self::PUBLIC_MAX ) as $id ) {
			$team[] = array(
				'name'       => self::chat_name( $id ),
				'avatar_url' => Avatars::url( $id ),
			);
		}
		return array(
			'team'       => $team,
			'team_total' => count( $ids ),
		);
	}

	/**
	 * Users who can be picked, for the admin search (admin-only data: may include avatar URLs).
	 *
	 * @param string $search Name, login or email fragment.
	 * @return list<array{id: int, name: string, chat_name: string, role: string, avatar: string}>
	 */
	public static function candidates( string $search = '' ): array {
		$args = array(
			'capability' => 'publish_posts',
			'number'     => 50,
			'orderby'    => 'display_name',
			'fields'     => 'all',
		);
		if ( '' !== $search ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'display_name', 'user_nicename' );
		}
		$out = array();
		foreach ( get_users( $args ) as $user ) {
			$out[] = self::describe( (int) $user->ID );
		}
		return $out;
	}

	/**
	 * One user for the admin screen. Avatar diagnostics (Settings → Team): `avatar_copy` is the copy the widget gets ('' for
	 * initials), `avatar_status` the last refresh (Avatar_Status, null when never refreshed) and
	 * `avatar_message` that status in words.
	 *
	 * @param int $user_id User id.
	 * @return array{id: int, name: string, chat_name: string, role: string, avatar: string, avatar_copy: string, avatar_source: string, avatar_status: array<string, mixed>|null, avatar_message: string}
	 */
	public static function describe( int $user_id ): array {
		$user   = get_user_by( 'id', $user_id );
		$roles  = wp_roles()->get_names();
		$role   = $user && $user->roles ? (string) reset( $user->roles ) : '';
		$copy   = Avatars::url( $user_id );
		$status = Avatars::status( $user_id );
		return array(
			'id'             => $user_id,
			'name'           => $user ? (string) $user->display_name : '',
			'chat_name'      => self::chat_name( $user_id ),
			'role'           => isset( $roles[ $role ] ) ? translate_user_role( $roles[ $role ] ) : $role,
			'avatar'         => '' !== $copy ? $copy : (string) get_avatar_url( $user_id, array( 'size' => 48 ) ),
			'avatar_copy'    => $copy,
			'avatar_source'  => Avatars::source_kind( $user_id ),
			'avatar_status'  => $status,
			'avatar_message' => Avatar_Status::message( $status ),
		);
	}
}
