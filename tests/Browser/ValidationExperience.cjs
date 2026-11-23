const fs = require('node:fs/promises');
const path = require('node:path');
const assert = require('node:assert/strict');
const { openChrome } = require('./ChromeSession.cjs');
(async () => {
    const browser = await openChrome('validation-ux');
    const report = { pages: [], assertions: 0, network: 'Offline DOM fixtures from isolated MySQL HTTP feature responses; no application server contacted.' };
    const script = await fs.readFile(path.join(__dirname, '../../public/assets/admin/js/validation.js'), 'utf8');
    const css = await fs.readFile(path.join(__dirname, '../../public/assets/admin/css/validation.css'), 'utf8');
    async function load(html) {
        await browser.send('Page.navigate', { url: 'about:blank' });
        await browser.until('document.readyState === "complete"');
        const clean = html.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, tag => /type=["']application\/json["']/.test(tag) ? tag : '').replace(/<link\b[^>]*>/gi, '').replace(/\s(?:src|srcset)="[^"]*"/gi, '');
        const { frameTree } = await browser.send('Page.getFrameTree');
        await browser.send('Page.setDocumentContent', { frameId: frameTree.frame.id, html: clean });
        await browser.evaluate(`const validationStyle = document.createElement('style'); validationStyle.textContent=${JSON.stringify(css)}; document.head.append(validationStyle);`);
        await browser.evaluate(script);
    }
    try {
        const directory = path.join(__dirname, '../../storage/framework/testing/validation-pages');
        for (const file of (await fs.readdir(directory)).filter(file => file.endsWith('.html'))) {
            await load(await fs.readFile(path.join(directory, file), 'utf8'));
            const result = await browser.evaluate(`(() => {
                let forms = 0, controls = 0;
                for (const form of document.querySelectorAll('form')) {
                    const fields = [...form.querySelectorAll('input,select,textarea')].filter(input => input.type !== 'hidden' && (input.name || input.dataset.validationField));
                    if (!fields.length) continue;
                    const values = fields.map(input => input.value);
                    const errors = Object.fromEntries(fields.map(input => [FormValidation.normalize(input.dataset.validationField || input.name), ['راجع قيمة هذا الحقل', 'أكمل البيانات المطلوبة']]));
                    FormValidation.show(form, errors, { focus: false });
                    for (let index = 0; index < fields.length; index++) {
                        const input = fields[index], id = input.getAttribute('aria-describedby').split(' ').at(-1);
                        if (input.getAttribute('aria-invalid') !== 'true' || !input.classList.contains('validation-invalid')) throw Error('Missing invalid state');
                        if (!document.getElementById(id)?.textContent.includes('أكمل البيانات المطلوبة')) throw Error('Missing field text');
                        if (input.value !== values[index]) throw Error('Input value lost');
                    }
                    controls += fields.length; forms++;
                    FormValidation.clear(form);
                    if (form.querySelector('.validation-invalid,[data-validation-message]')) throw Error('Errors not cleared');
                }
                return { forms, controls };
            })()`);
            assert.ok(result.forms > 0, file); report.pages.push({ file, ...result }); report.assertions += result.controls * 4 + result.forms;
        }
        await load(await fs.readFile(path.join(__dirname, '../../storage/framework/testing/validation-debt.html'), 'utf8'));
        await browser.until(`document.activeElement.name === 'original_date'`);
        assert.equal(await browser.evaluate(`document.querySelector('[name="original_date"]').value.length`), 10); report.assertions++;
        assert.equal(await browser.evaluate(`document.querySelector('[name="original_date"]').getAttribute('aria-describedby').includes('debt-origin-help')`), true); report.assertions++;
        report.supplierDebt = 'Real redirected validation HTML: Arabic date message, focus, preserved date and guidance.';
        // Exercise the actual purchase editor with two rows and a mocked preview response.
        await load(await fs.readFile(path.join(directory, 'purchases.invoices.create.html'), 'utf8'));
        await browser.evaluate(`delete window.FormValidation; window.fetch = async () => new Response(JSON.stringify({errors:{'rows.1.quantity':['راجع كمية السطر الثاني'], 'rows.1.unit_cost':['راجع تكلفة السطر الثاني']}}), {status:422,headers:{'content-type':'application/json'}});
            const config = JSON.parse(document.querySelector('#purchase-config').textContent); config.previewUrl='about:blank'; config.state.supplier_id=1;
            config.state.rows=[0,1].map(index => ({product_variant_id:index+1,product_id:1,color_id:index+1,size_id:1,name:'منتج اختبار',color:'لون',size:'M',sku:'TEST-'+index,quantity:1,unit_cost:'10.00'}));
            document.querySelector('#purchase-config').textContent=JSON.stringify(config);`);
        await browser.evaluate(script);
        await browser.evaluate(await fs.readFile(path.join(__dirname, '../../public/assets/admin/js/pages/catalog/color-images.js'), 'utf8'));
        await browser.evaluate(await fs.readFile(path.join(__dirname, '../../public/assets/admin/js/pages/purchases/editor.js'), 'utf8'));
        await browser.until(`document.querySelector('[data-validation-field="rows.1.quantity"][aria-invalid="true"]') !== null`);
        assert.equal(await browser.evaluate(`document.querySelector('[data-validation-field="rows.0.quantity"]').hasAttribute('aria-invalid')`), false); report.assertions++;
        assert.equal(await browser.evaluate(`document.querySelector('[data-validation-field="rows.1.unit_cost"]').getAttribute('aria-invalid')`), 'true'); report.assertions++;
        await browser.evaluate(`document.querySelector('.line-remove').click()`);
        assert.equal(await browser.evaluate(`document.querySelector('[data-validation-field="rows.0.quantity"]') !== null && document.querySelector('[data-validation-field="rows.1.quantity"]') === null`), true); report.assertions++;
        report.dynamicPurchaseEditor = 'Actual editor rendered rows, mapped 422 to second row, and reindexed after removal; responses mocked.';
        await load('<form id="one"><input name="name" value="قيمة محفوظة" aria-describedby="help"><small id="help">تعليمات</small><input name="rows[0][quantity]"><input name="rows[1][quantity]"><details><summary>تفاصيل</summary><input name="notes"></details></form><dialog><form id="two"><input name="amount"></form></dialog>');
        await browser.evaluate(`FormValidation.show(document.querySelector('#one'), {'rows.1.quantity':['أدخل كمية صحيحة'], name:['أدخل الاسم']});`);
        await browser.until(`document.activeElement.name === 'rows[1][quantity]'`); report.assertions++;
        assert.equal(await browser.evaluate(`document.querySelector('[name="rows[0][quantity]"]').hasAttribute('aria-invalid')`), false); report.assertions++;
        assert.equal(await browser.evaluate(`getComputedStyle(document.querySelector('[name="rows[1][quantity]"]')).borderTopColor`), 'rgb(180, 35, 24)'); report.assertions++;
        assert.equal(await browser.evaluate(`document.querySelector('[name="name"]').getAttribute('aria-describedby').startsWith('help ')`), true); report.assertions++;
        await browser.evaluate(`FormValidation.show(document.querySelector('#one'), {notes:['أدخل الملاحظات']});`);
        await browser.until(`document.querySelector('details').open && document.activeElement.name === 'notes'`); report.assertions++;
        await browser.evaluate(`FormValidation.show(document.querySelector('#two'), {amount:['أدخل المبلغ']});`);
        await browser.until(`document.querySelector('dialog').open && document.activeElement.name === 'amount'`); report.assertions++;
        await browser.evaluate(`document.querySelector('dialog').close(); FormValidation.show(document.querySelector('#one'), {operation:['لا يمكن اعتماد مستند مغلق']});`);
        assert.equal(await browser.evaluate(`document.querySelector('#one .validation-invalid') === null`), true); report.assertions++;
        await browser.evaluate(`FormValidation.show(document.querySelector('#one'), {name:['SQLSTATE secret App\\\\Test']});`);
        assert.equal(await browser.evaluate(`document.querySelector('[data-validation-summary]').textContent.includes('SQLSTATE')`), false); report.assertions++;
        // Same-origin fetch is mocked in Chrome; no network or financial request is sent.
        await load('<form id="ajax"><input name="rows[2][quantity]" value="0"><button>حفظ</button></form><form id="other"><input name="name"></form>');
        await browser.evaluate(`delete window.FormValidation; window.mockStatus=422; window.fetch = async () => new Response(JSON.stringify({errors:{'rows.2.quantity':['أدخل كمية أكبر من صفر']}}), {status:window.mockStatus,headers:{'content-type':'application/json'}});`);
        await browser.evaluate(script);
        await browser.evaluate(`fetch('http://validation.test/save', {method:'POST',validationScope:document.querySelector('#ajax')})`);
        // about:blank has an opaque origin. Use the same-origin data URL for interception.
        await browser.evaluate(`fetch('about:blank', {method:'POST',validationScope:document.querySelector('#ajax')})`);
        await browser.until(`document.querySelector('#ajax [aria-invalid="true"]') !== null`); report.assertions++;
        assert.equal(await browser.evaluate(`document.querySelector('#other [aria-invalid]') === null`), true); report.assertions++;
        await browser.evaluate(`(async () => { window.mockStatus=200; await fetch('about:blank', {method:'POST',validationScope:document.querySelector('#ajax')}); })()`);
        assert.equal(await browser.evaluate(`document.querySelector('#ajax [aria-invalid]') === null`), true); report.assertions++;
        await browser.evaluate(`document.querySelector('#ajax input').required=true; document.querySelector('#ajax input').value=''; document.querySelector('#ajax').reportValidity();`);
        await browser.until(`document.querySelector('#ajax [aria-invalid="true"]') !== null`); report.assertions++;
        report.consoleErrors = browser.errors; assert.equal(browser.errors.length, 0); report.assertions++;
        await fs.writeFile(path.join(__dirname, '../../storage/app/reports/validation-browser.json'), JSON.stringify(report, null, 2));
        console.log(JSON.stringify({ pages: report.pages.length, forms: report.pages.reduce((sum, page) => sum + page.forms, 0), controls: report.pages.reduce((sum, page) => sum + page.controls, 0), assertions: report.assertions, consoleErrors: browser.errors.length }));
    } finally { browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
