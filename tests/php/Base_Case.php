<?php
/**
 * Shared set-up: clean tables and settings, a fake Gemini (pre_http_request), comment/REST helpers.
 *
 * @package Dev3\AiCommentModerator
 */

use Dev3\AiCommentModerator\Ai\Prompts_Library;
use Dev3\AiCommentModerator\Core3\Api\Ai;
use Dev3\AiCommentModerator\Core3\Core\Cache;
use Dev3\AiCommentModerator\Core3\Core\Schema;
use Dev3\AiCommentModerator\Core3\Core\Settings_Store;
use Dev3\AiCommentModerator\Core3\Data\Usage_Repository;
use Dev3\AiCommentModerator\Data\Tables;
use Dev3\AiCommentModerator\Data\Threads;
use Dev3\AiCommentModerator\Moderation\Pipeline;

abstract class Dev3cm_Base_Case extends WP_UnitTestCase {

	/**
	 * Captured HTTP requests: [ url, body ].
	 *
	 * @var array
	 */
	protected $requests = array();

	/**
	 * Fake replies: url substring => [ status, body ] or callable( url, body ) → [ status, body ].
	 *
	 * @var array
	 */
	protected $http = array();

	public function set_up() {
		parent::set_up();
		Schema::install( DEV3CM_DB_VERSION );
		global $wpdb;
		foreach ( Tables::ALL as $t ) {
			$wpdb->query( 'DELETE FROM ' . Tables::name( $t ) ); // phpcs:ignore
		}
		$wpdb->query( "DELETE FROM {$wpdb->prefix}dev3cm_core_jobs" ); // phpcs:ignore
		update_option( Settings_Store::OPTION, array() );
		Settings_Store::flush();
		Usage_Repository::flush();
		Cache::reset_memo();
		Cache::flush();
		Threads::reset();
		Prompts_Library::register();
		Ai::$client         = null;
		Pipeline::$pending  = null;
		$this->requests     = array();
		$this->http         = array();
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
		add_filter( 'dev3cm_retry_delay_ms', '__return_zero' );
		add_filter( 'dev3cm_public_rate_limit', array( $this, 'no_limit' ) );
		add_filter( 'dev3cm_jobs_use_action_scheduler', '__return_false' );
		// Core comment moderation off, so only the plugin holds things.
		update_option( 'comment_moderation', 0 );
		update_option( 'comment_previously_approved', 0 );
		update_option( 'require_name_email', 0 );
		update_option( 'disallowed_keys', '' );
		update_option( 'moderation_keys', '' );
		update_option( 'comment_max_links', 50 );
		add_filter( 'duplicate_comment_id', '__return_false' );
		add_filter( 'comment_flood_filter', '__return_false' );
		delete_transient( 'dev3cm_queue_lock' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		remove_filter( 'dev3cm_public_rate_limit', array( $this, 'no_limit' ) );
		remove_filter( 'duplicate_comment_id', '__return_false' );
		remove_filter( 'comment_flood_filter', '__return_false' );
		wp_set_current_user( 0 );
		unset( $_SERVER['HTTP_ORIGIN'] );
		parent::tear_down();
	}

	public function no_limit() {
		return 10000;
	}

	/**
	 * Saves a (fake) API key so AI is enabled.
	 *
	 * @param array $settings Extra settings.
	 */
	protected function ai_on( array $settings = array() ) {
		Settings_Store::update( array( 'api_key' => 'AIza-test-key-000000000000' ) + $settings );
		Settings_Store::flush();
	}

	/**
	 * Updates plugin settings.
	 *
	 * @param array $settings Settings.
	 */
	protected function settings( array $settings ) {
		Settings_Store::update( $settings );
		Settings_Store::flush();
	}

	/**
	 * Gemini generateContent reply carrying structured JSON.
	 *
	 * @param array $data Structured output.
	 * @return array
	 */
	protected function gemini( array $data ) {
		return array(
			200,
			array(
				'candidates'    => array(
					array(
						'content'      => array( 'parts' => array( array( 'text' => wp_json_encode( $data ) ) ) ),
						'finishReason' => 'STOP',
					),
				),
				'usageMetadata' => array(
					'promptTokenCount'     => 300,
					'candidatesTokenCount' => 60,
					'totalTokenCount'      => 360,
				),
			),
		);
	}

	/**
	 * Fake classifier: scores every post id in the prompt with $scores (or $scorer( text ) when callable).
	 *
	 * @param array|callable $scores Scores or scorer.
	 */
	protected function fake_classify( $scores ) {
		$this->http[':generateContent'] = function ( $url, $body ) use ( $scores ) {
			$prompt = (string) ( $body['contents'][0]['parts'][0]['text'] ?? '' );
			$json   = json_decode( substr( $prompt, strpos( $prompt, '[' ) ), true );
			$items  = array();
			foreach ( (array) $json as $post ) {
				$row     = is_callable( $scores ) ? $scores( (string) $post['text'] ) : $scores;
				$items[] = $row + array(
					'id'         => (string) $post['id'],
					'spam'       => 0.02,
					'toxicity'   => 0.03,
					'harassment' => 0.01,
					'off_topic'  => 0.05,
					'pii'        => 0,
					'sentiment'  => 0.4,
					'language'   => 'en',
					'reason'     => 'Looks fine.',
				);
			}
			return $this->gemini( array( 'items' => $items ) );
		};
	}

	public function fake_http( $pre, $args, $url ) {
		$body             = isset( $args['body'] ) ? json_decode( (string) $args['body'], true ) : null;
		$this->requests[] = array(
			'url'  => $url,
			'body' => $body,
		);
		foreach ( $this->http as $needle => $reply ) {
			if ( false !== strpos( $url, $needle ) ) {
				if ( is_callable( $reply ) ) {
					$reply = $reply( $url, $body );
				}
				return array(
					'headers'  => array(),
					'body'     => is_string( $reply[1] ) ? $reply[1] : wp_json_encode( $reply[1] ),
					'response' => array(
						'code'    => $reply[0],
						'message' => 'x',
					),
					'cookies'  => array(),
				);
			}
		}
		return new WP_Error( 'http_blocked', 'No fake for ' . $url );
	}

	/**
	 * Requests sent to a URL fragment.
	 *
	 * @param string $needle Fragment.
	 * @return array
	 */
	protected function sent( $needle ) {
		return array_values( array_filter( $this->requests, static fn( $r ) => false !== strpos( $r['url'], $needle ) ) );
	}

	/**
	 * Submits a comment the way the comment form does (wp_new_comment → pre_comment_approved → comment_post).
	 *
	 * @param int    $post_id Post.
	 * @param string $text    Text.
	 * @param array  $extra   Extra comment fields.
	 * @return int Comment id.
	 */
	protected function submit( $post_id, $text, array $extra = array() ) {
		$id = wp_new_comment(
			$extra + array(
				'comment_post_ID'      => $post_id,
				'comment_content'      => $text,
				'comment_author'       => 'Visitor',
				'comment_author_email' => 'visitor@example.org',
				'comment_author_url'   => '',
				'comment_type'         => 'comment',
				'comment_author_IP'    => '203.0.113.7',
				'user_id'              => 0,
			),
			true
		);
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : 'comment not created' );
		return $id;
	}

	/**
	 * REST call as the current user (strict nonce).
	 *
	 * @param string $method Method.
	 * @param string $route  Route below dev3cm/v1.
	 * @param array  $params Params.
	 * @param bool   $nonce  Send the nonce.
	 * @return WP_REST_Response
	 */
	protected function rest( $method, $route, array $params = array(), $nonce = true ) {
		$parts = explode( '?', $route, 2 );
		$req   = new WP_REST_Request( $method, '/dev3cm/v1/' . ltrim( $parts[0], '/' ) );
		if ( isset( $parts[1] ) ) {
			parse_str( $parts[1], $query );
			$req->set_query_params( $query );
		}
		if ( $nonce ) {
			$req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return rest_get_server()->dispatch( $req );
	}

	/**
	 * Logs in an administrator.
	 *
	 * @return int User id.
	 */
	protected function admin() {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $id );
		return $id;
	}
}
