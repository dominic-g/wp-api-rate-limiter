<?php
/**
 * Registers and handles custom REST API endpoints for the plugin dashboard.
 *
 * @package WP_API_Rate_Limiter
 * @since 1.0.0
 */

namespace WPRateLimiter\Admin;

use WPRateLimiter\DB\RequestModel; // need this to help infetching data
// use WPRateLimiter\DB\AggregateModel; // Will need this for charts later

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * RestAPI class to manage custom REST endpoints.
 *
 * @since 1.0.0
 */
class RestAPI {

    /**
     * The RequestModel instance.
     *
     * @since 1.0.0
     * @access private
     * @var RequestModel $request_model
     */
    private $request_model;

    /**
     * Constructor for the RestAPI class.
     *
     * @since 1.0.0
     * @param RequestModel $request_model The request model instance.
     */
    public function __construct( RequestModel $request_model ) {
        $this->request_model = $request_model;
    }

    /**
     * Registers the plugin's REST API routes.
     *
     * @since 1.0.0
     */
    public function register_routes() {
        $namespace = 'rlm/v1';

        // Endpoint for Dashboard KPIs (Key Performance Indicators)
        register_rest_route( $namespace, '/dashboard-kpis', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_dashboard_kpis' ],
            'permission_callback' => [ $this, 'get_items_permissions_check' ],
            'args'                => [
                // will add 'start_date', 'end_date' later.
            ],
        ] );

        // Endpoint for recent requests table (live feed)
        register_rest_route( $namespace, '/recent-requests', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_recent_requests' ],
            'permission_callback' => [ $this, 'get_items_permissions_check' ],
            'args'                => [
                'per_page' => [
                    'sanitize_callback' => 'absint',
                    'default'           => 20,
                    'minimum'           => 1,
                    'maximum'           => 100,
                ],
                'offset'   => [
                    'sanitize_callback' => 'absint',
                    'default'           => 0,
                ],
            ],
        ] );

    }

    /**
     * Checks if a given request has access to view plugin data.
     *
     * @since 1.0.0
     * @param \WP_REST_Request $request The request object.
     * @return bool|\WP_Error True if permissions are met, WP_Error otherwise.
     */
    public function get_items_permissions_check( \WP_REST_Request $request ) {
        if ( current_user_can( 'manage_options' ) ) { // For MVP, only admins
            return true;
        }
        return new \WP_Error(
            'rest_forbidden',
            __( 'You do not have permission to access this data.', 'wp-api-rate-limiter' ),
            [ 'status' => rest_authorization_required_code() ]
        );
    }

    /**
     * Callback for the /dashboard-kpis endpoint.
     *
     * @since 1.0.0
     * @param \WP_REST_Request $request The request object.
     * @return \WP_REST_Response The response object.
     */
    public function get_dashboard_kpis( \WP_REST_Request $request ) {
        global $wpdb;
        $table_requests = $wpdb->prefix . RequestModel::TABLE_NAME;

        $today_start = gmdate( 'Y-m-d 00:00:00', current_time( 'timestamp' ) );

        // Total requests today
        $total_requests_today = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(id) FROM `{$table_requests}` WHERE request_time >= %s",
            $today_start
        ) );

        // Blocked requests today
        $blocked_requests_today = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(id) FROM `{$table_requests}` WHERE request_time >= %s AND is_blocked = 1",
            $today_start
        ) );

        // Top IPs today (example, needs proper aggregation later)
        $top_ips_query = $wpdb->get_results( $wpdb->prepare(
            "SELECT ip, COUNT(id) as count FROM `{$table_requests}` WHERE request_time >= %s GROUP BY ip ORDER BY count DESC LIMIT 5",
            $today_start
        ) );
        $top_ips = array_map(function($item) {
            return ['ip' => $item->ip, 'count' => (int) $item->count];
        }, $top_ips_query);


        $kpis = [
            'total_requests_today'   => (int) $total_requests_today,
            'blocked_requests_today' => (int) $blocked_requests_today,
            'percentage_blocked'     => ( $total_requests_today > 0 ) ? round( ( $blocked_requests_today / $total_requests_today ) * 100, 2 ) : 0,
            'top_ips_today'          => $top_ips,
            // will add avg response time, requests/min (real-time from cache) later.
        ];

        return new \WP_REST_Response( $kpis, 200 );
    }

    /**
     * Callback for the /recent-requests endpoint.
     *
     * @since 1.0.0
     * @param \WP_REST_Request $request The request object.
     * @return \WP_REST_Response The response object.
     */
    public function get_recent_requests( \WP_REST_Request $request ) {
        $per_page = $request->get_param( 'per_page' );
        $offset   = $request->get_param( 'offset' );

        $recent_requests = $this->request_model->get_recent_requests( $per_page, $offset );

        // Format data for easier consumption by React (e.g., convert bools, add readable timestamps)
        $formatted_requests = array_map( function( $req ) {
            $user_info = null;
            if ( $req->user_id ) {
                $user = get_user_by( 'id', $req->user_id );
                $user_info = [
                    'id'    => (int) $req->user_id,
                    'login' => $user ? $user->user_login : 'Unknown',
                    'role'  => $req->user_role,
                ];
            }
            return [
                'id'           => (int) $req->id,
                'request_time' => $req->request_time,
                'timestamp_readable' => human_time_diff( strtotime( $req->request_time ), current_time( 'timestamp', true ) ) . ' ago',
                'ip'           => $req->ip,
                'method'       => $req->method,
                'endpoint'     => $req->endpoint,
                'user'         => $user_info,
                'status_code'  => (int) $req->status_code,
                'response_ms'  => (int) $req->response_ms,
                'is_blocked'   => (bool) $req->is_blocked,
                'meta'         => json_decode( $req->meta, true ),
            ];
        }, $recent_requests );

        return new \WP_REST_Response( $formatted_requests, 200 );
    }
}