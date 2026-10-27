<?php
/**
 * Maintenance client debug logger.
 *
 * @package MarrisonCustomUpdater
 */

namespace MarrisonCustomUpdater\MaintenanceClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes bounded debug logs without sensitive values.
 */
final class Debug_Logger {
	const MAX_BYTES = 1048576;
	const MAX_FILES = 3;

	/**
	 * Log a debug event if enabled.
	 *
	 * @param string              $event   Event name.
	 * @param array<string,mixed> $context Context values.
	 * @return void
	 */
	public static function log( $event, array $context = array() ) {
		if ( ! Settings::debug_enabled() ) {
			return;
		}

		$path = self::log_path();
		if ( empty( $path ) ) {
			return;
		}

		self::rotate_if_needed( $path );

		$line = wp_json_encode(
			array(
				'time'    => gmdate( 'c' ),
				'event'   => sanitize_key( (string) $event ),
				'context' => self::sanitize_context( $context ),
			),
			JSON_UNESCAPED_SLASHES
		);

		if ( ! is_string( $line ) ) {
			return;
		}

		file_put_contents( $path, $line . PHP_EOL, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Return the log file path.
	 *
	 * @return string
	 */
	private static function log_path() {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		$dir = trailingslashit( $uploads['basedir'] ) . 'mcu-client';
		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, '<?php // Silence is golden' );
		}

		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			@file_put_contents( $htaccess, "deny from all\n" );
		}

		return trailingslashit( $dir ) . 'debug.log';
	}

	/**
	 * Rotate logs when the active file grows too large.
	 *
	 * @param string $path Log path.
	 * @return void
	 */
	private static function rotate_if_needed( $path ) {
		if ( ! file_exists( $path ) || filesize( $path ) < self::MAX_BYTES ) {
			return;
		}

		$oldest = $path . '.' . self::MAX_FILES;
		if ( file_exists( $oldest ) ) {
			unlink( $oldest );
		}

		for ( $i = self::MAX_FILES - 1; $i >= 1; $i-- ) {
			$old = $path . '.' . $i;
			$new = $path . '.' . ( $i + 1 );

			if ( file_exists( $old ) ) {
				rename( $old, $new );
			}
		}

		rename( $path, $path . '.1' );
	}

	/**
	 * Keep only diagnostic fields that are safe to persist.
	 *
	 * @param array<string,mixed> $context Raw context.
	 * @return array<string,mixed>
	 */
	private static function sanitize_context( array $context ) {
		$blocked = array( 'secret', 'key', 'signature', 'token', 'password', 'cookie', 'authorization' );
		$safe    = array();

		foreach ( $context as $key => $value ) {
			$lower = strtolower( (string) $key );
			foreach ( $blocked as $needle ) {
				if ( false !== strpos( $lower, $needle ) ) {
					continue 2;
				}
			}

			if ( is_scalar( $value ) || null === $value ) {
				$safe[ sanitize_key( (string) $key ) ] = sanitize_text_field( (string) $value );
			}
		}

		return $safe;
	}
}
