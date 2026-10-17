<?php
/**
 * Fetches, shapes and caches the data the widget shows.
 *
 * @package AIVisibilityDashboard
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the dashboard payload from several Mencoro API calls.
 */
class AIVD_Data {

	/**
	 * Payload for the configured project and a reporting window, from cache when possible.
	 *
	 * @param int  $range         Window in days.
	 * @param bool $force_refresh Skip the cache.
	 * @return array|WP_Error
	 */
	public static function get( $range, $force_refresh = false ) {
		$settings = AIVD_Settings::all();
		$range    = AIVD_Settings::range( $range );
		$key      = self::cache_key( $settings, $range );

		if ( ! $force_refresh ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				if ( isset( $cached['error_code'] ) ) {
					return new WP_Error( $cached['error_code'], $cached['error_message'] );
				}
				return $cached;
			}
		}

		$payload = self::build( $settings, $range );

		if ( is_wp_error( $payload ) ) {
			// Remember failures briefly so a broken key does not hit the API on every page load.
			set_transient(
				$key,
				array(
					'error_code'    => $payload->get_error_code(),
					'error_message' => $payload->get_error_message(),
				),
				5 * MINUTE_IN_SECONDS
			);
			return $payload;
		}

		/**
		 * Filters how long the dashboard data is cached, in seconds.
		 * Mencoro checks most queries once a day, so a few hours loses nothing.
		 *
		 * @param int $ttl Seconds.
		 */
		$ttl = (int) apply_filters( 'aivd_cache_ttl', 6 * HOUR_IN_SECONDS );
		set_transient( $key, $payload, max( 60, $ttl ) );

