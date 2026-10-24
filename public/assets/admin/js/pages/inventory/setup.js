(() => {
    'use strict';
    const config = document.getElementById('inventory-setup-config');
    if (!config) return;
    const settings = JSON.parse(config.textContent);
    const csrf = settings.csrf;
    const el = id => document.getElementById(id);
    const dialog = el('inventorySetupDialog');
    let selected = null, mode = 'edit', busy = false, feedbackTimer;
    function setBusy(value) {
        busy = value;
        el('inventorySetupFields').disabled = value;
        el('inventorySetupSubmit').disabled = value;
        dialog.querySelectorAll('[data-setup-close]').forEach(button => { button.disabled = value; });
        el('inventorySetupStatus').textContent = value ? 'جاري حفظ التصحيح...' : '';
    }
    function error(message) {
        const box = el('inventorySetupErrors');
        box.textContent = message;
        box.hidden = !message;
        if (message) box.focus();
    }
    function feedback(message) {
        clearTimeout(feedbackTimer);
        el('inventorySetupFeedback').textContent = message;
        el('inventorySetupFeedback').hidden = false;
        feedbackTimer = setTimeout(() => { el('inventorySetupFeedback').hidden = true; }, 7000);
    }
    document.addEventListener('click', async event => {
        const button = event.target.closest('[data-inventory-action]');
        if (!button || busy) return;
        selected = JSON.parse(button.closest('[data-inventory-details]').dataset.inventoryDetails);
        mode = button.dataset.inventoryAction;
        el('inventorySetupTitle').textContent = mode === 'delete' ? 'حذف سطر المخزون' : 'تصحيح بيانات المخزون';
        el('inventorySetupProduct').textContent = `${selected.code} — ${selected.name}`;
        el('inventorySetupScope').textContent = `${selected.branch} • ${selected.color} • ${selected.size} • الكمية الحالية: ${selected.quantity}`;
        el('inventorySetupName').value = selected.name;
        el('inventorySetupCode').value = selected.code;
        el('inventorySetupQuantity').value = selected.quantity;
        el('inventorySetupCost').value = selected.average_cost;
        el('inventorySetupPrice').value = selected.selling_price;
        el('inventorySetupReason').value = '';
        el('inventorySetupEditFields').hidden = mode === 'delete';
        el('inventorySetupEditFields').querySelectorAll('input, select').forEach(input => { input.required = mode === 'edit'; input.disabled = mode === 'delete'; });
        el('inventorySetupPriceHint').hidden = mode === 'delete';
        el('inventorySetupDeleteWarning').hidden = mode !== 'delete';
        el('inventorySetupSubmit').textContent = mode === 'delete' ? 'تأكيد الحذف' : 'حفظ التعديل';
        el('inventorySetupSubmit').className = `btn ${mode === 'delete' ? 'btn-danger' : 'btn-primary'}`;
        error(''); dialog.showModal();
        if (mode === 'edit') {
            setBusy(true);
            el('inventorySetupStatus').textContent = 'جاري تحميل الخيارات...';
            let optionsLoaded = false;
            try {
                const response = await fetch(settings.options_url, { headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error('تعذر تحميل القوائم. أغلق النافذة وحاول مجددًا.');
                const options = await response.json();
                for (const [id, key, field, label] of [
                    ['inventorySetupCategory', 'categories', 'category_id', 'category'],
                    ['inventorySetupColor', 'colors', 'color_id', 'color'],
                    ['inventorySetupSize', 'sizes', 'size_id', 'size'],
                ]) {
                    const select = el(id);
                    select.replaceChildren(new Option('اختر...', ''), ...options[key].map(item => new Option(item.name, item.id)));
                    if (!options[key].some(item => String(item.id) === String(selected[field]))) select.add(new Option(`${selected[label]} (غير فعال)`, selected[field]));
                    select.value = selected[field];
                }
                optionsLoaded = true;
            } catch (failure) { error(failure.message); }
            finally { setBusy(false); el('inventorySetupSubmit').disabled = !optionsLoaded; }
        } else { setBusy(false); }
    });
    dialog.querySelectorAll('[data-setup-close]').forEach(button => button.addEventListener('click', () => { if (!busy) dialog.close(); }));
    dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
    window.addEventListener('beforeunload', event => { if (busy) { event.preventDefault(); event.returnValue = ''; } });

    async function refreshTable() {
        const search = el('inventorySearch')?.value || '';
        const quantity = el('inventoryQuantityFilter')?.value || 'all';
        const url = new URL(window.location.href);
        let panel;
        for (let attempt = 0; attempt < 2; attempt++) {
            const response = await fetch(url, { headers: { Accept: 'text/html' } });
            if (!response.ok) throw new Error('تعذر تحديث الجدول.');
            panel = new DOMParser().parseFromString(await response.text(), 'text/html').getElementById('purchSubtab-stock');
            if (!panel) throw new Error('تعذر تحديث الجدول.');
            const page = Number(url.searchParams.get('page') || 1);
            if (!panel.querySelector('[data-inventory-row]') && page > 1 && attempt === 0) url.searchParams.set('page', String(page - 1));
            else break;
        }
        el('purchSubtab-stock').replaceWith(panel);
        el('inventorySearch').value = search;
        el('inventoryQuantityFilter').value = quantity;
        window.history.replaceState(null, '', url);
        document.dispatchEvent(new Event('inventory:refreshed'));
    }
    el('inventorySetupForm').addEventListener('submit', async event => {
        event.preventDefault();
        if (!selected || busy) return;
        const payload = { revision: selected.revision, reason: el('inventorySetupReason').value.trim() };
        if (mode === 'edit') {
            payload.name = el('inventorySetupName').value.trim();
            payload.code = el('inventorySetupCode').value.trim().toUpperCase();
            payload.category_id = el('inventorySetupCategory').value;
            payload.color_id = el('inventorySetupColor').value;
            payload.size_id = el('inventorySetupSize').value;
            payload.quantity = el('inventorySetupQuantity').value;
            payload.average_cost = el('inventorySetupCost').value;
            payload.selling_price = el('inventorySetupPrice').value;
        }
        setBusy(true); error('');
        let result;
        try {
            const response = await fetch(mode === 'delete' ? selected.delete_url : selected.update_url, {
                method: mode === 'delete' ? 'DELETE' : 'PUT',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify(payload),
            });
            result = await response.json().catch(() => ({}));
            if (!response.ok) {
                const message = response.status === 403 ? 'انتهت مرحلة التعديل المباشر للمخزون.' : response.status === 419 ? 'انتهت صلاحية الصفحة. حدّث الصفحة ثم أعد المحاولة.' : response.status === 404 ? 'السطر لم يعد موجودًا. حدّث الجدول.' : Object.values(result.errors || {}).flat().join('\n') || result.message || 'تعذر حفظ التصحيح.';
                throw new Error(message);
            }
        } catch (failure) {
            error(failure instanceof TypeError ? 'تعذر الاتصال بالخادم. حدّث الجدول للتحقق من النتيجة قبل إعادة المحاولة.' : failure.message);
            setBusy(false); return;
        }
        dialog.close();
        try { await refreshTable(); feedback(result.message); }
        catch { feedback(`${result.message} حدّث الصفحة لعرض الجدول المحدث.`); }
        finally { setBusy(false); selected = null; }
    });
})();