const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const net = require('node:net');
const { spawn, execFileSync } = require('node:child_process');
const { openChrome } = require('./ChromeSession.cjs');

(async () => {
    const root = path.resolve(__dirname, '../..');
    const fixture = JSON.parse(await fs.readFile(path.join(root, 'storage/app/private/purchase-editor-preview/context.json'), 'utf8'));
    assert.match(fixture.database, /^appstore_purchase_preview_[a-f0-9]{16}$/);
    const port = await new Promise(resolve => {
        const socket = net.createServer(); socket.listen(0, '127.0.0.1', () => { const port = socket.address().port; socket.close(() => resolve(port)); });
    });
    const base = `http://127.0.0.1:${port}`;
    const php = 'C:/laragon/bin/php/php-8.5/php.exe';
    const env = { ...process.env, APP_ENV: 'testing', APP_DEBUG: 'false', APP_URL: base, DB_CONNECTION: 'mysql',
        DB_DATABASE: fixture.database, DB_HOST: '127.0.0.1', DB_PORT: '3306', DB_URL: '', CACHE_STORE: 'array',
        SESSION_DRIVER: 'file', QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array',
        APP_CONFIG_CACHE: path.join(root, 'storage/framework/testing/purchase-no-config-' + port + '.php') };
    const guard = `require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
        $c=config('database.connections.mysql'); if (!app()->environment('testing') || app()->configurationIsCached() || config('database.default')!=='mysql'
        || $c['host']!=='127.0.0.1' || (string)$c['port']!=='3306' || $c['database']!==getenv('DB_DATABASE') || !preg_match('/^appstore_purchase_preview_[a-f0-9]{16}$/D',$c['database'])
        || !empty($c['url']) || !empty($c['read']) || !empty($c['write'])) {throw new LogicException('Isolated browser database required');}
        $db=Illuminate\\Support\\Facades\\DB::connection(); if($db->selectOne('SELECT DATABASE() AS name')->name!==$c['database']) {throw new LogicException('Database identity mismatch');}`;
    const query = code => JSON.parse(execFileSync(php, ['-r', guard + code], { cwd: root, env, encoding: 'utf8', windowsHide: true }));
    assert.equal(query('echo json_encode($c["database"]);'), fixture.database);
    const audit = () => query(`$i=$db->table('purchase_invoices')->first(); echo json_encode([
        'quantity'=>(int)$db->table('inventories')->sum('quantity'), 'value'=>(int)$db->table('inventories')->sum('value_minor'),
        'status'=>$i?->status, 'total'=>(int)($i?->total_minor ?? 0), 'items'=>$db->table('purchase_invoice_items')->count(),
        'payments'=>$db->table('supplier_payments')->count(), 'movements'=>$db->table('stock_movements')->where('movement_type','purchase')->count(),
        'unbalanced'=>$db->table('journal_lines')->select('journal_entry_id')->groupBy('journal_entry_id')->havingRaw('SUM(debit_minor) <> SUM(credit_minor)')->get()->count()
    ]);`);
    const server = spawn(php, ['-S', `127.0.0.1:${port}`, path.join(root, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], { cwd: path.join(root, 'public'), env, windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'] });
    let serverLog = ''; server.stderr.on('data', chunk => { serverLog = (serverLog + chunk).slice(-5000); });
    let chrome;
    const screenshots = [];
    try {
        for (let attempt = 0; attempt < 50; attempt++) {
            try { if ((await fetch(base + '/login')).ok) break; } catch {}
            await new Promise(resolve => setTimeout(resolve, 150));
        }
        chrome = await openChrome('purchase-editor');
        const evaluate = chrome.evaluate;
        const input = async (selector, value) => evaluate(`(()=>{const input=document.querySelector(${JSON.stringify(selector)});input.value=${JSON.stringify(String(value))};input.dispatchEvent(new Event('input',{bubbles:true}));input.dispatchEvent(new Event('change',{bubbles:true}));})()`);
        const click = selector => evaluate(`document.querySelector(${JSON.stringify(selector)}).click()`);
        const shot = async (name, width) => {
            await chrome.viewport(width); await evaluate('document.fonts.ready');
            const size = await evaluate('({width:innerWidth,scroll:document.documentElement.scrollWidth})');
            assert.ok(size.scroll <= size.width + 1, JSON.stringify(size));
            screenshots.push(await chrome.screenshot(name + '-' + width));
        };
        await chrome.viewport(1440); await chrome.navigate(base + '/login');
        await input('[name=username]', fixture.username); await input('[name=password]', fixture.password);
        await evaluate('document.querySelector("form").requestSubmit()');
        await chrome.until('!location.pathname.includes("login") && document.readyState === "complete"');
        await chrome.navigate(base + '/purchases/create');
        await input('#supplier-search', 'مورد تجربة');
        await chrome.until('!document.querySelector("#supplier-results").hidden && !!document.querySelector("#supplier-results button")');
        await click('#supplier-results button');
        await input('#product-search', 'PURCHASE-DEMO');
        await chrome.until('!document.querySelector("#product-results").hidden && !!document.querySelector("#product-results button")');
        await click('#product-results button');
        await chrome.until('document.querySelector("#purchase-variants-dialog").open && !document.querySelector("#purchase-add-variants").disabled');
        await click('#purchase-variants-rows input[type=checkbox]');
        await input('#purchase-variants-rows input[type=number]', 2); await input('#purchase-variants-rows input[inputmode=decimal]', '10');
        await evaluate('document.querySelector("#purchase-variants-form").requestSubmit()');
        await chrome.until('document.querySelectorAll("#purchase-rows tr[data-variant-id]").length === 1');
        await click('.purchase-product-group button');
        await chrome.until('!document.querySelector("#purchase-extend-product").disabled'); await click('#purchase-extend-product');
        await chrome.until('document.querySelector("#product-dialog").open && !document.querySelector("#quick-product-form button[type=submit]").disabled');
        for (const [type, name, code] of [['color', 'أزرق', 'BLUE'], ['color', 'أخضر', 'GREEN'], ['size', 'XL', 'XL']]) {
            await click(`[data-add-attribute=${type}]`); await input('#purchase-attribute-name', name); await input('#purchase-attribute-code', code);
            await click('#purchase-attribute-save'); await chrome.until('document.querySelector("#purchase-attribute-editor").hidden');
        }
        await evaluate(`for(const id of ['new-product-colors','new-product-sizes']){const select=document.getElementById(id);for(const option of select.options)option.selected=true;select.dispatchEvent(new Event('change',{bubbles:true}));}`);
        assert.equal(await evaluate('document.querySelectorAll("#new-product-combinations tr").length'), 6);
        await evaluate(`for(const row of document.querySelectorAll('#new-product-combinations tr')){for(const [selector,value] of [['input[type=number]','2'],['input[inputmode=decimal]','10']]){const input=row.querySelector(selector);input.value=value;input.dispatchEvent(new Event('input',{bubbles:true}));}} const row=[...document.querySelectorAll('#new-product-combinations tr')].find(row=>row.textContent.includes('تركيبة جديدة'));for(const [selector,value] of [['input[type=number]','3'],['input[inputmode=decimal]','11']]){const input=row.querySelector(selector);input.value=value;input.dispatchEvent(new Event('input',{bubbles:true}));}`);
        await shot('purchase-editor-combinations', 1440); await shot('purchase-editor-combinations', 390);
        await evaluate('document.querySelector("#quick-product-form").requestSubmit()');
        await chrome.until('!document.querySelector("#product-dialog").open');
        assert.equal(await evaluate('document.querySelectorAll("#purchase-rows tr[data-variant-id]").length'), 6);
        await chrome.until('document.querySelector("#preview-total").textContent === "133.00"');
        await click('.purchase-product-group button'); await chrome.until('!document.querySelector("#purchase-add-variants").disabled');
        assert.equal(await evaluate('document.querySelectorAll("#purchase-variants-rows input[type=checkbox]:disabled").length'), 6);
        await evaluate('document.querySelector("#purchase-variants-form").requestSubmit()');
        assert.equal(await evaluate('document.querySelector("#purchase-variants-error").hidden'), false);
        await click('#purchase-variants-dialog [data-close-dialog]');
        await click('#add-product'); await chrome.until('!document.querySelector("#quick-product-form button[type=submit]").disabled');
        await input('#new-product-name', 'منتج جديد للتجربة'); await input('#new-product-code', 'BROWSER-NEW');
        await input('#new-product-category', fixture.category_id); await input('#new-product-price', '20');
        await evaluate(`for(const id of ['new-product-colors','new-product-sizes']){const select=document.getElementById(id);select.options[0].selected=true;select.dispatchEvent(new Event('change',{bubbles:true}));}`);
        await input('#new-product-combinations input[inputmode=decimal]', '5');
        await evaluate('document.querySelector("#quick-product-form").requestSubmit()'); await chrome.until('!document.querySelector("#product-dialog").open');
        assert.equal(await evaluate('document.querySelectorAll("#purchase-rows tr[data-variant-id]").length'), 7);
        await evaluate('[...document.querySelectorAll("#purchase-rows tr[data-variant-id]")].at(-1).querySelector(".line-remove").click()');
        await input('#initial-payment', '20'); await input('#payment-account', fixture.account_id);
        await chrome.until('document.querySelector("#preview-remaining").textContent === "113.00"');
        assert.equal(await evaluate('document.querySelector("#preview-quantity").textContent'), '13');
        assert.equal(await evaluate('document.querySelector("#preview-paid").textContent'), '20.00');
        await shot('purchase-editor-invoice', 1440); await shot('purchase-editor-invoice', 390);
        await evaluate('document.querySelector("#purchase-form").requestSubmit()');
        await chrome.until('/^\\/purchases\\/\\d+$/.test(location.pathname) && document.readyState === "complete"');
        const draft = audit(); assert.equal(draft.status, 'draft'); assert.equal(draft.quantity, 4); assert.equal(draft.value, 8000); assert.equal(draft.payments, 0); assert.equal(draft.movements, 0);
        await click('[data-open-dialog="approve-dialog"]'); await evaluate('document.querySelector("#approve-dialog form").requestSubmit()');
        await chrome.until('!document.querySelector("[data-open-dialog=approve-dialog]") && document.body.innerText.includes("معتمدة")');
        const approved = audit(); assert.equal(approved.status, 'approved'); assert.equal(approved.quantity, 17); assert.equal(approved.value, 21300); assert.equal(approved.total, 13300); assert.equal(approved.payments, 1); assert.equal(approved.movements, 6); assert.equal(approved.unbalanced, 0);
        await shot('purchase-editor-approved', 1440);
        assert.deepEqual(chrome.errors, []); assert.deepEqual(chrome.network.filter(row => row.host === '127.0.0.1' && row.status >= 400), []);
        await fs.writeFile(path.join(root, 'storage/app/reports/purchase-editor-browser-20261113.json'), JSON.stringify({ database: fixture.database, actual_browser: 'Chrome live HTTP', widths: [1440, 390], screenshots, draft, approved, js_errors: chrome.errors, network: chrome.network, physical_phone_tested: false }, null, 2));
        console.log(JSON.stringify({ screenshots: screenshots.length, draft, approved, js_errors: 0, http_errors: 0 }));
    } catch (error) {
        if (chrome) { await chrome.screenshot('purchase-editor-failure'); console.error(await chrome.evaluate('document.body.innerText.slice(-1800)')); }
        console.error(serverLog.slice(-1500)); throw error;
    } finally { chrome?.close(); server.kill(); }
})().catch(error => { console.error(error.stack); process.exitCode = 1; });
