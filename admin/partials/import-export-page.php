<?php defined( 'ABSPATH' ) || exit; ?>

<div class="sheetsync-wrap">
    <?php require __DIR__ . '/header.php'; ?>

    <div class="sheetsync-card">
        <h2>📥 <?php esc_html_e( 'Import / Export', 'sheetsync-for-woocommerce' ); ?></h2>
        <p><?php esc_html_e( 'Bulk import products from Google Sheets or export WooCommerce data to sheets.', 'sheetsync-for-woocommerce' ); ?></p>

        <div class="sheetsync-pro-notice">
            <span class="sheetsync-pro-badge">⭐ Pro</span>
            <p><?php esc_html_e( 'Advanced import/export automations and reporting features are available in SheetSync Pro.', 'sheetsync-for-woocommerce' ); ?></p>
            <ul>
                <li><?php esc_html_e( '✅ Bulk import products from Google Sheets', 'sheetsync-for-woocommerce' ); ?></li>
                <li><?php esc_html_e( '✅ Export all WooCommerce data to sheets', 'sheetsync-for-woocommerce' ); ?></li>
                <li><?php esc_html_e( '✅ Scheduled automated imports', 'sheetsync-for-woocommerce' ); ?></li>
                <li><?php esc_html_e( '✅ Import/export connection settings', 'sheetsync-for-woocommerce' ); ?></li>
            </ul>
            <a href="<?php echo esc_url( 'https://devnajmus.com/sheetsync/pricing' ); ?>" target="_blank" class="button button-primary">
                <?php esc_html_e( 'Upgrade to Pro →', 'sheetsync-for-woocommerce' ); ?>
            </a>
        </div>

        <p class="description">
            <?php esc_html_e( 'The free version includes manual syncs, field mapping, and connection management.', 'sheetsync-for-woocommerce' ); ?>
        </p>
    </div>
</div>
