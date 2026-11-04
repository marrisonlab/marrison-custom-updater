(function(root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory;
    } else {
        root.MCUUpdateQueue = factory;
    }
}(typeof window !== 'undefined' ? window : this, function(options) {
    var ajax = options.ajax;
    var delay = options.delay || setTimeout;
    var now = options.now || Date.now;
    var sequence = 0;

    return function(data, callbacks) {
        var deadline = now() + 10 * 60 * 1000;
        var unknownSince = null;
        var requestId;
        var retries = 0;
        var finished = false;

        function halt() {
            if (finished) return;
            finished = true;
            callbacks.halted('Coda sospesa: impossibile confermare la conclusione dell’aggiornamento. Controlla i log e ricarica la pagina prima di riprovare.');
        }

        function done(response) {
            if (finished) return;
            finished = true;
            callbacks.done(response);
        }

        function wait() {
            if (finished) return;
            if (now() >= deadline) return halt();
            if (callbacks.waiting) callbacks.waiting();
            delay(poll, 3000);
        }

        function poll() {
            if (finished) return;
            if (now() >= deadline) return halt();
            ajax({
                url: options.url,
                type: 'POST',
                dataType: 'json',
                timeout: 15000,
                data: {
                    action: 'mcu_admin_update_queue_status',
                    queue_nonce: options.nonce,
                    mcu_request_id: requestId
                },
                success: function(response) {
                    if (!response || !response.success || !response.data) return halt();
                    var state = response.data;
                    if (state.locked) return wait();
                    if (state.state === 'finished' && state.response && typeof state.response.success === 'boolean') {
                        return done(state.response);
                    }
                    if (state.state === 'blocked') {
                        // A blocked request never started; it is safe to try this item again.
                        if (++retries > 5) return halt();
                        return dispatch();
                    }
                    if (state.state === 'unknown') {
                        if (unknownSince === null) unknownSince = now();
                        if (now() - unknownSince >= 30000) return halt();
                    } else {
                        unknownSince = null;
                    }
                    wait();
                },
                error: function(xhr) {
                    if (xhr && (xhr.status === 401 || xhr.status === 403)) return halt();
                    wait();
                }
            });
        }

        function dispatch() {
            if (finished) return;
            if (now() >= deadline) return halt();
            requestId = 'mcu_' + now().toString(36) + '_' + (++sequence) + '_' + Math.random().toString(36).slice(2, 14);
            unknownSince = null;
            ajax({
                url: options.url,
                type: 'POST',
                dataType: 'json',
                timeout: 120000,
                data: Object.assign({}, data, {mcu_request_id: requestId, queue_nonce: options.nonce}),
                success: function(response) {
                    var code = response && response.data && response.data.code;
                    if (code === 'mcu_update_locked' || code === 'mcu_update_pending') return wait();
                    if (!response || typeof response.success !== 'boolean') return wait();
                    done(response);
                },
                // A transport error does not establish whether PHP finished the update.
                error: wait
            });
        }

        dispatch();
    };
}));
