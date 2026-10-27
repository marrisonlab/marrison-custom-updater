<?php
/**
 * Maintenance client HMAC request authentication.
 *
 * @package MarrisonCustomUpdater
 */

namespace MarrisonCustomUpdater\MaintenanceClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates signed REST requests from a Marrison Maintenance Master site.
 */
final class Authenticator {
	const TIMESTAMP_TOLERANCE = 300;

	/**
	 * Authenticate a REST request.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return array<string,mixed>
	 */
	public static function authenticate( \WP_REST_Request $request ) {
		$ip      = self::request_ip();
		$site_id = self::header( $request, 'x-marrison-site-id' );

		if ( ! Rate_Limiter::allow( $ip ) ) {
			Settings::record_request( $ip, 'rate_limited' );
			return self::fail( 'marrison_rate_limited', 429, 'rate_limited', $site_id );
		}

		$timestamp = self::header( $request, 'x-marrison-timestamp' );
		$nonce     = self::header( $request, 'x-marrison-nonce' );
		$signature = self::header( $request, 'x-marrison-signature' );

		if ( '' === $timestamp || '' === $nonce || '' === $signature ) {
			Settings::record_request( $ip, 'missing_headers' );
			return self::fail( 'marrison_auth_failed', 401, 'missing_headers', $site_id );
		}

		if ( ! ctype_digit( (string) $timestamp ) ) {
			Settings::record_request( $ip, 'invalid_timestamp' );
			return self::fail( 'marrison_auth_failed', 401, 'invalid_timestamp', $site_id );
		}

		$timestamp_int = (int) $timestamp;
		if ( abs( time() - $timestamp_int ) > self::TIMESTAMP_TOLERANCE ) {
			Settings::record_request( $ip, 'timestamp_expired' );
			return self::fail( 'marrison_timestamp_expired', 401, 'timestamp_expired', $site_id );
		}

		if ( ! preg_match( '/^[A-Za-z0-9._:-]{12,128}$/', (string) $nonce ) ) {
			Settings::record_request( $ip, 'invalid_nonce' );
			return self::fail( 'marrison_auth_failed', 401, 'invalid_nonce', $site_id );
		}

		$nonce_key = 'mcu_client_nonce_' . hash( 'sha256', (string) $nonce );
		if ( false !== get_transient( $nonce_key ) ) {
			Settings::record_request( $ip, 'nonce_reused' );
			return self::fail( 'marrison_nonce_reused', 401, 'nonce_reused', $site_id );
		}

		$provided = self::normalize_signature( $signature );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $provided ) ) {
			Settings::record_request( $ip, 'invalid_signature_format' );
			return self::fail( 'marrison_auth_failed', 401, 'invalid_signature_format', $site_id );
		}

		$canonical = self::canonical_string(
			strtoupper( $request->get_method() ),
			self::canonical_rest_path( $request ),
			(string) $timestamp,
			(string) $nonce,
			(string) $request->get_body()
		);

		$expected = hash_hmac( 'sha256', $canonical, Settings::get_secret() );

		if ( ! hash_equals( $expected, $provided ) ) {
			Settings::record_request( $ip, 'signature_rejected' );
			return self::fail( 'marrison_signature_rejected', 403, 'signature_rejected', $site_id );
		}

		set_transient( $nonce_key, 1, self::TIMESTAMP_TOLERANCE );
		Settings::record_request( $ip, 'success' );

		return array(
			'ok'      => true,
			'site_id' => sanitize_text_field( (string) $site_id ),
		);
	}

	/**
	 * Create the canonical string used for signing.
	 *
	 * @param string $method    HTTP method.
	 * @param string $rest_path Canonical REST path.
	 * @param string $timestamp Unix timestamp.
	 * @param string $nonce     Random nonce.
	 * @param string $body      Raw request body.
	 * @return string
	 */
	public static function canonical_string( $method, $rest_path, $timestamp, $nonce, $body ) {
		return strtoupper( (string) $method ) . "\n" .
			(string) $rest_path . "\n" .
			(string) $timestamp . "\n" .
			(string) $nonce . "\n" .
			(string) $body;
	}

	/**
	 * Return the canonical REST path for the current authenticated endpoint.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return string
	 */
	private static function canonical_rest_path( \WP_REST_Request $request ) {
		$route = (string) $request->get_route();
		if ( class_exists( __NAMESPACE__ . '\\Debug_Log_Controller' ) && false !== strpos( $route, Debug_Log_Controller::CANONICAL_REST_PATH ) ) {
			return Debug_Log_Controller::CANONICAL_REST_PATH;
		}

		if ( false !== strpos( $route, Actions_Controller::CANONICAL_REST_PATH ) ) {
			return Actions_Controller::CANONICAL_REST_PATH;
		}

		return Rest_Controller::CANONICAL_REST_PATH;
	}

	/**
	 * Return a normalized header value.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @param string           $name    Header name.
	 * @return string
	 */
	private static function header( \WP_REST_Request $request, $name ) {
		$value = $request->get_header( $name );
		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Normalize a signature header.
	 *
	 * @param string $signature Signature header value.
	 * @return string
	 */
	private static function normalize_signature( $signature ) {
		$signature = strtolower( trim( (string) $signature ) );
		if ( 0 === strpos( $signature, 'sha256=' ) ) {
			$signature = substr( $signature, 7 );
		}

		return $signature;
	}

	/**
	 * Return the remote IP without trusting forwarded headers.
	 *
	 * @return string
	 */
	private static function request_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * Build a failure result.
	 *
	 * @param string $public_code Public WP_Error code.
	 * @param int    $status      HTTP status.
	 * @param string $reason      Internal reason.
	 * @param string $site_id     Site identifier.
	 * @return array<string,mixed>
	 */
	private static function fail( $public_code, $status, $reason, $site_id ) {
		return array(
			'ok'          => false,
			'public_code' => sanitize_key( $public_code ),
			'status'      => (int) $status,
			'reason'      => sanitize_key( $reason ),
			'site_id'     => sanitize_text_field( (string) $site_id ),
		);
	}
}
