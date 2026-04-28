<?php
/**
 * Resolves and caches field mappings for a connection.
 * @package SheetSync_For_WooCommerce
 * @since   1.0.0
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Field_Mapper' ) ) :

class SheetSync_Field_Mapper {

    /** Free fields available without Pro */
    public const FREE_FIELDS = array(
        '_sku'           => 'SKU (Product Key)',
        'post_title'     => 'Product Title',
        '_regular_price' => 'Regular Price',
        '_stock'         => 'Stock Quantity',
        'post_status'    => 'Product Status (publish/draft)',
    );

    /** Order fields for order sync sheet */
    public const ORDER_FIELDS = array(
        'order_id'           => 'Order ID',
        'order_date'         => 'Order Date',
        'order_status'       => 'Order Status',
        'customer_name'      => 'Customer Name',
        'billing_email'      => 'Billing Email',
        'billing_phone'      => 'Billing Phone',
        'billing_address'    => 'Billing Address',
        'order_total'        => 'Order Total',
        'payment_method'     => 'Payment Method',
        'items_summary'      => 'Items Summary',
        'shipping_method'    => 'Shipping Method',
        'customer_note'      => 'Customer Note',
    );

    /**
     * Get field maps for a connection, keyed by wc_field.
     *
     * @return array<string, array{sheet_column: string, is_key_field: bool}>
     */
    public static function get_maps( int $connection_id ): array {
        global $wpdb;

        $cache_key = "sheetsync_maps_{$connection_id}";
        $cached    = wp_cache_get( $cache_key, 'sheetsync' ); // FIX: added 'sheetsync' group
        if ( $cached !== false ) return $cached;

        $rows = $wpdb->get_results( $wpdb->prepare(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT wc_field, sheet_column, is_key_field
             FROM {$wpdb->prefix}sheetsync_field_maps
             WHERE connection_id = %d",
            $connection_id
        ), ARRAY_A );

        $maps = array();
        foreach ( $rows as $row ) {
            $maps[ $row['wc_field'] ] = array(
                'sheet_column' => strtoupper( $row['sheet_column'] ),
                'is_key_field' => (bool) $row['is_key_field'],
            );
        }

        wp_cache_set( $cache_key, $maps, 'sheetsync', 5 * MINUTE_IN_SECONDS ); // FIX: added 'sheetsync' group
        return $maps;
    }

    /**
     * Build a column-letter → array-index map from a header row.
     *
     * @param array $header_row  Raw row from Sheets e.g. ['SKU','Title','Price']
     * @return array<string, int>
     */
    public static function build_col_index( array $header_row ): array {
        $index = array();
        foreach ( $header_row as $i => $label ) {
            // Map by column letter (A, B, C…)
            $letter = self::index_to_col( $i );
            $index[ $letter ] = $i;
        }
        return $index;
    }

    /**
     * Convert 0-based column index to letter (0→A, 25→Z, 26→AA…).
     */
    public static function index_to_col( int $index ): string {
        $letter = '';
        $index++;
        while ( $index > 0 ) {
            $index--;
            $letter = chr( 65 + ( $index % 26 ) ) . $letter;
            $index  = intdiv( $index, 26 );
        }
        return $letter;
    }

    /**
     * Convert column letter to 0-based index (A→0, B→1, AA→26…).
     */
    public static function col_to_index( string $col ): int {
        $col = strtoupper( trim( $col ) );
        $n   = 0;
        foreach ( str_split( $col ) as $c ) {
            $n = $n * 26 + ( ord( $c ) - ord( 'A' ) + 1 );
        }
        return $n - 1;
    }

    /**
     * Get all available fields for the free version.
     * Pro fields are provided by the SheetSync Pro add-on.
     */
    public static function get_available_fields( bool $include_pro = false ): array {
        return self::FREE_FIELDS;
    }

    /**
     * Invalidate the cache for a connection's maps.
     */
    public static function invalidate_cache( int $connection_id ): void {
        wp_cache_delete( "sheetsync_maps_{$connection_id}", 'sheetsync' ); // FIX: added 'sheetsync' group
    }
}

endif; // class_exists SheetSync_Field_Mapper
