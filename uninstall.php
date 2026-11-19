<?php
/**
 * Deleting Zinn® Order Updates removes everything it stored: its options, its transients (the
 * visitor opt-in rate counters included), the shopper's channel choice and its queued sends.
 * Order meta stays: it is the store's record of what each customer agreed to.
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
	foreach ( array( 'zinn_order_updates_send', 'zinn_order_updates_consent_retry' ) as $hook ) {
		wp_unschedule_hook( $hook );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook );
		}
	}
	delete_metadata( 'user', 0, '_zinn_ou_channel', '', true );
	$like = array(
		$wpdb->esc_like( 'zinn_order_updates_' ) . '%',
		$wpdb->esc_like( '_transient_zinn_order_updates_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_zinn_order_updates_' ) . '%',
		$wpdb->esc_like( '_transient_zinn_ou_optin_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_zinn_ou_optin_' ) . '%',
	);
	foreach ( $like as $pattern ) {
		$options = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- uninstall: finding our own options.
		foreach ( (array) $options as $option ) {
			delete_option( (string) $option );
		}
	}
}

zinn_order_updates_uninstall();
