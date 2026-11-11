<?php
/**
 * Minimal read-only client for the Mencoro public API.
 *
 * @package AIVisibilityDashboard
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends authenticated GET requests to the Mencoro API.
 *
 * The plugin only ever reads: no request here creates, changes or deletes
 * anything in the Mencoro account, so a key with the "read" capability is enough.
 */
class AIVD_API {

	const DEFAULT_BASE = 'https://api.mencoro.com';

	/**
	 * Default seconds to wait for one response.
	 */
	const TIMEOUT = 10;

	/**
	 * Largest response body read, in bytes. The biggest answer the plugin asks
	 * for is a few kilobytes; anything near this size is not a valid answer.
	 */
	const MAX_RESPONSE_BYTES = 1048576;

	/**
	 * API key.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Constructor.
	 *
	 * @param string $key API key. Defaults to the configured one.
	 */
	public function __construct( $key = null ) {
		$this->key = null === $key ? AIVD_Settings::api_key() : (string) $key;
	}

	/**
	 * Base URL of the API, without a trailing slash.
	 *
	 * A custom base (AIVD_API_BASE or the aivd_api_base filter) exists for tests
	 * against a mock server. It must use HTTPS, except on the local machine, so
	 * the key is never sent in clear text; anything else falls back to the default.
	 *
	 * @return string
	 */
	public static function base_url() {
		$base = defined( 'AIVD_API_BASE' ) ? (string) AIVD_API_BASE : self::DEFAULT_BASE;

		/**
		 * Filters the Mencoro API base URL. Useful for testing against a mock server.
		 *
		 * @param string $base Base URL. HTTPS, or HTTP on localhost only.
		 */
		$base = untrailingslashit( (string) apply_filters( 'aivd_api_base', $base ) );

		return self::is_allowed_base( $base ) ? $base : self::DEFAULT_BASE;
	}

