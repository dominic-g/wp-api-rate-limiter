<?php
/**
 * Handles plugin general settings and options.
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
 * Settings class to manage plugin options.
 *
 * @since 1.0.0
 */
class Settings {
    // This class will eventually contain methods for:
    // - Registering settings sections and fields
    // - Sanitizing and validating input
    // - Providing helper functions to get setting values (e.g., is_logging_enabled(), get_raw_log_retention_days())

    /**
     * Retrieves the current logging status.
     * For MVP, always returns true as logging is implicitly on.
     *
     * @since 1.0.0
     * @return bool
     */
    public function is_logging_enabled(): bool {
        // Later, this functin will fetch from the actual setting.
        return true;
    }
}