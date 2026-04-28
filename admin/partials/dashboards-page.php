<?php defined( 'ABSPATH' ) || exit; ?>

<div class="sheetsync-wrap">
    <?php require __DIR__ . '/header.php'; ?>

    <div class="sheetsync-card">
        <h2><?php esc_html_e( 'Dashboards (Pro)', 'sheetsync-for-woocommerce' ); ?></h2>
        <p><?php esc_html_e( 'Dashboards, Bulk Order Export, and advanced reports are available in SheetSync Pro.', 'sheetsync-for-woocommerce' ); ?></p>
        <p>
            <a href="<?php echo esc_url( function_exists( 'sheetsync_upgrade_url' ) ? sheetsync_upgrade_url() : 'https://devnajmus.com/sheetsync/pricing' ); ?>" target="_blank" class="button button-primary">
                <?php esc_html_e( 'Upgrade to Pro for Dashboards & Reports', 'sheetsync-for-woocommerce' ); ?>
            </a>
        </p>
    </div>
</div>
