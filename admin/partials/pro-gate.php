<?php defined( 'ABSPATH' ) || exit; ?>
<div class="sheetsync-wrap">
    <?php require __DIR__ . '/header.php'; ?>
    <div class="sheetsync-card ss-empty-state">
        <span class="dashicons dashicons-lock" style="color:var(--ss-amber);"></span>
        <h3><?php esc_html_e( 'Pro Feature', 'sheetsync-for-woocommerce' ); ?></h3>
        <p><?php esc_html_e( 'This feature requires a Pro license.', 'sheetsync-for-woocommerce' ); ?></p>
        <a href="<?php echo esc_url( sheetsync_upgrade_url() ); ?>" target="_blank"
           class="button button-primary sheetsync-upgrade-btn button-hero">
            ⚡ <?php esc_html_e( 'Upgrade to Pro', 'sheetsync-for-woocommerce' ); ?>
        </a>
    </div>
</div>
