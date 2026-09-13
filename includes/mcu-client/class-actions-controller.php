<?php
/**
 * Maintenance client action endpoint.
 *
 * @package MarrisonCustomUpdater
 */

namespace MarrisonCustomUpdater\MaintenanceClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Executes explicit maintenance actions requested by the Master.
 */
final class Actions_Controller {
	const REST_NAMESPACE      = 'marrison-maintenance/v1';
	const REST_ROUTE          = '/action';
	const CANONICAL_REST_PATH = '/marrison-maintenance/v1/action';
	const MASTER_UPDATE_HOOK  = 'mcu_master_update_event';
	const UPDATE_LOCK_KEY     = 'marrison_update_lock';

	/**
	 * Register non-REST hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::MASTER_UPDATE_HOOK, array( __CLASS__, 'run_queued_update' ), 10, 1 );
	}

	/**
	 * Register route.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'run_action' ),
				'permission_callback' => '__return_true',
				'args'                => array(),
			)
		);
	}

	/**
	 * Run an explicit action.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function run_action( \WP_REST_Request $request ) {
		$start   = microtime( true );
		$site_id = (string) $request->get_header( 'x-marrison-site-id' );
		Debug_Logger::log( 'action_start', array( 'site_id' => $site_id ) );

		if ( strlen( (string) $request->get_body() ) > 1048576 ) {
			return new \WP_Error(
				'marrison_payload_too_large',
				__( 'Payload too large.', 'marrison-custom-updater' ),
				array( 'status' => 413 )
			);
		}

		$auth = Authenticator::authenticate( $request );
		if ( empty( $auth['ok'] ) ) {
			return new \WP_Error(
				isset( $auth['public_code'] ) ? $auth['public_code'] : 'marrison_auth_failed',
				__( 'Request rejected.', 'marrison-custom-updater' ),
				array( 'status' => isset( $auth['status'] ) ? (int) $auth['status'] : 401 )
			);
		}

		Plugin::enforce_repository_access();

		$operation = sanitize_key( (string) $request->get_param( 'operation' ) );
		if ( '' === $operation ) {
			$operation = 'unsupported';
		}

		$parameters = $request->get_param( 'parameters' );
		$parameters = is_array( $parameters ) ? $parameters : array();

		// Keep the local updater cache aligned before running an operation. This
		// also makes force_sync and queued updates use the new repository URLs.
		$repository_config = $request->get_param( 'repository_config' );
		if ( is_array( $repository_config ) && ! empty( $repository_config['revoke'] ) ) {
			Settings::revoke_repository_config();
		} else {
			Settings::sync_repository_config( $repository_config );
		}

		$result = self::execute( $operation, $parameters, $site_id, $start );

		Debug_Logger::log(
			'action_end',
			array(
				'site_id'     => $site_id,
				'operation'   => $operation,
				'duration_ms' => $result['duration_ms'],
				'success'     => ! empty( $result['success'] ) ? '1' : '0',
			)
		);

		return rest_ensure_response( $result );
	}

	/**
	 * Execute one of the explicit maintenance actions.
	 *
	 * @param string              $operation  Requested operation.
	 * @param array<string,mixed> $parameters Operation parameters.
	 * @param string              $site_id    Site ID.
	 * @param float               $start      Start time.
	 * @return array<string,mixed>
	 */
	private static function execute( $operation, array $parameters, $site_id, $start ) {
		$operation = self::canonical_operation( $operation );
		$registry  = self::operation_registry();

		if ( ! isset( $registry[ $operation ] ) ) {
			return self::operation_error( $site_id, $operation, 'unknown', 'unsupported_operation', __( 'Unsupported operation.', 'marrison-custom-updater' ), $start );
		}

		$meta = $registry[ $operation ];
		if ( 'read' === $meta['type'] ) {
			require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-diagnostics-sanitizer.php';
			require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-diagnostics-storage.php';
			require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-diagnostics-controller.php';

			$result = Diagnostics_Controller::execute_read_operation( $operation, $parameters );
			if ( empty( $result['success'] ) ) {
				return self::operation_error(
					$site_id,
					$operation,
					'read',
					isset( $result['error_code'] ) ? (string) $result['error_code'] : 'operation_failed',
					isset( $result['message'] ) ? (string) $result['message'] : __( 'Diagnostic operation failed.', 'marrison-custom-updater' ),
					$start
				);
			}

			return self::operation_success( $site_id, $operation, $meta, isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array(), $start );
		}

		if ( 'clear_cache' === $operation ) {
			self::clear_update_caches();
			$payload = array(
				'success' => true,
				'message' => __( 'Cache cleared.', 'marrison-custom-updater' ),
			);
		} elseif ( 'force_sync' === $operation ) {
			$payload = self::force_update_sync();
		} elseif ( 'cancel_master_update' === $operation ) {
			$payload = self::cancel_master_update();
		} elseif ( 'diagnostics_schedule_snapshot' === $operation ) {
			$payload = self::schedule_diagnostic_snapshot();
		} elseif ( 'update_plugin' === $operation ) {
			$payload = self::update_plugin( $parameters );
		} elseif ( 'update_all' === $operation ) {
			$payload = self::queue_update();
		} elseif ( 'revoke_repository_config' === $operation ) {
			$payload = array(
				'success' => true,
				'message' => __( 'Accesso ai repository privati revocato.', 'marrison-custom-updater' ),
			);
		} else {
			$payload = array(
				'success' => false,
				'message' => __( 'Unsupported operation.', 'marrison-custom-updater' ),
			);
		}

		if ( empty( $payload['success'] ) ) {
			return self::operation_error(
				$site_id,
				$operation,
				'write',
				isset( $payload['error_code'] ) ? (string) $payload['error_code'] : 'operation_failed',
				isset( $payload['message'] ) ? (string) $payload['message'] : __( 'Operation failed.', 'marrison-custom-updater' ),
				$start,
				$payload
			);
		}

		return self::operation_success( $site_id, $operation, $meta, $payload, $start, $payload );
	}

