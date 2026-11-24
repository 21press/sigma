<?php
/**
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Tests\Integration;

use TwentyOnePress\Sigma\Plugin;
use TwentyOnePress\Sigma\Team\Avatars;
use TwentyOnePress\Sigma\Team\Team;
use TwentyOnePress\Sigma\Tests\Support\Integration_Test_Case;

/**
 * The team behind "Talk to a human" and its avatars (§8.4).
 *
 * @covers \TwentyOnePress\Sigma\Team\Team
 * @covers \TwentyOnePress\Sigma\Team\Avatars
 */
final class Team_Test extends Integration_Test_Case {

	/**
	 * Which picture the fake avatar server returns: 'red', 'blue', 'jpeg' (the fixture), '404' or
	 * '503'. A URL with /avatar/throw throws.
	 *
	 * @var string
	 */
	private string $picture = 'red';

	/**
	 * The test image editor (Test_Avatar_Editor) reports no WebP support.
	 *
	 * @var bool
	 */
	public static bool $no_webp = false;

	/**
	 * The test image editor reports WebP support but fails to save WebP.
	 *
	 * @var bool
	 */
	public static bool $webp_save_fails = false;

	public function set_up(): void {
		parent::set_up();
		$this->picture         = 'red';
		self::$no_webp         = false;
		self::$webp_save_fails = false;
		$this->answer_http(
			function ( string $url ) {
				if ( ! str_contains( $url, 'gravatar.com' ) ) {
					return new \WP_Error( 'unexpected', $url );
				}
				if ( '404' === $this->picture ) {
					return self::json( 404, array() );
				}
				if ( '503' === $this->picture ) {
					return self::json( 503, array() );
				}
				if ( str_contains( $url, '/avatar/throw' ) ) {
					throw new \RuntimeException( 'boom' );
				}
				if ( 'jpeg' === $this->picture ) {
					return array(
						'headers'  => array( 'content-type' => 'image/jpeg' ),
						'body'     => (string) file_get_contents( dirname( __DIR__, 3 ) . '/fixtures/avatar.jpg' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture.
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'cookies'  => array(),
					);
				}
				return array(
					'headers'  => array( 'content-type' => 'image/png' ),
					'body'     => self::png( 'red' === $this->picture ? array( 220, 40, 40 ) : array( 40, 40, 220 ) ),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
				);
			}
		);
	}

	/**
	 * A 192×192 PNG of one colour.
	 *
	 * @param array{0: int, 1: int, 2: int} $rgb Colour.
	 */
	private static function png( array $rgb ): string {
		$img = imagecreatetruecolor( 192, 192 );
		imagefill( $img, 0, 0, imagecolorallocate( $img, $rgb[0], $rgb[1], $rgb[2] ) );
		ob_start();
		imagepng( $img );
		return (string) ob_get_clean();
	}

	private function user( string $role, string $first = 'Maya', string $email = '' ): int {
		return self::factory()->user->create(
			array(
				'role'       => $role,
				'first_name' => $first,
				'user_login' => strtolower( $first ) . wp_rand( 1000, 9999 ),
				'user_email' => '' !== $email ? $email : strtolower( $first ) . wp_rand( 1000, 9999 ) . '@example.org',
			)
		);
	}

	private function save_team( array $ids ): array {
		$response = $this->rest( 'POST', '/admin/settings', array( 'team' => $ids ) );
		$this->assertSame( 200, $response->get_status() );
		return $response->get_data();
	}

	public function test_authors_and_above_can_be_picked_contributors_cannot(): void {
		$author      = $this->user( 'author', 'Maya' );
		$contributor = $this->user( 'contributor', 'Raj' );
		$this->save_team( array( $contributor, $author ) );
		$this->assertSame( array( $author ), Team::ids() );
		$ids = array_column( $this->rest( 'GET', '/admin/team/candidates' )->get_data(), 'id' );
		$this->assertContains( $author, $ids );
		$this->assertNotContains( $contributor, $ids );
	}

	public function test_saving_grants_and_revokes_the_reply_capability(): void {
		$maya = $this->user( 'author', 'Maya' );
		$raj  = $this->user( 'editor', 'Raj' );
		$this->save_team( array( $maya, $raj ) );
		$this->assertTrue( user_can( $maya, Plugin::CAP_REPLY ) );
		$this->assertTrue( user_can( $raj, Plugin::CAP_REPLY ) );
		$this->save_team( array( $raj ) );
		$this->assertFalse( user_can( $maya, Plugin::CAP_REPLY ), 'dropped from the team: capability removed' );
		$this->assertTrue( user_can( $raj, Plugin::CAP_REPLY ) );
	}

	public function test_a_member_who_loses_publish_posts_drops_out_on_read_and_at_the_daily_refresh(): void {
		$maya = $this->user( 'author', 'Maya' );
		$raj  = $this->user( 'author', 'Raj' );
		$this->save_team( array( $maya, $raj ) );
		get_user_by( 'id', $maya )->set_role( 'contributor' );
		$this->assertSame( array( $raj ), Team::ids(), 'filtered on read' );
		Avatars::refresh_team();
		$this->assertFalse( user_can( $maya, Plugin::CAP_REPLY ) );
	}

	public function test_config_lists_up_to_four_in_order_with_the_total(): void {
		$ids = array();
		foreach ( array( 'Maya', 'Raj', 'Ana', 'Lee', 'Kim' ) as $name ) {
			$ids[] = $this->user( 'author', $name );
		}
		$this->save_team( $ids );
		wp_set_current_user( 0 );
		$config = $this->rest( 'GET', '/config' )->get_data();
		$this->assertSame( array( 'Maya', 'Raj', 'Ana', 'Lee' ), array_column( $config['team'], 'name' ) );
		$this->assertSame( 5, $config['team_total'] );

		$this->settings( array( 'team_avatars' => false ) );
		$config = $this->rest( 'GET', '/config' )->get_data();
		$this->assertSame( array(), $config['team'] );
		$this->assertSame( 0, $config['team_total'] );
	}

	public function test_no_gravatar_url_or_email_hash_ever_reaches_config(): void {
		$email = 'maya.private@example.org';
		$maya  = $this->user( 'author', 'Maya', $email );
		$this->save_team( array( $maya ) );
		wp_set_current_user( 0 );
		$json = (string) wp_json_encode( $this->rest( 'GET', '/config' )->get_data() );
		$this->assertStringNotContainsString( 'gravatar', $json );
		foreach ( array( md5( $email ), hash( 'sha256', $email ), $email, 'maya.private' ) as $leak ) {
			$this->assertStringNotContainsString( $leak, $json, 'no email or email hash' );
		}
		$url = Team::public_config()['team'][0]['avatar_url'];
		$this->assertMatchesRegularExpression( '#/uploads/delta-assistant/avatars/[a-z0-9]{20}\.(webp|png)$#', $url, 'a copy on this site under a random name' );
		$this->assertSame( array( 96, 96 ), array_slice( (array) getimagesize( Avatars::dir()['path'] . '/' . basename( $url ) ), 0, 2 ) );
	}

	public function test_avatars_rotate_when_the_picture_changes_and_old_files_go(): void {
		$maya  = $this->user( 'author', 'Maya' );
		$this->save_team( array( $maya ) );
		$first = Avatars::url( $maya );
		$file1 = Avatars::dir()['path'] . '/' . basename( $first );
		$this->assertFileExists( $file1 );

		Avatars::refresh( $maya );
		$this->assertSame( $first, Avatars::url( $maya ), 'same picture: same file' );

		$this->picture = 'blue';
		Avatars::refresh( $maya );
		$second = Avatars::url( $maya );
		$this->assertNotSame( $first, $second, 'a new picture gets a new random name' );
		$this->assertFileDoesNotExist( $file1, 'the old file is deleted' );

		$this->picture = '503';
		Avatars::refresh( $maya );
		$this->assertSame( $second, Avatars::url( $maya ), 'a temporary failure keeps the good copy' );

		$this->picture = '404';
		Avatars::refresh( $maya );
		$this->assertSame( '', Avatars::url( $maya ) );
		$this->assertFileDoesNotExist( Avatars::dir()['path'] . '/' . basename( $second ) );
	}

	public function test_no_picture_means_initials_and_a_chat_name_never_shows_the_login_or_email(): void {
		$this->picture = '404'; // Gravatar's answer to default=404 when the user has no picture.
		$id            = self::factory()->user->create(
			array(
				'role'         => 'author',
				'user_login'   => 'raj-login',
				'display_name' => 'raj-login',
				'user_email'   => 'raj@example.org',
			)
		);
		$this->save_team( array( $id ) );
		$entry = Team::public_config()['team'][0];
		$this->assertSame( '', $entry['avatar_url'], 'the widget draws initials' );
		$this->assertNotSame( 'raj-login', $entry['name'] );
		$this->assertStringNotContainsString( '@', $entry['name'] );

		update_user_meta( $id, Team::META_NAME, 'Raj' );
		$this->assertSame( 'Raj', Team::public_config()['team'][0]['name'] );
	}

	public function test_a_chat_avatar_from_the_media_library_wins_over_gravatar(): void {
		$maya = $this->user( 'author', 'Maya' );
		$file = trailingslashit( sys_get_temp_dir() ) . 'da-avatar-' . wp_generate_password( 8, false ) . '.png';
		file_put_contents( $file, self::png( array( 20, 160, 20 ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$attachment = self::factory()->attachment->create_upload_object( $file );
		update_user_meta( $maya, Team::META_AVATAR, $attachment );
		$this->picture = '404';
		$this->save_team( array( $maya ) );
		$this->assertNotSame( '', Avatars::url( $maya ), 'the chat avatar is used even without a Gravatar' );
		$this->assertSame( array(), array_filter( $this->http, static fn( array $r ): bool => str_contains( $r['url'], 'gravatar' ) ), 'and Gravatar is not asked' );
		$this->assertSame( 'chat', Avatars::status( $maya )['source'] );
	}

	public function test_a_chat_avatar_smaller_than_96px_is_kept_as_a_square_without_upscaling(): void {
		$maya = $this->user( 'author', 'Maya' );
		$file = trailingslashit( sys_get_temp_dir() ) . 'da-avatar-small-' . wp_generate_password( 8, false ) . '.png';
		$img  = imagecreatetruecolor( 80, 60 );
		imagefill( $img, 0, 0, imagecolorallocate( $img, 20, 20, 160 ) );
		imagepng( $img, $file );
		update_user_meta( $maya, Team::META_AVATAR, self::factory()->attachment->create_upload_object( $file ) );
		$this->save_team( array( $maya ) );
		$this->assertSame( 'ok', Avatars::status( $maya )['result'], Avatars::status( $maya )['reason'] . ' ' . Avatars::status( $maya )['detail'] );
		$this->assertSame( array( 60, 60 ), array_slice( (array) getimagesize( Avatars::dir()['path'] . '/' . basename( Avatars::url( $maya ) ) ), 0, 2 ) );
	}

	/**
	 * Use the test editor (GD with switchable WebP) for the rest of the test.
	 */
	private function use_test_editor(): void {
		require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
		require_once ABSPATH . WPINC . '/class-wp-image-editor-gd.php';
		require_once __DIR__ . '/class-test-avatar-editor.php';
		add_filter( 'wp_image_editors', static fn(): array => array( Test_Avatar_Editor::class ) );
	}

	public function test_a_real_jpeg_goes_through_refresh_and_write_to_a_96px_webp_and_the_status_says_so(): void {
		$this->picture = 'jpeg';
		$maya          = $this->user( 'author', 'Maya' );
		$this->save_team( array( $maya ) );
		$url  = Avatars::url( $maya );
		$file = Avatars::dir()['path'] . '/' . basename( $url );
		$this->assertMatchesRegularExpression( '#/delta-assistant/avatars/[a-z0-9]{20}\.webp$#', $url );
		$info = (array) getimagesize( $file );
		$this->assertSame( array( 96, 96, 'image/webp' ), array( $info[0], $info[1], $info['mime'] ) );
		$status = Avatars::status( $maya );
		$this->assertSame( array( 'gravatar', 'ok', 'WEBP' ), array( $status['source'], $status['result'], $status['detail'] ) );
		$this->assertEqualsWithDelta( time(), $status['at'], 5 );
		$this->assertSame( array(), (array) glob( Avatars::dir()['path'] . '/incoming-*' ), 'no temporary file left behind' );
	}

	public function test_without_webp_support_the_copy_is_a_png(): void {
		$this->use_test_editor();
		self::$no_webp = true;
		$this->picture = 'jpeg';
		$maya          = $this->user( 'author', 'Maya' );
		$this->save_team( array( $maya ) );
		$url = Avatars::url( $maya );
		$this->assertStringEndsWith( '.png', $url );
		$info = (array) getimagesize( Avatars::dir()['path'] . '/' . basename( $url ) );
		$this->assertSame( array( 96, 96, 'image/png' ), array( $info[0], $info[1], $info['mime'] ) );
		$this->assertSame( 'PNG', Avatars::status( $maya )['detail'] );
	}

	public function test_when_webp_is_reported_but_saving_it_fails_the_copy_falls_back_to_png(): void {
		$this->use_test_editor();
		self::$webp_save_fails = true;
		$this->picture         = 'jpeg';
		$maya                  = $this->user( 'author', 'Maya' );
		$this->save_team( array( $maya ) );
		$url = Avatars::url( $maya );
		$this->assertStringEndsWith( '.png', $url );
		$this->assertSame( 'ok', Avatars::status( $maya )['result'] );
		$this->assertFileDoesNotExist( Avatars::dir()['path'] . '/' . basename( $url, '.png' ) . '.webp', 'no half-written WebP left' );
	}

	public function test_the_url_follows_the_sites_scheme_and_domain_and_old_records_still_work(): void {
		$maya = $this->user( 'author', 'Maya' );
		$this->save_team( array( $maya ) );
		$name = basename( Avatars::url( $maya ) );

		$http = static function ( array $dir ): array {
			$dir['baseurl'] = 'http://example.org/wp-content/uploads';
			return $dir;
		};
		add_filter( 'upload_dir', $http );
		$this->assertSame( 'http://example.org/wp-content/uploads/delta-assistant/avatars/' . $name, Avatars::url( $maya ), 'plain http site: http' );
		$_SERVER['HTTPS'] = 'on';
		$this->assertSame( 'https://example.org/wp-content/uploads/delta-assistant/avatars/' . $name, Avatars::url( $maya ), 'an http uploads URL becomes https on an https request' );
		unset( $_SERVER['HTTPS'] );
		remove_filter( 'upload_dir', $http );

		$moved = static function ( array $dir ): array {
			$dir['baseurl'] = 'https://www.example.com/wp-content/uploads';
			return $dir;
		};
		add_filter( 'upload_dir', $moved );
		$this->assertSame( 'https://www.example.com/wp-content/uploads/delta-assistant/avatars/' . $name, Avatars::url( $maya ), 'built on read: follows a domain move' );
		remove_filter( 'upload_dir', $moved );

		// A 0.1.2 record: the full path and a URL stored.
		update_user_meta(
			$maya,
			Avatars::META,
			array(
				'file' => Avatars::dir()['path'] . '/' . $name,
				'url'  => 'http://old.example/' . $name,
				'hash' => 'x',
			)
		);
		$this->assertStringEndsWith( '/delta-assistant/avatars/' . $name, Avatars::url( $maya ) );
		$this->assertStringNotContainsString( 'old.example', Avatars::url( $maya ) );
	}

	public function test_every_refresh_records_its_outcome_without_the_email_or_hash(): void {
		$email         = 'raj.private@example.org';
		$raj           = $this->user( 'author', 'Raj', $email );
		$this->picture = '503';
		$this->save_team( array( $raj ) );
		$status = Avatars::status( $raj );
		$this->assertSame( array( 'failed', 'http_status', '503' ), array( $status['result'], $status['reason'], $status['detail'] ) );

		$this->picture = '404';
		Avatars::refresh( $raj );
		$this->assertSame( array( 'no_picture', 'http_404' ), array( Avatars::status( $raj )['result'], Avatars::status( $raj )['reason'] ) );

		$this->picture = 'red';
		Avatars::refresh( $raj );
		Avatars::refresh( $raj );
		$this->assertSame( 'unchanged', Avatars::status( $raj )['result'] );

		$this->picture = '503';
		Avatars::refresh( $raj );
		$this->assertSame( 'kept', Avatars::status( $raj )['result'], 'a failure with a good copy keeps it' );
		$this->assertNotSame( '', Avatars::url( $raj ) );

		$stored = wp_json_encode( get_user_meta( $raj, Avatars::STATUS_META, true ) ) . wp_json_encode( get_user_meta( $raj, Avatars::META, true ) );
		foreach ( array( $email, md5( $email ), hash( 'sha256', $email ), 'gravatar.com' ) as $leak ) {
			$this->assertStringNotContainsString( $leak, (string) $stored );
		}
	}

	public function test_an_error_for_one_member_never_breaks_the_save_or_the_others(): void {
		$maya = $this->user( 'author', 'Maya' );
		$raj  = $this->user( 'author', 'Raj' );
		add_filter(
			'pre_get_avatar_data',
			static function ( array $args, $id ) use ( $maya ): array {
				if ( (int) $id === $maya ) {
					$args['url'] = 'https://secure.gravatar.com/avatar/throw?d=404';
				}
				return $args;
			},
			10,
			2
		);
		$this->save_team( array( $maya, $raj ) ); // Asserts a 200.
		$status = Avatars::status( $maya );
		$this->assertSame( array( 'failed', 'exception', 'RuntimeException' ), array( $status['result'], $status['reason'], $status['detail'] ) );
		$this->assertNotSame( '', Avatars::url( $raj ), 'the next member still gets a copy' );
	}

	public function test_refresh_avatars_route_returns_each_members_status_for_admins_only(): void {
		$maya = $this->user( 'author', 'Maya' );
		$this->save_team( array( $maya ) );
		$response = $this->rest( 'POST', '/admin/team/refresh-avatars' );
		$this->assertSame( 200, $response->get_status() );
		$member = $response->get_data()[0];
		$this->assertSame( $maya, $member['id'] );
		$this->assertSame( 'unchanged', $member['avatar_status']['result'] );
		$this->assertSame( 'Up to date: Gravatar has not changed.', $member['avatar_message'] );
		$this->assertSame( Avatars::url( $maya ), $member['avatar_copy'] );

		wp_set_current_user( $maya );
		$this->assertSame( 403, $this->rest( 'POST', '/admin/team/refresh-avatars' )->get_status() );
	}
}
