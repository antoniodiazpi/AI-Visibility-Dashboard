<?php
/**
 * Stored settings and their defaults.
 *
 * @package AIVisibilityDashboard
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and normalises the plugin settings.
 */
class AIVD_Settings {

	/**
	 * Option name. Created with autoload disabled because it can hold the API key.
	 */
	const OPTION = 'aivd_settings';

	/**
	 * Option that versions the cache. Bumping it invalidates every cached payload,
	 * which also works on sites whose transients live in an object cache.
	 */
	const CACHE_VERSION_OPTION = 'aivd_cache_version';

	/**
	 * Engines that answer with generated text. Google SERP and Shopping are
	 * classic search results, so they stay out of an "AI visibility" view.
	 */
	const AI_ENGINES = array( 'chatgpt', 'claude', 'perplexity', 'google_ai_overview', 'google_ai_mode' );

	/**
	 * Allowed reporting windows, in days.
	 */
	const RANGES = array( 7, 28, 90 );

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'api_key'         => '',
			'organization_id' => '',
			'project_id'      => '',
			'range'           => 28,
			'engines'         => array(),
			'audience'        => 'administrators',
		);
	}

	/**
	 * All settings merged over the defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Saves a partial update.
	 *
	 * @param array $changes Settings to change.
	 */
	public static function update( array $changes ) {
		$all = array_merge( self::all(), $changes );
		if ( false === get_option( self::OPTION ) ) {
			add_option( self::OPTION, $all, '', false );
		} else {
			update_option( self::OPTION, $all, false );
		}
	}

	/**
	 * Where the API key comes from: a constant or environment variable set by
	 * the host wins over the value stored in the database.
	 *
	 * @return string One of 'constant', 'env', 'option' or ''.
	 */
	public static function key_source() {
		if ( defined( 'MENCORO_API_KEY' ) && '' !== (string) MENCORO_API_KEY ) {
			return 'constant';
		}
		$env = getenv( 'MENCORO_API_KEY' );
		if ( is_string( $env ) && '' !== $env ) {
			return 'env';
		}
		return '' !== (string) self::get( 'api_key' ) ? 'option' : '';
	}

	/**
	 * The API key in use, or an empty string.
	 *
	 * @return string
	 */
	public static function api_key() {
		switch ( self::key_source() ) {
			case 'constant':
				return (string) MENCORO_API_KEY;
			case 'env':
				return (string) getenv( 'MENCORO_API_KEY' );
			case 'option':
				return (string) self::get( 'api_key' );
		}
		return '';
	}

	/**
	 * Last four characters of the key, to recognise it without revealing it.
	 *
	 * @return string
	 */
	public static function key_hint() {
		$key = self::api_key();
		return strlen( $key ) > 8 ? substr( $key, -4 ) : '';
	}

	/**
	 * Whether a key and a project are both set.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== self::api_key() && '' !== (string) self::get( 'organization_id' ) && '' !== (string) self::get( 'project_id' );
	}

	/**
	 * Reporting window in days, always one of the allowed values.
	 *
	 * @param mixed $value Optional value to validate instead of the stored one.
	 * @return int
	 */
	public static function range( $value = null ) {
		$range = (int) ( null === $value ? self::get( 'range' ) : $value );
		return in_array( $range, self::RANGES, true ) ? $range : 28;
	}

	/**
	 * Capability needed to see the dashboard widget.
	 *
	 * @return string
	 */
	public static function widget_capability() {
		$capability = 'editors' === self::get( 'audience' ) ? 'edit_others_posts' : 'manage_options';

		/**
		 * Filters the capability required to see the AI visibility widget.
		 *
		 * @param string $capability Capability name.
		 */
		return (string) apply_filters( 'aivd_widget_capability', $capability );
	}

	/**
	 * Current cache version.
	 *
	 * @return int
	 */
	public static function cache_version() {
		return (int) get_option( self::CACHE_VERSION_OPTION, 1 );
	}

	/**
	 * Invalidates every cached payload.
	 */
	public static function flush_cache() {
		update_option( self::CACHE_VERSION_OPTION, self::cache_version() + 1, false );
	}

	/**
	 * URL of the settings screen.
	 *
	 * @return string
	 */
	public static function page_url() {
		return admin_url( 'options-general.php?page=ai-visibility-dashboard' );
	}
}