	/**
	 * Explicit operation registry.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function operation_registry() {
		$read_operations = array();
		try {
			require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-diagnostics-sanitizer.php';
			require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-diagnostics-storage.php';
			require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-diagnostics-controller.php';
			$read_operations = Diagnostics_Controller::read_operations();
		} catch ( \Throwable $exception ) {
			$read_operations = array();
		}

		return array_merge(
			array(
				'clear_cache' => array(
					'type'           => 'write',
					'cost_class'     => 'light',
					'required'       => array(),
					'allowed_params' => array(),
					'timeout'        => 30,
					'schema_version' => 1,
				),
				'force_sync' => array(
					'type'           => 'write',
					'cost_class'     => 'moderate',
					'required'       => array(),
					'allowed_params' => array(),
					'timeout'        => 120,
					'schema_version' => 1,
				),
				'cancel_master_update' => array(
					'type'           => 'write',
					'cost_class'     => 'light',
					'required'       => array(),
					'allowed_params' => array(),
					'timeout'        => 30,
					'schema_version' => 1,
				),
				'update_all' => array(
					'type'           => 'write',
					'cost_class'     => 'deferred',
					'required'       => array(),
					'allowed_params' => array(),
					'timeout'        => 30,
					'schema_version' => 1,
				),
				'revoke_repository_config' => array(
					'type'           => 'write',
					'cost_class'     => 'light',
					'required'       => array(),
					'allowed_params' => array(),
					'timeout'        => 30,
					'schema_version' => 1,
				),
				'update_plugin' => array(
					'type'           => 'write',
					'cost_class'     => 'moderate',
					'required'       => array( 'plugin_file' ),
					'allowed_params' => array( 'plugin_file', 'file', 'slug', 'name', 'type', 'current_version', 'new_version', 'package', 'job_id', 'step', 'total' ),
					'timeout'        => 300,
					'schema_version' => 1,
				),
				'diagnostics_schedule_snapshot' => array(
					'type'           => 'write',
					'cost_class'     => 'deferred',
					'required'       => array(),
					'allowed_params' => array(),
					'timeout'        => 30,
					'schema_version' => class_exists( __NAMESPACE__ . '\\Diagnostics_Storage' ) ? Diagnostics_Storage::SCHEMA_VERSION : 1,
				),
			),
			$read_operations
		);
	}

	/**
	 * Normalize operation aliases while preserving old names.
	 *
	 * @param string $operation Raw operation.
	 * @return string
	 */
	private static function canonical_operation( $operation ) {
		$operation = sanitize_key( (string) $operation );
		$aliases = array(
			'cache'                      => 'clear_cache',
			'force_check'                => 'force_sync',
			'clear_master_cron'          => 'cancel_master_update',
			'diagnostics_start_snapshot' => 'diagnostics_schedule_snapshot',
		);

		return isset( $aliases[ $operation ] ) ? $aliases[ $operation ] : $operation;
	}

	/**
	 * Standard success response.
	 *
	 * @param string              $site_id Site ID.
	 * @param string              $operation Operation.
	 * @param array<string,mixed> $meta Operation metadata.
	 * @param array<string,mixed> $data Response data.
	 * @param float               $start Start time.
	 * @param array<string,mixed> $compat Top-level compatibility fields.
	 * @return array<string,mixed>
	 */
	private static function operation_success( $site_id, $operation, array $meta, array $data, $start, array $compat = array() ) {
		$standard = array(
			'success'         => true,
			'site_id'         => sanitize_text_field( (string) $site_id ),
			'operation'       => sanitize_key( (string) $operation ),
			'operation_type'  => sanitize_key( (string) $meta['type'] ),
			'cost_class'      => sanitize_key( (string) $meta['cost_class'] ),
			'schema_version'  => isset( $meta['schema_version'] ) ? (int) $meta['schema_version'] : 1,
			'generated_at'    => time(),
			'duration_ms'     => self::duration_ms( $start ),
			'partial'         => false,
			'warnings'        => array(),
			'data'            => self::safe_data( $data ),
			'client_version'  => defined( 'MCU_PLUGIN_VERSION' ) ? MCU_PLUGIN_VERSION : '',
		);

		return array_merge( $standard, self::safe_data( $compat ) );
	}

	/**
	 * Standard error response.
	 *
	 * @param string              $site_id Site ID.
	 * @param string              $operation Operation.
	 * @param string              $type Operation type.
	 * @param string              $code Error code.
	 * @param string              $message Message.
	 * @param float               $start Start time.
	 * @param array<string,mixed> $compat Top-level compatibility fields.
	 * @return array<string,mixed>
	 */
	private static function operation_error( $site_id, $operation, $type, $code, $message, $start, array $compat = array() ) {
		$standard = array(
			'success'        => false,
			'site_id'        => sanitize_text_field( (string) $site_id ),
			'operation'      => sanitize_key( (string) $operation ),
			'operation_type' => sanitize_key( (string) $type ),
			'error_code'     => sanitize_key( (string) $code ),
			'message'        => self::safe_text( $message ),
			'duration_ms'    => self::duration_ms( $start ),
			'client_version' => defined( 'MCU_PLUGIN_VERSION' ) ? MCU_PLUGIN_VERSION : '',
		);

		return array_merge( $standard, self::safe_data( $compat ) );
	}

	/**
	 * Sanitize structured data for action responses.
	 *
	 * @param mixed $data Raw data.
	 * @return mixed
	 */
	private static function safe_data( $data ) {
		if ( class_exists( __NAMESPACE__ . '\\Diagnostics_Sanitizer' ) ) {
			return Diagnostics_Sanitizer::sanitize( $data );
		}

		return $data;
	}

