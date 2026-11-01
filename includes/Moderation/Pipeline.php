<?php
/**
 * Screening pipeline: hooks into comment and bbPress submission, runs rules (+ sync AI), stores the moderation
 * record and keeps it in sync with status changes made anywhere in WordPress.
 *
 * @package Dev3\AiCommentModerator
 */

namespace Dev3\AiCommentModerator\Moderation;

use Dev3\AiCommentModerator\Ai\Classifier;
use Dev3\AiCommentModerator\Core3\Core\Rate_Limiter;
use Dev3\AiCommentModerator\Data\Items;
use Dev3\AiCommentModerator\Data\Text;
use Dev3\AiCommentModerator\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Pipeline.
 */
class Pipeline {

	/**
	 * Screening result waiting for its object id (set before insert, read right after).
	 *
	 * @var array|null
	 */
	public static $pending = null;

	/**
	 * Registers hooks.
	 */
	public function register() {
		add_filter( 'pre_comment_approved', array( self::class, 'screen_comment' ), 99, 2 );
		add_action( 'comment_post', array( self::class, 'after_comment' ), 20, 3 );
		add_action( 'transition_comment_status', array( self::class, 'comment_transition' ), 10, 3 );
		add_action( 'deleted_comment', array( self::class, 'deleted_comment' ) );
		add_filter( 'bbp_new_reply_pre_insert', array( self::class, 'screen_bbp_reply' ), 99 );
		add_filter( 'bbp_new_topic_pre_insert', array( self::class, 'screen_bbp_topic' ), 99 );
		add_action( 'bbp_new_reply', array( self::class, 'after_bbp_reply' ), 20, 1 );
		add_action( 'bbp_new_topic', array( self::class, 'after_bbp_topic' ), 20, 1 );
		add_action( 'transition_post_status', array( self::class, 'post_transition' ), 10, 3 );
		add_action( 'deleted_post', array( self::class, 'deleted_post' ) );
	}

	/**
	 * Is the current writer trusted (skips screening)?
	 *
	 * @param string $source Source.
	 * @return bool
	 */
	public static function trusted( $source ) {
		$trusted = Settings::get( 'skip_trusted', true ) && is_user_logged_in()
			&& ( current_user_can( 'moderate_comments' ) || ( Sources::is_bbp( $source ) && current_user_can( 'moderate' ) // phpcs:ignore WordPress.WP.Capabilities.Unknown -- bbPress capability.
			) );
		/**
		 * Whether the current user skips screening.
		 *
		 * @param bool   $trusted Trusted.
		 * @param string $source  Source.
		 */
		return (bool) apply_filters( 'dev3cm_is_trusted', $trusted, $source );
	}

	/**
	 * Screens a submission: rules, then AI in sync mode.
	 *
	 * @param string $source Source.
	 * @param array  $input  { text, name, email, url, ip, user_id, post_id, parent_id, post_title, akismet_spam }.
	 * @param string $pre    Status WordPress/bbPress would give it (live|held|spam).
	 * @return array Screening result { target, flags, labels, decision, codes, ai_state, model, input }.
	 */
	public static function screen( $source, array $input, $pre ) {
		$input['ip_hash'] = Text::ip_hash( (string) ( $input['ip'] ?? '' ) );
		$flags            = Rules::screen( $input );
		$labels           = array();
		$ai_state         = 'none';
		$model            = '';
		$mode             = (string) Settings::get( 'ai_mode', 'sync' );
		if ( 'sync' === $mode && Classifier::available( 'submission' ) ) {
			$post   = get_post( (int) ( $input['post_id'] ?? 0 ) );
			$result = Classifier::classify(
				array(
					0 => array(
						'source'  => $source,
						'text'    => trim( (string) ( $input['post_title'] ?? '' ) . "\n" . Text::plain( (string) $input['text'] ) ),
						'author'  => (string) ( $input['name'] ?? '' ),
						'post'    => $post ? get_the_title( $post ) : '',
						'excerpt' => $post ? Text::excerpt( has_excerpt( $post ) ? $post->post_excerpt : $post->post_content, 300 ) : '',
						'parent'  => ! empty( $input['parent_id'] ) ? (string) ( Sources::parent_excerpt( $source, (int) $input['parent_id'] )['excerpt'] ?? '' ) : '',
					),
				),
				'submission',
				array( 'timeout' => (int) Settings::get( 'ai_timeout', 8 ) )
			);
			if ( ! is_wp_error( $result ) && isset( $result['labels'][0] ) ) {
				$labels   = $result['labels'][0];
				$ai_state = 'done';
				$model    = $result['model'];
			} else {
				// The visitor is not kept waiting: rules decide now, the queue retries the AI later.
				$ai_state = Classifier::available( 'cron' ) ? 'queued' : 'failed';
			}
		} elseif ( 'async' === $mode && Classifier::available( 'cron' ) ) {
			$ai_state = 'queued';
		}
		$decision = Policy::decide( $flags, $labels );
		return array(
			'source'   => $source,
			'target'   => Policy::target( $pre, $decision['decision'] ),
			'pre'      => $pre,
			'flags'    => $flags,
			'labels'   => $labels,
			'decision' => $decision['decision'],
			'codes'    => $decision['codes'],
			'ai_state' => $ai_state,
			'model'    => $model,
			'input'    => $input,
			'key'      => self::key( $input ),
		);
	}

