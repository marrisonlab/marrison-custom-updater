<?php
trait MCU_Scheduling_Trait {
    public function add_custom_cron_intervals($schedules) {
        $schedules['weekly'] = [
            'interval' => 604800, // 7 days
            'display'  => __('Settimanale', 'marrison-custom-updater')
        ];
        $schedules['monthly'] = [
            'interval' => 2592000, // 30 days
            'display'  => __('Mensile', 'marrison-custom-updater')
        ];
        $schedules['biannual'] = [
            'interval' => 15552000, // 180 days (6 months)
            'display'  => __('Semestrale', 'marrison-custom-updater')
        ];
        return $schedules;
    }

    private function mcu_clear_scheduled_update_events() {
        wp_clear_scheduled_hook('marrison_scheduled_update_event');
        wp_clear_scheduled_hook('marrison_scheduled_update_event', ['automatic']);
    }

    private function mcu_normalize_auto_update_frequency($frequency) {
        $frequency = sanitize_key((string) $frequency);
        return in_array($frequency, ['daily', 'weekly', 'monthly', 'biannual'], true) ? $frequency : 'daily';
    }

    private function mcu_normalize_auto_update_time($time) {
        $time = trim((string) $time);
        return preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $time) ? $time : '00:00';
    }

    private function mcu_normalize_auto_update_month_day($day) {
        $day = absint($day);
        if ($day < 1) {
            $day = (int) current_time('j');
        }

        return max(1, min(31, $day));
    }

    private function mcu_auto_update_timezone() {
        return new DateTimeZone('Europe/Rome');
    }

    private function mcu_datetime_for_month_day($year, $month, $day, $hour, $minute, DateTimeZone $tz) {
        $year = (int) $year;
        $month = (int) $month;

        while ($month > 12) {
            $month -= 12;
            $year++;
        }

        while ($month < 1) {
            $month += 12;
            $year--;
        }

        $first_day = new DateTime(sprintf('%04d-%02d-01 00:00:00', $year, $month), $tz);
        $last_day = (int) $first_day->format('t');
        $target_day = min(max(1, (int) $day), $last_day);

        $target = clone $first_day;
        $target->setDate($year, $month, $target_day);
        $target->setTime((int) $hour, (int) $minute, 0);

        return $target;
    }

    private function mcu_next_automatic_update_datetime($frequency, $time, $from_timestamp = null) {
        $frequency = $this->mcu_normalize_auto_update_frequency($frequency);
        $time = $this->mcu_normalize_auto_update_time($time);
        [$hour, $minute] = array_map('absint', explode(':', $time));
        $tz = $this->mcu_auto_update_timezone();
        $now = $from_timestamp ? new DateTime('@' . (int) $from_timestamp) : new DateTime('now', $tz);
        $now->setTimezone($tz);

        if (in_array($frequency, ['monthly', 'biannual'], true)) {
            $month_day = $this->mcu_normalize_auto_update_month_day(get_option('marrison_auto_update_month_day', current_time('j')));
            $target = $this->mcu_datetime_for_month_day(
                (int) $now->format('Y'),
                (int) $now->format('n'),
                $month_day,
                $hour,
                $minute,
                $tz
            );

            if ($target <= $now) {
                $target = $this->mcu_datetime_for_month_day(
                    (int) $now->format('Y'),
                    (int) $now->format('n') + ('biannual' === $frequency ? 6 : 1),
                    $month_day,
                    $hour,
                    $minute,
                    $tz
                );
            }

            return $target;
        }

        $target = clone $now;
        $target->setTime($hour, $minute, 0);

        if ($target <= $now) {
            $target->modify('weekly' === $frequency ? '+1 week' : '+1 day');
        }

        return $target;
    }

    private function mcu_schedule_automatic_update_event($frequency, $time, $from_timestamp = null) {
        $frequency = $this->mcu_normalize_auto_update_frequency($frequency);
        $target_time = $this->mcu_next_automatic_update_datetime($frequency, $time, $from_timestamp);
        $args = ['automatic'];

        if (in_array($frequency, ['monthly', 'biannual'], true)) {
            return wp_schedule_single_event($target_time->getTimestamp(), 'marrison_scheduled_update_event', $args);
        }

        return wp_schedule_event($target_time->getTimestamp(), $frequency, 'marrison_scheduled_update_event', $args);
    }

    private function mcu_ensure_automatic_update_event_scheduled($context = []) {
        if (get_option('marrison_auto_update_enabled') !== 'yes') {
            return false;
        }

        if ($this->mcu_next_scheduled_update_event_timestamp() > 0) {
            return false;
        }

        $frequency = $this->mcu_normalize_auto_update_frequency(get_option('marrison_auto_update_frequency', 'daily'));
        $time = get_option('marrison_auto_update_time', '00:00');
        $scheduled = $this->mcu_schedule_automatic_update_event($frequency, $time, time() + 60);

        if (method_exists($this, 'mcu_log_event')) {
            $this->mcu_log_event($scheduled ? 'info' : 'warning', 'automatic_update_schedule_repaired', [
                'scheduled' => (bool) $scheduled,
                'frequency' => $frequency,
                'time' => $time,
                'context' => $context,
                'next_run' => $this->mcu_next_scheduled_update_event_timestamp(),
            ]);
        }

        return (bool) $scheduled;
    }

    public function mcu_repair_missing_automatic_update_schedule() {
        $this->mcu_ensure_automatic_update_event_scheduled([
            'context' => 'init',
        ]);
    }

    private function mcu_reschedule_calendar_update_if_needed($source = '') {
        if ('automatic' !== $source || get_option('marrison_auto_update_enabled') !== 'yes') {
            return;
        }

        $frequency = $this->mcu_normalize_auto_update_frequency(get_option('marrison_auto_update_frequency', 'daily'));
        if (!in_array($frequency, ['monthly', 'biannual'], true)) {
            return;
        }

        wp_clear_scheduled_hook('marrison_scheduled_update_event', ['automatic']);
        $this->mcu_schedule_automatic_update_event($frequency, get_option('marrison_auto_update_time', '00:00'), time() + 60);
    }

    private function mcu_next_scheduled_update_event_timestamp() {
        $automatic = wp_next_scheduled('marrison_scheduled_update_event', ['automatic']);
        $legacy = wp_next_scheduled('marrison_scheduled_update_event');

        if ($automatic && $legacy) {
            return min((int) $automatic, (int) $legacy);
        }

        return $automatic ? (int) $automatic : ($legacy ? (int) $legacy : 0);
    }

    private function mcu_cron_started_stale_after_seconds() {
        $minute = defined('MINUTE_IN_SECONDS') ? MINUTE_IN_SECONDS : 60;
        $stale_after = (int) apply_filters('mcu_cron_started_stale_after', 10 * $minute);
        if ($stale_after < 10 * $minute) {
            $stale_after = 10 * $minute;
        }
        return $stale_after;
    }

    private function mcu_local_mysql_to_timestamp($mysql) {
        $mysql = trim((string) $mysql);
        if ($mysql === '' || $mysql === '0000-00-00 00:00:00') {
            return 0;
        }

        try {
            $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
            $date = new DateTimeImmutable($mysql, $timezone);
            return $date->getTimestamp();
        } catch (Exception $e) {
            $timestamp = strtotime($mysql);
            return $timestamp ? (int) $timestamp : 0;
        }
    }

    public function mcu_recover_stale_cron_log_if_needed($context = []) {
        $last_log = get_option('marrison_last_cron_log', []);
        if (!is_array($last_log) || sanitize_key((string) ($last_log['status'] ?? '')) !== 'started') {
            return false;
        }

        $started_at = $this->mcu_local_mysql_to_timestamp($last_log['time'] ?? '');
        $stale_after = $this->mcu_cron_started_stale_after_seconds();
        if ($started_at <= 0 || (time() - $started_at) <= $stale_after) {
            return false;
        }

        $last_log['status'] = 'error';
        $last_log['message'] = sprintf(
            __('Esecuzione precedente interrotta o scaduta: nessuna chiusura entro %d minuti.', 'marrison-custom-updater'),
            (int) ceil($stale_after / 60)
        );
        $last_log['stale'] = true;
        $last_log['stale_after_seconds'] = $stale_after;
        $last_log['recovered_at'] = current_time('mysql');

        update_option('marrison_last_cron_log', $last_log);
        if (method_exists($this, 'mcu_log_event')) {
            $this->mcu_log_event('warning', 'cron_log_stale_recovered', [
                'started_at' => $last_log['time'] ?? '',
                'recovered_at' => $last_log['recovered_at'],
                'context' => $context,
            ]);
        }

        return true;
    }

    private function mcu_is_terminal_cron_log_status($status) {
        return in_array(sanitize_key((string) $status), ['completed', 'error', 'skipped'], true);
    }

    private function mcu_shutdown_failure_message() {
        $error = error_get_last();
        $fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];

        if (is_array($error) && in_array((int) ($error['type'] ?? 0), $fatal_types, true)) {
            return sprintf(
                __('Esecuzione interrotta: %s in %s:%d', 'marrison-custom-updater'),
                sanitize_text_field((string) ($error['message'] ?? 'errore fatale')),
                sanitize_text_field((string) ($error['file'] ?? 'unknown')),
                (int) ($error['line'] ?? 0)
            );
        }

        return __('Esecuzione interrotta prima della chiusura del job.', 'marrison-custom-updater');
    }

    private function mcu_mark_master_update_failed_if_running($message, $last_log = []) {
        $status = get_option('mcu_master_update_status', []);
        if (!is_array($status) || !in_array(sanitize_key((string) ($status['status'] ?? '')), ['queued', 'running'], true)) {
            return;
        }

        $status['status'] = 'failed';
        $status['finished_at'] = time();
        $status['message'] = $message;
        $status['stale'] = true;
        $status['last_log'] = is_array($last_log) ? $last_log : [];

        update_option('mcu_master_update_status', $status, false);
    }

    private function mcu_close_interrupted_cron_log($message, $context = []) {
        $last_log = get_option('marrison_last_cron_log', []);
        if (!is_array($last_log) || $this->mcu_is_terminal_cron_log_status($last_log['status'] ?? '')) {
            return false;
        }

        if (empty($last_log['time'])) {
            $last_log['time'] = current_time('mysql');
        }

        $last_log['status'] = 'error';
        $last_log['message'] = $message;
        $last_log['interrupted'] = true;
        $last_log['recovered_at'] = current_time('mysql');

        update_option('marrison_last_cron_log', $last_log);

        if (($context['source'] ?? '') === 'master') {
            $this->mcu_mark_master_update_failed_if_running($message, $last_log);
        }

        if (method_exists($this, 'mcu_log_event')) {
            $this->mcu_log_event('warning', 'cron_log_interrupted_closed', [
                'message' => $message,
                'context' => $context,
            ]);
        }

        return true;
    }

    public function save_scheduling_settings() {
        check_admin_referer('marrison_save_scheduling');

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permessi insufficienti.', 'marrison-custom-updater'));
        }

        $enabled = isset($_POST['marrison_auto_update_enabled']) ? 'yes' : 'no';
        $frequency = $this->mcu_normalize_auto_update_frequency($_POST['marrison_auto_update_frequency'] ?? 'daily');
        $time = $this->mcu_normalize_auto_update_time($_POST['marrison_auto_update_time'] ?? '00:00');
        $month_day = $this->mcu_normalize_auto_update_month_day($_POST['marrison_auto_update_month_day'] ?? get_option('marrison_auto_update_month_day', current_time('j')));
        $email = sanitize_email($_POST['marrison_auto_update_email']);
        $db_backup = isset($_POST['marrison_db_backup_with_updates']) ? 'yes' : 'no';
        $files_backup = isset($_POST['marrison_files_backup_with_updates']) ? 'yes' : 'no';
        $files_backup_skip_large = isset($_POST['marrison_files_backup_skip_large_files']) ? 'yes' : 'no';
        if ($files_backup === 'yes') {
            $db_backup = 'yes';
        }

        update_option('marrison_auto_update_enabled', $enabled);
        update_option('marrison_auto_update_frequency', $frequency);
        update_option('marrison_auto_update_time', $time);
        update_option('marrison_auto_update_month_day', $month_day);
        update_option('marrison_auto_update_email', $email);
        update_option('marrison_db_backup_with_updates', $db_backup);
        update_option('marrison_files_backup_with_updates', $files_backup);
        update_option('marrison_files_backup_skip_large_files', $files_backup_skip_large);

        $this->mcu_clear_scheduled_update_events();

        if ($enabled === 'yes') {
            $this->mcu_schedule_automatic_update_event($frequency, $time);
        }

        wp_redirect(admin_url('admin.php?page=marrison-updater-settings&tab=scheduling&settings-updated=saved'));
        exit;
    }

    public function send_test_email_ajax() {
        check_ajax_referer('marrison_test_email', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permessi insufficienti.', 'marrison-custom-updater'));
        }
        
        $email = sanitize_email($_POST['email']);
        if (!is_email($email)) {
            wp_send_json_error(__('Indirizzo email non valido.', 'marrison-custom-updater'));
        }

        // --- Dati Simulati per il Test ---
        $updated_plugins = [
            [
                'name' => 'Advanced Custom Fields',
                'old_version' => '6.0.0',
                'new_version' => '6.1.0',
                'type' => 'Ufficiale'
            ],
            [
                'name' => 'Marrison Core Plugin',
                'old_version' => '1.0.2',
                'new_version' => '1.0.3',
                'type' => 'Privato'
            ]
        ];

        $updated_themes = [
            [
                'name' => 'Astra',
                'old_version' => '4.0.0',
                'new_version' => '4.1.0'
            ]
        ];

        $failed_updates = [
            [
                'name' => 'Plugin Corrotto Esempio',
                'version' => '2.0.0',
                'type' => 'Privato',
                'error' => 'Archivio ZIP non valido o corrotto.'
            ]
        ];

        $skipped_updates = [
            [
                'name' => 'Plugin Moderno',
                'version' => '3.5.0',
                'reason' => 'Richiede PHP 8.2 (Attuale: ' . phpversion() . ')'
            ]
        ];

        $updated_translations = 5;

        // --- Raccolta Info Reali Stato Sito ---
        global $wpdb;
        $site_info = [
            'wp_version'   => get_bloginfo('version'),
            'php_version'  => phpversion(),
            'server'       => isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : 'N/A',
            'db_version'   => $wpdb->db_version(),
            'memory_limit' => ini_get('memory_limit'),
            'debug_mode'   => (defined('WP_DEBUG') && WP_DEBUG) ? 'Attivo' : 'Disattivo',
            'site_url'     => get_site_url(),
            'home_url'     => get_home_url()
        ];

        $all_plugins = get_plugins();
        $active_plugins = [];
        $inactive_plugins = [];

        foreach ($all_plugins as $path => $plugin) {
            if (is_plugin_active($path)) {
                $active_plugins[] = $plugin;
            } else {
                $inactive_plugins[] = $plugin;
            }
        }
        
        // --- Generazione Email (Stesso Template di run_scheduled_updates) ---
        $from_email = get_option('admin_email');
        $from_name = get_bloginfo('name');
        
        $subject = '[' . get_bloginfo('name') . '] Test Report Aggiornamento (Simulazione)';
        
        // Stili CSS inline
        $bg_color = "#2c3338";
        $container_bg = "#ffffff";
        
        $style_body_wrapper = "background-color: {$bg_color}; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 40px 0; width: 100%;";
        $style_container = "background-color: {$container_bg}; border-radius: 10px; max-width: 600px; margin: 0 auto; padding: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);";
        
        $style_h2 = "color: #2c3338; border-bottom: 2px solid #eee; padding-bottom: 10px; margin-bottom: 20px; margin-top: 30px;";
        $style_table = "width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 14px;";
        $style_th = "background-color: #f8f9fa; border: 1px solid #ddd; padding: 10px; text-align: left; font-weight: bold; color: #555;";
        $style_td = "border: 1px solid #ddd; padding: 10px;";
        $style_badge_priv = "background-color: #0073aa; color: #fff; padding: 3px 6px; border-radius: 3px; font-size: 11px; font-weight: bold; display: inline-block;";
        $style_badge_off = "background-color: #46b450; color: #fff; padding: 3px 6px; border-radius: 3px; font-size: 11px; font-weight: bold; display: inline-block;";
        $style_badge_theme = "background-color: #e65100; color: #fff; padding: 3px 6px; border-radius: 3px; font-size: 11px; font-weight: bold; display: inline-block;";
        $style_badge_error = "background-color: #d63638; color: #fff; padding: 3px 6px; border-radius: 3px; font-size: 11px; font-weight: bold; display: inline-block;";
        $style_badge_warn = "background-color: #ffb900; color: #333; padding: 3px 6px; border-radius: 3px; font-size: 11px; font-weight: bold; display: inline-block;";
        
        $style_footer = "margin-top: 30px; border-top: 1px solid #eee; padding-top: 15px; font-size: 12px; color: #777; text-align: center;";
        $style_section_title = "color: #444; margin-top: 20px; margin-bottom: 10px; font-size: 16px; border-left: 4px solid #0073aa; padding-left: 10px;";
        $style_section_title_error = "color: #d63638; margin-top: 20px; margin-bottom: 10px; font-size: 16px; border-left: 4px solid #d63638; padding-left: 10px;";
        $style_section_title_warn = "color: #e65100; margin-top: 20px; margin-bottom: 10px; font-size: 16px; border-left: 4px solid #ffb900; padding-left: 10px;";
        $style_list_item = "padding: 5px 0; border-bottom: 1px solid #eee; font-size: 13px;";

        $message_html = '<!DOCTYPE html><html><body style="' . $style_body_wrapper . '">';
        
        $message_html .= '<div style="' . $style_container . '">';
        
        // Header / Logo
        $message_html .= '<div style="text-align: center; margin-bottom: 20px;">';
        $custom_logo_id = get_theme_mod('custom_logo');
        $logo_src = '';
        $is_valid_image = false;
        if ($custom_logo_id) {
            $logo_data = wp_get_attachment_image_src($custom_logo_id, 'full');
            if ($logo_data) {
                $src = $logo_data[0];
                $path = parse_url($src, PHP_URL_PATH);
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if ($ext !== 'svg') {
                    $logo_src = $src;
                    $is_valid_image = true;
                }
            }
        }
        if (!$is_valid_image && function_exists('get_site_icon_url')) {
            $icon_url = get_site_icon_url(512);
            if ($icon_url) {
                $path = parse_url($icon_url, PHP_URL_PATH);
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if ($ext !== 'svg') {
                    $logo_src = $icon_url;
                    $is_valid_image = true;
                }
            }
        }
        if ($is_valid_image && !empty($logo_src)) {
                    $message_html .= '<img src="' . esc_url($logo_src) . '" alt="' . esc_attr(get_bloginfo('name')) . '" style="max-width: 200px; max-height: 100px; width: auto; height: auto; display: inline-block; border: 0; outline: none; text-decoration: none;">';
                } else {
            $message_html .= '<h1 style="margin: 0; color: #444; font-size: 24px;">' . get_bloginfo('name') . '</h1>';
        }
        $message_html .= '</div>';
        
        // --- STATUS BANNER (SIMULATO) ---
        // In questa simulazione assumiamo che ci siano problemi (failed/skipped)
        $banner_color = "#d63638"; // Rosso
        $banner_icon = "❌";
        $banner_title = "Attenzione: Rilevati Problemi";
        $banner_desc = "Alcuni aggiornamenti non sono andati a buon fine o sono stati saltati.";
        
        $message_html .= '<div style="background-color: ' . $banner_color . '; color: #ffffff; padding: 20px; text-align: center; border-radius: 6px; margin-bottom: 25px;">';
        $message_html .= '<h2 style="margin: 0; font-size: 22px; color: #ffffff; border: none; font-weight: bold;">' . $banner_icon . ' ' . $banner_title . '</h2>';
        $message_html .= '<p style="margin: 10px 0 0 0; font-size: 15px; opacity: 0.95;">' . $banner_desc . '</p>';
        $message_html .= '</div>';
        
        $message_html .= '<h2 style="' . $style_h2 . '">Report Aggiornamenti (SIMULAZIONE)</h2>';
        $message_html .= '<div style="background-color: #fff8e5; border: 1px solid #ffb900; padding: 10px; margin-bottom: 20px; color: #444; font-size: 13px;"><strong>NOTA:</strong> Questa è una mail di test generata con dati fittizi per mostrarti come apparirà il report reale in caso di errori o avvisi.</div>';
        
        // --- ERRORI (SIMULATI) ---
        if (!empty($failed_updates)) {
            $message_html .= '<h3 style="' . $style_section_title_error . '">❌ Aggiornamenti Falliti</h3>';
            $message_html .= '<table style="' . $style_table . '">';
            $message_html .= '<thead><tr>';
            $message_html .= '<th style="' . $style_th . '">Elemento</th>';
            $message_html .= '<th style="' . $style_th . '">Tipo</th>';
            $message_html .= '<th style="' . $style_th . '">Errore</th>';
            $message_html .= '</tr></thead><tbody>';
            foreach ($failed_updates as $f) {
                $message_html .= '<tr>';
                $message_html .= '<td style="' . $style_td . '"><strong>' . esc_html($f['name']) . '</strong></td>';
                $message_html .= '<td style="' . $style_td . '"><span style="' . $style_badge_error . '">' . esc_html($f['type']) . '</span></td>';
                $message_html .= '<td style="' . $style_td . '"><span style="color: #d63638;">' . esc_html($f['error']) . '</span></td>';
                $message_html .= '</tr>';
            }
            $message_html .= '</tbody></table>';
        }

        // --- SKIPPED (SIMULATI) ---
        if (!empty($skipped_updates)) {
            $message_html .= '<h3 style="' . $style_section_title_warn . '">⚠️ Aggiornamenti Saltati</h3>';
            $message_html .= '<table style="' . $style_table . '">';
            $message_html .= '<thead><tr>';
            $message_html .= '<th style="' . $style_th . '">Elemento</th>';
            $message_html .= '<th style="' . $style_th . '">Motivo</th>';
            $message_html .= '</tr></thead><tbody>';
            foreach ($skipped_updates as $s) {
                $message_html .= '<tr>';
                $message_html .= '<td style="' . $style_td . '"><strong>' . esc_html($s['name']) . '</strong></td>';
                $message_html .= '<td style="' . $style_td . '">' . esc_html($s['reason']) . '</td>';
                $message_html .= '</tr>';
            }
            $message_html .= '</tbody></table>';
        }
        
        // --- AGGIORNATI (SIMULATI) ---
        if (!empty($updated_plugins)) {
            $message_html .= '<h3 style="' . $style_section_title . '">🔌 Plugin Aggiornati</h3>';
            $message_html .= '<table style="' . $style_table . '">';
            $message_html .= '<thead><tr>';
            $message_html .= '<th style="' . $style_th . '">Plugin</th>';
            $message_html .= '<th style="' . $style_th . '">Tipo</th>';
            $message_html .= '<th style="' . $style_th . '">Versione</th>';
            $message_html .= '</tr></thead><tbody>';
            foreach ($updated_plugins as $p) {
                $badge_style = ($p['type'] === 'Privato') ? $style_badge_priv : $style_badge_off;
                $message_html .= '<tr>';
                $message_html .= '<td style="' . $style_td . '"><strong>' . esc_html($p['name']) . '</strong></td>';
                $message_html .= '<td style="' . $style_td . '"><span style="' . $badge_style . '">' . esc_html($p['type']) . '</span></td>';
                $message_html .= '<td style="' . $style_td . '">' . esc_html($p['old_version']) . ' &rarr; <strong>' . esc_html($p['new_version']) . '</strong></td>';
                $message_html .= '</tr>';
            }
            $message_html .= '</tbody></table>';
        }
        
        if (!empty($updated_themes)) {
            $message_html .= '<h3 style="' . $style_section_title . '">🎨 Temi Aggiornati</h3>';
            $message_html .= '<table style="' . $style_table . '">';
            $message_html .= '<thead><tr>';
            $message_html .= '<th style="' . $style_th . '">Tema</th>';
            $message_html .= '<th style="' . $style_th . '">Versione</th>';
            $message_html .= '</tr></thead><tbody>';
            foreach ($updated_themes as $t) {
                $message_html .= '<tr>';
                $message_html .= '<td style="' . $style_td . '"><strong>' . esc_html($t['name']) . '</strong> <span style="' . $style_badge_theme . '">Tema</span></td>';
                $message_html .= '<td style="' . $style_td . '">' . esc_html($t['old_version']) . ' &rarr; <strong>' . esc_html($t['new_version']) . '</strong></td>';
                $message_html .= '</tr>';
            }
            $message_html .= '</tbody></table>';
        }
        
        if ($updated_translations > 0) {
            $message_html .= '<div style="background-color: #f0f0f1; padding: 10px; border-left: 4px solid #0073aa; margin-top: 20px;">';
            $message_html .= '<strong>🌍 Traduzioni:</strong> Aggiornati ' . intval($updated_translations) . ' pacchetti di traduzione.';
            $message_html .= '</div>';
        }

        // --- SEZIONE STATO DEL SITO (REALE) ---
        $message_html .= '<h2 style="' . $style_h2 . '">Stato del Sito</h2>';
        $message_html .= '<table style="' . $style_table . ' width: 100%; border: none;"><tbody>';
        $message_html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>WordPress:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">v' . $site_info['wp_version'] . '</td></tr>';
        $message_html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>PHP:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">v' . $site_info['php_version'] . '</td></tr>';
        $message_html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Web Server:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">' . esc_html($site_info['server']) . '</td></tr>';
        $message_html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Memory Limit:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">' . esc_html($site_info['memory_limit']) . '</td></tr>';
        $message_html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Debug Mode:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">' . esc_html($site_info['debug_mode']) . '</td></tr>';
        $message_html .= '</tbody></table>';

        $message_html .= '<h3 style="' . $style_section_title . '">✅ Plugin Attivi (' . count($active_plugins) . ')</h3>';
        if (!empty($active_plugins)) {
            $message_html .= '<ul style="list-style-type: none; padding: 0; margin: 0;">';
            foreach ($active_plugins as $p) {
                $message_html .= '<li style="' . $style_list_item . '"><strong>' . esc_html($p['Name']) . '</strong> <span style="color: #777; font-size: 12px;">(v' . esc_html($p['Version']) . ')</span></li>';
            }
            $message_html .= '</ul>';
        }

        $message_html .= '<h3 style="' . $style_section_title . '; border-left-color: #d63638;">🚫 Plugin Inattivi (' . count($inactive_plugins) . ')</h3>';
        if (!empty($inactive_plugins)) {
            $message_html .= '<ul style="list-style-type: none; padding: 0; margin: 0;">';
            foreach ($inactive_plugins as $p) {
                $message_html .= '<li style="' . $style_list_item . '"><strong>' . esc_html($p['Name']) . '</strong> <span style="color: #777; font-size: 12px;">(v' . esc_html($p['Version']) . ')</span></li>';
            }
            $message_html .= '</ul>';
        }
        
        $message_html .= '<div style="' . $style_footer . '">';
        $message_html .= '<a href="' . esc_url(admin_url()) . '" style="color: #0073aa; text-decoration: none;">Accedi al sito</a><br><br>';
        $message_html .= '<span style="font-size: 11px; color: #999;">Powered By <a href="https://marrisonlab.com" target="_blank" style="color: #999; text-decoration: none;">Angelo Marra</a></span>';
        $message_html .= '</div>';
        
        $message_html .= '</div>'; // Chiusura Container
        $message_html .= '</body></html>';
        
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $from_name . ' <' . $from_email . '>',
            'Reply-To: ' . get_option('admin_email')
        );
        
        $set_html_content_type = function() { return 'text/html'; };
        add_filter('wp_mail_content_type', $set_html_content_type);
        $force_html_phpmailer = function($phpmailer) { $phpmailer->isHTML(true); };
        add_action('phpmailer_init', $force_html_phpmailer, 999);
        
        $sent = wp_mail($email, $subject, $message_html, $headers);
        
        remove_filter('wp_mail_content_type', $set_html_content_type);
        remove_action('phpmailer_init', $force_html_phpmailer, 999);
        
        if ($sent) {
            wp_send_json_success(__('Mail di test (simulata) inviata correttamente!', 'marrison-custom-updater'));
        } else {
            wp_send_json_error(__('Invio fallito. Verifica i log del server o la configurazione SMTP.', 'marrison-custom-updater'));
        }
    }

    public function run_scheduled_updates($source = '') {
        $this->mcu_recover_stale_cron_log_if_needed(['source' => $source ?: 'cron', 'before' => 'run_scheduled_updates']);

        $log_entry = [
            'time' => current_time('mysql'),
            'status' => 'started',
            'message' => 'Cron job started.'
        ];
        update_option('marrison_last_cron_log', $log_entry);

        $mcu_update_lock = null;
        $mcu_update_snapshot = null;
        $mcu_shutdown_completed = false;
        $mcu_shutdown_source = $source ?: 'cron';
        $mcu_diagnostics_pre_fingerprint = [];
        $mcu_diagnostics_run_id = '';
        $mcu_diagnostics_maintenance_executed = false;
        $updated_plugins = [];
        $updated_themes = [];
        $updated_translations = 0;
        $failed_updates = [];
        $skipped_updates = [];

        register_shutdown_function(function() use (&$mcu_shutdown_completed, &$mcu_update_lock, &$mcu_update_snapshot, $mcu_shutdown_source) {
            if ($mcu_shutdown_completed) {
                return;
            }

            $message = $this->mcu_shutdown_failure_message();
            $this->mcu_close_interrupted_cron_log($message, [
                'source' => $mcu_shutdown_source,
                'context' => 'shutdown',
            ]);

            if (!empty($mcu_update_lock) && !is_wp_error($mcu_update_lock)) {
                $this->mcu_restore_active_plugin_snapshot($mcu_update_snapshot, ['operation' => 'scheduled_updates', 'context' => 'shutdown']);
                $this->mcu_release_update_lock($mcu_update_lock);
            }

            $this->mcu_ensure_automatic_update_event_scheduled([
                'source' => $mcu_shutdown_source,
                'context' => 'shutdown',
            ]);
        });

        try {
            @ignore_user_abort(true);
            @set_time_limit(0);

            $mcu_update_lock = $this->mcu_acquire_update_lock('scheduled_updates', ['source' => $source ?: 'cron']);
            if (is_wp_error($mcu_update_lock)) {
                $log_entry['status'] = 'skipped';
                $log_entry['message'] = $mcu_update_lock->get_error_message();
                update_option('marrison_last_cron_log', $log_entry);
                return;
            }
            $mcu_update_snapshot = $this->mcu_capture_active_plugin_snapshot(['operation' => 'scheduled_updates']);
            $mcu_diagnostics_maintenance_executed = true;
            $mcu_diagnostics_run_id = $this->mcu_get_update_run_id();
            $mcu_diagnostics_scheduler = '\\MarrisonCustomUpdater\\MaintenanceClient\\Diagnostics_Scheduler';
            if (!class_exists($mcu_diagnostics_scheduler) && defined('MCU_PLUGIN_DIR')) {
                require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-diagnostics-scheduler.php';
            }
            if (class_exists($mcu_diagnostics_scheduler)) {
                $mcu_diagnostics_pre_fingerprint = $mcu_diagnostics_scheduler::capture_pre_maintenance_fingerprint([
                    'source' => $source ?: 'cron',
                    'maintenance_run_id' => $mcu_diagnostics_run_id,
                ]);
            }
            $this->mcu_log_event('info', 'scheduled_updates_started', ['source' => $source ?: 'cron']);
            $this->mcu_touch_update_lock(['operation' => 'scheduled_updates', 'stage' => 'started']);
            $files_backup_required = get_option('marrison_files_backup_with_updates') === 'yes';
            $db_backup_required = get_option('marrison_db_backup_with_updates') === 'yes' || $files_backup_required;

            $db_backup_filename = false;
            $db_backup_error = '';
            if ($db_backup_required) {
                $this->mcu_touch_update_lock(['operation' => 'scheduled_updates', 'stage' => 'db_backup_started']);
                $db_backup_filename = $this->create_db_backup();
                if (is_wp_error($db_backup_filename)) {
                    $db_backup_error = $db_backup_filename->get_error_message();
                    $db_backup_filename = false;
                } elseif ($db_backup_filename) {
                    update_option('marrison_last_db_backup_filename', $db_backup_filename);
                }
                $this->mcu_touch_update_lock(['operation' => 'scheduled_updates', 'stage' => 'db_backup_finished']);
            }

            $files_backup_filename = false;
            $files_backup_error = '';
            if ($files_backup_required) {
                $this->mcu_touch_update_lock(['operation' => 'scheduled_updates', 'stage' => 'files_backup_started']);
                $files_backup_filename = $this->create_files_backup();
                if (is_wp_error($files_backup_filename)) {
                    $files_backup_error = $files_backup_filename->get_error_message();
                    $files_backup_filename = false;
                } elseif ($files_backup_filename) {
                    update_option('marrison_last_files_backup_filenames', $files_backup_filename);
                }
                $this->mcu_touch_update_lock(['operation' => 'scheduled_updates', 'stage' => 'files_backup_finished']);
            }

            $data = $this->get_all_updates_data();
            
            $log_entry['message'] = 'Updates check completed.';
            update_option('marrison_last_cron_log', $log_entry);

            include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
            include_once ABSPATH . 'wp-admin/includes/theme.php';
            include_once ABSPATH . 'wp-admin/includes/file.php';
            
            global $wp_filesystem;
            if (empty($wp_filesystem)) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                WP_Filesystem();
            }
            
            $skin = new Automatic_Upgrader_Skin();
            $backup_blocked_updates = ($db_backup_error !== '' || $files_backup_error !== '');
            if ($backup_blocked_updates) {
                $backup_errors = array_filter([$db_backup_error, $files_backup_error]);
                $skipped_updates[] = [
                    'name' => __('Aggiornamenti automatici', 'marrison-custom-updater'),
                    'version' => 'N/A',
                    'reason' => sprintf(__('Aggiornamenti bloccati: backup richiesto non completato (%s).', 'marrison-custom-updater'), implode(' | ', $backup_errors)),
                ];
            }

            // --- 1. Aggiornamento Plugin Privati ---
            if (!$backup_blocked_updates && !empty($data['plugins_private'])) {
                foreach ($data['plugins_private'] as $u) {
                    $this->mcu_touch_update_lock(['operation' => 'scheduled_updates', 'stage' => 'private_plugin_started', 'slug' => $u['slug'] ?? '']);
                    $plugin_file = $this->find_plugin_file($u['slug'], $u['name'] ?? '');
                    $old_version = 'N/A';
                    if ($plugin_file && file_exists(WP_PLUGIN_DIR . '/' . $plugin_file)) {
                        $p_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file);
                        $old_version = $p_data['Version'];
                    }

                    $result = $this->perform_update($u['slug']);
                    if ($result === true) {
                        $updated_plugins[] = [
                            'name'        => $u['name'],
                            'old_version' => $old_version,
                            'new_version' => $u['version'],
                            'type'        => 'Privato'
                        ];
                    } else {
                        $error_msg = is_wp_error($result) ? $result->get_error_message() : __('Errore sconosciuto', 'marrison-custom-updater');
                        $failed_updates[] = [
                            'name' => $u['name'],
                            'version' => $u['version'],
                            'type' => 'Privato',
                            'error' => $error_msg
                        ];
                    }
                    $this->mcu_touch_update_lock(['operation' => 'scheduled_updates', 'stage' => 'private_plugin_finished', 'slug' => $u['slug'] ?? '']);
                }
            }
            
            $log_entry['message'] = 'Private plugins processed.';
            update_option('marrison_last_cron_log', $log_entry);

            // --- 2. Aggiornamento Plugin Ufficiali ---
            if (!$backup_blocked_updates) {
                $this->mcu_touch_update_lock(['operation' => 'scheduled_updates', 'stage' => 'official_plugins_check_started']);
                wp_update_plugins();
                $transient_plugins = get_site_transient('update_plugins');
                $this->mcu_touch_update_lock(['operation' => 'scheduled_updates', 'stage' => 'official_plugins_check_finished']);
            } else {
                $transient_plugins = null;
            }
            if (!$backup_blocked_updates && !empty($transient_plugins->response)) {
                $private_updates = $this->get_available_updates();
                $private_slugs = array_map(function($u) { return $u['slug']; }, $private_updates);
                $known_slugs = get_option('marrison_known_private_slugs', []);
                if (is_array($known_slugs)) {
                    $private_slugs = array_unique(array_merge($private_slugs, $known_slugs));
                }
                $private_files = [];
                foreach ($private_updates as $u) {
                    $found_file = $this->find_plugin_file($u['slug'], $u['name'] ?? '');
                    if ($found_file) $private_files[] = $found_file;
                }
                
                $plugin_files = [];
                $plugin_info_map = []; // Mappa per conservare info versioni

                foreach ($transient_plugins->response as $file => $data_plugin) {
                    if (in_array($file, $private_files)) continue;
                    
                    $slug = isset($data_plugin->slug) ? $data_plugin->slug : dirname($file);
                    if ($slug === '.' || $slug === '') $slug = basename($file, '.php');
                    if (in_array($slug, $private_slugs)) continue;
                    
                    // Exclude
                    if ($this->is_item_excluded($slug, 'plugin')) {
                        continue;
                    }

                    // Check PHP Requirements
                    if (isset($data_plugin->requires_php) && version_compare(phpversion(), $data_plugin->requires_php, '<')) {
                        $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $file);
                        $skipped_updates[] = [
                            'name' => $plugin_data['Name'] ?? $slug,
                            'version' => $data_plugin->new_version,
                            'reason' => sprintf(__('Richiede PHP %s (Attuale: %s)', 'marrison-custom-updater'), $data_plugin->requires_php, phpversion())
                        ];
                        continue;
                    }

                    $plugin_files[] = $file;
                    $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $file);
                    
                    $plugin_info_map[$file] = [
                        'name' => $plugin_data['Name'] ?? $slug,
                        'old_version' => $plugin_data['Version'] ?? 'N/A',
                        'new_version' => $data_plugin->new_version ?? 'N/A'
                    ];
                }
                
                if (!empty($plugin_files)) {
                    $this->mcu_touch_update_lock(['operation' => 'scheduled_updates', 'stage' => 'official_plugins_update_started', 'count' => count($plugin_files)]);
                    $upgrader = new Plugin_Upgrader($skin);
                    $results = $upgrader->bulk_upgrade($plugin_files);
                    if (is_array($results)) {
                        foreach ($plugin_files as $file) {
                            $res = isset($results[$file]) ? $results[$file] : false;
                            $info = $plugin_info_map[$file];

                            if ($res === true || (is_array($res) && !is_wp_error($res))) {
                                $updated_plugins[] = [
                                    'name'        => $info['name'],
                                    'old_version' => $info['old_version'],
                                    'new_version' => $info['new_version'],
                                    'type'        => 'Ufficiale'
                                ];
                            } else {
                                $error_msg = __('Errore sconosciuto', 'marrison-custom-updater');
                                if (is_wp_error($res)) {
                                    $error_msg = $res->get_error_message();
                                } elseif ($res === false) {
                                    $error_msg = __('Aggiornamento fallito', 'marrison-custom-updater');
                                }
                                $failed_updates[] = [
                                    'name' => $info['name'],
                                    'version' => $info['new_version'],
                                    'type' => 'Ufficiale',
                                    'error' => $error_msg
                                ];
                            }
                        }
                    }
                    $this->mcu_flush_update_caches(['operation' => 'scheduled_official_plugins']);
                    $this->mcu_touch_update_lock(['operation' => 'scheduled_updates', 'stage' => 'official_plugins_update_finished', 'count' => count($plugin_files)]);
                }
            }
            
            $log_entry['message'] = 'Official plugins processed.';
            update_option('marrison_last_cron_log', $log_entry);

            // --- 3. Aggiornamento Temi ---
            if (!$backup_blocked_updates && $data['themes_count'] > 0) {
                $this->mcu_touch_update_lock(['operation' => 'scheduled_updates', 'stage' => 'themes_update_started']);
                $current = get_site_transient('update_themes');
                if (!empty($current->response)) {
                    $themes = array_keys($current->response);
                    
                    // Filter excluded themes
                    $themes = array_filter($themes, function($slug) {
                        return !$this->is_item_excluded($slug, 'theme');
                    });

                    // Prepara info versioni temi
                    $theme_info_map = [];
                    foreach ($themes as $slug) {
                        $theme = wp_get_theme($slug);
                        $new_ver = isset($current->response[$slug]['new_version']) ? $current->response[$slug]['new_version'] : 'N/A';
                        
                        $theme_info_map[$slug] = [
                            'name' => $theme->get('Name'),
                            'old_version' => $theme->get('Version'),
                            'new_version' => $new_ver
                        ];
                    }

                    $theme_upgrader = new Theme_Upgrader($skin);
                    $result = $theme_upgrader->bulk_upgrade($themes);
                    
                    if (is_array($result)) {
                        foreach ($themes as $slug) {
                            $res = isset($result[$slug]) ? $result[$slug] : false;
                            $info = $theme_info_map[$slug];

                            if ($res === true || (is_array($res) && !is_wp_error($res))) {
                                $updated_themes[] = [
                                    'name'        => $info['name'],
                                    'old_version' => $info['old_version'],
                                    'new_version' => $info['new_version']
                                ];
                            } else {
                                $error_msg = is_wp_error($res) ? $res->get_error_message() : __('Errore aggiornamento tema', 'marrison-custom-updater');
                                $failed_updates[] = [
                                    'name' => $info['name'],
                                    'version' => $info['new_version'],
                                    'type' => 'Tema',
                                    'error' => $error_msg
                                ];
                            }
                        }
                    }
                }
                $this->mcu_touch_update_lock(['operation' => 'scheduled_updates', 'stage' => 'themes_update_finished']);
            }
            
            $log_entry['message'] = 'Themes processed.';
            update_option('marrison_last_cron_log', $log_entry);
            
            if (!$backup_blocked_updates && $data['translations_count'] > 0) {
                $this->mcu_touch_update_lock(['operation' => 'scheduled_updates', 'stage' => 'translations_update_started']);
                include_once ABSPATH . 'wp-admin/includes/translation-install.php';
                $translations = wp_get_translation_updates();
                if (!empty($translations)) {
                    $lang_upgrader = new Language_Pack_Upgrader($skin);
                    $result = $lang_upgrader->bulk_upgrade($translations);
                    if ($result && !is_wp_error($result)) {
                        $count = 0;
                        foreach ($result as $r) {
                            if ($r && !is_wp_error($r)) $count++;
                        }
                        $updated_translations = $count;
                    }
                }
                $this->mcu_touch_update_lock(['operation' => 'scheduled_updates', 'stage' => 'translations_update_finished']);
            }
            
            
            // --- 4. Raccolta Info Stato Sito ---
            global $wpdb;
            $site_info = [
                'wp_version'   => get_bloginfo('version'),
                'php_version'  => phpversion(),
                'server'       => isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : 'N/A',
                'db_version'   => $wpdb->db_version(),
                'memory_limit' => ini_get('memory_limit'),
                'debug_mode'   => (defined('WP_DEBUG') && WP_DEBUG) ? 'Attivo' : 'Disattivo',
                'site_url'     => get_site_url(),
                'home_url'     => get_home_url()
            ];

            $all_plugins = get_plugins();
            $active_plugins = [];
            $inactive_plugins = [];

            foreach ($all_plugins as $path => $plugin) {
                if (is_plugin_active($path)) {
                    $active_plugins[] = $plugin;
                } else {
                    $inactive_plugins[] = $plugin;
                }
            }

            $log_entry['message'] = 'Translations processed. Preparing email...';
            update_option('marrison_last_cron_log', $log_entry);
            $this->mcu_touch_update_lock(['operation' => 'scheduled_updates', 'stage' => 'report_started']);
            
            // Check Elementor Log if Elementor was updated
            $elementor_db_info = null;
            $elementor_was_updated = false;
            foreach ($updated_plugins as $p) {
                if (stripos($p['name'], 'elementor') !== false) {
                    $elementor_was_updated = true;
                    break;
                }
            }
            if ($elementor_was_updated && is_plugin_active('elementor/elementor.php')) {
                 if (class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->db)) {
                      if (method_exists(\Elementor\Plugin::$instance->db, 'is_upgrade_required') && \Elementor\Plugin::$instance->db->is_upgrade_required()) {
                           $elementor_db_info = 'Richiesto (Processo in background avviato)';
                      } else {
                           $elementor_db_info = 'Non richiesto (Database già aggiornato)';
                      }
                 } else {
                      $elementor_db_info = 'Impossibile verificare (Elementor non caricato)';
                 }
            }

            $email = get_option('marrison_auto_update_email');
            if ($email) {
                $has_updates = (!empty($updated_plugins) || !empty($updated_themes) || $updated_translations > 0);
                $has_issues = (!empty($failed_updates) || !empty($skipped_updates));
                
                $subject = '[' . get_bloginfo('name') . '] Report Aggiornamento Automatico';
                if ($has_issues) {
                    $subject .= ' - ATTENZIONE: Rilevati Problemi';
                }
                
                // Stili CSS inline per compatibilità email
                $bg_color = "#2c3338"; // Sfondo scuro (WordPress dark grey)
                $container_bg = "#ffffff"; // Sfondo bianco per il contenuto
                
                $style_body_wrapper = "background-color: {$bg_color}; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 40px 0; width: 100%;";
                $style_container = "background-color: {$container_bg}; border-radius: 10px; max-width: 600px; margin: 0 auto; padding: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);";
                
                $style_h2 = "color: #2c3338; border-bottom: 2px solid #eee; padding-bottom: 10px; margin-bottom: 20px; margin-top: 30px;";
                $style_table = "width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 14px;";
                $style_th = "background-color: #f8f9fa; border: 1px solid #ddd; padding: 10px; text-align: left; font-weight: bold; color: #555;";
                $style_td = "border: 1px solid #ddd; padding: 10px;";
                $style_badge_priv = "background-color: #0073aa; color: #fff; padding: 3px 6px; border-radius: 3px; font-size: 11px; font-weight: bold; display: inline-block;";
                $style_badge_off = "background-color: #46b450; color: #fff; padding: 3px 6px; border-radius: 3px; font-size: 11px; font-weight: bold; display: inline-block;";
                $style_badge_theme = "background-color: #e65100; color: #fff; padding: 3px 6px; border-radius: 3px; font-size: 11px; font-weight: bold; display: inline-block;";
                $style_badge_error = "background-color: #d63638; color: #fff; padding: 3px 6px; border-radius: 3px; font-size: 11px; font-weight: bold; display: inline-block;";
                $style_badge_warn = "background-color: #ffb900; color: #333; padding: 3px 6px; border-radius: 3px; font-size: 11px; font-weight: bold; display: inline-block;";
                
                $style_footer = "margin-top: 30px; border-top: 1px solid #eee; padding-top: 15px; font-size: 12px; color: #777; text-align: center;";
                $style_section_title = "color: #444; margin-top: 20px; margin-bottom: 10px; font-size: 16px; border-left: 4px solid #0073aa; padding-left: 10px;";
                $style_section_title_error = "color: #d63638; margin-top: 20px; margin-bottom: 10px; font-size: 16px; border-left: 4px solid #d63638; padding-left: 10px;";
                $style_section_title_warn = "color: #e65100; margin-top: 20px; margin-bottom: 10px; font-size: 16px; border-left: 4px solid #ffb900; padding-left: 10px;";

                $style_list_item = "padding: 5px 0; border-bottom: 1px solid #eee; font-size: 13px;";
                $style_list_item_last = "padding: 5px 0; font-size: 13px;";
                $style_info_row = "display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f0f0f0;";
                $style_info_label = "font-weight: bold; color: #555;";
                $style_info_value = "color: #333;";

                $message_html = '<!DOCTYPE html><html><body style="' . $style_body_wrapper . '">';
                
                // Container Bianco con angoli smussati
                $message_html .= '<div style="' . $style_container . '">';
                
                $message_html .= '<div style="text-align: center; margin-bottom: 20px;">';
                // Logo o Nome Sito
                $custom_logo_id = get_theme_mod('custom_logo');
                $logo_src = '';
                $is_valid_image = false;

                // 1. Prova Logo Principale
                if ($custom_logo_id) {
                    $logo_data = wp_get_attachment_image_src($custom_logo_id, 'full');
                    if ($logo_data) {
                        $src = $logo_data[0];
                        // Check per SVG (spesso non supportati nei client mail)
                        $path = parse_url($src, PHP_URL_PATH);
                        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                        
                        if ($ext !== 'svg') {
                            $logo_src = $src;
                            $is_valid_image = true;
                        }
                    }
                }

                // 2. Fallback su Site Icon (Favicon) se il logo manca o è SVG
                if (!$is_valid_image && function_exists('get_site_icon_url')) {
                    $icon_url = get_site_icon_url(512); // Richiedi alta risoluzione
                    if ($icon_url) {
                        $path = parse_url($icon_url, PHP_URL_PATH);
                        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                        
                        if ($ext !== 'svg') {
                            $logo_src = $icon_url;
                            $is_valid_image = true;
                        }
                    }
                }

                if ($is_valid_image && !empty($logo_src)) {
                     $message_html .= '<img src="' . esc_url($logo_src) . '" alt="' . esc_attr(get_bloginfo('name')) . '" style="max-width: 200px; max-height: 100px; width: auto; height: auto; display: inline-block; border: 0; outline: none; text-decoration: none;">';
                } else {
                    $message_html .= '<h1 style="margin: 0; color: #444; font-size: 24px;">' . get_bloginfo('name') . '</h1>';
                }
                $message_html .= '</div>';
                
                // --- STATUS BANNER ---
                $banner_color = "#46b450"; // Verde (Default: Successo)
                $banner_icon = "✅";
                $banner_title = "Tutto Aggiornato";
                $banner_desc = "Il sistema è aggiornato e sicuro. Nessuna azione richiesta.";
                
                if ($has_issues) {
                    $banner_color = "#d63638"; // Rosso (Errore)
                    $banner_icon = "❌";
                    $banner_title = "Attenzione: Rilevati Problemi";
                    $banner_desc = "Alcuni aggiornamenti non sono andati a buon fine o sono stati saltati.";
                } elseif ($has_updates) {
                    $banner_title = "Aggiornamento Riuscito";
                    $banner_desc = "Tutte le operazioni di aggiornamento sono state completate con successo.";
                }
                
                $message_html .= '<div style="background-color: ' . $banner_color . '; color: #ffffff; padding: 20px; text-align: center; border-radius: 6px; margin-bottom: 25px;">';
                $message_html .= '<h2 style="margin: 0; font-size: 22px; color: #ffffff; border: none; font-weight: bold;">' . $banner_icon . ' ' . $banner_title . '</h2>';
                $message_html .= '<p style="margin: 10px 0 0 0; font-size: 15px; opacity: 0.95;">' . $banner_desc . '</p>';
                $message_html .= '</div>';

                $message_html .= '<h2 style="' . $style_h2 . '">Report Aggiornamenti</h2>';
                
                if ($has_updates || $has_issues) {
                    // --- ERRORI ---
                    if (!empty($failed_updates)) {
                        $message_html .= '<h3 style="' . $style_section_title_error . '">❌ Aggiornamenti Falliti</h3>';
                        $message_html .= '<table style="' . $style_table . '">';
                        $message_html .= '<thead><tr>';
                        $message_html .= '<th style="' . $style_th . '">Elemento</th>';
                        $message_html .= '<th style="' . $style_th . '">Tipo</th>';
                        $message_html .= '<th style="' . $style_th . '">Errore</th>';
                        $message_html .= '</tr></thead><tbody>';
                        
                        foreach ($failed_updates as $f) {
                            $message_html .= '<tr>';
                            $message_html .= '<td style="' . $style_td . '"><strong>' . esc_html($f['name']) . '</strong></td>';
                            $message_html .= '<td style="' . $style_td . '"><span style="' . $style_badge_error . '">' . esc_html($f['type']) . '</span></td>';
                            $message_html .= '<td style="' . $style_td . '"><span style="color: #d63638;">' . esc_html($f['error']) . '</span></td>';
                            $message_html .= '</tr>';
                        }
                        $message_html .= '</tbody></table>';
                    }

                    // --- SKIPPED ---
                    if (!empty($skipped_updates)) {
                        $message_html .= '<h3 style="' . $style_section_title_warn . '">⚠️ Aggiornamenti Saltati</h3>';
                        $message_html .= '<table style="' . $style_table . '">';
                        $message_html .= '<thead><tr>';
                        $message_html .= '<th style="' . $style_th . '">Elemento</th>';
                        $message_html .= '<th style="' . $style_th . '">Motivo</th>';
                        $message_html .= '</tr></thead><tbody>';
                        
                        foreach ($skipped_updates as $s) {
                            $message_html .= '<tr>';
                            $message_html .= '<td style="' . $style_td . '"><strong>' . esc_html($s['name']) . '</strong></td>';
                            $message_html .= '<td style="' . $style_td . '">' . esc_html($s['reason']) . '</td>';
                            $message_html .= '</tr>';
                        }
                        $message_html .= '</tbody></table>';
                    }
                    
                    if (!empty($updated_plugins)) {
                        $message_html .= '<h3 style="' . $style_section_title . '">🔌 Plugin Aggiornati</h3>';
                        $message_html .= '<table style="' . $style_table . '">';
                        $message_html .= '<thead><tr>';
                        $message_html .= '<th style="' . $style_th . '">Plugin</th>';
                        $message_html .= '<th style="' . $style_th . '">Tipo</th>';
                        $message_html .= '<th style="' . $style_th . '">Versione</th>';
                        $message_html .= '</tr></thead><tbody>';
                        
                        foreach ($updated_plugins as $p) {
                            $badge_style = ($p['type'] === 'Privato') ? $style_badge_priv : $style_badge_off;
                            $message_html .= '<tr>';
                            $message_html .= '<td style="' . $style_td . '"><strong>' . esc_html($p['name']) . '</strong></td>';
                            $message_html .= '<td style="' . $style_td . '"><span style="' . $badge_style . '">' . esc_html($p['type']) . '</span></td>';
                            $message_html .= '<td style="' . $style_td . '">' . esc_html($p['old_version']) . ' &rarr; <strong>' . esc_html($p['new_version']) . '</strong></td>';
                            $message_html .= '</tr>';
                        }
                        $message_html .= '</tbody></table>';
                    }
                    
                    if (!empty($updated_themes)) {
                        $message_html .= '<h3 style="' . $style_section_title . '">🎨 Temi Aggiornati</h3>';
                        $message_html .= '<table style="' . $style_table . '">';
                        $message_html .= '<thead><tr>';
                        $message_html .= '<th style="' . $style_th . '">Tema</th>';
                        $message_html .= '<th style="' . $style_th . '">Versione</th>';
                        $message_html .= '</tr></thead><tbody>';
                        
                        foreach ($updated_themes as $t) {
                            $message_html .= '<tr>';
                            $message_html .= '<td style="' . $style_td . '"><strong>' . esc_html($t['name']) . '</strong> <span style="' . $style_badge_theme . '">Tema</span></td>';
                            $message_html .= '<td style="' . $style_td . '">' . esc_html($t['old_version']) . ' &rarr; <strong>' . esc_html($t['new_version']) . '</strong></td>';
                            $message_html .= '</tr>';
                        }
                        $message_html .= '</tbody></table>';
                    }
                    
                    if ($updated_translations > 0) {
                        $message_html .= '<div style="background-color: #f0f0f1; padding: 10px; border-left: 4px solid #0073aa; margin-top: 20px;">';
                        $message_html .= '<strong>🌍 Traduzioni:</strong> Aggiornati ' . intval($updated_translations) . ' pacchetti di traduzione.';
                        $message_html .= '</div>';
                    }
                } else {
                    $message_html .= '<div style="background-color: #e7f7ed; color: #106a33; padding: 15px; border-radius: 5px; text-align: center; font-weight: bold; border: 1px solid #c3e6cb;">';
                    $message_html .= '✅ Nessun aggiornamento necessario. Il sistema è già aggiornato.';
                    $message_html .= '</div>';
                }

                // --- SEZIONE STATO DEL SITO ---
                $message_html .= '<h2 style="' . $style_h2 . '">Stato del Sito</h2>';
                
                // Info Sistema
                $message_html .= '<table style="' . $style_table . ' width: 100%; border: none;"><tbody>';
                $message_html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>WordPress:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">v' . $site_info['wp_version'] . '</td></tr>';
                $message_html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>PHP:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">v' . $site_info['php_version'] . '</td></tr>';
                $message_html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Web Server:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">' . esc_html($site_info['server']) . '</td></tr>';
                $message_html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Memory Limit:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">' . esc_html($site_info['memory_limit']) . '</td></tr>';
                $message_html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Debug Mode:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">' . esc_html($site_info['debug_mode']) . '</td></tr>';
                $message_html .= '</tbody></table>';

                // Plugin Attivi
                $message_html .= '<h3 style="' . $style_section_title . '">✅ Plugin Attivi (' . count($active_plugins) . ')</h3>';
                if (!empty($active_plugins)) {
                    $message_html .= '<ul style="list-style-type: none; padding: 0; margin: 0;">';
                    foreach ($active_plugins as $p) {
                        $message_html .= '<li style="' . $style_list_item . '"><strong>' . esc_html($p['Name']) . '</strong> <span style="color: #777; font-size: 12px;">(v' . esc_html($p['Version']) . ')</span></li>';
                    }
                    $message_html .= '</ul>';
                } else {
                    $message_html .= '<p style="font-size: 13px; color: #777;">Nessun plugin attivo.</p>';
                }

                // Plugin Inattivi
                $message_html .= '<h3 style="' . $style_section_title . '; border-left-color: #d63638;">🚫 Plugin Inattivi (' . count($inactive_plugins) . ')</h3>';
                if (!empty($inactive_plugins)) {
                    $message_html .= '<ul style="list-style-type: none; padding: 0; margin: 0;">';
                    foreach ($inactive_plugins as $p) {
                        $message_html .= '<li style="' . $style_list_item . '"><strong>' . esc_html($p['Name']) . '</strong> <span style="color: #777; font-size: 12px;">(v' . esc_html($p['Version']) . ')</span></li>';
                    }
                    $message_html .= '</ul>';
                } else {
                    $message_html .= '<p style="font-size: 13px; color: #777;">Nessun plugin inattivo.</p>';
                }
                
                if ($elementor_db_info) {
                     $message_html .= '<h3 style="' . $style_section_title . '">Elementor Data</h3>';
                     $message_html .= '<div style="background: #f0f0f1; padding: 10px; font-size: 13px; border-left: 4px solid #0073aa; margin-top: 10px;">';
                     $message_html .= '<strong>Stato Aggiornamento DB:</strong> ' . esc_html(is_string($elementor_db_info) ? $elementor_db_info : print_r($elementor_db_info, true));
                     $message_html .= '</div>';
                }

                if ($db_backup_filename) {
                    $backup_dir_path = WP_CONTENT_DIR . '/marrison-backups';
                    $backup_file_path = $backup_dir_path . '/' . $db_backup_filename;
                    $backup_size = file_exists($backup_file_path) ? size_format(filesize($backup_file_path)) : 'N/A';
                    $backup_download_url = $this->get_backup_download_url($db_backup_filename, 'db');
                    $db_integrity_records = get_option('marrison_db_backup_integrity', []);
                    $db_integrity = is_array($db_integrity_records) && isset($db_integrity_records[$db_backup_filename]) ? $db_integrity_records[$db_backup_filename] : [];

                    $message_html .= '<h3 style="' . $style_section_title . '; border-left-color: #46b450;">🗄️ Backup Database</h3>';
                    $message_html .= '<table style="width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 10px;">';
                    $message_html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Stato:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee; color: #46b450; font-weight: bold;">✅ Completato e verificato</td></tr>';
                    $message_html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>File:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee; font-family: monospace;">' . esc_html($db_backup_filename) . '</td></tr>';
                    $message_html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Dimensione:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">' . esc_html($backup_size) . '</td></tr>';
                    if (!empty($db_integrity)) {
                        $message_html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Contenuto:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">' . esc_html((int) ($db_integrity['total_tables'] ?? 0)) . ' tabelle, ' . esc_html((int) ($db_integrity['total_rows'] ?? 0)) . ' righe</td></tr>';
                        $message_html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>SHA256 SQL:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee; font-family: monospace;">' . esc_html(substr((string) ($db_integrity['sql_sha256'] ?? ''), 0, 16)) . '</td></tr>';
                    }
                    $message_html .= '<tr><td style="padding: 8px;"><strong>Download:</strong></td><td style="padding: 8px;"><a href="' . esc_url($backup_download_url) . '" style="display: inline-block; background: #46b450; color: #fff; padding: 8px 16px; text-decoration: none; border-radius: 4px; font-weight: bold; font-size: 13px;">Scarica backup database</a></td></tr>';
                    $message_html .= '</table>';
                } elseif ($db_backup_required) {
                    $message_html .= '<h3 style="' . $style_section_title . '; border-left-color: #d63638;">🗄️ Backup Database</h3>';
                    $message_html .= '<div style="background: #fef7f7; padding: 10px; font-size: 13px; border-left: 4px solid #d63638; margin-top: 10px;">';
                    $message_html .= '⚠️ <strong>Attenzione:</strong> Il backup del database non è stato completato correttamente.';
                    if ($db_backup_error !== '') {
                        $message_html .= '<br><strong>Errore:</strong> ' . esc_html($db_backup_error);
                    }
                    $message_html .= '</div>';
                }

                if ($files_backup_filename) {
                    $backup_dir_path = WP_CONTENT_DIR . '/marrison-backups';
                    $files_backup_filenames = is_array($files_backup_filename) ? $files_backup_filename : [$files_backup_filename];
                    $backup_total_size = 0;
                    $backup_download_links = [];
                    foreach ($files_backup_filenames as $part_index => $backup_part_filename) {
                        $backup_file_path = $backup_dir_path . '/' . $backup_part_filename;
                        if (file_exists($backup_file_path)) {
                            $backup_total_size += filesize($backup_file_path);
                        }
                        $backup_download_url = $this->get_backup_download_url($backup_part_filename, 'files');
                        $backup_download_links[] = '<a href="' . esc_url($backup_download_url) . '" style="display: inline-block; background: #0073aa; color: #fff; padding: 8px 16px; text-decoration: none; border-radius: 4px; font-weight: bold; font-size: 13px; margin: 0 6px 6px 0;">Scarica parte ' . ((int) $part_index + 1) . '</a>';
                    }
                    $backup_size = $backup_total_size > 0 ? size_format($backup_total_size) : 'N/A';
                    $skipped_large_files = get_option('marrison_last_files_backup_skipped', []);

                    $message_html .= '<h3 style="' . $style_section_title . '; border-left-color: #0073aa;">Backup File</h3>';
                    $message_html .= '<table style="width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 10px;">';
                    $message_html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Stato:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee; color: #46b450; font-weight: bold;">Completato</td></tr>';
                    $message_html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>File:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee; font-family: monospace;">' . esc_html(implode(', ', $files_backup_filenames)) . '</td></tr>';
                    $message_html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>Dimensione:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee;">' . esc_html($backup_size) . '</td></tr>';
                    if (!empty($skipped_large_files['count'])) {
                        $skipped_items = [];
                        foreach (($skipped_large_files['files'] ?? []) as $skipped_file) {
                            $reason = !empty($skipped_file['reason']) ? ' - ' . esc_html($skipped_file['reason']) : '';
                            $skipped_items[] = esc_html($skipped_file['path']) . ' (' . esc_html(size_format((int) $skipped_file['size'])) . ')' . $reason;
                        }
                        $message_html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #eee;"><strong>File esclusi:</strong></td><td style="padding: 8px; border-bottom: 1px solid #eee; color: #d63638;">' . esc_html((int) $skipped_large_files['count']) . ' file esclusi (' . esc_html(size_format((int) $skipped_large_files['bytes'])) . ')' . (!empty($skipped_items) ? '<br><span style="font-family: monospace; font-size: 12px;">' . implode('<br>', $skipped_items) . '</span>' : '') . '</td></tr>';
                    }
                    $message_html .= '<tr><td style="padding: 8px;"><strong>Download:</strong></td><td style="padding: 8px;">' . implode('', $backup_download_links) . '</td></tr>';
                    $message_html .= '</table>';
                } elseif ($files_backup_required) {
                    $message_html .= '<h3 style="' . $style_section_title . '; border-left-color: #d63638;">Backup File</h3>';
                    $message_html .= '<div style="background: #fef7f7; padding: 10px; font-size: 13px; border-left: 4px solid #d63638; margin-top: 10px;">';
                    $message_html .= '<strong>Attenzione:</strong> Il backup dei file non è stato completato correttamente.';
                    if ($files_backup_error !== '') {
                        $message_html .= '<br><strong>Errore:</strong> ' . esc_html($files_backup_error);
                    }
                    $message_html .= '</div>';
                }

                $message_html .= '<div style="' . $style_footer . '">';
                $message_html .= '<a href="' . esc_url(admin_url()) . '" style="color: #0073aa; text-decoration: none;">Accedi al sito</a><br><br>';
                $message_html .= '<span style="font-size: 11px; color: #999;">Powered By <a href="https://marrisonlab.com" target="_blank" style="color: #999; text-decoration: none;">Angelo Marra</a></span>';
                $message_html .= '</div>';
                
                $message_html .= '</div>'; // Chiusura Container
                $message_html .= '</body></html>';
                
                $from_email = get_option('admin_email');
                $from_name = get_bloginfo('name');
                
                $headers = array(
                    'Content-Type: text/html; charset=UTF-8',
                    'From: ' . $from_name . ' <' . $from_email . '>',
                    'Reply-To: ' . get_option('admin_email')
                );
                
                $set_html_content_type = function() { return 'text/html'; };
                add_filter('wp_mail_content_type', $set_html_content_type);
                $force_html_phpmailer = function($phpmailer) { $phpmailer->isHTML(true); };
                add_action('phpmailer_init', $force_html_phpmailer, 999);
                
                $sent = wp_mail($email, $subject, $message_html, $headers);
                
                remove_filter('wp_mail_content_type', $set_html_content_type);
                remove_action('phpmailer_init', $force_html_phpmailer, 999);
                
                $log_entry['status'] = 'completed';
                $log_entry['message'] = $sent ? 'Email inviata con successo.' : 'Errore invio email.';
                $log_entry['email_sent'] = $sent;
                $log_entry['updates_found'] = $has_updates;
                update_option('marrison_last_cron_log', $log_entry);
            } else {
                $log_entry['status'] = 'completed';
                $log_entry['message'] = 'Nessuna email configurata.';
                update_option('marrison_last_cron_log', $log_entry);
            }



        } catch (Throwable $e) {
            $log_entry['status'] = 'error';
            $log_entry['message'] = 'Errore Critico: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
            update_option('marrison_last_cron_log', $log_entry);
        } catch (Exception $e) {
            $log_entry['status'] = 'error';
            $log_entry['message'] = 'Eccezione: ' . $e->getMessage();
            update_option('marrison_last_cron_log', $log_entry);
        } finally {
            if (!empty($mcu_update_lock) && !is_wp_error($mcu_update_lock)) {
                $this->mcu_flush_update_caches(['operation' => 'scheduled_updates']);
                $this->mcu_restore_active_plugin_snapshot($mcu_update_snapshot, ['operation' => 'scheduled_updates']);
                $this->mcu_release_update_lock($mcu_update_lock);
                $this->mcu_log_event('info', 'scheduled_updates_finished', ['status' => $log_entry['status'] ?? 'unknown']);
            }
            $this->mcu_reschedule_calendar_update_if_needed($source);
            $this->mcu_ensure_automatic_update_event_scheduled([
                'source' => $source ?: 'cron',
                'context' => 'finally',
            ]);
            $mcu_diagnostics_scheduler = '\\MarrisonCustomUpdater\\MaintenanceClient\\Diagnostics_Scheduler';
            if (!class_exists($mcu_diagnostics_scheduler) && defined('MCU_PLUGIN_DIR')) {
                require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-diagnostics-scheduler.php';
            }
            if (class_exists($mcu_diagnostics_scheduler)) {
                $mcu_diagnostics_scheduler::maybe_schedule_after_maintenance([
                    'maintenance_executed' => $mcu_diagnostics_maintenance_executed,
                    'source' => $source ?: 'cron',
                    'maintenance_run_id' => $mcu_diagnostics_run_id,
                    'pre_fingerprint' => $mcu_diagnostics_pre_fingerprint,
                    'log_entry' => $log_entry,
                    'updated_plugins' => $updated_plugins,
                    'updated_themes' => $updated_themes,
                    'updated_translations' => $updated_translations,
                    'failed_updates' => $failed_updates,
                    'skipped_updates' => $skipped_updates,
                ]);
            }
            $mcu_shutdown_completed = true;
        }
    }
}
