<?php
/**
 * Provides multi-tiered IP-to-Country and rich GeoIP data lookup.
 *
 * Prioritizes external APIs, caches results, and falls back to a local database.
 *
 * @package WP_API_Rate_Limiter
 * @since 1.0.0
 */

namespace WPRateLimiter\GeoIP;

use WPRateLimiter\DB\GeoIPCacheModel;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * GeoIPLookup class for performing IP lookups with caching and fallbacks.
 *
 * @since 1.0.0
 */
class GeoIPLookup {

    /**
     * GeoIPCacheModel instance for DB caching.
     *
     * @since 1.0.0
     * @access private
     * @var GeoIPCacheModel $geoip_cache_model
     */
    private $geoip_cache_model;

    /**
     * The loaded local IP-to-Country data for fallback.
     *
     * @since 1.0.0
     * @access private
     * @var array|null $local_ip_data
     */
    private $local_ip_data;

    /**
     * API endpoint for apip.cc
     *
     * @since 1.0.0
     * @var string
     */
    const API_APIP_ENDPOINT = 'https://apip.cc/api-json/';

    /**
     * API endpoint for ipwho.is
     *
     * @since 1.0.0
     * @var string
     */
    const API_IPWHO_ENDPOINT = 'https://api.ipwho.is/ip/';

    /**
     * Cache group for session-level GeoIP results.
     *
     * @since 1.0.0
     * @var string
     */
    const SESSION_CACHE_GROUP = 'rlm_geoip_session';

    /**
     * Cache expiry for API results in DB (e.g., 7 days) before re-check.
     *
     * @since 1.0.0
     * @var int (seconds)
     */
    const DB_CACHE_EXPIRY = WEEK_IN_SECONDS;

    /**
     * Constructor for the GeoIPLookup class.
     *
     * @since 1.0.0
     * @param GeoIPCacheModel $geoip_cache_model The GeoIP cache model instance.
     */
    public function __construct( GeoIPCacheModel $geoip_cache_model ) {
        $this->geoip_cache_model = $geoip_cache_model;

        // Load the local IP-to-Country data (last resort fallback)
        $data_file = RLM_PLUGIN_DIR . 'includes/GeoIP/data/ip_to_country.php';
        if ( file_exists( $data_file ) ) {
            $this->local_ip_data = require $data_file;
            if ( ! is_array( $this->local_ip_data ) ) {
                $this->local_ip_data = null;
                error_log( 'RLM GeoIP Error: Local IP range data file exists but is not a valid array.' );
            }
        } else {
            error_log( 'RLM GeoIP Warning: Local IP range data file not found at ' . $data_file . '. Run ./bin/build-ip-data.sh' );
            $this->local_ip_data = null;
        }
    }

