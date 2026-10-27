<?php
/**
 * Remote debug log REST endpoint.
 *
 * @package MarrisonCustomUpdater
 */

namespace MarrisonCustomUpdater\MaintenanceClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves debug.log reads to authenticated Commander requests.
 */
final class Debug_Log_Controller {
	const REST_ROUTE          = '/debug-log';
	const CANONICAL_REST_PATH = '/marrison-maintenance/v1/debug-log';

	/**
	 * Register route.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			Rest_Controller::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'read_log' ),
				'permission_callback' => '__return_true',
				'args'                => array(),
			)
		);
	}

	/**
	 * Read debug.log through a signed Commander request.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function read_log( \WP_REST_Request $request ) {
		$site_id = (string) $request->get_header( 'x-marrison-site-id' );
		Debug_Logger::log( 'debug_log_read_start', array( 'site_id' => $site_id ) );

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

		$parameters = $request->get_param( 'parameters' );
		$parameters = is_array( $parameters ) ? $parameters : array();
		$result     = Debug_Manager::read_log( $parameters );

		Debug_Logger::log(
			'debug_log_read_end',
			array(
				'site_id' => $site_id,
				'success' => ! empty( $result['success'] ) ? '1' : '0',
				'bytes'   => isset( $result['bytes_read'] ) ? (string) (int) $result['bytes_read'] : '0',
			)
		);

		return rest_ensure_response( $result );
	}
}
