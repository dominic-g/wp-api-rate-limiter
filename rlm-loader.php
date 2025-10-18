<?php
/**
 * The core WPRateLimiter class.
 *
 * defines internationalization, admin-specific hooks, and
 * public-facing hooks.
 *
 * @package WP_API_Rate_Limiter
 * @since 1.0.0
 */

namespace WPRateLimiter;

use WPRateLimiter\DB\Schema;
use WPRateLimiter\DB\RequestModel;
use WPRateLimiter\DB\GeoIPCacheModel;
use WPRateLimiter\Core\Middleware;
use WPRateLimiter\Core\RulesEngine;
use WPRateLimiter\Core\PolicyEngine;
use WPRateLimiter\Core\RequestLogger;
use WPRateLimiter\Admin\AdminPage;
use WPRateLimiter\Admin\RestAPI;
use WPRateLimiter\Admin\Settings;
use WPRateLimiter\GeoIP\GeoIPLookup;

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
        require_once RLM_PLUGIN_DIR . 'includes/DB/RequestModel.php';
        require_once RLM_PLUGIN_DIR . 'includes/Core/Middleware.php';
        require_once RLM_PLUGIN_DIR . 'includes/Core/RulesEngine.php';
        require_once RLM_PLUGIN_DIR . 'includes/Core/PolicyEngine.php';
        require_once RLM_PLUGIN_DIR . 'includes/Core/RequestLogger.php';
        require_once RLM_PLUGIN_DIR . 'includes/Admin/AdminPage.php';
        require_once RLM_PLUGIN_DIR . 'includes/Admin/RestAPI.php';
        require_once RLM_PLUGIN_DIR . 'includes/Admin/Settings.php';
        require_once RLM_PLUGIN_DIR . 'includes/GeoIP/GeoIPLookup.php';
        require_once RLM_PLUGIN_DIR . 'includes/DB/GeoIPCacheModel.php';
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
        $admin_page = new AdminPage();
        $this->add_action( 'admin_menu', $admin_page, 'add_admin_menu' );
        $this->add_action( 'admin_enqueue_scripts', $admin_page, 'enqueue_admin_assets' );
        $this->add_action( 'admin_enqueue_scripts', $admin_page, 'enqueue_admin_assets_later', 100 );
    }

    /**
     * Register the hooks and filters for the public-facing side of the site.
     *
     * @since 1.0.0
     * @access private
     */
    private function define_public_hooks() {
        $rules_engine  = new RulesEngine();
        $geoip_cache_model = new GeoIPCacheModel();
        $policy_engine = new PolicyEngine();
        $request_model  = new RequestModel(); 
        $request_logger = new RequestLogger( $request_model );
        $geoip_lookup   = new GeoIPLookup( $geoip_cache_model );
        $middleware     = new Middleware( $rules_engine, $policy_engine, $request_logger, $geoip_lookup );
        $rest_api       = new RestAPI( $request_model );
        
        // Intercept REST API requests before dispatching.
        $this->add_filter( 'rest_pre_dispatch', $middleware, 'handle_rest_request', 10, 3 );
        // Log REST API requests after dispatch (for status code and response time).
        $this->add_filter( 'rest_post_dispatch', $middleware, 'handle_rest_post_dispatch', 10, 3 );
        // Intercept Admin-AJAX requests.
        $this->add_action( 'init', $middleware, 'handle_admin_ajax_request', 1 );
        // Register custom REST API routes for the dashboard.
        $this->add_action( 'rest_api_init', $rest_api, 'register_routes' );
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
        RulesEngine::initialize_default_limits();
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