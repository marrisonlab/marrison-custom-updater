<?php
/**
 * Maintenance client REST rate limiting.
 *
 * @package MarrisonCustomUpdater
 */

namespace MarrisonCustomUpdater\MaintenanceClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small transient-backed limiter for the status endpoint.
 */
final class Rate_Limiter {
	const WINDOW_SECONDS = 60;
	const MAX_REQUESTS   = 30;

	/**
	 * Check and increment the current IP bucket.
	 *
	 * @param string $ip Request IP.
	 * @return bool
	 */
	public static function allow( $ip ) {
		$key   = 'mcu_client_rate_' . hash( 'sha256', (string) $ip );
		$count = get_transient( $key );

		if ( false === $count ) {
			set_transient( $key, 1, self::WINDOW_SECONDS );
			return true;
		}

		$count = (int) $count;
		if ( $count >= self::MAX_REQUESTS ) {
			return false;
		}

		set_transient( $key, $count + 1, self::WINDOW_SECONDS );
		return true;
	}
}
