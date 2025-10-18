<?php
/**
 * Handles database operations for the wp_rlm_geoip_cache table.
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
 * GeoIPCacheModel class for wp_rlm_geoip_cache table interactions.
 *
 * @since 1.0.0
 */
class GeoIPCacheModel {

    /**
     * The table name (without global $wpdb->prefix).
     *
     * @since 1.0.0
     * @var string
     */
    const TABLE_NAME = 'rlm_geoip_cache';

    /**
     * Inserts or updates a GeoIP cache entry for an IP address.
     *
     * @since 1.0.0
     * @param array $data Associative array of GeoIP data.
     *                    Expected keys: ip (mandatory), plus all other schema columns.
     * @return int|false The number of affected rows on success, false on failure.
     */
    public function insert_or_update( array $data ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        if ( empty( $data['ip'] ) ) {
            error_log( 'RLM GeoIPCacheModel: IP address is mandatory for insert_or_update.' );
            return false;
        }

        $columns = [
            'ip'             => '%s',
            'continent'      => '%s',
            'continent_code' => '%s',
            'country'        => '%s',
            'country_code'   => '%s',
            'capital'        => '%s',
            'region'         => '%s',
            'region_code'    => '%s',
            'city'           => '%s',
            'postal_code'    => '%s',
            'dial_code'      => '%s',
            'is_in_eu'       => '%d',
            'latitude'       => '%f',
            'longitude'      => '%f',
            'accuracy_radius'=> '%d',
            'timezone'       => '%s', // JSON
            'currency'       => '%s', // JSON
            'connection'     => '%s', // JSON
            'security'       => '%s', // JSON
            'is_vpn'         => '%d',
            'is_tor'         => '%d',
            'is_threat'      => '%s',
            'checked_time'   => '%s', // DATETIME
        ];

        $insert_data    = [];
        $insert_formats = [];
        $update_data    = [];
        $update_formats = [];

        foreach ( $columns as $col => $format ) {
            if ( isset( $data[ $col ] ) ) {
                $value = $data[ $col ];

                // Special handling for JSON columns
                if ( in_array( $col, ['timezone', 'currency', 'connection', 'security'], true ) ) {
                    $value = is_array( $value ) ? wp_json_encode( $value ) : null;
                }
                // Special handling for boolean-like tinyint columns
                if ( in_array( $col, ['is_in_eu', 'is_vpn', 'is_tor'], true ) ) {
                    $value = (int) (bool) $value;
                }

                $insert_data[ $col ]    = $value;
                $insert_formats[]       = $format;
                if ( $col !== 'ip' ) { // IP is the primary key, no need to update it in SET clause
                    $update_data[ $col ]    = $value;
                    $update_formats[]       = $format;
                }
            } else {
                // If a column is not provided, set it to NULL explicitly if it's nullable
                $insert_data[ $col ] = null;
                $insert_formats[]    = $format;
                if ( $col !== 'ip' ) {
                    $update_data[ $col ] = null;
                    $update_formats[]    = $format;
                }
            }
        }

        // Add checked_time
        $insert_data['checked_time'] = current_time( 'mysql', true );
        $insert_formats[]            = '%s';
        $update_data['checked_time'] = current_time( 'mysql', true );
        $update_formats[]            = '%s';

        // Attempt to insert. If duplicate, update.
        // This leverages REPLACE INTO or INSERT ... ON DUPLICATE KEY UPDATE
        // Using ON DUPLICATE KEY UPDATE for better clarity and control.
        $query = "INSERT INTO `{$table}` (" . implode( ', ', array_keys( $insert_data ) ) . ") VALUES (" . implode( ', ', array_fill( 0, count( $insert_data ), '%s' ) ) . ") ON DUPLICATE KEY UPDATE ";

        $update_parts = [];
        foreach ( array_keys( $update_data ) as $col ) {
            $update_parts[] = "`{$col}` = %s";
        }
        $query .= implode( ', ', $update_parts );

        $all_data = array_merge( array_values( $insert_data ), array_values( $update_data ) );
        $all_formats = array_merge( $insert_formats, $update_formats );

        $result = $wpdb->query( $wpdb->prepare( $query, $all_data ) );

        if ( false === $result ) {
            error_log( 'RLM GeoIPCacheModel: Failed to insert/update GeoIP cache for IP ' . $data['ip'] . ': ' . $wpdb->last_error );
            return false;
        }

        return $result; // 1 for insert, 2 for update.
    }

    /**
     * Retrieves a GeoIP cache entry for a given IP address.
     *
     * @since 1.0.0
     * @param string $ip The IP address.
     * @return object|null The GeoIP data object, or null if not found.
     */
    public function get( string $ip ): ?object {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ip is sanitized by validation.
        $result = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE ip = %s", $ip ) );

        if ( $result ) {
            // Decode JSON fields back into arrays/objects
            $json_fields = ['timezone', 'currency', 'connection', 'security'];
            foreach ( $json_fields as $field ) {
                if ( isset( $result->$field ) && ! empty( $result->$field ) ) {
                    $result->$field = json_decode( $result->$field, true );
                }
            }
            // Convert tinyint to bool where applicable
            $bool_fields = ['is_in_eu', 'is_vpn', 'is_tor'];
            foreach ( $bool_fields as $field ) {
                if ( isset( $result->$field ) ) {
                    $result->$field = (bool) $result->$field;
                }
            }
        }

        return $result;
    }
}