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

	/**
	 * Runs a handler that ends in a redirect, returning where it redirected.
	 *
	 * @param callable $handler Handler.
	 * @return string
	 */
	private function run_redirecting( $handler ) {
		$catch = static function ( $location ) {
			throw new RuntimeException( 'redirect:' . $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test helper, never displayed.
		};
		add_filter( 'wp_redirect', $catch );
		try {
			call_user_func( $handler );
		} catch ( RuntimeException $e ) {
			return substr( $e->getMessage(), 9 );
		} finally {
			remove_filter( 'wp_redirect', $catch );
		}
		$this->fail( 'The handler did not redirect.' );
	}

	/**
	 * Prepares a valid save request.
	 *
	 * @param array $post Fields.
	 */
	private function post_save( array $post ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST                = $post;
		$_POST['_wpnonce']    = wp_create_nonce( 'aivd_save' );
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];
	}

	public function test_save_stores_a_pasted_key_without_spaces() {
		$this->routes['/api/v1/me'] = self::json( array( 'userId' => 'u1' ) );
		$this->post_save( array( 'aivd_api_key' => "  mencoro_sk_pasted_1234\n " ) );
		$this->run_redirecting( array( 'AIVD_Admin', 'handle_save' ) );
		$this->assertSame( 'mencoro_sk_pasted_1234', AIVD_Settings::api_key() );
	}

	public function test_save_rejects_something_that_cannot_be_a_key() {
		$this->post_save( array( 'aivd_api_key' => 'short' ) );
		$this->run_redirecting( array( 'AIVD_Admin', 'handle_save' ) );
		$this->assertSame( '', AIVD_Settings::api_key() );
		$notice = get_transient( 'aivd_notice_' . get_current_user_id() );
		$this->assertSame( 'error', $notice['type'] );
		$this->assertSame( 0, $this->count_requests( '/api/v1/me' ) );
	}

	public function test_save_validates_project_ids_and_engines() {
		$this->configure();
		$this->routes['/api/v1/me'] = self::json( array( 'userId' => 'u1' ) );
		$this->post_save(
			array(
				'aivd_project'  => '../../etc|passwd',
				'aivd_engines'  => array( 'chatgpt', 'google_serp', '<script>' ),
				'aivd_range'    => '365',
				'aivd_audience' => 'everyone',
			)
		);
		$this->run_redirecting( array( 'AIVD_Admin', 'handle_save' ) );
		$settings = AIVD_Settings::all();
		$this->assertSame( self::PROJECT, $settings['project_id'], 'An invalid project is ignored.' );
		$this->assertSame( array( 'chatgpt' ), $settings['engines'] );
		$this->assertSame( 28, $settings['range'] );
		$this->assertSame( 'administrators', $settings['audience'] );
	}

	public function test_save_never_stores_the_identity_returned_by_me() {
		$this->routes['/api/v1/me'] = self::json(
			array(
				'userId'   => 'u1',
				'email'    => 'owner@example.org',
				'fullName' => 'Key Owner',
			)
		);
		$this->post_save( array( 'aivd_api_key' => self::KEY ) );
		$this->run_redirecting( array( 'AIVD_Admin', 'handle_save' ) );

		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s", '%' . $wpdb->esc_like( 'owner@example.org' ) . '%' ) );
		$this->assertSame( '0', (string) $found );
	}

	public function test_refresh_by_an_allowed_editor_is_rate_limited() {
		$this->configure( array( 'audience' => 'editors' ) );
		$this->mock_project();
		AIVD_Data::state( 28 );
		$this->requests = array();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$_GET['aivd_range']   = '28';
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'aivd_refresh' );
		$_GET['_wpnonce']     = $_REQUEST['_wpnonce'];

		$location = $this->run_redirecting( array( 'AIVD_Admin', 'handle_refresh' ) );
		$this->assertStringContainsString( 'index.php?aivd_range=28', $location );
		$this->assertSame( 0, $this->count_requests(), 'Updated seconds ago: no new request.' );
		$this->assertIsString( get_transient( 'aivd_widget_notice_' . get_current_user_id() ) );
	}

	public function test_settings_page_shows_status_without_the_key() {
		$this->configure();
		$this->mock_project();
		$this->routes['/api/v1/organizations'] = self::json( array( 'items' => array() ) );
		AIVD_Data::state( 28 );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		AIVD_Admin::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'aivd-status-table', $html );
		$this->assertStringNotContainsString( self::KEY, $html );
		$this->assertStringContainsString( 'cdef', $html, 'Only the last four characters are shown.' );
	}
}
