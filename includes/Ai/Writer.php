<?php
/**
 * AI writing tasks: brand-voice reply drafts, discussion summaries and the front-end "be kind" nudge.
 * Every result is a draft: nothing is saved or posted here.
 *
 * @package Dev3\AiCommentModerator
 */

namespace Dev3\AiCommentModerator\Ai;

use Dev3\AiCommentModerator\Core3\Api\Ai;
use Dev3\AiCommentModerator\Core3\Api\Prompts;
use Dev3\AiCommentModerator\Data\Items;
use Dev3\AiCommentModerator\Data\Text;
use Dev3\AiCommentModerator\Data\Threads;
use Dev3\AiCommentModerator\Moderation\Sources;
use Dev3\AiCommentModerator\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Writer.
 */
class Writer {

	/**
	 * Tone descriptions.
	 *
	 * @return array
	 */
	public static function tones() {
		return array(
			'friendly'     => 'friendly and approachable, warm but not over the top',
			'professional' => 'professional, clear and courteous',
			'warm'         => 'warm, caring and personal',
			'playful'      => 'light, playful and upbeat (no sarcasm)',
			'concise'      => 'short, direct and helpful',
			'empathetic'   => 'empathetic, patient and reassuring',
		);
	}

	/**
	 * Source name for prompts.
	 *
	 * @param string $source Source.
	 * @return string
	 */
	private static function where( $source ) {
		$map = array(
			'comment'   => 'a blog comment',
			'review'    => 'a product review',
			'bbp_topic' => 'a new forum topic',
			'bbp_reply' => 'a forum reply',
		);
		return $map[ $source ] ?? 'a comment';
	}

