<?php
/**
 * Builds, hashes and stores the docs corpus (§7.3).
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Knowledge;

use TwentyOnePress\Sigma\AI\Anthropic_Provider;
use TwentyOnePress\Sigma\AI\Answerer;
use TwentyOnePress\Sigma\AI\Prompt;
use TwentyOnePress\Sigma\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The whole set of published docs of the source post types, as one text block in a stable order,
 * so it can sit in a cached prompt prefix. Stored in `delta_assistant_corpus` (autoload no).
 *
 * Active knowledge base entries (§7.6) are always part of it, ahead of the docs, in their own
 * `<knowledge>` block that sits in the cached prefix in both modes. They are read from the raw
 * `post_content`, never through the_content, so no shortcode, block or filter ever runs on them.
 *
 * A change to a source post schedules one rebuild 60 s later (debounced). A new hash means a new
 * cache prefix at the provider, so answers follow the docs as soon as the rebuild runs.
 */
final class Corpus {

	public const OPTION = 'delta_assistant_corpus';

	/** A small record of the last good build, so a request can check for a corpus without loading it. */
	public const META_OPTION  = 'delta_assistant_corpus_meta';
	public const CRON_REBUILD = 'delta_assistant_rebuild_corpus';

	/** Characters per token when no exact count exists: measured 2.8 on Delta's docs (§7.4). */
	private const CHARS_PER_TOKEN = 2.8;

	/**
	 * Hooks.
	 */
	public static function hooks(): void {
		add_action( 'save_post', array( self::class, 'post_changed' ), 10, 2 );
		add_action( 'deleted_post', array( self::class, 'post_changed' ), 10, 2 );
		add_action( 'transition_post_status', array( self::class, 'status_changed' ), 10, 3 );
		add_action( self::CRON_REBUILD, array( self::class, 'build' ) );
	}

	/**
	 * A post was saved or deleted.
	 *
	 * @param int           $post_id Post id.
	 * @param \WP_Post|null $post    Post.
	 */
	public static function post_changed( $post_id, $post = null ): void {
		if ( $post instanceof \WP_Post && self::is_source( $post ) ) {
			self::schedule();
		}
	}

	/**
	 * A post changed status (published, unpublished, trashed).
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 */
	public static function status_changed( $new_status, $old_status, $post ): void {
		if ( $new_status !== $old_status && $post instanceof \WP_Post && self::is_source( $post ) ) {
			self::schedule();
		}
	}

	/**
	 * Whether a post belongs to a source type (revisions and autosaves do not).
	 *
	 * @param \WP_Post $post Post.
	 */
	private static function is_source( \WP_Post $post ): bool {
		return in_array( $post->post_type, (array) Settings::get( 'sources' ), true ) && ! wp_is_post_revision( $post ) && ! wp_is_post_autosave( $post );
	}

	/**
	 * One rebuild 60 s from now, unless one is already waiting.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_REBUILD ) ) {
			wp_schedule_single_event( time() + 60, self::CRON_REBUILD );
		}
	}

	/**
	 * A rebuild in WP-Cron right away (§10b): admin saves and "Rebuild now" never build inside the
	 * request. The event is due now and WP-Cron is spawned without waiting.
	 */
	public static function schedule_now(): void {
		wp_clear_scheduled_hook( self::CRON_REBUILD );
		wp_schedule_single_event( time(), self::CRON_REBUILD );
		if ( function_exists( 'spawn_cron' ) && defined( 'WP_CRON_LOCK_TIMEOUT' ) && ! wp_doing_cron() ) {
			spawn_cron();
		}
	}

	/**
	 * Build and store the corpus now, as a logged-out visitor (§10b): the_content filters that show
	 * members-only content to the logged-in admin must never put it in the docs the AI answers from.
	 *
	 * @return array<string, mixed> The stored corpus.
	 */
	public static function build(): array {
		$user = get_current_user_id();
		wp_set_current_user( 0 );
		try {
			return self::build_as_visitor();
		} finally {
			wp_set_current_user( $user );
		}
	}

	/**
	 * Build and store the corpus (the current user is already 0).
	 *
	 * @return array<string, mixed> The stored corpus.
	 */
	private static function build_as_visitor(): array {
		$knowledge_rev = Knowledge_Base::revision(); // Read first: a change during the build stays newer.
		$sources       = array_values( array_filter( (array) Settings::get( 'sources' ), static fn( $type ): bool => post_type_exists( (string) $type ) && Settings::source_allowed( (string) $type ) ) );
		$docs          = array();
		foreach ( $sources as $type ) {
			$posts = get_posts(
				array(
					'post_type'        => $type,
					'post_status'      => 'publish',
					'has_password'     => false,
					'numberposts'      => -1,
					'orderby'          => 'ID',
					'order'            => 'ASC',
					'suppress_filters' => true,
					'no_found_rows'    => true,
				)
			);
			foreach ( $posts as $post ) {
				$text = Extractor::text( $post );
				if ( '' === $text ) {
					continue;
				}
				$docs[] = array(
					'id'      => (int) $post->ID,
					'type'    => $type,
					'url'     => (string) get_permalink( $post ),
					'title'   => wp_specialchars_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES ),
					'updated' => substr( (string) $post->post_modified_gmt, 0, 10 ),
					'text'    => $text,
				);
			}
		}

