<?php
/**
 * API client tests.
 *
 * @package AIVisibilityDashboard
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Test code.

/**
 * AIVD_API.
 */
class Test_AIVD_API extends AIVD_Test_Case {

	public function test_no_key_means_no_request() {
		$result = ( new AIVD_API( '' ) )->get( '/api/v1/me' );
		$this->assertWPError( $result );
		$this->assertSame( 'aivd_no_key', $result->get_error_code() );
		$this->assertSame( 0, $this->count_requests() );
	}

	public function test_get_sends_bearer_key_and_encodes_query() {
		$this->routes['/api/v1/me'] = self::json( array( 'userId' => 'u1' ) );

		$result = ( new AIVD_API( self::KEY ) )->get(
			'/api/v1/me',
			array(
				'engines' => array( 'chatgpt', 'claude' ),
				'empty'   => array(),
				'flag'    => true,
			)
		);

		$this->assertSame( array( 'userId' => 'u1' ), $result );
		$request = $this->requests[0];
		$this->assertStringStartsWith( 'https://api.mencoro.com/api/v1/me?', $request['url'] );
		$this->assertSame( 'chatgpt,claude', $request['query']['engines'] );
		$this->assertSame( 'true', $request['query']['flag'] );
		$this->assertArrayNotHasKey( 'empty', $request['query'] );
		$this->assertSame( 'Bearer ' . self::KEY, $request['args']['headers']['Authorization'] );
		$this->assertStringNotContainsString( self::KEY, $request['url'], 'The key never travels in the URL.' );
	}

	/**
	 * @dataProvider status_codes
	 *
	 * @param int    $status HTTP status.
	 * @param string $code   Expected error code.
	 */
	public function test_http_errors_become_wp_errors( $status, $code ) {
		$this->routes['/api/v1/me'] = self::json( array( 'message' => 'x' ), $status );
		$result                     = ( new AIVD_API( self::KEY ) )->get( '/api/v1/me' );
		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
	}

	public function status_codes() {
		return array(
			array( 401, 'aivd_http_401' ),
			array( 403, 'aivd_http_403' ),
			array( 404, 'aivd_http_404' ),
			array( 429, 'aivd_http_429' ),
			array( 500, 'aivd_http_500' ),
			array( 503, 'aivd_http_503' ),
		);
	}

	public function test_invalid_json_is_an_error() {
		$this->routes['/api/v1/me'] = self::json( '<html>proxy</html>' );
		$result                     = ( new AIVD_API( self::KEY ) )->get( '/api/v1/me' );
		$this->assertWPError( $result );
		$this->assertSame( 'aivd_bad_json', $result->get_error_code() );
	}

	public function test_transport_error_is_wrapped() {
		$this->routes['/api/v1/me'] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		$result                     = ( new AIVD_API( self::KEY ) )->get( '/api/v1/me' );
		$this->assertWPError( $result );
		$this->assertSame( 'aivd_http', $result->get_error_code() );
	}

	public function test_429_mentions_retry_after() {
		$this->routes['/api/v1/me'] = self::json( array(), 429, array( 'retry-after' => '42' ) );
		$result                     = ( new AIVD_API( self::KEY ) )->get( '/api/v1/me' );
		$this->assertStringContainsString( '42', $result->get_error_message() );
	}

	public function test_all_projects_lists_projects_of_every_organization() {
		$this->routes['/api/v1/organizations']                              = self::json(
			array(
				'items' => array(
					array(
						'id'   => self::ORG,
						'name' => 'Agency',
					),
					array( 'name' => 'No id, skipped' ),
				),
			)
		);
		$this->routes[ '/api/v1/organizations/' . self::ORG . '/projects' ] = self::json(
			array(
				'items' => array(
					array(
						'id'   => self::PROJECT,
						'name' => 'Client',
					),
				),
			)
		);

		$projects = ( new AIVD_API( self::KEY ) )->all_projects();
		$this->assertSame(
			array(
				array(
					'organization_id'   => self::ORG,
					'organization_name' => 'Agency',
					'project_id'        => self::PROJECT,
					'project_name'      => 'Client',
				),
			),
			$projects
		);
	}

