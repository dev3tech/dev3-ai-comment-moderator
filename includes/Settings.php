<?php
/**
 * Plugin-specific settings (shared AI settings live in Core3\Core\Settings_Store).
 *
 * @package Dev3\AiCommentModerator
 */

namespace Dev3\AiCommentModerator;

use Dev3\AiCommentModerator\Core3\Core\Settings_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Hooked into dev3cm_settings_defaults / dev3cm_settings_sanitize.
 */
class Settings {

	const ACTIONS    = array( 'off', 'flag', 'hold', 'spam' );
	const LABELS     = array( 'spam', 'toxicity', 'harassment', 'off_topic', 'pii' );
	const RULES      = array( 'links', 'blocklist', 'rate', 'duplicate', 'pii', 'profanity', 'akismet' );
	const TONES      = array( 'friendly', 'professional', 'warm', 'playful', 'concise', 'empathetic' );
	const AI_MODES   = array( 'sync', 'async', 'off' );
	const SUMMARY_AT = array( 'off', 'after_content' );

	/**
	 * Reads one setting.
	 *
	 * @param string $key      Key.
	 * @param mixed  $fallback Fallback.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		return Settings_Store::get( $key, $fallback );
	}

	/**
	 * Adds plugin defaults. Strings that shoppers see are translated at render time, so defaults stay '' here.
	 *
	 * @param array $defaults Shared defaults.
	 * @return array
	 */
	public static function defaults( $defaults ) {
		return array_merge(
			$defaults,
			array(
				// Sources.
				'src_comments'            => true,
				'src_reviews'             => true,
				'src_bbpress'             => true,
				'skip_trusted'            => true,
				// Rules.
				'rule_links_action'       => 'hold',
				'rule_links_max'          => 2,
				'rule_blocklist_action'   => 'hold',
				'rule_blocklist_words'    => '',
				'rule_rate_action'        => 'hold',
				'rule_rate_max'           => 5,
				'rule_rate_minutes'       => 10,
				'rule_duplicate_action'   => 'spam',
				'rule_duplicate_days'     => 30,
				'rule_pii_action'         => 'hold',
				'rule_profanity_action'   => 'flag',
				'rule_akismet_action'     => 'spam',
				// AI policy.
				'ai_mode'                 => 'sync',
				'ai_timeout'              => 8,
				'ai_spam_action'          => 'spam',
				'ai_spam_threshold'       => 0.85,
				'ai_toxicity_action'      => 'hold',
				'ai_toxicity_threshold'   => 0.75,
				'ai_harassment_action'    => 'hold',
				'ai_harassment_threshold' => 0.7,
				'ai_off_topic_action'     => 'flag',
				'ai_off_topic_threshold'  => 0.85,
				'ai_pii_action'           => 'hold',
				'ai_pii_threshold'        => 0.7,
				// Brand voice + replies.
				'voice_tone'              => 'friendly',
				'voice_guidelines'        => '',
				'voice_signoff'           => '',
				'voice_avoid'             => '',
				'reply_templates'         => array(),
				// Front-end.
				'nudge_enabled'           => true,
				'nudge_ai'                => false,
				'nudge_suggest'           => true,
				'nudge_title'             => '',
				'nudge_message'           => '',
				'note_enabled'            => false,
				'note_text'               => '',
				'summary_display'         => 'off',
				'summary_ai_note'         => true,
				'accent_color'            => '#2563eb',
				// Data.
				'retention_days'          => 365,
				'delete_on_uninstall'     => false,
			)
		);
	}

