<?php
/**
 * The knowledge base (§7.6): notes the bot reads with every question. Not published on the site, but not secret:
 * every Active entry goes to the AI provider with every question, and the bot can share what's in it with any visitor.
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Entries are the `da_knowledge` post type, kept off the site's front end: no front-end URL, no core REST,
 * no search, sitemap, feed or oEmbed, and every capability mapped to `manage_options`. The text is
 * plain Markdown in `post_content`, never rendered: the corpus reads the raw field. `publish` is
 * Active (the bot uses it, and it goes to the AI provider with every question), `draft` is Draft (never sent).
 */
final class Knowledge_Base {

	public const POST_TYPE = 'da_knowledge';

	/** Meta: the file an entry came from ('' = written here). */
	public const META_SOURCE = '_da_source';

	/** Meta: when that file was imported (UTC). */
	public const META_IMPORTED = '_da_imported_at';

	/** Largest entry text, in bytes (§7.6). */
	public const MAX_BYTES = 1048576;

	/** Longest title. */
	public const MAX_TITLE = 200;

	/** Most files per import request. */
	public const MAX_FILES = 50;

	/**
	 * Option: changes with every entry save, status change or delete. The corpus records the value it was
	 * built from; an answer that sees another value rebuilds the knowledge block for itself (Corpus::with_current_knowledge),
	 * so a deleted or drafted entry stops being used at once, before WP-Cron rebuilds the corpus.
	 */
	public const REV_OPTION = 'sigma_knowledge_rev';

