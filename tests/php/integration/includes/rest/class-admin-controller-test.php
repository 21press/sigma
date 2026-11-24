<?php
/**
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Tests\Integration;

use TwentyOnePress\Sigma\Plugin;
use TwentyOnePress\Sigma\Tests\Support\Integration_Test_Case;

/**
 * Admin routes: who may call each one, and that keys never come back.
 *
 * @covers \TwentyOnePress\Sigma\Rest\Admin_Controller
 */
final class Admin_Controller_Test extends Integration_Test_Case {

	/**
	 * Route => capability it needs.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function routes(): array {
		return array(
			'GET settings'       => array( 'GET', '/admin/settings' ),
			'POST settings'      => array( 'POST', '/admin/settings' ),
			'GET corpus'         => array( 'GET', '/admin/corpus' ),
			'POST corpus build'  => array( 'POST', '/admin/corpus/rebuild' ),
			'GET conversations'  => array( 'GET', '/admin/conversations' ),
		);
	}

	/**
	 * @dataProvider routes
	 */
	public function test_visitors_and_subscribers_are_refused( string $method, string $route ): void {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->rest( $method, $route )->get_status(), 'logged out' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->rest( $method, $route )->get_status(), 'a subscriber' );
	}

	public function test_transcripts_need_the_view_capability_settings_need_manage_options(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		$this->assertSame( 403, $this->rest( 'GET', '/admin/conversations' )->get_status() );
		get_user_by( 'id', $editor )->add_cap( Plugin::CAP_VIEW );
		wp_set_current_user( 0 );
		wp_set_current_user( $editor ); // A new request reads the user again.
		$this->assertSame( 200, $this->rest( 'GET', '/admin/conversations' )->get_status(), 'given the capability, an editor reads transcripts' );
		$this->assertSame( 403, $this->rest( 'GET', '/admin/settings' )->get_status(), 'but not settings' );
	}

	public function test_activation_gives_administrators_the_view_capability(): void {
		$this->assertTrue( get_role( 'administrator' )->has_cap( Plugin::CAP_VIEW ) );
	}

	public function test_a_saved_key_never_comes_back(): void {
		$response = $this->rest( 'POST', '/admin/settings', array( 'anthropic_key' => 'sk-ant-secret-value-9876' ) );
		$this->assertSame( 200, $response->get_status() );
		$json = (string) wp_json_encode( $response->get_data() );
		$this->assertStringNotContainsString( 'sk-ant-secret', $json );
		$this->assertStringNotContainsString( 'anthropic_key_enc', $json );
		$this->assertSame(
			array(
				'set'    => true,
				'source' => 'settings',
				'last4'  => '9876',
			),
			$response->get_data()['settings']['anthropic_key']
		);
		$this->assertStringNotContainsString( 'sk-ant-secret', (string) get_option( 'delta_assistant_settings' )['anthropic_key_enc'], 'stored encrypted' );
	}

	public function test_cloudflare_ranges_refresh_and_a_bad_download_keeps_the_old_list(): void {
		$this->answer_http( fn( string $url ) => array( 'headers' => array(), 'cookies' => array(), 'response' => array( 'code' => 200, 'message' => '' ), 'body' => str_ends_with( $url, 'v4' ) ? "1.2.3.0/24\n" : "2001:db8::/32\n" ) );
		$data = $this->rest( 'POST', '/admin/cloudflare-ranges/refresh' )->get_data();
		$this->assertSame( 'refreshed', $data['source'] );
		$this->assertSame( array( '1.2.3.0/24', '2001:db8::/32' ), \TwentyOnePress\Sigma\Security\Cloudflare::ranges() );

		remove_all_filters( 'pre_http_request' );
		$this->answer_http( fn() => array( 'headers' => array(), 'cookies' => array(), 'response' => array( 'code' => 200, 'message' => '' ), 'body' => '<html>oops</html>' ) );
		$this->assertSame( 502, $this->rest( 'POST', '/admin/cloudflare-ranges/refresh' )->get_status() );
		$this->assertSame( array( '1.2.3.0/24', '2001:db8::/32' ), \TwentyOnePress\Sigma\Security\Cloudflare::ranges(), 'the old list stays' );
	}

	public function test_the_client_ip_follows_the_setting_and_the_filter_has_the_last_word(): void {
		$_SERVER['REMOTE_ADDR']           = '172.70.1.5';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.1';
		$this->assertSame( '198.51.100.1', \TwentyOnePress\Sigma\Security\Rate_Limiter::client_ip(), 'default auto: the header, from a Cloudflare address (§10b)' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.50';
		$this->assertSame( '203.0.113.50', \TwentyOnePress\Sigma\Security\Rate_Limiter::client_ip(), 'auto: a header from anywhere else is ignored' );
		$this->settings( array( 'ip_source' => 'remote_addr' ) );
		$_SERVER['REMOTE_ADDR'] = '172.70.1.5';
		$this->assertSame( '172.70.1.5', \TwentyOnePress\Sigma\Security\Rate_Limiter::client_ip(), 'remote_addr never reads the header' );
		$this->assertTrue( \TwentyOnePress\Sigma\Security\Cloudflare::behind(), 'and Settings warns: requests come from Cloudflare' );
		$this->settings( array( 'ip_source' => 'cloudflare' ) );
		$this->assertSame( '198.51.100.1', \TwentyOnePress\Sigma\Security\Rate_Limiter::client_ip() );
		add_filter( 'sigma_client_ip', static fn() => '192.0.2.99' );
		$this->assertSame( '192.0.2.99', \TwentyOnePress\Sigma\Security\Rate_Limiter::client_ip() );
		unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );
	}

	public function test_an_invalid_setting_is_a_400_naming_the_field(): void {
		$response = $this->rest( 'POST', '/admin/settings', array( 'color_dark' => 'blue' ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'color_dark', $response->get_data()['data']['field'] );
	}

	public function test_a_transcript_reads_back_without_the_token_hash(): void {
		[ $uuid, $token ] = $this->conversation();
		$this->rest( 'POST', "/conversations/{$uuid}/handoff", array( 'name' => 'Ann', 'email' => 'ann@example.test', 'text' => 'Hello' ), array( 'X-DA-Token' => $token ) );
		$data = $this->rest( 'GET', "/admin/conversations/{$uuid}" )->get_data();
		$this->assertArrayNotHasKey( 'token_hash', $data['conversation'] );
		$this->assertSame( 'Hello', $data['messages'][0]['body'] );
		$list = $this->rest( 'GET', '/admin/conversations?search=ann%40example' )->get_data();
		$this->assertSame( 1, $list['total'] );
	}
}
