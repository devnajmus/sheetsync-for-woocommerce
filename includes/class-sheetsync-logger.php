<?php
/**
 * Logs sync activity to the sheetsync_logs database table.
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Logger' ) ) :

class SheetSync_Logger {

    /**
     * Log a sync event.
     */
    public static function log(
        ?int   $connection_id,
        string $sync_type,
        string $status,
        int    $rows_processed = 0,
        int    $rows_skipped   = 0,
        string $message        = '',
        int    $rows_errored   = 0
    ): void {
        global $wpdb;

        $wpdb->insert(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "{$wpdb->prefix}sheetsync_logs",
            array(
                'connection_id'  => $connection_id,
                'sync_type'      => sanitize_text_field( $sync_type ),
                'status'         => in_array( $status, array( 'success', 'error', 'partial' ), true ) ? $status : 'error',
                'rows_processed' => absint( $rows_processed ),
                'rows_skipped'   => absint( $rows_skipped ),
                'rows_errored'   => absint( $rows_errored ),
                'message'        => sanitize_textarea_field( $message ),
            ),
            array( '%d', '%s', '%s', '%d', '%d', '%d', '%s' )
        );

        // Invalidate logs cache so next get_logs() call returns fresh data.
        wp_cache_delete( 'sheetsync_logs_all', 'sheetsync' );
        wp_cache_delete( "sheetsync_logs_{$connection_id}", 'sheetsync' );

        // Prune old logs
        self::prune_old_logs();
    }

    /**
     * Log an error message only.
     */
    public static function error( string $message, ?int $connection_id = null ): void {
        self::log( $connection_id, 'error', 'error', 0, 0, $message );
    }

    /**
     * Get recent logs, optionally filtered by connection.
     */
    public static function get_logs( int $limit = 50, ?int $connection_id = null ): array {
        global $wpdb;

        if ( $connection_id ) {
            $cache_key = "sheetsync_logs_{$connection_id}";
            $results   = wp_cache_get( $cache_key, 'sheetsync' );

            if ( false === $results ) {
                $results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                    $wpdb->prepare(
                        "SELECT l.*, c.name as connection_name
                         FROM {$wpdb->prefix}sheetsync_logs l
                         LEFT JOIN {$wpdb->prefix}sheetsync_connections c ON l.connection_id = c.id
                         WHERE l.connection_id = %d
                         ORDER BY l.created_at DESC
                         LIMIT %d",
                        $connection_id,
                        $limit
                    ),
                    ARRAY_A
                );
                wp_cache_set( $cache_key, $results, 'sheetsync', MINUTE_IN_SECONDS * 5 );
            }

            return $results ?: array();
        }

        $cache_key = 'sheetsync_logs_all';
        $results   = wp_cache_get( $cache_key, 'sheetsync' );

        if ( false === $results ) {
            $results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $wpdb->prepare(
                    "SELECT l.*, c.name as connection_name
                     FROM {$wpdb->prefix}sheetsync_logs l
                     LEFT JOIN {$wpdb->prefix}sheetsync_connections c ON l.connection_id = c.id
                     ORDER BY l.created_at DESC
                     LIMIT %d",
                    $limit
                ),
                ARRAY_A
            );
            wp_cache_set( $cache_key, $results, 'sheetsync', MINUTE_IN_SECONDS * 5 );
        }

        return $results ?: array();
    }

    /**
     * Delete logs older than the retention period.
     */
    private static function prune_old_logs(): void {
        $settings       = get_option( 'sheetsync_settings', array() );
        $retention_days = absint( $settings['log_retention_days'] ?? 30 );

        if ( $retention_days < 1 ) return;

        // Only prune once per day
        if ( get_transient( 'sheetsync_log_pruned' ) ) return;

        global $wpdb;
        $wpdb->query( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "DELETE FROM {$wpdb->prefix}sheetsync_logs
             WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $retention_days
        ) );

        set_transient( 'sheetsync_log_pruned', true, DAY_IN_SECONDS );
    }
}

endif; // class_exists SheetSync_Logger
