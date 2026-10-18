<?php
/** Daily update metadata verification; installation has its own schedule. */
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
		return 'yes' === get_option( 'mcu_update_verification_enabled', 'yes' );
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
		if ( ! Settings::repository_config_managed() || ! self::enabled() ) {
			self::clear();
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( self::next_run(), self::HOOK );
		}
	}

	public static function clear() {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::RETRY_HOOK );
	}

	public static function run() {
		if ( ! Settings::repository_config_managed() || ! self::enabled() ) { self::clear(); return; }
		// Arrange tomorrow before network work, including when PHP is interrupted.
		self::ensure_scheduled();
		if ( ! wp_next_scheduled( self::RETRY_HOOK ) ) {
			wp_schedule_single_event( time() + 15 * MINUTE_IN_SECONDS, self::RETRY_HOOK );
		}
		$result = Actions_Controller::force_update_sync( 'scheduled' );
		if ( ! empty( $result['success'] ) ) {
			wp_clear_scheduled_hook( self::RETRY_HOOK );
		}
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Permessi insufficienti.' ); }
		check_admin_referer( 'mcu_save_update_verification' );
		if ( ! Settings::repository_config_managed() ) { wp_die( 'Client MCU non autorizzato.' ); }
		$value = isset( $_POST['mcu_update_verification_time'] ) && is_string( $_POST['mcu_update_verification_time'] )
			? wp_unslash( $_POST['mcu_update_verification_time'] ) : '';
		if ( ! preg_match( '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $value ) ) { wp_die( 'Orario non valido.' ); }
		update_option( 'mcu_update_verification_time', $value, false );
		update_option( 'mcu_update_verification_enabled', isset( $_POST['mcu_update_verification_enabled'] ) ? 'yes' : 'no', false );
		self::clear();
		self::ensure_scheduled();
		wp_safe_redirect( admin_url( 'admin.php?page=marrison-updater-settings&tab=general&settings-updated=saved' ) );
		exit;
	}

	public static function render_settings() {
		$state = get_option( 'mcu_update_sync_status', array() );
		$state = is_array( $state ) ? $state : array();
		$next = wp_next_scheduled( self::HOOK );
		$retry = wp_next_scheduled( self::RETRY_HOOK );
		?>
		<div class="mcu-card">
			<div class="mcu-card-header"><h2 class="mcu-card-title">Verifica aggiornamenti</h2></div>
			<p>Ricerca ogni giorno gli aggiornamenti disponibili con sincronizzazione forzata di WordPress, plugin, temi e traduzioni.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'mcu_save_update_verification' ); ?>
				<input type="hidden" name="action" value="mcu_save_update_verification">
				<table class="form-table"><tr><th><label for="mcu-update-verification-enabled">Verifica giornaliera</label></th><td>
					<label class="mcu-switch"><input id="mcu-update-verification-enabled" type="checkbox" name="mcu_update_verification_enabled" value="yes" <?php checked( self::enabled() ); ?>><span class="mcu-slider"></span></label>
				</td></tr><tr><th><label for="mcu-update-verification-time">Orario (Europe/Rome)</label></th><td>
					<input id="mcu-update-verification-time" type="time" required name="mcu_update_verification_time" value="<?php echo esc_attr( self::time() ); ?>">
				</td></tr></table>
				<p class="description">Sui siti poco visitati configura il cron dell'hosting per eseguire wp-cron.php ogni 5 minuti, oppure usa il monitor pianificato di Commander.</p>
				<?php submit_button( 'Salva verifica aggiornamenti' ); ?>
			</form>
			<?php foreach ( array( 'Ultimo tentativo' => isset( $state['attempted_at'] ) ? $state['attempted_at'] : 0, 'Ultima sincronizzazione riuscita' => isset( $state['last_success_at'] ) ? $state['last_success_at'] : 0, 'Prossima verifica' => $next, 'Nuovo tentativo' => $retry ) as $label => $timestamp ) : ?>
				<p><strong><?php echo esc_html( $label ); ?>:</strong> <?php echo esc_html( $timestamp ? wp_date( 'd/m/Y H:i', $timestamp, new \DateTimeZone( 'Europe/Rome' ) ) : '—' ); ?></p>
			<?php endforeach; ?>
			<?php if ( ! empty( $state['message'] ) ) : ?><p><?php echo esc_html( $state['message'] ); ?></p><?php endif; ?>
		</div>
		<?php
	}
}
