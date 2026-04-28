<?php
/**
 * Google Sheets API v4 client — uses WordPress HTTP API only.
 * Zero dependencies. No Composer. Works on any shared hosting.
 * @package SheetSync_For_WooCommerce
 * @since   1.0.0
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SheetSync_Sheets_Client' ) ) :

class SheetSync_Sheets_Client {

    private const BASE = 'https://sheets.googleapis.com/v4/spreadsheets';

    /**
     * Read all rows from a range.
     * Returns array of rows, each row is array of cell values (strings).
     */
    public function get_rows( string $spreadsheet_id, string $range ): array {
        $url      = self::BASE . '/' . rawurlencode( $spreadsheet_id )
                  . '/values/' . rawurlencode( $range );
        $response = SheetSync_Google_Auth::api_get( $url );
        return $response['values'] ?? [];
    }

    /**
     * Overwrite rows at a given range.
     */
    public function set_rows( string $spreadsheet_id, string $range, array $data ): void {
        $url = self::BASE . '/' . rawurlencode( $spreadsheet_id )
             . '/values/' . rawurlencode( $range )
             . '?valueInputOption=USER_ENTERED';

        SheetSync_Google_Auth::api_put( $url, [ 'values' => $data ] );
    }

    /**
     * Append rows to the end of existing data.
     */
    public function append_rows( string $spreadsheet_id, string $range, array $data ): void {
        $url = self::BASE . '/' . rawurlencode( $spreadsheet_id )
             . '/values/' . rawurlencode( $range )
             . ':append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS';

        SheetSync_Google_Auth::api_post( $url, [ 'values' => $data ] );
    }

    /**
     * Update a single cell.
     */
    public function update_cell( string $spreadsheet_id, string $sheet_name, int $row, string $col, $value ): void {
        $range = $sheet_name . '!' . $col . $row;
        $this->set_rows( $spreadsheet_id, $range, [ [ (string) $value ] ] );
    }

    /**
     * Get spreadsheet metadata (title, sheet tab names).
     */
    public function get_metadata( string $spreadsheet_id ): array {
        $url      = self::BASE . '/' . rawurlencode( $spreadsheet_id );
        $response = SheetSync_Google_Auth::api_get( $url );
        $sheets   = array_map(
            fn( $s ) => $s['properties']['title'],
            $response['sheets'] ?? []
        );
        return [
            'title'  => $response['properties']['title'] ?? '',
            'sheets' => $sheets,
        ];
    }

    /**
     * Delete a single row from a sheet using the batchUpdate API.
     * Row index is 0-based for the API (so pass row_num - 1).
     *
     * @param string $spreadsheet_id
     * @param string $sheet_name      Tab name (used to look up sheetId).
     * @param int    $row_num         1-based row number to delete.
     */
    public function delete_row( string $spreadsheet_id, string $sheet_name, int $row_num ): void {
        $sheet_id = $this->get_sheet_id( $spreadsheet_id, $sheet_name );

        $url  = self::BASE . '/' . rawurlencode( $spreadsheet_id ) . ':batchUpdate';
        $body = array(
            'requests' => array(
                array(
                    'deleteDimension' => array(
                        'range' => array(
                            'sheetId'    => $sheet_id,
                            'dimension'  => 'ROWS',
                            'startIndex' => $row_num - 1, // 0-based
                            'endIndex'   => $row_num,     // exclusive
                        ),
                    ),
                ),
            ),
        );

        SheetSync_Google_Auth::api_post( $url, $body );
    }

    /**
     * Get the numeric sheetId for a named tab.
     * Caches result per spreadsheet per request.
     *
     * @param string $spreadsheet_id
     * @param string $sheet_name
     * @return int sheetId (0 = first sheet)
     * @throws RuntimeException if tab not found.
     */
    public function get_sheet_id( string $spreadsheet_id, string $sheet_name ): int {
        static $cache = array();

        $cache_key = $spreadsheet_id . '::' . $sheet_name;
        if ( isset( $cache[ $cache_key ] ) ) {
            return $cache[ $cache_key ];
        }

        $url      = self::BASE . '/' . rawurlencode( $spreadsheet_id ) . '?fields=sheets.properties';
        $response = SheetSync_Google_Auth::api_get( $url );

        foreach ( $response['sheets'] ?? array() as $sheet ) {
            $title = $sheet['properties']['title'] ?? '';
            $id    = (int) ( $sheet['properties']['sheetId'] ?? 0 );
            $cache[ $spreadsheet_id . '::' . $title ] = $id;
        }

        if ( ! isset( $cache[ $cache_key ] ) ) {
            throw new RuntimeException(
                esc_html(
                    sprintf(
                        /* translators: %s: Google Sheet tab name */
                        __( "Sheet tab '%s' not found in spreadsheet.", 'sheetsync-for-woocommerce' ),
                        $sheet_name
                    )
                )
            );
        }

        return $cache[ $cache_key ];
    }

    /**
     * Ensure a sheet tab exists; create it if it doesn't.
     * Returns the sheetId (numeric) of the tab.
     */
    public function ensure_sheet_exists( string $spreadsheet_id, string $sheet_name ): int {
        // Try to get existing sheet id first
        try {
            return $this->get_sheet_id( $spreadsheet_id, $sheet_name );
        } catch ( RuntimeException $e ) {
            // Tab not found — create it
        }

        $url  = self::BASE . '/' . rawurlencode( $spreadsheet_id ) . ':batchUpdate';
        $body = array(
            'requests' => array(
                array(
                    'addSheet' => array(
                        'properties' => array(
                            'title' => $sheet_name,
                        ),
                    ),
                ),
            ),
        );

        $result   = SheetSync_Google_Auth::api_post( $url, $body );
        $sheet_id = (int) ( $result['replies'][0]['addSheet']['properties']['sheetId'] ?? 0 );

        // Cache the new sheet id
        // (get_sheet_id uses a static cache keyed by spreadsheet_id::sheet_name)
        // Re-call to populate the cache properly
        try {
            return $this->get_sheet_id( $spreadsheet_id, $sheet_name );
        } catch ( RuntimeException $e ) {
            return $sheet_id; // fallback to what API returned
        }
    }

    /**
     * Clear all content from a sheet tab (preserves formatting).
     * Uses the correct empty-object body that the Sheets API expects.
     */
    public function clear_sheet( string $spreadsheet_id, string $sheet_name ): void {
        $url = self::BASE . '/' . rawurlencode( $spreadsheet_id )
             . '/values/' . rawurlencode( $sheet_name ) . ':clear';

        // The Sheets clear endpoint requires an empty JSON object body {}, NOT an array [].
        // wp_json_encode( new stdClass() ) produces "{}" correctly.
        $response = wp_remote_post( $url, array(
            'timeout'   => 30,
            'sslverify' => true,
            'headers'   => array(
                'Authorization' => 'Bearer ' . SheetSync_Google_Auth::get_access_token(),
                'Content-Type'  => 'application/json',
            ),
            'body' => '{}',
        ) );

        if ( is_wp_error( $response ) ) {
            throw new RuntimeException( esc_html( $response->get_error_message() ) );
        }
    }

    /**
     * Write a styled header row to the Google Sheet.
     *
     * Writes the header values, then applies formatting:
     *  - Bold text, white foreground
     *  - Dark green background (#1e7e34)
     *  - Frozen first row
     *  - Auto-resize columns
     *
     * @param string   $spreadsheet_id
     * @param string   $sheet_name
     * @param int      $header_row    1-based row number for headers.
     * @param string[] $headers       Ordered list of header label strings.
     */
    public function write_styled_headers(
        string $spreadsheet_id,
        string $sheet_name,
        int    $header_row,
        array  $headers
    ): void {

        $sheet_id   = $this->get_sheet_id( $spreadsheet_id, $sheet_name );
        $col_count  = count( $headers );
        $row_index  = $header_row - 1; // 0-based

        // ── 1. Write header values ────────────────────────────────────────
        $col_end = SheetSync_Field_Mapper::index_to_col( $col_count - 1 );
        $range   = $sheet_name . '!A' . $header_row . ':' . $col_end . $header_row;
        $this->set_rows( $spreadsheet_id, $range, [ $headers ] );

        // ── 2. Build batchUpdate requests ─────────────────────────────────
        $requests = array();

        // 2a. Style: bold + white text + dark green background + center align
        $requests[] = array(
            'repeatCell' => array(
                'range' => array(
                    'sheetId'          => $sheet_id,
                    'startRowIndex'    => $row_index,
                    'endRowIndex'      => $row_index + 1,
                    'startColumnIndex' => 0,
                    'endColumnIndex'   => $col_count,
                ),
                'cell' => array(
                    'userEnteredFormat' => array(
                        'backgroundColor' => array(
                            'red'   => 0.1176,  // #1e  = 30/255
                            'green' => 0.4941,  // #7e  = 126/255
                            'blue'  => 0.2039,  // #34  = 52/255
                        ),
                        'textFormat' => array(
                            'bold'       => true,
                            'fontSize'   => 11,
                            'foregroundColor' => array(
                                'red'   => 1.0,
                                'green' => 1.0,
                                'blue'  => 1.0,
                            ),
                        ),
                        'horizontalAlignment' => 'CENTER',
                        'verticalAlignment'   => 'MIDDLE',
                        'wrapStrategy'        => 'CLIP',
                    ),
                ),
                'fields' => 'userEnteredFormat(backgroundColor,textFormat,horizontalAlignment,verticalAlignment,wrapStrategy)',
            ),
        );

        // 2b. Freeze the header row
        $requests[] = array(
            'updateSheetProperties' => array(
                'properties' => array(
                    'sheetId'     => $sheet_id,
                    'gridProperties' => array(
                        'frozenRowCount' => $header_row,
                    ),
                ),
                'fields' => 'gridProperties.frozenRowCount',
            ),
        );

        // 2c. Auto-resize all header columns
        $requests[] = array(
            'autoResizeDimensions' => array(
                'dimensions' => array(
                    'sheetId'    => $sheet_id,
                    'dimension'  => 'COLUMNS',
                    'startIndex' => 0,
                    'endIndex'   => $col_count,
                ),
            ),
        );

        // 2d. Set row height for the header row to 32px
        $requests[] = array(
            'updateDimensionProperties' => array(
                'range' => array(
                    'sheetId'    => $sheet_id,
                    'dimension'  => 'ROWS',
                    'startIndex' => $row_index,
                    'endIndex'   => $row_index + 1,
                ),
                'properties' => array(
                    'pixelSize' => 32,
                ),
                'fields' => 'pixelSize',
            ),
        );

        $url = self::BASE . '/' . rawurlencode( $spreadsheet_id ) . ':batchUpdate';
        SheetSync_Google_Auth::api_post( $url, array( 'requests' => $requests ) );

        // ── Also style any existing data rows below the header ───────────
        try {
            $all_rows = $this->get_rows( $spreadsheet_id, "{$sheet_name}!A:A" );
            $data_count = count( $all_rows ) - $header_row;
            if ( $data_count > 0 ) {
                $this->apply_row_colors( $spreadsheet_id, $sheet_name, $header_row, $data_count, $col_count );
            }
        } catch ( \Exception $e ) {
            // Non-fatal
        }
    }

    /**
     * Apply alternating row colors to data rows using Google Sheets BandedRange API.
     *
     * Uses a single batchUpdate with:
     *  1. addBanding  — server-side alternating row colors (1 request, works for any row count)
     *  2. updateDimensionProperties — row height (24px) for all data rows at once
     *  3. updateDimensionProperties — column widths (one request per column)
     *  4. updateSheetProperties — freeze header row
     *
     * Total: ~4 requests regardless of row count. Replaces the old O(N) per-row approach
     * that caused quota exhaustion for large catalogs.
     *
     * Colors:
     *  - First band (odd rows)  : white       (#FFFFFF)
     *  - Second band (even rows): light green  (#EAF7EE)
     * Header: dark green (#1e7e34) with white bold text — set by write_styled_headers().
     *
     * @param string $spreadsheet_id
     * @param string $sheet_name
     * @param int    $header_row     1-based header row number.
     * @param int    $data_row_count Number of data rows to style.
     * @param int    $col_count      Number of columns.
     */
    public function apply_row_colors(
        string $spreadsheet_id,
        string $sheet_name,
        int    $header_row,
        int    $data_row_count,
        int    $col_count
    ): void {

        if ( $data_row_count < 1 || $col_count < 1 ) return;

        $sheet_id   = $this->get_sheet_id( $spreadsheet_id, $sheet_name );
        $first_data = $header_row; // 0-based index of first data row

        $c = fn( float $r, float $g, float $b ) => array( 'red' => $r, 'green' => $g, 'blue' => $b );

        $white       = $c( 1.0,   1.0,   1.0   );   // odd rows  — #FFFFFF
        $light_green = $c( 0.918, 0.969, 0.933 );   // even rows — #EAF7EE
        $dark_text   = $c( 0.102, 0.102, 0.102 );   // text      — #1a1a1a

        $requests = array();

        // ── 1. Remove existing banded ranges on this sheet first ──────────
        // Prevents duplicate bands stacking up on repeated syncs.
        try {
            $meta = SheetSync_Google_Auth::api_get(
                self::BASE . '/' . rawurlencode( $spreadsheet_id )
                . '?fields=sheets(properties(sheetId,title),bandedRanges)'
            );
            foreach ( $meta['sheets'] ?? array() as $s ) {
                if ( ( $s['properties']['sheetId'] ?? -1 ) !== $sheet_id ) continue;
                foreach ( $s['bandedRanges'] ?? array() as $band ) {
                    if ( isset( $band['bandedRangeId'] ) ) {
                        $requests[] = array(
                            'deleteBanding' => array( 'bandedRangeId' => $band['bandedRangeId'] ),
                        );
                    }
                }
            }
        } catch ( \Exception $e ) {
            // Non-fatal — proceed without deleting old bands
        }

        // ── 2. Add BandedRange for alternating row colors ─────────────────
        // BandedRange is applied server-side by Google Sheets — zero extra API
        // calls per row. Works correctly for any number of rows.
        $requests[] = array(
            'addBanding' => array(
                'bandedRange' => array(
                    'range' => array(
                        'sheetId'          => $sheet_id,
                        'startRowIndex'    => $first_data,        // 0-based, first data row
                        'endRowIndex'      => $first_data + $data_row_count,
                        'startColumnIndex' => 0,
                        'endColumnIndex'   => $col_count,
                    ),
                    'rowProperties' => array(
                        'headerColor'     => null,                 // no header (header styled separately)
                        'firstBandColor'  => $white,               // odd rows — white
                        'secondBandColor' => $light_green,         // even rows — #EAF7EE
                    ),
                ),
            ),
        );

        // ── 3. Apply text formatting to ALL data rows (single repeatCell) ──
        // BandedRange handles background only; text format needs repeatCell.
        $requests[] = array(
            'repeatCell' => array(
                'range' => array(
                    'sheetId'          => $sheet_id,
                    'startRowIndex'    => $first_data,
                    'endRowIndex'      => $first_data + $data_row_count,
                    'startColumnIndex' => 0,
                    'endColumnIndex'   => $col_count,
                ),
                'cell' => array(
                    'userEnteredFormat' => array(
                        'textFormat'    => array(
                            'bold'            => false,
                            'fontSize'        => 10,
                            'foregroundColor' => $dark_text,
                        ),
                        'verticalAlignment' => 'MIDDLE',
                        'wrapStrategy'      => 'CLIP',
                    ),
                ),
                'fields' => 'userEnteredFormat(textFormat,verticalAlignment,wrapStrategy)',
            ),
        );

        // ── 4. Force ALL data rows to exactly 24px height (one request) ───
        $requests[] = array(
            'updateDimensionProperties' => array(
                'range' => array(
                    'sheetId'    => $sheet_id,
                    'dimension'  => 'ROWS',
                    'startIndex' => $first_data,
                    'endIndex'   => $first_data + $data_row_count,
                ),
                'properties' => array(
                    'pixelSize'    => 24,
                    'hiddenByUser' => false,
                ),
                'fields' => 'pixelSize',
            ),
        );

        // ── 5. Freeze header row ──────────────────────────────────────────
        $requests[] = array(
            'updateSheetProperties' => array(
                'properties' => array(
                    'sheetId'        => $sheet_id,
                    'gridProperties' => array(
                        'frozenRowCount' => $header_row,
                    ),
                ),
                'fields' => 'gridProperties.frozenRowCount',
            ),
        );

        // ── 6. Set column widths ──────────────────────────────────────────
        // Field-aware widths: SKU narrow, Title wide, descriptions wider.
        // Map column index to field name for smart widths.
        $col_field_map = array();
        // We don't have $maps here, so use position-based heuristics:
        // col 0 = SKU (narrow), col 1 = Title (wide), rest = medium
        for ( $c_idx = 0; $c_idx < $col_count; $c_idx++ ) {
            if ( $c_idx === 0 ) {
                $px = 100;   // SKU
            } elseif ( $c_idx === 1 ) {
                $px = 180;   // Product Title — wider
            } elseif ( $c_idx === 2 || $c_idx === 3 ) {
                $px = 110;   // Price / Stock
            } elseif ( $c_idx === 4 ) {
                $px = 110;   // Status
            } else {
                $px = 130;   // Pro fields — slightly wider
            }
            $requests[] = array(
                'updateDimensionProperties' => array(
                    'range' => array(
                        'sheetId'    => $sheet_id,
                        'dimension'  => 'COLUMNS',
                        'startIndex' => $c_idx,
                        'endIndex'   => $c_idx + 1,
                    ),
                    'properties' => array( 'pixelSize' => $px ),
                    'fields'     => 'pixelSize',
                ),
            );
        }

        $url = self::BASE . '/' . rawurlencode( $spreadsheet_id ) . ':batchUpdate';
        SheetSync_Google_Auth::api_post( $url, array( 'requests' => $requests ) );
    }

    /**
     * Find the row number (1-based) where a column contains a specific value.
     * Returns 0 if not found.
     */
    public function find_row_by_value(
        string $spreadsheet_id,
        string $sheet_name,
        string $column,
        string $value,
        int    $header_rows = 1
    ): int {
        $range    = $sheet_name . '!' . $column . ':' . $column;
        $col_data = $this->get_rows( $spreadsheet_id, $range );

        foreach ( $col_data as $i => $row ) {
            if ( $i < $header_rows ) continue;
            if ( ( $row[0] ?? '' ) === $value ) {
                return $i + 1; // 1-based
            }
        }
        return 0;
    }
}

endif; // class_exists SheetSync_Sheets_Client
