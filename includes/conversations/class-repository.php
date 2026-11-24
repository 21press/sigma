<?php
/**
 * The only code that touches the plugin's tables (AGENTS.md), and the MySQL named locks (§10b).
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Conversations;

use TwentyOnePress\Sigma\Schema;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables (§6); chat rows change on every poll, so there is nothing to cache.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Only the plugin's own table names are interpolated; values go through prepare().

/**
 * Conversations and messages. Rows come back as associative arrays; times are GMT 'Y-m-d H:i:s'.
 */
final class Repository {

	/** Senders a message can have (§6). */
	public const SENDERS = array( 'visitor', 'ai', 'agent', 'system' );

	/** Longest message body kept (§6). */
	public const MAX_BODY = 4000;

	/**
	 * Current GMT time in MySQL format.
	 */
	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Create a conversation.
	 *
	 * @param string $token_hash sha256 of the visitor secret.
	 * @param string $page_url   Where the bubble was opened.
	 * @param string $locale     Visitor locale.
	 * @return array<string, mixed> The new row.
	 */
	public function create( string $token_hash, string $page_url, string $locale ): array {
		global $wpdb;
		$now = self::now();
		$wpdb->insert(
			Schema::conversations(),
			array(
				'uuid'       => wp_generate_uuid4(),
				'token_hash' => $token_hash,
				'mode'       => 'ai',
				'status'     => 'open',
				'page_url'   => mb_substr( $page_url, 0, 500 ),
				'locale'     => mb_substr( $locale, 0, 20 ),
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		return (array) $this->find( (int) $wpdb->insert_id );
	}

	/**
	 * Create a conversation with a given uuid (lazy conversations, §10b): the first ask or handoff of a
	 * uuid the visitor got from POST /conversations. Two parallel first requests make one row.
	 *
	 * @param string $uuid       Uuid.
	 * @param string $token_hash sha256 of the visitor token.
	 * @param string $page_url   Where the bubble was opened.
	 * @param string $locale     Visitor locale.
	 * @return array<string, mixed>|null The row.
	 */
	public function create_with_uuid( string $uuid, string $token_hash, string $page_url, string $locale ): ?array {
		global $wpdb;
		$now = self::now();
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . Schema::conversations() . " (uuid, token_hash, mode, status, page_url, locale, created_at, updated_at) VALUES (%s, %s, 'ai', 'open', %s, %s, %s, %s)",
				$uuid,
				$token_hash,
				mb_substr( $page_url, 0, 500 ),
				mb_substr( $locale, 0, 20 ),
				$now,
				$now
			)
		);
		return $this->find_by_uuid( $uuid );
	}

