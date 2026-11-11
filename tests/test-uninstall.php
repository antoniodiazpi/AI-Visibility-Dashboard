<?php
/**
 * Uninstall tests.
 *
 * @package AIVisibilityDashboard
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Test code.

/**
 * uninstall.php.
 */
class Test_AIVD_Uninstall extends AIVD_Test_Case {

	/**
	 * Leaves data behind on the current site, as real use does.
	 */
	private function use_plugin() {
		$this->configure();
		$this->mock_project();
		AIVD_Data::state( 28 );
		AIVD_Lock::acquire( 'leftover', 600 );
		set_transient( 'aivd_projects_x', array( 'cached' ), 600 );
		wp_schedule_single_event( time() + 60, AIVD_Data::CRON_HOOK, array( 7 ) );
	}

	/**
	 * Every aivd row of the options table of the current site.
	 *
	 * @return string[]
	 */
	private function leftovers() {
		global $wpdb;
		return $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", 'aivd%', '%transient%aivd%' ) );
	}

	public function test_uninstall_removes_everything() {
		$this->use_plugin();

		$other = 0;
		if ( is_multisite() ) {
			$other = self::factory()->blog->create();
			switch_to_blog( $other );
			$this->use_plugin();
			restore_current_blog();
		}

		$this->assertNotEmpty( $this->leftovers() );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'ai-visibility-dashboard/ai-visibility-dashboard.php' );
		}
		include dirname( __DIR__ ) . '/uninstall.php';

		wp_cache_flush();
		$this->assertSame( array(), $this->leftovers() );
		$this->assertFalse( wp_next_scheduled( AIVD_Data::CRON_HOOK, array( 7 ) ) );
		$this->assertSame( '', AIVD_Settings::api_key() );

		if ( $other ) {
			switch_to_blog( $other );
			$this->assertSame( array(), $this->leftovers() );
			restore_current_blog();
		}
	}
}
