<?php
/**
 * Rule-based screening (no AI, no network): links, blocklist, rate, duplicates, PII, profanity, Akismet.
 *
 * @package Dev3\AiCommentModerator
 */

namespace Dev3\AiCommentModerator\Moderation;

use Dev3\AiCommentModerator\Data\Items;
use Dev3\AiCommentModerator\Data\Text;
use Dev3\AiCommentModerator\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless rule engine.
 */
class Rules {

	/**
	 * Action configured for a rule.
	 *
	 * @param string $rule Rule.
	 * @return string off|flag|hold|spam
	 */
	public static function action( $rule ) {
		$action = (string) Settings::get( "rule_{$rule}_action", 'off' );
		return in_array( $action, Settings::ACTIONS, true ) ? $action : 'off';
	}

	/**
	 * Screens one submission.
	 *
	 * @param array $input { text, name, email, url, ip_hash, post_id, akismet_spam, skip_rate }.
	 * @return array Flags [ { code, detail, action } ].
	 */
	public static function screen( array $input ) {
		$text  = (string) ( $input['text'] ?? '' );
		$name  = (string) ( $input['name'] ?? '' );
		$email = strtolower( (string) ( $input['email'] ?? '' ) );
		$url   = (string) ( $input['url'] ?? '' );
		$ip    = (string) ( $input['ip_hash'] ?? '' );
		$flags = array();

		$action = self::action( 'links' );
		if ( 'off' !== $action ) {
			$links = Text::link_count( $text );
			$max   = (int) Settings::get( 'rule_links_max', 2 );
			if ( $links > $max ) {
				$flags[] = self::flag(
					'links',
					/* translators: 1: links found, 2: allowed */
					sprintf( __( '%1$d links (max %2$d)', 'dev3-ai-comment-moderator' ), $links, $max ),
					$action
				);
			}
		}

		$action = self::action( 'blocklist' );
		if ( 'off' !== $action ) {
			$words = Text::lines( (string) Settings::get( 'rule_blocklist_words', '' ) );
			$hits  = $words ? Text::find_words( $text . ' ' . $name . ' ' . str_replace( array( '@', '.' ), ' ', $email . ' ' . $url ), $words ) : array();
			if ( $hits ) {
				/* translators: %s: matched words */
				$flags[] = self::flag( 'blocklist', sprintf( __( 'Blocked words: %s', 'dev3-ai-comment-moderator' ), implode( ', ', array_slice( $hits, 0, 5 ) ) ), $action );
			}
		}

		$action = self::action( 'rate' );
		if ( 'off' !== $action && empty( $input['skip_rate'] ) ) {
			$minutes = (int) Settings::get( 'rule_rate_minutes', 10 );
			$max     = (int) Settings::get( 'rule_rate_max', 5 );
			$recent  = Items::recent_from( $ip, $email, $minutes );
			if ( $recent >= $max ) {
				/* translators: 1: count, 2: minutes */
				$flags[] = self::flag( 'rate', sprintf( __( '%1$d posts in %2$d minutes', 'dev3-ai-comment-moderator' ), $recent + 1, $minutes ), $action );
			}
		}

		$action = self::action( 'duplicate' );
		if ( 'off' !== $action ) {
			$hash = Text::hash( $text );
			$dups = mb_strlen( Text::normalize( $text ) ) >= 12 ? Items::duplicates( $hash, (int) ( $input['post_id'] ?? 0 ), $ip, $email, (int) Settings::get( 'rule_duplicate_days', 30 ) ) : 0;
			if ( $dups ) {
				/* translators: %d: earlier copies */
				$flags[] = self::flag( 'duplicate', sprintf( _n( 'Same text posted %d time elsewhere', 'Same text posted %d times elsewhere', $dups, 'dev3-ai-comment-moderator' ), $dups ), $action );
			}
		}

		$action = self::action( 'pii' );
		if ( 'off' !== $action ) {
			$types = Text::pii( $text );
			if ( $types ) {
				/* translators: %s: data types */
				$flags[] = self::flag( 'pii', sprintf( __( 'Personal data: %s', 'dev3-ai-comment-moderator' ), implode( ', ', self::pii_labels( $types ) ) ), $action );
			}
		}

		$action = self::action( 'profanity' );
		if ( 'off' !== $action ) {
			$hits = Text::find_words( $text, Text::profanity_words() );
			if ( $hits ) {
				/* translators: %d: number of words */
				$flags[] = self::flag( 'profanity', sprintf( _n( '%d insult or swear word', '%d insult or swear words', count( $hits ), 'dev3-ai-comment-moderator' ), count( $hits ) ), $action );
			}
		}

		$action = self::action( 'akismet' );
		if ( 'off' !== $action && ! empty( $input['akismet_spam'] ) ) {
			$flags[] = self::flag( 'akismet', __( 'Akismet marked this as spam', 'dev3-ai-comment-moderator' ), $action );
		}

		/**
		 * Rule flags of a submission. Add your own: [ code, detail, action (flag|hold|spam) ].
		 *
		 * @param array $flags Flags.
		 * @param array $input Submission.
		 */
		return array_values( (array) apply_filters( 'dev3cm_rule_flags', $flags, $input ) );
	}

	/**
	 * Flag row.
	 *
	 * @param string $code   Code.
	 * @param string $detail Detail.
	 * @param string $action Action.
	 * @return array
	 */
	private static function flag( $code, $detail, $action ) {
		return array(
			'code'   => $code,
			'detail' => $detail,
			'action' => $action,
		);
	}

	/**
	 * Translated PII type names.
	 *
	 * @param string[] $types Types.
	 * @return string[]
	 */
	public static function pii_labels( array $types ) {
		$names = array(
			'email'   => __( 'email', 'dev3-ai-comment-moderator' ),
			'phone'   => __( 'phone number', 'dev3-ai-comment-moderator' ),
			'card'    => __( 'card number', 'dev3-ai-comment-moderator' ),
			'ip'      => __( 'IP address', 'dev3-ai-comment-moderator' ),
			'address' => __( 'home address', 'dev3-ai-comment-moderator' ),
			'id'      => __( 'ID number', 'dev3-ai-comment-moderator' ),
			'name'    => __( 'full name', 'dev3-ai-comment-moderator' ),
		);
		return array_map(
			static function ( $type ) use ( $names ) {
				return $names[ $type ] ?? $type;
			},
			$types
		);
	}
}
