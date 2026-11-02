const fs = require('node:fs/promises');
const path = require('node:path');
const { spawn } = require('node:child_process');

async function openChrome(label) {
    const root = path.resolve(__dirname, '../..');
    const profile = path.join(root, 'storage/framework/testing/chrome-' + label + '-' + Date.now());
    await fs.mkdir(profile, { recursive: true });
    const browser = spawn('C:/Program Files/Google/Chrome/Application/chrome.exe', [
        '--headless=new', '--remote-debugging-port=0', '--user-data-dir=' + profile,
        '--disable-background-networking', '--disable-component-update', '--disable-sync',
        '--no-first-run', '--no-default-browser-check', '--disable-extensions', 'about:blank',
    ], { windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'] });
    let socket;
    try {
        const endpoint = await new Promise((resolve, reject) => {
            const timer = setTimeout(() => reject(Error('Chrome startup timeout')), 20000);
            browser.stderr.on('data', data => {
                const match = String(data).match(/DevTools listening on (ws:\/\/[^\s]+)/);
                if (match) { clearTimeout(timer); resolve(match[1]); }
            });
            browser.once('error', reject);
        });
        const pages = await (await fetch('http://127.0.0.1:' + new URL(endpoint).port + '/json/list')).json();
        socket = new WebSocket(pages.find(page => page.type === 'page').webSocketDebuggerUrl);
        await new Promise((resolve, reject) => { socket.addEventListener('open', resolve, { once: true }); socket.addEventListener('error', reject, { once: true }); });
        let counter = 0;
        const pending = new Map(), errors = [], network = [];
        const send = (method, params = {}) => new Promise((resolve, reject) => {
            const id = ++counter; pending.set(id, { resolve, reject }); socket.send(JSON.stringify({ id, method, params }));
        });
        socket.addEventListener('message', event => {
            const data = JSON.parse(String(event.data));
            if (data.id) {
                const task = pending.get(data.id); if (task) { pending.delete(data.id); data.error ? task.reject(Error(data.error.message)) : task.resolve(data.result); }
            } else if (data.method === 'Runtime.exceptionThrown') {
                errors.push(data.params.exceptionDetails.text);
            } else if (data.method === 'Runtime.consoleAPICalled' && data.params.type === 'error') {
                errors.push('console.error: ' + (data.params.args[0]?.value || 'error'));
            } else if (data.method === 'Fetch.requestPaused') {
                const request = data.params.request;
                const url = new URL(request.url);
                const denied = url.hostname === '127.0.0.1' && url.port === '8000' && !['GET', 'HEAD'].includes(request.method);
                send(denied ? 'Fetch.failRequest' : 'Fetch.continueRequest', { requestId: data.params.requestId, ...(denied ? { errorReason: 'BlockedByClient' } : {}) }).catch(() => {});
            } else if (data.method === 'Network.responseReceived') {
                const response = data.params.response;
                if (/^https?:/.test(response.url)) { const url = new URL(response.url); network.push({ host: url.hostname, path: url.pathname.replace(/\/checkouts\/.*/, '/checkouts/[redacted]'), status: response.status, type: data.params.type }); }
            }
        });
        await send('Runtime.enable'); await send('Page.enable'); await send('Network.enable');
        const evaluate = async expression => {
            const result = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
            if (result.exceptionDetails) { throw Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text); }
            return result.result.value;
        };
        const until = async (expression, timeout = 30000) => {
            const start = Date.now();
            while (Date.now() - start < timeout) { if (await evaluate(expression)) { return; } await new Promise(resolve => setTimeout(resolve, 100)); }
            throw Error('Browser condition timeout: ' + expression);
        };
        const viewport = async width => send('Emulation.setDeviceMetricsOverride', { width, height: width < 600 ? 844 : 1000, deviceScaleFactor: 1, mobile: width < 600 });
        const navigate = async url => {
            const navigation = await send('Page.navigate', { url });
            if (navigation.errorText) throw Error(navigation.errorText);
            if (navigation.loaderId) {
                const start = Date.now();
                while ((await send('Page.getFrameTree')).frameTree.frame.loaderId !== navigation.loaderId) {
                    if (Date.now() - start > 30000) throw Error('Navigation did not commit');
                    await new Promise(resolve => setTimeout(resolve, 100));
                }
            }
            await until('document.readyState === "complete" && !!document.body');
            await new Promise(resolve => setTimeout(resolve, 700));
        };
        const screenshot = async name => {
            const height = await evaluate('Math.min(9000, Math.max(innerHeight,document.documentElement.scrollHeight))');
            const width = await evaluate('innerWidth');
            const result = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { x: 0, y: 0, width, height, scale: 1 } });
            const target = path.join(root, 'storage/app/reports', name + '.png');
            await fs.writeFile(target, Buffer.from(result.data, 'base64'));
            return target;
        };
        return { send, evaluate, until, viewport, navigate, screenshot, errors, network, close() { socket?.close(); browser.kill(); } };
    } catch (error) { socket?.close(); browser.kill(); throw error; }
}
module.exports = { openChrome };
