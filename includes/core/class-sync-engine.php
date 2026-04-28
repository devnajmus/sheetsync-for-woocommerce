<?php
/**
 * Core sync engine — reads from Google Sheets and updates WooCommerce products.
 * @package SheetSync_For_WooCommerce
 * @since   1.0.0
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Sync_Engine' ) ) :

class SheetSync_Sync_Engine {

    private const BATCH_SIZE = 50;

    /**
     * Run a full sync for a connection.
     *
     * @return array{success: bool, processed: int, skipped: int, errors: int, message: string}
     */
    public function run( int $connection_id ): array {
        global $wpdb;

        // ── Load connection ───────────────────────────────────────────────
        $conn = $wpdb->get_row( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT * FROM {$wpdb->prefix}sheetsync_connections WHERE id = %d AND status = 'active'",
            $connection_id
        ) );

        if ( ! $conn ) {
            return array(
                'success' => false,
                'message' => __( 'Connection not found or inactive.', 'sheetsync-for-woocommerce' ),
            );
        }

        // ══════════════════════════════════════════════════════════════════
        // PRODUCTS CONNECTION (Free: Sheet → WooCommerce only)
        // ══════════════════════════════════════════════════════════════════

        // ── Sheet → WC (pull from Google Sheet, update WooCommerce) ───────
        return $this->sync_sheet_to_wc( $conn, $connection_id );
    }

    /**
     * BUG FIX: This was the missing function.
     * Sheet → WooCommerce: read rows from Google Sheet and update products.
     * Previously this logic was inline in run() but ONLY reached when
     * sync_direction was NOT wc_to_sheets/two_way — which was correct,
     * but the early-return blocks for wc_to_sheets products were placed
     * BEFORE the field map load, causing the sheets_to_wc path to be
     * skipped entirely when the first block matched a different condition.
     *
     * Now cleanly separated into its own method.
     */
    private function sync_sheet_to_wc( object $conn, int $connection_id ): array {

        // Increase PHP limits for large sheets — silently ignored on restricted hosts.
        if ( function_exists( 'set_time_limit' ) ) {
            set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.DiscouragedFunctions.discouraged
        }
        wp_raise_memory_limit( 'admin' );

        // ── Load field maps ───────────────────────────────────────────────
        $maps = SheetSync_Field_Mapper::get_maps( $connection_id );
        if ( empty( $maps ) ) {
            return array(
                'success' => false,
                'message' => __( 'No field mappings configured for this connection.', 'sheetsync-for-woocommerce' ),
            );
        }

        // ── Fetch sheet data ──────────────────────────────────────────────
        try {
            $client   = new SheetSync_Sheets_Client();
            $range    = "{$conn->sheet_name}!A:Z";
            $all_rows = $client->get_rows( $conn->spreadsheet_id, $range );
        } catch ( Exception $e ) {
            SheetSync_Logger::log( $connection_id, 'manual', 'error', 0, 0, $e->getMessage() );
            return array(
                'success' => false,
                'message' => $e->getMessage(),
            );
        }

        if ( empty( $all_rows ) ) {
            return array(
                'success'   => true,
                'processed' => 0,
                'skipped'   => 0,
                'errors'    => 0,
                'message'   => __( 'Sheet is empty.', 'sheetsync-for-woocommerce' ),
            );
        }

        // ── Process rows ──────────────────────────────────────────────────
        $header_row_index = max( 0, (int) $conn->header_row - 1 );
        $data_rows        = array_slice( $all_rows, $header_row_index + 1 );
        $updater          = new SheetSync_Product_Updater( $maps );

        $processed = $skipped = $errors = 0;

        // ── Smart diff: find key field column index for change detection ──
        // Build a hash of each row's data to skip rows that haven't changed
        // since the last sync. This avoids expensive WC product load+save
        // for unchanged rows — critical for large catalogs (1000+ products).
        $last_hashes_option = 'sheetsync_row_hashes_' . $connection_id;
        $last_hashes        = get_option( $last_hashes_option, array() );
        $new_hashes         = array();

        // Find key field column for building per-row hash keys
        $key_col_idx = -1;
        foreach ( $maps as $wc_field => $map_info ) {
            if ( ! empty( $map_info['is_key_field'] ) ) {
                $key_col_idx = SheetSync_Field_Mapper::col_to_index( $map_info['sheet_column'] );
                break;
            }
        }

        foreach ( array_chunk( $data_rows, self::BATCH_SIZE ) as $batch ) {
            foreach ( $batch as $row ) {
                // Skip completely blank rows
                if ( empty( array_filter( $row, fn( $v ) => $v !== '' ) ) ) {
                    continue;
                }

                // Build a unique key for this row (use key field value if available)
                $row_key  = $key_col_idx >= 0 ? ( $row[ $key_col_idx ] ?? '' ) : '';
                $row_hash = md5( implode( '|', $row ) );

                // Store hash for next sync
                if ( $row_key !== '' ) {
                    $new_hashes[ $row_key ] = $row_hash;

                    // Smart diff: skip this row if it hasn't changed since last sync
                    if ( isset( $last_hashes[ $row_key ] ) && $last_hashes[ $row_key ] === $row_hash ) {
                        $skipped++;
                        continue;
                    }
                }

                $result = $updater->update( $row );
                match ( $result ) {
                    'updated' => $processed++,
                    'skipped' => $skipped++,
                    'error'   => $errors++,
                    default   => $skipped++,
                };
            }

            if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
                wp_cache_flush_group( 'sheetsync' );
            } else {
                wp_cache_delete( 'sheetsync_active_connections_products', 'sheetsync' );
                wp_cache_delete( 'sheetsync_active_connections_orders', 'sheetsync' );
            }
        }

        // Save new hashes for next sync (merge so unchanged rows keep their hash)
        $merged_hashes = array_merge( $last_hashes, $new_hashes );
        update_option( $last_hashes_option, $merged_hashes, false );

        // ── Update last sync timestamp ────────────────────────────────────
        global $wpdb;
        $wpdb->update(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "{$wpdb->prefix}sheetsync_connections",
            array( 'last_sync_at' => current_time( 'mysql' ) ),
            array( 'id' => $connection_id ),
            array( '%s' ),
            array( '%d' )
        );
        wp_cache_delete( "sheetsync_connection_{$connection_id}", 'sheetsync' );
        wp_cache_delete( 'sheetsync_active_connections_products', 'sheetsync' );

        // ── Log result ────────────────────────────────────────────────────
        $status  = $errors > 0 && $processed === 0 ? 'error' : ( $errors > 0 ? 'partial' : 'success' );
        $message = sprintf(
            'Processed: %d | Skipped: %d | Errors: %d',
            $processed, $skipped, $errors
        );

        SheetSync_Logger::log( $connection_id, 'manual', $status, $processed, $skipped, $message, $errors );

        return array(
            'success'   => true,
            'processed' => $processed,
            'skipped'   => $skipped,
            'errors'    => $errors,
            'message'   => $message,
        );
    }

    /**
     * Sync ALL WooCommerce products → Google Sheet.
     * Available in SheetSync Pro — https://devnajmus.com/sheetsync/pricing
     *
     * @deprecated This method is not available in the free version.
     */
    private function sync_wc_to_sheet( object $conn ): array {
        return array(
            'success' => false,
            'message' => __( 'WC → Sheets sync is available in SheetSync Pro.', 'sheetsync-for-woocommerce' ),
        );
    }

    /**
     * Placeholder kept for reference — actual implementation in Pro.
     * @codeCoverageIgnore
     */
    public static function get_active_connections( string $type = 'products' ): array {
        global $wpdb;

        $cache_key = "sheetsync_active_connections_{$type}";
        $results   = wp_cache_get( $cache_key, 'sheetsync' );

        if ( false === $results ) {
            if ( $type === 'orders' ) {
                $results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                    $wpdb->prepare(
                        "SELECT * FROM {$wpdb->prefix}sheetsync_connections
                         WHERE status = %s
                         AND (connection_type = %s OR connection_type LIKE %s)",
                        'active',
                        'orders',
                        $wpdb->esc_like( 'orders_' ) . '%'
                    )
                );
            } else {
                $results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                    $wpdb->prepare(
                        "SELECT * FROM {$wpdb->prefix}sheetsync_connections
                         WHERE status = 'active' AND connection_type = %s",
                        $type
                    )
                );
            }
            wp_cache_set( $cache_key, $results, 'sheetsync', MINUTE_IN_SECONDS * 5 );
        }

        return $results ?: array();
    }

    /**
     * Validate a connection type value.
     */
    public static function is_valid_connection_type( string $type ): bool {
        $valid_statuses = array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed', 'draft' );
        if ( in_array( $type, array( 'products', 'orders' ), true ) ) return true;
        if ( str_starts_with( $type, 'orders_' ) ) {
            $status = substr( $type, 7 );
            return in_array( $status, $valid_statuses, true );
        }
        return false;
    }

    public static function get_order_status_filter( string $connection_type ): ?string {
        if ( $connection_type === 'orders' ) return null;
        if ( str_starts_with( $connection_type, 'orders_' ) ) {
            return substr( $connection_type, 7 );
        }
        return null;
    }

    public static function is_orders_type( string $connection_type ): bool {
        return $connection_type === 'orders' || str_starts_with( $connection_type, 'orders_' );
    }

    public static function get_connection( int $id ): ?object {
        global $wpdb;

        $cache_key = "sheetsync_connection_{$id}";
        $result    = wp_cache_get( $cache_key, 'sheetsync' );

        if ( false === $result ) {
            $result = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}sheetsync_connections WHERE id = %d",
                    $id
                )
            );
            wp_cache_set( $cache_key, $result, 'sheetsync', MINUTE_IN_SECONDS * 5 );
        }

        return $result ?: null;
    }

    public static function save_connection( array $data, ?int $id = null ): int {
        global $wpdb;
        $table = "{$wpdb->prefix}sheetsync_connections";

        // Free version: only products type, only sheets_to_wc direction
        $requested_type      = 'products';
        $requested_direction = 'sheets_to_wc';

        $clean = array(
            'name'           => sanitize_text_field( $data['name'] ?? '' ),
            'spreadsheet_id' => sanitize_text_field( $data['spreadsheet_id'] ?? '' ),
            'sheet_name'     => sanitize_text_field( $data['sheet_name'] ?? 'Sheet1' ),
            'header_row'     => max( 1, absint( $data['header_row'] ?? 1 ) ),
            'status'         => in_array( $data['status'] ?? 'active', array( 'active', 'inactive' ), true )
                                ? $data['status'] : 'active',
            'connection_type'=> SheetSync_Sync_Engine::is_valid_connection_type( $requested_type )
                                ? $requested_type : 'products',
            'sync_direction' => in_array( $requested_direction, array( 'sheets_to_wc', 'wc_to_sheets', 'two_way' ), true )
                                ? $requested_direction : 'sheets_to_wc',
        );

        if ( $id ) {
            $wpdb->update( $table, $clean, array( 'id' => $id ), null, array( '%d' ) );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            wp_cache_delete( "sheetsync_connection_{$id}", 'sheetsync' );
            wp_cache_delete( 'sheetsync_active_connections_products', 'sheetsync' );
            wp_cache_delete( 'sheetsync_active_connections_orders', 'sheetsync' );
            return $id;
        }

        $wpdb->insert( $table, $clean );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        wp_cache_delete( 'sheetsync_active_connections_products', 'sheetsync' );
        wp_cache_delete( 'sheetsync_active_connections_orders', 'sheetsync' );
        return (int) $wpdb->insert_id;
    }

    public static function delete_connection( int $id ): void {
        global $wpdb;
        $wpdb->delete( "{$wpdb->prefix}sheetsync_field_maps", array( 'connection_id' => $id ), array( '%d' ) );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        // Clean up smart-diff hashes and per-connection options for this connection
        delete_option( 'sheetsync_row_hashes_' . $id );
        delete_option( 'sheetsync_date_filter_type_'   . $id );
        delete_option( 'sheetsync_date_filter_single_' . $id );
        delete_option( 'sheetsync_date_filter_from_'   . $id );
        delete_option( 'sheetsync_date_filter_to_'     . $id );
        // Remove entries from consolidated sync options and schedules (backwards compatible with older per-connection rows)
        $sync_opts = get_option( 'sheetsync_sync_options', array() );
        if ( isset( $sync_opts[ $id ] ) ) {
            unset( $sync_opts[ $id ] );
            update_option( 'sheetsync_sync_options', $sync_opts, false );
        }
        $schedules = get_option( 'sheetsync_schedules', array() );
        if ( isset( $schedules[ $id ] ) ) {
            unset( $schedules[ $id ] );
            update_option( 'sheetsync_schedules', $schedules, false );
        }
        // Legacy per-connection options cleanup for older installs
        delete_option( 'sheetsync_sync_strategy_' . $id );
        delete_option( 'sheetsync_auto_on_save_'  . $id );
        delete_option( 'sheetsync_schedule_'      . $id );
        $wpdb->delete( "{$wpdb->prefix}sheetsync_connections", array( 'id' => $id ), array( '%d' ) );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        wp_cache_delete( "sheetsync_connection_{$id}", 'sheetsync' );
        wp_cache_delete( 'sheetsync_active_connections_products', 'sheetsync' );
        wp_cache_delete( 'sheetsync_active_connections_orders', 'sheetsync' );
        SheetSync_Field_Mapper::invalidate_cache( $id );
    }

    public static function save_field_maps( int $connection_id, array $field_map ): void {
        global $wpdb;
        $table = "{$wpdb->prefix}sheetsync_field_maps";

        $wpdb->delete( $table, array( 'connection_id' => $connection_id ), array( '%d' ) );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        foreach ( $field_map as $wc_field => $map_data ) {
            $column = strtoupper( preg_replace( '/[^A-Za-z]/', '', $map_data['column'] ?? '' ) );

            if ( empty( $column ) && ! empty( $map_data['key'] ) ) {
                $column = 'A';
            }

            if ( empty( $column ) ) continue;

            // Free version: only allow free fields
            if ( ! array_key_exists( $wc_field, SheetSync_Field_Mapper::FREE_FIELDS ) ) {
                continue;
            }

            $wpdb->insert(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $table,
                array(
                    'connection_id' => $connection_id,
                    'wc_field'      => sanitize_key( $wc_field ),
                    'sheet_column'  => $column,
                    'is_key_field'  => ! empty( $map_data['key'] ) ? 1 : 0,
                ),
                array( '%d', '%s', '%s', '%d' )
            );
        }

        SheetSync_Field_Mapper::invalidate_cache( $connection_id );
    }
}

endif; // class_exists SheetSync_Sync_Engine
