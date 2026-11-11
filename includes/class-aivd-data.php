<?php
/**
 * Fetches, shapes and caches the data the widget shows.
 *
 * @package AIVisibilityDashboard
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the dashboard payload from several Mencoro API calls and keeps the
 * last valid one, so a slow or failing API never empties the widget.
 *
 * Storage, per context (organization, project, period, engines and key):
 * one option, never autoloaded, holding the last valid payload plus the state
 * of the last attempt. The number of contexts is capped, and they are all
 * deleted when the settings change and on uninstall.
 */
class AIVD_Data {

	/**
	 * Bump when the payload shape changes: older snapshots are then ignored.
	 */
	const SCHEMA = 2;

	const SNAPSHOT_PREFIX = 'aivd_snapshot_';
	const INDEX_OPTION    = 'aivd_snapshots';
	const CRON_HOOK       = 'aivd_background_refresh';

	/**
	 * Most snapshots kept at once (three periods, with room for a change of engines).
	 */
	const MAX_SNAPSHOTS = 6;

	/**
	 * Largest snapshot stored, in bytes. A real one is a few kilobytes.
	 */
	const MAX_BYTES = 262144;

	/**
	 * Seconds a refresh may hold the lock.
	 */
	const LOCK_TTL = 120;

	/**
	 * Seconds after which a payload with missing sections is refreshed again.
	 */
	const PARTIAL_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Seconds between two manual refreshes of the same view.
	 */
	const MANUAL_COOLDOWN = 30;

