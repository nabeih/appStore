(() => {
    'use strict';
    const config = JSON.parse(document.getElementById('adjustment-config').textContent), ui = SalesUI, el = id => document.getElementById(id), form = el('adjustment-form');
    const exchange = config.mode === 'exchange', clientUuid = ui.uuid(), rows = [];
    let customerId = config.customerId, timer, request, version = 0, reviewed = false, busy = false, dirty = false, searchTimer, searchRequest, customerTimer, customerRequest;
    function payment(container, initial = {}) {
        if (container.children.length >= 10) return;
        const row = ui.paymentRow(config.accounts,initial); row.addEventListener('removed',invalidate); container.append(row);
    }
    function refundMode() {
        const credit = el('adjustment-refund-mode').value === 'credit';
        el('adjustment-refunds').hidden = credit; el('adjustment-add-refund').hidden = credit; invalidate();
    }
    function payload() {
        const returns = [...form.querySelectorAll('[data-return-item]')].map(row => ({ sale_item_id: Number(row.dataset.returnItem),
            quantity: Number(row.querySelector('[data-return-quantity]').value), condition: row.querySelector('[data-return-condition]').value })).filter(row => row.quantity > 0);
        const common = { client_uuid: clientUuid, customer_id: customerId, reason: el('adjustment-reason').value.trim(), refund_payments: el('adjustment-refund-mode').value === 'refund' ? ui.payments(el('adjustment-refunds')) : [] };
        if (!exchange) return { ...common, rows: returns };
        let payments = ui.payments(el('exchange-payments'));
        if (el('exchange-settlement-mode').value === 'credit') payments = payments.filter(part => part.amount !== null);
        return { ...common, return_rows: returns, rows: rows.map(row => ({ product_variant_id: row.product_variant_id, quantity: row.quantity,
            unit_price: row.unit_price, discount: { type: 'amount', value: row.discount || '0' } })), settlement_mode: el('exchange-settlement-mode').value, payments };
    }
    function invalidate() {
        reviewed = false; dirty = true; version++; clearTimeout(timer); request?.abort(); el('adjustment-submit').disabled = true;
        el('adjustment-summary').replaceChildren(); el('adjustment-preview-status').textContent = 'جارٍ مراجعة التسوية...';
        if (!busy) timer = setTimeout(preview,400);
    }
    async function preview() {
        const current = version, controller = new AbortController(); request = controller;
        const labels = { total: 'قيمة المرتجع', debt_reduction: 'تخفيض الدين', credit_created: 'مبلغ لصالح العميل', refund: 'رد الأموال',
            credit_remaining: 'الرصيد الدائن المتبقي', due_after: 'المستحق بعد المرتجع', return_total: 'قيمة القطع القديمة', old_debt_after_return: 'الدين السابق بعد المرتجع',
            new_sale_total: 'صافي البدائل', credit_applied: 'رصيد خُصص للبدائل', full_due_before_payment: 'المركز الكامل قبل القبض',
            received: 'المقبوض للفاتورتين', change: 'الباقي النقدي للعميل', remaining_due: 'المستحق بعد التسوية', refunded: 'رد الأموال', remaining_credit: 'رصيد متبقٍ للعميل', gross_profit_change: 'التغير في مجمل الربح' };
        try {
            const result = await ui.request(config.previewUrl,{ method: 'POST', data: payload(), signal: controller.signal }); if (current !== version) return;
            const container = el('adjustment-summary'); container.replaceChildren();
            Object.entries(result).forEach(([key,value]) => { if (!labels[key]) return; const cell = ui.element('div','',labels[key]); cell.append(ui.element('strong','',value+' ₪')); container.append(cell); });
            reviewed = true; el('adjustment-submit').disabled = busy; el('adjustment-preview-status').textContent = 'راجع التسوية ثم أكد استلام القطع وتسليم البدائل عند وجودها.';
        } catch (failure) { if (failure.name !== 'AbortError' && current === version) el('adjustment-preview-status').textContent = failure.message; }
    }
    el('adjustment-add-refund').addEventListener('click',() => { payment(el('adjustment-refunds')); invalidate(); });
    el('adjustment-refund-mode').addEventListener('change',refundMode);
    payment(el('adjustment-refunds')); el('adjustment-refunds').hidden = el('adjustment-refund-mode').value === 'credit';
    el('adjustment-add-refund').hidden = el('adjustment-refund-mode').value === 'credit';
    form.addEventListener('input',event => { if (!['exchange-product-search','adjustment-customer-search'].includes(event.target.id)) invalidate(); });
    form.addEventListener('change',invalidate);
    if (el('adjustment-customer-search')) {
        el('adjustment-customer-search').addEventListener('input',() => {
            customerId = null; invalidate(); clearTimeout(customerTimer); customerRequest?.abort(); const q = el('adjustment-customer-search').value.trim();
            el('adjustment-customer-results').hidden = true; el('adjustment-customer-note').textContent = 'اختر العميل من النتائج.'; if (!q) return;
            customerTimer = setTimeout(async () => {
                const controller = new AbortController(); customerRequest = controller;
                try {
                    const result = await ui.request(config.customersUrl+'?'+new URLSearchParams({ q }),{ signal: controller.signal });
                    if (el('adjustment-customer-search').value.trim() !== q) return;
                    const container = el('adjustment-customer-results'); container.replaceChildren();
                    result.data.forEach(person => { const button = ui.element('button','',person.name+' — '+(person.phone || '')); button.type = 'button';
                        button.addEventListener('click',() => { customerId = person.id; el('adjustment-customer-search').value = person.name; el('adjustment-customer-note').textContent = 'العميل المختار: '+person.name; container.hidden = true; invalidate(); }); container.append(button); }); container.hidden = false;
                } catch (failure) { if (failure.name !== 'AbortError') ui.error(form,failure); }
            },250);
        });
    }
    function renderRows() {
        const container = el('exchange-lines'); container.replaceChildren();
        rows.forEach((row,index) => {
            const card = ui.element('article','cashier-line'), header = ui.element('header'); header.append(ui.element('strong','',row.name+' — '+row.color+' / '+row.size));
            const remove = ui.element('button','','×'); remove.type = 'button'; remove.dataset.removeLine = ''; remove.addEventListener('click',() => { rows.splice(index,1); renderRows(); invalidate(); }); header.append(remove); card.append(header);
            const grid = ui.element('div','sale-grid');
            const field = (text,input) => { const label = ui.element('label','',text); label.append(input); grid.append(label); };
            const quantity = ui.element('input'); quantity.type = 'number'; quantity.min = '1'; quantity.max = '1000000'; quantity.step = '1'; quantity.value = row.quantity;
            quantity.addEventListener('input',() => row.quantity = quantity.value); field('الكمية',quantity);
            const price = ui.element('input'); price.type = 'text'; price.inputMode = 'decimal'; price.value = row.unit_price; price.readOnly = !config.canChangePrice; price.addEventListener('input',() => row.unit_price = price.value); field('سعر الوحدة ₪',price);
            const discount = ui.element('input'); discount.type = 'text'; discount.inputMode = 'decimal'; discount.value = row.discount || '0'; discount.addEventListener('input',() => row.discount = discount.value); field('خصم السطر بمبلغ ₪',discount);
            card.append(grid); container.append(card);
        });
    }
    if (exchange) {
        payment(el('exchange-payments'));
        el('exchange-add-payment').addEventListener('click',() => { payment(el('exchange-payments')); invalidate(); });
        el('exchange-product-search').addEventListener('input',() => {
            clearTimeout(searchTimer); searchRequest?.abort(); const q = el('exchange-product-search').value.trim(); const container = el('exchange-product-results'); container.hidden = true; if (!q) return;
            searchTimer = setTimeout(async () => {
                const controller = new AbortController(); searchRequest = controller;
                try {
                    const result = await ui.request(config.catalogUrl+'?'+new URLSearchParams({ branch_id: config.branchId,q }),{ signal: controller.signal });
                    if (el('exchange-product-search').value.trim() !== q) return; container.replaceChildren();
                    result.data.forEach(product => product.variants.forEach(variant => {
                        const button = ui.element('button','',product.name+' — '+variant.color+' / '+variant.size+' — متاح '+variant.available+' — '+variant.unit_price+' ₪');
                        button.type = 'button'; button.disabled = variant.available <= 0;
                        button.addEventListener('click',() => {
                            const existing = rows.find(row => row.product_variant_id === variant.product_variant_id);
                            if (existing) existing.quantity = Number(existing.quantity)+1; else rows.push({ ...variant,name: product.name,quantity: 1,discount: '0' });
                            container.hidden = true; el('exchange-product-search').value = ''; renderRows(); invalidate();
                        }); container.append(button);
                    })); container.hidden = false;
                } catch (failure) { if (failure.name !== 'AbortError') ui.error(form,failure); }
            },250);
        });
        el('exchange-product-search').addEventListener('keydown',event => { if (event.key === 'Enter') event.preventDefault(); });
    }
    form.addEventListener('submit',async event => {
        event.preventDefault(); if (!reviewed || busy) return; busy = true; clearTimeout(timer); request?.abort(); ui.error(form,''); el('adjustment-fields').disabled = true;
        try { const result = await ui.request(config.storeUrl,{ method: 'POST',data: payload() }); dirty = false; busy = false; location.assign(result.url); }
        catch (failure) { ui.error(form,failure); busy = false; el('adjustment-fields').disabled = false; invalidate(); }
    });
    window.addEventListener('beforeunload',event => { if (dirty || busy) { event.preventDefault(); event.returnValue = ''; } });
})();
