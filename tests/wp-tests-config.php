<?php
/**
 * Configuration for the WordPress PHPUnit library.
 *
 * WP_CORE_DIR points at the WordPress install prepared by bin/install-wp-tests.sh.
 * With its SQLite drop-in in place no database server is needed; without it the
 * WP_DB_* environment variables describe a MySQL database that will be wiped.
 *
 * @package AIVisibilityDashboard
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Constants and globals required by the WordPress test library.

$aivd_core_dir = getenv( 'WP_CORE_DIR' );
if ( ! $aivd_core_dir ) {
	$aivd_core_dir = sys_get_temp_dir() . '/aivd-wp/wordpress';
}
define( 'ABSPATH', rtrim( $aivd_core_dir, '/\\' ) . '/' );

define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );

define( 'DB_NAME', getenv( 'WP_DB_NAME' ) ? getenv( 'WP_DB_NAME' ) : 'wordpress_test' );
define( 'DB_USER', getenv( 'WP_DB_USER' ) ? getenv( 'WP_DB_USER' ) : 'root' );
define( 'DB_PASSWORD', getenv( 'WP_DB_PASSWORD' ) ? getenv( 'WP_DB_PASSWORD' ) : '' );
define( 'DB_HOST', getenv( 'WP_DB_HOST' ) ? getenv( 'WP_DB_HOST' ) : '127.0.0.1' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

// Used by the SQLite drop-in only.
define( 'DB_DIR', sys_get_temp_dir() . '/aivd-tests-db/' );
define( 'DB_FILE', 'tests.sqlite' );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'AI Visibility Dashboard tests' );
define( 'WP_PHP_BINARY', PHP_BINARY );
define( 'WPLANG', '' );
