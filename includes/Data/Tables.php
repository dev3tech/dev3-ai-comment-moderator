<?php
/**
 * Plugin tables (moderation items, discussion summaries), added to the core dbDelta run.
 *
 * @package Dev3\AiCommentModerator
 */

namespace Dev3\AiCommentModerator\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Table names + schema.
 */
class Tables {

	const ALL = array( 'items', 'threads' );

	/**
	 * Table name.
	 *
	 * @param string $name items|threads.
	 * @return string
	 */
	public static function name( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'dev3cm_' . $name;
	}

	/**
	 * Adds CREATE TABLE statements (dev3cm_schema_sql).
	 *
	 * @param array  $sql     Statements.
	 * @param string $charset Charset collate.
	 * @return array
	 */
	public static function schema( $sql, $charset ) {
		$i     = self::name( 'items' );
		$t     = self::name( 'threads' );
		$sql[] = "CREATE TABLE {$i} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source varchar(20) NOT NULL DEFAULT 'comment',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			author_name varchar(120) NOT NULL DEFAULT '',
			author_email varchar(190) NOT NULL DEFAULT '',
			ip_hash char(40) NOT NULL DEFAULT '',
			excerpt varchar(400) NOT NULL DEFAULT '',
			content_hash char(32) NOT NULL DEFAULT '',
			rule_flags text NULL,
			codes varchar(255) NOT NULL DEFAULT '',
			labels text NULL,
			reason varchar(255) NOT NULL DEFAULT '',
			sentiment decimal(4,3) NOT NULL DEFAULT 0,
			sentiment_src varchar(10) NOT NULL DEFAULT 'lexicon',
			ai_state varchar(12) NOT NULL DEFAULT 'none',
			decision varchar(8) NOT NULL DEFAULT 'none',
			status varchar(8) NOT NULL DEFAULT 'live',
			reviewed tinyint(1) NOT NULL DEFAULT 0,
			reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
			history text NULL,
			model varchar(60) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_object (source,object_id),
			KEY status_created (status,created_at),
			KEY post_id (post_id),
			KEY content_hash (content_hash),
			KEY ai_state (ai_state)
		) {$charset};";
		$sql[] = "CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			summary text NULL,
			themes text NULL,
			questions text NULL,
			actions text NULL,
			sentiment decimal(4,3) NOT NULL DEFAULT 0,
			item_count int(10) unsigned NOT NULL DEFAULT 0,
			show_public tinyint(1) NOT NULL DEFAULT 0,
			model varchar(60) NOT NULL DEFAULT '',
			updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY post_id (post_id)
		) {$charset};";
		return $sql;
	}

	/**
	 * Drops plugin tables (uninstall).
	 */
	public static function drop() {
		global $wpdb;
		foreach ( self::ALL as $table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::name( $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		}
	}
}
