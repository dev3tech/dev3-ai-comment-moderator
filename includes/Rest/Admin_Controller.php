<?php
/**
 * Admin REST routes (queue, actions, replies, discussions, stats, scanner, policy tester, export).
 *
 * @package Dev3\AiCommentModerator
 */

namespace Dev3\AiCommentModerator\Rest;

use Dev3\AiCommentModerator\Ai\Classifier;
use Dev3\AiCommentModerator\Ai\Writer;
use Dev3\AiCommentModerator\Core3\Api\Ai;
use Dev3\AiCommentModerator\Core3\Core\Jobs;
use Dev3\AiCommentModerator\Core3\Rest\Base_Controller;
use Dev3\AiCommentModerator\Data\Items;
use Dev3\AiCommentModerator\Data\Text;
use Dev3\AiCommentModerator\Data\Threads;
use Dev3\AiCommentModerator\Moderation\Pipeline;
use Dev3\AiCommentModerator\Moderation\Policy;
use Dev3\AiCommentModerator\Moderation\Rules;
use Dev3\AiCommentModerator\Moderation\Sources;
use Dev3\AiCommentModerator\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under dev3cm/v1.
 */
class Admin_Controller extends Base_Controller {

	const ACTIONS = array( 'approve', 'hold', 'spam', 'trash', 'dismiss' );

	/**
	 * Registers routes.
	 */
	public function register_routes() {
		$manage   = array( $this, 'can_manage' );
		$generate = array( $this, 'can_generate' );
		$id_arg   = array(
			'id' => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
		);
		$routes   = array(
			array( '/items', 'GET', 'items', $manage ),
			array( '/items/(?P<id>\d+)', 'GET', 'item', $manage, $id_arg ),
			array( '/items/(?P<id>\d+)/action', 'POST', 'action', $manage, $id_arg ),
			array( '/items/bulk', 'POST', 'bulk', $manage ),
			array( '/items/(?P<id>\d+)/classify', 'POST', 'classify', $generate, $id_arg ),
			array( '/items/(?P<id>\d+)/reply-draft', 'POST', 'reply_draft', $generate, $id_arg ),
			array( '/items/(?P<id>\d+)/reply', 'POST', 'reply', $manage, $id_arg ),
			array( '/threads', 'GET', 'threads', $manage ),
			array( '/threads/(?P<id>\d+)', 'GET', 'thread', $manage, $id_arg ),
			array( '/threads/(?P<id>\d+)/summarize', 'POST', 'summarize', $generate, $id_arg ),
			array( '/threads/(?P<id>\d+)/summary', 'POST', 'save_summary', $manage, $id_arg ),
			array( '/threads/(?P<id>\d+)/summary', 'DELETE', 'delete_summary', $manage, $id_arg ),
			array( '/stats', 'GET', 'stats', $manage ),
			array( '/scan', 'POST', 'scan', $manage ),
			array( '/test', 'POST', 'test', $manage ),
			array( '/export/csv', 'GET', 'export', $manage ),
		);
		foreach ( $routes as $route ) {
			register_rest_route(
				self::NS,
				$route[0],
				array(
					'methods'             => $route[1],
					'callback'            => array( $this, $route[2] ),
					'permission_callback' => $route[3],
					'args'                => $route[4] ?? array(),
				)
			);
		}
	}

