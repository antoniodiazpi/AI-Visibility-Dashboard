<?php
/**
 * Short-lived lock so only one request refreshes the data at a time.
 *
 * @package AIVisibilityDashboard
 */

defined( 'ABSPATH' ) || exit;

/**
 * Atomic lock stored as a row of the options table.
 *
 * Same technique as WP_Upgrader::create_lock(): INSERT IGNORE succeeds for one
 * request only, because option_name is unique. The row is read and written
 * straight from the table, never through get_option(), so a persistent object
 * cache cannot hide a lock or keep a released one alive.
 */
class AIVD_Lock {

	const PREFIX = 'aivd_lock_';

	/**
	 * Takes the lock.
	 *
	 * @param string $name    Lock name: letters, digits and underscores.
	 * @param int    $seconds Lifetime. An expired lock can be taken over, so a
	 *                        request that dies while holding it blocks nobody for long.
	 * @return bool Whether this request holds the lock now.
	 */
	public static function acquire( $name, $seconds ) {
		global $wpdb;

		$option  = self::option( $name );
		$now     = time();
		$expires = $now + max( 1, (int) $seconds );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- A lock must bypass the options cache; see the class comment.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )",
				$option,
				(string) $expires
			)
		);
		if ( 1 === (int) $inserted ) {
			return true;
		}

		$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) );
		if ( null === $current ) {
			// Released between the two queries: try once more.
			$inserted = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )",
					$option,
					(string) $expires
				)
			);
			return 1 === (int) $inserted;
		}
		if ( (int) $current > $now ) {
			return false;
		}

		// Expired: take it over, but only if nobody else did first.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				(string) $expires,
				$option,
				$current
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		return 1 === (int) $updated;
	}

	/**
	 * Whether someone holds the lock right now.
	 *
	 * @param string $name Lock name.
	 * @return bool
	 */
	public static function is_locked( $name ) {
		global $wpdb;
		$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::option( $name ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- See the class comment.
		return null !== $current && (int) $current > time();
	}

	/**
	 * Releases the lock.
	 *
	 * @param string $name Lock name.
	 */
	public static function release( $name ) {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::option( $name ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- See the class comment.
	}

	/**
	 * Option name behind a lock.
	 *
	 * @param string $name Lock name.
	 * @return string
	 */
	private static function option( $name ) {
		return self::PREFIX . preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $name ) );
	}
}
