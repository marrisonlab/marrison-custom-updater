<?php
/**
 * Maintenance client bootstrap.
 *
 * @package MarrisonCustomUpdater
 */

namespace MarrisonCustomUpdater\MaintenanceClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires WordPress hooks for the bundled client.
 */
final class Plugin {
	/**
	 * Register plugin hooks.
	 *
	 * @return void
	 */
	public static function init() {
		require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-actions-controller.php';
		require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-dashboard-access-controller.php';
		require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-diagnostics-scheduler.php';
		require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-rest-controller.php';
		Actions_Controller::init();
		Dashboard_Access_Controller::init();
		Diagnostics_Scheduler::init();

		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		add_action( 'admin_init', array( __CLASS__, 'enforce_repository_access' ), 1 );

		if ( is_admin() ) {
			require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-admin.php';
			Admin::init();
		}
	}

	/**
	 * Remove legacy repository data from sites that have not been authorized
	 * by Commander yet.
	 *
	 * @return void
	 */
	public static function enforce_repository_access() {
		static $enforced = false;
		if ( $enforced ) {
			return;
		}
		$enforced = true;

		if ( Settings::repository_config_managed() ) {
			return;
		}

		$has_legacy_repository = get_option( Settings::PLUGIN_REPOSITORY_OPTION, '' ) || get_option( Settings::THEME_REPOSITORY_OPTION, '' );
		$has_cached_updates     = false !== get_transient( 'marrison_available_updates_v2' ) || false !== get_transient( 'marrison_available_theme_updates' );
		if ( $has_legacy_repository || $has_cached_updates ) {
			Settings::revoke_repository_config();
		}
	}

	/**
	 * Load REST-only code and register routes.
	 *
	 * @return void
	 */
	public static function register_rest_routes() {
		require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-debug-logger.php';
		require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-rate-limiter.php';
		require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-authenticator.php';

		Rest_Controller::register_routes();
		Actions_Controller::register_routes();
		Dashboard_Access_Controller::register_routes();
	}
}