	/**
	 * Filters from a request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	private function filters( \WP_REST_Request $request ) {
		return array(
			'view'     => sanitize_key( (string) $request->get_param( 'view' ) ),
			'source'   => sanitize_key( (string) $request->get_param( 'source' ) ),
			'label'    => sanitize_key( (string) $request->get_param( 'label' ) ),
			'post_id'  => absint( $request->get_param( 'post_id' ) ),
			'search'   => mb_substr( sanitize_text_field( (string) $request->get_param( 'search' ) ), 0, 100 ),
			'page'     => max( 1, absint( $request->get_param( 'page' ) ) ),
			'per_page' => min( 100, max( 1, absint( $request->get_param( 'per_page' ) ? $request->get_param( 'per_page' ) : 20 ) ) ),
		);
	}

	/**
	 * GET /items.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function items( \WP_REST_Request $request ) {
		// WP-Cron may not reach this site (Docker, DISABLE_WP_CRON): work the AI queue while someone is watching.
		if ( Items::queue_age() > 30 ) {
			Classifier::process_queue( 6 );
		}
		$args = $this->filters( $request );
		$res  = Items::query( $args );
		return $this->respond(
			array(
				'items'  => array_map( array( Items::class, 'format' ), $res['items'] ),
				'total'  => $res['total'],
				'counts' => Items::counts( array_diff_key( $args, array_flip( array( 'view', 'page', 'per_page' ) ) ) ),
				'queued' => count( Items::queued( 100 ) ),
			)
		);
	}

	/**
	 * Loads an item or a 404.
	 *
	 * @param int $id Id.
	 * @return array|\WP_Error
	 */
	private function load( $id ) {
		$item = Items::get( (int) $id );
		return $item ? $item : new \WP_Error( 'dev3cm_not_found', __( 'This item no longer exists.', 'dev3-ai-comment-moderator' ), array( 'status' => 404 ) );
	}

	/**
	 * Full item (with templates).
	 *
	 * @param array $item Item.
	 * @return array
	 */
	private function full( array $item ) {
		$out              = Items::format( $item, true );
		$out['templates'] = array_map(
			static function ( $tpl ) use ( $item ) {
				return array(
					'name' => $tpl['name'],
					'text' => Writer::fill_template( $tpl['text'], $item ),
				);
			},
			Settings::templates()
		);
		return $out;
	}

	/**
	 * GET /items/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function item( \WP_REST_Request $request ) {
		$item = $this->load( $request['id'] );
		return $this->respond( is_wp_error( $item ) ? $item : $this->full( $item ) );
	}

	/**
	 * Applies a moderator action to one item.
	 *
	 * @param array  $item   Item.
	 * @param string $action approve|hold|spam|trash|dismiss.
	 * @return bool
	 */
	public static function act( array $item, $action ) {
		$targets = array(
			'approve' => 'live',
			'hold'    => 'held',
			'spam'    => 'spam',
			'trash'   => 'trash',
		);
		$fields  = array(
			'reviewed'    => 1,
			'reviewed_by' => get_current_user_id(),
		);
		if ( isset( $targets[ $action ] ) ) {
			if ( ! Sources::set_status( $item['source'], $item['object_id'], $targets[ $action ] ) ) {
				return false;
			}
			$fields['status'] = $targets[ $action ];
		}
		Items::update( $item['id'], $fields );
		Items::log( $item['id'], 'dismiss' === $action ? 'reviewed' : Pipeline::verb( $targets[ $action ] ), 'user:' . get_current_user_id() );
		return true;
	}