		$kb      = self::knowledge_entries();
		$kb_text = self::format_knowledge( $kb['entries'] );
		$text    = self::format( $docs );
		$tokens  = self::estimate_tokens( $kb_text . $text );
		$exact   = self::count_exact( Answerer::rules( 'whole' ) . self::knowledge_block( $kb_text ) . "\n\n<docs>\n" . $text . "\n</docs>" );
		$corpus  = array(
			'hash'           => hash( 'sha256', $kb_text . "\n\n" . $text ),
			'built_at'       => gmdate( 'Y-m-d H:i:s' ),
			'sources'        => $sources,
			'knowledge'      => array_map(
				static fn( array $entry ): array => array(
					'id'    => $entry['id'],
					'title' => $entry['title'],
					'chars' => strlen( $entry['text'] ),
				),
				$kb['entries']
			),
			'knowledge_text' => $kb_text,
			'knowledge_rev'  => $knowledge_rev,
			'knowledge_left' => $kb['left_out'],
			'tokens'         => $tokens,
			'tokens_exact'   => is_int( $exact ) ? $exact : null,
			'tokens_model'   => is_int( $exact ) ? (string) Settings::get( 'anthropic_model' ) : '',
			'tokens_error'   => is_wp_error( $exact ) ? $exact->get_error_message() : '',
			'docs'           => array_map(
				static function ( array $doc ): array {
					$doc['chars'] = strlen( $doc['text'] );
					unset( $doc['text'] );
					return $doc;
				},
				$docs
			),
		);

