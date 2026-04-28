<?php defined( 'ABSPATH' ) || exit; ?>
<div class="sheetsync-header">
    <div class="sheetsync-logo">
        <span class="dashicons dashicons-table-col-after"></span>
        <h1>
            SheetSync for WooCommerce
            <span class="sheetsync-version">v<?php echo esc_html( SHEETSYNC_VERSION ); ?></span>
        </h1>
    </div>
    <?php if ( sheetsync_is_pro() ) : ?>
        <span class="sheetsync-pro-badge">PRO</span>
    <?php else : ?>
        <a href="<?php echo esc_url( sheetsync_upgrade_url() ); ?>" target="_blank" class="button sheetsync-upgrade-btn" style="margin-left:auto;">
            ⚡ <?php esc_html_e( 'Upgrade to Pro', 'sheetsync-for-woocommerce' ); ?>
        </a>
    <?php endif; ?>
</div>
