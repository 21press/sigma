<?php
/**
 * @package TwentyOnePress\Sigma\Tests
 */

declare( strict_types=1 );

namespace TwentyOnePress\Sigma\Tests\Integration;

use TwentyOnePress\Sigma\Conversations\Repository;
use TwentyOnePress\Sigma\Privacy\Privacy;
use TwentyOnePress\Sigma\Schema;
use TwentyOnePress\Sigma\Tests\Support\Integration_Test_Case;

/**
 * Retention purge, exporter, eraser, and the tables themselves (§6, §11).
 *
 * @covers \TwentyOnePress\Sigma\Privacy\Privacy
 * @covers \TwentyOnePress\Sigma\Schema
 */
final class Privacy_Test extends Integration_Test_Case {

	private function handed_off( string $email ): string {
		[ $uuid, $token ] = $this->conversation();
		$this->rest( 'POST', "/conversations/{$uuid}/handoff", array( 'name' => 'Ann', 'email' => $email, 'text' => 'My question' ), array( 'X-DA-Token' => $token ) );
		return $uuid;
	}

	public function test_the_tables_exist_with_the_release_2_columns(): void {
		global $wpdb;
		$columns = $wpdb->get_col( 'SHOW COLUMNS FROM ' . Schema::conversations() ); // phpcs:ignore WordPress.DB
		foreach ( array( 'uuid', 'token_hash', 'status', 'assigned_user_id', 'deadline_at', 'first_agent_reply_at' ) as $column ) {
			$this->assertContains( $column, $columns );
		}
		$this->assertSame( Schema::VERSION, (int) get_option( Schema::OPTION ) );
	}

	public function test_the_exporter_returns_the_chats_of_an_email(): void {
		$this->handed_off( 'ann@example.test' );
		$this->handed_off( 'bob@example.test' );
		$export = Privacy::export( 'ann@example.test' );
		$this->assertCount( 1, $export['data'] );
		$this->assertStringContainsString( 'My question', (string) wp_json_encode( $export['data'] ) );
		$this->assertTrue( $export['done'] );
	}

	public function test_the_eraser_deletes_the_chats_of_an_email_and_their_messages(): void {
		global $wpdb;
		$uuid = $this->handed_off( 'ann@example.test' );
		$id   = ( new Repository() )->find_by_uuid( $uuid )['id'];
		$this->handed_off( 'bob@example.test' );
		$this->assertTrue( Privacy::erase( 'ann@example.test' )['items_removed'] );
		$this->assertNull( ( new Repository() )->find_by_uuid( $uuid ) );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::messages() . ' WHERE conversation_id = %d', $id ) ) ); // phpcs:ignore WordPress.DB
		$this->assertCount( 1, Privacy::export( 'bob@example.test' )['data'], 'other visitors keep theirs' );
	}

	public function test_the_purge_deletes_only_chats_older_than_the_retention_period(): void {
		global $wpdb;
		$this->settings( array( 'retention_days' => 30 ) );
		$old = $this->handed_off( 'old@example.test' );
		$new = $this->handed_off( 'new@example.test' );
		$wpdb->update( Schema::conversations(), array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS ) ), array( 'uuid' => $old ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( 1, Privacy::purge() );
		$this->assertNull( ( new Repository() )->find_by_uuid( $old ) );
		$this->assertNotNull( ( new Repository() )->find_by_uuid( $new ) );
	}

	public function test_the_purge_runs_daily(): void {
		$this->assertNotFalse( wp_next_scheduled( 'delta_assistant_purge' ) );
	}
}
