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
                <p><?php esc_html_e( 'Loading dashboard...', 'wp-api-rate-limiter' ); ?></p>
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
    public function enqueue_admin_assets() {
        // Check if we are on our plugin's admin page.
        if ( get_current_screen()->id === 'toplevel_page_' . self::MENU_SLUG ) {
            wp_enqueue_style(
                'rlm-admin-style',
                RLM_PLUGIN_URL . 'assets/css/admin/rlm-admin.css',
                [],
                RLM_PLUGIN_VERSION
            );

            wp_enqueue_script(
                'rlm-admin-script',
                RLM_PLUGIN_URL . 'assets/js/admin/rlm-admin.js',
                [ 'wp-element', 'wp-components', 'wp-i18n', 'wp-data' ],
                RLM_PLUGIN_VERSION,
                true 
            );

            // Localize script for passing dynamic data to React app (e.g., REST API nonce).
            wp_localize_script(
                'rlm-admin-script',
                'rlmAdminData',
                [
                    'root'  => esc_url_raw( rest_url( 'rlm/v1' ) ),
                    'nonce' => wp_create_nonce( 'wp_rest' ),
                    'i18n'  => [
                        'loadingDashboard' => __( 'Loading dashboard...', 'wp-api-rate-limiter' ),
                        // Add more translatable strings as needed by React app
                    ],
                ]
            );
        }
    }
}