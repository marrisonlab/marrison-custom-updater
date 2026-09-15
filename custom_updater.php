<?php
/**
 * Plugin Name: WP Master Updater
 * Plugin URI:  https://github.com/marrisonlab/marrison-custom-updater
 * Description: This plugin is used to add a personal repository for updating plugins.
 * Version: 9.8.12
 * Author: Marrisonlab
 * Author URI:  https://marrisonlab.com
 * Text Domain: marrison-custom-updater
 * Domain Path: /languages
 */

if (!defined('MCU_PLUGIN_DIR')) {
    define('MCU_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined('MCU_PLUGIN_FILE')) {
    define('MCU_PLUGIN_FILE', __FILE__);
}
if (!defined('MCU_PLUGIN_URL')) {
    define('MCU_PLUGIN_URL', plugin_dir_url(__FILE__));
}
if (!defined('MCU_PLUGIN_VERSION')) {
    define('MCU_PLUGIN_VERSION', '9.8.12');
}

require_once __DIR__ . '/includes/mcu-client/class-settings.php';
require_once __DIR__ . '/includes/mcu-client/class-installer.php';
require_once __DIR__ . '/includes/mcu-client/class-plugin.php';
require_once __DIR__ . '/includes/traits/SchedulingTrait.php';
require_once __DIR__ . '/includes/traits/AdminUITrait.php';
require_once __DIR__ . '/includes/traits/UpdateOperationsTrait.php';

register_activation_hook(__FILE__, ['MarrisonCustomUpdater\\MaintenanceClient\\Installer', 'activate']);
register_deactivation_hook(__FILE__, ['MarrisonCustomUpdater\\MaintenanceClient\\Installer', 'deactivate']);

\MarrisonCustomUpdater\MaintenanceClient\Plugin::init();

if (!class_exists('MCU_Custom_Updater')) {
class MCU_Custom_Updater {

    private $updates_url = '';
    private $cache_duration;
    private $mcu_update_lock_token = '';

    use MCU_Scheduling_Trait;
    use MCU_Admin_UI_Trait;
    use MCU_Update_Operations_Trait;

    public function __construct() {
        $this->cache_duration = defined('HOUR_IN_SECONDS') ? 6 * constant('HOUR_IN_SECONDS') : 21600;

        add_action('plugins_loaded', [$this, 'load_textdomain']);

        // Usa site_transient_update_plugins invece di pre_set_site_transient_update_plugins
        // per iniettare gli aggiornamenti in tempo reale quando WP controlla la cache
        // Solo nell'area admin per non rallentare il frontend (guard is_admin() anche
        // dentro le funzioni stesse come protezione extra)
        add_action('admin_init', function() {
            add_filter('site_transient_update_plugins', [$this, 'check_for_updates'], 999);
            add_filter('site_transient_update_themes', [$this, 'check_for_theme_updates'], 999);
            add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
        });

        // Sincronizza la pulizia della cache solo dopo aggiornamenti completati
        // NON agganciare a delete_site_transient_update_plugins: WP la cancella su quasi ogni
        // pagina admin, il che svuoterebbe il cache del repo ad ogni richiesta costringendo
        // una chiamata HTTP sincrona di 15s al prossimo caricamento.
        add_action('upgrader_process_complete', [$this, 'delete_internal_cache'], 10, 2);
        // Hook per triggerare l'update del DB di Elementor
        add_action('upgrader_process_complete', [$this, 'trigger_elementor_db_update'], 20, 2);
        
        // Hook per tracciare l'ultimo aggiornamento plugin (manuale o automatico)
        add_action('upgrader_process_complete', [$this, 'update_last_plugin_update_timestamp'], 10, 0);
        
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_post_marrison_update_plugin', [$this, 'update_plugin']);
        add_action('admin_post_marrison_restore_plugin', [$this, 'restore_plugin']);
        add_action('admin_post_marrison_bulk_update', [$this, 'bulk_update']);
        add_action('admin_post_marrison_clear_cache', [$this, 'clear_cache']);
        add_action('admin_post_mcu_clear_update_lock', [$this, 'mcu_clear_update_lock_admin_action']);
        add_action('admin_post_marrison_force_check_mcu', [$this, 'force_check_mcu']);
        add_filter('mcu_remote_update_plugin', [$this, 'mcu_remote_update_plugin'], 10, 2);
        
        add_action('admin_post_marrison_download_repo_file', [$this, 'download_repo_file']);
        add_action('admin_post_marrison_download_theme_repo_file', [$this, 'download_theme_repo_file']);
        add_action('admin_post_marrison_save_scheduling', [$this, 'save_scheduling_settings']);
        add_action('admin_post_marrison_download_db_backup', [$this, 'download_db_backup']);
        add_action('admin_post_nopriv_marrison_download_db_backup', [$this, 'download_db_backup']);
        add_action('admin_post_marrison_download_files_backup', [$this, 'download_files_backup']);
        add_action('admin_post_nopriv_marrison_download_files_backup', [$this, 'download_files_backup']);
        add_action('admin_post_marrison_download_update_log', [$this, 'download_update_log']);
        add_action('admin_post_marrison_clear_update_logs', [$this, 'clear_update_logs']);
        
        // Cron
        add_filter('cron_schedules', [$this, 'add_custom_cron_intervals']);
        add_action('marrison_scheduled_update_event', [$this, 'run_scheduled_updates'], 10, 1);
        add_action('init', [$this, 'mcu_repair_missing_automatic_update_schedule'], 20);
        
        // Hook per AJAX
        add_action('wp_ajax_marrison_update_plugin_ajax', [$this, 'update_plugin_ajax']);
        add_action('wp_ajax_marrison_bulk_update_ajax', [$this, 'bulk_update_ajax']);
        add_action('wp_ajax_marrison_auto_update_ajax', [$this, 'auto_update_ajax']);
        add_action('wp_ajax_marrison_get_official_updates_ajax', [$this, 'get_official_updates_ajax']);
        add_action('wp_ajax_marrison_update_official_plugin_ajax', [$this, 'update_official_plugin_ajax']);
        add_action('wp_ajax_marrison_restore_plugin_ajax', [$this, 'restore_plugin_ajax']);
        add_action('wp_ajax_marrison_update_private_theme_ajax', [$this, 'update_private_theme_ajax']);
        add_action('wp_ajax_marrison_bulk_update_private_themes_ajax', [$this, 'bulk_update_private_themes_ajax']);
        add_action('wp_ajax_marrison_update_all_themes_ajax', [$this, 'update_all_themes_ajax']);
        add_action('wp_ajax_marrison_update_translations_ajax', [$this, 'update_translations_ajax']);
        add_action('wp_ajax_marrison_get_all_updates_ajax', [$this, 'get_all_updates_ajax']);
        add_action('wp_ajax_marrison_test_email', [$this, 'send_test_email_ajax']);
        add_action('wp_ajax_marrison_toggle_exclusion', [$this, 'toggle_exclusion_ajax']);
        add_action('wp_ajax_marrison_db_backup', [$this, 'ajax_db_backup']);
        add_action('wp_ajax_marrison_files_backup', [$this, 'ajax_files_backup']);
        add_action('wp_ajax_marrison_delete_backup', [$this, 'ajax_delete_backup']);
        
        // Aggiungi script e stili per la pagina admin
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        
        // Hook per aggiungere link al plugin Marrison Updater nella pagina dei plugin
        add_filter('plugin_action_links', [$this, 'add_marrison_action_links'], 10, 2);
        add_filter('plugin_row_meta', [$this, 'add_plugin_row_meta'], 10, 2);
        
        // Hook per aggiungere notifiche al menu
        add_action('admin_menu', [$this, 'add_menu_notification_badge'], 999);
        add_action('admin_head', [$this, 'add_menu_badge_styles']);
        add_action('admin_init', [$this, 'flush_rules_on_upgrade']);
        add_action('admin_init', [$this, 'maybe_cleanup_files_backup_retention']);

        // Ricalcola il conteggio badge ad ogni richiesta admin: senza questo hook
        // il valore restava bloccato all'ultimo conteggio calcolato dopo un update
        // manuale, anche quando non c'erano più aggiornamenti reali disponibili.
        // get_available_updates()/get_available_theme_updates() sono già cachate
        // con transient da 6h, quindi non introduce chiamate HTTP ripetute.
        add_action('admin_init', [$this, 'check_for_available_updates']);
        
        // Filtro per abilitare auto-update per questo plugin
        // add_filter('auto_update_plugin', [$this, 'auto_update_specific_plugins'], 10, 2);
        
        // La cache GitHub viene pulita esplicitamente da clear_cache() e force_check_mcu()
        // Non agganciare a delete_site_transient_update_plugins per evitare fetch ripetuti
    }

    public function load_textdomain() {
        $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
        $locale = apply_filters('plugin_locale', $locale, 'marrison-custom-updater');

        if (function_exists('is_textdomain_loaded') && is_textdomain_loaded('marrison-custom-updater')) {
            unload_textdomain('marrison-custom-updater');
        }

        $locales = array($locale);
        if (strpos($locale, 'en_') === 0 && $locale !== 'en_US') {
            $locales[] = 'en_US';
        }

        foreach (array_unique($locales) as $candidate_locale) {
            $bundled_mofile = MCU_PLUGIN_DIR . 'languages/marrison-custom-updater-' . $candidate_locale . '.mo';
            if (is_readable($bundled_mofile)) {
                load_textdomain('marrison-custom-updater', $bundled_mofile);
                break;
            }

            $global_mofile = WP_LANG_DIR . '/plugins/marrison-custom-updater-' . $candidate_locale . '.mo';
            if (is_readable($global_mofile)) {
                load_textdomain('marrison-custom-updater', $global_mofile);
                break;
            }
        }

        return load_plugin_textdomain('marrison-custom-updater', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function force_clear_github_cache() {
        delete_transient('marrison_updater_github_version');
        delete_transient('marrison_github_fetch_failed');
    }

    public function auto_update_specific_plugins($update, $item) {
        if (isset($item->slug) && $item->slug === 'marrison-custom-updater') {
            return true;
        }
        return $update;
    }

    public function toggle_exclusion_ajax() {
        check_ajax_referer('marrison_toggle_exclusion', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permessi insufficienti', 'marrison-custom-updater'));
        }

        $type = sanitize_text_field($_POST['type']);
        $slug = sanitize_text_field($_POST['slug']);
        $state = filter_var($_POST['state'], FILTER_VALIDATE_BOOLEAN);

        $option_name = ($type === 'theme') ? 'marrison_excluded_themes' : 'marrison_excluded_plugins';
        $excluded = get_option($option_name, []);
        if (!is_array($excluded)) $excluded = [];

        if ($state) {
            // Add to exclusion
            if (!in_array($slug, $excluded)) {
                $excluded[] = $slug;
            }
        } else {
            // Remove from exclusion
            $excluded = array_values(array_filter($excluded, function($s) use ($slug) {
                return $s !== $slug;
            }));
        }

        update_option($option_name, $excluded);
        
        // Clear transients so update check reflects change immediately
        delete_site_transient('update_plugins');
        delete_site_transient('update_themes');
        delete_transient('marrison_available_updates_v2');
        delete_transient('marrison_available_theme_updates');

        wp_send_json_success(['excluded' => $excluded]);
    }

    /* ===================== PERMISSIONS CHECK REMOVED ===================== */

    /* ===================== AUTO UPDATE AJAX HANDLER ===================== */

    public function auto_update_ajax() {
        // Verifica il nonce
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        
        if (!wp_verify_nonce($nonce, 'marrison_auto_update')) {
            wp_die(__('Security check failed', 'marrison-custom-updater'));
        }

        // Verifica i permessi
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'marrison-custom-updater'));
        }

        if (!\MarrisonCustomUpdater\MaintenanceClient\Settings::repository_config_managed()) {
            wp_send_json_error(__('Operazione bloccata: il client MCU non è autorizzato da Commander.', 'marrison-custom-updater'));
        }

        $cleared_cron_events = $this->mcu_clear_master_update_cron_events();
        if ($cleared_cron_events > 0) {
            $this->mcu_log_event('info', 'dashboard_public_update_cron_cleared', [
                'cleared_cron_events' => $cleared_cron_events,
            ]);
        }

        // Ottieni tutti i plugin con aggiornamenti automatici attivati
        $auto_update_plugins = (array) get_site_option('auto_update_plugins', []);
        
        // Forza il controllo degli aggiornamenti WordPress
        wp_update_plugins();
        $transient = get_site_transient('update_plugins');
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_plugins = get_plugins();
        
        if (empty($transient->response)) {
            wp_send_json_error(__('Nessun aggiornamento disponibile', 'marrison-custom-updater'));
        }

        // Identifica i plugin privati INSTALLATI per ESCLUDERLI
        $private_updates = $this->get_available_updates();
        $private_files = [];
        foreach ($private_updates as $u) {
            $f = $this->find_plugin_file($u['slug'], $u['name'] ?? '');
            if ($f) $private_files[] = $f;
        }
        $installed_slugs = [];
        foreach ($private_files as $pf) {
            $installed_slugs[] = dirname($pf);
            $installed_slugs[] = basename($pf, '.php');
        }
        $installed_slugs = array_values(array_filter(array_unique($installed_slugs), function($s){
            return $s !== '.' && $s !== '';
        }));

        $plugins_to_update = [];
        $slugs_map = []; // Mappa slug => file
        
        foreach ($transient->response as $file => $data) {
            $slug = dirname($file);
            if ($slug === '.' || $slug === '') $slug = basename($file, '.php');

            // ESCLUDI i plugin privati installati (FILE) o con SLUG noto privato
            if (in_array($file, $private_files)) continue;
            $plugin_name = isset($all_plugins[$file]['Name']) ? $all_plugins[$file]['Name'] : '';
            if ($this->mcu_is_plugin_update_excluded($slug, $file, $plugin_name, $data)) continue;
            $check_slugs = [$slug, basename($file, '.php')];
            if (isset($data->slug)) $check_slugs[] = $data->slug;
            $found_private = false;
            foreach (array_unique($check_slugs) as $s) {
                if ($s !== '.' && $s !== '' && in_array($s, $installed_slugs)) {
                    $found_private = true;
                    break;
                }
            }
            if ($found_private) continue;

            // Includi TUTTI i plugin standard che hanno un aggiornamento, non solo quelli con auto-update
            $plugins_to_update[] = $file;
            $slugs_map[$slug] = $file;
        }
        
        if (empty($plugins_to_update)) {
            wp_send_json_error(__('Nessun plugin "normale" ha aggiornamenti disponibili', 'marrison-custom-updater'));
        }

        $lock = $this->mcu_acquire_update_lock('official_plugins_bulk_ajax', ['plugins' => $plugins_to_update]);
        if (is_wp_error($lock)) {
            wp_send_json_error($lock->get_error_message());
        }
        $snapshot = $this->mcu_capture_active_plugin_snapshot([
            'operation' => 'official_plugins_bulk_ajax',
            'plugins'   => $plugins_to_update,
        ]);
        $this->mcu_log_event('info', 'official_plugins_bulk_started', ['plugins' => $plugins_to_update]);

        // Carica le classi necessarie per l'aggiornamento
        include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        include_once ABSPATH . 'wp-admin/includes/plugin.php';
        
        // Usa Automatic_Upgrader_Skin per evitare output HTML
        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);
        
        // Esegui l'aggiornamento
        $results = $upgrader->bulk_upgrade($plugins_to_update);
        if (is_wp_error($results)) {
            $this->mcu_log_event('error', 'official_plugins_bulk_failed', ['error' => $results]);
            $this->mcu_flush_update_caches(['operation' => 'official_plugins_bulk_ajax']);
            $this->mcu_restore_active_plugin_snapshot($snapshot, ['operation' => 'official_plugins_bulk_ajax']);
            $this->mcu_release_update_lock($lock);
            wp_send_json_error($results->get_error_message());
        }
        
        $success_count = 0;
        $formatted_results = [];

        // Analizza i risultati
        // bulk_upgrade restituisce un array indicizzato dai file path, con valore true/false/WP_Error/array info
        foreach ($slugs_map as $slug => $file) {
            $result = isset($results[$file]) ? $results[$file] : false;
            
            // Verifica se il risultato Ã¨ positivo (non false e non WP_Error)
            // A volte restituisce un array con 'destination_name', etc.
            $is_success = $result && !is_wp_error($result);
            $formatted_results[$slug] = $is_success;
            
            if ($is_success) {
                $success_count++;
            }
        }

        if ($success_count > 0) {
            $this->mcu_flush_update_caches(['operation' => 'official_plugins_bulk_ajax']);
            $this->mcu_restore_active_plugin_snapshot($snapshot, ['operation' => 'official_plugins_bulk_ajax']);
            $this->mcu_release_update_lock($lock);
            $this->check_for_available_updates();

            wp_send_json_success([
                'message' => sprintf(__('%d plugin aggiornati con successo', 'marrison-custom-updater'), $success_count),
                'results' => $formatted_results,
                'success_count' => $success_count,
                'total_count' => count($plugins_to_update)
            ]);
        } else {
            $this->mcu_log_event('error', 'official_plugins_bulk_no_success', [
                'plugins' => $plugins_to_update,
                'results' => $results,
            ]);
            $this->mcu_flush_update_caches(['operation' => 'official_plugins_bulk_ajax']);
            $this->mcu_restore_active_plugin_snapshot($snapshot, ['operation' => 'official_plugins_bulk_ajax']);
            $this->mcu_release_update_lock($lock);
            wp_send_json_error(__('Nessun plugin è stato aggiornato', 'marrison-custom-updater'));
        }
    }

    public function get_official_updates_ajax() {
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'marrison_auto_update')) {
            wp_send_json_error(__('Security check failed', 'marrison-custom-updater'));
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'marrison-custom-updater'));
        }

        if (!\MarrisonCustomUpdater\MaintenanceClient\Settings::repository_config_managed()) {
            wp_send_json_error(__('Operazione bloccata: il client MCU non è autorizzato da Commander.', 'marrison-custom-updater'));
        }

        // Forza controllo aggiornamenti
        wp_update_plugins();
        $transient = get_site_transient('update_plugins');

        if (empty($transient->response)) {
            wp_send_json_success([]);
        }

        // Calcola plugin privati INSTALLATI per evitare falsi positivi
        $private_updates = $this->get_available_updates();
        $private_files = [];
        foreach ($private_updates as $u) {
            $f = $this->find_plugin_file($u['slug'], $u['name'] ?? '');
            if ($f) $private_files[] = $f;
        }
        $installed_slugs = [];
        foreach ($private_files as $pf) {
            $installed_slugs[] = dirname($pf);
            $installed_slugs[] = basename($pf, '.php');
        }
        $installed_slugs = array_values(array_filter(array_unique($installed_slugs), function($s){
            return $s !== '.' && $s !== '';
        }));

        $plugins_to_update = [];
        
        foreach ($transient->response as $file => $data) {
            $slug = dirname($file);
            if ($slug === '.' || $slug === '') $slug = basename($file, '.php');

            // Escludi se il FILE appartiene ad un plugin privato installato
            if (in_array($file, $private_files)) continue;
            
            // Escludi se lo SLUG corrisponde ad un privato INSTALLATO (noti)
            $check_slugs = [$slug, basename($file, '.php')];
            if (isset($data->slug)) $check_slugs[] = $data->slug;
            $found_private = false;
            foreach (array_unique($check_slugs) as $s) {
                if ($s !== '.' && $s !== '' && in_array($s, $installed_slugs)) {
                    $found_private = true;
                    break;
                }
            }
            if ($found_private) continue;

            $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $file);
            if ($this->mcu_is_plugin_update_excluded($slug, $file, $plugin_data['Name'] ?? '', $data)) {
                continue;
            }
            
            $plugins_to_update[] = [
                'file' => $file,
                'slug' => $slug,
                'name' => $plugin_data['Name'] ?? $slug,
                'version' => $data->new_version,
                'package' => $data->package ?? '',
                'url' => $data->url ?? ''
            ];
        }
        
        wp_send_json_success($plugins_to_update);
    }

    public function update_official_plugin_ajax() {
        @ignore_user_abort(true);
        @set_time_limit(0);

        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        $file = sanitize_text_field($_POST['file'] ?? '');
        $package = isset($_POST['package']) ? esc_url_raw($_POST['package']) : '';
        $new_version = sanitize_text_field($_POST['new_version'] ?? '');

        if (!wp_verify_nonce($nonce, 'marrison_auto_update')) {
            wp_send_json_error(__('Security check failed', 'marrison-custom-updater'));
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'marrison-custom-updater'));
        }

        if (!\MarrisonCustomUpdater\MaintenanceClient\Settings::repository_config_managed()) {
            wp_send_json_error(__('Operazione bloccata: il client MCU non è autorizzato da Commander.', 'marrison-custom-updater'));
        }
        
        if (empty($file)) {
             wp_send_json_error(__('Missing file parameter', 'marrison-custom-updater'));
        }

        include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        include_once ABSPATH . 'wp-admin/includes/plugin.php';
        
        $was_active = is_plugin_active($file);
        $was_network_active = is_multisite() && function_exists('is_plugin_active_for_network') && is_plugin_active_for_network($file);
        $plugin_slug = dirname($file);
        if ($plugin_slug === '.' || $plugin_slug === '') {
            $plugin_slug = basename($file, '.php');
        }

        $all_plugins = get_plugins();
        $current_version = '';
        if (isset($all_plugins[$file])) {
            $current_version = $all_plugins[$file]['Version'];
        }
        $plugin_name = isset($all_plugins[$file]['Name']) ? $all_plugins[$file]['Name'] : $plugin_slug;
        if ($this->mcu_is_plugin_update_excluded($plugin_slug, $file, $plugin_name)) {
            wp_send_json_error(__('Plugin escluso dagli aggiornamenti.', 'marrison-custom-updater'));
        }

        $lock = $this->mcu_acquire_update_lock('official_plugin_update', [
            'file'        => $file,
            'new_version' => $new_version,
        ]);
        if (is_wp_error($lock)) {
            wp_send_json_error($lock->get_error_message());
        }
        $snapshot = $this->mcu_capture_active_plugin_snapshot([
            'operation' => 'official_plugin_update',
            'file'      => $file,
        ]);
        $this->mcu_log_event('info', 'official_plugin_update_started', [
            'file'            => $file,
            'current_version' => $current_version,
            'new_version'     => $new_version,
            'package'         => $package,
        ]);

        $backup_created = $this->create_backup($plugin_slug, $current_version, 'plugin', $file);
        $this->mcu_log_event($backup_created ? 'info' : 'warning', 'official_plugin_backup_created', [
            'file'            => $file,
            'slug'            => $plugin_slug,
            'current_version' => $current_version,
            'backup_created'  => (bool) $backup_created,
        ]);
        
        // Ensure update info is present in transient
        $transient = get_site_transient('update_plugins');
        if (!is_object($transient)) {
            $transient = new stdClass();
        }
        if (!isset($transient->response)) {
            $transient->response = [];
        }

        // Inject update info if missing and package is provided
        if (!isset($transient->response[$file]) && !empty($package)) {
            $obj = new stdClass();
            $obj->slug = dirname($file);
            if ($obj->slug == '.' || $obj->slug == '') $obj->slug = basename($file, '.php');
            $obj->plugin = $file;
            $obj->package = $package;
            $obj->new_version = $new_version; 
            $obj->url = ''; 
            
            $transient->response[$file] = $obj;
            set_site_transient('update_plugins', $transient);
        } elseif (!isset($transient->response[$file])) {
            // Fallback if no package provided: force remote check
            wp_update_plugins();
        }
        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);
        
        $result = $upgrader->upgrade($file);
        
        if (is_wp_error($result)) {
            $this->mcu_log_event('error', 'official_plugin_update_failed', [
                'file'  => $file,
                'error' => $result,
            ]);
            $this->mcu_flush_update_caches(['operation' => 'official_plugin_update', 'file' => $file]);
            $this->mcu_restore_active_plugin_snapshot($snapshot, ['operation' => 'official_plugin_update', 'file' => $file]);
            $this->mcu_release_update_lock($lock);
            wp_send_json_error($result->get_error_message());
        } elseif (!$result) {
            $this->mcu_log_event('error', 'official_plugin_update_returned_false', ['file' => $file]);
            $this->mcu_flush_update_caches(['operation' => 'official_plugin_update', 'file' => $file]);
            $this->mcu_restore_active_plugin_snapshot($snapshot, ['operation' => 'official_plugin_update', 'file' => $file]);
            $this->mcu_release_update_lock($lock);
            wp_send_json_error(__('Update failed', 'marrison-custom-updater'));
        } else {
            if ($was_active && !is_plugin_active($file)) {
                // Reactivate silently: do not fire activation hooks in an update context
                $activate = activate_plugin($file, '', $was_network_active, true);
                if (is_wp_error($activate)) {
                    $this->mcu_log_event('warning', 'official_plugin_reactivation_failed', [
                        'file'  => $file,
                        'error' => $activate,
                    ]);
                }
            }

            $this->mcu_flush_update_caches(['operation' => 'official_plugin_update', 'file' => $file]);
            $this->mcu_restore_active_plugin_snapshot($snapshot, ['operation' => 'official_plugin_update', 'file' => $file]);
            $this->mcu_release_update_lock($lock);
            wp_send_json_success(__('Plugin updated', 'marrison-custom-updater'));
        }
    }

    /* ===================== MENU NOTIFICATION BADGE ===================== */

    private function update_known_private_slugs($updates) {
        if (!is_array($updates)) return;
        
        $slugs = [];
        foreach ($updates as $u) {
            $file = $this->find_plugin_file($u['slug'], $u['name'] ?? '');
            if ($file) {
                $slugs[] = dirname($file);
                $slugs[] = basename($file, '.php');
                if (isset($u['slug'])) $slugs[] = $u['slug'];
            }
        }
        $slugs = array_values(array_filter(array_unique($slugs), function($s) {
            return $s !== '.' && $s !== '';
        }));
        
        // Salva solo se abbiamo rilevato plugin privati INSTALLATI
        update_option('marrison_known_private_slugs', $slugs, false); // autoload = false
    }

    public function check_for_available_updates() {
        if (!\MarrisonCustomUpdater\MaintenanceClient\Settings::repository_config_managed()) {
            update_option('marrison_available_updates_count', 0);
            return;
        }

        // Salva il numero di aggiornamenti disponibili in un'opzione per accesso rapido
        $updates = $this->get_available_updates();
        
        // Aggiorna la lista dei plugin conosciuti per il blocco futuro
        $this->update_known_private_slugs($updates);
        
        $plugins = get_plugins();
        $update_count = 0;
        
        foreach ($updates as $u) {
            $file = $this->find_plugin_file($u['slug'], $u['name'] ?? '');
            if ($this->mcu_is_plugin_update_excluded($u['slug'], $file, $u['name'] ?? '', $u)) {
                continue;
            }
            if ($file && isset($plugins[$file]) && version_compare($plugins[$file]['Version'], $u['version'], '<')) {
                $update_count++;
            }
        }

        // Aggiungi conteggio temi
        $theme_updates = $this->get_available_theme_updates();
        $installed_themes = wp_get_themes(); // Cache temi installati
        
        foreach ($theme_updates as $u) {
            $slug = $u['slug'];

            if ($this->is_item_excluded($slug, 'theme')) {
                continue;
            }
            
            $theme = wp_get_theme($slug);
            
            // Logica di fallback per trovare il tema se lo slug non corrisponde
            if (!$theme->exists()) {
                foreach ($installed_themes as $t_slug => $t_obj) {
                    if (strcasecmp($t_obj->get('Name'), $u['name']) === 0 || $t_obj->get('TextDomain') === $slug) {
                        $theme = $t_obj;
                        $slug = $t_slug;
                        break;
                    }
                }
            }

            if ($theme->exists() && version_compare($theme->get('Version'), $u['version'], '<')) {
                $update_count++;
            }
        }
        
        update_option('marrison_available_updates_count', $update_count);
    }

    public function flush_rules_on_upgrade() {
        // Run only once for version 8.7
        if (get_option('marrison_custom_updater_version') !== '8.7') {
            flush_rewrite_rules();
            update_option('marrison_custom_updater_version', '8.7');
        }
    }

    

    

    /* ===================== UPDATE SOURCE ===================== */

    /* ===================== WP UPDATE HOOK ===================== */

    public function check_for_updates($transient) {
        if (!is_admin()) return $transient;
        if (!\MarrisonCustomUpdater\MaintenanceClient\Settings::repository_config_managed()) return $transient;
        if (!is_object($transient)) $transient = new stdClass();
        
        // Assicurati che le proprietà esistano
        if (!isset($transient->response)) $transient->response = [];
        if (!isset($transient->no_update)) $transient->no_update = [];
        if (!isset($transient->checked)) $transient->checked = [];

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = get_plugins();

        // 1. Calcola gli slug dei plugin PRIVATI INSTALLATI
        $installed_private_slugs = [];
        $private_updates_list = $this->get_available_updates();
        if (!empty($private_updates_list)) {
            foreach ($private_updates_list as $u) {
                $f = $this->find_plugin_file($u['slug'], $u['name'] ?? '');
                if ($f) {
                    $installed_private_slugs[] = dirname($f);
                    $installed_private_slugs[] = basename($f, '.php');
                    $installed_private_slugs[] = $u['slug'];
                }
            }
            $installed_private_slugs = array_values(array_filter(array_unique($installed_private_slugs), function($s) {
                return $s !== '.' && $s !== '';
            }));
        }
        
        if (!empty($installed_private_slugs)) {
            // Pulizia aggressiva basata sullo SLUG, non solo sul file path
            // Questo gestisce casi in cui WP rileva il plugin in un path diverso (es. cartella standard vs rinominata)
            
            // Pulisci response SOLO per plugin che confliggono con privati INSTALLATI
            if (!empty($transient->response)) {
                foreach ($transient->response as $file => $data) {
                    $check_slugs = [];
                    $check_slugs[] = dirname($file);
                    $check_slugs[] = basename($file, '.php');
                    if (isset($data->slug)) $check_slugs[] = $data->slug;
                    
                    // Rimuovi duplicati e valori vuoti/punto
                    $check_slugs = array_filter(array_unique($check_slugs), function($s) {
                        return $s !== '.' && $s !== '';
                    });

                    foreach ($check_slugs as $s) {
                        if (in_array($s, $installed_private_slugs)) {
                            unset($transient->response[$file]);
                            break;
                        }
                    }
                }
            }

            // Pulisci no_update
            if (!empty($transient->no_update)) {
                foreach ($transient->no_update as $file => $data) {
                    $check_slugs = [];
                    $check_slugs[] = dirname($file);
                    $check_slugs[] = basename($file, '.php');
                    if (isset($data->slug)) $check_slugs[] = $data->slug;
                    
                    $check_slugs = array_filter(array_unique($check_slugs), function($s) {
                        return $s !== '.' && $s !== '';
                    });

                    foreach ($check_slugs as $s) {
                        if (in_array($s, $installed_private_slugs)) {
                            unset($transient->no_update[$file]);
                            break;
                        }
                    }
                }
            }
        }

        // 2. Inietta i NUOVI aggiornamenti dal repository privato (se disponibili)
        foreach ($this->get_available_updates() as $update) {
            $file = $this->find_plugin_file($update['slug'], $update['name'] ?? '');
            if ($this->mcu_is_plugin_update_excluded($update['slug'], $file, $update['name'] ?? '', $update)) {
                continue;
            }
            if (!$file || !isset($plugins[$file])) continue;

            // Rimuovi di nuovo per sicurezza (ridondante ma sicuro)
            if (isset($transient->response[$file])) unset($transient->response[$file]);
            if (isset($transient->no_update[$file])) unset($transient->no_update[$file]);

            $installed = $plugins[$file]['Version'];
            $remote    = $update['version'];

            if (version_compare($installed, $remote, '<')) {
                $transient->response[$file] = (object)[
                    'slug'        => $update['slug'],
                    'new_version' => $remote,
                    'package'     => $update['download_url'],
                    'plugin'      => $file,
                    'url'         => '', // Rimuovi URL per evitare link a wp.org
                ];
            } else {
                 $transient->no_update[$file] = (object)[
                    'slug'        => $update['slug'],
                    'new_version' => $remote,
                    'package'     => $update['download_url'],
                    'url'         => '',
                    'plugin'      => $file,
                ];
            }

            $transient->checked[$file] = $installed;
        }

        // Controlla anche il plugin stesso da GitHub
        $this->check_self_update($transient);

        return $transient;
    }

    /* ===================== THEME UPDATES ===================== */

    public function check_for_theme_updates($transient) {
        if (!is_admin()) return $transient;
        if (!\MarrisonCustomUpdater\MaintenanceClient\Settings::repository_config_managed()) return $transient;
        if (!is_object($transient)) $transient = new stdClass();
        
        if (!isset($transient->response)) $transient->response = [];
        if (!isset($transient->no_update)) $transient->no_update = [];
        if (!isset($transient->checked)) $transient->checked = [];

        // Recupera tutti i temi installati per la ricerca fuzzy
        $installed_themes = wp_get_themes();

        // Inietta i NUOVI aggiornamenti dal repository privato
        foreach ($this->get_available_theme_updates() as $update) {
            $slug = $update['slug'];

            // Check exclusion
            if ($this->is_item_excluded($slug, 'theme')) {
                continue;
            }
            
            // Cerca il tema installato
            $theme = wp_get_theme($slug);
            
            // Se non trova corrispondenza esatta, cerca per nome tema
            if (!$theme->exists()) {
                foreach ($installed_themes as $t_slug => $t_obj) {
                    // Cerca per nome (case insensitive)
                    if (strcasecmp($t_obj->get('Name'), $update['name']) === 0) {
                        $theme = $t_obj;
                        $slug = $t_slug; // Aggiorna lo slug con quello reale della cartella
                        break;
                    }
                    
                    // Cerca per Text Domain
                    if ($t_obj->get('TextDomain') === $update['slug']) {
                        $theme = $t_obj;
                        $slug = $t_slug;
                        break;
                    }
                }
            }
            
            if (!$theme->exists()) continue;

            $installed = $theme->get('Version');
            $remote    = $update['version'];

            // Rimuovi eventuali aggiornamenti ufficiali per evitare conflitti
            if (isset($transient->response[$slug])) unset($transient->response[$slug]);
            if (isset($transient->no_update[$slug])) unset($transient->no_update[$slug]);

            if (version_compare($installed, $remote, '<')) {
                $transient->response[$slug] = [
                    'theme'       => $slug,
                    'new_version' => $remote,
                    'package'     => $update['download_url'],
                    'url'         => '', 
                ];
            } else {
                 $transient->no_update[$slug] = [
                    'theme'       => $slug,
                    'new_version' => $remote,
                    'package'     => $update['download_url'],
                    'url'         => '',
                ];
            }

            $transient->checked[$slug] = $installed;
        }

        return $transient;
    }

    private function check_self_update($transient) {
        $plugin_file = plugin_basename(__FILE__);
        $plugins = get_plugins();

        if (!isset($plugins[$plugin_file])) return;

        $installed = $plugins[$plugin_file]['Version'];
        $remote = $this->get_github_version();

        $item = (object)[
            'id'          => 'marrison-custom-updater',
            'slug'        => 'marrison-custom-updater',
            'plugin'      => $plugin_file,
            'new_version' => $remote,
            'url'         => 'https://github.com/marrisonlab/Marrison-Custom-Updater',
            'package'     => 'https://github.com/marrisonlab/Marrison-Custom-Updater/archive/refs/tags/v' . $remote . '.zip',
            'tested'      => '6.9',
            'requires_php' => '7.4',
            'icons'       => [],
            'banners'     => [],
            'banners_rtl' => [],
            'compatibility' => new stdClass(),
        ];

        if (version_compare($installed, $remote, '<')) {
            $transient->response[$plugin_file] = $item;
        } else {
            // Importante: popolare no_update permette a WP di mostrare i controlli per auto-update
            $transient->no_update[$plugin_file] = $item;
        }

        $transient->checked[$plugin_file] = $installed;
    }

    private function get_github_version() {
        $cached = get_transient('marrison_updater_github_version');
        if ($cached !== false) return $cached;

        if (get_transient('marrison_github_fetch_failed') !== false) {
            return false;
        }

        $response = wp_remote_get('https://api.github.com/repos/marrisonlab/marrison-custom-updater/releases/latest', [
            'timeout' => 5,
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/MarrisonCustomUpdater'
            ]
        ]);

        if (is_wp_error($response)) {
            set_transient('marrison_github_fetch_failed', 1, 5 * MINUTE_IN_SECONDS);
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['tag_name'])) return false;

        $version = str_replace('v', '', $body['tag_name']);
        set_transient('marrison_updater_github_version', $version, 6 * HOUR_IN_SECONDS);

        return $version;
    }

    private function find_plugin_file($slug, $name = '') {
        $slug = trim($slug);
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = get_plugins();
        
        // 1. Cerca corrispondenza esatta della cartella (o nome file per plugin singoli)
        foreach ($plugins as $file => $data) {
            $dir = dirname($file);
            if ($dir === '.' || $dir === '') $dir = basename($file, '.php');
            if ($dir === $slug) return $file;
        }

        // 2. Tentativo secondario: Cerca corrispondenza esatta del file .php principale
        // Utile se la cartella ha un nome diverso ma il file del plugin corrisponde allo slug
        foreach ($plugins as $file => $data) {
             if (basename($file, '.php') === $slug) return $file;
        }

        // 3. Tentativo terziario: Cerca per Nome Plugin
        if (!empty($name)) {
            foreach ($plugins as $file => $data) {
                // Confronto case-insensitive del nome
                $plugin_name = html_entity_decode($data['Name']);
                if (strcasecmp($plugin_name, $name) === 0) {
                    // FIX SPECIFICO: WPCode Lite (insert-headers-and-footers)
                    // Evita che venga rilevato erroneamente se si cerca un altro plugin privato con nome simile
                    if (strpos($file, 'insert-headers-and-footers/ihaf.php') !== false) {
                        if ($slug !== 'insert-headers-and-footers' && $slug !== 'ihaf') {
                            continue;
                        }
                    }
                    return $file;
                }
            }
        }

        return null;
    }

    public function plugin_info($false, $action, $args) {
        if ($action !== 'plugin_information') return $false;
        
        // Controlla se è il nostro plugin
        if ($args->slug !== 'marrison-custom-updater') return $false;

        // Leggi le informazioni dal file locale invece che da GitHub
        $readme_file = plugin_dir_path(__FILE__) . 'readme.txt';
        if (!file_exists($readme_file)) return $false;
        
        $readme = file_get_contents($readme_file);
        if (empty($readme)) return $false;

        // Parsa il readme.txt
        return $this->parse_readme($readme);
    }

    private function parse_readme($readme) {
        $info = new stdClass();
        
        // Estrai la descrizione
        if (preg_match('/== Description ==\s*(.*?)\s*== /s', $readme, $match)) {
            $description = trim($match[1]);
            // Converti semplice markdown in HTML
            $description = $this->markdown_to_html($description);
            $info->description = $description;
        } else {
            $info->description = '';
        }

        // Estrai il changelog
        if (preg_match('/== Changelog ==\s*(.*?)$/s', $readme, $match)) {
            $changelog = trim($match[1]);
            // Converti semplice markdown in HTML
            $changelog = $this->markdown_to_html($changelog);
            $info->changelog = $changelog;
        } else {
            $info->changelog = '';
        }

        // Estrai metadata dal readme
        $version = '1.0.0';
        if (preg_match('/Stable tag:\s*([0-9\.]+)/i', $readme, $match)) {
            $version = trim($match[1]);
        }

        $tested = '6.0';
        if (preg_match('/Tested up to:\s*([0-9\.]+)/i', $readme, $match)) {
            $tested = trim($match[1]);
        }

        $requires = '5.0';
        if (preg_match('/Requires at least:\s*([0-9\.]+)/i', $readme, $match)) {
            $requires = trim($match[1]);
        }

        $requires_php = '7.4';
        if (preg_match('/Requires PHP:\s*([0-9\.]+)/i', $readme, $match)) {
            $requires_php = trim($match[1]);
        }

        // Dati base
        $info->name = 'WP Master Updater';
        $info->slug = 'marrison-custom-updater';
        $info->version = $version;
        $info->author = 'Angelo Marra';
        $info->author_profile = 'https://marrisonlab.com';
        $info->plugin_url = 'https://github.com/marrisonlab/marrison-custom-updater';
        $info->download_url = 'https://github.com/marrisonlab/marrison-custom-updater/archive/refs/tags/v' . $version . '.zip';
        $info->requires_php = $requires_php;
        $info->requires = $requires;
        $info->tested = $tested;
        $info->last_updated = current_time('mysql');
        $info->homepage = 'https://github.com/marrisonlab/marrison-custom-updater';
        $info->active_installs = 0;
        $info->rating = 100;
        $info->ratings = array(5 => 100);
        $info->num_ratings = 0;
        $info->support_url = 'https://github.com/marrisonlab/marrison-custom-updater/issues';
        $info->sections = array(
            'description' => $info->description ? $info->description : 'Plugin per aggiornamenti personalizzati',
            'changelog' => $info->changelog ? $info->changelog : 'Consultare il repository GitHub'
        );

        return $info;
    }

    private function markdown_to_html($text) {
        // Converti header changelog (= 1.0.0 =)
        $text = preg_replace('/^=\s*(.*?)\s*=\s*$/m', '<h4>$1</h4>', $text);
        
        // Converti grassetto (**text**)
        $text = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $text);
        
        // Converti liste puntate (* item)
        // Aggiungi newline prima delle liste per sicurezza
        $text = preg_replace('/^\*\s+(.*?)$/m', '<li>$1</li>', $text);
        
        // Avvolgi liste (questo è un po\' grezzo ma funziona per readme standard)
        // Cerchiamo gruppi di <li> e li avvolgiamo in <ul>
        $text = preg_replace('/(<li>.*?<\/li>(\s*<li>.*?<\/li>)*)/s', '<ul>$1</ul>', $text);
        
        // Converti paragrafi (doppio newline)
        $text = wpautop($text);
        
        return $text;
    }

    /* ===================== PLUGIN ACTION LINKS ===================== */

    public function add_marrison_action_links($actions, $plugin_file) {
        // Aggiungi il link impostazioni solo alla riga del Marrison Custom Updater.
        if ($plugin_file !== plugin_basename(__FILE__)) {
            return $actions;
        }

        // Link alla pagina del Marrison Updater
        $actions['marrison_settings'] = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=marrison-updater')),
            esc_html__('Setting', 'marrison-custom-updater')
        );

        return $actions;
    }

    public function add_plugin_row_meta($links, $file) {
        if (strpos($file, 'custom_updater.php') !== false || strpos($file, 'marrison-custom-updater') !== false) {
            $row_meta = [
                'docs' => '<a href="https://github.com/marrisonlab/marrison-custom-updater" target="_blank" aria-label="' . esc_attr__('Visita il sito del plugin', 'marrison-custom-updater') . '">' . esc_html__('Visita il sito del plugin', 'marrison-custom-updater') . '</a>',
            ];
            return array_merge($links, $row_meta);
        }
        return $links;
    }

    /* ===================== REAL UPDATE ENGINE ===================== */

    /* ===================== BACKUP & ROLLBACK ===================== */

    public function restore_plugin() {
        // Aumenta limiti esecuzione per evitare crash durante operazioni file
        @ignore_user_abort(true);
        @set_time_limit(0);

        $filename = sanitize_file_name($_GET['file'] ?? '');
        $slug_param = sanitize_text_field($_GET['slug'] ?? '');
        
        if (!empty($filename)) {
             check_admin_referer('marrison_restore_' . $filename);
        } elseif (!empty($slug_param)) {
             check_admin_referer('marrison_restore_' . $slug_param);
             // Fallback per vecchi link: cerca backup standard
             $filename = $slug_param . '-backup.zip';
             
             // Se non esiste, cerca se c'Ã¨ un backup versionato
             $backup_dir = $this->get_backup_dir();
             if (!file_exists($backup_dir . '/' . $filename)) {
                 $files = glob($backup_dir . '/' . $slug_param . '-*-backup.zip');
                 if (!empty($files)) {
                     $filename = basename($files[0]);
                 }
             }
        } else {
             wp_die('Missing parameters');
        }

        if (!current_user_can('install_plugins')) wp_die(__('Insufficient permissions', 'marrison-custom-updater'));

        $result = $this->perform_restore($filename);

        if (is_wp_error($result)) {
            wp_die(esc_html__('Errore ripristino backup: ', 'marrison-custom-updater') . esc_html($result->get_error_message()));
        }
        
        $redirect_to = !empty($_REQUEST['redirect_to']) ? $_REQUEST['redirect_to'] : admin_url('admin.php?page=marrison-updater-backups&restored=' . $result);
        wp_redirect($redirect_to);
        exit;
    }

    public function restore_plugin_ajax() {
        @ignore_user_abort(true);
        @set_time_limit(0);

        $filename = sanitize_file_name($_POST['filename'] ?? '');
        $nonce = $_POST['nonce'] ?? '';

        if (!wp_verify_nonce($nonce, 'marrison_restore_' . $filename)) {
            wp_send_json_error('Security check failed');
        }

        if (!current_user_can('install_plugins')) {
            wp_send_json_error('Insufficient permissions');
        }

        if (empty($filename)) {
            wp_send_json_error('Missing filename');
        }

        $result = $this->perform_restore($filename);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(['slug' => $result]);
    }

    /* ===================== ACTIONS ===================== */

    public function update_plugin() {
        $slug = sanitize_text_field($_GET['slug'] ?? '');
        check_admin_referer('marrison_update_' . $slug);

        $result = $this->perform_update($slug);
        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()));
        }

        wp_redirect(admin_url('admin.php?page=marrison-updater&updated=' . $slug));
        exit;
    }

    public function bulk_update() {
        check_admin_referer('marrison_bulk_update');
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permessi insufficienti', 'marrison-custom-updater'));
        }

        $lock = $this->mcu_acquire_update_lock('manual_bulk_update', [
            'plugins' => $_POST['plugins'] ?? [],
            'themes'  => $_POST['themes'] ?? [],
        ]);
        if (is_wp_error($lock)) {
            wp_die(esc_html($lock->get_error_message()));
        }
        $snapshot = $this->mcu_capture_active_plugin_snapshot(['operation' => 'manual_bulk_update']);

        $updated = [];
        
        // Update Plugins
        foreach ($_POST['plugins'] ?? [] as $slug) {
            $slug = sanitize_text_field($slug);
            $result = $this->perform_update($slug);
            if ($result === true) {
                $updated[] = $slug;
            }
        }

        // Update Themes
        if (!empty($_POST['themes'])) {
            $theme_updates = $this->get_available_theme_updates();
            foreach ($_POST['themes'] as $slug) {
                $slug = sanitize_text_field($slug);
                $download_url = '';
                foreach ($theme_updates as $u) {
                    if ($u['slug'] === $slug) {
                        $download_url = $u['download_url'];
                        break;
                    }
                }
                
                $result = $download_url ? $this->perform_theme_update($slug, $download_url) : false;
                if ($result === true) {
                    $updated[] = $slug;
                }
            }
        }

        $this->mcu_flush_update_caches(['operation' => 'manual_bulk_update']);
        $this->mcu_restore_active_plugin_snapshot($snapshot, ['operation' => 'manual_bulk_update']);
        $this->mcu_release_update_lock($lock);

        $query = http_build_query(['bulk_updated' => $updated]);
        wp_redirect(admin_url('admin.php?page=marrison-updater&' . $query));
        exit;
    }

    public function bulk_install() {
        check_admin_referer('marrison_bulk_install');
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permessi insufficienti', 'marrison-custom-updater'));
        }

        $lock = $this->mcu_acquire_update_lock('manual_bulk_install', ['plugins' => $_POST['plugins'] ?? []]);
        if (is_wp_error($lock)) {
            wp_die(esc_html($lock->get_error_message()));
        }
        $snapshot = $this->mcu_capture_active_plugin_snapshot(['operation' => 'manual_bulk_install']);

        $installed = [];
        foreach ($_POST['plugins'] ?? [] as $slug) {
            // perform_update gestisce anche l'installazione (scarica e copia)
            $slug = sanitize_text_field($slug);
            $result = $this->perform_update($slug);
            if ($result === true) {
                $installed[] = $slug;
            }
        }

        $this->mcu_flush_update_caches(['operation' => 'manual_bulk_install']);
        $this->mcu_restore_active_plugin_snapshot($snapshot, ['operation' => 'manual_bulk_install']);
        $this->mcu_release_update_lock($lock);

        $query = http_build_query(['installed' => $installed]);
        wp_redirect(admin_url('admin.php?page=marrison-updater-installer&' . $query));
        exit;
    }

    public function delete_internal_cache() {
        delete_transient('marrison_available_updates');
        delete_transient('marrison_available_updates_v2');
        delete_transient('marrison_available_theme_updates');
        delete_transient('marrison_updates_fetch_failed');
        delete_transient('marrison_theme_updates_fetch_failed');
    }

    public function clear_cache() {
        check_admin_referer('marrison_clear_cache');
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permessi insufficienti', 'marrison-custom-updater'));
        }
        
        // Pulisci cache interna
        $this->delete_internal_cache();
        
        // Pulisci cache GitHub
        delete_transient('marrison_updater_github_version');
        
        $this->mcu_flush_update_caches(['operation' => 'manual_clear_cache']);
        
        // Forza ricaricamento dagli aggiornamenti
        wp_update_plugins();
        wp_update_themes();
        
        // Pulisci opzioni di cache interna
        delete_option('marrison_known_private_slugs');
        
        // Reindirizza con messaggio di successo
        $redirect = !empty($_REQUEST['redirect_to']) ? $_REQUEST['redirect_to'] : admin_url('admin.php?page=marrison-updater&cache_cleared=1');
        wp_redirect($redirect);
        exit;
    }

    public function force_check_mcu() {
        check_admin_referer('marrison_force_check_mcu');
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permessi insufficienti', 'marrison-custom-updater'));
        }
        
        // Pulisce cache interna
        $this->delete_internal_cache();

        // Pulisce cache specifica GitHub
        delete_transient('marrison_updater_github_version');
        
        $this->mcu_flush_update_caches(['operation' => 'force_check_mcu']);
        wp_update_plugins();
        wp_update_themes();
        
        $redirect = !empty($_REQUEST['redirect_to']) ? $_REQUEST['redirect_to'] : admin_url('admin.php?page=marrison-updater&mcu_checked=1');
        wp_redirect($redirect);
        exit;
    }




    public function update_last_plugin_update_timestamp() {
        update_option('marrison_last_plugins_update_time', current_time('mysql'));
    }








    public function download_repo_file() {
        check_admin_referer('marrison_download_repo_file');
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permessi insufficienti', 'marrison-custom-updater'));
        }

        $type = $_POST['file_type'] ?? 'plugin';
        
        if ($type === 'plugin') {
            $content = '<?php
// Silent is golden.
// Directory listing prevention
header("HTTP/1.0 403 Forbidden");
?>';
            // In realta qui servirebbe lo script index.php che fa il listing
            // Ma per ora mettiamo un placeholder o il contenuto reale se lo abbiamo
            // Il codice originale probabilmente intendeva scaricare un file index.php "modello"
            
            // Creiamo un index.php semplice che fa il listing dei file zip
            $content = '<?php
$files = glob("*.zip");
$data = [];
foreach ($files as $file) {
    $slug = basename($file, ".zip");
    // Cerca info basilari
    $data[$slug] = [
        "name" => $slug,
        "version" => "1.0.0", // Fallback
        "download_url" => (isset($_SERVER["HTTPS"]) ? "https://" : "http://") . $_SERVER["HTTP_HOST"] . $_SERVER["REQUEST_URI"] . $file
    ];
}
header("Content-Type: application/json");
echo json_encode($data);
';
            $filename = 'index.php';
        } else {
            wp_die('Tipo file non supportato');
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $content;
        exit;
    }

    /* ===================== AJAX HANDLER ===================== */

    public function update_plugin_ajax() {
        $slug = sanitize_text_field($_POST['slug'] ?? '');
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        
        $nonce_valid = wp_verify_nonce($nonce, 'marrison_update_' . $slug) || 
                       wp_verify_nonce($nonce, 'marrison_bulk_update') ||
                       wp_verify_nonce($nonce, 'marrison_update_marrison-custom-updater');
        
        if (!$nonce_valid) {
            wp_send_json_error('Security check failed');
        }

        // Verifica i permessi
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        include_once ABSPATH . 'wp-admin/includes/plugin.php';

        $plugin_file_before = '';
        $name = '';
        if ($slug === 'marrison-custom-updater') {
            $plugin_file_before = plugin_basename(__FILE__);
        } else {
            // Trova il nome per migliorare la ricerca file
            $updates = $this->get_available_updates();
            foreach($updates as $u) {
                if ($u['slug'] === $slug) {
                    $name = $u['name'] ?? '';
                    break;
                }
            }
            $plugin_file_before = $this->find_plugin_file($slug, $name);
        }
        $was_active = $plugin_file_before && is_plugin_active($plugin_file_before);
        $was_network_active = $plugin_file_before && is_multisite() && is_plugin_active_for_network($plugin_file_before);

        $result = false;
        
        if ($slug === 'marrison-custom-updater') {
            $transient = get_site_transient('update_plugins');
            if (isset($transient->response[plugin_basename(__FILE__)])) {
                $update = $transient->response[plugin_basename(__FILE__)];
                $result = $this->perform_self_update($update->package);
            }
        } else {
            $result = $this->perform_update($slug);
        }

        if ($result === true) {
            $plugin_file_after = $plugin_file_before;
            if (!$plugin_file_after || !file_exists(WP_PLUGIN_DIR . '/' . $plugin_file_after)) {
                $plugin_file_after = $this->find_plugin_file($slug, $name);
            }

            $this->mcu_flush_update_caches(['operation' => 'private_plugin_ajax', 'slug' => $slug]);

            if ($was_active && $plugin_file_after) {
                if (!is_plugin_active($plugin_file_after)) {
                    // Reactivate silently: do not fire activation hooks in an update context
                    $activate = activate_plugin($plugin_file_after, '', $was_network_active, true);
                    if (is_wp_error($activate)) {
                        $this->mcu_log_event('warning', 'private_plugin_reactivation_failed', [
                            'slug'  => $slug,
                            'file'  => $plugin_file_after,
                            'error' => $activate,
                        ]);
                    }
                }
            }

            $this->check_for_available_updates();
            wp_send_json_success('Plugin aggiornato con successo');
        } elseif (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_error('Errore durante l\'aggiornamento del plugin');
        }
    }

    public function mcu_remote_update_plugin($response, $parameters) {
        if (!\MarrisonCustomUpdater\MaintenanceClient\Settings::repository_config_managed()) {
            return [
                'success' => false,
                'error_code' => 'client_not_authorized',
                'message' => __('Operazione bloccata: il client MCU non è autorizzato da Commander.', 'marrison-custom-updater'),
            ];
        }

        @ignore_user_abort(true);
        @set_time_limit(0);

        $parameters = is_array($parameters) ? $parameters : [];
        $type = sanitize_key((string) ($parameters['type'] ?? ''));
        $slug = $this->mcu_safe_private_update_slug((string) ($parameters['slug'] ?? ''));
        $name = sanitize_text_field((string) ($parameters['name'] ?? ''));
        $new_version = sanitize_text_field((string) ($parameters['new_version'] ?? ''));
        $package = isset($parameters['package']) ? esc_url_raw((string) $parameters['package']) : '';
        $file = $this->mcu_safe_remote_plugin_file((string) ($parameters['plugin_file'] ?? ''));

        include_once ABSPATH . 'wp-admin/includes/plugin.php';

        if ($file === '' && $slug !== '') {
            $file = (string) $this->find_plugin_file($slug, $name);
        }
        if ($slug === '' && $file !== '') {
            $slug = dirname($file);
            if ($slug === '.' || $slug === '') {
                $slug = basename($file, '.php');
            }
            $slug = $this->mcu_safe_private_update_slug($slug);
        }
        if ($file === '' || !file_exists(WP_PLUGIN_DIR . '/' . $file)) {
            return [
                'success' => false,
                'error_code' => 'plugin_not_found',
                'message' => __('Plugin non trovato sul sito client.', 'marrison-custom-updater'),
            ];
        }
        if ($slug === 'marrison-custom-updater' || $file === plugin_basename(__FILE__)) {
            return [
                'success' => false,
                'error_code' => 'self_update_not_supported',
                'message' => __('Aggiornamento diretto di MCU non supportato in questa modalita.', 'marrison-custom-updater'),
            ];
        }

        $plugins = get_plugins();
        $before = isset($plugins[$file]) ? $plugins[$file] : [];
        $old_version = isset($before['Version']) ? (string) $before['Version'] : '';
        $plugin_name = $name !== '' ? $name : (isset($before['Name']) ? (string) $before['Name'] : $slug);
        $was_active = is_plugin_active($file);
        $was_network_active = is_multisite() && function_exists('is_plugin_active_for_network') && is_plugin_active_for_network($file);
        if ($this->mcu_is_plugin_update_excluded($slug, $file, $plugin_name, $parameters)) {
            return [
                'success' => false,
                'error_code' => 'plugin_update_excluded',
                'message' => __('Plugin escluso dagli aggiornamenti.', 'marrison-custom-updater'),
                'plugin' => [
                    'file' => $file,
                    'slug' => $slug,
                    'name' => $plugin_name,
                    'old_version' => $old_version,
                    'new_version' => $new_version,
                ],
            ];
        }

        if ($type === 'private') {
            $result = $this->perform_update($slug);
        } else {
            $result = $this->mcu_remote_update_official_plugin($file, $new_version, $package);
            $type = 'wordpress_org';
        }

        if (is_wp_error($result)) {
            return [
                'success' => false,
                'error_code' => $result->get_error_code() ?: 'plugin_update_failed',
                'message' => $result->get_error_message(),
                'plugin' => [
                    'file' => $file,
                    'slug' => $slug,
                    'name' => $plugin_name,
                    'old_version' => $old_version,
                    'new_version' => $new_version,
                    'type' => $type,
                ],
            ];
        }
        if ($result !== true) {
            return [
                'success' => false,
                'error_code' => 'plugin_update_failed',
                'message' => __('Aggiornamento plugin non completato.', 'marrison-custom-updater'),
                'plugin' => [
                    'file' => $file,
                    'slug' => $slug,
                    'name' => $plugin_name,
                    'old_version' => $old_version,
                    'new_version' => $new_version,
                    'type' => $type,
                ],
            ];
        }

        if ($was_active && !is_plugin_active($file)) {
            $activate = activate_plugin($file, '', $was_network_active, true);
            if (is_wp_error($activate)) {
                $this->mcu_log_event('warning', 'remote_plugin_reactivation_failed', [
                    'file' => $file,
                    'error' => $activate,
                ]);
            }
        }

        clearstatcache();
        if (function_exists('wp_clean_plugins_cache')) {
            wp_clean_plugins_cache(true);
        }
        $this->check_for_available_updates();
        $plugins_after = get_plugins();
        $after = isset($plugins_after[$file]) ? $plugins_after[$file] : [];
        $installed_version = isset($after['Version']) ? (string) $after['Version'] : $new_version;

        return [
            'success' => true,
            'message' => sprintf(__('Plugin aggiornato: %s.', 'marrison-custom-updater'), $plugin_name),
            'plugin' => [
                'file' => $file,
                'slug' => $slug,
                'name' => $plugin_name,
                'old_version' => $old_version,
                'new_version' => $new_version,
                'installed_version' => $installed_version,
                'type' => $type,
                'was_active' => $was_active,
                'is_active' => is_plugin_active($file),
            ],
        ];
    }

    private function mcu_remote_update_official_plugin($file, $new_version = '', $package = '') {
        return $this->mcu_run_update_guard('commander_official_plugin_update', [
            'file' => $file,
            'new_version' => $new_version,
        ], function() use ($file, $new_version, $package) {
            include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
            include_once ABSPATH . 'wp-admin/includes/file.php';

            $plugins = get_plugins();
            if (!isset($plugins[$file])) {
                return new WP_Error('plugin_not_found', __('Plugin non trovato.', 'marrison-custom-updater'));
            }

            $plugin_slug = dirname($file);
            if ($plugin_slug === '.' || $plugin_slug === '') {
                $plugin_slug = basename($file, '.php');
            }
            $current_version = isset($plugins[$file]['Version']) ? (string) $plugins[$file]['Version'] : '';
            $plugin_name = isset($plugins[$file]['Name']) ? (string) $plugins[$file]['Name'] : $plugin_slug;
            if ($this->mcu_is_plugin_update_excluded($plugin_slug, $file, $plugin_name)) {
                return new WP_Error('plugin_update_excluded', __('Plugin escluso dagli aggiornamenti.', 'marrison-custom-updater'));
            }
            $this->create_backup($plugin_slug, $current_version, 'plugin', $file);

            $transient = get_site_transient('update_plugins');
            if (!is_object($transient)) {
                $transient = new stdClass();
            }
            if (!isset($transient->response) || !is_array($transient->response)) {
                $transient->response = [];
            }

            if (!isset($transient->response[$file]) && $package !== '') {
                $obj = new stdClass();
                $obj->slug = $plugin_slug;
                $obj->plugin = $file;
                $obj->package = $package;
                $obj->new_version = $new_version;
                $obj->url = '';
                $transient->response[$file] = $obj;
                set_site_transient('update_plugins', $transient);
            } elseif (!isset($transient->response[$file])) {
                wp_update_plugins();
                $transient = get_site_transient('update_plugins');
                if (!is_object($transient) || empty($transient->response[$file])) {
                    return new WP_Error('update_not_found', __('Aggiornamento WordPress.org non trovato.', 'marrison-custom-updater'));
                }
            }

            $skin = new Automatic_Upgrader_Skin();
            $upgrader = new Plugin_Upgrader($skin);
            $result = $upgrader->upgrade($file);

            if (is_wp_error($result)) {
                return $result;
            }
            if (!$result) {
                return new WP_Error('plugin_update_failed', __('Update failed', 'marrison-custom-updater'));
            }

            update_option('marrison_last_plugins_update_time', current_time('mysql'));
            return true;
        });
    }

    private function mcu_safe_remote_plugin_file($file) {
        $file = str_replace('\\', '/', sanitize_text_field((string) $file));
        $file = ltrim($file, '/');
        if ($file === '' || substr($file, -4) !== '.php') {
            return '';
        }
        if (function_exists('validate_file') && validate_file($file) !== 0) {
            return '';
        }
        if (strpos($file, '..') !== false || !preg_match('/^[A-Za-z0-9._\/-]+$/', $file)) {
            return '';
        }
        return $file;
    }

    /* ===================== THEME AJAX HANDLER ===================== */

    public function update_private_theme_ajax() {
        // Verifica il nonce
        $slug = sanitize_text_field($_POST['slug'] ?? '');
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        
        $nonce_valid = wp_verify_nonce($nonce, 'marrison_update_theme_' . $slug) || 
                       wp_verify_nonce($nonce, 'marrison_bulk_update');
        
        if (!$nonce_valid) {
            wp_send_json_error('Security check failed');
        }

        // Verifica i permessi
        if (!current_user_can('update_themes')) {
            wp_send_json_error('Insufficient permissions');
        }

        // Carica classi
        include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        include_once ABSPATH . 'wp-admin/includes/theme.php';

        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Theme_Upgrader($skin);
        
        // Trova l'URL di download
        $download_url = '';
        $updates = $this->get_available_theme_updates();
        $theme_obj = wp_get_theme($slug);
        
        foreach ($updates as $u) {
            // Check 1: Corrispondenza slug esatta
            if ($u['slug'] === $slug) {
                $download_url = $u['download_url'];
                break;
            }
            
            // Check 2: Se il tema è installato e lo slug non corrisponde, cerca per Nome
            if ($theme_obj->exists() && strcasecmp($theme_obj->get('Name'), $u['name']) === 0) {
                $download_url = $u['download_url'];
                break;
            }
            
            // Check 3: Cerca per TextDomain
            if ($theme_obj->exists() && $theme_obj->get('TextDomain') === $u['slug']) {
                $download_url = $u['download_url'];
                break;
            }
        }
        
        if (empty($download_url)) {
            wp_send_json_error('URL download non trovato per lo slug: ' . $slug);
        }

        // Esegui l'aggiornamento
        $result = $this->perform_theme_update($slug, $download_url);
        if ($result === true) {
            $this->check_for_available_updates();
            wp_send_json_success('Tema aggiornato con successo');
        } elseif (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_error('Errore durante l\'aggiornamento del tema');
        }
    }

    public function bulk_update_private_themes_ajax() {
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        $themes = isset($_POST['themes']) ? array_map('sanitize_text_field', $_POST['themes']) : [];
        
        if (!wp_verify_nonce($nonce, 'marrison_bulk_update')) {
            wp_die('Security check failed');
        }

        if (!current_user_can('update_themes')) {
            wp_die('Insufficient permissions');
        }

        if (empty($themes)) {
            wp_send_json_error('Nessun tema selezionato');
        }

        $results = [];
        $success_count = 0;
        
        foreach ($themes as $slug) {
            $download_url = '';
            foreach ($this->get_available_theme_updates() as $u) {
                if ($u['slug'] === $slug) {
                    $download_url = $u['download_url'];
                    break;
                }
            }
            
            $result = $download_url ? $this->perform_theme_update($slug, $download_url) : false;
            if ($result === true) {
                $results[$slug] = true;
                $success_count++;
            } else {
                $results[$slug] = is_wp_error($result) ? $result->get_error_message() : false;
            }
        }

        if ($success_count > 0) {
            $this->check_for_available_updates();
            wp_send_json_success([
                'message' => sprintf('%d temi aggiornati con successo', $success_count),
                'results' => $results,
                'success_count' => $success_count,
                'total_count' => count($themes)
            ]);
        } else {
            wp_send_json_error('Nessun tema è stato aggiornato');
        }
    }

    /* ===================== BULK UPDATE AJAX HANDLER ===================== */

    public function bulk_update_ajax() {
        // Verifica il nonce
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        $plugins = isset($_POST['plugins']) ? array_map('sanitize_text_field', $_POST['plugins']) : [];
        
        if (!wp_verify_nonce($nonce, 'marrison_bulk_update')) {
            wp_die('Security check failed');
        }

        // Verifica i permessi
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        if (empty($plugins)) {
            wp_send_json_error('Nessun plugin selezionato');
        }

        $lock = $this->mcu_acquire_update_lock('private_plugins_bulk_ajax', ['plugins' => $plugins]);
        if (is_wp_error($lock)) {
            wp_send_json_error($lock->get_error_message());
        }
        $snapshot = $this->mcu_capture_active_plugin_snapshot([
            'operation' => 'private_plugins_bulk_ajax',
            'plugins'   => $plugins,
        ]);

        $results = [];
        $success_count = 0;
        
        $available_updates = $this->get_available_updates();

        foreach ($plugins as $slug) {
            $result = false;
            
            // Trova il nome per la ricerca file e stato attivazione
            $name = '';
            foreach ($available_updates as $u) {
                if ($u['slug'] === $slug) {
                    $name = $u['name'] ?? '';
                    break;
                }
            }

            $plugin_file_before = $this->find_plugin_file($slug, $name);
            $was_active = $plugin_file_before && is_plugin_active($plugin_file_before);
            $was_network_active = $plugin_file_before && is_multisite() && is_plugin_active_for_network($plugin_file_before);
            
            // Controlla se è il plugin stesso (WP Master Updater)
            if ($slug === 'marrison-custom-updater') {
                $transient = get_site_transient('update_plugins');
                if (isset($transient->response[plugin_basename(__FILE__)])) {
                    $update = $transient->response[plugin_basename(__FILE__)];
                    $result = $this->perform_self_update($update->package);
                }
            } else {
                $result = $this->perform_update($slug);
            }
            
            $results[$slug] = is_wp_error($result) ? $result->get_error_message() : (bool) $result;
            if ($result === true) {
                $success_count++;
                
                $this->mcu_flush_update_caches(['operation' => 'private_plugins_bulk_ajax', 'slug' => $slug]);

                if ($was_active) {
                    $plugin_file_after = $this->find_plugin_file($slug, $name);
                    if ($plugin_file_after) {
                        if (!is_plugin_active($plugin_file_after)) {
                            // Reactivate silently: do not fire activation hooks in an update context
                            $activate = activate_plugin($plugin_file_after, '', $was_network_active, true);
                            if (is_wp_error($activate)) {
                                $this->mcu_log_event('warning', 'private_bulk_plugin_reactivation_failed', [
                                    'slug'  => $slug,
                                    'file'  => $plugin_file_after,
                                    'error' => $activate,
                                ]);
                            }
                        }
                    }
                }
            }
        }

        if ($success_count > 0) {
            $this->mcu_flush_update_caches(['operation' => 'private_plugins_bulk_ajax']);
            $this->mcu_restore_active_plugin_snapshot($snapshot, ['operation' => 'private_plugins_bulk_ajax']);
            $this->mcu_release_update_lock($lock);
            $this->check_for_available_updates();

            wp_send_json_success([
                'message' => sprintf('%d plugin aggiornati con successo', $success_count),
                'results' => $results,
                'success_count' => $success_count,
                'total_count' => count($plugins)
            ]);
        } else {
            $this->mcu_flush_update_caches(['operation' => 'private_plugins_bulk_ajax']);
            $this->mcu_restore_active_plugin_snapshot($snapshot, ['operation' => 'private_plugins_bulk_ajax']);
            $this->mcu_release_update_lock($lock);
            wp_send_json_error('Nessun plugin Ã¨ stato aggiornato');
        }
    }

    /* ===================== ADMIN UI ===================== */

    

    

    

    


    

    


    


    

    

    

    


}
}

if (class_exists('MCU_Custom_Updater')) {
    new MCU_Custom_Updater;
}

/**
 * Fix definitivo GitHub updater:
 * - rinomina la cartella del plugin con suffisso versione (es. -1.9)
 * - forza il refresh della cache plugin per mostrare la versione corretta in WP
 */
add_action( 'upgrader_process_complete', function ( $upgrader, $hook_extra ) {

    // Agisce solo sui plugin
    if ( empty( $hook_extra['type'] ) || $hook_extra['type'] !== 'plugin' ) {
        return;
    }

    $plugins_dir = WP_PLUGIN_DIR;
    $expected    = $plugins_dir . '/marrison-custom-updater';

    // Cerca directory tipo: marrison-custom-updater-*
    foreach ( glob( $plugins_dir . '/marrison-custom-updater-*', GLOB_ONLYDIR ) as $dir ) {

        // Se la directory corretta esiste giÃ , salta
        if ( is_dir( $expected ) ) {
            continue;
        }

        // Rinomina e pulisce la cache plugin
        if ( rename( $dir, $expected ) ) {
            wp_clean_plugins_cache( true );
        }

        break;
    }
}, 10, 2 );             
