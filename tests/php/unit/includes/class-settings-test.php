<?php
/**
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Tests\Unit;

use Brain\Monkey\Functions;
use TwentyOnePress\Sigma\Settings;
use TwentyOnePress\Sigma\Tests\Support\Source;
use TwentyOnePress\Sigma\Tests\Support\Unit_Test_Case;

/**
 * @covers \TwentyOnePress\Sigma\Settings
 */
final class Settings_Test extends Unit_Test_Case {

	protected function set_up(): void {
		parent::set_up();
		Source::load( 'includes/class-settings.php' );
		Functions\when( 'is_email' )->alias( static fn( $e ) => false !== filter_var( $e, FILTER_VALIDATE_EMAIL ) ? $e : false );
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'wp_http_validate_url' )->alias( static fn( $u ) => preg_match( '#^https?://[^/]+#', (string) $u ) ? $u : false );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
	}

	private function sanitize( array $input ) {
		return Settings::sanitize( $input, Settings::defaults() );
	}

	public function test_a_fresh_site_has_no_sources_so_the_ai_waits_to_be_set_up(): void {
		$this->assertSame( array(), Settings::defaults()['sources'] );
		$this->assertTrue( Settings::defaults()['admins_only'], 'a new install shows the bubble to admins only' );
	}

	public function test_bad_values_are_refused_with_the_field_named(): void {
		foreach ( array(
			array( 'support_email', 'not an email' ),
			array( 'color_light', 'red' ),
			array( 'color_bg_light', 'navy' ),
			array( 'color_text_dark', '#fff' ),
			array( 'launcher_icon', 'rocket' ),
			array( 'position', 'top' ),
			array( 'smtp_port', 70000 ),
			array( 'smtp_host', 'not a host!' ),
			array( 'smtp_encryption', 'tls' ),
			array( 'mail_from_email', 'nope' ),
			array( 'retention_days', 0 ),
			array( 'context_budget', 150000 ),
			array( 'retrieval_budget', 60000 ),
			array( 'pricing_url', 'javascript:alert(1)' ),
			array( 'greeting', str_repeat( 'x', 61 ) ),
			array( 'anthropic_model', 'bad model id' ),
			array( 'anthropic_model', 'claude-made-up-9' ),
			array( 'openai_model', 'claude-haiku-5-5' ),
		) as [ $field, $value ] ) {
			$result = $this->sanitize( array( $field => $value ) );
			$this->assertInstanceOf( \WP_Error::class, $result, "{$field} refuses " . wp_json_encode( $value ) );
			$this->assertSame( $field, $result->get_error_data()['field'] );
		}
	}

	public function test_the_model_lists_are_curated_with_one_default_each(): void {
		$models = Settings::models();
		$this->assertSame( array( 'claude-haiku-5-5', 'claude-sonnet-5-5', 'claude-opus-5-5' ), array_column( $models['anthropic'], 'id' ) );
		$this->assertSame( array( 'gpt-6-luna', 'gpt-6.1-terra', 'gpt-6.2-sol', 'gpt-6.2-astra' ), array_column( $models['openai'], 'id' ) );
		foreach ( array( 'anthropic', 'openai' ) as $provider ) {
			$defaults = array_values( array_filter( $models[ $provider ], static fn( array $m ): bool => $m['default'] ) );
			$this->assertCount( 1, $defaults, "{$provider}: one default" );
			$this->assertSame( Settings::defaults()[ $provider . '_model' ], $defaults[0]['id'], "{$provider}: the list default is the settings default" );
			foreach ( $models[ $provider ] as $m ) {
				$this->assertNotSame( '', $m['label'] );
			}
		}
		$this->assertStringContainsString( 'lowest cost', $models['anthropic'][0]['label'] );
		$this->assertStringContainsString( 'most capable', end( $models['openai'] )['label'] );
	}

	public function test_a_listed_model_is_accepted_and_an_unchanged_legacy_value_is_kept(): void {
		$this->assertSame( 'claude-opus-5-5', $this->sanitize( array( 'anthropic_model' => 'claude-opus-5-5' ) )['anthropic_model'] );
		$this->assertSame( 'gpt-6.2-astra', $this->sanitize( array( 'openai_model' => ' gpt-6.2-astra ' ) )['openai_model'] );

		$current = array( 'anthropic_model' => 'claude-sonnet-4-old' ) + Settings::defaults();
		$kept    = Settings::sanitize( array( 'anthropic_model' => 'claude-sonnet-4-old', 'bot_name' => 'Ada' ), $current );
		$this->assertIsArray( $kept, 'saving another field with an old model stored is fine' );
		$this->assertSame( 'claude-sonnet-4-old', $kept['anthropic_model'], 'not changed silently' );

		$refused = Settings::sanitize( array( 'anthropic_model' => 'claude-other-old' ), $current );
		$this->assertInstanceOf( \WP_Error::class, $refused, 'a new unlisted id is refused' );
		$this->assertSame( 'anthropic_model', $refused->get_error_data()['field'] );
		$this->assertStringContainsString( 'claude-other-old', $refused->get_error_message() );

		$this->assertSame( 'claude-haiku-5-5', Settings::sanitize( array( 'anthropic_model' => 'claude-haiku-5-5' ), $current )['anthropic_model'], 'moving from the old id to a listed one works' );

		$empty = Settings::sanitize( array( 'openai_model' => '  ' ), $current );
		$this->assertInstanceOf( \WP_Error::class, $empty );
		$this->assertSame( array( 'openai_model', 'Choose a model.' ), array( $empty->get_error_data()['field'], $empty->get_error_message() ) );
	}

	public function test_the_model_list_is_filterable_and_cleaned(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) {
				if ( 'sigma_models' !== $hook ) {
					return $value;
				}
				$value['anthropic'][] = array( 'id' => 'claude-custom-1', 'label' => '<b>Custom</b>' );
				$value['anthropic'][] = array( 'id' => 'claude-custom-1', 'label' => 'Duplicate' );
				$value['anthropic'][] = array( 'id' => 'bad id with spaces' );
				$value['openai']      = array( array( 'id' => 'gpt-proxy' ) );
				return $value;
			}
		);
		$models = Settings::models();
		$custom = end( $models['anthropic'] );
		$this->assertSame( array( 'claude-custom-1', 'Custom', false ), array( $custom['id'], $custom['label'], $custom['default'] ), 'added, tags stripped, deduplicated' );
		$this->assertNotContains( 'bad id with spaces', array_column( $models['anthropic'], 'id' ) );
		$this->assertSame( array( array( 'id' => 'gpt-proxy', 'label' => 'gpt-proxy', 'default' => false ) ), $models['openai'], 'a missing label falls back to the id' );
		$this->assertSame( 'claude-custom-1', $this->sanitize( array( 'anthropic_model' => 'claude-custom-1' ) )['anthropic_model'], 'a filtered-in id is accepted' );
		$this->assertInstanceOf( \WP_Error::class, $this->sanitize( array( 'openai_model' => 'gpt-6.2-sol' ) ), 'the filter replaced the OpenAI list' );
	}

	public function test_smtp_needs_a_host_a_from_and_credentials_when_auth_is_on(): void {
		$base = array(
			'mail_transport'  => 'smtp',
			'smtp_host'       => 'smtp.example.com',
			'mail_from_email' => 'help@example.com',
			'smtp_username'   => 'help@example.com',
			'smtp_password'   => 'secret-smtp-pass',
		);
		$this->assertIsArray( $this->sanitize( $base ) );
		foreach ( array( 'smtp_host', 'mail_from_email', 'smtp_username', 'smtp_password' ) as $missing ) {
			$result = $this->sanitize( array( $missing => '' ) + $base );
			$this->assertInstanceOf( \WP_Error::class, $result, "smtp without {$missing}" );
			$this->assertSame( $missing, $result->get_error_data()['field'] );
		}
		$this->assertIsArray( $this->sanitize( array( 'smtp_auth' => false, 'smtp_username' => '', 'smtp_password' => '' ) + $base ), 'no credentials needed without auth' );
	}

	public function test_the_smtp_password_is_stored_encrypted_and_never_in_the_public_settings(): void {
		$out = $this->sanitize( array( 'smtp_password' => 'secret-smtp-pass' ) );
		$this->assertStringNotContainsString( 'secret-smtp-pass', (string) wp_json_encode( $out ) );
		$this->assertSame( 'secret-smtp-pass', Settings::decrypt( $out['smtp_password_enc'] ) );
	}

	public function test_port_and_encryption_hints_and_the_from_domain_warning(): void {
		Functions\when( 'home_url' )->justReturn( 'https://deltatheme.com/' );
		Functions\when( 'wp_parse_url' )->alias( static fn( $u, $c = -1 ) => parse_url( $u, $c ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		$s = array(
			'mail_transport'  => 'smtp',
			'smtp_port'       => 465,
			'smtp_encryption' => 'starttls',
			'smtp_auth'       => true,
			'smtp_username'   => 'apikey',
			'mail_from_email' => 'help@deltatheme.com',
		);
		$this->assertCount( 1, Settings::mail_warnings( $s ), '465 + starttls' );
		$this->assertCount( 0, Settings::mail_warnings( array( 'smtp_port' => 587 ) + $s ) );
		$this->assertCount( 1, Settings::mail_warnings( array( 'smtp_port' => 587, 'mail_from_email' => 'help@gmail.com' ) + $s ), 'From domain is neither the site nor the account' );
		$this->assertCount( 0, Settings::mail_warnings( array( 'smtp_port' => 587, 'mail_from_email' => 'help@mail.deltatheme.com' ) + $s ), 'a subdomain of the site is fine' );
		$this->assertSame( array(), Settings::mail_warnings( array( 'mail_transport' => 'php' ) + $s ) );
	}

	public function test_the_bot_name_is_plain_text_up_to_30_and_empty_means_remy(): void {
		$this->assertSame( 'Ada', $this->sanitize( array( 'bot_name' => '<b>Ada</b>' ) )['bot_name'] );
		$this->assertSame( 'Sigma', $this->sanitize( array( 'bot_name' => '  ' ) )['bot_name'] );
		$this->assertSame( 'Sigma', Settings::defaults()['bot_name'] );
		$this->assertInstanceOf( \WP_Error::class, $this->sanitize( array( 'bot_name' => str_repeat( 'x', 31 ) ) ) );
		$this->assertInstanceOf( \WP_Error::class, $this->sanitize( array( 'teaser_text' => str_repeat( 'x', 121 ) ) ), 'teaser max 120' );
		$this->assertSame( '👋 Hi there', $this->sanitize( array( 'teaser_text' => '👋 Hi there' ) )['teaser_text'], 'emoji allowed' );
		$this->assertFalse( $this->sanitize( array( 'launcher_pulse' => '0' ) )['launcher_pulse'] );
		$this->assertTrue( Settings::defaults()['teaser_enabled'] );
	}

	public function test_remy_and_home_texts_have_the_owners_defaults_and_caps(): void {
		$defaults = Settings::text_defaults();
		$this->assertSame( 'Talk to a human', $defaults['human_card_title'] );
		$this->assertSame( 'Talk to {bot_name}', $defaults['bot_card_title'] );
		$this->assertSame( 'Handles instant support', $defaults['bot_card_text'] );
		$this->assertSame( "Hi, I'm {bot_name}. Ask me anything about {product} and I'll answer from our docs.", $defaults['bot_intro'] );
		$this->assertSame( "{bot_name}'s answers come from our docs and collective knowledge of {product}. Chats are stored for {days} days for better user experience.", $defaults['privacy_text_ai'] );
		foreach ( array( 'human_card_title', 'bot_card_title', 'bot_card_text', 'bot_intro' ) as $key ) {
			$this->assertSame( '', Settings::defaults()[ $key ], "{$key}: empty means the default" );
		}
		$this->assertSame(
			array(
				'human_card_title' => 40,
				'bot_card_title'   => 40,
				'bot_card_text'    => 80,
				'bot_intro'        => 300,
			),
			array_intersect_key( Settings::TEXTS, array_flip( array( 'human_card_title', 'bot_card_title', 'bot_card_text', 'bot_intro' ) ) )
		);
		$this->assertSame( str_repeat( 'é', 300 ), $this->sanitize( array( 'bot_intro' => str_repeat( 'é', 300 ) ) )['bot_intro'], 'characters, not bytes' );
		$this->assertInstanceOf( \WP_Error::class, $this->sanitize( array( 'bot_intro' => str_repeat( 'x', 301 ) ) ) );
		$this->assertInstanceOf( \WP_Error::class, $this->sanitize( array( 'bot_card_text' => str_repeat( 'x', 81 ) ) ) );
		$this->assertInstanceOf( \WP_Error::class, $this->sanitize( array( 'human_card_title' => str_repeat( 'x', 41 ) ) ) );
	}

	public function test_continue_card_titles_have_the_owners_defaults_and_a_40_cap(): void {
		$defaults = Settings::text_defaults();
		$this->assertSame( 'Continue with {bot_name}', $defaults['continue_card_title'] );
		$this->assertSame( 'Continue with the team', $defaults['continue_human_title'] );
		foreach ( array( 'continue_card_title', 'continue_human_title' ) as $key ) {
			$this->assertSame( '', Settings::defaults()[ $key ], "{$key}: empty means the default" );
			$this->assertSame( 40, Settings::TEXTS[ $key ] );
			$this->assertSame( str_repeat( 'x', 40 ), $this->sanitize( array( $key => str_repeat( 'x', 40 ) ) )[ $key ] );
			$this->assertInstanceOf( \WP_Error::class, $this->sanitize( array( $key => str_repeat( 'x', 41 ) ) ) );
		}
	}

	public function test_the_bot_avatar_is_an_image_attachment_or_zero(): void {
		Functions\when( 'wp_attachment_is_image' )->alias( static fn( $id ): bool => 7 === (int) $id );
		$this->assertSame( 0, Settings::defaults()['bot_avatar'] );
		$this->assertSame( 7, $this->sanitize( array( 'bot_avatar' => '7' ) )['bot_avatar'] );
		$this->assertSame( 0, $this->sanitize( array( 'bot_avatar' => '' ) )['bot_avatar'], 'empty: the default SVG' );
		$this->assertInstanceOf( \WP_Error::class, $this->sanitize( array( 'bot_avatar' => 8 ) ), 'not an image' );
	}

	public function test_the_test_provider_is_refused_outside_local_sites(): void {
		$this->assertInstanceOf( \WP_Error::class, $this->sanitize( array( 'provider' => 'mock' ) ) );
	}

	public function test_a_context_budget_past_100k_is_refused(): void {
		$this->assertInstanceOf( \WP_Error::class, $this->sanitize( array( 'context_budget' => 100001 ) ), 'Haiku bills 5× past 100k (§7.4)' );
	}

	public function test_a_key_is_stored_encrypted_with_its_last_four_characters(): void {
		$out = $this->sanitize( array( 'anthropic_key' => 'sk-ant-test-1234567890-WXYZ' ) );
		$this->assertIsArray( $out );
		$this->assertStringNotContainsString( 'sk-ant', $out['anthropic_key_enc'], 'the key is not stored in clear' );
		$this->assertSame( 'WXYZ', $out['anthropic_key_last4'] );
		$this->assertSame( 'sk-ant-test-1234567890-WXYZ', Settings::decrypt( $out['anthropic_key_enc'] ) );
		$this->assertArrayNotHasKey( 'anthropic_key', $out, 'the clear key field is never saved' );
	}

	public function test_an_empty_key_removes_the_stored_one(): void {
		$out = Settings::sanitize( array( 'anthropic_key' => '' ), array( 'anthropic_key_enc' => 'x', 'anthropic_key_last4' => 'abcd' ) + Settings::defaults() );
		$this->assertSame( '', $out['anthropic_key_enc'] );
	}

	public function test_a_tampered_ciphertext_decrypts_to_nothing(): void {
		$enc = Settings::encrypt( 'secret-key-value' );
		$raw = base64_decode( $enc ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$raw[ strlen( $raw ) - 1 ] = chr( ord( $raw[ strlen( $raw ) - 1 ] ) ^ 1 );
		$this->assertSame( '', Settings::decrypt( base64_encode( $raw ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public function test_panel_colours_are_lowercase_hex_and_empty_means_deltas_own(): void {
		$this->assertSame( '', Settings::defaults()['color_bg_light'], 'a fresh site keeps Delta\'s panel colours' );
		$out = $this->sanitize( array( 'color_bg_light' => ' #0F2A44 ', 'color_text_light' => '#FFFFFF', 'color_bg_dark' => '' ) );
		$this->assertSame( '#0f2a44', $out['color_bg_light'] );
		$this->assertSame( '#ffffff', $out['color_text_light'] );
		$this->assertSame( '', $out['color_bg_dark'], 'empty resets to Delta\'s colour' );
	}

	public function test_remys_icon_is_one_of_six_and_the_plugin_mark_by_default(): void {
		$this->assertSame( 'assistant', Settings::defaults()['launcher_icon'] );
		foreach ( array( 'assistant', 'remy', 'chat', 'sparkles', 'help' ) as $icon ) {
			$this->assertSame( $icon, $this->sanitize( array( 'launcher_icon' => $icon ) )['launcher_icon'] );
		}
		$this->assertSame( 'image', Settings::sanitize( array( 'launcher_icon' => 'image' ), array( 'bot_avatar' => 12 ) + Settings::defaults() )['launcher_icon'], 'image with an uploaded avatar' );
	}

	public function test_image_without_an_uploaded_avatar_is_refused_with_the_field_named(): void {
		$error = $this->sanitize( array( 'launcher_icon' => 'image' ) );
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'launcher_icon', $error->get_error_data()['field'] );
		$cleared = Settings::sanitize(
			array( 'bot_avatar' => 0 ),
			array(
				'launcher_icon' => 'image',
				'bot_avatar'    => 12,
			) + Settings::defaults()
		);
		$this->assertInstanceOf( \WP_Error::class, $cleared, 'removing the image while it is the icon' );
		$this->assertSame( 'launcher_icon', $cleared->get_error_data()['field'] );
	}

	public function test_unknown_fields_are_ignored(): void {
		$out = $this->sanitize( array( 'is_admin' => true ) );
		$this->assertArrayNotHasKey( 'is_admin', $out );
	}
}
