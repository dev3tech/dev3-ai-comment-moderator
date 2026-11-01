<?php
/**
 * Rule screen, policy matrix and text helpers (no AI).
 *
 * @package Dev3\AiCommentModerator
 */

use Dev3\AiCommentModerator\Data\Items;
use Dev3\AiCommentModerator\Data\Text;
use Dev3\AiCommentModerator\Moderation\Policy;
use Dev3\AiCommentModerator\Moderation\Rules;

class RulesTest extends Dev3cm_Base_Case {

	private function codes( array $flags ) {
		return wp_list_pluck( $flags, 'code' );
	}

	public function test_links_rule_counts_urls_and_anchors_once() {
		$this->assertSame( 0, Text::link_count( 'No links here.' ) );
		$this->assertSame( 2, Text::link_count( 'See https://a.example/x and www.b-shop.com' ) );
		$this->assertSame( 1, Text::link_count( '<a href="https://a.example">a.example</a>' ) );
		$flags = Rules::screen( array( 'text' => 'http://a.co http://b.co http://c.co' ) );
		$this->assertSame( array( 'links' ), $this->codes( $flags ) );
		$this->assertSame( 'hold', $flags[0]['action'] );
		$this->settings( array( 'rule_links_max' => 5 ) );
		$this->assertSame( array(), Rules::screen( array( 'text' => 'http://a.co http://b.co http://c.co' ) ) );
	}

	public function test_blocklist_matches_whole_words_in_text_name_and_email() {
		$this->settings( array( 'rule_blocklist_words' => "casino\nfree money, crypto" ) );
		$this->assertSame( array( 'blocklist' ), $this->codes( Rules::screen( array( 'text' => 'Win FREE money now' ) ) ) );
		$this->assertSame( array( 'blocklist' ), $this->codes( Rules::screen( array( 'text' => 'Hi', 'email' => 'bob@crypto.io' ) ) ) );
		$this->assertSame( array( 'blocklist' ), $this->codes( Rules::screen( array( 'text' => 'Hi', 'name' => 'Casino King' ) ) ) );
		$this->assertSame( array(), Rules::screen( array( 'text' => 'Cryptography is a fine topic' ) ), 'Whole words only' );
	}

	public function test_rate_rule_uses_recent_items_from_ip_or_email() {
		$this->settings( array( 'rule_rate_max' => 2, 'rule_rate_minutes' => 10 ) );
		$ip = Text::ip_hash( '198.51.100.4' );
		foreach ( array( 1, 2 ) as $n ) {
			Items::upsert(
				array(
					'source'    => 'comment',
					'object_id' => 900 + $n,
					'ip_hash'   => $ip,
					'status'    => 'live',
				)
			);
		}
		$this->assertSame( array( 'rate' ), $this->codes( Rules::screen( array( 'text' => 'Third', 'ip_hash' => $ip ) ) ) );
		$this->assertSame( array(), Rules::screen( array( 'text' => 'Third', 'ip_hash' => $ip, 'skip_rate' => true ) ) );
		$this->assertSame( array(), Rules::screen( array( 'text' => 'Other person', 'ip_hash' => Text::ip_hash( '198.51.100.5' ) ) ) );
	}

	public function test_duplicates_across_posts_by_same_author_or_many_posts() {
		$text = 'Great article, check my profile for cheap watches!';
		$hash = Text::hash( $text );
		$this->assertSame( Text::hash( 'great ARTICLE check my profile for cheap watches' ), $hash, 'Normalised hash ignores case and punctuation' );
		Items::upsert(
			array(
				'source'       => 'comment',
				'object_id'    => 1,
				'post_id'      => 10,
				'author_email' => 'x@spam.io',
				'content_hash' => $hash,
			)
		);
		$this->assertSame( array( 'duplicate' ), $this->codes( Rules::screen( array( 'text' => $text, 'post_id' => 11, 'email' => 'x@spam.io' ) ) ) );
		$this->assertSame( array(), Rules::screen( array( 'text' => $text, 'post_id' => 10, 'email' => 'x@spam.io' ) ), 'Same post is not a cross-post duplicate' );
		$this->assertSame( array(), Rules::screen( array( 'text' => $text, 'post_id' => 11, 'email' => 'other@ok.io' ) ), 'One other post by someone else is fine' );
		Items::upsert(
			array(
				'source'       => 'comment',
				'object_id'    => 2,
				'post_id'      => 12,
				'author_email' => 'y@spam.io',
				'content_hash' => $hash,
			)
		);
		$this->assertSame( array( 'duplicate' ), $this->codes( Rules::screen( array( 'text' => $text, 'post_id' => 13, 'email' => 'z@spam.io' ) ) ), 'Same text on 2+ other posts' );
	}

