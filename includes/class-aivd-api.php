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
	 * @return string
	 */
	public static function base_url() {
		$base = defined( 'AIVD_API_BASE' ) ? (string) AIVD_API_BASE : self::DEFAULT_BASE;

		/**
		 * Filters the Mencoro API base URL. Useful for testing against a mock server.
		 *
		 * @param string $base Base URL.
		 */
		return untrailingslashit( (string) apply_filters( 'aivd_api_base', $base ) );
	}

	/**
	 * GET request.
	 *
	 * @param string $path  Path starting with /api/v1/.
	 * @param array  $query Query parameters. Arrays are sent comma-separated.
	 * @return array|WP_Error Decoded JSON body, or an error with a readable message.
	 */
	public function get( $path, array $query = array() ) {
		if ( '' === $this->key ) {
			return new WP_Error( 'aivd_no_key', __( 'Add your Mencoro API key in the settings.', 'ai-visibility-dashboard' ) );
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
				'timeout'    => 15,
				'user-agent' => 'AIVisibilityDashboard/' . AIVD_VERSION . ' (WordPress plugin)',
				'headers'    => array(
					'Authorization' => 'Bearer ' . $this->key,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'aivd_http',
				/* translators: %s: technical error message from WordPress HTTP API. */
				sprintf( __( 'Could not reach Mencoro: %s', 'ai-visibility-dashboard' ), $response->get_error_message() )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status >= 200 && $status < 300 ) {
			if ( ! is_array( $body ) ) {
				return new WP_Error( 'aivd_bad_json', __( 'Mencoro sent a response the plugin could not read.', 'ai-visibility-dashboard' ) );
			}
			return $body;
		}

		return self::error_for_status( $status, $body, $response );
	}

	/**
	 * Turns an HTTP error into a message an editor can act on.
	 *
	 * @param int   $status   HTTP status.
	 * @param mixed $body     Decoded body.
	 * @param array $response Raw response.
	 * @return WP_Error
	 */
	private static function error_for_status( $status, $body, $response ) {
		$detail = '';
		if ( is_array( $body ) ) {
			foreach ( array( 'message', 'error', 'title', 'detail' ) as $field ) {
				if ( isset( $body[ $field ] ) && is_string( $body[ $field ] ) ) {
					$detail = $body[ $field ];
					break;
				}
			}
		}

		switch ( $status ) {
			case 401:
				$message = __( 'Mencoro rejected the API key. Check that it is complete and has not been revoked.', 'ai-visibility-dashboard' );
				break;
			case 403:
				$message = __( 'This API key cannot read this data. Give it the "read" capability and access to the organization.', 'ai-visibility-dashboard' );
				break;
			case 404:
				$message = __( 'Mencoro could not find this project with this key. Choose the project again in the settings.', 'ai-visibility-dashboard' );
				break;
			case 429:
				$retry   = (int) wp_remote_retrieve_header( $response, 'retry-after' );
				$message = $retry > 0
					/* translators: %d: number of seconds. */
					? sprintf( _n( 'Too many requests to Mencoro. Try again in %d second.', 'Too many requests to Mencoro. Try again in %d seconds.', $retry, 'ai-visibility-dashboard' ), $retry )
					: __( 'Too many requests to Mencoro. Try again in a few minutes.', 'ai-visibility-dashboard' );
				break;
			default:
				/* translators: %d: HTTP status code. */
				$message = sprintf( __( 'Mencoro answered with an error (HTTP %d).', 'ai-visibility-dashboard' ), $status );
		}

		if ( '' !== $detail && 401 !== $status ) {
			$message .= ' ' . $detail;
		}

		return new WP_Error( 'aivd_http_' . $status, $message, array( 'status' => $status ) );
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
			if ( empty( $org['id'] ) ) {
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
				if ( empty( $project['id'] ) ) {
					continue;
				}
				$out[] = array(
					'organization_id'   => (string) $org['id'],
					'organization_name' => isset( $org['name'] ) ? (string) $org['name'] : '',
					'project_id'        => (string) $project['id'],
					'project_name'      => isset( $project['name'] ) ? (string) $project['name'] : '',
				);
			}
		}
		return $out;
	}
}