	/**
	 * Sanitize response text.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private static function safe_text( $text ) {
		if ( class_exists( __NAMESPACE__ . '\\Diagnostics_Sanitizer' ) ) {
			return Diagnostics_Sanitizer::sanitize_text( $text );
		}

		return sanitize_text_field( (string) $text );
	}

	/**
	 * Queue a Master-requested update without running the heavy work in REST.
	 *
	 * @return array<string,mixed>
	 */
	private static function queue_update() {
		$current = self::current_update_status();
		if ( in_array( isset( $current['status'] ) ? $current['status'] : '', array( 'queued', 'running' ), true ) && ! self::is_stale_status( $current ) ) {
			$current_status = isset( $current['status'] ) ? (string) $current['status'] : 'queued';
			$job_id         = isset( $current['job_id'] ) ? (string) $current['job_id'] : '';
			$next_run       = self::next_master_update_run( $job_id );
			$cron_spawned   = false;
			$message        = __( 'Aggiornamento gia accodato.', 'marrison-custom-updater' );
			$can_spawn      = true;

			if ( 'queued' === $current_status && '' !== $job_id ) {
				if ( ! $next_run ) {
					$next_run  = time();
					$scheduled = wp_schedule_single_event( $next_run, self::MASTER_UPDATE_HOOK, array( $job_id ), true );
					if ( is_wp_error( $scheduled ) || ! $scheduled ) {
						$message = is_wp_error( $scheduled ) ? $scheduled->get_error_message() : __( 'Impossibile riaccodare il job di aggiornamento.', 'marrison-custom-updater' );
						$can_spawn = false;
					}
				}

				if ( $can_spawn ) {
					$cron_spawned = self::spawn_queued_update_cron( $next_run ? $next_run : time() );
					$message      = $cron_spawned
						? __( 'Aggiornamento gia accodato, cron riavviato.', 'marrison-custom-updater' )
						: __( 'Aggiornamento gia accodato, in attesa di WP-Cron.', 'marrison-custom-updater' );
				}

				self::save_update_status(
					array_merge(
						$current,
						array(
							'message'      => $message,
							'cron_spawned' => $cron_spawned,
						)
					)
				);
			}

			return array(
				'success'      => true,
				'message'      => $message,
				'job_id'       => $job_id,
				'status'       => $current_status,
				'next_run'     => $next_run,
				'cron_spawned' => $cron_spawned,
			);
		}

		self::clear_update_caches();

		$job_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : md5( uniqid( 'mcu-master-update', true ) );
		$run_at = time();

		self::save_update_status(
			array(
				'job_id'       => $job_id,
				'status'       => 'queued',
				'requested_at' => time(),
				'started_at'   => 0,
				'finished_at'  => 0,
				'message'      => __( 'Richiesta aggiornamento ricevuta dal Master.', 'marrison-custom-updater' ),
			)
		);

		if ( ! wp_next_scheduled( self::MASTER_UPDATE_HOOK, array( $job_id ) ) ) {
			$scheduled = wp_schedule_single_event( $run_at, self::MASTER_UPDATE_HOOK, array( $job_id ), true );
			if ( is_wp_error( $scheduled ) || ! $scheduled ) {
				self::save_update_status(
					array(
						'job_id'       => $job_id,
						'status'       => 'failed',
						'requested_at' => time(),
						'started_at'   => 0,
						'finished_at'  => time(),
						'message'      => is_wp_error( $scheduled ) ? $scheduled->get_error_message() : __( 'Impossibile accodare il job di aggiornamento.', 'marrison-custom-updater' ),
					)
				);

				return array(
					'success' => false,
					'message' => __( 'Impossibile accodare il job di aggiornamento.', 'marrison-custom-updater' ),
				);
			}
		}

		$cron_spawned = self::spawn_queued_update_cron( $run_at );
		$message      = $cron_spawned
			? __( 'Aggiornamento accodato e cron avviato.', 'marrison-custom-updater' )
			: __( 'Aggiornamento accodato, in attesa di WP-Cron.', 'marrison-custom-updater' );

		self::save_update_status(
			array_merge(
				self::current_update_status(),
				array(
					'message'       => $message,
					'cron_spawned'  => $cron_spawned,
				)
			)
		);

		return array(
			'success'      => true,
			'message'      => $message,
			'job_id'       => $job_id,
			'status'       => 'queued',
			'next_run'     => $run_at,
			'cron_spawned' => $cron_spawned,
		);
	}

	/**
	 * Cancel only the update request queued by Master/Commander.
	 *
	 * This deliberately does not touch MCU's automatic schedule
	 * (marrison_scheduled_update_event) and does not interrupt an update that
	 * has already started.
	 *
	 * @return array<string,mixed>
	 */
	private static function cancel_master_update() {
		$status              = self::current_update_status();
		$status_key          = sanitize_key( (string) ( isset( $status['status'] ) ? $status['status'] : '' ) );
		$cleared_cron_events = self::clear_master_update_cron_events();
		$cancelled           = false;
		$message             = __( 'Nessuna richiesta Master/Commander in coda.', 'marrison-custom-updater' );

		if ( 'queued' === $status_key ) {
			$status = array_merge(
				$status,
				array(
					'status'              => 'cancelled',
					'finished_at'         => time(),
					'cancelled_at'        => time(),
					'message'             => __( 'Richiesta Master/Commander annullata.', 'marrison-custom-updater' ),
					'cleared_cron_events' => $cleared_cron_events,
				)
			);
			self::save_update_status( $status );
			$cancelled = true;
			$message   = __( 'Richiesta Master/Commander annullata e cron rimosso.', 'marrison-custom-updater' );
		} elseif ( 'running' === $status_key ) {
			$message = __( 'Il job Master/Commander risulta gia in esecuzione: eventuali cron residui sono stati rimossi, ma l update in corso non viene interrotto.', 'marrison-custom-updater' );
		} elseif ( $cleared_cron_events > 0 ) {
			$message = __( 'Cron Master/Commander residui rimossi.', 'marrison-custom-updater' );
		}

		if ( class_exists( __NAMESPACE__ . '\\Debug_Logger' ) ) {
			Debug_Logger::log(
				'master_update_cancel_requested',
				array(
					'previous_status'     => $status_key,
					'cancelled'           => $cancelled ? '1' : '0',
					'cleared_cron_events' => $cleared_cron_events,
				)
			);
		}

		return array(
			'success'             => true,
			'message'             => $message,
			'cancelled'           => $cancelled,
			'previous_status'     => $status_key,
			'cleared_cron_events' => $cleared_cron_events,
			'status'              => self::current_update_status(),
		);
	}

