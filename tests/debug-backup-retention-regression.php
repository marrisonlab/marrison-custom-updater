<?php
/** Real temporary files and WordPress doubles; no live configuration or network. */
namespace MarrisonCustomUpdater\MaintenanceClient {
    function gmdate($format, $timestamp = null) {
        return \gmdate($format, $GLOBALS['backup_time'] + ($timestamp - time()));
    }
    function unlink($path) {
        if (!empty($GLOBALS['deny_delete'])) return false;
        return \unlink($path);
    }
    function scandir($path) {
        $GLOBALS['scans']++;
        return !empty($GLOBALS['deny_scan']) ? false : \scandir($path);
    }
    function copy($source, $target) {
        if (!empty($GLOBALS['partial_copy'])) { \file_put_contents($target, 'incomplete'); return false; }
        if (!empty($GLOBALS['deny_copy'])) return false;
        $result = \copy($source, $target);
        if (!empty($GLOBALS['deny_delete_after_copy'])) $GLOBALS['deny_delete'] = true;
        return $result;
    }
    function file_put_contents($path, $content, $flags = 0) {
        return !empty($GLOBALS['deny_write']) ? false : \file_put_contents($path, $content, $flags);
    }
}
namespace {
    use MarrisonCustomUpdater\MaintenanceClient\Debug_Manager;

