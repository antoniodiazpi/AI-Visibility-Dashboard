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
}