	/**
	 * Schedule a manual diagnostic snapshot requested by Commander.
	 *
	 * @return array<string,mixed>
	 */
	private static function schedule_diagnostic_snapshot() {
		require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-diagnostics-sanitizer.php';
		require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-diagnostics-storage.php';
		require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-diagnostics-scheduler.php';

		$lock = self::current_update_lock_status();
		if ( ! empty( $lock['locked'] ) ) {
			return array(
				'success'    => false,
				'error_code' => 'update_lock_active',
				'message'    => __( 'Aggiornamento in corso: snapshot diagnostico rimandato.', 'marrison-custom-updater' ),
				'lock'       => $lock,
			);
		}

		$pipeline = Diagnostics_Storage::pipeline_status();
		$status   = sanitize_key( (string) ( isset( $pipeline['status'] ) ? $pipeline['status'] : '' ) );
		if ( in_array( $status, array( 'queued', 'running' ), true ) ) {
			$snapshot_id = Diagnostics_Storage::safe_id( isset( $pipeline['snapshot_id'] ) ? $pipeline['snapshot_id'] : '' );
			$snapshot    = Diagnostics_Storage::latest_snapshot_summary();
			return array(
				'success'    => true,
				'message'    => __( 'Snapshot diagnostico gia in raccolta.', 'marrison-custom-updater' ),
				'status'     => $status,
				'snapshot_id' => $snapshot_id,
				'next_run'   => (int) ( isset( $pipeline['next_run'] ) ? $pipeline['next_run'] : 0 ),
				'snapshot'   => $snapshot,
				'pipeline'   => Diagnostics_Storage::pipeline_summary(),
			);
		}

		$run_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : md5( uniqid( 'mcu-manual-diagnostic', true ) );
		$fingerprint = Diagnostics_Scheduler::capture_pre_maintenance_fingerprint(
			array(
				'maintenance_run_id' => $run_id,
				'source'             => 'commander',
				'manual'             => true,
			)
		);
		$manifest = Diagnostics_Storage::create_manifest( 'manual_commander', 'commander', $run_id, $fingerprint );
		$run_at   = time() + 5;

		if ( ! wp_next_scheduled( Diagnostics_Scheduler::START_HOOK, array( $manifest['snapshot_id'] ) ) ) {
			$scheduled = wp_schedule_single_event( $run_at, Diagnostics_Scheduler::START_HOOK, array( $manifest['snapshot_id'] ), true );
			if ( is_wp_error( $scheduled ) || ! $scheduled ) {
				Diagnostics_Storage::save_pipeline(
					array(
						'status'      => 'failed',
						'snapshot_id' => $manifest['snapshot_id'],
						'last_error'  => is_wp_error( $scheduled ) ? $scheduled->get_error_message() : __( 'Impossibile accodare WP-Cron.', 'marrison-custom-updater' ),
						'updated_at'  => time(),
					)
				);

				return array(
					'success'    => false,
					'error_code' => 'diagnostic_snapshot_schedule_failed',
					'message'    => __( 'Impossibile accodare lo snapshot diagnostico.', 'marrison-custom-updater' ),
					'snapshot'   => Diagnostics_Storage::latest_snapshot_summary(),
					'pipeline'   => Diagnostics_Storage::pipeline_summary(),
				);
			}
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

		$cron_spawned = self::spawn_queued_update_cron( $run_at );

		return array(
			'success'      => true,
			'message'      => $cron_spawned
				? __( 'Snapshot diagnostico accodato e WP-Cron avviato.', 'marrison-custom-updater' )
				: __( 'Snapshot diagnostico accodato, in attesa di WP-Cron.', 'marrison-custom-updater' ),
			'status'       => 'queued',
			'snapshot_id'  => $manifest['snapshot_id'],
			'next_run'     => $run_at,
			'cron_spawned' => $cron_spawned,
			'snapshot'     => Diagnostics_Storage::latest_snapshot_summary(),
			'pipeline'     => Diagnostics_Storage::pipeline_summary(),
		);
	}

	/**
	 * Remove only Master/Commander queued update cron events.
	 *
	 * @return int Number of removed events.
	 */
	private static function clear_master_update_cron_events() {
		$cleared = 0;

		if ( ! function_exists( '_get_cron_array' ) || ! function_exists( 'wp_unschedule_event' ) ) {
			return $cleared;
		}

		$crons = _get_cron_array();
		if ( ! is_array( $crons ) ) {
			return $cleared;
		}

		foreach ( $crons as $timestamp => $hooks ) {
			if ( empty( $hooks[ self::MASTER_UPDATE_HOOK ] ) || ! is_array( $hooks[ self::MASTER_UPDATE_HOOK ] ) ) {
				continue;
			}

			foreach ( $hooks[ self::MASTER_UPDATE_HOOK ] as $event ) {
				$args   = isset( $event['args'] ) && is_array( $event['args'] ) ? $event['args'] : array();
				$result = wp_unschedule_event( (int) $timestamp, self::MASTER_UPDATE_HOOK, $args );
				if ( ! is_wp_error( $result ) && false !== $result ) {
					$cleared++;
				}
			}
		}

		return $cleared;
	}

	/**
	 * Execute one plugin update immediately for Commander.
	 *
	 * @param array<string,mixed> $parameters Operation parameters.
	 * @return array<string,mixed>
	 */
	private static function update_plugin( array $parameters ) {
		$job_id = isset( $parameters['job_id'] ) ? sanitize_text_field( (string) $parameters['job_id'] ) : '';
		if ( '' === $job_id ) {
			$job_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : md5( uniqid( 'mcu-plugin-update', true ) );
		}

		$plugin_file = self::safe_plugin_file( isset( $parameters['plugin_file'] ) ? (string) $parameters['plugin_file'] : (string) ( $parameters['file'] ?? '' ) );
		$slug        = sanitize_key( (string) ( $parameters['slug'] ?? '' ) );
		$name        = self::safe_text( (string) ( $parameters['name'] ?? '' ) );
		$step        = max( 1, (int) ( $parameters['step'] ?? 1 ) );
		$total       = max( $step, (int) ( $parameters['total'] ?? $step ) );

		if ( '' === $plugin_file && '' === $slug ) {
			return array(
				'success'    => false,
				'error_code' => 'missing_plugin',
				'message'    => __( 'Plugin da aggiornare non specificato.', 'marrison-custom-updater' ),
			);
		}

		self::clear_master_update_cron_events();
		self::save_update_status(
			array(
				'job_id'       => $job_id,
				'status'       => 'running',
				'requested_at' => time(),
				'started_at'   => time(),
				'finished_at'  => 0,
				'operation'    => 'update_plugin',
				'plugin_file'  => $plugin_file,
				'plugin_slug'  => $slug,
				'plugin_name'  => $name,
				'step'         => $step,
				'total'        => $total,
				'message'      => sprintf(
					/* translators: 1: plugin name, 2: current step, 3: total steps. */
					__( 'Aggiornamento plugin %1$s (%2$d/%3$d).', 'marrison-custom-updater' ),
					$name !== '' ? $name : ( $slug !== '' ? $slug : $plugin_file ),
					$step,
					$total
				),
			)
		);

		$parameters['plugin_file'] = $plugin_file;
		$parameters['slug']        = $slug;
		$parameters['name']        = $name;
		$result = apply_filters( 'mcu_remote_update_plugin', array( 'success' => false, 'error_code' => 'update_plugin_unavailable', 'message' => __( 'Runner aggiornamento plugin non disponibile.', 'marrison-custom-updater' ) ), $parameters );
		$result = is_array( $result ) ? $result : array(
			'success'    => false,
			'error_code' => 'invalid_update_plugin_response',
			'message'    => __( 'Risposta aggiornamento plugin non valida.', 'marrison-custom-updater' ),
		);

		$success = ! empty( $result['success'] );
		$message = isset( $result['message'] ) && '' !== trim( (string) $result['message'] )
			? self::safe_text( (string) $result['message'] )
			: ( $success ? __( 'Plugin aggiornato.', 'marrison-custom-updater' ) : __( 'Aggiornamento plugin fallito.', 'marrison-custom-updater' ) );

		self::save_update_status(
			array_merge(
				self::current_update_status(),
				array(
					'job_id'      => $job_id,
					'status'      => $success ? 'completed' : 'failed',
					'finished_at' => time(),
					'message'     => $message,
					'last_result' => $result,
				)
			)
		);

		return array_merge(
			$result,
			array(
				'job_id'  => $job_id,
				'status'  => $success ? 'completed' : 'failed',
				'step'    => $step,
				'total'   => $total,
				'message' => $message,
			)
		);
	}

	/**
	 * Sanitize a plugin file parameter.
	 *
	 * @param string $file Raw plugin file.
	 * @return string
	 */
	private static function safe_plugin_file( $file ) {
		$file = str_replace( '\\', '/', sanitize_text_field( (string) $file ) );
		$file = ltrim( $file, '/' );
		if ( '' === $file || '.php' !== substr( $file, -4 ) ) {
			return '';
		}
		if ( function_exists( 'validate_file' ) && 0 !== validate_file( $file ) ) {
			return '';
		}
		if ( false !== strpos( $file, '..' ) || ! preg_match( '/^[A-Za-z0-9._\/-]+$/', $file ) ) {
			return '';
		}

		return $file;
	}

	/**
	 * Execute the queued update through MCU's existing scheduled update flow.
	 *
	 * @param string $job_id Queued job identifier.
	 * @return void
	 */
	public static function run_queued_update( $job_id = '' ) {
		$job_id = sanitize_text_field( (string) $job_id );
		$status = self::current_update_status();

		if ( '' === $job_id || empty( $status['job_id'] ) || ! hash_equals( (string) $status['job_id'], $job_id ) ) {
			return;
		}

		$status_key = sanitize_key( (string) ( isset( $status['status'] ) ? $status['status'] : '' ) );
		if ( ! in_array( $status_key, array( 'queued', 'running' ), true ) ) {
			return;
		}

		if ( 'running' === $status_key && ! self::is_stale_status( $status ) ) {
			return;
		}

		self::save_update_status(
			array_merge(
				$status,
				array(
					'status'     => 'running',
					'started_at' => time(),
					'message'    => __( 'Aggiornamento in corso.', 'marrison-custom-updater' ),
				)
			)
		);

		@ignore_user_abort( true );
		@set_time_limit( 0 );
		self::clear_update_caches();

		try {
			do_action( 'marrison_scheduled_update_event', 'master' );
			$last_log = get_option( 'marrison_last_cron_log', array() );
			$log_status = is_array( $last_log ) && isset( $last_log['status'] ) ? sanitize_key( (string) $last_log['status'] ) : '';
			$finished_status = in_array( $log_status, array( 'error', 'skipped' ), true ) ? 'failed' : 'completed';
			$message = is_array( $last_log ) && ! empty( $last_log['message'] )
				? sanitize_text_field( (string) $last_log['message'] )
				: __( 'Aggiornamento completato tramite Master.', 'marrison-custom-updater' );

			self::save_update_status(
				array_merge(
					self::current_update_status(),
					array(
						'status'      => $finished_status,
						'finished_at' => time(),
						'message'     => $message,
						'last_log'    => is_array( $last_log ) ? $last_log : array(),
					)
				)
			);
		} catch ( \Throwable $exception ) {
			self::save_update_status(
				array_merge(
					self::current_update_status(),
					array(
						'status'      => 'failed',
						'finished_at' => time(),
						'message'     => $exception->getMessage(),
					)
				)
			);
		}
	}

	/**
	 * Clear MCU and WordPress update caches.
	 *
	 * @return void
	 */
	private static function clear_update_caches() {
		delete_transient( 'marrison_available_updates_v2' );
		delete_transient( 'marrison_available_theme_updates' );
		delete_site_transient( 'update_plugins' );
		delete_site_transient( 'update_themes' );

		if ( function_exists( 'wp_clean_update_cache' ) ) {
			wp_clean_update_cache();
		}
	}

	/**
	 * Force a user-requested update metadata refresh for Master/Commander.
	 *
	 * @return array<string,mixed>
	 */
	private static function force_update_sync() {
		$lock = self::current_update_lock_status();
		if ( ! empty( $lock['locked'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Aggiornamento in corso: sincronizzazione rimandata.', 'marrison-custom-updater' ),
				'locked'  => true,
				'lock'    => $lock,
			);
		}

		@set_time_limit( 120 );
		self::clear_update_caches();

		if ( file_exists( ABSPATH . WPINC . '/update.php' ) ) {
			require_once ABSPATH . WPINC . '/update.php';
		}
		if ( ! function_exists( 'get_plugins' ) && file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( function_exists( 'wp_version_check' ) ) {
			wp_version_check( array(), true );
		}
		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}
		if ( function_exists( 'wp_update_themes' ) ) {
			wp_update_themes();
		}

		$private_plugins = self::fetch_private_repo_updates( 'plugin' );
		$private_themes  = self::fetch_private_repo_updates( 'theme' );
		self::inject_private_theme_updates( $private_themes );

		if ( file_exists( ABSPATH . 'wp-admin/includes/translation-install.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/translation-install.php';
		}
		if ( function_exists( 'wp_get_translation_updates' ) ) {
			wp_get_translation_updates();
		}

		Debug_Logger::log(
			'force_update_sync_completed',
			array(
				'private_plugins' => count( $private_plugins ),
				'private_themes'  => count( $private_themes ),
			)
		);

		return array(
			'success' => true,
			'message' => __( 'Sincronizzazione aggiornamenti completata.', 'marrison-custom-updater' ),
			'private_plugins_count' => count( $private_plugins ),
			'private_themes_count'  => count( $private_themes ),
		);
	}

	/**
	 * Fetch private repo metadata and refresh MCU transients.
	 *
	 * @param string $type plugin|theme.
	 * @return array<int,array<string,mixed>>
	 */
	private static function fetch_private_repo_updates( $type = 'plugin' ) {
		$type              = 'theme' === $type ? 'theme' : 'plugin';
		if ( ! Settings::repository_config_managed() ) {
			return array();
		}
		$repo_option       = 'theme' === $type ? 'marrison_themes_repo_url' : 'marrison_repo_url';
		$cache_key         = 'theme' === $type ? 'marrison_available_theme_updates' : 'marrison_available_updates_v2';
		$failure_key       = 'theme' === $type ? 'marrison_theme_updates_fetch_failed' : 'marrison_updates_fetch_failed';
		$repo_url          = trailingslashit( trim( (string) get_option( $repo_option, '' ) ) );
		$hour              = defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600;
		$minute            = defined( 'MINUTE_IN_SECONDS' ) ? MINUTE_IN_SECONDS : 60;

		delete_transient( $failure_key );

		if ( '' === $repo_url ) {
			delete_transient( $cache_key );
			return array();
		}

		$response = wp_remote_get(
			$repo_url . 'index.php',
			array(
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) >= 400 ) {
			set_transient( $failure_key, 1, 5 * $minute );
			return array();
		}

		$updates = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $updates ) ) {
			set_transient( $failure_key, 1, 5 * $minute );
			return array();
		}

		$cleaned = array();
		foreach ( $updates as $update ) {
			if ( ! is_array( $update ) || empty( $update['slug'] ) ) {
				continue;
			}

			$update['slug'] = trim( (string) $update['slug'] );
			if ( isset( $update['version'] ) ) {
				$update['version'] = trim( (string) $update['version'] );
			}
			if ( isset( $update['name'] ) ) {
				$update['name'] = trim( (string) $update['name'] );
			}
			if ( isset( $update['name'] ) && ( false !== strpos( (string) $update['name'], '$' ) || false !== strpos( (string) $update['name'], '/i\'' ) ) ) {
				continue;
			}
			if ( isset( $update['version'] ) && false !== strpos( (string) $update['version'], '$' ) ) {
				continue;
			}

			$cleaned[] = $update;
		}

		set_transient( $cache_key, $cleaned, 6 * $hour );
		return $cleaned;
	}

	/**
	 * Inject private theme updates into WordPress theme update transient.
	 *
	 * @param array<int,array<string,mixed>> $updates Theme updates.
	 * @return void
	 */
	private static function inject_private_theme_updates( array $updates ) {
		if ( empty( $updates ) ) {
			return;
		}

		$transient = get_site_transient( 'update_themes' );
		if ( ! is_object( $transient ) ) {
			$transient = new \stdClass();
		}
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		$excluded = get_option( 'marrison_excluded_themes', array() );
		$excluded = is_array( $excluded ) ? array_map( 'sanitize_key', $excluded ) : array();

		foreach ( $updates as $update ) {
			$slug = sanitize_key( (string) ( $update['slug'] ?? '' ) );
			if ( '' === $slug || in_array( $slug, $excluded, true ) ) {
				continue;
			}

			$theme = wp_get_theme( $slug );
			if ( ! $theme->exists() ) {
				continue;
			}

			$new_version = sanitize_text_field( (string) ( $update['version'] ?? '' ) );
			if ( '' === $new_version || ! version_compare( (string) $theme->get( 'Version' ), $new_version, '<' ) ) {
				continue;
			}

			$transient->response[ $slug ] = array(
				'theme'       => $slug,
				'new_version' => $new_version,
				'url'         => isset( $update['info_url'] ) ? esc_url_raw( (string) $update['info_url'] ) : '',
				'package'     => isset( $update['download_url'] ) ? esc_url_raw( (string) $update['download_url'] ) : '',
			);
		}

		set_site_transient( 'update_themes', $transient );
	}

	/**
	 * Wake WP-Cron after an explicit Master request without blocking the REST response.
	 *
	 * @param int $run_at Scheduled timestamp.
	 * @return bool
	 */
	private static function spawn_queued_update_cron( $run_at ) {
		if ( function_exists( 'spawn_cron' ) && spawn_cron( (int) $run_at ) ) {
			return true;
		}

		$doing_wp_cron = sprintf( '%.22F', microtime( true ) );
		$cron_url      = site_url( 'wp-cron.php?doing_wp_cron=' . rawurlencode( $doing_wp_cron ) );
		$response      = wp_remote_post(
			$cron_url,
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'headers'   => array(
					'Cache-Control' => 'no-cache',
				),
			)
		);

		return ! is_wp_error( $response );
	}

