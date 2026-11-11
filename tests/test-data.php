<?php
/**
 * Data layer tests.
 *
 * @package AIVisibilityDashboard
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Test code.

/**
 * AIVD_Data.
 */
class Test_AIVD_Data extends AIVD_Test_Case {

	public function set_up() {
		parent::set_up();
		$this->configure();
		$this->mock_project();
	}

	public function test_builds_the_payload_from_seven_requests() {
		$data = AIVD_Data::get( 28 );

		$this->assertIsArray( $data );
		$this->assertSame( 7, $this->count_requests() );
		$this->assertSame( array( 'chatgpt', 'perplexity' ), $data['engines'], 'Only AI engines the project tracks.' );
		$this->assertSame( 31.4, $data['kpis']['share_of_voice']['value'] );
		$this->assertNull( $data['kpis']['positivity']['trend'], 'A missing trend stays null, never zero.' );
		$this->assertSame( 25, $data['counts']['queries'] );
		$this->assertSame( 'Beanly <script>alert(1)</script>', $data['competitors'][0]['name'], 'Raw data; escaping happens on output.' );
		$this->assertTrue( $data['competitors'][1]['is_brand'] );
		$this->assertNull( $data['series'][1]['value'] );
		$this->assertFalse( $data['sources'][0]['is_own'] );
		$this->assertSame( 'best coffee beans', $data['movers_up'][0]['query'] );
		$this->assertSame( -8.0, $data['movers_down'][0]['trend'] );
	}

	public function test_sends_only_tracked_ai_engines_and_utc_window() {
		AIVD_Data::get( 7 );
		foreach ( $this->requests as $request ) {
			if ( isset( $request['query']['engines'] ) ) {
				$this->assertSame( 'chatgpt,perplexity', $request['query']['engines'] );
				$this->assertSame( gmdate( 'Y-m-d' ), $request['query']['dateTo'] );
				$this->assertSame( gmdate( 'Y-m-d', time() - 6 * DAY_IN_SECONDS ), $request['query']['dateFrom'] );
			}
		}
	}

	public function test_second_call_is_served_from_cache() {
		AIVD_Data::get( 28 );
		AIVD_Data::get( 28 );
		$this->assertSame( 7, $this->count_requests() );
	}

	public function test_project_without_ai_engines_stops_after_two_requests() {
		$this->mock_project( array( '/available-filters' => self::json( array( 'engines' => array( 'google_serp' ) ) ) ) );
		$data = AIVD_Data::get( 28 );
		$this->assertTrue( $data['no_ai_engine'] );
		$this->assertFalse( $data['no_selected'] );
		$this->assertSame( 2, $this->count_requests() );
	}

	public function test_failing_accessory_endpoint_only_affects_its_section() {
		$this->mock_project( array( '/cited-sources' => self::json( array(), 500 ) ) );
		$data = AIVD_Data::get( 28 );
		$this->assertIsArray( $data );
		$this->assertIsString( $data['sources'] );
		$this->assertIsArray( $data['series'] );
	}

	public function test_failing_metrics_is_an_error() {
		$this->mock_project( array( '/metrics' => self::json( array(), 500 ) ) );
		$this->assertWPError( AIVD_Data::get( 28 ) );
	}

	public function test_own_domain_is_flagged_in_sources() {
		$this->mock_project(
			array(
				'/cited-sources' => self::json(
					array(
						'sources' => array(
							array(
								'domain'        => 'www.acme.example',
								'citationCount' => 3,
							),
						),
					)
				),
			)
		);
		$data = AIVD_Data::get( 28 );
		$this->assertTrue( $data['sources'][0]['is_own'] );
	}
}
