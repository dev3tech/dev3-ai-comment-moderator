<?php
/**
 * Comment pipeline end to end: rules + AI (mocked) on wp_new_comment, queue, human reviews, never approve/delete.
 *
 * @package Dev3\AiCommentModerator
 */

use Dev3\AiCommentModerator\Ai\Classifier;
use Dev3\AiCommentModerator\Data\Items;
use Dev3\AiCommentModerator\Moderation\Pipeline;
use Dev3\AiCommentModerator\Moderation\Policy;

class PipelineTest extends Dev3cm_Base_Case {

	private $post;

	public function set_up() {
		parent::set_up();
		$this->post = self::factory()->post->create( array( 'post_title' => 'How to brew coffee' ) );
	}

	public function test_clean_comment_stays_live_and_is_recorded_without_ai() {
		$id   = $this->submit( $this->post, 'Thanks, this was really helpful!' );
		$item = Items::find( 'comment', $id );
		$this->assertSame( '1', get_comment( $id )->comment_approved );
		$this->assertSame( 'live', $item['status'] );
		$this->assertSame( 'none', $item['decision'] );
		$this->assertSame( 'lexicon', $item['sentiment_src'] );
		$this->assertGreaterThan( 0, $item['sentiment'] );
		$this->assertSame( 'none', $item['ai_state'] );
		$this->assertSame( 'screened', $item['history'][0]['action'] );
		$this->assertCount( 0, $this->requests, 'No AI without a key' );
	}

	public function test_link_spam_is_held_with_a_reason() {
		$id   = $this->submit( $this->post, 'Buy https://a.example https://b.example https://c.example' );
		$item = Items::find( 'comment', $id );
		$this->assertSame( '0', get_comment( $id )->comment_approved );
		$this->assertSame( 'held', $item['status'] );
		$this->assertSame( 'Too many links', Policy::reasons( $item )[0]['label'] );
		$this->assertSame( 'held', end( $item['history'] )['action'] );
	}

	public function test_cross_post_copy_paste_goes_to_spam() {
		$other = self::factory()->post->create();
		$this->submit( $other, 'Amazing content, visit my page for cheap followers' );
		$id = $this->submit( $this->post, 'Amazing content, visit my page for cheap followers' );
		$this->assertSame( 'spam', get_comment( $id )->comment_approved );
		$this->assertSame( 'spam', Items::find( 'comment', $id )['status'] );
	}

	public function test_moderators_are_trusted_and_non_comment_types_ignored() {
		$this->admin();
		$id = $this->submit( $this->post, 'Admin note http://a http://b http://c http://d', array( 'user_id' => get_current_user_id() ) );
		$this->assertNull( Items::find( 'comment', $id ) );
		$this->assertSame( '1', get_comment( $id )->comment_approved );
		wp_set_current_user( 0 );
		$ping = $this->submit( $this->post, 'Pingback text', array( 'comment_type' => 'pingback' ) );
		$this->assertNull( Items::find( 'comment', $ping ) );
	}

	public function test_sync_ai_holds_toxic_and_spams_spam() {
		$this->ai_on();
		$this->fake_classify(
			static function ( $text ) {
				if ( false !== strpos( $text, 'garbage' ) ) {
					return array( 'toxicity' => 0.92, 'sentiment' => -0.9, 'reason' => 'Insults the author.' );
				}
				if ( false !== strpos( $text, 'casino' ) ) {
					return array( 'spam' => 0.97, 'reason' => 'Gambling advert.' );
				}
				return array();
			}
		);
		$toxic = $this->submit( $this->post, 'You wrote garbage and you know it' );
		$spam  = $this->submit( $this->post, 'Best online casino bonus today' );
		$fine  = $this->submit( $this->post, 'I prefer the pour-over method myself' );
		$this->assertSame( '0', get_comment( $toxic )->comment_approved );
		$this->assertSame( 'spam', get_comment( $spam )->comment_approved );
		$this->assertSame( '1', get_comment( $fine )->comment_approved );
		$item = Items::find( 'comment', $toxic );
		$this->assertSame( 'done', $item['ai_state'] );
		$this->assertSame( 'ai', $item['sentiment_src'] );
		$this->assertEqualsWithDelta( -0.9, $item['sentiment'], 0.001 );
		$this->assertSame( 'Insults the author.', $item['reason'] );
		$this->assertSame( 'ai_toxicity', Policy::reasons( $item )[0]['code'] );
		$this->assertSame( 0.92, Policy::reasons( $item )[0]['score'] );
		$body = $this->sent( ':generateContent' )[0]['body'];
		$this->assertStringContainsString( 'How to brew coffee', $body['contents'][0]['parts'][0]['text'], 'Post title gives off-topic context' );
		$this->assertLessThanOrEqual( 0.3, $body['generationConfig']['temperature'] );
	}

