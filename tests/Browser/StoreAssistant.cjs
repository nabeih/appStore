const fs = require('node:fs/promises');
const path = require('node:path');
const http = require('node:http');
const assert = require('node:assert/strict');
const { openChrome } = require('./ChromeSession.cjs');

(async () => {
    const root = path.resolve(__dirname, '../..');
    const fixture = JSON.parse(await fs.readFile(path.join(root, 'storage/framework/testing/store-assistant-browser.json'), 'utf8'));
    const requests = [], errors = [], checks = [];
    const html = fixture.html.replace(/<script\b[^>]*src=["']([^"']+)["'][^>]*>\s*<\/script>/g, (tag, src) => /\/(guidance|core)\.js/.test(src) ? tag : '')
        .replace(/<link\b[^>]*href=["'](https?:[^"']+)["'][^>]*>/g, (tag, href) => href.includes('/assets/') ? tag : '')
        .replace(/https?:\/\/[^/"']+(\/assets\/)/g, '$1')
        .replace('</body>', '<script src="/assets/admin/js/pages/sales/core.js" defer></script></body>');
    const server = http.createServer(async (req, res) => {
        try {
            const url = new URL(req.url, 'http://127.0.0.1');
            const json = (value, status = 200) => { res.writeHead(status, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(value)); };
            if (url.pathname === '/') { res.setHeader('Content-Type', 'text/html; charset=utf-8'); res.end(html); return; }
            if (url.pathname === '/help/ask') {
                let body = ''; for await (const chunk of req) body += chunk;
                const data = JSON.parse(body); requests.push(data); json(data.code ? fixture.problemAnswer : fixture.answer); return;
            }
            if (url.pathname === '/problem') { json({ message: fixture.problem.message, errors: { rows: [fixture.problem.message] }, guidance: fixture.problem }, 422); return; }
            if (url.pathname.startsWith('/assets/')) {
                const target = path.resolve(root, 'public', '.' + url.pathname);
                if (!target.startsWith(path.join(root, 'public/assets') + path.sep)) throw Error('Invalid asset path');
                res.setHeader('Content-Type', ({ '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml' })[path.extname(target)] || 'application/octet-stream');
                res.end(await fs.readFile(target)); return;
            }
            if (url.pathname === '/favicon.ico') { res.writeHead(204); res.end(); return; }
            errors.push(req.method + ' ' + url.pathname); res.writeHead(404); res.end();
        } catch (error) { errors.push(error.message); res.writeHead(500); res.end(); }
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const base = 'http://127.0.0.1:' + server.address().port;
    let chrome;
    try {
        chrome = await openChrome('store-assistant');
        for (const width of [1280, 390]) {
            await chrome.viewport(width); await chrome.navigate(base + '/');
            await chrome.until('!!window.StoreAssistant && !!window.SalesUI');
            await chrome.evaluate('document.querySelector("[data-assistant-topic=readiness]").click()');
            await chrome.until('document.querySelector("#store-assistant-answer").textContent.includes("إكمال إعداد المبيعات")');
            assert.equal(await chrome.evaluate('document.querySelector("#store-assistant-dialog").open'), true);
            assert.equal(await chrome.evaluate('document.querySelector("#store-assistant-dialog").scrollWidth <= document.querySelector("#store-assistant-dialog").clientWidth + 1'), true);
            await chrome.evaluate('document.querySelector("[data-assistant-close]").click()');
            await chrome.evaluate(`(async () => { const root = document.createElement('section'); root.innerHTML = '<div data-sales-errors tabindex="-1" role="alert"></div>'; document.querySelector('main').append(root); try { await SalesUI.request('/problem', {method:'POST', data:{}}); } catch (error) { SalesUI.error(root, error); } })()`);
            await chrome.until('!!document.querySelector("[data-sales-errors] [data-assistant-error-help]")');
            await chrome.evaluate('document.querySelector("[data-sales-errors] [data-assistant-error-help]").click()');
            await chrome.until('document.querySelector("#store-assistant-answer").textContent.includes("الكمية لا تكفي")');
            assert.equal(requests.at(-1).code, 'stock.insufficient');
            assert.equal(requests.at(-1).branch_id, fixture.answer.context.branch.id);
            assert.equal(await chrome.evaluate('[...document.querySelectorAll("#store-assistant-answer a")].every(a=>a.origin===location.origin)'), true);
            await chrome.evaluate('document.querySelector("#store-assistant-question").value="كيف أبدأ البيع؟"; document.querySelector("#store-assistant-form").requestSubmit()');
            await chrome.until('document.querySelector("#store-assistant-answer").textContent.includes("إكمال إعداد المبيعات")');
            assert.equal(requests.at(-1).question, 'كيف أبدأ البيع؟'); assert.equal(requests.at(-1).code, null);
            await chrome.screenshot('store-assistant-' + width);
            checks.push({ width, dialog: true, error_context: true, question: true, internal_links: true, horizontal_overflow: false });
        }
        assert.deepEqual(errors, []);
        assert.deepEqual(chrome.errors, []);
        assert.equal(chrome.network.some(request => !['127.0.0.1'].includes(request.host)), false);
        const report = { mode: 'Chrome using Laravel-rendered fixture and tested response fixtures; not a live database browser session', checks, requests: requests.length, console_errors: chrome.errors, unexpected_requests: errors };
        await fs.writeFile(path.join(root, 'storage/app/reports/store-assistant-browser.json'), JSON.stringify(report, null, 2));
        console.log(JSON.stringify(report, null, 2));
    } finally { chrome?.close(); await new Promise(resolve => server.close(resolve)); }
})().catch(error => { console.error(error); process.exitCode = 1; });
