<?php
/**
 * Admin endpoints (§8): settings, transcripts, corpus status. Cookie auth + X-WP-Nonce.
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Rest;

use TwentyOnePress\Sigma\AI\Answerer;
use TwentyOnePress\Sigma\Conversations\Repository;
use TwentyOnePress\Sigma\Knowledge\Corpus;
use TwentyOnePress\Sigma\Knowledge\Knowledge_Base;
use TwentyOnePress\Sigma\Mail\Mailer;
use TwentyOnePress\Sigma\Mail\Smtp_Check;
use TwentyOnePress\Sigma\Mail\Transport;
use TwentyOnePress\Sigma\Plugin;
use TwentyOnePress\Sigma\Security\Cloudflare;
use TwentyOnePress\Sigma\Security\Rate_Limiter;
use TwentyOnePress\Sigma\Security\Turnstile;
use TwentyOnePress\Sigma\Settings;
use TwentyOnePress\Sigma\Team\Avatar_Status;
use TwentyOnePress\Sigma\Team\Avatars;
use TwentyOnePress\Sigma\Team\Team;
use TwentyOnePress\Sigma\Usage;

defined( 'ABSPATH' ) || exit;

/**
 * Settings and the corpus need manage_options; transcripts need delta_assistant_view.
 */
final class Admin_Controller {

