<?php
/**
 * Settings screen handler tests.
 *
 * @package AIVisibilityDashboard
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.Security.NonceVerification -- Test code.

/**
 * AIVD_Admin.
 */
class Test_AIVD_Admin extends AIVD_Test_Case {

	public function tear_down() {
		$_POST    = array();
		$_REQUEST = array();
		$_GET     = array();
		parent::tear_down();
	}

	public function test_save_requires_manage_options() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$_POST['_wpnonce']     = wp_create_nonce( 'aivd_save' );
		$_REQUEST['_wpnonce']  = $_POST['_wpnonce'];
		$_POST['aivd_api_key'] = 'mencoro_sk_attacker_000000';

		$this->expectException( WPDieException::class );
		try {
			AIVD_Admin::handle_save();
		} finally {
			$this->assertSame( '', AIVD_Settings::api_key() );
		}
	}

	public function test_save_requires_a_valid_nonce() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST['aivd_api_key'] = self::KEY;
		$_REQUEST['_wpnonce']  = 'invalid';
		$_POST['_wpnonce']     = 'invalid';

		$this->expectException( WPDieException::class );
		AIVD_Admin::handle_save();
	}

	public function test_refresh_requires_widget_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'aivd_refresh' );
		$_GET['_wpnonce']     = $_REQUEST['_wpnonce'];

		$this->expectException( WPDieException::class );
		AIVD_Admin::handle_refresh();
	}
}
