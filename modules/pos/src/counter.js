'use strict';

function createCounterQueue(storage, prefix) {
    const key = id => prefix + id;
    return {
        save(entry) { storage.setItem(key(entry.key), JSON.stringify(entry)); },
        remove(id) { storage.removeItem(key(id)); },
        entries() {
            const entries = [];
            for (let index = 0; index < storage.length; index++) {
                const storedKey = storage.key(index);
                if (!storedKey?.startsWith(prefix)) continue;
                const raw = storage.getItem(storedKey);
                if (raw === null) continue;
                const entry = JSON.parse(raw);
                if (key(entry.key) !== storedKey || !entry.intent || !Number.isSafeInteger(entry.created)) {
                    throw new Error('Invalid stored sale');
                }
                entries.push(entry);
            }
            return entries.sort((first, second) => first.created - second.created || first.key.localeCompare(second.key));
        },
    };
}

function counterMinor(value) {
    if (!/^\d{1,8}(\.\d{1,2})?$/.test(value)) throw new Error('Invalid amount');
    const [major, fraction = ''] = value.split('.');
    const minor = Number(major) * 100 + Number(fraction.padEnd(2, '0'));
    if (minor > 1000000000) throw new Error('Invalid amount');
    return minor;
}

async function syncCounterQueue(queue, send, accepted) {
    for (const entry of queue.entries()) {
        const receipt = await send(entry.intent, entry.key);
        const valid = entry.intent.action === 'member_due_payment'
            ? receipt.kind === 'DUE_PAYMENT' && Number.isSafeInteger(receipt.payment_id) && receipt.payment_id > 0
            : Number.isSafeInteger(receipt.order_id) && receipt.order_id > 0 && typeof receipt.number === 'string';
        if (!valid) throw new Error('Invalid receipt');
        queue.remove(entry.key);
        accepted(receipt, entry.intent);
    }
}

if (typeof module !== 'undefined') module.exports = { createCounterQueue, counterMinor, syncCounterQueue };
if (typeof document !== 'undefined') initializeCounter();

