<?php
/**
 * Adapters for the three sources: WordPress comments, WooCommerce reviews (comments on products) and bbPress topics/replies.
 *
 * Status changes always use the native functions (wp_spam_comment, bbp_spam_reply…) so other plugins (Akismet,
 * counters, caches) see them. Nothing here deletes content.
 *
 * @package Dev3\AiCommentModerator
 */

namespace Dev3\AiCommentModerator\Moderation;

use Dev3\AiCommentModerator\Data\Text;
use Dev3\AiCommentModerator\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Source adapters.
 */
class Sources {

	const ALL = array( 'comment', 'review', 'bbp_topic', 'bbp_reply' );

	/**
	 * True while the plugin itself changes a status (so the change is not read as a human review).
	 *
	 * @var bool
	 */
	public static $internal = false;

	/**
	 * Is WooCommerce available?
	 *
	 * @return bool
	 */
	public static function has_woo() {
		return class_exists( 'WooCommerce' ) || post_type_exists( 'product' );
	}

	/**
	 * Is bbPress available?
	 *
	 * @return bool
	 */
	public static function has_bbpress() {
		return function_exists( 'bbpress' ) && function_exists( 'bbp_get_reply_post_type' );
	}

	/**
	 * Is a source switched on (and available)?
	 *
	 * @param string $source Source.
	 * @return bool
	 */
	public static function enabled( $source ) {
		switch ( $source ) {
			case 'comment':
				return (bool) Settings::get( 'src_comments', true );
			case 'review':
				return (bool) Settings::get( 'src_reviews', true );
			case 'bbp_topic':
			case 'bbp_reply':
				return self::has_bbpress() && (bool) Settings::get( 'src_bbpress', true );
		}
		return false;
	}

	/**
	 * Source of a comment (or '' when this comment type is not moderated, e.g. order notes, pingbacks).
	 *
	 * @param string $type    comment_type.
	 * @param int    $post_id Post id.
	 * @return string
	 */
	public static function comment_source( $type, $post_id ) {
		$type = '' === (string) $type ? 'comment' : (string) $type;
		/**
		 * Comment types the moderator screens.
		 *
		 * @param string[] $types Types.
		 */
		$types = (array) apply_filters( 'dev3cm_comment_types', array( 'comment', 'review' ) );
		if ( ! in_array( $type, $types, true ) ) {
			return '';
		}
		return ( 'review' === $type || 'product' === get_post_type( (int) $post_id ) ) ? 'review' : 'comment';
	}

	/**
	 * Source of a bbPress post type.
	 *
	 * @param string $post_type Post type.
	 * @return string
	 */
	public static function bbp_source( $post_type ) {
		if ( ! self::has_bbpress() ) {
			return '';
		}
		if ( bbp_get_reply_post_type() === $post_type ) {
			return 'bbp_reply';
		}
		return bbp_get_topic_post_type() === $post_type ? 'bbp_topic' : '';
	}

	/**
	 * Is the source a bbPress one?
	 *
	 * @param string $source Source.
	 * @return bool
	 */
	public static function is_bbp( $source ) {
		return 'bbp_topic' === $source || 'bbp_reply' === $source;
	}

	/**
	 * Status of a comment value / post status in plugin terms.
	 *
	 * @param string $source Source.
	 * @param string $raw    comment_approved or post_status.
	 * @return string live|held|spam|trash
	 */
	public static function map_status( $source, $raw ) {
		$raw = (string) $raw;
		if ( self::is_bbp( $source ) ) {
			$map = array(
				'publish' => 'live',
				'closed'  => 'live',
				'pending' => 'held',
				'spam'    => 'spam',
				'trash'   => 'trash',
			);
			return $map[ $raw ] ?? 'held';
		}
		$map = array(
			'1'       => 'live',
			'approve' => 'live',
			'0'       => 'held',
			'hold'    => 'held',
			'spam'    => 'spam',
			'trash'   => 'trash',
		);
		return $map[ $raw ] ?? 'held';
	}

