<?php
/**
 * Moderation records: one row per comment, review or forum post the plugin screened.
 *
 * @package Dev3\AiCommentModerator
 */

namespace Dev3\AiCommentModerator\Data;

use Dev3\AiCommentModerator\Moderation\Policy;
use Dev3\AiCommentModerator\Moderation\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * Repository for {prefix}dev3cm_items.
 */
class Items {

	const VIEWS    = array( 'needs', 'held', 'spam', 'flagged', 'reviewed', 'trash', 'all' );
	const STATUSES = array( 'live', 'held', 'spam', 'trash' );
	const JSON     = array( 'rule_flags', 'labels', 'history' );

	/**
	 * Table.
	 *
	 * @return string
	 */
	private static function table() {
		return Tables::name( 'items' );
	}

	/**
	 * Current GMT time.
	 *
	 * @return string
	 */
	public static function now() {
		return current_time( 'mysql', true );
	}

	/**
	 * Decodes a DB row.
	 *
	 * @param array|null $row Row.
	 * @return array|null
	 */
	public static function decode( $row ) {
		if ( ! $row ) {
			return null;
		}
		foreach ( self::JSON as $field ) {
			$value         = isset( $row[ $field ] ) ? json_decode( (string) $row[ $field ], true ) : null;
			$row[ $field ] = is_array( $value ) ? $value : array();
		}
		foreach ( array( 'id', 'object_id', 'post_id', 'parent_id', 'user_id', 'reviewed', 'reviewed_by' ) as $int ) {
			$row[ $int ] = (int) ( $row[ $int ] ?? 0 );
		}
		$row['sentiment'] = (float) ( $row['sentiment'] ?? 0 );
		return $row;
	}

	/**
	 * Encodes JSON columns.
	 *
	 * @param array $fields Fields.
	 * @return array
	 */
	private static function encode( array $fields ) {
		foreach ( self::JSON as $field ) {
			if ( array_key_exists( $field, $fields ) && ! is_string( $fields[ $field ] ) ) {
				$fields[ $field ] = wp_json_encode( $fields[ $field ] );
			}
		}
		if ( isset( $fields['excerpt'] ) ) {
			$fields['excerpt'] = mb_substr( (string) $fields['excerpt'], 0, 400 );
		}
		if ( isset( $fields['reason'] ) ) {
			$fields['reason'] = mb_substr( (string) $fields['reason'], 0, 255 );
		}
		if ( isset( $fields['author_name'] ) ) {
			$fields['author_name'] = mb_substr( (string) $fields['author_name'], 0, 120 );
		}
		if ( isset( $fields['codes'] ) && is_array( $fields['codes'] ) ) {
			$fields['codes'] = $fields['codes'] ? ',' . implode( ',', array_unique( $fields['codes'] ) ) . ',' : '';
		}
		return $fields;
	}

