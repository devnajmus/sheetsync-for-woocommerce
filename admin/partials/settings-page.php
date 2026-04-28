<?php defined( 'ABSPATH' ) || exit; ?>

<div class="sheetsync-wrap">
    <?php require __DIR__ . '/header.php'; ?>

    <div class="sheetsync-card">
        <h2><?php esc_html_e( 'Google API Connection', 'sheetsync-for-woocommerce' ); ?></h2>

        <?php if ( $account_email ) : ?>
            <p>
                <span class="ss-account-email">
                    <span class="dashicons dashicons-yes-alt ss-icon-success"></span>
                    <?php esc_html_e( 'Connected as:', 'sheetsync-for-woocommerce' ); ?>
                    <strong><?php echo esc_html( $account_email ); ?></strong>
                </span>
            </p>
            <p class="description"><?php esc_html_e( 'To change credentials, paste a new JSON key below.', 'sheetsync-for-woocommerce' ); ?></p>
        <?php else : ?>
            <div class="notice notice-warning inline ss-notice-spacing">
                <p><?php esc_html_e( 'No Google Service Account configured. Follow the steps below to connect.', 'sheetsync-for-woocommerce' ); ?></p>
            </div>
        <?php endif; ?>

        <details class="ss-details">
            <summary class="ss-summary">
                📋 <?php esc_html_e( 'How to get your Service Account JSON key', 'sheetsync-for-woocommerce' ); ?>
            </summary>
            <ol class="ss-steps-list">
                <li><?php esc_html_e( 'Go to console.cloud.google.com and create a new project.', 'sheetsync-for-woocommerce' ); ?></li>
                <li><?php esc_html_e( 'Enable the Google Sheets API under APIs &amp; Services → Library.', 'sheetsync-for-woocommerce' ); ?></li>
                <li><?php esc_html_e( 'Go to IAM &amp; Admin → Service Accounts → Create Service Account.', 'sheetsync-for-woocommerce' ); ?></li>
                <li><?php esc_html_e( 'Click the service account → Keys tab → Add Key → Create new key → JSON.', 'sheetsync-for-woocommerce' ); ?></li>
                <li><?php esc_html_e( 'Download the JSON file and paste its contents below.', 'sheetsync-for-woocommerce' ); ?></li>
                <li><?php esc_html_e( 'Share your Google Sheet with the service account email (Editor role).', 'sheetsync-for-woocommerce' ); ?></li>
            </ol>
        </details>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'sheetsync_save_settings' ); ?>
            <input type="hidden" name="action" value="sheetsync_save_settings">

            <table class="form-table sheetsync-settings-form">
                <tr>
                    <th><?php esc_html_e( 'Service Account JSON', 'sheetsync-for-woocommerce' ); ?></th>
                    <td>
                        <textarea name="service_account_json" class="ss-json-textarea"
                                  placeholder='{"type":"service_account","project_id":"...","private_key":"...","client_email":"...@....iam.gserviceaccount.com",...}'></textarea>
                        <p class="description ss-warning-text">
                            🔒 <?php esc_html_e( 'Stored encrypted. Never leave this field filled after saving — the field is intentionally blank on reload for security.', 'sheetsync-for-woocommerce' ); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th><?php esc_html_e( 'Batch Size', 'sheetsync-for-woocommerce' ); ?></th>
                    <td>
                        <input type="number" name="batch_size" class="small-text" min="1" max="500"
                               value="<?php echo esc_attr( $settings['batch_size'] ?? 50 ); ?>">
                        <p class="description"><?php esc_html_e( 'Number of rows to process per batch. Lower this if you experience timeouts (default: 50).', 'sheetsync-for-woocommerce' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <th><?php esc_html_e( 'Log Retention', 'sheetsync-for-woocommerce' ); ?></th>
                    <td>
                        <input type="number" name="log_retention_days" class="small-text" min="1" max="365"
                               value="<?php echo esc_attr( $settings['log_retention_days'] ?? 30 ); ?>">
                        <?php esc_html_e( 'days', 'sheetsync-for-woocommerce' ); ?>
                    </td>
                </tr>

                <tr>
                    <th><?php esc_html_e( 'Email Notifications', 'sheetsync-for-woocommerce' ); ?></th>
                    <td>
                        <?php if ( sheetsync_is_pro() ) : ?>
                            <label>
                                <input type="checkbox" name="email_notifications" value="1"
                                       <?php checked( ! empty( $settings['email_notifications'] ) ); ?>>
                                <?php esc_html_e( 'Send email when sync completes or fails', 'sheetsync-for-woocommerce' ); ?>
                            </label>
                            <br><br>
                            <input type="email" name="notification_email" class="regular-text"
                                   value="<?php echo esc_attr( $settings['notification_email'] ?? get_option( 'admin_email' ) ); ?>">
                            <p class="description"><?php esc_html_e( 'Email address for notifications.', 'sheetsync-for-woocommerce' ); ?></p>
                        <?php else : ?>
                            <?php SheetSync_Admin::render_pro_gate( __( 'Email Notifications', 'sheetsync-for-woocommerce' ) ); ?>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <p>
                <button type="submit" class="button button-primary">
                    <?php esc_html_e( 'Save Settings', 'sheetsync-for-woocommerce' ); ?>
                </button>
            </p>
        </form>
    </div>

    <?php if ( sheetsync_is_pro() ) : ?>
    <div class="sheetsync-card">
        <h2><?php esc_html_e( 'Webhook Configuration', 'sheetsync-for-woocommerce' ); ?> <span class="sheetsync-pro-badge">PRO</span></h2>
        <p><?php esc_html_e( 'Use this endpoint and secret in your Google Apps Script to enable real-time sync.', 'sheetsync-for-woocommerce' ); ?></p>

        <div class="ss-webhook-box">
            <strong><?php esc_html_e( 'Webhook URL:', 'sheetsync-for-woocommerce' ); ?></strong>
            <code id="webhook-endpoint"><?php echo esc_html( $webhook_endpoint ); ?></code>
            <button class="button ss-copy-btn" data-target="#webhook-endpoint"><?php esc_html_e( 'Copy', 'sheetsync-for-woocommerce' ); ?></button>

            <br><br>
            <?php // Secret displayed as masked password field, not plain <code>. ?>
            <strong><?php esc_html_e( 'Webhook Secret:', 'sheetsync-for-woocommerce' ); ?></strong>
            <span class="ss-inline-flex">
                <input type="password"
                       id="webhook-secret-field"
                       class="regular-text ss-monospace"
                       readonly
                       value="<?php echo esc_attr( $webhook_secret ); ?>">
                <button type="button" class="button" id="ss-reveal-secret">
                    <?php esc_html_e( 'Reveal', 'sheetsync-for-woocommerce' ); ?>
                </button>
                <button type="button" class="button" id="ss-copy-secret">
                    <?php esc_html_e( 'Copy', 'sheetsync-for-woocommerce' ); ?>
                </button>
            </span>
        </div>
    </div>
    <?php endif; ?>

</div>
