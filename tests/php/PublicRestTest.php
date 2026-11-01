<?php
/**
 * Public nudge endpoint: local fallback, mocked AI, cache, same-origin, rate limit, kill switches.
 *
 * @package Dev3\AiCommentModerator
 */

class PublicRestTest extends Dev3cm_Base_Case {

	public function set_up() {
		parent::set_up();
		do_action( 'rest_api_init' );
	}

	private function nudge( $text ) {
		return $this->rest( 'POST', 'public/nudge', array( 'text' => $text ), false );
	}

	public function test_local_check_without_ai() {
		$res = $this->nudge( 'You are such an IDIOT!!!!' );
		$this->assertSame( 200, $res->get_status() );
		$this->assertTrue( $res->get_data()['unkind'] );
		$this->assertSame( array( 'insult', 'pile_on' ), $res->get_data()['reasons'] );
		$this->assertFalse( $res->get_data()['ai'] );
		$this->assertFalse( $this->nudge( 'Thanks, very clear.' )->get_data()['unkind'] );
		$this->assertCount( 0, $this->requests );
	}

	public function test_ai_nudge_with_suggestion_is_cached_and_falls_back() {
		$this->ai_on( array( 'nudge_ai' => true ) );
		$this->http[':generateContent'] = $this->gemini(
			array(
				'unkind'     => true,
				'reason'     => 'This could feel like a personal attack.',
				'suggestion' => 'I think this approach has problems.',
			)
		);
		$res = $this->nudge( 'Only a clown would write this' )->get_data();
		$this->assertTrue( $res['ai'] );
		$this->assertTrue( $res['unkind'] );
		$this->assertSame( 'I think this approach has problems.', $res['suggestion'] );
		$this->nudge( 'Only a clown would write this' );
		$this->assertCount( 1, $this->sent( ':generateContent' ), 'Second identical check is cached' );

		$this->settings( array( 'nudge_suggest' => false ) );
		$this->http[':generateContent'] = $this->gemini( array( 'unkind' => true, 'reason' => 'x', 'suggestion' => 'y' ) );
		$this->assertSame( '', $this->nudge( 'Different harsh text here' )->get_data()['suggestion'] );

		// Storefront kill switch → local verdict, no HTTP.
		$this->settings( array( 'public_ai' => false ) );
		$before = count( $this->requests );
		$res    = $this->nudge( 'shut up already please' )->get_data();
		$this->assertFalse( $res['ai'] );
		$this->assertTrue( $res['unkind'] );
		$this->assertCount( $before, $this->requests );

		// AI errors → local verdict.
		$this->settings( array( 'public_ai' => true ) );
		$this->http[':generateContent'] = array( 500, array() );
		$this->assertFalse( $this->nudge( 'A brand new sentence for the error path' )->get_data()['ai'] );
	}

	public function test_cross_origin_rate_limit_and_disabled() {
		$_SERVER['HTTP_ORIGIN'] = 'https://evil.example';
		$req                    = new WP_REST_Request( 'POST', '/dev3cm/v1/public/nudge' );
		$req->set_header( 'Origin', 'https://evil.example' );
		$req->set_param( 'text', 'hi there' );
		$this->assertSame( 403, rest_get_server()->dispatch( $req )->get_status() );
		unset( $_SERVER['HTTP_ORIGIN'] );

		remove_filter( 'dev3cm_public_rate_limit', array( $this, 'no_limit' ) );
		add_filter( 'dev3cm_public_rate_limit', static fn() => 2 );
		$this->nudge( 'one' );
		$this->nudge( 'two' );
		$this->assertSame( 429, $this->nudge( 'three' )->get_status() );
		remove_all_filters( 'dev3cm_public_rate_limit' );
		add_filter( 'dev3cm_public_rate_limit', array( $this, 'no_limit' ) );

		$this->settings( array( 'nudge_enabled' => false ) );
		$this->assertSame( 404, $this->nudge( 'anything at all' )->get_status() );
	}
}
