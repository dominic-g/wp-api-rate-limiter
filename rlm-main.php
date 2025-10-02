<?php
/**
 * Plugin Name: WP API Rate Limiter / Request Monitor
 * Plugin URI:  https://dominicn.dev/wordpress-plugin/wp-api-rate-limiter
 * Description: Protects WordPress HTTP APIs from abuse, monitors traffic, and provides rate limiting features.
 * Version:     1.0.0
 * Author:      Dominic_N
 * Author URI:  https://dominicn.dev
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-api-rate-limiter
 * Domain Path: /languages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants.
if ( ! defined( 'RLM_PLUGIN_FILE' ) ) {
    define( 'RLM_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'RLM_PLUGIN_DIR' ) ) {
    define( 'RLM_PLUGIN_DIR', plugin_dir_path( RLM_PLUGIN_FILE ) );
}
if ( ! defined( 'RLM_PLUGIN_URL' ) ) {
    define( 'RLM_PLUGIN_URL', plugin_dir_url( RLM_PLUGIN_FILE ) );
}
if ( ! defined( 'RLM_PLUGIN_VERSION' ) ) {
    define( 'RLM_PLUGIN_VERSION', '1.0.0' );
}

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing hooks.
 */
require_once RLM_PLUGIN_DIR . 'rlm-loader.php';

/**
 * The code that runs during plugin activation.
 * This function is defined outside the class to ensure it runs even if the plugin is not fully loaded yet.
 *
 * @since 1.0.0
 */
function activate_wp_api_rate_limiter() {
    WPRateLimiter\RLM_Loader::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This function is defined outside the class to ensure it runs even if the plugin is not fully loaded yet.
 *
 * @since 1.0.0
 */
function deactivate_wp_api_rate_limiter() {
    WPRateLimiter\RLM_Loader::deactivate();
}

register_activation_hook( RLM_PLUGIN_FILE, 'activate_wp_api_rate_limiter' );
register_deactivation_hook( RLM_PLUGIN_FILE, 'deactivate_wp_api_rate_limiter' );

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then all of the functions for the plugin will be added to the hooks in this class.
 */
function run_wp_api_rate_limiter() {
    $plugin = new WPRateLimiter\RLM_Loader();
    $plugin->run();
}
run_wp_api_rate_limiter();