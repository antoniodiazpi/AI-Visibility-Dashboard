<?php
/**
 * PHPUnit bootstrap: loads the WordPress test library and the plugin.
 *
 * @package AIVisibilityDashboard
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Test bootstrap.

$aivd_root = dirname( __DIR__ );

require_once $aivd_root . '/vendor/autoload.php';

if ( ! getenv( 'WP_PHPUNIT__TESTS_CONFIG' ) ) {
	putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
}

if ( ! is_dir( sys_get_temp_dir() . '/aivd-tests-db' ) ) {
	mkdir( sys_get_temp_dir() . '/aivd-tests-db', 0700, true );
}

$aivd_tests_dir = getenv( 'WP_PHPUNIT__DIR' );

require_once $aivd_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $aivd_root ) {
		require $aivd_root . '/ai-visibility-dashboard.php';
	}
);

require $aivd_tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/class-aivd-test-case.php';
