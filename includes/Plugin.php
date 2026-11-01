<?php
/**
 * Bootstrap and wiring.
 *
 * @package Dev3\AiCommentModerator
 */

namespace Dev3\AiCommentModerator;

use Dev3\AiCommentModerator\Core3\Core\Assets;
use Dev3\AiCommentModerator\Core3\Core\Capabilities;
use Dev3\AiCommentModerator\Core3\Core\Privacy;
use Dev3\AiCommentModerator\Core3\Core\Schema;
use Dev3\AiCommentModerator\Data\Items;
use Dev3\AiCommentModerator\Data\Tables;
use Dev3\AiCommentModerator\Moderation\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton entry point.
 */
final class Plugin {

	const CRON_HOOK = 'dev3cm_daily';

	/**
	 * Instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Boots once.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Activation.
	 */
	public static function activate() {
		self::register_schema();
		Schema::install( DEV3CM_DB_VERSION );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( Ai\Classifier::QUEUE_HOOK );
	}

	/**
	 * Plugin tables / settings defaults are registered here so activation and runtime agree.
	 */
	public static function register_schema() {
		if ( ! has_filter( 'dev3cm_settings_defaults', array( Settings::class, 'defaults' ) ) ) {
			add_filter( 'dev3cm_settings_defaults', array( Settings::class, 'defaults' ) );
			add_filter( 'dev3cm_settings_sanitize', array( Settings::class, 'sanitize' ), 10, 3 );
			add_filter( 'dev3cm_schema_sql', array( Tables::class, 'schema' ), 10, 2 );
		}
	}

	/**
	 * Hooks.
	 */
	private function boot() {
		self::register_schema();
		add_action( 'init', array( $this, 'textdomain' ), 1 );
		add_action( 'before_woocommerce_init', array( $this, 'declare_compat' ) );
		add_action( 'admin_init', static fn() => Schema::maybe_install( DEV3CM_DB_VERSION ) );
		add_action( self::CRON_HOOK, array( $this, 'daily' ) );
		add_action( 'rest_api_init', array( $this, 'rest' ) );
		add_action( 'init', array( Ai\Prompts_Library::class, 'register' ), 5 );
		Privacy::init();
		add_action( 'init', array( $this, 'privacy' ) );
		// Background job types must be registered on every request (the runner looks them up).
		Ai\Classifier::register();
		( new Moderation\Pipeline() )->register();
		( new Frontend\Nudge() )->register();
		( new Frontend\Summary() )->register();
		add_action( 'admin_init', array( $this, 'admin_assets' ) );
		if ( is_admin() ) {
			( new Admin\Admin_Page() )->register();
		}
	}

	/**
	 * Daily housekeeping: core prune + moderation-record retention.
	 */
	public function daily() {
		Schema::prune_usage();
		Items::prune( (int) Settings::get( 'retention_days', 365 ) );
	}

	/**
	 * Admin bundle (registered once; Assets::register adds the boot script on every call).
	 */
	public function admin_assets() {
		if ( wp_script_is( Assets::handle( 'admin' ), 'registered' ) ) {
			return;
		}
		Assets::register(
			array( 'admin' ),
			array(
				'siteName'    => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
				'siteUrl'     => home_url( '/' ),
				'woo'         => Sources::has_woo(),
				'bbpress'     => Sources::has_bbpress(),
				'akismet'     => class_exists( '\Akismet' ),
				'canGenerate' => Capabilities::can_generate(),
				'commentsUrl' => admin_url( 'edit-comments.php' ),
				'strings'     => Frontend\Nudge::strings(),
				'words'       => Data\Text::profanity_words(),
			)
		);
	}

	/**
	 * GDPR exporter/eraser for moderation records (registered on init so the label can be translated).
	 */
	public function privacy() {
		Privacy::add_exporter( __( 'Comment moderation records', 'dev3-ai-comment-moderator' ), array( Items::class, 'export_personal_data' ) );
		Privacy::add_eraser( __( 'Comment moderation records', 'dev3-ai-comment-moderator' ), array( Items::class, 'erase_personal_data' ) );
	}

	/**
	 * Translations (on init, as WP 6.7 expects).
	 */
	public function textdomain() {
		load_plugin_textdomain( 'dev3-ai-comment-moderator', false, dirname( plugin_basename( DEV3CM_FILE ) ) . '/languages' );
	}

	/**
	 * HPOS + Cart/Checkout blocks compatibility (the plugin moderates product reviews only; it never touches orders or checkout).
	 */
	public function declare_compat() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', DEV3CM_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', DEV3CM_FILE, true );
		}
	}

	/**
	 * REST routes.
	 */
	public function rest() {
		( new Core3\Rest\Core_Controller() )->register_routes();
		( new Rest\Admin_Controller() )->register_routes();
		( new Rest\Public_Controller() )->register_routes();
	}
}
