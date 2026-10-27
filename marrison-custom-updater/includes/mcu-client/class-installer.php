<?php
/**
 * Maintenance client lifecycle handlers.
 *
 * @package MarrisonCustomUpdater
 */

namespace MarrisonCustomUpdater\MaintenanceClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles lifecycle events for the bundled client.
 */
final class Installer {
	/**
	 * Activate the client module.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			deactivate_plugins( plugin_basename( MCU_PLUGIN_FILE ) );
			wp_die(
				esc_html__( 'WP Master Updater maintenance client requires PHP 7.4 or newer.', 'marrison-custom-updater' ),
				esc_html__( 'Plugin activation failed', 'marrison-custom-updater' ),
				array( 'back_link' => true )
			);
		}

		Settings::ensure_defaults();

		require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-diagnostics-sanitizer.php';
		require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-diagnostics-storage.php';
		Diagnostics_Storage::install();
	}

	/**
	 * Deactivate the client module.
	 *
	 * General plugin data is kept, but Commander authorization and its runtime
	 * repository cache are revoked so reactivation cannot restart MCU silently.
	 *
	 * @return void
	 */
	public static function deactivate() {
		// Deactivation must not leave authorization or MCU cron events behind for
		// a future reactivation. Commander must explicitly authorize the client
		// again before any update functionality can resume.
		Settings::revoke_repository_config();
	}
}
