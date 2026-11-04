<?php
/** Real queue dispatcher/status/receipt methods with local WordPress doubles. */
require dirname(__DIR__) . '/marrison-custom-updater/includes/traits/AdminUpdateQueueTrait.php';
$options = []; $lock = false; $user = 1; $allowed = true; $nonce_valid = true; $save_allowed = true; $checks = 0;
function get_current_user_id() { return $GLOBALS['user']; }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) {
    if (!$GLOBALS['save_allowed']) return false;
    if ($autoload !== false) throw new RuntimeException('Receipts must not autoload');
    $GLOBALS['options'][$key] = $value;
    return true;
}
function get_transient($key) { return $GLOBALS['lock']; }
function current_user_can($cap) { return $GLOBALS['allowed']; }
function check_ajax_referer(...$args) { return $GLOBALS['nonce_valid']; }
class JsonResponse extends Exception {
    public $response; public $status;
    public function __construct($response, $status) { $this->response = $response; $this->status = $status; }
}
function wp_send_json($response, $status = 200) { throw new JsonResponse($response, $status); }
function wp_send_json_success($data, $status = 200) { wp_send_json(['success' => true, 'data' => $data], $status); }
function wp_send_json_error($data, $status = 200) { wp_send_json(['success' => false, 'data' => $data], $status); }
class QueueFixture {
    use MCU_Admin_Update_Queue_Trait;
    public $calls = 0;
    public $mode = 'success';
    public function update_plugin_ajax() {
        $this->calls++;
        if ($this->mode === 'running') return;
        if ($this->mode === 'race') $this->mcu_admin_queue_blocked = true;
        $this->mcu_send_admin_update_json($this->mode === 'success', $this->mode === 'success' ? 'Installed' : 'Download failed');
    }
}
function verify($ok, $message) { if (!$ok) throw new RuntimeException($message); $GLOBALS['checks']++; }
function response($fixture, $method = 'tracked_admin_update_ajax') {
    try { $fixture->$method(); } catch (JsonResponse $json) { return $json; }
    return null;
}
function request($id) {
    $_POST = ['action' => 'marrison_update_plugin_ajax', 'mcu_request_id' => $id, 'queue_nonce' => 'valid', 'nonce' => 'original'];
}
function status($id) {
    request($id);
    return response(new QueueFixture(), 'admin_update_queue_status_ajax')->response['data'];
}

$id = 'mcu_test_00000000000000001';
request($id); $f = new QueueFixture(); $r = response($f);
verify($r->response['success'] && $f->calls === 1, 'Original handler executes once');
verify(status($id)['state'] === 'finished' && status($id)['response']['success'], 'Full success persists for a fresh polling request');
request($id); $duplicate = new QueueFixture(); $r = response($duplicate);
verify($r->response['success'] && $duplicate->calls === 0, 'Duplicate completed request returns its receipt without installing again');
$user = 2;
verify(status($id)['state'] === 'unknown', 'Receipts are isolated by user');
$user = 1;

$busy = 'mcu_test_00000000000000002'; $lock = ['expires' => time() + 600, 'token' => 'private'];
request($busy); $f = new QueueFixture(); $r = response($f);
verify($f->calls === 0 && $r->response['data']['code'] === 'mcu_update_locked', 'Busy lock prevents starting a second handler');
$s = status($busy);
verify($s['state'] === 'blocked' && $s['locked'] && !isset($s['token']), 'Status identifies safe-to-retry blocked request without exposing lock secrets');
verify($lock['token'] === 'private', 'Polling does not clear the update lock');
$lock = false;
verify(!status($busy)['locked'], 'Lock release is visible to the queue');

$running = 'mcu_test_00000000000000003';
request($running); $f = new QueueFixture(); $f->mode = 'running'; response($f);
verify(status($running)['state'] === 'running', 'Receipt exists before slow handler work starts');
request($running); $f = new QueueFixture(); $r = response($f);
verify($f->calls === 0 && $r->response['data']['code'] === 'mcu_update_pending', 'Duplicate in-flight request never starts another installation');

$failed = 'mcu_test_00000000000000004';
request($failed); $f = new QueueFixture(); $f->mode = 'failure'; response($f);
verify(status($failed)['response']['success'] === false, 'A real installer error persists as failure');

$race = 'mcu_test_00000000000000005';
request($race); $f = new QueueFixture(); $f->mode = 'race'; $r = response($f);
verify($r->response['data']['code'] === 'mcu_update_locked' && status($race)['state'] === 'blocked', 'A lock collision after the initial check is also retryable');

$allowed = false;
request('mcu_test_00000000000000006'); $f = new QueueFixture(); $r = response($f);
verify($r->status === 403 && $f->calls === 0, 'Permission failure never starts updates');
verify(response(new QueueFixture(), 'admin_update_queue_status_ajax')->status === 403, 'Status also requires admin permission');
$allowed = true; $nonce_valid = false;
verify(response(new QueueFixture())->status === 403, 'Tracking nonce is mandatory');
verify(response(new QueueFixture(), 'admin_update_queue_status_ajax')->status === 403, 'Status nonce is mandatory');
$nonce_valid = true;
request('../invalid');
verify(response(new QueueFixture())->status === 400, 'Invalid receipt identifier rejected');
verify(response(new QueueFixture(), 'admin_update_queue_status_ajax')->status === 400, 'Invalid status identifier rejected');

request('mcu_test_00000000000000007'); $save_allowed = false; $f = new QueueFixture(); $r = response($f);
verify($r->status === 503 && $f->calls === 0, 'No installation starts if receipt persistence fails');
$save_allowed = true;

$_POST = ['action' => 'marrison_update_plugin_ajax', 'nonce' => 'original']; $f = new QueueFixture(); $r = response($f);
verify($r->response['success'] && $f->calls === 1, 'Legacy single-update callers remain supported');

$options['mcu_admin_update_receipts_1'][$id]['expires'] = time() - 1;
verify(status($id)['state'] === 'unknown', 'Expired receipt cannot claim success');
for ($i = 10; $i < 120; $i++) {
    request('mcu_retention_' . str_pad((string) $i, 12, '0', STR_PAD_LEFT));
    response(new QueueFixture());
}
verify(count($options['mcu_admin_update_receipts_1']) === 100, 'Receipt history is bounded and does not autoload');
echo "MCU admin receipts: $checks checks passed (real trait, no network).\n";
