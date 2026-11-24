<?php
/**
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Tests\Integration;

use TwentyOnePress\Sigma\AI\Answerer;
use TwentyOnePress\Sigma\Conversations\Repository;
use TwentyOnePress\Sigma\Knowledge\Corpus;
use TwentyOnePress\Sigma\Knowledge\Knowledge_Base;
use TwentyOnePress\Sigma\Settings;
use TwentyOnePress\Sigma\Tests\Support\Integration_Test_Case;

/**
 * The knowledge base (§7.6): private by construction, raw text, always a source, the sources marker.
 *
 * @covers \TwentyOnePress\Sigma\Knowledge\Knowledge_Base
 * @covers \TwentyOnePress\Sigma\Rest\Knowledge_Controller
 * @covers \TwentyOnePress\Sigma\Knowledge\Corpus
 * @covers \TwentyOnePress\Sigma\AI\Answerer
 */
final class Knowledge_Base_Test extends Integration_Test_Case {

	private const SECRET_TITLE = 'Zqxj partner pricing';

	private const SECRET_TEXT = "Partner price is ZQXJ-77 dollars.\n\nOnly for resellers.";

	public function set_up(): void {
		parent::set_up();
		$this->settings(
			array(
				'sources'   => array(),
				'ip_source' => 'remote_addr',
			)
		);
		$_SERVER['REMOTE_ADDR'] = '198.51.100.60';
	}

	private function entry( string $title = self::SECRET_TITLE, string $text = self::SECRET_TEXT, bool $active = true ): int {
		$id = Knowledge_Base::create( $title, $text, $active );
		$this->assertIsInt( $id );
		return $id;
	}

	/**
	 * Fail when a string shows the secret entry.
	 *
	 * @param string $haystack Output.
	 * @param string $where    Where it came from.
	 */
	private function assert_hidden( string $haystack, string $where ): void {
		$this->assertStringNotContainsStringIgnoringCase( 'zqxj', $haystack, $where );
	}

	/**
	 * A front-end request the way a visitor makes it: the URL's query in $_GET only. (go_to() passes
	 * the query to WP::main() as trusted extra query vars, which skips core's limits on visitor input,
	 * such as post_type being allowed for publicly queryable types only.)
	 *
	 * @param string $query Query string.
	 * @return string Titles and text of the posts the main query found.
	 */
	private function visit( string $query ): string {
		$_GET = array();
		parse_str( $query, $_GET );
		$_SERVER['REQUEST_URI'] = '/?' . $query;
		unset( $GLOBALS['wp_query'], $GLOBALS['wp_the_query'] );
		$GLOBALS['wp_the_query'] = new \WP_Query();
		$GLOBALS['wp_query']     = $GLOBALS['wp_the_query']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wp']->main();
		$seen = '';
		foreach ( (array) $GLOBALS['wp_query']->posts as $found ) {
			$seen .= $found->post_title . ' ' . $found->post_content . ' ';
		}
		$_GET = array();
		return $seen;
	}

	public function test_the_post_type_has_every_public_flag_off_and_admin_only_caps(): void {
		$type = get_post_type_object( Knowledge_Base::POST_TYPE );
		$this->assertNotNull( $type );
		foreach ( array( 'public', 'publicly_queryable', 'show_ui', 'show_in_rest', 'show_in_nav_menus', 'has_archive', 'query_var', 'rewrite' ) as $flag ) {
			$this->assertFalse( (bool) $type->$flag, $flag );
		}
		$this->assertTrue( $type->exclude_from_search );
		$this->assertTrue( $type->can_export, 'Tools → Export carries it' );
		$this->assertTrue( post_type_supports( Knowledge_Base::POST_TYPE, 'revisions' ) );
		foreach ( (array) $type->cap as $name => $primitive ) {
			if ( ! in_array( $name, array( 'edit_post', 'read_post', 'delete_post' ), true ) ) {
				$this->assertSame( 'manage_options', $primitive, "{$name}: every primitive cap is manage_options" );
			}
		}
	}