	/**
	 * POST /items/<id>/action.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function action( \WP_REST_Request $request ) {
		$item = $this->load( $request['id'] );
		if ( is_wp_error( $item ) ) {
			return $this->respond( $item );
		}
		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			return $this->respond( new \WP_Error( 'dev3cm_bad_action', __( 'Unknown action.', 'dev3-ai-comment-moderator' ), array( 'status' => 400 ) ) );
		}
		if ( ! self::act( $item, $action ) ) {
			return $this->respond( new \WP_Error( 'dev3cm_action_failed', __( 'The status could not be changed. The item may have been deleted.', 'dev3-ai-comment-moderator' ), array( 'status' => 409 ) ) );
		}
		return $this->respond( $this->full( Items::get( $item['id'] ) ) );
	}

	/**
	 * POST /items/bulk { ids, action }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function bulk( \WP_REST_Request $request ) {
		$ids    = array_slice( array_filter( array_map( 'absint', (array) $request->get_param( 'ids' ) ) ), 0, 200 );
		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		if ( ! $ids ) {
			return $this->respond( new \WP_Error( 'dev3cm_no_ids', __( 'Select at least one item.', 'dev3-ai-comment-moderator' ), array( 'status' => 400 ) ) );
		}
		if ( 'rescan' === $action ) {
			if ( ! \Dev3\AiCommentModerator\Core3\Core\Capabilities::can_generate() ) {
				return $this->respond( new \WP_Error( 'dev3cm_forbidden', __( 'You do not have permission to do that.', 'dev3-ai-comment-moderator' ), array( 'status' => 403 ) ) );
			}
			if ( ! Classifier::available( 'admin' ) ) {
				return $this->respond( new \WP_Error( 'dev3cm_ai_off', __( 'DEV3 AI is not available. Add a Gemini API key in API Config and set the AI mode in Policy.', 'dev3-ai-comment-moderator' ), array( 'status' => 400 ) ) );
			}
			$targets = array();
			foreach ( $ids as $id ) {
				$item = Items::get( $id );
				if ( $item ) {
					$targets[] = $item['source'] . ':' . $item['object_id'];
				}
			}
			$job = Jobs::create( Classifier::SCAN_JOB, $targets, array( 'ai' => true ), 8 );
			return $this->respond( is_wp_error( $job ) ? $job : array( 'job' => Jobs::get( $job ) ), 201 );
		}
		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			return $this->respond( new \WP_Error( 'dev3cm_bad_action', __( 'Unknown action.', 'dev3-ai-comment-moderator' ), array( 'status' => 400 ) ) );
		}
		$done = 0;
		foreach ( $ids as $id ) {
			$item = Items::get( $id );
			if ( $item && self::act( $item, $action ) ) {
				++$done;
			}
		}
		return $this->respond(
			array(
				'done'   => $done,
				'failed' => count( $ids ) - $done,
			)
		);
	}

	/**
	 * POST /items/<id>/classify (AI now).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function classify( \WP_REST_Request $request ) {
		$item = $this->load( $request['id'] );
		if ( is_wp_error( $item ) ) {
			return $this->respond( $item );
		}
		$result = Classifier::classify_items( array( $item['id'] ), 'admin' );
		if ( isset( $result[ $item['id'] ] ) && is_wp_error( $result[ $item['id'] ] ) ) {
			return $this->respond( $result[ $item['id'] ] );
		}
		return $this->respond( $this->full( Items::get( $item['id'] ) ) );
	}

	/**
	 * POST /items/<id>/reply-draft.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function reply_draft( \WP_REST_Request $request ) {
		$item = $this->load( $request['id'] );
		if ( is_wp_error( $item ) ) {
			return $this->respond( $item );
		}
		return $this->respond( Writer::reply( $item, sanitize_textarea_field( (string) $request->get_param( 'instructions' ) ) ) );
	}

	/**
	 * POST /items/<id>/reply { content, approve }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function reply( \WP_REST_Request $request ) {
		$item = $this->load( $request['id'] );
		if ( is_wp_error( $item ) ) {
			return $this->respond( $item );
		}
		$content = mb_substr( (string) $request->get_param( 'content' ), 0, 5000 );
		if ( $request->get_param( 'approve' ) && 'live' !== $item['status'] ) {
			self::act( $item, 'approve' );
		}
		$id = Sources::reply( $item['source'], $item['object_id'], $item['post_id'], $content );
		if ( is_wp_error( $id ) ) {
			return $this->respond( $id );
		}
		Items::log( $item['id'], 'replied', 'user:' . get_current_user_id(), Text::excerpt( $content, 120 ) );
		$out             = $this->full( Items::get( $item['id'] ) );
		$out['reply_id'] = $id;
		return $this->respond( $out, 201 );
	}

	/**
	 * GET /threads.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function threads( \WP_REST_Request $request ) {
		$args         = $this->filters( $request );
		$args['sort'] = sanitize_key( (string) $request->get_param( 'sort' ) );
		return $this->respond( Items::threads( $args ) );
	}

	/**
	 * GET /threads/<post_id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function thread( \WP_REST_Request $request ) {
		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return $this->respond( new \WP_Error( 'dev3cm_not_found', __( 'This post no longer exists.', 'dev3-ai-comment-moderator' ), array( 'status' => 404 ) ) );
		}
		$stats  = Items::threads( array( 'post_id' => $post_id ) );
		$recent = Items::query(
			array(
				'view'     => 'all',
				'post_id'  => $post_id,
				'per_page' => 8,
			)
		);
		return $this->respond(
			array(
				'post'     => array(
					'id'    => $post_id,
					'title' => wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES ),
					'url'   => (string) get_permalink( $post ),
					'type'  => (string) $post->post_type,
				),
				'stats'    => $stats['items'][0] ?? null,
				'summary'  => Threads::format( Threads::get( $post_id ) ),
				'insights' => Writer::insights( $post_id ),
				'recent'   => array_map( array( Items::class, 'format' ), $recent['items'] ),
			)
		);
	}

	/**
	 * POST /threads/<post_id>/summarize (AI draft).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function summarize( \WP_REST_Request $request ) {
		return $this->respond( Writer::summarize( (int) $request['id'] ) );
	}

	/**
	 * POST /threads/<post_id>/summary (accept / update).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save_summary( \WP_REST_Request $request ) {
		$post_id = (int) $request['id'];
		if ( ! get_post( $post_id ) ) {
			return $this->respond( new \WP_Error( 'dev3cm_not_found', __( 'This post no longer exists.', 'dev3-ai-comment-moderator' ), array( 'status' => 404 ) ) );
		}
		$params = (array) $request->get_json_params();
		if ( ! $params ) {
			$params = (array) $request->get_params();
		}
		$current = Threads::get( $post_id );
		if ( '' === trim( (string) ( $params['summary'] ?? '' ) ) && $current ) {
			// Toggle-only update (e.g. "show on site").
			$params = array_merge( $current, array_intersect_key( $params, array_flip( array( 'show_public' ) ) ) );
		}
		if ( '' === trim( (string) ( $params['summary'] ?? '' ) ) ) {
			return $this->respond( new \WP_Error( 'dev3cm_empty_summary', __( 'The summary is empty.', 'dev3-ai-comment-moderator' ), array( 'status' => 400 ) ) );
		}
		return $this->respond( Threads::format( Threads::save( $post_id, $params ) ) );
	}

	/**
	 * DELETE /threads/<post_id>/summary.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function delete_summary( \WP_REST_Request $request ) {
		Threads::delete( (int) $request['id'] );
		return $this->respond( array( 'deleted' => true ) );
	}

	/**
	 * GET /stats.
	 *
	 * @return \WP_REST_Response
	 */
	public function stats() {
		$stats             = Items::stats();
		$stats['negative'] = Items::threads(
			array(
				'sort'     => 'negative',
				'per_page' => 5,
			)
		)['items'];
		$held              = Items::query(
			array(
				'view'     => 'needs',
				'per_page' => 5,
			)
		);
		$stats['latest']   = array_map( array( Items::class, 'format' ), $held['items'] );
		$stats['sources']  = array(
			'woo'     => Sources::has_woo(),
			'bbpress' => Sources::has_bbpress(),
			'akismet' => class_exists( '\Akismet' ),
		);
		$stats['ai']       = array(
			'available' => Classifier::available( 'admin' ),
			'mode'      => (string) Settings::get( 'ai_mode', 'sync' ),
			'queued'    => count( Items::queued( 100 ) ),
		);
		return $this->respond( $stats );
	}

