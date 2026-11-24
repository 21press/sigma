<?php
/**
 * Retention purge, personal data exporter and eraser, privacy policy text (§11).
 *
 * @package TwentyOnePress\Sigma
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Privacy;

use TwentyOnePress\Sigma\Conversations\Repository;
use TwentyOnePress\Sigma\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Conversations are keyed by the visitor's email for export and erasure. An anonymous AI chat has
 * no email; it is removed by the retention purge.
 */
final class Privacy {

	/** A follow-up purge run when one run had more to delete than its bound. */
	public const CRON_MORE = 'delta_assistant_purge_more';


	/**
	 * Hooks.
	 */
	public static function hooks(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'register_eraser' ) );
		add_action( 'admin_init', array( self::class, 'policy_text' ) );
	}

	/**
	 * Delete conversations whose last activity is older than the retention period.
	 *
	 * @return int Conversations deleted.
	 */
	public static function purge(): int {
		$repo   = new Repository();
		$before = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS * max( 1, (int) Settings::get( 'retention_days' ) ) );
		$empty  = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$total  = 0;
		$more   = false;
		// Bounded work per run; what is left goes on in a follow-up run a few minutes later (§10b).
		for ( $i = 0; $i < 20; $i++ ) {
			$ids = $repo->stale_ids( $before, 500 );
			if ( ! $ids ) {
				break;
			}
			$total += $repo->delete( $ids );
			$more   = 19 === $i;
		}
		// Conversations without a message after 24 h (§10b): a first ask that failed, or rows from
		// before 0.1.7, when POST /conversations still wrote one for anyone.
		for ( $i = 0; $i < 20; $i++ ) {
			$ids = $repo->empty_ids( $empty, 500 );
			if ( ! $ids ) {
				break;
			}
			$total += $repo->delete( $ids );
			$more   = $more || 19 === $i;
		}
		Repository::purge_counters();
		if ( $more && ! wp_next_scheduled( self::CRON_MORE ) ) {
			wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::CRON_MORE );
		}
		return $total;
	}

	/**
	 * Register the exporter.
	 *
	 * @param array<string, mixed> $exporters Exporters.
	 * @return array<string, mixed>
	 */
	public static function register_exporter( array $exporters ): array {
		$exporters['sigma'] = array(
			'exporter_friendly_name' => __( 'Sigma chats', 'sigma' ),
			'callback'               => array( self::class, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array<string, mixed> $erasers Erasers.
	 * @return array<string, mixed>
	 */
	public static function register_eraser( array $erasers ): array {
		$erasers['sigma'] = array(
			'eraser_friendly_name' => __( 'Sigma chats', 'sigma' ),
			'callback'             => array( self::class, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Export the chats of an email.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page (one page holds everything).
	 * @return array{data: list<array<string, mixed>>, done: bool}
	 */
	public static function export( $email, $page = 1 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress passes the page; one page holds everything.
		$repo = new Repository();
		$data = array();
		foreach ( $repo->find_by_email( (string) $email ) as $conversation ) {
			$transcript = array();
			// Every message, in batches of 500 (§10b: no cap at 500).
			for ( $batch = 1; ; $batch++ ) {
				$messages = $repo->messages_page( (int) $conversation['id'], $batch, 500 );
				foreach ( $messages as $message ) {
					$transcript[] = sprintf( '[%s UTC] %s: %s', $message['created_at'], $message['sender'], $message['body'] );
				}
				if ( count( $messages ) < 500 ) {
					break;
				}
			}
			$data[] = array(
				'group_id'    => 'sigma',
				'group_label' => __( 'Help chats', 'sigma' ),
				'item_id'     => 'sigma-' . $conversation['uuid'],
				'data'        => array(
					array(
						'name'  => __( 'Name', 'sigma' ),
						'value' => (string) $conversation['visitor_name'],
					),
					array(
						'name'  => __( 'Email', 'sigma' ),
						'value' => (string) $conversation['visitor_email'],
					),
					array(
						'name'  => __( 'Started', 'sigma' ),
						'value' => (string) $conversation['created_at'] . ' UTC',
					),
					array(
						'name'  => __( 'Page', 'sigma' ),
						'value' => (string) $conversation['page_url'],
					),
					array(
						'name'  => __( 'Transcript', 'sigma' ),
						'value' => implode( "\n", $transcript ),
					),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Erase the chats of an email (whole conversations: the messages are the personal data).
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
	 */
	public static function erase( $email, $page = 1 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- As export().
		$repo    = new Repository();
		$ids     = array_map( static fn( array $c ): int => (int) $c['id'], $repo->find_by_email( (string) $email ) );
		$removed = $repo->delete( $ids );
		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Suggested privacy policy text, naming the AI provider as a processor.
	 */
	public static function policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$provider = match ( (string) Settings::get( 'provider' ) ) {
			'openai' => 'OpenAI',
			default  => 'Anthropic',
		};
		$days = (int) Settings::get( 'retention_days' );
		$text = '<p>' . sprintf(
			/* translators: 1: AI provider, 2: days. */
			esc_html__( 'Our help chat answers questions with AI using our public documentation. The text you type in the chat is sent to %1$s, which processes it on our behalf to write an answer; your name and email are not sent to them. Chats are stored on this site for %2$d days after the last message, then deleted.', 'sigma' ),
			esc_html( $provider ),
			$days
		) . '</p>' . ( Settings::get( 'voice_input' ) ? '<p>' . esc_html__( 'You can dictate a question instead of typing it. Your browser turns your speech into text; in some browsers (such as Chrome and Edge) the audio is sent to the browser maker\'s speech service (Google or Microsoft) for this. This site never receives the audio, only the text you choose to send.', 'sigma' ) . '</p>' : '' ) . '<p>' . esc_html__( 'If you ask to talk to a human, we store your name and email with the chat and email the conversation to our support team, who reply to you by email. You can ask us to export or erase your chats.', 'sigma' ) . '</p><p>' . esc_html__( 'While you chat, your browser keeps the conversation in this tab\'s session storage so it stays visible as you move between pages. It is removed when you close the tab.', 'sigma' ) . '</p>';
		wp_add_privacy_policy_content( __( 'Sigma', 'sigma' ), wp_kses_post( $text ) );
	}
}
