<?php
/** Real MCU exclusion handler, local WordPress option doubles; no network or sites. */
define('ABSPATH', __DIR__ . '/missing-wordpress/');
$mcuRoot = $argv[1] ?? dirname(__DIR__) . '/marrison-custom-updater';
require $mcuRoot . '/includes/mcu-client/class-actions-controller.php';
require $mcuRoot . '/includes/mcu-client/class-rest-controller.php';
function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $value)); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function __($value, $domain = '') { return $value; }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { if ($GLOBALS['writeFails'] || get_option($key) === $value) return false; $GLOBALS['options'][$key] = $value; return true; }
function delete_site_transient($key) { $GLOBALS['cleared'][] = $key; }
function get_plugins() { return ['classic-editor/classic-editor.php' => ['Name' => 'Classic Editor'], 'other/other.php' => ['Name' => 'Other']]; }
function verify($ok, $label) { if (!$ok) throw new RuntimeException($label); $GLOBALS['checks']++; }
$checks = 0; $writeFails = false; $cleared = [];
$handler = new ReflectionMethod(MarrisonCustomUpdater\MaintenanceClient\Actions_Controller::class, 'set_update_exclusion');
$status = new ReflectionMethod(MarrisonCustomUpdater\MaintenanceClient\Rest_Controller::class, 'is_plugin_update_excluded');
$counter = new ReflectionMethod(MarrisonCustomUpdater\MaintenanceClient\Actions_Controller::class, 'count_matching_plugin_exclusions');
$keys = new ReflectionMethod(MarrisonCustomUpdater\MaintenanceClient\Actions_Controller::class, 'plugin_identifier_key_list');
$request = ['type' => 'plugin', 'identifier' => 'classic-editor/classic-editor.php', 'file' => 'classic-editor/classic-editor.php', 'slug' => 'classic-editor', 'name' => 'Classic Editor'];
foreach ([0, '0', false, 'false', 'off'] as $off) {
    foreach (['classic-editor/classic-editor.php', 'classic-editor', 'Classic Editor', 'classiceditor'] as $stored) {
        $options = ['marrison_excluded_plugins' => ['other', $stored]];
        $result = $handler->invoke(null, $request + ['excluded' => $off]);
        verify($result['success'] && !$result['excluded'], 'Removal accepted for false value');
        verify($options['marrison_excluded_plugins'] === ['other'], 'Requested exclusion actually removed: ' . $stored);
        verify($result['before_count'] === 2 && $result['after_count'] === 1 && $result['removed_count'] === 1 && $result['remaining_match_count'] === 0, 'Removal counts describe persisted state');
        verify(!$status->invoke(null, 'classic-editor', 'classic-editor/classic-editor.php', 'Classic Editor'), 'Next status confirms readmission');
        verify($status->invoke(null, 'other', 'other/other.php', 'Other'), 'Other plugin stays excluded');
    }
}
verify($counter->invoke(null, ['classic-editor/classic-editor.php'], $keys->invoke(null, ['classic-editor'])) === 1, 'Residual exclusion counter detects a matching map key');
$options = ['marrison_excluded_plugins' => ['other', 'classic-editor', 'Classic Editor', 'classic-editor/classic-editor.php']];
$result = $handler->invoke(null, $request + ['excluded' => 0]);
verify($options['marrison_excluded_plugins'] === ['other'] && $result['removed_count'] === 3, 'All legacy aliases removed together');
$result = $handler->invoke(null, $request + ['excluded' => 0]);
verify($result['success'] && $result['removed_count'] === 0, 'Repeated readmission is a successful no-op');
$result = $handler->invoke(null, $request + ['excluded' => 1]);
verify($result['success'] && $status->invoke(null, 'classic-editor', $request['file'], $request['name']), 'Exclusion can be enabled again');
verify($result['remaining_match_count'] === 1, 'Enabled exclusion reports persisted match');
$writeFails = true;
$result = $handler->invoke(null, $request + ['excluded' => 0]);
verify(!$result['success'] && $result['error_code'] === 'update_exclusion_not_saved', 'Failed persistence never claims readmission');
verify($result['remaining_match_count'] === 1 && $result['removed_count'] === 0, 'Failed persistence reports actual stored counts');
$writeFails = false;
$options = ['marrison_excluded_themes' => ['theme-one', 'theme-two']];
$result = $handler->invoke(null, ['type' => 'theme', 'identifier' => 'theme-one', 'slug' => 'theme-one', 'excluded' => 0]);
verify($result['success'] && $options['marrison_excluded_themes'] === ['theme-two'], 'Theme removal remains supported');
$writeFails = true;
$result = $handler->invoke(null, ['type' => 'theme', 'identifier' => 'theme-two', 'slug' => 'theme-two', 'excluded' => 0]);
verify(!$result['success'], 'Theme persistence failure is explicit');
verify(in_array('update_plugins', $cleared, true) && in_array('update_themes', $cleared, true), 'Update caches invalidated');
echo "MCU exclusions: $checks checks passed (real action and status methods, local options).\n";