	/**
	 * POST /scan { days, sources[], ai, move }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function scan( \WP_REST_Request $request ) {
		$active = Jobs::active( Classifier::SCAN_JOB );
		if ( $active ) {
			return $this->respond( array( 'job' => $active ) );
		}
		$days    = min( 3650, max( 1, absint( $request->get_param( 'days' ) ? $request->get_param( 'days' ) : 30 ) ) );
		$sources = array_values( array_intersect( array_map( 'sanitize_key', (array) $request->get_param( 'sources' ) ), Sources::ALL ) );
		if ( ! $sources ) {
			$sources = Sources::ALL;
		}
		$sources = array_values( array_filter( $sources, array( Sources::class, 'enabled' ) ) );
		$targets = Sources::scan_targets( $days, $sources, 2000 );
		if ( ! $targets ) {
			return $this->respond( new \WP_Error( 'dev3cm_nothing', __( 'Nothing to scan in that period.', 'dev3-ai-comment-moderator' ), array( 'status' => 404 ) ) );
		}
		$ai = (bool) $request->get_param( 'ai' ) && Classifier::available( 'admin' ) && \Dev3\AiCommentModerator\Core3\Core\Capabilities::can_generate();
		$id = Jobs::create(
			Classifier::SCAN_JOB,
			$targets,
			array(
				'ai'   => $ai,
				'move' => (bool) $request->get_param( 'move' ),
			),
			$ai ? 8 : 40
		);
		return $this->respond( is_wp_error( $id ) ? $id : array( 'job' => Jobs::get( $id ) ), 201 );
	}

	/**
	 * POST /test: what would the rules + AI do with this text? Nothing is saved.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function test( \WP_REST_Request $request ) {
		$text = mb_substr( sanitize_textarea_field( (string) $request->get_param( 'text' ) ), 0, 3000 );
		if ( '' === trim( $text ) ) {
			return $this->respond( new \WP_Error( 'dev3cm_empty', __( 'Paste a sample comment first.', 'dev3-ai-comment-moderator' ), array( 'status' => 400 ) ) );
		}
		$input  = array(
			'text'      => $text,
			'name'      => sanitize_text_field( (string) $request->get_param( 'name' ) ),
			'email'     => sanitize_email( (string) $request->get_param( 'email' ) ),
			'url'       => esc_url_raw( (string) $request->get_param( 'url' ) ),
			'ip_hash'   => '',
			'post_id'   => 0,
			'skip_rate' => true,
		);
		$flags  = Rules::screen( $input );
		$labels = array();
		$error  = null;
		if ( $request->get_param( 'ai' ) ) {
			if ( ! \Dev3\AiCommentModerator\Core3\Core\Capabilities::can_generate() ) {
				$error = array(
					'code'    => 'dev3cm_forbidden',
					'message' => __( 'You do not have permission to use DEV3 AI.', 'dev3-ai-comment-moderator' ),
				);
			} else {
				$result = Classifier::classify(
					array(
						0 => array(
							'source' => 'comment',
							'text'   => $text,
							'author' => $input['name'],
						),
					),
					'admin'
				);
				if ( is_wp_error( $result ) ) {
					$error = \Dev3\AiCommentModerator\Core3\Api\Api_Error::to_response( $result )->get_data();
				} else {
					$labels = $result['labels'][0] ?? array();
				}
			}
		}
		$decision = Policy::decide( $flags, $labels );
		$pseudo   = array(
			'rule_flags' => $flags,
			'labels'     => $labels,
			'reason'     => (string) ( $labels['reason'] ?? '' ),
		);
		return $this->respond(
			array(
				'decision'  => $decision['decision'],
				'reasons'   => Policy::reasons( $pseudo ),
				'labels'    => $labels,
				'sentiment' => $labels ? (float) $labels['sentiment'] : Text::sentiment( $text ),
				'ai_error'  => $error,
				'ai'        => (bool) $labels,
			)
		);
	}

	/**
	 * GET /export/csv?view=… → { filename, csv }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function export( \WP_REST_Request $request ) {
		$args = $this->filters( $request );
		unset( $args['page'], $args['per_page'] );
		if ( '' === $args['view'] ) {
			$args['view'] = 'all';
		}
		$rows = Items::export_rows( $args );
		$fh   = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $fh, array( 'id', 'source', 'object_id', 'post_id', 'author', 'status', 'decision', 'reasons', 'sentiment', 'reviewed', 'excerpt', 'created_at' ) );
		foreach ( $rows as $row ) {
			// Neutralise spreadsheet formulas in user-written cells.
			$row = array_map(
				static function ( $cell ) {
					$cell = (string) $cell;
					return preg_match( '/^[=+\-@\t\r]/', $cell ) ? "'" . $cell : $cell;
				},
				$row
			);
			fputcsv( $fh, $row );
		}
		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $this->respond(
			array(
				'filename' => 'dev3cm-moderation-' . gmdate( 'Y-m-d' ) . '.csv',
				'csv'      => (string) $csv,
				'rows'     => count( $rows ),
			)
		);
	}
}