    /**
     * Performs a multi-tiered lookup for GeoIP data for a given IP address.
     *
     * @since 1.0.0
     * @param string $ip_address The IP address (IPv4).
     * @param bool   $force_api  If true, forces an API lookup, bypassing cache (for re-check logic).
     * @return object|null A stdClass object containing GeoIP data, or null if not found.
     */
    public function get_geoip_data( string $ip_address, bool $force_api = false ): ?object {
        if ( ! filter_var( $ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
            return null; // Only handle IPv4 for now, invalid IP.
        }

        // 1. Check Session Cache (WP_Object_Cache)
        if ( ! $force_api ) {
            $session_cached = wp_cache_get( $ip_address, self::SESSION_CACHE_GROUP );
            if ( false !== $session_cached ) {
                return $session_cached === 'NULL' ? null : (object) $session_cached;
            }
        }

        // 2. Check Database Cache
        $db_cached = $this->geoip_cache_model->get( $ip_address );
        if ( $db_cached ) {
            // Check if DB cache is still fresh enough (e.g., 7 days)
            $checked_time = strtotime( $db_cached->checked_time );
            if ( ( time() - $checked_time ) < self::DB_CACHE_EXPIRY && ! $force_api ) {
                wp_cache_set( $ip_address, (array) $db_cached, self::SESSION_CACHE_GROUP, HOUR_IN_SECONDS ); // Populate session cache
                return $db_cached;
            }
            // If cache expired or forced API, proceed to API lookup
        }

        $geo_data = null;

        // Only try APIs for public, non-reserved IPs
        if ( filter_var( $ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
            // 3. Try API Primary (apip.cc)
            $geo_data = $this->lookup_via_apip_cc( $ip_address );

            // 4. Try API Secondary (ipwho.is) if apip.cc fails
            if ( null === $geo_data ) {
                $geo_data = $this->lookup_via_ipwho_is( $ip_address );
            }
        }

        // 5. Fallback to Local Data if APIs failed or IP is private/reserved
        if ( null === $geo_data ) {
            $country_code = $this->lookup_via_local_data( $ip_address );
            if ( $country_code ) {
                // Construct a minimal object for the fallback
                $geo_data = (object) [
                    'ip'           => $ip_address,
                    'country_code' => $country_code,
                    'country'      => 'Unknown', // Placeholder, API provides full name
                ];
            }
        }

        // 6. Store in Database Cache
        if ( $geo_data ) {
            $this->geoip_cache_model->insert_or_update( (array) $geo_data );
        } else {
            // If nothing found, store a minimal entry in DB to avoid repeated API calls for same IP within cache expiry
            $this->geoip_cache_model->insert_or_update( [
                'ip'           => $ip_address,
                'country_code' => 'ZZ', // 'ZZ' for unknown/not found
                'checked_time' => current_time( 'mysql', true ),
            ] );
            error_log( 'RLM GeoIPLookup: No GeoIP data found for ' . $ip_address . ' after all tiers. Storing as "ZZ".' );
        }

        // 7. Store in Session Cache (for subsequent requests in same session)
        wp_cache_set( $ip_address, (array) $geo_data ?? 'NULL', self::SESSION_CACHE_GROUP, HOUR_IN_SECONDS );

        return $geo_data;
    }

    /**
     * Extracts and sanitizes data from apip.cc response.
     *
     * @since 1.0.0
     * @param array $api_data The API response data.
     * @param string $ip_address The IP address for logging.
     * @return object|null Formatted GeoIP data object, or null on failure.
     */
    private function format_apip_cc_data( array $api_data, string $ip_address ): ?object {
        if ( ! isset( $api_data['status'] ) || $api_data['status'] !== 'success' || empty( $api_data['CountryCode'] ) || $api_data['CountryCode'] === '-' ) {
            error_log( 'RLM GeoIP API (apip.cc) failed for ' . $ip_address . '. Response: ' . wp_json_encode( $api_data ) );
            return null;
        }

        $data = (object) [
            'ip'             => sanitize_text_field( $ip_address ),
            'continent'      => sanitize_text_field( $api_data['ContinentName'] ?? null ),
            'continent_code' => sanitize_text_field( $api_data['ContinentCode'] ?? null ),
            'country'        => sanitize_text_field( $api_data['CountryName'] ?? null ),
            'country_code'   => sanitize_text_field( $api_data['CountryCode'] ),
            'capital'        => sanitize_text_field( $api_data['Capital'] ?? null ),
            'region'         => sanitize_text_field( $api_data['RegionName'] ?? null ),
            'region_code'    => sanitize_text_field( $api_data['RegionCode'] ?? null ),
            'city'           => sanitize_text_field( $api_data['City'] ?? null ),
            'postal_code'    => sanitize_text_field( $api_data['Postal'] ?? null ),
            'dial_code'      => sanitize_text_field( $api_data['PhonePrefix'] ?? null ),
            'latitude'       => ( isset( $api_data['Latitude'] ) && is_numeric( $api_data['Latitude'] ) ) ? (float) $api_data['Latitude'] : null,
            'longitude'      => ( isset( $api_data['Longitude'] ) && is_numeric( $api_data['Longitude'] ) ) ? (float) $api_data['Longitude'] : null,
            'timezone'       => isset( $api_data['TimeZone'] ) ? ['time_zone' => sanitize_text_field( $api_data['TimeZone'] )] : null,
            'currency'       => isset( $api_data['Currency'] ) ? ['code' => sanitize_text_field( $api_data['Currency'] )] : null,
            'connection'     => isset( $api_data['org'] ) ? ['org' => sanitize_text_field( $api_data['org'] )] : null,
            // apip.cc doesn't provide security, is_in_eu, accuracy_radius, is_vpn, is_tor, is_threat directly
            'is_in_eu'       => null,
            'accuracy_radius'=> null,
            'security'       => null,
            'is_vpn'         => null,
            'is_tor'         => null,
            'is_threat'      => null,
            'checked_time'   => current_time( 'mysql', true ),
        ];

        return $data;
    }

    /**
     * Extracts and sanitizes data from ipwho.is response.
     *
     * @since 1.0.0
     * @param array $api_data The API response data.
     * @param string $ip_address The IP address for logging.
     * @return object|null Formatted GeoIP data object, or null on failure.
     */
    private function format_ipwho_is_data( array $api_data, string $ip_address ): ?object {
        if ( ! isset( $api_data['success'] ) || $api_data['success'] !== true || empty( $api_data['countryCode'] ) || $api_data['countryCode'] === '-' ) {
            error_log( 'RLM GeoIP API (ipwho.is) failed for ' . $ip_address . '. Response: ' . wp_json_encode( $api_data ) );
            return null;
        }

        $data = (object) [
            'ip'             => sanitize_text_field( $ip_address ),
            'continent'      => sanitize_text_field( $api_data['continent'] ?? null ),
            'continent_code' => sanitize_text_field( $api_data['continentCode'] ?? null ),
            'country'        => sanitize_text_field( $api_data['country'] ?? null ),
            'country_code'   => sanitize_text_field( $api_data['countryCode'] ),
            'capital'        => sanitize_text_field( $api_data['capital'] ?? null ),
            'region'         => sanitize_text_field( $api_data['region'] ?? null ),
            'region_code'    => sanitize_text_field( $api_data['regionCode'] ?? null ),
            'city'           => sanitize_text_field( $api_data['city'] ?? null ),
            'postal_code'    => sanitize_text_field( $api_data['postal_Code'] ?? null ), // Note: different key casing
            'dial_code'      => sanitize_text_field( $api_data['dial_code'] ?? null ),
            'is_in_eu'       => isset( $api_data['is_in_eu'] ) ? (bool) $api_data['is_in_eu'] : null,
            'latitude'       => ( isset( $api_data['latitude'] ) && is_numeric( $api_data['latitude'] ) ) ? (float) $api_data['latitude'] : null,
            'longitude'      => ( isset( $api_data['longitude'] ) && is_numeric( $api_data['longitude'] ) ) ? (float) $api_data['longitude'] : null,
            'accuracy_radius'=> ( isset( $api_data['accuracy_radius'] ) && is_numeric( $api_data['accuracy_radius'] ) ) ? (int) $api_data['accuracy_radius'] : null,
            'timezone'       => $api_data['timezone'] ?? null,
            'currency'       => $api_data['currency'] ?? null,
            'connection'     => $api_data['connection'] ?? null,
            'security'       => $api_data['security'] ?? null,
            'is_vpn'         => isset( $api_data['security']['isVpn'] ) ? (bool) $api_data['security']['isVpn'] : null,
            'is_tor'         => isset( $api_data['security']['isTor'] ) ? (bool) $api_data['security']['isTor'] : null,
            'is_threat'      => sanitize_text_field( $api_data['security']['isThreat'] ?? null ),
            'checked_time'   => current_time( 'mysql', true ),
        ];
        return $data;
    }

    /**
     * Generic API call helper.
     *
     * @since 1.0.0
     * @param string $url The API endpoint URL.
     * @return array|null Decoded JSON response array, or null on failure.
     */
    private function call_api( string $url ): ?array {
        $response = wp_remote_get( $url, [
            'timeout' => 3, // Short timeout for API calls
            'headers' => [
                'Accept' => 'application/json',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( 'RLM GeoIP API Request Error: ' . $response->get_error_message() . ' for ' . $url );
            return null;
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        if ( $http_code !== 200 ) {
            error_log( 'RLM GeoIP API Request Non-200 Status: ' . $http_code . ' for ' . $url );
            return null;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! is_array( $data ) ) {
            error_log( 'RLM GeoIP API Request Invalid JSON: ' . $body . ' for ' . $url );
            return null;
        }

        return $data;
    }

    /**
     * Performs GeoIP lookup using apip.cc.
     *
     * @since 1.0.0
     * @param string $ip_address The IP address.
     * @return object|null Formatted GeoIP data object, or null on failure.
     */
    private function lookup_via_apip_cc( string $ip_address ): ?object {
        $api_data = $this->call_api( self::API_APIP_ENDPOINT . $ip_address );
        if ( $api_data ) {
            return $this->format_apip_cc_data( $api_data, $ip_address );
        }
        return null;
    }

    /**
     * Performs GeoIP lookup using ipwho.is.
     *
     * @since 1.0.0
     * @param string $ip_address The IP address.
     * @return object|null Formatted GeoIP data object, or null on failure.
     */
    private function lookup_via_ipwho_is( string $ip_address ): ?object {
        $api_data = $this->call_api( self::API_IPWHO_ENDPOINT . $ip_address );
        if ( $api_data ) {
            return $this->format_ipwho_is_data( $api_data, $ip_address );
        }
        return null;
    }

    /**
     * Performs GeoIP lookup using the local IP range data (binary search).
     *
     * @since 1.0.0
     * @param string $ip_address The IP address (IPv4).
     * @return string|null The 2-letter ISO country code, or null if not found or is '-'.
     */
    private function lookup_via_local_data( string $ip_address ): ?string {
        if ( ! $this->local_ip_data ) {
            return null; // Local data not loaded.
        }

        $long_ip = ip2long( $ip_address );
        if ( false === $long_ip ) {
            return null;
        }
        $long_ip_unsigned = sprintf('%u', $long_ip); // Ensure unsigned

        $low = 0;
        $high = count( $this->local_ip_data ) - 1;

        while ( $low <= $high ) {
            $mid = floor( ( $low + $high ) / 2 );
            $record = $this->local_ip_data[ $mid ];

            $range_from = (string) $record[0];
            $range_to   = (string) $record[1];

            if ( $long_ip_unsigned >= $range_from && $long_ip_unsigned <= $range_to ) {
                $country_code = $record[2];
                return ( $country_code === '-' || empty( $country_code ) ) ? null : $country_code;
            } elseif ( $long_ip_unsigned < $range_from ) {
                $high = $mid - 1;
            } else {
                $low = $mid + 1;
            }
        }

        return null; // Not found
    }
}