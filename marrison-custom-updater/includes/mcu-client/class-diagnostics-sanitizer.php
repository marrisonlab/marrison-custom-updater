<?php
/**
 * Diagnostics sanitizer.
 *
 * @package MarrisonCustomUpdater
 */

namespace MarrisonCustomUpdater\MaintenanceClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sanitizes diagnostic data before storage or transport.
 */
final class Diagnostics_Sanitizer {
	const MAX_DEPTH        = 8;
	const MAX_ITEMS        = 250;
	const MAX_STRING_BYTES = 4000;

	/**
	 * Sanitize arbitrary values.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $depth Recursion depth.
	 * @return mixed
	 */
	public static function sanitize( $value, $depth = 0 ) {
		if ( $depth > self::MAX_DEPTH ) {
			return '[max-depth]';
		}

		if ( is_array( $value ) ) {
			$safe  = array();
			$count = 0;
			foreach ( $value as $key => $item ) {
				if ( $count >= self::MAX_ITEMS ) {
					$safe['_truncated'] = true;
					break;
				}
				$key_string = is_string( $key ) ? self::safe_key( $key ) : (string) $key;
				$safe[ $key_string ] = self::is_sensitive_key( $key_string ) ? '[redacted]' : self::sanitize( $item, $depth + 1 );
				$count++;
			}
			return $safe;
		}

		if ( is_object( $value ) ) {
			if ( $value instanceof \WP_Error ) {
				return array(
					'error_code'    => sanitize_key( $value->get_error_code() ),
					'error_message' => self::sanitize_text( $value->get_error_message() ),
				);
			}
			return self::sanitize( get_object_vars( $value ), $depth + 1 );
		}

		if ( is_string( $value ) ) {
			return self::sanitize_text( $value );
		}

		if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) || null === $value ) {
			return $value;
		}

		return '[unsupported]';
	}

	/**
	 * Encode sanitized JSON.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function encode_json( $value ) {
		$json = wp_json_encode( self::sanitize( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : '{}';
	}

	/**
	 * Compute checksum for sanitized data.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function checksum( $value ) {
		return hash( 'sha256', self::encode_json( $value ) );
	}

	/**
	 * Sanitize text.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function sanitize_text( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $text );
		$text = preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[redacted-email]', (string) $text );
		$text = preg_replace( '/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '[redacted-ip]', (string) $text );
		$text = preg_replace( '/(sha256=)[a-f0-9]{64}/i', '$1[redacted]', (string) $text );
		$text = preg_replace( '/([?&](?:token|secret|nonce|password|signature|auth|license|api_key)[^=]*=)[^&\s]+/i', '$1[redacted]', (string) $text );
		$text = self::relative_paths( (string) $text );

		if ( strlen( (string) $text ) > self::MAX_STRING_BYTES ) {
			$text = substr( (string) $text, 0, self::MAX_STRING_BYTES ) . '[truncated]';
		}

		return (string) $text;
	}

	/**
	 * Safe key.
	 *
	 * @param string $key Raw key.
	 * @return string
	 */
	public static function safe_key( $key ) {
		$key = preg_replace( '/[^a-zA-Z0-9_\-:.]/', '_', (string) $key );
		return trim( (string) $key, '_' );
	}

	/**
	 * Return whether a key is sensitive.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	private static function is_sensitive_key( $key ) {
		return (bool) preg_match( '/(^|[_:-])(password|passwd|secret|authorization|cookie|nonce|smtp|api[_-]?key|consumer[_-]?key|consumer[_-]?secret|private[_-]?key|access[_-]?token|refresh[_-]?token|license[_-]?key|webhook[_-]?secret)([_:-]|$)/i', (string) $key );
	}

	/**
	 * Convert known absolute paths to relative labels.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function relative_paths( $text ) {
		$roots = array(
			'ABSPATH'        => defined( 'ABSPATH' ) ? ABSPATH : '',
			'WP_CONTENT_DIR' => defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '',
			'WP_PLUGIN_DIR'  => defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '',
		);

		foreach ( $roots as $label => $root ) {
			$root = is_string( $root ) ? wp_normalize_path( $root ) : '';
			if ( '' === $root ) {
				continue;
			}
			$normalized = wp_normalize_path( $text );
			if ( 0 === strpos( $normalized, $root ) ) {
				return $label . '/' . ltrim( substr( $normalized, strlen( $root ) ), '/' );
			}
		}

		return $text;
	}
}