	/**
	 * A conversation by id.
	 *
	 * @param int $id Id.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::conversations() . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * A conversation by its public uuid.
	 *
	 * @param string $uuid Uuid.
	 * @return array<string, mixed>|null
	 */
	public function find_by_uuid( string $uuid ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::conversations() . ' WHERE uuid = %s', $uuid ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Change columns of a conversation; updated_at follows.
	 *
	 * @param int                  $id     Id.
	 * @param array<string, mixed> $fields Columns.
	 */
	public function update( int $id, array $fields ): bool {
		global $wpdb;
		$allowed = array( 'mode', 'status', 'visitor_name', 'visitor_email', 'assigned_user_id', 'handoff_at', 'deadline_at', 'first_agent_reply_at', 'emailed_at', 'closed_at', 'email_status' );
		$fields  = array_intersect_key( $fields, array_flip( $allowed ) );

		$fields['updated_at'] = self::now();
		return false !== $wpdb->update( Schema::conversations(), $fields, array( 'id' => $id ) );
	}

	/**
	 * Move a conversation from one status to another only if it is still in the first one. One
	 * caller wins (the R2 2-minute rule relies on this, §4).
	 *
	 * @param int                  $id     Id.
	 * @param string               $from   Expected status.
	 * @param array<string, mixed> $fields Columns to set, including the new status.
	 * @return bool Whether this call made the change.
	 */
	public function transition( int $id, string $from, array $fields ): bool {
		global $wpdb;
		$allowed = array( 'mode', 'status', 'visitor_name', 'visitor_email', 'handoff_at', 'deadline_at', 'emailed_at', 'closed_at' );
		$fields  = array_intersect_key( $fields, array_flip( $allowed ) );

		$fields['updated_at'] = self::now();
		$changed              = $wpdb->update(
			Schema::conversations(),
			$fields,
			array(
				'id'     => $id,
				'status' => $from,
			)
		);
		return 1 === $changed;
	}

	/**
	 * Add a message and touch the conversation.
	 *
	 * @param int                  $conversation_id Conversation id.
	 * @param string               $sender          visitor|ai|agent|system.
	 * @param string               $body            Text (cut to 4,000 characters).
	 * @param array<string, mixed> $meta            Cited URLs, model, tokens, cost.
	 * @param int|null             $user_id         Agent user id.
	 * @return array<string, mixed> The new message.
	 */
	public function add_message( int $conversation_id, string $sender, string $body, array $meta = array(), ?int $user_id = null ): array {
		global $wpdb;
		if ( ! in_array( $sender, self::SENDERS, true ) ) {
			$sender = 'system';
		}
		$row = array(
			'conversation_id' => $conversation_id,
			'sender'          => $sender,
			'user_id'         => $user_id,
			'body'            => mb_substr( $body, 0, self::MAX_BODY ),
			'meta'            => $meta ? wp_json_encode( $meta ) : null,
			'created_at'      => self::now(),
		);
		$wpdb->insert( Schema::messages(), $row, array( '%d', '%s', '%d', '%s', '%s', '%s' ) );
		$wpdb->update( Schema::conversations(), array( 'updated_at' => $row['created_at'] ), array( 'id' => $conversation_id ) );

		$row['id'] = (int) $wpdb->insert_id;
		return self::shape_message( $row );
	}

	/**
	 * Delete one message (a visitor question whose answer failed, so a retry does not repeat it).
	 *
	 * @param int $id Message id.
	 */
	public function delete_message( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( Schema::messages(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Messages of a conversation, oldest first.
	 *
	 * @param int $conversation_id Conversation id.
	 * @param int $after           Only ids above this (poll cursor).
	 * @param int $limit           At most this many.
	 * @return list<array<string, mixed>>
	 */
	public function messages( int $conversation_id, int $after = 0, int $limit = 500 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . Schema::messages() . ' WHERE conversation_id = %d AND id > %d ORDER BY id ASC LIMIT %d', $conversation_id, $after, $limit ),
			ARRAY_A
		);
		return array_map( array( self::class, 'shape_message' ), (array) $rows );
	}

	/**
	 * The last turns of a conversation for the AI prompt, oldest first.
	 *
	 * @param int $conversation_id Conversation id.
	 * @param int $turns           Messages to keep.
	 * @return list<array<string, mixed>>
	 */
	public function recent( int $conversation_id, int $turns = 10 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . Schema::messages() . " WHERE conversation_id = %d AND sender IN ('visitor','ai') ORDER BY id DESC LIMIT %d", $conversation_id, $turns ),
			ARRAY_A
		);
		return array_reverse( array_map( array( self::class, 'shape_message' ), (array) $rows ) );
	}