	/**
	 * Return the latest Master update status.
	 *
	 * @return array<string,mixed>
	 */
	public static function current_update_status() {
		self::recover_stale_cron_log_if_needed( 'master_status' );

		$status = get_option( 'mcu_master_update_status', array() );
		if ( ! is_array( $status ) ) {
			return array();
		}

		if ( in_array( isset( $status['status'] ) ? $status['status'] : '', array( 'queued', 'running' ), true ) && self::is_stale_status( $status ) ) {
			$status = array_merge(
				$status,
				array(
					'status'      => 'failed',
					'finished_at' => time(),
					'message'     => __( 'Richiesta precedente senza risposta dal client.', 'marrison-custom-updater' ),
					'stale'       => true,
				)
			);
			self::save_update_status( $status );
		}

		return $status;
	}

	/**
	 * Return the active MCU update lock without exposing the secret token.
	 *
	 * @return array<string,mixed>
	 */
	public static function current_update_lock_status() {
		$lock = get_transient( self::UPDATE_LOCK_KEY );
		if ( ! is_array( $lock ) ) {
			return array(
				'locked' => false,
			);
		}

		$now           = time();
		$last_activity = self::update_lock_last_activity( $lock );
		$heartbeat_at  = isset( $lock['heartbeat_at'] ) ? (int) $lock['heartbeat_at'] : 0;
		$expires_at    = isset( $lock['expires'] ) ? (int) $lock['expires'] : 0;
		$operation     = isset( $lock['operation'] ) ? sanitize_key( (string) $lock['operation'] ) : '';
		$run_id        = isset( $lock['run_id'] ) ? sanitize_text_field( (string) $lock['run_id'] ) : '';
		$is_expired    = $expires_at <= $now;
		$is_stale      = self::is_update_lock_stale( $lock );

		if ( $is_expired || $is_stale ) {
			delete_transient( self::UPDATE_LOCK_KEY );
			if ( class_exists( __NAMESPACE__ . '\\Debug_Logger' ) ) {
				Debug_Logger::log(
					'update_lock_stale_cleared',
					array(
						'operation'             => $operation,
						'run_id'                => $run_id,
						'expired'               => $is_expired ? '1' : '0',
						'last_activity_seconds' => $last_activity > 0 ? max( 0, $now - $last_activity ) : 0,
					)
				);
			}

			return array(
				'locked'                => false,
				'operation'             => $operation,
				'started_at'            => isset( $lock['started_at'] ) ? sanitize_text_field( (string) $lock['started_at'] ) : '',
				'started_at_unix'       => isset( $lock['started_at_unix'] ) ? (int) $lock['started_at_unix'] : 0,
				'last_activity_at'      => $last_activity,
				'last_activity_seconds' => $last_activity > 0 ? max( 0, $now - $last_activity ) : 0,
				'heartbeat_at'          => $heartbeat_at,
				'heartbeat_seconds'     => $heartbeat_at > 0 ? max( 0, $now - $heartbeat_at ) : 0,
				'expires_at'            => $expires_at,
				'expires_in'            => $expires_at > 0 ? max( 0, $expires_at - $now ) : 0,
				'is_stale'              => true,
				'stale_cleared'         => true,
				'run_id'                => $run_id,
			);
		}

		return array(
			'locked'                => true,
			'operation'             => $operation,
			'started_at'            => isset( $lock['started_at'] ) ? sanitize_text_field( (string) $lock['started_at'] ) : '',
			'started_at_unix'       => isset( $lock['started_at_unix'] ) ? (int) $lock['started_at_unix'] : 0,
			'last_activity_at'      => $last_activity,
			'last_activity_seconds' => $last_activity > 0 ? max( 0, $now - $last_activity ) : 0,
			'heartbeat_at'          => $heartbeat_at,
			'heartbeat_seconds'     => $heartbeat_at > 0 ? max( 0, $now - $heartbeat_at ) : 0,
			'expires_at'            => $expires_at,
			'expires_in'            => max( 0, $expires_at - $now ),
			'is_stale'              => false,
			'run_id'                => $run_id,
		);
	}