	/**
	 * One record.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return self::decode( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A ) );
	}

	/**
	 * Record of a source object.
	 *
	 * @param string $source    Source.
	 * @param int    $object_id Object id.
	 * @return array|null
	 */
	public static function find( $source, $object_id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return self::decode( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE source = %s AND object_id = %d", (string) $source, (int) $object_id ), ARRAY_A ) );
	}

	/**
	 * Inserts or updates by (source, object_id).
	 *
	 * @param array $row Row.
	 * @return int Id.
	 */
	public static function upsert( array $row ) {
		global $wpdb;
		$existing = self::find( $row['source'], $row['object_id'] );
		if ( $existing ) {
			self::update( $existing['id'], $row );
			return $existing['id'];
		}
		$now               = self::now();
		$row               = self::encode(
			$row + array(
				'rule_flags' => array(),
				'labels'     => array(),
				'history'    => array(),
				'created_at' => $now,
			)
		);
		$row['updated_at'] = $now;
		$wpdb->insert( self::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->insert_id;
	}

	/**
	 * Updates fields.
	 *
	 * @param int   $id     Id.
	 * @param array $fields Fields.
	 * @return bool
	 */
	public static function update( $id, array $fields ) {
		global $wpdb;
		unset( $fields['id'], $fields['created_at'] );
		$fields               = self::encode( $fields );
		$fields['updated_at'] = self::now();
		return false !== $wpdb->update( self::table(), $fields, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Deletes one record (the content it describes is already gone).
	 *
	 * @param int $id Id.
	 */
	public static function remove( $id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Appends a history event (keeps the last 20).
	 *
	 * @param int    $id     Id.
	 * @param string $action Action (screened, held, spammed, approved, reviewed, classified, replied…).
	 * @param string $by     rules|ai|user:<id>|system.
	 * @param string $note   Note.
	 */
	public static function log( $id, $action, $by, $note = '' ) {
		$item = self::get( $id );
		if ( ! $item ) {
			return;
		}
		$history   = $item['history'];
		$history[] = array(
			'at'     => self::now(),
			'action' => (string) $action,
			'by'     => (string) $by,
			'note'   => mb_substr( (string) $note, 0, 200 ),
		);
		self::update( $id, array( 'history' => array_slice( $history, -20 ) ) );
	}

	/**
	 * WHERE clause of a view.
	 *
	 * @param string $view View.
	 * @return string
	 */
	private static function view_sql( $view ) {
		switch ( $view ) {
			case 'needs':
				return "reviewed = 0 AND ( status = 'held' OR ( status = 'live' AND decision <> 'none' ) )";
			case 'held':
				return "status = 'held'";
			case 'spam':
				return "status = 'spam'";
			case 'flagged':
				return "status = 'live' AND decision <> 'none'";
			case 'reviewed':
				return 'reviewed = 1';
			case 'trash':
				return "status = 'trash'";
			default:
				return "status <> 'trash'";
		}
	}

	/**
	 * Builds WHERE for filters (except view).
	 *
	 * @param array $args source, label, post_id, search.
	 * @return string
	 */
	private static function filters_sql( array $args ) {
		global $wpdb;
		$where = array( '1=1' );
		if ( ! empty( $args['source'] ) && in_array( $args['source'], Sources::ALL, true ) ) {
			$where[] = $wpdb->prepare( 'source = %s', $args['source'] );
		}
		if ( ! empty( $args['label'] ) && preg_match( '/^[a-z_]{2,30}$/', (string) $args['label'] ) ) {
			$where[] = $wpdb->prepare( 'codes LIKE %s', '%,' . $wpdb->esc_like( (string) $args['label'] ) . ',%' );
		}
		if ( ! empty( $args['post_id'] ) ) {
			$where[] = $wpdb->prepare( 'post_id = %d', (int) $args['post_id'] );
		}
		if ( ! empty( $args['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[] = $wpdb->prepare( '( excerpt LIKE %s OR author_name LIKE %s OR author_email LIKE %s OR reason LIKE %s )', $like, $like, $like, $like );
		}
		return implode( ' AND ', $where );
	}

	/**
	 * Paged list.
	 *
	 * @param array $args view, source, label, post_id, search, page, per_page.
	 * @return array { items, total }
	 */
	public static function query( array $args ) {
		global $wpdb;
		$table    = self::table();
		$view     = in_array( $args['view'] ?? 'needs', self::VIEWS, true ) ? $args['view'] : 'needs';
		$per_page = min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$where    = self::view_sql( $view ) . ' AND ' . self::filters_sql( $args );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $per_page, ( $page - 1 ) * $per_page ), ARRAY_A );
		// phpcs:enable
		return array(
			'items' => array_map( array( self::class, 'decode' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Count per view (with filters).
	 *
	 * @param array $args Filters.
	 * @return array
	 */
	public static function counts( array $args = array() ) {
		global $wpdb;
		$table  = self::table();
		$where  = self::filters_sql( $args );
		$select = array();
		foreach ( self::VIEWS as $view ) {
			$select[] = 'SUM(CASE WHEN ' . self::view_sql( $view ) . " THEN 1 ELSE 0 END) AS `{$view}`";
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$row = (array) $wpdb->get_row( 'SELECT ' . implode( ', ', $select ) . " FROM {$table} WHERE {$where}", ARRAY_A );
		$out = array();
		foreach ( self::VIEWS as $view ) {
			$out[ $view ] = (int) ( $row[ $view ] ?? 0 );
		}
		return $out;
	}

	/**
	 * Items from the same IP hash or email in the last N minutes.
	 *
	 * @param string $ip_hash IP hash.
	 * @param string $email   Email.
	 * @param int    $minutes Window.
	 * @return int
	 */
	public static function recent_from( $ip_hash, $email, $minutes ) {
		global $wpdb;
		if ( '' === $ip_hash && '' === $email ) {
			return 0;
		}
		$table = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - 60 * (int) $minutes );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s AND ( ( ip_hash <> '' AND ip_hash = %s ) OR ( author_email <> '' AND author_email = %s ) )", $since, $ip_hash, $email ) );
	}

	/**
	 * Same normalised text by the same author/IP on another post, or by anyone on 3+ posts, within N days.
	 *
	 * @param string $hash    Content hash.
	 * @param int    $post_id Post the new item belongs to.
	 * @param string $ip_hash IP hash.
	 * @param string $email   Email.
	 * @param int    $days    Window.
	 * @return int Matching earlier items.
	 */
	public static function duplicates( $hash, $post_id, $ip_hash, $email, $days ) {
		global $wpdb;
		if ( '' === $hash ) {
			return 0;
		}
		$table = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS * (int) $days );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$same_author = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE content_hash = %s AND created_at >= %s AND post_id <> %d AND ( ( ip_hash <> '' AND ip_hash = %s ) OR ( author_email <> '' AND author_email = %s ) )", $hash, $since, (int) $post_id, $ip_hash, $email ) );
		if ( $same_author ) {
			return $same_author;
		}
		$posts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT post_id) FROM {$table} WHERE content_hash = %s AND created_at >= %s AND post_id <> %d", $hash, $since, (int) $post_id ) );
		// phpcs:enable
		return $posts >= 2 ? $posts : 0;
	}

	/**
	 * Records queued for AI.
	 *
	 * @param int $limit Max.
	 * @return array
	 */
	public static function queued( $limit = 40 ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE ai_state = 'queued' ORDER BY id ASC LIMIT %d", (int) $limit ), ARRAY_A );
		return array_map( array( self::class, 'decode' ), (array) $rows );
	}

	/**
	 * Oldest queued record age in seconds (0 when none).
	 *
	 * @return int
	 */
	public static function queue_age() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$oldest = $wpdb->get_var( "SELECT MIN(updated_at) FROM {$table} WHERE ai_state = 'queued'" );
		return $oldest ? max( 0, time() - (int) strtotime( $oldest . ' UTC' ) ) : 0;
	}

	/**
	 * API shape.
	 *
	 * @param array $item Decoded row.
	 * @param bool  $full Include full content, parent and post context.
	 * @return array
	 */
	public static function format( array $item, $full = false ) {
		$post = get_post( $item['post_id'] );
		$out  = array(
			'id'            => $item['id'],
			'source'        => $item['source'],
			'object_id'     => $item['object_id'],
			'post'          => $post ? array(
				'id'    => (int) $post->ID,
				'title' => wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES ),
				'url'   => (string) get_permalink( $post ),
				'type'  => (string) $post->post_type,
			) : null,
			'author_name'   => $item['author_name'],
			'author_email'  => $item['author_email'],
			'user_id'       => $item['user_id'],
			'excerpt'       => $item['excerpt'],
			'status'        => $item['status'],
			'decision'      => $item['decision'],
			'reasons'       => Policy::reasons( $item ),
			'labels'        => $item['labels'],
			'reason'        => $item['reason'],
			'sentiment'     => $item['sentiment'],
			'sentiment_src' => $item['sentiment_src'],
			'ai_state'      => $item['ai_state'],
			'reviewed'      => (bool) $item['reviewed'],
			'created_at'    => mysql_to_rfc3339( $item['created_at'] ),
		);
		if ( $full ) {
			$out['content']  = Sources::content( $item['source'], $item['object_id'] );
			$out['parent']   = $item['parent_id'] ? Sources::parent_excerpt( $item['source'], $item['parent_id'] ) : null;
			$out['history']  = $item['history'];
			$out['ip_hash']  = $item['ip_hash'] ? substr( $item['ip_hash'], 0, 10 ) : '';
			$out['model']    = $item['model'];
			$out['edit_url'] = Sources::edit_url( $item['source'], $item['object_id'] );
			$out['rating']   = 'review' === $item['source'] ? (int) get_comment_meta( $item['object_id'], 'rating', true ) : 0;
			$out['replies']  = Sources::replies( $item['source'], $item['object_id'], $item['post_id'] );
		}
		return $out;
	}

	/**
	 * Dashboard statistics.
	 *
	 * @return array
	 */
	public static function stats() {
		global $wpdb;
		$table = self::table();
		$d30   = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$d14   = gmdate( 'Y-m-d', time() - 13 * DAY_IN_SECONDS );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$kpi       = (array) $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM(CASE WHEN created_at >= %s THEN 1 ELSE 0 END) AS screened,
					SUM(CASE WHEN created_at >= %s AND status = 'held' THEN 1 ELSE 0 END) AS held,
					SUM(CASE WHEN created_at >= %s AND status = 'spam' THEN 1 ELSE 0 END) AS spam,
					SUM(CASE WHEN created_at >= %s AND ai_state = 'done' THEN 1 ELSE 0 END) AS ai,
					AVG(CASE WHEN created_at >= %s AND status IN ('live','held') THEN sentiment END) AS sentiment
				FROM {$table}",
				$d30,
				$d30,
				$d30,
				$d30,
				$d30
			),
			ARRAY_A
		);
		$days      = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) AS d, SUM(status = 'live') AS live, SUM(status = 'held') AS held, SUM(status = 'spam') AS spam
				FROM {$table} WHERE created_at >= %s GROUP BY DATE(created_at)",
				$d14 . ' 00:00:00'
			),
			ARRAY_A
		);
		$code_rows = $wpdb->get_col( $wpdb->prepare( "SELECT codes FROM {$table} WHERE created_at >= %s AND codes <> '' LIMIT 5000", $d30 ) );
		// phpcs:enable
		$by_day = array();
		foreach ( (array) $days as $row ) {
			$by_day[ $row['d'] ] = $row;
		}
		$series = array();
		for ( $i = 13; $i >= 0; $i-- ) {
			$day      = gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
			$series[] = array(
				'date' => $day,
				'live' => (int) ( $by_day[ $day ]['live'] ?? 0 ),
				'held' => (int) ( $by_day[ $day ]['held'] ?? 0 ),
				'spam' => (int) ( $by_day[ $day ]['spam'] ?? 0 ),
			);
		}
		$codes = array();
		foreach ( (array) $code_rows as $list ) {
			foreach ( array_filter( explode( ',', (string) $list ) ) as $code ) {
				$codes[ $code ] = ( $codes[ $code ] ?? 0 ) + 1;
			}
		}
		arsort( $codes );
		$counts = self::counts();
		return array(
			'needs'      => $counts['needs'],
			'screened'   => (int) ( $kpi['screened'] ?? 0 ),
			'held'       => (int) ( $kpi['held'] ?? 0 ),
			'spam'       => (int) ( $kpi['spam'] ?? 0 ),
			'classified' => (int) ( $kpi['ai'] ?? 0 ),
			'sentiment'  => null === ( $kpi['sentiment'] ?? null ) ? null : round( (float) $kpi['sentiment'], 2 ),
			'series'     => $series,
			'codes'      => $codes,
			'counts'     => $counts,
		);
	}

