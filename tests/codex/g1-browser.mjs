import {spawn} from 'node:child_process';
import {mkdir, readFile} from 'node:fs/promises';
import {resolve} from 'node:path';

const fixture = JSON.parse(await readFile('.fixture/g1-browser-input.json', 'utf8'));
const home = new URL(fixture.home);
if (home.hostname !== '127.0.0.1') throw new Error('Local fixture required');
const profile = resolve('.fixture', 'g1-chrome-' + Date.now());
await mkdir(profile, {mode: 0o700});
const chrome = process.env.CHROME_BIN || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const child = spawn(chrome, [
    '--headless', '--disable-background-networking', '--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE 127.0.0.1', '--remote-debugging-address=127.0.0.1',
    '--remote-debugging-port=0', '--user-data-dir=' + profile, 'about:blank',
], {stdio: 'ignore'});
const sleep = ms => new Promise(resolveDelay => setTimeout(resolveDelay, ms));
let socket;

async function waitFor(read, label) {
    for (let i = 0; i < 200; i++) {
        const result = await read();
        if (result) return result;
        await sleep(200);
    }
    throw new Error('Timed out: ' + label);
}

try {
    const port = await waitFor(async () => {
        try { return (await readFile(resolve(profile, 'DevToolsActivePort'), 'utf8')).split('\n')[0]; }
        catch { return null; }
    }, 'Chrome DevTools');
    const targets = await (await fetch('http://127.0.0.1:' + port + '/json/list')).json();
    socket = new WebSocket(targets.find(target => target.type === 'page').webSocketDebuggerUrl);
    await new Promise((yes, no) => {
        socket.addEventListener('open', yes, {once: true});
        socket.addEventListener('error', no, {once: true});
    });
    let id = 0;
    const callbacks = new Map();
    socket.addEventListener('message', event => {
        const response = JSON.parse(event.data);
        const complete = callbacks.get(response.id);
        if (complete) {
            callbacks.delete(response.id);
            complete(response);
        }
    });
    async function cdp(method, params = {}) {
        const messageId = ++id;
        const response = await new Promise(done => {
            callbacks.set(messageId, done);
            socket.send(JSON.stringify({id: messageId, method, params}));
        });
        if (response.error) throw new Error(response.error.message);
        return response.result;
    }
    async function evalJS(expression) {
        const result = await cdp('Runtime.evaluate', {expression, awaitPromise: true, returnByValue: true});
        if (result.exceptionDetails) throw new Error(result.exceptionDetails.text);
        return result.result.value;
    }
    async function go(url, marker) {
        await cdp('Page.navigate', {url});
        await waitFor(async () => await evalJS(
            'document.readyState === "complete" && document.body?.innerText.includes(' + JSON.stringify(marker) + ')'
        ), marker);
    }
    async function assert(expression, label) {
        if (!await evalJS(expression)) throw new Error(label);
        console.log('PASS ' + label);
    }

    await cdp('Network.enable');
    await go(fixture.product, 'SFM4U synthetic browser pack');
    await assert('!document.querySelector("select[name=sfmwh_pack]") && !document.body.innerText.includes("100.00 base price")',
        'guest product HTML excludes restricted packs and price');
    await assert(`(async () => {
        const response = await fetch(${JSON.stringify(fixture.blocks)}, { method: 'POST',
            body: new URLSearchParams({ sfmwh_profit_card: 'guest', 'sfmwh_retail[1]': '123.45' }) });
        return response.status === 403;
    })()`, 'guest cannot submit a retail override');
    const cookie = await cdp('Network.setCookie', {...fixture.cookie, url: fixture.home, httpOnly: true, sameSite: 'Lax'});
    if (!cookie.success) throw new Error('Synthetic cookie was not accepted');
    await go(fixture.product, '100.00 base price');
    await assert('document.querySelector("select[name=sfmwh_pack] option[value=case]") !== null',
        'approved buyer sees the case selector');
    await assert(`(async () => {
        const response=await fetch(${JSON.stringify(fixture.product)}, {credentials:'same-origin'});
        const policy=response.headers.get('cache-control')||'';
        return policy.includes('no-store') && (await response.text()).includes('100.00 base price');
    })()`, 'eligible buyer response is explicitly no-store');
    await cdp('Emulation.setDeviceMetricsOverride', {width:390,height:844,deviceScaleFactor:1,mobile:true});
    await assert('document.documentElement.scrollWidth <= document.documentElement.clientWidth+1', 'approved pack form fits a 390px viewport');
    await cdp('Emulation.clearDeviceMetricsOverride');
    await assert(`(async () => {
        const form = document.querySelector('form.cart');
        form.querySelector('[name=sfmwh_pack]').value = 'case';
        form.querySelector('[name=quantity]').value = '12';
        const payload = new URLSearchParams(new FormData(form, form.querySelector('button[name=add-to-cart]')));
        const response = await fetch(${JSON.stringify(fixture.product)}, {
            method: 'POST', body: payload, credentials: 'same-origin'
        });
        return response.ok;
    })()`, 'classic product form posts selected pack');
    await go(fixture.classic, 'Synthetic Classic basket');
    await assert('document.body.innerText.includes("100.00") && document.body.innerText.includes("1 × 12")',
        'Classic browser cart displays the exact pack total and count');
    await assert('document.querySelector("[data-sfmwh-profit]")?.innerText.includes("৳1688.00")',
        'Classic cart shows the wholesale profit estimate');
    await go(fixture.blocks, 'Synthetic Blocks basket');
    await waitFor(async () => await evalJS(
        'document.querySelector(".wc-block-cart")?.innerText.includes("100.00")'
    ), 'Blocks displays wholesale total');
    await assert('document.querySelector(".wc-block-cart")?.innerText.includes("SFM4U synthetic browser pack")',
        'Blocks browser cart displays the same server-priced item');
    await go(fixture.classicCheckout, 'Synthetic Classic checkout');
    await assert('document.querySelector(".woocommerce-checkout-review-order-table")?.innerText.includes("100.00")',
        'Classic checkout order review keeps the exact wholesale total');
    await cdp('Page.navigate', {url: fixture.blocksCheckout});
    await waitFor(async () => await evalJS(
        'document.querySelector(".wc-block-checkout")?.innerText.includes("100.00")'
    ), 'Blocks checkout displays wholesale total');
    await evalJS('document.querySelector(".wc-block-components-checkout-order-summary__title[aria-expanded=false]")?.click()');
    await waitFor(async () => await evalJS(
        'document.querySelector(".wc-block-components-order-summary-item")?.innerText.includes("SFM4U synthetic browser pack")'
    ), 'Blocks checkout order summary shows the wholesale item');
    console.log('PASS Blocks checkout shows the same wholesale item and total');
    await go(fixture.blocks, 'Synthetic Blocks basket');
    await assert('document.querySelector("[data-sfmwh-profit]")?.innerText.includes("৳1688.00")',
        'Blocks cart shows the wholesale profit estimate');
    await waitFor(async () => await evalJS(
        'window.wp?.data?.select("wc/store/cart")?.getCartData()?.items?.length > 0'
    ), 'Blocks cart data available');
    await evalJS(`(async () => {
        const cart = wp.data.select('wc/store/cart');
        const item = cart.getCartData().items[0];
        await wp.data.dispatch('wc/store/cart').changeCartItemQuantity(item.key, 24);
    })()`);
    await waitFor(async () => await evalJS(
        'document.querySelector("[data-sfmwh-profit]")?.innerText.includes("৳3376.00")'
    ), 'Blocks profit card refreshes after a quantity change');
    console.log('PASS Blocks cart refreshes the profit estimate after quantity changes');
    await assert(`(async () => {
        const form = document.querySelector('[data-sfmwh-profit] form');
        form.querySelector('input[type=number]').value = '160.00';
        const response = await fetch(form.action, { method: 'POST', credentials: 'same-origin',
            body: new URLSearchParams(new FormData(form)) });
        return response.ok && (await response.text()).includes('৳3640.00');
    })()`, 'private cart form saves the override and recomputes the estimate');
    await assert(`(async () => {
        const card = document.querySelector('[data-sfmwh-profit]');
        const refresh = await fetch(card.dataset.sfmwhProfit);
        const form = card.querySelector('form');
        const payload = new URLSearchParams(new FormData(form));
        for (let i = 1000; i < 1201; i++) payload.set('sfmwh_retail[' + i + ']', '1.00');
        const rejected = await fetch(form.action, { method: 'POST', body: payload });
        const cache = refresh.headers.get('Cache-Control') || '';
        return refresh.ok && cache.includes('private') && cache.includes('no-store') && rejected.status === 422;
    })()`, 'private refresh has explicit no-store and oversized override submissions are rejected');
    await go(fixture.purchases, 'Wholesale purchase history');
    await assert(`document.querySelector('table')?.innerText.includes(${JSON.stringify(String(fixture.purchaseId))})
        && document.querySelector('table')?.innerText.includes('1688.00')`, 'account endpoint shows the saved purchase estimate');
    await assert(`(async () => {
        const url = new URL(document.querySelector('a[href*="sfmwh_purchase_csv"]').href);
        const valid = await fetch(url);
        const contents = await valid.text();
        url.searchParams.set('_wpnonce', 'invalid');
        const denied = await fetch(url);
        return valid.ok && contents.includes('TOTAL') && contents.includes('1688.00') && denied.status === 403;
    })()`, 'signed CSV matches the page and an invalid nonce is rejected');
} finally {
    socket?.close();
    child.kill();
}
