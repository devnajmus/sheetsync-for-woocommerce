<?php
defined( 'ABSPATH' ) || exit;

$sheetsync_conn_count   = count( $connections );
$sheetsync_can_add_more = true;  // All users can add connections
?>

<div class="sheetsync-wrap">

    <?php require __DIR__ . '/header.php'; ?>

    <div class="sheetsync-card">
        <div class="ss-card-header-row">
            <h2 class="ss-card-title"><?php esc_html_e( 'Sheet Connections', 'sheetsync-for-woocommerce' ); ?></h2>

            <?php if ( $sheetsync_can_add_more ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=sheetsync&sheetsync_action=new' ) ); ?>"
                   class="button button-primary">
                    + <?php esc_html_e( 'Add Connection', 'sheetsync-for-woocommerce' ); ?>
                </a>
            <?php endif; ?>
        </div>


    </div>

    <?php if ( empty( $connections ) ) : ?>
        <div class="sheetsync-card ss-empty-state">
            <span class="dashicons dashicons-table-col-after"></span>
            <h3><?php esc_html_e( 'No connections yet', 'sheetsync-for-woocommerce' ); ?></h3>
            <p><?php esc_html_e( 'Connect a Google Sheet to start syncing your WooCommerce products.', 'sheetsync-for-woocommerce' ); ?></p>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=sheetsync&sheetsync_action=new' ) ); ?>"
               class="button button-primary button-hero">
                <?php esc_html_e( 'Add Your First Connection', 'sheetsync-for-woocommerce' ); ?>
            </a>
        </div>
    <?php else : ?>
        <div class="sheetsync-connections-grid">
            <?php foreach ( $connections as $sheetsync_conn ) : ?>
                <div class="sheetsync-connection-card">
                    <p class="ss-conn-name">
                        <?php echo esc_html( $sheetsync_conn->name ?: __( '(Unnamed Connection)', 'sheetsync-for-woocommerce' ) ); ?>
                        <span class="ss-status-badge ss-status-<?php echo esc_attr( $sheetsync_conn->status ); ?>">
                            <?php echo esc_html( $sheetsync_conn->status ); ?>
                        </span>
                    </p>
                    <div class="ss-conn-meta">
                        <strong><?php esc_html_e( 'Sheet:', 'sheetsync-for-woocommerce' ); ?></strong>
                        <?php echo esc_html( $sheetsync_conn->sheet_name ); ?><br>

                        <strong><?php esc_html_e( 'Type:', 'sheetsync-for-woocommerce' ); ?></strong>
                        <?php
                        $sheetsync_type_labels = array(
                            'products'          => __( 'Products', 'sheetsync-for-woocommerce' ),
                            'orders'            => __( 'Orders (All)', 'sheetsync-for-woocommerce' ),
                            'orders_pending'    => __( 'Orders: Pending Payment', 'sheetsync-for-woocommerce' ),
                            'orders_processing' => __( 'Orders: Processing', 'sheetsync-for-woocommerce' ),
                            'orders_on-hold'    => __( 'Orders: On Hold', 'sheetsync-for-woocommerce' ),
                            'orders_completed'  => __( 'Orders: Completed', 'sheetsync-for-woocommerce' ),
                            'orders_cancelled'  => __( 'Orders: Cancelled', 'sheetsync-for-woocommerce' ),
                            'orders_refunded'   => __( 'Orders: Refunded', 'sheetsync-for-woocommerce' ),
                            'orders_failed'     => __( 'Orders: Failed', 'sheetsync-for-woocommerce' ),
                            'orders_draft'      => __( 'Orders: Draft', 'sheetsync-for-woocommerce' ),
                        );
                        echo esc_html( $sheetsync_type_labels[ $sheetsync_conn->connection_type ] ?? ucfirst( str_replace( '_', ' ', $sheetsync_conn->connection_type ) ) );
                        ?><br>

                        <strong><?php esc_html_e( 'Direction:', 'sheetsync-for-woocommerce' ); ?></strong>
                        <?php
                        $sheetsync_directions = array(
                            'sheets_to_wc' => __( 'Sheets → WooCommerce', 'sheetsync-for-woocommerce' ),
                            'wc_to_sheets' => __( 'WooCommerce → Sheets', 'sheetsync-for-woocommerce' ),
                            'two_way'      => __( 'Two-Way', 'sheetsync-for-woocommerce' ),
                        );
                        echo esc_html( $sheetsync_directions[ $sheetsync_conn->sync_direction ] ?? $sheetsync_conn->sync_direction );
                        ?><br>

                        <?php if ( $sheetsync_conn->last_sync_at ) : ?>
                            <strong><?php esc_html_e( 'Last sync:', 'sheetsync-for-woocommerce' ); ?></strong>
                            <?php
                            /* translators: %s: human-readable time difference */
                            printf( esc_html__( '%s ago', 'sheetsync-for-woocommerce' ), esc_html( human_time_diff( strtotime( $sheetsync_conn->last_sync_at ), time() ) ) );
                            ?>
                        <?php else : ?>
                            <em><?php esc_html_e( 'Never synced', 'sheetsync-for-woocommerce' ); ?></em>
                        <?php endif; ?>
                    </div>

                    <div class="ss-conn-actions">
                        <?php if ( $sheetsync_conn->sync_direction !== 'wc_to_sheets' ) : ?>
                            <button class="button ss-sync-btn"
                                    data-connection-id="<?php echo esc_attr( $sheetsync_conn->id ); ?>">
                                <span class="dashicons dashicons-update"></span>
                                <?php esc_html_e( 'Sync Now', 'sheetsync-for-woocommerce' ); ?>
                            </button>
                        <?php endif; ?>

                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=sheetsync&sheetsync_action=edit&connection_id=' . absint( $sheetsync_conn->id ) ) ); ?>"
                           class="button">
                            <?php esc_html_e( 'Edit', 'sheetsync-for-woocommerce' ); ?>
                        </a>

                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=sheetsync-logs&connection_id=' . absint( $sheetsync_conn->id ) ) ); ?>"
                           class="button">
                            <?php esc_html_e( 'Logs', 'sheetsync-for-woocommerce' ); ?>
                        </a>

                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                              class="ss-delete-form ss-inline-form">
                            <?php wp_nonce_field( 'sheetsync_delete_connection' ); ?>
                            <input type="hidden" name="action" value="sheetsync_delete_connection">
                            <input type="hidden" name="connection_id" value="<?php echo esc_attr( $sheetsync_conn->id ); ?>">
                            <button type="submit" class="button ss-delete-btn">
                                <?php esc_html_e( 'Delete', 'sheetsync-for-woocommerce' ); ?>
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
