<?php
/**
 * Diagnostics snapshot scheduler.
 *
 * @package MarrisonCustomUpdater
 */

namespace MarrisonCustomUpdater\MaintenanceClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs diagnostic snapshots through single WP-Cron events.
 */
final class Diagnostics_Scheduler {
	const START_HOOK   = 'mcu_diagnostics_start_snapshot';
	const COLLECT_HOOK = 'mcu_diagnostics_collect_module';

	/**
	 * Register single-event hooks only.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::START_HOOK, array( __CLASS__, 'start_snapshot' ), 10, 1 );
		add_action( self::COLLECT_HOOK, array( __CLASS__, 'collect_module' ), 10, 2 );
	}

	/**
	 * Capture a very light fingerprint during the update process.
	 *
	 * @param array<string,mixed> $context Context.
	 * @return array<string,mixed>
	 */
	public static function capture_pre_maintenance_fingerprint( array $context = array() ) {
		self::load_runtime();
		$run_id = isset( $context['maintenance_run_id'] ) ? (string) $context['maintenance_run_id'] : '';
		if ( '' === $run_id ) {
			$run_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : md5( uniqid( 'mcu-maintenance', true ) );
			$context['maintenance_run_id'] = $run_id;
		}

		$fingerprint = Diagnostics_Collector::fingerprint( $context );
		Diagnostics_Storage::save_pre_fingerprint( $run_id, $fingerprint );

		return $fingerprint;
	}

	/**
	 * Schedule a post-maintenance diagnostic snapshot when useful.
	 *
	 * @param array<string,mixed> $context Maintenance context.
	 * @return array<string,mixed>
	 */
	public static function maybe_schedule_after_maintenance( array $context = array() ) {
		if ( ! Settings::repository_config_managed() ) {
			return array( 'scheduled' => false, 'reason' => 'client_not_authorized' );
		}

		if ( empty( $context['maintenance_executed'] ) ) {
			return array( 'scheduled' => false, 'reason' => 'maintenance_not_executed' );
		}

		self::load_storage_only();
		$pipeline = Diagnostics_Storage::pipeline_status();
		if ( in_array( sanitize_key( (string) ( isset( $pipeline['status'] ) ? $pipeline['status'] : '' ) ), array( 'queued', 'running' ), true ) ) {
			return array( 'scheduled' => false, 'reason' => 'pipeline_already_active' );
		}

		$run_id      = sanitize_text_field( (string) ( isset( $context['maintenance_run_id'] ) ? $context['maintenance_run_id'] : '' ) );
		$latest      = Diagnostics_Storage::latest_snapshot_summary();
		$latest_time = ! empty( $latest['completed_at'] ) ? (int) $latest['completed_at'] : (int) ( isset( $latest['generated_at'] ) ? $latest['generated_at'] : 0 );
		$changed     = ! empty( $context['updated_plugins'] ) || ! empty( $context['updated_themes'] ) || ! empty( $context['updated_translations'] );
		$failed      = ! empty( $context['failed_updates'] );
		$stale       = $latest_time <= 0 || ( time() - $latest_time ) > 30 * DAY_IN_SECONDS;

		if ( ! $changed && ! $failed && ! $stale ) {
			return array( 'scheduled' => false, 'reason' => 'recent_snapshot_and_no_changes' );
		}

		$pre_fingerprint = isset( $context['pre_fingerprint'] ) && is_array( $context['pre_fingerprint'] )
			? $context['pre_fingerprint']
			: Diagnostics_Storage::get_pre_fingerprint( $run_id );
		$manifest = Diagnostics_Storage::create_manifest( 'post_scheduled_maintenance', isset( $context['source'] ) ? $context['source'] : '', $run_id, $pre_fingerprint );
		$delay    = self::distributed_delay( $run_id );
		$run_at   = time() + $delay;

		if ( ! wp_next_scheduled( self::START_HOOK, array( $manifest['snapshot_id'] ) ) ) {
			wp_schedule_single_event( $run_at, self::START_HOOK, array( $manifest['snapshot_id'] ), true );
		}

		Diagnostics_Storage::save_pipeline(
			array(
				'status'         => 'queued',
				'snapshot_id'    => $manifest['snapshot_id'],
				'pending_since'  => time(),
				'next_run'       => $run_at,
				'current_module' => '',
				'updated_at'     => time(),
			)
		);

		return array(
			'scheduled'   => true,
			'snapshot_id' => $manifest['snapshot_id'],
			'next_run'    => $run_at,
			'delay'       => $delay,
		);
	}

