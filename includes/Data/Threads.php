<?php
/**
 * Accepted discussion summaries (one per post, topic or product).
 *
 * @package Dev3\AiCommentModerator
 */

namespace Dev3\AiCommentModerator\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Repository for {prefix}dev3cm_threads.
 */
class Threads {

	/**
	 * Request memo.
	 *
	 * @var array
	 */
	private static $memo = array();

	/**
	 * Saved summary of a post.
	 *
	 * @param int $post_id Post.
	 * @return array|null
	 */
	public static function get( $post_id ) {
		global $wpdb;
		$post_id = (int) $post_id;
		if ( array_key_exists( $post_id, self::$memo ) ) {
			return self::$memo[ $post_id ];
		}
		$table = Tables::name( 'threads' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d", $post_id ), ARRAY_A );
		if ( $row ) {
			foreach ( array( 'themes', 'questions', 'actions' ) as $field ) {
				$value         = json_decode( (string) $row[ $field ], true );
				$row[ $field ] = is_array( $value ) ? $value : array();
			}
			$row['sentiment']   = (float) $row['sentiment'];
			$row['item_count']  = (int) $row['item_count'];
			$row['show_public'] = (int) $row['show_public'];
		}
		self::$memo[ $post_id ] = $row ? $row : null;
		return self::$memo[ $post_id ];
	}

	/**
	 * Saves (accepts) a summary.
	 *
	 * @param int   $post_id Post.
	 * @param array $data    summary, themes, questions, actions, sentiment, item_count, show_public, model.
	 * @return array|null Saved row.
	 */
	public static function save( $post_id, array $data ) {
		global $wpdb;
		$post_id = (int) $post_id;
		$row     = array(
			'post_id'     => $post_id,
			'summary'     => mb_substr( sanitize_textarea_field( (string) ( $data['summary'] ?? '' ) ), 0, 1200 ),
			'themes'      => wp_json_encode( self::clean_themes( (array) ( $data['themes'] ?? array() ) ) ),
			'questions'   => wp_json_encode( self::clean_list( (array) ( $data['questions'] ?? array() ) ) ),
			'actions'     => wp_json_encode( self::clean_list( (array) ( $data['actions'] ?? array() ) ) ),
			'sentiment'   => max( -1, min( 1, (float) ( $data['sentiment'] ?? 0 ) ) ),
			'item_count'  => max( 0, (int) ( $data['item_count'] ?? 0 ) ),
			'show_public' => empty( $data['show_public'] ) ? 0 : 1,
			'model'       => mb_substr( sanitize_text_field( (string) ( $data['model'] ?? '' ) ), 0, 60 ),
			'updated_by'  => get_current_user_id(),
			'updated_at'  => current_time( 'mysql', true ),
		);
		$table   = Tables::name( 'threads' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE post_id = %d", $post_id ) );
		if ( $exists ) {
			$wpdb->update( $table, $row, array( 'id' => $exists ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$wpdb->insert( $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		unset( self::$memo[ $post_id ] );
		return self::get( $post_id );
	}

	/**
	 * Removes a summary.
	 *
	 * @param int $post_id Post.
	 * @return bool
	 */
	public static function delete( $post_id ) {
		global $wpdb;
		unset( self::$memo[ (int) $post_id ] );
		return (bool) $wpdb->delete( Tables::name( 'threads' ), array( 'post_id' => (int) $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Clears the memo (tests).
	 */
	public static function reset() {
		self::$memo = array();
	}

	/**
	 * Theme list.
	 *
	 * @param array $themes Raw.
	 * @return array
	 */
	public static function clean_themes( array $themes ) {
		$out = array();
		foreach ( array_slice( $themes, 0, 6 ) as $theme ) {
			if ( ! is_array( $theme ) || '' === trim( (string) ( $theme['label'] ?? '' ) ) ) {
				continue;
			}
			$out[] = array(
				'label'     => mb_substr( sanitize_text_field( (string) $theme['label'] ), 0, 60 ),
				'sentiment' => round( max( -1, min( 1, (float) ( $theme['sentiment'] ?? 0 ) ) ), 2 ),
				'share'     => round( max( 0, min( 1, (float) ( $theme['share'] ?? 0 ) ) ), 2 ),
			);
		}
		return $out;
	}

	/**
	 * String list.
	 *
	 * @param array $lines_in Raw.
	 * @return array
	 */
	public static function clean_list( array $lines_in ) {
		$out = array();
		foreach ( array_slice( $lines_in, 0, 5 ) as $line ) {
			$line = mb_substr( sanitize_text_field( (string) $line ), 0, 200 );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	/**
	 * API shape.
	 *
	 * @param array|null $row Row.
	 * @return array|null
	 */
	public static function format( $row ) {
		if ( ! $row ) {
			return null;
		}
		return array(
			'summary'     => (string) $row['summary'],
			'themes'      => $row['themes'],
			'questions'   => $row['questions'],
			'actions'     => $row['actions'],
			'sentiment'   => (float) $row['sentiment'],
			'item_count'  => (int) $row['item_count'],
			'show_public' => (bool) $row['show_public'],
			'model'       => (string) $row['model'],
			'updated_at'  => mysql_to_rfc3339( $row['updated_at'] ),
		);
	}
}
