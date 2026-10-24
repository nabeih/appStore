(() => {
    'use strict';
    const config = JSON.parse(document.getElementById('purchase-config').textContent);
    const state = config.state;
    const form = document.getElementById('purchase-form');
    const rowsElement = document.getElementById('purchase-rows');
    const errors = document.getElementById('purchase-errors');
    const supplierSearch = document.getElementById('supplier-search');
    const productSearch = document.getElementById('product-search');
    const supplierResults = document.getElementById('supplier-results');
    const productResults = document.getElementById('product-results');
    const supplierDialog = document.getElementById('supplier-dialog');
    const productDialog = document.getElementById('product-dialog');
    const productForm = document.getElementById('quick-product-form');
    let dirty = false;
    let saving = false;
    let previewTimer;
    let previewRequest;
    let previewVersion = 0;
    let productId = null;
    let optionsRequest;
    let productCatalog = null;
    const quickImages = new Map();
    let quickExistingImages = new Map();
    const rowUploads = new Map();
    const clearQuickImages = () => {
        quickImages.forEach(image => URL.revokeObjectURL(image.preview));
        quickImages.clear();
    };
    const syncSaveButton = () => {
        document.getElementById('save-purchase').disabled = saving || [...rowUploads.values()].some(upload => upload.busy);
    };
    function renderQuickImages() {
        const colors = [...document.getElementById('new-product-colors').selectedOptions];
        document.getElementById('new-product-images-section').hidden = colors.length === 0;
        ColorImageFields.render(document.getElementById('new-product-images'), colors.map(option => ({
            key: option.value, name: document.getElementById('new-product-name').value || 'منتج جديد', color: option.textContent,
            url: quickExistingImages.get(option.value), ...quickImages.get(option.value),
        })), {
            onFile(group, file) {
                try {
                    ColorImageFields.validate(file, productCatalog.limits.max_image_bytes);
                    const previous = quickImages.get(group.key);
                    if (previous) URL.revokeObjectURL(previous.preview);
                    quickImages.set(group.key, { file, preview: URL.createObjectURL(file) });
                    renderQuickImages();
                } catch (error) {
                    const message = productDialog.querySelector('[data-dialog-errors]'); message.textContent = error.message; message.hidden = false;
                }
            },
            onClear(group) {
                URL.revokeObjectURL(quickImages.get(group.key).preview); quickImages.delete(group.key); renderQuickImages();
            },
        });
    }
    function renderRowImages() {
        const groups = new Map();
        state.rows.forEach(row => {
            if (!row.product_id || !row.color_id) return;
            const key = `${row.product_id}:${row.color_id}`;
            if (!groups.has(key)) groups.set(key, { key, name: row.name, color: row.color, url: row.image_url, uploadUrl: row.image_upload_url, colorId: row.color_id, productId: row.product_id });
            if (row.image_url) groups.get(key).url = row.image_url;
        });
        rowUploads.forEach((upload, key) => {
            if (!groups.has(key) && !upload.busy) { URL.revokeObjectURL(upload.preview); rowUploads.delete(key); }
        });
        document.getElementById('purchase-color-images-section').hidden = groups.size === 0;
        ColorImageFields.render(document.getElementById('purchase-color-images'), [...groups.values()].map(group => ({ ...group, ...rowUploads.get(group.key) })), {
            async onFile(group, file) {
                if (rowUploads.get(group.key)?.busy) return;
                try { ColorImageFields.validate(file); } catch (error) { showError(error.message); return; }
                const previous = rowUploads.get(group.key);
                if (previous?.preview) URL.revokeObjectURL(previous.preview);
                const upload = { file, preview: URL.createObjectURL(file), busy: true };
                rowUploads.set(group.key, upload); renderRowImages(); syncSaveButton();
                const data = new FormData(); data.append('images[0][color_id]', group.colorId); data.append('images[0][image]', file);
                try {
                    const result = await request(group.uploadUrl, { method: 'POST', body: data });
                    const image = result.data.find(image => String(image.color_id) === String(group.colorId));
                    state.rows.forEach(row => { if (String(row.product_id) === String(group.productId) && String(row.color_id) === String(group.colorId)) row.image_url = image?.url || null; });
                    rowUploads.delete(group.key); URL.revokeObjectURL(upload.preview);
                } catch (error) { upload.error = error.message; upload.busy = false; }
                finally { renderRowImages(); syncSaveButton(); }
            },
            onClear(group) {
                const upload = rowUploads.get(group.key);
                if (upload?.preview) URL.revokeObjectURL(upload.preview);
                rowUploads.delete(group.key); renderRowImages();
            },
        });
    }
    let searchTimers = {};
    const searches = {};
    const element = (tag, className, text) => {
        const item = document.createElement(tag);
        if (className) item.className = className;
        if (text !== undefined) item.textContent = text;
        return item;
    };
    const showError = message => {
        errors.textContent = message;
        errors.hidden = false;
        errors.focus();
    };
    const request = async (url, options = {}) => {
        const response = await fetch(url, { ...options, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': config.csrf, ...options.headers } });
        if (response.status === 413) throw new Error('حجم الصور يتجاوز حد الخادم. قلل عدد الصور أو حجمها.');
        if (response.status === 401 || response.status === 419) throw new Error('انتهت الجلسة. افتح تسجيل الدخول في نافذة أخرى ثم أعد المحاولة.');
        const data = await response.json();
        if (!response.ok) throw new Error(Object.values(data.errors || {}).flat().join('\n') || data.message || 'تعذر تنفيذ الطلب.');
        return data;
    };
    const payload = () => ({ client_uuid: state.client_uuid, version: state.version, supplier_id: state.supplier_id,
        branch_id: document.getElementById('purchase-branch').value, invoice_date: document.getElementById('purchase-date').value,
        supplier_invoice_number: document.getElementById('supplier-invoice-number').value, notes: document.getElementById('purchase-notes').value,
        initial_payment: document.getElementById('initial-payment').value, payment_method: document.getElementById('payment-method').value,
        rows: state.rows.map(row => ({ product_variant_id: row.product_variant_id, quantity: row.quantity, unit_cost: row.unit_cost })) });
    const jsonOptions = (body, method = 'POST') => ({ method, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    const invalidatePreview = () => {
        previewVersion++;
        clearTimeout(previewTimer);
        previewRequest?.abort();
        document.getElementById('preview-total').textContent = '—';
        document.getElementById('preview-remaining').textContent = '—';
        document.getElementById('preview-quantity').textContent = '—';
        rowsElement.querySelectorAll('[data-line-total]').forEach(cell => cell.textContent = '—');
        document.getElementById('preview-status').textContent = state.rows.length ? 'جارٍ مراجعة الحساب...' : 'أضف الأصناف لحساب الإجمالي.';
        if (state.rows.length && state.supplier_id) previewTimer = setTimeout(preview, 400);
        else if (!state.supplier_id) document.getElementById('preview-status').textContent = 'اختر المورد لحساب الفاتورة.';
    };
    async function preview() {
        const version = previewVersion;
        previewRequest = new AbortController();
        try {
            const data = await request(config.previewUrl, { ...jsonOptions(payload()), signal: previewRequest.signal });
            if (version !== previewVersion) return;
            document.getElementById('preview-total').textContent = data.total;
            document.getElementById('preview-remaining').textContent = data.remaining;
            document.getElementById('preview-quantity').textContent = data.quantity;
            rowsElement.querySelectorAll('[data-line-total]').forEach((cell, index) => cell.textContent = data.lines[index]);
            document.getElementById('preview-status').textContent = 'تمت مراجعة الحساب.';
        } catch (error) {
            if (error.name !== 'AbortError' && version === previewVersion) document.getElementById('preview-status').textContent = error.message;
        }
    }
    function renderRows() {
        rowsElement.replaceChildren();
        if (!state.rows.length) {
            const row = element('tr');
            const cell = element('td', 'trade-empty', 'ابحث عن صنف لإضافته، أو أنشئ منتجًا جديدًا.');
            cell.colSpan = 6;
            row.append(cell);
            rowsElement.append(row);
        }
        state.rows.forEach((row, index) => {
            const tr = element('tr');
            const name = element('td', '', row.name);
            name.append(element('small', '', row.sku));
            const choice = element('td', '', `${row.color} / ${row.size}`);
            const quantityCell = element('td');
            quantityCell.dataset.label = 'الكمية';
            const quantity = element('input');
            quantity.type = 'number'; quantity.min = '1'; quantity.max = '1000000'; quantity.step = '1'; quantity.required = true;
            quantity.value = row.quantity;
            quantity.setAttribute('aria-label', `كمية ${row.name} ${row.color} ${row.size}`);
            quantity.addEventListener('input', () => { row.quantity = quantity.value; dirty = true; invalidatePreview(); });
            quantityCell.append(quantity);
            const costCell = element('td');
            costCell.dataset.label = 'تكلفة الوحدة ₪';
            const cost = element('input');
            cost.type = 'text'; cost.inputMode = 'decimal'; cost.required = true; cost.value = row.unit_cost;
            cost.setAttribute('aria-label', `تكلفة ${row.name} ${row.color} ${row.size}`);
            cost.addEventListener('input', () => { row.unit_cost = cost.value; dirty = true; invalidatePreview(); });
            costCell.append(cost);
            const total = element('td', '', '—'); total.dataset.lineTotal = ''; total.dataset.label = 'المجموع ₪';
            const action = element('td');
            const remove = element('button', 'line-remove', '×'); remove.type = 'button';
            remove.setAttribute('aria-label', `حذف ${row.name} من المسودة`);
            remove.addEventListener('click', () => { state.rows.splice(index, 1); dirty = true; renderRows(); invalidatePreview(); });
            action.append(remove); tr.append(name, choice, quantityCell, costCell, total, action); rowsElement.append(tr);
        });
        renderRowImages();
    }
    const addRows = rows => {
        const additional = rows.filter(row => !state.rows.some(current => current.product_variant_id === row.product_variant_id));
        if (state.rows.length + additional.length > 300) throw new Error('الحد الأقصى 300 صنف في الفاتورة.');
        additional.forEach(row => state.rows.push({ ...row, quantity: 1, unit_cost: row.unit_cost || '0.00' }));
        dirty = true;
        renderRows();
        invalidatePreview();
    };
    const selectSupplier = supplier => {
        state.supplier_id = supplier.id;
        supplierSearch.value = supplier.name;
        document.getElementById('supplier-selection').textContent = `المورد المختار: ${supplier.name}`;
        supplierResults.hidden = true;
        dirty = true;
        invalidatePreview();
    };
    const runSearch = (kind, input, results, url, render) => {
        clearTimeout(searchTimers[kind]);
        searches[kind]?.abort();
        results.hidden = true;
        const query = input.value.trim();
        if (!query) return;
        searchTimers[kind] = setTimeout(async () => {
            const controller = new AbortController(); searches[kind] = controller;
            const params = new URLSearchParams({ q: query });
            if (kind === 'products') params.set('branch_id', document.getElementById('purchase-branch').value);
            try {
                const data = await request(`${url}?${params}`, { signal: controller.signal });
                if (input.value.trim() !== query) return;
                results.replaceChildren();
                if (!data.data.length) results.append(element('p', 'trade-muted', 'لا توجد نتائج. يمكنك الإضافة بزر جديد.'));
                data.data.forEach(item => render(item, results));
                results.hidden = false;
            } catch (error) {
                if (error.name !== 'AbortError') { results.replaceChildren(element('p', 'trade-muted', error.message)); results.hidden = false; }
            }
        }, 250);
    };
    supplierSearch.addEventListener('input', () => {
        state.supplier_id = null; dirty = true;
        document.getElementById('supplier-selection').textContent = 'اختر المورد من نتائج البحث.';
        invalidatePreview();
        runSearch('suppliers', supplierSearch, supplierResults, config.suppliersUrl, (supplier, container) => {
            const button = element('button', '', supplier.name); button.type = 'button';
            button.append(element('small', '', supplier.phone1 || ''));
            button.addEventListener('click', () => selectSupplier(supplier)); container.append(button);
        });
    });
    productSearch.addEventListener('input', () => runSearch('products', productSearch, productResults, config.productsUrl, (row, container) => {
        const wrapper = element('div', 'trade-result-row');
        const select = element('button', '', `${row.name} — ${row.color} / ${row.size}`); select.type = 'button';
        select.append(element('small', '', row.sku));
        select.addEventListener('click', () => {
            try {
                addRows([row]); productResults.hidden = true; productSearch.value = ''; productSearch.focus();
            } catch (error) { showError(error.message); }
        });
        const add = element('button', '', 'خيارات إضافية'); add.type = 'button';
        add.addEventListener('click', () => openProduct(row)); wrapper.append(select, add); container.append(wrapper);
    }));
    for (const [input, results] of [[productSearch, productResults], [supplierSearch, supplierResults]]) {
        input.addEventListener('keydown', event => {
            if (event.key !== 'Enter') return;
            event.preventDefault();
            const choices = results.querySelectorAll('.trade-result-row > button:first-child, :scope > button');
            if (!results.hidden && choices.length === 1) choices[0].click();
        });
    }
    document.addEventListener('click', event => {
        if (!supplierSearch.parentElement.contains(event.target)) supplierResults.hidden = true;
        if (!productSearch.parentElement.contains(event.target)) productResults.hidden = true;
    });
    document.querySelectorAll('[data-close-dialog]').forEach(button => button.addEventListener('click', () => button.closest('dialog').close()));
    document.getElementById('add-supplier').addEventListener('click', () => supplierDialog.showModal());
    document.getElementById('quick-supplier-form').addEventListener('submit', async event => {
        event.preventDefault(); const quickForm = event.currentTarget; const button = quickForm.querySelector('[type="submit"]');
        if (button.disabled) return;
        button.disabled = true;
        const message = supplierDialog.querySelector('[data-dialog-errors]'); message.hidden = true;
        try {
            const data = await request(quickForm.action, { method: 'POST', body: new FormData(quickForm) });
            selectSupplier(data.data); quickForm.reset(); supplierDialog.close();
        } catch (error) { message.textContent = error.message; message.hidden = false; }
        finally { button.disabled = false; }
    });
    async function openProduct(existing = null) {
        optionsRequest?.abort();
        optionsRequest = new AbortController();
        const currentRequest = optionsRequest;
        clearQuickImages(); quickExistingImages = new Map();
        productForm.reset();
        document.getElementById('new-product-images-section').hidden = true;
        productId = existing?.product_id || null;
        productDialog.querySelector('[data-dialog-errors]').hidden = true;
        document.getElementById('product-dialog-title').textContent = existing ? `خيارات إضافية: ${existing.name}` : 'منتج جديد';
        const name = document.getElementById('new-product-name'); const code = document.getElementById('new-product-code'); const category = document.getElementById('new-product-category');
        name.value = existing?.name || ''; code.value = existing?.code || ''; name.disabled = code.disabled = category.disabled = !!existing;
        document.getElementById('new-product-price').value = existing?.selling_price || '0.00';
        const button = productForm.querySelector('[type="submit"]'); button.disabled = true;
        productDialog.showModal();
        try {
            const [data, imageData] = await Promise.all([
                request(config.optionsUrl, { signal: currentRequest.signal }),
                existing?.images_url ? request(existing.images_url, { signal: currentRequest.signal }) : Promise.resolve({ data: [] }),
            ]);
            productCatalog = data;
            quickExistingImages = new Map(imageData.data.map(image => [String(image.color_id), image.url]));
            for (const [key, target] of [['categories', category], ['colors', document.getElementById('new-product-colors')], ['sizes', document.getElementById('new-product-sizes')]]) {
                target.replaceChildren();
                if (key === 'categories') target.append(new Option('اختر التصنيف', ''));
                data[key].forEach(item => target.append(new Option(item.name, item.id)));
            }
            if (existing) category.value = existing.category_id;
            renderQuickImages();
            button.disabled = false;
        } catch (error) { if (error.name !== 'AbortError') { const message = productDialog.querySelector('[data-dialog-errors]'); message.textContent = error.message; message.hidden = false; } }
    }
    document.getElementById('new-product-colors').addEventListener('change', renderQuickImages);
    document.getElementById('new-product-name').addEventListener('input', renderQuickImages);
    document.getElementById('add-product').addEventListener('click', () => openProduct());
    productForm.addEventListener('submit', async event => {
        event.preventDefault(); const button = productForm.querySelector('[type="submit"]'); if (button.disabled) return;
        button.disabled = true; const message = productDialog.querySelector('[data-dialog-errors]'); message.hidden = true;
        const data = { product_id: productId, name: document.getElementById('new-product-name').value, code: document.getElementById('new-product-code').value || null,
            category_id: document.getElementById('new-product-category').value, colors: [...document.getElementById('new-product-colors').selectedOptions].map(option => Number(option.value)),
            sizes: [...document.getElementById('new-product-sizes').selectedOptions].map(option => Number(option.value)), selling_price: document.getElementById('new-product-price').value };
        try {
            const multipart = new FormData();
            for (const [key, value] of Object.entries(data)) {
                if (Array.isArray(value)) value.forEach(item => multipart.append(`${key}[]`, item));
                else if (value !== null && value !== undefined) multipart.append(key, value);
            }
            const selected = data.colors.map(color => quickImages.get(String(color))).filter(Boolean);
            if (selected.length > productCatalog.limits.max_images) throw new Error('عدد الصور يتجاوز الحد المسموح للرفع في عملية واحدة.');
            if (selected.reduce((total, image) => total + image.file.size, 4096) > productCatalog.limits.max_request_bytes) throw new Error('حجم الصور يتجاوز حد الخادم. قلل عددها أو حجمها.');
            let imageIndex = 0;
            data.colors.forEach(color => {
                const image = quickImages.get(String(color));
                if (!image || quickExistingImages.has(String(color))) return;
                multipart.append(`images[${imageIndex}][color_id]`, color); multipart.append(`images[${imageIndex}][image]`, image.file); imageIndex++;
            });
            const result = await request(config.productStoreUrl, { method: 'POST', body: multipart });
            clearQuickImages();
            addRows(result.data); productDialog.close(); productResults.hidden = true;
        } catch (error) { message.textContent = error.message; message.hidden = false; }
        finally { button.disabled = false; }
    });
    form.addEventListener('input', event => {
        if (!rowsElement.contains(event.target) && event.target !== supplierSearch && event.target !== productSearch) { dirty = true; invalidatePreview(); }
    });
    form.addEventListener('change', () => { dirty = true; invalidatePreview(); });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (saving) return;
        if ([...rowUploads.values()].some(upload => upload.busy)) { showError('انتظر اكتمال رفع صور الألوان قبل حفظ الفاتورة.'); return; }
        if (!state.supplier_id) { showError('اختر المورد من نتائج البحث أولًا.'); return; }
        if (!state.rows.length) { showError('أضف صنفًا واحدًا على الأقل.'); return; }
        saving = true; document.getElementById('save-purchase').disabled = true; errors.hidden = true;
        try {
            const data = await request(form.action, jsonOptions(payload(), config.saveMethod));
            dirty = false; window.location.assign(data.url);
        } catch (error) { showError(error.message); saving = false; document.getElementById('save-purchase').disabled = false; }
    });
    window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
    renderRows();
    invalidatePreview();
})();