	/**
	 * Seconds a scheduled refresh may wait before WP-Cron is considered not to be running.
	 */
	const CRON_GRACE = 15 * MINUTE_IN_SECONDS;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_scheduled_refresh' ) );
	}

	/**
	 * Payload for the configured project and a reporting window.
	 *
	 * Kept for backward compatibility; the widget uses state().
	 *
	 * @param int  $range         Window in days.
	 * @param bool $force_refresh Fetch now, ignoring the cache (still one request at a time).
	 * @return array|WP_Error The last valid payload, even if out of date, or the error.
	 */
	public static function get( $range, $force_refresh = false ) {
		if ( $force_refresh ) {
			$snapshot = self::refresh( self::context( $range ), 'interactive' );
			if ( null === $snapshot ) {
				return new WP_Error( 'aivd_locked', __( 'An update is already running. Reload the page in a few seconds.', 'ai-visibility-dashboard' ) );
			}
			if ( ! empty( $snapshot['error'] ) && ( empty( $snapshot['payload'] ) || $snapshot['attempted_at'] !== $snapshot['fetched_at'] ) ) {
				return self::error_from( $snapshot['error'] );
			}
			return $snapshot['payload'];
		}

		$state = self::state( $range );
		if ( is_array( $state['payload'] ) ) {
			return $state['payload'];
		}
		return $state['error'] instanceof WP_Error ? $state['error'] : new WP_Error( 'aivd_pending', __( 'Your data is on its way. Reload the page in a few seconds.', 'ai-visibility-dashboard' ) );
	}

	/**
	 * What the widget can show right now, without waiting for Mencoro when it
	 * already has something to show.
	 *
	 * - fresh:   payload within its lifetime.
	 * - partial: as fresh, but some secondary sections could not be loaded.
	 * - empty:   Mencoro answered, but there is nothing to measure yet.
	 * - stale:   the last valid payload, out of date; an update is scheduled
	 *            unless Mencoro asked to wait.
	 * - pending: nothing yet and another request is fetching it.
	 * - error:   nothing valid to show and the last attempt failed.
	 *
	 * @param int  $range      Window in days.
	 * @param bool $allow_sync Fetch synchronously when there is nothing to show yet.
	 * @return array {
	 *     @type string        $status     One of the values above.
	 *     @type array|null    $payload    Payload to render.
	 *     @type bool          $partial    Whether some sections failed in that payload.
	 *     @type int           $fetched_at When the payload was fetched (0 if none).
	 *     @type int           $expires_at When it goes out of date (0 if none).
	 *     @type WP_Error|null $error      Last error, if the last attempt failed.
	 *     @type int           $retry_at   Earliest automatic retry (0 when free).
	 *     @type bool          $refreshing Whether an update is scheduled or running.
	 *     @type bool          $cron_late  Whether scheduled updates seem not to run on this site.
	 * }
	 */
	public static function state( $range, $allow_sync = true ) {
		$context  = self::context( $range );
		$snapshot = self::read( $context );
		$now      = time();

		if ( self::has_payload( $snapshot ) && ! self::is_expired( $snapshot, $now ) ) {
			return self::describe( $snapshot, $context, false );
		}

		$may_retry = null === $snapshot || (int) $snapshot['retry_at'] <= $now;

		if ( self::has_payload( $snapshot ) || ! $may_retry || ! $allow_sync ) {
			if ( $may_retry ) {
				self::schedule( $context['range'] );
			}
			return self::describe( $snapshot, $context, $may_retry );
		}

		// Nothing to show yet: the only case where the Dashboard waits for Mencoro, within a short budget.
		$fetched = self::refresh( $context, 'interactive' );
		if ( null === $fetched ) {
			return self::describe( $snapshot, $context, true );
		}
		return self::describe( $fetched, $context, false );
	}

	/**
	 * Same as state(), without fetching or scheduling anything. For status displays.
	 *
	 * @param int $range Window in days.
	 * @return array See state().
	 */
	public static function peek( $range ) {
		$context = self::context( $range );
		return self::describe( self::read( $context ), $context, false );
	}

	/**
	 * Refresh asked for by a person, from the widget.
	 *
	 * @param int $range Window in days.
	 * @return string 'updated', 'failed', 'running', 'too_soon' or 'rate_limited'.
	 */
	public static function refresh_now( $range ) {
		$context  = self::context( $range );
		$snapshot = self::read( $context );
		$now      = time();

		if ( null !== $snapshot ) {
			if ( ! empty( $snapshot['error']['status'] ) && 429 === (int) $snapshot['error']['status'] && (int) $snapshot['retry_at'] > $now ) {
				return 'rate_limited';
			}
			if ( (int) $snapshot['attempted_at'] > $now - self::MANUAL_COOLDOWN ) {
				return 'too_soon';
			}
		}

		$fetched = self::refresh( $context, 'interactive' );
		if ( null === $fetched ) {
			return 'running';
		}
		return empty( $fetched['error'] ) ? 'updated' : 'failed';
	}

	/**
	 * WP-Cron callback: updates one period in the background.
	 *
	 * @param int $range Window in days.
	 */
	public static function run_scheduled_refresh( $range = 28 ) {
		if ( ! AIVD_Settings::is_configured() ) {
			return;
		}
		$context  = self::context( $range );
		$snapshot = self::read( $context );
		if ( self::has_payload( $snapshot ) && ! self::is_expired( $snapshot, time() ) ) {
			return;
		}
		if ( null !== $snapshot && (int) $snapshot['retry_at'] > time() ) {
			return;
		}
		self::refresh( $context, 'background' );
	}

	/**
	 * Fetches the payload under the lock and stores the outcome. A failure keeps
	 * the previous valid payload and only records the error and the next retry.
	 *
	 * @param array  $context Context.
	 * @param string $mode    'interactive' when someone is waiting for the page,
	 *                        'background' from WP-Cron. Sets the time budget.
	 * @return array|null The stored snapshot, or null if another request holds the lock.
	 */
	private static function refresh( array $context, $mode ) {
		$lock = 'refresh_' . $context['id'];
		if ( ! AIVD_Lock::acquire( $lock, self::LOCK_TTL ) ) {
			return null;
		}

		try {
			$snapshot = self::read( $context );
			if ( null === $snapshot ) {
				$snapshot = self::blank( $context );
			}

			$now     = time();
			$payload = self::build( AIVD_Settings::all(), $context['range'], self::budget( $mode ) );

			$snapshot['attempted_at'] = $now;
			if ( is_wp_error( $payload ) ) {
				$snapshot['failures'] = (int) $snapshot['failures'] + 1;
				$snapshot['error']    = array(
					'code'    => $payload->get_error_code(),
					'message' => $payload->get_error_message(),
					'status'  => self::error_status( $payload ),
				);
				$snapshot['retry_at'] = $now + self::backoff( $payload, $snapshot['failures'] );
			} else {
				$snapshot['payload']    = $payload;
				$snapshot['fetched_at'] = $now;
				$snapshot['failures']   = 0;
				$snapshot['error']      = null;
				$snapshot['retry_at']   = 0;
			}

			self::write( $context, $snapshot );
			wp_clear_scheduled_hook( self::CRON_HOOK, array( $context['range'] ) );
		} finally {
			AIVD_Lock::release( $lock );
		}

		return $snapshot;
	}

	/**
	 * Seconds one refresh may spend waiting for Mencoro.
	 *
	 * @param string $mode 'interactive' or 'background'.
	 * @return int
	 */
	private static function budget( $mode ) {
		/**
		 * Filters the time, in seconds, one refresh may spend waiting for Mencoro.
		 * Sections that do not fit are skipped and retried on a later update.
		 *
		 * @param int    $budget Seconds: 12 while someone waits for the page, 25 in the background.
		 * @param string $mode   'interactive' or 'background'.
		 */
		return max( 5, (int) apply_filters( 'aivd_refresh_budget', 'background' === $mode ? 25 : 12, $mode ) );
	}

	/**
	 * Seconds to wait before trying again after an error.
	 *
	 * @param WP_Error $error    Error.
	 * @param int      $failures Consecutive failures, including this one.
	 * @return int
	 */
	private static function backoff( WP_Error $error, $failures ) {
		$status = self::error_status( $error );
		$data   = $error->get_error_data();

		if ( 429 === $status ) {
			$retry = is_array( $data ) && ! empty( $data['retry_after'] ) ? (int) $data['retry_after'] : 0;
			return $retry > 0 ? $retry : 5 * MINUTE_IN_SECONDS;
		}
		if ( in_array( $status, array( 401, 403, 404 ), true ) || 'aivd_no_key' === $error->get_error_code() ) {
			// Needs someone to fix the key or the project; saving the settings clears this.
			return 15 * MINUTE_IN_SECONDS;
		}
		// Server errors, timeouts and unreadable answers: 1, 2, 4… minutes, at most 30.
		return (int) min( 30 * MINUTE_IN_SECONDS, MINUTE_IN_SECONDS * pow( 2, max( 0, (int) $failures - 1 ) ) );
	}

	/**
	 * HTTP status carried by an error, 0 when there is none.
	 *
	 * @param WP_Error $error Error.
	 * @return int
	 */
	private static function error_status( WP_Error $error ) {
		$data = $error->get_error_data();
		return is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
	}

	/**
	 * Rebuilds a WP_Error from a stored one.
	 *
	 * @param array $error Stored error.
	 * @return WP_Error
	 */
	private static function error_from( array $error ) {
		return new WP_Error( (string) $error['code'], (string) $error['message'], array( 'status' => (int) $error['status'] ) );
	}

	/**
	 * Schedules a background update of one period, once.
	 *
	 * @param int $range Window in days.
	 */
	private static function schedule( $range ) {
		$args = array( (int) $range );
		if ( wp_next_scheduled( self::CRON_HOOK, $args ) ) {
			return;
		}
		wp_schedule_single_event( time(), self::CRON_HOOK, $args );
		if ( ! wp_doing_cron() && ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
			// Non-blocking loopback request, so the update starts now and not on the next page load.
			spawn_cron();
		}
	}

	/**
	 * Public shape of a snapshot.
	 *
	 * @param array|null $snapshot   Snapshot.
	 * @param array      $context    Context.
	 * @param bool       $refreshing Whether an update is on its way.
	 * @return array
	 */
	private static function describe( $snapshot, array $context, $refreshing ) {
		$now        = time();
		$payload    = self::has_payload( $snapshot ) ? $snapshot['payload'] : null;
		$error      = null !== $snapshot && ! empty( $snapshot['error'] ) ? self::error_from( $snapshot['error'] ) : null;
		$partial    = is_array( $payload ) && ! empty( $payload['failed_sections'] );
		$fetched_at = is_array( $payload ) ? (int) $snapshot['fetched_at'] : 0;
		$expires_at = is_array( $payload ) ? $fetched_at + self::ttl() : 0;

		if ( null === $payload ) {
			$status = $refreshing || AIVD_Lock::is_locked( 'refresh_' . $context['id'] ) ? 'pending' : 'error';
		} elseif ( self::is_expired( $snapshot, $now ) ) {
			$status = 'stale';
		} elseif ( self::is_empty( $payload ) ) {
			$status = 'empty';
		} else {
			$status = $partial ? 'partial' : 'fresh';
		}

		if ( 'error' === $status && null === $error ) {
			$error = new WP_Error( 'aivd_pending', __( 'Your data is on its way. Reload the page in a few seconds.', 'ai-visibility-dashboard' ) );
		}

		$next_run  = wp_next_scheduled( self::CRON_HOOK, array( $context['range'] ) );
		$cron_late = $next_run && $next_run < $now - self::CRON_GRACE;

		return array(
			'status'     => $status,
			'payload'    => $payload,
			'partial'    => $partial,
			'fetched_at' => $fetched_at,
			'expires_at' => $expires_at,
			'error'      => $error,
			'retry_at'   => null !== $snapshot ? (int) $snapshot['retry_at'] : 0,
			'refreshing' => (bool) $refreshing || (bool) $next_run,
			'cron_late'  => $cron_late,
		);
	}

	/**
	 * Whether Mencoro answered but there is nothing to measure.
	 *
	 * @param array $payload Payload.
	 * @return bool
	 */
	private static function is_empty( array $payload ) {
		if ( ! empty( $payload['no_ai_engine'] ) ) {
			return true;
		}
		return null === $payload['kpis']['share_of_voice']['value'] && null === $payload['kpis']['mention_rate']['value'];
	}

	/**
	 * Cache lifetime in seconds.
	 *
	 * @return int
	 */
	public static function ttl() {
		/**
		 * Filters how long the dashboard data is considered fresh, in seconds.
		 * Mencoro checks most queries once a day, so a few hours loses nothing.
		 *
		 * @param int $ttl Seconds, at least 60.
		 */
		return max( 60, (int) apply_filters( 'aivd_cache_ttl', 6 * HOUR_IN_SECONDS ) );
	}

	/**
	 * Whether a snapshot's payload is out of date: past its lifetime, or from
	 * an earlier UTC day, since the reporting window then moves.
	 *
	 * @param array $snapshot Snapshot with a payload.
	 * @param int   $now      Timestamp.
	 * @return bool
	 */
	private static function is_expired( array $snapshot, $now ) {
		if ( ! self::has_payload( $snapshot ) ) {
			return true;
		}
		$ttl = self::ttl();
		if ( ! empty( $snapshot['payload']['failed_sections'] ) ) {
			// Fill in the missing sections sooner, in the background.
			$ttl = min( $ttl, self::PARTIAL_TTL );
		}
		if ( (int) $snapshot['fetched_at'] + $ttl <= $now ) {
			return true;
		}
		return gmdate( 'Y-m-d', $now ) !== $snapshot['payload']['date_to'];
	}

	/**
	 * Whether a snapshot holds a payload.
	 *
	 * @param array|null $snapshot Snapshot.
	 * @return bool
	 */
	private static function has_payload( $snapshot ) {
		return is_array( $snapshot ) && is_array( $snapshot['payload'] );
	}

	/**
	 * What a payload depends on. Two contexts never share data.
	 *
	 * @param int $range Window in days.
	 * @return array
	 */
	private static function context( $range ) {
		$settings = AIVD_Settings::all();
		$range    = AIVD_Settings::range( $range );
		$engines  = (array) $settings['engines'];
		sort( $engines );

		$parts = array(
			(string) $settings['organization_id'],
			(string) $settings['project_id'],
			$range,
			implode( ',', $engines ),
			// Salted fingerprint of the key: rotating it never shows data fetched with the old one.
			wp_hash( AIVD_Settings::api_key() ),
		);

		return array(
			'id'    => md5( implode( '|', $parts ) ),
			'range' => $range,
		);
	}

	/**
	 * Empty snapshot.
	 *
	 * @param array $context Context.
	 * @return array
	 */
	private static function blank( array $context ) {
		return array(
			'schema'       => self::SCHEMA,
			'context'      => $context['id'],
			'payload'      => null,
			'fetched_at'   => 0,
			'attempted_at' => 0,
			'failures'     => 0,
			'error'        => null,
			'retry_at'     => 0,
		);
	}

	/**
	 * Stored snapshot of a context.
	 *
	 * @param array $context Context.
	 * @return array|null
	 */
	private static function read( array $context ) {
		$snapshot = get_option( self::SNAPSHOT_PREFIX . $context['id'] );
		if ( ! is_array( $snapshot ) || ! isset( $snapshot['schema'], $snapshot['context'] ) || self::SCHEMA !== (int) $snapshot['schema'] || $context['id'] !== $snapshot['context'] ) {
			return null;
		}
		return array_merge( self::blank( $context ), $snapshot );
	}

	/**
	 * Stores a snapshot, keeping at most MAX_SNAPSHOTS of them.
	 *
	 * @param array $context  Context.
	 * @param array $snapshot Snapshot.
	 */
	private static function write( array $context, array $snapshot ) {
		if ( strlen( maybe_serialize( $snapshot ) ) > self::MAX_BYTES ) {
			// Far bigger than any real answer: keep the previous data rather than store it.
			return;
		}

		$name = self::SNAPSHOT_PREFIX . $context['id'];
		update_option( $name, $snapshot, false );

		$index = get_option( self::INDEX_OPTION, array() );
		$index = is_array( $index ) ? $index : array();
		unset( $index[ $context['id'] ] );
		$index[ $context['id'] ] = time();

		// Oldest first: drop the ones over the limit.
		asort( $index );
		$extra = count( $index ) - self::MAX_SNAPSHOTS;
		foreach ( array_slice( array_keys( $index ), 0, max( 0, $extra ) ) as $id ) {
			delete_option( self::SNAPSHOT_PREFIX . $id );
			unset( $index[ $id ] );
		}
		update_option( self::INDEX_OPTION, $index, false );
	}

	/**
	 * Deletes every stored snapshot and pending update. Called when the key,
	 * the project or the engines change, so nothing of the previous setup stays.
	 */
	public static function purge() {
		$index = get_option( self::INDEX_OPTION, array() );
		foreach ( is_array( $index ) ? array_keys( $index ) : array() as $id ) {
			delete_option( self::SNAPSHOT_PREFIX . $id );
		}
		delete_option( self::INDEX_OPTION );
		wp_unschedule_hook( self::CRON_HOOK );
	}

	/**
	 * Calls the API and shapes the result.
	 *
	 * The project, its filters and the metrics are essential: if one fails the
	 * whole build fails. Trend, sources and movers are secondary: a failure
	 * leaves an error message in that section and marks the payload as partial.
	 *
	 * @param array $settings Settings.
	 * @param int   $range    Window in days.
	 * @param int   $budget   Seconds the whole build may wait for Mencoro.
	 * @return array|WP_Error
	 */
	private static function build( array $settings, $range, $budget ) {
		$api      = new AIVD_API();
		$base     = AIVD_API::project_path( $settings['organization_id'], $settings['project_id'] );
		$deadline = microtime( true ) + $budget;

		$project = self::essential( $api, $deadline, $base, array() );
		if ( is_wp_error( $project ) ) {
			return $project;
		}

		$filters = self::essential( $api, $deadline, $base . '/available-filters', array() );
		if ( is_wp_error( $filters ) ) {
			return $filters;
		}

		$available = isset( $filters['engines'] ) ? array_map( 'strval', array_filter( (array) $filters['engines'], 'is_scalar' ) ) : array();
		$wanted    = ! empty( $settings['engines'] ) ? (array) $settings['engines'] : AIVD_Settings::AI_ENGINES;
		$engines   = array_values( array_intersect( AIVD_Settings::AI_ENGINES, $wanted, $available ) );

		$date_to   = gmdate( 'Y-m-d' );
		$date_from = gmdate( 'Y-m-d', time() - ( $range - 1 ) * DAY_IN_SECONDS );

		$brands  = isset( $project['brandNames'] ) ? array_values( array_filter( (array) $project['brandNames'], 'is_scalar' ) ) : array();
		$domains = isset( $project['websiteDomains'] ) ? array_values( array_filter( (array) $project['websiteDomains'], 'is_scalar' ) ) : array();

		$payload = array(
			'project'         => array(
				'name'    => self::str( $project, 'name' ),
				'domains' => array_map( 'strval', $domains ),
				'brand'   => isset( $brands[0] ) ? (string) $brands[0] : '',
			),
			'range'           => $range,
			'engines'         => $engines,
			'date_from'       => $date_from,
			'date_to'         => $date_to,
			'fetched_at'      => time(),
			'no_ai_engine'    => empty( $engines ),
			// True when the project tracks AI engines, just none of the ones ticked in the settings.
			'no_selected'     => empty( $engines ) && array() !== array_intersect( $available, AIVD_Settings::AI_ENGINES ),
			'failed_sections' => array(),
		);

		if ( empty( $engines ) ) {
			return $payload;
		}

		$window = array(
			'dateFrom' => $date_from,
			'dateTo'   => $date_to,
			'engines'  => $engines,
		);

		$metrics = self::essential( $api, $deadline, $base . '/metrics', $window );
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

		$payload['dirty_since'] = self::str( $metrics, 'dataDirtySince' );
		$payload['competitors'] = self::competitors( $project, $metrics, $payload['project'] );

		$series            = self::secondary(
			$api,
			$deadline,
			$base . '/timeseries',
			array_merge( $window, array( 'granularity' => $range > 28 ? 'weekly' : 'daily' ) )
		);
		$payload['series'] = is_wp_error( $series ) ? $series->get_error_message() : self::series( $series );

		$sources                  = self::secondary(
			$api,
			$deadline,
			$base . '/cited-sources',
			array_merge(
				$window,
				array(
					'groupBy' => 'domain',
					'limit'   => 6,
				)
			)
		);
		$payload['sources']       = is_wp_error( $sources ) ? $sources->get_error_message() : self::sources( $sources, $payload['project']['domains'] );
		$payload['sources_total'] = is_array( $sources ) ? self::int( $sources, 'total' ) : 0;

		$payload['movers_up']   = self::movers( $api, $deadline, $base, $window, 'desc' );
		$payload['movers_down'] = self::movers( $api, $deadline, $base, $window, 'asc' );

		foreach ( array( 'series', 'sources', 'movers_up', 'movers_down' ) as $section ) {
			if ( is_string( $payload[ $section ] ) ) {
				$payload['failed_sections'][] = $section;
			}
		}

		return $payload;
	}

	/**
	 * GET for essential data, never waiting past the refresh's time budget.
	 *
	 * @param AIVD_API $api      Client.
	 * @param float    $deadline microtime() after which nothing more is requested.
	 * @param string   $path     Path.
	 * @param array    $query    Query.
	 * @return array|WP_Error
	 */
	private static function essential( AIVD_API $api, $deadline, $path, array $query ) {
		$left = (int) floor( $deadline - microtime( true ) );
		if ( $left < 1 ) {
			return new WP_Error( 'aivd_http', __( 'Mencoro did not answer in time. The widget will try again later.', 'ai-visibility-dashboard' ), array( 'status' => 0 ) );
		}
		return $api->get( $path, $query, min( AIVD_API::timeout(), $left ) );
	}

	/**
	 * GET for a secondary section, within the refresh's time budget.
	 *
	 * @param AIVD_API $api      Client.
	 * @param float    $deadline microtime() after which nothing more is requested.
	 * @param string   $path     Path.
	 * @param array    $query    Query.
	 * @return array|WP_Error
	 */
	private static function secondary( AIVD_API $api, $deadline, $path, array $query ) {
		$left = (int) floor( $deadline - microtime( true ) );
		if ( $left < 2 ) {
			return new WP_Error( 'aivd_skipped', __( 'Not loaded this time because Mencoro was slow. It will be retried on the next update.', 'ai-visibility-dashboard' ) );
		}
		return $api->get( $path, $query, min( AIVD_API::timeout(), $left ) );
	}

	/**
	 * Scalar field as a string, empty when missing or not a scalar.
	 *
	 * @param array  $data  Response.
	 * @param string $field Field.
	 * @return string
	 */
	private static function str( array $data, $field ) {
		return isset( $data[ $field ] ) && is_scalar( $data[ $field ] ) ? (string) $data[ $field ] : '';
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
		return isset( $data[ $field ] ) && is_numeric( $data[ $field ] ) ? (int) $data[ $field ] : 0;
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
			if ( is_array( $competitor ) && ! empty( $competitor['id'] ) && is_scalar( $competitor['id'] ) ) {
				$names[ (string) $competitor['id'] ] = self::str( $competitor, 'name' );
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
			if ( ! is_array( $row ) || empty( $row['competitorId'] ) || ! is_scalar( $row['competitorId'] ) || ! isset( $row['shareOfVoice'] ) || ! is_numeric( $row['shareOfVoice'] ) ) {
				continue;
			}
			$id     = (string) $row['competitorId'];
			$rows[] = array(
				'name'     => isset( $names[ $id ] ) && '' !== $names[ $id ] ? $names[ $id ] : __( 'Competitor', 'ai-visibility-dashboard' ),
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
			if ( ! is_array( $point ) ) {
				continue;
			}
			$brand = isset( $point['brand'] ) && is_array( $point['brand'] ) ? $point['brand'] : array();
			$out[] = array(
				'date'  => self::str( $point, 'rawDate' ),
				'value' => isset( $brand['shareOfVoice'] ) && is_numeric( $brand['shareOfVoice'] ) ? (float) $brand['shareOfVoice'] : null,
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
			if ( ! is_array( $row ) ) {
				continue;
			}
			$domain = '' !== self::str( $row, 'domain' ) ? self::str( $row, 'domain' ) : self::str( $row, 'groupKey' );
			if ( '' === $domain ) {
				continue;
			}
			$out[] = array(
				'domain'    => $domain,
				'citations' => self::int( $row, 'citationCount' ),
				'queries'   => self::int( $row, 'distinctQueryCount' ),
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
	 * @param AIVD_API $api      Client.
	 * @param float    $deadline Time budget, see secondary().
	 * @param string   $base     Project path.
	 * @param array    $window   Date window and engines.
	 * @param string   $order    'desc' for gains, 'asc' for losses.
	 * @return array|string Rows, or an error message.
	 */
	private static function movers( AIVD_API $api, $deadline, $base, array $window, $order ) {
		$response = self::secondary(
			$api,
			$deadline,
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
			if ( ! is_array( $row ) || ! isset( $row['trendShareOfVoice'] ) || ! is_numeric( $row['trendShareOfVoice'] ) ) {
				continue;
			}
			$trend = (float) $row['trendShareOfVoice'];
			// A gains list only shows real gains, a losses list only real losses.
			if ( ( 'desc' === $order && $trend <= 0 ) || ( 'asc' === $order && $trend >= 0 ) ) {
				continue;
			}
			$out[] = array(
				'query'   => self::str( $row, 'queryText' ),
				'engine'  => self::str( $row, 'engine' ),
				'country' => self::str( $row, 'country' ),
				'sov'     => isset( $row['shareOfVoice'] ) && is_numeric( $row['shareOfVoice'] ) ? (float) $row['shareOfVoice'] : null,
				'trend'   => $trend,
			);
		}
		return $out;
	}
}
