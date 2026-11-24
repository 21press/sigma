<?php
/**
 * Retrieval mode (§7.4): chunks, BM25 ranking, the doc map.
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Used when the whole corpus is over the context budget. Each doc is split at `##` headings; a
 * chunk over 1,500 tokens is split again at `###`, then at paragraphs (code fences stay whole).
 * Chunks are ranked with BM25 in PHP, the title weighted ×3 and the heading ×2 over the body, and
 * the best ones up to the retrieval budget go into the prompt, grouped back under their doc.
 *
 * Everything here is pure: the unit layer tests it.
 */
final class Retriever {

	/** Largest chunk before it is split again (§7.4). */
	public const MAX_CHUNK_TOKENS = 1500;

	/** BM25 parameters. */
	private const K1 = 1.2;
	private const B  = 0.75;

	/** Field weights (§7.4). */
	private const W_TITLE   = 3;
	private const W_HEADING = 2;

	/** Words that carry no topic. */
	private const STOP = array( 'a', 'an', 'and', 'are', 'as', 'at', 'be', 'but', 'by', 'can', 'could', 'do', 'does', 'for', 'from', 'get', 'has', 'have', 'how', 'i', 'if', 'in', 'into', 'is', 'it', 'its', 'me', 'my', 'no', 'not', 'of', 'on', 'or', 'our', 'so', 'than', 'that', 'the', 'their', 'them', 'then', 'there', 'these', 'this', 'to', 'up', 'us', 'was', 'we', 'what', 'when', 'where', 'which', 'who', 'why', 'will', 'with', 'would', 'you', 'your' );

	/**
	 * Split docs into chunks.
	 *
	 * @param list<array{url: string, title: string, updated: string, text: string}> $docs            Docs.
	 * @param float                                                                  $chars_per_token Calibration for token estimates.
	 * @return list<array{doc: int, heading: string, text: string, tokens: int}>
	 */
	public static function chunk( array $docs, float $chars_per_token ): array {
		$max    = (int) floor( self::MAX_CHUNK_TOKENS * $chars_per_token );
		$chunks = array();
		foreach ( array_values( $docs ) as $i => $doc ) {
			foreach ( self::split_at( (string) $doc['text'], '## ' ) as [ $h2, $section ] ) {
				$parts = strlen( $section ) > $max ? self::split_at( $section, '### ' ) : array( array( '', $section ) );
				foreach ( $parts as [ $h3, $part ] ) {
					$heading = trim( $h2 . ( '' !== $h3 ? ' > ' . $h3 : '' ) );
					foreach ( strlen( $part ) > $max ? self::split_paragraphs( $part, $max ) : array( $part ) as $piece ) {
						$piece = trim( $piece );
						if ( '' === $piece ) {
							continue;
						}
						$chunks[] = array(
							'doc'     => $i,
							'heading' => $heading,
							'text'    => $piece,
							'tokens'  => (int) ceil( strlen( $piece ) / $chars_per_token ),
						);
					}
				}
			}
		}
		return $chunks;
	}

	/**
	 * Split a text at lines starting with a marker (`## ` or `### `), outside code fences. The text
	 * before the first marker is a part with an empty heading. Each part keeps its heading line.
	 *
	 * @param string $text   Text.
	 * @param string $marker Line prefix.
	 * @return list<array{0: string, 1: string}> [ heading, text ] pairs.
	 */
	private static function split_at( string $text, string $marker ): array {
		$parts   = array();
		$heading = '';
		$buffer  = array();
		$fenced  = false;
		foreach ( explode( "\n", $text ) as $line ) {
			if ( str_starts_with( $line, '```' ) ) {
				$fenced = ! $fenced;
			}
			if ( ! $fenced && str_starts_with( $line, $marker ) ) {
				if ( $buffer ) {
					$parts[] = array( $heading, implode( "\n", $buffer ) );
				}
				$heading = trim( substr( $line, strlen( $marker ) ) );
				$buffer  = array( $line );
				continue;
			}
			$buffer[] = $line;
		}
		if ( $buffer ) {
			$parts[] = array( $heading, implode( "\n", $buffer ) );
		}
		return $parts;
	}