	/**
	 * Start a snapshot by scheduling the first module.
	 *
	 * @param string $snapshot_id Snapshot ID.
	 * @return void
	 */
	public static function start_snapshot( $snapshot_id ) {
		if ( ! Settings::repository_config_managed() ) {
			return;
		}

		self::load_storage_only();
		$snapshot_id = Diagnostics_Storage::safe_id( $snapshot_id );
		$manifest    = Diagnostics_Storage::get_manifest( $snapshot_id );
		if ( empty( $manifest ) || self::update_lock_active() ) {
			self::reschedule_start( $snapshot_id, 5 * MINUTE_IN_SECONDS );
			return;
		}

		$module = self::next_pending_module( $manifest );
		if ( '' === $module ) {
			Diagnostics_Storage::clear_pipeline();
			return;
		}

		Diagnostics_Storage::save_pipeline(
			array(
				'status'         => 'running',
				'snapshot_id'    => $snapshot_id,
				'pending_since'  => isset( $manifest['generated_at'] ) ? (int) $manifest['generated_at'] : time(),
				'next_run'       => time() + 5,
				'current_module' => $module,
				'updated_at'     => time(),
			)
		);

		if ( ! wp_next_scheduled( self::COLLECT_HOOK, array( $snapshot_id, $module ) ) ) {
			wp_schedule_single_event( time() + 5, self::COLLECT_HOOK, array( $snapshot_id, $module ), true );
		}
	}

	/**
	 * Collect one module, then schedule the next one.
	 *
	 * @param string $snapshot_id Snapshot ID.
	 * @param string $module      Module key.
	 * @return void
	 */
	public static function collect_module( $snapshot_id, $module = '' ) {
		if ( ! Settings::repository_config_managed() ) {
			return;
		}

		self::load_runtime();
		$snapshot_id = Diagnostics_Storage::safe_id( $snapshot_id );
		$module      = Diagnostics_Storage::safe_module( $module );

		if ( '' === $snapshot_id ) {
			return;
		}

		if ( self::update_lock_active() ) {
			self::reschedule_collect( $snapshot_id, $module, 5 * MINUTE_IN_SECONDS );
			return;
		}

		$token = Diagnostics_Storage::acquire_lock( 'collect' );
		if ( '' === $token ) {
			self::reschedule_collect( $snapshot_id, $module, 3 * MINUTE_IN_SECONDS );
			return;
		}

		try {
			$manifest = Diagnostics_Storage::get_manifest( $snapshot_id );
			if ( empty( $manifest ) ) {
				return;
			}
			if ( '' === $module ) {
				$module = self::next_pending_module( $manifest );
			}
			if ( '' === $module ) {
				Diagnostics_Storage::clear_pipeline();
				return;
			}

			Diagnostics_Storage::save_pipeline(
				array(
					'status'         => 'running',
					'snapshot_id'    => $snapshot_id,
					'pending_since'  => isset( $manifest['generated_at'] ) ? (int) $manifest['generated_at'] : time(),
					'next_run'       => 0,
					'current_module' => $module,
					'updated_at'     => time(),
				)
			);

			$result = Diagnostics_Collector::collect_module( $module );
			Diagnostics_Storage::save_module( $snapshot_id, $module, $result );

			if ( ! Settings::repository_config_managed() ) {
				Diagnostics_Storage::clear_pipeline();
				return;
			}

			$manifest = Diagnostics_Storage::get_manifest( $snapshot_id );
			$next     = self::next_pending_module( $manifest );
			if ( '' === $next ) {
				Diagnostics_Storage::clear_pipeline();
				Diagnostics_Storage::cleanup_old_snapshots( 5 );
				return;
			}

			$delay = self::module_delay( $snapshot_id, $next );
			$run_at = time() + $delay;
			Diagnostics_Storage::save_pipeline(
				array(
					'status'         => 'running',
					'snapshot_id'    => $snapshot_id,
					'pending_since'  => isset( $manifest['generated_at'] ) ? (int) $manifest['generated_at'] : time(),
					'next_run'       => $run_at,
					'current_module' => $next,
					'updated_at'     => time(),
				)
			);
			wp_schedule_single_event( $run_at, self::COLLECT_HOOK, array( $snapshot_id, $next ), true );
		} catch ( \Throwable $exception ) {
			Diagnostics_Storage::save_pipeline(
				array(
					'status'         => 'failed',
					'snapshot_id'    => $snapshot_id,
					'last_error'     => Diagnostics_Sanitizer::sanitize_text( $exception->getMessage() ),
					'updated_at'     => time(),
				)
			);
		} finally {
			Diagnostics_Storage::release_lock( $token );
		}
	}

