<?php
/**
 * Visitor endpoints (§8), authenticated by the per-conversation X-DA-Token.
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Rest;

use TwentyOnePress\Sigma\AI\Answerer;
use TwentyOnePress\Sigma\Conversations\Repository;
use TwentyOnePress\Sigma\Mail\Mailer;
use TwentyOnePress\Sigma\Plugin;
use TwentyOnePress\Sigma\Security\Ask_Slots;
use TwentyOnePress\Sigma\Security\Rate_Limiter;
use TwentyOnePress\Sigma\Security\Turnstile;
use TwentyOnePress\Sigma\Security\Visitor_Token;
use TwentyOnePress\Sigma\Settings;
use TwentyOnePress\Sigma\Team\Avatars;
use TwentyOnePress\Sigma\Team\Team;

defined( 'ABSPATH' ) || exit;

/**
 * No WP nonce: the pages that load the widget are cached. While "Show to admins only" is on, the
 * widget is printed only for administrators, with a REST nonce, and these routes require it.
 */
final class Visitor_Controller {

	public const NAMESPACE = 'sigma/v1';

	/** Longest visitor message (§10). */
	public const MAX_TEXT = 2000;

	private const UUID = '(?P<uuid>[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})';

	/**
	 * Conversations.
	 *
	 * @var Repository
	 */
	private Repository $repo;

