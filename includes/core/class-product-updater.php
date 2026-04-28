<?php
/**
 * Applies data from a Google Sheets row to a WooCommerce product.
 * @package SheetSync_For_WooCommerce
 * @since   1.0.0
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Product_Updater' ) ) :

class SheetSync_Product_Updater {

    /** @var array<string, array{sheet_column: string, is_key_field: bool}> */
    private array $maps;

    /** @var array<string,int> Pre-built SKU → product ID map for fast lookup */
    private array $sku_index = array();

    public function __construct( array $maps ) {
        $this->maps = $maps;
        $this->build_sku_index();
    }

    /**
     * Build an in-memory SKU → product ID index in ONE database query.
     * This replaces repeated wc_get_product_id_by_sku() calls (one DB query
     * per product) with a single bulk lookup — critical for large catalogs.
     */
    private function build_sku_index(): void {
        global $wpdb;

        // Use a static cache so repeated instantiations within the same request
        // do not re-run the heavy SKU query (important on large catalogs).
        static $sku_index_cache = null;
        if ( $sku_index_cache !== null ) {
            $this->sku_index = $sku_index_cache;
            return;
        }

        $rows = $wpdb->get_results(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT post_id, meta_value AS sku
             FROM {$wpdb->postmeta}
             WHERE meta_key = '_sku'
               AND meta_value != ''",
            ARRAY_A
        );

        foreach ( $rows as $row ) {
            $this->sku_index[ $row['sku'] ] = (int) $row['post_id'];
        }

        $sku_index_cache = $this->sku_index;
    }

    /**
     * Process one sheet row. Returns 'updated'|'skipped'|'error'.
     */
    public function update( array $row ): string {
        // Extract data using field maps
        $data = $this->extract_data( $row );
        if ( empty( $data ) ) return 'skipped';

        // Find the WooCommerce product — create new if not found.
        $product = $this->find_product( $data );
        $is_new  = false;
        if ( ! $product ) {
            // Skip if no SKU or Title.
            if ( empty( $data['_sku'] ) && empty( $data['post_title'] ) ) {
                return 'skipped';
            }
            $product = new WC_Product_Simple();
            $is_new  = true;
        }

        // Apply updates
        try {
            $this->apply_updates( $product, $data );

            // Set default status for new product if not provided.
            if ( $is_new && empty( $data['post_status'] ) ) {
                $product->set_status( 'publish' );
            }

            // BUG FIX: New products with no mapped/populated title were saved
            // with the WooCommerce default name "Product". Set the SKU as the
            // fallback name so the product is at least identifiable.
            if ( $is_new && '' === $product->get_name() ) {
                $fallback_name = ! empty( $data['_sku'] ) ? $data['_sku'] : __( 'Imported Product', 'sheetsync-for-woocommerce' );
                $product->set_name( sanitize_text_field( $fallback_name ) );
            }

            // Prevent two-way sync from re-triggering a sheet push
            // when we save the product here (would cause an infinite loop).
            if ( ! defined( 'SHEETSYNC_DOING_PRODUCT_UPDATE' ) ) {
                define( 'SHEETSYNC_DOING_PRODUCT_UPDATE', true );
            }

            $product->save();

            return 'updated';
        } catch ( Exception $e ) {
            SheetSync_Logger::error( $e->getMessage() );
            return 'error';
        }
    }

    /**
     * Extract mapped data from a raw row.
     *
     * @return array<string, string>
     */
    private function extract_data( array $row ): array {
        $data = array();
        foreach ( $this->maps as $wc_field => $map_info ) {
            $col_index = SheetSync_Field_Mapper::col_to_index( $map_info['sheet_column'] );
            $value     = $row[ $col_index ] ?? '';
            if ( $value !== '' ) {
                $data[ $wc_field ] = $value;
            }
        }
        return $data;
    }

    /**
     * Find a WooCommerce product matching the row's key field(s).
     *
     * BUG FIX: Previously find_product() only checked $data['_sku'] hardcoded.
     * If _sku was not the key field (or not mapped), products were never found
     * and new duplicates were created on every sync.
     *
     * Now: first checks the field marked as is_key_field in field maps,
     * then falls back to _sku lookup, then falls back to title match.
     */
    private function find_product( array $data ): ?WC_Product {

        // ── Step 1: Use the field marked as key field in Field Mapping ────
        foreach ( $this->maps as $wc_field => $map_info ) {
            if ( empty( $map_info['is_key_field'] ) ) continue;
            if ( empty( $data[ $wc_field ] ) ) continue;

            $key_value = sanitize_text_field( $data[ $wc_field ] );

            // Key field is SKU — use pre-built index for O(1) lookup
            if ( $wc_field === '_sku' ) {
                $id = $this->sku_index[ $key_value ] ?? wc_get_product_id_by_sku( $key_value );
                if ( $id ) return wc_get_product( $id );
            }

            // Key field is Product Title
            if ( $wc_field === 'post_title' ) {
                $posts = get_posts( array(
                    'post_type'   => 'product',
                    'post_status' => 'any',
                    'title'       => $key_value,
                    'fields'      => 'ids',
                    'numberposts' => 1,
                ) );
                if ( ! empty( $posts ) ) return wc_get_product( $posts[0] );
            }

            break; // Only use first key field found
        }

        // ── Step 2: Fallback — try SKU match using pre-built index ─────
        if ( ! empty( $data['_sku'] ) ) {
            $sku = sanitize_text_field( $data['_sku'] );
            $id  = $this->sku_index[ $sku ] ?? wc_get_product_id_by_sku( $sku );
            if ( $id ) return wc_get_product( $id );
        }

        // ── Step 3: Fallback — try exact title match ───────────────────────
        if ( ! empty( $data['post_title'] ) ) {
            $posts = get_posts( array(
                'post_type'   => 'product',
                'post_status' => 'any',
                'title'       => sanitize_text_field( $data['post_title'] ),
                'fields'      => 'ids',
                'numberposts' => 1,
            ) );
            if ( ! empty( $posts ) ) return wc_get_product( $posts[0] );
        }

        return null;
    }

    /**
     * Apply all mapped field updates to the product object.
     * Does NOT call $product->save() — caller handles that.
     */
    public function apply_updates( WC_Product $product, array $data ): void {
        foreach ( $data as $field => $value ) {
            $this->apply_field( $product, $field, $value );
        }
    }

    /**
     * Apply a single field update.
     */
    private function apply_field( WC_Product $product, string $field, string $value ): void {
        switch ( $field ) {
            // ── Free fields ───────────────────────────────────────────
            case 'post_title':
                $product->set_name( sanitize_text_field( $value ) );
                break;

            case '_sku':
                $product->set_sku( sanitize_text_field( $value ) );
                break;

            case '_regular_price':
                $price = wc_format_decimal( $value );
                if ( is_numeric( $price ) ) {
                    $product->set_regular_price( $price );
                    // If no sale price is active, set_price must also be updated
                    // so the product's displayed price reflects the new regular price.
                    if ( '' === $product->get_sale_price() ) {
                        $product->set_price( $price );
                    }
                }
                break;

            case '_stock':
                $qty = (int) $value;
                $product->set_manage_stock( true );
                $product->set_stock_quantity( $qty );
                // Only auto-set stock status if _stock_status is NOT also mapped
                // (prevents conflict when both fields are mapped — _stock_status takes priority)
                if ( ! isset( $this->maps['_stock_status'] ) ) {
                    $product->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
                }
                break;

            case 'post_status':
                $status = in_array( strtolower( $value ), array( 'publish', 'draft', 'private' ), true )
                    ? strtolower( $value ) : 'draft';
                $product->set_status( $status );
                break;

            // ── Pro fields ────────────────────────────────────────────
            // Premium fields are intentionally no-ops in the Free plugin.
            // The Pro plugin implements these behaviors when active.
            case '_sale_price':
            case 'post_excerpt':
            case '_stock_status':
            case '_weight':
            case 'post_content':
            case '_product_image':
            case '_gallery_images':
            case '_product_type':
            case '_product_cats':
            case '_product_tags':
                // No-op in Free version: premium updates handled by Pro plugin.
                break;
        }
    }

    /**
     * Gallery images — set from comma-separated URLs.
     *
     * FIX H-3: Each sideloaded attachment is MIME-validated before use.
     * Files that are not safe raster images are deleted immediately.
     *
     * @param WC_Product $product     Product to update.
     * @param string     $urls_string Comma-separated list of image URLs.
     * @return void
     */
    private function set_gallery_images_from_urls( WC_Product $product, string $urls_string ): void {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $allowed_mimes = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
        $urls          = array_filter( array_map( 'trim', explode( ',', $urls_string ) ) );
        $attach_ids    = array();

        foreach ( $urls as $url ) {
            if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
                continue;
            }
            // Ensure only http/https schemes are allowed to prevent SSRF via file://, php://, etc.
            $scheme = strtolower( wp_parse_url( $url, PHP_URL_SCHEME ) ?? '' );
            if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
                continue;
            }

            // Guard: HEAD-check file size before downloading.
            $head           = wp_remote_head( $url, array( 'timeout' => 5, 'redirection' => 3 ) );
            $content_length = is_wp_error( $head ) ? 0 : (int) wp_remote_retrieve_header( $head, 'content-length' );
            if ( $content_length > 5 * MB_IN_BYTES ) {
                continue; // Skip files > 5 MB.
            }

            $id = media_sideload_image( $url, $product->get_id(), null, 'id' );
            if ( is_wp_error( $id ) ) {
                continue;
            }

            // FIX H-3: Validate MIME type — reject anything that isn't a safe raster image.
            $mime = (string) get_post_mime_type( $id );
            if ( ! in_array( $mime, $allowed_mimes, true ) ) {
                wp_delete_attachment( $id, true );
                continue;
            }

            $attach_ids[] = $id;
        }

        if ( ! empty( $attach_ids ) ) {
            $product->set_gallery_image_ids( $attach_ids );
        }
    }

    /**
     * Import an image from a URL and set it as the product thumbnail.
     *
     * FIX H-3: After sideloading, the attachment MIME type is validated.
     * If the file is not a safe raster image (e.g. PHP script, SVG with JS),
     * the attachment is deleted immediately and the product image is not updated.
     *
     * @param WC_Product $product Product to update.
     * @param string     $url     Pre-validated remote image URL.
     * @return void
     */
    private function set_product_image_from_url( WC_Product $product, string $url ): void {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Guard: HEAD-check file size before downloading.
        $head           = wp_remote_head( $url, array( 'timeout' => 5, 'redirection' => 3 ) );
        $content_length = is_wp_error( $head ) ? 0 : (int) wp_remote_retrieve_header( $head, 'content-length' );
        if ( $content_length > 5 * MB_IN_BYTES ) {
            return; // Skip files > 5 MB.
        }

        $attachment_id = media_sideload_image( $url, $product->get_id(), null, 'id' );
        if ( is_wp_error( $attachment_id ) ) {
            return;
        }

        // FIX H-3: Validate MIME type — only safe raster formats accepted.
        $allowed_mimes = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
        $mime          = (string) get_post_mime_type( $attachment_id );
        if ( ! in_array( $mime, $allowed_mimes, true ) ) {
            wp_delete_attachment( $attachment_id, true );
            SheetSync_Logger::error(
                /* translators: 1: MIME type, 2: URL */
                sprintf( __( 'Rejected image with disallowed MIME type "%1$s" from: %2$s', 'sheetsync-for-woocommerce' ), $mime, $url )
            );
            return;
        }

        $product->set_image_id( $attachment_id );
    }

    /**
     * Set product categories from comma-separated string.
     */
    private function set_product_categories( WC_Product $product, string $cats_string ): void {
        $cat_names = array_filter( array_map( 'trim', explode( ',', $cats_string ) ) );
        $term_ids  = array();

        foreach ( $cat_names as $name ) {
            $term = get_term_by( 'name', $name, 'product_cat' );
            if ( ! $term ) {
                // Create the category if it doesn't exist
                $result = wp_insert_term( $name, 'product_cat' );
                if ( ! is_wp_error( $result ) ) {
                    $term_ids[] = $result['term_id'];
                }
            } else {
                $term_ids[] = $term->term_id;
            }
        }

        if ( ! empty( $term_ids ) ) {
            $product->set_category_ids( $term_ids );
        }
    }

    /**
     * Set product tags from comma-separated string.
     */
    private function set_product_tags( WC_Product $product, string $tags_string ): void {
        $tag_names = array_filter( array_map( 'trim', explode( ',', $tags_string ) ) );
        $term_ids  = array();

        foreach ( $tag_names as $name ) {
            $term = get_term_by( 'name', $name, 'product_tag' );
            if ( ! $term ) {
                $result = wp_insert_term( $name, 'product_tag' );
                if ( ! is_wp_error( $result ) ) {
                    $term_ids[] = $result['term_id'];
                }
            } else {
                $term_ids[] = $term->term_id;
            }
        }

        if ( ! empty( $term_ids ) ) {
            $product->set_tag_ids( $term_ids );
        }
    }

    /**
     * Build a row array for writing a product TO Google Sheets (two-way sync).
     */
    public function product_to_row( WC_Product $product ): array {
        // Find max column index needed
        $max_col = 0;
        foreach ( $this->maps as $map_info ) {
            $idx     = SheetSync_Field_Mapper::col_to_index( $map_info['sheet_column'] );
            $max_col = max( $max_col, $idx );
        }

        $row = array_fill( 0, $max_col + 1, '' );

        foreach ( $this->maps as $field => $map_info ) {
            $idx       = SheetSync_Field_Mapper::col_to_index( $map_info['sheet_column'] );
            $row[$idx] = $this->get_product_field_value( $product, $field );
        }

        return $row;
    }

    /**
     * Truncate a string for Google Sheets display.
     * Google Sheets auto-expands row height when cell content is long,
     * even with CLIP wrap strategy and forced pixelSize.
     * We cap at 150 chars — a readable preview that keeps rows compact.
     */
    private static function truncate_for_sheet( string $text, int $max = 150 ): string {
        $text = trim( $text );
        if ( mb_strlen( $text ) <= $max ) {
            return $text;
        }
        return mb_substr( $text, 0, $max - 3 ) . '...';
    }

    /**
     * Read a field value from a WC product.
     */
    private function get_product_field_value( WC_Product $product, string $field ): string {
        return match ( $field ) {
            '_sku'            => (string) $product->get_sku(),
            'post_title'      => (string) $product->get_name(),
            '_regular_price'  => (string) $product->get_regular_price(),
            '_sale_price'     => (string) $product->get_sale_price(),
            '_stock'          => (string) $product->get_stock_quantity(),
            'post_status'     => (string) $product->get_status(),
            'post_content'    => self::truncate_for_sheet( wp_strip_all_tags( (string) $product->get_description() ) ),
            'post_excerpt'    => self::truncate_for_sheet( wp_strip_all_tags( (string) $product->get_short_description() ) ),
            '_stock_status'   => (string) $product->get_stock_status(),
            '_weight'         => (string) $product->get_weight(),
            '_length'         => (string) $product->get_length(),
            '_width'          => (string) $product->get_width(),
            '_height'         => (string) $product->get_height(),
            '_product_type'   => (string) $product->get_type(),
            '_product_image'  => (string) wp_get_attachment_url( $product->get_image_id() ),
            '_gallery_images' => implode( ', ', array_filter( array_map(
                'wp_get_attachment_url', $product->get_gallery_image_ids()
            ) ) ),
            '_product_cats'   => implode( ', ', wp_list_pluck(
                get_the_terms( $product->get_id(), 'product_cat' ) ?: array(), 'name'
            ) ),
            '_product_tags'   => implode( ', ', wp_list_pluck(
                get_the_terms( $product->get_id(), 'product_tag' ) ?: array(), 'name'
            ) ),
            default           => '',
        };
    }
}

endif; // class_exists SheetSync_Product_Updater
