<?php
/**
 * Uninstall: removes data only when "delete data on uninstall" is enabled. Comments themselves are never touched.
 *
 * @package Dev3\AiCommentModerator
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$dev3cm_settings = get_option( 'dev3cm_settings', array() );
if ( empty( $dev3cm_settings['delete_on_uninstall'] ) ) {
	return;
}
global $wpdb;
foreach ( array( 'dev3cm_items', 'dev3cm_threads', 'dev3cm_usage', 'dev3cm_core_cache', 'dev3cm_core_jobs', 'dev3cm_core_embeddings' ) as $dev3cm_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$dev3cm_table}" );
}
foreach ( array( 'dev3cm_settings', 'dev3cm_db_version' ) as $dev3cm_option ) {
	delete_option( $dev3cm_option );
}
delete_transient( 'dev3cm_models' );
delete_transient( 'dev3cm_needs_count' );
wp_unschedule_hook( 'dev3cm_run_job' );
wp_unschedule_hook( 'dev3cm_daily' );
wp_unschedule_hook( 'dev3cm_process_queue' );
