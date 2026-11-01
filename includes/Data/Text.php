<?php
/**
 * Text helpers that work without AI: normalising, hashing, links, PII, profanity, lexicon sentiment, keywords.
 *
 * @package Dev3\AiCommentModerator
 */

namespace Dev3\AiCommentModerator\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless helpers.
 */
class Text {

	/**
	 * Plain text from comment HTML.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function plain( $html ) {
		$text = wp_strip_all_tags( (string) $html );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		return trim( preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Short excerpt.
	 *
	 * @param string $text Text.
	 * @param int    $max  Characters.
	 * @return string
	 */
	public static function excerpt( $text, $max = 300 ) {
		$text = self::plain( $text );
		return mb_strlen( $text ) > $max ? rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…' : $text;
	}

	/**
	 * Normalised form for duplicate detection.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function normalize( $text ) {
		$text = mb_strtolower( self::plain( $text ) );
		$text = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );
		return trim( preg_replace( '/\s+/', ' ', $text ) );
	}

	/**
	 * Content hash (normalised).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function hash( $text ) {
		$norm = self::normalize( $text );
		return '' === $norm ? '' : md5( $norm );
	}

	/**
	 * Number of links (http(s)://, www., <a href>).
	 *
	 * @param string $text Raw content.
	 * @return int
	 */
	public static function link_count( $text ) {
		$text = (string) $text;
		$urls = preg_match_all( '#\bhttps?://[^\s<>"\']+|\bwww\.[a-z0-9-]+\.[a-z]{2,}#i', $text );
		$tags = preg_match_all( '#<a\s[^>]*href=#i', $text );
		// An <a href="https://…"> counts once, not twice.
		return max( (int) $urls, (int) $tags );
	}