		// Whole mode while rules + corpus fit the context budget; retrieval mode above it (§7.4).
		$corpus['mode'] = self::over_budget( $corpus ) ? 'retrieval' : 'whole';
		/**
		 * Filters the corpus mode. The evals force `whole` once to measure what retrieval costs in
		 * accuracy (§7.4); a site should not need it.
		 *
		 * @param string $mode whole|retrieval.
		 */
		$forced = (string) \TwentyOnePress\Sigma\Hooks::filter( 'corpus_mode', $corpus['mode'] );
		if ( in_array( $forced, array( 'whole', 'retrieval' ), true ) ) {
			$corpus['mode'] = $forced;
		}
		if ( 'whole' === $corpus['mode'] ) {
			$corpus['text'] = $text;
		} else {
			$per_token        = is_int( $exact ) && $exact > 0 ? strlen( $text ) / $exact : self::CHARS_PER_TOKEN;
			$chunks           = Retriever::chunk( $docs, $per_token );
			$corpus['chunks'] = $chunks;
			$corpus['stats']  = Retriever::stats( $chunks, $docs );
			$prefix           = self::count_exact( Answerer::rules( 'retrieval' ) . self::knowledge_block( $kb_text ) . "\n\n<doc_map>\n" . Retriever::doc_map( $docs ) . "\n</doc_map>" );

			$corpus['prefix_tokens_exact'] = is_int( $prefix ) ? $prefix : null;
		}
		update_option( self::OPTION, $corpus, false );
		update_option(
			self::META_OPTION,
			array(
				'hash'      => $corpus['hash'],
				'mode'      => $corpus['mode'],
				'sources'   => $corpus['sources'],
				'docs'      => count( $corpus['docs'] ),
				'knowledge' => count( $corpus['knowledge'] ),
				'built_at'  => $corpus['built_at'],
			),
			false
		);
		return $corpus;
	}

	/**
	 * Active knowledge entries for the prompt, oldest first, as long as they fit in half the context
	 * budget (a single 1 MB entry must not make every question fail); the rest are listed as left out.
	 *
	 * @return array{entries: list<array{id: int, title: string, updated: string, text: string}>, left_out: list<int>}
	 */
	private static function knowledge_entries(): array {
		$budget  = (int) floor( (int) Settings::get( 'context_budget' ) / 2 );
		$used    = 0;
		$entries = array();
		$left    = array();
		foreach ( Knowledge_Base::active_posts() as $post ) {
			$text = trim( (string) $post->post_content ); // Raw Markdown: never the_content (§7.6).
			if ( '' === $text ) {
				continue;
			}
			$tokens = self::estimate_tokens( $text );
			if ( $used + $tokens > $budget ) {
				$left[] = (int) $post->ID;
				continue;
			}
			$used     += $tokens;
			$entries[] = array(
				'id'      => (int) $post->ID,
				'title'   => (string) $post->post_title,
				'updated' => substr( Knowledge_Base::modified_gmt( $post ), 0, 10 ),
				'text'    => $text,
			);
		}
		return array(
			'entries'  => $entries,
			'left_out' => $left,
		);
	}

	/**
	 * The corpus with its knowledge as it is now: when an entry was saved, drafted or deleted since the build, the
	 * knowledge block is rebuilt for this request from the active entries (small; the docs stay as built).
	 *
	 * @param array<string, mixed> $corpus Corpus.
	 * @return array<string, mixed>
	 */
	public static function with_current_knowledge( array $corpus ): array {
		if ( Knowledge_Base::revision() === ( $corpus['knowledge_rev'] ?? null ) ) {
			return $corpus;
		}
		$kb                       = self::knowledge_entries();
		$corpus['knowledge_text'] = self::format_knowledge( $kb['entries'] );
		$corpus['knowledge_left'] = $kb['left_out'];
		$corpus['knowledge']      = array_map(
			static fn( array $entry ): array => array(
				'id'    => $entry['id'],
				'title' => $entry['title'],
				'chars' => strlen( $entry['text'] ),
			),
			$kb['entries']
		);
		return $corpus;
	}

	/**
	 * Knowledge entries as prompt docs: `<doc kind="knowledge" id="kb-12" title="…" updated="…">`,
	 * with no URL (§7.6). Pure.
	 *
	 * @param list<array{id: int, title: string, updated: string, text: string}> $entries Entries.
	 */
	public static function format_knowledge( array $entries ): string {
		$out = array();
		foreach ( $entries as $entry ) {
			$out[] = sprintf(
				"<doc kind=\"knowledge\" id=\"kb-%d\" title=\"%s\" updated=\"%s\">\n%s\n</doc>",
				(int) $entry['id'],
				htmlspecialchars( $entry['title'], ENT_QUOTES, 'UTF-8' ),
				htmlspecialchars( $entry['updated'], ENT_QUOTES, 'UTF-8' ),
				str_ireplace( array( '</doc>', '</knowledge>' ), array( '&lt;/doc>', '&lt;/knowledge>' ), $entry['text'] )
			);
		}
		return implode( "\n\n", $out );
	}

	/**
	 * The `<knowledge>` block of the cached prompt prefix ('' when there are no entries). Pure.
	 *
	 * @param string $kb_text format_knowledge() output.
	 */
	public static function knowledge_block( string $kb_text ): string {
		return '' === $kb_text ? '' : "\n\n<knowledge>\n" . $kb_text . "\n</knowledge>";
	}

	/**
	 * Ids of the knowledge entries in the stored corpus.
	 *
	 * @return list<int>
	 */
	public static function knowledge_ids(): array {
		return array_map( static fn( array $entry ): int => (int) $entry['id'], (array) ( self::get()['knowledge'] ?? array() ) );
	}

	/**
	 * Docs as the prompt block: `<doc url="…" title="…" updated="…">text</doc>`. Pure.
	 *
	 * @param list<array{url: string, title: string, updated: string, text: string}> $docs Docs.
	 */
	public static function format( array $docs ): string {
		$out = array();
		foreach ( $docs as $doc ) {
			$out[] = sprintf(
				"<doc url=\"%s\" title=\"%s\" updated=\"%s\">\n%s\n</doc>",
				htmlspecialchars( $doc['url'], ENT_QUOTES, 'UTF-8' ),
				htmlspecialchars( $doc['title'], ENT_QUOTES, 'UTF-8' ),
				htmlspecialchars( $doc['updated'], ENT_QUOTES, 'UTF-8' ),
				str_replace( '</doc>', '&lt;/doc>', $doc['text'] )
			);
		}
		return implode( "\n\n", $out );
	}

	/**
	 * Exact tokens of a cached prompt block from Anthropic's free count_tokens endpoint, when the
	 * provider is Anthropic and a key is set. Null otherwise: the estimate is then used. The count
	 * is stored with the corpus hash, so it always belongs to this corpus.
	 *
	 * @param string $block The cached system block (rules + corpus, or rules + doc map).
	 * @return int|\WP_Error|null
	 */
	private static function count_exact( string $block ) {
		if ( 'anthropic' !== Settings::get( 'provider' ) ) {
			return null;
		}
		$key = Settings::api_key( 'anthropic' );
		if ( '' === $key ) {
			return null;
		}
		$prompt = new Prompt( $block, Answerer::context( home_url( '/' ) ) );
		return ( new Anthropic_Provider( $key ) )->count_tokens( $prompt, (string) Settings::get( 'anthropic_model' ) );
	}

	/**
	 * Rough token count of a text.
	 *
	 * @param string $text Text.
	 */
	public static function estimate_tokens( string $text ): int {
		return (int) ceil( strlen( $text ) / self::CHARS_PER_TOKEN );
	}

	/**
	 * The stored corpus; built now when there is none yet or the sources changed.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$corpus = get_option( self::OPTION );
		if ( ! is_array( $corpus ) || ! isset( $corpus['mode'] ) ) {
			self::schedule();
			return array();
		}
		if ( ( $corpus['sources'] ?? null ) !== self::wanted_sources() || ! array_key_exists( 'knowledge', $corpus ) ) {
			self::schedule(); // Stale: answer from the last good corpus while the rebuild waits.
		}
		return $corpus;
	}

	/**
	 * Whether a built corpus exists, from the small meta option (no 400 KB load). A request never
	 * builds (plan v1.10.1): it answers from the last good corpus, and a missing one is scheduled.
	 */
	public static function available(): bool {
		$meta = get_option( self::META_OPTION );
		if ( is_array( $meta ) && ( ! empty( $meta['docs'] ) || ! empty( $meta['knowledge'] ) ) ) {
			return true;
		}
		// Built before the meta option existed: fall back to the corpus itself once, and write the meta.
		$corpus = get_option( self::OPTION );
		if ( is_array( $corpus ) && isset( $corpus['mode'] ) && ! empty( $corpus['docs'] ) ) {
			update_option(
				self::META_OPTION,
				array(
					'hash'     => $corpus['hash'],
					'mode'     => $corpus['mode'],
					'sources'  => $corpus['sources'],
					'docs'     => count( $corpus['docs'] ),
					'built_at' => $corpus['built_at'],
				),
				false
			);
			return true;
		}
		self::schedule();
		// Nothing to answer from: ask WP-Cron to run now (a non-blocking loopback) instead of waiting
		// for the next visit on a quiet site. The build still runs in its own request, never this one.
		if ( function_exists( 'spawn_cron' ) && ! wp_doing_cron() ) {
			spawn_cron();
		}
		return false;
	}

	/**
	 * The source post types that exist, in the stored order.
	 *
	 * @return list<string>
	 */
	private static function wanted_sources(): array {
		return array_values( array_filter( (array) Settings::get( 'sources' ), 'post_type_exists' ) );
	}

	/**
	 * The corpus without its text, for wp-admin.
	 *
	 * @return array<string, mixed>
	 */
	public static function status(): array {
		$corpus = get_option( self::OPTION );
		if ( ! is_array( $corpus ) ) {
			return array(
				'built'          => false,
				'sources'        => (array) Settings::get( 'sources' ),
				'docs'           => array(),
				'tokens'         => 0,
				'context_budget' => (int) Settings::get( 'context_budget' ),
			);
		}
		unset( $corpus['text'], $corpus['chunks'], $corpus['stats'], $corpus['knowledge_text'] );
		$corpus['built']           = true;
		$corpus['context_budget']  = (int) Settings::get( 'context_budget' );
		$corpus['over_budget']     = self::over_budget( $corpus );
		$corpus['budget_tokens']   = self::budget_tokens( $corpus );
		$corpus['rebuild_pending'] = (bool) wp_next_scheduled( self::CRON_REBUILD );
		return $corpus;
	}

	/**
	 * Whether a corpus is too big for the whole-corpus prompt (§7.4). The exact count (rules, corpus
	 * and request context) leaves 2,000 tokens for the chat; the estimate (corpus only) leaves 3,000
	 * for rules and chat.
	 *
	 * @param array<string, mixed> $corpus Corpus.
	 */
	public static function over_budget( array $corpus ): bool {
		return self::budget_tokens( $corpus ) > (int) Settings::get( 'context_budget' );
	}

	/**
	 * Tokens a question costs before the chat: the exact count when there is one, else the estimate.
	 *
	 * @param array<string, mixed> $corpus Corpus.
	 */
	public static function budget_tokens( array $corpus ): int {
		if ( isset( $corpus['tokens_exact'] ) && is_int( $corpus['tokens_exact'] ) ) {
			return $corpus['tokens_exact'] + 2000;
		}
		return (int) ( $corpus['tokens'] ?? 0 ) + 3000;
	}

	/**
	 * Doc URLs and titles, for link checks.
	 *
	 * @return array<string, string> Title by URL.
	 */
	public static function urls(): array {
		$out = array();
		foreach ( (array) ( self::get()['docs'] ?? array() ) as $doc ) {
			if ( '' !== (string) ( $doc['url'] ?? '' ) ) {
				$out[ (string) $doc['url'] ] = (string) $doc['title'];
			}
		}
		return $out;
	}
}
