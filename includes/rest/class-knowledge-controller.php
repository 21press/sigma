<?php
/**
 * Admin REST for the knowledge base (§7.6). There are no visitor routes.
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Rest;

use TwentyOnePress\Sigma\Knowledge\Knowledge_Base;

defined( 'ABSPATH' ) || exit;

/**
 * Every route needs manage_options (and the REST nonce, which the cookie auth requires).
 */
final class Knowledge_Controller {

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$ns   = Visitor_Controller::NAMESPACE;
		$perm = array( $this, 'can_manage' );
		register_rest_route(
			$ns,
			'/admin/knowledge',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $perm,
					'args'                => array(
						'status'   => array(
							'type'    => 'string',
							'enum'    => array( 'all', 'active', 'draft' ),
							'default' => 'all',
						),
						'search'   => array(
							'type'    => 'string',
							'default' => '',
						),
						'page'     => array(
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create' ),
					'permission_callback' => $perm,
				),
			)
		);
		register_rest_route(
			$ns,
			'/admin/knowledge/titles',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'titles' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$ns,
			'/admin/knowledge/bulk',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'bulk' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$ns,
			'/admin/knowledge/import',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'import' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$ns,
			'/admin/knowledge/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'show' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => 'PUT, PATCH',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'destroy' ),
					'permission_callback' => $perm,
				),
			)
		);
	}

	/**
	 * Site administrators only.
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET /admin/knowledge.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		$page = Knowledge_Base::query( (string) $request['status'], trim( (string) $request['search'] ), (int) $request['page'], (int) $request['per_page'] );
		return self::response( $page + Knowledge_Base::summary() );
	}

	/**
	 * POST /admin/knowledge: { title, content, status, source? }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create( \WP_REST_Request $request ) {
		$body = (array) $request->get_json_params();
		$id   = Knowledge_Base::create( (string) ( $body['title'] ?? '' ), (string) ( $body['content'] ?? '' ), self::active( $body ), (string) ( $body['source'] ?? '' ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return self::response( Knowledge_Base::to_array( Knowledge_Base::find( $id ) ), 201 );
	}

	/**
	 * GET /admin/knowledge/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function show( \WP_REST_Request $request ) {
		$post = Knowledge_Base::find( (int) $request['id'] );
		return null === $post ? self::missing() : self::response( Knowledge_Base::to_array( $post ) );
	}

	/**
	 * PUT /admin/knowledge/{id}: any of { title, content, status, source }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update( \WP_REST_Request $request ) {
		$body   = (array) $request->get_json_params();
		$fields = array_intersect_key( $body, array_flip( array( 'title', 'content', 'source' ) ) );
		if ( array_key_exists( 'status', $body ) || array_key_exists( 'active', $body ) ) {
			$fields['active'] = self::active( $body );
		}
		$id = Knowledge_Base::update( (int) $request['id'], $fields );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return self::response( Knowledge_Base::to_array( Knowledge_Base::find( $id ) ) );
	}

	/**
	 * DELETE /admin/knowledge/{id}: for good, no trash.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function destroy( \WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$result = Knowledge_Base::delete( $id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::response(
			array(
				'deleted' => true,
				'id'      => $id,
			)
		);
	}

	/**
	 * GET /admin/knowledge/titles: [{ id, title }] of every entry.
	 */
	public function titles(): \WP_REST_Response {
		return self::response( Knowledge_Base::titles() );
	}

	/**
	 * POST /admin/knowledge/bulk: { action: activate|draft|delete, ids[] }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function bulk( \WP_REST_Request $request ) {
		$body   = (array) $request->get_json_params();
		$action = (string) ( $body['action'] ?? '' );
		if ( ! in_array( $action, array( 'activate', 'draft', 'delete' ), true ) ) {
			return self::invalid( 'action', __( 'Choose Make active, Move to draft or Delete.', 'sigma' ) );
		}
		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $body['ids'] ?? array() ) ) ) ) );
		if ( ! $ids || count( $ids ) > 500 ) {
			return self::invalid( 'ids', __( 'Select between 1 and 500 entries.', 'sigma' ) );
		}
		$done   = array();
		$failed = array();
		foreach ( $ids as $id ) {
			$result = 'delete' === $action ? Knowledge_Base::delete( $id ) : Knowledge_Base::set_active( $id, 'activate' === $action );
			if ( is_wp_error( $result ) ) {
				$failed[] = array(
					'id'     => $id,
					'reason' => $result->get_error_code(),
				);
			} else {
				$done[] = $id;
			}
		}
		return self::response(
			array(
				'done'   => $done,
				'failed' => $failed,
			) + Knowledge_Base::summary()
		);
	}

	/**
	 * POST /admin/knowledge/import: { files: [{ name, title, content }], active }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import( \WP_REST_Request $request ) {
		$body  = (array) $request->get_json_params();
		$files = $body['files'] ?? null;
		if ( ! is_array( $files ) || ! $files || count( $files ) > Knowledge_Base::MAX_FILES ) {
			/* translators: %d: files. */
			return self::invalid( 'files', sprintf( __( 'Import between 1 and %d files at a time.', 'sigma' ), Knowledge_Base::MAX_FILES ) );
		}
		return self::response( array( 'results' => Knowledge_Base::import( array_values( $files ), ! empty( $body['active'] ) ) ) + Knowledge_Base::summary() );
	}

	/**
	 * Active from { status: active|draft } (or a boolean `active`).
	 *
	 * @param array<string, mixed> $body Body.
	 */
	private static function active( array $body ): bool {
		if ( array_key_exists( 'status', $body ) ) {
			return 'active' === $body['status'];
		}
		return ! empty( $body['active'] );
	}

	/**
	 * A response that's never cached.
	 *
	 * @param mixed $data   Data.
	 * @param int   $status HTTP status.
	 */
	private static function response( $data, int $status = 200 ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	/**
	 * A 400 with the field named.
	 *
	 * @param string $field   Field.
	 * @param string $message Message.
	 */
	private static function invalid( string $field, string $message ): \WP_Error {
		return new \WP_Error(
			'invalid_entry',
			$message,
			array(
				'status' => 400,
				'field'  => $field,
			)
		);
	}

	/**
	 * A 404.
	 */
	private static function missing(): \WP_Error {
		return new \WP_Error( 'not_found', __( 'There is no such entry.', 'sigma' ), array( 'status' => 404 ) );
	}
}
