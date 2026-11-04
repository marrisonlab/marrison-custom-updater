const assert = require('node:assert/strict');
const createQueue = require('../marrison-custom-updater/assets/js/update-queue.js');
let checks = 0;
function verify(condition, message) { assert.ok(condition, message); checks++; }

function fixture() {
    let time = 100000;
    const requests = [], timers = [], results = [], halts = [];
    const run = createQueue({
        ajax: settings => requests.push(settings),
        delay: (fn, ms) => timers.push({fn, ms}),
        now: () => time,
        url: '/admin-ajax.php', nonce: 'queue-nonce'
    });
    run({action: 'marrison_update_plugin_ajax', slug: 'jet-engine', nonce: 'original-nonce'}, {
        done: response => results.push(response), halted: message => halts.push(message)
    });
    return {
        requests, results, halts,
        advance(ms) { time += ms; },
        next() { const timer = timers.shift(); assert.ok(timer, 'Polling scheduled'); time += timer.ms; timer.fn(); },
        answer(state, locked = false, response = null) {
            requests.at(-1).success({success: true, data: {state, locked, response}});
        },
        updates() { return requests.filter(r => r.data.action !== 'mcu_admin_update_queue_status'); }
    };
}

// Reproduce the supplied log: transport ends at 58 seconds, PHP finishes at 89.
{
    const f = fixture();
    const first = f.requests[0];
    verify(first.data.nonce === 'original-nonce' && first.data.queue_nonce === 'queue-nonce', 'Both security nonces are sent');
    verify(/^[a-zA-Z0-9_-]{20,80}$/.test(first.data.mcu_request_id), 'Receipt ID accepted by server');
    f.advance(58000); first.error({status: 504});
    f.next(); f.answer('running', true);
    verify(f.results.length === 0 && f.updates().length === 1, 'No next item or duplicate install while PHP runs');
    verify(f.requests.at(-1).data.mcu_request_id === first.data.mcu_request_id, 'Poll the exact timed-out request');
    f.advance(28000); f.next(); f.answer('finished', false, {success: true, data: 'Installed'});
    verify(f.results.length === 1 && f.results[0].success && f.updates().length === 1, 'Recover successful completion without reinstalling');
}
{
    const f = fixture();
    const firstId = f.requests[0].data.mcu_request_id;
    f.requests[0].success({success: false, data: {code: 'mcu_update_locked'}});
    f.next(); f.answer('blocked', true);
    verify(f.results.length === 0 && f.updates().length === 1, 'A busy site does not fail/skip the item');
    f.next(); f.answer('blocked', false);
    verify(f.updates().length === 2 && f.requests.at(-1).data.mcu_request_id !== firstId, 'Retry only a request proven never to have started');
    f.requests.at(-1).success({success: true, data: 'Installed'});
    verify(f.results.length === 1 && f.results[0].success, 'Retried blocked item completes');
}
{
    const f = fixture();
    f.requests[0].error({status: 502});
    f.next(); f.answer('finished', false, {success: false, data: 'Download failed'});
    verify(f.results.length === 1 && !f.results[0].success && f.updates().length === 1, 'Actual server failure stays a failure and is not reinstalled');
}
{
    const f = fixture();
    f.requests[0].error({status: 0});
    f.next(); f.answer('unknown');
    for (let i = 0; i < 10; i++) { f.next(); f.answer('unknown'); }
    verify(f.halts.length === 1 && f.results.length === 0 && f.updates().length === 1, 'Missing receipt stops the queue without claiming success or blindly retrying');
}
{
    const f = fixture();
    f.requests[0].error({status: 504});
    f.next(); f.answer('finished', true, {success: true});
    verify(f.results.length === 0, 'Even a known outcome waits for the active global lock');
    f.next(); f.answer('finished', false, {success: true});
    verify(f.results.length === 1, 'Continue once the lock is free');
}
{
    const f = fixture();
    f.requests[0].error({status: 504});
    f.next(); f.requests.at(-1).error({status: 503});
    f.next(); f.answer('running', true);
    f.advance(600000); f.next();
    verify(f.halts.length === 1 && !f.results.length, 'Unreachable or stuck operations have a bounded wait and never advance the queue');
}
{
    const f = fixture();
    f.requests[0].success({success: true, data: 'OK'});
    verify(f.results.length === 1 && f.halts.length === 0, 'Normal updates complete immediately');
}
{
    const f = fixture();
    f.requests[0].error({status: 504});
    f.next(); f.requests.at(-1).error({status: 403});
    verify(f.halts.length === 1 && !f.results.length, 'Expired polling session suspends rather than skipping updates');
}
console.log(`MCU admin queue: ${checks} checks passed (virtual clock, no network).`);
