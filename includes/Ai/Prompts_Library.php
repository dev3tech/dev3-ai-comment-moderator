<?php
/**
 * Versioned prompt templates + output schemas (core prompt library; override with dev3cm_prompt_<id>).
 *
 * @package Dev3\AiCommentModerator
 */

namespace Dev3\AiCommentModerator\Ai;

use Dev3\AiCommentModerator\Core3\Api\Prompts;

defined( 'ABSPATH' ) || exit;

/**
 * Every string property has a maxLength (prevents structured-output loops).
 */
class Prompts_Library {

	/**
	 * String schema.
	 *
	 * @param int $max Max length.
	 * @return array
	 */
	private static function str( $max ) {
		return array(
			'type'      => 'STRING',
			'maxLength' => $max,
		);
	}

	/**
	 * Number schema.
	 *
	 * @return array
	 */
	private static function num() {
		return array( 'type' => 'NUMBER' );
	}

	/**
	 * Classification schema (batch).
	 *
	 * @return array
	 */
	public static function classify_schema() {
		return array(
			'type'       => 'OBJECT',
			'properties' => array(
				'items' => array(
					'type'     => 'ARRAY',
					'maxItems' => 10,
					'items'    => array(
						'type'       => 'OBJECT',
						'properties' => array(
							'id'         => self::str( 20 ),
							'spam'       => self::num(),
							'toxicity'   => self::num(),
							'harassment' => self::num(),
							'off_topic'  => self::num(),
							'pii'        => self::num(),
							'pii_types'  => array(
								'type'     => 'ARRAY',
								'maxItems' => 5,
								'items'    => array(
									'type' => 'STRING',
									'enum' => array( 'email', 'phone', 'address', 'card', 'id', 'name', 'ip' ),
								),
							),
							'sentiment'  => self::num(),
							'language'   => self::str( 12 ),
							'reason'     => self::str( 200 ),
						),
						'required'   => array( 'id', 'spam', 'toxicity', 'harassment', 'off_topic', 'pii', 'sentiment', 'reason' ),
					),
				),
			),
			'required'   => array( 'items' ),
		);
	}

	/**
	 * Reply schema.
	 *
	 * @return array
	 */
	public static function reply_schema() {
		return array(
			'type'       => 'OBJECT',
			'properties' => array(
				'reply' => self::str( 1200 ),
				'tone'  => self::str( 40 ),
			),
			'required'   => array( 'reply' ),
		);
	}

	/**
	 * Summary schema.
	 *
	 * @return array
	 */
	public static function summary_schema() {
		return array(
			'type'       => 'OBJECT',
			'properties' => array(
				'summary'   => self::str( 900 ),
				'sentiment' => self::num(),
				'themes'    => array(
					'type'     => 'ARRAY',
					'maxItems' => 6,
					'items'    => array(
						'type'       => 'OBJECT',
						'properties' => array(
							'label'     => self::str( 60 ),
							'sentiment' => self::num(),
							'share'     => self::num(),
						),
						'required'   => array( 'label', 'sentiment', 'share' ),
					),
				),
				'questions' => array(
					'type'     => 'ARRAY',
					'maxItems' => 5,
					'items'    => self::str( 200 ),
				),
				'actions'   => array(
					'type'     => 'ARRAY',
					'maxItems' => 5,
					'items'    => self::str( 200 ),
				),
			),
			'required'   => array( 'summary', 'sentiment', 'themes', 'questions', 'actions' ),
		);
	}

	/**
	 * Nudge schema.
	 *
	 * @return array
	 */
	public static function nudge_schema() {
		return array(
			'type'       => 'OBJECT',
			'properties' => array(
				'unkind'     => array( 'type' => 'BOOLEAN' ),
				'reason'     => self::str( 140 ),
				'suggestion' => self::str( 600 ),
			),
			'required'   => array( 'unkind', 'reason', 'suggestion' ),
		);
	}

