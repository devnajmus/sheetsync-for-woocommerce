<?php
defined( 'ABSPATH' ) || exit;

$sheetsync_conn_id     = $connection ? (int) $connection->id : 0;
$sheetsync_is_new      = ! $sheetsync_conn_id;
$sheetsync_free_fields = SheetSync_Field_Mapper::FREE_FIELDS;
$sheetsync_pro_fields  = array();
$sheetsync_is_pro      = function_exists( 'sheetsync_is_pro' ) ? sheetsync_is_pro() : false;

// Date filter settings — always initialized (safe for new connections)
$sheetsync_date_type   = $sheetsync_conn_id ? get_option( 'sheetsync_date_filter_type_'   . $sheetsync_conn_id, 'all' ) : 'all';
$sheetsync_date_single = $sheetsync_conn_id ? get_option( 'sheetsync_date_filter_single_' . $sheetsync_conn_id, ''    ) : '';
$sheetsync_date_from   = $sheetsync_conn_id ? get_option( 'sheetsync_date_filter_from_'   . $sheetsync_conn_id, ''    ) : '';
$sheetsync_date_to     = $sheetsync_conn_id ? get_option( 'sheetsync_date_filter_to_'     . $sheetsync_conn_id, ''    ) : '';
?>
<div class="sheetsync-wrap">

    <?php require __DIR__ . '/header.php'; ?>

    <p>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=sheetsync' ) ); ?>">
            ← <?php esc_html_e( 'All Connections', 'sheetsync-for-woocommerce' ); ?>
        </a>
    </p>

    <!-- Tabs -->
    <div class="ss-tabs">
        <a href="#tab-connection" class="ss-tab"><?php esc_html_e( 'Connection', 'sheetsync-for-woocommerce' ); ?></a>
        <?php if ( ! $sheetsync_is_new ) : ?>
            <a href="#tab-field-mapping" class="ss-tab"><?php esc_html_e( 'Field Mapping', 'sheetsync-for-woocommerce' ); ?></a>
            <a href="#tab-sync" class="ss-tab"><?php esc_html_e( 'Sync', 'sheetsync-for-woocommerce' ); ?></a>
        <?php endif; ?>
    </div>

    <!-- Tab: Connection -->
    <div id="tab-connection" class="ss-tab-panel ss-hidden">
        <div class="sheetsync-card">
            <h2><?php echo $sheetsync_is_new ? esc_html__( 'New Connection', 'sheetsync-for-woocommerce' ) : esc_html__( 'Edit Connection', 'sheetsync-for-woocommerce' ); ?></h2>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'sheetsync_save_connection' ); ?>
                <input type="hidden" name="action" value="sheetsync_save_connection">
                <input type="hidden" name="connection_id" value="<?php echo esc_attr( $sheetsync_conn_id ); ?>">

                <table class="form-table sheetsync-settings-form">
                    <tr>
                        <th><?php esc_html_e( 'Connection Name', 'sheetsync-for-woocommerce' ); ?></th>
                        <td>
                            <input type="text" name="connection_name" class="regular-text"
                                   value="<?php echo esc_attr( $connection->name ?? '' ); ?>"
                                   placeholder="e.g. Products Inventory Sheet">
                            <p class="description"><?php esc_html_e( 'A friendly name to identify this connection.', 'sheetsync-for-woocommerce' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Spreadsheet ID', 'sheetsync-for-woocommerce' ); ?></th>
                        <td>
                            <div class="ss-sheet-id-group">
                                <input type="text" id="spreadsheet_id" name="spreadsheet_id"
                                       class="regular-text"
                                       value="<?php echo esc_attr( $connection->spreadsheet_id ?? '' ); ?>"
                                       placeholder="1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgVE2upms">
                                <button type="button" id="ss-test-connection" class="button ss-connection-test">
                                    <?php esc_html_e( 'Test Connection', 'sheetsync-for-woocommerce' ); ?>
                                </button>
                            </div>
                            <p class="description">
                                <?php esc_html_e( 'Found in the Google Sheets URL: docs.google.com/spreadsheets/d/', 'sheetsync-for-woocommerce' ); ?>
                                <strong>[SPREADSHEET_ID]</strong>/edit
                            </p>
                            <div id="ss-test-result" class="ss-test-result ss-hidden"></div>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Sheet Tab Name', 'sheetsync-for-woocommerce' ); ?></th>
                        <td>
                            <div class="ss-sheet-select-row ss-hidden">
                                <select id="sheet_name_select" name="sheet_name_select"
                                        data-current="<?php echo esc_attr( $connection->sheet_name ?? 'Sheet1' ); ?>"
                                        onchange="document.getElementById('sheet_name').value = this.value;">
                                </select>
                                <p class="description"><?php esc_html_e( 'Or type the name manually:', 'sheetsync-for-woocommerce' ); ?></p>
                            </div>
                            <input type="text" id="sheet_name" name="sheet_name" class="regular-text"
                                   value="<?php echo esc_attr( $connection->sheet_name ?? 'Sheet1' ); ?>"
                                   placeholder="Sheet1">
                            <p class="description"><?php esc_html_e( 'The tab name at the bottom of your Google Sheet.', 'sheetsync-for-woocommerce' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Header Row', 'sheetsync-for-woocommerce' ); ?></th>
                        <td>
                            <input type="number" name="header_row" class="small-text" min="1" max="10"
                                   value="<?php echo esc_attr( $connection->header_row ?? 1 ); ?>">
                            <p class="description"><?php esc_html_e( 'Row number containing your column headers (usually 1).', 'sheetsync-for-woocommerce' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Connection Type', 'sheetsync-for-woocommerce' ); ?></th>
                        <td>
                            <?php
                             $sheetsync_order_statuses = array(
                                'orders'            => __( 'Orders (All)', 'sheetsync-for-woocommerce' ),
                                'orders_pending'    => __( 'Pending Payment', 'sheetsync-for-woocommerce' ),
                                'orders_processing' => __( 'Processing', 'sheetsync-for-woocommerce' ),
                                'orders_on-hold'    => __( 'On Hold', 'sheetsync-for-woocommerce' ),
                                'orders_completed'  => __( 'Completed', 'sheetsync-for-woocommerce' ),
                                'orders_cancelled'  => __( 'Cancelled', 'sheetsync-for-woocommerce' ),
                                'orders_refunded'   => __( 'Refunded', 'sheetsync-for-woocommerce' ),
                                'orders_failed'     => __( 'Failed', 'sheetsync-for-woocommerce' ),
                                'orders_draft'      => __( 'Draft', 'sheetsync-for-woocommerce' ),
                            );
                            $sheetsync_current_type = $connection->connection_type ?? 'products';
                            ?>
                            <select name="connection_type" id="sheetsync_connection_type">
                                <option value="products" <?php selected( $sheetsync_current_type, 'products' ); ?>>
                                    <?php esc_html_e( 'Products', 'sheetsync-for-woocommerce' ); ?>
                                </option>
                                <optgroup label="--- Orders (Pro) ---">
                                <?php foreach ( $sheetsync_order_statuses as $sheetsync_type_val => $sheetsync_type_label ) :
                                    $sheetsync_is_currently_selected = ( $sheetsync_current_type === $sheetsync_type_val );
                                    $sheetsync_option_disabled = ! $sheetsync_is_pro && ! $sheetsync_is_currently_selected;
                                ?>
                                    <option value="<?php echo esc_attr( $sheetsync_type_val ); ?>"
                                        <?php selected( $sheetsync_current_type, $sheetsync_type_val ); ?>
                                        <?php disabled( $sheetsync_option_disabled ); ?>>
                                        <?php echo esc_html( $sheetsync_type_label ); ?>
                                        <?php if ( $sheetsync_option_disabled ) echo ' (Pro)'; ?>
                                    </option>
                                <?php endforeach; ?>
                                </optgroup>
                            </select>
                            <?php if ( ! $sheetsync_is_pro && SheetSync_Sync_Engine::is_orders_type( $sheetsync_current_type ) ) : ?>
                                <p class="description ss-orders-type-notice">
                                    <span class="dashicons dashicons-filter"></span>
                                    <?php
                                    printf(
                                        /* translators: %s: order status label */
                                        esc_html__( 'Filter: "%s" orders only. Upgrade to Pro to use Orders sync.', 'sheetsync-for-woocommerce' ),
                                        esc_html( $sheetsync_order_statuses[ $sheetsync_current_type ] ?? $sheetsync_current_type )
                                    );
                                    ?>
                                </p>
                            <?php elseif ( $sheetsync_is_pro && SheetSync_Sync_Engine::is_orders_type( $sheetsync_current_type ) ) : ?>
                                <p class="description ss-orders-type-notice">
                                    <span class="dashicons dashicons-filter"></span>
                                    <?php
                                    printf(
                                        /* translators: %s: order status label */
                                        esc_html__( 'Only "%s" orders will sync to this sheet.', 'sheetsync-for-woocommerce' ),
                                        esc_html( $sheetsync_order_statuses[ $sheetsync_current_type ] ?? $sheetsync_current_type )
                                    );
                                    ?>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <!-- ── Date Filter Row (shows only for Order connection types) ── -->
                    <tr id="sheetsync-date-filter-row" class="<?php echo esc_attr( SheetSync_Sync_Engine::is_orders_type( $sheetsync_current_type ) ? '' : 'ss-hidden' ); ?>">
                        <th><?php esc_html_e( 'Date Filter', 'sheetsync-for-woocommerce' ); ?></th>
                        <td>
                            <select id="sheetsync_date_type" name="order_date_type" class="ss-date-select">
                                <option value="all"    <?php selected( $sheetsync_date_type, 'all'    ); ?>><?php esc_html_e( 'All Dates (no filter)',  'sheetsync-for-woocommerce' ); ?></option>
                                <option value="single" <?php selected( $sheetsync_date_type, 'single' ); ?>><?php esc_html_e( 'Specific Date',          'sheetsync-for-woocommerce' ); ?></option>
                                <option value="range"  <?php selected( $sheetsync_date_type, 'range'  ); ?>><?php esc_html_e( 'Date Range (From → To)', 'sheetsync-for-woocommerce' ); ?></option>
                            </select>

                            <div id="sheetsync-date-single" class="<?php echo esc_attr( $sheetsync_date_type === 'single' ? 'ss-margin-top-4' : 'ss-hidden ss-margin-top-4' ); ?>">
                                <input type="date" name="order_date_single"
                                       value="<?php echo esc_attr( $sheetsync_date_single ); ?>"
                                       class="ss-date-input">
                                <p class="description"><?php esc_html_e( 'Only orders placed on this date will be synced.', 'sheetsync-for-woocommerce' ); ?></p>
                            </div>

                            <div id="sheetsync-date-range" class="<?php echo esc_attr( $sheetsync_date_type === 'range' ? 'ss-margin-top-4' : 'ss-hidden ss-margin-top-4' ); ?>">
                                <div class="ss-date-range-row">
                                    <div>
                                        <label class="ss-date-filter-label"><?php esc_html_e( 'From', 'sheetsync-for-woocommerce' ); ?></label>
                                        <input type="date" name="order_date_from" value="<?php echo esc_attr( $sheetsync_date_from ); ?>"
                                               class="ss-date-input">
                                    </div>
                                    <span class="ss-date-arrow">→</span>
                                    <div>
                                        <label class="ss-date-filter-label"><?php esc_html_e( 'To', 'sheetsync-for-woocommerce' ); ?></label>
                                        <input type="date" name="order_date_to" value="<?php echo esc_attr( $sheetsync_date_to ); ?>"
                                               class="ss-date-input">
                                    </div>
                                </div>
                                <p class="description"><?php esc_html_e( 'Only orders placed between these dates (inclusive) will be synced.', 'sheetsync-for-woocommerce' ); ?></p>
                            </div>

                            <?php if ( $sheetsync_date_type !== 'all' ) : ?>
                                <p class="ss-date-active-notice">
                                    <span class="dashicons dashicons-calendar-alt ss-icon-sm"></span>
                                    <?php
                                    if ( $sheetsync_date_type === 'single' && $sheetsync_date_single ) {
										/* translators: %s: the active date. */
                                        printf( esc_html__( 'Active: %s', 'sheetsync-for-woocommerce' ), esc_html( $sheetsync_date_single ) );
                                    } elseif ( $sheetsync_date_type === 'range' ) {
										/* translators: %1$s: start date, %2$s: end date. "→" indicates a range. */
                                        printf( esc_html__( 'Active: %1$s → %2$s', 'sheetsync-for-woocommerce' ),
                                            esc_html( $sheetsync_date_from ?: __('any', 'sheetsync-for-woocommerce') ),
                                            esc_html( $sheetsync_date_to   ?: __('any', 'sheetsync-for-woocommerce') )
                                        );
                                    }
                                    ?>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <tr>
                        <th><?php esc_html_e( 'Sync Direction', 'sheetsync-for-woocommerce' ); ?></th>
                        <td>
                            <select name="sync_direction">
                                <option value="sheets_to_wc" <?php selected( $connection->sync_direction ?? 'sheets_to_wc', 'sheets_to_wc' ); ?>>
                                    <?php esc_html_e( 'Google Sheets → WooCommerce', 'sheetsync-for-woocommerce' ); ?>
                                </option>
                                <option value="wc_to_sheets" <?php selected( $connection->sync_direction ?? '', 'wc_to_sheets' ); ?>
                                    <?php disabled( ! $sheetsync_is_pro ); ?>>
                                    <?php esc_html_e( 'WooCommerce → Google Sheets', 'sheetsync-for-woocommerce' ); ?>
                                    <?php if ( ! $sheetsync_is_pro ) echo esc_html__( '(Pro)', 'sheetsync-for-woocommerce' ); ?>
                                </option>
                                <option value="two_way" <?php selected( $connection->sync_direction ?? '', 'two_way' ); ?>
                                    <?php disabled( ! $sheetsync_is_pro ); ?>>
                                    <?php esc_html_e( 'Two-Way Sync', 'sheetsync-for-woocommerce' ); ?>
                                    <?php if ( ! $sheetsync_is_pro ) echo esc_html__( '(Pro)', 'sheetsync-for-woocommerce' ); ?>
                                </option>
                            </select>
                            <?php if ( ! $sheetsync_is_pro ) : ?>
                                <p class="description">
                                    <span class="dashicons dashicons-info"></span>
                                    <?php esc_html_e( 'WooCommerce → Google Sheets and Two-Way Sync are Pro features.', 'sheetsync-for-woocommerce' ); ?>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Status', 'sheetsync-for-woocommerce' ); ?></th>
                        <td>
                            <select name="status">
                                <option value="active"   <?php selected( $connection->status ?? 'active', 'active' ); ?>><?php esc_html_e( 'Active', 'sheetsync-for-woocommerce' ); ?></option>
                                <option value="inactive" <?php selected( $connection->status ?? '', 'inactive' ); ?>><?php esc_html_e( 'Inactive', 'sheetsync-for-woocommerce' ); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>

                <p>
                    <button type="submit" class="button button-primary">
                        <?php echo $sheetsync_is_new ? esc_html__( 'Create Connection', 'sheetsync-for-woocommerce' ) : esc_html__( 'Save Connection', 'sheetsync-for-woocommerce' ); ?>
                    </button>
                </p>
            </form>
        </div>
    </div>

    <?php if ( ! $sheetsync_is_new ) : ?>

    <!-- Tab: Field Mapping -->
    <div id="tab-field-mapping" class="ss-tab-panel ss-hidden">
        <div class="sheetsync-card">
            <h2><?php esc_html_e( 'Field Mapping', 'sheetsync-for-woocommerce' ); ?></h2>
            <p><?php esc_html_e( 'Enter the column letter (A, B, C…) from your Google Sheet that corresponds to each WooCommerce field. Mark the key field used to identify products (SKU recommended).', 'sheetsync-for-woocommerce' ); ?></p>

            <!-- ── Import Header Row ──────────────────────────────────── -->
                <div class="ss-import-header-box">
                <h3 class="ss-no-margin-top">
                    <span class="dashicons dashicons-download ss-icon-green"></span>
                    <?php esc_html_e( 'Import Headers from Sheet', 'sheetsync-for-woocommerce' ); ?>
                </h3>
                    <p class="description">
                        <?php esc_html_e( 'Automatically fills column mappings from your Google Sheet. Upgrade to Pro to map additional fields (images, categories, tags, sale price, etc.).', 'sheetsync-for-woocommerce' ); ?>
                    </p>

                <div class="ss-import-header-row">
                    <button type="button" class="button button-primary ss-import-headers-btn"
                            data-connection-id="<?php echo esc_attr( $sheetsync_conn_id ); ?>"
                            data-nonce="<?php echo esc_attr( wp_create_nonce( 'sheetsync_nonce' ) ); ?>">
                        <span class="dashicons dashicons-download"></span>
                        <?php esc_html_e( 'Import Headers', 'sheetsync-for-woocommerce' ); ?>
                    </button>
                    <span class="ss-hint-text">
                        <?php esc_html_e( 'Free: basic fields (SKU, Title, Regular Price, Stock, Status).', 'sheetsync-for-woocommerce' ); ?> &nbsp;|&nbsp;
                        <a href="<?php echo esc_url( function_exists( 'sheetsync_upgrade_url' ) ? sheetsync_upgrade_url() : 'https://devnajmus.com/sheetsync/pricing' ); ?>" target="_blank" class="ss-link-green">
                            <?php esc_html_e( 'Upgrade to Pro for additional product fields →', 'sheetsync-for-woocommerce' ); ?>
                        </a>
                    </span>
                    <span class="ss-import-result"></span>
                </div>

                <div id="ss-header-preview" class="ss-header-preview ss-hidden">
                    <p><strong><?php echo '✅ ' . esc_html__( 'Found in Sheet:', 'sheetsync-for-woocommerce' ); ?></strong></p>
                    <div id="ss-header-list" class="ss-header-chips"></div>
                    <p class="description ss-orders-type-notice">
                        The columns below have been automatically matched. Adjust manually if needed.
                    </p>
                </div>
            </div>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'sheetsync_save_field_map' ); ?>
                <input type="hidden" name="action" value="sheetsync_save_field_map">
                <input type="hidden" name="connection_id" value="<?php echo esc_attr( $sheetsync_conn_id ); ?>">

                <table class="sheetsync-mapping-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'WooCommerce Field', 'sheetsync-for-woocommerce' ); ?></th>
                            <th class="ss-col-sheet"><?php esc_html_e( 'Sheet Column', 'sheetsync-for-woocommerce' ); ?></th>
                            <th class="ss-col-key"><?php esc_html_e( 'Key Field', 'sheetsync-for-woocommerce' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Free fields -->
                        <?php foreach ( $sheetsync_free_fields as $sheetsync_key => $sheetsync_label ) : ?>
                            <tr>
                                <td>
                                    <span class="ss-field-label"><?php echo esc_html( $sheetsync_label ); ?></span>
                                    <span class="ss-field-key"><?php echo esc_html( $sheetsync_key ); ?></span>
                                </td>
                                <td>
                                    <input type="text" name="field_map[<?php echo esc_attr( $sheetsync_key ); ?>][column]"
                                           class="column-input"
                                           value="<?php echo esc_attr( $field_maps[ $sheetsync_key ]['sheet_column'] ?? '' ); ?>"
                                           maxlength="3" placeholder="e.g. A or B">
                                </td>
                                <td class="ss-td-center">
                                    <input type="checkbox"
                                           name="field_map[<?php echo esc_attr( $sheetsync_key ); ?>][key]"
                                           value="1"
                                           <?php
                                           // Default: SKU is key field if no mapping saved yet
                                           $sheetsync_is_key = ! empty( $field_maps[ $sheetsync_key ]['is_key_field'] );
                                           if ( ! $sheetsync_is_key && $sheetsync_key === '_sku' && empty( $field_maps ) ) {
                                               $sheetsync_is_key = true;
                                           }
                                           checked( $sheetsync_is_key );
                                           ?>>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p class="ss-action-row">
                    <button type="submit" class="button button-primary">
                        <?php esc_html_e( 'Save Field Mapping', 'sheetsync-for-woocommerce' ); ?>
                    </button>
                </p>
            </form>
        </div>
    </div>

    <!-- Tab: Sync -->
    <div id="tab-sync" class="ss-tab-panel ss-hidden">
        <?php
        $sheetsync_has_maps     = SheetSync_Sync_Engine::is_orders_type( $connection->connection_type ) || ! empty( SheetSync_Field_Mapper::get_maps( $sheetsync_conn_id ) );
        // Prefer consolidated options with legacy fallbacks for older installs.
        $sheetsync_sync_opts = get_option( 'sheetsync_sync_options', array() );
        $sheetsync_strategy = isset( $sheetsync_sync_opts[ $sheetsync_conn_id ]['strategy'] )
            ? $sheetsync_sync_opts[ $sheetsync_conn_id ]['strategy']
            : get_option( 'sheetsync_sync_strategy_' . $sheetsync_conn_id, 'smart' );
        $sheetsync_auto_on_save = isset( $sheetsync_sync_opts[ $sheetsync_conn_id ]['auto_on_save'] )
            ? (bool) $sheetsync_sync_opts[ $sheetsync_conn_id ]['auto_on_save']
            : (bool) get_option( 'sheetsync_auto_on_save_' . $sheetsync_conn_id, false );
        $sheetsync_schedules = get_option( 'sheetsync_schedules', array() );
        $sheetsync_schedule = $sheetsync_schedules[ $sheetsync_conn_id ] ?? get_option( 'sheetsync_schedule_' . $sheetsync_conn_id, '' );
        $sheetsync_next_run     = $sheetsync_is_pro ? SheetSync_Cron_Manager::get_next_run( $sheetsync_conn_id ) : null;
        $sheetsync_auto_sync_map     = get_option( 'sheetsync_auto_sync_settings', array() );
        $sheetsync_auto_sync_enabled = (bool) ( $sheetsync_auto_sync_map[ $sheetsync_conn_id ] ?? false );
        ?>

        <?php if ( ! $sheetsync_has_maps ) : ?>
        <div class="notice notice-warning inline sheetsync-card">
            <p>
                <span class="dashicons dashicons-warning"></span>
                <?php esc_html_e( 'No field mappings yet.', 'sheetsync-for-woocommerce' ); ?>
                <a href="<?php echo esc_url( admin_url( "admin.php?page=sheetsync&sheetsync_action=edit&connection_id={$sheetsync_conn_id}#tab-field-mapping" ) ); ?>">
                    <?php esc_html_e( 'Set up Field Mapping first →', 'sheetsync-for-woocommerce' ); ?>
                </a>
            </p>
        </div>
        <?php endif; ?>

        <!-- ══ CARD 1: Manual Sync ══════════════════════════════════════════ -->
        <div class="sheetsync-card">
            <h2>
                <span class="dashicons dashicons-update ss-icon-green"></span>
                <?php esc_html_e( 'Manual Sync', 'sheetsync-for-woocommerce' ); ?>
            </h2>
            <p class="description">
                <?php esc_html_e( 'Sync all WooCommerce products to your Google Sheet instantly.', 'sheetsync-for-woocommerce' ); ?>
            </p>

            <button class="button button-primary ss-sync-btn" data-connection-id="<?php echo esc_attr( $sheetsync_conn_id ); ?>" <?php disabled( ! $sheetsync_has_maps ); ?>>
                <span class="dashicons dashicons-update"></span>
                <?php esc_html_e( 'Sync Now', 'sheetsync-for-woocommerce' ); ?>
            </button>
            <span class="ss-sync-result ss-hidden"></span>
        </div>

        <!-- ══ CARD 2: Sync Strategy (Free + Pro) ═══════════════════════════ -->
        <div class="sheetsync-card">
            <h2>
                <span class="dashicons dashicons-performance ss-icon-green"></span>
                <?php esc_html_e( 'Sync Strategy', 'sheetsync-for-woocommerce' ); ?>
            </h2>
            <p class="description">
                <?php esc_html_e( 'Choose how products are compared before syncing.', 'sheetsync-for-woocommerce' ); ?>
            </p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'sheetsync_save_sync_options' ); ?>
                <input type="hidden" name="action" value="sheetsync_save_sync_options">
                <input type="hidden" name="connection_id" value="<?php echo esc_attr( $sheetsync_conn_id ); ?>">

                <div class="ss-strategy-cards">

                    <!-- Smart Diff -->
                    <label class="ss-strategy-card <?php echo esc_attr( $sheetsync_strategy === 'smart' ? 'selected' : '' ); ?>">
                        <input type="radio" name="sync_strategy" value="smart" <?php checked( $sheetsync_strategy, 'smart' ); ?>>
                        <div class="ss-strategy-icon">⚡</div>
                        <div>
                            <strong><?php esc_html_e( 'Smart Diff', 'sheetsync-for-woocommerce' ); ?></strong>
                            <p class="description ss-desc-tight">
                                <?php esc_html_e( 'Only sync products that changed. Fastest — skips unchanged rows completely.', 'sheetsync-for-woocommerce' ); ?>
                            </p>
                        </div>
                    </label>

                    <!-- Full Sync -->
                    <label class="ss-strategy-card <?php echo esc_attr( $sheetsync_strategy === 'full' ? 'selected' : '' ); ?>">
                        <input type="radio" name="sync_strategy" value="full" <?php checked( $sheetsync_strategy, 'full' ); ?>>
                        <div class="ss-strategy-icon">🔄</div>
                        <div>
                            <strong><?php esc_html_e( 'Full Sync', 'sheetsync-for-woocommerce' ); ?></strong>
                            <p class="description ss-desc-tight">
                                <?php esc_html_e( 'Always overwrite every row. Use when you want a guaranteed fresh sheet.', 'sheetsync-for-woocommerce' ); ?>
                            </p>
                        </div>
                    </label>

                </div>

                <?php if ( $sheetsync_is_pro ) : ?>
                <!-- Auto Sync on Product Save (Pro) -->
                <div class="ss-section-divider">
                    <label class="ss-flex-label">
                        <input type="checkbox" name="auto_on_save" value="1"
                               <?php checked( $sheetsync_auto_on_save ); ?>
                               class="ss-checkbox-styled">
                        <div>
                            <strong><?php esc_html_e( 'Auto Sync on Product Save', 'sheetsync-for-woocommerce' ); ?></strong>
                            <p class="description ss-desc-tight">
                                <?php esc_html_e( 'Automatically push a product to the sheet whenever it is saved or updated in WooCommerce — no manual sync needed.', 'sheetsync-for-woocommerce' ); ?>
                            </p>
                        </div>
                    </label>
                </div>
                <?php else : ?>
                <div class="sheetsync-pro-gate">
                    <span class="dashicons dashicons-lock"></span>
                    <strong><?php esc_html_e( 'Auto Sync on Product Save', 'sheetsync-for-woocommerce' ); ?></strong>
                    — <?php esc_html_e( 'Automatically push changes to Sheet on every product save.', 'sheetsync-for-woocommerce' ); ?>
                    <a href="<?php echo esc_url( sheetsync_upgrade_url() ); ?>" target="_blank" class="button sheetsync-upgrade-btn ss-ml-8">
                        <?php esc_html_e( 'Upgrade to Pro', 'sheetsync-for-woocommerce' ); ?>
                    </a>
                </div>
                <?php endif; ?>

                <p class="ss-action-row">
                    <button type="submit" class="button button-primary">
                        <?php esc_html_e( 'Save Options', 'sheetsync-for-woocommerce' ); ?>
                    </button>
                </p>
            </form>
        </div>

        <!-- ══ CARD 3: Scheduled Sync (Pro) ════════════════════════════════ -->
        <div class="sheetsync-card">
            <h2>
                <span class="dashicons dashicons-clock ss-icon-green"></span>
                <?php esc_html_e( 'Scheduled Sync', 'sheetsync-for-woocommerce' ); ?>
                <span class="sheetsync-pro-badge">PRO</span>
            </h2>

            <?php if ( $sheetsync_is_pro ) : ?>
                <p class="description">
                    <?php esc_html_e( 'Automatically sync on a schedule using WP-Cron.', 'sheetsync-for-woocommerce' ); ?>
                </p>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'sheetsync_save_schedule' ); ?>
                    <input type="hidden" name="action" value="sheetsync_save_schedule">
                    <input type="hidden" name="connection_id" value="<?php echo esc_attr( $sheetsync_conn_id ); ?>">

                    <div class="ss-schedule-grid">
                        <?php
                        $sheetsync_schedules = array(
                            ''                 => array( 'label' => __( 'Disabled', 'sheetsync-for-woocommerce' ),       'icon' => '🚫' ),
                            'sheetsync_15min'  => array( 'label' => __( 'Every 15 min', 'sheetsync-for-woocommerce' ),   'icon' => '⚡' ),
                            'sheetsync_30min'  => array( 'label' => __( 'Every 30 min', 'sheetsync-for-woocommerce' ),   'icon' => '🕐' ),
                            'sheetsync_1hour'  => array( 'label' => __( 'Every hour', 'sheetsync-for-woocommerce' ),     'icon' => '🕑' ),
                            'twicedaily'       => array( 'label' => __( 'Twice daily', 'sheetsync-for-woocommerce' ),    'icon' => '📅' ),
                            'daily'            => array( 'label' => __( 'Once daily', 'sheetsync-for-woocommerce' ),     'icon' => '📆' ),
                        );
                        foreach ( $sheetsync_schedules as $sheetsync_val => $sheetsync_s ) : ?>
                            <label class="ss-schedule-option <?php echo esc_attr( $sheetsync_schedule === $sheetsync_val ? 'selected' : '' ); ?>">
                                <input type="radio" name="sync_interval" value="<?php echo esc_attr( $sheetsync_val ); ?>"
                                       <?php checked( $sheetsync_schedule, $sheetsync_val ); ?>>
                                <span class="ss-schedule-icon"><?php echo esc_html( $sheetsync_s['icon'] ); ?></span>
                                <span><?php echo esc_html( $sheetsync_s['label'] ); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>

						<?php if ( $sheetsync_next_run ) : ?>
							<p class="description ss-next-run-notice">
								<span class="dashicons dashicons-clock"></span>
								<?php 
								/* translators: %s: the next scheduled run date/time. */
								printf( esc_html__( 'Next run: %s', 'sheetsync-for-woocommerce' ), esc_html( $sheetsync_next_run ) ); 
								?>
							</p>
						<?php endif; ?>

                    <p class="ss-action-row">
                        <button type="submit" class="button button-primary">
                            <?php esc_html_e( 'Save Schedule', 'sheetsync-for-woocommerce' ); ?>
                        </button>
                        <?php if ( $sheetsync_schedule ) : ?>
                            <span class="ss-success-inline">
                                ✅ <?php esc_html_e( 'Active', 'sheetsync-for-woocommerce' ); ?>
                            </span>
                        <?php endif; ?>
                    </p>
                </form>

            <?php else : ?>
                <?php SheetSync_Admin::render_pro_gate( __( 'Scheduled Sync', 'sheetsync-for-woocommerce' ) ); ?>
                <p class="description ss-desc-spaced">
                    <?php esc_html_e( 'Run sync automatically every 15 min, 30 min, hourly, or daily — no manual trigger needed.', 'sheetsync-for-woocommerce' ); ?>
                </p>
            <?php endif; ?>
        </div>

        <!-- ══ CARD 4: Sheet→WC Real-Time (Apps Script) ═════════════════════ -->
        <div class="sheetsync-card">
            <h2>
                <span class="dashicons dashicons-table-row-before ss-icon-green"></span>
                <?php esc_html_e( 'Sheet → WooCommerce (Real-Time)', 'sheetsync-for-woocommerce' ); ?>
                <span class="sheetsync-pro-badge">PRO</span>
            </h2>

            <?php if ( $sheetsync_is_pro ) : ?>
                <p class="description">
                    <?php esc_html_e( 'Any edit in Google Sheet instantly updates WooCommerce via Apps Script webhook.', 'sheetsync-for-woocommerce' ); ?>
                </p>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ss-form-spaced">
                    <?php wp_nonce_field( 'sheetsync_toggle_auto_sync' ); ?>
                    <input type="hidden" name="action" value="sheetsync_toggle_auto_sync">
                    <input type="hidden" name="connection_id" value="<?php echo esc_attr( $sheetsync_conn_id ); ?>">

                    <label class="ss-toggle-label">
                        <input type="checkbox" name="auto_sync_enabled" value="1"
                               <?php checked( $sheetsync_auto_sync_enabled ); ?>
                               onchange="this.form.submit()">
                        <span class="ss-toggle-switch"></span>
                        <?php if ( $sheetsync_auto_sync_enabled ) : ?>
                            <span class="ss-status-active">✅ <?php esc_html_e( 'Active', 'sheetsync-for-woocommerce' ); ?></span>
                        <?php else : ?>
                            <span class="ss-status-inactive">❌ <?php esc_html_e( 'Inactive', 'sheetsync-for-woocommerce' ); ?></span>
                        <?php endif; ?>
                    </label>
                </form>

                <?php if ( $sheetsync_auto_sync_enabled ) :
                    $sheetsync_apps_script = SheetSync_Webhook_Handler::get_apps_script( $sheetsync_conn_id ); ?>
                <div class="ss-apps-script-box">
                    <p><strong>📋 <?php esc_html_e( 'Paste this Apps Script into your Google Sheet:', 'sheetsync-for-woocommerce' ); ?></strong></p>
                    <p class="description"><?php esc_html_e( 'Sheet → Extensions → Apps Script → Delete old code → Paste → Save → Run once', 'sheetsync-for-woocommerce' ); ?></p>
                    <textarea class="ss-code-block" readonly rows="20" onclick="this.select()"><?php echo esc_textarea( $sheetsync_apps_script ); ?></textarea>
                    <p class="ss-desc-spaced">
                        <button class="button ss-copy-btn" data-target=".ss-code-block">📋 <?php esc_html_e( 'Copy Code', 'sheetsync-for-woocommerce' ); ?></button>
                    </p>
                </div>
                <?php else : ?>
                <div class="notice notice-info inline"><p><?php esc_html_e( 'Enable to get the Apps Script code.', 'sheetsync-for-woocommerce' ); ?></p></div>
                <?php endif; ?>

            <?php else : ?>
                <?php SheetSync_Admin::render_pro_gate( __( 'Sheet → WooCommerce Real-Time', 'sheetsync-for-woocommerce' ) ); ?>
                    <p class="description ss-desc-spaced">
                    ✅ <strong><?php esc_html_e( 'Free:', 'sheetsync-for-woocommerce' ); ?></strong> <?php esc_html_e( 'Manual Sync (Sync Now button)', 'sheetsync-for-woocommerce' ); ?><br>
                    ⚡ <strong><?php esc_html_e( 'Pro:', 'sheetsync-for-woocommerce' ); ?></strong> <?php esc_html_e( 'Any Sheet edit → WooCommerce updates instantly', 'sheetsync-for-woocommerce' ); ?>
                </p>
            <?php endif; ?>
        </div>

    </div>

    <?php endif; ?>

</div>