	/**
	 * Regression: 1.0.0 appended the provider's message to errors shown to editors.
	 */
	public function test_error_messages_never_copy_the_response_body() {
		$this->routes['/api/v1/me'] = self::json( array( 'message' => 'SQLSTATE internal-db-7 user=admin@example.org' ), 403 );
		$result                     = ( new AIVD_API( self::KEY ) )->get( '/api/v1/me' );
		$this->assertStringNotContainsString( 'SQLSTATE', $result->get_error_message() );
		$this->assertStringNotContainsString( 'admin@example.org', $result->get_error_message() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_requests_do_not_follow_redirects_and_cap_size_and_time() {
		$this->routes['/api/v1/me'] = self::json( array( 'ok' => true ) );
		( new AIVD_API( self::KEY ) )->get( '/api/v1/me' );
		$args = $this->requests[0]['args'];
		$this->assertSame( 0, $args['redirection'], 'A redirect could hand the key to another host.' );
		$this->assertSame( AIVD_API::MAX_RESPONSE_BYTES, $args['limit_response_size'] );
		$this->assertLessThanOrEqual( 30, $args['timeout'] );
		$this->assertSame( 'application/json', $args['headers']['Accept'] );
	}

	public function test_redirect_answer_is_an_error() {
		$this->routes['/api/v1/me'] = self::json( '', 302, array( 'location' => 'https://evil.example/' ) );
		$result                     = ( new AIVD_API( self::KEY ) )->get( '/api/v1/me' );
		$this->assertWPError( $result );
		$this->assertSame( 'aivd_http_302', $result->get_error_code() );
	}

	public function test_retry_after_is_exposed_and_capped() {
		$this->routes['/api/v1/me'] = self::json( array(), 429, array( 'retry-after' => '99999' ) );
		$result                     = ( new AIVD_API( self::KEY ) )->get( '/api/v1/me' );
		$this->assertSame( HOUR_IN_SECONDS, $result->get_error_data()['retry_after'] );

		$this->routes['/api/v1/me'] = self::json( array(), 429, array( 'retry-after' => gmdate( 'D, d M Y H:i:s', time() + 90 ) . ' GMT' ) );
		$result                     = ( new AIVD_API( self::KEY ) )->get( '/api/v1/me' );
		$this->assertEqualsWithDelta( 90, $result->get_error_data()['retry_after'], 2 );
	}

	public function test_timeout_gets_a_specific_message() {
		$this->routes['/api/v1/me'] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 10001 milliseconds' );
		$result                     = ( new AIVD_API( self::KEY ) )->get( '/api/v1/me' );
		$this->assertStringContainsString( 'did not answer in time', $result->get_error_message() );
		$this->assertSame( 0, $result->get_error_data()['status'] );
	}

	/**
	 * @dataProvider base_urls
	 *
	 * @param string $base     Custom base.
	 * @param string $expected Base used.
	 */
	public function test_custom_base_url_must_be_safe( $base, $expected ) {
		$filter = static function () use ( $base ) {
			return $base;
		};
		add_filter( 'aivd_api_base', $filter );
		$this->assertSame( $expected, AIVD_API::base_url() );
		remove_filter( 'aivd_api_base', $filter );
	}

	public function base_urls() {
		return array(
			'https mock'          => array( 'https://mock.example/', 'https://mock.example' ),
			'local http mock'     => array( 'http://127.0.0.1:8898', 'http://127.0.0.1:8898' ),
			'localhost http mock' => array( 'http://localhost:8898', 'http://localhost:8898' ),
			'remote plain http'   => array( 'http://api.example.com', AIVD_API::DEFAULT_BASE ),
			'credentials in url'  => array( 'https://user:pass@api.example.com', AIVD_API::DEFAULT_BASE ),
			'not a url'           => array( 'javascript:alert(1)', AIVD_API::DEFAULT_BASE ),
			'empty'               => array( '', AIVD_API::DEFAULT_BASE ),
		);
	}

	public function test_malformed_project_lists_are_skipped() {
		$this->routes['/api/v1/organizations']                              = self::json(
			array(
				'items' => array(
					'oops',
					array( 'id' => array() ),
					array(
						'id'   => self::ORG,
						'name' => array(),
					),
				),
			)
		);
		$this->routes[ '/api/v1/organizations/' . self::ORG . '/projects' ] = self::json( array( 'items' => array( 'oops', array( 'id' => self::PROJECT ) ) ) );

		$projects = ( new AIVD_API( self::KEY ) )->all_projects();
		$this->assertCount( 1, $projects );
		$this->assertSame( '', $projects[0]['organization_name'] );
	}
}
