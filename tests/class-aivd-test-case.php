<?php
/**
 * Base test case: a fake Mencoro API behind the WordPress HTTP API.
 *
 * @package AIVisibilityDashboard
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Test code.

/**
 * Every outgoing request is answered by $this->routes through the
 * pre_http_request filter, so no test ever reaches the network.
 */
abstract class AIVD_Test_Case extends WP_UnitTestCase {

	const ORG     = '11111111-1111-4111-8111-111111111111';
	const PROJECT = '22222222-2222-4222-8222-222222222222';
	const KEY     = 'mencoro_sk_test_0123456789abcdef';

	/**
	 * Path => response array, WP_Error or callable( $query, $args ) returning one of them.
	 *
	 * @var array
	 */
	protected $routes = array();

	/**
	 * Requests seen, in order: url, path, query, args.
	 *
	 * @var array
	 */
	protected $requests = array();

	/**
	 * Resets the fake API and the plugin state.
	 */
	public function set_up() {
		parent::set_up();
		$this->routes   = array();
		$this->requests = array();
		putenv( 'MENCORO_API_KEY' );
		add_filter( 'pre_http_request', array( $this, 'mock_http' ), 10, 3 );
	}

	/**
	 * Removes the fake API.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'mock_http' ), 10 );
		putenv( 'MENCORO_API_KEY' );
		parent::tear_down();
	}

	/**
	 * Answers a request from $this->routes.
	 *
	 * @param false|array|WP_Error $pre  Short-circuit value.
	 * @param array                $args Request arguments.
	 * @param string               $url  URL.
	 * @return array|WP_Error
	 */
	public function mock_http( $pre, $args, $url ) {
		$parts = wp_parse_url( $url );
		$path  = isset( $parts['path'] ) ? $parts['path'] : '';
		$query = array();
		if ( isset( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
		}
		$this->requests[] = array(
			'url'   => $url,
			'path'  => $path,
			'query' => $query,
			'args'  => $args,
		);

		if ( ! isset( $this->routes[ $path ] ) ) {
			return self::json( array( 'message' => 'Route not mocked: ' . $path ), 404 );
		}
		$route = $this->routes[ $path ];
		if ( is_callable( $route ) ) {
			$route = call_user_func( $route, $query, $args );
		}
		return $route;
	}

	/**
	 * A JSON HTTP response.
	 *
	 * @param mixed $body    Body, encoded unless it is already a string.
	 * @param int   $status  Status code.
	 * @param array $headers Lowercase header names.
	 * @return array
	 */
	protected static function json( $body, $status = 200, array $headers = array() ) {
		return array(
			'headers'  => $headers,
			'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Project path on the fake API.
	 *
	 * @param string $suffix Path after the project.
	 * @return string
	 */
	protected static function path( $suffix = '' ) {
		return '/api/v1/organizations/' . self::ORG . '/projects/' . self::PROJECT . $suffix;
	}

	/**
	 * Saves a working configuration.
	 *
	 * @param array $overrides Settings to change.
	 */
	protected function configure( array $overrides = array() ) {
		AIVD_Settings::update(
			array_merge(
				array(
					'api_key'         => self::KEY,
					'organization_id' => self::ORG,
					'project_id'      => self::PROJECT,
				),
				$overrides
			)
		);
	}

	/**
	 * Routes for a healthy project with data.
	 *
	 * @param array $overrides Path suffix => response to replace.
	 */
	protected function mock_project( array $overrides = array() ) {
		$routes = array(
			''                   => self::json(
				array(
					'id'             => self::PROJECT,
					'name'           => 'Acme <b>Coffee</b>',
					'websiteDomains' => array( 'acme.example' ),
					'brandNames'     => array( 'Acme' ),
					'competitors'    => array(
						array(
							'id'   => 'c1',
							'name' => 'Beanly <script>alert(1)</script>',
						),
						array(
							'id'   => 'c2',
							'name' => 'Brewster',
						),
					),
				)
			),
			'/available-filters' => self::json(
				array(
					'engines'   => array( 'chatgpt', 'perplexity', 'google_serp' ),
					'countries' => array( 'es' ),
				)
			),
			'/metrics'           => self::json(
				array(
					'shareOfVoice'           => 31.4,
					'trendShareOfVoice'      => 2.2,
					'mentionRate'            => 42,
					'trendMentionRate'       => -3,
					'avgMentionPosition'     => 2.4,
					'trendMention'           => 0.3,
					'positivityIndex'        => 71,
					'trendPositivity'        => null,
					'mentionCount'           => 120,
					'aiTrackedQueryCount'    => 25,
					'aiQueriesWithMention'   => 14,
					'sentimentPositive'      => 70,
					'sentimentNeutral'       => 40,
					'sentimentNegative'      => 10,
					'competitorShareOfVoice' => array(
						array(
							'competitorId' => 'c1',
							'shareOfVoice' => 40.1,
						),
						array(
							'competitorId' => 'c2',
							'shareOfVoice' => 12.5,
						),
					),
				)
			),
			'/timeseries'        => self::json(
				array(
					'points' => array(
						array(
							'rawDate' => '2026-11-01',
							'brand'   => array( 'shareOfVoice' => 28.0 ),
						),
						array(
							'rawDate' => '2026-11-02',
							'brand'   => array( 'shareOfVoice' => null ),
						),
						array(
							'rawDate' => '2026-11-03',
							'brand'   => array( 'shareOfVoice' => 30.0 ),
						),
						array(
							'rawDate' => '2026-11-04',
							'brand'   => array( 'shareOfVoice' => 31.4 ),
						),
					),
				)
			),
			'/cited-sources'     => self::json(
				array(
					'sources' => array(
						array(
							'domain'             => 'www.wikipedia.org',
							'citationCount'      => 30,
							'distinctQueryCount' => 9,
						),
						array(
							'domain'             => 'reviews.example',
							'citationCount'      => 12,
							'distinctQueryCount' => 4,
						),
					),
					'total'   => 48,
				)
			),
			'/metrics/movers'    => static function ( $query ) {
				$rows = 'desc' === $query['sortOrder']
					? array(
						array(
							'queryText'         => 'best coffee beans',
							'engine'            => 'chatgpt',
							'country'           => 'es',
							'shareOfVoice'      => 50.0,
							'trendShareOfVoice' => 10.0,
						),
					)
					: array(
						array(
							'queryText'         => 'coffee subscription',
							'engine'            => 'perplexity',
							'country'           => 'es',
							'shareOfVoice'      => 5.0,
							'trendShareOfVoice' => -8.0,
						),
					);
				return AIVD_Test_Case::json_public( array( 'rows' => $rows ) );
			},
		);

		foreach ( array_merge( $routes, $overrides ) as $suffix => $response ) {
			$this->routes[ self::path( $suffix ) ] = $response;
		}
	}

	/**
	 * Public alias of json() for closures.
	 *
	 * @param mixed $body    Body.
	 * @param int   $status  Status code.
	 * @param array $headers Headers.
	 * @return array
	 */
	public static function json_public( $body, $status = 200, array $headers = array() ) {
		return self::json( $body, $status, $headers );
	}

	/**
	 * Number of requests whose path ends with a suffix.
	 *
	 * @param string|null $suffix Path suffix, or null for all.
	 * @return int
	 */
	protected function count_requests( $suffix = null ) {
		if ( null === $suffix ) {
			return count( $this->requests );
		}
		$count = 0;
		foreach ( $this->requests as $request ) {
			if ( substr( $request['path'], -strlen( $suffix ) ) === $suffix ) {
				++$count;
			}
		}
		return $count;
	}
}
