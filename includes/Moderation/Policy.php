<?php
/**
 * Policy matrix: turns rule flags and AI scores into one decision (none|flag|hold|spam) and applies it.
 *
 * The policy only ever moves content "down" (live → held → spam). It never approves, never trashes and never
 * deletes, and it leaves items a human has reviewed alone.
 *
 * @package Dev3\AiCommentModerator
 */

namespace Dev3\AiCommentModerator\Moderation;

use Dev3\AiCommentModerator\Data\Items;
use Dev3\AiCommentModerator\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Decisions.
 */
class Policy {

	const RANK = array(
		'none' => 0,
		'off'  => 0,
		'flag' => 1,
		'hold' => 2,
		'spam' => 3,
	);

	/**
	 * Human names of reason codes.
	 *
	 * @return array
	 */
	public static function names() {
		return array(
			'links'         => __( 'Too many links', 'dev3-ai-comment-moderator' ),
			'blocklist'     => __( 'Blocklist', 'dev3-ai-comment-moderator' ),
			'rate'          => __( 'Posting too fast', 'dev3-ai-comment-moderator' ),
			'duplicate'     => __( 'Duplicate', 'dev3-ai-comment-moderator' ),
			'pii'           => __( 'Personal data', 'dev3-ai-comment-moderator' ),
			'profanity'     => __( 'Profanity', 'dev3-ai-comment-moderator' ),
			'akismet'       => __( 'Akismet', 'dev3-ai-comment-moderator' ),
			'ai_spam'       => __( 'Spam (AI)', 'dev3-ai-comment-moderator' ),
			'ai_toxicity'   => __( 'Toxic (AI)', 'dev3-ai-comment-moderator' ),
			'ai_harassment' => __( 'Harassment (AI)', 'dev3-ai-comment-moderator' ),
			'ai_off_topic'  => __( 'Off-topic (AI)', 'dev3-ai-comment-moderator' ),
			'ai_pii'        => __( 'Personal data (AI)', 'dev3-ai-comment-moderator' ),
		);
	}

	/**
	 * AI labels over their thresholds.
	 *
	 * @param array $labels Labels (scores 0–1).
	 * @return array [ { code, label, score, threshold, action } ]
	 */
	public static function ai_hits( array $labels ) {
		$hits = array();
		if ( ! $labels ) {
			return $hits;
		}
		foreach ( Settings::LABELS as $label ) {
			$action    = (string) Settings::get( "ai_{$label}_action", 'off' );
			$threshold = (float) Settings::get( "ai_{$label}_threshold", 0.8 );
			$score     = isset( $labels[ $label ] ) ? (float) $labels[ $label ] : 0.0;
			if ( 'off' === $action || $score < $threshold ) {
				continue;
			}
			$hits[] = array(
				'code'      => 'ai_' . $label,
				'label'     => $label,
				'score'     => round( $score, 2 ),
				'threshold' => $threshold,
				'action'    => $action,
			);
		}
		return $hits;
	}

	/**
	 * Strictest action of rule flags + AI hits.
	 *
	 * @param array $flags  Rule flags.
	 * @param array $labels AI labels.
	 * @return array { decision, codes[] }
	 */
	public static function decide( array $flags, array $labels ) {
		$decision = 'none';
		$codes    = array();
		foreach ( array_merge( $flags, self::ai_hits( $labels ) ) as $hit ) {
			$action = (string) ( $hit['action'] ?? 'flag' );
			if ( 'off' === $action ) {
				continue;
			}
			$codes[] = (string) $hit['code'];
			if ( self::RANK[ $action ] > self::RANK[ $decision ] ) {
				$decision = $action;
			}
		}
		/**
		 * Final policy decision.
		 *
		 * @param string $decision none|flag|hold|spam.
		 * @param array  $flags    Rule flags.
		 * @param array  $labels   AI labels.
		 */
		$decision = (string) apply_filters( 'dev3cm_policy_decision', $decision, $flags, $labels );
		if ( ! isset( self::RANK[ $decision ] ) || 'off' === $decision ) {
			$decision = 'none';
		}
		return array(
			'decision' => $decision,
			'codes'    => array_values( array_unique( $codes ) ),
		);
	}

	/**
	 * Status the decision leads to (only ever stricter than the current one).
	 *
	 * @param string $current  live|held|spam|trash.
	 * @param string $decision none|flag|hold|spam.
	 * @return string
	 */
	public static function target( $current, $decision ) {
		$want = array(
			'hold' => 'held',
			'spam' => 'spam',
		);
		if ( ! isset( $want[ $decision ] ) ) {
			return $current;
		}
		return Sources::severity( $want[ $decision ] ) > Sources::severity( $current ) && 'trash' !== $current ? $want[ $decision ] : $current;
	}

	/**
	 * Re-decides a record and moves its object when needed.
	 *
	 * @param int    $item_id Item.
	 * @param string $by      rules|ai.
	 * @param bool   $move    Move the object (false = only record the decision).
	 * @return array|null Updated item.
	 */
	public static function apply( $item_id, $by = 'ai', $move = true ) {
		$item = Items::get( $item_id );
		if ( ! $item ) {
			return null;
		}
		$result  = self::decide( $item['rule_flags'], $item['labels'] );
		$fields  = array(
			'decision' => $result['decision'],
			'codes'    => $result['codes'],
		);
		$current = Sources::status( $item['source'], $item['object_id'] );
		if ( '' === $current ) {
			return $item;
		}
		$fields['status'] = $current;
		if ( $move && ! $item['reviewed'] ) {
			$target = self::target( $current, $result['decision'] );
			if ( $target !== $current && Sources::set_status( $item['source'], $item['object_id'], $target ) ) {
				$fields['status'] = $target;
				Items::update( $item_id, $fields );
				Items::log( $item_id, 'spam' === $target ? 'spammed' : 'held', $by, implode( ', ', array_map( array( self::class, 'name' ), $result['codes'] ) ) );
				return Items::get( $item_id );
			}
		}
		Items::update( $item_id, $fields );
		return Items::get( $item_id );
	}

	/**
	 * Name of a code.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function name( $code ) {
		$names = self::names();
		return $names[ $code ] ?? $code;
	}

	/**
	 * Reasons shown in the queue.
	 *
	 * @param array $item Decoded item.
	 * @return array [ { code, label, detail, action, score, by } ]
	 */
	public static function reasons( array $item ) {
		$out = array();
		foreach ( (array) $item['rule_flags'] as $flag ) {
			$out[] = array(
				'code'   => (string) $flag['code'],
				'label'  => self::name( (string) $flag['code'] ),
				'detail' => (string) ( $flag['detail'] ?? '' ),
				'action' => (string) ( $flag['action'] ?? 'flag' ),
				'score'  => null,
				'by'     => 'rules',
			);
		}
		$ai_reason = (string) ( $item['reason'] ?? '' );
		foreach ( self::ai_hits( (array) $item['labels'] ) as $hit ) {
			$detail = $ai_reason;
			if ( 'pii' === $hit['label'] && ! empty( $item['labels']['pii_types'] ) ) {
				$detail = implode( ', ', Rules::pii_labels( (array) $item['labels']['pii_types'] ) );
			}
			$out[] = array(
				'code'   => $hit['code'],
				'label'  => self::name( $hit['code'] ),
				'detail' => $detail,
				'action' => $hit['action'],
				'score'  => $hit['score'],
				'by'     => 'ai',
			);
		}
		return $out;
	}
}