	/**
	 * Whether a base URL is safe to send the key to.
	 *
	 * @param string $base Base URL.
	 * @return bool
	 */
	private static function is_allowed_base( $base ) {
		$parts = wp_parse_url( $base );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return false;
		}
		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( trim( $parts['host'], '[]' ) );
		if ( 'https' === $scheme ) {
			return true;
		}
		return 'http' === $scheme && in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );
	}

	/**
	 * Seconds to wait for one response.
	 *
	 * @return int
	 */
	public static function timeout() {
		/**
		 * Filters how long, in seconds, the plugin waits for one Mencoro response.
		 *
		 * @param int $timeout Seconds, between 2 and 30.
		 */
		return min( 30, max( 2, (int) apply_filters( 'aivd_http_timeout', self::TIMEOUT ) ) );
	}

	/**
	 * GET request.
	 *
	 * @param string   $path    Path starting with /api/v1/.
	 * @param array    $query   Query parameters. Arrays are sent comma-separated.
	 * @param int|null $timeout Seconds to wait, or null for the default.
	 * @return array|WP_Error Decoded JSON body, or an error with a readable message.
	 *                        Error data holds the HTTP status (0 when unreachable)
	 *                        and, for 429, the seconds to wait in "retry_after".
	 */
	public function get( $path, array $query = array(), $timeout = null ) {
		if ( '' === $this->key ) {
			return new WP_Error( 'aivd_no_key', __( 'Add your Mencoro API key in the settings.', 'ai-visibility-dashboard' ), array( 'status' => 0 ) );
		}

		foreach ( $query as $name => $value ) {
			if ( is_array( $value ) ) {
				if ( empty( $value ) ) {
					unset( $query[ $name ] );
				} else {
					$query[ $name ] = implode( ',', $value );
				}
			} elseif ( is_bool( $value ) ) {
				$query[ $name ] = $value ? 'true' : 'false';
			}
		}

		$url = self::base_url() . $path;
		if ( ! empty( $query ) ) {
			$url .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => null === $timeout ? self::timeout() : max( 1, (int) $timeout ),
				// The API never redirects. Following one could hand the key to another host.
				'redirection'         => 0,
				'limit_response_size' => self::MAX_RESPONSE_BYTES,
				'user-agent'          => 'AIVisibilityDashboard/' . AIVD_VERSION . ' (WordPress plugin)',
				'headers'             => array(
					'Authorization' => 'Bearer ' . $this->key,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::transport_error( $response );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( $status >= 200 && $status < 300 ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $body ) ) {
				return new WP_Error( 'aivd_bad_json', __( 'Mencoro sent a response the plugin could not read. If this keeps happening, a proxy or firewall may be answering instead of Mencoro.', 'ai-visibility-dashboard' ), array( 'status' => $status ) );
			}
			return $body;
		}

		return self::error_for_status( $status, $response );
	}

	/**
	 * Turns a failed connection into a message an editor can act on.
	 *
	 * @param WP_Error $error Error from the WordPress HTTP API.
	 * @return WP_Error
	 */
	private static function transport_error( WP_Error $error ) {
		$detail = $error->get_error_message();
		if ( false !== stripos( $detail, 'timed out' ) || false !== strpos( $detail, 'cURL error 28' ) ) {
			$message = __( 'Mencoro did not answer in time. The widget will try again later.', 'ai-visibility-dashboard' );
		} else {
			$message = sprintf(
				/* translators: %s: technical error message from WordPress HTTP API. */
				__( 'Could not reach Mencoro: %s', 'ai-visibility-dashboard' ),
				// Keep the technical reason short; it never contains the key, which travels in a header.
				wp_html_excerpt( $detail, 200, '…' )
			);
		}
		return new WP_Error( 'aivd_http', $message, array( 'status' => 0 ) );
	}

	/**
	 * Turns an HTTP error into a message an editor can act on.
	 *
	 * The response body is never copied into the message: it could hold
	 * internal details of the provider, and the widget may be seen by editors.
	 *
	 * @param int   $status   HTTP status.
	 * @param array $response Raw response.
	 * @return WP_Error
	 */
	private static function error_for_status( $status, $response ) {
		$data = array( 'status' => $status );

		switch ( true ) {
			case 401 === $status:
				$message = __( 'Mencoro rejected the API key. Check that it is complete and has not been revoked.', 'ai-visibility-dashboard' );
				break;
			case 403 === $status:
				$message = __( 'This API key cannot read this data. Give it the "read" capability and access to the organization.', 'ai-visibility-dashboard' );
				break;
			case 404 === $status:
				$message = __( 'Mencoro could not find this project with this key. Choose the project again in the settings.', 'ai-visibility-dashboard' );
				break;
			case 429 === $status:
				$retry               = self::retry_after( $response );
				$data['retry_after'] = $retry;
				$message             = $retry > 0
					/* translators: %d: number of seconds. */
					? sprintf( _n( 'Too many requests to Mencoro. Try again in %d second.', 'Too many requests to Mencoro. Try again in %d seconds.', $retry, 'ai-visibility-dashboard' ), $retry )
					: __( 'Too many requests to Mencoro. Try again in a few minutes.', 'ai-visibility-dashboard' );
				break;
			case $status >= 300 && $status < 400:
				/* translators: %d: HTTP status code. */
				$message = sprintf( __( 'Mencoro answered with an unexpected redirect (HTTP %d). Check that nothing between your server and Mencoro rewrites the address.', 'ai-visibility-dashboard' ), $status );
				break;
			case $status >= 500:
				/* translators: %d: HTTP status code. */
				$message = sprintf( __( 'Mencoro answered with an error (HTTP %d). This is usually temporary: the widget will try again later.', 'ai-visibility-dashboard' ), $status );
				break;
			default:
				/* translators: %d: HTTP status code. */
				$message = sprintf( __( 'Mencoro answered with an error (HTTP %d).', 'ai-visibility-dashboard' ), $status );
		}

		return new WP_Error( 'aivd_http_' . $status, $message, $data );
	}

	/**
	 * Seconds asked for in a Retry-After header, 0 when absent or unreadable.
	 *
	 * @param array $response Raw response.
	 * @return int Seconds, capped at one hour.
	 */
	private static function retry_after( $response ) {
		$header = wp_remote_retrieve_header( $response, 'retry-after' );
		if ( is_array( $header ) ) {
			$header = reset( $header );
		}
		$header = trim( (string) $header );
		if ( '' === $header ) {
			return 0;
		}
		if ( ctype_digit( $header ) ) {
			$seconds = (int) $header;
		} else {
			// An HTTP date is allowed too.
			$time    = strtotime( $header );
			$seconds = $time ? $time - time() : 0;
		}
		return min( HOUR_IN_SECONDS, max( 0, $seconds ) );
	}

	/**
	 * Path prefix for a project.
	 *
	 * @param string $org_id     Organization id.
	 * @param string $project_id Project id.
	 * @return string
	 */
	public static function project_path( $org_id, $project_id ) {
		return '/api/v1/organizations/' . rawurlencode( $org_id ) . '/projects/' . rawurlencode( $project_id );
	}

	/**
	 * Identity behind the key.
	 *
	 * @return array|WP_Error
	 */
	public function me() {
		return $this->get( '/api/v1/me' );
	}

	/**
	 * Every active project the key can read, across its organizations.
	 *
	 * @return array|WP_Error List of arrays with organization and project ids and names.
	 */
	public function all_projects() {
		$orgs = $this->get(
			'/api/v1/organizations',
			array(
				'limit'     => 100,
				'status'    => 'active',
				'sortBy'    => 'name',
				'sortOrder' => 'asc',
			)
		);
		if ( is_wp_error( $orgs ) ) {
			return $orgs;
		}

		$out = array();
		foreach ( isset( $orgs['items'] ) ? (array) $orgs['items'] : array() as $org ) {
			if ( ! is_array( $org ) || empty( $org['id'] ) || ! is_string( $org['id'] ) ) {
				continue;
			}
			$projects = $this->get(
				'/api/v1/organizations/' . rawurlencode( $org['id'] ) . '/projects',
				array(
					'limit'     => 100,
					'status'    => 'active',
					'sortBy'    => 'name',
					'sortOrder' => 'asc',
				)
			);
			if ( is_wp_error( $projects ) ) {
				return $projects;
			}
			foreach ( isset( $projects['items'] ) ? (array) $projects['items'] : array() as $project ) {
				if ( ! is_array( $project ) || empty( $project['id'] ) || ! is_string( $project['id'] ) ) {
					continue;
				}
				$out[] = array(
					'organization_id'   => (string) $org['id'],
					'organization_name' => isset( $org['name'] ) && is_scalar( $org['name'] ) ? (string) $org['name'] : '',
					'project_id'        => (string) $project['id'],
					'project_name'      => isset( $project['name'] ) && is_scalar( $project['name'] ) ? (string) $project['name'] : '',
				);
			}
		}
		return $out;
	}
}