	public function test_pii_and_profanity_rules() {
		$this->assertSame( array( 'email' ), Text::pii( 'mail me at jo@example.com' ) );
		$this->assertSame( array( 'card' ), Text::pii( 'card 4111 1111 1111 1111 exp 12/29' ) );
		$this->assertSame( array(), Text::pii( 'order 1234 5678 9012 3456 is not a card' ), 'Luhn check' );
		$this->assertSame( array( 'phone' ), Text::pii( 'call +1 (555) 123-4567 tonight' ) );
		$this->assertSame( array(), Text::pii( 'Posted on 2026-10-25 at 10:00' ) );
		$flags = Rules::screen( array( 'text' => 'You idiot, call me on +44 20 7946 0958' ) );
		$this->assertSame( array( 'pii', 'profanity' ), $this->codes( $flags ) );
		$this->assertSame( 'flag', $flags[1]['action'] );
	}

	public function test_akismet_flag_and_off_actions() {
		$this->assertSame( array( 'akismet' ), $this->codes( Rules::screen( array( 'text' => 'hello', 'akismet_spam' => true ) ) ) );
		$this->settings( array( 'rule_akismet_action' => 'off', 'rule_links_action' => 'off' ) );
		$this->assertSame( array(), Rules::screen( array( 'text' => 'hello http://a http://b http://c http://d', 'akismet_spam' => true ) ) );
	}

	public function test_policy_takes_the_strictest_action_and_only_moves_down() {
		$flags = array(
			array( 'code' => 'profanity', 'action' => 'flag' ),
			array( 'code' => 'links', 'action' => 'hold' ),
		);
		$this->assertSame( 'hold', Policy::decide( $flags, array() )['decision'] );
		$labels = array( 'spam' => 0.9, 'toxicity' => 0.1, 'harassment' => 0, 'off_topic' => 0.9, 'pii' => 0 );
		$out    = Policy::decide( $flags, $labels );
		$this->assertSame( 'spam', $out['decision'] );
		$this->assertSame( array( 'profanity', 'links', 'ai_spam', 'ai_off_topic' ), $out['codes'] );
		$this->assertSame( 'held', Policy::target( 'live', 'hold' ) );
		$this->assertSame( 'spam', Policy::target( 'held', 'spam' ) );
		$this->assertSame( 'spam', Policy::target( 'spam', 'hold' ), 'Never moves up' );
		$this->assertSame( 'live', Policy::target( 'live', 'flag' ), 'Flags never move content' );
		$this->assertSame( 'trash', Policy::target( 'trash', 'spam' ) );
		$this->settings( array( 'ai_spam_threshold' => 0.95 ) );
		$this->assertSame( 'hold', Policy::decide( $flags, $labels )['decision'], 'Below the threshold the label does not fire' );
	}

	public function test_lexicon_sentiment_keywords_and_unkind_signals() {
		$this->assertGreaterThan( 0.25, Text::sentiment( 'I love this, really helpful and clear' ) );
		$this->assertLessThan( -0.25, Text::sentiment( 'Terrible, broken and useless' ) );
		$this->assertLessThan( 0, Text::sentiment( 'This is not good' ), 'Negation flips' );
		$this->assertSame( 0.0, Text::sentiment( 'The meeting is on Tuesday' ) );
		$this->assertSame( 'neutral', Text::bucket( 0.1 ) );
		$kw = Text::keywords( array( 'Pricing is unclear', 'pricing page broken', 'Love the pricing tiers', 'docs are great' ), 3 );
		$this->assertSame( 'pricing', $kw[0]['word'] );
		$this->assertSame( 3, $kw[0]['count'] );
		$this->assertSame( array( 'insult', 'shouting', 'pile_on' ), Text::unkind_signals( 'SHUT UP YOU MORON!!!!' ) );
		$this->assertSame( array(), Text::unkind_signals( 'I disagree with the second point.' ) );
	}
}