	/**
	 * Sanitizes plugin keys.
	 *
	 * @param array $out     Sanitized so far.
	 * @param array $input   Raw input.
	 * @param array $current Current.
	 * @return array
	 */
	public static function sanitize( $out, $input, $current ) {
		unset( $current );
		$bools = array( 'src_comments', 'src_reviews', 'src_bbpress', 'skip_trusted', 'nudge_enabled', 'nudge_ai', 'nudge_suggest', 'note_enabled', 'summary_ai_note', 'delete_on_uninstall' );
		foreach ( $bools as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = (bool) $input[ $key ];
			}
		}
		foreach ( self::RULES as $rule ) {
			$key = "rule_{$rule}_action";
			if ( isset( $input[ $key ] ) && in_array( $input[ $key ], self::ACTIONS, true ) ) {
				$out[ $key ] = $input[ $key ];
			}
		}
		foreach ( self::LABELS as $label ) {
			$key = "ai_{$label}_action";
			if ( isset( $input[ $key ] ) && in_array( $input[ $key ], self::ACTIONS, true ) ) {
				$out[ $key ] = $input[ $key ];
			}
			$key = "ai_{$label}_threshold";
			if ( isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ) {
				$out[ $key ] = round( min( 1, max( 0.05, (float) $input[ $key ] ) ), 2 );
			}
		}
		$ints = array(
			'rule_links_max'      => array( 0, 50 ),
			'rule_rate_max'       => array( 1, 500 ),
			'rule_rate_minutes'   => array( 1, 1440 ),
			'rule_duplicate_days' => array( 1, 365 ),
			'ai_timeout'          => array( 3, 30 ),
			'retention_days'      => array( 0, 3650 ),
		);
		foreach ( $ints as $key => $range ) {
			if ( isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ) {
				$out[ $key ] = min( $range[1], max( $range[0], (int) $input[ $key ] ) );
			}
		}
		if ( isset( $input['rule_blocklist_words'] ) ) {
			$out['rule_blocklist_words'] = mb_substr( sanitize_textarea_field( wp_unslash( (string) $input['rule_blocklist_words'] ) ), 0, 20000 );
		}
		if ( isset( $input['ai_mode'] ) && in_array( $input['ai_mode'], self::AI_MODES, true ) ) {
			$out['ai_mode'] = $input['ai_mode'];
		}
		if ( isset( $input['voice_tone'] ) && in_array( $input['voice_tone'], self::TONES, true ) ) {
			$out['voice_tone'] = $input['voice_tone'];
		}
		$texts = array(
			'voice_guidelines' => 1500,
			'voice_avoid'      => 600,
			'nudge_message'    => 300,
			'note_text'        => 300,
		);
		foreach ( $texts as $key => $max ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = mb_substr( sanitize_textarea_field( wp_unslash( (string) $input[ $key ] ) ), 0, $max );
			}
		}
		$lines = array(
			'voice_signoff' => 120,
			'nudge_title'   => 120,
		);
		foreach ( $lines as $key => $max ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = mb_substr( sanitize_text_field( wp_unslash( (string) $input[ $key ] ) ), 0, $max );
			}
		}
		if ( isset( $input['summary_display'] ) && in_array( $input['summary_display'], self::SUMMARY_AT, true ) ) {
			$out['summary_display'] = $input['summary_display'];
		}
		if ( isset( $input['accent_color'] ) ) {
			$color = sanitize_hex_color( (string) $input['accent_color'] );
			if ( $color ) {
				$out['accent_color'] = $color;
			}
		}
		if ( isset( $input['reply_templates'] ) && is_array( $input['reply_templates'] ) ) {
			$templates = array();
			foreach ( array_slice( $input['reply_templates'], 0, 30 ) as $tpl ) {
				if ( ! is_array( $tpl ) ) {
					continue;
				}
				$name = mb_substr( sanitize_text_field( wp_unslash( (string) ( $tpl['name'] ?? '' ) ) ), 0, 80 );
				$text = mb_substr( sanitize_textarea_field( wp_unslash( (string) ( $tpl['text'] ?? '' ) ) ), 0, 1500 );
				if ( '' !== $name && '' !== $text ) {
					$templates[] = array(
						'name' => $name,
						'text' => $text,
					);
				}
			}
			$out['reply_templates'] = $templates;
		}
		return $out;
	}

	/**
	 * Default reply templates (used while the admin has saved none).
	 *
	 * @return array
	 */
	public static function default_templates() {
		return array(
			array(
				'name' => __( 'Thanks for the feedback', 'dev3-ai-comment-moderator' ),
				'text' => __( 'Thanks for sharing your thoughts, {author}! We really appreciate you taking the time to comment on "{post}".', 'dev3-ai-comment-moderator' ),
			),
			array(
				'name' => __( 'Keep it kind', 'dev3-ai-comment-moderator' ),
				'text' => __( 'Hi {author}, thanks for joining the discussion. Please keep comments respectful so everyone feels welcome here.', 'dev3-ai-comment-moderator' ),
			),
			array(
				'name' => __( 'We will look into it', 'dev3-ai-comment-moderator' ),
				'text' => __( 'Thanks {author}, good question. We are checking this and will update this thread soon. The {site} team', 'dev3-ai-comment-moderator' ),
			),
		);
	}

	/**
	 * Reply templates (saved or defaults).
	 *
	 * @return array
	 */
	public static function templates() {
		$saved = (array) self::get( 'reply_templates', array() );
		return $saved ? $saved : self::default_templates();
	}
}