	/**
	 * Reply draft for a record.
	 *
	 * @param array  $item         Item.
	 * @param string $instructions Moderator's extra instructions.
	 * @return array|\WP_Error { draft, model, tokens, cached }
	 */
	public static function reply( array $item, $instructions = '' ) {
		$post    = get_post( $item['post_id'] );
		$parent  = $item['parent_id'] ? Sources::parent_excerpt( $item['source'], $item['parent_id'] ) : null;
		$tones   = self::tones();
		$rating  = 'review' === $item['source'] ? (int) get_comment_meta( $item['object_id'], 'rating', true ) : 0;
		$comment = Sources::content( $item['source'], $item['object_id'] );
		if ( '' === $comment ) {
			return new \WP_Error( 'dev3cm_gone', __( 'This item no longer exists.', 'dev3-ai-comment-moderator' ), array( 'status' => 404 ) );
		}
		$result = Prompts::run(
			'reply',
			array(
				'site'         => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
				'tone'         => $tones[ Settings::get( 'voice_tone', 'friendly' ) ] ?? $tones['friendly'],
				'guidelines'   => self::or_none( (string) Settings::get( 'voice_guidelines', '' ) ),
				'avoid'        => self::or_none( (string) Settings::get( 'voice_avoid', '' ) ),
				'signoff'      => self::or_none( (string) Settings::get( 'voice_signoff', '' ) ),
				'source'       => self::where( $item['source'] ),
				'post'         => $post ? get_the_title( $post ) : '',
				'excerpt'      => $post ? Text::excerpt( has_excerpt( $post ) ? $post->post_excerpt : $post->post_content, 300 ) : '',
				'parent'       => $parent ? 'It answers ' . $parent['author'] . ': "' . $parent['excerpt'] . '"' : '',
				'author'       => '' !== $item['author_name'] ? $item['author_name'] : 'a visitor',
				'rating'       => $rating ? " (rating {$rating}/5)" : '',
				'comment'      => $comment,
				'instructions' => self::or_none( mb_substr( (string) $instructions, 0, 400 ) ),
			),
			'admin'
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$reply = trim( sanitize_textarea_field( (string) ( $result['data']['reply'] ?? '' ) ) );
		if ( '' === $reply ) {
			return new \WP_Error( 'dev3cm_empty_draft', __( 'DEV3 AI returned an empty reply. Try again.', 'dev3-ai-comment-moderator' ), array( 'status' => 502 ) );
		}
		return array(
			'draft'  => $reply,
			'model'  => (string) $result['model'],
			'tokens' => (int) ( $result['usage']['total'] ?? 0 ),
			'cached' => ! empty( $result['cached'] ),
		);
	}

	/**
	 * "none" for empty prompt variables.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function or_none( $text ) {
		$text = trim( (string) $text );
		return '' === $text ? '(none)' : $text;
	}

	/**
	 * Fills a reply template's placeholders (non-AI replies).
	 *
	 * @param string $template Template.
	 * @param array  $item     Item.
	 * @return string
	 */
	public static function fill_template( $template, array $item ) {
		$post  = get_post( $item['post_id'] );
		$first = trim( strtok( (string) $item['author_name'], ' ' ) );
		return strtr(
			(string) $template,
			array(
				'{author}' => '' !== $first ? $first : __( 'there', 'dev3-ai-comment-moderator' ),
				'{post}'   => $post ? get_the_title( $post ) : '',
				'{site}'   => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			)
		);
	}

	/**
	 * Discussion summary draft.
	 *
	 * @param int $post_id Post/topic/product.
	 * @return array|\WP_Error { draft: {summary, sentiment, themes, questions, actions}, item_count, model, tokens }
	 */
	public static function summarize( $post_id ) {
		$post  = get_post( (int) $post_id );
		$texts = Sources::thread_texts( (int) $post_id, 150 );
		if ( ! $post ) {
			return new \WP_Error( 'dev3cm_gone', __( 'This post no longer exists.', 'dev3-ai-comment-moderator' ), array( 'status' => 404 ) );
		}
		if ( count( $texts ) < 2 ) {
			return new \WP_Error( 'dev3cm_too_few', __( 'There are not enough approved comments to summarize yet (at least 2).', 'dev3-ai-comment-moderator' ), array( 'status' => 400 ) );
		}
		$lines = array();
		$size  = 0;
		foreach ( $texts as $row ) {
			$line  = '- ' . mb_substr( $row['text'], 0, 600 );
			$size += mb_strlen( $line );
			if ( $size > 60000 ) {
				break;
			}
			$lines[] = $line;
		}
		$big    = count( $lines ) > 120;
		$result = Prompts::run(
			'summary',
			array(
				'site'     => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
				'post'     => get_the_title( $post ),
				'excerpt'  => Text::excerpt( has_excerpt( $post ) ? $post->post_excerpt : $post->post_content, 400 ),
				'count'    => count( $lines ),
				'comments' => implode( "\n", $lines ),
				'language' => self::site_language(),
			),
			'admin',
			$big ? array(
				'model'   => 'pro',
				'profile' => 'heavy',
			) : array()
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$data = (array) ( $result['data'] ?? array() );
		return array(
			'draft'      => array(
				'summary'   => mb_substr( sanitize_textarea_field( (string) ( $data['summary'] ?? '' ) ), 0, 1200 ),
				'sentiment' => round( max( -1, min( 1, (float) ( $data['sentiment'] ?? 0 ) ) ), 2 ),
				'themes'    => Threads::clean_themes( (array) ( $data['themes'] ?? array() ) ),
				'questions' => Threads::clean_list( (array) ( $data['questions'] ?? array() ) ),
				'actions'   => Threads::clean_list( (array) ( $data['actions'] ?? array() ) ),
			),
			'item_count' => count( $texts ),
			'model'      => (string) $result['model'],
			'tokens'     => (int) ( $result['usage']['total'] ?? 0 ),
			'cached'     => ! empty( $result['cached'] ),
		);
	}

	/**
	 * Site language name for prompts.
	 *
	 * @return string
	 */
	private static function site_language() {
		$locale = (string) get_locale();
		return 0 === strpos( $locale, 'en' ) || '' === $locale ? 'English' : 'the language with locale ' . $locale;
	}

	/**
	 * Local insight without AI (keywords + sentiment counts) for a discussion.
	 *
	 * @param int $post_id Post.
	 * @return array
	 */
	public static function insights( $post_id ) {
		$texts = Sources::thread_texts( (int) $post_id, 300 );
		$pos   = 0;
		$neg   = 0;
		foreach ( $texts as $row ) {
			$bucket = Text::bucket( Text::sentiment( $row['text'] ) );
			if ( 'positive' === $bucket ) {
				++$pos;
			} elseif ( 'negative' === $bucket ) {
				++$neg;
			}
		}
		return array(
			'keywords' => Text::keywords( wp_list_pluck( $texts, 'text' ), 10 ),
			'count'    => count( $texts ),
			'positive' => $pos,
			'negative' => $neg,
			'neutral'  => count( $texts ) - $pos - $neg,
		);
	}

	/**
	 * Front-end nudge: local check, then (optionally) AI. Falls back to the local verdict on any AI error.
	 *
	 * @param string $text Draft text.
	 * @return array { unkind, reasons[], suggestion, ai }
	 */
	public static function nudge( $text ) {
		$text    = mb_substr( Text::plain( $text ), 0, 2000 );
		$reasons = Text::unkind_signals( $text );
		$local   = array(
			'unkind'     => ! empty( $reasons ),
			'reasons'    => $reasons,
			'message'    => '',
			'suggestion' => '',
			'ai'         => false,
		);
		if ( mb_strlen( $text ) < 8 || ! Settings::get( 'nudge_ai', false ) ) {
			return $local;
		}
		$args = Prompts::build( 'nudge', array( 'text' => $text ), array( 'cache' => DAY_IN_SECONDS ) );
		if ( is_wp_error( $args ) ) {
			return $local;
		}
		return self::nudge_result( $args, $local );
	}

	/**
	 * Runs the nudge prompt in the visitor context (no retries, 8 s); any error returns the local verdict.
	 *
	 * @param array $args  Args.
	 * @param array $local Local verdict.
	 * @return array
	 */
	private static function nudge_result( array $args, array $local ) {
		$result = Ai::run( 'nudge', $args + array( 'timeout' => 8 ), 'frontend' );
		if ( is_wp_error( $result ) || ! isset( $result['data']['unkind'] ) ) {
			return $local;
		}
		$unkind = (bool) $result['data']['unkind'];
		return array(
			'unkind'     => $unkind,
			'reasons'    => $unkind ? array_values( array_unique( array_merge( array( 'ai' ), $local['reasons'] ) ) ) : array(),
			'message'    => $unkind ? mb_substr( sanitize_text_field( (string) ( $result['data']['reason'] ?? '' ) ), 0, 140 ) : '',
			'suggestion' => $unkind && Settings::get( 'nudge_suggest', true ) ? mb_substr( sanitize_textarea_field( (string) ( $result['data']['suggestion'] ?? '' ) ), 0, 600 ) : '',
			'ai'         => true,
		);
	}
}
