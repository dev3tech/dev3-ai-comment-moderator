<?php
/**
 * Front-end: nudge assets only with a form, community note, discussion summary block/shortcode/auto-insert.
 *
 * @package Dev3\AiCommentModerator
 */

use Dev3\AiCommentModerator\Data\Threads;
use Dev3\AiCommentModerator\Frontend\Nudge;
use Dev3\AiCommentModerator\Frontend\Summary;

class FrontendTest extends Dev3cm_Base_Case {

	public function tear_down() {
		wp_dequeue_script( Nudge::HANDLE );
		wp_dequeue_style( Nudge::HANDLE );
		wp_deregister_script( Nudge::HANDLE );
		parent::tear_down();
	}

	public function test_nudge_loads_only_with_a_comment_form_and_carries_no_key() {
		$this->ai_on( array( 'nudge_ai' => true ) );
		$this->assertFalse( wp_script_is( Nudge::HANDLE, 'enqueued' ) );
		do_action( 'comment_form_before' );
		$this->assertTrue( wp_script_is( Nudge::HANDLE, 'enqueued' ) );
		$inline = implode( '', (array) wp_scripts()->get_data( Nudge::HANDLE, 'before' ) );
		$this->assertStringContainsString( 'dev3cm\/v1\/public\/nudge', $inline );
		$this->assertStringContainsString( '"ai":true', $inline );
		$this->assertStringNotContainsString( 'AIza', $inline );
		$this->assertStringNotContainsString( 'DEV3', $inline, 'Visitor text is neutral' );
	}

	public function test_nudge_off_and_community_note() {
		$this->settings( array( 'nudge_enabled' => false ) );
		do_action( 'comment_form_before' );
		$this->assertFalse( wp_script_is( Nudge::HANDLE, 'enqueued' ) );
		$this->assertSame( '<textarea></textarea>', apply_filters( 'comment_form_field_comment', '<textarea></textarea>' ) );
		$this->settings( array( 'note_enabled' => true, 'note_text' => 'Be nice & kind' ) );
		$html = apply_filters( 'comment_form_field_comment', '<textarea></textarea>' );
		$this->assertStringContainsString( '<p class="dev3cm-note">Be nice &amp; kind</p>', $html );
	}

	public function test_summary_renders_only_when_public() {
		$post = self::factory()->post->create( array( 'post_title' => 'Brew guide' ) );
		$this->assertSame( '', Summary::render( $post ) );
		Threads::save(
			$post,
			array(
				'summary'     => 'Readers love the ratios.',
				'sentiment'   => 0.6,
				'themes'      => array( array( 'label' => 'Ratios', 'sentiment' => 0.7, 'share' => 0.5 ) ),
				'item_count'  => 12,
				'show_public' => false,
			)
		);
		$this->assertSame( '', Summary::render( $post ), 'Saved but not public' );
		Threads::save( $post, array_merge( Threads::get( $post ), array( 'show_public' => 1 ) ) );
		$html = Summary::render( $post );
		$this->assertStringContainsString( 'What readers are saying', $html );
		$this->assertStringContainsString( 'Mostly positive', $html );
		$this->assertStringContainsString( 'Based on 12 comments', $html );
		$this->assertStringContainsString( 'dev3cm-theme--positive', $html );
		$this->assertStringContainsString( 'reviewed by the site team', $html );
		$this->assertSame( $html, do_shortcode( '[dev3cm_summary post_id="' . $post . '"]' ) );
		$this->assertStringContainsString( 'dev3cm-sr', Summary::render( $post, false ) );
		$block = render_block(
			array(
				'blockName'    => 'dev3cm/discussion-summary',
				'attrs'        => array( 'postId' => $post ),
				'innerHTML'    => '',
				'innerContent' => array(),
				'innerBlocks'  => array(),
			)
		);
		$this->assertStringContainsString( 'Readers love the ratios.', $block );
		wp_update_post( array( 'ID' => $post, 'post_status' => 'draft' ) );
		$this->assertSame( '', Summary::render( $post ), 'Drafts never leak' );
	}

	public function test_auto_insert_after_content() {
		$post = self::factory()->post->create( array( 'post_content' => 'Body text' ) );
		Threads::save( $post, array( 'summary' => 'All good here.', 'item_count' => 3, 'show_public' => 1 ) );
		$this->go_to( get_permalink( $post ) );
		$this->assertSame( 'x', Summary::auto_insert( 'x' ), 'Off by default' );
		$this->settings( array( 'summary_display' => 'after_content' ) );
		global $wp_query;
		$wp_query->in_the_loop = true;
		the_post();
		$out = Summary::auto_insert( 'x' );
		$this->assertStringStartsWith( 'x<section class="dev3cm-summary"', $out );
		$this->assertSame( 'x [dev3cm_summary]', Summary::auto_insert( 'x [dev3cm_summary]' ), 'No double output' );
		$wp_query->in_the_loop = false;
	}
}
