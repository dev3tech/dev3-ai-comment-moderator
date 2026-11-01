<?php
/**
 * Plugin Name:       DEV3 AI Comment & Community Moderator for WordPress
 * Plugin URI:        https://dev3.tech/item/dev3-ai-comment-moderator
 * Description:       AI moderation for WordPress comments, WooCommerce reviews and bbPress replies: rule screening, toxicity and spam classification, a moderation queue, reply drafts, thread summaries and sentiment.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            DEV3 Technologies
 * Author URI:        https://dev3.tech
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dev3-ai-comment-moderator
 * Domain Path:       /languages
 *
 * @package Dev3\AiCommentModerator
 */

defined( 'ABSPATH' ) || exit;

define( 'DEV3CM_VERSION', '1.0.0' );
define( 'DEV3CM_FILE', __FILE__ );
define( 'DEV3CM_PATH', plugin_dir_path( __FILE__ ) );
define( 'DEV3CM_URL', plugin_dir_url( __FILE__ ) );
define( 'DEV3CM_SLUG', 'dev3-ai-comment-moderator' );
define( 'DEV3CM_NAME', 'DEV3 AI Comment & Community Moderator for WordPress' );
define( 'DEV3CM_MIN_PHP', '7.4' );
define( 'DEV3CM_MIN_WP', '6.0' );
define( 'DEV3CM_DB_VERSION', '1.0.0' );

if ( version_compare( PHP_VERSION, DEV3CM_MIN_PHP, '<' ) ) {
	return;
}

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Dev3\AiCommentModerator\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$file = DEV3CM_PATH . 'includes/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( '\Dev3\AiCommentModerator\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\Dev3\AiCommentModerator\Plugin', 'deactivate' ) );
add_action( 'plugins_loaded', array( '\Dev3\AiCommentModerator\Plugin', 'instance' ) );