	/**
	 * Match key between "before insert" and "after insert".
	 *
	 * @param array $input Input.
	 * @return string
	 */
	private static function key( array $input ) {
		return md5( (int) ( $input['post_id'] ?? 0 ) . '|' . strtolower( (string) ( $input['email'] ?? '' ) ) . '|' . Text::normalize( (string) ( $input['text'] ?? '' ) ) );
	}

	/**
	 * Filter pre_comment_approved.
	 *
	 * @param int|string|\WP_Error $approved    1, 0, 'spam', 'trash' or an error.
	 * @param array                $commentdata Comment data.
	 * @return int|string|\WP_Error
	 */
	public static function screen_comment( $approved, $commentdata ) {
		if ( is_wp_error( $approved ) ) {
			return $approved;
		}
		$source = Sources::comment_source( (string) ( $commentdata['comment_type'] ?? '' ), (int) ( $commentdata['comment_post_ID'] ?? 0 ) );
		if ( '' === $source || ! Sources::enabled( $source ) || self::trusted( $source ) ) {
			self::$pending = null;
			return $approved;
		}
		$key = self::key(
			array(
				'post_id' => (int) ( $commentdata['comment_post_ID'] ?? 0 ),
				'email'   => (string) ( $commentdata['comment_author_email'] ?? '' ),
				'text'    => (string) ( $commentdata['comment_content'] ?? '' ),
			)
		);
		// WordPress runs this filter twice per comment (wp_allow_comment + wp_check_comment_data): screen once.
		if ( self::$pending && $key === self::$pending['key'] ) {
			$pre = Sources::map_status( $source, (string) $approved );
			$to  = Policy::target( $pre, self::$pending['decision'] );
			return $to === $pre ? $approved : ( 'spam' === $to ? 'spam' : 0 );
		}
		self::$pending = null;
		$pre           = Sources::map_status( $source, (string) $approved );
		$result        = self::screen(
			$source,
			array(
				'text'         => (string) ( $commentdata['comment_content'] ?? '' ),
				'name'         => (string) ( $commentdata['comment_author'] ?? '' ),
				'email'        => (string) ( $commentdata['comment_author_email'] ?? '' ),
				'url'          => (string) ( $commentdata['comment_author_url'] ?? '' ),
				'ip'           => (string) ( $commentdata['comment_author_IP'] ?? Rate_Limiter::client_ip() ),
				'user_id'      => (int) ( $commentdata['user_id'] ?? 0 ),
				'post_id'      => (int) ( $commentdata['comment_post_ID'] ?? 0 ),
				'parent_id'    => (int) ( $commentdata['comment_parent'] ?? 0 ),
				'akismet_spam' => 'spam' === $approved && ( 'true' === (string) ( $commentdata['akismet_result'] ?? '' ) || class_exists( '\Akismet' ) ),
			),
			$pre
		);
		self::$pending = $result;
		if ( $result['target'] === $pre ) {
			return $approved;
		}
		return 'spam' === $result['target'] ? 'spam' : 0;
	}

	/**
	 * Action comment_post: stores the record.
	 *
	 * @param int        $comment_id Comment id.
	 * @param int|string $approved   Final status.
	 */
	public static function after_comment( $comment_id, $approved = 1 ) {
		$comment       = get_comment( $comment_id );
		$pending       = self::$pending;
		self::$pending = null;
		if ( ! $comment || ! $pending ) {
			return;
		}
		$key = self::key(
			array(
				'post_id' => (int) $comment->comment_post_ID,
				'email'   => (string) $comment->comment_author_email,
				'text'    => (string) $comment->comment_content,
			)
		);
		if ( $key !== $pending['key'] ) {
			return;
		}
		self::store( $pending, (int) $comment_id, Sources::map_status( $pending['source'], (string) $approved ) );
	}

