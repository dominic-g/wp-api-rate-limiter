<?php
/**
 * Enforces rate limiting policies (e.g., return 429 status).
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
 * PolicyEngine class to enforce rate limits.
 *
 * @since 1.0.0
 */
class PolicyEngine {

    /**
     * Sends a 429 Too Many Requests response for REST API requests.
     *
     * @since 1.0.0
     * @param int $retry_after The number of seconds after which the client can retry.
     * @return \WP_REST_Response The response object.
     */
    public function block_rest_request( $retry_after ) {
        $response_data = [
            'code'    => 'rlm_rate_limit_exceeded',
            'message' => __( 'You have exceeded the API rate limit.', 'wp-api-rate-limiter' ),
            'data'    => [
                'status'      => 429,
                'retry_after' => $retry_after,
            ],
        ];

        $response = new \WP_REST_Response( $response_data, 429 );
        $response->header( 'Retry-After', $retry_after );
        $response->header( 'X-RateLimit-Limit', 'N/A' ); // Will be dynamic later
        $response->header( 'X-RateLimit-Remaining', 0 );
        $response->header( 'X-RateLimit-Reset', time() + $retry_after );

        return $response;
    }

    /**
     * Sends a 429 Too Many Requests response for Admin-AJAX requests.
     *
     * @since 1.0.0
     * @param int $retry_after The number of seconds after which the client can retry.
     */
    public function block_admin_ajax_request( $retry_after ) {
        // Set headers before sending JSON.
        header( 'Content-Type: application/json', true, 429 );
        header( 'Retry-After: ' . $retry_after );
        header( 'X-RateLimit-Limit: N/A' ); // Will be dynamic later
        header( 'X-RateLimit-Remaining: 0' );
        header( 'X-RateLimit-Reset: ' . ( time() + $retry_after ) );

        echo json_encode(
            [
                'success' => false,
                'data'    => [
                    'message'     => __( 'You have exceeded the API rate limit. Please try again later.', 'wp-api-rate-limiter' ),
                    'retry_after' => $retry_after,
                ],
            ]
        );
        wp_die(); // Terminate execution.
    }
}