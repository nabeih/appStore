(() => {
    'use strict';
    const form = document.getElementById('opening-import-form');
    if (!form) return;
    const preview = form.querySelector('[data-import-preview]'), failure = form.querySelector('[data-import-error]');
    let token = null, busy = false;
    form.addEventListener('change', event => {
        if (event.target.name !== 'confirmed') { token = null; preview.hidden = true; form.elements.confirmed.checked = false; }
    });
    async function send(save) {
        if (busy || !form.reportValidity()) return;
        if (save && (!token || !form.elements.confirmed.checked)) { failure.textContent = 'أعد المعاينة وأكد المراجعة أولًا.'; failure.hidden = false; return; }
        busy = true; failure.hidden = true;
        const body = new FormData(form);
        if (save) body.set('token', token);
        form.querySelectorAll('button, fieldset').forEach(node => { node.disabled = true; });
        try {
            const response = await fetch(save ? form.dataset.storeUrl : form.dataset.previewUrl, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
            const result = await response.json();
            if (!response.ok) throw Error(Object.values(result.errors || {}).flat().join(' ') || result.message || 'تعذر إتمام العملية؛ لم تُعتمد البضاعة.');
            if (save) {
                const target = form.querySelector('[data-import-result]'); target.replaceChildren();
                const message = document.createElement('p'); message.textContent = result.message; target.append(message);
                for (const documentInfo of result.documents) {
                    const item = document.createElement('p'), link = document.createElement('a');
                    link.href = documentInfo.url; link.textContent = `${documentInfo.number} — ${{ approved: 'معتمد سابقًا', cancelled: 'ملغى؛ لا يُستورد مجددًا', draft: 'مسودة' }[documentInfo.status] || documentInfo.status} — ${documentInfo.quantity} قطعة — ${documentInfo.cost} ₪`;
                    item.append(link); target.append(item);
                }
            } else {
                token = result.token; preview.hidden = false;
                form.querySelector('[data-import-totals]').textContent = `${result.row_count} سطر، ${result.total_quantity} قطعة، ${result.total_cost} ₪، ${result.documents} مستند.`;
                const rows = form.querySelector('[data-import-rows]'); rows.replaceChildren();
                for (const row of result.rows) {
                    const tr = document.createElement('tr');
                    for (const key of ['product_name', 'category', 'color', 'size', 'opening_quantity', 'unit_cost', 'selling_price']) {
                        const td = document.createElement('td'); td.textContent = row[key]; tr.append(td);
                    }
                    rows.append(tr);
                }
            }
        } catch (error) { failure.textContent = error.message; failure.hidden = false; }
        finally { busy = false; form.querySelectorAll('button, fieldset').forEach(node => { node.disabled = false; }); }
    }
    form.addEventListener('submit', event => { event.preventDefault(); send(false); });
    form.querySelector('[data-import-save]').addEventListener('click', () => send(true));
})();
