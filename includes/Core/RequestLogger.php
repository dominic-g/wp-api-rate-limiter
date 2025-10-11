<?php
/**
 * Manages logging of API requests to the database.
 *
 * @package WP_API_Rate_Limiter
 * @since 1.0.0
 */

namespace WPRateLimiter\Core;

use WPRateLimiter\DB\RequestModel;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * RequestLogger class to handle recording request data.
 *
 * @since 1.0.0
 */
class RequestLogger {

    /**
     * The RequestModel instance.
     *
     * @since 1.0.0
     * @access private
     * @var RequestModel $request_model
     */
    private $request_model;

    /**
     * Constructor for the RequestLogger class.
     *
     * @since 1.0.0
     * @param RequestModel $request_model The request model instance.
     */
    public function __construct( RequestModel $request_model ) {
        $this->request_model = $request_model;
    }

    /**
     * Logs a single API request.
     *
     * @since 1.0.0
     * @param array $log_data Associative array containing request details.
     *                        Expected keys: ip, method, endpoint, user_id, user_role,
     *                                       status_code, response_ms, bytes, is_blocked, meta.
     * @return int|false The ID of the inserted log entry on success, false on failure.
     */
    public function log_request( array $log_data ) {
        // Here we can add a check for 'enable_logging' option later.
        // For MVP, logging is always on.

        return $this->request_model->insert( $log_data );
    }
}