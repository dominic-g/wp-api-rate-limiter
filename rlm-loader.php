<?php
/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing hooks.
 *
 * @package WP_API_Rate_Limiter
 * @since 1.0.0
 */

namespace WPRateLimiter;

use WPRateLimiter\DB\Schema;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The RLM_Loader class.
 *
 * Orchestrates the plugin's core functionality.
 *
 * @since 1.0.0
 */
class RLM_Loader {

    /**
     * Stores all the actions and filters to be registered.
     *
     * @since 1.0.0
     * @access protected
     * @var array $actions The collection of callbacks and their parameters to register with WordPress.
     * @var array $filters The collection of callbacks and their parameters to register with WordPress.
     */
    protected $actions;
    protected $filters;

    /**
     * Constructor for the RLM_Loader class.
     *
     * @since 1.0.0
     */
    public function __construct() {
        $this->actions = [];
        $this->filters = [];

        $this->load_dependencies();
        $this->set_locale();
        $this->define_admin_hooks();
        $this->define_public_hooks();
    }

    /**
     * Load the required dependencies for this plugin.
     *
     * Include the Composer autoloader.
     *
     * @since 1.0.0
     * @access private
     */
    private function load_dependencies() {
        // Autoload Composer dependencies.
        if ( file_exists( RLM_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
            require_once RLM_PLUGIN_DIR . 'vendor/autoload.php';
        } else {
            // Fallback for development if Composer hasn't been run yet.
            // In a production environment, this should always exist.
            error_log( 'WP API Rate Limiter: Composer autoloader not found. Please run `composer install`.' );
        }

        // Include other required classes manually for now, will transition to autoloader.
        require_once RLM_PLUGIN_DIR . 'includes/DB/Schema.php';
    }

    /**
     * Define the locale for this plugin for internationalization.
     *
     * @since 1.0.0
     * @access private
     */
    private function set_locale() {
        load_plugin_textdomain(
            'wp-api-rate-limiter',
            false,
            dirname( plugin_basename( RLM_PLUGIN_FILE ) ) . '/languages/'
        );
    }

    /**
     * Register the hooks and filters for the admin area.
     *
     * @since 1.0.0
     * @access private
     */
    private function define_admin_hooks() {
        // $this->add_action( 'admin_menu', $admin_page_handler, 'add_menu_page' );
    }

    /**
     * Register the hooks and filters for the public-facing side of the site.
     *
     * @since 1.0.0
     * @access private
     */
    private function define_public_hooks() {
        // $this->add_filter( 'rest_pre_dispatch', $middleware, 'handle_request' );
    }

    /**
     * Add a new action to the collection to be registered with WordPress.
     *
     * @since 1.0.0
     * @param string $hook             The name of the WordPress action that is being registered.
     * @param object $component        A reference to the instance of the object on which the action is defined.
     * @param string $callback         The name of the function definition on the object.
     * @param int    $priority         Optional. The priority at which the function should be fired. Default is 10.
     * @param int    $accepted_args    Optional. The number of arguments that should be passed to the callback. Default is 1.
     */
    public function add_action( $hook, $component, $callback, $priority = 10, $accepted_args = 1 ) {
        $this->actions[] = compact( 'hook', 'component', 'callback', 'priority', 'accepted_args' );
    }

    /**
     * Add a new filter to the collection to be registered with WordPress.
     *
     * @since 1.0.0
     * @param string $hook             The name of the WordPress filter that is being registered.
     * @param object $component        A reference to the instance of the object on which the filter is defined.
     * @param string $callback         The name of the function definition on the object.
     * @param int    $priority         Optional. The priority at which the function should be fired. Default is 10.
     * @param int    $accepted_args    Optional. The number of arguments that should be passed to the callback. Default is 1.
     */
    public function add_filter( $hook, $component, $callback, $priority = 10, $accepted_args = 1 ) {
        $this->filters[] = compact( 'hook', 'component', 'callback', 'priority', 'accepted_args' );
    }

    /**
     * Register the filters and actions with WordPress.
     *
     * @since 1.0.0
     */
    public function run() {
        foreach ( $this->filters as $hook ) {
            add_filter( $hook['hook'], [ $hook['component'], $hook['callback'] ], $hook['priority'], $hook['accepted_args'] );
        }
        foreach ( $this->actions as $hook ) {
            add_action( $hook['hook'], [ $hook['component'], $hook['callback'] ], $hook['priority'], $hook['accepted_args'] );
        }
    }

    /**
     * Handles plugin activation tasks.
     *
     * @since 1.0.0
     */
    public static function activate() {
        // Ensure Composer autoloader is available for Schema class.
        if ( file_exists( RLM_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
            require_once RLM_PLUGIN_DIR . 'vendor/autoload.php';
        } else {
            // This is critical for activation, so we prevent activation if autoloader is missing.
            deactivate_plugins( plugin_basename( RLM_PLUGIN_FILE ) );
            wp_die(
                __( 'WP API Rate Limiter requires Composer dependencies to be installed. Please run `composer install` in the plugin directory.', 'wp-api-rate-limiter' ),
                __( 'Plugin Activation Error', 'wp-api-rate-limiter' ),
                [ 'back_link' => true ]
            );
        }
        
        // Create database tables.
        Schema::create_tables();
    }

    /**
     * Handles plugin deactivation tasks.
     *
     * @since 1.0.0
     */
    public static function deactivate() {
        // Clean up cron jobs if any were registered.
        wp_clear_scheduled_hook( 'rlm_hourly_aggregation_event' );
        // Optionally drop tables during deactivation if we need to delete all the data
        // (I prefer this to be an option for the user to select on uninstall if they agree/check the 
        // remove all data then we can drop all the tables related to our plugin rlm).
        // Schema::drop_tables(); 
    }
}