	public function test_ai_failure_falls_back_to_rules_and_queues_a_retry() {
		$this->ai_on();
		$this->http[':generateContent'] = array( 500, array( 'error' => array( 'message' => 'boom' ) ) );
		$id   = $this->submit( $this->post, 'Plain comment, AI is down' );
		$item = Items::find( 'comment', $id );
		$this->assertSame( '1', get_comment( $id )->comment_approved );
		$this->assertSame( 'queued', $item['ai_state'] );
		$this->assertCount( 1, $this->sent( ':generateContent' ), 'Visitor context: no retries' );
		// The queue retries later and holds it if the AI now says so.
		$this->fake_classify( array( 'harassment' => 0.95 ) );
		$this->assertSame( 1, Classifier::process_queue( 5 ) );
		$this->assertSame( '0', get_comment( $id )->comment_approved );
		$item = Items::find( 'comment', $id );
		$this->assertSame( 'held', $item['status'] );
		$this->assertSame( 'done', $item['ai_state'] );
	}

	public function test_async_mode_classifies_in_the_background_and_never_approves() {
		$this->ai_on( array( 'ai_mode' => 'async' ) );
		update_option( 'comment_moderation', '1' );
		$this->fake_classify( array() );
		$id = $this->submit( $this->post, 'Held by WordPress itself, AI finds it clean' );
		$this->assertCount( 0, $this->requests, 'Async: no AI call while the visitor waits' );
		$this->assertSame( 'queued', Items::find( 'comment', $id )['ai_state'] );
		$this->assertNotFalse( wp_next_scheduled( Classifier::QUEUE_HOOK ) );
		Classifier::process_queue( 5 );
		$this->assertSame( '0', get_comment( $id )->comment_approved, 'The AI never approves' );
		$this->assertSame( 'done', Items::find( 'comment', $id )['ai_state'] );
	}

	public function test_human_review_is_respected_and_nothing_is_deleted() {
		$this->ai_on( array( 'ai_mode' => 'async' ) );
		$id   = $this->submit( $this->post, 'Borderline remark' );
		$item = Items::find( 'comment', $id );
		$this->admin();
		wp_set_comment_status( $id, 'hold' );
		wp_set_comment_status( $id, 'approve' );
		$item = Items::find( 'comment', $id );
		$this->assertSame( 1, $item['reviewed'] );
		$this->assertSame( 'approved', end( $item['history'] )['action'] );
		$this->fake_classify( array( 'toxicity' => 0.99 ) );
		Classifier::classify_items( array( $item['id'] ) );
		$this->assertSame( '1', get_comment( $id )->comment_approved, 'A reviewed item is not moved again' );
		$this->assertSame( 'hold', Items::get( $item['id'] )['decision'], 'but the decision is recorded' );
		wp_spam_comment( $id );
		$this->assertNotNull( get_comment( $id ), 'Spam is not deletion' );
		wp_delete_comment( $id, true );
		$this->assertNull( Items::find( 'comment', $id ), 'Record removed with the comment' );
	}

	public function test_record_existing_flags_without_moving_when_asked() {
		$id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->post,
				'comment_content'  => 'old comment with http://a http://b http://c',
				'comment_approved' => 1,
			)
		);
		$item_id = Pipeline::record_existing( 'comment', $id, false );
		$this->assertSame( 'hold', Items::get( $item_id )['decision'] );
		$this->assertSame( '1', get_comment( $id )->comment_approved );
		Pipeline::record_existing( 'comment', $id, true );
		$this->assertSame( '0', get_comment( $id )->comment_approved );
		$this->assertSame( 0, Pipeline::record_existing( 'nope', $id ) );
	}

	public function test_sources_can_be_switched_off() {
		$this->settings( array( 'src_comments' => false ) );
		$id = $this->submit( $this->post, 'http://a http://b http://c' );
		$this->assertSame( '1', get_comment( $id )->comment_approved );
		$this->assertNull( Items::find( 'comment', $id ) );
	}
}
