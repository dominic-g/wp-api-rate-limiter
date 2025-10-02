<?php
/**
 * Determines if a request should be rate-limited based on defined rules.
 *
 * @package WP_API_Rate_Limiter
 * @since 1.0.0
 */

namespace WPRateLimiter\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * RulesEngine class to apply rate limiting rules.
 *
 * @since 1.0.0
 */
class RulesEngine {

    const RLM_GLOBAL_UNAUTH_LIMIT_OPTION = 'rlm_global_unauth_limit';
    const RLM_GLOBAL_AUTH_LIMIT_OPTION   = 'rlm_global_auth_limit';

    /**
     * Checks if a given request should be blocked based on defined rate limits.
     *
     * @since 1.0.0
     * @param string $ip The client IP address.
     * @param int|null $user_id The authenticated user ID, or null if unauthenticated.
     * @param string $endpoint The requested endpoint (e.g., REST route, AJAX action).
     * @return array An array containing 'blocked' (bool) and 'retry_after' (int) if blocked.
     */
    public function check_request( $ip, $user_id, $endpoint ) {
        $blocked     = false;
        $retry_after = 0;

        // Determine the type of request for global limits.
        $is_authenticated = ! empty( $user_id );

        // Get global limits from options. For MVP, these will be simple arrays {count, seconds}.
        $global_unauth_limit = get_option( self::RLM_GLOBAL_UNAUTH_LIMIT_OPTION, ['count' => 100, 'seconds' => 60] ); // Default 100 reqs/min
        $global_auth_limit   = get_option( self::RLM_GLOBAL_AUTH_LIMIT_OPTION, ['count' => 500, 'seconds' => 60] );   // Default 500 reqs/min

        $limit_config = $is_authenticated ? $global_auth_limit : $global_unauth_limit;

        $limit_count   = isset( $limit_config['count'] ) ? (int) $limit_config['count'] : 100;
        $per_seconds   = isset( $limit_config['seconds'] ) ? (int) $limit_config['seconds'] : 60;

        if ( $limit_count <= 0 || $per_seconds <= 0 ) {
            return ['blocked' => false, 'retry_after' => 0]; // Limits disabled or invalid.
        }

        // Determine the cache key for this request.
        // For MVP, we use a simple Fixed Window algorithm with WP_Object_Cache.
        $cache_key = $this->get_cache_key( $ip, $user_id, $is_authenticated );
        $window_start = floor( time() / $per_seconds ) * $per_seconds; // Current window timestamp.
        $cache_key_with_window = $cache_key . ':' . $window_start;

        // Get current count from object cache.
        $current_count = wp_cache_get( $cache_key_with_window, 'rlm_rate_limits' );
        if ( false === $current_count ) {
            $current_count = 0;
            // Set initial count, expire it at the end of the window.
            wp_cache_set( $cache_key_with_window, 1, 'rlm_rate_limits', $per_seconds );
        } else {
            // Increment the counter.
            $current_count = wp_cache_incr( $cache_key_with_window, 1, 'rlm_rate_limits' );
        }

        // Check if limit is exceeded.
        if ( $current_count > $limit_count ) {
            $blocked = true;
            $retry_after = $per_seconds - ( time() % $per_seconds );
            error_log( sprintf( 'RLM: Request blocked. Key: %s, Count: %d, Limit: %d/%d, Retry after: %d', $cache_key_with_window, $current_count, $limit_count, $per_seconds, $retry_after ) );
        } else {
            error_log( sprintf( 'RLM: Request allowed. Key: %s, Count: %d, Limit: %d/%d', $cache_key_with_window, $current_count, $limit_count, $per_seconds ) );
        }

        return ['blocked' => $blocked, 'retry_after' => $retry_after];
    }

    /**
     * Generates a unique cache key for a request based on IP or user ID.
     *
     * @since 1.0.0
     * @param string $ip The client IP address.
     * @param int|null $user_id The authenticated user ID, or null.
     * @param bool $is_authenticated True if the user is authenticated.
     * @return string The cache key.
     */
    private function get_cache_key( $ip, $user_id, $is_authenticated ) {
        if ( $is_authenticated ) {
            return 'auth_user:' . $user_id;
        }
        return 'unauth_ip:' . $ip;
    }

    /**
     * Initializes default global limits if they don't exist.
     * Called on plugin activation.
     *
     * @since 1.0.0
     */
    public static function initialize_default_limits() {
        if ( false === get_option( self::RLM_GLOBAL_UNAUTH_LIMIT_OPTION ) ) {
            add_option( self::RLM_GLOBAL_UNAUTH_LIMIT_OPTION, ['count' => 100, 'seconds' => 60] );
        }
        if ( false === get_option( self::RLM_GLOBAL_AUTH_LIMIT_OPTION ) ) {
            add_option( self::RLM_GLOBAL_AUTH_LIMIT_OPTION, ['count' => 500, 'seconds' => 60] );
        }
    }
}