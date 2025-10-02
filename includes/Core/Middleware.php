<?php
/**
 * Intercepts HTTP requests to identify and prepare them for rate limiting.
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
 * Middleware class to intercept and process requests.
 *
 * @since 1.0.0
 */
class Middleware {

    /**
     * Handles the interception of REST API requests.
     *
     * This filter runs early in the REST API request process,
     * before the actual endpoint handler is dispatched.
     *
     * @since 1.0.0
     * @param WP_REST_Response|WP_Error|mixed $response The response object.
     * @param array $handler The handler for the request.
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error|mixed The original response, or a new response if blocked.
     */
    public function handle_rest_request( $response, $handler, $request ) {
        // For MVP, just log/debug that we caught it.
        // Later, this is where we'll call RulesEngine and PolicyEngine.
        error_log( 'RLM: Intercepted REST request: ' . $request->get_route() . ' by ' . $this->get_request_identifier( $request ) );

        // In the future, if blocked:
        // return new \WP_REST_Response(
        //     [
        //         'code' => 'rlm_rate_limit_exceeded',
        //         'message' => __( 'You have exceeded the API rate limit.', 'wp-api-rate-limiter' ),
        //         'data' => [
        //             'status' => 429,
        //             'retry_after' => 60, // Example
        //         ],
        //     ],
        //     429
        // );

        return $response;
    }

    /**
     * Handles the interception of Admin-AJAX requests.
     *
     * This method hooks into the 'init' action and checks for DOING_AJAX.
     *
     * @since 1.0.0
     */
    public function handle_admin_ajax_request() {
        if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) {
            return;
        }

        // For MVP, just log/debug that we caught it.
        // Later, this is where we'll call RulesEngine and PolicyEngine.
        $action = isset( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) : 'unknown_ajax_action';
        error_log( 'RLM: Intercepted Admin-AJAX request: ' . $action . ' by ' . $this->get_request_identifier( null, $action ) );

        // If an AJAX request is blocked, we would typically short-circuit.
        // For now, let it pass.
        // header( 'Content-Type: application/json', true, 429 );
        // echo json_encode( [ 'success' => false, 'data' => [ 'message' => __( 'Rate limit exceeded.', 'wp-api-rate-limiter' ) ] ] );
        // wp_die();
    }

    /**
     * Gets a unique identifier for the current request context.
     *
     * @since 1.0.0
     * @param \WP_REST_Request|null $request The REST request object.
     * @param string $ajax_action The admin-ajax action if applicable.
     * @return string Identifier combining user/IP.
     */
    private function get_request_identifier( $request = null, $ajax_action = '' ) {
        $user_id = get_current_user_id();
        $ip = $this->get_client_ip();

        if ( $user_id ) {
            $user = get_user_by( 'id', $user_id );
            $identifier = 'user:' . $user_id . '(' . ( $user ? $user->user_login : 'unknown' ) . ')';
        } else {
            $identifier = 'ip:' . $ip . '(unauthenticated)';
        }

        if ( $request ) {
            $identifier .= ' - ' . $request->get_route();
        } elseif ( $ajax_action ) {
            $identifier .= ' - ajax:' . $ajax_action;
        }
        return $identifier;
    }

    /**
     * Gets the client IP address, accounting for proxies.
     *
     * @since 1.0.0
     * @return string The client IP address.
     */
    private function get_client_ip() {
        // TODO: Later implement trusted proxies logic.
        $ip = '0.0.0.0'; // Default unknown.

        if ( isset( $_SERVER['HTTP_CLIENT_IP'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CLIENT_IP'] ) );
        } elseif ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
            // Get the first non-private IP, or the last if all are private.
            $ip = trim( end( $ips ) );
        } elseif ( isset( $_SERVER['HTTP_X_FORWARDED'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED'] ) );
        } elseif ( isset( $_SERVER['HTTP_FORWARDED_FOR'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_FORWARDED_FOR'] ) );
        } elseif ( isset( $_SERVER['HTTP_FORWARDED'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_FORWARDED'] ) );
        } elseif ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
        }

        return $ip;
    }
}