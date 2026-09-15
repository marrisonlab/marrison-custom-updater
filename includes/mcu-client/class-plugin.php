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
		require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-debug-logger.php';
		require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-authenticator.php';
		require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-actions-controller.php';
		require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-dashboard-access-controller.php';
		require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-diagnostics-scheduler.php';
		require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-rest-controller.php';
		Actions_Controller::init();
		Dashboard_Access_Controller::init();
		Diagnostics_Scheduler::init();

		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		// Enforce the inactive state on frontend, admin and WP-Cron requests.
		add_action( 'init', array( __CLASS__, 'enforce_repository_access' ), 1 );
		add_action( 'admin_init', array( __CLASS__, 'enforce_repository_access' ), 1 );

		if ( is_admin() ) {
			require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-admin.php';
			Admin::init();
		}
	}

	/**
	 * Enforce the inactive state on sites that are not authorized by Commander.
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

		self::stop_scheduled_activity();
	}

	/**
	 * Stop all MCU background activity while the client is unauthorized.
	 *
	 * This is intentionally independent from the REST routes: Commander must
	 * still be able to send a new managed repository configuration and
	 * re-authorize the client.
	 *
	 * @return void
	 */
	public static function stop_scheduled_activity() {
		foreach (
			array(
				'marrison_scheduled_update_event',
				'mcu_master_update_event',
				'mcu_diagnostics_start_snapshot',
				'mcu_diagnostics_collect_module',
			) as $hook
		) {
			self::clear_scheduled_hook( $hook );
		}

		$master_status = get_option( 'mcu_master_update_status', array() );
		if ( is_array( $master_status ) && 'queued' === sanitize_key( (string) ( isset( $master_status['status'] ) ? $master_status['status'] : '' ) ) ) {
			$now                    = time();
			$master_status['status'] = 'cancelled';
			$master_status['stage']  = 'cancelled';
			$master_status['finished_at'] = $now;
			$master_status['cancelled_at'] = $now;
			$master_status['message'] = __( 'Job annullato: il client MCU non è autorizzato da Commander.', 'marrison-custom-updater' );
			update_option( 'mcu_master_update_status', $master_status, false );
		}

		// A queued diagnostics pipeline has no remaining work after its cron
		// events are removed. Leave a running pipeline untouched so an already
		// executing request can release its lock and finish safely.
		require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-diagnostics-storage.php';
		$pipeline = Diagnostics_Storage::pipeline_status();
		if ( is_array( $pipeline ) && 'queued' === sanitize_key( (string) ( isset( $pipeline['status'] ) ? $pipeline['status'] : '' ) ) ) {
			Diagnostics_Storage::clear_pipeline();
		}
	}

	/**
	 * Remove every scheduled event registered under one MCU hook.
	 *
	 * @param string $hook Hook name.
	 * @return void
	 */
	private static function clear_scheduled_hook( $hook ) {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( $hook );
		}

		if ( ! function_exists( '_get_cron_array' ) || ! function_exists( 'wp_unschedule_event' ) ) {
			return;
		}

		$crons = _get_cron_array();
		if ( ! is_array( $crons ) ) {
			return;
		}

		foreach ( $crons as $timestamp => $hooks ) {
			if ( empty( $hooks[ $hook ] ) || ! is_array( $hooks[ $hook ] ) ) {
				continue;
			}

			foreach ( $hooks[ $hook ] as $event ) {
				if ( ! is_array( $event ) ) {
					continue;
				}

				$args = isset( $event['args'] ) && is_array( $event['args'] ) ? $event['args'] : array();
				wp_unschedule_event( (int) $timestamp, $hook, $args );
			}
		}
	}

	/**
	 * Load REST-only code and register routes.
	 *
	 * @return void
	 */
	public static function register_rest_routes() {
		require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-debug-manager.php';
		require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-debug-log-controller.php';
		require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-rate-limiter.php';

		Rest_Controller::register_routes();
		Actions_Controller::register_routes();
		Debug_Log_Controller::register_routes();
		Dashboard_Access_Controller::register_routes();
	}
}
