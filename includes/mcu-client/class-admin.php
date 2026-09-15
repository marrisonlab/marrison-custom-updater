<?php
/**
 * Maintenance client admin panel.
 *
 * @package MarrisonCustomUpdater
 */

namespace MarrisonCustomUpdater\MaintenanceClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders and handles the MCU Client settings panel.
 */
final class Admin {
	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( 'MarrisonCustomUpdater\MaintenanceClient\Settings', 'ensure_defaults' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_post_mcu_maintenance_client_download_config', array( __CLASS__, 'handle_download_config' ) );
		add_action( 'admin_post_mcu_maintenance_client_regenerate_secret', array( __CLASS__, 'handle_regenerate_secret' ) );
		add_action( 'admin_post_mcu_maintenance_client_regenerate_dashboard_access', array( __CLASS__, 'handle_regenerate_dashboard_access' ) );
		add_action( 'admin_post_mcu_maintenance_client_save_settings', array( __CLASS__, 'handle_save_settings' ) );
	}

	/**
	 * Enqueue client admin assets on the MCU settings page.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		$is_settings_page          = false !== strpos( (string) $hook_suffix, 'marrison-updater-settings' );
		$is_disconnected_main_page = 'toplevel_page_marrison-updater' === (string) $hook_suffix
			&& ! Settings::repository_config_managed();

		if ( ! $is_settings_page && ! $is_disconnected_main_page ) {
			return;
		}

		$version = defined( 'MCU_PLUGIN_VERSION' ) ? MCU_PLUGIN_VERSION : '1.0.0';
		$url     = defined( 'MCU_PLUGIN_URL' ) ? MCU_PLUGIN_URL : plugin_dir_url( MCU_PLUGIN_FILE );

		wp_enqueue_style(
			'mcu-client-admin',
			$url . 'assets/css/mcu-client.css',
			array( 'mcu-admin-style' ),
			$version
		);

	}

	/**
	 * Render the MCU Client settings panel.
	 *
	 * @return void
	 */
	public static function render_settings_panel() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accesso non consentito.', 'marrison-custom-updater' ) );
		}

		Settings::ensure_defaults();
		if ( ! Settings::repository_config_managed() ) {
			?>
			<div class="mcu-card mcu-client-card">
				<div class="mcu-client-actions">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mcu-client-inline-form">
						<?php wp_nonce_field( 'mcu_maintenance_client_download_config' ); ?>
						<input type="hidden" name="action" value="mcu_maintenance_client_download_config" />
						<button type="submit" class="mcu-button mcu-button-primary">
							<span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Scarica configurazione', 'marrison-custom-updater' ); ?>
						</button>
					</form>
				</div>
			</div>
			<?php
			return;
		}

		$settings              = Settings::ensure_dashboard_access( get_current_user_id() );
		$endpoint              = rest_url( Rest_Controller::REST_NAMESPACE . Rest_Controller::REST_ROUTE );
		$dashboard_endpoint    = rest_url( Rest_Controller::REST_NAMESPACE . Dashboard_Access_Controller::REST_ROUTE );
		$last_request          = ! empty( $settings['last_request_at'] ) ? wp_date( 'Y-m-d H:i:s', (int) $settings['last_request_at'] ) : __( 'Mai', 'marrison-custom-updater' );
		$last_dashboard_access = ! empty( $settings['last_dashboard_access_at'] ) ? wp_date( 'Y-m-d H:i:s', (int) $settings['last_dashboard_access_at'] ) : __( 'Mai', 'marrison-custom-updater' );
		$dashboard_enabled     = ! empty( $settings['dashboard_access_enabled'] );
		$dashboard_user        = ! empty( $settings['dashboard_access_user_id'] ) ? get_user_by( 'id', absint( $settings['dashboard_access_user_id'] ) ) : false;
		$notice_message        = isset( $_GET['mcu_client_notice'] ) ? sanitize_key( wp_unslash( $_GET['mcu_client_notice'] ) ) : '';
		?>
		<?php if ( 'secret_regenerated' === $notice_message ) : ?>
			<div class="mcu-notice mcu-notice-success"><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Chiave client rigenerata.', 'marrison-custom-updater' ); ?></div>
		<?php elseif ( 'dashboard_access_regenerated' === $notice_message ) : ?>
			<div class="mcu-notice mcu-notice-success"><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Accesso dashboard rigenerato.', 'marrison-custom-updater' ); ?></div>
		<?php elseif ( 'settings_saved' === $notice_message ) : ?>
			<div class="mcu-notice mcu-notice-success"><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Impostazioni client salvate.', 'marrison-custom-updater' ); ?></div>
		<?php endif; ?>

		<div class="mcu-card mcu-client-card">
			<div class="mcu-card-header">
				<h2 class="mcu-card-title"><span class="dashicons dashicons-rest-api"></span> <?php esc_html_e( 'Client Maintenance', 'marrison-custom-updater' ); ?></h2>
			</div>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'URL sito', 'marrison-custom-updater' ); ?></th>
						<td><code><?php echo esc_html( site_url() ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Endpoint Client', 'marrison-custom-updater' ); ?></th>
						<td><code><?php echo esc_url( $endpoint ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Endpoint dashboard', 'marrison-custom-updater' ); ?></th>
						<td><code><?php echo esc_url( $dashboard_endpoint ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><label for="mcu-client-secret"><?php esc_html_e( 'Chiave di connessione', 'marrison-custom-updater' ); ?></label></th>
						<td>
							<input id="mcu-client-secret" class="large-text code" type="text" readonly value="<?php echo esc_attr( (string) $settings['secret'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Visibile solo agli amministratori. La chiave non viene restituita dall endpoint REST.', 'marrison-custom-updater' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Stato client', 'marrison-custom-updater' ); ?></th>
						<td><?php esc_html_e( 'Attivo e in attesa di richieste autenticate dal Master.', 'marrison-custom-updater' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Accesso dashboard', 'marrison-custom-updater' ); ?></th>
						<td>
							<?php echo esc_html( $dashboard_enabled ? __( 'Abilitato', 'marrison-custom-updater' ) : __( 'Disabilitato', 'marrison-custom-updater' ) ); ?>
							<?php if ( $dashboard_user ) : ?>
								<span class="description"><?php echo esc_html( sprintf( __( 'Utente: %s', 'marrison-custom-updater' ), $dashboard_user->user_login ) ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Ultimo accesso dashboard', 'marrison-custom-updater' ); ?></th>
						<td><?php echo esc_html( $last_dashboard_access ); ?> <code><?php echo esc_html( (string) $settings['last_dashboard_access_result'] ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Ultima richiesta Master', 'marrison-custom-updater' ); ?></th>
						<td><?php echo esc_html( $last_request ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'IP ultima richiesta', 'marrison-custom-updater' ); ?></th>
						<td><?php echo esc_html( ! empty( $settings['last_request_ip'] ) ? (string) $settings['last_request_ip'] : __( 'Non disponibile', 'marrison-custom-updater' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Esito ultima autenticazione', 'marrison-custom-updater' ); ?></th>
						<td><code><?php echo esc_html( (string) $settings['last_auth_result'] ); ?></code></td>
					</tr>
				</tbody>
			</table>

			<div class="mcu-client-actions">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mcu-client-inline-form">
					<?php wp_nonce_field( 'mcu_maintenance_client_download_config' ); ?>
					<input type="hidden" name="action" value="mcu_maintenance_client_download_config" />
					<button type="submit" class="mcu-button mcu-button-primary">
						<span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Scarica configurazione', 'marrison-custom-updater' ); ?>
					</button>
				</form>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mcu-client-inline-form">
				<?php wp_nonce_field( 'mcu_maintenance_client_regenerate_secret' ); ?>
				<input type="hidden" name="action" value="mcu_maintenance_client_regenerate_secret" />
				<button type="submit" class="mcu-button mcu-button-secondary" onclick="return confirm('<?php echo esc_js( __( 'Rigenerare la chiave client? Dovrai aggiornare il Master.', 'marrison-custom-updater' ) ); ?>');">
					<span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Rigenera chiave', 'marrison-custom-updater' ); ?>
				</button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mcu-client-inline-form">
				<?php wp_nonce_field( 'mcu_maintenance_client_regenerate_dashboard_access' ); ?>
				<input type="hidden" name="action" value="mcu_maintenance_client_regenerate_dashboard_access" />
				<button type="submit" class="mcu-button mcu-button-secondary" onclick="return confirm('<?php echo esc_js( __( 'Rigenerare l accesso dashboard? Dovrai aggiornare il Master caricando una nuova configurazione.', 'marrison-custom-updater' ) ); ?>');">
					<span class="dashicons dashicons-unlock"></span> <?php esc_html_e( 'Rigenera accesso dashboard', 'marrison-custom-updater' ); ?>
				</button>
			</form>
		</div>

		<div class="mcu-card mcu-client-card" style="margin-top: 20px;">
			<div class="mcu-card-header">
				<h2 class="mcu-card-title"><span class="dashicons dashicons-admin-tools"></span> <?php esc_html_e( 'Accesso dashboard', 'marrison-custom-updater' ); ?></h2>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'mcu_maintenance_client_save_settings' ); ?>
				<input type="hidden" name="action" value="mcu_maintenance_client_save_settings" />
				<label class="mcu-client-debug-toggle">
					<input type="checkbox" name="dashboard_access_enabled" value="1" <?php checked( $dashboard_enabled ); ?> />
					<?php esc_html_e( 'Abilita accesso one-click dashboard dal Master', 'marrison-custom-updater' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'Il Master riceve un link temporaneo monouso solo quando premi il pulsante dashboard. Non vengono salvate password WordPress.', 'marrison-custom-updater' ); ?></p>
				<div style="margin-top: 16px;">
					<button type="submit" class="mcu-button mcu-button-primary"><?php esc_html_e( 'Salva impostazioni client', 'marrison-custom-updater' ); ?></button>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Download the Master import configuration file.
	 *
	 * @return void
	 */
	public static function handle_download_config() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accesso non consentito.', 'marrison-custom-updater' ) );
		}

		check_admin_referer( 'mcu_maintenance_client_download_config' );
		Settings::ensure_defaults();
		$settings  = Settings::ensure_dashboard_access( get_current_user_id() );
		$site_name = get_bloginfo( 'name' );
		$config = array(
			'schema'                   => 'mcu-maintenance-client-config',
			'version'                  => 2,
			'generated_at'             => gmdate( 'c' ),
			'site_name'                => $site_name,
			'site_url'                 => site_url(),
			'endpoint_url'             => rest_url( Rest_Controller::REST_NAMESPACE . Rest_Controller::REST_ROUTE ),
			'connection_key'           => (string) $settings['secret'],
			'dashboard_access_url'     => rest_url( Rest_Controller::REST_NAMESPACE . Dashboard_Access_Controller::REST_ROUTE ),
			'dashboard_access_key'     => (string) $settings['dashboard_access_key'],
			'dashboard_access_enabled' => ! empty( $settings['dashboard_access_enabled'] ),
			'client_version'           => defined( 'MCU_PLUGIN_VERSION' ) ? MCU_PLUGIN_VERSION : '',
		);

		$json = wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			wp_die( esc_html__( 'Impossibile generare la configurazione.', 'marrison-custom-updater' ) );
		}

		$filename = sanitize_title( '' !== $site_name ? $site_name : wp_parse_url( site_url(), PHP_URL_HOST ) );
		if ( '' === $filename ) {
			$filename = 'mcu-client';
		}

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '-mcu-config.json"' );
		header( 'Content-Length: ' . strlen( $json ) );
		echo $json;
		exit;
	}

	/**
	 * Regenerate the shared secret.
	 *
	 * @return void
	 */
	public static function handle_regenerate_secret() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accesso non consentito.', 'marrison-custom-updater' ) );
		}

		check_admin_referer( 'mcu_maintenance_client_regenerate_secret' );
		Settings::regenerate_secret();

		wp_safe_redirect( self::settings_url( 'secret_regenerated' ) );
		exit;
	}

	/**
	 * Regenerate the dashboard access key.
	 *
	 * @return void
	 */
	public static function handle_regenerate_dashboard_access() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accesso non consentito.', 'marrison-custom-updater' ) );
		}

		check_admin_referer( 'mcu_maintenance_client_regenerate_dashboard_access' );
		Settings::regenerate_dashboard_access_key( get_current_user_id() );

		wp_safe_redirect( self::settings_url( 'dashboard_access_regenerated' ) );
		exit;
	}

	/**
	 * Save settings.
	 *
	 * @return void
	 */
	public static function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accesso non consentito.', 'marrison-custom-updater' ) );
		}

		check_admin_referer( 'mcu_maintenance_client_save_settings' );
		Settings::set_dashboard_access_enabled( ! empty( $_POST['dashboard_access_enabled'] ), get_current_user_id() );

		wp_safe_redirect( self::settings_url( 'settings_saved' ) );
		exit;
	}

	/**
	 * Return the MCU Client settings URL.
	 *
	 * @param string $notice Optional notice key.
	 * @return string
	 */
	private static function settings_url( $notice = '' ) {
		$args = array(
			'page' => 'marrison-updater-settings',
			'tab'  => 'client',
		);

		if ( '' !== $notice ) {
			$args['mcu_client_notice'] = sanitize_key( $notice );
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}
}
