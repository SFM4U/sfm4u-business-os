import {spawn} from 'node:child_process';
import {mkdir, readFile, writeFile} from 'node:fs/promises';
import {resolve} from 'node:path';

const profile = resolve('.fixture', 'g2-register-chrome-' + Date.now());
await mkdir(profile, {mode: 0o700});
const chrome = process.env.CHROME_BIN || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const child = spawn(chrome, [
    '--headless', '--disable-background-networking', '--remote-debugging-address=127.0.0.1',
    '--remote-debugging-port=0', '--user-data-dir=' + profile, 'about:blank',
], {stdio: 'ignore'});
const sleep = ms => new Promise(resolveDelay => setTimeout(resolveDelay, ms));
let socket;

async function waitFor(read, label) {
    for (let i = 0; i < 100; i++) {
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

    const url = 'http://127.0.0.1:18941/';
    const status = marker => waitFor(() => evalJS('document.querySelector("#status").textContent.includes(' + JSON.stringify(marker) + ')'), marker);
    const click = selector => evalJS('document.querySelector(' + JSON.stringify(selector) + ').click()');
    await go(url, 'Ready.');
    await assert('document.querySelector("#credit").disabled', 'cashier credit unavailable');
    await assert('document.querySelector("#sale button").disabled', 'register requires opening float before a sale');
    await evalJS('document.querySelector("#drawer-amount").value = "100.00"');
    await click('#drawer-open');
    try {
        await status('Drawer activity saved');
    } catch (error) {
        console.error(await evalJS('document.querySelector("#error").textContent + " / " + document.querySelector("#drawer-status").textContent'));
        throw error;
    }
    await evalJS(`{
        const fetchOriginal = window.fetch;
        window.fetch = async (...args) => {
            window.fetch = fetchOriginal;
            await fetchOriginal(...args);
            throw new TypeError('Synthetic lost response after commit');
        };
    }`);
    await click('#sale button');
    await waitFor(() => evalJS('!document.querySelector("#error").hidden'), 'unconfirmed sale');
    await assert('document.querySelector("#sale button").disabled', 'unknown sale locks fresh submission');
    await go(url, 'Last request is unconfirmed');
    await click('#recover');
    await status('Stock remaining: 9');
    await assert('document.querySelector("#document-number").textContent === "R1-000001"', 'reload and recovery retain the committed receipt');

    await click('details:has(#receipt-lookup) summary');
    await click('[name=reprint]');
    await status('COPY ready');
    await assert('document.querySelector("#document-number").textContent === "R1-000001" && document.body.innerText.includes("Stock remaining: 9")', 'COPY retains number and stock');
    await evalJS(`{
        const select = document.querySelector('#role');
        select.value = 'manager';
        select.dispatchEvent(new Event('change'));
        const fetchOriginal = window.fetch;
        window.fetch = async () => {
            window.fetch = fetchOriginal;
            throw new TypeError('Synthetic disconnection before submission');
        };
    }`);
    await click('#credit');
    await waitFor(() => evalJS('!document.querySelector("#error").hidden'), 'unconfirmed credit');
    await click('#recover');
    await status('No committed receipt found');
    await click('#retry');
    await status('Stock remaining: 10');
    await assert('document.querySelector("#document-number").textContent === "R1-000002" && document.querySelector("#document-type").textContent === "Credit note"', 'same-key retry issues one sequential credit and restores one unit');
    await evalJS('document.querySelector("#drawer-amount").value = "100.00"');
    await click('#drawer-close');
    await status('Drawer activity saved');
    await assert('document.querySelector("#z-heading").textContent === "Z-report · R1-Z-000001"', 'closed drawer displays immutable Z report');
    await go(url, 'Ready.');
    await assert('document.querySelector("#z-heading").textContent === "Z-report · R1-Z-000001" && document.querySelector("#sale button").disabled',
        'reload restores closed state and Z report');
    await cdp('Emulation.setDeviceMetricsOverride', {width:390,height:844,deviceScaleFactor:1,mobile:true});
    await assert('document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1', 'register fits 390px viewport');
    await cdp('Emulation.setDeviceMetricsOverride', {width:1120,height:910,deviceScaleFactor:1,mobile:false});
    const {data} = await cdp('Page.captureScreenshot', {format:'png'});
    await writeFile(resolve('.fixture/register-z-browser.png'), Buffer.from(data,'base64'));
} finally {
    socket?.close();
    child.kill();
}
