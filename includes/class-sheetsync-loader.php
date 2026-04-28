<?php
/**
 * Plugin loader — bootstraps all core classes.
 *
 * The Free plugin is a fully self-contained, WordPress.org-compliant plugin.
 * It contains NO references to Pro file paths, Pro class names, or any
 * premium-only logic. Pro features are loaded exclusively by the Pro plugin
 * via its own class-sheetsync-loader.php which extends this base loader.
 *
 * @package SheetSync_For_WooCommerce
 * @license GPL-2.0-or-later
 * @copyright 2024 MD Najmus Shadat
 */

defined( 'ABSPATH' ) || exit;

// Resolve plugin base directory using this file's location so loader
// does not depend on the SHEETSYNC_DIR constant which may be defined
// by other plugins (e.g. the Pro add-on) before this file is loaded.
 $sheetsync_base_dir = dirname( dirname( __FILE__ ) ) . DIRECTORY_SEPARATOR;
 $GLOBALS['sheetsync_base_dir'] = $sheetsync_base_dir;

if ( ! class_exists( 'SheetSync_Loader' ) ) :

class SheetSync_Loader {

	public function run(): void {
		$this->load_dependencies();
		$this->load_core();
		if ( is_admin() ) {
			$this->load_admin();
		}
		$this->load_api();
	}

	private function load_dependencies(): void {
		require_once $GLOBALS['sheetsync_base_dir'] . 'includes/class-sheetsync-encryptor.php';
		require_once $GLOBALS['sheetsync_base_dir'] . 'includes/class-sheetsync-logger.php';
		require_once $GLOBALS['sheetsync_base_dir'] . 'includes/core/class-google-auth.php';
		require_once $GLOBALS['sheetsync_base_dir'] . 'includes/core/class-sheets-client.php';
		require_once $GLOBALS['sheetsync_base_dir'] . 'includes/core/class-field-mapper.php';
		require_once $GLOBALS['sheetsync_base_dir'] . 'includes/core/class-product-updater.php';
		require_once $GLOBALS['sheetsync_base_dir'] . 'includes/core/class-sync-engine.php';
	}

	private function load_core(): void {
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );
	}


	private function load_admin(): void {
		require_once $GLOBALS['sheetsync_base_dir'] . 'admin/class-sheetsync-admin.php';
		new SheetSync_Admin();
	}

	private function load_api(): void {
		require_once $GLOBALS['sheetsync_base_dir'] . 'api/class-rest-api.php';
		$api = new SheetSync_REST_API();
		add_action( 'rest_api_init', array( $api, 'register_routes' ) );
	}

	public function add_cron_schedules( array $schedules ): array {
		$schedules['sheetsync_15min'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 Minutes (SheetSync)', 'sheetsync-for-woocommerce' ),
		);
		$schedules['sheetsync_30min'] = array(
			'interval' => 30 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 30 Minutes (SheetSync)', 'sheetsync-for-woocommerce' ),
		);
		$schedules['sheetsync_1hour'] = array(
			'interval' => HOUR_IN_SECONDS,
			'display'  => __( 'Every Hour (SheetSync)', 'sheetsync-for-woocommerce' ),
		);
		return $schedules;
	}
}

endif; // class_exists SheetSync_Loader

/**
 * Is a Pro license active?
 *
 * Reads the SHEETSYNC_IS_PRO constant defined in the main plugin file
 * immediately after Freemius SDK initialisation.
 *
 * Wrapped in function_exists() so the Pro plugin can override without
 * a fatal "cannot redeclare" error.
 */
if ( ! function_exists( 'sheetsync_is_pro' ) ) {
	function sheetsync_is_pro(): bool {
		return defined( 'SHEETSYNC_IS_PRO' ) && true === SHEETSYNC_IS_PRO;
	}
}

/**
 * Returns the Freemius checkout / upgrade URL.
 *
 * Uses sheetsync_fs() — the single standardised Freemius function name
 * shared by both Free and Pro to avoid duplicate Freemius instances and
 * broken upgrade flows.
 *
 * Wrapped in function_exists() so the Pro plugin can override if needed.
 */
if ( ! function_exists( 'sheetsync_upgrade_url' ) ) {
	function sheetsync_upgrade_url(): string {
		if ( function_exists( 'sheetsync_fs' )
			 && is_object( sheetsync_fs() )
			 && method_exists( sheetsync_fs(), 'get_upgrade_url' ) ) {
			return sheetsync_fs()->get_upgrade_url();
		}
		return 'https://checkout.freemius.com/mode/dialog/plugin/26705/plan/44217/';
	}
}