	/**
	 * Per-post aggregates (Discussions screen).
	 *
	 * @param array $args search, sort (negative|active|recent|positive), source, page, per_page, post_id.
	 * @return array { items, total }
	 */
	public static function threads( array $args ) {
		global $wpdb;
		$table    = self::table();
		$per_page = min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$where    = array( "i.status <> 'trash'" );
		if ( ! empty( $args['source'] ) && in_array( $args['source'], Sources::ALL, true ) ) {
			$where[] = $wpdb->prepare( 'i.source = %s', $args['source'] );
		}
		if ( ! empty( $args['post_id'] ) ) {
			$where[] = $wpdb->prepare( 'i.post_id = %d', (int) $args['post_id'] );
		}
		if ( ! empty( $args['search'] ) ) {
			$where[] = $wpdb->prepare( 'p.post_title LIKE %s', '%' . $wpdb->esc_like( (string) $args['search'] ) . '%' );
		}
		$where = implode( ' AND ', $where );
		$sorts = array(
			'negative' => 'sentiment ASC, total DESC',
			'positive' => 'sentiment DESC, total DESC',
			'active'   => 'total DESC, last_at DESC',
			'recent'   => 'last_at DESC',
		);
		$order = $sorts[ $args['sort'] ?? 'recent' ] ?? $sorts['recent'];
		$from  = "{$table} i LEFT JOIN {$wpdb->posts} p ON p.ID = i.post_id";
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT i.post_id) FROM {$from} WHERE {$where}" );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.post_id, COUNT(*) AS total,
					SUM(i.status = 'live') AS live, SUM(i.status = 'held') AS held, SUM(i.status = 'spam') AS spam,
					AVG(CASE WHEN i.status <> 'spam' THEN i.sentiment END) AS sentiment,
					SUM(i.status <> 'spam' AND i.sentiment >= 0.25) AS positive,
					SUM(i.status <> 'spam' AND i.sentiment <= -0.25) AS negative,
					SUM(i.status <> 'spam' AND i.sentiment > -0.25 AND i.sentiment < 0.25) AS neutral,
					MAX(i.created_at) AS last_at, MAX(i.source) AS source
				FROM {$from} WHERE {$where} GROUP BY i.post_id ORDER BY {$order} LIMIT %d OFFSET %d",
				$per_page,
				( $page - 1 ) * $per_page
			),
			ARRAY_A
		);
		// phpcs:enable
		$items = array();
		foreach ( (array) $rows as $row ) {
			$post_id = (int) $row['post_id'];
			$post    = get_post( $post_id );
			$summary = Threads::get( $post_id );
			$items[] = array(
				'post_id'   => $post_id,
				'title'     => $post ? wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES ) : __( '(deleted)', 'dev3-ai-comment-moderator' ),
				'url'       => $post ? (string) get_permalink( $post ) : '',
				'type'      => $post ? (string) $post->post_type : '',
				'source'    => (string) $row['source'],
				'total'     => (int) $row['total'],
				'live'      => (int) $row['live'],
				'held'      => (int) $row['held'],
				'spam'      => (int) $row['spam'],
				'sentiment' => null === $row['sentiment'] ? 0.0 : round( (float) $row['sentiment'], 2 ),
				'positive'  => (int) $row['positive'],
				'neutral'   => (int) $row['neutral'],
				'negative'  => (int) $row['negative'],
				'last_at'   => mysql_to_rfc3339( $row['last_at'] ),
				'summary'   => $summary ? array(
					'updated_at'  => mysql_to_rfc3339( $summary['updated_at'] ),
					'show_public' => (bool) $summary['show_public'],
					'stale'       => (int) $row['live'] > (int) $summary['item_count'],
				) : null,
			);
		}
		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Deletes records older than the retention window (daily cron). Never touches the comments themselves.
	 *
	 * @param int $days Days (0 = keep forever).
	 * @return int Rows removed.
	 */
	public static function prune( $days ) {
		global $wpdb;
		if ( $days <= 0 ) {
			return 0;
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
	}

	/**
	 * Rows for the CSV export.
	 *
	 * @param array $args Filters + view.
	 * @return array
	 */
	public static function export_rows( array $args ) {
		$out  = array();
		$page = 1;
		do {
			$res = self::query(
				$args + array(
					'per_page' => 100,
					'page'     => $page,
				)
			);
			foreach ( $res['items'] as $item ) {
				$out[] = array(
					'id'         => $item['id'],
					'source'     => $item['source'],
					'object_id'  => $item['object_id'],
					'post_id'    => $item['post_id'],
					'author'     => $item['author_name'],
					'status'     => $item['status'],
					'decision'   => $item['decision'],
					'reasons'    => implode( '; ', wp_list_pluck( Policy::reasons( $item ), 'label' ) ),
					'sentiment'  => $item['sentiment'],
					'reviewed'   => $item['reviewed'],
					'excerpt'    => $item['excerpt'],
					'created_at' => $item['created_at'],
				);
			}
			++$page;
			$full = 100 === count( $res['items'] );
		} while ( $full && $page <= 100 );
		return $out;
	}

	/**
	 * GDPR exporter.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function export_personal_data( $email, $page = 1 ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE author_email = %s ORDER BY id LIMIT 100 OFFSET %d", $email, ( max( 1, (int) $page ) - 1 ) * 100 ), ARRAY_A );
		$data = array();
		foreach ( (array) $rows as $row ) {
			$data[] = array(
				'group_id'    => 'dev3cm-moderation',
				'group_label' => __( 'Comment moderation records', 'dev3-ai-comment-moderator' ),
				'item_id'     => 'dev3cm-item-' . $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'Excerpt', 'dev3-ai-comment-moderator' ),
						'value' => $row['excerpt'],
					),
					array(
						'name'  => __( 'Moderation status', 'dev3-ai-comment-moderator' ),
						'value' => $row['status'],
					),
					array(
						'name'  => __( 'Date', 'dev3-ai-comment-moderator' ),
						'value' => $row['created_at'],
					),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => count( (array) $rows ) < 100,
		);
	}

	/**
	 * GDPR eraser: anonymises author fields.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function erase_personal_data( $email, $page = 1 ) {
		global $wpdb;
		unset( $page );
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET author_email = '', author_name = %s, ip_hash = '' WHERE author_email = %s", __( 'Anonymous', 'dev3-ai-comment-moderator' ), $email ) );
		return array(
			'items_removed'  => $count > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
