(() => {
    'use strict';
    const config = JSON.parse(document.getElementById('customer-receipt-config').textContent), ui = SalesUI, el = id => document.getElementById(id), form = el('customer-receipt-form');
    if (!form) return;
    const clientUuid = ui.uuid(); let busy = false, dirty = false;
    const nonzero = value => value && !/^0+(?:\.0+)?$/.test(value.trim());
    function addPayment() { if (el('customer-receipt-payments').children.length < 10) el('customer-receipt-payments').append(ui.paymentRow(config.accounts)); }
    el('customer-receipt-add-payment').addEventListener('click',addPayment); addPayment();
    el('customer-receipt-kind').addEventListener('change',() => {
        const kind = el('customer-receipt-kind').value; el('receipt-invoice-section').hidden = kind === 'refund';
        el('receipt-credit-section').hidden = kind === 'receipt'; el('receipt-payment-section').hidden = kind === 'credit'; dirty = true;
    });
    el('receipt-auto-allocate').addEventListener('click',async event => {
        const button = event.currentTarget; if (button.disabled) return; button.disabled = true; ui.error(form,'');
        try {
            const result = await ui.request(config.previewUrl,{ method: 'POST',data: { customer_id: config.customerId,branch_id: config.branchId,amount: el('receipt-auto-amount').value.trim() } });
            form.querySelectorAll('[data-allocation-amount]').forEach(input => input.value = '');
            result.allocations.forEach(allocation => { const row = form.querySelector('[data-allocate-invoice="'+allocation.sale_invoice_id+'"]'); if (row) row.querySelector('[data-allocation-amount]').value = allocation.amount; });
            el('receipt-allocation-note').textContent = result.message; dirty = true;
        } catch (failure) { ui.error(form,failure); } finally { button.disabled = false; }
    });
    form.addEventListener('input',() => dirty = true);
    form.addEventListener('submit',async event => {
        event.preventDefault(); if (busy) return; const kind = el('customer-receipt-kind').value;
        const allocations = kind === 'refund' ? [] : [...form.querySelectorAll('[data-allocate-invoice]')].map(row => ({ sale_invoice_id: Number(row.dataset.allocateInvoice),amount: row.querySelector('[data-allocation-amount]').value.trim() })).filter(row => nonzero(row.amount));
        const credits = kind === 'receipt' ? [] : [...form.querySelectorAll('[data-use-credit]')].map(row => ({ sale_return_id: Number(row.dataset.useCredit),amount: row.querySelector('[data-credit-amount]').value.trim() })).filter(row => nonzero(row.amount));
        const data = { client_uuid: clientUuid,branch_id: config.branchId,customer_id: config.customerId,kind,allocations,credits,payments: kind === 'credit' ? [] : ui.payments(el('customer-receipt-payments')),notes: el('customer-receipt-notes').value.trim() || null };
        busy = true; el('customer-receipt-fields').disabled = true; ui.error(form,'');
        try { const result = await ui.request(config.storeUrl,{ method: 'POST',data }); dirty = false; busy = false; location.assign(result.url); }
        catch (failure) { ui.error(form,failure); busy = false; el('customer-receipt-fields').disabled = false; }
    });
    window.addEventListener('beforeunload',event => { if (dirty || busy) { event.preventDefault(); event.returnValue = ''; } });
})();
