<?php
/**
 * Admin REST: permissions, queue, actions, bulk, AI routes (mocked), discussions, stats, tester, export, scan.
 *
 * @package Dev3\AiCommentModerator
 */

use Dev3\AiCommentModerator\Core3\Core\Jobs;
use Dev3\AiCommentModerator\Data\Items;
use Dev3\AiCommentModerator\Data\Threads;

class AdminRestTest extends Dev3cm_Base_Case {

	private $post;

	public function set_up() {
		parent::set_up();
		do_action( 'rest_api_init' );
		$this->post = self::factory()->post->create( array( 'post_title' => 'Roasting guide' ) );
	}

	private function held( $text = 'Spam http://a.io http://b.io http://c.io' ) {
		$id = $this->submit( $this->post, $text );
		return Items::find( 'comment', $id );
	}

	public function test_routes_need_a_manager_and_a_nonce() {
		$this->assertSame( 401, $this->rest( 'GET', 'items' )->get_status() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->rest( 'GET', 'items' )->get_status() );
		$this->admin();
		$this->assertSame( 403, $this->rest( 'GET', 'items', array(), false )->get_status(), 'Strict nonce' );
		$this->assertSame( 200, $this->rest( 'GET', 'items' )->get_status() );
	}

	public function test_queue_lists_views_and_counts() {
		$item = $this->held();
		$this->submit( $this->post, 'Lovely guide, thanks' );
		$this->admin();
		$res = $this->rest( 'GET', 'items?view=needs' )->get_data();
		$this->assertSame( 1, $res['total'] );
		$this->assertSame( $item['id'], $res['items'][0]['id'] );
		$this->assertSame( 'Too many links', $res['items'][0]['reasons'][0]['label'] );
		$this->assertSame( 'Roasting guide', $res['items'][0]['post']['title'] );
		$this->assertSame( 1, $res['counts']['held'] );
		$this->assertSame( 2, $res['counts']['all'] );
		$this->assertSame( 1, $this->rest( 'GET', 'items?view=all&label=links' )->get_data()['total'] );
		$this->assertSame( 1, $this->rest( 'GET', 'items?view=all&search=Lovely' )->get_data()['total'] );
		$full = $this->rest( 'GET', 'items/' . $item['id'] )->get_data();
		$this->assertStringContainsString( 'http://a.io', $full['content'] );
		$this->assertNotEmpty( $full['templates'] );
		$this->assertStringNotContainsString( '{author}', $full['templates'][0]['text'] );
		$this->assertSame( 404, $this->rest( 'GET', 'items/999999' )->get_status() );
	}

