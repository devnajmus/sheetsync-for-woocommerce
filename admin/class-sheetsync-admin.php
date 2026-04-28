<?php
/**
 * Admin controller — menus, settings, AJAX handlers, asset enqueuing.
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Admin' ) ) :

class SheetSync_Admin {

    public function __construct() {
        add_action( 'admin_menu',            array( $this, 'register_menus' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_post_sheetsync_save_settings',    array( $this, 'handle_save_settings' ) );
        add_action( 'admin_post_sheetsync_save_connection',  array( $this, 'handle_save_connection' ) );
        add_action( 'admin_post_sheetsync_delete_connection',array( $this, 'handle_delete_connection' ) );
        add_action( 'admin_post_sheetsync_save_field_map',   array( $this, 'handle_save_field_map' ) );
        add_action( 'admin_post_sheetsync_toggle_auto_sync',  array( $this, 'handle_toggle_auto_sync' ) );
        add_action( 'admin_post_sheetsync_save_sync_options', array( $this, 'handle_save_sync_options' ) );
        add_action( 'admin_post_sheetsync_save_schedule',     array( $this, 'handle_save_schedule_proxy' ) );

        // AJAX
        add_action( 'wp_ajax_sheetsync_manual_sync',    array( $this, 'ajax_manual_sync' ) );
        add_action( 'wp_ajax_sheetsync_get_headers',     array( $this, 'ajax_get_headers' ) );
        add_action( 'wp_ajax_sheetsync_import_from_sheet', array( $this, 'ajax_import_from_sheet' ) );
        add_action( 'wp_ajax_sheetsync_test_connection',array( $this, 'ajax_test_connection' ) );
        add_action( 'wp_ajax_sheetsync_dismiss_notice', array( $this, 'ajax_dismiss_notice' ) );
        add_action( 'wp_ajax_sheetsync_import_headers',  array( $this, 'ajax_import_headers' ) );

        // Dashboard settings persistence
        add_action( 'wp_ajax_sheetsync_save_dashboard_settings', array( $this, 'ajax_save_dashboard_settings' ) );
        add_action( 'wp_ajax_sheetsync_load_dashboard_settings', array( $this, 'ajax_load_dashboard_settings' ) );

        // Admin notices
        add_action( 'admin_notices', array( $this, 'show_admin_notices' ) );
    }

    // ─── Menus ────────────────────────────────────────────────────────────────

    public function register_menus(): void {
        // FIX L-1: Validate the filtered capability against a strict allowlist.
        // Without this, a misconfigured plugin could set the cap to 'read',
        // exposing the entire SheetSync admin to any logged-in subscriber.
        $allowed_caps = array( 'manage_woocommerce', 'manage_options', 'edit_shop_orders' );
        $filtered_cap = apply_filters( 'sheetsync_admin_capability', 'manage_woocommerce' );
        $cap          = in_array( $filtered_cap, $allowed_caps, true ) ? $filtered_cap : 'manage_woocommerce';

        add_menu_page(
            __( 'SheetSync', 'sheetsync-for-woocommerce' ),
            __( 'SheetSync', 'sheetsync-for-woocommerce' ),
            $cap,
            'sheetsync',
            array( $this, 'render_connections_page' ),
            'dashicons-table-col-after',
            56
        );

        add_submenu_page(
            'sheetsync',
            __( 'Connections', 'sheetsync-for-woocommerce' ),
            __( 'Connections', 'sheetsync-for-woocommerce' ),
            $cap,
            'sheetsync',
            array( $this, 'render_connections_page' )
        );

        add_submenu_page(
            'sheetsync',
            __( 'Settings', 'sheetsync-for-woocommerce' ),
            __( 'Settings', 'sheetsync-for-woocommerce' ),
            $cap,
            'sheetsync-settings',
            array( $this, 'render_settings_page' )
        );

        add_submenu_page(
            'sheetsync',
            __( 'Sync Logs', 'sheetsync-for-woocommerce' ),
            __( 'Sync Logs', 'sheetsync-for-woocommerce' ),
            $cap,
            'sheetsync-logs',
            array( $this, 'render_logs_page' )
        );

        // Register Pro pages so the admin menu shows upgrade paths in Free.
        // The actual page renderers will display a pro-gate notice when
        // `sheetsync_is_pro()` is false, ensuring no premium implementation
        // runs in the Free plugin.
        add_submenu_page(
            'sheetsync',
            __( 'Import/Export', 'sheetsync-for-woocommerce' ),
            __( 'Import/Export', 'sheetsync-for-woocommerce' ),
            $cap,
            'sheetsync-import-export',
            array( $this, 'render_import_export_page' )
        );

        add_submenu_page(
            'sheetsync',
            __( 'Dashboards', 'sheetsync-for-woocommerce' ),
            __( 'Dashboards', 'sheetsync-for-woocommerce' ),
            $cap,
            'sheetsync-dashboards',
            array( $this, 'render_dashboards_page' )
        );
    }

    // ─── Assets ───────────────────────────────────────────────────────────────

    public function enqueue_assets( string $hook ): void {
        $sheetsync_pages = array(
            'toplevel_page_sheetsync',
            'sheetsync_page_sheetsync-settings',
            'sheetsync_page_sheetsync-logs',
        );

        $pro_pages = array(
            'sheetsync_page_sheetsync-import-export',
            'sheetsync_page_sheetsync-dashboards',
        );

        $is_sheetsync_page = in_array( $hook, $sheetsync_pages, true )
            || ( isset( $_GET['page'] ) && strpos( sanitize_text_field( wp_unslash( $_GET['page'] ?? '' ) ), 'sheetsync' ) !== false ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $is_pro_page = in_array( $hook, $pro_pages, true )
            || ( isset( $_GET['page'] ) && in_array( sanitize_text_field( wp_unslash( $_GET['page'] ?? '' ) ), array( 'sheetsync-import-export', 'sheetsync-dashboards' ), true ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( ! $is_sheetsync_page && ! $is_pro_page ) {
            return;
        }

        // If this is a pro-only page and the site is not Pro, avoid loading
        // the plugin's admin assets. The renderer will show an upgrade notice
        // without pulling in Pro feature code or scripts.
        if ( $is_pro_page && ( ! function_exists( 'sheetsync_is_pro' ) || ! sheetsync_is_pro() ) ) {
            return;
        }

        wp_enqueue_style(
            'sheetsync-admin',
            SHEETSYNC_URL . 'admin/css/admin-style.css',
            array(),
            SHEETSYNC_VERSION
        );

        wp_enqueue_script(
            'sheetsync-admin',
            SHEETSYNC_URL . 'admin/js/admin-script.js',
            array( 'jquery' ),
            SHEETSYNC_VERSION,
            true
        );

        // FIX H-5: All user-facing JS strings are localised here.
        // No hardcoded Bengali (or any other language) strings may appear in JS files.
        wp_localize_script( 'sheetsync-admin', 'sheetsync', array(
            'ajax_url'    => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( 'sheetsync_nonce' ),
            'is_pro'      => sheetsync_is_pro(),
            'upgrade_url' => sheetsync_upgrade_url(),
            'i18n'        => array(
                'syncing'                 => __( 'Syncing…', 'sheetsync-for-woocommerce' ),
                'sync_complete'           => __( 'Sync Complete!', 'sheetsync-for-woocommerce' ),
                'sync_error'              => __( 'Sync failed. Check logs.', 'sheetsync-for-woocommerce' ),
                'testing'                 => __( 'Testing…', 'sheetsync-for-woocommerce' ),
                'test_connection'         => __( 'Test Connection', 'sheetsync-for-woocommerce' ),
                'connection_failed'       => __( 'Connection failed.', 'sheetsync-for-woocommerce' ),
                'request_failed'          => __( 'Request failed.', 'sheetsync-for-woocommerce' ),
                'copy'                    => __( 'Copy', 'sheetsync-for-woocommerce' ),
                'copied'                  => __( 'Copied!', 'sheetsync-for-woocommerce' ),
                'reveal'                  => __( 'Reveal', 'sheetsync-for-woocommerce' ),
                'hide'                    => __( 'Hide', 'sheetsync-for-woocommerce' ),
                'import_failed'           => __( 'Import failed.', 'sheetsync-for-woocommerce' ),
                'headers_written'         => __( 'Headers written to Google Sheet! Column mapping applied (A, B, C\u2026).', 'sheetsync-for-woocommerce' ),
                'connected_to'            => __( '\u2713 Connected to: ', 'sheetsync-for-woocommerce' ),
                'please_enter_spreadsheet'=> __( 'Please enter a Spreadsheet ID first.', 'sheetsync-for-woocommerce' ),
                'error_generic'         => __( 'Error', 'sheetsync-for-woocommerce' ),
                'headers_load_failed'   => __( 'Failed to load headers:', 'sheetsync-for-woocommerce' ),
                'unknown_error'         => __( 'Unknown error', 'sheetsync-for-woocommerce' ),
                'confirm_delete'          => __( 'Delete this connection and all its field maps?', 'sheetsync-for-woocommerce' ),
                // H-5: Previously hardcoded in Bengali — now properly translatable.
                'field_map_required'      => __( 'Please enter a Sheet Column for at least one field (e.g. A, B, C).', 'sheetsync-for-woocommerce' ),
                'key_field_empty_confirm' => __( 'A Key Field is checked but its Sheet Column is empty. Continue anyway?', 'sheetsync-for-woocommerce' ),
                'importing'               => __( 'Importing\u2026', 'sheetsync-for-woocommerce' ),
                'fields_matched'          => __( 'field(s) matched!', 'sheetsync-for-woocommerce' ),
                'unmatched'               => __( 'unmatched', 'sheetsync-for-woocommerce' ),
                'synced'                  => __( 'synced',    'sheetsync-for-woocommerce' ),
                'unchanged'               => __( 'unchanged', 'sheetsync-for-woocommerce' ),
                'errors_lbl'              => __( 'errors',    'sheetsync-for-woocommerce' ),
                // Additional UI strings used by admin JS
                'google_quota_exceeded'  => __( 'Google API quota exceeded. Please wait a moment and try again.', 'sheetsync-for-woocommerce' ),
                'please_select_connection'=> __( 'Please select a connection first.', 'sheetsync-for-woocommerce' ),
                'sku_map_warning'        => __( 'Please map the SKU column — otherwise duplicate products will be created!', 'sheetsync-for-woocommerce' ),
                'loading_preview'        => __( 'Loading preview\u2026', 'sheetsync-for-woocommerce' ),
                'exporting'              => __( 'Exporting\u2026', 'sheetsync-for-woocommerce' ),
                'counting'               => __( 'Counting\u2026', 'sheetsync-for-woocommerce' ),
                'generating_csv'         => __( 'Generating CSV\u2026', 'sheetsync-for-woocommerce' ),
                'conn_type_labels'        => array(
                    'orders'            => __( 'All Orders', 'sheetsync-for-woocommerce' ),
                    'orders_pending'    => __( 'Pending Payment', 'sheetsync-for-woocommerce' ),
                    'orders_processing' => __( 'Processing', 'sheetsync-for-woocommerce' ),
                    'orders_on-hold'    => __( 'On Hold', 'sheetsync-for-woocommerce' ),
                    'orders_completed'  => __( 'Completed', 'sheetsync-for-woocommerce' ),
                    'orders_cancelled'  => __( 'Cancelled', 'sheetsync-for-woocommerce' ),
                    'orders_refunded'   => __( 'Refunded', 'sheetsync-for-woocommerce' ),
                    'orders_failed'     => __( 'Failed', 'sheetsync-for-woocommerce' ),
                    'orders_draft'      => __( 'Draft', 'sheetsync-for-woocommerce' ),
                    'products'          => __( 'Products', 'sheetsync-for-woocommerce' ),
                ),
            ),
        ) );
    }

    // ─── Page renderers ───────────────────────────────────────────────────────

    public function render_connections_page(): void {
        // FIX M-1: Explicit capability check on the render path, not just the menu registration.
        $cap = apply_filters( 'sheetsync_admin_capability', 'manage_woocommerce' );
        if ( ! current_user_can( $cap ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'sheetsync-for-woocommerce' ), 403 );
        }

        global $wpdb;

        $action  = sanitize_text_field( wp_unslash( $_GET['sheetsync_action'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $conn_id = absint( wp_unslash( $_GET['connection_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( $action === 'edit' && $conn_id ) {
            $connection = SheetSync_Sync_Engine::get_connection( $conn_id );
            $field_maps = SheetSync_Field_Mapper::get_maps( $conn_id );
            require SHEETSYNC_DIR . 'admin/partials/edit-connection.php';
            return;
        }

        if ( $action === 'new' ) {
            $connection = null;
            $field_maps = array();
            require SHEETSYNC_DIR . 'admin/partials/edit-connection.php';
            return;
        }

        // List all connections
        $cache_key   = 'sheetsync_all_connections';
        $connections = wp_cache_get( $cache_key, 'sheetsync' );
        if ( false === $connections ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $connections = $wpdb->get_results(
                "SELECT * FROM {$wpdb->prefix}sheetsync_connections ORDER BY created_at DESC" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            );
            wp_cache_set( $cache_key, $connections, 'sheetsync', MINUTE_IN_SECONDS * 5 );
        }
        require SHEETSYNC_DIR . 'admin/partials/connections-list.php';
    }

    public function render_settings_page(): void {
        $cap = apply_filters( 'sheetsync_admin_capability', 'manage_woocommerce' );
        if ( ! current_user_can( $cap ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'sheetsync-for-woocommerce' ), 403 );
        }

        $settings         = get_option( 'sheetsync_settings', array() );
        $account_email    = SheetSync_Google_Auth::get_account_email();
        $webhook_secret   = get_option( 'sheetsync_webhook_secret', '' );
        $webhook_endpoint = rest_url( 'sheetsync/v1/webhook' );
        require SHEETSYNC_DIR . 'admin/partials/settings-page.php';
    }

    public function render_logs_page(): void {
        $cap = apply_filters( 'sheetsync_admin_capability', 'manage_woocommerce' );
        if ( ! current_user_can( $cap ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'sheetsync-for-woocommerce' ), 403 );
        }

        $conn_id = absint( wp_unslash( $_GET['connection_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $logs    = SheetSync_Logger::get_logs( 100, $conn_id ?: null );
        require SHEETSYNC_DIR . 'admin/partials/logs-page.php';
    }

    public function render_import_export_page(): void {
        require SHEETSYNC_DIR . 'admin/partials/import-export-page.php';
    }

    public function render_dashboards_page(): void {
        require SHEETSYNC_DIR . 'admin/partials/dashboards-page.php';
    }

    // ─── Form handlers ────────────────────────────────────────────────────────

    public function handle_save_settings(): void {
        check_admin_referer( 'sheetsync_save_settings' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( esc_html__( 'Forbidden', 'sheetsync-for-woocommerce' ), 403 );

        // Save Service Account JSON
        $json = sanitize_textarea_field( wp_unslash( $_POST['service_account_json'] ?? '' ) );
        if ( ! empty( $json ) ) {
            if ( ! SheetSync_Google_Auth::save_credentials( $json ) ) {
                $this->add_notice( 'error', __( 'Invalid Service Account JSON. Please check the file.', 'sheetsync-for-woocommerce' ) );
                wp_safe_redirect( admin_url( 'admin.php?page=sheetsync-settings' ) );
                exit;
            }
        }

        // General settings
        $settings = array(
            'batch_size'          => max( 1, min( 500, absint( $_POST['batch_size'] ?? 50 ) ) ),
            'log_retention_days'  => max( 1, absint( $_POST['log_retention_days'] ?? 30 ) ),
            'email_notifications' => ! empty( $_POST['email_notifications'] ),
            'notification_email'  => sanitize_email( wp_unslash( $_POST['notification_email'] ?? get_option( 'admin_email' ) ) ),
        );
        update_option( 'sheetsync_settings', $settings, false );

        // Pro Test Mode toggle
        $test_mode = ! empty( $_POST['pro_test_mode'] );
        update_option( 'sheetsync_pro_test_mode', $test_mode, false );

        $this->add_notice( 'success', __( 'Settings saved.', 'sheetsync-for-woocommerce' ) );
        wp_safe_redirect( admin_url( 'admin.php?page=sheetsync-settings' ) );
        exit;
    }

    public function handle_save_connection(): void {
        check_admin_referer( 'sheetsync_save_connection' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( esc_html__( 'Forbidden', 'sheetsync-for-woocommerce' ), 403 );

        $conn_id = absint( $_POST['connection_id'] ?? 0 );

        $data = array(
            'name'            => sanitize_text_field( wp_unslash( $_POST['connection_name'] ?? '' ) ),
            'spreadsheet_id'  => sanitize_text_field( wp_unslash( $_POST['spreadsheet_id'] ?? '' ) ),
            'sheet_name'      => sanitize_text_field( wp_unslash( $_POST['sheet_name'] ?? 'Sheet1' ) ),
            'header_row'      => absint( $_POST['header_row'] ?? 1 ),
            'status'          => sanitize_text_field( wp_unslash( $_POST['status'] ?? 'active' ) ),
            'connection_type' => sanitize_text_field( wp_unslash( $_POST['connection_type'] ?? 'products' ) ),
            'sync_direction'  => sanitize_text_field( wp_unslash( $_POST['sync_direction'] ?? 'sheets_to_wc' ) ),
        );

        $saved_id = SheetSync_Sync_Engine::save_connection( $data, $conn_id ?: null );

        // ── Save date filter settings (stored in wp_options, not DB schema) ─
        if ( SheetSync_Sync_Engine::is_orders_type( $data['connection_type'] ) ) {
            $date_type   = sanitize_text_field( wp_unslash( $_POST['order_date_type']   ?? 'all' ) );
            $date_single = sanitize_text_field( wp_unslash( $_POST['order_date_single'] ?? '' ) );
            $date_from   = sanitize_text_field( wp_unslash( $_POST['order_date_from']   ?? '' ) );
            $date_to     = sanitize_text_field( wp_unslash( $_POST['order_date_to']     ?? '' ) );

            $date_type = in_array( $date_type, array( 'all', 'single', 'range' ), true ) ? $date_type : 'all';

            // Validate date format YYYY-MM-DD
            $date_single = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_single ) ? $date_single : '';
            $date_from   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from   ) ? $date_from   : '';
            $date_to     = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to     ) ? $date_to     : '';

            update_option( 'sheetsync_date_filter_type_'   . $saved_id, $date_type,   false );
            update_option( 'sheetsync_date_filter_single_' . $saved_id, $date_single, false );
            update_option( 'sheetsync_date_filter_from_'   . $saved_id, $date_from,   false );
            update_option( 'sheetsync_date_filter_to_'     . $saved_id, $date_to,     false );
        } else {
            // Products connection — clear any previous date filter options
            delete_option( 'sheetsync_date_filter_type_'   . $saved_id );
            delete_option( 'sheetsync_date_filter_single_' . $saved_id );
            delete_option( 'sheetsync_date_filter_from_'   . $saved_id );
            delete_option( 'sheetsync_date_filter_to_'     . $saved_id );
        }

        // Invalidate connections list cache.
        wp_cache_delete( 'sheetsync_all_connections', 'sheetsync' );

        $this->add_notice( 'success', __( 'Connection saved.', 'sheetsync-for-woocommerce' ) );
        wp_safe_redirect( add_query_arg( array( 'page' => 'sheetsync', 'sheetsync_action' => 'edit', 'connection_id' => absint( $saved_id ) ), admin_url( 'admin.php' ) ) ); // FIX M-3
        exit;
    }

    public function handle_delete_connection(): void {
        check_admin_referer( 'sheetsync_delete_connection' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( esc_html__( 'Forbidden', 'sheetsync-for-woocommerce' ), 403 );

        $conn_id = absint( $_POST['connection_id'] ?? 0 );
        if ( $conn_id ) {
            SheetSync_Sync_Engine::delete_connection( $conn_id );

            // Invalidate connections list cache.
            wp_cache_delete( 'sheetsync_all_connections', 'sheetsync' );

            $this->add_notice( 'success', __( 'Connection deleted.', 'sheetsync-for-woocommerce' ) );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=sheetsync' ) );
        exit;
    }

    public function handle_save_field_map(): void {
        check_admin_referer( 'sheetsync_save_field_map' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( esc_html__( 'Forbidden', 'sheetsync-for-woocommerce' ), 403 );

        $conn_id       = absint( $_POST['connection_id'] ?? 0 );
        $raw_field_map = wp_unslash( $_POST['field_map'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        // BUG FIX: field_map is a nested array (field => [column, key]).
        // Using array_map( 'sanitize_text_field' ) flattens it — must sanitize each sub-array.
        $field_map = array();
        if ( is_array( $raw_field_map ) ) {
            foreach ( $raw_field_map as $wc_field => $map_data ) {
                if ( is_array( $map_data ) ) {
                    $field_map[ sanitize_key( $wc_field ) ] = array(
                        'column' => sanitize_text_field( $map_data['column'] ?? '' ),
                        'key'    => ! empty( $map_data['key'] ) ? 1 : 0,
                    );
                }
            }
        }

        if ( $conn_id && ! empty( $field_map ) ) {
            // If no key field is set at all, default SKU as the key field
            $has_key_field = false;
            foreach ( $field_map as $data ) {
                if ( ! empty( $data['key'] ) ) {
                    $has_key_field = true;
                    break;
                }
            }
            if ( ! $has_key_field && isset( $field_map['_sku'] ) && ! empty( $field_map['_sku']['column'] ) ) {
                $field_map['_sku']['key'] = 1;
            }

            SheetSync_Sync_Engine::save_field_maps( $conn_id, $field_map );
            $this->add_notice( 'success', __( 'Field mapping saved.', 'sheetsync-for-woocommerce' ) );
        }

        wp_safe_redirect( add_query_arg( array( 'page' => 'sheetsync', 'sheetsync_action' => 'edit', 'connection_id' => absint( $conn_id ) ), admin_url( 'admin.php' ) ) . '#tab-field-mapping' ); // FIX M-3
        exit;
    }

    public function handle_toggle_auto_sync(): void {
        check_admin_referer( 'sheetsync_toggle_auto_sync' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( esc_html__( 'Forbidden', 'sheetsync-for-woocommerce' ), 403 );

        $conn_id = absint( $_POST['connection_id'] ?? 0 );
        $enabled = ! empty( $_POST['auto_sync_enabled'] );

        // Store all auto-sync flags in one serialized option to prevent unbounded
        // option table growth (previously wrote one row per connection ID).
        $auto_sync_settings             = get_option( 'sheetsync_auto_sync_settings', array() );
        $auto_sync_settings[ $conn_id ] = (bool) $enabled;
        update_option( 'sheetsync_auto_sync_settings', $auto_sync_settings, false );

        $msg = $enabled ? '✅ ' . __( 'Auto Sync enabled.', 'sheetsync-for-woocommerce' ) : __( 'Auto Sync disabled.', 'sheetsync-for-woocommerce' );
        $this->add_notice( 'success', $msg );
        wp_safe_redirect( add_query_arg( array( 'page' => 'sheetsync', 'sheetsync_action' => 'edit', 'connection_id' => absint( $conn_id ) ), admin_url( 'admin.php' ) ) . '#tab-sync' ); // FIX M-3
        exit;
    }

    /**
     * Save sync strategy + auto-sync-on-save toggle (Free + Pro).
     */
    public function handle_save_sync_options(): void {
        check_admin_referer( 'sheetsync_save_sync_options' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( esc_html__( 'Forbidden', 'sheetsync-for-woocommerce' ), 403 );

        $conn_id  = absint( $_POST['connection_id'] ?? 0 );
        $strategy = sanitize_text_field( wp_unslash( $_POST['sync_strategy'] ?? 'smart' ) );
        $strategy = in_array( $strategy, array( 'smart', 'full' ), true ) ? $strategy : 'smart';

        // Auto sync on product save
        $auto_on_save = ! empty( $_POST['auto_on_save'] ) ? 1 : 0;

        // Consolidate per-connection sync options to avoid option table proliferation.
        $sync_opts = get_option( 'sheetsync_sync_options', array() );
        $sync_opts[ $conn_id ] = array(
            'strategy'     => $strategy,
            'auto_on_save' => (bool) $auto_on_save,
        );
        update_option( 'sheetsync_sync_options', $sync_opts, false );

        $this->add_notice( 'success', __( 'Sync options saved.', 'sheetsync-for-woocommerce' ) );
        wp_safe_redirect( add_query_arg(
            array( 'page' => 'sheetsync', 'sheetsync_action' => 'edit', 'connection_id' => $conn_id ),
            admin_url( 'admin.php' )
        ) . '#tab-sync' );
        exit;
    }

    /**
     * Proxy for schedule save — delegates to SheetSync_Cron_Manager (Pro).
     */
    public function handle_save_schedule_proxy(): void {
        check_admin_referer( 'sheetsync_save_schedule' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( esc_html__( 'Forbidden', 'sheetsync-for-woocommerce' ), 403 );
        if ( ! function_exists( 'sheetsync_is_pro' ) || ! sheetsync_is_pro() ) wp_die( esc_html__( 'Pro required.', 'sheetsync-for-woocommerce' ), 403 );

        // Note: Scheduled sync is a Pro feature. This handler saves settings
        // but the actual cron execution is handled by the Pro add-on.
        $conn_id  = absint( $_POST['connection_id'] ?? 0 );
        $interval = sanitize_text_field( wp_unslash( $_POST['sync_interval'] ?? '' ) );
        $allowed  = array( '', 'sheetsync_15min', 'sheetsync_30min', 'sheetsync_1hour', 'twicedaily', 'daily' );
        if ( ! in_array( $interval, $allowed, true ) ) $interval = '';

        // Consolidate schedules into a single option to avoid one DB row per connection.
        $schedules = get_option( 'sheetsync_schedules', array() );
        if ( $interval === '' ) {
            unset( $schedules[ $conn_id ] );
        } else {
            $schedules[ $conn_id ] = $interval;
        }
        update_option( 'sheetsync_schedules', $schedules, false );
        if ( class_exists( 'SheetSync_Cron_Manager' ) ) {
            SheetSync_Cron_Manager::schedule( $conn_id, $interval );
        }

        $msg = $interval
            ? __( 'Schedule saved.', 'sheetsync-for-woocommerce' )
            : __( 'Schedule disabled.', 'sheetsync-for-woocommerce' );
        $this->add_notice( 'success', $msg );
        wp_safe_redirect( add_query_arg(
            array( 'page' => 'sheetsync', 'sheetsync_action' => 'edit', 'connection_id' => $conn_id ),
            admin_url( 'admin.php' )
        ) . '#tab-sync' );
        exit;
    }

    // ─── AJAX handlers ────────────────────────────────────────────────────────

    public function ajax_manual_sync(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        $connection_id = absint( $_POST['connection_id'] ?? 0 );
        if ( ! $connection_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid connection ID.', 'sheetsync-for-woocommerce' ) ) );
        }

        // Allow JS to override strategy for this single run (full vs smart)
        $strategy_override = sanitize_text_field( wp_unslash( $_POST['sync_strategy'] ?? '' ) );
        if ( in_array( $strategy_override, array( 'smart', 'full' ), true ) ) {
            // Temporarily store override so engine picks it up
            add_filter( 'sheetsync_sync_strategy_' . $connection_id, fn() => $strategy_override );
        }

        $engine = new SheetSync_Sync_Engine();
        $result = $engine->run( $connection_id );

        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    public function ajax_test_connection(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        $spreadsheet_id = sanitize_text_field( wp_unslash( $_POST['spreadsheet_id'] ?? '' ) );
        if ( empty( $spreadsheet_id ) ) {
            wp_send_json_error( array( 'message' => __( 'No spreadsheet ID provided.', 'sheetsync-for-woocommerce' ) ) );
        }

        $result = SheetSync_Google_Auth::test_connection( $spreadsheet_id );
        $result['success'] ? wp_send_json_success( $result ) : wp_send_json_error( $result );
    }

    public function ajax_dismiss_notice(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        $notice_id = sanitize_key( $_POST['notice_id'] ?? '' );
        if ( $notice_id ) {
            update_user_meta( get_current_user_id(), "sheetsync_dismissed_{$notice_id}", true );
        }
        wp_send_json_success();
    }

    // ─── Admin notices ────────────────────────────────────────────────────────

    public function show_admin_notices(): void {
        $notices = get_transient( 'sheetsync_admin_notices_' . get_current_user_id() );
        if ( ! $notices ) return;

        foreach ( $notices as $notice ) {
            $type    = esc_attr( $notice['type'] ?? 'info' );
            $message = esc_html( $notice['message'] ?? '' );
            echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
        }

        delete_transient( 'sheetsync_admin_notices_' . get_current_user_id() );
    }

    private function add_notice( string $type, string $message ): void {
        $key     = 'sheetsync_admin_notices_' . get_current_user_id();
        $notices = get_transient( $key ) ?: array();
        $notices[] = array( 'type' => $type, 'message' => $message );
        set_transient( $key, $notices, 60 );
    }

    public function ajax_import_headers(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( array( 'message' => __( 'Forbidden', 'sheetsync-for-woocommerce' ) ), 403 );

        $conn_id = absint( $_POST['connection_id'] ?? 0 );
        $conn    = SheetSync_Sync_Engine::get_connection( $conn_id );
        if ( ! $conn ) wp_send_json_error( array( 'message' => __( 'Connection not found.', 'sheetsync-for-woocommerce' ) ) );

        $is_pro = sheetsync_is_pro();

        try {
            $client  = new SheetSync_Sheets_Client();
            $range   = "{$conn->sheet_name}!A{$conn->header_row}:Z{$conn->header_row}";
            $rows    = $client->get_rows( $conn->spreadsheet_id, $range );
            $headers = $rows[0] ?? array();

            // ── Fields available for this license tier ────────────────────
            // Use the Field Mapper API so the Free plugin does not reference
            // any Pro-only constants. The Pro add-on may extend fields at runtime.
            $available_fields = SheetSync_Field_Mapper::get_available_fields( $is_pro );

            // All WooCommerce fields (for label lookup during auto-detect)
            $all_fields = SheetSync_Field_Mapper::get_available_fields( true );

            // ── If Sheet has no headers → write styled headers to the sheet ──
            // Generate from available_fields (Free or Pro), then return mapping.
            if ( empty( $headers ) ) {

                $header_labels = array_values( $available_fields );

                // Write the headers WITH styling to the Google Sheet
                $client->write_styled_headers(
                    $conn->spreadsheet_id,
                    $conn->sheet_name,
                    (int) $conn->header_row,
                    $header_labels
                );

                // Build matched array: each WC field → its column letter (A, B, C…)
                $matched = array();
                $col_idx = 0;
                foreach ( $available_fields as $wc_field => $label ) {
                    $letter    = SheetSync_Field_Mapper::index_to_col( $col_idx );
                    $matched[] = array(
                        'wc_field'   => $wc_field,
                        'col_letter' => $letter,
                        'header'     => $label,
                        'label'      => $label,
                        'auto'       => true,
                    );
                    $col_idx++;
                }

                $notice = $is_pro
                    ? __( 'All fields written to your Google Sheet with styling! Column mapping applied automatically.', 'sheetsync-for-woocommerce' )
                    : __( 'Free fields written to your Google Sheet with styling! Upgrade to Pro to add more fields.', 'sheetsync-for-woocommerce' );

                // ── Server-side save: persist matched field maps immediately ──
                // This ensures mappings are saved even if the JS form submit
                // fails or disabled inputs are not re-enabled in time.
                $field_map_to_save = array();
                foreach ( $matched as $m ) {
                    $wc_field = $m['wc_field'];
                    $field_map_to_save[ $wc_field ] = array(
                        'column' => $m['col_letter'],
                        'key'    => ( $wc_field === '_sku' ) ? 1 : 0,
                    );
                }
                if ( ! empty( $field_map_to_save ) ) {
                    SheetSync_Sync_Engine::save_field_maps( $conn_id, $field_map_to_save );
                }

                wp_send_json_success( array(
                    'matched'        => $matched,
                    'unmatched'      => array(),
                    'headers'        => $header_labels,
                    'auto_generated' => true,
                    'headers_written'=> true,
                    'notice'         => $notice,
                ) );
                return;
            }

            // ── Sheet already has headers → auto-detect and match ─────────
            // Also re-style existing headers.
            $client->write_styled_headers(
                $conn->spreadsheet_id,
                $conn->sheet_name,
                (int) $conn->header_row,
                $headers
            );

            $matched   = array();
            $unmatched = array();

            foreach ( $headers as $idx => $header ) {
                $letter = SheetSync_Field_Mapper::index_to_col( $idx );
                $h      = strtolower( trim( $header ) );
                $found  = '';

                if ( str_contains( $h, 'sku' ) )                                                           $found = '_sku';
                elseif ( str_contains( $h, 'title' ) || str_contains( $h, 'name' ) )                      $found = 'post_title';
                elseif ( str_contains( $h, 'regular' ) || $h === 'price' || $h === 'regular price' )       $found = '_regular_price';
                elseif ( str_contains( $h, 'sale' ) )                                                      $found = '_sale_price';
                elseif ( str_contains( $h, 'stock' ) && str_contains( $h, 'status' ) )                    $found = '_stock_status';
                elseif ( str_contains( $h, 'quantity' ) || ( str_contains( $h, 'stock' ) && ! str_contains( $h, 'status' ) ) ) $found = '_stock';
                elseif ( str_contains( $h, 'status' ) )                                                    $found = 'post_status';
                elseif ( str_contains( $h, 'long' ) && str_contains( $h, 'desc' ) )                       $found = 'post_content';
                elseif ( str_contains( $h, 'short' ) && str_contains( $h, 'desc' ) )                      $found = 'post_excerpt';
                elseif ( str_contains( $h, 'desc' ) )                                                      $found = 'post_excerpt';
                elseif ( str_contains( $h, 'gallery' ) )                                                   $found = '_gallery_images';
                elseif ( str_contains( $h, 'image' ) || str_contains( $h, 'photo' ) )                     $found = '_product_image';
                elseif ( str_contains( $h, 'categor' ) )                                                   $found = '_product_cats';
                elseif ( str_contains( $h, 'tag' ) )                                                       $found = '_product_tags';
                elseif ( str_contains( $h, 'weight' ) )                                                    $found = '_weight';
                elseif ( str_contains( $h, 'length' ) )                                                    $found = '_length';
                elseif ( str_contains( $h, 'width' ) )                                                     $found = '_width';
                elseif ( str_contains( $h, 'height' ) )                                                    $found = '_height';
                elseif ( str_contains( $h, 'type' ) )                                                      $found = '_product_type';
                elseif ( str_contains( $h, 'parent' ) )                                                    $found = 'parent_sku';
                elseif ( str_contains( $h, 'variat' ) || str_contains( $h, 'attr' ) )                     $found = 'variation_attrs';
                elseif ( str_contains( $h, 'price' ) )                                                     $found = '_regular_price';

                // For free users, only allow matching to FREE_FIELDS
                if ( $found && ! $is_pro && ! isset( SheetSync_Field_Mapper::FREE_FIELDS[ $found ] ) ) {
                    $found = '';
                }

                if ( $found ) {
                    $matched[] = array(
                        'wc_field'   => $found,
                        'col_letter' => $letter,
                        'header'     => $header,
                        'label'      => $all_fields[ $found ] ?? $found,
                    );
                } else {
                    $unmatched[] = array( 'col_letter' => $letter, 'header' => $header );
                }
            }

            // ── Server-side save: persist matched field maps immediately ──
            $field_map_to_save = array();
            foreach ( $matched as $m ) {
                $wc_field = $m['wc_field'];
                $field_map_to_save[ $wc_field ] = array(
                    'column' => $m['col_letter'],
                    'key'    => ( $wc_field === '_sku' ) ? 1 : 0,
                );
            }
            if ( ! empty( $field_map_to_save ) ) {
                SheetSync_Sync_Engine::save_field_maps( $conn_id, $field_map_to_save );
            }

            wp_send_json_success( array(
                'matched'        => $matched,
                'unmatched'      => $unmatched,
                'headers'        => $headers,
                'headers_written'=> true,
            ) );

        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => esc_html( $e->getMessage() ) ) );
        }
    }

    public function ajax_get_headers(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( array( 'message' => __( 'Forbidden', 'sheetsync-for-woocommerce' ) ), 403 );

        $conn_id = absint( $_POST['connection_id'] ?? 0 );
        $conn    = SheetSync_Sync_Engine::get_connection( $conn_id );
        if ( ! $conn ) wp_send_json_error( array( 'message' => __( 'Connection not found.', 'sheetsync-for-woocommerce' ) ) );

        try {
            $client   = new SheetSync_Sheets_Client();
            $range    = "{$conn->sheet_name}!A{$conn->header_row}:Z{$conn->header_row}";
            $rows     = $client->get_rows( $conn->spreadsheet_id, $range );
            $headers  = $rows[0] ?? array();
            wp_send_json_success( array( 'headers' => $headers ) );
        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => esc_html( $e->getMessage() ) ) );
        }
    }

    public function ajax_import_from_sheet(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( array( 'message' => __( 'Forbidden', 'sheetsync-for-woocommerce' ) ), 403 );

        $conn_id       = absint( $_POST['connection_id'] ?? 0 );
        $raw_field_map = sanitize_text_field( wp_unslash( $_POST['field_map'] ?? '{}' ) );
        $field_map     = json_decode( $raw_field_map, true );
        $skip_existing = ! empty( $_POST['skip_existing'] );
        $create_new    = ! empty( $_POST['create_new'] );

        $conn = SheetSync_Sync_Engine::get_connection( $conn_id );
        if ( ! $conn ) wp_send_json_error( array( 'message' => __( 'Connection not found.', 'sheetsync-for-woocommerce' ) ) );

        try {
            $client   = new SheetSync_Sheets_Client();
            $all_rows = $client->get_rows( $conn->spreadsheet_id, "{$conn->sheet_name}!A:Z" );

            if ( empty( $all_rows ) ) {
                wp_send_json_error( array( 'message' => __( 'Sheet is empty.', 'sheetsync-for-woocommerce' ) ) );
            }

            // Header skip
            $header_idx = max( 0, (int) $conn->header_row - 1 );
            $data_rows  = array_slice( $all_rows, $header_idx + 1 );

            // field_map: wc_field => column_letter
            // Convert to: wc_field => col_index
            $col_map = array();
            foreach ( $field_map as $wc_field => $col_letter ) {
                $col_map[ $wc_field ] = SheetSync_Field_Mapper::col_to_index( $col_letter );
            }

            $created = $updated = $skipped = 0;
            $log     = array();

            foreach ( $data_rows as $row ) {
                if ( empty( array_filter( $row, fn($v) => $v !== '' ) ) ) continue;

                // Extract data
                $data = array();
                foreach ( $col_map as $wc_field => $idx ) {
                    $val = $row[ $idx ] ?? '';
                    if ( $val !== '' ) $data[ $wc_field ] = (string) $val;
                }

                if ( empty( $data ) ) continue;

                $sku     = sanitize_text_field( $data['_sku'] ?? '' );
                $title   = sanitize_text_field( $data['post_title'] ?? '' );
                $display = $sku ?: $title ?: 'Row';

                // Find existing product
                $product = null;
                if ( $sku ) {
                    $id = wc_get_product_id_by_sku( $sku );
                    if ( $id ) $product = wc_get_product( $id );
                }

                if ( $product && $skip_existing ) {
                    $skipped++;
                    $log[] = array( 'type' => 'skipped', 'msg' => "⏭️ Skip: {$display} (already exists)" );
                    continue;
                }

                if ( ! $product ) {
                    if ( ! $create_new ) {
                        $skipped++;
                        continue;
                    }
                    if ( ! $sku && ! $title ) { $skipped++; continue; }
                    $product = new WC_Product_Simple();
                }

                $is_new = ! $product->get_id();

                // Apply fields
                foreach ( $data as $field => $value ) {
                    switch ( $field ) {
                        case 'post_title':     $product->set_name( sanitize_text_field( $value ) ); break;
                        case '_sku':           $product->set_sku( sanitize_text_field( $value ) ); break;
                        case '_regular_price': $p = wc_format_decimal( $value ); if ( is_numeric($p) ) $product->set_regular_price( $p ); break;
                        case '_sale_price':    $p = wc_format_decimal( $value ); if ( is_numeric($p) ) $product->set_sale_price( $p ); break;
                        case '_stock':
                            $qty = (int) $value;
                            $product->set_manage_stock( true );
                            $product->set_stock_quantity( $qty );
                            $product->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
                            break;
                        case 'post_status':
                            $s = strtolower( $value );
                            $product->set_status( in_array($s, array('publish','draft','private'), true) ? $s : 'draft' );
                            break;
                        case 'post_excerpt':   $product->set_short_description( wp_kses_post( $value ) ); break;
                        case '_weight':        $product->set_weight( wc_format_decimal( $value ) ); break;
                        case '_product_image':
                            if ( filter_var( $value, FILTER_VALIDATE_URL ) ) {
                                // Reject non-HTTP(S) schemes to prevent SSRF via file://, ftp://, etc.
                                $parsed_scheme = strtolower( wp_parse_url( $value, PHP_URL_SCHEME ) ?? '' );
                                if ( in_array( $parsed_scheme, array( 'http', 'https' ), true ) ) {
                                    require_once ABSPATH . 'wp-admin/includes/media.php';
                                    require_once ABSPATH . 'wp-admin/includes/file.php';
                                    require_once ABSPATH . 'wp-admin/includes/image.php';
                                    $att_id = media_sideload_image( $value, 0, null, 'id' );
                                    // Validate MIME type before accepting the image.
                                    if ( ! is_wp_error( $att_id ) ) {
                                        $allowed_img = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
                                        $img_mime    = (string) get_post_mime_type( $att_id );
                                        if ( in_array( $img_mime, $allowed_img, true ) ) {
                                            $product->set_image_id( $att_id );
                                        } else {
                                            wp_delete_attachment( $att_id, true );
                                        }
                                    }
                                }
                            }
                            break;
                    }
                }

                if ( $is_new && empty( $data['post_status'] ) ) $product->set_status( 'publish' );

                $product->save();

                if ( $is_new ) {
                    $created++;
                    $log[] = array( 'type' => 'created', 'msg' => "✅ Created: {$display}" );
                } else {
                    $updated++;
                    $log[] = array( 'type' => 'updated', 'msg' => "🔄 Updated: {$display}" );
                }
            }

            wp_send_json_success( array(
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
                'log'     => $log,
            ) );

        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => esc_html( $e->getMessage() ) ) );
        }
    }

    /**
     * Render a Pro upgrade gate block.
     */
    public static function render_pro_gate( string $feature_name ): void {
        if ( sheetsync_is_pro() ) return;
        ?>
        <div class="sheetsync-pro-gate">
            <span class="dashicons dashicons-lock"></span>
            <strong><?php echo esc_html( $feature_name ); ?></strong>
            <?php esc_html_e( 'is a Pro feature.', 'sheetsync-for-woocommerce' ); ?>
            <a href="<?php echo esc_url( sheetsync_upgrade_url() ); ?>" target="_blank" class="button button-primary sheetsync-upgrade-btn">
                <?php esc_html_e( 'Upgrade to Pro', 'sheetsync-for-woocommerce' ); ?>
            </a>
        </div>
        <?php
    }

    // ─── Dashboard Settings Persistence ──────────────────────────────────────

    /**
     * AJAX: Save dashboard field values to a WordPress option so they survive page reloads.
     */
    public function ajax_save_dashboard_settings(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        $raw      = wp_unslash( $_POST['settings'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $settings = is_array( $raw ) ? $raw : array();

        $clean = array(
            'sd_spreadsheet_id'  => sanitize_text_field( $settings['sd_spreadsheet_id']  ?? '' ),
            'sd_sheet_name'      => sanitize_text_field( $settings['sd_sheet_name']      ?? 'Sales Dashboard' ),
            'sd_period'          => sanitize_text_field( $settings['sd_period']          ?? '6months' ),
            'inv_spreadsheet_id' => sanitize_text_field( $settings['inv_spreadsheet_id'] ?? '' ),
            'inv_sheet_name'     => sanitize_text_field( $settings['inv_sheet_name']     ?? 'Inventory Status' ),
            'inv_low_stock'      => absint( $settings['inv_low_stock'] ?? 5 ),
            'boe_spreadsheet_id' => sanitize_text_field( $settings['boe_spreadsheet_id'] ?? '' ),
            'boe_sheet_name'     => sanitize_text_field( $settings['boe_sheet_name']     ?? 'Orders Export' ),
        );

        update_option( 'sheetsync_dashboard_settings', $clean, false );
        wp_send_json_success();
    }

    /**
     * AJAX: Load saved dashboard settings.
     */
    public function ajax_load_dashboard_settings(): void {
        check_ajax_referer( 'sheetsync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'sheetsync-for-woocommerce' ) ), 403 );
        }

        $defaults = array(
            'sd_spreadsheet_id'  => '',
            'sd_sheet_name'      => 'Sales Dashboard',
            'sd_period'          => '6months',
            'inv_spreadsheet_id' => '',
            'inv_sheet_name'     => 'Inventory Status',
            'inv_low_stock'      => 5,
            'boe_spreadsheet_id' => '',
            'boe_sheet_name'     => 'Orders Export',
        );

        $saved = get_option( 'sheetsync_dashboard_settings', array() );
        wp_send_json_success( wp_parse_args( $saved, $defaults ) );
    }
}

endif; // class_exists SheetSync_Admin
