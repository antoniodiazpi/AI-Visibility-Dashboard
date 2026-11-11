<?php
/**
 * Settings tests.
 *
 * @package AIVisibilityDashboard
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Test code.

/**
 * AIVD_Settings.
 */
class Test_AIVD_Settings extends AIVD_Test_Case {

	public function test_defaults_when_nothing_is_stored() {
		$this->assertSame( AIVD_Settings::defaults(), AIVD_Settings::all() );
		$this->assertFalse( AIVD_Settings::is_configured() );
		$this->assertSame( '', AIVD_Settings::key_source() );
	}

	public function test_option_is_not_autoloaded() {
		$this->configure();
		global $wpdb;
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", AIVD_Settings::OPTION ) );
		$this->assertContains( $autoload, array( 'no', 'off' ) );
	}

	public function test_environment_variable_wins_over_stored_key() {
		$this->configure();
		$this->assertSame( 'option', AIVD_Settings::key_source() );
		putenv( 'MENCORO_API_KEY=mencoro_sk_from_env_99998888' );
		$this->assertSame( 'env', AIVD_Settings::key_source() );
		$this->assertSame( 'mencoro_sk_from_env_99998888', AIVD_Settings::api_key() );
	}

	public function test_key_hint_shows_only_last_four_characters() {
		$this->configure();
		$this->assertSame( 'cdef', AIVD_Settings::key_hint() );
		AIVD_Settings::update( array( 'api_key' => 'short' ) );
		$this->assertSame( '', AIVD_Settings::key_hint(), 'Short keys are not hinted at all.' );
	}

	/**
	 * @dataProvider range_values
	 *
	 * @param mixed $value    Input.
	 * @param int   $expected Range.
	 */
	public function test_range_only_accepts_known_windows( $value, $expected ) {
		$this->assertSame( $expected, AIVD_Settings::range( $value ) );
	}

	public function range_values() {
		return array(
			array( 7, 7 ),
			array( '90', 90 ),
			array( 30, 28 ),
			array( 'abc', 28 ),
			array( -7, 28 ),
		);
	}

	public function test_widget_capability_follows_audience_and_filter() {
		$this->assertSame( 'manage_options', AIVD_Settings::widget_capability() );
		AIVD_Settings::update( array( 'audience' => 'editors' ) );
		$this->assertSame( 'edit_others_posts', AIVD_Settings::widget_capability() );

		$filter = static function () {
			return 'read';
		};
		add_filter( 'aivd_widget_capability', $filter );
		$this->assertSame( 'read', AIVD_Settings::widget_capability() );
		remove_filter( 'aivd_widget_capability', $filter );
	}
}
