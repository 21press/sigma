<?php
/**
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Tests\Integration;

use TwentyOnePress\Sigma\Admin\Admin_Page;
use TwentyOnePress\Sigma\Tests\Support\Integration_Test_Case;
use TwentyOnePress\Sigma\Widget;
use TwentyOnePress\Sigma\Widget_Strings;

/**
 * Translations (§19.3 item 3): the chat box's strings travel as SigmaWidget.i18n, only those that differ from English;
 * the admin app loads wp-i18n and its JSON translations; the widget strings file is in sync with the widget.
 *
 * @covers \TwentyOnePress\Sigma\Widget_Strings
 * @covers \TwentyOnePress\Sigma\Widget
 * @covers \TwentyOnePress\Sigma\Admin\Admin_Page
 */
final class Translations_Test extends Integration_Test_Case {

	/** A test translation through WordPress's own gettext filter, as a loaded .mo would give. */
	private static function translate_some(): void {
		add_filter(
			'gettext_sigma',
			static fn( string $translation, string $text ): string => array(
				'Ask a question' => 'Stellen Sie eine Frage',
				'Talk to %s'     => 'Mit %s sprechen',
				'Open help'      => 'Hilfe öffnen',
			)[ $text ] ?? $translation,
			10,
			2
		);
	}

	public function test_on_an_english_site_nothing_is_sent(): void {
		$this->assertSame( array(), Widget_Strings::translated() );
		$this->assertArrayHasKey( 'Ask a question', Widget_Strings::all() );
		$this->assertGreaterThan( 50, count( Widget_Strings::all() ), 'every t( … ) string of the widget' );
	}

	public function test_only_the_translated_strings_are_sent(): void {
		self::translate_some();
		$this->assertSame(
			array(
				'Ask a question' => 'Stellen Sie eine Frage',
				'Open help'      => 'Hilfe öffnen',
				'Talk to %s'     => 'Mit %s sprechen',
			),
			Widget_Strings::translated()
		);
	}

	public function test_the_inline_data_carries_the_translations(): void {
		if ( ! is_readable( P21_SIGMA_DIR . '/' . Widget::script_path() ) ) {
			$this->markTestSkipped( 'The inline data needs the built widget (npm run build:widget).' );
		}
		wp_set_current_user( 0 );
		wp_scripts()->registered = array();
		Widget::enqueue();
		$english = implode( '', (array) wp_scripts()->get_data( Widget::HANDLE, 'before' ) );
		$this->assertStringNotContainsString( '"i18n"', $english, 'nothing to send on an English site' );

		self::translate_some();
		wp_scripts()->registered = array();
		Widget::enqueue();
		$inline = implode( '', (array) wp_scripts()->get_data( Widget::HANDLE, 'before' ) );
		$this->assertStringContainsString( '"i18n":{', $inline );
		$this->assertStringContainsString( '"Ask a question":"Stellen Sie eine Frage"', $inline );
	}

	public function test_the_admin_app_loads_wp_i18n_and_its_translations(): void {
		if ( ! is_readable( P21_SIGMA_DIR . '/build/admin/admin.js' ) ) {
			$this->markTestSkipped( 'Needs the built admin app (npm run build:admin).' );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Admin_Page::menu();
		wp_scripts()->registered = array();
		Admin_Page::enqueue( 'toplevel_page_' . Admin_Page::SLUG );
		$script = wp_scripts()->registered['sigma-admin'] ?? null;
		$this->assertNotNull( $script );
		$this->assertContains( 'wp-i18n', $script->deps );
		$this->assertSame( 'sigma', $script->textdomain );
		$this->assertSame( P21_SIGMA_DIR . '/languages', $script->translations_path );
	}

	public function test_the_widget_strings_file_matches_the_widget_source(): void {
		if ( ! is_dir( P21_SIGMA_DIR . '/apps/widget/src' ) ) {
			$this->markTestSkipped( 'Needs the source tree.' );
		}
		exec( 'node ' . escapeshellarg( P21_SIGMA_DIR . '/scripts/widget-strings.mjs' ) . ' --check 2>&1', $out, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Test only.
		$this->assertSame( 0, $code, implode( "\n", $out ) );
	}
}