	/**
	 * Persist Master update status.
	 *
	 * @param array<string,mixed> $status Status data.
	 * @return void
	 */
	private static function save_update_status( array $status ) {
		update_option( 'mcu_master_update_status', $status, false );
	}

	/**
	 * Mark an interrupted scheduled update log as failed once it is stale.
	 *
	 * @param string $context Caller context.
	 * @return bool
	 */
	private static function recover_stale_cron_log_if_needed( $context = '' ) {
		$last_log = get_option( 'marrison_last_cron_log', array() );
		if ( ! is_array( $last_log ) || 'started' !== sanitize_key( (string) ( isset( $last_log['status'] ) ? $last_log['status'] : '' ) ) ) {
			return false;
		}

		$started_at  = self::local_mysql_to_timestamp( isset( $last_log['time'] ) ? (string) $last_log['time'] : '' );
		$stale_after = self::cron_started_stale_after_seconds();
		if ( $started_at <= 0 || ( time() - $started_at ) <= $stale_after ) {
			return false;
		}

		$last_log['status']              = 'error';
		$last_log['message']             = sprintf(
			__( 'Esecuzione precedente interrotta o scaduta: nessuna chiusura entro %d minuti.', 'marrison-custom-updater' ),
			(int) ceil( $stale_after / 60 )
		);
		$last_log['stale']               = true;
		$last_log['stale_after_seconds'] = $stale_after;
		$last_log['recovered_at']        = current_time( 'mysql' );

		update_option( 'marrison_last_cron_log', $last_log );
		if ( class_exists( __NAMESPACE__ . '\\Debug_Logger' ) ) {
			Debug_Logger::log(
				'cron_log_stale_recovered',
				array(
					'started_at'   => isset( $last_log['time'] ) ? (string) $last_log['time'] : '',
					'recovered_at' => $last_log['recovered_at'],
					'context'      => sanitize_key( (string) $context ),
				)
			);
		}

		return true;
	}

