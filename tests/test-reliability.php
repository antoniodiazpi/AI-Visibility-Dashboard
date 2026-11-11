<?php
/**
 * Reliability tests: last valid data, background updates, locks and backoff.
 *
 * @package AIVisibilityDashboard
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Test code.

/**
 * AIVD_Data states and AIVD_Lock.
 */
class Test_AIVD_Reliability extends AIVD_Test_Case {

	public function set_up() {
		parent::set_up();
		$this->configure();
		$this->mock_project();
		_set_cron_array( array() );
	}

	/**
	 * Every stored snapshot.
	 *
	 * @return array Option name => snapshot.
	 */
	private function snapshots() {
		$out = array();
		foreach ( array_keys( (array) get_option( AIVD_Data::INDEX_OPTION, array() ) ) as $id ) {
			$out[ AIVD_Data::SNAPSHOT_PREFIX . $id ] = get_option( AIVD_Data::SNAPSHOT_PREFIX . $id );
		}
		return $out;
	}

	/**
	 * Moves every snapshot back in time.
	 *
	 * @param int $seconds Seconds.
	 */
	private function age_snapshots( $seconds ) {
		foreach ( $this->snapshots() as $name => $snapshot ) {
			$snapshot['fetched_at']   -= $seconds;
			$snapshot['attempted_at'] -= $seconds;
			update_option( $name, $snapshot, false );
		}
	}

	/**
	 * Replaces every endpoint with one answer.
	 *
	 * @param array|WP_Error $response Response.
	 */
	private function break_api( $response ) {
		foreach ( array_keys( $this->routes ) as $path ) {
			$this->routes[ $path ] = $response;
		}
	}

	public function test_first_view_fetches_and_reports_fresh() {
		$state = AIVD_Data::state( 28 );
		$this->assertSame( 'fresh', $state['status'] );
		$this->assertFalse( $state['partial'] );
		$this->assertSame( 7, $this->count_requests() );
		$this->assertGreaterThan( time(), $state['expires_at'] );
	}

	public function test_snapshot_is_stored_without_autoload_and_without_the_key() {
		AIVD_Data::state( 28 );
		global $wpdb;
		$snapshots = $this->snapshots();
		$this->assertCount( 1, $snapshots );
		$name     = key( $snapshots );
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		$this->assertContains( $autoload, array( 'no', 'off' ) );
		$this->assertStringNotContainsString( self::KEY, maybe_serialize( current( $snapshots ) ) );
		$this->assertStringNotContainsString( self::KEY, (string) $name );
	}

	/**
	 * Regression: in 1.0.0 an expired cache plus a Mencoro outage emptied the widget.
	 */
	public function test_outage_after_expiry_keeps_last_valid_data() {
		AIVD_Data::state( 28 );
		$this->age_snapshots( 7 * HOUR_IN_SECONDS );
		$this->break_api( self::json( array(), 503 ) );
		$this->requests = array();

		$state = AIVD_Data::state( 28 );
		$this->assertSame( 'stale', $state['status'] );
		$this->assertSame( 31.4, $state['payload']['kpis']['share_of_voice']['value'] );
		$this->assertSame( 0, $this->count_requests( '/metrics' ), 'Out-of-date data is shown at once; the update runs in the background.' );
		$this->assertNotFalse( wp_next_scheduled( AIVD_Data::CRON_HOOK, array( 28 ) ) );

		// The background update fails too: the data stays, the error is recorded.
		AIVD_Data::run_scheduled_refresh( 28 );
		$state = AIVD_Data::state( 28 );
		$this->assertSame( 'stale', $state['status'] );
		$this->assertSame( 31.4, $state['payload']['kpis']['share_of_voice']['value'] );
		$this->assertWPError( $state['error'] );
		$this->assertSame( 'aivd_http_503', $state['error']->get_error_code() );
		$this->assertGreaterThan( time(), $state['retry_at'] );

		// Backward-compatible API: get() still returns the last payload, not the error.
		$this->assertIsArray( AIVD_Data::get( 28 ) );
	}

