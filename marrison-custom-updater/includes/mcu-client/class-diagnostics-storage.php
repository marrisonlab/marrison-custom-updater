<?php
/**
 * Diagnostics snapshot storage.
 *
 * @package MarrisonCustomUpdater
 */

namespace MarrisonCustomUpdater\MaintenanceClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores bounded diagnostic snapshots in non-autoloaded options.
 */
final class Diagnostics_Storage {
	const SCHEMA_VERSION       = 1;
	const INDEX_OPTION         = 'mcu_diagnostics_snapshot_index';
	const LATEST_OPTION        = 'mcu_diagnostics_latest_snapshot';
	const PIPELINE_OPTION      = 'mcu_diagnostics_pipeline';
	const MANIFEST_PREFIX      = 'mcu_diagnostics_manifest_';
	const MODULE_PREFIX        = 'mcu_diagnostics_module_';
	const PRE_FINGERPRINT_PREF = 'mcu_diagnostics_pre_';
	const LOCK_TRANSIENT       = 'mcu_diagnostics_lock';

	/**
	 * Ensure diagnostic options exist with autoload disabled.
	 *
	 * @return void
	 */
	public static function install() {
		if ( false === get_option( self::INDEX_OPTION, false ) ) {
			add_option( self::INDEX_OPTION, array(), '', 'no' );
		}
		if ( false === get_option( self::LATEST_OPTION, false ) ) {
			add_option( self::LATEST_OPTION, '', '', 'no' );
		}
		if ( false === get_option( self::PIPELINE_OPTION, false ) ) {
			add_option( self::PIPELINE_OPTION, self::empty_pipeline(), '', 'no' );
		}
	}

	/**
	 * Return snapshot module keys.
	 *
	 * @return array<int,string>
	 */
	public static function modules() {
		return array(
			'environment',
			'extensions',
			'capabilities',
			'cache',
			'database',
			'cron',
			'errors',
			'content',
			'menus',
			'builders',
			'woocommerce',
			'maintenance',
		);
	}

	/**
	 * Store a lightweight pre-maintenance fingerprint.
	 *
	 * @param string              $run_id      Maintenance run ID.
	 * @param array<string,mixed> $fingerprint Fingerprint data.
	 * @return void
	 */
	public static function save_pre_fingerprint( $run_id, array $fingerprint ) {
		$run_id = self::safe_id( $run_id );
		if ( '' === $run_id ) {
			return;
		}

		update_option( self::PRE_FINGERPRINT_PREF . $run_id, self::sanitize( $fingerprint ), false );
	}

