<?php
trait MCU_Update_Operations_Trait {
    private function get_available_updates() {
        if (!\MarrisonCustomUpdater\MaintenanceClient\Settings::repository_config_managed()) return [];
        $repo_url = trailingslashit((string) get_option('marrison_repo_url', ''));
        if (empty($repo_url)) return [];
        $cached = get_transient('marrison_available_updates_v2');
        if ($cached !== false && is_array($cached)) {
            $is_clean = true;
            foreach ($cached as $u) {
                if (isset($u['name']) && (strpos($u['name'], '/i\'') !== false || strpos($u['name'], '$') !== false)) {
                    $is_clean = false;
                    break;
                }
            }
            if ($is_clean) return $cached;
        }
        if (get_transient('marrison_updates_fetch_failed') !== false) {
            return [];
        }
        $response = wp_remote_get($repo_url . 'index.php', ['timeout' => 5]);
        if (is_wp_error($response)) {
            $this->mcu_log_event('error', 'private_plugin_repo_fetch_failed', [
                'url'   => $repo_url . 'index.php',
                'error' => $response,
            ]);
            set_transient('marrison_updates_fetch_failed', 1, 5 * MINUTE_IN_SECONDS);
            return [];
        }
        $response_code = (int) wp_remote_retrieve_response_code($response);
        if ($response_code >= 400) {
            $this->mcu_log_event('error', 'private_plugin_repo_http_error', [
                'url'           => $repo_url . 'index.php',
                'response_code' => $response_code,
            ]);
            set_transient('marrison_updates_fetch_failed', 1, 5 * MINUTE_IN_SECONDS);
            return [];
        }
        $body = wp_remote_retrieve_body($response);
        $updates = json_decode($body, true);
        if (!is_array($updates)) {
            $this->mcu_log_event('error', 'private_plugin_repo_invalid_json', [
                'url'         => $repo_url . 'index.php',
                'body_sample' => substr((string) $body, 0, 500),
            ]);
            set_transient('marrison_updates_fetch_failed', 1, 5 * MINUTE_IN_SECONDS);
            return [];
        }
        $cleaned_updates = [];
        foreach ($updates as $u) {
            if (!isset($u['slug'])) continue;
            $u['slug'] = trim($u['slug']);
            if (isset($u['version'])) $u['version'] = trim($u['version']);
            if (isset($u['name'])) $u['name'] = trim($u['name']);
            if (isset($u['name']) && (strpos($u['name'], '$') !== false || strpos($u['name'], '/i\'') !== false)) continue;
            if (isset($u['version']) && strpos($u['version'], '$') !== false) continue;
            $cleaned_updates[] = $u;
        }
        $updates = $cleaned_updates;
        set_transient('marrison_available_updates_v2', $updates, $this->cache_duration);
        $this->mcu_log_event('info', 'private_plugin_repo_fetched', [
            'url'   => $repo_url . 'index.php',
            'count' => count($updates),
        ]);
        return $updates;
    }

