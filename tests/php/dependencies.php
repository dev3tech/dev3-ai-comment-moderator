<?php
/**
 * Loads WooCommerce and bbPress (both free, installed in wp-env) before the plugin in the test suite.
 *
 * @package Dev3\AiCommentModerator
 */

tests_add_filter(
	'dev3cm_test_dependencies',
	static function () {
		$deps = array();
		foreach ( array( 'woocommerce/woocommerce.php', 'woocommerce.latest-stable/woocommerce.php', 'bbpress/bbpress.php' ) as $dev3cm_dep ) {
			if ( file_exists( WP_PLUGIN_DIR . '/' . $dev3cm_dep ) ) {
				$deps[] = $dev3cm_dep;
			}
		}
		return $deps;
	}
);

tests_add_filter(
	'setup_theme',
	static function () {
		if ( class_exists( 'WC_Install' ) ) {
			WC_Install::install();
			$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reload roles WooCommerce just added.
			wp_roles();
		}
		global $wpdb;
		foreach ( array( 'dev3cm_items', 'dev3cm_threads' ) as $dev3cm_table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $dev3cm_table ) ) ) {
				$wpdb->query( "DELETE FROM {$wpdb->prefix}{$dev3cm_table}" ); // phpcs:ignore
			}
		}
	}
);

tests_add_filter(
	'wp_mail_from',
	static function () {
		return 'site@example.org';
	}
);