	private const UUID = '(?P<uuid>[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})';

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$ns = Visitor_Controller::NAMESPACE;
		register_rest_route(
			$ns,
			'/admin/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'save_settings' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);
		register_rest_route(
			$ns,
			'/admin/conversations',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_conversations' ),
				'permission_callback' => array( $this, 'can_view' ),
				'args'                => array(
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					),
					'status'   => array(
						'type'    => 'string',
						'default' => '',
						'enum'    => array( '', 'open', 'waiting', 'active', 'emailed', 'closed' ),
					),
					'search'   => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/admin/conversations/' . self::UUID,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_conversation' ),
				'permission_callback' => array( $this, 'can_view' ),
			)
		);
		register_rest_route(
			$ns,
			'/admin/corpus',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_corpus' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		foreach ( array(
			'/admin/mail/check' => 'check_mail',
			'/admin/mail/test'  => 'test_mail',
		) as $route => $callback ) {
			register_rest_route(
				$ns,
				$route,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, $callback ),
					'permission_callback' => array( $this, 'can_manage' ),
				)
			);
		}
		register_rest_route(
			$ns,
			'/admin/stream-test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'stream_test' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			$ns,
			'/admin/conversations/' . self::UUID . '/retry-email',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'retry_email' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			$ns,
			'/admin/team/candidates',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'team_candidates' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'search' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/admin/team/refresh-avatars',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'refresh_avatars' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			$ns,
			'/admin/cloudflare-ranges/refresh',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'refresh_cloudflare' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			$ns,
			'/admin/preview/ask',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'preview_ask' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			$ns,
			'/admin/turnstile/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_turnstile' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			$ns,
			'/admin/corpus/rebuild',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rebuild_corpus' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	/**
	 * Settings and the corpus.
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Transcripts.
	 */
	public function can_view(): bool {
		return current_user_can( Plugin::CAP_VIEW ) || current_user_can( 'manage_options' );
	}

	/**
	 * GET /admin/settings: the settings (no keys), usage, and what the screen needs to show.
	 */
	public function get_settings(): \WP_REST_Response {
		$types = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $type ) {
			if ( in_array( $type->name, array( 'attachment', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation' ), true ) || ! Settings::source_allowed( $type->name ) ) {
				continue; // §10b: only types visitors can view may be knowledge sources.
			}
			$counts  = wp_count_posts( $type->name );
			$types[] = array(
				'name'      => $type->name,
				'label'     => $type->labels->name,
				'published' => (int) ( $counts->publish ?? 0 ),
			);
		}
		return new \WP_REST_Response(
			array(
				'settings'             => Settings::to_public(),
				'post_types'           => $types,
				'usage'                => Usage::summary(),
				'corpus'               => Corpus::status(),
				'cloudflare'           => Cloudflare::status(),
				'team'                 => array_map( array( Team::class, 'describe' ), Team::ids() ),
				'bot_avatar'           => self::bot_avatar(),
				'remy_icons'           => \TwentyOnePress\Sigma\Admin\Remy_Icon::choices(),
				// §7.6: the knowledge base is always a source; Settings shows it checked and disabled.
				'knowledge'            => self::knowledge_summary(),
				// §7.7: the editable default (placeholders unfilled) and the protected rules, read-only.
				'instructions_default' => Settings::default_instructions(),
				'system_rules'         => Answerer::system_rules( 'whole' ),
				'instructions_max'     => Settings::INSTRUCTIONS_MAX,
				'mail'                 => Transport::status() + array( 'failed_handoffs' => ( new Repository() )->count_email_failed() ),
				'ai_status'            => Answerer::unavailable_reason(),
				'killed'               => Plugin::killed(),
			)
		);
	}

	/**
	 * POST /admin/settings: a partial update.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_settings( \WP_REST_Request $request ) {
		$input  = (array) $request->get_json_params();
		$watch  = array( 'sources', 'provider', 'anthropic_model', 'anthropic_key_enc', 'instructions' ); // Instructions are in the counted prefix (§7.7).
		$before = array_intersect_key( Settings::all(), array_flip( $watch ) );
		// Switching Turnstile on needs the saved keys to have passed "Test keys", unchanged (§10b).
		$enabling = filter_var( $input['turnstile_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN ) && ! Settings::get( 'turnstile_enabled' );
		$rekeying = ( array_key_exists( 'turnstile_site_key', $input ) && (string) Settings::get( 'turnstile_site_key' ) !== (string) $input['turnstile_site_key'] ) || ( isset( $input['turnstile_secret'] ) && '' !== (string) $input['turnstile_secret'] );
		if ( $enabling && ( $rekeying || ! Turnstile::tested( (string) Settings::get( 'turnstile_site_key' ), Settings::turnstile_secret() ) ) ) {
			return new \WP_Error(
				'invalid_setting',
				__( 'Save the Turnstile keys and test them first: "Test keys" must accept the secret before the protection can be switched on.', 'sigma' ),
				array(
					'status' => 400,
					'field'  => 'turnstile_enabled',
				)
			);
		}
		$updated = Settings::update( $input );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		// What the corpus or its exact token count depends on changed: rebuild and count again, in
		// WP-Cron right away (§10b: never inside the admin request).
		if ( array_intersect_key( Settings::all(), array_flip( $watch ) ) !== $before ) {
			Corpus::schedule_now();
		}
		if ( array_key_exists( 'team', $input ) ) {
			Team::sync_caps(); // Grant delta_assistant_reply to the team, remove it from everyone else.
			Avatars::refresh_team();
		} elseif ( array_key_exists( 'bot_avatar', $input ) ) {
			try {
				Avatars::refresh( Avatars::BOT ); // A new Remy image: copy it now (§8.6).
			} catch ( \Throwable $e ) {
				Avatars::refresh_soon(); // Try again in WP-Cron; the save itself stands.
			}
		}
		return $this->get_settings();
	}

	/**
	 * GET /admin/conversations.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function list_conversations( \WP_REST_Request $request ): \WP_REST_Response {
		$result   = ( new Repository() )->list(
			array(
				'page'     => (int) $request['page'],
				'per_page' => (int) $request['per_page'],
				'status'   => (string) $request['status'],
				'search'   => (string) $request['search'],
			)
		);
		$per_page = (int) $request['per_page'];
		$response = new \WP_REST_Response(
			array(
				'items' => $result['items'],
				'total' => $result['total'],
				'pages' => (int) ceil( $result['total'] / max( 1, $per_page ) ),
			)
		);
		$response->header( 'X-WP-Total', (string) $result['total'] );
		return $response;
	}

	/**
	 * GET /admin/conversations/{uuid}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_conversation( \WP_REST_Request $request ) {
		$repo         = new Repository();
		$conversation = $repo->find_by_uuid( (string) $request['uuid'] );
		if ( ! $conversation ) {
			return new \WP_Error( 'not_found', __( 'This conversation does not exist.', 'sigma' ), array( 'status' => 404 ) );
		}
		return new \WP_REST_Response(
			array(
				'conversation' => Repository::shape_conversation( $conversation ),
				'messages'     => array_map( array( self::class, 'with_knowledge' ), $repo->messages( (int) $conversation['id'] ) ),
			)
		);
	}

	/**
	 * A message with the knowledge entries it used as `meta.kb = [{ id, title }]` (§7.6), for
	 * "Used: …" in the transcript. Entries deleted since are dropped; titles are current.
	 *
	 * @param array<string, mixed> $message Message (meta decoded).
	 * @return array<string, mixed>
	 */
	public static function with_knowledge( array $message ): array {
		if ( empty( $message['meta']['kb'] ) || ! is_array( $message['meta']['kb'] ) ) {
			return $message;
		}
		$used = array();
		foreach ( $message['meta']['kb'] as $id ) {
			$post = Knowledge_Base::find( (int) $id );
			if ( null !== $post ) {
				$used[] = array(
					'id'    => (int) $post->ID,
					'title' => (string) $post->post_title,
				);
			}
		}
		$message['meta']['kb'] = $used;
		return $message;
	}

	/**
	 * POST /admin/preview/ask (§7.7): answer a question from Settings' live preview with the unsaved
	 * instructions. Nothing is stored but the usage; 20 an hour per admin.
	 *
	 * Body: { question, instructions?: string|null, history?: [{ role: user|assistant, content }] }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function preview_ask( \WP_REST_Request $request ) {
		$body     = (array) $request->get_json_params();
		$question = trim( (string) ( $body['question'] ?? '' ) );
		if ( '' === $question || mb_strlen( $question ) > 2000 ) {
			return new \WP_Error(
				'invalid_setting',
				__( 'Ask a question of up to 2,000 characters.', 'sigma' ),
				array(
					'status' => 400,
					'field'  => 'question',
				)
			);
		}
		$instructions = null;
		if ( isset( $body['instructions'] ) ) {
			$clean = Settings::clean_instructions( (string) $body['instructions'] );
			if ( is_wp_error( $clean ) ) {
				return $clean;
			}
			$instructions = $clean;
		}
		$turns = array();
		foreach ( array_slice( (array) ( $body['history'] ?? array() ), -6 ) as $turn ) {
			$role = is_array( $turn ) ? (string) ( $turn['role'] ?? '' ) : '';
			if ( in_array( $role, array( 'user', 'assistant' ), true ) && '' !== trim( (string) ( $turn['content'] ?? '' ) ) ) {
				$turns[] = array(
					'role'    => $role,
					'content' => mb_substr( (string) $turn['content'], 0, 4000 ),
				);
			}
		}
		$turns[] = array(
			'role'    => 'user',
			'content' => $question,
		);
		// Alternate roles, starting with the visitor (what the providers expect).
		$rows  = array_map(
			static fn( array $t ): array => array(
				'sender' => 'user' === $t['role'] ? 'visitor' : 'ai',
				'body'   => $t['content'],
			),
			$turns
		);
		$turns = Answerer::turns( $rows );

		$wait = Rate_Limiter::hit( 'preview_admin_hour', 'u' . get_current_user_id() );
		if ( $wait > 0 ) {
			return new \WP_Error(
				'rate_limited',
				__( 'That is 20 previews this hour. Try again later.', 'sigma' ),
				array(
					'status'      => 429,
					'retry_after' => $wait,
				)
			);
		}
		$answerer = new Answerer( new Repository() );
		$answer   = $answerer->preview( $turns, $instructions );
		if ( ! $answerer->reached_provider() ) {
			Rate_Limiter::refund( 'preview_admin_hour', 'u' . get_current_user_id() );
		}
		if ( is_wp_error( $answer ) ) {
			return $answer;
		}
		$kb = self::with_knowledge( array( 'meta' => array( 'kb' => $answer['meta']['kb'] ?? array() ) ) )['meta']['kb'];
		return new \WP_REST_Response(
			array(
				'body'              => $answer['body'],
				'links'             => $answer['links'],
				'answered'          => $answer['answered'],
				'handoff_requested' => (bool) $answer['handoff'],
				'kb'                => is_array( $kb ) ? $kb : array(),
				'meta'              => array(
					'model' => (string) ( $answer['meta']['model'] ?? '' ),
					'cost'  => (float) ( $answer['meta']['cost'] ?? 0 ),
				),
			)
		);
	}

	/**
	 * Knowledge base counts for Settings.
	 *
	 * @return array{active: int, draft: int, active_words: int}
	 */
	private static function knowledge_summary(): array {
		$summary = Knowledge_Base::summary();
		return array(
			'active'       => $summary['counts']['active'],
			'draft'        => $summary['counts']['draft'],
			'active_words' => $summary['active_words'],
		);
	}

	/**
	 * POST /admin/stream-test: a canned 5-second stream from this server, no AI, no cost (§8.3).
	 * The browser times the first event against the last: when they arrive together, something
	 * between PHP and the browser buffers responses.
	 *
	 * @return \WP_REST_Response
	 */
	public function stream_test(): \WP_REST_Response {
		$sse = new Sse();
		$sse->open();
		$started = microtime( true );
		$sse->event( 'start', array( 'test' => true ) );
		/**
		 * Filters the pause between self-test events, in microseconds (tests use 0).
		 *
		 * @param int $pause Microseconds.
		 */
		$pause = (int) \TwentyOnePress\Sigma\Hooks::filter( 'stream_test_pause', 500000 );
		for ( $i = 1; $i <= 10; $i++ ) {
			if ( $pause > 0 ) {
				usleep( $pause );
			}
			/* translators: 1: part, 2: parts. */
			$sse->event( 'delta', array( 'text' => sprintf( __( 'Part %1$d of %2$d. ', 'sigma' ), $i, 10 ) ) );
		}
		$sse->event( 'done', array( 'server_seconds' => round( microtime( true ) - $started, 2 ) ) );
		$sse->close();
		return new \WP_REST_Response( null, 200 ); // Only reached when a test captures the stream.
	}

	/**
	 * POST /admin/mail/check: the step-by-step SMTP report for the saved settings (§10a check 2).
	 */
	public function check_mail(): \WP_REST_Response {
		$steps = Smtp_Check::run(
			array(
				'host'       => (string) Settings::get( 'smtp_host' ),
				'port'       => (int) Settings::get( 'smtp_port' ),
				'encryption' => (string) Settings::get( 'smtp_encryption' ),
				'auth'       => (bool) Settings::get( 'smtp_auth' ),
				'username'   => (string) Settings::get( 'smtp_username' ),
				'password'   => Settings::smtp_password(),
			),
			Transport::ssl_options()
		);
		return new \WP_REST_Response(
			array(
				'ok'    => ! in_array( false, array_column( $steps, 'ok' ), true ),
				'steps' => $steps,
			)
		);
	}

	/**
	 * POST /admin/mail/test: a test email to the support address, the way a handoff goes (check 3).
	 */
	public function test_mail(): \WP_REST_Response {
		$sent = ( new Mailer() )->send_test();
		return new \WP_REST_Response(
			array(
				'sent'   => $sent,
				'to'     => Settings::support_email(),
				'status' => Transport::status(),
			)
		);
	}

	/**
	 * POST /admin/conversations/{uuid}/retry-email: send a failed handoff email again.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function retry_email( \WP_REST_Request $request ) {
		$repo         = new Repository();
		$conversation = $repo->find_by_uuid( (string) $request['uuid'] );
		if ( ! $conversation || empty( $conversation['visitor_email'] ) ) {
			return new \WP_Error( 'not_found', __( 'This conversation has no handoff to send.', 'sigma' ), array( 'status' => 404 ) );
		}
		$messages = $repo->messages( (int) $conversation['id'] );
		$handoffs = array_values( array_filter( $messages, static fn( array $m ): bool => ! empty( $m['meta']['handoff'] ) ) );
		$text     = $handoffs ? (string) end( $handoffs )['body'] : '';
		$sent     = ( new Mailer() )->send_handoff( $conversation, (string) $conversation['visitor_name'], (string) $conversation['visitor_email'], $text, $messages );
		$repo->update(
			(int) $conversation['id'],
			$sent ? array(
				'email_status' => 'sent',
				'emailed_at'   => Repository::now(),
			) : array( 'email_status' => 'failed' )
		);
		return new \WP_REST_Response(
			array(
				'sent'         => $sent,
				'email_status' => $sent ? 'sent' : 'failed',
				'error'        => $sent ? '' : Transport::status()['last_error'],
			)
		);
	}

	/**
	 * GET /admin/team/candidates: users who can be on the team (Author and above).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function team_candidates( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( Team::candidates( sanitize_text_field( (string) $request['search'] ) ) );
	}

	/**
	 * The bot avatar for Settings → Remy: what the widget shows, the chosen image and the last refresh.
	 *
	 * @return array<string, mixed>
	 */
	private static function bot_avatar(): array {
		$attachment = (int) Settings::get( 'bot_avatar' );
		$status     = Avatars::status( Avatars::BOT );
		return array(
			'url'         => Avatars::bot_url(),
			'default'     => '' === Avatars::url( Avatars::BOT ),
			'default_url' => Avatars::bot_default_url(),
			'preview'     => $attachment ? (string) wp_get_attachment_image_url( $attachment, 'thumbnail' ) : '',
			'status'      => $status,
			'message'     => $attachment ? Avatar_Status::message( $status ) : __( 'No image chosen: the widget uses the default bot icon.', 'sigma' ),
		);
	}

	/**
	 * POST /admin/team/refresh-avatars: make the team's avatar copies again now (Settings → Team).
	 * Returns the team with each member's avatar status.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function refresh_avatars() {
		if ( ! Avatars::refresh_team() ) {
			return new \WP_Error( 'refresh_running', __( 'A refresh is already running. Try again in a minute.', 'sigma' ), array( 'status' => 409 ) );
		}
		return new \WP_REST_Response( array_map( array( Team::class, 'describe' ), Team::ids() ) );
	}

	/**
	 * POST /admin/cloudflare-ranges/refresh: download Cloudflare's current IP ranges.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function refresh_cloudflare() {
		$status = Cloudflare::refresh();
		return is_wp_error( $status ) ? $status : new \WP_REST_Response( $status );
	}

	/**
	 * GET /admin/corpus.
	 */
	public function get_corpus(): \WP_REST_Response {
		return new \WP_REST_Response( Corpus::status() );
	}

	/**
	 * POST /admin/corpus/rebuild.
	 */
	public function rebuild_corpus(): \WP_REST_Response {
		Corpus::schedule_now(); // §10b: in WP-Cron, as a logged-out visitor, not inside this request.
		return new \WP_REST_Response( Corpus::status() + array( 'rebuilding' => true ) );
	}

	/**
	 * POST /admin/turnstile/test: siteverify with the saved secret and a dummy token (§10b).
	 */
	public function test_turnstile(): \WP_REST_Response {
		return new \WP_REST_Response( Turnstile::test_keys() );
	}
}