	/**
	 * Return seconds after which a "started" cron log is considered stale.
	 *
	 * @return int
	 */
	private static function cron_started_stale_after_seconds() {
		$minute      = defined( 'MINUTE_IN_SECONDS' ) ? MINUTE_IN_SECONDS : 60;
		$stale_after = (int) apply_filters( 'mcu_cron_started_stale_after', 10 * $minute );
		return max( 10 * $minute, $stale_after );
	}

	/**
	 * Return whether a queued or running status is stale.
	 *
	 * @param array<string,mixed> $status Status data.
	 * @return bool
	 */
	private static function is_stale_status( array $status ) {
		$now = time();
		$running_since = isset( $status['started_at'] ) ? (int) $status['started_at'] : 0;
		$requested_at  = isset( $status['requested_at'] ) ? (int) $status['requested_at'] : 0;
		$minute        = defined( 'MINUTE_IN_SECONDS' ) ? MINUTE_IN_SECONDS : 60;
		$hour          = defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600;

		if ( 'running' === ( isset( $status['status'] ) ? $status['status'] : '' ) ) {
			$lock = self::current_update_lock_status();
			if ( ! empty( $lock['stale_cleared'] ) ) {
				return true;
			}

			if ( ! empty( $lock['locked'] ) ) {
				if ( 'scheduled_updates' !== ( isset( $lock['operation'] ) ? (string) $lock['operation'] : '' ) ) {
					return $running_since > 0 && ( $now - $running_since ) > 15 * $minute;
				}

				return ! empty( $lock['is_stale'] );
			}

			return $running_since > 0 && ( $now - $running_since ) > 15 * $minute;
		}

		return $requested_at > 0 && ( $now - $requested_at ) > $hour;
	}