	/**
	 * Registers templates (init).
	 */
	public static function register() {
		Prompts::register(
			'classify',
			array(
				'version'     => '1.0',
				'task'        => 'classify',
				'system'      => "You are a careful content moderator for the website \"{{site}}\". Score each user post from 0 to 1 for:\n"
					. "- spam: adverts, SEO links, scams, crypto/casino/pharma pitches, gibberish, or text unrelated to anything that only promotes something.\n"
					. "- toxicity: insults, profanity aimed at people, demeaning or hateful language. Strong criticism of an idea, product or article is NOT toxic.\n"
					. "- harassment: threats, targeted attacks on a person or group, doxxing, sexual harassment, encouraging self-harm.\n"
					. "- off_topic: has nothing to do with the post it was written under (the post title/excerpt is given). Questions and tangents that relate to the subject are on-topic.\n"
					. "- pii: the writer shares personal data such as an email, phone number, home address, card or ID number. Placeholders like [email], [phone], [card], [ip] mean such data was present. List the kinds in pii_types.\n"
					. "- sentiment: -1 (very negative) to 1 (very positive) about the subject.\n"
					. 'Give one short neutral reason (max 25 words) that explains the highest score, without repeating personal data. Return one result per input id. Judge content in any language; language = ISO code of the post.',
				'template'    => "Posts (JSON):\n{{items}}",
				'schema'      => self::classify_schema(),
				'temperature' => 0.1,
				'max_tokens'  => 2400,
				'profile'     => 'fast',
			)
		);
		Prompts::register(
			'reply',
			array(
				'version'     => '1.0',
				'task'        => 'reply_draft',
				'system'      => "You write replies on behalf of \"{{site}}\" to comments, product reviews and forum posts.\n"
					. "Brand voice: {{tone}}.\n"
					. "Guidelines from the site owner: {{guidelines}}\n"
					. "Never use these words or phrases: {{avoid}}\n"
					. "Rules:\n"
					. "- Reply in the language of the comment, at most 120 words, plain text, no markdown, no hashtags.\n"
					. "- Address the commenter by first name when known. Be specific to what they said; thank them only when it fits.\n"
					. "- Never promise refunds, discounts, dates or fixes that the guidelines do not mention. If something must be checked, say the team will look into it.\n"
					. "- If the comment is hostile, stay calm and kind, do not argue, and invite them to keep the conversation respectful.\n"
					. '- End with this sign-off when one is given: {{signoff}}',
				'template'    => "Where: {{source}} on \"{{post}}\"\nPost excerpt: {{excerpt}}\n{{parent}}\nComment by {{author}}{{rating}}:\n{{comment}}\n\nExtra instructions from the moderator: {{instructions}}",
				'schema'      => self::reply_schema(),
				'temperature' => 0.6,
				'max_tokens'  => 900,
				'profile'     => 'fast',
			)
		);
		Prompts::register(
			'summary',
			array(
				'version'     => '1.0',
				'task'        => 'thread_summary',
				'system'      => "You summarise the discussion under one post of the website \"{{site}}\" for the site team.\n"
					. "- summary: 2-4 neutral sentences on what people say, agree and disagree on. No names, no quotes of personal data.\n"
					. "- sentiment: overall -1..1.\n"
					. "- themes: up to 6 recurring topics, each with its own sentiment (-1..1) and share (0..1 of comments).\n"
					. "- questions: open questions readers asked that the site has not answered yet.\n"
					. "- actions: concrete follow-ups for the team (fix, clarify, reply), only when the comments support them.\n"
					. 'Write in {{language}}. Ratings like [4/5] are product review stars.',
				'template'    => "Post: {{post}}\nExcerpt: {{excerpt}}\n\nComments ({{count}}, newest first):\n{{comments}}",
				'schema'      => self::summary_schema(),
				'temperature' => 0.2,
				'max_tokens'  => 2400,
				'profile'     => 'fast',
			)
		);
		Prompts::register(
			'nudge',
			array(
				'version'     => '1.0',
				'task'        => 'nudge',
				'system'      => "You help people post kindly on a website. Decide if the draft comment below is likely to come across as rude, insulting, hostile or harassing to other people (unkind=true). Disagreement or criticism of ideas, products or articles is fine (unkind=false).\n"
					. "reason: one short friendly sentence to the writer (second person, max 20 words) when unkind, else empty.\n"
					. 'suggestion: when unkind, rewrite the draft so it keeps the writer\'s point and language but drops insults and hostility; otherwise empty. Plain text.',
				'template'    => "Draft comment:\n{{text}}",
				'schema'      => self::nudge_schema(),
				'temperature' => 0.2,
				'max_tokens'  => 700,
				'profile'     => 'fast',
			)
		);
	}
}
