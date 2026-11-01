<?php
/**
 * Admin menu + React app mount.
 *
 * @package Dev3\AiCommentModerator
 */

namespace Dev3\AiCommentModerator\Admin;

use Dev3\AiCommentModerator\Core3\Core\Assets;
use Dev3\AiCommentModerator\Core3\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Top-level menu page.
 */
class Admin_Page {

	/**
	 * Hook suffix.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Registers hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DEV3CM_FILE ), array( $this, 'links' ) );
	}

	/**
	 * Menu.
	 */
	public function menu() {
		$count = 0;
		if ( current_user_can( Capabilities::manage() ) ) {
			$count = (int) get_transient( 'dev3cm_needs_count' );
			if ( false === get_transient( 'dev3cm_needs_count' ) ) {
				$count = \Dev3\AiCommentModerator\Data\Items::counts()['needs'];
				set_transient( 'dev3cm_needs_count', $count, MINUTE_IN_SECONDS );
			}
		}
		$title = __( 'DEV3 AI Moderator', 'dev3-ai-comment-moderator' );
		if ( $count > 0 ) {
			$title .= ' <span class="awaiting-mod count-' . (int) $count . '"><span class="pending-count">' . esc_html( number_format_i18n( $count ) ) . '</span></span>';
		}
		$this->hook = (string) add_menu_page( DEV3CM_NAME, $title, Capabilities::manage(), DEV3CM_SLUG, array( $this, 'render' ), 'dashicons-shield', 26 );
	}

	/**
	 * Mount point.
	 */
	public function render() {
		// The h1 names the page for screen readers; wp-header-end keeps admin notices above the app.
		echo '<div class="wrap dev3cm-wrap"><h1 class="screen-reader-text">' . esc_html( DEV3CM_NAME ) . '</h1><hr class="wp-header-end"><div id="dev3cm-admin-root" class="dev3cm-root dev3cm-root--app"></div></div>';
	}

	/**
	 * Assets on our page only.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function enqueue( $hook ) {
		if ( $hook === $this->hook ) {
			Assets::enqueue( 'admin' );
		}
	}

	/**
	 * Settings link.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . DEV3CM_SLUG ) ) . '">' . esc_html__( 'Settings', 'dev3-ai-comment-moderator' ) . '</a>' );
		return $links;
	}
}
