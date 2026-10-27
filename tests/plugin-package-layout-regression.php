<?php
/** Package selection uses a virtual filesystem; no network or installed plugin. */
define('MCU_PLUGIN_FILE', '/plugins/marrison-custom-updater/custom_updater.php');
class WP_Error {
    public $code;
    public function __construct($code, $message = '') { $this->code = $code; }
}
function __($value, $domain = '') { return $value; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function trailingslashit($value) { return rtrim($value, '/') . '/'; }
function plugin_basename($value) { return 'marrison-custom-updater/custom_updater.php'; }
require __DIR__ . '/../marrison-custom-updater/includes/traits/UpdateOperationsTrait.php';
class PackageFixture {
    use MCU_Update_Operations_Trait;
    public function self_update_source($source) { return $this->resolve_self_update_source($source); }
}
class PackageFilesystem {
    public $files = [];
    public $deny_move = false;
    public function is_file($path) { return isset($this->files[$path]); }
    public function exists($path) {
        foreach ($this->files as $file => $content) if (strpos($file, $path) === 0) return true;
        return false;
    }
    public function move($source, $target) {
        if ($this->deny_move) return false;
        foreach ($this->files as $path => $content) {
            if (strpos($path, $source) !== 0) continue;
            $this->files[$target . substr($path, strlen($source))] = $content;
            unset($this->files[$path]);
        }
        return true;
    }
}
$wp_filesystem = new PackageFilesystem();
$fixture = new PackageFixture();
$extra = ['plugin' => plugin_basename(MCU_PLUGIN_FILE)];
$checks = 0;
function check($condition, $message) {
    $GLOBALS['checks']++;
    if (!$condition) throw new RuntimeException($message);
}

$wp_filesystem->files = [
    '/upgrade/repository/marrison-custom-updater/custom_updater.php' => 'plugin',
    '/upgrade/repository/marrison-custom-updater/includes/client.php' => 'runtime',
    '/upgrade/repository/README.md' => 'docs',
    '/upgrade/repository/tests/test.php' => 'test',
    '/upgrade/repository/dist/archive.zip' => 'zip',
];
check($fixture->self_update_source('/upgrade/repository') === '/upgrade/repository/marrison-custom-updater/', 'MCU self-update selects nested plugin');
$result = $fixture->select_self_update_source('/upgrade/repository/', '/upgrade/', null, $extra);
check($result === '/upgrade/marrison-custom-updater/', 'WordPress receives canonical plugin folder');
check($wp_filesystem->is_file($result . 'custom_updater.php') && $wp_filesystem->is_file($result . 'includes/client.php'), 'Runtime files move with plugin');
foreach (['README.md', 'tests/test.php', 'dist/archive.zip'] as $path) {
    check($wp_filesystem->is_file('/upgrade/repository/' . $path), 'Repository files remain outside installable plugin');
    check(!$wp_filesystem->is_file($result . $path), 'Repository files excluded from selected source');
}
check($fixture->select_self_update_source($result, '/upgrade/', null, $extra) === $result, 'Plugin-only ZIP remains compatible');
check($fixture->self_update_source($result) === $result, 'MCU self-update accepts plugin-only ZIP');

$wp_filesystem->files = ['/upgrade/legacy/custom_updater.php' => 'old layout'];
check($fixture->self_update_source('/upgrade/legacy') === '/upgrade/legacy/', 'Legacy flat package remains supported');
check($fixture->select_self_update_source('/upgrade/legacy/', '/upgrade/', null, $extra) === '/upgrade/marrison-custom-updater/', 'Legacy folder normalized');
check(is_wp_error($fixture->self_update_source('/upgrade/invalid')), 'Missing entry rejected before replacing installed plugin');
check(is_wp_error($fixture->select_self_update_source('/upgrade/invalid/', '/upgrade/', null, $extra)), 'WordPress rejects invalid MCU package');
check($fixture->select_self_update_source('/upgrade/other/', '/upgrade/', null, ['plugin' => 'other/other.php']) === '/upgrade/other/', 'Other plugin update unchanged');
check($fixture->select_self_update_source('/upgrade/other/', '/upgrade/', null) === '/upgrade/other/', 'Unrelated installer unchanged');
$error = new WP_Error('previous_error');
check($fixture->select_self_update_source($error, '/upgrade/', null, $extra) === $error, 'Previous upgrader error preserved');

$wp_filesystem->files['/upgrade/new/custom_updater.php'] = 'new';
check($fixture->select_self_update_source('/upgrade/new/', '/upgrade/', null, $extra)->code === 'mcu_update_source_move', 'Existing destination not overwritten');
unset($wp_filesystem->files['/upgrade/marrison-custom-updater/custom_updater.php']);
$wp_filesystem->deny_move = true;
check($fixture->select_self_update_source('/upgrade/new/', '/upgrade/', null, $extra)->code === 'mcu_update_source_move', 'Move failure blocks update');
check($wp_filesystem->is_file('/upgrade/new/custom_updater.php'), 'Failed move preserves source');
echo 'MCU package layout: ' . $checks . " checks passed.\n";