		return $payload;
	}

	/**
	 * Cache key for a settings combination.
	 *
	 * @param array $settings Settings.
	 * @param int   $range    Window in days.
	 * @return string
	 */
	private static function cache_key( array $settings, $range ) {
		$parts = array(
			AIVD_Settings::cache_version(),
			$settings['organization_id'],
			$settings['project_id'],
			$range,
			implode( ',', (array) $settings['engines'] ),
			md5( AIVD_Settings::api_key() ),
			gmdate( 'Y-m-d' ),
		);
		return 'aivd_' . md5( implode( '|', $parts ) );
	}

	/**
	 * Calls the API and shapes the result.
	 *
	 * @param array $settings Settings.
	 * @param int   $range    Window in days.
	 * @return array|WP_Error
	 */
	private static function build( array $settings, $range ) {
		$api  = new AIVD_API();
		$base = AIVD_API::project_path( $settings['organization_id'], $settings['project_id'] );

		$project = $api->get( $base );
		if ( is_wp_error( $project ) ) {
			return $project;
		}

		$filters = $api->get( $base . '/available-filters' );
		if ( is_wp_error( $filters ) ) {
			return $filters;
		}

		$available = isset( $filters['engines'] ) ? array_map( 'strval', (array) $filters['engines'] ) : array();
		$wanted    = ! empty( $settings['engines'] ) ? (array) $settings['engines'] : AIVD_Settings::AI_ENGINES;
		$engines   = array_values( array_intersect( $wanted, $available, AIVD_Settings::AI_ENGINES ) );

		$date_to   = gmdate( 'Y-m-d' );
		$date_from = gmdate( 'Y-m-d', time() - ( $range - 1 ) * DAY_IN_SECONDS );

		$payload = array(
			'project'      => array(
				'name'    => isset( $project['name'] ) ? (string) $project['name'] : '',
				'domains' => isset( $project['websiteDomains'] ) ? array_map( 'strval', (array) $project['websiteDomains'] ) : array(),
				'brand'   => ! empty( $project['brandNames'][0] ) ? (string) $project['brandNames'][0] : '',
			),
			'range'        => $range,
			'engines'      => $engines,
			'date_from'    => $date_from,
			'date_to'      => $date_to,
			'fetched_at'   => time(),
			'no_ai_engine' => empty( $engines ),
			// True when the project tracks AI engines, just none of the ones ticked in the settings.
			'no_selected'  => empty( $engines ) && array() !== array_intersect( $available, AIVD_Settings::AI_ENGINES ),
		);

		if ( empty( $engines ) ) {
			return $payload;
		}

		$window = array(
			'dateFrom' => $date_from,
			'dateTo'   => $date_to,
			'engines'  => $engines,
		);

		$metrics = $api->get( $base . '/metrics', $window );
		if ( is_wp_error( $metrics ) ) {
			return $metrics;
		}

		$payload['kpis'] = array(
			'share_of_voice' => self::kpi( $metrics, 'shareOfVoice', 'trendShareOfVoice' ),
			'mention_rate'   => self::kpi( $metrics, 'mentionRate', 'trendMentionRate' ),
			'position'       => self::kpi( $metrics, 'avgMentionPosition', 'trendMention' ),
			'positivity'     => self::kpi( $metrics, 'positivityIndex', 'trendPositivity' ),
		);

		$payload['counts'] = array(
			'mentions'     => self::int( $metrics, 'mentionCount' ),
			'queries'      => self::int( $metrics, 'aiTrackedQueryCount' ),
			'with_mention' => self::int( $metrics, 'aiQueriesWithMention' ),
			'positive'     => self::int( $metrics, 'sentimentPositive' ),
			'neutral'      => self::int( $metrics, 'sentimentNeutral' ),
			'negative'     => self::int( $metrics, 'sentimentNegative' ),
		);

		$payload['dirty_since'] = ! empty( $metrics['dataDirtySince'] ) ? (string) $metrics['dataDirtySince'] : '';
		$payload['competitors'] = self::competitors( $project, $metrics, $payload['project'] );

		$series            = $api->get(
			$base . '/timeseries',
			array_merge( $window, array( 'granularity' => $range > 28 ? 'weekly' : 'daily' ) )
		);
		$payload['series'] = is_wp_error( $series ) ? $series->get_error_message() : self::series( $series );

		$sources            = $api->get(
			$base . '/cited-sources',
			array_merge(
				$window,
				array(
					'groupBy' => 'domain',
					'limit'   => 6,
				)
			)
		);
		$payload['sources'] = is_wp_error( $sources ) ? $sources->get_error_message() : self::sources( $sources, $payload['project']['domains'] );

		$payload['movers_up']   = self::movers( $api, $base, $window, 'desc' );
		$payload['movers_down'] = self::movers( $api, $base, $window, 'asc' );

		return $payload;
	}

	/**
	 * Value and trend of one headline metric. Null means "no data yet", never zero.
	 *
	 * @param array  $metrics Metrics response.
	 * @param string $value   Value field.
	 * @param string $trend   Trend field. Positive always means improved.
	 * @return array
	 */
	private static function kpi( array $metrics, $value, $trend ) {
		return array(
			'value' => isset( $metrics[ $value ] ) && is_numeric( $metrics[ $value ] ) ? (float) $metrics[ $value ] : null,
			'trend' => isset( $metrics[ $trend ] ) && is_numeric( $metrics[ $trend ] ) ? (float) $metrics[ $trend ] : null,
		);
	}

	/**
	 * Integer counter, zero when missing.
	 *
	 * @param array  $data  Response.
	 * @param string $field Field.
	 * @return int
	 */
	private static function int( array $data, $field ) {
		return isset( $data[ $field ] ) ? (int) $data[ $field ] : 0;
	}

	/**
	 * Brand and competitors with their Share of Voice, highest first.
	 *
	 * @param array $project Project response.
	 * @param array $metrics Metrics response.
	 * @param array $shaped  Shaped project.
	 * @return array
	 */
	private static function competitors( array $project, array $metrics, array $shaped ) {
		$names = array();
		foreach ( isset( $project['competitors'] ) ? (array) $project['competitors'] : array() as $competitor ) {
			if ( ! empty( $competitor['id'] ) ) {
				$names[ $competitor['id'] ] = isset( $competitor['name'] ) ? (string) $competitor['name'] : '';
			}
		}

		$rows = array();
		if ( isset( $metrics['shareOfVoice'] ) && is_numeric( $metrics['shareOfVoice'] ) ) {
			$rows[] = array(
				'name'     => '' !== $shaped['brand'] ? $shaped['brand'] : $shaped['name'],
				'sov'      => (float) $metrics['shareOfVoice'],
				'is_brand' => true,
			);
		}
		foreach ( isset( $metrics['competitorShareOfVoice'] ) ? (array) $metrics['competitorShareOfVoice'] : array() as $row ) {
			if ( empty( $row['competitorId'] ) || ! isset( $row['shareOfVoice'] ) || ! is_numeric( $row['shareOfVoice'] ) ) {
				continue;
			}
			$rows[] = array(
				'name'     => isset( $names[ $row['competitorId'] ] ) && '' !== $names[ $row['competitorId'] ] ? $names[ $row['competitorId'] ] : __( 'Competitor', 'ai-visibility-dashboard' ),
				'sov'      => (float) $row['shareOfVoice'],
				'is_brand' => false,
			);
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return $b['sov'] <=> $a['sov'];
			}
		);

		return array_slice( $rows, 0, 6 );
	}

	/**
	 * Share of Voice per bucket.
	 *
	 * @param array $series Time series response.
	 * @return array List of [ 'date' => string, 'value' => float|null ].
	 */
	private static function series( array $series ) {
		$out = array();
		foreach ( isset( $series['points'] ) ? (array) $series['points'] : array() as $point ) {
			$value = isset( $point['brand']['shareOfVoice'] ) && is_numeric( $point['brand']['shareOfVoice'] ) ? (float) $point['brand']['shareOfVoice'] : null;
			$out[] = array(
				'date'  => isset( $point['rawDate'] ) ? (string) $point['rawDate'] : '',
				'value' => $value,
			);
		}
		return $out;
	}

	/**
	 * Most cited domains, flagging the project's own.
	 *
	 * @param array    $sources Cited sources response.
	 * @param string[] $domains Project domains.
	 * @return array
	 */
	private static function sources( array $sources, array $domains ) {
		$own   = array_map( array( __CLASS__, 'bare_host' ), $domains );
		$own[] = self::bare_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$own   = array_filter( array_unique( $own ) );

		$out = array();
		foreach ( isset( $sources['sources'] ) ? (array) $sources['sources'] : array() as $row ) {
			$domain = ! empty( $row['domain'] ) ? (string) $row['domain'] : (string) ( isset( $row['groupKey'] ) ? $row['groupKey'] : '' );
			if ( '' === $domain ) {
				continue;
			}
			$out[] = array(
				'domain'    => $domain,
				'citations' => isset( $row['citationCount'] ) ? (int) $row['citationCount'] : 0,
				'queries'   => isset( $row['distinctQueryCount'] ) ? (int) $row['distinctQueryCount'] : 0,
				'is_own'    => in_array( self::bare_host( $domain ), $own, true ),
			);
		}
		return $out;
	}

	/**
	 * Lowercase host without a leading "www.".
	 *
	 * @param string $host Host or URL.
	 * @return string
	 */
	private static function bare_host( $host ) {
		$host = strtolower( trim( (string) $host ) );
		if ( false !== strpos( $host, '/' ) ) {
			$parsed = wp_parse_url( false === strpos( $host, '//' ) ? 'https://' . $host : $host, PHP_URL_HOST );
			$host   = is_string( $parsed ) ? $parsed : $host;
		}
		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * Tracked queries whose Share of Voice moved the most in one direction.
	 *
	 * @param AIVD_API $api    Client.
	 * @param string   $base   Project path.
	 * @param array    $window Date window and engines.
	 * @param string   $order  'desc' for gains, 'asc' for losses.
	 * @return array|string Rows, or an error message.
	 */
	private static function movers( AIVD_API $api, $base, array $window, $order ) {
		$response = $api->get(
			$base . '/metrics/movers',
			array_merge(
				$window,
				array(
					'sortBy'    => 'trend_share_of_voice',
					'sortOrder' => $order,
					'limit'     => 3,
				)
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}

		$out = array();
		foreach ( isset( $response['rows'] ) ? (array) $response['rows'] : array() as $row ) {
			if ( ! isset( $row['trendShareOfVoice'] ) || ! is_numeric( $row['trendShareOfVoice'] ) ) {
				continue;
			}
			$trend = (float) $row['trendShareOfVoice'];
			// A gains list only shows real gains, a losses list only real losses.
			if ( ( 'desc' === $order && $trend <= 0 ) || ( 'asc' === $order && $trend >= 0 ) ) {
				continue;
			}
			$out[] = array(
				'query'   => isset( $row['queryText'] ) ? (string) $row['queryText'] : '',
				'engine'  => isset( $row['engine'] ) ? (string) $row['engine'] : '',
				'country' => isset( $row['country'] ) ? (string) $row['country'] : '',
				'sov'     => isset( $row['shareOfVoice'] ) && is_numeric( $row['shareOfVoice'] ) ? (float) $row['shareOfVoice'] : null,
				'trend'   => $trend,
			);
		}
		return $out;
	}
}
