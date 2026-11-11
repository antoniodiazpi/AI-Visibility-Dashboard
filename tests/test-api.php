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
}
