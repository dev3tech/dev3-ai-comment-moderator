<?php
/**
 * Public REST route for the front-end "be kind" nudge (logged-out writers too).
 *
 * @package Dev3\AiCommentModerator
 */

namespace Dev3\AiCommentModerator\Rest;

use Dev3\AiCommentModerator\Ai\Writer;
use Dev3\AiCommentModerator\Core3\Rest\Base_Controller;
use Dev3\AiCommentModerator\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under dev3cm/v1/public.
 */
class Public_Controller extends Base_Controller {

	/**
	 * Registers routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/public/nudge',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'nudge' ),
				'permission_callback' => array( $this, 'nudge_permission' ),
				'args'                => array(
					'text' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);
	}

	/**
	 * Same-origin + 12 checks per minute per IP.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function nudge_permission( \WP_REST_Request $request ) {
		return $this->public_permission( $request, 'nudge', 12 );
	}

	/**
	 * POST /public/nudge { text } → { unkind, reasons[], message, suggestion, ai }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function nudge( \WP_REST_Request $request ) {
		if ( ! Settings::get( 'nudge_enabled', true ) ) {
			return $this->respond( new \WP_Error( 'dev3cm_nudge_off', __( 'This check is turned off.', 'dev3-ai-comment-moderator' ), array( 'status' => 404 ) ) );
		}
		$text = (string) $request->get_param( 'text' );
		if ( mb_strlen( $text ) > 5000 ) {
			$text = mb_substr( $text, 0, 5000 );
		}
		return $this->respond( Writer::nudge( $text ) );
	}
}