	/**
	 * Hooks.
	 */
	public static function hooks(): void {
		add_action( 'init', array( self::class, 'register' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( self::class, 'changed' ) );
		add_action( 'deleted_post', array( self::class, 'deleted' ), 10, 2 );
		add_action( 'transition_post_status', array( self::class, 'status_changed' ), 10, 3 );
	}

	/**
	 * Register the post type: every public flag off.
	 */
	public static function register(): void {
		$cap = 'manage_options';
		register_post_type(
			self::POST_TYPE,
			array(
				'label'               => __( 'Knowledge', 'sigma' ),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'query_var'           => false,
				'rewrite'             => false,
				'can_export'          => true, // Tools → Export, for migrations (§7.6).
				'hierarchical'        => false,
				'delete_with_user'    => false,
				'supports'            => array( 'title', 'editor', 'revisions' ),
				'capability_type'     => self::POST_TYPE,
				'map_meta_cap'        => true,
				// Every primitive capability is manage_options, so the meta capabilities (edit_post,
				// read_post, delete_post) map to it too: Editors and Authors can't reach an entry.
				'capabilities'        => array(
					'edit_posts'             => $cap,
					'edit_others_posts'      => $cap,
					'edit_private_posts'     => $cap,
					'edit_published_posts'   => $cap,
					'publish_posts'          => $cap,
					'read'                   => $cap,
					'read_private_posts'     => $cap,
					'delete_posts'           => $cap,
					'delete_private_posts'   => $cap,
					'delete_published_posts' => $cap,
					'delete_others_posts'    => $cap,
					'create_posts'           => $cap,
				),
			)
		);
	}

	/**
	 * An entry was saved.
	 *
	 * @param int $post_id Post id.
	 */
	public static function changed( $post_id ): void {
		if ( ! wp_is_post_revision( (int) $post_id ) && ! wp_is_post_autosave( (int) $post_id ) ) {
			self::schedule_rebuild();
		}
	}

	/**
	 * A post was deleted.
	 *
	 * @param int           $post_id Post id.
	 * @param \WP_Post|null $post    Post.
	 */
	public static function deleted( $post_id, $post = null ): void {
		if ( $post instanceof \WP_Post && self::POST_TYPE === $post->post_type ) {
			self::schedule_rebuild();
		}
	}

	/**
	 * An entry changed status.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 */
	public static function status_changed( $new_status, $old_status, $post ): void {
		if ( $new_status !== $old_status && $post instanceof \WP_Post && self::POST_TYPE === $post->post_type ) {
			self::schedule_rebuild();
		}
	}

	/**
	 * Rebuild the corpus a few seconds from now, never inline (§10b). Every change moves the run back,
	 * so a bulk action or an import rebuilds once. (WP-Cron refuses a second event of the same hook
	 * within 10 minutes, so the waiting one is cleared first.)
	 */
	public static function schedule_rebuild(): void {
		update_option( self::REV_OPTION, uniqid( '', true ), true );
		Corpus::schedule_now(); // Answers use the current entries meanwhile (REV_OPTION).
	}

	/**
	 * The current knowledge revision ('0' before any change).
	 */
	public static function revision(): string {
		return (string) get_option( self::REV_OPTION, '0' );
	}

	/**
	 * Clean entry text: valid UTF-8 required by the caller; control characters other than newline and
	 * tab removed; line endings unified. Pure.
	 *
	 * @param string $text Text.
	 */
	public static function clean_text( string $text ): string {
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		return (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );
	}

	/**
	 * A clean, single-line title. Pure.
	 *
	 * @param string $title Title.
	 */
	public static function clean_title( string $title ): string {
		$title = (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $title );
		return trim( (string) preg_replace( '/\s+/u', ' ', $title ) );
	}

	/**
	 * Words in a text (whitespace-separated runs). Pure.
	 *
	 * @param string $text Text.
	 */
	public static function words( string $text ): int {
		$text = trim( $text );
		return '' === $text ? 0 : count( (array) preg_split( '/\s+/u', $text ) );
	}

	/**
	 * Check a title and content. Returns the cleaned pair, or a 400 with the field named.
	 *
	 * @param string $title   Title.
	 * @param string $content Content.
	 * @return array{title: string, content: string}|\WP_Error
	 */
	public static function validate( string $title, string $content ) {
		if ( ! mb_check_encoding( $title, 'UTF-8' ) ) {
			return self::invalid( 'title', __( 'The title is not valid UTF-8 text.', 'sigma' ) );
		}
		$title = self::clean_title( $title );
		if ( '' === $title ) {
			return self::invalid( 'title', __( 'Give the entry a title.', 'sigma' ) );
		}
		if ( mb_strlen( $title ) > self::MAX_TITLE ) {
			/* translators: %d: characters. */
			return self::invalid( 'title', sprintf( __( 'The title can be at most %d characters.', 'sigma' ), self::MAX_TITLE ) );
		}
		if ( ! mb_check_encoding( $content, 'UTF-8' ) ) {
			return self::invalid( 'content', __( 'The text is not valid UTF-8. Save the file as UTF-8 and try again.', 'sigma' ) );
		}
		$content = self::clean_text( $content );
		if ( strlen( $content ) > self::MAX_BYTES ) {
			return self::invalid( 'content', __( 'The text is larger than 1 MB. Split it into smaller entries.', 'sigma' ) );
		}
		return array(
			'title'   => $title,
			'content' => $content,
		);
	}

	/**
	 * A clean source note (a file name), or a 400.
	 *
	 * @param string $source File name.
	 * @return string|\WP_Error
	 */
	public static function clean_source( string $source ) {
		$source = trim( (string) preg_replace( '/[\x00-\x1F\x7F\/\\\\]+/', '', $source ) );
		if ( mb_strlen( $source ) > 255 || ! mb_check_encoding( $source, 'UTF-8' ) ) {
			return self::invalid( 'source', __( 'The file name is too long.', 'sigma' ) );
		}
		return $source;
	}

	/**
	 * Create an entry.
	 *
	 * @param string $title   Title.
	 * @param string $content Markdown.
	 * @param bool   $active  Active (publish) or Draft.
	 * @param string $source  File name, '' when written here.
	 * @return int|\WP_Error Post id.
	 */
	public static function create( string $title, string $content, bool $active, string $source = '' ) {
		$valid = self::validate( $title, $content );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$source = self::clean_source( $source );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$id = self::insert(
			array(
				'post_type'    => self::POST_TYPE,
				'post_title'   => $valid['title'],
				'post_content' => $valid['content'],
				'post_status'  => $active ? 'publish' : 'draft',
			)
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		self::set_source( $id, $source );
		return $id;
	}

	/**
	 * Update an entry. Only the given fields change; a new source also stamps the import time.
	 *
	 * @param int                  $id     Entry id.
	 * @param array<string, mixed> $fields title, content, active (bool), source.
	 * @return int|\WP_Error
	 */
	public static function update( int $id, array $fields ) {
		$post = self::find( $id );
		if ( null === $post ) {
			return self::missing();
		}
		$valid = self::validate(
			array_key_exists( 'title', $fields ) ? (string) $fields['title'] : $post->post_title,
			array_key_exists( 'content', $fields ) ? (string) $fields['content'] : $post->post_content
		);
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$source = null;
		if ( array_key_exists( 'source', $fields ) ) {
			$source = self::clean_source( (string) $fields['source'] );
			if ( is_wp_error( $source ) ) {
				return $source;
			}
		}
		$data = array(
			'ID'           => $id,
			'post_title'   => $valid['title'],
			'post_content' => $valid['content'],
		);
		if ( array_key_exists( 'active', $fields ) ) {
			$data['post_status'] = $fields['active'] ? 'publish' : 'draft';
		}
		$result = self::insert( $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( null !== $source ) {
			self::set_source( $id, $source );
		}
		return $id;
	}

	/**
	 * Delete an entry for good (with its revisions).
	 *
	 * @param int $id Entry id.
	 * @return true|\WP_Error
	 */
	public static function delete( int $id ) {
		if ( null === self::find( $id ) ) {
			return self::missing();
		}
		return wp_delete_post( $id, true ) ? true : new \WP_Error( 'delete_failed', __( 'The entry could not be deleted.', 'sigma' ), array( 'status' => 500 ) );
	}

	/**
	 * Make an entry active or a draft.
	 *
	 * @param int  $id     Entry id.
	 * @param bool $active Active.
	 * @return int|\WP_Error
	 */
	public static function set_active( int $id, bool $active ) {
		return self::update( $id, array( 'active' => $active ) );
	}

	/**
	 * An entry post, or null (any other post type is not an entry).
	 *
	 * @param int $id Entry id.
	 */
	public static function find( int $id ): ?\WP_Post {
		$post = $id > 0 ? get_post( $id ) : null;
		return $post instanceof \WP_Post && self::POST_TYPE === $post->post_type && in_array( $post->post_status, array( 'publish', 'draft' ), true ) ? $post : null;
	}

	/**
	 * An entry as the admin REST returns it (§7.6).
	 *
	 * @param \WP_Post $post         Entry.
	 * @param bool     $with_content Include the text.
	 * @return array<string, mixed>
	 */
	public static function to_array( \WP_Post $post, bool $with_content = true ): array {
		$imported = (string) get_post_meta( $post->ID, self::META_IMPORTED, true );
		$entry    = array(
			'id'          => (int) $post->ID,
			'title'       => (string) $post->post_title,
			'status'      => 'publish' === $post->post_status ? 'active' : 'draft',
			'source'      => (string) get_post_meta( $post->ID, self::META_SOURCE, true ),
			'imported_at' => '' !== $imported ? $imported : null,
			'updated_at'  => self::modified_gmt( $post ),
			'words'       => self::words( (string) $post->post_content ),
			'bytes'       => strlen( (string) $post->post_content ),
		);
		if ( $with_content ) {
			$entry['content'] = (string) $post->post_content;
		}
		return $entry;
	}

	/**
	 * When an entry last changed, in UTC. A draft that was never published has no GMT dates in
	 * WordPress ('0000-00-00 00:00:00'), so the local time is converted then.
	 *
	 * @param \WP_Post $post Entry.
	 */
	public static function modified_gmt( \WP_Post $post ): string {
		$gmt = (string) $post->post_modified_gmt;
		if ( '' !== $gmt && '0000-00-00 00:00:00' !== $gmt ) {
			return $gmt;
		}
		return (string) get_gmt_from_date( (string) $post->post_modified );
	}

	/**
	 * A page of entries.
	 *
	 * @param string $status   all|active|draft.
	 * @param string $search   Words in the title or text.
	 * @param int    $page     Page, from 1.
	 * @param int    $per_page At most 100.
	 * @return array{items: list<array<string, mixed>>, total: int, pages: int}
	 */
	public static function query( string $status, string $search, int $page, int $per_page ): array {
		$statuses = array(
			'active' => array( 'publish' ),
			'draft'  => array( 'draft' ),
		);
		$query    = new \WP_Query(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => $statuses[ $status ] ?? array( 'publish', 'draft' ),
				's'                => $search,
				'posts_per_page'   => max( 1, min( 100, $per_page ) ),
				'paged'            => max( 1, $page ),
				'orderby'          => array(
					'modified' => 'DESC',
					'ID'       => 'DESC',
				),
				'suppress_filters' => true,
			)
		);
		return array(
			'items' => array_map( static fn( \WP_Post $post ): array => self::to_array( $post, false ), $query->posts ),
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
		);
	}

	/**
	 * Counts for the views and the footer: entries per status, and the words of the active ones.
	 *
	 * @return array{counts: array{all: int, active: int, draft: int}, active_words: int}
	 */
	public static function summary(): array {
		$active = self::active_posts();
		$counts = wp_count_posts( self::POST_TYPE );
		$draft  = (int) ( $counts->draft ?? 0 );
		return array(
			'counts'       => array(
				'all'    => count( $active ) + $draft,
				'active' => count( $active ),
				'draft'  => $draft,
			),
			'active_words' => array_sum( array_map( static fn( \WP_Post $post ): int => self::words( (string) $post->post_content ), $active ) ),
		);
	}

	/**
	 * Active entries, oldest first (a stable order for the cached prompt prefix).
	 *
	 * @return list<\WP_Post>
	 */
	public static function active_posts(): array {
		return array_values(
			get_posts(
				array(
					'post_type'        => self::POST_TYPE,
					'post_status'      => 'publish',
					'numberposts'      => -1,
					'orderby'          => 'ID',
					'order'            => 'ASC',
					'suppress_filters' => true,
					'no_found_rows'    => true,
				)
			)
		);
	}

	/**
	 * Whether any entry is active (cheap: the post counts are cached).
	 */
	public static function has_active(): bool {
		return post_type_exists( self::POST_TYPE ) && (int) ( wp_count_posts( self::POST_TYPE )->publish ?? 0 ) > 0;
	}

	/**
	 * Every entry's id and title (any status), for the import screen's "replaces the current text".
	 *
	 * @return list<array{id: int, title: string}>
	 */
	public static function titles(): array {
		$posts = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => array( 'publish', 'draft' ),
				'numberposts'      => -1,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => true,
				'no_found_rows'    => true,
			)
		);
		return array_map(
			static fn( \WP_Post $post ): array => array(
				'id'    => (int) $post->ID,
				'title' => (string) $post->post_title,
			),
			$posts
		);
	}

