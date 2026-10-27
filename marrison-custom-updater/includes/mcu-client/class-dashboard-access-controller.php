<?php
/**
 * Maintenance client dashboard one-click access.
 *
 * @package MarrisonCustomUpdater
 */

namespace MarrisonCustomUpdater\MaintenanceClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Issues short-lived dashboard login links requested by the Master.
 */
final class Dashboard_Access_Controller {
	const REST_ROUTE          = '/dashboard-access';
	const CANONICAL_REST_PATH = '/marrison-maintenance/v1/dashboard-access';
	const LOGIN_FLAG          = 'mcu_dashboard_access';
	const TOKEN_PARAM         = 'token';
	const TRANSIENT_PREFIX    = 'mcu_dashboard_access_';
	const TOKEN_TTL           = 60;

	/**
	 * Register runtime hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'login_init', array( __CLASS__, 'maybe_handle_login' ), 1 );
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			Rest_Controller::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_login_url' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Create a short-lived one-time dashboard login URL.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function create_login_url( \WP_REST_Request $request ) {
		$auth = self::authenticate( $request );
		if ( empty( $auth['ok'] ) ) {
			return new \WP_Error(
				isset( $auth['public_code'] ) ? $auth['public_code'] : 'mcu_dashboard_access_denied',
				__( 'Dashboard access authentication failed.', 'marrison-custom-updater' ),
				array( 'status' => isset( $auth['status'] ) ? (int) $auth['status'] : 401 )
			);
		}

		if ( ! Settings::dashboard_access_enabled() ) {
			Settings::record_dashboard_access_request( self::request_ip(), 'disabled' );
			return new \WP_Error(
				'mcu_dashboard_access_disabled',
				__( 'Dashboard access is disabled on this Client.', 'marrison-custom-updater' ),
				array( 'status' => 403 )
			);
		}

		$user_id = Settings::dashboard_access_user_id();
		$user    = $user_id > 0 ? get_user_by( 'id', $user_id ) : false;
		if ( ! $user || ! user_can( $user_id, 'manage_options' ) ) {
			Settings::record_dashboard_access_request( self::request_ip(), 'missing_admin_user' );
			return new \WP_Error(
				'mcu_dashboard_access_user_unavailable',
				__( 'Dashboard access user is not available.', 'marrison-custom-updater' ),
				array( 'status' => 403 )
			);
		}

		$token = self::generate_token();
		set_transient(
			self::token_transient_key( $token ),
			array(
				'user_id'      => (int) $user_id,
				'requested_at' => time(),
				'master_url'   => esc_url_raw( (string) $request->get_param( 'master_url' ) ),
				'site_id'      => sanitize_text_field( (string) self::header( $request, 'x-marrison-site-id' ) ),
			),
			self::TOKEN_TTL
		);

		Settings::record_dashboard_access_request( self::request_ip(), 'link_issued' );

		$login_url = add_query_arg(
			array(
				self::LOGIN_FLAG  => '1',
				self::TOKEN_PARAM => $token,
			),
			wp_login_url()
		);

		return new \WP_REST_Response(
			array(
				'success'    => true,
				'login_url'  => $login_url,
				'expires_in' => self::TOKEN_TTL,
			),
			200
		);
	}

	/**
	 * Consume a dashboard access token and log the configured admin user in.
	 *
	 * @return void
	 */
	public static function maybe_handle_login() {
		if ( empty( $_GET[ self::LOGIN_FLAG ] ) ) {
			return;
		}

		$token = isset( $_GET[ self::TOKEN_PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::TOKEN_PARAM ] ) ) : '';
		if ( '' === $token ) {
			Settings::record_dashboard_access_request( self::request_ip(), 'missing_login_token' );
			self::deny_login( __( 'Link dashboard non valido.', 'marrison-custom-updater' ) );
		}

		$key  = self::token_transient_key( $token );
		$data = get_transient( $key );
		delete_transient( $key );

		if ( ! is_array( $data ) || empty( $data['user_id'] ) ) {
			Settings::record_dashboard_access_request( self::request_ip(), 'login_token_invalid' );
			self::deny_login( __( 'Link dashboard scaduto o gia usato.', 'marrison-custom-updater' ) );
		}

		if ( ! Settings::dashboard_access_enabled() ) {
			Settings::record_dashboard_access_request( self::request_ip(), 'login_disabled' );
			self::deny_login( __( 'Accesso dashboard disabilitato.', 'marrison-custom-updater' ) );
		}

