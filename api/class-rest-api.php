<?php
/**
 * REST API endpoints for SheetSync.
 * Namespace: sheetsync/v1
 * @package SheetSync_For_WooCommerce
 * @since   1.0.0
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_REST_API' ) ) :

class SheetSync_REST_API {

    public function register_routes(): void {
        // Webhook endpoint — receives push from Google Apps Script
        register_rest_route( 'sheetsync/v1', '/webhook', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_webhook' ),
            'permission_callback' => '__return_true', // auth done inside
        ) );

        // Sync status endpoint
        register_rest_route( 'sheetsync/v1', '/sync-status/(?P<id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_sync_status' ),
            'permission_callback' => array( $this, 'auth_manage_woocommerce' ),
            'args'                => array(
                'id' => array(
                    'validate_callback' => fn( $v ) => is_numeric( $v ),
                    'sanitize_callback' => 'absint',
                ),
            ),
        ) );
    }

    // ── Webhook handler ────────────────────────────────────────────────────────

    public function handle_webhook( WP_REST_Request $request ): WP_REST_Response {
        // Rate limit: max 60 requests / minute per IP
        if ( ! $this->check_rate_limit() ) {
            return new WP_REST_Response( array( 'error' => __( 'Rate limit exceeded.', 'sheetsync-for-woocommerce' ) ), 429 );
        }

        // Verify shared secret
        $secret = get_option( 'sheetsync_webhook_secret', '' );
        $header = $request->get_header( 'X-SheetSync-Secret' );
        if ( empty( $secret ) || ! hash_equals( $secret, (string) $header ) ) {
            return new WP_REST_Response( array( 'error' => __( 'Unauthorized.', 'sheetsync-for-woocommerce' ) ), 401 );
        }

        // Parse and validate payload
        $payload = $request->get_json_params();
        if ( empty( $payload ) ) {
            return new WP_REST_Response( array( 'error' => __( 'Empty payload.', 'sheetsync-for-woocommerce' ) ), 400 );
        }

        $connection_id = absint( $payload['connection_id'] ?? 0 );
        if ( ! $connection_id ) {
            return new WP_REST_Response( array( 'error' => __( 'Missing connection_id.', 'sheetsync-for-woocommerce' ) ), 400 );
        }

        // Get the row data
        $row = array_map( function( $cell ) {
            // Apps Script sends numbers as numeric types, booleans as bool.
            // sanitize_text_field() on a number/bool works but preserve numeric strings
            // for fields like price, stock quantity.
            if ( is_bool( $cell ) ) return $cell ? 'true' : 'false';
            if ( is_numeric( $cell ) ) return (string) $cell;
            return sanitize_text_field( (string) $cell );
        }, (array) ( $payload['row'] ?? array() ) );
        if ( empty( $row ) ) {
            return new WP_REST_Response( array( 'error' => __( 'Empty row data.', 'sheetsync-for-woocommerce' ) ), 400 );
        }

        // Check connection type — Orders or Products.
        $conn = SheetSync_Sync_Engine::get_connection( $connection_id );
        if ( ! $conn ) {
            return new WP_REST_Response( array( 'error' => __( 'Connection not found.', 'sheetsync-for-woocommerce' ) ), 404 );
        }

        // If Orders connection, update order status.
        // FIX: Check for ALL order connection types (orders, orders_processing, orders_completed, etc.)
        if ( SheetSync_Sync_Engine::is_orders_type( $conn->connection_type ) ) {
            $order_id = absint( $row[0] ?? 0 );
            $status   = strtolower( trim( $row[2] ?? '' ) );

            $allowed = array( 'pending','processing','on-hold','completed','cancelled','refunded','failed' );

            if ( ! $order_id || ! in_array( $status, $allowed, true ) ) {
                return new WP_REST_Response( array( 'result' => 'skipped', 'reason' => __( 'Invalid order ID or status.', 'sheetsync-for-woocommerce' ) ), 200 );
            }

            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                return new WP_REST_Response( array( 'result' => 'skipped', 'reason' => __( 'Order not found.', 'sheetsync-for-woocommerce' ) ), 200 );
            }

            if ( $order->get_status() === $status ) {
                return new WP_REST_Response( array( 'result' => 'skipped', 'reason' => __( 'Same status.', 'sheetsync-for-woocommerce' ) ), 200 );
            }

            $order->update_status( $status, __( 'Updated by SheetSync Auto-Sync.', 'sheetsync-for-woocommerce' ), true );
            SheetSync_Logger::log( $connection_id, 'webhook', 'success', 1, 0, "Order #{$order_id} → {$status}" );
            return new WP_REST_Response( array( 'result' => 'updated', 'order_id' => $order_id, 'status' => $status ), 200 );
        }

        // Products connection
        $maps    = SheetSync_Field_Mapper::get_maps( $connection_id );
        $updater = new SheetSync_Product_Updater( $maps );
        $result  = $updater->update( $row );

        SheetSync_Logger::log(
            $connection_id,
            'webhook',
            $result === 'updated' ? 'success' : ( $result === 'error' ? 'error' : 'partial' ),
            $result === 'updated' ? 1 : 0,
            $result === 'skipped' ? 1 : 0,
            "Webhook: {$result}",
            $result === 'error' ? 1 : 0
        );

        return new WP_REST_Response( array( 'result' => $result ), 200 );
    }

    // ── Sync status ────────────────────────────────────────────────────────────

    public function get_sync_status( WP_REST_Request $request ): WP_REST_Response {
        $conn = SheetSync_Sync_Engine::get_connection( $request->get_param( 'id' ) );
        if ( ! $conn ) {
            return new WP_REST_Response( array( 'error' => __( 'Not found.', 'sheetsync-for-woocommerce' ) ), 404 );
        }

        $logs = SheetSync_Logger::get_logs( 5, $conn->id );
        return new WP_REST_Response( array(
            'connection'   => array(
                'id'           => $conn->id,
                'name'         => $conn->name,
                'status'       => $conn->status,
                'last_sync_at' => $conn->last_sync_at,
            ),
            'recent_logs'  => $logs,
        ), 200 );
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    public function auth_manage_woocommerce(): bool {
        return current_user_can( 'manage_woocommerce' );
    }

    private function check_rate_limit(): bool {
        $ip  = $this->get_client_ip();
        $key = 'sheetsync_rl_' . md5( $ip );

        $data = get_transient( $key );

        if ( false === $data ) {
            // First request — open a fixed 60-second window.
            set_transient( $key, array( 'count' => 1, 'start' => time() ), 60 );
            return true;
        }

        $count = isset( $data['count'] ) ? (int) $data['count'] : 0;

        if ( $count >= 60 ) {
            return false;
        }

        // Increment the counter but preserve the original TTL by recalculating
        // the remaining seconds from the fixed window start time.
        $start     = isset( $data['start'] ) ? (int) $data['start'] : time();
        $remaining = max( 1, 60 - ( time() - $start ) );
        set_transient( $key, array( 'count' => $count + 1, 'start' => $start ), $remaining );

        return true;
    }

    private function get_client_ip(): string {
        // REMOTE_ADDR is the only value that cannot be forged by a client.
        // HTTP_CF_CONNECTING_IP and HTTP_X_FORWARDED_FOR are plain HTTP headers
        // that any attacker can set to any value, trivially bypassing rate limits.
        // If this site runs behind a trusted reverse proxy, configure the real client
        // IP at the server/infrastructure layer — never in application code.
        return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) );
    }
}

endif; // class_exists SheetSync_REST_API
