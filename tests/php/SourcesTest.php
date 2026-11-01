<?php
/**
 * WooCommerce reviews and bbPress topics/replies (real plugins loaded from wp-env).
 *
 * @package Dev3\AiCommentModerator
 */

use Dev3\AiCommentModerator\Data\Items;
use Dev3\AiCommentModerator\Moderation\Pipeline;
use Dev3\AiCommentModerator\Moderation\Sources;

class SourcesTest extends Dev3cm_Base_Case {

	public function test_woocommerce_review_is_screened_as_a_review() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce not installed.' );
		}
		$product = new WC_Product_Simple();
		$product->set_name( 'Ceramic mug' );
		$product->set_status( 'publish' );
		$product->save();
		$this->settings( array( 'rule_profanity_action' => 'hold' ) );
		$id = $this->submit( $product->get_id(), 'This crap mug broke in a week', array( 'comment_type' => 'review' ) );
		update_comment_meta( $id, 'rating', 1 );
		$item = Items::find( 'review', $id );
		$this->assertNotNull( $item );
		$this->assertSame( 'held', $item['status'] );
		$this->assertSame( '0', get_comment( $id )->comment_approved );
		$this->assertSame( 1, Items::format( $item, true )['rating'] );
		$this->assertSame( 'review', Sources::comment_source( '', $product->get_id() ), 'Plain comments on products count as reviews' );
		$texts = Sources::thread_texts( $product->get_id() );
		$this->assertSame( array(), $texts, 'Held reviews are not part of summaries' );
		wp_set_comment_status( $id, 'approve' );
		$this->assertStringStartsWith( '[1/5]', Sources::thread_texts( $product->get_id() )[0]['text'] );
	}

	public function test_hpos_and_blocks_compatibility_is_declared() {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			$this->markTestSkipped( 'WooCommerce not installed.' );
		}
		$this->assertNotFalse( has_action( 'before_woocommerce_init' ) );
	}

	/**
	 * Creates a forum + topic with bbPress.
	 *
	 * @return array [ forum, topic ]
	 */
	private function forum() {
		$forum = bbp_insert_forum( array( 'post_title' => 'Help desk' ) );
		$topic = bbp_insert_topic(
			array(
				'post_parent' => $forum,
				'post_title'  => 'Grinder settings',
				'post_content' => 'Which grind size for espresso?',
			),
			array( 'forum_id' => $forum )
		);
		return array( $forum, $topic );
	}

	/**
	 * Simulates bbPress's new-reply handler: pre_insert filter → insert → bbp_new_reply.
	 *
	 * @param int    $topic  Topic.
	 * @param int    $forum  Forum.
	 * @param string $text   Content.
	 * @param int    $author Author.
	 * @return int Reply id.
	 */
	private function bbp_reply( $topic, $forum, $text, $author ) {
		$data = apply_filters(
			'bbp_new_reply_pre_insert',
			array(
				'post_author'  => $author,
				'post_title'   => 'Reply To: Grinder settings',
				'post_content' => $text,
				'post_status'  => bbp_get_public_status_id(),
				'post_parent'  => $topic,
				'post_type'    => bbp_get_reply_post_type(),
			)
		);
		$id   = wp_insert_post( $data );
		update_post_meta( $id, '_bbp_author_ip', '203.0.113.9' );
		do_action( 'bbp_new_reply', $id, $topic, $forum, array(), $author, false, 0 );
		return $id;
	}

	public function test_bbpress_reply_is_held_spammed_and_moderated_with_native_functions() {
		if ( ! Sources::has_bbpress() ) {
			$this->markTestSkipped( 'bbPress not installed.' );
		}
		list( $forum, $topic ) = $this->forum();
		$member                = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $member );
		$clean = $this->bbp_reply( $topic, $forum, 'Try a finer grind and 18 g in.', $member );
		$links = $this->bbp_reply( $topic, $forum, 'Visit http://a.io http://b.io http://c.io', $member );
		$this->assertSame( 'publish', get_post_status( $clean ) );
		$this->assertSame( bbp_get_pending_status_id(), get_post_status( $links ) );
		$item = Items::find( 'bbp_reply', $links );
		$this->assertSame( 'held', $item['status'] );
		$this->assertSame( $topic, $item['post_id'] );
		wp_set_current_user( 0 );

		// The plugin moves it to spam with bbp_spam_reply (restorable), never deletes.
		$this->assertTrue( Sources::set_status( 'bbp_reply', $links, 'spam' ) );
		$this->assertSame( bbp_get_spam_status_id(), get_post_status( $links ) );
		$this->assertTrue( Sources::set_status( 'bbp_reply', $links, 'live' ) );
		$this->assertSame( 'publish', get_post_status( $links ) );

		// A moderator change in wp-admin marks the record reviewed.
		$this->admin();
		bbp_unapprove_reply( $clean );
		$this->assertSame( 1, Items::find( 'bbp_reply', $clean )['reviewed'] );
		$this->assertSame( 'held', Items::find( 'bbp_reply', $clean )['status'] );

		// Reply from the queue as the moderator.
		$reply = Sources::reply( 'bbp_reply', $links, $topic, 'Thanks, please avoid link dumps.' );
		$this->assertIsInt( $reply );
		$this->assertSame( $topic, wp_get_post_parent_id( $reply ) );
		$this->assertSame( $links, (int) get_post_meta( $reply, '_bbp_reply_to', true ) );
	}

	public function test_bbpress_topic_screening_uses_title_and_content() {
		if ( ! Sources::has_bbpress() ) {
			$this->markTestSkipped( 'bbPress not installed.' );
		}
		$this->settings( array( 'rule_blocklist_words' => 'casino' ) );
		$forum = bbp_insert_forum( array( 'post_title' => 'General' ) );
		$data  = apply_filters(
			'bbp_new_topic_pre_insert',
			array(
				'post_author'  => 0,
				'post_title'   => 'Casino bonus inside',
				'post_content' => 'Click to win',
				'post_status'  => bbp_get_public_status_id(),
				'post_parent'  => $forum,
				'post_type'    => bbp_get_topic_post_type(),
			)
		);
		$this->assertSame( bbp_get_pending_status_id(), $data['post_status'] );
		$id = wp_insert_post( $data );
		do_action( 'bbp_new_topic', $id, $forum, array(), 0 );
		$item = Items::find( 'bbp_topic', $id );
		$this->assertSame( $id, $item['post_id'] );
		$this->assertStringContainsString( 'Casino bonus inside', $item['excerpt'] );
		$this->assertContains( 'bbp_topic:' . $id, Sources::scan_targets( 2, array( 'bbp_topic' ) ) );
		$this->assertSame( $item['id'], Pipeline::record_existing( 'bbp_topic', $id, false ) );
	}

	public function test_comment_reply_from_queue_is_a_child_comment() {
		$post = self::factory()->post->create();
		$id   = $this->submit( $post, 'Where do I buy the kettle?' );
		$this->admin();
		$reply = Sources::reply( 'comment', $id, $post, 'Any good kitchen shop has it.' );
		$this->assertSame( $id, (int) get_comment( $reply )->comment_parent );
		$this->assertSame( '1', get_comment( $reply )->comment_approved );
		$this->assertNull( Items::find( 'comment', $reply ), 'Staff replies are not screened' );
		$this->assertCount( 1, Sources::replies( 'comment', $id, $post ) );
	}
}
