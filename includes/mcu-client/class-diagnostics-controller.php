<?php
/**
 * Diagnostics read operation controller.
 *
 * @package MarrisonCustomUpdater
 */

namespace MarrisonCustomUpdater\MaintenanceClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Executes authenticated read-only diagnostic operations.
 */
final class Diagnostics_Controller {
	/**
	 * Read operation registry.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function read_operations() {
		return array(
			'diagnostics_snapshot_manifest' => array(
				'type'           => 'read',
				'cost_class'     => 'light',
				'required'       => array(),
				'allowed_params' => array( 'snapshot_id' ),
				'schema_version' => Diagnostics_Storage::SCHEMA_VERSION,
			),
			'diagnostics_snapshot_module' => array(
				'type'           => 'read',
				'cost_class'     => 'light',
				'required'       => array( 'snapshot_id', 'module' ),
				'allowed_params' => array( 'snapshot_id', 'module' ),
				'allowed_modules'=> Diagnostics_Storage::modules(),
				'schema_version' => Diagnostics_Storage::SCHEMA_VERSION,
			),
			'diagnostics_live_module' => array(
				'type'           => 'read',
				'cost_class'     => 'moderate',
				'required'       => array( 'module' ),
				'allowed_params' => array( 'module' ),
				'allowed_modules'=> Diagnostics_Storage::modules(),
				'schema_version' => Diagnostics_Storage::SCHEMA_VERSION,
			),
			'diagnostics_page_inspect' => array(
				'type'           => 'read',
				'cost_class'     => 'moderate',
				'required'       => array( 'page_id' ),
				'allowed_params' => array( 'page_id' ),
				'schema_version' => Diagnostics_Storage::SCHEMA_VERSION,
			),
			'diagnostics_menu_inspect' => array(
				'type'           => 'read',
				'cost_class'     => 'moderate',
				'required'       => array(),
				'allowed_params' => array( 'menu_id', 'location' ),
				'schema_version' => Diagnostics_Storage::SCHEMA_VERSION,
			),
		);
	}

	/**
	 * Return compact read operations for /status.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function supported_read_operations() {
		$list = array();
		foreach ( self::read_operations() as $name => $meta ) {
			$list[] = array(
				'name'       => $name,
				'cost_class' => (string) $meta['cost_class'],
			);
		}

		return $list;
	}

	/**
	 * Return write operation names for /status.
	 *
	 * @return array<int,string>
	 */
	public static function supported_write_operations() {
		return array( 'clear_cache', 'force_sync', 'cancel_master_update', 'update_all', 'update_plugin', 'update_theme', 'set_update_exclusion', 'diagnostics_schedule_snapshot', 'revoke_repository_config', 'debug_toggle', 'debug_log_delete', 'debug_log_clear' );
	}

	/**
	 * Execute a read-only diagnostic operation.
	 *
	 * @param string              $operation  Operation name.
	 * @param array<string,mixed> $parameters Raw parameters.
	 * @return array<string,mixed>
	 */
	public static function execute_read_operation( $operation, array $parameters = array() ) {
		self::load_runtime();
		$operation  = sanitize_key( (string) $operation );
		$operations = self::read_operations();
		if ( empty( $operations[ $operation ] ) ) {
			return self::error( 'unsupported_operation', 'Unsupported read operation.' );
		}

		$validation = self::validate( $operation, $parameters, $operations[ $operation ] );
		if ( empty( $validation['success'] ) ) {
			return $validation;
		}

		$params = isset( $validation['parameters'] ) && is_array( $validation['parameters'] ) ? $validation['parameters'] : array();

		if ( 'diagnostics_snapshot_manifest' === $operation ) {
			$snapshot_id = isset( $params['snapshot_id'] ) ? (string) $params['snapshot_id'] : 'latest';
			$manifest    = Diagnostics_Storage::get_manifest( $snapshot_id );
			if ( empty( $manifest ) ) {
				return self::error( 'snapshot_not_found', 'Snapshot not available.' );
			}
			return self::success( $manifest );
		}

		if ( 'diagnostics_snapshot_module' === $operation ) {
			$module = (string) $params['module'];
			$data   = Diagnostics_Storage::get_module( (string) $params['snapshot_id'], $module );
			if ( empty( $data ) ) {
				return self::error( 'module_not_found', 'Snapshot module not available.' );
			}
			return self::success( $data );
		}

		if ( 'diagnostics_live_module' === $operation ) {
			$module = (string) $params['module'];
			return self::success( Diagnostics_Collector::collect_module( $module ) );
		}

		if ( 'diagnostics_page_inspect' === $operation ) {
			return self::success( Diagnostics_Collector::inspect_page( (int) $params['page_id'] ) );
		}

		if ( 'diagnostics_menu_inspect' === $operation ) {
			return self::success( Diagnostics_Collector::inspect_menu( isset( $params['menu_id'] ) ? (int) $params['menu_id'] : 0, isset( $params['location'] ) ? (string) $params['location'] : '' ) );
		}

		return self::error( 'unsupported_operation', 'Unsupported read operation.' );
	}