function initializeCounter() {
    const form = document.getElementById('sfm4u-counter');
    if (!form || typeof sfm4uCounter === 'undefined') return;
    const config = sfm4uCounter;
    const labels = config.labels;
    const el = id => document.getElementById('sfc-' + id);
    const context = () => {
        const [merchant, register] = el('register').value.split(':');
        if (!merchant || !register) throw new Error(labels.open);
        return { merchant: Number(merchant), register };
    };
    const money = minor => '৳' + (minor / 100).toFixed(2);
    const cart = new Map();
    let queue;
    let quote;
    let session = 0;
    let busy = false;
    let dueScope;
    const announce = message => { el('status').textContent = message; };
    const updateConfirm = () => { el('confirm').disabled = !queue || !quote || !session || busy; };
    const invalidate = () => {
        quote = null;
        el('total').textContent = '';
        updateConfirm();
    };

    function showQueue() {
        try {
            queue ??= createCounterQueue(window.localStorage, config.storage);
            const entries = queue.entries();
            el('queue').replaceChildren(...entries.map(entry => {
                const item = document.createElement('li');
                item.textContent = entry.intent.register + ' · ' + money(entry.total) + ' · ' + entry.key;
                return item;
            }));
            el('sync').disabled = !entries.length || busy;
            return entries;
        } catch {
            queue = null;
            el('sync').disabled = true;
            announce(labels.storage);
            return [];
        }
    }

    async function send(intent, key) {
        const response = await fetch(config.url, {
            method: 'POST', credentials: 'same-origin', cache: 'no-store',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce, 'Idempotency-Key': key ?? '' },
            body: JSON.stringify(intent), signal: AbortSignal.timeout(20000),
        });
        if (!response.ok) {
            let text = response.status < 500 ? labels.rejected : labels.failed;
            try { const body = await response.json(); if (body && body.code === 'counter_rejected' && body.message) text = body.message; } catch (e) { /* keep generic */ }
            throw Object.assign(new Error(text), { status: response.status });
        }
        return response.json();
    }

    function receipt(data, intent) {
        if (data.kind === 'DUE_PAYMENT') {
            const { merchant, register, member } = intent;
            if (JSON.stringify({ merchant, register, member }) === dueScope) {
                el('due').textContent = labels.due + ' ' + money(data.due_minor) + ' · ' + labels.advance + ' ' + money(data.advance_minor);
            }
            announce(labels.dueSaved + ' #' + data.payment_id);
            el('due-only').value = '0.00';
            return;
        }
        el('order').value = data.order_id;
        announce(labels.saved + ' ' + labels.receipt + ' ' + data.number);
    }

    async function perform(task) {
        if (busy) return;
        busy = true;
        updateConfirm();
        try {
            await task();
        } catch (failure) {
            const pending = showQueue().length > 0;
            announce(pending ? (failure.status > 0 && failure.status < 500 ? labels.rejected : labels.queued)
                : failure.status ? failure.message : labels.failed);
        } finally {
            busy = false;
            showQueue();
            updateConfirm();
        }
    }

    function drawCart() {
        el('cart').replaceChildren(...[...cart.values()].map(item => {
            const row = document.createElement('li');
            const quantity = document.createElement('input');
            quantity.type = 'number';
            quantity.min = '1';
            quantity.max = '10000';
            quantity.step = '1';
            quantity.value = item.quantity;
            quantity.setAttribute('aria-label', labels.quantity + ' ' + item.name);
            quantity.addEventListener('input', () => { item.quantity = quantity.valueAsNumber; invalidate(); });
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'button';
            remove.textContent = labels.remove;
            remove.addEventListener('click', () => { cart.delete(item.product); drawCart(); invalidate(); });
            row.append(document.createTextNode(item.name), quantity, remove);
            return row;
        }));
    }

    function add(row) {
        const prior = cart.get(row.product);
        if ((!prior && cart.size >= 30) || (prior?.quantity ?? 0) + row.quantity > 10000) {
            announce(labels.invalid);
            return;
        }
        cart.set(row.product, { ...row, quantity: (prior?.quantity || 0) + row.quantity });
        drawCart();
        invalidate();
        el('term').value = '';
        el('term').focus();
    }

    function payload() {
        const lines = [...cart.values()].map(({ product, quantity }) => ({ product, quantity }));
        if (!lines.length || lines.some(({ quantity }) => !Number.isSafeInteger(quantity) || quantity < 1 || quantity > 10000)) {
            throw new Error(labels.empty);
        }
        const tier = el('tier').value;
        const member = tier === 'member' ? el('member').value.trim() : '';
        if (tier === 'member' && !member) throw new Error(labels.scanCard || labels.invalid);
        return { ...context(), lines, tier, method: el('method').value, discount: counterMinor(el('discount').value), ...(member ? { member } : {}) };
    }

    function showDrawer(data) {
        const fields = { date: 'date', cash_in_minor: 'in', cash_out_minor: 'out', number: 'receipt', session_id: 'session', opening_minor: 'opening', cash_sales_minor: 'sales',
            cash_returns_minor: 'returns', expected_cash_minor: 'expected', counted_minor: 'counted', variance_minor: 'variance' };
        el('summary').replaceChildren(...Object.entries(fields).flatMap(([key, label]) => {
            if (!Object.hasOwn(data, key)) return [];
            const term = document.createElement('dt');
            const value = document.createElement('dd');
            term.textContent = labels[label];
            value.textContent = key.endsWith('_minor') ? money(data[key]) : String(data[key]);
            return [term, value];
        }));
    }

    function drawerStatus() {
        if (!el('register').value || !navigator.onLine) return;
        void perform(async () => {
            const current = context();
            const state = await send({ ...current, action: 'drawer', drawer_action: 'status', session: 0, amount: 0 });
            if (current.register !== context().register || current.merchant !== context().merchant) return;
            session = state.session_id;
            showDrawer(state.last_z ?? state);
        });
    }

    function resetDue() {
        dueScope = null;
        el('khata').hidden = true;
        el('khata-sale').hidden = true;
        el('due').textContent = '';
        el('history').replaceChildren();
        el('cash-due').textContent = '';
        for (const id of ['due-only', 'due-payment', 'credit']) el(id).value = '0.00';
    }

    function memberContext() {
        return { ...context(), member: el('member').value.trim() };
    }

    async function readDue() {
        const scope = memberContext();
        if (!scope.member || el('tier').value !== 'member') return;
        const reply = await send({ ...scope, action: 'member_due' });
        if (JSON.stringify(scope) !== JSON.stringify(memberContext()) || el('tier').value !== 'member') return;
        dueScope = JSON.stringify(scope);
        el('khata').hidden = false;
        el('khata-sale').hidden = el('method').value !== 'cash';
        el('due').textContent = labels.due + ' ' + money(reply.due_minor) + ' · ' + labels.advance + ' ' + money(reply.advance_minor);
    }

    function khataAmounts() {
        if (!dueScope || el('khata-sale').hidden) return { due_payment_minor: 0, credit_minor: 0 };
        return { due_payment_minor: counterMinor(el('due-payment').value), credit_minor: counterMinor(el('credit').value) };
    }

    function refreshTender() {
        if (!quote) return;
        try {
            const parts = khataAmounts();
            const cash = quote.total + parts.due_payment_minor - parts.credit_minor;
            if (cash < 0 || parts.credit_minor > quote.total) throw new Error();
            el('cash-due').textContent = labels.cashDue + ' ' + money(cash);
            el('tender').value = (cash / 100).toFixed(2);
        } catch {
            el('cash-due').textContent = labels.invalid;
        }
    }

    for (const id of ['due-payment', 'credit']) el(id).addEventListener('input', refreshTender);
    el('due-history').addEventListener('click', () => {
        if (!dueScope) return;
        void perform(async () => {
            const scope = memberContext();
            const result = await send({ ...scope, action: 'member_due_history' });
            if (JSON.stringify(scope) !== dueScope) return;
            el('history').replaceChildren(...result.days.map(day => {
                const row = document.createElement('li');
                row.textContent = day.date + ' · ' + money(day.charged_minor) + ' − ' + money(day.paid_minor)
                    + ' · ' + money(day.closing_minor);
                return row;
            }));
        });
    });

    el('pay-due').addEventListener('click', () => {
        if (busy || !dueScope || !session || !queue || !navigator.onLine) return announce(labels.open);
        let amount;
        try {
            amount = counterMinor(el('due-only').value);
            if (!amount || JSON.stringify(memberContext()) !== dueScope) throw new Error();
            queue.save({ intent: { ...memberContext(), action: 'member_due_payment', session, amount },
                key: window.crypto.randomUUID(), created: Date.now(), total: amount });
        } catch {
            return announce(labels.invalid);
        }
        el('due-only').value = '0.00';
        showQueue();
        void perform(() => syncCounterQueue(queue, send, receipt));
    });

    el('register').addEventListener('change', () => {
        session = 0;
        resetDue();
        cart.clear();
        el('results').replaceChildren();
        el('summary').replaceChildren();
        el('order').value = '';
        el('receipt').replaceChildren();
        drawCart();
        invalidate();
        drawerStatus();
    });
    for (const id of ['tier', 'method', 'discount', 'member']) el(id).addEventListener('input', invalidate);
    el('member').addEventListener('input', resetDue);
    el('method').addEventListener('input', () => { el('khata-sale').hidden = !dueScope || el('method').value !== 'cash'; });
    el('tier').addEventListener('input', () => {
        resetDue();
        el('member-row').hidden = el('tier').value !== 'member';
        el('member-info').textContent = '';
        if (el('tier').value === 'member') el('member').focus();
    });
    // A card scanner types the code and Enter: review the total straight away.
    el('member').addEventListener('keydown', event => {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        if (cart.size) el('quote').requestSubmit();
        else void perform(readDue);
    });

    el('search').addEventListener('submit', event => {
        event.preventDefault();
        const term = el('term').value.trim();
        const scope = el('register').value;
        void perform(async () => {
            const result = await send({ ...context(), action: 'search', search: term });
            if (el('register').value !== scope) return;
            const rows = result.products;
            if (rows.length === 1 && (rows[0].sku.toLowerCase() === term.toLowerCase() || /^\d{13}$/.test(term))) {
                add(rows[0]);
                el('results').replaceChildren();
                return;
            }
            if (!rows.length) announce(labels.noResults);
            el('results').replaceChildren(...rows.map(row => {
                const item = document.createElement('li');
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'button';
                button.textContent = labels.add;
                button.addEventListener('click', () => add(row));
                item.append(document.createTextNode(row.name + ' · ' + row.sku), button);
                return item;
            }));
        });
    });

    el('quote').addEventListener('submit', event => {
        event.preventDefault();
        invalidate();
        void perform(async () => {
            const intent = payload();
            const reply = await send({ ...intent, action: 'counter_quote' });
            if (JSON.stringify(payload()) !== JSON.stringify(intent)) {
                announce(labels.cartChanged);
                return;
            }
            quote = { intent, digest: reply.digest, total: reply.total_minor };
            el('member-info').textContent = reply.member
                ? (reply.member.name + (reply.member.business_name ? ' · ' + reply.member.business_name : '') + ' · ' + reply.member.tier + ' · ' + reply.member.member_no)
                : '';
            el('total').textContent = labels.total + ' ' + money(quote.total);
            el('tender').value = (quote.total / 100).toFixed(2);
            if (intent.member) {
                try {
                    await readDue();
                } catch {
                    resetDue();
                    announce(labels.dueUnavailable);
                }
            }
        });
    });

    el('sale').addEventListener('submit', event => {
        event.preventDefault();
        if (busy || !quote || !session || !queue) return;
        let tender, khata;
        try {
            khata = khataAmounts();
            tender = counterMinor(el('tender').value);
            const cash = quote.total + khata.due_payment_minor - khata.credit_minor;
            if (khata.credit_minor > quote.total || tender < cash || (quote.intent.method !== 'cash' && tender !== cash)) throw new Error();
        } catch {
            announce(labels.invalid);
            return;
        }
        const intent = { ...quote.intent, action: 'counter_sale', session, tender, digest: quote.digest, ...khata };
        try {
            queue.save({ intent, key: window.crypto.randomUUID(), created: Date.now(), total: quote.total });
        } catch {
            announce(labels.storage);
            return;
        }
        cart.clear();
        resetDue();
        drawCart();
        invalidate();
        showQueue();
        announce(labels.queued);
        if (navigator.onLine) void perform(() => syncCounterQueue(queue, send, receipt));
    });

    el('drawer').addEventListener('submit', event => {
        event.preventDefault();
        const action = event.submitter?.value;
        if (!action) return;
        if (action === 'close' && showQueue().length) {
            announce(labels.syncFirst);
            return;
        }
        const scope = el('register').value;
        void perform(async () => {
            const data = await send({ ...context(), action: 'drawer', drawer_action: action, session,
                amount: counterMinor(el('amount').value), approval: el('approval')?.value ?? '' }, window.crypto.randomUUID());
            if (el('register').value !== scope) return;
            if (action === 'open' || action === 'status') session = data.session_id;
            if (action === 'close') session = 0;
            showDrawer(data.last_z ?? data);
        });
    });

    el('print').addEventListener('submit', event => {
        event.preventDefault();
        void perform(async () => {
            const reply = await send({ ...context(), action: 'invoice', order: el('order').valueAsNumber, paper: el('paper').value });
            // The authenticated endpoint supplies invoice markup with escaped snapshot fields.
            el('receipt').innerHTML = reply.styles + reply.html;
            window.print();
        });
    });

    const sync = () => {
        if (showQueue().length && navigator.onLine) return perform(() => syncCounterQueue(queue, send, receipt));
    };
    el('sync').addEventListener('click', sync);
    window.addEventListener('online', sync);
    window.addEventListener('storage', showQueue);
    window.addEventListener('focus', sync);
    void Promise.resolve(sync()).then(drawerStatus);
}
