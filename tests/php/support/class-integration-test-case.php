<?php
/**
 * Base class for integration tests.
 *
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Tests\Support;

use TwentyOnePress\Sigma\Settings;

/**
 * WordPress's WP_UnitTestCase (each test in a transaction that rolls back, the plugin's tables
 * included), plus: settings in a known state, a REST helper, and HTTP and mail capture.
 */
abstract class Integration_Test_Case extends \WP_UnitTestCase {

	/**
	 * HTTP requests the code made, with the answers given.
	 *
	 * @var list<array{url: string, args: array}>
	 */
	protected array $http = array();

	/**
	 * Start each test as an administrator, with the plugin open to visitors and the test provider.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->http = array();
		delete_transient( 'doing_cron' ); // WP-Cron's spawn lock (60 s), set by a spawn in an earlier test or the bootstrap.
		self::clear_counters(); // Counters are rows since 0.1.7; start every test from zero.
		delete_option( Settings::OPTION );
		delete_option( 'delta_assistant_corpus' );
		Settings::flush();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->settings(
			array(
				'admins_only'   => false,
				'provider'      => 'mock',
				'support_email' => 'support@example.org',
			)
		);
		reset_phpmailer_instance();
	}

	/**
	 * Change settings through the plugin's own validation.
	 *
	 * @param array<string, mixed> $values Settings.
	 */
	protected function settings( array $values ): void {
		$result = Settings::update( $values );
		$this->assertIsArray( $result, 'settings saved: ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' ) );
	}

	/**
	 * A REST request through the server, as the current user.
	 *
	 * @param string               $method  Method.
	 * @param string               $route   Route under sigma/v1, e.g. '/config'.
	 * @param array<string, mixed> $body    JSON body.
	 * @param array<string, string> $headers Headers.
	 */
	protected function rest( string $method, string $route, array $body = array(), array $headers = array() ): \WP_REST_Response {
		$path    = (string) strtok( $route, '?' );
		$request = new \WP_REST_Request( $method, '/sigma/v1' . $path );
		$query   = (string) wp_parse_url( $route, PHP_URL_QUERY );
		if ( '' !== $query ) {
			parse_str( $query, $params );
			$request->set_query_params( $params );
		}
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		if ( $body ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * rest(), callable from a closure inside a test (a request that arrives during another one).
	 *
	 * @param string                $method  Method.
	 * @param string                $route   Route.
	 * @param array<string, mixed>  $body    Body.
	 * @param array<string, string> $headers Headers.
	 */
	public function rest_public( string $method, string $route, array $body = array(), array $headers = array() ): \WP_REST_Response {
		return $this->rest( $method, $route, $body, $headers );
	}

	/**
	 * A visitor conversation: [ uuid, token ]. POST /conversations stores nothing since 0.1.7 (lazy,
	 * §10b); with $stored the row is made as a first ask would, for tests that work on it directly.
	 *
	 * @param bool $stored Create the row now.
	 * @return array{0: string, 1: string}
	 */
	protected function conversation( bool $stored = true ): array {
		self::clear_counters( 'rl:conversation_ip' );
		$response = $this->rest( 'POST', '/conversations', array( 'page_url' => home_url( '/' ) ) );
		$this->assertSame( 201, $response->get_status(), 'a conversation starts' );
		$data = $response->get_data();
		if ( $stored ) {
			( new \TwentyOnePress\Sigma\Conversations\Repository() )->create_with_uuid( $data['uuid'], \TwentyOnePress\Sigma\Security\Visitor_Token::hash( $data['token'] ), home_url( '/' ), '' );
		}
		return array( $data['uuid'], $data['token'] );
	}

	/**
	 * Delete counters whose name starts with a prefix (rate limits between steps of one test).
	 *
	 * @param string $prefix Counter name prefix.
	 */
	protected static function clear_counters( string $prefix = '' ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . \TwentyOnePress\Sigma\Schema::counters() . ' WHERE bucket LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Answer outbound HTTP with a callback; requests are recorded in $this->http.
	 *
	 * @param callable $answer fn( string $url, array $args ): array|\WP_Error, a wp_remote_* response.
	 */
	protected function answer_http( callable $answer ): void {
		add_filter(
			'pre_http_request',
			function ( $pre, array $args, string $url ) use ( $answer ) {
				$this->http[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return $answer( $url, $args );
			},
			10,
			3
		);
	}

	/**
	 * A JSON HTTP response.
	 *
	 * @param int   $status Status.
	 * @param array $body   Body.
	 */
	protected static function json( int $status, array $body ): array {
		return array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode( $body ),
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * A published doc post the corpus can use.
	 *
	 * @param string $title Title.
	 * @param string $html  Content.
	 * @param array  $extra Post fields.
	 */
	protected function doc( string $title, string $html, array $extra = array() ): int {
		return self::factory()->post->create(
			array_merge(
				array(
					'post_type'    => 'post',
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_content' => $html,
				),
				$extra
			)
		);
	}
}