	/**
	 * Validate and strip parameters.
	 *
	 * @param string              $operation  Operation.
	 * @param array<string,mixed> $parameters Parameters.
	 * @param array<string,mixed> $meta       Operation metadata.
	 * @return array<string,mixed>
	 */
	private static function validate( $operation, array $parameters, array $meta ) {
		$allowed = isset( $meta['allowed_params'] ) && is_array( $meta['allowed_params'] ) ? $meta['allowed_params'] : array();
		$clean   = array();

		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $parameters ) ) {
				$clean[ $key ] = $parameters[ $key ];
			}
		}

		$required = isset( $meta['required'] ) && is_array( $meta['required'] ) ? $meta['required'] : array();
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $clean ) || '' === trim( (string) $clean[ $key ] ) ) {
				return self::error( 'missing_parameter', 'A required parameter is missing.' );
			}
		}

		if ( isset( $clean['snapshot_id'] ) ) {
			$clean['snapshot_id'] = Diagnostics_Storage::safe_id( $clean['snapshot_id'] );
			if ( '' === $clean['snapshot_id'] ) {
				$clean['snapshot_id'] = 'latest';
			}
		}

		if ( isset( $clean['module'] ) ) {
			$clean['module'] = Diagnostics_Storage::safe_module( $clean['module'] );
			$allowed_modules = isset( $meta['allowed_modules'] ) && is_array( $meta['allowed_modules'] ) ? $meta['allowed_modules'] : array();
			if ( '' === $clean['module'] || ! in_array( $clean['module'], $allowed_modules, true ) ) {
				return self::error( 'invalid_module', 'Module is not allowed.' );
			}
		}

		if ( isset( $clean['page_id'] ) ) {
			$clean['page_id'] = absint( $clean['page_id'] );
			if ( $clean['page_id'] <= 0 ) {
				return self::error( 'invalid_page_id', 'Page ID is not valid.' );
			}
		}

		if ( isset( $clean['menu_id'] ) ) {
			$clean['menu_id'] = absint( $clean['menu_id'] );
		}
		if ( isset( $clean['location'] ) ) {
			$clean['location'] = sanitize_key( (string) $clean['location'] );
		}

		return array( 'success' => true, 'parameters' => $clean );
	}

	/**
	 * Return a success envelope.
	 *
	 * @param array<string,mixed> $data Data.
	 * @return array<string,mixed>
	 */
	private static function success( array $data ) {
		return array(
			'success' => true,
			'data'    => Diagnostics_Sanitizer::sanitize( $data ),
		);
	}

	/**
	 * Return an error envelope.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @return array<string,mixed>
	 */
	private static function error( $code, $message ) {
		return array(
			'success'    => false,
			'error_code' => sanitize_key( (string) $code ),
			'message'    => Diagnostics_Sanitizer::sanitize_text( $message ),
			'data'       => array(),
		);
	}

	/**
	 * Load runtime classes lazily.
	 *
	 * @return void
	 */
	private static function load_runtime() {
		require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-diagnostics-sanitizer.php';
		require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-diagnostics-storage.php';
		require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-diagnostics-capabilities.php';
		require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-diagnostics-collector.php';
	}
}
