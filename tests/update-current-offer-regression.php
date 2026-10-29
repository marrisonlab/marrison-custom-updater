<?php
/** Real MCU collector and official installer method; local WP doubles only. */
$mcu = $argv[1] ?? dirname(__DIR__) . '/marrison-custom-updater';
$fixture = sys_get_temp_dir() . '/mcu-current-' . bin2hex(random_bytes(8));
mkdir($fixture . '/wp-admin/includes', 0700, true);
foreach (['plugin.php', 'file.php', 'class-wp-upgrader.php'] as $name) file_put_contents($fixture . '/wp-admin/includes/' . $name, '<?php');
define('ABSPATH', $fixture . '/');
function sanitize_key($v) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($v)); }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function __($v, $domain = '') { return $v; }
function get_option($k, $d = false) { return $d; }
function get_transient($k) { return []; }
function get_plugins() { return $GLOBALS['plugins']; }
function get_site_transient($key) { return $GLOBALS['offer']; }
function set_site_transient($key, $value) { $GLOBALS['offer'] = $value; }
function wp_update_plugins() { $GLOBALS['refreshes']++; }
function wp_clean_plugins_cache($updates = true) {}
function update_option(...$args) {}
function current_time($format) { return '2026-10-29 08:00:00'; }
function is_wp_error($value) { return $value instanceof WP_Error; }
class WP_Error { public function __construct(public $code, public $message) {} }
class Automatic_Upgrader_Skin {}
class Plugin_Upgrader {
    public function __construct($skin) {}
    public function upgrade($file) { $GLOBALS['upgrades']++; return $GLOBALS['upgradeResult']; }
}
eval('namespace MarrisonCustomUpdater\\MaintenanceClient; class Settings { public static function repository_config_managed() { return false; } }');
require $mcu . '/includes/mcu-client/class-rest-controller.php';
$source = file_get_contents($mcu . '/custom_updater.php');
$start = strpos($source, '    private function mcu_remote_update_official_plugin(');
$end = strpos($source, '    public function mcu_remote_update_theme(', $start);
$method = str_replace('private function mcu_remote_update_official_plugin', 'public function mcu_remote_update_official_plugin', substr($source, $start, $end - $start));
eval('class OfficialFixture { public $backups = 0; ' . $method . '
public function mcu_run_update_guard($operation, $context, $callback) { return $callback(); }
public function mcu_is_plugin_update_excluded(...$args) { return $GLOBALS["excluded"]; }
public function create_backup(...$args) { $this->backups++; }
public function mcu_upgrader_failure_message($skin, $upgrader, $fallback) { return $fallback; }
}');
$file = 'olympus-google-fonts/olympus-google-fonts.php';
$plugins = [$file => ['Name' => 'Fonts Plugin', 'Version' => '4.2.3']];
$excluded = false; $refreshes = 0; $upgrades = 0; $upgradeResult = true; $checks = 0;
$collector = new ReflectionMethod(MarrisonCustomUpdater\MaintenanceClient\Rest_Controller::class, 'plugin_update_items');
function verify($ok, $text) { if (!$ok) throw new RuntimeException($text); $GLOBALS['checks']++; }
try {
    foreach (['4.2.3', '4.2.2'] as $target) {
        $offer = (object) ['response' => [$file => (object) ['slug' => 'olympus-google-fonts', 'new_version' => $target]]];
        verify($collector->invoke(null, $plugins, $offer) === [], 'Installed or older official offer cannot reappear after sync');
    }
    $offer = (object) ['response' => [$file => (object) ['slug' => 'olympus-google-fonts', 'new_version' => '4.2.4']]];
    verify(count($collector->invoke(null, $plugins, $offer)) === 1, 'A real newer official offer remains visible');
    $runner = new OfficialFixture();
    foreach (['4.2.3', '4.2.2'] as $target) {
        $offer = (object) ['response' => []];
        verify($runner->mcu_remote_update_official_plugin($file, $target) === true, 'Already installed target is a successful no-op');
        verify($upgrades === 0 && $runner->backups === 0, 'No-op neither installs again nor creates a backup');
    }
    $r = $runner->mcu_remote_update_official_plugin($file, '4.2.4');
    verify(is_wp_error($r) && $r->code === 'update_not_found', 'Missing genuinely newer package remains an error');
    $r = $runner->mcu_remote_update_official_plugin($file, '');
    verify(is_wp_error($r), 'Unknown target cannot claim already-current success');
    $offer = (object) ['response' => [$file => (object) ['new_version' => '4.2.4']]];
    verify($runner->mcu_remote_update_official_plugin($file, '4.2.4') === true && $upgrades === 1, 'A real newer offer uses the actual upgrader');
    $upgradeResult = new WP_Error('download_failed', 'Download failed');
    verify(is_wp_error($runner->mcu_remote_update_official_plugin($file, '4.2.4')), 'Actual install errors remain errors');
    $excluded = true;
    $r = $runner->mcu_remote_update_official_plugin($file, '4.2.3');
    verify(is_wp_error($r) && $r->code === 'plugin_update_excluded', 'Exclusion still takes priority over already-current no-op');
    echo "MCU current offers: $checks checks passed (real collector and installer body; no network).\n";
} finally {
    foreach (['plugin.php', 'file.php', 'class-wp-upgrader.php'] as $name) unlink($fixture . '/wp-admin/includes/' . $name);
    rmdir($fixture . '/wp-admin/includes'); rmdir($fixture . '/wp-admin'); rmdir($fixture);
}
