<?php
/** Local WordPress doubles. No network, credentials, installation or database. */
namespace MarrisonCustomUpdater\MaintenanceClient {
    final class Settings { public static $authorized = true; public static function repository_config_managed() { return self::$authorized; } }
    final class Debug_Logger { public static function log($event, $data) {} }
    final class Rest_Controller { public static function collect_status() { return ['updates_data_fresh' => true, 'plugin_updates_count' => $GLOBALS['remaining'], 'theme_updates_count' => 0, 'translation_updates_count' => 0]; } }
}
namespace {
    use MarrisonCustomUpdater\MaintenanceClient\Actions_Controller;
    use MarrisonCustomUpdater\MaintenanceClient\Update_Verification;
    use MarrisonCustomUpdater\MaintenanceClient\Settings;
    define('ABSPATH', __DIR__ . '/missing-wordpress/');
    define('WPINC', 'wp-includes');
    define('MINUTE_IN_SECONDS', 60);
    define('HOUR_IN_SECONDS', 3600);
    $options = []; $cache = []; $siteCache = []; $hooks = []; $events = []; $requests = []; $failure = '';
    class WP_Error {}
    function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
    function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; return true; }
    function add_option($key, $value, $unused = '', $autoload = null) { if (isset($GLOBALS['options'][$key])) return false; return update_option($key, $value); }
    function delete_option($key) { unset($GLOBALS['options'][$key]); }
    function get_transient($key) { return $GLOBALS['cache'][$key] ?? false; }
    function set_transient($key, $value, $ttl = 0) { $GLOBALS['cache'][$key] = $value; }
    function delete_transient($key) { unset($GLOBALS['cache'][$key]); }
    function get_site_transient($key) { return $GLOBALS['siteCache'][$key] ?? false; }
    function set_site_transient($key, $value) { $GLOBALS['siteCache'][$key] = $value; }
    function delete_site_transient($key) { unset($GLOBALS['siteCache'][$key]); }
    function wp_clean_update_cache() { delete_site_transient('update_core'); }
    function wp_clean_plugins_cache($updates = true) {}
    function wp_clean_themes_cache($updates = true) {}
    function wp_generate_uuid4() { return uniqid('fixture-', true); }
    function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)); }
    function __($value, $domain = '') { return $value; }
    function wp_parse_url($value, $component) { return parse_url($value, $component); }
    function trailingslashit($value) { return $value === '' ? '' : rtrim($value, '/') . '/'; }
    function is_wp_error($value) { return $value instanceof WP_Error; }
    function wp_remote_retrieve_response_code($value) { return $value['response']['code'] ?? 0; }
    function wp_remote_retrieve_body($value) { return $value['body'] ?? ''; }
    function add_action($hook, $callback, $priority = 10, $args = 1) { $GLOBALS['hooks'][$hook][] = $callback; }
    function remove_action($hook, $callback, $priority = 10) {
        $GLOBALS['hooks'][$hook] = array_filter($GLOBALS['hooks'][$hook] ?? [], fn($item) => $item !== $callback);
    }
    function apply_filters($hook, $value) { return $hook === 'mcu_refresh_self_update' ? ($GLOBALS['failure'] !== 'github' ? '9.8.24' : false) : $value; }
    function wp_next_scheduled($hook) { return $GLOBALS['events'][$hook] ?? false; }
    function wp_schedule_single_event($when, $hook) { $GLOBALS['events'][$hook] = $when; return true; }
    function wp_clear_scheduled_hook($hook) { unset($GLOBALS['events'][$hook]); }
    function get_plugins() { return ['one/main.php' => ['Version' => '1.0']]; }
    function wp_remote_get($url, $args = []) {
        $GLOBALS['requests'][] = $url;
        if ($GLOBALS['failure'] === 'private') { return new WP_Error(); }
        return ['response' => ['code' => 200], 'body' => '[]'];
    }
    function simulateSource($kind) {
        $GLOBALS['requests'][] = $kind;
        $key = $kind === 'core' ? 'offers' : $kind;
        $result = ['response' => ['code' => $GLOBALS['failure'] === $kind ? 503 : 200],
            'body' => $GLOBALS['failure'] === 'invalid-json' ? '<html>challenge</html>' : json_encode([$key => []])];
        foreach ($GLOBALS['hooks']['http_api_debug'] ?? [] as $hook) { $hook($result, 'response', '', [], 'https://api.wordpress.org/' . $kind . '/' . ($kind === 'core' ? 'version-check' : 'update-check') . '/1.1/'); }
        set_site_transient('update_' . $kind, (object) ['last_checked' => time(), 'response' => [], 'updates' => []]);
    }
    function wp_version_check($extra = [], $force = false) { simulateSource('core'); }
    function wp_update_plugins() { simulateSource('plugins'); }
    function wp_update_themes() { simulateSource('themes'); }
    function wp_get_translation_updates() { return []; }
    require __DIR__ . '/../includes/mcu-client/class-actions-controller.php';
    require __DIR__ . '/../includes/mcu-client/class-update-verification.php';
    $checks = 0;
    function check($condition, $label) { if (!$condition) throw new \RuntimeException($label); $GLOBALS['checks']++; }
    check(Update_Verification::time() === '03:00', 'Default 03:00');
    foreach (['2026-03-28 12:00', '2026-03-29 12:00', '2026-10-24 12:00', '2026-10-25 12:00'] as $date) {
        $now = new \DateTimeImmutable($date, new \DateTimeZone('Europe/Rome'));
        $next = (new \DateTimeImmutable('@' . Update_Verification::next_run($now->getTimestamp())))->setTimezone(new \DateTimeZone('Europe/Rome'));
        check($next->format('H:i') === '03:00', '03:00 across DST: ' . $date);
        check($next->getTimestamp() > $now->getTimestamp(), 'Future event');
    }
    $options['mcu_update_verification_time'] = '27:80';
    check(Update_Verification::time() === '03:00', 'Invalid saved time');
    $options['mcu_update_verification_time'] = '04:25';
    check(Update_Verification::time() === '04:25', 'Custom time');
    Update_Verification::ensure_scheduled();
    check(isset($events[Update_Verification::HOOK]), 'Schedules daily event');
    $initial = $events;
    Update_Verification::ensure_scheduled();
    check($initial === $events, 'No duplicates');
    $options['marrison_repo_url'] = 'https://private.invalid/plugins';
    $result = Actions_Controller::force_update_sync();
    check(!empty($result['success']), 'All sources complete successfully');
    check($options['mcu_update_sync_status']['status'] === 'completed', 'Persists actual success');
    $lastSuccess = $options['mcu_update_sync_status']['last_success_at'];
    foreach (['plugins', 'themes', 'core', 'private', 'github', 'invalid-json'] as $failure) {
        $result = Actions_Controller::force_update_sync();
        check(empty($result['success']), 'No false success on ' . $failure);
        check($options['mcu_update_sync_status']['last_success_at'] === $lastSuccess, 'Retains last success on ' . $failure);
        check(!isset($options['mcu_update_sync_lock']), 'Releases sync lock on failure');
    }
    $failure = 'plugins';
    Update_Verification::run();
    check(($events[Update_Verification::RETRY_HOOK] ?? 0) >= time() + 899, 'Retries after 15 minutes');
    $failure = '';
    Update_Verification::run();
    check(!isset($events[Update_Verification::RETRY_HOOK]), 'Clears retry after success');
    $verifyOutcome = new \ReflectionMethod(Actions_Controller::class, 'verify_update_outcome');
    $remaining = 1;
    $outcome = ['status' => 'completed', 'stage' => 'completed', 'message' => '3 plugin aggiornati.'];
    check($verifyOutcome->invoke(null, $outcome)['status'] === 'failed', '3 updates followed by 1 remaining never completes green');
    $remaining = 0;
    check($verifyOutcome->invoke(null, $outcome)['status'] === 'completed', 'Completes only after a fresh zero');
    $failure = 'plugins';
    check($verifyOutcome->invoke(null, $outcome)['status'] === 'failed', 'Cache wipe plus failed provider never completes green');
    $failure = '';
    $options['mcu_update_sync_lock'] = ['token' => 'other', 'expires_at' => time() + 100];
    $before = count($requests);
    check(empty(Actions_Controller::force_update_sync()['success']), 'Concurrent sync rejected');
    check($before === count($requests), 'No overlapping HTTP');
    $options['mcu_update_sync_lock']['expires_at'] = time() - 1;
    check(!empty(Actions_Controller::force_update_sync()['success']), 'Recovers expired sync lock');
    Settings::$authorized = false;
    Update_Verification::ensure_scheduled();
    check(!$events, 'Revocation stops daily and retry events');
    check(empty(Actions_Controller::force_update_sync()['success']), 'Unauthorized sync rejected');
    Settings::$authorized = true;
    $options['mcu_update_verification_enabled'] = 'no';
    Update_Verification::ensure_scheduled();
    check(!$events, 'Disabled scheduler stops events');
    echo 'MCU verification: ' . $checks . " checks passed.\n";
}