	/**
	 * Saves a screening result as a record.
	 *
	 * @param array  $result    Screening result.
	 * @param int    $object_id Object id.
	 * @param string $status    Final status.
	 * @param int    $post_id   Post id override (topics).
	 * @return int Item id.
	 */
	private static function store( array $result, $object_id, $status, $post_id = 0 ) {
		$input  = $result['input'];
		$labels = $result['labels'];
		$data   = Sources::data( $result['source'], $object_id );
		$text   = (string) ( $input['post_title'] ?? '' ) . ' ' . (string) $input['text'];
		$row    = array(
			'source'        => $result['source'],
			'object_id'     => (int) $object_id,
			'post_id'       => $post_id ? (int) $post_id : (int) ( $input['post_id'] ?? 0 ),
			'parent_id'     => (int) ( $input['parent_id'] ?? 0 ),
			'user_id'       => (int) ( $input['user_id'] ?? 0 ),
			'author_name'   => $data && '' !== $data['author_name'] ? $data['author_name'] : (string) ( $input['name'] ?? '' ),
			'author_email'  => strtolower( $data && '' !== $data['author_email'] ? $data['author_email'] : (string) ( $input['email'] ?? '' ) ),
			'ip_hash'       => (string) $input['ip_hash'],
			'excerpt'       => Text::excerpt( $text, 300 ),
			'content_hash'  => Text::hash( (string) $input['text'] ),
			'rule_flags'    => $result['flags'],
			'labels'        => $labels ? array_diff_key( $labels, array( 'reason' => 1 ) ) : array(),
			'reason'        => (string) ( $labels['reason'] ?? '' ),
			'codes'         => $result['codes'],
			'decision'      => $result['decision'],
			'status'        => $status,
			'sentiment'     => $labels ? (float) $labels['sentiment'] : Text::sentiment( $text ),
			'sentiment_src' => $labels ? 'ai' : 'lexicon',
			'ai_state'      => $result['ai_state'],
			'model'         => (string) $result['model'],
		);
		$id     = Items::upsert( $row );
		$by     = $labels ? 'ai' : 'rules';
		if ( Sources::severity( $status ) > Sources::severity( $result['pre'] ) ) {
			Items::log( $id, 'spam' === $status ? 'spammed' : 'held', $by, implode( ', ', array_map( array( Policy::class, 'name' ), $result['codes'] ) ) );
		} else {
			Items::log( $id, 'screened', $by, $result['codes'] ? implode( ', ', array_map( array( Policy::class, 'name' ), $result['codes'] ) ) : __( 'No issues found', 'dev3-ai-comment-moderator' ) );
		}
		if ( 'queued' === $result['ai_state'] ) {
			Classifier::enqueue( $id );
		}
		/**
		 * A new item was screened and stored.
		 *
		 * @param int   $id     Item id.
		 * @param array $result Screening result.
		 */
		do_action( 'dev3cm_item_screened', $id, $result );
		return $id;
	}

	/**
	 * Author fields of the current bbPress submission.
	 *
	 * @param int $author_id Author.
	 * @return array { name, email, url }
	 */
	private static function bbp_author( $author_id ) {
		if ( $author_id ) {
			$user = get_userdata( (int) $author_id );
			return array(
				'name'  => $user ? $user->display_name : '',
				'email' => $user ? $user->user_email : '',
				'url'   => $user ? $user->user_url : '',
			);
		}
		$anon = function_exists( 'bbp_filter_anonymous_post_data' ) ? (array) bbp_filter_anonymous_post_data() : array();
		return array(
			'name'  => (string) ( $anon['bbp_anonymous_name'] ?? '' ),
			'email' => (string) ( $anon['bbp_anonymous_email'] ?? '' ),
			'url'   => (string) ( $anon['bbp_anonymous_website'] ?? '' ),
		);
	}