	/**
	 * Read a stored pre-maintenance fingerprint.
	 *
	 * @param string $run_id Maintenance run ID.
	 * @return array<string,mixed>
	 */
	public static function get_pre_fingerprint( $run_id ) {
		$run_id = self::safe_id( $run_id );
		if ( '' === $run_id ) {
			return array();
		}

		$value = get_option( self::PRE_FINGERPRINT_PREF . $run_id, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Create and persist a new manifest.
	 *
	 * @param string              $trigger         Trigger label.
	 * @param string              $source          Maintenance source.
	 * @param string              $run_id          Maintenance run ID.
	 * @param array<string,mixed> $pre_fingerprint Lightweight pre fingerprint.
	 * @return array<string,mixed>
	 */
	public static function create_manifest( $trigger, $source, $run_id, array $pre_fingerprint = array() ) {
		self::install();

		$snapshot_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : md5( uniqid( 'mcu-diagnostic', true ) );
		$modules     = self::modules();
		$status_map  = array();
		foreach ( $modules as $module ) {
			$status_map[ $module ] = array(
				'status'      => 'pending',
				'generated_at' => 0,
				'duration_ms' => 0,
				'partial'     => false,
				'warnings'    => array(),
			);
		}

		$manifest = array(
			'snapshot_id'           => $snapshot_id,
			'schema_version'        => self::SCHEMA_VERSION,
			'generated_at'          => time(),
			'completed_at'          => 0,
			'trigger'               => sanitize_key( (string) $trigger ),
			'source'                => sanitize_key( (string) $source ),
			'maintenance_run_id'    => sanitize_text_field( (string) $run_id ),
			'status'                => 'pending',
			'modules'               => $modules,
			'module_status'         => $status_map,
			'partial'               => false,
			'warnings'              => array(),
			'pre_fingerprint'       => self::sanitize( $pre_fingerprint ),
			'checksum'              => '',
			'client_plugin_version' => defined( 'MCU_PLUGIN_VERSION' ) ? MCU_PLUGIN_VERSION : '',
		);

		self::save_manifest( $manifest );
		update_option( self::LATEST_OPTION, $snapshot_id, false );

		$index = self::index();
		array_unshift( $index, $snapshot_id );
		$index = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'safe_id' ), $index ) ) ) );
		update_option( self::INDEX_OPTION, array_slice( $index, 0, 10 ), false );

		return $manifest;
	}

	/**
	 * Save a manifest.
	 *
	 * @param array<string,mixed> $manifest Manifest data.
	 * @return void
	 */
	public static function save_manifest( array $manifest ) {
		$snapshot_id = self::safe_id( isset( $manifest['snapshot_id'] ) ? $manifest['snapshot_id'] : '' );
		if ( '' === $snapshot_id ) {
			return;
		}

		$manifest['snapshot_id'] = $snapshot_id;
		$manifest['checksum']    = self::manifest_checksum( $manifest );
		update_option( self::MANIFEST_PREFIX . $snapshot_id, self::sanitize( $manifest ), false );
	}

	/**
	 * Return latest snapshot summary for status.
	 *
	 * @return array<string,mixed>
	 */
	public static function latest_snapshot_summary() {
		$manifest = self::get_manifest( 'latest' );
		if ( empty( $manifest ) ) {
			return array(
				'available'          => false,
				'snapshot_id'        => '',
				'status'             => 'none',
				'generated_at'       => 0,
				'completed_at'       => 0,
				'trigger'            => '',
				'maintenance_run_id' => '',
				'modules'            => array(),
				'partial'            => false,
				'checksum'           => '',
			);
		}

		return array(
			'available'          => true,
			'snapshot_id'        => (string) ( isset( $manifest['snapshot_id'] ) ? $manifest['snapshot_id'] : '' ),
			'status'             => sanitize_key( (string) ( isset( $manifest['status'] ) ? $manifest['status'] : '' ) ),
			'generated_at'       => (int) ( isset( $manifest['generated_at'] ) ? $manifest['generated_at'] : 0 ),
			'completed_at'       => (int) ( isset( $manifest['completed_at'] ) ? $manifest['completed_at'] : 0 ),
			'trigger'            => sanitize_key( (string) ( isset( $manifest['trigger'] ) ? $manifest['trigger'] : '' ) ),
			'maintenance_run_id' => sanitize_text_field( (string) ( isset( $manifest['maintenance_run_id'] ) ? $manifest['maintenance_run_id'] : '' ) ),
			'modules'            => isset( $manifest['modules'] ) && is_array( $manifest['modules'] ) ? array_values( array_map( 'sanitize_key', $manifest['modules'] ) ) : array(),
			'partial'            => ! empty( $manifest['partial'] ),
			'checksum'           => sanitize_text_field( (string) ( isset( $manifest['checksum'] ) ? $manifest['checksum'] : '' ) ),
		);
	}

	/**
	 * Return a full manifest.
	 *
	 * @param string $snapshot_id Snapshot ID or latest.
	 * @return array<string,mixed>
	 */
	public static function get_manifest( $snapshot_id = 'latest' ) {
		$snapshot_id = self::resolve_snapshot_id( $snapshot_id );
		if ( '' === $snapshot_id ) {
			return array();
		}

		$manifest = get_option( self::MANIFEST_PREFIX . $snapshot_id, array() );
		return is_array( $manifest ) ? $manifest : array();
	}

	/**
	 * Save one module and update the manifest.
	 *
	 * @param string              $snapshot_id Snapshot ID.
	 * @param string              $module      Module key.
	 * @param array<string,mixed> $result      Module result.
	 * @return array<string,mixed>
	 */
	public static function save_module( $snapshot_id, $module, array $result ) {
		$snapshot_id = self::safe_id( $snapshot_id );
		$module      = self::safe_module( $module );
		if ( '' === $snapshot_id || '' === $module ) {
			return array();
		}

		$result = self::sanitize( $result );
		update_option( self::MODULE_PREFIX . $snapshot_id . '_' . $module, $result, false );

		$manifest = self::get_manifest( $snapshot_id );
		if ( empty( $manifest ) ) {
			return $result;
		}

		if ( empty( $manifest['module_status'] ) || ! is_array( $manifest['module_status'] ) ) {
			$manifest['module_status'] = array();
		}

		$manifest['module_status'][ $module ] = array(
			'status'      => empty( $result['success'] ) ? 'failed' : 'completed',
			'generated_at' => (int) ( isset( $result['generated_at'] ) ? $result['generated_at'] : time() ),
			'duration_ms' => (int) ( isset( $result['duration_ms'] ) ? $result['duration_ms'] : 0 ),
			'partial'     => ! empty( $result['partial'] ),
			'warnings'    => isset( $result['warnings'] ) && is_array( $result['warnings'] ) ? $result['warnings'] : array(),
			'checksum'    => sanitize_text_field( (string) ( isset( $result['checksum'] ) ? $result['checksum'] : '' ) ),
			'size_bytes'  => (int) ( isset( $result['size_bytes'] ) ? $result['size_bytes'] : 0 ),
		);

		self::refresh_manifest_status( $manifest );
		self::save_manifest( $manifest );

		return $result;
	}

	/**
	 * Read one stored module.
	 *
	 * @param string $snapshot_id Snapshot ID or latest.
	 * @param string $module      Module key.
	 * @return array<string,mixed>
	 */
	public static function get_module( $snapshot_id, $module ) {
		$snapshot_id = self::resolve_snapshot_id( $snapshot_id );
		$module      = self::safe_module( $module );
		if ( '' === $snapshot_id || '' === $module ) {
			return array();
		}

		$value = get_option( self::MODULE_PREFIX . $snapshot_id . '_' . $module, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Return current pipeline state.
	 *
	 * @return array<string,mixed>
	 */
	public static function pipeline_status() {
		self::install();
		$status = get_option( self::PIPELINE_OPTION, self::empty_pipeline() );
		return is_array( $status ) ? array_merge( self::empty_pipeline(), $status ) : self::empty_pipeline();
	}

	/**
	 * Return compact pipeline status for /status.
	 *
	 * @return array<string,mixed>
	 */
	public static function pipeline_summary() {
		$status = self::pipeline_status();
		return array(
			'status'         => sanitize_key( (string) ( isset( $status['status'] ) ? $status['status'] : 'idle' ) ),
			'pending_since'  => (int) ( isset( $status['pending_since'] ) ? $status['pending_since'] : 0 ),
			'next_run'       => (int) ( isset( $status['next_run'] ) ? $status['next_run'] : 0 ),
			'current_module' => sanitize_key( (string) ( isset( $status['current_module'] ) ? $status['current_module'] : '' ) ),
		);
	}

	/**
	 * Save pipeline state.
	 *
	 * @param array<string,mixed> $status Pipeline state.
	 * @return void
	 */
	public static function save_pipeline( array $status ) {
		update_option( self::PIPELINE_OPTION, self::sanitize( array_merge( self::empty_pipeline(), $status ) ), false );
	}

	/**
	 * Reset pipeline state.
	 *
	 * @return void
	 */
	public static function clear_pipeline() {
		self::save_pipeline( self::empty_pipeline() );
	}

	/**
	 * Remove older snapshots, keeping recent manifests and modules only.
	 *
	 * @param int $keep Number of snapshots to retain.
	 * @return void
	 */
	public static function cleanup_old_snapshots( $keep = 5 ) {
		$index = self::index();
		$keep  = max( 1, (int) $keep );
		$old   = array_slice( $index, $keep );
		foreach ( $old as $snapshot_id ) {
			delete_option( self::MANIFEST_PREFIX . $snapshot_id );
			foreach ( self::modules() as $module ) {
				delete_option( self::MODULE_PREFIX . $snapshot_id . '_' . $module );
			}
		}

		update_option( self::INDEX_OPTION, array_slice( $index, 0, $keep ), false );
	}

	/**
	 * Try to acquire the diagnostic lock.
	 *
	 * @param string $owner Lock owner.
	 * @return string Lock token or empty string.
	 */
	public static function acquire_lock( $owner ) {
		$existing = get_transient( self::LOCK_TRANSIENT );
		if ( is_array( $existing ) && ! empty( $existing['expires'] ) && (int) $existing['expires'] > time() ) {
			return '';
		}

		$token = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : md5( uniqid( 'mcu-diagnostics-lock', true ) );
		set_transient(
			self::LOCK_TRANSIENT,
			array(
				'token'      => $token,
				'owner'      => sanitize_key( (string) $owner ),
				'started_at' => time(),
				'expires'    => time() + 10 * MINUTE_IN_SECONDS,
			),
			10 * MINUTE_IN_SECONDS
		);

		return $token;
	}

	/**
	 * Release the diagnostic lock.
	 *
	 * @param string $token Lock token.
	 * @return void
	 */
	public static function release_lock( $token ) {
		$existing = get_transient( self::LOCK_TRANSIENT );
		if ( ! is_array( $existing ) || empty( $existing['token'] ) || hash_equals( (string) $existing['token'], (string) $token ) ) {
			delete_transient( self::LOCK_TRANSIENT );
		}
	}

	/**
	 * Normalize snapshot ID.
	 *
	 * @param string $snapshot_id Snapshot ID.
	 * @return string
	 */
	public static function safe_id( $snapshot_id ) {
		$snapshot_id = preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $snapshot_id );
		return is_string( $snapshot_id ) ? substr( $snapshot_id, 0, 80 ) : '';
	}

	/**
	 * Normalize a module key.
	 *
	 * @param string $module Module key.
	 * @return string
	 */
	public static function safe_module( $module ) {
		$module  = sanitize_key( (string) $module );
		$allowed = self::modules();
		$allowed[] = 'page';
		$allowed[] = 'menu';
		return in_array( $module, $allowed, true ) ? $module : '';
	}

	/**
	 * Return empty pipeline data.
	 *
	 * @return array<string,mixed>
	 */
	private static function empty_pipeline() {
		return array(
			'status'         => 'idle',
			'snapshot_id'    => '',
			'pending_since'  => 0,
			'next_run'       => 0,
			'current_module' => '',
			'last_error'     => '',
			'updated_at'     => 0,
		);
	}

	/**
	 * Return the snapshot index.
	 *
	 * @return array<int,string>
	 */
	private static function index() {
		$index = get_option( self::INDEX_OPTION, array() );
		return is_array( $index ) ? array_values( array_filter( array_map( array( __CLASS__, 'safe_id' ), $index ) ) ) : array();
	}

	/**
	 * Resolve latest to the current snapshot ID.
	 *
	 * @param string $snapshot_id Snapshot ID.
	 * @return string
	 */
	private static function resolve_snapshot_id( $snapshot_id ) {
		$snapshot_id = self::safe_id( $snapshot_id );
		if ( 'latest' === $snapshot_id ) {
			$snapshot_id = self::safe_id( get_option( self::LATEST_OPTION, '' ) );
		}

		return $snapshot_id;
	}

	/**
	 * Refresh aggregate manifest state.
	 *
	 * @param array<string,mixed> $manifest Manifest data.
	 * @return void
	 */
	private static function refresh_manifest_status( array &$manifest ) {
		$modules       = isset( $manifest['modules'] ) && is_array( $manifest['modules'] ) ? $manifest['modules'] : self::modules();
		$module_status = isset( $manifest['module_status'] ) && is_array( $manifest['module_status'] ) ? $manifest['module_status'] : array();
		$complete      = true;
		$partial       = false;
		$failed        = 0;

		foreach ( $modules as $module ) {
			$key    = sanitize_key( (string) $module );
			$status = isset( $module_status[ $key ]['status'] ) ? sanitize_key( (string) $module_status[ $key ]['status'] ) : 'pending';
			if ( 'pending' === $status || 'running' === $status ) {
				$complete = false;
			}
			if ( 'failed' === $status ) {
				$failed++;
				$partial = true;
			}
			if ( ! empty( $module_status[ $key ]['partial'] ) ) {
				$partial = true;
			}
		}

		if ( $complete ) {
			$manifest['status']       = $failed === count( $modules ) ? 'failed' : 'completed';
			$manifest['completed_at'] = time();
		} else {
			$manifest['status'] = $failed > 0 ? 'partial' : 'running';
		}

		$manifest['partial'] = $partial;
	}

	/**
	 * Compute manifest checksum without including its previous checksum.
	 *
	 * @param array<string,mixed> $manifest Manifest data.
	 * @return string
	 */
	private static function manifest_checksum( array $manifest ) {
		$copy = $manifest;
		unset( $copy['checksum'] );
		$json = wp_json_encode( $copy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return hash( 'sha256', is_string( $json ) ? $json : '' );
	}

	/**
	 * Sanitize data recursively.
	 *
	 * @param mixed $value Raw value.
	 * @return mixed
	 */
	private static function sanitize( $value ) {
		if ( class_exists( __NAMESPACE__ . '\\Diagnostics_Sanitizer' ) ) {
			return Diagnostics_Sanitizer::sanitize( $value );
		}

		return $value;
	}
}
