<?php
/**
 * Intercepts HTTP requests to identify and prepare them for rate limiting.
 *
 * @package WP_API_Rate_Limiter
 * @since 1.0.0
 */

namespace WPRateLimiter\Core;

use WPRateLimiter\Core\RulesEngine;
use WPRateLimiter\Core\PolicyEngine;
use WPRateLimiter\Core\RequestLogger;
use WPRateLimiter\GeoIP\GeoIPLookup;

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
     * The RulesEngine instance.
     *
     * @since 1.0.0
     * @access private
     * @var RulesEngine $rules_engine
     */
    private $rules_engine;

    /**
     * The PolicyEngine instance.
     *
     * @since 1.0.0
     * @access private
     * @var PolicyEngine $policy_engine
     */
    private $policy_engine;

    /**
     * The RequestLogger instance.
     *
     * @since 1.0.0
     * @access private
     * @var RequestLogger $request_logger
     */
    private $request_logger;

    /**
     * The GeoIPLookup instance.
     *
     * @since 1.0.0
     * @access private
     * @var GeoIPLookup|null $geoip_lookup
     */
    private $geoip_lookup;

    /**
     * Temporary storage for request data before final logging (e.g., awaiting status code).
     *
     * @since 1.0.0
     * @access private
     * @var array $request_start_data
     */
    private $request_start_data = [];

    /**
     * Constructor for the Middleware class.
     *
     * @since 1.0.0
     * @param RulesEngine   $rules_engine   The rules engine instance.
     * @param PolicyEngine  $policy_engine  The policy engine instance.
     * @param RequestLogger $request_logger The request logger instance.
     */
    public function __construct( RulesEngine $rules_engine, PolicyEngine $policy_engine, RequestLogger $request_logger, GeoIPLookup $geoip_lookup = null  ) {
        $this->rules_engine   = $rules_engine;
        $this->policy_engine  = $policy_engine;
        $this->request_logger = $request_logger;
        $this->geoip_lookup   = $geoip_lookup;
    }

    /**
     * Initializes request data at the start of a REST or AJAX request.
     *
     * @since 1.0.0
     * @param string $request_type 'rest' or 'ajax'.
     * @param string $endpoint The endpoint string.
     * @param string $method The HTTP method.
     * @return array Initial request data array.
     */
    private function initialize_request_data( $request_type, $endpoint, $method ) {
        $ip      = $this->get_client_ip();
        $user_id = get_current_user_id();
        $user_role = null;
        if ( $user_id ) {
            $user = get_user_by( 'id', $user_id );
            if ( $user && ! empty( $user->roles ) ) {
                $user_role = $user->roles[0];
            }
        }

        $country_code = null;
         if ( $this->geoip_lookup && filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
                $geoip_data = $this->geoip_lookup->get_geoip_data( $ip );
                if ( $geoip_data && ! empty( $geoip_data->country_code ) && $geoip_data->country_code !== 'ZZ' ) {
                    $country_code = $geoip_data->country_code;
                }
            }
        
        $data = [
            'request_time' => current_time( 'mysql', true ),
            'ip'           => $ip,
            'country_code' => $country_code,
            'method'       => $method,
            'endpoint'     => $endpoint,
            'user_id'      => $user_id ?: null,
            'user_role'    => $user_role,
            'is_blocked'   => 0,
            'response_ms'  => null,
            'status_code'  => null,
            'bytes'        => null,
            'meta'         => [],
        ];
        return $data;
    }

    /**
     * Handles the interception of REST API requests.
     *
     * This filter runs early in the REST API request process,
     * before the actual endpoint handler is dispatched.
     *
     * @since 1.0.0
     * @param \WP_REST_Response|\WP_Error|mixed $response The response object.
     * @param array $handler The handler for the request.
     * @param \WP_REST_Request $request The request object.
     * @return \WP_REST_Response|\WP_Error|mixed The original response, or a new response if blocked.
     */
    public function handle_rest_pre_dispatch( $response, $handler, $request ) {
        $endpoint = $request->get_route();
        $method   = $request->get_method();
        $ip       = $this->get_client_ip();
        $user_id  = get_current_user_id();

        $this->request_start_data['rest'] = $this->initialize_request_data( 'rest', $endpoint, $method );
        $request_start_time = microtime( true );

        $check_result = $this->rules_engine->check_request( $ip, $user_id, $endpoint );

        if ( $check_result['blocked'] ) {
            $response = $this->policy_engine->block_rest_request( $check_result['retry_after'] );
            // Log immediately if blocked, as it short-circuits.
            $this->request_start_data['rest']['is_blocked']  = 1;
            $this->request_start_data['rest']['status_code'] = 429;
            $this->request_start_data['rest']['response_ms'] = round( ( microtime( true ) - $request_start_time ) * 1000 );
            $this->request_logger->log_request( $this->request_start_data['rest'] );
            unset( $this->request_start_data['rest'] ); // Clear temporary data
        }

        // Store start time for post-dispatch logging.
        $this->request_start_data['rest']['_start_time'] = $request_start_time;

        return $response;
    }

    /**
     * Logs REST API request details after dispatch.
     *
     * @since 1.0.0
     * @param \WP_REST_Response $response The response object.
     * @param \WP_REST_Server $handler The handler for the request.
     * @param \WP_REST_Request $request The request object.
     * @return \WP_REST_Response The original response.
     */
    public function handle_rest_post_dispatch( $response, $handler, $request ) {
        // Only log if not already blocked and logged by pre-dispatch.
        if ( isset( $this->request_start_data['rest'] ) && ! $this->request_start_data['rest']['is_blocked'] ) {
            $log_data = $this->request_start_data['rest'];
            $request_start_time = $log_data['_start_time'];

            $log_data['response_ms'] = round( ( microtime( true ) - $request_start_time ) * 1000 );
            $log_data['status_code'] = $response->get_status();
            $log_data['bytes']       = isset( $_SERVER['HTTP_CONTENT_LENGTH'] ) ? (int) $_SERVER['HTTP_CONTENT_LENGTH'] : null;

            $this->request_logger->log_request( $log_data );
            unset( $this->request_start_data['rest'] ); // Clear temp data
        }
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

        $ajax_action = isset( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) : 'unknown_ajax_action';
        $endpoint    = 'admin-ajax:' . $ajax_action;
        $method      = $_SERVER['REQUEST_METHOD'] ?? 'POST';

        $ip      = $this->get_client_ip();
        $user_id = get_current_user_id();

        $this->request_start_data['ajax'] = $this->initialize_request_data( 'ajax', $endpoint, $method );
        $request_start_time = microtime( true );

        $check_result = $this->rules_engine->check_request( $ip, $user_id, $endpoint );

        if ( $check_result['blocked'] ) {
            $this->request_start_data['ajax']['is_blocked']  = 1;
            $this->request_start_data['ajax']['status_code'] = 429;
            $this->request_start_data['ajax']['response_ms'] = round( ( microtime( true ) - $request_start_time ) * 1000 );
            $this->request_logger->log_request( $this->request_start_data['ajax'] );

            $this->policy_engine->block_admin_ajax_request( $check_result['retry_after'] ); // This will wp_die()
            // Execution stops here for blocked AJAX requests, so no need for post-dispatch.
        } else {
            // For allowed AJAX requests, we need to log AFTER the action completes.
            // Hook into shutdown to capture final status if possible.
            $this->request_start_data['ajax']['_start_time'] = $request_start_time;
            add_action( 'shutdown', [ $this, 'handle_admin_ajax_shutdown_log' ] );
        }
    }

    /**
     * Logs Admin-AJAX request details during shutdown for allowed requests.
     * This is a best-effort approach to capture status for AJAX.
     *
     * @since 1.0.0
     */
    public function handle_admin_ajax_shutdown_log() {
        if ( isset( $this->request_start_data['ajax'] ) && ! $this->request_start_data['ajax']['is_blocked'] ) {
            $log_data = $this->request_start_data['ajax'];
            $request_start_time = $log_data['_start_time'];

            $log_data['response_ms'] = round( ( microtime( true ) - $request_start_time ) * 1000 );
            // Attempt to get status code, though for AJAX it's usually 200 unless wp_die() or error.
            $log_data['status_code'] = http_response_code() ?: 200;
            $log_data['bytes']       = isset( $_SERVER['HTTP_CONTENT_LENGTH'] ) ? (int) $_SERVER['HTTP_CONTENT_LENGTH'] : null;

            $this->request_logger->log_request( $log_data );
            unset( $this->request_start_data['ajax'] ); // Clear temporary data
        }
    }


    /**
     * Gets the client IP address, accounting for proxies.
     *
     * @since 1.0.0
     * @return string The client IP address.
     */
    private function get_client_ip() {
        // TODO: Later implement trusted proxies logic and make this configurable.
        $ip = '0.0.0.0'; // Default unknown.

        if ( isset( $_SERVER['HTTP_CLIENT_IP'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CLIENT_IP'] ) );
        } elseif ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
            $ip = trim( end( $ips ) ); // Get the last IP, assuming it's the client's.
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