	public function test_no_front_end_url_search_feed_sitemap_or_oembed_reveals_an_entry(): void {
		$id   = $this->entry();
		$post = get_post( $id );
		$urls = array(
			'/?p=' . $id,
			'/?post_type=da_knowledge',
			'/?post_type=da_knowledge&p=' . $id,
			'/?da_knowledge=' . $post->post_name,
			'/?s=Zqxj',
			'/?s=Zqxj&post_type=da_knowledge',
			'/?feed=rss2',
			'/?feed=rss2&post_type=da_knowledge',
			'/?page_id=' . $id,
		);
		wp_set_current_user( 0 );
		foreach ( $urls as $url ) {
			$this->assert_hidden( $this->visit( substr( $url, 2 ) ), $url );
			$this->assertFalse( is_singular( Knowledge_Base::POST_TYPE ), $url );
		}
		$this->assertNotContains( Knowledge_Base::POST_TYPE, array_keys( wp_sitemaps_get_server()->registry->get_provider( 'posts' )->get_object_subtypes() ), 'not in the sitemap' );
		$this->assertFalse( get_oembed_response_data( $post, 600 ), 'no oEmbed data' );
		$this->assertFalse( is_post_publicly_viewable( $post ) );
	}

	public function test_core_rest_never_shows_an_entry_even_to_an_admin(): void {
		$this->entry();
		$this->assertSame( 404, rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/wp/v2/da_knowledge' ) )->get_status(), 'no core route' );
		$type = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/wp/v2/types/da_knowledge' ) );
		$this->assertContains( $type->get_status(), array( 403, 404 ), 'the type is not exposed' );
		$this->assert_hidden( (string) wp_json_encode( rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/wp/v2/types' ) )->get_data() ), 'types list' );
		foreach ( array( '/wp/v2/search', '/wp/v2/posts', '/wp/v2/pages' ) as $route ) {
			$request = new \WP_REST_Request( 'GET', $route );
			$request->set_query_params( array( 'search' => 'Zqxj' ) );
			$this->assert_hidden( (string) wp_json_encode( rest_get_server()->dispatch( $request )->get_data() ), $route );
		}
		$embed = new \WP_REST_Request( 'GET', '/oembed/1.0/embed' );
		$embed->set_query_params( array( 'url' => (string) get_permalink( Knowledge_Base::find( $this->entry( 'Second zqxj note', 'zqxj two' ) ) ) ) );
		$this->assertNotSame( 200, rest_get_server()->dispatch( $embed )->get_status(), 'no oEmbed' );
	}

	public function test_an_editor_cannot_read_edit_or_delete_an_entry_through_any_route(): void {
		$id = $this->entry();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		foreach ( array( 'read_post', 'edit_post', 'delete_post', 'publish_post' ) as $cap ) {
			$this->assertFalse( current_user_can( $cap, $id ), $cap );
		}
		$type = get_post_type_object( Knowledge_Base::POST_TYPE );
		foreach ( array( 'edit_posts', 'create_posts', 'publish_posts', 'delete_posts', 'read_private_posts' ) as $cap ) {
			$this->assertFalse( current_user_can( $type->cap->$cap ), $cap );
		}
		foreach ( array( array( 'GET', '/admin/knowledge' ), array( 'GET', "/admin/knowledge/{$id}" ), array( 'PUT', "/admin/knowledge/{$id}" ), array( 'DELETE', "/admin/knowledge/{$id}" ), array( 'GET', '/admin/knowledge/titles' ), array( 'POST', '/admin/knowledge/import' ) ) as [ $method, $route ] ) {
			$this->assertSame( 403, $this->rest( $method, $route, array( 'title' => 'x' ) )->get_status(), "{$method} {$route}" );
		}
		$this->assertSame( self::SECRET_TEXT, get_post( $id )->post_content, 'unchanged' );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertTrue( user_can( $admin, 'edit_post', $id ), 'an administrator can' );
		$this->assertTrue( user_can( $admin, 'delete_post', $id ) );
	}

	public function test_shortcodes_scripts_and_blocks_are_stored_and_used_as_raw_text_never_run(): void {
		add_shortcode(
			'da_boom',
			static function (): string {
				return 'SHORTCODE-RAN';
			}
		);
		$text = "Call [da_boom] now.\n<script>alert('x')</script>\n<!-- wp:paragraph --><p>Block text</p><!-- /wp:paragraph -->\n\x01Tab\there";
		$id   = $this->entry( 'Raw text', $text );
		$want = "Call [da_boom] now.\n<script>alert('x')</script>\n<!-- wp:paragraph --><p>Block text</p><!-- /wp:paragraph -->\nTab\there";
		$this->assertSame( $want, get_post( $id )->post_content, 'kept exactly, control characters stripped' );
		$this->assertSame( $want, $this->rest( 'GET', "/admin/knowledge/{$id}" )->get_data()['content'], 'the admin gets the text as text' );
		$corpus = Corpus::build();
		$this->assertStringContainsString( $want, $corpus['knowledge_text'], 'the corpus holds the raw text' );
		$this->assertStringNotContainsString( 'SHORTCODE-RAN', $corpus['knowledge_text'] );
		$this->assertStringContainsString( '<doc kind="knowledge" id="kb-' . $id . '" title="Raw text"', $corpus['knowledge_text'] );
	}

	public function test_knowledge_is_always_a_source_even_with_no_post_types(): void {
		$this->assertSame( 'no_sources', Answerer::unavailable_reason(), 'nothing at all to answer from' );
		$draft = $this->entry( 'A draft', 'Not used yet.', false );
		$this->assertSame( 'no_sources', Answerer::unavailable_reason(), 'a draft is not a source' );
		Knowledge_Base::set_active( $draft, true );
		$this->assertSame( '', Answerer::unavailable_reason(), 'one active entry is enough' );
		$corpus = Corpus::build();
		$this->assertSame( array( $draft ), array_column( $corpus['knowledge'], 'id' ) );
		$this->assertSame( array(), $corpus['docs'] );
		$this->assertTrue( Corpus::available() );
		$settings = $this->rest( 'GET', '/admin/settings' )->get_data()['knowledge'];
		$this->assertSame(
			array(
				'active'       => 1,
				'draft'        => 0,
				'active_words' => 3,
			),
			$settings
		);
	}

	public function test_an_answer_from_knowledge_is_answered_lists_the_entry_and_never_shows_the_marker(): void {
		$id = $this->entry( 'Licence prices', "The Pro licence costs 149 dollars a year for five sites.\nThe Agency licence costs 399 dollars a year." );
		$this->doc( 'Snippets', '<p>Snippets runs small pieces of code.</p>' );
		$this->settings( array( 'sources' => array( 'post' ) ) );
		Corpus::build();
		[ $uuid, $token ] = $this->conversation( false );
		$response         = $this->rest( 'POST', "/conversations/{$uuid}/ask", array( 'text' => 'How much does the Agency licence cost per year?' ), array( 'X-DA-Token' => $token ) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['answered'], 'knowledge only, no link: still answered' );
		$this->assertStringContainsString( '399 dollars', $data['message']['body'] );
		$this->assertStringNotContainsString( '[[', $data['message']['body'] );
		$this->assertStringNotContainsString( 'kb-', $data['message']['body'] );
		$this->assertSame( array(), $data['message']['links'], 'knowledge is never linked' );

		$messages = $this->rest( 'GET', "/admin/conversations/{$uuid}" )->get_data()['messages'];
		$ai       = array_values( array_filter( $messages, static fn( array $m ): bool => 'ai' === $m['sender'] ) )[0];
		$this->assertSame(
			array(
				array(
					'id'    => $id,
					'title' => 'Licence prices',
				),
			),
			$ai['meta']['kb'],
			'Conversations shows "Used: Licence prices"'
		);
		wp_delete_post( $id, true );
		$messages = $this->rest( 'GET', "/admin/conversations/{$uuid}" )->get_data()['messages'];
		$ai       = array_values( array_filter( $messages, static fn( array $m ): bool => 'ai' === $m['sender'] ) )[0];
		$this->assertSame( array(), $ai['meta']['kb'], 'a deleted entry is dropped' );
	}

	public function test_unknown_or_inactive_knowledge_ids_are_dropped(): void {
		$active = $this->entry( 'Active one', 'Yes.' );
		$draft  = $this->entry( 'Draft one', 'No.', false );
		$post   = self::factory()->post->create();
		$corpus = array(
			array( 'id' => $active ),
			array( 'id' => $draft ),
			array( 'id' => $post ),
		);
		$this->assertSame( array( $active ), Answerer::valid_knowledge( array( $active, $draft, $post, 999999 ), $corpus ) );
		Knowledge_Base::set_active( $active, false );
		$this->assertSame( array(), Answerer::valid_knowledge( array( $active ), $corpus ), 'moved to draft after the build' );
		$this->assertSame( array(), Answerer::valid_knowledge( array( $active ), array() ), 'not in the corpus the answer came from' );
	}

	public function test_rest_crud_bulk_list_counts_and_search(): void {
		$created = $this->rest(
			'POST',
			'/admin/knowledge',
			array(
				'title'   => '  Refund policy ',
				'content' => "Refunds within 30 days.\r\nNo questions asked.",
				'status'  => 'active',
			)
		);
		$this->assertSame( 201, $created->get_status() );
		$entry = $created->get_data();
		$this->assertSame( 'Refund policy', $entry['title'] );
		$this->assertSame( "Refunds within 30 days.\nNo questions asked.", $entry['content'] );
		$this->assertSame( array( 'active', '', null, 7 ), array( $entry['status'], $entry['source'], $entry['imported_at'], $entry['words'] ) );
		$id = $entry['id'];

		$this->assertSame(
			'invalid_entry',
			$this->rest(
				'POST',
				'/admin/knowledge',
				array(
					'title'   => '',
					'content' => 'x',
				)
			)->as_error()->get_error_code()
		);
		$this->assertSame(
			'title',
			$this->rest(
				'POST',
				'/admin/knowledge',
				array(
					'title'   => str_repeat( 'a', 201 ),
					'content' => 'x',
				)
			)->as_error()->get_error_data()['field']
		);
		$this->assertSame(
			'content',
			$this->rest(
				'POST',
				'/admin/knowledge',
				array(
					'title'   => 'Big',
					'content' => str_repeat( 'a', Knowledge_Base::MAX_BYTES + 1 ),
				)
			)->as_error()->get_error_data()['field']
		);

		$updated = $this->rest(
			'PUT',
			"/admin/knowledge/{$id}",
			array(
				'content' => 'Refunds within 60 days.',
				'source'  => 'refunds.md',
				'status'  => 'draft',
			)
		)->get_data();
		$this->assertSame( array( 'Refund policy', 'Refunds within 60 days.', 'draft', 'refunds.md' ), array( $updated['title'], $updated['content'], $updated['status'], $updated['source'] ) );
		$this->assertNotNull( $updated['imported_at'] );
		$this->assertNotEmpty( wp_get_post_revisions( $id ), 'revisions: free undo' );

		$this->entry( 'Shipping', 'We ship worldwide.' );
		$old = $this->entry( 'Old note', 'Something else.', false );
		$this->assertMatchesRegularExpression( '/^20\d\d-\d\d-\d\d \d\d:\d\d:\d\d$/', Knowledge_Base::to_array( get_post( $old ) )['updated_at'], 'a new draft has a real time, not zeros' );
		$list = $this->rest( 'GET', '/admin/knowledge?status=draft' )->get_data();
		$this->assertSame(
			array(
				'all'    => 3,
				'active' => 1,
				'draft'  => 2,
			),
			$list['counts']
		);
		$this->assertSame( 2, $list['total'] );
		$this->assertArrayNotHasKey( 'content', $list['items'][0], 'the list carries no text' );
		$this->assertSame( array( 'Shipping' ), array_column( $this->rest( 'GET', '/admin/knowledge?search=worldwide' )->get_data()['items'], 'title' ), 'search covers the text' );
		$this->assertSame( 3, $list['active_words'], 'the footer counts active words' );

		$bulk = $this->rest(
			'POST',
			'/admin/knowledge/bulk',
			array(
				'action' => 'activate',
				'ids'    => array( $id, 999999 ),
			)
		)->get_data();
		$this->assertSame( array( $id ), $bulk['done'] );
		$this->assertSame( 'not_found', $bulk['failed'][0]['reason'] );
		$this->assertSame( 2, $bulk['counts']['active'] );
		$this->assertSame(
			400,
			$this->rest(
				'POST',
				'/admin/knowledge/bulk',
				array(
					'action' => 'publish_all',
					'ids'    => array( $id ),
				)
			)->get_status()
		);

		$this->assertSame(
			array(
				'deleted' => true,
				'id'      => $id,
			),
			$this->rest( 'DELETE', "/admin/knowledge/{$id}" )->get_data()
		);
		$this->assertNull( get_post( $id ), 'for good, no trash' );
		$this->assertSame( 404, $this->rest( 'GET', "/admin/knowledge/{$id}" )->get_status() );
		$this->assertSame( 404, $this->rest( 'GET', '/admin/knowledge/' . self::factory()->post->create() )->get_status(), 'other post types are not entries' );
		$this->assertSame( array( 'Old note', 'Shipping' ), array_column( $this->rest( 'GET', '/admin/knowledge/titles' )->get_data(), 'title' ) );
	}

	public function test_import_creates_drafts_replaces_same_titles_and_refuses_bad_files(): void {
		$existing = $this->entry( 'Pricing', 'Old prices.' );
		$result   = $this->rest(
			'POST',
			'/admin/knowledge/import',
			array(
				'active' => false,
				'files'  => array(
					array(
						'name'    => 'pricing.md',
						'title'   => '  PRICING ',
						'content' => 'New prices: 149.',
					),
					array(
						'name'    => 'faq.md',
						'title'   => 'FAQ',
						'content' => 'Questions.',
					),
					array(
						'name'    => 'notes.txt',
						'title'   => '',
						'content' => 'Plain notes.',
					),
					array(
						'name'    => 'logo.png',
						'title'   => 'Logo',
						'content' => 'x',
					),
					array(
						'name'    => 'huge.md',
						'title'   => 'Huge',
						'content' => str_repeat( 'a', Knowledge_Base::MAX_BYTES + 1 ),
					),
					array(
						'name'    => 'blank.md',
						'title'   => 'Blank',
						'content' => "  \n ",
					),
				),
			)
		)->get_data();
		$this->assertSame(
			array( 'replaced', 'created', 'created', 'refused', 'refused', 'refused' ),
			array_column( $result['results'], 'result' )
		);
		$this->assertSame( array( 'extension', 'size', 'empty' ), array_column( array_slice( $result['results'], 3 ), 'reason' ) );
		// JSON can't carry invalid UTF-8 (the REST body would not parse), so the server check is tested directly.
		$latin = Knowledge_Base::import(
			array(
				array(
					'name'    => 'latin1.md',
					'title'   => 'Latin',
					'content' => "caf\xE9",
				),
			),
			false
		);
		$this->assertSame( array( 'refused', 'encoding' ), array( $latin[0]['result'], $latin[0]['reason'] ) );
		$this->assertSame( $existing, $result['results'][0]['id'] );
		$this->assertSame( 'New prices: 149.', get_post( $existing )->post_content );
		$this->assertSame( 'publish', get_post( $existing )->post_status, 'a replaced entry keeps its status' );
		$this->assertSame( 'pricing.md', get_post_meta( $existing, Knowledge_Base::META_SOURCE, true ) );
		$this->assertSame( 'draft', get_post( $result['results'][1]['id'] )->post_status, 'new entries start as drafts' );
		$this->assertSame( 'notes', get_post( $result['results'][2]['id'] )->post_title, 'no title: the file name' );
		$this->assertSame(
			400,
			$this->rest(
				'POST',
				'/admin/knowledge/import',
				array(
					'files' => array_fill(
						0,
						51,
						array(
							'name'    => 'a.md',
							'title'   => 'A',
							'content' => 'a',
						)
					),
				)
			)->get_status()
		);
		$active = $this->rest(
			'POST',
			'/admin/knowledge/import',
			array(
				'active' => true,
				'files'  => array(
					array(
						'name'    => 'new.md',
						'title'   => 'New one',
						'content' => 'Fresh.',
					),
				),
			)
		)->get_data();
		$this->assertSame( 'publish', get_post( $active['results'][0]['id'] )->post_status, '"Make new entries active"' );
	}

	public function test_any_change_schedules_one_debounced_rebuild(): void {
		wp_clear_scheduled_hook( Corpus::CRON_REBUILD );
		$id = $this->entry();
		$at = wp_next_scheduled( Corpus::CRON_REBUILD );
		$this->assertNotFalse( $at );
		$this->assertLessThanOrEqual( time(), $at, 'due now (the answers use the current entries meanwhile)' );
		Knowledge_Base::update( $id, array( 'content' => 'Changed.' ) );
		Knowledge_Base::set_active( $id, false );
		$crons = array_filter( _get_cron_array(), static fn( array $hooks ): bool => isset( $hooks[ Corpus::CRON_REBUILD ] ) );
		$this->assertCount( 1, $crons, 'one waiting rebuild, not one per change' );
		wp_clear_scheduled_hook( Corpus::CRON_REBUILD );
		Knowledge_Base::delete( $id );
		$this->assertNotFalse( wp_next_scheduled( Corpus::CRON_REBUILD ), 'deleting schedules it too' );
	}

	public function test_uninstall_keeps_entries_when_the_data_is_kept(): void {
		$id = $this->entry();
		$this->settings( array( 'delete_on_uninstall' => false ) );
		try {
			if ( ! function_exists( 'sigma_uninstall_site' ) ) {
				if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
					define( 'WP_UNINSTALL_PLUGIN', 'sigma/sigma.php' );
				}
				require dirname( __DIR__, 5 ) . '/uninstall.php';
			} else {
				sigma_uninstall_site();
			}
			$this->assertNotNull( get_post( $id ), 'kept with the data' );
		} finally {
			get_role( 'administrator' )->add_cap( \TwentyOnePress\Sigma\Plugin::CAP_VIEW );
		}
	}

	public function test_privacy_tools_leave_knowledge_alone(): void {
		$id     = $this->entry( 'Contact', 'Write to ann@example.test about invoices.' );
		$export = \TwentyOnePress\Sigma\Privacy\Privacy::export( 'ann@example.test' );
		$this->assertSame( array(), $export['data'], 'knowledge is not personal data' );
		\TwentyOnePress\Sigma\Privacy\Privacy::erase( 'ann@example.test' );
		$this->assertNotNull( get_post( $id ) );
		$this->assertSame( array(), ( new Repository() )->find_by_email( 'ann@example.test' ) );
		$this->assertSame( array(), Settings::get( 'sources' ) );
	}
}
