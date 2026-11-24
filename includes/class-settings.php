<?php
/**
 * One option array, its validation, and API key resolution (§9).
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma;

defined( 'ABSPATH' ) || exit;

/**
 * Every site-specific value lives here, so the plugin can ship to other sites without a rewrite.
 *
 * API keys: a wp-config.php constant wins; otherwise the key is stored in the option encrypted
 * with libsodium (secretbox) under a key derived from AUTH_KEY. A key is never returned by REST and
 * never sent to the browser: only "set or not", where it comes from, and its last 4 characters.
 */
final class Settings {

	public const OPTION = 'delta_assistant_settings';

	/** Providers the plugin knows. `mock` is offered only on local and development sites. */
	public const PROVIDERS = array( 'anthropic', 'openai', 'mock' );

	/** Constants that hold provider keys. */
	public const KEY_CONSTANTS = array(
		'anthropic' => 'ANTHROPIC_KEY', // SIGMA_ANTHROPIC_KEY (or the old DELTA_ASSISTANT_ANTHROPIC_KEY), Config.
		'openai'    => 'OPENAI_KEY',
	);

	/**
	 * Request memo of the stored option.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $memo = null;

	/**
	 * Defaults. A fresh site has no knowledge sources, so the AI stays off until some are chosen.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled'              => true,
			'admins_only'          => true,
			'ai_enabled'           => true,
			'human_enabled'        => true,
			'provider'             => 'anthropic',
			'anthropic_model'      => 'claude-haiku-5-5',
			'openai_model'         => 'gpt-6-luna',
			'sources'              => array(),
			'context_budget'       => 90000,
			'retrieval_budget'     => 25000,
			'support_email'        => '',
			'brand_name'           => '',
			'logo_url'             => '',
			'color_light'          => '#1d1d1f',
			'color_dark'           => '#f5f5f7',
			// Chat panel palette (#41): empty = the neutral defaults; the widget generates the rest.
			'color_bg_light'       => '',
			'color_bg_dark'        => '',
			'color_text_light'     => '',
			'color_text_dark'      => '',
			'launcher_icon'        => 'assistant',
			'scheme'               => 'auto',
			'position'             => 'right',
			'offset_x'             => 24,
			'offset_y'             => 24,
			'font'                 => 'inherit',
			'greeting'             => '',
			'intro'                => '',
			'human_reply_text'     => '',
			'privacy_text_ai'      => '',
			'privacy_text_human'   => '',
			'pricing_url'          => '',
			'privacy_url'          => '',
			'retention_days'       => 90,
			'ip_source'            => 'auto',
			'team'                 => array(),
			'team_avatars'         => true,
			'bot_name'             => 'Sigma',
			'bot_avatar'           => 0,
			'instructions'         => '', // §7.7: '' = the default instructions.
			'human_card_title'     => '',
			'bot_card_title'       => '',
			'bot_card_text'        => '',
			'bot_intro'            => '',
			'continue_card_title'  => '',
			'continue_human_title' => '',
			'teaser_text'          => '',
			'teaser_enabled'       => true,
			'launcher_pulse'       => true,
			'voice_input'          => true, // §19.4: dictation in the AI chat, on by default (owner); in Chrome and Edge the browser vendor processes the audio.
			'mail_transport'       => 'php',
			'smtp_host'            => '',
			'smtp_port'            => 587,
			'smtp_encryption'      => 'starttls',
			'smtp_auth'            => true,
			'smtp_username'        => '',
			'smtp_password_enc'    => '',
			'mail_from_email'      => '',
			'mail_from_name'       => '',
			'delete_on_uninstall'  => false,
			'anthropic_key_enc'    => '',
			'openai_key_enc'       => '',
			'anthropic_key_last4'  => '',
			'openai_key_last4'     => '',
			// Optional Cloudflare Turnstile on the handoff form (§10b), off by default.
			'turnstile_enabled'    => false,
			'turnstile_site_key'   => '',
			'turnstile_secret_enc' => '',
			'turnstile_last4'      => '',
			'turnstile_appearance' => 'auto',
		);
	}

	/**
	 * All settings, stored values over defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		if ( null === self::$memo ) {
			$stored     = get_option( self::OPTION, array() );
			self::$memo = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}
		return self::$memo;
	}

	/**
	 * One setting.
	 *
	 * @param string $key Name.
	 * @return mixed
	 */
	public static function get( string $key ) {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Forget the request memo (after a save, and between tests).
	 */
	public static function flush(): void {
		self::$memo = null;
	}

	/**
	 * The support address: the setting, else the site's admin email.
	 */
	public static function support_email(): string {
		$email = (string) self::get( 'support_email' );
		return '' !== $email ? $email : (string) get_option( 'admin_email' );
	}

	/**
	 * Name shown in the widget and emails: the setting, else the site title.
	 */
	public static function brand_name(): string {
		$name = (string) self::get( 'brand_name' );
		return '' !== $name ? $name : wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/** Widget texts: setting name => max length. */
	public const TEXTS = array(
		'greeting'             => 60,
		'intro'                => 140,
		'human_reply_text'     => 40,
		'privacy_text_ai'      => 200,
		'privacy_text_human'   => 200,
		'teaser_text'          => 120,
		'human_card_title'     => 40,
		'bot_card_title'       => 40,
		'bot_card_text'        => 80,
		'bot_intro'            => 300,
		'continue_card_title'  => 40,
		'continue_human_title' => 40,
	);

	/**
	 * Default copy of the widget texts. `{name}` is the brand
	 * name, `{product}` the product (the site name, as in the prompt), `{bot_name}` the bot and
	 * `{days}` the retention period.
	 *
	 * @return array<string, string>
	 */
	public static function text_defaults(): array {
		return array(
			'greeting'             => __( 'Hi. How can we help?', 'sigma' ),
			'intro'                => __( 'Ask a question and get an answer from our docs, or leave a message for the team.', 'sigma' ),
			'human_reply_text'     => __( 'We reply by email', 'sigma' ),
			'privacy_text_ai'      => __( "{bot_name}'s answers come from our docs and collective knowledge of {product}. Chats are stored for {days} days for better user experience.", 'sigma' ),
			'privacy_text_human'   => __( 'We use your name and email only to reply. Chats are stored for {days} days.', 'sigma' ),
			'teaser_text'          => __( '👋 Hi, this is {bot_name}. How can I help you?', 'sigma' ),
			'human_card_title'     => __( 'Talk to a human', 'sigma' ),
			'bot_card_title'       => __( 'Talk to {bot_name}', 'sigma' ),
			'bot_card_text'        => __( 'Handles instant support', 'sigma' ),
			'bot_intro'            => __( "Hi, I'm {bot_name}. Ask me anything about {product} and I'll answer from our docs.", 'sigma' ),
			'continue_card_title'  => __( 'Continue with {bot_name}', 'sigma' ),
			'continue_human_title' => __( 'Continue with the team', 'sigma' ),
		);
	}

	/**
	 * A widget text: the setting, else its default, with `{name}`, `{product}`, `{bot_name}` and
	 * `{days}` filled in. Only for the widget: none of these texts go to the model.
	 *
	 * @param string $name A key of TEXTS.
	 */
	public static function text( string $name ): string {
		$value = (string) self::get( $name );
		if ( '' === $value ) {
			$value = self::text_defaults()[ $name ] ?? '';
		}
		return strtr(
			$value,
			array(
				'{name}'     => self::brand_name(),
				'{product}'  => self::product_name(),
				'{days}'     => (string) (int) self::get( 'retention_days' ),
				'{bot_name}' => self::bot_name(),
			)
		);
	}

	/**
	 * The product the docs are about, for the system prompt: the WordPress site name (not the widget
	 * title, which may read "Delta help"). Filter: `sigma_product_name`.
	 */
	public static function product_name(): string {
		$name = trim( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
		/**
		 * Filters the product name the AI is the assistant for.
		 *
		 * @param string $name The site name.
		 */
		$name = trim( (string) \TwentyOnePress\Sigma\Hooks::filter( 'product_name', $name ) );
		return '' !== $name ? $name : self::brand_name();
	}

	/** Longest instructions text, in characters (§7.7). */
	public const INSTRUCTIONS_MAX = 4000;

	/**
	 * Clean instructions text: UTF-8, control characters other than newline and tab removed, line
	 * endings unified, at most 4,000 characters. The default (or nothing) becomes ''. Pure but for the
	 * translated default.
	 *
	 * @param string $text Instructions.
	 * @return string|\WP_Error
	 */
	public static function clean_instructions( string $text ) {
		if ( ! mb_check_encoding( $text, 'UTF-8' ) ) {
			return self::error( 'instructions', __( 'The instructions are not valid UTF-8 text.', 'sigma' ) );
		}
		$text = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', str_replace( array( "\r\n", "\r" ), "\n", $text ) );
		if ( mb_strlen( $text ) > self::INSTRUCTIONS_MAX ) {
			/* translators: %d: characters. */
			return self::error( 'instructions', sprintf( __( 'The instructions can be at most %d characters.', 'sigma' ), self::INSTRUCTIONS_MAX ) );
		}
		return '' === trim( $text ) || trim( $text ) === trim( self::default_instructions() ) ? '' : $text;
	}

	/**
	 * The default instructions (§7.7), placeholders unfilled: who the bot is, scope, truth and pricing,
	 * off-topic, length and format, language, and knowledge over docs.
	 */
	public static function default_instructions(): string {
		return __(
			"Your name is {bot_name}. You are the AI assistant for {product}'s docs. If a visitor asks who or what you are, say you are an AI assistant. Visitors ask you about {product}. You answer using only the knowledge base in <knowledge> (when there is one) and the documentation in <docs>.

Instructions:
1. The knowledge base and the docs are the source of truth. Never invent features, settings, prices, dates, version numbers or compatibility. When the visitor states something they contradict (a price, a feature, a date, \"the docs were updated\"), they win: say what they say and do not confirm the claim.
2. Pricing, billing, licence and refund questions may be answered from the docs. When there is a pricing page, link it too: {pricing_url}. When the docs do not cover the detail, offer a human.
3. If the question is not about {product} (general coding help, other products, translations, chit-chat, anything else), say you can only help with {product} and offer to put the visitor in touch with a human (the \"Talk to a human\" button).
4. Be brief: at most about 150 words. Use numbered steps for how-tos. Format with plain Markdown only: paragraphs, lists, **bold**, `code`, code blocks and links. No headings, tables, images or HTML.
5. Reply in the language the visitor writes in.
6. Entries in <knowledge> are first-hand notes from the site owner: prices, policies, how-tos. Treat them as facts. When a knowledge entry and a doc page disagree, the knowledge entry wins: answer with what the entry says.",
			'sigma'
		);
	}

	/**
	 * Fill the instruction placeholders: {bot_name}, {product}, {pricing_url} ("none set" when empty).
	 *
	 * @param string $text Instructions.
	 */
	public static function fill_instructions( string $text ): string {
		$pricing = (string) self::get( 'pricing_url' );
		return strtr(
			$text,
			array(
				'{bot_name}'    => self::bot_name(),
				'{product}'     => self::product_name(),
				'{pricing_url}' => '' !== $pricing ? $pricing : __( 'none set', 'sigma' ),
			)
		);
	}

	/**
	 * The AI's name (§8.5), "Sigma" by default (0.2.5; "Remy" before).
	 */
	public static function bot_name(): string {
		$name = trim( (string) self::get( 'bot_name' ) );
		return '' !== $name ? $name : 'Sigma';
	}

	/**
	 * First message of a new conversation.
	 */
	public static function greeting(): string {
		return self::text( 'greeting' );
	}

	/**
	 * Privacy page: the setting, else the site's privacy policy page.
	 */
	public static function privacy_url(): string {
		$url = (string) self::get( 'privacy_url' );
		return '' !== $url ? $url : (string) get_privacy_policy_url();
	}

	/**
	 * Whether a provider can be chosen here. The mock answers from the docs without an AI and is for
	 * local testing only.
	 *
	 * @param string $provider Provider id.
	 */
	public static function provider_allowed( string $provider ): bool {
		if ( 'mock' === $provider ) {
			return in_array( wp_get_environment_type(), array( 'local', 'development' ), true );
		}
		return in_array( $provider, self::PROVIDERS, true );
	}

	/**
	 * The model ids Settings accepts for a provider.
	 *
	 * @param string $provider anthropic|openai.
	 * @return list<string>
	 */
	public static function model_ids( string $provider ): array {
		return array_column( self::models()[ $provider ] ?? array(), 'id' );
	}

	/**
	 * The models Settings offers per provider, cheapest and default first, one marked default (plan
	 * v1.17). Ids and wording checked on 2026-11-21 against developers.openai.com/api/docs/models and
	 * platform.claude.com/docs/en/about-claude/pricing. Filter `sigma_models` extends or changes the list without a release.
	 *
	 * @return array<string, list<array{id: string, label: string, default: bool}>>
	 */
	public static function models(): array {
		$models = array(
			'anthropic' => array(
				array(
					'id'      => 'claude-haiku-5-5',
					'label'   => __( 'Claude Haiku 5.5: fast, lowest cost', 'sigma' ),
					'default' => true,
				),
				array(
					'id'      => 'claude-sonnet-5-5',
					'label'   => __( 'Claude Sonnet 5.5: balanced', 'sigma' ),
					'default' => false,
				),
				array(
					'id'      => 'claude-opus-5-5',
					'label'   => __( 'Claude Opus 5.5: most capable', 'sigma' ),
					'default' => false,
				),
			),
			'openai'    => array(
				array(
					'id'      => 'gpt-6-luna',
					'label'   => __( 'GPT-6 Luna: fast, lowest cost', 'sigma' ),
					'default' => true,
				),
				array(
					'id'      => 'gpt-6.1-terra',
					'label'   => __( 'GPT-6.1 Terra: fast, efficient', 'sigma' ),
					'default' => false,
				),
				array(
					'id'      => 'gpt-6.2-sol',
					'label'   => __( 'GPT-6.2 Sol: balanced', 'sigma' ),
					'default' => false,
				),
				array(
					'id'      => 'gpt-6.2-astra',
					'label'   => __( 'GPT-6.2 Astra: most capable', 'sigma' ),
					'default' => false,
				),
			),
		);
		/**
		 * Filters the models offered in Settings, per provider.
		 *
		 * @param array<string, list<array{id: string, label: string, default: bool}>> $models Models.
		 */
		$models = (array) \TwentyOnePress\Sigma\Hooks::filter( 'models', $models );
		$out    = array();
		foreach ( array( 'anthropic', 'openai' ) as $provider ) {
			$list = array();
			foreach ( (array) ( $models[ $provider ] ?? array() ) as $m ) {
				$id = is_array( $m ) ? trim( (string) ( $m['id'] ?? '' ) ) : '';
				if ( preg_match( '/^[A-Za-z0-9._:\-\/]{1,100}$/', $id ) && ! in_array( $id, array_column( $list, 'id' ), true ) ) {
					$list[] = array(
						'id'      => $id,
						'label'   => '' !== trim( (string) ( $m['label'] ?? '' ) ) ? wp_strip_all_tags( (string) $m['label'] ) : $id,
						'default' => ! empty( $m['default'] ),
					);
				}
			}
			$out[ $provider ] = $list;
		}
		return $out;
	}

	/**
	 * The model used by a provider.
	 *
	 * @param string $provider anthropic|openai|mock.
	 */
	public static function model( string $provider ): string {
		return match ( $provider ) {
			'anthropic' => (string) self::get( 'anthropic_model' ),
			'openai'    => (string) self::get( 'openai_model' ),
			default     => 'mock',
		};
	}

	/**
	 * The API key of a provider: the constant, else the stored encrypted key, else ''. The filter
	 * lets a test runner or a secrets manager hand the key in at runtime without writing it anywhere.
	 *
	 * @param string $provider anthropic|openai.
	 */
	public static function api_key( string $provider ): string {
		$key      = '';
		$constant = self::KEY_CONSTANTS[ $provider ] ?? '';
		if ( '' !== $constant && '' !== Config::string( $constant ) ) {
			$key = Config::string( $constant );
		} else {
			$key = self::decrypt( (string) self::get( $provider . '_key_enc' ) );
		}
		/**
		 * Filters the provider API key.
		 *
		 * @param string $key      Key, or ''.
		 * @param string $provider anthropic|openai.
		 */
		return (string) \TwentyOnePress\Sigma\Hooks::filter( 'api_key', $key, $provider );
	}

	/**
	 * What the admin screen may know about a key.
	 *
	 * @param string $provider anthropic|openai.
	 * @return array{set: bool, source: string, last4: string}
	 */
	public static function key_status( string $provider ): array {
		$constant = self::KEY_CONSTANTS[ $provider ] ?? '';
		if ( '' !== $constant && '' !== Config::string( $constant ) ) {
			return array(
				'set'    => true,
				'source' => 'constant',
				'last4'  => substr( Config::string( $constant ), -4 ),
			);
		}
		$enc = (string) self::get( $provider . '_key_enc' );
		if ( '' !== $enc && '' === self::decrypt( $enc ) ) {
			// Stored but not readable (the site's keys changed): never treated as set (§10b).
			return array(
				'set'    => false,
				'source' => 'unreadable',
				'last4'  => (string) self::get( $provider . '_key_last4' ),
			);
		}
		return array(
			'set'    => '' !== $enc,
			'source' => '' !== $enc ? 'settings' : 'none',
			'last4'  => '' !== $enc ? (string) self::get( $provider . '_key_last4' ) : '',
		);
	}

	/**
	 * What the admin screen may know about the Turnstile secret: set / not set / unreadable, last 4.
	 *
	 * @return array{set: bool, source: string, last4: string}
	 */
	public static function turnstile_status(): array {
		$constant = Config::string( 'TURNSTILE_SECRET' );
		if ( '' !== $constant ) {
			return array(
				'set'    => true,
				'source' => 'constant',
				'last4'  => substr( $constant, -4 ),
			);
		}
		$enc = (string) self::get( 'turnstile_secret_enc' );
		if ( '' !== $enc && '' === self::decrypt( $enc ) ) {
			return array(
				'set'    => false,
				'source' => 'unreadable',
				'last4'  => (string) self::get( 'turnstile_last4' ),
			);
		}
		return array(
			'set'    => '' !== $enc,
			'source' => '' !== $enc ? 'settings' : 'none',
			'last4'  => '' !== $enc ? (string) self::get( 'turnstile_last4' ) : '',
		);
	}

	/**
	 * The Turnstile secret: the constant, else the stored encrypted one. Never returned by REST.
	 */
	public static function turnstile_secret(): string {
		$constant = Config::string( 'TURNSTILE_SECRET' );
		return '' !== $constant ? $constant : self::decrypt( (string) self::get( 'turnstile_secret_enc' ) );
	}

	/**
	 * Whether Turnstile protects the visitor routes: on, with a site key and a readable secret.
	 */
	public static function turnstile_on(): bool {
		return (bool) self::get( 'turnstile_enabled' ) && '' !== (string) self::get( 'turnstile_site_key' ) && '' !== self::turnstile_secret();
	}

	/**
	 * Settings as the admin app sees them: no encrypted keys, key status instead.
	 *
	 * @return array<string, mixed>
	 */
	public static function to_public(): array {
		$all = self::all();
		foreach ( array_keys( self::KEY_CONSTANTS ) as $provider ) {
			unset( $all[ $provider . '_key_enc' ], $all[ $provider . '_key_last4' ] );
			$all[ $provider . '_key' ] = self::key_status( $provider );
		}
		unset( $all['turnstile_secret_enc'], $all['turnstile_last4'] );
		$all['turnstile_secret']        = self::turnstile_status();
		$all['support_email_effective'] = self::support_email();
		$all['killed_by_constant']      = (bool) Config::get( 'DISABLED' );
		$all['mock_allowed']            = self::provider_allowed( 'mock' );
		$all['text_defaults']           = self::text_defaults();
		$all['models']                  = self::models();
		unset( $all['smtp_password_enc'] );
		$smtp_enc             = (string) self::get( 'smtp_password_enc' );
		$all['smtp_password'] = array(
			'set'    => '' !== self::smtp_password(),
			'source' => '' !== self::smtp_password_constant() ? 'constant' : ( '' === $smtp_enc ? 'none' : ( '' === self::decrypt( $smtp_enc ) ? 'unreadable' : 'settings' ) ),
		);
		$all['mail_warnings'] = self::mail_warnings( self::all() );
		return $all;
	}

	/**
	 * Validate and save a partial update. Unknown keys are ignored; a bad value is an error and
	 * nothing is saved.
	 *
	 * @param array<string, mixed> $input Fields to change. `anthropic_key` / `openai_key`: a new key,
	 *                                    or '' to remove the stored one.
	 * @return array<string, mixed>|\WP_Error The saved settings, or the first error.
	 */
	public static function update( array $input ) {
		$clean = self::sanitize( $input, self::all() );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		update_option( self::OPTION, $clean, true );
		self::flush();
		return self::all();
	}

	/**
	 * Validation: pure apart from the key encryption, so the unit layer tests it.
	 *
	 * @param array<string, mixed> $input   Fields to change.
	 * @param array<string, mixed> $current Current settings.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function sanitize( array $input, array $current ) {
		$out = array_merge( self::defaults(), $current );

		// §7.7: the editable instructions. Plain text (never rendered), capped; the default is stored as ''.
		if ( array_key_exists( 'instructions', $input ) ) {
			$instructions = self::clean_instructions( (string) $input['instructions'] );
			if ( is_wp_error( $instructions ) ) {
				return $instructions;
			}
			$out['instructions'] = $instructions;
		}

		foreach ( array( 'enabled', 'admins_only', 'ai_enabled', 'human_enabled', 'delete_on_uninstall', 'smtp_auth', 'team_avatars', 'teaser_enabled', 'launcher_pulse', 'voice_input', 'turnstile_enabled' ) as $flag ) {
			if ( array_key_exists( $flag, $input ) ) {
				$out[ $flag ] = (bool) filter_var( $input[ $flag ], FILTER_VALIDATE_BOOLEAN );
			}
		}

		if ( array_key_exists( 'provider', $input ) ) {
			$provider = sanitize_key( (string) $input['provider'] );
			if ( ! self::provider_allowed( $provider ) ) {
				return self::error( 'provider', __( 'Unknown AI provider.', 'sigma' ) );
			}
			$out['provider'] = $provider;
		}

		foreach ( array( 'anthropic_model', 'openai_model' ) as $model ) {
			if ( array_key_exists( $model, $input ) ) {
				$value = trim( (string) $input[ $model ] );
				// The list in Settings (plan v1.17). An unchanged stored value is kept even when it is not in the
				// list (an older or custom id), so saving another field never changes the model silently.
				$listed = in_array( $value, self::model_ids( 'anthropic_model' === $model ? 'anthropic' : 'openai' ), true );
				if ( '' === $value ) {
					return self::error( $model, __( 'Choose a model.', 'sigma' ) );
				}
				if ( ! $listed && (string) ( $current[ $model ] ?? '' ) !== $value ) {
					/* translators: %s: a model id. */
					return self::error( $model, sprintf( __( '%s is not one of the models in the list.', 'sigma' ), $value ) );
				}
				$out[ $model ] = $value;
			}
		}

		if ( array_key_exists( 'sources', $input ) ) {
			$sources = array();
			foreach ( (array) $input['sources'] as $type ) {
				$type = sanitize_key( (string) $type );
				// Only types visitors can view (§10b): never private or admin-only content.
				if ( '' !== $type && self::source_allowed( $type ) ) {
					$sources[] = $type;
				}
			}
			$out['sources'] = array_values( array_unique( $sources ) );
		}

		$ints = array(
			'context_budget'   => array( 1000, 100000 ),
			'retrieval_budget' => array( 5000, 40000 ),
			'retention_days'   => array( 1, 3650 ),
			'offset_x'         => array( 0, 200 ),
			'offset_y'         => array( 0, 200 ),
			'smtp_port'        => array( 1, 65535 ),
		);
		foreach ( $ints as $name => [ $min, $max ] ) {
			if ( array_key_exists( $name, $input ) ) {
				$value = filter_var( $input[ $name ], FILTER_VALIDATE_INT );
				if ( false === $value || $value < $min || $value > $max ) {
					/* translators: 1: setting name, 2: minimum, 3: maximum. */
					return self::error( $name, sprintf( __( '%1$s must be a whole number from %2$d to %3$d.', 'sigma' ), $name, $min, $max ) );
				}
				$out[ $name ] = $value;
			}
		}

		if ( array_key_exists( 'support_email', $input ) ) {
			$email = trim( (string) $input['support_email'] );
			if ( '' !== $email && ! is_email( $email ) ) {
				return self::error( 'support_email', __( 'The support email is not a valid address.', 'sigma' ) );
			}
			$out['support_email'] = '' === $email ? '' : sanitize_email( $email );
		}

		foreach ( array_merge( array( 'brand_name' => 32 ), self::TEXTS ) as $name => $max ) {
			if ( array_key_exists( $name, $input ) ) {
				$value = sanitize_text_field( (string) $input[ $name ] );
				if ( mb_strlen( $value ) > $max ) {
					/* translators: 1: setting name, 2: maximum characters. */
					return self::error( $name, sprintf( __( '%1$s can be at most %2$d characters.', 'sigma' ), $name, $max ) );
				}
				$out[ $name ] = $value;
			}
		}

		if ( array_key_exists( 'bot_avatar', $input ) ) {
			$attachment = absint( $input['bot_avatar'] );
			if ( $attachment > 0 && ! wp_attachment_is_image( $attachment ) ) {
				return self::error( 'bot_avatar', __( 'The bot avatar must be an image from the Media Library.', 'sigma' ) );
			}
			$out['bot_avatar'] = $attachment;
		}

		foreach ( array( 'color_light', 'color_dark' ) as $name ) {
			if ( array_key_exists( $name, $input ) ) {
				$color = strtolower( trim( (string) $input[ $name ] ) );
				if ( ! preg_match( '/^#[0-9a-f]{6}$/', $color ) ) {
					return self::error( $name, __( 'A color is a hex value like #26292a.', 'sigma' ) );
				}
				$out[ $name ] = $color;
			}
		}

		// Panel palette (#41): a hex colour, or '' for Delta's own colour.
		foreach ( array( 'color_bg_light', 'color_bg_dark', 'color_text_light', 'color_text_dark' ) as $name ) {
			if ( array_key_exists( $name, $input ) ) {
				$color = strtolower( trim( (string) $input[ $name ] ) );
				if ( '' !== $color && ! preg_match( '/^#[0-9a-f]{6}$/', $color ) ) {
					return self::error( $name, __( 'A color is a hex value like #26292a.', 'sigma' ) );
				}
				$out[ $name ] = $color;
			}
		}

		$choices = array(
			'scheme'          => array( 'auto', 'light', 'dark' ),
			'position'        => array( 'right', 'left' ),
			'font'            => array( 'inherit', 'system' ),
			'ip_source'       => array( 'auto', 'remote_addr', 'cloudflare' ),
			'mail_transport'  => array( 'php', 'smtp' ),
			'smtp_encryption' => array( 'none', 'ssl', 'starttls' ),
			'launcher_icon'   => array( 'assistant', 'remy', 'chat', 'sparkles', 'help', 'image' ), // Remy's icon (§8.8).
		);
		foreach ( $choices as $name => $allowed ) {
			if ( array_key_exists( $name, $input ) ) {
				$value = (string) $input[ $name ];
				if ( ! in_array( $value, $allowed, true ) ) {
					/* translators: 1: setting name, 2: allowed values. */
					return self::error( $name, sprintf( __( '%1$s must be one of: %2$s.', 'sigma' ), $name, implode( ', ', $allowed ) ) );
				}
				$out[ $name ] = $value;
			}
		}

		// Remy's icon `image` is the uploaded bot avatar (§8.8): without one, the choice is refused.
		if ( ( array_key_exists( 'launcher_icon', $input ) || array_key_exists( 'bot_avatar', $input ) ) && 'image' === $out['launcher_icon'] && (int) $out['bot_avatar'] <= 0 ) {
			return self::error( 'launcher_icon', __( 'Upload an image for the bot icon, or choose one of the built-in icons.', 'sigma' ) );
		}

		foreach ( array( 'pricing_url', 'privacy_url', 'logo_url' ) as $name ) {
			if ( array_key_exists( $name, $input ) ) {
				$url = trim( (string) $input[ $name ] );
				if ( '' !== $url && ! wp_http_validate_url( $url ) ) {
					return self::error( $name, __( 'Enter a full http(s) URL.', 'sigma' ) );
				}
				$out[ $name ] = '' === $url ? '' : esc_url_raw( $url );
			}
		}

		if ( array_key_exists( 'bot_name', $input ) ) {
			// Plain text, 30 characters (§8.5); empty puts the default back.
			$bot = trim( sanitize_text_field( (string) $input['bot_name'] ) );
			if ( mb_strlen( $bot ) > 30 ) {
				return self::error( 'bot_name', __( 'The bot name can be at most 30 characters.', 'sigma' ) );
			}
			$out['bot_name'] = '' === $bot ? 'Sigma' : $bot;
		}
		if ( array_key_exists( 'team', $input ) ) {
			// Ordered, unique user ids of Authors and above (§8.4); others are dropped.
			$ids = array();
			foreach ( (array) $input['team'] as $id ) {
				$id = absint( $id );
				if ( $id > 0 && ! in_array( $id, $ids, true ) && user_can( $id, 'publish_posts' ) ) {
					$ids[] = $id;
				}
			}
			$out['team'] = array_slice( $ids, 0, 50 );
		}
		if ( array_key_exists( 'smtp_host', $input ) ) {
			$host = strtolower( trim( (string) $input['smtp_host'] ) );
			if ( '' !== $host && ! self::valid_host( $host ) ) {
				return self::error( 'smtp_host', __( 'Enter a host name like smtp.example.com, or an IP address.', 'sigma' ) );
			}
			$out['smtp_host'] = $host;
		}
		if ( array_key_exists( 'smtp_username', $input ) ) {
			$out['smtp_username'] = mb_substr( trim( sanitize_text_field( (string) $input['smtp_username'] ) ), 0, 190 );
		}
		if ( array_key_exists( 'mail_from_email', $input ) ) {
			$from = trim( (string) $input['mail_from_email'] );
			if ( '' !== $from && ! is_email( $from ) ) {
				return self::error( 'mail_from_email', __( 'The From email is not a valid address.', 'sigma' ) );
			}
			$out['mail_from_email'] = '' === $from ? '' : sanitize_email( $from );
		}
		if ( array_key_exists( 'mail_from_name', $input ) ) {
			$out['mail_from_name'] = mb_substr( trim( (string) preg_replace( '/[\x00-\x1F\x7F"<>]+/u', '', sanitize_text_field( (string) $input['mail_from_name'] ) ) ), 0, 100 );
		}
		// A new destination never reuses the stored password (§10b): an admin could otherwise point the
		// plugin at their own server and read the password. A new one must come with the change.
		$moved = false;
		foreach ( array( 'smtp_host', 'smtp_port', 'smtp_username', 'smtp_encryption' ) as $field ) {
			if ( (string) ( $current[ $field ] ?? '' ) !== (string) $out[ $field ] ) {
				$moved = true;
			}
		}
		$new_password = array_key_exists( 'smtp_password', $input ) && null !== $input['smtp_password'];
		if ( $moved && ! $new_password ) {
			$out['smtp_password_enc'] = '';
		}
		if ( $new_password ) {
			$password = (string) $input['smtp_password'];
			if ( strlen( $password ) > 500 || preg_match( '/[\r\n]/', $password ) ) {
				return self::error( 'smtp_password', __( 'That does not look like a password.', 'sigma' ) );
			}
			$out['smtp_password_enc'] = '' === $password ? '' : self::encrypt( $password );
		}
		// What SMTP needs to work at all, checked when it is the chosen transport (§10a check 1).
		if ( 'smtp' === $out['mail_transport'] ) {
			if ( '' === $out['smtp_host'] ) {
				return self::error( 'smtp_host', __( 'SMTP needs a host.', 'sigma' ) );
			}
			if ( '' === $out['mail_from_email'] ) {
				return self::error( 'mail_from_email', __( 'SMTP needs a From email the server is allowed to send as.', 'sigma' ) );
			}
			if ( $out['smtp_auth'] && '' === $out['smtp_username'] ) {
				return self::error( 'smtp_username', __( 'With authentication on, enter the SMTP username.', 'sigma' ) );
			}
			if ( $out['smtp_auth'] && '' === $out['smtp_password_enc'] && '' === self::smtp_password_constant( (string) $out['smtp_host'] ) ) {
				return self::error( 'smtp_password', $moved ? __( 'The SMTP server or account changed: enter the password again.', 'sigma' ) : __( 'With authentication on, enter the SMTP password (or define SIGMA_SMTP_PASSWORD with SIGMA_SMTP_HOST).', 'sigma' ) );
			}
			if ( $out['smtp_auth'] && 'none' === $out['smtp_encryption'] && ! self::is_loopback( (string) $out['smtp_host'] ) ) {
				return self::error( 'smtp_encryption', __( 'A password is never sent without encryption. Choose STARTTLS or SSL, or switch authentication off.', 'sigma' ) );
			}
		}

		if ( array_key_exists( 'turnstile_site_key', $input ) ) {
			$site_key = trim( (string) $input['turnstile_site_key'] );
			if ( '' !== $site_key && ! self::valid_turnstile_site_key( $site_key ) ) {
				return self::error( 'turnstile_site_key', __( 'A Turnstile site key starts with 0x (or 1x–3x for Cloudflare\'s test keys) and is 20 to 40 letters and digits.', 'sigma' ) );
			}
			$out['turnstile_site_key'] = $site_key;
		}
		if ( array_key_exists( 'turnstile_secret', $input ) && null !== $input['turnstile_secret'] ) {
			$secret = trim( (string) $input['turnstile_secret'] );
			if ( '' !== $secret && ! preg_match( '/^[\x21-\x7e]{10,200}$/', $secret ) ) {
				return self::error( 'turnstile_secret', __( 'That does not look like a Turnstile secret key.', 'sigma' ) );
			}
			$out['turnstile_secret_enc'] = '' === $secret ? '' : self::encrypt( $secret );
			$out['turnstile_last4']      = '' === $secret ? '' : substr( $secret, -4 );
		}
		if ( array_key_exists( 'turnstile_appearance', $input ) ) {
			$look = (string) $input['turnstile_appearance'];
			if ( ! in_array( $look, array( 'auto', 'light', 'dark' ), true ) ) {
				return self::error( 'turnstile_appearance', __( 'Appearance is Auto, Light or Dark.', 'sigma' ) );
			}
			$out['turnstile_appearance'] = $look;
		}
		if ( $out['turnstile_enabled'] ) {
			$has_secret = '' !== $out['turnstile_secret_enc'] || '' !== Config::string( 'TURNSTILE_SECRET' );
			if ( '' === $out['turnstile_site_key'] || ! $has_secret ) {
				return self::error( 'turnstile_enabled', __( 'Add the Turnstile site key and secret key, and test them, before switching it on.', 'sigma' ) );
			}
		}

		foreach ( array_keys( self::KEY_CONSTANTS ) as $provider ) {
			$field = $provider . '_key';
			if ( ! array_key_exists( $field, $input ) || null === $input[ $field ] ) {
				continue;
			}
			$key = trim( (string) $input[ $field ] );
			if ( '' !== $key && ! preg_match( '/^[\x21-\x7e]{8,300}$/', $key ) ) {
				return self::error( $field, __( 'That does not look like an API key.', 'sigma' ) );
			}
			$out[ $provider . '_key_enc' ]   = '' === $key ? '' : self::encrypt( $key );
			$out[ $provider . '_key_last4' ] = '' === $key ? '' : substr( $key, -4 );
		}

		return array_intersect_key( $out, self::defaults() );
	}

	/**
	 * A host name (RFC 1123 labels) or an IP address. Pure.
	 *
	 * @param string $host Host.
	 */
	public static function valid_host( string $host ): bool {
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return true;
		}
		return (bool) preg_match( '/^(?=.{1,253}$)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/i', $host );
	}

