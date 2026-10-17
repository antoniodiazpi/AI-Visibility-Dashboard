<?php
/**
 * Plugin Name:       AI Visibility Dashboard for Mencoro
 * Plugin URI:        https://github.com/antoniodiazpi/ai-visibility-dashboard
 * Description:       See how ChatGPT, Claude, Perplexity and Google AI talk about your brand, right in the WordPress dashboard. Share of Voice, mention rate, sentiment, competitors and the sources AI answers cite, powered by your Mencoro account.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            TrailSEO
 * Author URI:        https://trailseo.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-visibility-dashboard
 * Domain Path:       /languages
 *
 * @package AIVisibilityDashboard
 */

defined( 'ABSPATH' ) || exit;

define( 'AIVD_VERSION', '1.0.0' );
define( 'AIVD_FILE', __FILE__ );
define( 'AIVD_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIVD_URL', plugin_dir_url( __FILE__ ) );

require_once AIVD_DIR . 'includes/class-aivd-settings.php';
require_once AIVD_DIR . 'includes/class-aivd-api.php';
require_once AIVD_DIR . 'includes/class-aivd-data.php';
require_once AIVD_DIR . 'includes/class-aivd-widget.php';
require_once AIVD_DIR . 'includes/class-aivd-admin.php';

/**
 * Loads the bundled translations.
 *
 * Translations from translate.wordpress.org take precedence when they exist;
 * the files shipped in /languages cover installs from GitHub.
 */
function aivd_load_textdomain() {
	load_plugin_textdomain( 'ai-visibility-dashboard', false, dirname( plugin_basename( AIVD_FILE ) ) . '/languages' );
}
add_action( 'init', 'aivd_load_textdomain' );

AIVD_Admin::init();
AIVD_Widget::init();

/**
 * Adds a "Settings" link next to the plugin on the Plugins screen.
 *
 * @param string[] $links Existing action links.
 * @return string[]
 */
function aivd_plugin_action_links( $links ) {
	$settings = sprintf(
		'<a href="%s">%s</a>',
		esc_url( AIVD_Settings::page_url() ),
		esc_html__( 'Settings', 'ai-visibility-dashboard' )
	);
	array_unshift( $links, $settings );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( AIVD_FILE ), 'aivd_plugin_action_links' );

/**
 * Creates the settings option without autoload on activation.
 *
 * The option holds the API key, so it should not be loaded on every request.
 */
function aivd_activate() {
	if ( false === get_option( AIVD_Settings::OPTION ) ) {
		add_option( AIVD_Settings::OPTION, AIVD_Settings::defaults(), '', false );
	}
}
register_activation_hook( AIVD_FILE, 'aivd_activate' );
