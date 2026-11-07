const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const http = require('node:http');
const { spawn, execFileSync } = require('node:child_process');
const { openChrome } = require('./ChromeSession.cjs');

(async () => {
    const root = path.resolve(__dirname, '../..');
    const context = JSON.parse(await fs.readFile(path.join(root, 'storage/app/private/audits/financial-audit-browser.json'), 'utf8'));
    assert.match(context.database, /^appstore_financial_audit_[a-f0-9]{16}$/);
    const probe = http.createServer();
    await new Promise(resolve => probe.listen(0, '127.0.0.1', resolve));
    const port = probe.address().port;
    await new Promise(resolve => probe.close(resolve));
    const origin = 'http://127.0.0.1:' + port;
    const env = { ...process.env, APP_ENV: 'testing', DB_CONNECTION: 'mysql', DB_HOST: '127.0.0.1', DB_DATABASE: context.database, DB_URL: '',
        APP_URL: origin, ASSET_URL: origin, APP_DEBUG: 'false', DEBUGBAR_ENABLED: 'false', SESSION_DRIVER: 'file',
        SESSION_COOKIE: 'chart_preview_' + port, SESSION_SECURE_COOKIE: 'false', CACHE_STORE: 'array', LOG_CHANNEL: 'null',
        MAIL_MAILER: 'log', QUEUE_CONNECTION: 'sync', VISUAL_SEARCH_ENABLED: 'false', VISUAL_SEARCH_INDEXING: 'false' };
    const php = 'C:/laragon/bin/php/php-8.5/php.exe';
    const guard = "require 'vendor/autoload.php';$a=new Illuminate\\Foundation\\Application(getcwd());(new Illuminate\\Foundation\\Bootstrap\\LoadEnvironmentVariables)->bootstrap($a);(new Illuminate\\Foundation\\Bootstrap\\LoadConfiguration)->bootstrap($a);$c=$a['config']->get('database.connections.mysql');if($a->configurationIsCached()||$a['config']->get('app.env')!=='testing'||$c['database']!==getenv('DB_DATABASE')||$c['host']!=='127.0.0.1'||!empty($c['url']))throw new LogicException('Isolated HTTP guard rejected');echo 'isolated';";
    assert.equal(execFileSync(php, ['-r', guard], { cwd: root, env, encoding: 'utf8' }), 'isolated');
    const server = spawn(php, ['-S', '127.0.0.1:' + port, path.join(root, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')],
        { cwd: path.join(root, 'public'), env, windowsHide: true, stdio: 'ignore' });
    let chrome;
    try {
        for (let attempt = 0; attempt < 60; attempt++) {
            try { if ((await fetch(origin + '/login')).status === 200) break; } catch {}
            await new Promise(resolve => setTimeout(resolve, 100));
        }
        chrome = await openChrome('chart-of-accounts');
        await chrome.send('Network.setBlockedURLs', { urls: ['https://*'] });
        await chrome.navigate(origin + '/login');
        await chrome.evaluate('document.querySelector("#username").value=' + JSON.stringify(context.username) +
            ';document.querySelector("#password").value=' + JSON.stringify(context.password) + ';document.querySelector("#login-form").requestSubmit()');
        await chrome.until('location.pathname==="/dashboard" && document.readyState==="complete"');
        const pages = [];
        for (const width of [1280, 390]) {
            await chrome.viewport(width);
            for (const [url, name, selector] of [
                ['/dashboard?branch_id=' + context.branch_id, 'dashboard-valuation', '[data-inventory-valuation]'],
                ['/finance/chart?branch_id=' + context.branch_id, 'accounts-tree', '.chart-tree'],
                ['/finance/inventory-reconciliation?branch_id=' + context.branch_id, 'inventory-reconciliation', '[data-inventory-valuation]'],
            ]) {
                await chrome.navigate(origin + url);
                assert.equal(await chrome.evaluate('!!document.querySelector(' + JSON.stringify(selector) + ')'), true);
                const overflow = await chrome.evaluate('document.documentElement.scrollWidth>innerWidth');
                assert.equal(overflow, false, name + ' overflow at ' + width);
                if (name === 'accounts-tree') {
                    await chrome.until('getComputedStyle(document.querySelector(".sale-header")).display==="flex" && getComputedStyle(document.querySelector(".sale-panel")).backgroundColor==="rgb(255, 255, 255)"');
                    assert.equal(await chrome.evaluate('document.querySelectorAll(".chart-group").length'), 5);
                    await chrome.evaluate('document.querySelector(".chart-group summary").click()');
                    assert.equal(await chrome.evaluate('document.querySelector(".chart-group").open'), false);
                    await chrome.evaluate('document.querySelector(".chart-group summary").click()');
                }
                pages.push({ width, url, overflow, screenshot: await chrome.screenshot('chart-' + name + '-' + width) });
            }
        }
        await chrome.navigate(origin + '/finance/chart?branch_id=' + context.branch_id + '&q=inventory');
        const accountUrl = await chrome.evaluate('[...document.querySelectorAll(".chart-account a")].find(x=>x.textContent.includes("inventory")).href');
        await chrome.navigate(accountUrl);
        assert.equal(await chrome.evaluate('!!document.querySelector("form[action*=chart]")'), true);
        pages.push({ url: accountUrl, screenshot: await chrome.screenshot('chart-account-ledger-390') });
        assert.deepEqual(chrome.errors, []);
        assert.deepEqual(chrome.network.filter(row => row.host === '127.0.0.1' && row.status >= 400), []);
        await fs.writeFile(path.join(root, 'storage/app/reports/chart-browser.json'), JSON.stringify({ database: context.database, actual_http: true, pages,
            javascript_errors: chrome.errors, business_forms_submitted: false, external_cdn_blocked: true, physical_phone_tested: false, safari_tested: false }, null, 2));
        console.log(JSON.stringify({ pages: pages.length, errors: 0, database: context.database }));
    } finally { chrome?.close(); server.kill(); }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
