<?php
/**
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Tests\Integration;

use TwentyOnePress\Sigma\AI\Answerer;
use TwentyOnePress\Sigma\Knowledge\Corpus;
use TwentyOnePress\Sigma\Knowledge\Knowledge_Base;
use TwentyOnePress\Sigma\Tests\Support\Integration_Test_Case;

/**
 * Security re-review (0.2.5, low): a deleted, drafted or edited knowledge entry stops being used at once, as the delete
 * dialog says, without waiting for WP-Cron to rebuild the corpus. WP-Cron never runs in these tests.
 *
 * @covers \TwentyOnePress\Sigma\Knowledge\Corpus::with_current_knowledge
 * @covers \TwentyOnePress\Sigma\Knowledge\Knowledge_Base::schedule_rebuild
 */
final class Knowledge_Freshness_Test extends Integration_Test_Case {

	/** The knowledge the next answer's prompt carries. */
	private static function next_prompt(): string {
		$corpus = Corpus::with_current_knowledge( Corpus::get() );
		$prompt = Answerer::prompt(
			$corpus,
			array(
				array(
					'role'    => 'user',
					'content' => 'What is the staff discount?',
				),
			),
			home_url( '/' )
		)['prompt'];
		return $prompt->cached . "\n" . $prompt->dynamic;
	}

	public function test_a_deleted_drafted_or_edited_entry_is_gone_from_the_very_next_prompt(): void {
		$keep   = Knowledge_Base::create( 'Opening hours', 'We answer Monday to Friday.', true );
		$secret = Knowledge_Base::create( 'Staff discount', 'The staff code is ZEBRA-42.', true );
		Corpus::build();
		$built = (string) Corpus::get()['knowledge_rev'];
		$this->assertStringContainsString( 'ZEBRA-42', self::next_prompt(), 'in use while active' );
		$this->assertSame( $built, Knowledge_Base::revision(), 'nothing changed: the built corpus is used as is' );

		wp_delete_post( $secret, true );
		$this->assertNotSame( $built, Knowledge_Base::revision() );
		$this->assertNotFalse( wp_next_scheduled( Corpus::CRON_REBUILD ), 'the rebuild is due now' );
		$this->assertLessThanOrEqual( time(), (int) wp_next_scheduled( Corpus::CRON_REBUILD ) );
		$this->assertStringContainsString( 'ZEBRA-42', (string) Corpus::get()['knowledge_text'], 'the stored corpus is still the old one (no cron here)' );
		$prompt = self::next_prompt();
		$this->assertStringNotContainsString( 'ZEBRA-42', $prompt, 'deleted: gone from the next answer at once' );
		$this->assertStringContainsString( 'Monday to Friday', $prompt, 'the other entries stay' );

		$draft = Knowledge_Base::create( 'Supplier', 'Our supplier is ACME-77.', true );
		Corpus::build();
		$this->assertStringContainsString( 'ACME-77', self::next_prompt() );
		wp_update_post(
			array(
				'ID'          => $draft,
				'post_status' => 'draft',
			)
		);
		$this->assertStringNotContainsString( 'ACME-77', self::next_prompt(), 'moved to draft: gone at once' );

		Corpus::build();
		wp_update_post(
			array(
				'ID'           => $keep,
				'post_content' => 'We answer Monday to Saturday.',
			)
		);
		$this->assertStringContainsString( 'Monday to Saturday', self::next_prompt(), 'edited: the new text at once' );
	}

	public function test_a_corpus_from_before_the_revision_existed_uses_the_current_entries(): void {
		Knowledge_Base::create( 'Returns', 'Returns within 30 days.', true );
		Corpus::build();
		$stored = get_option( Corpus::OPTION );
		unset( $stored['knowledge_rev'] );
		update_option( Corpus::OPTION, $stored, false );
		$this->assertStringContainsString( 'Returns within 30 days.', self::next_prompt() );
	}
}
