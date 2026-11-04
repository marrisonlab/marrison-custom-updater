<?php
/** Compatibility cleanup for retired automatic Maintenance verification. */
namespace MarrisonCustomUpdater\MaintenanceClient;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Update_Verification {
	const HOOK = 'mcu_update_verification';
	const RETRY_HOOK = 'mcu_update_verification_retry';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ), 20 );
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( self::RETRY_HOOK, array( __CLASS__, 'run' ) );
		add_action( 'admin_post_mcu_save_update_verification', array( __CLASS__, 'save' ) );
	}

	public static function time() {
		$value = (string) get_option( 'mcu_update_verification_time', '03:00' );
		return preg_match( '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $value ) ? $value : '03:00';
	}

	public static function enabled() {
		// Saved settings cannot re-enable autonomous Maintenance checks.
		return false;
	}

	public static function next_run( $now = null ) {
		$now = null === $now ? time() : (int) $now;
		$local = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( new \DateTimeZone( 'Europe/Rome' ) );
		list( $hour, $minute ) = array_map( 'intval', explode( ':', self::time() ) );
		$next = $local->setTime( $hour, $minute );
		if ( $next->getTimestamp() <= $now ) { $next = $next->modify( '+1 day' )->setTime( $hour, $minute ); }
		return $next->getTimestamp();
	}

	public static function ensure_scheduled() {
		self::clear();
	}

	public static function clear() {
		if ( wp_next_scheduled( self::HOOK ) ) { wp_clear_scheduled_hook( self::HOOK ); }
		if ( wp_next_scheduled( self::RETRY_HOOK ) ) { wp_clear_scheduled_hook( self::RETRY_HOOK ); }
	}

	public static function run() {
		// An old WP-Cron event must never start a forced synchronization.
		self::clear();
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Permessi insufficienti.' ); }
		check_admin_referer( 'mcu_save_update_verification' );
		if ( ! Settings::repository_config_managed() ) { wp_die( 'Client MCU non autorizzato.' ); }
		update_option( 'mcu_update_verification_enabled', 'no', false );
		self::clear();
		self::ensure_scheduled();
		wp_safe_redirect( admin_url( 'admin.php?page=marrison-updater-settings&tab=general&settings-updated=saved' ) );
		exit;
	}

	public static function render_settings() {
		?>
		<div class="mcu-card">
			<div class="mcu-card-header"><h2 class="mcu-card-title">Verifica aggiornamenti</h2></div>
			<p>Le verifiche Maintenance si eseguono su richiesta. La verifica giornaliera autonoma e i suoi tentativi automatici sono disattivati.</p>
		</div>
		<?php
	}
}
