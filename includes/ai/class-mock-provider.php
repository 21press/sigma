<?php
/**
 * A provider with no AI, for local sites and tests.
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the doc whose words best match the question and quotes its start with a link. No network,
 * no cost. Offered only when wp_get_environment_type() is local or development (Settings), so the
 * widget can be built and tested without a provider key.
 */
final class Mock_Provider implements Streaming_Provider {

	/** Words that say nothing about a topic. */
	private const STOP = array( 'the', 'a', 'an', 'and', 'or', 'of', 'to', 'in', 'on', 'for', 'is', 'are', 'it', 'i', 'my', 'me', 'do', 'does', 'how', 'what', 'can', 'with', 'you', 'your', 'this', 'that', 'be', 'from', 'at', 'by', 'as', 'there', 'way', 'which', 'when', 'where', 'why', 'will', 'should', 'need', 'get', 'use', 'using', 'about', 'delta' );

	/**
	 * Provider id.
	 */
	public function id(): string {
		return 'mock';
	}

	/**
	 * Answer from the best-matching doc.
	 *
	 * @param Prompt                                     $prompt   System prompt (the corpus is in it).
	 * @param list<array{role: string, content: string}> $messages Turns.
	 * @param array<string, mixed>                       $options  Unused.
	 * @return Answer
	 */
	public function answer( Prompt $prompt, array $messages, array $options ) {
		$question = (string) ( end( $messages )['content'] ?? '' );
		$words    = self::words( $question );

		preg_match_all( '#<doc url="([^"]*)" title="([^"]*)" updated="[^"]*">\n(.*?)\n</doc>#s', $prompt->cached . "\n" . $prompt->dynamic, $docs, PREG_SET_ORDER );
		// Knowledge entries (§7.6) compete too, with a small head start: they win a tie, as the rules say.
		preg_match_all( '#<doc kind="knowledge" id="(kb-\d+)" title="([^"]*)" updated="[^"]*">\n(.*?)\n</doc>#s', $prompt->cached, $notes, PREG_SET_ORDER );
		$best  = null;
		$score = 0.0;
		foreach ( array_merge( $notes, $docs ) as $doc ) {
			$title = self::words( html_entity_decode( $doc[2], ENT_QUOTES, 'UTF-8' ) );
			$body  = array_count_values( self::words( $doc[3] ) );
			$s     = str_starts_with( $doc[1], 'kb-' ) ? 0.5 : 0.0;
			foreach ( array_unique( $words ) as $word ) {
				$s += in_array( $word, $title, true ) ? 3.0 : 0.0;
				$s += isset( $body[ $word ] ) ? min( 3.0, log( 1 + $body[ $word ] ) ) : 0.0;
			}
			if ( $s > $score ) {
				$score = $s;
				$best  = $doc;
			}
		}

		if ( Answerer::asks_for_human( $question ) ) {
			$text = "I can connect you with the team: they reply by email.\n\n_Test provider: no AI was used._\n" . Answerer::HUMAN_REQUESTED;
		} elseif ( null === $best || $score < 3.0 ) {
			$text = "[[NOT_IN_DOCS]]I don't know: I could not find that in the docs. Would you like to talk to a human?\n\n_Test provider: no AI was used._";
		} elseif ( str_starts_with( $best[1], 'kb-' ) ) {
			// A knowledge entry: quoted with no link (it has no URL), and listed in the sources marker.
			$title   = html_entity_decode( $best[2], ENT_QUOTES, 'UTF-8' );
			$excerpt = mb_substr( trim( (string) preg_replace( "/\n{2,}/", "\n\n", (string) preg_replace( '/^#+ .*$/m', '', $best[3] ) ) ), 0, 420 );
			$text    = "From our notes on **{$title}**:\n\n{$excerpt}\n\n_Test provider: no AI was used._\n[[SOURCES: {$best[1]}]]";
		} else {
			$title   = html_entity_decode( $best[2], ENT_QUOTES, 'UTF-8' );
			$url     = html_entity_decode( $best[1], ENT_QUOTES, 'UTF-8' );
			$excerpt = trim( (string) preg_replace( '/^## .*$/m', '', $best[3] ) );
			$excerpt = mb_substr( (string) preg_replace( "/\n{2,}/", "\n\n", $excerpt ), 0, 420 );
			$text    = "From **{$title}**:\n\n{$excerpt}…\n\nRead more: [{$title}]({$url})\n\n_Test provider: no AI was used._";
		}
		return new Answer(
			$text,
			'mock',
			array(
				'input'       => 0,
				'cache_write' => 0,
				'cache_read'  => 0,
				'output'      => 0,
			)
		);
	}

	/**
	 * The test provider always streams (no network).
	 */
	public function can_stream(): bool {
		return true;
	}

	/**
	 * Stream the same answer in small pieces, about 25 a second, so the widget can be built and tested
	 * against a real stream without an AI.
	 *
	 * @param Prompt                                     $prompt   System prompt.
	 * @param list<array{role: string, content: string}> $messages Turns.
	 * @param array<string, mixed>                       $options  Unused.
	 * @param callable(string): void                     $on_text  Text deltas.
	 * @param callable(): void                           $on_tick  Unused.
	 * @return Answer
	 */
	public function stream( Prompt $prompt, array $messages, array $options, callable $on_text, callable $on_tick ) {
		$answer = $this->answer( $prompt, $messages, $options );
		/**
		 * Filters the pause between test-provider pieces, in microseconds (tests use 0).
		 *
		 * @param int $pause Microseconds.
		 */
		$pause = (int) \TwentyOnePress\Sigma\Hooks::filter( 'mock_stream_pause', 40000 );
		foreach ( mb_str_split( $answer->text, 12 ) as $piece ) {
			$on_text( $piece );
			if ( $pause > 0 ) {
				usleep( $pause );
			}
		}
		return $answer;
	}

	/**
	 * Lowercase content words of a text.
	 *
	 * @param string $text Text.
	 * @return list<string>
	 */
	private static function words( string $text ): array {
		preg_match_all( '/[a-z0-9][a-z0-9\-]{1,}/', strtolower( $text ), $m );
		return array_values( array_diff( $m[0], self::STOP ) );
	}
}
