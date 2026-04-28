/* global sheetsync, jQuery */
( function( $ ) {
    'use strict';

    /**
     * Safely encode a value for insertion into HTML.
     * Prevents XSS from server-supplied strings.
     *
     * @param {*} val
     * @returns {string}
     */
    function safeText( val ) {
        return $( '<span>' ).text( String( val ) ).html();
    }

    // ── Manual Sync ────────────────────────────────────────────────────────────
    $( document ).on( 'click', '.ss-sync-btn', function( e ) {
        e.preventDefault();

        const $btn     = $( this );
        const connId   = $btn.data( 'connection-id' );
        let $result  = $btn.next( '.ss-sync-result' );
        if ( ! $result.length ) {
            $btn.after( '<span class="ss-sync-result"></span>' );
            $result = $btn.next( '.ss-sync-result' );
        }
        const origHtml = $btn.html();

        $btn.addClass( 'loading' ).html(
            '<span class="dashicons dashicons-update ss-spin"></span> ' + sheetsync.i18n.syncing
        );
        $result.remove();

        $.post( sheetsync.ajax_url, {
            action        : 'sheetsync_manual_sync',
            nonce         : sheetsync.nonce,
            connection_id : connId,
        } )
        .done( function( response ) {
            if ( response.success ) {
                const d = response.data;

                // ── Build context badge ──────────────────────────────────
                const typeLabels = sheetsync.i18n.conn_type_labels || {};
                const connType  = d.connection_type || '';
                const typeLabel = typeLabels[ connType ] || connType;

                // Date filter badge — escape all server-supplied values to prevent XSS.
                let dateBadge = '';
                if ( d.date_type === 'single' && d.date_single ) {
                    dateBadge = '<span class="ss-badge ss-badge-date">📅 ' + safeText( d.date_single ) + '</span>';
                } else if ( d.date_type === 'range' ) {
                    const from = safeText( d.date_from || '…' );
                    const to   = safeText( d.date_to   || '…' );
                    dateBadge = '<span class="ss-badge ss-badge-date">📅 ' + from + ' → ' + to + '</span>';
                }

                // Stats — use localised labels from wp_localize_script
                let parts = [];
                if ( d.processed > 0 ) parts.push( '<span class="ss-stat ss-stat-ok">✅ ' + d.processed + ' ' + sheetsync.i18n.synced + '</span>' );
                if ( d.skipped   > 0 ) parts.push( '<span class="ss-stat ss-stat-skip">⏭ ' + d.skipped   + ' ' + sheetsync.i18n.unchanged + '</span>' );
                if ( d.errors    > 0 ) parts.push( '<span class="ss-stat ss-stat-err">❌ ' + d.errors    + ' ' + sheetsync.i18n.errors_lbl + '</span>' );
                if ( parts.length === 0 ) parts.push( '<span class="ss-stat ss-stat-ok">✅ ' + sheetsync.i18n.sync_complete + '</span>' );

                const html =
                    '<div class="ss-sync-toast ss-toast-success">' +
                        '<div class="ss-toast-header">' +
                            ( typeLabel ? '<span class="ss-badge ss-badge-type">' + typeLabel + '</span>' : '' ) +
                            dateBadge +
                        '</div>' +
                        '<div class="ss-toast-stats">' + parts.join( '' ) + '</div>' +
                    '</div>';

                $btn.after( html );
            } else {
                const msg = ( response.data && response.data.message ) || sheetsync.i18n.sync_error;
                $btn.after( '<div class="ss-sync-toast ss-toast-error">❌ ' + $( '<span>' ).text( msg ).html() + '</div>' );
            }
        } )
        .fail( function() {
            $btn.after( '<div class="ss-sync-toast ss-toast-error">❌ ' + sheetsync.i18n.sync_error + '</div>' );
        } )
        .always( function() {
            $btn.removeClass( 'loading' ).html( origHtml );
            // Auto-hide toast after 8 seconds
            setTimeout( function() {
                $btn.siblings( '.ss-sync-toast' ).fadeOut( 400, function() { $( this ).remove(); } );
            }, 8000 );
        } );
    } );

    // ── Test Connection ────────────────────────────────────────────────────────
    $( document ).on( 'click', '#ss-test-connection', function( e ) {
        e.preventDefault();

        const $btn          = $( this );
        const spreadsheetId = $( '#spreadsheet_id' ).val().trim();
        const $result       = $( '#ss-test-result' );

        if ( ! spreadsheetId ) {
            $result.attr( 'class', 'ss-test-result error' ).text( sheetsync.i18n.please_enter_spreadsheet ).show();
            return;
        }

        $btn.text( sheetsync.i18n.testing ).prop( 'disabled', true );
        $result.hide();

        $.post( sheetsync.ajax_url, {
            action         : 'sheetsync_test_connection',
            nonce          : sheetsync.nonce,
            spreadsheet_id : spreadsheetId,
        } )
        .done( function( response ) {
            if ( response.success ) {
                const d = response.data;

                // SECURITY: Never inject API-supplied data (spreadsheet title, sheet names)
                // as raw HTML — a malicious sheet owner could craft XSS payloads.
                // Use createTextNode() and jQuery {text:} which auto-encode all HTML chars.
                const $strong = $( '<strong>' ).text( sheetsync.i18n.connected_to );
                const $title  = $( document.createTextNode( d.title ) );
                const $sheets = $( document.createTextNode(
                    ' \u2014 Sheets: ' + ( d.sheets ? d.sheets.length : 0 )
                ) );
                $result.attr( 'class', 'ss-test-result success' )
                       .empty()
                       .append( $strong, $title, $( '<br>' ), $sheets )
                       .show();

                // Populate sheet selector if present
                if ( d.sheets && $( '#sheet_name_select' ).length ) {
                    const $sel = $( '#sheet_name_select' );
                    const cur  = $sel.data( 'current' );
                    $sel.empty();
                    d.sheets.forEach( function( s ) {
                        // jQuery {value:, text:} syntax safely encodes both attribute and content.
                        $sel.append( $( '<option>', { value: s, text: s } ).prop( 'selected', s === cur ) );
                    } );
                    $sel.closest( '.ss-sheet-select-row' ).show();
                }
            } else {
                const msg = ( response.data && response.data.message ) || sheetsync.i18n.connection_failed;
                $result.attr( 'class', 'ss-test-result error' ).text( '✗ ' + msg ).show();
            }
        } )
        .fail( function() {
            $result.attr( 'class', 'ss-test-result error' ).text( '\u2717 ' + sheetsync.i18n.request_failed ).show();
        } )
        .always( function() {
            $btn.text( sheetsync.i18n.test_connection ).prop( 'disabled', false );
        } );
    } );

    // ── Copy to clipboard ──────────────────────────────────────────────────────
    $( document ).on( 'click', '.ss-copy-btn', function() {
        const target = $( this ).data( 'target' );
        const text   = $( target ).text();
        navigator.clipboard.writeText( text ).then( () => {
            const $btn = $( this );
            $btn.text( sheetsync.i18n.copied );
            setTimeout( () => $btn.text( sheetsync.i18n.copy ), 2000 );
        } );
    } );

    // ── Field Mapping form validation ─────────────────────────────────────────
    $( document ).on( 'submit', 'form:has(.column-input)', function( e ) {
        let hasMapping = false;
        let keyFieldSet = false;
        let keyFieldHasColumn = true;

        $( '.column-input:enabled', this ).each( function() {
            const val = $( this ).val().trim();
            const $row = $( this ).closest( 'tr' );
            const isKey = $row.find( 'input[type="checkbox"]' ).is( ':checked' );

            if ( val !== '' ) hasMapping = true;
            if ( isKey ) {
                keyFieldSet = true;
                if ( val === '' ) keyFieldHasColumn = false;
            }
        } );

        if ( ! hasMapping ) {
            e.preventDefault();
            alert( sheetsync.i18n.field_map_required );
            return false;
        }

        if ( keyFieldSet && ! keyFieldHasColumn ) {
            // Key field has no column set — warn the user but allow submission to continue.
            if ( ! window.confirm( sheetsync.i18n.key_field_empty_confirm ) ) {
                e.preventDefault();
                return false;
            }
        }
    } );
    $( document ).on( 'input', '.column-input', function() {
        this.value = this.value.toUpperCase().replace( /[^A-Z]/g, '' );
    } );

    // ── Delete connection confirmation ────────────────────────────────────────
    $( document ).on( 'submit', '.ss-delete-form', function() {
        return window.confirm( sheetsync.i18n.confirm_delete );
    } );

    // ── Import Headers from Sheet ─────────────────────────────────────────────
    $( document ).on( 'click', '.ss-import-headers-btn', function( e ) {
        e.preventDefault();

        const $btn      = $( this );
        const connId    = $btn.data( 'connection-id' );
        const nonce     = $btn.data( 'nonce' );
        const $result   = $btn.siblings( '.ss-import-result' );
        const origText  = $btn.text();

        $btn.text( sheetsync.i18n.importing ).prop( 'disabled', true );
        $result.text( '' ).removeClass( 'success error' );

        $.post( sheetsync.ajax_url, {
            action        : 'sheetsync_import_headers',
            nonce         : nonce,
            connection_id : connId,
        } )
        .done( function( response ) {
            if ( response.success ) {
                const d = response.data;

                // Populate matched field column letters into their respective inputs.
                d.matched.forEach( function( m ) {
                    const $input = $( 'input[name="field_map[' + m.wc_field + '][column]"]' );
                    if ( $input.length ) {
                        // Remove disabled so value is included in form submit
                        $input.prop( 'disabled', false );
                        $input.val( m.col_letter ).css( 'background', '#dcfce7' ).trigger( 'change' );
                        // Re-enable checkbox (Pro fields may be disabled by default).
                        const $cb = $input.closest( 'tr' ).find( 'input[type="checkbox"]' );
                        $cb.prop( 'disabled', false );
                        // Auto-check the key field checkbox when the field is SKU.
                        if ( m.wc_field === '_sku' ) {
                            $cb.prop( 'checked', true );
                        }
                    }
                } );

                let msg;
                if ( d.auto_generated ) {
                    // Headers were written to the Sheet with styling
                    const sheetIcon = d.headers_written ? '🎨 ' : '⚠️ ';
                    msg = sheetIcon + ( d.notice || sheetsync.i18n.headers_written );
                    $result.text( msg ).css( 'color', d.headers_written ? '#059669' : '#d97706' );
                } else {
                    msg = '✅ ' + d.matched.length + ' ' + sheetsync.i18n.fields_matched;
                    if ( d.headers_written ) {
                        msg += ' · 🎨 Sheet headers styled!';
                    }
                    if ( d.unmatched && d.unmatched.length ) {
                        msg += ' (' + d.unmatched.length + ' ' + sheetsync.i18n.unmatched + ': ';
                        msg += d.unmatched.map( c => c.header + '=' + c.col_letter ).join( ', ' ) + ')';
                    }
                    $result.text( msg ).css( 'color', '#16a34a' );
                }

                // Server-side already saved the field maps via AJAX.
                // Reload the page to show the saved column letters (green inputs)
                // without depending on disabled-input form submit.
                setTimeout( function() {
                    window.location.reload();
                }, 1000 );

            } else {
                const msg = ( response.data && response.data.message ) || sheetsync.i18n.import_failed;
                $result.text( '❌ ' + msg ).css( 'color', '#dc2626' );
            }
        } )
        .fail( function( jqXHR ) {
            let errMsg = '❌ ' + ( sheetsync.i18n.request_failed || 'Request failed.' );
            try {
                const resp = JSON.parse( jqXHR.responseText );
                if ( resp && resp.data && resp.data.message ) {
                    errMsg = '❌ ' + resp.data.message;
                }
            } catch ( e ) {}
            // Quota / rate-limit hint
            if ( jqXHR.status === 429 || errMsg.toLowerCase().indexOf( 'quota' ) !== -1 ) {
                errMsg = '⏳ ' + ( sheetsync.i18n.google_quota_exceeded || 'Google API quota exceeded. Please wait a moment and try again.' );
            }
            $result.text( errMsg ).css( 'color', '#dc2626' );
        } )
        .always( function() {
            $btn.text( origText ).prop( 'disabled', false );
        } );
    } );

    // ── Tab navigation ────────────────────────────────────────────────────────
    $( document ).on( 'click', '.ss-tab', function( e ) {
        e.preventDefault();
        const target = $( this ).attr( 'href' );

        $( '.ss-tab' ).removeClass( 'active' );
        $( this ).addClass( 'active' );

        $( '.ss-tab-panel' ).hide();
        $( target ).show();

        // If showing connection tab, init date filter visibility
        if ( target === '#tab-connection' ) {
            setTimeout( ss_date_filter_init, 30 );
        }

        // Update URL hash without scrolling
        history.replaceState( null, '', target );
    } );

    // Activate tab from URL hash on load
    // Supports both #tab-field-mapping and legacy #field-mapping formats
    const hash = window.location.hash;
    let activated = false;
    if ( hash ) {
        // Try exact match first
        let $tabLink = $( '.ss-tab[href="' + hash + '"]' );
        // Try adding 'tab-' prefix if no exact match (e.g. #field-mapping → #tab-field-mapping)
        if ( ! $tabLink.length ) {
            const prefixed = hash.replace( '#', '#tab-' );
            $tabLink = $( '.ss-tab[href="' + prefixed + '"]' );
        }
        if ( $tabLink.length ) {
            $tabLink.trigger( 'click' );
            activated = true;
        }
    }
    if ( ! activated ) {
        $( '.ss-tab:first' ).addClass( 'active' );
        $( '.ss-tab-panel:first' ).show();
        // After making the first panel visible, run date filter init
        const firstHref = $( '.ss-tab:first' ).attr( 'href' );
        if ( firstHref === '#tab-connection' ) {
            ss_date_filter_init();
        }
    }

    // ── Spin animation for dashicons ──────────────────────────────────────────
    const spinStyle = document.createElement( 'style' );
    spinStyle.textContent = '.ss-spin { animation: ss-rotate 1s linear infinite; }' +
                            '@keyframes ss-rotate { to { transform: rotate(360deg); } }';
    document.head.appendChild( spinStyle );

    // ── Sync Strategy Cards — visual selection ────────────────────────────
    $( document ).on( 'change', '.ss-strategy-card input[type="radio"]', function() {
        $( '.ss-strategy-card' ).removeClass( 'selected' );
        $( this ).closest( '.ss-strategy-card' ).addClass( 'selected' );
    } );

    // ── Schedule Options — visual selection ──────────────────────────────
    $( document ).on( 'change', '.ss-schedule-option input[type="radio"]', function() {
        $( '.ss-schedule-option' ).removeClass( 'selected' );
        $( this ).closest( '.ss-schedule-option' ).addClass( 'selected' );
    } );

    // ── Date Filter ───────────────────────────────────────────────────────

    function ss_date_filter_init() {
        const $type_select = $( '#sheetsync_connection_type' );
        if ( ! $type_select.length ) return;

        // Read value from the selected option directly — more reliable than .val()
        // because .val() can return null/wrong value when the selected option is disabled.
        const val      = $type_select.find( 'option:selected' ).val() || $type_select.val() || '';
        const is_order = val !== '' && val !== 'products';

        if ( is_order ) {
            $( '#sheetsync-date-filter-row' ).show();
        } else {
            $( '#sheetsync-date-filter-row' ).hide();
        }

        // Sub-fields
        const dtype = $( '#sheetsync_date_type' ).val() || 'all';
        if ( dtype === 'single' ) {
            $( '#sheetsync-date-single' ).show();
            $( '#sheetsync-date-range'  ).hide();
        } else if ( dtype === 'range' ) {
            $( '#sheetsync-date-single' ).hide();
            $( '#sheetsync-date-range'  ).show();
        } else {
            $( '#sheetsync-date-single' ).hide();
            $( '#sheetsync-date-range'  ).hide();
        }
    }

    // Connection Type change → instant show/hide, no reload
    $( document ).on( 'change', '#sheetsync_connection_type', function() {
        const val      = $( this ).find( 'option:selected' ).val() || $( this ).val() || '';
        const is_order = val !== '' && val !== 'products';
        if ( is_order ) {
            $( '#sheetsync-date-filter-row' ).show();
        } else {
            $( '#sheetsync-date-filter-row' ).hide();
            $( '#sheetsync-date-single' ).hide();
            $( '#sheetsync-date-range'  ).hide();
        }
    } );

    // Date sub-type change
    $( document ).on( 'change', '#sheetsync_date_type', function() {
        $( '#sheetsync-date-single' ).toggle( $( this ).val() === 'single' );
        $( '#sheetsync-date-range'  ).toggle( $( this ).val() === 'range'  );
    } );

    // Run init only if the connection tab is already visible on page load.
    // PHP already renders the correct show/hide state via inline style,
    // so we only re-run if JS tab switching has not yet occurred.
    if ( $( '#tab-connection' ).is( ':visible' ) ) {
        ss_date_filter_init();
    }

    // ── Webhook Secret Reveal / Copy (settings-page.php) ─────────────────────
    ( function() {
        var field  = document.getElementById( 'webhook-secret-field' );
        var reveal = document.getElementById( 'ss-reveal-secret' );
        var copy   = document.getElementById( 'ss-copy-secret' );
        if ( reveal && field ) {
            reveal.addEventListener( 'click', function() {
                field.type = field.type === 'password' ? 'text' : 'password';
                reveal.textContent = field.type === 'password'
                    ? sheetsync.i18n.reveal
                    : sheetsync.i18n.hide;
            } );
        }
        if ( copy && field ) {
            copy.addEventListener( 'click', function() {
                navigator.clipboard.writeText( field.value ).then( function() {
                    copy.textContent = sheetsync.i18n.copied;
                    setTimeout( function() {
                        copy.textContent = sheetsync.i18n.copy;
                    }, 2000 );
                } );
            } );
        }
    } )();

    // ── Dashboards Page (dashboards-page.php) ─────────────────────────────────
    if ( $( '#ss-dash-panel-sales' ).length ) {
        var AJAX  = sheetsync.ajax_url;
        var NONCE = sheetsync.nonce;

        function loadSavedSettings() {
            $.post( AJAX, { action: 'sheetsync_load_dashboard_settings', nonce: NONCE } ).done( function( r ) {
                if ( ! r.success ) return;
                var s = r.data;
                if ( s.sd_spreadsheet_id )  $( '#ss_sd_spreadsheet_id' ).val( s.sd_spreadsheet_id );
                if ( s.sd_sheet_name )      $( '#ss_sd_sheet_name' ).val( s.sd_sheet_name );
                if ( s.sd_period )          $( '#ss_sd_period' ).val( s.sd_period );
                if ( s.inv_spreadsheet_id ) $( '#ss_inv_spreadsheet_id' ).val( s.inv_spreadsheet_id );
                if ( s.inv_sheet_name )     $( '#ss_inv_sheet_name' ).val( s.inv_sheet_name );
                if ( s.inv_low_stock )      $( '#ss_inv_low_stock' ).val( s.inv_low_stock );
                if ( s.boe_spreadsheet_id ) $( '#ss_boe_spreadsheet_id' ).val( s.boe_spreadsheet_id );
                if ( s.boe_sheet_name )     $( '#ss_boe_sheet_name' ).val( s.boe_sheet_name );
            } );
        }
        loadSavedSettings();

        var saveTimer;
        function scheduleSettingsSave() {
            clearTimeout( saveTimer );
            saveTimer = setTimeout( function() {
                $.post( AJAX, {
                    action                       : 'sheetsync_save_dashboard_settings',
                    nonce                        : NONCE,
                    'settings[sd_spreadsheet_id]'  : $( '#ss_sd_spreadsheet_id' ).val(),
                    'settings[sd_sheet_name]'       : $( '#ss_sd_sheet_name' ).val(),
                    'settings[sd_period]'           : $( '#ss_sd_period' ).val(),
                    'settings[inv_spreadsheet_id]'  : $( '#ss_inv_spreadsheet_id' ).val(),
                    'settings[inv_sheet_name]'      : $( '#ss_inv_sheet_name' ).val(),
                    'settings[inv_low_stock]'       : $( '#ss_inv_low_stock' ).val(),
                    'settings[boe_spreadsheet_id]'  : $( '#ss_boe_spreadsheet_id' ).val(),
                    'settings[boe_sheet_name]'      : $( '#ss_boe_sheet_name' ).val(),
                } );
            }, 800 );
        }
        $( '#ss_sd_spreadsheet_id,#ss_sd_sheet_name,#ss_sd_period,#ss_inv_spreadsheet_id,#ss_inv_sheet_name,#ss_inv_low_stock,#ss_boe_spreadsheet_id,#ss_boe_sheet_name' ).on( 'input change', scheduleSettingsSave );

        // Tab switching
        $( document ).on( 'click', '.ss-dash-tab-btn', function() {
            var t = $( this ).data( 'target' );
            $( '.ss-dash-tab-btn' ).removeClass( 'ss-dash-tab-active' );
            $( this ).addClass( 'ss-dash-tab-active' );
            $( '.ss-dash-panel' ).hide();
            $( '#' + t ).show();
        } );

        function fmtMoney( v ) { return '$' + ( parseFloat( v ) || 0 ).toLocaleString( 'en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 } ); }
        function showRes( $el, html, type ) { $el.attr( 'class', 'ss-dash-result ss-res-' + type ).html( html ).show(); }
        function btnLoad( $b, on ) {
            if ( on ) { $b.data( 'oh', $b.html() ).prop( 'disabled', true ).html( '<span class="dashicons dashicons-update ss-spin"></span> Loading&hellip;' ); }
            else { $b.prop( 'disabled', false ).html( $b.data( 'oh' ) || $b.html() ); }
        }

        // SALES DASHBOARD - Preview
        $( '#ss_sd_preview_btn' ).on( 'click', function() {
            var $b = $( this ), $panel = $( '#ss_sd_preview_panel' ), $res = $( '#ss_sd_result' );
            btnLoad( $b, true );
            showRes( $res, '<span class="dashicons dashicons-update ss-spin"></span> Loading preview&hellip;', 'loading' );
            $.post( AJAX, { action: 'sheetsync_get_sales_preview', nonce: NONCE, period: $( '#ss_sd_period' ).val() } )
            .done( function( r ) {
                btnLoad( $b, false ); $res.hide();
                if ( ! r.success ) { showRes( $res, '&#10060; ' + ( r.data && r.data.message || sheetsync.i18n.error_generic ), 'error' ); return; }
                var d = r.data, s = d.summary;
                var h = '<div class="ss-stat-row">'
                    + '<div class="ss-stat-box"><strong>' + fmtMoney( s.total_revenue ) + '</strong><span>Total Revenue</span></div>'
                    + '<div class="ss-stat-box"><strong>' + s.total_orders + '</strong><span>Total Orders</span></div>'
                    + '<div class="ss-stat-box"><strong>' + fmtMoney( s.avg_order_value ) + '</strong><span>Avg Order Value</span></div>'
                    + '</div>';
                h += '<p class="ss-preview-heading">Monthly Sales (last 6 months)</p>';
                h += '<table class="ss-mini-table"><thead><tr><th>Month</th><th>Orders</th><th>Revenue</th></tr></thead><tbody>';
                $.each( ( d.monthly || [] ).slice( -6 ), function( i, m ) { h += '<tr><td>' + m.month + '</td><td>' + m.orders + '</td><td>' + fmtMoney( m.revenue ) + '</td></tr>'; } );
                h += '</tbody></table>';
                if ( d.top_products && d.top_products.length ) {
                    h += '<p class="ss-preview-heading">Top Products</p>';
                    h += '<table class="ss-mini-table"><thead><tr><th>#</th><th>Product</th><th>Qty</th></tr></thead><tbody>';
                    $.each( d.top_products.slice( 0, 5 ), function( i, p ) {
                        var safeRank = parseInt( p.rank, 10 ) || 0;
                        var safeQty  = parseInt( p.quantity, 10 ) || 0;
                        h += '<tr><td><b>' + safeRank + '</b></td><td>' + $( '<s>' ).text( p.name ).html() + '</td><td>' + safeQty + '</td></tr>';
                    } );
                    h += '</tbody></table>';
                }
                $panel.html( h );
            } ).fail( function() { btnLoad( $b, false ); showRes( $res, '&#10060; Request failed.', 'error' ); } );
        } );

        // SALES DASHBOARD - Export
        $( '#ss_sd_export_btn' ).on( 'click', function() {
            var $b = $( this ), $res = $( '#ss_sd_result' );
            var sid = $.trim( $( '#ss_sd_spreadsheet_id' ).val() );
            if ( ! sid ) { showRes( $res, '&#10060; ' + ( sheetsync.i18n.please_enter_spreadsheet || 'Please enter a Spreadsheet ID.' ), 'error' ); return; }
            btnLoad( $b, true ); showRes( $res, '<span class="dashicons dashicons-update ss-spin"></span> Exporting&hellip;', 'loading' );
            $.post( AJAX, { action: 'sheetsync_export_sales_dashboard', nonce: NONCE, spreadsheet_id: sid, sheet_name: $( '#ss_sd_sheet_name' ).val() || 'Sales Dashboard', period: $( '#ss_sd_period' ).val() } )
            .done( function( r ) {
                btnLoad( $b, false );
                if ( r.success ) { showRes( $res, '&#9989; ' + r.data.message + '<br><small>' + r.data.monthly_count + ' monthly | ' + r.data.daily_count + ' daily | ' + r.data.products_count + ' products</small>', 'success' ); }
                else { showRes( $res, '&#10060; ' + ( r.data && r.data.message || 'Export failed.' ), 'error' ); }
            } ).fail( function() { btnLoad( $b, false ); showRes( $res, '&#10060; Request failed.', 'error' ); } );
        } );

        // INVENTORY - Preview
        $( '#ss_inv_preview_btn' ).on( 'click', function() {
            var $b = $( this ), $panel = $( '#ss_inv_preview_panel' ), $res = $( '#ss_inv_result' );
            btnLoad( $b, true ); showRes( $res, '<span class="dashicons dashicons-update ss-spin"></span> Loading&hellip;', 'loading' );
            $.post( AJAX, { action: 'sheetsync_get_inventory_preview', nonce: NONCE, low_stock_threshold: parseInt( $( '#ss_inv_low_stock' ).val(), 10 ) || 5 } )
            .done( function( r ) {
                btnLoad( $b, false ); $res.hide();
                if ( ! r.success ) { showRes( $res, '&#10060; ' + ( r.data && r.data.message || sheetsync.i18n.error_generic ), 'error' ); return; }
                var d = r.data, s = d.summary;
                var h = '<div class="ss-stat-row">'
                    + '<div class="ss-stat-box"><strong>' + s.total_products + '</strong><span>Total</span></div>'
                    + '<div class="ss-stat-box ss-stat-warn"><strong>' + s.low_stock + '</strong><span>Low Stock</span></div>'
                    + '<div class="ss-stat-box ss-stat-danger"><strong>' + s.out_of_stock + '</strong><span>Out of Stock</span></div>'
                    + '</div>';
                h += '<p class="ss-preview-heading">Products (by urgency)</p>';
                h += '<table class="ss-mini-table"><thead><tr><th>Product</th><th>Qty</th><th>Status</th></tr></thead><tbody>';
                var sorted = ( d.all_products || [] ).slice().sort( function( a, b ) { var o = { outofstock: 0, low_stock: 1, instock: 2 }; return ( o[ a.status ] || 2 ) - ( o[ b.status ] || 2 ); } );
                $.each( sorted.slice( 0, 10 ), function( i, p ) {
                    var pm = { instock: 'ss-pill-in', low_stock: 'ss-pill-low', outofstock: 'ss-pill-out' };
                    var lm = { instock: 'In Stock', low_stock: '&#9888; Low', outofstock: '&#10007; Out' };
                    h += '<tr><td>' + $( '<s>' ).text( p.name ).html() + '</td><td>' + ( p.stock_qty !== undefined && p.stock_qty !== null ? parseInt( p.stock_qty, 10 ) : 'N/A' ) + '</td><td><span class="ss-pill ' + ( pm[ p.status ] || '' ) + '">' + ( lm[ p.status ] || p.status ) + '</span></td></tr>';
                } );
                h += '</tbody></table>';
                if ( d.categories && d.categories.length ) {
                    h += '<p class="ss-preview-heading">By Category</p>';
                    h += '<table class="ss-mini-table"><thead><tr><th>Category</th><th>Total</th><th>In</th><th>Low</th><th>Out</th></tr></thead><tbody>';
                    $.each( d.categories.slice( 0, 5 ), function( i, c ) {
                        var safeTotal    = parseInt( c.total, 10 ) || 0;
                        var safeInStock  = parseInt( c.in_stock, 10 ) || 0;
                        var safeLow      = parseInt( c.low_stock, 10 ) || 0;
                        var safeOut      = parseInt( c.out_of_stock, 10 ) || 0;
                        h += '<tr><td>' + $( '<s>' ).text( c.name ).html() + '</td><td>' + safeTotal + '</td><td>' + safeInStock + '</td><td>' + safeLow + '</td><td>' + safeOut + '</td></tr>';
                    } );
                    h += '</tbody></table>';
                }
                $panel.html( h );
            } ).fail( function() { btnLoad( $b, false ); showRes( $res, '&#10060; Request failed.', 'error' ); } );
        } );

        // INVENTORY - Export
        $( '#ss_inv_export_btn' ).on( 'click', function() {
            var $b = $( this ), $res = $( '#ss_inv_result' );
            var sid = $.trim( $( '#ss_inv_spreadsheet_id' ).val() );
            if ( ! sid ) { showRes( $res, '&#10060; ' + ( sheetsync.i18n.please_enter_spreadsheet || 'Please enter a Spreadsheet ID.' ), 'error' ); return; }
            btnLoad( $b, true ); showRes( $res, '<span class="dashicons dashicons-update ss-spin"></span> Exporting&hellip;', 'loading' );
            $.post( AJAX, { action: 'sheetsync_export_inventory_dashboard', nonce: NONCE, spreadsheet_id: sid, sheet_name: $( '#ss_inv_sheet_name' ).val() || 'Inventory Status', low_stock_threshold: parseInt( $( '#ss_inv_low_stock' ).val(), 10 ) || 5 } )
            .done( function( r ) {
                btnLoad( $b, false );
                if ( r.success ) { showRes( $res, '&#9989; ' + r.data.message + '<br><small>' + r.data.total + ' products | &#9888; ' + r.data.low_stock + ' low | &#10007; ' + r.data.out_of_stock + ' out</small>', 'success' ); }
                else { showRes( $res, '&#10060; ' + ( r.data && r.data.message || 'Export failed.' ), 'error' ); }
            } ).fail( function() { btnLoad( $b, false ); showRes( $res, '&#10060; Request failed.', 'error' ); } );
        } );

        // BULK ORDER - shared payload builder
        function boePay( extra ) {
            var s = [];
            $( 'input[name="boe_statuses[]"]:checked' ).each( function() { s.push( $( this ).val() ); } );
            return $.extend( { nonce: NONCE, statuses: s, date_from: $( '#ss_boe_date_from' ).val(), date_to: $( '#ss_boe_date_to' ).val(), customer: $( '#ss_boe_customer' ).val(), min_total: $( '#ss_boe_min_total' ).val(), max_total: $( '#ss_boe_max_total' ).val() }, extra || {} );
        }

        $( '#ss_boe_count_btn' ).on( 'click', function() {
            var $b = $( this ), $res = $( '#ss_boe_result' ), $cnt = $( '#ss_boe_count_display' );
            btnLoad( $b, true ); showRes( $res, '<span class="dashicons dashicons-update ss-spin"></span> Counting&hellip;', 'loading' ); $cnt.hide();
            $.post( AJAX, boePay( { action: 'sheetsync_bulk_order_count' } ) )
            .done( function( r ) {
                btnLoad( $b, false ); $res.hide();
                if ( r.success ) { var safeCount = parseInt( r.data.count, 10 ) || 0; $cnt.html( '<span class="dashicons dashicons-yes-alt"></span> <b>' + safeCount + '</b> orders match your filters' ).show(); }
                else { showRes( $res, '&#10060; ' + ( r.data && r.data.message || sheetsync.i18n.error_generic ), 'error' ); }
            } ).fail( function() { btnLoad( $b, false ); showRes( $res, '&#10060; Request failed.', 'error' ); } );
        } );

        $( '#ss_boe_csv_btn' ).on( 'click', function() {
            var $b = $( this ), $res = $( '#ss_boe_result' );
            btnLoad( $b, true ); showRes( $res, '<span class="dashicons dashicons-update ss-spin"></span> Generating CSV&hellip;', 'loading' );
            $.post( AJAX, boePay( { action: 'sheetsync_bulk_order_export_csv' } ) )
            .done( function( r ) {
                btnLoad( $b, false );
                if ( r.success ) {
                    var blob = new Blob( [ r.data.csv ], { type: 'text/csv;charset=utf-8;' } );
                    var url = URL.createObjectURL( blob ), a = document.createElement( 'a' );
                    a.href = url; a.download = r.data.filename || 'orders-export.csv';
                    document.body.appendChild( a ); a.click(); document.body.removeChild( a ); URL.revokeObjectURL( url );
                    showRes( $res, '&#9989; CSV downloaded! (' + r.data.order_count + ' orders)', 'success' );
                } else { showRes( $res, '&#10060; ' + ( r.data && r.data.message || 'Export failed.' ), 'error' ); }
            } ).fail( function() { btnLoad( $b, false ); showRes( $res, '&#10060; Request failed.', 'error' ); } );
        } );

        $( '#ss_boe_sheets_btn' ).on( 'click', function() {
            var $b = $( this ), $res = $( '#ss_boe_result' );
            var sid = $.trim( $( '#ss_boe_spreadsheet_id' ).val() );
            if ( ! sid ) { showRes( $res, '&#10060; ' + ( sheetsync.i18n.please_enter_spreadsheet || 'Please enter a Spreadsheet ID.' ), 'error' ); return; }
            btnLoad( $b, true ); showRes( $res, '<span class="dashicons dashicons-update ss-spin"></span> Exporting to Google Sheets&hellip;', 'loading' );
            $.post( AJAX, boePay( { action: 'sheetsync_bulk_order_export_sheets', spreadsheet_id: sid, sheet_name: $( '#ss_boe_sheet_name' ).val() || 'Orders Export' } ) )
            .done( function( r ) {
                btnLoad( $b, false );
                if ( r.success ) { showRes( $res, '&#9989; ' + r.data.message + '<br><small>' + r.data.rows_written + ' rows written</small>', 'success' ); }
                else { showRes( $res, '&#10060; ' + ( r.data && r.data.message || 'Export failed.' ), 'error' ); }
            } ).fail( function() { btnLoad( $b, false ); showRes( $res, '&#10060; Request failed.', 'error' ); } );
        } );
    }

    // ── Import/Export Page (import-export-page.php) ───────────────────────────
    if ( $( '#ss-step-1' ).length ) {
        var wc_fields = {
            ''               : '— Skip —',
            '_sku'           : 'SKU (Product Key)',
            'post_title'     : 'Product Title',
            '_regular_price' : 'Regular Price',
            '_sale_price'    : 'Sale Price',
            '_stock'         : 'Stock Quantity',
            'post_status'    : 'Product Status',
            'post_excerpt'   : 'Short Description',
            '_weight'        : 'Weight',
            '_product_image' : 'Featured Image URL',
            '_product_cats'  : 'Categories',
            '_product_tags'  : 'Tags',
        };

        function autoDetect( header ) {
            var h = header.toLowerCase().trim();
            if ( h.indexOf( 'sku' ) !== -1 )                                            return '_sku';
            if ( h.indexOf( 'title' ) !== -1 || h.indexOf( 'name' ) !== -1 )           return 'post_title';
            if ( h.indexOf( 'regular' ) !== -1 || h.indexOf( 'price' ) !== -1 )        return '_regular_price';
            if ( h.indexOf( 'sale' ) !== -1 )                                           return '_sale_price';
            if ( h.indexOf( 'stock' ) !== -1 || h.indexOf( 'quantity' ) !== -1 )       return '_stock';
            if ( h.indexOf( 'status' ) !== -1 )                                         return 'post_status';
            if ( h.indexOf( 'description' ) !== -1 )                                   return 'post_excerpt';
            if ( h.indexOf( 'weight' ) !== -1 )                                         return '_weight';
            if ( h.indexOf( 'image' ) !== -1 || h.indexOf( 'photo' ) !== -1 )          return '_product_image';
            if ( h.indexOf( 'categor' ) !== -1 )                                        return '_product_cats';
            if ( h.indexOf( 'tag' ) !== -1 )                                            return '_product_tags';
            return '';
        }

        function colLetter( idx ) {
            var letter = '', n = idx + 1;
            while ( n > 0 ) {
                n--;
                letter = String.fromCharCode( 65 + ( n % 26 ) ) + letter;
                n = Math.floor( n / 26 );
            }
            return letter;
        }

        var sheetHeaders = [];
        var connData = {};

        function buildMappingTable( headers ) {
            var $tbody = $( '#ss-header-rows' ).empty();
            $.each( headers, function( idx, header ) {
                var col = colLetter( idx );
                var auto = autoDetect( header );
                var options = '';
                $.each( wc_fields, function( val, label ) {
                    var sel = val === auto ? ' selected' : '';
                    options += '<option value="' + val + '"' + sel + '>' + label + '</option>';
                } );
                $tbody.append(
                    '<tr>' +
                    '<td><strong>' + col + '</strong></td>' +
                    '<td>' + ( header ? $( '<span>' ).text( header ).html() : '<em style="color:#9ca3af">empty</em>' ) + '</td>' +
                    '<td><select class="ss-field-select" data-col="' + col + '" data-idx="' + idx + '">' + options + '</select></td>' +
                    '</tr>'
                );
            } );
        }

        $( '#ss-load-headers' ).on( 'click', function() {
            var $sel = $( '#ss-import-conn' );
            var connId = $sel.val();
            if ( ! connId ) {
                $( '#ss-step-1' ).find( '.ss-inline-err' ).remove();
                var selMsg = $( '<span>' ).text( sheetsync.i18n.please_select_connection || 'Please select a connection first.' ).html();
                $( '#ss-import-conn' ).after( '<p class="ss-inline-err" style="color:#dc2626;margin:4px 0 0;">' + selMsg + '</p>' );
                return;
            }
            var opt = $sel.find( 'option:selected' );
            connData = { id: connId, spreadsheet: opt.data( 'spreadsheet' ), sheet: opt.data( 'sheet' ), header: opt.data( 'header' ) };
            $( '#ss-header-loader' ).show();
            $( this ).prop( 'disabled', true );
            $.post( sheetsync.ajax_url, { action: 'sheetsync_get_headers', nonce: sheetsync.nonce, connection_id: connId } )
            .done( function( res ) {
                if ( res.success && res.data.headers ) {
                    sheetHeaders = res.data.headers;
                    buildMappingTable( sheetHeaders );
                    $( '#ss-step-1' ).hide();
                    $( '#ss-step-2' ).show();
                } else {
                    $( '#ss-step-1' ).find( '.ss-inline-err' ).remove();
                    var hdrMsg = ( res.data && res.data.message ) || ( sheetsync.i18n && sheetsync.i18n.unknown_error ) || 'Unknown error';
                    $( '#ss-import-conn' ).after( '<p class="ss-inline-err" style="color:#dc2626;margin:4px 0 0;">' + ( sheetsync.i18n && sheetsync.i18n.headers_load_failed ? sheetsync.i18n.headers_load_failed + ' ' : 'Failed to load headers: ' ) + $( '<span>' ).text( hdrMsg ).html() + '</p>' );
                }
            } )
            .fail( function() { $( '#ss-progress-text' ).text( 'Request failed.' ); } )
            .always( function() {
                $( '#ss-header-loader' ).hide();
                $( '#ss-load-headers' ).prop( 'disabled', false );
            } );
        } );

        $( '#ss-back-step1' ).on( 'click', function() {
            $( '#ss-step-2' ).hide();
            $( '#ss-step-1' ).show();
        } );

        $( '#ss-start-import' ).on( 'click', function() {
            var fieldMap = {};
            var hasSku = false;
            $( '.ss-field-select' ).each( function() {
                var val = $( this ).val();
                if ( ! val ) return;
                var col = $( this ).data( 'col' );
                fieldMap[ val ] = col;
                if ( val === '_sku' ) hasSku = true;
            } );
            if ( ! hasSku ) {
                $( '#ss-step-2 .ss-sku-warning' ).remove();
                var skuMsg = '⚠️ ' + ( sheetsync.i18n.sku_map_warning || 'Please map the SKU column — otherwise duplicate products will be created!' );
                $( '#ss-start-import' ).before( '<p class="ss-sku-warning" style="color:#dc2626;font-weight:600;">' + $( '<span>' ).text( skuMsg ).html() + '</p>' );
                return;
            }
            $( '#ss-step-2' ).hide();
            $( '#ss-step-3' ).show();
            $( '#ss-progress-bar' ).css( 'width', '5%' );
            $( '#ss-progress-text' ).text( 'Starting import...' );
            $.post( sheetsync.ajax_url, {
                action        : 'sheetsync_import_from_sheet',
                nonce         : sheetsync.nonce,
                connection_id : connData.id,
                field_map     : JSON.stringify( fieldMap ),
                skip_existing : $( '#ss-skip-existing' ).is( ':checked' ) ? 1 : 0,
                create_new    : $( '#ss-create-new' ).is( ':checked' ) ? 1 : 0,
            } )
            .done( function( res ) {
                $( '#ss-progress-bar' ).css( 'width', '100%' );
                if ( res.success ) {
                    var d = res.data;
                    var $log = $( '#ss-import-log' );
                    if ( d.log && d.log.length ) {
                        $.each( d.log, function( i, line ) {
                            var cls = line.type === 'created' ? 'log-ok' : line.type === 'skipped' ? 'log-skip' : 'log-err';
                            $log.append( '<div class="' + cls + '">' + $( '<span>' ).text( line.msg ).html() + '</div>' );
                        } );
                    }
                    setTimeout( function() {
                        $( '#ss-step-3' ).hide();
                        $( '#ss-import-summary' ).html(
                            '&#9989; <strong>' + d.created + '</strong> new product(s) created | ' +
                            '&#x1F504; <strong>' + d.updated + '</strong> updated | ' +
                            '&#x23ED;&#xFE0F; <strong>' + d.skipped + '</strong> skipped'
                        );
                        $( '#ss-step-4' ).show();
                    }, 800 );
                } else {
                    $( '#ss-progress-text' ).text( '\u274C Error: ' + ( ( res.data && res.data.message ) || sheetsync.i18n.import_failed ) );
                }
            } )
            .fail( function() {
                $( '#ss-progress-text' ).text( '\u274C Request failed.' );
            } );
        } );

        $( '#ss-import-again' ).on( 'click', function() {
            $( '#ss-step-4' ).hide();
            $( '#ss-step-1' ).show();
            $( '#ss-import-log' ).empty();
            $( '#ss-progress-bar' ).css( 'width', '0%' );
        } );
    }

} )( jQuery );