	/**
	 * Create the controller.
	 *
	 * @param Repository|null $repo Conversations.
	 */
	public function __construct( ?Repository $repo = null ) {
		$this->repo = $repo ?? new Repository();
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/config',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'config' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/conversations',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create' ),
				'permission_callback' => array( $this, 'gate' ),
				'args'                => array(
					'page_url'        => array(
						'type'    => 'string',
						'default' => '',
					),
					'locale'          => array(
						'type'    => 'string',
						'default' => '',
					),
					'turnstile_token' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/conversations/' . self::UUID . '/ask',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'ask' ),
				'permission_callback' => array( $this, 'owner' ),
				'args'                => array(
					'text'     => array(
						'type'     => 'string',
						'required' => true,
					),
					'page_url' => array(
						'type'    => 'string',
						'default' => '',
					),
					'locale'   => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/conversations/' . self::UUID . '/handoff',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handoff' ),
				'permission_callback' => array( $this, 'owner' ),
				'args'                => array(
					'name'            => array(
						'type'     => 'string',
						'required' => true,
					),
					'email'           => array(
						'type'     => 'string',
						'required' => true,
					),
					'text'            => array(
						'type'     => 'string',
						'required' => true,
					),
					'website'         => array(
						'type'    => 'string',
						'default' => '',
					),
					'idempotency_key' => array(
						'type'    => 'string',
						'default' => '',
					),
					'turnstile_token' => array(
						'type'    => 'string',
						'default' => '',
					),
					'page_url'        => array(
						'type'    => 'string',
						'default' => '',
					),
					'locale'          => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * GET /config: what the widget needs once it opens.
	 */
	public function config(): \WP_REST_Response {
		$enabled  = true === $this->gate();
		$ai       = $enabled && '' === Answerer::unavailable_reason();
		$logo     = (string) Settings::get( 'logo_url' );
		$response = self::response(
			array(
				'enabled'              => $enabled,
				'ai_enabled'           => $ai,
				'human_enabled'        => $enabled && (bool) Settings::get( 'human_enabled' ),
				'confirmation_email'   => false,
				'bot_name'             => Settings::bot_name(), // Owner, v1.6: no visitor email; the chat confirms.
				'greeting'             => Settings::text( 'greeting' ),
				'intro'                => Settings::text( 'intro' ),
				'human_card_title'     => Settings::text( 'human_card_title' ),
				'human_reply_text'     => Settings::text( 'human_reply_text' ),
				'bot_avatar_url'       => Avatars::bot_url(),
				'bot_card_title'       => Settings::text( 'bot_card_title' ),
				'bot_card_text'        => Settings::text( 'bot_card_text' ),
				'bot_intro'            => Settings::text( 'bot_intro' ), // Rendered by the widget; never sent to the model or stored.
				'continue_card_title'  => Settings::text( 'continue_card_title' ),
				'continue_human_title' => Settings::text( 'continue_human_title' ),
				'privacy_text_ai'      => Settings::text( 'privacy_text_ai' ),
				'privacy_text_human'   => Settings::text( 'privacy_text_human' ),
				'privacy_url'          => Settings::privacy_url(),
				'retention_days'       => (int) Settings::get( 'retention_days' ),
				'voice_input'          => (bool) Settings::get( 'voice_input' ), // §19.4: dictation in the AI chat, by the browser.
				'brand'                => array(
					'name'          => Settings::brand_name(),
					'logo_url'      => '' !== $logo ? $logo : null,
					'color_light'   => (string) Settings::get( 'color_light' ),
					'color_dark'    => (string) Settings::get( 'color_dark' ),
					// Panel palette and launcher icon (#41); empty colours = Delta's own.
					'bg_light'      => (string) Settings::get( 'color_bg_light' ),
					'bg_dark'       => (string) Settings::get( 'color_bg_dark' ),
					'text_light'    => (string) Settings::get( 'color_text_light' ),
					'text_dark'     => (string) Settings::get( 'color_text_dark' ),
					'launcher_icon' => (string) Settings::get( 'launcher_icon' ),
					'scheme'        => (string) Settings::get( 'scheme' ),
					'position'      => (string) Settings::get( 'position' ),
					'offset_x'      => (int) Settings::get( 'offset_x' ),
					'offset_y'      => (int) Settings::get( 'offset_y' ),
					'font'          => (string) Settings::get( 'font' ),
				),
				'agents_online'        => 0,
				// §10b: links to this host stay clickable in the widget; Turnstile's site key ('' = off).
				'site_host'            => self::site_host(),
				'turnstile_site_key'   => Settings::turnstile_on() ? (string) Settings::get( 'turnstile_site_key' ) : '',
				'turnstile_theme'      => (string) Settings::get( 'turnstile_appearance' ),
			) + Team::public_config()
		);
		// The browser may keep it 5 minutes (§8.1); `private` keeps Cloudflare and other shared caches out.
		$response->header( 'Cache-Control', 'private, max-age=300' );
		return $response;
	}

	/**
	 * Whether the visitor API is open: not killed, and the visitor is an admin while "admins only"
	 * is on.
	 *
	 * @return true|\WP_Error
	 */
	public function gate() {
		if ( Plugin::killed() ) {
			return new \WP_Error( 'disabled', __( 'The help chat is switched off.', 'sigma' ), array( 'status' => 503 ) );
		}
		if ( Settings::get( 'admins_only' ) && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'not_available', __( 'The help chat is not available.', 'sigma' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Permission for a conversation route: the gate, then the token of that conversation.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function owner( \WP_REST_Request $request ) {
		$gate = $this->gate();
		if ( true !== $gate ) {
			return $gate;
		}
		$uuid         = strtolower( (string) $request['uuid'] );
		$token        = (string) $request->get_header( 'x_da_token' );
		$conversation = $this->repo->find_by_uuid( $uuid );
		if ( $conversation ) {
			if ( ! Visitor_Token::verify( $token, (string) $conversation['token_hash'] ) ) {
				return self::forbidden();
			}
			$request->set_param( '_conversation', $conversation );
			return true;
		}
		// Lazy conversation (§10b): no row yet. The token must be this uuid's HMAC; the row is created by
		// the route itself, only when it gets that far.
		if ( ! Visitor_Token::matches_uuid( $token, $uuid ) ) {
			return self::forbidden();
		}
		$request->set_param( '_conversation', null );
		$request->set_param( '_lazy_token', $token );
		return true;
	}

	/**
	 * POST /conversations.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create( \WP_REST_Request $request ) {
		$ip   = Rate_Limiter::client_ip();
		$hits = array_merge( Rate_Limiter::ip_hits( 'conversation_ip', $ip ), Rate_Limiter::ip_hits( 'conversation_ip_day', $ip ) );
		$hit  = Rate_Limiter::hit_all( $hits );
		if ( $hit['wait'] > 0 ) {
			return self::rate_limited( $hit['wait'], str_ends_with( $hit['name'], '_day' ) ? 'day' : 'hour' );
		}
		// Past half of the site-wide question breaker, a new conversation needs a Turnstile token (§10b).
		[ $global_max ] = Rate_Limiter::limits()['ask_global_hour'] ?? array( 600 );
		$checked        = Turnstile::check( (string) $request['turnstile_token'], $ip, Repository::counter_get( 'rl:ask_global_hour:global' ) * 2 > (int) $global_max );
		if ( is_wp_error( $checked ) ) {
			Rate_Limiter::refund_all( $hits );
			return $checked;
		}
		// Cloudflare unreachable: the conversation starts; its questions keep every other limit (§10b).
		// Lazy (§10b): nothing is stored here. The first ask or handoff makes the row.
		$uuid = wp_generate_uuid4();
		return self::response(
			array(
				'uuid'     => $uuid,
				'token'    => Visitor_Token::for_uuid( $uuid ),
				'greeting' => Settings::greeting(),
			),
			201
		);
	}

	/**
	 * The conversation of a request, created now for a lazy uuid (first ask or handoff, §10b).
	 *
	 * @param \WP_REST_Request $request Request (after owner()).
	 * @return array<string, mixed>|\WP_Error
	 */
	private function conversation_for( \WP_REST_Request $request ) {
		$conversation = $request['_conversation'];
		if ( is_array( $conversation ) ) {
			return $conversation;
		}
		// The page the chat started on: the widget's page_url, else the Referer (older widget builds), and
		// only on this site's host (www or not), so a stored page link can never point elsewhere.
		$page_url = (string) $request['page_url'];
		if ( ! self::on_site( $page_url ) ) {
			$page_url = (string) $request->get_header( 'referer' );
		}
		if ( ! self::on_site( $page_url ) ) {
			$page_url = '';
		}
		$row = $this->repo->create_with_uuid(
			strtolower( (string) $request['uuid'] ),
			Visitor_Token::hash( (string) $request['_lazy_token'] ),
			esc_url_raw( mb_substr( $page_url, 0, 500 ) ),
			(string) preg_replace( '/[^A-Za-z0-9_\-]/', '', mb_substr( (string) $request['locale'], 0, 20 ) )
		);
		if ( ! $row || ! Visitor_Token::verify( (string) $request['_lazy_token'], (string) $row['token_hash'] ) ) {
			return self::forbidden();
		}
		return $row;
	}

	/**
	 * POST /conversations/{uuid}/ask.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function ask( \WP_REST_Request $request ) {
		$text  = self::clean_text( (string) $request['text'] );
		$error = self::check_text( $text );
		if ( $error ) {
			return $error;
		}
		$existing = $request['_conversation'];
		if ( is_array( $existing ) && 'closed' === $existing['status'] ) {
			return new \WP_Error( 'closed', __( 'This conversation is closed. Start a new one.', 'sigma' ), array( 'status' => 409 ) );
		}

		// One answer in flight per conversation, 2 per IP, 4 on the site (§10b), before anything else.
		$uuid  = strtolower( (string) $request['uuid'] );
		$ip    = Rate_Limiter::client_ip();
		$slots = new Ask_Slots();
		if ( ! $slots->acquire( $uuid, Rate_Limiter::ip_keys( $ip ) ) ) {
			return self::busy();
		}
		try {
			// Counted before the call (§10b): per conversation, per IP (/64 and /48), then the site breaker.
			$hits = array_merge(
				array( array( 'ask_day', 'c:' . $uuid ), array( 'ask_hour', 'c:' . $uuid ) ),
				Rate_Limiter::ip_hits( 'ask_ip_day', $ip ),
				Rate_Limiter::ip_hits( 'ask_ip_hour', $ip ),
				array( array( 'ask_global_hour', 'global' ) )
			);
			$hit  = Rate_Limiter::hit_all( $hits );
			if ( $hit['wait'] > 0 ) {
				return self::rate_limited( $hit['wait'], str_ends_with( $hit['name'], '_day' ) ? 'day' : 'hour' );
			}
			$conversation = $this->conversation_for( $request );
			if ( is_wp_error( $conversation ) ) {
				Rate_Limiter::refund_all( $hits );
				return $conversation;
			}
			$id       = (int) $conversation['id'];
			$question = $this->repo->add_message( $id, 'visitor', $text );
			$answerer = new Answerer( $this->repo );
			if ( self::wants_stream( $request ) ) {
				return $this->ask_stream( $conversation, $question, $answerer, $hits );
			}
			$answer = $answerer->answer( $conversation );
			if ( is_wp_error( $answer ) ) {
				// Not answered: forget the question, so "Try again" neither repeats it nor counts twice.
				$this->repo->delete_message( (int) $question['id'] );
				if ( ! $answerer->reached_provider() ) {
					Rate_Limiter::refund_all( $hits ); // Nothing was sent to the provider (§10b refund rule).
				}
				return $answer;
			}
			return self::response( $this->store_answer( $id, $answer ) );
		} finally {
			$slots->release();
		}
	}

	/**
	 * Whether the client asked for a stream (§8.3): `Accept: text/event-stream`.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	private static function wants_stream( \WP_REST_Request $request ): bool {
		return str_contains( strtolower( (string) $request->get_header( 'accept' ) ), 'text/event-stream' );
	}

	/**
	 * /ask as a stream (§8.3): start, delta…, then done (the JSON answer's shape) or error. Checks that
	 * fail before the provider call stay normal JSON errors. A visitor who leaves mid-stream does not
	 * stop the answer being finished and stored.
	 *
	 * @param array<string, mixed>              $conversation Conversation row.
	 * @param array<string, mixed>              $question     The stored visitor message.
	 * @param Answerer                          $answerer     The answerer (knows whether the provider was reached).
	 * @param list<array{0: string, 1: string}> $hits         The limit hits to refund when it was not.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function ask_stream( array $conversation, array $question, Answerer $answerer, array $hits ) {
		$sse    = new Sse();
		$opened = false;
		$answer = $answerer->stream(
			$conversation,
			static function () use ( $sse, &$opened, $conversation ): void {
				$sse->open();
				$opened = true;
				$sse->event( 'start', array( 'conversation' => (string) $conversation['uuid'] ) );
			},
			static function ( string $text ) use ( $sse ): void {
				$sse->event( 'delta', array( 'text' => $text ) );
			},
			static function () use ( $sse ): void {
				$sse->maybe_ping();
			}
		);

		if ( is_wp_error( $answer ) ) {
			// Not answered: forget the question, so "Try again" neither repeats it nor counts twice.
			$this->repo->delete_message( (int) $question['id'] );
			if ( ! $answerer->reached_provider() ) {
				Rate_Limiter::refund_all( $hits ); // Nothing was sent to the provider (§10b refund rule).
			}
			if ( ! $opened ) {
				return $answer; // Before the stream: a normal JSON error.
			}
			$sse->event(
				'error',
				array(
					'code'    => $answer->get_error_code(),
					'message' => $answer->get_error_message(),
					'data'    => array( 'retryable' => true ) + (array) $answer->get_error_data(),
				)
			);
		} else {
			$sse->event( 'done', $this->store_answer( (int) $conversation['id'], $answer ) );
		}
		$sse->close();
		return new \WP_REST_Response( null, 200 ); // Only reached when a test captures the stream.
	}

	/**
	 * Store an AI answer and shape the /ask response (the JSON body, and the stream's `done`).
	 *
	 * @param int                  $id     Conversation id.
	 * @param array<string, mixed> $answer From Answerer.
	 * @return array<string, mixed>
	 */
	private function store_answer( int $id, array $answer ): array {
		$message = $this->repo->add_message(
			$id,
			'ai',
			$answer['body'],
			$answer['meta'] + array(
				'answered'          => $answer['answered'],
				'handoff_requested' => (bool) ( $answer['handoff'] ?? false ),
			)
		);
		return array(
			'message'           => array(
				'id'     => $message['id'],
				'sender' => 'ai',
				'body'   => $message['body'],
				'links'  => $answer['links'],
			),
			'answered'          => $answer['answered'],
			'handoff_requested' => (bool) ( $answer['handoff'] ?? false ), // §8.7: offer a human under this reply.
			'can_handoff'       => (bool) Settings::get( 'human_enabled' ),
		);
	}

	/**
	 * POST /conversations/{uuid}/handoff (release 1: email only).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handoff( \WP_REST_Request $request ) {
		if ( ! Settings::get( 'human_enabled' ) ) {
			return new \WP_Error( 'human_unavailable', __( 'Contact by email is switched off.', 'sigma' ), array( 'status' => 403 ) );
		}
		// Honeypot (§10): a bot that fills every field gets the normal answer and nothing is sent.
		if ( '' !== trim( (string) $request['website'] ) ) {
			return self::response( array( 'status' => 'emailed' ) );
		}

		$existing = $request['_conversation'];
		if ( is_array( $existing ) && 'closed' === $existing['status'] ) {
			return new \WP_Error( 'closed', __( 'This conversation is closed. Start a new one.', 'sigma' ), array( 'status' => 409 ) );
		}
		$name  = Mailer::clean_name( sanitize_text_field( (string) $request['name'] ) );
		$email = sanitize_email( Mailer::clean_line( (string) $request['email'] ) );
		$text  = self::clean_text( (string) $request['text'] );
		if ( '' === $name ) {
			return self::invalid( 'name', __( 'Please enter your name.', 'sigma' ) );
		}
		if ( ! is_email( $email ) ) {
			return self::invalid( 'email', __( 'Please enter a valid email address.', 'sigma' ) );
		}
		$error = self::check_text( $text );
		if ( $error ) {
			return $error;
		}
		// Idempotency (§8, v1.7.1). A widget without a key (older build) gets one made here: it still
		// sends once, and a second handoff on the conversation answers 409.
		$key = strtolower( trim( (string) $request['idempotency_key'] ) );
		if ( '' === $key ) {
			$key = wp_generate_uuid4();
		} elseif ( ! wp_is_uuid( $key ) ) {
			return self::invalid( 'idempotency_key', __( 'The request key is not valid.', 'sigma' ) );
		}
		if ( is_array( $existing ) ) {
			$prior = $this->handoff_result( (int) $existing['id'], $key );
			if ( $prior ) {
				return $prior;
			}
		}
		$ip      = Rate_Limiter::client_ip();
		$checked = Turnstile::check( (string) $request['turnstile_token'], $ip );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}
		$outage  = Turnstile::UNREACHABLE === $checked;
		$ip_hits = Rate_Limiter::ip_hits( 'handoff_ip', $ip );
		if ( $outage ) {
			// Cloudflare is down (§10b): the handoff is accepted, but with 1 per hour per IP.
			$ip_hits = array_merge( $ip_hits, Rate_Limiter::ip_hits( 'handoff_ip_unreachable', $ip ) );
		}
		$hit = Rate_Limiter::hit_all( $ip_hits );
		if ( $hit['wait'] > 0 ) {
			return self::rate_limited( $hit['wait'] );
		}
		// Site-wide cap (§10b): a flood from many IPs can't fill the support inbox.
		if ( Rate_Limiter::hit( 'handoff_global_hour', 'global' ) > 0 ) {
			Rate_Limiter::refund_all( $ip_hits );
			return new \WP_Error( 'handoff_busy', __( 'Our inbox is busy, try again later.', 'sigma' ), array( 'status' => 503 ) );
		}
		$conversation = $this->conversation_for( $request );
		if ( is_wp_error( $conversation ) ) {
			return $conversation;
		}
		$id = (int) $conversation['id'];

		// Check and claim in one statement: only one request per conversation gets past this line.
		$now = Repository::now();
		if ( ! $this->repo->claim_handoff(
			$id,
			$key,
			array(
				'mode'          => 'human',
				'status'        => 'emailed',
				'visitor_name'  => $name,
				'visitor_email' => $email,
				'handoff_at'    => $now,
				'email_status'  => 'sending',
			)
		) ) {
			return $this->handoff_result( $id, $key ) ?? new \WP_Error( 'handoff_failed', __( 'Please try again.', 'sigma' ), array( 'status' => 500 ) );
		}

		$this->repo->add_message(
			$id,
			'visitor',
			$text,
			$outage ? array(
				'handoff'   => true,
				'turnstile' => 'unreachable',
			) : array( 'handoff' => true )
		);
		$sent = ( new Mailer() )->send_handoff( $conversation, $name, $email, $text, $this->repo->messages( $id ) );
		// Stored either way (§10a): a failed email is marked, shown in Conversations with Retry send
		// and an admin notice; the visitor's message is safe, so "we got your message" stays true.
		$this->repo->update(
			$id,
			array(
				'emailed_at'   => $sent ? Repository::now() : null,
				'email_status' => $sent ? 'sent' : 'failed',
			)
		);
		/* translators: %s: visitor email. */
		$this->repo->add_message( $id, 'system', sprintf( __( 'We got your message. We will reply to %s by email.', 'sigma' ), $email ) );

		return self::response(
			array(
				'status'       => 'emailed',
				'email_status' => $sent ? 'sent' : 'failed',
			)
		);
	}

	/**
	 * The stored handoff result when this conversation already handed off: the same key gets it back
	 * (200, nothing sent again), another key gets 409 already_handed_off with it. Null when the
	 * conversation has not handed off yet.
	 *
	 * @param int    $id  Conversation id.
	 * @param string $key Idempotency key of this request.
	 * @return \WP_REST_Response|\WP_Error|null
	 */
	private function handoff_result( int $id, string $key ) {
		$this->repo->fail_stale_sending();
		$row = $this->repo->find( $id );
		if ( ! $row || ( empty( $row['handoff_key'] ) && empty( $row['handoff_at'] ) ) ) {
			return null;
		}
		$stored = array(
			'status'       => (string) $row['status'],
			'email_status' => (string) ( $row['email_status'] ?? '' ),
		);
		// A handoff from before the key existed (schema v2) has no key: any new key is a second handoff.
		if ( '' !== (string) $row['handoff_key'] && hash_equals( (string) $row['handoff_key'], $key ) ) {
			return self::response( $stored );
		}
		// data.status is the HTTP code (WordPress), so the stored result goes under data.result.
		return new \WP_Error(
			'already_handed_off',
			__( 'This conversation was already sent to the team.', 'sigma' ),
			array(
				'status' => 409,
				'result' => $stored,
			)
		);
	}

	/**
	 * Visitor text: plain, trimmed, line breaks kept.
	 *
	 * @param string $text Text.
	 */
	private static function clean_text( string $text ): string {
		return trim( sanitize_textarea_field( $text ) );
	}

	/**
	 * Empty or too long text is a 400.
	 *
	 * @param string $text Clean text.
	 */
	private static function check_text( string $text ): ?\WP_Error {
		if ( '' === $text ) {
			return self::invalid( 'text', __( 'Please type a message.', 'sigma' ) );
		}
		if ( mb_strlen( $text ) > self::MAX_TEXT ) {
			/* translators: %d: maximum characters. */
			return new \WP_Error( 'text_too_long', sprintf( __( 'Please keep your message under %d characters.', 'sigma' ), self::MAX_TEXT ), array( 'status' => 400 ) );
		}
		return null;
	}

	/**
	 * A 400 for a field.
	 *
	 * @param string $field   Field.
	 * @param string $message Message.
	 */
	private static function invalid( string $field, string $message ): \WP_Error {
		return new \WP_Error(
			'invalid_' . $field,
			$message,
			array(
				'status' => 400,
				'field'  => $field,
			)
		);
	}

	/**
	 * A 429 with retry_after (seconds, also sent as a Retry-After header) and scope (§8.1).
	 *
	 * @param int    $seconds Seconds to wait.
	 * @param string $scope   hour|day: which window is full.
	 */
	private static function rate_limited( int $seconds, string $scope = 'hour' ): \WP_Error {
		return new \WP_Error(
			'rate_limited',
			__( 'Too many messages. Please wait a moment.', 'sigma' ),
			array(
				'status'      => 429,
				'retry_after' => max( 1, $seconds ),
				'scope'       => $scope,
			)
		);
	}

	/**
	 * A 403 for a wrong token (also for an unknown uuid: never says whether it exists).
	 */
	private static function forbidden(): \WP_Error {
		return new \WP_Error( 'invalid_token', __( 'This conversation belongs to someone else.', 'sigma' ), array( 'status' => 403 ) );
	}

	/**
	 * A 429 busy: no answer slot free right now (§10b), try again in a few seconds.
	 */
	private static function busy(): \WP_Error {
		return new \WP_Error(
			'busy',
			__( 'The assistant is busy. Try again in a few seconds.', 'sigma' ),
			array(
				'status'      => 429,
				'retry_after' => 5,
			)
		);
	}

	/**
	 * Whether a URL is an http(s) page of this site: its host is the home URL's host, with or without
	 * a leading "www.".
	 *
	 * @param string $url URL.
	 */
	public static function on_site( string $url ): bool {
		if ( '' === $url ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) ) {
			return false;
		}
		$bare = static fn( string $host ): string => (string) preg_replace( '/^www\./', '', strtolower( $host ) );
		$home = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		return '' !== $home && $bare( (string) ( $parts['host'] ?? '' ) ) === $bare( $home );
	}

	/**
	 * The site's host for the widget's link check (§10b): bare host, with the port only when it is
	 * not the default one.
	 */
	public static function site_host(): string {
		$parts = wp_parse_url( home_url() );
		$host  = strtolower( (string) ( $parts['host'] ?? '' ) );
		$port  = (int) ( $parts['port'] ?? 0 );
		$https = 'https' === ( $parts['scheme'] ?? '' );
		return ( $port > 0 && ( $https ? 443 : 80 ) !== $port ) ? $host . ':' . $port : $host;
	}

	/**
	 * A JSON response that no cache keeps.
	 *
	 * @param array<string, mixed> $data   Data.
	 * @param int                  $status Status.
	 */
	private static function response( array $data, int $status = 200 ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	/**
	 * Hooks for every response of the namespace, errors included: no caching, Retry-After on 429.
	 */
	public static function hooks(): void {
		add_filter(
			'rest_post_dispatch',
			static function ( $response, $server, $request ) {
				if ( $response instanceof \WP_REST_Response && $request instanceof \WP_REST_Request && str_starts_with( $request->get_route(), '/' . self::NAMESPACE . '/' ) ) {
					if ( ! isset( $response->get_headers()['Cache-Control'] ) ) {
						$response->header( 'Cache-Control', 'no-store, private' );
					}
					$data = $response->get_data();
					if ( 429 === $response->get_status() && is_array( $data ) && isset( $data['data']['retry_after'] ) ) {
						$response->header( 'Retry-After', (string) (int) $data['data']['retry_after'] );
					}
				}
				return $response;
			},
			10,
			3
		);
	}
}