    private function get_available_theme_updates() {
        if (!\MarrisonCustomUpdater\MaintenanceClient\Settings::repository_config_managed()) return [];
        $repo_url = get_option('marrison_themes_repo_url');
        if (empty($repo_url)) return [];
        $repo_url = trailingslashit($repo_url);
        $cached = get_transient('marrison_available_theme_updates');
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }
        if (get_transient('marrison_theme_updates_fetch_failed') !== false) {
            return [];
        }
        $response = wp_remote_get($repo_url . 'index.php', ['timeout' => 5]);
        if (is_wp_error($response)) {
            $this->mcu_log_event('error', 'private_theme_repo_fetch_failed', [
                'url'   => $repo_url . 'index.php',
                'error' => $response,
            ]);
            set_transient('marrison_theme_updates_fetch_failed', 1, 5 * MINUTE_IN_SECONDS);
            return [];
        }
        $response_code = (int) wp_remote_retrieve_response_code($response);
        if ($response_code >= 400) {
            $this->mcu_log_event('error', 'private_theme_repo_http_error', [
                'url'           => $repo_url . 'index.php',
                'response_code' => $response_code,
            ]);
            set_transient('marrison_theme_updates_fetch_failed', 1, 5 * MINUTE_IN_SECONDS);
            return [];
        }
        $body = wp_remote_retrieve_body($response);
        $updates = json_decode($body, true);
        if (!is_array($updates)) {
            $this->mcu_log_event('error', 'private_theme_repo_invalid_json', [
                'url'         => $repo_url . 'index.php',
                'body_sample' => substr((string) $body, 0, 500),
            ]);
            set_transient('marrison_theme_updates_fetch_failed', 1, 5 * MINUTE_IN_SECONDS);
            return [];
        }
        $cleaned_updates = [];
        foreach ($updates as $u) {
            if (!isset($u['slug'])) continue;
            $u['slug'] = trim($u['slug']);
            if (isset($u['version'])) $u['version'] = trim($u['version']);
            if (isset($u['name'])) $u['name'] = trim($u['name']);
            if (isset($u['name']) && (strpos($u['name'], '$') !== false || strpos($u['name'], '/i\'') !== false)) continue;
            if (isset($u['version']) && strpos($u['version'], '$') !== false) continue;
            $cleaned_updates[] = $u;
        }
        $updates = $cleaned_updates;
        set_transient('marrison_available_theme_updates', $updates, $this->cache_duration);
        $this->mcu_log_event('info', 'private_theme_repo_fetched', [
            'url'   => $repo_url . 'index.php',
            'count' => count($updates),
        ]);
        return $updates;
    }

    private function mcu_private_repo_status($type = 'plugin') {
        $type = $type === 'theme' ? 'theme' : 'plugin';
        if (!\MarrisonCustomUpdater\MaintenanceClient\Settings::repository_config_managed()) {
            return [
                'configured' => false,
                'reachable'  => false,
                'class'      => 'warning',
                'icon'       => 'warning',
                'message'    => __('Repository disponibile solo per siti autorizzati da Marrison Commander.', 'marrison-custom-updater'),
            ];
        }
        if ($type === 'theme') {
            $repo_url = get_option('marrison_themes_repo_url');
        } else {
            $repo_url = get_option('marrison_repo_url', '');
        }
        $repo_url = !empty($repo_url) ? trailingslashit($repo_url) : '';

        if (empty($repo_url)) {
            return [
                'configured' => false,
                'reachable' => false,
                'class'      => 'warning',
                'icon'       => 'warning',
                'message'    => __('Repository non configurato.', 'marrison-custom-updater'),
            ];
        }

        $failure_transient = $type === 'theme' ? 'marrison_theme_updates_fetch_failed' : 'marrison_updates_fetch_failed';
        if (get_transient($failure_transient) !== false) {
            return [
                'configured' => true,
                'reachable' => false,
                'class'      => 'danger',
                'icon'       => 'no',
                'message'    => __('Ultimo controllo repository fallito. Usa "Forza controllo MCU" dopo aver verificato l URL.', 'marrison-custom-updater'),
            ];
        }

        return [
            'configured' => true,
            'reachable' => true,
            'class'      => 'success',
            'icon'       => 'yes',
            'message'    => __('Repository configurato.', 'marrison-custom-updater'),
        ];
    }

    protected function is_item_excluded($slug, $type = 'plugin') {
        $option_name = ($type === 'theme') ? 'marrison_excluded_themes' : 'marrison_excluded_plugins';
        $excluded = get_option($option_name, []);
        if (!is_array($excluded)) $excluded = [];
        if ($type !== 'plugin') {
            return in_array($slug, $excluded, true);
        }

        return $this->mcu_plugin_candidate_excluded([$slug], $excluded);
    }

    protected function mcu_is_plugin_update_excluded($slug = '', $file = '', $name = '', $update = null) {
        $excluded = get_option('marrison_excluded_plugins', []);
        if (!is_array($excluded) || empty($excluded)) {
            return false;
        }

        $candidates = [$slug, $file, $name];

        if ($file !== '') {
            $normalized_file = str_replace('\\', '/', (string) $file);
            $dirname = dirname($normalized_file);
            if ($dirname !== '.' && $dirname !== '') {
                $candidates[] = $dirname;
            }
            $candidates[] = basename($normalized_file, '.php');
        }

        if (is_object($update)) {
            foreach (['slug', 'plugin', 'file', 'name'] as $key) {
                if (!empty($update->{$key})) {
                    $candidates[] = (string) $update->{$key};
                }
            }
        } elseif (is_array($update)) {
            foreach (['slug', 'plugin', 'file', 'name'] as $key) {
                if (!empty($update[$key])) {
                    $candidates[] = (string) $update[$key];
                }
            }
        }

        return $this->mcu_plugin_candidate_excluded($candidates, $excluded);
    }

    private function mcu_plugin_candidate_excluded(array $candidates, array $excluded) {
        $excluded_keys = [];
        foreach ($excluded as $excluded_identifier) {
            foreach ($this->mcu_plugin_identifier_keys($excluded_identifier) as $key) {
                $excluded_keys[$key] = true;
            }
        }

        foreach ($candidates as $candidate) {
            foreach ($this->mcu_plugin_identifier_keys($candidate) as $key) {
                if (isset($excluded_keys[$key])) {
                    return true;
                }
            }
        }

        return false;
    }

    private function mcu_plugin_identifier_keys($identifier) {
        $identifier = trim((string) $identifier);
        if ($identifier === '') {
            return [];
        }

        $parts = [$identifier, sanitize_key($identifier), $this->mcu_normalize_plugin_match_key($identifier)];
        if (strpos($identifier, '/') !== false || strpos($identifier, '\\') !== false) {
            $normalized = str_replace('\\', '/', $identifier);
            $dirname = dirname($normalized);
            if ($dirname !== '.' && $dirname !== '') {
                $parts[] = $dirname;
            }
            $parts[] = basename($normalized, '.php');
        }

        $keys = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }
            $keys[] = $part;
            $keys[] = sanitize_key($part);
            $keys[] = $this->mcu_normalize_plugin_match_key($part);
        }

        return array_values(array_unique(array_filter($keys)));
    }

    private function mcu_normalize_plugin_match_key($value) {
        $normalized = preg_replace('/[^a-z0-9]/', '', strtolower((string) $value));
        return is_string($normalized) ? $normalized : '';
    }

    private function mcu_get_update_run_id() {
        static $run_id = null;
        if ($run_id === null) {
            $run_id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : md5(uniqid('mcu', true));
        }
        return $run_id;
    }

    private function mcu_get_update_log_dir() {
        $dir = WP_CONTENT_DIR . '/marrison-updater-logs';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        if (is_dir($dir)) {
            $index = $dir . '/index.php';
            if (!file_exists($index)) {
                @file_put_contents($index, '<?php // Silence is golden');
            }
            $htaccess = $dir . '/.htaccess';
            if (!file_exists($htaccess)) {
                @file_put_contents($htaccess, "deny from all\n");
            }
        }
        return $dir;
    }

    private function mcu_get_update_log_file($timestamp = null) {
        $timestamp = $timestamp ? (int) $timestamp : current_time('timestamp');
        return $this->mcu_get_update_log_dir() . '/mcu-update-' . date('Y-m', $timestamp) . '.log';
    }

    private function mcu_get_update_log_files() {
        $dir = $this->mcu_get_update_log_dir();
        $files = glob($dir . '/mcu-update-*.log');
        if (!is_array($files)) {
            return [];
        }
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        return $files;
    }

    private function mcu_get_update_log_items() {
        $items = [];
        foreach ($this->mcu_get_update_log_files() as $file) {
            $filename = basename($file);
            $items[] = [
                'filename' => $filename,
                'date'     => date_i18n('d/m/Y H:i', filemtime($file)),
                'size'     => function_exists('size_format') ? size_format(filesize($file)) : filesize($file) . ' B',
                'url'      => wp_nonce_url(
                    add_query_arg(
                        [
                            'action' => 'marrison_download_update_log',
                            'file'   => $filename,
                        ],
                        admin_url('admin-post.php')
                    ),
                    'marrison_download_update_log_' . $filename
                ),
            ];
        }
        return $items;
    }

    private function mcu_cleanup_update_logs($force = false) {
        $cleanup_key = 'marrison_update_log_cleanup_ran';
        if (!$force && get_transient($cleanup_key) !== false) {
            return;
        }

        $retention_months = (int) apply_filters('mcu_update_log_retention_months', 6);
        if ($retention_months < 1) {
            $retention_months = 1;
        }

        $cutoff = strtotime('-' . $retention_months . ' months', current_time('timestamp'));
        foreach ($this->mcu_get_update_log_files() as $file) {
            if (filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }

        $day = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
        $month = defined('MONTH_IN_SECONDS') ? MONTH_IN_SECONDS : 30 * $day;
        set_transient($cleanup_key, 1, $month);
    }

    private function mcu_redact_url_for_log($url) {
        $parts = wp_parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return $url;
        }

        $safe = '';
        if (!empty($parts['scheme'])) {
            $safe .= $parts['scheme'] . '://';
        }
        $safe .= $parts['host'];
        if (!empty($parts['port'])) {
            $safe .= ':' . $parts['port'];
        }
        if (!empty($parts['path'])) {
            $safe .= $parts['path'];
        }
        if (!empty($parts['query'])) {
            $safe .= '?[redacted-query]';
        }
        return $safe;
    }

    private function mcu_normalize_log_context($value, $depth = 0) {
        if ($depth > 4) {
            return '[max-depth]';
        }
        if (is_wp_error($value)) {
            return [
                'error_code'    => $value->get_error_code(),
                'error_message' => $value->get_error_message(),
                'error_data'    => $this->mcu_normalize_log_context($value->get_error_data(), $depth + 1),
            ];
        }
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $key_string = is_string($key) ? $key : (string) $key;
                if (preg_match('/(password|secret|token|key|nonce|auth|signature|license)/i', $key_string)) {
                    $normalized[$key_string] = '[redacted]';
                } else {
                    $normalized[$key_string] = $this->mcu_normalize_log_context($item, $depth + 1);
                }
            }
            return $normalized;
        }
        if (is_object($value)) {
            return $this->mcu_normalize_log_context(get_object_vars($value), $depth + 1);
        }
        if (is_string($value)) {
            $string = filter_var($value, FILTER_VALIDATE_URL) ? $this->mcu_redact_url_for_log($value) : $value;
            return strlen($string) > 2000 ? substr($string, 0, 2000) . '[truncated]' : $string;
        }
        if (is_resource($value)) {
            return '[resource]';
        }
        return $value;
    }

    private function mcu_log_event($level, $event, $context = []) {
        $this->mcu_cleanup_update_logs();

        $entry = [
            'time'       => current_time('mysql'),
            'level'      => $level,
            'event'      => $event,
            'run_id'     => $this->mcu_get_update_run_id(),
            'site_url'   => function_exists('home_url') ? home_url() : '',
            'memory'     => function_exists('memory_get_usage') ? memory_get_usage(true) : 0,
            'context'    => $this->mcu_normalize_log_context($context),
        ];

        $encoded = function_exists('wp_json_encode')
            ? wp_json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : json_encode($entry);

        if (!$encoded) {
            return false;
        }

        $file = $this->mcu_get_update_log_file();
        return (bool) @file_put_contents($file, $encoded . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function mcu_error_message($result, $fallback = '') {
        if (is_wp_error($result)) {
            return $result->get_error_message();
        }
        if (is_string($result) && $result !== '') {
            return $result;
        }
        return $fallback !== '' ? $fallback : __('Errore sconosciuto', 'marrison-custom-updater');
    }

    private function mcu_upgrader_failure_message($skin = null, $upgrader = null, $fallback = '') {
        $messages = [];

        $this->mcu_collect_upgrader_messages($skin, $messages);
        $this->mcu_collect_upgrader_messages($upgrader, $messages);

        if (is_object($upgrader) && isset($upgrader->skin) && $upgrader->skin !== $skin) {
            $this->mcu_collect_upgrader_messages($upgrader->skin, $messages);
        }

        $messages = array_values(array_unique(array_filter($messages)));
        $diagnostic = array_values(array_filter($messages, [$this, 'mcu_is_diagnostic_upgrader_message']));
        $selected = !empty($diagnostic) ? $diagnostic : $messages;

        if (!empty($selected)) {
            return implode(' | ', array_slice($selected, -3));
        }

        return $fallback !== '' ? $fallback : __('Aggiornamento fallito: WordPress non ha restituito dettagli tecnici.', 'marrison-custom-updater');
    }

    private function mcu_collect_upgrader_messages($value, &$messages, $depth = 0) {
        if ($depth > 3 || $value === null || $value === false) {
            return;
        }

        if (is_wp_error($value)) {
            foreach ($value->get_error_codes() as $code) {
                foreach ($value->get_error_messages($code) as $message) {
                    $this->mcu_add_upgrader_message($message, $messages);
                }

                $data = $value->get_error_data($code);
                if ($data !== null) {
                    $this->mcu_collect_upgrader_messages($data, $messages, $depth + 1);
                }
            }
            return;
        }

        if (is_string($value) || is_numeric($value)) {
            $this->mcu_add_upgrader_message($value, $messages);
            return;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                $this->mcu_collect_upgrader_messages($item, $messages, $depth + 1);
            }
            return;
        }

        if (!is_object($value)) {
            return;
        }

        if (method_exists($value, 'get_errors')) {
            $this->mcu_collect_upgrader_messages($value->get_errors(), $messages, $depth + 1);
        }

        if (method_exists($value, 'get_upgrade_messages')) {
            $this->mcu_collect_upgrader_messages($value->get_upgrade_messages(), $messages, $depth + 1);
        }

        foreach (['result', 'errors', 'messages', 'feedback'] as $property) {
            if (isset($value->$property)) {
                $this->mcu_collect_upgrader_messages($value->$property, $messages, $depth + 1);
            }
        }
    }

    private function mcu_add_upgrader_message($message, &$messages) {
        if (!is_scalar($message)) {
            return;
        }

        $message = (string) $message;
        if (function_exists('wp_strip_all_tags')) {
            $message = wp_strip_all_tags($message);
        } else {
            $message = strip_tags($message);
        }

        $message = html_entity_decode($message, ENT_QUOTES, 'UTF-8');
        $message = preg_replace_callback('#https?://[^\s<>"\']+#i', function($matches) {
            return $this->mcu_redact_url_for_log($matches[0]);
        }, $message);
        $message = trim(preg_replace('/\s+/', ' ', $message));

        if ($message === '') {
            return;
        }

        if (strlen($message) > 500) {
            $message = substr($message, 0, 500) . '[truncated]';
        }

        $messages[] = $message;
    }

    private function mcu_is_diagnostic_upgrader_message($message) {
        $message = is_string($message) ? $message : '';
        if ($message === '') {
            return false;
        }

        $lower = function_exists('mb_strtolower') ? mb_strtolower($message, 'UTF-8') : strtolower($message);
        $keywords = [
            'error',
            'errore',
            'failed',
            'failure',
            'fallito',
            'fallita',
            'impossibile',
            'unable',
            'could not',
            'not found',
            'non trovato',
            'non trovata',
            'not available',
            'non disponibile',
            'forbidden',
            'unauthorized',
            'pclzip',
            'cURL',
            'download_failed',
        ];

        foreach ($keywords as $keyword) {
            if (strpos($lower, strtolower($keyword)) !== false) {
                return true;
            }
        }

        return false;
    }

    private function mcu_has_local_update_lock() {
        return !empty($this->mcu_update_lock_token);
    }

    private function mcu_update_lock_timeout_seconds() {
        $minute = defined('MINUTE_IN_SECONDS') ? MINUTE_IN_SECONDS : 60;
        $hour = defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600;
        $timeout = (int) apply_filters('mcu_update_lock_timeout', 2 * $hour);
        if ($timeout < 5 * $minute) {
            $timeout = 5 * $minute;
        }
        return $timeout;
    }

    private function mcu_update_lock_stale_after_seconds() {
        $minute = defined('MINUTE_IN_SECONDS') ? MINUTE_IN_SECONDS : 60;
        $stale_after = (int) apply_filters('mcu_update_lock_stale_after', 10 * $minute);
        if ($stale_after < 10 * $minute) {
            $stale_after = 10 * $minute;
        }
        return $stale_after;
    }

    private function mcu_update_lock_last_activity($lock) {
        if (!is_array($lock)) {
            return 0;
        }
        if (!empty($lock['heartbeat_at'])) {
            return (int) $lock['heartbeat_at'];
        }
        if (!empty($lock['started_at_unix'])) {
            return (int) $lock['started_at_unix'];
        }
        if (!empty($lock['expires'])) {
            return max(0, (int) $lock['expires'] - $this->mcu_update_lock_timeout_seconds());
        }
        return 0;
    }

    private function mcu_is_update_lock_stale($lock) {
        if (!is_array($lock) || empty($lock['expires'])) {
            return false;
        }
        if ((int) $lock['expires'] <= time()) {
            return true;
        }
        $last_activity = $this->mcu_update_lock_last_activity($lock);
        return $last_activity > 0 && (time() - $last_activity) > $this->mcu_update_lock_stale_after_seconds();
    }

    private function mcu_acquire_update_lock($operation, $context = []) {
        if ($this->mcu_has_local_update_lock()) {
            $this->mcu_touch_update_lock(['operation' => $operation, 'context' => $context]);
            return $this->mcu_update_lock_token;
        }

        if (method_exists($this, 'mcu_recover_stale_cron_log_if_needed')) {
            $this->mcu_recover_stale_cron_log_if_needed(['operation' => $operation, 'context' => $context]);
        }

        $existing = get_transient('marrison_update_lock');
        if (is_array($existing) && (!empty($existing['expires']) && (int) $existing['expires'] <= time())) {
            delete_transient('marrison_update_lock');
            $this->mcu_log_event('warning', 'update_lock_expired_cleared', [
                'operation' => $operation,
                'existing'  => $existing,
                'context'   => $context,
            ]);
            $existing = false;
        }

        if (is_array($existing) && !empty($existing['expires']) && (int) $existing['expires'] > time()) {
            if ($this->mcu_is_update_lock_stale($existing)) {
                delete_transient('marrison_update_lock');
                $this->mcu_log_event('warning', 'update_lock_stale_cleared', [
                    'operation' => $operation,
                    'existing'  => $existing,
                    'context'   => $context,
                ]);
            } else {
                $this->mcu_log_event('warning', 'update_lock_blocked', [
                    'operation' => $operation,
                    'existing'  => $existing,
                    'context'   => $context,
                ]);
                return new WP_Error(
                    'mcu_update_locked',
                    sprintf(
                        __('Aggiornamento gia in corso (%s). Riprova tra qualche minuto.', 'marrison-custom-updater'),
                        isset($existing['operation']) ? $existing['operation'] : 'unknown'
                    )
                );
            }
        }

        $timeout = $this->mcu_update_lock_timeout_seconds();
        $token = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : md5(uniqid('mcu-lock', true));
        $lock = [
            'token'              => $token,
            'operation'          => $operation,
            'started_at'         => current_time('mysql'),
            'started_at_unix'    => time(),
            'heartbeat_at'       => time(),
            'heartbeat_at_mysql' => current_time('mysql'),
            'expires'            => time() + $timeout,
            'run_id'             => $this->mcu_get_update_run_id(),
        ];

        set_transient('marrison_update_lock', $lock, $timeout);
        $this->mcu_update_lock_token = $token;
        $this->mcu_log_event('info', 'update_lock_acquired', [
            'operation' => $operation,
            'context'   => $context,
        ]);

        return $token;
    }

    private function mcu_touch_update_lock($context = []) {
        if (!$this->mcu_has_local_update_lock()) {
            return false;
        }

        $existing = get_transient('marrison_update_lock');
        if (!is_array($existing) || empty($existing['token']) || !hash_equals((string) $existing['token'], (string) $this->mcu_update_lock_token)) {
            return false;
        }

        $now = time();
        $stage_changed = false;
        if (!empty($context['stage'])) {
            $next_stage = sanitize_key((string) $context['stage']);
            $stage_changed = $next_stage !== (string) ($existing['stage'] ?? '');
            $existing['stage'] = $next_stage;
        }

        if (empty($context['force']) && !$stage_changed && !empty($existing['heartbeat_at']) && ($now - (int) $existing['heartbeat_at']) < 30) {
            return true;
        }

        $existing['heartbeat_at'] = $now;
        $existing['heartbeat_at_mysql'] = current_time('mysql');
        $remaining = !empty($existing['expires']) ? max(300, (int) $existing['expires'] - $now) : $this->mcu_update_lock_timeout_seconds();
        set_transient('marrison_update_lock', $existing, $remaining);

        $this->mcu_log_event('info', 'update_lock_heartbeat', [
            'operation' => isset($existing['operation']) ? $existing['operation'] : 'unknown',
            'context'   => $context,
        ]);

        return true;
    }

    private function mcu_release_update_lock($token = '') {
        $token = $token !== '' ? $token : $this->mcu_update_lock_token;
        $existing = get_transient('marrison_update_lock');
        if (!is_array($existing) || empty($existing['token']) || hash_equals((string) $existing['token'], (string) $token)) {
            delete_transient('marrison_update_lock');
        }
        $this->mcu_log_event('info', 'update_lock_released', ['token' => $token]);
        if (hash_equals((string) $this->mcu_update_lock_token, (string) $token)) {
            $this->mcu_update_lock_token = '';
        }
    }

    private function mcu_clear_master_update_cron_events() {
        $cleared = 0;

        if (!function_exists('_get_cron_array') || !function_exists('wp_unschedule_event')) {
            return $cleared;
        }

        $crons = _get_cron_array();
        if (!is_array($crons)) {
            return $cleared;
        }

        foreach ($crons as $timestamp => $hooks) {
            if (empty($hooks['mcu_master_update_event']) || !is_array($hooks['mcu_master_update_event'])) {
                continue;
            }

            foreach ($hooks['mcu_master_update_event'] as $event) {
                $args = isset($event['args']) && is_array($event['args']) ? $event['args'] : [];
                $result = wp_unschedule_event((int) $timestamp, 'mcu_master_update_event', $args);
                if (!is_wp_error($result) && $result !== false) {
                    $cleared++;
                }
            }
        }

        return $cleared;
    }

    public function mcu_clear_update_lock_admin_action() {
        check_admin_referer('mcu_clear_update_lock');
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permessi insufficienti.', 'marrison-custom-updater'));
        }

        $existing = get_transient('marrison_update_lock');
        $master_status = get_option('mcu_master_update_status', []);
        $cleared_master_cron_events = $this->mcu_clear_master_update_cron_events();
        delete_transient('marrison_update_lock');

        $message = __('Cron/update bloccato azzerato manualmente.', 'marrison-custom-updater');
        if (method_exists($this, 'mcu_close_interrupted_cron_log')) {
            $this->mcu_close_interrupted_cron_log($message, [
                'source' => 'master',
                'context' => 'manual_unlock',
            ]);
        } else {
            $last_log = get_option('marrison_last_cron_log', []);
            if (is_array($last_log) && sanitize_key((string) ($last_log['status'] ?? '')) === 'started') {
                $last_log['status'] = 'error';
                $last_log['message'] = $message;
                $last_log['interrupted'] = true;
                $last_log['recovered_at'] = current_time('mysql');
                update_option('marrison_last_cron_log', $last_log);
            }
        }

        if (method_exists($this, 'mcu_mark_master_update_failed_if_running')) {
            $this->mcu_mark_master_update_failed_if_running($message, get_option('marrison_last_cron_log', []));
        }

        $last_log = get_option('marrison_last_cron_log', []);
        if (is_array($last_log)) {
            $last_log['manual_cleared'] = true;
            $last_log['manual_cleared_at'] = current_time('mysql');
            update_option('marrison_last_cron_log', $last_log);
        }

        $this->mcu_log_event('warning', 'update_lock_manually_cleared', [
            'existing' => is_array($existing) ? $existing : [],
            'master_status' => is_array($master_status) ? $master_status : [],
            'cleared_master_cron_events' => $cleared_master_cron_events,
        ]);

        wp_redirect(admin_url('admin.php?page=marrison-updater-settings&tab=scheduling&mcu_lock_cleared=1'));
        exit;
    }

    private function mcu_capture_active_plugin_snapshot($context = []) {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $snapshot = [
            'active_plugins'  => array_values((array) get_option('active_plugins', [])),
            'network_plugins' => (array) get_site_option('active_sitewide_plugins', []),
        ];

        $this->mcu_log_event('info', 'active_plugin_snapshot_captured', [
            'active_count'  => count($snapshot['active_plugins']),
            'network_count' => count($snapshot['network_plugins']),
            'woo_active'    => in_array('woocommerce/woocommerce.php', $snapshot['active_plugins'], true) || isset($snapshot['network_plugins']['woocommerce/woocommerce.php']),
            'context'       => $context,
        ]);

        return $snapshot;
    }

    private function mcu_plugin_activation_priority($plugin_file) {
        if ($plugin_file === 'woocommerce/woocommerce.php') {
            return 0;
        }

        $path = WP_PLUGIN_DIR . '/' . $plugin_file;
        if (file_exists($path) && function_exists('get_plugin_data')) {
            $data = get_plugin_data($path, false, false);
            $requires = isset($data['RequiresPlugins']) ? strtolower((string) $data['RequiresPlugins']) : '';
            if (strpos($requires, 'woocommerce') !== false) {
                return 10;
            }
        }

        return 20;
    }

    private function mcu_sort_plugins_for_activation($plugins) {
        usort($plugins, function($a, $b) {
            $priority = $this->mcu_plugin_activation_priority($a) - $this->mcu_plugin_activation_priority($b);
            if ($priority !== 0) {
                return $priority;
            }
            return strcmp($a, $b);
        });
        return $plugins;
    }

    private function mcu_restore_active_plugin_snapshot($snapshot, $context = []) {
        if (empty($snapshot) || !is_array($snapshot)) {
            return;
        }
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $active_before = isset($snapshot['active_plugins']) ? (array) $snapshot['active_plugins'] : [];
        $network_before = isset($snapshot['network_plugins']) ? (array) $snapshot['network_plugins'] : [];
        $active_now = array_values((array) get_option('active_plugins', []));
        $network_now = (array) get_site_option('active_sitewide_plugins', []);

        $missing = array_values(array_diff($active_before, $active_now));
        $missing_network = array_values(array_diff(array_keys($network_before), array_keys($network_now)));
        $reactivated = [];
        $failed = [];

        foreach ($this->mcu_sort_plugins_for_activation($missing_network) as $plugin_file) {
            if (!file_exists(WP_PLUGIN_DIR . '/' . $plugin_file)) {
                $failed[$plugin_file] = 'Plugin file missing after update';
                continue;
            }
            if (is_multisite() && function_exists('is_plugin_active_for_network') && is_plugin_active_for_network($plugin_file)) {
                continue;
            }
            $activate = activate_plugin($plugin_file, '', true, true);
            if (is_wp_error($activate)) {
                $failed[$plugin_file] = $activate->get_error_message();
            } else {
                $reactivated[] = $plugin_file;
            }
        }

        foreach ($this->mcu_sort_plugins_for_activation($missing) as $plugin_file) {
            if (!file_exists(WP_PLUGIN_DIR . '/' . $plugin_file)) {
                $failed[$plugin_file] = 'Plugin file missing after update';
                continue;
            }
            if (is_plugin_active($plugin_file)) {
                continue;
            }
            $activate = activate_plugin($plugin_file, '', false, true);
            if (is_wp_error($activate)) {
                $failed[$plugin_file] = $activate->get_error_message();
            } else {
                $reactivated[] = $plugin_file;
            }
        }

        if (!empty($missing) || !empty($missing_network) || !empty($reactivated) || !empty($failed)) {
            $this->mcu_log_event(empty($failed) ? 'info' : 'warning', 'active_plugin_snapshot_restored', [
                'missing'           => $missing,
                'missing_network'   => $missing_network,
                'reactivated'       => $reactivated,
                'failed'            => $failed,
                'context'           => $context,
            ]);
        }
    }

    private function mcu_flush_update_caches($context = []) {
        $flushed = [];
        $active_lock = get_transient('marrison_update_lock');

        $this->delete_internal_cache();
        $flushed[] = 'mcu_internal';

        delete_site_transient('update_plugins');
        delete_site_transient('update_themes');
        delete_site_transient('update_core');
        $flushed[] = 'wp_update_transients';

        if (function_exists('wp_clean_plugins_cache')) {
            wp_clean_plugins_cache(true);
            $flushed[] = 'plugin_cache';
        }
        if (function_exists('wp_clean_themes_cache')) {
            wp_clean_themes_cache(true);
            $flushed[] = 'theme_cache';
        }
        if (function_exists('wp_clean_update_cache')) {
            wp_clean_update_cache();
            $flushed[] = 'update_cache';
        }
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
            $flushed[] = 'object_cache';
            if (is_array($active_lock) && !empty($active_lock['token']) && !empty($active_lock['expires']) && (int) $active_lock['expires'] > time()) {
                if ($this->mcu_is_update_lock_stale($active_lock)) {
                    delete_transient('marrison_update_lock');
                    $flushed[] = 'stale_update_lock_cleared';
                } else {
                    $remaining = !empty($active_lock['expires']) ? max(300, (int) $active_lock['expires'] - time()) : 300;
                    set_transient('marrison_update_lock', $active_lock, $remaining);
                    $flushed[] = 'update_lock_preserved';
                }
            }
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
            $flushed[] = 'opcache';
        }

        $cache_callbacks = [
            'wp_rocket'      => 'rocket_clean_domain',
            'w3_total_cache' => 'w3tc_flush_all',
            'wp_fastest'     => 'wpfc_clear_all_cache',
            'siteground'     => 'sg_cachepress_purge_cache',
        ];
        foreach ($cache_callbacks as $name => $callback) {
            if (function_exists($callback)) {
                try {
                    call_user_func($callback);
                    $flushed[] = $name;
                } catch (Throwable $e) {
                    $this->mcu_log_event('warning', 'cache_flush_callback_failed', [
                        'cache' => $name,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if (class_exists('LiteSpeed_Cache_API') && method_exists('LiteSpeed_Cache_API', 'purge_all')) {
            try {
                LiteSpeed_Cache_API::purge_all();
                $flushed[] = 'litespeed';
            } catch (Throwable $e) {
                $this->mcu_log_event('warning', 'cache_flush_callback_failed', [
                    'cache' => 'litespeed',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        clearstatcache();
        $this->mcu_log_event('info', 'update_cache_flush_completed', [
            'flushed' => $flushed,
            'context' => $context,
        ]);
    }

    private function mcu_run_update_guard($operation, $context, $callback) {
        if (!\MarrisonCustomUpdater\MaintenanceClient\Settings::repository_config_managed()) {
            return new WP_Error(
                'mcu_client_not_authorized',
                __('Operazione bloccata: il client MCU non è autorizzato da Commander.', 'marrison-custom-updater')
            );
        }

        $already_locked = $this->mcu_has_local_update_lock();
        $token = $this->mcu_acquire_update_lock($operation, $context);
        if (is_wp_error($token)) {
            return $token;
        }

        $owns_lock = !$already_locked;
        $snapshot = $owns_lock ? $this->mcu_capture_active_plugin_snapshot(['operation' => $operation, 'context' => $context]) : null;
        $started = microtime(true);
        $result = null;

        $this->mcu_log_event('info', 'update_operation_started', [
            'operation' => $operation,
            'context'   => $context,
        ]);
        $this->mcu_touch_update_lock(['operation' => $operation, 'stage' => 'started', 'context' => $context]);

        try {
            $result = call_user_func($callback);
        } catch (Throwable $e) {
            $result = new WP_Error(
                'mcu_update_exception',
                $e->getMessage(),
                [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]
            );
        }
        $this->mcu_touch_update_lock(['operation' => $operation, 'stage' => 'callback_finished', 'context' => $context]);

        if ($owns_lock) {
            $this->mcu_flush_update_caches(['operation' => $operation, 'context' => $context]);
            $this->mcu_restore_active_plugin_snapshot($snapshot, ['operation' => $operation, 'context' => $context]);
        }

        $this->mcu_log_event(is_wp_error($result) || !$result ? 'error' : 'info', 'update_operation_finished', [
            'operation' => $operation,
            'duration'  => round(microtime(true) - $started, 3),
            'result'    => is_wp_error($result) ? $result : (bool) $result,
            'context'   => $context,
        ]);

        if ($owns_lock) {
            $this->mcu_release_update_lock($token);
        }

        return $result;
    }

    public function download_update_log() {
        $filename = sanitize_file_name($_GET['file'] ?? '');
        if (empty($filename) || !preg_match('/^mcu-update-\d{4}-\d{2}\.log$/', $filename)) {
            wp_die(esc_html__('File log non valido.', 'marrison-custom-updater'));
        }

        check_admin_referer('marrison_download_update_log_' . $filename);
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permessi insufficienti.', 'marrison-custom-updater'));
        }

        $dir = realpath($this->mcu_get_update_log_dir());
        $file = realpath($this->mcu_get_update_log_dir() . '/' . $filename);
        if (!$dir || !$file || strpos(wp_normalize_path($file), trailingslashit(wp_normalize_path($dir))) !== 0 || !is_readable($file)) {
            wp_die(esc_html__('File log non trovato.', 'marrison-custom-updater'));
        }

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        header('Content-Description: File Transfer');
        header('Content-Type: text/plain; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: no-cache, must-revalidate');
        readfile($file);
        exit;
    }

    public function clear_update_logs() {
        check_admin_referer('marrison_clear_update_logs');
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permessi insufficienti.', 'marrison-custom-updater'));
        }

        foreach ($this->mcu_get_update_log_files() as $file) {
            @unlink($file);
        }
        delete_transient('marrison_update_log_cleanup_ran');

        wp_redirect(admin_url('admin.php?page=marrison-updater-settings&tab=logs&logs_cleared=1'));
        exit;
    }

    public function check_for_updates($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        // --- Filter out excluded plugins from ALL updates (including public repo) ---
        if (!empty($transient->response)) {
            if (!function_exists('get_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $all_plugins = get_plugins();
            foreach ($transient->response as $plugin_file => $data) {
                $slug = dirname($plugin_file);
                if ($slug === '.' || $slug === '') $slug = basename($plugin_file, '.php');
                $plugin_name = isset($all_plugins[$plugin_file]['Name']) ? $all_plugins[$plugin_file]['Name'] : '';

                if ($this->mcu_is_plugin_update_excluded($slug, $plugin_file, $plugin_name, $data)) {
                    unset($transient->response[$plugin_file]);
                }
            }
        }

        $updates = $this->get_available_updates();

        foreach ($updates as $update) {
            $slug = $update['slug'];

            $plugin_file = $this->find_plugin_file($slug, $update['name'] ?? '');
            if ($this->mcu_is_plugin_update_excluded($slug, $plugin_file, $update['name'] ?? '', $update)) {
                continue;
            }

            if ($plugin_file && isset($transient->checked[$plugin_file])) {
                $current_version = $transient->checked[$plugin_file];
                
                if (version_compare($current_version, $update['version'], '<')) {
                    $plugin_data = new stdClass();
                    $plugin_data->slug = $slug;
                    $plugin_data->plugin = $plugin_file;
                    $plugin_data->new_version = $update['version'];
                    $plugin_data->url = $update['info_url'] ?? '';
                    $plugin_data->package = $update['download_url'];
                    $plugin_data->icons = isset($update['icons']) ? (array)$update['icons'] : [];
                    $plugin_data->banners = isset($update['banners']) ? (array)$update['banners'] : [];
                    $plugin_data->banners_rtl = isset($update['banners_rtl']) ? (array)$update['banners_rtl'] : [];
                    
                    $transient->response[$plugin_file] = $plugin_data;
                }
            }
        }
        return $transient;
    }

    public function check_for_theme_updates($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        // --- Filter out excluded themes from ALL updates (including public repo) ---
        if (!empty($transient->response)) {
            $excluded_themes = get_option('marrison_excluded_themes', []);
            if (!empty($excluded_themes)) {
                foreach ($excluded_themes as $excluded_slug) {
                    if (isset($transient->response[$excluded_slug])) {
                        unset($transient->response[$excluded_slug]);
                    }
                }
            }
        }

        $updates = $this->get_available_theme_updates();

        foreach ($updates as $update) {
            $slug = $update['slug'];

            if ($this->is_item_excluded($slug, 'theme')) {
                continue;
            }

            $theme = wp_get_theme($slug);

            if ($theme->exists()) {
                $current_version = $theme->get('Version');
                if (version_compare($current_version, $update['version'], '<')) {
                    $theme_data = [];
                    $theme_data['theme'] = $slug;
                    $theme_data['new_version'] = $update['version'];
                    $theme_data['url'] = $update['info_url'] ?? '';
                    $theme_data['package'] = $update['download_url'];
                    
                    $transient->response[$slug] = $theme_data;
                }
            }
        }
        return $transient;
    }

    public function plugin_info($res, $action, $args) {
        if ($action !== 'plugin_information') {
            return $res;
        }

        if (empty($args->slug)) {
            return $res;
        }

        $updates = $this->get_available_updates();
        foreach ($updates as $update) {
            if ($update['slug'] === $args->slug) {
                $res = new stdClass();
                $res->name = $update['name'];
                $res->slug = $update['slug'];
                $res->version = $update['version'];
                $res->tested = $update['tested'] ?? '';
                $res->requires = $update['requires'] ?? '';
                $res->author = $update['author'] ?? '';
                $res->author_profile = $update['author_profile'] ?? '';
                $res->download_link = $update['download_url'];
                $res->trunk = $update['download_url'];
                $res->requires_php = $update['requires_php'] ?? '';
                $res->last_updated = $update['last_updated'] ?? '';
                $res->sections = [
                    'description' => $update['description'] ?? 'No description provided.',
                    'installation' => $update['installation'] ?? 'No installation instructions provided.',
                    'changelog' => $update['changelog'] ?? 'No changelog provided.'
                ];
                $res->banners = isset($update['banners']) ? (array)$update['banners'] : [];
                return $res;
            }
        }

        return $res;
    }

    private function find_plugin_file($slug, $name = '') {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_plugins = get_plugins();
        
        // 1. Cerca per dirname (cartella dello slug)
        foreach ($all_plugins as $file => $data) {
            if (dirname($file) === $slug) {
                return $file;
            }
        }
        
        // 2. Cerca per nome esatto (se fornito)
        if (!empty($name)) {
            foreach ($all_plugins as $file => $data) {
                if ($data['Name'] === $name) {
                    return $file;
                }
            }
        }

        // 3. Fallback: cerca se il file inizia con lo slug
        foreach ($all_plugins as $file => $data) {
            if (strpos($file, $slug . '/') === 0 || $file === $slug . '.php') {
                return $file;
            }
        }

        return false;
    }
    
    public function delete_internal_cache() {
        delete_transient('marrison_available_updates_v2');
        delete_transient('marrison_available_theme_updates');
    }


    private function mcu_safe_private_update_slug($slug) {
        $slug = sanitize_text_field((string) $slug);
        $slug = preg_replace('/[^A-Za-z0-9._-]/', '', $slug);
        return substr((string) $slug, 0, 180);
    }

    private function perform_update($slug) {
        $slug = $this->mcu_safe_private_update_slug($slug);

        return $this->mcu_run_update_guard('private_plugin_update', ['slug' => $slug], function() use ($slug) {
        global $wp_filesystem;
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
        if (!$wp_filesystem) return new WP_Error('fs_init_failed', __('Impossibile inizializzare il filesystem.', 'marrison-custom-updater'));
        foreach ($this->get_available_updates() as $update) {
            $update_slug = $this->mcu_safe_private_update_slug($update['slug'] ?? '');
            if ($update_slug !== $slug) continue;
            $plugin_file = $this->find_plugin_file($update_slug, $update['name'] ?? '');
            if ($this->mcu_is_plugin_update_excluded($update_slug, $plugin_file, $update['name'] ?? '', $update)) {
                return new WP_Error('plugin_update_excluded', __('Plugin escluso dagli aggiornamenti.', 'marrison-custom-updater'));
            }
            $this->mcu_log_event('info', 'private_plugin_download_started', [
                'slug'        => $update_slug,
                'version'     => $update['version'] ?? '',
                'download_url' => $update['download_url'] ?? '',
            ]);
            $zip = download_url($update['download_url']);
            if (is_wp_error($zip)) {
                $this->mcu_log_event('error', 'private_plugin_download_failed', [
                    'slug'  => $slug,
                    'error' => $zip,
                ]);
                return $zip;
            }
            $current_version = '';
            if ($plugin_file) {
                if (!function_exists('get_plugins')) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }
                $all_plugins = get_plugins();
                if (isset($all_plugins[$plugin_file])) {
                    $current_version = $all_plugins[$plugin_file]['Version'];
                }
            }
            $backup_created = $this->create_backup($slug, $current_version, 'plugin', $plugin_file);
            $this->mcu_log_event($backup_created ? 'info' : 'warning', 'private_plugin_backup_created', [
                'slug'            => $slug,
                'plugin_file'     => $plugin_file,
                'current_version' => $current_version,
                'backup_created'  => (bool) $backup_created,
            ]);
            $upgrade_dir = WP_CONTENT_DIR . '/upgrade/marrison-' . $slug;
            wp_mkdir_p($upgrade_dir);
            $unzip = unzip_file($zip, $upgrade_dir);
            unlink($zip);
            
            if (is_wp_error($unzip)) {
                $this->mcu_log_event('error', 'private_plugin_unzip_failed', [
                    'slug'  => $slug,
                    'error' => $unzip,
                ]);
                return $unzip;
            }

            $dirs = glob($upgrade_dir . '/*', GLOB_ONLYDIR);
            if (empty($dirs)) {
                return new WP_Error('empty_archive', __('Archivio vuoto o non valido.', 'marrison-custom-updater'));
            } 
            $source = trailingslashit($dirs[0]);
            $dest_folder = $slug;
            if ($plugin_file) {
                $installed_dir = dirname($plugin_file);
                if ($installed_dir !== '.' && $installed_dir !== '') {
                    $dest_folder = $installed_dir;
                }
            }
            $dest      = trailingslashit(WP_PLUGIN_DIR . '/' . $dest_folder);
            $dest_temp = WP_PLUGIN_DIR . '/' . $dest_folder . '-marrison-new-' . time();
            $dest_old  = WP_PLUGIN_DIR . '/' . $dest_folder . '-marrison-old-' . time();

            // 1. Copy new files to a temporary directory first (no destructive action yet)
            $result = copy_dir($source, $dest_temp);
            $wp_filesystem->delete($upgrade_dir, true);

            if (is_wp_error($result)) {
                $wp_filesystem->delete($dest_temp, true);
                return $result;
            }

            // 2. Atomic swap: rename old → backup, new → final
            $old_exists = $wp_filesystem->is_dir($dest);
            if ($old_exists) {
                if (!rename(untrailingslashit($dest), $dest_old)) {
                    $wp_filesystem->delete($dest_temp, true);
                    return new WP_Error('rename_old_failed', __('Impossibile rinominare la directory del plugin esistente.', 'marrison-custom-updater'));
                }
            }

            if (!rename($dest_temp, untrailingslashit($dest))) {
                // Restore old directory before returning error
                if ($old_exists && is_dir($dest_old)) {
                    rename($dest_old, untrailingslashit($dest));
                }
                $wp_filesystem->delete($dest_temp, true);
                return new WP_Error('rename_new_failed', __('Impossibile spostare la nuova versione del plugin.', 'marrison-custom-updater'));
            }

            // 3. Delete the old backup directory
            if ($old_exists && is_dir($dest_old)) {
                $wp_filesystem->delete($dest_old, true);
            }
            
            $this->mcu_flush_update_caches([
                'operation' => 'private_plugin_update',
                'slug'      => $slug,
            ]);
            update_option('marrison_last_plugins_update_time', current_time('mysql'));
            return true;
        }
        return new WP_Error('update_not_found', __('Aggiornamento non trovato.', 'marrison-custom-updater'));
        });
    }

    private function perform_self_update($download_url) {
        return $this->mcu_run_update_guard('self_update', ['download_url' => $download_url], function() use ($download_url) {
        global $wp_filesystem;
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
        if (!$wp_filesystem) return new WP_Error('fs_error', 'Filesystem init failed');
        
        $zip = download_url($download_url);
        if (is_wp_error($zip)) return $zip;
        
        $upgrade_dir = WP_CONTENT_DIR . '/upgrade/marrison-custom-updater-temp';
        $wp_filesystem->delete($upgrade_dir, true); // Clean previous attempts
        wp_mkdir_p($upgrade_dir);
        
        $unzip = unzip_file($zip, $upgrade_dir);
        @unlink($zip);
        
        if (is_wp_error($unzip)) return $unzip;
        
        $dirs = glob($upgrade_dir . '/*', GLOB_ONLYDIR);
        if (empty($dirs)) {
             $wp_filesystem->delete($upgrade_dir, true);
             return new WP_Error('empty_zip', 'Zip archive is empty or invalid structure');
        }
        
        $source = trailingslashit($dirs[0]);
        $dest   = trailingslashit(WP_PLUGIN_DIR . '/marrison-custom-updater');
        
        // Strategy: Move current to backup, copy new, if fail restore backup
        $backup_dest = WP_PLUGIN_DIR . '/marrison-custom-updater-backup-' . time();
        $moved_backup = false;
        
        if ($wp_filesystem->is_dir($dest)) {
            // Try to move to backup
            if (!$wp_filesystem->move($dest, $backup_dest)) {
                // If move fails, try direct delete (fallback, risky but standard)
                // But better to fail safe if we can't backup
                // Let's try to proceed with delete if move failed? 
                // No, let's try copy_dir first to a temp dest? 
                // Actually, standard WP way is Maintenance mode + delete + copy.
                // But we want to avoid deactivation.
                // Let's try standard delete if move fails, but log it?
                // For now, let's assume move works or fail.
                $wp_filesystem->delete($upgrade_dir, true);
                return new WP_Error('backup_failed', 'Could not backup existing version');
            }
            $moved_backup = true;
        }
        
        $result = copy_dir($source, $dest);
        
        if (is_wp_error($result)) {
            // Restore backup
            if ($moved_backup) {
                $wp_filesystem->move($backup_dest, $dest);
            }
            $wp_filesystem->delete($upgrade_dir, true);
            return $result;
        }
        
        // Success
        if ($moved_backup) {
            $wp_filesystem->delete($backup_dest, true);
        }
        $wp_filesystem->delete($upgrade_dir, true);
        
        $this->mcu_flush_update_caches(['operation' => 'self_update']);
        
        return true;
        });
    }

    private function get_backup_dir() {
        $dir = WP_CONTENT_DIR . '/marrison-backups';
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
            file_put_contents($dir . '/index.php', '<?php // Silence is golden');
            file_put_contents($dir . '/.htaccess', 'deny from all');
        }
        return $dir;
    }

    private function cleanup_orphan_plugin_backups() {
        $backup_dir = $this->get_backup_dir();
        if (!is_dir($backup_dir)) {
            return;
        }

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $installed_slugs = [];
        foreach (array_keys(get_plugins()) as $plugin_file) {
            $plugin_dir = dirname($plugin_file);
            if ($plugin_dir === '.' || $plugin_dir === '') {
                $installed_slugs[] = basename($plugin_file, '.php');
            } else {
                $installed_slugs[] = $plugin_dir;
            }
        }
        $installed_slugs = array_values(array_unique(array_filter($installed_slugs)));

        foreach (glob($backup_dir . '/*-backup.zip') as $file) {
            $filename = basename($file);
            $is_plugin_backup = true;
            $parse_name = $filename;

            if (strpos($filename, 'theme-') === 0) {
                continue;
            }

            if (strpos($filename, 'plugin-') === 0) {
                $parse_name = substr($filename, 7);
            }

            $slug = '';
            if (preg_match('/^(.*?)-v.*-backup\.zip$/', $parse_name, $matches)) {
                $slug = $matches[1];
            } elseif (preg_match('/^(.*?)-backup\.zip$/', $parse_name, $matches)) {
                $slug = $matches[1];
            }

            if (!$is_plugin_backup || empty($slug)) {
                continue;
            }

            if (!in_array($slug, $installed_slugs, true)) {
                @unlink($file);
            }
        }
    }

    private function create_backup($slug, $version = '', $type = 'plugin', $known_file = '') {
        $source = '';
        $backup_slug = $slug;
        if ($type === 'plugin') {
            $plugin_file = $known_file ? $known_file : $this->find_plugin_file($slug);
            if (!$plugin_file) return false;
            $plugin_dir = dirname($plugin_file);
            if ($plugin_dir === '.' || $plugin_dir === '') {
                $source = WP_PLUGIN_DIR . '/' . $plugin_file;
                $backup_slug = basename($plugin_file, '.php');
            } else {
                $source = WP_PLUGIN_DIR . '/' . $plugin_dir;
                $backup_slug = $plugin_dir;
            }
        } else {
            $theme = wp_get_theme($slug);
            if (!$theme->exists()) return false;
            $source = get_theme_root() . '/' . $slug;
        }
        if (!file_exists($source)) return false;
        if (empty($backup_slug)) return false;
        $backup_dir = $this->get_backup_dir();
        $pattern = $backup_dir . '/' . $type . '-' . $backup_slug . '-*-backup.zip';
        foreach (glob($pattern) as $f) @unlink($f);
        if ($type === 'plugin') {
            foreach (glob($backup_dir . '/' . $backup_slug . '-*-backup.zip') as $f) @unlink($f);
            if ($backup_slug !== $slug) {
                foreach (glob($backup_dir . '/' . $slug . '-*-backup.zip') as $f) @unlink($f);
                foreach (glob($backup_dir . '/plugin-' . $slug . '-*-backup.zip') as $f) @unlink($f);
            }
        }
        $date = date('Ymd');
        $time = date('His');
        $ver_str = $version ? $version : 'na';
        $filename = sprintf('%s-%s-v%s-%s-%s-backup.zip', $type, $backup_slug, $ver_str, $date, $time);
        $zip_file = $backup_dir . '/' . $filename;
        if (file_exists($zip_file)) @unlink($zip_file);
        if (!class_exists('PclZip')) {
            require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
        }
        $archive = new PclZip($zip_file);
        $remove_path = ($type === 'theme') ? get_theme_root() : WP_PLUGIN_DIR;
        $v_list = $archive->create($source, PCLZIP_OPT_REMOVE_PATH, $remove_path);
        return ($v_list != 0);
    }

    private function perform_restore($filename) {
        return $this->mcu_run_update_guard('restore_backup', ['filename' => $filename], function() use ($filename) {
        try {
            $backup_dir = $this->get_backup_dir();
            $zip_file = $backup_dir . '/' . $filename;
            if (!file_exists($zip_file)) {
                return new WP_Error('not_found', 'Backup not found');
            }
            $type = 'plugin';
            $slug = '';
            if (strpos($filename, 'theme-') === 0) {
                $type = 'theme';
                $remaining = substr($filename, 6);
                if (preg_match('/^(.*?)-v.*-backup\.zip$/', $remaining, $matches)) {
                    $slug = $matches[1];
                } elseif (preg_match('/^(.*?)-backup\.zip$/', $remaining, $matches)) {
                    $slug = $matches[1];
                }
            } elseif (strpos($filename, 'plugin-') === 0) {
                $type = 'plugin';
                $remaining = substr($filename, 7);
                if (preg_match('/^(.*?)-v.*-backup\.zip$/', $remaining, $matches)) {
                    $slug = $matches[1];
                } elseif (preg_match('/^(.*?)-backup\.zip$/', $remaining, $matches)) {
                    $slug = $matches[1];
                }
            } else {
                if (preg_match('/^(.*)-v(.*)-backup\.zip$/', $filename, $matches)) {
                    $slug = $matches[1];
                } else {
                    $slug = str_replace('-backup.zip', '', $filename);
                }
            }
            if (empty($slug) || strpos($slug, '.') !== false || strpos($slug, '/') !== false || strpos($slug, '\\') !== false) {
                return new WP_Error('invalid_slug', 'Invalid slug derived from filename');
            }
            global $wp_filesystem;
            if (!function_exists('WP_Filesystem')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            if ( ! WP_Filesystem() ) {
                return new WP_Error('fs_error', 'Filesystem error - Could not initialize');
            }
            if (!$wp_filesystem) {
                return new WP_Error('fs_error', 'Filesystem error - Object is null');
            }

            // Check if plugin is active before deleting
            $was_active = false;
            if ($type === 'plugin') {
                if (!function_exists('is_plugin_active')) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }
                $current_file = $this->find_plugin_file($slug);
                if ($current_file && is_plugin_active($current_file)) {
                    $was_active = true;
                }
            }

            $dest_root = ($type === 'theme') ? get_theme_root() : WP_PLUGIN_DIR;
            $dest = $dest_root . '/' . $slug;
            if (realpath($dest) === realpath($dest_root)) {
                return new WP_Error('invalid_dest', 'Destination invalid');
            }
            if ($wp_filesystem->is_dir($dest)) {
                $deleted = $wp_filesystem->delete($dest, true);
                if (!$deleted) {
                    $trash_dir = $dest_root . '/.' . $slug . '_trash_' . time();
                    if ($wp_filesystem->move($dest, $trash_dir)) {
                        $wp_filesystem->delete($trash_dir, true);
                    }
                }
            } elseif ($type === 'plugin') {
                $current_file = $this->find_plugin_file($slug);
                if ($current_file && dirname($current_file) === '.') {
                    $single_file_dest = WP_PLUGIN_DIR . '/' . $current_file;
                    if ($wp_filesystem->exists($single_file_dest)) {
                        $wp_filesystem->delete($single_file_dest);
                    }
                }
            }
            $result = unzip_file($zip_file, $dest_root);
            if (is_wp_error($result)) {
                return $result;
            }
            if ($type === 'theme') {
                $this->mcu_flush_update_caches([
                    'operation' => 'restore_backup',
                    'type'      => 'theme',
                    'slug'      => $slug,
                ]);
            } else {
                $this->mcu_flush_update_caches([
                    'operation' => 'restore_backup',
                    'type'      => 'plugin',
                    'slug'      => $slug,
                ]);

                if ($was_active) {
                    $new_file = $this->find_plugin_file($slug);
                    if ($new_file) {
                        activate_plugin($new_file, '', false, true);
                    }
                }
            }
            return $slug;
        } catch (Throwable $e) {
            return new WP_Error('exception', 'Critical error during restore: ' . $e->getMessage());
        } catch (Exception $e) {
            return new WP_Error('exception', 'Exception during restore: ' . $e->getMessage());
        }
        });
    }

    private function perform_theme_update($slug, $download_url) {
        return $this->mcu_run_update_guard('private_theme_update', ['slug' => $slug, 'download_url' => $download_url], function() use ($slug, $download_url) {
            global $wp_filesystem;
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
            if (!$wp_filesystem) {
                return new WP_Error('fs_init_failed', __('Impossibile inizializzare il filesystem.', 'marrison-custom-updater'));
            }

            $theme = wp_get_theme($slug);
            $current_version = $theme->exists() ? $theme->get('Version') : '';
            $backup_created = $this->create_backup($slug, $current_version, 'theme');
            $this->mcu_log_event($backup_created ? 'info' : 'warning', 'private_theme_backup_created', [
                'slug'            => $slug,
                'current_version' => $current_version,
                'backup_created'  => (bool) $backup_created,
            ]);

            $zip = download_url($download_url);
            if (is_wp_error($zip)) {
                return $zip;
            }

            $upgrade_dir = WP_CONTENT_DIR . '/upgrade/marrison-theme-' . $slug;
            wp_mkdir_p($upgrade_dir);
            $unzip = unzip_file($zip, $upgrade_dir);
            @unlink($zip);
            if (is_wp_error($unzip)) {
                return $unzip;
            }

            $dirs = glob($upgrade_dir . '/*', GLOB_ONLYDIR);
            if (empty($dirs)) {
                return new WP_Error('empty_archive', __('Archivio vuoto o non valido.', 'marrison-custom-updater'));
            }

            $source = trailingslashit($dirs[0]);
            $dest = trailingslashit(get_theme_root() . '/' . $slug);
            if ($wp_filesystem->is_dir($dest)) {
                $wp_filesystem->delete($dest, true);
            }

            $copy = copy_dir($source, $dest);
            $wp_filesystem->delete($upgrade_dir, true);
            if (is_wp_error($copy)) {
                return $copy;
            }

            $this->mcu_flush_update_caches([
                'operation' => 'private_theme_update',
                'slug'      => $slug,
            ]);
            return true;
        });
    }

    public function create_db_backup() {
        global $wpdb;

        $backup_dir = $this->get_backup_dir();
        if (!is_dir($backup_dir) || !is_writable($backup_dir)) {
            return new WP_Error('backup_dir_not_writable', __('Directory backup non scrivibile.', 'marrison-custom-updater'));
        }

        $date              = date('Ymd');
        $time              = date('His');
        $prefix            = 'db-backup-' . $date . '-' . $time;
        $sql_filename      = $prefix . '.sql';
        $zip_filename      = $prefix . '.zip';
        $manifest_filename = $prefix . '-manifest.json';
        $sql_path          = $backup_dir . '/' . $sql_filename;
        $zip_path          = $backup_dir . '/' . $zip_filename;
        $manifest_path     = $backup_dir . '/' . $manifest_filename;

        $handle = null;
        $snapshot_started = false;
        $tables_locked = false;
        $snapshot_method = 'transaction';
        $non_transactional_tables = [];
        $sql_bytes = 0;
        $table_stats = [];
        $total_rows = 0;
        $charset = $this->get_db_backup_charset();

        $fail = function($code, $message) use (&$handle, &$snapshot_started, &$tables_locked, $sql_path, $zip_path, $manifest_path) {
            return $this->abort_db_backup($handle, $snapshot_started, $tables_locked, $sql_path, $zip_path, $manifest_path, $code, $message);
        };

        $tables = $this->get_db_backup_base_tables($wpdb);
        if (is_wp_error($tables)) {
            return $fail($tables->get_error_code(), $tables->get_error_message());
        }
        if (empty($tables)) {
            return $fail('db_backup_no_tables', __('Backup database non creato: nessuna tabella trovata.', 'marrison-custom-updater'));
        }

        $table_engines = $this->get_db_backup_table_engines($wpdb, $tables);
        $non_transactional_tables = $this->get_db_backup_non_transactional_tables($tables, $table_engines);

        if (empty($non_transactional_tables)) {
            if ($wpdb->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ') !== false && $wpdb->query('START TRANSACTION WITH CONSISTENT SNAPSHOT') !== false) {
                $snapshot_started = true;
            } else {
                $snapshot_method = 'read_locks_fallback';
                $lock_result = $this->lock_db_backup_tables($wpdb, $tables);
                if (is_wp_error($lock_result)) {
                    return $fail($lock_result->get_error_code(), $lock_result->get_error_message());
                }
                $tables_locked = true;
            }
        } else {
            $snapshot_method = 'read_locks';
            $lock_result = $this->lock_db_backup_tables($wpdb, $tables);
            if (is_wp_error($lock_result)) {
                return $fail($lock_result->get_error_code(), $lock_result->get_error_message());
            }
            $tables_locked = true;
        }

        $handle = @fopen($sql_path, 'wb');
        if (!$handle) {
            return $fail('db_backup_open_failed', __('Impossibile creare il file SQL del backup database.', 'marrison-custom-updater'));
        }

        $header_sql =
            "-- phpMyAdmin SQL Dump\n" .
            "-- version 5.2.1\n" .
            "-- https://www.phpmyadmin.net/\n" .
            "--\n" .
            "-- Host: " . $wpdb->dbhost . "\n" .
            "-- Generation Time: " . date('r') . "\n" .
            "-- Server version: " . $wpdb->db_version() . "\n" .
            "-- PHP Version: " . phpversion() . "\n" .
            "-- Snapshot method: " . $snapshot_method . "\n" .
            "--\n" .
            "-- Database: " . $this->quote_db_identifier(DB_NAME) . "\n" .
            "--\n\n" .
            "/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;\n" .
            "/*!40103 SET TIME_ZONE='+00:00' */;\n" .
            "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n" .
            "/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;\n" .
            "/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;\n" .
            "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n" .
            "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n" .
            "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n" .
            "/*!40101 SET NAMES " . $charset . " */;\n\n" .
            "/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;\n\n" .
            "START TRANSACTION;\n\n";

        if (!$this->write_backup_stream_data($handle, $header_sql, $sql_bytes)) {
            return $fail('db_backup_write_failed', __('Errore durante la scrittura dell\'header SQL del backup database.', 'marrison-custom-updater'));
        }

        foreach ($tables as $table) {
            if (!$tables_locked) {
                $this->mcu_touch_update_lock(['operation' => 'db_backup', 'stage' => 'table_started', 'table' => $table]);
            }
            $table_sql = $this->quote_db_identifier($table);
            $create = $wpdb->get_row("SHOW CREATE TABLE {$table_sql}", ARRAY_N);
            if (!$create || empty($create[1])) {
                return $fail('db_backup_schema_failed', sprintf(__('Impossibile leggere lo schema della tabella %s.', 'marrison-custom-updater'), $table));
            }

            $create_sql = (string) $create[1];
            $columns = $wpdb->get_results("SHOW COLUMNS FROM {$table_sql}", ARRAY_A);
            if (empty($columns) || !is_array($columns)) {
                return $fail('db_backup_columns_failed', sprintf(__('Impossibile leggere le colonne della tabella %s.', 'marrison-custom-updater'), $table));
            }

            $expected_rows_raw = $wpdb->get_var("SELECT COUNT(*) FROM {$table_sql}");
            if ($expected_rows_raw === null) {
                return $fail('db_backup_count_failed', sprintf(__('Impossibile contare le righe della tabella %s.', 'marrison-custom-updater'), $table));
            }

            $expected_rows = (int) $expected_rows_raw;
            $dumped_rows = 0;
            $order_clause = $this->get_db_backup_order_clause($wpdb, $table);

            $auto_increment_columns = array_filter($columns, function($col) {
                return isset($col['Extra']) && stripos($col['Extra'], 'auto_increment') !== false;
            });
            if (!empty($auto_increment_columns) && stripos($create_sql, 'AUTO_INCREMENT') === false) {
                return $fail(
                    'missing_auto_increment',
                    sprintf(__('Schema non valido per la tabella %s: AUTO_INCREMENT mancante.', 'marrison-custom-updater'), $table)
                );
            }

            $schema_sql =
                "--\n-- Table structure for table " . $table_sql . "\n--\n\n" .
                "DROP TABLE IF EXISTS " . $table_sql . ";\n" .
                "/*!40101 SET @saved_cs_client     = @@character_set_client */;\n" .
                "/*!40101 SET character_set_client = " . $charset . " */;\n" .
                $create_sql . ";\n" .
                "/*!40101 SET character_set_client = @saved_cs_client */;\n\n";

            if (!$this->write_backup_stream_data($handle, $schema_sql, $sql_bytes)) {
                return $fail('db_backup_write_failed', sprintf(__('Errore durante la scrittura dello schema della tabella %s.', 'marrison-custom-updater'), $table));
            }

            $insert_columns = array_values(array_filter($columns, [$this, 'is_db_backup_insertable_column']));
            if ($expected_rows > 0 && empty($insert_columns)) {
                return $fail('db_backup_no_insertable_columns', sprintf(__('La tabella %s contiene righe ma nessuna colonna inseribile.', 'marrison-custom-updater'), $table));
            }

            $column_names = array_map(function($col) {
                return $this->quote_db_identifier($col['Field']);
            }, $insert_columns);

            $numeric_cols = [];
            $binary_cols = [];
            foreach ($insert_columns as $col) {
                $numeric_cols[$col['Field']] = $this->is_db_backup_numeric_column($col['Type']);
                $binary_cols[$col['Field']] = $this->is_db_backup_binary_column($col['Type']);
            }

            if (!empty($insert_columns)) {
                $offset = 0;
                $batch = 500;
                $select_columns_sql = implode(', ', $column_names);
                $insert_prefix = "INSERT INTO " . $table_sql . " (" . implode(', ', $column_names) . ") VALUES\n";
                $insert_chunk_limit = 1024 * 1024;

                while (true) {
                    if (!$tables_locked) {
                        $this->mcu_touch_update_lock(['operation' => 'db_backup', 'stage' => 'rows_batch', 'table' => $table]);
                    }
                    $rows = $wpdb->get_results(
                        $wpdb->prepare("SELECT {$select_columns_sql} FROM {$table_sql}{$order_clause} LIMIT %d OFFSET %d", $batch, $offset),
                        ARRAY_A
                    );
                    if ($rows === null && !empty($wpdb->last_error)) {
                        return $fail('db_backup_select_failed', sprintf(__('Errore durante la lettura della tabella %s.', 'marrison-custom-updater'), $table));
                    }
                    if (empty($rows)) {
                        break;
                    }

                    $value_rows = [];
                    $current_insert_size = strlen($insert_prefix);
                    foreach ($rows as $row) {
                        $vals = [];
                        foreach ($insert_columns as $col) {
                            $field = $col['Field'];
                            $val = array_key_exists($field, $row) ? $row[$field] : null;
                            $vals[] = $this->get_db_backup_sql_value($wpdb, $val, $col, $numeric_cols, $binary_cols);
                        }

                        $tuple = '(' . implode(', ', $vals) . ')';
                        $tuple_size = strlen($tuple) + 2;
                        if (!empty($value_rows) && ($current_insert_size + $tuple_size) > $insert_chunk_limit) {
                            if (!$this->write_backup_stream_data($handle, $insert_prefix . implode(",\n", $value_rows) . ";\n", $sql_bytes)) {
                                return $fail('db_backup_write_failed', sprintf(__('Errore durante la scrittura dei dati della tabella %s.', 'marrison-custom-updater'), $table));
                            }
                            $value_rows = [];
                            $current_insert_size = strlen($insert_prefix);
                        }

                        $value_rows[] = $tuple;
                        $current_insert_size += $tuple_size;
                    }

                    if (!empty($value_rows)) {
                        if (!$this->write_backup_stream_data($handle, $insert_prefix . implode(",\n", $value_rows) . ";\n", $sql_bytes)) {
                            return $fail('db_backup_write_failed', sprintf(__('Errore durante la scrittura dei dati della tabella %s.', 'marrison-custom-updater'), $table));
                        }
                    }

                    $offset += $batch;
                    $dumped_rows += count($rows);
                    if (count($rows) < $batch) {
                        break;
                    }
                }
            }

            if ($dumped_rows !== $expected_rows) {
                return $fail(
                    'db_backup_row_count_mismatch',
                    sprintf(__('Backup database non valido per la tabella %1$s: attese %2$d righe, scritte %3$d.', 'marrison-custom-updater'), $table, $expected_rows, $dumped_rows)
                );
            }

            $total_rows += $dumped_rows;
            $table_stats[] = [
                'name' => $table,
                'rows' => $dumped_rows,
                'columns' => count($columns),
                'insert_columns' => count($insert_columns),
            ];

            if (!$this->write_backup_stream_data($handle, "-- --------------------------------------------------------\n\n", $sql_bytes)) {
                return $fail('db_backup_write_failed', sprintf(__('Errore durante la finalizzazione della tabella %s.', 'marrison-custom-updater'), $table));
            }
        }

        if ($tables_locked) {
            $unlock_result = $this->unlock_db_backup_tables($wpdb);
            if (is_wp_error($unlock_result)) {
                return $fail($unlock_result->get_error_code(), $unlock_result->get_error_message());
            }
            $tables_locked = false;
        }

        $footer_sql =
            "COMMIT;\n\n" .
            "/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;\n" .
            "/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n" .
            "/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;\n" .
            "/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;\n" .
            "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n" .
            "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n" .
            "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n" .
            "/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;\n" .
            "-- MCU_BACKUP_COMPLETE\n";

        if (!$this->write_backup_stream_data($handle, $footer_sql, $sql_bytes)) {
            return $fail('db_backup_write_failed', __('Errore durante la scrittura del footer SQL del backup database.', 'marrison-custom-updater'));
        }

        if (!@fflush($handle)) {
            return $fail('db_backup_flush_failed', __('Backup database non valido: impossibile sincronizzare il file SQL su disco.', 'marrison-custom-updater'));
        }

        if (!@fclose($handle)) {
            $handle = null;
            return $fail('db_backup_close_failed', __('Backup database non valido: impossibile chiudere il file SQL.', 'marrison-custom-updater'));
        }
        $handle = null;

        if ($snapshot_started) {
            $wpdb->query('COMMIT');
            $snapshot_started = false;
        }

        clearstatcache(true, $sql_path);
        $sql_size = @filesize($sql_path);
        if ($sql_size === false || (int) $sql_size !== (int) $sql_bytes) {
            return $fail('db_backup_size_mismatch', __('Backup database non valido: la dimensione del file SQL non coincide con i byte scritti.', 'marrison-custom-updater'));
        }

        $sql_hash = @hash_file('sha256', $sql_path);
        if (!$sql_hash) {
            return $fail('db_backup_hash_failed', __('Backup database non valido: impossibile calcolare l\'hash del file SQL.', 'marrison-custom-updater'));
        }

        $manifest = [
            'type' => 'marrison_database_backup',
            'format_version' => 2,
            'plugin_version' => defined('MCU_PLUGIN_VERSION') ? MCU_PLUGIN_VERSION : 'unknown',
            'generated_at' => gmdate('c'),
            'database' => [
                'name' => defined('DB_NAME') ? DB_NAME : '',
                'host' => $wpdb->dbhost,
                'server_version' => $wpdb->db_version(),
                'charset' => $charset,
            ],
            'sql' => [
                'file' => $sql_filename,
                'bytes' => (int) $sql_size,
                'sha256' => $sql_hash,
                'complete_marker' => 'MCU_BACKUP_COMPLETE',
            ],
            'totals' => [
                'tables' => count($table_stats),
                'rows' => $total_rows,
            ],
            'tables' => $table_stats,
            'integrity' => [
                'consistent_snapshot' => true,
                'snapshot_method' => $snapshot_method,
                'table_locks' => in_array($snapshot_method, ['read_locks', 'read_locks_fallback'], true),
                'non_transactional_tables' => count($non_transactional_tables),
                'write_verified' => true,
                'row_counts_verified' => true,
                'sql_hash_verified' => true,
                'zip_verified_before_success' => true,
            ],
        ];

        $manifest_json = wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($manifest_json) || $manifest_json === '') {
            return $fail('db_backup_manifest_encode_failed', __('Backup database non valido: impossibile generare il manifest di verifica.', 'marrison-custom-updater'));
        }

        if (!$this->write_backup_file_data($manifest_path, $manifest_json . "\n")) {
            return $fail('db_backup_manifest_write_failed', __('Backup database non valido: impossibile scrivere il manifest di verifica.', 'marrison-custom-updater'));
        }

        $zip_result = $this->create_db_backup_zip_archive($zip_path, $backup_dir, [$sql_path, $manifest_path]);
        if (is_wp_error($zip_result)) {
            return $fail($zip_result->get_error_code(), $zip_result->get_error_message());
        }

        $zip_validation = $this->validate_db_backup_zip($zip_path, $sql_filename, $manifest_filename, (int) $sql_size, $sql_hash);
        if (is_wp_error($zip_validation)) {
            return $fail($zip_validation->get_error_code(), $zip_validation->get_error_message());
        }

        clearstatcache(true, $zip_path);
        $archive_size = @filesize($zip_path);
        $archive_hash = @hash_file('sha256', $zip_path);
        if ($archive_size === false || !$archive_hash) {
            return $fail('db_backup_archive_hash_failed', __('Backup database non valido: impossibile calcolare l\'hash dello ZIP.', 'marrison-custom-updater'));
        }

        $this->record_db_backup_integrity($zip_filename, [
            'created_at' => $manifest['generated_at'],
            'verified_at' => gmdate('c'),
            'plugin_version' => $manifest['plugin_version'],
            'archive_bytes' => (int) $archive_size,
            'archive_sha256' => $archive_hash,
            'sql_bytes' => (int) $sql_size,
            'sql_sha256' => $sql_hash,
            'total_tables' => count($table_stats),
            'total_rows' => $total_rows,
            'snapshot_method' => $snapshot_method,
            'non_transactional_tables' => count($non_transactional_tables),
        ]);

        @unlink($sql_path);
        @unlink($manifest_path);

        $db_backups = glob($backup_dir . '/db-backup-*.zip');
        if (is_array($db_backups) && count($db_backups) > 3) {
            usort($db_backups, function($a, $b) { return filemtime($a) - filemtime($b); });
            foreach (array_slice($db_backups, 0, count($db_backups) - 3) as $f) {
                @unlink($f);
            }
        }
        $this->prune_db_backup_integrity_records($backup_dir);

        return $zip_filename;
    }

    private function abort_db_backup(&$handle, &$snapshot_started, &$tables_locked, $sql_path, $zip_path, $manifest_path, $code, $message) {
        global $wpdb;

        if (is_resource($handle)) {
            @fclose($handle);
            $handle = null;
        }

        if ($snapshot_started) {
            $wpdb->query('ROLLBACK');
            $snapshot_started = false;
        }

        if ($tables_locked) {
            $wpdb->query('UNLOCK TABLES');
            $tables_locked = false;
        }

        foreach ([$sql_path, $zip_path, $manifest_path] as $path) {
            if ($path && file_exists($path)) {
                @unlink($path);
            }
        }

        return new WP_Error($code, $message);
    }

    private function get_db_backup_base_tables($wpdb) {
        $rows = $wpdb->get_results('SHOW FULL TABLES', ARRAY_N);
        if ($rows === null && !empty($wpdb->last_error)) {
            return new WP_Error(
                'db_backup_tables_failed',
                sprintf(__('Impossibile leggere le tabelle del database: %s', 'marrison-custom-updater'), $wpdb->last_error)
            );
        }

        $tables = [];
        $unsupported = [];
        foreach ((array) $rows as $row) {
            $name = isset($row[0]) ? (string) $row[0] : '';
            $type = strtoupper(isset($row[1]) ? (string) $row[1] : 'BASE TABLE');
            if ($name === '') {
                continue;
            }
            if ($type === 'BASE TABLE') {
                $tables[] = $name;
            } else {
                $unsupported[] = $type . ' ' . $name;
            }
        }

        $triggers = $wpdb->get_results('SHOW TRIGGERS', ARRAY_A);
        if (is_array($triggers) && !empty($triggers)) {
            foreach ($triggers as $trigger) {
                $unsupported[] = 'TRIGGER ' . (isset($trigger['Trigger']) ? $trigger['Trigger'] : '');
            }
        }

        if (!empty($unsupported)) {
            $sample = implode(', ', array_slice($unsupported, 0, 10));
            if (count($unsupported) > 10) {
                $sample .= ', ...';
            }

            return new WP_Error(
                'db_backup_unsupported_objects',
                sprintf(__('Backup database non creato: sono presenti oggetti non supportati dal dump verificato (%s). Usa phpMyAdmin o mysqldump per includerli.', 'marrison-custom-updater'), $sample)
            );
        }

        sort($tables, SORT_STRING);
        return $tables;
    }

    private function get_db_backup_table_engines($wpdb, $tables) {
        $table_engines = [];
        $status_rows = $wpdb->get_results('SHOW TABLE STATUS', ARRAY_A);
        if (is_array($status_rows)) {
            foreach ($status_rows as $status_row) {
                if (!empty($status_row['Name'])) {
                    $table_engines[$status_row['Name']] = isset($status_row['Engine']) ? (string) $status_row['Engine'] : '';
                }
            }
        }

        foreach ($tables as $table) {
            if (!isset($table_engines[$table])) {
                $table_engines[$table] = '';
            }
        }

        return $table_engines;
    }

    private function get_db_backup_non_transactional_tables($tables, $table_engines) {
        $non_transactional = [];
        foreach ($tables as $table) {
            $engine = isset($table_engines[$table]) ? strtolower($table_engines[$table]) : '';
            if ($engine !== 'innodb') {
                $non_transactional[] = ($engine !== '' ? strtoupper($engine) : 'UNKNOWN') . ' ' . $table;
            }
        }

        return $non_transactional;
    }

    private function lock_db_backup_tables($wpdb, $tables) {
        if (empty($tables)) {
            return true;
        }

        $locks = [];
        foreach ($tables as $table) {
            $locks[] = $this->quote_db_identifier($table) . ' READ';
        }

        if ($wpdb->query('LOCK TABLES ' . implode(', ', $locks)) === false) {
            return new WP_Error(
                'db_backup_table_lock_failed',
                sprintf(__('Backup database non creato: impossibile bloccare le tabelle per creare un dump coerente (%s).', 'marrison-custom-updater'), $wpdb->last_error ?: __('errore sconosciuto', 'marrison-custom-updater'))
            );
        }

        return true;
    }

    private function unlock_db_backup_tables($wpdb) {
        if ($wpdb->query('UNLOCK TABLES') === false) {
            return new WP_Error(
                'db_backup_table_unlock_failed',
                sprintf(__('Backup database creato ma impossibile rilasciare il lock delle tabelle (%s).', 'marrison-custom-updater'), $wpdb->last_error ?: __('errore sconosciuto', 'marrison-custom-updater'))
            );
        }

        return true;
    }

    private function get_db_backup_charset() {
        $charset = defined('DB_CHARSET') && DB_CHARSET ? DB_CHARSET : 'utf8mb4';
        return preg_match('/^[A-Za-z0-9_]+$/', $charset) ? $charset : 'utf8mb4';
    }

    private function quote_db_identifier($identifier) {
        return '`' . str_replace('`', '``', (string) $identifier) . '`';
    }

    private function write_backup_stream_data($handle, $data, &$bytes_written = null) {
        $length = strlen($data);
        $offset = 0;

        while ($offset < $length) {
            $written = @fwrite($handle, substr($data, $offset));
            if ($written === false || $written <= 0) {
                return false;
            }
            $offset += $written;
        }

        if ($bytes_written !== null) {
            $bytes_written += $length;
        }

        return true;
    }

    private function write_backup_file_data($path, $data) {
        $written = @file_put_contents($path, $data, LOCK_EX);
        return $written !== false && (int) $written === strlen($data);
    }

    private function is_db_backup_insertable_column($col) {
        $extra = isset($col['Extra']) ? strtolower((string) $col['Extra']) : '';
        return strpos($extra, 'generated') === false;
    }

    private function is_db_backup_numeric_column($type) {
        return (bool) preg_match('/^(tinyint|smallint|mediumint|int|bigint|float|double|decimal|numeric|real|year)/i', (string) $type);
    }

    private function is_db_backup_binary_column($type) {
        $type = strtolower((string) $type);
        return strpos($type, 'blob') !== false || strpos($type, 'binary') !== false || preg_match('/^bit\b/i', $type);
    }

    private function get_db_backup_sql_value($wpdb, $val, $col, $numeric_cols, $binary_cols) {
        $field = $col['Field'];

        if ($val === null) {
            return 'NULL';
        }

        if (!empty($binary_cols[$field])) {
            return "X'" . bin2hex((string) $val) . "'";
        }

        if (!empty($numeric_cols[$field]) && is_numeric($val)) {
            return (string) $val;
        }

        return "'" . $this->escape_for_sql($wpdb, (string) $val) . "'";
    }

    private function create_db_backup_zip_archive($zip_path, $backup_dir, $files) {
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            $opened = $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            if ($opened !== true) {
                return new WP_Error('db_backup_zip_open_failed', __('Impossibile creare il file ZIP del backup database.', 'marrison-custom-updater'));
            }

            foreach ($files as $file) {
                if (!$zip->addFile($file, basename($file))) {
                    $zip->close();
                    return new WP_Error('db_backup_zip_add_failed', sprintf(__('Impossibile aggiungere %s allo ZIP del backup database.', 'marrison-custom-updater'), basename($file)));
                }
            }

            if (!$zip->close()) {
                return new WP_Error('db_backup_zip_close_failed', __('Impossibile finalizzare lo ZIP del backup database.', 'marrison-custom-updater'));
            }

            return true;
        }

        if (!class_exists('PclZip')) {
            require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
        }

        $archive = new PclZip($zip_path);
        $result = $archive->create($files, PCLZIP_OPT_REMOVE_PATH, $backup_dir);
        if (!is_array($result) || empty($result)) {
            return new WP_Error('db_backup_zip_create_failed', __('Impossibile creare lo ZIP del backup database.', 'marrison-custom-updater'));
        }

        return true;
    }

    private function validate_db_backup_zip($zip_path, $sql_filename, $manifest_filename, $expected_sql_size, $expected_sql_hash) {
        clearstatcache(true, $zip_path);
        if (!file_exists($zip_path) || filesize($zip_path) <= 0) {
            return new WP_Error('db_backup_zip_missing', __('Backup database non valido: lo ZIP non e stato creato correttamente.', 'marrison-custom-updater'));
        }

        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            $opened = $zip->open($zip_path, ZipArchive::CHECKCONS);
            if ($opened !== true) {
                return new WP_Error('db_backup_zip_invalid', __('Backup database non valido: lo ZIP non supera il controllo di consistenza.', 'marrison-custom-updater'));
            }

            $sql_stat = $zip->statName($sql_filename);
            $manifest_stat = $zip->statName($manifest_filename);
            if (!$sql_stat || !$manifest_stat) {
                $zip->close();
                return new WP_Error('db_backup_zip_missing_entries', __('Backup database non valido: SQL o manifest mancanti nello ZIP.', 'marrison-custom-updater'));
            }

            if ((int) $sql_stat['size'] !== (int) $expected_sql_size) {
                $zip->close();
                return new WP_Error('db_backup_zip_size_mismatch', __('Backup database non valido: la dimensione SQL nello ZIP non coincide.', 'marrison-custom-updater'));
            }

            $stream = $zip->getStream($sql_filename);
            if (!$stream) {
                $zip->close();
                return new WP_Error('db_backup_zip_stream_failed', __('Backup database non valido: impossibile leggere SQL dallo ZIP.', 'marrison-custom-updater'));
            }

            $bytes = 0;
            $hash = $this->hash_backup_stream($stream, $bytes);
            fclose($stream);
            $zip->close();

            if (!$hash || (int) $bytes !== (int) $expected_sql_size || !hash_equals($expected_sql_hash, $hash)) {
                return new WP_Error('db_backup_zip_hash_mismatch', __('Backup database non valido: hash SQL nello ZIP non coincidente.', 'marrison-custom-updater'));
            }

            return true;
        }

        if (!class_exists('PclZip')) {
            require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
        }

        $tmp_dir = trailingslashit(dirname($zip_path)) . 'mcu-zip-validate-' . wp_generate_password(8, false, false);
        if (!wp_mkdir_p($tmp_dir)) {
            return new WP_Error('db_backup_validation_tmp_failed', __('Backup database non valido: impossibile preparare la verifica dello ZIP.', 'marrison-custom-updater'));
        }

        $archive = new PclZip($zip_path);
        $result = $archive->extract(PCLZIP_OPT_PATH, $tmp_dir, PCLZIP_OPT_REMOVE_ALL_PATH);
        if (!is_array($result) || empty($result)) {
            $this->delete_backup_temp_dir($tmp_dir);
            return new WP_Error('db_backup_zip_extract_failed', __('Backup database non valido: impossibile estrarre lo ZIP per la verifica.', 'marrison-custom-updater'));
        }

        $sql_path = $tmp_dir . '/' . $sql_filename;
        $manifest_path = $tmp_dir . '/' . $manifest_filename;
        clearstatcache(true, $sql_path);
        if (!file_exists($sql_path) || !file_exists($manifest_path) || (int) filesize($sql_path) !== (int) $expected_sql_size) {
            $this->delete_backup_temp_dir($tmp_dir);
            return new WP_Error('db_backup_zip_extract_mismatch', __('Backup database non valido: contenuto ZIP non coincidente.', 'marrison-custom-updater'));
        }

        $hash = @hash_file('sha256', $sql_path);
        $this->delete_backup_temp_dir($tmp_dir);
        if (!$hash || !hash_equals($expected_sql_hash, $hash)) {
            return new WP_Error('db_backup_zip_hash_mismatch', __('Backup database non valido: hash SQL nello ZIP non coincidente.', 'marrison-custom-updater'));
        }

        return true;
    }

    private function hash_backup_stream($stream, &$bytes) {
        $context = hash_init('sha256');
        $bytes = 0;

        while (!feof($stream)) {
            $chunk = fread($stream, 1024 * 1024);
            if ($chunk === false) {
                return false;
            }
            if ($chunk === '') {
                continue;
            }
            $bytes += strlen($chunk);
            hash_update($context, $chunk);
        }

        return hash_final($context);
    }

    private function delete_backup_temp_dir($dir) {
        if (!is_dir($dir)) {
            return;
        }

        $items = @scandir($dir);
        if (is_array($items)) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }

                $path = $dir . '/' . $item;
                if (is_dir($path)) {
                    $this->delete_backup_temp_dir($path);
                } else {
                    @unlink($path);
                }
            }
        }

        @rmdir($dir);
    }

    private function record_db_backup_integrity($filename, $record) {
        $records = get_option('marrison_db_backup_integrity', []);
        if (!is_array($records)) {
            $records = [];
        }

        $records[$filename] = $record;
        update_option('marrison_db_backup_integrity', $records, false);
    }

    private function prune_db_backup_integrity_records($backup_dir) {
        $records = get_option('marrison_db_backup_integrity', []);
        if (!is_array($records) || empty($records)) {
            return;
        }

        foreach (array_keys($records) as $filename) {
            if (!file_exists(trailingslashit($backup_dir) . $filename)) {
                unset($records[$filename]);
            }
        }

        update_option('marrison_db_backup_integrity', $records, false);
    }

    private function remove_db_backup_integrity_record($filename) {
        $records = get_option('marrison_db_backup_integrity', []);
        if (!is_array($records) || !isset($records[$filename])) {
            return;
        }

        unset($records[$filename]);
        update_option('marrison_db_backup_integrity', $records, false);
    }

    private function get_db_backup_integrity_status($filename, $file_path) {
        $records = get_option('marrison_db_backup_integrity', []);
        if (!is_array($records) || empty($records[$filename])) {
            return [
                'class' => 'mcu-badge-warning',
                'label' => __('Legacy', 'marrison-custom-updater'),
                'detail' => __('Creato prima della verifica forte.', 'marrison-custom-updater'),
            ];
        }

        $record = $records[$filename];
        clearstatcache(true, $file_path);
        $current_size = file_exists($file_path) ? @filesize($file_path) : false;
        if ($current_size === false || (!empty($record['archive_bytes']) && (int) $current_size !== (int) $record['archive_bytes'])) {
            return [
                'class' => 'mcu-badge-danger',
                'label' => __('Da ricontrollare', 'marrison-custom-updater'),
                'detail' => __('Dimensione archivio diversa dalla verifica iniziale.', 'marrison-custom-updater'),
            ];
        }

        $tables = isset($record['total_tables']) ? (int) $record['total_tables'] : 0;
        $rows = isset($record['total_rows']) ? (int) $record['total_rows'] : 0;
        $hash = !empty($record['sql_sha256']) ? substr($record['sql_sha256'], 0, 12) : '';

        return [
            'class' => 'mcu-badge-success',
            'label' => __('Verificato', 'marrison-custom-updater'),
            'detail' => sprintf(
                __('%1$s tabelle, %2$s righe, SHA256 %3$s', 'marrison-custom-updater'),
                function_exists('number_format_i18n') ? number_format_i18n($tables) : number_format($tables),
                function_exists('number_format_i18n') ? number_format_i18n($rows) : number_format($rows),
                $hash
            ),
        ];
    }

    private function get_db_backup_order_clause($wpdb, $table) {
        $table_sql = $this->quote_db_identifier($table);
        $keys = $wpdb->get_results("SHOW KEYS FROM {$table_sql} WHERE Key_name = 'PRIMARY'", ARRAY_A);
        if (empty($keys)) {
            return '';
        }

        usort($keys, function($a, $b) {
            return (int) $a['Seq_in_index'] - (int) $b['Seq_in_index'];
        });

        $columns = [];
        foreach ($keys as $key) {
            if (!empty($key['Column_name'])) {
                $columns[] = $this->quote_db_identifier($key['Column_name']);
            }
        }

        return empty($columns) ? '' : ' ORDER BY ' . implode(', ', $columns);
    }

    public function create_files_backup() {
        $state = $this->init_files_backup_job();
        if (is_wp_error($state)) {
            return $state;
        }

        do {
            $this->mcu_touch_update_lock(['operation' => 'files_backup', 'stage' => 'job_step_started']);
            $running_state = $state;
            $state = $this->process_files_backup_job($state);
            if (is_wp_error($state)) {
                $this->cleanup_files_backup_job_artifacts($running_state);
                return $state;
            }
            $this->mcu_touch_update_lock(['operation' => 'files_backup', 'stage' => 'job_step_finished']);
        } while ($state['status'] !== 'complete');

        update_option('marrison_last_files_backup_skipped', [
            'count' => (int) ($state['skipped_count'] ?? 0),
            'bytes' => (int) ($state['skipped_bytes'] ?? 0),
            'files' => $state['skipped_files'] ?? [],
            'time' => time(),
        ], false);

        return count($state['parts']) === 1 ? $state['parts'][0] : $state['parts'];
    }

    private function create_files_backup_tar_gz($backup_dir, $date, $time, $root_path) {
        $archive_filename = 'files-backup-' . $date . '-' . $time . '.tar.gz';
        $archive_path     = $backup_dir . '/' . $archive_filename;

        if (!function_exists('gzopen')) {
            return new WP_Error(
                'zlib_missing',
                __('Backup file non disponibile: abilita l\'estensione PHP zlib sul server per creare archivi tar.gz.', 'marrison-custom-updater')
            );
        }

        if (!is_dir($root_path) || !is_writable($backup_dir)) {
            return new WP_Error('backup_dir_not_writable', __('Directory backup non scrivibile.', 'marrison-custom-updater'));
        }

        if (file_exists($archive_path)) {
            @unlink($archive_path);
        }

        $handle = @gzopen($archive_path, 'wb1');
        if (!$handle) {
            return new WP_Error('targz_open_failed', __('Impossibile creare il file tar.gz del backup.', 'marrison-custom-updater'));
        }

        $stats = ['files' => 0, 'dirs' => 0, 'bytes' => 0];
        $result = $this->add_files_to_backup_tar_gz($handle, $root_path, $root_path, $archive_path, $stats);
        if (!is_wp_error($result) && !$this->write_tar_data($handle, str_repeat("\0", 1024))) {
            $result = new WP_Error('targz_write_failed', __('Errore durante la chiusura dell\'archivio tar.gz.', 'marrison-custom-updater'));
        }

        $closed = @gzclose($handle);

        if (is_wp_error($result) || !$closed || !file_exists($archive_path) || $stats['files'] === 0) {
            if (file_exists($archive_path)) {
                @unlink($archive_path);
            }

            return is_wp_error($result)
                ? $result
                : new WP_Error('targz_create_failed', __('Il backup file non è stato creato correttamente.', 'marrison-custom-updater'));
        }

        $file_backups = array_merge(
            glob($backup_dir . '/files-backup-*.tar.gz') ?: [],
            glob($backup_dir . '/files-backup-*.zip') ?: []
        );
        $max_file_backup_sets = $this->get_files_backup_max_sets();
        if (count($file_backups) > $max_file_backup_sets) {
            usort($file_backups, function($a, $b) { return filemtime($a) - filemtime($b); });
            foreach (array_slice($file_backups, 0, count($file_backups) - $max_file_backup_sets) as $f) @unlink($f);
        }

        return $archive_filename;
    }

    private function get_files_backup_part_limit() {
        if (defined('MCU_FILES_BACKUP_PART_LIMIT')) {
            return max(1024 * 1024, (int) MCU_FILES_BACKUP_PART_LIMIT);
        }

        return 850 * 1024 * 1024;
    }

    private function get_files_backup_max_sets() {
        $max_sets = defined('MCU_FILES_BACKUP_MAX_SETS') ? (int) MCU_FILES_BACKUP_MAX_SETS : 1;
        return max(1, (int) apply_filters('mcu_files_backup_max_sets', $max_sets));
    }

    public function maybe_cleanup_files_backup_retention() {
        if (!is_admin() || (function_exists('wp_doing_ajax') && wp_doing_ajax())) {
            return;
        }

        $version = defined('MCU_PLUGIN_VERSION') ? MCU_PLUGIN_VERSION : 'unknown';
        $max_sets = $this->get_files_backup_max_sets();
        $option_value = $version . '|' . $max_sets;
        if (get_option('mcu_files_backup_retention_applied') === $option_value) {
            return;
        }

        $backup_dir = WP_CONTENT_DIR . '/marrison-backups';
        if (is_dir($backup_dir)) {
            $this->cleanup_files_backup_sets($backup_dir, $max_sets);
        }

        update_option('mcu_files_backup_retention_applied', $option_value, false);
    }

    private function get_files_backup_job_key($job_id) {
        return 'mcu_files_backup_job_' . sanitize_key($job_id);
    }

    private function save_files_backup_job($state) {
        set_transient($this->get_files_backup_job_key($state['job_id']), $state, DAY_IN_SECONDS);
    }

    private function load_files_backup_job($job_id) {
        $state = get_transient($this->get_files_backup_job_key($job_id));
        return is_array($state) ? $state : false;
    }

    private function delete_files_backup_job($state) {
        if (!empty($state['manifest_path']) && file_exists($state['manifest_path'])) {
            @unlink($state['manifest_path']);
        }
        if (!empty($state['job_id'])) {
            delete_transient($this->get_files_backup_job_key($state['job_id']));
        }
    }

    private function cleanup_files_backup_job_artifacts($state) {
        if (!empty($state['backup_dir']) && !empty($state['prefix'])) {
            foreach (glob(trailingslashit($state['backup_dir']) . $state['prefix'] . '-part*.tar.gz*') ?: [] as $file) {
                @unlink($file);
            }
        }
        $this->delete_files_backup_job($state);
    }

    private function init_files_backup_job() {
        $backup_dir = $this->get_backup_dir();
        $root_path  = wp_normalize_path(untrailingslashit(ABSPATH));
        $root_entries = $this->get_files_backup_allowed_root_entries($root_path);
        $date       = date('Ymd');
        $time       = date('His');
        $prefix     = 'files-backup-' . $date . '-' . $time;
        $job_id     = wp_generate_password(12, false, false);
        $manifest   = $backup_dir . '/' . $prefix . '-' . $job_id . '.manifest.tmp';

        if (!function_exists('gzopen')) {
            return new WP_Error(
                'zlib_missing',
                __('Backup file non disponibile: abilita l\'estensione PHP zlib sul server per creare archivi tar.gz.', 'marrison-custom-updater')
            );
        }

        if (!is_dir($root_path) || !is_writable($backup_dir)) {
            return new WP_Error('backup_dir_not_writable', __('Directory backup non scrivibile.', 'marrison-custom-updater'));
        }

        $scan = $this->scan_files_backup_manifest($root_path, $backup_dir, $manifest, $root_entries);
        if (is_wp_error($scan)) {
            if (file_exists($manifest)) {
                @unlink($manifest);
            }
            return $scan;
        }

        $state = [
            'job_id' => $job_id,
            'prefix' => $prefix,
            'backup_dir' => $backup_dir,
            'root_path' => $root_path,
            'scope' => 'wordpress',
            'root_entries' => $root_entries,
            'manifest_path' => $manifest,
            'manifest_offset' => 0,
            'total_entries' => $scan['entries'],
            'total_files' => $scan['files'],
            'total_bytes' => $scan['bytes'],
            'skipped_count' => $scan['skipped_count'],
            'skipped_bytes' => $scan['skipped_bytes'],
            'skipped_files' => $scan['skipped_files'],
            'processed_entries' => 0,
            'processed_files' => 0,
            'processed_bytes' => 0,
            'current_part' => 1,
            'current_part_entries' => 0,
            'parts' => [],
            'status' => 'running',
            'created_at' => time(),
            'part_limit' => $this->get_files_backup_part_limit(),
        ];
        $this->save_files_backup_job($state);

        return $state;
    }

    private function get_files_backup_allowed_root_entries($root_path) {
        $root_path = trailingslashit(wp_normalize_path(untrailingslashit($root_path)));
        $directories = ['wp-admin', defined('WPINC') ? trim(WPINC, '/\\') : 'wp-includes'];
        $content_dir = wp_normalize_path(untrailingslashit(WP_CONTENT_DIR));

        if ($content_dir && strpos($content_dir, $root_path) === 0) {
            $content_entry = trim(substr($content_dir, strlen($root_path)), '/\\');
            if ($content_entry !== '' && strpos($content_entry, '/') === false) {
                $directories[] = $content_entry;
            }
        } else {
            $directories[] = 'wp-content';
        }

        $files = [
            '.htaccess',
            '.user.ini',
            'index.php',
            'license.txt',
            'readme.html',
            'web.config',
            'wp-activate.php',
            'wp-blog-header.php',
            'wp-comments-post.php',
            'wp-config.php',
            'wp-config-sample.php',
            'wp-cron.php',
            'wp-links-opml.php',
            'wp-load.php',
            'wp-login.php',
            'wp-mail.php',
            'wp-settings.php',
            'wp-signup.php',
            'wp-trackback.php',
            'xmlrpc.php',
        ];

        $directories = array_values(array_unique(array_filter($directories, function($entry) use ($root_path) {
            return $entry !== '' && strpos($entry, '/') === false && is_dir($root_path . $entry);
        })));
        $files = array_values(array_unique(array_filter($files, function($entry) use ($root_path) {
            return $entry !== '' && is_file($root_path . $entry);
        })));

        return [
            'directories' => $directories,
            'files' => $files,
        ];
    }

    private function is_allowed_files_backup_root_entry($item, $path, $root_entries) {
        if (is_dir($path)) {
            return in_array($item, $root_entries['directories'] ?? [], true);
        }

        if (is_file($path)) {
            return in_array($item, $root_entries['files'] ?? [], true);
        }

        return false;
    }

    private function scan_files_backup_manifest($root_path, $backup_dir, $manifest_path, $root_entries = null) {
        $handle = @fopen($manifest_path, 'wb');
        if (!$handle) {
            return new WP_Error('backup_manifest_failed', __('Impossibile creare il manifest del backup file.', 'marrison-custom-updater'));
        }

        $stats = ['entries' => 0, 'files' => 0, 'bytes' => 0, 'skipped_count' => 0, 'skipped_bytes' => 0, 'skipped_files' => []];
        $root_path = wp_normalize_path(untrailingslashit($root_path));
        $root_entries = is_array($root_entries) ? $root_entries : $this->get_files_backup_allowed_root_entries($root_path);
        $stack = [$root_path];
        $part_limit = $this->get_files_backup_part_limit();
        $skip_large_files = get_option('marrison_files_backup_skip_large_files') === 'yes';
        $heartbeat_entries = 0;

        while (!empty($stack)) {
            $dir = array_pop($stack);
            $is_root_dir = wp_normalize_path(untrailingslashit($dir)) === $root_path;
            $items = @scandir($dir);
            if (!is_array($items)) {
                $relative_dir = ltrim(str_replace($root_path, '', wp_normalize_path($dir)), '/\\');
                $this->record_files_backup_skipped($stats, $relative_dir ?: $dir, 0, 'directory_not_readable');
                continue;
            }

            sort($items);
            for ($i = count($items) - 1; $i >= 0; $i--) {
                $item = $items[$i];
                if ($item === '.' || $item === '..') {
                    continue;
                }

                $path = $dir . '/' . $item;
                if ($is_root_dir && !$this->is_allowed_files_backup_root_entry($item, $path, $root_entries)) {
                    continue;
                }

                if (is_link($path) || $this->is_excluded_from_files_backup($path, $manifest_path)) {
                    continue;
                }

                $relative_path = ltrim(str_replace($root_path, '', wp_normalize_path($path)), '/\\');
                if ($relative_path === '') {
                    continue;
                }

                if (is_dir($path)) {
                    $entry = [
                        'type' => 'dir',
                        'path' => $path,
                        'rel' => $relative_path,
                        'mtime' => @filemtime($path) ?: time(),
                        'mode' => @fileperms($path) ?: 0755,
                        'size' => 0,
                    ];
                    if (!$this->write_backup_stream_data($handle, wp_json_encode($entry) . "\n")) {
                        fclose($handle);
                        return new WP_Error('backup_manifest_write_failed', __('Errore durante la scrittura del manifest del backup file.', 'marrison-custom-updater'));
                    }
                    $stats['entries']++;
                    $heartbeat_entries++;
                    if ($heartbeat_entries % 500 === 0) {
                        $this->mcu_touch_update_lock(['operation' => 'files_backup', 'stage' => 'manifest_scan', 'entries' => $stats['entries']]);
                    }
                    $stack[] = $path;
                    continue;
                }

                if (!is_file($path)) {
                    continue;
                }

                if (!is_readable($path)) {
                    $this->record_files_backup_skipped($stats, $relative_path, 0, 'file_not_readable');
                    continue;
                }

                $size = @filesize($path);
                if ($size === false) {
                    $this->record_files_backup_skipped($stats, $relative_path, 0, 'file_size_unavailable');
                    continue;
                }

                $part_margin = min(2 * 1024 * 1024, max(128 * 1024, (int) floor($part_limit * 0.05)));
                if ($size > ($part_limit - $part_margin)) {
                    if ($skip_large_files) {
                        $this->record_files_backup_skipped($stats, $relative_path, $size, 'file_too_large');
                        continue;
                    }

                    fclose($handle);
                    return new WP_Error(
                        'backup_single_file_too_large',
                        sprintf(__('File troppo grande per il limite del singolo archivio (%1$s): %2$s', 'marrison-custom-updater'), size_format($part_limit), $relative_path)
                    );
                }

                $entry = [
                    'type' => 'file',
                    'path' => $path,
                    'rel' => $relative_path,
                    'mtime' => @filemtime($path) ?: time(),
                    'mode' => @fileperms($path) ?: 0644,
                    'size' => $size,
                ];
                if (!$this->write_backup_stream_data($handle, wp_json_encode($entry) . "\n")) {
                    fclose($handle);
                    return new WP_Error('backup_manifest_write_failed', __('Errore durante la scrittura del manifest del backup file.', 'marrison-custom-updater'));
                }
                $stats['entries']++;
                $stats['files']++;
                $stats['bytes'] += $size;
                $heartbeat_entries++;
                if ($heartbeat_entries % 500 === 0) {
                    $this->mcu_touch_update_lock(['operation' => 'files_backup', 'stage' => 'manifest_scan', 'entries' => $stats['entries']]);
                }
            }
        }

        if (!fclose($handle)) {
            return new WP_Error('backup_manifest_close_failed', __('Errore durante la chiusura del manifest del backup file.', 'marrison-custom-updater'));
        }
        if ($stats['files'] === 0) {
            return new WP_Error('backup_empty', __('Nessun file leggibile trovato per il backup.', 'marrison-custom-updater'));
        }

        return $stats;
    }

    private function process_files_backup_job($state) {
        $deadline = time() + 8;
        $handle = @fopen($state['manifest_path'], 'rb');
        if (!$handle) {
            return new WP_Error('backup_manifest_missing', __('Manifest del backup non trovato.', 'marrison-custom-updater'));
        }
        fseek($handle, (int) $state['manifest_offset']);
        $heartbeat_entries = 0;

        while (!feof($handle) && time() < $deadline) {
            $line = fgets($handle);
            if ($line === false) {
                break;
            }

            $entry = json_decode($line, true);
            if (!is_array($entry)) {
                fclose($handle);
                return new WP_Error('backup_manifest_invalid', __('Manifest del backup non valido.', 'marrison-custom-updater'));
            }

            $result = $this->write_files_backup_job_entry($state, $entry);
            if (is_wp_error($result)) {
                fclose($handle);
                return $result;
            }

            $state['processed_entries']++;
            if ($entry['type'] === 'file') {
                $state['processed_files']++;
                $state['processed_bytes'] += (int) $entry['size'];
            }
            $state['manifest_offset'] = ftell($handle);
            $heartbeat_entries++;
            if ($heartbeat_entries % 100 === 0) {
                $this->mcu_touch_update_lock(['operation' => 'files_backup', 'stage' => 'archive_write', 'processed_entries' => $state['processed_entries']]);
            }
        }

        $complete = feof($handle);
        fclose($handle);

        if ($complete) {
            if ((int) $state['processed_entries'] !== (int) $state['total_entries']) {
                return new WP_Error('backup_manifest_incomplete', __('Manifest del backup incompleto: il numero di elementi processati non coincide.', 'marrison-custom-updater'));
            }

            $result = $this->finalize_files_backup_job_part($state);
            if (is_wp_error($result)) {
                return $result;
            }
            $state['status'] = 'complete';
            $this->cleanup_files_backup_sets($state['backup_dir'], $this->get_files_backup_max_sets());
            $this->delete_files_backup_job($state);
        } else {
            $this->save_files_backup_job($state);
        }

        return $state;
    }

    private function record_files_backup_skipped(&$state, $relative_path, $size = 0, $reason = '') {
        $state['skipped_count'] = (int) ($state['skipped_count'] ?? 0) + 1;
        $state['skipped_bytes'] = (int) ($state['skipped_bytes'] ?? 0) + max(0, (int) $size);
        if (!isset($state['skipped_files']) || !is_array($state['skipped_files'])) {
            $state['skipped_files'] = [];
        }
        if (count($state['skipped_files']) < 50) {
            $state['skipped_files'][] = [
                'path' => $relative_path,
                'size' => max(0, (int) $size),
                'reason' => $reason,
            ];
        }
    }

    private function write_files_backup_job_entry(&$state, $entry) {
        $part_path = $this->get_files_backup_job_part_path($state);
        $entry_size = ($entry['type'] === 'file') ? (int) $entry['size'] : 0;

        $part_margin = min(2 * 1024 * 1024, max(128 * 1024, (int) floor(((int) $state['part_limit']) * 0.05)));
        if ($state['current_part_entries'] > 0 && file_exists($part_path) && (filesize($part_path) + $entry_size + $part_margin) > (int) $state['part_limit']) {
            $result = $this->finalize_files_backup_job_part($state);
            if (is_wp_error($result)) {
                return $result;
            }
            $state['current_part']++;
            $state['current_part_entries'] = 0;
            $part_path = $this->get_files_backup_job_part_path($state);
        }

        if ($entry['type'] === 'file') {
            if (!file_exists($entry['path']) || !is_file($entry['path']) || !is_readable($entry['path'])) {
                $this->record_files_backup_skipped($state, $entry['rel'], (int) $entry['size'], 'file_missing_or_not_readable');
                return true;
            }

            $current_size = @filesize($entry['path']);
            if ($current_size === false || (int) $current_size !== (int) $entry['size']) {
                $this->record_files_backup_skipped($state, $entry['rel'], (int) $entry['size'], 'file_changed_during_backup');
                return true;
            }
        }

        $mode = file_exists($part_path) ? 'ab1' : 'wb1';
        $handle = @gzopen($part_path, $mode);
        if (!$handle) {
            return new WP_Error('targz_open_failed', __('Impossibile aprire una parte tar.gz del backup.', 'marrison-custom-updater'));
        }

        if ($entry['type'] === 'dir') {
            $ok = $this->write_tar_header($handle, rtrim($entry['rel'], '/') . '/', 0, (int) $entry['mtime'], '5', (int) $entry['mode']);
            $closed = @gzclose($handle);
            if (!$ok || !$closed) {
                return new WP_Error('targz_write_failed', sprintf(__('Errore durante la scrittura della directory: %s', 'marrison-custom-updater'), $entry['rel']));
            }
            $state['current_part_entries']++;
            return true;
        }

        $ok = $this->write_tar_header($handle, $entry['rel'], (int) $entry['size'], (int) $entry['mtime'], '0', (int) $entry['mode']);
        if ($ok) {
            $ok = $this->write_file_to_tar_gz_handle($handle, $entry['path'], $entry['rel'], (int) $entry['size']);
        }
        $closed = @gzclose($handle);

        if (is_wp_error($ok)) {
            return $ok;
        }
        if (!$ok || !$closed) {
            return new WP_Error('targz_write_failed', sprintf(__('Errore durante la scrittura dell\'archivio per il file: %s', 'marrison-custom-updater'), $entry['rel']));
        }

        $state['current_part_entries']++;
        return true;
    }

    private function write_file_to_tar_gz_handle($handle, $path, $relative_path, $size) {
        $file_handle = @fopen($path, 'rb');
        if (!$file_handle) {
            return new WP_Error('backup_file_open_failed', sprintf(__('Impossibile aprire il file durante il backup: %s', 'marrison-custom-updater'), $relative_path));
        }

        $written = 0;
        while ($written < $size && !feof($file_handle)) {
            $remaining = $size - $written;
            $chunk = fread($file_handle, min(1024 * 1024, $remaining));
            if ($chunk === false) {
                fclose($file_handle);
                return new WP_Error('backup_file_read_failed', sprintf(__('Errore durante la lettura del file: %s', 'marrison-custom-updater'), $relative_path));
            }
            if ($chunk === '') {
                continue;
            }
            if (!$this->write_tar_data($handle, $chunk)) {
                fclose($file_handle);
                return new WP_Error('targz_write_failed', sprintf(__('Errore durante la scrittura del file: %s', 'marrison-custom-updater'), $relative_path));
            }
            $written += strlen($chunk);
            $this->mcu_touch_update_lock(['operation' => 'files_backup', 'stage' => 'file_chunk', 'file' => $relative_path]);
        }
        fclose($file_handle);

        $padding = (512 - ($written % 512)) % 512;
        if ($padding > 0 && !$this->write_tar_data($handle, str_repeat("\0", $padding))) {
            return new WP_Error('targz_write_failed', sprintf(__('Errore durante la scrittura del padding del file: %s', 'marrison-custom-updater'), $relative_path));
        }

        if ($written !== $size) {
            return new WP_Error('backup_file_incomplete', sprintf(__('File scritto parzialmente durante il backup: %s', 'marrison-custom-updater'), $relative_path));
        }

        return true;
    }

    private function get_files_backup_job_part_path($state, $final = false) {
        $path = trailingslashit($state['backup_dir']) . $state['prefix'] . '-part' . sprintf('%03d', (int) $state['current_part']) . '.tar.gz';
        return $final ? $path : $path . '.tmp';
    }

    private function finalize_files_backup_job_part(&$state) {
        $part_path = $this->get_files_backup_job_part_path($state);
        if ($state['current_part_entries'] <= 0 || !file_exists($part_path)) {
            return true;
        }

        $handle = @gzopen($part_path, 'ab1');
        if (!$handle) {
            return new WP_Error('targz_open_failed', __('Impossibile finalizzare una parte tar.gz del backup.', 'marrison-custom-updater'));
        }
        $ok = $this->write_tar_data($handle, str_repeat("\0", 1024));
        $closed = @gzclose($handle);
        if (!$ok || !$closed) {
            return new WP_Error('targz_write_failed', __('Errore durante la finalizzazione di una parte tar.gz del backup.', 'marrison-custom-updater'));
        }

        $final_path = $this->get_files_backup_job_part_path($state, true);
        if (!@rename($part_path, $final_path)) {
            return new WP_Error('targz_rename_failed', __('Impossibile finalizzare il nome di una parte tar.gz del backup.', 'marrison-custom-updater'));
        }

        $filename = basename($final_path);
        if (!in_array($filename, $state['parts'], true)) {
            $state['parts'][] = $filename;
        }

        return true;
    }

    private function cleanup_files_backup_sets($backup_dir, $max_sets = null) {
        $max_sets = ($max_sets === null) ? $this->get_files_backup_max_sets() : max(1, (int) $max_sets);

        foreach (glob($backup_dir . '/files-backup-*.tmp') ?: [] as $tmp_file) {
            if (filemtime($tmp_file) < time() - DAY_IN_SECONDS) {
                @unlink($tmp_file);
            }
        }

        $files = array_merge(
            glob($backup_dir . '/files-backup-*.tar.gz') ?: [],
            glob($backup_dir . '/files-backup-*.zip') ?: []
        );
        $sets = [];
        foreach ($files as $file) {
            $filename = basename($file);
            if (preg_match('/^(files-backup-\d{8}-\d{6})(?:-part\d{3})?\.(?:tar\.gz|zip)$/', $filename, $matches)) {
                $sets[$matches[1]][] = $file;
            }
        }
        if (count($sets) <= $max_sets) {
            return;
        }

        uasort($sets, function($a, $b) {
            return max(array_map('filemtime', $b)) - max(array_map('filemtime', $a));
        });

        $old_sets = array_slice($sets, $max_sets, null, true);
        foreach ($old_sets as $set_files) {
            foreach ($set_files as $file) {
                @unlink($file);
            }
        }
    }

    private function add_files_to_backup_tar_gz($handle, $dir, $root_path, $archive_path, &$stats) {
        $items = @scandir($dir);
        if (!is_array($items)) {
            return new WP_Error('backup_read_failed', sprintf(__('Impossibile leggere la directory: %s', 'marrison-custom-updater'), $dir));
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_link($path) || $this->is_excluded_from_files_backup($path, $archive_path)) {
                continue;
            }

            $relative_path = ltrim(str_replace($root_path, '', wp_normalize_path($path)), '/\\');
            if ($relative_path === '') {
                continue;
            }

            if (is_dir($path)) {
                $mtime = @filemtime($path);
                if (!$this->write_tar_header($handle, rtrim($relative_path, '/') . '/', 0, $mtime ?: time(), '5', @fileperms($path) ?: 0755)) {
                    return new WP_Error('targz_write_failed', sprintf(__('Errore durante la scrittura della directory: %s', 'marrison-custom-updater'), $relative_path));
                }

                $stats['dirs']++;
                $result = $this->add_files_to_backup_tar_gz($handle, $path, $root_path, $archive_path, $stats);
                if (is_wp_error($result)) {
                    return $result;
                }
            } elseif (is_file($path)) {
                if (!is_readable($path)) {
                    return new WP_Error('backup_file_not_readable', sprintf(__('File non leggibile durante il backup: %s', 'marrison-custom-updater'), $relative_path));
                }

                $size = @filesize($path);
                if ($size === false) {
                    return new WP_Error('backup_file_size_failed', sprintf(__('Impossibile determinare la dimensione del file: %s', 'marrison-custom-updater'), $relative_path));
                }

                $mtime = @filemtime($path);
                if (!$this->write_tar_header($handle, $relative_path, $size, $mtime ?: time(), '0', @fileperms($path) ?: 0644)) {
                    return new WP_Error('targz_write_failed', sprintf(__('Errore durante la scrittura dell\'header del file: %s', 'marrison-custom-updater'), $relative_path));
                }

                $file_handle = @fopen($path, 'rb');
                if (!$file_handle) {
                    return new WP_Error('backup_file_open_failed', sprintf(__('Impossibile aprire il file durante il backup: %s', 'marrison-custom-updater'), $relative_path));
                }

                $written = 0;
                while (!feof($file_handle)) {
                    $chunk = fread($file_handle, 1024 * 1024);
                    if ($chunk === false) {
                        fclose($file_handle);
                        return new WP_Error('backup_file_read_failed', sprintf(__('Errore durante la lettura del file: %s', 'marrison-custom-updater'), $relative_path));
                    }
                    if ($chunk === '') {
                        continue;
                    }
                    if (!$this->write_tar_data($handle, $chunk)) {
                        fclose($file_handle);
                        return new WP_Error('targz_write_failed', sprintf(__('Errore durante la scrittura del file: %s', 'marrison-custom-updater'), $relative_path));
                    }
                    $written += strlen($chunk);
                }
                fclose($file_handle);

                $padding = (512 - ($written % 512)) % 512;
                if ($padding > 0 && !$this->write_tar_data($handle, str_repeat("\0", $padding))) {
                    return new WP_Error('targz_write_failed', sprintf(__('Errore durante la scrittura del padding del file: %s', 'marrison-custom-updater'), $relative_path));
                }

                $stats['files']++;
                $stats['bytes'] += $written;
            }
        }

        return true;
    }

    private function write_tar_header($handle, $path, $size, $mtime, $typeflag = '0', $mode = 0644, $allow_pax = true) {
        $path = ltrim(str_replace('\\', '/', $path), '/');

        if ($allow_pax && !$this->tar_path_fits_ustar($path)) {
            $pax_data = $this->build_tar_pax_record('path', $path);
            $pax_name = 'PaxHeaders/' . substr(basename($path), 0, 90);
            if (!$this->write_tar_header($handle, $pax_name, strlen($pax_data), time(), 'x', 0644, false)) {
                return false;
            }
            if (!$this->write_tar_data($handle, $pax_data)) {
                return false;
            }
            $padding = (512 - (strlen($pax_data) % 512)) % 512;
            if ($padding > 0 && !$this->write_tar_data($handle, str_repeat("\0", $padding))) {
                return false;
            }
        }

        list($name, $prefix) = $this->get_tar_name_fields($path);
        $header  = str_pad($name, 100, "\0");
        $header .= $this->format_tar_number($mode & 0777, 8);
        $header .= $this->format_tar_number(0, 8);
        $header .= $this->format_tar_number(0, 8);
        $header .= $this->format_tar_number($size, 12);
        $header .= $this->format_tar_number($mtime, 12);
        $header .= str_repeat(' ', 8);
        $header .= $typeflag;
        $header .= str_repeat("\0", 100);
        $header .= "ustar\0";
        $header .= '00';
        $header .= str_pad('mcu', 32, "\0");
        $header .= str_pad('mcu', 32, "\0");
        $header .= $this->format_tar_number(0, 8);
        $header .= $this->format_tar_number(0, 8);
        $header .= str_pad($prefix, 155, "\0");
        $header .= str_repeat("\0", 12);

        $checksum = 0;
        for ($i = 0; $i < 512; $i++) {
            $checksum += ord($header[$i]);
        }
        $checksum_field = sprintf('%06o', $checksum) . "\0 ";
        $header = substr($header, 0, 148) . $checksum_field . substr($header, 156);

        return $this->write_tar_data($handle, $header);
    }

    private function write_tar_data($handle, $data) {
        $length = strlen($data);
        $offset = 0;
        while ($offset < $length) {
            $written = @gzwrite($handle, substr($data, $offset));
            if ($written === false || $written <= 0) {
                return false;
            }
            $offset += $written;
        }
        return true;
    }

    private function build_tar_pax_record($key, $value) {
        $payload = $key . '=' . $value . "\n";
        $length = strlen($payload) + 2;
        do {
            $record = $length . ' ' . $payload;
            $new_length = strlen($record);
            if ($new_length === $length) {
                return $record;
            }
            $length = $new_length;
        } while (true);
    }

    private function get_tar_name_fields($path) {
        if (strlen($path) <= 100) {
            return [$path, ''];
        }

        $best = null;
        $length = strlen($path);
        for ($i = 0; $i < $length; $i++) {
            if ($path[$i] !== '/') {
                continue;
            }
            $prefix = substr($path, 0, $i);
            $name = substr($path, $i + 1);
            if (strlen($prefix) <= 155 && strlen($name) <= 100) {
                $best = [$name, $prefix];
            }
        }

        if ($best) {
            return $best;
        }

        return [substr(basename($path), 0, 100), ''];
    }

    private function tar_path_fits_ustar($path) {
        if (strlen($path) <= 100) {
            return true;
        }

        $length = strlen($path);
        for ($i = 0; $i < $length; $i++) {
            if ($path[$i] !== '/') {
                continue;
            }
            $prefix = substr($path, 0, $i);
            $name = substr($path, $i + 1);
            if (strlen($prefix) <= 155 && strlen($name) <= 100) {
                return true;
            }
        }

        return false;
    }

    private function format_tar_number($value, $length) {
        $max_octal = pow(8, $length - 1) - 1;
        if ($value <= $max_octal) {
            return sprintf('%0' . ($length - 1) . 'o', $value) . "\0";
        }

        $bytes = array_fill(0, $length, 0);
        for ($i = $length - 1; $i >= 0; $i--) {
            $bytes[$i] = $value & 0xff;
            $value = intdiv($value, 256);
        }
        $bytes[0] |= 0x80;
        return implode('', array_map('chr', $bytes));
    }

    private function is_excluded_from_files_backup($path, $zip_path) {
        $normalized_path = wp_normalize_path($path);
        $exclude_roots = [
            wp_normalize_path($this->get_backup_dir()),
            wp_normalize_path(WP_CONTENT_DIR . '/upgrade'),
            wp_normalize_path(WP_CONTENT_DIR . '/cache'),
            wp_normalize_path(ABSPATH . '.git'),
        ];

        foreach ($exclude_roots as $exclude_root) {
            if ($exclude_root && strpos($normalized_path, untrailingslashit($exclude_root) . '/') === 0) {
                return true;
            }
            if ($normalized_path === untrailingslashit($exclude_root)) {
                return true;
            }
        }

        $basename = basename($normalized_path);
        if (in_array($basename, ['debug.log', 'error_log'], true)) {
            return true;
        }

        return $normalized_path === wp_normalize_path($zip_path);
    }

    private function get_backup_download_token($filename, $type) {
        return wp_hash($type . '|' . $filename);
    }

    private function get_backup_download_url($filename, $type = 'db') {
        $action = ($type === 'files') ? 'marrison_download_files_backup' : 'marrison_download_db_backup';
        return add_query_arg(
            [
                'action' => $action,
                'file'   => $filename,
                'token'  => $this->get_backup_download_token($filename, $type),
            ],
            admin_url('admin-post.php')
        );
    }

    private function can_download_backup($filename, $type) {
        $token = sanitize_text_field($_GET['token'] ?? '');
        if ($token && hash_equals($this->get_backup_download_token($filename, $type), $token)) {
            return true;
        }

        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            return false;
        }

        return isset($_GET['_wpnonce']) && wp_verify_nonce(sanitize_text_field($_GET['_wpnonce']), 'marrison_download_' . $type . '_backup');
    }

    private function escape_for_sql($wpdb, $val) {
        if (isset($wpdb->dbh) && ($wpdb->dbh instanceof mysqli)) {
            return mysqli_real_escape_string($wpdb->dbh, $val);
        }
        return str_replace(
            ["\\", "'", "\n", "\r", "\x00", "\x1a"],
            ["\\\\", "\\'", "\\n", "\\r", "\\0", "\\Z"],
            $val
        );
    }

    public function ajax_db_backup() {
        check_ajax_referer('marrison_db_backup', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permessi insufficienti.', 'marrison-custom-updater'));
        }

        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        try {
            $filename = $this->create_db_backup();
            if (is_wp_error($filename)) {
                wp_send_json_error($filename->get_error_message());
            } elseif ($filename) {
                $record = get_option('marrison_db_backup_integrity', []);
                $record = is_array($record) && isset($record[$filename]) ? $record[$filename] : [];
                update_option('marrison_last_db_backup_filename', $filename);
                $message = __('Backup database completato e verificato!', 'marrison-custom-updater');
                if (!empty($record)) {
                    $message .= ' ' . sprintf(
                        __('%1$s tabelle, %2$s righe.', 'marrison-custom-updater'),
                        function_exists('number_format_i18n') ? number_format_i18n((int) ($record['total_tables'] ?? 0)) : number_format((int) ($record['total_tables'] ?? 0)),
                        function_exists('number_format_i18n') ? number_format_i18n((int) ($record['total_rows'] ?? 0)) : number_format((int) ($record['total_rows'] ?? 0))
                    );
                }

                wp_send_json_success(['message' => $message, 'filename' => $filename]);
            } else {
                wp_send_json_error('Errore durante la creazione del backup database.');
            }
        } catch (Exception $e) {
            wp_send_json_error('Errore: ' . $e->getMessage());
        } catch (Error $e) {
            wp_send_json_error('Errore fatale: ' . $e->getMessage());
        }
    }

    public function ajax_files_backup() {
        check_ajax_referer('marrison_files_backup', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permessi insufficienti.', 'marrison-custom-updater'));
        }

        @set_time_limit(30);
        @ini_set('memory_limit', '512M');

        try {
            $job_id = sanitize_key($_POST['job_id'] ?? '');
            $state = $job_id ? $this->load_files_backup_job($job_id) : $this->init_files_backup_job();
            if (is_wp_error($state)) {
                wp_send_json_error($state->get_error_message());
            }
            if (!$state) {
                wp_send_json_error(__('Job backup non trovato o scaduto.', 'marrison-custom-updater'));
            }

            $running_state = $state;
            $state = $this->process_files_backup_job($state);
            if (is_wp_error($state)) {
                $this->cleanup_files_backup_job_artifacts($running_state);
                wp_send_json_error($state->get_error_message());
            }

            $percent = $state['total_bytes'] > 0
                ? min(99, (int) floor(($state['processed_bytes'] / $state['total_bytes']) * 100))
                : 0;

            if ($state['status'] === 'complete') {
                update_option('marrison_last_files_backup_filenames', $state['parts']);
                $message = sprintf(__('Backup file completato in %d parti.', 'marrison-custom-updater'), count($state['parts']));
                if (!empty($state['skipped_count'])) {
                    $message .= ' ' . sprintf(
                        __('Saltati %1$d file (%2$s).', 'marrison-custom-updater'),
                        (int) $state['skipped_count'],
                        size_format((int) $state['skipped_bytes'])
                    );
                }

                wp_send_json_success([
                    'done' => true,
                    'percent' => 100,
                    'message' => $message,
                    'files' => $state['parts'],
                    'skipped_count' => (int) $state['skipped_count'],
                    'skipped_files' => $state['skipped_files'],
                ]);
            } else {
                wp_send_json_success([
                    'done' => false,
                    'job_id' => $state['job_id'],
                    'percent' => $percent,
                    'message' => sprintf(
                        __('Backup file in corso: %1$d/%2$d file, %3$s/%4$s.', 'marrison-custom-updater'),
                        (int) $state['processed_files'],
                        (int) $state['total_files'],
                        size_format((int) $state['processed_bytes']),
                        size_format((int) $state['total_bytes'])
                    ),
                ]);
            }
        } catch (Exception $e) {
            wp_send_json_error('Errore: ' . $e->getMessage());
        } catch (Error $e) {
            wp_send_json_error('Errore fatale: ' . $e->getMessage());
        }
    }

    public function ajax_delete_backup() {
        $filename = sanitize_file_name($_POST['filename'] ?? '');
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'marrison_delete_backup_' . $filename)) {
            wp_send_json_error(__('Security check failed', 'marrison-custom-updater'));
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permessi insufficienti.', 'marrison-custom-updater'));
        }

        if (!$this->is_valid_backup_filename($filename)) {
            wp_send_json_error(__('File backup non valido.', 'marrison-custom-updater'));
        }

        $backup_dir = $this->get_backup_dir();
        $file_path = $backup_dir . '/' . $filename;
        $real_backup_dir = realpath($backup_dir);
        $real_file_path = realpath($file_path);

        if (!$real_backup_dir || !$real_file_path || strpos(wp_normalize_path($real_file_path), trailingslashit(wp_normalize_path($real_backup_dir))) !== 0) {
            wp_send_json_error(__('File backup non trovato.', 'marrison-custom-updater'));
        }

        if (!is_file($real_file_path) || !@unlink($real_file_path)) {
            wp_send_json_error(__('Impossibile eliminare il backup.', 'marrison-custom-updater'));
        }

        if (strpos($filename, 'db-backup-') === 0) {
            $this->remove_db_backup_integrity_record($filename);
        }

        wp_send_json_success(['message' => __('Backup eliminato correttamente.', 'marrison-custom-updater')]);
    }

    private function is_valid_backup_filename($filename) {
        if (empty($filename)) {
            return false;
        }

        return (
            (strpos($filename, 'db-backup-') === 0 && pathinfo($filename, PATHINFO_EXTENSION) === 'zip') ||
            $this->is_files_backup_filename($filename) ||
            preg_match('/^.+-backup\.zip$/', $filename)
        );
    }

    private function is_files_backup_filename($filename) {
        return (bool) preg_match('/^files-backup-\d{8}-\d{6}(?:-part\d{3})?\.(zip|tar\.gz)$/', $filename);
    }

    public function download_db_backup() {
        $filename = sanitize_file_name($_GET['file'] ?? '');
        if (empty($filename) || strpos($filename, 'db-backup-') !== 0 || pathinfo($filename, PATHINFO_EXTENSION) !== 'zip') {
            wp_die('File non valido.');
        }

        if (!$this->can_download_backup($filename, 'db')) {
            wp_die(esc_html__('Permessi insufficienti.', 'marrison-custom-updater'));
        }

        $backup_dir = $this->get_backup_dir();
        $file_path  = $backup_dir . '/' . $filename;
        if (!file_exists($file_path)) wp_die('File non trovato.');

        $this->stream_backup_download($file_path, $filename);
    }

    public function download_files_backup() {
        $filename = sanitize_file_name($_GET['file'] ?? '');
        if (empty($filename) || !$this->is_files_backup_filename($filename)) {
            wp_die('File non valido.');
        }

        if (!$this->can_download_backup($filename, 'files')) {
            wp_die(esc_html__('Permessi insufficienti.', 'marrison-custom-updater'));
        }

        $backup_dir = $this->get_backup_dir();
        $file_path  = $backup_dir . '/' . $filename;
        if (!file_exists($file_path)) wp_die('File non trovato.');

        $this->stream_backup_download($file_path, $filename);
    }

    private function stream_backup_download($file_path, $filename) {
        @set_time_limit(0);
        @ini_set('zlib.output_compression', 'Off');

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        $handle = fopen($file_path, 'rb');
        if (!$handle) {
            wp_die('Impossibile leggere il file.');
        }

        $content_type = (substr($filename, -7) === '.tar.gz') ? 'application/gzip' : 'application/zip';

        header('Content-Description: File Transfer');
        header('Content-Type: ' . $content_type);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($file_path));
        header('Cache-Control: must-revalidate');
        header('Pragma: public');

        $chunk_size = 1024 * 1024;
        while (!feof($handle)) {
            echo fread($handle, $chunk_size);
            flush();
            if (connection_aborted()) {
                break;
            }
        }

        fclose($handle);
        exit;
    }

    public function trigger_elementor_db_update($upgrader_object, $options) {


        if (!isset($options['action']) || $options['action'] !== 'update') {
            return;
        }
        if (!isset($options['type']) || $options['type'] !== 'plugin') {
            return;
        }
        
        $plugins = [];
        if (isset($options['plugins']) && is_array($options['plugins'])) {
            $plugins = $options['plugins'];
        } elseif (isset($options['plugin'])) {
            $plugins = [$options['plugin']];
        }

        if (empty($plugins)) {
            return;
        }

        $elementor_updated = false;
        foreach ($plugins as $plugin) {
            // Elementor slug/file is typically 'elementor/elementor.php'
            if (strpos($plugin, 'elementor/elementor.php') !== false) {
                $elementor_updated = true;
                break;
            }
        }

        if ($elementor_updated) {
            // Breve delay per assicurare che il filesystem sia stabile e la cache aggiornata
            sleep(3);

            if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
                return;
            }
            
            // Assicurati che le classi necessarie siano caricate
            if ( class_exists( '\Elementor\App\Modules\ImportExport\Utils' ) || class_exists( '\Elementor\Plugin' ) ) {
                
                // Forza l'aggiornamento del database di Elementor
                // Rimosso il metodo get_remote_info() che non esiste nelle versioni recenti o è privato
                
                if (isset(\Elementor\Plugin::$instance->updater) && method_exists(\Elementor\Plugin::$instance->updater, 'update')) {
                    \Elementor\Plugin::$instance->updater->update();
                    $this->mcu_log_event('info', 'elementor_db_update_triggered', ['plugin' => 'elementor/elementor.php']);
                }
            }
        }
    }
}
