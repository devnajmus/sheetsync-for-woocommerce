<?php
/**
 * Uninstall SheetSync for WooCommerce.
 *
 * FIX L-4: This file is required by WordPress.org Plugin Review Guidelines.
 * Without it, all custom DB tables, options, and transients left behind on
 * uninstall are a WP.org submission blocker.
 *
 * WordPress executes this file directly (not via include) when the user clicks
 * "Delete" in the Plugins screen. The WP_UNINSTALL_PLUGIN constant is always
 * defined before this file runs — we abort if it isn't.
 *
 * @package SheetSync_For_WooCommerce
 * @since   1.0.1
 */

defined( 'ABSPATH' ) || exit;
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// ── Remove custom database tables ────────────────────────────────────────────
$tables = array(
    $wpdb->prefix . 'sheetsync_connections',
    $wpdb->prefix . 'sheetsync_field_maps',
    $wpdb->prefix . 'sheetsync_logs',
);

foreach ( $tables as $table ) {

    $table = esc_sql( $table );

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery
    $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// ── Remove plugin options ─────────────────────────────────────────────────────
$options = array(
    'sheetsync_settings',
    'sheetsync_webhook_secret',
    'sheetsync_pro_test_mode',
    'sheetsync_auto_sync_settings',
    'sheetsync_db_version',
    'sheetsync_service_account',    // encrypted credential blob
    'sheetsync_google_token_cache', // OAuth2 token cache
);

foreach ( $options as $option ) {
    delete_option( $option );
}

// ── Remove all cron events ────────────────────────────────────────────────────
wp_clear_scheduled_hook( 'sheetsync_scheduled_sync' );

// ── Remove transients ────────────────────────────────────────────────────────
// Known static transients.
$transients = array(
    'sheetsync_admin_notices',
    'sheetsync_all_connections',
    'sheetsync_active_product_connections',
    'sheetsync_google_token',
);

foreach ( $transients as $transient ) {
    delete_transient( $transient );
}

// Dynamic transients keyed by connection ID or IP hash.
$wpdb->query(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s
         OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like( '_transient_sheetsync_' ) . '%',
        $wpdb->esc_like( '_transient_timeout_sheetsync_' ) . '%',
        $wpdb->esc_like( 'sheetsync_dismissed_' ) . '%',
        $wpdb->esc_like( '_transient_sheetsync_rl_' ) . '%',
        $wpdb->esc_like( 'sheetsync_date_filter_type_' ) . '%',
        $wpdb->esc_like( 'sheetsync_date_filter_single_' ) . '%',
        $wpdb->esc_like( 'sheetsync_date_filter_from_' ) . '%',
        $wpdb->esc_like( 'sheetsync_date_filter_to_' ) . '%'
    )
);

// ── Remove per-user meta ──────────────────────────────────────────────────────
$wpdb->query(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->prepare(
        "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
        $wpdb->esc_like( 'sheetsync_dismissed_' ) . '%'
    )
);
