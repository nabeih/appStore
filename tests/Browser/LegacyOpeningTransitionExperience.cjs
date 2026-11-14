const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const http = require('node:http');
const { openChrome } = require('./ChromeSession.cjs');

(async () => {
    const root = path.resolve(__dirname, '../..');
    const fixture = JSON.parse(await fs.readFile(path.join(root, 'storage/app/reports/legacy-transition-ui-fixture.json'), 'utf8'));
    const requests = [], posted = new Set();
    let base;
    const server = http.createServer(async (req, res) => {
        try {
            const url = new URL(req.url, base);
            if (req.method === 'GET' && url.pathname === '/notifications') {
                res.writeHead(200, { 'content-type': 'application/json' }); res.end(JSON.stringify({ data: [], unread_count: 0 })); return;
            }
            if (req.method === 'POST') {
                let raw = ''; for await (const chunk of req) raw += chunk;
                const body = JSON.parse(raw); requests.push({ path: url.pathname, body });
                assert.equal(body.source_kind, 'opening');
                for (const key of ['account_id', 'funding_confirmed', 'funding_evidence', 'opening_inclusion']) assert.equal(Object.hasOwn(body, key), false);
                const plan = fixture.plans[body.source_id]; assert.ok(plan);
                let result = plan;
                if (url.pathname.endsWith('/post')) {
                    assert.equal(body.confirmed, true); assert.equal(body.revision, plan.revision); assert.equal(plan.status, 'eligible');
                    posted.add(String(body.source_id)); result = fixture.responses[body.source_id]; assert.ok(result);
                }
                res.writeHead(200, { 'content-type': 'application/json' }); res.end(JSON.stringify(result)); return;
            }
            if (url.pathname === '/') {
                res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
                res.end(fixture.html.replace(/http:\/\/localhost(?::\d+)?/g, base)); return;
            }
            const target = path.resolve(root, 'public', '.' + decodeURIComponent(url.pathname));
            assert.ok(target.startsWith(path.join(root, 'public') + path.sep));
            const bytes = await fs.readFile(target);
            const type = { '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2', '.svg': 'image/svg+xml', '.png': 'image/png' }[path.extname(target)] || 'application/octet-stream';
            res.writeHead(200, { 'content-type': type }); res.end(bytes);
        } catch (error) { res.writeHead(500, { 'content-type': 'application/json' }); res.end(JSON.stringify({ message: error.message })); }
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve)); base = 'http://127.0.0.1:' + server.address().port;
    const chrome = await openChrome('legacy-opening-transition');
    const screenshots = [];
    try {
        await chrome.viewport(1440); await chrome.navigate(base + '/');
        const evaluate = chrome.evaluate;
        const input = (selector, value) => evaluate(`(()=>{const el=document.querySelector(${JSON.stringify(selector)});el.value=${JSON.stringify(value)};el.dispatchEvent(new Event('input',{bubbles:true}));el.dispatchEvent(new Event('change',{bubbles:true}));})()`);
        assert.equal(await evaluate('document.querySelectorAll("[data-opening-row]").length'), 3);
        assert.equal(await evaluate('document.querySelectorAll("[data-opening-select]:disabled").length'), 1);
        const source = 'opening:' + Object.keys(fixture.responses)[0];
        await input('[data-transition-form] [name=source]', source);
        assert.equal(await evaluate('document.querySelector("[data-transition-funding]").hidden'), true);
        const summary = await evaluate('document.querySelector("[data-opening-summary]").textContent');
        assert.ok(summary.includes('1820.00'), JSON.stringify({ summary, errors: chrome.errors, network: chrome.network.filter(row => row.status >= 400) }));
        await evaluate('(()=>{const select=document.querySelector("[data-transition-form] [name=source]");select.append(new Option("Payment UI test","payment:999"));select.value="payment:999";select.dispatchEvent(new Event("change",{bubbles:true}));})()');
        assert.equal(await evaluate('document.querySelector("[data-transition-funding]").hidden'), false);
        await input('[data-transition-form] [name=source]', source);
        await input('[data-transition-form] [name=evidence]', 'محضر جرد بداية موثق للمجموعة');
        await input('[data-transition-form] [name=reason]', 'اعتماد الأثر المالي للافتتاح');
        await evaluate('document.querySelector("[data-transition-form]").requestSubmit()');
        await chrome.until('document.querySelector("[data-transition-result]").dataset.previewStatus === "eligible"');
        assert.equal(await evaluate('document.querySelector("[data-transition-post]").disabled'), true);
        await evaluate('document.querySelector("[data-transition-form] [name=confirmed]").click()');
        assert.equal(await evaluate('document.querySelector("[data-transition-post]").disabled'), false);
        await input('[data-transition-form] [name=reason]', 'تغيير الدليل يتطلب معاينة جديدة');
        assert.equal(await evaluate('document.querySelector("[data-transition-post]").disabled'), true);
        await input('[data-opening-batch-form] [name=evidence]', 'محضر جرد بداية موثق للمجموعة');
        await input('[data-opening-batch-form] [name=reason]', 'اعتماد الأثر المالي للافتتاح');
        await evaluate('for(const el of document.querySelectorAll("[data-opening-select]:not(:disabled)"))el.click();document.querySelector("[data-opening-batch-form]").requestSubmit()');
        await chrome.until('document.querySelectorAll("[data-opening-result][data-status=eligible]").length === 2 && !document.querySelector("[data-opening-batch-form] [name=confirmed]").disabled');
        assert.equal(await evaluate('document.querySelector("[data-opening-batch-post]").disabled'), true);
        await evaluate('window.confirm=()=>true;document.querySelector("[data-opening-batch-form] [name=confirmed]").click();document.querySelector("[data-opening-batch-post]").click()');
        await chrome.until('document.querySelectorAll("[data-opening-result][data-status=posted]").length === 2 && document.querySelector("[data-opening-batch-post]").disabled', 40000);
        assert.equal(posted.size, 2); assert.equal(requests.filter(row => row.path.endsWith('/post')).length, 2);
        assert.equal(await evaluate('document.querySelectorAll("[data-opening-select]:disabled").length'), 3);
        for (const width of [1440, 390]) {
            await chrome.viewport(width); await evaluate('document.fonts.ready');
            const sizes = await evaluate('({width:innerWidth,scroll:document.documentElement.scrollWidth})');
            assert.ok(sizes.scroll <= sizes.width + 1, JSON.stringify(sizes));
            screenshots.push(await chrome.screenshot('legacy-opening-transition-' + width));
        }
        assert.deepEqual(chrome.errors, []);
        assert.deepEqual(chrome.network.filter(row => row.status >= 400), []);
        const result = { browser: 'Chrome', transport: 'Rendered isolated feature-test HTML and recorded API responses; no application database connection', widths: [1440, 390], screenshots, posted_documents: posted.size, requests, js_errors: chrome.errors };
        await fs.writeFile(path.join(root, 'storage/app/reports/legacy-opening-transition-browser.json'), JSON.stringify(result, null, 2));
        console.log(JSON.stringify({ posted_documents: posted.size, screenshots: screenshots.length, js_errors: chrome.errors.length, transport: result.transport }));
    } finally { chrome.close(); server.closeAllConnections(); server.close(); }
})().catch(error => { console.error(error.stack); process.exitCode = 1; });