	/**
	 * Import files (§7.6): each becomes one entry; a title that matches an existing entry (any status,
	 * case-insensitive, trimmed) replaces that entry's text and keeps its status. The extension, size
	 * and UTF-8 are checked again here, whatever the browser did.
	 *
	 * @param list<array{name?: mixed, title?: mixed, content?: mixed}> $files  Files.
	 * @param bool                                                      $active New entries active.
	 * @return list<array<string, mixed>>
	 */
	public static function import( array $files, bool $active ): array {
		$existing = array();
		foreach ( self::titles() as $row ) {
			$existing[ self::title_key( $row['title'] ) ] ??= $row['id'];
		}
		$results = array();
		foreach ( $files as $file ) {
			$name    = is_array( $file ) ? (string) ( $file['name'] ?? '' ) : '';
			$content = is_array( $file ) ? (string) ( $file['content'] ?? '' ) : '';
			$title   = is_array( $file ) ? (string) ( $file['title'] ?? '' ) : '';
			$result  = array(
				'name'  => $name,
				'title' => $title,
			);
			$refused = self::refusal( $name, $content );
			if ( '' !== $refused ) {
				$results[] = $result + array(
					'result' => 'refused',
					'reason' => $refused,
				);
				continue;
			}
			if ( ! mb_check_encoding( $title, 'UTF-8' ) ) {
				$results[] = $result + array(
					'result' => 'refused',
					'reason' => 'encoding',
				);
				continue;
			}
			if ( '' === self::clean_title( $title ) ) {
				$title = (string) preg_replace( '/\.(md|txt)$/i', '', basename( $name ) );
			}
			$title           = mb_substr( self::clean_title( $title ), 0, self::MAX_TITLE );
			$result['title'] = $title;
			$key             = self::title_key( $title );
			if ( isset( $existing[ $key ] ) ) {
				$id      = self::update(
					(int) $existing[ $key ],
					array(
						'content' => $content,
						'source'  => $name,
					)
				);
				$outcome = 'replaced';
			} else {
				$id      = self::create( $title, $content, $active, $name );
				$outcome = 'created';
				if ( is_int( $id ) ) {
					$existing[ $key ] = $id; // A second file with the same title replaces the first one's text.
				}
			}
			$results[] = is_wp_error( $id )
				? $result + array(
					'result' => 'refused',
					'reason' => 'empty',
				)
				: $result + array(
					'result' => $outcome,
					'id'     => $id,
				);
		}
		return $results;
	}

