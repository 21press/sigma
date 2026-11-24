<?php
/**
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Tests\Unit;

use TwentyOnePress\Sigma\Knowledge\Retriever;
use TwentyOnePress\Sigma\Tests\Support\Source;
use TwentyOnePress\Sigma\Tests\Support\Unit_Test_Case;

/**
 * @covers \TwentyOnePress\Sigma\Knowledge\Retriever
 */
final class Retriever_Test extends Unit_Test_Case {

	protected function set_up(): void {
		parent::set_up();
		Source::load( 'includes/knowledge/class-retriever.php' );
	}

	private function docs(): array {
		return array(
			array(
				'url'     => 'https://example.com/docs/snippets/',
				'title'   => 'Snippets',
				'updated' => '2026-11-18',
				'text'    => "Snippets runs code.\n\n## Switch it on\n\nGo to Features and switch on Snippets.\n\n## The Failsafe link\n\nA broken snippet is switched off by the failsafe.",
			),
			array(
				'url'     => 'https://example.com/docs/backups/',
				'title'   => 'Import & Export',
				'updated' => '2026-11-18',
				'text'    => "Back up the database and media.\n\n## Bring a backup in\n\nUpload the bundle on another site.",
			),
		);
	}

	public function test_docs_split_at_level_two_headings_and_keep_them(): void {
		$chunks = Retriever::chunk( $this->docs(), 3.0 );
		$this->assertCount( 5, $chunks );
		$this->assertSame( '', $chunks[0]['heading'], 'the text before the first heading is a chunk' );
		$this->assertSame( 'Switch it on', $chunks[1]['heading'] );
		$this->assertStringStartsWith( '## Switch it on', $chunks[1]['text'], 'a chunk keeps its heading line' );
		$this->assertSame( 1, $chunks[4]['doc'] );
	}

	public function test_a_big_section_splits_at_level_three_then_at_paragraphs_never_inside_code(): void {
		$para = str_repeat( 'word ', 400 );
		$code = "```\n" . str_repeat( "line\n\n", 50 ) . '```';
		$doc  = array(
			'url'     => 'https://example.com/docs/hooks/',
			'title'   => 'Developer hooks',
			'updated' => '2026-11-18',
			'text'    => "## Filters\n\n### Templates\n\n{$para}\n\n{$para}\n\n{$code}\n\n### Render\n\nShort.",
		);
		$chunks = Retriever::chunk( array( $doc ), 1.0 ); // 1 char a token: 1,500-byte chunks.
		$this->assertGreaterThan( 3, count( $chunks ) );
		foreach ( $chunks as $chunk ) {
			$this->assertSame( 0, substr_count( $chunk['text'], '```' ) % 2, 'a code fence is never cut in two' );
		}
		$this->assertSame( 'Filters > Render', end( $chunks )['heading'], 'the heading path names both levels' );
	}

	public function test_bm25_ranks_the_matching_doc_first_and_respects_the_budget(): void {
		$docs   = $this->docs();
		$chunks = Retriever::chunk( $docs, 3.0 );
		$stats  = Retriever::stats( $chunks, $docs );
		$chosen = Retriever::select( $chunks, $docs, $stats, 'how do I restore a backup on another site', 10000 );
		$this->assertNotEmpty( $chosen );
		$this->assertSame( 1, $chosen[0]['doc'], 'the backup doc is chosen' );

		$one = Retriever::select( $chunks, $docs, $stats, 'snippets failsafe broken', 1 );
		$this->assertSame( array(), $one, 'nothing fits a 1-token budget' );
	}

	public function test_the_title_weighs_more_than_the_body(): void {
		$docs   = array(
			array(
				'url'     => 'https://example.com/a/',
				'title'   => 'Fonts',
				'updated' => '',
				'text'    => 'Pick weights.',
			),
			array(
				'url'     => 'https://example.com/b/',
				'title'   => 'Other',
				'updated' => '',
				'text'    => 'Mentions fonts once among many other words here.',
			),
		);
		$chunks = Retriever::chunk( $docs, 3.0 );
		$chosen = Retriever::select( $chunks, $docs, Retriever::stats( $chunks, $docs ), 'fonts', 10000 );
		usort( $chosen, static fn( $a, $b ) => $b['score'] <=> $a['score'] );
		$this->assertSame( 0, $chosen[0]['doc'] );
	}

	public function test_plurals_match_and_stop_words_do_not(): void {
		$this->assertSame( array( 'snippet', 'entry' ), Retriever::terms( 'The Snippets and the entries' ) );
	}

	public function test_chosen_chunks_go_back_under_their_doc_in_document_order(): void {
		$docs   = $this->docs();
		$chunks = Retriever::chunk( $docs, 3.0 );
		$block  = Retriever::docs_block( array( $chunks[4], $chunks[1], $chunks[2] ), $docs );
		$this->assertSame( 2, substr_count( $block, '<doc url=' ), 'one tag per doc' );
		$this->assertLessThan( strpos( $block, 'backups' ), strpos( $block, 'snippets' ), 'document order' );
		$this->assertStringContainsString( '[…]', $block, 'two chunks of one doc are separated' );
	}

	public function test_the_doc_map_lists_every_doc(): void {
		$this->assertSame( "- Snippets: https://example.com/docs/snippets/\n- Import & Export: https://example.com/docs/backups/", Retriever::doc_map( $this->docs() ) );
	}
}