	/**
	 * The SMTP password from wp-config.php, used only for the host it was defined for (§10b): with
	 * SIGMA_SMTP_HOST (or the old DELTA_ASSISTANT_SMTP_HOST) matching the saved host, so changing the host in Settings can never
	 * send the constant's password somewhere else.
	 *
	 * @param string|null $host The SMTP host (default: the saved one).
	 */
	private static function smtp_password_constant( ?string $host = null ): string {
		if ( ! Config::has( 'SMTP_PASSWORD' ) || ! Config::has( 'SMTP_HOST' ) ) {
			return '';
		}
		$host = strtolower( trim( $host ?? (string) self::get( 'smtp_host' ) ) );
		return '' !== $host && strtolower( trim( Config::string( 'SMTP_HOST' ) ) ) === $host ? Config::string( 'SMTP_PASSWORD' ) : '';
	}

	/**
	 * Whether a host is this machine (localhost, *.localhost, 127.0.0.0/8, ::1). Pure.
	 *
	 * @param string $host Host.
	 */
	public static function is_loopback( string $host ): bool {
		$host = strtolower( trim( $host, " []\t" ) );
		return 'localhost' === $host || str_ends_with( $host, '.localhost' ) || '::1' === $host || 1 === preg_match( '/^127\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $host );
	}

	/**
	 * A Turnstile site key: 0x then letters, digits, dashes or underscores, 20 to 40 characters in all.
	 * Cloudflare's public test keys (1x…, 2x…, 3x…) pass too, so a local site can try it without real keys. Pure.
	 *
	 * @param string $key Site key.
	 */
	public static function valid_turnstile_site_key( string $key ): bool {
		return 1 === preg_match( '/^[0-3]x[A-Za-z0-9_-]{18,38}$/', $key );
	}

	/**
	 * Whether a post type may be a knowledge source: one visitors can view (§10b).
	 *
	 * @param string $type Post type.
	 */
	public static function source_allowed( string $type ): bool {
		return function_exists( 'is_post_type_viewable' ) ? (bool) is_post_type_viewable( $type ) : true;
	}

	/**
	 * The SMTP password: the constant, else the stored encrypted one. Never returned by REST.
	 */
	public static function smtp_password(): string {
		$constant = self::smtp_password_constant();
		return '' !== $constant ? $constant : self::decrypt( (string) self::get( 'smtp_password_enc' ) );
	}

	/**
	 * Warnings that do not block a save (§10a checks 1 and 4): port and encryption that rarely go
	 * together, and a From domain that SPF/DKIM/DMARC may reject.
	 *
	 * @param array<string, mixed> $s Settings.
	 * @return list<string>
	 */
	public static function mail_warnings( array $s ): array {
		$warnings = array();
		if ( 'smtp' !== ( $s['mail_transport'] ?? '' ) ) {
			return $warnings;
		}
		$port = (int) $s['smtp_port'];
		$enc  = (string) $s['smtp_encryption'];
		if ( 465 === $port && 'ssl' !== $enc ) {
			$warnings[] = __( 'Port 465 usually means SSL (implicit TLS).', 'sigma' );
		}
		if ( 587 === $port && 'starttls' !== $enc ) {
			$warnings[] = __( 'Port 587 usually means STARTTLS.', 'sigma' );
		}
		if ( 25 === $port ) {
			$warnings[] = __( 'Port 25 is blocked by many hosts and cloud providers. Use 587 with STARTTLS.', 'sigma' );
		}
		if ( class_exists( Mail\Transport::class ) && ! Mail\Transport::wp_mail_is_core() ) {
			$warnings[] = __( 'Another plugin replaces wp_mail(), so the built-in SMTP may be skipped. Use Send test email to see which path works, or switch to WordPress mail.', 'sigma' );
		}
		if ( 'none' === $enc && ! empty( $s['smtp_auth'] ) ) {
			$warnings[] = __( 'Without encryption the password crosses the network in the clear.', 'sigma' );
		}
		$from_domain = self::domain_of( (string) $s['mail_from_email'] );
		if ( '' !== $from_domain ) {
			$site_domain = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
			$user_domain = self::domain_of( (string) $s['smtp_username'] );
			if ( ! self::same_domain( $from_domain, $site_domain ) && ! self::same_domain( $from_domain, $user_domain ) ) {
				/* translators: %s: From domain. */
				$warnings[] = sprintf( __( 'The From domain (%s) is neither the site\'s domain nor the SMTP account\'s. Unless its SPF and DKIM records allow this server, receivers may reject the email or file it as spam (DMARC).', 'sigma' ), $from_domain );
			}
		}
		return $warnings;
	}

	/**
	 * Domain part of an email address, lowercase; '' when there is none.
	 *
	 * @param string $email Email.
	 */
	private static function domain_of( string $email ): string {
		$at = strrpos( $email, '@' );
		return false === $at ? '' : strtolower( substr( $email, $at + 1 ) );
	}

	/**
	 * Whether two domains are the same or one is a subdomain of the other.
	 *
	 * @param string $a Domain.
	 * @param string $b Domain.
	 */
	private static function same_domain( string $a, string $b ): bool {
		return '' !== $b && ( $a === $b || str_ends_with( $a, '.' . $b ) || str_ends_with( $b, '.' . $a ) );
	}

	/**
	 * Encrypt a key for storage. Format: base64( nonce . ciphertext ).
	 *
	 * @param string $plain Key.
	 */
	public static function encrypt( string $plain ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::secret() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary ciphertext in an option.
	}

	/**
	 * Decrypt a stored key. '' when it is empty or cannot be read (AUTH_KEY changed).
	 *
	 * @param string $stored Stored value.
	 */
	public static function decrypt( string $stored ): string {
		if ( '' === $stored ) {
			return '';
		}
		$raw = base64_decode( $stored, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- See encrypt().
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$box   = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain = sodium_crypto_secretbox_open( $box, $nonce, self::secret() );
		if ( false === $plain ) {
			// Encrypted by 0.1.6 or older on a site without the AUTH_KEY/AUTH_SALT constants (§10b migration).
			$plain = sodium_crypto_secretbox_open( $box, $nonce, self::secret( true ) );
		}
		return false === $plain ? '' : $plain;
	}

	/**
	 * Encrypt every stored secret again under the current key (0.1.7 migration). Values that cannot be
	 * read stay as they are and keep showing "unreadable".
	 */
	public static function rekey(): void {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return;
		}
		$changed = false;
		foreach ( array( 'anthropic_key_enc', 'openai_key_enc', 'smtp_password_enc', 'turnstile_secret_enc' ) as $field ) {
			$plain = empty( $stored[ $field ] ) ? '' : self::decrypt( (string) $stored[ $field ] );
			if ( '' !== $plain ) {
				$stored[ $field ] = self::encrypt( $plain );
				$changed          = true;
			}
		}
		if ( $changed ) {
			update_option( self::OPTION, $stored, true );
			self::flush();
		}
	}

	/**
	 * The encryption key, derived from wp_salt( 'auth' ) (§10b), never stored. wp_salt() uses the
	 * AUTH_KEY and AUTH_SALT constants when they are set, and keys kept in the database otherwise.
	 *
	 * @param bool $legacy The 0.1.6 derivation (the raw constants, empty when undefined).
	 */
	private static function secret( bool $legacy = false ): string {
		if ( ! $legacy && function_exists( 'wp_salt' ) ) {
			$material = (string) wp_salt( 'auth' );
		} else {
			$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'AUTH_SALT' ) ? AUTH_SALT : '' );
		}
		return sodium_crypto_generichash( 'delta-assistant-keys|' . $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * A validation error for REST.
	 *
	 * @param string $field   Field.
	 * @param string $message Message.
	 */
	private static function error( string $field, string $message ): \WP_Error {
		return new \WP_Error(
			'invalid_setting',
			$message,
			array(
				'status' => 400,
				'field'  => $field,
			)
		);
	}
}