	/**
	 * Pack paragraphs (blank-line separated, code fences whole) into pieces of at most $max bytes.
	 * A single paragraph longer than $max is its own piece.
	 *
	 * @param string $text Text.
	 * @param int    $max  Max bytes.
	 * @return list<string>
	 */
	private static function split_paragraphs( string $text, int $max ): array {
		$paragraphs = array();
		$current    = '';
		$fenced     = false;
		foreach ( explode( "\n", $text ) as $line ) {
			if ( str_starts_with( $line, '```' ) ) {
				$fenced = ! $fenced;
			}
			if ( ! $fenced && '' === trim( $line ) ) {
				if ( '' !== $current ) {
					$paragraphs[] = $current;
					$current      = '';
				}
				continue;
			}
			$current .= ( '' === $current ? '' : "\n" ) . $line;
		}
		if ( '' !== $current ) {
			$paragraphs[] = $current;
		}

		$pieces = array();
		$piece  = '';
		foreach ( $paragraphs as $p ) {
			if ( '' !== $piece && strlen( $piece ) + 2 + strlen( $p ) > $max ) {
				$pieces[] = $piece;
				$piece    = '';
			}
			$piece .= ( '' === $piece ? '' : "\n\n" ) . $p;
		}
		if ( '' !== $piece ) {
			$pieces[] = $piece;
		}
		return $pieces;
	}

	/**
	 * Lowercase content words, with a light plural/verb stem so "snippets" finds "snippet".
	 *
	 * @param string $text Text.
	 * @return list<string>
	 */
	public static function terms( string $text ): array {
		preg_match_all( '/[\p{L}\p{N}][\p{L}\p{N}_\-]*/u', mb_strtolower( $text ), $m );
		$out = array();
		foreach ( $m[0] as $word ) {
			if ( mb_strlen( $word ) < 2 || in_array( $word, self::STOP, true ) ) {
				continue;
			}
			$out[] = self::stem( $word );
		}
		return $out;
	}

	/**
	 * A very small stemmer: plurals only ("snippets" → "snippet", "entries" → "entry"). Stripping
	 * -ed/-ing/-es mangles more words than it joins ("cached" vs "cache"), so it does not.
	 *
	 * @param string $word Lowercase word.
	 */
	private static function stem( string $word ): string {
		foreach ( array(
			'ies' => 'y',
			's'   => '',
		) as $suffix => $replace ) {
			if ( mb_strlen( $word ) > strlen( $suffix ) + 3 && str_ends_with( $word, $suffix ) && ! str_ends_with( $word, 'ss' ) ) {
				return substr( $word, 0, -strlen( $suffix ) ) . $replace;
			}
		}
		return $word;
	}

	/**
	 * Weighted term frequencies of a chunk: title ×3, heading ×2, body ×1.
	 *
	 * @param array<string, mixed> $chunk Chunk.
	 * @param string               $title Its doc's title.
	 * @return array<string, int>
	 */
	private static function weighted_tf( array $chunk, string $title ): array {
		$tf = array();
		foreach ( array(
			array( $title, self::W_TITLE ),
			array( (string) $chunk['heading'], self::W_HEADING ),
			array( (string) $chunk['text'], 1 ),
		) as [ $field, $weight ] ) {
			foreach ( self::terms( $field ) as $term ) {
				$tf[ $term ] = ( $tf[ $term ] ?? 0 ) + $weight;
			}
		}
		return $tf;
	}

	/**
	 * BM25 statistics of a chunk set: document frequency of each term, average weighted length.
	 *
	 * @param list<array<string, mixed>> $chunks Chunks.
	 * @param list<array<string, mixed>> $docs   Docs (for titles).
	 * @return array{n: int, avgdl: float, df: array<string, int>}
	 */
	public static function stats( array $chunks, array $docs ): array {
		$df    = array();
		$total = 0;
		foreach ( $chunks as $chunk ) {
			$tf     = self::weighted_tf( $chunk, (string) ( $docs[ $chunk['doc'] ]['title'] ?? '' ) );
			$total += array_sum( $tf );
			foreach ( array_keys( $tf ) as $term ) {
				$df[ $term ] = ( $df[ $term ] ?? 0 ) + 1;
			}
		}
		return array(
			'n'     => count( $chunks ),
			'avgdl' => $chunks ? $total / count( $chunks ) : 0.0,
			'df'    => $df,
		);
	}

