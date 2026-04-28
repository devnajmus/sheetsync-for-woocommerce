=== SheetSync for WooCommerce ===
Contributors: MD Najmus Shadat
Tags: woocommerce, google sheets, sync, products, spreadsheet
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
WC requires at least: 7.0
WC tested up to: 9.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync WooCommerce products with Google Sheets. No Composer needed — works on any hosting.

== Description ==

**SheetSync for WooCommerce** connects your WooCommerce store to Google Sheets, letting you manage products directly from a spreadsheet.

= Free Features =
* Sync products from Google Sheets → WooCommerce
* Map sheet columns to product fields (SKU, Title, Price, Stock, Status)
* Manual sync with one click
* 1 active connection
* Sync logs

= Pro Features =
* **Orders sync** — sync all orders or filter by status (Processing, Completed etc.)
* **WooCommerce → Google Sheets** direction
* **Two-way sync**
* **Auto sync** — real-time updates via Google Apps Script webhook
* **Multiple connections** — unlimited sheets
* **More product fields** — Sale Price, Categories, Tags, Images, Weight, Dimensions
* **Email notifications** on sync complete/fail
* **Import/Export** connections

[Upgrade to Pro →](https://najmussgadat.com/sheetsync/pricing)

= Requirements =
* WordPress 6.0+
* WooCommerce 7.0+
* PHP 8.0+
* PHP OpenSSL extension
* A Google Cloud Service Account with Sheets API enabled

= How to Connect Google Sheets =
1. Go to [Google Cloud Console](https://console.cloud.google.com) and create a project
2. Enable the **Google Sheets API**
3. Create a **Service Account** and download the JSON key
4. In SheetSync Settings, paste the JSON key
5. Share your Google Sheet with the service account email (Editor role)

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu
3. Go to **SheetSync → Settings** and paste your Google Service Account JSON
4. Go to **SheetSync → Connections** and create your first connection

== Frequently Asked Questions ==

= Do I need Composer or any server setup? =
No. SheetSync uses only the WordPress HTTP API and PHP's built-in OpenSSL — no Composer, no extra libraries.

= Is my Google API key stored securely? =
Yes. The Service Account JSON is encrypted using AES-256 before being stored in the database.

= How many products can I sync? =
The free version supports up to 500 products per sync. Pro supports unlimited with batch processing.

= Does it work with WooCommerce HPOS? =
Yes, SheetSync is fully compatible with WooCommerce High-Performance Order Storage (HPOS).

== Changelog ==
= 1.0.0 =
* Initial release

== External Services ==

This plugin connects to the following external services:

= Google Sheets API =
Used to read and write data to your Google Sheets spreadsheet.
* Service: Google Sheets API (googleapis.com)
* When: During sync operations and connection tests
* Data sent: Product data you choose to sync
* Privacy Policy: https://policies.google.com/privacy
* Terms of Service: https://developers.google.com/terms

= Google OAuth2 =
Used to authenticate with Google APIs using a Service Account.
* Service: Google OAuth 2.0 (oauth2.googleapis.com)
* When: During every sync, when an access token is needed
* Data sent: A signed JWT using your Service Account credentials
* Privacy Policy: https://policies.google.com/privacy
* Terms of Service: https://developers.google.com/terms

= Freemius =
Used for license management and plugin updates (Pro version).
* Service: Freemius (freemius.com)
* When: Only after explicit user opt-in during plugin activation
* Data sent: Site URL, WordPress version, plugin version (anonymised)
* Privacy Policy: https://freemius.com/privacy/
* Terms of Service: https://freemius.com/terms/

No data is sent to any external service without your explicit action or consent.

