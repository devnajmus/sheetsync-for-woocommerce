<?php defined( 'ABSPATH' ) || exit; ?>

<div class="sheetsync-wrap">
    <?php require __DIR__ . '/header.php'; ?>

    <div class="sheetsync-card">
        <h2><?php esc_html_e( 'Sync Logs', 'sheetsync-for-woocommerce' ); ?></h2>

        <?php if ( empty( $logs ) ) : ?>
            <div class="ss-empty-state">
                <span class="dashicons dashicons-list-view"></span>
                <h3><?php esc_html_e( 'No logs yet', 'sheetsync-for-woocommerce' ); ?></h3>
                <p><?php esc_html_e( 'Sync activity will appear here after your first sync.', 'sheetsync-for-woocommerce' ); ?></p>
            </div>
        <?php else : ?>
            <table class="sheetsync-logs-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Time', 'sheetsync-for-woocommerce' ); ?></th>
                        <th><?php esc_html_e( 'Connection', 'sheetsync-for-woocommerce' ); ?></th>
                        <th><?php esc_html_e( 'Type', 'sheetsync-for-woocommerce' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'sheetsync-for-woocommerce' ); ?></th>
                        <th><?php esc_html_e( 'Processed', 'sheetsync-for-woocommerce' ); ?></th>
                        <th><?php esc_html_e( 'Skipped', 'sheetsync-for-woocommerce' ); ?></th>
                        <th><?php esc_html_e( 'Errors', 'sheetsync-for-woocommerce' ); ?></th>
                        <th><?php esc_html_e( 'Message', 'sheetsync-for-woocommerce' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $logs as $sheetsync_log ) : ?>
                        <tr>
                            <td>
                                <?php
                                $sheetsync_ts = strtotime( $sheetsync_log['created_at'] );
                                echo esc_html( human_time_diff( $sheetsync_ts, time() ) . ' ago' );
                                ?>
                                <br><small class="ss-hint-text"><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $sheetsync_ts ) ); ?></small>
                            </td>
                            <td><?php echo esc_html( $sheetsync_log['connection_name'] ?? '—' ); ?></td>
                            <td><code><?php echo esc_html( $sheetsync_log['sync_type'] ); ?></code></td>
                            <td>
                                <span class="ss-log-status ss-log-<?php echo esc_attr( $sheetsync_log['status'] ); ?>">
                                    <?php echo esc_html( $sheetsync_log['status'] ); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html( $sheetsync_log['rows_processed'] ); ?></td>
                            <td><?php echo esc_html( $sheetsync_log['rows_skipped'] ); ?></td>
                            <td><?php echo esc_html( $sheetsync_log['rows_errored'] ); ?></td>
                            <td class="ss-log-message-cell">
                                <?php echo esc_html( $sheetsync_log['message'] ); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>