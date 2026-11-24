<?php
/**
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Tests\Integration;

use TwentyOnePress\Sigma\AI\Answerer;
use TwentyOnePress\Sigma\Knowledge\Corpus;
use TwentyOnePress\Sigma\Tests\Support\Integration_Test_Case;

/**
 * The corpus from real posts: what goes in, when it rebuilds, which mode it picks (§7.3, §7.4).
 *
 * @covers \TwentyOnePress\Sigma\Knowledge\Corpus
 * @covers \TwentyOnePress\Sigma\Knowledge\Extractor
 */
final class Corpus_Test extends Integration_Test_Case {

	public function test_only_published_public_posts_of_the_sources_go_in(): void {
		$this->doc( 'Public doc', '<p>Visible.</p>' );
		$this->doc( 'Draft doc', '<p>Draft.</p>', array( 'post_status' => 'draft' ) );
		$this->doc( 'Locked doc', '<p>Secret.</p>', array( 'post_password' => 'pw' ) );
		self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'A page',
			)
		);
		$this->settings( array( 'sources' => array( 'post' ) ) );
		$titles = array_column( Corpus::build()['docs'], 'title' );
		$this->assertSame( array( 'Public doc' ), $titles );
	}

	public function test_editing_a_source_post_schedules_one_rebuild(): void {
		$this->settings( array( 'sources' => array( 'post' ) ) );
		$id = $this->doc( 'Doc', '<p>One.</p>' );
		wp_clear_scheduled_hook( Corpus::CRON_REBUILD );
		wp_update_post( array( 'ID' => $id, 'post_content' => '<p>Two.</p>' ) );
		$first = wp_next_scheduled( Corpus::CRON_REBUILD );
		$this->assertNotFalse( $first );
		wp_update_post( array( 'ID' => $id, 'post_content' => '<p>Three.</p>' ) );
		$this->assertSame( $first, wp_next_scheduled( Corpus::CRON_REBUILD ), 'debounced: still one rebuild' );
	}

	public function test_a_post_outside_the_sources_schedules_nothing(): void {
		$this->settings( array( 'sources' => array( 'page' ) ) );
		wp_clear_scheduled_hook( Corpus::CRON_REBUILD );
		$this->doc( 'Doc', '<p>One.</p>' );
		$this->assertFalse( wp_next_scheduled( Corpus::CRON_REBUILD ) );
	}

	public function test_small_docs_use_whole_mode_and_large_docs_retrieval(): void {
		$this->settings( array( 'sources' => array( 'post' ) ) );
		$this->doc( 'Small', '<h2>Part</h2><p>Short text.</p>' );
		$this->assertSame( 'whole', Corpus::build()['mode'] );

		$this->settings( array( 'context_budget' => 1000 ) );
		$this->doc( 'Big', '<h2>One</h2><p>' . str_repeat( 'Lorem ipsum dolor sit amet. ', 400 ) . '</p>' );
		$corpus = Corpus::build();
		$this->assertSame( 'retrieval', $corpus['mode'] );
		$this->assertNotEmpty( $corpus['chunks'] );
		$this->assertArrayNotHasKey( 'text', $corpus, 'retrieval mode does not keep the whole text' );
	}

	public function test_retrieval_puts_the_doc_map_in_the_cached_block_and_chunks_in_the_other(): void {
		$this->settings(
			array(
				'sources'        => array( 'post' ),
				'context_budget' => 1000,
			)
		);
		$this->doc( 'Fonts Manager', '<h2>Linking a font is refused</h2><p>Delta hosts fonts itself.</p>' );
		$this->doc( 'Filler', '<p>' . str_repeat( 'Unrelated words about something else. ', 300 ) . '</p>' );
		$corpus = Corpus::build();
		$prompt = Answerer::prompt(
			$corpus,
			array(
				array(
					'role'    => 'user',
					'content' => 'Why is linking a font refused?',
				),
			),
			home_url( '/' )
		)['prompt'];
		$this->assertStringContainsString( '<doc_map>', $prompt->cached );
		$this->assertStringContainsString( '- Fonts Manager: ', $prompt->cached );
		$this->assertStringNotContainsString( 'Delta hosts fonts itself', $prompt->cached, 'chunks are not in the cached prefix' );
		$this->assertStringContainsString( 'Delta hosts fonts itself', $prompt->dynamic );
	}

	public function test_the_exact_count_comes_from_count_tokens_and_decides_the_mode(): void {
		$this->settings(
			array(
				'sources'       => array( 'post' ),
				'provider'      => 'anthropic',
				'anthropic_key' => 'sk-ant-test-key-0000',
			)
		);
		$this->doc( 'Small', '<p>Short.</p>' );
		$this->answer_http( fn() => self::json( 200, array( 'input_tokens' => 95000 ) ) );
		$corpus = Corpus::build();
		$this->assertSame( 95000, $corpus['tokens_exact'] );
		$this->assertSame( 'retrieval', $corpus['mode'], 'the exact count, not the tiny estimate, decides' );
		$this->assertStringEndsWith( '/v1/messages/count_tokens', $this->http[0]['url'] );
	}
}