		$user_id = absint( $data['user_id'] );
		$user    = get_user_by( 'id', $user_id );
		if ( ! $user || ! user_can( $user_id, 'manage_options' ) ) {
			Settings::record_dashboard_access_request( self::request_ip(), 'login_user_unavailable' );
			self::deny_login( __( 'Utente dashboard non disponibile.', 'marrison-custom-updater' ) );
		}

		wp_clear_auth_cookie();
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, false, is_ssl() );
		do_action( 'wp_login', $user->user_login, $user );

		Settings::record_dashboard_access_request( self::request_ip(), 'login_success' );

		wp_safe_redirect( admin_url() );
		exit;
	}

	/**
	 * Authenticate the Master request with the dashboard access key.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return array<string,mixed>
	 */
	private static function authenticate( \WP_REST_Request $request ) {
		$ip      = self::request_ip();
		$site_id = self::header( $request, 'x-marrison-site-id' );

		if ( ! Rate_Limiter::allow( $ip ) ) {
			Settings::record_dashboard_access_request( $ip, 'rate_limited' );
			return self::fail( 'mcu_dashboard_access_rate_limited', 429, 'rate_limited', $site_id );
		}

		$timestamp = self::header( $request, 'x-marrison-timestamp' );
		$nonce     = self::header( $request, 'x-marrison-nonce' );
		$signature = self::header( $request, 'x-marrison-signature' );

		if ( '' === $timestamp || '' === $nonce || '' === $signature ) {
			Settings::record_dashboard_access_request( $ip, 'missing_headers' );
			return self::fail( 'mcu_dashboard_access_auth_failed', 401, 'missing_headers', $site_id );
		}

		if ( ! ctype_digit( (string) $timestamp ) || abs( time() - (int) $timestamp ) > Authenticator::TIMESTAMP_TOLERANCE ) {
			Settings::record_dashboard_access_request( $ip, 'timestamp_expired' );
			return self::fail( 'mcu_dashboard_access_timestamp_expired', 401, 'timestamp_expired', $site_id );
		}

		if ( ! preg_match( '/^[A-Za-z0-9._:-]{12,128}$/', (string) $nonce ) ) {
			Settings::record_dashboard_access_request( $ip, 'invalid_nonce' );
			return self::fail( 'mcu_dashboard_access_auth_failed', 401, 'invalid_nonce', $site_id );
		}

		$nonce_key = 'mcu_dashboard_access_nonce_' . hash( 'sha256', (string) $nonce );
		if ( false !== get_transient( $nonce_key ) ) {
			Settings::record_dashboard_access_request( $ip, 'nonce_reused' );
			return self::fail( 'mcu_dashboard_access_nonce_reused', 401, 'nonce_reused', $site_id );
		}

		$provided = self::normalize_signature( $signature );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $provided ) ) {
			Settings::record_dashboard_access_request( $ip, 'invalid_signature_format' );
			return self::fail( 'mcu_dashboard_access_auth_failed', 401, 'invalid_signature_format', $site_id );
		}

		$canonical = Authenticator::canonical_string(
			strtoupper( $request->get_method() ),
			self::CANONICAL_REST_PATH,
			(string) $timestamp,
			(string) $nonce,
			(string) $request->get_body()
		);

		$expected = hash_hmac( 'sha256', $canonical, Settings::get_dashboard_access_key() );
		if ( ! hash_equals( $expected, $provided ) ) {
			Settings::record_dashboard_access_request( $ip, 'signature_rejected' );
			return self::fail( 'mcu_dashboard_access_signature_rejected', 403, 'signature_rejected', $site_id );
		}

		set_transient( $nonce_key, 1, Authenticator::TIMESTAMP_TOLERANCE );
		Settings::record_dashboard_access_request( $ip, 'success' );

		return array(
			'ok'      => true,
			'site_id' => sanitize_text_field( (string) $site_id ),
		);
	}

	/**
	 * Generate a token for the browser login handoff.
	 *
	 * @return string
	 */
	private static function generate_token() {
		if ( function_exists( 'random_bytes' ) ) {
			try {
				return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
			} catch ( \Exception $exception ) {
				// Fall back to WordPress below.
			}
		}

		return wp_generate_password( 48, false, false );
	}

	/**
	 * Stop a dashboard login handoff with a 403 response.
	 *
	 * @param string $message Public message.
	 * @return void
	 */
	private static function deny_login( $message ) {
		wp_die(
			esc_html( (string) $message ),
			esc_html__( 'Accesso dashboard negato', 'marrison-custom-updater' ),
			array( 'response' => 403 )
		);
	}

	/**
	 * Return the transient key for a raw login token.
	 *
	 * @param string $token Raw token.
	 * @return string
	 */
	private static function token_transient_key( $token ) {
		return self::TRANSIENT_PREFIX . hash( 'sha256', (string) $token );
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