	/**
	 * Current status of an object ('' when it no longer exists).
	 *
	 * @param string $source    Source.
	 * @param int    $object_id Id.
	 * @return string
	 */
	public static function status( $source, $object_id ) {
		if ( self::is_bbp( $source ) ) {
			$post = get_post( (int) $object_id );
			return $post ? self::map_status( $source, $post->post_status ) : '';
		}
		$comment = get_comment( (int) $object_id );
		return $comment ? self::map_status( $source, $comment->comment_approved ) : '';
	}

	/**
	 * Severity of a status (moves only go "down" automatically).
	 *
	 * @param string $status Status.
	 * @return int
	 */
	public static function severity( $status ) {
		$order = array(
			'live'  => 0,
			'held'  => 1,
			'spam'  => 2,
			'trash' => 3,
		);
		return $order[ $status ] ?? 0;
	}

	/**
	 * Moves an object to live|held|spam|trash with the native functions.
	 *
	 * @param string $source    Source.
	 * @param int    $object_id Id.
	 * @param string $status    Target.
	 * @return bool
	 */
	public static function set_status( $source, $object_id, $status ) {
		$current = self::status( $source, $object_id );
		if ( '' === $current ) {
			return false;
		}
		if ( $current === $status ) {
			return true;
		}
		self::$internal = true;
		try {
			$ok = self::is_bbp( $source ) ? self::set_bbp_status( $source, (int) $object_id, $current, $status ) : self::set_comment_status( (int) $object_id, $current, $status );
		} finally {
			self::$internal = false;
		}
		return $ok;
	}

	/**
	 * Comment status change.
	 *
	 * @param int    $id      Comment id.
	 * @param string $current Current.
	 * @param string $status  Target.
	 * @return bool
	 */
	private static function set_comment_status( $id, $current, $status ) {
		if ( 'spam' === $current && 'spam' !== $status ) {
			wp_unspam_comment( $id );
		} elseif ( 'trash' === $current && 'trash' !== $status ) {
			wp_untrash_comment( $id );
		}
		switch ( $status ) {
			case 'spam':
				return (bool) wp_spam_comment( $id );
			case 'trash':
				return (bool) wp_trash_comment( $id );
			case 'held':
				return self::map_status( 'comment', get_comment( $id )->comment_approved ) === 'held' || (bool) wp_set_comment_status( $id, 'hold' );
			default:
				return self::map_status( 'comment', get_comment( $id )->comment_approved ) === 'live' || (bool) wp_set_comment_status( $id, 'approve' );
		}
	}

	/**
	 * Status change of a bbPress topic or reply.
	 *
	 * @param string $source  bbp_topic|bbp_reply.
	 * @param int    $id      Post id.
	 * @param string $current Current.
	 * @param string $status  Target.
	 * @return bool
	 */
	private static function set_bbp_status( $source, $id, $current, $status ) {
		$kind = 'bbp_topic' === $source ? 'topic' : 'reply';
		if ( 'spam' === $current && 'spam' !== $status ) {
			call_user_func( "bbp_unspam_{$kind}", $id );
		} elseif ( 'trash' === $current && 'trash' !== $status ) {
			wp_untrash_post( $id );
		}
		$now = self::status( $source, $id );
		if ( $now === $status ) {
			return true;
		}
		switch ( $status ) {
			case 'spam':
				return false !== call_user_func( "bbp_spam_{$kind}", $id );
			case 'trash':
				return (bool) wp_trash_post( $id );
			case 'held':
				return false !== call_user_func( "bbp_unapprove_{$kind}", $id );
			default:
				return false !== call_user_func( "bbp_approve_{$kind}", $id );
		}
	}

