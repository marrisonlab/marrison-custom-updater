<?php
/** Private installation is exposed only through the authenticated Commander action. */
trait MCU_Install_Operations_Trait {
    public function mcu_remote_install_plugin($response, $parameters) {
        $fail = static function ($code, $message) { return ['success' => false, 'error_code' => $code, 'message' => $message]; };
        if (!\MarrisonCustomUpdater\MaintenanceClient\Settings::repository_config_managed()) return $fail('client_not_authorized', 'Client non autorizzato da Commander.');
        if (!wp_is_file_mod_allowed('install_plugins')) return $fail('file_mods_disabled', 'Installazione disabilitata dalle policy WordPress.');
        $slug = (string) ($parameters['slug'] ?? '');
        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/D', $slug)) return $fail('invalid_slug', 'Identificativo plugin non valido.');
        $lock = $this->mcu_acquire_update_lock('commander_install_plugin', ['slug' => $slug]);
        if (is_wp_error($lock)) return $fail($lock->get_error_code(), $lock->get_error_message());
        @ignore_user_abort(true); @set_time_limit(0);
        $level = ob_get_level(); ob_start(); $temporary = ''; $guard = null;
        try {
            if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
            if (!function_exists('wp_tempnam')) require_once ABSPATH . 'wp-admin/includes/file.php';
            if (!class_exists('Plugin_Upgrader')) require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            $base = rtrim((string) get_option('marrison_repo_url', ''), '/') . '/';
            $configured = rtrim($base, '/');
            $catalogUrl = preg_match('~\.php(?:\?.*)?$~i', $configured) ? $configured : $base . 'index.php';
            if ($catalogUrl === $configured) $base = substr($configured, 0, strrpos($configured, '/') + 1);
            for ($redirect = 0; $redirect <= 3; $redirect++) {
                $catalog = wp_safe_remote_get($catalogUrl, ['timeout' => 30, 'redirection' => 0, 'limit_response_size' => 2097152]);
                if (is_wp_error($catalog)) break;
                $status = wp_remote_retrieve_response_code($catalog);
                if ($status < 300 || $status >= 400) break;
                $location = wp_remote_retrieve_header($catalog, 'location');
                if (!$location) break;
                $target = WP_Http::make_absolute_url($location, $catalogUrl);
                $from = parse_url($catalogUrl); $to = parse_url($target);
                if (!$to || preg_replace('/^www\./i', '', strtolower($from['host'] ?? '')) !== preg_replace('/^www\./i', '', strtolower($to['host'] ?? ''))
                    || !in_array($to['scheme'] ?? '', ['http', 'https'], true)
                    || (($from['scheme'] ?? '') === 'https' && $to['scheme'] !== 'https')
                    || isset($to['user']) || ($from['port'] ?? null) !== ($to['port'] ?? null)) {
                    return $fail('untrusted_catalog_redirect', 'Il catalogo reindirizza fuori dal repository autorizzato.');
                }
                $catalogUrl = $target;
            }
            if (is_wp_error($catalog) || wp_remote_retrieve_response_code($catalog) !== 200) return $fail('repository_unavailable', 'Catalogo privato non disponibile.');
            $entries = json_decode(wp_remote_retrieve_body($catalog), true);
            if (!is_array($entries)) return $fail('invalid_catalog', 'Catalogo privato non valido.');
            $matches = array_values(array_filter($entries, static function ($entry) use ($slug) { return is_array($entry) && ($entry['slug'] ?? '') === $slug; }));
            if (!$matches) return $fail('plugin_not_in_repository', 'Plugin assente nel repository privato.');
            usort($matches, static function ($a, $b) { return version_compare((string) ($b['version'] ?? ''), (string) ($a['version'] ?? '')); });
            $url = (string) ($matches[0]['download_url'] ?? '');
            $origin = parse_url($base); $package = parse_url($url);
            $path = rawurldecode((string) ($package['path'] ?? ''));
            if (!$origin || !$package || !in_array($package['scheme'] ?? '', ['http', 'https'], true)
                || ($origin['scheme'] ?? '') !== ($package['scheme'] ?? '') || preg_replace('/^www\./i', '', strtolower($origin['host'] ?? '')) !== preg_replace('/^www\./i', '', strtolower($package['host'] ?? ''))
                || ($origin['port'] ?? null) !== ($package['port'] ?? null) || isset($package['user']) || isset($package['fragment'])
                || strpos($path, rawurldecode($origin['path'] ?? '/')) !== 0 || preg_match('~(^|/)\.\.?(/|$)|[\\\\\x00]~', $path)) {
                return $fail('untrusted_package', 'Il pacchetto non appartiene al repository privato configurato.');
            }
            wp_clean_plugins_cache(false);
            foreach (get_plugins() as $file => $metadata) {
                if (strpos($file, $slug . '/') === 0 || $file === $slug . '.php') {
                    return is_plugin_active($file)
                        ? ['success' => true, 'already_active' => true, 'plugin_file' => $file, 'message' => 'Plugin gia installato e attivo; nessuna modifica.']
                        : $fail('plugin_already_installed', 'Plugin gia installato ma inattivo: attivazione automatica non eseguita.');
                }
            }
            if (file_exists(WP_PLUGIN_DIR . '/' . $slug)) return $fail('destination_exists', 'La cartella di destinazione esiste gia.');
            $temporary = wp_tempnam($slug . '.zip');
            if (!$temporary) return $fail('temporary_file_failed', 'Impossibile preparare il download.');
            $download = wp_safe_remote_get($url, ['timeout' => 120, 'redirection' => 0, 'stream' => true, 'filename' => $temporary]);
            if (is_wp_error($download) || wp_remote_retrieve_response_code($download) !== 200) return $fail('download_failed', 'Download del pacchetto privato fallito.');
            // Validate the destination before the upgrader copies anything into plugins/.
            $guard = static function ($source, $remote, $upgrader) use ($slug) {
                if (is_wp_error($source)) return $source;
                return basename(rtrim($source, '/\\')) === $slug ? $source : new WP_Error('package_identity_mismatch', 'La cartella del pacchetto non corrisponde al plugin scelto.');
            };
            add_filter('upgrader_source_selection', $guard, 99, 3);
            $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
            $installed = $upgrader->install($temporary, ['overwrite_package' => false]);
            if (is_wp_error($installed)) return $fail($installed->get_error_code(), sanitize_text_field($installed->get_error_message()));
            if (!$installed) return $fail('install_failed', 'Installazione non completata; verificare filesystem e compatibilita.');
            $file = $upgrader->plugin_info();
            wp_clean_plugins_cache(false);
            if (!is_string($file) || strpos($file, $slug . '/') !== 0 || !isset(get_plugins()[$file])) return $fail('install_not_verified', 'Identita del plugin installato non verificata.');
            if (is_multisite() && is_network_only_plugin($file)) return $fail('network_activation_required', 'Plugin installato ma non attivato: richiede attivazione di rete.');
            $requirements = validate_plugin_requirements($file);
            if (is_wp_error($requirements)) return $fail($requirements->get_error_code(), sanitize_text_field($requirements->get_error_message()));
            $inventory = get_plugins();
            foreach (array_filter(array_map('trim', explode(',', (string) ($inventory[$file]['RequiresPlugins'] ?? '')))) as $dependency) {
                $found = false;
                foreach ($inventory as $dependencyFile => $metadata) {
                    if ((dirname($dependencyFile) === $dependency || $dependencyFile === $dependency . '.php') && is_plugin_active($dependencyFile)) $found = true;
                }
                if (!$found) return $fail('missing_dependency', 'Plugin installato ma non attivato: dipendenza richiesta non attiva (' . sanitize_text_field($dependency) . ').');
            }
            $activated = activate_plugin($file, '', false, false);
            if (is_wp_error($activated)) return $fail('activation_failed', 'Plugin installato ma attivazione fallita: ' . sanitize_text_field($activated->get_error_message()));
            if (!is_plugin_active($file)) return $fail('activation_not_verified', 'Plugin installato ma attivazione non confermata.');
            return ['success' => true, 'plugin_file' => $file, 'installed' => true, 'active' => true, 'message' => 'Plugin installato e attivato: ' . $slug . '.'];
        } catch (\Throwable $error) {
            return $fail('plugin_install_interrupted', 'Installazione o attivazione interrotta; verificare lo stato del plugin prima di riprovare.');
        } finally {
            if ($guard) remove_filter('upgrader_source_selection', $guard, 99);
            if ($temporary && file_exists($temporary)) wp_delete_file($temporary);
            while (ob_get_level() > $level) ob_end_clean();
            $this->mcu_release_update_lock($lock);
        }
    }
}
