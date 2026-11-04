<?php

trait MCU_Admin_Update_Queue_Trait {
    private $mcu_admin_queue_request = '';
    private $mcu_admin_queue_blocked = false;

    private function mcu_admin_queue_receipts() {
        $receipts = get_option('mcu_admin_update_receipts_' . get_current_user_id(), []);
        return is_array($receipts) ? $receipts : [];
    }

    private function mcu_save_admin_queue_receipt($id, $receipt) {
        // Options survive wp_cache_flush(), including with a persistent object cache.
        $receipts = $this->mcu_admin_queue_receipts();
        foreach ($receipts as $key => $value) {
            if ((int) ($value['expires'] ?? 0) <= time()) {
                unset($receipts[$key]);
            }
        }
        unset($receipts[$id]);
        $receipt['expires'] = time() + 86400;
        $receipts[$id] = $receipt;
        $receipts = array_slice($receipts, -100, null, true);
        update_option('mcu_admin_update_receipts_' . get_current_user_id(), $receipts, false);
        $saved = $this->mcu_admin_queue_receipts();
        return isset($saved[$id]) && $saved[$id] === $receipt;
    }

    private function mcu_admin_queue_locked() {
        $lock = get_transient('marrison_update_lock');
        // A status query never releases a lock or starts an update.
        return is_array($lock) && (int) ($lock['expires'] ?? 0) > time();
    }

    private function mcu_admin_queue_id() {
        $id = $_POST['mcu_request_id'] ?? '';
        return is_string($id) && preg_match('/^[a-zA-Z0-9_-]{20,80}$/D', $id) ? $id : '';
    }

    public function admin_update_queue_status_ajax() {
        if (!current_user_can('manage_options') || !check_ajax_referer('mcu_admin_update_queue', 'queue_nonce', false)) {
            wp_send_json_error('Verifica della sessione non riuscita. Ricarica la pagina.', 403);
        }
        $id = $this->mcu_admin_queue_id();
        if ($id === '') {
            wp_send_json_error('Identificativo aggiornamento non valido.', 400);
        }
        $receipts = $this->mcu_admin_queue_receipts();
        $receipt = $receipts[$id] ?? [];
        if ((int) ($receipt['expires'] ?? 0) <= time()) {
            $receipt = [];
        }
        wp_send_json_success([
            'state' => $receipt['state'] ?? 'unknown',
            'response' => $receipt['response'] ?? null,
            'locked' => $this->mcu_admin_queue_locked(),
        ]);
    }

    public function tracked_admin_update_ajax() {
        $handlers = [
            'marrison_update_plugin_ajax' => 'update_plugin_ajax',
            'marrison_update_official_plugin_ajax' => 'update_official_plugin_ajax',
            'marrison_update_private_theme_ajax' => 'update_private_theme_ajax',
            'marrison_update_all_themes_ajax' => 'update_all_themes_ajax',
            'marrison_update_translations_ajax' => 'update_translations_ajax',
        ];
        $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
        if (!isset($handlers[$action])) {
            wp_send_json_error('Operazione non valida.', 400);
        }
        // Existing single-update and integration callers keep their original checks.
        if (!isset($_POST['mcu_request_id'])) {
            return $this->{$handlers[$action]}();
        }
        if (!current_user_can('manage_options') || !check_ajax_referer('mcu_admin_update_queue', 'queue_nonce', false)) {
            wp_send_json_error('Verifica della sessione non riuscita. Ricarica la pagina.', 403);
        }
        $id = $this->mcu_admin_queue_id();
        if ($id === '') {
            wp_send_json_error('Identificativo aggiornamento non valido.', 400);
        }
        $receipts = $this->mcu_admin_queue_receipts();
        $previous = $receipts[$id] ?? [];
        if ((int) ($previous['expires'] ?? 0) > time()) {
            if ($previous['state'] === 'finished') {
                wp_send_json($previous['response']);
            }
            wp_send_json_error(['code' => 'mcu_update_pending']);
        }
        $this->mcu_admin_queue_request = $id;
        if ($this->mcu_admin_queue_locked()) {
            $this->mcu_save_admin_queue_receipt($id, ['state' => 'blocked']);
            wp_send_json_error(['code' => 'mcu_update_locked']);
        }
        if (!$this->mcu_save_admin_queue_receipt($id, ['state' => 'running'])) {
            wp_send_json_error('Impossibile registrare lo stato aggiornamento. Coda sospesa.', 503);
        }
        // The request can finish even when a proxy/browser stops waiting.
        @ignore_user_abort(true);
        @set_time_limit(0);
        return $this->{$handlers[$action]}();
    }

    private function mcu_send_admin_update_json($success, $data) {
        if ($this->mcu_admin_queue_request !== '' && $this->mcu_admin_queue_blocked) {
            // Also cover a lock acquired by another request after our initial check.
            $this->mcu_save_admin_queue_receipt($this->mcu_admin_queue_request, ['state' => 'blocked']);
            wp_send_json_error(['code' => 'mcu_update_locked']);
        }
        $response = ['success' => (bool) $success, 'data' => $data];
        if ($this->mcu_admin_queue_request !== '') {
            $this->mcu_save_admin_queue_receipt($this->mcu_admin_queue_request, [
                'state' => 'finished',
                'response' => $response,
            ]);
        }
        wp_send_json($response);
    }
}