	public function test_background_refresh_replaces_stale_data() {
		AIVD_Data::state( 28 );
		$this->age_snapshots( 7 * HOUR_IN_SECONDS );
		$this->assertSame( 'stale', AIVD_Data::state( 28 )['status'] );

		AIVD_Data::run_scheduled_refresh( 28 );
		$state = AIVD_Data::state( 28 );
		$this->assertSame( 'fresh', $state['status'] );
		$this->assertFalse( wp_next_scheduled( AIVD_Data::CRON_HOOK, array( 28 ) ) );
	}

	public function test_scheduled_refresh_does_nothing_when_data_is_fresh() {
		AIVD_Data::state( 28 );
		$this->requests = array();
		AIVD_Data::run_scheduled_refresh( 28 );
		$this->assertSame( 0, $this->count_requests() );
	}

	public function test_new_utc_day_makes_data_stale() {
		AIVD_Data::state( 7 );
		foreach ( $this->snapshots() as $name => $snapshot ) {
			$snapshot['payload']['date_to'] = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
			update_option( $name, $snapshot, false );
		}
		$this->assertSame( 'stale', AIVD_Data::state( 7 )['status'] );
	}

	public function test_429_waits_for_retry_after_everywhere() {
		$this->break_api( self::json( array(), 429, array( 'retry-after' => '120' ) ) );

		$state = AIVD_Data::state( 28 );
		$this->assertSame( 'error', $state['status'] );
		$this->assertEqualsWithDelta( time() + 120, $state['retry_at'], 2 );
		$this->assertSame( 1, $this->count_requests() );

		$this->requests = array();
		$this->assertSame( 'error', AIVD_Data::state( 28 )['status'] );
		AIVD_Data::run_scheduled_refresh( 28 );
		$this->assertSame( 'rate_limited', AIVD_Data::refresh_now( 28 ) );
		$this->assertSame( 0, $this->count_requests(), 'Nothing is sent before Retry-After.' );
	}

	public function test_server_errors_back_off_exponentially() {
		$this->break_api( self::json( array(), 500 ) );

		$first = AIVD_Data::state( 28 );
		$this->assertEqualsWithDelta( time() + MINUTE_IN_SECONDS, $first['retry_at'], 2 );

		// Pretend the wait is over and fail again.
		foreach ( $this->snapshots() as $name => $snapshot ) {
			$snapshot['retry_at']      = time() - 1;
			$snapshot['attempted_at'] -= HOUR_IN_SECONDS;
			update_option( $name, $snapshot, false );
		}
		$second = AIVD_Data::state( 28 );
		$this->assertEqualsWithDelta( time() + 2 * MINUTE_IN_SECONDS, $second['retry_at'], 2 );
	}

	public function test_manual_refresh_is_rate_limited() {
		AIVD_Data::state( 28 );
		$this->requests = array();
		$this->assertSame( 'too_soon', AIVD_Data::refresh_now( 28 ) );
		$this->assertSame( 0, $this->count_requests() );

		$this->age_snapshots( MINUTE_IN_SECONDS );
		$this->assertSame( 'updated', AIVD_Data::refresh_now( 28 ) );
		$this->assertSame( 7, $this->count_requests() );
	}

	public function test_manual_refresh_can_retry_after_an_auth_error() {
		$this->mock_project( array( '' => self::json( array(), 401 ) ) );
		$this->assertSame( 'error', AIVD_Data::state( 28 )['status'] );
		$this->age_snapshots( MINUTE_IN_SECONDS );

		$this->mock_project();
		$this->assertSame( 'updated', AIVD_Data::refresh_now( 28 ) );
		$this->assertSame( 'fresh', AIVD_Data::state( 28 )['status'] );
	}

	public function test_concurrent_request_does_not_fetch_twice() {
		// Another request is fetching the first data right now.
		$context = new ReflectionMethod( 'AIVD_Data', 'context' );
		$context->setAccessible( true );
		$lock = 'refresh_' . $context->invoke( null, 28 )['id'];
		$this->assertTrue( AIVD_Lock::acquire( $lock, 60 ) );

		$state = AIVD_Data::state( 28 );
		$this->assertSame( 'pending', $state['status'] );
		$this->assertNull( $state['payload'] );
		$this->assertSame( 'running', AIVD_Data::refresh_now( 28 ) );
		$this->assertSame( 0, $this->count_requests() );

		AIVD_Lock::release( $lock );
		$this->assertSame( 'fresh', AIVD_Data::state( 28 )['status'] );
	}