	/**
	 * A page of conversations for wp-admin, newest activity first.
	 *
	 * @param array{page?: int, per_page?: int, status?: string, search?: string} $args Filters.
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function list( array $args ): array {
		global $wpdb;
		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$where    = array( '1=1' );
		$values   = array();
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'c.status = %s';
			$values[] = (string) $args['status'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(c.visitor_email LIKE %s OR c.visitor_name LIKE %s OR c.page_url LIKE %s OR EXISTS (SELECT 1 FROM ' . Schema::messages() . ' m2 WHERE m2.conversation_id = c.id AND m2.body LIKE %s))';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
		}
		$where_sql = implode( ' AND ', $where );

		$total_sql = 'SELECT COUNT(*) FROM ' . Schema::conversations() . " c WHERE {$where_sql}";
		$total     = (int) ( $values ? $wpdb->get_var( $wpdb->prepare( $total_sql, $values ) ) : $wpdb->get_var( $total_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Built from placeholders above.

		$list_sql = 'SELECT c.*, (SELECT COUNT(*) FROM ' . Schema::messages() . ' m WHERE m.conversation_id = c.id) AS message_count,'
			. ' (SELECT m3.body FROM ' . Schema::messages() . " m3 WHERE m3.conversation_id = c.id AND m3.sender = 'visitor' ORDER BY m3.id ASC LIMIT 1) AS first_question"
			. ' FROM ' . Schema::conversations() . " c WHERE {$where_sql} ORDER BY c.updated_at DESC, c.id DESC LIMIT %d OFFSET %d";
		$rows     = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $values, array( $per_page, ( $page - 1 ) * $per_page ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Built from placeholders above.

		return array(
			'items' => array_map( array( self::class, 'shape_conversation' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Claim the handoff of a conversation for one idempotency key, atomically (§8, v1.7.1): only the
	 * first request whose UPDATE finds handoff_key empty wins, so two parallel requests send one email.
	 *
	 * @param int                  $id     Conversation id.
	 * @param string               $key    Idempotency key.
	 * @param array<string, mixed> $fields Columns to set with the claim.
	 * @return bool Whether this call claimed it.
	 */
	public function claim_handoff( int $id, string $key, array $fields ): bool {
		global $wpdb;
		$allowed = array( 'mode', 'status', 'visitor_name', 'visitor_email', 'handoff_at', 'email_status' );
		$fields  = array_intersect_key( $fields, array_flip( $allowed ) );
		$sets    = array( 'handoff_key = %s', 'updated_at = %s' );
		$values  = array( $key, self::now() );
		foreach ( $fields as $column => $value ) {
			$sets[]   = "{$column} = %s";
			$values[] = (string) $value;
		}
		$values[] = $id;
		$changed  = $wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::conversations() . ' SET ' . implode( ', ', $sets ) . ' WHERE id = %d AND handoff_key IS NULL', $values ) );
		return 1 === $changed;
	}

	/**
	 * A send that died mid-way (PHP fatal, worker killed) leaves email_status "sending". After 2
	 * minutes no live request can still own it (SMTP gives up within about 40 s), so it is failed:
	 * visible to Retry send and the admin notice.
	 *
	 * @return int Rows marked failed.
	 */
	public function fail_stale_sending(): int {
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare( 'UPDATE ' . Schema::conversations() . " SET email_status = 'failed' WHERE email_status = 'sending' AND handoff_at < %s", gmdate( 'Y-m-d H:i:s', time() - 2 * MINUTE_IN_SECONDS ) )
		);
	}

	/**
	 * Handoffs whose support email failed (§10a).
	 */
	public function count_email_failed(): int {
		global $wpdb;
		$this->fail_stale_sending();
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::conversations() . " WHERE email_status = 'failed'" );
	}

	/**
	 * Conversations of a visitor email (privacy exporter and eraser).
	 *
	 * @param string $email Email.
	 * @return list<array<string, mixed>>
	 */
	public function find_by_email( string $email ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::conversations() . ' WHERE visitor_email = %s ORDER BY id ASC', $email ), ARRAY_A );
		return (array) $rows;
	}

	/**
	 * Delete conversations and their messages.
	 *
	 * @param int[] $ids Conversation ids.
	 * @return int Conversations deleted.
	 */
	public function delete( array $ids ): int {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}
		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::messages() . " WHERE conversation_id IN ({$in})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in is a list of %d.
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::conversations() . " WHERE id IN ({$in})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in is a list of %d.
	}

	/**
	 * Ids of conversations whose last activity is older than a time (retention purge).
	 *
	 * @param string $before GMT time.
	 * @param int    $limit  Batch size.
	 * @return list<int>
	 */
	public function stale_ids( string $before, int $limit = 500 ): array {
		global $wpdb;
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Schema::conversations() . ' WHERE updated_at < %s ORDER BY id ASC LIMIT %d', $before, $limit ) ) );
	}

	/**
	 * Ids of conversations without any message, created before a time (§10b: rows a first ask made
	 * and then failed, or from before 0.1.7, when POST /conversations still wrote a row).
	 *
	 * @param string $before GMT time.
	 * @param int    $limit  Batch size.
	 * @return list<int>
	 */
	public function empty_ids( string $before, int $limit = 500 ): array {
		global $wpdb;
		return array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare( 'SELECT c.id FROM ' . Schema::conversations() . ' c WHERE c.created_at < %s AND NOT EXISTS (SELECT 1 FROM ' . Schema::messages() . ' m WHERE m.conversation_id = c.id) ORDER BY c.id ASC LIMIT %d', $before, $limit )
			)
		);
	}

	/**
	 * Messages of a conversation in pages of 500 (privacy exporter), oldest first.
	 *
	 * @param int $conversation_id Conversation id.
	 * @param int $page            1-based page.
	 * @param int $per_page        Page size.
	 * @return list<array<string, mixed>>
	 */
	public function messages_page( int $conversation_id, int $page, int $per_page = 500 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . Schema::messages() . ' WHERE conversation_id = %d ORDER BY id ASC LIMIT %d OFFSET %d', $conversation_id, $per_page, max( 0, $page - 1 ) * $per_page ),
			ARRAY_A
		);
		return array_map( array( self::class, 'shape_message' ), (array) $rows );
	}

	// ----- Counters (§10b): one atomic statement per hit, so parallel requests never both pass. -----

	/**
	 * Add $by to a counter in a fixed window and return its new value and expiry. A window that has
	 * ended starts again at $by. The value comes back through LAST_INSERT_ID(), so it is exactly this
	 * request's result even under parallel hits.
	 *
	 * @param string $bucket Counter name (at most 100 characters).
	 * @param int    $window Window in seconds.
	 * @param int    $by     Amount (default 1).
	 * @return array{n: int, expires: int}
	 */
	public static function counter_add( string $bucket, int $window, int $by = 1 ): array {
		global $wpdb;
		$bucket  = substr( $bucket, 0, 100 );
		$now     = gmdate( 'Y-m-d H:i:s' );
		$expires = gmdate( 'Y-m-d H:i:s', time() + max( 1, $window ) );
		$by      = max( 0, $by );
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . Schema::counters() . ' (bucket, n, expires_at) VALUES (%s, LAST_INSERT_ID(%d), %s)'
				. ' ON DUPLICATE KEY UPDATE n = LAST_INSERT_ID(IF(expires_at <= %s, %d, n + %d)), expires_at = IF(expires_at <= %s, %s, expires_at)',
				$bucket,
				$by,
				$expires,
				$now,
				$by,
				$by,
				$now,
				$expires
			)
		);
		$n   = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
		$exp = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT expires_at FROM ' . Schema::counters() . ' WHERE bucket = %s', $bucket ) );
		return array(
			'n'       => $n,
			'expires' => '' !== $exp ? (int) strtotime( $exp . ' UTC' ) : time() + $window,
		);
	}

	/**
	 * Take one back (a refund: nothing reached the provider), never below 0, only inside the window.
	 *
	 * @param string $bucket Counter name.
	 */
	public static function counter_refund( string $bucket ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::counters() . ' SET n = IF(n > 0, n - 1, 0) WHERE bucket = %s AND expires_at > %s', substr( $bucket, 0, 100 ), gmdate( 'Y-m-d H:i:s' ) ) );
	}

	/**
	 * A counter's value now (0 when missing or expired).
	 *
	 * @param string $bucket Counter name.
	 */
	public static function counter_get( string $bucket ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT n FROM ' . Schema::counters() . ' WHERE bucket = %s AND expires_at > %s', substr( $bucket, 0, 100 ), gmdate( 'Y-m-d H:i:s' ) ) );
	}

	/**
	 * Values of counters whose name starts with a prefix (usage summary).
	 *
	 * @param string $prefix Name prefix.
	 * @return array<string, int>
	 */
	public static function counters_like( string $prefix ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT bucket, n FROM ' . Schema::counters() . ' WHERE bucket LIKE %s AND expires_at > %s', $wpdb->esc_like( $prefix ) . '%', gmdate( 'Y-m-d H:i:s' ) ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['bucket'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Delete a counter (a released fallback slot).
	 *
	 * @param string $bucket Counter name.
	 */
	public static function counter_delete( string $bucket ): void {
		global $wpdb;
		$wpdb->delete( Schema::counters(), array( 'bucket' => substr( $bucket, 0, 100 ) ), array( '%s' ) );
	}

	/**
	 * Delete expired counters (daily purge).
	 *
	 * @return int Rows deleted.
	 */
	public static function purge_counters(): int {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::counters() . ' WHERE expires_at <= %s', gmdate( 'Y-m-d H:i:s' ) ) );
	}

	/**
	 * Try a MySQL named lock without waiting (§10b concurrency). True: held by this connection until
	 * released or the connection ends (crash-safe). False: held elsewhere. Null: not available.
	 *
	 * @param string $name Lock name (at most 64 characters).
	 */
	public static function try_lock( string $name ): ?bool {
		global $wpdb;
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', substr( $name, 0, 64 ) ) );
		if ( null === $got ) {
			return null;
		}
		return '1' === (string) $got;
	}

	/**
	 * Release a named lock held by this connection.
	 *
	 * @param string $name Lock name.
	 */
	public static function release_lock( string $name ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', substr( $name, 0, 64 ) ) );
	}

	/**
	 * A conversation row as the API returns it (never the token hash).
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	public static function shape_conversation( array $row ): array {
		unset( $row['token_hash'] );
		foreach ( array( 'id', 'assigned_user_id', 'message_count' ) as $int ) {
			if ( isset( $row[ $int ] ) ) {
				$row[ $int ] = (int) $row[ $int ];
			}
		}
		return $row;
	}

	/**
	 * A message row as the API returns it, meta decoded.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	public static function shape_message( array $row ): array {
		$meta = is_string( $row['meta'] ?? null ) ? json_decode( $row['meta'], true ) : ( $row['meta'] ?? null );
		return array(
			'id'              => (int) $row['id'],
			'conversation_id' => (int) $row['conversation_id'],
			'sender'          => (string) $row['sender'],
			'user_id'         => isset( $row['user_id'] ) ? (int) $row['user_id'] : null,
			'body'            => (string) $row['body'],
			'meta'            => is_array( $meta ) ? $meta : array(),
			'created_at'      => (string) $row['created_at'],
		);
	}
}
