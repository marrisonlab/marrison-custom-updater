<?php
/** Real MCU action dispatcher and core runner; local WordPress/upgrader doubles only. */
namespace MarrisonCustomUpdater\MaintenanceClient {
    class Settings {
        public static $authorized = true;
        public static function repository_config_managed() { return self::$authorized; }
    }
}
namespace {
    use MarrisonCustomUpdater\MaintenanceClient\Actions_Controller;
    use MarrisonCustomUpdater\MaintenanceClient\Settings;
    define('ABSPATH', __DIR__ . '/missing-wordpress/');
    define('WPINC', 'wp-includes');
    $options = []; $upgrades = []; $checks = 0; $mode = ''; $result = '6.9.2'; $allowed = true;
    $wp_version = '6.9.0';
    $offers = [(object) ['response' => 'upgrade', 'current' => '6.9.1', 'packages' => (object) ['full' => 'https://wordpress.invalid/one.zip']],
        (object) ['response' => 'upgrade', 'current' => '6.9.2', 'packages' => (object) ['full' => 'https://wordpress.invalid/two.zip']]];
    class WP_Error {
        public function __construct(private $code, private $message) {}
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
    }
    class Automatic_Upgrader_Skin {}
    class Core_Upgrader {
        public function __construct($skin) {}
        public function upgrade($offer, $args) {
            $GLOBALS['upgrades'][] = [$offer, $args];
            echo 'upgrader output must not corrupt JSON';
            if ($GLOBALS['mode'] === 'throw') throw new \RuntimeException('private exception details');
            return $GLOBALS['result'];
        }
    }
    function request_filesystem_credentials() {}
    function wp_is_file_mod_allowed($context) { return $GLOBALS['allowed']; }
    function wp_version_check($stats = [], $force = false) {
        if (!$force) throw new \RuntimeException('Core metadata must be refreshed');
        if (empty($GLOBALS['core_cache_cleared'])) throw new \RuntimeException('Cached core offers must be discarded before HTTP');
    }
    function delete_site_transient($key) { $GLOBALS['core_cache_cleared'] = $key === 'update_core'; }
    function get_site_transient($key) {
        if ($GLOBALS['mode'] === 'missing') return false;
        return (object) ['updates' => $GLOBALS['mode'] === 'http-failure' ? [] : $GLOBALS['offers'], 'version_checked' => $GLOBALS['mode'] === 'wrong-version' ? '5.0' : $GLOBALS['wp_version'],
            'last_checked' => time() - ($GLOBALS['mode'] === 'stale' ? 600 : 0)];
    }
    function is_wp_error($value) { return $value instanceof WP_Error; }
    function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
    function wp_strip_all_tags($value) { return strip_tags((string) $value); }
    function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $value)); }
    function wp_generate_uuid4() { return 'fixture-core-job'; }
    function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
    function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; }
    function get_transient($key) { return false; }
    function apply_filters($hook, $value, ...$args) {
        return $hook === 'mcu_remote_update_core' ? $GLOBALS['runner']->mcu_remote_update_core($value, ...$args) : $value;
    }
    require __DIR__ . '/../marrison-custom-updater/includes/traits/UpdateOperationsTrait.php';
    require __DIR__ . '/../marrison-custom-updater/includes/mcu-client/class-actions-controller.php';
    class Runner {
        use \MCU_Update_Operations_Trait;
        public $locked = false; public $busy = false; public $releases = 0;
        private function mcu_acquire_update_lock($operation, $context = []) {
            if ($this->busy) return new WP_Error('mcu_update_locked', 'Aggiornamento gia in corso.');
            $this->locked = true; return 'lock';
        }
        private function mcu_release_update_lock($token = '') { $this->locked = false; $this->releases++; }
        private function mcu_upgrader_failure_message($skin = null, $upgrader = null, $fallback = '') { return $fallback; }
    }
    $runner = new Runner();
    $action = new \ReflectionMethod(Actions_Controller::class, 'execute');
    function dispatch() { return $GLOBALS['action']->invoke(null, 'update_core', [
        'job_id' => 'batch', 'step' => 3, 'total' => 4, 'package' => 'https://ignored.invalid/evil.zip', 'new_version' => '0.1',
    ], 'fixture-site', microtime(true)); }
    function check($ok, $label) { if (!$ok) throw new \RuntimeException($label); $GLOBALS['checks']++; }
    ob_start(); $response = dispatch(); $output = ob_get_clean();
    check($response['success'] && $response['operation'] === 'update_core', 'Core runs through the actual operation registry and dispatcher');
    check($response['data']['updated_core_version'] === '6.9.2', 'Successful upgrader version is returned');
    check($upgrades[0][0] === $offers[1], 'Newest stable WordPress-resolved offer is used, ignoring caller URL/version');
    check($upgrades[0][1] === ['attempt_rollback' => true], 'Native rollback remains enabled');
    check($output === '' && !$runner->locked && $runner->releases === 1, 'Output is buffered and lock is released');
    check($response['data']['job_id'] === 'batch' && $response['data']['step'] === 3 && $response['data']['total'] === 4, 'Batch progress survives the operation');
    check($options['mcu_master_update_status']['operation'] === 'update_core' && $options['mcu_master_update_status']['status'] === 'completed', 'Core outcome is persisted for polling');
    foreach ([false, true, '6.9.1', new WP_Error('filesystem', 'Filesystem denied')] as $failed) {
        $result = $failed; $response = dispatch();
        check(!$response['success'] && !$runner->locked, 'False, malformed, obsolete and WP_Error outcomes cannot complete');
        check($options['mcu_master_update_status']['status'] === 'failed', 'Failed outcome is persisted');
    }
    $result = '6.9.2'; $mode = 'throw'; $response = dispatch();
    check(!$response['success'] && !$runner->locked && !str_contains($response['message'], 'private'), 'Exception releases lock without leaking details');
    foreach (['missing', 'stale', 'wrong-version', 'http-failure'] as $mode) {
        $before = count($upgrades); $response = dispatch();
        check(!$response['success'] && count($upgrades) === $before && !$runner->locked, 'Missing/stale/wrong-version metadata never installs: ' . $mode);
    }
    $mode = ''; $saved = $offers;
    foreach ([[], [(object) ['response' => 'upgrade', 'current' => '6.8.0']], [(object) ['response' => 'upgrade', 'current' => '7.0-beta1']]] as $offers) {
        $before = count($upgrades); $response = dispatch();
        check(!$response['success'] && count($upgrades) === $before, 'No install from empty, downgrade or prerelease offers');
    }
    $offers = [(object) ['response' => 'latest', 'current' => '6.9.0']]; $before = count($upgrades);
    $response = dispatch();
    check($response['success'] && $response['data']['already_current'] && count($upgrades) === $before, 'Fresh latest metadata produces a no-op');
    $offers = $saved; $runner->busy = true; $response = dispatch();
    check(!$response['success'] && $response['error_code'] === 'mcu_update_locked' && count($upgrades) === $before, 'Lock blocks duplicate installation');
    $runner->busy = false; Settings::$authorized = false; $response = dispatch();
    check(!$response['success'] && count($upgrades) === $before, 'Revoked authorization prevents installation');
    Settings::$authorized = true; $allowed = false; $response = dispatch();
    check(!$response['success'] && $response['error_code'] === 'file_mods_disabled' && count($upgrades) === $before, 'WordPress file policy prevents installation');
    $allowed = true; define('DISALLOW_FILE_MODS', true); $response = dispatch();
    check(!$response['success'] && count($upgrades) === $before, 'DISALLOW_FILE_MODS prevents installation');
    require __DIR__ . '/../marrison-custom-updater/includes/mcu-client/class-diagnostics-controller.php';
    require __DIR__ . '/../marrison-custom-updater/includes/mcu-client/class-rest-controller.php';
    check(in_array('update_core', \MarrisonCustomUpdater\MaintenanceClient\Diagnostics_Controller::supported_write_operations(), true), 'Core capability is advertised to Commander');
    $protocol = new \ReflectionMethod(\MarrisonCustomUpdater\MaintenanceClient\Rest_Controller::class, 'diagnostic_protocol_status');
    check(in_array('update_core', $protocol->invoke(null)['supported_write_operations'], true), 'Fallback status advertises the same core capability');
    echo "MCU direct core: $checks checks passed (real dispatcher and runner, local doubles).\n";
}
