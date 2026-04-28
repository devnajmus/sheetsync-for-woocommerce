<?php
/**
 * Plugin Name:       SheetSync for WooCommerce
 * Plugin URI:        https://najmusshadat.com/sheetsync
 * Description:       Sync WooCommerce products with Google Sheets bidirectionally. No Composer required — works on any hosting environment.
 * Version:           1.0.0
 * Author:            MD Najmus Shadat
 * Author URI:        https://najmusshadat.com
 * Text Domain:       sheetsync-for-woocommerce
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * WC requires at least: 7.0
 * WC tested up to:   9.9
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

// ── Constants ─────────────────────────────────────────────────────────────────
if ( ! defined( 'SHEETSYNC_VERSION' ) ) {
	define( 'SHEETSYNC_VERSION', '1.1.0' );
}
if ( ! defined( 'SHEETSYNC_FILE' ) ) {
	define( 'SHEETSYNC_FILE', __FILE__ );
}
if ( ! defined( 'SHEETSYNC_DIR' ) ) {
	define( 'SHEETSYNC_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'SHEETSYNC_URL' ) ) {
	define( 'SHEETSYNC_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'SHEETSYNC_BASENAME' ) ) {
	define( 'SHEETSYNC_BASENAME', plugin_basename( __FILE__ ) );
}

// ── Freemius SDK ──────────────────────────────────────────────────────────────
// IMPORTANT: Both Free and Pro use the same function name: sheetsync_fs().
//
// The function_exists() guard serves two purposes:
//  1. Prevents a fatal "cannot redeclare" error if both plugins are briefly
//     active at the same time (e.g. during upgrade).
//  2. Allows the Pro plugin to define sheetsync_fs() first (with is_premium
//     => true), then call set_basename() so WordPress auto-deactivates the
//     Free version. If Pro has already defined sheetsync_fs(), we skip this
//     block entirely.
if ( ! function_exists( 'sheetsync_fs' ) ) {

	function sheetsync_fs() {
		global $sheetsync_fs;

		if ( ! isset( $sheetsync_fs ) ) {
			require_once SHEETSYNC_DIR . 'vendor/freemius/start.php';

			$sheetsync_fs = fs_dynamic_init( array(
				'id'                  => '26705',
				'slug'                => 'sheetsync-for-woocommerce',
				'type'                => 'plugin',
				'public_key'          => 'pk_3bb51306d3a82102a268cc87e8d6d',
				'is_premium'          => false,
				'premium_suffix'      => 'Pro',
				'has_premium_version' => true,
				'has_addons'          => false,
				'has_paid_plans'      => true,
				'is_org_compliant'    => true,
				'menu'                => array(
					'slug'    => 'sheetsync',
					'support' => false,
				),
			) );
		}

		return $sheetsync_fs;
	}

	sheetsync_fs();
	do_action( 'sheetsync_fs_loaded' );
}

// ── Pro version detection ─────────────────────────────────────────────────────
// Only define once — the Pro plugin may have already defined this constant.
if ( ! defined( 'SHEETSYNC_IS_PRO' ) ) {
	define( 'SHEETSYNC_IS_PRO', sheetsync_fs()->can_use_premium_code() );
}

// ── Requirements Check ────────────────────────────────────────────────────────
if ( ! function_exists( 'sheetsync_check_requirements' ) ) {
	function sheetsync_check_requirements(): array {
		$errors = array();
		if ( ! class_exists( 'WooCommerce' ) ) {
			$errors[] = __( 'WooCommerce is not active. SheetSync requires WooCommerce 7.0+.', 'sheetsync-for-woocommerce' );
		}
		if ( ! extension_loaded( 'openssl' ) ) {
			$errors[] = __( 'PHP OpenSSL extension is required for Google authentication.', 'sheetsync-for-woocommerce' );
		}
		if ( ! extension_loaded( 'json' ) ) {
			$errors[] = __( 'PHP JSON extension is required.', 'sheetsync-for-woocommerce' );
		}
		return $errors;
	}
}

// ── Boot ──────────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', function () {
		$errors = sheetsync_check_requirements();
		if ( ! empty( $errors ) ) {
			add_action( 'admin_notices', function () use ( $errors ) {
				foreach ( $errors as $error ) {
				echo '<div class="notice notice-error"><p><strong>SheetSync:</strong> ' . esc_html( $error ) . '</p></div>';
			}
		} );
		return;
	}
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-sheetsync-loader.php';

	if ( ! class_exists( 'SheetSync_Loader' ) ) {
		// Defensive: if the loader file failed to define the class (file missing or corrupted),
		// show an admin notice and avoid a fatal error.
		add_action( 'admin_notices', function() {
			echo '<div class="notice notice-error"><p><strong>SheetSync:</strong> ' . esc_html__( 'Core loader missing or failed to load. Please reinstall the plugin.', 'sheetsync-for-woocommerce' ) . '</p></div>';
		} );
		return;
	}

	( new SheetSync_Loader() )->run();

	/**
	 * Signal to add-ons that the core Free plugin has finished booting.
	 * Pro add-ons should attach to this action to initialise safely.
	 */
	do_action( 'sheetsync_loaded' );
} );

// ── WooCommerce HPOS Compatibility ───────────────────────────────────────────
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

// ── Activation / Deactivation ─────────────────────────────────────────────────
register_activation_hook( __FILE__, function () {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-sheetsync-activator.php';
	SheetSync_Activator::activate();
} );

register_deactivation_hook( __FILE__, function () {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-sheetsync-deactivator.php';
	SheetSync_Deactivator::deactivate();
} );