	public function test_lock_is_exclusive_and_expires() {
		$this->assertTrue( AIVD_Lock::acquire( 'unit', 60 ) );
		$this->assertFalse( AIVD_Lock::acquire( 'unit', 60 ) );
		$this->assertTrue( AIVD_Lock::is_locked( 'unit' ) );

		AIVD_Lock::release( 'unit' );
		$this->assertFalse( AIVD_Lock::is_locked( 'unit' ) );
		$this->assertTrue( AIVD_Lock::acquire( 'unit', 60 ) );

		// A lock left by a request that died can be taken over once it expires.
		global $wpdb;
		$wpdb->update( $wpdb->options, array( 'option_value' => (string) ( time() - 1 ) ), array( 'option_name' => 'aivd_lock_unit' ) );
		$this->assertTrue( AIVD_Lock::acquire( 'unit', 60 ) );
		$this->assertFalse( AIVD_Lock::acquire( 'unit', 60 ) );
		AIVD_Lock::release( 'unit' );
	}

	public function test_lock_ignores_a_stale_object_cache() {
		// A cached "no such option" must not make a held lock look free.
		wp_cache_set( 'notoptions', array( 'aivd_lock_cache' => true ), 'options' );
		$this->assertTrue( AIVD_Lock::acquire( 'cache', 60 ) );
		$this->assertFalse( AIVD_Lock::acquire( 'cache', 60 ) );
		AIVD_Lock::release( 'cache' );
	}

	public function test_failing_secondary_section_reports_partial() {
		$this->mock_project( array( '/cited-sources' => self::json( array(), 500 ) ) );
		$state = AIVD_Data::state( 28 );
		$this->assertSame( 'partial', $state['status'] );
		$this->assertTrue( $state['partial'] );
		$this->assertSame( array( 'sources' ), $state['payload']['failed_sections'] );
	}

	public function test_slow_api_skips_secondary_sections_within_budget() {
		$budget = static function () {
			return 5;
		};
		add_filter( 'aivd_refresh_budget', $budget );
		$metrics                                  = $this->routes[ self::path( '/metrics' ) ];
		$this->routes[ self::path( '/metrics' ) ] = static function () use ( $metrics ) {
			usleep( 3200000 );
			return $metrics;
		};

		$state = AIVD_Data::state( 28 );
		remove_filter( 'aivd_refresh_budget', $budget );

		$this->assertSame( 'partial', $state['status'] );
		$this->assertSame( 3, $this->count_requests(), 'Only the essential requests were sent.' );
		$this->assertSame( array( 'series', 'sources', 'movers_up', 'movers_down' ), $state['payload']['failed_sections'] );
	}

	public function test_no_observations_reports_empty() {
		$this->mock_project(
			array(
				'/metrics' => self::json(
					array(
						'shareOfVoice' => null,
						'mentionRate'  => null,
					)
				),
			)
		);
		$this->assertSame( 'empty', AIVD_Data::state( 28 )['status'] );
	}

	public function test_project_change_never_shows_previous_project() {
		AIVD_Data::state( 28 );
		$this->assertCount( 1, $this->snapshots() );

		// What saving the settings does.
		AIVD_Settings::update( array( 'project_id' => '33333333-3333-4333-8333-333333333333' ) );
		AIVD_Settings::flush_cache();
		$this->assertCount( 0, $this->snapshots(), 'Data of the previous project is deleted.' );

		$this->routes = array();
		$state        = AIVD_Data::state( 28 );
		$this->assertSame( 'error', $state['status'] );
		$this->assertNull( $state['payload'] );
	}

	public function test_rotated_key_does_not_reuse_data() {
		AIVD_Data::state( 28 );
		$this->requests = array();
		AIVD_Settings::update( array( 'api_key' => 'mencoro_sk_rotated_key_99999999' ) );
		AIVD_Data::state( 28 );
		$this->assertSame( 7, $this->count_requests() );
		$this->assertSame( 'Bearer mencoro_sk_rotated_key_99999999', end( $this->requests )['args']['headers']['Authorization'] );
	}