	/**
	 * Data needed to build/refresh a moderation record.
	 *
	 * @param string $source    Source.
	 * @param int    $object_id Id.
	 * @return array|null { post_id, parent_id, user_id, author_name, author_email, author_url, ip, content, status, date }
	 */
	public static function data( $source, $object_id ) {
		if ( self::is_bbp( $source ) ) {
			$post = get_post( (int) $object_id );
			if ( ! $post ) {
				return null;
			}
			$is_reply = 'bbp_reply' === $source;
			$user_id  = (int) $post->post_author;
			$user     = $user_id ? get_userdata( $user_id ) : null;
			$parent   = $is_reply ? (int) get_post_meta( $post->ID, '_bbp_reply_to', true ) : 0;
			return array(
				'post_id'      => $is_reply ? (int) $post->post_parent : (int) $post->ID,
				'parent_id'    => $parent,
				'user_id'      => $user_id,
				'author_name'  => $user ? $user->display_name : (string) get_post_meta( $post->ID, '_bbp_anonymous_name', true ),
				'author_email' => $user ? $user->user_email : (string) get_post_meta( $post->ID, '_bbp_anonymous_email', true ),
				'author_url'   => $user ? $user->user_url : (string) get_post_meta( $post->ID, '_bbp_anonymous_website', true ),
				'ip'           => (string) get_post_meta( $post->ID, '_bbp_author_ip', true ),
				'content'      => (string) $post->post_content,
				'title'        => $is_reply ? '' : (string) $post->post_title,
				'status'       => self::map_status( $source, $post->post_status ),
				'date'         => (string) $post->post_date_gmt,
			);
		}
		$comment = get_comment( (int) $object_id );
		if ( ! $comment ) {
			return null;
		}
		return array(
			'post_id'      => (int) $comment->comment_post_ID,
			'parent_id'    => (int) $comment->comment_parent,
			'user_id'      => (int) $comment->user_id,
			'author_name'  => (string) $comment->comment_author,
			'author_email' => (string) $comment->comment_author_email,
			'author_url'   => (string) $comment->comment_author_url,
			'ip'           => (string) $comment->comment_author_IP,
			'content'      => (string) $comment->comment_content,
			'title'        => '',
			'status'       => self::map_status( $source, $comment->comment_approved ),
			'date'         => (string) $comment->comment_date_gmt,
		);
	}

	/**
	 * Full plain text.
	 *
	 * @param string $source    Source.
	 * @param int    $object_id Id.
	 * @return string
	 */
	public static function content( $source, $object_id ) {
		$data = self::data( $source, $object_id );
		if ( ! $data ) {
			return '';
		}
		$text = trim( ( '' !== $data['title'] ? $data['title'] . "\n\n" : '' ) . Text::plain( $data['content'] ) );
		return mb_substr( $text, 0, 6000 );
	}

	/**
	 * Parent item (the comment/reply this one answers).
	 *
	 * @param string $source    Source.
	 * @param int    $parent_id Parent id.
	 * @return array|null { author, excerpt }
	 */
	public static function parent_excerpt( $source, $parent_id ) {
		$data = self::data( 'bbp_reply' === $source ? 'bbp_reply' : 'comment', $parent_id );
		if ( ! $data ) {
			return null;
		}
		return array(
			'author'  => $data['author_name'],
			'excerpt' => Text::excerpt( $data['content'], 400 ),
		);
	}

	/**
	 * Admin edit URL.
	 *
	 * @param string $source    Source.
	 * @param int    $object_id Id.
	 * @return string
	 */
	public static function edit_url( $source, $object_id ) {
		if ( self::is_bbp( $source ) ) {
			return (string) get_edit_post_link( (int) $object_id, 'raw' );
		}
		return admin_url( 'comment.php?action=editcomment&c=' . (int) $object_id );
	}

