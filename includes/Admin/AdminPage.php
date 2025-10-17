<?php
/**
 * Handles the registration of the plugin's admin pages.
 *
 * @package WP_API_Rate_Limiter
 * @since 1.0.0
 */

namespace WPRateLimiter\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AdminPage class to manage admin menu and page rendering.
 *
 * @since 1.0.0
 */
class AdminPage {

    /**
     * The slug for the main admin menu page.
     *
     * @since 1.0.0
     * @var string
     */
    const MENU_SLUG = 'wp-api-rate-limiter';

    /**
     * Registers the plugin's admin menu page.
     *
     * @since 1.0.0
     */
    public function add_admin_menu() {
        add_menu_page(
            __( 'API Rate Limiter', 'wp-api-rate-limiter' ),
            __( 'Rate Limiter', 'wp-api-rate-limiter' ),
            'manage_options',                            
            self::MENU_SLUG,                              
            [ $this, 'render_admin_page' ],                
            'dashicons-chart-bar',                          
            25                                              
        );
    }

    /**
     * Renders the content of the admin page.
     *
     * This is where our React app will be mounted.
     *
     * @since 1.0.0
     */
    public function render_admin_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'WP API Rate Limiter Dashboard', 'wp-api-rate-limiter' ); ?></h1>
            <div id="rlm-admin-app">
                <p><?php esc_html_e( 'Loading dashboard not react...', 'wp-api-rate-limiter' ); ?></p>
                <!-- This is where our React appication will be mounted -->
            </div>
        </div>
        <?php
    }

    /**
     * Enqueues the React app's JavaScript and CSS files.
     * This will be implemented fully when we build the React app.
     *
     * @since 1.0.0
     */
    public function enqueue_admin_assets_later() {
        if ( get_current_screen()->id === 'toplevel_page_' . self::MENU_SLUG ) {
            $asset_filepath = RLM_PLUGIN_DIR . 'assets/js/admin/build/index.asset.php';

            $dependencies = [];
            $file_version = RLM_PLUGIN_VERSION;

            if ( file_exists( $asset_filepath ) ) {
                $asset_config = require $asset_filepath;
                $dependencies = $asset_config['dependencies'] ?? [];
                $file_version = $asset_config['version'] ?? RLM_PLUGIN_VERSION;
                error_log( 'RLM: Asset config loaded. Dependencies: ' . implode(', ', $dependencies) . ' | Version: ' . $file_version );

                // Remove 'react-jsx-runtime' from the dependencies, as we are now using 'classic' runtime.
                // It will be replaced implicitly by 'wp-element' providing the React global.
                $dependencies = array_filter( $dependencies, fn($dep) => $dep !== 'react-jsx-runtime' );

                // Ensure 'wp-element' is always a dependency, as it provides the React global.
                if ( ! in_array( 'wp-element', $dependencies, true ) ) {
                    $dependencies[] = 'wp-element';
                }
                // Also ensure 'wp-api-fetch', 'wp-components', 'wp-i18n' are present if your asset config doesn't list them.
                // wp-scripts usually includes them, but good to double check.
                if ( ! in_array( 'wp-api-fetch', $dependencies, true ) ) { $dependencies[] = 'wp-api-fetch'; }
                if ( ! in_array( 'wp-components', $dependencies, true ) ) { $dependencies[] = 'wp-components'; }
                if ( ! in_array( 'wp-i18n', $dependencies, true ) ) { $dependencies[] = 'wp-i18n'; }
                // Remove 'react-jsx-runtime' if it somehow still appears after filtering
                $dependencies = array_values( array_unique( $dependencies ) ); // Clean up and re-index.


            } else {
                error_log( 'RLM: ERROR: index.asset.php not found at: ' . $asset_filepath );
                // Fallback dependencies if asset.php is missing (should not happen in production build)
                $dependencies = [ 'wp-element', 'wp-api-fetch', 'wp-components', 'wp-i18n' ];
            }
            wp_enqueue_script(
                'rlm-admin-script',
                RLM_PLUGIN_URL . 'assets/js/admin/build/index.js',
                $dependencies,
                // [],
                $file_version,
                true // Enqueue in footer
            );

            // Localize script for passing dynamic data to React app (e.g., REST API nonce).
            wp_localize_script(
                'rlm-admin-script',
                'rlmAdminData',
                [
                    // 'root'  => esc_url_raw( rest_url( 'rlm/v1' ) ),
                    'namespace'    => 'rlm/v1',
                    'nonce' => wp_create_nonce( 'wp_rest' ),
                    'i18n'  => [
                        'loadingDashboard' => __( 'Loading dashboard from react...', 'wp-api-rate-limiter' ),
                        __( 'Failed to load data. Check console for details.', 'wp-api-rate-limiter' ),
                        __( 'WordPress REST API root or nonce not available. Check plugin localization data.', 'wp-api-rate-limiter' ),
                        __( 'Overview', 'wp-api-rate-limiter' ),
                        __( 'Total Requests Today', 'wp-api-rate-limiter' ),
                        __( 'Blocked Requests Today', 'wp-api-rate-limiter' ),
                        __( 'Blocked Rate', 'wp-api-rate-limiter' ),
                        __( 'Recent Requests', 'wp-api-rate-limiter' ),
                        __( 'No recent requests to display.', 'wp-api-rate-limiter' ),
                        __( 'Time', 'wp-api-rate-limiter' ),
                        __( 'IP', 'wp-api-rate-limiter' ),
                        __( 'Endpoint', 'wp-api-rate-limiter' ),
                        __( 'User', 'wp-api-rate-limiter' ),
                        __( 'Status', 'wp-api-rate-limiter' ),
                        __( 'Blocked', 'wp-api-rate-limiter' ),
                        __( 'Unauthenticated', 'wp-api-rate-limiter' ),
                        __( 'Yes', 'wp-api-rate-limiter' ),
                        __( 'No', 'wp-api-rate-limiter' ),
                        __( 'Top 5 Offending IPs Today', 'wp-api-rate-limiter' ),
                        __( 'requests', 'wp-api-rate-limiter' ),
                    ],
                ]
            );
        }
    }
    public function enqueue_admin_assets() {
        // Check if we are on our plugin's admin page.
        if ( get_current_screen()->id === 'toplevel_page_' . self::MENU_SLUG ) {
            $asset_filepath = RLM_PLUGIN_DIR . 'assets/js/admin/build/index.asset.php';

            $dependencies = [];
            $file_version = RLM_PLUGIN_VERSION;

            if ( file_exists( $asset_filepath ) ) {
                $asset_config = require $asset_filepath;
                $dependencies = $asset_config['dependencies'] ?? [];
                $file_version = $asset_config['version'] ?? RLM_PLUGIN_VERSION;
            }


            wp_enqueue_style(
                'rlm-admin-style',
                RLM_PLUGIN_URL . 'assets/js/admin/build/style-index.css',
                [], // Dependencies for style are usually none or basic WP styles
                $file_version
            );

            
        }
    }
}