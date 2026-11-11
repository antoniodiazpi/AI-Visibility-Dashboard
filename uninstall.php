<?php
/**
 * Removes everything the plugin stored: settings, the API key, cached data,
 * locks and scheduled updates.
 *
 * @package AIVisibilityDashboard
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Deletes the plugin data of the current site.
 */
function aivd_uninstall_site() {
	global $wpdb;

	delete_option( 'aivd_settings' );
	delete_option( 'aivd_cache_version' );

	// delete_option() also clears each snapshot from a persistent object cache.
	$aivd_index = get_option( 'aivd_snapshots', array() );
	foreach ( is_array( $aivd_index ) ? array_keys( $aivd_index ) : array() as $aivd_id ) {
		delete_option( 'aivd_snapshot_' . $aivd_id );
	}
	delete_option( 'aivd_snapshots' );

	wp_unschedule_hook( 'aivd_background_refresh' );

	// Anything left behind (locks, snapshots of an older index, transients) has hashed names.
	// Transients kept in an object cache expire on their own within minutes.
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup on uninstall.
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( 'aivd_snapshot_' ) . '%',
			$wpdb->esc_like( 'aivd_lock_' ) . '%',
			$wpdb->esc_like( '_transient_aivd_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_aivd_' ) . '%'
		)
	);
}

if ( is_multisite() ) {
	$aivd_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $aivd_site_ids as $aivd_site_id ) {
		switch_to_blog( $aivd_site_id );
		aivd_uninstall_site();
		restore_current_blog();
	}
} else {
	aivd_uninstall_site();
}
