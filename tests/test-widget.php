<?php
/**
 * Widget rendering tests.
 *
 * @package AIVisibilityDashboard
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Test code.

/**
 * AIVD_Widget.
 */
class Test_AIVD_Widget extends AIVD_Test_Case {

	/**
	 * Rendered widget HTML.
	 *
	 * @return string
	 */
	private function render() {
		ob_start();
		AIVD_Widget::render();
		return (string) ob_get_clean();
	}

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_setup_message_when_not_configured() {
		$html = $this->render();
		$this->assertStringContainsString( 'aivd-state--setup', $html );
		$this->assertSame( 0, $this->count_requests() );
	}

	public function test_api_values_are_escaped() {
		$this->configure();
		$this->mock_project();
		$html = $this->render();
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringNotContainsString( '<b>Coffee</b>', $html );
	}

	public function test_api_key_never_reaches_the_html() {
		$this->configure();
		$this->mock_project();
		$this->assertStringNotContainsString( self::KEY, $this->render() );
	}

	public function test_error_without_data_shows_message_and_retry() {
		$this->configure();
		$this->mock_project( array( '' => self::json( array(), 401 ) ) );
		$html = $this->render();
		$this->assertStringContainsString( 'aivd-state--error', $html );
		$this->assertStringContainsString( 'action=aivd_refresh', $html );
	}

	public function test_editor_cannot_see_widget_by_default() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertFalse( current_user_can( AIVD_Settings::widget_capability() ) );
		AIVD_Settings::update( array( 'audience' => 'editors' ) );
		$this->assertTrue( current_user_can( AIVD_Settings::widget_capability() ) );
	}

	public function test_stale_data_is_labelled_with_its_age() {
		$this->configure();
		$this->mock_project();
		AIVD_Data::state( 28 );
		foreach ( array_keys( (array) get_option( AIVD_Data::INDEX_OPTION ) ) as $id ) {
			$snapshot                = get_option( AIVD_Data::SNAPSHOT_PREFIX . $id );
			$snapshot['fetched_at'] -= 8 * HOUR_IN_SECONDS;
			update_option( AIVD_Data::SNAPSHOT_PREFIX . $id, $snapshot, false );
		}
		$html = $this->render();
		$this->assertStringContainsString( 'aivd-status--stale', $html );
		$this->assertStringContainsString( 'Last data available', $html );
		$this->assertStringContainsString( 'aivd-kpis', $html, 'The figures are still shown.' );
	}

	public function test_pending_state_explains_what_is_happening() {
		$this->configure();
		$this->mock_project();
		$context = new ReflectionMethod( 'AIVD_Data', 'context' );
		$context->setAccessible( true );
		$lock = 'refresh_' . $context->invoke( null, 28 )['id'];
		AIVD_Lock::acquire( $lock, 60 );
		$html = $this->render();
		AIVD_Lock::release( $lock );
		$this->assertStringContainsString( 'on its way', $html );
		$this->assertStringNotContainsString( 'aivd-state--error', $html );
	}

	public function test_error_shows_next_automatic_attempt() {
		$this->configure();
		$this->mock_project( array( '' => self::json( array(), 503 ) ) );
		$html = $this->render();
		$this->assertStringContainsString( 'aivd-state--error', $html );
		$this->assertStringContainsString( 'Next automatic attempt', $html );
	}

	public function test_sample_and_methodology_are_stated() {
		$this->configure();
		$this->mock_project();
		$html = $this->render();
		$this->assertStringContainsString( 'aivd-sample', $html );
		$this->assertStringContainsString( '25 tracked queries', $html );
		$this->assertStringContainsString( '(UTC)', $html );
		$this->assertStringContainsString( 'The 2 most cited of 48 sources', $html );
	}

	/**
	 * Regression: 1.0.0 called the cited sites "the sites the AI trusts" and
	 * suggested getting mentioned on them, which the data cannot support.
	 */
	public function test_missing_own_domain_is_not_overstated() {
		$this->configure();
		$this->mock_project();
		$html = $this->render();
		$this->assertStringNotContainsString( 'trusts', $html );
		$this->assertStringNotContainsString( 'fastest way', $html );
		$this->assertStringContainsString( 'does not mean it is never cited', $html );
	}

	public function test_nothing_is_added_to_the_front_end() {
		$this->configure();
		set_current_screen( 'front' );
		$this->assertFalse( has_action( 'wp_enqueue_scripts' ) && has_action( 'wp_head', array( 'AIVD_Widget', 'render' ) ) );
		do_action( 'wp_enqueue_scripts' );
		$this->assertFalse( wp_style_is( 'aivd-admin', 'enqueued' ) );
		$this->assertSame( 0, $this->count_requests() );
	}

	public function test_stylesheet_loads_only_on_dashboard_and_settings() {
		AIVD_Admin::assets( 'edit.php' );
		$this->assertFalse( wp_style_is( 'aivd-admin', 'enqueued' ) );
		AIVD_Admin::assets( 'index.php' );
		$this->assertTrue( wp_style_is( 'aivd-admin', 'enqueued' ) );
		wp_dequeue_style( 'aivd-admin' );
	}
}
