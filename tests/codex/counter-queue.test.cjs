const test = require('node:test');
const assert = require('node:assert/strict');
const { createCounterQueue, counterMinor, syncCounterQueue } = require('../modules/pos/src/counter.js');

function storage() {
    const data = new Map();
    return {
        get length() { return data.size; },
        key(index) { return [...data.keys()][index]; },
        setItem(key, value) { data.set(key, value); },
        getItem(key) { return data.get(key) ?? null; },
        removeItem(key) { data.delete(key); },
    };
}

test('queued carts survive reload and never cross account keys', () => {
    const disk = storage();
    createCounterQueue(disk, 'identity-a:').save({ key: 'key', intent: { session: 8 }, created: 1 });
    assert.deepEqual(createCounterQueue(disk, 'identity-a:').entries()[0].intent, { session: 8 });
    assert.deepEqual(createCounterQueue(disk, 'identity-b:').entries(), []);
});

test('a lost response keeps the exact request for an idempotent retry', async () => {
    const queue = createCounterQueue(storage(), 'identity:');
    const entry = { key: 'stable', intent: { session: 8, lines: [{ product: 1, quantity: 2 }] }, created: 1 };
    queue.save(entry);
    await assert.rejects(syncCounterQueue(queue, async () => { throw new Error('offline'); }, () => {}));
    assert.deepEqual(queue.entries(), [entry]);
    const accepted = [];
    await syncCounterQueue(queue, async (intent, key) => {
        assert.deepEqual(intent, entry.intent);
        assert.equal(key, 'stable');
        return { order_id: 12, number: 'R1-000001' };
    }, receipt => accepted.push(receipt.number));
    assert.deepEqual(accepted, ['R1-000001']);
    assert.deepEqual(queue.entries(), []);
});

test('a rejected or malformed response keeps the queue and prevents out-of-order replay', async () => {
    const queue = createCounterQueue(storage(), 'identity:');
    queue.save({ key: 'second', intent: { session: 2 }, created: 10 });
    queue.save({ key: 'first', intent: { session: 2 }, created: 1 });
    const attempted = [];
    await assert.rejects(syncCounterQueue(queue, async (_, key) => { attempted.push(key); return {}; }, () => {}));
    assert.deepEqual(attempted, ['first']);
    assert.deepEqual(queue.entries().map(entry => entry.key), ['first', 'second']);
});

test('corrupt persisted entries fail closed', () => {
    const disk = storage();
    disk.setItem('identity:bad', '{}');
    assert.throws(() => createCounterQueue(disk, 'identity:').entries());
});

test('currency input preserves minor units and rejects malformed or excessive inputs', () => {
    assert.equal(counterMinor('12.09'), 1209);
    assert.equal(counterMinor('0.1'), 10);
    for (const bad of ['-1', '1e2', '1.002', '10000000.01']) assert.throws(() => counterMinor(bad));
});
