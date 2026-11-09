<?php
/**
 * Deleting Zinn® Order Updates removes everything it stored: its options and its transients.
 * The store token stops being used; disconnect the store in the Zinn® dashboard too.
 *
 * @package ZinnOrderUpdates
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes the plugin's data.
 */
function zinn_order_updates_uninstall(): void {
	global $wpdb;
	$like = array(
		$wpdb->esc_like( 'zinn_order_updates_' ) . '%',
		$wpdb->esc_like( '_transient_zinn_order_updates_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_zinn_order_updates_' ) . '%',
	);
	foreach ( $like as $pattern ) {
		$options = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- uninstall: finding our own options.
		foreach ( (array) $options as $option ) {
			delete_option( (string) $option );
		}
	}
}

zinn_order_updates_uninstall();