	/**
	 * Personal data found in the text.
	 *
	 * @param string $text Text.
	 * @return string[] Types: email, phone, card, ip.
	 */
	public static function pii( $text ) {
		$text  = self::plain( $text );
		$found = array();
		if ( preg_match( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text ) ) {
			$found[] = 'email';
		}
		if ( preg_match_all( '/\b(?:\d[ -]?){13,19}\b/', $text, $m ) ) {
			foreach ( $m[0] as $candidate ) {
				if ( self::luhn( preg_replace( '/\D/', '', $candidate ) ) ) {
					$found[] = 'card';
					break;
				}
			}
		}
		if ( preg_match( '/\b(?:25[0-5]|2[0-4]\d|1?\d?\d)(?:\.(?:25[0-5]|2[0-4]\d|1?\d?\d)){3}\b/', $text ) ) {
			$found[] = 'ip';
		}
		if ( preg_match_all( '/(?<![\w])\+?\d[\d ().-]{7,}\d(?![\w])/', $text, $m ) ) {
			foreach ( $m[0] as $candidate ) {
				$digits = strlen( preg_replace( '/\D/', '', $candidate ) );
				if ( $digits >= 9 && $digits <= 15 && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $candidate ) && ! in_array( 'card', $found, true ) ) {
					$found[] = 'phone';
					break;
				}
			}
		}
		return array_values( array_unique( $found ) );
	}

	/**
	 * Luhn checksum.
	 *
	 * @param string $digits Digits.
	 * @return bool
	 */
	public static function luhn( $digits ) {
		$len = strlen( $digits );
		if ( $len < 13 || $len > 19 ) {
			return false;
		}
		$sum = 0;
		for ( $i = 0; $i < $len; $i++ ) {
			$d = (int) $digits[ $len - 1 - $i ];
			if ( 1 === $i % 2 ) {
				$d *= 2;
				if ( $d > 9 ) {
					$d -= 9;
				}
			}
			$sum += $d;
		}
		return 0 === $sum % 10;
	}

	/**
	 * Built-in insult/profanity list (non-AI toxicity fallback). Kept short and generic; extend it with the filter.
	 *
	 * @return string[]
	 */
	public static function profanity_words() {
		$words = array(
			'idiot',
			'idiots',
			'stupid',
			'moron',
			'morons',
			'dumbass',
			'imbecile',
			'loser',
			'losers',
			'retard',
			'shut up',
			'scum',
			'pathetic',
			'garbage person',
			'kill yourself',
			'kys',
			'go die',
			'hate you',
			'piece of shit',
			'shit',
			'bullshit',
			'fuck',
			'fucking',
			'fucker',
			'motherfucker',
			'wtf',
			'bitch',
			'bastard',
			'asshole',
			'dickhead',
			'crap',
			'jerk',
			'clown',
		);
		/**
		 * Profanity / insult words used by the rule screen and the front-end nudge.
		 *
		 * @param string[] $words Lowercase words or phrases.
		 */
		return array_values( array_filter( array_map( 'strval', (array) apply_filters( 'dev3cm_profanity_words', $words ) ) ) );
	}

	/**
	 * Words/phrases from $list found in $text (whole words, case-insensitive).
	 *
	 * @param string   $text Text.
	 * @param string[] $lines_in Words.
	 * @return string[]
	 */
	public static function find_words( $text, array $lines_in ) {
		$hay  = ' ' . self::normalize( $text ) . ' ';
		$hits = array();
		foreach ( $lines_in as $word ) {
			$needle = self::normalize( $word );
			if ( '' !== $needle && false !== strpos( $hay, ' ' . $needle . ' ' ) ) {
				$hits[] = $needle;
			}
		}
		return array_values( array_unique( $hits ) );
	}

	/**
	 * Lines of a textarea list.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function lines( $text ) {
		return array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', (string) $text ) ) ) );
	}

	/**
	 * Small sentiment lexicon (AFINN-style, -3..3).
	 *
	 * @return array
	 */
	public static function lexicon() {
		$lex = array(
			'love'          => 3,
			'loved'         => 3,
			'amazing'       => 3,
			'awesome'       => 3,
			'excellent'     => 3,
			'fantastic'     => 3,
			'brilliant'     => 3,
			'perfect'       => 3,
			'outstanding'   => 3,
			'wonderful'     => 3,
			'great'         => 2,
			'good'          => 2,
			'nice'          => 2,
			'helpful'       => 2,
			'thanks'        => 2,
			'thank'         => 2,
			'useful'        => 2,
			'happy'         => 2,
			'glad'          => 2,
			'beautiful'     => 2,
			'recommend'     => 2,
			'enjoyed'       => 2,
			'enjoy'         => 2,
			'clear'         => 1,
			'easy'          => 1,
			'interesting'   => 1,
			'agree'         => 1,
			'fine'          => 1,
			'works'         => 1,
			'like'          => 1,
			'liked'         => 2,
			'fast'          => 1,
			'informative'   => 2,
			'insightful'    => 2,
			'superb'        => 3,
			'bad'           => -2,
			'poor'          => -2,
			'wrong'         => -2,
			'broken'        => -2,
			'disappointed'  => -2,
			'disappointing' => -2,
			'annoying'      => -2,
			'useless'       => -3,
			'terrible'      => -3,
			'awful'         => -3,
			'horrible'      => -3,
			'worst'         => -3,
			'hate'          => -3,
			'hated'         => -3,
			'scam'          => -3,
			'waste'         => -2,
			'slow'          => -1,
			'confusing'     => -2,
			'misleading'    => -2,
			'boring'        => -2,
			'problem'       => -1,
			'problems'      => -1,
			'issue'         => -1,
			'issues'        => -1,
			'bug'           => -1,
			'fail'          => -2,
			'failed'        => -2,
			'sad'           => -2,
			'angry'         => -2,
			'ridiculous'    => -2,
			'stupid'        => -3,
			'idiot'         => -3,
			'garbage'       => -3,
			'trash'         => -2,
			'disagree'      => -1,
			'refund'        => -1,
			'expensive'     => -1,
			'unfortunately' => -1,
			'meh'           => -1,
		);
		/**
		 * Sentiment lexicon used without AI (word => -3..3).
		 *
		 * @param array $lex Lexicon.
		 */
		return (array) apply_filters( 'dev3cm_sentiment_lexicon', $lex );
	}

	/**
	 * Lexicon sentiment in -1..1 (negations flip the next word).
	 *
	 * @param string $text Text.
	 * @return float
	 */
	public static function sentiment( $text ) {
		$words = explode( ' ', self::normalize( $text ) );
		$lex   = self::lexicon();
		$score = 0;
		$hits  = 0;
		$flip  = false;
		foreach ( $words as $word ) {
			if ( in_array( $word, array( 'not', 'no', 'never', 'dont', 'don', 'isnt', 'isn', 'wasnt', 'wasn', 'didn', 'doesn', 'hardly', 'without' ), true ) ) {
				$flip = true;
				continue;
			}
			if ( isset( $lex[ $word ] ) ) {
				$value  = (int) $lex[ $word ];
				$score += $flip ? -$value : $value;
				++$hits;
			}
			$flip = false;
		}
		if ( 0 === $hits ) {
			return 0.0;
		}
		// Normalise like VADER: score / sqrt(score^2 + alpha).
		return round( max( -1, min( 1, $score / sqrt( $score * $score + 15 ) ) ), 3 );
	}

	/**
	 * Sentiment bucket.
	 *
	 * @param float $score Score.
	 * @return string positive|neutral|negative
	 */
	public static function bucket( $score ) {
		if ( $score >= 0.25 ) {
			return 'positive';
		}
		return $score <= -0.25 ? 'negative' : 'neutral';
	}

	/**
	 * Top keywords across texts (non-AI themes).
	 *
	 * @param string[] $texts Texts.
	 * @param int      $limit Count.
	 * @return array [ [ word, count ] ]
	 */
	public static function keywords( array $texts, $limit = 8 ) {
		$stop   = array_flip( explode( ' ', 'a an the and or but if then so to of in on at for with from by is are was were be been being it its this that these those i me my we our you your he she they them their his her there here what which who whom how why when where not no yes do does did done have has had just very really can could would should will shall may might must also too than more most much many some any all each about into over after before again out up down off only own same such both few other am im ive dont doesnt didnt cant wont isnt thats its youre theyre one get got like even well still thing things lot make made way see know think use used post comment comments article thanks thank please' ) );
		$counts = array();
		foreach ( $texts as $text ) {
			$seen = array();
			foreach ( explode( ' ', self::normalize( $text ) ) as $word ) {
				if ( mb_strlen( $word ) < 4 || isset( $stop[ $word ] ) || is_numeric( $word ) || isset( $seen[ $word ] ) ) {
					continue;
				}
				$seen[ $word ]   = true;
				$counts[ $word ] = ( $counts[ $word ] ?? 0 ) + 1;
			}
		}
		arsort( $counts );
		$out = array();
		foreach ( array_slice( $counts, 0, $limit, true ) as $word => $count ) {
			if ( $count < 2 && count( $texts ) > 3 ) {
				break;
			}
			$out[] = array(
				'word'  => (string) $word,
				'count' => (int) $count,
			);
		}
		return $out;
	}

	/**
	 * Local "is this unkind?" check used by the nudge fallback.
	 *
	 * @param string $text Text.
	 * @return string[] Reasons: insult, shouting, pile_on.
	 */
	public static function unkind_signals( $text ) {
		$plain   = self::plain( $text );
		$reasons = array();
		if ( self::find_words( $plain, self::profanity_words() ) ) {
			$reasons[] = 'insult';
		}
		$letters = preg_replace( '/[^\p{L}]/u', '', $plain );
		if ( mb_strlen( $letters ) >= 12 ) {
			$upper = preg_replace( '/[^\p{Lu}]/u', '', $letters );
			if ( mb_strlen( $upper ) / mb_strlen( $letters ) > 0.7 ) {
				$reasons[] = 'shouting';
			}
		}
		if ( preg_match( '/[!?]{4,}/', $plain ) ) {
			$reasons[] = 'pile_on';
		}
		return $reasons;
	}

	/**
	 * Hash of an IP (never stored in clear).
	 *
	 * @param string $ip IP.
	 * @return string
	 */
	public static function ip_hash( $ip ) {
		return '' === (string) $ip ? '' : sha1( wp_salt( 'nonce' ) . '|' . $ip );
	}
}
