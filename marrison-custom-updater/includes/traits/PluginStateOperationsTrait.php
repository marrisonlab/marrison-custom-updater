<?php
/** Explicit, signed Commander commands for installed plugins only. */
trait MCU_Plugin_State_Operations_Trait {
    public function mcu_remote_set_plugin_active($response, $parameters) {
        $fail = static function ($code, $message) { return ['success' => false, 'error_code' => $code, 'message' => $message]; };
        if (!\MarrisonCustomUpdater\MaintenanceClient\Settings::repository_config_managed()) return $fail('client_not_authorized', 'Client non autorizzato da Commander.');
        if (!wp_is_file_mod_allowed('activate_plugins')) return $fail('file_mods_disabled', 'Modifica dello stato plugin disabilitata dalle policy WordPress.');
        if (is_multisite()) return $fail('multisite_not_supported', 'Attivazione remota non disponibile su WordPress multisito.');
        $file = (string) ($parameters['plugin_file'] ?? '');
        $expected = (string) ($parameters['expected_fingerprint'] ?? '');
        $desired = $parameters['active'] ?? null;
        if (!preg_match('~^[a-zA-Z0-9_.-]+(?:/[a-zA-Z0-9_.-]+)*\.php$~D', $file) || in_array('..', explode('/', $file), true)
            || !preg_match('/^[a-f0-9]{64}$/D', $expected) || !in_array($desired, [true, false, 1, 0, '1', '0'], true)) return $fail('invalid_plugin_identity', 'Plugin o stato richiesto non valido.');
        $desired = in_array($desired, [true, 1, '1'], true);
        $lock = $this->mcu_acquire_update_lock('commander_plugin_state', ['file' => $file]);
        if (is_wp_error($lock)) return $fail($lock->get_error_code(), $lock->get_error_message());
        $level = ob_get_level(); ob_start(); @ignore_user_abort(true); @set_time_limit(0);
        try {
            if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
            require_once MCU_PLUGIN_DIR . 'includes/mcu-client/class-plugin-security.php';
            wp_clean_plugins_cache(false);
            $plugins = get_plugins();
            if (!isset($plugins[$file])) return $fail('plugin_not_installed', 'Plugin non presente sul sito.');
            $identity = \MarrisonCustomUpdater\MaintenanceClient\Plugin_Security::metadata($file, $plugins[$file]);
            if (!hash_equals($identity['review_fingerprint'], $expected)) return $fail('plugin_identity_changed', 'Plugin cambiato: verifica il sito prima di modificarne lo stato.');
            $main = realpath(WP_PLUGIN_DIR . '/' . $file);
            $mcu = realpath(MCU_PLUGIN_DIR);
            if (!$desired && $main && $mcu && strpos(str_replace('\\', '/', $main), rtrim(str_replace('\\', '/', $mcu), '/') . '/') === 0) return $fail('protected_plugin', 'MCU non puo essere disattivato da Commander.');
            if (is_plugin_active($file) === $desired) return ['success' => true, 'already_current' => true, 'plugin_file' => $file, 'active' => $desired, 'message' => 'Stato plugin gia corrispondente alla richiesta.'];
            $slug = strpos($file, '/') === false ? basename($file, '.php') : explode('/', $file)[0];
            if ($desired) {
                $requirements = validate_plugin_requirements($file);
                if (is_wp_error($requirements)) return $fail($requirements->get_error_code(), sanitize_text_field($requirements->get_error_message()));
                foreach (array_filter(array_map('trim', explode(',', (string) ($plugins[$file]['RequiresPlugins'] ?? '')))) as $dependency) {
                    $found = false;
                    foreach ($plugins as $otherFile => $metadata) if ((dirname($otherFile) === $dependency || $otherFile === $dependency . '.php') && is_plugin_active($otherFile)) $found = true;
                    if (!$found) return $fail('missing_dependency', 'Attivazione bloccata: dipendenza non attiva (' . sanitize_text_field($dependency) . ').');
                }
                $result = activate_plugin($file, '', false, false);
                if (is_wp_error($result)) return $fail('activation_failed', sanitize_text_field($result->get_error_message()));
            } else {
                foreach ($plugins as $otherFile => $metadata) {
                    if ($otherFile !== $file && is_plugin_active($otherFile) && in_array($slug, array_map('trim', explode(',', (string) ($metadata['RequiresPlugins'] ?? ''))), true)) return $fail('plugin_required', 'Disattivazione bloccata: un plugin attivo richiede questa dipendenza.');
                }
                deactivate_plugins($file, false);
            }
            if (is_plugin_active($file) !== $desired) return $fail('plugin_state_not_verified', 'Stato plugin non confermato dopo la richiesta.');
            return ['success' => true, 'plugin_file' => $file, 'active' => $desired, 'message' => $desired ? 'Plugin attivato.' : 'Plugin disattivato.'];
        } catch (\Throwable $error) {
            return $fail('plugin_state_interrupted', 'Modifica dello stato interrotta; verifica il sito prima di riprovare.');
        } finally {
            while (ob_get_level() > $level) ob_end_clean();
            $this->mcu_release_update_lock($lock);
        }
    }
}