	/**
	 * Return deterministic 10-45 minute delay.
	 *
	 * @param string $run_id Maintenance run ID.
	 * @return int
	 */
	private static function distributed_delay( $run_id ) {
		$minute = defined( 'MINUTE_IN_SECONDS' ) ? MINUTE_IN_SECONDS : 60;
		$hash   = sprintf( '%u', crc32( home_url() . '|' . (string) $run_id ) );
		return 10 * $minute + ( (int) $hash % ( 35 * $minute ) );
	}

	/**
	 * Return deterministic 60-180 second inter-module delay.
	 *
	 * @param string $snapshot_id Snapshot ID.
	 * @param string $module      Module key.
	 * @return int
	 */
	private static function module_delay( $snapshot_id, $module ) {
		$hash = sprintf( '%u', crc32( (string) $snapshot_id . '|' . (string) $module ) );
		return 60 + ( (int) $hash % 121 );
	}

	/**
	 * Return next pending module.
	 *
	 * @param array<string,mixed> $manifest Manifest.
	 * @return string
	 */
	private static function next_pending_module( array $manifest ) {
		$modules = isset( $manifest['modules'] ) && is_array( $manifest['modules'] ) ? $manifest['modules'] : Diagnostics_Storage::modules();
		$status  = isset( $manifest['module_status'] ) && is_array( $manifest['module_status'] ) ? $manifest['module_status'] : array();
		foreach ( $modules as $module ) {
			$module_key = Diagnostics_Storage::safe_module( $module );
			if ( '' === $module_key ) {
				continue;
			}
			$current = isset( $status[ $module_key ]['status'] ) ? sanitize_key( (string) $status[ $module_key ]['status'] ) : 'pending';
			if ( ! in_array( $current, array( 'completed', 'failed' ), true ) ) {
				return $module_key;
			}
		}

		return '';
	}

	/**
	 * Return whether the normal update lock is active.
	 *
	 * @return bool
	 */
	private static function update_lock_active() {
		$lock = get_transient( 'marrison_update_lock' );
		return is_array( $lock ) && ! empty( $lock['expires'] ) && (int) $lock['expires'] > time();
	}

	/**
	 * Reschedule snapshot start.
	 *
	 * @param string $snapshot_id Snapshot ID.
	 * @param int    $delay       Delay.
	 * @return void
	 */
	private static function reschedule_start( $snapshot_id, $delay ) {
		if ( ! Settings::repository_config_managed() ) {
			return;
		}

		$snapshot_id = Diagnostics_Storage::safe_id( $snapshot_id );
		if ( '' === $snapshot_id ) {
			return;
		}
		$run_at = time() + max( 60, (int) $delay );
		wp_schedule_single_event( $run_at, self::START_HOOK, array( $snapshot_id ), true );
		Diagnostics_Storage::save_pipeline(
			array(
				'status'      => 'queued',
				'snapshot_id' => $snapshot_id,
				'next_run'    => $run_at,
				'updated_at'  => time(),
			)
		);
	}

	/**
	 * Reschedule module collection.
	 *
	 * @param string $snapshot_id Snapshot ID.
	 * @param string $module      Module.
	 * @param int    $delay       Delay.
	 * @return void
	 */
	private static function reschedule_collect( $snapshot_id, $module, $delay ) {
		if ( ! Settings::repository_config_managed() ) {
			return;
		}

		$snapshot_id = Diagnostics_Storage::safe_id( $snapshot_id );
		$module      = Diagnostics_Storage::safe_module( $module );
		if ( '' === $snapshot_id ) {
			return;
		}
		$run_at = time() + max( 60, (int) $delay );
		wp_schedule_single_event( $run_at, self::COLLECT_HOOK, array( $snapshot_id, $module ), true );
		Diagnostics_Storage::save_pipeline(
			array(
				'status'         => 'queued',
				'snapshot_id'    => $snapshot_id,
				'next_run'       => $run_at,
				'current_module' => $module,
				'updated_at'     => time(),
			)
		);
	}

	/**
	 * Load only storage and sanitizer.
	 *
	 * @return void
	 */
	private static function load_storage_only() {
		require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-diagnostics-sanitizer.php';
		require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-diagnostics-storage.php';
	}

	/**
	 * Load collector runtime.
	 *
	 * @return void
	 */
	private static function load_runtime() {
		self::load_storage_only();
		require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-diagnostics-capabilities.php';
		require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-diagnostics-collector.php';
	}
}
