const fs = require('node:fs/promises');
const path = require('node:path');
const http = require('node:http');
const assert = require('node:assert/strict');
const { openChrome } = require('./ChromeSession.cjs');

(async () => {
    const root = path.resolve(__dirname, '../..');
    const fixture = JSON.parse(await fs.readFile(path.join(root, 'storage/app/reports/owner-experience-browser-fixture.json'), 'utf8'));
    let base, unread = 1, allowDelete = false;
    const sales = [], errors = [];
    const server = http.createServer(async (req, res) => {
        try {
            const url = new URL(req.url, base);
            const json = value => { res.setHeader('Content-Type', 'application/json'); res.end(JSON.stringify(value)); };
            if (url.pathname === '/cashier-fixture' || url.pathname === '/inventory-fixture') {
                res.setHeader('Content-Type', 'text/html; charset=utf-8');
                res.end(fixture[url.pathname === '/cashier-fixture' ? 'cashier' : 'inventory'].replace(/http:\/\/localhost:8000/g, base).replace(/http:\\\/\\\/localhost:8000/g, base)); return;
            }
            if (url.pathname === '/notifications') return json({ enabled: true, unread, new_orders: 0, data: unread ? [{ id: 1, title: 'تنبيه تجريبي', message: 'مراجعة', details: [], read: false, url: base + '/inventory-fixture', created_at: new Date().toISOString() }] : [] });
            if (url.pathname.startsWith('/notifications/') && url.pathname.endsWith('/read')) { unread = 0; return json({ message: 'OK' }); }
            if (url.pathname.endsWith('/correction-readiness')) return json({ allowed: allowDelete, reason: 'رصيد قديم بلا مستند افتتاحي موثّق', review_url: base + '/inventory/readiness' });
            if (url.pathname.endsWith('/opening-stock/options')) return json(fixture.options);
            if (url.pathname.endsWith('/sales/catalog')) return json(fixture.catalog);
            if (url.pathname.endsWith('/sales/preview')) return json(fixture.quote);
            if (req.method === 'POST' && url.pathname === '/sales/complete') {
                let body = ''; for await (const chunk of req) body += chunk;
                sales.push(JSON.parse(body)); return json({ url: base + '/done' });
            }
            if (url.pathname === '/done') { res.end('<p>Recorded browser submission; database approval tested separately.</p>'); return; }
            if (url.pathname.startsWith('/assets/')) {
                const file = path.resolve(root, 'public', '.' + url.pathname);
                if (!file.startsWith(path.join(root, 'public/assets') + path.sep)) throw Error('Invalid asset');
                const extension = path.extname(file);
                res.setHeader('Content-Type', ({ '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' })[extension] || 'application/octet-stream');
                res.end(await fs.readFile(file)); return;
            }
            if (url.pathname === '/favicon.ico') { res.writeHead(204); res.end(); return; }
            errors.push(req.method + ' ' + url.pathname); res.writeHead(404); res.end();
        } catch (error) { errors.push(error.message); res.writeHead(500); res.end(); }
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    base = 'http://127.0.0.1:' + server.address().port;
    const chrome = await openChrome('owner-alerts-stock');
    const { evaluate, until } = chrome;
    const checks = [];
    try {
        await chrome.viewport(1280);
        await chrome.navigate(base + '/cashier-fixture');
        await until('!!document.querySelector("#cashier-products button")');
        await evaluate('document.querySelector("#cashier-products button").click()');
        await evaluate('document.querySelector("#cashier-variant-sizes button").click(); document.querySelector("#cashier-add-variant").click()');
        await until('!document.querySelector("#cashier-complete").disabled');
        await evaluate('document.querySelector("#cashier-discount-value").value="2"; document.querySelector("#cashier-discount-value").dispatchEvent(new Event("input",{bubbles:true}))');
        await until('!document.querySelector("#cashier-complete").disabled');
        await evaluate('document.querySelector("#cashier-complete").click()');
        await until('document.querySelector("#cashier-minimum-dialog").open');
        assert.equal(sales.length, 0);
        assert.match(await evaluate('document.querySelector("#cashier-minimum-items").textContent'), /33\.000000/);
        await evaluate('document.querySelector("#cashier-minimum-review").click()');
        assert.equal(await evaluate('document.querySelector("#cashier-minimum-dialog").open'), false);
        await evaluate('document.querySelector("#cashier-complete").click(); document.querySelector("#cashier-discount-value").value="3"; document.querySelector("#cashier-discount-value").dispatchEvent(new Event("input",{bubbles:true}))');
        assert.equal(await evaluate('document.querySelector("#cashier-minimum-dialog").open'), false);
        await until('!document.querySelector("#cashier-complete").disabled');
        await evaluate('document.querySelector("#cashier-complete").click(); document.querySelector("#cashier-minimum-accept").click()');
        await until('location.pathname === "/done"');
        assert.equal(sales.length, 1);
        assert.equal(sales[0].below_minimum_confirmed, true);
        assert.equal(sales[0].below_minimum_confirmation, fixture.quote.minimum_warning.token);
        checks.push('Minimum modal, review, cart change invalidation and explicit confirmed submission');

        await chrome.viewport(390);
        await chrome.navigate(base + '/inventory-fixture');
        await evaluate('window.openQuickOpeningStockModal()');
        await until('!!document.querySelector("#quickOpeningStockTableBody [data-remove]")');
        await evaluate('document.querySelector("#quickOpeningStockTableBody [data-remove]").click()');
        await until('!!document.querySelector("[data-opening-empty]")');
        assert.match(await evaluate('document.querySelector("[data-opening-empty]").textContent'), /لا توجد أصناف مدخلة/);
        assert.equal(await evaluate('document.querySelectorAll("#quickOpeningStockTableBody [data-field]").length'), 0);
        await evaluate('document.querySelector("[data-add-empty-row]").click()');
        await until('!!document.querySelector("#quickOpeningStockTableBody [data-remove]")');
        await evaluate('document.querySelector("#osClose").click()');
        checks.push('Removing last opening row shows empty state; add row restores editable row');

        await evaluate('document.querySelector("[data-inventory-action=delete]").click()');
        await until('!document.querySelector("#inventorySetupFeedback").hidden');
        assert.equal(await evaluate('document.querySelector("#inventorySetupDialog").open'), false);
        assert.equal(await evaluate('!!document.querySelector("#inventorySetupFeedback a")'), true);
        allowDelete = true;
        await evaluate('document.querySelector("[data-inventory-action=delete]").click()');
        await until('document.querySelector("#inventorySetupDialog").open');
        await evaluate('document.querySelector("#inventorySetupDialog [data-setup-close]").click()');
        checks.push('Blocked deletion explains reason before confirmation; ready deletion opens confirmation');

        await until('document.querySelector("[data-alert-count]").textContent === "1"');
        await evaluate('document.querySelector("[data-alert-toggle]").click(); document.querySelector("[data-alert-read-all]").click()');
        await until('document.querySelector("[data-alert-count]").hidden');
        unread = 2;
        await evaluate('window.dispatchEvent(new Event("app:alerts-changed"))');
        await until('document.querySelector("[data-alert-count]").textContent === "2" && !document.querySelector("[data-alert-count]").hidden');
        checks.push('Bell unread count refreshes after read and new notification event');
        await chrome.screenshot('owner-alerts-stock-mobile');
        assert.deepEqual(chrome.errors, []);
        assert.deepEqual(errors, []);
        const result = { mode: 'Chrome against Laravel-rendered isolated MySQL fixtures; AJAX recorded/mocked; real database approval covered by feature tests', checks, javascript_errors: chrome.errors, unexpected_requests: errors };
        await fs.writeFile(path.join(root, 'storage/app/reports/owner-experience-browser-result.json'), JSON.stringify(result, null, 2));
        console.log(JSON.stringify(result, null, 2));
    } catch (error) { console.error(JSON.stringify({ errors, javascript: chrome.errors, network: chrome.network.filter(item => item.status >= 400) })); throw error; }
    finally { chrome.close(); server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
})().catch(error => { console.error(error); process.exitCode = 1; });