	public function test_snapshots_are_capped() {
		$engines = array( array( 'chatgpt' ), array( 'perplexity' ), array( 'chatgpt', 'perplexity' ) );
		foreach ( $engines as $choice ) {
			AIVD_Settings::update( array( 'engines' => $choice ) );
			foreach ( AIVD_Settings::RANGES as $range ) {
				AIVD_Data::state( $range );
			}
		}
		$this->assertCount( AIVD_Data::MAX_SNAPSHOTS, $this->snapshots() );
	}

	public function test_purge_removes_snapshots_and_scheduled_updates() {
		AIVD_Data::state( 28 );
		$this->age_snapshots( 7 * HOUR_IN_SECONDS );
		AIVD_Data::state( 28 );
		$this->assertNotFalse( wp_next_scheduled( AIVD_Data::CRON_HOOK, array( 28 ) ) );

		AIVD_Data::purge();
		$this->assertSame( array(), $this->snapshots() );
		$this->assertFalse( get_option( AIVD_Data::INDEX_OPTION ) );
		$this->assertFalse( wp_next_scheduled( AIVD_Data::CRON_HOOK, array( 28 ) ) );
	}

	public function test_stuck_cron_is_reported() {
		AIVD_Data::state( 28 );
		$this->age_snapshots( 7 * HOUR_IN_SECONDS );
		wp_schedule_single_event( time() - HOUR_IN_SECONDS, AIVD_Data::CRON_HOOK, array( 28 ) );
		$state = AIVD_Data::state( 28 );
		$this->assertSame( 'stale', $state['status'] );
		$this->assertTrue( $state['cron_late'] );
	}

	/**
	 * Malformed answers must never raise PHP notices (the suite turns them into failures).
	 */
	public function test_malformed_answers_are_tolerated() {
		$this->mock_project(
			array(
				''                   => self::json(
					array(
						'name'           => array( 'not', 'a', 'string' ),
						'brandNames'     => 'Acme',
						'websiteDomains' => array( array( 'x' ), 'acme.example' ),
						'competitors'    => array( 'oops', array( 'id' => array() ) ),
					)
				),
				'/available-filters' => self::json( array( 'engines' => array( 'chatgpt', array( 'x' ) ) ) ),
				'/metrics'           => self::json(
					array(
						'shareOfVoice'           => '12.5',
						'mentionRate'            => 'n/a',
						'mentionCount'           => array(),
						'dataDirtySince'         => array( 'x' ),
						'competitorShareOfVoice' => array(
							'oops',
							array(
								'competitorId' => array(),
								'shareOfVoice' => 3,
							),
						),
					)
				),
				'/timeseries'        => self::json( array( 'points' => array( 'oops', array( 'brand' => 'x' ) ) ) ),
				'/cited-sources'     => self::json(
					array(
						'sources' => array(
							'oops',
							array( 'domain' => array( 'x' ) ),
							array(
								'domain'        => 'ok.example',
								'citationCount' => 'many',
							),
						),
					)
				),
				'/metrics/movers'    => self::json( array( 'rows' => 'oops' ) ),
			)
		);

		$state = AIVD_Data::state( 28 );
		$this->assertSame( 'fresh', $state['status'] );
		$data = $state['payload'];
		$this->assertSame( '', $data['project']['name'] );
		$this->assertSame( array( 'acme.example' ), $data['project']['domains'] );
		$this->assertSame( 12.5, $data['kpis']['share_of_voice']['value'] );
		$this->assertNull( $data['kpis']['mention_rate']['value'] );
		$this->assertSame( 0, $data['counts']['mentions'] );
		$this->assertSame( '', $data['dirty_since'] );
		$this->assertSame( array( 'chatgpt' ), $data['engines'] );
		$this->assertSame( array( 'ok.example' ), wp_list_pluck( $data['sources'], 'domain' ) );
		$this->assertSame( 0, $data['sources'][0]['citations'] );
		$this->assertSame( array(), $data['movers_up'] );

		// And the widget renders it without notices either.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		AIVD_Widget::render();
		$this->assertStringContainsString( 'ok.example', (string) ob_get_clean() );
	}

	public function test_deactivation_unschedules_updates() {
		wp_schedule_single_event( time(), AIVD_Data::CRON_HOOK, array( 28 ) );
		aivd_deactivate();
		$this->assertFalse( wp_next_scheduled( AIVD_Data::CRON_HOOK, array( 28 ) ) );
	}
}