	/**
	 * Replies already posted to an item (newest 5).
	 *
	 * @param string $source    Source.
	 * @param int    $object_id Id.
	 * @param int    $post_id   Post/topic.
	 * @return array
	 */
	public static function replies( $source, $object_id, $post_id ) {
		$out = array();
		if ( self::is_bbp( $source ) ) {
			if ( ! self::has_bbpress() ) {
				return $out;
			}
			$query = array(
				'post_type'      => bbp_get_reply_post_type(),
				'post_parent'    => (int) $post_id,
				'posts_per_page' => 5,
				'post_status'    => 'any',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			);
			if ( 'bbp_reply' === $source ) {
				$query['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_bbp_reply_to',
						'value' => (int) $object_id,
					),
				);
			}
			foreach ( get_posts( $query ) as $reply ) {
				$out[] = array(
					'author'  => get_the_author_meta( 'display_name', (int) $reply->post_author ),
					'excerpt' => Text::excerpt( $reply->post_content, 240 ),
					'date'    => mysql_to_rfc3339( $reply->post_date_gmt ),
				);
			}
			return $out;
		}
		$children = get_comments(
			array(
				'parent' => (int) $object_id,
				'number' => 5,
				'status' => 'all',
			)
		);
		foreach ( $children as $child ) {
			$out[] = array(
				'author'  => $child->comment_author,
				'excerpt' => Text::excerpt( $child->comment_content, 240 ),
				'date'    => mysql_to_rfc3339( $child->comment_date_gmt ),
			);
		}
		return $out;
	}

	/**
	 * Posts a reply as the current user. The plugin's own replies are never screened (trusted user).
	 *
	 * @param string $source    Source.
	 * @param int    $object_id Item being answered.
	 * @param int    $post_id   Post/topic/product.
	 * @param string $content   Reply text.
	 * @return int|\WP_Error New comment/reply id.
	 */
	public static function reply( $source, $object_id, $post_id, $content ) {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->ID ) {
			return new \WP_Error( 'dev3cm_no_user', __( 'Log in to reply.', 'dev3-ai-comment-moderator' ), array( 'status' => 403 ) );
		}
		$content = trim( wp_kses_post( $content ) );
		if ( '' === $content ) {
			return new \WP_Error( 'dev3cm_empty_reply', __( 'Write a reply first.', 'dev3-ai-comment-moderator' ), array( 'status' => 400 ) );
		}
		if ( self::is_bbp( $source ) ) {
			if ( ! self::has_bbpress() ) {
				return new \WP_Error( 'dev3cm_no_bbpress', __( 'bbPress is not active.', 'dev3-ai-comment-moderator' ), array( 'status' => 400 ) );
			}
			$topic_id = (int) $post_id;
			$forum_id = (int) bbp_get_topic_forum_id( $topic_id );
			$reply_id = bbp_insert_reply(
				array(
					'post_parent'  => $topic_id,
					'post_content' => $content,
					'post_author'  => $user->ID,
					'post_title'   => sprintf( /* translators: %s: topic title */ __( 'Reply To: %s', 'dev3-ai-comment-moderator' ), get_the_title( $topic_id ) ),
				),
				array(
					'forum_id' => $forum_id,
					'topic_id' => $topic_id,
					'reply_to' => 'bbp_reply' === $source ? (int) $object_id : 0,
				)
			);
			if ( ! $reply_id || is_wp_error( $reply_id ) ) {
				return is_wp_error( $reply_id ) ? $reply_id : new \WP_Error( 'dev3cm_reply_failed', __( 'The reply could not be saved.', 'dev3-ai-comment-moderator' ), array( 'status' => 500 ) );
			}
			if ( function_exists( 'bbp_update_reply' ) ) {
				bbp_update_reply( $reply_id, $topic_id, $forum_id, array(), $user->ID, false, 'bbp_reply' === $source ? (int) $object_id : 0 );
			}
			return (int) $reply_id;
		}
		$parent = get_comment( (int) $object_id );
		$id     = wp_insert_comment(
			array(
				'comment_post_ID'      => (int) $post_id,
				'comment_parent'       => (int) $object_id,
				'comment_content'      => $content,
				'comment_type'         => 'comment',
				'comment_approved'     => 1,
				'user_id'              => $user->ID,
				'comment_author'       => $user->display_name,
				'comment_author_email' => $user->user_email,
				'comment_author_url'   => $user->user_url,
			)
		);
		if ( ! $id ) {
			return new \WP_Error( 'dev3cm_reply_failed', __( 'The reply could not be saved.', 'dev3-ai-comment-moderator' ), array( 'status' => 500 ) );
		}
		return (int) $id;
	}

	/**
	 * Live texts of a discussion (for summaries and keywords), newest first.
	 *
	 * @param int $post_id Post/topic/product.
	 * @param int $limit   Max.
	 * @return array [ { id, author, text, date } ]
	 */
	public static function thread_texts( $post_id, $limit = 150 ) {
		$out  = array();
		$post = get_post( (int) $post_id );
		if ( ! $post ) {
			return $out;
		}
		if ( self::has_bbpress() && bbp_get_topic_post_type() === $post->post_type ) {
			$replies = get_posts(
				array(
					'post_type'      => bbp_get_reply_post_type(),
					'post_parent'    => $post->ID,
					'post_status'    => array( 'publish', 'closed' ),
					'posts_per_page' => $limit,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'no_found_rows'  => true,
				)
			);
			foreach ( $replies as $reply ) {
				$out[] = array(
					'id'     => (int) $reply->ID,
					'author' => get_the_author_meta( 'display_name', (int) $reply->post_author ),
					'text'   => mb_substr( Text::plain( $reply->post_content ), 0, 1200 ),
					'date'   => (string) $reply->post_date_gmt,
				);
			}
			return $out;
		}
		$comments = get_comments(
			array(
				'post_id' => $post->ID,
				'status'  => 'approve',
				'number'  => $limit,
				'type'    => array( 'comment', 'review' ),
				'orderby' => 'comment_date_gmt',
				'order'   => 'DESC',
			)
		);
		foreach ( $comments as $comment ) {
			$rating = 'review' === $comment->comment_type ? (int) get_comment_meta( $comment->comment_ID, 'rating', true ) : 0;
			$out[]  = array(
				'id'     => (int) $comment->comment_ID,
				'author' => (string) $comment->comment_author,
				'text'   => ( $rating ? "[{$rating}/5] " : '' ) . mb_substr( Text::plain( $comment->comment_content ), 0, 1200 ),
				'date'   => (string) $comment->comment_date_gmt,
			);
		}
		return $out;
	}

	/**
	 * Objects to (re)scan: "source:id" strings, newest first.
	 *
	 * @param int      $days    Look-back days.
	 * @param string[] $sources Sources.
	 * @param int      $limit   Max.
	 * @return string[]
	 */
	public static function scan_targets( $days, array $sources, $limit = 2000 ) {
		$out   = array();
		$after = gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) $days ) * DAY_IN_SECONDS );
		if ( array_intersect( array( 'comment', 'review' ), $sources ) ) {
			$comments = get_comments(
				array(
					'status'     => 'all',
					'type'       => array( 'comment', 'review' ),
					'number'     => $limit,
					'date_query' => array(
						array(
							'after'  => $after,
							'column' => 'comment_date_gmt',
						),
					),
					'orderby'    => 'comment_date_gmt',
					'order'      => 'DESC',
				)
			);
			foreach ( $comments as $comment ) {
				$source = self::comment_source( $comment->comment_type, $comment->comment_post_ID );
				if ( $source && in_array( $source, $sources, true ) && 'trash' !== $comment->comment_approved ) {
					$out[] = $source . ':' . $comment->comment_ID;
				}
			}
		}
		if ( self::has_bbpress() && array_intersect( array( 'bbp_topic', 'bbp_reply' ), $sources ) ) {
			$types = array();
			if ( in_array( 'bbp_topic', $sources, true ) ) {
				$types[] = bbp_get_topic_post_type();
			}
			if ( in_array( 'bbp_reply', $sources, true ) ) {
				$types[] = bbp_get_reply_post_type();
			}
			$posts = get_posts(
				array(
					'post_type'      => $types,
					'post_status'    => array( 'publish', 'closed', 'pending', 'spam' ),
					'posts_per_page' => $limit,
					'date_query'     => array(
						array(
							'after'  => $after,
							'column' => 'post_date_gmt',
						),
					),
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			foreach ( $posts as $id ) {
				$out[] = self::bbp_source( get_post_type( $id ) ) . ':' . (int) $id;
			}
		}
		return array_slice( $out, 0, $limit );
	}
}
