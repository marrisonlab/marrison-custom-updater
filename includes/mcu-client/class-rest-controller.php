<?php
/**
 * Maintenance client REST endpoint.
 *
 * @package MarrisonCustomUpdater
 */

namespace MarrisonCustomUpdater\MaintenanceClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Status REST controller.
 */
final class Rest_Controller {
	const REST_NAMESPACE      = 'marrison-maintenance/v1';
	const REST_ROUTE          = '/status';
	const CANONICAL_REST_PATH = '/marrison-maintenance/v1/status';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'status' ),
				'permission_callback' => '__return_true',
				'args'                => array(),
			)
		);
	}

	/**
	 * Return the signed status response.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function status( \WP_REST_Request $request ) {
		$start   = microtime( true );
		$site_id = (string) $request->get_header( 'x-marrison-site-id' );
		Debug_Logger::log( 'request_start', array( 'site_id' => $site_id ) );

		$auth = Authenticator::authenticate( $request );
		if ( empty( $auth['ok'] ) ) {
			Debug_Logger::log(
				'request_end',
				array(
					'site_id'     => isset( $auth['site_id'] ) ? $auth['site_id'] : '',
					'duration_ms' => self::duration_ms( $start ),
					'http_code'   => isset( $auth['status'] ) ? $auth['status'] : 401,
					'error_type'  => isset( $auth['reason'] ) ? $auth['reason'] : 'authentication_failed',
				)
			);

			return new \WP_Error(
				isset( $auth['public_code'] ) ? $auth['public_code'] : 'marrison_auth_failed',
				__( 'Request rejected.', 'marrison-custom-updater' ),
				array( 'status' => isset( $auth['status'] ) ? (int) $auth['status'] : 401 )
			);
		}

		Plugin::enforce_repository_access();

		// Commander is the source of truth for private repository URLs. The
		// payload is accepted only after the normal HMAC authentication above.
		Settings::sync_repository_config( $request->get_param( 'repository_config' ) );

		$data = self::collect_status();

		Debug_Logger::log(
			'request_end',
			array(
				'site_id'     => isset( $auth['site_id'] ) ? $auth['site_id'] : '',
				'duration_ms' => self::duration_ms( $start ),
				'http_code'   => 200,
				'error_type'  => '',
			)
		);

		return rest_ensure_response( $data );
	}

	/**
	 * Build status data without forcing external update checks.
	 *
	 * @return array<string,mixed>
	 */
	private static function collect_status() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		global $wp_version;

		$plugins        = function_exists( 'get_plugins' ) ? get_plugins() : array();
		$active_plugins = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$sitewide_plugins = (array) get_site_option( 'active_sitewide_plugins', array() );
			$active_plugins   = array_merge( $active_plugins, array_keys( $sitewide_plugins ) );
		}

		$theme = wp_get_theme();

		$plugin_updates      = get_site_transient( 'update_plugins' );
		$theme_updates       = get_site_transient( 'update_themes' );
		$core_updates        = get_site_transient( 'update_core' );
		$plugin_update_items = self::plugin_update_items( $plugins, $plugin_updates );
		$plugin_update_count = count( $plugin_update_items );
		$theme_update_count  = self::theme_update_count( $theme_updates );
		$core_update_count   = self::core_update_count( $core_updates );
		$translation_count   = self::translation_update_count( $plugin_updates, $theme_updates, $core_updates );
		$mcu_update_count    = self::plugin_update_type_count( $plugin_update_items, 'private' );
		$total_update_count  = $plugin_update_count + $theme_update_count + $translation_count + $core_update_count;
		$last_checked        = max(
			self::transient_last_checked( $plugin_updates ),
			self::transient_last_checked( $theme_updates ),
			self::transient_last_checked( $core_updates )
		);
		$updates_fresh       = $last_checked > 0 && ( time() - $last_checked ) < 12 * HOUR_IN_SECONDS;
		$next_scheduled_run  = self::next_scheduled_update_run();
		$mcu_frequency       = self::mcu_update_frequency();
		$mcu_month_day       = self::mcu_update_month_day();
		$mcu_last_update_at  = self::mcu_last_update_timestamp();
		$backup_summary      = self::backup_summary();
		$master_update       = class_exists( __NAMESPACE__ . '\\Actions_Controller' ) ? Actions_Controller::current_update_status() : array();
		$update_lock         = class_exists( __NAMESPACE__ . '\\Actions_Controller' ) ? Actions_Controller::current_update_lock_status() : array( 'locked' => false );
		$diagnostics         = self::diagnostic_protocol_status();
		$remote_debug        = class_exists( __NAMESPACE__ . '\\Debug_Manager' ) ? Debug_Manager::status_summary() : array( 'available' => false );

		return array(
			'success'                    => true,
			'protocol_version'           => 2,
			'diagnostic_schema_version'  => 1,
			'supported_read_operations'  => $diagnostics['supported_read_operations'],
			'supported_write_operations' => $diagnostics['supported_write_operations'],
			'snapshot'                   => $diagnostics['snapshot'],
			'snapshot_pipeline'          => $diagnostics['snapshot_pipeline'],
			'site_url'                   => site_url(),
			'site_name'                  => get_bloginfo( 'name' ),
			'client_plugin_version'      => defined( 'MCU_PLUGIN_VERSION' ) ? MCU_PLUGIN_VERSION : '',
			'mcu_plugin_version'         => defined( 'MCU_PLUGIN_VERSION' ) ? MCU_PLUGIN_VERSION : '',
			'wordpress_version'          => isset( $wp_version ) ? (string) $wp_version : '',
			'php_version'                => PHP_VERSION,
			'server_software'            => self::server_software(),
			'is_ssl'                     => is_ssl(),
			'multisite'                  => is_multisite(),
			'timezone'                   => wp_timezone_string(),
			'current_timestamp'          => time(),
			'active_plugins_count'       => count( array_unique( $active_plugins ) ),
			'installed_plugins_count'    => count( $plugins ),
			'active_theme'               => $theme->get( 'Name' ),
			'active_theme_version'       => $theme->get( 'Version' ),
			'updates_available_count'    => $total_update_count,
			'wordpress_updates_count'    => $core_update_count,
			'plugin_updates_count'       => $plugin_update_count,
			'plugin_updates'             => $plugin_update_items,
			'theme_updates_count'        => $theme_update_count,
			'translation_updates_count'  => $translation_count,
			'mcu_private_updates_count'  => $mcu_update_count,
			'updates_data_fresh'         => $updates_fresh,
			'updates_may_be_stale'       => ! $updates_fresh,
			'updates_last_checked_at'    => $last_checked,
			'last_wordpress_cron'        => null,
			'mcu_auto_update_enabled'    => 'yes' === get_option( 'marrison_auto_update_enabled' ),
			'mcu_update_frequency'       => $mcu_frequency,
			'mcu_update_frequency_label' => self::mcu_update_frequency_label( $mcu_frequency ),
			'mcu_update_month_day'       => $mcu_month_day,
			'mcu_last_update_at'         => $mcu_last_update_at,
			'mcu_next_scheduled_update'  => $next_scheduled_run ? (int) $next_scheduled_run : 0,
			'mcu_master_update_status'   => $master_update,
			'mcu_update_lock'            => $update_lock,
			'mcu_backup_summary'         => $backup_summary,
			'memory_limit'               => ini_get( 'memory_limit' ),
			'debug_mode'                 => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'mcu_remote_debug'           => $remote_debug,
			'environment_type'           => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
		);
	}

	/**
	 * Return light protocol v2 diagnostic metadata for /status.
	 *
	 * @return array<string,mixed>
	 */
	private static function diagnostic_protocol_status() {
		try {
			require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-diagnostics-sanitizer.php';
			require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-diagnostics-storage.php';
			require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-diagnostics-controller.php';

			return array(
				'supported_read_operations'  => Diagnostics_Controller::supported_read_operations(),
				'supported_write_operations' => Diagnostics_Controller::supported_write_operations(),
				'snapshot'                   => Diagnostics_Storage::latest_snapshot_summary(),
				'snapshot_pipeline'          => Diagnostics_Storage::pipeline_summary(),
			);
		} catch ( \Throwable $exception ) {
			return array(
				'supported_read_operations'  => array(),
				'supported_write_operations' => array( 'clear_cache', 'force_sync', 'cancel_master_update', 'update_all', 'update_plugin', 'diagnostics_schedule_snapshot', 'revoke_repository_config', 'debug_toggle', 'debug_log_delete', 'debug_log_clear' ),
				'snapshot'                   => array(
					'available' => false,
					'status'    => 'unavailable',
				),
				'snapshot_pipeline'          => array(
					'status'         => 'unavailable',
					'pending_since'  => 0,
					'next_run'       => 0,
					'current_module' => '',
				),
			);
		}
	}

	/**
	 * Return the latest available backup metadata for the Master report.
	 *
	 * @return array<string,mixed>
	 */
	private static function backup_summary() {
		$summary = array(
			'database' => array(
				'available'   => false,
				'filename'    => '',
				'download_url'=> '',
				'size'        => '',
			),
			'files'    => array(
				'available'    => false,
				'filenames'    => array(),
				'download_urls'=> array(),
				'size'         => '',
			),
		);

		$db_filename = sanitize_file_name( (string) get_option( 'marrison_last_db_backup_filename', '' ) );
		if ( '' !== $db_filename ) {
			$summary['database'] = self::backup_file_summary( $db_filename, 'db' );
		}

		$files_filenames = get_option( 'marrison_last_files_backup_filenames', array() );
		if ( ! is_array( $files_filenames ) ) {
			$files_filenames = array();
		}
		$files_filenames = array_values(
			array_filter(
				array_map(
					static function ( $filename ) {
						return sanitize_file_name( (string) $filename );
					},
					$files_filenames
				)
			)
		);

		if ( ! empty( $files_filenames ) ) {
			$files_summary = array(
				'available'     => true,
				'filenames'     => array(),
				'download_urls' => array(),
				'size'          => '',
			);
			$total_bytes = 0;
			foreach ( $files_filenames as $filename ) {
				$file_summary = self::backup_file_summary( $filename, 'files' );
				if ( empty( $file_summary['available'] ) ) {
					continue;
				}
				$files_summary['filenames'][]     = $filename;
				$files_summary['download_urls'][] = $file_summary['download_url'];
				$total_bytes += isset( $file_summary['bytes'] ) ? (int) $file_summary['bytes'] : 0;
			}
			if ( ! empty( $files_summary['filenames'] ) ) {
				$files_summary['size'] = $total_bytes > 0 ? size_format( $total_bytes ) : '';
				$summary['files']      = $files_summary;
			}
		}

		return $summary;
	}

	/**
	 * Build metadata for one backup file.
	 *
	 * @param string $filename Backup filename.
	 * @param string $type Backup type.
	 * @return array<string,mixed>
	 */
	private static function backup_file_summary( $filename, $type ) {
		$backup_dir = trailingslashit( WP_CONTENT_DIR ) . 'marrison-backups';
		$file_path  = trailingslashit( $backup_dir ) . $filename;
		$exists     = is_readable( $file_path );

		return array(
			'available'    => $exists,
			'filename'     => $exists ? $filename : '',
			'download_url'  => $exists ? self::backup_download_url( $filename, $type ) : '',
			'bytes'        => $exists ? (int) filesize( $file_path ) : 0,
			'size'         => $exists ? size_format( filesize( $file_path ) ) : '',
		);
	}

	/**
	 * Build the backup download URL using the client token scheme.
	 *
	 * @param string $filename Backup filename.
	 * @param string $type Backup type.
	 * @return string
	 */
	private static function backup_download_url( $filename, $type ) {
		$action = 'files' === $type ? 'marrison_download_files_backup' : 'marrison_download_db_backup';
		return add_query_arg(
			array(
				'action' => $action,
				'file'   => $filename,
				'token'  => wp_hash( $type . '|' . $filename ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Return plugin updates with display metadata for the Master details panel.
	 *
	 * @param array<string,array<string,string>> $plugins        Installed plugins.
	 * @param mixed                             $plugin_updates WordPress.org update transient.
	 * @return array<int,array<string,string>>
	 */
	private static function plugin_update_items( array $plugins, $plugin_updates ) {
		$items = array();
		$seen  = array();

		$private_updates = Settings::repository_config_managed() ? get_transient( 'marrison_available_updates_v2' ) : array();
		if ( is_array( $private_updates ) ) {
			foreach ( $private_updates as $update ) {
				if ( ! is_array( $update ) || empty( $update['slug'] ) ) {
					continue;
				}

				$slug = sanitize_key( (string) $update['slug'] );
				if ( '' === $slug || self::is_plugin_excluded( $slug ) ) {
					continue;
				}

				$name        = isset( $update['name'] ) ? sanitize_text_field( (string) $update['name'] ) : '';
				$new_version = isset( $update['version'] ) ? sanitize_text_field( (string) $update['version'] ) : '';
				if ( '' === $new_version ) {
					continue;
				}

				$file = self::find_plugin_file_for_update( $slug, $name, $plugins );
				if ( ! $file || isset( $seen[ $file ] ) || ! isset( $plugins[ $file ] ) ) {
					continue;
				}

				$current = isset( $plugins[ $file ]['Version'] ) ? (string) $plugins[ $file ]['Version'] : '';
				if ( '' === $current || ! version_compare( $current, $new_version, '<' ) ) {
					continue;
				}

				if ( '' === $name && ! empty( $plugins[ $file ]['Name'] ) ) {
					$name = (string) $plugins[ $file ]['Name'];
				}

				$items[]       = self::plugin_update_item( 'private', $file, $slug, $name, $current, $new_version );
				$seen[ $file ] = true;
			}
		}

		if ( is_object( $plugin_updates ) && isset( $plugin_updates->response ) && is_array( $plugin_updates->response ) ) {
			foreach ( $plugin_updates->response as $file => $update ) {
				$file = (string) $file;
				if ( isset( $seen[ $file ] ) ) {
					continue;
				}

				$slug        = self::plugin_update_slug( $file, $update );
				$data        = isset( $plugins[ $file ] ) && is_array( $plugins[ $file ] ) ? $plugins[ $file ] : array();
				$name        = isset( $data['Name'] ) && '' !== $data['Name'] ? (string) $data['Name'] : $slug;
				$current     = isset( $data['Version'] ) ? (string) $data['Version'] : '';
				$new_version = is_object( $update ) && isset( $update->new_version ) ? (string) $update->new_version : '';
				if ( self::is_plugin_update_excluded( $slug, $file, $name, $update ) ) {
					continue;
				}

				$items[]       = self::plugin_update_item( 'wordpress_org', $file, $slug, $name, $current, $new_version );
				$seen[ $file ] = true;
			}
		}

		usort(
			$items,
			static function ( $first, $second ) {
				return strcasecmp( $first['name'], $second['name'] );
			}
		);

		return array_values( $items );
	}

	/**
	 * Normalize a plugin update item.
	 *
	 * @param string $type        Update source.
	 * @param string $file        Plugin file.
	 * @param string $slug        Plugin slug.
	 * @param string $name        Plugin name.
	 * @param string $current     Current version.
	 * @param string $new_version New version.
	 * @return array<string,string>
	 */
	private static function plugin_update_item( $type, $file, $slug, $name, $current, $new_version ) {
		return array(
			'type'            => sanitize_key( $type ),
			'file'            => sanitize_text_field( $file ),
			'slug'            => sanitize_key( $slug ),
			'name'            => sanitize_text_field( '' !== $name ? $name : $slug ),
			'current_version' => sanitize_text_field( $current ),
			'new_version'     => sanitize_text_field( $new_version ),
		);
	}

	/**
	 * Count plugin update items by source type.
	 *
	 * @param array<int,array<string,string>> $items Plugin update items.
	 * @param string                          $type  Source type.
	 * @return int
	 */
	private static function plugin_update_type_count( array $items, $type ) {
		$count = 0;
		foreach ( $items as $item ) {
			if ( isset( $item['type'] ) && $item['type'] === $type ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Return an update slug from a WordPress.org transient item.
	 *
	 * @param string $file   Plugin file.
	 * @param mixed  $update Update item.
	 * @return string
	 */
	private static function plugin_update_slug( $file, $update ) {
		if ( is_object( $update ) && ! empty( $update->slug ) ) {
			return sanitize_key( (string) $update->slug );
		}

		$dirname = dirname( (string) $file );
		if ( '.' !== $dirname && '' !== $dirname ) {
			return sanitize_key( $dirname );
		}

		return sanitize_key( basename( (string) $file, '.php' ) );
	}

	/**
	 * Return whether a plugin update is excluded from MCU updates.
	 *
	 * @param string $slug   Plugin slug.
	 * @param string $file   Plugin file.
	 * @param string $name   Plugin name.
	 * @param mixed  $update Update object.
	 * @return bool
	 */
	private static function is_plugin_update_excluded( $slug, $file = '', $name = '', $update = null ) {
		$candidates = array( $slug, $file, $name );

		if ( '' !== $file ) {
			$dirname = dirname( $file );
			if ( '.' !== $dirname && '' !== $dirname ) {
				$candidates[] = $dirname;
			}
			$candidates[] = basename( $file, '.php' );
		}

		if ( is_object( $update ) ) {
			if ( ! empty( $update->slug ) ) {
				$candidates[] = (string) $update->slug;
			}
			if ( ! empty( $update->plugin ) ) {
				$candidates[] = (string) $update->plugin;
			}
		}

		return self::is_plugin_candidate_excluded( $candidates );
	}

	/**
	 * Return whether a plugin slug is excluded from MCU updates.
	 *
	 * @param string $slug Plugin slug.
	 * @return bool
	 */
	private static function is_plugin_excluded( $slug ) {
		return self::is_plugin_candidate_excluded( array( $slug ) );
	}

	/**
	 * Return whether any plugin candidate matches an excluded plugin entry.
	 *
	 * @param array<int,string> $candidates Possible plugin identifiers.
	 * @return bool
	 */
	private static function is_plugin_candidate_excluded( array $candidates ) {
		$excluded = get_option( 'marrison_excluded_plugins', array() );
		if ( ! is_array( $excluded ) ) {
			return false;
		}

		$excluded_keys = array();
		foreach ( $excluded as $excluded_slug ) {
			foreach ( self::plugin_identifier_keys( (string) $excluded_slug ) as $key ) {
				$excluded_keys[ $key ] = true;
			}
		}

		foreach ( $candidates as $candidate ) {
			foreach ( self::plugin_identifier_keys( (string) $candidate ) as $key ) {
				if ( isset( $excluded_keys[ $key ] ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Build comparison keys for plugin slugs, names and files.
	 *
	 * @param string $identifier Plugin identifier.
	 * @return array<int,string>
	 */
	private static function plugin_identifier_keys( $identifier ) {
		$identifier = trim( (string) $identifier );
		if ( '' === $identifier ) {
			return array();
		}

		$parts = array( $identifier, sanitize_key( $identifier ), self::normalize_plugin_match_key( $identifier ) );
		if ( false !== strpos( $identifier, '/' ) || false !== strpos( $identifier, '\\' ) ) {
			$normalized = str_replace( '\\', '/', $identifier );
			$dirname    = dirname( $normalized );
			if ( '.' !== $dirname && '' !== $dirname ) {
				$parts[] = $dirname;
			}
			$parts[] = basename( $normalized, '.php' );
		}

		$keys = array();
		foreach ( $parts as $part ) {
			$part = trim( (string) $part );
			if ( '' === $part ) {
				continue;
			}
			$keys[] = $part;
			$keys[] = sanitize_key( $part );
			$keys[] = self::normalize_plugin_match_key( $part );
		}

		return array_values( array_unique( array_filter( $keys ) ) );
	}

	/**
	 * Find an installed plugin file using the same matching strategy as MCU.
	 *
	 * @param string                            $slug    Plugin slug.
	 * @param string                            $name    Plugin name.
	 * @param array<string,array<string,string>> $plugins Installed plugins.
	 * @return string
	 */
	private static function find_plugin_file_for_update( $slug, $name, array $plugins ) {
		$normalized_slug = self::normalize_plugin_match_key( $slug );
		foreach ( $plugins as $file => $data ) {
			if ( dirname( $file ) === $slug || self::normalize_plugin_match_key( dirname( $file ) ) === $normalized_slug ) {
				return (string) $file;
			}
		}

		if ( '' !== $name ) {
			$normalized_name = self::normalize_plugin_match_key( $name );
			foreach ( $plugins as $file => $data ) {
				if ( isset( $data['Name'] ) && ( $data['Name'] === $name || self::normalize_plugin_match_key( $data['Name'] ) === $normalized_name ) ) {
					return (string) $file;
				}
			}
		}

		foreach ( $plugins as $file => $data ) {
			if ( 0 === strpos( $file, $slug . '/' ) || $file === $slug . '.php' ) {
				return (string) $file;
			}
		}

		return '';
	}

	/**
	 * Normalize plugin names and slugs only for duplicate matching.
	 *
	 * @param string $value Raw plugin name or slug.
	 * @return string
	 */
	private static function normalize_plugin_match_key( $value ) {
		$normalized = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $value ) );
		return is_string( $normalized ) ? $normalized : '';
	}

	/**
	 * Return the configured MCU automatic update frequency.
	 *
	 * @return string
	 */
	private static function mcu_update_frequency() {
		$frequency = sanitize_key( (string) get_option( 'marrison_auto_update_frequency', 'daily' ) );
		return '' !== $frequency ? $frequency : 'daily';
	}

	/**
	 * Return the configured calendar day for monthly/biannual updates.
	 *
	 * @return int
	 */
	private static function mcu_update_month_day() {
		$day = absint( get_option( 'marrison_auto_update_month_day', wp_date( 'j' ) ) );
		return max( 1, min( 31, $day ) );
	}

	/**
	 * Return the next automatic update timestamp, supporting the new automatic-arg event and legacy events.
	 *
	 * @return int
	 */
	private static function next_scheduled_update_run() {
		$automatic = wp_next_scheduled( 'marrison_scheduled_update_event', array( 'automatic' ) );
		$legacy    = wp_next_scheduled( 'marrison_scheduled_update_event' );
		$scheduled = array_filter( array( (int) $automatic, (int) $legacy ) );

		// wp_next_scheduled() only finds an event when its arguments match exactly.
		// Include events left by older MCU versions or normalized by cron managers.
		if ( function_exists( '_get_cron_array' ) ) {
			$cron = _get_cron_array();
			foreach ( is_array( $cron ) ? $cron : array() as $timestamp => $hooks ) {
				if ( isset( $hooks['marrison_scheduled_update_event'] ) ) {
					$scheduled[] = (int) $timestamp;
				}
			}
		}

		return $scheduled ? min( $scheduled ) : 0;
	}

	/**
	 * Return a human-readable MCU automatic update frequency label.
	 *
	 * @param string $frequency Frequency slug.
	 * @return string
	 */
	private static function mcu_update_frequency_label( $frequency ) {
		$labels = array(
			'daily'    => __( 'Giornaliera', 'marrison-custom-updater' ),
			'weekly'   => __( 'Settimanale', 'marrison-custom-updater' ),
			'monthly'  => __( 'Mensile', 'marrison-custom-updater' ),
			'biannual' => __( 'Semestrale', 'marrison-custom-updater' ),
		);

		$label = isset( $labels[ $frequency ] ) ? $labels[ $frequency ] : (string) $frequency;
		if ( in_array( $frequency, array( 'monthly', 'biannual' ), true ) ) {
			$label .= ' - ' . sprintf(
				__( 'giorno %d', 'marrison-custom-updater' ),
				self::mcu_update_month_day()
			);
		}

		return $label;
	}

	/**
	 * Return the last MCU update execution timestamp.
	 *
	 * @return int
	 */
	private static function mcu_last_update_timestamp() {
		$last_cron_log = get_option( 'marrison_last_cron_log', array() );
		if ( is_array( $last_cron_log ) && ! empty( $last_cron_log['time'] ) ) {
			$timestamp = self::local_mysql_to_timestamp( (string) $last_cron_log['time'] );
			if ( $timestamp > 0 ) {
				return $timestamp;
			}
		}

		$last_plugin_update = get_option( 'marrison_last_plugins_update_time', '' );
		if ( '' !== $last_plugin_update ) {
			return self::local_mysql_to_timestamp( (string) $last_plugin_update );
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
	 * Count update response entries.
	 *
	 * @param mixed $transient Update transient.
	 * @return int
	 */
	private static function object_response_count( $transient ) {
		if ( is_object( $transient ) && isset( $transient->response ) && is_array( $transient->response ) ) {
			return count( $transient->response );
		}

		return 0;
	}

	/**
	 * Count public and authorized private theme updates.
	 *
	 * Private theme metadata is cached separately by MCU and is not always
	 * injected into the WordPress theme transient during a REST request.
	 *
	 * @param mixed $transient Update transient.
	 * @return int
	 */
	private static function theme_update_count( $transient ) {
		$count = self::object_response_count( $transient );
		if ( ! Settings::repository_config_managed() ) {
			return $count;
		}

		$private_updates = get_transient( 'marrison_available_theme_updates' );
		if ( ! is_array( $private_updates ) ) {
			return $count;
		}

		$seen = array();
		if ( is_object( $transient ) && isset( $transient->response ) && is_array( $transient->response ) ) {
			foreach ( $transient->response as $slug => $update ) {
				$seen[ sanitize_key( (string) $slug ) ] = true;
			}
		}

		$excluded = get_option( 'marrison_excluded_themes', array() );
		$excluded = is_array( $excluded ) ? array_map( 'sanitize_key', $excluded ) : array();
		$themes   = function_exists( 'wp_get_themes' ) ? wp_get_themes() : array();

		foreach ( $private_updates as $update ) {
			if ( ! is_array( $update ) || empty( $update['slug'] ) || empty( $update['version'] ) ) {
				continue;
			}

			$slug = sanitize_key( (string) $update['slug'] );
			if ( '' === $slug || in_array( $slug, $excluded, true ) ) {
				continue;
			}

			$theme = wp_get_theme( $slug );
			if ( ! $theme->exists() && ! empty( $update['name'] ) ) {
				foreach ( $themes as $theme_slug => $theme_object ) {
					if (
						strcasecmp( (string) $theme_object->get( 'Name' ), (string) $update['name'] ) === 0
						|| (string) $theme_object->get( 'TextDomain' ) === $slug
					) {
						$theme      = $theme_object;
						$slug       = sanitize_key( (string) $theme_slug );
						break;
					}
				}
			}

			if (
				! $theme->exists()
				|| isset( $seen[ $slug ] )
				|| ! version_compare( (string) $theme->get( 'Version' ), (string) $update['version'], '<' )
			) {
				continue;
			}

			$seen[ $slug ] = true;
			$count++;
		}

		return $count;
	}

	/**
	 * Count core updates that require an upgrade.
	 *
	 * @param mixed $transient Core update transient.
	 * @return int
	 */
	private static function core_update_count( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->updates ) || ! is_array( $transient->updates ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $transient->updates as $update ) {
			if ( is_object( $update ) && isset( $update->response ) && 'upgrade' === $update->response ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Count translation updates in an update transient.
	 *
	 * @param mixed $transient Update transient.
	 * @return int
	 */
	private static function translation_update_count( $plugin_updates, $theme_updates, $core_updates ) {
		$translation_file = ABSPATH . 'wp-admin/includes/translation-install.php';
		if ( ! function_exists( 'wp_get_translation_updates' ) && file_exists( $translation_file ) ) {
			require_once $translation_file;
		}

		if ( function_exists( 'wp_get_translation_updates' ) ) {
			$updates = wp_get_translation_updates();
			if ( is_array( $updates ) ) {
				return count( $updates );
			}
		}

		$count = 0;
		foreach ( array( $plugin_updates, $theme_updates, $core_updates ) as $transient ) {
			if ( is_object( $transient ) && isset( $transient->translations ) && is_array( $transient->translations ) ) {
				$count += count( $transient->translations );
			}
		}

		return $count;
	}

	/**
	 * Read last_checked from a transient.
	 *
	 * @param mixed $transient Update transient.
	 * @return int
	 */
	private static function transient_last_checked( $transient ) {
		if ( is_object( $transient ) && isset( $transient->last_checked ) ) {
			return (int) $transient->last_checked;
		}

		return 0;
	}

	/**
	 * Return safe server software value.
	 *
	 * @return string
	 */
	private static function server_software() {
		if ( empty( $_SERVER['SERVER_SOFTWARE'] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) );
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
