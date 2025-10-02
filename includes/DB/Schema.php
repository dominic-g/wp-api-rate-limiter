<?php
/**
 * Handles database schema creation and updates.
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
 * Schema class to manage plugin database tables.
 *
 * @since 1.0.0
 */
class Schema {

    /**
     * Creates all necessary database tables for the plugin.
     *
     * @since 1.0.0
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table_prefix = $wpdb->prefix . 'rlm_'; // Custom prefix for plugin tables

        require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php'; // Required for dbDelta
        require_once ABSPATH . 'wp-admin/includes/upgrade.php'; // For dbDelta

        // Table: wp_rlm_requests
        $sql_requests = "CREATE TABLE `{$table_prefix}requests` (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            request_time DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
            ip VARCHAR(45) NOT NULL DEFAULT '',
            method VARCHAR(8) NOT NULL DEFAULT '',
            endpoint VARCHAR(191) NOT NULL DEFAULT '',
            user_id BIGINT(20) UNSIGNED NULL,
            user_role VARCHAR(100) NULL,
            status_code SMALLINT(5) UNSIGNED NULL,
            response_ms INT(10) UNSIGNED NULL,
            bytes INT(10) UNSIGNED NULL,
            is_blocked TINYINT(1) NOT NULL DEFAULT 0,
            meta JSON NULL,
            PRIMARY KEY (id),
            KEY request_time (request_time),
            KEY ip (ip),
            KEY endpoint (endpoint)
        ) {$charset_collate};";
        dbDelta( $sql_requests );

        // Table: wp_rlm_aggregates
        $sql_aggregates = "CREATE TABLE `{$table_prefix}aggregates` (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            agg_hour DATETIME NOT NULL,
            endpoint VARCHAR(191) NOT NULL DEFAULT '',
            role VARCHAR(100) NULL,
            ip VARCHAR(45) NULL,
            requests_count INT(10) UNSIGNED NOT NULL DEFAULT 0,
            blocked_count INT(10) UNSIGNED NOT NULL DEFAULT 0,
            avg_resptime_ms INT(10) UNSIGNED NULL,
            PRIMARY KEY (id),
            UNIQUE KEY unique_aggregate (agg_hour, endpoint, role, ip)
        ) {$charset_collate};";
        dbDelta( $sql_aggregates );

        // Table: wp_rlm_limits
        $sql_limits = "CREATE TABLE `{$table_prefix}limits` (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            type ENUM('global','role','endpoint','user','ip') NOT NULL,
            target VARCHAR(191) NULL, -- role name / endpoint / user id / ip or NULL for global
            limit_count INT(10) UNSIGNED NOT NULL,
            per_seconds INT(10) UNSIGNED NOT NULL,
            burst INT(10) UNSIGNED NULL,
            block_duration_seconds INT(10) UNSIGNED NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY unique_limit (type, target)
        ) {$charset_collate};";
        dbDelta( $sql_limits );

        // Table: wp_rlm_blacklist
        $sql_blacklist = "CREATE TABLE `{$table_prefix}blacklist` (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            ip_or_cidr VARCHAR(191) NOT NULL,
            reason TEXT NULL,
            blocked_until DATETIME NULL,
            permanent TINYINT(1) NOT NULL DEFAULT 0,
            added_by BIGINT(20) UNSIGNED NULL,
            added_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ip_or_cidr (ip_or_cidr)
        ) {$charset_collate};";
        dbDelta( $sql_blacklist );

        // Table: wp_rlm_audit
        $sql_audit = "CREATE TABLE `{$table_prefix}audit` (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            action VARCHAR(255) NOT NULL,
            actor_id BIGINT(20) UNSIGNED NULL,
            target VARCHAR(255) NULL,
            details JSON NULL,
            timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY timestamp (timestamp)
        ) {$charset_collate};";
        dbDelta( $sql_audit );
    }

    /**
     * Drops all plugin database tables.
     *
     * WARNING: Use with extreme caution, mainly for development or uninstall.
     *
     * @since 1.0.0
     */
    public static function drop_tables() {
        global $wpdb;
        $table_prefix = $wpdb->prefix . 'rlm_';

        $tables = [
            'requests',
            'aggregates',
            'limits',
            'blacklist',
            'audit',
        ];

        foreach ( $tables as $table ) {
            $wpdb->query( "DROP TABLE IF EXISTS `{$table_prefix}{$table}`" );
        }
    }
}