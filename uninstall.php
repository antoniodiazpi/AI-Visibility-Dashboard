<?php
/**
 * Removes everything the plugin stored: settings, the API key and cached data.
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

	// Cached payloads use versioned keys, so they cannot be listed one by one.
	// On sites with an object cache they expire on their own within hours.
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup on uninstall.
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
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