	/**
	 * Why a file can't be imported ('' when it can). Pure.
	 *
	 * @param string $name    File name.
	 * @param string $content Text.
	 */
	public static function refusal( string $name, string $content ): string {
		if ( ! preg_match( '/\.(md|txt)$/i', $name ) ) {
			return 'extension';
		}
		if ( strlen( $content ) > self::MAX_BYTES ) {
			return 'size';
		}
		if ( ! mb_check_encoding( $content, 'UTF-8' ) ) {
			return 'encoding';
		}
		if ( '' === trim( $content ) ) {
			return 'empty';
		}
		return '';
	}

	/**
	 * Case-insensitive, trimmed title key. Pure.
	 *
	 * @param string $title Title.
	 */
	private static function title_key( string $title ): string {
		return mb_strtolower( self::clean_title( $title ) );
	}

	/**
	 * Save a post without letting the editor's kses or other content filters change the Markdown.
	 *
	 * @param array<string, mixed> $data Post data.
	 * @return int|\WP_Error
	 */
	private static function insert( array $data ) {
		// wp_insert_post() expects slashed data and would run kses on it for users without
		// unfiltered_html; the text is never rendered, so it is stored exactly as written.
		$kses = has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $kses ) {
			kses_remove_filters();
		}
		try {
			$id = isset( $data['ID'] ) ? wp_update_post( wp_slash( $data ), true ) : wp_insert_post( wp_slash( $data ), true );
		} finally {
			if ( $kses ) {
				kses_init_filters();
			}
		}
		return is_wp_error( $id ) ? new \WP_Error( 'save_failed', $id->get_error_message(), array( 'status' => 500 ) ) : (int) $id;
	}

	/**
	 * Store the source note.
	 *
	 * @param int    $id     Entry id.
	 * @param string $source File name ('' = written here).
	 */
	private static function set_source( int $id, string $source ): void {
		update_post_meta( $id, self::META_SOURCE, $source );
		if ( '' !== $source ) {
			update_post_meta( $id, self::META_IMPORTED, gmdate( 'Y-m-d H:i:s' ) );
		} else {
			delete_post_meta( $id, self::META_IMPORTED );
		}
	}

	/**
	 * A 400 with the field named.
	 *
	 * @param string $field   Field.
	 * @param string $message Message.
	 */
	private static function invalid( string $field, string $message ): \WP_Error {
		return new \WP_Error(
			'invalid_entry',
			$message,
			array(
				'status' => 400,
				'field'  => $field,
			)
		);
	}

	/**
	 * A 404.
	 */
	private static function missing(): \WP_Error {
		return new \WP_Error( 'not_found', __( 'There is no such entry.', 'sigma' ), array( 'status' => 404 ) );
	}
}