	public function test_actions_change_status_and_mark_reviewed() {
		$item = $this->held();
		$this->admin();
		$res = $this->rest( 'POST', 'items/' . $item['id'] . '/action', array( 'action' => 'approve' ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'live', $res->get_data()['status'] );
		$this->assertTrue( $res->get_data()['reviewed'] );
		$this->assertSame( '1', get_comment( $item['object_id'] )->comment_approved );
		$this->assertSame( 400, $this->rest( 'POST', 'items/' . $item['id'] . '/action', array( 'action' => 'delete' ) )->get_status(), 'No delete action' );
		$res = $this->rest( 'POST', 'items/' . $item['id'] . '/action', array( 'action' => 'trash' ) );
		$this->assertSame( 'trash', get_comment( $item['object_id'] )->comment_approved );
		$this->assertSame( 'trash', $res->get_data()['status'] );
	}

	public function test_bulk_actions_and_ai_rescan_job() {
		$a = $this->held();
		$b = $this->held( 'More spam http://x.io http://y.io http://z.io http://w.io' );
		$this->admin();
		$res = $this->rest( 'POST', 'items/bulk', array( 'ids' => array( $a['id'], $b['id'] ), 'action' => 'spam' ) );
		$this->assertSame( 2, $res->get_data()['done'] );
		$this->assertSame( 'spam', get_comment( $a['object_id'] )->comment_approved );
		$this->assertSame( 400, $this->rest( 'POST', 'items/bulk', array( 'ids' => array( $a['id'] ), 'action' => 'rescan' ) )->get_status(), 'Rescan needs AI' );
		$this->ai_on();
		$this->fake_classify( array( 'spam' => 0.99 ) );
		$res = $this->rest( 'POST', 'items/bulk', array( 'ids' => array( $a['id'], $b['id'] ), 'action' => 'rescan' ) );
		$this->assertSame( 201, $res->get_status() );
		Jobs::run( $res->get_data()['job']['id'] );
		$this->assertSame( 'done', Items::get( $a['id'] )['ai_state'] );
		$this->assertCount( 1, $this->sent( ':generateContent' ), 'Both items classified in one batch call' );
	}

	public function test_classify_and_reply_draft_use_mocked_ai_with_brand_voice() {
		$item = $this->held( 'Your roast times are wrong, idiot' );
		$this->admin();
		$this->assertSame( 400, $this->rest( 'POST', 'items/' . $item['id'] . '/reply-draft' )->get_status(), 'No key → error envelope' );
		$this->ai_on(
			array(
				'voice_tone'       => 'empathetic',
				'voice_guidelines' => 'Support email: help@roast.example',
				'voice_signoff'    => '– The Roast team',
			)
		);
		$this->fake_classify( array( 'toxicity' => 0.88, 'reason' => 'Name-calling.' ) );
		$res = $this->rest( 'POST', 'items/' . $item['id'] . '/classify' );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 0.88, $res->get_data()['labels']['toxicity'] );
		$this->assertSame( 'Name-calling.', $res->get_data()['reason'] );

		$this->http[':generateContent'] = $this->gemini( array( 'reply' => 'Thanks for flagging this, Visitor. – The Roast team' ) );
		$res                            = $this->rest( 'POST', 'items/' . $item['id'] . '/reply-draft', array( 'instructions' => 'Mention the updated chart' ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'Thanks for flagging this, Visitor. – The Roast team', $res->get_data()['draft'] );
		$sent   = end( $this->requests )['body'];
		$system = $sent['systemInstruction']['parts'][0]['text'] ?? wp_json_encode( $sent );
		$this->assertStringContainsString( 'empathetic', $system );
		$this->assertStringContainsString( 'help@roast.example', $system );
		$this->assertStringContainsString( 'Mention the updated chart', $sent['contents'][0]['parts'][0]['text'] );
		$this->assertCount( 0, get_comments( array( 'parent' => $item['object_id'] ) ), 'A draft is never posted by itself' );

		$res = $this->rest( 'POST', 'items/' . $item['id'] . '/reply', array( 'content' => 'Thanks, we updated it.', 'approve' => true ) );
		$this->assertSame( 201, $res->get_status() );
		$this->assertSame( '1', get_comment( $item['object_id'] )->comment_approved );
		$this->assertSame( 'Thanks, we updated it.', get_comment( $res->get_data()['reply_id'] )->comment_content );
		$this->assertSame( 'replied', end( $res->get_data()['history'] )['action'] );
	}

	public function test_threads_summary_draft_accept_toggle_and_delete() {
		foreach ( array( 'Great guide, love it', 'Very helpful, thanks', 'The pricing table is confusing' ) as $text ) {
			$this->submit( $this->post, $text );
		}
		$this->admin();
		$list = $this->rest( 'GET', 'threads?sort=negative' )->get_data();
		$this->assertSame( 1, $list['total'] );
		$this->assertSame( 3, $list['items'][0]['total'] );
		$this->assertSame( 2, $list['items'][0]['positive'] );
		$one = $this->rest( 'GET', 'threads/' . $this->post )->get_data();
		$this->assertSame( 3, $one['insights']['count'] );
		$this->assertNull( $one['summary'] );

		$this->ai_on();
		$this->http[':generateContent'] = $this->gemini(
			array(
				'summary'   => 'Readers find the guide helpful; some are confused by pricing.',
				'sentiment' => 0.5,
				'themes'    => array( array( 'label' => 'Pricing', 'sentiment' => -0.4, 'share' => 0.33 ) ),
				'questions' => array( 'Is there a cheaper plan?' ),
				'actions'   => array( 'Clarify the pricing table' ),
			)
		);
		$draft = $this->rest( 'POST', 'threads/' . $this->post . '/summarize' )->get_data();
		$this->assertSame( 3, $draft['item_count'] );
		$this->assertSame( 'Pricing', $draft['draft']['themes'][0]['label'] );
		$this->assertNull( Threads::get( $this->post ), 'Not saved until accepted' );

		$saved = $this->rest( 'POST', 'threads/' . $this->post . '/summary', $draft['draft'] + array( 'item_count' => 3, 'show_public' => false ) );
		$this->assertSame( 200, $saved->get_status() );
		$this->assertFalse( $saved->get_data()['show_public'] );
		$toggled = $this->rest( 'POST', 'threads/' . $this->post . '/summary', array( 'show_public' => true ) );
		$this->assertTrue( $toggled->get_data()['show_public'] );
		$this->assertSame( 'Readers find the guide helpful; some are confused by pricing.', $toggled->get_data()['summary'] );
		$this->rest( 'DELETE', 'threads/' . $this->post . '/summary' );
		Threads::reset();
		$this->assertNull( Threads::get( $this->post ) );
	}

	public function test_summary_needs_two_comments() {
		$this->submit( $this->post, 'Only one' );
		$this->admin();
		$this->ai_on();
		$this->assertSame( 400, $this->rest( 'POST', 'threads/' . $this->post . '/summarize' )->get_status() );
	}

	public function test_stats_tester_and_csv_export() {
		$this->held();
		$this->submit( $this->post, '=HYPERLINK("http://evil") nice' );
		$this->admin();
		$stats = $this->rest( 'GET', 'stats' )->get_data();
		$this->assertSame( 1, $stats['needs'] );
		$this->assertSame( 2, $stats['screened'] );
		$this->assertCount( 14, $stats['series'] );
		$this->assertSame( 1, $stats['codes']['links'] );

		$test = $this->rest( 'POST', 'test', array( 'text' => 'You moron, email me at a@b.co' ) )->get_data();
		$this->assertSame( 'hold', $test['decision'] );
		$this->assertSame( array( 'pii', 'profanity' ), wp_list_pluck( $test['reasons'], 'code' ) );
		$this->assertSame( 0, Items::counts()['all'] - 2, 'Tester stores nothing' );

		$csv = $this->rest( 'GET', 'export/csv?view=all' )->get_data();
		$this->assertSame( 2, $csv['rows'] );
		$this->assertStringContainsString( "'=HYPERLINK", $csv['csv'], 'Formulas are neutralised' );
	}

	public function test_scan_job_records_existing_comments() {
		self::factory()->comment->create_post_comments( $this->post, 3, array( 'comment_content' => 'Old but gold' ) );
		$this->admin();
		$res = $this->rest( 'POST', 'scan', array( 'days' => 7 ) );
		$this->assertSame( 201, $res->get_status() );
		$job = Jobs::run( $res->get_data()['job']['id'] );
		$this->assertSame( 3, Items::counts()['all'] );
		$this->assertSame( 'scanned', Items::query( array( 'view' => 'all' ) )['items'][0]['history'][0]['action'] );
	}
}