	/**
	 * Shared bbPress screen.
	 *
	 * @param string $source bbp_reply|bbp_topic.
	 * @param array  $data   Insert data.
	 * @return array
	 */
	private static function screen_bbp( $source, array $data ) {
		self::$pending = null;
		if ( ! Sources::enabled( $source ) || self::trusted( $source ) ) {
			return $data;
		}
		$author = self::bbp_author( (int) ( $data['post_author'] ?? 0 ) );
		$pre    = Sources::map_status( $source, (string) ( $data['post_status'] ?? 'publish' ) );
		$is_rep = 'bbp_reply' === $source;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- bbPress verified its own nonce before this filter.
		$reply_to      = $is_rep && isset( $_POST['bbp_reply_to'] ) ? absint( wp_unslash( $_POST['bbp_reply_to'] ) ) : 0;
		$result        = self::screen(
			$source,
			array(
				'text'         => trim( ( $is_rep ? '' : (string) ( $data['post_title'] ?? '' ) . "\n" ) . (string) ( $data['post_content'] ?? '' ) ),
				'name'         => $author['name'],
				'email'        => $author['email'],
				'url'          => $author['url'],
				'ip'           => function_exists( 'bbp_current_author_ip' ) ? (string) bbp_current_author_ip() : Rate_Limiter::client_ip(),
				'user_id'      => (int) ( $data['post_author'] ?? 0 ),
				'post_id'      => $is_rep ? (int) ( $data['post_parent'] ?? 0 ) : 0,
				'parent_id'    => $reply_to,
				'akismet_spam' => 'spam' === $pre && class_exists( '\Akismet' ),
			),
			$pre
		);
		self::$pending = $result;
		if ( $result['target'] !== $pre ) {
			$data['post_status'] = 'spam' === $result['target'] ? bbp_get_spam_status_id() : bbp_get_pending_status_id();
		}
		return $data;
	}

	/**
	 * Filter bbp_new_reply_pre_insert.
	 *
	 * @param array $data Reply data.
	 * @return array
	 */
	public static function screen_bbp_reply( $data ) {
		return self::screen_bbp( 'bbp_reply', (array) $data );
	}

	/**
	 * Filter bbp_new_topic_pre_insert.
	 *
	 * @param array $data Topic data.
	 * @return array
	 */
	public static function screen_bbp_topic( $data ) {
		return self::screen_bbp( 'bbp_topic', (array) $data );
	}

	/**
	 * Action bbp_new_reply.
	 *
	 * @param int $reply_id Reply id.
	 */
	public static function after_bbp_reply( $reply_id ) {
		self::after_bbp( 'bbp_reply', (int) $reply_id );
	}

	/**
	 * Action bbp_new_topic.
	 *
	 * @param int $topic_id Topic id.
	 */
	public static function after_bbp_topic( $topic_id ) {
		self::after_bbp( 'bbp_topic', (int) $topic_id );
	}

	/**
	 * Stores a bbPress record.
	 *
	 * @param string $source Source.
	 * @param int    $id     Post id.
	 */
	private static function after_bbp( $source, $id ) {
		$pending       = self::$pending;
		self::$pending = null;
		$post          = get_post( $id );
		if ( ! $pending || ! $post || $pending['source'] !== $source ) {
			return;
		}
		self::store( $pending, $id, Sources::map_status( $source, $post->post_status ), 'bbp_topic' === $source ? $id : (int) $post->post_parent );
	}

	/**
	 * Keeps records in sync with comment status changes (and records human reviews).
	 *
	 * @param string      $new_status New.
	 * @param string      $old_status Old.
	 * @param \WP_Comment $comment    Comment.
	 */
	public static function comment_transition( $new_status, $old_status, $comment ) {
		if ( $new_status === $old_status || ! $comment ) {
			return;
		}
		$source = Sources::comment_source( (string) $comment->comment_type, (int) $comment->comment_post_ID );
		if ( '' === $source ) {
			return;
		}
		$map = array(
			'approved'   => 'live',
			'unapproved' => 'held',
			'spam'       => 'spam',
			'trash'      => 'trash',
		);
		self::sync_status( $source, (int) $comment->comment_ID, $map[ $new_status ] ?? 'held' );
	}

	/**
	 * Keeps bbPress records in sync.
	 *
	 * @param string   $new_status New.
	 * @param string   $old_status Old.
	 * @param \WP_Post $post       Post.
	 */
	public static function post_transition( $new_status, $old_status, $post ) {
		if ( $new_status === $old_status || ! $post || 'new' === $old_status || 'auto-draft' === $old_status ) {
			return;
		}
		$source = Sources::bbp_source( (string) $post->post_type );
		if ( '' !== $source ) {
			self::sync_status( $source, (int) $post->ID, Sources::map_status( $source, $new_status ) );
		}
	}