	/**
	 * The best chunks for a query, up to a token budget, in document order.
	 *
	 * @param list<array<string, mixed>>                          $chunks Chunks.
	 * @param list<array<string, mixed>>                          $docs   Docs.
	 * @param array{n: int, avgdl: float, df: array<string, int>} $stats  stats() of the chunks.
	 * @param string                                              $query  Question (+ previous question).
	 * @param int                                                 $budget Token budget.
	 * @return list<array<string, mixed>> Chosen chunks, each with its score.
	 */
	public static function select( array $chunks, array $docs, array $stats, string $query, int $budget ): array {
		$terms = array_unique( self::terms( $query ) );
		if ( ! $terms || ! $chunks ) {
			return array();
		}
		$n      = max( 1, (int) $stats['n'] );
		$avgdl  = max( 1.0, (float) $stats['avgdl'] );
		$scored = array();
		foreach ( $chunks as $index => $chunk ) {
			$tf    = self::weighted_tf( $chunk, (string) ( $docs[ $chunk['doc'] ]['title'] ?? '' ) );
			$len   = array_sum( $tf );
			$score = 0.0;
			foreach ( $terms as $term ) {
				if ( empty( $tf[ $term ] ) ) {
					continue;
				}
				$df     = (int) ( $stats['df'][ $term ] ?? 0 );
				$idf    = log( 1 + ( $n - $df + 0.5 ) / ( $df + 0.5 ) );
				$f      = $tf[ $term ];
				$score += $idf * ( $f * ( self::K1 + 1 ) ) / ( $f + self::K1 * ( 1 - self::B + self::B * $len / $avgdl ) );
			}
			if ( $score > 0 ) {
				$scored[ $index ] = $score;
			}
		}
		arsort( $scored );

		$chosen = array();
		$used   = 0;
		foreach ( $scored as $index => $score ) {
			$tokens = (int) $chunks[ $index ]['tokens'];
			if ( $used + $tokens > $budget ) {
				continue;
			}
			$used            += $tokens;
			$chosen[ $index ] = $chunks[ $index ] + array( 'score' => round( $score, 3 ) );
		}
		ksort( $chosen );
		return array_values( $chosen );
	}

	/**
	 * Chosen chunks grouped back under their doc tag, in document order.
	 *
	 * @param list<array<string, mixed>> $chosen Chunks from select().
	 * @param list<array<string, mixed>> $docs   Docs.
	 */
	public static function docs_block( array $chosen, array $docs ): string {
		$by_doc = array();
		foreach ( $chosen as $chunk ) {
			$by_doc[ (int) $chunk['doc'] ][] = (string) $chunk['text'];
		}
		ksort( $by_doc );
		$out = array();
		foreach ( $by_doc as $i => $texts ) {
			$doc   = $docs[ $i ];
			$out[] = sprintf(
				"<doc url=\"%s\" title=\"%s\" updated=\"%s\">\n%s\n</doc>",
				htmlspecialchars( (string) $doc['url'], ENT_QUOTES, 'UTF-8' ),
				htmlspecialchars( (string) $doc['title'], ENT_QUOTES, 'UTF-8' ),
				htmlspecialchars( (string) $doc['updated'], ENT_QUOTES, 'UTF-8' ),
				str_replace( '</doc>', '&lt;/doc>', implode( "\n\n[…]\n\n", $texts ) )
			);
		}
		return implode( "\n\n", $out );
	}

	/**
	 * Every doc's title and URL, one per line (§7.4 b). Stable, so it sits in the cached prefix.
	 *
	 * @param list<array<string, mixed>> $docs Docs.
	 */
	public static function doc_map( array $docs ): string {
		$lines = array();
		foreach ( $docs as $doc ) {
			$lines[] = '- ' . str_replace( "\n", ' ', (string) $doc['title'] ) . ': ' . $doc['url'];
		}
		return implode( "\n", $lines );
	}
}
