<?php
/**
 * Handles database operations for the wp_rlm_requests table.
 *
 * @package WP_API_Rate_Limiter
 * @since 1.0.0
 */

namespace WPRateLimiter\DB;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * RequestModel class for wp_rlm_requests table interactions.
 *
 * @since 1.0.0
 */
class RequestModel {

    /**
     * The table name (without global $wpdb->prefix).
     *
     * @since 1.0.0
     * @var string
     */
    const TABLE_NAME = 'rlm_requests';

    /**
     * Inserts a new request log entry into the database.
     *
     * @since 1.0.0
     * @param array $data Associative array of data to insert.
     *                    Expected keys: request_time, ip, method, endpoint, user_id, user_role,
     *                                   status_code, response_ms, bytes, is_blocked, meta.
     * @return int|false The ID of the inserted row on success, false on failure.
     */
    public function insert( array $data ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        $formats = [
            '%s', // request_time
            '%s', // ip
            '%s', // country_code
            '%s', // method
            '%s', // endpoint
            '%d', // user_id
            '%s', // user_role
            '%d', // status_code
            '%d', // response_ms
            '%d', // bytes
            '%d', // is_blocked
            '%s', // meta (JSON)
        ];

        // all keys shod be present, even if null, to match formats array.
        $data = wp_parse_args( $data, [
            'request_time' => current_time( 'mysql', true ),
            'ip'           => '0.0.0.0',
            'country_code' => null,
            'method'       => 'GET',
            'endpoint'     => '',
            'user_id'      => null,
            'user_role'    => null,
            'status_code'  => null,
            'response_ms'  => null,
            'bytes'        => null,
            'is_blocked'   => 0,
            'meta'         => null,
        ] );

        // Filter out null values for integer/string/float where not applicable,
        // and ensure meta is JSON encoded.
        if ( is_array( $data['meta'] ) ) {
            $data['meta'] = wp_json_encode( $data['meta'] );
        } elseif ( ! is_string( $data['meta'] ) ) {
            $data['meta'] = null;
        }

        // Remove array keys not defined in the table schema explicitly to prevent errors
        $insert_data = [];
        $insert_formats = [];
        $schema_columns = ['request_time', 'ip', 'country_code', 'method', 'endpoint', 'user_id', 'user_role', 'status_code', 'response_ms', 'bytes', 'is_blocked', 'meta'];

        foreach ($schema_columns as $column) {
            if (isset($data[$column])) {
                $insert_data[$column] = $data[$column];
                $insert_formats[] = $formats[array_search($column, $schema_columns)];
            }
        }

        $result = $wpdb->insert( $table, $insert_data, $insert_formats );

        if ( false === $result ) {
            error_log( 'WP API Rate Limiter: Failed to insert request log: ' . $wpdb->last_error );
            return false;
        }

        return $wpdb->insert_id;
    }

    /**
     * Retrieves recent request logs.
     *
     * @since 1.0.0
     * @param int $limit The maximum number of logs to retrieve.
     * @param int $offset The offset for pagination.
     * @return array An array of request log objects.
     */
    public function get_recent_requests( $limit = 20, $offset = 0 ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        $limit  = absint( $limit );
        $offset = absint( $offset );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- limit and offset are sanitized by absint
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}` ORDER BY request_time DESC LIMIT %d OFFSET %d",
            $limit,
            $offset
        ) );

        return $results;
    }

    /**
     * Clears old request logs based on a retention period.
     * This will be primarily used by the aggregation job.
     *
     * @since 1.0.0
     * @param int $days The number of days to retain logs. Logs older than this will be deleted.
     * @return int|false The number of deleted rows on success, false on failure.
     */
    public function delete_old_requests( $days ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $cutoff_date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        $result = $wpdb->query( $wpdb->prepare(
            "DELETE FROM `{$table}` WHERE request_time < %s",
            $cutoff_date
        ) );

        if ( false === $result ) {
            error_log( 'WP API Rate Limiter: Failed to delete old request logs: ' . $wpdb->last_error );
        }

        return $result;
    }
}