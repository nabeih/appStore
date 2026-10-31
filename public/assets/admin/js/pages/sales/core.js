(() => {
    'use strict';
    const csrf = document.querySelector('meta[name=csrf-token]')?.content;
    const element = (tag, className = '', text) => {
        const node = document.createElement(tag); if (className) node.className = className;
        if (text !== undefined) node.textContent = text; return node;
    };
    const uuid = () => {
        if (crypto.randomUUID) return crypto.randomUUID();
        const bytes = crypto.getRandomValues(new Uint8Array(16)); bytes[6] = (bytes[6] & 15) | 64; bytes[8] = (bytes[8] & 63) | 128;
        const hex = [...bytes].map(value => value.toString(16).padStart(2, '0')).join('');
        return [hex.slice(0,8),hex.slice(8,12),hex.slice(12,16),hex.slice(16,20),hex.slice(20)].join('-');
    };
    async function request(url, options = {}) {
        const { data, ...init } = options;
        const response = await fetch(url, { ...init, body: data === undefined ? init.body : JSON.stringify(data),
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf, ...(data === undefined ? {} : { 'Content-Type': 'application/json' }), ...init.headers } });
        const result = await response.json().catch(() => ({}));
        if (!response.ok) {
            const message = response.status === 419 || response.status === 401 ? 'انتهت الجلسة. افتح تسجيل الدخول في تبويب آخر ثم أعد المحاولة مع إبقاء هذه الصفحة.'
                : Object.values(result.errors || {}).flat().join('\n') || result.message || 'تعذر إكمال الطلب. احتفظ بالصفحة وأعد المحاولة.';
            throw Object.assign(new Error(message), { fields: result.errors || {}, status: response.status });
        }
        return result;
    }
    function error(root, message) {
        const box = root.querySelector('[data-sales-errors]'); if (!box) return;
        box.textContent = message instanceof Error ? message.message : message || ''; box.hidden = !message;
        if (message) box.focus();
    }
    function paymentRow(accounts, initial = {}) {
        const row = element('div', 'sale-payment-row'); row.dataset.paymentRow = '';
        const accountLabel = element('label', '', 'الحساب الفعلي'); const select = element('select'); select.dataset.paymentAccount = '';
        select.append(new Option('اختر الحساب', ''));
        accounts.forEach(account => { const option = new Option(account.name, account.id); option.dataset.type = account.type; select.append(option); });
        select.value = initial.account_id || accounts[0]?.id || ''; accountLabel.append(select);
        const amountLabel = element('label', '', 'المبلغ / المسلّم نقدًا'); const amount = element('input'); amount.type = 'text'; amount.inputMode = 'decimal';
        amount.dataset.paymentAmount = ''; amount.value = initial.amount ?? ''; amount.placeholder = 'كامل المبلغ'; amountLabel.append(amount);
        const remove = element('button', '', '×'); remove.type = 'button'; remove.setAttribute('aria-label', 'إزالة وسيلة القبض');
        remove.addEventListener('click', () => { row.remove(); row.dispatchEvent(new Event('removed')); });
        const confirmedLabel = element('label', 'sale-check'); const confirmed = element('input'); confirmed.type = 'checkbox'; confirmed.dataset.paymentConfirmed = '';
        confirmed.checked = !!initial.confirmed; confirmedLabel.append(confirmed, document.createTextNode('أؤكد وصول التحويل للحساب؛ الإشعار وحده لا يكفي.'));
        const referenceLabel = element('label', 'sale-payment-reference', 'مرجع التحويل (اختياري)'); const reference = element('input');
        reference.type = 'text'; reference.maxLength = 150; reference.dataset.paymentReference = ''; reference.value = initial.reference || ''; referenceLabel.append(reference);
        const change = () => { const cash = select.selectedOptions[0]?.dataset.type === 'cash'; confirmedLabel.hidden = cash; referenceLabel.hidden = cash; };
        select.addEventListener('change', change); change(); row.append(accountLabel, amountLabel, remove, confirmedLabel, referenceLabel); return row;
    }
    function payments(container) {
        if (!container) return [];
        return [...container.querySelectorAll('[data-payment-row]')].filter(row => row.querySelector('[data-payment-account]').value)
            .map(row => ({ account_id: Number(row.querySelector('[data-payment-account]').value), amount: row.querySelector('[data-payment-amount]').value.trim() || null,
                confirmed: row.querySelector('[data-payment-confirmed]')?.checked || false, reference: row.querySelector('[data-payment-reference]')?.value.trim() || null }));
    }
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-close-sale-dialog]'); if (button) button.closest('dialog').close();
        if (event.target.closest('[data-print-document]')) window.print();
    });
    document.addEventListener('submit', async event => {
        const form = event.target.closest('[data-simple-form]'); if (!form) return;
        event.preventDefault(); if (form.dataset.busy) return;
        const data = Object.fromEntries(new FormData(form)); delete data._token; delete data._method;
        ['goods_confirmed', 'funds_confirmed', 'confirm_opening', 'confirmed'].forEach(key => { const input = form.elements.namedItem(key); if (input?.type === 'checkbox') data[key] = input.checked; });
        if (form.querySelector('[data-refund-rows]')) data.refund_payments = payments(form.querySelector('[data-refund-rows]'));
        form.dataset.busy = '1'; error(form, '');
        form.querySelectorAll('fieldset, button').forEach(node => node.disabled = true);
        try {
            const result = await request(form.action, { method: form.dataset.method || 'POST', data });
            if (result.url) location.assign(result.url);
            else { form.dispatchEvent(new CustomEvent('sales:saved', { detail: result })); const status = form.querySelector('[data-sales-status]'); if (status) { status.textContent = result.message || 'تم الحفظ.'; status.hidden = false; } }
        } catch (failure) { error(form, failure); }
        finally { delete form.dataset.busy; form.querySelectorAll('fieldset, button').forEach(node => node.disabled = false); }
    });
    window.SalesUI = { element, uuid, request, error, paymentRow, payments };
})();