	/**
	 * Return seconds after which a lock heartbeat is considered stale.
	 *
	 * @return int
	 */
	private static function update_lock_stale_after_seconds() {
		$minute      = defined( 'MINUTE_IN_SECONDS' ) ? MINUTE_IN_SECONDS : 60;
		$stale_after = (int) apply_filters( 'mcu_update_lock_stale_after', 10 * $minute );
		return max( 10 * $minute, $stale_after );
	}

	/**
	 * Return the configured lock timeout.
	 *
	 * @return int
	 */
	private static function update_lock_timeout_seconds() {
		$minute  = defined( 'MINUTE_IN_SECONDS' ) ? MINUTE_IN_SECONDS : 60;
		$hour    = defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600;
		$timeout = (int) apply_filters( 'mcu_update_lock_timeout', 2 * $hour );
		return max( 5 * $minute, $timeout );
	}

	/**
	 * Return the latest activity timestamp for a lock.
	 *
	 * @param array<string,mixed> $lock Lock data.
	 * @return int
	 */
	private static function update_lock_last_activity( array $lock ) {
		if ( ! empty( $lock['heartbeat_at'] ) ) {
			return (int) $lock['heartbeat_at'];
		}
		if ( ! empty( $lock['started_at_unix'] ) ) {
			return (int) $lock['started_at_unix'];
		}
		if ( ! empty( $lock['expires'] ) ) {
			return max( 0, (int) $lock['expires'] - self::update_lock_timeout_seconds() );
		}

		return 0;
	}

	/**
	 * Convert a site-local MySQL datetime to a Unix timestamp.
	 *
	 * @param string $mysql Local MySQL datetime.
	 * @return int
	 */
	private static function local_mysql_to_timestamp( $mysql ) {
		$mysql = trim( (string) $mysql );
		if ( '' === $mysql || '0000-00-00 00:00:00' === $mysql ) {
			return 0;
		}

		try {
			$date = new \DateTimeImmutable( $mysql, wp_timezone() );
			return $date->getTimestamp();
		} catch ( \Exception $exception ) {
			$timestamp = strtotime( $mysql );
			return $timestamp ? (int) $timestamp : 0;
		}
	}

	/**
	 * Return whether a lock is stale.
	 *
	 * @param array<string,mixed> $lock Lock data.
	 * @return bool
	 */
	private static function is_update_lock_stale( array $lock ) {
		if ( empty( $lock['expires'] ) ) {
			return false;
		}
		if ( (int) $lock['expires'] <= time() ) {
			return true;
		}

		$last_activity = self::update_lock_last_activity( $lock );
		return $last_activity > 0 && ( time() - $last_activity ) > self::update_lock_stale_after_seconds();
	}

	/**
	 * Return the next scheduled run for a Master update job.
	 *
	 * @param string $job_id Job identifier.
	 * @return int
	 */
	private static function next_master_update_run( $job_id ) {
		if ( '' === $job_id ) {
			return 0;
		}

		$timestamp = wp_next_scheduled( self::MASTER_UPDATE_HOOK, array( $job_id ) );
		return $timestamp ? (int) $timestamp : 0;
	}

	/**
	 * Return duration in milliseconds.
	 *
	 * @param float $start Start time.
	 * @return int
	 */
	private static function duration_ms( $start ) {
		return (int) round( ( microtime( true ) - (float) $start ) * 1000 );
	}
}