	/**
	 * Updates a record's status; a change by a moderator (not by the plugin) marks it reviewed.
	 *
	 * @param string $source    Source.
	 * @param int    $object_id Object.
	 * @param string $status    New status.
	 */
	private static function sync_status( $source, $object_id, $status ) {
		$item = Items::find( $source, $object_id );
		if ( ! $item || $item['status'] === $status ) {
			return;
		}
		$fields = array( 'status' => $status );
		$human  = ! Sources::$internal && is_user_logged_in() && current_user_can( 'moderate_comments' );
		if ( $human ) {
			$fields['reviewed']    = 1;
			$fields['reviewed_by'] = get_current_user_id();
		}
		Items::update( $item['id'], $fields );
		if ( $human ) {
			Items::log( $item['id'], self::verb( $status ), 'user:' . get_current_user_id() );
		}
	}

	/**
	 * History verb of a status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function verb( $status ) {
		$map = array(
			'live'  => 'approved',
			'held'  => 'held',
			'spam'  => 'spammed',
			'trash' => 'trashed',
		);
		return $map[ $status ] ?? $status;
	}

	/**
	 * Drops the record of a deleted comment.
	 *
	 * @param int $comment_id Comment id.
	 */
	public static function deleted_comment( $comment_id ) {
		foreach ( array( 'comment', 'review' ) as $source ) {
			$item = Items::find( $source, (int) $comment_id );
			if ( $item ) {
				Items::remove( $item['id'] );
			}
		}
	}

	/**
	 * Drops the record of a deleted bbPress post.
	 *
	 * @param int $post_id Post id.
	 */
	public static function deleted_post( $post_id ) {
		foreach ( array( 'bbp_topic', 'bbp_reply' ) as $source ) {
			$item = Items::find( $source, (int) $post_id );
			if ( $item ) {
				Items::remove( $item['id'] );
			}
		}
	}

	/**
	 * Creates/refreshes the record of existing content (scanner). AI labels already stored are kept.
	 *
	 * @param string $source    Source.
	 * @param int    $object_id Object.
	 * @param bool   $move      Move content per policy (false = only flag in the queue).
	 * @return int Item id (0 = skipped).
	 */
	public static function record_existing( $source, $object_id, $move = true ) {
		if ( ! in_array( $source, Sources::ALL, true ) || ! Sources::enabled( $source ) ) {
			return 0;
		}
		$data = Sources::data( $source, $object_id );
		if ( ! $data || 'trash' === $data['status'] ) {
			return 0;
		}
		$existing = Items::find( $source, $object_id );
		$input    = array(
			'text'      => (string) $data['content'],
			'name'      => (string) $data['author_name'],
			'email'     => strtolower( (string) $data['author_email'] ),
			'url'       => (string) $data['author_url'],
			'ip_hash'   => Text::ip_hash( (string) $data['ip'] ),
			'post_id'   => (int) $data['post_id'],
			'skip_rate' => true,
		);
		$flags    = Rules::screen( $input );
		$labels   = $existing ? $existing['labels'] : array();
		$text     = trim( $data['title'] . ' ' . $data['content'] );
		$row      = array(
			'source'       => $source,
			'object_id'    => (int) $object_id,
			'post_id'      => (int) $data['post_id'],
			'parent_id'    => (int) $data['parent_id'],
			'user_id'      => (int) $data['user_id'],
			'author_name'  => (string) $data['author_name'],
			'author_email' => strtolower( (string) $data['author_email'] ),
			'ip_hash'      => $input['ip_hash'],
			'excerpt'      => Text::excerpt( $text, 300 ),
			'content_hash' => Text::hash( (string) $data['content'] ),
			'rule_flags'   => $flags,
			'status'       => $data['status'],
		);
		if ( ! $existing ) {
			$row['created_at']    = '' !== $data['date'] && '0000-00-00 00:00:00' !== $data['date'] ? $data['date'] : Items::now();
			$row['sentiment']     = Text::sentiment( $text );
			$row['sentiment_src'] = 'lexicon';
			$row['ai_state']      = 'none';
		} elseif ( 'ai' !== $existing['sentiment_src'] ) {
			$row['sentiment'] = Text::sentiment( $text );
		}
		$id       = Items::upsert( $row );
		$decision = Policy::decide( $flags, $labels );
		Items::update(
			$id,
			array(
				'decision' => $decision['decision'],
				'codes'    => $decision['codes'],
			)
		);
		if ( ! $existing ) {
			Items::log( $id, 'scanned', 'rules', $decision['codes'] ? implode( ', ', array_map( array( Policy::class, 'name' ), $decision['codes'] ) ) : __( 'No issues found', 'dev3-ai-comment-moderator' ) );
		}
		if ( $move ) {
			Policy::apply( $id, 'rules' );
		}
		return $id;
	}
}
