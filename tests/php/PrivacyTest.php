<?php
/**
 * GDPR exporter/eraser for moderation records, retention pruning, uninstall safety.
 *
 * @package Dev3\AiCommentModerator
 */

use Dev3\AiCommentModerator\Data\Items;
use Dev3\AiCommentModerator\Plugin;

class PrivacyTest extends Dev3cm_Base_Case {

	public function test_export_and_erase_personal_data() {
		$post = self::factory()->post->create();
		$this->submit( $post, 'Hello from Pat', array( 'comment_author_email' => 'pat@example.org', 'comment_author' => 'Pat' ) );
		$export = Items::export_personal_data( 'pat@example.org' );
		$this->assertCount( 1, $export['data'] );
		$this->assertSame( 'Hello from Pat', $export['data'][0]['data'][0]['value'] );
		$this->assertTrue( Items::erase_personal_data( 'pat@example.org' )['items_removed'] );
		$this->assertCount( 0, Items::export_personal_data( 'pat@example.org' )['data'] );
		$row = Items::query( array( 'view' => 'all' ) )['items'][0];
		$this->assertSame( 'Anonymous', $row['author_name'] );
		$this->assertSame( '', $row['ip_hash'] );
		$this->assertArrayHasKey( 'dev3-ai-comment-moderator-comment-moderation-records', apply_filters( 'wp_privacy_personal_data_exporters', array() ) );
	}

	public function test_retention_prunes_old_records_but_not_comments() {
		$post = self::factory()->post->create();
		$id   = $this->submit( $post, 'Very old comment' );
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'dev3cm_items', array( 'created_at' => '2020-01-01 00:00:00' ), array( 'object_id' => $id ) ); // phpcs:ignore
		$this->submit( $post, 'New comment' );
		$this->settings( array( 'retention_days' => 30 ) );
		( Plugin::instance() )->daily();
		$this->assertSame( 1, Items::counts()['all'] );
		$this->assertNotNull( get_comment( $id ) );
		$this->assertSame( 0, Items::prune( 0 ), '0 keeps forever' );
	}

	public function test_ip_is_stored_hashed() {
		$post = self::factory()->post->create();
		$id   = $this->submit( $post, 'IP check' );
		$item = Items::find( 'comment', $id );
		$this->assertSame( 40, strlen( $item['ip_hash'] ) );
		$this->assertStringNotContainsString( '203.0.113.7', wp_json_encode( Items::format( $item, true ) ) );
	}
}