    $fixture = str_replace('\\', '/', sys_get_temp_dir()) . '/mcu-debug-' . bin2hex(random_bytes(8));
    mkdir($fixture);
    mkdir($fixture . '/wordpress');
    define('ABSPATH', $fixture . '/wordpress/');
    define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
    $options = []; $scans = 0; $checks = 0;
    $backup_time = strtotime('2026-10-26 12:00:00 UTC');
    function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
    function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; return true; }
    function trailingslashit($value) { return rtrim($value, '/\\') . '/'; }
    function __($value, $domain = '') { return $value; }
    function check($value, $message) {
        $GLOBALS['checks']++;
        if (!$value) throw new \RuntimeException($message);
    }
    function backups($config) {
        return array_values(array_filter(glob($config . '.*-debug-backup-*'), function ($path) {
            return is_file($path) && preg_match('/\.(?:mcu|marrison)-debug-backup-\d{14}$/', $path);
        }));
    }
    require __DIR__ . '/../marrison-custom-updater/includes/mcu-client/class-debug-manager.php';

    // Locate wp-config.php above ABSPATH, just as supported in WordPress hosting.
    $config = $fixture . '/wp-config.php';
    $original = "<?php\ndefine( 'DB_NAME', 'fixture' );\ndefine( 'WP_DEBUG', false );\n/* That's all, stop editing! Happy publishing. */\n";
    file_put_contents($config, $original);
    $dates = ['20260916125853', '20260930110555', '20260930110610', '20261016073546', '20261022134829'];
    foreach ($dates as $date) file_put_contents($config . '.mcu-debug-backup-' . $date, 'historical-' . $date);
    $unrelated = [$config . '.bak', $config . '.mcu-debug-backup-manual', $fixture . '/other.php.mcu-debug-backup-20260916125853'];
    foreach ($unrelated as $path) file_put_contents($path, 'untouched');
    mkdir($config . '.mcu-debug-backup-20260101000000');

    try {
        Debug_Manager::maybe_cleanup_config_backups();
        $expected = $config . '.mcu-debug-backup-' . end($dates);
        check(backups($config) === [$expected], 'Upgrade removes the four older screenshot backups');
        check(file_get_contents($expected) === 'historical-' . end($dates), 'Newest historical backup preserved byte for byte');
        check(file_get_contents($config) === $original, 'Migration never writes wp-config.php');
        check(get_option(Debug_Manager::OPTION_LAST_CONFIG_BACKUP) === $expected, 'Stored backup points to retained file');
        foreach ($unrelated as $path) check(file_get_contents($path) === 'untouched', 'Unrelated file preserved');
        check(is_dir($config . '.mcu-debug-backup-20260101000000'), 'Matching directory preserved');
        $before = $scans;
        Debug_Manager::maybe_cleanup_config_backups();
        check($scans === $before, 'Successful migration does not rescan on subsequent requests');

        foreach ([true, true, false, false, true, false] as $enabled) {
            // A legacy copy can also appear after migration.
            file_put_contents($config . '.marrison-debug-backup-20260101000001', 'legacy');
            $previous = file_get_contents($config);
            $result = Debug_Manager::set_enabled($enabled);
            check(!empty($result['success']), 'Toggle succeeds');
            $remaining = backups($config);
            check(count($remaining) === 1, 'Exactly one backup after enable, disable or repeated state');
            check(file_get_contents($remaining[0]) === $previous, 'Backup contains configuration before this toggle');
            check($result['backup_cleanup']['kept'] === $remaining[0], 'Response identifies actual retained file');
            $content = file_get_contents($config);
            check(strpos($content, "define( 'WP_DEBUG', " . ($enabled ? 'true' : 'false') . ' );') !== false, 'Requested debug state written');
        }

        $previous = file_get_contents($config);
        $deny_write = true;
        $result = Debug_Manager::set_enabled(true);
        check($result['error_code'] === 'wp_config_write_failed', 'Write failure reported');
        check(count(backups($config)) === 1, 'Write failure still leaves only one backup');
        check(file_get_contents(backups($config)[0]) === $previous, 'Recovery copy survives write failure');
        $deny_write = false;

        $deny_copy = true;
        $result = Debug_Manager::set_enabled(true);
        check($result['error_code'] === 'wp_config_backup_failed', 'Backup failure reported');
        check(count(backups($config)) === 1 && file_get_contents($config) === $previous, 'Backup failure preserves config and existing backup');
        $deny_copy = false;

        $partial_copy = true;
        $kept = backups($config)[0];
        $result = Debug_Manager::set_enabled(true);
        check($result['error_code'] === 'wp_config_backup_failed', 'Partial copy failure reported');
        check(backups($config) === [$kept] && file_get_contents($kept) === $previous, 'Partial copy removed without overwriting existing backup in the same second');
        $partial_copy = false;

        file_put_contents($config . '.mcu-debug-backup-20260101000002', 'old');
        $deny_delete = true;
        $result = Debug_Manager::set_enabled(true);
        check($result['error_code'] === 'wp_config_backup_cleanup_failed', 'Deletion failure reported explicitly');
        check(file_get_contents($config) === $previous && count(backups($config)) === 2, 'Deletion failure stops changes and creates no extra backup');
        Debug_Manager::set_enabled(true);
        check(count(backups($config)) === 2, 'Repeated permission failure cannot accumulate new copies');

        unset($options[Debug_Manager::OPTION_BACKUP_CLEANUP]);
        Debug_Manager::maybe_cleanup_config_backups();
        check(empty($options[Debug_Manager::OPTION_BACKUP_CLEANUP]['completed']), 'Failed migration is not marked complete');
        $before = $scans;
        Debug_Manager::maybe_cleanup_config_backups();
        check($scans === $before, 'Migration failure has a retry delay');
        $deny_delete = false;
        $options[Debug_Manager::OPTION_BACKUP_CLEANUP]['retry_after'] = 0;
        Debug_Manager::maybe_cleanup_config_backups();
        check(count(backups($config)) === 1 && $options[Debug_Manager::OPTION_BACKUP_CLEANUP]['completed'], 'Migration retries successfully after permissions recover');

        $deny_delete_after_copy = true;
        $result = Debug_Manager::set_enabled(true);
        check($result['error_code'] === 'wp_config_backup_cleanup_failed', 'Failure removing previous backup after copy is reported');
        check(file_get_contents($config) === $previous, 'Post-copy cleanup failure cancels config modification');
        $deny_delete_after_copy = false; $deny_delete = false;
        check(Debug_Manager::set_enabled(true)['success'] && count(backups($config)) === 1, 'Next successful operation restores one backup');

        $deny_scan = true;
        $result = Debug_Manager::set_enabled(false);
        check($result['error_code'] === 'wp_config_backup_cleanup_failed', 'Directory scan failure blocks backup creation');
        check(count(backups($config)) === 1, 'Scan failure leaves existing backup alone');
        $deny_scan = false;

        // A site without backups must not create one during the upgrade cleanup.
        foreach (backups($config) as $path) unlink($path);
        unset($options[Debug_Manager::OPTION_BACKUP_CLEANUP]);
        Debug_Manager::maybe_cleanup_config_backups();
        check(count(backups($config)) === 0, 'Migration does not create a backup unnecessarily');
        check(Debug_Manager::set_enabled(false)['success'] && count(backups($config)) === 1, 'First debug command creates one backup');
        echo 'MCU debug retention: ' . $checks . " checks passed.\n";
    } finally {
        // Only direct children of this randomly created fixture are removed.
        foreach (glob($fixture . '/*') as $path) {
            if (is_dir($path)) rmdir($path); else unlink($path);
        }
        rmdir($fixture);
    }
}
