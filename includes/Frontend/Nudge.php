<?php
/**
 * Front-end: "be kind" nudge on comment, review and bbPress forms, plus the optional community note.
 * Assets load only when a form is rendered; nothing calls the network on page load.
 *
 * @package Dev3\AiCommentModerator
 */

namespace Dev3\AiCommentModerator\Frontend;

use Dev3\AiCommentModerator\Core3\Api\Ai;
use Dev3\AiCommentModerator\Data\Text;
use Dev3\AiCommentModerator\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Nudge.
 */
class Nudge {

	const HANDLE = 'dev3cm-frontend';

	/**
	 * Registers hooks.
	 */
	public function register() {
		add_action( 'wp_enqueue_scripts', array( self::class, 'register_assets' ) );
		add_action( 'comment_form_before', array( self::class, 'enqueue' ) );
		add_action( 'bbp_theme_before_reply_form', array( self::class, 'enqueue' ) );
		add_action( 'bbp_theme_before_topic_form', array( self::class, 'enqueue' ) );
		add_filter( 'comment_form_field_comment', array( self::class, 'note_after_field' ) );
		add_action( 'bbp_theme_after_reply_form_content', array( self::class, 'echo_note' ) );
		add_action( 'bbp_theme_after_topic_form_content', array( self::class, 'echo_note' ) );
	}

	/**
	 * Registers the script + style (enqueued later only when a form shows).
	 */
	public static function register_assets() {
		if ( wp_script_is( self::HANDLE, 'registered' ) ) {
			return;
		}
		$asset_file = DEV3CM_PATH . 'build/frontend.asset.php';
		$asset      = is_readable( $asset_file ) ? include $asset_file : array( 'version' => DEV3CM_VERSION );
		wp_register_script( self::HANDLE, DEV3CM_URL . 'build/frontend.js', array(), $asset['version'], true );
		if ( function_exists( 'wp_script_add_data' ) ) {
			wp_script_add_data( self::HANDLE, 'strategy', 'defer' );
		}
		wp_register_style( self::HANDLE, DEV3CM_URL . 'build/frontend.css', array(), $asset['version'] );
	}

	/**
	 * Shopper/reader-facing strings (neutral, no DEV3 branding).
	 *
	 * @return array
	 */
	public static function strings() {
		$title   = (string) Settings::get( 'nudge_title', '' );
		$message = (string) Settings::get( 'nudge_message', '' );
		return array(
			'title'      => '' !== $title ? $title : __( 'Before you post…', 'dev3-ai-comment-moderator' ),
			'message'    => '' !== $message ? $message : __( 'Your comment may come across as hurtful to others. Would you like to edit it?', 'dev3-ai-comment-moderator' ),
			'edit'       => __( 'Edit my comment', 'dev3-ai-comment-moderator' ),
			'use'        => __( 'Use suggested wording', 'dev3-ai-comment-moderator' ),
			'post'       => __( 'Post anyway', 'dev3-ai-comment-moderator' ),
			'checking'   => __( 'Checking your comment…', 'dev3-ai-comment-moderator' ),
			'suggestion' => __( 'A kinder way to say it:', 'dev3-ai-comment-moderator' ),
			'reasons'    => array(
				'insult'   => __( 'It contains words that may hurt someone.', 'dev3-ai-comment-moderator' ),
				'shouting' => __( 'Writing in capital letters can read as shouting.', 'dev3-ai-comment-moderator' ),
				'pile_on'  => __( 'Lots of exclamation or question marks can feel aggressive.', 'dev3-ai-comment-moderator' ),
			),
		);
	}

	/**
	 * Boot config for the script.
	 *
	 * @return array
	 */
	public static function config() {
		return array(
			'url'     => esc_url_raw( rest_url( 'dev3cm/v1/public/nudge' ) ),
			'ai'      => (bool) Settings::get( 'nudge_ai', false ) && Ai::enabled( 'frontend' ),
			'words'   => Text::profanity_words(),
			'strings' => self::strings(),
		);
	}

	/**
	 * Enqueues the nudge (once per page).
	 */
	public static function enqueue() {
		if ( ! Settings::get( 'nudge_enabled', true ) || wp_script_is( self::HANDLE, 'enqueued' ) ) {
			return;
		}
		self::register_assets();
		wp_enqueue_script( self::HANDLE );
		wp_enqueue_style( self::HANDLE );
		$accent = (string) Settings::get( 'accent_color', '#2563eb' );
		wp_add_inline_style( self::HANDLE, '.dev3cm-nudge,.dev3cm-summary{--dev3cm-accent:' . esc_attr( $accent ) . ';}' );
		wp_add_inline_script( self::HANDLE, 'window.dev3cmNudge=' . wp_json_encode( self::config() ) . ';', 'before' );
	}

	/**
	 * Community note HTML.
	 *
	 * @return string
	 */
	public static function note() {
		if ( ! Settings::get( 'note_enabled', false ) ) {
			return '';
		}
		$text = (string) Settings::get( 'note_text', '' );
		if ( '' === $text ) {
			$text = __( 'Please keep it kind and on topic. Comments are moderated.', 'dev3-ai-comment-moderator' );
		}
		self::register_assets();
		wp_enqueue_style( self::HANDLE );
		return '<p class="dev3cm-note">' . esc_html( $text ) . '</p>';
	}

	/**
	 * Filter comment_form_field_comment: note under the comment box.
	 *
	 * @param string $field Field HTML.
	 * @return string
	 */
	public static function note_after_field( $field ) {
		return $field . self::note();
	}

	/**
	 * Note under the bbPress editor.
	 */
	public static function echo_note() {
		echo wp_kses_post( self::note() );
	}
}
