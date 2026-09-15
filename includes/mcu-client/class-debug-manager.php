<?php
/**
 * Remote WordPress debug management.
 *
 * @package MarrisonCustomUpdater
 */

namespace MarrisonCustomUpdater\MaintenanceClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides Commander-only WP debug operations.
 */
final class Debug_Manager {
	const OPTION_LAST_CONFIG_BACKUP = 'mcu_remote_debug_last_config_backup';
	const CONFIG_BLOCK_START        = '// BEGIN Marrison Commander Debug';
	const CONFIG_BLOCK_END          = '// END Marrison Commander Debug';
	const VIEW_MAX_BYTES            = 524288;
	const AI_MAX_BYTES              = 131072;
	const DOWNLOAD_MAX_BYTES        = 2097152;

	/**
	 * Return lightweight debug status metadata.
	 *
	 * @return array<string,mixed>
	 */
	public static function status_summary() {
		$path        = self::debug_log_path();
		$config_path = self::wp_config_path();
		$exists      = '' !== $path && is_file( $path );
		$readable    = $exists && is_readable( $path );
		$size        = $exists ? (int) filesize( $path ) : 0;
		$modified    = $exists ? (int) filemtime( $path ) : 0;
		$directory   = '' !== $path ? dirname( $path ) : '';

		return array(
			'available'          => true,
			'enabled'            => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'log_enabled'        => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
			'display_enabled'    => defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY,
			'config_path'        => $config_path,
			'config_writable'    => '' !== $config_path && is_readable( $config_path ) && is_writable( $config_path ),
			'last_config_backup' => (string) get_option( self::OPTION_LAST_CONFIG_BACKUP, '' ),
			'log'                => array(
				'path'          => $path,
				'exists'        => $exists,
				'readable'      => $readable,
				'writable'      => $exists ? is_writable( $path ) : ( is_dir( $directory ) && is_writable( $directory ) ),
				'size_bytes'    => $size,
				'size_label'    => $size > 0 ? size_format( $size, 2 ) : __( '0 B', 'marrison-custom-updater' ),
				'modified_at'   => $modified,
				'modified_label'=> $modified > 0 ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $modified ) : __( 'mai', 'marrison-custom-updater' ),
			),
			'limits'             => array(
				'view_max_bytes'     => self::VIEW_MAX_BYTES,
				'ai_max_bytes'       => self::AI_MAX_BYTES,
				'download_max_bytes' => self::DOWNLOAD_MAX_BYTES,
			),
		);
	}

	/**
	 * Enable or disable WordPress debug constants in wp-config.php.
	 *
	 * @param bool $enabled Target debug state.
	 * @return array<string,mixed>
	 */
	public static function set_enabled( $enabled ) {
		$result = self::write_debug_config( (bool) $enabled );
		if ( empty( $result['success'] ) ) {
			$result['debug'] = self::status_summary();
			return $result;
		}

		$backup_cleanup = array();
		if ( ! $enabled ) {
			$backup_cleanup = self::prune_config_backups();
		}

		return array(
			'success'        => true,
			'message'        => $enabled
				? __( 'Debug WordPress attivato.', 'marrison-custom-updater' )
				: __( 'Debug WordPress disattivato.', 'marrison-custom-updater' ),
			'debug'          => self::status_summary(),
			'backup_cleanup' => $backup_cleanup,
		);
	}

	/**
	 * Delete debug.log when present.
	 *
	 * @return array<string,mixed>
	 */
	public static function delete_log() {
		$path = self::debug_log_path();
		if ( '' === $path || ! is_file( $path ) ) {
			return array(
				'success' => true,
				'message' => __( 'debug.log non presente.', 'marrison-custom-updater' ),
				'debug'   => self::status_summary(),
			);
		}

		if ( ! is_writable( $path ) && ! is_writable( dirname( $path ) ) ) {
			return array(
				'success'    => false,
				'error_code' => 'debug_log_not_writable',
				'message'    => __( 'debug.log non eliminabile dal processo PHP.', 'marrison-custom-updater' ),
				'debug'      => self::status_summary(),
			);
		}

		if ( ! @unlink( $path ) ) {
			return array(
				'success'    => false,
				'error_code' => 'debug_log_delete_failed',
				'message'    => __( 'Impossibile eliminare debug.log.', 'marrison-custom-updater' ),
				'debug'      => self::status_summary(),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'debug.log eliminato.', 'marrison-custom-updater' ),
			'debug'   => self::status_summary(),
		);
	}

	/**
	 * Read the tail of debug.log for Commander.
	 *
	 * @param array<string,mixed> $parameters Request parameters.
	 * @return array<string,mixed>
	 */
	public static function read_log( array $parameters = array() ) {
		$path    = self::debug_log_path();
		$purpose = isset( $parameters['purpose'] ) ? sanitize_key( (string) $parameters['purpose'] ) : 'view';
		$max     = self::VIEW_MAX_BYTES;

		if ( 'ai' === $purpose ) {
			$max = self::AI_MAX_BYTES;
		} elseif ( 'download' === $purpose ) {
			$max = self::DOWNLOAD_MAX_BYTES;
		}

		$requested = isset( $parameters['bytes'] ) ? absint( $parameters['bytes'] ) : $max;
		$bytes     = max( 1024, min( $requested, $max ) );
		$tail      = self::tail_file( $path, $bytes );
		$status    = self::status_summary();

		if ( empty( $tail['readable'] ) ) {
			return array(
				'success'    => false,
				'error_code' => 'debug_log_unavailable',
				'message'    => __( 'debug.log non disponibile o non leggibile.', 'marrison-custom-updater' ),
				'debug'      => $status,
			);
		}

		$content = (string) $tail['content'];

		return array(
			'success'        => true,
			'message'        => __( 'debug.log letto.', 'marrison-custom-updater' ),
			'purpose'        => $purpose,
			'encoding'       => 'base64',
			'content_base64' => base64_encode( $content ),
			'bytes_read'     => strlen( $content ),
			'truncated'      => ! empty( $tail['truncated'] ),
			'sha256'         => hash( 'sha256', $content ),
			'filename'       => 'debug-log-' . gmdate( 'Ymd-His' ) . '.log',
			'debug'          => $status,
		);
	}

	/**
	 * Write the required debug constants to wp-config.php.
	 *
	 * @param bool $enabled Target debug state.
	 * @return array<string,mixed>
	 */
	private static function write_debug_config( $enabled ) {
		$path = self::wp_config_path();
		if ( '' === $path ) {
			return array(
				'success'    => false,
				'error_code' => 'wp_config_not_found',
				'message'    => __( 'wp-config.php non trovato.', 'marrison-custom-updater' ),
			);
		}

		if ( ! is_readable( $path ) || ! is_writable( $path ) ) {
			return array(
				'success'    => false,
				'error_code' => 'wp_config_not_writable',
				'message'    => __( 'wp-config.php non e leggibile o scrivibile dal processo PHP.', 'marrison-custom-updater' ),
			);
		}

		$content = file_get_contents( $path );
		if ( false === $content ) {
			return array(
				'success'    => false,
				'error_code' => 'wp_config_read_failed',
				'message'    => __( 'Impossibile leggere wp-config.php.', 'marrison-custom-updater' ),
			);
		}

		$backup = $path . '.mcu-debug-backup-' . gmdate( 'YmdHis' );
		if ( ! @copy( $path, $backup ) ) {
			return array(
				'success'    => false,
				'error_code' => 'wp_config_backup_failed',
				'message'    => __( 'Impossibile creare il backup di wp-config.php. Cambio annullato.', 'marrison-custom-updater' ),
			);
		}

		$content = self::remove_managed_config_block( $content );
		$missing = array();
		$values  = array(
			'WP_DEBUG'         => $enabled ? 'true' : 'false',
			'WP_DEBUG_LOG'     => $enabled ? 'true' : 'false',
			'WP_DEBUG_DISPLAY' => 'false',
		);

		foreach ( $values as $constant => $value ) {
			$line     = "define( '" . $constant . "', " . $value . ' );';
			$replaced = self::replace_config_define( $content, $constant, $line );
			if ( ! $replaced && $enabled ) {
				$missing[] = $line;
			}
		}

		if ( $enabled && ! empty( $missing ) ) {
			$missing[] = "@ini_set( 'display_errors', 0 );";
			$block     = self::CONFIG_BLOCK_START . "\n" . implode( "\n", $missing ) . "\n" . self::CONFIG_BLOCK_END . "\n";
			$content   = self::insert_config_block( $content, $block );
		}

		$written = file_put_contents( $path, $content, LOCK_EX );
		if ( false === $written ) {
			return array(
				'success'    => false,
				'error_code' => 'wp_config_write_failed',
				'message'    => __( 'Impossibile scrivere wp-config.php. Backup creato ma modifica non applicata.', 'marrison-custom-updater' ),
			);
		}

		update_option( self::OPTION_LAST_CONFIG_BACKUP, $backup, false );

		return array(
			'success' => true,
			'message' => '',
		);
	}

	/**
	 * Keep only the newest wp-config.php debug backup.
	 *
	 * @return array<string,mixed>
	 */
	private static function prune_config_backups() {
		$config_path = self::wp_config_path();
		if ( '' === $config_path ) {
			return array(
				'deleted' => 0,
				'kept'    => '',
			);
		}

		$directory = dirname( $config_path );
		$basename  = basename( $config_path );
		$entries   = is_dir( $directory ) ? scandir( $directory ) : false;
		if ( ! is_array( $entries ) ) {
			return array(
				'deleted' => 0,
				'kept'    => '',
			);
		}

		$pattern = '/^' . preg_quote( $basename, '/' ) . '\.(?:mcu|marrison)-debug-backup-(\d{14})$/';
		$backups = array();
		foreach ( $entries as $entry ) {
			if ( ! is_string( $entry ) || ! preg_match( $pattern, $entry, $matches ) ) {
				continue;
			}

			$path = trailingslashit( $directory ) . $entry;
			if ( ! is_file( $path ) ) {
				continue;
			}

			$backups[] = array(
				'path'      => $path,
				'timestamp' => (string) $matches[1],
				'modified'  => (int) filemtime( $path ),
			);
		}

		if ( count( $backups ) <= 1 ) {
			$kept = ! empty( $backups[0]['path'] ) ? (string) $backups[0]['path'] : '';
			if ( '' !== $kept ) {
				update_option( self::OPTION_LAST_CONFIG_BACKUP, $kept, false );
			}

			return array(
				'deleted' => 0,
				'kept'    => $kept,
			);
		}

		usort(
			$backups,
			function ( $a, $b ) {
				$time_compare = strcmp( (string) $b['timestamp'], (string) $a['timestamp'] );
				if ( 0 !== $time_compare ) {
					return $time_compare;
				}

				return (int) $b['modified'] <=> (int) $a['modified'];
			}
		);

		$kept    = (string) $backups[0]['path'];
		$deleted = 0;
		foreach ( array_slice( $backups, 1 ) as $backup ) {
			$path = (string) $backup['path'];
			if ( @unlink( $path ) ) {
				$deleted++;
			}
		}

		update_option( self::OPTION_LAST_CONFIG_BACKUP, $kept, false );

		return array(
			'deleted' => $deleted,
			'kept'    => $kept,
		);
	}

	/**
	 * Replace one debug constant definition when already present.
	 *
	 * @param string $content     wp-config.php content.
	 * @param string $constant    Constant name.
	 * @param string $replacement Replacement line.
	 * @return bool Whether a definition was replaced.
	 */
	private static function replace_config_define( &$content, $constant, $replacement ) {
		$constant_pattern = preg_quote( (string) $constant, '/' );
		$patterns = array(
			'/^[\t ]*(?:defined\s*\(\s*[\'"]' . $constant_pattern . '[\'"]\s*\)\s*\|\|\s*)?define\s*\(\s*[\'"]' . $constant_pattern . '[\'"]\s*,\s*[^;]+;\s*(?:\/\/.*)?$/mi',
			'/^[\t ]*if\s*\(\s*!\s*defined\s*\(\s*[\'"]' . $constant_pattern . '[\'"]\s*\)\s*\)\s*\{\s*define\s*\(\s*[\'"]' . $constant_pattern . '[\'"]\s*,\s*[^;]+;\s*\}\s*(?:\/\/.*)?$/mi',
		);

		$total_count = 0;
		foreach ( $patterns as $pattern ) {
			$updated = preg_replace( $pattern, (string) $replacement, $content, -1, $count );
			if ( null !== $updated && $count > 0 ) {
				$content      = $updated;
				$total_count += $count;
			}
		}

		return $total_count > 0;
	}

	/**
	 * Remove the block previously inserted by this manager.
	 *
	 * @param string $content wp-config.php content.
	 * @return string
	 */
	private static function remove_managed_config_block( $content ) {
		$pattern = '/\R?' . preg_quote( self::CONFIG_BLOCK_START, '/' ) . '[\s\S]*?' . preg_quote( self::CONFIG_BLOCK_END, '/' ) . '\R?/m';
		$updated = preg_replace( $pattern, "\n", (string) $content );

		return is_string( $updated ) ? $updated : (string) $content;
	}

	/**
	 * Insert a managed config block before the normal wp-config.php anchor.
	 *
	 * @param string $content wp-config.php content.
	 * @param string $block   Managed block.
	 * @return string
	 */
	private static function insert_config_block( $content, $block ) {
		$anchors = array(
			"/* That's all, stop editing! Happy publishing. */",
			"/* That's all, stop editing! Happy blogging. */",
			"require_once ABSPATH . 'wp-settings.php';",
			"require_once(ABSPATH . 'wp-settings.php');",
		);

		foreach ( $anchors as $anchor ) {
			$position = strpos( (string) $content, $anchor );
			if ( false !== $position ) {
				return substr( (string) $content, 0, $position ) . $block . "\n" . substr( (string) $content, $position );
			}
		}

		return rtrim( (string) $content ) . "\n\n" . $block;
	}

	/**
	 * Locate wp-config.php.
	 *
	 * @return string
	 */
	private static function wp_config_path() {
		$candidates = array();
		if ( defined( 'ABSPATH' ) ) {
			$candidates[] = trailingslashit( ABSPATH ) . 'wp-config.php';
			$candidates[] = trailingslashit( dirname( ABSPATH ) ) . 'wp-config.php';
		}

		foreach ( $candidates as $candidate ) {
			if ( is_file( $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Return the active debug.log path.
	 *
	 * @return string
	 */
	private static function debug_log_path() {
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && '' !== WP_DEBUG_LOG ) {
			return (string) WP_DEBUG_LOG;
		}

		return trailingslashit( WP_CONTENT_DIR ) . 'debug.log';
	}

	/**
	 * Read the tail of a file.
	 *
	 * @param string $path  File path.
	 * @param int    $bytes Bytes to read.
	 * @return array<string,mixed>
	 */
	private static function tail_file( $path, $bytes ) {
		if ( '' === $path || ! is_readable( $path ) || ! is_file( $path ) ) {
			return array(
				'readable'  => false,
				'truncated' => false,
				'content'   => '',
			);
		}

		$size   = (int) filesize( $path );
		$offset = max( 0, $size - (int) $bytes );
		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) {
			return array(
				'readable'  => false,
				'truncated' => false,
				'content'   => '',
			);
		}

		if ( $offset > 0 ) {
			fseek( $handle, $offset );
		}

		$content = stream_get_contents( $handle );
		fclose( $handle );

		return array(
			'readable'  => true,
			'truncated' => $offset > 0,
			'content'   => is_string( $content ) ? $content : '',
		);
	}
}
